<?php
require_once __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') {
    json_response(['error' => 'Cleanup is only available from the server command line.'], 403);
}

try {
    $cleanup = cleanup_old_artifacts(true);
    $runningTasks = rebuild_running_task_index();
    $result = [
        'retentionHours' => TASK_RETENTION_HOURS,
        'runningTasks' => $runningTasks,
        'cleanup' => $cleanup,
        'updatedAt' => now_ms(),
    ];
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
