<?php
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed.'], 405);
}

$taskId = (string)($_GET['taskId'] ?? '');
$index = isset($_GET['index']) ? (int)$_GET['index'] : -1;

if ($taskId === '' || !preg_match('/^task_[a-f0-9]{32}$/', $taskId)) {
    json_response(['error' => 'Invalid task id.'], 400);
}

if ($index < 0) {
    json_response(['error' => 'Invalid image index.'], 400);
}

$apiKey = request_api_key();
if ($apiKey === '') {
    json_response(['error' => 'API key is required.'], 400);
}

try {
    $task = load_task($taskId);
    if (!$task) {
        json_response(['error' => 'Task not found.'], 404);
    }

    $image = image_item_from_task($task, $index);
    if (!$image) {
        json_response(['error' => 'Image not found.'], 404);
    }

    $slot = null;
    if (($image['sourceType'] ?? '') === 'url') {
        $slot = acquire_image_download_slot();
        if ($slot === null) {
            header('Retry-After: 2');
            json_response(['error' => '图片加载请求较多，请稍后重试。'], 429);
        }
    }

    try {
        @set_time_limit(IMAGE_DOWNLOAD_TIMEOUT_SECONDS + 5);
        @ini_set('max_execution_time', (string)(IMAGE_DOWNLOAD_TIMEOUT_SECONDS + 5));
        $payload = image_bytes_from_task_image($image, $apiKey);
    } finally {
        release_image_download_slot($slot);
    }
    $contentType = (string)($payload['contentType'] ?? 'image/png');
    $bytes = (string)($payload['bytes'] ?? '');

    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=604800, immutable');
    header('Vary: X-API-Key, Cookie');
    echo $bytes;
    exit;
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 500);
}
