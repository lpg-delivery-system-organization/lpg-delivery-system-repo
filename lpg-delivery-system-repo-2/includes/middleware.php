<?php
/**
 * Middleware Layer - Route Guards and Access Control
 * LPG Delivery System v2
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

/**
 * Custom Exception for Auth & Middleware guards in test and programmatic mode
 */
class AuthException extends RuntimeException {
    private int $statusCode;
    private ?string $redirectUrl;
    private ?array $jsonResponse;

    public function __construct(
        string $message,
        int $statusCode = 0,
        ?string $redirectUrl = null,
        ?array $jsonResponse = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->redirectUrl = $redirectUrl;
        $this->jsonResponse = $jsonResponse;
    }

    public function getStatusCode(): int {
        return $this->statusCode;
    }

    public function getRedirectUrl(): ?string {
        return $this->redirectUrl;
    }

    public function getJsonResponse(): ?array {
        return $this->jsonResponse;
    }
}

/**
 * Determine whether the current HTTP request is an AJAX or API request
 *
 * Checks:
 * 1. HTTP_X_REQUESTED_WITH == 'xmlhttprequest'
 * 2. HTTP_X_CSRF_TOKEN presence (typical for Fetch/Axios AJAX requests)
 * 3. Accept header requesting application/json
 * 4. Content-Type header specifying application/json
 *
 * @return bool
 */
function is_ajax(): bool {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return true;
    }

    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        return true;
    }

    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        return true;
    }

    return false;
}

/**
 * Require user to be logged in
 *
 * If unauthenticated:
 * - For AJAX: returns HTTP 401 JSON response
 * - For Web: sets flash error and redirects to login page
 *
 * @return void
 * @throws AuthException When running under test mode
 */
function require_login(): void {
    if (!is_logged_in()) {
        if (is_ajax()) {
            $responseData = [
                'success' => false,
                'error' => 'Authentication required. Please log in.',
                'code' => 401
            ];

            if (!empty($GLOBALS['TEST_MODE'])) {
                $GLOBALS['LAST_HTTP_CODE'] = 401;
                $GLOBALS['LAST_RESPONSE'] = $responseData;
                throw new AuthException('Authentication required. Please log in.', 401, null, $responseData);
            }

            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($responseData);
            exit;
        }

        set_flash('error', 'Please log in to continue.');
        $redirectUrl = url('/login.php');

        if (!empty($GLOBALS['TEST_MODE'])) {
            $GLOBALS['LAST_HTTP_CODE'] = 302;
            $GLOBALS['LAST_REDIRECT'] = $redirectUrl;
            throw new AuthException('Authentication required. Redirect to login.', 302, $redirectUrl);
        }

        redirect('/login.php');
    }
}

/**
 * Require user to possess at least one of the specified roles
 *
 * Automatically verifies login first.
 * If unauthorized:
 * - For AJAX: returns HTTP 403 JSON response
 * - For Web: sets flash error and redirects to the user's role-appropriate dashboard
 *
 * @param string ...$roles Allowed role names (e.g. 'admin', 'rider', 'customer')
 * @return void
 * @throws AuthException When running under test mode
 */
function require_role(string ...$roles): void {
    require_login();

    $userRole = current_user_role();
    if ($userRole === null || !in_array($userRole, $roles, true)) {
        if (is_ajax()) {
            $responseData = [
                'success' => false,
                'error' => 'Access denied. You do not have permission to access this resource.',
                'code' => 403
            ];

            if (!empty($GLOBALS['TEST_MODE'])) {
                $GLOBALS['LAST_HTTP_CODE'] = 403;
                $GLOBALS['LAST_RESPONSE'] = $responseData;
                throw new AuthException('Access denied. Insufficient role permissions.', 403, null, $responseData);
            }

            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($responseData);
            exit;
        }

        set_flash('error', 'Access denied. You do not have permission to access this page.');

        $targetDashboard = '/customer/dashboard.php';
        if ($userRole === 'admin') {
            $targetDashboard = '/admin/dashboard.php';
        } elseif ($userRole === 'rider') {
            $targetDashboard = '/rider/dashboard.php';
        }

        $redirectUrl = url($targetDashboard);

        if (!empty($GLOBALS['TEST_MODE'])) {
            $GLOBALS['LAST_HTTP_CODE'] = 403;
            $GLOBALS['LAST_REDIRECT'] = $redirectUrl;
            throw new AuthException('Access denied. Redirect to role dashboard.', 403, $redirectUrl);
        }

        redirect($targetDashboard);
    }
}

/**
 * Require visitor to be an unauthenticated guest
 *
 * If user is already authenticated:
 * - For AJAX: returns HTTP 400 Bad Request JSON response
 * - For Web: redirects directly to role dashboard
 *
 * @return void
 * @throws AuthException When running under test mode
 */
function require_guest(): void {
    if (is_logged_in()) {
        $userRole = current_user_role();
        $targetDashboard = '/customer/dashboard.php';
        if ($userRole === 'admin') {
            $targetDashboard = '/admin/dashboard.php';
        } elseif ($userRole === 'rider') {
            $targetDashboard = '/rider/dashboard.php';
        }

        if (is_ajax()) {
            $responseData = [
                'success' => false,
                'error' => 'Already authenticated.',
                'code' => 400
            ];

            if (!empty($GLOBALS['TEST_MODE'])) {
                $GLOBALS['LAST_HTTP_CODE'] = 400;
                $GLOBALS['LAST_RESPONSE'] = $responseData;
                throw new AuthException('Already authenticated.', 400, null, $responseData);
            }

            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($responseData);
            exit;
        }

        $redirectUrl = url($targetDashboard);

        if (!empty($GLOBALS['TEST_MODE'])) {
            $GLOBALS['LAST_HTTP_CODE'] = 302;
            $GLOBALS['LAST_REDIRECT'] = $redirectUrl;
            throw new AuthException('Guest required. Redirect to role dashboard.', 302, $redirectUrl);
        }

        redirect($targetDashboard);
    }
}

/**
 * Guard POST/AJAX requests requiring valid CSRF tokens
 *
 * @param string|null $token Optional explicit token string
 * @return void
 * @throws AuthException When running under test mode
 */
function require_csrf(?string $token = null): void {
    if (!verify_csrf($token)) {
        if (is_ajax()) {
            $responseData = [
                'success' => false,
                'error' => 'Invalid or missing CSRF security token.',
                'code' => 403
            ];

            if (!empty($GLOBALS['TEST_MODE'])) {
                $GLOBALS['LAST_HTTP_CODE'] = 403;
                $GLOBALS['LAST_RESPONSE'] = $responseData;
                throw new AuthException('Invalid or missing CSRF token.', 403, null, $responseData);
            }

            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode($responseData);
            exit;
        }

        set_flash('error', 'Security token expired or invalid. Please try again.');
        $redirectUrl = url('/index.php');

        if (!empty($GLOBALS['TEST_MODE'])) {
            $GLOBALS['LAST_HTTP_CODE'] = 403;
            $GLOBALS['LAST_REDIRECT'] = $redirectUrl;
            throw new AuthException('Invalid CSRF token.', 403, $redirectUrl);
        }

        redirect('/index.php');
    }
}
