#!/usr/bin/env python3
"""
populateShows.py

Usage:
  python populateShows.py

Purpose:
  Creates/initializes the SQLite DB and populates table `allShows` with every
  TV show in your Plex libraries (stores ratingKey, title, total_episodes).

Environment:
  - .env in project root with:
      PLEX_URL
      PLEX_TOKEN
      PLEX_VERIFY_SSL (optional; default "false")

Exit codes:
  (script prints warnings and raises on missing PLEX_*; exits 1 on unhandled errors)
"""

import os
import sys
import sqlite3
from pathlib import Path

import requests
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

PLEX_URL = os.getenv("PLEX_URL", "").strip()
PLEX_TOKEN = os.getenv("PLEX_TOKEN", "").strip()
PLEX_VERIFY_SSL = os.getenv('PLEX_VERIFY_SSL', 'false').strip().lower() in ('1', 'true', 'yes')

if not PLEX_URL or not PLEX_TOKEN:
    raise RuntimeError(f"Missing PLEX_URL or PLEX_TOKEN. Checked: {ENV_PATH}")

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
    uid = gid = None  # may not exist in some containers

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
    if uid is not None and gid is not None:
        os.chown(DB_PATH, uid, gid)
except Exception:
    pass

# ----- Plex connection (respect PLEX_VERIFY_SSL) -----
try:
    session = requests.Session()
    session.verify = True if PLEX_VERIFY_SSL else False
    plex = PlexServer(PLEX_URL, PLEX_TOKEN, session=session)
    print("Connected to Plex Server.")
except Exception as e:
    print(f"[ERROR] Plex connect failed: {e}", file=sys.stderr)
    sys.exit(1)

# ----- Populate shows -----
try:
    shows = plex.library.search(libtype="show")
except Exception as e:
    print(f"[ERROR] Plex library query failed: {e}", file=sys.stderr)
    sys.exit(1)

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
            print(f"[WARN] Skip {getattr(show, 'title', '<unknown>')}: {e}", file=sys.stderr)
    conn.commit()

print(f"[SUCCESS] Database update complete. Wrote {DB_PATH}")
