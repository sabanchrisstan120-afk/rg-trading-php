<?php
require_once __DIR__ . '/../includes/config.php';
require_login();

$path = trim((string)($_GET['path'] ?? ''));
if ($path === '') {
    http_response_code(400);
    echo 'Missing proof path.';
    exit;
}

if (preg_match('/^https?:\/\//i', $path)) {
    header('Location: ' . $path);
    exit;
}

if ($path[0] !== '/') {
    $path = '/' . $path;
}

if (strpos($path, '/uploads/') !== 0) {
    http_response_code(400);
    echo 'Invalid proof path.';
    exit;
}

$local_file = dirname(__DIR__) . $path;
if (is_file($local_file)) {
    $mime = mime_content_type($local_file) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($local_file));
    readfile($local_file);
    exit;
}

function fetch_remote_proof(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: image/*,*/*;q=0.8']);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $code !== 200 || $body === '') {
        return null;
    }

    if ($content_type === '') {
        $content_type = 'application/octet-stream';
    }

    return ['body' => $body, 'content_type' => $content_type];
}

$api_root = preg_replace('#/api/?$#', '', rtrim(API_BASE, '/'));
$candidates = [
    $api_root . $path,
    rtrim(API_BASE, '/') . $path,
];

foreach ($candidates as $candidate) {
    $result = fetch_remote_proof($candidate);
    if ($result !== null) {
        header('Content-Type: ' . $result['content_type']);
        echo $result['body'];
        exit;
    }
}

http_response_code(404);
echo 'Proof image not found.';
