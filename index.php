<?php
require 'config.php';
require 'auth.php';
requireLogin();
sendSecurityHeaders();
csrfStart();
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrfVerify();

try {
    $db = getDB();
} catch (PDOException $e) {
    error_log("DB: " . $e->getMessage());
    die(t('common.db_error'));
}

// ── Vidage du journal (réservé aux admins) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vider_journal') {
    if (!isAdmin()) { http_response_code(403); die(t('common.access_denied_short')); }
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM appels");
        $db->exec("DELETE FROM sqlite_sequence WHERE name='appels'");
        // Crée sync_meta si absente (cas d'un vidage avant toute synchro Python),
        // puis invalide le cache pour forcer la prochaine synchro à télécharger
        // le CSV distant (sinon fetch_remote_calls retourne -1 "CSV inchangé").
        $db->exec("CREATE TABLE IF NOT EXISTS sync_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
        $db->exec("DELETE FROM sync_meta WHERE key IN ('csv_mtime','csv_size')");
        $db->commit();
        header('Location: index.php');
        exit;
    } catch (PDOException $e) {
        $db->rollBack();
        die(t('common.clear_error'));
    }
}

// ── Filtres & pagination ──────────────────────────────────────────────────────
$etatsValides = ['', 'ANSWERED', 'NO ANSWER'];
$filtreEtat   = in_array(trim($_GET['etat'] ?? ''), $etatsValides, true) ? trim($_GET['etat'] ?? '') : '';
$filtreNum    = trim($_GET['numero'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$parPage    = 15;

// Cache des numéros spéciaux (une seule requête par chargement)
$speciaux = [];
foreach ($db->query("SELECT numero, label, categorie FROM numeros_speciaux")->fetchAll() as $s) {
    $speciaux[$s['numero']] = $s;
}

// ── Construction de la requête filtrée ────────────────────────────────────────
$where  = [];
$params = [];
if ($filtreEtat !== '') {
    $where[]  = "a.etat = ?";
    $params[] = $filtreEtat;
}
if ($filtreNum !== '') {
    $numNorm = normaliserNumero($filtreNum);
    if ($numNorm !== '') {
        $n        = '%' . $numNorm . '%';
        $where[]  = "(a.src LIKE ? OR a.dst LIKE ?)";
        $params[] = $n;
        $params[] = $n;
    }
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmtCount = $db->prepare("SELECT COUNT(*) FROM appels a $whereSQL");
$stmtCount->execute($params);
$totalLignes = (int)$stmtCount->fetchColumn();
$totalPages  = max(1, (int)ceil($totalLignes / $parPage));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * $parPage;

// Tous les paramètres positionnels (?) : PDO interdit de mélanger ? et :named dans une même requête
$sql  = "SELECT a.* FROM appels a $whereSQL ORDER BY a.date_appel DESC, a.duree DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k + 1, $v); }
$stmt->bindValue(count($params) + 1, $parPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset,  PDO::PARAM_INT);
$stmt->execute();
$appels = $stmt->fetchAll();

// ── Résolution des contacts en batch ──────────────────────────────────────────
$numerosPage = [];
foreach ($appels as $row) {
    $isOut = strlen(trim($row['src'])) <= 3;
    $num   = normaliserNumero($isOut ? $row['dst'] : $row['src']);
    if ($num !== '') $numerosPage[] = $num;
}
$numerosPage = array_values(array_unique($numerosPage));

$nomsParNumero = [];
if (!empty($numerosPage)) {
    $ph = implode(',', array_fill(0, count($numerosPage), '?'));
    $sn = $db->prepare("SELECT n.numero, c.nom FROM numeros n JOIN contacts c ON c.id = n.contact_id WHERE n.numero IN ($ph)");
    $sn->execute($numerosPage);
    foreach ($sn->fetchAll() as $r) {
        $nomsParNumero[$r['numero']][] = $r['nom'];
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
require_once 'index_functions.php';

/** Génère le HTML des lignes du tableau avec libellés traduits. Retourne ['html'=>string, 'lastDay'=>string]. */
function renderTableRows(array $appels, array $speciaux, array $nomsParNumero, string $lastDay = ''): array {
    $html = '';
    $cur  = $lastDay;
    foreach ($appels as $row) {
        if (trim($row['src']) === '' && trim($row['dst']) === '') continue;
        $dt   = new DateTime($row['date_appel']);
        $day  = $dt->format('d/m/Y');
        $info = infosLigne($row, $speciaux, $nomsParNumero);

        if ($day !== $cur) {
            $cur   = $day;
            $html .= '<tr class="day-row"><td colspan="5">📅 ' . htmlspecialchars($day) . '</td></tr>';
        }

        if ($info['numSpecial']) {
            $cat    = htmlspecialchars($info['numSpecial']['categorie']);
            $nomAff = '<span class="badge badge-cat-' . $cat . '">⚙ ' . htmlspecialchars($info['numSpecial']['label']) . '</span>';
        } elseif ($info['isMasque']) {
            $nomAff = '<span class="badge-numero badge-numero-inconnu">🔒 ' . htmlspecialchars(t('index.masque')) . '</span>';
        } elseif (empty($info['noms'])) {
            $nomAff = '<a href="tel:' . htmlspecialchars($info['numExterne']) . '" class="badge-numero badge-numero-inconnu">' . htmlspecialchars($info['numExterne']) . '</a>';
        } elseif (count($info['noms']) === 1) {
            // Numéro sur ligne 1, nom sur ligne 2 (tronqué via CSS sur mobile)
            $nomAff = '<span class="num-cell">'
                    . '<a href="tel:' . htmlspecialchars($info['numExterne']) . '" class="badge-numero badge-numero-connu">' . htmlspecialchars($info['numExterne']) . '</a>'
                    . '<span class="nom-contact">' . htmlspecialchars($info['noms'][0]) . '</span>'
                    . '</span>';
        } else {
            // Plusieurs contacts : numéro + noms + badge ambiguïté
            $nomAff = '<span class="num-cell">'
                    . '<a href="tel:' . htmlspecialchars($info['numExterne']) . '" class="badge-numero badge-numero-connu">' . htmlspecialchars($info['numExterne']) . '</a>'
                    . '<span class="nom-contact">' . htmlspecialchars(implode(', ', $info['noms']))
                    . ' <span class="badge badge-multi">' . htmlspecialchars(t('index.ambiguite')) . '</span></span>'
                    . '</span>';
        }

        $dir     = $info['isOut'] ? 'sortant' : 'entrant';
        $dirIcon = $info['isOut'] ? '⬅' : '➡';
        $dirText = $info['isOut']
                 ? ' ' . htmlspecialchars(t('index.sortant'))
                 : ' ' . htmlspecialchars(t('index.entrant'));

        $html .= '<tr>'
              . '<td>' . $dt->format('H:i') . '</td>'
              . '<td>' . $nomAff . '</td>'
              . '<td class="' . $dir . '"><span class="dir-icon">' . $dirIcon . '</span><span class="dir-text">' . $dirText . '</span></td>'
              . '<td class="col-poste"><span class="badge badge-cat-' . htmlspecialchars($info['posteCat']) . '">' . htmlspecialchars($info['posteLabel']) . '</span></td>'
              . '<td class="col-duree">' . formatDuree((int)($row['duree'] ?? 0)) . '</td>'
              . '</tr>';
    }
    return ['html' => $html, 'lastDay' => $cur];
}

// ── Réponses AJAX ─────────────────────────────────────────────────────────────
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
       && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$mode   = $_GET['mode'] ?? '';

// Vérifie s'il y a de nouveaux appels depuis une date donnée (auto-refresh léger en mode cron)
if ($isAjax && $mode === 'check_new') {
    $after = $_GET['after'] ?? '';
    $count = 0;
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $after)) {
        // Applique les mêmes filtres que la vue courante pour éviter les faux positifs
        $checkWhere = $where
            ? 'WHERE ' . implode(' AND ', $where) . ' AND a.date_appel > ?'
            : 'WHERE a.date_appel > ?';
        $s = $db->prepare("SELECT COUNT(*) FROM appels a $checkWhere");
        $s->execute(array_merge($params, [$after]));
        $count = (int)$s->fetchColumn();
    }
    // Renvoie aussi totalLignes/totalPages pour que le JS rafraîchisse l'indicateur
    // de page sans devoir recharger toute la vue quand l'utilisateur reste en page 2+
    header('Content-Type: application/json');
    echo json_encode([
        'count'       => $count,
        'totalLignes' => $totalLignes,
        'totalPages'  => $totalPages,
    ]);
    exit;
}

// Retourne la page cible pour une date donnée (navigation par date)
if ($isAjax && $mode === 'find_date') {
    $targetDate = $_GET['date'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Date invalide']);
        exit;
    }
    $aw  = $where ? 'WHERE ' . implode(' AND ', $where) . ' AND date(a.date_appel) > ?' : 'WHERE date(a.date_appel) > ?';
    $sfd = $db->prepare("SELECT COUNT(*) FROM appels a $aw");
    $sfd->execute(array_merge($params, [$targetDate]));
    $tp  = max(1, (int)floor((int)$sfd->fetchColumn() / $parPage) + 1);
    header('Content-Type: application/json');
    echo json_encode(['page' => $tp, 'totalPages' => $totalPages]);
    exit;
}

// Retourne les lignes HTML d'une page (scroll infini + windowing)
if ($isAjax && $mode === 'rows') {
    $result = renderTableRows($appels, $speciaux, $nomsParNumero, $_GET['lastDay'] ?? '');
    header('Content-Type: application/json');
    echo json_encode([
        'html'       => $result['html'],
        'lastDay'    => $result['lastDay'],
        'hasMore'    => $page < $totalPages,
        'page'       => $page,
        'totalPages' => $totalPages,
    ]);
    exit;
}

$rendered  = renderTableRows($appels, $speciaux, $nomsParNumero);
// Date la plus récente du journal respectant les filtres actifs (pour check_new).
$stmtMax = $db->prepare("SELECT MAX(date_appel) FROM appels a $whereSQL");
$stmtMax->execute($params);
$firstDate = (string)$stmtMax->fetchColumn() ?: '';
// Locale JS pour le formatage des nombres
$jsLocale  = detectLang() === 'fr' ? 'fr-FR' : 'en-US';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(detectLang()) ?>">
<head>
    <meta charset="UTF-8">
	<meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars(t('index.title')) ?></title>
    <link rel="icon" type="image/png" href="./favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Pleine hauteur viewport : le scroll est géré par .table-scroll, pas le body */
        html, body { height: 100%; overflow: hidden; }
        body {
            height: 100dvh;           /* dvh : exclut les barres mobiles rétractables */
            display: flex; flex-direction: column;
            overflow: hidden; padding: 0;
        }

        /* Bouton Synchroniser : vert */
        .btn-sync {
            padding: 6px 14px; border: none; border-radius: 5px; cursor: pointer;
            background: #27ae60; color: white; font-weight: 700; font-size: .82rem; white-space: nowrap;
        }
        .btn-sync:hover    { background: #38bf71; }
        .btn-sync:disabled { background: #888; cursor: wait; } /* pendant la sync en cours */
        /* Bouton Vider : rouge, avec hover distinct */
        .btn-sync-danger          { background: #c0392b !important; }
        .btn-sync-danger:hover    { background: #e74c3c !important; }
        .btn-sync-danger:disabled { background: #888 !important; cursor: wait; }

        /* Boutons hamburger visibles uniquement sur mobile (cachés sur desktop) */
        #btn-toggle-filtres, #btn-toggle-topbar {
            display: none; background: none; border: 1px solid #556;
            color: #ccc; border-radius: 5px; padding: 5px 10px; cursor: pointer;
            font-size: .82rem; white-space: nowrap;
        }
        #btn-toggle-topbar { font-size: 1rem; } /* icône hamburger légèrement plus grande */

        /* Zone d'actions de la topbar : liens et boutons en ligne */
        .topbar-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }

        /* ── Filtres ── */
        /* Barre de filtres collée sous la topbar, fond blanc, ne scroll pas */
        .filtres-bar {
            background: white; padding: 10px 16px; box-shadow: 0 1px 3px #0001;
            display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; flex-shrink: 0;
        }
        /* Masquée par défaut sur mobile (toggle JS) */
        .filtres-bar.hidden { display: none; }
        .filtres-bar label { font-size: .8rem; color: #555; display: block; margin-bottom: 2px; }
        .filtres-bar input[type=text] { width: 155px; }
        /* Bouton Filtrer */
        .btn-filtre { padding: 6px 14px; border: none; border-radius: 5px; background: #374785; color: white; font-weight: 600; cursor: pointer; font-size: .86rem; }
        .btn-filtre:hover { background: #4a5fa8; }
        /* Bouton Reset (lien stylisé en bouton gris) */
        .btn-reset { padding: 6px 12px; border: none; border-radius: 5px; background: #bbb; color: white; cursor: pointer; font-size: .86rem; text-decoration: none; display: inline-block; }

        /* ── Layout principal (scroll infini) ── */
        /* Wrapper : occupe tout l'espace restant entre topbar et bas de viewport */
        .main-wrapper { flex: 1; display: flex; flex-direction: column; min-height: 0; padding: 12px 16px; }
        /* Card : conteneur flex colonne pour que .table-scroll puisse scroller indépendamment */
        .card { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; padding: 0; }

        /* En-tête de la card : info page + contrôles navigation, ne scroll pas */
        .card-header {
            flex-shrink: 0; display: flex; align-items: center; flex-wrap: wrap;
            gap: 10px; padding: 8px 16px; border-bottom: 1px solid #eee;
            font-size: .82rem; color: #777;
        }
        /* Contrôles de navigation (saut page/date) poussés à droite */
        .nav-controls { display: flex; align-items: center; gap: 6px; margin-left: auto; flex-wrap: wrap; }

        /* Zone scrollable du tableau : prend tout l'espace restant dans .card */
        .table-scroll { flex: 1; overflow-y: auto; min-height: 0; -webkit-overflow-scrolling: touch; }

        /* ── Tableau ── */
        /* En-tête de tableau fixe pendant le scroll */
        thead tr { position: sticky; top: 0; z-index: 5; }
        /* Ligne de séparateur de jour (fond bleu foncé) */
        .day-row td { background: #374785; color: white; font-weight: 700; font-size: .78rem; letter-spacing: .04em; padding: 5px 12px; }

        /* Couleurs directionnelles des appels */
        .entrant   { color: #1565c0; font-weight: 600; } /* bleu : appel entrant */
        .sortant   { color: #1b5e20; font-weight: 600; } /* vert : appel sortant */
        /* Couleurs d'état (utilisées si besoin via classe dynamique) */
        .ANSWERED  { color: #2e7d32; font-weight: 700; } /* vert  : répondu */
        .NO_ANSWER { color: #c62828; }                   /* rouge : manqué  */

        /* Badge d'avertissement d'ambiguïté (numéro lié à plusieurs contacts) */
        .badge-multi         { background: #fff3e0; color: #e65100; }
        /* Colonnes poste et durée : pas de retour à la ligne */
        .col-poste, .col-duree { white-space: nowrap; }
        .col-duree { color: #555; }

        /* Badge numéro de téléphone cliquable (lien tel:) */
        .badge-numero { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: .82rem; font-weight: 600; text-decoration: none; }
        .badge-numero-connu   { background: #e3f2fd; color: #1565c0; } /* bleu : numéro identifié dans les contacts */
        .badge-numero-inconnu { background: #eceff1; color: #546e7a; } /* gris : numéro inconnu ou masqué           */

        /* Conteneur numéro + nom : inline sur desktop, colonne sur mobile */
        .num-cell { display: inline; }
        /* Nom du contact affiché à côté du numéro sur desktop */
        .nom-contact { color: #555; font-size: .82rem; margin-left: 4px; }

        /* Spinner de chargement de la page suivante (scroll infini) */
        #scroll-loader { display: none; text-align: center; padding: 14px; color: #888; font-size: .88rem; }

        /* ── Bannière nouveaux appels ── */
        /* Notification fixe en haut, visible quand l'utilisateur a scrollé ou est en page 2+ */
        #new-calls-banner {
            display: none; position: fixed; top: 56px; left: 50%; transform: translateX(-50%);
            background: #374785; color: white; padding: 8px 22px; border-radius: 20px;
            font-size: .85rem; font-weight: 600; cursor: pointer; z-index: 40;
            box-shadow: 0 2px 8px #0003; white-space: nowrap; border: none;
        }
        #new-calls-banner:hover { background: #4a5fa8; }

        /* ── Notification de sync ── */
        /* Toast flottant centré en bas, couleur variable (ok/erreur appliquée via JS) */
        #sync-status {
            display: none; position: fixed; bottom: 16px; left: 50%; transform: translateX(-50%);
            padding: 8px 20px; border-radius: 20px; font-size: .85rem; font-weight: 600;
            box-shadow: 0 2px 8px #0003; z-index: 50; white-space: nowrap;
        }

        /* ── Mobile ── */
        @media (max-width: 640px) {
            .topbar { padding: 8px 10px; gap: 6px; }
            .topbar h2 { font-size: .95rem; }
            /* Affiche les boutons hamburger uniquement sur mobile */
            #btn-toggle-topbar, #btn-toggle-filtres { display: inline-block; }
            /* Actions topbar masquées par défaut, affichées via JS (.open) */
            .topbar-actions       { display: none; width: 100%; }
            .topbar-actions.open  { display: flex; }
            .main-wrapper { padding: 6px; }
            .card-header { padding: 6px 10px; font-size: .75rem; }
            .nav-controls input[type=number] { width: 50px; }
            .nav-controls input[type=date]   { width: auto; font-size: .75rem; }
            th, td { padding: 6px 8px; font-size: .8rem; }
            /* Direction : affiche uniquement la flèche ➡/⬅ sur mobile */
            .dir-text { display: none; }
            /* Numéro et nom : empilés verticalement sur mobile */
            .num-cell { display: flex; flex-direction: column; align-items: flex-start; }
            /* Nom sous le numéro, tronqué avec ellipsis (~25 caractères visuels) */
            .nom-contact { margin-left: 0; margin-top: 2px; font-size: .75rem;
                           max-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            /* Masque uniquement la colonne Durée sur mobile (Poste reste visible) */
            .col-duree, th:nth-child(5), td:nth-child(5) { display: none; }
        }
        /* Sur desktop : la barre de filtres est toujours visible (ignore .hidden) */
        @media (min-width: 641px) {
            .filtres-bar.hidden { display: flex; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <h2>📞 <?= htmlspecialchars(t('index.title')) ?></h2>
    <button id="btn-toggle-topbar" title="Menu">☰</button>
    <button id="btn-toggle-filtres" title="<?= htmlspecialchars(t('index.filter.btn')) ?>">🔍</button>
    <div class="topbar-actions" id="topbar-actions">
        <button id="btn-sync-main" class="btn-sync">🔄 <?= htmlspecialchars(t('index.sync')) ?></button>
        <?php if (isAdmin()): ?>
        <form method="post" class="form-confirm" style="display:inline"
              data-confirm="<?= htmlspecialchars(t('index.vider_confirm')) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="vider_journal">
            <button type="submit" class="btn-sync btn-sync-danger">🗑 <?= htmlspecialchars(t('index.vider')) ?></button>
        </form>
            <a href="contacts.php">👥 <?= htmlspecialchars(t('nav.contacts')) ?></a>
            <a href="numeros_speciaux.php">⚙️ <?= htmlspecialchars(t('nav.speciaux')) ?></a>
        <?php endif; ?>
        <?= topbarUserHtml() ?>
    </div>
</div>

<form method="get" class="filtres-bar hidden" id="filtres-bar">
    <div>
        <label><?= htmlspecialchars(t('index.filter.numero')) ?></label>
        <input type="text" name="numero" value="<?= htmlspecialchars($filtreNum) ?>"
               placeholder="<?= htmlspecialchars(t('index.filter.placeholder')) ?>">
    </div>
    <div>
        <label><?= htmlspecialchars(t('index.filter.etat')) ?></label>
        <select name="etat">
            <option value=""><?= htmlspecialchars(t('index.filter.tous')) ?></option>
            <option value="ANSWERED"  <?= $filtreEtat === 'ANSWERED'  ? 'selected' : '' ?>>✅ <?= htmlspecialchars(t('index.filter.repondus')) ?></option>
            <option value="NO ANSWER" <?= $filtreEtat === 'NO ANSWER' ? 'selected' : '' ?>>❌ <?= htmlspecialchars(t('index.filter.manques')) ?></option>
        </select>
    </div>
    <button type="submit" class="btn-filtre">🔍 <?= htmlspecialchars(t('index.filter.btn')) ?></button>
    <a href="index.php" class="btn-reset">✕ <?= htmlspecialchars(t('index.filter.reset')) ?></a>
</form>

<div class="main-wrapper">
    <div class="card">
        <div class="card-header">
            <span id="page-info">
                <?= htmlspecialchars(t('index.appels_count', ['n' => number_format($totalLignes, 0, ',', ' ')])) ?> —
                <?= htmlspecialchars(t('index.page')) ?> <strong><?= $page ?></strong> / <strong><?= $totalPages ?></strong>
            </span>
            <div class="nav-controls">
                <label><?= htmlspecialchars(t('index.page')) ?></label>
                <input type="number" id="input-jump-page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>"
                       style="width:65px;padding:4px 7px;border:1px solid #ccc;border-radius:5px;font-size:.82rem">
                <button id="btn-jump-page"
                        style="padding:4px 10px;background:#374785;color:white;border:none;border-radius:5px;cursor:pointer">→</button>
                <label><?= htmlspecialchars(t('index.date')) ?></label>
                <input type="date" id="input-jump-date"
                       style="padding:4px 7px;border:1px solid #ccc;border-radius:5px;font-size:.82rem">
            </div>
        </div>

        <div class="table-scroll" id="table-scroll">
            <table id="appels-table">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars(t('index.col.heure')) ?></th>
                        <th><?= htmlspecialchars(t('index.col.nom')) ?></th>
                        <th><?= htmlspecialchars(t('index.col.sens')) ?></th>
                        <th class="col-poste"><?= htmlspecialchars(t('index.col.poste')) ?></th>
                        <th class="col-duree"><?= htmlspecialchars(t('index.col.duree')) ?></th>
                    </tr>
                </thead>
                <!-- Spacer haut : représente la hauteur virtuelle des pages déchargées du haut du DOM -->
                <tbody id="top-spacer-tbody">
                    <tr style="height:0"><td id="top-spacer-td" colspan="5" style="height:0;padding:0;border:0;line-height:0"></td></tr>
                </tbody>
                <!-- Page initiale rendue côté serveur -->
                <tbody id="page-body-<?= $page ?>" data-page="<?= $page ?>"><?= $rendered['html'] ?></tbody>
            </table>
            <div id="scroll-loader">⏳ <?= htmlspecialchars(t('index.loading')) ?></div>
            <div id="scroll-sentinel" style="height:1px"></div>
        </div>
    </div>
</div>

<span id="sync-status"></span>
<button id="new-calls-banner">
    <span id="new-calls-text"></span> <?= htmlspecialchars(t('index.new_calls_show')) ?>
</button>

<script nonce="<?= htmlspecialchars(cspNonce()) ?>">
const SYNC_MODE = <?= json_encode(SYNC_MODE) ?>;

// ── Traductions JS (injectées depuis PHP au chargement de la page) ─────────────
const I18N = {
    sync:          <?= json_encode(t('index.sync')) ?>,
    syncing:       <?= json_encode(t('index.syncing')) ?>,
    loading:       <?= json_encode(t('index.loading')) ?>,
    syncOk:        <?= json_encode(t('index.sync_ok')) ?>,
    syncErr:       <?= json_encode(t('index.sync_error')) ?>,
    dateNotFound:  <?= json_encode(t('index.date_not_found')) ?>,
    newCalls:      <?= json_encode(t('index.new_calls')) ?>,       // contient {n}
};
// Locale pour le formatage des nombres (ex: séparateur de milliers)
const LOCALE = <?= json_encode($jsLocale) ?>;

// ── Windowing ─────────────────────────────────────────────────────────────────
/** Nombre max de pages (de 15 lignes) maintenues simultanément dans le DOM.
 *  Les pages excédentaires sont déchargées du haut ; leur hauteur est conservée
 *  dans le spacer pour maintenir la position du scrollbar. */
const MAX_PAGES_IN_DOM = 5;

// ── État global ───────────────────────────────────────────────────────────────
let currentPage     = <?= (int)$page ?>;
let totalPages      = <?= (int)$totalPages ?>;
let totalLignes     = <?= (int)$totalLignes ?>;
let hasMore         = <?= ($page < $totalPages) ? 'true' : 'false' ?>;
let lastDay         = <?= json_encode($rendered['lastDay']) ?>;
let newestDate      = <?= json_encode($firstDate) ?>; // date du dernier appel affiché (pour check_new)
let isLoading       = false;
let isJumping       = false;
let isSyncing       = false;
let autoTimer       = null;

/** Fenêtre de pages dans le DOM : [{page:int, tbodyEl:HTMLElement, height:int}] */
let pageWindows     = [];
/** Hauteur cumulée (px) des pages supprimées du haut du DOM. */
let topSpacerHeight = 0;

// ── Refs DOM ──────────────────────────────────────────────────────────────────
const loader         = document.getElementById('scroll-loader');
const sentinel       = document.getElementById('scroll-sentinel');
const scrollZone     = document.getElementById('table-scroll');
const pageInfo       = document.getElementById('page-info');
const jumpInput      = document.getElementById('input-jump-page');
const statusEl       = document.getElementById('sync-status');
const tableEl        = document.getElementById('appels-table');
const topSpacerTbody = document.getElementById('top-spacer-tbody');
const topSpacerTr    = topSpacerTbody.querySelector('tr');
const topSpacerTd    = document.getElementById('top-spacer-td');

// ── Initialisation de la fenêtre de pages ─────────────────────────────────────
/** Enregistre le tbody rendu côté serveur comme première page de la fenêtre. */
(function initPageWindows() {
    const initialTbody = document.getElementById('page-body-<?= (int)$page ?>');
    if (initialTbody) {
        pageWindows.push({ page: <?= (int)$page ?>, tbodyEl: initialTbody, height: initialTbody.offsetHeight });
    }
})();

// ── Utilitaires ───────────────────────────────────────────────────────────────
/** Retourne les paramètres GET courants (filtres actifs). */
function getFilterParams() { return new URLSearchParams(window.location.search); }

/** Met à jour l'indicateur de page et le max du champ de saut. */
function updatePageInfo() {
    // Reconstruit "X appel(s) — page Y / Z" avec la traduction de 'index.appels_count'
    const countStr = <?= json_encode(t('index.appels_count')) ?>.replace('{n}', totalLignes.toLocaleString(LOCALE));
    pageInfo.innerHTML = countStr + ' — ' + <?= json_encode(t('index.page')) ?> +
        ' <strong>' + currentPage + '</strong> / <strong>' + totalPages + '</strong>';
    jumpInput.max = totalPages;
}

/** Applique la hauteur du spacer haut pour représenter les pages déchargées. */
function setTopSpacer(h) {
    topSpacerHeight = h;
    topSpacerTr.style.height = h + 'px';
    topSpacerTd.style.height = h + 'px';
}

/** Décharge les pages les plus anciennes du DOM si la fenêtre dépasse MAX_PAGES_IN_DOM.
 *  Leur hauteur est ajoutée au spacer pour préserver la position du scrollbar. */
function trimTop() {
    while (pageWindows.length > MAX_PAGES_IN_DOM) {
        const oldest = pageWindows.shift();
        setTopSpacer(topSpacerHeight + oldest.height);
        oldest.tbodyEl.remove();
    }
}

/** Décharge les pages les plus récentes du DOM (symétrique de trimTop) lors
 *  d'un scroll vers le haut. hasMore repasse à true pour permettre le rechargement. */
function trimBottom() {
    while (pageWindows.length > MAX_PAGES_IN_DOM) {
        const newest = pageWindows.pop();
        newest.tbodyEl.remove();
        hasMore = true;
    }
}

let statusTimer = null;
/** Affiche un toast de statut coloré en bas de page. */
function showStatus(text, bg, color, autoHide = true) {
    clearTimeout(statusTimer);
    Object.assign(statusEl.style, { background: bg, color, display: 'inline-block' });
    statusEl.textContent = text;
    if (autoHide) statusTimer = setTimeout(() => { statusEl.style.display = 'none'; }, 3000);
}

/** Affiche/masque la barre de filtres sur mobile. */
function toggleFiltres() { document.getElementById('filtres-bar').classList.toggle('hidden'); }
/** Affiche/masque les actions de la topbar sur mobile. */
function toggleTopbar()  { document.getElementById('topbar-actions').classList.toggle('open'); }

// ── Chargement d'une page (scroll infini avec windowing) ──────────────────────
/** Charge la page demandée dans un nouveau tbody, l'insère dans le tableau,
 *  puis décharge les pages excédentaires du haut via trimTop(). */
async function loadPage(page) {
    if (isLoading) return;
    isLoading = true;
    loader.style.display = 'block';

    const params = getFilterParams();
    // Le lastDay n'est valide que si on charge la page qui suit immédiatement
    // la dernière page de la fenêtre (enchaînement continu des séparateurs de jour).
    const lastInWindow = pageWindows.length > 0 ? pageWindows[pageWindows.length - 1].page : 0;
    const ld = (page === lastInWindow + 1) ? lastDay : '';
    params.set('page', page); params.set('mode', 'rows'); params.set('lastDay', ld);

    try {
        const resp = await fetch('index.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();

        // Crée un tbody dédié à cette page et l'insère à la fin du tableau
        const newTbody = document.createElement('tbody');
        newTbody.id = 'page-body-' + page;
        newTbody.dataset.page = page;
        newTbody.innerHTML = data.html;
        tableEl.appendChild(newTbody);

        hasMore     = data.hasMore;
        totalPages  = data.totalPages;
        lastDay     = data.lastDay;

        // offsetHeight force un reflow et retourne la hauteur réelle du tbody inséré
        pageWindows.push({ page, tbodyEl: newTbody, height: newTbody.offsetHeight });

        // Décharge les pages excédentaires du haut pour limiter le DOM
        trimTop();

    } catch (e) {
        console.error('loadPage:', e);
        showStatus('❌ ' + I18N.syncErr, '#ffebee', '#c62828');
    } finally {
        isLoading = false;
        loader.style.display = 'none';
    }
}

/** Charge une page précédente (scroll vers le haut) et la prépend au tableau.
 *  Ajuste topSpacer et scrollTop pour préserver la position visuelle de l'utilisateur. */
async function loadPageAbove(page) {
    if (isLoading || page < 1) return;
    if (pageWindows.some(pw => pw.page === page)) return; // déjà chargée
    isLoading = true;
    loader.style.display = 'block';

    const params = getFilterParams();
    // lastDay vide : le serveur recalcule les séparateurs depuis le début de la page
    params.set('page', page); params.set('mode', 'rows'); params.set('lastDay', '');

    try {
        const resp = await fetch('index.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();

        const newTbody = document.createElement('tbody');
        newTbody.id = 'page-body-' + page;
        newTbody.dataset.page = page;
        newTbody.innerHTML = data.html;

        // Insère le tbody juste après le spacer, avant le premier tbody de page existant
        const firstPageTbody = pageWindows[0] ? pageWindows[0].tbodyEl : null;
        if (firstPageTbody) {
            tableEl.insertBefore(newTbody, firstPageTbody);
        } else {
            tableEl.appendChild(newTbody);
        }

        const h = newTbody.offsetHeight;
        pageWindows.unshift({ page, tbodyEl: newTbody, height: h });

        // Compense le spacer (la zone virtuelle du haut diminue de h, mais le contenu
        // réel ajouté mesure h : la position de scroll reste donc inchangée visuellement).
        const newSpacer = Math.max(0, topSpacerHeight - h);
        // Ajuste scrollTop pour éviter un saut si le spacer ne peut pas absorber toute la hauteur
        const delta = topSpacerHeight - newSpacer; // partie effectivement retirée du spacer
        scrollZone.scrollTop += (h - delta);
        setTopSpacer(newSpacer);

        // Décharge les pages excédentaires du bas
        trimBottom();

    } catch (e) {
        console.error('loadPageAbove:', e);
        showStatus('❌ ' + I18N.syncErr, '#ffebee', '#c62828');
    } finally {
        isLoading = false;
        loader.style.display = 'none';
    }
}

// ── Saut vers une page précise ────────────────────────────────────────────────
/** Vide tous les tbodys de pages, réinitialise le spacer et charge la page demandée. */
async function jumpToPage(page) {
    page = Math.max(1, Math.min(parseInt(page) || 1, totalPages));
    if (isJumping) return;
    isJumping = true;
    hasMore   = false;

    let waited = 0;
    while (isLoading && waited < 5000) { await new Promise(r => setTimeout(r, 50)); waited += 50; }

    // Supprime tous les tbodys de pages du DOM et réinitialise l'état
    pageWindows.forEach(pw => pw.tbodyEl.remove());
    pageWindows = [];
    setTopSpacer(0);
    lastDay     = '';
    currentPage = 0;
    scrollZone.scrollTop = 0;

    try {
        newestDate  = new Date().toISOString().replace('T', ' ').slice(0, 19);
        await loadPage(page);
        currentPage = page;
        updatePageInfo();
    } finally {
        isJumping = false;
    }
}

// ── Navigation par date ───────────────────────────────────────────────────────
/** Cherche la page correspondant à une date et y saute. */
async function jumpToDate(dateStr) {
    if (!dateStr) return;
    const params = getFilterParams();
    params.set('mode', 'find_date'); params.set('date', dateStr);
    try {
        const resp = await fetch('index.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await resp.json();
        if (data.error) throw new Error(data.error);
        if (data.page) await jumpToPage(data.page);
    } catch (e) {
        showStatus('❌ ' + I18N.dateNotFound, '#ffebee', '#c62828');
    }
}

// ── Scroll infini (IntersectionObserver) ──────────────────────────────────────
/** Déclenche loadPage() quand le sentinel en bas du tableau devient visible.
 *  La prochaine page est calculée depuis pageWindows pour rester indépendant
 *  de currentPage, qui ne suit que les sauts manuels. */
new IntersectionObserver(entries => {
    if (entries[0].isIntersecting && hasMore && !isLoading && !isJumping) {
        const nextPage = pageWindows.length > 0
            ? pageWindows[pageWindows.length - 1].page + 1
            : currentPage + 1;
        loadPage(nextPage);
    }
}, { root: scrollZone, rootMargin: '200px', threshold: 0 }).observe(sentinel);

/** Déclenche loadPageAbove() quand le spacer du haut (pages déchargées) redevient visible.
 *  Observe le tbody du spacer : si l'utilisateur remonte et que des pages ont été déchargées,
 *  on recharge la page précédant la première page actuellement en fenêtre. */
new IntersectionObserver(entries => {
    if (!entries[0].isIntersecting || isLoading || isJumping) return;
    if (topSpacerHeight <= 0 || pageWindows.length === 0) return;
    const prevPage = pageWindows[0].page - 1;
    if (prevPage >= 1) loadPageAbove(prevPage);
}, { root: scrollZone, rootMargin: '200px', threshold: 0 }).observe(topSpacerTbody);

// ── Suivi de la page dominante visible (indicateur "Page X / Y") ──────────────
/** Détermine la page dont le tbody occupe le plus de surface dans la zone visible
 *  et met à jour currentPage + l'indicateur. Débouncée via requestAnimationFrame. */
let scrollRaf = null;
function updateCurrentPageFromScroll() {
    if (isJumping || pageWindows.length === 0) return;
    const zoneTop    = scrollZone.scrollTop;
    const zoneBottom = zoneTop + scrollZone.clientHeight;
    let bestPage     = pageWindows[0].page;
    let bestOverlap  = 0;

    // Pour chaque tbody en fenêtre, calcule l'intersection verticale avec la zone visible
    for (const pw of pageWindows) {
        const top     = pw.tbodyEl.offsetTop;
        const bottom  = top + pw.height;
        const overlap = Math.max(0, Math.min(zoneBottom, bottom) - Math.max(zoneTop, top));
        if (overlap > bestOverlap) { bestOverlap = overlap; bestPage = pw.page; }
    }

    if (bestPage !== currentPage) {
        currentPage = bestPage;
        updatePageInfo();
    }
}
scrollZone.addEventListener('scroll', () => {
    if (scrollRaf !== null) return;
    scrollRaf = requestAnimationFrame(() => {
        scrollRaf = null;
        updateCurrentPageFromScroll();
    });
}, { passive: true });

// ── Contrôles UI ──────────────────────────────────────────────────────────────
document.getElementById('btn-jump-page').addEventListener('click', () => jumpToPage(jumpInput.value));
jumpInput.addEventListener('keydown', e => { if (e.key === 'Enter') jumpToPage(e.target.value); });
document.getElementById('input-jump-date').addEventListener('change', e => jumpToDate(e.target.value));

// ── Synchronisation (appel à sync.php) ────────────────────────────────────────
/** Lance la synchronisation via sync.php et appelle onSuccess si ok. */
function lancerSync(onSuccess) {
    if (isSyncing) return;
    isSyncing = true;
    document.querySelectorAll('.btn-sync').forEach(b => {
        if (b.textContent.includes(I18N.sync.replace('🔄 ', ''))) {
            b.disabled = true; b.textContent = '⏳ ' + I18N.syncing;
        }
    });
    showStatus(I18N.syncing, '#fff8e1', '#e65100', false);

    fetch('sync.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: 'csrf_token=' + encodeURIComponent(<?= json_encode(csrfToken()) ?>)
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) { showStatus('✅ ' + data.msg, '#e8f5e9', '#2e7d32'); if (onSuccess) onSuccess(); }
        else          { showStatus('❌ ' + data.msg, '#ffebee', '#c62828', false); }
    })
    .catch(err => showStatus('❌ ' + err.message, '#ffebee', '#c62828', false))
    .finally(() => {
        isSyncing = false;
        document.querySelectorAll('.btn-sync').forEach(b => {
            if (b.disabled) { b.disabled = false; b.textContent = '🔄 ' + I18N.sync; }
        });
    });
}

/** Affiche la bannière signalant de nouveaux appels disponibles. */
function showNewCallsBanner(count) {
    document.getElementById('new-calls-text').textContent =
        I18N.newCalls.replace('{n}', count);
    document.getElementById('new-calls-banner').style.display = 'block';
}

/** Cache la bannière et saute à la page 1.
 *  newestDate est réinitialisé ici pour éviter la re-détection par le timer concurrent. */
function dismissBanner() {
    document.getElementById('new-calls-banner').style.display = 'none';
    newestDate = new Date().toISOString().replace('T', ' ').slice(0, 19);
    jumpToPage(1);
}

/** Vérifie si de nouveaux appels existent depuis newestDate.
 *  Recharge la page 1 si l'utilisateur est en haut, sinon affiche la bannière. */
async function checkForNewAndBanner() {
    try {
        const params = getFilterParams();
        params.set('mode', 'check_new'); params.set('after', newestDate);
        const resp = await fetch('index.php?' + params, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await resp.json();
        if (data.count > 0) {
            // Rafraîchit le compteur global avant le saut ou l'affichage de la bannière
            if (typeof data.totalLignes === 'number') totalLignes = data.totalLignes;
            if (typeof data.totalPages  === 'number') totalPages  = data.totalPages;

            // Refresh silencieux uniquement si : page 1, en haut du scroll,
            // ET une seule page chargée dans le DOM (sinon on tronquerait la fenêtre)
            if (currentPage <= 1 && scrollZone.scrollTop < 80 && pageWindows.length <= 1) {
                // Mis à jour avant le saut pour éviter qu'un timer concurrent
                // re-détecte les mêmes nouveaux appels
                document.getElementById('new-calls-banner').style.display = 'none';
                newestDate = new Date().toISOString().replace('T', ' ').slice(0, 19);
                await jumpToPage(1);
            } else {
                showNewCallsBanner(data.count);
                updatePageInfo();
            }
        }
    } catch (e) { /* silencieux */ }
}

/** Vérifie les nouveaux appels sans déclencher de sync (mode cron). */
async function checkAndRefreshDisplay() {
    await checkForNewAndBanner();
}

/** Déclenché par le bouton Synchroniser : sync puis vérification des nouveaux appels. */
function syncAndRefresh() {
    lancerSync(async () => {
        if (!newestDate) {
            window.location.reload();
        } else {
            await checkForNewAndBanner();
        }
    });
}

// ── Auto-refresh ──────────────────────────────────────────────────────────────
/** Démarre le timer d'auto-refresh selon le mode configuré (web/cron). */
function startAutoRefresh() {
    clearInterval(autoTimer);
    // Les deux modes vérifient uniquement la DB (pas de sync Python).
    // web  : intervalle de 60 s (Python lancé manuellement via le bouton).
    // cron : intervalle de 30 s (Python lancé par tâche planifiée).
    const interval = SYNC_MODE === 'web' ? 60000 : 30000;

    autoTimer = setInterval(() => {
        if (document.hidden) return;
        checkAndRefreshDisplay();
    }, interval);
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        checkAndRefreshDisplay();
        startAutoRefresh();
    } else {
        clearInterval(autoTimer);
        autoTimer = null;
    }
});

startAutoRefresh();

// ── Listeners remplaçant les anciens handlers inline (CSP stricte) ────────────
document.getElementById('btn-toggle-topbar').addEventListener('click', toggleTopbar);
document.getElementById('btn-toggle-filtres').addEventListener('click', toggleFiltres);
document.getElementById('btn-sync-main').addEventListener('click', syncAndRefresh);
document.getElementById('new-calls-banner').addEventListener('click', dismissBanner);

// Formulaires à confirmation (attribut data-confirm sur la <form>)
document.querySelectorAll('form.form-confirm').forEach(f => {
    f.addEventListener('submit', e => {
        if (!confirm(f.dataset.confirm)) e.preventDefault();
    });
});
</script>
</body>
</html>
