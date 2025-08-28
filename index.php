<?php
declare(strict_types=1);

// Load helpers and constants first (defines $ROOT and run_py_logged)
require __DIR__ . '/public/_bootstrap.php';

$errorHtml = null;

// Handle POST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initialize'])) {
    // Run populateShows.py using the helper
    $logFile = $ROOT . '/logs/populate_' . date('Ymd_His') . '.log';
    $res = run_py_logged('populateShows.py', [], $logFile);

    if (!isset($res['exit_code']) || (int)$res['exit_code'] !== 0) {
        // Capture error to display later (no output before header/redirect!)
        $exitCode = (int)($res['exit_code'] ?? -1);
        $stderr   = htmlspecialchars($res['stderr'] ?? 'No STDERR captured');
        $errorHtml = <<<HTML
<pre style="color:#c00; white-space:pre-wrap; text-align:left">
populateShows.py failed.
Exit: {$exitCode}

STDERR:
{$stderr}
</pre>
HTML;
    } else {
        // Success: redirect BEFORE any output
        header('Location: public/add_shows.php');
        exit;
    }
}

// From here on, it’s safe to output HTML.
// Include header & nav (this likely prints HTML)
require __DIR__ . '/public/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Plex TV Playlist App</title>
    <style>
        .container { max-width: 800px; margin: auto; padding: 20px; text-align: center; }
        .button-container { margin-top: 20px; }
        .error { margin: 20px auto; max-width: 800px; text-align: left; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome to the Plex TV Playlist App</h1>
        <p>
            This application helps you generate round-robin playlists for your TV shows on Plex Media Server.
            You can select the shows, arrange them in your preferred order, and create a playlist that cycles
            through the first episode of each show, then the second, and so on.
        </p>
        <h2>How It Works</h2>
        <p>
            The app retrieves all your TV shows from the Plex server and stores them in a database. You can then
            select which shows you want in your playlist and assign them timeslots.
        </p>
        <h2>Getting Started</h2>
        <p>
            Click the button to initialize (or refresh) your TV show database from Plex.
        </p>

        <?php if ($errorHtml): ?>
            <div class="error">
                <?php echo $errorHtml; ?>
            </div>
        <?php endif; ?>

        <div class="button-container">
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                <button type="submit" name="initialize">Initialize Database / Update TV Shows</button>
            </form>
        </div>
    </div>
</body>
</html>
