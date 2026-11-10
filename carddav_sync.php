<?php
/**
 * Synchronisation des carnets CardDAV actifs, pour une tâche planifiée.
 * Usage : php carddav_sync.php [--forcer]
 * Sans --forcer, un carnet dont l'étiquette de version (ctag) n'a pas changé n'est pas relu.
 * Code de sortie : 0 si tout va bien, 1 si un carnet a échoué, 2 si une autre synchro tourne.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require __DIR__ . '/config.php';
require_once __DIR__ . '/carddav_functions.php';

$r = carddavSynchroniserTout(getDB(), in_array('--forcer', $argv, true));
if (!$r['verrou']) {
    fwrite(STDERR, t('carnets.err_verrou') . "\n");
    exit(2);
}
$echec = false;
foreach ($r['resultats'] as $res) {
    echo date('Y-m-d H:i:s') . ' ' . ($res['ok'] ? 'OK ' : 'ERR') . ' ' . $res['nom'] . ' : ' . $res['msg'] . "\n";
    $echec = $echec || !$res['ok'];
}
if (!$r['resultats']) echo date('Y-m-d H:i:s') . " aucun carnet actif\n";
exit($echec ? 1 : 0);
