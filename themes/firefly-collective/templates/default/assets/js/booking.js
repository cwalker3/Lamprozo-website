// template/assets/js/booking.js — the public booking widget.
//
// Ported from the firefly-v2 site into the default template. Class names use
// the neutral ffc- prefix; the element IDs are the contract with
// views/request-an-appointment.php and must not change.
//
// Three steps in one card: month grid → time chips → details form → booked.
// Slots come from GET custom-api/v1/booking-slots?month=YYYY-MM (public);
// booking POSTs custom-api/v1/book-appointment with the myApi nonce. The
// server re-validates the slot at booking time, so a stale grid can never
// double-book — the visitor is just asked to pick again.

document.addEventListener('DOMContentLoaded', function () {

	const widget = document.getElementById('ffc-booking-widget');
	if (!widget) return;

	const els = {
		error:     document.getElementById('ffc-booking-error'),
		cal:       document.getElementById('ffc-booking-cal'),
		calTitle:  document.getElementById('ffc-cal-title'),
		calDays:   document.getElementById('ffc-cal-days'),
		prev:      document.getElementById('ffc-cal-prev'),
		next:      document.getElementById('ffc-cal-next'),
		slotsWrap: document.getElementById('ffc-booking-slots-wrap'),
		slotsDay:  document.getElementById('ffc-slots-day'),
		slots:     document.getElementById('ffc-booking-slots'),
		tz:        document.getElementById('ffc-booking-tz'),
		details:   document.getElementById('ffc-booking-details'),
		chosen:    document.getElementById('ffc-chosen-label'),
		change:    document.getElementById('ffc-chosen-change'),
		name:      document.getElementById('ffc-booking-name'),
		email:     document.getElementById('ffc-booking-email'),
		phone:     document.getElementById('ffc-booking-phone'),
		notes:     document.getElementById('ffc-booking-notes'),
		submit:    document.getElementById('ffc-booking-submit'),
		success:   document.getElementById('ffc-booking-success'),
		when:      document.getElementById('ffc-success-when'),
		ics:       document.getElementById('ffc-success-ics'),
		typeTitle: document.getElementById('ffc-booking-type-title'),
		typeNote:  document.getElementById('ffc-booking-type-note'),
	};

	const today = new Date();
	let view    = { y: today.getFullYear(), m: today.getMonth() + 1 };  // 1-based month
	let monthDays = {};       // 'YYYY-MM-DD' -> [ISO slot starts]
	let selectedDay  = null;
	let selectedSlot = null;  // ISO string

	// Prefill from the quote form's hand-off. lead_* prefixes on purpose:
	// `name` is a reserved WordPress query var (it means "post slug"), and a
	// bare ?name= 301s through WP's canonical redirect before we ever run.
	const qs = new URLSearchParams(window.location.search);
	if (qs.get('lead_name'))  els.name.value  = qs.get('lead_name');
	if (qs.get('lead_email')) els.email.value = qs.get('lead_email');

	function showError(msg) { els.error.textContent = msg || ''; }

	function fmtMonth(y, m) {
		return new Date(y, m - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
	}

	function loadMonth() {
		showError('');
		els.calTitle.textContent = fmtMonth(view.y, view.m);
		els.calDays.innerHTML = '<span class="ffc-booking__loading">Loading…</span>';

		const month = view.y + '-' + String(view.m).padStart(2, '0');
		fetch(`${myApi.api_url}booking-slots?month=${month}`)
			.then(r => r.json())
			.then(data => {
				monthDays = data.days || {};
				if (data.type) {
					els.typeTitle.textContent = 'Book a ' + data.type.title.toLowerCase();
					// Matches the server-rendered default in
					// views/request-an-appointment.php — change both together.
					els.typeNote.textContent  = data.type.duration + ' minutes over video. Pick a day, then a time.';
				}
				// Times render in the VISITOR's locale; the zone label keeps an
				// out-of-area caller from assuming ours.
				els.tz.textContent = 'Times shown in your time zone (' +
					Intl.DateTimeFormat().resolvedOptions().timeZone.replace(/_/g, ' ') + ').';
				renderCalendar();
			})
			.catch(err => {
				console.error('booking widget:', err.stack || err);
				els.calDays.innerHTML = '';
				showError('Could not load availability. Please refresh and try again.');
			});
	}

	function renderCalendar() {
		els.calDays.innerHTML = '';
		const first   = new Date(view.y, view.m - 1, 1);
		const daysIn  = new Date(view.y, view.m, 0).getDate();

		// Leading pads so day 1 lands under its weekday (Sunday-start grid).
		for (let i = 0; i < first.getDay(); i++) {
			els.calDays.appendChild(document.createElement('span'));
		}

		for (let d = 1; d <= daysIn; d++) {
			const key  = view.y + '-' + String(view.m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
			const open = Object.prototype.hasOwnProperty.call(monthDays, key);
			const btn  = document.createElement('button');
			btn.type = 'button';
			btn.className = 'ffc-booking__day' + (open ? ' is-open' : '') + (key === selectedDay ? ' is-selected' : '');
			btn.textContent = d;
			btn.disabled = !open;
			if (open) btn.addEventListener('click', () => selectDay(key, btn));
			els.calDays.appendChild(btn);
		}
	}

	// Respect the OS "reduce motion" setting for both the CSS animation (handled
	// in booking.css) and the scroll below.
	const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function closeSlots() {
		els.slotsWrap.classList.remove('is-open');
	}

	function selectDay(key, btn) {
		selectedDay = key;
		[...els.calDays.querySelectorAll('.is-selected')].forEach(b => b.classList.remove('is-selected'));
		btn.classList.add('is-selected');

		els.slotsDay.textContent = new Date(key + 'T12:00:00').toLocaleDateString('en-US',
			{ weekday: 'long', month: 'long', day: 'numeric' });
		els.slots.innerHTML = '';
		(monthDays[key] || []).forEach((iso, i) => {
			const chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'ffc-booking__slot';
			// Drives the staggered fade-up; the CSS reads it as an index.
			chip.style.setProperty('--ffc-slot-i', i);
			chip.textContent = new Date(iso).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
			chip.addEventListener('click', () => selectSlot(iso, chip.textContent));
			els.slots.appendChild(chip);
		});

		// Opening on the NEXT frame matters when the panel is already open and
		// they pick a different day: the class has to come off and go back on
		// across a paint for the chip animations to restart, otherwise the new
		// times appear with no transition at all.
		els.slotsWrap.classList.remove('is-open');
		requestAnimationFrame(() => {
			els.slotsWrap.classList.add('is-open');
			revealSlots();
		});
	}

	// Bring the times into view once the card has grown most of the way.
	// Scrolling immediately would aim at the old, collapsed layout and land
	// short; waiting for the full transition to finish reads as a lag. Roughly
	// two-thirds through is where the target stops moving enough to matter.
	function revealSlots() {
		if (reduceMotion) return;
		setTimeout(() => {
			// 'center' rather than 'start': the nav is fixed over the top ~100px
			// of the viewport, so a start-aligned scroll tucks the day heading
			// underneath it.
			els.slotsWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}, 240);
	}

	function selectSlot(iso, timeLabel) {
		selectedSlot = iso;
		els.chosen.textContent = els.slotsDay.textContent + ' at ' + timeLabel;
		els.cal.hidden = true;
		els.details.hidden = false;
		(els.name.value ? (els.email.value ? els.notes : els.email) : els.name).focus();
	}

	els.change.addEventListener('click', () => {
		selectedSlot = null;
		els.details.hidden = true;
		els.cal.hidden = false;
		showError('');
	});

	els.prev.addEventListener('click', () => {
		view.m--; if (view.m < 1) { view.m = 12; view.y--; }
		selectedDay = null; closeSlots();
		loadMonth();
	});
	els.next.addEventListener('click', () => {
		view.m++; if (view.m > 12) { view.m = 1; view.y++; }
		selectedDay = null; closeSlots();
		loadMonth();
	});

	els.submit.addEventListener('click', function submitBooking() {
		const name  = els.name.value.trim();
		const email = els.email.value.trim();

		// Flag the field as well as naming the problem — a message above a form
		// leaves someone hunting for which box it means.
		[els.name, els.email].forEach(el => el.classList.remove('is-invalid'));

		if (!name)  { els.name.classList.add('is-invalid'); showError('Please add your name.'); els.name.focus(); return; }
		if (!email || !isValidEmail(email)) { els.email.classList.add('is-invalid'); showError('Please add a valid email. The invite goes there.'); els.email.focus(); return; }
		if (!selectedSlot) { showError('Pick a time first.'); return; }
		showError('');

		els.submit.innerHTML = `<img class="loader" src="${myApi.template_path}/images/loading.gif" alt="Booking">`;

		fetch(`${myApi.api_url}book-appointment`, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': myApi.nonce },
			body: JSON.stringify({
				name: name, email: email,
				phone: els.phone.value.trim(),
				notes: els.notes.value.trim(),
				start: selectedSlot
			})
		})
			.then(r => r.json().then(data => ({ ok: r.ok, status: r.status, data })))
			.then(({ ok, status, data }) => {
				if (!ok) {
					// 409 = the slot went while they typed. Reload the month so
					// the grid is honest, and put them back on the calendar.
					els.submit.innerHTML = '<input type="button" class="ffc-btn ffc-btn--accent" value="Confirm booking">';
					if (status === 409) {
						els.details.hidden = true; els.cal.hidden = false;
						selectedSlot = null; loadMonth();
					}
					throw new Error(data.message || 'Something went wrong.');
				}
				els.details.hidden = true;
				els.when.textContent = data.when;
				// Offer the ics directly too — belt to the emailed invite's braces.
				els.ics.href = URL.createObjectURL(new Blob([data.ics], { type: 'text/calendar' }));
				els.success.hidden = false;
				els.success.setAttribute('tabindex', '-1');
				els.success.focus({ preventScroll: true });
			})
			.catch(e => showError(e.message));
	});

	loadMonth();
});
