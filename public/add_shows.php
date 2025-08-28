<?php
declare(strict_types=1);

// --- Strictly no output before potential redirects ---

// (Optional) PHP error visibility while developing
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Load env via composer if available (fails gracefully if vendor/ missing)
$fatalError = null;
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
    if (class_exists(\Dotenv\Dotenv::class)) {
        try {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
            $dotenv->load();
        } catch (Throwable $e) {
            // Don't echo yet—capture for display later
            $fatalError = 'Failed to load .env: ' . $e->getMessage();
        }
    }
}

// Database path
$dbFilePath = __DIR__ . '/../database/plex_playlist.db';

// Helper to open a PDO connection (no echo!)
function open_db(string $path): PDO {
    $pdo = new PDO("sqlite:" . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

// Handle POST first (may redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shows'])) {
    try {
        $conn = open_db($dbFilePath);

        // Sanitize show IDs to integers
        $submittedShows = array_map('intval', (array)$_POST['shows']);

        // Reset playlistShows
        $conn->exec("DELETE FROM playlistShows");

        // Insert selected shows from allShows
        $stmtInsert = $conn->prepare(
            "INSERT INTO playlistShows (id, title, total_episodes, timeSlot)
             SELECT id, title, total_episodes, NULL
             FROM allShows
             WHERE id = ?"
        );

        foreach ($submittedShows as $showId) {
            $stmtInsert->execute([$showId]);
        }

        // Close and redirect
        $conn = null;
        header('Location: timeslots.php');
        exit;

    } catch (Throwable $e) {
        // Capture error to render after header.php (no output yet)
        $fatalError = 'Failed to save selection: ' . $e->getMessage();
    }
}

// Not redirecting -> fetch data for the page
$shows = [];
$existingShowIds = [];
if ($fatalError === null) {
    try {
        $conn = open_db($dbFilePath);

        // All shows
        $stmtAll = $conn->query(
            "SELECT id, title, total_episodes
             FROM allShows
             ORDER BY title ASC"
        );
        $shows = $stmtAll->fetchAll(PDO::FETCH_ASSOC);

        // Existing playlist show IDs
        $stmtExisting = $conn->query("SELECT id FROM playlistShows");
        while ($row = $stmtExisting->fetch(PDO::FETCH_ASSOC)) {
            $existingShowIds[] = (int)$row['id'];
        }

        $conn = null;
    } catch (Throwable $e) {
        $fatalError = 'Failed to load data: ' . $e->getMessage();
    }
}

// Safe to output now:
require __DIR__ . '/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Select TV Shows</title>
    <style>
        .centered-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px;
        }
        .form-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            max-width: 1200px;
            margin: auto;
        }
        .show-item {
            flex: 1 1 45%;
            margin: 5px;
            box-sizing: border-box;
            text-align: left;
        }
        button {
            flex-basis: 100%;
            margin: 10px 0;
        }
        .explanation {
            margin-bottom: 20px;
            text-align: center;
            max-width: 800px;
        }
        .error {
            color: #c00;
            max-width: 800px;
            text-align: left;
            margin: 10px auto 20px;
            white-space: pre-wrap;
        }
        @media (max-width: 600px) {
            .show-item { flex: 1 1 100%; }
        }
    </style>
</head>
<body>
    <div class="centered-container">
        <div class="explanation">
            <h2>Select TV Shows for Your Playlist</h2>
            <p>Select the TV shows you want in your Plex playlist. Check the boxes next to the shows and click "Submit".
               You will then assign timeslots to each selected show on the next page.</p>
        </div>

        <?php if ($fatalError !== null): ?>
            <div class="error"><?php echo htmlspecialchars($fatalError, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" method="post" class="form-container">
            <?php foreach ($shows as $show):
                $id   = (int)$show['id'];
                $isChecked = in_array($id, $existingShowIds, true) ? 'checked' : '';
            ?>
                <div class="show-item">
                    <label>
                        <input type="checkbox" name="shows[]" value="<?php echo $id; ?>" <?php echo $isChecked; ?>>
                        <?php echo htmlspecialchars($show['title'], ENT_QUOTES, 'UTF-8'); ?>
                        — Eps: <?php echo htmlspecialchars((string)$show['total_episodes'], ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                </div>
            <?php endforeach; ?>
            <button type="submit" style="flex-basis: 100%;">Submit</button>
        </form>
    </div>
</body>
</html>
