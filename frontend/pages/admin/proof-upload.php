<?php
require_once __DIR__ . '/../../includes/config.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

$orderId = trim((string)($_POST['order_id'] ?? ''));
$action = trim((string)($_POST['action'] ?? ''));
if ($orderId === '' || $action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

$uploadDir = __DIR__ . '/../../uploads/proof-of-delivery/';
$uploadWeb = BASE_URL . '/uploads/proof-of-delivery/';
$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$maxSize = 5 * 1024 * 1024;

function normalizeProofPath(string $path): string {
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (strpos($path, BASE_URL) === 0) {
        return substr($path, strlen(BASE_URL));
    }
    return $path;
}

function unlinkProofFile(string $proofUrl, string $uploadDir, string $uploadWeb): void {
    $proofUrl = trim($proofUrl);
    if ($proofUrl === '') {
        return;
    }
    if (strpos($proofUrl, $uploadWeb) !== 0) {
        return;
    }
    $localPath = $uploadDir . basename($proofUrl);
    if (is_file($localPath)) {
        @unlink($localPath);
    }
}

if ($action === 'upload_proof') {
    if (empty($_FILES['proof_image']) || (int)($_FILES['proof_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No proof image uploaded.']);
        exit;
    }

    $file = $_FILES['proof_image'];
    if (!isset($allowedTypes[$file['type']])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
        exit;
    }
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File exceeds 5MB.']);
        exit;
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to create upload directory.']);
        exit;
    }

    $ext = $allowedTypes[$file['type']];
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $orderId);
    $filename = sprintf('proof_%s_%s.%s', $safeId, bin2hex(random_bytes(10)), $ext);
    $destination = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not save proof image.']);
        exit;
    }

    $proofImageUrl = $uploadWeb . $filename;
    $oldProofUrl = trim((string)($_POST['replace_proof_url'] ?? ''));
    if ($oldProofUrl !== '') {
        unlinkProofFile($oldProofUrl, $uploadDir, $uploadWeb);
    }

    $payload = [
        'status' => 'delivered',
        'delivery_status' => 'delivered',
        'proof_of_delivery_image' => $proofImageUrl,
        'proof_uploaded_at' => date('Y-m-d H:i:s'),
        'proof_uploaded_by' => $_SESSION['user']['id'] ?? null,
    ];

    $apiResult = api_update_order_status($orderId, $payload, true);
    if ($apiResult['status'] !== 200) {
        @unlink($destination);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $apiResult['body']['message'] ?? 'Order update failed.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Proof uploaded and order marked delivered.', 'proof_url' => $proofImageUrl]);
    exit;
}

if ($action === 'delete_proof') {
    $proofUrl = trim((string)($_POST['proof_url'] ?? ''));
    if ($proofUrl === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Proof URL is required.']);
        exit;
    }

    $revertOrderStatus = trim((string)($_POST['revert_order_status'] ?? '')) ?: 'shipped';
    $revertDeliveryStatus = trim((string)($_POST['revert_delivery_status'] ?? '')) ?: 'out_for_delivery';

    unlinkProofFile($proofUrl, $uploadDir, $uploadWeb);

    $payload = [
        'proof_of_delivery_image' => null,
        'proof_uploaded_at' => null,
        'proof_uploaded_by' => null,
        'status' => $revertOrderStatus,
        'delivery_status' => $revertDeliveryStatus,
    ];

    $apiResult = api_update_order_status($orderId, $payload, true);
    if ($apiResult['status'] !== 200) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $apiResult['body']['message'] ?? 'Proof delete failed.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Proof deleted and order status updated.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action.']);
exit;
