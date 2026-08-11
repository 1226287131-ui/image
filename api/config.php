<?php
require_once __DIR__ . '/lib.php';

json_response([
    'apiBaseUrl' => API_BASE_URL,
    'acceptsUserKey' => true,
    'requiresUserKey' => true,
    'ratios' => SUPPORTED_RATIOS,
    'resolutions' => ['1K', '2K', '4K'],
    'qualities' => ['auto', 'low', 'medium', 'high'],
]);
