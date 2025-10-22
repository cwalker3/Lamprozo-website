// ui/services/DialogService.js

/**
 * Service for handling user confirmation dialogs
 */
export class DialogService {
    constructor(eventBus, config) {
        this.eventBus = eventBus;
        this.config = config;
        this.activeDialog = null;
        this.initialize();
    }

    /**
     * Initialize dialog service and set up DOM elements
     */
    initialize() {
        this.dialog = document.getElementById('confirm-dialog');
        this.message = document.getElementById('confirm-message');
        this.yesButton = document.getElementById('confirm-yes');
        this.noButton = document.getElementById('confirm-no');

        if (!this.dialog || !this.message || !this.yesButton || !this.noButton) {
            return;
        }

        this.setupKeyboardHandling();
    }

    /**
     * Show confirmation dialog for deletion
     * @param {string} itemName - Name of item to delete
     * @param {Function} onConfirm - Callback for confirmation
     * @param {Object} options - Additional options
     */
    confirmDeletion(itemName, onConfirm, options = {}) {
        const message = options.message || `Are you sure you want to delete "${itemName}"?`;
        this.showDialog(message, onConfirm, options);
    }

    /**
     * Show confirmation dialog for cloning
     * @param {string} itemName - Name of item to clone
     * @param {Function} onConfirm - Callback for confirmation
     * @param {Object} options - Additional options
     */
    confirmClone(itemName, onConfirm, options = {}) {
        const message = options.message || `Are you sure you want to clone "${itemName}"?`;
        this.showDialog(message, onConfirm, options);
    }

    /**
     * Show generic confirmation dialog
     * @param {string} message - Dialog message
     * @param {Function} onConfirm - Callback for confirmation
     * @param {Object} options - Additional options
     */
    showDialog(message, onConfirm, options = {}) {
        if (this.activeDialog) {
            return;
        }

        this.activeDialog = {
            onConfirm,
            onCancel: options.onCancel || (() => {}),
            confirmText: options.confirmText || 'Yes',
            cancelText: options.cancelText || 'No'
        };

        this.message.textContent = message;
        this.yesButton.textContent = this.activeDialog.confirmText;
        this.noButton.textContent = this.activeDialog.cancelText;

        this.dialog.classList.add('show');
        
        // Focus management
        this.yesButton.focus();

        // Set up event listeners
        this.yesButton.addEventListener('click', this.handleConfirm.bind(this));
        this.noButton.addEventListener('click', this.handleCancel.bind(this));

        // Emit event
        this.eventBus.emit('dialogShown', {
            message,
            type: 'confirmation'
        });
    }

    /**
     * Handle confirmation
     * @private
     */
    handleConfirm() {
        if (!this.activeDialog) return;

        const { onConfirm } = this.activeDialog;
        this.closeDialog();

        try {
            onConfirm();
        } catch (error) {
            this.eventBus.emit('dialogError', { error });
        }

        this.eventBus.emit('dialogConfirmed');
    }

    /**
     * Handle cancellation
     * @private
     */
    handleCancel() {
        if (!this.activeDialog) return;

        const { onCancel } = this.activeDialog;
        this.closeDialog();

        try {
            onCancel();
        } catch (error) {
            this.eventBus.emit('dialogError', { error });
        }

        this.eventBus.emit('dialogCancelled');
    }

    /**
     * Close active dialog
     * @private
     */
    closeDialog() {
        if (!this.activeDialog) return;

        this.dialog.classList.remove('show');
        
        // Clean up event listeners
        this.yesButton.removeEventListener('click', this.handleConfirm.bind(this));
        this.noButton.removeEventListener('click', this.handleCancel.bind(this));

        this.activeDialog = null;

        this.eventBus.emit('dialogClosed');
    }

    /**
     * Set up keyboard handling for dialog
     * @private
     */
    setupKeyboardHandling() {
        document.addEventListener('keydown', (e) => {
            if (!this.activeDialog) return;

            switch (e.key) {
                case 'Enter':
                    e.preventDefault();
                    this.handleConfirm();
                    break;
                case 'Escape':
                    e.preventDefault();
                    this.handleCancel();
                    break;
                case 'Tab':
                    // Keep focus within dialog
                    e.preventDefault();
                    if (document.activeElement === this.yesButton) {
                        this.noButton.focus();
                    } else {
                        this.yesButton.focus();
                    }
                    break;
            }
        });
    }

    /**
     * Show simple alert dialog
     * @param {string} message - Alert message
     * @param {Object} options - Additional options
     */
    alert(message, options = {}) {
        this.showDialog(message, () => {}, {
            ...options,
            confirmText: options.confirmText || 'OK',
            cancelText: '', // Hide cancel button for alerts
            onCancel: () => {} // No-op for alerts
        });

        // Hide the cancel button for alerts
        if (options.confirmText || !options.cancelText) {
            this.noButton.style.display = 'none';
        }
    }

    /**
     * Show custom dialog with custom buttons
     * @param {string} message - Dialog message
     * @param {Array} buttons - Array of button configurations
     */
    customDialog(message, buttons = []) {
        // For more complex dialogs, this could be extended
        // For now, fall back to basic confirmation
        if (buttons.length >= 2) {
            this.showDialog(message, buttons[0].callback, {
                confirmText: buttons[0].text,
                cancelText: buttons[1].text,
                onCancel: buttons[1].callback
            });
        } else if (buttons.length === 1) {
            this.alert(message, {
                confirmText: buttons[0].text,
                onConfirm: buttons[0].callback
            });
        }
    }

    /**
     * Check if dialog is currently active
     * @returns {boolean} True if dialog is active
     */
    isActive() {
        return this.activeDialog !== null;
    }

    /**
     * Force close any active dialog (for cleanup)
     */
    forceClose() {
        if (this.activeDialog) {
            this.closeDialog();
        }
    }
}