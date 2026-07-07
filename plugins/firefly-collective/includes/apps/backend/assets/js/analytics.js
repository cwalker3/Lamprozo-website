/**
 * Firefly Analytics — dashboard app (Vue 3, no build step).
 *
 * Hand-rolled SVG chart components (sparkline, area, ranked bars, donut)
 * + the main command-center app. All data comes from the windowed
 * /analytics?type=… REST dispatcher; the app fans out parallel fetches
 * and re-loads whenever the range / compare / template / metric changes.
 */
(function () {
    'use strict';

    var cfg = window.fireflyAnalytics || {};
    var REST = cfg.restUrl || '';
    var NONCE = cfg.nonce || '';
    var ADMIN = cfg.adminUrl || '/wp-admin/';
    var CURRENCY = cfg.currency || '$';

    if (!window.Vue) { return; }
    var createApp = window.Vue.createApp;

    // ---- fetch helper ------------------------------------------------------
    function api(type, params) {
        var qs = new URLSearchParams(Object.assign({ type: type }, params || {}));
        return fetch(REST + '?' + qs.toString(), {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE, 'Accept': 'application/json' }
        }).then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; });
    }

    // POST to a sub-path of the analytics REST base (track-local, pull, reset).
    function postApi(path, body) {
        return fetch(REST + path, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(body || {})
        }).then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
    }

    // ---- formatters --------------------------------------------------------
    function nfmt(n) {
        n = Number(n) || 0;
        if (Math.abs(n) >= 1e6) return (n / 1e6).toFixed(n % 1e6 === 0 ? 0 : 1) + 'M';
        if (Math.abs(n) >= 1e3) return (n / 1e3).toFixed(n % 1e3 === 0 ? 0 : 1) + 'k';
        return String(Math.round(n));
    }
    function money(n) {
        n = Number(n) || 0;
        var s = Math.abs(n) >= 1e4 ? nfmt(n) : n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
        return CURRENCY + s;
    }
    function dur(s) {
        s = Math.round(Number(s) || 0);
        if (s < 60) return s + 's';
        var m = Math.floor(s / 60), r = s % 60;
        if (m < 60) return m + 'm ' + (r < 10 ? '0' : '') + r + 's';
        var h = Math.floor(m / 60); m = m % 60;
        return h + 'h ' + (m < 10 ? '0' : '') + m + 'm';
    }
    function flag(code) {
        if (!code || code.length !== 2 || code === '??') return '🌐';
        var A = 0x1F1E6, base = 'A'.charCodeAt(0);
        return String.fromCodePoint(A + code.charCodeAt(0) - base) + String.fromCodePoint(A + code.charCodeAt(1) - base);
    }

    // ---- SVG geometry ------------------------------------------------------
    // Smooth a series of [x,y] points into a path via a light Catmull-Rom→bezier.
    function smoothPath(pts) {
        if (!pts.length) return '';
        if (pts.length < 3) return 'M' + pts.map(function (p) { return p[0] + ',' + p[1]; }).join(' L');
        var d = 'M' + pts[0][0] + ',' + pts[0][1];
        for (var i = 0; i < pts.length - 1; i++) {
            var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
            var c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = p1[1] + (p2[1] - p0[1]) / 6;
            var c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = p2[1] - (p3[1] - p1[1]) / 6;
            d += ' C' + c1x + ',' + c1y + ' ' + c2x + ',' + c2y + ' ' + p2[0] + ',' + p2[1];
        }
        return d;
    }

    // ========================================================================
    //  Component: sparkline
    // ========================================================================
    var SparkLine = {
        props: { data: Array, color: { type: String, default: '#d99b2c' } },
        computed: {
            geo: function () {
                var vals = (this.data || []).map(function (v) { return Number(v) || 0; });
                if (!vals.length) return { line: '', dot: null };
                var W = 74, H = 26, p = 2;
                var max = Math.max.apply(null, vals), min = Math.min.apply(null, vals);
                var span = (max - min) || 1;
                var step = vals.length > 1 ? (W - p * 2) / (vals.length - 1) : 0;
                var pts = vals.map(function (v, i) {
                    return [p + i * step, H - p - ((v - min) / span) * (H - p * 2)];
                });
                return { line: smoothPath(pts), dot: pts[pts.length - 1] };
            }
        },
        template:
            '<svg class="ffa-kpi-spark" viewBox="0 0 74 26" preserveAspectRatio="none">' +
            '<path :d="geo.line" fill="none" :stroke="color" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"/>' +
            '<circle v-if="geo.dot" :cx="geo.dot[0]" :cy="geo.dot[1]" r="2" :fill="color"/>' +
            '</svg>'
    };

    // ========================================================================
    //  Component: ranked bar list
    // ========================================================================
    var RankedBars = {
        props: {
            rows: Array, labelKey: { default: 'label' }, valKey: { default: 'views' },
            val2Key: { default: 'visitors' }, loading: Boolean, isFlag: Boolean,
            money: Boolean, fmtVal: Function
        },
        computed: {
            max: function () {
                var k = this.valKey, m = 0;
                (this.rows || []).forEach(function (r) { m = Math.max(m, Number(r[k]) || 0); });
                return m || 1;
            }
        },
        methods: {
            fv: function (v) { return this.fmtVal ? this.fmtVal(v) : (this.money ? money(v) : nfmt(v)); },
            flag: flag,
            pctw: function (v) { return Math.max(2, Math.round(((Number(v) || 0) / this.max) * 100)); }
        },
        template:
            '<div>' +
            '<div v-if="loading" class="ffa-loading-rows"><div class="ffa-skel" v-for="i in 6" :key="i"></div></div>' +
            '<div v-else-if="!rows || !rows.length" class="ffa-empty">No data in this range</div>' +
            '<div v-else class="ffa-ranked">' +
            '<div v-for="(r,i) in rows" :key="i" class="ffa-row">' +
            '<span class="ffa-row-bar" :style="{width: pctw(r[valKey]) + \'%\'}"></span>' +
            '<span class="ffa-row-label"><span v-if="isFlag" class="ffa-flag">{{ flag(r.code) }}</span>{{ r[labelKey] || \'(none)\' }}</span>' +
            '<span class="ffa-row-val">{{ fv(r[valKey]) }}</span>' +
            '<span v-if="val2Key && r[val2Key] !== undefined" class="ffa-row-val2">{{ fmtVal ? fv(r[val2Key]) : nf(r[val2Key]) }}</span>' +
            '</div>' +
            '</div>' +
            '</div>',
        setup: function () { return { nf: nfmt }; }
    };

    // ========================================================================
    //  Component: donut + legend
    // ========================================================================
    var PALETTE = ['#d99b2c', '#15a07a', '#5b6cff', '#e5774d', '#8b5cf6', '#0ea5e9', '#94a3b8', '#ec4899'];
    var Donut = {
        props: { slices: Array, total: Number, fmt: Function },
        computed: {
            arcs: function () {
                var R = 56, C = 2 * Math.PI * R, total = this.total || 0, off = 0, out = [];
                var items = this.slices || [];
                for (var i = 0; i < items.length; i++) {
                    var v = Number(items[i].value) || 0;
                    var len = total > 0 ? (v / total) * C : 0;
                    out.push({ color: PALETTE[i % PALETTE.length], dash: len + ' ' + (C - len), offset: -off, label: items[i].label, value: v });
                    off += len;
                }
                return out;
            },
            circ: function () { return 2 * Math.PI * 56; }
        },
        methods: { fv: function (v) { return this.fmt ? this.fmt(v) : nfmt(v); } },
        template:
            '<div class="ffa-donut-wrap">' +
            '<svg class="ffa-donut" viewBox="0 0 132 132">' +
            '<g transform="translate(66,66) rotate(-90)">' +
            '<circle r="56" fill="none" stroke="#eef0f4" stroke-width="16"/>' +
            '<circle v-for="(a,i) in arcs" :key="i" r="56" fill="none" :stroke="a.color" stroke-width="16" ' +
            ':stroke-dasharray="a.dash" :stroke-dashoffset="a.offset" stroke-linecap="butt"/>' +
            '</g></svg>' +
            '<div class="ffa-legend">' +
            '<div v-for="(a,i) in arcs" :key="i" class="ffa-legend-item">' +
            '<span class="ffa-legend-swatch" :style="{background:a.color}"></span>' +
            '<span class="ffa-legend-label">{{ a.label }}</span>' +
            '<span class="ffa-legend-val">{{ fv(a.value) }}</span>' +
            '</div></div></div>'
    };

    // ========================================================================
    //  Component: hero area chart (with hover tooltip + compare ghost)
    // ========================================================================
    var AreaChart = {
        props: { series: Array, previous: Array, granularity: String, label: String, money: Boolean },
        data: function () { return { W: 820, H: 300, hover: -1, tipX: 0, tipY: 0, _ro: null }; },
        computed: {
            pad: function () { return { l: 8, r: 8, t: 16, b: 22 }; },
            vals: function () { return (this.series || []).map(function (d) { return Number(d.value) || 0; }); },
            prevVals: function () { return (this.previous || []).map(function (d) { return Number(d.value) || 0; }); },
            max: function () {
                var a = this.vals.concat(this.prevVals);
                return Math.max(1, Math.max.apply(null, a.length ? a : [0]));
            },
            pts: function () { return this._project(this.vals); },
            prevPts: function () { return this.prevVals.length ? this._project(this.prevVals) : []; },
            linePath: function () { return smoothPath(this.pts); },
            areaPath: function () {
                if (!this.pts.length) return '';
                var base = this.H - this.pad.b;
                return smoothPath(this.pts) + ' L' + this.pts[this.pts.length - 1][0] + ',' + base + ' L' + this.pts[0][0] + ',' + base + ' Z';
            },
            ghostPath: function () { return this.prevPts.length ? smoothPath(this.prevPts) : ''; },
            gridY: function () {
                var out = [], n = 3, base = this.H - this.pad.b, top = this.pad.t;
                for (var i = 0; i <= n; i++) {
                    var y = top + (base - top) * (i / n);
                    out.push({ y: y, v: Math.round(this.max * (1 - i / n)) });
                }
                return out;
            },
            xLabels: function () {
                var s = this.series || [];
                if (!s.length) return [];
                var want = Math.min(6, s.length), out = [], step = (s.length - 1) / (want - 1 || 1);
                for (var i = 0; i < want; i++) {
                    var idx = Math.round(i * step);
                    out.push({ x: this.pts[idx] ? this.pts[idx][0] : 0, t: this._fmtX(s[idx].t) });
                }
                return out;
            },
            hoverData: function () {
                if (this.hover < 0 || !this.series[this.hover]) return null;
                var d = this.series[this.hover], p = this.pts[this.hover];
                return { x: p[0], y: p[1], t: this._fmtXFull(d.t), v: d.value, prev: this.previous && this.previous[this.hover] ? this.previous[this.hover].value : null };
            }
        },
        methods: {
            _project: function (vals) {
                var W = this.W, H = this.H, pl = this.pad.l, pr = this.pad.r, pt = this.pad.t, pb = this.pad.b;
                var max = this.max, n = vals.length;
                var step = n > 1 ? (W - pl - pr) / (n - 1) : 0;
                return vals.map(function (v, i) {
                    return [pl + i * step, (H - pb) - (v / max) * (H - pt - pb)];
                });
            },
            _fmtX: function (t) {
                if (this.granularity === 'hour') return (t || '').slice(11, 16);
                if (this.granularity === 'month') return (t || '') + '';
                var p = (t || '').split('-'); return p.length === 3 ? (p[1] + '/' + p[2]) : t;
            },
            _fmtXFull: function (t) {
                if (this.granularity === 'hour') return (t || '').replace('T', ' ');
                return t;
            },
            fmtV: function (v) { return this.money ? money(v) : nfmt(v); },
            onMove: function (e) {
                if (!this.pts.length) return;
                var rect = this.$refs.svg.getBoundingClientRect();
                var rel = (e.clientX - rect.left) / rect.width * this.W;
                var pl = this.pad.l, pr = this.pad.r, n = this.pts.length;
                var step = n > 1 ? (this.W - pl - pr) / (n - 1) : 1;
                var idx = Math.round((rel - pl) / step);
                idx = Math.max(0, Math.min(n - 1, idx));
                this.hover = idx;
                this.tipX = (this.pts[idx][0] / this.W) * rect.width;
                this.tipY = (this.pts[idx][1] / this.H) * rect.height;
            },
            onLeave: function () { this.hover = -1; },
            measure: function () {
                if (!this.$refs.svg) return;
                var r = this.$refs.svg.getBoundingClientRect();
                var w = Math.round(r.width), h = Math.round(r.height);
                // viewBox tracks the rendered px box exactly so the 1:1 mapping
                // holds at every breakpoint — no text/dot distortion.
                if (w > 0 && Math.abs(w - this.W) > 1) { this.W = w; }
                if (h > 0 && Math.abs(h - this.H) > 1) { this.H = h; }
            }
        },
        mounted: function () {
            var self = this;
            this.measure();
            if (window.ResizeObserver) {
                this._ro = new ResizeObserver(function () { self.measure(); });
                this._ro.observe(this.$refs.svg);
            } else {
                this._onResize = function () { self.measure(); };
                window.addEventListener('resize', this._onResize);
            }
        },
        beforeUnmount: function () {
            if (this._ro) { this._ro.disconnect(); }
            else if (this._onResize) { window.removeEventListener('resize', this._onResize); }
        },
        template:
            '<div class="ffa-chart-wrap">' +
            '<svg class="ffa-chart-svg" ref="svg" :viewBox="\'0 0 \'+W+\' \'+H" preserveAspectRatio="xMidYMid meet" ' +
            'role="img" :aria-label="(label||\'value\')+\' over time chart\'" ' +
            '@mousemove="onMove" @mouseleave="onLeave" @touchmove.prevent="onMove($event.touches[0])" @touchend="onLeave">' +
            '<defs><linearGradient id="ffaGrad" x1="0" y1="0" x2="0" y2="1">' +
            '<stop offset="0%" stop-color="#f5b544" stop-opacity="0.32"/>' +
            '<stop offset="100%" stop-color="#f5b544" stop-opacity="0"/>' +
            '</linearGradient></defs>' +
            '<line v-for="(g,i) in gridY" :key="\'g\'+i" class="ffa-grid-line" :x1="0" :x2="W" :y1="g.y" :y2="g.y"/>' +
            '<text v-for="(g,i) in gridY" :key="\'gt\'+i" class="ffa-axis-label" :x="2" :y="g.y-3">{{ fmtV(g.v) }}</text>' +
            '<path v-if="ghostPath" class="ffa-ghost-line" :d="ghostPath"/>' +
            '<path v-if="areaPath" :d="areaPath" fill="url(#ffaGrad)"/>' +
            '<path v-if="linePath" class="ffa-area-line" :d="linePath"/>' +
            '<text v-for="(x,i) in xLabels" :key="\'x\'+i" class="ffa-axis-label" :x="x.x" :y="H-6" text-anchor="middle">{{ x.t }}</text>' +
            '<template v-if="hoverData">' +
            '<line class="ffa-cursor-line" :x1="hoverData.x" :x2="hoverData.x" :y1="pad.t" :y2="H-pad.b"/>' +
            '<circle class="ffa-cursor-dot" :cx="hoverData.x" :cy="hoverData.y" r="4"/>' +
            '</template>' +
            '</svg>' +
            '<div v-if="hoverData" class="ffa-tooltip" :style="{left: tipX+\'px\', top: tipY+\'px\'}">' +
            '<div class="ffa-tt-date">{{ hoverData.t }}</div>' +
            '<div><b>{{ fmtV(hoverData.v) }}</b> {{ label }}</div>' +
            '<div v-if="hoverData.prev!==null" style="opacity:.7">prev: {{ fmtV(hoverData.prev) }}</div>' +
            '</div>' +
            '</div>'
    };

    // ========================================================================
    //  Main app
    // ========================================================================
    var RANGES = [
        { id: 'today', label: 'Today' }, { id: '24h', label: '24h' }, { id: '7d', label: '7d' },
        { id: '30d', label: '30d' }, { id: '90d', label: '90d' }, { id: '12mo', label: '12mo' }
    ];
    var METRICS = [
        { id: 'visitors', label: 'Visitors' }, { id: 'pageviews', label: 'Pageviews' }, { id: 'visits', label: 'Visits' }
    ];

    var app = createApp({
        components: { SparkLine: SparkLine, RankedBars: RankedBars, Donut: Donut, AreaChart: AreaChart },
        data: function () {
            return {
                cfg: cfg,
                ranges: RANGES, metrics: METRICS,
                range: '30d', compare: true, metric: 'visitors',
                template: cfg.activeTemplate || 'default',
                templates: cfg.templates || [],
                booting: true,
                loading: { kpis: true, ts: true, sources: true, pages: true, devices: true, countries: true, engagement: true, posts: true },
                kpis: null, ts: { series: [], previous: null, granularity: 'day' },
                sourcesDim: 'channels', sources: [],
                pagesDim: 'top', pages: [],
                posts: [],
                devicesDim: 'device', devices: [],
                countries: [],
                engagement: { scroll: [], dwell: [], ctas: [] },
                admin: { login_views: 0, login_unique: 0, logins: [] },
                realtime: { online: 0, feed: [], spark: [] },
                rtTimer: null,
                toast: null,
                // Manage menu / data tools
                showSettings: false,
                trackLocal: !!cfg.trackLocal,
                pullSource: 'dev',
                modal: null,
                busy: false,
                // Revenue / conversions
                revLoading: true,
                revenue: {
                    kpis: null,
                    ts: { series: [], granularity: 'day' },
                    products: [], campaigns: [],
                    bookings: { total: 0, confirmed: 0, by_type: [] },
                    submissions: { total: 0, by_form: [] },
                    referrals: { total: 0, by_status: [], available: false }
                }
            };
        },
        computed: {
            kpiTiles: function () {
                var k = this.kpis;
                return [
                    { id: 'visitors', label: 'Unique visitors', metric: 'visitors', fmt: nfmt, data: k && k.visitors },
                    { id: 'pageviews', label: 'Pageviews', metric: 'pageviews', fmt: nfmt, data: k && k.pageviews },
                    { id: 'vpv', label: 'Views / visit', metric: 'visits', fmt: function (v) { return (Number(v) || 0).toFixed(2); }, data: k && k.views_per_visit, invert: false },
                    { id: 'bounce', label: 'Bounce rate', metric: 'visits', fmt: function (v) { return (Number(v) || 0) + '%'; }, data: k && k.bounce_rate, invert: true },
                    { id: 'dur', label: 'Avg visit time', metric: 'visitors', fmt: dur, data: k && k.avg_duration, invert: false }
                ];
            },
            sparkSeries: function () { return (this.ts.series || []).map(function (d) { return d.value; }); },
            revSparkSeries: function () { return (this.revenue.ts.series || []).map(function (d) { return d.value; }); },
            revTiles: function () {
                var k = this.revenue.kpis;
                return [
                    { id: 'revenue', label: 'Revenue', fmt: money, data: k && k.revenue },
                    { id: 'orders', label: 'Orders', fmt: nfmt, data: k && k.orders },
                    { id: 'conversion', label: 'Conversion rate', fmt: function (v) { return (Number(v) || 0) + '%'; }, data: k && k.conversion },
                    { id: 'aov', label: 'Avg order value', fmt: money, data: k && k.aov }
                ];
            }
        },
        methods: {
            nfmt: nfmt, dur: dur, money: money, flag: flag,
            // delta rendering
            deltaClass: function (tile) {
                if (!tile.data || tile.data.delta === null || tile.data.delta === undefined) return 'flat';
                var d = tile.data.delta; if (d === 0) return 'flat';
                var good = tile.invert ? d < 0 : d > 0;
                return good ? 'up' : 'down';
            },
            deltaText: function (tile) {
                if (!tile.data || tile.data.delta === null || tile.data.delta === undefined) return '—';
                var d = tile.data.delta;
                return (d > 0 ? '▲ ' : d < 0 ? '▼ ' : '') + Math.abs(d) + '%';
            },
            tileValue: function (tile) {
                if (!tile.data) return '—';
                return tile.fmt(tile.data.value);
            },
            // ---- loaders ----
            params: function (extra) {
                return Object.assign({ template: this.template, range: this.range, compare: this.compare ? 1 : 0 }, extra || {});
            },
            loadAll: function () {
                this.loadKpis(); this.loadTs(); this.loadSources(); this.loadPages();
                this.loadDevices(); this.loadCountries(); this.loadEngagement(); this.loadPosts();
                this.loadAdmin(); this.loadRevenue();
            },
            loadAdmin: function () {
                var self = this;
                // Legacy endpoint takes ?days; map the active range to a day count.
                var days = { 'today': 1, '24h': 1, '7d': 7, '30d': 30, '90d': 90, '12mo': 365 }[this.range] || 30;
                return api('admin', { template: this.template, days: days }).then(function (d) { if (d) self.admin = d; });
            },
            loadRevenue: function () {
                var self = this, p = this.params(), rev = this.revenue;
                this.revLoading = true;
                Promise.all([
                    api('revenue_kpis', p).then(function (d) { if (d) rev.kpis = d; }),
                    api('revenue_timeseries', p).then(function (d) { if (d) rev.ts = { series: d.series || [], granularity: d.granularity || 'day' }; }),
                    api('revenue_products', p).then(function (d) { rev.products = d || []; }),
                    api('revenue_campaigns', p).then(function (d) { rev.campaigns = d || []; }),
                    api('revenue_bookings', p).then(function (d) { if (d) rev.bookings = d; }),
                    api('revenue_submissions', p).then(function (d) { if (d) rev.submissions = d; }),
                    api('revenue_referrals', p).then(function (d) { if (d) rev.referrals = d; })
                ]).then(function () { self.revLoading = false; });
            },
            loadKpis: function () {
                var self = this; this.loading.kpis = true;
                return api('kpis', this.params()).then(function (d) { if (d) self.kpis = d; self.loading.kpis = false; });
            },
            loadTs: function () {
                var self = this; this.loading.ts = true;
                return api('timeseries', this.params({ metric: this.metric })).then(function (d) {
                    if (d) self.ts = { series: d.series || [], previous: d.previous || null, granularity: d.granularity || 'day' };
                    self.loading.ts = false;
                });
            },
            loadSources: function () {
                var self = this; this.loading.sources = true;
                return api('sources', this.params({ dim: this.sourcesDim })).then(function (d) { self.sources = d || []; self.loading.sources = false; });
            },
            loadPages: function () {
                var self = this; this.loading.pages = true;
                return api('pages', this.params({ dim: this.pagesDim })).then(function (d) { self.pages = d || []; self.loading.pages = false; });
            },
            loadPosts: function () {
                var self = this; this.loading.posts = true;
                return api('posts', this.params()).then(function (d) { self.posts = d || []; self.loading.posts = false; });
            },
            loadDevices: function () {
                var self = this; this.loading.devices = true;
                return api('devices', this.params({ dim: this.devicesDim })).then(function (d) { self.devices = d || []; self.loading.devices = false; });
            },
            loadCountries: function () {
                var self = this; this.loading.countries = true;
                return api('countries', this.params()).then(function (d) { self.countries = d || []; self.loading.countries = false; });
            },
            loadEngagement: function () {
                var self = this; this.loading.engagement = true;
                return api('engagement', this.params()).then(function (d) { if (d) self.engagement = d; self.loading.engagement = false; });
            },
            loadRealtime: function () {
                var self = this;
                return api('realtime', { template: this.template }).then(function (d) { if (d) self.realtime = d; });
            },
            // ---- setters that trigger reloads ----
            setRange: function (r) { if (this.range === r) return; this.range = r; this.loadAll(); },
            setMetric: function (m) { if (this.metric === m) return; this.metric = m; this.loadTs(); },
            toggleCompare: function () { this.compare = !this.compare; this.loadKpis(); this.loadTs(); },
            setTemplate: function (e) { this.template = e.target.value; this.loadAll(); this.loadRealtime(); },
            setSourcesDim: function (d) { this.sourcesDim = d; this.loadSources(); },
            setPagesDim: function (d) { this.pagesDim = d; this.loadPages(); },
            setDevicesDim: function (d) { this.devicesDim = d; this.loadDevices(); },
            // device donut data
            deviceSlices: function () {
                return (this.devices || []).slice(0, 8).map(function (r) { return { label: r.label, value: r.views }; });
            },
            deviceTotal: function () {
                return (this.devices || []).reduce(function (s, r) { return s + (Number(r.views) || 0); }, 0);
            },
            scrollSlices: function () {
                var order = ['100%', '75-99%', '50-75%', '25-50%', '0-25%', 'No data'];
                var map = {}; (this.engagement.scroll || []).forEach(function (r) { map[r.label] = r.views; });
                return order.filter(function (k) { return map[k]; }).map(function (k) { return { label: k, value: map[k] }; });
            },
            scrollTotal: function () {
                return (this.engagement.scroll || []).reduce(function (s, r) { return s + (Number(r.views) || 0); }, 0);
            },
            metricLabel: function () {
                var m = this.metrics.filter(function (x) { return x.id === this.metric; }, this); return m.length ? m[0].label.toLowerCase() : this.metric;
            },
            editLink: function (postId) { return ADMIN + 'post.php?post=' + postId + '&action=edit'; },
            relTime: function (ts) {
                if (!ts) return '';
                var t = new Date((ts + '').replace(' ', 'T') + 'Z').getTime();
                var diff = Math.max(0, (Date.now() - t) / 1000);
                if (diff < 60) return Math.floor(diff) + 's ago';
                if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
                if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
                return Math.floor(diff / 86400) + 'd ago';
            },
            notify: function (msg, isErr) {
                var self = this; this.toast = { msg: msg, err: !!isErr };
                setTimeout(function () { self.toast = null; }, 3200);
            },
            // ---- data tools (Manage menu) ----
            toggleTrackLocal: function () {
                var self = this, next = !this.trackLocal;
                this.trackLocal = next; // optimistic
                postApi('/track-local', { enabled: next }).then(function (r) {
                    if (r.ok && r.data && r.data.success) {
                        self.notify('Local tracking ' + (next ? 'enabled' : 'disabled'));
                    } else {
                        self.trackLocal = !next; self.notify('Could not update setting', true);
                    }
                }).catch(function () { self.trackLocal = !next; self.notify('Could not update setting', true); });
            },
            confirmPull: function () {
                var self = this;
                this.busy = true;
                var url = this.pullSource === 'prod' ? cfg.prodUrl : cfg.liveDevUrl;
                postApi('/pull', { source: url }).then(function (r) {
                    self.busy = false; self.modal = null;
                    if (r.ok && r.data && r.data.success) {
                        self.notify(r.data.message || 'Pulled from remote');
                        self.loadAll(); self.loadRealtime();
                    } else {
                        self.notify((r.data && r.data.message) || 'Pull failed', true);
                    }
                }).catch(function () { self.busy = false; self.modal = null; self.notify('Pull failed', true); });
            },
            confirmReset: function () {
                var self = this;
                this.busy = true;
                postApi('/reset', {}).then(function (r) {
                    self.busy = false; self.modal = null;
                    if (r.ok && r.data && r.data.success) {
                        self.notify('Analytics data reset');
                        self.loadAll(); self.loadRealtime();
                    } else {
                        self.notify((r.data && r.data.message) || 'Reset failed', true);
                    }
                }).catch(function () { self.busy = false; self.modal = null; self.notify('Reset failed', true); });
            }
        },
        mounted: function () {
            var self = this;
            this.loadAll();
            this.loadRealtime().then(function () { self.booting = false; });
            this.rtTimer = setInterval(function () { self.loadRealtime(); }, 15000);
            setTimeout(function () { self.booting = false; }, 1500);
        },
        beforeUnmount: function () { if (this.rtTimer) clearInterval(this.rtTimer); }
    });

    var el = document.getElementById('ffa-app');
    if (el) { app.mount('#ffa-app'); }
})();
