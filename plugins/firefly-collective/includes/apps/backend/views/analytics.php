<?php
/**
 * Analytics Dashboard View — command center shell.
 *
 * Server-rendered wrapper: enqueues Vue 3 (CDN) + our bespoke SVG-charting
 * app, localizes config (REST url + nonce + template list + currency), and
 * holds the in-DOM Vue template. All logic + charts live in analytics.js;
 * all styling in analytics.css (scoped under .ffa).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_enqueue_script( 'vue-js', 'https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js', array(), '3', true );

$js_file  = plugin_dir_path( __FILE__ ) . '../assets/js/analytics.js';
$css_file = plugin_dir_path( __FILE__ ) . '../assets/css/analytics.css';

wp_enqueue_script(
    'firefly-analytics',
    plugin_dir_url( __FILE__ ) . '../assets/js/analytics.js',
    array( 'vue-js' ),
    file_exists( $js_file ) ? filemtime( $js_file ) : '2.0.0',
    true
);
wp_enqueue_style(
    'firefly-analytics',
    plugin_dir_url( __FILE__ ) . '../assets/css/analytics.css',
    array(),
    file_exists( $css_file ) ? filemtime( $css_file ) : '2.0.0'
);

// Template list for the site selector.
$active_template = function_exists( 'firefly_collective_get_active_template' )
    ? firefly_collective_get_active_template() : 'default';
$template_ids = function_exists( 'firefly_get_valid_templates' )
    ? firefly_get_valid_templates() : array( $active_template );
$templates = array();
foreach ( (array) $template_ids as $t ) {
    $templates[] = array( 'id' => $t, 'label' => ucwords( str_replace( array( '-', '_' ), ' ', $t ) ) );
}

wp_localize_script( 'firefly-analytics', 'fireflyAnalytics', array(
    'restUrl'         => rest_url( 'firefly-collective/v1/analytics' ),
    'nonce'           => wp_create_nonce( 'wp_rest' ),
    'adminUrl'        => admin_url(),
    'activeTemplate'  => $active_template,
    'templates'       => $templates,
    'currency'        => '$',
    'isDev'           => defined( 'FIREFLY_DEV' ) && FIREFLY_DEV,
    'trackLocal'      => (bool) get_option( 'firefly_analytics_track_local', false ),
    'liveDevUrl'      => defined( 'LIVE_DEV_URL' ) ? LIVE_DEV_URL : 'https://dev.fireflycreative.io',
    'prodUrl'         => defined( 'PROD_URL' ) ? PROD_URL : 'https://fireflycreative.io',
) );
?>

<div id="ffa-app" class="ffa" v-cloak>

    <!-- Boot loader -->
    <div v-if="booting" class="ffa-boot"><span class="ffa-spinner"></span> Loading analytics…</div>

    <template v-else>

        <!-- ===== Top bar ===== -->
        <div class="ffa-topbar ffa-fade">
            <div class="ffa-title">
                <h1>Analytics</h1>
                <span class="ffa-sub" v-if="templates.length <= 1">{{ template }}</span>
            </div>

            <label v-if="templates.length > 1" class="ffa-control">
                <span class="dashicons dashicons-screenoptions" style="font-size:15px;width:15px;height:15px"></span>
                <select :value="template" @change="setTemplate">
                    <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.label }}</option>
                </select>
            </label>

            <div class="ffa-seg">
                <button v-for="r in ranges" :key="r.id" :class="{'is-active': range===r.id}" @click="setRange(r.id)">{{ r.label }}</button>
            </div>

            <button class="ffa-control" :class="{'is-on': compare}" @click="toggleCompare">
                <span class="dashicons dashicons-image-rotate" style="font-size:14px;width:14px;height:14px"></span>
                Compare
            </button>

            <span class="ffa-live"><span class="ffa-dot"></span>{{ realtime.online }} online</span>

            <!-- Manage menu: track-local toggle, pull-from-remote, reset -->
            <div class="ffa-menu-wrap">
                <button class="ffa-control" :class="{'is-on': showSettings}" @click="showSettings = !showSettings" aria-label="Manage analytics data">
                    <span class="dashicons dashicons-admin-generic" style="font-size:15px;width:15px;height:15px"></span>
                    Manage
                </button>
                <div v-if="showSettings" class="ffa-menu-backdrop" @click="showSettings = false"></div>
                <div v-if="showSettings" class="ffa-menu">
                    <template v-if="cfg.isDev">
                        <h4>Local environment</h4>
                        <div class="ffa-menu-row">
                            <span>Track data on local</span>
                            <button class="ffa-switch" :class="{'is-on': trackLocal}" @click="toggleTrackLocal" role="switch" :aria-checked="trackLocal"><span class="ffa-switch-knob"></span></button>
                        </div>
                        <div class="ffa-menu-sep"></div>
                        <h4>Pull from remote</h4>
                        <div class="ffa-menu-row">
                            <div class="ffa-seg ffa-seg-sm">
                                <button :class="{'is-active': pullSource==='dev'}" @click="pullSource='dev'">Live Dev</button>
                                <button :class="{'is-active': pullSource==='prod'}" @click="pullSource='prod'">Production</button>
                            </div>
                            <button class="ffa-btn ffa-btn-primary" style="padding:6px 12px" @click="modal='pull'; showSettings=false">Pull</button>
                        </div>
                        <div class="ffa-menu-sep"></div>
                    </template>
                    <button class="ffa-menu-danger" @click="modal='reset'; showSettings=false">
                        <span class="dashicons dashicons-trash" style="font-size:15px;width:15px;height:15px;vertical-align:text-bottom"></span>
                        Reset all analytics data
                    </button>
                </div>
            </div>
        </div>

        <!-- ===== KPI tiles ===== -->
        <div class="ffa-kpis ffa-fade">
            <div v-for="tile in kpiTiles" :key="tile.id" class="ffa-kpi" :class="{'is-active': metric===tile.metric}" @click="setMetric(tile.metric)">
                <div class="ffa-kpi-label">{{ tile.label }}</div>
                <div class="ffa-kpi-value">{{ tileValue(tile) }}</div>
                <div class="ffa-kpi-foot">
                    <span class="ffa-delta" :class="deltaClass(tile)">{{ deltaText(tile) }}</span>
                    <spark-line :data="sparkSeries" :color="metric===tile.metric ? '#d99b2c' : '#c4c9d2'"></spark-line>
                </div>
            </div>
        </div>

        <!-- ===== Hero timeseries ===== -->
        <div class="ffa-card ffa-fade">
            <div class="ffa-card-head">
                <h2>{{ metricLabel().charAt(0).toUpperCase() + metricLabel().slice(1) }} over time</h2>
                <div class="ffa-spacer"></div>
                <div class="ffa-tabs">
                    <button v-for="m in metrics" :key="m.id" :class="{'is-active': metric===m.id}" @click="setMetric(m.id)">{{ m.label }}</button>
                </div>
            </div>
            <area-chart :series="ts.series" :previous="ts.previous" :granularity="ts.granularity" :label="metricLabel()"></area-chart>
        </div>

        <!-- ===== Breakdown grid: Sources + Pages ===== -->
        <div class="ffa-grid ffa-fade">
            <div class="ffa-card">
                <div class="ffa-card-head">
                    <h2>Sources</h2>
                    <div class="ffa-spacer"></div>
                    <div class="ffa-tabs">
                        <button :class="{'is-active': sourcesDim==='channels'}" @click="setSourcesDim('channels')">Channels</button>
                        <button :class="{'is-active': sourcesDim==='referrers'}" @click="setSourcesDim('referrers')">Referrers</button>
                        <button :class="{'is-active': sourcesDim==='utm_source'}" @click="setSourcesDim('utm_source')">UTM source</button>
                        <button :class="{'is-active': sourcesDim==='utm_campaign'}" @click="setSourcesDim('utm_campaign')">Campaign</button>
                    </div>
                </div>
                <ranked-bars :rows="sources" :loading="loading.sources" val-key="views" val2-key="visitors"></ranked-bars>
            </div>

            <div class="ffa-card">
                <div class="ffa-card-head">
                    <h2>Pages</h2>
                    <div class="ffa-spacer"></div>
                    <div class="ffa-tabs">
                        <button :class="{'is-active': pagesDim==='top'}" @click="setPagesDim('top')">Top</button>
                        <button :class="{'is-active': pagesDim==='entry'}" @click="setPagesDim('entry')">Entry</button>
                        <button :class="{'is-active': pagesDim==='exit'}" @click="setPagesDim('exit')">Exit</button>
                    </div>
                </div>
                <ranked-bars :rows="pages" :loading="loading.pages" val-key="views" val2-key="visitors"></ranked-bars>
            </div>
        </div>

        <!-- ===== Breakdown grid: Devices + Locations ===== -->
        <div class="ffa-grid ffa-fade">
            <div class="ffa-card">
                <div class="ffa-card-head">
                    <h2>Devices &amp; tech</h2>
                    <div class="ffa-spacer"></div>
                    <div class="ffa-tabs">
                        <button :class="{'is-active': devicesDim==='device'}" @click="setDevicesDim('device')">Device</button>
                        <button :class="{'is-active': devicesDim==='browser'}" @click="setDevicesDim('browser')">Browser</button>
                        <button :class="{'is-active': devicesDim==='os'}" @click="setDevicesDim('os')">OS</button>
                        <button :class="{'is-active': devicesDim==='screen'}" @click="setDevicesDim('screen')">Screen</button>
                    </div>
                </div>
                <donut v-if="devicesDim==='device'" :slices="deviceSlices()" :total="deviceTotal()"></donut>
                <ranked-bars v-else :rows="devices" :loading="loading.devices" val-key="views" val2-key="visitors"></ranked-bars>
            </div>

            <div class="ffa-card">
                <div class="ffa-card-head"><h2>Locations</h2></div>
                <ranked-bars :rows="countries" :loading="loading.countries" :is-flag="true" val-key="views" val2-key="visitors"></ranked-bars>
            </div>
        </div>

        <!-- ===== Engagement + Content ===== -->
        <div class="ffa-grid ffa-fade">
            <div class="ffa-card">
                <div class="ffa-card-head"><h2>Scroll depth</h2></div>
                <donut :slices="scrollSlices()" :total="scrollTotal()"></donut>
                <div class="ffa-card-head" style="margin-top:18px"><h2>Top CTAs</h2></div>
                <ranked-bars :rows="engagement.ctas" :loading="loading.engagement" label-key="label" val-key="clicks" val2-key="unique_clicks"></ranked-bars>
            </div>

            <div class="ffa-card">
                <div class="ffa-card-head"><h2>Blog posts</h2></div>
                <ranked-bars :rows="posts" :loading="loading.posts" val-key="views" val2-key="visitors"></ranked-bars>
                <div class="ffa-card-head" style="margin-top:18px"><h2>Avg time on page</h2></div>
                <ranked-bars :rows="engagement.dwell" :loading="loading.engagement" val-key="avg_seconds" :val2-key="''" :fmt-val="dur"></ranked-bars>
            </div>
        </div>

        <!-- ===== Realtime + Admin activity ===== -->
        <div class="ffa-grid ffa-fade">
            <div class="ffa-card">
                <div class="ffa-card-head">
                    <h2>Realtime</h2>
                    <div class="ffa-spacer"></div>
                    <div class="ffa-rt-head">
                        <span class="ffa-rt-online">{{ realtime.online }}</span>
                        <span class="ffa-rt-online-label">in the last 5 min</span>
                    </div>
                </div>
                <div v-if="realtime.feed && realtime.feed.length" class="ffa-feed">
                    <div v-for="(f,i) in realtime.feed" :key="i" class="ffa-feed-row">
                        <span class="ffa-feed-path">{{ f.page_title || f.page_path }}</span>
                        <span class="ffa-chip" v-if="f.device_type">{{ f.device_type }}</span>
                        <span class="ffa-chip" v-if="f.country">{{ flag(f.country) }} {{ f.country }}</span>
                        <span class="ffa-feed-meta">{{ relTime(f.created_at) }}</span>
                    </div>
                </div>
                <div v-else class="ffa-empty">No visitors in the last 30 minutes</div>
            </div>

            <div class="ffa-card">
                <div class="ffa-card-head">
                    <h2>Admin activity</h2>
                    <div class="ffa-spacer"></div>
                    <span class="ffa-chip">{{ nfmt(admin.login_views) }} login views · {{ nfmt(admin.login_unique) }} unique</span>
                </div>
                <div v-if="admin.logins && admin.logins.length" class="ffa-feed">
                    <div v-for="(l,i) in admin.logins" :key="i" class="ffa-feed-row">
                        <span class="dashicons dashicons-admin-users" style="font-size:15px;width:15px;height:15px;color:var(--ffa-ink-3)"></span>
                        <span class="ffa-feed-path">{{ l.username }}</span>
                        <span class="ffa-chip">login</span>
                        <span class="ffa-feed-meta">{{ relTime(l.created_at) }}</span>
                    </div>
                </div>
                <div v-else class="ffa-empty">No admin logins in this range</div>
            </div>
        </div>

        <!-- ===== Revenue / Conversions (populated in Group 5) ===== -->
        <!-- ffa:revenue-anchor -->

        <!-- Pull confirm -->
        <div v-if="modal==='pull'" class="ffa-modal-overlay" @click.self="modal=null">
            <div class="ffa-modal">
                <h3>Pull from {{ pullSource==='prod' ? 'Production' : 'Live Dev' }}?</h3>
                <p>This replaces <strong>all local analytics, link-click, and admin-activity data</strong> with a copy fetched from
                    <code>{{ pullSource==='prod' ? cfg.prodUrl : cfg.liveDevUrl }}</code>. Local-only data will be lost. This can't be undone.</p>
                <div class="ffa-modal-actions">
                    <button class="ffa-btn ffa-btn-ghost" @click="modal=null" :disabled="busy">Cancel</button>
                    <button class="ffa-btn ffa-btn-primary" @click="confirmPull" :disabled="busy">{{ busy ? 'Pulling…' : 'Pull &amp; replace' }}</button>
                </div>
            </div>
        </div>

        <!-- Reset confirm -->
        <div v-if="modal==='reset'" class="ffa-modal-overlay" @click.self="modal=null">
            <div class="ffa-modal">
                <h3>Reset all analytics data?</h3>
                <p>This permanently deletes <strong>all</strong> pageview, engagement, link-click, and admin-activity records for every template. Tracked-link registrations are kept. This can't be undone.</p>
                <div class="ffa-modal-actions">
                    <button class="ffa-btn ffa-btn-ghost" @click="modal=null" :disabled="busy">Cancel</button>
                    <button class="ffa-btn ffa-btn-danger" @click="confirmReset" :disabled="busy">{{ busy ? 'Resetting…' : 'Delete everything' }}</button>
                </div>
            </div>
        </div>

        <!-- Toast -->
        <div v-if="toast" class="ffa-toast" :class="{'is-error': toast.err}">{{ toast.msg }}</div>

    </template>
</div>
