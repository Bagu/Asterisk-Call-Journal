<?php
/**
 * Page de connexion.
 * Gère le rate-limiting (verrouillage affiché), la protection CSRF et la redirection post-login.
 * Si aucun utilisateur n'existe, affiche les instructions CLI de bootstrap.
 */
require 'config.php';
require 'auth.php';
sendSecurityHeaders();
csrfStart();

// Déjà connecté → redirection immédiate
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$authDb    = getAuthDB();
$userCount = (int)$authDb->query("SELECT COUNT(*) FROM users")->fetchColumn();

// Sanitize du paramètre next depuis GET (affiché dans le formulaire)
// Le helper sanitizeNext() est défini dans auth.php
$next = sanitizeNext($_GET['next'] ?? '');

$error  = '';
$locked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userCount > 0) {
    csrfVerify();
    // Re-valide next depuis POST (le champ hidden peut avoir été altéré)
    $next     = sanitizeNext($_POST['next'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = t('login.err_empty');
    } else {
        $result = attemptLogin($username, $password);
        if ($result === true) {
            // Redirection vers la page demandée ou le journal par défaut
            $redirect = ($next !== '') ? $next : 'index.php';
            header('Location: ' . $redirect);
            exit;
        } elseif ($result === 'locked') {
            $locked = true;
            $error  = t('login.err_locked', ['min' => LOCKOUT_MINUTES]);
        } else {
            // Message volontairement générique pour ne pas révéler si le compte existe
            $error = t('login.err_invalid');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detectLang()) ?>">
<head>
    <meta charset="UTF-8">
	<meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(t('login.title')) ?></title>
    <link rel="icon" type="image/png" href="./favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Centrage vertical de la carte sur tout le viewport */
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:16px; }
        /* Carte de login centrée, largeur fixe */
        .login-card { background:white; border-radius:10px; padding:32px 28px; box-shadow:0 2px 12px #0002; width:100%; max-width:360px; }
        /* Titre avec icône centré */
        .login-card h1 { margin:0 0 24px; font-size:1.2rem; text-align:center; color:#1a1a2e; }
        /* Champs pleine largeur */
        .login-card input[type=text],
        .login-card input[type=password] { width:100%; margin-bottom:12px; }
        /* Bouton pleine largeur */
        .login-card .btn { width:100%; padding:10px; font-size:.95rem; }
        /* Message d'information (aucun utilisateur configuré) */
        .login-info { background:#e3f2fd; color:#1565c0; border:1px solid #90caf9; border-radius:6px; padding:10px 14px; margin-bottom:16px; font-size:.85rem; line-height:1.6; }
        /* Code inline dans la carte */
        .login-info code { background:#d0e8f8; padding:1px 5px; border-radius:3px; font-size:.82rem; }
    </style>
</head>
<body>
<div class="login-card">
    <h1>📞 <?= htmlspecialchars(t('index.title')) ?></h1>

    <?php if ($userCount === 0): ?>
        <div class="login-info">
            ℹ️ <?= htmlspecialchars(t('login.no_user')) ?><br>
            <?= htmlspecialchars(t('login.bootstrap_cli')) ?><br><br>
            <code>php manage_users.php add &lt;nom&gt; &lt;mdp&gt; admin</code><br><br>
            <?= htmlspecialchars(t('login.bootstrap_reset')) ?><br>
            <code>php manage_users.php reset-password &lt;nom&gt; &lt;nouveau_mdp&gt;</code>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="alert alert-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <?php if ($next): ?>
                <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <?php endif; ?>
            <label class="lbl"><?= htmlspecialchars(t('login.username')) ?></label>
            <input type="text" name="username" autocomplete="username"
                   <?= $locked ? 'disabled' : '' ?> required
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            <label class="lbl"><?= htmlspecialchars(t('login.password')) ?></label>
            <input type="password" name="password" autocomplete="current-password"
                   <?= $locked ? 'disabled' : '' ?> required>
            <button type="submit" class="btn btn-blue" <?= $locked ? 'disabled' : '' ?>>
                <?= htmlspecialchars(t('login.submit')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
