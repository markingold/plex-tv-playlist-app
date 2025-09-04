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
  <p class="text-muted">Sign in with Plex to auto-discover your servers and save your settings. You can also test connectivity and choose libraries manually.</p>

  <?php if ($feedback): ?><div class="alert alert-success" style="white-space:pre-wrap;"><?= htmlspecialchars((string)$feedback) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger" style="white-space:pre-wrap;"><?= htmlspecialchars((string)$error) ?></div><?php endif; ?>

  <!-- Sign in with Plex (PIN) -->
  <div class="card mb-4 bg-dark text-light border border-warning-subtle">
    <div class="card-body">
      <h5 class="card-title">Sign in with Plex</h5>
      <div id="plex-auth-area">
        <button id="plex-auth-start" class="btn btn-warning">Sign in with Plex</button>
        <div id="plex-auth-step" class="mt-3" style="display:none;">
          <p>Open this link and approve access. Then return here — we’ll auto-detect when it’s complete.</p>
          <p>
            <a id="plex-auth-link" href="#" target="_blank" class="btn btn-warning">Open Plex Auth</a>
          </p>
          <!-- Make these readable on dark background -->
          <p class="mb-0 text-light">Code: <span id="plex-auth-code" class="text-light"></span></p>
          <p class="text-light">Expires at: <span id="plex-auth-exp" class="text-light"></span></p>
        </div>
        <div id="plex-server-pick" class="mt-3" style="display:none;">
          <h6>Choose your Plex Server</h6>
          <div id="plex-server-list" class="mb-2"></div>
          <div class="mb-2">
            <input type="text" class="form-control bg-dark text-light border-secondary" id="manual-server-url"
                   placeholder="http://192.168.x.x:32400 (optional)">
          </div>
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="plex-verify-ssl" checked>
            <label class="form-check-label" for="plex-verify-ssl">Verify SSL (use for valid HTTPS)</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="plex-force-save">
            <label class="form-check-label" for="plex-force-save">Force save even if connectivity check fails</label>
          </div>
          <button id="plex-save-env" class="btn btn-success">Save to .env</button>
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

// ---- PIN flow (with manual + force save options) ----
const startBtn = document.getElementById('plex-auth-start');
const stepDiv  = document.getElementById('plex-auth-step');
const linkA    = document.getElementById('plex-auth-link');
const codeEl   = document.getElementById('plex-auth-code');
const expEl    = document.getElementById('plex-auth-exp');
const pickDiv  = document.getElementById('plex-server-pick');
const listDiv  = document.getElementById('plex-server-list');
const saveBtn  = document.getElementById('plex-save-env');
const saveRes  = document.getElementById('plex-save-result');
const sslChk   = document.getElementById('plex-verify-ssl');
const forceChk = document.getElementById('plex-force-save');

let pinId = null;
let token = null;
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
    if (j.debug) log('DEBUG(start)', j.debug);
    if (!j.ok) { alert('Failed to start Plex auth: ' + (j.error||'')); startBtn.disabled = false; return; }
    pinId = j.pinId;
    stepDiv.style.display = '';
    linkA.href = j.deeplink;
    codeEl.textContent = j.code || '';
    expEl.textContent  = j.expiresAt || '';
    window.open(j.deeplink, '_blank');

    // Poll until token
    pollTimer = setInterval(async () => {
      const r = await api('poll', {pinId});
      log('API poll <-', r);
      if (r.debug) log('DEBUG(poll)', r.debug);
      if (!r.ok) { clearInterval(pollTimer); alert('Auth failed: ' + (r.error||'')); startBtn.disabled = false; return; }
      if (r.pending) return;
      clearInterval(pollTimer);
      token = r.token;
      log('PIN authorized; token received.', { tokenPreview: token ? (token.slice(0,6) + '...') : null });

      // Build server list with server ID (clientIdentifier) and token source previews
      listDiv.innerHTML = '';
      const servers = Array.isArray(r.servers) ? r.servers : [];
      if (servers.length > 0) {
        servers.forEach((s, idx) => {
          const id = 'srv_' + idx;
          const serverId = s.id || s.clientIdentifier || '';
          const label = `${s.name} — ${s.uri} ${s.local ? '(local)' : ''} ${s.https ? 'HTTPS' : ''}`
            + (serverId ? ` — <small>id: ${serverId}</small>` : '')
            + (Array.isArray(s.token_sources) && Array.isArray(s.token_previews) ? 
               `<br><small>tokens: ${s.token_sources.map((src,i)=>`${src}:${s.token_previews[i]||''}`).join(', ')}</small>` : '');

          const row = document.createElement('div');
          row.className = 'form-check';
          row.innerHTML = `
            <input class="form-check-input" type="radio" name="serverUrl" value="${s.uri}" id="${id}" ${idx===0?'checked':''}
                   data-server-id="${serverId}">
            <label class="form-check-label" for="${id}">${label}</label>`;
          listDiv.appendChild(row);
        });
      }
      pickDiv.style.display = '';
      saveRes.textContent = 'Signed in to Plex. If Plex showed an error page, it’s safe to ignore — authentication succeeded.';
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
      token,
      serverUrl,
      serverId,
      verifySsl: sslChk.checked ? 'true' : 'false',
      forceSave: forceChk.checked ? 'true' : 'false'
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
    if (j.debug) log('DEBUG(save)', j.debug);

    if (!j.ok) {
      if (Array.isArray(j.probes)) {
        log('Probe details', j.probes);
        const lines = j.probes.map(p => {
          return `- url=${p.candidateUrl} token=${p.tokenPreview} src=${p.tokenSource} code=${p.code} ok=${p.ok} err=${p.err||'n/a'}`
            + (p.identity && p.identity.machineIdentifier ? ` id=${p.identity.machineIdentifier}` : '');
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
