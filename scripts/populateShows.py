from plexapi.server import PlexServer
from dotenv import load_dotenv
import sqlite3
import os

# Load environment variables from .env file
load_dotenv()

PLEX_URL = os.getenv('PLEX_URL')
PLEX_TOKEN = os.getenv('PLEX_TOKEN')

# Create the database directory if it doesn't exist
db_directory = os.path.join(os.path.dirname(__file__), '../database')
if not os.path.exists(db_directory):
    os.makedirs(db_directory)

db_file = os.path.join(db_directory, 'plex_playlist.db')

# SQL for creating tables
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

# Connect to Plex Server
plex = PlexServer(PLEX_URL, PLEX_TOKEN)
print("Connected to Plex Server.")

# Connect to SQLite Database
try:
    db_conn = sqlite3.connect(db_file)
    cursor = db_conn.cursor()
    print("Successfully connected to the database.")
    
    # Create tables if they do not exist
    cursor.executescript(create_tables_sql)
    db_conn.commit()
    print("Created tables in the database.")

except sqlite3.Error as e:
    print(f"Error connecting to SQLite: {e}")
    exit(1)

# Fetch all TV shows from Plex
shows = plex.library.search(libtype='show')
print(f"Found {len(shows)} TV shows in the entire library.")

# Iterate over Plex shows and insert into the database
for show in shows:
    try:
        total_episodes = len(show.episodes())
        cursor.execute("INSERT INTO allShows (id, title, total_episodes) VALUES (?, ?, ?)",
                       (show.ratingKey, show.title, total_episodes))
        db_conn.commit()
        print(f"Inserted show: {show.title} with {total_episodes} episodes.")
    except sqlite3.IntegrityError:
        print(f"Show already exists in the database: {show.title}")
    except sqlite3.Error as e:
        print(f"Failed to insert show data for {show.title}: {e}")

print("Database update complete.")

# Close connections
cursor.close()
db_conn.close()
