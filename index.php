<?php
include 'public/header.php'; // Include the header file

ob_start(); // Start output buffering

// Check if the form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Specify the path to the Python executable within the venv
    $pythonExecutable = './venv/bin/python3';
    
    // Handle Initialize Database button press
    if (isset($_POST['initialize'])) {
        // Specify the path to your populateShows.py script
        $populateShowsScript = 'scripts/populateShows.py';
        
        // Run populateShows.py to initialize the database
        $populateShowsCommand = escapeshellcmd("$pythonExecutable $populateShowsScript");
        $populateShowsOutput = shell_exec($populateShowsCommand);

        // Redirect to add_shows.php
        header("Location: public/add_shows.php");
        exit();
    }
}

ob_end_flush(); // End output buffering and send output
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Plex TV Playlist App</title>
    <style>
        .container {
            max-width: 800px;
            margin: auto;
            padding: 20px;
            text-align: center;
        }
        .button-container {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome to the Plex TV Playlist App</h1>
        <p>
            This application helps you generate round-robin playlists for your TV shows on Plex Media Server. 
            You can select the shows, arrange them in your preferred order, and create a playlist that cycles 
            through the first episode of each show, then the second episode of each show, and so on, until all episodes 
            are added to the playlist.
        </p>
        <h2>How It Works</h2>
        <p>
            The app retrieves all your TV shows from the Plex server and stores them in a database. You can then 
            select which shows you want in your playlist and assign them timeslots. The application will generate 
            a playlist in Plex that plays episodes in the order you specified.
        </p>
        <h2>Getting Started</h2>
        <p>
            To get started, click the "Initialize Database" button below. This will set up the necessary database 
            and prepare the app for use. After initialization, you will be redirected to select your shows and 
            assign timeslots. This button is also used to update the TV Shows from your Plex Media Server.
        </p>
        <div class="button-container">
            <form action="index.php" method="post">
                <button type="submit" name="initialize">Initialize Database/Update TV Shows</button>
            </form>
        </div>
    </div>
</body>
</html>
