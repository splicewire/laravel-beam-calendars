<?php

// Separate-process acceptance proof for the standalone workflow fixture. This intentionally uses
// the same router as the browser host, with a file-backed SQLite database shared by each process.
$router = __DIR__.'/standalone-router.php';
$database = sys_get_temp_dir().'/beam-standalone-process-proof-'.bin2hex(random_bytes(5)).'.sqlite';
$environment = array_merge(getenv(), ['BEAM_CALENDAR_FIXTURE_DB' => $database]);

$run = function (array $arguments) use ($router, $environment): array {
    $process = proc_open([PHP_BINARY, $router, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($router), $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start standalone fixture process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException("Standalone fixture failed ({$exit}): {$stderr}{$stdout}");
    }

    return json_decode(trim($stdout), true, flags: JSON_THROW_ON_ERROR);
};

$process = null;
$marker = $database.'.barrier';
try {
    $scheduled = $run(['schedule']);
    $actionId = $scheduled['action_id'];
    $process = proc_open([PHP_BINARY, $router, 'run', $actionId, 'before-commit', $marker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname($router), $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start standalone crash worker.');
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $deadline = microtime(true) + 15;
    while (! file_exists($marker) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (! file_exists($marker)) {
        throw new RuntimeException('Standalone worker did not reach its pre-commit barrier.');
    }
    proc_terminate($process, 9);
    proc_close($process);
    $process = null;

    $pending = $run(['inspect', $actionId]);
    if ($pending !== ['status' => 'pending', 'attempts' => 1, 'article_status' => 'draft']) {
        throw new RuntimeException('Pre-commit crash left an unexpected standalone state: '.json_encode($pending));
    }
    $run(['run', $actionId]);
    $applied = $run(['inspect', $actionId]);
    if ($applied !== ['status' => 'applied', 'attempts' => 1, 'article_status' => 'published']) {
        throw new RuntimeException('Standalone recovery produced an unexpected state: '.json_encode($applied));
    }
    $run(['run', $actionId]);
    $duplicate = $run(['inspect', $actionId]);
    if ($duplicate !== $applied) {
        throw new RuntimeException('Standalone duplicate execution changed durable state: '.json_encode($duplicate));
    }

    echo json_encode(['action_id' => $actionId, 'crash_recovery' => true, 'duplicate_execution' => true], JSON_PRETTY_PRINT)."\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process, 9);
        proc_close($process);
    }
    foreach (glob($marker.'*') ?: [] as $file) {
        @unlink($file);
    }
    @unlink($database);
}
