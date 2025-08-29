<?php
declare(strict_types=1);
require __DIR__ . '/public/_bootstrap.php';
require __DIR__ . '/public/_csrf.php';

$errorHtml = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize'])) {
    csrf_validate();
    $logFile = $ROOT . '/logs/populate_' . date('Ymd_His') . '.log';
    $res = run_py_logged('populateShows.py', [], $logFile);

    if (!isset($res['exit_code']) || (int)$res['exit_code'] !== 0) {
        $exitCode = (int)($res['exit_code'] ?? -1);
        $stderr   = htmlspecialchars($res['stderr'] ?? 'No STDERR captured');
        $errorHtml = <<<HTML
<pre style="color:#c00; white-space:pre-wrap; text-align:left">
populateShows.py failed. Exit: {$exitCode}

STDERR:
{$stderr}
</pre>
HTML;
    } else {
        header('Location: public/add_shows.php'); exit;
    }
}

require __DIR__ . '/public/partials/head.php';
require __DIR__ . '/public/partials/nav.php';
?>
<div class="container" style="max-width:800px; margin:auto; padding:20px; text-align:center;">
  <h1>Welcome to the Plex TV Playlist App</h1>
  <p>This app builds a round‑robin playlist across your selected shows.</p>

  <?php if ($errorHtml): ?>
    <div class="error" style="margin:20px auto; max-width:800px; text-align:left;"><?= $errorHtml ?></div>
  <?php endif; ?>

  <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="mt-4">
    <?= csrf_field() ?>
    <button class="btn btn-warning" type="submit" name="initialize">Initialize Database / Update TV Shows</button>
  </form>
</div>
<?php require __DIR__ . '/public/partials/footer.php'; ?>
