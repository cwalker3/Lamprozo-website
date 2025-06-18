<?php
    // plugin/views/subscriptions.php

    global $currentUserIdAdmin;
?>

<?php if ($currentUserIdAdmin): ?>
<div id="ffc-subscriptions-app" v-cloak>
    <!-- Loading State -->
    <div v-if="loading" class="ffc-loading">
        <span class="spinner is-active"></span>
        <p>Loading subscriptions...</p>
    </div>
    
    <!-- Main Content -->
    <div v-else>
        <h1>Subscriptions</h1>
        
        <!-- Filters Section -->
        <div class="ffc-filters-container">
            <div class="ffc-filters">
                <div class="ffc-filter-item">
                    <label>Status</label>
                    <select v-model="filters.status" @change="fetchSubscriptions">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="trialing">Trialing</option>
                        <option value="past_due">Past Due</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
                
                <div class="ffc-filter-item">
                    <label>Search</label>
                    <input 
                        type="text" 
                        v-model="filters.search" 
                        @input="handleSearchInput"
                        placeholder="Search subscriptions..."
                    >
                </div>
                
                <div class="ffc-filter-item" style="align-self: flex-end;">
                    <button @click="resetFilters" class="button button-secondary">
                        Reset Filters
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Subscriptions Table -->
        <div v-if="filteredSubscriptions.length > 0" class="ffc-table-container">
            <table class="ffc-subscriptions-table">
                <thead>
                    <tr>
                        <th @click="sortBy('subscription_id')" :class="getSortClass('subscription_id')">
                            Subscription ID
                        </th>
                        
                        <th @click="sortBy('userId')" :class="getSortClass('userId')">
                            Customer
                        </th>

                        <th>Items</th>
                        <th @click="sortBy('total_amount')" :class="getSortClass('total_amount')">
                            Amount
                        </th>
                        <th>Interval</th>
                        <th @click="sortBy('subscription_status')" :class="getSortClass('subscription_status')">
                            Status
                        </th>
                        <th @click="sortBy('subscription_current_period_end')" :class="getSortClass('subscription_current_period_end')">
                            Next Renewal
                        </th>
                        <th @click="sortBy('started_at')" :class="getSortClass('started_at')">
                            Started
                        </th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="subscription in paginatedSubscriptions" :key="subscription.subscription_id" class="ffc-subscription-row">
                        <td>
                            {{ formatSubscriptionId(subscription.subscription_id) }}
                        </td>
                        <td>{{ getUserName(subscription.userId) }}</td>
                        <td>
                            <div class="ffc-subscription-items">
                                <div v-for="(feature, index) in parseFeatures(subscription)" :key="index">
                                    {{ feature }}
                                </div>
                            </div>
                        </td>
                        <td>${{ formatPrice(subscription.total_amount) }}/{{ getIntervalDisplay(subscription.intervals) }}</td>
                        <td>{{ getIntervalDisplay(subscription.intervals) }}</td>
                        <td>
                            <span class="ffc-status-badge" :class="'ffc-status-' + subscription.subscription_status">
                                {{ capitalizeFirst(subscription.subscription_status) }}
                            </span>
                        </td>
                        <td>{{ formatDate(subscription.subscription_current_period_end) }}</td>
                        <td>{{ formatDate(subscription.started_at) }}</td>
                        <td class="ffc-actions-cell">
                            <div class="ffc-row-actions">
                                <button @click="viewSubscriptionDetails(subscription)" class="button button-small">
                                    View
                                </button>
                                <button 
                                    v-if="subscription.subscription_status === 'active' || subscription.subscription_status === 'trialing'"
                                    @click="confirmCancel(subscription.subscription_id)" 
                                    class="button button-small">
                                    Cancel
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <div class="ffc-pagination">
                <div class="ffc-pagination-info">
                    Showing {{ ((pagination.currentPage - 1) * pagination.perPage) + 1 }} to 
                    {{ Math.min(pagination.currentPage * pagination.perPage, filteredSubscriptions.length) }} of 
                    {{ filteredSubscriptions.length }} subscriptions
                </div>
                <div class="ffc-pagination-controls">
                    <button 
                        @click="changePage(pagination.currentPage - 1)" 
                        :disabled="pagination.currentPage === 1"
                        class="button button-small">
                        Previous
                    </button>
                    <span class="ffc-pagination-pages">
                        Page {{ pagination.currentPage }} of {{ pagination.totalPages }}
                    </span>
                    <button 
                        @click="changePage(pagination.currentPage + 1)" 
                        :disabled="pagination.currentPage === pagination.totalPages"
                        class="button button-small">
                        Next
                    </button>
                </div>
            </div>
        </div>
        
        <!-- No Subscriptions Message -->
        <div v-else class="ffc-no-subscriptions">
            <p>No subscriptions found.</p>
        </div>
    </div>
    
    <!-- Subscription Details Modal -->
    <div v-if="showDetailModal" class="ffc-modal" @click.self="showDetailModal = false">
        <div class="ffc-modal-content">
            <div class="ffc-modal-header">
                <h2>Subscription Details</h2>
                <button @click="showDetailModal = false" class="ffc-modal-close">&times;</button>
            </div>
            <div class="ffc-modal-body">
                <div class="ffc-subscription-summary">
                    <div class="ffc-summary-item">
                        <strong>Subscription ID:</strong><br>
                        {{ currentSubscription.subscription_id }}
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Status:</strong><br>
                        <span class="ffc-status-badge" :class="'ffc-status-' + currentSubscription.subscription_status">
                            {{ capitalizeFirst(currentSubscription.subscription_status) }}
                        </span>
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Started:</strong><br>
                        {{ formatDate(currentSubscription.started_at) }}
                    </div>
                    <div class="ffc-summary-item">
                        <strong>Next Renewal:</strong><br>
                        {{ formatDate(currentSubscription.subscription_current_period_end) }}
                    </div>
                </div>
                
                <h3>Subscription Items</h3>
                <div class="ffc-subscription-items-detail">
                    <div v-for="(feature, index) in parseFeatures(currentSubscription)" :key="index" class="ffc-item-detail">
                        <strong>{{ feature }}</strong>
                        <div v-if="parseOptions(currentSubscription)[index]">
                            Option: {{ parseOptions(currentSubscription)[index] }}
                        </div>
                    </div>
                </div>
                
                <div v-if="currentSubscription.payment_method" class="ffc-payment-method">
                    <h3>Payment Method</h3>
                    <p>
                        {{ currentSubscription.payment_method.brand }} ending in {{ currentSubscription.payment_method.last4 }}
                        <br>
                        Expires: {{ currentSubscription.payment_method.exp_month }}/{{ currentSubscription.payment_method.exp_year }}
                    </p>
                </div>
                
                <div class="ffc-subscription-total">
                    <strong>Total:</strong> ${{ formatPrice(currentSubscription.total_amount) }}/{{ getIntervalDisplay(currentSubscription.intervals) }}
                </div>
            </div>
            <div class="ffc-modal-footer">
                <button @click="showDetailModal = false" class="button">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Cancel Confirmation Modal -->
    <div v-if="showCancelModal" class="ffc-modal" @click.self="showCancelModal = false">
        <div class="ffc-modal-content ffc-small-modal">
            <div class="ffc-modal-header">
                <h2>Cancel Subscription</h2>
                <button @click="showCancelModal = false" class="ffc-modal-close">&times;</button>
            </div>
            <div class="ffc-modal-body">
                <p>Are you sure you want to cancel this subscription?</p>
                <p>This action cannot be undone. The subscription will remain active until the end of the current billing period.</p>
            </div>
            <div class="ffc-modal-footer">
                <button @click="showCancelModal = false" class="button">No, Keep Subscription</button>
                <button @click="cancelSubscription" class="button button-primary">Yes, Cancel Subscription</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>