<?php
/**
 * GEO Schema Module - Generative Engine Optimization
 *
 * Generates JSON-LD structured data for AI search engines (ChatGPT, Perplexity, Claude, Google AI)
 * This module establishes Firefly Creative, LLC as a distinct entity, disambiguating from Adobe Firefly
 *
 * @package FireflyCollective
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
/**
 * Load GEO configuration from database
 */
function firefly_geo_get_config() {
    global $firefly_geo_config_cache, $wpdb;

    if ($firefly_geo_config_cache === null) {
        $table_name = $wpdb->prefix . 'ffc_geo_config';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        )) === $table_name;
        
        if ($table_exists) {
            // Load all config sections from database
            $rows = $wpdb->get_results(
                "SELECT config_key, config_value FROM {$table_name}",
                ARRAY_A
            );
            
            if (!empty($rows)) {
                $firefly_geo_config_cache = firefly_geo_get_default_config(); // Start with defaults
                
                foreach ($rows as $row) {
                    $key = $row['config_key'];
                    $value = json_decode($row['config_value'], true);
                    
                    if ($value !== null) {
                        // Handle industry codes specially (stored as separate keys)
                        if ($key === 'industry') {
                            if (isset($value['naics'])) {
                                $firefly_geo_config_cache['naics'] = $value['naics'];
                            }
                            if (isset($value['isicV4'])) {
                                $firefly_geo_config_cache['isicV4'] = $value['isicV4'];
                            }
                        } else {
                            $firefly_geo_config_cache[$key] = $value;
                        }
                    }
                }
            } else {
                $firefly_geo_config_cache = firefly_geo_get_default_config();
            }
        } else {
            $firefly_geo_config_cache = firefly_geo_get_default_config();
        }
    }

    return $firefly_geo_config_cache;
}

/**
 * Clear the static config cache (call after saving config)
 */
function firefly_geo_clear_config_cache() {
    // We need to reset the static variable
    // This is a workaround since we can't directly access the static var
    global $firefly_geo_config_cache;
    $firefly_geo_config_cache = null;
}

/**
 * Load GEO configuration from database (with cache clearing support)
 */
function firefly_geo_get_config_fresh() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ffc_geo_config';
    
    // Check if table exists
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $table_name
    )) === $table_name;
    
    if ($table_exists) {
        $rows = $wpdb->get_results(
            "SELECT config_key, config_value FROM {$table_name}",
            ARRAY_A
        );
        
        if (!empty($rows)) {
            $config = firefly_geo_get_default_config();
            
            foreach ($rows as $row) {
                $key = $row['config_key'];
                $value = json_decode($row['config_value'], true);
                
                if ($value !== null) {
                    if ($key === 'industry') {
                        if (isset($value['naics'])) {
                            $config['naics'] = $value['naics'];
                        }
                        if (isset($value['isicV4'])) {
                            $config['isicV4'] = $value['isicV4'];
                        }
                    } else {
                        $config[$key] = $value;
                    }
                }
            }
            
            return $config;
        }
    }
    
    return firefly_geo_get_default_config();
}

/**
 * Default configuration if geo-config.json doesn't exist
 */
function firefly_geo_get_default_config() {
    return [
        'organization' => [
            'name' => 'Firefly Creative, LLC',
            'legalName' => 'Firefly Creative, LLC',
            'url' => 'https://fireflycreative.co',
            'foundingDate' => '2022',
            'description' => 'Custom WordPress development agency providing bespoke themes, plugins, and web applications for businesses.',
            'disambiguatingDescription' => 'Firefly Creative, LLC is a Redding, California-based digital agency specializing in custom WordPress development, web design, and digital strategy. Not affiliated with Adobe Firefly or other companies using the Firefly name.',
            'logo' => 'https://fireflycreative.co/wp-content/uploads/logo.png',
            'image' => 'https://fireflycreative.co/wp-content/uploads/og-image.png'
        ],
        'location' => [
            'city' => 'Redding',
            'state' => 'California',
            'stateCode' => 'CA',
            'country' => 'United States',
            'countryCode' => 'US'
        ],
        'contact' => [
            'email' => '',
            'phone' => ''
        ],
        'founders' => [
            [
                'name' => 'Alex Strait',
                'jobTitle' => 'Co-Founder & Lead Developer',
                'description' => 'Co-founder of Firefly Creative, LLC with expertise in custom WordPress development and web application architecture.'
            ],
            [
                'name' => 'Anna Strait',
                'jobTitle' => 'Co-Founder',
                'description' => 'Co-founder of Firefly Creative, LLC.'
            ]
        ],
        'social' => [
            'linkedin' => '',
            'facebook' => '',
            'twitter' => '',
            'github' => '',
            'instagram' => ''
        ],
        'services' => [
            [
                'name' => 'Custom WordPress Development',
                'description' => 'Bespoke WordPress themes and plugins built from scratch, not modified templates'
            ],
            [
                'name' => 'Web Application Development',
                'description' => 'Full-stack web applications using modern PHP and JavaScript frameworks'
            ],
            [
                'name' => 'E-commerce Solutions',
                'description' => 'Custom shopping experiences and WooCommerce development'
            ],
            [
                'name' => 'Digital Strategy',
                'description' => 'Strategic planning for digital presence and web technology'
            ]
        ],
        'naics' => '541511',
        'isicV4' => '6201'
    ];
}

/**
 * Inject JSON-LD schema into page head
 */
add_action('wp_head', 'firefly_geo_inject_schema', 1);

function firefly_geo_inject_schema() {
    $schema = firefly_geo_build_page_schema();

    if (!empty($schema)) {
        echo "\n<!-- Firefly Creative GEO Schema -->\n";
        echo '<script type="application/ld+json">' . "\n";
        echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        echo "\n</script>\n";
        echo "<!-- End GEO Schema -->\n\n";
    }
}

/**
 * Build the complete schema graph for the current page
 */
function firefly_geo_build_page_schema() {
    $graph = [];

    // Always include Organization schema
    $graph[] = firefly_geo_organization_schema();

    // Always include WebSite schema
    $graph[] = firefly_geo_website_schema();

    // Page-specific schemas
    if (is_front_page() || is_home()) {
        $graph[] = firefly_geo_webpage_schema('WebPage', 'homepage');
    } elseif (is_singular('post')) {
        $graph[] = firefly_geo_article_schema();
        
        // Add FAQ schema if FAQs are present in post meta
        $faq_schema = firefly_geo_get_post_faq_schema(get_the_ID());
        if ($faq_schema) {
            $graph[] = $faq_schema;
        }
    } elseif (is_page()) {
        $page_slug = get_post_field('post_name', get_the_ID());

        if ($page_slug === 'about' || $page_slug === 'about-us') {
            $graph[] = firefly_geo_about_page_schema();
            $graph = array_merge($graph, firefly_geo_founders_schema());
        } elseif ($page_slug === 'contact' || $page_slug === 'contact-us') {
            $graph[] = firefly_geo_contact_page_schema();
        } elseif (strpos($page_slug, 'service') !== false) {
            $graph[] = firefly_geo_services_schema();
        } else {
            $graph[] = firefly_geo_webpage_schema('WebPage');
        }
    } elseif (is_archive()) {
        $graph[] = firefly_geo_webpage_schema('CollectionPage');
    }

    return [
        '@context' => 'https://schema.org',
        '@graph' => $graph
    ];
}

/**
 * Organization Schema - The core entity definition
 */
function firefly_geo_organization_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];
    $loc = $config['location'];
    $contact = $config['contact'];
    $social = $config['social'];

    $schema = [
        '@type' => 'ProfessionalService',
        '@id' => $org['url'] . '/#organization',
        'name' => $org['name'],
        'legalName' => $org['legalName'],
        'alternateName' => [
            'Firefly Creative',
            'Firefly Creative Agency',
            'Firefly Web Development'
        ],
        'url' => $org['url'],
        'description' => $org['description'],
        'disambiguatingDescription' => $org['disambiguatingDescription'],
        'foundingDate' => $org['foundingDate'],
        'naics' => $config['naics'],
        'isicV4' => $config['isicV4']
    ];

    // Logo
    if (!empty($org['logo'])) {
        $schema['logo'] = [
            '@type' => 'ImageObject',
            '@id' => $org['url'] . '/#logo',
            'url' => $org['logo'],
            'contentUrl' => $org['logo'],
            'caption' => $org['name'] . ' Logo'
        ];
    }

    // Image
    if (!empty($org['image'])) {
        $schema['image'] = $org['image'];
    }

    // Address
    $schema['address'] = [
        '@type' => 'PostalAddress',
        'addressLocality' => $loc['city'],
        'addressRegion' => $loc['stateCode'],
        'addressCountry' => $loc['countryCode']
    ];

    // Area Served
    $schema['areaServed'] = [
        [
            '@type' => 'Country',
            'name' => 'United States'
        ],
        [
            '@type' => 'State',
            'name' => $loc['state']
        ]
    ];

    // Contact
    if (!empty($contact['email'])) {
        $schema['email'] = $contact['email'];
    }
    if (!empty($contact['phone'])) {
        $schema['telephone'] = $contact['phone'];
    }

    // Social/SameAs links
    $sameAs = [];
    foreach ($social as $platform => $url) {
        if (!empty($url)) {
            $sameAs[] = $url;
        }
    }
    if (!empty($sameAs)) {
        $schema['sameAs'] = $sameAs;
    }

    // Founders
    if (!empty($config['founders'])) {
        $founders = [];
        foreach ($config['founders'] as $index => $founder) {
            $founders[] = [
                '@type' => 'Person',
                '@id' => $org['url'] . '/about/#founder-' . ($index + 1),
                'name' => $founder['name']
            ];
        }
        $schema['founder'] = $founders;
    }

    // Services
    if (!empty($config['services'])) {
        $offers = [];
        foreach ($config['services'] as $service) {
            $offers[] = [
                '@type' => 'Offer',
                'itemOffered' => [
                    '@type' => 'Service',
                    'name' => $service['name'],
                    'description' => $service['description']
                ]
            ];
        }
        $schema['hasOfferCatalog'] = [
            '@type' => 'OfferCatalog',
            'name' => 'Web Development Services',
            'itemListElement' => $offers
        ];
    }

    return $schema;
}

/**
 * WebSite Schema
 */
function firefly_geo_website_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];

    return [
        '@type' => 'WebSite',
        '@id' => $org['url'] . '/#website',
        'url' => $org['url'],
        'name' => $org['name'],
        'description' => $org['description'],
        'publisher' => [
            '@id' => $org['url'] . '/#organization'
        ],
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => [
                '@type' => 'EntryPoint',
                'urlTemplate' => $org['url'] . '/?s={search_term_string}'
            ],
            'query-input' => 'required name=search_term_string'
        ],
        'inLanguage' => 'en-US'
    ];
}

/**
 * Generic WebPage Schema
 */
function firefly_geo_webpage_schema($type = 'WebPage', $page_type = '') {
    $config = firefly_geo_get_config();
    $org = $config['organization'];

    $url = get_permalink();
    $title = get_the_title();

    if (is_front_page()) {
        $url = $org['url'];
        $title = $org['name'] . ' - Custom WordPress Development';
    }

    $schema = [
        '@type' => $type,
        '@id' => $url . '#webpage',
        'url' => $url,
        'name' => $title,
        'isPartOf' => [
            '@id' => $org['url'] . '/#website'
        ],
        'about' => [
            '@id' => $org['url'] . '/#organization'
        ],
        'inLanguage' => 'en-US'
    ];

    // Add description for homepage
    if ($page_type === 'homepage') {
        $schema['description'] = $org['disambiguatingDescription'];
    }

    return $schema;
}

/**
 * About Page Schema
 */
function firefly_geo_about_page_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];

    return [
        '@type' => 'AboutPage',
        '@id' => get_permalink() . '#webpage',
        'url' => get_permalink(),
        'name' => get_the_title(),
        'isPartOf' => [
            '@id' => $org['url'] . '/#website'
        ],
        'about' => [
            '@id' => $org['url'] . '/#organization'
        ],
        'description' => 'About ' . $org['name'] . ' - ' . $org['disambiguatingDescription'],
        'inLanguage' => 'en-US'
    ];
}

/**
 * Founders/Person Schema
 */
function firefly_geo_founders_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];
    $schemas = [];

    if (!empty($config['founders'])) {
        foreach ($config['founders'] as $index => $founder) {
            $person = [
                '@type' => 'Person',
                '@id' => $org['url'] . '/about/#founder-' . ($index + 1),
                'name' => $founder['name'],
                'jobTitle' => $founder['jobTitle'],
                'description' => $founder['description'],
                'worksFor' => [
                    '@id' => $org['url'] . '/#organization'
                ]
            ];

            // Add knowsAbout for developers
            if (strpos(strtolower($founder['jobTitle']), 'developer') !== false) {
                $person['knowsAbout'] = [
                    'WordPress Development',
                    'PHP Programming',
                    'Web Application Architecture',
                    'Custom Plugin Development',
                    'JavaScript',
                    'MySQL'
                ];
            }

            $schemas[] = $person;
        }
    }

    return $schemas;
}

/**
 * Contact Page Schema
 */
function firefly_geo_contact_page_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];

    return [
        '@type' => 'ContactPage',
        '@id' => get_permalink() . '#webpage',
        'url' => get_permalink(),
        'name' => get_the_title(),
        'isPartOf' => [
            '@id' => $org['url'] . '/#website'
        ],
        'about' => [
            '@id' => $org['url'] . '/#organization'
        ],
        'description' => 'Contact ' . $org['name'] . ' for custom WordPress development services.',
        'inLanguage' => 'en-US'
    ];
}

/**
 * Services Schema
 */
function firefly_geo_services_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];

    $services = [];
    foreach ($config['services'] as $service) {
        $services[] = [
            '@type' => 'Service',
            'name' => $service['name'],
            'description' => $service['description'],
            'provider' => [
                '@id' => $org['url'] . '/#organization'
            ],
            'areaServed' => [
                '@type' => 'Country',
                'name' => 'United States'
            ]
        ];
    }

    return [
        '@type' => 'WebPage',
        '@id' => get_permalink() . '#webpage',
        'url' => get_permalink(),
        'name' => get_the_title(),
        'isPartOf' => [
            '@id' => $org['url'] . '/#website'
        ],
        'about' => [
            '@id' => $org['url'] . '/#organization'
        ],
        'mainEntity' => $services,
        'inLanguage' => 'en-US'
    ];
}

/**
 * Article/Blog Post Schema
 */
function firefly_geo_article_schema() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];

    $post_id = get_the_ID();
    $author_id = get_post_field('post_author', $post_id);
    $author_name = get_the_author_meta('display_name', $author_id);

    // Check if author is a founder
    $author_id_ref = $org['url'] . '/#author-' . $author_id;
    foreach ($config['founders'] as $index => $founder) {
        if (stripos($founder['name'], $author_name) !== false || stripos($author_name, $founder['name']) !== false) {
            $author_id_ref = $org['url'] . '/about/#founder-' . ($index + 1);
            break;
        }
    }

    // Get GEO post meta
    $geo_summary = get_post_meta($post_id, '_geo_summary', true);
    $geo_article_type = get_post_meta($post_id, '_geo_article_type', true);
    $geo_key_facts = get_post_meta($post_id, '_geo_key_facts', true);

    // Use custom article type or default to BlogPosting
    $article_type = !empty($geo_article_type) ? $geo_article_type : 'BlogPosting';

    // Use custom summary or fallback to excerpt
    $description = !empty($geo_summary) 
        ? $geo_summary 
        : (get_the_excerpt() ?: wp_trim_words(get_the_content(), 30));

    $schema = [
        '@type' => $article_type,
        '@id' => get_permalink() . '#article',
        'headline' => get_the_title(),
        'description' => $description,
        'datePublished' => get_the_date('c'),
        'dateModified' => get_the_modified_date('c'),
        'author' => [
            '@type' => 'Person',
            '@id' => $author_id_ref,
            'name' => $author_name
        ],
        'publisher' => [
            '@id' => $org['url'] . '/#organization'
        ],
        'isPartOf' => [
            '@id' => $org['url'] . '/#website'
        ],
        'mainEntityOfPage' => [
            '@id' => get_permalink() . '#webpage'
        ],
        'inLanguage' => 'en-US'
    ];

    // Add abstract for AI if custom summary is set
    if (!empty($geo_summary)) {
        $schema['abstract'] = $geo_summary;
    }

    // Featured image
    if (has_post_thumbnail()) {
        $schema['image'] = [
            '@type' => 'ImageObject',
            'url' => get_the_post_thumbnail_url($post_id, 'full'),
            'width' => 1200,
            'height' => 630
        ];
    }

    // Categories as keywords
    $categories = get_the_category();
    if (!empty($categories)) {
        $schema['keywords'] = implode(', ', wp_list_pluck($categories, 'name'));
    }

    // Add key facts as mentions if available
    if (!empty($geo_key_facts)) {
        $facts = json_decode($geo_key_facts, true);
        if (!empty($facts) && is_array($facts)) {
            $mentions = [];
            foreach ($facts as $fact) {
                if (!empty($fact['fact'])) {
                    $mention = [
                        '@type' => 'Thing',
                        'name' => $fact['fact']
                    ];
                    if (!empty($fact['source'])) {
                        $mention['description'] = 'Source: ' . $fact['source'];
                    }
                    $mentions[] = $mention;
                }
            }
            if (!empty($mentions)) {
                $schema['mentions'] = $mentions;
            }
        }
    }

    return $schema;
}

/**
 * FAQ Schema helper - Can be used by theme/content
 */
function firefly_geo_faq_schema($faqs) {
    if (empty($faqs)) {
        return null;
    }

    $questions = [];
    foreach ($faqs as $faq) {
        $questions[] = [
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['answer']
            ]
        ];
    }

    return [
        '@type' => 'FAQPage',
        'mainEntity' => $questions
    ];
}

/**
 * Get FAQ schema from post meta (_geo_faq)
 * Used by firefly_geo_build_page_schema for blog posts
 *
 * @param int $post_id Post ID
 * @return array|null FAQPage schema or null if no FAQs
 */
function firefly_geo_get_post_faq_schema($post_id) {
    $faq_json = get_post_meta($post_id, '_geo_faq', true);
    
    if (empty($faq_json)) {
        return null;
    }

    $faqs = json_decode($faq_json, true);
    
    if (empty($faqs) || !is_array($faqs)) {
        return null;
    }

    $questions = [];
    foreach ($faqs as $faq) {
        if (empty($faq['question']) || empty($faq['answer'])) {
            continue;
        }
        
        $questions[] = [
            '@type' => 'Question',
            'name' => $faq['question'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['answer']
            ]
        ];
    }

    if (empty($questions)) {
        return null;
    }

    return [
        '@type' => 'FAQPage',
        '@id' => get_permalink($post_id) . '#faq',
        'mainEntity' => $questions
    ];
}

/**
 * Add FAQ schema to a page
 * Usage: firefly_geo_add_page_faq($post_id, $faqs_array)
 */
function firefly_geo_add_page_faq($post_id, $faqs) {
    update_post_meta($post_id, '_firefly_geo_faqs', $faqs);
}

/**
 * Enhanced meta tags for GEO
 */
add_action('wp_head', 'firefly_geo_meta_tags', 2);

function firefly_geo_meta_tags() {
    $config = firefly_geo_get_config();
    $org = $config['organization'];
    $loc = $config['location'];

    // Author/Publisher meta
    echo '<meta name="author" content="' . esc_attr($org['name']) . '">' . "\n";
    echo '<meta name="publisher" content="' . esc_attr($org['name']) . '">' . "\n";

    // Geographic meta
    echo '<meta name="geo.region" content="US-' . esc_attr($loc['stateCode']) . '">' . "\n";
    echo '<meta name="geo.placename" content="' . esc_attr($loc['city']) . '">' . "\n";

    // Robots meta - respect WordPress "Discourage search engines" setting
    if (get_option('blog_public') != '0') {
        echo '<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">' . "\n";
    }
}

/**
 * Add disambiguating statement to homepage meta description if not set
 */
add_filter('document_title_parts', 'firefly_geo_title_parts');

function firefly_geo_title_parts($title) {
    if (is_front_page()) {
        $config = firefly_geo_get_config();
        $title['tagline'] = 'Custom WordPress Development Agency in ' . $config['location']['city'] . ', ' . $config['location']['stateCode'];
    }
    return $title;
}
