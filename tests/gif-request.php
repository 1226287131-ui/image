<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/lib.php';

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $label);
    $checks++;
}

function rejects(array $payload, string $label): void
{
    try {
        summarize_request($payload);
    } catch (InvalidArgumentException $e) {
        check(true, $label);
        return;
    }
    throw new RuntimeException('FAIL: accepted ' . $label);
}

$gif = ['model' => GPT_IMAGE_2_6_SUNBURST_MODEL, 'prompt' => 'A cat waving, seamless loop.', 'output_format' => 'gif'];
foreach ([GPT_IMAGE_2_6_FLARE_MODEL, GPT_IMAGE_2_6_SUNBURST_MODEL] as $model) {
    $payload = array_replace($gif, ['model' => $model]);
    $request = build_upstream_request($payload);
    $body = json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR);
    check($request['endpoint'] === '/v1/images/generations' && !$request['isMultipart'], 'GIF JSON generation endpoint');
    check($body === ['model' => $model, 'prompt' => $gif['prompt'], 'n' => 1, 'response_format' => 'url', 'output_format' => 'gif', 'frame_count' => 9, 'frame_duration_ms' => 200, 'size' => '512x512'], 'exact GIF default body and original model');
    check(!isset($body['quality'], $body['resolution'], $body['exactSize']), 'no still image options');
    $summary = summarize_request($payload);
    check($summary['output_format'] === 'gif' && $summary['exactSize'] === '512x512' && $summary['count'] === 1, 'GIF history metadata');
}

foreach ([2, 16] as $frames) {
    check(gif_request_options(array_replace($gif, ['frame_count' => $frames]))['frame_count'] === $frames, 'frame boundary');
}
foreach ([20 => 20, 204 => 200, 205 => 210, 2000 => 2000] as $duration => $expected) {
    check(gif_request_options(array_replace($gif, ['frame_duration_ms' => $duration]))['frame_duration_ms'] === $expected, '10ms rounding');
}
foreach (['auto', '16x32', '768x512', '99984x16'] as $size) {
    check(gif_request_options(array_replace($gif, ['size' => $size]))['size'] === $size, 'GIF size allowed');
}
foreach ([1, 10] as $count) {
    foreach (['count', 'n'] as $key) {
        $request = build_upstream_request(array_replace($gif, [$key => $count]));
        check($request['summaryBody']['n'] === $count, 'count aliases and boundaries');
    }
}
check(build_upstream_request(array_replace($gif, ['n' => 10]), 1)['summaryBody']['n'] === 1, 'existing sequential batch override');
check(build_upstream_request(array_replace($gif, ['quality' => 'high', 'resolution' => '4K', 'exactSize' => '3840x2160']))['summaryBody']['size'] === '512x512', 'independent GIF pixel budget');

foreach (['gpt-image-2', GPT_IMAGE_2_5_FLARE_MODEL, GPT_IMAGE_2_5_SUNBURST_MODEL, GPT_IMAGE_2_6_CF_MODEL, NANO_BANANA_2_MODEL, NANO_BANANA_PRO_MODEL, 'unknown'] as $model) {
    rejects(array_replace($gif, ['model' => $model]), 'unsupported GIF model');
}
foreach (['frame_count' => [1, 17, 2.5, '9', false], 'frame_duration_ms' => [19, 2001, '200', false, INF], 'size' => ['', '512x513', '0x512', '1024', '512:512', '100000x16'], 'n' => [0, 11, 1.5, '1'], 'count' => [0, 11, 1.5, '1']] as $key => $values) {
    foreach ($values as $value) rejects(array_replace($gif, [$key => $value]), 'invalid ' . $key);
}
foreach (['stream', 'partial_images', 'mask', 'output_compression', 'prompt_rewriter'] as $key) {
    rejects(array_replace($gif, [$key => false]), 'incompatible ' . $key);
}
rejects(array_replace($gif, ['referenceImages' => ['data:image/png;base64,AA==']]), 'GIF reference image');

$reference = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aA1cAAAAASUVORK5CYII=';
foreach ([[], [$reference]] as $references) {
    $still = ['model' => DEFAULT_IMAGE_MODEL, 'prompt' => 'still', 'ratio' => '3:2', 'resolution' => '2K', 'exactSize' => '1536x1024', 'quality' => 'high', 'count' => 2, 'referenceImages' => $references];
    $baseline = build_upstream_request($still);
    foreach ([GPT_IMAGE_2_6_FLARE_MODEL, GPT_IMAGE_2_6_SUNBURST_MODEL] as $model) {
        $request = build_upstream_request(array_replace($still, ['model' => $model]));
        $expected = array_replace($baseline['summaryBody'], ['model' => $model]);
        check($request['summaryBody'] === $expected && $request['endpoint'] === $baseline['endpoint'] && $request['isMultipart'] === $baseline['isMultipart'], 'static GPT image protocol parity');
        foreach ($request['tempFiles'] as $file) @unlink($file);
    }
    foreach ($baseline['tempFiles'] as $file) @unlink($file);
}
check(build_upstream_request(['model' => NANO_BANANA_2_MODEL, 'prompt' => 'still', 'ratio' => '1:1'])['provider'] === 'gemini', 'Nano Banana native protocol retained');

$bytes = hex2bin('47494638396101000100800000ff00000000ff21ff0b4e45545343415045322e30030100000021f904000a0000002c000000000100010000020244010021f904000a0000002c00000000010001000002024c01003b');
check(image_content_type_from_bytes($bytes, 'image/png') === 'image/gif', 'GIF byte signature overrides incorrect MIME');
check(infer_extension('', 'image/gif') === 'gif', 'GIF extension');
$image = normalize_generated_image('task_test', ['url' => 'https://example.test/animated.gif'], 0);
check($image['sourceType'] === 'url' && $image['source'] === 'https://example.test/animated.gif', 'upstream data[].url retained');
$dataImage = normalize_generated_image('task_test', ['url' => 'data:image/gif;base64,' . base64_encode($bytes)], 0);
$decoded = image_bytes_from_task_image($dataImage);
check($decoded['bytes'] === $bytes && $decoded['contentType'] === 'image/gif', 'all animation bytes retained');

echo "PASS: {$checks} checks (no paid upstream calls).\n";
