<?php
/**
 * Analytics Dashboard View
 */

if (!defined('ABSPATH')) {
    exit;
}

// Enqueue Vue.js and our assets
wp_enqueue_script('vue-js', 'https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js', array(), '3', true);

$js_file = plugin_dir_path(__FILE__) . '../assets/js/analytics.js';
$css_file = plugin_dir_path(__FILE__) . '../assets/css/analytics.css';

wp_enqueue_script(
    'firefly-analytics',
    plugin_dir_url(__FILE__) . '../assets/js/analytics.js',
    array('vue-js'),
    file_exists($js_file) ? filemtime($js_file) : '1.0.0',
    true
);

wp_enqueue_style(
    'firefly-analytics',
    plugin_dir_url(__FILE__) . '../assets/css/analytics.css',
    array(),
    file_exists($css_file) ? filemtime($css_file) : '1.0.0'
);

wp_localize_script('firefly-analytics', 'fireflyAnalytics', array(
    'restUrl' => rest_url('firefly-collective/v1/analytics'),
    'nonce' => wp_create_nonce('wp_rest'),
    'adminUrl' => admin_url(),
    'liveDevUrl' => defined('LIVE_DEV_URL') ? LIVE_DEV_URL : 'https://dev.fireflycreative.io',
    'prodUrl' => defined('PROD_URL') ? PROD_URL : 'https://fireflycreative.io'
));
?>

<div class="wrap">
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1>Analytics</h1>
            <?php
            $template = function_exists('firefly_collective_get_active_template')
                ? firefly_collective_get_active_template()
                : 'firefly';
            $track_local = get_option('firefly_analytics_track_local', false);
            $is_local = defined('FIREFLY_DEV') && FIREFLY_DEV;
            ?>
            <p style="margin: 0; color: #646970;">
                Template: <strong><?php echo esc_html($template); ?></strong>
                <?php if ($is_local): ?>
                    <span style="margin-left: 10px; padding: 2px 8px; background: #f0f0f1; border-radius: 3px; font-size: 12px;">LOCAL</span>
                <?php endif; ?>
            </p>
        </div>
        <div style="display: flex; gap: 15px; align-items: center;">
            <?php if ($is_local): ?>
                <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                    <input type="checkbox" id="analytics-track-local" <?php checked($track_local); ?>>
                    <span>Track on Local</span>
                </label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <span style="margin: 0; font-size: 13px;">Pull from:</span>
                    <?php
                    $live_dev_url = defined('LIVE_DEV_URL') ? LIVE_DEV_URL : 'https://dev.fireflycreative.io';
                    $prod_url = defined('PROD_URL') ? PROD_URL : 'https://fireflycreative.io';
                    ?>
                    <div class="analytics-source-toggle" style="display: inline-flex; border-radius: 4px; overflow: hidden; border: 1px solid #2271b1;">
                        <button class="source-toggle-btn active" data-source="<?php echo esc_attr($live_dev_url); ?>" style="padding: 6px 14px; border: none; background: #2271b1; color: white; cursor: pointer; font-size: 13px; transition: all 0.2s;">
                            Live Dev
                        </button>
                        <button class="source-toggle-btn" data-source="<?php echo esc_attr($prod_url); ?>" style="padding: 6px 14px; border: none; background: white; color: #2271b1; cursor: pointer; font-size: 13px; transition: all 0.2s;">
                            Production
                        </button>
                    </div>
                    <button id="analytics-pull-btn" class="button button-primary">Pull Data</button>
                </div>
            <?php endif; ?>
            <button id="analytics-reset-btn" class="button button-secondary" style="color: #b32d2e;">Reset All Data</button>
        </div>
    </div>

    <div id="firefly-analytics-app" v-cloak>
        <!-- Overview Cards -->
        <div class="analytics-overview">
            <div class="analytics-card">
                <h3>Today</h3>
                <div class="analytics-stat">
                    <span class="stat-value">{{ overview.today.views }}</span>
                    <span class="stat-label">views</span>
                </div>
                <div class="analytics-stat secondary">
                    <span class="stat-value">{{ overview.today.unique }}</span>
                    <span class="stat-label">unique</span>
                </div>
            </div>
            <div class="analytics-card">
                <h3>Last 7 Days</h3>
                <div class="analytics-stat">
                    <span class="stat-value">{{ overview.week.views }}</span>
                    <span class="stat-label">views</span>
                </div>
                <div class="analytics-stat secondary">
                    <span class="stat-value">{{ overview.week.unique }}</span>
                    <span class="stat-label">unique</span>
                </div>
            </div>
            <div class="analytics-card">
                <h3>Last 30 Days</h3>
                <div class="analytics-stat">
                    <span class="stat-value">{{ overview.month.views }}</span>
                    <span class="stat-label">views</span>
                </div>
                <div class="analytics-stat secondary">
                    <span class="stat-value">{{ overview.month.unique }}</span>
                    <span class="stat-label">unique</span>
                </div>
            </div>
        </div>

        <!-- Chart -->
        <div class="analytics-section">
            <h2>Views Over Time</h2>
            <div class="analytics-chart">
                <div class="chart-bars">
                    <div 
                        v-for="(day, index) in chartData" 
                        :key="index"
                        class="chart-bar-container"
                        :title="day.date + ': ' + day.views + ' views'"
                    >
                        <div 
                            class="chart-bar" 
                            :style="{ height: getBarHeight(day.views) + '%' }"
                        ></div>
                        <span class="chart-label" v-if="index % 5 === 0">{{ formatDate(day.date) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="analytics-tabs">
            <button
                :class="{ active: activeTab === 'pages' }"
                @click="activeTab = 'pages'; loadData('pages')"
            >Top Pages</button>
            <button
                :class="{ active: activeTab === 'posts' }"
                @click="activeTab = 'posts'; loadData('posts')"
            >Blog Posts</button>
            <button
                :class="{ active: activeTab === 'referrers' }"
                @click="activeTab = 'referrers'; loadData('referrers')"
            >Referrers</button>
            <button
                :class="{ active: activeTab === 'links' }"
                @click="activeTab = 'links'; loadData('links')"
            >Tracked Links</button>
            <button
                :class="{ active: activeTab === 'admin' }"
                @click="activeTab = 'admin'; loadData('admin')"
            >Admin Activity</button>
        </div>

        <!-- Date Range -->
        <div class="analytics-controls">
            <select v-model="days" @change="loadAllData()">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
            </select>
        </div>

        <!-- Data Tables -->
        <div class="analytics-section" v-if="activeTab === 'pages'">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th width="100">Views</th>
                        <th width="100">Unique</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="page in pages" :key="page.page_path">
                        <td>
                            <strong>{{ page.page_title || page.page_path }}</strong>
                            <br><small>{{ page.page_path }}</small>
                        </td>
                        <td>{{ page.views }}</td>
                        <td>{{ page.unique_visits }}</td>
                    </tr>
                    <tr v-if="pages.length === 0">
                        <td colspan="3">No data yet</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="analytics-section" v-if="activeTab === 'posts'">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Blog Post</th>
                        <th width="100">Views</th>
                        <th width="100">Unique</th>
                        <th width="80">Edit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="post in posts" :key="post.page_path">
                        <td>
                            <strong>{{ post.page_title || post.page_path }}</strong>
                            <br><small>{{ post.page_path }}</small>
                        </td>
                        <td>{{ post.views }}</td>
                        <td>{{ post.unique_visits }}</td>
                        <td>
                            <a v-if="post.post_id" :href="adminUrl + 'post.php?post=' + post.post_id + '&action=edit'" target="_blank">Edit</a>
                        </td>
                    </tr>
                    <tr v-if="posts.length === 0">
                        <td colspan="4">No blog post data yet</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="analytics-section" v-if="activeTab === 'referrers'">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Referrer</th>
                        <th width="100">Visits</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="ref in referrers" :key="ref.domain">
                        <td>{{ ref.domain }}</td>
                        <td>{{ ref.visits }}</td>
                    </tr>
                    <tr v-if="referrers.length === 0">
                        <td colspan="2">No referrer data yet</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="analytics-section" v-if="activeTab === 'links'">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Link URL</th>
                        <th width="250">Post/Page</th>
                        <th width="100">Total Clicks</th>
                        <th width="100">Unique Clicks</th>
                        <th width="120">Last Clicked</th>
                        <th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="link in trackedLinks" :key="link.id">
                        <td>
                            <a :href="link.link_url" target="_blank" rel="noopener noreferrer" style="word-break: break-all;">
                                {{ link.link_text || link.link_url }}
                            </a>
                        </td>
                        <td>
                            <a :href="adminUrl + 'post.php?post=' + link.post_id + '&action=edit'" target="_blank">
                                {{ link.post_title }}
                            </a>
                            <span style="color: #666; font-size: 12px;"> ({{ link.post_type }})</span>
                        </td>
                        <td>{{ link.total_clicks }}</td>
                        <td>{{ link.unique_clicks }}</td>
                        <td>
                            <span v-if="link.last_click">{{ formatDateTime(link.last_click) }}</span>
                            <span v-else style="color: #999;">Never</span>
                        </td>
                        <td>
                            <button
                                v-if="link.total_clicks > 0"
                                @click="showDeleteClickModal(link)"
                                class="button button-small"
                                style="padding: 2px 8px; font-size: 12px;"
                                :disabled="link.deleting"
                            >
                                {{ link.deleting ? 'Deleting...' : 'Delete Last' }}
                            </button>
                        </td>
                    </tr>
                    <tr v-if="trackedLinks.length === 0">
                        <td colspan="6">
                            No tracked links yet. Enable tracking on links in the Gutenberg editor to see click data here.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="analytics-section" v-if="activeTab === 'admin'">
            <div class="analytics-overview" style="margin-bottom: 20px;">
                <div class="analytics-card">
                    <h3>Login Page Views</h3>
                    <div class="analytics-stat">
                        <span class="stat-value">{{ adminActivity.login_views }}</span>
                        <span class="stat-label">views</span>
                    </div>
                    <div class="analytics-stat secondary">
                        <span class="stat-value">{{ adminActivity.login_unique }}</span>
                        <span class="stat-label">unique</span>
                    </div>
                </div>
                <div class="analytics-card">
                    <h3>Successful Logins</h3>
                    <div class="analytics-stat">
                        <span class="stat-value">{{ adminActivity.logins.length }}</span>
                        <span class="stat-label">logins</span>
                    </div>
                </div>
            </div>
            <h3>Login History</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th width="200">Time</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="login in adminActivity.logins" :key="login.created_at">
                        <td><strong>{{ login.username }}</strong></td>
                        <td>{{ formatDateTime(login.created_at) }}</td>
                    </tr>
                    <tr v-if="adminActivity.logins.length === 0">
                        <td colspan="2">No login activity yet</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Loading State -->
        <div v-if="loading" class="analytics-loading">Loading...</div>
    </div>

    <!-- Pull Data Confirmation Modal -->
    <div id="pull-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Pull</h2>
                <button class="modal-close" onclick="closePullModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>Replace local analytics data?</strong>
                </div>
                <div class="modal-details">
                    <p><strong>Source:</strong> <span id="pull-source-name"></span></p>
                    <p class="modal-description">
                        This will download analytics data from the selected environment and replace all local analytics data.
                        Your current local data will be lost. This action cannot be undone.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closePullModal()" class="modal-button modal-button-cancel">Cancel</button>
                <button onclick="confirmPull()" class="modal-button modal-button-confirm modal-button-primary">Pull Data</button>
            </div>
        </div>
    </div>

    <!-- Reset Confirmation Modal -->
    <div id="reset-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Confirm Reset</h2>
                <button class="modal-close" onclick="closeResetModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>Delete ALL analytics data?</strong>
                </div>
                <div class="modal-details">
                    <p class="modal-description">
                        This will permanently delete all analytics data from the database.
                        All page views, statistics, and historical data will be lost.
                        This action cannot be undone.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeResetModal()" class="modal-button modal-button-cancel">Cancel</button>
                <button onclick="confirmReset()" class="modal-button modal-button-confirm modal-button-danger">Delete All Data</button>
            </div>
        </div>
    </div>

    <!-- Delete Click Confirmation Modal -->
    <div id="delete-click-modal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header">
                <h2>Delete Click</h2>
                <button class="modal-close" onclick="closeDeleteClickModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="modal-warning">
                    <span class="warning-icon">⚠️</span>
                    <strong>Delete most recent click?</strong>
                </div>
                <div class="modal-details">
                    <p class="modal-description">
                        This will delete the most recent click for this link and decrement the click count.
                        This is useful for reversing accidental clicks.
                    </p>
                    <p style="margin-top: 10px;">
                        <strong>Link:</strong> <a id="delete-click-link" href="#" target="_blank" style="word-break: break-all;"></a>
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button onclick="closeDeleteClickModal()" class="modal-button modal-button-cancel">Cancel</button>
                <button id="delete-click-confirm-btn" onclick="confirmDeleteClick()" class="modal-button modal-button-confirm modal-button-danger">Delete Click</button>
            </div>
        </div>
    </div>
</div>

<style>
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}

.modal-container {
    background: white;
    border-radius: 4px;
    max-width: 500px;
    width: 90%;
    max-height: 90vh;
    overflow: auto;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid #ddd;
}

.modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.modal-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #666;
    line-height: 1;
    padding: 0;
    width: 30px;
    height: 30px;
}

.modal-close:hover {
    color: #000;
}

.modal-body {
    padding: 20px;
}

.modal-warning {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 4px;
    margin-bottom: 15px;
}

.warning-icon {
    font-size: 24px;
}

.modal-details {
    color: #444;
}

.modal-details p {
    margin: 10px 0;
}

.modal-description {
    color: #666;
    font-size: 14px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 20px;
    border-top: 1px solid #ddd;
}

.modal-button {
    padding: 8px 16px;
    border-radius: 4px;
    border: 1px solid #ddd;
    cursor: pointer;
    font-size: 14px;
    transition: all 0.2s;
}

.modal-button-cancel {
    background: white;
    color: #333;
}

.modal-button-cancel:hover {
    background: #f5f5f5;
}

.modal-button-confirm {
    color: white;
}

.modal-button-primary {
    background: #2271b1;
    border-color: #2271b1;
}

.modal-button-primary:hover {
    background: #135e96;
}

.modal-button-danger {
    background: #dc3545;
    border-color: #dc3545;
}

.modal-button-danger:hover {
    background: #c82333;
}
</style>
