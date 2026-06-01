<?php
/**
 * Mobile Featured Image — Framework Model
 *
 * WordPress only supports one native featured image per post. This model
 * adds a parallel "Mobile Featured Image" slot stored in post meta
 * `_firefly_mobile_thumbnail_id`, exposed via REST so a Gutenberg
 * sidebar panel can read/write it through wp.data.
 *
 * Frontend helpers render a <picture> element with the mobile image
 * loaded only on narrow viewports, so the right image downloads per
 * breakpoint (no double-fetch).
 *
 * Schema integration: when `mobile_featured_image` (filename) appears in
 * a post entry in {template}-schema.json, the importer resolves it to an
 * attachment and persists the meta — mirroring how `featured_image`
 * works for the native thumbnail.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'FIREFLY_MOBILE_THUMBNAIL_META_KEY' ) ) {
	define( 'FIREFLY_MOBILE_THUMBNAIL_META_KEY', '_firefly_mobile_thumbnail_id' );
}

// Per-post breakpoint (in px) below which the mobile image is served.
if ( ! defined( 'FIREFLY_MOBILE_THUMBNAIL_BREAKPOINT_META_KEY' ) ) {
	define( 'FIREFLY_MOBILE_THUMBNAIL_BREAKPOINT_META_KEY', '_firefly_mobile_thumbnail_breakpoint' );
}

// Framework default breakpoint in pixels (used when post meta is 0/empty).
if ( ! defined( 'FIREFLY_MOBILE_THUMBNAIL_DEFAULT_BREAKPOINT_PX' ) ) {
	define( 'FIREFLY_MOBILE_THUMBNAIL_DEFAULT_BREAKPOINT_PX', 768 );
}

// =============================================================================
// META REGISTRATION
// =============================================================================

/**
 * Register the mobile thumbnail meta on posts and pages so it's available
 * via the REST API. wp.data in the block editor needs this to read and
 * write the value.
 */
function firefly_register_mobile_thumbnail_meta() {
	$args = array(
		'type'              => 'integer',
		'single'            => true,
		'default'           => 0,
		'show_in_rest'      => true,
		'sanitize_callback' => 'absint',
		'auth_callback'     => function () {
			return current_user_can( 'edit_posts' );
		},
	);

	foreach ( array( 'post', 'page' ) as $post_type ) {
		register_post_meta( $post_type, FIREFLY_MOBILE_THUMBNAIL_META_KEY, $args );
		register_post_meta( $post_type, FIREFLY_MOBILE_THUMBNAIL_BREAKPOINT_META_KEY, $args );
	}
}
add_action( 'init', 'firefly_register_mobile_thumbnail_meta' );

// =============================================================================
// GUTENBERG SIDEBAR ENQUEUE
// =============================================================================

/**
 * Enqueue the mobile thumbnail sidebar panel script + style in the
 * block editor. Lives next to (and registers below) the native
 * Featured Image panel.
 */
function firefly_enqueue_mobile_thumbnail_sidebar() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && method_exists( $screen, 'is_block_editor' ) && ! $screen->is_block_editor() ) {
		return;
	}

	$base_uri = get_template_directory_uri();
	$base_dir = get_template_directory();

	$js_rel  = '/assets/js/mobile-thumbnail-sidebar.js';
	$css_rel = '/assets/css/mobile-thumbnail-sidebar.css';

	wp_enqueue_script(
		'firefly-mobile-thumbnail-sidebar',
		$base_uri . $js_rel,
		array(
			'wp-hooks',         // addFilter
			'wp-compose',       // createHigherOrderComponent
			'wp-editor',        // PostFeaturedImage filter target
			'wp-element',
			'wp-components',
			'wp-data',
			'wp-block-editor',  // MediaUpload / MediaUploadCheck
			'wp-i18n',
		),
		file_exists( $base_dir . $js_rel ) ? filemtime( $base_dir . $js_rel ) : false,
		true
	);

	wp_enqueue_style(
		'firefly-mobile-thumbnail-sidebar',
		$base_uri . $css_rel,
		array(),
		file_exists( $base_dir . $css_rel ) ? filemtime( $base_dir . $css_rel ) : false
	);

	// Expose the framework default breakpoint to the sidebar so the
	// placeholder + help text stay in sync.
	wp_add_inline_script(
		'firefly-mobile-thumbnail-sidebar',
		'window.fireflyMobileThumbnail = ' . wp_json_encode( array(
			'defaultBreakpoint' => (int) FIREFLY_MOBILE_THUMBNAIL_DEFAULT_BREAKPOINT_PX,
		) ) . ';',
		'before'
	);
}
add_action( 'enqueue_block_editor_assets', 'firefly_enqueue_mobile_thumbnail_sidebar' );

// =============================================================================
// PUBLIC HELPERS
// =============================================================================

/**
 * Get the mobile thumbnail attachment ID for a post.
 *
 * @param int|null $post_id Defaults to current post.
 * @return int Attachment ID, or 0 if none set.
 */
function firefly_get_mobile_thumbnail_id( $post_id = null ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id ) {
		return 0;
	}
	return (int) get_post_meta( $post_id, FIREFLY_MOBILE_THUMBNAIL_META_KEY, true );
}

/**
 * Get the mobile thumbnail breakpoint (in px) for a post.
 *
 * Returns the per-post override when set, otherwise the framework default.
 *
 * @param int|null $post_id Defaults to current post.
 * @return int Breakpoint in pixels.
 */
function firefly_get_mobile_thumbnail_breakpoint( $post_id = null ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	$default = (int) FIREFLY_MOBILE_THUMBNAIL_DEFAULT_BREAKPOINT_PX;
	if ( ! $post_id ) {
		return $default;
	}
	$value = (int) get_post_meta( $post_id, FIREFLY_MOBILE_THUMBNAIL_BREAKPOINT_META_KEY, true );
	return $value > 0 ? $value : $default;
}

/**
 * Get the mobile thumbnail URL for a post at a given size.
 *
 * @param int|null    $post_id Defaults to current post.
 * @param string|null $size    Image size (default 'full').
 * @return string URL or empty string.
 */
function firefly_get_mobile_thumbnail_url( $post_id = null, $size = 'full' ) {
	$id = firefly_get_mobile_thumbnail_id( $post_id );
	if ( ! $id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $id, $size );
	return $url ? $url : '';
}

/**
 * Render a <picture> element that swaps the desktop featured image for
 * the mobile thumbnail below FIREFLY_MOBILE_THUMBNAIL_BREAKPOINT.
 *
 * If no mobile thumbnail is set, falls back to a plain <img> using the
 * native featured image — so callers can use this everywhere without
 * branching on the meta.
 *
 * @param int|null    $post_id   Defaults to current post.
 * @param string|null $size      Image size (default 'full').
 * @param array       $img_attrs Extra HTML attributes for the <img> tag
 *                               (alt, class, loading, etc.).
 * @return string HTML, or empty string if no featured image at all.
 */
function firefly_get_responsive_featured_image( $post_id = null, $size = 'full', $img_attrs = array() ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id ) {
		return '';
	}

	$desktop_url = get_the_post_thumbnail_url( $post_id, $size );
	if ( ! $desktop_url ) {
		// No native featured image — fall back to mobile if it exists.
		$mobile_only = firefly_get_mobile_thumbnail_url( $post_id, $size );
		if ( ! $mobile_only ) {
			return '';
		}
		$desktop_url = $mobile_only;
	}

	$mobile_url = firefly_get_mobile_thumbnail_url( $post_id, $size );

	// Default alt to the post title.
	if ( ! isset( $img_attrs['alt'] ) ) {
		$img_attrs['alt'] = get_the_title( $post_id );
	}

	$attr_string = '';
	foreach ( $img_attrs as $key => $value ) {
		$attr_string .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
	}

	if ( ! $mobile_url || $mobile_url === $desktop_url ) {
		// No mobile variant — plain <img>.
		return sprintf( '<img src="%s"%s>', esc_url( $desktop_url ), $attr_string );
	}

	$bp = firefly_get_mobile_thumbnail_breakpoint( $post_id );

	return sprintf(
		'<picture><source media="(max-width: %dpx)" srcset="%s"><img src="%s"%s></picture>',
		(int) $bp,
		esc_url( $mobile_url ),
		esc_url( $desktop_url ),
		$attr_string
	);
}

// =============================================================================
// OPEN GRAPH / SOCIAL SHARING
// =============================================================================

// firefly_get_og_image_url() is defined in seo-meta.php; it already prefers
// the mobile featured image when one is set via firefly_get_mobile_thumbnail_url().
// The Yoast/RankMath filters below call into it.

/**
 * Get the OG image attachment ID, honoring the mobile featured image
 * preference. Useful when an SEO plugin filter expects an attachment ID
 * (e.g. Yoast's wpseo_opengraph_image_id).
 *
 * @param int|null $post_id Defaults to current post.
 * @return int Attachment ID, or 0 if none set.
 */
function firefly_get_og_image_id( $post_id = null ) {
	if ( ! $post_id ) {
		$post_id = get_the_ID();
	}
	if ( ! $post_id ) {
		return 0;
	}
	$mobile_id = firefly_get_mobile_thumbnail_id( $post_id );
	if ( $mobile_id ) {
		return $mobile_id;
	}
	return (int) get_post_thumbnail_id( $post_id );
}

// Yoast SEO integration — runs only when Yoast is installed and the
// filter actually fires. Returns the mobile image URL/ID when applicable.
add_filter( 'wpseo_opengraph_image', function ( $url ) {
	$override = firefly_get_og_image_url();
	return $override ? $override : $url;
}, 5 );

add_filter( 'wpseo_opengraph_image_id', function ( $id ) {
	$override = firefly_get_og_image_id();
	return $override ? $override : $id;
}, 5 );

add_filter( 'wpseo_twitter_image', function ( $url ) {
	$override = firefly_get_og_image_url();
	return $override ? $override : $url;
}, 5 );

// RankMath SEO integration — same pattern.
add_filter( 'rank_math/opengraph/facebook/image', function ( $url ) {
	$override = firefly_get_og_image_url();
	return $override ? $override : $url;
}, 5 );

add_filter( 'rank_math/opengraph/twitter/image', function ( $url ) {
	$override = firefly_get_og_image_url();
	return $override ? $override : $url;
}, 5 );

// =============================================================================
// SCHEMA INTEGRATION
// =============================================================================

/**
 * When schema-driven post import sets a featured image, also look for a
 * `mobile_featured_image` filename in the same entry and persist it to
 * meta. Hooks the existing import flow without modifying template-schema.php.
 *
 * The importer in firefly_create_template_posts() doesn't expose a per-
 * post filter, so we listen for save_post on posts that have the
 * template meta key and look up the corresponding schema entry.
 */
function firefly_apply_mobile_thumbnail_from_schema( $post_id ) {
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
		return;
	}

	$template = get_post_meta( $post_id, FIREFLY_TEMPLATE_META_KEY, true );
	if ( ! $template ) {
		return;
	}

	if ( ! function_exists( 'firefly_get_template_schema' ) ) {
		return;
	}

	$schema = firefly_get_template_schema( $template );
	if ( ! $schema ) {
		return;
	}

	$key  = ( 'post' === $post_type ) ? 'posts' : 'pages';
	$slug = get_post_field( 'post_name', $post_id );

	if ( empty( $schema[ $key ] ) || ! is_array( $schema[ $key ] ) ) {
		return;
	}

	$entry = null;
	foreach ( $schema[ $key ] as $candidate ) {
		if ( isset( $candidate['slug'] ) && $candidate['slug'] === $slug ) {
			$entry = $candidate;
			break;
		}
	}
	if ( ! $entry || empty( $entry['mobile_featured_image'] ) ) {
		return;
	}

	if ( ! function_exists( 'firefly_get_attachment_by_filename' ) ) {
		return;
	}

	$attachment_id = firefly_get_attachment_by_filename( $entry['mobile_featured_image'] );
	if ( $attachment_id ) {
		update_post_meta( $post_id, FIREFLY_MOBILE_THUMBNAIL_META_KEY, (int) $attachment_id );
	}

	// Optional per-post breakpoint override from schema.
	if ( isset( $entry['mobile_featured_image_breakpoint'] ) ) {
		$bp = (int) $entry['mobile_featured_image_breakpoint'];
		if ( $bp > 0 ) {
			update_post_meta( $post_id, FIREFLY_MOBILE_THUMBNAIL_BREAKPOINT_META_KEY, $bp );
		}
	}
}
add_action( 'save_post', 'firefly_apply_mobile_thumbnail_from_schema', 30 );
