<?php
/**
 * Mailer Wrapper Class
 * LPG Delivery System v2
 */

class Mailer {
    /**
     * @var array
     */
    private array $config;

    /**
     * @var bool
     */
    private bool $mockMode;

    /**
     * In-memory store for sent emails in mock/test mode
     *
     * @var array
     */
    private static array $sentLogs = [];

    /**
     * Mailer Constructor
     *
     * @param array|null $config Optional custom configuration array
     */
    public function __construct(?array $config = null) {
        if ($config === null) {
            $configFile = dirname(__DIR__) . '/config/mail.php';
            if (file_exists($configFile)) {
                $this->config = require $configFile;
            } else {
                $this->config = [
                    'host' => 'smtp.gmail.com',
                    'port' => 587,
                    'encryption' => 'tls',
                    'username' => 'noreply@lpgdeliverysystem.com',
                    'password' => '',
                    'from_email' => 'noreply@lpgdeliverysystem.com',
                    'from_name' => 'LPG Delivery System',
                    'mock_mode' => true,
                ];
            }
        } else {
            $this->config = $config;
        }

        $this->mockMode = (bool)($this->config['mock_mode'] ?? true);
    }

    /**
     * Check whether mailer is in mock/fallback mode
     *
     * @return bool
     */
    public function isMockMode(): bool {
        return $this->mockMode;
    }

    /**
     * Set mock mode flag
     *
     * @param bool $mock
     * @return void
     */
    public function setMockMode(bool $mock): void {
        $this->mockMode = $mock;
    }

    /**
     * Retrieve all sent email logs from mock mode
     *
     * @return array
     */
    public static function getSentLogs(): array {
        return self::$sentLogs;
    }

    /**
     * Retrieve the most recently sent email record from mock mode
     *
     * @return array|null
     */
    public static function getLastSent(): ?array {
        if (empty(self::$sentLogs)) {
            return null;
        }
        return self::$sentLogs[count(self::$sentLogs) - 1];
    }

    /**
     * Clear the mock email logs
     *
     * @return void
     */
    public static function clearSentLogs(): void {
        self::$sentLogs = [];
    }

    /**
     * Send an email (via PHPMailer or mock logger)
     *
     * @param string $toEmail Recipient email address
     * @param string $toName Recipient name
     * @param string $subject Email subject line
     * @param string $bodyHtml HTML message body
     * @param string $altBodyText Plaintext alternative body
     * @return bool True if sent (or mocked successfully)
     */
    public function send(string $toEmail, string $toName, string $subject, string $bodyHtml, string $altBodyText = ''): bool {
        // Fallback / mock mode: capture in memory
        if ($this->mockMode || !class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            self::$sentLogs[] = [
                'to_email'     => $toEmail,
                'to_name'      => $toName,
                'subject'      => $subject,
                'body_html'    => $bodyHtml,
                'alt_body'     => $altBodyText ?: strip_tags($bodyHtml),
                'from_email'   => $this->config['from_email'] ?? 'noreply@lpgdeliverysystem.com',
                'from_name'    => $this->config['from_name'] ?? 'LPG Delivery System',
                'timestamp'    => time(),
            ];
            return true;
        }

        // Real PHPMailer sending (when PHPMailer is loaded and mock_mode is false)
        try {
            $mailClass = 'PHPMailer\PHPMailer\PHPMailer';
            /** @var dynamic $mail */
            $mail = new $mailClass(true);

            $mail->isSMTP();
            $mail->Host       = $this->config['host'] ?? 'localhost';
            $mail->SMTPAuth   = !empty($this->config['password']);
            $mail->Username   = $this->config['username'] ?? '';
            $mail->Password   = $this->config['password'] ?? '';
            $mail->SMTPSecure = $this->config['encryption'] ?? 'tls';
            $mail->Port       = (int)($this->config['port'] ?? 587);

            $fromEmail = $this->config['from_email'] ?? 'noreply@lpgdeliverysystem.com';
            $fromName  = $this->config['from_name'] ?? 'LPG Delivery System';
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($toEmail, $toName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = $altBodyText ?: strip_tags($bodyHtml);

            return (bool)$mail->send();
        } catch (Throwable $e) {
            error_log("Mailer error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send a password reset email with token and link
     *
     * @param string $toEmail Recipient email address
     * @param string $resetToken Generated reset token string
     * @param string $recipientName Recipient display name
     * @param string|null $resetBaseUrl Optional custom base URL for reset link
     * @return bool
     */
    public function sendResetEmail(
        string $toEmail,
        string $resetToken,
        string $recipientName = 'Customer',
        ?string $resetBaseUrl = null
    ): bool {
        $baseUrl = $resetBaseUrl;
        if ($baseUrl === null) {
            $baseUrl = defined('BASE_URL') ? BASE_URL : '';
        }

        $resetUrl = rtrim($baseUrl, '/') . '/forgot-password.php?token=' . urlencode($resetToken);
        $appName = defined('APP_NAME') ? APP_NAME : 'LPG Delivery System';

        $subject = "Password Reset Request - {$appName}";

        $safeName = htmlspecialchars($recipientName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{$subject}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <tr>
            <td style="background-color: #0d9488; padding: 24px; text-align: center; color: #ffffff;">
                <h1 style="margin: 0; font-size: 22px;">{$appName}</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px 24px;">
                <p style="font-size: 16px; line-height: 1.5; margin-top: 0;">Hello <strong>{$safeName}</strong>,</p>
                <p style="font-size: 15px; line-height: 1.5;">We received a request to reset the password for your account. Click the button below to set a new password:</p>
                <p style="text-align: center; margin: 30px 0;">
                    <a href="{$safeUrl}" style="background-color: #0d9488; color: #ffffff; padding: 12px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;">Reset My Password</a>
                </p>
                <p style="font-size: 13px; color: #6c757d; line-height: 1.4;">This link will expire in 60 minutes. If you did not request a password reset, please ignore this email or contact support if you suspect unauthorized access.</p>
                <hr style="border: none; border-top: 1px solid #e9ecef; margin: 24px 0;">
                <p style="font-size: 12px; color: #adb5bd; word-break: break-all;">If the button doesn't work, copy and paste this URL into your browser:<br><a href="{$safeUrl}" style="color: #0d9488;">{$safeUrl}</a></p>
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #6c757d;">
                &copy; 2026 {$appName}. All rights reserved.
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $plainText = "Hello {$recipientName},\n\n"
            . "We received a request to reset your password. Use the link below to set a new password:\n"
            . "{$resetUrl}\n\n"
            . "This link expires in 60 minutes. If you did not request this, please ignore this email.\n\n"
            . "— {$appName} Team";

        return $this->send($toEmail, $recipientName, $subject, $htmlBody, $plainText);
    }
}
