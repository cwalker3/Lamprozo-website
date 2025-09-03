// utils/helpers.js

/**
 * Utility functions for the pricing system
 */

/**
 * Debounce function to limit rapid function calls
 * @param {Function} func - Function to debounce
 * @param {number} wait - Wait time in milliseconds
 * @param {Object} options - Options object
 * @returns {Function} Debounced function
 */
export function debounce(func, wait, options = {}) {
    let timeout;
    let lastArgs;
    let lastThis;
    let result;
    let lastCallTime = 0;
    
    const leading = !!options.leading;
    const trailing = 'trailing' in options ? !!options.trailing : true;
    
    function invokeFunc() {
        result = func.apply(lastThis, lastArgs);
        lastThis = lastArgs = null;
        return result;
    }
    
    return function(...args) {
        const time = Date.now();
        const isInvoking = leading && (lastCallTime === 0);
        
        lastArgs = args;
        lastThis = this;
        lastCallTime = time;
        
        if (isInvoking) {
            result = invokeFunc();
        }
        
        clearTimeout(timeout);
        
        if (trailing) {
            timeout = setTimeout(() => {
                lastCallTime = 0;
                if (!leading || (Date.now() - lastCallTime) >= wait) {
                    result = invokeFunc();
                }
            }, wait);
        }
        
        return result;
    };
}

/**
 * Throttle function to limit function calls to once per interval
 * @param {Function} func - Function to throttle
 * @param {number} limit - Time limit in milliseconds
 * @returns {Function} Throttled function
 */
export function throttle(func, limit) {
    let inThrottle;
    return function(...args) {
        if (!inThrottle) {
            func.apply(this, args);
            inThrottle = true;
            setTimeout(() => inThrottle = false, limit);
        }
    };
}

/**
 * Deep clone an object
 * @param {*} obj - Object to clone
 * @returns {*} Cloned object
 */
export function deepClone(obj) {
    if (obj === null || typeof obj !== 'object') {
        return obj;
    }
    
    if (obj instanceof Date) {
        return new Date(obj.getTime());
    }
    
    if (obj instanceof Array) {
        return obj.map(item => deepClone(item));
    }
    
    if (typeof obj === 'object') {
        const cloned = {};
        for (const key in obj) {
            if (obj.hasOwnProperty(key)) {
                cloned[key] = deepClone(obj[key]);
            }
        }
        return cloned;
    }
    
    return obj;
}

/**
 * Check if two objects are deeply equal
 * @param {*} obj1 - First object
 * @param {*} obj2 - Second object
 * @returns {boolean} True if equal
 */
export function deepEqual(obj1, obj2) {
    if (obj1 === obj2) {
        return true;
    }
    
    if (obj1 == null || obj2 == null) {
        return obj1 === obj2;
    }
    
    if (typeof obj1 !== typeof obj2) {
        return false;
    }
    
    if (typeof obj1 !== 'object') {
        return obj1 === obj2;
    }
    
    if (Array.isArray(obj1) !== Array.isArray(obj2)) {
        return false;
    }
    
    const keys1 = Object.keys(obj1);
    const keys2 = Object.keys(obj2);
    
    if (keys1.length !== keys2.length) {
        return false;
    }
    
    for (const key of keys1) {
        if (!keys2.includes(key)) {
            return false;
        }
        
        if (!deepEqual(obj1[key], obj2[key])) {
            return false;
        }
    }
    
    return true;
}

/**
 * Generate a unique ID
 * @param {string} prefix - Optional prefix
 * @returns {string} Unique ID
 */
export function generateId(prefix = 'id') {
    return `${prefix}-${Math.random().toString(36).substring(2, 9)}`;
}

/**
 * Safely parse a JSON string
 * @param {string} jsonString - JSON string to parse
 * @param {*} fallback - Fallback value if parsing fails
 * @returns {*} Parsed object or fallback
 */
export function safeJsonParse(jsonString, fallback = null) {
    try {
        return JSON.parse(jsonString);
    } catch (error) {
        console.warn('Failed to parse JSON:', error);
        return fallback;
    }
}

/**
 * Safely stringify an object to JSON
 * @param {*} obj - Object to stringify
 * @param {string} fallback - Fallback string if stringify fails
 * @returns {string} JSON string or fallback
 */
export function safeJsonStringify(obj, fallback = '{}') {
    try {
        return JSON.stringify(obj);
    } catch (error) {
        console.warn('Failed to stringify object:', error);
        return fallback;
    }
}

/**
 * Get nested property from object using dot notation
 * @param {Object} obj - Source object
 * @param {string} path - Dot notation path (e.g., 'user.name.first')
 * @param {*} defaultValue - Default value if path not found
 * @returns {*} Property value or default
 */
export function getNestedProperty(obj, path, defaultValue = undefined) {
    if (!obj || typeof path !== 'string') {
        return defaultValue;
    }
    
    return path.split('.').reduce((current, key) => {
        return current && current[key] !== undefined ? current[key] : defaultValue;
    }, obj);
}

/**
 * Set nested property on object using dot notation
 * @param {Object} obj - Target object
 * @param {string} path - Dot notation path
 * @param {*} value - Value to set
 * @returns {Object} Modified object
 */
export function setNestedProperty(obj, path, value) {
    if (!obj || typeof path !== 'string') {
        return obj;
    }
    
    const keys = path.split('.');
    const lastKey = keys.pop();
    
    const target = keys.reduce((current, key) => {
        if (!current[key] || typeof current[key] !== 'object') {
            current[key] = {};
        }
        return current[key];
    }, obj);
    
    target[lastKey] = value;
    return obj;
}

/**
 * Format field name for display (camelCase to Title Case)
 * @param {string} fieldName - Field name to format
 * @returns {string} Formatted field name
 */
export function formatFieldName(fieldName) {
    return fieldName
        .replace(/[-_]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .split(' ')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/**
 * Validate email format
 * @param {string} email - Email to validate
 * @returns {boolean} True if valid email format
 */
export function isValidEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return emailRegex.test(email);
}

/**
 * Validate number format
 * @param {*} value - Value to validate
 * @param {Object} options - Validation options
 * @returns {boolean} True if valid number
 */
export function isValidNumber(value, options = {}) {
    const num = parseFloat(value);
    
    if (isNaN(num) || !isFinite(num)) {
        return false;
    }
    
    if (options.min !== undefined && num < options.min) {
        return false;
    }
    
    if (options.max !== undefined && num > options.max) {
        return false;
    }
    
    if (options.integer && !Number.isInteger(num)) {
        return false;
    }
    
    return true;
}

/**
 * Clamp a number between min and max values
 * @param {number} value - Value to clamp
 * @param {number} min - Minimum value
 * @param {number} max - Maximum value
 * @returns {number} Clamped value
 */
export function clamp(value, min, max) {
    return Math.min(Math.max(value, min), max);
}

/**
 * Convert string to kebab-case
 * @param {string} str - String to convert
 * @returns {string} Kebab-case string
 */
export function toKebabCase(str) {
    return str
        .replace(/([a-z])([A-Z])/g, '$1-$2')
        .replace(/[^a-zA-Z0-9-]/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .toLowerCase();
}

/**
 * Convert string to camelCase
 * @param {string} str - String to convert
 * @returns {string} CamelCase string
 */
export function toCamelCase(str) {
    return str
        .replace(/[-_\s]+(.)?/g, (_, char) => char ? char.toUpperCase() : '')
        .replace(/^[A-Z]/, char => char.toLowerCase());
}

/**
 * Escape HTML entities
 * @param {string} text - Text to escape
 * @returns {string} Escaped text
 */
export function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Remove HTML tags from string
 * @param {string} html - HTML string
 * @returns {string} Plain text
 */
export function stripHtml(html) {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent || div.innerText || '';
}

/**
 * Format currency value
 * @param {number} amount - Amount to format
 * @param {Object} options - Formatting options
 * @returns {string} Formatted currency string
 */
export function formatCurrency(amount, options = {}) {
    const {
        currency = 'USD',
        locale = 'en-US',
        minimumFractionDigits = 2,
        maximumFractionDigits = 2
    } = options;
    
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency,
        minimumFractionDigits,
        maximumFractionDigits
    }).format(amount);
}

/**
 * Format percentage value
 * @param {number} value - Value to format (0.1 = 10%)
 * @param {Object} options - Formatting options
 * @returns {string} Formatted percentage string
 */
export function formatPercentage(value, options = {}) {
    const {
        locale = 'en-US',
        minimumFractionDigits = 0,
        maximumFractionDigits = 2
    } = options;
    
    return new Intl.NumberFormat(locale, {
        style: 'percent',
        minimumFractionDigits,
        maximumFractionDigits
    }).format(value);
}

/**
 * Create a promise that resolves after specified time
 * @param {number} ms - Milliseconds to wait
 * @returns {Promise} Promise that resolves after delay
 */
export function delay(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

/**
 * Retry a function with exponential backoff
 * @param {Function} fn - Function to retry
 * @param {Object} options - Retry options
 * @returns {Promise} Promise that resolves with function result
 */
export async function retry(fn, options = {}) {
    const {
        maxAttempts = 3,
        baseDelay = 1000,
        maxDelay = 10000,
        backoffFactor = 2
    } = options;
    
    let lastError;
    
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        try {
            return await fn();
        } catch (error) {
            lastError = error;
            
            if (attempt === maxAttempts) {
                break;
            }
            
            const delayTime = Math.min(
                baseDelay * Math.pow(backoffFactor, attempt - 1),
                maxDelay
            );
            
            await delay(delayTime);
        }
    }
    
    throw lastError;
}

/**
 * Create an array of numbers in a range
 * @param {number} start - Start value
 * @param {number} end - End value
 * @param {number} step - Step increment
 * @returns {Array<number>} Array of numbers
 */
export function range(start, end, step = 1) {
    const result = [];
    
    if (step > 0) {
        for (let i = start; i <= end; i += step) {
            result.push(i);
        }
    } else if (step < 0) {
        for (let i = start; i >= end; i += step) {
            result.push(i);
        }
    }
    
    return result;
}

/**
 * Group array items by a key function
 * @param {Array} array - Array to group
 * @param {Function|string} keyFn - Key function or property name
 * @returns {Object} Grouped object
 */
export function groupBy(array, keyFn) {
    const getKey = typeof keyFn === 'function' 
        ? keyFn 
        : item => item[keyFn];
    
    return array.reduce((groups, item) => {
        const key = getKey(item);
        if (!groups[key]) {
            groups[key] = [];
        }
        groups[key].push(item);
        return groups;
    }, {});
}

/**
 * Remove duplicate items from array
 * @param {Array} array - Array to deduplicate
 * @param {Function} keyFn - Optional key function for complex objects
 * @returns {Array} Deduplicated array
 */
export function unique(array, keyFn) {
    if (!keyFn) {
        return [...new Set(array)];
    }
    
    const seen = new Set();
    return array.filter(item => {
        const key = keyFn(item);
        if (seen.has(key)) {
            return false;
        }
        seen.add(key);
        return true;
    });
}

/**
 * Sort array of objects by multiple keys
 * @param {Array} array - Array to sort
 * @param {Array} sortKeys - Array of sort key objects
 * @returns {Array} Sorted array
 */
export function multiSort(array, sortKeys) {
    return array.sort((a, b) => {
        for (const { key, direction = 'asc' } of sortKeys) {
            const aVal = getNestedProperty(a, key);
            const bVal = getNestedProperty(b, key);
            
            if (aVal < bVal) {
                return direction === 'asc' ? -1 : 1;
            }
            if (aVal > bVal) {
                return direction === 'asc' ? 1 : -1;
            }
        }
        return 0;
    });
}

/**
 * Check if value is empty (null, undefined, empty string, empty array, empty object)
 * @param {*} value - Value to check
 * @returns {boolean} True if empty
 */
export function isEmpty(value) {
    if (value == null) return true;
    if (typeof value === 'string') return value.trim() === '';
    if (Array.isArray(value)) return value.length === 0;
    if (typeof value === 'object') return Object.keys(value).length === 0;
    return false;
}

/**
 * Truncate string to specified length
 * @param {string} str - String to truncate
 * @param {number} length - Maximum length
 * @param {string} suffix - Suffix to add when truncated
 * @returns {string} Truncated string
 */
export function truncate(str, length, suffix = '...') {
    if (str.length <= length) {
        return str;
    }
    return str.substring(0, length - suffix.length) + suffix;
}