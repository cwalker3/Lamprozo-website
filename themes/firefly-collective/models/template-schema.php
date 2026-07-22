<?php
/**
 * Template Schema System
 *
 * Reads template definitions from JSON schema files and creates content from snippets.
 * This is the core of the schema-driven template content system.
 */

// =============================================================================
// SCHEMA READING
// =============================================================================

/**
 * Read schema for a template.
 */
function firefly_get_template_schema($template) {
    $path = get_template_directory() . "/data/schemas/{$template}-schema.json";

    if (!file_exists($path)) {
        return null;
    }

    $json = file_get_contents($path);
    $schema = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $schema;
}

/**
 * Get page structure from schema.
 * This replaces the hardcoded get_theme_pages_structure() function.
 */
function firefly_get_schema_pages($template) {
    $schema = firefly_get_template_schema($template);

    if (!$schema || !isset($schema['pages'])) {
        return array();
    }

    $pages = array();
    foreach ($schema['pages'] as $page) {
        $content = firefly_load_snippet_content($template, $page);
        $pages[$page['slug']] = array(
            'title'      => $page['title'],
            'content'    => $content,
            'in_menu'    => isset($page['in_menu']) ? $page['in_menu'] : true,
            'menu_order' => isset($page['menu_order']) ? $page['menu_order'] : 0,
            'set_as'     => isset($page['set_as']) ? $page['set_as'] : null,
            'required'   => isset($page['required']) ? $page['required'] : false
        );
    }

    return $pages;
}

/**
 * Load snippet content with optional appends and replacements.
 * Snippets are organized in pages/ and posts/ subfolders.
 */
function firefly_load_snippet_content($template, $content_def, $type = 'pages') {
    $base_path = get_template_directory() . "/templates/{$template}/snippets/{$type}/";
    $content = '';

    // Load primary snippet
    if (isset($content_def['snippet']) && !empty($content_def['snippet'])) {
        $snippet_path = $base_path . $content_def['snippet'];
        if (file_exists($snippet_path)) {
            $content = file_get_contents($snippet_path);
        }
    }

    // Append secondary snippet
    if (isset($content_def['snippet_append']) && !empty($content_def['snippet_append'])) {
        $append_path = $base_path . $content_def['snippet_append'];
        if (file_exists($append_path)) {
            $content .= file_get_contents($append_path);
        }
    }

    // Apply content replacements
    if (isset($content_def['content_replace']) && is_array($content_def['content_replace'])) {
        foreach ($content_def['content_replace'] as $search => $replace) {
            $content = str_replace($search, $replace, $content);
        }
    }

    // Fallback to static content if no snippet
    if (empty($content) && isset($content_def['content'])) {
        $content = $content_def['content'];
    }

    // Absolutize relative URLs so Gutenberg block validation passes.
    // Export strips the domain; import must restore it.
    if (!empty($content)) {
        $site_url = untrailingslashit(home_url());
        // Match src="/ href="/ url":"/ (covers HTML attributes and block JSON comments)
        $content = preg_replace(
            '#((?:src|href|url)=["\'])(/wp-content/)#',
            '$1' . $site_url . '$2',
            $content
        );
        // Also handle JSON attributes in block comments: "url":"/wp-content/..."
        $content = preg_replace(
            '#("url":")(/wp-content/)#',
            '$1' . $site_url . '$2',
            $content
        );
    }

    return $content;
}

// =============================================================================
// PAGE SLUG HANDLING
// =============================================================================

/**
 * Get the actual slug for a page.
 * Firefly uses base slugs, other templates get suffixed.
 */
function firefly_get_page_slug($base_slug, $template) {
    // All templates use base slugs — template scoping is handled by _firefly_template meta
    return $base_slug;
}

/**
 * Get the base slug from an actual slug.
 */
function firefly_get_base_slug($actual_slug, $template) {
    // All templates use base slugs — no suffix to strip
    return $actual_slug;
}

// =============================================================================
// SCOPED PAGE LOOKUP
// =============================================================================

/**
 * Find page by base slug AND template.
 * This replaces get_page_by_path() for template-scoped lookups.
 */
function firefly_get_scoped_page($base_slug, $template = null) {
    if (!$template) {
        $template = firefly_get_scoping_template();
    }

    $actual_slug = firefly_get_page_slug($base_slug, $template);

    // Direct query since multiple pages can share the same slug
    global $wpdb;
    $page_id = $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'page'
           AND p.post_name = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s
         LIMIT 1",
        $actual_slug,
        FIREFLY_TEMPLATE_META_KEY,
        $template
    ));

    // Fallback: check for WordPress dedup suffixes (-2, -3, etc.)
    if (!$page_id) {
        $page_id = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = 'page'
               AND p.post_name RLIKE CONCAT('^', %s, '-[0-9]+$')
               AND pm.meta_key = %s
               AND pm.meta_value = %s
             ORDER BY p.ID ASC
             LIMIT 1",
            $actual_slug,
            FIREFLY_TEMPLATE_META_KEY,
            $template
        ));

        // Fix the slug back to the canonical base slug
        if ($page_id) {
            $wpdb->update($wpdb->posts, array('post_name' => $actual_slug), array('ID' => $page_id));
            clean_post_cache($page_id);
        }
    }

    return $page_id ? get_post($page_id) : null;
}

/**
 * Get page ID by base slug and template.
 */
function firefly_get_scoped_page_id($base_slug, $template = null) {
    $page = firefly_get_scoped_page($base_slug, $template);
    return $page ? $page->ID : 0;
}

// =============================================================================
// PAGE CREATION FROM SCHEMA
// =============================================================================

/**
 * Create all pages for a template from its schema.
 */
function firefly_create_template_pages($template) {
    $pages = firefly_get_schema_pages($template);
    $page_ids = array();

    foreach ($pages as $base_slug => $data) {
        $actual_slug = firefly_get_page_slug($base_slug, $template);

        // Check if page already exists
        $existing = firefly_get_scoped_page($base_slug, $template);

        if (!$existing) {
            $page_id = wp_insert_post(array(
                'post_title'   => $data['title'],
                'post_content' => $data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $actual_slug,
                'menu_order'   => $data['menu_order']
            ));

            if (!is_wp_error($page_id)) {
                // Force the correct slug — WordPress may have appended -2 etc.
                // No unique constraint on post_name in DB; scoping is via meta.
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    array('post_name' => $actual_slug),
                    array('ID' => $page_id)
                );
                clean_post_cache($page_id);

                update_post_meta($page_id, FIREFLY_TEMPLATE_META_KEY, $template);
                update_post_meta($page_id, '_firefly_page_id', $template . ':' . $base_slug);
                $page_ids[$base_slug] = array('id' => $page_id, 'status' => 'created');
            } else {
                $page_ids[$base_slug] = array('id' => 0, 'status' => 'error', 'error' => $page_id->get_error_message());
            }
        } else {
            $page_id = $existing->ID;

            // Always update content from snippet on reimport
            // Use $wpdb->update instead of wp_update_post to bypass WordPress
            // slug deduplication — template scoping allows shared slugs.
            global $wpdb;
            if (!empty($data['content'])) {
                $old_hash = md5($existing->post_content);
                $new_hash = md5($data['content']);

                if ($old_hash !== $new_hash) {
                    $result = $wpdb->update(
                        $wpdb->posts,
                        array('post_content' => $data['content']),
                        array('ID' => $page_id)
                    );

                    if ($result === false) {
                        $page_ids[$base_slug] = array('id' => $page_id, 'status' => 'error', 'error' => $wpdb->last_error);
                    } else {
                        $page_ids[$base_slug] = array('id' => $page_id, 'status' => 'updated');
                    }
                } else {
                    $page_ids[$base_slug] = array('id' => $page_id, 'status' => 'unchanged');
                }
            } else {
                $page_ids[$base_slug] = array('id' => $page_id, 'status' => 'empty_snippet');
            }

            // Fix slug if WordPress dedup mangled it
            if ($existing->post_name !== $actual_slug) {
                $wpdb->update($wpdb->posts, array('post_name' => $actual_slug), array('ID' => $page_id));
            }
            clean_post_cache($page_id);

            // Backfill _firefly_page_id for existing pages
            $existing_fpid = get_post_meta($page_id, '_firefly_page_id', true);
            if (empty($existing_fpid)) {
                update_post_meta($page_id, '_firefly_page_id', $template . ':' . $base_slug);
            }
        }
    }

    // Set front page / posts page options per template
    foreach ($pages as $slug => $data) {
        $id = isset($page_ids[$slug]['id']) ? $page_ids[$slug]['id'] : 0;
        if (!empty($data['set_as']) && $id) {
            if ($data['set_as'] === 'front_page') {
                update_option("firefly_front_page_{$template}", $id);
            } elseif ($data['set_as'] === 'posts_page') {
                update_option("firefly_posts_page_{$template}", $id);
            }
        }
    }

    return $page_ids;
}

/**
 * Delete all pages for a template.
 */
function firefly_delete_template_pages($template) {
    $pages = get_posts(array(
        'post_type'      => 'page',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_query'     => array(
            array(
                'key'     => FIREFLY_TEMPLATE_META_KEY,
                'value'   => $template,
                'compare' => '='
            )
        ),
        'firefly_skip_scoping' => true
    ));

    $deleted = 0;
    foreach ($pages as $page) {
        if (wp_delete_post($page->ID, true)) {
            $deleted++;
        }
    }

    // Clean up options
    delete_option("firefly_front_page_{$template}");
    delete_option("firefly_posts_page_{$template}");

    return $deleted;
}

// =============================================================================
// POST CREATION FROM SCHEMA
// =============================================================================

/**
 * Get posts structure from schema.
 */
function firefly_get_schema_posts($template) {
    $schema = firefly_get_template_schema($template);

    if (!$schema || !isset($schema['posts'])) {
        return array();
    }

    $posts = array();
    foreach ($schema['posts'] as $post) {
        $content = firefly_load_snippet_content($template, $post, 'posts');
        $posts[$post['slug']] = array(
            'title'          => $post['title'],
            'content'        => $content,
            'category'       => isset($post['category']) ? $post['category'] : 'uncategorized',
            'geo'            => isset($post['geo']) ? $post['geo'] : null,
            'featured_image' => isset($post['featured_image']) ? $post['featured_image'] : null
        );
    }

    return $posts;
}

/**
 * Find post by base slug AND template.
 * Uses direct SQL since multiple posts can share the same slug.
 */
function firefly_get_scoped_post($base_slug, $template = null) {
    if (!$template) {
        $template = firefly_get_scoping_template();
    }

    $actual_slug = firefly_get_page_slug($base_slug, $template);

    // Direct query since multiple posts can share the same slug
    global $wpdb;
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = 'post'
           AND p.post_name = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s
         LIMIT 1",
        $actual_slug,
        FIREFLY_TEMPLATE_META_KEY,
        $template
    ));

    return $post_id ? get_post($post_id) : null;
}

/**
 * Create all posts for a template from its schema.
 */
function firefly_create_template_posts($template) {
    $posts = firefly_get_schema_posts($template);
    $post_ids = array();

    foreach ($posts as $base_slug => $data) {
        $actual_slug = firefly_get_page_slug($base_slug, $template);

        // Check if post already exists
        $existing = firefly_get_scoped_post($base_slug, $template);

        if (!$existing) {
            $post_id = wp_insert_post(array(
                'post_title'   => $data['title'],
                'post_content' => $data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_name'    => $actual_slug
            ));

            if (!is_wp_error($post_id)) {
                // Force the correct slug — WordPress may have appended -2 etc.
                global $wpdb;
                $wpdb->update(
                    $wpdb->posts,
                    array('post_name' => $actual_slug),
                    array('ID' => $post_id)
                );
                clean_post_cache($post_id);

                update_post_meta($post_id, FIREFLY_TEMPLATE_META_KEY, $template);

                // Assign category if specified
                if (!empty($data['category'])) {
                    $cat_slug = $template === 'default' ? $data['category'] : "{$data['category']}-{$template}";
                    $category = get_term_by('slug', $cat_slug, 'category');
                    if ($category) {
                        wp_set_post_categories($post_id, array($category->term_id));
                    }
                }

                firefly_apply_schema_post_extras($post_id, $data);

                $post_ids[$base_slug] = array('id' => $post_id, 'status' => 'created');
            } else {
                $post_ids[$base_slug] = array('id' => 0, 'status' => 'error', 'error' => $post_id->get_error_message());
            }
        } else {
            $post_id = $existing->ID;

            // Update content from snippet on reimport (same as pages)
            global $wpdb;
            if (!empty($data['content'])) {
                $old_hash = md5($existing->post_content);
                $new_hash = md5($data['content']);

                if ($old_hash !== $new_hash) {
                    $result = $wpdb->update(
                        $wpdb->posts,
                        array('post_content' => $data['content']),
                        array('ID' => $post_id)
                    );
                    clean_post_cache($post_id);

                    if ($result === false) {
                        $post_ids[$base_slug] = array('id' => $post_id, 'status' => 'error', 'error' => $wpdb->last_error);
                    } else {
                        $post_ids[$base_slug] = array('id' => $post_id, 'status' => 'updated');
                    }
                } else {
                    $post_ids[$base_slug] = array('id' => $post_id, 'status' => 'unchanged');
                }
            } else {
                $post_ids[$base_slug] = array('id' => $post_id, 'status' => 'unchanged');
            }

            // GEO meta + featured image were create-only, so schema geo edits
            // never reached existing posts on reimport. Import is idempotent
            // with the schema as source of truth — refresh them here too.
            firefly_apply_schema_post_extras($post_id, $data);
        }
    }

    return $post_ids;
}

/**
 * Apply a schema post entry's GEO meta + featured image to a post.
 * Shared by the create and reimport paths of firefly_create_template_posts().
 *
 * Values are wp_slash()ed because update_post_meta() unslashes its input —
 * without it, the \uXXXX escapes json_encode() produces for ’ – etc. lose
 * their backslash and get stored as literal "u2019" text. That's the bug
 * that corrupted key_facts/faq on every import (and then round-tripped into
 * the schema files via export) while the un-encoded summary stayed clean.
 * JSON_UNESCAPED_UNICODE keeps the stored JSON readable as a bonus.
 */
function firefly_apply_schema_post_extras($post_id, $data) {
    if (!empty($data['geo'])) {
        $geo = $data['geo'];
        if (!empty($geo['summary'])) {
            update_post_meta($post_id, '_geo_summary', wp_slash($geo['summary']));
        }
        if (!empty($geo['article_type'])) {
            update_post_meta($post_id, '_geo_article_type', wp_slash($geo['article_type']));
        }
        if (!empty($geo['key_facts'])) {
            update_post_meta($post_id, '_geo_key_facts', wp_slash(json_encode($geo['key_facts'], JSON_UNESCAPED_UNICODE)));
        }
        if (!empty($geo['faq'])) {
            update_post_meta($post_id, '_geo_faq', wp_slash(json_encode($geo['faq'], JSON_UNESCAPED_UNICODE)));
        }
    }

    if (!empty($data['featured_image'])) {
        $attachment_id = firefly_get_attachment_by_filename($data['featured_image'], $post_id);
        if ($attachment_id && (int) get_post_thumbnail_id($post_id) !== $attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    }
}

// =============================================================================
// ATTACHMENT LOOKUP
// =============================================================================

/**
 * Find an attachment by its filename.
 * Searches the _wp_attached_file meta for a matching filename.
 *
 * Matches the path BASENAME exactly ("featured.webp" must not match
 * "fable-blog-featured.webp" — the old suffix LIKE did). Generic filenames
 * like "featured.webp" can still legitimately exist many times, so when
 * $for_post_id is given, an attachment uploaded to that post (post_parent)
 * wins over any other match — that's how a schema entry's featured_image
 * resolves to the post's OWN upload instead of another article's.
 */
function firefly_get_attachment_by_filename($filename, $for_post_id = 0) {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm.post_id, p.post_parent FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_wp_attached_file'
         AND (pm.meta_value = %s OR pm.meta_value LIKE %s)
         ORDER BY pm.post_id DESC",
        $filename,
        '%/' . $wpdb->esc_like($filename)
    ), ARRAY_A);

    if (empty($rows)) {
        return 0;
    }

    if ($for_post_id) {
        foreach ($rows as $row) {
            if ((int) $row['post_parent'] === (int) $for_post_id) {
                return (int) $row['post_id'];
            }
        }
    }

    return (int) $rows[0]['post_id'];
}

// =============================================================================
// SNIPPET EXPORT
// =============================================================================

/**
 * Export page content back to its snippet file.
 */
function firefly_export_page_to_snippet($page_id) {
    $page = get_post($page_id);
    if (!$page || $page->post_type !== 'page') {
        return false;
    }

    $template = get_post_meta($page_id, FIREFLY_TEMPLATE_META_KEY, true);
    if (empty($template)) {
        return false;
    }

    $actual_slug = $page->post_name;
    $base_slug = firefly_get_base_slug($actual_slug, $template);

    // Find the page definition in schema
    $schema = firefly_get_template_schema($template);
    if (!$schema || !isset($schema['pages'])) {
        return false;
    }

    $page_def = null;
    foreach ($schema['pages'] as $def) {
        if ($def['slug'] === $base_slug) {
            $page_def = $def;
            break;
        }
    }

    if (!$page_def || !isset($page_def['snippet'])) {
        return false;
    }

    $snippet_path = get_template_directory() . "/templates/{$template}/snippets/pages/{$page_def['snippet']}";

    // Write content to snippet file
    $result = file_put_contents($snippet_path, $page->post_content);

    return $result !== false;
}

/**
 * Get the snippet file path for a page.
 */
function firefly_get_page_snippet_path($page_id) {
    $page = get_post($page_id);
    if (!$page || $page->post_type !== 'page') {
        return null;
    }

    $template = get_post_meta($page_id, FIREFLY_TEMPLATE_META_KEY, true);
    if (empty($template)) {
        return null;
    }

    $actual_slug = $page->post_name;
    $base_slug = firefly_get_base_slug($actual_slug, $template);

    $schema = firefly_get_template_schema($template);
    if (!$schema || !isset($schema['pages'])) {
        return null;
    }

    foreach ($schema['pages'] as $def) {
        if ($def['slug'] === $base_slug && isset($def['snippet'])) {
            return get_template_directory() . "/templates/{$template}/snippets/pages/{$def['snippet']}";
        }
    }

    return null;
}

// =============================================================================
// TEMPLATE CONTENT PROVISIONING
// =============================================================================

/**
 * Ensure a template's content exists, creating from schema if missing.
 * Safe to call repeatedly — only creates what doesn't already exist.
 */
function firefly_ensure_template_content($template) {
    $schema = firefly_get_template_schema($template);
    if (!$schema) {
        return array();
    }

    $created = array();

    // Always run page creation/update — it skips existing pages with content
    // and populates empty pages from snippets
    $page_ids = firefly_create_template_pages($template);
    if (!empty($page_ids)) {
        $created['pages'] = $page_ids;
    }

    // Create categories (skips existing)
    if (isset($schema['categories'])) {
        $cat_ids = firefly_create_template_categories($template);
        if (!empty($cat_ids)) {
            $created['categories'] = $cat_ids;
        }
    }

    // Create posts (skips existing)
    if (isset($schema['posts'])) {
        $post_ids = firefly_create_template_posts($template);
        if (!empty($post_ids)) {
            $created['posts'] = $post_ids;
        }
    }

    // Always recreate menu from schema to pick up changes
    $old_menu_id = get_option("firefly_menu_{$template}", 0);
    if ($old_menu_id && wp_get_nav_menu_object($old_menu_id)) {
        wp_delete_nav_menu($old_menu_id);
        delete_option("firefly_menu_{$template}");
    }
    $menu_id = firefly_create_template_navigation($template);
    if ($menu_id) {
        $created['menu'] = $menu_id;
    }

    return $created;
}

// =============================================================================
// TEMPLATE SWITCH HANDLING
// =============================================================================

/**
 * When template changes, ensure content exists and update WordPress options.
 */
add_action('update_option_' . FIREFLY_COLLECTIVE_TEMPLATE_OPTION, 'firefly_on_template_change', 10, 2);

function firefly_on_template_change($old_value, $new_value) {
    // Ensure content exists (creates from schema if missing)
    firefly_ensure_template_content($new_value);

    $front_page = get_option("firefly_front_page_{$new_value}");
    $posts_page = get_option("firefly_posts_page_{$new_value}");

    if ($front_page) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front_page);
    }

    if ($posts_page) {
        update_option('page_for_posts', $posts_page);
    }

    // Update menu assignment. Resolve robustly instead of trusting the raw
    // firefly_menu_{template} option: a pull/import can replace the menu term
    // (new ID) without updating the option, and the stale pointer here was
    // exactly how the menu "disappeared" after switching templates —
    // find_template_menu_id() re-resolves by term meta and heals the option.
    $base_name = 'Main Menu';
    $schema = firefly_get_template_schema($new_value);
    if (!empty($schema['menu']['name'])) {
        $base_name = $schema['menu']['name'];
    }
    $menu_id = function_exists('firefly_find_template_menu_id')
        ? firefly_find_template_menu_id($new_value, $base_name)
        : (int) get_option("firefly_menu_{$new_value}");
    if ($menu_id) {
        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['website-menu'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
    }
}

// =============================================================================
// CATEGORIES FROM SCHEMA
// =============================================================================

/**
 * Create categories for a template from its schema.
 */
function firefly_create_template_categories($template) {
    $schema = firefly_get_template_schema($template);

    if (!$schema || !isset($schema['categories'])) {
        return array();
    }

    $term_ids = array();

    foreach ($schema['categories'] as $cat_def) {
        $slug = $template === 'default' ? $cat_def['slug'] : "{$cat_def['slug']}-{$template}";

        // Check if exists
        $existing = get_term_by('slug', $slug, 'category');

        if (!$existing) {
            $result = wp_insert_term($cat_def['name'], 'category', array(
                'slug' => $slug
            ));

            if (!is_wp_error($result)) {
                update_term_meta($result['term_id'], FIREFLY_TEMPLATE_META_KEY, $template);
                $term_ids[$cat_def['slug']] = array('id' => $result['term_id'], 'status' => 'created');
            } else {
                $term_ids[$cat_def['slug']] = array('id' => 0, 'status' => 'error', 'error' => $result->get_error_message());
            }
        } else {
            // Ensure template meta is set
            update_term_meta($existing->term_id, FIREFLY_TEMPLATE_META_KEY, $template);
            $term_ids[$cat_def['slug']] = array('id' => $existing->term_id, 'status' => 'unchanged');
        }
    }

    return $term_ids;
}

// =============================================================================
// REST API FOR TEMPLATE ACTIVATION
// =============================================================================

/**
 * Register REST endpoint for template activation.
 */
add_action('rest_api_init', 'firefly_register_template_activation_endpoint');

function firefly_register_template_activation_endpoint() {
    register_rest_route('firefly/v1', '/activate-template', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_handle_activate_template',
        'permission_callback' => 'firefly_cli_permission_check'
    ));

    register_rest_route('firefly/v1', '/deactivate-template', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_handle_deactivate_template',
        'permission_callback' => 'firefly_cli_permission_check'
    ));

    register_rest_route('firefly/v1', '/create-page', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_handle_create_page',
        'permission_callback' => 'firefly_cli_permission_check'
    ));

    register_rest_route('firefly/v1', '/trash-page', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_handle_trash_page',
        'permission_callback' => 'firefly_cli_permission_check'
    ));

    register_rest_route('firefly/v1', '/opcache-reset', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_handle_opcache_reset',
        'permission_callback' => 'firefly_cli_permission_check'
    ));

    register_rest_route('firefly/v1', '/clear-cache', array(
        'methods'             => 'POST',
        'callback'            => 'firefly_handle_clear_cache',
        'permission_callback' => 'firefly_cli_permission_check'
    ));
}

/**
 * Permission check for CLI endpoints.
 * Allows access if user is admin OR if CLI key matches.
 */
function firefly_cli_permission_check(WP_REST_Request $request) {
    // Allow if user has admin rights
    if (current_user_can('manage_options')) {
        return true;
    }

    // Allow if CLI key matches (for local development)
    $cli_key = $request->get_header('X-Firefly-CLI-Key');
    if (!$cli_key) {
        $cli_key = $request->get_param('cli_key');
    }

    // Simple shared secret for CLI access
    // Can be overridden in wp-config.php with define('FIREFLY_CLI_KEY', 'your-key')
    $expected_key = defined('FIREFLY_CLI_KEY') ? FIREFLY_CLI_KEY : 'firefly-cli-dev-key';

    return $cli_key === $expected_key;
}

/**
 * Handle template activation request.
 * Creates pages, categories, posts, and menu from schema.
 */
function firefly_handle_activate_template(WP_REST_Request $request) {
    $template = sanitize_file_name($request->get_param('template'));

    if (empty($template)) {
        return new WP_Error('missing_template', 'Template name is required', array('status' => 400));
    }

    // Check if schema exists
    $schema = firefly_get_template_schema($template);
    if (!$schema) {
        return new WP_Error('invalid_template', 'Template schema not found', array('status' => 404));
    }

    $result = array(
        'template' => $template,
        'pages'    => array(),
        'posts'    => array(),
        'categories' => array(),
        'menu'     => 0
    );

    // Create pages
    $page_ids = firefly_create_template_pages($template);
    $result['pages'] = $page_ids;

    // Create categories
    $cat_ids = firefly_create_template_categories($template);
    $result['categories'] = $cat_ids;

    // Create posts
    $post_ids = firefly_create_template_posts($template);
    $result['posts'] = $post_ids;

    // Create navigation menu
    if (function_exists('firefly_create_template_navigation')) {
        $menu_id = firefly_create_template_navigation($template);
        $result['menu'] = $menu_id;
    }

    // Set global WP options from per-template options (front page, posts page, menu)
    $front_page = get_option("firefly_front_page_{$template}");
    $posts_page = get_option("firefly_posts_page_{$template}");

    if ($front_page) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front_page);
    }

    if ($posts_page) {
        update_option('page_for_posts', $posts_page);
    }

    if ($result['menu']) {
        $locations = get_theme_mod('nav_menu_locations', array());
        $locations['website-menu'] = $result['menu'];
        set_theme_mod('nav_menu_locations', $locations);
        update_option("firefly_menu_{$template}", $result['menu']);
    }

    // Set active template
    update_option('firefly_collective_active_template', $template);

    return rest_ensure_response(array(
        'success' => true,
        'message' => "Template '{$template}' activated successfully",
        'created' => $result
    ));
}

/**
 * Handle template deactivation from CLI.
 * Removes pages, posts, categories, menu, and per-template options.
 */
function firefly_handle_deactivate_template(WP_REST_Request $request) {
    $template = sanitize_file_name($request->get_param('template'));
    $switch_to = sanitize_file_name($request->get_param('switch_to'));

    if (empty($template)) {
        return new WP_Error('missing_template', 'Template name is required', array('status' => 400));
    }

    $result = array('template' => $template);

    // Delete pages
    $deleted_pages = firefly_delete_template_pages($template);
    $result['pages_deleted'] = $deleted_pages;

    // Delete posts scoped to this template
    $posts = get_posts(array(
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_query'     => array(
            array(
                'key'     => FIREFLY_TEMPLATE_META_KEY,
                'value'   => $template,
                'compare' => '='
            )
        ),
        'firefly_skip_scoping' => true
    ));
    $deleted_posts = 0;
    foreach ($posts as $post) {
        if (wp_delete_post($post->ID, true)) {
            $deleted_posts++;
        }
    }
    $result['posts_deleted'] = $deleted_posts;

    // Delete categories scoped to this template
    $categories = get_terms(array(
        'taxonomy'   => 'category',
        'hide_empty' => false,
        'meta_query' => array(
            array(
                'key'     => FIREFLY_TEMPLATE_META_KEY,
                'value'   => $template,
                'compare' => '='
            )
        )
    ));
    $deleted_cats = 0;
    if (!is_wp_error($categories)) {
        foreach ($categories as $cat) {
            if (wp_delete_term($cat->term_id, 'category')) {
                $deleted_cats++;
            }
        }
    }
    $result['categories_deleted'] = $deleted_cats;

    // Delete menu
    $menu_id = get_option("firefly_menu_{$template}", 0);
    if ($menu_id && wp_get_nav_menu_object($menu_id)) {
        wp_delete_nav_menu($menu_id);
        delete_option("firefly_menu_{$template}");
        $result['menu_deleted'] = true;
    }

    // Clean up per-template options
    delete_option("firefly_front_page_{$template}");
    delete_option("firefly_posts_page_{$template}");

    // Switch to another template if requested or if this was active
    $active = get_option('firefly_collective_active_template');
    if ($active === $template) {
        if (!empty($switch_to)) {
            update_option('firefly_collective_active_template', $switch_to);
            // Trigger activation of the new template
            firefly_on_template_change($template, $switch_to);
            $result['switched_to'] = $switch_to;
        }
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => "Template '{$template}' deactivated",
        'result'  => $result
    ));
}

/**
 * Handle single page creation from CLI.
 */
function firefly_handle_create_page(WP_REST_Request $request) {
    $slug     = sanitize_title($request->get_param('slug'));
    $title    = sanitize_text_field($request->get_param('title'));
    $content  = $request->get_param('content') ?: '';
    $template = sanitize_file_name($request->get_param('template'));

    if (empty($slug) || empty($title)) {
        return new WP_Error('missing_params', 'slug and title are required', array('status' => 400));
    }

    // Check if page already exists for this template
    $existing = get_posts(array(
        'post_type'   => 'page',
        'name'        => $slug,
        'post_status' => 'publish',
        'meta_key'    => '_firefly_template',
        'meta_value'  => $template,
        'numberposts' => 1
    ));

    if (!empty($existing)) {
        return rest_ensure_response(array(
            'success' => true,
            'page_id' => $existing[0]->ID,
            'message' => 'Page already exists'
        ));
    }

    $page_id = wp_insert_post(array(
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ));

    if (is_wp_error($page_id)) {
        return new WP_Error('create_failed', $page_id->get_error_message(), array('status' => 500));
    }

    update_post_meta($page_id, '_firefly_template', $template);

    return rest_ensure_response(array(
        'success' => true,
        'page_id' => $page_id
    ));
}

/**
 * Handle page trash from CLI.
 */
function firefly_handle_trash_page(WP_REST_Request $request) {
    $slug     = sanitize_title($request->get_param('slug'));
    $template = sanitize_file_name($request->get_param('template'));

    if (empty($slug)) {
        return new WP_Error('missing_params', 'slug is required', array('status' => 400));
    }

    $pages = get_posts(array(
        'post_type'   => 'page',
        'name'        => $slug,
        'post_status' => array('publish', 'draft'),
        'meta_key'    => '_firefly_template',
        'meta_value'  => $template,
        'numberposts' => 1
    ));

    if (empty($pages)) {
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'No matching page found'
        ));
    }

    wp_trash_post($pages[0]->ID);

    return rest_ensure_response(array(
        'success' => true,
        'page_id' => $pages[0]->ID,
        'message' => 'Page trashed'
    ));
}

/**
 * Handle PHP opcache reset from CLI.
 */
function firefly_handle_opcache_reset(WP_REST_Request $request) {
    $reset = false;
    if (function_exists('opcache_reset')) {
        $reset = opcache_reset();
    }

    return rest_ensure_response(array(
        'success' => true,
        'opcache_reset' => $reset
    ));
}

/**
 * Handle page cache clearing from CLI.
 */
function firefly_handle_clear_cache(WP_REST_Request $request) {
    // Cast to string first: with no template param, get_param() returns null,
    // and sanitize_file_name(null) fatals on PHP 8.1+ (null → wp_is_valid_utf8).
    // Empty template means "clear all" (see below).
    $template = sanitize_file_name( (string) $request->get_param('template') );

    if (!empty($template) && function_exists('firefly_collective_cache_delete_template')) {
        firefly_collective_cache_delete_template($template);
    } elseif (function_exists('firefly_collective_cache_clear_all')) {
        firefly_collective_cache_clear_all();
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Cache cleared'
    ));
}
