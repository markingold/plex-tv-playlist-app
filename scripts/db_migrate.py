#!/usr/bin/env python3
import os, sqlite3, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DB = ROOT / 'database' / 'plex_playlist.db'
DB.parent.mkdir(parents=True, exist_ok=True)

SQL = """
CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

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
