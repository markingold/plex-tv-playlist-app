# Plex TV Playlist (Round‑Robin) — Dockerized Web App

Create a **round‑robin TV playlist** on your Plex Media Server: pick shows, assign unique time slots, and the app builds a playlist that cycles Ep1 of each show, then Ep2, and so on. Everything ships in a single Docker service with PHP (UI) + Python (Plex logic) baked in.

> TL;DR  
> 1) `docker compose up -d`  
> 2) Open `http://localhost:8080`  
> 3) Sign in with Plex → Choose your server → Done

---

## ✨ Features

- Plex PIN login (auth.plex.tv) with server selection and token validation
- No local Python setup: Python + deps are baked into the container
- Click‑and‑go web UI (Bootstrap)
- Round‑robin playlist generation across shows
- Persistent DB & logs (`./database`, `./logs`)
- Auto-detects Plex token, server URL, and SSL settings
- Self-signed Plex? Just click "Use insecure connection"

---

## 🧱 What’s in the box

    .
    ├── docker-compose.yml             # One service: php-apache + embedded Python
    ├── Dockerfile                     # Multi-stage: Python deps + PHP runtime
    ├── docker/entrypoint.sh           # Bootstraps .env, fixes permissions, ensures dirs
    ├── .env.example                   # Legacy manual env; optional now
    ├── public/
    │   ├── index.php                  # Setup wizard entry
    │   ├── _env.php                   # Reads/writes .env, handles Plex Auth flow
    │   ├── plex_auth.php             # PIN login and server token probing
    │   ├── _bootstrap.php             # PHP helpers (paths, Python exec)
    │   ├── header.php                 # Header layout
    │   ├── partials/                  # Shared layout components
    │   ├── add_shows.php              # Select shows
    │   └── timeslots.php              # Assign timeslots & generate playlist
    ├── scripts/
    │   ├── populateShows.py           # Build `allShows` from Plex
    │   ├── getEpisodes.py             # Fill `playlistEpisodes`
    │   ├── newPlaylist.py             # Create empty Plex playlist
    │   ├── generatePlaylist.py        # Round-robin add episodes to playlist
    │   └── plex_debug_dump.py         # CLI diagnostic tool (token test, library listing)
    ├── run.sh                         # Optional CLI bootstrap script
    ├── database/                      # SQLite DB (mounted volume)
    ├── logs/                          # Logs (per script run)
    └── README.md                      # This file

---

## ⚙️ Requirements

- Docker / Docker Compose v2+
- A reachable Plex Media Server on your local network or remote
- Plex account credentials (PIN login used)

**✅ Tested on:**  
✔️ macOS (Docker Desktop)  
✔️ Linux (Debian/Ubuntu)  
✔️ Windows (via WSL2 or native Docker)  
✔️ Unraid (Docker tab + `docker-compose.yml` or via stack templates)

---

## 🛫 Quick Start (Zero-Config)

1) Clone and launch:

```bash
git clone https://github.com/markingold/plex-tv-playlist-app.git
cd plex-tv-playlist-app
docker compose up -d
Open your browser to:

arduino
Always show details

Copy code
http://localhost:8080
Use the Setup Wizard:

Click “Sign in with Plex”

Authorize your account

Select your Plex server

Configure SSL options (self-signed or verified)

The .env file will be saved automatically

You’ll land on the Show Selection page once setup is complete.

🧰 Manual .env Mode (Advanced / Legacy)
You can still use .env manually if you prefer:

bash
Always show details

Copy code
cp .env.example .env
Edit the file and set:

PLEX_URL — e.g. https://192.168.1.50:32400

PLEX_TOKEN — Plex token (get via Plex Web → Account → Devices)

PLEX_VERIFY_SSL — false for self-signed certs or plex.direct

▶️ Walkthrough
Start the app

bash
Always show details

Copy code
docker compose up -d
Visit http://localhost:8080

Authenticate with Plex

Use the built-in PIN login

Select your server

Verify connection (the app probes /identity to validate token)

Once complete, you're redirected to Show Selection

Choose Shows

Select any shows you want to include

Submit to save them

Assign Timeslots & Generate

Each show needs a unique slot (1, 2, 3…)

Click “Generate Playlist”

The app runs:

getEpisodes.py

newPlaylist.py

generatePlaylist.py <ratingKey>

🐳 Unraid / Other Hosts
Unraid Options:

Use the docker-compose.yml in Unraid’s “Stacks” tab (via Compose Manager plugin)

Or manually create a Docker container with:

Image: php:apache

Mount plex-tv-playlist-app repo into /var/www/html

Set working directory

Run the run.sh script or use docker exec to manually run Python scripts

Alternative Docker Hosts:

Docker Desktop (Mac, Windows): works out of the box

WSL2: use host.docker.internal to reach host Plex server

Remote: if your Plex server is cloud-hosted, ensure it’s accessible

🧪 Scripts / Shell Access
Access container shell:

bash
Always show details

Copy code
docker compose exec plex-playlist bash
Run any script manually:

bash
Always show details

Copy code
python /var/www/html/scripts/populateShows.py
python /var/www/html/scripts/plex_debug_dump.py --token ... --url ...
🗃️ Data & Logs
database/plex_playlist.db — SQLite database

logs/*.log — Script logs (one per run)

.env — Auto-generated by the Setup Wizard

Reset state:

bash
Always show details

Copy code
docker compose down
rm -rf database/*.db logs/* .env
docker compose build --no-cache
docker compose up -d
🔐 Security Notes
.env contains your Plex token. Never commit it!

The app auto-creates .env but will never overwrite it without consent

If exposing publicly, use a reverse proxy with HTTPS and authentication

🧠 Under the Hood
populateShows.py → builds allShows

getEpisodes.py → builds playlistEpisodes

newPlaylist.py → creates playlist

generatePlaylist.py → adds episodes round-robin

UI flows: index.php → add_shows.php → timeslots.php

🧩 Dev Tips
Use run.sh to bootstrap locally

Debug token/server issues with plex_debug_dump.py

Logs and DB are persistent between container restarts

The PHP helper _bootstrap.php includes helpers for safely running Python

🤝 Contributing
Pull requests welcome!

Please:

Use consistent logging styles ([INFO], [WARN], [ERROR])

Keep .env.example current

Do not commit .env or any real tokens/URLs

📜 License
MIT — see LICENSE