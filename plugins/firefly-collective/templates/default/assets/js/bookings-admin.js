// plugin/assets/js/bookings-admin.js — Vue app for the Bookings admin page.
// Talks to /booking-admin/* (cookie auth + X-WP-Nonce, manage_options).
// Mirrors the submissions.js patterns so the two admin pages stay one idiom.

document.addEventListener('DOMContentLoaded', function () {
    const { createApp, ref, reactive } = Vue;

    createApp({
        setup() {
            const loading   = ref(true);
            const saving    = ref(false);
            const savedTick = ref(false);
            const copied    = ref(false);
            const overview  = ref({ upcoming: [], blocks: [], busy_status: {} });
            const googleUrl = ref('');
            const ownerEmail = ref('');
            const ownerTick  = ref(false);
            const meetingUrl = ref('');
            const meetTick   = ref(false);

            // Weekday index (1=Mon … 7=Sun) → label, matching the PHP config keys.
            const dayNames = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday', 6: 'Saturday', 7: 'Sunday' };

            const availability = reactive({ days: {}, lead_hours: 24, horizon_days: 45 });
            const newBlock     = reactive({ start: '', end: '', reason: '' });

            function api(path, opts = {}) {
                return fetch(bookingsData.api_url + path, {
                    credentials: 'same-origin',
                    headers: Object.assign(
                        { 'X-WP-Nonce': bookingsData.nonce, 'Content-Type': 'application/json' },
                        opts.headers || {}
                    ),
                    method: opts.method || 'GET',
                    body: opts.body ? JSON.stringify(opts.body) : undefined
                }).then(r => {
                    if (!r.ok) return r.json().then(d => { throw new Error(d.message || ('HTTP ' + r.status)); });
                    return r.json();
                });
            }

            function refresh() {
                return api('booking-admin/overview').then(data => {
                    overview.value = data;
                    googleUrl.value = data.google_url;
                    ownerEmail.value = data.owner_email || '';
                    meetingUrl.value = data.meeting_url || '';
                    Object.assign(availability, data.availability);
                    loading.value = false;
                }).catch(e => {
                    loading.value = false;
                    alert('Could not load bookings: ' + e.message);
                });
            }

            function saveAvailability() {
                saving.value = true;
                api('booking-admin/availability', { method: 'POST', body: {
                    days: availability.days,
                    lead_hours: availability.lead_hours,
                    horizon_days: availability.horizon_days
                }}).then(d => {
                    Object.assign(availability, d.availability);
                    savedTick.value = true;
                    setTimeout(() => savedTick.value = false, 2000);
                }).catch(e => alert(e.message)).finally(() => saving.value = false);
            }

            function addBlock() {
                api('booking-admin/blocks', { method: 'POST', body: { ...newBlock } })
                    .then(() => { newBlock.start = newBlock.end = newBlock.reason = ''; return refresh(); })
                    .catch(e => alert(e.message));
            }

            function deleteBlock(b) {
                api('booking-admin/blocks/' + b.id, { method: 'DELETE' })
                    .then(refresh).catch(e => alert(e.message));
            }

            function cancelAppointment(a) {
                // Cancelling notifies the client and pushes a calendar CANCEL —
                // confirm() is the right amount of ceremony for that.
                if (!confirm('Cancel ' + a.name + "'s " + a.type_title + ' on ' + a.local + '?\n\nThey will be emailed, and the event is removed from calendars.')) return;
                api('booking-admin/appointments/' + a.id + '/cancel', { method: 'POST' })
                    .then(refresh).catch(e => alert(e.message));
            }

            function saveOwnerEmail() {
                saving.value = true;
                api('booking-admin/owner-email', { method: 'POST', body: { email: ownerEmail.value } })
                    .then(d => {
                        ownerEmail.value = d.owner_email;
                        ownerTick.value = true;
                        setTimeout(() => ownerTick.value = false, 2000);
                    })
                    .catch(e => alert(e.message)).finally(() => saving.value = false);
            }

            function saveMeetingUrl() {
                saving.value = true;
                api('booking-admin/meeting-url', { method: 'POST', body: { url: meetingUrl.value } })
                    .then(d => {
                        meetingUrl.value = d.meeting_url;
                        meetTick.value = true;
                        setTimeout(() => meetTick.value = false, 2000);
                    })
                    .catch(e => alert(e.message)).finally(() => saving.value = false);
            }

            function saveGoogleUrl() {
                saving.value = true;
                api('booking-admin/google-url', { method: 'POST', body: { url: googleUrl.value } })
                    .then(refresh).catch(e => alert(e.message)).finally(() => saving.value = false);
            }

            function syncNow() {
                api('booking-admin/busy-sync', { method: 'POST' })
                    .then(refresh).catch(e => alert(e.message));
            }

            function rotateToken() {
                if (!confirm('Regenerate the feed token?\n\nEvery calendar currently subscribed to the feed URL will stop updating until it is re-subscribed with the new URL.')) return;
                api('booking-admin/feed-token', { method: 'POST' })
                    .then(refresh).catch(e => alert(e.message));
            }

            function copyFeed() {
                navigator.clipboard.writeText(overview.value.feed_url).then(() => {
                    copied.value = true;
                    setTimeout(() => copied.value = false, 2000);
                });
            }

            refresh();

            return {
                loading, saving, savedTick, copied, overview, googleUrl,
                dayNames, availability, newBlock, ownerEmail, ownerTick, saveOwnerEmail,
                meetingUrl, meetTick, saveMeetingUrl,
                saveAvailability, addBlock, deleteBlock, cancelAppointment,
                saveGoogleUrl, syncNow, rotateToken, copyFeed
            };
        }
    }).mount('#ffc-bookings-app');
});
