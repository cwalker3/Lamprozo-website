// plugin/assets/js/orders.js

/**
 * Orders Management JavaScript
 */

// Use window property (not `var`/`let`) so re-evaluation of this script never throws "already declared"
window.isPWA = !!document.getElementById('app-root');

document.addEventListener('DOMContentLoaded', function() {
    // Non-PWA (standard /order-history page) auto-mounts. In PWA mode, app.js calls initOrdersApp() after injecting markup.
    if (typeof Vue !== 'undefined' && document.getElementById('ffc-orders-app') && !window.isPWA) {
        initOrdersApp();
    }
});

function initOrdersApp() {
    const { createApp, ref, computed, onMounted, watch } = Vue;

    // In PWA mode, ordersData is not localized by wp_localize_script — synthesize it from window globals set by app.js
    if (typeof ordersData === 'undefined' || !ordersData) {
        ordersData = {
            nonce: window.nonce,
            apiUrl: window.api_url,
            auth_id: window.auth_id,
            currentUserIsAdmin: false,
            onlinePaymentsEnabled: '1'
        };
    }

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
            const showRefundModal = ref(false);
            const newStatus = ref('pending');
            
            // Sorting
            const sortField = ref('createdAt');
            const sortDirection = ref('desc');
            
            // Bulk actions
            const bulkAction = ref('');
            const bulkStatus = ref('pending');

            // Admin check
            const currentUserIdAdmin = ref(ordersData.currentUserIsAdmin || false);

            // State for tracking item refunds
            const itemToRefund = ref(null);
            const showItemRefundModal = ref(false);

            // Online payments toggle state
            const onlinePaymentsEnabled = ref(ordersData.onlinePaymentsEnabled === '1' || ordersData.onlinePaymentsEnabled === true);
            const toggleMessage = ref('');
            const toggleMessageType = ref('success');
            
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
                        userData: order.userData ? JSON.parse(order.userData) : {},
                        hasPartialRefund: false,
                        refundedAmount: 0,
                        anonUserEmail: order.anonUserEmail || null,
                        anonUserFirstName: order.anonUserFirstName || null,
                        anonUserLastName: order.anonUserLastName || null
                    };
                }
                
                groups[order.orderID].items.push(order);
                groups[order.orderID].totalValue += parseFloat(order.totalPrice) || 0;
                
                // ONLY use the database refundAmount field - ignore item status for refund calculation
                const dbRefundAmount = parseFloat(order.refundAmount) || 0;
                if (dbRefundAmount > 0) {
                    // For orders with multiple items, take the maximum refundAmount (should be same across items in same order)
                    groups[order.orderID].refundedAmount = Math.max(groups[order.orderID].refundedAmount, dbRefundAmount);
                }
            });
            
            // Determine overall status for each group based on actual refund amounts
            Object.values(groups).forEach(group => {
                const totalPrice = group.totalValue;
                const refundedAmount = group.refundedAmount;
                
                if (refundedAmount === 0) {
                    // No refund - use the most common item status
                    const statuses = group.items.map(item => item.status);
                    group.status = statuses[0]; // Use first item's status as default
                } else if (Math.abs(refundedAmount - totalPrice) < 0.01) { // Account for rounding
                    // Full refund
                    group.status = 'refunded';
                    group.hasPartialRefund = false;
                } else {
                    // Partial refund
                    group.status = 'partial';
                    group.hasPartialRefund = true;
                }
            });
            
            return Object.values(groups);
        });
            
            const allSelected = computed(() => {
                return groupedOrders.value.length > 0 && selectedOrders.value.length === groupedOrders.value.length;
            });
            
            // Methods
            async function fetchOrders() {
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

                // Prepare request
                const url = `${ordersData.apiUrl}get-orders?${queryParams.toString()}&auth_id=${ordersData.auth_id}`;
                const options = {
                    headers: {
                        'Content-Type': 'application/json',
                    }
                };

                try {
                    const response = await fetch(url, options);

                    // If we got a 403 specifically, handle it first
                    if (response.status === 403) {
                        console.error('You\'re not authorized.');
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

                    orders.value = data.orders;
                    pagination.value.totalItems = data.total;
                    pagination.value.totalPages = Math.ceil(data.total / pagination.value.perPage);
                    
                    // If current page is greater than total pages, go to last page
                    if (pagination.value.currentPage > pagination.value.totalPages && pagination.value.totalPages > 0) {
                        changePage(pagination.value.totalPages);
                        return;
                    }
                    loading.value = false;
                }
                catch (error) {
                    console.error('Error fetching orders:', error);
                    loading.value = false;
                }
            }
            
            function fetchLookupData() {
                // Fetch features, options, addons, and users in parallel
                Promise.all([
                    fetch(`${ordersData.apiUrl}get-features/?auth_id=${ordersData.auth_id}`, {
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    }).then(response => response.json()),
                    
                    fetch(`${ordersData.apiUrl}get-options/?auth_id=${ordersData.auth_id}`, {
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    }).then(response => response.json()),
                    
                    fetch(`${ordersData.apiUrl}get-addons/?auth_id=${ordersData.auth_id}`, {
                        headers: {
                            'Content-Type': 'application/json'
                        }
                    }).then(response => response.json()),
                    
                    fetch(`${ordersData.apiUrl}get-users/?auth_id=${ordersData.auth_id}`, {
                        headers: {
                            'Content-Type': 'application/json'
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
            
            function getActualOrderValue(group) {
                let total = 0;
                group.items.forEach(item => {
                    // Only add to total if item is not already refunded
                    if (item.status !== 'refunded') {
                        total += parseFloat(item.totalPrice) || 0;
                    }
                });
                return total;
            }

            // Calculate actual order value
            function getActualOrderValue(group) {
                let total = 0;
                group.items.forEach(item => {
                    // Only add to total if item is not already refunded
                    if (item.status !== 'refunded') {
                        total += parseFloat(item.totalPrice) || 0;
                    }
                });
                return total;
            }

            // Confirm refunding a single item
            function confirmItemRefund(orderID, itemId) {
                currentOrderId.value = orderID;
                itemToRefund.value = itemId;
                showItemRefundModal.value = true;
            }

            // Refund a single item
            async function refundItem() {
                showItemRefundModal.value = false;
                
                try {
                    loading.value = true;
                    
                    const response = await fetch(`${ordersData.apiUrl}refund-payment`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            orderID: currentOrderId.value,
                            itemId: itemToRefund.value,  // Send specific item ID
                            auth_id: ordersData.auth_id
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update the local state without full reload
                        updateRefundedItems(currentOrderId.value, data.refunded_item_ids);
                        
                        alert(data.message || 'Item refunded successfully');
                    } else {
                        alert(data.message || 'Failed to refund item');
                    }
                } catch (error) {
                    console.error('Refund error:', error);
                    alert('An error occurred while refunding the item');
                } finally {
                    loading.value = false;
                    itemToRefund.value = null;
                }
            }

            // Update UI after successful refund
            function updateRefundedItems(orderID, refundedItemIds) {
                // Update the orders array
                orders.value.forEach(order => {
                    if (order.orderID === orderID && refundedItemIds.includes(order.id)) {
                        order.status = 'refunded';
                    }
                });
                
                // Force Vue to re-compute groupedOrders
                orders.value = [...orders.value];
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
                // Build itemized rows
                let itemsHtml = '';
                this.currentOrder.items.forEach((item, itemIndex) => {
                    const pricing = this.getItemizedPricing(item);

                    pricing.lines.forEach((line) => {
                    let rowClass = '';
                    let rowStyle = 'padding: 8px; border: 1px solid #ddd;';

                    if (line.isBase) {
                        rowClass = 'base-item';
                        rowStyle += ' font-weight: bold;';
                    } else if (line.isAddon) {
                        rowClass = 'addon-item';
                        rowStyle += ' font-size: 0.95em; padding-left: 20px;';
                    } else if (line.isDiscount) {
                        rowClass = 'discount-item';
                        rowStyle +=
                        ' font-size: 0.85em; font-style: italic; color: #0066cc; padding-left: 30px;';
                    }

                    itemsHtml += `
                        <tr class="${rowClass}">
                        <td style="${rowStyle}">
                            ${line.isAddon ? '+ ' : ''}${line.name}
                        </td>
                        <td style="${rowStyle} text-align: center;">
                            ${line.quantity || ''}
                        </td>
                        <td style="${rowStyle} text-align: right;">
                            ${this.formatMoney(line.unitPrice)}
                        </td>
                        <td style="${rowStyle} text-align: right;">
                            ${this.formatMoney(line.totalPrice)}
                        </td>
                        </tr>
                    `;
                    });

                    // separator between items
                    if (itemIndex < this.currentOrder.items.length - 1) {
                    itemsHtml += `
                        <tr class="item-separator">
                        <td colspan="4" style="border: none; padding: 10px 0;"></td>
                        </tr>
                    `;
                    }
                });

                // Build full print content
                const printContent = document.createElement('div');
                printContent.innerHTML = `
                    <h1>Order #${this.formatOrderID(this.currentOrder.orderID)}</h1>
                    <div style="margin-bottom: 20px;">
                    <div><strong>Customer:</strong> ${this.getUserName(
                        this.currentOrder.userId,
                        this.currentOrder.anonUserEmail,
                        this.currentOrder.anonUserFirstName,
                        this.currentOrder.anonUserLastName
                    )}</div>
                    <div><strong>Date:</strong> ${this.formatDate(this.currentOrder.createdAt)}</div>
                    <div><strong>Status:</strong> ${this.capitalizeFirst(this.currentOrder.status)}</div>
                    </div>

                    <h2>Order Items</h2>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr>
                        <th style="text-align: left; padding: 8px; border: 1px solid #ddd; background-color: #f2f2f2;">Item</th>
                        <th style="text-align: center; padding: 8px; border: 1px solid #ddd; background-color: #f2f2f2;">Quantity</th>
                        <th style="text-align: right; padding: 8px; border: 1px solid #ddd; background-color: #f2f2f2;">Unit Price</th>
                        <th style="text-align: right; padding: 8px; border: 1px solid #ddd; background-color: #f2f2f2;">Total Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                    <tfoot>
                        <tr>
                        <td colspan="3" style="text-align: right; padding: 8px; border: 1px solid #ddd; font-weight: bold;">
                            Total
                        </td>
                        <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-weight: bold;">
                            ${this.formatMoney(this.currentOrder.totalValue)}
                        </td>
                        </tr>
                    </tfoot>
                    </table>

                    ${Object.keys(this.currentOrder.userData || {}).length > 0
                    ? `<h2>Additional Information</h2>
                        <div style="border: 1px solid #ddd; padding: 10px; margin-bottom: 20px;">
                        ${Object.entries(this.currentOrder.userData)
                            .map(
                            ([key, value]) => `
                            <div style="margin-bottom: 5px;">
                            <strong>${this.formatKey(key)}:</strong> ${this.formatValue(value)}
                            </div>
                        `
                            )
                            .join('')}
                        </div>`
                    : ''
                    }
                `;

                // Open print window
                const printWindow = window.open('', '_blank');
                printWindow.document.write(`
                    <html>
                    <head>
                        <title>Order #${this.formatOrderID(this.currentOrder.orderID)}</title>
                        <style>
                        body { font-family: Arial; margin: 30px; }
                        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
                        th { background-color: #f2f2f2; }
                        .discount-item { color: #0066cc; font-style: italic; }
                        .addon-item { font-size: 0.95em; }
                        </style>
                    </head>
                    <body>
                        ${printContent.innerHTML}
                    </body>
                    </html>
                `);
                printWindow.document.close();
                printWindow.focus();

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
                        'Content-Type': 'application/json'
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
                        'Content-Type': 'application/json'
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

            // Confirm refunding a single item
            function confirmItemRefund(orderID, itemId) {
                currentOrderId.value = orderID;
                itemToRefund.value = itemId;
                showItemRefundModal.value = true;
            }

            // Refund a single item
            async function refundItem() {
                showItemRefundModal.value = false;
                
                try {
                    loading.value = true;
                    
                    const response = await fetch(`${ordersData.apiUrl}refund-payment`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            orderID: currentOrderId.value,
                            itemId: itemToRefund.value,  // Send specific item ID
                            auth_id: ordersData.auth_id
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        // Update the local state without full reload
                        updateRefundedItems(currentOrderId.value, data.refunded_item_ids);
                        
                        alert(data.message || 'Item refunded successfully');
                    } else {
                        alert(data.message || 'Failed to refund item');
                    }
                } catch (error) {
                    console.error('Refund error:', error);
                    alert('An error occurred while refunding the item');
                } finally {
                    loading.value = false;
                    itemToRefund.value = null;
                }
            }

            // Update UI after successful refund
            function updateRefundedItems(orderID, refundedItemIds) {
                // Update the orders array
                orders.value.forEach(order => {
                    if (order.orderID === orderID && refundedItemIds.includes(parseInt(order.id))) {
                        order.status = 'refunded';
                    }
                });
                
                // Force Vue to re-compute groupedOrders
                orders.value = [...orders.value];
            }

            function confirmRefund(orderID) {
                currentOrderId.value = orderID;
                showRefundModal.value = true;
            }
            
            async function refundOrder() {
                showRefundModal.value = false;
                
                try {
                    // First, check what's actually left to refund
                    const orderGroup = groupedOrders.value.find(g => g.orderID === currentOrderId.value);
                    if (!orderGroup) {
                        alert('Order not found');
                        return;
                    }
                    
                    // Calculate what's already been refunded
                    const alreadyRefunded = orderGroup.items
                        .filter(item => item.status === 'refunded')
                        .reduce((sum, item) => sum + parseFloat(item.totalPrice), 0);
                    
                    const remainingToRefund = orderGroup.totalValue - alreadyRefunded;
                    
                    if (remainingToRefund <= 0) {
                        alert('This order has already been fully refunded.');
                        return;
                    }
                    
                    // Confirm the partial refund amount if needed
                    if (alreadyRefunded > 0) {
                        const confirmPartial = confirm(`This order has already been partially refunded ($${alreadyRefunded.toFixed(2)}). Do you want to refund the remaining $${remainingToRefund.toFixed(2)}?`);
                        if (!confirmPartial) return;
                    }
                    
                    loading.value = true;
                    
                    const response = await fetch(`${ordersData.apiUrl}refund-payment`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            orderID: currentOrderId.value,
                            auth_id: ordersData.auth_id
                        })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        alert(data.message || 'Order refunded successfully');
                        fetchOrders();
                    } else {
                        alert(data.message || 'Failed to refund order');
                    }
                } catch (error) {
                    console.error('Refund error:', error);
                    alert('An error occurred while refunding the order');
                } finally {
                    loading.value = false;
                }
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
                        'Content-Type': 'application/json'
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
            
            function formatMoney(price) {
                const n = parseFloat(price);
                if (isNaN(n)) return '';
                return n < 0
                ? '-$' + Math.abs(n).toFixed(2)
                :  '$' +       n.toFixed(2);
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

            function hasDiscounts(order) {
                return order.items.some(item => 
                    parseFloat(item.totalPriceDiscount) > 0
                );
            }

            /**
             * Calculate itemized pricing breakdown for an order item
             */
            function getItemizedPricing(item) {
                const result = {
                    lines: [],
                    subtotal: 0
                };

                // 1) Base option info + price
                const option  = options.value.find(o => o.id == item.optionId);
                const feature = features.value.find(f => f.id == item.featureId);
                if (!option || !feature) return result;

                // 2) Parse discount info
                let discountInfo = {};
                try {
                    discountInfo = typeof item.priceDiscountsInfo === 'string'
                    ? JSON.parse(item.priceDiscountsInfo)
                    : (item.priceDiscountsInfo || {});
                } catch (e) {
                    console.warn('Error parsing discount info:', e);
                }

                // 3) Compute basePrice
                let basePrice = parseFloat(item.totalPrice) + parseFloat(item.totalPriceDiscount || 0);

                // subtract all add-on costs
                const addonIds = JSON.parse(item.addonIds || '[]');
                addonIds.forEach(addonId => {
                    const a = addons.value.find(x => x.id == addonId);
                    if (a) {
                    basePrice -= parseFloat(a.staticPriceMod || 0) * item.quantity;
                    }
                });

                // 4) Push base option row
                result.lines.push({
                    type: 'option',
                    name: `${feature.featureName} - ${option.optionName}`,
                    quantity: item.quantity,
                    unitPrice: basePrice / item.quantity,
                    totalPrice: basePrice,
                    isBase: true
                });
                result.subtotal += basePrice;

                // 5) Option-level discount?
                if (discountInfo.option?.trim()) {
                    result.lines.push({
                    type: 'discount',
                    name: discountInfo.option,
                    quantity: null,
                    unitPrice: null,
                    totalPrice: null,
                    isDiscount: true,
                    parentType: 'option'
                    });
                }

                // 6) Add individual addons, _collecting_ their discounts_
                const collectedAddonDiscounts = [];
                addonIds.forEach((addonId, idx) => {
                    const a = addons.value.find(x => x.id == addonId);
                    if (!a) return;

                    const unitPrice  = parseFloat(a.staticPriceMod || 0);
                    const totalPrice = unitPrice * item.quantity;

                    // a) the addon row
                    result.lines.push({
                    type: 'addon',
                    name: a.addonName,
                    quantity: item.quantity,
                    unitPrice,
                    totalPrice,
                    isAddon: true
                    });
                    result.subtotal += totalPrice;

                    // b) collect its discount text for later
                    const txt = discountInfo.addons?.[idx]?.trim();
                    if (txt) {
                    collectedAddonDiscounts.push({
                        type: 'discount',
                        name: txt,
                        quantity: null,
                        unitPrice: null,
                        totalPrice: null,
                        isDiscount: true,
                        parentType: 'addon'
                    });
                    }
                });

                // 7) Append _all_ addon discounts at the end
                collectedAddonDiscounts.forEach(line => result.lines.push(line));

                // 8) Finally, the item‐level discount (negative totalPrice)
                if (parseFloat(item.totalPriceDiscount) > 0) {
                    const amt = -parseFloat(item.totalPriceDiscount);
                    result.lines.push({
                    type: 'discount',
                    name: 'Item Discount',
                    quantity: null,
                    unitPrice: null,
                    totalPrice: amt,
                    isDiscount: true,
                    parentType: 'item'
                    });
                    result.subtotal += amt;
                }

                return result;
            }

            function getTotalDiscount(order) {
                return order.items.reduce((total, item) => 
                    total + parseFloat(item.totalPriceDiscount || 0), 0
                );
            }

            function getDiscountDescriptions(order) {
                const descriptions = [];
                
                order.items.forEach(item => {
                    if (item.priceDiscountsInfo) {
                        try {
                            const info = typeof item.priceDiscountsInfo === 'string' 
                                ? JSON.parse(item.priceDiscountsInfo) 
                                : item.priceDiscountsInfo;
                            
                            if (info.option && info.option.trim()) {
                                descriptions.push(info.option);
                            }
                            
                            if (info.addons && Array.isArray(info.addons)) {
                                info.addons.forEach(addon => {
                                    if (addon && addon.trim()) {
                                        descriptions.push(addon);
                                    }
                                });
                            }
                        } catch (e) {
                            console.error('Error parsing discount info:', e);
                        }
                    }
                });
                
                return [...new Set(descriptions)];
            }
            
            function getUserName(userId, anonUserEmail = null, anonUserFirstName = null, anonUserLastName = null) {
                // Convert userId to number for comparison, or check both string and number
                if ((userId === 0 || userId === "0") && anonUserEmail) {
                    // Check if we have both first and last name
                    if (anonUserFirstName && anonUserLastName) {
                        return `${anonUserFirstName} ${anonUserLastName} (${anonUserEmail})`;
                    }
                    // Otherwise just return the email
                    return anonUserEmail;
                }
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

            // Toggle online payments
            async function toggleOnlinePayments() {
                try {
                    const response = await fetch(`${ordersData.apiUrl}toggle-online-payments`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            enabled: onlinePaymentsEnabled.value,
                            auth_id: ordersData.auth_id
                        })
                    });

                    const data = await response.json();

                    if (data.success) {
                        toggleMessageType.value = 'success';
                        toggleMessage.value = data.message || 'Setting updated successfully';

                        // Clear message after 3 seconds
                        setTimeout(() => {
                            toggleMessage.value = '';
                        }, 3000);
                    } else {
                        toggleMessageType.value = 'error';
                        toggleMessage.value = data.message || 'Failed to update setting';

                        // Revert toggle on error
                        onlinePaymentsEnabled.value = !onlinePaymentsEnabled.value;
                    }
                } catch (error) {
                    console.error('Error toggling online payments:', error);
                    toggleMessageType.value = 'error';
                    toggleMessage.value = 'An error occurred while updating the setting';

                    // Revert toggle on error
                    onlinePaymentsEnabled.value = !onlinePaymentsEnabled.value;
                }
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
                showRefundModal,
                currentOrder,
                currentOrderId,
                newStatus,
                bulkAction,
                bulkStatus,
                allSelected,
                currentUserIdAdmin,
                itemToRefund,
                showItemRefundModal,
                onlinePaymentsEnabled,
                toggleMessage,
                toggleMessageType,

                // Methods
                fetchOrders,
                toggleOnlinePayments,
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
                confirmRefund,
                deleteOrder,
                refundOrder,
                applyBulkAction,
                printOrder,
                getActualOrderValue,
                confirmItemRefund,
                refundItem,
                updateRefundedItems,
                
                // Helper functions
                formatOrderID,
                formatDate,
                formatMoney,
                capitalizeFirst,
                getUserName,
                getFeatureName,
                getOptionName,
                getAddonNames,
                formatKey,
                formatValue,
                hasDiscounts,
                getTotalDiscount,
                getDiscountDescriptions,
                getItemizedPricing,

                /**
                 * Parse the item.addonIds JSON into an array of numeric IDs.
                 */
                getAddonIds(item) {
                    try {
                        return JSON.parse(item.addonIds || '[]');
                    } catch {
                        return [];
                    }
                },

                /**
                 * Return true if the “option” field in priceDiscountsInfo is non‐empty.
                 */
                hasOptionDiscount(item) {
                    if (!item.priceDiscountsInfo) return false;
                    try {
                        const info = typeof item.priceDiscountsInfo === 'string'
                        ? JSON.parse(item.priceDiscountsInfo)
                        : item.priceDiscountsInfo;
                        return !!(info.option && info.option.trim());
                    } catch {
                        return false;
                    }
                },

                /**
                 * Return the option‐level discount text (e.g. "10% off Cheese upgrade").
                 */
                getOptionDiscountText(item) {
                    if (!item.priceDiscountsInfo) return '';
                    try {
                        const info = typeof item.priceDiscountsInfo === 'string'
                        ? JSON.parse(item.priceDiscountsInfo)
                        : item.priceDiscountsInfo;
                        return info.option || '';
                    } catch {
                        return '';
                    }
                },

                /**
                 * Return true if the addon at index `idx` has a non‐empty discount string.
                 */
                hasAddonDiscount(item, idx) {
                    if (!item.priceDiscountsInfo) return false;
                    try {
                        const info = typeof item.priceDiscountsInfo === 'string'
                        ? JSON.parse(item.priceDiscountsInfo)
                        : item.priceDiscountsInfo;
                        return Array.isArray(info.addons) && !!(info.addons[idx] && info.addons[idx].trim());
                    } catch {
                        return false;
                    }
                },

                /**
                 * Return the discount text for the addon at index `idx` (e.g. "5% off Pepperoni").
                 */
                getAddonDiscountText(item, idx) {
                    if (!item.priceDiscountsInfo) return '';
                    try {
                        const info = typeof item.priceDiscountsInfo === 'string'
                        ? JSON.parse(item.priceDiscountsInfo)
                        : item.priceDiscountsInfo;
                        return info.addons[idx] || '';
                    } catch {
                        return '';
                    }
                },

                /**
                 * Return the display name for a given numeric addonId.
                 */
                getAddonName(addonId) {
                    const addon = addons.value.find(a => a.id == addonId);
                    return addon ? addon.addonName : `Addon #${addonId}`;
                },

                getAllAddonDiscounts(item) {
                    if (!item.priceDiscountsInfo) {
                        return [];
                    }
                    try {
                        const info = typeof item.priceDiscountsInfo === 'string'
                            ? JSON.parse(item.priceDiscountsInfo)
                            : item.priceDiscountsInfo;
                        // info.addons should be an array of strings.
                        if (Array.isArray(info.addons)) {
                            // Filter out any empty or whitespace-only entries:
                            return info.addons.filter(d => typeof d === 'string' && d.trim() !== '');
                        }
                        return [];
                    } catch {
                        return [];
                    }
                },

                /**
                 * (Optional helper, only if you want to centralize parsing logic.)
                 * Example usage inside getAllAddonDiscounts; not strictly required if
                 * you put the parsing inline, but shown here for clarity.
                 */
                extractAddonDiscountsFromInfo(item) {
                    try {
                    const info = typeof item.priceDiscountsInfo === 'string'
                        ? JSON.parse(item.priceDiscountsInfo)
                        : item.priceDiscountsInfo;
                    return Array.isArray(info.addons)
                        ? info.addons.filter(d => typeof d === 'string' && d.trim() !== '')
                        : [];
                    } catch {
                    return [];
                    }
                }

                
            };
        }
    }).mount('#ffc-orders-app');
}