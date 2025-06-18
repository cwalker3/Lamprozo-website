<!-- plugin/views/orders.php -->

<?php

    global $currentUserIdAdmin;

?>

<div class="wrap" id="ffc-orders-app" v-cloak>
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <!-- Loading State -->
    <div v-if="loading" class="ffc-loading">
        <div class="spinner is-active"></div>
        <p>Loading orders...</p>
    </div>
    
    <!-- Filters Section -->
    <?php if ($currentUserIdAdmin): ?>
    <div class="ffc-filters-container">
        <div class="ffc-filters">
            <div class="ffc-filter-item">
                <label for="status-filter">Status:</label>
                <select id="status-filter" v-model="filters.status" @change="fetchOrders()">
                    <option value="">All</option>
                    <option value="pending">Pending</option>
                    <option value="completed">Completed</option>
                    <option value="paid">Paid</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="refunded">Refunded</option>
                </select>
            </div>
            
            <div class="ffc-filter-item">
                <label for="date-from">Date From:</label>
                <input type="date" id="date-from" v-model="filters.dateFrom" @change="fetchOrders()">
            </div>
            
            <div class="ffc-filter-item">
                <label for="date-to">Date To:</label>
                <input type="date" id="date-to" v-model="filters.dateTo" @change="fetchOrders()">
            </div>
            
            <div class="ffc-filter-item">
                <label for="search">Search:</label>
                <input type="text" id="search" v-model="filters.search" @input="handleSearchInput" placeholder="Order ID, User Name...">
            </div>
            
            <button class="button" @click="resetFilters()">Reset Filters</button>
        </div>
        
        <!-- Bulk Actions -->
        <div class="ffc-bulk-actions" v-if="selectedOrders.length > 0">
            <span>{{ selectedOrders.length }} order(s) selected</span>
            <select v-model="bulkAction">
                <option value="">Bulk Actions</option>
                <option value="delete">Delete</option>
                <option value="update-status">Update Status</option>
            </select>
            <select v-if="bulkAction === 'update-status'" v-model="bulkStatus">
                <option value="pending">Pending</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <button class="button" @click="applyBulkAction" :disabled="!bulkAction">Apply</button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Orders Table -->
    <div class="ffc-table-container" v-if="!loading && orders.length > 0">
        <table class="wp-list-table widefat fixed striped ffc-orders-table">
            <thead>
                <tr>

                    <?php if ($currentUserIdAdmin): ?>
                    <th class="check-column">
                        <input type="checkbox" @change="toggleSelectAll" :checked="allSelected">
                    </th>
                    <?php endif; ?>

                    <th @click="sortBy('orderID')" :class="getSortClass('orderID')">Order ID</th>
                    <th @click="sortBy('userId')" :class="getSortClass('userId')">Customer</th>
                    <th>Items</th>
                    <th @click="sortBy('totalPrice')" :class="getSortClass('totalPrice')">Total Price</th>
                    <th @click="sortBy('status')" :class="getSortClass('status')">Status</th>
                    <th @click="sortBy('createdAt')" :class="getSortClass('createdAt')">Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="(group, groupIndex) in groupedOrders" :key="'order-' + groupIndex">
                    <tr :class="{'ffc-order-row': true, 'expanded': expandedOrders.includes(group.orderID)}">
                        
                    <?php if ($currentUserIdAdmin): ?>
                        <td>
                            <input type="checkbox" :value="group.orderID" 
                                v-model="selectedOrders" @change="handleOrderSelection">
                        </td>
                        <?php endif; ?>

                        <td>{{ formatOrderID(group.orderID) }}</td>
                        <td>{{ getUserName(group.userId) }}</td>
                        <td>
                            {{ group.items.length }} item(s)
                            <button class="ffc-expand-btn" @click="toggleExpand(group.orderID)">
                                {{ expandedOrders.includes(group.orderID) ? '▼' : '►' }}
                            </button>
                        </td>
                        <td>
                            <span v-if="!group.hasPartialRefund">
                                ${{ formatPrice(group.totalValue) }}
                            </span>
                            <span v-else>
                                ${{ formatPrice(getActualOrderValue(group)) }}
                                <span class="ffc-original-price">
                                    (was ${{ formatPrice(group.totalValue) }})
                                </span>
                            </span>
                        </td>
                        <td>
                            <span class="ffc-status-badge" :class="'ffc-status-' + group.status">
                                <template v-if="group.status === 'partial'">
                                    Mixed
                                </template>
                                <template v-else>
                                    {{ capitalizeFirst(group.status) }}
                                </template>
                            </span>
                        </td>
                        <td>{{ formatDate(group.createdAt) }}</td>
                        <td class="ffc-actions-cell">
                            <div class="ffc-row-actions">
                                <button class="button button-small" @click="viewOrderDetails(group)">View</button>

                                <?php if ($currentUserIdAdmin): ?>
                                <button class="button button-small" @click="updateOrderStatus(group.orderID)">Status</button>
                                <button class="button button-small button-link-delete" @click="confirmDelete(group.orderID)">Delete</button>
                                <?php endif; ?>
                                <button
                                    class="button button-small button-link-delete"
                                    @click="confirmRefund(group.orderID)"
                                    :disabled="group.status === 'refunded' || (group.totalValue - group.refundedAmount) <= 0"
                                    :title="group.hasPartialRefund ? `Refund remaining $${formatPrice(group.totalValue - group.refundedAmount)}` : 'Refund order'"
                                >
                                    {{ group.hasPartialRefund ? 'Refund Rest' : 'Refund' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                    <!-- Expanded Order Items -->
                    <tr v-if="expandedOrders.includes(group.orderID)" class="ffc-order-details-row">
                        <td colspan="8">
                            <div class="ffc-order-items">
                                <table class="ffc-items-table">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Option</th>
                                            <th>Addons</th>
                                            <th>Quantity</th>
                                            <th>Price</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="(item, itemIndex) in group.items" :key="'item-' + itemIndex">
                                            <td>{{ getFeatureName(item.featureId) }}</td>
                                            <td>
                                                {{ getOptionName(item.optionId) }}

                                                <!-- If this option got a discount, show it right below -->
                                                <div v-if="hasOptionDiscount(item)" class="ffc-discount-inline">
                                                    - {{ getOptionDiscountText(item) }}
                                                </div>
                                            </td>
                                            <td>
                                                <!-- First: show all addons as a comma-separated list (or “No addons”). -->
                                                <span v-if="getAddonNames(item.addonIds).length">
                                                    {{ getAddonNames(item.addonIds).join(', ') }}
                                                </span>
                                                <span v-else>No addons</span>

                                                <!-- Then: if there are one or more addon-discount messages, show them all on one line. -->
                                                <div v-if="getAllAddonDiscounts(item).length" class="ffc-discount-inline">
                                                    - {{ getAllAddonDiscounts(item).join(', ') }}
                                                </div>
                                            </td>
                                            <td>{{ item.quantity }}</td>
                                            <td>
                                                ${{ formatPrice(item.totalPrice) }}

                                                <!-- If this item had a “totalPriceDiscount” > 0, show it immediately below -->
                                                <div v-if="parseFloat(item.totalPriceDiscount) > 0" class="ffc-discount-inline">
                                                    - ${{ formatPrice(item.totalPriceDiscount) }}
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                
                                <div class="ffc-user-data" v-if="Object.keys(group.userData || {}).length > 0">
                                    <h4>Additional Information</h4>
                                    <div v-for="(value, key) in group.userData" :key="key" class="ffc-user-data-item">
                                        <strong>{{ formatKey(key) }}:</strong> {{ formatValue(value) }}
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <div class="ffc-pagination">
            <span class="ffc-pagination-info">
                Showing {{ (pagination.currentPage - 1) * pagination.perPage + 1 }} - 
                {{ Math.min(pagination.currentPage * pagination.perPage, pagination.totalItems) }} 
                of {{ pagination.totalItems }} orders
            </span>
            <div class="ffc-pagination-controls">
                <button class="button" :disabled="pagination.currentPage === 1" @click="changePage(1)">« First</button>
                <button class="button" :disabled="pagination.currentPage === 1" @click="changePage(pagination.currentPage - 1)">‹ Previous</button>
                <span class="ffc-pagination-pages">
                    Page {{ pagination.currentPage }} of {{ pagination.totalPages }}
                </span>
                <button class="button" :disabled="pagination.currentPage === pagination.totalPages" @click="changePage(pagination.currentPage + 1)">Next ›</button>
                <button class="button" :disabled="pagination.currentPage === pagination.totalPages" @click="changePage(pagination.totalPages)">Last »</button>
            </div>
        </div>
    </div>
    
    <!-- No Orders Message -->
    <div class="ffc-no-orders" v-if="!loading && orders.length === 0">
        <p>No orders found. Adjust your filters or try again later.</p>
    </div>
    
    <!-- Order Detail Modal -->
    <div class="ffc-modal" v-if="showDetailModal" v-cloak>
        <div class="ffc-modal-content">
            <div class="ffc-modal-header">
                <h2>Order Details</h2>
                <button class="ffc-modal-close" @click="showDetailModal = false">×</button>
            </div>
            <div class="ffc-modal-body" v-if="currentOrder">
                <div class="ffc-order-summary">
                    <div class="ffc-summary-item">
                        <strong>Order ID:</strong> {{ formatOrderID(currentOrder.orderID) }}
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Customer:</strong> {{ getUserName(currentOrder.userId) }}
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Date:</strong> {{ formatDate(currentOrder.createdAt) }}
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Status:</strong> 
                        <span class="ffc-status-badge" :class="'ffc-status-' + currentOrder.status">
                            {{ capitalizeFirst(currentOrder.status) }}
                        </span>
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Total:</strong> ${{ formatPrice(currentOrder.totalValue) }}
                    </div>
                </div>
                
                <h3>Order Items</h3>
                <table class="ffc-items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Option</th>
                            <th>Addons</th>
                            <th>Quantity</th>
                            <th>Price</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, itemIndex) in currentOrder.items" :key="'modal-item-' + itemIndex">
                            <td>{{ getFeatureName(item.featureId) }}</td>
                            <td>
                                {{ getOptionName(item.optionId) }}

                                <!-- If this option got a discount, show it right below -->
                                <div v-if="hasOptionDiscount(item)" class="ffc-discount-inline">
                                    - {{ getOptionDiscountText(item) }}
                                </div>
                            </td>
                            <td>
                                <!-- First: show all addons as a comma-separated list (or “No addons”). -->
                                <span v-if="getAddonNames(item.addonIds).length">
                                    {{ getAddonNames(item.addonIds).join(', ') }}
                                </span>
                                <span v-else>No addons</span>

                                <!-- Then: if there are one or more addon-discount messages, show them all on one line. -->
                                <div v-if="getAllAddonDiscounts(item).length" class="ffc-discount-inline">
                                    - {{ getAllAddonDiscounts(item).join(', ') }}
                                </div>
                            </td>
                            <td>{{ item.quantity }}</td>
                            <td>
                                ${{ formatPrice(item.totalPrice) }}

                                <!-- If this item had a “totalPriceDiscount” > 0, show it immediately below -->
                                <div v-if="parseFloat(item.totalPriceDiscount) > 0" class="ffc-discount-inline">
                                    - ${{ formatPrice(item.totalPriceDiscount) }}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="ffc-total-label">Subtotal</td>
                            <td>${{ formatPrice(currentOrder.totalValue) }}</td>
                        </tr>
                        <tr v-if="currentOrder.refundedAmount > 0">
                            <td colspan="4" class="ffc-total-label">Already Refunded</td>
                            <td style="color: #dc3545;">-${{ formatPrice(currentOrder.refundedAmount) }}</td>
                        </tr>
                        <tr v-if="currentOrder.refundedAmount > 0">
                            <td colspan="4" class="ffc-total-label"><strong>Current Total</strong></td>
                            <td><strong>${{ formatPrice(currentOrder.totalValue - currentOrder.refundedAmount) }}</strong></td>
                        </tr>
                        <tr v-else>
                            <td colspan="4" class="ffc-total-label"><strong>Total</strong></td>
                            <td><strong>${{ formatPrice(currentOrder.totalValue) }}</strong></td>
                        </tr>
                        <tr v-if="parseFloat(getTotalDiscount(currentOrder)) > 0">
                            <td colspan="4"></td>
                            <td class="ffc-discount-inline" style="text-align: right;">
                                - ${{ formatPrice(getTotalDiscount(currentOrder)) }} discount
                            </td>
                        </tr>
                    </tfoot>
                </table>
                
                <div class="ffc-user-data" v-if="Object.keys(currentOrder.userData || {}).length > 0">
                    <h3>Additional Information</h3>
                    <div v-for="(value, key) in currentOrder.userData" :key="'modal-data-' + key" class="ffc-user-data-item">
                        <strong>{{ formatKey(key) }}:</strong> {{ formatValue(value) }}
                    </div>
                </div>
            </div>
            <div class="ffc-modal-footer">
                <button class="button" @click="showDetailModal = false">Close</button>
                <button class="button button-primary" @click="printOrder()">Print</button>
            </div>
        </div>
    </div>
    
    <!-- Status Update Modal -->
    <div class="ffc-modal" v-if="showStatusModal" v-cloak>
        <div class="ffc-modal-content ffc-small-modal">
            <div class="ffc-modal-header">
                <h2>Update Order Status</h2>
                <button class="ffc-modal-close" @click="showStatusModal = false">×</button>
            </div>
            <div class="ffc-modal-body">
                <div class="ffc-status-form">
                    <label for="new-status">New Status:</label>
                    <select id="new-status" v-model="newStatus">
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="ffc-modal-footer">
                <button class="button" @click="showStatusModal = false">Cancel</button>
                <button class="button button-primary" @click="saveOrderStatus()">Update</button>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div class="ffc-modal" v-if="showDeleteModal" v-cloak>
        <div class="ffc-modal-content ffc-small-modal">
            <div class="ffc-modal-header">
                <h2>Confirm Deletion</h2>
                <button class="ffc-modal-close" @click="showDeleteModal = false">×</button>
            </div>
            <div class="ffc-modal-body">
                <p>Are you sure you want to DELETE this order? This action cannot be undone.</p>
            </div>
            <div class="ffc-modal-footer">
                <button class="button" @click="showDeleteModal = false">Cancel</button>
                <button class="button button-link-delete" @click="deleteOrder()">Delete</button>
            </div>
        </div>
    </div>

    <!-- Refund Confirmation Modal -->
    <div class="ffc-modal" v-if="showRefundModal" v-cloak>
        <div class="ffc-modal-content ffc-small-modal">
            <div class="ffc-modal-header">
                <h2>Confirm Refund</h2>
                <button class="ffc-modal-close" @click="showRefundModal = false">×</button>
            </div>
            <div class="ffc-modal-body">
                <p>Are you sure you want to REFUND this order? This action cannot be undone.</p>
            </div>
            <div class="ffc-modal-footer">
                <button class="button" @click="showRefundModal = false">Cancel</button>
                <button class="button button-link-delete" @click="refundOrder()">Refund</button>
            </div>
        </div>
    </div>
</div>