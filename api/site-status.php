<?php
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

try {
    touch_current_visitor();
    json_response([
        'runningTasks' => count_running_tasks(),
        'onlineVisitors' => count_online_visitors(),
        'onlineWindowSeconds' => ONLINE_WINDOW_SECONDS,
        'retentionHours' => TASK_RETENTION_HOURS,
        'cleanup' => latest_cleanup_status(),
        'updatedAt' => now_ms(),
    ]);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
