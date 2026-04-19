<?php
/**
 * Authentification — fonctions partagées par toutes les pages protégées.
 * DB séparée : users.db (ne contient que les comptes, jamais les mots de passe en clair).
 * Hash : Argon2id si disponible (PHP 7.3+), sinon bcrypt.
 */
require_once __DIR__ . '/config.php';

/** Chemin de la base SQLite d'authentification, séparée de journal.db. */
define('AUTH_DB_PATH',      __DIR__ . '/users.db');
/** Nombre de tentatives échouées avant verrouillage temporaire. */
define('MAX_LOGIN_ATTEMPTS', 5);
/** Durée du verrouillage en minutes. */
define('LOCKOUT_MINUTES',   15);

// ── Base d'authentification ───────────────────────────────────────────────────

/**
 * Retourne l'instance PDO SQLite de la base d'authentification (singleton).
 * Configure WAL et initialise le schéma si nécessaire.
 */
function getAuthDB(): PDO {
    static $authDb = null;
    if ($authDb !== null) return $authDb;
    $authDb = new PDO('sqlite:' . AUTH_DB_PATH);
    $authDb->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $authDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $authDb->exec("PRAGMA journal_mode=WAL");
    $authDb->exec("PRAGMA synchronous=NORMAL");
    $authDb->exec("PRAGMA foreign_keys=ON");
    initAuthDB($authDb);
    return $authDb;
}

/**
 * Crée les tables users et login_attempts si elles n'existent pas.
 * Les mots de passe ne sont jamais stockés en clair (hash uniquement).
 */
function initAuthDB(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        role          TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin','user')),
        created_at    TEXT DEFAULT (datetime('now','localtime')),
        last_login    TEXT
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        username     TEXT NOT NULL,
        ip           TEXT NOT NULL,
        attempted_at TEXT DEFAULT (datetime('now','localtime')),
        success      INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_attempts_user_time ON login_attempts(username, attempted_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_attempts_ip_time   ON login_attempts(ip, attempted_at)");
}

// ── Hachage ───────────────────────────────────────────────────────────────────

/**
 * Retourne le hash sécurisé d'un mot de passe.
 * Préfère Argon2id (recommandé, PHP 7.3+), repli sur bcrypt.
 */
function hashPassword(string $password): string {
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($password, $algo);
}

// ── Anti-brute-force ──────────────────────────────────────────────────────────

/**
 * Retourne true si l'utilisateur ou l'IP est verrouillé (trop de tentatives récentes échouées).
 */
function isLockedOut(PDO $db, string $username, string $ip): bool {
    $cutoff = date('Y-m-d H:i:s', strtotime('-' . LOCKOUT_MINUTES . ' minutes'));
    $stmt   = $db->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE (username = ? OR ip = ?)
          AND attempted_at > ?
          AND success = 0
    ");
    $stmt->execute([$username, $ip, $cutoff]);
    return (int)$stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

/**
 * Enregistre une tentative de connexion (réussie ou non) et purge les entrées > 24 h.
 * Après une réussite, efface aussi les échecs récents de ce couple username/IP
 * pour éviter qu'un lockout se déclenche après une connexion légitime.
 */
function recordLoginAttempt(PDO $db, string $username, string $ip, bool $success): void {
    $db->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?,?,?)")
       ->execute([$username, $ip, $success ? 1 : 0]);
    if ($success) {
        // Une réussite remet le compteur à zéro pour ce username et cette IP
        $db->prepare("DELETE FROM login_attempts WHERE (username = ? OR ip = ?) AND success = 0")
           ->execute([$username, $ip]);
    }
    // Purge des anciennes tentatives pour garder la table légère
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now','-1 day')");
}

// ── Connexion / session ───────────────────────────────────────────────────────

/**
 * Tente une connexion avec identifiant et mot de passe.
 * Retourne true en cas de succès, 'locked' si verrouillé, false sinon.
 * Régénère l'ID de session après connexion réussie (anti-fixation).
 */
function attemptLogin(string $username, string $password): bool|string {
    $db = getAuthDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (isLockedOut($db, $username, $ip)) return 'locked';

    $stmt = $db->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($db, $username, $ip, false);
        return false;
    }

    recordLoginAttempt($db, $username, $ip, true);
    $db->prepare("UPDATE users SET last_login = datetime('now','localtime') WHERE id = ?")
       ->execute([$user['id']]);

    // Régénération de l'ID de session : prévient la fixation de session
    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'];

    return true;
}

/**
 * Exige qu'un utilisateur soit connecté.
 * Sur requête AJAX : répond en JSON 401 avec message traduit (pour que le JS gère la session expirée).
 * Sur requête normale : redirige vers login.php avec le paramètre ?next=.
 */
function requireLogin(): void {
    csrfStart();
    if (!empty($_SESSION['user_id'])) {
        // Vérifie que le compte existe toujours (suppression en cours de session)
        $stmt = getAuthDB()->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            // Régénère l'ID de session si le rôle a changé depuis la dernière requête
            // (protection contre la réutilisation d'une session avec d'anciens privilèges)
            if (($_SESSION['role'] ?? null) !== $user['role']) {
                session_regenerate_id(true);
            }
            // Rafraîchit les données de session (rôle peut avoir changé)
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            return;
        }
        session_destroy();
    }

    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
           && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($isAjax) {
        // Réponse JSON pour les appels AJAX (sync, check_new, rows…)
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['ok' => false, 'msg' => t('common.session_expired')]);
        exit;
    }

    // Sanitize du paramètre next : chemin relatif uniquement, sans traversée
    // ni redirection protocol-relative (//...) ou backslash
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $nextValid = str_starts_with($uri, '/')
              && !str_starts_with($uri, '//')
              && !str_contains($uri, '\\')
              && !str_contains($uri, '..')
              && preg_match('/^\/[a-zA-Z0-9\/_\-.?=&%]*$/', $uri);
    $next = $nextValid ? '?next=' . urlencode($uri) : '';
    header('Location: login.php' . $next);
    exit;
}

/**
 * Exige qu'un utilisateur connecté ait le rôle admin.
 * Retourne une page 403 traduite selon la langue du navigateur sinon.
 */
function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        sendSecurityHeaders();
        http_response_code(403);
        $lang = htmlspecialchars(detectLang());
        echo '<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="UTF-8">'
           . '<title>' . htmlspecialchars(t('common.access_denied')) . '</title>'
           . '<link rel="stylesheet" href="style.css"></head><body style="padding:40px;text-align:center">'
           . '<h1>⛔ ' . htmlspecialchars(t('common.access_denied')) . '</h1>'
           . '<p>' . htmlspecialchars(t('common.access_denied_msg')) . '</p>'
           . '<a href="index.php" class="btn btn-blue" style="text-decoration:none">'
           . htmlspecialchars(t('common.back_journal')) . '</a>'
           . '</body></html>';
        exit;
    }
}

/**
 * Retourne les informations de l'utilisateur connecté, ou null si non connecté.
 */
function currentUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role']     ?? 'user',
    ];
}

/**
 * Retourne true si l'utilisateur connecté est administrateur.
 */
function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Génère le HTML du bloc utilisateur pour la topbar.
 * Affiche le lien "Utilisateurs" uniquement pour les admins, et le bouton de déconnexion.
 * Les libellés sont traduits selon la langue détectée du navigateur.
 */
function topbarUserHtml(): string {
    $u = currentUser();
    if (!$u) return '';
    $adminLink = $u['role'] === 'admin'
        ? '<a href="admin_users.php" class="btn btn-gray" style="white-space:nowrap">👤 '
          . htmlspecialchars(t('nav.users')) . '</a>'
        : '';
    return $adminLink
         . '<form method="post" action="logout.php" style="display:inline">'
         . csrfField()
         . '<button type="submit" class="btn btn-gray" style="white-space:nowrap">🚪 '
         . htmlspecialchars(t('nav.logout')) . '</button>'
         . '</form>';
}
