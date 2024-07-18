<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require '../vendor/autoload.php'; // Include the Composer autoload file

use Dotenv\Dotenv;

// Load the .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

include 'header.php'; // Include header.php

// Database connection settings
$dbFilePath = __DIR__ . '/../database/plex_playlist.db';

try {
    // Create database connection
    $conn = new PDO("sqlite:$dbFilePath");
    // Set error mode to exceptions
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['shows'])) {
    $submittedShows = $_POST['shows'];
    // Convert submitted show IDs to integer for security
    $submittedShows = array_map('intval', $submittedShows);

    // First, remove all shows from `playlistShows`
    $sqlRemove = "DELETE FROM playlistShows";
    $conn->exec($sqlRemove);

    // Then, insert checked shows into `playlistShows`
    $sqlInsert = "INSERT INTO playlistShows (id, title, total_episodes, timeSlot)
                  SELECT id, title, total_episodes, NULL FROM allShows WHERE id = ?";
    $stmtInsert = $conn->prepare($sqlInsert);
    foreach ($submittedShows as $showId) {
        $stmtInsert->execute([$showId]);
    }

    // Close the database connection before redirect
    $conn = null;

    // Redirect to timeslots.php
    header('Location: timeslots.php');
    exit(); // Ensure no further processing occurs
}

// Fetch all shows for listing
$sqlAllShows = "SELECT id, title, total_episodes FROM allShows ORDER BY title ASC";
$stmtAllShows = $conn->query($sqlAllShows);
$shows = $stmtAllShows->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing shows from `playlistShows` for checkbox states
$existingShowIds = [];
$sqlExistingShows = "SELECT id FROM playlistShows";
$stmtExistingShows = $conn->query($sqlExistingShows);
while ($rowExistingShows = $stmtExistingShows->fetch(PDO::FETCH_ASSOC)) {
    $existingShowIds[] = $rowExistingShows['id'];
}

// Close the database connection
$conn = null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>List of Shows</title>
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
        @media (max-width: 600px) {
            .show-item {
                flex: 1 1 100%;
            }
        }
    </style>
</head>
<body>
    <div class="centered-container">
        <div class="explanation">
            <h2>Select TV Shows for Your Playlist</h2>
            <p>Select the TV shows you want in your Plex playlist. Check the boxes next to the shows and click "Submit". You will then assign timeslots to each selected show on the next page.</p>
        </div>
        <form action="add_shows.php" method="post" class="form-container">
            <?php foreach ($shows as $show): 
                $isChecked = in_array($show['id'], $existingShowIds) ? 'checked' : ''; ?>
                <div class="show-item">
                    <input type="checkbox" name="shows[]" value="<?= htmlspecialchars($show['id']) ?>" <?= $isChecked ?>>
                    <?= htmlspecialchars($show['title']) ?> - Eps: <?= htmlspecialchars($show['total_episodes']) ?>
                </div>
            <?php endforeach; ?>
            <button type="submit" style="flex-basis: 100%;">Submit</button>
        </form>
    </div>
</body>
</html>
