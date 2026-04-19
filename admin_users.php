<?php
/**
 * Gestion des utilisateurs — interface web, réservée aux admins.
 * Permet : créer un compte, réinitialiser un mot de passe, changer le rôle, supprimer.
 * Les mots de passe acceptent tous les caractères (8 caractères minimum).
 * La suppression et la rétrogradation du dernier admin sont bloquées.
 */
require 'config.php';
require 'auth.php';
requireAdmin();
sendSecurityHeaders();
csrfStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify();

$authDb = getAuthDB();
$msg    = [];
$errors = [];
$action = $_POST['action'] ?? '';

/** Valide un identifiant : 2-64 caractères a-z A-Z 0-9 _ - */
function validUsername(string $u): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_\-]{2,64}$/', $u);
}

/** Valide un mot de passe : 8 caractères minimum, tous caractères acceptés. */
function validPassword(string $p): bool {
    return mb_strlen($p) >= 8;
}

/** Retourne le nombre d'administrateurs actifs. */
function countAdmins(PDO $db): int {
    return (int) $db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
}

// ── Ajout d'un utilisateur ────────────────────────────────────────────────────
if ($action === 'add_user') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = in_array($_POST['role'] ?? '', ['admin', 'user'], true) ? $_POST['role'] : 'user';

    if (!validUsername($username)) {
        $errors[] = t('users.err_username');
    } elseif (!validPassword($password)) {
        $errors[] = t('users.err_password');
    } else {
        try {
            $authDb->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)")
                   ->execute([$username, hashPassword($password), $role]);
            $msg[] = t('users.ok_created', ['name' => $username, 'role' => $role]);
        } catch (PDOException) {
            $errors[] = t('users.err_duplicate');
        }
    }
}

// ── Réinitialisation du mot de passe ─────────────────────────────────────────
if ($action === 'reset_password') {
    $userId   = (int)($_POST['user_id'] ?? 0);
    $password = $_POST['password'] ?? '';

    if (!validPassword($password)) {
        $errors[] = t('users.err_password');
    } else {
        $stmt = $authDb->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([hashPassword($password), $userId]);
        if ($stmt->rowCount()) $msg[]    = t('users.ok_pwd_reset');
        else                   $errors[] = t('users.err_not_found');
    }
}

// ── Changement de rôle ────────────────────────────────────────────────────────
if ($action === 'change_role') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role   = in_array($_POST['role'] ?? '', ['admin', 'user'], true) ? $_POST['role'] : '';

    if (!$role) {
        $errors[] = t('users.err_role');
    } elseif ($userId === (int)($_SESSION['user_id'] ?? 0)) {
        // Empêche un admin de modifier son propre rôle (cohérent avec delete_user)
        $errors[] = t('users.err_self_role');
    } else {
        // Empêche de rétrograder le dernier admin
        $row = $authDb->prepare("SELECT role FROM users WHERE id = ?");
        $row->execute([$userId]);
        $current = $row->fetch();
        if ($current && $current['role'] === 'admin' && $role === 'user' && countAdmins($authDb) <= 1) {
            $errors[] = t('users.err_last_admin_deg');
        } else {
            $authDb->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $userId]);
            $msg[] = t('users.ok_role');
        }
    }
}

// ── Suppression ───────────────────────────────────────────────────────────────
if ($action === 'delete_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    // Empêche de supprimer son propre compte ou le dernier admin
    $row = $authDb->prepare("SELECT role FROM users WHERE id = ?");
    $row->execute([$userId]);
    $target = $row->fetch();

    if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
        $errors[] = t('users.err_self_delete');
    } elseif ($target && $target['role'] === 'admin' && countAdmins($authDb) <= 1) {
        $errors[] = t('users.err_last_admin_del');
    } else {
        $stmt = $authDb->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if ($stmt->rowCount()) $msg[]    = t('users.ok_deleted');
        else                   $errors[] = t('users.err_not_found');
    }
}

// ── Chargement de la liste ────────────────────────────────────────────────────
$users = $authDb->query(
    "SELECT id, username, role, created_at, last_login FROM users ORDER BY role DESC, username COLLATE NOCASE"
)->fetchAll();

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detectLang()) ?>">
<head>
    <meta charset="UTF-8">
	<meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(t('users.title')) ?></title>
    <link rel="icon" type="image/png" href="./favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Badge de rôle coloré dans le tableau */
        .role-admin { background: #fff8e1; color: #e65100; font-weight: 700; }
        .role-user  { background: #e8f5e9; color: #2e7d32; }
        /* Colonne date : police réduite et sans retour à la ligne */
        .col-date { font-size: .8rem; color: #999; white-space: nowrap; }
        /* Indicateur "vous" sur la ligne du compte connecté */
        .badge-you { background: #e3f2fd; color: #1565c0; font-size: .72rem; padding: 1px 7px; border-radius: 10px; }
        /* Masque les colonnes secondaires sur mobile */
        @media (max-width: 600px) {
            th:nth-child(3), td:nth-child(3),
            th:nth-child(4), td:nth-child(4) { display: none; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <h2>👤 <?= htmlspecialchars(t('users.title')) ?></h2>
    <a href="index.php">📞 <?= htmlspecialchars(t('nav.journal')) ?></a>
    <a href="contacts.php">👥 <?= htmlspecialchars(t('nav.contacts')) ?></a>
    <a href="numeros_speciaux.php">⚙️ <?= htmlspecialchars(t('nav.speciaux')) ?></a>
    <?= topbarUserHtml() ?>
</div>

<?php foreach ($msg    as $m): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-err">⚠️ <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<!-- Ajout d'un utilisateur -->
<div class="card">
    <details>
        <summary>➕ <?= htmlspecialchars(t('users.add')) ?></summary><br>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_user">
            <div class="form-row">
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('users.username')) ?></label>
                    <input type="text" name="username"
                           placeholder="<?= htmlspecialchars(t('users.username_placeholder')) ?>"
                           style="width:160px" autocomplete="off" required>
                </div>
                <div>
                    <label class="lbl">
                        <?= htmlspecialchars(t('users.password')) ?>
                        <span style="color:#aaa;font-size:.75rem"><?= htmlspecialchars(t('users.password_hint')) ?></span>
                    </label>
                    <input type="password" name="password" style="width:200px"
                           autocomplete="new-password" required>
                </div>
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('users.role')) ?></label>
                    <select name="role">
                        <option value="user"><?= htmlspecialchars(t('users.role_user')) ?></option>
                        <option value="admin"><?= htmlspecialchars(t('users.role_admin')) ?></option>
                    </select>
                </div>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('users.create_btn')) ?></button>
            </div>
        </form>
    </details>
</div>

<!-- Liste des utilisateurs -->
<div class="card">
    <h3>📋 <?= htmlspecialchars(t('users.list', ['n' => count($users)])) ?></h3>
    <?php if (empty($users)): ?>
        <p style="color:#aaa;text-align:center;padding:20px 0"><?= htmlspecialchars(t('users.none')) ?></p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th><?= htmlspecialchars(t('users.col.username')) ?></th>
                <th><?= htmlspecialchars(t('users.col.role')) ?></th>
                <th><?= htmlspecialchars(t('users.col.created')) ?></th>
                <th><?= htmlspecialchars(t('users.col.last_login')) ?></th>
                <th><?= htmlspecialchars(t('users.col.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u):
            $isMe = ((int)$u['id'] === $currentUserId);
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($u['username']) ?></strong>
                <?php if ($isMe): ?>
                    <span class="badge-you"><?= htmlspecialchars(t('users.badge_you')) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge role-<?= $u['role'] ?>">
                    <?= htmlspecialchars($u['role'] === 'admin' ? t('users.role_badge_admin') : t('users.role_badge_user')) ?>
                </span>
            </td>
            <td class="col-date"><?= htmlspecialchars(substr($u['created_at'] ?? '—', 0, 16)) ?></td>
            <td class="col-date">
                <?= $u['last_login']
                    ? htmlspecialchars(substr($u['last_login'], 0, 16))
                    : '<span style="color:#ccc">' . htmlspecialchars(t('users.never')) . '</span>' ?>
            </td>
            <td style="white-space:nowrap">
                <!-- Bouton réinitialiser le mot de passe (data-* lus par le JS noncé) -->
                <button class="btn btn-orange btn-reset-pwd"
                        data-user-id="<?= (int)$u['id'] ?>"
                        data-username="<?= htmlspecialchars($u['username']) ?>">
                    🔑 <?= htmlspecialchars(t('users.btn_pwd')) ?>
                </button>
                <!-- Changement de rôle (désactivé pour soi-même) -->
                <form method="post" style="display:inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"  value="change_role">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <select name="role" class="select-auto-submit"
                            style="font-size:.8rem;padding:3px 6px;border-radius:5px;border:1px solid #ccc"
                            <?= $isMe ? 'disabled' : '' ?>>
                        <option value="user"  <?= $u['role'] === 'user'  ? 'selected' : '' ?>><?= htmlspecialchars(t('users.role_short_user')) ?></option>
                        <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>><?= htmlspecialchars(t('users.role_short_admin')) ?></option>
                    </select>
                </form>
                <!-- Suppression (désactivée pour son propre compte) -->
                <?php if (!$isMe): ?>
               <form method="post" class="form-confirm" style="display:inline"
                      data-confirm="<?= htmlspecialchars(t('users.delete_confirm', ['name' => $u['username']])) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"  value="delete_user">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <button type="submit" class="btn btn-red">🗑</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Modale : réinitialiser le mot de passe -->
<div class="modal-overlay" id="modal-reset-pwd">
    <div class="modal" style="width:360px">
        <h4>🔑 <?= htmlspecialchars(t('users.modal_pwd_title')) ?></h4>
        <p style="margin:0 0 12px;font-size:.9rem">
            <?= htmlspecialchars(t('users.modal_pwd_account')) ?> <strong id="reset-pwd-username"></strong>
        </p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action"  value="reset_password">
            <input type="hidden" name="user_id" id="reset-pwd-id">
            <label class="lbl">
                <?= htmlspecialchars(t('users.modal_pwd_label')) ?>
                <span style="color:#aaa;font-size:.75rem"><?= htmlspecialchars(t('users.password_hint')) ?></span>
            </label>
            <input type="password" name="password" id="reset-pwd-input"
                   style="width:100%" autocomplete="new-password" required>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray btn-close-modal" data-modal="modal-reset-pwd">
                    <?= htmlspecialchars(t('users.cancel')) ?>
                </button>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('users.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= htmlspecialchars(cspNonce()) ?>">
/** Ouvre la modale de réinitialisation de mot de passe pour l'utilisateur donné. */
function openResetPwd(id, username) {
    document.getElementById('reset-pwd-id').value              = id;
    document.getElementById('reset-pwd-username').textContent  = username;
    document.getElementById('reset-pwd-input').value           = '';
    document.getElementById('modal-reset-pwd').classList.add('open');
}
/** Ferme une modale par son id. */
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Boutons "Réinitialiser mot de passe" : récupération des valeurs via data-*
document.querySelectorAll('.btn-reset-pwd').forEach(btn => {
    btn.addEventListener('click', () => {
        openResetPwd(btn.dataset.userId, btn.dataset.username);
    });
});

// Sélecteurs à soumission automatique au changement (changement de rôle)
document.querySelectorAll('select.select-auto-submit').forEach(sel => {
    sel.addEventListener('change', () => sel.form.submit());
});

// Boutons de fermeture de modale (data-modal cible l'ID à fermer)
document.querySelectorAll('.btn-close-modal').forEach(btn => {
    btn.addEventListener('click', () => closeModal(btn.dataset.modal));
});

// Formulaires à confirmation (attribut data-confirm sur la <form>)
document.querySelectorAll('form.form-confirm').forEach(f => {
    f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
});

// Fermeture de la modale par clic sur l'overlay
document.getElementById('modal-reset-pwd').addEventListener('click', function(e) {
    if (e.target === this) closeModal('modal-reset-pwd');
});
</script>
</body>
</html>
