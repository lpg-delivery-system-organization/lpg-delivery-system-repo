<?php
/**
 * Order Receipt (80mm PDF) Automated Test Suite
 * LPG Delivery System v2
 *
 * Covers the official-receipt feature:
 *   - Philippine formatting helpers (peso amounts, PHT timestamps, receipt no.)
 *   - Receipt::normalize() label mapping for every order/payment/refund status
 *   - Receipt::clean() stripping of glyphs the PDF core fonts cannot render
 *   - Receipt::buildProgress() state machine across all fulfillment statuses
 *   - Receipt::buildPdfBytes() emitting a valid, single-page 80mm PDF
 *   - Endpoint authorization (customer owns the order, admin may print any)
 */

// Enable test mode before components load
$GLOBALS['TEST_MODE'] = true;

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Product.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/Receipt.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/middleware.php';

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

function assert_not_empty($actual, string $message = ''): bool {
    if (empty($actual)) {
        throw new Exception($message ?: 'Expected a non-empty value but it was empty.');
    }
    return true;
}

function assert_contains(string $needle, string $haystack, string $message = ''): bool {
    if (strpos($haystack, $needle) === false) {
        throw new Exception(
            ($message ? $message . ': ' : '') .
            "Expected to find '{$needle}' in '" . $haystack . "'"
        );
    }
    return true;
}

// Reset request environment between tests
function reset_receipt_env(): void {
    $_SESSION = [];
    $_POST = [];
    $_GET = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset(
        $_SERVER['HTTP_X_REQUESTED_WITH'],
        $_SERVER['HTTP_X_CSRF_TOKEN'],
        $_SERVER['HTTP_CSRF_TOKEN'],
        $_SERVER['HTTP_ACCEPT'],
        $_SERVER['CONTENT_TYPE']
    );
    unset(
        $GLOBALS['LAST_HTTP_CODE'],
        $GLOBALS['LAST_REDIRECT'],
        $GLOBALS['LAST_RESPONSE'],
        $GLOBALS['LAST_PDF']
    );
}

// Include a receipt endpoint under test mode, swallowing the redirect exception.
function run_receipt_endpoint(string $pagePath): void {
    $GLOBALS['RECEIPT_AUTH_DENIED'] = false;
    ob_start();
    try {
        include $pagePath;
    } catch (AuthException $e) {
        // require_role() throws in test mode instead of exiting; record it so
        // the caller can assert the guard actually fired.
        $GLOBALS['RECEIPT_AUTH_DENIED'] = true;
    }
    ob_end_clean();
}

/**
 * Decompress a PDF's FlateDecode content streams so tests can assert on the
 * text that FPDF embeds (the page content is deflate-compressed by default).
 *
 * @param string $bytes Raw PDF document
 * @return string The concatenated, inflated stream payloads
 */
function pdf_visible_text(string $bytes): string {
    $out = '';
    if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $bytes, $matches)) {
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $out .= ($inflated === false) ? '' : $inflated;
        }
    }
    return $out;
}

/**
 * Render a page into a string under test mode, swallowing the redirect
 * exception that require_role() throws instead of exiting.
 *
 * @param string $pagePath
 * @return string
 */
function render_page_output(string $pagePath): string {
    ob_start();
    try {
        include $pagePath;
    } catch (AuthException $e) {
        // Expected when a role guard rejects the session
    }
    return ob_get_clean();
}

/**
 * Count opening/closing occurrences of an HTML tag.
 *
 * Used to prove the receipt entry points did not unbalance the surrounding
 * Bootstrap grid markup, which PHP's linter cannot catch.
 *
 * @param string $html
 * @param string $tag
 * @return array{0:int,1:int} [openCount, closeCount]
 */
function tag_balance(string $html, string $tag): array {
    preg_match_all('#<' . $tag . '\b#i', $html, $open);
    preg_match_all('#</' . $tag . '\s*>#i', $html, $close);
    return [count($open[0]), count($close[0])];
}

echo "====================================================\n";
echo " LPG Delivery System v2 - Order Receipt (80mm PDF) Test Suite\n";
echo "====================================================\n\n";

$db = Database::connect();
$userModel = new User($db);
$productModel = new Product($db);
$orderModel = new Order($db);

$testCustomer = $userModel->findByEmail('customer@lpg.com');
assert_not_empty($testCustomer, 'Test customer must exist');
$customerId = (int)$testCustomer['id'];

$testAdmin = $userModel->findByEmail('admin@lpg.com');
assert_not_empty($testAdmin, 'Test admin must exist');

$testRider = $userModel->findByEmail('rider@lpg.com');
assert_not_empty($testRider, 'Test rider must exist');

/**
 * Build a synthetic order row. Receipt rendering is pure, so most assertions
 * use fixtures rather than the database to stay deterministic and side-effect
 * free; the endpoint tests below use real rows.
 */
function receipt_fixture(array $overrides = []): array {
    return array_merge([
        'id'                  => 1042,
        'customer_id'         => 5,
        'product_id'          => 3,
        'product_name'        => 'LPG Cylinder 11kg',
        'product_brand'       => 'Petron',
        'product_weight'      => '11kg',
        'quantity'            => 2,
        'unit_price'          => 1650.00,
        'total_amount'        => 3300.00,
        'payment_method'      => 'cod',
        'payment_status'      => 'unpaid',
        'refund_status'       => 'none',
        'payment_reference'   => null,
        'payment_id'          => null,
        'refund_reason'       => null,
        'refund_requested_at' => null,
        'refund_processed_at' => null,
        'cancel_reason'       => null,
        'status'              => 'pending',
        'customer_name'       => 'Juan Dela Cruz',
        'customer_email'      => 'juan@example.ph',
        'contact_phone'       => '09171234567',
        'delivery_address'    => '123 Mabini St, Brgy. San Isidro, Tondo, Manila',
        'notes'               => '',
        'rider_name'          => null,
        'rider_phone'         => null,
        'created_at'          => '2026-09-30 15:45:07',
        'paid_at'             => null,
        'delivered_at'        => null,
    ], $overrides);
}

echo "\n--- 1. Philippine formatting helpers ---\n";

it('app timezone is set to Asia/Manila (PHT)', function () {
    assert_equals('Asia/Manila', date_default_timezone_get(), 'Receipt timestamps must be Philippine time');
    assert_true(defined('APP_TIMEZONE'), 'APP_TIMEZONE constant should be defined');
});

it('format_php_amount renders a core-font-safe peso amount', function () {
    assert_equals('PHP 1,650.00', format_php_amount(1650));
    assert_equals('PHP 0.00', format_php_amount(0));
    assert_equals('PHP 3,300.00', format_php_amount('3300.004'));
});

it('format_php_amount omits the peso glyph that core fonts cannot render', function () {
    $rendered = format_php_amount(1234.5);
    assert_false(strpos($rendered, "\u{20B1}") !== false, 'PDF amounts must not contain U+20B1');
});

it('format_currency still renders the peso glyph for the web UI', function () {
    assert_equals("\u{20B1}1,650.00", format_currency(1650));
});

it('format_ph_datetime uses the long Philippine convention by default', function () {
    assert_equals('September 30, 2026 3:45 PM', format_ph_datetime('2026-09-30 15:45:07'));
    assert_equals('January 2, 2026 12:05 AM', format_ph_datetime('2026-01-02 00:05:00'));
});

it('format_ph_datetime accepts a shorter format for narrow columns', function () {
    assert_equals('Sep 30, 2026 3:45 PM', format_ph_datetime('2026-09-30 15:45:07', 'M j, Y g:i A'));
});

it('format_ph_datetime returns N/A for missing or unparseable input', function () {
    assert_equals('N/A', format_ph_datetime(null));
    assert_equals('N/A', format_ph_datetime(''));
});

it('order_receipt_number zero-pads the order id', function () {
    assert_equals('ORD-0000001042', order_receipt_number(1042));
    assert_equals('ORD-0000000007', order_receipt_number(7));
    assert_equals('ORD-0000000000', order_receipt_number(0));
});

echo "\n--- 2. Receipt::clean() sanitization ---\n";

it('clean() strips the peso glyph, newlines, and control characters', function () {
    $out = Receipt::clean("Total \u{20B1}850.00\nRing\x07 twice");
    assert_false(strpos($out, "\u{20B1}") !== false, 'Peso glyph must be removed');
    assert_false(strpos($out, "\n") !== false, 'Newlines must be removed');
    assert_false(strpos($out, "\x07") !== false, 'Control characters must be removed');
});

it('clean() transliterates curly quotes, dashes, and ellipses to ASCII', function () {
    // The leading U+2019 becomes a straight apostrophe, so the result opens
    // with a quote character.
    assert_equals('\'It\'s "quoted" - really...',
        Receipt::clean("\u{2019}It\u{2019}s \u{201C}quoted\u{201D} \u{2014} really\u{2026}")
    );
});

it('clean() trims surrounding whitespace', function () {
    assert_equals('Petron', Receipt::clean('   Petron   '));
});

echo "\n--- 3. Receipt::normalize() label mapping ---\n";

it('normalize() maps a COD order to the Cash on Delivery labels', function () {
    $d = Receipt::normalize(receipt_fixture());
    assert_equals('cod', $d['payment_method']);
    assert_equals('COD', $d['payment_method_key']);
    assert_equals('Cash on Delivery (COD)', $d['payment_method_label']);
    assert_false($d['is_gcash'], 'COD must not be flagged as GCash');
});

it('normalize() recognises the lowercase gcash enum value', function () {
    $d = Receipt::normalize(receipt_fixture([
        'payment_method' => 'gcash',
        'payment_status' => 'paid',
        'paid_at'        => '2026-09-30 15:47:52',
    ]));
    assert_true($d['is_gcash'], 'lowercase "gcash" must be detected');
    assert_equals('GCASH', $d['payment_method_key']);
    assert_equals('GCash (Digital Payment)', $d['payment_method_label']);
    assert_true($d['is_paid']);
});

it('normalize() is case-insensitive on the payment_method column', function () {
    foreach (['gcash', 'GCash', 'GCASH', 'Gcash'] as $variant) {
        $d = Receipt::normalize(receipt_fixture(['payment_method' => $variant]));
        assert_true($d['is_gcash'], "variant '{$variant}' must be detected as GCash");
        assert_equals('GCASH', $d['payment_method_key']);
    }
});

it('normalize() labels every payment_status enum value', function () {
    $expected = [
        'unpaid' => 'Awaiting Payment',
        'paid'   => 'Paid',
        'failed' => 'Payment Failed',
    ];
    foreach ($expected as $dbValue => $label) {
        $d = Receipt::normalize(receipt_fixture(['payment_status' => $dbValue]));
        assert_equals($label, $d['payment_status_label'], "payment_status={$dbValue}");
    }
});

it('normalize() labels every refund_status enum value', function () {
    $expected = [
        'none'      => '',
        'requested' => 'Refund Requested',
        'refunded'  => 'Refunded',
        'failed'    => 'Refund Failed',
        'rejected'  => 'Refund Declined',
    ];
    foreach ($expected as $dbValue => $label) {
        $d = Receipt::normalize(receipt_fixture(['refund_status' => $dbValue]));
        assert_equals($label, $d['refund_status_label'], "refund_status={$dbValue}");
    }
});

it('normalize() labels every order status enum value', function () {
    $expected = [
        'pending_payment'    => 'Awaiting Payment',
        'pending'            => 'Order Placed',
        'approved'           => 'Approved',
        'ready_for_delivery' => 'Ready for Delivery',
        'picked_up'          => 'Picked Up',
        'out_for_delivery'   => 'Out for Delivery',
        'delivered'          => 'Delivered',
        'cancelled'          => 'Cancelled',
    ];
    foreach ($expected as $dbValue => $label) {
        $d = Receipt::normalize(receipt_fixture(['status' => $dbValue]));
        assert_equals($label, $d['status_label'], "status={$dbValue}");
    }
});

it('normalize() computes the receipt number and the peso total', function () {
    $d = Receipt::normalize(receipt_fixture());
    assert_equals(1042, $d['order_id']);
    assert_equals('ORD-0000001042', $d['receipt_no']);
    assert_equals('PHP 3,300.00', $d['total_amount_text']);
    assert_equals('PHP 1,650.00', $d['unit_price_text']);
    assert_equals('FREE', $d['delivery_fee_label']);
});

it('normalize() renders N/A for an unpaid order with no paid_at', function () {
    $d = Receipt::normalize(receipt_fixture());
    assert_equals('N/A', $d['paid_at']);
    assert_equals('N/A', $d['delivered_at']);
});

it('normalize() formats a paid order timestamp in PHT', function () {
    $d = Receipt::normalize(receipt_fixture([
        'paid_at' => '2026-09-30 15:47:52',
    ]));
    assert_equals('Sep 30, 2026 3:47 PM', $d['paid_at']);
});

it('normalize() joins brand and weight into a product variant', function () {
    $d = Receipt::normalize(receipt_fixture());
    assert_equals('Petron 11kg', $d['product_variant']);
});

it('normalize() omits the variant when brand and weight are both empty', function () {
    $d = Receipt::normalize(receipt_fixture([
        'product_brand'  => '',
        'product_weight' => '',
    ]));
    assert_equals('', $d['product_variant']);
});

it('normalize() flags a cancelled order', function () {
    $d = Receipt::normalize(receipt_fixture([
        'status'        => 'cancelled',
        'cancel_reason' => 'Change of mind',
    ]));
    assert_true($d['is_cancelled']);
    assert_equals('Change of mind', $d['cancel_reason']);
});

it('normalize() sanitizes user-supplied address and notes', function () {
    $d = Receipt::normalize(receipt_fixture([
        'delivery_address' => "123 Mabini St\nBrgy. San Isidro",
        'notes'            => "Ring twice \u{20B1}please",
    ]));
    assert_equals('123 Mabini St Brgy. San Isidro', $d['delivery_address']);
    assert_equals('Ring twice please', $d['notes']);
});

it('normalize() defaults the quantity to at least 1', function () {
    $d = Receipt::normalize(receipt_fixture(['quantity' => 0]));
    assert_equals(1, $d['quantity']);
});

echo "\n--- 4. Receipt::buildProgress() state machine ---\n";

it('buildProgress() marks every step todo for a brand new pending order', function () {
    $rows = Receipt::buildProgress('pending');
    assert_equals(6, count($rows));
    assert_equals(['current', 'todo', 'todo', 'todo', 'todo', 'todo'],
        array_column($rows, 'state'));
});

it('buildProgress() advances the current step as the order progresses', function () {
    // Earlier steps read "done" and the current status reads "current", which
    // mirrors the $idx <= $currentIndex timeline in the customer order views.
    $expected = [
        'approved'           => ['done', 'current', 'todo', 'todo', 'todo', 'todo'],
        'ready_for_delivery' => ['done', 'done', 'current', 'todo', 'todo', 'todo'],
        'picked_up'          => ['done', 'done', 'done', 'current', 'todo', 'todo'],
        'out_for_delivery'   => ['done', 'done', 'done', 'done', 'current', 'todo'],
        'delivered'          => ['done', 'done', 'done', 'done', 'done', 'current'],
    ];
    foreach ($expected as $status => $states) {
        assert_equals($states, array_column(Receipt::buildProgress($status), 'state'), "status={$status}");
    }
});

it('buildProgress() labels the six fulfillment steps', function () {
    $labels = array_column(Receipt::buildProgress('pending'), 'label');
    assert_equals([
        'Order Placed', 'Approved', 'Ready', 'Picked Up', 'Out for Delivery', 'Delivered',
    ], $labels);
});

it('buildProgress() falls back to the first step for a cancelled or unknown status', function () {
    foreach (['cancelled', 'pending_payment', 'not_a_real_status'] as $status) {
        $rows = Receipt::buildProgress($status);
        assert_equals('current', $rows[0]['state'], "status={$status}");
        assert_equals('todo', $rows[5]['state'], "status={$status}");
    }
});

echo "\n--- 5. Receipt::buildPdfBytes() ---\n";

it('buildPdfBytes() emits a valid PDF document', function () {
    $bytes = Receipt::buildPdfBytes(receipt_fixture());
    assert_not_empty($bytes, 'PDF payload must not be empty');
    assert_equals('%PDF-1.3', substr($bytes, 0, 8), 'PDF magic header');
    assert_contains('%%EOF', $bytes, 'PDF trailer');
});

it('buildPdfBytes() always produces a single page, whatever the content length', function () {
    $cases = [
        'typical'    => receipt_fixture(),
        'no notes'   => receipt_fixture(['notes' => '', 'customer_email' => null, 'rider_name' => null]),
        'very long'  => receipt_fixture([
            'delivery_address' => str_repeat('123 Mabini Street, Brgy. San Isidro, Tondo, Manila, ', 8),
            'notes'            => str_repeat('Please call upon arrival and leave with the guard. ', 10),
        ]),
    ];
    foreach ($cases as $name => $fixture) {
        $bytes = Receipt::buildPdfBytes($fixture);
        $pages = (int)(preg_match_all('#/Type\s*/Page[^s]#', $bytes));
        assert_equals(1, $pages, "receipt ('{$name}') must fit on a single page");
    }
});

it('buildPdfBytes() sizes the roll to 80mm paper', function () {
    $bytes = Receipt::buildPdfBytes(receipt_fixture());
    if (!preg_match('#/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]#', $bytes, $m)) {
        throw new Exception('MediaBox not found in PDF output');
    }
    $widthMm = (float)$m[1] / 72 * 25.4;
    $heightMm = (float)$m[2] / 72 * 25.4;
    assert_true(abs($widthMm - 72) < 0.5, "expected ~72mm printable width, got {$widthMm}mm");
    assert_true($heightMm > 100, "roll should be at least 100mm tall, got {$heightMm}mm");
    assert_true($heightMm < 600, "roll should not be absurdly tall, got {$heightMm}mm");
});

it('buildPdfBytes() embeds the receipt number, order number, and peso total', function () {
    $bytes = Receipt::buildPdfBytes(receipt_fixture());

    // The document title is written uncompressed in the Info dictionary.
    assert_contains('Official Receipt ORD-0000001042', $bytes, 'PDF /Title metadata');
    assert_contains('Subject (Order #1042)', $bytes, 'PDF /Subject metadata');

    // Page content is deflate-compressed, so inflate before searching.
    $text = pdf_visible_text($bytes);
    assert_not_empty($text, 'page content stream should inflate');
    assert_contains('ORD-0000001042', $text, 'receipt reference on the page');
    assert_contains('3,300.00', $text, 'grand total');
    assert_contains('1,650.00', $text, 'unit price');
    assert_contains('TOTAL AMOUNT', $text, 'total label');
    assert_contains('Juan Dela Cruz', $text, 'customer name');
    assert_contains('123 Mabini St', $text, 'delivery address');
    assert_contains('Salamat po', $text, 'closing message');
});

it('buildPdfBytes() does not leak the peso glyph into the document', function () {
    $bytes = Receipt::buildPdfBytes(receipt_fixture([
        'notes' => "Change \u{20B1}500 please",
    ]));
    assert_false(strpos($bytes, "\u{20B1}") !== false, 'PDF must not contain the unmappable peso glyph');
    assert_false(strpos(pdf_visible_text($bytes), "\u{20B1}") !== false, 'inflated text must not contain U+20B1');
});

it('buildPdfBytes() survives an order missing every optional field', function () {
    $bytes = Receipt::buildPdfBytes([
        'id'             => 1,
        'quantity'       => 1,
        'unit_price'     => 0,
        'total_amount'   => 0,
        'payment_method' => 'cod',
        'payment_status' => 'unpaid',
        'refund_status'  => 'none',
        'status'         => 'pending',
    ]);
    assert_equals('%PDF-1.3', substr($bytes, 0, 8));
    assert_equals(1, (int)preg_match_all('#/Type\s*/Page[^s]#', $bytes));
});

it('Receipt::filename() derives a safe .pdf download name', function () {
    assert_equals('ORD-0000001042.pdf', Receipt::filename(1042));
});

it('no fixed-width label/value row wraps onto a second line on an 80mm roll', function () {
    require_once __DIR__ . '/../includes/lib/fpdf.php';

    $measure = new FPDF('P', 'mm', [72, 500]);
    $measure->AddPage();
    $measure->SetFont('Helvetica', '', 8.5);

    // 23mm is the narrowest label column that fits "Payment Method" (22.67mm),
    // leaving 39mm for the value. Every field below has a bounded width.
    $valueWidth = Receipt::CONTENT_WIDTH_MM - 23;

    // Guard the column split itself, so a future label change cannot silently
    // push the value column below the widths asserted here.
    assert_true(
        $measure->GetStringWidth('Payment Method') <= 23,
        'the widest label must still fit the 23mm label column'
    );

    $cases = [
        'COD fixture'      => receipt_fixture(),
        'GCash paid'       => receipt_fixture([
            'payment_method'    => 'gcash',
            'payment_status'    => 'paid',
            'paid_at'           => '2026-09-30 15:47:52',
            'payment_reference' => 'cs_9aBcDeFgHiJkLmNoP',
            'payment_id'        => 'pay_XyZ123456789',
        ]),
        'GCash unpaid'     => receipt_fixture([
            'payment_method'    => 'GCASH',
            'payment_status'    => 'pending',
        ]),
        'refund requested' => receipt_fixture([
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
            'refund_status'  => 'requested',
        ]),
        'refund failed'    => receipt_fixture([
            'payment_method' => 'gcash',
            'payment_status' => 'paid',
            'refund_status'  => 'failed',
        ]),
        'delivered'        => receipt_fixture([
            'status'       => 'delivered',
            'delivered_at' => '2026-09-30 18:05:00',
        ]),
    ];

    foreach ($cases as $name => $fixture) {
        $d = Receipt::normalize($fixture);

        // Free-text fields (customer name, refund reason, cancel reason) are
        // intentionally excluded: they wrap by design, and the adaptive page
        // height absorbs the extra lines.
        $rows = [
            'Receipt No.'    => $d['receipt_no'],
            'Order No.'      => '#' . $d['order_id'],
            'Date'           => $d['placed_at'],
            'Payment Method' => $d['payment_method_label'],
            'Payment Status' => $d['payment_status_label'],
            'GCash Ref'      => $d['payment_reference'],
            'PayMongo ID'    => $d['payment_id'],
            'Paid On'        => $d['paid_at'],
            'Balance Due'    => $d['total_amount_text'],
            'Payable On'     => 'Delivery / Cash on Delivery',
            'Refund'         => $d['refund_status_label'],
            'Refunded On'    => $d['refunded_at'],
        ];

        foreach ($rows as $label => $value) {
            if ($value === '' || $value === 'N/A') {
                continue;
            }
            $width = $measure->GetStringWidth($value);
            assert_true(
                $width <= $valueWidth,
                "receipt ('{$name}') row '{$label}' needs " . round($width, 2) .
                "mm but only {$valueWidth}mm is available: '{$value}'"
            );
        }
    }
});

echo "\n--- 6. stream_pdf() response handling ---\n";

it('stream_pdf() records an inline disposition by default in test mode', function () {
    $GLOBALS['LAST_PDF'] = null;
    stream_pdf('%PDF-1.3 test', 'ORD-0000001042.pdf', false);
    assert_not_empty($GLOBALS['LAST_PDF']);
    assert_equals('inline', $GLOBALS['LAST_PDF']['disposition']);
    assert_equals('ORD-0000001042.pdf', $GLOBALS['LAST_PDF']['filename']);
    assert_equals(200, $GLOBALS['LAST_HTTP_CODE']);
});

it('stream_pdf() records an attachment disposition when download is requested', function () {
    $GLOBALS['LAST_PDF'] = null;
    stream_pdf('%PDF-1.3 test', 'ORD-0000001042.pdf', true);
    assert_equals('attachment', $GLOBALS['LAST_PDF']['disposition']);
});

it('stream_pdf() strips characters that could inject response headers', function () {
    $GLOBALS['LAST_PDF'] = null;
    stream_pdf('%PDF-1.3 test', "bad\r\nX-Injected: 1.pdf", false);
    assert_false(strpos($GLOBALS['LAST_PDF']['filename'], "\n") !== false, 'newlines must be stripped');
    assert_false(strpos($GLOBALS['LAST_PDF']['filename'], ':') !== false, 'colons must be stripped');
});

echo "\n--- 7. Endpoint authorization ---\n";

$products = $productModel->getActive();
assert_not_empty($products, 'At least one active product required');
$productId = (int)$products[0]['id'];
if ((int)$products[0]['stock'] < 2) {
    $productModel->updateStock($productId, 10);
}

// A real COD order owned by the test customer.
$ownedOrderId = $orderModel->place([
    'customer_id'        => $customerId,
    'product_id'         => $productId,
    'quantity'           => 1,
    'payment_method'     => 'cod',
    'delivery_address'   => 'Receipt Test St, Quezon City',
    'contact_phone'      => '09171234567',
    'delivery_latitude'  => '14.6000',
    'delivery_longitude' => '121.0500',
    'notes'              => '',
    'status'             => 'pending',
]);

// A second customer must exist so cross-customer receipt access can be tested.
// Reuse the row on re-runs so the suite is idempotent.
$otherCustomer = $userModel->findByEmail('receipt.rival@lpg.test');
if ($otherCustomer) {
    $otherCustomerId = (int)$otherCustomer['id'];
} else {
    $otherCustomerId = $userModel->create([
        'full_name' => 'Receipt Test Rival',
        'email'     => 'receipt.rival@lpg.test',
        'password'  => 'RivalPass!234',
        'role'      => 'customer',
        'phone'     => '09181234567',
        'address'   => 'Rival Test St, Manila',
    ]);
    $otherCustomer = $userModel->findById($otherCustomerId);
}
assert_not_empty($otherCustomer, 'second test customer must exist');

// An order owned by the rival customer, used to prove ownership isolation.
$rivalOrderId = $orderModel->place([
    'customer_id'        => $otherCustomerId,
    'product_id'         => $productId,
    'quantity'           => 1,
    'payment_method'     => 'cod',
    'delivery_address'   => 'Rival Receipt St, Manila',
    'contact_phone'      => '09181234567',
    'delivery_latitude'  => '14.5700',
    'delivery_longitude' => '121.0300',
    'notes'              => '',
    'status'             => 'pending',
]);

it('customer endpoint serves a PDF for an order they own', function () use ($ownedOrderId, $testCustomer) {
    reset_receipt_env();
    login_user($testCustomer);
    $_GET = ['id' => $ownedOrderId];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/customer/receipt.php');

    assert_false($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'owner must not be denied');
    assert_equals(200, $GLOBALS['LAST_HTTP_CODE'] ?? null, 'own order should return 200');
    assert_not_empty($GLOBALS['LAST_PDF'] ?? null, 'a PDF should have been produced');
    assert_equals('%PDF-', substr($GLOBALS['LAST_PDF']['bytes'], 0, 5), 'payload should be a PDF');
    assert_equals('ORD-' . str_pad((string)$ownedOrderId, 10, '0', STR_PAD_LEFT) . '.pdf',
        $GLOBALS['LAST_PDF']['filename']);
});

it('customer endpoint forces an attachment when download=1 is passed', function () use ($ownedOrderId, $testCustomer) {
    reset_receipt_env();
    login_user($testCustomer);
    $_GET = ['id' => $ownedOrderId, 'download' => '1'];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/customer/receipt.php');

    assert_equals('attachment', $GLOBALS['LAST_PDF']['disposition'] ?? null);
});

it('customer endpoint refuses a rival customer\'s order', function () use ($rivalOrderId, $testCustomer) {
    // The session is a valid customer, so the role guard passes and the
    // ownership check is what must refuse this request.
    reset_receipt_env();
    login_user($testCustomer);
    $_GET = ['id' => $rivalOrderId];

    $GLOBALS['LAST_PDF'] = null;
    $GLOBALS['LAST_REDIRECT'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/customer/receipt.php');

    assert_false($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'guard should pass; ownership must be the gate');
    assert_true(empty($GLOBALS['LAST_PDF']), 'no PDF may be leaked for another customer\'s order');
    assert_contains('orders.php', (string)($GLOBALS['LAST_REDIRECT'] ?? ''), 'should redirect to order history');
});

it('customer endpoint refuses a rider session', function () use ($ownedOrderId, $testRider) {
    reset_receipt_env();
    login_user($testRider);
    $_GET = ['id' => $ownedOrderId];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/customer/receipt.php');

    assert_true($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'a rider must not reach the customer receipt');
    assert_true(empty($GLOBALS['LAST_PDF']), 'no PDF may be produced for a refused request');
});

it('customer endpoint refuses an admin session', function () use ($ownedOrderId, $testAdmin) {
    reset_receipt_env();
    login_user($testAdmin);
    $_GET = ['id' => $ownedOrderId];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/customer/receipt.php');

    assert_true($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'an admin must not reach the customer receipt');
    assert_true(empty($GLOBALS['LAST_PDF']), 'no PDF may be produced for a refused request');
});

it('customer endpoint rejects a non-numeric order id', function () use ($testCustomer) {
    reset_receipt_env();
    login_user($testCustomer);
    $_GET = ['id' => '0'];
    $GLOBALS['LAST_REDIRECT'] = null;

    run_receipt_endpoint(__DIR__ . '/../pages/customer/receipt.php');

    assert_contains('orders.php', (string)($GLOBALS['LAST_REDIRECT'] ?? ''), 'should redirect to order history');
});

it('admin endpoint serves a PDF for any order', function () use ($ownedOrderId, $testAdmin) {
    reset_receipt_env();
    login_user($testAdmin);
    $_GET = ['id' => $ownedOrderId];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/admin/receipt.php');

    assert_false($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'admin must not be denied');
    assert_equals(200, $GLOBALS['LAST_HTTP_CODE'] ?? null, 'admin should return 200');
    assert_equals('%PDF-', substr($GLOBALS['LAST_PDF']['bytes'] ?? '', 0, 5), 'payload should be a PDF');
});

it('admin endpoint serves a rival customer\'s order (no ownership filter)', function () use ($rivalOrderId, $testAdmin) {
    reset_receipt_env();
    login_user($testAdmin);
    $_GET = ['id' => $rivalOrderId, 'download' => '1'];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/admin/receipt.php');

    assert_equals(200, $GLOBALS['LAST_HTTP_CODE'] ?? null);
    assert_equals('attachment', $GLOBALS['LAST_PDF']['disposition'] ?? null);
});

it('admin endpoint returns 400 for a non-numeric order id', function () use ($testAdmin) {
    reset_receipt_env();
    login_user($testAdmin);
    $_GET = ['id' => 0];

    run_receipt_endpoint(__DIR__ . '/../pages/admin/receipt.php');

    assert_equals(400, $GLOBALS['LAST_HTTP_CODE'] ?? null);
});

it('admin endpoint returns 404 for an order that does not exist', function () use ($testAdmin) {
    reset_receipt_env();
    login_user($testAdmin);
    $_GET = ['id' => 99999999];

    run_receipt_endpoint(__DIR__ . '/../pages/admin/receipt.php');

    assert_equals(404, $GLOBALS['LAST_HTTP_CODE'] ?? null);
});

it('admin endpoint refuses a customer session', function () use ($ownedOrderId, $testCustomer) {
    reset_receipt_env();
    login_user($testCustomer);
    $_GET = ['id' => $ownedOrderId];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/admin/receipt.php');

    assert_true($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'a customer must not reach the admin receipt');
    assert_true(empty($GLOBALS['LAST_PDF']), 'no PDF may be produced for a refused request');
});

it('admin endpoint refuses a rider session', function () use ($ownedOrderId, $testRider) {
    reset_receipt_env();
    login_user($testRider);
    $_GET = ['id' => $ownedOrderId];

    $GLOBALS['LAST_PDF'] = null;
    run_receipt_endpoint(__DIR__ . '/../pages/admin/receipt.php');

    assert_true($GLOBALS['RECEIPT_AUTH_DENIED'] ?? false, 'a rider must not reach the admin receipt');
    assert_true(empty($GLOBALS['LAST_PDF']), 'no PDF may be produced for a refused request');
});

echo "\n--- 8. Real order rows render end to end ---\n";

it('a real COD order produces a receipt containing its delivery address', function () use ($orderModel, $ownedOrderId) {
    $order = $orderModel->findById($ownedOrderId);
    assert_not_empty($order, 'order should be found');
    $bytes = Receipt::buildPdfBytes($order);
    assert_equals('%PDF-1.3', substr($bytes, 0, 8));
    assert_contains('Receipt Test St', Receipt::normalize($order)['delivery_address']);
});

it('a real GCash order is detected as GCash (guards the enum casing)', function () use ($orderModel, $customerId, $productId) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'gcash',
        'delivery_address'   => 'GCash Receipt Test St, Makati',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5500',
        'delivery_longitude' => '121.0200',
        'notes'              => '',
        'status'             => 'pending_payment',
    ]);
    $order = $orderModel->findById($orderId);
    $d = Receipt::normalize($order);

    assert_equals('gcash', $d['payment_method'], 'the DB stores lowercase gcash');
    assert_true($d['is_gcash'], 'the receipt must recognise the lowercase enum value');
    assert_equals('Awaiting Payment', $d['payment_status_label']);
    assert_equals('Awaiting Payment', $d['status_label'], 'pending_payment order label');
});

it('a real paid GCash order shows the payment reference and paid timestamp', function () use ($orderModel, $customerId, $productId) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'gcash',
        'delivery_address'   => 'Paid GCash Receipt Test St, Pasig',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending_payment',
    ]);
    $orderModel->confirmPayment($orderId, 'pay_receipt_test');
    $orderModel->setPaymentReference($orderId, 'cs_receipt_test');

    $d = Receipt::normalize($orderModel->findById($orderId));
    assert_true($d['is_paid'], 'order should be paid');
    assert_equals('Paid', $d['payment_status_label']);
    assert_equals('cs_receipt_test', $d['payment_reference']);
    assert_true($d['paid_at'] !== 'N/A', 'paid_at should be populated');

    $bytes = Receipt::buildPdfBytes($orderModel->findById($orderId));
    assert_equals('%PDF-1.3', substr($bytes, 0, 8));
});

// =========================================================================
// Group 9: Entry-point markup integrity
// =========================================================================
echo "\n--- 9. Receipt entry-point markup ---\n";

/**
 * Assert that the receipt controls were added without unbalancing the
 * surrounding Bootstrap grid, which PHP's linter cannot catch.
 */
function assert_balanced_markup(string $html, string $label): void {
    foreach (['div', 'table', 'tbody', 'thead', 'span', 'a'] as $tag) {
        [$open, $close] = tag_balance($html, $tag);
        assert_equals($open, $close, "{$label}: unbalanced <{$tag}> tags");
    }
}

it('customer order-detail.php keeps its markup balanced for a COD order', function () use ($orderModel, $customerId, $productId, $testCustomer) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'cod',
        'delivery_address'   => 'Receipt Markup COD Test St, Manila',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending',
    ]);

    reset_receipt_env();
    login_user($testCustomer);
    $_GET['id'] = $orderId;

    $html = render_page_output(__DIR__ . '/../pages/customer/order-detail.php');

    assert_balanced_markup($html, 'customer order-detail (COD)');
    assert_contains('customer/receipt.php?id=' . $orderId, $html, 'view/download links should render');
});

it('customer order-detail.php keeps its markup balanced for a GCash order', function () use ($orderModel, $customerId, $productId, $testCustomer) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'gcash',
        'delivery_address'   => 'Receipt Markup GCash Test St, Manila',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending_payment',
    ]);

    reset_receipt_env();
    login_user($testCustomer);
    $_GET['id'] = $orderId;

    $html = render_page_output(__DIR__ . '/../pages/customer/order-detail.php');

    assert_balanced_markup($html, 'customer order-detail (GCash)');
    assert_contains('customer/receipt.php?id=' . $orderId, $html);
    // The receipt control must render for both payment methods, not just GCash.
    assert_contains('Official Receipt', $html);
});

it('customer order-detail.php shows the success banner and receipt only with ?placed=1', function () use ($orderModel, $customerId, $productId, $testCustomer) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'cod',
        'delivery_address'   => 'Receipt Banner Test St, Manila',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending',
    ]);

    reset_receipt_env();
    login_user($testCustomer);
    $_GET['id'] = $orderId;
    $withoutFlag = render_page_output(__DIR__ . '/../pages/customer/order-detail.php');
    assert_true(
        strpos($withoutFlag, 'placed successfully') === false,
        'the success banner must not show for a plain order-detail visit'
    );

    reset_receipt_env();
    login_user($testCustomer);
    $_GET['id'] = $orderId;
    $_GET['placed'] = '1';
    $withFlag = render_page_output(__DIR__ . '/../pages/customer/order-detail.php');
    assert_contains('placed successfully', $withFlag, '?placed=1 should reveal the banner');
    assert_contains('View Receipt', $withFlag);
    assert_contains('Download PDF', $withFlag);
    assert_balanced_markup($withFlag, 'customer order-detail (placed banner)');
});

it('customer orders.php lists a receipt download for every order row', function () use ($orderModel, $customerId, $productId, $testCustomer) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'cod',
        'delivery_address'   => 'Receipt History Test St, Manila',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending',
    ]);

    reset_receipt_env();
    login_user($testCustomer);

    $html = render_page_output(__DIR__ . '/../pages/customer/orders.php');

    // url() is echoed raw throughout this codebase, so the separator stays "&".
    assert_contains('customer/receipt.php?id=' . $orderId . '&download=1', $html);
    assert_balanced_markup($html, 'customer orders list');
});

it('admin order-detail.php keeps its markup balanced and offers a receipt', function () use ($orderModel, $customerId, $productId, $testAdmin) {
    $orderId = $orderModel->place([
        'customer_id'        => $customerId,
        'product_id'         => $productId,
        'quantity'           => 1,
        'payment_method'     => 'gcash',
        'delivery_address'   => 'Receipt Admin Test St, Manila',
        'contact_phone'      => '09171234567',
        'delivery_latitude'  => '14.5600',
        'delivery_longitude' => '121.0800',
        'notes'              => '',
        'status'             => 'pending_payment',
    ]);

    reset_receipt_env();
    login_user($testAdmin);
    $_GET['id'] = $orderId;

    $html = render_page_output(__DIR__ . '/../pages/admin/order-detail.php');

    assert_balanced_markup($html, 'admin order-detail');
    assert_contains('admin/receipt.php?id=' . $orderId, $html);
    assert_contains('Download Receipt PDF', $html);
});

echo "\n====================================================\n";
echo " Test Results: {$passCount} / {$testCount} passed";
if ($failCount > 0) {
    echo " ({$failCount} failed)";
}
echo "\n====================================================\n";

exit($failCount > 0 ? 1 : 0);
