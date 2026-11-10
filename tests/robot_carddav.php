<?php
/**
 * Robot d'essai de la synchronisation CardDAV, de bout en bout contre tests/faux_carddav.php.
 * Usage : php tests/robot_carddav.php   (php doit avoir les extensions openssl et pdo_sqlite)
 * Rend une ligne de verdict ; code de sortie 0 si tout passe, 1 sinon.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$tmp = sys_get_temp_dir() . '/robot_carddav_' . getmypid();
@mkdir($tmp);
define('CARDDAV_KEY_PATH', $tmp . '/carddav.key');
require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/carddav_functions.php';

$echecs = [];
$total  = 0;
/** Note une vérification ; affiche le détail si elle échoue. */
function verifier(string $libelle, mixed $obtenu, mixed $attendu): void {
    global $echecs, $total;
    $total++;
    if ($obtenu !== $attendu) {
        $echecs[] = $libelle;
        echo "ÉCHEC $libelle : attendu " . var_export($attendu, true) . ', obtenu ' . var_export($obtenu, true) . "\n";
    }
}

/** vCard 3.0 minimale. */
function fiche(string $nom, array $tels): string {
    $v = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:$nom\r\n";
    foreach ($tels as $type => $num) $v .= "TEL;TYPE=$type:$num\r\n";
    return $v . "END:VCARD\r\n";
}

$etatFichier = $tmp . '/etat.json';
$etat = ['carnets' => [
    'perso' => ['nom' => 'Perso', 'report' => true, 'fiches' => [
        'a.vcf' => fiche('Alice Robot', ['CELL' => '06 39 98 00 81', 'HOME' => '01 99 00 00 82']),
        'b.vcf' => fiche('Bruno Robot', ['WORK' => '+33 6 39 98 00 83']),
    ]],
    'pro' => ['nom' => 'Pro', 'report' => false, 'fiches' => [
        'c.vcf' => fiche('Chloé Robot', ['CELL' => '0639980084']),
        'd.vcf' => fiche('Alice Robot', ['WORK' => '0639980085']),
    ]],
]];
$ecrire = function () use (&$etat, $etatFichier) { file_put_contents($etatFichier, json_encode($etat)); };
$ecrire();

// ── Faux serveur ──────────────────────────────────────────────────────────────
$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$env = getenv() + ['FAUX_ETAT' => $etatFichier];
$env['FAUX_ETAT'] = $etatFichier;
$serveur = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/faux_carddav.php'],
                     [1 => ['file', $tmp . '/serveur.log', 'a'], 2 => ['file', $tmp . '/serveur.log', 'a']], $pipes, null, $env);
$arreter = function () use (&$serveur) {
    if (is_resource($serveur)) {
        $st = proc_get_status($serveur);
        if (PHP_OS_FAMILY === 'Windows') exec('taskkill /F /T /PID ' . (int)$st['pid'] . ' 2>NUL');
        else proc_terminate($serveur);
        proc_close($serveur);
    }
};
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e1, $e2, 0.1); $i++) usleep(100000);
$racine = "http://127.0.0.1:$port/";

try {
    // ── Découverte ────────────────────────────────────────────────────────────
    $d = carddavDecouvrir($racine, 'alice', 'mdp-alice');
    verifier('Découverte depuis la racine', [$d['ok'], $d['direct'], array_column($d['carnets'], 'nom')], [true, false, ['Perso', 'Pro']]);
    $urlPerso = $d['carnets'][0]['url'] ?? '';
    $urlPro   = $d['carnets'][1]['url'] ?? '';
    $d = carddavDecouvrir($urlPerso, 'alice', 'mdp-alice');
    verifier('Adresse directe d\'un carnet', [$d['ok'], $d['direct'], $d['carnets'][0]['nom'] ?? null], [true, true, 'Perso']);
    $d = carddavDecouvrir($racine, 'alice', 'faux');
    verifier('Mauvais mot de passe refusé', [$d['ok'], str_contains($d['msg'], '401')], [false, true]);

    // ── Première synchro ──────────────────────────────────────────────────────
    $db = new PDO('sqlite:' . $tmp . '/journal.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec("PRAGMA foreign_keys=ON");
    initDB($db);
    $ins = $db->prepare("INSERT INTO carnets (nom, url, utilisateur, mot_de_passe) VALUES (?,?,?,?)");
    $ins->execute(['Perso', $urlPerso, 'alice', carddavChiffrer('mdp-alice')]);
    $ins->execute(['Pro',   $urlPro,   'alice', carddavChiffrer('mdp-alice')]);
    verifier('Mot de passe chiffré en base', str_contains(json_encode($db->query("SELECT * FROM carnets")->fetchAll()), 'mdp-alice'), false);

    $nom = fn(string $n) => chercherContactParNumero($db, $n)['noms'] ?? null;
    $r = carddavSynchroniserTout($db, false);
    verifier('Synchro des deux carnets', array_column($r['resultats'], 'ok'), [true, true]);
    verifier('Carnet Perso (REPORT) lu', [$nom('639980081'), $nom('199000082'), $nom('639980083')], [['Alice Robot'], ['Alice Robot'], ['Bruno Robot']]);
    verifier('Carnet Pro (multiget) lu', [$nom('639980084'), $nom('639980085')], [['Chloé Robot'], ['Alice Robot']]);
    verifier('Alice fusionnée entre carnets', (int)$db->query("SELECT COUNT(*) FROM contacts WHERE nom='Alice Robot'")->fetchColumn(), 1);
    verifier('Type de numéro repris', chercherContactParNumero($db, '639980083')['type'] ?? null, 'travail');

    // ── Sans changement ───────────────────────────────────────────────────────
    $r = carddavSynchroniserTout($db, false);
    verifier('Carnets inchangés non relus', array_column($r['resultats'], 'inchange'), [true, true]);

    // ── Changements côté serveur ──────────────────────────────────────────────
    unset($etat['carnets']['perso']['fiches']['b.vcf']);
    $etat['carnets']['pro']['fiches']['c.vcf'] = fiche('Chloé Robot', ['CELL' => '0639980094']);
    $ecrire();
    $r = carddavSynchroniserTout($db, false);
    verifier('Changements relus', array_column($r['resultats'], 'inchange'), [false, false]);
    verifier('Fiche supprimée : numéro retiré', $nom('639980083'), null);
    verifier('Fiche supprimée : contact retiré', (int)$db->query("SELECT COUNT(*) FROM contacts WHERE nom='Bruno Robot'")->fetchColumn(), 0);
    verifier('Numéro changé', [$nom('639980084'), $nom('639980094')], [null, ['Chloé Robot']]);

    // ── Pannes : rien ne doit être retiré ─────────────────────────────────────
    $avant = (int)$db->query("SELECT COUNT(*) FROM numeros")->fetchColumn();
    $etat['carnets']['perso']['fiches'] = [];
    $ecrire();
    $r = carddavSynchroniserTout($db, true);
    verifier('Carnet reçu vide refusé', $r['resultats'][0]['ok'], false);
    verifier('Carnet vide : rien retiré', (int)$db->query("SELECT COUNT(*) FROM numeros")->fetchColumn(), $avant);
    $db->exec("UPDATE carnets SET mot_de_passe = '" . carddavChiffrer('faux') . "' WHERE nom = 'Pro'");
    $r = carddavSynchroniserTout($db, true);
    verifier('Mot de passe changé : erreur 401', str_contains($r['resultats'][1]['msg'], '401'), true);
    $arreter();
    $r = carddavSynchroniserTout($db, true);
    verifier('Serveur arrêté : erreur réseau', array_column($r['resultats'], 'ok'), [false, false]);
    verifier('Serveur arrêté : rien retiré', (int)$db->query("SELECT COUNT(*) FROM numeros")->fetchColumn(), $avant);
    verifier('État d\'échec noté', (int)$db->query("SELECT SUM(dernier_ok) FROM carnets")->fetchColumn(), 0);

    // ── Suppression d'un carnet ───────────────────────────────────────────────
    $db->exec("DELETE FROM carnets WHERE nom = 'Pro'");
    carddavNettoyer($db);
    verifier('Carnet supprimé : Chloé retirée', $nom('639980094'), null);
    verifier('Carnet supprimé : Alice gardée par Perso', $nom('639980081'), ['Alice Robot']);
} finally {
    $arreter();
    $db = $ins = $nom = null;
    gc_collect_cycles();
    foreach (glob($tmp . '/*') as $f) @unlink($f);
    @rmdir($tmp);
}

echo $echecs ? "ROBOT CARDDAV : " . count($echecs) . " échec(s) sur $total\n" : "ROBOT CARDDAV : OK ($total/$total)\n";
exit($echecs ? 1 : 0);
