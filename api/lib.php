<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

const API_BASE_URL = 'https://api.kkone.vip';
const ASSET_BASE_URL = 'https://gimg.mooko.ai';
const REQUEST_TIMEOUT_SECONDS = 1200;
const IMAGE_DOWNLOAD_TIMEOUT_SECONDS = 35;
const MAX_CONCURRENT_IMAGE_DOWNLOADS = 3;
const SUPPORTED_RATIOS = ['1:1', '5:4', '4:3', '3:2', '16:9', '21:9', '9:16', '4:5', '3:4', '2:3'];
const DEFAULT_IMAGE_MODEL = 'gpt-image-2';
const GPT_IMAGE_2_5_FLARE_MODEL = 'gpt-image-2.5-flare';
const GPT_IMAGE_2_5_SUNBURST_MODEL = 'gpt-image-2.5-sunburst';
const NANO_BANANA_2_MODEL = 'Nano Banana 2';
const NANO_BANANA_PRO_MODEL = 'Nano Banana Pro';
const MAX_REFERENCE_IMAGES = 16;
const ONLINE_WINDOW_SECONDS = 90;
const TASK_RETENTION_HOURS = 48;
const CLEANUP_INTERVAL_SECONDS = 600;
const RUNNING_TASK_STALE_MS = 30 * 60 * 1000;

function json_response(array $data, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_response_and_continue(array $data, int $status = 200)
{
    ignore_user_abort(true);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($body));
    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
}

function starts_with(string $value, string $prefix): bool
{
    return substr($value, 0, strlen($prefix)) === $prefix;
}

function contains_text(string $value, string $needle): bool
{
    return $needle === '' || strpos($value, $needle) !== false;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function now_ms(): int
{
    return (int)floor(microtime(true) * 1000);
}

function root_path(string $path): string
{
    return dirname(__DIR__) . '/' . ltrim($path, '/');
}

function ensure_dir(string $path)
{
    if (is_dir($path)) {
        if (!is_writable($path)) {
            throw new RuntimeException('Directory is not writable: ' . $path);
        }
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Failed to create directory: ' . $path);
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Directory is not writable: ' . $path);
    }
}

function task_path(string $taskId): string
{
    ensure_dir(root_path('storage/tasks'));
    return root_path('storage/tasks/' . $taskId . '.json');
}

function task_lock_path(string $taskId): string
{
    ensure_dir(root_path('storage/tasks'));
    return root_path('storage/tasks/' . $taskId . '.lock');
}

function cleanup_state_path(): string
{
    ensure_dir(root_path('storage'));
    return root_path('storage/cleanup-state.json');
}

function cleanup_lock_path(): string
{
    ensure_dir(root_path('storage'));
    return root_path('storage/cleanup.lock');
}

function running_task_index_path(): string
{
    ensure_dir(root_path('storage'));
    return root_path('storage/running-tasks.json');
}

function visitor_path(string $visitorId): string
{
    ensure_dir(root_path('storage/visitors'));
    return root_path('storage/visitors/' . $visitorId . '.json');
}

function current_visitor_id(): string
{
    $cookieName = 'site_visitor_id';
    $visitorId = (string)($_COOKIE[$cookieName] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $visitorId)) {
        $visitorId = bin2hex(random_bytes(16));
    }

    setcookie($cookieName, $visitorId, [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    return $visitorId;
}

function load_task(string $taskId)
{
    $path = task_path($taskId);
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') return null;
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : null;
}

function save_task(string $taskId, array $task)
{
    $task['updatedAt'] = now_ms();
    $json = json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Failed to encode task data: ' . json_last_error_msg());
    }
    $path = task_path($taskId);
    $bytes = file_put_contents($path, $json, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('Failed to write task file. Check storage/tasks permissions.');
    }

    try {
        update_running_task_index($task);
    } catch (Throwable $e) {
        error_log('Failed to update running task index: ' . $e->getMessage());
    }
}

function api_key_cookie_name(): string
{
    return 'site_api_key';
}

function sync_api_key_cookie(string $apiKey): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(api_key_cookie_name(), $apiKey, [
        'expires' => time() + 30 * 24 * 60 * 60,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_api_key_cookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(api_key_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function touch_current_visitor(): void
{
    $visitorId = current_visitor_id();
    $path = visitor_path($visitorId);
    $data = [
        'id' => $visitorId,
        'lastSeen' => now_ms(),
        'ipHash' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')),
        'userAgentHash' => hash('sha256', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300)),
    ];
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function count_online_visitors(): int
{
    ensure_dir(root_path('storage/visitors'));
    $now = now_ms();
    $cutoff = $now - ONLINE_WINDOW_SECONDS * 1000;
    $cleanupCutoff = $now - 24 * 60 * 60 * 1000;
    $count = 0;

    foreach (glob(root_path('storage/visitors/*.json')) ?: [] as $path) {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw ? json_decode((string)$raw, true) : null;
        $lastSeen = is_array($data) ? (int)($data['lastSeen'] ?? 0) : 0;

        if ($lastSeen >= $cutoff) {
            $count++;
        } elseif ($lastSeen > 0 && $lastSeen < $cleanupCutoff) {
            @unlink($path);
        }
    }

    return $count;
}

function active_task_status(string $status): bool
{
    return $status === 'queued' || $status === 'running';
}

function read_running_task_index($handle): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);
    $data = $raw ? json_decode((string)$raw, true) : [];
    $tasks = is_array($data['tasks'] ?? null) ? $data['tasks'] : [];
    $cutoff = now_ms() - RUNNING_TASK_STALE_MS;
    $valid = [];

    foreach ($tasks as $taskId => $updatedAt) {
        if (!is_string($taskId) || !preg_match('/^task_[a-f0-9]{32}$/', $taskId)) continue;
        $updatedAt = (int)$updatedAt;
        if ($updatedAt >= $cutoff) $valid[$taskId] = $updatedAt;
    }

    return $valid;
}

function write_running_task_index($handle, array $tasks): void
{
    $json = json_encode([
        'updatedAt' => now_ms(),
        'tasks' => $tasks,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return;

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, $json);
    fflush($handle);
}

function update_running_task_index(array $task): void
{
    $taskId = (string)($task['id'] ?? '');
    if (!preg_match('/^task_[a-f0-9]{32}$/', $taskId)) return;

    $handle = @fopen(running_task_index_path(), 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return;
    }

    try {
        $tasks = read_running_task_index($handle);
        $status = (string)($task['status'] ?? '');
        if (active_task_status($status)) {
            $tasks[$taskId] = (int)($task['updatedAt'] ?? now_ms());
        } else {
            unset($tasks[$taskId]);
        }
        write_running_task_index($handle, $tasks);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function replace_running_task_index(array $tasks): int
{
    $handle = @fopen(running_task_index_path(), 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        return count($tasks);
    }

    try {
        write_running_task_index($handle, $tasks);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    return count($tasks);
}

function rebuild_running_task_index(): int
{
    $tasks = [];
    $cutoff = now_ms() - RUNNING_TASK_STALE_MS;
    foreach (glob(root_path('storage/tasks/task_*.json')) ?: [] as $path) {
        $head = file_get_contents($path, false, null, 0, 8192);
        if (!is_string($head)) continue;
        if (!preg_match('/"status"\s*:\s*"(?:queued|running)"/', $head)) continue;
        $updatedAt = 0;
        if (preg_match('/"updatedAt"\s*:\s*(\d+)/', $head, $matches)) {
            $updatedAt = (int)$matches[1];
        }
        if ($updatedAt < $cutoff) continue;
        $taskId = pathinfo($path, PATHINFO_FILENAME);
        if (preg_match('/^task_[a-f0-9]{32}$/', $taskId)) $tasks[$taskId] = $updatedAt;
    }

    return replace_running_task_index($tasks);
}

function count_running_tasks(): int
{
    $path = running_task_index_path();
    $handle = @fopen($path, 'c+');
    if (!$handle || !flock($handle, LOCK_SH)) {
        if (is_resource($handle)) fclose($handle);
        return 0;
    }

    try {
        return count(read_running_task_index($handle));
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function task_expired(array $task, int $cutoffMs): bool
{
    $createdAt = (int)($task['createdAt'] ?? 0);
    $updatedAt = (int)($task['updatedAt'] ?? 0);
    $timestamp = $createdAt > 0 ? $createdAt : $updatedAt;
    if ($timestamp <= 0) return false;
    return $timestamp < $cutoffMs;
}

function output_path_from_public_url(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $url;
    }

    if (!starts_with($path, '/outputs/')) return null;
    $filename = basename($path);
    if ($filename === '' || $filename === '.' || $filename === '..' || $filename === '.gitkeep') return null;

    return root_path('outputs/' . $filename);
}

function delete_task_images(array $task): int
{
    $deleted = 0;
    $seen = [];
    foreach (($task['images'] ?? []) as $image) {
        if (!is_array($image)) continue;
        foreach (['url', 'absoluteUrl'] as $key) {
            $url = (string)($image[$key] ?? '');
            if ($url === '') continue;
            $path = output_path_from_public_url($url);
            if (!$path || isset($seen[$path])) continue;
            $seen[$path] = true;
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

function latest_cleanup_status(): array
{
    $path = cleanup_state_path();
    $raw = is_file($path) ? file_get_contents($path) : false;
    $data = $raw ? json_decode((string)$raw, true) : null;
    if (is_array($data)) return $data;

    return [
        'skipped' => true,
        'lastRun' => 0,
        'deletedTasks' => 0,
        'deletedImages' => 0,
    ];
}

function compact_terminal_task_payload(string $path, array $task): bool
{
    if (!array_key_exists('payload', $task)) return false;
    $status = (string)($task['status'] ?? '');
    if ($status !== 'succeeded' && $status !== 'failed') return false;

    unset($task['payload']);
    $json = json_encode($task, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json !== false && file_put_contents($path, $json, LOCK_EX) !== false;
}

function cleanup_old_artifacts(bool $force = false): array
{
    $now = now_ms();
    $statePath = cleanup_state_path();
    $stateRaw = is_file($statePath) ? file_get_contents($statePath) : '';
    $state = $stateRaw ? json_decode((string)$stateRaw, true) : [];
    $lastRun = is_array($state) ? (int)($state['lastRun'] ?? 0) : 0;

    if (!$force && $lastRun > 0 && $now - $lastRun < CLEANUP_INTERVAL_SECONDS * 1000) {
        return [
            'skipped' => true,
            'lastRun' => $lastRun,
            'deletedTasks' => 0,
            'deletedImages' => 0,
        ];
    }

    $lock = fopen(cleanup_lock_path(), 'c');
    if (!$lock) {
        return [
            'skipped' => true,
            'lastRun' => $lastRun,
            'deletedTasks' => 0,
            'deletedImages' => 0,
        ];
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return [
            'skipped' => true,
            'lastRun' => $lastRun,
            'deletedTasks' => 0,
            'deletedImages' => 0,
        ];
    }

    $deletedTasks = 0;
    $deletedImages = 0;
    $deletedLocks = 0;
    $compactedTasks = 0;
    $cutoffMs = $now - TASK_RETENTION_HOURS * 60 * 60 * 1000;
    $cutoffSeconds = (int)floor($cutoffMs / 1000);

    ensure_dir(root_path('storage/tasks'));
    foreach (glob(root_path('storage/tasks/task_*.json')) ?: [] as $path) {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $task = $raw ? json_decode((string)$raw, true) : null;
        if (!is_array($task)) continue;
        if (!task_expired($task, $cutoffMs)) {
            if (compact_terminal_task_payload($path, $task)) $compactedTasks++;
            continue;
        }

        $deletedImages += delete_task_images($task);
        if (@unlink($path)) {
            $deletedTasks++;
        }
    }

    foreach (glob(root_path('storage/tasks/task_*.lock')) ?: [] as $path) {
        $mtime = filemtime($path);
        if ($mtime !== false && $mtime < $cutoffSeconds && @unlink($path)) $deletedLocks++;
    }

    ensure_dir(root_path('outputs'));
    foreach (glob(root_path('outputs/task_*.*')) ?: [] as $path) {
        if (!is_file($path)) continue;
        $mtime = filemtime($path);
        if ($mtime !== false && $mtime < $cutoffSeconds && @unlink($path)) {
            $deletedImages++;
        }
    }

    $result = [
        'skipped' => false,
        'lastRun' => $now,
        'deletedTasks' => $deletedTasks,
        'deletedImages' => $deletedImages,
        'deletedLocks' => $deletedLocks,
        'compactedTasks' => $compactedTasks,
    ];

    file_put_contents($statePath, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);

    rebuild_running_task_index();

    return $result;
}

function public_task(array $task): array
{
    unset($task['payload']);
    if (is_array($task['images'] ?? null)) {
        $task['images'] = array_values(array_map(function ($image) {
            if (!is_array($image)) return $image;
            unset($image['source']);
            unset($image['upstreamUrl']);
            unset($image['sourceType']);
            unset($image['mimeType']);
            return $image;
        }, $task['images']));
    }
    return $task;
}

function mark_stale_task_if_needed(array $task): array
{
    $status = $task['status'] ?? '';
    $updatedAt = (int)($task['updatedAt'] ?? 0);
    if (($status === 'queued' || $status === 'running') && $updatedAt > 0 && now_ms() - $updatedAt > 30 * 60 * 1000) {
        $task['status'] = 'failed';
        $task['progress'] = 100;
        $task['error'] = '任务处理超时。可能是 PHP 虚拟主机中途终止了长请求，请重新提交或检查主机超时设置。';
        save_task($task['id'], $task);
    }
    return $task;
}

function process_task_with_lock(array $task, string $apiKey): array
{
    $lockPath = task_lock_path($task['id']);
    $lock = fopen($lockPath, 'c');
    if (!$lock) return $task;

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $latest = load_task($task['id']);
        return $latest ?: $task;
    }

    $latest = load_task($task['id']);
    if ($latest) $task = $latest;
    if (($task['status'] ?? '') === 'queued') {
        $task = process_task($task, $apiKey);
        save_task($task['id'], $task);
    }

    flock($lock, LOCK_UN);
    fclose($lock);
    return $task;
}

function requested_image_count(array $payload): int
{
    return max(1, min(10, (int)($payload['count'] ?? ($payload['n'] ?? 1))));
}

function reference_images_from_payload(array $payload): array
{
    $references = array_values(array_filter(is_array($payload['referenceImages'] ?? null) ? $payload['referenceImages'] : []));
    return array_slice($references, 0, MAX_REFERENCE_IMAGES);
}

function requested_model(array $payload): string
{
    $model = trim((string)($payload['model'] ?? DEFAULT_IMAGE_MODEL));
    $normalized = strtolower($model);

    // Keep old browser state and older API clients working after the model rename.
    if ($normalized === 'nano banana 2' || $normalized === 'banana2' || $normalized === 'gemini-3.1-flash-image') {
        return NANO_BANANA_2_MODEL;
    }
    if ($normalized === 'nano banana pro') return NANO_BANANA_PRO_MODEL;
    if ($normalized === GPT_IMAGE_2_5_FLARE_MODEL) return GPT_IMAGE_2_5_FLARE_MODEL;
    if ($normalized === GPT_IMAGE_2_5_SUNBURST_MODEL) return GPT_IMAGE_2_5_SUNBURST_MODEL;

    return DEFAULT_IMAGE_MODEL;
}

function requested_model_label(string $model): string
{
    if (is_nano_banana_model($model)) return $model;
    if ($model === GPT_IMAGE_2_5_FLARE_MODEL) return 'GPT-image-2.5 Flare';
    if ($model === GPT_IMAGE_2_5_SUNBURST_MODEL) return 'GPT-image-2.5 Sunburst';
    return 'GPT-image-2';
}

function upstream_model_name(string $model): string
{
    return $model;
}

function is_nano_banana_model(string $model): bool
{
    return $model === NANO_BANANA_2_MODEL || $model === NANO_BANANA_PRO_MODEL;
}

function summarize_request(array $payload): array
{
    $refs = reference_images_from_payload($payload);
    $model = requested_model($payload);
    return [
        'prompt' => $payload['prompt'] ?? '',
        'model' => $model,
        'modelLabel' => requested_model_label($model),
        'ratio' => $payload['ratio'] ?? '1:1',
        'resolution' => $payload['resolution'] ?? '1K',
        'exactSize' => $payload['exactSize'] ?? ($payload['size'] ?? null),
        'quality' => $payload['quality'] ?? 'auto',
        'count' => requested_image_count($payload),
        'referenceImageCount' => count($refs),
    ];
}

function prompt_with_reference_map(array $payload, string $prompt): string
{
    $refs = reference_images_from_payload($payload);
    if (count($refs) === 0) return $prompt;

    $lines = [
        'Reference image mapping:',
    ];
    foreach ($refs as $index => $_) {
        $number = $index + 1;
        $lines[] = "- @参考图{$number} means input image {$number} in the uploaded image order.";
    }
    $lines[] = 'When the prompt uses @参考图1, @参考图2, etc., apply the instruction to the corresponding input image by number.';

    return implode("\n", $lines) . "\n\nUser prompt:\n" . $prompt;
}

function calculate_exact_size(string $ratio = '1:1', string $resolution = '1K'): string
{
    $parts = array_map('floatval', explode(':', $ratio));
    $r = (($parts[1] ?? 1) > 0) ? (($parts[0] ?? 1) / $parts[1]) : 1;
    $level = strtoupper($resolution);
    $targetPixels = $level === '4K' ? 8294400 : ($level === '2K' ? 4194304 : 1048576);
    $height = (int)round(sqrt($targetPixels / $r) / 16) * 16;
    $width = (int)round(($height * $r) / 16) * 16;

    if ($width > 3840) {
        $width = 3840;
        $height = (int)round(($width / $r) / 16) * 16;
    }
    if ($height > 3840) {
        $height = 3840;
        $width = (int)round(($height * $r) / 16) * 16;
    }

    $currentPixels = $width * $height;
    if ($currentPixels > 8294400) {
        $scale = sqrt(8294400 / $currentPixels);
        $width = (int)floor(($width * $scale) / 16) * 16;
        $height = (int)floor(($height * $scale) / 16) * 16;
    } elseif ($currentPixels < 655360) {
        $scale = sqrt(655360 / $currentPixels);
        $width = (int)ceil(($width * $scale) / 16) * 16;
        $height = (int)ceil(($height * $scale) / 16) * 16;
    }

    return $width . 'x' . $height;
}

function strip_data_url(string $value): string
{
    if (starts_with($value, 'data:')) {
        $pos = strpos($value, ',');
        return $pos === false ? $value : substr($value, $pos + 1);
    }
    return $value;
}

function data_url_mime(string $value): string
{
    if (preg_match('/^data:([^;]+);base64,/', $value, $m)) {
        return $m[1];
    }
    return 'image/png';
}

function infer_extension(string $source, string $contentType = ''): string
{
    if (starts_with($source, 'data:image/jpeg') || contains_text($contentType, 'jpeg')) return 'jpg';
    if (starts_with($source, 'data:image/webp') || contains_text($contentType, 'webp')) return 'webp';
    return 'png';
}

function image_content_type_from_bytes(string $bytes, string $hint = ''): string
{
    $head = bin2hex(substr($bytes, 0, 12));
    if (starts_with($head, '89504e47')) return 'image/png';
    if (starts_with($head, 'ffd8ff')) return 'image/jpeg';
    if (starts_with($head, '47494638')) return 'image/gif';
    if (starts_with($head, '52494646') && substr($bytes, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($bytes, 4, 8) === 'ftypavif' || substr($bytes, 4, 8) === 'ftypavis') return 'image/avif';

    $normalizedHint = strtolower(trim(explode(';', $hint, 2)[0]));
    return starts_with($normalizedHint, 'image/') ? $normalizedHint : '';
}

function assert_image_bytes(string $bytes, string $contentType = '')
{
    if (image_content_type_from_bytes($bytes, $contentType) === '') {
        throw new RuntimeException('Downloaded file is not an image. Check asset domain.');
    }
}

function http_request(string $url, string $method, array $headers, $body, ?int $timeoutSeconds = null, ?string $timeoutMessage = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }
    $timeout = max(1, $timeoutSeconds ?? REQUEST_TIMEOUT_SECONDS);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($response === false) {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            if ($timeoutMessage !== null) throw new RuntimeException($timeoutMessage);
            throw new RuntimeException('中转站生成超时：接口 20 分钟内没有返回结果。请减少生成张数，或降低分辨率/质量后重试。');
        }
        throw new RuntimeException('中转站网络请求失败：' . ($error ?: '未知网络错误'));
    }
    return ['status' => $status, 'contentType' => $contentType, 'body' => (string)$response];
}

function generated_image_proxy_url(string $taskId, int $index): string
{
    return '/api/image-file.php?taskId=' . rawurlencode($taskId) . '&index=' . $index;
}

function normalize_generated_image(string $taskId, array $item, int $index): array
{
    // Some gateways include an empty url key next to the actual b64_json result.
    // Select the first non-empty image source instead of relying on null coalescing.
    $source = '';
    $sourceKeyUsed = '';
    foreach (['url', 'b64_json', 'image'] as $sourceKey) {
        $candidate = $item[$sourceKey] ?? '';
        if (!is_string($candidate) && !is_numeric($candidate)) continue;
        $candidate = trim((string)$candidate);
        if ($candidate !== '') {
            $source = $candidate;
            $sourceKeyUsed = $sourceKey;
            break;
        }
    }
    if ($source === '') throw new RuntimeException('Upstream response does not contain image data.');

    $sourceType = 'url';
    $mimeType = null;
    $upstreamUrl = '';

    if (starts_with($source, 'data:')) {
        $sourceType = 'data_url';
        $mimeType = data_url_mime($source);
    } elseif ($sourceKeyUsed === 'b64_json' || (preg_match('/^[A-Za-z0-9+\/=\s]+$/', $source) && strlen((string)preg_replace('/\s+/', '', $source)) > 1000)) {
        $sourceType = 'b64_json';
        $source = (string)preg_replace('/\s+/', '', $source);
        $mimeType = 'image/png';
    } else {
        $upstreamUrl = starts_with($source, 'http') ? $source : ASSET_BASE_URL . $source;
        $source = $upstreamUrl;
    }

    return [
        'url' => generated_image_proxy_url($taskId, $index),
        'proxyUrl' => generated_image_proxy_url($taskId, $index),
        'sourceType' => $sourceType,
        'source' => $source,
        'mimeType' => $mimeType,
        'absoluteUrl' => public_base_url() . generated_image_proxy_url($taskId, $index),
        'upstreamUrl' => $upstreamUrl !== '' ? $upstreamUrl : null,
        'revisedPrompt' => $item['revised_prompt'] ?? ($item['revisedPrompt'] ?? ''),
    ];
}

function image_item_from_task(array $task, int $index): ?array
{
    $images = is_array($task['images'] ?? null) ? $task['images'] : [];
    $item = $images[$index] ?? null;
    return is_array($item) ? $item : null;
}

function request_api_key(): string
{
    $header = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($header !== '') return trim($header);

    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
        return trim($m[1]);
    }

    $cookie = (string)($_COOKIE[api_key_cookie_name()] ?? '');
    if ($cookie !== '') return trim($cookie);

    return '';
}

function image_url_accepts_api_key(string $source): bool
{
    $scheme = parse_url($source, PHP_URL_SCHEME);
    $sourceHost = parse_url($source, PHP_URL_HOST);
    $sourcePort = parse_url($source, PHP_URL_PORT);

    if (!is_string($scheme) || strcasecmp($scheme, 'https') !== 0) return false;
    if (!is_string($sourceHost) || $sourceHost === '') return false;
    if ($sourcePort !== null && (int)$sourcePort !== 443) return false;

    foreach ([API_BASE_URL, ASSET_BASE_URL] as $trustedBaseUrl) {
        $trustedHost = parse_url($trustedBaseUrl, PHP_URL_HOST);
        if (is_string($trustedHost) && strcasecmp($sourceHost, $trustedHost) === 0) {
            return true;
        }
    }

    return false;
}

function image_download_headers(string $source, string $apiKey): array
{
    if ($apiKey === '' || !image_url_accepts_api_key($source)) return [];
    return ['Authorization: Bearer ' . $apiKey];
}

function acquire_image_download_slot()
{
    $directory = root_path('storage/image-download-slots');
    ensure_dir($directory);

    for ($index = 0; $index < MAX_CONCURRENT_IMAGE_DOWNLOADS; $index++) {
        $handle = fopen($directory . '/slot-' . $index . '.lock', 'c');
        if ($handle === false) continue;
        if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
        fclose($handle);
    }

    return null;
}

function release_image_download_slot($handle): void
{
    if (!is_resource($handle)) return;
    flock($handle, LOCK_UN);
    fclose($handle);
}

function image_bytes_from_task_image(array $image, string $apiKey = ''): array
{
    $sourceType = (string)($image['sourceType'] ?? '');
    $source = (string)($image['source'] ?? '');
    if ($source === '') {
        throw new RuntimeException('Image source is missing.');
    }

    if ($sourceType === 'data_url') {
        $bytes = base64_decode(strip_data_url($source), true);
        if ($bytes === false) throw new RuntimeException('Invalid base64 image data.');
        $hint = (string)($image['mimeType'] ?? data_url_mime($source));
        assert_image_bytes($bytes, $hint);
        return [
            'bytes' => $bytes,
            'contentType' => image_content_type_from_bytes($bytes, $hint) ?: 'image/png',
        ];
    }

    if ($sourceType === 'b64_json') {
        $bytes = base64_decode($source, true);
        if ($bytes === false) throw new RuntimeException('Invalid base64 image data.');
        $hint = (string)($image['mimeType'] ?? '');
        assert_image_bytes($bytes, $hint);
        return [
            'bytes' => $bytes,
            'contentType' => image_content_type_from_bytes($bytes, $hint) ?: 'image/png',
        ];
    }

    $headers = image_download_headers($source, $apiKey);
    $result = http_request($source, 'GET', $headers, null, IMAGE_DOWNLOAD_TIMEOUT_SECONDS, '图片读取超时，请稍后重试。');
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException('Image download failed: ' . $result['status'] . ' ' . substr($result['body'], 0, 300));
    }
    assert_image_bytes($result['body'], $result['contentType']);

    return [
        'bytes' => $result['body'],
        'contentType' => image_content_type_from_bytes($result['body'], $result['contentType']) ?: 'image/png',
    ];
}

function public_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function gemini_inline_data_part(string $reference): array
{
    $data = (string)preg_replace('/\s+/', '', strip_data_url($reference));
    if ($data === '' || base64_decode($data, true) === false) {
        throw new RuntimeException('参考图不是有效的 Base64 图片数据。');
    }

    return [
        'inlineData' => [
            'mimeType' => data_url_mime($reference),
            'data' => $data,
        ],
    ];
}

function build_gemini_image_request(string $model, string $prompt, string $ratio, string $resolution, array $references): array
{
    $parts = [['text' => $prompt]];
    $summaryParts = [['text' => $prompt]];
    foreach ($references as $reference) {
        $part = gemini_inline_data_part((string)$reference);
        $parts[] = $part;
        $summaryParts[] = [
            'inlineData' => [
                'mimeType' => $part['inlineData']['mimeType'],
                'data_length' => strlen($part['inlineData']['data']),
            ],
        ];
    }

    $generationConfig = [
        'responseModalities' => ['IMAGE'],
        'imageConfig' => [
            'aspectRatio' => $ratio,
            'imageSize' => $resolution,
        ],
    ];
    $body = [
        'contents' => [[
            'role' => 'user',
            'parts' => $parts,
        ]],
        'generationConfig' => $generationConfig,
    ];

    return [
        'provider' => 'gemini',
        'endpoint' => '/v1beta/models/' . rawurlencode($model) . ':generateContent',
        'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'isMultipart' => false,
        'tempFiles' => [],
        'summaryBody' => [
            'contents' => [[
                'role' => 'user',
                'parts' => $summaryParts,
            ]],
            'generationConfig' => $generationConfig,
            'model' => $model,
            'image_count' => count($references),
        ],
    ];
}

function build_upstream_request(array $payload, ?int $countOverride = null): array
{
    $requestedModel = requested_model($payload);
    $upstreamModel = upstream_model_name($requestedModel);
    $ratio = in_array(($payload['ratio'] ?? '1:1'), SUPPORTED_RATIOS, true) ? $payload['ratio'] : '1:1';
    $resolution = strtoupper((string)($payload['resolution'] ?? '1K'));
    $size = $payload['exactSize'] ?? ($payload['size'] ?? calculate_exact_size($ratio, $resolution));
    $references = reference_images_from_payload($payload);
    $count = $countOverride ?? requested_image_count($payload);
    $model = $upstreamModel;
    $isEdit = count($references) > 0;
    $userPrompt = prompt_with_reference_map($payload, (string)$payload['prompt']);

    if (is_nano_banana_model($requestedModel)) {
        return build_gemini_image_request($model, $userPrompt, $ratio, $resolution, $references);
    }

    $prompt = (($payload['lockRatio'] ?? true) === false)
        ? $userPrompt
        : "Make the aspect ratio {$ratio}. Output size {$size}.\n" . $userPrompt;

    $endpoint = $isEdit ? '/v1/images/edits' : '/v1/images/generations';

    if ($isEdit) {
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => (string)$count,
            'size' => $size,
            'quality' => $payload['quality'] ?? 'auto',
            'response_format' => 'url',
        ];
        $tempFiles = [];
        foreach ($references as $index => $reference) {
            $tmp = tempnam(sys_get_temp_dir(), 'img_ref_');
            file_put_contents($tmp, base64_decode(strip_data_url((string)$reference)));
            $tempFiles[] = $tmp;
            $field = count($references) === 1 ? 'image' : 'image[' . $index . ']';
            $body[$field] = new CURLFile($tmp, data_url_mime((string)$reference), 'reference_' . $index . '.png');
        }
        return [
            'provider' => 'openai',
            'endpoint' => $endpoint,
            'body' => $body,
            'isMultipart' => true,
            'tempFiles' => $tempFiles,
            'summaryBody' => [
                'model' => $model,
                'prompt' => $prompt,
                'n' => $count,
                'size' => $size,
                'quality' => $payload['quality'] ?? 'auto',
                'response_format' => 'url',
                'image_count' => count($references),
            ],
        ];
    }

    $body = [
        'model' => $model,
        'prompt' => $prompt,
        'n' => $count,
        'size' => $size,
        'quality' => $payload['quality'] ?? 'auto',
        'response_format' => 'url',
    ];

    if ($model === 'gpt-image-2' && count($references) > 0) {
        $body['reference_images'] = [];
        foreach ($references as $reference) {
            $reference = (string)$reference;
            $body['reference_images'][] = starts_with($reference, 'data:') ? $reference : 'data:image/png;base64,' . $reference;
        }
    }

    return [
        'provider' => 'openai',
        'endpoint' => $endpoint,
        'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'isMultipart' => false,
        'tempFiles' => [],
        'summaryBody' => $body,
    ];
}

function upstream_error_message(array $json, string $fallback): string
{
    $candidates = [
        $json['error']['message'] ?? null,
        $json['error']['code'] ?? null,
        $json['error']['type'] ?? null,
        $json['message'] ?? null,
        $json['msg'] ?? null,
        $json['error'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return $fallback !== '' ? $fallback : '上游接口返回错误，但没有提供具体原因。';
}

function gemini_image_urls_from_text(string $text): array
{
    $urls = [];
    $patterns = [
        '/!\[[^\]]*\]\(\s*(https?:\/\/[^\s)]+)\s*\)/i',
        '/https?:\/\/[^\s<>"\')]+/i',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $text, $matches)) continue;
        foreach (($matches[1] ?? $matches[0] ?? []) as $url) {
            $url = rtrim(trim((string)$url), '.,;');
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[$url] = true;
            }
        }
        if (count($urls) > 0) break;
    }

    return array_keys($urls);
}

function gemini_items_from_response(array $json): array
{
    $items = [];
    $seenSources = [];

    foreach (($json['candidates'] ?? []) as $candidate) {
        $parts = $candidate['content']['parts'] ?? [];
        if (!is_array($parts)) continue;

        foreach ($parts as $part) {
            if (!is_array($part)) continue;
            $inlineData = null;
            if (is_array($part['inlineData'] ?? null)) {
                $inlineData = $part['inlineData'];
            } elseif (is_array($part['inline_data'] ?? null)) {
                $inlineData = $part['inline_data'];
            }

            if (!is_array($inlineData)) continue;

            $mimeType = trim((string)($inlineData['mimeType'] ?? ($inlineData['mime_type'] ?? 'image/png')));
            $data = trim((string)($inlineData['data'] ?? ''));
            if ($data === '') continue;

            $source = 'data:' . ($mimeType !== '' ? $mimeType : 'image/png') . ';base64,' . $data;
            $seenSources[$source] = true;
            $items[] = [
                'image' => $source,
            ];
        }

        foreach ($parts as $part) {
            if (!is_array($part)) continue;
            $text = trim((string)($part['text'] ?? ''));
            if ($text === '') continue;

            foreach (gemini_image_urls_from_text($text) as $url) {
                if (isset($seenSources[$url])) continue;
                $seenSources[$url] = true;
                $items[] = ['url' => $url];
            }
        }
    }

    return $items;
}

function execute_upstream_request(array $upstream, string $apiKey): array
{
    $headers = ($upstream['provider'] ?? 'openai') === 'gemini'
        ? ['x-goog-api-key: ' . $apiKey]
        : ['Authorization: Bearer ' . $apiKey];
    if (!$upstream['isMultipart']) {
        $headers[] = 'Content-Type: application/json';
    }

    $result = http_request(API_BASE_URL . $upstream['endpoint'], 'POST', $headers, $upstream['body']);
    $json = json_decode($result['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException('Upstream returned non-JSON: ' . substr($result['body'], 0, 300));
    }
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException(upstream_error_message($json, $result['body']));
    }

    $items = ($upstream['provider'] ?? 'openai') === 'gemini'
        ? gemini_items_from_response($json)
        : (is_array($json['data'] ?? null) ? $json['data'] : []);
    if (count($items) === 0) throw new RuntimeException(upstream_error_message($json, '上游接口没有返回图片数据。'));

    return ['json' => $json, 'items' => $items];
}

function summarize_upstream_response(array $json): array
{
    if (is_array($json['candidates'] ?? null)) {
        $items = [];
        foreach (gemini_items_from_response($json) as $item) {
            $source = (string)($item['image'] ?? ($item['url'] ?? ''));
            $items[] = [
                'url_type' => starts_with($source, 'data:') ? 'data_url' : (starts_with($source, 'http') ? 'url' : 'unknown'),
                'url_length' => strlen($source),
            ];
        }

        return [
            'created' => null,
            'task_id' => $json['responseId'] ?? null,
            'data' => $items,
        ];
    }

    $items = [];
    foreach (($json['data'] ?? []) as $item) {
        $url = (string)($item['url'] ?? '');
        $b64 = (string)($item['b64_json'] ?? '');
        $items[] = [
            'file_id' => $item['file_id'] ?? null,
            'revised_prompt' => $item['revised_prompt'] ?? ($item['revisedPrompt'] ?? ''),
            'url_type' => starts_with($url, 'data:') ? 'data_url' : ($url !== '' ? 'url' : ($b64 !== '' ? 'b64_json' : 'unknown')),
            'url_length' => strlen($url ?: $b64),
        ];
    }
    return [
        'created' => $json['created'] ?? null,
        'task_id' => $json['task_id'] ?? null,
        'data' => $items,
    ];
}

function process_task(array $task, string $apiKey): array
{
    $tempFiles = [];
    try {
        ignore_user_abort(true);
        $payload = $task['payload'] ?? $task['request'];
        $requestedCount = requested_image_count($payload);
        // Plan A: one upstream request per image. This avoids gateway instability with n > 1.
        $useSequentialBatch = $requestedCount > 1;
        $totalBudget = REQUEST_TIMEOUT_SECONDS * max(1, $requestedCount) + 60;
        @set_time_limit($totalBudget);
        @ini_set('max_execution_time', (string)$totalBudget);

        $task['status'] = 'running';
        $task['progress'] = 10;
        $task['startedAt'] = (int)($task['startedAt'] ?? now_ms());
        $task['updatedAt'] = now_ms();
        save_task($task['id'], $task);

        $upstreams = [];
        if ($useSequentialBatch) {
            for ($i = 0; $i < $requestedCount; $i++) {
                $upstreams[] = build_upstream_request($payload, 1);
            }
        } else {
            $upstreams[] = build_upstream_request($payload);
        }

        foreach ($upstreams as $upstream) {
            foreach (($upstream['tempFiles'] ?? []) as $tmp) {
                $tempFiles[] = $tmp;
            }
        }

        $task['progress'] = 25;
        $task['upstream'] = [
            'endpoint' => $upstreams[0]['endpoint'],
            'body' => $useSequentialBatch ? array_map(fn($item) => $item['summaryBody'], $upstreams) : $upstreams[0]['summaryBody'],
            'mode' => $useSequentialBatch ? 'sequential_batch' : 'single_request',
            'batchCount' => count($upstreams),
        ];
        save_task($task['id'], $task);

        $items = [];
        $responses = [];
        foreach ($upstreams as $batchIndex => $upstream) {
            $executed = execute_upstream_request($upstream, $apiKey);
            $responses[] = summarize_upstream_response($executed['json']);
            foreach ($executed['items'] as $item) {
                $items[] = $item;
            }
            $task['progress'] = min(74, 25 + (int)floor(45 * (($batchIndex + 1) / max(1, count($upstreams)))));
            $task['upstream']['response'] = $useSequentialBatch ? $responses : $responses[0];
            save_task($task['id'], $task);
        }

        $task['progress'] = 75;
        save_task($task['id'], $task);

        $images = [];
        foreach ($items as $index => $item) {
            $images[] = normalize_generated_image($task['id'], $item, $index);
        }

        $task['status'] = 'succeeded';
        $task['progress'] = 100;
        $task['images'] = $images;
        $task['upstreamTaskId'] = $responses[0]['task_id'] ?? null;
        $task['finishedAt'] = now_ms();
        $task['durationMs'] = max(0, (int)$task['finishedAt'] - (int)($task['startedAt'] ?? $task['createdAt'] ?? $task['finishedAt']));
        $task['error'] = null;
    } catch (Throwable $e) {
        $task['status'] = 'failed';
        $task['progress'] = 100;
        $task['finishedAt'] = now_ms();
        $task['durationMs'] = max(0, (int)$task['finishedAt'] - (int)($task['startedAt'] ?? $task['createdAt'] ?? $task['finishedAt']));
        $task['error'] = $e->getMessage();
    } finally {
        foreach ($tempFiles as $tmp) {
            if (is_string($tmp) && is_file($tmp)) @unlink($tmp);
        }
    }

    if (($task['status'] ?? '') === 'succeeded' || ($task['status'] ?? '') === 'failed') {
        unset($task['payload']);
    }
    $task['updatedAt'] = now_ms();
    return $task;
}

