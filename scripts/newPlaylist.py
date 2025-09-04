#!/usr/bin/env python3
"""
newPlaylist.py

Usage:
  python newPlaylist.py

Purpose:
  Creates a new (initially empty) Plex playlist and prints JSON:
    {"ok": true, "ratingKey": 12345, "title": "TV Playlist 2025-08-27 13:45:02"}

Notes:
  - Plex requires items at creation time. We seed with one episode, then clear it.
  - generatePlaylist.py will fill the playlist afterward.

Environment:
  - .env in project root with:
      PLEX_URL
      PLEX_TOKEN
      PLEX_VERIFY_SSL (optional; default "false")

Exit codes:
  2 -> .env missing or PLEX_* missing
  3 -> Plex connection failed
  4 -> DB missing / query failed
  5 -> No seed episode found
  6 -> Couldn’t fetch seed item
  7 -> Playlist creation failed
  0 -> Success
"""

import os
import sys
import json
import sqlite3
from datetime import datetime
from typing import Optional

import requests
from dotenv import load_dotenv
from plexapi.server import PlexServer
from urllib.parse import urlparse, urlunparse

# ----------------------
# Paths & environment
# ----------------------
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV_PATH = os.path.join(ROOT, '.env')
DB_PATH = os.path.join(ROOT, 'database', 'plex_playlist.db')

def jerr(msg: str, code: int) -> None:
    """Print a JSON error and exit with code."""
    print(json.dumps({"ok": False, "error": msg}))
    sys.exit(code)

if not os.path.exists(ENV_PATH):
    jerr(f".env not found at {ENV_PATH}", 2)

load_dotenv(ENV_PATH, override=True)

PLEX_URL = os.getenv('PLEX_URL', '').strip()
PLEX_TOKEN = os.getenv('PLEX_TOKEN', '').strip()
PLEX_VERIFY_SSL = os.getenv('PLEX_VERIFY_SSL', 'false').strip().lower() in ('1', 'true', 'yes')

if not PLEX_URL or not PLEX_TOKEN:
    jerr("Missing PLEX_URL or PLEX_TOKEN", 2)

def remap_localhost_for_container(url: str) -> str:
    """If URL is localhost/127.0.0.1, map to host.docker.internal (useful when Plex runs on the host)."""
    try:
        u = urlparse(url or '')
        host = (u.hostname or '').lower()
        if host in ('localhost', '127.0.0.1'):
            scheme = (u.scheme or 'http')
            port = u.port or (443 if scheme == 'https' else 32400)
            netloc = f"host.docker.internal:{port}"
            return urlunparse((scheme, netloc, u.path or '', u.params or '', u.query or '', u.fragment or ''))
    except Exception:
        pass
    return url

# Remap if needed
PLEX_URL = remap_localhost_for_container(PLEX_URL)

# ----------------------
# Connect to Plex
# ----------------------
try:
    session = requests.Session()
    session.verify = True if PLEX_VERIFY_SSL else False  # disable verification for plex.direct/self-signed if false
    plex = PlexServer(PLEX_URL, PLEX_TOKEN, session=session)
except Exception as e:
    jerr(f"Plex connect failed: {e}", 3)

# ----------------------
# Get a seed episode
# ----------------------
seed_key: Optional[int] = None

if not os.path.exists(DB_PATH):
    jerr(f"DB not found at {DB_PATH}", 4)

try:
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("""
        SELECT ratingKey
        FROM playlistEpisodes
        ORDER BY timeSlot, show_id, season, episode
        LIMIT 1
    """)
    row = cur.fetchone()
    seed_key = int(row[0]) if row else None
except Exception as e:
    jerr(f"DB query failed: {e}", 4)
finally:
    try:
        cur.close()
        conn.close()
    except Exception:
        pass

# Fallback: ask Plex for any episode if DB is empty
if seed_key is None:
    try:
        eps = plex.library.search(libtype='episode')
        if eps:
            try:
                seed_key = int(eps[0].ratingKey)
            except Exception:
                seed_key = None
    except Exception:
        seed_key = None

if seed_key is None:
    jerr("No episode found to seed playlist creation.", 5)

# ----------------------
# Create the playlist
# ----------------------
now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
playlist_name = f"TV Playlist {now}"

try:
    seed_item = plex.fetchItem(seed_key)
except Exception as e:
    jerr(f"Failed to fetch seed item {seed_key}: {e}", 6)

try:
    pl = plex.createPlaylist(title=playlist_name, items=[seed_item])

    # Clear seed so it's empty for the real fill step later
    try:
        items = pl.items()
        if items:
            pl.removeItems(items)
    except Exception:
        # Not fatal — playlist is created already
        pass

    print(json.dumps({"ok": True, "ratingKey": int(pl.ratingKey), "title": pl.title}))
    sys.exit(0)
except Exception as e:
    jerr(f"Playlist creation failed: {e}", 7)
