import sqlite3
from plexapi.server import PlexServer
from plexapi.playlist import Playlist
from dotenv import load_dotenv
import os
import argparse

# Load environment variables from .env file
load_dotenv()

# Plex server connection details
PLEX_URL = os.getenv('PLEX_URL')
PLEX_TOKEN = os.getenv('PLEX_TOKEN')

# Set up argument parser
parser = argparse.ArgumentParser(description="Manage Plex playlist.")
parser.add_argument("ratingKey", type=int, help="The ratingKey of the playlist")
args = parser.parse_args()

playlist_rating_key = args.ratingKey  # Get the ratingKey from command-line argument

# Connect to Plex server
plex = PlexServer(PLEX_URL, PLEX_TOKEN)

# Attempt to fetch the playlist directly by its ratingKey
try:
    playlist = plex.fetchItem(playlist_rating_key)  # Passing as an integer
    if isinstance(playlist, Playlist):
        print(f"Found playlist: {playlist.title}. Clearing existing items.")
        playlist.removeItems(playlist.items())
    else:
        print("Fetched item is not a playlist.")
        exit(1)
except Exception as e:
    print(f"Error fetching the playlist by ratingKey: {e}")
    exit(1)

# Database connection details
db_file = os.path.join(os.path.dirname(__file__), '../database/plex_playlist.db')

# Connect to Database
try:
    db_conn = sqlite3.connect(db_file)
    cursor = db_conn.cursor()
    print("Successfully connected to the database.")
except sqlite3.Error as e:
    print(f"Error connecting to SQLite: {e}")
    exit(1)

# Fetch episodes from the database with timeSlot
query = """
SELECT ratingKey, timeSlot FROM playlistEpisodes
ORDER BY timeSlot, show_id, season, episode
"""
cursor.execute(query)
episodes = cursor.fetchall()

# Group episodes by timeSlot
episodes_by_timeSlot = {}
for episode_key, timeSlot in episodes:
    if timeSlot not in episodes_by_timeSlot:
        episodes_by_timeSlot[timeSlot] = []
    episodes_by_timeSlot[timeSlot].append(int(episode_key))  # Ensure ratingKey is an integer

# Prepare episodes in round-robin order based on timeSlot
items_order = []
episode_count = {timeSlot: len(episodes) for timeSlot, episodes in episodes_by_timeSlot.items()}
timeSlots = sorted(episodes_by_timeSlot.keys())  # Ensure a consistent order of timeSlots

# Continue round-robin until all episodes are added
while any(episode_count[timeSlot] > 0 for timeSlot in timeSlots):
    for timeSlot in timeSlots:
        if episode_count[timeSlot] > 0:
            items_order.append(episodes_by_timeSlot[timeSlot][len(episodes_by_timeSlot[timeSlot]) - episode_count[timeSlot]])
            episode_count[timeSlot] -= 1

# Fetch episodes in the order for playlist addition
items_to_add = []
for episode_key in items_order:
    try:
        episode = plex.fetchItem(episode_key)
        items_to_add.append(episode)
    except Exception as e:
        print(f"Error fetching episode with ratingKey {episode_key} from Plex: {e}")

# Add all episodes to the playlist in the new order
if items_to_add:
    try:
        playlist.addItems(items_to_add)
        print(f"Successfully added {len(items_to_add)} episodes in rotated order to playlist '{playlist.title}'.")
    except Exception as e:
        print(f"Error adding episodes to playlist: {e}")

# Close database connection
cursor.close()
db_conn.close()
