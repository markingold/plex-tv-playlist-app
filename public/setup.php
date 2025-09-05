<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_csrf.php';
require __DIR__ . '/_env.php';

$dbFilePath = __DIR__ . '/../database/plex_playlist.db';
function open_db(string $p): PDO { $pdo = new PDO("sqlite:" . $p); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

// optional migration (settings + indexes)
function run_migration(): void {
    $ROOT = realpath(__DIR__ . '/..');
    $log = $ROOT . '/logs/migrate_' . date('Ymd_His') . '.log';
    @run_py_logged('db_migrate.py', [], $log);
}
run_migration();

$feedback = null; $error = null; $sections = [];

// Handle Manual test / library selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    if (isset($_POST['action']) && $_POST['action'] === 'test') {
        $r = run_py_logged('healthcheck.py', [], null);
        if ($r['exit_code'] === 0) {
            $j = json_decode($r['stdout'], true);
            if (!empty($j['ok'])) {
                $sections = $j['sections'] ?? [];
                $feedback = "Connected to Plex. Found ".count($sections)." libraries.";
            } else {
                $error = "Healthcheck failed: ".($j['error'] ?? 'Unknown');
            }
        } else { $error = "healthcheck.py failed. STDERR:\n".$r['stderr']; }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save_sections' && isset($_POST['tv_sections'])) {
        try {
            $conn = open_db($dbFilePath);
            $conn->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
            $keys = array_map('strval', (array)$_POST['tv_sections']);
            $csv = implode(',', $keys);
            $stmt = $conn->prepare("INSERT INTO settings(key, value) VALUES('tv_section_keys', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $stmt->execute([$csv]);
            $conn = null;
            $feedback = "Saved TV library selection.";
        } catch (Throwable $e) { $error = 'Save failed: ' . $e->getMessage(); }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'init') {
        $logFile = realpath(__DIR__.'/..').'/logs/populate_' . date('Ymd_His') . '.log';
        $res = run_py_logged('populateShows.py', [], $logFile);
        if ($res['exit_code'] === 0) { $feedback = "Initialized database from Plex."; }
        else { $error = "populateShows.py failed. See logs."; }
    }
}

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>
<div class="container py-4" style="max-width:900px;">
  <h2 class="mb-3">Setup</h2>
  <p class="text-muted">Sign in with Plex to auto‑discover your server and save the connection. You can also run a connectivity test and pick libraries manually.</p>

  <?php if ($feedback): ?><div class="alert alert-success" style="white-space:pre-wrap;"><?= htmlspecialchars((string)$feedback) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger" style="white-space:pre-wrap;"><?= htmlspecialchars((string)$error) ?></div><?php endif; ?>

  <!-- Sign in with Plex (PIN) -->
  <div class="card mb-4 bg-dark text-light border border-warning-subtle">
    <div class="card-body">
      <h5 class="card-title">Sign in with Plex</h5>
      <div id="plex-auth-area">
        <button id="plex-auth-start" class="btn btn-warning">Sign in with Plex</button>

        <div id="plex-auth-step" class="mt-3" style="display:none;">
          <p>We opened Plex in a new tab to approve access. If nothing opened, allow pop‑ups for this site and click the button again.</p>

          <!-- Advanced details for power users -->
          <details id="plex-auth-advanced" class="mt-2">
            <summary>Advanced: show PIN details</summary>
            <div class="small text-muted mt-2">
              <div>Code: <code id="plex-auth-code">—</code></div>
              <div>Expires at: <code id="plex-auth-exp">—</code></div>
              <div>Token (after approval): <code id="plex-auth-token">—</code></div>
              <div class="mt-1">Auth URL: <code id="plex-auth-link"></code></div>
            </div>
          </details>
        </div>

        <div id="plex-server-pick" class="mt-3" style="display:none;">
          <h6>Choose your Plex Server</h6>
          <div id="plex-server-list" class="mb-2"></div>

          <!-- Advanced options: manual URL + SSL toggle -->
          <details id="plex-advanced-options" class="mt-2">
            <summary>Advanced options</summary>
            <div class="mt-2">
              <label for="manual-server-url" class="form-label small text-muted mb-1">Manual server URL (optional)</label>
              <input type="text" class="form-control bg-dark text-light border-secondary" id="manual-server-url"
                     placeholder="http://192.168.x.x:32400">
              <div class="form-check form-switch mt-3">
                <input class="form-check-input" type="checkbox" id="plex-verify-ssl" checked>
                <label class="form-check-label" for="plex-verify-ssl">Verify SSL (use for valid HTTPS)</label>
              </div>
            </div>
          </details>

          <button id="plex-save-env" class="btn btn-success mt-3">Save to .env</button>
          <div id="plex-save-result" class="mt-2 small"></div>
        </div>

        <details class="mt-3">
          <summary>Debug log</summary>
          <pre id="plex-debug-log" class="bg-black text-warning p-2" style="max-height:240px; overflow:auto; white-space:pre-wrap;"></pre>
        </details>
      </div>
    </div>
  </div>

  <!-- Manual test / library selection -->
  <div class="card mb-4 bg-dark text-light border border-warning-subtle">
    <div class="card-body">
      <h5 class="card-title">Manual Connectivity Test</h5>
      <form method="post" class="mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test">
        <button class="btn btn-secondary" type="submit">Test Plex Connection (from current .env)</button>
      </form>

      <?php if (!empty($sections)): ?>
        <form method="post" class="mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_sections">
          <div class="mb-2"><strong>TV Libraries (type=show):</strong></div>
          <?php foreach ($sections as $s):
              if (($s['type'] ?? '') !== 'show') continue;
              $key = htmlspecialchars((string)($s['key'] ?? ''), ENT_QUOTES, 'UTF-8');
              $title = htmlspecialchars((string)($s['title'] ?? ''), ENT_QUOTES, 'UTF-8');
          ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="tv_sections[]" value="<?= $key ?>" id="lib_<?= $key ?>">
              <label class="form-check-label" for="lib_<?= $key ?>"><?= $title ?> (key: <?= $key ?>)</label>
            </div>
          <?php endforeach; ?>
          <div class="mt-3">
            <button class="btn btn-primary" type="submit">Save Selection</button>
          </div>
        </form>
      <?php endif; ?>

      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="init">
        <button class="btn btn-warning" type="submit">Initialize Database / Refresh Shows</button>
      </form>
    </div>
  </div>
</div>

<script>
// ---- Debug logger ----
const logEl = document.getElementById('plex-debug-log');
function log(msg, obj) {
  const ts = new Date().toISOString();
  const line = `[${ts}] ${msg}`;
  logEl.textContent += line + (obj ? ' ' + JSON.stringify(obj, null, 2) : '') + "\n";
  logEl.scrollTop = logEl.scrollHeight;
}

// ---- PIN flow (simplified UI; advanced details in <details>) ----
const startBtn = document.getElementById('plex-auth-start');
const stepDiv  = document.getElementById('plex-auth-step');
const codeEl   = document.getElementById('plex-auth-code');
const expEl    = document.getElementById('plex-auth-exp');
const tokenEl  = document.getElementById('plex-auth-token');
const linkCode = document.getElementById('plex-auth-link');

const pickDiv  = document.getElementById('plex-server-pick');
const listDiv  = document.getElementById('plex-server-list');
const saveBtn  = document.getElementById('plex-save-env');
const saveRes  = document.getElementById('plex-save-result');
const sslChk   = document.getElementById('plex-verify-ssl');

let pinId = null;
let token = null;
let deeplink = null;
let pollTimer = null;

// Small helper around fetch -> JSON
async function api(action, payload) {
  const res = await fetch('plex_auth.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action, ...payload})
  });
  return res.json();
}

startBtn?.addEventListener('click', async () => {
  try {
    startBtn.disabled = true;
    log('Starting Plex PIN flow...');
    const j = await api('start', {});
    log('API start <-', j);
    if (!j.ok) { alert('Failed to start Plex auth: ' + (j.error||'')); startBtn.disabled = false; return; }

    pinId = j.pinId;
    deeplink = j.deeplink || '';
    stepDiv.style.display = '';

    // Fill advanced fields
    codeEl.textContent = j.code || '—';
    expEl.textContent  = j.expiresAt || '—';
    linkCode.textContent = deeplink || '—';

    // Attempt to open Plex Auth automatically
    if (deeplink) {
      const w = window.open(deeplink, '_blank');
      if (!w) {
        log('Popup blocked; user must allow pop-ups or copy the Auth URL from Advanced.');
      }
    }

    // Poll until token
    pollTimer = setInterval(async () => {
      const r = await api('poll', {pinId});
      log('API poll <-', r);
      if (!r.ok) { clearInterval(pollTimer); alert('Auth failed: ' + (r.error||'')); startBtn.disabled = false; return; }
      if (r.pending) return;

      clearInterval(pollTimer);
      token = r.token;
      tokenEl.textContent = token ? (token.slice(0,6) + '...') : '—';

      // ===== Simplified server picker: Recommended + Advanced =====
      listDiv.innerHTML = '';

      const serversRaw = Array.isArray(r.servers) ? r.servers : [];
      // Normalize & de-dup by URI
      const seen = new Set();
      const servers = [];
      for (const s of serversRaw) {
        if (!s || !s.uri) continue;
        const uri = String(s.uri).replace(/\/+$/,'');
        if (seen.has(uri)) continue;
        seen.add(uri);
        servers.push({
          name: s.name || 'Plex Server',
          uri,
          id: s.id || s.clientIdentifier || '',
          local: !!s.local,
          https: !!s.https || /^https:\/\//i.test(uri),
        });
      }

      // Ranking: local+https > https > local > anything
      const score = (x) => (x.local && x.https ? 4 : x.https ? 3 : x.local ? 2 : 1);
      servers.sort((a,b) => score(b) - score(a));

      const fmtBadges = (s) => {
        const badges = [];
        if (s.local)  badges.push('<span class="badge text-bg-secondary ms-2">Local</span>');
        if (s.https)  badges.push('<span class="badge text-bg-success ms-1">HTTPS</span>');
        return badges.join('');
      };
      const hostPort = (u) => { try { const uu = new URL(u); return uu.host; } catch { return u; } };

      // Recommended
      const recommended = servers[0];
      if (recommended) {
        const id = 'srv_recommended';
        const row = document.createElement('div');
        row.className = 'form-check mb-2';
        row.innerHTML = `
          <div class="mb-1 small text-muted">Recommended</div>
          <input class="form-check-input" type="radio" name="serverUrl" value="${recommended.uri}" id="${id}" checked
                 data-server-id="${recommended.id}">
          <label class="form-check-label" for="${id}">
            ${recommended.name} — ${hostPort(recommended.uri)} ${fmtBadges(recommended)}
            ${recommended.id ? `<br><small class="text-muted">id: ${recommended.id}</small>` : ''}
          </label>`;
        listDiv.appendChild(row);
      }

      // Advanced (collapsed): alternate routes
      if (servers.length > 1) {
        const details = document.createElement('details');
        details.className = 'mt-2';
        const count = servers.length - 1;
        details.innerHTML = `<summary>Advanced: show ${count} alternate route${count===1?'':'s'}</summary>`;
        const advWrap = document.createElement('div');
        advWrap.className = 'mt-2';

        servers.slice(1).forEach((s, idx) => {
          const id = `srv_alt_${idx}`;
          const row = document.createElement('div');
          row.className = 'form-check mb-1';
          row.innerHTML = `
            <input class="form-check-input" type="radio" name="serverUrl" value="${s.uri}" id="${id}"
                   data-server-id="${s.id}">
            <label class="form-check-label" for="${id}">
              ${s.name} — ${hostPort(s.uri)} ${fmtBadges(s)}
              ${s.id ? `<br><small class="text-muted">id: ${s.id}</small>` : ''}
            </label>`;
          advWrap.appendChild(row);
        });

        details.appendChild(advWrap);
        listDiv.appendChild(details);
      }

      pickDiv.style.display = '';
      saveRes.textContent = 'Signed in! Pick the recommended server or open Advanced for alternates / manual settings.';
    }, 1500);
  } catch (e) {
    log('Exception during start', {error: String(e)});
    startBtn.disabled = false;
  }
});

saveBtn?.addEventListener('click', async () => {
  const checked = listDiv.querySelector('input[name="serverUrl"]:checked');
  const manual  = document.getElementById('manual-server-url');

  const serverUrl = (checked?.value || manual?.value || '').trim();
  const serverId  = (checked?.dataset?.serverId || '').trim();

  if (!token) { alert('No Plex token available. Please sign in again.'); return; }
  if (!serverUrl) { alert('Choose a server or enter a server URL.'); return; }
  if (!/^https?:\/\//i.test(serverUrl)) { alert('Please include http:// or https:// in the server URL.'); return; }

  saveBtn.disabled = true;
  saveRes.textContent = 'Saving...';

  // --- 15s client-side timeout so UI never sticks ---
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort('timeout'), 15000);

  try {
    const body = new URLSearchParams({
      action: 'save',
      token,                 // kept for backward compatibility; backend prefers per-server token from session
      serverUrl,
      serverId,              // optional echo
      verifySsl: (document.getElementById('plex-verify-ssl')?.checked ? 'true' : 'false')
    });

    const res = await fetch('plex_auth.php', {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body,
      signal: ctrl.signal
    });
    const j = await res.json().catch(() => ({}));
    clearTimeout(t);

    log('API save <-', j);

    if (!j.ok) {
      if (Array.isArray(j.probes)) {
        const lines = j.probes.map(p => {
          return `- url=${p.candidateUrl || p.url || 'n/a'} code=${p.code ?? 'n/a'} ok=${p.ok ? 'true':'false'} err=${p.err||'n/a'}`;
        });
        saveRes.textContent = 'Save failed: ' + (j.error || 'Unknown') + '\n' + lines.join('\n');
      } else {
        saveRes.textContent = 'Save failed: ' + (j.error || 'Unknown');
      }
      saveBtn.disabled = false;
      return;
    }

    const idEcho = j.serverId ? ` (server id: ${j.serverId})` : '';
    saveRes.textContent = `Saved. Using ${j.chosenUrl}${idEcho}. You can now Initialize Database or head to Edit Shows.`;
  } catch (e) {
    clearTimeout(t);
    saveRes.textContent = 'Save failed (request aborted or timed out).';
    saveBtn.disabled = false;
  }
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
