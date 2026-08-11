<?php
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$payload = read_json_body();
$prompt = trim((string)($payload['prompt'] ?? ''));

if ($prompt === '') {
    json_response(['error' => 'Prompt is required.'], 400);
}

$apiKey = trim((string)($payload['apiKey'] ?? ''));
if ($apiKey === '') {
    json_response(['error' => 'API key is required.'], 400);
}
sync_api_key_cookie($apiKey);

unset($payload['apiKey']);

$taskId = 'task_' . bin2hex(random_bytes(16));
$task = [
    'id' => $taskId,
    'status' => 'queued',
    'progress' => 0,
    'createdAt' => now_ms(),
    'updatedAt' => now_ms(),
    'request' => summarize_request($payload),
    'payload' => $payload,
    'images' => [],
    'error' => null,
    'upstream' => null,
];

try {
    save_task($taskId, $task);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}

json_response_and_continue(['taskId' => $taskId], 202);

process_task_with_lock($task, $apiKey);
