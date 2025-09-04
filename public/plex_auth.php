<?php
declare(strict_types=1);

/**
 * public/plex_auth.php
 *
 * Goals:
 *  - Always save a **server access token** (never the PIN/account token).
 *  - Prefer resource.accessToken first, then connection-URI token, then PIN as last resort.
 *  - Probe /identity with each candidate token+URL and save only the first that works.
 *  - Persist PLEX_SERVER_ID (machine/clientIdentifier) alongside URL/token.
 *  - Rich logging to logs/plex_auth.log and structured debug info back to the UI.
 */

require __DIR__ . '/_env.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
header('Content-Type: application/json');

// -------- paths & logging --------
$ROOT = realpath(__DIR__ . '/..');
$LOGF = $ROOT . '/logs/plex_auth.log';
@is_dir($ROOT . '/logs') || @mkdir($ROOT . '/logs', 0775, true);

function log_line(string $event, array $data = []): void {
    global $LOGF;
    $rec = [
        'ts'    => gmdate('Y-m-d\TH:i:s.v\Z'),
        'event' => $event,
        'data'  => $data,
    ];
    @file_put_contents($LOGF, json_encode($rec, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
}

// ---------- client identity ----------
$clientId = $_SESSION['plex_client_id'] ??= bin2hex(random_bytes(16));
$product  = 'Plex Toolbox';
$version  = '1.0';
$device   = 'Web';
$platform = 'DockerPHP';

function plex_headers(string $clientId, ?string $token = null): array {
    global $product, $version, $device, $platform;
    $h = [
        'Accept: application/json',
        'X-Plex-Product: ' . $product,
        'X-Plex-Version: ' . $version,
        'X-Plex-Client-Identifier: ' . $clientId,
        'X-Plex-Device: ' . $device,
        'X-Plex-Platform: ' . $platform,
    ];
    if ($token) $h[] = 'X-Plex-Token: ' . $token;
    return $h;
}

/** HTTP JSON helper */
function http_json(string $method, string $url, array $headers, array $body = null, bool $verify = true): array {
    $ch = curl_init($url);

    $curlHeaders = [];
    foreach ($headers as $h) {
        if (is_string($h)) { $curlHeaders[] = $h; continue; }
        if (is_array($h)) { foreach ($h as $k => $v) $curlHeaders[] = $k . ': ' . $v; }
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => $curlHeaders,
    ];

    if (stripos($url, 'https://') === 0) {
        $opts[CURLOPT_SSL_VERIFYPEER] = $verify ? 1 : 0;
        $opts[CURLOPT_SSL_VERIFYHOST] = $verify ? 2 : 0;
    }

    $methodU = strtoupper($method);
    if ($methodU === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
    } elseif ($methodU !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $methodU;
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = http_build_query($body);
    }

    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    curl_close($ch);

    $json = null;
    if ($res !== false && $res !== '') {
        $json = json_decode($res, true);
    }

    return [$json, $code, $err ?: null, $res];
}

// ----- helpers -----
function array_unique_by_uri(array $arr): array {
    $seen = [];
    $out  = [];
    foreach ($arr as $s) {
        $k = $s['uri'] ?? '';
        if (!$k || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $s;
    }
    return $out;
}

function is_server_resource(array $r): bool {
    $provides = strtolower((string)($r['provides'] ?? ''));
    if (strpos($provides, 'server') !== false) return true;
    $prod = strtolower((string)($r['product'] ?? ''));
    return (strpos($prod, 'plex media server') !== false);
}

function parse_token_from_uri(string $uri): ?string {
    $parts = parse_url($uri);
    if (!is_array($parts)) return null;
    if (!isset($parts['query'])) return null;
    parse_str($parts['query'], $qs);
    $tok = $qs['X-Plex-Token'] ?? $qs['x-plex-token'] ?? null;
    return is_string($tok) && $tok !== '' ? $tok : null;
}

function preview_token(?string $t): ?string {
    if (!$t) return null;
    return substr($t, 0, 6) . '...';
}

/**
 * Build server list where each entry has:
 *  - name, id (clientIdentifier), uri, local, https
 *  - tokens: ordered list of candidate tokens (strings)
 *  - token_sources: parallel list of sources for each token: 'access' | 'uri' | 'pin'
 *
 * Order is **critical**: access token first, then uri token, then pin.
 */
function build_server_list(array $resources, string $fallbackToken): array {
    $servers = [];
    foreach ($resources as $r) {
        if (!is_array($r) || !is_server_resource($r)) continue;

        $name         = $r['name'] ?? 'Server';
        $serverId     = $r['clientIdentifier'] ?? null;   // machine/server id
        $accessToken  = $r['accessToken'] ?? null;        // server access token (best)
        $connections  = is_array($r['connections'] ?? null) ? $r['connections'] : [];

        foreach ($connections as $c) {
            if (!is_array($c)) continue;
            $uri     = $c['uri'] ?? null;
            if (!$uri) continue;

            $isLocal = (bool)($c['local'] ?? false);
            $isHttps = (strtolower((string)($c['protocol'] ?? '')) === 'https') || (bool)($c['https'] ?? false);

            // Collect candidates with strong preference order
            $candidates = [];
            $sources    = [];

            // 1) accessToken from resource
            if ($accessToken && !in_array($accessToken, $candidates, true)) {
                $candidates[] = $accessToken;
                $sources[]    = 'access';
            }

            // 2) token embedded in connection URI
            $uriTok = parse_token_from_uri($uri);
            if ($uriTok && !in_array($uriTok, $candidates, true)) {
                $candidates[] = $uriTok;
                $sources[]    = 'uri';
            }

            // 3) fallback: PIN/account token
            if ($fallbackToken && !in_array($fallbackToken, $candidates, true)) {
                $candidates[] = $fallbackToken;
                $sources[]    = 'pin';
            }

            $servers[] = [
                'name'          => $name,
                'id'            => $serverId,
                'uri'           => $uri,
                'local'         => $isLocal,
                'https'         => $isHttps,
                'tokens'        => $candidates,
                'token_sources' => $sources,
                // for UI/debug only (no secrets)
                'token_previews'=> array_map('preview_token', $candidates),
            ];
        }
    }

    $servers = array_unique_by_uri($servers);

    if (empty($servers)) {
        $servers[] = [
            'name'          => 'Localhost (same machine)',
            'id'            => null,
            'uri'           => 'http://localhost:32400',
            'local'         => true,
            'https'         => false,
            'tokens'        => [$fallbackToken],
            'token_sources' => ['pin'],
            'token_previews'=> [preview_token($fallbackToken)],
        ];
        $servers[] = [
            'name'          => 'Docker host (host.docker.internal)',
            'id'            => null,
            'uri'           => 'http://host.docker.internal:32400',
            'local'         => true,
            'https'         => false,
            'tokens'        => [$fallbackToken],
            'token_sources' => ['pin'],
            'token_previews'=> [preview_token($fallbackToken)],
        ];
    }
    return $servers;
}

function remap_localhost_for_container(string $url): string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return $url;
    $host = strtolower($parts['host']);
    if ($host === 'localhost' || $host === '127.0.0.1') {
        $scheme = $parts['scheme'] ?? 'http';
        $port   = $parts['port'] ?? (($scheme === 'https') ? 443 : 32400);
        return $scheme . '://host.docker.internal:' . $port;
    }
    return $url;
}

function probe_identity(string $url, string $token, bool $verify): array {
    $ch = curl_init(rtrim($url, '/') . '/identity');
    $hdrs = [
        'Accept: application/json',
        'X-Plex-Token: ' . $token,
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => $hdrs,
    ];
    if (stripos($url, 'https://') === 0) {
        $opts[CURLOPT_SSL_VERIFYPEER] = $verify ? 1 : 0;
        $opts[CURLOPT_SSL_VERIFYHOST] = $verify ? 2 : 0;
    }
    curl_setopt_array($ch, $opts);
    $res  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0;
    $json = null;
    if ($res !== false && $res !== '') {
        $json = json_decode($res, true);
    }
    curl_close($ch);
    return [
        'url' => $url,
        'code'=> $code,
        'err' => $err ?: null,
        'ok'  => ($res !== false && $code > 0 && $code < 400),
        'identity' => is_array($json) ? [
            'machineIdentifier' => $json['machineIdentifier'] ?? null,
            'version'           => $json['version'] ?? null,
            'device'            => $json['device'] ?? null,
            'platform'          => $json['platform'] ?? null,
        ] : null,
    ];
}

// ---- actions ----
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'start') {
        log_line('action_start', ['clientId' => $clientId]);

        [$json, $code, $err, $raw] = http_json(
            'POST',
            'https://plex.tv/api/v2/pins?strong=true',
            plex_headers($clientId),
            [],
            true
        );
        $debug = [
            'pin_start_http_code' => $code,
            'pin_start_error'     => $err,
        ];
        log_line('pin_start_response', [
            'code'        => $code,
            'error'       => $err,
            'json_fields' => $json ? array_keys($json) : null,
            'raw_preview' => is_string($raw) ? substr($raw, 0, 300) : null
        ]);

        if (!$json || $err || $code >= 400) {
            echo json_encode(['ok' => false, 'error' => $err ?: ('HTTP ' . $code), 'debug' => $debug]); exit;
        }

        $pinId     = (int)($json['id'] ?? 0);
        $pinCode   = (string)($json['code'] ?? '');
        $expiresAt = (string)($json['expiresAt'] ?? '');
        $_SESSION['plex_pin_id'] = $pinId;

        $deeplink = 'https://app.plex.tv/auth#?' . http_build_query([
            'clientID'                  => $clientId,
            'code'                      => $pinCode,
            'context[device][product]'  => $product,
            'context[device][version]'  => $version,
            'context[device][platform]' => $platform,
        ]);

        log_line('pin_created', ['pinId' => $pinId, 'code' => $pinCode, 'deeplink' => $deeplink]);

        echo json_encode([
            'ok'        => true,
            'pinId'     => $pinId,
            'code'      => $pinCode,
            'expiresAt' => $expiresAt,
            'deeplink'  => $deeplink,
            'debug'     => $debug,
        ]);
        exit;
    }

    if ($action === 'poll') {
        log_line('action_poll', ['clientId' => $clientId]);

        $pinId = (int)($_GET['pinId'] ?? $_POST['pinId'] ?? 0);
        if ($pinId <= 0 || ($pinId !== (int)($_SESSION['plex_pin_id'] ?? 0))) {
            echo json_encode(['ok' => false, 'error' => 'Invalid or unknown PIN id']); exit;
        }

        [$pinJson, $code, $err, $raw] = http_json(
            'GET',
            'https://plex.tv/api/v2/pins/' . $pinId,
            plex_headers($clientId),
            null,
            true
        );
        log_line('pin_poll_response', [
            'code'        => $code,
            'error'       => $err,
            'json_fields' => $pinJson ? array_keys($pinJson) : null,
            'raw_preview' => is_string($raw) ? substr($raw, 0, 300) : null
        ]);

        if (!$pinJson || $err || $code >= 400) {
            echo json_encode(['ok' => false, 'error' => $err ?: ('HTTP ' . $code)]); exit;
        }

        $authToken = $pinJson['authToken'] ?? null; // PIN/account token
        if (!$authToken) {
            echo json_encode(['ok' => true, 'pending' => true]); exit;
        }

        // Discover resources using the PIN token
        [$resources, $rc, $re, $rraw] = http_json(
            'GET',
            'https://plex.tv/api/v2/resources?includeHttps=1&includeRelay=1&includeIPv6=1&includeTokens=1',
            plex_headers($clientId, $authToken),
            null,
            true
        );

        if (!$resources || $re || $rc >= 400) {
            log_line('resources_response', [
                'code'        => $rc,
                'error'       => $re,
                'raw_preview' => is_string($rraw) ? substr($rraw, 0, 300) : null
            ]);
            echo json_encode(['ok' => false, 'error' => 'Token ok, but resource discovery failed']); exit;
        }

        $servers = build_server_list($resources, $authToken);
        $_SESSION['plex_servers'] = $servers; // keep tokens per server/uri

        // Debug summaries (no secrets)
        $debugServers = [];
        foreach ($servers as $s) {
            $debugServers[] = [
                'name'          => $s['name'] ?? null,
                'id'            => $s['id'] ?? null,
                'uri'           => $s['uri'] ?? null,
                'local'         => $s['local'] ?? null,
                'https'         => $s['https'] ?? null,
                'token_sources' => $s['token_sources'] ?? [],
                'token_previews'=> $s['token_previews'] ?? [],
            ];
        }

        log_line('servers_built', ['count' => count($servers), 'servers_debug' => $debugServers]);

        echo json_encode([
            'ok'      => true,
            'token'   => $authToken, // informational (PIN), not what we’ll save
            'servers' => $servers,
            'debug'   => [
                'resources_http_code' => $rc,
                'servers_found'       => count($servers),
                'servers'             => $debugServers,
            ],
        ]);
        exit;
    }

    if ($action === 'save') {
        $postedToken = (string)($_POST['token'] ?? '');      // PIN token from UI (may be wrong)
        $serverUrl   = (string)($_POST['serverUrl'] ?? '');
        $serverId    = (string)($_POST['serverId'] ?? '');
        $verifySsl   = strtolower((string)($_POST['verifySsl'] ?? 'false')) === 'true';
        $forceSave   = strtolower((string)($_POST['forceSave'] ?? 'false')) === 'true';

        log_line('action_save_begin', [
            'serverUrl' => $serverUrl,
            'verifySsl' => $verifySsl,
            'postedTokenPreview' => $postedToken ? substr($postedToken, 0, 6) . '...' : null
        ]);

        if (!$serverUrl){ echo json_encode(['ok'=>false,'error'=>'Missing serverUrl']); exit; }

        $servers = $_SESSION['plex_servers'] ?? [];
        $matched = null;

        // Prefer exact URI match; fallback to host:port match
        foreach ($servers as $s) {
            if (!empty($s['uri']) && rtrim((string)$s['uri'],'/') === rtrim($serverUrl,'/')) { $matched = $s; break; }
        }
        if (!$matched) {
            $want = parse_url($serverUrl);
            foreach ($servers as $s) {
                $have = parse_url((string)($s['uri'] ?? ''));
                if ($want && $have && (($want['host'] ?? null) === ($have['host'] ?? null)) && (($want['port'] ?? null) === ($have['port'] ?? null))) {
                    $matched = $s; break;
                }
            }
        }

        // Candidate URLs to probe (include remapped localhost variant)
        $candidates = [];
        $addUrl = function(string $u) use (&$candidates) {
            $u = rtrim($u, '/');
            if (!preg_match('#^https?://#i', $u)) return;
            if (!in_array($u, $candidates, true)) $candidates[] = $u;
        };
        $addUrl($serverUrl);
        $remapped = remap_localhost_for_container($serverUrl);
        if ($remapped !== $serverUrl) $addUrl($remapped);

        // Candidate tokens: order already enforced in build_server_list()
        $candidateTokens = [];
        $candidateSources = [];
        if ($matched && !empty($matched['tokens']) && is_array($matched['tokens'])) {
            foreach ($matched['tokens'] as $idx => $t) {
                if ($t && !in_array($t, $candidateTokens, true)) {
                    $candidateTokens[]  = $t;
                    $candidateSources[] = $matched['token_sources'][$idx] ?? 'unknown';
                }
            }
        }
        // As a final fallback, include the posted PIN token if not already present
        if ($postedToken && !in_array($postedToken, $candidateTokens, true)) {
            $candidateTokens[]  = $postedToken;
            $candidateSources[] = 'pin(posted)';
        }

        // Probe to find the first working token+url
        $probes = [];
        $okUrl = null;
        $okToken = null;
        $okSource = null;
        $okIdentity = null;

        foreach ($candidates as $u) {
            foreach ($candidateTokens as $i => $t) {
                $probe = probe_identity($u, $t, $verifySsl);
                $entry = [
                    'candidateUrl' => $u,
                    'tokenPreview' => preview_token($t),
                    'tokenSource'  => $candidateSources[$i] ?? 'unknown',
                    'code'         => $probe['code'],
                    'err'          => $probe['err'],
                    'ok'           => $probe['ok'],
                    'identity'     => $probe['identity'],
                ];
                $probes[] = $entry;
                if ($probe['ok']) {
                    // If serverId is provided, prefer matches; otherwise accept first ok
                    $identityId = $probe['identity']['machineIdentifier'] ?? null;
                    if ($serverId && $identityId && strcasecmp($serverId, $identityId) !== 0) {
                        // keep probing for matching server
                        continue;
                    }
                    $okUrl      = $u;
                    $okToken    = $t;
                    $okSource   = $candidateSources[$i] ?? 'unknown';
                    $okIdentity = $probe['identity'];
                    break 2;
                }
            }
        }

        log_line('save_probes', [
            'candidates' => $candidates,
            'tokensTried'=> array_map(function($t, $src){ return ($src ?? 'unknown') . ':' . preview_token($t); }, $candidateTokens, $candidateSources),
            'result'     => [
                'okUrl'        => $okUrl,
                'okTokenPreview'=> $okToken ? preview_token($okToken) : null,
                'okTokenSource'=> $okSource,
                'identity'     => $okIdentity,
            ],
            'matchedId'  => $matched['id'] ?? null,
            'requestedServerId' => $serverId ?: null,
        ]);

        if ((!$okUrl || !$okToken) && !$forceSave) {
            echo json_encode([
                'ok'     => false,
                'error'  => 'Could not reach Plex with any candidate token/url',
                'probes' => $probes,
                'debug'  => [
                    'candidate_urls' => $candidates,
                    'candidate_tokens' => array_map(function($t, $src){ return ['src'=>$src, 'preview'=>preview_token($t)]; }, $candidateTokens, $candidateSources),
                ],
            ]);
            exit;
        }

        $finalUrl   = rtrim($okUrl ?: $serverUrl, '/');
        $finalToken = $okToken ?: ($candidateTokens[0] ?? $postedToken);
        $finalId    = $serverId ?: ($matched['id'] ?? '');

        try {
            env_write_updates([
                'PLEX_URL'        => $finalUrl,
                'PLEX_TOKEN'      => (string)$finalToken, // server token preferred
                'PLEX_VERIFY_SSL' => $verifySsl ? 'true' : 'false',
                'PLEX_SERVER_ID'  => (string)$finalId,
            ]);
        } catch (Throwable $e) {
            log_line('save_env_failed', ['message' => $e->getMessage()]);
            echo json_encode(['ok'=>false,'error'=>'Failed writing .env']); exit;
        }

        log_line('save_env_ok', [
            'PLEX_URL'     => $finalUrl,
            'serverId'     => $finalId ?: null,
            'chosenSource' => $okSource ?: null,
            'tokenNote'    => 'server access token preferred; may be from resource.accessToken or connection URI',
        ]);

        echo json_encode([
            'ok'        => true,
            'chosenUrl' => $finalUrl,
            'serverId'  => $finalId,
            'probes'    => $probes,
            'debug'     => [
                'chosen_source' => $okSource,
                'identity'      => $okIdentity,
            ],
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;

} catch (Throwable $e) {
    log_line('exception', ['message' => $e->getMessage()]);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]); exit;
}
