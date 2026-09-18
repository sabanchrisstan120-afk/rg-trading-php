<?php
// ─── Environment Helpers ───────────────────────────────────────────────────────
function is_local_development_environment(): bool {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $server_addr = strtolower((string)($_SERVER['SERVER_ADDR'] ?? ''));
    $app_env = strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ($_SERVER['APP_ENV'] ?? ''))));

    return $app_env === 'development'
        || $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || $server_addr === '127.0.0.1'
        || $server_addr === '::1';
}

function resolve_api_base(): string {
    $envBase = getenv('API_BASE') ?: ($_ENV['API_BASE'] ?? ($_SERVER['API_BASE'] ?? ''));
    if (!empty($envBase)) {
        return rtrim($envBase, '/');
    }

    if (is_local_development_environment()) {
        return 'http://localhost:3000/api';
    }

    return '';
}

// ─── API Configuration ────────────────────────────────────────────────────────
define('API_BASE', resolve_api_base());
define('APP_NAME', 'R&G Trading');
define('BASE_URL', '/rg-trading-php');
define('GCASH_QR_IMAGE', BASE_URL . '/assets/img/gcash_qr.png');
define('MAYA_QR_IMAGE', BASE_URL . '/assets/img/maya_qr.png');
define('PAYMENT_QR_PLACEHOLDER_IMAGE', BASE_URL . '/assets/img/payment-placeholder.png');
define('GCASH_ACCOUNT_NAME', 'R&G Trading');
define('GCASH_ACCOUNT_NUMBER', '09XXXXXXXXX');
define('MAYA_ACCOUNT_NAME', 'R&G Trading');
define('MAYA_ACCOUNT_NUMBER', '09XXXXXXXXX');
define('ORDER_STATE_STORE_FILE', __DIR__ . '/../uploads/order-state-store.json');

// ─── Direct DB Configuration (used only for password reset) ──────────────────
// The Node.js API has no password-reset route, so the forgot-password flow
// updates users.password_hash directly. Use environment configuration for
// production and keep localhost as a local development default only.
define('DB_HOST', getenv('DB_HOST') ?: (is_local_development_environment() ? 'localhost' : ''));
define('DB_NAME', getenv('DB_NAME') ?: (is_local_development_environment() ? 'rg_trading' : ''));
define('DB_USER', getenv('DB_USER') ?: (is_local_development_environment() ? 'root' : ''));
define('DB_PASS', getenv('DB_PASS') ?: '');

function get_db_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (empty(DB_HOST) || empty(DB_NAME) || empty(DB_USER)) {
            throw new RuntimeException('Database configuration is missing. Set DB_HOST, DB_NAME, and DB_USER in the production environment.');
        }

        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

/**
 * Directly resets a user's password by email, bypassing the API.
 * Returns true on success, false if no user with that email exists,
 * or a string with the failure reason on a DB error.
 */
function db_reset_password(string $email, string $newPassword) {
    try {
        $pdo = get_db_pdo();

        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if (!$check->fetch()) {
            return false;
        }

        // bcrypt, cost 12 — matches the $2a$12$... hashes already in the
        // users table, so password_verify() on the Node API side keeps working.
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        $update = $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE email = ?');
        $update->execute([$hash, $email]);

        return true;
    } catch (PDOException $e) {
        error_log('db_reset_password failed: ' . $e->getMessage());
        return $e->getMessage();
    }
}

// ─── Session Start ────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    $session_lifetime = getenv('SESSION_LIFETIME') ?: 3600;
    $secure_cookie = (!empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
    session_set_cookie_params([
        'lifetime' => (int)$session_lifetime,
        'path' => '/',
        'secure' => $secure_cookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function http_request_json_and_status(string $method, string $url, array $data = [], array $headers = []): array {
    $method = strtoupper($method);
    $payload = null;

    if (!empty($data) && !in_array($method, ['GET', 'HEAD'], true)) {
        $payload = json_encode($data);
    }

    $contextHeaders = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
    ], $headers);

    $contextOptions = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $contextHeaders),
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ];

    if ($payload !== null) {
        $contextOptions['http']['content'] = $payload;
    }

    $context = stream_context_create($contextOptions);
    $response = @file_get_contents($url, false, $context);
    $status = 200;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (stripos($header, 'HTTP/') === 0) {
                if (preg_match('/\b(\d{3})\b/', $header, $matches)) {
                    $status = (int)$matches[1];
                }
                break;
            }
        }
    }

    if ($response === false) {
        return ['status' => $status === 200 ? 0 : $status, 'body' => []];
    }

    $decoded = json_decode($response, true);
    return ['status' => $status, 'body' => $decoded ?? []];
}

// ─── API Helper: Send request to Node.js backend ─────────────────────────────
function api_request(string $method, string $endpoint, array $data = [], bool $auth = false): array {
    if (empty(API_BASE)) {
        return [
            'status' => 0,
            'body' => ['success' => false, 'message' => 'API_BASE is not configured for this environment. Set API_BASE to the production backend URL.'],
        ];
    }

    $url = API_BASE . $endpoint;
    $headers = ['Content-Type: application/json'];

    if ($auth && isset($_SESSION['access_token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['access_token'];
    }

    return http_request_json_and_status($method, $url, $data, $headers);
}

function api_request_with_fallback(string $method, array $endpoints, array $data = [], bool $auth = false): array {
    $last_response = ['status' => 0, 'body' => []];

    foreach ($endpoints as $endpoint) {
        $response = api_request($method, $endpoint, $data, $auth);
        $last_response = $response;

        $status = (int)($response['status'] ?? 0);
        if ($status >= 200 && $status < 400) {
            return $response;
        }
    }

    return $last_response;
}

function generate_order_number(): string {
    $prefix = 'RG-' . date('Ymd') . '-';
    do {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $order_number = $prefix . $suffix;
        $pdo = get_db_pdo();
        $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = ? LIMIT 1');
        $stmt->execute([$order_number]);
        $exists = $stmt->fetch();
    } while ($exists);

    return $order_number;
}

function create_order_direct(array $payload, array $product, int $quantity): array {
    $user_id = $_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? null);
    if (empty($user_id)) {
        return ['status' => 401, 'body' => ['success' => false, 'message' => 'User session is missing.']];
    }

    $product_name = trim((string)($product['name'] ?? 'Unknown Product'));
    $model_number = trim((string)($product['model_number'] ?? ''));
    $unit_price = (float)($product['price'] ?? 0);
    $quantity = max(1, intval($quantity));
    $contact_number = trim((string)($payload['contact_number'] ?? $payload['phone'] ?? $payload['customer_phone'] ?? ''));
    $payment_method = normalize_payment_method($payload['payment_method'] ?? 'gcash');
    $payment_reference = trim((string)($payload['payment_reference'] ?? ''));
    $address_id = $payload['address_id'] ?? null;
    $status = in_array($payload['status'] ?? 'pending', order_status_options(), true)
        ? $payload['status']
        : 'pending';
    $payment_status = in_array($payload['payment_status'] ?? 'pending', payment_status_options(), true)
        ? $payload['payment_status']
        : 'pending';

    // Authoritative shipping fee: retrieve server-side configured value.
    // Ignore any client-supplied shipping_fee in the payload for security.
    $shipping_fee = get_setting_float('shipping_fee', 500.00);
    if ($shipping_fee === null) {
        return ['status' => 500, 'body' => ['success' => false, 'message' => 'Invalid shipping fee configuration.']];
    }

    $subtotal = $unit_price * $quantity;
    $total_amount = $subtotal + $shipping_fee;
    $notes = trim((string)($payload['notes'] ?? ''));

    try {
        $pdo = get_db_pdo();
        $pdo->beginTransaction();

        $order_id = $pdo->query('SELECT UUID()')->fetchColumn();
        $order_number = generate_order_number();

        $stmt = $pdo->prepare('INSERT INTO orders (
            id, order_number, user_id, contact_number, payment_reference, address_id, status, payment_status,
            payment_method, subtotal, discount_amount, shipping_fee, total_amount, notes, ordered_at, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, NOW(), NOW(), NOW()
        )');

        $stmt->execute([
            $order_id,
            $order_number,
            $user_id,
            $contact_number !== '' ? $contact_number : null,
            $payment_reference !== '' ? $payment_reference : null,
            $address_id,
            $status,
            $payment_status,
            $payment_method,
            $subtotal,
            $shipping_fee,
            $total_amount,
            $notes !== '' ? $notes : null,
        ]);

        $item_id = $pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare('INSERT INTO order_items (id, order_id, product_id, product_name, model_number, quantity, unit_price, total_price, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([
                $item_id,
                $order_id,
                $payload['items'][0]['product_id'] ?? null,
                $product_name,
                $model_number,
                $quantity,
                $unit_price,
                $subtotal,
            ]);

        $delivery_id = $pdo->query('SELECT UUID()')->fetchColumn();
        $pdo->prepare('INSERT INTO order_deliveries (id, order_id, expected_delivery_date, delivery_status, created_at, updated_at)
            VALUES (?, ?, NULL, ?, NOW(), NOW())')
            ->execute([$delivery_id, $order_id, 'pending']);

        $pdo->commit();

        return [
            'status' => 201,
            'body' => [
                'success' => true,
                'message' => 'Order created successfully.',
                'data' => [
                    'order' => [
                        'id' => $order_id,
                        'order_number' => $order_number,
                        'payment_status' => $payment_status,
                        'payment_reference' => $payment_reference,
                        'contact_number' => $contact_number,
                        'payment_method' => $payment_method,
                    ],
                ],
            ],
        ];
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('create_order_direct failed: ' . $e->getMessage());
        return ['status' => 500, 'body' => ['success' => false, 'message' => 'Order creation failed in database fallback.']];
    }
}

function order_status_options(): array {
    return ['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
}

function delivery_status_options(): array {
    return ['pending', 'out_for_delivery', 'delivered', 'cannot_find_customer', 'failed', 'cancelled'];
}

function payment_method_options(): array {
    return ['gcash', 'maya'];
}

function payment_status_options(): array {
    return ['pending', 'paid', 'failed', 'refunded'];
}

function payment_status_label(string $status): string {
    $status = strtolower(trim($status));
    $labels = [
        'pending' => 'Pending Verification',
        'paid' => 'Paid',
        'failed' => 'Rejected',
        'refunded' => 'Refunded',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function normalize_payment_method(string $method): string {
    $method = strtolower(trim($method));
    return in_array($method, ['gcash', 'maya'], true) ? $method : 'gcash';
}

function is_valid_payment_reference(string $reference): bool {
    $reference = trim((string) $reference);
    if ($reference === '') {
        return false;
    }

    return preg_match('/^[A-Za-z0-9#\+\-\/\.\s]{4,80}$/', $reference) === 1;
}

function resolve_qr_asset_path(string $method): string {
    $method = normalize_payment_method($method);
    $file_name = $method === 'maya' ? 'maya_qr.png' : 'gcash_qr.png';
    $local_path = __DIR__ . '/../assets/img/' . $file_name;

    if (file_exists($local_path) && is_file($local_path)) {
        return $method === 'maya' ? MAYA_QR_IMAGE : GCASH_QR_IMAGE;
    }

    return PAYMENT_QR_PLACEHOLDER_IMAGE;
}

function payment_settings_defaults(string $method): array {
    $method = normalize_payment_method($method);
    $is_maya = $method === 'maya';
    $qr_path = resolve_qr_asset_path($method);

    return [
        'payment_method' => $method,
        'qr_code_path' => $qr_path,
        'account_name' => $is_maya ? MAYA_ACCOUNT_NAME : GCASH_ACCOUNT_NAME,
        'account_number' => $is_maya ? MAYA_ACCOUNT_NUMBER : GCASH_ACCOUNT_NUMBER,
        'instructions' => $is_maya
            ? 'Scan the Maya QR code using your Maya app, then send the payment and enter the payment reference number below.'
            : 'Scan the GCash QR code using your mobile wallet, then send the payment and enter the payment reference number below.',
        'updated_at' => null,
    ];
}

function ensure_payment_settings_table(): void {
    $pdo = get_db_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        payment_method ENUM('gcash','maya') NOT NULL,
        qr_code_path VARCHAR(255) NOT NULL,
        account_name VARCHAR(255) DEFAULT NULL,
        account_number VARCHAR(255) DEFAULT NULL,
        instructions TEXT DEFAULT NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_payment_method (payment_method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $defaults = [
        ['gcash', resolve_qr_asset_path('gcash'), GCASH_ACCOUNT_NAME, GCASH_ACCOUNT_NUMBER, 'Scan the GCash QR code using your mobile wallet, then send the payment and enter the payment reference number below.'],
        ['maya', resolve_qr_asset_path('maya'), MAYA_ACCOUNT_NAME, MAYA_ACCOUNT_NUMBER, 'Scan the Maya QR code using your Maya app, then send the payment and enter the payment reference number below.'],
    ];

    foreach ($defaults as $row) {
        $insert = $pdo->prepare('INSERT INTO payment_settings (payment_method, qr_code_path, account_name, account_number, instructions)
            SELECT ?, ?, ?, ?, ?
            WHERE NOT EXISTS (
                SELECT 1 FROM payment_settings WHERE payment_method = ?
            )');

        $insert->execute([$row[0], $row[1], $row[2], $row[3], $row[4], $row[0]]);
    }

    // Ensure a generic settings table exists for small site-wide values
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (setting_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed a default shipping_fee if it doesn't exist yet. Do not overwrite an existing value.
        $seed = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) SELECT ?, ? WHERE NOT EXISTS (SELECT 1 FROM settings WHERE setting_key = ?)');
        $seed->execute(['shipping_fee', '500.00', 'shipping_fee']);
    } catch (Throwable $e) {
        // Non-fatal — settings table may be managed elsewhere. Log for visibility.
        error_log('ensure_settings_table failed: ' . $e->getMessage());
    }

}

/**
 * Get a site setting value as string. Returns null if not present.
 */
function get_setting(string $key, $default = null): ?string {
    try {
        $pdo = get_db_pdo();
        ensure_payment_settings_table(); // also ensures settings table via the helper above
        $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && array_key_exists('setting_value', $row)) {
            return $row['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('get_setting failed: ' . $e->getMessage());
    }
    return $default !== null ? (string)$default : null;
}

/**
 * Set a site setting value. Stores values as strings and updates `updated_at`.
 */
function set_setting(string $key, string $value): bool {
    try {
        $pdo = get_db_pdo();
        ensure_payment_settings_table();
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP');
        return (bool)$stmt->execute([$key, $value]);
    } catch (Throwable $e) {
        error_log('set_setting failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Convenience: get a setting as a validated float (2 decimals). Returns null if invalid.
 */
function get_setting_float(string $key, float $default = 0.0): ?float {
    $raw = get_setting($key, null);
    if ($raw === null) {
        // Persist the default so behavior remains stable
        $val = number_format($default, 2, '.', '');
        set_setting($key, $val);
        return $default;
    }

    // Normalize and validate numeric format
    $clean = trim((string)$raw);
    // Allow values like "500" or "500.0" or "500.00"
    if ($clean === '') return null;
    if (!is_numeric($clean)) return null;
    $float = (float)$clean;
    if ($float < 0) return null;
    // Round to 2 decimals
    return round($float, 2);
}
function load_payment_settings(string $method): array {
    $method = normalize_payment_method($method);
    ensure_payment_settings_table();

    $pdo = get_db_pdo();
    $stmt = $pdo->prepare('SELECT payment_method, qr_code_path, account_name, account_number, instructions, updated_at
        FROM payment_settings WHERE payment_method = ? LIMIT 1');
    $stmt->execute([$method]);
    $row = $stmt->fetch();

    if (!$row) {
        return payment_settings_defaults($method);
    }

    $default_qr = payment_settings_defaults($method)['qr_code_path'];
    $qr_code_path = trim((string)($row['qr_code_path'] ?? ''));
    $qr_path = $qr_code_path !== '' ? $qr_code_path : $default_qr;
    $file_url = parse_url($qr_path, PHP_URL_PATH);
    $local_file = $file_url !== false && $file_url !== '' ? ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../') . $file_url : null;

    if ($local_file !== null && !file_exists($local_file)) {
        $qr_path = $default_qr;
    }

    return [
        'payment_method' => $row['payment_method'] ?? $method,
        'qr_code_path' => $qr_path,
        'account_name' => $row['account_name'] ?: payment_settings_defaults($method)['account_name'],
        'account_number' => $row['account_number'] ?: payment_settings_defaults($method)['account_number'],
        'instructions' => $row['instructions'] ?: payment_settings_defaults($method)['instructions'],
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function manual_payment_config(string $method): array {
    $data = load_payment_settings($method);
    $method = normalize_payment_method($method);
    $label = $method === 'maya' ? 'Maya' : 'GCash';

    return [
        'label' => $label,
        'qr' => $data['qr_code_path'] ?? ($method === 'maya' ? MAYA_QR_IMAGE : GCASH_QR_IMAGE),
        'account_name' => $data['account_name'] ?? ($method === 'maya' ? MAYA_ACCOUNT_NAME : GCASH_ACCOUNT_NAME),
        'account_number' => $data['account_number'] ?? ($method === 'maya' ? MAYA_ACCOUNT_NUMBER : GCASH_ACCOUNT_NUMBER),
        'instructions' => $data['instructions'] ?? ($method === 'maya'
            ? 'Scan the Maya QR code using your Maya app, then send the payment and enter the payment reference number below.'
            : 'Scan the GCash QR code using your mobile wallet, then send the payment and enter the payment reference number below.'),
    ];
}

/**
 * Persists an admin order update directly to MySQL.
 *
 * `status` / `payment_status` belong to the `orders` table. `delivery_status`
 * and `expected_delivery_date` belong to the related `order_deliveries` row
 * (one-to-one via order_deliveries.order_id). The Node API has no route for
 * this yet, so — same pattern as db_reset_password() — we go straight to the
 * database rather than pretending the update happened.
 */
function api_update_order_status(string $order_id, array $payload = [], bool $auth = false): array {
    $order_id = trim($order_id);

    if ($order_id === '') {
        return ['status' => 400, 'body' => ['success' => false, 'message' => 'Missing order id.']];
    }

    if (empty($payload)) {
        return ['status' => 200, 'body' => ['success' => true, 'message' => 'No updates to apply.']];
    }

    $order_fields    = array_intersect_key($payload, array_flip(['status', 'payment_status', 'payment_reference', 'contact_number']));
    $delivery_fields = array_intersect_key($payload, array_flip(['delivery_status', 'expected_delivery_date']));

    // Validate against the same enums the DB columns actually accept, so a
    // bad value fails loudly here instead of erroring (or silently
    // truncating) inside MySQL.
    if (isset($order_fields['status']) && !in_array($order_fields['status'], order_status_options(), true)) {
        return ['status' => 422, 'body' => ['success' => false, 'message' => 'Invalid order status value.']];
    }
    if (isset($order_fields['payment_status']) && !in_array($order_fields['payment_status'], payment_status_options(), true)) {
        return ['status' => 422, 'body' => ['success' => false, 'message' => 'Invalid payment status value.']];
    }
    if (isset($order_fields['payment_reference'])) {
        $order_fields['payment_reference'] = trim((string) $order_fields['payment_reference']);
    }
    if (isset($order_fields['contact_number'])) {
        $order_fields['contact_number'] = trim((string) $order_fields['contact_number']);
    }
    if (isset($delivery_fields['delivery_status']) && !in_array($delivery_fields['delivery_status'], delivery_status_options(), true)) {
        return ['status' => 422, 'body' => ['success' => false, 'message' => 'Invalid delivery status value.']];
    }
    if (!empty($delivery_fields['expected_delivery_date'])) {
        $date = DateTime::createFromFormat('Y-m-d', $delivery_fields['expected_delivery_date']);
        if (!$date || $date->format('Y-m-d') !== $delivery_fields['expected_delivery_date']) {
            return ['status' => 422, 'body' => ['success' => false, 'message' => 'Invalid expected delivery date.']];
        }
    }

    $pdo = get_db_pdo();
    $order_columns = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
    $has_payment_reference_col = in_array('payment_reference', $order_columns, true);
    $has_contact_number_col = in_array('contact_number', $order_columns, true);

    if (isset($order_fields['payment_reference']) && !$has_payment_reference_col) {
        remember_order_update($order_id, ['payment_reference' => $order_fields['payment_reference']]);
        unset($order_fields['payment_reference']);
    }
    if (isset($order_fields['contact_number']) && !$has_contact_number_col) {
        remember_order_update($order_id, ['contact_number' => $order_fields['contact_number'], 'phone' => $order_fields['contact_number'], 'customer_phone' => $order_fields['contact_number']]);
        unset($order_fields['contact_number']);
    }

    try {
        $pdo->beginTransaction();

        $check = $pdo->prepare('SELECT id FROM orders WHERE id = ? LIMIT 1');
        $check->execute([$order_id]);
        if (!$check->fetch()) {
            $pdo->rollBack();
            return ['status' => 404, 'body' => ['success' => false, 'message' => 'Order not found.']];
        }

        if (!empty($order_fields)) {
            $set = [];
            $params = [];
            foreach ($order_fields as $col => $val) {
                $set[] = "`$col` = ?";
                $params[] = $val;
            }
            $params[] = $order_id;

            $column_names = array_keys($order_fields);
            $columns = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
            $valid_columns = array_flip($columns);
            $safe_fields = [];
            foreach ($order_fields as $col => $val) {
                if (isset($valid_columns[$col])) {
                    $safe_fields[$col] = $val;
                }
            }

            if (!empty($safe_fields)) {
                $safe_set = [];
                $safe_params = [];
                foreach ($safe_fields as $col => $val) {
                    $safe_set[] = "`$col` = ?";
                    $safe_params[] = $val;
                }
                $safe_params[] = $order_id;
                $pdo->prepare('UPDATE orders SET ' . implode(', ', $safe_set) . ', updated_at = NOW() WHERE id = ?')
                    ->execute($safe_params);
            }
        }

        if (!empty($delivery_fields)) {
            $existing = $pdo->prepare('SELECT id FROM order_deliveries WHERE order_id = ? LIMIT 1');
            $existing->execute([$order_id]);

            if ($existing->fetch()) {
                $set = [];
                $params = [];
                foreach ($delivery_fields as $col => $val) {
                    $set[] = "`$col` = ?";
                    $params[] = ($val === '' ? null : $val);
                }
                $params[] = $order_id;
                $pdo->prepare('UPDATE order_deliveries SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE order_id = ?')
                    ->execute($params);
            } else {
                $cols = array_keys($delivery_fields);
                $vals = array_map(fn($v) => ($v === '' ? null : $v), array_values($delivery_fields));
                $cols[] = 'order_id';
                $vals[] = $order_id;
                $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
                $placeholders = implode(', ', array_fill(0, count($cols), '?'));
                $pdo->prepare("INSERT INTO order_deliveries ($colList) VALUES ($placeholders)")
                    ->execute($vals);
            }
        }

        $pdo->commit();

        return [
            'status' => 200,
            'body' => ['success' => true, 'message' => 'Order updated.', 'stored' => $payload],
        ];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('api_update_order_status failed: ' . $e->getMessage());
        return ['status' => 500, 'body' => ['success' => false, 'message' => 'A database error occurred while updating the order.']];
    }
}

// ─── Auth Helpers ─────────────────────────────────────────────────────────────
function is_logged_in(): bool {
    return isset($_SESSION['access_token']) && isset($_SESSION['user']);
}

function is_admin(): bool {
    return is_logged_in() && in_array($_SESSION['user']['role'] ?? '', ['admin', 'superadmin']);
}

function is_rider(): bool {
    return is_logged_in() && ($_SESSION['user']['role'] ?? '') === 'rider';
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: /rg-trading-php/login.php');
        exit;
    }
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: /rg-trading-php/index.php?error=unauthorized');
        exit;
    }
}

function require_rider(): void {
    if (!is_rider()) {
        header('Location: /rg-trading-php/index.php?error=unauthorized');
        exit;
    }
}

function current_user(): array {
    return $_SESSION['user'] ?? [];
}

function get_authenticated_profile(): array {
    $fallback = current_user();

    if (!is_logged_in()) {
        return $fallback;
    }

    $result = api_request('GET', '/auth/me', [], true);
    if (($result['status'] ?? 0) >= 200 && ($result['status'] ?? 0) < 400) {
        $body = $result['body'] ?? [];
        if (isset($body['data']['user']) && is_array($body['data']['user'])) {
            $_SESSION['user'] = $body['data']['user'];
            return $_SESSION['user'];
        }
        if (isset($body['user']) && is_array($body['user'])) {
            $_SESSION['user'] = $body['user'];
            return $_SESSION['user'];
        }
    }

    return $fallback;
}

// ─── Flash Messages ───────────────────────────────────────────────────────────
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function verify_csrf_token(string $token): bool {
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token(): string {
    return generate_csrf_token();
}

function get_order_state_store(): array {
    $store = [];
    $store_file = ORDER_STATE_STORE_FILE;

    if (is_file($store_file)) {
        $content = @file_get_contents($store_file);
        if ($content !== false && trim($content) !== '') {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $store = $decoded;
            }
        }
    }

    if (!isset($_SESSION['order_state_store']) || !is_array($_SESSION['order_state_store'])) {
        $_SESSION['order_state_store'] = $store;
    }

    return is_array($_SESSION['order_state_store']) ? $_SESSION['order_state_store'] : $store;
}

function save_order_state_store(array $store): void {
    $store_file = ORDER_STATE_STORE_FILE;
    $dir = dirname($store_file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded !== false) {
        @file_put_contents($store_file, $encoded);
    }
}

function remember_order_update(string $order_id, array $payload): void {
    $order_id = (string) $order_id;
    if ($order_id === '') {
        return;
    }

    $store = get_order_state_store();
    $store[$order_id] = array_merge($store[$order_id] ?? [], $payload);
    $_SESSION['order_state_store'] = $store;
    save_order_state_store($store);
}

function apply_order_state_overrides(array $orders): array {
    $store = get_order_state_store();
    if ($store === []) {
        return $orders;
    }

    foreach ($orders as &$order) {
        $order_id = (string)($order['id'] ?? '');
        if ($order_id !== '' && isset($store[$order_id])) {
            $order = array_merge($order, $store[$order_id]);
        }
    }

    return $orders;
}

function is_associative_array(array $data): bool {
    if ($data === []) {
        return false;
    }
    return array_keys($data) !== range(0, count($data) - 1);
}

function normalize_api_order_response(array $body): array {
    if (isset($body['data']) && is_array($body['data'])) {
        if (isset($body['data']['order']) && is_array($body['data']['order'])) {
            return $body['data']['order'];
        }
        if (is_associative_array($body['data'])) {
            return $body['data'];
        }
    }
    if (isset($body['order']) && is_array($body['order'])) {
        return $body['order'];
    }
    if (is_associative_array($body)) {
        return $body;
    }
    return [];
}

function api_get_order_detail(string $order_id): array {
    $order_id = trim($order_id);
    if ($order_id === '') {
        return [];
    }

    $result = api_request('GET', '/orders/' . urlencode($order_id), [], true);
    if (($result['status'] ?? 0) >= 200 && ($result['status'] ?? 0) < 400) {
        return normalize_api_order_response($result['body'] ?? []);
    }

    return [];
}

function api_get_user_detail(string $user_id): array {
    static $user_cache = [];

    $user_id = trim($user_id);
    if ($user_id === '') {
        return [];
    }

    if (isset($user_cache[$user_id])) {
        return $user_cache[$user_id];
    }

    $endpoints = [
        '/admin/users?role=customer&limit=1&user_id=' . urlencode($user_id),
        '/admin/users?role=customer&limit=1&id=' . urlencode($user_id),
        '/admin/users/' . urlencode($user_id),
        '/users/' . urlencode($user_id),
    ];

    foreach ($endpoints as $endpoint) {
        $result = api_request('GET', $endpoint, [], true);
        if (($result['status'] ?? 0) < 200 || ($result['status'] ?? 0) >= 400) {
            continue;
        }

        $body = $result['body'] ?? [];
        if (isset($body['data']['users'][0]) && is_array($body['data']['users'][0])) {
            $user_cache[$user_id] = $body['data']['users'][0];
            return $user_cache[$user_id];
        }
        if (isset($body['users'][0]) && is_array($body['users'][0])) {
            $user_cache[$user_id] = $body['users'][0];
            return $user_cache[$user_id];
        }
        if (isset($body['data']['user']) && is_array($body['data']['user'])) {
            $user_cache[$user_id] = $body['data']['user'];
            return $user_cache[$user_id];
        }
        if (isset($body['user']) && is_array($body['user'])) {
            $user_cache[$user_id] = $body['user'];
            return $user_cache[$user_id];
        }
    }

    $user_cache[$user_id] = [];
    return [];
}

function hydrate_missing_order_fields_from_db(array $order): array {
    $order_id = (string)($order['id'] ?? '');
    if ($order_id === '') {
        return $order;
    }

    try {
        $pdo = get_db_pdo();
        $columns = $pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_COLUMN);
        $allowed = [];

        foreach (['payment_reference', 'contact_number', 'phone', 'customer_phone'] as $field) {
            if (in_array($field, $columns, true)) {
                $allowed[] = $field;
            }
        }

        if ($allowed === []) {
            return $order;
        }

        $quoted = implode(', ', array_map(static fn(string $field) => '`' . str_replace('`', '``', $field) . '`', $allowed));
        $stmt = $pdo->prepare('SELECT ' . $quoted . ' FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$order_id]);
        $row = $stmt->fetch();
        if (!$row || !is_array($row)) {
            return $order;
        }

        foreach ($row as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (($order[$field] ?? null) === null || trim((string)$order[$field]) === '') {
                $order[$field] = $value;
            }
        }
    } catch (Throwable $e) {
        error_log('hydrate_missing_order_fields_from_db failed: ' . $e->getMessage());
    }

    return $order;
}

function enrich_orders_with_details(array $orders): array {
    $state_store = get_order_state_store();

    foreach ($orders as &$order) {
        $order_id = (string)($order['id'] ?? '');
        if ($order_id !== '' && isset($state_store[$order_id]) && is_array($state_store[$order_id])) {
            $order = array_replace_recursive($order, $state_store[$order_id]);
        }

        $order = hydrate_missing_order_fields_from_db($order);

        $has_address = trim(format_order_address($order)) !== '—';
        $has_phone = trim(get_order_phone($order)) !== '';
        if ($has_address && $has_phone && !empty($order['payment_reference'])) {
            continue;
        }

        if ($order_id === '') {
            continue;
        }

        $detail = api_get_order_detail($order_id);
        if (!empty($detail)) {
            $order = array_replace_recursive($order, $detail);
        }

        if (isset($state_store[$order_id]) && is_array($state_store[$order_id])) {
            $order = array_replace_recursive($order, $state_store[$order_id]);
        }

        $order = hydrate_missing_order_fields_from_db($order);

        if (trim(get_order_phone($order)) === '' && !empty($order['user_id'])) {
            $user = api_get_user_detail((string)$order['user_id']);
            if (!empty($user)) {
                $order['user'] = array_replace_recursive($order['user'] ?? [], $user);
                if (empty($order['email']) && !empty($user['email'])) {
                    $order['email'] = $user['email'];
                }
                if (empty($order['customer_name']) && (!empty($user['first_name']) || !empty($user['last_name']))) {
                    $order['customer_name'] = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                }
                if (empty($order['phone']) && !empty($user['phone'])) {
                    $order['phone'] = $user['phone'];
                }
                if (empty($order['customer_phone']) && !empty($user['phone'])) {
                    $order['customer_phone'] = $user['phone'];
                }
            }
        }
    }
    return $orders;
}

// ─── Utility ──────────────────────────────────────────────────────────────────
function format_price(float $amount): string {
    return '₱' . number_format($amount, 2);
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function get_order_nested_value(array $order, array $keys): string {
    foreach ($keys as $key) {
        if (array_key_exists($key, $order) && $order[$key] !== null && $order[$key] !== '') {
            return (string) $order[$key];
        }
    }
    foreach ($order as $value) {
        if (is_array($value)) {
            $found = get_order_nested_value($value, $keys);
            if ($found !== '') {
                return $found;
            }
        }
    }

    return '';
}

function get_order_customer_name(array $order): string {
    $nested = trim((string)get_order_nested_value($order, ['customer_name', 'customer_full_name', 'full_name', 'name', 'buyer_name', 'recipient_name']));
    if ($nested !== '') {
        return $nested;
    }

    foreach (['customer', 'user', 'buyer', 'account', 'contact'] as $node) {
        if (!isset($order[$node]) || !is_array($order[$node])) {
            continue;
        }

        $first = trim((string)($order[$node]['first_name'] ?? $order[$node]['fname'] ?? ''));
        $last = trim((string)($order[$node]['last_name'] ?? $order[$node]['lname'] ?? ''));
        if ($first !== '' || $last !== '') {
            return trim($first . ' ' . $last);
        }

        $name = trim((string)($order[$node]['name'] ?? $order[$node]['full_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
    }

    $first = trim((string)($order['first_name'] ?? $order['customer_first_name'] ?? $order['user_first_name'] ?? ''));
    $last = trim((string)($order['last_name'] ?? $order['customer_last_name'] ?? $order['user_last_name'] ?? ''));
    if ($first !== '' || $last !== '') {
        return trim($first . ' ' . $last);
    }

    return trim((string)($order['name'] ?? $order['full_name'] ?? $order['customer_name'] ?? ''));
}

function get_order_customer_email(array $order): string {
    $nested = trim((string)get_order_nested_value($order, ['email', 'customer_email', 'user_email', 'contact_email']));
    if ($nested !== '') {
        return $nested;
    }

    return trim((string)($order['email'] ?? $order['customer_email'] ?? $order['user']['email'] ?? $order['customer']['email'] ?? ''));
}

function find_phone_number_in_array(array $data): string {
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            $lower = strtolower($key);
            if (preg_match('/phone|tel|mobile|whatsapp|landline/', $lower) && $value !== null && $value !== '') {
                if (!is_array($value)) {
                    return trim((string)$value);
                }
            }
        }

        if (is_array($value)) {
            $found = find_phone_number_in_array($value);
            if ($found !== '') {
                return trim($found);
            }
        }
    }

    return '';
}

function get_order_phone(array $order): string {
    $candidates = [
        'phone',
        'phone_number',
        'phone_no',
        'contact_no',
        'contact_phone',
        'contact_number',
        'customer_phone',
        'delivery_phone',
        'shipping_phone',
        'mobile',
        'mobile_phone',
        'mobile_no',
        'cellphone',
        'cell_number',
        'billing_phone',
        'recipient_phone',
        'contact_number',
        'telephone',
        'tel',
        'whatsapp',
        'whatsapp_number',
        'phone1',
        'phone2',
        'phone_home',
        'phone_work',
        'landline',
        'msisdn',
    ];

    foreach ($candidates as $key) {
        if (array_key_exists($key, $order) && $order[$key] !== null && $order[$key] !== '') {
            return trim((string)$order[$key]);
        }
    }

    $nested = get_order_nested_value($order, $candidates);
    if ($nested !== '') {
        return trim($nested);
    }

    foreach (['user', 'customer', 'buyer', 'account', 'contact', 'billing_address', 'shipping_address', 'delivery_address'] as $node) {
        if (isset($order[$node]) && is_array($order[$node])) {
            $nested = get_order_nested_value($order[$node], $candidates);
            if ($nested !== '') {
                return trim($nested);
            }
            $fallback = find_phone_number_in_array($order[$node]);
            if ($fallback !== '') {
                return trim($fallback);
            }
        }
    }

    $fallback = find_phone_number_in_array($order);
    if ($fallback !== '') {
        return trim($fallback);
    }

    return '';
}

function get_order_address_parts(array $order): array {
    $address = ['street' => '', 'city' => '', 'province' => '', 'zip' => ''];

    $address_candidates = [];
    foreach (['shipping_address', 'delivery_address', 'address', 'customer_address'] as $key) {
        if (array_key_exists($key, $order)) {
            $address_candidates[] = $order[$key];
        }
    }

    foreach ($order as $value) {
        if (is_array($value)) {
            foreach (['shipping_address', 'delivery_address', 'address', 'customer_address'] as $key) {
                if (array_key_exists($key, $value)) {
                    $address_candidates[] = $value[$key];
                }
            }
        }
    }

    foreach ($address_candidates as $candidate) {
        if (is_array($candidate)) {
            $address['street'] = $address['street'] !== '' ? $address['street'] : trim((string)($candidate['street'] ?? $candidate['address'] ?? $candidate['address_line_1'] ?? $candidate['address_line'] ?? ''));
            $address['city'] = $address['city'] !== '' ? $address['city'] : trim((string)($candidate['city'] ?? $candidate['town'] ?? $candidate['municipality'] ?? ''));
            $address['province'] = $address['province'] !== '' ? $address['province'] : trim((string)($candidate['province'] ?? $candidate['state'] ?? $candidate['region'] ?? ''));
            $address['zip'] = $address['zip'] !== '' ? $address['zip'] : trim((string)($candidate['zip'] ?? $candidate['zip_code'] ?? $candidate['postal_code'] ?? ''));
        } elseif (is_string($candidate) && trim($candidate) !== '') {
            $address['street'] = $address['street'] !== '' ? $address['street'] : trim($candidate);
        }
    }

    $address['street'] = $address['street'] !== '' ? $address['street'] : trim((string)get_order_nested_value($order, ['street', 'address', 'street_address', 'address_line_1', 'address_line']));
    $address['city'] = $address['city'] !== '' ? $address['city'] : trim((string)get_order_nested_value($order, ['city', 'town', 'municipality']));
    $address['province'] = $address['province'] !== '' ? $address['province'] : trim((string)get_order_nested_value($order, ['province', 'state', 'region']));
    $address['zip'] = $address['zip'] !== '' ? $address['zip'] : trim((string)get_order_nested_value($order, ['zip', 'zip_code', 'postal_code']));

    return $address;
}

function format_order_address(array $order): string {
    $parts = array_filter(get_order_address_parts($order));
    return implode(', ', $parts) ?: '—';
}

function order_status_label(string $status): string {
    $status = strtolower(trim($status));
    $labels = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'refunded' => 'Refunded',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function order_status_badge_class(string $status): string {
    $status = strtolower(trim($status));
    $map = [
        'pending' => 'pending',
        'confirmed' => 'success',
        'processing' => 'info',
        'shipped' => 'blue',
        'delivered' => 'success',
        'cancelled' => 'danger',
        'refunded' => 'danger',
    ];
    return $map[$status] ?? 'pending';
}

// ─── Product Image Helper ─────────────────────────────────────────────────────
// Returns the image URL to store:
//   - If a file was uploaded → saves it to assets/uploads/ and returns the web path
//   - If a URL was pasted   → returns the URL as-is
//   - If neither            → returns null (keep existing image or no image)
function resolve_product_image_url(string $url_input): ?string {
    $upload_dir  = __DIR__ . '/../assets/uploads/';
    $upload_web  = '/rg-trading-php/assets/uploads/';

    // Priority 1: uploaded file
    if (!empty($_FILES['product_image']['tmp_name'])
        && (int)($_FILES['product_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {

        $file     = $_FILES['product_image'];
        $allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $max_size = 5 * 1024 * 1024; // 5 MB

        if (!in_array($file['type'], $allowed, true) || $file['size'] > $max_size) {
            return null; // caller will show error
        }

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = uniqid('product_', true) . '.' . strtolower($ext);
        $dest     = $upload_dir . $filename;

        if (move_uploaded_file($file['tmp_name'], $dest)) {
            return $upload_web . $filename;
        }
        return null;
    }

    // Priority 2: pasted URL
    $url = trim($url_input);
    if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }

    // Nothing provided
    return null;
}