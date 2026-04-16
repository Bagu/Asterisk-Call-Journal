import csv
import io
import os
import sqlite3
import sys
from datetime import datetime, timedelta

import dotenv
import paramiko

BASE_DIR = os.path.dirname(os.path.abspath(__file__))

# ── Configuration ─────────────────────────────────────────────────────────────
_ENV_PATH = r"E:\secrets\journal.env"
dotenv.load_dotenv(_ENV_PATH)

DB_PATH      = os.path.join(BASE_DIR, 'journal.db')
MASKED_LABEL = "Masqué"

_REQUIRED_ENV = ["DEBIAN_IP", "SSH_USER", "SSH_PASS", "REMOTE_CSV"]
_missing = [k for k in _REQUIRED_ENV if not os.environ.get(k)]
if _missing:
    print(f"[ERREUR] Variables d'environnement manquantes : {', '.join(_missing)}", file=sys.stderr)
    sys.exit(1)

DEBIAN_IP  = os.environ["DEBIAN_IP"]
SSH_USER   = os.environ["SSH_USER"]
SSH_PASS   = os.environ["SSH_PASS"]
REMOTE_CSV = os.environ["REMOTE_CSV"]


def init_db(conn: sqlite3.Connection) -> None:
    """Crée les tables appels et sync_meta si elles n'existent pas."""
    conn.execute("""
        CREATE TABLE IF NOT EXISTS appels (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            date_appel TEXT NOT NULL,
            src        TEXT NOT NULL,
            dst        TEXT NOT NULL,
            duree      INTEGER DEFAULT 0,
            etat       TEXT,
            clid       TEXT,
            uniqueid   TEXT UNIQUE
        )
    """)
    conn.execute("""
        CREATE TABLE IF NOT EXISTS sync_meta (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )
    """)
    conn.commit()

def _parse_date(raw: str) -> datetime:
    """Parse une date Asterisk ; retourne datetime.min en cas d'échec."""
    try:
        return datetime.strptime(raw.strip(), '%Y-%m-%d %H:%M:%S')
    except ValueError:
        return datetime.min


def _get_meta(conn: sqlite3.Connection, key: str) -> str | None:
    """Retourne la valeur d'une clé dans sync_meta, ou None si absente."""
    row = conn.execute("SELECT value FROM sync_meta WHERE key = ?", (key,)).fetchone()
    return row[0] if row else None


def _set_meta(conn: sqlite3.Connection, key: str, value: str) -> None:
    """Insère ou met à jour une clé dans sync_meta."""
    conn.execute(
        "INSERT INTO sync_meta (key, value) VALUES (?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
        (key, value),
    )
    conn.commit()


def fetch_remote_calls(conn: sqlite3.Connection) -> int:
    """
    Télécharge le CSV CDR depuis le serveur Asterisk via SFTP.

    Optimisation : le fichier n'est téléchargé que si son mtime ou sa taille
    ont changé depuis la dernière synchronisation (valeurs stockées dans sync_meta).
    Purge les lignes de plus d'un an côté distant et côté local.

    Returns:
        Nombre de nouveaux appels insérés, ou -1 si le fichier est inchangé.
    Raises:
        FileNotFoundError: si le fichier known_hosts est absent.
        paramiko.SSHException: si la connexion SSH échoue.
    """
    known_hosts = os.path.join(BASE_DIR, 'known_hosts')
    if not os.path.exists(known_hosts):
        raise FileNotFoundError(f"Fichier known_hosts introuvable : {known_hosts}")

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.RejectPolicy())
    ssh.load_host_keys(known_hosts)
    # allow_agent=False, look_for_keys=False : authentification par mot de passe uniquement,
    # sans fallback vers l'agent SSH système ni les clés privées locales.
    ssh.connect(DEBIAN_IP, username=SSH_USER, password=SSH_PASS, timeout=15,
                allow_agent=False, look_for_keys=False)

    inserted = 0
    try:
        cutoff = datetime.now() - timedelta(days=365)

        with ssh.open_sftp() as sftp:
            # ── Vérification mtime + taille avant téléchargement ──────────────
            stat = sftp.stat(REMOTE_CSV)
            csv_mtime = str(stat.st_mtime)
            csv_size  = str(stat.st_size)
            if (csv_mtime == _get_meta(conn, 'csv_mtime') and
                    csv_size == _get_meta(conn, 'csv_size')):
                return -1  # Aucun changement, synchro inutile
            # Lecture en binaire puis décodage : évite l'ambiguïté text/bytes de paramiko en Python 3
            with sftp.open(REMOTE_CSV, 'rb') as f:
                raw = f.read().decode('utf-8', errors='replace')
            all_rows = [r for r in csv.reader(io.StringIO(raw)) if len(r) >= 6]

            recent_rows = [r for r in all_rows if _parse_date(r[0]) >= cutoff]

            # Purge distante si des lignes ont été éliminées
            if len(recent_rows) < len(all_rows):
                buf = io.StringIO()
                csv.writer(buf).writerows(recent_rows)
                # Écriture en binaire explicite pour cohérence avec le mode 'rb'
                with sftp.open(REMOTE_CSV, 'wb') as f:
                    f.write(buf.getvalue().encode('utf-8'))
                print(
                    f"CSV purgé : {len(all_rows) - len(recent_rows)} ligne(s) supprimée(s)",
                    file=sys.stderr,
                )
                # Le fichier distant a été réécrit : mettre à jour mtime/taille
                stat2     = sftp.stat(REMOTE_CSV)
                csv_mtime = str(stat2.st_mtime)
                csv_size  = str(stat2.st_size)

        # Passe 1 : identifie les UniqueID ayant abouti à ANSWERED
        answered_uids: set[str] = {
            r[8].strip()
            for r in recent_rows
            if len(r) > 8 and r[5].strip() == 'ANSWERED' and r[8].strip()
        }

        # Passe 2 : insertion / mise à jour
        cursor = conn.cursor()
        count_before: int = conn.execute("SELECT COUNT(*) FROM appels").fetchone()[0]

        for row in recent_rows:
            uid = row[8].strip() if len(row) > 8 else ''
            if not uid:
                print(f"[WARN] Ligne sans uniqueid ignorée : {row}", file=sys.stderr)
                continue

            src  = row[1].strip()
            if src in ("813", "HT813", "0000000000", ""):
                src = MASKED_LABEL

            dst        = row[2].strip()
            etat       = row[5].strip()
            clid       = row[6].strip() if len(row) > 6 else ''
            userfield  = row[7].strip() if len(row) > 7 else ''
            date_appel = row[0].strip()

            clean_uf = userfield.replace(';', '').strip()
            # Transfert : 's' est l'extension système ; userfield contient le vrai poste
            if dst == 's' and clean_uf:
                dst = clean_uf

            # Ignore NO ANSWER si le même appel a finalement été répondu (via un autre segment)
            if etat == 'NO ANSWER' and uid in answered_uids:
                continue

            try:
                # col[4] = billsec : durée effective de conversation (0 si non répondu)
                # col[3] = duration : inclut le temps de sonnerie
                duree = int(row[4])
            except (ValueError, IndexError):
                duree = 0

            try:
                cursor.execute(
                    """
                    INSERT INTO appels (date_appel, src, dst, duree, etat, clid, uniqueid)
                    VALUES (?,?,?,?,?,?,?)
                    ON CONFLICT(uniqueid) DO UPDATE SET
                        dst  = CASE WHEN excluded.etat = 'ANSWERED' THEN excluded.dst  ELSE appels.dst  END,
                        etat = CASE WHEN excluded.etat = 'ANSWERED' THEN 'ANSWERED'    ELSE appels.etat END,
                        duree = MAX(appels.duree, excluded.duree)
                    """,
                    (date_appel, src, dst, duree, etat, clid, uid),
                )
            except sqlite3.IntegrityError as e:
                print(f"[WARN] Contrainte uid={uid} : {e}", file=sys.stderr)
            except sqlite3.Error as e:
                print(f"[WARN] Erreur SQL uid={uid} : {e}", file=sys.stderr)

        conn.commit()
        count_after = conn.execute("SELECT COUNT(*) FROM appels").fetchone()[0]
        inserted = max(0, count_after - count_before)

        # Purge locale des appels > 1 an : exécutée au plus une fois par jour pour
        # éviter une requête DELETE inutile à chaque synchronisation.
        last_purge = _get_meta(conn, 'last_purge')
        today      = datetime.now().strftime('%Y-%m-%d')
        if last_purge != today:
            conn.execute("DELETE FROM appels WHERE date_appel < datetime('now', '-365 days')")
            conn.commit()
            _set_meta(conn, 'last_purge', today)

        # Sauvegarde de l'état du CSV distant pour la prochaine vérification
        _set_meta(conn, 'csv_mtime', csv_mtime)
        _set_meta(conn, 'csv_size',  csv_size)

    except Exception:
        # Rollback systématique : garantit la cohérence de la DB en cas d'erreur SFTP ou SQL.
        # L'exception est re-levée pour être journalisée et affichée par main().
        conn.rollback()
        raise
    finally:
        ssh.close()

    return inserted


def main() -> None:
    """Point d'entrée : ouvre la DB, initialise le schéma, lance la synchronisation."""
    conn = sqlite3.connect(DB_PATH)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    try:
        init_db(conn)
        n = fetch_remote_calls(conn)
        if n == -1:
            print("CSV inchangé, synchronisation ignorée")
        elif n > 0:
            print(f"OK: {n} nouvel(aux) appel(s)")
        else:
            print("Journal à jour")
    except Exception as e:
        print(f"[ERREUR] {e}", file=sys.stderr)
        sys.exit(1)
    finally:
        conn.close()


if __name__ == "__main__":
    main()
