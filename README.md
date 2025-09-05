# 📺 Plex TV Playlist App — Round‑Robin TV Scheduler for Plex

Create a **round‑robin TV playlist** on your Plex Media Server: pick shows, assign unique time slots, and the app builds a playlist that cycles Ep1 of each show, then Ep2, and so on — like live TV.

This runs entirely in **Docker** and includes a web UI, Python logic, and a guided **Plex login & server setup wizard**. No manual token copying required.

---

## ⚡ TL;DR (Quick Start if you already have Docker)

1) Download the app (clone or ZIP), open a terminal in the project folder, then:

    docker compose up -d

2) Open your browser at:

    http://localhost:8080

3) Click **Setup** → **Sign in with Plex** → choose your server → **Save** → **Initialize / Refresh TV Shows** → pick shows → assign timeslots → **Generate Playlist**.

---

## 🐳 Install Docker (if you’ve never used it)

You only have to do this once.

### Windows or macOS
1. Download Docker Desktop:  https://www.docker.com/products/docker-desktop/
2. Install and launch it once so it finalizes setup.
3. Verify in a terminal:

       docker --version
       docker compose version

If either command fails, restart your computer and try again.

### Linux
1. Install Docker Engine:  https://docs.docker.com/engine/install/
2. Install Docker Compose plugin:  https://docs.docker.com/compose/install/linux/
3. Verify:

       docker --version
       docker compose version

---

## ⬇️ Get the App Files

Choose one:

**A) Download ZIP (easiest, no Git required)**
- Go to the GitHub repo page.
- Click **Code** → **Download ZIP**.
- Unzip it somewhere easy (e.g., your Desktop).
- Open a terminal in that unzipped folder.

**B) Clone with Git (power users)**

    git clone https://github.com/markingold/plex-tv-playlist-app.git
    cd plex-tv-playlist-app

---

## 🚀 Start the App

From the project folder:

    docker compose up -d

This builds and starts a single container. When it’s ready, open:

    http://localhost:8080

You’ll see the web UI.

---

## 🧭 First‑Run Setup (in the Web UI)

1) Go to **Setup**.
2) Click **Sign in with Plex** (PIN flow).
   - A Plex page opens — approve access.
   - The app auto-detects your **Plex servers** and shows them in a list.
3) Select your server and click **Save to .env**.
   - The app tests connectivity and writes `PLEX_URL`, `PLEX_TOKEN`, and `PLEX_VERIFY_SSL` for you.
4) Click **Initialize / Refresh TV Shows** to sync your TV libraries.
5) Go to **Edit Shows** and select the shows you want included.
6) Go to **Timeslots**, assign each show a unique slot (1..N), and click **Generate Playlist**.
   - The app creates (or clears) the target playlist and fills it in round‑robin order.

That’s it. Open Plex → Playlists to see it.

---

## ✨ Features

- Round‑robin episode scheduling across multiple shows.
- Built‑in **Plex PIN login** and **server discovery** (no manual token copying).
- Works with local IPs, `plex.direct`, HTTPS, and self‑signed certs.
- All Python logic is bundled; no local Python needed.
- Persistent **SQLite database** and **logs** on your host machine.
- Healthcheck endpoint and a **debug tool** for deeper troubleshooting.

---

## 📂 What’s in the Project

    .
    ├── docker-compose.yml          # One service: PHP+Apache with Python baked in
    ├── Dockerfile                  # Multi-stage build (Python deps → PHP runtime)
    ├── docker/entrypoint.sh        # Bootstraps .env, ensures writable dirs
    ├── public/                     # Web UI (PHP)
    │   ├── setup.php               # Plex login & server selection wizard
    │   ├── add_shows.php           # Pick shows to include
    │   ├── timeslots.php           # Assign slots & generate playlist
    │   ├── _bootstrap.php          # PHP helpers (run Python scripts)
    │   ├── _env.php                # .env read/write helpers
    │   └── plex_auth.php           # PIN flow, resource discovery, .env save
    ├── scripts/                    # Python logic
    │   ├── populateShows.py        # Pull shows into SQLite
    │   ├── getEpisodes.py          # Pull episodes for selected shows
    │   ├── newPlaylist.py          # Create/clear target playlist
    │   ├── generatePlaylist.py     # Build round‑robin order & add items
    │   └── plex_debug_dump.py      # Deep-dive debug tool (URL/token checks)
    ├── database/                   # SQLite DB lives here
    ├── logs/                       # Script and auth logs
    ├── .env.example                # Sample configuration (docs/defaults)
    └── README.md                   # This file

---

## 🧪 Optional: Run Scripts Manually (inside the Container)

Open a shell inside the running container:

    docker compose exec plex-playlist bash

From there you can run:

    python scripts/populateShows.py
    python scripts/getEpisodes.py
    python scripts/newPlaylist.py
    python scripts/generatePlaylist.py

Handy for debugging.

---

## 🗃️ Data & Logs on Your Host

- Database:  `./database/plex_playlist.db`
- Logs:      `./logs/*.log`
- Config:    `./.env` (auto-written by the setup wizard)

These paths are mounted into the container, so they persist across updates.

---

## 🔁 Upgrading

If you cloned with Git:

    git pull
    docker compose up -d --build

If you used a ZIP:
- Download the new ZIP and replace the files (keep your `database/` and `logs/` folders).
- Then run:

    docker compose up -d --build

---

## 🧼 Reset / Start Fresh

    docker compose down
    rm -rf database/*.db logs/*
    docker compose build --no-cache
    docker compose up -d

---

## 🛠 Troubleshooting

**Can’t find/connect to Plex?**
- Make sure Plex is running and reachable on your network.
- Use the **Plex Sign-In** in the Setup page (it auto-discovers your servers).
- If your Plex uses a self‑signed cert, uncheck “Verify SSL” in the wizard when saving.

**Playlist didn’t fill?**
- Ensure you selected shows and assigned **unique** timeslots before generating.
- Re-run **Initialize / Refresh TV Shows** if you recently added libraries.

**Advanced: token/URL mismatches**
- Use the debug tool (inside the container):

      python scripts/plex_debug_dump.py --url "YOUR_PLEX_URL" --token "YOUR_TOKEN" --verify true

- You can also pass `--compare-url "a_plex_url_that_contains_X-Plex-Token=..."` to compare tokens.

---

## 🧠 How It Works

1. Setup wizard logs into Plex (PIN), discovers your servers, and saves the correct **server token** to `.env`.
2. You choose TV libraries and shows (via the UI).
3. Scripts populate the database and create an empty playlist.
4. Episodes are added in **round‑robin** order by timeslot.

Schema created on first run:

- `allShows(id, title, total_episodes)`
- `playlistShows(id, title, total_episodes, timeSlot)`
- `playlistEpisodes(ratingKey, season, episode, releaseDate, duration, summary, watchedStatus, title, episodeTitle, show_id, timeSlot)`

---

## 🧰 Unraid (and other NAS) Notes

You can run this anywhere that supports Docker. Two common approaches on Unraid:

**A) Using Docker Compose (recommended)**
- SSH into your Unraid box (or use the Compose Manager plugin).
- Place the project folder somewhere persistent (e.g., `/mnt/user/appdata/plex-tv-playlist-app`).
- From that folder:

      docker compose up -d

- Access on your LAN: `http://<unraid-ip>:8080`

**B) Using plain Docker commands**
- Build the image:

      docker build -t plex-tv-playlist-app .

- Run the container:

      docker run -d \
        --name plex-playlist \
        -p 8080:80 \
        -v $(pwd)/database:/var/www/html/database \
        -v $(pwd)/logs:/var/www/html/logs \
        plex-tv-playlist-app

---

## 🔐 Security Tips

- Treat your `PLEX_TOKEN` like a password (the wizard saves it to `.env`).
- Don’t post real tokens or IPs publicly.
- If exposing the web UI outside your LAN, use a reverse proxy with TLS and auth.

---

## 🤝 Contributing

PRs welcome! Please:
- Keep `.env.example` current.
- Don’t commit real tokens or `.env`.
- Follow the log style: `[INFO]`, `[WARN]`, `[SUCCESS]`, `[ERROR]`.

---

## 📜 License

MIT — see `LICENSE`.
