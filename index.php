<?php
include 'public/header.php'; // header & nav
include 'public/_bootstrap.php'; // helpers

ob_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['initialize'])) {
        // Run populateShows.py using the helper
        $res = run_py_logged('populateShows.py', [], $ROOT . '/logs/populate_' . date('Ymd_His') . '.log');
        // Optionally surface errors in UI
        if ($res['exit_code'] !== 0) {
            echo "<pre style='color:#c00; white-space:pre-wrap;'>populateShows.py failed.
Exit: {$res['exit_code']}

STDERR:
" . htmlspecialchars($res['stderr']) . "</pre>";
            ob_end_flush();
            exit();
        }
        header("Location: public/add_shows.php");
        exit();
    }
}

ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Plex TV Playlist App</title>
    <style>
        .container { max-width: 800px; margin: auto; padding: 20px; text-align: center; }
        .button-container { margin-top: 20px; }
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
        <div class="button-container">
            <form action="index.php" method="post">
                <button type="submit" name="initialize">Initialize Database / Update TV Shows</button>
            </form>
        </div>
    </div>
</body>
</html>
