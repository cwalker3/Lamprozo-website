<?php

    // plugin/views/my-bookings.php — rules-based booking admin (v2).
    // Rendered by firefly_collective_bookings_dashboard(); the Vue app lives in
    // assets/js/bookings-admin.js and talks to the /booking-admin/* REST routes.

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have sufficient permissions to access this page.' );
    }

?>

<div class="wrap" id="ffc-bookings-app" v-cloak>
    <h1>Bookings</h1>

    <div v-if="loading" class="ffc-loading">
        <div class="spinner is-active"></div>
        <p>Loading…</p>
    </div>

    <template v-else>

        <p class="ffc-booking-strap">
            Availability rules generate the open slots on
            <a href="/request-an-appointment/" target="_blank" rel="noopener">/request-an-appointment</a>.
            Confirmed bookings, your blocks, and your Google busy times all knock slots out.
            Times shown in <strong>{{ overview.timezone }}</strong>.
        </p>

        <!-- ============ Upcoming ============ -->
        <h2>Upcoming</h2>
        <table class="wp-list-table widefat fixed striped" v-if="overview.upcoming.length">
            <thead>
                <tr><th>When</th><th>Who</th><th>Contact</th><th>Notes</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="a in overview.upcoming" :key="a.id" :class="{ 'ffc-cancelled': a.status !== 'confirmed' }">
                    <td><strong>{{ a.local }}</strong><br><span class="ffc-muted">{{ a.type_title }}</span></td>
                    <td>{{ a.name }}</td>
                    <td>
                        <a :href="'mailto:' + a.email">{{ a.email }}</a>
                        <template v-if="a.phone"><br><a :href="'tel:' + a.phone">{{ a.phone }}</a></template>
                    </td>
                    <td class="ffc-notes-cell">{{ a.notes || '—' }}</td>
                    <td>{{ a.status }}</td>
                    <td>
                        <button v-if="a.status === 'confirmed'" class="button button-small"
                                @click="cancelAppointment(a)">Cancel</button>
                    </td>
                </tr>
            </tbody>
        </table>
        <p v-else class="ffc-muted">Nothing booked yet. The page is live — slots come from the rules below.</p>

        <!-- ============ Availability ============ -->
        <h2>Weekly availability</h2>
        <table class="ffc-availability">
            <tr v-for="(row, n) in availability.days" :key="n">
                <td class="ffc-dayname">
                    <label><input type="checkbox" v-model="row.on"> {{ dayNames[n] }}</label>
                </td>
                <td>
                    <input type="time" v-model="row.start" :disabled="!row.on"> –
                    <input type="time" v-model="row.end" :disabled="!row.on">
                </td>
            </tr>
        </table>
        <p>
            Lead time <input type="number" min="0" max="336" v-model.number="availability.lead_hours" class="small-text"> hours
            &nbsp;·&nbsp;
            Horizon <input type="number" min="1" max="365" v-model.number="availability.horizon_days" class="small-text"> days
            &nbsp;
            <button class="button button-primary" @click="saveAvailability" :disabled="saving">Save availability</button>
            <span v-if="savedTick" class="ffc-saved">✓ saved</span>
        </p>

        <!-- ============ Blocks ============ -->
        <h2>Blocked times</h2>
        <p class="ffc-muted">One-off exceptions on top of the weekly rules — vacation days, a long lunch, a conference.</p>
        <table class="wp-list-table widefat fixed striped" v-if="overview.blocks.length">
            <tbody>
                <tr v-for="b in overview.blocks" :key="b.id">
                    <td>{{ b.local }}</td>
                    <td>{{ b.reason || '—' }}</td>
                    <td><button class="button button-small" @click="deleteBlock(b)">Remove</button></td>
                </tr>
            </tbody>
        </table>
        <p>
            <input type="datetime-local" v-model="newBlock.start"> →
            <input type="datetime-local" v-model="newBlock.end">
            <input type="text" v-model="newBlock.reason" placeholder="Reason (optional)">
            <button class="button" @click="addBlock" :disabled="!newBlock.start || !newBlock.end">Add block</button>
        </p>

        <!-- ============ Calendar sync ============ -->
        <h2>Calendar sync</h2>
        <div class="ffc-sync-grid">
            <div>
                <h3>Your bookings → any calendar</h3>
                <p class="ffc-muted">Subscribe from Google (Settings → Add calendar → From URL), Apple, or Outlook.
                   Every confirmed booking also emails you an invite instantly — the feed is the belt to that suspenders.</p>
                <code class="ffc-feed-url">{{ overview.feed_url }}</code>
                <p>
                    <button class="button" @click="copyFeed">{{ copied ? 'Copied ✓' : 'Copy URL' }}</button>
                    <button class="button" @click="rotateToken">Regenerate token…</button>
                </p>
            </div>
            <div>
                <h3>Which calendar gets the booking</h3>
                <p class="ffc-muted">The address whose calendar should hold the event. Google only files an
                   invite automatically when the recipient is named on it, so a shared or forwarding
                   mailbox (info@) needs the real person here.</p>
                <input type="email" class="regular-text" v-model="ownerEmail" placeholder="you@yourdomain.com">
                <p>
                    <button class="button button-primary" @click="saveOwnerEmail" :disabled="saving">Save</button>
                    <span v-if="ownerTick" class="ffc-saved">✓ saved</span>
                </p>
            </div>
            <div>
                <h3>Meeting link</h3>
                <p class="ffc-muted">The video room every booking is held in — a permanent Google Meet, Jitsi,
                   or Zoom room. It goes on the calendar invite and in the confirmation email to both sides.
                   <strong>The booking page tells people they will get this link</strong>, so leaving it blank
                   means promising something the email does not contain.</p>
                <input type="url" class="regular-text" v-model="meetingUrl" placeholder="https://meet.google.com/your-room">
                <p>
                    <button class="button button-primary" @click="saveMeetingUrl" :disabled="saving">Save</button>
                    <span v-if="meetTick" class="ffc-saved">✓ saved</span>
                    <span v-if="!meetingUrl" class="ffc-muted" style="margin-left:.6rem;">Not set — invites go out without a link.</span>
                </p>
            </div>
            <div>
                <h3>Google busy times → here</h3>
                <p class="ffc-muted">Paste your calendar's <em>Secret address in iCal format</em>
                   (Google Calendar → Settings → your calendar → Integrate calendar). Events marked Free are ignored;
                   busy ones grey out slots. No password or Google login involved — the secret URL is the whole handshake.</p>
                <input type="url" class="regular-text" v-model="googleUrl" placeholder="https://calendar.google.com/calendar/ical/…/basic.ics">
                <p>
                    <button class="button button-primary" @click="saveGoogleUrl" :disabled="saving">Save &amp; sync</button>
                    <button class="button" @click="syncNow" :disabled="!overview.google_url">Sync now</button>
                </p>
                <p class="ffc-muted" v-if="overview.busy_status.fetched_at">
                    Last sync {{ overview.busy_status.fetched_at }} — {{ overview.busy_status.count }} busy blocks.
                    <template v-if="overview.busy_status.skipped">
                        <br><strong>{{ overview.busy_status.skipped }} recurring event(s) skipped</strong>
                        (monthly/yearly repeats aren't expanded yet — block those times manually if they matter).
                    </template>
                    <span v-if="overview.busy_status.error" class="ffc-error">{{ overview.busy_status.error }}</span>
                </p>
            </div>
        </div>

    </template>
</div>
