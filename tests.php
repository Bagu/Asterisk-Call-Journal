<?php
if (PHP_SAPI !== 'cli') {
    // Vérification IP en PHP en plus du .htaccess (inclut localhost pour les tests depuis le serveur)
    $allowed = ['127.0.0.1', '::1', '172.16.', '192.168.'];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!array_filter($allowed, fn($p) => str_starts_with($ip, $p))) {
        http_response_code(403); exit;
    }
}
/**
 * Tests unitaires — CLI ou navigateur (accès interne uniquement).
 * Usage : php tests.php
 */
require __DIR__ . '/config.php';
require_once __DIR__ . '/contacts_functions.php';
require_once __DIR__ . '/index_functions.php';

// ── Détection du contexte de sortie ──────────────────────────────────────────
$isCli     = PHP_SAPI === 'cli';
$useColor  = $isCli && stream_isatty(STDOUT);
$startTime = microtime(true);

/** Applique un code couleur ANSI si le terminal le supporte, sinon retourne le texte brut. */
function ansi(string $text, string $code): string {
    global $useColor;
    return $useColor ? "\e[{$code}m{$text}\e[0m" : $text;
}

// ── État global ───────────────────────────────────────────────────────────────
$results  = ['pass' => 0, 'fail' => 0];
$sections = [];
$curSec   = -1;

/** Déclare une nouvelle section de tests et l'ajoute à la pile de résultats. */
function section(string $title): void {
    global $sections, $curSec;
    $sections[] = ['title' => $title, 'pass' => 0, 'fail' => 0, 'lines' => []];
    $curSec     = count($sections) - 1;
}

/** Vérifie une assertion ($actual === $expected) et enregistre le résultat dans la section courante. */
function expect(string $label, mixed $actual, mixed $expected): void {
    global $results, $sections, $curSec;
    $ok = ($actual === $expected);
    $results[$ok ? 'pass' : 'fail']++;
    $sections[$curSec][$ok ? 'pass' : 'fail']++;
    $sections[$curSec]['lines'][] = $ok
        ? ['ok' => true,  'label' => $label]
        : ['ok' => false, 'label' => $label,
           'expected' => var_export($expected, true),
           'actual'   => var_export($actual,   true)];
}

// ── DB de test en mémoire ─────────────────────────────────────────────────────
/** Crée une base SQLite en mémoire initialisée avec le schéma complet pour les tests. */
function makeTestDB(): PDO {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA foreign_keys=ON");
    initDB($db);
    return $db;
}

// ═════════════════════════════════════════════════════════════════════════════
// TESTS
// ═════════════════════════════════════════════════════════════════════════════

section('normaliserNumero()');
expect('Numéro standard 10 chiffres',  normaliserNumero('0612345678'),        '612345678');
expect('+33 → 0',                      normaliserNumero('+33612345678'),       '612345678');
expect('0033 → 0',                     normaliserNumero('0033612345678'),      '612345678');
expect('+33 avec espaces',             normaliserNumero('+33 6 12 34 56 78'), '612345678');
expect('0033 long (>9 chiffres)',       normaliserNumero('0033609442513'),     '609442513');
expect('Espaces ignorés',              normaliserNumero(' 06 12 34 56 78 '),  '612345678');
expect('Tirets ignorés',               normaliserNumero('06-12-34-56-78'),    '612345678');
expect('Déjà normalisé (9 chiffres)',  normaliserNumero('612345678'),         '612345678');
expect('Extension courte',             normaliserNumero('101'),               '101');
expect('Chaîne vide → vide',           normaliserNumero(''),                  '');
expect('Lettres seules → vide',        normaliserNumero('abc'),               '');
expect('Numéro int. long (> 9)',       normaliserNumero('+33 1 23 45 67 89'), '123456789');
expect('Zéros uniquement',             normaliserNumero('000000000'),         '000000000');
expect('1 seul chiffre',               normaliserNumero('5'),                 '5');
expect('Répondeur **1 → tel quel',     normaliserNumero('**1'),               '**1');
expect('Extension s → telle quelle',   normaliserNumero('s'),                 's');
expect('Redirection ##101 → telle quelle', normaliserNumero('##101'),         '##101');
expect('Transfert #*121 → tel quel',   normaliserNumero('#*121'),             '#*121');
expect('Masqué → vide (pas un spécial)', normaliserNumero('Masqué'),          '');
expect('Mot contenant s → vide',       normaliserNumero('assistance'),        '');

section('getNumeroSpecial()');
$db = makeTestDB();
expect('Extension locale connue',      getNumeroSpecial($db, '101'),         'Softphone');
expect('Code étoile (**1)',            getNumeroSpecial($db, '**1'),         'Répondeur');
expect('999 (retrait blacklist)',       getNumeroSpecial($db, '999'),         'Retrait blacklist');
expect('Inconnu → null',               getNumeroSpecial($db, '999999'),      null);
expect('Chaîne vide → null',           getNumeroSpecial($db, ''),            null);
expect('Espaces → null (invalide)',     getNumeroSpecial($db, '1 2 3'),       null);
expect("Injection SQL → null",         getNumeroSpecial($db, "' OR '1'='1"), null);
$db->exec("INSERT OR REPLACE INTO numeros_speciaux (numero, label, categorie) VALUES ('s', 'Contexte système', 'system')");
expect("Extension alpha 's' reconnue",     getNumeroSpecial($db, 's'),   'Contexte système');
expect("Extension alpha 'S' (casse diff)", getNumeroSpecial($db, 'S'),   null);
expect("Lettres invalides (espace)",       getNumeroSpecial($db, 's 1'), null);
expect("Lettre non autorisée 'a' → null",      getNumeroSpecial($db, 'a'),            null);
expect("Caractère '+' non autorisé → null",     getNumeroSpecial($db, '+33612345678'), null);
expect("Préfixe '##101' → redirection directe", getNumeroSpecial($db, '##101'),        'Redirection → 101');
expect("Préfixe '#*121' → transfert",           getNumeroSpecial($db, '#*121'),         'Transfert → 121');
expect("'##' seul sans extension → null",       getNumeroSpecial($db, '##'),            null);
expect("'#*' seul sans extension → null",       getNumeroSpecial($db, '#*'),            null);

section('infosLigne() — préfixes ## et #*');
// Appel sortant vers ##111 (redirection directe) : doit être reconnu comme numéro spécial
$rowOut  = ['src' => '101', 'dst' => '##111', 'duree' => 0, 'etat' => 'ANSWERED', 'clid' => '', 'uniqueid' => 'u1', 'date_appel' => '2024-01-01 10:00:00'];
// Appel entrant depuis #*121 (transfert supervisé) : doit être reconnu comme numéro spécial
$rowIn   = ['src' => '#*121', 'dst' => '101', 'duree' => 5, 'etat' => 'ANSWERED', 'clid' => '', 'uniqueid' => 'u2', 'date_appel' => '2024-01-01 10:01:00'];
$infOut  = infosLigne($rowOut, [], []);
$infIn   = infosLigne($rowIn,  [], []);
expect('##111 sortant : numSpecial non null',    $infOut['numSpecial'] !== null,                                 true);
expect('##111 sortant : label redirection',      $infOut['numSpecial']['label'],                                 t('index.redirect',  ['ext' => '111']));
expect('##111 sortant : catégorie system',       $infOut['numSpecial']['categorie'],                             'system');
expect('#*121 entrant : numSpecial non null',    $infIn['numSpecial']  !== null,                                 true);
expect('#*121 entrant : label transfert',        $infIn['numSpecial']['label'],                                  t('index.transfert', ['ext' => '121']));
expect('#*121 entrant : catégorie system',       $infIn['numSpecial']['categorie'],                              'system');
expect('Numéro ordinaire : numSpecial null',     infosLigne(['src'=>'612345678','dst'=>'101','duree'=>10,'etat'=>'ANSWERED','clid'=>'','uniqueid'=>'u3','date_appel'=>'2024-01-01 10:02:00'], [], [])['numSpecial'], null);
expect('0033 entrant : affiché 0609442513',      infosLigne(['src'=>'0033609442513','dst'=>'101','duree'=>174,'etat'=>'ANSWERED','clid'=>'','uniqueid'=>'u4','date_appel'=>'2026-04-07 14:21:40'], [], [])['numExterne'],
    '0609442513');
expect('+33 entrant : affiché 0677517188',       infosLigne(['src'=>'+33677517188','dst'=>'101','duree'=>241,'etat'=>'ANSWERED','clid'=>'','uniqueid'=>'u5','date_appel'=>'2026-04-07 15:17:34'], [], [])['numExterne'],
    '0677517188');

section('formatDuree()');
expect('0 seconde → tiret',        formatDuree(0),   '—');
expect('Négatif → tiret',          formatDuree(-5),  '—');
expect('59 secondes',              formatDuree(59),  '59s');
expect('60 secondes → 1m',        formatDuree(60),  '1m');
expect('61 secondes → 1m 1s',     formatDuree(61),  '1m 1s');
expect('3600 secondes → 60m',     formatDuree(3600),'60m');
expect('3661 secondes → 61m 1s',  formatDuree(3661),'61m 1s');

section('chercherContactParNumero()');
$db = makeTestDB();
$db->exec("INSERT INTO contacts (nom) VALUES ('Alice')"); $aliceId = (int)$db->lastInsertId();
$db->exec("INSERT INTO contacts (nom) VALUES ('Bob')");   $bobId   = (int)$db->lastInsertId();
$db->exec("INSERT INTO numeros (numero, contact_id, type) VALUES ('612000001', $aliceId, 'portable')");
$db->exec("INSERT INTO numeros (numero, contact_id, type) VALUES ('612000002', $bobId,   'domicile')");
$db->exec("INSERT INTO numeros (numero, contact_id, type) VALUES ('612000003', $aliceId, 'travail')");
$db->exec("INSERT INTO numeros (numero, contact_id, type) VALUES ('612000003', $bobId,   'travail')");
$res = chercherContactParNumero($db, '612000001');
expect('Contact Alice trouvé',         $res['noms'],                         ['Alice']);
expect('Type portable correct',        $res['type'],                         'portable');
expect('Numéro inconnu → null',        chercherContactParNumero($db, '999999999'), null);
expect('Numéro vide → null',           chercherContactParNumero($db, ''),    null);
$res2 = chercherContactParNumero($db, '612000003');
expect('Ambiguïté : 2 noms retournés', count($res2['noms']),                 2);

section('trouverOuCreerContact() + lierNumero()');
$db2 = makeTestDB();
$r   = trouverOuCreerContact($db2, 'Charlie');
expect('Création → created=true',      $r['created'],                        true);
expect('ID > 0',                       $r['id'] > 0,                         true);
$r2  = trouverOuCreerContact($db2, 'Charlie');
expect('Doublon → created=false',      $r2['created'],                       false);
expect('Même ID retourné',             $r2['id'],                            $r['id']);
expect('lierNumero → created',         lierNumero($db2, $r['id'], '612000010', 'portable'), 'created');
expect('lierNumero → exists',          lierNumero($db2, $r['id'], '612000010', 'portable'), 'exists');
expect('lierNumero → updated',         lierNumero($db2, $r['id'], '612000010', 'domicile'), 'updated');

section('Auth — hashPassword() + DB');
require_once __DIR__ . '/auth.php';
$hash = hashPassword('monMotDePasse123');
expect('Hash non vide',                strlen($hash) > 0,                              true);
expect('Hash différent du mdp clair',  $hash !== 'monMotDePasse123',                  true);
expect('password_verify valide',       password_verify('monMotDePasse123', $hash),    true);
expect('password_verify invalide',     password_verify('mauvais', $hash),             false);
// Vérifie que l'algorithme effectivement utilisé correspond à celui attendu
// (Argon2id si dispo, sinon bcrypt) — garantit que hashPassword() respecte sa stratégie.
$algoAttendu = defined('PASSWORD_ARGON2ID') ? 'argon2id' : '2y';
expect('Algorithme attendu utilisé',   password_get_info($hash)['algoName'],          $algoAttendu);

$authDb = new PDO('sqlite::memory:');
$authDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$authDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$authDb->exec("PRAGMA foreign_keys=ON");
initAuthDB($authDb);

$cols = array_column($authDb->query("PRAGMA table_info(users)")->fetchAll(), 'name');
expect('Table users : colonne username',      in_array('username',      $cols, true), true);
expect('Table users : colonne password_hash', in_array('password_hash', $cols, true), true);
expect('Table users : colonne role',          in_array('role',          $cols, true), true);

section('Auth — isLockedOut()');
// Aucune tentative → pas verrouillé
expect('0 tentative → non verrouillé',  isLockedOut($authDb, 'alice', '127.0.0.1'), false);
// Injection de MAX_LOGIN_ATTEMPTS tentatives échouées récentes
$insStmt = $authDb->prepare(
    "INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?,?,0, datetime('now','localtime'))"
);
for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) {
    $insStmt->execute(['alice', '127.0.0.1']);
}
expect('5 tentatives échouées → verrouillé',    isLockedOut($authDb, 'alice', '127.0.0.1'), true);
expect('Username différent, IP propre → libre', isLockedOut($authDb, 'bob',   '10.0.0.1'),  false);
// Verrouillage par IP : l'IP est verrouillée, même pour un username inconnu
$insStmt2 = $authDb->prepare(
    "INSERT INTO login_attempts (username, ip, success, attempted_at) VALUES (?,?,0, datetime('now','localtime'))"
);
for ($i = 0; $i < MAX_LOGIN_ATTEMPTS; $i++) {
    $insStmt2->execute(['carol', '192.168.1.100']);
}
expect('IP verrouillée → autre username bloqué', isLockedOut($authDb, 'dave', '192.168.1.100'), true);

section('CSRF');
csrfStart();
$token = csrfToken();
expect('Token non vide',               strlen($token) > 0,            true);
expect('Token hexadécimal',            ctype_xdigit($token),          true);
expect('Token stable en session',      csrfToken(),                   $token);
expect('csrfField() contient le token', str_contains(csrfField(), $token), true);

section('afficherNumero()');
$rA = afficherNumero('612345678');
expect('9 chiffres : tel = 0612345678',         $rA['tel'],                             '0612345678');
expect('9 chiffres : html commence par "06"',   str_starts_with($rA['html'], '06'),     true);
expect('9 chiffres : contient num-sp',          str_contains($rA['html'], 'num-sp'),    true);
expect('9 chiffres : 4 séparateurs',            substr_count($rA['html'], 'num-sp'),    4);
$rB = afficherNumero('**1');
expect('Spécial **1 : tel inchangé',            $rB['tel'],                             '**1');
expect('Spécial **1 : pas de num-sp',           str_contains($rB['html'], 'num-sp'),    false);
$rC = afficherNumero('101');
expect('Extension 101 : tel inchangé',          $rC['tel'],                             '101');
expect('Extension 101 : pas de num-sp',         str_contains($rC['html'], 'num-sp'),    false);
$rD = afficherNumero('#*121');
expect('Transfert #*121 : tel inchangé',        $rD['tel'],                             '#*121');
$rE = afficherNumero('');
expect('Vide : tel vide',                       $rE['tel'],                             '');
expect('Vide : html vide',                      $rE['html'],                            '');

// ═════════════════════════════════════════════════════════════════════════════

// ═════════════════════════════════════════════════════════════════════════════
// RENDU
// ═════════════════════════════════════════════════════════════════════════════
$elapsed = round((microtime(true) - $startTime) * 1000);
$total   = $results['pass'] + $results['fail'];
$allOk   = $results['fail'] === 0;

if (!$isCli) {
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Tests</title><style>
body{font-family:monospace;background:#1e1e2e;color:#cdd6f4;margin:0;padding:24px;font-size:14px}
h1{color:#cba6f7;margin:0 0 20px;font-size:1.1rem}
.sec{margin:0 0 18px}
.sec-head{display:flex;align-items:center;gap:10px;padding:6px 12px;background:#313244;border-radius:6px 6px 0 0;font-weight:bold;color:#89b4fa}
.sec-body{border:1px solid #313244;border-top:none;border-radius:0 0 6px 6px;padding:4px 0}
.row{display:flex;align-items:baseline;gap:8px;padding:3px 12px}.row:hover{background:#2a2a3e}
.icon{width:18px;text-align:center}.lbl{flex:1}
.diff{font-size:.85em;color:#6c7086}
.exp{color:#a6e3a1}.act{color:#f38ba8}
.badge{font-size:.72em;padding:1px 8px;border-radius:10px;font-weight:bold}
.ok{background:#a6e3a120;color:#a6e3a1}.nok{background:#f38ba820;color:#f38ba8}
.summary{margin-top:24px;padding:14px 18px;border-radius:8px;display:flex;align-items:center;gap:16px}
.all-ok{background:#a6e3a110;border:1px solid #a6e3a140}
.has-err{background:#f38ba810;border:1px solid #f38ba840}
.big{font-size:2rem;font-weight:bold}.ok-big{color:#a6e3a1}.nok-big{color:#f38ba8}
.meta{font-size:.8em;color:#6c7086;margin-top:2px}
</style></head><body><h1>🧪 Tests unitaires</h1>';
}

foreach ($sections as $sec) {
    $secOk    = $sec['fail'] === 0;
    $secTotal = $sec['pass'] + $sec['fail'];
    $badge    = $sec['pass'] . '/' . $secTotal;

    if ($isCli) {
        $icon = $secOk ? ansi('✓', '32') : ansi('✗', '31');
        echo "\n" . ansi('▸ ' . $sec['title'], $secOk ? '1;34' : '1;31')
           . '  ' . ansi("[$badge]", $secOk ? '32' : '31') . "\n";
        foreach ($sec['lines'] as $l) {
            if ($l['ok']) {
                echo '  ' . ansi('✓', '32') . '  ' . $l['label'] . "\n";
            } else {
                echo '  ' . ansi('✗', '1;31') . '  ' . ansi($l['label'], '1;31') . "\n";
                echo '      ' . ansi('Attendu : ', '33') . ansi($l['expected'], '32') . "\n";
                echo '      ' . ansi('Obtenu  : ', '33') . ansi($l['actual'],   '31') . "\n";
            }
        }
    } else {
        echo '<div class="sec"><div class="sec-head">'
           . htmlspecialchars($sec['title'])
           . ' <span class="badge ' . ($secOk ? 'ok' : 'nok') . '">' . $badge . '</span></div>'
           . '<div class="sec-body">';
        foreach ($sec['lines'] as $l) {
            if ($l['ok']) {
                echo '<div class="row">'
                   . '<span class="icon" style="color:#a6e3a1">✓</span>'
                   . '<span class="lbl">' . htmlspecialchars($l['label']) . '</span></div>';
            } else {
                echo '<div class="row">'
                   . '<span class="icon" style="color:#f38ba8">✗</span>'
                   . '<span class="lbl" style="color:#f38ba8">' . htmlspecialchars($l['label']) . '</span>'
                   . '<span class="diff">attendu <span class="exp">' . htmlspecialchars($l['expected']) . '</span>'
                   . ' · obtenu <span class="act">' . htmlspecialchars($l['actual']) . '</span></span></div>';
            }
        }
        echo '</div></div>';
    }
}

// Résumé
if ($isCli) {
    $line = str_repeat('─', 48);
    echo "\n" . ansi($line, '90') . "\n";
    echo $allOk
        ? ansi('  ✓ Tous les tests passés', '1;32') . ansi("  ($total/$total · {$elapsed}ms)", '90') . "\n"
        : ansi("  ✗ {$results['fail']} échec(s) sur $total · {$elapsed}ms", '1;31') . "\n";
    echo ansi($line, '90') . "\n";
} else {
    echo '<div class="summary ' . ($allOk ? 'all-ok' : 'has-err') . '">';
    echo '<span class="big ' . ($allOk ? 'ok-big' : 'nok-big') . '">' . ($allOk ? '✓' : '✗') . '</span>';
    echo '<div>';
    echo $allOk
        ? '<strong>Tous les tests sont passés</strong>'
        : '<strong style="color:#f38ba8">' . $results['fail'] . ' échec(s)</strong>';
    echo '<div class="meta">' . $results['pass'] . '/' . $total . ' réussis · ' . $elapsed . ' ms</div>';
    echo '</div></div></body></html>';
}

exit($results['fail'] > 0 ? 1 : 0);
