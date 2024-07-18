import requests
from dotenv import load_dotenv
import os
from datetime import datetime
from plexapi.server import PlexServer

# Load environment variables from .env file
load_dotenv()

# Retrieve environment variables
PLEX_URL = os.getenv('PLEX_URL')
PLEX_TOKEN = os.getenv('PLEX_TOKEN')

def create_playlist_with_episodes(episode_keys, playlist_name):
    headers = {'X-Plex-Token': PLEX_TOKEN, 'Accept': 'application/json'}
    uri_parts = [f"/library/metadata/{key}" for key in episode_keys]
    uri = f"server://{PLEX_URL.split('//')[1].split(':')[0]}/com.plexapp.plugins.library{','.join(uri_parts)}"
    params = {
        'type': 'video',
        'title': playlist_name,
        'smart': 0,
        'uri': uri
    }
    response = requests.post(f"{PLEX_URL}/playlists", params=params, headers=headers)
    
    if response.status_code in [200, 201]:
        print(f"Playlist '{playlist_name}' created successfully.")
        
        # Connect to Plex server
        plex = PlexServer(PLEX_URL, PLEX_TOKEN)
        
        # Fetch the playlist by name to get its ratingKey
        for playlist in plex.playlists():
            if playlist.title == playlist_name:
                return playlist.ratingKey, playlist_name
    else:
        print(f"Error during playlist creation: {response.status_code} - {response.content.decode('utf-8')}")
        return None, None

if __name__ == "__main__":
    # Generate the playlist name with the current date and time
    current_datetime = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    playlist_name = f"TV Playlist {current_datetime}"

    episode_keys = [12345]  # Example keys, replace with actual keys from your episodes

    rating_key, playlist_name = create_playlist_with_episodes(episode_keys, playlist_name)
    if rating_key:
        print(f"New Playlist Name: {playlist_name}")
        print(f"New Playlist Rating Key: {rating_key}")
