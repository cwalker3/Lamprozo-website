// plugin/assets/js/subscriptions.js

/**
 * Subscriptions Management JavaScript
 */

// Progressive web app check
if (typeof isPWA === 'undefined') isPWA = subscriptionsData.isPWA;
isPWA = Boolean(Number(isPWA));

document.addEventListener('DOMContentLoaded', function() {
    // Check if Vue is loaded and we're on the subscriptions page
    if (typeof Vue !== 'undefined' && document.getElementById('ffc-subscriptions-app') && !isPWA) {
        initSubscriptionsApp();
    }
});

function initSubscriptionsApp() {
    const { createApp, ref, computed, onMounted, watch } = Vue;

    createApp({
        setup() {
            // State management
            const loading = ref(true);
            const subscriptions = ref([]);
            const users = ref({});
            
            // UI state
            const currentSubscription = ref(null);
            const searchTimer = ref(null);
            
            // Stripe state
            const stripe = ref(null);
            const elements = ref(null);
            const paymentElement = ref(null);
            const paymentClientSecret = ref('');
            const processingPayment = ref(false);
            const paymentError = ref('');
            
            // Modals
            const showDetailModal = ref(false);
            const showCancelModal = ref(false);
            const showPaymentModal = ref(false);
            const cancelSubscriptionId = ref(null);
            const updateSubscriptionId = ref(null);
            
            // Sorting
            const sortField = ref('subscription_current_period_end');
            const sortDirection = ref('asc');
            
            // Filters
            const filters = ref({
                status: '',
                search: ''
            });
            
            // Pagination
            const pagination = ref({
                currentPage: 1,
                perPage: 10,
                totalItems: 0,
                totalPages: 1
            });
            
            // Computed properties
            const filteredSubscriptions = computed(() => {
                let filtered = [...subscriptions.value];
                
                // Apply status filter
                if (filters.value.status) {
                    filtered = filtered.filter(sub => sub.subscription_status === filters.value.status);
                }
                
                // Apply search filter
                if (filters.value.search) {
                    const searchLower = filters.value.search.toLowerCase();
                    filtered = filtered.filter(sub => {
                        return sub.subscription_id.toLowerCase().includes(searchLower) ||
                               sub.features.toLowerCase().includes(searchLower) ||
                               sub.options.toLowerCase().includes(searchLower) ||
                               (users.value[sub.userId] && users.value[sub.userId].toLowerCase().includes(searchLower));
                    });
                }
                
                // Apply sorting
                filtered.sort((a, b) => {
                    let aVal = a[sortField.value];
                    let bVal = b[sortField.value];
                    
                    // Handle numeric values
                    if (sortField.value === 'total_amount') {
                        aVal = parseFloat(aVal);
                        bVal = parseFloat(bVal);
                    }
                    
                    // Handle dates
                    if (sortField.value.includes('_at') || sortField.value.includes('period')) {
                        aVal = new Date(aVal).getTime();
                        bVal = new Date(bVal).getTime();
                    }
                    
                    if (sortDirection.value === 'asc') {
                        return aVal > bVal ? 1 : -1;
                    } else {
                        return aVal < bVal ? 1 : -1;
                    }
                });
                
                // Update pagination
                pagination.value.totalItems = filtered.length;
                pagination.value.totalPages = Math.ceil(filtered.length / pagination.value.perPage);
                
                return filtered;
            });
            
            const paginatedSubscriptions = computed(() => {
                const start = (pagination.value.currentPage - 1) * pagination.value.perPage;
                const end = start + pagination.value.perPage;
                return filteredSubscriptions.value.slice(start, end);
            });
            
            // Methods
            async function fetchSubscriptions() {
                loading.value = true;
                
                // Prepare request
                const url = `${subscriptionsData.apiUrl}get-subscriptions/?auth_id=${window.auth_id}`;
                const options = {
                    headers: {
                        'Content-Type': 'application/json',
                    }
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
                            // non-JSON body or parse failed
                        }
                        console.error(`Error (${response.status}): ${errMsg}`);
                        return;
                    }

                    const data = await response.json();

                    subscriptions.value = data.subscriptions || [];
                    loading.value = false;
                }
                catch (error) {
                    console.error('Error fetching subscriptions:', error);
                    loading.value = false;
                }
            }
            
            function fetchUsers() {
                // Fetch users for display names
                fetch(`${subscriptionsData.apiUrl}get-users/?auth_id=${window.auth_id}`, {
                    headers: {
                        'Content-Type': 'application/json'
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        users.value = data.users;
                    }
                })
                .catch(error => {
                    console.error('Error fetching users:', error);
                });
            }
            
            function resetFilters() {
                filters.value = {
                    status: '',
                    search: ''
                };
                pagination.value.currentPage = 1;
            }
            
            function handleSearchInput() {
                // Debounce search input
                if (searchTimer.value) {
                    clearTimeout(searchTimer.value);
                }
                
                searchTimer.value = setTimeout(() => {
                    pagination.value.currentPage = 1;
                }, 500);
            }
            
            function changePage(page) {
                if (page >= 1 && page <= pagination.value.totalPages) {
                    pagination.value.currentPage = page;
                }
            }
            
            function sortBy(field) {
                if (sortField.value === field) {
                    // Toggle direction if same field
                    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
                } else {
                    // Set new field and default to ascending
                    sortField.value = field;
                    sortDirection.value = 'asc';
                }
            }
            
            function getSortClass(field) {
                if (sortField.value !== field) return '';
                return sortDirection.value === 'asc' ? 'sorted-asc' : 'sorted-desc';
            }
            
            function viewSubscriptionDetails(subscription) {
                currentSubscription.value = subscription;
                showDetailModal.value = true;
            }
            
            function confirmCancel(subscriptionId) {
                cancelSubscriptionId.value = subscriptionId;
                showCancelModal.value = true;
            }
            
            async function cancelSubscription() {
                loading.value = true;
                
                try {
                    const response = await fetch(`${subscriptionsData.apiUrl}cancel-subscription`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            subscriptionId: cancelSubscriptionId.value,
                            auth_id: window.auth_id
                        })
                    });
                    
                    const data = await response.json();

                    if (data.success) {
                        showCancelModal.value = false;
                        // Update local state
                        const sub = subscriptions.value.find(s => s.subscription_id === cancelSubscriptionId.value);
                        if (sub) {
                            sub.subscription_status = 'cancelled';
                        }
                        fetchSubscriptions(); // Refresh to get latest data
                    } else {
                        console.error('Error cancelling subscription:', data.message);
                        alert('Error cancelling subscription: ' + (data.message || 'Unknown error'));
                    }
                } catch (error) {
                    console.error('Error cancelling subscription:', error);
                    alert('Error cancelling subscription. Please try again.');
                } finally {
                    loading.value = false;
                }
            }
            
            // Helper functions
            function formatSubscriptionId(id) {
                return id ? id.substr(0, 20) + '...' : 'N/A';
            }
            
            function formatDate(dateString) {
                if (!dateString) return 'N/A';
                
                const date = new Date(dateString);
                return date.toLocaleDateString();
            }
            
            function formatPrice(price) {
                return parseFloat(price).toFixed(2);
            }
            
            function capitalizeFirst(string) {
                if (!string) return '';
                return string.charAt(0).toUpperCase() + string.slice(1);
            }
            
            function getUserName(userId) {
                return users.value[userId] || `User #${userId}`;
            }
            
            function parseFeatures(subscription) {
                if (!subscription.features) return [];
                return subscription.features.split(',').map(f => f.trim());
            }
            
            function parseOptions(subscription) {
                if (!subscription.options) return [];
                return subscription.options.split(',').map(o => o.trim());
            }
            
            function getIntervalDisplay(intervals) {
                if (!intervals) return 'N/A';
                const parts = intervals.split(',');
                const interval = parts[0] ? parts[0].trim() : '';
                
                // Handle different interval formats
                switch(interval.toLowerCase()) {
                    case 'month':
                    case 'monthly':
                        return 'month';
                    case 'year':
                    case 'yearly':
                        return 'year';
                    case 'week':
                    case 'weekly':
                        return 'week';
                    default:
                        return interval;
                }
            }
            
            // Lifecycle hooks
            onMounted(() => {
                fetchUsers();
                fetchSubscriptions();
            });
            
            // Return exposed state and methods
            return {
                loading,
                subscriptions,
                users,
                filteredSubscriptions,
                paginatedSubscriptions,
                pagination,
                sortField,
                sortDirection,
                filters,
                showDetailModal,
                showCancelModal,
                showPaymentModal,
                currentSubscription,
                paymentClientSecret,
                processingPayment,
                paymentError,
                
                // Methods
                fetchSubscriptions,
                resetFilters,
                handleSearchInput,
                changePage,
                sortBy,
                getSortClass,
                viewSubscriptionDetails,
                confirmCancel,
                cancelSubscription,
                
                // Helper functions
                formatSubscriptionId,
                formatDate,
                formatPrice,
                capitalizeFirst,
                getUserName,
                parseFeatures,
                parseOptions,
                getIntervalDisplay
            };
        }
    }).mount('#ffc-subscriptions-app');
}