<?php
/**
 * Flash Alert Component
 * LPG Delivery System v2
 *
 * Renders session flash notifications as dismissible Bootstrap 5 alerts.
 */

if (function_exists('get_flash')) {
    $flash = get_flash();
    if ($flash && !empty($flash['message'])) {
        $type = (string)($flash['type'] ?? 'info');
        $bsType = ($type === 'error') ? 'danger' : $type;
        // Hidden carrier: the message pops up as a toast (footer script),
        // so no static banner is shown at the top of the page.
        ?>
        <div class="d-none app-flash-alert" role="alert" data-flash-type="<?= e($bsType) ?>" data-flash-message="<?= e($flash['message']) ?>"></div>
        <?php
    }
}
