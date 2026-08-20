<?php
/**
 * Master Test Runner - LPG Delivery System v2
 *
 * Runs all automated test suites and produces an aggregated summary report:
 * 1. test_db.php              - Database schema, constraints, PDO singleton, seed data
 * 2. test_models.php          - User, Product, Order, Mailer models & pessimistic concurrency
 * 3. test_security.php        - CSRF tokens, rate limiter, RBAC guards, file uploads, XSS
 * 4. test_templates.php       - Headers, dynamic sidebars, footers, alerts, cards, asset assets
 * 5. test_auth_pages.php      - Login, register, password reset lifecycle, logout
 * 6. test_customer_portal.php - Product catalog, transactional checkout, order tracking, profile
 * 7. test_admin_portal.php    - Admin analytics, order approval/assignment, inventory, user verify
 * 8. test_rider_portal.php    - Delivery workflows, concurrency-safe claiming, status transitions
 * 9. test_api.php             - AJAX REST APIs (/api/orders.php, /api/products.php, /api/users.php)
 *
 * Usage:
 *   php tests/run_all_tests.php              # Standard run with aggregated table
 *   php tests/run_all_tests.php --verbose    # Output complete test logs
 *   php tests/run_all_tests.php --filter=api # Run only matching suites
 *   php tests/run_all_tests.php --no-color   # Disable ANSI colors
 */

// Environment & CLI Argument Processing
$options = getopt('vhq', ['verbose', 'help', 'quiet', 'no-color', 'filter:']);
$isVerbose = isset($options['v']) || isset($options['verbose']);
$isQuiet = isset($options['q']) || isset($options['quiet']);
$isHelp = isset($options['h']) || isset($options['help']);
$noColor = isset($options['no-color']) || (DIRECTORY_SEPARATOR === '\\' && !getenv('ANSICON') && !getenv('WT_SESSION'));
$filter = $options['filter'] ?? null;

if ($isHelp) {
    echo <<<HELP
LPG Delivery System v2 - Master Test Runner

Usage:
  php run_all_tests.php [options]

Options:
  -v, --verbose        Show full output of all executed test suites
  -q, --quiet          Suppress individual suite status lines, display only summary table
  --filter=<string>    Execute only test suites matching the specified name or filename
  --no-color           Disable ANSI color codes in console output
  -h, --help           Show this help information

HELP;
    exit(0);
}

// ANSI Color Helpers
function color(string $text, string $code, bool $noColor): string {
    if ($noColor) return $text;
    $codes = [
        'reset'        => "\033[0m",
        'bold'         => "\033[1m",
        'dim'          => "\033[2m",
        'green'        => "\033[32m",
        'bold_green'   => "\033[1;32m",
        'red'          => "\033[31m",
        'bold_red'     => "\033[1;31m",
        'yellow'       => "\033[33m",
        'bold_yellow'  => "\033[1;33m",
        'cyan'         => "\033[36m",
        'bold_cyan'    => "\033[1;36m",
        'white'        => "\033[37m",
        'bold_white'   => "\033[1;37m",
        'bg_green'     => "\033[42;30m",
        'bg_red'       => "\033[41;37m",
    ];
    return ($codes[$code] ?? '') . $text . $codes['reset'];
}

// Test Suite Catalog
$testSuites = [
    [
        'id'          => 'db',
        'name'        => 'Database & Config',
        'file'        => 'test_db.php',
        'description' => 'Database schema, constraints, PDO singleton & seed validation'
    ],
    [
        'id'          => 'models',
        'name'        => 'Core Models & Concurrency',
        'file'        => 'test_models.php',
        'description' => 'User, Product, Order, Mailer models & pessimistic row locking'
    ],
    [
        'id'          => 'security',
        'name'        => 'Security, Auth & Middleware',
        'file'        => 'test_security.php',
        'description' => 'CSRF lifecycle, rate limiting, RBAC guards, file upload & XSS'
    ],
    [
        'id'          => 'templates',
        'name'        => 'Templates & Layout Components',
        'file'        => 'test_templates.php',
        'description' => 'Header, dynamic sidebar, footer, alert/modal/card components'
    ],
    [
        'id'          => 'auth_pages',
        'name'        => 'Authentication Pages & Flows',
        'file'        => 'test_auth_pages.php',
        'description' => 'Login, register, forgot/reset password & logout lifecycle'
    ],
    [
        'id'          => 'customer_portal',
        'name'        => 'Customer Portal Workflows',
        'file'        => 'test_customer_portal.php',
        'description' => 'Product shop, transactional checkout, order history & profile'
    ],
    [
        'id'          => 'admin_portal',
        'name'        => 'Admin Management Portal',
        'file'        => 'test_admin_portal.php',
        'description' => 'Analytics, order approval/assignment, inventory & ID verification'
    ],
    [
        'id'          => 'rider_portal',
        'name'        => 'Rider Delivery Portal',
        'file'        => 'test_rider_portal.php',
        'description' => 'Available orders, race-safe claiming & sequential status delivery'
    ],
    [
        'id'          => 'api',
        'name'        => 'REST API Endpoints',
        'file'        => 'test_api.php',
        'description' => 'AJAX endpoints for orders, products, user verification & auth'
    ],
];

// Apply Filter if requested
if ($filter !== null && $filter !== '') {
    $filterLower = strtolower($filter);
    $testSuites = array_values(array_filter($testSuites, function ($suite) use ($filterLower) {
        return strpos(strtolower($suite['id']), $filterLower) !== false
            || strpos(strtolower($suite['name']), $filterLower) !== false
            || strpos(strtolower($suite['file']), $filterLower) !== false;
    }));

    if (empty($testSuites)) {
        echo color("Error: No test suites matched filter '{$filter}'\n", 'bold_red', $noColor);
        exit(1);
    }
}

// Locate PHP Binary and Test Directory
$phpBinary = PHP_BINARY ?: 'php';
$testsDirectory = __DIR__;

// Header Banner
$totalSuitesCount = count($testSuites);
echo "\n" . color("================================================================================", 'bold_cyan', $noColor) . "\n";
echo color("  LPG DELIVERY SYSTEM v2 - MASTER AUTOMATED TEST RUNNER", 'bold_white', $noColor) . "\n";
echo color("  PHP Version: " . PHP_VERSION . " | Total Suites: " . $totalSuitesCount, 'dim', $noColor) . "\n";
echo color("================================================================================", 'bold_cyan', $noColor) . "\n\n";

$results = [];
$suiteIndex = 0;
$masterStartTime = microtime(true);

$grandTotalTests = 0;
$grandTotalPassed = 0;
$grandTotalFailed = 0;
$allSuitesPassed = true;

foreach ($testSuites as $suite) {
    $suiteIndex++;
    $filePath = $testsDirectory . DIRECTORY_SEPARATOR . $suite['file'];
    
    if (!file_exists($filePath)) {
        $results[] = [
            'suite'    => $suite,
            'status'   => 'MISSING',
            'tests'    => 0,
            'passed'   => 0,
            'failed'   => 1,
            'duration' => 0.0,
            'output'   => "Test file {$suite['file']} not found."
        ];
        $allSuitesPassed = false;
        $grandTotalFailed++;
        continue;
    }

    if (!$isQuiet) {
        printf(
            "[%d/%d] Running %-32s (%s) ... ",
            $suiteIndex,
            $totalSuitesCount,
            color($suite['name'], 'bold', $noColor),
            color($suite['file'], 'dim', $noColor)
        );
        flush();
    }

    // Execute test suite in isolated process
    $suiteStartTime = microtime(true);
    $cmd = escapeshellcmd($phpBinary) . ' ' . escapeshellarg($filePath);
    
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $process = proc_open($cmd, $descriptors, $pipes, dirname($filePath));
    $stdout = '';
    $stderr = '';
    $exitCode = -1;

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
    } else {
        $stdout = shell_exec($cmd . ' 2>&1');
        $exitCode = 1;
    }
    
    $suiteDuration = microtime(true) - $suiteStartTime;
    $combinedOutput = trim($stdout . ($stderr ? "\nSTDERR:\n" . $stderr : ''));

    // Parse test counts from output
    $passed = 0;
    $failed = 0;
    $total = 0;

    // Match pattern 1: "Test Results: 17 / 17 passed" or "Test Results: 15 / 17 passed (2 failed)"
    if (preg_match('/Test Results:\s*(\d+)\s*\/\s*(\d+)\s*passed/i', $combinedOutput, $matches)) {
        $passed = (int)$matches[1];
        $total = (int)$matches[2];
        $failed = $total - $passed;
    }
    // Match pattern 2: "Test Summary: 31 Passed, 0 Failed (Total: 31)"
    elseif (preg_match('/Test Summary:\s*(\d+)\s*Passed,\s*(\d+)\s*Failed\s*\(Total:\s*(\d+)\)/i', $combinedOutput, $matches)) {
        $passed = (int)$matches[1];
        $failed = (int)$matches[2];
        $total = (int)$matches[3];
    } else {
        // Fallback: count checkmarks and crossmarks in output
        $passed = substr_count($combinedOutput, '✓');
        $failed = substr_count($combinedOutput, '✗');
        $total = $passed + $failed;
    }

    $isPassed = ($exitCode === 0 && $failed === 0 && $total > 0 && $passed === $total);

    if ($isPassed) {
        $statusStr = color('PASS', 'bold_green', $noColor);
        if (!$isQuiet) {
            echo "{$statusStr} " . color(sprintf("(%d/%d tests, %.2fs)", $passed, $total, $suiteDuration), 'dim', $noColor) . "\n";
        }
    } else {
        $allSuitesPassed = false;
        $statusStr = color('FAIL', 'bold_red', $noColor);
        if (!$isQuiet) {
            echo "{$statusStr} " . color(sprintf("(%d/%d tests, %d failed, exit code %d, %.2fs)", $passed, $total, $failed, $exitCode, $suiteDuration), 'bold_red', $noColor) . "\n";
        }
    }

    if ($isVerbose || !$isPassed) {
        echo "\n" . color("--- [Output for {$suite['file']}] ---", 'bold_yellow', $noColor) . "\n";
        echo $combinedOutput . "\n";
        echo color("--------------------------------------------------------------------------------", 'dim', $noColor) . "\n\n";
    }

    $results[] = [
        'suite'    => $suite,
        'status'   => $isPassed ? 'PASS' : 'FAIL',
        'tests'    => $total,
        'passed'   => $passed,
        'failed'   => $failed,
        'duration' => $suiteDuration,
        'output'   => $combinedOutput
    ];

    $grandTotalTests += $total;
    $grandTotalPassed += $passed;
    $grandTotalFailed += $failed;
}

$masterTotalDuration = microtime(true) - $masterStartTime;

// Aggregated Summary Table
echo "\n" . color("================================================================================", 'bold_cyan', $noColor) . "\n";
echo color("  AGGREGATED TEST EXECUTION SUMMARY TABLE", 'bold_white', $noColor) . "\n";
echo color("================================================================================", 'bold_cyan', $noColor) . "\n";

$headerFmt = "| %-3s | %-30s | %-24s | %-5s | %-4s | %-4s | %-6s | %-6s |\n";
$sepLine   = "+-----+--------------------------------+--------------------------+-------+------+------+--------+--------+\n";

echo $sepLine;
printf($headerFmt, '#', 'Suite Name', 'File', 'Tests', 'Pass', 'Fail', 'Time', 'Status');
echo $sepLine;

foreach ($results as $idx => $r) {
    $idxNum = $idx + 1;
    $suiteName = mb_strimwidth($r['suite']['name'], 0, 30, '..');
    $fileName = mb_strimwidth($r['suite']['file'], 0, 24, '..');
    $statusDisplay = ($r['status'] === 'PASS')
        ? color('PASS', 'bold_green', $noColor)
        : color('FAIL', 'bold_red', $noColor);
    
    // Note: color escape sequences add bytes, so we format string length carefully
    if ($noColor) {
        printf(
            "| %-3d | %-30s | %-24s | %-5d | %-4d | %-4d | %-5.2fs | %-6s |\n",
            $idxNum,
            $suiteName,
            $fileName,
            $r['tests'],
            $r['passed'],
            $r['failed'],
            $r['duration'],
            $r['status']
        );
    } else {
        printf(
            "| %-3d | %-30s | %-24s | %-5d | %-4d | %-4d | %-5.2fs | %s      |\n",
            $idxNum,
            $suiteName,
            $fileName,
            $r['tests'],
            $r['passed'],
            $r['failed'],
            $r['duration'],
            $statusDisplay
        );
    }
}

echo $sepLine;

// Summary Row
$totalStatusDisplay = $allSuitesPassed ? color('PASS', 'bold_green', $noColor) : color('FAIL', 'bold_red', $noColor);
if ($noColor) {
    printf(
        "| %-60s | %-5d | %-4d | %-4d | %-5.2fs | %-6s |\n",
        "TOTAL (All " . count($results) . " Suites)",
        $grandTotalTests,
        $grandTotalPassed,
        $grandTotalFailed,
        $masterTotalDuration,
        $allSuitesPassed ? 'PASS' : 'FAIL'
    );
} else {
    printf(
        "| %-71s | %-5d | %-4d | %-4d | %-5.2fs | %s      |\n",
        color("TOTAL (All " . count($results) . " Suites)", 'bold_white', $noColor),
        $grandTotalTests,
        $grandTotalPassed,
        $grandTotalFailed,
        $masterTotalDuration,
        $totalStatusDisplay
    );
}
echo $sepLine . "\n";

// Final Verdict
if ($allSuitesPassed && $grandTotalFailed === 0) {
    echo color("================================================================================", 'bold_green', $noColor) . "\n";
    echo color("  ✓ 100% TEST VERIFICATION PASSED: {$grandTotalPassed}/{$grandTotalTests} tests passed across " . count($results) . " suites in " . sprintf('%.2f', $masterTotalDuration) . "s", 'bold_green', $noColor) . "\n";
    echo color("================================================================================", 'bold_green', $noColor) . "\n\n";
    exit(0);
} else {
    echo color("================================================================================", 'bold_red', $noColor) . "\n";
    echo color("  ✗ TEST VERIFICATION FAILED: {$grandTotalFailed} failed tests detected out of {$grandTotalTests} total tests.", 'bold_red', $noColor) . "\n";
    echo color("================================================================================", 'bold_red', $noColor) . "\n\n";
    exit(1);
}
