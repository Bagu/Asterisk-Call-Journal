<?php
/**
 * Synchronisation des contacts depuis des carnets CardDAV (lecture seule côté serveur).
 *
 * Chaque carnet est copié en miroir : un numéro venu d'un carnet (numeros.manuel = 0) est
 * retiré quand plus aucun carnet ne le fournit ; un numéro saisi à la main ou importé en
 * CSV (manuel = 1) n'est jamais retiré. Le lien numéro / carnet est dans sources_numeros.
 */
require_once __DIR__ . '/contacts_functions.php';

const CARDDAV_TAILLE_MAX = 20 * 1024 * 1024;
const CARDDAV_DELAI      = 30;
const CARDDAV_LOT_MULTIGET = 100;
const NS_DAV     = 'DAV:';
const NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';
const NS_CS      = 'http://calendarserver.org/ns/';

// ── Chiffrement des mots de passe ─────────────────────────────────────────────

/** Retourne la clé AES de 32 octets, créée au premier appel. Lève RuntimeException si impossible. */
function carddavCle(string $chemin = CARDDAV_KEY_PATH): string {
    if (is_file($chemin)) {
        $cle = base64_decode(trim((string)file_get_contents($chemin)), true);
        if ($cle === false || strlen($cle) !== 32) {
            throw new RuntimeException('Clé CardDAV illisible : ' . $chemin);
        }
        return $cle;
    }
    $cle = random_bytes(32);
    if (@file_put_contents($chemin, base64_encode($cle), LOCK_EX) === false) {
        throw new RuntimeException('Impossible de créer la clé CardDAV : ' . $chemin);
    }
    @chmod($chemin, 0600);
    return $cle;
}

/** Chiffre un texte (AES-256-GCM). Retourne 'v1:' . base64(iv . tag . chiffré). */
function carddavChiffrer(string $clair, ?string $cle = null): string {
    if ($clair === '') return '';
    $cle = $cle ?? carddavCle();
    $iv  = random_bytes(12);
    $tag = '';
    $c   = openssl_encrypt($clair, 'aes-256-gcm', $cle, OPENSSL_RAW_DATA, $iv, $tag);
    if ($c === false) throw new RuntimeException('Chiffrement impossible');
    return 'v1:' . base64_encode($iv . $tag . $c);
}

/** Déchiffre un texte produit par carddavChiffrer(). Retourne null si la clé ou le texte ne vont pas. */
function carddavDechiffrer(string $chiffre, ?string $cle = null): ?string {
    if ($chiffre === '') return '';
    if (!str_starts_with($chiffre, 'v1:')) return null;
    $brut = base64_decode(substr($chiffre, 3), true);
    if ($brut === false || strlen($brut) < 29) return null;
    $cle = $cle ?? carddavCle();
    $r   = openssl_decrypt(substr($brut, 28), 'aes-256-gcm', $cle, OPENSSL_RAW_DATA,
                           substr($brut, 0, 12), substr($brut, 12, 16));
    return $r === false ? null : $r;
}

// ── Lecture des vCards ────────────────────────────────────────────────────────

/** Retire les échappements d'une valeur texte vCard (\n, \, \; \\). */
function vcardDeseschapper(string $v): string {
    return preg_replace_callback('/\\\\(.)/s', fn($m) => match (strtolower($m[1])) {
        'n'     => ' ',
        default => $m[1],
    }, $v);
}

/** Découpe une chaîne sur un séparateur en ignorant ceux qui sont entre guillemets. */
function vcardDecouper(string $s, string $sep): array {
    $parts = [];
    $cur   = '';
    $quote = false;
    $len   = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch = $s[$i];
        if ($ch === '"') { $quote = !$quote; $cur .= $ch; continue; }
        if ($ch === $sep && !$quote) { $parts[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    $parts[] = $cur;
    return $parts;
}

/** Découpe une composante structurée (N, ORG) sur les ';' non échappés. */
function vcardComposantes(string $v): array {
    return array_map('vcardDeseschapper', preg_split('/(?<!\\\\);/', $v));
}

/**
 * Convertit une liste de types vCard (cell, home, work...) en type de numéro de l'application.
 * Priorité : portable, puis travail, puis domicile ; fax, pager... deviennent 'autre'.
 */
function vcardTypeNumero(array $types): string {
    $types = array_map('strtolower', $types);
    if (array_intersect($types, ['cell', 'mobile', 'iphone'])) return 'portable';
    if (array_intersect($types, ['fax', 'pager', 'video', 'textphone', 'car', 'isdn', 'bbs', 'modem'])) return 'autre';
    if (in_array('work', $types, true)) return 'travail';
    if (in_array('home', $types, true)) return 'domicile';
    if (array_intersect($types, ['main', 'other'])) return 'autre';
    return 'inconnu';
}

/**
 * Lit le texte d'une ou plusieurs vCards (versions 2.1, 3.0 et 4.0).
 * Retourne une liste de ['nom'=>string, 'numeros'=>[[brut, type], ...], 'groupe'=>bool].
 */
function vcardLire(string $texte): array {
    if (!mb_check_encoding($texte, 'UTF-8')) {
        $texte = mb_convert_encoding($texte, 'UTF-8', 'Windows-1252');
    }
    $texte = str_replace(["\r\n", "\r"], "\n", $texte);
    $texte = preg_replace("/\n[ \t]/", '', $texte);

    $fiches = [];
    $fiche  = null;
    $lignes = explode("\n", $texte);
    $nb     = count($lignes);
    for ($i = 0; $i < $nb; $i++) {
        $ligne = $lignes[$i];
        if (trim($ligne) === '') continue;
        $pos = null;
        $quote = false;
        for ($j = 0, $l = strlen($ligne); $j < $l; $j++) {
            if ($ligne[$j] === '"') $quote = !$quote;
            elseif ($ligne[$j] === ':' && !$quote) { $pos = $j; break; }
        }
        if ($pos === null) continue;
        $tete   = substr($ligne, 0, $pos);
        $valeur = substr($ligne, $pos + 1);
        $params = vcardDecouper($tete, ';');
        $nomProp = strtoupper(array_shift($params));
        if (($p = strrpos($nomProp, '.')) !== false) $nomProp = substr($nomProp, $p + 1);

        $types = [];
        $qp    = false;
        foreach ($params as $param) {
            [$k, $v] = array_pad(explode('=', $param, 2), 2, null);
            if ($v === null) { $types[] = $k; continue; }
            $k = strtoupper(trim($k));
            $v = trim($v, '"');
            if ($k === 'TYPE') {
                foreach (explode(',', $v) as $t) $types[] = trim($t, '" ');
            } elseif ($k === 'ENCODING' && strtoupper($v) === 'QUOTED-PRINTABLE') {
                $qp = true;
            }
        }
        if ($qp) {
            while (str_ends_with($valeur, '=') && $i + 1 < $nb) {
                $valeur = substr($valeur, 0, -1) . $lignes[++$i];
            }
            $valeur = quoted_printable_decode($valeur);
            if (!mb_check_encoding($valeur, 'UTF-8')) {
                $valeur = mb_convert_encoding($valeur, 'UTF-8', 'Windows-1252');
            }
        }

        switch ($nomProp) {
            case 'BEGIN':
                if (strtoupper(trim($valeur)) === 'VCARD') {
                    $fiche = ['fn' => '', 'n' => '', 'org' => '', 'numeros' => [], 'groupe' => false];
                }
                break;
            case 'END':
                if ($fiche !== null && strtoupper(trim($valeur)) === 'VCARD') {
                    $nom = trim($fiche['fn']);
                    if ($nom === '' && $fiche['n'] !== '') {
                        $n   = vcardComposantes($fiche['n']);
                        $nom = trim(($n[1] ?? '') . ' ' . ($n[0] ?? ''));
                    }
                    if ($nom === '' && $fiche['org'] !== '') {
                        $nom = trim(vcardComposantes($fiche['org'])[0]);
                    }
                    $fiches[] = ['nom' => preg_replace('/\s+/u', ' ', $nom),
                                 'numeros' => $fiche['numeros'], 'groupe' => $fiche['groupe']];
                    $fiche = null;
                }
                break;
            default:
                if ($fiche === null) break;
                match ($nomProp) {
                    'FN'  => $fiche['fn']  = vcardDeseschapper($valeur),
                    'N'   => $fiche['n']   = $valeur,
                    'ORG' => $fiche['org'] = $valeur,
                    'TEL' => $fiche['numeros'][] = [
                        trim(preg_replace('/;.*$/', '', preg_replace('/^tel:/i', '', trim($valeur)))),
                        vcardTypeNumero($types),
                    ],
                    'KIND', 'X-ADDRESSBOOKSERVER-KIND'
                          => $fiche['groupe'] = $fiche['groupe'] || strtolower(trim($valeur)) === 'group',
                    default => null,
                };
        }
    }
    return $fiches;
}

// ── Requêtes HTTP (WebDAV) ────────────────────────────────────────────────────

/** Vrai si l'adresse est une URL http(s) valide. Toute autre forme (file:, php:...) est refusée. */
function carddavUrlValide(string $url): bool {
    return (bool)preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** Résout une adresse relative (href, Location) par rapport à une URL de base. */
function carddavResoudre(string $base, string $href): string {
    if (preg_match('#^https?://#i', $href)) return $href;
    $p = parse_url($base);
    $origine = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (str_starts_with($href, '/')) return $origine . $href;
    $chemin = $p['path'] ?? '/';
    $dossier = substr($chemin, 0, strrpos($chemin, '/') + 1);
    return $origine . $dossier . $href;
}

/**
 * Vrai si les identifiants peuvent suivre une redirection de $depart vers $cible :
 * pas de passage de https à http, et même domaine (deux derniers niveaux du nom d'hôte).
 */
function carddavRedirectionSure(string $depart, string $cible): bool {
    $a = parse_url($depart);
    $b = parse_url($cible);
    if (strtolower($a['scheme'] ?? '') === 'https' && strtolower($b['scheme'] ?? '') !== 'https') return false;
    $ha = strtolower($a['host'] ?? '');
    $hb = strtolower($b['host'] ?? '');
    if ($ha === $hb) return true;
    if (filter_var($ha, FILTER_VALIDATE_IP) || filter_var($hb, FILTER_VALIDATE_IP)) return false;
    $domaine = fn(string $h) => implode('.', array_slice(explode('.', $h), -2));
    return substr_count($ha, '.') >= 1 && $domaine($ha) === $domaine($hb);
}

/**
 * Envoie une requête WebDAV avec authentification Basic.
 * Suit jusqu'à 5 redirections en gardant la méthode.
 * Retourne ['code'=>int, 'corps'=>string, 'url'=>string] ; code 0 et 'erreur' si pas de réponse.
 */
function carddavRequete(string $methode, string $url, string $utilisateur, string $motDePasse,
                        string $corps = '', ?string $profondeur = null): array {
    $depart = $url;
    for ($saut = 0; $saut <= 5; $saut++) {
        if (!carddavUrlValide($url)) return ['code' => 0, 'corps' => '', 'url' => $url, 'erreur' => 'URL refusée'];
        if (!carddavRedirectionSure($depart, $url)) {
            return ['code' => 0, 'corps' => '', 'url' => $url, 'erreur' => 'redirection vers un autre domaine refusée'];
        }
        $entetes = ['User-Agent: Asterisk-Call-Journal', 'Accept: application/xml, text/xml, text/vcard'];
        if ($utilisateur !== '' || $motDePasse !== '') {
            $entetes[] = 'Authorization: Basic ' . base64_encode($utilisateur . ':' . $motDePasse);
        }
        if ($corps !== '')        $entetes[] = 'Content-Type: application/xml; charset=utf-8';
        if ($profondeur !== null) $entetes[] = 'Depth: ' . $profondeur;
        $ctx = stream_context_create([
            'http' => [
                'method'          => $methode,
                'header'          => implode("\r\n", $entetes),
                'content'         => $corps,
                'ignore_errors'   => true,
                'follow_location' => 0,
                'timeout'         => CARDDAV_DELAI,
                'protocol_version' => 1.1,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $reponse = @file_get_contents($url, false, $ctx, 0, CARDDAV_TAILLE_MAX + 1);
        $lignes  = http_get_last_response_headers() ?? [];
        if ($reponse === false || !$lignes) {
            $err = error_get_last()['message'] ?? 'pas de réponse';
            return ['code' => 0, 'corps' => '', 'url' => $url, 'erreur' => preg_replace('/^.*?: /', '', $err)];
        }
        if (strlen($reponse) > CARDDAV_TAILLE_MAX) {
            return ['code' => 0, 'corps' => '', 'url' => $url, 'erreur' => 'réponse trop grosse'];
        }
        $code = preg_match('#^HTTP/\S+\s+(\d{3})#', $lignes[0], $m) ? (int)$m[1] : 0;
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $loc = null;
            foreach ($lignes as $l) {
                if (stripos($l, 'Location:') === 0) $loc = trim(substr($l, 9));
            }
            if ($loc === null) break;
            $url = carddavResoudre($url, $loc);
            continue;
        }
        return ['code' => $code, 'corps' => $reponse, 'url' => $url];
    }
    return ['code' => 0, 'corps' => '', 'url' => $url, 'erreur' => 'trop de redirections'];
}

/**
 * Lit une réponse 207 Multi-Status.
 * Retourne null si le XML est illisible ou porte un DOCTYPE (entités), sinon une liste de
 * ['href'=>string, 'props'=>['{ns}nom' => ['texte'=>string, 'hrefs'=>[...], 'enfants'=>['{ns}nom', ...]]]]
 * (propriétés en statut 200 seulement).
 */
function carddavLireMultistatus(string $xml): ?array {
    if (trim($xml) === '' || stripos($xml, '<!DOCTYPE') !== false) return null;
    $dom = new DOMDocument();
    $ancien = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($ancien);
    if (!$ok) return null;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('d', NS_DAV);
    $sortie = [];
    foreach ($xp->query('//d:response') as $rep) {
        $href = trim((string)$xp->evaluate('string(d:href)', $rep));
        $props = [];
        foreach ($xp->query('d:propstat', $rep) as $ps) {
            $statut = (string)$xp->evaluate('string(d:status)', $ps);
            if (!preg_match('#\s2\d\d\s#', $statut . ' ')) continue;
            foreach ($xp->query('d:prop/*', $ps) as $prop) {
                $hrefs = [];
                foreach ($xp->query('.//d:href', $prop) as $h) $hrefs[] = trim($h->textContent);
                $enfants = [];
                foreach ($prop->childNodes as $c) {
                    if ($c instanceof DOMElement) $enfants[] = '{' . $c->namespaceURI . '}' . $c->localName;
                }
                $props['{' . $prop->namespaceURI . '}' . $prop->localName] = [
                    'texte' => $prop->textContent, 'hrefs' => $hrefs, 'enfants' => $enfants,
                ];
            }
        }
        $sortie[] = ['href' => $href, 'props' => $props];
    }
    return $sortie;
}

/** Corps XML d'un PROPFIND demandant les propriétés données (['d:resourcetype', 'cs:getctag'...]). */
function carddavCorpsPropfind(array $props): string {
    return '<?xml version="1.0" encoding="utf-8"?>'
         . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">'
         . '<d:prop>' . implode('', array_map(fn($p) => "<$p/>", $props)) . '</d:prop></d:propfind>';
}

/** PROPFIND lu. Retourne ['ok'=>bool, 'code'=>int, 'reponses'=>?array, 'url'=>string, 'erreur'=>?string]. */
function carddavPropfind(string $url, string $u, string $p, array $props, string $profondeur): array {
    $r = carddavRequete('PROPFIND', $url, $u, $p, carddavCorpsPropfind($props), $profondeur);
    $reps = $r['code'] === 207 ? carddavLireMultistatus($r['corps']) : null;
    return ['ok' => $reps !== null, 'code' => $r['code'], 'reponses' => $reps, 'url' => $r['url'],
            'erreur' => $r['erreur'] ?? null];
}

/** Message lisible pour un échec HTTP (code ou erreur réseau). */
function carddavMessageEchec(array $r): string {
    if (($r['code'] ?? 0) === 0) return t('carnets.err_reseau', ['msg' => $r['erreur'] ?? '?']);
    if (in_array($r['code'], [401, 403], true)) return t('carnets.err_auth', ['code' => $r['code']]);
    return t('carnets.err_http', ['code' => $r['code']]);
}

/** Compare deux adresses en ignorant la barre finale et l'origine (le href d'une réponse est souvent un chemin). */
function carddavMemeRessource(string $url, string $href): bool {
    $a = rtrim(rawurldecode(parse_url($url, PHP_URL_PATH) ?? '/'), '/');
    $b = rtrim(rawurldecode(parse_url($href, PHP_URL_PATH) ?? $href), '/');
    return $a === $b;
}

/**
 * Examine une adresse : carnet d'adresses, ou point de départ de la découverte.
 * Retourne ['ok'=>bool, 'carnets'=>[['url'=>, 'nom'=>], ...], 'direct'=>bool, 'msg'=>string].
 * direct = true si l'adresse donnée est elle-même un carnet.
 */
function carddavDecouvrir(string $url, string $u, string $p): array {
    $estCarnet = fn(array $props) => in_array('{' . NS_CARDDAV . '}addressbook',
                                              $props['{' . NS_DAV . '}resourcetype']['enfants'] ?? [], true);
    $nomDe = fn(array $props, string $href) => trim($props['{' . NS_DAV . '}displayname']['texte'] ?? '')
                                              ?: rawurldecode(basename(rtrim($href, '/')));

    $demande = ['d:resourcetype', 'd:displayname', 'd:current-user-principal'];
    $r = carddavPropfind($url, $u, $p, $demande, '0');
    $props = $r['reponses'][0]['props'] ?? [];
    $sansPiste = !$r['ok'] || (!$estCarnet($props) && !isset($props['{' . NS_DAV . '}current-user-principal']));
    if ($sansPiste && $r['code'] !== 0 && !in_array($r['code'], [401, 403], true)) {
        // Racine du serveur : le chemin de découverte standard est /.well-known/carddav
        $r2 = carddavPropfind(carddavResoudre($url, '/.well-known/carddav'), $u, $p, $demande, '0');
        if ($r2['ok']) $r = $r2;
    }
    if (!$r['ok']) return ['ok' => false, 'carnets' => [], 'direct' => false, 'msg' => carddavMessageEchec($r)];

    $base  = $r['url'];
    $props = $r['reponses'][0]['props'] ?? [];
    if ($estCarnet($props)) {
        return ['ok' => true, 'direct' => true, 'msg' => '',
                'carnets' => [['url' => $base, 'nom' => $nomDe($props, $base)]]];
    }

    $principal = $props['{' . NS_DAV . '}current-user-principal']['hrefs'][0] ?? null;
    $maisons   = [];
    if ($principal !== null) {
        $rp = carddavPropfind(carddavResoudre($base, $principal), $u, $p, ['c:addressbook-home-set'], '0');
        if ($rp['ok']) {
            foreach ($rp['reponses'][0]['props']['{' . NS_CARDDAV . '}addressbook-home-set']['hrefs'] ?? [] as $h) {
                $maisons[] = carddavResoudre($rp['url'], $h);
            }
        }
    }
    if (!$maisons) $maisons[] = $base;

    $carnets = [];
    foreach ($maisons as $maison) {
        $rm = carddavPropfind($maison, $u, $p, ['d:resourcetype', 'd:displayname'], '1');
        if (!$rm['ok']) continue;
        foreach ($rm['reponses'] as $rep) {
            if (!$estCarnet($rep['props'])) continue;
            $urlCarnet = carddavResoudre($rm['url'], $rep['href']);
            $carnets[$urlCarnet] = ['url' => $urlCarnet, 'nom' => $nomDe($rep['props'], $rep['href'])];
        }
    }
    if (!$carnets) return ['ok' => false, 'carnets' => [], 'direct' => false, 'msg' => t('carnets.err_aucun')];
    return ['ok' => true, 'carnets' => array_values($carnets), 'direct' => false, 'msg' => ''];
}

/**
 * Télécharge toutes les vCards d'un carnet : REPORT addressbook-query, sinon liste par
 * PROPFIND puis addressbook-multiget par lots.
 * Retourne ['ok'=>bool, 'vcards'=>[texte, ...], 'sans_donnees'=>int, 'msg'=>string].
 */
function carddavTelecharger(string $url, string $u, string $p): array {
    $prop = '<d:prop><d:getetag/><c:address-data/></d:prop>';
    $corps = '<?xml version="1.0" encoding="utf-8"?>'
           . '<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav">' . $prop
           . '</c:addressbook-query>';
    $r = carddavRequete('REPORT', $url, $u, $p, $corps, '1');
    if (in_array($r['code'], [401, 403], true) || $r['code'] === 0) {
        return ['ok' => false, 'vcards' => [], 'sans_donnees' => 0, 'msg' => carddavMessageEchec($r)];
    }
    $reps = $r['code'] === 207 ? carddavLireMultistatus($r['corps']) : null;

    if ($reps === null) {
        // Pas de REPORT : liste des fiches, puis lecture par lots
        $l = carddavPropfind($url, $u, $p, ['d:resourcetype', 'd:getetag'], '1');
        if (!$l['ok']) return ['ok' => false, 'vcards' => [], 'sans_donnees' => 0, 'msg' => carddavMessageEchec($l)];
        $hrefs = [];
        foreach ($l['reponses'] as $rep) {
            if (carddavMemeRessource($l['url'], $rep['href'])) continue;
            if (($rep['props']['{' . NS_DAV . '}resourcetype']['enfants'] ?? []) !== []) continue;
            $hrefs[] = $rep['href'];
        }
        $reps = [];
        foreach (array_chunk($hrefs, CARDDAV_LOT_MULTIGET) as $lot) {
            $c = '<?xml version="1.0" encoding="utf-8"?>'
               . '<c:addressbook-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav">' . $prop
               . implode('', array_map(fn($h) => '<d:href>' . htmlspecialchars($h, ENT_XML1) . '</d:href>', $lot))
               . '</c:addressbook-multiget>';
            $rm = carddavRequete('REPORT', $url, $u, $p, $c, '1');
            $lu = $rm['code'] === 207 ? carddavLireMultistatus($rm['corps']) : null;
            if ($lu === null) return ['ok' => false, 'vcards' => [], 'sans_donnees' => 0, 'msg' => carddavMessageEchec($rm)];
            array_push($reps, ...$lu);
        }
    }

    $vcards = [];
    $sansDonnees = 0;
    foreach ($reps as $rep) {
        if (carddavMemeRessource($url, $rep['href'])) continue;
        $data = $rep['props']['{' . NS_CARDDAV . '}address-data']['texte'] ?? '';
        if (stripos($data, 'BEGIN:VCARD') === false) { $sansDonnees++; continue; }
        $vcards[] = $data;
    }
    if ($sansDonnees > 0 && !$vcards) {
        return ['ok' => false, 'vcards' => [], 'sans_donnees' => $sansDonnees, 'msg' => t('carnets.err_donnees')];
    }
    return ['ok' => true, 'vcards' => $vcards, 'sans_donnees' => $sansDonnees, 'msg' => ''];
}

/** Lit l'étiquette de version du carnet (getctag, sinon sync-token). Retourne null si le serveur n'en donne pas. */
function carddavEtiquette(string $url, string $u, string $p): ?string {
    $r = carddavPropfind($url, $u, $p, ['cs:getctag', 'd:sync-token'], '0');
    if (!$r['ok']) return null;
    $props = $r['reponses'][0]['props'] ?? [];
    $v = trim($props['{' . NS_CS . '}getctag']['texte'] ?? '') ?: trim($props['{' . NS_DAV . '}sync-token']['texte'] ?? '');
    return $v === '' ? null : $v;
}

// ── Application à la base ─────────────────────────────────────────────────────

/** Retire les numéros venus des carnets que plus aucun carnet ne fournit, puis les contacts devenus vides. */
function carddavNettoyer(PDO $db): array {
    $n = $db->exec("DELETE FROM numeros WHERE manuel = 0
                    AND id NOT IN (SELECT numero_id FROM sources_numeros)");
    $c = $db->exec("DELETE FROM contacts WHERE manuel = 0
                    AND id NOT IN (SELECT contact_id FROM numeros)");
    return ['numeros_retires' => (int)$n, 'contacts_retires' => (int)$c];
}

/**
 * Applique en miroir les fiches lues d'un carnet. À appeler dans une transaction.
 * $fiches : sortie de vcardLire(). Retourne les compteurs de la synchronisation.
 */
function carddavAppliquer(PDO $db, int $carnetId, array $fiches): array {
    $stats = ['fiches' => count($fiches), 'contacts_crees' => 0, 'numeros_ajoutes' => 0,
              'numeros_modifies' => 0, 'numeros_retires' => 0, 'contacts_retires' => 0,
              'sans_nom' => 0, 'sans_numero' => 0, 'groupes' => 0];

    $s = $db->prepare("SELECT numero_id FROM sources_numeros WHERE carnet_id = ?");
    $s->execute([$carnetId]);
    $anciens = array_fill_keys(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), true);
    $vus     = [];
    $lien    = $db->prepare("INSERT OR IGNORE INTO sources_numeros (numero_id, carnet_id) VALUES (?, ?)");

    foreach ($fiches as $f) {
        if ($f['groupe']) { $stats['groupes']++; continue; }
        if ($f['nom'] === '') { $stats['sans_nom']++; continue; }
        $numeros = [];
        foreach ($f['numeros'] as [$brut, $type]) {
            $num = normaliserNumero($brut);
            if ($num === '') continue;
            // Même numéro deux fois : le type connu l'emporte sur 'inconnu'
            if (!isset($numeros[$num]) || $numeros[$num] === 'inconnu') $numeros[$num] = $type;
        }
        if (!$numeros) { $stats['sans_numero']++; continue; }

        $contact = trouverOuCreerContact($db, $f['nom'], false);
        if ($contact['created']) $stats['contacts_crees']++;
        foreach ($numeros as $num => $type) {
            $r = lierNumeroId($db, $contact['id'], (string)$num, $type, false);
            if ($r['etat'] === 'created')     $stats['numeros_ajoutes']++;
            elseif ($r['etat'] === 'updated') $stats['numeros_modifies']++;
            $lien->execute([$r['id'], $carnetId]);
            $vus[$r['id']] = true;
        }
    }

    $suppr = $db->prepare("DELETE FROM sources_numeros WHERE numero_id = ? AND carnet_id = ?");
    foreach (array_diff_key($anciens, $vus) as $id => $_) $suppr->execute([$id, $carnetId]);

    $n = carddavNettoyer($db);
    $stats['numeros_retires']  = $n['numeros_retires'];
    $stats['contacts_retires'] = $n['contacts_retires'];
    return $stats;
}

/** Nombre de numéros actuellement fournis par un carnet. */
function carddavNbNumeros(PDO $db, int $carnetId): int {
    $s = $db->prepare("SELECT COUNT(*) FROM sources_numeros WHERE carnet_id = ?");
    $s->execute([$carnetId]);
    return (int)$s->fetchColumn();
}

/** Enregistre le résultat d'une synchronisation sur la ligne du carnet. */
function carddavNoterEtat(PDO $db, int $carnetId, bool $ok, string $etat, ?string $ctag = null, ?int $nbFiches = null): void {
    $db->prepare("UPDATE carnets SET derniere_synchro = datetime('now','localtime'), dernier_ok = ?,
                  dernier_etat = ?, ctag = COALESCE(?, ctag), nb_fiches = COALESCE(?, nb_fiches) WHERE id = ?")
       ->execute([(int)$ok, mb_substr($etat, 0, 500), $ctag, $nbFiches, $carnetId]);
}

/** Résumé lisible des compteurs d'une synchronisation. */
function carddavResume(array $st): string {
    $txt = t('carnets.resume', [
        'fiches' => $st['fiches'], 'ajoutes' => $st['numeros_ajoutes'], 'modifies' => $st['numeros_modifies'],
        'retires' => $st['numeros_retires'], 'crees' => $st['contacts_crees'], 'contacts_retires' => $st['contacts_retires'],
    ]);
    $ignores = $st['sans_nom'] + $st['sans_numero'];
    if ($ignores > 0) $txt .= ' ' . t('carnets.resume_ignores', ['n' => $ignores, 'sans_nom' => $st['sans_nom'], 'sans_numero' => $st['sans_numero']]);
    if (($st['sans_donnees'] ?? 0) > 0) $txt .= ' ' . t('carnets.resume_illisibles', ['n' => $st['sans_donnees']]);
    return $txt;
}

/**
 * Synchronise un carnet (ligne de la table carnets).
 * $forcer = false : rien n'est téléchargé si l'étiquette de version n'a pas changé.
 * Retourne ['ok'=>bool, 'msg'=>string, 'stats'=>?array, 'inchange'=>bool].
 */
function carddavSynchroniserCarnet(PDO $db, array $carnet, bool $forcer = true): array {
    $id = (int)$carnet['id'];
    try {
        $mdp = carddavDechiffrer((string)$carnet['mot_de_passe']);
    } catch (RuntimeException $e) {
        error_log('[carddav] ' . $e->getMessage());
        $msg = t('carnets.err_cle_creation');
        carddavNoterEtat($db, $id, false, $msg);
        return ['ok' => false, 'msg' => $msg, 'stats' => null, 'inchange' => false];
    }
    if ($mdp === null) {
        $msg = t('carnets.err_cle');
        carddavNoterEtat($db, $id, false, $msg);
        return ['ok' => false, 'msg' => $msg, 'stats' => null, 'inchange' => false];
    }
    $u = (string)$carnet['utilisateur'];

    $ctag = carddavEtiquette($carnet['url'], $u, $mdp);
    if (!$forcer && $ctag !== null && $ctag === $carnet['ctag'] && (int)$carnet['dernier_ok'] === 1) {
        return ['ok' => true, 'msg' => t('carnets.inchange'), 'stats' => null, 'inchange' => true];
    }

    $t = carddavTelecharger($carnet['url'], $u, $mdp);
    if (!$t['ok']) {
        carddavNoterEtat($db, $id, false, $t['msg']);
        return ['ok' => false, 'msg' => $t['msg'], 'stats' => null, 'inchange' => false];
    }

    $fiches = [];
    foreach ($t['vcards'] as $v) array_push($fiches, ...vcardLire($v));

    // Un carnet reçu vide alors qu'il fournissait des numéros : plutôt une panne du serveur
    if (!$fiches && carddavNbNumeros($db, $id) > 0) {
        $msg = t('carnets.err_vide');
        carddavNoterEtat($db, $id, false, $msg);
        return ['ok' => false, 'msg' => $msg, 'stats' => null, 'inchange' => false];
    }

    $db->beginTransaction();
    try {
        $stats = carddavAppliquer($db, $id, $fiches);
        $stats['sans_donnees'] = $t['sans_donnees'];
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('[carddav] carnet ' . $id . ' : ' . $e->getMessage());
        $msg = t('carnets.err_base');
        carddavNoterEtat($db, $id, false, $msg);
        return ['ok' => false, 'msg' => $msg, 'stats' => null, 'inchange' => false];
    }
    $msg = carddavResume($stats);
    carddavNoterEtat($db, $id, true, $msg, $ctag ?? '', count($t['vcards']));
    return ['ok' => true, 'msg' => $msg, 'stats' => $stats, 'inchange' => false];
}

/**
 * Synchronise les carnets actifs (ou celui dont l'id est donné), sous verrou exclusif.
 * Retourne ['verrou'=>bool, 'resultats'=>[['nom'=>, 'ok'=>, 'msg'=>, 'inchange'=>], ...]].
 */
function carddavSynchroniserTout(PDO $db, bool $forcer = true, ?int $seulId = null): array {
    $fp = fopen(__DIR__ . '/carddav.lock', 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        if ($fp) fclose($fp);
        return ['verrou' => false, 'resultats' => []];
    }
    try {
        $sql = "SELECT * FROM carnets WHERE " . ($seulId !== null ? "id = ?" : "actif = 1") . " ORDER BY nom COLLATE NOCASE";
        $s = $db->prepare($sql);
        $s->execute($seulId !== null ? [$seulId] : []);
        $res = [];
        foreach ($s->fetchAll() as $c) {
            $r = carddavSynchroniserCarnet($db, $c, $forcer);
            $res[] = ['nom' => $c['nom'], 'ok' => $r['ok'], 'msg' => $r['msg'], 'inchange' => $r['inchange']];
        }
        return ['verrou' => true, 'resultats' => $res];
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
