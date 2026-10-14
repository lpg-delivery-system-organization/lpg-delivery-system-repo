<?php
/**
 * Purge Rejected Government ID Accounts
 * LPG Delivery System v2
 *
 * Enforces the government ID rejection life-cycle: applicants whose ID was
 * rejected and not replaced within the grace window have their account removed.
 * A final reminder is emailed/in-app notified shortly before removal.
 *
 * Run it from a scheduler — Windows Task Scheduler, cron, or a hosting panel's
 * cron feature — for example once an hour:
 *
 *   php database/tools/purge-rejected-users.php
 *
 * Options:
 *   --dry-run       List what would be warned/purged; make no changes.
 *   --grace=HOURS   Override the grace window (default from config/app.php).
 *   --lead=HOURS    Override the reminder lead window.
 *   --keep-files    Delete accounts but leave uploaded ID documents on disk.
 *
 * Exit codes: 0 success, 1 error.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../includes/verification.php';

$options = [
    'dry-run'    => false,
    'keep-files' => false,
    'grace'      => 0,
    'lead'       => 0,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $options['dry-run'] = true;
    } elseif ($arg === '--keep-files') {
        $options['keep-files'] = true;
    } elseif (preg_match('/^--grace=(\d+)$/', $arg, $m)) {
        $options['grace'] = (int)$m[1];
    } elseif (preg_match('/^--lead=(\d+)$/', $arg, $m)) {
        $options['lead'] = (int)$m[1];
    } else {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        exit(1);
    }
}

$graceHours = $options['grace'] > 0
    ? $options['grace']
    : (defined('ID_REJECTION_GRACE_HOURS') ? (int)ID_REJECTION_GRACE_HOURS : 24);
$leadHours = $options['lead'] > 0
    ? $options['lead']
    : (defined('ID_REJECTION_REMINDER_LEAD_HOURS') ? (int)ID_REJECTION_REMINDER_LEAD_HOURS : 4);

try {
    $userModel = new User(Database::connect());
} catch (Throwable $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "=== Rejected ID account cleanup ===\n";
echo "Grace: {$graceHours}h | Reminder lead: {$leadHours}h | Files: "
    . ($options['keep-files'] ? 'keep' : 'delete') . "\n\n";

if ($options['dry-run']) {
    $reminders = $userModel->getRejectionsDueForReminder($graceHours, $leadHours);
    $expired = $userModel->getExpiredRejections($graceHours);

    echo '[DRY RUN] Reminders due: ' . count($reminders) . "\n";
    foreach ($reminders as $applicant) {
        echo '  - #' . (int)$applicant['id'] . ' ' . $applicant['email']
            . ' (rejected at ' . $applicant['id_reviewed_at'] . ")\n";
    }

    echo '[DRY RUN] Accounts to purge: ' . count($expired) . "\n";
    foreach ($expired as $applicant) {
        echo '  - #' . (int)$applicant['id'] . ' '
            . ($applicant['valid_id_path'] !== null && $applicant['valid_id_path'] !== ''
                ? $applicant['valid_id_path']
                : '(no ID file)') . "\n";
    }

    echo "\nNo changes made.\n";
    exit(0);
}

$result = run_verification_cleanup($graceHours, $leadHours, !$options['keep-files']);

if ($result['error'] !== null) {
    fwrite(STDERR, 'Cleanup error: ' . $result['error'] . "\n");
    exit(1);
}

echo "Reminders sent:       {$result['warned']}\n";
echo "Accounts purged:      {$result['purged']}\n";
echo "ID files deleted:     {$result['files_deleted']}\n";
echo "Skipped (have orders): {$result['skipped_with_orders']}\n";
exit(0);
