#!/usr/bin/env python3
import os, sys, json, requests
from dotenv import load_dotenv
from plexapi.server import PlexServer

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV = os.path.join(ROOT, '.env')
if os.path.exists(ENV):
    load_dotenv(ENV)

URL = os.getenv('PLEX_URL', '').strip()
TOK = os.getenv('PLEX_TOKEN', '').strip()
VERIFY = os.getenv('PLEX_VERIFY_SSL', 'false').strip().lower() in ('1','true','yes')

def main():
    if not URL or not TOK:
        print(json.dumps({'ok': False, 'error': 'Missing PLEX_URL or PLEX_TOKEN'}))
        return 1
    try:
        s = requests.Session(); s.verify = True if VERIFY else False
        plex = PlexServer(URL, TOK, session=s)
        secs = plex.library.sections()
        out = [{'key': getattr(x,'key',None), 'title': x.title, 'type': getattr(x,'type','')} for x in secs]
        print(json.dumps({'ok': True, 'sections': out}))
        return 0
    except Exception as e:
        print(json.dumps({'ok': False, 'error': str(e)}))
        return 1

if __name__ == '__main__':
    sys.exit(main())
