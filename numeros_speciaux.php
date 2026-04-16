<?php
require 'config.php';
require 'auth.php';
requireAdmin();
sendSecurityHeaders();
csrfStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify();

$db         = getDB();
$msg        = [];
$errors     = [];
$categories = ['system', 'local', 'blacklist', 'autre'];

if (($_POST['action'] ?? '') === 'add') {
    $num = trim($_POST['numero'] ?? '');
    $lbl = trim($_POST['label']  ?? '');
    $cat = in_array(trim($_POST['categorie'] ?? ''), $categories, true) ? trim($_POST['categorie']) : 'system';
    if ($num === '' || $lbl === '' || !preg_match('/^[0-9s*#]+$/', $num)) {
        $errors[] = t('speciaux.err_invalid');
    } else {
        $db->prepare("INSERT OR REPLACE INTO numeros_speciaux (numero, label, categorie) VALUES (?,?,?)")
           ->execute([$num, $lbl, $cat]);
        $msg[] = t('speciaux.ok_saved', ['num' => $num]);
    }
}

if (($_POST['action'] ?? '') === 'delete') {
    $num = trim($_POST['numero'] ?? '');
    if (!preg_match('/^[0-9s*#]+$/', $num)) {
        $errors[] = t('speciaux.err_num_invalid');
    } else {
        $db->prepare("DELETE FROM numeros_speciaux WHERE numero = ?")->execute([$num]);
        $msg[] = t('speciaux.ok_deleted', ['num' => $num]);
    }
}

if (($_POST['action'] ?? '') === 'edit') {
    $num = trim($_POST['numero'] ?? '');
    $lbl = trim($_POST['label']  ?? '');
    $cat = in_array(trim($_POST['categorie'] ?? ''), $categories, true) ? trim($_POST['categorie']) : 'system';
    if (!preg_match('/^[0-9s*#]+$/', $num)) {
        $errors[] = t('speciaux.err_num_invalid');
    } elseif ($lbl === '') {
        $errors[] = t('speciaux.err_lib_vide');
    } else {
        $db->prepare("UPDATE numeros_speciaux SET label = ?, categorie = ? WHERE numero = ?")
           ->execute([$lbl, $cat, $num]);
        $msg[] = t('speciaux.ok_updated', ['num' => $num]);
    }
}

$grouped = [];
foreach ($db->query("SELECT * FROM numeros_speciaux ORDER BY categorie, numero")->fetchAll() as $s) {
    $grouped[$s['categorie']][] = $s;
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detectLang()) ?>">
<head>
    <meta charset="UTF-8">
	<meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(t('speciaux.title')) ?></title>
    <link rel="icon" type="image/png" href="./favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Modale d'édition : largeur fixe adaptée au formulaire compact */
        .modal  { width: 360px; }
        /* En-tête de groupe dans le tableau (séparation par catégorie) */
        .cat-header { background: #ecf0f1; color: #555; font-weight: 700; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; }
        /* Badge de catégorie (system, local, blacklist, autre) dans le tableau */
        .badge-cat  { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: .75rem; font-weight: 600; }
    </style>
</head>
<body>

<div class="topbar">
    <h2>⚙️ <?= htmlspecialchars(t('speciaux.title')) ?></h2>
    <a href="index.php">📞 <?= htmlspecialchars(t('nav.journal')) ?></a>
    <a href="contacts.php">👥 <?= htmlspecialchars(t('nav.contacts')) ?></a>
    <?= topbarUserHtml() ?>
</div>

<?php foreach ($msg    as $m): ?><div class="alert alert-ok">✅ <?= htmlspecialchars($m) ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-err">⚠️ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card">
    <h3>➕ <?= htmlspecialchars(t('speciaux.add')) ?></h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="form-row">
            <div>
                <label class="lbl"><?= htmlspecialchars(t('speciaux.num_label')) ?></label>
                <input type="text" name="numero"
                       placeholder="<?= htmlspecialchars(t('speciaux.num_placeholder')) ?>"
                       style="width:110px" required>
            </div>
            <div>
                <label class="lbl"><?= htmlspecialchars(t('speciaux.lib_label')) ?></label>
                <input type="text" name="label"
                       placeholder="<?= htmlspecialchars(t('speciaux.lib_placeholder')) ?>"
                       style="width:200px" required>
            </div>
            <div>
                <label class="lbl"><?= htmlspecialchars(t('speciaux.cat_label')) ?></label>
                <select name="categorie">
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat ?>"><?= htmlspecialchars(t('speciaux.cat.' . $cat)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('speciaux.add_btn')) ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h3>📋 <?= htmlspecialchars(t('speciaux.list')) ?></h3>
    <table>
        <thead>
            <tr>
                <th><?= htmlspecialchars(t('speciaux.col.numero')) ?></th>
                <th><?= htmlspecialchars(t('speciaux.col.label')) ?></th>
                <th><?= htmlspecialchars(t('speciaux.col.cat')) ?></th>
                <th><?= htmlspecialchars(t('speciaux.col.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($categories as $cat):
            if (empty($grouped[$cat])) continue;
        ?>
            <tr class="cat-header"><td colspan="4"><?= htmlspecialchars(t('speciaux.cat.' . $cat)) ?> (<?= count($grouped[$cat]) ?>)</td></tr>
            <?php foreach ($grouped[$cat] as $s): ?>
            <tr>
                <td><code><?= htmlspecialchars($s['numero']) ?></code></td>
                <td><?= htmlspecialchars($s['label']) ?></td>
                <td><span class="badge-cat badge-cat-<?= htmlspecialchars($s['categorie']) ?>"><?= htmlspecialchars($s['categorie']) ?></span></td>
                <td>
                    <button class="btn btn-orange"
                            onclick="openEdit(<?= json_encode($s['numero'], JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($s['label'], JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, <?= json_encode($s['categorie'], JSON_HEX_TAG|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)">
                        ✏️ <?= htmlspecialchars(t('speciaux.btn_edit')) ?>
                    </button>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm(<?= json_encode(t('speciaux.delete_confirm', ['num' => $s['numero']]), JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)">
                        <?= csrfField() ?>
                        <input type="hidden" name="action"  value="delete">
                        <input type="hidden" name="numero"  value="<?= htmlspecialchars($s['numero']) ?>">
                        <button type="submit" class="btn btn-red">🗑 <?= htmlspecialchars(t('speciaux.btn_delete')) ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modale édition -->
<div class="modal-overlay" id="modal-edit">
    <div class="modal">
        <h4>✏️ <?= htmlspecialchars(t('speciaux.modal_title')) ?></h4>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="numero" id="edit-num">
            <p style="margin:0 0 12px;font-size:.9rem">
                <?= htmlspecialchars(t('speciaux.modal_ext')) ?> <strong id="edit-num-display"></strong>
            </p>
            <label class="lbl"><?= htmlspecialchars(t('speciaux.modal_label')) ?></label>
            <input type="text" name="label" id="edit-label" style="width:100%" required>
            <label class="lbl" style="margin-top:10px"><?= htmlspecialchars(t('speciaux.modal_cat')) ?></label>
            <select name="categorie" id="edit-cat" style="width:100%">
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat ?>"><?= htmlspecialchars(t('speciaux.cat.' . $cat)) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray"
                        onclick="document.getElementById('modal-edit').classList.remove('open')">
                    <?= htmlspecialchars(t('speciaux.cancel')) ?>
                </button>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('speciaux.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(num, label, cat) {
    document.getElementById('edit-num').value               = num;
    document.getElementById('edit-num-display').textContent = num;
    document.getElementById('edit-label').value             = label;
    [...document.getElementById('edit-cat').options].forEach(o => o.selected = o.value === cat);
    document.getElementById('modal-edit').classList.add('open');
}
document.getElementById('modal-edit').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>
</body>
</html>
