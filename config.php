<?php
define('DB_PATH',      __DIR__ . '/journal.db');
define('NUMERO_MASQUE', 'Masqué');

// 'web'  : sync Python déclenchée par le bouton / auto-refresh.
// 'cron' : Python lancé par tâche planifiée ; l'auto-refresh ne rafraîchit que l'affichage.
define('SYNC_MODE', 'web');

// ── Session sécurisée ────────────────────────────────────────────────────────
$_isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
// Durée de session : 30 jours — l'utilisateur reste connecté jusqu'à la déconnexion explicite
define('SESSION_LIFETIME', 60 * 60 * 24 * 30);
ini_set('session.cookie_secure',  $_isHttps ? 1 : 0);
ini_set('session.gc_maxlifetime', SESSION_LIFETIME); // Durée de vie côté serveur
session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME, // Durée de vie du cookie côté client
    'path'     => '/', 'domain'  => '',
    'secure'   => $_isHttps, 'httponly' => true, 'samesite' => 'Lax',
]);
unset($_isHttps);

/** Retourne le nonce CSP de la requête courante (généré une seule fois par requête).
 *  À injecter dans chaque balise <script> inline autorisée. */
function cspNonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/** Envoie les en-têtes de sécurité HTTP standards (clickjacking, sniffing, referrer, CSP, HSTS). */
function sendSecurityHeaders(): void {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    // CSP stricte : scripts autorisés uniquement avec le nonce de la requête,
    // plus de 'unsafe-inline'. Les styles inline restent permis (tolérance pragmatique).
    $nonce = cspNonce();
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'");
    // HSTS : force HTTPS pour 1 an (ne pas activer si le site n'est pas 100% HTTPS)
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
	// Désactive l'accès aux API navigateur sensibles (caméra, micro, géoloc…)
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// ── Base de données ───────────────────────────────────────────────────────────
/** Retourne l'instance PDO SQLite (singleton). Configure les PRAGMAs et initialise le schéma. */
function getDB(): PDO {
    static $db = null;
    if ($db !== null) return $db;

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA synchronous=NORMAL");
    $db->exec("PRAGMA cache_size=10000");    // ~10 Mo de cache en RAM
    $db->exec("PRAGMA temp_store=MEMORY");   // tables temporaires en RAM
    $db->exec("PRAGMA mmap_size=134217728"); // 128 Mo de mmap pour lectures rapides
    $db->exec("PRAGMA foreign_keys=ON");

    initDB($db);
    return $db;
}

/** Crée les tables, index et données par défaut si absents ; applique les migrations nécessaires. */
function initDB(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS contacts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        nom        TEXT NOT NULL UNIQUE,
        created_at TEXT DEFAULT (datetime('now','localtime')),
        updated_at TEXT DEFAULT (datetime('now','localtime'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS numeros (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        numero     TEXT NOT NULL,
        contact_id INTEGER NOT NULL,
        type       TEXT DEFAULT 'inconnu',
        FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
        UNIQUE(numero, contact_id)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS appels (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        date_appel TEXT NOT NULL,
        src        TEXT NOT NULL,
        dst        TEXT NOT NULL,
        duree      INTEGER DEFAULT 0,
        etat       TEXT,
        clid       TEXT,
        uniqueid   TEXT
    )");

    // Migration : ajout colonne uniqueid si absente (base ancienne)
    $cols = array_column($db->query("PRAGMA table_info(appels)")->fetchAll(), 'name');
    if (!in_array('uniqueid', $cols, true)) {
        $db->exec("ALTER TABLE appels ADD COLUMN uniqueid TEXT");
    }
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_appels_uniqueid ON appels(uniqueid)");

    $db->exec("CREATE TABLE IF NOT EXISTS numeros_speciaux (
        numero    TEXT PRIMARY KEY,
        label     TEXT NOT NULL,
        categorie TEXT DEFAULT 'system'
    )");

    // Index pour les colonnes fréquemment filtrées
    $db->exec("CREATE INDEX IF NOT EXISTS idx_appels_src       ON appels(src)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_appels_dst       ON appels(dst)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_appels_date      ON appels(date_appel)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_appels_etat_date ON appels(etat, date_appel DESC)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_contacts_nom     ON contacts(nom)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_numeros_numero   ON numeros(numero)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_numeros_cid      ON numeros(contact_id)");

    migrateNumerosTable($db);

    // Numéros spéciaux par défaut
    $stmt = $db->prepare(
        "INSERT OR IGNORE INTO numeros_speciaux (numero, label, categorie) VALUES (?,?,?)"
    );
    foreach ([
        ['**1', 'Répondeur',              'system'],
        ['666', 'Blacklist dernier app.', 'blacklist'],
        ['667', 'Blacklist nº manuel',    'blacklist'],
        ['999', 'Retrait blacklist',      'blacklist'],
        ['101', 'PC Bagu',                'local'],
        ['111', 'PC Bureau',              'local'],
        ['121', 'Smartphone',             'local'],
        ['131', 'Base DECT',              'local'],
    ] as $s) {
        $stmt->execute($s);
    }
}

/** Migration : remplace UNIQUE(numero) par UNIQUE(numero, contact_id). */
function migrateNumerosTable(PDO $db): void {
    if ((int)$db->query("PRAGMA user_version")->fetchColumn() >= 2) return;

    $sql = $db->query(
        "SELECT sql FROM sqlite_master WHERE type='table' AND name='numeros'"
    )->fetchColumn();

    if ($sql && preg_match('/numero\s+TEXT[^,\n]*UNIQUE/i', $sql)) {
        $db->exec("
            BEGIN;
            CREATE TABLE IF NOT EXISTS numeros_new (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                numero     TEXT NOT NULL,
                contact_id INTEGER NOT NULL,
                type       TEXT DEFAULT 'inconnu',
                FOREIGN KEY(contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
                UNIQUE(numero, contact_id)
            );
            INSERT OR IGNORE INTO numeros_new SELECT * FROM numeros;
            DROP TABLE numeros;
            ALTER TABLE numeros_new RENAME TO numeros;
            COMMIT;
        ");
        $db->exec("PRAGMA user_version = 2");
    }
}

/** Normalise un numéro téléphonique : retire les espaces/tirets,
 * remplace +33 ou 0033 par 0, retourne les 9 derniers chiffres.
 * Si le numéro est entièrement composé de [0-9*#s] et contient au moins un caractère spécial
 * (*, # ou s), il est retourné tel quel : c'est un numéro spécial Asterisk. */
function normaliserNumero(string $num): string {
    $num = trim($num);
    if ($num === '') return '';
    // Numéro spécial : composé uniquement de chiffres, *, #, s — ET contient au moins un caractère non chiffre
    if (preg_match('/^[0-9s*#]+$/', $num) && preg_match('/[*#s]/', $num)) return $num;
    $num = preg_replace('/^\+33/', '0', $num);   // +33XXXXXXXXX → 0XXXXXXXXX
    $num = preg_replace('/^0033/',  '0', $num);  // 0033XXXXXXXXX → 0XXXXXXXXX
    $num = preg_replace('/\D/', '', $num);
    return $num ? substr($num, -9) : '';
}

/** Cherche un contact par numéro normalisé. Retourne ['noms'=>[...], 'type'=>...] ou null. */
function chercherContactParNumero(PDO $db, string $numNorm): ?array {
    if (!$numNorm) return null;
    $stmt = $db->prepare("
        SELECT c.nom, n.type
        FROM numeros n
        JOIN contacts c ON c.id = n.contact_id
        WHERE n.numero = ?
    ");
    $stmt->execute([$numNorm]);
    $rows = $stmt->fetchAll();
    if (!$rows) return null;
    return ['noms' => array_column($rows, 'nom'), 'type' => $rows[0]['type']];
}

/** Retourne le libellé d'un numéro spécial ou d'un préfixe de redirection, ou null si inconnu.
 * Gère les préfixes ## (redirection directe) et #* (transfert après mise en relation). */
function getNumeroSpecial(PDO $db, string $raw): ?string {
	$raw = trim($raw);
	if (!preg_match('/^[0-9s*#]+$/', $raw)) return null;

	// Préfixe ## : redirection directe vers l'extension composée (ex : ##101)
	if (str_starts_with($raw, '##') && strlen($raw) > 2) {
		return t('index.redirect', ['ext' => substr($raw, 2)]);
	}
	// Préfixe #* : transfert après mise en relation avec l'extension composée (ex : #*101)
	if (str_starts_with($raw, '#*') && strlen($raw) > 2) {
		return t('index.transfert', ['ext' => substr($raw, 2)]);
	}

	$stmt = $db->prepare("SELECT label FROM numeros_speciaux WHERE numero = ?");
	$stmt->execute([$raw]);
	$row = $stmt->fetch();
	if ($row) return $row['label'];

	// Fallback sans zéros initiaux (numéros classiques uniquement, pas */#)
	if (!str_contains($raw, '*') && !str_contains($raw, '#')) {
		$stripped = ltrim($raw, '0');
		if ($stripped !== $raw && $stripped !== '') {
			$stmt->execute([$stripped]);
			$row = $stmt->fetch();
			if ($row) return $row['label'];
		}
	}
	return null;
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
/** Démarre la session sécurisée et génère le token CSRF s'il est absent. */
function csrfStart(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/** Retourne le token CSRF de la session courante (crée la session si nécessaire). */
function csrfToken(): string {
    csrfStart();
    return $_SESSION['csrf_token'];
}

/** Génère le champ HTML hidden contenant le token CSRF pour les formulaires. */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrfToken()) . '">';
}

/** Vérifie le token CSRF d'une requête POST ; termine avec HTTP 403 si invalide. */
function csrfVerify(): void {
    csrfStart();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die(t('common.csrf_error'));
    }
}

// Chargement du système de traduction (doit être après toutes les fonctions)
require_once __DIR__ . '/lang.php';
