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

        $iconMap = [
            'success' => 'bi-check-circle-fill',
            'danger'  => 'bi-exclamation-triangle-fill',
            'warning' => 'bi-exclamation-circle-fill',
            'info'    => 'bi-info-circle-fill'
        ];

        $icon = $iconMap[$bsType] ?? 'bi-info-circle-fill';
        ?>
        <div class="alert alert-<?= e($bsType) ?> alert-dismissible fade show d-flex align-items-center shadow-sm mb-4 app-flash-alert" role="alert" data-flash-type="<?= e($bsType) ?>" data-flash-message="<?= e($flash['message']) ?>">
            <i class="bi <?= $icon ?> fs-5 me-2 flex-shrink-0"></i>
            <div class="flex-grow-1"><?= e($flash['message']) ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php
    }
}
