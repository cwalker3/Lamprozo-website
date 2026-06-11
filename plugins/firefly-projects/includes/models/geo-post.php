<?php
/**
 * Firefly Projects - GEO Post Settings
 *
 * Adds LLM optimization fields (GEO - Generative Engine Optimization) to blog posts
 * via Gutenberg sidebar panel and custom FAQ block.
 *
 * @package FireflyProjects
 * @since 1.0.22
 */

// Ensure no direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register GEO post meta fields
 * Called from firefly_projects_register_meta() in main plugin file
 */
function firefly_projects_register_geo_meta() {
    // AI Summary - 120 word answer capsule for LLMs
    register_post_meta('post', '_geo_summary', array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_textarea_field',
        'auth_callback'     => function() {
            return current_user_can('edit_posts');
        }
    ));

    // Key Facts - JSON array of {fact, source}
    register_post_meta('post', '_geo_key_facts', array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => function() {
            return current_user_can('edit_posts');
        }
    ));

    // Article Type - BlogPosting, HowTo, NewsArticle
    register_post_meta('post', '_geo_article_type', array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'default'           => 'BlogPosting',
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => function() {
            return current_user_can('edit_posts');
        }
    ));

    // FAQ - JSON array of {question, answer}
    register_post_meta('post', '_geo_faq', array(
        'type'              => 'string',
        'single'            => true,
        'show_in_rest'      => true,
        'sanitize_callback' => 'sanitize_text_field',
        'auth_callback'     => function() {
            return current_user_can('edit_posts');
        }
    ));
}

/**
 * Register FAQ block type
 */
function firefly_projects_register_faq_block() {
    register_block_type('firefly/faq', array(
        // Match the JS-side apiVersion: 3 so the server registration
        // doesn't fall back to v1 metadata defaults.
        'api_version'     => 3,
        'render_callback' => 'firefly_projects_render_faq_block',
        'attributes'      => array(
            'faqs' => array(
                'type'    => 'array',
                'default' => array(),
                'items'   => array(
                    'type' => 'object'
                )
            )
        )
    ));
}
add_action('init', 'firefly_projects_register_faq_block');

/**
 * Render FAQ block on frontend
 *
 * @param array $attributes Block attributes
 * @return string HTML output
 */
function firefly_projects_render_faq_block($attributes) {
    $faqs = isset($attributes['faqs']) ? $attributes['faqs'] : array();

    if (empty($faqs)) {
        return '';
    }

    ob_start();
    ?>
    <div class="firefly-faq-block" itemscope itemtype="https://schema.org/FAQPage">
        <h3 class="faq-heading">Frequently Asked Questions</h3>
        <?php foreach ($faqs as $i => $faq): ?>
        <div class="faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
            <button class="faq-question" aria-expanded="false" aria-controls="faq-answer-<?php echo esc_attr($i); ?>" itemprop="name">
                <span class="faq-question-text"><?php echo esc_html($faq['question'] ?? ''); ?></span>
                <span class="faq-icon" aria-hidden="true"></span>
            </button>
            <div class="faq-answer" id="faq-answer-<?php echo esc_attr($i); ?>" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer" hidden>
                <div itemprop="text"><?php echo wp_kses_post($faq['answer'] ?? ''); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <script>
    (function() {
        document.querySelectorAll('.firefly-faq-block .faq-question').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var expanded = this.getAttribute('aria-expanded') === 'true';
                var answer = document.getElementById(this.getAttribute('aria-controls'));
                this.setAttribute('aria-expanded', !expanded);
                answer.hidden = expanded;
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Sync FAQ block content to post meta on save
 * This allows schema generation from meta even when FAQ block is used
 *
 * @param int     $post_id Post ID
 * @param WP_Post $post    Post object
 */
function firefly_projects_sync_faq_block_to_meta($post_id, $post) {
    if ($post->post_type !== 'post') {
        return;
    }

    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Parse blocks to find FAQ block
    $blocks = parse_blocks($post->post_content);
    $all_faqs = array();

    foreach ($blocks as $block) {
        if ($block['blockName'] === 'firefly/faq') {
            $faqs = isset($block['attrs']['faqs']) ? $block['attrs']['faqs'] : array();
            $all_faqs = array_merge($all_faqs, $faqs);
        }
    }

    // Only update if we found FAQ blocks (don't clear existing meta from sidebar)
    if (!empty($all_faqs)) {
        update_post_meta($post_id, '_geo_faq', json_encode($all_faqs));
    }
}
add_action('save_post', 'firefly_projects_sync_faq_block_to_meta', 10, 2);
