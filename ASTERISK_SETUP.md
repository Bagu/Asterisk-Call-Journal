# Asterisk Configuration / Configuration Asterisk

---

## 🇬🇧 English

### Overview

This project reads an Asterisk CSV CDR file (`appels.csv`) produced by the CSV backend. You must configure Asterisk to generate **exactly 9 columns in a specific order**, otherwise the Python sync script (`sync_calls.py`) and the PHP display layer will not parse the data correctly.

### 1. Enable the CSV CDR backend

**File:** `/etc/asterisk/cdr.conf`

```ini
[general]
enable=yes
unanswered=yes          ; REQUIRED: missed calls are logged (NO ANSWER)
congestion=no

[csv]
usegmtime=no            ; local time (matches the PHP UI timezone)
loguniqueid=yes         ; REQUIRED: uniqueid is column 9 and used as UNIQUE key
loguserfield=yes        ; REQUIRED: userfield carries the real extension on transfers
accountlogs=no
```

### 2. Define the custom CSV column order

**File:** `/etc/asterisk/cdr_custom.conf`

```ini
[mappings]
appels.csv => ${CSV_QUOTE(${CDR(start)})},${CSV_QUOTE(${CDR(src)})},${CSV_QUOTE(${CDR(dst)})},${CSV_QUOTE(${CDR(duration)})},${CSV_QUOTE(${CDR(billsec)})},${CSV_QUOTE(${CDR(disposition)})},${CSV_QUOTE(${CDR(clid)})},${CSV_QUOTE(${CDR(userfield)})},${CSV_QUOTE(${CDR(uniqueid)})}
```

Column order expected by `sync_calls.py`:

| # | Field | Usage |
|---|---|---|
| 0 | `start` | Call date (`YYYY-MM-DD HH:MM:SS`) |
| 1 | `src` | Caller number |
| 2 | `dst` | Dialed number (may be `s` on transfers) |
| 3 | `duration` | Total duration including ringing |
| 4 | `billsec` | Effective talk time (stored in DB) |
| 5 | `disposition` | `ANSWERED` / `NO ANSWER` |
| 6 | `clid` | Caller ID string |
| 7 | `userfield` | Real extension on transfers (see below) |
| 8 | `uniqueid` | Unique call ID, used as SQLite UNIQUE index |

The file is written to `/var/log/asterisk/cdr-custom/appels.csv` by default. The path must match the `REMOTE_CSV` environment variable used by the Python sync script.

### 3. Reload Asterisk modules

```
asterisk -rx "module reload cdr_csv.so"
asterisk -rx "module reload cdr_custom.so"
```

### 4. Masked / anonymous callers

The sync script replaces these `src` values with the literal label `Masqué`:

- `813`, `HT813` (Grandstream HT813 ATA default caller IDs)
- `0000000000`
- empty string

Adapt the list in `sync_calls.py` if your hardware emits different placeholders.

### 5. Transfers — the `s` extension

When Asterisk bridges an incoming call to a local extension through a transfer context, the `dst` column is often the system extension `s`. To let the UI show the real destination, populate `userfield` in your dialplan with the target extension, e.g.:

```
exten => _X.,n,Set(CDR(userfield)=;${EXTEN})
```

The sync script strips the leading `;` and, if `dst == 's'`, replaces it with the value of `userfield`.

### 6. Special numbers (handled by the web UI)

Special numbers are stored in the `numeros_speciaux` SQLite table. Default entries are created automatically on first run (`config.php` → `initDB()`):

| Number | Label | Category |
|---|---|---|
| `**1` | Free operator voicemail (French ISP **Free** — dial `**1` from a Freebox line to reach your voicemail) | `system` |
| `666` | Blacklist the last caller | `blacklist` |
| `667` | Blacklist a number entered on the keypad | `blacklist` |
| `999` | Remove a number from the blacklist | `blacklist` |
| `101` | Internal extension — PC Bagu | `local` |
| `111` | Internal extension — Office PC | `local` |
| `121` | Internal extension — Smartphone | `local` |
| `131` | Internal extension — DECT base station | `local` |

Admins can add/edit/remove entries via `numeros_speciaux.php`. Valid characters for a special number: digits, `*`, `#`, and lowercase `s`.

**Dynamic prefixes** (detected at runtime, no DB entry needed):

- `##<ext>` — **direct forwarding** to extension `<ext>` (example: `##101`)
- `#*<ext>` — **attended transfer** to extension `<ext>` after a consultation call (example: `#*121`)

Both prefixes must be implemented in your Asterisk dialplan. The web UI only decodes and labels them for display.

### 7. Internal extensions

Internal extensions are `101`, `111`, `121`, `131`. They are recognized as local postes (badge category `local`) and used by the UI to determine call direction: a call is **outbound** when `src` length ≤ 3 characters (i.e. a short internal extension).

### 8. SFTP access

The Python script connects to the Asterisk server over SFTP using **password authentication only** (agent and local keys disabled) and validates the host via a `known_hosts` file sitting next to the script. Required environment variables (loaded from `E:\secrets\journal.env` by default):

```
DEBIAN_IP=<asterisk.server.ip>
SSH_USER=<linux-user>
SSH_PASS=<password>
REMOTE_CSV=/var/log/asterisk/cdr-custom/appels.csv
```

Generate `known_hosts` once:

```
ssh-keyscan -H <asterisk.server.ip> > known_hosts
```

Records older than 365 days are purged from both the remote CSV and the local SQLite database.

---

## 🇫🇷 Français

### Vue d'ensemble

Le projet lit un fichier CSV CDR produit par Asterisk (`appels.csv`) via le backend CSV. Asterisk doit être configuré pour générer **exactement 9 colonnes dans un ordre précis**, faute de quoi le script Python (`sync_calls.py`) et l'affichage PHP ne fonctionneront pas correctement.

### 1. Activer le backend CSV

**Fichier :** `/etc/asterisk/cdr.conf`

```ini
[general]
enable=yes
unanswered=yes          ; OBLIGATOIRE : journalise les appels manqués (NO ANSWER)
congestion=no

[csv]
usegmtime=no            ; heure locale (cohérent avec le fuseau de l'UI PHP)
loguniqueid=yes         ; OBLIGATOIRE : uniqueid est la colonne 9 et sert de clé UNIQUE
loguserfield=yes        ; OBLIGATOIRE : userfield contient le vrai poste lors d'un transfert
accountlogs=no
```

### 2. Définir l'ordre des colonnes CSV

**Fichier :** `/etc/asterisk/cdr_custom.conf`

```ini
[mappings]
appels.csv => ${CSV_QUOTE(${CDR(start)})},${CSV_QUOTE(${CDR(src)})},${CSV_QUOTE(${CDR(dst)})},${CSV_QUOTE(${CDR(duration)})},${CSV_QUOTE(${CDR(billsec)})},${CSV_QUOTE(${CDR(disposition)})},${CSV_QUOTE(${CDR(clid)})},${CSV_QUOTE(${CDR(userfield)})},${CSV_QUOTE(${CDR(uniqueid)})}
```

Ordre attendu par `sync_calls.py` :

| # | Champ | Usage |
|---|---|---|
| 0 | `start` | Date de l'appel (`AAAA-MM-JJ HH:MM:SS`) |
| 1 | `src` | Numéro appelant |
| 2 | `dst` | Numéro composé (peut valoir `s` sur un transfert) |
| 3 | `duration` | Durée totale incluant la sonnerie |
| 4 | `billsec` | Durée effective de conversation (stockée en base) |
| 5 | `disposition` | `ANSWERED` / `NO ANSWER` |
| 6 | `clid` | Caller ID |
| 7 | `userfield` | Vrai poste lors d'un transfert (voir ci-dessous) |
| 8 | `uniqueid` | Identifiant unique de l'appel, utilisé comme index UNIQUE SQLite |

Par défaut, le fichier est écrit dans `/var/log/asterisk/cdr-custom/appels.csv`. Ce chemin doit correspondre à la variable d'environnement `REMOTE_CSV` du script Python.

### 3. Recharger les modules Asterisk

```
asterisk -rx "module reload cdr_csv.so"
asterisk -rx "module reload cdr_custom.so"
```

### 4. Numéros masqués / anonymes

Le script de synchronisation remplace ces valeurs de `src` par le libellé `Masqué` :

- `813`, `HT813` (caller IDs par défaut des ATA Grandstream HT813)
- `0000000000`
- chaîne vide

Adaptez la liste dans `sync_calls.py` si votre matériel émet d'autres valeurs par défaut.

### 5. Transferts — l'extension `s`

Lorsqu'Asterisk met en relation un appel entrant avec un poste local via un contexte de transfert, la colonne `dst` vaut souvent l'extension système `s`. Pour que l'UI affiche le vrai destinataire, renseignez `userfield` dans le dialplan avec l'extension cible, par exemple :

```
exten => _X.,n,Set(CDR(userfield)=;${EXTEN})
```

Le script Python retire le `;` initial et, si `dst == 's'`, remplace `dst` par la valeur de `userfield`.

### 6. Numéros spéciaux (gérés par l'interface web)

Les numéros spéciaux sont stockés dans la table SQLite `numeros_speciaux`. Les entrées par défaut sont créées automatiquement au premier lancement (`config.php` → `initDB()`) :

| Numéro | Libellé | Catégorie |
|---|---|---|
| `**1` | Répondeur de l'opérateur français **Free** (depuis une ligne Freebox, composer `**1` pour accéder à la messagerie vocale) | `system` |
| `666` | Blacklister le dernier appelant | `blacklist` |
| `667` | Blacklister un numéro saisi au clavier | `blacklist` |
| `999` | Retirer un numéro de la blacklist | `blacklist` |
| `101` | Poste interne — PC Bagu | `local` |
| `111` | Poste interne — PC Bureau | `local` |
| `121` | Poste interne — Smartphone | `local` |
| `131` | Poste interne — Base DECT | `local` |

Les administrateurs peuvent ajouter/modifier/supprimer des entrées via `numeros_speciaux.php`. Caractères valides pour un numéro spécial : chiffres, `*`, `#`, et `s` minuscule.

**Préfixes dynamiques** (détectés à la volée, aucune entrée en base requise) :

- `##<poste>` — **renvoi direct** vers le poste `<poste>` (ex. : `##101`)
- `#*<poste>` — **transfert supervisé** vers le poste `<poste>` après mise en relation (ex. : `#*121`)

Ces deux préfixes doivent être implémentés dans votre dialplan Asterisk. L'UI web se contente de les décoder et de les étiqueter à l'affichage.

### 7. Postes internes

Les postes internes sont `101`, `111`, `121`, `131`. Ils sont reconnus comme locaux (catégorie `local`) et servent à déterminer le sens de l'appel : un appel est **sortant** si `src` fait ≤ 3 caractères (donc une extension courte interne).

### 8. Accès SFTP

Le script Python se connecte au serveur Asterisk en SFTP en **authentification par mot de passe uniquement** (agent et clés locales désactivés) et valide l'hôte via un fichier `known_hosts` placé à côté du script. Variables d'environnement requises (chargées depuis `E:\secrets\journal.env` par défaut) :

```
DEBIAN_IP=<ip.serveur.asterisk>
SSH_USER=<utilisateur-linux>
SSH_PASS=<mot-de-passe>
REMOTE_CSV=/var/log/asterisk/cdr-custom/appels.csv
```

Générez le fichier `known_hosts` une fois :

```
ssh-keyscan -H <ip.serveur.asterisk> > known_hosts
```

Les enregistrements de plus de 365 jours sont purgés à la fois du CSV distant et de la base SQLite locale.
