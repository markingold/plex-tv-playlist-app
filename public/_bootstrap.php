<?php
// public/_bootstrap.php
// Central helpers for paths and Python execution

// Absolute project root (repo root)
$ROOT = realpath(__DIR__ . '/..');

// Python executable: prefer env, then default
$PY = getenv('PYTHON_EXEC') ?: '/opt/plex_playlist_venv/bin/python3';

// Run a Python script from /scripts with optional args.
// Returns array [exit_code, stdout, stderr]
function run_py_logged(string $script, array $args = [], string $logfile = null): array {
    global $ROOT, $PY;

    $cmd = escapeshellarg($PY) . ' ' . escapeshellarg($ROOT . '/scripts/' . $script);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string)$a);
    }

    $descriptorspec = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];

    $process = proc_open($cmd, $descriptorspec, $pipes, $ROOT);
    $result = ['exit_code' => -1, 'stdout' => '', 'stderr' => '', 'cmd' => $cmd];

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);

        $result['exit_code'] = $exit;
        $result['stdout'] = $stdout;
        $result['stderr'] = $stderr;

        if ($logfile) {
            @file_put_contents($logfile,
                "=== CMD ===\n$cmd\n\n=== EXIT ===\n$exit\n\n=== STDOUT ===\n$stdout\n\n=== STDERR ===\n$stderr\n"
            );
        }
    }
    return $result;
}

// Convenience wrapper (no logging) that returns stdout or null on failure
function run_py_stdout(string $script, array $args = []): ?string {
    $r = run_py_logged($script, $args);
    return $r['exit_code'] === 0 ? $r['stdout'] : null;
}
