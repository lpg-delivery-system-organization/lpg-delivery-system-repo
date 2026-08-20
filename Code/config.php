<?php
// config.php
// Change these values to match your MySQL/XAMPP setup.
session_start();

$dbHost = "localhost";
$dbName = "lpg_delivery";
$dbUser = "root";
$dbPass = "";

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed. Check config.php and make sure MySQL is running.");
}

// SMTP settings for password reset emails.
// Update these values before using the forgot password feature in production.
define("SMTP_HOST", "smtp.gmail.com");
define("SMTP_PORT", 587);
define("SMTP_ENCRYPTION", "tls");
define("SMTP_USERNAME", "jayveeesponilla60@gmail.com");
define("SMTP_PASSWORD", "otvj wrdj gqxo qjfy");
define("SMTP_FROM_EMAIL", "jayveeesponilla60@gmail.com");
define("SMTP_FROM_NAME", "LPG Delivery");

function sendPasswordResetEmail(string $toEmail, string $fullName, string $verificationCode): bool {
    require_once __DIR__ . "/../PHPMailer/src/PHPMailer.php";
    require_once __DIR__ . "/../PHPMailer/src/SMTP.php";
    require_once __DIR__ . "/../PHPMailer/src/Exception.php";

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_ENCRYPTION;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = "UTF-8";

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $fullName ?: "Customer");
        $mail->Subject = "Your LPG Delivery password reset code";
        $mail->Body = "Hello {$fullName},\n\n" .
            "Your password reset verification code is: {$verificationCode}\n\n" .
            "This code is valid for 10 minutes.\n\n" .
            "If you did not request this reset, you can ignore this email.";
        $mail->AltBody = "Your LPG Delivery password reset code is: {$verificationCode}. This code is valid for 10 minutes.";

        $mail->send();
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        error_log("SMTP reset email failed for {$toEmail}: " . $e->getMessage());
        return false;
    }
}

function redirectByRole(string $role): void {
    switch ($role) {
        case "admin":
            header("Location: admin_panel.php");
            break;
        case "rider":
            header("Location: rider_panel.php");
            break;
        default:
            header("Location: customer_panel.php");
    }
    exit;
}

function requireRole(string $role): void {
    if (!isset($_SESSION["user_id"], $_SESSION["role"]) || $_SESSION["role"] !== $role) {
        header("Location: index.php");
        exit;
    }
}
?>
