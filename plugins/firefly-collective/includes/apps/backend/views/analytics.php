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
    'adminUrl' => admin_url()
));
?>

<div class="wrap">
    <h1>Analytics</h1>
    
    <div id="firefly-analytics-app">
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

        <!-- Loading State -->
        <div v-if="loading" class="analytics-loading">Loading...</div>
    </div>
</div>
