<?php
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

try {
    json_response([
        'retentionHours' => TASK_RETENTION_HOURS,
        'cleanup' => cleanup_old_artifacts(true),
        'updatedAt' => now_ms(),
    ]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
