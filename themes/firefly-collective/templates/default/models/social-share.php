<?php

// Social Share Model
// Provides reusable functions for rendering social media share buttons

// Ensure no direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Render social share buttons for a post
 *
 * @param int $post_id Optional post ID. Defaults to current post.
 * @return void Outputs HTML for share buttons
 */
function render_social_share_buttons($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }

    // Get post data
    $post_url = get_permalink($post_id);
    $post_title = get_the_title($post_id);

    // Extract hashtags from categories (first 3 categories)
    $categories = get_the_category($post_id);
    $hashtags = array();
    if ($categories && count($categories) > 0) {
        foreach (array_slice($categories, 0, 3) as $category) {
            $hashtags[] = str_replace(array(' ', '-'), '', $category->name);
        }
    }
    $hashtag_string = !empty($hashtags) ? implode(',', $hashtags) : '';

    // Encode URL and text for sharing
    $encoded_url = urlencode($post_url);
    $encoded_title = urlencode($post_title);

    // Build share URLs for each platform (privacy-friendly, no tracking)
    $facebook_url = 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url;
    $twitter_url = 'https://twitter.com/intent/tweet?url=' . $encoded_url . '&text=' . $encoded_title;
    if (!empty($hashtag_string)) {
        $twitter_url .= '&hashtags=' . urlencode($hashtag_string);
    }
    $linkedin_url = 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encoded_url;
    $bluesky_url = 'https://bsky.app/intent/compose?text=' . $encoded_title . '%20' . $encoded_url;

    // Output share buttons HTML
    ?>
    <div class="blog-post-share">
        <a href="<?php echo esc_url($facebook_url); ?>"
           class="share-button"
           data-share="facebook"
           title="Share on Facebook"
           aria-label="Share this post on Facebook"
           target="_blank"
           rel="noopener noreferrer">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
        </a>
        <a href="<?php echo esc_url($twitter_url); ?>"
           class="share-button"
           data-share="twitter"
           title="Share on X (Twitter)"
           aria-label="Share this post on X (formerly Twitter)"
           target="_blank"
           rel="noopener noreferrer">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
            </svg>
        </a>
        <a href="<?php echo esc_url($linkedin_url); ?>"
           class="share-button"
           data-share="linkedin"
           title="Share on LinkedIn"
           aria-label="Share this post on LinkedIn"
           target="_blank"
           rel="noopener noreferrer">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
            </svg>
        </a>
        <a href="<?php echo esc_url($bluesky_url); ?>"
           class="share-button"
           data-share="bluesky"
           title="Share on Bluesky"
           aria-label="Share this post on Bluesky"
           target="_blank"
           rel="noopener noreferrer">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.039.415-.056-.138.022-.276.04-.415.056-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.056c.14.017.279.036.415.056 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.478 0-.69-.139-1.861-.902-2.206-.659-.298-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8Z"/>
            </svg>
        </a>
    </div>
    <?php
}
