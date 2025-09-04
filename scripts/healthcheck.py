#!/usr/bin/env python3
import os
import sys
import json
import requests
from dotenv import load_dotenv
from plexapi.server import PlexServer
from urllib.parse import urlparse, urlunparse

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV = os.path.join(ROOT, '.env')
if os.path.exists(ENV):
    load_dotenv(ENV, override=True)

URL = os.getenv('PLEX_URL', '').strip()
TOK = os.getenv('PLEX_TOKEN', '').strip()
VERIFY = os.getenv('PLEX_VERIFY_SSL', 'false').strip().lower() in ('1', 'true', 'yes')

def remap_localhost_for_container(url: str) -> str:
    """If URL points to localhost/127.0.0.1, remap to host.docker.internal for Docker-on-host use."""
    try:
        u = urlparse(url or '')
        host = (u.hostname or '').lower()
        if host in ('localhost', '127.0.0.1'):
            port = u.port or (443 if (u.scheme or 'http') == 'https' else 32400)
            netloc = f"host.docker.internal:{port}"
            return urlunparse((
                (u.scheme or 'http'),
                netloc,
                u.path or '',
                u.params or '',
                u.query or '',
                u.fragment or ''
            ))
    except Exception:
        pass
    return url

# Remap if needed
URL = remap_localhost_for_container(URL)

def main():
    if not URL or not TOK:
        print(json.dumps({'ok': False, 'error': 'Missing PLEX_URL or PLEX_TOKEN'}))
        return 1
    try:
        session = requests.Session()
        session.verify = True if VERIFY else False
        plex = PlexServer(URL, TOK, session=session)
        sections = plex.library.sections()
        out = [{
            'key': getattr(s, 'key', None),
            'title': getattr(s, 'title', ''),
            'type': getattr(s, 'type', '')
        } for s in sections]
        print(json.dumps({'ok': True, 'sections': out}))
        return 0
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e)}))
        return 1

if __name__ == '__main__':
    sys.exit(main())
