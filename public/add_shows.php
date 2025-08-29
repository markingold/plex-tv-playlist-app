<?php
declare(strict_types=1);
ini_set('display_errors','1'); ini_set('display_startup_errors','1'); error_reporting(E_ALL);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_csrf.php';

$dbFilePath = __DIR__ . '/../database/plex_playlist.db';
function open_db(string $path): PDO { $pdo = new PDO("sqlite:" . $path); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); return $pdo; }

$fatalError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shows'])) {
    csrf_validate();
    try {
        $conn = open_db($dbFilePath);
        $submittedShows = array_map('intval', (array)$_POST['shows']);
        $conn->exec("DELETE FROM playlistShows");
        $stmtInsert = $conn->prepare(
            "INSERT INTO playlistShows (id, title, total_episodes, timeSlot)
             SELECT id, title, total_episodes, NULL FROM allShows WHERE id = ?"
        );
        foreach ($submittedShows as $showId) { $stmtInsert->execute([$showId]); }
        $conn = null;
        header('Location: timeslots.php'); exit;
    } catch (Throwable $e) {
        $fatalError = 'Failed to save selection: ' . $e->getMessage();
    }
}

$shows = []; $existingShowIds = [];
if ($fatalError === null) {
    try {
        $conn = open_db($dbFilePath);
        $shows = $conn->query("SELECT id, title, total_episodes FROM allShows ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
        $stmtExisting = $conn->query("SELECT id FROM playlistShows");
        while ($row = $stmtExisting->fetch(PDO::FETCH_ASSOC)) { $existingShowIds[] = (int)$row['id']; }
        $conn = null;
    } catch (Throwable $e) { $fatalError = 'Failed to load data: ' . $e->getMessage(); }
}

require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>
<div class="container py-4">
  <div class="mb-3 text-center">
    <h2>Select TV Shows for Your Playlist</h2>
    <p>Check the shows you want, then click Submit to assign timeslots.</p>
  </div>
  <?php if ($fatalError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($fatalError, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <form action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="row row-cols-1 row-cols-md-2 g-2">
    <?= csrf_field() ?>
    <?php foreach ($shows as $show):
      $id = (int)$show['id']; $isChecked = in_array($id, $existingShowIds, true) ? 'checked' : '';
    ?>
      <div class="col">
        <label class="form-check">
          <input class="form-check-input" type="checkbox" name="shows[]" value="<?= $id; ?>" <?= $isChecked; ?>>
          <span class="form-check-label">
            <?= htmlspecialchars($show['title'], ENT_QUOTES, 'UTF-8'); ?> — Eps: <?= (int)$show['total_episodes']; ?>
          </span>
        </label>
      </div>
    <?php endforeach; ?>
    <div class="col-12 mt-3">
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
