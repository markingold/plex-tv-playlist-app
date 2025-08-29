<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_csrf.php';

$dbFilePath = __DIR__ . '/../database/plex_playlist.db';
function open_db(string $p): PDO { $pdo = new PDO("sqlite:" . $p); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

// Run migration to ensure settings/indexes exist
function run_migration(): void {
    $ROOT = realpath(__DIR__ . '/..');
    $out = run_py_logged('db_migrate.py', [], $ROOT . '/logs/migrate_' . date('Ymd_His') . '.log');
    if ($out['exit_code'] !== 0) {
        // Non-fatal; page still works, just no settings table
    }
}
run_migration();

$feedback = null; $error = null; $sections = [];

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
        } else {
            $error = "healthcheck.py failed. STDERR:\n".$r['stderr'];
        }
    }

    if (isset($_POST['action']) && $_POST['action'] === 'save' && isset($_POST['tv_sections'])) {
        try {
            $conn = open_db($dbFilePath);
            $conn->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
            $keys = array_map('strval', (array)$_POST['tv_sections']);
            $csv = implode(',', $keys);
            $stmt = $conn->prepare("INSERT INTO settings(key, value) VALUES('tv_section_keys', ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value");
            $stmt->execute([$csv]);
            $conn = null;
            $feedback = "Saved TV library selection.";
        } catch (Throwable $e) {
            $error = 'Save failed: ' . $e->getMessage();
        }
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
<div class="container py-4" style="max-width:840px;">
  <h2>Setup</h2>
  <p>Use this page to verify Plex connectivity and choose which TV libraries to use. (Environment variables are read from the container; this page does not modify your .env)</p>

  <?php if ($feedback): ?><div class="alert alert-success" style="white-space:pre-wrap;"><?= htmlspecialchars($feedback) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger" style="white-space:pre-wrap;"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <form method="post" class="mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test">
    <button class="btn btn-secondary" type="submit">Test Plex Connection</button>
  </form>

  <?php if (!empty($sections)): ?>
    <form method="post" class="mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
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
<?php require __DIR__ . '/partials/footer.php'; ?>
