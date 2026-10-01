<?php
/**
 * Receipt Model Class
 * LPG Delivery System v2
 *
 * Builds Philippine-format 80mm thermal receipts as PDF documents.
 *
 * Receipts are generated on demand from a single `orders` row (the schema has
 * no order_items/payments tables — one order is one product plus a quantity),
 * so nothing is persisted to the database.
 *
 * The PDF core font set is cp1252/WinAnsi, which has no glyph for the peso
 * sign (U+20B1). Amounts are therefore rendered with the "PHP" prefix via
 * format_php_amount() instead of the "₱" glyph used on screen.
 */

require_once __DIR__ . '/../includes/helpers.php';

class Receipt {
    /** Roll width in millimetres (standard 80mm thermal paper). */
    public const PAPER_WIDTH_MM = 80;

    /** Page width in millimetres, used to derive the dynamic page height. */
    public const PAGE_WIDTH_MM = 72;

    /** Left/right/top margin in millimetres. */
    public const MARGIN_MM = 5;

    /** Printable column width in millimetres. */
    public const CONTENT_WIDTH_MM = self::PAGE_WIDTH_MM - (self::MARGIN_MM * 2);

    /**
     * Sheet height used for the measuring pass, sized far beyond any realistic
     * receipt so the content can never wrap onto a second page while measuring.
     */
    private const MEASURE_HEIGHT_MM = 2000.0;

    /** Blank millimetres reserved below the footer before the roll is cut. */
    private const BOTTOM_PADDING_MM = 6.0;

    /**
     * Fulfillment progress steps, in order. Mirrors the timeline rendered in
     * pages/customer/order-detail.php and templates/components/order-card.php.
     */
    public const PROGRESS_STEPS = [
        'pending'            => 'Order Placed',
        'approved'           => 'Approved',
        'ready_for_delivery' => 'Ready',
        'picked_up'          => 'Picked Up',
        'out_for_delivery'   => 'Out for Delivery',
        'delivered'          => 'Delivered',
    ];

    /**
     * Human-readable labels for the `status` column.
     */
    public const STATUS_LABELS = [
        'pending_payment'    => 'Awaiting Payment',
        'pending'            => 'Order Placed',
        'approved'           => 'Approved',
        'ready_for_delivery' => 'Ready for Delivery',
        'picked_up'          => 'Picked Up',
        'out_for_delivery'   => 'Out for Delivery',
        'delivered'          => 'Delivered',
        'cancelled'          => 'Cancelled',
    ];

    /**
     * Human-readable labels for the `payment_status` column.
     */
    public const PAYMENT_STATUS_LABELS = [
        'unpaid' => 'Awaiting Payment',
        'paid'   => 'Paid',
        'failed' => 'Payment Failed',
    ];

    /**
     * Human-readable labels for the `refund_status` column.
     */
    public const REFUND_STATUS_LABELS = [
        'none'      => '',
        'requested' => 'Refund Requested',
        'refunded'  => 'Refunded',
        'failed'    => 'Refund Failed',
        'rejected'  => 'Refund Declined',
    ];

    /**
     * Human-readable labels for the `payment_method` column.
     */
    public const PAYMENT_METHOD_LABELS = [
        'cod'   => 'Cash on Delivery (COD)',
        'gcash' => 'GCash (Digital Payment)',
    ];

    /**
     * Vendor/business identity printed on the receipt header.
     *
     * @var array<string, string>
     */
    public static array $merchant = [
        'name'     => 'LPG Delivery System',
        'tagline'  => 'LPG Retail & Home Delivery',
        'address'  => 'Manila, Philippines',
        'contact'  => 'Support via the LPG Delivery System website',
    ];

    /**
     * Normalize a raw order row into receipt-ready display data.
     *
     * Every user-facing string is derived here so the PDF layout code stays
     * purely presentational, and so the same values can be asserted in tests.
     *
     * @param array $order A row from Order::findById()
     * @return array
     */
    public static function normalize(array $order): array {
        $orderId = (int)($order['id'] ?? 0);

        $status = strtolower((string)($order['status'] ?? 'pending'));
        $paymentMethod = strtolower((string)($order['payment_method'] ?? 'cod'));
        $paymentStatus = strtolower((string)($order['payment_status'] ?? 'unpaid'));
        $refundStatus = strtolower((string)($order['refund_status'] ?? 'none'));

        $quantity = max(1, (int)($order['quantity'] ?? 1));
        $unitPrice = (float)($order['unit_price'] ?? 0);
        $totalAmount = (float)($order['total_amount'] ?? 0);

        $productName = self::clean((string)($order['product_name'] ?? 'LPG Cylinder'));
        $productBrand = self::clean((string)($order['product_brand'] ?? ''));
        $productWeight = self::clean((string)($order['product_weight'] ?? ''));

        return [
            'order_id'            => $orderId,
            'receipt_no'          => order_receipt_number($orderId),

            'customer_name'       => self::clean((string)($order['customer_name'] ?? 'Walk-in Customer')),
            'customer_email'      => self::clean((string)($order['customer_email'] ?? '')),
            'customer_phone'      => self::clean((string)($order['contact_phone'] ?? '')),

            'product_name'        => $productName,
            'product_brand'       => $productBrand,
            'product_weight'      => $productWeight,
            'product_variant'     => trim(implode(' ', array_filter([$productBrand, $productWeight]))),

            'quantity'            => $quantity,
            'unit_price'          => $unitPrice,
            'total_amount'        => $totalAmount,
            'delivery_fee'        => 0.0,
            'delivery_fee_label'  => 'FREE',

            'unit_price_text'     => format_php_amount($unitPrice),
            'total_amount_text'   => format_php_amount($totalAmount),
            'subtotal_text'       => format_php_amount($totalAmount),

            'status'              => $status,
            'status_label'        => self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            'is_cancelled'        => ($status === 'cancelled'),
            'cancel_reason'       => self::clean((string)($order['cancel_reason'] ?? '')),

            'payment_method'      => $paymentMethod,
            'payment_method_key'  => strtoupper($paymentMethod) === 'GCASH' ? 'GCASH' : 'COD',
            'payment_method_label'=> self::PAYMENT_METHOD_LABELS[$paymentMethod]
                                        ?? ucfirst(str_replace('_', ' ', $paymentMethod)),
            'is_gcash'            => ($paymentMethod === 'gcash'),

            'payment_status'      => $paymentStatus,
            'payment_status_label'=> self::PAYMENT_STATUS_LABELS[$paymentStatus] ?? ucfirst($paymentStatus),
            'is_paid'             => ($paymentStatus === 'paid'),

            'refund_status'       => $refundStatus,
            'refund_status_label' => self::REFUND_STATUS_LABELS[$refundStatus] ?? '',
            'refund_reason'       => self::clean((string)($order['refund_reason'] ?? '')),

            'payment_reference'   => self::clean((string)($order['payment_reference'] ?? '')),
            'payment_id'          => self::clean((string)($order['payment_id'] ?? '')),

            // The abbreviated month keeps these on a single line inside the
            // 40mm label/value columns of an 80mm roll.
            'placed_at'           => format_ph_datetime($order['created_at'] ?? null, 'M j, Y g:i A'),
            'paid_at'             => format_ph_datetime($order['paid_at'] ?? null, 'M j, Y g:i A'),
            'refunded_at'         => format_ph_datetime($order['refund_processed_at'] ?? null, 'M j, Y g:i A'),
            'delivered_at'        => format_ph_datetime($order['delivered_at'] ?? null, 'M j, Y g:i A'),

            'delivery_address'    => self::clean((string)($order['delivery_address'] ?? '')),
            'notes'               => self::clean((string)($order['notes'] ?? '')),
            'rider_name'          => self::clean((string)($order['rider_name'] ?? '')),
            'rider_phone'         => self::clean((string)($order['rider_phone'] ?? '')),

            'progress'            => self::buildProgress($status),
        ];
    }

    /**
     * Compute the fulfillment progress rows for an order status.
     *
     * @param string $status
     * @return array<int, array{label: string, state: string}>
     */
    public static function buildProgress(string $status): array {
        $steps = array_keys(self::PROGRESS_STEPS);
        $currentIndex = array_search($status, $steps, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        $rows = [];
        foreach ($steps as $index => $stepKey) {
            $rows[] = [
                'label' => self::PROGRESS_STEPS[$stepKey],
                'state' => ($index < $currentIndex) ? 'done' : (($index === $currentIndex) ? 'current' : 'todo'),
            ];
        }
        return $rows;
    }

    /**
     * Render a normalized receipt as PDF bytes.
     *
     * Returns the raw document rather than streaming it so callers can decide
     * between inline preview and file download, and so the test harness can
     * assert on the bytes without emitting HTTP headers.
     *
     * @param array $order A row from Order::findById()
     * @return string The raw PDF document
     */
    public static function buildPdfBytes(array $order): string {
        $data = self::normalize($order);

        require_once __DIR__ . '/../includes/lib/fpdf.php';

        // A thermal roll has no fixed page length, so the document is drawn
        // twice: once against an unbounded sheet to discover how tall the
        // content actually is, then again on a sheet sized to fit it exactly.
        // This keeps every receipt on a single page regardless of how long the
        // delivery address or special instructions are.
        $measure = self::draw($data, self::MEASURE_HEIGHT_MM);
        $contentHeight = $measure->GetY();

        $pdf = self::draw($data, $contentHeight + self::BOTTOM_PADDING_MM);

        // 'S' returns the document as a string without touching headers or exiting.
        return $pdf->Output('S');
    }

    /**
     * Draw the full receipt onto a new single-page document.
     *
     * @param array $data Output of self::normalize()
     * @param float $pageHeightMm Height of the roll in millimetres
     * @return FPDF
     */
    private static function draw(array $data, float $pageHeightMm): FPDF {
        // Small unit = millimetres, portrait, custom page height.
        $pdf = new FPDF('P', 'mm', [self::PAGE_WIDTH_MM, $pageHeightMm]);
        $pdf->SetCreator('LPG Delivery System');
        $pdf->SetAuthor(self::$merchant['name']);
        $pdf->SetTitle('Official Receipt ' . $data['receipt_no']);
        $pdf->SetSubject('Order #' . $data['order_id']);

        $pdf->SetMargins(self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM);

        // Auto page-break is off because the page is already sized to the
        // content; this keeps the trailing footer on the same sheet.
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        self::drawHeader($pdf, $data);
        self::drawOrderMeta($pdf, $data);
        self::drawItems($pdf, $data);
        self::drawTotals($pdf, $data);
        self::drawPayment($pdf, $data);
        self::drawDelivery($pdf, $data);
        self::drawProgress($pdf, $data);
        self::drawFooter($pdf, $data);

        return $pdf;
    }

    /**
     * Build the suggested download filename for a receipt.
     *
     * @param int $orderId
     * @return string
     */
    public static function filename(int $orderId): string {
        return order_receipt_number($orderId) . '.pdf';
    }

    /**
     * Draw the merchant letterhead and receipt title.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawHeader(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell($w, 5.5, strtoupper(self::$merchant['name']), 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->Cell($w, 3.6, self::$merchant['tagline'], 0, 1, 'C');
        $pdf->Cell($w, 3.6, self::$merchant['address'], 0, 1, 'C');
        $pdf->Cell($w, 3.6, self::$merchant['contact'], 0, 1, 'C');

        $pdf->Ln(0.5);
        $pdf->SetFont('Helvetica', 'B', 10.5);
        $pdf->Cell($w, 5.5, 'OFFICIAL RECEIPT', 0, 1, 'C');
        self::rule($pdf);
    }

    /**
     * Draw the receipt number, timestamps, and customer identity block.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawOrderMeta(FPDF $pdf, array $data): void {
        self::metaRow($pdf, 'Receipt No.', $data['receipt_no'], 22, true);
        self::metaRow($pdf, 'Order No.', '#' . $data['order_id']);
        self::metaRow($pdf, 'Date', $data['placed_at']);
        self::metaRow($pdf, 'Customer', $data['customer_name']);

        if ($data['customer_phone'] !== '') {
            self::metaRow($pdf, 'Contact No.', $data['customer_phone']);
        }
        if ($data['customer_email'] !== '') {
            self::metaRow($pdf, 'Email', $data['customer_email'], 22);
        }

        self::rule($pdf);
    }

    /**
     * Draw the purchased item, its variant, and the line computation.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawItems(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->MultiCell($w, 4.6, $data['product_name'], 0, 'L');

        if ($data['product_variant'] !== '') {
            $pdf->SetFont('Helvetica', '', 7.5);
            $pdf->Cell($w, 3.8, 'Variant: ' . $data['product_variant'], 0, 1, 'L');
        }

        // "2 x 1,650.00" on the left, "= 3,300.00" right-aligned.
        $pdf->SetFont('Helvetica', '', 9);
        $line = $data['quantity'] . ' x ' . number_format((float)$data['unit_price'], 2);
        $result = '= ' . number_format((float)$data['total_amount'], 2);

        $pdf->Cell($w, 4.4, $line, 0, 0, 'L');
        $pdf->Cell($w, 4.4, $result, 0, 1, 'R');

        $pdf->Ln(0.5);
        self::rule($pdf);
    }

    /**
     * Draw the subtotal, delivery fee, and grand total.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawTotals(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell($w, 4.4, 'Subtotal', 0, 0, 'L');
        $pdf->Cell($w, 4.4, number_format((float)$data['total_amount'], 2), 0, 1, 'R');

        $pdf->Cell($w, 4.4, 'Delivery Fee', 0, 0, 'L');
        $pdf->Cell($w, 4.4, $data['delivery_fee_label'], 0, 1, 'R');

        // Highlighted grand total band.
        $pdf->SetFillColor(240, 246, 245);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell($w, 6.5, 'TOTAL AMOUNT', 0, 0, 'L', true);
        $pdf->Cell($w, 6.5, $data['total_amount_text'], 0, 1, 'R', true);

        $pdf->Ln(0.5);
        self::rule($pdf);
    }

    /**
     * Draw the payment method, settlement state, and any refund information.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawPayment(FPDF $pdf, array $data): void {
        // 23mm is the narrowest label column that still fits the widest label
        // ("Payment Method", 22.67mm at 8.5pt), leaving 39mm for values.
        $labelWidth = 23;

        self::metaRow($pdf, 'Payment Method', $data['payment_method_label'], $labelWidth);

        if ($data['is_gcash']) {
            self::metaRow($pdf, 'Payment Status', $data['payment_status_label'], $labelWidth);
            if ($data['payment_reference'] !== '') {
                self::metaRow($pdf, 'GCash Ref', $data['payment_reference'], $labelWidth);
            }
            if ($data['payment_id'] !== '') {
                self::metaRow($pdf, 'PayMongo ID', $data['payment_id'], $labelWidth);
            }
            if ($data['is_paid'] && $data['paid_at'] !== 'N/A') {
                self::metaRow($pdf, 'Paid On', $data['paid_at'], $labelWidth);
            }
        } else {
            self::metaRow($pdf, 'Balance Due', $data['total_amount_text'], $labelWidth);
            self::metaRow($pdf, 'Payable On', 'Delivery / Cash on Delivery', $labelWidth);
        }

        if ($data['refund_status_label'] !== '') {
            self::metaRow($pdf, 'Refund', $data['refund_status_label'], $labelWidth);
            if ($data['refund_reason'] !== '') {
                self::metaRow($pdf, 'Refund Reason', $data['refund_reason'], $labelWidth);
            }
            if ($data['refunded_at'] !== 'N/A') {
                self::metaRow($pdf, 'Refunded On', $data['refunded_at'], $labelWidth);
            }
        }

        if ($data['is_cancelled']) {
            self::metaRow($pdf, 'Cancel Reason', $data['cancel_reason'] !== '' ? $data['cancel_reason'] : 'Not specified', $labelWidth);
        }

        self::rule($pdf);
    }

    /**
     * Draw the delivery address, contact number, and special notes.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawDelivery(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($w, 4.4, 'DELIVER TO', 0, 1, 'L');

        $pdf->SetFont('Helvetica', '', 9);
        if ($data['delivery_address'] !== '') {
            $pdf->MultiCell($w, 4.2, $data['delivery_address'], 0, 'L');
        }
        if ($data['customer_phone'] !== '') {
            $pdf->Cell($w, 4.2, 'Contact: ' . $data['customer_phone'], 0, 1, 'L');
        }
        if ($data['notes'] !== '') {
            $pdf->Ln(0.5);
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->Cell($w, 3.8, 'Special Instructions:', 0, 1, 'L');
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->MultiCell($w, 3.8, $data['notes'], 0, 'L');
        }

        if ($data['rider_name'] !== '') {
            $pdf->Ln(0.5);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->Cell($w, 3.8, 'Assigned Rider: ' . $data['rider_name'], 0, 1, 'L');
        }

        $pdf->Ln(0.5);
        self::rule($pdf);
    }

    /**
     * Draw the order status and the six-step fulfillment checklist.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawProgress(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($w, 4.4, 'Order Status: ' . $data['status_label'], 0, 1, 'L');

        if ($data['is_cancelled']) {
            $pdf->SetFont('Helvetica', 'I', 8);
            $pdf->Cell($w, 3.8, 'Progress tracking is void for cancelled orders.', 0, 1, 'L');
        } else {
            $pdf->SetFont('Helvetica', '', 8);
            foreach ($data['progress'] as $step) {
                $mark = match ($step['state']) {
                    'done'    => '[x]',
                    'current' => '[>]',
                    default   => '[ ]',
                };
                $pdf->Cell($w, 3.9, $mark . ' ' . $step['label'], 0, 1, 'L');
            }
        }

        if ($data['delivered_at'] !== 'N/A') {
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->Cell($w, 3.9, 'Delivered On: ' . $data['delivered_at'], 0, 1, 'L');
        }

        $pdf->Ln(0.5);
        self::rule($pdf);
    }

    /**
     * Draw the closing message and the machine-verifiable footer.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawFooter(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($w, 5, 'Salamat po! Thank you.', 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->Cell($w, 4, 'Please keep this receipt for your records.', 0, 1, 'C');
        $pdf->Cell($w, 4, 'All prices are in Philippine Pesos (PHP).', 0, 1, 'C');
        $pdf->Cell($w, 4, 'Issued ' . date('F j, Y g:i A') . ' PHT', 0, 1, 'C');

        $pdf->Ln(1);
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->Cell($w, 5, $data['receipt_no'], 0, 1, 'C');
    }

    /**
     * Draw a full-width dashed separator, the standard thermal-receipt divider.
     *
     * FPDF's Line() takes no dash pattern, so the dashes are emitted as short
     * segments along the baseline.
     *
     * @param FPDF $pdf
     * @return void
     */
    private static function rule(FPDF $pdf): void {
        $y = $pdf->GetY();
        $left = self::MARGIN_MM;
        $right = self::PAGE_WIDTH_MM - self::MARGIN_MM;
        $dash = 0.9;
        $gap = 0.7;

        $pdf->SetLineWidth(0.1);
        $pdf->SetDrawColor(90, 90, 90);

        for ($x = $left; $x < $right; $x += ($dash + $gap)) {
            $pdf->Line($x, $y, min($x + $dash, $right), $y);
        }

        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->Ln(1.4);
    }

    /**
     * Draw a "label : value" row, wrapping the value when it is too long.
     *
     * @param FPDF $pdf
     * @param string $label
     * @param string $value
     * @param float $labelWidth
     * @param bool $emphasis Whether to render both label and value in bold
     * @return void
     */
    private static function metaRow(FPDF $pdf, string $label, string $value, float $labelWidth = 22, bool $emphasis = false): void {
        $valueWidth = self::CONTENT_WIDTH_MM - $labelWidth;
        $lineHeight = 4.2;
        $startY = $pdf->GetY();

        $pdf->SetFont('Helvetica', $emphasis ? 'B' : '', 8.5);
        $pdf->Cell($labelWidth, $lineHeight, $label, 0, 0, 'L');

        $pdf->SetFont('Helvetica', $emphasis ? 'B' : '', 8.5);
        $pdf->MultiCell($valueWidth, $lineHeight, $value === '' ? 'N/A' : $value, 0, 'L');

        // The label Cell does not advance Y, so a value that fits on one line
        // leaves Y exactly one line-height down; reset defensively so an
        // unusually tight font metric cannot overlap the next row.
        if ($pdf->GetY() < $startY + $lineHeight) {
            $pdf->SetY($startY + $lineHeight);
        }
    }

    /**
     * Normalize a value for the cp1252 PDF core font set.
     *
     * Strips the peso glyph (rendered as an "PHP" prefix instead), replaces the
     * handful of typographic characters users commonly type with ASCII
     * equivalents, and drops anything the core fonts cannot render so the
     * document never prints a row of "?" glyphs.
     *
     * @param string $value
     * @return string
     */
    public static function clean(string $value): string {
        $value = str_replace("\u{20B1}", '', $value); // ₱
        $value = strtr($value, [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2026}" => '...',
            "\u{00A0}" => ' ',
            "\u{20BF}" => 'PHP ',
        ]);

        // Collapse control characters and newlines, which FPDF renders poorly.
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
