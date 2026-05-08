// plugin/assets/js/dashboard.js

(function() {
    // ---------- SVG icon picker for price-calculator feature cards ----------
    // Maps a feature.featureName to an inline SVG marker so each feature-type
    // card reads as a configured product, not raw form fields. Keywords are
    // matched lowercase substring; falls through to a generic "layers" icon.
    var FEATURE_ICONS = {
        membership: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m11.42 2.86-1.81 3.66-4.05.59c-.73.1-1.02.99-.49 1.5l2.93 2.85-.69 4.02c-.13.72.63 1.27 1.28.93l3.61-1.9 3.61 1.9c.65.34 1.41-.21 1.28-.93l-.69-4.02 2.93-2.85c.53-.51.24-1.4-.49-1.5l-4.05-.59-1.81-3.66a.832.832 0 0 0-1.49 0Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        member: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12C14.21 12 16 10.21 16 8C16 5.79 14.21 4 12 4C9.79 4 8 5.79 8 8C8 10.21 9.79 12 12 12ZM12 14C9.33 14 4 15.34 4 18V20H20V18C20 15.34 14.67 14 12 14Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>',
        subscription: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M21 12a9 9 0 1 1-3.75-7.31M21 4v5h-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        plan: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M9 12h6M9 16h4M7 4h7l5 5v9a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        addon: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        addons: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
        booking: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v3M16 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        appointment: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v3M16 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        donation: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        donate: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s-7-4.5-7-10a4 4 0 0 1 7-2.5A4 4 0 0 1 19 11c0 5.5-7 10-7 10Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        product: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        service: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        bonus: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M20 12v10H4V12M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7Zm0 0h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    };
    var DEFAULT_FEATURE_ICON = '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function pickFeatureIcon(name) {
        var key = String(name || '').toLowerCase();
        for (var k in FEATURE_ICONS) {
            if (key.indexOf(k) !== -1) return FEATURE_ICONS[k];
        }
        return DEFAULT_FEATURE_ICON;
    }

    // ---------- SVG icon picker for option-tile cards ----------
    // Maps an option.optionName to an inline SVG. Defaults to whatever the
    // parent feature picks, so option tiles stay visually unified.
    var OPTION_ICONS = {
        free:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/></svg>',
        starter:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/></svg>',
        basic:      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/></svg>',
        plus:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        pro:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        premium:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8l4 5 5-7 5 7 4-5v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        enterprise: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8l4 5 5-7 5 7 4-5v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        gold:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8l4 5 5-7 5 7 4-5v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        team:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-8 0a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2 20v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1m0-1a4 4 0 0 1 4-4h2a4 4 0 0 1 4 4v1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        cohort:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M16 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-8 0a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7ZM2 20v-1a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v1m0-1a4 4 0 0 1 4-4h2a4 4 0 0 1 4 4v1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        solo:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 5v1h14v-1c0-3.3-3.7-5-7-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        self:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 5v1h14v-1c0-3.3-3.7-5-7-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    };

    function pickOptionIcon(option, feature) {
        var key = String((option && option.optionName) || '').toLowerCase();
        for (var k in OPTION_ICONS) {
            if (key.indexOf(k) !== -1) return OPTION_ICONS[k];
        }
        // Fall back to the parent feature's icon so the tiles still feel
        // typed even when option names are bespoke.
        return pickFeatureIcon((feature && feature.featureName) || (option && option.optionName) || '');
    }

    // ---------- SVG icon picker for addon cards ----------
    // Priority: addon.addOnMetric → keyword in addonName → default plus.
    var ADDON_ICONS = {
        perk:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        bonus:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        service:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        support:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        consult:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        upgrade:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 19V5M5 12l7-7 7 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        boost:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 19V5M5 12l7-7 7 7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        extra:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        feature:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        package:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7l9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        kit:      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 7l9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        time:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        hour:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        session:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 4h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 13l5 2v3a2 2 0 0 1-2 2A14 14 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        call:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 4h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 13l5 2v3a2 2 0 0 1-2 2A14 14 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        meeting:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 4h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 13l5 2v3a2 2 0 0 1-2 2A14 14 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        file:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 3v5h5M9 13h6M9 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        download: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4v11m0 0 4-4m-4 4-4-4M5 19h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        template: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M14 3v5h5M9 13h6M9 17h4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        course:   '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2V4Zm20 0h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8V4Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        program:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H2V4Zm20 0h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8V4Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        research: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 20h16M7 17v-7M12 17V5M17 17v-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        report:   '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 20h16M7 17v-7M12 17V5M17 17v-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        addon:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>'
    };
    var DEFAULT_ADDON_ICON = '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

    function pickAddonIcon(addon) {
        var metric = String((addon && addon.addOnMetric) || '').toLowerCase();
        if (metric && ADDON_ICONS[metric]) return ADDON_ICONS[metric];
        var nameKey = String((addon && addon.addonName) || '').toLowerCase();
        for (var k in ADDON_ICONS) {
            if (nameKey.indexOf(k) !== -1) return ADDON_ICONS[k];
        }
        return DEFAULT_ADDON_ICON;
    }

    // ---------- SVG icon picker for user-input field labels ----------
    // Returns null when no keyword matches — caller renders the label
    // without an icon rather than forcing one.
    var USERFIELD_ICONS = {
        date:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v3M16 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        birthday:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v3M16 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        anniversary: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 2v3M16 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        name:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 5v1h14v-1c0-3.3-3.7-5-7-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        firstname:   '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 5v1h14v-1c0-3.3-3.7-5-7-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        lastname:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-7 1.7-7 5v1h14v-1c0-3.3-3.7-5-7-5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        email:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M4 6h16v12H4z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        phone:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 4h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 13l5 2v3a2 2 0 0 1-2 2A14 14 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        tel:         '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 4h3l2 5-2.5 1.5a11 11 0 0 0 5 5L14 13l5 2v3a2 2 0 0 1-2 2A14 14 0 0 1 3 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        address:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>',
        city:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>',
        zip:         '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>',
        notes:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m4 20 4-1 11-11-3-3L5 16l-1 4Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        message:     '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m4 20 4-1 11-11-3-3L5 16l-1 4Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        comments:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m4 20 4-1 11-11-3-3L5 16l-1 4Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        focus:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="4" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>'
    };

    function pickUserFieldIcon(label) {
        var key = String(label || '').toLowerCase().replace(/[^a-z]/g, '');
        if (!key) return null;
        for (var k in USERFIELD_ICONS) {
            if (key.indexOf(k) !== -1) return USERFIELD_ICONS[k];
        }
        return null;
    }

    // ---------- Utility icons ----------
    // Direct lookup, used by stepper, toggles, alerts, instance-delete,
    // threshold-track, etc. No picker — call UTILITY_ICONS.x directly.
    var UTILITY_ICONS = {
        check:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m5 12 5 5 9-11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        circle:       '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/></svg>',
        circleCheck:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" fill="currentColor"/><path d="m8 12 3 3 5-6" stroke="#0a0a0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        plus:         '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        minus:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M5 12h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        x:            '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
        warning:      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4 2 20h20L12 4Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 10v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="17" r="0.9" fill="currentColor"/></svg>',
        info:         '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 11v5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="12" cy="8" r="0.9" fill="currentColor"/></svg>',
        chevronUp:    '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m6 14 6-6 6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        chevronDown:  '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="m6 10 6 6 6-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        arrowUpRight: '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 17 17 7M9 7h8v8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        crown:        '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3 8l4 5 5-7 5 7 4-5v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        sparkle:      '<svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>'
    };

    // Shared dark/amber appearance for every Stripe Elements instance we
    // mount. Stripe's `theme: 'night'` gives a dark base; the variable
    // overrides bring it inline with the Firefly token palette so card-
    // number/expiry labels render off-white on the dark surface.
    function fireflyStripeAppearance() {
        return {
            theme: 'night',
            variables: {
                colorPrimary:    '#f5b544',
                colorBackground: '#0a0a0b',
                colorText:       '#fafaf7',
                colorTextSecondary: 'rgba(250,250,247,0.72)',
                colorTextPlaceholder: 'rgba(250,250,247,0.38)',
                colorDanger:     '#ff7a7a',
                colorIconTab:    '#fafaf7',
                colorIconTabSelected: '#0a0a0b',
                fontFamily:      'Geist, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                fontSizeBase:    '15px',
                spacingUnit:     '4px',
                borderRadius:    '6px'
            },
            rules: {
                '.Tab, .Input, .Block': {
                    backgroundColor: '#141416',
                    border: '1px solid rgba(255,255,255,0.08)',
                    boxShadow: 'none'
                },
                '.Tab:hover, .Input:hover': {
                    borderColor: 'rgba(245,181,68,0.28)'
                },
                '.Tab--selected, .Input:focus': {
                    borderColor: '#f5b544',
                    boxShadow: '0 0 0 1px rgba(245,181,68,0.4)'
                },
                '.Label': {
                    color: 'rgba(250,250,247,0.72)',
                    fontWeight: '500'
                }
            }
        };
    }

    // Store initialization state globally to persist across re-renders
    if (typeof window.dashboardInitialized === 'undefined') {
        window.dashboardInitialized = false;
    }

    // Main initialization function
    window.initializeDashboard = function() {

        // Prevent multiple initializations
        if (window.dashboardInitialized) {
            return;
        }
        
        // Check if we have the required elements
        const featuresContainer = document.getElementById('features-container');
        if (!featuresContainer) {
            console.log('Dashboard elements not found, skipping initialization');
            return;
        }

        window.dashboardInitialized = true;

        if (isPWA || websiteApp) {
            myApi = {
                nonce: window.nonce,
                api_url: window.api_url
            }

            dashboardData = { 
                nonce: window.nonce,
                theme_path: window.theme_path,
                template_path: window.template_path,
                features: window.features,
                stripeKey: window.stripeKey,
                subscription_status: window.subscription_status,
                third_party: window.third_party
            }
        }

        // Disable password reset if from third-party login
        if (typeof dashboardData !== 'undefined' && dashboardData.third_party) {
            const passwordReset = document.querySelector('#reset-password-btn');
            if (passwordReset) {
                passwordReset.style.opacity = '0.3';
                passwordReset.style.pointerEvents = 'none';
                passwordReset.disabled = true;
            }
        }

        // Apply campaign configuration if present
        if (dashboardData.campaign_config) {
            const campaignConfig = dashboardData.campaign_config;
            
            // Store original features before filtering for reference
            const originalFeatures = [...dashboardData.features];
            
            // Filter features based on campaign config
            dashboardData.features = dashboardData.features.filter(feature => {
                if (campaignConfig.features_config[feature.id] && campaignConfig.features_config[feature.id].show) {
                    // Filter options
                    feature.options = feature.options.filter(option => {
                        if (campaignConfig.features_config[feature.id].options[option.id] && 
                            campaignConfig.features_config[feature.id].options[option.id].show) {
                            // Filter addons
                            if (option.addons) {
                                option.addons = option.addons.filter(addon => 
                                    campaignConfig.features_config[feature.id].options[option.id].addons[addon.id]
                                );
                            }
                            return true;
                        }
                        return false;
                    });
                    return feature.options.length > 0;
                }
                return false;
            });
            
            // Clear and rebuild selections object completely
            selections = {};
            
            // Initialize selections for each filtered feature
            dashboardData.features.forEach((feature, fIndex) => {
                selections[fIndex] = [{}]; // Initialize with empty instance
                
                // Apply preselections for this feature
                if (campaignConfig.preselect_config[feature.id]) {
                    const preselect = campaignConfig.preselect_config[feature.id];
                    if (preselect.selectedOption) {
                        // Find the option index in the filtered options
                        const optionIndex = feature.options.findIndex(o => o.id == preselect.selectedOption);
                        if (optionIndex !== -1) {
                            selections[fIndex][0].optionIndex = optionIndex;
                            
                            // Preselect addons
                            if (preselect.selectedAddons && preselect.selectedAddons.length) {
                                selections[fIndex][0].addons = [...preselect.selectedAddons];
                            }
                            
                            // Set quantity if specified
                            if (preselect.quantity && !feature.recurring) {
                                selections[fIndex][0].quantity = parseInt(preselect.quantity) || 1;
                            }
                        }
                    }
                }
            });
            
            saveSelections();
            
            // Hide "Add new" buttons in campaign mode
            setTimeout(() => {
                document.querySelectorAll('.add-new-feature').forEach(btn => {
                    btn.style.display = 'none';
                });
                
                // Also hide delete instance buttons (.instance-delete is
                // the new name; .delete-instance kept for legacy paths).
                document.querySelectorAll('.instance-delete, .delete-instance').forEach(btn => {
                    btn.style.display = 'none';
                });
            }, 100);
        }

        // Handle anonymous user form for campaign mode
        if (dashboardData.campaign_config && !window.auth_id) {
            // Find the campaign head notice and inject the form after it
            const campaignHead = document.getElementById('campaign-head');
            
            if (campaignHead) {
                // Create the conditional anonymous user form
                const anonFormHTML = `
                    <div id="anonymous-user-form">
                        <h3>Contact Information</h3>
                        <div class="anon-field">
                            <input type="text" id="anon-firstName" placeholder="First Name (optional)">
                        </div>
                        <div class="anon-field">
                            <input type="text" id="anon-lastName" placeholder="Last Name (optional)">
                        </div>
                        <div class="anon-field">
                            <input type="email" id="anon-email" placeholder="Email Address (required)" required>
                        </div>
                        <div class="anon-field">
                            <input type="tel" id="anon-phone" placeholder="Phone Number (optional)">
                        </div>
                        <div id="anon-email-error" style="color: red; display: none; margin-top: 5px;">
                            Please enter a valid email address
                        </div>
                        
                        <!-- Account Creation Section (shown/hidden based on recurring features) -->
                        <div id="account-required-section" style="display: none;">
                            <div class="account-required-notice">
                                <strong>Account Required:</strong> Subscription services require an account for management and billing.
                            </div>
                            
                            <!-- Google Sign-in Option -->
                            <div class="account-creation-options">
                                <div class="google-signin-option">
                                    <button type="button" id="campaign-google-signin" class="google-signin-button">
                                        Sign in with Google
                                    </button>
                                    <div class="auth-divider">
                                        <span>or</span>
                                    </div>
                                </div>
                                
                                <!-- Username/Password Fields -->
                                <div class="account-fields" id="manual-account-fields">
                                    <div class="anon-field">
                                        <input type="text" id="anon-username" placeholder="Username (required)" autocomplete="username">
                                        <div id="username-error" style="color: red; display: none; margin-top: 3px; font-size: 12px;"></div>
                                    </div>
                                    <div class="anon-field password-field">
                                        <input type="password" id="anon-password" placeholder="Password (required)" autocomplete="new-password">
                                        <button type="button" class="password-toggle" id="password-toggle">
                                            <span class="password-eye">👁️</span>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Google Account Linked Status (hidden initially) -->
                                <div id="google-account-linked" style="display: none;">
                                    <div class="google-account-status">
                                        <span class="google-icon">✓</span>
                                        <span>Google account linked: <strong id="linked-email"></strong></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                
                // Insert the form after the campaign notice
                campaignHead.insertAdjacentHTML('afterend', anonFormHTML);

                // Form interaction handlers
                const emailInput = document.getElementById('anon-email');
                const emailError = document.getElementById('anon-email-error');
                const usernameField = document.getElementById('anon-username');
                const passwordField = document.getElementById('anon-password');
                const passwordToggle = document.getElementById('password-toggle');
                const usernameError = document.getElementById('username-error');
                const accountSection = document.getElementById('account-required-section');
                const googleSigninBtn = document.getElementById('campaign-google-signin');
                const manualAccountFields = document.getElementById('manual-account-fields');
                const googleLinkedSection = document.getElementById('google-account-linked');
                const linkedEmailSpan = document.getElementById('linked-email');

                // Function to check if current selections have recurring features
                function hasRecurringFeatures() {
                    for (const [fIdx, instances] of Object.entries(selections)) {
                        for (const instance of instances) {
                            if (instance.optionIndex !== undefined) {
                                const feature = dashboardData.features[fIdx];
                                if (feature && feature.recurring) {
                                    return true;
                                }
                            }
                        }
                    }
                    return false;
                }
                
                // Function to update form visibility based on selections
                function updateFormVisibility() {
                    const hasRecurring = hasRecurringFeatures();
                    accountSection.style.display = hasRecurring ? 'block' : 'none';
                    
                    // Update email placeholder and validation
                    if (hasRecurring) {
                        emailInput.placeholder = "Email Address (required - will be used for account)";
                    } else {
                        emailInput.placeholder = "Email Address (required)";
                    }
                    
                    updateOrderButton();
                }

                // Google Sign-in handler
                googleSigninBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    // Set campaign mode flag for auth.js
                    window.campaignMode = true;
                    
                    // Trigger existing Google auth
                    const width = 500;
                    const height = 600;
                    const left = (screen.width - width) / 2;
                    const top = (screen.height - height) / 2;
                    
                    const gapiEndpoint = myApi.gapiDomain || window.location.origin;
                    window.open(
                        `${gapiEndpoint}/wp-json/custom-api/v1/google-auth-init`,
                        'google-signin',
                        `width=${width},height=${height},left=${left},top=${top}`
                    );
                });

                // Function to handle successful Google authentication
                window.handleCampaignGoogleSuccess = function(authData) {
                    googleAuthData = authData;
                    
                    // Update UI to show linked account
                    manualAccountFields.style.display = 'none';
                    googleLinkedSection.style.display = 'block';
                    linkedEmailSpan.textContent = authData.email || 'Unknown email';
                    
                    // Disable the Google sign-in button
                    googleSigninBtn.disabled = true;
                    googleSigninBtn.style.opacity = '0.6';
                    googleSigninBtn.style.cursor = 'not-allowed';
                    googleSigninBtn.textContent = 'Google Account Linked';
                    
                    // Disable contact form fields since info comes from Google
                    const contactFields = ['anon-firstName', 'anon-lastName', 'anon-email', 'anon-phone'];
                    contactFields.forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (field) {
                            field.disabled = true;
                            field.style.opacity = '0.6';
                            field.style.backgroundColor = '#f5f5f5';
                        }
                    });
                    
                    // Add a note about disabled fields
                    const contactNote = document.createElement('div');
                    contactNote.id = 'contact-fields-note';
                    contactNote.style.cssText = `
                        background: #e8f5e8;
                        border: 1px solid #4caf50;
                        border-radius: 4px;
                        padding: 10px;
                        margin-bottom: 15px;
                        color: #2e7d32;
                        font-size: 14px;
                    `;
                    contactNote.innerHTML = '<strong>Note:</strong> Contact information will be automatically filled from your Google account.';
                    
                    // Insert after the contact information heading
                    const contactHeading = document.querySelector('#anonymous-user-form h3');
                    if (contactHeading && contactHeading.nextSibling) {
                        contactHeading.parentNode.insertBefore(contactNote, contactHeading.nextSibling);
                    }
                    
                    // Set the auth_id for order processing
                    window.auth_id = authData.auth_id;
                    
                    // Update order button
                    updateOrderButton();
                };

                // Function to handle manual account creation success (NEW)
                function handleManualAccountSuccess() {
                    // Show success message similar to Google flow
                    setTimeout(() => {
                        const accountMessage = document.createElement('div');
                        accountMessage.style.cssText = `
                            position: fixed;
                            top: 80px;
                            right: 20px;
                            background: #2196F3;
                            color: white;
                            padding: 15px 20px;
                            border-radius: 4px;
                            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                            z-index: 10000;
                            max-width: 300px;
                        `;
                        accountMessage.innerHTML = `
                            <strong>Account Created!</strong><br>
                            Your account has been successfully created and you are now logged in.
                        `;
                        document.body.appendChild(accountMessage);
                        
                        // Remove message after 8 seconds
                        setTimeout(() => {
                            accountMessage.remove();
                        }, 8000);
                    }, 2000);

                    // Update form UI to show account is linked
                    const manualAccountFields = document.getElementById('manual-account-fields');
                    const googleLinkedSection = document.getElementById('google-account-linked');
                    const linkedEmailSpan = document.getElementById('linked-email');
                    
                    if (manualAccountFields && googleLinkedSection) {
                        manualAccountFields.style.display = 'none';
                        googleLinkedSection.style.display = 'block';
                        
                        // Show the email from the form
                        const emailInput = document.getElementById('anon-email');
                        if (emailInput && linkedEmailSpan) {
                            linkedEmailSpan.textContent = emailInput.value;
                        }
                        
                        // Update the status text
                        const statusDiv = googleLinkedSection.querySelector('.google-account-status span:first-child');
                        if (statusDiv) {
                            statusDiv.textContent = '✓';
                        }
                        const statusText = googleLinkedSection.querySelector('.google-account-status span:last-child');
                        if (statusText) {
                            statusText.innerHTML = 'Account created: <strong>' + (linkedEmailSpan.textContent || 'Success') + '</strong>';
                        }
                    }

                    // Disable contact form fields
                    const contactFields = ['anon-firstName', 'anon-lastName', 'anon-email', 'anon-phone'];
                    contactFields.forEach(fieldId => {
                        const field = document.getElementById(fieldId);
                        if (field) {
                            field.disabled = true;
                            field.style.opacity = '0.6';
                            field.style.backgroundColor = '#f5f5f5';
                        }
                    });

                    // Add success note
                    const existingNote = document.getElementById('contact-fields-note');
                    if (!existingNote) {
                        const contactNote = document.createElement('div');
                        contactNote.id = 'contact-fields-note';
                        contactNote.style.cssText = `
                            background: #e8f5e8;
                            border: 1px solid #4caf50;
                            border-radius: 4px;
                            padding: 10px;
                            margin-bottom: 15px;
                            color: #2e7d32;
                            font-size: 14px;
                        `;
                        contactNote.innerHTML = '<strong>Success!</strong> Your account has been created and you are now logged in.';
                        
                        const contactHeading = document.querySelector('#anonymous-user-form h3');
                        if (contactHeading && contactHeading.nextSibling) {
                            contactHeading.parentNode.insertBefore(contactNote, contactHeading.nextSibling);
                        }
                    }
                }

                // Function to check for auth_id changes (detect manual account creation)
                function detectAccountCreation() {
                    // Only run in campaign mode for anonymous users
                    if (!dashboardData.campaign_config || window.auth_id || googleAuthData) {
                        return;
                    }

                    // Function to get auth_id from cookie
                    function getAuthIdFromCookie() {
                        const name = 'auth_id=';
                        const decodedCookie = decodeURIComponent(document.cookie);
                        const ca = decodedCookie.split(';');
                        for(let i = 0; i < ca.length; i++) {
                            let c = ca[i];
                            while (c.charAt(0) === ' ') {
                                c = c.substring(1);
                            }
                            if (c.indexOf(name) === 0) {
                                return c.substring(name.length, c.length);
                            }
                        }
                        return null;
                    }

                    // Check if auth_id cookie appears
                    const currentAuthId = getAuthIdFromCookie();
                    if (currentAuthId && !window.auth_id) {
                        // Account was created! Set the auth_id and trigger success flow
                        window.auth_id = currentAuthId;
                        handleManualAccountSuccess();
                        
                        // Stop checking
                        if (window.accountCreationChecker) {
                            clearInterval(window.accountCreationChecker);
                            window.accountCreationChecker = null;
                        }
                    }
                }

                // Start checking for account creation if in campaign mode
                if (dashboardData.campaign_config && !window.auth_id) {
                    // Check every 2 seconds for auth_id cookie
                    window.accountCreationChecker = setInterval(detectAccountCreation, 2000);
                    
                    // Stop checking after 5 minutes to prevent endless polling
                    setTimeout(() => {
                        if (window.accountCreationChecker) {
                            clearInterval(window.accountCreationChecker);
                            window.accountCreationChecker = null;
                        }
                    }, 300000); // 5 minutes
                }
                
                // Email validation
                async function validateEmail() {
                    const email = emailInput.value.trim();
                    const isValidFormat = email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                    
                    if (email && !isValidFormat) {
                        emailError.textContent = 'Please enter a valid email address';
                        emailError.style.display = 'block';
                        updateOrderButton();
                        return false;
                    }
                    
                    // Only check email exists if we need manual account creation and Google isn't linked
                    if (isValidFormat && hasRecurringFeatures() && !googleAuthData) {
                        try {
                            const response = await fetch(`${myApi.api_url}check-email?email=${encodeURIComponent(email)}`);
                            const data = await response.json();
                            
                            if (data.exists) {
                                emailError.textContent = 'An account with this email already exists. Please log in instead.';
                                emailError.style.display = 'block';
                                updateOrderButton();
                                return false;
                            }
                        } catch (error) {
                            console.error('Email validation error:', error);
                        }
                    }
                    
                    emailError.style.display = 'none';
                    updateOrderButton();
                    return isValidFormat;
                }
                
                // Username validation
                async function validateUsername() {
                    // Skip validation if Google is linked
                    if (googleAuthData) {
                        return true;
                    }
                    
                    const username = usernameField.value.trim();
                    if (!username) {
                        usernameError.style.display = 'none';
                        updateOrderButton();
                        return !hasRecurringFeatures(); // Required only for recurring
                    }
                    
                    try {
                        const response = await fetch(`${myApi.api_url}check-username?username=${encodeURIComponent(username)}`);
                        const data = await response.json();
                        
                        if (data.exists) {
                            usernameError.textContent = 'Username already taken';
                            usernameError.style.display = 'block';
                            updateOrderButton();
                            return false;
                        } else {
                            usernameError.style.display = 'none';
                            updateOrderButton();
                            return true;
                        }
                    } catch (error) {
                        console.error('Username validation error:', error);
                        updateOrderButton();
                        return true; // Allow on error
                    }
                }
                
                // Event listeners
                emailInput.addEventListener('input', () => {
                    clearTimeout(window.emailValidationTimeout);
                    window.emailValidationTimeout = setTimeout(validateEmail, 500);
                });
                emailInput.addEventListener('blur', validateEmail);
                
                // Username validation with debounce
                usernameField.addEventListener('input', () => {
                    clearTimeout(window.usernameValidationTimeout);
                    window.usernameValidationTimeout = setTimeout(validateUsername, 500);
                });
                
                // Password toggle
                passwordToggle.addEventListener('click', function() {
                    const isPassword = passwordField.type === 'password';
                    passwordField.type = isPassword ? 'text' : 'password';
                    this.querySelector('.password-eye').textContent = isPassword ? '🙈' : '👁️';
                });
                
                // Listen for selection changes to update form visibility
                const originalUpdateInvoice = updateInvoice;
                updateInvoice = function() {
                    originalUpdateInvoice.call(this);
                    updateFormVisibility();
                };
                
                // Initial form visibility check
                updateFormVisibility();
                
                // Also trigger validation on other inputs to update button state
                ['anon-firstName', 'anon-lastName', 'anon-phone', 'anon-password'].forEach(id => {
                    const input = document.getElementById(id);
                    if (input) {
                        input.addEventListener('input', updateOrderButton);
                    }
                });
            }
        }

        // Global state: keys are feature type indexes; each value is an array of instance objects.
        // Each instance object: { optionIndex: number, addons: [number, ...], quantity?: number }
        var selections = {};

        // Keeps track of mode
        let estimateMode = false;

        // Store Google auth state
        let googleAuthData = null;

        // Check for corrupt or invalid session data and clean it
        try {
            const orderData = sessionStorage.getItem('placedOrder');
            if (orderData) {
                const orderInfo = JSON.parse(orderData);
                if (!orderInfo || !orderInfo.orderID || (orderInfo.status !== 'pending' && orderInfo.status !== 'paid')) {
                    // If the data doesn't look like a valid order, clear it
                    sessionStorage.removeItem('placedOrder');
                }
            }
        } catch (e) {
            console.error('Error parsing order data on load', e);
            sessionStorage.removeItem('placedOrder');
        }

        if (hasValidOrder()) disableFormInteraction();
        if (hasValidOrder()) {
            disableFormInteraction();
            
            // If the order is already paid, show success immediately
            if (isOrderPaid()) {
                setTimeout(() => {
                    showPaymentSuccess();
                }, 100);
            }
        }

        // Load any saved state from sessionStorage
        const stored = sessionStorage.getItem('priceCalcSelections');
        if ( stored && stored !== 'undefined' ) {
            try {
                selections = JSON.parse( stored );
            } catch(e) {
                console.warn('Corrupt priceCalcSelections, clearing it:', e);
                sessionStorage.removeItem('priceCalcSelections');
                selections = {};
            }
        }

        if (isOrderPaid()) {
            setTimeout(() => {
                disableFormInteraction();
            }, 0);
        }

        const updateModal = document.getElementById('update-payment-modal');

        // Backdrop-click closes the static dashboard modals (cancel-sub
        // wires its own listener inside cancelSubscription, but update-
        // payment is opened from multiple places — install once here).
        if (updateModal) {
            updateModal.addEventListener('click', function(e) {
                if (e.target === updateModal) {
                    closeUpdatePaymentModal();
                }
            });
        }

        const invoiceDetails = document.getElementById('invoice-details');
        const invoiceTotal = document.getElementById('invoice-total');
        const themePath = dashboardData.theme_path;

        // Clear container to prevent duplication.
        if (featuresContainer) featuresContainer.innerHTML = '';

        // Safely parse floats without returning NaN
        function parseSafe(val, fallback = 0) {
            let p = parseFloat(val);
            return isNaN(p) ? fallback : p;
        }

        // Save selections to sessionStorage
        function saveSelections() {
            sessionStorage.setItem('priceCalcSelections', JSON.stringify(selections));
        }

        // Safely extract a description from either an object or string
        function getDescriptionText(desc) {
            if (!desc) return '';
            if (typeof desc === 'object' && desc.text) return desc.text;
            if (typeof desc === 'string') return desc;
            return '';
        }

        function formatFieldLabel(key) {
            // Remove _user/_display suffix
            let text = key.replace(/_user$|_display$/, '');
            
            // Convert camelCase to Title Case With Spaces
            return text
                .replace(/([A-Z])/g, ' $1') // Add space before capital letters
                .replace(/^./, function(str) { return str.toUpperCase(); }) // Capitalize first letter
                .trim();
        }

        // Smooth scroll helper
        function smoothScrollToElement(el, offset = 120) {
            const rect = el.getBoundingClientRect();
            const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
            const targetY = rect.top + scrollTop - offset;
            window.scrollTo({
                top: targetY,
                behavior: 'smooth'
            });
        }

        // Check if an addon uses multiplication.
        function isMultiply(addon) {
            if (addon.priceModifierType && typeof addon.priceModifierType === 'object') {
                return addon.priceModifierType.selected === 1;
            }
            if (typeof addon.priceModifierType === 'string') {
                return addon.priceModifierType.toLowerCase() === 'multiply';
            }
            return false;
        }

        // Determine if an instance (option + selected addons) has any range
        function instanceHasRange(feature, instance) {
            const option = feature.options[instance.optionIndex];
            if (!option) return false;

            // If option has a non-zero floor or ceiling, that's a range
            const floorVal = parseSafe(option.priceFloor);
            const ceilVal  = parseSafe(option.priceCeiling);
            if (floorVal !== 0 || ceilVal !== 0) {
                return true;
            }

            // Check any selected addons for a non-zero floor or ceiling
            if (instance.addons && Array.isArray(instance.addons)) {
                for (let aIndex of instance.addons) {
                    const addon = option.addons[aIndex];
                    if (addon) {
                        const addonFloor = parseSafe(addon.floorPriceMod);
                        const addonCeil  = parseSafe(addon.ceilingPriceMod);
                        if (addonFloor !== 0 || addonCeil !== 0) {
                            return true;
                        }
                    }
                }
            }
            return false;
        }

        function disableFormInteraction() {
            // Disable the features container (the form)
            const featuresContainer = document.getElementById('features-container');
            if (featuresContainer) {
                featuresContainer.style.pointerEvents = 'none';
                featuresContainer.style.opacity = '0.7';
            }

            // Show a viewport-level toast in the top-right corner.
            // Auto-dismisses after a few seconds with a fade.
            showOrderPlacedToast();
        }

        // Pop a one-shot success toast in the top-right of the viewport.
        // Reuses the .ff-toast structure styled in dashboard.css.
        function showOrderPlacedToast() {
            // De-dup if user clicks twice in quick succession.
            const existing = document.getElementById('ff-order-toast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.id = 'ff-order-toast';
            toast.className = 'ff-toast ff-toast-success';
            toast.setAttribute('role', 'status');
            toast.setAttribute('aria-live', 'polite');
            toast.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>Order placed successfully. Pay below.</span>
            `;
            document.body.appendChild(toast);

            // Slide in on next frame.
            requestAnimationFrame(() => toast.classList.add('is-visible'));

            // Fade + slide out, then remove.
            setTimeout(() => {
                toast.classList.remove('is-visible');
                toast.classList.add('is-leaving');
                setTimeout(() => toast.remove(), 400);
            }, 4000);
        }

        function showOrderConfirmation() {
            // Create modal (only once per click)
            const modal = document.createElement('div');
            modal.className = 'order-confirm-modal';
            modal.innerHTML = `
                <div class="order-confirm-content">
                <h3>Confirm Your Order</h3>
                <p>Are you sure you want to place this order?</p>
                <div class="order-confirm-buttons">
                    <button class="confirm-button">Confirm</button>
                    <button class="cancel-button">Cancel</button>
                </div>
                </div>
            `;
            document.body.appendChild(modal);

            // Force reflow and fade-in
            requestAnimationFrame(() => modal.classList.add('active'));

            const cleanup = () => {
                modal.classList.remove('active');
                // remove after fade
                setTimeout(() => modal.remove(), 300);
            };

            // Confirm: once, then cleanup + proceed
            modal.querySelector('.confirm-button')
            .addEventListener('click', () => {
                cleanup();
                submitOrder();
            }, { once: true });

            // Cancel: once, cleanup only
            modal.querySelector('.cancel-button')
                .addEventListener('click', () => cleanup(), { once: true });

            // Click outside content also cancels
            modal.addEventListener('click', e => {
                if (e.target === modal) cleanup();
            }, { once: true });
            }


        function showLoadingOverlay() {
            const overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = `<div class="loading-spinner">
                <img src="${dashboardData.template_path}/images/loading.gif" alt="Loading">
            </div>`;
            document.body.appendChild(overlay);
            
            // Force reflow for animation
            void overlay.offsetWidth;
            overlay.classList.add('active');
            return overlay;
        }

        function hideLoadingOverlay(overlay) {
            overlay.classList.remove('active');
            setTimeout(() => overlay.remove(), 300);
        }

        async function submitOrder() {
            const overlay = showLoadingOverlay();
            
            // Find all selections that have an optionIndex
            let orderItems = [];
            let foundSelections = false;

            // Extract data from selections
            for (const [fIdx, instances] of Object.entries(selections)) {
                for (const instance of instances) {
                    if (instance.optionIndex !== undefined) {
                        // Only count as a valid selection if it points to a real option
                        const feature = dashboardData.features[fIdx];
                        if (feature && feature.options && feature.options[instance.optionIndex]) {
                            const option = feature.options[instance.optionIndex];
                            
                            // Use actual database IDs instead of array indices
                            const orderItem = {
                                featureId: feature.id,
                                optionId: option.id,
                                addonIds: instance.addons || [],
                                userData: {},
                                clientCalculatedPrice: calculateInstancePrice(feature, instance)
                            };

                            // Add price option index as a dedicated field
                            if (instance.priceOptionIndex !== undefined) {
                                orderItem.priceOptionIndex = instance.priceOptionIndex;
                            }

                            // Add quantity for non-recurring features
                            if (!feature.recurring && instance.quantity) {
                                orderItem.quantity = parseInt(instance.quantity);
                            }

                            // Collect user fields
                            if (instance.userFields) {
                                orderItem.userData = { ...orderItem.userData, ...instance.userFields };
                            }

                            // Add feature-level fields if they exist
                            if (instance.featureFields) {
                                orderItem.userData = { ...orderItem.userData, ...instance.featureFields };
                            }
                            
                            orderItems.push(orderItem);
                            foundSelections = true;
                        }
                    }
                }
            }

            if (!foundSelections || orderItems.length === 0) {
                hideLoadingOverlay(overlay);
                alert('Please select at least one item to order.');
                return;
            }

            // Check for existing order
            let orderID = null;
            const existingOrder = sessionStorage.getItem('placedOrder');
            if (existingOrder) {
                try {
                    const orderInfo = JSON.parse(existingOrder);
                    if (orderInfo.orderID) orderID = orderInfo.orderID;
                } catch (e) {
                    console.error('Error parsing existing order data', e);
                }
            }
            
            // Handle anonymous user in campaign mode
            let anonUser = null;
            if (dashboardData.campaign_config && !window.auth_id) {
                const firstName = document.getElementById('anon-firstName')?.value || '';
                const lastName  = document.getElementById('anon-lastName')?.value  || '';
                const email     = document.getElementById('anon-email')?.value     || '';
                const phone     = document.getElementById('anon-phone')?.value     || '';
                
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    hideLoadingOverlay(overlay);
                    alert('Please enter a valid email address.');
                    // Re-enable button
                    const btn = document.getElementById('pay-now');
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = 'Place Order';
                    }
                    return;
                }
                
                anonUser = { firstName, lastName, email, phone };
                
                // Check if order has recurring features - if so, require account creation
                let hasRecurringFeatures = false;
                for (const item of orderItems) {
                    const feature = dashboardData.features.find(f => f.id === item.featureId);
                    if (feature && feature.recurring) {
                        hasRecurringFeatures = true;
                        break;
                    }
                }
                
                if (hasRecurringFeatures) {
                    // Check if we have Google authentication
                    if (googleAuthData && googleAuthData.auth_id) {
                        // Use Google authentication
                        anonUser.createAccount = true;
                        anonUser.signupMethod = 'google';
                        anonUser.googleAuthId = googleAuthData.auth_id;
                        
                        // Override auth_id for this order
                        window.auth_id = googleAuthData.auth_id;
                    } else {
                        // Use username/password authentication (existing logic)
                        const username = document.getElementById('anon-username')?.value.trim() || '';
                        const password = document.getElementById('anon-password')?.value.trim() || '';
                        
                        if (!username || !password) {
                            hideLoadingOverlay(overlay);
                            alert('Username and password are required for subscription services.');
                            const btn = document.getElementById('pay-now');
                            if (btn) {
                                btn.disabled = false;
                                btn.textContent = 'Place Order';
                            }
                            return;
                        }
                        
                        anonUser.createAccount = true;
                        anonUser.signupMethod = 'username';
                        anonUser.username = username;
                        anonUser.password = password;
                    }
                }
            }

            // Build payload (rest remains the same)
            const orderData = { items: orderItems };
            if (window.auth_id) {
                orderData.auth_id = window.auth_id;
            } else if (anonUser) {
                orderData.anonUser = anonUser;
            }

            // Add orderID if we have one
            if (orderID) {
                orderData.orderID = orderID;
            }

            // Send request
            try {
                const response = await fetch(`${myApi.api_url}place-order`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(orderData)
                });

                if (response.status === 403) {
                    console.error('You\'re not authorized to place this order.');
                    hideLoadingOverlay(overlay);
                    return;
                }

                // For any non-2xx status, attempt to pull an error message from JSON
                if (!response.ok) {
                    let errMsg = response.statusText;
                    try {
                        const errJson = await response.json();
                        errMsg = errJson.error || errJson.message || errMsg;
                    } catch (e) { /* ignore */ }
                    console.error(`Error (${response.status}): ${errMsg}`);
                    hideLoadingOverlay(overlay);
                    return;
                }

                // Success path
                const data = await response.json();

                // Persist a “pending” order
                sessionStorage.setItem('placedOrder', JSON.stringify({
                    recordId: data.records ? data.records[0].recordId : null,
                    orderID: data.orderID,
                    status: 'pending',
                    itemCount: orderItems.length,
                    totalValue: data.totalOrderValue,
                    type: data.type === 'subscription' ? 'subscription' : 'one_time',
                    subscriptionId: data.subscriptionId || undefined
                }));

                updateOrderButton();
                // → no disableFormInteraction() here

                if (data.type === 'subscription') {
                    handleSubscriptionPayment(data.clientSecret, data.subscriptionId);
                } else {
                    initializeStripePayment();
                }

                hideLoadingOverlay(overlay);
            } catch (error) {
                hideLoadingOverlay(overlay);
                alert(error.message || 'An error occurred while placing your order.');
                console.error('Order submission error:', error);
            }
        }

        // Check if there's actually a valid order
        function hasValidOrder() {
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) return false;
            
            try {
                const orderInfo = JSON.parse(orderData);
                // Make sure the order data has required fields that would indicate
                // it's a properly formed order
                return orderInfo && orderInfo.orderID && orderInfo.status === 'pending';
            } catch (e) {
                console.error('Error parsing order data', e);
                // If there's an error parsing the data, it's not valid
                sessionStorage.removeItem('placedOrder'); // Clear invalid data
                return false;
            }
        }
        
        function updateOrderButton() {
            const btn = document.getElementById('pay-now');
            if (!btn) return;

            btn.onclick = null;

            // Check if order is paid AFTER getting the button element
            if (isOrderPaid()) {
                btn.textContent = 'Payment Successful';
                btn.disabled = true;
                btn.style.backgroundColor = '#4CAF50';
                btn.style.cursor = 'not-allowed';
                return;
            }

            // Check if there are any valid selections
            if (!hasValidSelections()) {
                btn.textContent = 'Select Items to Continue';
                btn.disabled = true;
                btn.style.opacity = '0.5';
                btn.style.cursor = 'not-allowed';
                return;
            }

            // Check validation for anonymous users
            if (dashboardData.campaign_config && !window.auth_id) {
                const emailInput = document.getElementById('anon-email');
                const email = emailInput?.value.trim() || '';
                const isValidEmail = email && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                
                const hasRecurring = hasRecurringFeatures();
                
                // PRIORITY 1: If has recurring features, check account setup
                if (hasRecurring) {
                    // First check if email is provided and valid
                    if (!isValidEmail) {
                        btn.textContent = 'Complete Account Setup';
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        return;
                    }
                    
                    // Check if we have either Google auth OR username/password
                    if (googleAuthData) {
                        // Google authentication is complete
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                    } else {
                        // Check username and password for manual creation
                        const username = document.getElementById('anon-username')?.value.trim() || '';
                        const password = document.getElementById('anon-password')?.value.trim() || '';
                        const usernameError = document.getElementById('username-error');
                        const emailError = document.getElementById('anon-email-error');
                        
                        if (!username || !password) {
                            btn.textContent = 'Complete Account Setup';
                            btn.disabled = true;
                            btn.style.opacity = '0.5';
                            return;
                        }
                        
                        if (usernameError?.style.display === 'block' || emailError?.style.display === 'block') {
                            btn.textContent = 'Fix Validation Errors';
                            btn.disabled = true;
                            btn.style.opacity = '0.5';
                            return;
                        }
                        
                        btn.disabled = false;
                        btn.style.opacity = '1';
                        btn.style.cursor = 'pointer';
                    }
                }
                // PRIORITY 2: No recurring features, just need email
                else {
                    if (!isValidEmail) {
                        btn.textContent = 'Enter Email to Continue';
                        btn.disabled = true;
                        btn.style.opacity = '0.5';
                        return;
                    }
                    
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                }
            }

            // Rest of existing logic for order states...
            if (hasValidOrder() && !isOrderPaid()) {
                btn.textContent = 'Pay Now';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.onclick = function(e) {
                    e.preventDefault();
                    initializeStripePayment();
                };
            } else if (estimateMode) {
                btn.textContent = 'Request Estimate';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.onclick = function(e) {
                    e.preventDefault();
                    alert('Estimate request functionality coming soon!');
                };
            } else {
                btn.textContent = 'Place Order';
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
                btn.onclick = function(e) {
                    e.preventDefault();
                    if (btn.disabled) return;
                    btn.disabled = true;
                    btn.textContent = 'Processing...';
                    showOrderConfirmation();
                };
            }
        }

        // -------------------------
        // INVOICE CALCULATION LOGIC
        // (All addition of addons is done here, not in the option details)
        // -------------------------

        // Calculate a single instance's price (no range) for invoice
        function calculateInstancePrice(feature, instance) {
            if (!instance || instance.optionIndex === undefined) return 0;
            const option = feature.options[instance.optionIndex];
            if (!option) return 0;

            // Get base price
            let price = parseSafe(option.staticPrice, 0);
            
            // Handle price options
            let priceOptionsArray = [];
            if (option.priceOptions) {
                try {
                    if (typeof option.priceOptions === 'string') {
                        priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                    } else if (option.priceOptions.types) {
                        priceOptionsArray = option.priceOptions.types;
                    }
                    
                    // If we have price options and a selection, use that price
                    if (priceOptionsArray.length > 0 && 
                        instance.priceOptionIndex !== undefined &&
                        priceOptionsArray[instance.priceOptionIndex]) {
                        price = parseSafe(priceOptionsArray[instance.priceOptionIndex].price, price);
                    }
                } catch(e) {
                    console.error("Error parsing price options:", e);
                }
            }
            
            // Add addon prices (without group discounts yet)
            if (instance.addons && Array.isArray(instance.addons)) {
                instance.addons.forEach(addonId => {
                    // Find addon by ID instead of index
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon) {
                        const modVal = parseSafe(addon.staticPriceMod, 0);
                        if (isMultiply(addon)) {
                            price *= (modVal || 1);
                        } else {
                            price += modVal;
                        }
                    }
                });
            }
            
            // Get quantity (default to 1)
            const qty = parseInt(instance.quantity) || 1;
            
            // Calculate total price before group discounts
            let totalPrice = price * qty;
            const originalPrice = totalPrice;
            
            // NOW apply group discounts to the total addon cost
            if (instance.addons && Array.isArray(instance.addons)) {
                // Initialize or clear the groupDiscounts object
                instance.groupDiscounts = {};
                
                // Group selected addons by their group name
                const addonsByGroup = {};
                
                instance.addons.forEach(addonId => {
                    // Find addon by ID
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon && addon.groupName && addon.enableGrouping) {
                        if (!addonsByGroup[addon.groupName]) {
                            addonsByGroup[addon.groupName] = {
                                addons: [],
                                thresholdDiscounts: parseThresholdDiscounts(addon.groupThresholdDiscounts)
                            };
                        }
                        addonsByGroup[addon.groupName].addons.push(addon);
                    }
                });
                
                // Process each group for discounts
                Object.values(addonsByGroup).forEach(group => {
                    if (group.thresholdDiscounts.length === 0 || group.addons.length === 0) return;
                    
                    // Sort discounts by item count in descending order
                    const sortedDiscounts = [...group.thresholdDiscounts]
                        .sort((a, b) => parseInt(b.itemCount) - parseInt(a.itemCount));
                    
                    // Find the highest applicable discount
                    const applicableDiscount = sortedDiscounts.find(d => 
                        group.addons.length >= parseInt(d.itemCount)
                    );
                    
                    if (applicableDiscount) {
                        // Calculate the discount amount on the TOTAL price of this group's addons (including quantity)
                        const groupItemsPerUnit = group.addons.reduce((sum, addon) => {
                            // For premium toppings which are all additive, we just sum the static price modifiers
                            return sum + parseSafe(addon.staticPriceMod, 0);
                        }, 0);
                        
                        const groupItemsTotal = groupItemsPerUnit * qty; // Apply quantity here
                        const discountPercent = parseFloat(applicableDiscount.discount);
                        const discountAmount = groupItemsTotal * (discountPercent / 100);
                        
                        // Apply discount to the total price
                        totalPrice -= discountAmount;

                        // Store discount info for display in the invoice
                        instance.groupDiscounts[group.addons[0].groupName] = {
                            count: group.addons.length,
                            threshold: parseInt(applicableDiscount.itemCount),
                            percentage: discountPercent,
                            amount: discountAmount,
                            originalAmount: groupItemsTotal
                        };
                    }
                    // If no discount applies, we don't add an entry (since we cleared the object above)
                });
            }
            
            // Apply highest applicable threshold discount
            let appliedThreshold = null;
            let discountPercentage = 0;
            
            if (option.thresholdDiscounts) {
                try {
                    let thresholds = [];
                    if (typeof option.thresholdDiscounts === 'string') {
                        thresholds = JSON.parse(option.thresholdDiscounts);
                    } else if (option.thresholdDiscounts.types) {
                        thresholds = option.thresholdDiscounts.types;
                    } else if (Array.isArray(option.thresholdDiscounts)) {
                        thresholds = option.thresholdDiscounts;
                    }
                    
                    // Find highest applicable discount
                    if (Array.isArray(thresholds)) {
                        // Sort thresholds by itemCount in descending order
                        const sortedThresholds = [...thresholds]
                            .sort((a, b) => parseInt(b.itemCount) - parseInt(a.itemCount))
                            .filter(t => parseInt(t.itemCount) > 0 && parseFloat(t.discount) > 0);
                        
                        // Find first threshold that applies (highest one)
                        appliedThreshold = sortedThresholds.find(t => qty >= parseInt(t.itemCount));
                        
                        if (appliedThreshold) {
                            discountPercentage = parseFloat(appliedThreshold.discount);
                            totalPrice = originalPrice * (1 - discountPercentage/100);
                            
                            instance.appliedDiscount = {
                                threshold: parseInt(appliedThreshold.itemCount),
                                percentage: discountPercentage,
                                originalPrice: originalPrice
                            };
                        }
                    }
                } catch (e) {
                    console.error("Error processing threshold discounts:", e);
                }
            }
            
            if (!appliedThreshold) {
                delete instance.appliedDiscount;
            }
            
            return totalPrice;
        }

        // Parse threshold discounts from JSON string
        function parseThresholdDiscounts(discountsData) {
            if (!discountsData) return [];
            
            try {
                let thresholds = [];
                
                if (typeof discountsData === 'string') {
                    const parsed = JSON.parse(discountsData);
                    // Handle the case where it's parsed as an object with types
                    if (parsed && parsed.types) {
                        thresholds = parsed.types;
                    } else {
                        thresholds = parsed; // Assume it's directly an array
                    }
                } else if (discountsData.types) {
                    thresholds = discountsData.types;
                } else if (Array.isArray(discountsData)) {
                    thresholds = discountsData;
                }
                
                // Make sure we convert itemCount and discount to numbers
                const result = Array.isArray(thresholds) ? 
                    thresholds.filter(t => t.itemCount && t.discount).map(t => ({
                        itemCount: parseInt(t.itemCount, 10),
                        discount: parseFloat(t.discount)
                    })) : [];
                    
                return result;
            } catch (e) {
                console.error("Error parsing threshold discounts:", e, discountsData);
                return [];
            }
        }

        // Calculate lower bound for invoice if there's a range
        function calculateInstancePriceLower(feature, instance) {
            const option = feature.options[instance.optionIndex];
            if (!option) return 0;

            let price = parseSafe(option.priceFloor, parseSafe(option.staticPrice, 0));
            if (instance.addons && Array.isArray(instance.addons)) {
                instance.addons.forEach(addonId => {
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon) {
                        const floorVal = parseSafe(addon.floorPriceMod, parseSafe(addon.staticPriceMod, 0));
                        if (isMultiply(addon)) {
                            price *= (floorVal || 1);
                        } else {
                            price += floorVal;
                        }
                    }
                });
            }
            if (!feature.recurring) {
                const qty = parseInt(instance.quantity) || 1;
                price *= qty;
            }
            return price;
        }

        // Calculate upper bound for invoice if there's a range
        function calculateInstancePriceUpper(feature, instance) {
            const option = feature.options[instance.optionIndex];
            if (!option) return 0;

            let price = parseSafe(option.priceCeiling, parseSafe(option.staticPrice, 0));
            if (instance.addons && Array.isArray(instance.addons)) {
                instance.addons.forEach(addonId => {
                    const addon = option.addons.find(a => a.id === addonId);
                    if (addon) {
                        const ceilVal = parseSafe(addon.ceilingPriceMod, parseSafe(addon.staticPriceMod, 0));
                        if (isMultiply(addon)) {
                            price *= (ceilVal || 1);
                        } else {
                            price += ceilVal;
                        }
                    }
                });
            }
            if (!feature.recurring) {
                const qty = parseInt(instance.quantity) || 1;
                price *= qty;
            }
            return price;
        }

        // Build and display the invoice
        function updateInvoice() {
            let totalLower = 0;
            let totalUpper = 0;
            let totalFinal = 0;
            let recurringTotal = 0;
            let oneTimeTotal = 0;
            let oneTimeOriginalTotal = 0;
            let recurringOriginalTotal = 0;
            estimateMode = false;

            let tableHTML = `
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            // Separate recurring and one-time items
            let recurringItems = [];
            let oneTimeItems = [];

            dashboardData.features.forEach((feature, fIndex) => {
                const instances = selections[fIndex] || [];
                instances.forEach(instance => {
                    if (instance.optionIndex === undefined) return;
                    const option = feature.options[instance.optionIndex];
                    if (!option) return;
                    
                    const itemData = {
                        feature: feature,
                        option: option,
                        instance: instance,
                        fIndex: fIndex
                    };
                    
                    if (feature.recurring) {
                        recurringItems.push(itemData);
                    } else {
                        oneTimeItems.push(itemData);
                    }
                });
            });

            // Determine the interval label for recurring totals
            const recurringInterval = recurringItems.length > 0
                ? recurringItems[0].option.interval
                : '';

            // Function to render item rows
            const renderItemRow = (itemData) => {
                const { feature, option, instance, fIndex } = itemData;
                calculateInstancePrice(feature, instance);

                let itemDescription = `<div class="item-main">
                    <div class="feature-name">${feature.featureName}</div>
                    <div class="option-details">`;

                // handle priceOptions label
                let selectedOptionText = '';
                let priceOptionsArray = [];
                if (option.priceOptions) {
                    try {
                        if (typeof option.priceOptions === 'string') {
                            priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                        } else {
                            priceOptionsArray = option.priceOptions.types || [];
                        }
                        const idx = instance.priceOptionIndex;
                        if (priceOptionsArray.length > 0 && idx != null && priceOptionsArray[idx]) {
                            selectedOptionText = ` (${priceOptionsArray[idx].label})`;
                        }
                    } catch (e) {
                        console.error("Error parsing price options:", e);
                    }
                }

                // quantity label for non-recurring
                let qtyLabel = '';
                if (!feature.recurring) {
                    const qty = parseInt(instance.quantity) || 1;
                    qtyLabel = ` (Qty: ${qty})`;
                }

                itemDescription += `<div class="option-name">
                    ${option.optionName}${selectedOptionText}${qtyLabel}
                </div>`;

                // recurring label
                if (feature.recurring && option.interval) {
                    itemDescription += `<div class="recurring-interval">Billed ${option.interval}ly</div>`;
                }

                // extra lines: userFields + addons
                const additionalDetails = [];
                if (instance.userFields) {
                    for (const [fieldName, selIdx] of Object.entries(instance.userFields)) {
                        const userField = option[`${fieldName}_user`];
                        if (!userField) continue;
                        try {
                            const fd = typeof userField === 'string' ? JSON.parse(userField) : userField;
                            if (fd && Array.isArray(fd.types) && fd.types[selIdx]) {
                                const niceName = fieldName
                                    .replace(/([A-Z])/g, ' $1')
                                    .replace(/^./, s => s.toUpperCase())
                                    .trim();
                                additionalDetails.push(`${niceName}: ${fd.types[selIdx]}`);
                            }
                        } catch (e) {
                            console.error(`Error user field ${fieldName}:`, e);
                        }
                    }
                }

                let hasAddon = false;
                if (Array.isArray(instance.addons)) {
                    instance.addons.forEach(addonId => {
                        const a = option.addons.find(x => x.id === addonId);
                        if (a) {
                            additionalDetails.push(`<span class="addon-item-name">${a.addonName}</span>`);
                            hasAddon = true;
                        }
                    });
                }

                const bullet = hasAddon ? '' : '• ';
                additionalDetails.forEach(detail => {
                    itemDescription += `<div class="option-detail-line">${bullet}${detail}</div>`;
                });

                itemDescription += `</div></div>`;

                //
                // --- pricing logic (same as before) ---
                //

                // 1) per-unit base price
                let unitBasePrice = parseSafe(option.staticPrice, 0);
                priceOptionsArray = [];
                if (option.priceOptions) {
                    try {
                        if (typeof option.priceOptions === 'string') {
                            priceOptionsArray = JSON.parse(option.priceOptions).types || [];
                        } else {
                            priceOptionsArray = option.priceOptions.types || [];
                        }
                        const idx = instance.priceOptionIndex;
                        if (priceOptionsArray.length > 0 && idx != null && priceOptionsArray[idx]) {
                            unitBasePrice = parseSafe(priceOptionsArray[idx].price, unitBasePrice);
                        }
                    } catch (e) {
                        console.error("Error parsing price options:", e);
                    }
                }

                // 2) per-unit addons total
                let addonUnitTotal = 0;
                if (Array.isArray(instance.addons)) {
                    instance.addons.forEach(addonId => {
                        const a = option.addons.find(x => x.id === addonId);
                        if (a) {
                            addonUnitTotal += parseSafe(a.staticPriceMod, 0);
                        }
                    });
                }

                // 3) compute totals
                const qty      = feature.recurring ? 1 : (parseInt(instance.quantity) || 1);
                const baseTotal  = unitBasePrice * qty;
                const addonTotal = addonUnitTotal * qty;
                const originalPrice = baseTotal + addonTotal;

                // 4) read any group-discount amounts (populated by calculateInstancePrice)
                const groupDiscountTotal = (!feature.recurring && instance.groupDiscounts)
                    ? Object.values(instance.groupDiscounts).reduce((sum, g) => sum + g.amount, 0)
                    : 0;

                // 5) figure out percent discount (stored or threshold)
                let pct = 0;
                if (!feature.recurring && instance.appliedDiscount) {
                    pct = instance.appliedDiscount.percentage || 0;
                } else if (!feature.recurring && option.thresholdDiscounts) {
                    try {
                        let thresholds = typeof option.thresholdDiscounts === 'string'
                            ? JSON.parse(option.thresholdDiscounts)
                            : option.thresholdDiscounts;
                        if (Array.isArray(thresholds)) {
                            thresholds.forEach(t => {
                                const count = parseInt(t.itemCount, 10);
                                const d = parseFloat(t.discount);
                                if (qty >= count) pct = Math.max(pct, d);
                            });
                        }
                    } catch (e) {
                        console.error("Error parsing thresholds:", e);
                    }
                }

                // 6) apply percent only to base, then subtract group discounts, then add addons
                const afterPct   = baseTotal * (1 - pct/100);
                let finalPrice = afterPct - groupDiscountTotal + addonTotal;
                finalPrice = Math.round(finalPrice * 100) / 100;

                return {
                    html: `<tr>
                        <td>${itemDescription}</td>
                        <td>${
                            feature.recurring
                                ? `${originalPrice.toFixed(2)}/${option.interval}`
                                : originalPrice.toFixed(2)
                        }</td>
                    </tr>`,
                    price: finalPrice,
                    originalPrice,
                    isRecurring: feature.recurring
                };
            };

            // Render one-time items first
            if (oneTimeItems.length > 0) {
                tableHTML += `<tr class="section-header"><td colspan="2"><strong>One-Time Charges</strong></td></tr>`;
                oneTimeItems.forEach(itemData => {
                    const rendered = renderItemRow(itemData);
                    tableHTML += rendered.html;
                    oneTimeTotal += rendered.price; // discounted price for actual total
                    oneTimeOriginalTotal += rendered.originalPrice; // original price for discount calculation
                    totalFinal += rendered.price;
                });
            }

            // Render recurring items
            if (recurringItems.length > 0) {
                if (oneTimeItems.length > 0) {
                    tableHTML += `<tr class="section-spacer"><td colspan="2">&nbsp;</td></tr>`;
                }
                tableHTML += `<tr class="section-header"><td colspan="2"><strong>Recurring Charges</strong></td></tr>`;
                recurringItems.forEach(itemData => {
                    const rendered = renderItemRow(itemData);
                    tableHTML += rendered.html;
                    recurringTotal += rendered.price; // discounted price for actual total
                    recurringOriginalTotal += rendered.originalPrice; // original price for discount calculation
                });

                // When we have both one-time and recurring, add recurring to the total
                if (oneTimeItems.length > 0) {
                    totalFinal += recurringTotal;
                } else if (recurringItems.length > 0) {
                    totalFinal = recurringTotal;
                }
            }

            // Calculate total discounts for display using the correct logic
            let totalOriginalPrice = 0;
            let totalDiscountedPrice = 0;

            // Calculate for one-time items
            if (oneTimeItems.length > 0) {
                totalOriginalPrice += oneTimeOriginalTotal;
                totalDiscountedPrice += oneTimeTotal;
            }

            // Calculate for recurring items  
            if (recurringItems.length > 0) {
                totalOriginalPrice += recurringOriginalTotal;
                totalDiscountedPrice += recurringTotal;
            }

            const totalDiscount = totalOriginalPrice - totalDiscountedPrice;
            
            // Show totals
            tableHTML += `
                    </tbody>
                    <tfoot>`;

            if (oneTimeItems.length > 0 && recurringItems.length > 0) {
                // Show both totals
                tableHTML += `
                    <tr class="subtotal-row">
                        <td style="text-align: right;">One-Time Total:</td>
                        <td>$${oneTimeTotal.toFixed(2)}</td>
                    </tr>
                    <tr class="subtotal-row">
                        <td style="text-align: right;">Recurring Total:</td>
                        <td>$${recurringTotal.toFixed(2)}/${recurringInterval}</td>
                    </tr>`;
                
                // Add discount row if there are discounts
                if (totalDiscount > 0) {
                    tableHTML += `
                        <tr class="discount-row" style="color: #0066cc;">
                            <td style="text-align: right;">Discount:</td>
                            <td>-$${totalDiscount.toFixed(2)}</td>
                        </tr>`;
                }
                
                tableHTML += `
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: bold;">Due Today:</td>
                        <td id="invoice-total">$${totalFinal.toFixed(2)}</td>
                    </tr>`;
            } else if (recurringItems.length > 0) {
                // Only recurring
                if (totalDiscount > 0) {
                    tableHTML += `
                        <tr class="discount-row" style="color: #0066cc;">
                            <td style="text-align: right;">Discount:</td>
                            <td>-$${totalDiscount.toFixed(2)}</td>
                        </tr>`;
                }
                
                tableHTML += `
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: bold;">Recurring Total:</td>
                        <td id="invoice-total">$${recurringTotal.toFixed(2)}/${recurringInterval}</td>
                    </tr>`;
            } else {
                // Only one-time
                if (totalDiscount > 0) {
                    tableHTML += `
                        <tr class="discount-row" style="color: #0066cc;">
                            <td style="text-align: right;">Discount:</td>
                            <td>-$${totalDiscount.toFixed(2)}</td>
                        </tr>`;
                }
                
                tableHTML += `
                    <tr class="total-row">
                        <td style="text-align: right; font-weight: bold;">Total:</td>
                        <td id="invoice-total">$${totalFinal.toFixed(2)}</td>
                    </tr>`;
            }

            tableHTML += `
                    </tfoot>
                </table>
            `;
            
            if (invoiceDetails) invoiceDetails.innerHTML = tableHTML;

            updateOrderButton();
        }

        // Check if there are any valid selections in the invoice
        function hasValidSelections() {
            for (const [fIndex, instances] of Object.entries(selections)) {
                for (const instance of instances) {
                    if (instance.optionIndex !== undefined) {
                        const feature = dashboardData.features[fIndex];
                        if (feature && feature.options && feature.options[instance.optionIndex]) {
                            return true; // Found at least one valid selection
                        }
                    }
                }
            }
            return false; // No valid selections found
        }

        // Render feature-level user fields
        function renderFeatureLevelFields(feature, fIndex, instanceDiv, instance) {
            const featureFieldsDiv = document.createElement('div');
            featureFieldsDiv.classList.add('feature-fields');
            featureFieldsDiv.id = `feature-${feature.featureName.toLowerCase().replace(/\s+/g, '-')}`;

            let hasUserFields = false;

            // Loop through feature properties; render via the shared
            // renderUserFieldRow helper so feature-level fields visually
            // match option-level fields and wp-admin Profile inputs.
            // State bucket = 'featureFields' so the order payload still
            // distinguishes feature-level vs option-level user input.
            for (const key in feature) {
                if ((key.endsWith('_user') || key.endsWith('_display')) && feature[key] !== null) {
                    hasUserFields = true;
                    try {
                        const suffix = key.endsWith('_display') ? '_display' : '_user';
                        featureFieldsDiv.appendChild(
                            renderUserFieldRow(feature[key], key, fIndex, 0, instance, suffix, 'featureFields')
                        );
                    } catch (e) {
                        console.error(`Error processing feature user field ${key}:`, e);
                    }
                }
            }

            if (hasUserFields) instanceDiv.appendChild(featureFieldsDiv);
            return hasUserFields;
        }

        // Render a single instance in the UI (option details)
        // ---------- Option-tile picker (replaces native <select>) ----------
        // Builds a radiogroup of clickable tile cards. Click toggles
        // selection (click selected tile again → deselect, mirroring the
        // legacy <option value="">None</option>). Arrow keys move focus
        // and selection (radio roving-tabindex pattern).
        //
        // The change-handler logic is captured in `selectOption(oIndex)`
        // — same code path is used by initial-render auto-selection,
        // tile click, and keyboard activation.
        function renderOptionTiles(feature, fIndex, instIndex, instance, instanceDiv, optionDetailsDiv) {
            const grid = document.createElement('div');
            grid.classList.add('option-tile-grid');
            grid.setAttribute('role', 'radiogroup');
            grid.setAttribute('aria-label', `Choose a ${feature.featureName} option`);

            const subDetails = dashboardData.subscription_status &&
                               dashboardData.subscription_status.has_active_subscription &&
                               dashboardData.subscription_status.subscription_details;
            const subscribedOptionId = subDetails ? parseInt(subDetails.option_id) : null;

            function teaserFor(option) {
                // Prefer a curated tierBenefits _display field; fall back
                // to the first sentence of the option description.
                const benefits = option.tierBenefits_display || option.tierBenefits;
                if (benefits) {
                    try {
                        const parsed = typeof benefits === 'string' ? JSON.parse(benefits) : benefits;
                        if (parsed && parsed.value) return String(parsed.value).trim();
                    } catch (_) { /* fall through */ }
                    if (typeof benefits === 'string') return benefits.trim();
                }
                const desc = getDescriptionText(option.description) || '';
                if (!desc) return '';
                const first = String(desc).split(/(?<=[.!?])\s+/)[0] || desc;
                return first;
            }

            // Tier-benefit teasers in pricing.json conventionally use the
            // middle-dot " · " as a separator. When present we render a
            // checkmark feature list — way more readable than a wrapped
            // paragraph and matches typical SaaS tier-card aesthetic.
            function teaserAsBullets(text) {
                if (!text) return null;
                if (text.indexOf('·') === -1) return null;
                const parts = text.split('·')
                    .map(s => s.trim())
                    .filter(Boolean);
                return parts.length >= 2 ? parts : null;
            }

            function priceLabel(option, recurring) {
                let basePrice = parseSafe(option.staticPrice, 0);
                // If pricingType is price-options, surface the lowest tier
                // as the "starting at" price on the tile.
                let priceOpts = [];
                if (option.priceOptions) {
                    try {
                        const po = typeof option.priceOptions === 'string'
                            ? JSON.parse(option.priceOptions)
                            : option.priceOptions;
                        priceOpts = (po && po.types) || [];
                    } catch (_) {}
                }
                let pricingType = option.pricingType;
                if (pricingType && typeof pricingType === 'object') {
                    if (pricingType.value && pricingType.value.types) {
                        pricingType = pricingType.value.types[pricingType.value.selected];
                    } else if (pricingType.types) {
                        pricingType = pricingType.types[pricingType.selected];
                    }
                }
                if (pricingType === 'price options' && priceOpts.length > 0) {
                    const cheapest = priceOpts.reduce((min, p) => {
                        const v = parseSafe(p.price, Infinity);
                        return v < min ? v : min;
                    }, Infinity);
                    return {
                        amount: '$' + (Number.isFinite(cheapest) ? cheapest.toFixed(0) : '0'),
                        period: recurring ? '/' + (option.interval || 'mo') : '+',
                        prefix: 'from '
                    };
                }
                return {
                    amount: '$' + basePrice.toFixed(basePrice % 1 === 0 ? 0 : 2),
                    period: recurring ? '/' + (option.interval || 'mo') : '',
                    prefix: ''
                };
            }

            function selectOption(oIndex) {
                const featureFieldsDivId = feature.featureName.toLowerCase().replace(/\s+/g, '-');
                const featureFieldsDiv = instanceDiv.querySelector(`#feature-${featureFieldsDivId}`);

                if (oIndex === undefined || oIndex === null || oIndex === '' || isNaN(oIndex)) {
                    instance.optionIndex = undefined;
                    if (featureFieldsDiv) featureFieldsDiv.style.display = 'none';
                } else {
                    instance.optionIndex = parseInt(oIndex);
                    if (featureFieldsDiv) featureFieldsDiv.style.display = 'block';
                }

                // Reset addons and quantity when the option changes.
                instance.addons = [];
                if (!feature.recurring) {
                    instance.quantity = 1;
                }
                instance.priceOptionIndex = undefined;

                // Sync tile UI state
                const tiles = grid.querySelectorAll('.option-tile');
                tiles.forEach((tile) => {
                    const tIdx = parseInt(tile.getAttribute('data-oindex'));
                    const isSel = tIdx === instance.optionIndex;
                    tile.classList.toggle('is-selected', isSel);
                    tile.setAttribute('aria-checked', isSel ? 'true' : 'false');
                    tile.setAttribute('tabindex', isSel ? '0' : '-1');
                });
                // No selection → first tile gets focus tabindex
                if (instance.optionIndex === undefined && tiles.length) {
                    tiles[0].setAttribute('tabindex', '0');
                }

                renderOptionDetails(fIndex, instIndex, feature, optionDetailsDiv);
                if (instance.optionIndex !== undefined && !isNaN(instance.optionIndex)) {
                    smoothScrollToElement(instanceDiv, 120);
                }
                saveSelections();
            }

            feature.options.forEach((option, oIndex) => {
                const tile = document.createElement('button');
                tile.type = 'button';
                tile.classList.add('option-tile');
                tile.setAttribute('role', 'radio');
                tile.setAttribute('data-oindex', String(oIndex));
                const isSelected = parseInt(instance.optionIndex) === oIndex;
                tile.setAttribute('aria-checked', isSelected ? 'true' : 'false');
                tile.setAttribute('tabindex', isSelected ? '0' : '-1');
                if (isSelected) tile.classList.add('is-selected');

                const isSubscribed = feature.recurring && subscribedOptionId !== null
                    && parseInt(option.id) === subscribedOptionId;
                if (isSubscribed) {
                    tile.disabled = true;
                    tile.classList.add('is-disabled');
                    tile.setAttribute('aria-disabled', 'true');
                }

                // ----- Header row: icon + name + price + check -----
                const header = document.createElement('span');
                header.classList.add('option-tile-header');

                const iconEl = document.createElement('span');
                iconEl.classList.add('option-tile-icon');
                iconEl.setAttribute('aria-hidden', 'true');
                iconEl.innerHTML = pickOptionIcon(option, feature);

                const nameEl = document.createElement('span');
                nameEl.classList.add('option-tile-name');
                nameEl.textContent = option.optionName;
                if (isSubscribed) {
                    const sub = document.createElement('span');
                    sub.classList.add('option-tile-disabled-note');
                    sub.textContent = 'Subscribed';
                    nameEl.appendChild(sub);
                }

                const meta = document.createElement('span');
                meta.classList.add('option-tile-meta');

                const priceEl = document.createElement('span');
                priceEl.classList.add('option-tile-price');
                const p = priceLabel(option, feature.recurring);
                priceEl.textContent = (p.prefix || '') + p.amount;
                if (p.period) {
                    const period = document.createElement('span');
                    period.classList.add('option-tile-period');
                    period.textContent = p.period;
                    priceEl.appendChild(period);
                }
                meta.appendChild(priceEl);

                const check = document.createElement('span');
                check.classList.add('option-tile-check');
                check.setAttribute('aria-hidden', 'true');
                check.innerHTML = UTILITY_ICONS.check;
                meta.appendChild(check);

                header.appendChild(iconEl);
                header.appendChild(nameEl);
                header.appendChild(meta);
                tile.appendChild(header);

                // ----- Body row: bullets or teaser, full tile width -----
                const teaser = teaserFor(option);
                const bullets = teaserAsBullets(teaser);
                if (bullets) {
                    const ul = document.createElement('ul');
                    ul.classList.add('option-tile-bullets');
                    bullets.forEach((item) => {
                        const li = document.createElement('li');
                        li.innerHTML = UTILITY_ICONS.check + '<span>' + item + '</span>';
                        ul.appendChild(li);
                    });
                    tile.appendChild(ul);
                } else if (teaser) {
                    const teaserEl = document.createElement('span');
                    teaserEl.classList.add('option-tile-teaser');
                    teaserEl.textContent = teaser;
                    tile.appendChild(teaserEl);
                }

                // Click to select / re-click to deselect
                tile.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (tile.disabled) return;
                    const currentlySelected = parseInt(instance.optionIndex) === oIndex;
                    selectOption(currentlySelected ? undefined : oIndex);
                });

                grid.appendChild(tile);
            });

            // Roving-tabindex keyboard navigation
            grid.addEventListener('keydown', function(e) {
                const tiles = Array.from(grid.querySelectorAll('.option-tile:not([disabled])'));
                if (!tiles.length) return;
                const currentIdx = tiles.indexOf(document.activeElement);
                let nextIdx = currentIdx;
                switch (e.key) {
                    case 'ArrowRight':
                    case 'ArrowDown':
                        e.preventDefault();
                        nextIdx = (currentIdx + 1) % tiles.length;
                        break;
                    case 'ArrowLeft':
                    case 'ArrowUp':
                        e.preventDefault();
                        nextIdx = (currentIdx - 1 + tiles.length) % tiles.length;
                        break;
                    case 'Home':
                        e.preventDefault();
                        nextIdx = 0;
                        break;
                    case 'End':
                        e.preventDefault();
                        nextIdx = tiles.length - 1;
                        break;
                    case ' ':
                    case 'Enter': {
                        e.preventDefault();
                        const focused = tiles[currentIdx];
                        if (!focused) return;
                        const oIdx = parseInt(focused.getAttribute('data-oindex'));
                        const currentlySelected = parseInt(instance.optionIndex) === oIdx;
                        selectOption(currentlySelected ? undefined : oIdx);
                        return;
                    }
                    default:
                        return;
                }
                if (nextIdx !== currentIdx && tiles[nextIdx]) {
                    tiles.forEach((t, i) => t.setAttribute('tabindex', i === nextIdx ? '0' : '-1'));
                    tiles[nextIdx].focus();
                }
            });

            return grid;
        }

        // ---------- Segmented price-options selector ----------
        // Replaces the inner <select> for pricingType: "price options".
        // Pill-row of buttons, one per priceOption type. Single-select
        // via role="radiogroup". Wrapped in a .form-row so it inherits
        // the Profile-card label aesthetic.
        function renderSegmentedPriceOptions(fIndex, instIndex, instance, priceOptionsArray) {
            const row = document.createElement('div');
            row.classList.add('form-row', 'price-option-row');

            const label = document.createElement('span');
            label.classList.add('form-label');
            label.textContent = 'Format';
            row.appendChild(label);

            const group = document.createElement('div');
            group.classList.add('segmented');
            group.setAttribute('role', 'radiogroup');
            group.setAttribute('aria-label', 'Format');

            function selectPill(idx) {
                instance.priceOptionIndex = idx;
                const pills = group.querySelectorAll('.segmented-pill');
                pills.forEach((p) => {
                    const pIdx = parseInt(p.getAttribute('data-pidx'));
                    const isSel = pIdx === idx;
                    p.classList.toggle('is-selected', isSel);
                    p.setAttribute('aria-checked', isSel ? 'true' : 'false');
                    p.setAttribute('tabindex', isSel ? '0' : '-1');
                });
                saveSelections();
                updateInvoice();
            }

            priceOptionsArray.forEach((optData, idx) => {
                const pill = document.createElement('button');
                pill.type = 'button';
                pill.classList.add('segmented-pill');
                pill.setAttribute('role', 'radio');
                pill.setAttribute('data-pidx', String(idx));
                const isSelected = idx === instance.priceOptionIndex;
                pill.setAttribute('aria-checked', isSelected ? 'true' : 'false');
                pill.setAttribute('tabindex', isSelected ? '0' : '-1');
                if (isSelected) pill.classList.add('is-selected');

                const labelEl = document.createElement('span');
                labelEl.classList.add('segmented-pill-label');
                labelEl.textContent = optData.label;

                const priceEl = document.createElement('span');
                priceEl.classList.add('segmented-pill-price');
                priceEl.textContent = '$' + parseSafe(optData.price).toFixed(parseSafe(optData.price) % 1 === 0 ? 0 : 2);

                pill.appendChild(labelEl);
                pill.appendChild(priceEl);

                pill.addEventListener('click', function(e) {
                    e.preventDefault();
                    selectPill(idx);
                });

                group.appendChild(pill);
            });

            // Roving-tabindex keyboard navigation
            group.addEventListener('keydown', function(e) {
                const pills = Array.from(group.querySelectorAll('.segmented-pill'));
                if (!pills.length) return;
                const currentIdx = pills.indexOf(document.activeElement);
                let nextIdx = currentIdx;
                switch (e.key) {
                    case 'ArrowRight':
                    case 'ArrowDown':
                        e.preventDefault();
                        nextIdx = (currentIdx + 1) % pills.length;
                        break;
                    case 'ArrowLeft':
                    case 'ArrowUp':
                        e.preventDefault();
                        nextIdx = (currentIdx - 1 + pills.length) % pills.length;
                        break;
                    case 'Home':
                        e.preventDefault();
                        nextIdx = 0;
                        break;
                    case 'End':
                        e.preventDefault();
                        nextIdx = pills.length - 1;
                        break;
                    case ' ':
                    case 'Enter': {
                        e.preventDefault();
                        const focused = pills[currentIdx];
                        if (!focused) return;
                        selectPill(parseInt(focused.getAttribute('data-pidx')));
                        return;
                    }
                    default:
                        return;
                }
                if (nextIdx !== currentIdx && pills[nextIdx]) {
                    pills.forEach((p, i) => p.setAttribute('tabindex', i === nextIdx ? '0' : '-1'));
                    pills[nextIdx].focus();
                }
            });

            row.appendChild(group);
            return row;
        }

        // ---------- Display-row helpers ----------
        // Replace the legacy `<p><strong>X:</strong> Y</p>` pattern with
        // the same .form-row + .form-label structure used by the Profile
        // card. Description gets a flat .option-summary paragraph (no
        // "Description:" prefix); _display fields render label + amber
        // pill; the price row gets a mono amber price treatment.

        function renderOptionSummary(text) {
            const p = document.createElement('p');
            p.classList.add('option-summary');
            p.textContent = text || '';
            return p;
        }

        function renderDisplayFieldRow(label, value) {
            const row = document.createElement('div');
            row.classList.add('form-row', 'display-field-row');
            const labelEl = document.createElement('span');
            labelEl.classList.add('form-label');
            labelEl.textContent = label;
            const pill = document.createElement('span');
            pill.classList.add('user-display-value');
            pill.textContent = value;
            row.appendChild(labelEl);
            row.appendChild(pill);
            return row;
        }

        function renderPriceRow(text, recurring, interval) {
            const row = document.createElement('div');
            row.classList.add('form-row', 'price-row');
            const labelEl = document.createElement('span');
            labelEl.classList.add('form-label');
            labelEl.textContent = 'Price';
            const display = document.createElement('span');
            display.classList.add('option-price-display');
            const valueEl = document.createElement('span');
            valueEl.classList.add('option-price-value');
            valueEl.textContent = text;
            display.appendChild(valueEl);
            if (recurring) {
                const suffix = document.createElement('span');
                suffix.classList.add('option-price-suffix');
                suffix.textContent = '/ ' + (interval || 'mo');
                display.appendChild(suffix);
            }
            row.appendChild(labelEl);
            row.appendChild(display);
            return row;
        }

        // ---------- User-field row (shared for instance + feature-level) ----------
        // Replaces the duplicated switch in renderOptionDetails and
        // renderFeatureLevelFields. Promotes inputs to the .form-input
        // pattern (Profile card aesthetic) and adds an optional leading
        // icon next to the label based on the field name.
        //
        // bucket = 'userFields' (option-level) or 'featureFields'
        // (feature-level) — controls which sub-object on the instance
        // stores user input. Both buckets travel into the order payload
        // unchanged.
        function renderUserFieldRow(rawField, key, fIndex, instIndex, instance, suffix, bucket) {
            const stateBucket = bucket || 'userFields';
            // suffix = '_user' or '_display' — strip from field key for storage.
            const fieldName = key.replace(suffix, '');
            const isDisplay = suffix === '_display';
            const displayName = formatFieldLabel(key);

            // Parse the wrapper into {ui_type, value, types, placeholder}
            let fieldData = rawField;
            let fieldType = 'normal-text';
            let fieldValue = '';
            let placeholder = '';
            let dropdownOptions = [];
            try {
                if (typeof rawField === 'string') {
                    try { fieldData = JSON.parse(rawField); } catch (_) { fieldData = { value: rawField }; }
                }
                if (fieldData && typeof fieldData === 'object') {
                    if (fieldData.ui_type) fieldType = fieldData.ui_type;
                    if (fieldType === 'array') {
                        if (Array.isArray(fieldData.types)) {
                            dropdownOptions = fieldData.types;
                        } else if (fieldData.value && Array.isArray(fieldData.value.types)) {
                            dropdownOptions = fieldData.value.types;
                        }
                    } else if (fieldData.value !== undefined) {
                        fieldValue = fieldData.value;
                    }
                    if (fieldData.placeholder) placeholder = fieldData.placeholder;
                } else {
                    fieldValue = rawField;
                    fieldType = typeof fieldValue === 'number' ? 'int-float' : 'normal-text';
                }
            } catch (e) {
                console.warn(`Failed to parse field ${key}:`, e);
                fieldValue = rawField;
            }

            if (!instance[stateBucket]) instance[stateBucket] = {};

            // Wrapper — <label class="form-row user-field"> for inputs,
            // or <div class="form-row display-field-row"> for read-only.
            const tagName = isDisplay ? 'div' : 'label';
            const row = document.createElement(tagName);
            row.classList.add('form-row', 'user-field');
            if (isDisplay) row.classList.add('display-field-row');

            // Label with optional leading icon
            const labelSpan = document.createElement('span');
            labelSpan.classList.add('form-label');
            const iconSvg = pickUserFieldIcon(displayName);
            if (iconSvg) {
                const iconEl = document.createElement('span');
                iconEl.classList.add('user-field-icon');
                iconEl.setAttribute('aria-hidden', 'true');
                iconEl.innerHTML = iconSvg;
                labelSpan.appendChild(iconEl);
            }
            labelSpan.appendChild(document.createTextNode(displayName));
            row.appendChild(labelSpan);

            // Display fields → amber pill, no input.
            if (isDisplay) {
                const pill = document.createElement('span');
                pill.classList.add('user-display-value');
                if (fieldType === 'array' && dropdownOptions.length) {
                    const selIdx = instance[stateBucket][fieldName] || (fieldData && fieldData.selected) || 0;
                    pill.textContent = dropdownOptions[selIdx] || '';
                } else {
                    pill.textContent = fieldValue || '';
                }
                row.appendChild(pill);
                return row;
            }

            // Editable inputs — all get .form-input.
            let input;
            const inputId = `user-field-${fIndex}-${instIndex}-${fieldName}`;
            switch (fieldType) {
                case 'array': {
                    input = document.createElement('select');
                    input.classList.add('form-input');
                    input.id = inputId;
                    if (!dropdownOptions.length) dropdownOptions = ['No options available'];
                    if (instance[stateBucket][fieldName] === undefined) instance[stateBucket][fieldName] = 0;
                    dropdownOptions.forEach((opt, i) => {
                        const o = document.createElement('option');
                        o.value = i;
                        o.textContent = opt;
                        if (i === instance[stateBucket][fieldName]) o.selected = true;
                        input.appendChild(o);
                    });
                    input.addEventListener('change', function() {
                        instance[stateBucket][fieldName] = parseInt(this.value);
                        saveSelections();
                        updateInvoice();
                    });
                    break;
                }
                case 'long-text': {
                    input = document.createElement('textarea');
                    input.classList.add('form-input');
                    input.id = inputId;
                    input.rows = 3;
                    if (placeholder) input.placeholder = placeholder;
                    if (instance[stateBucket][fieldName] === undefined) instance[stateBucket][fieldName] = fieldValue;
                    input.value = instance[stateBucket][fieldName] || '';
                    input.addEventListener('input', function() {
                        instance[stateBucket][fieldName] = this.value;
                        saveSelections();
                        updateInvoice();
                    });
                    break;
                }
                case 'int-float': {
                    input = document.createElement('input');
                    input.type = 'number';
                    input.classList.add('form-input');
                    input.id = inputId;
                    input.step = 'any';
                    if (placeholder) input.placeholder = placeholder;
                    if (instance[stateBucket][fieldName] === undefined) instance[stateBucket][fieldName] = fieldValue;
                    input.value = instance[stateBucket][fieldName];
                    input.addEventListener('input', function() {
                        const val = parseFloat(this.value);
                        instance[stateBucket][fieldName] = isNaN(val) ? 0 : val;
                        saveSelections();
                        updateInvoice();
                    });
                    break;
                }
                case 'date': {
                    input = document.createElement('input');
                    input.type = 'date';
                    input.classList.add('form-input');
                    input.id = inputId;
                    if (instance[stateBucket][fieldName] === undefined) instance[stateBucket][fieldName] = fieldValue;
                    input.value = instance[stateBucket][fieldName];
                    input.addEventListener('change', function() {
                        instance[stateBucket][fieldName] = this.value;
                        saveSelections();
                        updateInvoice();
                    });
                    break;
                }
                case 'normal-text':
                default: {
                    input = document.createElement('input');
                    input.type = 'text';
                    input.classList.add('form-input');
                    input.id = inputId;
                    if (placeholder) input.placeholder = placeholder;
                    if (instance[stateBucket][fieldName] === undefined) instance[stateBucket][fieldName] = fieldValue;
                    input.value = instance[stateBucket][fieldName] || '';
                    input.addEventListener('input', function() {
                        instance[stateBucket][fieldName] = this.value;
                        saveSelections();
                        updateInvoice();
                    });
                    break;
                }
            }

            row.appendChild(input);
            return row;
        }

        // ---------- Inline alert (replaces inline-styled red message) ----------
        // Variant: 'warning' | 'info' | 'success'. Returns a DOM node.
        function renderInlineAlert(text, variant) {
            const alert = document.createElement('div');
            alert.classList.add('ff-alert', 'ff-alert-' + (variant || 'info'));
            alert.setAttribute('role', 'status');

            const iconEl = document.createElement('span');
            iconEl.classList.add('ff-alert-icon');
            iconEl.setAttribute('aria-hidden', 'true');
            iconEl.innerHTML = variant === 'success'
                ? UTILITY_ICONS.check
                : (variant === 'warning' ? UTILITY_ICONS.warning : UTILITY_ICONS.info);

            const textEl = document.createElement('span');
            textEl.classList.add('ff-alert-text');
            textEl.textContent = text;

            alert.appendChild(iconEl);
            alert.appendChild(textEl);
            return alert;
        }

        // ---------- Addon card (replaces .addon-item + tooltip "?") ----------
        // The native <input type=checkbox> is kept inside the button (CSS
        // visually hides it) so existing addonsDiv.querySelectorAll(
        // 'input[type="checkbox"]') queries in enforceMaxAddons /
        // enforceMaxGroupItems continue to match without rewrite.
        function renderAddonCard(addon, aIndex, fIndex, instIndex, instance) {
            const card = document.createElement('button');
            card.type = 'button';
            card.classList.add('addon-card');
            card.setAttribute('role', 'checkbox');
            card.setAttribute('data-addon-id', String(addon.id));
            if (addon.groupName) card.dataset.group = addon.groupName;

            const isSelected = !!(instance.addons && instance.addons.includes(addon.id));
            card.setAttribute('aria-checked', isSelected ? 'true' : 'false');
            if (isSelected) card.classList.add('is-selected');

            // Visually-hidden checkbox — still used by the existing
            // selectors in enforceMaxAddons / enforceMaxGroupItems.
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.tabIndex = -1;
            checkbox.value = addon.id;
            checkbox.id = `addon-checkbox-${fIndex}-${instance.optionIndex}-${addon.id}`;
            checkbox.classList.add('addon-checkbox');
            if (addon.groupName) checkbox.dataset.group = addon.groupName;
            checkbox.checked = isSelected;
            checkbox.setAttribute('aria-hidden', 'true');
            card.appendChild(checkbox);

            // Icon
            const iconEl = document.createElement('span');
            iconEl.classList.add('addon-card-icon');
            iconEl.setAttribute('aria-hidden', 'true');
            iconEl.innerHTML = pickAddonIcon(addon);
            card.appendChild(iconEl);

            // Body — name + (optional) description
            const body = document.createElement('span');
            body.classList.add('addon-card-body');

            const nameEl = document.createElement('span');
            nameEl.classList.add('addon-card-name');
            nameEl.textContent = addon.addonName || '';
            body.appendChild(nameEl);

            const descText = getDescriptionText(addon.description);
            if (descText) {
                const descEl = document.createElement('span');
                descEl.classList.add('addon-card-desc');
                descEl.textContent = descText;
                body.appendChild(descEl);
            }
            card.appendChild(body);

            // Meta — price + toggle indicator
            const meta = document.createElement('span');
            meta.classList.add('addon-card-meta');

            const priceEl = document.createElement('span');
            priceEl.classList.add('addon-card-price');
            const floorVal = parseSafe(addon.floorPriceMod);
            const ceilVal = parseSafe(addon.ceilingPriceMod);
            const symbol = isMultiply(addon) ? '×' : '+';
            if (floorVal !== 0 || ceilVal !== 0) {
                priceEl.textContent = `${symbol}$${floorVal.toFixed(2)} – $${ceilVal.toFixed(2)}`;
            } else {
                const addonStatic = parseSafe(addon.staticPriceMod, 0);
                if (isMultiply(addon)) {
                    priceEl.textContent = `× ${addonStatic}`;
                } else {
                    priceEl.textContent = `+$${addonStatic.toFixed(addonStatic % 1 === 0 ? 0 : 2)}`;
                }
            }
            meta.appendChild(priceEl);

            const toggle = document.createElement('span');
            toggle.classList.add('addon-card-toggle');
            toggle.setAttribute('aria-hidden', 'true');
            toggle.innerHTML =
                '<span class="addon-card-toggle-empty">' + UTILITY_ICONS.circle + '</span>' +
                '<span class="addon-card-toggle-checked">' + UTILITY_ICONS.circleCheck + '</span>';
            meta.appendChild(toggle);

            card.appendChild(meta);

            // Toggle handler — clicks the hidden checkbox so the existing
            // change handler on it fires (which mutates instance.addons,
            // calls enforceMax… and updateInvoice).
            function toggle_state() {
                if (card.classList.contains('is-disabled')) return;
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                card.classList.toggle('is-selected', checkbox.checked);
                card.setAttribute('aria-checked', checkbox.checked ? 'true' : 'false');
            }

            card.addEventListener('click', function(e) {
                e.preventDefault();
                toggle_state();
            });
            card.addEventListener('keydown', function(e) {
                if (e.key === ' ' || e.key === 'Enter') {
                    e.preventDefault();
                    toggle_state();
                }
            });

            // Same change-handler logic as the legacy createAddonCheckboxItem.
            checkbox.addEventListener('change', function() {
                if (!instance.addons) instance.addons = [];
                if (this.checked) {
                    if (!instance.addons.includes(addon.id)) {
                        instance.addons.push(addon.id);
                    }
                } else {
                    const idx = instance.addons.indexOf(addon.id);
                    if (idx !== -1) instance.addons.splice(idx, 1);
                }
                const addonsDiv = card.closest('.addons');
                if (addonsDiv) {
                    enforceMaxAddons(fIndex, instIndex, addonsDiv);
                    enforceMaxGroupItems(fIndex, instIndex, addonsDiv);
                    updateGroupDiscountDisplay(fIndex, instIndex, addonsDiv);
                }
                saveSelections();
                updateInvoice();
            });

            return card;
        }

        // ---------- Threshold-discount track ----------
        // Replaces the bullet <ul> of "20% off when you order 25+".
        // Returns the element + an `update(qty)` closure stored on
        // instance.__thresholdUpdater so the qty stepper can refresh
        // the active tier without re-rendering the whole panel.
        function renderThresholdTrack(thresholds, fIndex, instIndex, instance, optionMetric) {
            // Sort by itemCount asc, filter empty rows
            const sorted = thresholds
                .filter((t) => t.itemCount && t.discount)
                .map((t) => ({ itemCount: parseInt(t.itemCount), discount: parseFloat(t.discount) }))
                .sort((a, b) => a.itemCount - b.itemCount);
            if (!sorted.length) return null;

            const wrap = document.createElement('div');
            wrap.classList.add('threshold-track');

            const label = document.createElement('span');
            label.classList.add('overline', 'threshold-track-label');
            label.textContent = 'Bulk discount';
            wrap.appendChild(label);

            const tiers = document.createElement('ol');
            tiers.classList.add('threshold-track-tiers');
            const tileEls = sorted.map((tier) => {
                const li = document.createElement('li');
                li.classList.add('threshold-tier');
                li.dataset.min = String(tier.itemCount);
                li.dataset.pct = String(tier.discount);

                const iconEl = document.createElement('span');
                iconEl.classList.add('threshold-tier-icon');
                iconEl.setAttribute('aria-hidden', 'true');
                iconEl.innerHTML = UTILITY_ICONS.circle;

                const count = document.createElement('span');
                count.classList.add('threshold-tier-count');
                count.textContent = `${tier.itemCount}+`;

                const pct = document.createElement('span');
                pct.classList.add('threshold-tier-pct');
                pct.textContent = `${tier.discount}% off`;

                li.appendChild(iconEl);
                li.appendChild(count);
                li.appendChild(pct);
                tiers.appendChild(li);
                return li;
            });
            wrap.appendChild(tiers);

            const current = document.createElement('span');
            current.classList.add('threshold-track-current');
            wrap.appendChild(current);

            function update(qty) {
                const q = Math.max(1, parseInt(qty) || 1);
                let activeIdx = -1;
                tileEls.forEach((li, i) => {
                    const min = parseInt(li.dataset.min);
                    li.classList.remove('is-passed', 'is-active');
                    const icon = li.querySelector('.threshold-tier-icon');
                    if (q >= min) {
                        li.classList.add('is-passed');
                        if (icon) icon.innerHTML = UTILITY_ICONS.check;
                        activeIdx = i;
                    } else if (icon) {
                        icon.innerHTML = UTILITY_ICONS.circle;
                    }
                });
                if (activeIdx >= 0) {
                    tileEls[activeIdx].classList.remove('is-passed');
                    tileEls[activeIdx].classList.add('is-active');
                    const tier = sorted[activeIdx];
                    const unit = optionMetric ? optionMetric : 'item';
                    current.textContent = `Saving ${tier.discount}% on ${q} ${unit}${q === 1 ? '' : 's'}`;
                } else {
                    const next = sorted[0];
                    if (next) {
                        const unit = optionMetric ? optionMetric : 'item';
                        const need = next.itemCount - q;
                        current.textContent = `${need} more ${unit}${need === 1 ? '' : 's'} for ${next.discount}% off`;
                    } else {
                        current.textContent = '';
                    }
                }
            }

            // Initial paint with current quantity
            const startQty = parseInt(instance.quantity) || 1;
            update(startQty);

            // Stash on the instance so the qty stepper can call it.
            instance.__thresholdUpdater = update;

            return wrap;
        }

        // ---------- Quantity stepper ----------
        // [−] N [+] flex control. The native <input type="number"> is
        // kept inside (CSS hides webkit spinners) so the input is still
        // typeable and accessible. Buttons mutate the input value and
        // dispatch a 'change' so any other listeners (and the threshold
        // updater) fire identically to keyboard typing.
        function renderQuantityStepper(fIndex, instIndex, instance, feature, selectedOption) {
            let qty = parseInt(instance.quantity) || 1;
            if (qty < 1) qty = 1;
            instance.quantity = qty;

            const row = document.createElement('div');
            row.classList.add('form-row', 'quantity-row');

            const labelEl = document.createElement('span');
            labelEl.classList.add('form-label');
            labelEl.appendChild(document.createTextNode('Quantity'));
            if (selectedOption && selectedOption.optionMetric) {
                const metric = document.createElement('span');
                metric.classList.add('quantity-metric');
                metric.textContent = selectedOption.optionMetric;
                labelEl.appendChild(metric);
            }
            row.appendChild(labelEl);

            const stepper = document.createElement('div');
            stepper.classList.add('qty-stepper');
            stepper.setAttribute('role', 'group');
            stepper.setAttribute('aria-label', 'Quantity');

            const dec = document.createElement('button');
            dec.type = 'button';
            dec.classList.add('qty-step', 'qty-step-dec');
            dec.setAttribute('aria-label', 'Decrease quantity');
            dec.innerHTML = UTILITY_ICONS.minus;

            const input = document.createElement('input');
            input.type = 'number';
            input.classList.add('qty-step-input');
            input.min = '1';
            input.value = String(qty);
            input.inputMode = 'numeric';

            const inc = document.createElement('button');
            inc.type = 'button';
            inc.classList.add('qty-step', 'qty-step-inc');
            inc.setAttribute('aria-label', 'Increase quantity');
            inc.innerHTML = UTILITY_ICONS.plus;

            function commit(val) {
                let next = parseInt(val);
                if (isNaN(next) || next < 1) next = 1;
                input.value = String(next);
                instance.quantity = next;
                dec.disabled = next <= 1;
                if (typeof instance.__thresholdUpdater === 'function') {
                    instance.__thresholdUpdater(next);
                }
                saveSelections();
                updateInvoice();
            }

            dec.addEventListener('click', function() {
                commit((parseInt(input.value) || 1) - 1);
            });
            inc.addEventListener('click', function() {
                commit((parseInt(input.value) || 1) + 1);
            });
            input.addEventListener('change', function() {
                commit(this.value);
            });
            input.addEventListener('input', function() {
                // Live update so the threshold-track tier highlights
                // mid-typing — saveSelections / invoice still wait for
                // the change event to debounce.
                const live = parseInt(this.value);
                if (Number.isFinite(live) && live >= 1 && typeof instance.__thresholdUpdater === 'function') {
                    instance.__thresholdUpdater(live);
                }
            });

            // Initial disabled state for dec when at 1
            dec.disabled = qty <= 1;

            stepper.appendChild(dec);
            stepper.appendChild(input);
            stepper.appendChild(inc);
            row.appendChild(stepper);

            return row;
        }

        // ---------- Addon group panel (replaces inline-styled .addon-group) ----------
        function renderAddonGroup(group, fIndex, instIndex, instance, selectedOption) {
            const container = document.createElement('section');
            container.classList.add('addon-group');
            container.dataset.groupName = group.name;
            container.dataset.maxItems = group.maxItems;

            // Header: group name + counter
            const header = document.createElement('header');
            header.classList.add('addon-group-head');

            const nameEl = document.createElement('span');
            nameEl.classList.add('overline', 'addon-group-name');
            nameEl.textContent = group.name;
            header.appendChild(nameEl);

            const counter = document.createElement('span');
            counter.classList.add('addon-group-counter');
            const selectedCount = getSelectedGroupCount(instance, group.name, selectedOption.addons);
            const totalInGroup = group.addons.length;
            const maxLabel = group.maxItems > 0 ? group.maxItems : totalInGroup;
            counter.textContent = `${selectedCount} / ${maxLabel} selected`;
            header.appendChild(counter);

            container.appendChild(header);

            // Items
            const items = document.createElement('div');
            items.classList.add('addon-group-items');
            group.addons.forEach(({ addon, index: aIndex }) => {
                items.appendChild(renderAddonCard(addon, aIndex, fIndex, instIndex, instance));
            });
            container.appendChild(items);

            // Footer with discount status pill (filled in by
            // updateGroupDiscountDisplay; placeholder in .is-empty state).
            if (group.thresholdDiscounts && group.thresholdDiscounts.length) {
                const footer = document.createElement('footer');
                footer.classList.add('addon-group-footer');

                const status = document.createElement('span');
                status.classList.add('addon-group-status', 'is-empty');
                status.innerHTML =
                    '<span class="addon-group-status-icon" aria-hidden="true">' + UTILITY_ICONS.sparkle + '</span>' +
                    '<span class="addon-group-status-text"></span>';
                footer.appendChild(status);

                container.appendChild(footer);
            }

            return container;
        }

        function renderInstance(feature, fIndex, instIndex, instance) {
            const instanceDiv = document.createElement('div');
            instanceDiv.classList.add('feature');

            // Header — only show an eyebrow on additional instances
            // ("Option 2 / Option 3 …") so the first instance reads as
            // the default config. The delete button is icon-only and
            // absolutely positioned via CSS regardless of header content.
            const headerDiv = document.createElement('div');
            headerDiv.classList.add('instance-header');

            if (instIndex > 0) {
                const eyebrow = document.createElement('span');
                eyebrow.classList.add('overline', 'instance-eyebrow');
                eyebrow.textContent = `Option ${instIndex + 1}`;
                headerDiv.appendChild(eyebrow);
            }

            if (selections[fIndex] && selections[fIndex].length > 1) {
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.classList.add('instance-delete');
                deleteBtn.setAttribute('aria-label', 'Remove this option');
                deleteBtn.innerHTML = UTILITY_ICONS.x;
                deleteBtn.addEventListener('click', function() {
                    selections[fIndex].splice(instIndex, 1);
                    saveSelections();
                    renderFeatureInstances(feature, fIndex);
                    updateInvoice();
                });
                headerDiv.appendChild(deleteBtn);
            }
            instanceDiv.appendChild(headerDiv);

            // Render feature-level user fields before the option tiles
            renderFeatureLevelFields(feature, fIndex, instanceDiv, instance);

            // Container for option details (rendered below the tiles).
            // .is-hidden controls visibility instead of inline display
            // so the CSS rule's `display: flex; gap: 1.25rem` survives
            // when the panel is shown.
            const optionDetailsDiv = document.createElement('div');
            optionDetailsDiv.classList.add('option-details', 'is-hidden');
            // Append option-details AFTER the tile grid so DOM order
            // matches reading order.
            const tileGrid = renderOptionTiles(feature, fIndex, instIndex, instance, instanceDiv, optionDetailsDiv);
            instanceDiv.appendChild(tileGrid);
            instanceDiv.appendChild(optionDetailsDiv);

            // Render details if there's already a saved option
            if (instance.optionIndex !== undefined) {
                renderOptionDetails(fIndex, instIndex, feature, optionDetailsDiv);
            }

            return instanceDiv;
        }

        // Control max addons selection
        function enforceMaxAddons(fIndex, instIndex, addonsDiv) {
            const instance = selections[fIndex][instIndex];
            const option = dashboardData.features[fIndex].options[instance.optionIndex];

            // No constraint or unlimited (-1) → re-enable everything and
            // remove any lingering alert.
            if (!option || !option.maxAddons || option.maxAddons < 0) {
                const lingering = addonsDiv.querySelector('.max-addons-alert');
                if (lingering) lingering.remove();
                addonsDiv.querySelectorAll('.addon-card').forEach((card) => {
                    const cb = card.querySelector('input[type="checkbox"]');
                    if (cb && !cb.checked) {
                        card.classList.remove('is-disabled');
                        cb.disabled = false;
                    }
                });
                return;
            }

            const maxAllowed = parseInt(option.maxAddons);
            const checkboxes = addonsDiv.querySelectorAll('input[type="checkbox"]');
            const checkedCount = (instance.addons || []).length;

            // Lock unchecked cards if the limit is reached; unlock when
            // the user deselects something. Mirror the disabled state on
            // both the hidden checkbox and the .addon-card wrapper so the
            // CSS .is-disabled treatment kicks in.
            checkboxes.forEach((checkbox) => {
                const card = checkbox.closest('.addon-card');
                if (!checkbox.checked) {
                    const shouldDisable = checkedCount >= maxAllowed;
                    checkbox.disabled = shouldDisable;
                    if (card) card.classList.toggle('is-disabled', shouldDisable);
                } else {
                    checkbox.disabled = false;
                    if (card) card.classList.remove('is-disabled');
                }
            });

            // Inline alert: show only when at the limit.
            let alertEl = addonsDiv.querySelector('.max-addons-alert');
            if (checkedCount >= maxAllowed) {
                const message = `Maximum of ${maxAllowed} add-on${maxAllowed === 1 ? '' : 's'} selected.`;
                if (!alertEl) {
                    alertEl = renderInlineAlert(message, 'warning');
                    alertEl.classList.add('max-addons-alert');
                    addonsDiv.appendChild(alertEl);
                } else {
                    const t = alertEl.querySelector('.ff-alert-text');
                    if (t) t.textContent = message;
                }
            } else if (alertEl) {
                alertEl.remove();
            }
        }

        // Render the raw option details (no addition with addons)
        function renderOptionDetails(fIndex, instIndex, feature, optionDetailsDiv) {
            optionDetailsDiv.innerHTML = '';
            const instance = selections[fIndex][instIndex];
            if (instance.optionIndex === undefined || isNaN(instance.optionIndex)) {
                optionDetailsDiv.classList.add('is-hidden');
                saveSelections();
                updateInvoice();
                return;
            }
            const selectedOption = feature.options[instance.optionIndex];
            if (!selectedOption) {
                optionDetailsDiv.classList.add('is-hidden');
                saveSelections();
                updateInvoice();
                return;
            }
            optionDetailsDiv.classList.remove('is-hidden');

            // Show the raw option data. If it has a range, show that. Otherwise, show static.
            const descText = getDescriptionText(selectedOption.description);
            const floorVal = parseSafe(selectedOption.priceFloor);
            const ceilVal  = parseSafe(selectedOption.priceCeiling);
            let optionPriceText = '';

            // If there's a range for the option
            if (floorVal !== 0 || ceilVal !== 0) {
                optionPriceText = `$${floorVal.toFixed(2)} - $${ceilVal.toFixed(2)}`;
            } else {
                // Otherwise, show static
                const staticP = parseSafe(selectedOption.staticPrice, 0);
                optionPriceText = `$${staticP.toFixed(2)}`;
            }

            // Description as a flat paragraph (no "Description:" prefix).
            if (descText) optionDetailsDiv.appendChild(renderOptionSummary(descText));

            // _display fields → label + amber pill (Profile-card aesthetic).
            for (const key in selectedOption) {
                if (key.endsWith('_display') && selectedOption[key] !== null) {
                    try {
                        let fieldData;
                        let fieldType = 'normal-text';
                        let displayValue = '';
                        if (typeof selectedOption[key] === 'string') {
                            try {
                                fieldData = JSON.parse(selectedOption[key]);
                                if (fieldData) {
                                    fieldType = fieldData.ui_type || 'normal-text';
                                    if (fieldType === 'array' && Array.isArray(fieldData.types)) {
                                        const selIdx = fieldData.selected || 0;
                                        displayValue = fieldData.types[selIdx] || '';
                                    } else {
                                        displayValue = fieldData.value || '';
                                    }
                                }
                            } catch (e) {
                                displayValue = selectedOption[key];
                            }
                        } else if (typeof selectedOption[key] === 'object') {
                            fieldData = selectedOption[key];
                            if (fieldData.ui_type === 'array' && Array.isArray(fieldData.types)) {
                                const selIdx = fieldData.selected || 0;
                                displayValue = fieldData.types[selIdx] || '';
                            } else if (fieldData.value !== undefined) {
                                displayValue = fieldData.value;
                            }
                        } else {
                            displayValue = selectedOption[key];
                        }
                        optionDetailsDiv.appendChild(
                            renderDisplayFieldRow(formatFieldLabel(key), displayValue)
                        );
                    } catch (e) {
                        console.error(`Error processing display field ${key}:`, e);
                    }
                }
            }

            // Detect pricingType=price options to skip the static price row.
            let showPriceOptions = false;
            if (selectedOption.pricingType) {
                try {
                    if (typeof selectedOption.pricingType === 'string') {
                        showPriceOptions = (selectedOption.pricingType === 'price options');
                    } else if (typeof selectedOption.pricingType === 'object') {
                        const pt = selectedOption.pricingType;
                        if (pt.value && pt.value.types && pt.value.selected !== undefined) {
                            showPriceOptions = (pt.value.types[pt.value.selected] === 'price options');
                        }
                    }
                } catch (e) { showPriceOptions = false; }
            }

            // Static / range price → label + amber price chip.
            if (!showPriceOptions) {
                optionDetailsDiv.appendChild(
                    renderPriceRow(optionPriceText, !!feature.recurring, selectedOption.interval)
                );
            }
            
            let priceOptionsArray = [];
            if (selectedOption.priceOptions) {
                try {
                    if (typeof selectedOption.priceOptions === 'string') {
                        priceOptionsArray = JSON.parse(selectedOption.priceOptions).types || [];
                    } else if (selectedOption.priceOptions.types) {
                        priceOptionsArray = selectedOption.priceOptions.types;
                    }
                } catch(e) {
                    console.error("Error parsing price options:", e);
                    priceOptionsArray = [];
                }
            }

            // Only show price options selector if pricingType is "price options" AND we have options data
            if (showPriceOptions && priceOptionsArray && priceOptionsArray.length > 0) {
                if (instance.priceOptionIndex === undefined) {
                    instance.priceOptionIndex = 0;
                }

                const instanceDiv = optionDetailsDiv.parentNode;
                const featureFieldsDiv = instanceDiv.querySelector(
                    `#feature-${feature.featureName.toLowerCase().replace(/\s+/g, '-')}`
                );
                if (featureFieldsDiv) featureFieldsDiv.style.display = 'block';

                optionDetailsDiv.appendChild(
                    renderSegmentedPriceOptions(fIndex, instIndex, instance, priceOptionsArray)
                );
            }

            // Threshold-discount track (shows always when thresholds defined,
            // not just for non-recurring — server-side calc respects qty=1
            // for recurring features so the track just sits at the
            // "0 → first tier" call-out).
            if (selectedOption.thresholdDiscounts) {
                try {
                    let thresholds = [];
                    if (typeof selectedOption.thresholdDiscounts === 'string') {
                        thresholds = JSON.parse(selectedOption.thresholdDiscounts);
                    } else if (selectedOption.thresholdDiscounts.types) {
                        thresholds = selectedOption.thresholdDiscounts.types;
                    } else if (Array.isArray(selectedOption.thresholdDiscounts)) {
                        thresholds = selectedOption.thresholdDiscounts;
                    }

                    if (Array.isArray(thresholds) && thresholds.some((t) => t.itemCount && t.discount)) {
                        const track = renderThresholdTrack(
                            thresholds,
                            fIndex,
                            instIndex,
                            instance,
                            selectedOption.optionMetric || ''
                        );
                        if (track) optionDetailsDiv.appendChild(track);
                    }
                } catch (e) {
                    console.error('Error rendering threshold-track:', e);
                }
            }

            // _user fields → shared renderUserFieldRow helper. Promotes
            // every input to the .form-input pattern so the calculator's
            // inputs match the Profile card aesthetic exactly.
            for (const key in selectedOption) {
                if (key.endsWith('_user') && !key.endsWith('_display') && selectedOption[key] !== null) {
                    try {
                        optionDetailsDiv.appendChild(
                            renderUserFieldRow(selectedOption[key], key, fIndex, instIndex, instance, '_user')
                        );
                    } catch (e) {
                        console.error(`Error processing user field ${key}:`, e);
                    }
                }
            }

            // Quantity stepper for non-recurring features.
            if (!feature.recurring) {
                optionDetailsDiv.appendChild(
                    renderQuantityStepper(fIndex, instIndex, instance, feature, selectedOption)
                );
            }

            // ---------- Addons block ----------
            // Header (overline) + ungrouped list + per-group panels.
            // Each addon renders via renderAddonCard; groups via
            // renderAddonGroup. Discount status pills inside groups are
            // populated by updateGroupDiscountDisplay below.
            if (selectedOption.addons && selectedOption.addons.length > 0) {
                const addonsDiv = document.createElement('div');
                addonsDiv.classList.add('addons');

                const addonsHead = document.createElement('div');
                addonsHead.classList.add('addons-head');
                const addonsLabel = document.createElement('span');
                addonsLabel.classList.add('overline');
                addonsLabel.textContent = 'Add-ons';
                addonsHead.appendChild(addonsLabel);
                addonsDiv.appendChild(addonsHead);

                const { groups, ungrouped } = organizeAddonsByGroup(selectedOption.addons);

                if (ungrouped.length) {
                    const list = document.createElement('div');
                    list.classList.add('addons-list');
                    ungrouped.forEach(({ addon, index: aIndex }) => {
                        list.appendChild(renderAddonCard(addon, aIndex, fIndex, instIndex, instance));
                    });
                    addonsDiv.appendChild(list);
                }

                Object.values(groups).forEach((group) => {
                    addonsDiv.appendChild(
                        renderAddonGroup(group, fIndex, instIndex, instance, selectedOption)
                    );
                });

                optionDetailsDiv.appendChild(addonsDiv);

                // Initial constraint + discount-display pass.
                enforceMaxAddons(fIndex, instIndex, addonsDiv);
                enforceMaxGroupItems(fIndex, instIndex, addonsDiv);
                updateGroupDiscountDisplay(fIndex, instIndex, addonsDiv);
            }
            
            saveSelections();
            updateInvoice();
        }

        // Helper to create addon checkbox items
        function createAddonCheckboxItem(addon, aIndex, fIndex, instIndex, instance) {
            const container = document.createElement('div');
            container.classList.add('addon-item');
            
            // Create checkbox
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = addon.id; // Use the actual addon ID instead of index
            checkbox.id = `addon-checkbox-${fIndex}-${instance.optionIndex}-${addon.id}`;
            checkbox.classList.add('addon-checkbox');
            if (addon.groupName) {
                checkbox.dataset.group = addon.groupName;
            }
            
            // Check if this addon is selected by ID instead of index
            if (instance.addons && instance.addons.includes(addon.id)) {
                checkbox.checked = true;
            }
            
            // Checkbox event handler
            checkbox.addEventListener('change', function() {
                if (!instance.addons) {
                    instance.addons = [];
                }
                if (this.checked) {
                    instance.addons.push(addon.id); // Store actual addon ID
                } else {
                    const idx = instance.addons.indexOf(addon.id);
                    if (idx !== -1) {
                        instance.addons.splice(idx, 1);
                    }
                }
                
                // After changing selection, enforce max addons and max group items
                const addonsDiv = this.closest('.addons');
                enforceMaxAddons(fIndex, instIndex, addonsDiv);
                enforceMaxGroupItems(fIndex, instIndex, addonsDiv);
                
                // Update discount info displays for all groups
                updateGroupDiscountDisplay(fIndex, instIndex, addonsDiv);
                
                saveSelections();
                updateInvoice();
            });
            
            container.appendChild(checkbox);
            
            // Get description and add tooltip if needed
            const addonDescription = getDescriptionText(addon.description);
            
            if (addonDescription) {
                const tooltipIcon = document.createElement('span');
                tooltipIcon.classList.add('tooltip-icon');
                tooltipIcon.textContent = '?';
                tooltipIcon.setAttribute('data-addon-name', addon.addonName);
                tooltipIcon.setAttribute('data-addon-desc', addonDescription);
                
                tooltipIcon.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                showTooltip(addon.addonName, addonDescription);
                });
                
                container.appendChild(tooltipIcon);
            } else {
                // Add a spacer element to maintain consistent layout
                const spacer = document.createElement('span');
                spacer.style.width = '16px';
                spacer.style.margin = '0 8px 0 8px';
                spacer.style.display = 'inline-block';
                container.appendChild(spacer);
            }
            
            // Create addon text span with proper class
            const addonTextSpan = document.createElement('span');
            addonTextSpan.classList.add('addon-text');
            
            // Price calculation and display
            const floorVal = parseSafe(addon.floorPriceMod);
            const ceilVal = parseSafe(addon.ceilingPriceMod);
            const symbol = isMultiply(addon) ? 'x' : '+';
            
            if (floorVal !== 0 || ceilVal !== 0) {
                addonTextSpan.textContent = `${addon.addonName} (${symbol}$${floorVal.toFixed(2)} - $${ceilVal.toFixed(2)})`;
            } else {
                const addonStatic = parseSafe(addon.staticPriceMod, 0);
                addonTextSpan.textContent = `${addon.addonName} (${symbol}$${addonStatic.toFixed(2)})`;
            }
            
            container.appendChild(addonTextSpan);
            return container;
            }

            // Update the discount status pill + counter on each group panel.
            function updateGroupDiscountDisplay(fIndex, instIndex, addonsDiv) {
                const instance = selections[fIndex][instIndex];
                const option = dashboardData.features[fIndex].options[instance.optionIndex];
                if (!option || !option.addons) return;

                const groupContainers = addonsDiv.querySelectorAll('.addon-group');

                groupContainers.forEach((container) => {
                    const groupName = container.dataset.groupName;
                    if (!groupName) return;

                    const groupAddons = option.addons.filter(
                        (a) => a.groupName === groupName && a.enableGrouping
                    );
                    const selectedCount = getSelectedGroupCount(instance, groupName, option.addons);

                    // Counter: "X / Y selected" — Y is maxItems if set,
                    // else total in group.
                    const counter = container.querySelector('.addon-group-counter');
                    if (counter) {
                        const maxItems = parseInt(container.dataset.maxItems);
                        const cap = Number.isFinite(maxItems) && maxItems > 0
                            ? maxItems
                            : groupAddons.length;
                        counter.textContent = `${selectedCount} / ${cap} selected`;
                    }

                    // Status pill (only present when group has thresholds).
                    const status = container.querySelector('.addon-group-status');
                    if (!status) return;

                    const statusText = status.querySelector('.addon-group-status-text');
                    const statusIcon = status.querySelector('.addon-group-status-icon');
                    status.classList.remove('is-active', 'is-pending', 'is-empty');

                    const thresholdDiscounts = groupAddons.length
                        ? parseThresholdDiscounts(groupAddons[0].groupThresholdDiscounts)
                        : [];
                    if (!thresholdDiscounts.length) {
                        status.classList.add('is-empty');
                        if (statusText) statusText.textContent = '';
                        return;
                    }

                    const sorted = [...thresholdDiscounts].sort((a, b) => a.itemCount - b.itemCount);
                    const passed = sorted.filter((d) => selectedCount >= d.itemCount);
                    const applicable = passed.length ? passed[passed.length - 1] : null;
                    const next = sorted.find((d) => selectedCount < d.itemCount);

                    // Highest tier reached → green "active"; partway → amber
                    // "pending"; none reached and no next tier → empty.
                    if (applicable && !next) {
                        status.classList.add('is-active');
                        if (statusIcon) statusIcon.innerHTML = UTILITY_ICONS.check;
                        if (statusText) statusText.textContent = `${applicable.discount}% group discount unlocked`;
                    } else if (applicable) {
                        status.classList.add('is-active');
                        if (statusIcon) statusIcon.innerHTML = UTILITY_ICONS.check;
                        if (statusText) {
                            statusText.textContent = `${applicable.discount}% off · pick ${next.itemCount - selectedCount} more for ${next.discount}%`;
                        }
                    } else if (next) {
                        status.classList.add('is-pending');
                        if (statusIcon) statusIcon.innerHTML = UTILITY_ICONS.sparkle;
                        if (statusText) {
                            statusText.textContent = `Pick ${next.itemCount - selectedCount} more for ${next.discount}% off`;
                        }
                    } else {
                        status.classList.add('is-empty');
                        if (statusText) statusText.textContent = '';
                    }
                });
            }

            // Count selected addons in a specific group
            function getSelectedGroupCount(instance, groupName, allAddons) {
            if (!instance.addons || !Array.isArray(instance.addons)) return 0;
            
            return instance.addons.filter(addonId => {
                const addon = allAddons.find(a => a.id === addonId);
                return addon && addon.groupName === groupName && addon.enableGrouping;
            }).length;
        }

        // Enforce max group items limitation
        function enforceMaxGroupItems(fIndex, instIndex, addonsDiv) {
            const instance = selections[fIndex][instIndex];
            const option = dashboardData.features[fIndex].options[instance.optionIndex];
            if (!option || !option.addons) return;

            const groupContainers = addonsDiv.querySelectorAll('.addon-group');

            groupContainers.forEach((container) => {
                const groupName = container.dataset.groupName;
                const maxItems = parseInt(container.dataset.maxItems);
                const groupCheckboxes = container.querySelectorAll('input[type="checkbox"]');

                // No per-group cap → make sure every unchecked card in
                // this group is re-enabled (option-level enforceMaxAddons
                // may still disable them later if the option-level cap
                // is reached).
                if (!groupName || !Number.isFinite(maxItems) || maxItems <= 0) {
                    groupCheckboxes.forEach((checkbox) => {
                        const card = checkbox.closest('.addon-card');
                        if (!checkbox.checked) {
                            checkbox.disabled = false;
                            if (card) card.classList.remove('is-disabled');
                        }
                    });
                    return;
                }

                const selectedCount = getSelectedGroupCount(instance, groupName, option.addons);

                groupCheckboxes.forEach((checkbox) => {
                    const card = checkbox.closest('.addon-card');
                    if (!checkbox.checked) {
                        const shouldDisable = selectedCount >= maxItems;
                        checkbox.disabled = shouldDisable;
                        if (card) card.classList.toggle('is-disabled', shouldDisable);
                    } else {
                        checkbox.disabled = false;
                        if (card) card.classList.remove('is-disabled');
                    }
                });
            });
        }

        // Helper function to organize addons by group
        function organizeAddonsByGroup(addons) {
            const groups = {};
            const ungrouped = [];
            
            addons.forEach((addon, index) => {
                if (addon.groupName && addon.enableGrouping) {
                if (!groups[addon.groupName]) {
                    groups[addon.groupName] = {
                    name: addon.groupName,
                    maxItems: addon.maxGroupItems,
                    addons: [],
                    thresholdDiscounts: parseThresholdDiscounts(addon.groupThresholdDiscounts)
                    };
                }
                groups[addon.groupName].addons.push({addon, index});
                } else {
                ungrouped.push({addon, index});
                }
            });
            
            return { groups, ungrouped };
        }

        // Global tooltip functions
        function showTooltip(title, description) {
            // Remove any existing tooltips
            let existingTooltip = document.querySelector('.tooltip-overlay');
            if (existingTooltip) {
                document.body.removeChild(existingTooltip);
            }
            
            // Create new tooltip
            const overlay = document.createElement('div');
            overlay.classList.add('tooltip-overlay');
            
            const content = document.createElement('div');
            content.classList.add('tooltip-content');
            
            const tooltipTitle = document.createElement('div');
            tooltipTitle.classList.add('tooltip-title');
            tooltipTitle.textContent = title;
            
            const tooltipBody = document.createElement('div');
            tooltipBody.classList.add('tooltip-body');
            tooltipBody.textContent = description;
            
            content.appendChild(tooltipTitle);
            content.appendChild(tooltipBody);
            overlay.appendChild(content);
            
            // Add to body
            document.body.appendChild(overlay);
            
            // Force a reflow before adding the active class
            void overlay.offsetWidth;
            
            // Add active class to trigger animations
            overlay.classList.add('active');
            
            // Click handler to close when clicking outside
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    closeTooltip(overlay);
                }
            });
            
            // Add swipe gesture support
            let startX, startY, distX, distY;
            let threshold = 100; // Minimum distance required for a swipe
            
            content.addEventListener('touchstart', function(e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
                e.preventDefault();
            }, false);
            
            content.addEventListener('touchmove', function(e) {
                if (!startX || !startY) return;
                
                distX = e.touches[0].clientX - startX;
                distY = e.touches[0].clientY - startY;
                e.preventDefault();
            }, false);
            
            content.addEventListener('touchend', function(e) {
                if (!startX || !startY) return;
                
                // Check if horizontal swipe distance is greater than threshold
                if (Math.abs(distX) >= threshold || Math.abs(distY) >= threshold) {
                    closeTooltip(overlay);
                }
                
                // Reset values
                startX = startY = distX = distY = null;
                e.preventDefault();
            }, false);
            
            // Also close when pressing escape
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    closeTooltip(overlay);
                    document.removeEventListener('keydown', escHandler);
                }
            });
        }

        function closeTooltip(overlay) {
            overlay.classList.remove('active');
            
            // Remove from DOM after animation completes
            setTimeout(() => {
                if (overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            }, 300); // Match transition duration
        }

        // Helper to re-render all instances for a given feature type
        function renderFeatureInstances(feature, fIndex) {
            const featureTypeDiv = document.querySelectorAll('.feature-type')[fIndex];
            const instancesContainer = featureTypeDiv.querySelector('.instances-container');
            instancesContainer.innerHTML = '';
            selections[fIndex].forEach((instance, instIndex) => {
                const instanceDiv = renderInstance(feature, fIndex, instIndex, instance);
                instancesContainer.appendChild(instanceDiv);
            });
        }

        // Render all feature types with their instances
        dashboardData.features.forEach((feature, fIndex) => {
            if (!selections[fIndex] || !Array.isArray(selections[fIndex])) {
                selections[fIndex] = [{}]; // Start with one empty instance
            }

            const featureTypeDiv = document.createElement('div');
            featureTypeDiv.classList.add('feature-type');

            // Decorative icon — picks a relevant SVG based on featureName
            // keywords so the price calculator reads as a real product
            // configurator rather than raw form fields. CSS positions it
            // top-right of the .feature-type card.
            const iconWrap = document.createElement('div');
            iconWrap.className = 'feature-type-icon';
            iconWrap.setAttribute('aria-hidden', 'true');
            iconWrap.innerHTML = pickFeatureIcon(feature.featureName);
            featureTypeDiv.appendChild(iconWrap);

            // Header with feature name + description
            const header = document.createElement('h3');
            header.textContent = feature.featureName;
            featureTypeDiv.appendChild(header);

            const descriptionP = document.createElement('p');
            descriptionP.textContent = feature.description;
            featureTypeDiv.appendChild(descriptionP);

            // Container for the instances
            const instancesContainer = document.createElement('div');
            instancesContainer.classList.add('instances-container');

            // Render each instance
            selections[fIndex].forEach((instance, instIndex) => {
                const instanceDiv = renderInstance(feature, fIndex, instIndex, instance);
                instancesContainer.appendChild(instanceDiv);
            });
            featureTypeDiv.appendChild(instancesContainer);

            // "Add new" button — uses the .btn .btn-ghost design-system
            // styling plus the dashed-border .add-new-feature modifier.
            // The leading "+" glyph is now an inline SVG (utility icon)
            // instead of the legacy ::before content rule.
            const addNewBtn = document.createElement('button');
            addNewBtn.type = 'button';
            addNewBtn.classList.add('btn', 'btn-ghost', 'add-new-feature');
            const addIcon = document.createElement('span');
            addIcon.classList.add('add-new-feature-icon');
            addIcon.setAttribute('aria-hidden', 'true');
            addIcon.innerHTML = UTILITY_ICONS.plus;
            addNewBtn.appendChild(addIcon);
            addNewBtn.appendChild(document.createTextNode(`Add another ${feature.featureName.toLowerCase()}`));
            addNewBtn.addEventListener('click', function() {
                selections[fIndex].push({});
                saveSelections();
                renderFeatureInstances(feature, fIndex);
                updateInvoice();
            });
            featureTypeDiv.appendChild(addNewBtn);

            featuresContainer.appendChild(featureTypeDiv);

            // Reveal animation — stagger feature cards as they appear.
            featureTypeDiv.classList.add('reveal');
            requestAnimationFrame(() => {
                requestAnimationFrame(() => featureTypeDiv.classList.add('is-in'));
            });
        });

        // Update profile handler
        const updateProfileBtn = document.getElementById('update-profile-btn');
        if (updateProfileBtn) updateProfileBtn.addEventListener('click', async () =>{
            if (dashboardData.campaign_config) return;

            const profileMessage   = document.getElementById('profile-message');
            const updateProfileBtn = document.getElementById('update-profile-btn');

            // Gather form data
            const firstName = document.getElementById('profile-first-name').value.trim();
            const lastName  = document.getElementById('profile-last-name').value.trim();
            const email     = document.getElementById('profile-email').value.trim();

            // Prepare request
            const url = `${myApi.api_url}update-profile`;
            const options = {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                first_name: firstName,
                last_name:  lastName,
                email:      email,
                auth_id:    window.auth_id
                })
            };

            // Clear prior messages and show loader
            profileMessage.textContent = '';
            updateProfileBtn.innerHTML = `<img class="loader" src="${window.template_path}/images/loading.gif" alt="Loading…" style="max-width: 15px;">`;

            try {
                const response = await fetch(url, options);

                // If we got a 403 specifically, handle it first
                if (response.status === 403) {
                    profileMessage.textContent = 'You\'re not authorized to update this profile.';
                    return;
                }

                // For any non-2xx status, attempt to pull an error message from JSON
                if (!response.ok) {
                    let errMsg = response.statusText; // fallback
                try {
                    const errJson = await response.json();
                    // adjust these keys to whatever your API returns
                    errMsg = errJson.error || errJson.message || errMsg;
                } catch (e) {
                    // non-JSON body or parse failed; keep statusText
                }
                    profileMessage.textContent = `Error (${response.status}): ${errMsg}`;
                    return;
                }

                // Success path
                const data = await response.json();
                profileMessage.textContent = data.message || 'Profile updated successfully.';
            }
            catch (networkError) {
                // e.g. DNS failure or network down
                profileMessage.textContent = `Network error: ${networkError.message}`;
            }
            finally {
                // Restore the button text in all cases
                updateProfileBtn.innerHTML = 'Update Profile';
            }
        });

        // Reset password handler
        const resetPasswordBtn = document.getElementById('reset-password-btn');
        if (resetPasswordBtn) {
            resetPasswordBtn.addEventListener('click', async () => {
                const profileMessage = document.getElementById('profile-message');
                // Prepare request
                const url = `${myApi.api_url}reset-password`;
                const options = {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        auth_id:    window.auth_id
                    })
                };

                // Clear prior messages and show loader
                profileMessage.textContent = '';
                resetPasswordBtn.innerHTML = `<img class="loader" src="${window.template_path}/images/loading.gif" alt="Loading…" style="max-width: 15px;">`

                try {
                    const response = await fetch(url, options);

                    // If we got a 403 specifically, handle it first
                    if (response.status === 403) {
                        profileMessage.textContent = 'You\'re not authorized to update this profile.';
                        return;
                    }

                    // For any non-2xx status, attempt to pull an error message from JSON
                    if (!response.ok) {
                        let errMsg = response.statusText; // fallback
                    try {
                        const errJson = await response.json();
                        // adjust these keys to whatever your API returns
                        errMsg = errJson.error || errJson.message || errMsg;
                    } catch (e) {
                        // non-JSON body or parse failed; keep statusText
                    }
                        profileMessage.textContent = `Error (${response.status}): ${errMsg}`;
                        return;
                    }

                    // Success path
                    const data = await response.json();
                    profileMessage.textContent = data.message || 'Password reset email sent.';
                }
                catch (networkError) {
                    // e.g. DNS failure or network down
                    profileMessage.textContent = `Network error: ${networkError.message}`;
                }
                finally {
                    // Restore the button text in all cases
                    resetPasswordBtn.innerHTML = 'Send Password Reset';
                }
            });
        }

        // Finally, build the invoice
        updateInvoice();

        // Stub pay-now button
        const payNowBtn = document.getElementById('pay-now');
        if (payNowBtn) {
            payNowBtn.removeEventListener('click', payNowBtn.onclick);
            updateOrderButton();
        }

        // ---------------------------------------------------------------
        // Stripe integration
        // ---------------------------------------------------------------
        let stripe, elements, paymentElement;
        
        // Decide which Stripe confirm* to call based on server response
        async function confirmByIntentType(resp, elements) {
        const s = stripe || window.stripe;
        if (!s) throw new Error('Stripe.js not initialized');

        const confirmParams = { return_url: window.location.href };

        if (resp.intentType === 'payment_intent') {
            const { error, paymentIntent } = await s.confirmPayment({
                elements,
                confirmParams,
                redirect: 'if_required',
            });
            if (error) throw error;
            return { kind: 'payment_intent', id: paymentIntent.id };
        } else {
            const { error, setupIntent } = await s.confirmSetup({
                elements,
                confirmParams,
                redirect: 'if_required',
            });
            if (error) throw error;
            return { kind: 'setup_intent', id: setupIntent.id };
        }
        }

        // Initialize Stripe if the library is loaded
        if (typeof Stripe !== 'undefined' && dashboardData.stripeKey) {
            
            if (window.Stripe && navigator.onLine) {
                // Initialize Stripe only when online
                stripe = Stripe(dashboardData.stripeKey);
                window.stripe = stripe;
            } else {
                console.log('Stripe not available offline');
                // Disable payment features or show offline message
            }
            
            // Check URL parameters for payment status
            checkPaymentStatus();
            
            // Initialize payment if there's a valid order that's not yet paid
            if (hasValidOrder() && !isOrderPaid()) {
                initializeStripePayment();
            } else if (isOrderPaid()) {
                // If order is already paid, show success
                showPaymentSuccess();
            }
        }
        
        // Function to initialize Stripe payment form
        async function initializeStripePayment() {
            
            if (isOrderPaid()) {
                showPaymentSuccess();
                return;
            }

            if (!stripe) {
                console.error('Stripe not properly configured');
                return;
            }
            
            // Get existing order data
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) {
                console.error('No order data found');
                return;
            }
            
            const orderInfo = JSON.parse(orderData);
            if (!orderInfo || !orderInfo.orderID) {
                console.error('Invalid order data');
                return;
            }
            
            // Create payment intent
            const overlay = showLoadingOverlay();

            // Prepare request
            const url = `${myApi.api_url}create-payment-intent`;
            const options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    orderID: orderInfo.orderID,
                    auth_id: window.auth_id
                })
            };

            try {
                const response = await fetch(url, options);

                // If we got a 403 specifically, handle it first
                if (response.status === 403) {
                    console.error('You\'re not authorized to create this payment intent.');
                    return;
                }

                // For any non-2xx status, attempt to pull an error message from JSON
                if (!response.ok) {
                    let errMsg = response.statusText; // fallback
                try {
                    const errJson = await response.json();
                    // adjust these keys to whatever your API returns
                    errMsg = errJson.error || errJson.message || errMsg;
                } catch (e) {
                    // non-JSON body or parse failed; keep statusText
                }
                    console.error(`Error (${response.status}): ${errMsg}`);
                    return;
                }

                // Success path
                const data = await response.json();

                // Check if payments are disabled
                if (data.payment_disabled) {
                    hideLoadingOverlay(overlay);
                    showOrderSuccess();
                    return;
                }

                if (data.success && data.clientSecret) {
                    // Create the payment form
                    createPaymentForm(data.clientSecret);
                } else {
                    throw new Error('Invalid response from server');
                }
                hideLoadingOverlay(overlay);
            }
            catch (error) {
                console.error('Payment intent error:', error);
                alert('Error initializing payment: ' + error.message);
                hideLoadingOverlay(overlay);
            }
        }
        
        // Create and mount the Stripe payment form. The form lives in a
        // centered overlay modal — backdrop, blur, all chrome matches the
        // existing .dash-modal pattern. The Pay Now button is relocated
        // into the modal so it's reachable above the backdrop, then
        // restored to its origin if the user cancels.
        function createPaymentForm(clientSecret) {
            if (isOrderPaid()) {
                showPaymentSuccess();
                return;
            }

            const payNowBtn = document.getElementById('pay-now');
            // Remember where Pay Now lived so Cancel can put it back.
            const payNowOrigin = {
                parent: payNowBtn.parentNode,
                next:   payNowBtn.nextSibling
            };

            // Build the modal shell.
            const modal = document.createElement('div');
            modal.id = 'payment-form-modal';
            modal.className = 'dash-modal payment-form-modal';
            modal.setAttribute('role', 'dialog');
            modal.setAttribute('aria-modal', 'true');
            modal.setAttribute('aria-labelledby', 'payment-form-title');

            const content = document.createElement('div');
            content.className = 'dash-modal-content payment-form-content';

            const heading = document.createElement('h3');
            heading.id = 'payment-form-title';
            heading.textContent = 'Complete payment';
            content.appendChild(heading);

            const description = document.createElement('p');
            description.className = 'payment-form-description';
            description.textContent = 'Enter your card details to finish placing your order.';
            content.appendChild(description);

            const form = document.createElement('form');
            form.id = 'payment-form';

            const paymentElementDiv = document.createElement('div');
            paymentElementDiv.id = 'payment-element';
            form.appendChild(paymentElementDiv);

            const errorDiv = document.createElement('div');
            errorDiv.id = 'payment-error';
            errorDiv.className = 'form-message';
            errorDiv.style.display = 'none';
            form.appendChild(errorDiv);

            content.appendChild(form);

            // Action row: Cancel + relocated Pay Now button.
            const btnRow = document.createElement('div');
            btnRow.className = 'btn-row payment-form-actions';

            const cancelBtn = document.createElement('button');
            cancelBtn.type = 'button';
            cancelBtn.className = 'btn btn-ghost';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.addEventListener('click', closePaymentFormModal);

            btnRow.appendChild(cancelBtn);
            btnRow.appendChild(payNowBtn);
            content.appendChild(btnRow);

            modal.appendChild(content);
            document.body.appendChild(modal);

            // Backdrop click dismisses too.
            modal.addEventListener('click', function(e) {
                if (e.target === modal) closePaymentFormModal();
            });

            // Initialize Stripe Elements with our shared dark/amber appearance.
            elements = stripe.elements({
                clientSecret: clientSecret,
                appearance: fireflyStripeAppearance()
            });

            paymentElement = elements.create('payment');
            paymentElement.mount('#payment-element');

            // Re-enable Pay Now and wire it to the payment handler.
            payNowBtn.textContent = 'Pay Now';
            payNowBtn.disabled = false;
            payNowBtn.onclick = function(e) {
                e.preventDefault();
                payNowBtn.disabled = true;
                payNowBtn.textContent = 'Processing...';
                handlePayment(e);
            };

            // Stash the cleanup so handlers can call it on success/error.
            window._closePaymentFormModal = closePaymentFormModal;

            function closePaymentFormModal() {
                if (paymentElement) {
                    try { paymentElement.unmount(); } catch (e) { /* ignore */ }
                    paymentElement = null;
                }
                elements = null;

                // Restore Pay Now to its original location.
                if (payNowOrigin.parent) {
                    payNowBtn.disabled = false;
                    payNowBtn.textContent = 'Pay now';
                    payNowOrigin.parent.insertBefore(payNowBtn, payNowOrigin.next);
                }
                modal.remove();
                window._closePaymentFormModal = null;
            }
        }
        
        // Handle the payment submission
        async function handlePayment(event) {
            event.preventDefault();
            
            if (!stripe || !elements) {
                console.error('Stripe not initialized');
                return;
            }
            
            const payNowBtn = document.getElementById('pay-now');
            const errorDisplay = document.getElementById('payment-error');
            
            // Disable the button and show loading state
            payNowBtn.disabled = true;
            payNowBtn.textContent = 'Processing...';
            errorDisplay.style.display = 'none';
            
            try {
                // Get return URL for after payment (current page)
                const returnUrl = window.location.href;
                
                // Confirm payment with Stripe
                const result = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: returnUrl,
                    },
                    redirect: 'if_required'
                });
                
                if (result.error) {
                    // Show error to customer
                    errorDisplay.textContent = result.error.message;
                    errorDisplay.style.display = 'block';
                    
                    // Reset button state
                    payNowBtn.disabled = false;
                    payNowBtn.textContent = 'Pay Now';
                } else {
                    // Payment succeeded
                    payNowBtn.textContent = 'Payment Successful!';
                    
                    // If we're still on the page (no redirect happened), update the UI
                    if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
                        // Update order status in the database
                        await updateOrderAfterPayment(result.paymentIntent);
                        // showPaymentSuccess() will be called by updateOrderAfterPayment()
                    }
                }
            } catch (error) {
                console.error('Payment error:', error);
                
                // Show error to customer
                errorDisplay.textContent = 'An unexpected error occurred. Please try again.';
                errorDisplay.style.display = 'block';
                
                // Reset button state
                payNowBtn.disabled = false;
                payNowBtn.textContent = 'Pay Now';
            }
        }

        // Update order status after successful payment
        async function updateOrderAfterPayment(paymentIntent) {
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) {
                console.error('No order data found');
                return;
            }
            
            const orderInfo = JSON.parse(orderData);
            if (!orderInfo || !orderInfo.orderID) {
                console.error('Invalid order data');
                return;
            }

            // Prepare request
            const url = `${myApi.api_url}update-payment-status`;
            const options = {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    orderID: orderInfo.orderID,
                    status: 'paid',
                    paymentIntentId: paymentIntent.id,
                    auth_id: window.auth_id
                })
            };

            try {
                const response = await fetch(url, options);

                if (response.status === 403) {
                    console.error('You\'re not authorized.');
                    return;
                }

                if (!response.ok) {
                    let errMsg = response.statusText;
                    try {
                        const errJson = await response.json();
                        errMsg = errJson.error || errJson.message || errMsg;
                    } catch (e) {
                        // non-JSON body or parse failed; keep statusText
                    }
                    console.error(`Error (${response.status}): ${errMsg}`);
                    return;
                }

                const data = await response.json();

                if (data && data.loggedIn) window.auth_id = '1';

                if (data.success) {
                    // Update session storage
                    orderInfo.status = 'paid';
                    orderInfo.paymentIntentId = paymentIntent.id;
                    orderInfo.paidAt = new Date().toISOString();
                    sessionStorage.setItem('placedOrder', JSON.stringify(orderInfo));
                    
                    // If this was a campaign order with account creation, show account message
                    if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                        setTimeout(() => {
                            const accountMessage = document.createElement('div');
                            accountMessage.style.cssText = `
                                position: fixed;
                                top: 80px;
                                right: 20px;
                                background: #2196F3;
                                color: white;
                                padding: 15px 20px;
                                border-radius: 4px;
                                box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                                z-index: 10000;
                                max-width: 300px;
                            `;
                            accountMessage.innerHTML = `
                                <strong>Account Created!</strong><br>
                                Your account is now set up. You can access your full dashboard anytime by logging in with Google.
                            `;
                            document.body.appendChild(accountMessage);
                            
                            // Remove message after 8 seconds
                            setTimeout(() => {
                                accountMessage.remove();
                            }, 8000);
                        }, 2000);
                    }

                    // Show success UI
                    showPaymentSuccess();
                } else {
                    console.error('Failed to update order status:', data);
                }
            }
            catch (error) {
                console.error('Error updating order status:', error);
            }
        }
        
        function isOrderPaid() {
            const orderData = sessionStorage.getItem('placedOrder');
            if (!orderData) return false;
            
            try {
                const orderInfo = JSON.parse(orderData);
                return orderInfo && orderInfo.status === 'paid';
            } catch (e) {
                return false;
            }
        }

        function showOrderSuccess() {
            const payNowBtn = document.getElementById('pay-now');

            // Disable form interaction immediately
            disableFormInteraction();

            // Remove any existing payment form
            const existingPaymentContainer = document.getElementById('payment-element-container');
            if (existingPaymentContainer) {
                existingPaymentContainer.remove();
            }

            // Clear any Stripe elements
            if (paymentElement) {
                try {
                    paymentElement.unmount();
                    paymentElement = null;
                    elements = null;
                } catch (error) {
                    console.log('Payment element already unmounted');
                }
            }

            // Remove any existing success container to avoid duplicates
            const existingSuccessContainer = document.getElementById('payment-success-container');
            if (existingSuccessContainer) {
                existingSuccessContainer.remove();
            }

            // Create success message with new order button
            const successContainer = document.createElement('div');
            successContainer.id = 'payment-success-container';
            successContainer.style.marginBottom = '20px';

            // Success message for when payments are disabled
            let successMessage = `
                <div style="padding: 20px; background-color: #4CAF50; color: white; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                    <h3 style="margin: 0 0 10px 0;">Order Placed Successfully!</h3>
                    <p style="margin: 0;">Your order has been received. Thank you!</p>
                </div>
            `;

            let buttonText = 'Start a New Order';
            let buttonAction = () => {
                // Clear the session storage
                sessionStorage.removeItem('placedOrder');
                sessionStorage.removeItem('priceCalcSelections');

                // If this was a campaign order, redirect to login page so they can access full dashboard
                if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                    window.location.href = '/ffc-login'; // Use your custom login slug
                } else {
                    // Regular flow - reload page
                    window.location.reload();
                }
            };

            // If this was a campaign order with account creation
            if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                successMessage = `
                    <div style="padding: 20px; background-color: #4CAF50; color: white; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                        <h3 style="margin: 0 0 10px 0;">Order Placed Successfully!</h3>
                        <p style="margin: 0 0 10px 0;">Your order has been received and your account has been created!</p>
                        <p style="margin: 0; font-size: 14px;">You can now access your full dashboard anytime by logging in with Google.</p>
                    </div>
                `;
                buttonText = 'Go to Login Page';
            }

            successContainer.innerHTML = `
                ${successMessage}
                <button id="start-new-order" style="
                    display: block;
                    width: 100%;
                    padding: 12px 20px;
                    background-color: #0073aa;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                ">
                    ${buttonText}
                </button>
            `;

            // Insert before the pay now button
            if (payNowBtn && payNowBtn.parentNode) {
                payNowBtn.parentNode.insertBefore(successContainer, payNowBtn);
                payNowBtn.style.display = 'none';

                // Add event listener to new order button
                document.getElementById('start-new-order').addEventListener('click', buttonAction);
            }
        }

        function showPaymentSuccess() {
            // Close the centered payment-form modal if it's still open.
            // (Pay Now button is restored to its origin as part of close.)
            if (typeof window._closePaymentFormModal === 'function') {
                window._closePaymentFormModal();
            }

            const payNowBtn = document.getElementById('pay-now');

            // Disable form interaction immediately
            disableFormInteraction();

            // Remove any existing payment form
            const existingPaymentContainer = document.getElementById('payment-element-container');
            if (existingPaymentContainer) {
                existingPaymentContainer.remove();
            }

            // Clear any Stripe elements
            if (paymentElement) {
                try {
                    paymentElement.unmount();
                    paymentElement = null;
                    elements = null;
                } catch (error) {
                    console.log('Payment element already unmounted');
                }
            }

            // Remove any existing success container to avoid duplicates
            const existingSuccessContainer = document.getElementById('payment-success-container');
            if (existingSuccessContainer) {
                existingSuccessContainer.remove();
            }

            // Create success message with new order button
            const successContainer = document.createElement('div');
            successContainer.id = 'payment-success-container';
            successContainer.style.marginBottom = '20px';

            // Different messages for campaign vs regular orders
            let successMessage = `
                <div style="padding: 20px; background-color: #4CAF50; color: white; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                    <h3 style="margin: 0 0 10px 0;">Payment Successful!</h3>
                    <p style="margin: 0;">Your order has been paid. Thank you for your purchase!</p>
                </div>
            `;

            let buttonText = 'Start a New Order';
            let buttonAction = () => {
                // Clear the session storage
                sessionStorage.removeItem('placedOrder');
                sessionStorage.removeItem('priceCalcSelections');

                // If this was a campaign order, redirect to login page so they can access full dashboard
                if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                    window.location.href = '/ffc-login'; // Use your custom login slug
                } else {
                    // Regular flow - reload page
                    window.location.reload();
                }
            };

            // If this was a campaign order with account creation
            if (dashboardData.campaign_config && (googleAuthData || window.auth_id)) {
                successMessage = `
                    <div style="padding: 20px; background-color: #4CAF50; color: white; border-radius: 4px; text-align: center; margin-bottom: 15px;">
                        <h3 style="margin: 0 0 10px 0;">Payment Successful!</h3>
                        <p style="margin: 0 0 10px 0;">Your order has been paid and your account has been created!</p>
                        <p style="margin: 0; font-size: 14px;">You can now access your full dashboard anytime by logging in with Google.</p>
                    </div>
                `;
                buttonText = 'Go to Login Page';
            }

            successContainer.innerHTML = `
                ${successMessage}
                <button id="start-new-order" style="
                    display: block;
                    width: 100%;
                    padding: 12px 20px;
                    background-color: #0073aa;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                ">
                    ${buttonText}
                </button>
            `;

            // Insert before the pay now button
            if (payNowBtn && payNowBtn.parentNode) {
                payNowBtn.parentNode.insertBefore(successContainer, payNowBtn);
                payNowBtn.style.display = 'none';

                // Add event listener to new order button
                document.getElementById('start-new-order').addEventListener('click', buttonAction);
            }
        }

        // Check for URL parameters indicating payment status
        function checkPaymentStatus() {
            const urlParams = new URLSearchParams(window.location.search);
            const paymentIntent = urlParams.get('payment_intent');
            const paymentIntentClientSecret = urlParams.get('payment_intent_client_secret');
            const redirectStatus = urlParams.get('redirect_status');
            
            if (paymentIntent && paymentIntentClientSecret && redirectStatus) {
                // Handle the redirect back from Stripe
                if (redirectStatus === 'succeeded') {
                    // Update order session
                    const orderData = sessionStorage.getItem('placedOrder');
                    if (orderData) {
                        const orderInfo = JSON.parse(orderData);
                        orderInfo.status = 'paid';
                        orderInfo.paymentIntentId = paymentIntent;
                        orderInfo.paidAt = new Date().toISOString();
                        sessionStorage.setItem('placedOrder', JSON.stringify(orderInfo));
                    }
                    
                    // Retrieve the payment intent to update the order
                    stripe.retrievePaymentIntent(paymentIntentClientSecret)
                        .then(({paymentIntent}) => {
                            if (paymentIntent && paymentIntent.status === 'succeeded') {
                                updateOrderAfterPayment(paymentIntent);
                                // showPaymentSuccess() will be called by updateOrderAfterPayment()
                            }
                        })
                        .catch(error => {
                            console.error('Error retrieving payment intent:', error);
                            // Still show success since we know it succeeded from redirect
                            showPaymentSuccess();
                        });
                } else {
                    // Payment failed or was cancelled
                    const errorDisplay = document.getElementById('payment-error');
                    if (errorDisplay) {
                        errorDisplay.textContent = 'Payment failed or was cancelled. Please try again.';
                        errorDisplay.style.display = 'block';
                    }
                }
                
                // Clean up the URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        }
        
        // Subscription management functions
        let updatePaymentElements, updatePaymentElement;

        async function loadSubscriptions() {
            const container = document.getElementById('subscriptions-container');
            const loading = document.getElementById('subscriptions-loading');
            const noSubs = document.getElementById('no-subscriptions');
            const subsManagementEl = document.querySelector('#subscriptions-management');
            const manageSubsBtn = document.querySelector('#manage-subs-btn');
            
            loading.style.display = 'block';
            container.style.display = 'none';
            noSubs.style.display = 'none';
            manageSubsBtn.style.opacity = '0.1';
            manageSubsBtn.style.pointerEvents = 'none';
            
            try {
                const response = await fetch(`${myApi.api_url}get-subscriptions`, {
                    method: 'GET'
                });
                
                if (!response.ok) throw new Error('Failed to load subscriptions');
                
                const data = await response.json();
                smoothScrollToElement(subsManagementEl);

                if (data.success && data.subscriptions.length > 0) {
                    renderSubscriptions(data.subscriptions);
                    container.style.display = 'block';
                } else {
                    noSubs.style.display = 'block';
                }
            } catch (error) {
                console.error('Error loading subscriptions:', error);
                container.innerHTML = '<p style="color: red;">Error loading subscriptions. Please try again.</p>';
                container.style.display = 'block';
            } finally {
                loading.style.display = 'none';
            }
        }

        function renderSubscriptions(subscriptions) {
            const container = document.getElementById('subscriptions-container');
            container.innerHTML = '';
            
            subscriptions.forEach(sub => {
                const card = document.createElement('div');
                card.className = 'subscription-card';
                
                const nextBilling = new Date(sub.subscription_current_period_end);
                const formattedDate = nextBilling.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                });
                
                // Get feature and option IDs directly from the subscription data
                const featureId = parseInt(sub.featureId);
                const currentOptionId = parseInt(sub.optionId);
                
                const isPastDue = sub.subscription_status === 'past_due';

                // Check if this recurring feature has other options
                let availablePlans = [];
                
                if (featureId && dashboardData.features) {
                    const feature = dashboardData.features.find(f => parseInt(f.id) === featureId);
                    if (feature && feature.recurring && feature.options) {
                        // Filter out the current option
                        availablePlans = feature.options.filter(opt => isPastDue || parseInt(opt.id) !== currentOptionId);
                    }
                }
                
                card.innerHTML = `
                    <div class="subscription-header">
                        <div class="subscription-title">${sub.features}</div>
                        <div class="subscription-status ${sub.subscription_status}">${formatStatus(sub.subscription_status)}</div>
                    </div>
                    
                    <div class="subscription-details">
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Plan</div>
                            <div class="subscription-detail-value">${sub.options}</div>
                        </div>
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Billing</div>
                            <div class="subscription-detail-value">$${sub.total_amount} / ${sub.intervals}</div>
                        </div>
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Next Payment</div>
                            <div class="subscription-detail-value">${formattedDate}</div>
                        </div>
                        <div class="subscription-detail">
                            <div class="subscription-detail-label">Started</div>
                            <div class="subscription-detail-value">${new Date(sub.started_at).toLocaleDateString()}</div>
                        </div>
                    </div>
                    
                    ${(availablePlans.length > 0 || isPastDue) ? `
                        <div class="plan-change-section">
                            ${isPastDue ?
                                `<div class="plan-past-due">
                                    Your subscription is past due. Please renew to continue service.
                                </div>` : ''
                            }

                            ${availablePlans.length > 0 ? `
                                <label class="plan-change-label">
                                    ${isPastDue ? 'Renew with a different plan' : 'Change plan'}
                                </label>
                                <select class="plan-select form-input"
                                        data-subscription-id="${sub.subscription_id}"
                                        data-current-option="${currentOptionId}">
                                    <option value="">Select a plan…</option>
                                    ${availablePlans.map(plan => `
                                        <option value="${plan.id}">
                                            ${plan.optionName} — $${plan.staticPrice}/${plan.interval || 'month'}
                                        </option>
                                    `).join('')}
                                </select>
                            ` : ''}

                            <button class="btn btn-primary ${isPastDue ? 'renew-subscription' : 'change-plan'}"
                                    data-subscription-id="${sub.subscription_id}"
                                    ${availablePlans.length > 0 ? 'disabled' : ''}>
                                ${isPastDue ? 'Renew subscription' : 'Change plan'}
                            </button>
                        </div>
                    ` : ''}
                    
                    <div class="subscription-actions">
                        <button class="btn btn-primary update-payment-btn"
                                ${isPastDue > 0 ? 'disabled ' : ''}
                                data-subscription-id="${sub.subscription_id}">
                            Update payment method
                        </button>
                        ${sub.subscription_status === 'active' && !isPastDue ?
                            `<button class="btn btn-danger cancel-sub-btn" data-subscription-id="${sub.subscription_id}">
                                Cancel subscription
                            </button>`
                            : ''}
                    </div>
                `;
                
                container.appendChild(card);
            });
            
            // Add event listeners
            container.querySelectorAll('.cancel-sub-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    cancelSubscription(e.target.dataset.subscriptionId);
                });
            });
            
            container.querySelectorAll('.update-payment-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    updatePaymentMethod(e.target.dataset.subscriptionId);
                });
            });
            
            // Plan change dropdown listeners
            container.querySelectorAll('.plan-select').forEach(select => {
                select.addEventListener('change', (e) => {
                    const btn = e.target.parentElement.querySelector('.change-plan, .renew-subscription');
                    btn.disabled = !e.target.value;
                });
            });
            
            // Change plan / Renew button listeners
            container.querySelectorAll('.change-plan, .renew-subscription').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const subscriptionId = e.target.dataset.subscriptionId;
                    const planSelect = e.target.parentElement.querySelector('.plan-select');
                    const newOptionId = planSelect ? planSelect.value : null;
                    const isRenewal = e.target.classList.contains('renew-subscription');
                    
                    if (newOptionId) {
                        initiatePlanChange(subscriptionId, newOptionId, isRenewal);
                    }
                });
            });
        }

        function formatStatus(status) {
            const statusMap = {
                'active': 'Active',
                'past_due': 'Past Due',
                'cancelled': 'Cancelled',
                'trialing': 'Trial'
            };
            return statusMap[status] || status;
        }

        async function cancelSubscription(subscriptionId) {
            const modal = document.getElementById('cancel-subscription-modal');
            const content = document.getElementById('cancel-subscription-content');
            const loader = document.getElementById('cancel-modal-loader');
            const closeBtn = document.getElementById('close-cancel-modal');
            const confirmBtn = document.getElementById('confirm-cancel-subscription');
            const actionButtons = document.querySelector('.update-payment-actions');
            
            // Show modal
            modal.style.display = 'flex';
            content.style.display = 'block';
            loader.style.display = 'none';
            actionButtons.style.display = 'flex';
            
            // Store subscription ID for the confirm handler
            modal.dataset.subscriptionId = subscriptionId;
            
            // Close button handler
            const closeHandler = () => {
                modal.style.display = 'none';
                modal.dataset.subscriptionId = '';
            };
            
            // Remove any existing listeners
            closeBtn.removeEventListener('click', closeHandler);
            modal.removeEventListener('click', modalClickHandler);
            
            // Add close handlers
            closeBtn.addEventListener('click', closeHandler);
            
            // Click outside to close
            function modalClickHandler(e) {
                if (e.target === modal) {
                    closeHandler();
                }
            }
            modal.addEventListener('click', modalClickHandler);
            
            // Confirm button handler (one-time use)
            confirmBtn.onclick = async () => {
                // Hide content and show loader
                content.style.display = 'none';
                loader.style.display = 'block';
                
                try {
                    const response = await fetch(`${myApi.api_url}cancel-subscription`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            subscriptionId: modal.dataset.subscriptionId
                        })
                    });
                    
                    const data = await response.json();

                    if (data.success) {
                        // Close modal
                        modal.style.display = 'none';
                        
                        // Show success message
                        const successMessage = document.createElement('div');
                        successMessage.style.cssText = `
                            position: fixed;
                            top: 20px;
                            right: 20px;
                            background: #4CAF50;
                            color: white;
                            padding: 15px 20px;
                            border-radius: 4px;
                            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
                            z-index: 10000;
                        `;
                        successMessage.textContent = 'Successfully canceled';
                        document.body.appendChild(successMessage);
                        
                        // Remove success message after 3 seconds
                        setTimeout(() => {
                            successMessage.remove();
                        }, 3000);
                        
                        // Reload subscriptions
                        loadSubscriptions();
                    } else {
                        throw new Error(data.message || 'Failed to cancel subscription');
                    }
                } catch (error) {
                    // Show error in modal
                    loader.style.display = 'none';
                    content.innerHTML = `
                        <div style="color: #d83838; margin-bottom: 20px;">
                            <p><strong>Error:</strong> ${error.message}</p>
                        </div>
                        <div class="update-payment-actions">
                            <button class="button" id="error-close-cancel">Close</button>
                        </div>
                    `;
                    content.style.display = 'block';
                    
                    // Add close handler to error button
                    document.getElementById('error-close-cancel').addEventListener('click', closeHandler);
                }
            };
        }

        if (updateModal) {
            // Ensure payment element container exists
            if (!document.getElementById('update-payment-element')) {
                const modalContent = updateModal.querySelector('.update-payment-content');
                if (modalContent) {
                    const paymentElementDiv = document.createElement('div');
                    paymentElementDiv.id = 'update-payment-element';
                    
                    // Insert before error div if it exists
                    const errorDiv = document.getElementById('update-payment-error');
                    if (errorDiv) {
                        errorDiv.parentNode.insertBefore(paymentElementDiv, errorDiv);
                    } else {
                        modalContent.appendChild(paymentElementDiv);
                    }
                }
            }
            
            // Ensure submit button is disabled by default
            const submitBtn = document.getElementById('update-payment-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Update';
            }
        }

        // Helper to show action buttons after payment element loads
        function showModalActionButtons() {
            const actionButtons = document.querySelector('.update-payment-actions');
            if (actionButtons) {
                // Use requestAnimationFrame to ensure the browser has rendered the payment element
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        actionButtons.style.display = 'flex';
                    });
                });
            }
        }

        async function updatePaymentMethod() {
            const modal = document.getElementById('update-payment-modal');
            const modalLoader = document.querySelector('#update-modal-loader');
            const cancelBtn = document.querySelector('#cancel-update-payment');
            const submitBtn = document.getElementById('update-payment-submit');
            const actionButtons = document.querySelector('.update-payment-actions');
            
            // Setup modal
            modal.style.display = 'flex';
            modalLoader.style.display = 'block';
            submitBtn.disabled = true; // Keep disabled until loaded
            submitBtn.textContent = 'Update';
            
            // Hide action buttons until payment element loads
            if (actionButtons) {
                actionButtons.style.display = 'none';
            }

            // Add cancel handler (remove any existing first)
            cancelBtn.removeEventListener('pointerup', closeUpdatePaymentModal);
            cancelBtn.addEventListener('pointerup', closeUpdatePaymentModal);
            
            try {
                const response = await fetch(`${myApi.api_url}update-payment-method`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (data.success && stripe) {
                    // Create elements for updating payment method
                    updatePaymentElements = stripe.elements({
                        clientSecret: data.clientSecret,
                        appearance: fireflyStripeAppearance()
                    });
                    
                    updatePaymentElement = updatePaymentElements.create('payment');
                    updatePaymentElement.mount('#update-payment-element');
                    
                    // Show action buttons after payment element is rendered
                    showModalActionButtons();

                    // Enable submit button now that content is loaded
                    submitBtn.disabled = false;
                    
                    // Handle form submission
                    submitBtn.onclick = async () => {
                        const errorDiv = document.getElementById('update-payment-error');
                        
                        const usePI = data.intentType === 'payment_intent';
                        let result;

                        if (usePI) {
                        result = await stripe.confirmPayment({
                            elements: updatePaymentElements,
                            confirmParams: { return_url: window.location.href },
                            redirect: 'if_required'
                        });
                        } else {
                        result = await stripe.confirmSetup({
                            elements: updatePaymentElements,
                            confirmParams: { return_url: window.location.href },
                            redirect: 'if_required'
                        });
                        }

                        if (result.error) {
                            errorDiv.textContent = result.error.message;
                            errorDiv.style.display = 'block';
                            submitBtn.disabled = false;
                            submitBtn.textContent = 'Update';
                        } else {
                            alert('Payment method updated successfully!');
                            closeUpdatePaymentModal();
                            loadSubscriptions();
                        }
                    };
                }
            } catch (error) {
                console.error('Error setting up payment update:', error);
                alert('Error setting up payment update. Please try again.');
                closeUpdatePaymentModal();
            } finally {
                modalLoader.style.display = 'none';
            }
        }

        function closeUpdatePaymentModal() {
            const modal = document.getElementById('update-payment-modal');
            modal.style.display = 'none';
            
            // Hide action buttons for next time
            const actionButtons = document.querySelector('.update-payment-actions');
            if (actionButtons) {
                actionButtons.style.display = 'none';
            }
            
            // Reset modal title
            const modalTitle = modal.querySelector('h3');
            if (modalTitle) modalTitle.textContent = 'Update Payment Method';
            
            // Clear any plan change details
            const detailsDiv = modal.querySelector('.plan-change-details');
            if (detailsDiv) {
                detailsDiv.remove();
            }
            
            // Reset error display
            const errorDiv = document.getElementById('update-payment-error');
            if (errorDiv) {
                errorDiv.style.display = 'none';
                errorDiv.textContent = '';
            }
            
            // Unmount payment element if it exists
            if (updatePaymentElement) {
                updatePaymentElement.unmount();
                updatePaymentElement = null;
                updatePaymentElements = null;
            }
            
            // IMPORTANT: Recreate the payment element container
            let paymentElementContainer = document.getElementById('update-payment-element');
            if (!paymentElementContainer) {
                const modalContent = modal.querySelector('.update-payment-content');
                const newPaymentElement = document.createElement('div');
                newPaymentElement.id = 'update-payment-element';
                
                // Insert before the error div
                const errorElement = document.getElementById('update-payment-error');
                if (errorElement && errorElement.parentNode) {
                    errorElement.parentNode.insertBefore(newPaymentElement, errorElement);
                } else if (modalContent) {
                    modalContent.appendChild(newPaymentElement);
                }
            }
            
            // Reset submit button
            const submitBtn = document.getElementById('update-payment-submit');
            if (submitBtn) {
                submitBtn.textContent = 'Update';
                submitBtn.disabled = true; // Disabled by default
                submitBtn.onclick = null; // Clear any existing handlers
            }
        }

        // Plan change functions
        async function initiatePlanChange(subscriptionId, newOptionId, isRenewal = false) {
            const modal = document.getElementById('update-payment-modal');
            const modalLoader = document.querySelector('#update-modal-loader');
            const modalTitle = modal.querySelector('h3');
            const submitBtn = document.getElementById('update-payment-submit');
            const errorDiv = document.getElementById('update-payment-error');
            const cancelBtn = document.querySelector('#cancel-update-payment');
            const actionButtons = document.querySelector('.update-payment-actions');
            
            // Update modal for plan change context
            modalTitle.textContent = isRenewal ? 'Renew Subscription' : 'Change Subscription Plan';
            modal.style.display = 'flex';
            modalLoader.style.display = 'block';
            submitBtn.disabled = true; // Keep disabled until loaded
            submitBtn.textContent = isRenewal ? 'Renew Plan' : 'Change Plan';
            
            // Hide action buttons until payment element loads
            if (actionButtons) {
                actionButtons.style.display = 'none';
            }

            // Add cancel handler (remove any existing first)
            cancelBtn.removeEventListener('pointerup', closeUpdatePaymentModal);
            cancelBtn.addEventListener('pointerup', closeUpdatePaymentModal);

            try {
                const response = await fetch(`${myApi.api_url}change-subscription-plan`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        subscriptionId: subscriptionId,
                        newOptionId: parseInt(newOptionId),
                        isRenewal: isRenewal,
                        auth_id: window.auth_id
                    })
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.message || 'Failed to initialize plan change');
                }
                
                if (data.success && stripe) {
                    // Show plan change details
                    const detailsDiv = document.createElement('div');
                    detailsDiv.className = 'plan-change-details'; // Use class for easy identification
                    detailsDiv.style.marginBottom = '15px';
                    detailsDiv.innerHTML = `
                        <p><strong>Current Plan:</strong> ${data.currentPlan} ($${data.currentPrice.toFixed(2)})</p>
                        <p><strong>New Plan:</strong> ${data.newPlan} ($${data.newPrice.toFixed(2)})</p>
                        ${data.immediateCharge > 0 ? 
                            `<p style="font-size: 1.1em; color: #333;"><strong>Due Today: $${data.immediateCharge.toFixed(2)}</strong></p>` :
                            data.immediateCharge < 0 ?
                            `<p style="font-size: 1.1em; color: #28a745;"><strong>Credit Applied: $${Math.abs(data.immediateCharge).toFixed(2)}</strong></p>` :
                            '<p style="font-size: 0.9em; color: #666;">No additional charge today</p>'
                        }
                    `;
                    
                    // Insert details before the payment element
                    const paymentElement = document.getElementById('update-payment-element');
                    if (paymentElement && paymentElement.parentNode) {
                        paymentElement.parentNode.insertBefore(detailsDiv, paymentElement);
                    }
                    
                    // Create elements for plan change
                    updatePaymentElements = stripe.elements({
                        clientSecret: data.clientSecret,
                        appearance: fireflyStripeAppearance()
                    });
                    
                    updatePaymentElement = updatePaymentElements.create('payment');
                    updatePaymentElement.mount('#update-payment-element');
                    
                    // Show action buttons after payment element is rendered
                    showModalActionButtons();

                    // Enable submit button now that content is loaded
                    submitBtn.disabled = false;
                    
                    // Handle form submission
                    submitBtn.onclick = async () => {
                        submitBtn.disabled = true;
                        submitBtn.textContent = isRenewal ? 'Renewing...' : 'Changing Plan...';
                        errorDiv.style.display = 'none';

                        try {
                            // Confirm with the correct API based on server response
                            const confirmed = await confirmByIntentType(data, updatePaymentElements);

                            // Tell the server we’re done (support PI or SI)
                            await completePlanChange({
                                ...(confirmed.kind === 'payment_intent'
                                    ? { paymentIntentId: confirmed.id }
                                    : { setupIntentId: confirmed.id }),
                                invoiceId: data.invoiceId || null,
                                newOptionId: parseInt(newOptionId),
                                isRenewal: !!isRenewal,
                            });

                            // Success UI
                            closeUpdatePaymentModal();

                            const successMessage = document.createElement('div');
                            successMessage.style.cssText = `
                                position: fixed; top: 20px; right: 20px; background: #4CAF50; color: white;
                                padding: 15px 20px; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 10000;
                            `;
                            successMessage.textContent = isRenewal ? 'Subscription renewed successfully!' : 'Plan changed successfully!';
                            document.body.appendChild(successMessage);
                            setTimeout(() => successMessage.remove(), 3000);
                            setTimeout(() => loadSubscriptions(), 500);

                        } catch (err) {
                            errorDiv.textContent = err.message || 'Something went wrong.';
                            errorDiv.style.display = 'block';
                            submitBtn.disabled = false;
                            submitBtn.textContent = isRenewal ? 'Renew Plan' : 'Change Plan';
                        }
                    };
                } else {
                    throw new Error(data.message || 'Failed to initialize plan change');
                }
            } catch (error) {
                console.error('Error setting up plan change:', error);
                alert('Error: ' + error.message);
                closeUpdatePaymentModal();
            } finally {
                modalLoader.style.display = 'none';
            }
        }

        async function completePlanChange(payload) {
            // payload: { paymentIntentId? , setupIntentId?, newOptionId?, isRenewal? }
            const body = { ...payload, auth_id: window.auth_id };

            const response = await fetch(`${myApi.api_url}complete-plan-change`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });

            const data = await response.json();

            if (!response.ok || data.success !== true) {
                throw new Error(data.message || 'Failed to complete plan change');
            }

            return data;
        }

        // Handle subscription response
        function handleSubscriptionPayment(clientSecret, subscriptionId) {
            // Create payment form as before
            createPaymentForm(clientSecret);
            
            // Store subscription ID for later use
            const orderData = sessionStorage.getItem('placedOrder');
            if (orderData) {
                const orderInfo = JSON.parse(orderData);
                orderInfo.subscriptionId = subscriptionId;
                sessionStorage.setItem('placedOrder', JSON.stringify(orderInfo));
            }
        }
        
        // Add a button to show subscriptions section
        function addSubscriptionsTab() {
            const profileContainer = document.getElementById('profile-container');
            if (profileContainer) {
                const subscriptionsBtn = document.createElement('button');
                subscriptionsBtn.type = 'button';
                subscriptionsBtn.id = "manage-subs-btn";
                subscriptionsBtn.textContent = 'Manage Subscriptions';
                subscriptionsBtn.style.marginTop = '20px';
                subscriptionsBtn.onclick = () => {
                    document.getElementById('subscriptions-management').style.display = 'block';
                    loadSubscriptions();
                };
                profileContainer.appendChild(subscriptionsBtn);
            }
        }

        // Initialize on page load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', addSubscriptionsTab);
        } else {
            addSubscriptionsTab();
            if (isPWA) {
                const manageSubsLoader = document.querySelector('#subs-loader');
                manageSubsLoader.src = `${window.template_path}/images/loading.gif`;
            }
        }

        // Override the existing updateOrderButton function
        window.updateOrderButton = updateOrderButton;

        if (isOrderPaid()) {
            disableFormInteraction();
            showPaymentSuccess();
        }

    };

    // Reset function to allow re-initialization if needed
    window.resetDashboard = function() {
        window.dashboardInitialized = false;
    };
    
    // Still run on DOMContentLoaded in case the HTML is already there
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if the dashboard container exists
        if (document.getElementById('features-container')) {
            window.initializeDashboard();
        }
    });
})();
