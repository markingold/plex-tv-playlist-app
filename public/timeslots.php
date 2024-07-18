<?php
require '../vendor/autoload.php'; // Include the Composer autoload file

use Dotenv\Dotenv;

// Load the .env file
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

include 'header.php'; // Include the header file

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

// Flag to check if redirect should happen
$shouldRedirect = false;
$error = '';

// If form was submitted, process the form
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['timeslots'])) {
    $timeslots = $_POST['timeslots'];
    $uniqueTimeslots = array_unique($timeslots);

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

// Fetch all shows for timeslot assignment, sorted by total_episodes
$sql = "SELECT id, title, timeSlot, total_episodes FROM playlistShows ORDER BY total_episodes ASC";
$stmt = $conn->query($sql);
$shows = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $shows[] = $row;
}

// Determine the number of shows for the dropdown
$numOfShows = count($shows);

// Initialize timeslots from 1 to $numOfShows if not already set
foreach ($shows as $index => $show) {
    if (!$show['timeSlot']) {
        $shows[$index]['timeSlot'] = $index + 1;
    }
}

// Close the database connection
$conn = null;

// Specify the path to the Python executable within the venv
$pythonExecutable = '../venv/bin/python3';

// Run the Python script if form was submitted and validated
if ($shouldRedirect) {
    // Run getEpisodes.py
    $getEpisodesScript = '../scripts/getEpisodes.py';
    $getEpisodesCommand = escapeshellcmd("$pythonExecutable $getEpisodesScript");
    shell_exec($getEpisodesCommand);
    
    // Run newPlaylist.py to create a new playlist and get its ratingKey
    $newPlaylistScript = '../scripts/newPlaylist.py';
    $newPlaylistCommand = escapeshellcmd("$pythonExecutable $newPlaylistScript");
    $newPlaylistOutput = shell_exec($newPlaylistCommand);
    
    // Extract the ratingKey from the output of newPlaylist.py
    preg_match('/New Playlist Rating Key: (\d+)/', $newPlaylistOutput, $matches);
    $ratingKey = $matches[1] ?? null;
    
    if ($ratingKey) {
        // Run generatePlaylist.py with the extracted ratingKey
        $generatePlaylistScript = '../scripts/generatePlaylist.py';
        $generatePlaylistCommand = escapeshellcmd("$pythonExecutable $generatePlaylistScript $ratingKey");
        shell_exec($generatePlaylistCommand);
        
        // Display success message and redirect
        echo "<script>alert('Playlist Generated in Plex'); window.location.href = '../index.php';</script>";
    } else {
        echo "Failed to create new playlist or retrieve its ratingKey.";
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
            var timeslots = document.querySelectorAll('select[name^="timeslots"]');
            var selectedValues = [];
            for (var i = 0; i < timeslots.length; i++) {
                var value = timeslots[i].value;
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
    <?php if ($error): ?>
        <p style="color: red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form action="timeslots.php" method="post" onsubmit="return validateForm()">
        <?php foreach ($shows as $index => $show): ?>
            <div>
                <!-- Display the show title along with its total number of episodes -->
                <span><?= htmlspecialchars($show['title']) ?> - Episodes: <?= htmlspecialchars($show['total_episodes']) ?>:</span>
                <select name="timeslots[<?= $show['id'] ?>]">
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
