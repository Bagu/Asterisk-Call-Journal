<?php
/**
 * Faux serveur CardDAV pour tests/robot_carddav.php (php -S 127.0.0.1:port tests/faux_carddav.php).
 * Compte alice / mdp-alice. État lu à chaque requête dans le fichier JSON de FAUX_ETAT :
 * {"carnets": {"perso": {"nom": "...", "report": true, "fiches": {"1.vcf": "BEGIN:VCARD..."}}}}
 * Un carnet "report": false refuse addressbook-query (501) pour forcer la lecture par multiget.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }

$etat    = json_decode((string)file_get_contents(getenv('FAUX_ETAT')), true);
$methode = $_SERVER['REQUEST_METHOD'];
$chemin  = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$corps   = (string)file_get_contents('php://input');
$depth   = $_SERVER['HTTP_DEPTH'] ?? '0';

/** Termine la requête par une réponse 207 composée des blocs <d:response> donnés. */
function multistatus(array $reponses): never {
    http_response_code(207);
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" '
       . 'xmlns:c="urn:ietf:params:xml:ns:carddav" xmlns:cs="http://calendarserver.org/ns/">'
       . implode('', $reponses) . '</d:multistatus>';
    exit;
}

/** Bloc <d:response> pour un href et ses propriétés (XML déjà formé). */
function reponse(string $href, string $props): string {
    return '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href><d:propstat><d:prop>'
         . $props . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
}

/** Propriétés d'un carnet : type, nom, ctag (empreinte des fiches). */
function propsCarnet(array $c): string {
    return '<d:resourcetype><d:collection/><c:addressbook/></d:resourcetype>'
         . '<d:displayname>' . htmlspecialchars($c['nom'], ENT_XML1) . '</d:displayname>'
         . '<cs:getctag>' . md5(json_encode($c['fiches'])) . '</cs:getctag>';
}

/** Propriétés d'une fiche, avec ou sans son contenu vCard. */
function propsFiche(string $vcf, bool $donnees): string {
    return '<d:getetag>"' . md5($vcf) . '"</d:getetag>'
         . ($donnees ? '<c:address-data>' . htmlspecialchars($vcf, ENT_XML1) . '</c:address-data>' : '');
}

// ── Authentification ──────────────────────────────────────────────────────────
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if ($auth !== 'Basic ' . base64_encode('alice:mdp-alice')) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="faux"');
    exit;
}

// ── Découverte ────────────────────────────────────────────────────────────────
if ($chemin === '/.well-known/carddav') {
    http_response_code(301);
    header('Location: /dav/');
    exit;
}
if ($methode === 'PROPFIND' && $chemin === '/dav/') {
    multistatus([reponse('/dav/', '<d:resourcetype><d:collection/></d:resourcetype>'
        . '<d:current-user-principal><d:href>/dav/principals/alice/</d:href></d:current-user-principal>')]);
}
if ($methode === 'PROPFIND' && $chemin === '/dav/principals/alice/') {
    multistatus([reponse('/dav/principals/alice/',
        '<c:addressbook-home-set><d:href>/dav/carnets/alice/</d:href></c:addressbook-home-set>')]);
}
if ($methode === 'PROPFIND' && $chemin === '/dav/carnets/alice/') {
    $reps = [reponse('/dav/carnets/alice/', '<d:resourcetype><d:collection/></d:resourcetype>')];
    if ($depth === '1') {
        foreach ($etat['carnets'] as $id => $c) $reps[] = reponse("/dav/carnets/alice/$id/", propsCarnet($c));
    }
    multistatus($reps);
}

// ── Carnets ───────────────────────────────────────────────────────────────────
if (preg_match('#^/dav/carnets/alice/([^/]+)/$#', $chemin, $m) && isset($etat['carnets'][$m[1]])) {
    $id    = $m[1];
    $c     = $etat['carnets'][$id];
    $base  = "/dav/carnets/alice/$id/";
    if ($methode === 'PROPFIND') {
        $reps = [reponse($base, propsCarnet($c))];
        if ($depth === '1') {
            foreach ($c['fiches'] as $nom => $vcf) $reps[] = reponse($base . $nom, propsFiche($vcf, false));
        }
        multistatus($reps);
    }
    if ($methode === 'REPORT' && str_contains($corps, 'addressbook-query')) {
        if (!$c['report']) { http_response_code(501); exit; }
        $reps = [];
        foreach ($c['fiches'] as $nom => $vcf) $reps[] = reponse($base . $nom, propsFiche($vcf, true));
        multistatus($reps);
    }
    if ($methode === 'REPORT' && str_contains($corps, 'addressbook-multiget')) {
        preg_match_all('#<d:href>([^<]+)</d:href>#', $corps, $hrefs);
        $reps = [];
        foreach ($hrefs[1] as $h) {
            $nom = basename(html_entity_decode($h, ENT_XML1));
            if (isset($c['fiches'][$nom])) $reps[] = reponse($base . $nom, propsFiche($c['fiches'][$nom], true));
        }
        multistatus($reps);
    }
}

http_response_code($methode === 'PROPFIND' ? 404 : 405);
