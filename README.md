# 📞 Journal d'appels Asterisk / Asterisk Call Journal

> Interface web pour visualiser et gérer le journal des appels d'un IPBX Asterisk,
> avec synchronisation SFTP automatique du CDR (Call Detail Records).

> Web interface to view and manage the call log of an Asterisk PBX,
> with automatic SFTP synchronisation of CDR (Call Detail Records).

---

## 🇫🇷 Documentation française

### Présentation

Ce projet est une interface web légère permettant de consulter le journal des appels
téléphoniques d'un IPBX Asterisk. Les données CDR sont récupérées via SFTP depuis le
serveur Asterisk, stockées localement dans une base SQLite, et affichées dans une
interface responsive avec scroll infini, filtres et recherche par numéro.

**Fonctionnalités principales :**

- Consultation du journal des appels avec scroll infini (virtual windowing)
- Synchronisation SFTP du CDR Asterisk (manuelle ou par tâche planifiée)
- Identification des appelants via un annuaire de contacts (import CSV)
- Gestion des numéros spéciaux Asterisk (répondeur, blacklist, redirections…)
- Interface multilingue (français / anglais, détection automatique via `Accept-Language`)
- Authentification multi-utilisateurs avec rôles admin / user
- Protection CSRF, anti-brute-force, sessions sécurisées (Argon2id)

### Architecture

```
┌─────────────────────┐     SFTP      ┌──────────────────────┐
│   Serveur Asterisk  │ ──────────── ▶│  sync_calls.py       │
│   (CDR CSV distant) │               │  (script Python)     │
└─────────────────────┘               └──────────┬───────────┘
                                                 │ SQLite (journal.db)
                                      ┌──────────▼───────────┐
                                      │  Interface PHP        │
                                      │  Apache 2.4 / PHP 8.4│
                                      └──────────────────────┘
```

| Composant        | Technologie                |
|------------------|----------------------------|
| Backend          | PHP 8.4, SQLite (PDO)      |
| Synchronisation  | Python 3.12, Paramiko, SSH |
| Serveur web      | Apache 2.4 + mod_rewrite   |
| Authentification | Sessions PHP, Argon2id     |
| Frontend         | HTML/CSS/JS vanilla        |

### Prérequis

- Apache 2.4 avec `mod_rewrite` activé
- PHP 8.4+ avec extensions : `pdo_sqlite`, `fileinfo`, `mbstring`, `openssl`
- Python 3.12+ avec packages : `paramiko`, `python-dotenv`
- Accès SFTP au serveur Asterisk distant

### Installation

#### 1. Cloner le dépôt

```bash
git clone https://github.com/Bagu/journal-appels.git /var/www/journal
```

#### 2. Créer le fichier de configuration `.env`

Ce fichier doit être placé **hors du répertoire web** (ex : `/etc/secrets/journal.env`
sous Linux, `C:\secrets\journal.env` sous Windows).

```ini
DEBIAN_IP=192.168.1.10               # Adresse IP du serveur Asterisk
SSH_USER=asterisk                     # Utilisateur SSH
SSH_PASS=mot_de_passe_fort            # Mot de passe SSH
REMOTE_CSV=/var/log/asterisk/cdr.csv  # Chemin du fichier CDR sur le serveur distant
PYTHON_EXE=/usr/bin/python3           # Chemin de l'interpréteur Python (optionnel)
```

> ⚠️ Ce fichier contient des credentials. Ne jamais le versionner.

#### 3. Générer le fichier `known_hosts`

Le fichier `known_hosts` enregistre l'empreinte SSH du serveur Asterisk.
Il est obligatoire : le script refuse toute connexion vers un hôte non reconnu (`RejectPolicy`).

**Linux / macOS**

```bash
ssh-keyscan -H <IP_ASTERISK> >> /var/www/journal/known_hosts
```

**Windows** — OpenSSH est inclus depuis Windows 10 (1809) et Windows Server 2019.
Dans PowerShell ou CMD :

```powershell
ssh-keyscan -H <IP_ASTERISK> >> C:\chemin\vers\journal\known_hosts
```

Si la commande `ssh-keyscan` est introuvable, activer le client OpenSSH :
**Paramètres → Applications → Fonctionnalités facultatives → Ajouter → Client OpenSSH**

> Le fichier `known_hosts` doit être placé à la racine du projet.

#### 4. Mettre à jour le chemin `.env` dans `sync.php` et `sync_calls.py`

Remplacer le chemin par défaut par le chemin réel du fichier `.env` dans :

- `sync.php` → variable `$envPath`
- `sync_calls.py` → constante `_ENV_PATH`

#### 5. Créer le premier compte administrateur

```bash
php manage_users.php add <nom> <mot_de_passe> admin
```

#### 6. Permissions des fichiers (Linux uniquement)

```bash
# Le répertoire doit être accessible en écriture par Apache (bases SQLite)
chown -R www-data:www-data /var/www/journal
chmod 750 /var/www/journal
chmod 640 /var/www/journal/*.php
```

### Configuration du mode de synchronisation

Dans `config.php`, la constante `SYNC_MODE` contrôle le comportement de l'auto-refresh :

| Valeur   | Comportement                                                                          |
|----------|---------------------------------------------------------------------------------------|
| `'web'`  | Le script Python est lancé via le bouton **Synchroniser** (défaut)                   |
| `'cron'` | Le script Python est lancé par une tâche cron externe ; l'interface vérifie uniquement si de nouveaux appels sont disponibles (intervalle 30 s) |

**Exemple de crontab (mode `'cron'`, Linux) :**

```cron
*/5 * * * * www-data /usr/bin/python3 /var/www/journal/sync_calls.py >> /var/log/journal-appels.log 2>&1
```

**Exemple de tâche planifiée (mode `'cron'`, Windows) :**

```powershell
schtasks /create /tn "JournalAppels" /tr "python C:\chemin\vers\journal\sync_calls.py" /sc minute /mo 5
```

### Gestion des utilisateurs

```bash
# Créer un utilisateur
php manage_users.php add <nom> <mdp> [admin|user]

# Réinitialiser un mot de passe
php manage_users.php reset-password <nom> <nouveau_mdp>

# Lister les utilisateurs
php manage_users.php list
```

### Notes de sécurité

- Les mots de passe sont hachés avec **Argon2id** (bcrypt en fallback)
- Les sessions sont sécurisées : `HttpOnly`, `SameSite=Lax`, `Secure` (si HTTPS)
- Toutes les requêtes POST sont protégées par un token CSRF
- Le brute-force est limité à 5 tentatives / 15 minutes, par utilisateur ET par IP
- Les fichiers sensibles (`.db`, `.env`, `.py`, `.csv`, `known_hosts`) sont bloqués par Apache
- L'interface est marquée `noindex, nofollow` (non indexée par les moteurs de recherche)

---

## 🇬🇧 English Documentation

### Overview

A lightweight web interface for browsing the call log of an Asterisk PBX.
CDR data is fetched via SFTP from the Asterisk server, stored locally in a SQLite
database, and displayed in a responsive infinite-scroll interface with filters
and phone number search.

**Key features:**

- Infinite-scroll call log with virtual windowing (DOM off-screen unloading)
- Asterisk CDR SFTP synchronisation (manual button or scheduled cron job)
- Caller identification via a contact directory (CSV import supported)
- Asterisk special numbers management (voicemail, blacklist, call transfers…)
- Multilingual UI (French / English, auto-detected via `Accept-Language`)
- Multi-user authentication with admin / user roles
- CSRF protection, brute-force lockout, secure sessions (Argon2id)

### Requirements

- Apache 2.4 with `mod_rewrite` enabled
- PHP 8.4+ with extensions: `pdo_sqlite`, `fileinfo`, `mbstring`, `openssl`
- Python 3.12+ with packages: `paramiko`, `python-dotenv`
- SFTP access to the remote Asterisk server

### Installation

#### 1. Clone the repository

```bash
git clone https://github.com/Bagu/asterisk-call-journal.git /var/www/journal
```

#### 2. Create the `.env` configuration file

Place this file **outside the web root** (e.g. `/etc/secrets/journal.env` on Linux,
`C:\secrets\journal.env` on Windows).

```ini
DEBIAN_IP=192.168.1.10               # Asterisk server IP address
SSH_USER=asterisk                     # SSH username
SSH_PASS=strong_password              # SSH password
REMOTE_CSV=/var/log/asterisk/cdr.csv  # Path to the CDR file on the remote server
PYTHON_EXE=/usr/bin/python3           # Python interpreter path (optional)
```

> ⚠️ This file contains credentials. Never commit it.

#### 3. Generate the `known_hosts` file

The `known_hosts` file stores the Asterisk server's SSH fingerprint.
It is required: the script refuses any connection to an unrecognised host (`RejectPolicy`).

**Linux / macOS**

```bash
ssh-keyscan -H <ASTERISK_IP> >> /var/www/journal/known_hosts
```

**Windows** — OpenSSH is bundled since Windows 10 (1809) and Windows Server 2019.
In PowerShell or CMD:

```powershell
ssh-keyscan -H <ASTERISK_IP> >> C:\path\to\journal\known_hosts
```

If `ssh-keyscan` is not found, enable the OpenSSH client:
**Settings → Apps → Optional features → Add a feature → OpenSSH Client**

> Place `known_hosts` at the project root.

#### 4. Update the `.env` path in `sync.php` and `sync_calls.py`

Replace the default path with the actual path to your `.env` file in:

- `sync.php` → `$envPath` variable
- `sync_calls.py` → `_ENV_PATH` constant

#### 5. Create the first admin account

```bash
php manage_users.php add <name> <password> admin
```

#### 6. File permissions (Linux only)

```bash
chown -R www-data:www-data /var/www/journal
chmod 750 /var/www/journal
chmod 640 /var/www/journal/*.php
```

### Sync Mode

Set `SYNC_MODE` in `config.php`:

| Value    | Behaviour                                                                              |
|----------|----------------------------------------------------------------------------------------|
| `'web'`  | Python script is triggered by the **Sync** button (default)                           |
| `'cron'` | Python script runs via an external scheduler; the UI only polls for new calls every 30 s |

**Example crontab (`'cron'` mode, Linux):**

```cron
*/5 * * * * www-data /usr/bin/python3 /var/www/journal/sync_calls.py >> /var/log/journal-appels.log 2>&1
```

**Example scheduled task (`'cron'` mode, Windows):**

```powershell
schtasks /create /tn "JournalAppels" /tr "python C:\path\to\journal\sync_calls.py" /sc minute /mo 5
```

### User Management

```bash
# Create a user
php manage_users.php add <name> <password> [admin|user]

# Reset a password
php manage_users.php reset-password <name> <new_password>

# List users
php manage_users.php list
```

### Security Notes

- Passwords hashed with **Argon2id** (bcrypt fallback)
- Secure sessions: `HttpOnly`, `SameSite=Lax`, `Secure` (when HTTPS)
- All POST requests protected with CSRF tokens
- Brute-force limited to 5 attempts / 15 minutes, per username AND per IP
- Sensitive files (`.db`, `.env`, `.py`, `.csv`, `known_hosts`) blocked by Apache
- Pages marked `noindex, nofollow`
