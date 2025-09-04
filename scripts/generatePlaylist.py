#!/usr/bin/env python3
"""
generatePlaylist.py

Usage:
  python generatePlaylist.py <playlist_ratingKey>

Purpose:
  Clears the specified Plex playlist and re-populates it in a round-robin order
  using episodes stored in SQLite (table: playlistEpisodes), grouped by timeSlot.

Environment:
  - .env in project root with:
      PLEX_URL
      PLEX_TOKEN
      PLEX_VERIFY_SSL (optional; default "false")

Requirements:
  - Tables populated by populateShows.py and getEpisodes.py

Exit codes:
  2 -> .env missing or PLEX_* missing
  3 -> Plex connection failed
  4 -> Playlist fetch failed or not a playlist
  5 -> SQLite DB missing or cannot open
  6 -> Failed to clear playlist
  7 -> Failed to add items
  0 -> Success
"""

import os
import sys
import sqlite3
import argparse
from typing import Dict, List, Iterable
from urllib.parse import urlparse, urlunparse

import requests
from dotenv import load_dotenv
from plexapi.server import PlexServer
from plexapi.playlist import Playlist

# ---------------------------
# Paths & .env loading
# ---------------------------
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV_PATH = os.path.join(ROOT, '.env')
DB_PATH = os.path.join(ROOT, 'database', 'plex_playlist.db')

if not os.path.exists(ENV_PATH):
    print(f"[ERROR] .env not found at {ENV_PATH}", file=sys.stderr)
    sys.exit(2)

load_dotenv(ENV_PATH, override=True)

PLEX_URL = os.getenv('PLEX_URL', '').strip()
PLEX_TOKEN = os.getenv('PLEX_TOKEN', '').strip()
PLEX_VERIFY_SSL = os.getenv('PLEX_VERIFY_SSL', 'false').strip().lower() in ('1', 'true', 'yes')

if not PLEX_URL or not PLEX_TOKEN:
    print(f"[ERROR] Missing PLEX_URL or PLEX_TOKEN in {ENV_PATH}", file=sys.stderr)
    sys.exit(2)

def remap_localhost_for_container(url: str) -> str:
    """Map localhost/127.0.0.1 to host.docker.internal for container -> host access."""
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

# ---------------------------
# Args
# ---------------------------
parser = argparse.ArgumentParser(description="Clear and repopulate a Plex playlist from DB.")
parser.add_argument("ratingKey", type=int, help="The ratingKey (numeric id) of the target playlist")
args = parser.parse_args()
playlist_rating_key: int = args.ratingKey

# ---------------------------
# Helpers
# ---------------------------
def round_robin(grouped: Dict[int, List[int]]) -> List[int]:
    """
    Interleave lists by index to produce a round-robin order.
    grouped = { timeSlot: [ratingKey, ...], ... }
    """
    if not grouped:
        return []
    keys = sorted(grouped.keys())
    max_len = max(len(v) for v in grouped.values()) if grouped else 0
    order: List[int] = []
    for i in range(max_len):
        for k in keys:
            lst = grouped.get(k, [])
            if i < len(lst):
                order.append(lst[i])
    return order

def chunked(iterable: List, size: int) -> Iterable[List]:
    """Yield lists of length <= size from iterable."""
    for i in range(0, len(iterable), size):
        yield iterable[i:i+size]

# ---------------------------
# Connect to Plex (requests.Session controls SSL verify)
# ---------------------------
try:
    session = requests.Session()
    session.verify = True if PLEX_VERIFY_SSL else False
    plex = PlexServer(PLEX_URL, PLEX_TOKEN, session=session)
except Exception as e:
    print(f"[ERROR] Failed to connect to Plex at {PLEX_URL}: {e}", file=sys.stderr)
    sys.exit(3)

# ---------------------------
# Fetch playlist by ratingKey
# ---------------------------
try:
    item = plex.fetchItem(playlist_rating_key)
    if not isinstance(item, Playlist):
        print("[ERROR] The fetched item is not a Playlist. Check the ratingKey.", file=sys.stderr)
        sys.exit(4)
    playlist: Playlist = item
    print(f"[INFO] Target playlist: '{playlist.title}' (ratingKey={playlist_rating_key})")
except Exception as e:
    print(f"[ERROR] Could not fetch playlist with ratingKey {playlist_rating_key}: {e}", file=sys.stderr)
    sys.exit(4)

# ---------------------------
# Connect to DB and read episodes
# ---------------------------
if not os.path.exists(DB_PATH):
    print(f"[ERROR] Database not found at {DB_PATH}", file=sys.stderr)
    sys.exit(5)

try:
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
except Exception as e:
    print(f"[ERROR] Could not open SQLite DB at {DB_PATH}: {e}", file=sys.stderr)
    sys.exit(5)

try:
    query = """
    SELECT ratingKey, timeSlot
    FROM playlistEpisodes
    ORDER BY timeSlot, show_id, season, episode
    """
    cur.execute(query)
    rows = cur.fetchall()
finally:
    cur.close()
    conn.close()

if not rows:
    print("[WARN] No episodes found in playlistEpisodes. Nothing to add.", file=sys.stderr)
    rows = []

# Group by timeSlot
episodes_by_slot: Dict[int, List[int]] = {}
for rating_key, slot in rows:
    try:
        rk_int = int(rating_key)
        episodes_by_slot.setdefault(int(slot), []).append(rk_int)
    except Exception:
        continue

# Produce round-robin order
episode_order: List[int] = round_robin(episodes_by_slot)
print(f"[INFO] Episodes to add (count): {len(episode_order)}")

# ---------------------------
# Clear existing items
# ---------------------------
try:
    current_items = playlist.items()
    if current_items:
        print(f"[INFO] Clearing existing playlist items: {len(current_items)}")
        playlist.removeItems(current_items)
    else:
        print("[INFO] Playlist already empty.")
except Exception as e:
    print(f"[ERROR] Failed to clear existing playlist items: {e}", file=sys.stderr)
    sys.exit(6)

# ---------------------------
# Fetch episodes & add in chunks
# ---------------------------
if not episode_order:
    print("[INFO] No episodes to add. Leaving playlist empty.")
    sys.exit(0)

items_to_add = []
failed_fetch = 0
for rk in episode_order:
    try:
        items_to_add.append(plex.fetchItem(rk))
    except Exception as e:
        failed_fetch += 1
        print(f"[WARN] Could not fetch episode ratingKey={rk}: {e}", file=sys.stderr)

print(f"[INFO] Fetched {len(items_to_add)} items; {failed_fetch} failed.")

added_total = 0
try:
    for batch in chunked(items_to_add, 500):
        if not batch:
            continue
        playlist.addItems(batch)
        added_total += len(batch)
        print(f"[INFO] Added {len(batch)} items (running total: {added_total})")
except Exception as e:
    print(f"[ERROR] Failed while adding items to playlist '{playlist.title}': {e}", file=sys.stderr)
    sys.exit(7)

print(f"[SUCCESS] Added {added_total} episodes to playlist '{playlist.title}'.")
sys.exit(0)
