<?php
/**
 * Receipt Model Class
 * LPG Delivery System v2
 *
 * Builds Philippine-format 80mm thermal receipts as PDF documents.
 *
 * Receipts are generated on demand from a single `orders` row plus its
 * `order_items` lines (passed through as $order['items']). Single-item orders
 * keep the original compact layout; multi-item orders additionally print an
 * itemised breakdown. Nothing is persisted to the database.
 *
 * The PDF core font set is cp1252/WinAnsi, which has no glyph for the peso
 * sign (U+20B1). Amounts are therefore rendered as plain, comma-grouped
 * numbers (matching the branch's BIR-registered invoice) and any "₱" glyph is
 * stripped by Receipt::clean().
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

    /** Label column width in millimetres for a left-label/right-amount row. */
    private const AMOUNT_LABEL_WIDTH_MM = 40.0;

    /** Value-added tax rate applied on top of the order subtotal (12%). */
    public const VAT_RATE = 0.12;

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
     * Vendor identity and BIR-registered details printed on the receipt header
     * and footer.
     *
     * These values are fixed to the branch's registered identity and do not
     * vary per order, so they are hardcoded rather than read from the database.
     *
     * @var array<string, string>
     */
    public static array $merchant = [
        'name'                => 'Elevate LPG',
        'tagline'             => 'LPG Retail & Home Delivery',
        'address'             => 'Caloocan, Philippines',
        'membership_validity' => 'October 31, 2026',
        'branch'              => '1064 - Caloocan 5',
        'terminal'            => '1',
        'hotline'             => '09178288968 / 09992270108',
        'company_name'        => 'JIMAC INCORPORATED',
        'company_address1'    => 'PENTHOUSE TC PLAZA 40 QUEZON AVE',
        'company_address2'    => 'QUEZON CITY',
        'vat_reg_tin'         => 'VAT REG TIN : 002-705-362-000',
        'brn_accred'          => 'BRN ACCRED : 0390027053422015100423',
        'company_date_issued' => 'Date Issued: 03/14/2005',
        'permit_no'           => 'PERMIT NO  : FPD12023-027-0367769-00073',
        'permit_date_issued'  => 'Date Issued: 05/12/23',
        'effectivity_date'    => 'Effectivity Date: 05/25/23',
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
        $deliveryFee = 0.0;

        // `total_amount` is the VAT-inclusive gross the customer pays, matching
        // how the reports split VAT via sales_vat_split(). The receipt shows the
        // net subtotal, the 12% output VAT, and the gross total.
        $vatSplit = sales_vat_split($totalAmount);
        $netSales = $vatSplit['net'];
        $vatAmount = $vatSplit['vat'];
        $grandTotal = round($totalAmount + $deliveryFee, 2);

        $productName = self::clean((string)($order['product_name'] ?? 'LPG Cylinder'));
        $productBrand = self::clean((string)($order['product_brand'] ?? ''));
        $productWeight = self::clean((string)($order['product_weight'] ?? ''));

        $items = self::normalizeItems($order);

        return [
            'order_id'            => $orderId,
            'receipt_no'          => order_receipt_number($orderId),
            'invoice_no'          => order_receipt_number($orderId),
            'trans_date'          => format_ph_datetime($order['created_at'] ?? null, 'm/d/Y H:i'),
            'branch'              => self::$merchant['branch'],
            'terminal'            => self::$merchant['terminal'],
            'membership_validity' => self::$merchant['membership_validity'],

            'customer_name'       => self::titleCaseName(self::clean((string)($order['customer_name'] ?? 'Walk-in Customer'))),
            'customer_email'      => self::clean((string)($order['customer_email'] ?? '')),
            'customer_phone'      => self::clean((string)($order['contact_phone'] ?? '')),

            'product_name'        => $productName,
            'product_brand'       => $productBrand,
            'product_weight'      => $productWeight,
            'product_variant'     => trim(implode(' ', array_filter([$productBrand, $productWeight]))),

            'items'               => $items,
            'has_itemized'        => count($items) > 1,

            'quantity'            => $quantity,
            'unit_price'          => $unitPrice,
            'total_amount'        => $totalAmount,
            'delivery_fee'        => $deliveryFee,
            'delivery_fee_label'  => 'FREE',

            'vat_rate'            => self::VAT_RATE,
            'vat_amount'          => $vatAmount,
            'grand_total'         => $grandTotal,

            'unit_price_text'     => format_php_amount($unitPrice),
            'total_amount_text'   => format_php_amount($totalAmount),
            'subtotal_text'       => format_php_amount($netSales),
            'subtotal_plain_text' => number_format($netSales, 2),
            'vat_12_text'         => number_format($vatAmount, 2),
            'total_amount_plain_text' => number_format($totalAmount, 2),
            'vat_sales_text'      => number_format($netSales, 2),
            'vat_amount_text'     => number_format($vatAmount, 2),
            'vat_exempt_text'     => '0.00',
            'zero_rated_text'     => '0.00',
            'grand_total_text'    => 'PHP ' . number_format($grandTotal, 2),

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
     * Normalize an order's line items for receipt display.
     *
     * Uses $order['items'] (from Order::findById()) when present, otherwise
     * synthesizes the single legacy line from the order's own columns so older
     * or seed orders still render.
     *
     * @param array $order
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeItems(array $order): array {
        $raw = $order['items'] ?? null;

        if (!is_array($raw) || empty($raw)) {
            $raw = [[
                'product_name' => $order['product_name'] ?? 'LPG Cylinder',
                'brand'        => $order['product_brand'] ?? '',
                'weight'       => $order['product_weight'] ?? '',
                'unit_price'   => (float)($order['unit_price'] ?? 0),
                'quantity'     => max(1, (int)($order['quantity'] ?? 1)),
                'subtotal'     => (float)($order['total_amount'] ?? 0),
            ]];
        }

        $items = [];
        foreach ($raw as $item) {
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $unit = (float)($item['unit_price'] ?? 0);
            $subtotal = (float)($item['subtotal'] ?? round($unit * $qty, 2));

            $brand = self::clean((string)($item['brand'] ?? ''));
            $weight = self::clean((string)($item['weight'] ?? ''));

            $items[] = [
                'name'            => self::clean((string)($item['product_name'] ?? 'LPG Cylinder')),
                'variant'         => trim(implode(' ', array_filter([$brand, $weight]))),
                'quantity'        => $qty,
                'unit_price'      => $unit,
                'subtotal'        => $subtotal,
                'unit_price_plain'=> number_format($unit, 2),
                'subtotal_plain'  => number_format($subtotal, 2),
            ];
        }

        return $items;
    }

    /**
     * Compute the fulfillment progress rows for an order status.
     *
     * @param string $status
     * @return array<int, array{label: string, state: string}>
     */
    public static function buildProgress(string $status): array {        $steps = array_keys(self::PROGRESS_STEPS);
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
        $pdf->SetCreator('Elevate LPG');
        $pdf->SetAuthor(self::$merchant['name']);
        $pdf->SetTitle("Sale's Invoice " . $data['receipt_no']);
        $pdf->SetSubject('Order #' . $data['order_id']);

        $pdf->SetMargins(self::MARGIN_MM, self::MARGIN_MM, self::MARGIN_MM);

        // Auto page-break is off because the page is already sized to the
        // content; this keeps the trailing footer on the same sheet.
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        self::drawHeader($pdf, $data);
        self::drawInvoiceMeta($pdf, $data);
        if (!empty($data['has_itemized'])) {
            self::drawItems($pdf, $data);
        }
        self::drawTotals($pdf, $data);
        self::drawVatBreakdown($pdf, $data);
        self::drawHotline($pdf, $data);
        self::drawCompanyFooter($pdf, $data);

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
     * Draw the merchant letterhead and membership validity line.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawHeader(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->Cell($w, 5.5, self::$merchant['name'], 0, 1, 'C');

        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($w, 3.6, self::$merchant['tagline'], 0, 1, 'C');
        $pdf->Cell($w, 3.6, self::$merchant['address'], 0, 1, 'C');

        $pdf->Ln(1.2);
        self::rule($pdf);
    }

    /**
     * Draw the invoice reference, branch/terminal, customer, rider, and date.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawInvoiceMeta(FPDF $pdf, array $data): void {
        self::metaLine($pdf, 'Invoice: ' . $data['invoice_no']);
        self::metaLine($pdf, 'Order No.: ' . $data['order_id']);
        self::metaLine($pdf, 'Branch: ' . $data['branch']);
        self::metaLine($pdf, 'Terminal: ' . $data['terminal']);
        self::metaLine($pdf, 'Customer Name: ' . $data['customer_name']);
        self::metaLine($pdf, 'Rider Name: ' . ($data['rider_name'] !== '' ? $data['rider_name'] : 'N/A'));
        self::metaLine($pdf, 'Trans Date: ' . $data['trans_date']);

        self::rule($pdf);
    }

    /**
     * Draw the itemised breakdown for a multi-item order.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawItems(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($w, 4.4, 'ITEMS', 0, 1, 'L');

        foreach ($data['items'] as $item) {
            $pdf->SetFont('Helvetica', '', 8.5);
            $line = $item['name'] . ($item['variant'] !== '' ? ' (' . $item['variant'] . ')' : '');
            $pdf->MultiCell($w, 4.2, $line, 0, 'L');

            self::amountRow($pdf, $item['quantity'] . ' x ' . $item['unit_price_plain'], $item['subtotal_plain'], $w);
        }

        $pdf->Ln(1.2);
        self::rule($pdf);
    }

    /**
     * Draw the transaction summary: net subtotal, 12% VAT, and gross total.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawTotals(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', '', 9);
        self::amountRow($pdf, 'Subtotal', $data['subtotal_plain_text'], $w);
        self::amountRow($pdf, 'VAT 12%', $data['vat_12_text'], $w);

        $pdf->Ln(3);

        $pdf->SetFont('Helvetica', 'B', 10);
        self::amountRow($pdf, 'TOTAL AMOUNT', $data['total_amount_plain_text'], $w);

        $pdf->Ln(0.5);
        self::rule($pdf);
    }

    /**
     * Draw the BIR VAT breakdown: VATable sales, output VAT, exempt, zero-rated.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawVatBreakdown(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', '', 9);
        self::amountRow($pdf, '(V)VAT Sales', $data['vat_sales_text'], $w);
        self::amountRow($pdf, 'VAT Amount', $data['vat_amount_text'], $w);
        self::amountRow($pdf, '(E)VAT-Exempt', $data['vat_exempt_text'], $w);
        self::amountRow($pdf, '(Z)Zero-Rated', $data['zero_rated_text'], $w);

        self::rule($pdf);
    }

    /**
     * Draw the delivery hotline inside an asterisk border.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawHotline(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', '', 8);
        $starWidth = $pdf->GetStringWidth('*') ?: 1.3;
        $stars = str_repeat('*', max(1, (int)floor($w / $starWidth)));

        $pdf->Ln(1.2);
        $pdf->Cell($w, 4, $stars, 0, 1, 'C');
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell($w, 4.2, 'FOR DELIVERY CALL/TXT', 0, 1, 'C');
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($w, 4.2, self::$merchant['hotline'], 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($w, 4, $stars, 0, 1, 'C');

        $pdf->Ln(1.2);
    }

    /**
     * Draw the BIR-registered company identity and permit details.
     *
     * @param FPDF $pdf
     * @param array $data
     * @return void
     */
    private static function drawCompanyFooter(FPDF $pdf, array $data): void {
        $w = self::CONTENT_WIDTH_MM;

        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell($w, 4.4, self::$merchant['company_name'], 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->Cell($w, 3.8, self::$merchant['company_address1'], 0, 1, 'C');
        $pdf->Cell($w, 3.8, self::$merchant['company_address2'], 0, 1, 'C');

        $pdf->Ln(1.2);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->Cell($w, 3.8, self::$merchant['vat_reg_tin'], 0, 1, 'L');
        $pdf->Cell($w, 3.8, self::$merchant['brn_accred'], 0, 1, 'L');
        $pdf->Cell($w, 3.8, self::$merchant['company_date_issued'], 0, 1, 'L');
        $pdf->Cell($w, 3.8, self::$merchant['permit_no'], 0, 1, 'L');
        $pdf->Cell($w, 3.8, self::$merchant['permit_date_issued'], 0, 1, 'L');
        $pdf->Cell($w, 3.8, self::$merchant['effectivity_date'], 0, 1, 'L');
    }

    /**
     * Draw a single left-aligned "Label: value" line, wrapping long values.
     *
     * @param FPDF $pdf
     * @param string $text
     * @return void
     */
    private static function metaLine(FPDF $pdf, string $text): void {
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->MultiCell(self::CONTENT_WIDTH_MM, 4.2, $text, 0, 'L');
    }

    /**
     * Draw a left-aligned label with a right-aligned amount.
     *
     * @param FPDF $pdf
     * @param string $label
     * @param string $value
     * @param float $w Row width in millimetres
     * @return void
     */
    private static function amountRow(FPDF $pdf, string $label, string $value, float $w): void {
        // The two cells must share the roll width: the label is left-aligned in
        // the first column and the amount right-aligned in the remainder. Using
        // the full width for both would push the amount off the 80mm page.
        $labelWidth = self::AMOUNT_LABEL_WIDTH_MM;
        $pdf->Cell($labelWidth, 4.4, $label, 0, 0, 'L');
        $pdf->Cell($w - $labelWidth, 4.4, $value, 0, 1, 'R');
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
     * Capitalize the first letter of every word in a person's name.
     *
     * Names are stored exactly as typed ("JUAN dela cruz", "juan DELA CRUZ"),
     * so the string is folded first and then title-cased for a consistent
     * presentation on the invoice.
     *
     * @param string $value
     * @return string
     */
    public static function titleCaseName(string $value): string {
        if ($value === '') {
            return '';
        }

        return ucwords(mb_strtolower($value, 'UTF-8'));
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
