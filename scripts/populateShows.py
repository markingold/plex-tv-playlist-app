#!/usr/bin/env python3
import os
import sys
import sqlite3
from pathlib import Path
from dotenv import load_dotenv
from plexapi.server import PlexServer

# ----- Paths & env (.env sits next to web root) -----
APP_ROOT = Path(__file__).resolve().parents[1]               # /var/www/html
ENV_PATH = APP_ROOT / ".env"
DB_DIR = APP_ROOT / "database"
DB_PATH = DB_DIR / "plex_playlist.db"

# Load .env if present (don't crash if missing/unreadable)
try:
    if ENV_PATH.exists():
        loaded = load_dotenv(ENV_PATH)
        if not loaded:
            print(f"Warning: .env not loaded from {ENV_PATH}. Using environment variables only.", file=sys.stderr)
    else:
        print(f"Warning: {ENV_PATH} not found. Using environment variables only.", file=sys.stderr)
except PermissionError as e:
    print(f"Warning: cannot read {ENV_PATH} ({e}). Using environment variables only.", file=sys.stderr)

PLEX_URL = os.getenv("PLEX_URL")
PLEX_TOKEN = os.getenv("PLEX_TOKEN")

# Create DB dir and make it group-writable for www-data
os.umask(0o0002)  # default new files/dirs -> group-writable
DB_DIR.mkdir(parents=True, exist_ok=True)

# Best-effort chown to www-data if available
try:
    import pwd, grp
    uid = pwd.getpwnam("www-data").pw_uid
    gid = grp.getgrnam("www-data").gr_gid
    os.chown(DB_DIR, uid, gid)
except Exception:
    pass  # not fatal in containers without passwd entries

# ----- Init database -----
create_tables_sql = """
CREATE TABLE IF NOT EXISTS allShows (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    total_episodes INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS playlistShows (
    id INTEGER PRIMARY KEY,
    title TEXT NOT NULL,
    total_episodes INTEGER DEFAULT 0,
    timeSlot INTEGER
);
CREATE TABLE IF NOT EXISTS playlistEpisodes (
    ratingKey INTEGER PRIMARY KEY,
    season INTEGER,
    episode INTEGER,
    releaseDate TEXT,
    duration INTEGER,
    summary TEXT,
    watchedStatus BOOLEAN,
    title TEXT,
    episodeTitle TEXT,
    show_id INTEGER,
    timeSlot INTEGER
);
"""

with sqlite3.connect(DB_PATH) as conn:
    conn.executescript(create_tables_sql)
    conn.commit()

# Make DB file group-writable and owned by www-data if possible
try:
    os.chmod(DB_PATH, 0o664)
    os.chown(DB_PATH, uid, gid)  # uses uid/gid from above if they exist
except Exception:
    pass

# ----- Plex connection -----
if not PLEX_URL or not PLEX_TOKEN:
    raise RuntimeError(f"Missing PLEX_URL or PLEX_TOKEN. Checked: {ENV_PATH}")

plex = PlexServer(PLEX_URL, PLEX_TOKEN)
print("Connected to Plex Server.")

# ----- Populate shows -----
shows = plex.library.search(libtype="show")
with sqlite3.connect(DB_PATH) as conn:
    cur = conn.cursor()
    for show in shows:
        try:
            total = getattr(show, "leafCount", None)
            if total is None:
                total = len(show.episodes())
            cur.execute(
                "INSERT OR REPLACE INTO allShows (id, title, total_episodes) VALUES (?, ?, ?)",
                (int(show.ratingKey), show.title, int(total or 0)),
            )
        except Exception as e:
            print(f"Skip {getattr(show, 'title', '<unknown>')}: {e}", file=sys.stderr)
    conn.commit()

print(f"Database update complete. Wrote {DB_PATH}")
