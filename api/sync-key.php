<?php
require_once __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = read_json_body();
    $apiKey = trim((string)($input['apiKey'] ?? ''));
    if ($apiKey === '') {
        clear_api_key_cookie();
        json_response(['ok' => true, 'synced' => false]);
    }

    sync_api_key_cookie($apiKey);
    json_response(['ok' => true, 'synced' => true]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    clear_api_key_cookie();
    json_response(['ok' => true, 'synced' => false]);
}

json_response(['error' => 'Method not allowed.'], 405);
