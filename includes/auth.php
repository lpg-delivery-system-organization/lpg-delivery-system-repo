<?php
/**
 * Authentication and Session Security Layer
 * LPG Delivery System v2
 */

require_once __DIR__ . '/helpers.php';

// Load app config for session lifetime if available
$appConfigFile = __DIR__ . '/../config/app.php';
$sessionLifetime = 1800; // 30 minutes default
$maxLoginAttempts = 5;
$rateLimitLockout = 900; // 15 minutes default

if (file_exists($appConfigFile)) {
    $config = require $appConfigFile;
    if (is_array($config)) {
        $sessionLifetime = (int)($config['session_lifetime'] ?? 1800);
        $maxLoginAttempts = (int)($config['max_login_attempts'] ?? 5);
        $rateLimitLockout = (int)($config['rate_limit_lockout'] ?? 900);
    }
}

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', $sessionLifetime);
}
if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', $maxLoginAttempts);
}
if (!defined('RATE_LIMIT_LOCKOUT')) {
    define('RATE_LIMIT_LOCKOUT', $rateLimitLockout);
}

/**
 * Initialize a secure PHP session with hardened cookie parameters
 *
 * Configures:
 * - httponly: true (prevents XSS access to cookie)
 * - samesite: Strict (CSRF mitigation)
 * - secure: true when HTTPS is enabled
 * - use_strict_mode: true (prevents uninitialized session ID adoption)
 *
 * Checks:
 * - 30-minute inactivity timeout check
 *
 * @param int|null $customLifetime
 * @return void
 */
function init_session(?int $customLifetime = null): void {
    $lifetime = $customLifetime ?? (defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 1800);

    if (session_status() === PHP_SESSION_NONE) {
        if (!headers_sent()) {
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                        (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            } else {
                session_set_cookie_params(
                    0,
                    '/; samesite=Strict',
                    '',
                    $isSecure,
                    true
                );
            }
            ini_set('session.use_strict_mode', '1');
            ini_set('session.cookie_httponly', '1');
            @session_start();
        } elseif (php_sapi_name() === 'cli' && empty($_SESSION)) {
            $_SESSION = [];
        }
    }

    // Check inactivity timeout
    check_session_timeout($lifetime);
}

/**
 * Check if the active session has exceeded the inactivity lifetime limit
 *
 * @param int $lifetime Maximum allowed inactivity in seconds (default 1800)
 * @return bool True if session is valid and active; false if timed out and cleared
 */
function check_session_timeout(int $lifetime = 1800): bool {
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - (int)$_SESSION['last_activity'];
        if ($elapsed > $lifetime) {
            $wasLoggedIn = !empty($_SESSION['user_id']);
            logout_user();
            if ($wasLoggedIn) {
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'message' => 'Your session has expired due to inactivity. Please log in again.'
                ];
            }
            return false;
        }
    }

    // Refresh activity timestamp if user is logged in
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }

    return true;
}

/**
 * Generate or retrieve the current session's CSRF token
 *
 * @return string 64-character hexadecimal CSRF token
 */
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        init_session();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Return an HTML hidden input tag containing the CSRF token
 *
 * @return string HTML <input> element
 */
function csrf_input(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Timing-safe verification of the CSRF token from POST or HTTP Header
 *
 * Supports:
 * 1. Explicit token string passed as argument
 * 2. POST parameter $_POST['csrf_token']
 * 3. Header HTTP_X_CSRF_TOKEN (standard for AJAX requests)
 * 4. Header HTTP_CSRF_TOKEN
 *
 * @param string|null $token
 * @return bool
 */
function verify_csrf(?string $token = null): bool {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        init_session();
    }

    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    if ($token === null) {
        if (!empty($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (!empty($_SERVER['HTTP_CSRF_TOKEN'])) {
            $token = $_SERVER['HTTP_CSRF_TOKEN'];
        }
    }

    if (empty($token) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sliding window rate limiting helper
 *
 * Tracks attempts for an action name in the active session.
 *
 * @param string $action Action key (e.g. 'login', 'password_reset')
 * @param int $max Maximum number of allowed attempts within the window
 * @param int $window Window duration in seconds (default 900 = 15 minutes)
 * @return bool True if action is within limit; false if rate limit exceeded
 */
function check_rate_limit(string $action, int $max = 5, int $window = 900): bool {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        init_session();
    }

    $now = time();

    if (!isset($_SESSION['rate_limits']) || !is_array($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $attempts = $_SESSION['rate_limits'][$action] ?? [];
    if (!is_array($attempts)) {
        $attempts = [];
    }

    // Filter out attempts outside the sliding window
    $validAttempts = array_values(array_filter($attempts, function ($timestamp) use ($now, $window) {
        return ($now - (int)$timestamp) < $window;
    }));

    if (count($validAttempts) >= $max) {
        $_SESSION['rate_limits'][$action] = $validAttempts;
        return false;
    }

    // Record this attempt
    $validAttempts[] = $now;
    $_SESSION['rate_limits'][$action] = $validAttempts;
    return true;
}

/**
 * Reset rate limit counters for a specific action
 *
 * @param string $action
 * @return void
 */
function reset_rate_limit(string $action): void {
    if (isset($_SESSION['rate_limits'][$action])) {
        unset($_SESSION['rate_limits'][$action]);
    }
}

/**
 * Get remaining rate limit attempts for an action
 *
 * @param string $action
 * @param int $max
 * @param int $window
 * @return int
 */
function get_rate_limit_remaining(string $action, int $max = 5, int $window = 900): int {
    $now = time();
    $attempts = $_SESSION['rate_limits'][$action] ?? [];
    if (!is_array($attempts)) {
        return $max;
    }

    $validAttempts = array_filter($attempts, function ($timestamp) use ($now, $window) {
        return ($now - (int)$timestamp) < $window;
    });

    return max(0, $max - count($validAttempts));
}

/**
 * Log in a user and establish an authenticated session
 *
 * Regenerates the session ID to protect against session fixation attacks.
 * Strips sensitive fields (like password hash) from the stored user array.
 *
 * @param array $user User database record array
 * @return void
 */
function login_user(array $user): void {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        init_session();
    }

    // Regenerate session ID to prevent session fixation
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        @session_regenerate_id(true);
    }

    // Sanitize user record (never store password in session)
    $userData = $user;
    unset($userData['password']);

    $_SESSION['user'] = $userData;
    $_SESSION['user_id'] = (int)($user['id'] ?? 0);
    $_SESSION['user_role'] = (string)($user['role'] ?? 'customer');
    $_SESSION['user_name'] = (string)($user['full_name'] ?? '');
    $_SESSION['user_email'] = (string)($user['email'] ?? '');
    $_SESSION['last_activity'] = time();

    // Regenerate CSRF token on authentication boundary
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Terminate user session and clean up session cookie
 *
 * @return void
 */
function logout_user(): void {
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get("session.use_cookies") && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        if (empty($GLOBALS['TEST_MODE']) && !headers_sent()) {
            @session_destroy();
        }
    }
}

/**
 * Check if the current visitor is authenticated
 *
 * @return bool
 */
function is_logged_in(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        init_session();
    }
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user']);
}

/**
 * Get authenticated user session data array
 *
 * @return array|null
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return $_SESSION['user'] ?? null;
}

/**
 * Get authenticated user ID
 *
 * @return int|null
 */
function current_user_id(): ?int {
    if (!is_logged_in()) {
        return null;
    }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Get authenticated user role ('customer', 'admin', 'rider')
 *
 * @return string|null
 */
function current_user_role(): ?string {
    if (!is_logged_in()) {
        return null;
    }
    return $_SESSION['user_role'] ?? null;
}

/**
 * Check if the authenticated user has one of the specified roles
 *
 * @param string ...$roles
 * @return bool
 */
function has_role(string ...$roles): bool {
    if (!is_logged_in()) {
        return false;
    }
    $role = current_user_role();
    return $role !== null && in_array($role, $roles, true);
}

// Auto-initialize session if not in CLI and headers not sent
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE && !headers_sent()) {
    init_session();
}
