// services/ValidationService.js

/**
 * Service for validating pricing data and form inputs
 */
export class ValidationService {
    constructor(eventBus, config) {
        this.eventBus = eventBus;
        this.config = config;
        this.validationRules = this.initializeValidationRules();
    }

    /**
     * Initialize validation rules
     * @returns {Object} Validation rules configuration
     */
    initializeValidationRules() {
        return {
            feature: {
                featureName: {
                    required: true,
                    type: 'string',
                    maxLength: 255,
                    message: 'Feature name is required and must be less than 255 characters'
                },
                recurring: {
                    type: 'boolean'
                }
            },
            option: {
                optionName: {
                    required: true,
                    type: 'string',
                    maxLength: 255,
                    message: 'Option name is required and must be less than 255 characters'
                },
                staticPrice: {
                    type: 'number',
                    min: 0,
                    message: 'Static price must be a positive number'
                },
                priceFloor: {
                    type: 'number',
                    min: 0,
                    message: 'Price floor must be a positive number'
                },
                priceCeiling: {
                    type: 'number',
                    min: 0,
                    message: 'Price ceiling must be a positive number',
                    validate: (value, data) => {
                        if (data.priceFloor && value < data.priceFloor) {
                            return 'Price ceiling must be greater than price floor';
                        }
                        return null;
                    }
                },
                maxAddons: {
                    type: 'number',
                    min: -1,
                    message: 'Max addons must be -1 (unlimited) or positive number'
                }
            },
            addon: {
                addonName: {
                    required: true,
                    type: 'string',
                    maxLength: 255,
                    message: 'Addon name is required and must be less than 255 characters'
                },
                staticPriceMod: {
                    type: 'number',
                    message: 'Static price modifier must be a number'
                },
                floorPriceMod: {
                    type: 'number',
                    min: 0,
                    message: 'Floor price modifier must be a positive number'
                },
                ceilingPriceMod: {
                    type: 'number',
                    min: 0,
                    message: 'Ceiling price modifier must be a positive number'
                },
                maxGroupItems: {
                    type: 'number',
                    min: -1,
                    message: 'Max group items must be -1 (unlimited) or positive number'
                },
                groupName: {
                    type: 'string',
                    maxLength: 255,
                    validate: (value, data) => {
                        if (data.enableGrouping && (!value || !value.trim())) {
                            return 'Group name is required when grouping is enabled';
                        }
                        return null;
                    }
                }
            },
            priceOptions: {
                validate: (options) => {
                    if (!Array.isArray(options)) {
                        return 'Price options must be an array';
                    }
                    
                    for (let i = 0; i < options.length; i++) {
                        const option = options[i];
                        if (!option.label || !option.label.trim()) {
                            return `Price option ${i + 1} must have a label`;
                        }
                        if (typeof option.price !== 'number' || option.price < 0) {
                            return `Price option ${i + 1} must have a valid price`;
                        }
                    }
                    
                    return null;
                }
            },
            thresholdDiscounts: {
                validate: (discounts) => {
                    if (!Array.isArray(discounts)) {
                        return 'Threshold discounts must be an array';
                    }
                    
                    for (let i = 0; i < discounts.length; i++) {
                        const discount = discounts[i];
                        
                        if (discount.itemCount !== '' && 
                            (typeof discount.itemCount !== 'number' || discount.itemCount < 1)) {
                            return `Threshold ${i + 1} item count must be a positive number`;
                        }
                        
                        if (discount.discount !== '' && 
                            (typeof discount.discount !== 'number' || 
                             discount.discount < 0 || discount.discount > 100)) {
                            return `Threshold ${i + 1} discount must be between 0 and 100`;
                        }
                        
                        // Check for completeness - both fields should be filled if either is
                        const hasCount = discount.itemCount !== '' && discount.itemCount !== null;
                        const hasDiscount = discount.discount !== '' && discount.discount !== null;
                        
                        if (hasCount !== hasDiscount) {
                            return `Threshold ${i + 1} must have both item count and discount percentage`;
                        }
                    }
                    
                    return null;
                }
            }
        };
    }

    /**
     * Validate a feature object
     * @param {Object} feature - Feature to validate
     * @returns {Object} Validation result
     */
    validateFeature(feature) {
        const errors = [];
        const warnings = [];
        
        const featureRules = this.validationRules.feature;
        
        // Validate basic feature fields
        for (const [field, rule] of Object.entries(featureRules)) {
            const value = this.getFieldValue(feature, field);
            const error = this.validateField(field, value, rule, feature);
            if (error) errors.push(error);
        }
        
        // Validate options
        if (feature.options && Array.isArray(feature.options)) {
            feature.options.forEach((option, index) => {
                const optionResult = this.validateOption(option);
                if (optionResult.errors.length > 0) {
                    errors.push(...optionResult.errors.map(err => 
                        `Option ${index + 1}: ${err}`));
                }
                if (optionResult.warnings.length > 0) {
                    warnings.push(...optionResult.warnings.map(warn => 
                        `Option ${index + 1}: ${warn}`));
                }
            });
        } else {
            warnings.push('Feature has no options');
        }
        
        return {
            isValid: errors.length === 0,
            errors,
            warnings
        };
    }

    /**
     * Validate an option object
     * @param {Object} option - Option to validate
     * @returns {Object} Validation result
     */
    validateOption(option) {
        const errors = [];
        const warnings = [];
        
        const optionRules = this.validationRules.option;
        
        // Validate basic option fields
        for (const [field, rule] of Object.entries(optionRules)) {
            const value = this.getFieldValue(option, field);
            const error = this.validateField(field, value, rule, option);
            if (error) errors.push(error);
        }
        
        // Validate pricing type consistency
        const pricingTypeError = this.validatePricingTypeConsistency(option);
        if (pricingTypeError) errors.push(pricingTypeError);
        
        // Validate price options if present
        if (option.priceOptions) {
            const priceOptionsData = this.extractPriceOptionsData(option.priceOptions);
            if (priceOptionsData) {
                const priceOptionsError = this.validationRules.priceOptions.validate(priceOptionsData);
                if (priceOptionsError) errors.push(priceOptionsError);
            }
        }
        
        // Validate threshold discounts if enabled
        if (option.enableThresholdDiscounts && option.thresholdDiscounts) {
            const thresholdData = this.extractThresholdData(option.thresholdDiscounts);
            if (thresholdData) {
                const thresholdError = this.validationRules.thresholdDiscounts.validate(thresholdData);
                if (thresholdError) errors.push(thresholdError);
            }
        }
        
        // Validate addons
        if (option.addons && Array.isArray(option.addons)) {
            option.addons.forEach((addon, index) => {
                const addonResult = this.validateAddon(addon);
                if (addonResult.errors.length > 0) {
                    errors.push(...addonResult.errors.map(err => 
                        `Addon ${index + 1}: ${err}`));
                }
                if (addonResult.warnings.length > 0) {
                    warnings.push(...addonResult.warnings.map(warn => 
                        `Addon ${index + 1}: ${warn}`));
                }
            });
            
            // Check max addons constraint
            if (option.maxAddons !== -1 && option.addons.length > option.maxAddons) {
                warnings.push(`Option has ${option.addons.length} addons but max is set to ${option.maxAddons}`);
            }
        }
        
        return {
            isValid: errors.length === 0,
            errors,
            warnings
        };
    }

    /**
     * Validate an addon object
     * @param {Object} addon - Addon to validate
     * @returns {Object} Validation result
     */
    validateAddon(addon) {
        const errors = [];
        const warnings = [];
        
        const addonRules = this.validationRules.addon;
        
        // Validate basic addon fields
        for (const [field, rule] of Object.entries(addonRules)) {
            const value = this.getFieldValue(addon, field);
            const error = this.validateField(field, value, rule, addon);
            if (error) errors.push(error);
        }
        
        // Validate group threshold discounts if grouping is enabled
        if (addon.enableGrouping && addon.groupThresholdDiscounts) {
            const thresholdData = this.extractThresholdData(addon.groupThresholdDiscounts);
            if (thresholdData) {
                const thresholdError = this.validationRules.thresholdDiscounts.validate(thresholdData);
                if (thresholdError) errors.push(`Group threshold discounts: ${thresholdError}`);
            }
        }
        
        return {
            isValid: errors.length === 0,
            errors,
            warnings
        };
    }

    /**
     * Validate entire pricing data structure
     * @param {Object} data - Complete pricing data
     * @returns {Object} Validation result
     */
    validatePricingData(data) {
        const errors = [];
        const warnings = [];
        
        if (!data || !data.features || !Array.isArray(data.features)) {
            errors.push('Invalid pricing data structure');
            return { isValid: false, errors, warnings };
        }
        
        if (data.features.length === 0) {
            warnings.push('No features defined');
        }
        
        // Validate each feature
        data.features.forEach((feature, index) => {
            const featureResult = this.validateFeature(feature);
            if (featureResult.errors.length > 0) {
                errors.push(...featureResult.errors.map(err => 
                    `Feature ${index + 1}: ${err}`));
            }
            if (featureResult.warnings.length > 0) {
                warnings.push(...featureResult.warnings.map(warn => 
                    `Feature ${index + 1}: ${warn}`));
            }
        });
        
        // Check for duplicate names
        const duplicateErrors = this.checkForDuplicateNames(data);
        errors.push(...duplicateErrors);
        
        return {
            isValid: errors.length === 0,
            errors,
            warnings
        };
    }

    /**
     * Validate a single field against its rules
     * @param {string} fieldName - Name of the field
     * @param {*} value - Field value
     * @param {Object} rule - Validation rule
     * @param {Object} data - Complete data object for context
     * @returns {string|null} Error message or null if valid
     */
    validateField(fieldName, value, rule, data) {
        // Check required fields
        if (rule.required && (value === undefined || value === null || value === '')) {
            return rule.message || `${fieldName} is required`;
        }
        
        // Skip further validation for empty non-required fields
        if (!rule.required && (value === undefined || value === null || value === '')) {
            return null;
        }
        
        // Type validation
        if (rule.type) {
            if (rule.type === 'number' && (typeof value !== 'number' || isNaN(value))) {
                return rule.message || `${fieldName} must be a number`;
            }
            if (rule.type === 'string' && typeof value !== 'string') {
                return rule.message || `${fieldName} must be a string`;
            }
            if (rule.type === 'boolean' && typeof value !== 'boolean') {
                return rule.message || `${fieldName} must be a boolean`;
            }
        }
        
        // Min/Max validation for numbers
        if (typeof value === 'number') {
            if (rule.min !== undefined && value < rule.min) {
                return rule.message || `${fieldName} must be at least ${rule.min}`;
            }
            if (rule.max !== undefined && value > rule.max) {
                return rule.message || `${fieldName} must be at most ${rule.max}`;
            }
        }
        
        // Length validation for strings
        if (typeof value === 'string') {
            if (rule.minLength && value.length < rule.minLength) {
                return rule.message || `${fieldName} must be at least ${rule.minLength} characters`;
            }
            if (rule.maxLength && value.length > rule.maxLength) {
                return rule.message || `${fieldName} must be at most ${rule.maxLength} characters`;
            }
        }
        
        // Custom validation function
        if (rule.validate && typeof rule.validate === 'function') {
            const customError = rule.validate(value, data);
            if (customError) return customError;
        }
        
        return null;
    }

    /**
     * Check for duplicate names across features, options, and addons
     * @param {Object} data - Pricing data
     * @returns {Array} Array of error messages
     */
    checkForDuplicateNames(data) {
        const errors = [];
        const featureNames = new Set();
        
        data.features.forEach((feature, fIdx) => {
            // Check duplicate feature names
            if (feature.featureName) {
                if (featureNames.has(feature.featureName)) {
                    errors.push(`Duplicate feature name: "${feature.featureName}"`);
                } else {
                    featureNames.add(feature.featureName);
                }
            }
            
            // Check option names within each feature
            const optionNames = new Set();
            if (feature.options) {
                feature.options.forEach((option, oIdx) => {
                    if (option.optionName) {
                        if (optionNames.has(option.optionName)) {
                            errors.push(`Feature ${fIdx + 1}: Duplicate option name: "${option.optionName}"`);
                        } else {
                            optionNames.add(option.optionName);
                        }
                    }
                    
                    // Check addon names within each option
                    const addonNames = new Set();
                    if (option.addons) {
                        option.addons.forEach((addon, aIdx) => {
                            if (addon.addonName) {
                                if (addonNames.has(addon.addonName)) {
                                    errors.push(`Feature ${fIdx + 1}, Option ${oIdx + 1}: Duplicate addon name: "${addon.addonName}"`);
                                } else {
                                    addonNames.add(addon.addonName);
                                }
                            }
                        });
                    }
                });
            }
        });
        
        return errors;
    }

    /**
     * Validate pricing type consistency
     * @param {Object} option - Option to validate
     * @returns {string|null} Error message or null
     */
    validatePricingTypeConsistency(option) {
        const pricingType = this.extractPricingType(option.pricingType);
        
        if (pricingType === 'static price') {
            if (!option.staticPrice && option.staticPrice !== 0) {
                return 'Static price is required when pricing type is "static price"';
            }
        } else if (pricingType === 'price range') {
            if ((!option.priceFloor && option.priceFloor !== 0) || 
                (!option.priceCeiling && option.priceCeiling !== 0)) {
                return 'Price floor and ceiling are required when pricing type is "price range"';
            }
        } else if (pricingType === 'price options') {
            if (!option.priceOptions) {
                return 'Price options are required when pricing type is "price options"';
            }
        }
        
        return null;
    }

    // Helper methods for extracting data from complex structures

    getFieldValue(obj, fieldPath) {
        return fieldPath.split('.').reduce((current, key) => {
            if (current && typeof current === 'object') {
                if ('ui_type' in current && 'value' in current) {
                    return current.value;
                }
                return current[key];
            }
            return current;
        }, obj);
    }

    extractPricingType(pricingTypeData) {
        if (!pricingTypeData) return null;
        
        if (typeof pricingTypeData === 'string') return pricingTypeData;
        
        if (pricingTypeData.value && Array.isArray(pricingTypeData.value.types)) {
            const selected = pricingTypeData.value.selected || 0;
            return pricingTypeData.value.types[selected];
        }
        
        return null;
    }

    extractPriceOptionsData(priceOptionsData) {
        if (!priceOptionsData) return null;
        
        if (Array.isArray(priceOptionsData)) return priceOptionsData;
        
        if (typeof priceOptionsData === 'string') {
            try {
                return JSON.parse(priceOptionsData);
            } catch (e) {
                return null;
            }
        }
        
        if (priceOptionsData.value && priceOptionsData.value.types) {
            return priceOptionsData.value.types;
        }
        
        return null;
    }

    extractThresholdData(thresholdData) {
        if (!thresholdData) return null;
        
        if (Array.isArray(thresholdData)) return thresholdData;
        
        if (typeof thresholdData === 'string') {
            try {
                const parsed = JSON.parse(thresholdData);
                return Array.isArray(parsed) ? parsed : (parsed.types || null);
            } catch (e) {
                return null;
            }
        }
        
        if (thresholdData.value && thresholdData.value.types) {
            return thresholdData.value.types;
        }
        
        if (thresholdData.types) {
            return thresholdData.types;
        }
        
        return null;
    }

    /**
     * Quick validation for form fields (real-time feedback)
     * @param {string} fieldType - Type of field (feature, option, addon)
     * @param {string} fieldName - Name of the field
     * @param {*} value - Current value
     * @param {Object} context - Additional context for validation
     * @returns {Object} Quick validation result
     */
    quickValidate(fieldType, fieldName, value, context = {}) {
        const rules = this.validationRules[fieldType];
        if (!rules || !rules[fieldName]) {
            return { isValid: true, error: null };
        }
        
        const rule = rules[fieldName];
        const error = this.validateField(fieldName, value, rule, context);
        
        return {
            isValid: !error,
            error
        };
    }
}