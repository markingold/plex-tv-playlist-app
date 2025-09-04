<?php
// ===== Timeslots & Playlist Generator =====
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_csrf.php';

// Paths
$ROOT = realpath(__DIR__ . '/..');
$dbFilePath = $ROOT . '/database/plex_playlist.db';

// Scripts (names only; helpers prepend /scripts)
$getEpisodesScript       = 'getEpisodes.py';
$newPlaylistScript       = 'newPlaylist.py';
$generatePlaylistScript  = 'generatePlaylist.py';

// Logs
$logDir = $ROOT . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
$timestamp = date('Ymd_His');
$log_getEpisodes      = "$logDir/getEpisodes_$timestamp.log";
$log_newPlaylist      = "$logDir/newPlaylist_$timestamp.log";
$log_generatePlaylist = "$logDir/generatePlaylist_$timestamp.log";

// ---- DB connect (to list shows & set timeslots) ----
try {
    $conn = new PDO("sqlite:$dbFilePath");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . htmlspecialchars((string)$e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

$shouldRunPipeline = false;
$error = '';

// On POST, update timeslots
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['timeslots'])) {
    csrf_validate();

    // Ensure we have an array
    $timeslots = is_array($_POST['timeslots']) ? $_POST['timeslots'] : [];
    $uniqueTimeslots = array_unique($timeslots, SORT_REGULAR);

    if (count($timeslots) !== count($uniqueTimeslots)) {
        $error = "Each timeslot must be unique. Please ensure no duplicate timeslots are assigned.";
    } else {
        $shouldRunPipeline = true;
        foreach ($timeslots as $showId => $timeslot) {
            $sql = "UPDATE playlistShows SET timeSlot = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            // Cast safety
            $stmt->execute([ (int)$timeslot, (int)$showId ]);
        }
    }
}

// Fetch shows for form
$sql = "SELECT id, title, timeSlot, total_episodes FROM playlistShows ORDER BY total_episodes ASC";
$stmt = $conn->query($sql);
$shows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$numOfShows = count($shows);

foreach ($shows as $index => $show) {
    if (empty($show['timeSlot'])) {
        $shows[$index]['timeSlot'] = $index + 1;
    }
}
// We won't use $conn after this
$conn = null;

// ---- Helper: run with logging via bootstrap ----
function run_with_logging(string $script, array $args, string $logfile): array {
    return run_py_logged($script, $args, $logfile);
}

// ---- If form validated, run the pipeline ----
if ($shouldRunPipeline) {
    // 1) getEpisodes.py
    $r1 = run_with_logging($getEpisodesScript, [], $log_getEpisodes);
    if ($r1['exit_code'] !== 0) {
        require __DIR__ . '/partials/head.php';
        require __DIR__ . '/partials/nav.php';
        echo "<pre style='color:#c00;'>getEpisodes.py failed (exit {$r1['exit_code']}). See log:\n{$log_getEpisodes}\n\nSTDERR:\n" . htmlspecialchars((string)$r1['stderr'], ENT_QUOTES, 'UTF-8') . "</pre>";
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    // 2) newPlaylist.py -> expect JSON
    $r2 = run_with_logging($newPlaylistScript, [], $log_newPlaylist);
    if ($r2['exit_code'] !== 0) {
        require __DIR__ . '/partials/head.php';
        require __DIR__ . '/partials/nav.php';
        echo "<pre style='color:#c00;'>newPlaylist.py failed (exit {$r2['exit_code']}). See log:\n{$log_newPlaylist}\n\nSTDERR:\n" . htmlspecialchars((string)$r2['stderr'], ENT_QUOTES, 'UTF-8') . "</pre>";
        require __DIR__ . '/partials/footer.php';
        exit;
    }

    $ratingKey = null;
    $json = json_decode($r2['stdout'], true);
    if (is_array($json) && !empty($json['ok']) && !empty($json['ratingKey'])) {
        $ratingKey = (int)$json['ratingKey'];
    }

    if ($ratingKey) {
        // 3) generatePlaylist.py <ratingKey>
        $r3 = run_with_logging($generatePlaylistScript, [ (string)$ratingKey ], $log_generatePlaylist);
        if ($r3['exit_code'] !== 0) {
            require __DIR__ . '/partials/head.php';
            require __DIR__ . '/partials/nav.php';
            echo "<pre style='color:#c00;'>generatePlaylist.py failed (exit {$r3['exit_code']}). See log:\n{$log_generatePlaylist}\n\nSTDERR:\n" . htmlspecialchars((string)$r3['stderr'], ENT_QUOTES, 'UTF-8') . "</pre>";
            require __DIR__ . '/partials/footer.php';
            exit;
        }

        // Success
        require __DIR__ . '/partials/head.php';
        require __DIR__ . '/partials/nav.php';
        echo "<script>alert('Playlist Generated in Plex'); window.location.href = '../index.php';</script>";
        require __DIR__ . '/partials/footer.php';
        exit;
    } else {
        require __DIR__ . '/partials/head.php';
        require __DIR__ . '/partials/nav.php';
        echo "<pre style='color:#c00;'>Failed to create new playlist or retrieve its ratingKey.\nSee log for details:\n{$log_newPlaylist}\n\nSTDOUT:\n" . htmlspecialchars((string)$r2['stdout'], ENT_QUOTES, 'UTF-8') . "\n\nSTDERR:\n" . htmlspecialchars((string)$r2['stderr'], ENT_QUOTES, 'UTF-8') . "</pre>";
        require __DIR__ . '/partials/footer.php';
        exit;
    }
}

// ---------- RENDER FORM ----------
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>
<div class="container py-4">
    <h2 class="mb-3">Assign Timeslots</h2>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form action="timeslots.php" method="post" onsubmit="return (function(){
        const els=[...document.querySelectorAll('select[name^=&quot;timeslots&quot;]')];
        const seen=new Set();
        for(const el of els){
            if(seen.has(el.value)){ alert('Each timeslot must be unique.'); return false; }
            seen.add(el.value);
        }
        return true;
    })();">
        <?= csrf_field() ?>
        <?php foreach ($shows as $index => $show): ?>
            <div class="row align-items-center mb-2">
                <div class="col-8">
                    <span>
                        <?= htmlspecialchars((string)$show['title'], ENT_QUOTES, 'UTF-8') ?>
                        &mdash; Episodes:
                        <?= htmlspecialchars((string)$show['total_episodes'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div class="col-4">
                    <select class="form-select form-select-sm" name="timeslots[<?= (int)$show['id'] ?>]">
                        <?php for ($i = 1; $i <= $numOfShows; $i++): ?>
                            <option value="<?= $i ?>" <?= ($i === (int)$show['timeSlot']) ? 'selected' : '' ?>><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        <?php endforeach; ?>
        <button class="btn btn-success mt-3" type="submit">Generate Playlist</button>
    </form>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
