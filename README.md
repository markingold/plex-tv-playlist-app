# Plex TV Playlist App

This application helps you generate round-robin playlists for your TV shows on Plex Media Server. 
You can select the shows, arrange them in your preferred order, and create a playlist that cycles 
through the first episode of each show, then the second episode of each show, and so on, until all episodes 
are added to the playlist.

## Features

- Connects seamlessly to your Plex Media Server.
- Uses SQLite for efficient data storage.
- Allows for round-robin episode selection.

## How It Works

The app retrieves all your TV shows from the Plex server and stores them in a database. You can then 
select which shows you want in your playlist and assign them timeslots. The application will generate 
a playlist in Plex that plays episodes in the order you specified.

## Getting Started

### Prerequisites

- Python 3.x
- PHP 7.x or higher
- Composer
- SQLite
- Plex Media Server

### Installation

1. Clone the repository:
    ```sh
    git clone https://github.com/yourusername/plex-tv-playlist-app.git
    cd plex-tv-playlist-app
    ```

2. Set up the Python virtual environment:
    ```sh
    python3 -m venv venv
    source venv/bin/activate
    ```

3. Install Python dependencies:
    ```sh
    pip install -r requirements.txt
    ```

4. Install PHP dependencies using Composer:
    ```sh
    composer install
    ```

5. Copy the example environment file and configure it:
    ```sh
    cp .env.example .env
    ```

6. Update the `.env` file with your Plex URL and Plex Token. 

### Finding Your Plex Token

To find your Plex token, follow these steps:

1. Open your web browser and log into your Plex account.
2. Right-click anywhere on the page and select "Inspect" or "Inspect Element" to open the developer tools.
3. Go to the "Network" tab.
4. In the filter box, type `X-Plex-Token`.
5. Refresh the page.
6. Look for network requests that include the `X-Plex-Token` header.
7. Copy the token value and paste it into your `.env` file.

Example of the `.env` file:
```
PLEX_URL=http://your-plex-server:32400
PLEX_TOKEN=your_plex_token
```

### Setting Permissions

7. Ensure the SQLite database and directory have the correct permissions:
    ```sh
    sudo chown www-data:www-data /path/to/plex_playlist.db
    sudo chmod 664 /path/to/plex_playlist.db
    sudo chown -R www-data:www-data /path/to/database/directory
    sudo chmod -R 775 /path/to/database/directory
    ```

### Usage

1. Start your web server (e.g., Apache) and navigate to the application URL.

2. Click the "Initialize Database/Update TV Shows" button on the home page to set up the database and fetch TV shows from your Plex server.

3. Select the TV shows you want to include in the playlist and assign them timeslots.

4. Generate the playlist.

### Directory Structure

```
plex_tv_playlist_app/
├── README.md
├── .env.example
├── composer.json
├── composer.lock
├── index.php
├── public/
│   ├── add_shows.php
│   ├── timeslots.php
│   └── header.php
├── scripts/
│   ├── generatePlaylist.py
│   ├── getEpisodes.py
│   ├── newPlaylist.py
│   └── populateShows.py
├── database/
│   └── plex_playlist.db
├── vendor/ (composer dependencies)
├── venv/ (virtual environment)
└── pyvenv.cfg
```

## License

This project is licensed under the MIT License - see the LICENSE.md file for details.
>>>>>>> 64572ab (Initial commit)
