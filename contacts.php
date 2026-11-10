<?php
require 'config.php';
require 'auth.php';
requireAdmin();
sendSecurityHeaders();
csrfStart();

if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify();

$db = getDB();
require_once 'contacts_functions.php';

// ── Actions POST ──────────────────────────────────────────────────────────────
$msg         = [];
$errors      = [];
$importStats = null;
$action      = $_POST['action'] ?? '';
$types       = ['inconnu', 'portable', 'domicile', 'travail', 'autre'];

if ($action === 'delete_contact') {
    $db->prepare("DELETE FROM contacts WHERE id = ?")->execute([(int)$_POST['contact_id']]);
    $msg[] = t('contacts.ok_deleted');
}

if ($action === 'edit_contact') {
    $id  = (int)$_POST['contact_id'];
    $nom = trim($_POST['nom'] ?? '');
    if ($nom === '') {
        $errors[] = t('contacts.err_nom_vide');
    } else {
        try {
            $db->prepare("UPDATE contacts SET nom = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$nom, $id]);
            $msg[] = t('contacts.ok_renamed', ['name' => $nom]);
        } catch (PDOException) {
            $errors[] = t('contacts.err_nom_exist');
        }
    }
}

if ($action === 'add_contact') {
    $nom  = trim($_POST['nom'] ?? '');
    $num  = normaliserNumero($_POST['numero'] ?? '');
    $type = trim($_POST['type'] ?? 'inconnu') ?: 'inconnu';
    if (!in_array($type, $types, true)) $type = 'inconnu';
    if ($nom === '') {
        $errors[] = t('contacts.err_nom_oblig');
    } else {
        try {
            $contactId = trouverOuCreerContact($db, $nom)['id'];
            if ($num !== '') lierNumero($db, $contactId, $num, $type);
            $msg[] = t('contacts.ok_saved', ['name' => $nom]);
        } catch (PDOException $e) {
            error_log('[contacts] add_contact : ' . $e->getMessage());
            $errors[] = t('contacts.err_nom_exist');
        }
    }
}

if ($action === 'add_numero') {
    $contactId = (int)$_POST['contact_id'];
    $num       = normaliserNumero($_POST['numero'] ?? '');
    $type      = trim($_POST['type'] ?? 'inconnu') ?: 'inconnu';
    if (!in_array($type, $types, true)) $type = 'inconnu';
    if ($num === '') {
        $errors[] = t('contacts.err_num_invalid');
    } else {
        $r     = lierNumero($db, $contactId, $num, $type);
        $msg[] = $r === 'exists' ? t('contacts.ok_num_exists') : t('contacts.ok_num_added');
    }
}

if ($action === 'edit_numero_type') {
    $type = trim($_POST['type'] ?? 'inconnu') ?: 'inconnu';
    if (!in_array($type, $types, true)) $type = 'inconnu';
    $db->prepare("UPDATE numeros SET type = ? WHERE id = ?")
       ->execute([$type, (int)$_POST['numero_id']]);
    $msg[] = t('contacts.ok_type_updated');
}

if ($action === 'delete_numero') {
    $db->prepare("DELETE FROM numeros WHERE id = ?")->execute([(int)$_POST['numero_id']]);
    // Suppression silencieuse : le rechargement de page confirme visuellement
}

if ($action === 'purge_sans_numeros') {
    // Supprime uniquement les contacts qui n'ont aucun numéro associé
    $stmt = $db->prepare("DELETE FROM contacts WHERE id NOT IN (SELECT DISTINCT contact_id FROM numeros)");
    $stmt->execute();
    $msg[] = t('contacts.ok_purged', ['n' => $stmt->rowCount()]);
}

// ── Import CSV ─────────────────────────────────────────────────────────────────
if ($action === 'import_csv' && isset($_FILES['csvfile'])) {
    $file = $_FILES['csvfile'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = t('contacts.err_upload', ['code' => $file['error']]);
    } elseif ($file['size'] > 4 * 1024 * 1024) {
        $errors[] = t('contacts.err_size');
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'text/comma-separated-values'], true)
            || !in_array($ext, ['csv', 'txt'], true)) {
            $errors[] = t('contacts.err_mime');
        }
    }

    if (empty($errors) && isset($file)) {
        $content = file_get_contents($file['tmp_name']);
        if (mb_detect_encoding($content, 'UTF-8', true) === false) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $handle  = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $firstLine = fgets($handle);
        rewind($handle);
        $sep = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';

        $headers  = array_map('trim', str_getcsv(fgets($handle), $sep, '"', ""));
        $idxNom   = array_search('Nom à afficher', $headers);
        $idxMob   = array_search('Portable',        $headers);
        $idxPerso = array_search('Tél. personnel',  $headers);
        $idxPro   = array_search('Tél. professionnel', $headers);

        $stats = [
            'contacts_crees'   => 0, 'numeros_lies'    => 0,
            'types_mis_a_jour' => 0, 'deja_presents'   => 0,
            'lignes_ignorees'  => 0, 'avertissements'  => [],
        ];

        $db->beginTransaction();
        try {
            while (($row = fgetcsv($handle, 0, $sep, '"', "")) !== false) {
                $nom = $idxNom !== false ? trim($row[$idxNom] ?? '') : '';
                if ($nom === '') { $stats['lignes_ignorees']++; continue; }

                // Collecter les numéros valides AVANT de créer le contact
                // pour ne pas importer de contacts fantômes sans numéro
                $numerosValides = [];
                foreach ([
                    [$idxMob,   'portable'],
                    [$idxPerso, 'domicile'],
                    [$idxPro,   'travail'],
                ] as [$idx, $type]) {
                    $numNorm = $idx !== false ? normaliserNumero($row[(int)$idx] ?? '') : '';
                    if ($numNorm !== '') $numerosValides[] = [$numNorm, $type];
                }
                if (empty($numerosValides)) { $stats['lignes_ignorees']++; continue; }

                $contact   = trouverOuCreerContact($db, $nom);
                $contactId = $contact['id'];
                if ($contact['created']) $stats['contacts_crees']++;

                $anyNew = false;
                foreach ($numerosValides as [$numNorm, $type]) {
                    $res = lierNumero($db, $contactId, $numNorm, $type);
                    if ($res === 'created')     { $stats['numeros_lies']++;     $anyNew = true; }
                    elseif ($res === 'updated') { $stats['types_mis_a_jour']++; $anyNew = true; }
                }
                if (!$anyNew && !$contact['created']) $stats['deja_presents']++;
            }
            $db->commit();
            $importStats = $stats;
        } catch (Exception $e) {
            $db->rollBack();
            error_log('[contacts] import_csv : ' . $e->getMessage());
            $errors[] = t('contacts.err_import');
        }
        fclose($handle);
    }
}

// ── Chargement des contacts ───────────────────────────────────────────────────
$search       = trim($_GET['q'] ?? '');
$searchParams = [];
$whereSQL     = '';
if ($search !== '') {
    $likeName   = '%' . $search . '%';
    $numNorm    = normaliserNumero($search);
    $likeNum    = $numNorm !== '' ? '%' . $numNorm . '%' : $likeName;
    $whereSQL   = "WHERE c.nom LIKE ? OR c.id IN (SELECT contact_id FROM numeros WHERE numero LIKE ?)";
    $searchParams = [$likeName, $likeNum];
}

// Pagination : 50 contacts par page, bornée à la dernière page disponible
$parPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

// Compte total des contacts correspondant au filtre (requête légère, sans JOIN)
$stmtCount = $db->prepare("SELECT COUNT(DISTINCT c.id) FROM contacts c $whereSQL");
$stmtCount->execute($searchParams);
$totalContacts = (int)$stmtCount->fetchColumn();
$totalPages    = max(1, (int)ceil($totalContacts / $parPage));
$page          = min($page, $totalPages);
$offset        = ($page - 1) * $parPage;

// Compte global des numéros (indépendant du filtre et de la page)
$totalNumeros = (int)$db->query("SELECT COUNT(*) FROM numeros")->fetchColumn();
// Nombre de contacts sans aucun numéro (pour le bouton de purge)
$nbSansNumero = (int)$db->query(
    "SELECT COUNT(*) FROM contacts WHERE id NOT IN (SELECT DISTINCT contact_id FROM numeros)"
)->fetchColumn();

// Requête principale paginée : LIMIT/OFFSET en paramètres positionnels (pas de mélange ?/: avec PDO)
$stmtC = $db->prepare("
    SELECT c.id, c.nom, c.created_at,
           GROUP_CONCAT(n.id || '|' || n.numero || '|' || n.type || '|' || REPLACE(IFNULL(
               (SELECT GROUP_CONCAT(k.nom, ', ') FROM sources_numeros s
                JOIN carnets k ON k.id = s.carnet_id WHERE s.numero_id = n.id), ''), ';;', ';'), ';;') AS numeros_raw
    FROM contacts c
    LEFT JOIN numeros n ON n.contact_id = c.id
    $whereSQL
    GROUP BY c.id
    ORDER BY c.nom COLLATE NOCASE
    LIMIT ? OFFSET ?
");
foreach ($searchParams as $k => $v) { $stmtC->bindValue($k + 1, $v); }
$stmtC->bindValue(count($searchParams) + 1, $parPage, PDO::PARAM_INT);
$stmtC->bindValue(count($searchParams) + 2, $offset,  PDO::PARAM_INT);
$stmtC->execute();
$contacts = $stmtC->fetchAll();

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detectLang()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(t('contacts.title')) ?></title>
    <link rel="icon" type="image/png" href="./favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- theme.js avant le CSS pour éviter le flash de thème incorrect (FOUC) -->
    <script src="theme.js"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Mobile < 600px : masque la colonne "Ajouté le" et empile le formulaire */
        @media (max-width: 600px) {
            th:nth-child(3), td:nth-child(3) { display: none; }
            .form-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <h2>👥 <?= htmlspecialchars(t('contacts.title')) ?></h2>
    <a href="index.php">📞 <?= htmlspecialchars(t('nav.journal')) ?></a>
    <a href="numeros_speciaux.php">⚙️ <?= htmlspecialchars(t('nav.speciaux')) ?></a>
    <a href="carnets.php">📇 <?= htmlspecialchars(t('nav.carnets')) ?></a>
    <?= topbarUserHtml() ?>
    <!-- Bouton bascule thème clair/sombre -->
    <button id="btn-theme" title="Changer de thème">🌙</button>
</div>

<?php foreach ($msg    as $m): ?>
    <div class="alert alert-ok">✅ <?= htmlspecialchars($m) ?></div>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
    <div class="alert alert-err">⚠️ <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if ($importStats !== null): ?>
<div class="alert alert-ok">
    ✅ <?= htmlspecialchars(t('contacts.import_done')) ?>
    <div class="import-stats">
        <?php foreach ([
            'contacts_crees'   => 'contacts.import_created',
            'numeros_lies'     => 'contacts.import_added',
            'types_mis_a_jour' => 'contacts.import_updated',
            'deja_presents'    => 'contacts.import_existing',
            'lignes_ignorees'  => 'contacts.import_ignored',
        ] as $key => $langKey): ?>
            <div class="stat-box">
                <div class="num"><?= htmlspecialchars((string)$importStats[$key]) ?></div>
                <div class="lbl"><?= htmlspecialchars(t($langKey)) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php foreach ($importStats['avertissements'] as $w): ?>
        <div class="alert-warn">⚠️ <?= htmlspecialchars($w) ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Import CSV -->
<div class="card">
    <details>
        <summary>📂 <?= htmlspecialchars(t('contacts.import')) ?></summary>
        <div class="hint">
            <strong><?= htmlspecialchars(t('contacts.import_format')) ?></strong>
            colonnes <code>Nom à afficher</code>, <code>Portable</code>, <code>Tél. personnel</code>, <code>Tél. professionnel</code><br>
            • Séparateur : <code>;</code> ou <code>,</code> (auto-détecté) · Encodage Windows-1252 ou UTF-8<br>
            • Les numéros sont normalisés (9 derniers chiffres, +33→0)<br>
            • Doublons : numéro existant mis à jour si le type change.
        </div>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="import_csv">
            <div class="form-row">
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('contacts.csv_file_label')) ?></label>
                    <input type="file" name="csvfile" accept=".csv,.txt" required>
                </div>
                <button type="submit" class="btn btn-green">⬆️ <?= htmlspecialchars(t('contacts.import_btn')) ?></button>
            </div>
        </form>
    </details>
</div>

<!-- Ajout manuel -->
<div class="card">
    <details>
        <summary>➕ <?= htmlspecialchars(t('contacts.add')) ?></summary><br>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_contact">
            <div class="form-row">
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('contacts.nom_label')) ?></label>
                    <input type="text" name="nom" placeholder="Dupont Jean" required style="width:180px">
                </div>
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('contacts.num_label')) ?></label>
                    <input type="text" name="numero" placeholder="0612345678" style="width:150px">
                </div>
                <div>
                    <label class="lbl"><?= htmlspecialchars(t('contacts.type_label')) ?></label>
                    <select name="type">
                        <?php foreach ($types as $typ): ?>
                            <option value="<?= $typ ?>"><?= htmlspecialchars(t('contacts.type.' . $typ)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('contacts.add_btn')) ?></button>
            </div>
        </form>
    </details>
</div>

<!-- Liste des contacts -->
<div class="card">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap">
        <h3 style="margin:0">📋 <?= htmlspecialchars(t('contacts.list')) ?></h3>
        <?php if ($nbSansNumero > 0): ?>
        <form method="post" class="form-confirm" style="display:inline"
              data-confirm="<?= htmlspecialchars(t('contacts.purge_confirm', ['n' => $nbSansNumero])) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="purge_sans_numeros">
            <button type="submit" class="btn btn-orange" style="font-size:.78rem;padding:4px 9px">
                🗑 <?= htmlspecialchars(t('contacts.btn_purge', ['n' => $nbSansNumero])) ?>
            </button>
        </form>
        <?php endif; ?>
    </div>

    <form method="get" class="filtres">
        <div>
            <label class="lbl"><?= htmlspecialchars(t('contacts.search')) ?></label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="<?= htmlspecialchars(t('contacts.search_placeholder')) ?>">
        </div>
        <button type="submit" class="btn btn-blue">🔍 <?= htmlspecialchars(t('contacts.search_btn')) ?></button>
        <?php if ($search !== ''): ?>
            <a href="contacts.php" class="btn btn-gray" style="text-decoration:none">✕</a>
        <?php endif; ?>
    </form>

    <div class="info-total">
        <?= htmlspecialchars(t('contacts.stat_contacts', ['n' => $totalContacts])) ?>
        · <?= htmlspecialchars(t('contacts.stat_numeros', ['n' => $totalNumeros])) ?>
        <?= $search !== ''
            ? ' — ' . htmlspecialchars(t('contacts.stat_filtered', ['q' => $search]))
            : '' ?>
    </div>

    <?php if ($totalPages > 1):
        // Construit les paramètres GET en conservant la recherche active
        $qParams = $search !== '' ? ['q' => $search] : [];
        /** Retourne l'URL de la page $p en conservant les filtres actifs. */
        $pageUrl = fn(int $p) => '?' . http_build_query($qParams + ['page' => $p]);
        // Fenêtre glissante : 2 pages autour de la page courante + première/dernière
        $win = 2;
    ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="<?= htmlspecialchars($pageUrl($page - 1)) ?>" class="btn btn-gray">‹</a>
        <?php endif; ?>

        <?php
        $shown = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || abs($i - $page) <= $win) {
                $shown[] = $i;
            }
        }
        $prev = null;
        foreach ($shown as $i):
            if ($prev !== null && $i - $prev > 1): ?>
                <span class="pagination-ellipsis">…</span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($pageUrl($i)) ?>"
               class="btn <?= $i === $page ? 'btn-blue' : 'btn-gray' ?>"><?= $i ?></a>
        <?php $prev = $i; endforeach; ?>

        <?php if ($page < $totalPages): ?>
            <a href="<?= htmlspecialchars($pageUrl($page + 1)) ?>" class="btn btn-gray">›</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($contacts)): ?>
        <p style="color:var(--text-none);text-align:center;padding:20px 0"><?= htmlspecialchars(t('contacts.none')) ?></p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th><?= htmlspecialchars(t('contacts.col.nom')) ?></th>
                <th><?= htmlspecialchars(t('contacts.col.numeros')) ?></th>
                <th><?= htmlspecialchars(t('contacts.col.created')) ?></th>
                <th><?= htmlspecialchars(t('contacts.col.actions')) ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($contacts as $contact):
            $numeros = [];
            if ($contact['numeros_raw']) {
                foreach (explode(';;', $contact['numeros_raw']) as $raw) {
                    // Défaut '' pour éviter Undefined array key si GROUP_CONCAT tronque
                    $parts = array_pad(explode('|', $raw, 4), 4, '');
                    [$nid, $nnum, $ntype, $nsrc] = $parts;
                    if ($nid === '' || $nnum === '') continue;
                    $numeros[] = ['id' => $nid, 'numero' => $nnum, 'type' => $ntype ?: 'inconnu', 'sources' => $nsrc];
                }
            }
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($contact['nom']) ?></strong></td>
            <td>
                <?php foreach ($numeros as $n): ?>
                    <div class="num-row">
                        <!-- Indicateur coloré du type + sélecteur de changement de type -->
                        <span class="dot-type dot-<?= htmlspecialchars($n['type']) ?>"></span>
                        <form method="post" style="display:inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"    value="edit_numero_type">
                            <input type="hidden" name="numero_id" value="<?= (int)$n['id'] ?>">
                            <select name="type" class="select-auto-submit"
                                    style="font-size:.73rem;padding:1px 4px;border-radius:10px;border:1px solid var(--border-color)">
                                <?php foreach ($types as $typ): ?>
                                    <option value="<?= $typ ?>" <?= $n['type'] === $typ ? 'selected' : '' ?>><?= htmlspecialchars(t('contacts.type.' . $typ)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php $numFmt = afficherNumero($n['numero']); ?>
                        <a href="tel:<?= htmlspecialchars($numFmt['tel']) ?>" class="num-lien"><?= $numFmt['html'] ?></a>
                        <?php if ($n['sources'] !== ''): ?>
                            <span title="<?= htmlspecialchars(t('contacts.source_carnet', ['name' => $n['sources']])) ?>">📇</span>
                        <?php endif; ?>
                        <form method="post" class="form-confirm" style="display:inline"
                              data-confirm="<?= htmlspecialchars(t('contacts.del_num_confirm')) ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action"    value="delete_numero">
                            <input type="hidden" name="numero_id" value="<?= (int)$n['id'] ?>">
                            <button type="submit" class="btn btn-red" style="padding:1px 6px;font-size:.7rem">✕</button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($numeros)): ?>
                    <span style="color:var(--text-none);font-size:.82rem"><?= htmlspecialchars(t('contacts.no_numero')) ?></span>
                <?php endif; ?>
            </td>
            <td style="font-size:.8rem;color:var(--text-date);white-space:nowrap">
                <?= htmlspecialchars(substr($contact['created_at'] ?? '', 0, 10)) ?>
            </td>
            <td style="white-space:nowrap">
                <button class="btn btn-orange btn-edit-contact"
                        data-contact-id="<?= (int)$contact['id'] ?>"
                        data-contact-nom="<?= htmlspecialchars($contact['nom']) ?>">✏️</button>
                <button class="btn btn-blue btn-add-num" style="font-size:.78rem;padding:4px 9px"
                        data-contact-id="<?= (int)$contact['id'] ?>"
                        data-contact-nom="<?= htmlspecialchars($contact['nom']) ?>">+ <?= htmlspecialchars(t('contacts.col.numeros')) ?></button>
                <form method="post" class="form-confirm" style="display:inline"
                      data-confirm="<?= htmlspecialchars(t('contacts.delete_confirm', ['name' => $contact['nom']])) ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action"     value="delete_contact">
                    <input type="hidden" name="contact_id" value="<?= (int)$contact['id'] ?>">
                    <button type="submit" class="btn btn-red">🗑</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Modale : renommer un contact -->
<div class="modal-overlay" id="modal-edit">
    <div class="modal">
        <h4>✏️ <?= htmlspecialchars(t('contacts.modal_edit_title')) ?></h4>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action"     value="edit_contact">
            <input type="hidden" name="contact_id" id="edit-contact-id">
            <label class="lbl"><?= htmlspecialchars(t('contacts.modal_edit_label')) ?></label>
            <input type="text" name="nom" id="edit-nom" style="width:100%" required>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray btn-close-modal" data-modal="modal-edit">
                    <?= htmlspecialchars(t('users.cancel')) ?>
                </button>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('users.save')) ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modale : ajouter un numéro -->
<div class="modal-overlay" id="modal-addnum">
    <div class="modal">
        <h4>➕ <?= htmlspecialchars(t('contacts.modal_num_title')) ?></h4>
        <p style="margin:0 0 12px;font-size:.9rem">
            <?= htmlspecialchars(t('contacts.modal_num_contact')) ?> <strong id="addnum-nom-display"></strong>
        </p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action"     value="add_numero">
            <input type="hidden" name="contact_id" id="addnum-contact-id">
            <label class="lbl"><?= htmlspecialchars(t('contacts.num_label')) ?></label>
            <input type="text" name="numero" id="addnum-numero" placeholder="0612345678" style="width:100%" required>
            <label class="lbl"><?= htmlspecialchars(t('contacts.type_label')) ?></label>
            <select name="type" style="width:100%">
                <?php foreach ($types as $typ): ?>
                    <option value="<?= $typ ?>"><?= htmlspecialchars(t('contacts.type.' . $typ)) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="modal-actions">
                <button type="button" class="btn btn-gray btn-close-modal" data-modal="modal-addnum">
                    <?= htmlspecialchars(t('users.cancel')) ?>
                </button>
                <button type="submit" class="btn btn-blue"><?= htmlspecialchars(t('contacts.add_btn')) ?></button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= htmlspecialchars(cspNonce()) ?>">
/** Remplit et ouvre la modale d'édition d'un contact. */
function openEdit(id, nom) {
    document.getElementById('edit-contact-id').value = id;
    document.getElementById('edit-nom').value        = nom;
    document.getElementById('modal-edit').classList.add('open');
}

/** Remplit et ouvre la modale d'ajout d'un numéro pour un contact. */
function openAddNum(id, nom) {
    document.getElementById('addnum-contact-id').value       = id;
    document.getElementById('addnum-nom-display').textContent = nom;
    document.getElementById('addnum-numero').value           = '';
    document.getElementById('modal-addnum').classList.add('open');
}

/** Ferme une modale par son id. */
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Boutons "✏️" : ouvre la modale d'édition (data-contact-id/nom)
document.querySelectorAll('.btn-edit-contact').forEach(btn => {
    btn.addEventListener('click', () => openEdit(btn.dataset.contactId, btn.dataset.contactNom));
});

// Boutons "+ Numéro" : ouvre la modale d'ajout de numéro
document.querySelectorAll('.btn-add-num').forEach(btn => {
    btn.addEventListener('click', () => openAddNum(btn.dataset.contactId, btn.dataset.contactNom));
});

// Boutons de fermeture de modale (data-modal cible l'ID à fermer)
document.querySelectorAll('.btn-close-modal').forEach(btn => {
    btn.addEventListener('click', () => closeModal(btn.dataset.modal));
});

// Sélecteurs à soumission automatique au changement (type de numéro)
document.querySelectorAll('select.select-auto-submit').forEach(sel => {
    sel.addEventListener('change', () => sel.form.submit());
});

// Formulaires à confirmation (attribut data-confirm sur la <form>)
document.querySelectorAll('form.form-confirm').forEach(f => {
    f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
});

// Fermeture des modales par clic sur l'overlay
['modal-edit', 'modal-addnum'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) closeModal(id);
    });
});
</script>
</body>
</html>
