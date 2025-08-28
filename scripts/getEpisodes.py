#!/usr/bin/env python3
import os
import sqlite3
import math
from dotenv import load_dotenv
from plexapi.server import PlexServer

# Load environment variables from .env file
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
load_dotenv(os.path.join(ROOT, '.env'))

PLEX_URL = os.getenv('PLEX_URL')
PLEX_TOKEN = os.getenv('PLEX_TOKEN')
DB_FILE = os.path.join(ROOT, 'database', 'plex_playlist.db')

if not PLEX_URL or not PLEX_TOKEN:
    print("Missing PLEX_URL or PLEX_TOKEN in .env")
    exit(2)

# Connect to Plex Server
plex = PlexServer(PLEX_URL, PLEX_TOKEN)
print("Connected to Plex Server.")

# Connect to Database
try:
    db_conn = sqlite3.connect(DB_FILE)
    cursor = db_conn.cursor()
    print("Successfully connected to the database.")

    # Clear the playlistEpisodes table before starting
    cursor.execute("DELETE FROM playlistEpisodes")
    db_conn.commit()
    print("Cleared the playlistEpisodes table.")
except sqlite3.Error as e:
    print(f"Error connecting to SQLite: {e}")
    exit(1)

# Fetch shows (ratingKey + timeslot) from DB
# We will match by ratingKey, not by title.
cursor.execute("SELECT id, timeSlot FROM playlistShows")
rows = cursor.fetchall()
shows_from_db = {int(rk): ts for rk, ts in rows}
print(f"Retrieved {len(shows_from_db)} selected shows from database.")

# Gather TV libraries (type == 'show')
tv_sections = [s for s in plex.library.sections() if getattr(s, 'type', '') == 'show']
if not tv_sections:
    print("No TV Show libraries found.")
    exit(1)

matched_shows = 0
total_episodes_processed = 0

for section in tv_sections:
    print(f"Processing TV library: {section.title}")
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
                print(f"Failed to insert episode data for {getattr(ep, 'title', '<unknown>')}: {e}")

print(f"Database update complete. {matched_shows} shows matched. {total_episodes_processed} episodes processed.")

cursor.close()
db_conn.close()
