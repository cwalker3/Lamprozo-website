(function(){
  // Detect user-edit/profile pages
  const path = window.location.pathname;
  const isUserPage = path.endsWith('user-edit.php') || path.endsWith('profile.php');

  // ─── Move Referral Stage table under Role dropdown ──────────────────────────
  if ( isUserPage ) {
    document.addEventListener('DOMContentLoaded', () => {
      const select = document.getElementById('referral-program-stage');
      if ( select ) {
        const table = select.closest('table.form-table');
        const roleTable = document.getElementById('role').closest('table.form-table');
        roleTable.insertAdjacentElement('afterend', table);
      }
    });
    return;
  }

  // ─── Below: Referral Program admin page JS ───────────────────────────────
  const pendingContainer = document.getElementById('referral-submissions');
  const paidList         = document.getElementById('paid-referrals-list');
  const paidContainer    = document.getElementById('paid-referrals-container');
  const summary          = paidContainer.querySelector('summary');
  
  // Modal elements
  const modal            = document.getElementById('confirmation-modal');
  const messageEl        = document.getElementById('confirmation-message');
  const yesBtn           = document.getElementById('confirm-yes');
  const noBtn            = document.getElementById('confirm-no');

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showConfirm(message) {
    return new Promise(resolve => {
      messageEl.textContent = message;
      modal.classList.remove('hidden');

      function cleanUp() {
        yesBtn.removeEventListener('click', onYes);
        noBtn.removeEventListener('click', onNo);
      }
      function onYes() {
        cleanUp();
        modal.classList.add('hidden');
        resolve(true);
      }
      function onNo() {
        cleanUp();
        modal.classList.add('hidden');
        resolve(false);
      }

      yesBtn.addEventListener('click', onYes);
      noBtn.addEventListener('click', onNo);
    });
  }

  function fadeOut(el) {
    return new Promise(resolve => {
      el.style.transition = 'opacity 0.4s';
      el.style.opacity    = 0;
      el.addEventListener('transitionend', resolve, { once: true });
    });
  }

  function updatePaidCount() {
    const count = paidList.children.length;
    summary.textContent = `Past Referrals (${count})`;
  }

  function createPaidCard(data) {
    const card = document.createElement('div');
    card.className = 'referral-card paid-card';
    card.innerHTML = `
      <div class="referral-details">
        <p><strong>Company:</strong> ${escapeHtml(data.company_name)}</p>
        <p><strong>Contact:</strong> ${escapeHtml(data.contact_name)}</p>
        <p><strong>Phone:</strong> ${escapeHtml(data.phone)}</p>
        <p><strong>Email:</strong> ${escapeHtml(data.email)}</p>
        <p><strong>Description:</strong> ${escapeHtml(data.description)}</p>
        <p><strong>Paid On:</strong> ${new Date().toLocaleString()}</p>
      </div>
    `;
    return card;
  }

  async function handleAction(isPaid, id, card) {
    const endpoint = isPaid ? 'mark-referral-as-paid' : 'cancel-referral-submission';
    const loaderImg = card.querySelector('.loader img');
    loaderImg.style.display = 'inline-block';

    try {
      const res  = await fetch(`${referralProgramData.api_url}${endpoint}`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': referralProgramData.nonce
        },
        body: JSON.stringify({ submissionId: parseInt(id, 10) })
      });
      const json = await res.json();

      if ( json.success ) {
        await fadeOut(card);
        card.remove();

        if ( isPaid ) {
          const paidCard = createPaidCard(json.referral);
          paidList.appendChild(paidCard);
          updatePaidCount();
        }
      } else {
        console.error('Server error:', json);
      }
    } catch(err) {
      console.error(err);
    } finally {
      loaderImg.style.display = 'none';
    }
  }

  async function onActionClick(e) {
    const btn    = e.currentTarget;
    const isPaid = btn.classList.contains('mark-as-paid-btn');
    const card   = btn.closest('.referral-card');
    const id     = card.dataset.id;
    const message = isPaid
      ? 'Are you sure you want to mark this referral as paid?'
      : 'Are you sure you want to cancel this referral submission?';

    const confirmed = await showConfirm(message);
    if ( !confirmed ) return;

    handleAction(isPaid, id, card);
  }

  document.querySelectorAll('.cancel-submission-btn')
    .forEach(btn => btn.addEventListener('click', onActionClick));

  document.querySelectorAll('.mark-as-paid-btn')
    .forEach(btn => btn.addEventListener('click', onActionClick));

})();
