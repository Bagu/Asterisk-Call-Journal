<?php
/**
 * Détection de la langue du navigateur et chargement du fichier de traduction.
 * Fallback sur le français si la langue détectée n'est pas disponible.
 */

define('LANG_DIR',     __DIR__ . '/locales/');
define('LANG_DEFAULT', 'fr');

/** Détecte la langue préférée du navigateur parmi les fichiers disponibles dans locales/. */
function detectLang(): string {
    $files = glob(LANG_DIR . '*.php') ?: [];
    $available = array_map(fn($f) => basename($f, '.php'), $files);
    $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    // Ex : "fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7"
    preg_match_all('/([a-z]{2})(?:-[A-Z]{2})?(?:;q=[\d.]+)?/', strtolower($header), $m);
    foreach ($m[1] as $lang) {
        if (in_array($lang, $available, true)) return $lang;
    }
    return LANG_DEFAULT;
}

/** Charge le fichier de traduction en mémoire. */
function loadLang(): void {
    global $_LANG;
    if (!is_dir(LANG_DIR)) {
        error_log('[lang] Dossier de locales introuvable : ' . LANG_DIR);
        $_LANG = [];
        return;
    }
    $lang = detectLang();
    $file = LANG_DIR . $lang . '.php';
    if (!is_file($file)) $file = LANG_DIR . LANG_DEFAULT . '.php';
    if (!is_file($file)) {
        error_log('[lang] Fichier de traduction introuvable : ' . $file);
        $_LANG = [];
        return;
    }
    $_LANG = require $file;
}

$_LANG = [];
loadLang();

/**
 * Retourne la traduction d'une clé.
 * Remplace les variables {nom} par les valeurs du tableau $params.
 * Retourne la clé elle-même si la traduction est absente.
 */
function t(string $key, array $params = []): string {
    global $_LANG;
    $str = $_LANG[$key] ?? $key;
    foreach ($params as $k => $v) {
        $str = str_replace('{' . $k . '}', (string)$v, $str);
    }
    return $str;
}