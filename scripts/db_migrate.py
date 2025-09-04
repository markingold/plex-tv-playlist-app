#!/usr/bin/env python3
import os, sqlite3, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / 'database' / 'plex_playlist.db'
DB.parent.mkdir(parents=True, exist_ok=True)

SQL = """
-- Ensure tables exist before we create indexes
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
CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- Now indexes
CREATE INDEX IF NOT EXISTS idx_playlistEpisodes_slot_show
  ON playlistEpisodes(timeSlot, show_id, season, episode);

CREATE INDEX IF NOT EXISTS idx_playlistShows_id
  ON playlistShows(id);
"""

def main():
    try:
        with sqlite3.connect(DB) as conn:
            conn.executescript(SQL)
        return 0
    except Exception as e:
        print(f"[ERROR] migration failed: {e}", file=sys.stderr)
        return 1

if __name__ == '__main__':
    sys.exit(main())
