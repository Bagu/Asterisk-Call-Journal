<?php
ob_start(); // Indispensable : permet aux handlers d'erreur de nettoyer la sortie avant d'envoyer du JSON

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    if (ob_get_level() > 0) ob_clean();
    error_log("[sync] Erreur PHP [$errno] : $errstr ($errfile:$errline)");
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => "Erreur interne du serveur."]);
    exit;
});

set_exception_handler(function(Throwable $e): void {
    if (ob_get_level() > 0) ob_clean();
    error_log("[sync] Exception : " . $e->getMessage() . " ({$e->getFile()}:{$e->getLine()})");
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => "Erreur interne du serveur."]);
    exit;
});

require 'config.php';
require 'auth.php';
requireLogin(); // Requête AJAX : retourne JSON 401 si session expirée

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => t('sync.err_method')]);
    exit;
}
csrfVerify();

if (!function_exists('exec')) {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => t('sync.err_exec')]);
    exit;
}

$pyScript = __DIR__ . '/sync_calls.py';
if (!is_file($pyScript) || !is_readable($pyScript)) {
    if (ob_get_level() > 0) ob_clean();
    error_log("[sync] Script Python introuvable : $pyScript");
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'msg' => t('sync.err_script')]);
    exit;
}

// ── Lecture de PYTHON_EXE depuis le fichier .env ──────────────────────────────
$envPath   = '/path/to/journal.env';
$pythonExe = PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3';

if (is_readable($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        if (trim($k) === 'PYTHON_EXE') {
            $candidate = trim($v, "\"'");
            if ($candidate !== ''
                && !preg_match('/[;&|`$<>\n\r"\']/', $candidate)
                && is_file($candidate)
                && is_executable($candidate)) {
                $pythonExe = $candidate;
            } else {
                error_log("[sync] PYTHON_EXE invalide ignoré : $candidate");
            }
            break;
        }
    }
}

// ── Verrou pour éviter les synchronisations concurrentes ──────────────────────
// Deux utilisateurs cliquant simultanément pourraient corrompre la DB ou le CSV distant.
$lockFile = __DIR__ . '/sync.lock';
$lockFp   = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) fclose($lockFp);
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => t('sync.err_busy')]);
    exit;
}

// ── Exécution du script Python ────────────────────────────────────────────────
$prefix = PHP_OS_FAMILY === 'Windows' ? 'set PYTHONIOENCODING=utf-8 && ' : '';
$cmd    = $prefix . escapeshellarg($pythonExe) . ' ' . escapeshellarg($pyScript) . ' 2>&1';

$output = [];
$code   = 0;
exec($cmd, $output, $code);

// Libération du verrou dès que exec() est terminé
flock($lockFp, LOCK_UN);
fclose($lockFp);

if (ob_get_level() > 0) ob_clean();
header('Content-Type: application/json');
if ($code === 0) {
    // Première ligne de stdout Python : résultat métier ("OK: 3 nouvel(aux) appel(s)" ou "Journal à jour")
    $msg = trim($output[0] ?? '') ?: t('sync.done');
} else {
    // Erreur : on journalise le détail côté serveur, on retourne un message générique
    error_log('[sync_calls] code=' . $code . ' : ' . implode(' | ', array_slice($output, 0, 10)));
    $msg = t('sync.err_code', ['code' => $code]);
}
echo json_encode(['ok' => ($code === 0), 'msg' => $msg]);
