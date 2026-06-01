/**
 * Firefly Mobile Featured Image — Gutenberg sidebar UI
 *
 * Injects a "Mobile Featured Image" picker directly below the native
 * Featured Image inside its own panel — using the editor.PostFeaturedImage
 * HoC filter so the position is deterministic (right below the desktop
 * featured image, inside the same "Featured image" panel).
 *
 * Two post-meta fields:
 *   _firefly_mobile_thumbnail_id          — selected attachment ID
 *   _firefly_mobile_thumbnail_breakpoint  — viewport width (px) below
 *                                            which the mobile image is
 *                                            served. 0 = framework default.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.compose ) {
		return;
	}

	const META_KEY        = '_firefly_mobile_thumbnail_id';
	const BREAKPOINT_KEY  = '_firefly_mobile_thumbnail_breakpoint';
	// Falls back to whatever the PHP layer considers the default (currently 768).
	const DEFAULT_BREAKPOINT = ( window.fireflyMobileThumbnail && parseInt( window.fireflyMobileThumbnail.defaultBreakpoint, 10 ) ) || 768;

	const { addFilter }                     = wp.hooks;
	const { createHigherOrderComponent }    = wp.compose;
	const { createElement: el, Fragment }   = wp.element;
	const { Button, TextControl }           = wp.components;
	const { useSelect, useDispatch }        = wp.data;
	const { __, sprintf }                   = wp.i18n;

	const MediaUpload =
		( wp.blockEditor && wp.blockEditor.MediaUpload ) ||
		( wp.editor && wp.editor.MediaUpload );

	const MediaUploadCheck =
		( wp.blockEditor && wp.blockEditor.MediaUploadCheck ) ||
		( wp.editor && wp.editor.MediaUploadCheck );

	if ( ! MediaUpload || ! MediaUploadCheck ) {
		// eslint-disable-next-line no-console
		console.warn( '[firefly-mobile-thumbnail] MediaUpload not available' );
		return;
	}

	function MobileThumbnailField() {
		const attachmentId = useSelect( ( select ) => {
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
			const v = meta && meta[ META_KEY ];
			return v ? parseInt( v, 10 ) : 0;
		}, [] );

		const breakpoint = useSelect( ( select ) => {
			const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' );
			const v = meta && meta[ BREAKPOINT_KEY ];
			return v ? parseInt( v, 10 ) : 0; // 0 = use framework default
		}, [] );

		const attachment = useSelect( ( select ) => {
			if ( ! attachmentId ) return null;
			return select( 'core' ).getMedia( attachmentId );
		}, [ attachmentId ] );

		const { editPost } = useDispatch( 'core/editor' );

		const setId = ( id ) => {
			editPost( { meta: { [ META_KEY ]: id ? parseInt( id, 10 ) : 0 } } );
		};

		const setBreakpoint = ( val ) => {
			const num = parseInt( val, 10 );
			editPost( { meta: { [ BREAKPOINT_KEY ]: isFinite( num ) && num > 0 ? num : 0 } } );
		};

		const renderPicker = ( { open } ) => {
			if ( ! attachmentId ) {
				return el(
					Button,
					{
						variant: 'secondary',
						className: 'firefly-mobile-thumbnail__set',
						onClick: open,
					},
					__( 'Set mobile featured image', 'firefly' )
				);
			}

			const sizes = attachment && attachment.media_details && attachment.media_details.sizes;
			const url =
				( sizes && sizes.medium && sizes.medium.source_url ) ||
				( attachment && attachment.source_url ) ||
				'';

			return el(
				'div',
				{ className: 'firefly-mobile-thumbnail__display' },
				url && el( 'img', {
					className: 'firefly-mobile-thumbnail__image',
					src: url,
					alt: '',
				} ),
				el(
					'div',
					{ className: 'firefly-mobile-thumbnail__hover' },
					el(
						Button,
						{
							variant: 'secondary',
							className: 'firefly-mobile-thumbnail__btn',
							onClick: open,
						},
						__( 'Replace', 'firefly' )
					),
					el(
						Button,
						{
							variant: 'secondary',
							isDestructive: true,
							className: 'firefly-mobile-thumbnail__btn',
							onClick: () => setId( 0 ),
						},
						__( 'Remove', 'firefly' )
					)
				)
			);
		};

		const placeholderHelp = sprintf(
			/* translators: %d is the default breakpoint in pixels */
			__( 'Mobile image shows below this viewport width. Default: %dpx.', 'firefly' ),
			DEFAULT_BREAKPOINT
		);

		return el(
			'div',
			{ className: 'firefly-mobile-thumbnail-section' },
			el(
				'h3',
				{ className: 'firefly-mobile-thumbnail__label' },
				__( 'Mobile Featured Image', 'firefly' )
			),
			el(
				'p',
				{ className: 'firefly-mobile-thumbnail__help' },
				__( 'Shown on narrow viewports in place of the featured image.', 'firefly' )
			),
			el(
				MediaUploadCheck,
				null,
				el( MediaUpload, {
					onSelect:     ( media ) => setId( media && media.id ),
					allowedTypes: [ 'image' ],
					value:        attachmentId,
					render:       renderPicker,
				} )
			),
			// Breakpoint input — only meaningful when an image is selected.
			attachmentId
				? el(
					'div',
					{ className: 'firefly-mobile-thumbnail__breakpoint' },
					el( TextControl, {
						label:       __( 'Breakpoint (px)', 'firefly' ),
						type:        'number',
						min:         1,
						max:         9999,
						value:       breakpoint || '',
						placeholder: String( DEFAULT_BREAKPOINT ),
						help:        placeholderHelp,
						onChange:    setBreakpoint,
					} )
				)
				: null
		);
	}

	const withMobileThumbnail = createHigherOrderComponent( ( OriginalComponent ) => {
		return ( props ) => {
			return el(
				Fragment,
				null,
				el( OriginalComponent, props ),
				el( MobileThumbnailField, null )
			);
		};
	}, 'withMobileThumbnail' );

	addFilter(
		'editor.PostFeaturedImage',
		'firefly/mobile-thumbnail',
		withMobileThumbnail
	);
} )( window.wp );
