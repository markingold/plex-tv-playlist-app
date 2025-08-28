#!/usr/bin/env python3
import os
import sqlite3
from dotenv import load_dotenv
from plexapi.server import PlexServer

# ---- Paths & env loading (works regardless of CWD) ----
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV_PATH = os.path.join(ROOT, '.env')
load_dotenv(ENV_PATH)

PLEX_URL = os.getenv('PLEX_URL')
PLEX_TOKEN = os.getenv('PLEX_TOKEN')

# ---- DB setup ----
db_directory = os.path.join(ROOT, 'database')
os.makedirs(db_directory, exist_ok=True)  # tolerate if exists
db_file = os.path.join(db_directory, 'plex_playlist.db')

create_tables_sql = '''
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
'''

with sqlite3.connect(db_file) as conn:
    conn.executescript(create_tables_sql)
    conn.commit()

# ---- Connect to Plex AFTER DB is ready ----
if not PLEX_URL or not PLEX_TOKEN:
    raise RuntimeError(f"Missing PLEX_URL or PLEX_TOKEN. Checked: {ENV_PATH}")

plex = PlexServer(PLEX_URL, PLEX_TOKEN)
print("Connected to Plex Server.")

# ---- Populate shows (fast count using leafCount, fallback if needed) ----
shows = plex.library.search(libtype='show')
with sqlite3.connect(db_file) as conn:
    cur = conn.cursor()
    for show in shows:
        try:
            total = getattr(show, 'leafCount', None)
            if total is None:
                total = len(show.episodes())
            cur.execute(
                "INSERT OR REPLACE INTO allShows (id, title, total_episodes) VALUES (?, ?, ?)",
                (int(show.ratingKey), show.title, int(total or 0))
            )
        except Exception as e:
            print(f"Skip {getattr(show,'title','<unknown>')}: {e}")
    conn.commit()

print("Database update complete.")
