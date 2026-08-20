<?php
/**
 * Reusable Bootstrap 5 Modal Component
 * LPG Delivery System v2
 */

if (!function_exists('render_modal')) {
    /**
     * Helper function to render a structured Bootstrap 5 modal
     *
     * @param string $id Modal element ID
     * @param string $title Modal header title
     * @param string $bodyHtml Modal body HTML content
     * @param string $footerHtml Optional footer HTML content (action buttons)
     * @param string $size Optional size ('sm', 'lg', 'xl', or empty)
     * @param bool $centered Whether modal is vertically centered (default true)
     * @param bool $scrollable Whether modal body is scrollable (default false)
     * @return string
     */
    function render_modal(
        string $id,
        string $title,
        string $bodyHtml,
        string $footerHtml = '',
        string $size = '',
        bool $centered = true,
        bool $scrollable = false
    ): string {
        $sizeClass = !empty($size) ? ' modal-' . e($size) : '';
        $centeredClass = $centered ? ' modal-dialog-centered' : '';
        $scrollableClass = $scrollable ? ' modal-dialog-scrollable' : '';

        $out = '<div class="modal fade" id="' . e($id) . '" tabindex="-1" aria-labelledby="' . e($id) . 'Label" aria-hidden="true">';
        $out .= '  <div class="modal-dialog' . $sizeClass . $centeredClass . $scrollableClass . '">';
        $out .= '    <div class="modal-content shadow border-0">';
        $out .= '      <div class="modal-header bg-light border-bottom">';
        $out .= '        <h5 class="modal-title fw-bold" id="' . e($id) . 'Label">' . e($title) . '</h5>';
        $out .= '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
        $out .= '      </div>';
        $out .= '      <div class="modal-body p-4">';
        $out .= $bodyHtml;
        $out .= '      </div>';
        if (!empty($footerHtml)) {
            $out .= '      <div class="modal-footer bg-light border-top">';
            $out .= $footerHtml;
            $out .= '      </div>';
        }
        $out .= '    </div>';
        $out .= '  </div>';
        $out .= '</div>';

        return $out;
    }
}

// Support direct inclusion with variables
if (isset($modal_id) && isset($modal_title)) {
    echo render_modal(
        $modal_id,
        $modal_title,
        $modal_body ?? '',
        $modal_footer ?? '',
        $modal_size ?? '',
        $modal_centered ?? true,
        $modal_scrollable ?? false
    );
}
