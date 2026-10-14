<?php
/**
 * Government ID Verification Notifications
 * LPG Delivery System v2
 *
 * Centralised helpers for the ID-verification workflow. They persist in-app
 * notifications (Notification model) and send branded emails (Mailer) whenever
 * an applicant submits an ID or an administrator approves/rejects one.
 *
 * Every helper is defensive: notification or mail failures must never abort the
 * originating request (registration, admin action, re-upload), so all work is
 * wrapped in a Throwable guard and failures are logged.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Notification.php';
require_once __DIR__ . '/../classes/Mailer.php';

if (!function_exists('verification_build_email')) {
    /**
     * Build the branded HTML + plaintext body for a verification email.
     *
     * @param string $appName
     * @param string $heading
     * @param string $intro
     * @param string $bodyLine
     * @param string $accentColor Hex colour for the header/button
     * @param string|null $ctaLabel
     * @param string|null $ctaUrl
     * @return array{html:string,text:string}
     */
    function verification_build_email(
        string $appName,
        string $heading,
        string $intro,
        string $bodyLine,
        string $accentColor = '#0d9488',
        ?string $ctaLabel = null,
        ?string $ctaUrl = null
    ): array {
        $safeApp = htmlspecialchars($appName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeHeading = htmlspecialchars($heading, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeIntro = htmlspecialchars($intro, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeBody = htmlspecialchars($bodyLine, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $ctaHtml = '';
        $ctaText = '';
        if ($ctaLabel !== null && $ctaUrl !== null && $ctaUrl !== '') {
            $safeLabel = htmlspecialchars($ctaLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $safeUrl = htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ctaHtml = '<p style="text-align:center;margin:28px 0;">'
                . '<a href="' . $safeUrl . '" style="background-color:' . $accentColor . ';color:#ffffff;padding:12px 28px;'
                . 'text-decoration:none;border-radius:6px;font-weight:bold;display:inline-block;">' . $safeLabel . '</a></p>';
            $ctaText = "\n\n{$ctaLabel}: {$ctaUrl}";
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{$safeHeading}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
        <tr>
            <td style="background-color: {$accentColor}; padding: 24px; text-align: center; color: #ffffff;">
                <h1 style="margin: 0; font-size: 22px;">{$safeApp}</h1>
            </td>
        </tr>
        <tr>
            <td style="padding: 32px 24px;">
                <h2 style="font-size: 18px; margin-top: 0;">{$safeHeading}</h2>
                <p style="font-size: 15px; line-height: 1.5;">{$safeIntro}</p>
                <p style="font-size: 15px; line-height: 1.5;">{$safeBody}</p>
                {$ctaHtml}
            </td>
        </tr>
        <tr>
            <td style="background-color: #f8f9fa; padding: 16px; text-align: center; font-size: 12px; color: #6c757d;">
                &copy; 2026 {$safeApp}. All rights reserved.
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $text = "{$heading}\n\n{$intro}\n\n{$bodyLine}{$ctaText}\n\n— {$appName} Team";

        return ['html' => $html, 'text' => $text];
    }
}

if (!function_exists('notify_admins_pending_verification')) {
    /**
     * Notify every administrator that a new government ID awaits review.
     *
     * @param int $applicantUserId
     * @param string $applicantName
     * @return void
     */
    function notify_admins_pending_verification(int $applicantUserId, string $applicantName): void {
        try {
            $db = Database::connect();
            $userModel = new User($db);
            $notification = new Notification($db);

            $admins = $userModel->getAllByRole('admin');
            $link = 'pages/admin/users.php?verification=pending';

            foreach ($admins as $admin) {
                $adminId = (int)($admin['id'] ?? 0);
                if ($adminId <= 0) {
                    continue;
                }
                $notification->create(
                    $adminId,
                    'id_verification_submitted',
                    'New ID awaiting verification',
                    $applicantName . ' submitted a government ID for verification.',
                    null,
                    $link
                );
            }
        } catch (Throwable $e) {
            error_log('notify_admins_pending_verification failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notify_user_id_decision')) {
    /**
     * Notify an applicant of an approve/reject decision via in-app + email.
     *
     * @param int $userId Applicant user ID
     * @param string $decision 'approved' or 'rejected'
     * @param string $reason Rejection reason (only used when rejected)
     * @return void
     */
    function notify_user_id_decision(int $userId, string $decision, string $reason = ''): void {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return;
        }

        try {
            $db = Database::connect();
            $userModel = new User($db);
            $user = $userModel->findById($userId);
            if (!$user) {
                return;
            }

            $appName = defined('APP_NAME') ? APP_NAME : 'Elevate LPG';
            $name = (string)($user['full_name'] ?? 'Customer');
            $link = 'pages/customer/verification.php';
            $recipientEmail = (string)($user['email'] ?? '');
            $hasEmail = $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL);

            // 1. Send the email first so the in-app notification can truthfully
            //    report whether a copy was delivered to the applicant.
            $emailSent = false;
            if ($hasEmail) {
                $baseUrl = defined('BASE_URL') ? BASE_URL : '';
                $ctaUrl = rtrim($baseUrl, '/') . '/' . $link;

                if ($decision === 'approved') {
                    $email = verification_build_email(
                        $appName,
                        'Your ID is verified',
                        "Hello {$name}, good news!",
                        'Your government ID has been reviewed and approved. You may now browse the catalog and place orders.',
                        '#0d9488',
                        'Go to Shop',
                        rtrim($baseUrl, '/') . '/pages/customer/shop.php'
                    );
                    $emailSubject = 'Government ID approved - ' . $appName;
                } else {
                    $graceHours = defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24;
                    $email = verification_build_email(
                        $appName,
                        'Your ID was rejected',
                        "Hello {$name}, we could not verify your government ID.",
                        'Reason: ' . ($reason !== '' ? $reason : 'The uploaded document could not be accepted.')
                            . " Please sign in and re-submit a valid government ID within {$graceHours} hours."
                            . ' If you do not, your account will be deleted.',
                        '#dc3545',
                        'Re-upload ID',
                        $ctaUrl
                    );
                    $emailSubject = 'Government ID rejected - ' . $appName;
                }

                try {
                    $mailer = new Mailer();
                    $emailSent = $mailer->send($recipientEmail, $name, $emailSubject, $email['html'], $email['text']);
                } catch (Throwable $mailEx) {
                    error_log('notify_user_id_decision email failed: ' . $mailEx->getMessage());
                    $emailSent = false;
                }
            }

            // 2. In-app notification (always created).
            if ($decision === 'approved') {
                $title = 'Government ID approved';
                $message = 'Your government ID has been verified. You can now place orders.';
            } else {
                $graceHours = defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24;
                $title = $emailSent ? 'Elevate LPG sent you an email' : 'Government ID rejected';
                $message = 'Your government ID was rejected.';
                if ($reason !== '') {
                    $message .= ' Reason: ' . $reason;
                }
                $message .= " Please re-submit a valid ID within {$graceHours} hours, or your account will be deleted.";
                if ($emailSent) {
                    $message .= ' A copy of this notice was emailed to you.';
                }
            }

            $notification = new Notification($db);
            $notification->create($userId, 'id_verification', $title, $message, null, $link);
        } catch (Throwable $e) {
            error_log('notify_user_id_decision failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notify_user_pending_purge')) {
    /**
     * Warn a rejected applicant that their account is about to be deleted
     * because no corrected ID was submitted.
     *
     * Sent via in-app notification and email. Best-effort: a failure here must
     * never abort the cleanup sweep (the account is still purged on schedule).
     *
     * @param int $userId    Applicant user ID
     * @param int $hoursLeft Approximate hours remaining before deletion
     * @return void
     */
    function notify_user_pending_purge(int $userId, int $hoursLeft = 0): void {
        try {
            $db = Database::connect();
            $userModel = new User($db);
            $user = $userModel->findById($userId);
            if (!$user) {
                return;
            }

            $hoursLeft = $hoursLeft > 0 ? (int)$hoursLeft : (defined('ID_REJECTION_REMINDER_LEAD_HOURS') ? (int)ID_REJECTION_REMINDER_LEAD_HOURS : 4);
            $appName = defined('APP_NAME') ? APP_NAME : 'Elevate LPG';
            $name = (string)($user['full_name'] ?? 'Customer');
            $link = 'pages/customer/verification.php';
            $recipientEmail = (string)($user['email'] ?? '');
            $hasEmail = $recipientEmail !== '' && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL);

            // 1. Email first so the notification can report real delivery.
            $emailSent = false;
            if ($hasEmail) {
                $baseUrl = defined('BASE_URL') ? BASE_URL : '';
                $ctaUrl = rtrim($baseUrl, '/') . '/' . $link;

                $email = verification_build_email(
                    $appName,
                    'Your account will be removed',
                    "Hello {$name}, we still need a valid government ID from you.",
                    "Your account is scheduled for automatic removal within {$hoursLeft} hours. Upload a clear, valid government ID to keep it.",
                    '#dc3545',
                    'Upload a new ID',
                    $ctaUrl
                );

                try {
                    $mailer = new Mailer();
                    $emailSent = $mailer->send($recipientEmail, $name, 'Account scheduled for removal - ' . $appName, $email['html'], $email['text']);
                } catch (Throwable $mailEx) {
                    error_log('notify_user_pending_purge email failed: ' . $mailEx->getMessage());
                    $emailSent = false;
                }
            }

            // 2. In-app notification (always created).
            $title = $emailSent ? 'Elevate LPG sent you an email' : 'Account scheduled for removal';
            $message = "Your government ID was rejected and has not been replaced. Upload a valid ID within {$hoursLeft} hours or your account will be deleted.";
            if ($emailSent) {
                $message .= ' A copy of this notice was emailed to you.';
            }

            $notification = new Notification($db);
            $notification->create($userId, 'id_purge_warning', $title, $message, null, $link);
        } catch (Throwable $e) {
            error_log('notify_user_pending_purge failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('run_verification_cleanup')) {
    /**
     * Run the government ID rejection life-cycle sweep: warn applicants whose
     * grace window is nearly over, then delete accounts whose ID was never
     * resubmitted in time.
     *
     * Called opportunistically from the admin UI and from the CLI cron script.
     * All failures are caught so a cleanup error can never break the caller.
     *
     * @param int  $graceHours    Hours after rejection before purge (0 = config default)
     * @param int  $leadHours     Reminder lead window (0 = config default)
     * @param bool $deleteIdFiles Also remove stored ID documents from disk
     * @return array ['warned' => int, 'purged' => int, 'files_deleted' => int, 'skipped_with_orders' => int, 'error' => ?string]
     */
    function run_verification_cleanup(int $graceHours = 0, int $leadHours = 0, bool $deleteIdFiles = true): array {
        $graceHours = $graceHours > 0 ? (int)$graceHours : (defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24);
        $leadHours = $leadHours > 0 ? (int)$leadHours : (defined('ID_REJECTION_REMINDER_LEAD_HOURS') ? (int)ID_REJECTION_REMINDER_LEAD_HOURS : 4);

        $result = [
            'warned' => 0,
            'purged' => 0,
            'files_deleted' => 0,
            'skipped_with_orders' => 0,
            'error' => null,
        ];

        try {
            $db = Database::connect();
            $userModel = new User($db);

            // 1. Send final reminders (at most once per applicant).
            foreach ($userModel->getRejectionsDueForReminder($graceHours, $leadHours) as $applicant) {
                $userId = (int)($applicant['id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                if (!$userModel->markPurgeWarned($userId)) {
                    continue;
                }
                notify_user_pending_purge($userId, $leadHours);
                $result['warned']++;
            }

            // 2. Purge accounts past the grace window.
            $summary = $userModel->purgeExpiredRejections($graceHours, $deleteIdFiles);
            $result['purged'] = (int)$summary['purged'];
            $result['files_deleted'] = (int)$summary['files_deleted'];
            $result['skipped_with_orders'] = (int)$summary['skipped_with_orders'];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            error_log('run_verification_cleanup failed: ' . $e->getMessage());
        }

        return $result;
    }
}
