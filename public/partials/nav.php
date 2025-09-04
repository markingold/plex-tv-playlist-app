<?php
// public/partials/nav.php
$base_path = '/public';
?>
<body>
<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= $base_path ?>/../index.php">Plex Toolbox</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNavDropdown">
      <ul class="navbar-nav">
        <li class="nav-item"><a class="nav-link" href="<?= $base_path ?>/add_shows.php">Edit Shows</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= $base_path ?>/timeslots.php">Timeslots</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= $base_path ?>/setup.php">Setup</a></li>
      </ul>
    </div>
  </div>
</nav>
