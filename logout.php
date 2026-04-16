<?php
/**
 * Déconnexion sécurisée.
 * Accepte uniquement les requêtes POST avec token CSRF valide.
 */
require 'config.php';
require 'auth.php';
csrfStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    // Destruction complète de la session et du cookie
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

header('Location: login.php');
exit;