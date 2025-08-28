<?php
// ===== Timeslots & Playlist Generator =====
include 'header.php';
include '_bootstrap.php';

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
    die("Connection failed: " . htmlspecialchars($e->getMessage()));
}

$shouldRedirect = false;
$error = '';

// On POST, update timeslots
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['timeslots'])) {
    $timeslots = $_POST['timeslots'];
    $uniqueTimeslots = array_unique($timeslots, SORT_REGULAR);

    if (count($timeslots) !== count($uniqueTimeslots)) {
        $error = "Each timeslot must be unique. Please ensure no duplicate timeslots are assigned.";
    } else {
        $shouldRedirect = true;
        foreach ($timeslots as $showId => $timeslot) {
            $sql = "UPDATE playlistShows SET timeSlot = ? WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$timeslot, $showId]);
        }
    }
}

// Fetch shows for form
$sql = "SELECT id, title, timeSlot, total_episodes FROM playlistShows ORDER BY total_episodes ASC";
$stmt = $conn->query($sql);
$shows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$numOfShows = count($shows);
foreach ($shows as $index => $show) {
    if (!$show['timeSlot']) {
        $shows[$index]['timeSlot'] = $index + 1;
    }
}
$conn = null;

// ---- Helper: run with logging via bootstrap ----
function run_with_logging(string $script, array $args, string $logfile): array {
    return run_py_logged($script, $args, $logfile);
}

// ---- If form validated, run the pipeline ----
if ($shouldRedirect) {
    // 1) getEpisodes.py
    $r1 = run_with_logging($getEpisodesScript, [], $log_getEpisodes);
    if ($r1['exit_code'] !== 0) {
        echo "<pre style='color:#c00;'>getEpisodes.py failed (exit {$r1['exit_code']}). See log:\n{$log_getEpisodes}\n\nSTDERR:\n" . htmlspecialchars($r1['stderr']) . "</pre>";
        exit;
    }

    // 2) newPlaylist.py -> expect JSON
    $r2 = run_with_logging($newPlaylistScript, [], $log_newPlaylist);
    if ($r2['exit_code'] !== 0) {
        echo "<pre style='color:#c00;'>newPlaylist.py failed (exit {$r2['exit_code']}). See log:\n{$log_newPlaylist}\n\nSTDERR:\n" . htmlspecialchars($r2['stderr']) . "</pre>";
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
            echo "<pre style='color:#c00;'>generatePlaylist.py failed (exit {$r3['exit_code']}). See log:\n{$log_generatePlaylist}\n\nSTDERR:\n" . htmlspecialchars($r3['stderr']) . "</pre>";
            exit;
        }

        echo "<script>alert('Playlist Generated in Plex'); window.location.href = '../index.php';</script>";
        exit;
    } else {
        echo "<pre style='color:#c00;'>Failed to create new playlist or retrieve its ratingKey.\nSee log for details:\n{$log_newPlaylist}\n\nSTDOUT:\n" . htmlspecialchars($r2['stdout']) . "\n\nSTDERR:\n" . htmlspecialchars($r2['stderr']) . "</pre>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Assign Timeslots</title>
    <script>
        function validateForm() {
            const timeslots = document.querySelectorAll('select[name^="timeslots"]');
            const selectedValues = [];
            for (let i = 0; i < timeslots.length; i++) {
                const value = timeslots[i].value;
                if (selectedValues.includes(value)) {
                    alert("Each timeslot must be unique. Please ensure no duplicate timeslots are assigned.");
                    return false;
                }
                selectedValues.push(value);
            }
            return true;
        }
    </script>
</head>
<body>
    <?php if (!empty($error)): ?>
        <p style="color: red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form action="timeslots.php" method="post" onsubmit="return validateForm()">
        <?php foreach ($shows as $index => $show): ?>
            <div>
                <span><?= htmlspecialchars($show['title']) ?> - Episodes: <?= htmlspecialchars($show['total_episodes']) ?>:</span>
                <select name="timeslots[<?= (int)$show['id'] ?>]">
                    <?php for ($i = 1; $i <= $numOfShows; $i++): ?>
                        <option value="<?= $i ?>" <?= $i == $show['timeSlot'] ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        <?php endforeach; ?>
        <button type="submit">Generate Playlist</button>
    </form>
</body>
</html>
