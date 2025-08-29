<?php
// public/partials/head.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Plex Toolbox</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #1a1a1a; color: #ffffff; }
    .navbar {
      background-color: #0f0f0f;
      background: linear-gradient(90deg, rgba(15, 15, 15, 1) 0%, rgba(0, 0, 0, 1) 100%);
      border-bottom: 3px solid #e5a00d;
    }
    .navbar-brand, .nav-link { color: #ffffff !important; }
    .navbar-brand { font-weight: bold; font-size: 1.5em; }
    .nav-link { transition: color .3s ease, text-shadow .3s ease; }
    .nav-link:hover { color: #e5a00d !important; text-shadow: 0 0 10px #e5a00d; }
    .nav-link.active { color: #e5a00d !important; }
  </style>
</head>
