#!/usr/bin/env python3
"""
plex_debug_dump.py

Purpose:
  Exhaustively dump Plex connection details using both plexapi and raw HTTP,
  so we can diagnose token/URL issues (e.g., PIN token vs server access token).

What it does:
  - Loads .env (PLEX_URL, PLEX_TOKEN, PLEX_VERIFY_SSL).
  - (Optionally) override via CLI: --url, --token, --verify, --compare-url.
  - Remaps localhost -> host.docker.internal when not disabled via --no-remap.
  - Performs raw GETs (/identity, /:/prefs, /status/sessions) and summarizes results.
  - Connects with PlexServer and dumps high‑value fields:
      friendlyName, version, platform, machineIdentifier, myPlexUsername (if any)
      library sections (title, type)
  - If --compare-url is passed (e.g. a thumbnail/transcode URL with X-Plex-Token),
    extracts that token and compares it to the token in use.

Usage:
  python scripts/plex_debug_dump.py
  python scripts/plex_debug_dump.py --url https://192-168-...plex.direct:32400 --token ABC123 --verify true
  python scripts/plex_debug_dump.py --compare-url "http://127.0.0.1:32400/photo/...&X-Plex-Token=XYZ"

Exit codes:
  0 -> Ran all probes (even if some failed)
  2 -> Missing URL/token
"""

import os
import sys
import json
import argparse
from urllib.parse import urlparse, urlunparse, parse_qs

import requests
from dotenv import load_dotenv
from plexapi.server import PlexServer

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
ENV_PATH = os.path.join(ROOT, '.env')


def remap_localhost_for_container(url: str) -> str:
    """Map localhost/127.0.0.1 to host.docker.internal for container -> host access."""
    try:
        u = urlparse(url or '')
        h = (u.hostname or '').lower()
        if h in ('localhost', '127.0.0.1'):
            scheme = (u.scheme or 'http')
            port = u.port or (443 if scheme == 'https' else 32400)
            netloc = f"host.docker.internal:{port}"
            return urlunparse((scheme, netloc, u.path or '', u.params or '', u.query or '', u.fragment or ''))
    except Exception:
        pass
    return url


def short(s, n=600):
    if s is None:
        return None
    if isinstance(s, bytes):
        try:
            s = s.decode('utf-8', 'replace')
        except Exception:
            s = str(s)
    return s if len(s) <= n else s[:n] + f"... (+{len(s)-n} more)"


def get_bool(s):
    return str(s).strip().lower() in ('1', 'true', 'yes', 'on')


def raw_get(session: requests.Session, base: str, path: str, token: str, verify_https: bool):
    url = base.rstrip('/') + path
    # Minimal but helpful headers; X-Plex-Token authenticates most endpoints.
    headers = {
        'Accept': 'application/json, application/xml;q=0.9, */*;q=0.8',
        'X-Plex-Token': token,
        'X-Plex-Product': 'Plex Toolbox Debug',
        'X-Plex-Client-Identifier': 'plex-toolbox-debug-script',
        'X-Plex-Version': '1.0',
        'X-Plex-Platform': 'Python',
    }
    session.verify = True if verify_https else False
    try:
        r = session.get(url, timeout=10, headers=headers)
        return {
            'ok': True,
            'url': url,
            'status': r.status_code,
            'headers': dict(r.headers),
            'text_preview': short(r.text, 600),
        }
    except Exception as e:
        return {
            'ok': False,
            'url': url,
            'error': str(e),
        }


def extract_token_from_url(u: str):
    try:
        q = parse_qs(urlparse(u).query)
        t = q.get('X-Plex-Token') or q.get('x-plex-token') or []
        return t[0] if t else None
    except Exception:
        return None


def main():
    parser = argparse.ArgumentParser(description="Dump Plex connection details for debugging")
    parser.add_argument('--url', help='Override PLEX_URL')
    parser.add_argument('--token', help='Override PLEX_TOKEN')
    parser.add_argument('--verify', help='Override PLEX_VERIFY_SSL (true/false)')
    parser.add_argument('--compare-url', dest='compare_url', help='A URL containing X-Plex-Token to compare against')
    parser.add_argument('--no-remap', dest='no_remap', action='store_true',
                        help='Do not remap localhost to host.docker.internal')
    args = parser.parse_args()

    # Load env (if present)
    if os.path.exists(ENV_PATH):
        load_dotenv(ENV_PATH)

    url_env = os.getenv('PLEX_URL', '').strip()
    tok_env = os.getenv('PLEX_TOKEN', '').strip()
    verify_env = os.getenv('PLEX_VERIFY_SSL', 'false').strip()

    url = (args.url or url_env).strip()
    token = (args.token or tok_env).strip()
    verify = get_bool(args.verify if args.verify is not None else verify_env)

    if not url or not token:
        print(json.dumps({
            'ok': False,
            'error': 'Missing PLEX_URL or PLEX_TOKEN',
            'env_path': ENV_PATH,
            'have_url': bool(url),
            'have_token': bool(token),
        }, indent=2))
        sys.exit(2)

    effective_url = url if args.no_remap else remap_localhost_for_container(url)

    out = {
        'inputs': {
            'env_path': ENV_PATH,
            'env_URL': url_env,
            'env_TOKEN_len': len(tok_env),
            'env_VERIFY_SSL': verify_env,
            'cli_url_override': bool(args.url),
            'cli_token_override': bool(args.token),
            'cli_verify_override': args.verify,
            'no_remap': bool(args.no_remap),
            'effective_url': effective_url,
        },
        'raw_http': {
            'identity': None,
            'prefs': None,
            'sessions': None,
        },
        'plexapi': {
            'connected': False,
            'server': {},
            'libraries': [],
            'errors': [],
        },
        'comparison': {},
    }

    # ---- Raw probes
    sess = requests.Session()
    out['raw_http']['identity'] = raw_get(sess, effective_url, '/identity', token, verify)
    out['raw_http']['prefs']    = raw_get(sess, effective_url, '/:/prefs', token, verify)
    out['raw_http']['sessions'] = raw_get(sess, effective_url, '/status/sessions', token, verify)

    # ---- plexapi connection
    try:
        s = requests.Session()
        s.verify = True if verify else False
        plex = PlexServer(effective_url, token, session=s)
        out['plexapi']['connected'] = True

        # Server fields
        try:
            out['plexapi']['server'] = {
                'baseurl': getattr(plex, '_baseurl', None),
                'friendlyName': getattr(plex, 'friendlyName', None),
                'version': getattr(plex, 'version', None),
                'platform': getattr(plex, 'platform', None),
                'machineIdentifier': getattr(plex, 'machineIdentifier', None),
                'myPlexUsername': getattr(plex, 'myPlexUsername', None),
                # Whether this session is logged into Plex account:
                'myPlex': getattr(plex, 'myPlex', None),
            }
        except Exception as e:
            out['plexapi']['errors'].append(f"server fields: {e}")

        # Libraries
        try:
            sections = plex.library.sections()
            out['plexapi']['libraries'] = [
                {
                    'title': getattr(sec, 'title', None),
                    'type': getattr(sec, 'type', None),
                    'key': getattr(sec, 'key', None),
                } for sec in sections
            ]
        except Exception as e:
            out['plexapi']['errors'].append(f"library.sections: {e}")

    except Exception as e:
        out['plexapi']['errors'].append(f"connect: {e}")

    # ---- Optional comparison against a URL-provided token
    if args.compare_url:
        cmp_tok = extract_token_from_url(args.compare_url)
        out['comparison'] = {
            'compare_url_has_token': bool(cmp_tok),
            'compare_token': cmp_tok,
            'compare_token_len': len(cmp_tok or ''),
            'matches_env_token': (cmp_tok == token) if cmp_tok else None,
        }

    print(json.dumps(out, indent=2))
    return 0


if __name__ == '__main__':
    sys.exit(main())
