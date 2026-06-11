/**
 * Professional Modal System for Firefly Collective
 * Replaces native JavaScript confirm() dialogs with branded modals
 */

(function() {
    'use strict';

    /**
     * Show a confirmation modal and return a Promise
     * @param {Object} options - Modal configuration
     * @param {string} options.title - Modal title
     * @param {string} options.message - Modal message/description
     * @param {string} options.confirmText - Text for confirm button (default: 'Confirm')
     * @param {string} options.cancelText - Text for cancel button (default: 'Cancel')
     * @param {string} options.confirmClass - Button style class: 'primary' or 'danger' (default: 'primary')
     * @param {string} options.icon - Optional icon type: 'warning', 'error', 'info', 'question' (default: 'question')
     * @returns {Promise<boolean>} - Resolves to true if confirmed, false if cancelled
     */
    window.showConfirmModal = function(options) {
        return new Promise((resolve) => {
            const config = {
                title: options.title || 'Confirm',
                message: options.message || 'Are you sure?',
                confirmText: options.confirmText || 'Confirm',
                cancelText: options.cancelText || 'Cancel',
                confirmClass: options.confirmClass || 'primary',
                icon: options.icon || 'question'
            };

            // Create modal elements
            const modal = createModalElements(config);
            
            // Add to DOM
            document.body.appendChild(modal.backdrop);
            
            // Trigger animation after a brief delay to ensure CSS transition works
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    modal.backdrop.classList.add('active');
                });
            });

            // Focus the confirm button for accessibility
            setTimeout(() => {
                modal.confirmBtn.focus();
            }, 100);

            // Handle confirm
            modal.confirmBtn.addEventListener('click', () => {
                closeModal(modal.backdrop, () => resolve(true));
            });

            // Handle cancel
            modal.cancelBtn.addEventListener('click', () => {
                closeModal(modal.backdrop, () => resolve(false));
            });

            // Handle backdrop click (cancel)
            modal.backdrop.addEventListener('click', (e) => {
                if (e.target === modal.backdrop) {
                    closeModal(modal.backdrop, () => resolve(false));
                }
            });

            // Handle escape key (cancel)
            const escapeHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal(modal.backdrop, () => {
                        resolve(false);
                        document.removeEventListener('keydown', escapeHandler);
                    });
                }
            };
            document.addEventListener('keydown', escapeHandler);

            // Focus trap: keep focus within modal
            const focusableElements = modal.container.querySelectorAll(
                'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
            );
            const firstFocusable = focusableElements[0];
            const lastFocusable = focusableElements[focusableElements.length - 1];

            modal.container.addEventListener('keydown', (e) => {
                if (e.key === 'Tab') {
                    if (e.shiftKey) {
                        // Shift + Tab
                        if (document.activeElement === firstFocusable) {
                            e.preventDefault();
                            lastFocusable.focus();
                        }
                    } else {
                        // Tab
                        if (document.activeElement === lastFocusable) {
                            e.preventDefault();
                            firstFocusable.focus();
                        }
                    }
                }
            });
        });
    };

    /**
     * Create modal DOM elements
     * @param {Object} config - Modal configuration
     * @returns {Object} - Object containing modal element references
     */
    function createModalElements(config) {
        // Create backdrop
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.setAttribute('role', 'dialog');
        backdrop.setAttribute('aria-modal', 'true');
        backdrop.setAttribute('aria-labelledby', 'modal-title');
        backdrop.setAttribute('aria-describedby', 'modal-message');

        // Create container
        const container = document.createElement('div');
        container.className = 'modal-container';

        // Create header
        const header = document.createElement('div');
        header.className = 'modal-header';
        
        const iconHtml = getIconHtml(config.icon);
        if (iconHtml) {
            const iconWrapper = document.createElement('div');
            iconWrapper.className = 'modal-icon modal-icon-' + config.icon;
            iconWrapper.innerHTML = iconHtml;
            header.appendChild(iconWrapper);
        }

        const title = document.createElement('h2');
        title.id = 'modal-title';
        title.className = 'modal-title';
        title.textContent = config.title;
        header.appendChild(title);

        // Create body
        const body = document.createElement('div');
        body.className = 'modal-body';
        
        const message = document.createElement('p');
        message.id = 'modal-message';
        message.className = 'modal-message';
        message.textContent = config.message;
        body.appendChild(message);

        // Create footer
        const footer = document.createElement('div');
        footer.className = 'modal-footer';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'modal-btn modal-btn-cancel';
        cancelBtn.textContent = config.cancelText;
        cancelBtn.setAttribute('aria-label', config.cancelText);

        const confirmBtn = document.createElement('button');
        confirmBtn.type = 'button';
        confirmBtn.className = 'modal-btn modal-btn-' + config.confirmClass;
        confirmBtn.textContent = config.confirmText;
        confirmBtn.setAttribute('aria-label', config.confirmText);

        footer.appendChild(cancelBtn);
        footer.appendChild(confirmBtn);

        // Assemble modal
        container.appendChild(header);
        container.appendChild(body);
        container.appendChild(footer);
        backdrop.appendChild(container);

        return {
            backdrop,
            container,
            confirmBtn,
            cancelBtn
        };
    }

    /**
     * Close modal with animation
     * @param {HTMLElement} backdrop - Modal backdrop element
     * @param {Function} callback - Callback to execute after close animation
     */
    function closeModal(backdrop, callback) {
        backdrop.classList.remove('active');
        
        // Wait for animation to complete before removing from DOM
        setTimeout(() => {
            if (backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
            if (callback) callback();
        }, 200); // Match CSS transition duration
    }

    /**
     * Get SVG icon HTML based on icon type
     * @param {string} iconType - Type of icon: 'warning', 'error', 'info', 'question'
     * @returns {string} - SVG HTML string
     */
    function getIconHtml(iconType) {
        const icons = {
            warning: `
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 20H22L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 9V13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M12 17H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            error: `
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M15 9L9 15M9 9L15 15" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            info: `
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 16V12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M12 8H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `,
            question: `
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/>
                    <path d="M9.09 9C9.3251 8.33167 9.78915 7.76811 10.4 7.40913C11.0108 7.05016 11.7289 6.91894 12.4272 7.03871C13.1255 7.15849 13.7588 7.52152 14.2151 8.06353C14.6713 8.60553 14.9211 9.29152 14.92 10C14.92 12 11.92 13 11.92 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M12 17H12.01" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            `
        };

        return icons[iconType] || icons.question;
    }

    /**
     * Show an alert modal (info only, single button)
     * @param {Object} options - Modal configuration
     * @param {string} options.title - Modal title
     * @param {string} options.message - Modal message/description
     * @param {string} options.buttonText - Text for button (default: 'OK')
     * @param {string} options.icon - Optional icon type (default: 'info')
     * @returns {Promise<void>}
     */
    window.showAlertModal = function(options) {
        return new Promise((resolve) => {
            const config = {
                title: options.title || 'Notice',
                message: options.message || '',
                buttonText: options.buttonText || 'OK',
                icon: options.icon || 'info'
            };

            // Create modal elements (similar to confirm, but single button)
            const backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop';
            backdrop.setAttribute('role', 'alertdialog');
            backdrop.setAttribute('aria-modal', 'true');
            backdrop.setAttribute('aria-labelledby', 'modal-title');
            backdrop.setAttribute('aria-describedby', 'modal-message');

            const container = document.createElement('div');
            container.className = 'modal-container';

            // Header
            const header = document.createElement('div');
            header.className = 'modal-header';
            
            const iconHtml = getIconHtml(config.icon);
            if (iconHtml) {
                const iconWrapper = document.createElement('div');
                iconWrapper.className = 'modal-icon modal-icon-' + config.icon;
                iconWrapper.innerHTML = iconHtml;
                header.appendChild(iconWrapper);
            }

            const title = document.createElement('h2');
            title.id = 'modal-title';
            title.className = 'modal-title';
            title.textContent = config.title;
            header.appendChild(title);

            // Body
            const body = document.createElement('div');
            body.className = 'modal-body';
            
            const message = document.createElement('p');
            message.id = 'modal-message';
            message.className = 'modal-message';
            message.textContent = config.message;
            body.appendChild(message);

            // Footer
            const footer = document.createElement('div');
            footer.className = 'modal-footer modal-footer-single';

            const okBtn = document.createElement('button');
            okBtn.type = 'button';
            okBtn.className = 'modal-btn modal-btn-primary';
            okBtn.textContent = config.buttonText;
            okBtn.setAttribute('aria-label', config.buttonText);

            footer.appendChild(okBtn);

            // Assemble
            container.appendChild(header);
            container.appendChild(body);
            container.appendChild(footer);
            backdrop.appendChild(container);

            // Add to DOM
            document.body.appendChild(backdrop);
            
            // Trigger animation
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    backdrop.classList.add('active');
                });
            });

            // Focus button
            setTimeout(() => {
                okBtn.focus();
            }, 100);

            // Handle OK button
            okBtn.addEventListener('click', () => {
                closeModal(backdrop, resolve);
            });

            // Handle escape key
            const escapeHandler = (e) => {
                if (e.key === 'Escape') {
                    closeModal(backdrop, () => {
                        resolve();
                        document.removeEventListener('keydown', escapeHandler);
                    });
                }
            };
            document.addEventListener('keydown', escapeHandler);
        });
    };

})();
