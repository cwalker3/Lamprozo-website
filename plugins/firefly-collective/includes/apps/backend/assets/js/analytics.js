/**
 * Firefly Analytics Dashboard - Vue.js 3 App
 */

document.addEventListener('DOMContentLoaded', function() {
    // Store Vue app instance for data refresh
    let vueApp = null;

    // Track on Local toggle handler
    const trackLocalCheckbox = document.getElementById('analytics-track-local');
    if (trackLocalCheckbox) {
        trackLocalCheckbox.addEventListener('change', function() {
            const enabled = this.checked;

            fetch(fireflyAnalytics.restUrl.replace('/analytics', '/analytics/track-local'), {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': fireflyAnalytics.nonce,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ enabled: enabled })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Track on local setting updated:', enabled);
                } else {
                    alert('Failed to update setting');
                    trackLocalCheckbox.checked = !enabled; // Revert
                }
            })
            .catch(error => {
                console.error('Error updating setting:', error);
                alert('Error updating setting');
                trackLocalCheckbox.checked = !enabled; // Revert
            });
        });
    }

    // Source toggle buttons handler
    const sourceToggleBtns = document.querySelectorAll('.source-toggle-btn');
    let selectedSource = fireflyAnalytics.liveDevUrl; // Default to Live Dev

    sourceToggleBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            // Remove active from all buttons
            sourceToggleBtns.forEach(b => {
                b.classList.remove('active');
                b.style.background = 'white';
                b.style.color = '#2271b1';
            });

            // Add active to clicked button
            this.classList.add('active');
            this.style.background = '#2271b1';
            this.style.color = 'white';

            // Update selected source
            selectedSource = this.getAttribute('data-source');
        });
    });

    // Modal control functions for Pull Data
    window.closePullModal = function() {
        document.getElementById('pull-modal').style.display = 'none';
    };

    window.confirmPull = function() {
        const pullBtn = document.getElementById('analytics-pull-btn');
        const sourceName = selectedSource.includes('dev.') ? 'Live Dev' : 'Production';

        // Close modal
        closePullModal();

        // Start pull process
        pullBtn.disabled = true;
        pullBtn.textContent = 'Pulling...';

        // Call server-side pull endpoint
        fetch(fireflyAnalytics.restUrl.replace('/analytics', '/analytics/pull'), {
            method: 'POST',
            headers: {
                'X-WP-Nonce': fireflyAnalytics.nonce,
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ source: selectedSource })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                const successMsg = document.createElement('div');
                successMsg.className = 'notice notice-success is-dismissible';
                const i = data.imported;
                successMsg.innerHTML = `<p><strong>Success!</strong> Imported ${i.analytics} analytics records, ${i.link_clicks} link clicks, and ${i.admin_activity} admin activity records from ${sourceName}.</p>`;
                document.querySelector('.wrap').insertBefore(successMsg, document.querySelector('.wrap').firstChild);

                // Refresh Vue app data
                if (vueApp && typeof vueApp.loadAllData === 'function') {
                    vueApp.loadAllData();
                }

                // Auto-dismiss after 5 seconds
                setTimeout(() => successMsg.remove(), 5000);
            } else {
                alert('Failed to pull data: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Pull error:', error);
            alert('Error pulling analytics data. Check console for details.');
        })
        .finally(() => {
            pullBtn.disabled = false;
            pullBtn.textContent = 'Pull Data';
        });
    };

    // Pull Data button handler
    const pullBtn = document.getElementById('analytics-pull-btn');
    if (pullBtn) {
        pullBtn.addEventListener('click', function() {
            const sourceName = selectedSource.includes('dev.') ? 'Live Dev' : 'Production';

            // Update modal with source name
            document.getElementById('pull-source-name').textContent = sourceName;

            // Show modal
            document.getElementById('pull-modal').style.display = 'flex';
        });
    }

    // Modal control functions for Reset
    window.closeResetModal = function() {
        document.getElementById('reset-modal').style.display = 'none';
    };

    window.confirmReset = function() {
        const resetBtn = document.getElementById('analytics-reset-btn');

        // Close modal
        closeResetModal();

        // Start reset process
        resetBtn.disabled = true;
        resetBtn.textContent = 'Resetting...';

        fetch(fireflyAnalytics.restUrl.replace('/analytics', '/analytics/reset'), {
            method: 'POST',
            headers: {
                'X-WP-Nonce': fireflyAnalytics.nonce
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success and reload
                const successMsg = document.createElement('div');
                successMsg.className = 'notice notice-success';
                successMsg.innerHTML = '<p><strong>Success!</strong> Analytics data has been reset. Reloading page...</p>';
                document.querySelector('.wrap').insertBefore(successMsg, document.querySelector('.wrap').firstChild);

                setTimeout(() => location.reload(), 1500);
            } else {
                alert('Failed to reset analytics data: ' + (data.message || 'Unknown error'));
                resetBtn.disabled = false;
                resetBtn.textContent = 'Reset All Data';
            }
        })
        .catch(error => {
            console.error('Reset error:', error);
            alert('Error resetting analytics data');
            resetBtn.disabled = false;
            resetBtn.textContent = 'Reset All Data';
        });
    };

    // Reset button handler
    const resetBtn = document.getElementById('analytics-reset-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            // Show modal
            document.getElementById('reset-modal').style.display = 'flex';
        });
    }

    const { createApp } = Vue;

    vueApp = createApp({
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
                trackedLinks: [],
                adminActivity: { login_views: 0, login_unique: 0, logins: [] },
                adminUrl: fireflyAnalytics.adminUrl,
                deleteClickLink: null
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
                    // Use different endpoint for tracked links
                    const url = type === 'links'
                        ? '/wp-json/firefly-collective/v1/tracked-links?days=' + this.days
                        : fireflyAnalytics.restUrl + '?type=' + type + '&days=' + this.days;

                    const response = await fetch(url, {
                        headers: {
                            'X-WP-Nonce': fireflyAnalytics.nonce
                        }
                    });
                    const data = await response.json();

                    if (type === 'pages') {
                        this.pages = data;
                    } else if (type === 'posts') {
                        this.posts = data;
                    } else if (type === 'referrers') {
                        this.referrers = data;
                    } else if (type === 'links') {
                        this.trackedLinks = data;
                    } else if (type === 'admin') {
                        this.adminActivity = data;
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
            },

            formatDateTime(dateStr) {
                const date = new Date(dateStr);
                const now = new Date();
                const diff = Math.floor((now - date) / 1000); // seconds

                if (diff < 60) return 'Just now';
                if (diff < 3600) return Math.floor(diff / 60) + ' min ago';
                if (diff < 86400) return Math.floor(diff / 3600) + ' hrs ago';
                if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';

                // For older dates, show formatted date
                return (date.getMonth() + 1) + '/' + date.getDate() + '/' + date.getFullYear();
            },

            showDeleteClickModal(link) {
                this.deleteClickLink = link;

                // Populate modal content
                const linkElement = document.getElementById('delete-click-link');
                linkElement.href = link.link_url;
                linkElement.textContent = link.link_text || link.link_url;

                // Show modal
                document.getElementById('delete-click-modal').style.display = 'flex';
            },

            async doDeleteClick() {
                const link = this.deleteClickLink;
                if (!link) return;

                // Disable button and show loading state
                const confirmBtn = document.getElementById('delete-click-confirm-btn');
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Deleting...';
                link.deleting = true;

                try {
                    const response = await fetch('/wp-json/firefly-collective/v1/delete-click/' + link.id, {
                        method: 'DELETE',
                        headers: {
                            'X-WP-Nonce': fireflyAnalytics.nonce
                        }
                    });

                    const data = await response.json();

                    if (response.ok && data.success) {
                        // Update the link counts in the UI
                        link.total_clicks = data.total_clicks;
                        link.unique_clicks = data.unique_clicks;

                        // If no more clicks, update last_click
                        if (data.total_clicks === 0) {
                            link.last_click = null;
                        }

                        // Close modal
                        document.getElementById('delete-click-modal').style.display = 'none';
                        this.deleteClickLink = null;
                    } else {
                        alert('Failed to delete click: ' + (data.error || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Failed to delete click:', error);
                    alert('Failed to delete click. Check console for details.');
                } finally {
                    link.deleting = false;
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Delete Click';
                }
            }
        }
    }).mount('#firefly-analytics-app');

    // Global functions for modal handlers (outside Vue app)
    window.closeDeleteClickModal = function() {
        document.getElementById('delete-click-modal').style.display = 'none';
    };

    window.confirmDeleteClick = function() {
        if (vueApp && vueApp.doDeleteClick) {
            vueApp.doDeleteClick();
        }
    };
});
