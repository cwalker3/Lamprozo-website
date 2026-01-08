/**
 * Firefly Analytics Dashboard - Vue.js 3 App
 */

document.addEventListener('DOMContentLoaded', function() {
    const { createApp } = Vue;

    createApp({
        data() {
            return {
                loading: false,
                activeTab: 'pages',
                days: 30,
                overview: {
                    today: { views: 0, unique: 0 },
                    week: { views: 0, unique: 0 },
                    month: { views: 0, unique: 0 }
                },
                chartData: [],
                pages: [],
                posts: [],
                referrers: [],
                adminUrl: fireflyAnalytics.adminUrl
            };
        },

        mounted() {
            this.loadAllData();
        },

        methods: {
            async loadAllData() {
                this.loading = true;
                await Promise.all([
                    this.loadOverview(),
                    this.loadChart(),
                    this.loadData(this.activeTab)
                ]);
                this.loading = false;
            },

            async loadOverview() {
                try {
                    const response = await fetch(
                        fireflyAnalytics.restUrl + '?type=overview',
                        {
                            headers: {
                                'X-WP-Nonce': fireflyAnalytics.nonce
                            }
                        }
                    );
                    const data = await response.json();
                    this.overview = data;
                } catch (error) {
                    console.error('Failed to load overview:', error);
                }
            },

            async loadChart() {
                try {
                    const response = await fetch(
                        fireflyAnalytics.restUrl + '?type=chart&days=' + this.days,
                        {
                            headers: {
                                'X-WP-Nonce': fireflyAnalytics.nonce
                            }
                        }
                    );
                    this.chartData = await response.json();
                } catch (error) {
                    console.error('Failed to load chart:', error);
                }
            },

            async loadData(type) {
                try {
                    const response = await fetch(
                        fireflyAnalytics.restUrl + '?type=' + type + '&days=' + this.days,
                        {
                            headers: {
                                'X-WP-Nonce': fireflyAnalytics.nonce
                            }
                        }
                    );
                    const data = await response.json();
                    
                    if (type === 'pages') {
                        this.pages = data;
                    } else if (type === 'posts') {
                        this.posts = data;
                    } else if (type === 'referrers') {
                        this.referrers = data;
                    }
                } catch (error) {
                    console.error('Failed to load ' + type + ':', error);
                }
            },

            getBarHeight(views) {
                if (this.chartData.length === 0) return 0;
                const maxViews = Math.max(...this.chartData.map(d => parseInt(d.views) || 0));
                if (maxViews === 0) return 0;
                return Math.max(5, (views / maxViews) * 100);
            },

            formatDate(dateStr) {
                const date = new Date(dateStr);
                return (date.getMonth() + 1) + '/' + date.getDate();
            }
        }
    }).mount('#firefly-analytics-app');
});
