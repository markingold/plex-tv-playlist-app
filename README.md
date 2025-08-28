# Plex TV Playlist (Round‑Robin) — Dockerized Web App

Create a **round‑robin TV playlist** on your Plex Media Server: pick shows, assign unique time slots, and the app builds a playlist that cycles Ep1 of each show, then Ep2, and so on. Everything ships in a single Docker service with PHP (UI) + Python (Plex logic) baked in.

> TL;DR  
> 1) `cp .env.example .env` and set `PLEX_URL` + `PLEX_TOKEN`  
> 2) `docker compose up -d`  
> 3) Open `http://localhost:8080` → Initialize DB → Select shows → Assign timeslots → Generate playlist

---

## ✨ Features

- No local Python setup: Python + deps are baked into the image
- Click‑and‑go web UI (Bootstrap nav)
- Round‑robin playlist across multiple shows
- Persistent DB & logs on host (`./database`, `./logs`)
- Plex token & URL via environment (`.env` or Compose `env_file`)
- Self‑signed Plex? Use `PLEX_VERIFY_SSL=false`

---

## 🧱 What’s in the box

    .
    ├── docker-compose.yml            # One service: php-apache + baked python
    ├── dockerfile                    # Multi-stage: Python deps + PHP runtime
    ├── docker/entrypoint.sh          # Bootstraps .env, fixes permissions, ensures dirs
    ├── .env.example                  # Copy to .env and fill in your Plex details
    ├── public/
    │   ├── header.php                # Top nav (Bootstrap)
    │   ├── _bootstrap.php            # PHP helpers (paths, run_py_logged)
    │   ├── add_shows.php             # Select which shows to include
    │   └── timeslots.php             # Assign unique time slots & generate playlist
    ├── index.php                     # Home & initialize database from Plex
    ├── scripts/
    │   ├── populateShows.py          # Build DB table `allShows` from Plex libraries
    │   ├── getEpisodes.py            # Fill `playlistEpisodes` for selected shows
    │   ├── newPlaylist.py            # Create empty Plex playlist (seed/clear)
    │   ├── generatePlaylist.py       # Clear & repopulate playlist in round‑robin
    │   └── requirements.txt          # Python deps (plexapi, python-dotenv, requests)
    ├── database/                     # (mounted) SQLite DB lives here
    ├── logs/                         # (mounted) Script logs
    ├── LICENSE                       # MIT
    └── README.md                     # This file

---

## ⚙️ Requirements

- Docker / Docker Compose v2
- A reachable Plex Media Server with a valid X‑Plex‑Token

Get your token: Plex Web → Account → Devices → find your server → Show Token.

---

## 🔧 Configuration

Copy the example file and edit it:

    cp .env.example .env

`.env` variables:

- `PLEX_URL` — e.g. `http://192.168.1.50:32400` or `https://myhost:32400`
- `PLEX_TOKEN` — your Plex auth token
- `PLEX_VERIFY_SSL` — `true` if using a valid HTTPS cert; `false` for self‑signed/plex.direct
- `PYTHON_EXEC` — Python inside the container (default: `/usr/local/bin/python3`)

Never commit your real `.env`. The repo’s `.gitignore` helps prevent that.

Compose loads these with:

    services:
      plex-playlist:
        env_file:
          - .env

---

## ▶️ Quick Start

1) Build & run

    docker compose up -d

2) Open the app

- Visit: `http://localhost:8080` (or your mapped host/port)

3) Initialize database

- Click “Initialize Database / Update TV Shows” — this runs `scripts/populateShows.py`
- On success, you’ll be redirected to Edit Shows

4) Select shows

- Check the shows you want in the playlist and click Submit

5) Assign timeslots & generate

- Each show must have a unique time slot (1..N)
- Click Generate Playlist  
  The app runs:
  - `getEpisodes.py` → fills `playlistEpisodes`
  - `newPlaylist.py` → creates empty playlist (seeded then cleared)
  - `generatePlaylist.py <ratingKey>` → adds episodes in round‑robin

---

## 🧪 Using the Container Shell (optional)

Drop into the container:

    docker compose exec plex-playlist bash

Run scripts manually (handy for debugging):

    python /var/www/html/scripts/populateShows.py
    python /var/www/html/scripts/getEpisodes.py
    python /var/www/html/scripts/newPlaylist.py
    python /var/www/html/scripts/generatePlaylist.py <playlist_ratingKey>

---

## 🗃️ Data & Logs

- Database: `./database/plex_playlist.db` (mounted into `/var/www/html/database`)
- Logs: `./logs/*.log` (created per run from the UI)

Inspect container logs:

    docker logs -f plex-playlist

Healthcheck (inside container):

    curl -fsS http://localhost/

---

## 🔐 Security Notes

- Treat your `PLEX_TOKEN` like a password.
- Don’t commit real tokens or IPs. Use `.env.example` for docs/defaults.
- If exposing publicly, put behind a reverse proxy with auth and TLS.

---

## 🛠️ Troubleshooting

1) ConnectTimeoutError or timeouts reaching Plex  
   The container can’t reach `PLEX_URL`. Check:
   - IP/hostname and port (usually 32400)
   - Docker host can reach your Plex server (try curl from host)
   - For self‑signed HTTPS, set `PLEX_VERIFY_SSL=false`

2) Missing PLEX_URL or PLEX_TOKEN  
   Ensure `.env` exists and Compose loads it:
   - `docker compose config` should show env resolved
   - Recreate after editing `.env`: `docker compose up -d --force-recreate`

3) Header already sent / PHP warnings  
   Pages process POST and redirects before output. If you edit, keep redirects before any echo/HTML.

4) Permissions / DB write issues  
   Entrypoint ensures `database/` and `logs/` are writable by `www-data`.  
   If your host mount is restrictive:
   - `chmod -R u+rwX,g+rwX database logs`

5) Start fresh (rebuild)

    docker compose down
    rm -rf database/*.db logs/*
    docker compose build --no-cache
    docker compose up -d

---

## 🧩 How it works (under the hood)

- `populateShows.py`  
  Connects to Plex and lists all TV shows → `allShows(id, title, total_episodes)`
- `add_shows.php`  
  You pick shows → writes to `playlistShows(id, title, total_episodes, timeSlot NULL)`
- `timeslots.php`  
  You assign unique timeslots and run the pipeline:
  - `getEpisodes.py` → collects episodes into `playlistEpisodes`
  - `newPlaylist.py`  → creates empty Plex playlist (seed/clear)
  - `generatePlaylist.py` → groups by timeSlot and adds episodes in round‑robin

Schema created on first run:

- `allShows(id, title, total_episodes)`
- `playlistShows(id, title, total_episodes, timeSlot)`
- `playlistEpisodes(ratingKey, season, episode, releaseDate, duration, summary, watchedStatus, title, episodeTitle, show_id, timeSlot)`

---

## 🧭 Development Tips

- PHP helpers live in `public/_bootstrap.php`:
  - `run_py_logged($script, $args, $logfile)` runs a Python script from `/scripts` and captures stdout/stderr + exit code.
- All Python scripts read `.env` in the project root and respect `PLEX_VERIFY_SSL`.
- Keep timeslots unique; the UI enforces and the backend validates it.

---

## 🤝 Contributing

PRs welcome. Please:
- Keep `.env.example` current
- Don’t commit real tokens
- Follow the existing log style: `[INFO]`, `[WARN]`, `[ERROR]`, `[SUCCESS]`

---

## 📜 License

MIT — see `LICENSE`.
