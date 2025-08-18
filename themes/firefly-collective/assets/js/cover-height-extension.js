// theme/assets/js/cover-height-extension.js

(function() {
	'use strict';

	// Import required WordPress dependencies
	const { createHigherOrderComponent } = wp.compose;
	const { Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor;
	const { SelectControl } = wp.components;
	const { addFilter } = wp.hooks;
	const { useSelect } = wp.data;

	/**
	 * Add height preset dropdown to Cover block inspector controls
	 */
	const withCoverHeightPreset = createHigherOrderComponent((BlockEdit) => {
		return (props) => {
			// Only apply to core/cover block
			if (props.name !== 'core/cover') {
				return wp.element.createElement(BlockEdit, props);
			}

			const { attributes, setAttributes, clientId } = props;
			const { heightPreset } = attributes;

			// Check if this is a top-level Cover block OR inside a top-level Columns block
			const isTopLevel = useSelect((select) => {
				const { getBlockParents, getBlockName } = select('core/block-editor');
				const parents = getBlockParents(clientId);
				
				// If no parents, it's top-level
				if (parents.length === 0) {
					return true;
				}
				
				// Check if this Cover is directly inside a Column that's inside a top-level Columns block
				if (parents.length <= 2) {
					const parentBlockNames = parents.map(parentId => getBlockName(parentId));
					
					// Allow if: Cover → Column → Columns (at root level)
					if (parentBlockNames.includes('core/column') && parentBlockNames.includes('core/columns')) {
						// Make sure the Columns block is at the top level (no more than 2 levels deep)
						return parents.length === 2;
					}
				}
				
				// Check if any parent is a Cover block (nested cover)
				// If so, this is not a top-level cover
				for (let parentId of parents) {
					const parentBlockName = getBlockName(parentId);
					if (parentBlockName === 'core/cover') {
						return false;
					}
				}
				
				return true;
			}, [clientId]);

			// Height preset options
			const heightOptions = [
				{ label: 'Full Height (100vh)', value: 'full' },
				{ label: 'Half Height (50vh)', value: 'half' }
			];

			return wp.element.createElement(
				Fragment,
				{},
				wp.element.createElement(BlockEdit, props),
				// Only show controls for top-level Cover blocks
				isTopLevel && wp.element.createElement(
					InspectorControls,
					{ group: 'dimensions' },
					wp.element.createElement(SelectControl, {
						label: 'Height Preset',
						value: heightPreset || 'full',
						options: heightOptions,
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
						onChange: (value) => {
							setAttributes({ heightPreset: value });
							
							// Set height properties based on preset
							if (value === 'full') {
								setAttributes({
									style: {
										...attributes.style,
										dimensions: {
											...attributes.style?.dimensions,
											minHeight: '100vh'
										}
									}
								});
							} else if (value === 'half') {
								setAttributes({
									style: {
										...attributes.style,
										dimensions: {
											...attributes.style?.dimensions,
											minHeight: '50vh'
										}
									}
								});
							}
						},
						help: 'Choose a height preset for this cover block.'
					})
				)
			);
		};
	}, 'withCoverHeightPreset');

	// Apply the higher-order component to all blocks
	addFilter(
		'editor.BlockEdit',
		'cover-height-preset/with-inspector-controls',
		withCoverHeightPreset
	);

	// Add classes to the block wrapper in the editor
	const withCoverHeightPresetClassName = createHigherOrderComponent((BlockListBlock) => {
		return (props) => {
			const { name, attributes } = props;
			
			// Only apply to cover blocks
			if (name !== 'core/cover') {
				return wp.element.createElement(BlockListBlock, props);
			}
			
			const { heightPreset } = attributes;
			let className = props.className || '';
			
			// Add the appropriate class based on heightPreset
			if (heightPreset === 'full') {
				className = className + ' height-preset-full';
			} else if (heightPreset === 'half') {
				className = className + ' height-preset-half';
			}
			
			return wp.element.createElement(BlockListBlock, {
				...props,
				className: className
			});
		};
	}, 'withCoverHeightPresetClassName');

	// Apply the className filter for the editor
	addFilter(
		'editor.BlockListBlock',
		'cover-height-preset/with-class-name',
		withCoverHeightPresetClassName
	);

	// Also add classes to saved content (this works with your PHP filter)
	addFilter(
		'blocks.getSaveContent.extraProps',
		'cover-height-preset/add-save-props',
		function(extraProps, blockType, attributes) {
			// Only apply to cover blocks
			if (blockType.name !== 'core/cover') {
				return extraProps;
			}
			
			const { heightPreset } = attributes;
			
			if (heightPreset === 'full') {
				extraProps.className = extraProps.className 
					? extraProps.className + ' height-preset-full'
					: 'height-preset-full';
			} else if (heightPreset === 'half') {
				extraProps.className = extraProps.className 
					? extraProps.className + ' height-preset-half'
					: 'height-preset-half';
			}
			
			return extraProps;
		}
	);

})();