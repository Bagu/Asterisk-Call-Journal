<?php
/**
 * Gestion des comptes utilisateurs — CLI uniquement.
 * Ce script refuse toute exécution depuis un navigateur web.
 *
 * Usage :
 *   php manage_users.php list
 *   php manage_users.php add <username> <password> [admin|user]
 *   php manage_users.php reset-password <username> <new-password>
 *   php manage_users.php delete <username>
 *   php manage_users.php role <username> [admin|user]
 *
 * Récupération du mot de passe admin perdu (accès physique / SSH local requis) :
 *   php manage_users.php reset-password <username> <nouveau-mot-de-passe>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Accès interdit. Ce script est réservé à la ligne de commande.');
}

require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

$db   = getAuthDB();
$args = array_slice($argv, 1);
$cmd  = $args[0] ?? '';

/** Affiche un message sur stdout ou stderr selon $error. */
function out(string $msg, bool $error = false): void {
    fwrite($error ? STDERR : STDOUT, $msg . PHP_EOL);
}

/** Valide un identifiant : 2-64 caractères alphanumériques, tiret, underscore. */
function validUsername(string $u): bool {
    return (bool) preg_match('/^[a-zA-Z0-9_\-]{2,64}$/', $u);
}

/** Valide un mot de passe : 8 caractères minimum (compte les caractères Unicode, pas les octets). */
function validPassword(string $p): bool {
    return mb_strlen($p) >= 8;
}

switch ($cmd) {

    // ── Lister les utilisateurs ───────────────────────────────────────────────
    case 'list':
        $rows = $db->query(
            "SELECT id, username, role, created_at, last_login FROM users ORDER BY username"
        )->fetchAll();
        if (empty($rows)) { out('Aucun utilisateur configuré.'); break; }
        out(sprintf("%-4s %-24s %-8s %-20s %s", 'ID', 'Identifiant', 'Rôle', 'Créé le', 'Dernière connexion'));
        out(str_repeat('─', 80));
        foreach ($rows as $r) {
            out(sprintf("%-4d %-24s %-8s %-20s %s",
                $r['id'], $r['username'], $r['role'],
                $r['created_at'] ?? '—',
                $r['last_login']  ?? 'jamais'));
        }
        break;

    // ── Ajouter un utilisateur ────────────────────────────────────────────────
    case 'add':
        $username = $args[1] ?? '';
        $password = $args[2] ?? '';
        $role     = in_array($args[3] ?? '', ['admin', 'user'], true) ? $args[3] : 'user';
        if (!validUsername($username)) {
            out("Identifiant invalide. Utilisez 2-64 caractères : a-z A-Z 0-9 _ -", true); exit(1);
        }
        if (!validPassword($password)) {
            out("Mot de passe trop court (8 caractères minimum).", true); exit(1);
        }
        try {
            $db->prepare("INSERT INTO users (username, password_hash, role) VALUES (?,?,?)")
               ->execute([$username, hashPassword($password), $role]);
            out("✓ Utilisateur « $username » créé avec le rôle « $role ».");
        } catch (PDOException $e) {
            out("Erreur : " . $e->getMessage(), true); exit(1);
        }
        break;

    // ── Réinitialiser le mot de passe (récupération admin) ───────────────────
    case 'reset-password':
        $username = $args[1] ?? '';
        $password = $args[2] ?? '';
        if (!validPassword($password)) {
            out("Mot de passe trop court (8 caractères minimum).", true); exit(1);
        }
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
        $stmt->execute([hashPassword($password), $username]);
        if ($stmt->rowCount() === 0) {
            out("Utilisateur introuvable : « $username »", true); exit(1);
        }
        out("✓ Mot de passe réinitialisé pour « $username ».");
        break;

    // ── Supprimer un utilisateur ──────────────────────────────────────────────
    case 'delete':
        $username = $args[1] ?? '';
        // Empêche la suppression du dernier admin
        $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        $targetRole = $db->prepare("SELECT role FROM users WHERE username = ?");
        $targetRole->execute([$username]);
        $row = $targetRole->fetch();
        if ($row && $row['role'] === 'admin' && $adminCount <= 1) {
            out("Impossible de supprimer le dernier administrateur.", true); exit(1);
        }
        $stmt = $db->prepare("DELETE FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() === 0) {
            out("Utilisateur introuvable : « $username »", true); exit(1);
        }
        out("✓ Utilisateur « $username » supprimé.");
        break;

    // ── Changer le rôle d'un utilisateur ─────────────────────────────────────
    case 'role':
        $username = $args[1] ?? '';
        $role     = in_array($args[2] ?? '', ['admin', 'user'], true) ? $args[2] : '';
        if (!$role) {
            out("Rôle invalide. Valeurs acceptées : admin, user.", true); exit(1);
        }
        // Empêche de rétrograder le dernier admin
        if ($role === 'user') {
            $adminCount = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
            $targetRole = $db->prepare("SELECT role FROM users WHERE username = ?");
            $targetRole->execute([$username]);
            $row = $targetRole->fetch();
            if ($row && $row['role'] === 'admin' && $adminCount <= 1) {
                out("Impossible de rétrograder le dernier administrateur.", true); exit(1);
            }
        }
        $stmt = $db->prepare("UPDATE users SET role = ? WHERE username = ?");
        $stmt->execute([$role, $username]);
        if ($stmt->rowCount() === 0) {
            out("Utilisateur introuvable : « $username »", true); exit(1);
        }
        out("✓ Rôle de « $username » défini à « $role ».");
        break;

    // ── Aide ──────────────────────────────────────────────────────────────────
    default:
        out("Gestion des utilisateurs — Journal des appels");
        out(str_repeat('─', 48));
        out("  php manage_users.php list");
        out("  php manage_users.php add <username> <password> [admin|user]");
        out("  php manage_users.php reset-password <username> <new-password>");
        out("  php manage_users.php delete <username>");
        out("  php manage_users.php role <username> [admin|user]");
        out("");
        out("Récupération mot de passe admin perdu (local uniquement) :");
        out("  php manage_users.php reset-password <username> <nouveau-mdp>");
        exit(1);
}
exit(0);