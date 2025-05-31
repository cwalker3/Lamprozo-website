// plugin/assets/js/orders.js

/**
 * Orders Management JavaScript
 */
document.addEventListener('DOMContentLoaded', function() {
    // Check if Vue is loaded and we're on the orders page
    if (typeof Vue !== 'undefined' && document.getElementById('ffc-orders-app')) {
        initOrdersApp();
    }
});

function initOrdersApp() {
    const { createApp, ref, computed, onMounted, watch } = Vue;
    
    createApp({
        setup() {
            // State management
            const loading = ref(true);
            const orders = ref([]);
            const allItems = ref([]);
            const features = ref([]);
            const options = ref([]);
            const addons = ref([]);
            const users = ref({});
            
            // UI state
            const expandedOrders = ref([]);
            const selectedOrders = ref([]);
            const currentOrderId = ref(null);
            const currentOrder = ref(null);
            const searchTimer = ref(null);
            
            // Modals
            const showDetailModal = ref(false);
            const showStatusModal = ref(false);
            const showDeleteModal = ref(false);
            const newStatus = ref('pending');
            
            // Sorting
            const sortField = ref('createdAt');
            const sortDirection = ref('desc');
            
            // Bulk actions
            const bulkAction = ref('');
            const bulkStatus = ref('pending');
            
            // Filters
            const filters = ref({
                status: '',
                dateFrom: '',
                dateTo: '',
                search: '',
                orderID: ''
            });
            
            // Pagination
            const pagination = ref({
                currentPage: 1,
                perPage: 10,
                totalItems: 0,
                totalPages: 1
            });
            
            // Computed properties
            const groupedOrders = computed(() => {
                const groups = {};
                
                orders.value.forEach(order => {
                    if (!groups[order.orderID]) {
                        groups[order.orderID] = {
                            orderID: order.orderID,
                            userId: order.userId,
                            status: order.status,
                            createdAt: order.createdAt,
                            totalValue: 0,
                            items: [],
                            userData: order.userData ? JSON.parse(order.userData) : {}
                        };
                    }
                    
                    groups[order.orderID].items.push(order);
                    groups[order.orderID].totalValue += parseFloat(order.totalPrice);
                });
                
                return Object.values(groups);
            });
            
            const allSelected = computed(() => {
                return groupedOrders.value.length > 0 && selectedOrders.value.length === groupedOrders.value.length;
            });
            
            // Methods
            function fetchOrders() {
                loading.value = true;
                
                const queryParams = new URLSearchParams({
                    page: pagination.value.currentPage,
                    per_page: pagination.value.perPage,
                    sort_field: sortField.value,
                    sort_direction: sortDirection.value
                });
                
                // Add filters if they exist
                if (filters.value.status) queryParams.append('status', filters.value.status);
                if (filters.value.dateFrom) queryParams.append('date_from', filters.value.dateFrom);
                if (filters.value.dateTo) queryParams.append('date_to', filters.value.dateTo);
                if (filters.value.search) queryParams.append('search', filters.value.search);
                if (filters.value.orderID) queryParams.append('order_id', filters.value.orderID);
                
                fetch(`${ordersData.apiUrl}get-orders?${queryParams.toString()}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ordersData.nonce
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        orders.value = data.orders;
                        pagination.value.totalItems = data.total;
                        pagination.value.totalPages = Math.ceil(data.total / pagination.value.perPage);
                        
                        // If current page is greater than total pages, go to last page
                        if (pagination.value.currentPage > pagination.value.totalPages && pagination.value.totalPages > 0) {
                            changePage(pagination.value.totalPages);
                            return;
                        }
                    } else {
                        console.error('Error fetching orders:', data.message);
                    }
                    loading.value = false;
                })
                .catch(error => {
                    console.error('Error fetching orders:', error);
                    loading.value = false;
                });
            }
            
            function fetchLookupData() {
                // Fetch features, options, addons, and users in parallel
                Promise.all([
                    fetch(`${ordersData.apiUrl}get-features`, {
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': ordersData.nonce
                        }
                    }).then(response => response.json()),
                    
                    fetch(`${ordersData.apiUrl}get-options`, {
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': ordersData.nonce
                        }
                    }).then(response => response.json()),
                    
                    fetch(`${ordersData.apiUrl}get-addons`, {
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': ordersData.nonce
                        }
                    }).then(response => response.json()),
                    
                    fetch(`${ordersData.apiUrl}get-users`, {
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': ordersData.nonce
                        }
                    }).then(response => response.json())
                ])
                .then(([featuresData, optionsData, addonsData, usersData]) => {
                    if (featuresData.success) features.value = featuresData.features;
                    if (optionsData.success) options.value = optionsData.options;
                    if (addonsData.success) addons.value = addonsData.addons;
                    if (usersData.success) users.value = usersData.users;
                })
                .catch(error => {
                    console.error('Error fetching lookup data:', error);
                });
            }
            
            function toggleExpand(orderID) {
                const index = expandedOrders.value.indexOf(orderID);
                if (index === -1) {
                    expandedOrders.value.push(orderID);
                } else {
                    expandedOrders.value.splice(index, 1);
                }
            }
            
            function toggleSelectAll(event) {
                if (event.target.checked) {
                    // Select all orders
                    selectedOrders.value = groupedOrders.value.map(order => order.orderID);
                } else {
                    // Deselect all orders
                    selectedOrders.value = [];
                }
            }
            
            function handleOrderSelection() {
                // This is handled by v-model binding, this function is here for potential future use
            }
            
            function resetFilters() {
                filters.value = {
                    status: '',
                    dateFrom: '',
                    dateTo: '',
                    search: '',
                    orderID: ''
                };
                fetchOrders();
            }
            
            function handleSearchInput() {
                // Debounce search input
                if (searchTimer.value) {
                    clearTimeout(searchTimer.value);
                }
                
                searchTimer.value = setTimeout(() => {
                    fetchOrders();
                }, 500);
            }
            
            function changePage(page) {
                pagination.value.currentPage = page;
                fetchOrders();
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
                fetchOrders();
            }
            
            function getSortClass(field) {
                if (sortField.value !== field) return '';
                return sortDirection.value === 'asc' ? 'sorted-asc' : 'sorted-desc';
            }
            
            function viewOrderDetails(order) {
                currentOrder.value = order;
                showDetailModal.value = true;
            }
            
            function printOrder() {
                // Create a printable version of the current order
                const printContent = document.createElement('div');
                printContent.innerHTML = `
                    <h1>Order #${formatOrderID(currentOrder.value.orderID)}</h1>
                    <div style="margin-bottom: 20px;">
                        <div><strong>Customer:</strong> ${getUserName(currentOrder.value.userId)}</div>
                        <div><strong>Date:</strong> ${formatDate(currentOrder.value.createdAt)}</div>
                        <div><strong>Status:</strong> ${capitalizeFirst(currentOrder.value.status)}</div>
                    </div>
                    
                    <h2>Order Items</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Item</th>
                                <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Options</th>
                                <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Addons</th>
                                <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Quantity</th>
                                <th style="text-align: left; padding: 8px; border: 1px solid #ddd;">Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${currentOrder.value.items.map(item => `
                                <tr>
                                    <td style="padding: 8px; border: 1px solid #ddd;">${getFeatureName(item.featureId)}</td>
                                    <td style="padding: 8px; border: 1px solid #ddd;">${getOptionName(item.optionId)}</td>
                                    <td style="padding: 8px; border: 1px solid #ddd;">
                                        ${getAddonNames(item.addonIds).length ? getAddonNames(item.addonIds).join(', ') : 'No addons'}
                                    </td>
                                    <td style="padding: 8px; border: 1px solid #ddd;">${item.quantity}</td>
                                    <td style="padding: 8px; border: 1px solid #ddd;">$${formatPrice(item.totalPrice)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" style="text-align: right; padding: 8px; border: 1px solid #ddd;"><strong>Total</strong></td>
                                <td style="padding: 8px; border: 1px solid #ddd;">$${formatPrice(currentOrder.value.totalValue)}</td>
                            </tr>
                        </tfoot>
                    </table>
                    
                    ${Object.keys(currentOrder.value.userData || {}).length > 0 ? `
                        <h2>Additional Information</h2>
                        <div style="border: 1px solid #ddd; padding: 10px; margin-bottom: 20px;">
                            ${Object.entries(currentOrder.value.userData).map(([key, value]) => `
                                <div style="margin-bottom: 5px;">
                                    <strong>${formatKey(key)}:</strong> ${formatValue(value)}
                                </div>
                            `).join('')}
                        </div>
                    ` : ''}
                `;
                
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                        <head>
                            <title>Order #${formatOrderID(currentOrder.value.orderID)}</title>
                            <style>
                                body { font-family: Arial, sans-serif; margin: 30px; }
                                h1 { margin-bottom: 20px; }
                                table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                                th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
                                th { background-color: #f2f2f2; }
                            </style>
                        </head>
                        <body>
                            ${printContent.innerHTML}
                        </body>
                    </html>
                `);
                
                printWindow.document.close();
                printWindow.focus();
                
                // Wait for content to load
                setTimeout(() => {
                    printWindow.print();
                    printWindow.close();
                }, 250);
            }
            
            function updateOrderStatus(orderID) {
                currentOrderId.value = orderID;
                
                // Set initial status based on current order status
                const orderGroup = groupedOrders.value.find(group => group.orderID === orderID);
                if (orderGroup) {
                    newStatus.value = orderGroup.status;
                }
                
                showStatusModal.value = true;
            }
            
            function saveOrderStatus() {
                loading.value = true;
                
                fetch(`${ordersData.apiUrl}update-order-status`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ordersData.nonce
                    },
                    body: JSON.stringify({
                        orderID: currentOrderId.value,
                        status: newStatus.value
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showStatusModal.value = false;
                        fetchOrders();
                    } else {
                        console.error('Error updating order status:', data.message);
                    }
                    loading.value = false;
                })
                .catch(error => {
                    console.error('Error updating order status:', error);
                    loading.value = false;
                });
            }
            
            function confirmDelete(orderID) {
                currentOrderId.value = orderID;
                showDeleteModal.value = true;
            }
            
            function deleteOrder() {
                loading.value = true;
                
                fetch(`${ordersData.apiUrl}delete-order`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ordersData.nonce
                    },
                    body: JSON.stringify({
                        orderID: currentOrderId.value
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showDeleteModal.value = false;
                        selectedOrders.value = selectedOrders.value.filter(id => id !== currentOrderId.value);
                        fetchOrders();
                    } else {
                        console.error('Error deleting order:', data.message);
                    }
                    loading.value = false;
                })
                .catch(error => {
                    console.error('Error deleting order:', error);
                    loading.value = false;
                });
            }
            
            function applyBulkAction() {
                if (!bulkAction.value || selectedOrders.value.length === 0) return;
                
                loading.value = true;
                
                let endpoint = '';
                let bodyData = { orderIDs: selectedOrders.value };
                
                if (bulkAction.value === 'delete') {
                    endpoint = 'delete-orders-bulk';
                } else if (bulkAction.value === 'update-status') {
                    endpoint = 'update-orders-status-bulk';
                    bodyData.status = bulkStatus.value;
                }
                
                fetch(`${ordersData.apiUrl}${endpoint}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ordersData.nonce
                    },
                    body: JSON.stringify(bodyData)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        selectedOrders.value = [];
                        bulkAction.value = '';
                        fetchOrders();
                    } else {
                        console.error('Error applying bulk action:', data.message);
                    }
                    loading.value = false;
                })
                .catch(error => {
                    console.error('Error applying bulk action:', error);
                    loading.value = false;
                });
            }
            
            // Helper functions
            function formatOrderID(orderID) {
                return orderID ? orderID.substr(0, 8) : 'N/A';
            }
            
            function formatDate(dateString) {
                if (!dateString) return 'N/A';
                
                const date = new Date(dateString);
                return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
            }
            
            function formatPrice(price) {
                return parseFloat(price).toFixed(2);
            }
            
            function capitalizeFirst(string) {
                if (!string) return '';
                return string.charAt(0).toUpperCase() + string.slice(1);
            }
            
            function formatKey(key) {
                if (!key) return '';
                
                // Handle special keys
                if (key === 'dineInTakeOutDelivery') return 'Service Type';
                
                // Convert camelCase to Title Case
                return key
                    .replace(/([A-Z])/g, ' $1')
                    .replace(/^./, str => str.toUpperCase());
            }
            
            function formatValue(value) {
                if (value === null || value === undefined) return '';
                
                if (typeof value === 'object') {
                    return JSON.stringify(value);
                }
                
                // Handle boolean values
                if (value === true) return 'Yes';
                if (value === false) return 'No';
                
                // Parse numeric values for dineInTakeOutDelivery
                if (!isNaN(value) && ['0', '1', '2'].includes(value.toString())) {
                    const options = ['Dine In', 'Take Out', 'Delivery'];
                    return options[parseInt(value)] || value;
                }
                
                return value.toString();
            }
            
            function getUserName(userId) {
                return users.value[userId] || `User #${userId}`;
            }
            
            function getFeatureName(featureId) {
                const feature = features.value.find(f => f.id == featureId);
                return feature ? feature.featureName : `Feature #${featureId}`;
            }
            
            function getOptionName(optionId) {
                const option = options.value.find(o => o.id == optionId);
                return option ? option.optionName : `Option #${optionId}`;
            }
            
            function getAddonNames(addonIds) {
                if (!addonIds || !Array.isArray(JSON.parse(addonIds))) return [];
                
                return JSON.parse(addonIds).map(id => {
                    const addon = addons.value.find(a => a.id == id);
                    return addon ? addon.addonName : `Addon #${id}`;
                });
            }
            
            // Lifecycle hooks
            onMounted(() => {
                fetchLookupData();
                fetchOrders();
            });
            
            // Return exposed state and methods
            return {
                loading,
                orders,
                features,
                options,
                addons,
                users,
                expandedOrders,
                selectedOrders,
                groupedOrders,
                pagination,
                sortField,
                sortDirection,
                filters,
                showDetailModal,
                showStatusModal,
                showDeleteModal,
                currentOrder,
                currentOrderId,
                newStatus,
                bulkAction,
                bulkStatus,
                allSelected,
                
                // Methods
                fetchOrders,
                toggleExpand,
                toggleSelectAll,
                handleOrderSelection,
                resetFilters,
                handleSearchInput,
                changePage,
                sortBy,
                getSortClass,
                viewOrderDetails,
                updateOrderStatus,
                saveOrderStatus,
                confirmDelete,
                deleteOrder,
                applyBulkAction,
                printOrder,
                
                // Helper functions
                formatOrderID,
                formatDate,
                formatPrice,
                capitalizeFirst,
                getUserName,
                getFeatureName,
                getOptionName,
                getAddonNames,
                formatKey,
                formatValue
            };
        }
    }).mount('#ffc-orders-app');
}