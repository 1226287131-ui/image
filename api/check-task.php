<?php
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$input = read_json_body();
$taskId = (string)($input['taskId'] ?? '');
$apiKey = trim((string)($input['apiKey'] ?? ''));

if ($taskId === '' || !preg_match('/^task_[a-f0-9]{32}$/', $taskId)) {
    json_response(['error' => 'Invalid task id.'], 400);
}

if ($apiKey === '') {
    json_response(['error' => 'API key is required.'], 400);
}
sync_api_key_cookie($apiKey);

try {
    $task = load_task($taskId);
    if (!$task) {
        json_response(['error' => 'Task not found.'], 404);
    }

    $task = mark_stale_task_if_needed($task);

    if (($task['status'] ?? '') === 'queued') {
        $task = process_task_with_lock($task, $apiKey);
    }

    json_response(public_task($task));
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
