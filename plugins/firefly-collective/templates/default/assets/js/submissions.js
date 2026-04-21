// plugin/assets/js/submissions.js

document.addEventListener('DOMContentLoaded', function () {
    if (typeof Vue === 'undefined' || !document.getElementById('ffc-submissions-app')) return;

    const { createApp, ref, computed, onMounted, watch } = Vue;

    createApp({
        setup() {
            // State
            const loading = ref(true);
            const submissions = ref([]);
            const statusCounts = ref({});
            const selectedIds = ref([]);
            const bulkAction = ref('');

            // Filters
            const filters = ref({
                form_type: '',
                status: '',
                search: '',
                date_from: '',
                date_to: ''
            });

            // Sorting
            const sortField = ref('created_at');
            const sortDirection = ref('desc');

            // Pagination
            const pagination = ref({
                page: 1,
                per_page: 20,
                total: 0
            });

            // Modals
            const showDetailModal = ref(false);
            const showDeleteModal = ref(false);
            const currentItem = ref({});
            const deleteTarget = ref({});

            // Debounce timer
            let searchTimer = null;

            // Computed
            const totalPages = computed(() => Math.ceil(pagination.value.total / pagination.value.per_page) || 1);
            const allSelected = computed(() =>
                submissions.value.length > 0 && selectedIds.value.length === submissions.value.length
            );

            // Methods
            async function fetchSubmissions() {
                loading.value = true;

                const params = new URLSearchParams({
                    page: pagination.value.page,
                    per_page: pagination.value.per_page,
                    sort_field: sortField.value,
                    sort_direction: sortDirection.value
                });

                if (filters.value.form_type) params.append('form_type', filters.value.form_type);
                if (filters.value.status) params.append('status', filters.value.status);
                if (filters.value.search) params.append('search', filters.value.search);
                if (filters.value.date_from) params.append('date_from', filters.value.date_from);
                if (filters.value.date_to) params.append('date_to', filters.value.date_to);

                try {
                    const response = await fetch(
                        `${submissionsData.api_url}get-submissions?${params.toString()}`,
                        { headers: { 'X-WP-Nonce': submissionsData.nonce } }
                    );
                    const data = await response.json();

                    if (data.success) {
                        submissions.value = data.submissions;
                        pagination.value.total = data.total;
                        statusCounts.value = data.status_counts || {};
                    }
                } catch (e) {
                    console.error('Failed to fetch submissions:', e);
                } finally {
                    loading.value = false;
                }
            }

            async function updateStatus(item) {
                try {
                    await fetch(`${submissionsData.api_url}update-submission-status`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': submissionsData.nonce
                        },
                        body: JSON.stringify({ id: item.id, status: item.status })
                    });
                    // Refresh counts
                    fetchSubmissions();
                } catch (e) {
                    console.error('Failed to update status:', e);
                }
            }

            function confirmDelete(item) {
                deleteTarget.value = item;
                showDeleteModal.value = true;
            }

            async function deleteSubmission() {
                try {
                    await fetch(`${submissionsData.api_url}delete-submission`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': submissionsData.nonce
                        },
                        body: JSON.stringify({ id: deleteTarget.value.id })
                    });
                    showDeleteModal.value = false;
                    fetchSubmissions();
                } catch (e) {
                    console.error('Failed to delete:', e);
                }
            }

            async function applyBulkAction() {
                if (!bulkAction.value || selectedIds.value.length === 0) return;

                const action = bulkAction.value;
                const ids = [...selectedIds.value];

                try {
                    if (action === 'delete') {
                        await fetch(`${submissionsData.api_url}bulk-delete-submissions`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': submissionsData.nonce
                            },
                            body: JSON.stringify({ ids })
                        });
                    } else {
                        await fetch(`${submissionsData.api_url}bulk-update-submissions-status`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-WP-Nonce': submissionsData.nonce
                            },
                            body: JSON.stringify({ ids, status: action })
                        });
                    }

                    selectedIds.value = [];
                    bulkAction.value = '';
                    fetchSubmissions();
                } catch (e) {
                    console.error('Bulk action failed:', e);
                }
            }

            function viewDetail(item) {
                currentItem.value = { ...item };
                showDetailModal.value = true;

                // Auto-mark as read
                if (item.status === 'new') {
                    item.status = 'read';
                    currentItem.value.status = 'read';
                    updateStatus(item);
                }
            }

            function sortBy(field) {
                if (sortField.value === field) {
                    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
                } else {
                    sortField.value = field;
                    sortDirection.value = 'desc';
                }
                fetchSubmissions();
            }

            function getSortClass(field) {
                if (sortField.value !== field) return 'sortable';
                return sortDirection.value === 'asc' ? 'sortable sorted-asc' : 'sortable sorted-desc';
            }

            function goToPage(page) {
                pagination.value.page = page;
                selectedIds.value = [];
                fetchSubmissions();
            }

            function toggleSelectAll(e) {
                if (e.target.checked) {
                    selectedIds.value = submissions.value.map(s => s.id);
                } else {
                    selectedIds.value = [];
                }
            }

            function resetFilters() {
                filters.value = { form_type: '', status: '', search: '', date_from: '', date_to: '' };
                pagination.value.page = 1;
                fetchSubmissions();
            }

            function debouncedSearch() {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    pagination.value.page = 1;
                    fetchSubmissions();
                }, 300);
            }

            function formatDate(dateStr) {
                if (!dateStr) return '—';
                const d = new Date(dateStr + ' UTC');
                return d.toLocaleDateString('en-US', {
                    year: 'numeric', month: 'short', day: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });
            }

            function formatMessage(msg) {
                if (!msg) return '';
                return msg.replace(/\n/g, '<br>');
            }

            function formatType(type, long) {
                if (!type) return '—';
                if (type === 'quote') return long ? 'Quote Request' : 'Quote';
                return type.charAt(0).toUpperCase() + type.slice(1);
            }

            // Lifecycle
            onMounted(() => {
                fetchSubmissions();
            });

            return {
                loading,
                submissions,
                statusCounts,
                selectedIds,
                bulkAction,
                filters,
                sortField,
                sortDirection,
                pagination,
                totalPages,
                allSelected,
                showDetailModal,
                showDeleteModal,
                currentItem,
                deleteTarget,
                fetchSubmissions,
                updateStatus,
                confirmDelete,
                deleteSubmission,
                applyBulkAction,
                viewDetail,
                sortBy,
                getSortClass,
                goToPage,
                toggleSelectAll,
                resetFilters,
                debouncedSearch,
                formatDate,
                formatMessage,
                formatType
            };
        }
    }).mount('#ffc-submissions-app');
});
