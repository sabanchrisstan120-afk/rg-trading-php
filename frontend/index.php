<?php
require_once __DIR__ . '/includes/config.php';

// ── PHPMailer (for OTP emails) ─────────────────────────────────────────────
// Uses Composer if available, otherwise falls back to a manual /PHPMailer folder.
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
  require_once $autoload;
  // If Composer's autoloader is present but PHPMailer isn't installed via
  // Composer, fall back to the bundled PHPMailer files in /PHPMailer/.
  if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';
    require_once __DIR__ . '/PHPMailer/Exception.php';
  }
} else {
  require_once __DIR__ . '/PHPMailer/PHPMailer.php';
  require_once __DIR__ . '/PHPMailer/SMTP.php';
  require_once __DIR__ . '/PHPMailer/Exception.php';
}
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

$mailConfig = [
    'smtp_host'     => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'smtp_port'     => getenv('SMTP_PORT') ?: 587,
    'smtp_secure'   => getenv('SMTP_SECURE') ?: 'tls',
    'smtp_username' => getenv('SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
    'from_email'    => getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: ''),
    'from_name'     => getenv('SMTP_FROM_NAME') ?: 'R&G Trading',
];

/**
 * Sends the OTP email.
 * Returns true on success, or a string with the failure reason on error
 * (so the caller can log/display it instead of just failing silently).
 */
function send_otp_email($config, $to, $firstName, $otp, $purpose) {
    $mail = new PHPMailer(true);
    $debugLog = [];
    try {
        $mail->isSMTP();
        $mail->Host       = $config['smtp_host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->Port       = $config['smtp_port'];
        $mail->Timeout    = 10;

        // Most "connection failed" errors on local XAMPP setups (esp. Windows)
        // come from an outdated/self-signed local CA bundle. This relaxes
        // certificate checking for the SMTP handshake only — fine for a
        // known, hardcoded Gmail host like this one.
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Capture the SMTP conversation only in local development.
        // Production should not expose connection details or SMTP transcript output.
        $mail->SMTPDebug = is_local_env() ? 2 : 0;
        if (is_local_env()) {
            $mail->Debugoutput = function ($str) use (&$debugLog) { $debugLog[] = trim($str); };
        }

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);

        if ($purpose === 'register') {
            $intro = "Thanks for creating an account with R&amp;G Trading. Enter this code to finish setting up your account:";
        } elseif ($purpose === 'reset') {
            $intro = "We received a request to reset your R&amp;G Trading password. Enter this code to continue:";
        } else {
            $intro = "We noticed a sign-in to your R&amp;G Trading account. Enter this code to continue:";
        }

        $mail->Subject = 'Your R&G Trading verification code';
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;'>
              <div style='background:#1c60a3;color:#fff;padding:16px 22px;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;font-size:19px;'>❄️ R&amp;G Trading</h2>
              </div>
              <div style='padding:24px 22px;background:#f7fafc;border-radius:0 0 10px 10px;'>
                <p style='margin-top:0;'>Hi " . htmlspecialchars($firstName) . ",</p>
                <p>$intro</p>
                <div style='font-size:30px;font-weight:800;letter-spacing:8px;text-align:center;color:#1c60a3;background:#fff;border:1px solid #e2e8f0;border-radius:10px;margin:22px 0;padding:14px;'>$otp</div>
                <p style='color:#4a5568;'>This code expires in <strong>5 minutes</strong>. If you didn't request this, you can ignore this email.</p>
              </div>
            </div>";
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        $reason = $mail->ErrorInfo ?: $e->getMessage();
        error_log('OTP email failed: ' . $reason);
        if (!empty($debugLog)) error_log("SMTP transcript:\n" . implode("\n", $debugLog));
        return $reason;
    }
}

/** True when running on a local dev machine (used to surface OTPs/errors that are otherwise hidden). */
function is_local_env() {
    $host = $_SERVER['SERVER_NAME'] ?? '';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($host, ['localhost', '127.0.0.1'], true) || in_array($ip, ['127.0.0.1', '::1'], true);
}

function mask_email($email) {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $visible = mb_substr($parts[0], 0, 2);
    return $visible . str_repeat('*', max(3, mb_strlen($parts[0]) - 2)) . '@' . $parts[1];
}

// ── Modal / OTP state ───────────────────────────────────────────────────────
$showLoginModal     = false;
$showRegisterModal  = false;
$showOtpModal       = false;
$showForgotModal    = false;
$showResetOtpModal  = false;
$showResetPassModal = false;
$authError          = '';
$authSuccess        = '';
$regErrors          = [];
$regValues          = [];
$otpEmailMasked     = '';
$resetEmailMasked   = '';
$resetErrors        = [];

/** Builds the current page URL (path + query) with any ?auth= param stripped. */
function current_url_without_auth() {
    $params = $_GET;
    unset($params['auth']);
    $qs   = http_build_query($params);
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $path . ($qs ? '?' . $qs : '');
}

/**
 * Stores what the modal should show after a redirect, then redirects (PRG
 * pattern) so refreshing the result page never resubmits the form.
 */
function redirect_with_auth_flash($view, $error = '', $success = '', $regErrors = [], $otpEmailMasked = '', $regValues = [], $resetErrors = [], $resetEmailMasked = '') {
    $_SESSION['auth_flash'] = [
        'view'               => $view,
        'error'              => $error,
        'success'            => $success,
        'reg_errors'         => $regErrors,
        'otp_email_masked'   => $otpEmailMasked,
        'reg_values'         => $regValues,
        'reset_errors'       => $resetErrors,
        'reset_email_masked' => $resetEmailMasked,
    ];
    $dest = $_POST['return_to'] ?? current_url_without_auth();
    if (empty($dest)) $dest = '/rg-trading-php/index.php';
    header('Location: ' . $dest);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Consume any flash left by a previous POST (this is the *only* place
    // a failed/succeeded submission's result gets displayed — never on the
    // POST request itself, so refresh/back never re-triggers it).
    if (!empty($_SESSION['auth_flash'])) {
        $flash = $_SESSION['auth_flash'];
        unset($_SESSION['auth_flash']);

        $showLoginModal     = $flash['view'] === 'login';
        $showRegisterModal  = $flash['view'] === 'register';
        $showOtpModal       = $flash['view'] === 'otp';
        $showForgotModal    = $flash['view'] === 'forgot';
        $showResetOtpModal  = $flash['view'] === 'reset-otp';
        $showResetPassModal = $flash['view'] === 'reset-password';
        $authError          = $flash['error'] ?? '';
        $authSuccess        = $flash['success'] ?? '';
        $regErrors          = $flash['reg_errors'] ?? [];
        $otpEmailMasked     = $flash['otp_email_masked'] ?? '';
        $regValues          = $flash['reg_values'] ?? [];
        $resetErrors        = $flash['reset_errors'] ?? [];
        $resetEmailMasked   = $flash['reset_email_masked'] ?? '';
    } elseif (isset($_GET['auth']) && $_GET['auth'] === 'register') {
        $showRegisterModal = true;
    } elseif (isset($_GET['auth']) && $_GET['auth'] === 'login') {
        $showLoginModal = true;
    } elseif (isset($_GET['auth']) && $_GET['auth'] === 'forgot') {
        $showForgotModal = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- Login ----
    if (isset($_POST['login_submit'])) {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            redirect_with_auth_flash('login', 'Email and password are required.');
        }

        $result = api_request('POST', '/auth/login', ['email' => $email, 'password' => $password]);

        if ($result['status'] === 200 && isset($result['body']['data']['access_token'])) {
            $user = $result['body']['data']['user'];
            $otp  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $_SESSION['pending_auth'] = [
                'purpose'      => 'login',
                'otp'          => $otp,
                'expires_at'   => time() + 300,
                'attempts'     => 0,
                'last_sent_at' => time(),
                'resend_count' => 0,
                'email'        => $user['email'],
                'tokens'       => $result['body']['data'],
            ];

            $mailResult = send_otp_email($mailConfig, $user['email'], $user['first_name'], $otp, 'login');
            $otpErr = '';
            if ($mailResult !== true) {
                $otpErr = is_local_env()
                    ? "Couldn't email the code (dev-only, your code is {$otp}): {$mailResult}"
                    : "We couldn't send your code. Tap \"Resend code\" to try again.";
            }
            redirect_with_auth_flash('otp', $otpErr, '', [], mask_email($user['email']));
        }

        redirect_with_auth_flash('login', $result['body']['message'] ?? 'Invalid email or password.');
    }

    // ---- Register ----
    if (isset($_POST['register_submit'])) {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');
        $email      = trim($_POST['email']      ?? '');
        $password   = $_POST['password']        ?? '';
        $phone      = trim($_POST['phone']      ?? '');

        $submittedValues = compact('first_name', 'last_name', 'email', 'phone');

        if (empty($first_name)) $regErrors['first_name'] = 'First name is required.';
        if (empty($last_name))  $regErrors['last_name']  = 'Last name is required.';
        if (empty($email))      $regErrors['email']      = 'Email is required.';
        if (strlen($password) < 8) $regErrors['password'] = 'Password must be at least 8 characters.';

        if (!empty($regErrors)) {
            redirect_with_auth_flash('register', '', '', $regErrors, '', $submittedValues);
        }

        $payload = ['first_name' => $first_name, 'last_name' => $last_name, 'email' => $email, 'password' => $password];
        if (!empty($phone)) $payload['phone'] = $phone;

        $result = api_request('POST', '/auth/register', $payload);

        if ($result['status'] === 201 && isset($result['body']['data']['access_token'])) {
            $user = $result['body']['data']['user'];
            $otp  = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $_SESSION['pending_auth'] = [
                'purpose'      => 'register',
                'otp'          => $otp,
                'expires_at'   => time() + 300,
                'attempts'     => 0,
                'last_sent_at' => time(),
                'resend_count' => 0,
                'email'        => $user['email'],
                'tokens'       => $result['body']['data'],
            ];

            $mailResult = send_otp_email($mailConfig, $user['email'], $user['first_name'], $otp, 'register');
            $otpErr = '';
            if ($mailResult !== true) {
                $otpErr = is_local_env()
                    ? "Couldn't email the code (dev-only, your code is {$otp}): {$mailResult}"
                    : "We couldn't send your code. Tap \"Resend code\" to try again.";
            }
            // Account already exists via the API now — always land on the OTP step,
            // never back on the register form (retrying would just hit "already registered").
            redirect_with_auth_flash('otp', $otpErr, '', [], mask_email($user['email']));
        }

        if (isset($result['body']['errors'])) {
            $apiErrors = [];
            foreach ($result['body']['errors'] as $e) $apiErrors[$e['field']] = $e['message'];
            redirect_with_auth_flash('register', '', '', $apiErrors, '', $submittedValues);
        }

        redirect_with_auth_flash('register', $result['body']['message'] ?? 'Registration failed. Please try again.', '', [], '', $submittedValues);
    }

    // ---- Verify OTP ----
    if (isset($_POST['verify_otp'])) {
        $submitted = trim($_POST['otp'] ?? '');
        $pending   = $_SESSION['pending_auth'] ?? null;

        if (!$pending) {
            redirect_with_auth_flash('login', 'Your session expired. Please log in again.');
        } elseif (time() > $pending['expires_at']) {
            unset($_SESSION['pending_auth']);
            redirect_with_auth_flash('login', 'This code expired. Please log in again.');
        } elseif ($pending['attempts'] >= 5) {
            unset($_SESSION['pending_auth']);
            redirect_with_auth_flash('login', 'Too many incorrect attempts. Please log in again.');
        } elseif (!hash_equals($pending['otp'], $submitted)) {
            $_SESSION['pending_auth']['attempts']++;
            $remaining = 5 - $_SESSION['pending_auth']['attempts'];
            redirect_with_auth_flash('otp', "Incorrect code. {$remaining} attempt(s) left.", '', [], mask_email($pending['email']));
        } else {
            $tokens = $pending['tokens'];
            $_SESSION['access_token']  = $tokens['access_token'];
            $_SESSION['refresh_token'] = $tokens['refresh_token'];
            $_SESSION['user']          = $tokens['user'];
            $purpose = $pending['purpose'];
            unset($_SESSION['pending_auth']);

            set_flash('success', $purpose === 'register'
                ? 'Account created and verified! Welcome, ' . $tokens['user']['first_name'] . '.'
                : 'Welcome back, ' . $tokens['user']['first_name'] . '!');

            if (is_admin()) {
                header('Location: /rg-trading-php/pages/admin/dashboard.php');
            } elseif (is_rider()) {
                header('Location: /rg-trading-php/pages/rider/orders.php');
            } else {
                header('Location: /rg-trading-php/index.php');
            }
            exit;
        }
    }

    // ---- Resend OTP ----
    if (isset($_POST['resend_otp'])) {
        $pending = $_SESSION['pending_auth'] ?? null;

        if (!$pending) {
            redirect_with_auth_flash('login', 'Your session expired. Please log in again.');
        }

        $wait = 30 - (time() - $pending['last_sent_at']);
        if ($wait > 0) {
            redirect_with_auth_flash('otp', "Please wait {$wait}s before requesting another code.", '', [], mask_email($pending['email']));
        }

        if ($pending['resend_count'] >= 5) {
            unset($_SESSION['pending_auth']);
            redirect_with_auth_flash('login', 'Too many resend requests. Please log in again.');
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_auth']['otp']          = $otp;
        $_SESSION['pending_auth']['expires_at']   = time() + 300;
        $_SESSION['pending_auth']['attempts']     = 0;
        $_SESSION['pending_auth']['last_sent_at'] = time();
        $_SESSION['pending_auth']['resend_count']++;

        $firstName  = $pending['tokens']['user']['first_name'] ?? '';
        $mailResult = send_otp_email($mailConfig, $pending['email'], $firstName, $otp, $pending['purpose']);

        if ($mailResult === true) {
            redirect_with_auth_flash('otp', '', 'A new code has been sent to your email.', [], mask_email($pending['email']));
        }

        $otpErr = is_local_env()
            ? "Couldn't email the code (dev-only, your code is {$otp}): {$mailResult}"
            : 'Failed to resend the code. Please try again shortly.';
        redirect_with_auth_flash('otp', $otpErr, '', [], mask_email($pending['email']));
    }

    // ---- Forgot password: request code ----
    if (isset($_POST['forgot_submit'])) {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirect_with_auth_flash('forgot', '', '', [], '', [], ['email' => 'Enter a valid email address.']);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $_SESSION['pending_reset'] = [
            'otp'          => $otp,
            'expires_at'   => time() + 300,
            'attempts'     => 0,
            'last_sent_at' => time(),
            'resend_count' => 0,
            'email'        => $email,
            'verified'     => false,
        ];

        // We don't reveal whether the address is registered — the code is
        // only ever "usable" via reset_password_submit below, which will
        // fail server-side if there's no account for it.
        $mailResult = send_otp_email($mailConfig, $email, 'there', $otp, 'reset');
        $otpErr = '';
        if ($mailResult !== true) {
            $otpErr = is_local_env()
                ? "Couldn't email the code (dev-only, your code is {$otp}): {$mailResult}"
                : "We couldn't send your code. Tap \"Resend code\" to try again.";
        }
        redirect_with_auth_flash('reset-otp', $otpErr, '', [], '', [], [], mask_email($email));
    }

    // ---- Forgot password: verify code ----
    if (isset($_POST['verify_reset_otp'])) {
        $submitted = trim($_POST['otp'] ?? '');
        $pending   = $_SESSION['pending_reset'] ?? null;

        if (!$pending) {
            redirect_with_auth_flash('forgot', 'Your session expired. Please start again.');
        } elseif (time() > $pending['expires_at']) {
            unset($_SESSION['pending_reset']);
            redirect_with_auth_flash('forgot', 'This code expired. Please start again.');
        } elseif ($pending['attempts'] >= 5) {
            unset($_SESSION['pending_reset']);
            redirect_with_auth_flash('forgot', 'Too many incorrect attempts. Please start again.');
        } elseif (!hash_equals($pending['otp'], $submitted)) {
            $_SESSION['pending_reset']['attempts']++;
            $remaining = 5 - $_SESSION['pending_reset']['attempts'];
            redirect_with_auth_flash('reset-otp', "Incorrect code. {$remaining} attempt(s) left.", '', [], '', [], [], mask_email($pending['email']));
        } else {
            $_SESSION['pending_reset']['verified'] = true;
            redirect_with_auth_flash('reset-password', '', '', [], '', [], [], mask_email($pending['email']));
        }
    }

    // ---- Forgot password: resend code ----
    if (isset($_POST['resend_reset_otp'])) {
        $pending = $_SESSION['pending_reset'] ?? null;

        if (!$pending) {
            redirect_with_auth_flash('forgot', 'Your session expired. Please start again.');
        }

        $wait = 30 - (time() - $pending['last_sent_at']);
        if ($wait > 0) {
            redirect_with_auth_flash('reset-otp', "Please wait {$wait}s before requesting another code.", '', [], '', [], [], mask_email($pending['email']));
        }

        if ($pending['resend_count'] >= 5) {
            unset($_SESSION['pending_reset']);
            redirect_with_auth_flash('forgot', 'Too many resend requests. Please start again.');
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['pending_reset']['otp']          = $otp;
        $_SESSION['pending_reset']['expires_at']   = time() + 300;
        $_SESSION['pending_reset']['attempts']     = 0;
        $_SESSION['pending_reset']['last_sent_at'] = time();
        $_SESSION['pending_reset']['resend_count']++;

        $mailResult = send_otp_email($mailConfig, $pending['email'], 'there', $otp, 'reset');

        if ($mailResult === true) {
            redirect_with_auth_flash('reset-otp', '', 'A new code has been sent to your email.', [], '', [], [], mask_email($pending['email']));
        }

        $otpErr = is_local_env()
            ? "Couldn't email the code (dev-only, your code is {$otp}): {$mailResult}"
            : 'Failed to resend the code. Please try again shortly.';
        redirect_with_auth_flash('reset-otp', $otpErr, '', [], '', [], [], mask_email($pending['email']));
    }

    // ---- Forgot password: set new password ----
    if (isset($_POST['reset_password_submit'])) {
        $pending = $_SESSION['pending_reset'] ?? null;

        if (!$pending || empty($pending['verified'])) {
            redirect_with_auth_flash('forgot', 'Your session expired. Please start again.');
        }

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';
        $errs = [];
        if (strlen($password) < 8) $errs['password'] = 'Password must be at least 8 characters.';
        elseif ($password !== $confirm) $errs['password_confirm'] = 'Passwords do not match.';

        if (!empty($errs)) {
            redirect_with_auth_flash('reset-password', '', '', [], '', [], $errs, mask_email($pending['email']));
        }

        // The Node.js API has no password-reset route, so we update
        // users.password_hash directly (see includes/config.php: db_reset_password()).
        $result = db_reset_password($pending['email'], $password);

        if ($result === true) {
            unset($_SESSION['pending_reset']);
            redirect_with_auth_flash('login', '', 'Your password has been reset. Please sign in.');
        }

        $errMsg = ($result === false)
            ? 'No account found with that email.'
            : 'Could not reset your password. Please try again.';
        redirect_with_auth_flash('reset-password', $errMsg, '', [], '', [], [], mask_email($pending['email']));
    }
}

// ── Existing catalog logic (unchanged) ──────────────────────────────────────
$search   = trim($_GET['search']   ?? '');
$category = trim($_GET['category'] ?? '');
$sort     = $_GET['sort']          ?? 'created_at';
$page     = max(1, intval($_GET['page'] ?? 1));
$limit    = 12;

$params = http_build_query(array_filter([
    'search' => $search, 'category' => $category,
    'sort' => $sort, 'page' => $page, 'limit' => $limit,
]));

$result      = api_request('GET', '/products?' . $params);
$products    = $result['body']['data']['products']   ?? [];
$pagination  = $result['body']['data']['pagination'] ?? [];
$total       = $pagination['total'] ?? 0;
$total_pages = ceil($total / $limit);

$cat_result = api_request('GET', '/products/categories');
$categories = $cat_result['body']['data']['categories'] ?? [];

$page_title = 'Shop — ' . APP_NAME;
include __DIR__ . '/includes/header.php';
?>

<?php if (!$search && !$category): ?>
<div class="hero">
  <div class="hero-inner">
    <div class="hero-grid">
      <div class="hero-copy">
        <h1>Stay Cool with R&G Trading</h1>
        <p>Premium air conditioner units for every home and business in Iloilo</p>
        <div class="hero-search">
          <form method="GET" class="form-contents">
            <input type="text" name="search" placeholder="Search by brand, model, or type..." value="<?= h($search) ?>" class="search-input">
            <button type="submit">Search</button>
          </form>
        </div>
        <div class="hero-stats">
          <div class="hero-stat"><strong><?= count($categories) ?>+</strong><span>Categories</span></div>
          <div class="hero-stat"><strong><?= $total ?>+</strong><span>Products</span></div>
          <div class="hero-stat"><strong>Free</strong><span>Shipping ₱10k+</span></div>
        </div>
      </div>
      <div class="hero-image-frame">
        <div class="hero-image-placeholder"></div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="main-content">

  <?php if (!$search): ?>
  <div class="category-pills">
    <a href="/rg-trading-php/" class="cat-pill <?= $category === '' ? 'active' : '' ?>">All</a>
    <?php foreach ($categories as $cat): ?>
      <a href="?category=<?= h($cat['slug']) ?>" class="cat-pill <?= $category === $cat['slug'] ? 'active' : '' ?>"><?= h($cat['name']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($search || $category): ?>
  <div class="page-header">
    <h1>
      <?php if ($search): ?>Results for "<?= h($search) ?>"
      <?php elseif ($category): ?>
        <?php foreach ($categories as $c) { if ($c['slug'] === $category) echo h($c['name']); } ?>
      <?php endif; ?>
    </h1>
      <p><?= $total ?> product<?= $total !== 1 ? 's' : '' ?> found
      — <a href="<?= BASE_URL ?>/" class="btn-link">Clear filters</a>
    </p>
  </div>
  <?php endif; ?>

  <form method="GET" class="filter-bar">
    <?php if ($category): ?><input type="hidden" name="category" value="<?= h($category) ?>"><?php endif; ?>
    <input type="text" name="search" placeholder="Search..." value="<?= h($search) ?>" class="search-input">
    <select name="category">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= h($cat['slug']) ?>" <?= $category === $cat['slug'] ? 'selected' : '' ?>><?= h($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="sort">
      <option value="created_at" <?= $sort==='created_at'?'selected':'' ?>>Newest</option>
      <option value="price"      <?= $sort==='price'?'selected':'' ?>>Price: Low to High</option>
      <option value="name"       <?= $sort==='name'?'selected':'' ?>>Name A–Z</option>
    </select>
    <button type="submit">Filter</button>
  </form>

  <?php if (empty($products)): ?>
    <div class="empty-state">
      <div class="icon">❄️</div>
      <p>No products found. Try adjusting your search.</p>
    </div>
  <?php else: ?>
    <?php if (!$search && !$category): ?><h2 class="section-title">All Products</h2><?php endif; ?>
    <div class="products-grid">
      <?php foreach ($products as $p): ?>
        <div class="product-card">
          <a href="/rg-trading-php/pages/product-detail.php?id=<?= h($p['id']) ?>">
            <div class="product-img-wrap">
              <?php if (!empty($p['image_url'])): ?>
                <img src="<?= h($p['image_url']) ?>" alt="<?= h($p['name']) ?>">
              <?php else: ?>
                <div class="product-img-placeholder">❄️</div>
              <?php endif; ?>
              <?php if ($p['stock_qty'] <= 0): ?>
                <span class="product-badge">Out of Stock</span>
              <?php elseif ($p['stock_qty'] <= 5): ?>
                <span class="product-badge">Low Stock</span>
              <?php endif; ?>
            </div>
          </a>
          <div class="product-body">
            <div class="product-brand"><?= h($p['brand']) ?></div>
            <a href="/rg-trading-php/pages/product-detail.php?id=<?= h($p['id']) ?>">
              <div class="product-name"><?= h($p['name']) ?></div>
            </a>
            <div class="product-model">Model: <?= h($p['model_number']) ?></div>
            <div class="product-specs">
              <?php if ($p['horsepower']): ?><span class="spec-tag"><?= h($p['horsepower']) ?>HP</span><?php endif; ?>
              <?php if ($p['energy_rating']): ?><span class="spec-tag"><?= h($p['energy_rating']) ?></span><?php endif; ?>
              <?php if ($p['category']): ?><span class="spec-tag"><?= h($p['category']) ?></span><?php endif; ?>
            </div>
            <div class="product-footer">
              <div>
                <div class="product-price"><?= format_price($p['price']) ?></div>
                <?php if ($p['stock_qty'] <= 5 && $p['stock_qty'] > 0): ?>
                  <span class="stock-low">Only <?= $p['stock_qty'] ?> left!</span>
                <?php elseif ($p['stock_qty'] <= 0): ?>
                  <span class="stock-low">Out of stock</span>
                <?php else: ?>
                  <span class="stock-ok">In stock</span>
                <?php endif; ?>
              </div>
              <?php if (is_logged_in() && $p['stock_qty'] > 0): ?>
                <a href="/rg-trading-php/pages/checkout.php?product_id=<?= h($p['id']) ?>">
                  <button class="btn-add-cart">Order Now</button>
                </a>
              <?php elseif (!is_logged_in()): ?>
                <a href="#" data-auth-open="login">
                  <button class="btn-view">Login to Order</button>
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">← Prev</a>
        <?php endif; ?>
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="active"><?= $i ?></span>
          <?php else: ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ============================= AUTH MODAL ============================= -->
<style>
.auth-modal-box{width:min(420px,92vw);}
.auth-modal-box .modal-body{padding:28px 26px 30px;}
.auth-modal-box h2{font-size:21px;font-weight:700;color:#1a365d;margin:0 0 6px;}
.auth-subtitle{color:#718096;font-size:14px;margin:0 0 20px;}
.auth-footer{text-align:center;margin-top:18px;font-size:14px;color:#718096;}
.auth-footer a{color:#3182ce;font-weight:600;cursor:pointer;}
.otp-footer{display:flex;flex-direction:column;align-items:center;gap:6px;}
.auth-resend-btn{background:none;border:none;color:#3182ce;font-weight:600;font-size:14px;cursor:pointer;padding:0;}
.otp-code-input{letter-spacing:10px;font-size:22px;text-align:center;font-weight:700;}
.form-row{display:flex;gap:12px;}
.form-row .form-group{flex:1;}
.auth-forgot-link{margin:8px 0 0;text-align:right;font-size:13px;}
.auth-forgot-link a{color:#3182ce;font-weight:600;cursor:pointer;}
</style>
<div id="authModalOverlay" class="modal-overlay <?= ($showLoginModal || $showRegisterModal || $showOtpModal || $showForgotModal || $showResetOtpModal || $showResetPassModal) ? 'open' : '' ?>">
  <div class="modal modal-small auth-modal-box">

    <div id="authView-login" class="auth-view" style="<?= $showLoginModal ? '' : 'display:none;' ?>">
      <div class="modal-header">
        <h2>Welcome back</h2>
        <button type="button" class="modal-close" data-auth-close>&times;</button>
      </div>
      <div class="modal-body">
        <p class="auth-subtitle">Sign in to your R&amp;G Trading account</p>
        <?php if ($authError && $showLoginModal): ?>
          <div class="flash flash-error flash-inline"><?= h($authError) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <div class="form-group">
            <label for="loginEmail">Email address</label>
            <input type="email" id="loginEmail" name="email" placeholder="you@example.com" required autofocus>
          </div>
          <div class="form-group">
            <label for="loginPassword">Password</label>
            <input type="password" id="loginPassword" name="password" placeholder="••••••••" required>
            <p class="auth-forgot-link"><a data-auth-switch="forgot">Forgot password?</a></p>
          </div>
          <button type="submit" name="login_submit" class="btn-primary">Sign In</button>
        </form>
        <div class="auth-footer">
          Don't have an account? <a data-auth-switch="register">Register here</a>
        </div>
      </div>
    </div>

    <div id="authView-register" class="auth-view" style="<?= $showRegisterModal ? '' : 'display:none;' ?>">
      <div class="modal-header">
        <h2>Create an account</h2>
        <button type="button" class="modal-close" data-auth-close>&times;</button>
      </div>
      <div class="modal-body">
        <p class="auth-subtitle">Join R&amp;G Trading to browse and order aircon units</p>
        <?php if ($authError && $showRegisterModal): ?>
          <div class="flash flash-error flash-inline"><?= h($authError) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <div class="form-row">
            <div class="form-group">
              <label>First Name</label>
              <input type="text" name="first_name" placeholder="Juan" value="<?= h($regValues['first_name'] ?? '') ?>" required>
              <?php if (isset($regErrors['first_name'])): ?><p class="form-error"><?= h($regErrors['first_name']) ?></p><?php endif; ?>
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input type="text" name="last_name" placeholder="dela Cruz" value="<?= h($regValues['last_name'] ?? '') ?>" required>
              <?php if (isset($regErrors['last_name'])): ?><p class="form-error"><?= h($regErrors['last_name']) ?></p><?php endif; ?>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="juan@example.com" value="<?= h($regValues['email'] ?? '') ?>" required>
            <?php if (isset($regErrors['email'])): ?><p class="form-error"><?= h($regErrors['email']) ?></p><?php endif; ?>
          </div>
          <div class="form-group">
            <label>Password <span class="small-muted">(min 8 characters)</span></label>
            <input type="password" name="password" placeholder="••••••••" required>
            <?php if (isset($regErrors['password'])): ?><p class="form-error"><?= h($regErrors['password']) ?></p><?php endif; ?>
          </div>
          <div class="form-group">
            <label>Phone <span class="small-muted">(optional)</span></label>
            <input type="text" name="phone" placeholder="09XXXXXXXXX" value="<?= h($regValues['phone'] ?? '') ?>">
          </div>
          <button type="submit" name="register_submit" class="btn-primary">Create Account</button>
        </form>
        <div class="auth-footer">
          Already have an account? <a data-auth-switch="login">Sign in</a>
        </div>
      </div>
    </div>

    <div id="authView-otp" class="auth-view" style="<?= $showOtpModal ? '' : 'display:none;' ?>">
      <div class="modal-header">
        <h2>Verify your email</h2>
        <button type="button" class="modal-close" data-auth-close>&times;</button>
      </div>
      <div class="modal-body">
        <p class="auth-subtitle">We sent a 6-digit code to <strong><?= h($otpEmailMasked) ?></strong>.</p>
        <?php if ($authError && $showOtpModal): ?>
          <div class="flash flash-error flash-inline"><?= h($authError) ?></div>
        <?php endif; ?>
        <?php if ($authSuccess && $showOtpModal): ?>
          <div class="flash flash-success flash-inline"><?= h($authSuccess) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <div class="form-group">
            <label for="otpCode">Verification code</label>
            <input type="text" id="otpCode" name="otp" class="otp-code-input" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code" required autofocus>
          </div>
          <button type="submit" name="verify_otp" class="btn-primary">Verify &amp; Continue</button>
        </form>
        <form method="POST" class="auth-footer otp-footer">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <button type="submit" name="resend_otp" class="auth-resend-btn">Didn't get it? Resend code</button>
        </form>
      </div>
    </div>

    <div id="authView-forgot" class="auth-view" style="<?= $showForgotModal ? '' : 'display:none;' ?>">
      <div class="modal-header">
        <h2>Reset your password</h2>
        <button type="button" class="modal-close" data-auth-close>&times;</button>
      </div>
      <div class="modal-body">
        <p class="auth-subtitle">Enter your account email and we'll send you a verification code.</p>
        <?php if ($authError && $showForgotModal): ?>
          <div class="flash flash-error flash-inline"><?= h($authError) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <div class="form-group">
            <label for="forgotEmail">Email address</label>
            <input type="email" id="forgotEmail" name="email" placeholder="you@example.com" required autofocus>
            <?php if (isset($resetErrors['email'])): ?><p class="form-error"><?= h($resetErrors['email']) ?></p><?php endif; ?>
          </div>
          <button type="submit" name="forgot_submit" class="btn-primary">Send Code</button>
        </form>
        <div class="auth-footer">
          Remembered it? <a data-auth-switch="login">Back to sign in</a>
        </div>
      </div>
    </div>

    <div id="authView-reset-otp" class="auth-view" style="<?= $showResetOtpModal ? '' : 'display:none;' ?>">
      <div class="modal-header">
        <h2>Verify your email</h2>
        <button type="button" class="modal-close" data-auth-close>&times;</button>
      </div>
      <div class="modal-body">
        <p class="auth-subtitle">We sent a 6-digit code to <strong><?= h($resetEmailMasked) ?></strong>.</p>
        <?php if ($authError && $showResetOtpModal): ?>
          <div class="flash flash-error flash-inline"><?= h($authError) ?></div>
        <?php endif; ?>
        <?php if ($authSuccess && $showResetOtpModal): ?>
          <div class="flash flash-success flash-inline"><?= h($authSuccess) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <div class="form-group">
            <label for="resetOtpCode">Verification code</label>
            <input type="text" id="resetOtpCode" name="otp" class="otp-code-input" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code" required autofocus>
          </div>
          <button type="submit" name="verify_reset_otp" class="btn-primary">Verify Code</button>
        </form>
        <form method="POST" class="auth-footer otp-footer">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <button type="submit" name="resend_reset_otp" class="auth-resend-btn">Didn't get it? Resend code</button>
        </form>
      </div>
    </div>

    <div id="authView-reset-password" class="auth-view" style="<?= $showResetPassModal ? '' : 'display:none;' ?>">
      <div class="modal-header">
        <h2>Set a new password</h2>
        <button type="button" class="modal-close" data-auth-close>&times;</button>
      </div>
      <div class="modal-body">
        <p class="auth-subtitle">Choose a new password for <strong><?= h($resetEmailMasked) ?></strong>.</p>
        <?php if ($authError && $showResetPassModal): ?>
          <div class="flash flash-error flash-inline"><?= h($authError) ?></div>
        <?php endif; ?>
        <form method="POST">
          <input type="hidden" name="return_to" value="<?= h(current_url_without_auth()) ?>">
          <div class="form-group">
            <label>New password <span class="small-muted">(min 8 characters)</span></label>
            <input type="password" name="password" placeholder="••••••••" required>
            <?php if (isset($resetErrors['password'])): ?><p class="form-error"><?= h($resetErrors['password']) ?></p><?php endif; ?>
          </div>
          <div class="form-group">
            <label>Confirm new password</label>
            <input type="password" name="password_confirm" placeholder="••••••••" required>
            <?php if (isset($resetErrors['password_confirm'])): ?><p class="form-error"><?= h($resetErrors['password_confirm']) ?></p><?php endif; ?>
          </div>
          <button type="submit" name="reset_password_submit" class="btn-primary">Update Password</button>
        </form>
      </div>
    </div>

  </div>
</div>
<script>
(function () {
  var overlay = document.getElementById('authModalOverlay');
  var views = {
    login: document.getElementById('authView-login'),
    register: document.getElementById('authView-register'),
    otp: document.getElementById('authView-otp'),
    forgot: document.getElementById('authView-forgot'),
    'reset-otp': document.getElementById('authView-reset-otp'),
    'reset-password': document.getElementById('authView-reset-password'),
  };
  function open(view) {
    Object.keys(views).forEach(function (k) { views[k].style.display = (k === view) ? '' : 'none'; });
    overlay.classList.add('open');
  }
  function close() { overlay.classList.remove('open'); }

  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-auth-close]') || e.target === overlay) { close(); return; }
    var openTrigger = e.target.closest('[data-auth-open]');
    if (openTrigger) { e.preventDefault(); open(openTrigger.getAttribute('data-auth-open')); return; }
    var switchTrigger = e.target.closest('[data-auth-switch]');
    if (switchTrigger) { e.preventDefault(); open(switchTrigger.getAttribute('data-auth-switch')); return; }
    var link = e.target.closest('a[href]');
    if (link) {
      var href = link.getAttribute('href');
      if (/\/login\.php(\?.*)?$/.test(href) || link.classList.contains('btn-login')) { e.preventDefault(); open('login'); }
      else if (/\/register\.php(\?.*)?$/.test(href) || link.classList.contains('btn-register')) { e.preventDefault(); open('register'); }
    }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });

  ['otpCode', 'resetOtpCode'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) el.addEventListener('input', function () { el.value = el.value.replace(/\D/g, '').slice(0, 6); });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>