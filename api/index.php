<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

// =========================================================
//  PASSWORD STRENGTH VALIDATION
// =========================================================
function isStrongPassword($password)
{
    // At least 8 characters, uppercase, lowercase, digit, and special char
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'load';
        if ($action !== 'load') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            exit;
        }

        echo json_encode(getDbData());
        exit;
    }

    if ($method === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $action = $payload['action'] ?? 'save';

        switch ($action) {
            case 'save':
                if (!isset($payload['data']) || !is_array($payload['data'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid payload']);
                    exit;
                }
                saveDbData($payload['data']);
                echo json_encode(['ok' => true]);
                exit;

            case 'register':
                handleRegister($payload);
                break;

            case 'send_reset_code':
                $email = trim($payload['email'] ?? '');
                handleSendResetCode($email);
                break;

            case 'verify_reset_code':
                $email = trim($payload['email'] ?? '');
                $code = trim($payload['code'] ?? '');
                handleVerifyResetCode($email, $code);
                break;

            case 'reset_password':
                $email = trim($payload['email'] ?? '');
                $code = trim($payload['code'] ?? '');
                $newPassword = $payload['password'] ?? '';
                handleResetPassword($email, $code, $newPassword);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
                exit;
        }

        echo json_encode(['error' => 'Unhandled action']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// =========================================================
//  REGISTER HANDLER (direct to MySQL)
// =========================================================
function handleRegister($payload)
{
    $name = trim($payload['name'] ?? '');
    $email = trim($payload['email'] ?? '');
    $password = $payload['password'] ?? '';
    $address = trim($payload['address'] ?? '');
    $phone = trim($payload['phone'] ?? '');

    if ($name === '' || $phone === '' || $address === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Please fill in all fields.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address.']);
        exit;
    }

if (!isStrongPassword($password)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.']);
        exit;
    }

    if (userExists($email)) {
        http_response_code(400);
        echo json_encode(['error' => 'Email already registered.']);
        exit;
    }

    $user = storeUser($name, $email, $password, $address, $phone);

    echo json_encode(['ok' => true, 'user' => $user]);
    exit;
}

// =========================================================
//  PASSWORD RESET HANDLERS
// =========================================================
function handleSendResetCode($email)
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email address.']);
        exit;
    }

    $users = getDbData()['users'];
    $found = false;
    foreach ($users as $u) {
        if (strtolower($u['email']) === strtolower($email)) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'No account found with that email.']);
        exit;
    }

    // Generate 6-digit code valid for 10 minutes
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('c', time() + (10 * 60));

    storeResetCode($email, $code, $expiresAt);

    $subject = 'Your LPG Delivery Password Reset Code';
    $body = "Hello,\n\n"
        . "You requested to reset your password for your LPG Delivery account.\n\n"
        . "Your verification code is: {$code}\n\n"
        . "This code will expire in 10 minutes.\n\n"
        . "If you did not request this, please ignore this email.\n\n"
        . "— LPG Delivery System";

    $sent = sendMail($email, $subject, $body);

    @$GLOBALS['__reset_code_for_demo'] = $code;

    echo json_encode([
        'ok' => true,
        'email' => $email,
        'demo' => $sent ? false : true,
        'message' => $sent
            ? 'A verification code was sent to your email.'
            : 'Could not send email (SMTP not configured). Use the displayed code below for testing.',
        'debugCode' => $sent ? null : $code
    ]);
    exit;
}

function handleVerifyResetCode($email, $code)
{
    $stored = getResetCode($email);

    if (!$stored) {
        http_response_code(400);
        echo json_encode(['error' => 'No reset request found. Please request a new code.']);
        exit;
    }

    if (strtotime($stored['expires_at']) < time()) {
        clearResetCode($email);
        http_response_code(400);
        echo json_encode(['error' => 'The code has expired. Please request a new code.']);
        exit;
    }

    if (!hash_equals((string) $stored['code'], (string) $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid verification code.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Code verified.']);
    exit;
}

function handleResetPassword($email, $code, $newPassword)
{
    if (!isStrongPassword($newPassword)) {
        http_response_code(400);
        echo json_encode(['error' => 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a special character.']);
        exit;
    }

    // Re-verify code to prevent bypass
    $stored = getResetCode($email);
    if (!$stored || strtotime($stored['expires_at']) < time() || !hash_equals((string) $stored['code'], (string) $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or expired code. Please request a new one.']);
        exit;
    }

    updateUserPassword($email, $newPassword);
    clearResetCode($email);

    echo json_encode(['ok' => true, 'message' => 'Password has been reset successfully.']);
    exit;
}

// =========================================================
//  SIMPLE SMTP MAIL SENDER (native PHP, no libraries)
// =========================================================
function sendMail($to, $subject, $body)
{
    if (SMTP_USER === 'YOUR_GMAIL_ADDRESS@gmail.com' || SMTP_PASS === 'YOUR_16_CHAR_APP_PASSWORD') {
        return false; // not configured
    }

    $header  = "MIME-Version: 1.0\r\n";
    $header .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $header .= "From: " . MAIL_FROM . "\r\n";
    $header .= "To: " . $to . "\r\n";

    $eol = "\r\n";
    $socketTimeout = 20;

    $smtp = @stream_socket_client(
        'tcp://' . SMTP_HOST . ':' . SMTP_PORT,
        $errno,
        $errstr,
        $socketTimeout
    );
    if (!$smtp) {
        return false;
    }

    if (!smtpRead($smtp, 220)) {
        fclose($smtp);
        return false;
    }

    $secure = (SMTP_PORT == 465);
    if ($secure) {
        // Implicit TLS (port 465)
        stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!smtpWrite($smtp, 'EHLO localhost' . $eol, 250)) {
            fclose($smtp);
            return false;
        }
    } else {
        // STARTTLS (port 587)
        if (!smtpWrite($smtp, 'EHLO localhost' . $eol, 250)) {
            fclose($smtp);
            return false;
        }
        if (!smtpWrite($smtp, 'STARTTLS' . $eol, 220)) {
            fclose($smtp);
            return false;
        }
        stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!smtpWrite($smtp, 'EHLO localhost' . $eol, 250)) {
            fclose($smtp);
            return false;
        }
    }

    // AUTH LOGIN
    if (!smtpWrite($smtp, 'AUTH LOGIN' . $eol, 334)) {
        fclose($smtp);
        return false;
    }
    if (!smtpWrite($smtp, base64_encode(SMTP_USER) . $eol, 334)) {
        fclose($smtp);
        return false;
    }
    if (!smtpWrite($smtp, base64_encode(SMTP_PASS) . $eol, 235)) {
        fclose($smtp);
        return false;
    }

    if (!smtpWrite($smtp, 'MAIL FROM:<' . SMTP_USER . '>' . $eol, 250)) {
        fclose($smtp);
        return false;
    }
    if (!smtpWrite($smtp, 'RCPT TO:<' . $to . '>' . $eol, 250)) {
        fclose($smtp);
        return false;
    }
    if (!smtpWrite($smtp, 'DATA' . $eol, 354)) {
        fclose($smtp);
        return false;
    }

    $message = "Subject: " . $subject . $eol . $header . $eol . $body . $eol . "." . $eol;
    if (!smtpWrite($smtp, $message, 250)) {
        fclose($smtp);
        return false;
    }

    smtpWrite($smtp, 'QUIT' . $eol, 221);
    fclose($smtp);

    return true;
}

function smtpWrite($smtp, $data, $expectedCode)
{
    fwrite($smtp, $data);
    $response = '';

    while ($line = fgets($smtp, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    $code = (int) substr($response, 0, 3);
    return $code === $expectedCode;
}

function smtpRead($smtp, $expectedCode)
{
    $response = '';
    while ($line = fgets($smtp, 515)) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    $code = (int) substr($response, 0, 3);
    return $code === $expectedCode;
}
