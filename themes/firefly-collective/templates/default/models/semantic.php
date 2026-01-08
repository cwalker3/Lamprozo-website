<?php
/**
 * Semantic HTML Helper Functions for GEO Optimization
 *
 * Provides helper functions for creating AI-parseable HTML structures
 * that improve visibility in ChatGPT, Perplexity, Claude, and Google AI.
 *
 * @package FireflyCollective
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate a semantic section with proper heading hierarchy
 *
 * @param string $title Section title
 * @param string $content Section content (HTML allowed)
 * @param int $level Heading level (2-6)
 * @param array $attrs Additional attributes
 * @return string HTML output
 */
function firefly_semantic_section($title, $content, $level = 2, $attrs = []) {
    $level = max(2, min(6, $level)); // Clamp between 2-6
    $tag = "h{$level}";

    $class = isset($attrs['class']) ? ' class="' . esc_attr($attrs['class']) . '"' : '';
    $id = isset($attrs['id']) ? ' id="' . esc_attr($attrs['id']) . '"' : '';

    return sprintf(
        '<section%s%s>
            <%s>%s</%s>
            %s
        </section>',
        $id,
        $class,
        $tag,
        esc_html($title),
        $tag,
        $content
    );
}

/**
 * Generate FAQ section with proper schema markup
 * AI engines love the <details>/<summary> pattern for FAQs
 *
 * @param array $faqs Array of ['question' => '', 'answer' => '']
 * @param string $title Optional section title
 * @return string HTML output
 */
function firefly_faq_section($faqs, $title = 'Frequently Asked Questions') {
    if (empty($faqs)) {
        return '';
    }

    $html = '<section class="faq-section" itemscope itemtype="https://schema.org/FAQPage">';
    $html .= '<h2>' . esc_html($title) . '</h2>';

    foreach ($faqs as $faq) {
        if (empty($faq['question']) || empty($faq['answer'])) {
            continue;
        }

        $html .= '
        <details class="faq-item" itemscope itemprop="mainEntity" itemtype="https://schema.org/Question">
            <summary itemprop="name">' . esc_html($faq['question']) . '</summary>
            <div class="faq-answer" itemscope itemprop="acceptedAnswer" itemtype="https://schema.org/Answer">
                <div itemprop="text">' . wp_kses_post($faq['answer']) . '</div>
            </div>
        </details>';
    }

    $html .= '</section>';
    return $html;
}

/**
 * Generate a service card with proper schema
 *
 * @param array $service ['name' => '', 'description' => '', 'url' => '']
 * @return string HTML output
 */
function firefly_service_card($service) {
    $html = '<article class="service-card" itemscope itemtype="https://schema.org/Service">';

    if (!empty($service['name'])) {
        $html .= '<h3 itemprop="name">' . esc_html($service['name']) . '</h3>';
    }

    if (!empty($service['description'])) {
        $html .= '<p itemprop="description">' . esc_html($service['description']) . '</p>';
    }

    if (!empty($service['url'])) {
        $html .= '<a href="' . esc_url($service['url']) . '" itemprop="url">Learn more</a>';
    }

    // Link to organization
    $html .= '<meta itemprop="provider" content="Firefly Creative, LLC">';

    $html .= '</article>';
    return $html;
}

/**
 * Generate breadcrumb navigation with schema
 *
 * @param array $items Array of ['name' => '', 'url' => '']
 * @return string HTML output
 */
function firefly_breadcrumbs($items = null) {
    if ($items === null) {
        // Auto-generate from current page
        $items = [];
        $items[] = ['name' => 'Home', 'url' => home_url('/')];

        if (is_singular('post')) {
            $items[] = ['name' => 'Blog', 'url' => home_url('/newsroom')];
            $items[] = ['name' => get_the_title(), 'url' => get_permalink()];
        } elseif (is_page()) {
            $items[] = ['name' => get_the_title(), 'url' => get_permalink()];
        }
    }

    if (count($items) < 2) {
        return '';
    }

    $html = '<nav class="breadcrumbs" aria-label="Breadcrumb">';
    $html .= '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';

    foreach ($items as $index => $item) {
        $position = $index + 1;
        $is_last = ($index === count($items) - 1);

        $html .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';

        if ($is_last) {
            $html .= '<span itemprop="name">' . esc_html($item['name']) . '</span>';
        } else {
            $html .= '<a itemprop="item" href="' . esc_url($item['url']) . '">';
            $html .= '<span itemprop="name">' . esc_html($item['name']) . '</span>';
            $html .= '</a>';
        }

        $html .= '<meta itemprop="position" content="' . $position . '">';
        $html .= '</li>';
    }

    $html .= '</ol>';
    $html .= '</nav>';

    return $html;
}

/**
 * Generate author bio box with Person schema
 *
 * @param int $author_id WordPress user ID
 * @return string HTML output
 */
function firefly_author_box($author_id = null) {
    if ($author_id === null) {
        $author_id = get_the_author_meta('ID');
    }

    $name = get_the_author_meta('display_name', $author_id);
    $bio = get_the_author_meta('description', $author_id);
    $avatar = get_avatar($author_id, 100);
    $url = get_author_posts_url($author_id);

    $html = '<aside class="author-box" itemscope itemtype="https://schema.org/Person">';
    $html .= '<div class="author-avatar">' . $avatar . '</div>';
    $html .= '<div class="author-info">';
    $html .= '<h3 class="author-name">';
    $html .= '<a href="' . esc_url($url) . '" itemprop="url">';
    $html .= '<span itemprop="name">' . esc_html($name) . '</span>';
    $html .= '</a>';
    $html .= '</h3>';

    if ($bio) {
        $html .= '<p class="author-bio" itemprop="description">' . esc_html($bio) . '</p>';
    }

    $html .= '</div>';
    $html .= '</aside>';

    return $html;
}

/**
 * Wrap article content with proper microdata
 *
 * @param string $content The article content
 * @param array $meta Article metadata
 * @return string HTML output
 */
function firefly_article_wrapper($content, $meta = []) {
    $defaults = [
        'type' => 'BlogPosting',
        'headline' => get_the_title(),
        'date_published' => get_the_date('c'),
        'date_modified' => get_the_modified_date('c'),
        'author_name' => get_the_author(),
        'author_id' => get_the_author_meta('ID'),
        'image' => get_the_post_thumbnail_url(null, 'full'),
        'excerpt' => get_the_excerpt()
    ];

    $meta = wp_parse_args($meta, $defaults);

    $html = '<article itemscope itemtype="https://schema.org/' . esc_attr($meta['type']) . '">';

    // Hidden metadata
    $html .= '<meta itemprop="headline" content="' . esc_attr($meta['headline']) . '">';
    $html .= '<meta itemprop="datePublished" content="' . esc_attr($meta['date_published']) . '">';
    $html .= '<meta itemprop="dateModified" content="' . esc_attr($meta['date_modified']) . '">';

    if ($meta['image']) {
        $html .= '<meta itemprop="image" content="' . esc_url($meta['image']) . '">';
    }

    if ($meta['excerpt']) {
        $html .= '<meta itemprop="description" content="' . esc_attr($meta['excerpt']) . '">';
    }

    // Author
    $html .= '<span itemprop="author" itemscope itemtype="https://schema.org/Person">';
    $html .= '<meta itemprop="name" content="' . esc_attr($meta['author_name']) . '">';
    $html .= '</span>';

    // Publisher
    $html .= '<span itemprop="publisher" itemscope itemtype="https://schema.org/Organization">';
    $html .= '<meta itemprop="name" content="Firefly Creative, LLC">';
    $html .= '</span>';

    // Main content
    $html .= '<div itemprop="articleBody">' . $content . '</div>';

    $html .= '</article>';

    return $html;
}

/**
 * Generate a "How It Works" or step-by-step section
 * AI loves numbered step formats
 *
 * @param array $steps Array of ['title' => '', 'description' => '']
 * @param string $title Section title
 * @return string HTML output
 */
function firefly_how_it_works($steps, $title = 'How It Works') {
    if (empty($steps)) {
        return '';
    }

    $html = '<section class="how-it-works" itemscope itemtype="https://schema.org/HowTo">';
    $html .= '<h2 itemprop="name">' . esc_html($title) . '</h2>';
    $html .= '<ol class="steps-list">';

    foreach ($steps as $index => $step) {
        $position = $index + 1;

        $html .= '<li class="step" itemscope itemprop="step" itemtype="https://schema.org/HowToStep">';
        $html .= '<meta itemprop="position" content="' . $position . '">';

        if (!empty($step['title'])) {
            $html .= '<h3 itemprop="name">' . esc_html($step['title']) . '</h3>';
        }

        if (!empty($step['description'])) {
            $html .= '<p itemprop="text">' . wp_kses_post($step['description']) . '</p>';
        }

        $html .= '</li>';
    }

    $html .= '</ol>';
    $html .= '</section>';

    return $html;
}

/**
 * Generate key points/benefits list
 * H2 > H3 > bullet structure that AI loves
 *
 * @param array $points Array of strings or ['title' => '', 'description' => '']
 * @param string $title Section title
 * @return string HTML output
 */
function firefly_key_points($points, $title = 'Key Benefits') {
    if (empty($points)) {
        return '';
    }

    $html = '<section class="key-points">';
    $html .= '<h2>' . esc_html($title) . '</h2>';
    $html .= '<ul class="points-list">';

    foreach ($points as $point) {
        $html .= '<li>';

        if (is_array($point)) {
            if (!empty($point['title'])) {
                $html .= '<strong>' . esc_html($point['title']) . '</strong>';
                if (!empty($point['description'])) {
                    $html .= ' - ' . esc_html($point['description']);
                }
            }
        } else {
            $html .= esc_html($point);
        }

        $html .= '</li>';
    }

    $html .= '</ul>';
    $html .= '</section>';

    return $html;
}

/**
 * Generate a definition/answer block
 * Great for "What is X?" type content that AI extracts
 *
 * @param string $term The term being defined
 * @param string $definition The definition
 * @return string HTML output
 */
function firefly_definition($term, $definition) {
    return sprintf(
        '<div class="definition-block" itemscope itemtype="https://schema.org/DefinedTerm">
            <h2>What is <span itemprop="name">%s</span>?</h2>
            <p itemprop="description">%s</p>
        </div>',
        esc_html($term),
        wp_kses_post($definition)
    );
}

/**
 * Add CSS for semantic components
 */
add_action('wp_head', 'firefly_semantic_styles', 99);
function firefly_semantic_styles() {
    ?>
    <style>
    /* FAQ Section Styles */
    .faq-section details {
        margin-bottom: 1rem;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        overflow: hidden;
    }
    .faq-section summary {
        padding: 1rem;
        cursor: pointer;
        font-weight: 600;
        background: #f8f8f8;
        list-style: none;
    }
    .faq-section summary::-webkit-details-marker {
        display: none;
    }
    .faq-section summary::before {
        content: '+';
        margin-right: 0.5rem;
        font-weight: bold;
    }
    .faq-section details[open] summary::before {
        content: '−';
    }
    .faq-section .faq-answer {
        padding: 1rem;
    }

    /* Breadcrumbs */
    .breadcrumbs ol {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .breadcrumbs li:not(:last-child)::after {
        content: '›';
        margin-left: 0.5rem;
        color: #666;
    }
    .breadcrumbs a {
        color: #0066cc;
        text-decoration: none;
    }
    .breadcrumbs a:hover {
        text-decoration: underline;
    }

    /* How It Works */
    .how-it-works ol {
        counter-reset: step-counter;
        list-style: none;
        padding-left: 0;
    }
    .how-it-works .step {
        counter-increment: step-counter;
        padding-left: 3rem;
        position: relative;
        margin-bottom: 1.5rem;
    }
    .how-it-works .step::before {
        content: counter(step-counter);
        position: absolute;
        left: 0;
        top: 0;
        width: 2rem;
        height: 2rem;
        background: var(--primary-color, #0066cc);
        color: white;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
    }

    /* Key Points */
    .key-points ul {
        list-style: disc;
        padding-left: 1.5rem;
    }
    .key-points li {
        margin-bottom: 0.75rem;
    }
    .key-points li strong {
        color: var(--heading-color, #333);
    }
    </style>
    <?php
}
