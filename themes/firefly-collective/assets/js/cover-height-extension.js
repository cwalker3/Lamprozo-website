// theme/assets/js/cover-height-extension.js

(function() {
	'use strict';

	// Import required WordPress dependencies
	const { createHigherOrderComponent } = wp.compose;
	const { Fragment } = wp.element;
	const { InspectorControls } = wp.blockEditor || wp.editor;
	const { SelectControl } = wp.components;
	const { addFilter } = wp.hooks;
	const { useSelect } = wp.data;

	/**
	 * 1) Register the custom attribute on the client, BUT with **no default**
	 *    to avoid invalidating or altering markup for existing content.
	 */
	addFilter(
		'blocks.registerBlockType',
		'cover-height-preset/register-attr',
		(settings, name) => {
			if (name !== 'core/cover') {
				return settings;
			}
			return {
				...settings,
				attributes: {
					...settings.attributes,
					heightPreset: {
						type: 'string',
						enum: ['full', 'half'],
						// IMPORTANT: no default value
					},
				},
			};
		}
	);

	/**
	 * 2) Rehydrate the attribute from existing classes when loading content
	 *    Keeps the editor UI (dropdown) in sync with what’s already in markup.
	 */
	addFilter(
		'blocks.getBlockAttributes',
		'cover-height-preset/rehydrate-from-class',
		(blockAttributes, blockType /*, innerHTML, knownAttrs */) => {
			if (!blockType || blockType.name !== 'core/cover') {
				return blockAttributes;
			}
			const cls = blockAttributes?.className || '';
			if (cls.includes('height-preset-half')) {
				blockAttributes.heightPreset = 'half';
			} else if (cls.includes('height-preset-full')) {
				blockAttributes.heightPreset = 'full';
			}
			return blockAttributes;
		}
	);

	/**
	 * 3) Add height preset dropdown to Cover block inspector controls
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

				// Allow if: Cover → Column → Columns at root level
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

	// Apply the inspector control filter
	addFilter(
		'editor.BlockEdit',
		'cover-height-preset/with-inspector-controls',
		withCoverHeightPreset
	);

	/**
	 * 4) Add the appropriate class name in the editor,
	 *    so you see the effect live when toggling.
	 */
	const withCoverHeightPresetClassName = createHigherOrderComponent((BlockListBlock) => {
		return (props) => {
			const { name, attributes } = props;

			if (name !== 'core/cover') {
				return wp.element.createElement(BlockListBlock, props);
			}

			const { heightPreset } = attributes;
			let className = props.className || '';

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

	addFilter(
		'editor.BlockListBlock',
		'cover-height-preset/with-class-name',
		withCoverHeightPresetClassName
	);

	/**
	 * 5) Add classes to the saved content (only if heightPreset is set),
	 *    preserving validation for legacy blocks.
	 */
	addFilter(
		'blocks.getSaveContent.extraProps',
		'cover-height-preset/add-save-props',
		function(extraProps, blockType, attributes) {
			if (blockType.name !== 'core/cover') {
				return extraProps;
			}

			const { heightPreset } = attributes;
			if (!heightPreset) {
				return extraProps;
			}

			const cls = extraProps.className ? extraProps.className + ' ' : '';
			if (heightPreset === 'full') {
				extraProps.className = cls + 'height-preset-full';
			} else if (heightPreset === 'half') {
				extraProps.className = cls + 'height-preset-half';
			}

			return extraProps;
		}
	);

})();
