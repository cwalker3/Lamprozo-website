<?php
global $referrals;
$pending = array_filter( $referrals, fn( $r ) => $r['status'] === 'pending' );
$paid    = array_filter( $referrals, fn( $r ) => $r['status'] !== 'pending' );
?>
<div id="referral-program-admin">
  <h1>Referral Program Admin</h1>

  <div id="referral-submissions">
    <?php foreach ( $pending as $referral ) : ?>
      <div class="referral-card" data-id="<?= esc_attr( $referral['id'] ) ?>">
        <div class="referral-details">
          <p><strong>Company:</strong> <?= esc_html( $referral['company_name'] ); ?></p>
          <p><strong>Contact:</strong> <?= esc_html( $referral['contact_name'] ); ?></p>
          <p><strong>Phone:</strong> <?= esc_html( $referral['phone'] ); ?></p>
          <p><strong>Email:</strong> <?= esc_html( $referral['email'] ); ?></p>
          <p><strong>Description:</strong> <?= esc_html( $referral['description'] ); ?></p>
          <p><strong>Submitted:</strong> <?= esc_html( date( 'F j, Y g:ia', strtotime( $referral['created_at'] ) ) ); ?></p>
        </div>
        <div class="referral-actions">
          <button type="button" class="btn btn-secondary cancel-submission-btn">Cancel</button>
          <button type="button" class="btn btn-primary mark-as-paid-btn">Mark Paid</button>
        </div>
        <div class="loader"><img src="<?= get_template_directory_uri() ?>/images/loading-dark.gif" alt="Loading…"></div>
      </div>
    <?php endforeach; ?>
  </div>

  <details id="paid-referrals-container">
    <summary>Past Referrals (<?= count( $paid ) ?>)</summary>
    <div id="paid-referrals-list">
      <?php foreach ( $paid as $referral ) : ?>
        <div class="referral-card paid-card">
          <div class="referral-details">
            <p><strong>Company:</strong> <?= esc_html( $referral['company_name'] ); ?></p>
            <p><strong>Contact:</strong> <?= esc_html( $referral['contact_name'] ); ?></p>
            <p><strong>Phone:</strong> <?= esc_html( $referral['phone'] ); ?></p>
            <p><strong>Email:</strong> <?= esc_html( $referral['email'] ); ?></p>
            <p><strong>Description:</strong> <?= esc_html( $referral['description'] ); ?></p>
            <p><strong>Paid On:</strong> <?= esc_html( date( 'F j, Y g:ia', strtotime( $referral['created_at'] ) ) ); ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </details>
</div>

<!-- Confirmation Modal -->
<div id="confirmation-modal" class="modal hidden">
  <div class="modal-overlay"></div>
  <div class="modal-content">
    <p id="confirmation-message"></p>
    <div class="modal-buttons">
      <button type="button" id="confirm-no" class="btn btn-secondary">No</button>
      <button type="button" id="confirm-yes" class="btn btn-primary">Yes</button>
    </div>
  </div>
</div>
