// ui/controllers/ExpandCollapseController.js

/**
 * Controller for managing expand/collapse animations and height calculations
 */
export class ExpandCollapseController {
    constructor(eventBus, config) {
        this.eventBus = eventBus;
        this.config = config;
        this.animationDuration = config.getUI('ANIMATION_DURATION');
        this.heightMultiplier = config.getUI('MAX_HEIGHT_MULTIPLIER');
        this.pendingUpdates = new Set();
        this.updateTimeout = null;
        
        // Listen for container update events
        this.eventBus.on('containerHeightUpdate', this.handleContainerUpdate.bind(this));
        this.eventBus.on('elementExpanded', this.updateParentContainers.bind(this));
        this.eventBus.on('elementCollapsed', this.updateParentContainers.bind(this));
    }

    /**
     * Toggle expand/collapse state of an element
     * @param {HTMLElement} element - Element to toggle
     * @param {boolean} forceExpand - Force expand regardless of current state
     */
    toggleExpandCollapse(element, forceExpand = false) {
        // Use specific selectors based on element type to avoid conflicts
        let content;
        if (element.classList.contains('feature')) {
            // For features, look for direct child feature-content
            content = element.querySelector(':scope > .feature-content');
        } else if (element.classList.contains('option')) {
            // For options, look for direct child content
            content = element.querySelector(':scope > .content');
        } else if (element.classList.contains('addon')) {
            // For addons, look for direct child content
            content = element.querySelector(':scope > .content');
        } else {
            // Fallback to original logic
            content = element.querySelector('.content');
            if (!content) {
                content = element.querySelector('.feature-content');
            }
        }
        
        const toggle = element.querySelector('.toggle-indicator');
        
        if (!content || !toggle) return;
        
        const isCurrentlyOpen = content.classList.contains('open');
        
        if (isCurrentlyOpen && !forceExpand) {
            this.collapse(element, content, toggle);
        } else {
            this.expand(element, content, toggle);
        }
    }

    /**
     * Expand an element
     * @param {HTMLElement} element - Parent element
     * @param {HTMLElement} content - Content element to expand
     * @param {HTMLElement} toggle - Toggle indicator
     */
    expand(element, content, toggle) {
        // Set initial state for smooth animation
        content.style.maxHeight = '0px';
        void content.offsetHeight; // Force reflow
        
        // Add open class and update toggle
        content.classList.add('open');
        toggle.textContent = '-';
        
        // Calculate height with generous buffer for dynamic content like original system
        const scrollHeight = content.scrollHeight;
        const safeHeight = Math.max(scrollHeight * 2.5, 2000); // Much more generous like original
        content.style.maxHeight = safeHeight + 'px';
        
        // Emit event for other components to react
        this.eventBus.emit('elementExpanded', {
            element,
            content,
            calculatedHeight: safeHeight
        });
        
        // Update parent containers after animation starts
        setTimeout(() => {
            this.updateParentContainers({ element });
        }, 100);
    }

    /**
     * Collapse an element
     * @param {HTMLElement} element - Parent element
     * @param {HTMLElement} content - Content element to collapse
     * @param {HTMLElement} toggle - Toggle indicator
     */
    collapse(element, content, toggle) {
        content.classList.remove('open');
        content.style.maxHeight = '0px';
        toggle.textContent = '+';
        
        // Emit event for other components to react
        this.eventBus.emit('elementCollapsed', {
            element,
            content
        });
        
        // Update parent containers after collapse
        setTimeout(() => {
            this.updateParentContainers({ element });
        }, 100);
    }

    /**
     * Force expand a feature element
     * @param {HTMLElement} featureElement - Feature element to expand
     */
    expandFeature(featureElement) {
        const content = featureElement.querySelector('.feature-content');
        const toggle = featureElement.querySelector('.toggle-indicator');
        
        if (!content || content.classList.contains('open')) return;
        
        // Set max-height to 0 first
        content.style.maxHeight = '0px';
        void content.offsetHeight; // Force reflow
        
        // Add open class and update toggle
        content.classList.add('open');
        if (toggle) toggle.textContent = '-';
        
        // Animate to full height
        setTimeout(() => {
            // Use the same generous height calculation as original
            content.style.maxHeight = (content.scrollHeight * 3) + 'px';
        }, 10);
        
        this.eventBus.emit('featureExpanded', { element: featureElement });
    }

    /**
     * Update parent container heights to accommodate content
     * @param {Object} data - Event data containing element reference
     */
    updateParentContainers(data) {
        const { element } = data;
        
        // Force a reflow to ensure accurate measurements
        document.body.offsetHeight;
        
        // Update option parent if it exists (for addon changes)
        const optionEl = element.closest('.option');
        if (optionEl && optionEl !== element) {
            this.dynamicUpdateContainerHeight(optionEl, ':scope > .content', 1.3);
        }
        
        // Update feature container (for option or addon changes)
        const featureEl = element.closest('.feature');
        if (featureEl && featureEl !== element) {
            this.dynamicUpdateContainerHeight(featureEl, ':scope > .feature-content', 1.2);
        }
    }

    /**
     * Update container height for a specific element
     * @param {HTMLElement} containerEl - Container element
     * @param {string} contentSelector - CSS selector for content element
     * @param {number} multiplier - Height multiplier for safety margin
     */
    updateContainerHeight(containerEl, contentSelector, multiplier = 1.3) {
        const content = containerEl.querySelector(contentSelector);
        if (content && content.classList.contains('open')) {
            const newHeight = Math.max(content.scrollHeight * multiplier, 300);
            content.style.maxHeight = newHeight + 'px';
        }
    }

    /**
     * Dynamically update container height based on current content size
     * @param {HTMLElement} containerEl - Container element
     * @param {string} contentSelector - CSS selector for content element
     * @param {number} multiplier - Height multiplier for safety margin
     */
    dynamicUpdateContainerHeight(containerEl, contentSelector, multiplier = 2.0) {
        const content = containerEl.querySelector(contentSelector);
        if (!content || !content.classList.contains('open')) return;
        
        // Force complete layout recalculation for accurate measurements
        document.body.offsetHeight; // Force reflow
        
        // Temporarily remove max-height to get accurate scrollHeight
        content.style.maxHeight = 'none';
        
        // Force another reflow after removing max-height
        const actualScrollHeight = content.scrollHeight;
        
        // Use generous height buffer like the original system
        const exactHeight = Math.max(actualScrollHeight * multiplier, 500);
        
        // Apply the generous height
        content.style.maxHeight = exactHeight + 'px';
    }

    /**
     * Cascade update all open containers with proper height calculations
     */
    updateAllOpenContainers() {
        // Prevent multiple rapid updates
        if (this.pendingUpdates.has('all')) return;
        this.pendingUpdates.add('all');
        
        requestAnimationFrame(() => {
            // Force a reflow first
            document.body.offsetHeight;
            
            // Update all open addon contents first (innermost containers)
            this.updateContainersBySelector('.addon > .content.open', 1.5, 300);
            
            // Then update all open option contents (middle containers)
            this.updateContainersBySelector('.option > .content.open', 1.3, 500);
            
            // Finally update all open feature contents (outermost containers)
            this.updateContainersBySelector('.feature-content.open', 1.2, 800);
            
            this.pendingUpdates.delete('all');
        });
    }

    /**
     * Update containers matching a specific selector
     * @param {string} selector - CSS selector for containers
     * @param {number} multiplier - Height multiplier
     * @param {number} minHeight - Minimum height
     */
    updateContainersBySelector(selector, multiplier, minHeight) {
        document.querySelectorAll(selector).forEach(content => {
            const newHeight = Math.max(content.scrollHeight * multiplier, minHeight);
            content.style.maxHeight = newHeight + 'px';
        });
    }

    /**
     * Aggressive cascade update for complex nested structures
     * @param {HTMLElement} element - Starting element
     */
    cascadeUpdateContainers(element) {
        // Start with the element itself
        if (element.classList.contains('addon') || element.classList.contains('option')) {
            const content = element.querySelector('.content');
            if (content && content.classList.contains('open')) {
                const multiplier = element.classList.contains('addon') ? 1.5 : 1.3;
                content.style.maxHeight = (content.scrollHeight * multiplier) + 'px';
            }
        }
        
        // Update parent containers
        this.updateParentContainers({ element });
    }

    /**
     * Pre-expand containers to maximum size for form insertion
     * @param {HTMLElement} element - Container element
     */
    preExpandForForm(element) {
        const content = element.querySelector('.content, .feature-content');
        if (content && content.classList.contains('open')) {
            content.style.maxHeight = '99999px';
        }
        
        // Also pre-expand parent containers
        const parentOption = element.closest('.option');
        if (parentOption) {
            const optionContent = parentOption.querySelector('.content');
            if (optionContent && optionContent.classList.contains('open')) {
                optionContent.style.maxHeight = '99999px';
            }
        }
        
        const parentFeature = element.closest('.feature');
        if (parentFeature) {
            const featureContent = parentFeature.querySelector('.feature-content');
            if (featureContent && featureContent.classList.contains('open')) {
                featureContent.style.maxHeight = '99999px';
            }
        }
    }

    /**
     * Handle container update events from other components
     * @param {Object} data - Event data
     */
    handleContainerUpdate(data) {
        const { element, delay = 100 } = data;
        
        setTimeout(() => {
            if (element) {
                this.cascadeUpdateContainers(element);
            } else {
                this.updateAllOpenContainers();
            }
        }, delay);
    }

    /**
     * Smooth scroll element into view with offset
     * @param {HTMLElement} element - Element to scroll to
     */
    scrollIntoViewWithOffset(element) {
        if (!element) return;
        
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'center'
        });
    }

    /**
     * Comprehensive height recalculation for all open containers
     * This method properly handles nested content by updating in the correct order
     */
    recalculateAllHeights() {
        // Clear any pending updates to avoid conflicts
        if (this.updateTimeout) {
            clearTimeout(this.updateTimeout);
        }
        
        this.updateTimeout = setTimeout(() => {
            
            // Force complete layout recalculation first
            document.body.offsetHeight;
            
            // Update in order: innermost to outermost (addons -> options -> features)
            // This ensures parent containers measure their children's final sizes
            
            // 1. Update all open addons first (innermost)
            document.querySelectorAll('.addon .content.open').forEach(content => {
                const addonElement = content.closest('.addon');
                if (addonElement) {
                    const beforeHeight = content.style.maxHeight;
                    content.style.maxHeight = 'none';
                    const scrollHeight = content.scrollHeight;
                    const exactHeight = Math.max(scrollHeight * 1.1, 50);
                    content.style.maxHeight = exactHeight + 'px';
                }
            });
            
            // Longer delay to let addon changes fully settle
            setTimeout(() => {
                // 2. Update all open options (middle level)
                document.querySelectorAll('.option .content.open').forEach(content => {
                    const optionElement = content.closest('.option');
                    if (optionElement) {
                        const beforeHeight = content.style.maxHeight;
                        content.style.maxHeight = 'none';
                        document.body.offsetHeight; // Force reflow
                        const scrollHeight = content.scrollHeight;
                        const exactHeight = Math.max(scrollHeight * 1.05, 50);
                        content.style.maxHeight = exactHeight + 'px';
                    }
                });
                
                // Longer delay to let option changes fully settle before measuring features
                setTimeout(() => {
                    // Force multiple reflows to ensure DOM is stable
                    document.body.offsetHeight;
                    document.body.offsetHeight;
                    
                    // 3. Update all open features last (outermost)
                    document.querySelectorAll('.feature-content.open').forEach(content => {
                        const featureElement = content.closest('.feature');
                        if (featureElement) {
                            const beforeHeight = content.style.maxHeight;
                            content.style.maxHeight = 'none';
                            document.body.offsetHeight; // Force reflow
                            const scrollHeight = content.scrollHeight;
                            const exactHeight = Math.max(scrollHeight * 1.02, 50);
                            content.style.maxHeight = exactHeight + 'px';
                        }
                    });
                    
                }, 150);
            }, 100);
            
            this.updateTimeout = null;
        }, 200);
    }

    /**
     * Clean up any pending animations or updates
     */
    cleanup() {
        this.pendingUpdates.clear();
        if (this.updateTimeout) {
            clearTimeout(this.updateTimeout);
        }
    }
}