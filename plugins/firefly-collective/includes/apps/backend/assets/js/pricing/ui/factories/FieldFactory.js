// ui/factories/FieldFactory.js

import { debounce } from '../../utils/helpers.js';

/**
 * Factory for creating different types of form fields
 */
export class FieldFactory {
    constructor(eventBus, config) {
        this.eventBus = eventBus;
        this.config = config;
    }

    /**
     * Create a field group with label and input
     * @param {string} labelText - Label text
     * @param {string} inputType - Type of input
     * @param {Function} getValue - Function to get current value
     * @param {Function} setValue - Function to set value
     * @param {string} placeholder - Placeholder text
     * @param {Object} options - Additional options
     * @returns {HTMLElement} Field group element
     */
    createFieldGroup(labelText, inputType, getValue, setValue, placeholder = '', options = {}) {
        const group = document.createElement('div');
        group.className = this.config.getClass('field_group');

        const label = document.createElement('label');
        label.textContent = this.formatFieldName(labelText) + ':';
        group.appendChild(label);

        // Create debounced commit function
        const commit = debounce((val) => {
            setValue(val);
            this.eventBus.emit('fieldChanged', {
                field: labelText,
                value: val,
                type: inputType
            });
        }, this.config.getUI('DEBOUNCE_DELAY'));

        const input = this.createInput(inputType, getValue, commit, placeholder, options);
        group.appendChild(input);

        return group;
    }

    /**
     * Create input element based on type
     * @param {string} inputType - Type of input
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @param {string} placeholder - Placeholder text
     * @param {Object} options - Additional options
     * @returns {HTMLElement} Input element
     */
    createInput(inputType, getValue, commit, placeholder = '', options = {}) {
        switch (inputType) {
            case this.config.getFieldType('boolean'):
                return this.createCheckbox(getValue, commit);

            case this.config.getFieldType('number'):
            case this.config.getFieldType('int_float'):
                return this.createNumberInput(inputType, getValue, commit, placeholder);

            case this.config.getFieldType('date'):
                return this.createDateInput(getValue, commit);

            case this.config.getFieldType('long_text'):
            case this.config.getFieldType('textarea'):
                return this.createTextarea(getValue, commit, placeholder, options);

            case this.config.getFieldType('dropdown'):
                return this.createDropdown(getValue, commit, options);

            case this.config.getFieldType('text'):
            default:
                return this.createTextInput(getValue, commit, placeholder, options);
        }
    }

    /**
     * Create checkbox input
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @returns {HTMLInputElement} Checkbox element
     */
    createCheckbox(getValue, commit) {
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = !!getValue();
        input.addEventListener('change', e => commit(e.target.checked));
        return input;
    }

    /**
     * Create number input
     * @param {string} inputType - Specific number input type
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @param {string} placeholder - Placeholder text
     * @returns {HTMLInputElement} Number input element
     */
    createNumberInput(inputType, getValue, commit, placeholder) {
        const input = document.createElement('input');
        input.type = 'number';
        
        if (inputType === this.config.getFieldType('int_float')) {
            input.step = 'any';
        }
        
        input.placeholder = placeholder;
        input.value = getValue() != null ? getValue() : '';
        
        input.addEventListener('input', e => {
            const value = parseFloat(e.target.value) || 0;
            commit(value);
        });
        
        return input;
    }

    /**
     * Create date input
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @returns {HTMLInputElement} Date input element
     */
    createDateInput(getValue, commit) {
        const input = document.createElement('input');
        input.type = 'date';
        input.value = getValue() || '';
        input.addEventListener('change', e => commit(e.target.value));
        return input;
    }

    /**
     * Create textarea
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @param {string} placeholder - Placeholder text
     * @param {Object} options - Additional options
     * @returns {HTMLTextAreaElement} Textarea element
     */
    createTextarea(getValue, commit, placeholder, options = {}) {
        const textarea = document.createElement('textarea');
        textarea.rows = options.rows || 3;
        textarea.placeholder = placeholder;
        textarea.value = getValue() || '';
        textarea.addEventListener('input', e => commit(e.target.value));
        return textarea;
    }

    /**
     * Create dropdown/select
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @param {Object} options - Dropdown options
     * @returns {HTMLSelectElement} Select element
     */
    createDropdown(getValue, commit, options = {}) {
        const { types, selected } = getValue();
        const select = document.createElement('select');
        
        types.forEach((optionText, index) => {
            const option = document.createElement('option');
            option.value = index;
            option.textContent = optionText;
            if (index === selected) {
                option.selected = true;
            }
            select.appendChild(option);
        });

        select.addEventListener('change', e => {
            commit({
                types,
                selected: parseInt(e.target.value, 10)
            });
        });

        return select;
    }

    /**
     * Create text input
     * @param {Function} getValue - Function to get current value
     * @param {Function} commit - Function to commit changes
     * @param {string} placeholder - Placeholder text
     * @param {Object} options - Additional options
     * @returns {HTMLInputElement} Text input element
     */
    createTextInput(getValue, commit, placeholder, options = {}) {
        const input = document.createElement('input');
        input.type = 'text';
        input.placeholder = placeholder;
        input.value = getValue() != null ? getValue() : '';
        
        // Add autocomplete if provided
        if (options.datalist) {
            const datalistId = `datalist-${Math.random().toString(36).substring(2, 9)}`;
            const datalist = document.createElement('datalist');
            datalist.id = datalistId;
            
            options.datalist.forEach(item => {
                const option = document.createElement('option');
                option.value = typeof item === 'object' ? item.value : item;
                if (typeof item === 'object' && item.label) {
                    option.textContent = item.label;
                }
                datalist.appendChild(option);
            });
            
            input.setAttribute('list', datalistId);
            document.body.appendChild(datalist);
        }
        
        input.addEventListener('input', e => commit(e.target.value));
        return input;
    }

    /**
     * Create dynamic field based on data structure
     * @param {string} key - Field key
     * @param {*} rawValue - Raw field value
     * @param {Function} onChange - Change handler
     * @param {Object} options - Additional options
     * @returns {HTMLElement} Field element
     */
    createDynamicField(key, rawValue, onChange, options = {}) {
        let wrapper = null;
        let fieldType;
        let valueHolder;

        // Check if this is a wrapped field structure
        if (rawValue && typeof rawValue === 'object' && 
            'ui_type' in rawValue && 'value' in rawValue) {
            wrapper = rawValue;
            fieldType = rawValue.ui_type;
            valueHolder = rawValue.value;
        } else {
            valueHolder = rawValue;
            fieldType = this.determineFieldType(rawValue);
        }

        const group = document.createElement('div');
        group.className = this.config.getClass('field_group');

        // Add special styling for user-display fields
        if (wrapper && (wrapper.level === 'user-display' || wrapper.is_display) ||
            key.endsWith('_display')) {
            group.classList.add(this.config.getClass('user_display_field'));
        }

        const label = document.createElement('label');
        label.textContent = this.formatFieldName(key) + ':';
        group.appendChild(label);

        const commit = (val) => {
            if (wrapper) {
                wrapper.value = val;
            } else {
                onChange(val);
            }
            this.eventBus.emit('dynamicFieldChanged', {
                key,
                value: val,
                wrapper: wrapper ? { ...wrapper } : null
            });
        };

        const input = this.createDynamicInput(fieldType, valueHolder, commit, options);
        group.appendChild(input);

        return group;
    }

    /**
     * Create input for dynamic field
     * @param {string} fieldType - Field type
     * @param {*} valueHolder - Current value
     * @param {Function} commit - Commit function
     * @param {Object} options - Additional options
     * @returns {HTMLElement} Input element
     */
    createDynamicInput(fieldType, valueHolder, commit, options = {}) {
        if (fieldType === this.config.getFieldType('array') && 
            Array.isArray(valueHolder.types)) {
            return this.createDropdown(
                () => valueHolder,
                commit,
                options
            );
        }

        if (fieldType === this.config.getFieldType('boolean')) {
            return this.createCheckbox(
                () => !!valueHolder,
                commit
            );
        }

        if (fieldType === this.config.getFieldType('number') || 
            fieldType === this.config.getFieldType('int_float')) {
            return this.createNumberInput(
                fieldType,
                () => valueHolder,
                commit,
                options.placeholder
            );
        }

        if (fieldType === this.config.getFieldType('date')) {
            return this.createDateInput(
                () => valueHolder || '',
                commit
            );
        }

        if (fieldType === this.config.getFieldType('textarea') || 
            fieldType === this.config.getFieldType('long_text')) {
            return this.createTextarea(
                () => {
                    if (valueHolder && valueHolder.text != null) {
                        return valueHolder.text;
                    }
                    return valueHolder || '';
                },
                commit,
                options.placeholder,
                options
            );
        }

        // Default to text input
        return this.createTextInput(
            () => valueHolder != null ? valueHolder : '',
            commit,
            options.placeholder,
            options
        );
    }

    /**
     * Determine field type from value
     * @param {*} value - Value to analyze
     * @returns {string} Field type
     */
    determineFieldType(value) {
        if (value && typeof value === 'object') {
            if (Array.isArray(value.types) && 'selected' in value) {
                return this.config.getFieldType('dropdown');
            }
            if ('text' in value) {
                return this.config.getFieldType('textarea');
            }
        }

        if (typeof value === 'boolean') {
            return this.config.getFieldType('boolean');
        }

        if (typeof value === 'number') {
            return this.config.getFieldType('number');
        }

        if (Array.isArray(value)) {
            return value.every(x => typeof x === 'string') ? 
                this.config.getFieldType('dropdown') : 
                this.config.getFieldType('text');
        }

        if (typeof value === 'string' && value.length > 80) {
            return this.config.getFieldType('textarea');
        }

        return this.config.getFieldType('text');
    }

    /**
     * Format field name for display
     * @param {string} name - Raw field name
     * @returns {string} Formatted field name
     */
    formatFieldName(name) {
        return name
            .replace(/[-_]+/g, ' ')
            .replace(/([a-z])([A-Z])/g, '$1 $2')
            .split(' ')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    }

    /**
     * Create fields for unknown/dynamic properties
     * @param {Object} obj - Object to create fields for
     * @param {HTMLElement} container - Container element
     * @param {Array} knownKeys - Keys to skip
     * @param {Object} options - Additional options
     */
    createDynamicFields(obj, container, knownKeys = [], options = {}) {
        Object.keys(obj).forEach(key => {
            if (!obj.hasOwnProperty(key) || knownKeys.includes(key)) {
                return;
            }

            const rawValue = obj[key];

            // Skip non-admin fields unless specifically handling them
            if (rawValue && typeof rawValue === 'object' &&
                'level' in rawValue && 'ui_type' in rawValue && 'value' in rawValue &&
                rawValue.level !== 'admin' && rawValue.level !== 'user-display') {
                return;
            }

            const field = this.createDynamicField(
                key,
                rawValue,
                value => obj[key] = value,
                options
            );

            container.appendChild(field);
        });
    }
}