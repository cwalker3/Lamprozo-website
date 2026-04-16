<?php

    // plugin/views/submissions.php

    if (!current_user_can('manage_options')) {
        wp_die('You do not have sufficient permissions to access this page.');
    }

?>

<div class="wrap" id="ffc-submissions-app" v-cloak>
    <h1>Form Submissions</h1>

    <!-- Loading State -->
    <div v-if="loading" class="ffc-loading">
        <div class="spinner is-active"></div>
        <p>Loading submissions...</p>
    </div>

    <!-- Filters -->
    <div v-if="!loading" class="ffc-filters-container">
        <div class="ffc-filters">
            <div class="ffc-filter-item">
                <label>Type</label>
                <select v-model="filters.form_type" @change="fetchSubmissions()">
                    <option value="">All Types</option>
                    <option value="contact">Contact</option>
                    <option value="quote">Quote Request</option>
                </select>
            </div>

            <div class="ffc-filter-item">
                <label>Status</label>
                <select v-model="filters.status" @change="fetchSubmissions()">
                    <option value="">All Statuses</option>
                    <option value="new">New ({{ statusCounts.new || 0 }})</option>
                    <option value="read">Read ({{ statusCounts.read || 0 }})</option>
                    <option value="replied">Replied ({{ statusCounts.replied || 0 }})</option>
                    <option value="archived">Archived ({{ statusCounts.archived || 0 }})</option>
                </select>
            </div>

            <div class="ffc-filter-item">
                <label>From</label>
                <input type="date" v-model="filters.date_from" @change="fetchSubmissions()">
            </div>

            <div class="ffc-filter-item">
                <label>To</label>
                <input type="date" v-model="filters.date_to" @change="fetchSubmissions()">
            </div>

            <div class="ffc-filter-item">
                <label>Search</label>
                <input type="text" v-model="filters.search" @input="debouncedSearch" placeholder="Name, email, company...">
            </div>

            <div class="ffc-filter-item ffc-filter-actions">
                <label>&nbsp;</label>
                <button class="button" @click="resetFilters">Reset</button>
            </div>
        </div>

        <!-- Bulk Actions -->
        <div v-if="selectedIds.length > 0" class="ffc-bulk-actions">
            <span>{{ selectedIds.length }} selected</span>
            <select v-model="bulkAction">
                <option value="">Bulk Actions</option>
                <option value="read">Mark as Read</option>
                <option value="replied">Mark as Replied</option>
                <option value="archived">Archive</option>
                <option value="delete">Delete</option>
            </select>
            <button class="button" @click="applyBulkAction" :disabled="!bulkAction">Apply</button>
        </div>
    </div>

    <!-- Table -->
    <div v-if="!loading && submissions.length > 0" class="ffc-table-container">
        <table class="ffc-submissions-table">
            <thead>
                <tr>
                    <th class="check-column">
                        <input type="checkbox" @change="toggleSelectAll" :checked="allSelected">
                    </th>
                    <th @click="sortBy('id')" :class="getSortClass('id')">ID</th>
                    <th @click="sortBy('form_type')" :class="getSortClass('form_type')">Type</th>
                    <th @click="sortBy('name')" :class="getSortClass('name')">Name</th>
                    <th @click="sortBy('email')" :class="getSortClass('email')">Email</th>
                    <th>Company</th>
                    <th>Phone</th>
                    <th @click="sortBy('status')" :class="getSortClass('status')">Status</th>
                    <th @click="sortBy('created_at')" :class="getSortClass('created_at')">Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="item in submissions" :key="item.id" class="ffc-submission-row" :class="{ 'ffc-row-new': item.status === 'new' }">
                    <td>
                        <input type="checkbox" :value="item.id" v-model="selectedIds">
                    </td>
                    <td>{{ item.id }}</td>
                    <td>
                        <span class="ffc-type-badge" :class="'ffc-type-' + item.form_type">
                            {{ item.form_type === 'quote' ? 'Quote' : 'Contact' }}
                        </span>
                    </td>
                    <td><strong>{{ item.name }}</strong></td>
                    <td><a :href="'mailto:' + item.email">{{ item.email }}</a></td>
                    <td>{{ item.company || '—' }}</td>
                    <td>{{ item.phone || '—' }}</td>
                    <td>
                        <span class="ffc-status-badge" :class="'ffc-status-' + item.status">
                            {{ item.status }}
                        </span>
                    </td>
                    <td>{{ formatDate(item.created_at) }}</td>
                    <td class="ffc-actions-cell">
                        <div class="ffc-row-actions">
                            <button class="button button-small" @click="viewDetail(item)">View</button>
                            <button class="button button-small button-link-delete" @click="confirmDelete(item)">Delete</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Pagination -->
        <div class="ffc-pagination">
            <span class="ffc-pagination-info">
                Showing {{ ((pagination.page - 1) * pagination.per_page) + 1 }}–{{ Math.min(pagination.page * pagination.per_page, pagination.total) }} of {{ pagination.total }}
            </span>
            <div class="ffc-pagination-controls">
                <button class="button" :disabled="pagination.page <= 1" @click="goToPage(1)">First</button>
                <button class="button" :disabled="pagination.page <= 1" @click="goToPage(pagination.page - 1)">Prev</button>
                <span class="ffc-pagination-pages">Page {{ pagination.page }} of {{ totalPages }}</span>
                <button class="button" :disabled="pagination.page >= totalPages" @click="goToPage(pagination.page + 1)">Next</button>
                <button class="button" :disabled="pagination.page >= totalPages" @click="goToPage(totalPages)">Last</button>
            </div>
        </div>
    </div>

    <!-- No results -->
    <div v-if="!loading && submissions.length === 0" class="ffc-no-submissions">
        <p>No submissions found.</p>
    </div>

    <!-- Detail Modal -->
    <div v-if="showDetailModal" class="ffc-modal" @click.self="showDetailModal = false">
        <div class="ffc-modal-content">
            <div class="ffc-modal-header">
                <h2>
                    <span class="ffc-type-badge" :class="'ffc-type-' + currentItem.form_type">
                        {{ currentItem.form_type === 'quote' ? 'Quote Request' : 'Contact' }}
                    </span>
                    from {{ currentItem.name }}
                </h2>
                <button class="ffc-modal-close" @click="showDetailModal = false">&times;</button>
            </div>
            <div class="ffc-modal-body">
                <div class="ffc-detail-grid">
                    <div class="ffc-detail-item">
                        <label>Name</label>
                        <span>{{ currentItem.name }}</span>
                    </div>
                    <div class="ffc-detail-item">
                        <label>Email</label>
                        <span><a :href="'mailto:' + currentItem.email">{{ currentItem.email }}</a></span>
                    </div>
                    <div v-if="currentItem.company" class="ffc-detail-item">
                        <label>Company</label>
                        <span>{{ currentItem.company }}</span>
                    </div>
                    <div v-if="currentItem.phone" class="ffc-detail-item">
                        <label>Phone</label>
                        <span><a :href="'tel:' + currentItem.phone">{{ currentItem.phone }}</a></span>
                    </div>
                    <div v-if="currentItem.plan" class="ffc-detail-item">
                        <label>Plan</label>
                        <span>{{ currentItem.plan }}</span>
                    </div>
                    <div class="ffc-detail-item">
                        <label>Date</label>
                        <span>{{ formatDate(currentItem.created_at) }}</span>
                    </div>
                    <div class="ffc-detail-item">
                        <label>Status</label>
                        <select v-model="currentItem.status" @change="updateStatus(currentItem)">
                            <option value="new">New</option>
                            <option value="read">Read</option>
                            <option value="replied">Replied</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>
                <div class="ffc-detail-message">
                    <label>Message</label>
                    <div class="ffc-message-content" v-html="formatMessage(currentItem.message)"></div>
                </div>
            </div>
            <div class="ffc-modal-footer">
                <a :href="'mailto:' + currentItem.email" class="button button-primary">Reply via Email</a>
                <button class="button" @click="showDetailModal = false">Close</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="ffc-modal" @click.self="showDeleteModal = false">
        <div class="ffc-modal-content ffc-small-modal">
            <div class="ffc-modal-header">
                <h2>Confirm Delete</h2>
                <button class="ffc-modal-close" @click="showDeleteModal = false">&times;</button>
            </div>
            <div class="ffc-modal-body">
                <p>Are you sure you want to delete this submission from <strong>{{ deleteTarget.name }}</strong>?</p>
            </div>
            <div class="ffc-modal-footer">
                <button class="button button-link-delete" @click="deleteSubmission">Delete</button>
                <button class="button" @click="showDeleteModal = false">Cancel</button>
            </div>
        </div>
    </div>
</div>
