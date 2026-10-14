<?php
/**
 * Rejected ID Account Cleanup Test Suite
 * LPG Delivery System v2
 *
 * Verifies the government ID rejection life-cycle:
 * 1. Expired rejections are purged (row + uploaded ID file removed)
 * 2. Applicants inside the grace window are left alone
 * 3. Re-submitting an ID removes the applicant from the purge set
 * 4. Applicants with order history are never purged
 * 5. The near-deadline reminder is sent at most once per rejection
 * 6. run_verification_cleanup() warns + purges and is idempotent
 * 7. The CLI script --dry-run makes no changes
 */

// Enable test mode before components load
$GLOBALS['TEST_MODE'] = true;

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Notification.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/verification.php';

$testCount = 0;
$passCount = 0;
$failCount = 0;

function it(string $description, callable $fn): void {
    global $testCount, $passCount, $failCount;
    $testCount++;
    try {
        $result = $fn();
        if ($result !== false) {
            $passCount++;
            echo "  ✓ {$description}\n";
        } else {
            $failCount++;
            echo "  ✗ {$description} (returned false)\n";
        }
    } catch (Throwable $e) {
        $failCount++;
        echo "  ✗ {$description} (Exception: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()})\n";
    }
}

function assert_equals($expected, $actual, string $message = ''): bool {
    if ($expected !== $actual) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            'Expected ' . var_export($expected, true) . ' but got ' . var_export($actual, true)
        );
    }
    return true;
}

function assert_true($actual, string $message = ''): bool {
    if ($actual !== true) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected true but got ' . var_export($actual, true));
    }
    return true;
}

function assert_false($actual, string $message = ''): bool {
    if ($actual !== false) {
        throw new Exception(($message ? $message . ': ' : '') . 'Expected false but got ' . var_export($actual, true));
    }
    return true;
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Rejected ID Cleanup Tests\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);

// Track everything we create so the suite is self-cleaning.
$createdUserIds = [];
$createdOrderIds = [];
$createdFiles = [];

/**
 * Create a customer with a real ID file on disk and (optionally) an order.
 */
function purge_make_rejected_customer(PDO $db, User $userModel, string $tag, bool $withOrder = false): array {
    global $createdUserIds, $createdOrderIds, $createdFiles;

    $token = $tag . '_' . uniqid();
    $userId = $userModel->create([
        'full_name'              => 'Purge ' . ucfirst($tag),
        'email'                  => $token . '@example.com',
        'password'               => 'SecretPass@2026',
        'role'                   => 'customer',
        'phone'                  => '09171112222',
        'address'                => '123 Test St, Test City',
        'valid_id_path'          => 'uploads/ids/' . $token . '.png',
        'status'                 => 'active',
        'id_verification_status' => 'pending',
    ]);
    $createdUserIds[] = $userId;

    // Materialise the ID file so deletion from disk can be asserted.
    $idsDir = (defined('UPLOAD_PATH') ? UPLOAD_PATH : dirname(__DIR__) . '/uploads') . '/ids';
    if (!is_dir($idsDir)) {
        @mkdir($idsDir, 0755, true);
    }
    @file_put_contents($idsDir . '/' . $token . '.png', 'dummy-id-image');

    $userModel->rejectId($userId, 'Blurred photo', 1);

    $createdFiles[] = $idsDir . '/' . $token . '.png';

    if ($withOrder) {
        $orderModel = new Order($db);
        $orderId = $orderModel->place([
            'customer_id'      => $userId,
            'product_id'       => 1,
            'quantity'         => 1,
            'payment_method'   => 'cod',
            'delivery_address' => '123 Test St, Test City',
            'contact_phone'    => '09171112222',
        ], false);
        $createdOrderIds[] = $orderId;
    }

    return [
        'id'       => $userId,
        'token'    => $token,
        'file'     => $idsDir . '/' . $token . '.png',
        'relative' => 'uploads/ids/' . $token . '.png',
    ];
}

function purge_backdate(PDO $db, int $userId, int $hoursAgo): void {
    $stmt = $db->prepare("UPDATE users SET id_reviewed_at = DATE_SUB(NOW(), INTERVAL ? HOUR), purge_warned_at = NULL WHERE id = ?");
    $stmt->execute([$hoursAgo, $userId]);
}

try {
    // ----------------------------------------------------------
    // 1. Purge deletes an expired rejection + its ID file
    // ----------------------------------------------------------
    echo "--- 1. Expired rejection purge ---\n";

    it("purgeExpiredRejections deletes a rejected customer past the grace window and removes the ID file", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'expired');
        purge_backdate($db, $c['id'], 25);

        assert_true(is_file($c['file']), "Setup: ID file should exist before purge");

        $summary = $userModel->purgeExpiredRejections(24);

        assert_true(in_array($c['id'], $summary['ids'], true), "Our expired account must be purged");
        assert_true($summary['purged'] >= 1, "At least our account should be purged");
        assert_true($summary['files_deleted'] >= 1, "Our ID file should be counted as deleted");
        assert_equals(null, $userModel->findById($c['id']), "The user row must be gone");
        assert_false(is_file($c['file']), "The ID file must be removed from disk");
        return true;
    });

    it("purgeExpiredRejections leaves a rejected applicant inside the grace window", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'inside');
        purge_backdate($db, $c['id'], 2);

        $summary = $userModel->purgeExpiredRejections(24);

        assert_equals(0, $summary['purged'], "No account should be purged inside the grace window");
        assert_true($userModel->findById($c['id']) !== null, "The user must still exist");
        assert_true(is_file($c['file']), "The ID file must remain");
        return true;
    });

    it("purgeExpiredRejections leaves a rejected applicant who resubmitted (status pending)", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'resubmit');
        purge_backdate($db, $c['id'], 48);
        $userModel->submitId($c['id'], $c['relative']);

        $summary = $userModel->purgeExpiredRejections(24);

        assert_equals(0, $summary['purged'], "A pending re-submission must not be purged");
        $user = $userModel->findById($c['id']);
        assert_true($user !== null, "The user must still exist");
        assert_equals('pending', $user['id_verification_status']);
        return true;
    });

    it("purgeExpiredRejections skips rejected customers with order history", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'hasorder', true);
        purge_backdate($db, $c['id'], 48);

        $summary = $userModel->purgeExpiredRejections(24);

        assert_true($summary['skipped_with_orders'] >= 1, "Users with orders must be reported as skipped");
        assert_true($userModel->findById($c['id']) !== null, "A customer with orders must never be purged");
        assert_true(is_file($c['file']), "Their ID file must remain untouched");
        return true;
    });

    // ----------------------------------------------------------
    // 2. Reminder + warning marker
    // ----------------------------------------------------------
    echo "\n--- 2. Pending-purge reminder ---\n";

    it("reminder is due near the deadline and markPurgeWarned claims it exactly once", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'remind');
        // Grace 24h, lead 4h => reminder fires once elapsed >= 20h.
        purge_backdate($db, $c['id'], 21);

        $due = $userModel->getRejectionsDueForReminder(24, 4);
        $ids = array_map(fn($r) => (int)$r['id'], $due);
        assert_true(in_array($c['id'], $ids, true), "Applicant must be due for a reminder");

        assert_true($userModel->markPurgeWarned($c['id']), "First claim must succeed");
        assert_false($userModel->markPurgeWarned($c['id']), "Second claim must fail (already warned)");

        $user = $userModel->findById($c['id']);
        assert_true(!empty($user['purge_warned_at']), "purge_warned_at must be set");

        $dueAgain = array_map(fn($r) => (int)$r['id'], $userModel->getRejectionsDueForReminder(24, 4));
        assert_false(in_array($c['id'], $dueAgain, true), "A warned applicant must not be reminded again");
        return true;
    });

    it("resubmitting an ID clears purge_warned_at and the reminder cycle restarts", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'reset');
        purge_backdate($db, $c['id'], 21);

        assert_true($userModel->markPurgeWarned($c['id']), "Warning should be claimable");
        $userModel->submitId($c['id'], $c['relative']);

        $user = $userModel->findById($c['id']);
        assert_equals(null, $user['purge_warned_at'], "Resubmission must clear purge_warned_at");
        assert_equals('pending', $user['id_verification_status']);

        // A fresh rejection must be warnable again.
        $userModel->rejectId($c['id'], 'Second rejection', 1);
        purge_backdate($db, $c['id'], 21);
        assert_true($userModel->markPurgeWarned($c['id']), "Re-rejection must reset the warning and allow a new claim");
        return true;
    });

    // ----------------------------------------------------------
    // 3. Orchestrator (warn + purge, idempotent)
    // ----------------------------------------------------------
    echo "\n--- 3. Cleanup orchestrator ---\n";

    it("run_verification_cleanup warns due applicants, purges expired ones, and is idempotent", function() use ($db, $userModel) {
        $warnMe = purge_make_rejected_customer($db, $userModel, 'orchwarn');
        purge_backdate($db, $warnMe['id'], 21);

        $purgeMe = purge_make_rejected_customer($db, $userModel, 'orchpurge');
        purge_backdate($db, $purgeMe['id'], 26);

        $notificationModel = new Notification($db);
        $before = $notificationModel->getUnreadCount($warnMe['id']);

        $result = run_verification_cleanup(24, 4);

        assert_equals(null, $result['error'], "Cleanup must not report an error");
        assert_true($result['warned'] >= 1, "At least one reminder must be sent");
        assert_true($result['purged'] >= 1, "At least one account must be purged");

        // Warning delivered in-app.
        assert_true($notificationModel->getUnreadCount($warnMe['id']) > $before, "Warning notification must be created");
        assert_true($userModel->findById($warnMe['id']) !== null, "Warned applicant is not purged yet");
        assert_equals(null, $userModel->findById($purgeMe['id']), "Expired applicant must be purged");

        // Second run: nothing left to warn or purge for our accounts.
        $second = run_verification_cleanup(24, 4);
        assert_equals(0, $second['warned'], "Second run must not re-warn");
        assert_equals(0, $second['purged'], "Second run must not re-purge");
        return true;
    });

    // ----------------------------------------------------------
    // 4. CLI dry-run makes no changes
    // ----------------------------------------------------------
    echo "\n--- 4. CLI script ---\n";

    it("CLI --dry-run lists expired accounts without deleting them", function() use ($db, $userModel) {
        $c = purge_make_rejected_customer($db, $userModel, 'clidry');
        purge_backdate($db, $c['id'], 30);

        $script = dirname(__DIR__) . '/database/tools/purge-rejected-users.php';
        assert_true(is_file($script), "CLI script must exist");

        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --dry-run 2>&1';
        $output = (string)shell_exec($cmd);

        assert_true(strpos($output, '[DRY RUN]') !== false, "Dry-run output must be produced. Got: {$output}");
        assert_true(strpos($output, '#' . $c['id']) !== false, "Dry-run must list our expired account");
        assert_true($userModel->findById($c['id']) !== null, "Dry-run must NOT delete the account");
        assert_true(is_file($c['file']), "Dry-run must NOT delete the ID file");
        return true;
    });
} finally {
    // ----------------------------------------------------------
    // Cleanup
    // ----------------------------------------------------------
    foreach ($createdOrderIds as $orderId) {
        try { $db->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]); } catch (Throwable $e) {}
    }
    foreach ($createdUserIds as $userId) {
        try { $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]); } catch (Throwable $e) {}
    }
    foreach ($createdFiles as $file) {
        if (is_file($file)) { @unlink($file); }
    }
}

// ----------------------------------------------------------
// Summary
// ----------------------------------------------------------
echo "\n====================================================\n";
echo " Test Results: {$passCount} / {$testCount} passed";
if ($failCount > 0) {
    echo " ({$failCount} failed)\n";
    echo "====================================================\n";
    exit(1);
} else {
    echo " (100% success)\n";
    echo "====================================================\n";
    exit(0);
}
