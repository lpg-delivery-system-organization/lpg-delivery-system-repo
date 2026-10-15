<?php
/**
 * Send Test Mail
 * LPG Delivery System v2
 *
 * Diagnostic CLI tool: sends one real email through the configured Mailer so
 * you can confirm SMTP delivery to a Gmail inbox without touching the web UI.
 *
 *   php database/tools/send-test-mail.php recipient@gmail.com
 *
 * Options:
 *   --to=EMAIL      Recipient address (alternative to the positional argument).
 *   --subject=TEXT  Custom subject line.
 *   --debug         Stream the full SMTP transcript (implies verbose output).
 *
 * The recipient is any address you want to test - it has nothing to do with the
 * sender. The sender/credentials come from config/mail.local.php (or the
 * committed config/mail.php template when the local file is absent).
 *
 * Exit codes: 0 sent, 1 error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../classes/Mailer.php';

$recipient = null;
$subject = 'Elevate LPG - SMTP test';
$debug = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--to=(.+)$/', $arg, $m)) {
        $recipient = trim($m[1]);
    } elseif (preg_match('/^--subject=(.+)$/', $arg, $m)) {
        $subject = trim($m[1]);
    } elseif ($arg === '--debug') {
        $debug = true;
    } elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    } elseif ($recipient === null) {
        $recipient = trim($arg);
    } else {
        fwrite(STDERR, "Unexpected argument: {$arg}\n");
        exit(1);
    }
}

if ($recipient === null || $recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php database/tools/send-test-mail.php recipient@example.com [--debug]\n");
    exit(1);
}

$localConfig = dirname(__DIR__, 2) . '/config/mail.local.php';
$usingLocal = file_exists($localConfig);

echo "=== SMTP test ===\n";
echo 'Recipient:  ' . $recipient . "\n";
echo 'Config:     ' . ($usingLocal ? 'config/mail.local.php' : 'config/mail.php (template)') . "\n";

$mailer = new Mailer();
if ($debug) {
    $mailer->setDebug(2);
}

echo 'Mock mode:  ' . ($mailer->isMockMode() ? 'YES (nothing will be sent)' : 'no (sending for real)') . "\n\n";

if ($mailer->isMockMode()) {
    fwrite(STDERR, "Mailer is in mock mode - no email was sent.\n");
    fwrite(STDERR, "Create config/mail.local.php with your Gmail address + App Password and\n");
    fwrite(STDERR, "set 'mock_mode' => false, then run this command again.\n");
    exit(1);
}

$appName = defined('APP_NAME') ? APP_NAME : 'Elevate LPG';
$html = '<p>This is a test email from the <strong>' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</strong> mail configuration.</p>'
    . '<p>If you are reading this, SMTP delivery works.</p>';
$text = "This is a test email from the {$appName} mail configuration.\n\nIf you are reading this, SMTP delivery works.";

echo "Sending...\n";
$ok = $mailer->send($recipient, 'Mail Test', $subject, $html, $text);

if ($ok) {
    echo "\n[OK] Email accepted by the SMTP server for {$recipient}.\n";
    echo "Check the inbox (and the Spam folder) in a moment.\n";
    exit(0);
}

echo "\n[FAIL] The SMTP server rejected the message.\n";
$error = $mailer->getLastError();
if ($error !== null && $error !== '') {
    echo 'Reason: ' . $error . "\n";
}
echo "Tips: use a Gmail address + 16-char App Password (2-Step Verification on),\n";
echo "     set from_email equal to that Gmail address, and re-run with --debug.\n";
exit(1);
