#!/usr/bin/env python3
"""
getEpisodes.py

Usage:
  python getEpisodes.py

Purpose:
  Reads selected shows (id, timeSlot) from SQLite table `playlistShows`,
  queries Plex for all episodes in those shows, and populates `playlistEpisodes`.

Environment:
  - .env in project root with:
      PLEX_URL
      PLEX_TOKEN
      PLEX_VERIFY_SSL (optional; default "false")

Exit codes:
  1 -> SQLite error / write failure
  2 -> Missing PLEX_URL or PLEX_TOKEN
  3 -> Plex connection failed
  0 -> Success
"""

import os
import sys
import math
import sqlite3

import requests
from dotenv import load_dotenv
from plexapi.server import PlexServer

# ---------------------------
# Paths & .env loading
# ---------------------------
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV_PATH = os.path.join(ROOT, '.env')
DB_FILE = os.path.join(ROOT, 'database', 'plex_playlist.db')

if not os.path.exists(ENV_PATH):
    print(f"[ERROR] .env not found at {ENV_PATH}", file=sys.stderr)
    sys.exit(2)

load_dotenv(ENV_PATH)

PLEX_URL = os.getenv('PLEX_URL', '').strip()
PLEX_TOKEN = os.getenv('PLEX_TOKEN', '').strip()
PLEX_VERIFY_SSL = os.getenv('PLEX_VERIFY_SSL', 'false').strip().lower() in ('1', 'true', 'yes')

if not PLEX_URL or not PLEX_TOKEN:
    print("[ERROR] Missing PLEX_URL or PLEX_TOKEN in .env", file=sys.stderr)
    sys.exit(2)

# ---------------------------
# Connect to Plex
# ---------------------------
try:
    session = requests.Session()
    session.verify = True if PLEX_VERIFY_SSL else False
    plex = PlexServer(PLEX_URL, PLEX_TOKEN, session=session)
    print("[INFO] Connected to Plex Server.")
except Exception as e:
    print(f"[ERROR] Plex connect failed: {e}", file=sys.stderr)
    sys.exit(3)

# ---------------------------
# Connect to Database
# ---------------------------
try:
    db_conn = sqlite3.connect(DB_FILE)
    cursor = db_conn.cursor()
    print("[INFO] Connected to SQLite DB.")
except sqlite3.Error as e:
    print(f"[ERROR] SQLite connect failed: {e}", file=sys.stderr)
    sys.exit(1)

# Clear the table before refilling
try:
    cursor.execute("DELETE FROM playlistEpisodes")
    db_conn.commit()
    print("[INFO] Cleared playlistEpisodes.")
except sqlite3.Error as e:
    print(f"[ERROR] Could not clear playlistEpisodes: {e}", file=sys.stderr)
    cursor.close()
    db_conn.close()
    sys.exit(1)

# ---------------------------
# Fetch selected shows (ratingKey + timeSlot)
# ---------------------------
cursor.execute("SELECT id, timeSlot FROM playlistShows")
rows = cursor.fetchall()
shows_from_db = {int(rk): ts for rk, ts in rows}
print(f"[INFO] Selected shows: {len(shows_from_db)}")

# ---------------------------
# Gather TV libraries (type == 'show')
# ---------------------------
tv_sections = [s for s in plex.library.sections() if getattr(s, 'type', '') == 'show']
if not tv_sections:
    print("[WARN] No TV Show libraries found.")

matched_shows = 0
total_episodes_processed = 0

for section in tv_sections:
    print(f"[INFO] Processing TV library: {section.title}")
    for show in section.all():
        try:
            rk = int(show.ratingKey)
        except Exception:
            continue

        if rk not in shows_from_db:
            continue

        matched_shows += 1
        slot = shows_from_db[rk]

        for ep in show.episodes():
            try:
                # Duration is stored (rounded up) in minutes
                duration_ms = getattr(ep, 'duration', 0) or 0
                duration_minutes = math.ceil(duration_ms / 60000) if duration_ms else 0

                insert_stmt = ("""
                    INSERT INTO playlistEpisodes
                    (ratingKey, season, episode, releaseDate, duration, summary,
                     watchedStatus, title, episodeTitle, show_id, timeSlot)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """)
                data = (
                    int(ep.ratingKey),
                    getattr(ep, 'parentIndex', None),
                    getattr(ep, 'index', None),
                    getattr(ep, 'originallyAvailableAt', None),
                    duration_minutes,
                    getattr(ep, 'summary', None),
                    bool(getattr(ep, 'viewCount', 0)),
                    getattr(ep, 'grandparentTitle', '') or '',
                    getattr(ep, 'title', '') or '',
                    rk,
                    slot
                )
                cursor.execute(insert_stmt, data)
                db_conn.commit()
                total_episodes_processed += 1
            except sqlite3.Error as e:
                print(f"[WARN] Insert failed for episode {getattr(ep, 'title', '<unknown>')}: {e}", file=sys.stderr)

print(f"[SUCCESS] DB update complete. {matched_shows} shows matched. {total_episodes_processed} episodes processed.")

cursor.close()
db_conn.close()
sys.exit(0)
