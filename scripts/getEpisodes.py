from plexapi.server import PlexServer
import sqlite3
import math  # For rounding up the duration
from dotenv import load_dotenv
import os

# Load environment variables from .env file
load_dotenv()

PLEX_URL = os.getenv('PLEX_URL')
PLEX_TOKEN = os.getenv('PLEX_TOKEN')
db_file = os.path.join(os.path.dirname(__file__), '../database/plex_playlist.db')

# Connect to Plex Server
plex = PlexServer(PLEX_URL, PLEX_TOKEN)
print("Connected to Plex Server.")

# Connect to Database
try:
    db_conn = sqlite3.connect(db_file)
    cursor = db_conn.cursor()
    print("Successfully connected to the database.")
    
    # Clear the playlistEpisodes table before starting
    cursor.execute("DELETE FROM playlistEpisodes")
    db_conn.commit()
    print("Cleared the playlistEpisodes table.")
    
except sqlite3.Error as e:
    print(f"Error connecting to SQLite: {e}")
    exit(1)

# Fetch shows from the database
cursor.execute("SELECT id, title, timeSlot FROM playlistShows")
shows_from_db = cursor.fetchall()
print(f"Retrieved {len(shows_from_db)} shows from database.")

# Find the TV Shows section
tv_shows_section = None
for section in plex.library.sections():
    if section.title == 'TV Shows':
        tv_shows_section = section
        break

if not tv_shows_section:
    print("TV Shows section not found.")
    exit(1)

print(f"Library sections found: {[section.title for section in plex.library.sections()]}")
print(f"Processing shows from section: {tv_shows_section.title}")

# Iterate over Plex shows and database shows to find matches
matched_shows = 0
total_episodes_processed = 0
for show in tv_shows_section.all():
    for db_show in shows_from_db:
        db_show_id, db_show_title, db_show_timeSlot = db_show
        if show.title == db_show_title:
            print(f"Match found for show: {show.title}")
            matched_shows += 1
            # Found a match, now iterate over episodes
            for episode in show.episodes():
                try:
                    # Convert duration from milliseconds to minutes, rounded up
                    duration_minutes = math.ceil(episode.duration / 60000)
                    
                    insert_stmt = ("INSERT INTO playlistEpisodes "
                                   "(ratingKey, season, episode, releaseDate, duration, summary, "
                                   "watchedStatus, title, episodeTitle, show_id, timeSlot) "
                                   "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    data = (episode.ratingKey, episode.parentIndex, episode.index, episode.originallyAvailableAt,
                            duration_minutes, episode.summary, episode.viewCount > 0,
                            episode.grandparentTitle, episode.title, db_show_id, db_show_timeSlot)
                    
                    cursor.execute(insert_stmt, data)
                    db_conn.commit()
                    total_episodes_processed += 1
                    print(f"Inserted episode: {episode.title} with duration {duration_minutes} minutes")
                except sqlite3.Error as e:
                    print(f"Failed to insert episode data for {episode.title}: {e}")

print(f"Database update complete. {matched_shows} shows matched. {total_episodes_processed} episodes processed.")

# Close connections
cursor.close()
db_conn.close()
