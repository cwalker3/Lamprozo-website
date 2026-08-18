<?php

    /* plugin/models/bookings.php — sovereign booking system (v2).
     *
     * Full rewrite of the v1 slot-inventory model (admin hand-opened each slot;
     * a visitor's request claimed the row). This version is rules-based: weekly
     * availability generates slots, minus manual blocks, minus confirmed
     * appointments, minus the owner's Google busy times. The SITE is the source
     * of truth; external calendars are subscribers:
     *
     *   out, instant   — every confirmed/cancelled appointment emails a
     *                    standards iCalendar part (iTIP REQUEST/CANCEL). Gmail
     *                    ingests these into Google Calendar automatically.
     *   out, pollable  — GET /wp-json/custom-api/v1/bookings.ics?token=…
     *                    (METHOD:PUBLISH feed; subscribe from any calendar app).
     *   in,  busy only — the owner's Google "secret address in iCal format" is
     *                    polled hourly and its events grey out slots. Pure iCal;
     *                    no OAuth, no app password, in either direction.
     *
     * The v1 tables (firefly_collective_bookings*) are intentionally left in
     * the database untouched — this model neither reads nor drops them.
     *
     * All times are stored UTC (DATETIME, 'Y-m-d H:i:s'); slot generation and
     * display happen in the site timezone (wp_timezone()).
     */

    if ( ! defined( 'ABSPATH' ) ) exit;

    // =========================================================================
    // Constants / option keys
    // =========================================================================

    define( 'FFC_BOOKING_APTS_TABLE',   'ffc_appointments' );
    define( 'FFC_BOOKING_TYPES_TABLE',  'ffc_appointment_types' );
    define( 'FFC_BOOKING_BLOCKS_TABLE', 'ffc_booking_blocks' );

    define( 'FFC_BOOKING_OPT_AVAILABILITY', 'ffc_booking_availability' );
    define( 'FFC_BOOKING_OPT_FEED_TOKEN',   'ffc_booking_feed_token' );
    define( 'FFC_BOOKING_OPT_GOOGLE_URL',   'ffc_google_busy_url' );
    define( 'FFC_BOOKING_OPT_GOOGLE_CACHE', 'ffc_google_busy_cache' );

    define( 'FFC_BOOKING_OPT_OWNER_EMAIL', 'ffc_booking_owner_email' );
    define( 'FFC_BOOKING_OPT_MEETING_URL', 'ffc_booking_meeting_url' );

    /**
     * The video room every appointment is held in.
     *
     * A single standing room (a permanent Google Meet / Jitsi / Zoom link)
     * rather than a per-booking link: generating one link per event would mean
     * write access to a provider's calendar API and an OAuth token to keep
     * alive, where a fixed room needs neither and cannot silently stop working.
     *
     * Empty by default. Everything that renders it checks first, so an
     * unconfigured site simply omits the line rather than emailing a blank
     * link — but the /request-an-appointment copy promises a link, so this
     * should be set.
     */
    function ffc_booking_meeting_url() {
        $url = trim( (string) get_option( FFC_BOOKING_OPT_MEETING_URL, '' ) );
        return apply_filters( 'ffc_booking_meeting_url', $url );
    }

    /**
     * Organizer identity on every iCalendar object.
     *
     * Derived from the site rather than hardcoded, so a forked install is
     * correct out of the box. Override with the filter if the calendar
     * organizer should differ from the site's admin address.
     */
    function ffc_booking_organizer_email() {
        $email = get_option( 'admin_email' );
        if ( ! $email || ! is_email( $email ) ) {
            // Last resort: no-reply at the site's own host. Better a valid
            // address on the right domain than one belonging to another site.
            $host  = wp_parse_url( home_url(), PHP_URL_HOST );
            $host  = $host ? preg_replace( '/^www\\./', '', $host ) : 'localhost';
            $email = 'no-reply@' . $host;
        }
        return apply_filters( 'ffc_booking_organizer_email', $email );
    }

    /** Display name on outgoing booking mail and calendar objects. */
    function ffc_booking_from_name() {
        return apply_filters( 'ffc_booking_from_name', get_bloginfo( 'name' ) );
    }

    /**
     * The person whose CALENDAR should hold the booking — distinct from the
     * organizer mailbox. Gmail/Workspace only auto-adds an invite when the
     * recipient is listed as an ATTENDEE on it, so a notification sent to a
     * shared/forwarding address (info@) arrives as a mere attachment unless the
     * real human is named. Defaults to the organizer so behaviour is unchanged
     * until an owner is configured.
     */
    function ffc_booking_owner_email() {
        $owner = trim( (string) get_option( FFC_BOOKING_OPT_OWNER_EMAIL, '' ) );
        return $owner && is_email( $owner ) ? $owner : ffc_booking_organizer_email();
    }

    // =========================================================================
    // Schema
    // =========================================================================

    function ffc_booking_create_tables() {
        global $wpdb;
        $collate = $wpdb->get_charset_collate();

        /* Same caveat as ffc_submissions: dbDelta cannot diff a CREATE TABLE IF
         * NOT EXISTS statement (it misparses the table name as "IF"), so these
         * CREATEs only serve fresh installs. Column additions later must go
         * through an explicit check-and-ALTER like submissions.php does. */

        $apts = $wpdb->prefix . FFC_BOOKING_APTS_TABLE;
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$apts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uid VARCHAR(120) NOT NULL,
            type_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(191) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            notes TEXT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
            sequence INT NOT NULL DEFAULT 0,
            manage_token VARCHAR(48) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_starts (starts_at),
            INDEX idx_status (status)
        ) {$collate};" );

        $types = $wpdb->prefix . FFC_BOOKING_TYPES_TABLE;
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$types} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(60) NOT NULL,
            title VARCHAR(150) NOT NULL,
            duration_min INT NOT NULL DEFAULT 30,
            description TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug (slug)
        ) {$collate};" );

        $blocks = $wpdb->prefix . FFC_BOOKING_BLOCKS_TABLE;
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$blocks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            reason VARCHAR(191) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_starts (starts_at)
        ) {$collate};" );

        // Seed the single agreed type. INSERT IGNORE keys off uniq_slug, so
        // retitling/re-timing it in the admin later is never overwritten.
        $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$types} (slug, title, duration_min, description, active)
             VALUES (%s, %s, %d, %s, 1)",
            'discovery', 'Discovery call', 30,
            'A short call to hear how your business runs and what you need. You will get a straight answer on whether we can help.'
        ) );

        // Feed token: carry the v1 authorization_token forward if one exists,
        // so a feed URL someone already subscribed to keeps working.
        if ( ! get_option( FFC_BOOKING_OPT_FEED_TOKEN ) ) {
            $legacy = $wpdb->get_var( $wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}firefly_collective_bookings_settings
                 WHERE setting_type = %s LIMIT 1", 'authorization_token'
            ) );
            $token = ( $legacy && strlen( $legacy ) >= 16 ) ? $legacy : generateToken( 32 );
            add_option( FFC_BOOKING_OPT_FEED_TOKEN, $token, '', 'no' );
        }
    }
    ffc_booking_create_tables();

    // =========================================================================
    // Availability
    // =========================================================================

    /** Availability config with defaults merged. days is keyed 1 (Mon) – 7 (Sun). */
    function ffc_booking_get_availability() {
        $defaults = array(
            'days' => array(
                1 => array( 'on' => true,  'start' => '10:00', 'end' => '16:00' ),
                2 => array( 'on' => true,  'start' => '10:00', 'end' => '16:00' ),
                3 => array( 'on' => true,  'start' => '10:00', 'end' => '16:00' ),
                4 => array( 'on' => true,  'start' => '10:00', 'end' => '16:00' ),
                5 => array( 'on' => true,  'start' => '10:00', 'end' => '16:00' ),
                6 => array( 'on' => false, 'start' => '10:00', 'end' => '14:00' ),
                7 => array( 'on' => false, 'start' => '10:00', 'end' => '14:00' ),
            ),
            'lead_hours'   => 24,
            'horizon_days' => 45,
        );

        $saved = get_option( FFC_BOOKING_OPT_AVAILABILITY );
        if ( ! is_array( $saved ) ) {
            return $defaults;
        }
        $merged = $defaults;
        if ( isset( $saved['days'] ) && is_array( $saved['days'] ) ) {
            foreach ( $saved['days'] as $d => $row ) {
                $d = (int) $d;
                if ( $d >= 1 && $d <= 7 && is_array( $row ) ) {
                    $merged['days'][ $d ] = array(
                        'on'    => ! empty( $row['on'] ),
                        'start' => preg_match( '/^\d{2}:\d{2}$/', $row['start'] ?? '' ) ? $row['start'] : '10:00',
                        'end'   => preg_match( '/^\d{2}:\d{2}$/', $row['end'] ?? '' )   ? $row['end']   : '16:00',
                    );
                }
            }
        }
        if ( isset( $saved['lead_hours'] ) )   $merged['lead_hours']   = max( 0, min( 336, (int) $saved['lead_hours'] ) );
        if ( isset( $saved['horizon_days'] ) ) $merged['horizon_days'] = max( 1, min( 365, (int) $saved['horizon_days'] ) );
        return $merged;
    }

    function ffc_booking_get_type() {
        global $wpdb;
        $types = $wpdb->prefix . FFC_BOOKING_TYPES_TABLE;
        return $wpdb->get_row( "SELECT * FROM {$types} WHERE active = 1 ORDER BY id ASC LIMIT 1" );
    }

    /**
     * Every busy [startUtc, endUtc] pair inside the window: confirmed
     * appointments, manual blocks, and the cached Google busy intervals.
     * Timestamps are unix ints for cheap overlap tests.
     */
    function ffc_booking_busy_intervals( $from_ts, $to_ts ) {
        global $wpdb;
        $busy = array();

        $from_sql = gmdate( 'Y-m-d H:i:s', $from_ts );
        $to_sql   = gmdate( 'Y-m-d H:i:s', $to_ts );

        $apts = $wpdb->prefix . FFC_BOOKING_APTS_TABLE;
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT starts_at, ends_at FROM {$apts}
             WHERE status = 'confirmed' AND starts_at < %s AND ends_at > %s",
            $to_sql, $from_sql
        ) ) as $r ) {
            $busy[] = array( strtotime( $r->starts_at . ' UTC' ), strtotime( $r->ends_at . ' UTC' ) );
        }

        $blocks = $wpdb->prefix . FFC_BOOKING_BLOCKS_TABLE;
        foreach ( $wpdb->get_results( $wpdb->prepare(
            "SELECT starts_at, ends_at FROM {$blocks} WHERE starts_at < %s AND ends_at > %s",
            $to_sql, $from_sql
        ) ) as $r ) {
            $busy[] = array( strtotime( $r->starts_at . ' UTC' ), strtotime( $r->ends_at . ' UTC' ) );
        }

        $cache = get_option( FFC_BOOKING_OPT_GOOGLE_CACHE );
        if ( is_array( $cache ) && ! empty( $cache['intervals'] ) ) {
            foreach ( $cache['intervals'] as $iv ) {
                if ( $iv[0] < $to_ts && $iv[1] > $from_ts ) {
                    $busy[] = array( (int) $iv[0], (int) $iv[1] );
                }
            }
        }
        return $busy;
    }

    /**
     * Available slot starts for one calendar month, computed in the site
     * timezone. Returns array of 'Y-m-d' => [DateTimeImmutable, …].
     */
    function ffc_booking_month_slots( $year, $month ) {
        $type = ffc_booking_get_type();
        if ( ! $type ) return array();

        $conf     = ffc_booking_get_availability();
        $tz       = wp_timezone();
        $duration = max( 5, (int) $type->duration_min ) * 60;

        $now_ts      = time();
        $earliest_ts = $now_ts + $conf['lead_hours'] * 3600;
        $horizon_ts  = $now_ts + $conf['horizon_days'] * 86400;

        $first = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );
        $last  = $first->modify( 'last day of this month' )->setTime( 23, 59, 59 );

        // One busy lookup for the whole month, not one per slot.
        $busy = ffc_booking_busy_intervals( $first->getTimestamp(), $last->getTimestamp() );

        $out = array();
        for ( $day = $first; $day <= $last; $day = $day->modify( '+1 day' ) ) {
            $rule = $conf['days'][ (int) $day->format( 'N' ) ];
            if ( empty( $rule['on'] ) ) continue;

            list( $sh, $sm ) = array_map( 'intval', explode( ':', $rule['start'] ) );
            list( $eh, $em ) = array_map( 'intval', explode( ':', $rule['end'] ) );
            $open  = $day->setTime( $sh, $sm );
            $close = $day->setTime( $eh, $em );

            $slots = array();
            for ( $s = $open; $s->getTimestamp() + $duration <= $close->getTimestamp(); $s = $s->modify( '+' . $type->duration_min . ' minutes' ) ) {
                $s_ts = $s->getTimestamp();
                $e_ts = $s_ts + $duration;
                if ( $s_ts < $earliest_ts || $s_ts > $horizon_ts ) continue;

                $clear = true;
                foreach ( $busy as $iv ) {
                    if ( $s_ts < $iv[1] && $e_ts > $iv[0] ) { $clear = false; break; }
                }
                if ( $clear ) $slots[] = $s;
            }
            if ( $slots ) $out[ $day->format( 'Y-m-d' ) ] = $slots;
        }
        return $out;
    }

    // =========================================================================
    // iCalendar
    // =========================================================================

    function ffc_ics_escape( $s ) {
        return str_replace(
            array( '\\', ';', ',', "\r\n", "\n" ),
            array( '\\\\', '\;', '\,', '\n', '\n' ),
            (string) $s
        );
    }

    /** RFC 5545 lines are CRLF-terminated and folded at 75 octets. */
    function ffc_ics_fold( $line ) {
        $out = '';
        while ( strlen( $line ) > 73 ) {
            $out .= substr( $line, 0, 73 ) . "\r\n ";
            $line = substr( $line, 73 );
        }
        return $out . $line . "\r\n";
    }

    function ffc_ics_utc( $ts ) {
        return gmdate( 'Ymd\THis\Z', $ts );
    }

    /**
     * One appointment as a complete iTIP VCALENDAR (method REQUEST or CANCEL).
     *
     * $for_owner adds the site owner as a second ATTENDEE. That is what makes
     * Google Workspace put the event on their calendar automatically instead of
     * showing an .ics attachment they must open by hand.
     */
    function ffc_booking_appointment_ics( $apt, $method = 'REQUEST', $for_owner = false ) {
        $start = strtotime( $apt->starts_at . ' UTC' );
        $end   = strtotime( $apt->ends_at . ' UTC' );
        $org   = ffc_booking_organizer_email();
        $site  = get_bloginfo( 'name' );
        $meet  = ffc_booking_meeting_url();

        $lines = array(
            'BEGIN:VCALENDAR',
            'PRODID:-//' . ffc_ics_escape( ffc_booking_from_name() ) . '//Bookings//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:' . $apt->uid,
            'SEQUENCE:' . (int) $apt->sequence,
            'DTSTAMP:' . ffc_ics_utc( time() ),
            'DTSTART:' . ffc_ics_utc( $start ),
            'DTEND:' . ffc_ics_utc( $end ),
            'SUMMARY:' . ffc_ics_escape( ( 'CANCEL' === $method ? 'Cancelled: ' : '' ) . $apt->type_title . ': ' . $apt->name ),
            'DESCRIPTION:' . ffc_ics_escape(
                "Booked at {$site}.\n" .
                // First line of the description on purpose: most calendar
                // clients preview only the opening line, and a "join" button
                // is the one thing wanted at the moment the reminder fires.
                ( $meet ? "Join: {$meet}\n\n" : '' ) .
                "Name: {$apt->name}\nEmail: {$apt->email}" .
                ( $apt->phone ? "\nPhone: {$apt->phone}" : '' ) .
                ( $apt->notes ? "\n\n{$apt->notes}" : '' )
            ),
            'ORGANIZER;CN=' . ffc_ics_escape( $site ) . ':mailto:' . $org,
            'ATTENDEE;CN=' . ffc_ics_escape( $apt->name ) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED:mailto:' . $apt->email,
        );

        if ( $for_owner ) {
            $owner = ffc_booking_owner_email();
            // Skip when the owner IS the organizer — an ORGANIZER that also
            // appears as an ATTENDEE confuses some clients.
            if ( $owner !== $org ) {
                $lines[] = 'ATTENDEE;CN=' . ffc_ics_escape( get_bloginfo( 'name' ) )
                         . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $owner;
            }
        }

        if ( $meet ) {
            // LOCATION is what Google/Apple surface as the joinable line on the
            // event; URL is the RFC 5545 field for the same thing and is what
            // some desktop clients use instead. Both, so it shows up wherever
            // the invitee happens to read it.
            $lines[] = 'LOCATION:' . ffc_ics_escape( $meet );
            $lines[] = 'URL:' . ffc_ics_escape( $meet );
        }

        $lines = array_merge( $lines, array(
            'STATUS:' . ( 'CANCEL' === $method ? 'CANCELLED' : 'CONFIRMED' ),
            'END:VEVENT',
            'END:VCALENDAR',
        ) );

        $ics = '';
        foreach ( $lines as $l ) $ics .= ffc_ics_fold( $l );
        return $ics;
    }

    /** The subscribable feed: every confirmed appointment, recent past included. */
    function ffc_booking_feed_ics() {
        global $wpdb;
        $apts  = $wpdb->prefix . FFC_BOOKING_APTS_TABLE;
        $types = $wpdb->prefix . FFC_BOOKING_TYPES_TABLE;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, t.title AS type_title FROM {$apts} a
             LEFT JOIN {$types} t ON t.id = a.type_id
             WHERE a.status = 'confirmed' AND a.ends_at > %s
             ORDER BY a.starts_at ASC",
            gmdate( 'Y-m-d H:i:s', time() - 90 * 86400 )
        ) );

        $site  = get_bloginfo( 'name' );
        $ics   = '';
        $head  = array(
            'BEGIN:VCALENDAR',
            'PRODID:-//' . ffc_ics_escape( ffc_booking_from_name() ) . '//Bookings//EN',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . ffc_ics_escape( $site . ' Bookings' ),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        );
        foreach ( $head as $l ) $ics .= ffc_ics_fold( $l );

        foreach ( $rows as $apt ) {
            $ev = array(
                'BEGIN:VEVENT',
                'UID:' . $apt->uid,
                'SEQUENCE:' . (int) $apt->sequence,
                'DTSTAMP:' . ffc_ics_utc( strtotime( $apt->updated_at . ' UTC' ) ),
                'DTSTART:' . ffc_ics_utc( strtotime( $apt->starts_at . ' UTC' ) ),
                'DTEND:' . ffc_ics_utc( strtotime( $apt->ends_at . ' UTC' ) ),
                'SUMMARY:' . ffc_ics_escape( $apt->type_title . ': ' . $apt->name ),
                'DESCRIPTION:' . ffc_ics_escape( "Email: {$apt->email}" . ( $apt->phone ? "\nPhone: {$apt->phone}" : '' ) . ( $apt->notes ? "\n\n{$apt->notes}" : '' ) ),
                'STATUS:CONFIRMED',
                'END:VEVENT',
            );
            foreach ( $ev as $l ) $ics .= ffc_ics_fold( $l );
        }
        return $ics . ffc_ics_fold( 'END:VCALENDAR' );
    }

    // =========================================================================
    // Email (iMIP) — invites ride ordinary email, which is why Google needs no
    // credentials: Gmail sees a text/calendar attachment and files the event.
    // =========================================================================

    /**
     * @param string|null $reply_to Address a reply should reach. The two emails
     *                              that go to the OWNER are about a visitor, so
     *                              hitting Reply on them has to reach that
     *                              visitor. Left at the organizer mailbox they
     *                              replied to the site's own inbox, which is
     *                              nobody. Client-bound mail keeps the
     *                              organizer so replies come to Firefly.
     */
    function ffc_booking_send_ics_mail( $to, $subject, $html, $ics, $method = 'REQUEST', $reply_to = null ) {
        $tmp = wp_tempnam( 'invite.ics' );
        file_put_contents( $tmp, $ics );
        // wp_mail names the attachment after the file; Gmail keys off .ics.
        $named = dirname( $tmp ) . '/invite-' . wp_generate_password( 6, false ) . '.ics';
        rename( $tmp, $named );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . ffc_booking_from_name() . ' <' . ffc_booking_organizer_email() . '>',
            'Reply-To: ' . ( $reply_to && is_email( $reply_to ) ? $reply_to : ffc_booking_organizer_email() ),
        );
        $sent = wp_mail( $to, $subject, $html, $headers, array( $named ) );
        @unlink( $named );
        return $sent;
    }

    function ffc_booking_send_confirmation_emails( $apt ) {
        $tz    = wp_timezone();
        $when  = ( new DateTimeImmutable( $apt->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
        $nice  = $when->format( 'l, F j \a\t g:i A T' );
        $ics   = ffc_booking_appointment_ics( $apt, 'REQUEST' );
        $meet  = ffc_booking_meeting_url();

        // The /request-an-appointment page promises a link in this email, so it
        // gets its own paragraph rather than being buried in the attachment.
        $join_html = $meet
            ? '<p><strong>Join the call:</strong> <a href="' . esc_url( $meet ) . '">' . esc_html( $meet ) . '</a></p>'
            : '';

        $client_html = "
            <html><body>
                <p>Hi " . esc_html( $apt->name ) . ",</p>
                <p>Your <strong>" . esc_html( $apt->type_title ) . "</strong> with " . esc_html( ffc_booking_from_name() ) . " is booked for
                   <strong>{$nice}</strong>.</p>
                {$join_html}
                <p>The attached invite adds it to your calendar automatically in most mail apps.
                   Need to change it? Just reply to this email.</p>
                <p>" . esc_html( ffc_booking_from_name() ) . "</p>
            </body></html>";
        ffc_booking_send_ics_mail( $apt->email, 'Booked: ' . $apt->type_title . ' on ' . $when->format( 'M j' ), $client_html, $ics );

        $admin_html = "
            <html><body>
                <p><strong>New booking:</strong> {$nice}</p>
                <p>
                    <strong>Name:</strong> " . esc_html( $apt->name ) . "<br>
                    <strong>Email:</strong> " . esc_html( $apt->email ) . "<br>" .
                    ( $apt->phone ? '<strong>Phone:</strong> ' . esc_html( $apt->phone ) . '<br>' : '' ) .
                    ( $apt->notes ? '<strong>Notes:</strong> ' . nl2br( esc_html( $apt->notes ) ) . '<br>' : '' ) . "
                </p>
                {$join_html}
                <p>The attached invite drops it onto the calendar.</p>
            </body></html>";
        // Owner copy carries the owner as an ATTENDEE, so Workspace files it on
        // their calendar rather than attaching a file they must open.
        $owner_ics = ffc_booking_appointment_ics( $apt, 'REQUEST', true );
        ffc_booking_send_ics_mail( ffc_booking_owner_email(), $apt->name . ' booked a ' . $apt->type_title, $admin_html, $owner_ics, 'REQUEST', $apt->email );
    }

    function ffc_booking_send_cancellation_emails( $apt ) {
        $tz   = wp_timezone();
        $when = ( new DateTimeImmutable( $apt->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
        $nice = $when->format( 'l, F j \a\t g:i A T' );
        $ics  = ffc_booking_appointment_ics( $apt, 'CANCEL' );
        // "Book again any time" with no link made the reader go hunt for the
        // page. Same filter the quote form and nav CTA resolve, so the
        // destination stays in one place and no domain is hardcoded.
        $book_url = apply_filters( 'firefly_discovery_call_url', home_url( '/request-an-appointment/' ) );

        $html = "
            <html><body>
                <p>Hi " . esc_html( $apt->name ) . ",</p>
                <p>Your <strong>" . esc_html( $apt->type_title ) . "</strong> on <strong>{$nice}</strong> has been cancelled.</p>
                <p>The attached update removes it from your calendar. If you'd like a new time,
                   <a href=\"" . esc_url( $book_url ) . "\">pick one here</a>, or just reply to this email.</p>
                <p>" . esc_html( ffc_booking_from_name() ) . "</p>
            </body></html>";
        ffc_booking_send_ics_mail( $apt->email, 'Cancelled: ' . $apt->type_title . ' on ' . $when->format( 'M j' ), $html, $ics, 'CANCEL' );

        $admin_html = '<html><body><p>Cancelled: ' . esc_html( $apt->name ) . ", {$nice}. Attached update clears the calendar.</p></body></html>";
        $owner_ics  = ffc_booking_appointment_ics( $apt, 'CANCEL', true );
        ffc_booking_send_ics_mail( ffc_booking_owner_email(), 'Cancelled: ' . $apt->name . ', ' . $when->format( 'M j' ), $admin_html, $owner_ics, 'CANCEL', $apt->email );
    }

    // =========================================================================
    // Google busy sync (inbound, busy times only)
    // =========================================================================

    /**
     * Parse an iCalendar text into busy [startTs, endTs] intervals within the
     * window. Handles plain events, all-day DATE events, TZID-parameterised
     * times, EXDATE, and expands DAILY/WEEKLY RRULEs. Anything it cannot
     * expand (MONTHLY/YEARLY rules) is counted in 'skipped' and surfaced in
     * the admin, never silently dropped.
     */
    function ffc_booking_parse_busy_ics( $text, $win_start, $win_end ) {
        // Unfold continuation lines (CRLF + single space/tab).
        $text  = preg_replace( '/\r\n[ \t]/', '', str_replace( "\r\n", "\r\n", $text ) );
        $lines = preg_split( '/\r\n|\n|\r/', $text );

        $intervals = array();
        $skipped   = 0;
        $ev        = null;

        $parse_dt = function ( $prop_params, $value ) {
            // VALUE=DATE (all-day) → midnight in site tz.
            if ( stripos( $prop_params, 'VALUE=DATE' ) !== false || preg_match( '/^\d{8}$/', $value ) ) {
                $d = DateTimeImmutable::createFromFormat( 'Ymd', substr( $value, 0, 8 ), wp_timezone() );
                return $d ? $d->setTime( 0, 0 )->getTimestamp() : null;
            }
            if ( substr( $value, -1 ) === 'Z' ) {
                $d = DateTimeImmutable::createFromFormat( 'Ymd\THis\Z', $value, new DateTimeZone( 'UTC' ) );
                return $d ? $d->getTimestamp() : null;
            }
            $tz = wp_timezone();
            if ( preg_match( '/TZID=([^;:]+)/i', $prop_params, $m ) ) {
                try { $tz = new DateTimeZone( trim( $m[1], '"' ) ); } catch ( Exception $e ) { /* fall back to site tz */ }
            }
            $d = DateTimeImmutable::createFromFormat( 'Ymd\THis', $value, $tz );
            return $d ? $d->getTimestamp() : null;
        };

        foreach ( $lines as $line ) {
            if ( 'BEGIN:VEVENT' === $line ) {
                $ev = array( 'exdates' => array() );
                continue;
            }
            if ( null === $ev ) continue;

            if ( 'END:VEVENT' === $line ) {
                // Free/tentative events don't block: TRANSP:TRANSPARENT is how
                // Google marks "Free", CANCELLED is gone entirely.
                $transparent = isset( $ev['TRANSP'] ) && 'TRANSPARENT' === strtoupper( $ev['TRANSP'] );
                $cancelled   = isset( $ev['STATUS'] ) && 'CANCELLED' === strtoupper( $ev['STATUS'] );

                if ( ! $transparent && ! $cancelled && isset( $ev['start_ts'] ) ) {
                    $dur = isset( $ev['end_ts'] ) ? max( 0, $ev['end_ts'] - $ev['start_ts'] ) : 3600;

                    if ( empty( $ev['RRULE'] ) ) {
                        if ( $ev['start_ts'] < $win_end && $ev['start_ts'] + $dur > $win_start ) {
                            $intervals[] = array( $ev['start_ts'], $ev['start_ts'] + $dur );
                        }
                    } else {
                        parse_str( str_replace( ';', '&', $ev['RRULE'] ), $r );
                        $freq = strtoupper( $r['FREQ'] ?? '' );
                        if ( ! in_array( $freq, array( 'DAILY', 'WEEKLY' ), true ) ) {
                            $skipped++;
                        } else {
                            $interval = max( 1, (int) ( $r['INTERVAL'] ?? 1 ) );
                            $count    = isset( $r['COUNT'] ) ? (int) $r['COUNT'] : null;
                            $until    = null;
                            if ( isset( $r['UNTIL'] ) ) {
                                $u = DateTimeImmutable::createFromFormat(
                                    strlen( $r['UNTIL'] ) > 8 ? 'Ymd\THis\Z' : 'Ymd',
                                    $r['UNTIL'], new DateTimeZone( 'UTC' )
                                );
                                if ( $u ) $until = $u->getTimestamp();
                            }
                            $bydays = array();
                            if ( 'WEEKLY' === $freq && ! empty( $r['BYDAY'] ) ) {
                                $map = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
                                foreach ( explode( ',', $r['BYDAY'] ) as $bd ) {
                                    if ( isset( $map[ $bd ] ) ) $bydays[] = $map[ $bd ];
                                }
                            }

                            $tz   = wp_timezone();
                            $cur  = ( new DateTimeImmutable( '@' . $ev['start_ts'] ) )->setTimezone( $tz );
                            $made = 0;
                            for ( $i = 0; $i < 500; $i++ ) {
                                $ts = $cur->getTimestamp();
                                if ( $ts > $win_end ) break;
                                if ( null !== $until && $ts > $until ) break;
                                if ( null !== $count && $made >= $count ) break;

                                $occurs = ( 'DAILY' === $freq )
                                    ? ( 0 === ( $i % $interval ) )
                                    : ( ( empty( $bydays ) ? (int) $cur->format( 'N' ) === (int) ( new DateTimeImmutable( '@' . $ev['start_ts'] ) )->setTimezone( $tz )->format( 'N' )
                                                           : in_array( (int) $cur->format( 'N' ), $bydays, true ) )
                                        && 0 === ( intdiv( (int) floor( ( $ts - $ev['start_ts'] ) / 86400 ), 7 ) % $interval ) );

                                if ( $occurs ) {
                                    $made++;
                                    if ( ! in_array( $ts, $ev['exdates'], true ) && $ts < $win_end && $ts + $dur > $win_start ) {
                                        $intervals[] = array( $ts, $ts + $dur );
                                    }
                                }
                                $cur = $cur->modify( '+1 day' );
                            }
                        }
                    }
                }
                $ev = null;
                continue;
            }

            if ( ! preg_match( '/^([A-Z\-]+)([;][^:]*)?:(.*)$/', $line, $m ) ) continue;
            $prop = $m[1]; $params = $m[2] ?? ''; $value = $m[3];

            switch ( $prop ) {
                case 'DTSTART': $ev['start_ts'] = $parse_dt( $params, $value ); break;
                case 'DTEND':   $ev['end_ts']   = $parse_dt( $params, $value ); break;
                case 'RRULE':   $ev['RRULE']    = $value; break;
                case 'STATUS':  $ev['STATUS']   = $value; break;
                case 'TRANSP':  $ev['TRANSP']   = $value; break;
                case 'EXDATE':
                    foreach ( explode( ',', $value ) as $x ) {
                        $ts = $parse_dt( $params, trim( $x ) );
                        if ( $ts ) $ev['exdates'][] = $ts;
                    }
                    break;
            }
        }
        return array( 'intervals' => $intervals, 'skipped' => $skipped );
    }

    function ffc_booking_google_busy_sync() {
        $url = trim( (string) get_option( FFC_BOOKING_OPT_GOOGLE_URL, '' ) );
        if ( '' === $url ) return array( 'ok' => false, 'error' => 'No Google iCal URL configured.' );

        $res  = wp_remote_get( $url, array( 'timeout' => 15 ) );
        $code = is_wp_error( $res ) ? 0 : wp_remote_retrieve_response_code( $res );

        if ( is_wp_error( $res ) || 200 !== $code ) {
            $err = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;

            /* A 404 here is nearly always the same mistake: Google's settings
             * page offers a PUBLIC address (…/public/basic.ics) right above the
             * SECRET one (…/private-<hash>/basic.ics), and the public one only
             * resolves for a calendar shared publicly. Name the fix instead of
             * making them decode a status code. */
            if ( 404 === $code ) {
                if ( false !== strpos( $url, '/public/' ) ) {
                    $err = 'That is the “Public address”, which only works if the calendar is shared publicly. '
                         . 'Use the “Secret address in iCal format” just below it — its URL contains /private-';
                } elseif ( false === strpos( $url, '/private-' ) ) {
                    $err = 'Google returned 404. A working secret iCal URL contains /private- and ends in basic.ics — '
                         . 'check you copied the “Secret address in iCal format”.';
                } else {
                    $err = 'Google returned 404 for that secret address. It may have been regenerated — copy it again from '
                         . 'Google Calendar → Settings → your calendar → Integrate calendar.';
                }
            }
            update_option( FFC_BOOKING_OPT_GOOGLE_CACHE, array_merge(
                (array) get_option( FFC_BOOKING_OPT_GOOGLE_CACHE, array() ),
                array( 'error' => $err, 'fetched_at' => time() )
            ), 'no' );
            return array( 'ok' => false, 'error' => $err );
        }

        $conf   = ffc_booking_get_availability();
        $parsed = ffc_booking_parse_busy_ics(
            wp_remote_retrieve_body( $res ),
            time() - 86400,
            time() + ( $conf['horizon_days'] + 7 ) * 86400
        );

        update_option( FFC_BOOKING_OPT_GOOGLE_CACHE, array(
            'intervals'  => $parsed['intervals'],
            'skipped'    => $parsed['skipped'],
            'fetched_at' => time(),
            'error'      => '',
        ), 'no' );

        return array( 'ok' => true, 'count' => count( $parsed['intervals'] ), 'skipped' => $parsed['skipped'] );
    }

    add_action( 'ffc_google_busy_sync_event', 'ffc_booking_google_busy_sync' );
    add_action( 'init', function () {
        if ( ! wp_next_scheduled( 'ffc_google_busy_sync_event' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'ffc_google_busy_sync_event' );
        }
    } );

    // =========================================================================
    // REST — public
    // =========================================================================

    add_action( 'rest_api_init', function () {

        // Slot availability for a month. Public by design: it exposes nothing
        // but the same free/busy grid the page shows.
        register_rest_route( 'custom-api/v1', '/booking-slots', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $req ) {
                $month = $req->get_param( 'month' );
                if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $month, $m ) ) {
                    return new WP_Error( 'bad_month', 'month must be YYYY-MM', array( 'status' => 400 ) );
                }
                $type  = ffc_booking_get_type();
                $slots = ffc_booking_month_slots( (int) $m[1], (int) $m[2] );

                $days = array();
                foreach ( $slots as $date => $list ) {
                    $days[ $date ] = array_map( function ( $dt ) { return $dt->format( DateTimeInterface::ATOM ); }, $list );
                }
                return rest_ensure_response( array(
                    'timezone' => wp_timezone_string(),
                    'type'     => $type ? array( 'title' => $type->title, 'duration' => (int) $type->duration_min ) : null,
                    'days'     => $days,
                ) );
            },
        ) );

        // Create a booking.
        register_rest_route( 'custom-api/v1', '/book-appointment', array(
            'methods'             => 'POST',
            'permission_callback' => 'verify_rest_nonce',
            'callback'            => 'ffc_booking_create_appointment',
        ) );

        // The subscribable feed. Token-gated inside so the route itself is open.
        register_rest_route( 'custom-api/v1', '/bookings.ics', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $req ) {
                $token = (string) $req->get_param( 'token' );
                if ( ! $token || ! hash_equals( (string) get_option( FFC_BOOKING_OPT_FEED_TOKEN ), $token ) ) {
                    return new WP_Error( 'forbidden', 'Bad token', array( 'status' => 403 ) );
                }
                // REST always JSON-encodes returned values, so serve raw and exit.
                nocache_headers();
                header( 'Content-Type: text/calendar; charset=utf-8' );
                header( 'Content-Disposition: inline; filename="firefly-bookings.ics"' );
                echo ffc_booking_feed_ics();
                exit;
            },
        ) );
    } );

    function ffc_booking_create_appointment( WP_REST_Request $req ) {
        global $wpdb;

        $name  = sanitize_text_field( $req->get_param( 'name' ) ?? '' );
        $email = sanitize_email( $req->get_param( 'email' ) ?? '' );
        $phone = sanitize_text_field( $req->get_param( 'phone' ) ?? '' );
        $notes = sanitize_textarea_field( $req->get_param( 'notes' ) ?? '' );
        $start = sanitize_text_field( $req->get_param( 'start' ) ?? '' );

        if ( '' === $name || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_input', __( 'Please provide your name and a valid email.' ), array( 'status' => 400 ) );
        }

        $type = ffc_booking_get_type();
        if ( ! $type ) {
            return new WP_Error( 'no_type', __( 'Booking is not configured yet.' ), array( 'status' => 500 ) );
        }

        // The slot must be one the engine would offer RIGHT NOW — one check
        // covers malformed input, lead time, horizon, blocks, Google busy and
        // double-booking in a single pass.
        try {
            $req_dt = new DateTimeImmutable( $start );
        } catch ( Exception $e ) {
            return new WP_Error( 'bad_start', __( 'That time could not be read.' ), array( 'status' => 400 ) );
        }
        $req_dt_local = $req_dt->setTimezone( wp_timezone() );

        $month_slots = ffc_booking_month_slots( (int) $req_dt_local->format( 'Y' ), (int) $req_dt_local->format( 'n' ) );
        $day_key     = $req_dt_local->format( 'Y-m-d' );
        $offered     = false;
        foreach ( $month_slots[ $day_key ] ?? array() as $slot ) {
            if ( $slot->getTimestamp() === $req_dt->getTimestamp() ) { $offered = true; break; }
        }
        if ( ! $offered ) {
            return new WP_Error( 'slot_gone', __( 'That time just became unavailable. Please pick another.' ), array( 'status' => 409 ) );
        }

        $start_utc = gmdate( 'Y-m-d H:i:s', $req_dt->getTimestamp() );
        $end_utc   = gmdate( 'Y-m-d H:i:s', $req_dt->getTimestamp() + $type->duration_min * 60 );

        $apts = $wpdb->prefix . FFC_BOOKING_APTS_TABLE;
        $wpdb->insert( $apts, array(
            'uid'          => 'ffc-apt-' . wp_generate_uuid4() . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
            'type_id'      => (int) $type->id,
            'name'         => $name,
            'email'        => $email,
            'phone'        => $phone ?: null,
            'notes'        => $notes ?: null,
            'starts_at'    => $start_utc,
            'ends_at'      => $end_utc,
            'status'       => 'confirmed',
            'sequence'     => 0,
            'manage_token' => generateToken( 32 ),
        ), array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ) );
        $id = (int) $wpdb->insert_id;

        // Race guard: if two requests won the same slot, the later insert
        // yields and the visitor is asked to repick — losing a row beats
        // double-booking a human.
        $winner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(id) FROM {$apts} WHERE status='confirmed' AND starts_at < %s AND ends_at > %s",
            $end_utc, $start_utc
        ) );
        if ( $winner !== $id ) {
            $wpdb->delete( $apts, array( 'id' => $id ), array( '%d' ) );
            return new WP_Error( 'slot_gone', __( 'That time was just taken. Please pick another.' ), array( 'status' => 409 ) );
        }

        $apt = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$apts} WHERE id = %d", $id ) );
        $apt->type_title = $type->title;

        ffc_booking_send_confirmation_emails( $apt );

        $tz   = wp_timezone();
        $when = ( new DateTimeImmutable( $apt->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );

        return rest_ensure_response( array(
            'message' => __( 'Booked!' ),
            'when'    => $when->format( 'l, F j \a\t g:i A T' ),
            // Handed back so the page can offer a direct .ics download too.
            'ics'     => ffc_booking_appointment_ics( $apt, 'REQUEST' ),
        ) );
    }

    // =========================================================================
    // REST — admin (cookie auth via X-WP-Nonce, then capability check)
    // =========================================================================

    function ffc_booking_admin_permission() {
        return current_user_can( 'manage_options' );
    }

    add_action( 'rest_api_init', function () {
        $ns = 'custom-api/v1';

        register_rest_route( $ns, '/booking-admin/overview', array(
            'methods'             => 'GET',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function () {
                global $wpdb;
                $apts   = $wpdb->prefix . FFC_BOOKING_APTS_TABLE;
                $types  = $wpdb->prefix . FFC_BOOKING_TYPES_TABLE;
                $blocks = $wpdb->prefix . FFC_BOOKING_BLOCKS_TABLE;
                $tz     = wp_timezone();

                $upcoming = array_map( function ( $r ) use ( $tz ) {
                    $s = ( new DateTimeImmutable( $r->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
                    $r->local = $s->format( 'D M j, g:i A' );
                    return $r;
                }, $wpdb->get_results( $wpdb->prepare(
                    "SELECT a.id, a.name, a.email, a.phone, a.notes, a.starts_at, a.status, t.title AS type_title
                     FROM {$apts} a LEFT JOIN {$types} t ON t.id = a.type_id
                     WHERE a.ends_at > %s ORDER BY a.starts_at ASC LIMIT 100",
                    gmdate( 'Y-m-d H:i:s' )
                ) ) );

                $block_rows = array_map( function ( $r ) use ( $tz ) {
                    $s = ( new DateTimeImmutable( $r->starts_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
                    $e = ( new DateTimeImmutable( $r->ends_at, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
                    $r->local = $s->format( 'M j, g:i A' ) . ' → ' . $e->format( 'M j, g:i A' );
                    return $r;
                }, $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM {$blocks} WHERE ends_at > %s ORDER BY starts_at ASC LIMIT 100",
                    gmdate( 'Y-m-d H:i:s' )
                ) ) );

                $cache = (array) get_option( FFC_BOOKING_OPT_GOOGLE_CACHE, array() );
                return rest_ensure_response( array(
                    'availability' => ffc_booking_get_availability(),
                    'type'         => ffc_booking_get_type(),
                    'upcoming'     => $upcoming,
                    'blocks'       => $block_rows,
                    'timezone'     => wp_timezone_string(),
                    'feed_url'     => add_query_arg( 'token', get_option( FFC_BOOKING_OPT_FEED_TOKEN ), rest_url( 'custom-api/v1/bookings.ics' ) ),
                    'google_url'   => (string) get_option( FFC_BOOKING_OPT_GOOGLE_URL, '' ),
                    'owner_email'  => ffc_booking_owner_email(),
                    'meeting_url'  => ffc_booking_meeting_url(),
                    'busy_status'  => array(
                        'fetched_at' => isset( $cache['fetched_at'] ) ? wp_date( 'M j, g:i A', $cache['fetched_at'] ) : null,
                        'count'      => isset( $cache['intervals'] ) ? count( $cache['intervals'] ) : 0,
                        'skipped'    => (int) ( $cache['skipped'] ?? 0 ),
                        'error'      => (string) ( $cache['error'] ?? '' ),
                    ),
                ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/availability', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                $body = $req->get_json_params();
                // Round-trip through the getter so ONLY well-formed values persist.
                update_option( FFC_BOOKING_OPT_AVAILABILITY, array(
                    'days'         => (array) ( $body['days'] ?? array() ),
                    'lead_hours'   => (int) ( $body['lead_hours'] ?? 24 ),
                    'horizon_days' => (int) ( $body['horizon_days'] ?? 45 ),
                ), 'no' );
                return rest_ensure_response( array( 'saved' => true, 'availability' => ffc_booking_get_availability() ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/blocks', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                global $wpdb;
                $body = $req->get_json_params();
                try {
                    $tz = wp_timezone();
                    $s  = new DateTimeImmutable( (string) ( $body['start'] ?? '' ), $tz );
                    $e  = new DateTimeImmutable( (string) ( $body['end'] ?? '' ), $tz );
                } catch ( Exception $ex ) {
                    return new WP_Error( 'bad_range', 'Unreadable dates', array( 'status' => 400 ) );
                }
                if ( $e <= $s ) return new WP_Error( 'bad_range', 'End must be after start', array( 'status' => 400 ) );

                $wpdb->insert( $wpdb->prefix . FFC_BOOKING_BLOCKS_TABLE, array(
                    'starts_at' => gmdate( 'Y-m-d H:i:s', $s->getTimestamp() ),
                    'ends_at'   => gmdate( 'Y-m-d H:i:s', $e->getTimestamp() ),
                    'reason'    => sanitize_text_field( $body['reason'] ?? '' ) ?: null,
                ), array( '%s', '%s', '%s' ) );
                return rest_ensure_response( array( 'id' => (int) $wpdb->insert_id ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/blocks/(?P<id>\d+)', array(
            'methods'             => 'DELETE',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                global $wpdb;
                $wpdb->delete( $wpdb->prefix . FFC_BOOKING_BLOCKS_TABLE, array( 'id' => (int) $req['id'] ), array( '%d' ) );
                return rest_ensure_response( array( 'deleted' => true ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/appointments/(?P<id>\d+)/cancel', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                global $wpdb;
                $apts  = $wpdb->prefix . FFC_BOOKING_APTS_TABLE;
                $types = $wpdb->prefix . FFC_BOOKING_TYPES_TABLE;
                $apt   = $wpdb->get_row( $wpdb->prepare(
                    "SELECT a.*, t.title AS type_title FROM {$apts} a
                     LEFT JOIN {$types} t ON t.id = a.type_id WHERE a.id = %d", (int) $req['id']
                ) );
                if ( ! $apt || 'confirmed' !== $apt->status ) {
                    return new WP_Error( 'not_found', 'No confirmed appointment with that id', array( 'status' => 404 ) );
                }
                // SEQUENCE must rise for calendars to honour the cancellation.
                $wpdb->update( $apts,
                    array( 'status' => 'cancelled', 'sequence' => (int) $apt->sequence + 1 ),
                    array( 'id' => $apt->id ), array( '%s', '%d' ), array( '%d' )
                );
                $apt->status   = 'cancelled';
                $apt->sequence = (int) $apt->sequence + 1;
                ffc_booking_send_cancellation_emails( $apt );
                return rest_ensure_response( array( 'cancelled' => true ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/google-url', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                $body = $req->get_json_params();
                $url  = esc_url_raw( trim( (string) ( $body['url'] ?? '' ) ) );
                update_option( FFC_BOOKING_OPT_GOOGLE_URL, $url, 'no' );
                if ( '' === $url ) {
                    delete_option( FFC_BOOKING_OPT_GOOGLE_CACHE );
                    return rest_ensure_response( array( 'saved' => true, 'sync' => null ) );
                }
                return rest_ensure_response( array( 'saved' => true, 'sync' => ffc_booking_google_busy_sync() ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/owner-email', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                $body  = $req->get_json_params();
                $email = sanitize_email( (string) ( $body['email'] ?? '' ) );
                if ( '' !== $email && ! is_email( $email ) ) {
                    return new WP_Error( 'bad_email', 'That does not look like an email address', array( 'status' => 400 ) );
                }
                update_option( FFC_BOOKING_OPT_OWNER_EMAIL, $email, 'no' );
                return rest_ensure_response( array( 'saved' => true, 'owner_email' => ffc_booking_owner_email() ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/meeting-url', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function ( WP_REST_Request $req ) {
                $body = $req->get_json_params();
                $url  = trim( (string) ( $body['url'] ?? '' ) );
                if ( '' !== $url ) {
                    $url = esc_url_raw( $url );
                    // A room link that isn't a URL would be emailed to clients
                    // as-is and silently fail to open, so reject it here.
                    if ( '' === $url || ! wp_http_validate_url( $url ) ) {
                        return new WP_Error( 'bad_url', 'That does not look like a valid meeting link', array( 'status' => 400 ) );
                    }
                }
                update_option( FFC_BOOKING_OPT_MEETING_URL, $url, 'no' );
                return rest_ensure_response( array( 'saved' => true, 'meeting_url' => ffc_booking_meeting_url() ) );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/busy-sync', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function () {
                return rest_ensure_response( ffc_booking_google_busy_sync() );
            },
        ) );

        register_rest_route( $ns, '/booking-admin/feed-token', array(
            'methods'             => 'POST',
            'permission_callback' => 'ffc_booking_admin_permission',
            'callback'            => function () {
                // Rotating the token cuts off every subscribed calendar — the
                // admin UI warns before calling this.
                update_option( FFC_BOOKING_OPT_FEED_TOKEN, generateToken( 32 ), 'no' );
                return rest_ensure_response( array(
                    'feed_url' => add_query_arg( 'token', get_option( FFC_BOOKING_OPT_FEED_TOKEN ), rest_url( 'custom-api/v1/bookings.ics' ) ),
                ) );
            },
        ) );
    } );

    // =========================================================================
    // Admin page
    // =========================================================================

    /**
     * Directory these admin assets actually live in.
     *
     * NOT FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE: in this fork that constant is
     * 'firefly' (the v1 template), while this model and its assets ship under
     * the ACTIVE template — using the default 404'd every file. The
     * Submissions admin gets away with the same pattern only because its CSS
     * happens to exist in both directories.
     *
     * Resolve the ACTIVE template instead, and fall back to this model's own
     * directory name, which is by definition where its assets are.
     */
    function ffc_booking_assets_template() {
        $active = get_option( FIREFLY_COLLECTIVE_TEMPLATE_OPTION, '' );
        $mine   = basename( dirname( dirname( __FILE__ ) ) );

        if ( $active ) {
            $dir = plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . $active . '/assets/js/bookings-admin.js';
            if ( file_exists( $dir ) ) return $active;
        }
        return $mine;
    }

    function enqueue_bookings_styles_and_scripts( $hook ) {
        if ( 'toplevel_page_my-bookings' !== $hook ) return;

        $plugin_path   = plugin_dir_url( dirname( dirname( __FILE__ ) ) );
        $template_name = ffc_booking_assets_template();
        $unique_id     = uniqid();

        wp_enqueue_script( 'vue-js', VUE_REMOTE_CORE, array(), null, true );

        // submissions.css carries the shared ffc-* admin table/badge/modal
        // styles; bookings-admin.css adds only what this page needs on top.
        wp_enqueue_style( 'submissions-css', $plugin_path . $template_name . '/assets/css/submissions.css', array(), $unique_id );
        wp_enqueue_style( 'bookings-admin-css', $plugin_path . $template_name . '/assets/css/bookings-admin.css', array(), $unique_id );
        wp_enqueue_script( 'bookings-admin-js', $plugin_path . $template_name . '/assets/js/bookings-admin.js', array( 'vue-js' ), $unique_id, true );

        wp_localize_script( 'bookings-admin-js', 'bookingsData', array(
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'api_url' => get_rest_url( null, 'custom-api/v1/' ),
        ) );
    }
    add_action( 'admin_enqueue_scripts', 'enqueue_bookings_styles_and_scripts' );

    function firefly_collective_add_bookings_link() {
        add_menu_page(
            'Bookings',
            'Bookings',
            'manage_options',
            'my-bookings',
            'firefly_collective_bookings_dashboard',
            'dashicons-calendar'
        );
    }
    add_action( 'admin_menu', 'firefly_collective_add_bookings_link' );

    function firefly_collective_bookings_dashboard() {
        include plugin_dir_path( dirname( __FILE__ ) ) . 'views/my-bookings.php';
    }
