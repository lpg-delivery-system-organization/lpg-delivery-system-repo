<?php
/**
 * Shared Confirmation Modal Component
 * LPG Delivery System v2
 *
 * This modal is manipulated dynamically via window.confirmAction() in app.js
 */
?>
<div class="modal fade" id="globalConfirmModal" tabindex="-1" aria-labelledby="globalConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg text-center p-3">
            <div class="modal-body p-3">
                <div id="globalConfirmIconBox" class="modal-confirm-icon-box bg-light text-primary mx-auto">
                    <i id="globalConfirmIcon" class="bi bi-question-circle"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2" id="globalConfirmTitle">Confirm Action</h5>
                <p class="text-muted small mb-4" id="globalConfirmMessage">Are you sure you want to proceed?</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light px-3 fw-semibold border" data-bs-dismiss="modal" id="globalConfirmCancelBtn">Cancel</button>
                    <button type="button" class="btn btn-primary px-4 fw-semibold shadow-sm" id="globalConfirmProceedBtn">Proceed</button>
                </div>
            </div>
        </div>
    </div>
</div>
