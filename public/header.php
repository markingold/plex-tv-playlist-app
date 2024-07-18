<?php
// Define the base path and URL
$base_path = '/plex_lite/public';
$base_url = sprintf(
    "%s://%s%s",
    isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http',
    $_SERVER['SERVER_NAME'],
    $base_path
);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Plex Toolbox</title>
    <style>
        body {
            background-color: #1a1a1a;
            color: #ffffff;
        }
        .navbar {
            background-color: #0f0f0f;
            background: linear-gradient(90deg, rgba(15, 15, 15, 1) 0%, rgba(0, 0, 0, 1) 100%);
            border-bottom: 3px solid #e5a00d; /* Add orange border to the navbar */
        }
        .navbar-brand, .nav-link {
            color: #ffffff !important;
        }
        .navbar-brand {
            font-weight: bold;
            font-size: 1.5em;
        }
        .nav-link {
            transition: color 0.3s ease, text-shadow 0.3s ease;
        }
        .nav-link:hover {
            color: #e5a00d !important;
            text-shadow: 0 0 10px #e5a00d; /* Add glow effect on hover */
        }
        .nav-link.active {
            color: #e5a00d !important; /* Highlight active link with orange */
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= $base_path ?>/../index.php">Plex Toolbox</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNavDropdown">
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link" href="<?= $base_path ?>/add_shows.php">Edit Shows</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= $base_path ?>/timeslots.php">Timeslots</a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Bootstrap Bundle with Popper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
