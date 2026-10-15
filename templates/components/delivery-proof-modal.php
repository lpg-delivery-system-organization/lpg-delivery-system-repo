<?php
/**
 * Proof-of-Delivery Modal Component
 * LPG Delivery System v2
 *
 * Shared between the rider order-detail page and the deliveries list. The rider
 * must attach a photo of the delivered item at the customer's location before
 * the delivery can be completed. Opened by assets/js/rider.js
 * (initDeliveryProofModal) via any element carrying the .btn-open-proof-modal
 * class; the opener supplies order id / customer / payment details through data
 * attributes which the script copies into this modal before showing it.
 *
 * The form posts multipart/form-data to api/delivery-proof.php.
 */
?>
<div class="modal fade" id="deliveryProofModal" tabindex="-1" aria-labelledby="deliveryProofModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form id="deliveryProofForm" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="order_id" id="proofOrderId" value="">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="deliveryProofModalLabel">
                        <i class="bi bi-camera-fill text-success me-2"></i>Proof of Delivery
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning border border-warning-subtle p-3 mb-3 d-none" id="proofCodAlert">
                        <strong class="d-block text-danger"><i class="bi bi-cash-coin me-1"></i>Collect Cash on Delivery</strong>
                        <small class="text-muted">Confirm you collected <span id="proofCodAmount" class="fw-bold text-danger"></span> from the customer before completing.</small>
                    </div>

                    <p class="text-muted small mb-3">
                        Take a clear photo of the delivered LPG cylinder at the customer's location.
                        <strong class="text-dark">This photo is required</strong> to complete delivery
                        <span id="proofOrderLabel" class="fw-semibold text-dark"></span>.
                    </p>

                    <div class="mb-3">
                        <label for="proofPhotoInput" class="form-label fw-semibold small text-uppercase">Delivery Photo <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" id="proofPhotoInput" name="proof_photo" accept="image/*" capture="environment" required>
                        <div class="form-text">JPG, PNG or WebP &middot; up to 5 MB. On a phone this opens the camera.</div>
                    </div>

                    <div id="proofPreviewWrap" class="d-none text-center mb-3">
                        <img id="proofPreview" src="" alt="Proof of delivery preview" class="img-fluid rounded border" style="max-height: 260px; object-fit: contain;">
                        <div>
                            <button type="button" class="btn btn-sm btn-link text-danger mt-1 text-decoration-none" id="proofRetake">
                                <i class="bi bi-arrow-repeat me-1"></i>Retake / choose another
                            </button>
                        </div>
                    </div>

                    <div class="mb-1">
                        <label for="proofNote" class="form-label fw-semibold small text-uppercase">Note <span class="text-muted fw-normal text-lowercase">(optional)</span></label>
                        <input type="text" class="form-control" id="proofNote" name="delivery_note" maxlength="255" placeholder="e.g. Handed to the customer at the door" autocomplete="off">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold" id="btnSubmitDeliveryProof" disabled>
                        <i class="bi bi-check-circle-fill me-1"></i>Complete Delivery
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
