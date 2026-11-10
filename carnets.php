<?php
require 'config.php';
require 'auth.php';
requireAdmin();
sendSecurityHeaders();
csrfStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify();

$db = getDB();
require_once 'carddav_functions.php';

// ── Actions POST ──────────────────────────────────────────────────────────────
$msg       = [];
$errors    = [];
$resultats = [];
$action    = $_POST['action'] ?? '';
$choix     = null;

/** Ajoute un carnet et retourne son id, ou null si l'adresse et l'utilisateur existent déjà. */
function ajouterCarnet(PDO $db, string $nom, string $url, string $utilisateur, string $mdpChiffre): ?int {
    try {
        $db->prepare("INSERT INTO carnets (nom, url, utilisateur, mot_de_passe) VALUES (?,?,?,?)")
           ->execute([$nom, $url, $utilisateur, $mdpChiffre]);
        return (int)$db->lastInsertId();
    } catch (PDOException) {
        return null;
    }
}

/** Lance la synchronisation (un carnet ou tous les actifs) et range les résultats pour l'affichage. */
function lancerSynchro(PDO $db, ?int $id, array &$resultats, array &$errors): void {
    set_time_limit(300);
    $r = carddavSynchroniserTout($db, true, $id);
    if (!$r['verrou']) { $errors[] = t('carnets.err_verrou'); return; }
    $resultats = array_merge($resultats, $r['resultats']);
}

if (in_array($action, ['add_carnet', 'edit_carnet'], true)) {
    $url = trim($_POST['url'] ?? '');
    $utilisateur = trim($_POST['utilisateur'] ?? '');
    $mdp = (string)($_POST['mot_de_passe'] ?? '');
    $nom = trim($_POST['nom'] ?? '');
    if (!carddavUrlValide($url)) $errors[] = t('carnets.err_url');
}

if ($action === 'add_carnet' && !$errors) {
    try {
        $mdpChiffre = carddavChiffrer($mdp);
        set_time_limit(120);
        $d = carddavDecouvrir($url, $utilisateur, $mdp);
        if (!$d['ok']) {
            $errors[] = $d['msg'];
        } elseif ($d['direct'] || count($d['carnets']) === 1) {
            $c  = $d['carnets'][0];
            $id = ajouterCarnet($db, $nom !== '' ? $nom : $c['nom'], $c['url'], $utilisateur, $mdpChiffre);
            if ($id === null) {
                $errors[] = t('carnets.err_existe');
            } else {
                $msg[] = t('carnets.ok_ajoute', ['name' => $nom !== '' ? $nom : $c['nom']]);
                lancerSynchro($db, $id, $resultats, $errors);
            }
        } else {
            // Plusieurs carnets trouvés : l'usager choisit, le mot de passe attend chiffré en session
            $_SESSION['carddav_choix'] = ['utilisateur' => $utilisateur, 'mdp' => $mdpChiffre,
                                          'carnets' => $d['carnets']];
        }
    } catch (RuntimeException $e) {
        error_log('[carnets] ' . $e->getMessage());
        $errors[] = t('carnets.err_cle_creation');
    }
}

if ($action === 'add_choisis' && isset($_SESSION['carddav_choix'])) {
    $p = $_SESSION['carddav_choix'];
    unset($_SESSION['carddav_choix']);
    $ids = [];
    foreach ((array)($_POST['choix'] ?? []) as $i) {
        $c = $p['carnets'][(int)$i] ?? null;
        if ($c === null) continue;
        $id = ajouterCarnet($db, $c['nom'], $c['url'], $p['utilisateur'], $p['mdp']);
        if ($id === null) { $errors[] = t('carnets.err_existe_nom', ['name' => $c['nom']]); continue; }
        $msg[] = t('carnets.ok_ajoute', ['name' => $c['nom']]);
        $ids[] = $id;
    }
    foreach ($ids as $id) lancerSynchro($db, $id, $resultats, $errors);
}

if ($action === 'cancel_choix') unset($_SESSION['carddav_choix']);

if ($action === 'edit_carnet' && !$errors) {
    $id = (int)($_POST['carnet_id'] ?? 0);
    if ($nom === '') {
        $errors[] = t('carnets.err_nom');
    } else {
        try {
            $actif = isset($_POST['actif']) ? 1 : 0;
            if ($mdp !== '') {
                $db->prepare("UPDATE carnets SET nom=?, url=?, utilisateur=?, actif=?, mot_de_passe=?, ctag=NULL WHERE id=?")
                   ->execute([$nom, $url, $utilisateur, $actif, carddavChiffrer($mdp), $id]);
            } else {
                $db->prepare("UPDATE carnets SET nom=?, url=?, utilisateur=?, actif=?, ctag=NULL WHERE id=?")
                   ->execute([$nom, $url, $utilisateur, $actif, $id]);
            }
            $msg[] = t('carnets.ok_modifie', ['name' => $nom]);
        } catch (PDOException) {
            $errors[] = t('carnets.err_existe');
        } catch (RuntimeException $e) {
            error_log('[carnets] ' . $e->getMessage());
            $errors[] = t('carnets.err_cle_creation');
        }
    }
}

if ($action === 'delete_carnet') {
    $db->beginTransaction();
    $db->prepare("DELETE FROM carnets WHERE id = ?")->execute([(int)($_POST['carnet_id'] ?? 0)]);
    $n = carddavNettoyer($db);
    $db->commit();
    $msg[] = t('carnets.ok_supprime', ['numeros' => $n['numeros_retires'], 'contacts' => $n['contacts_retires']]);
}

if ($action === 'sync_carnet') lancerSynchro($db, (int)($_POST['carnet_id'] ?? 0), $resultats, $errors);
if ($action === 'sync_tous')   lancerSynchro($db, null, $resultats, $errors);

$choix = $_SESSION['carddav_choix'] ?? null;

// ── Chargement des carnets ────────────────────────────────────────────────────
$carnets = $db->query("
    SELECT c.*, (SELECT COUNT(*) FROM sources_numeros s WHERE s.carnet_id = c.id) AS nb_numeros
    FROM carnets c ORDER BY c.nom COLLATE NOCASE
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detectLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(t('carnets.title')) ?></title>
    <link rel="icon" type="image/png" href="./favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="theme.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Carnets, téléphone d'abord : champs pleine largeur, cibles de 44 px, colonne Compte masquée */
        .carnet-url  { font-size: .78rem; color: var(--text-date); word-break: break-all; }
        .carnet-etat { font-size: .8rem; }
        .carnet-etat.ko { color: var(--alert-err-txt); }
        .carnets-page .card .btn, .carnets-page .modal .btn { min-height: 44px; min-width: 44px; }
        .carnets-page .form-row { flex-direction: column; align-items: stretch; }
        .carnets-page .form-row input { width: 100% !important; min-height: 44px; box-sizing: border-box; }
        .carnets-actions { display: flex; gap: 6px; flex-wrap: wrap; }
        .choix-carnet { display: flex; gap: 10px; align-items: center; min-height: 44px; }
        .choix-carnet input { width: 22px; height: 22px; }
        .col-compte { display: none; }

        /* Tablette et ordinateur : formulaire en ligne, colonne Compte visible */
        @media (min-width: 601px) {
            .carnets-page .form-row { flex-direction: row; align-items: flex-end; }
            .carnets-page .form-row input { width: auto !important; }
            .col-compte { display: table-cell; }
        }
    </style>
</head>
<body class="carnets-page">

<div class="topbar">
    <h2>📇 <?= htmlspecialchars(t('carnets.title')) ?></h2>
    <a href="index.php">📞 <?= htmlspecialchars(t('nav.journal')) ?></a>
    <a href="contacts.php">👥 <?= htmlspecialchars(t('nav.contacts')) ?></a>
    <?= topbarUserHtml() ?>
    <button id="btn-theme" title="Changer de thème">🌙</button>
</div>

<?php foreach ($msg    as $m): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-err">⚠️ <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>
<?php foreach ($resultats as $r): ?>
    <div class="alert <?= $r['ok'] ? 'alert-ok' : 'alert-err' ?>">
        <?= $r['ok'] ? '🔄' : '⚠️' ?> <strong><?= htmlspecialchars($r['nom']) ?></strong> : <?= htmlspecialchars($r['msg']) ?>
    </div>
<?php endforeach; ?>

<?php if ($choix !== null): ?>
<!-- Choix parmi les carnets trouvés sur le serveur -->
<div class="card">
    <h3>🔎 <?= htmlspecialchars(t('carnets.choix_titre', ['n' => count($choix['carnets'])])) ?></h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_choisis">
        <?php foreach ($choix['carnets'] as $i => $c): ?>
            <label class="choix-carnet">
                <input type="checkbox" name="choix[]" value="<?= (int)$i ?>" checked>
                <span><strong><?= htmlspecialchars($c['nom']) ?></strong><br>
                <span class="carnet-url"><?= htmlspecialchars($c['url']) ?></span></span>
            </label>
        <?php endforeach; ?>
        <div class="carnets-actions" style="margin-top:10px">
            <button type="submit" class="btn btn-green"><?= htmlspecialchars(t('carnets.choix_btn')) ?></button>
        </div>
    </form>
    <form method="post" style="margin-top:8px">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cancel_choix">
        <button type="submit" class="btn btn-gray"><?= htmlspecialchars(t('users.cancel')) ?></button>
    </form>
</div>
<?php endif; ?>

<!-- Ajout d'un carnet -->
<div class="card">
    <details <?= empty($carnets) && $choix === null ? 'open' : '' ?>>
        <summary>➕ <?= htmlspecialchars(t('carnets.add')) ?></summary>
        <div class="hint"><?= htmlspecialchars(t('carnets.add_hint')) ?></div>
        <form method="post" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_carnet">
            <div class="form-row">
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('carnets.url_label')) ?></label>
                    <input type="url" name="url" required placeholder="https://nas.example.com/carddav/" style="width:320px">
                </div>
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('carnets.user_label')) ?></label>
                    <input type="text" name="utilisateur" style="width:160px">
                </div>
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('carnets.pass_label')) ?></label>
                    <input type="password" name="mot_de_passe" autocomplete="new-password" style="width:160px">
                </div>
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('carnets.nom_label')) ?></label>
                    <input type="text" name="nom" style="width:160px">
                </div>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('carnets.add_btn')) ?></button>
            </div>
        </form>
    </details>
</div>

<!-- Liste des carnets -->
<div class="card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <h3 style="margin:0">📇 <?= htmlspecialchars(t('carnets.list')) ?></h3>
        <?php if ($carnets): ?>
        <form method="post" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync_tous">
            <button type="submit" class="btn btn-green">🔄 <?= htmlspecialchars(t('carnets.sync_tous')) ?></button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!$carnets): ?>
        <p style="color:var(--text-none);text-align:center;padding:20px 0"><?= htmlspecialchars(t('carnets.none')) ?></p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th><?= htmlspecialchars(t('carnets.col.nom')) ?></th>
                <th class="col-compte"><?= htmlspecialchars(t('carnets.col.compte')) ?></th>
                <th><?= htmlspecialchars(t('carnets.col.etat')) ?></th>
                <th><?= htmlspecialchars(t('contacts.col.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($carnets as $c): ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($c['nom']) ?></strong>
                <?php if (!(int)$c['actif']): ?><span class="badge badge-cat-autre"><?= htmlspecialchars(t('carnets.inactif')) ?></span><?php endif; ?>
                <div class="carnet-url"><?= htmlspecialchars($c['url']) ?></div>
            </td>
            <td class="col-compte" style="font-size:.85rem"><?= htmlspecialchars($c['utilisateur']) ?></td>
            <td class="carnet-etat <?= $c['dernier_ok'] !== null && !(int)$c['dernier_ok'] ? 'ko' : '' ?>">
                <?php if ($c['derniere_synchro'] === null): ?>
                    <?= htmlspecialchars(t('carnets.jamais')) ?>
                <?php else: ?>
                    <?= htmlspecialchars($c['derniere_synchro']) ?><br>
                    <?= htmlspecialchars(t('carnets.compte_numeros', ['n' => $c['nb_numeros']])) ?><br>
                    <?= htmlspecialchars((string)$c['dernier_etat']) ?>
                <?php endif; ?>
            </td>
            <td>
                <div class="carnets-actions">
                    <form method="post" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="sync_carnet">
                        <input type="hidden" name="carnet_id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="btn btn-blue" title="<?= htmlspecialchars(t('carnets.sync')) ?>">🔄</button>
                    </form>
                    <button class="btn btn-orange btn-edit-carnet"
                            data-id="<?= (int)$c['id'] ?>"
                            data-nom="<?= htmlspecialchars($c['nom']) ?>"
                            data-url="<?= htmlspecialchars($c['url']) ?>"
                            data-utilisateur="<?= htmlspecialchars($c['utilisateur']) ?>"
                            data-actif="<?= (int)$c['actif'] ?>">✏️</button>
                    <form method="post" class="form-confirm" style="display:inline"
                          data-confirm="<?= htmlspecialchars(t('carnets.delete_confirm', ['name' => $c['nom'], 'n' => $c['nb_numeros']])) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete_carnet">
                        <input type="hidden" name="carnet_id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="btn btn-red">🗑</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <div class="hint" style="margin-top:12px"><?= htmlspecialchars(t('carnets.miroir_hint')) ?></div>
</div>

<!-- Modale : modifier un carnet -->
<div class="modal-overlay" id="modal-carnet">
    <div class="modal">
        <h4>✏️ <?= htmlspecialchars(t('carnets.modal_titre')) ?></h4>
        <form method="post" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_carnet">
            <input type="hidden" name="carnet_id" id="carnet-id">
            <label class="lbl"><?= htmlspecialchars(t('carnets.nom_label')) ?></label>
            <input type="text" name="nom" id="carnet-nom" style="width:100%" required>
            <label class="lbl"><?= htmlspecialchars(t('carnets.url_label')) ?></label>
            <input type="url" name="url" id="carnet-url" style="width:100%" required>
            <label class="lbl"><?= htmlspecialchars(t('carnets.user_label')) ?></label>
            <input type="text" name="utilisateur" id="carnet-utilisateur" style="width:100%">
            <label class="lbl"><?= htmlspecialchars(t('carnets.pass_garder')) ?></label>
            <input type="password" name="mot_de_passe" autocomplete="new-password" style="width:100%">
            <label class="choix-carnet">
                <input type="checkbox" name="actif" id="carnet-actif" value="1">
                <?= htmlspecialchars(t('carnets.actif_label')) ?>
            </label>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray btn-close-modal"><?= htmlspecialchars(t('users.cancel')) ?></button>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('users.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= htmlspecialchars(cspNonce()) ?>">
const modale = document.getElementById('modal-carnet');

// Boutons ✏️ : remplit et ouvre la modale de modification
document.querySelectorAll('.btn-edit-carnet').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('carnet-id').value          = btn.dataset.id;
        document.getElementById('carnet-nom').value         = btn.dataset.nom;
        document.getElementById('carnet-url').value         = btn.dataset.url;
        document.getElementById('carnet-utilisateur').value = btn.dataset.utilisateur;
        document.getElementById('carnet-actif').checked     = btn.dataset.actif === '1';
        modale.classList.add('open');
    });
});

// Fermeture de la modale : bouton Annuler ou clic sur le fond
modale.querySelector('.btn-close-modal').addEventListener('click', () => modale.classList.remove('open'));
modale.addEventListener('click', e => { if (e.target === modale) modale.classList.remove('open'); });

// Formulaires à confirmation (attribut data-confirm sur la <form>)
document.querySelectorAll('form.form-confirm').forEach(f => {
    f.addEventListener('submit', e => { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
});
</script>
</body>
</html>
