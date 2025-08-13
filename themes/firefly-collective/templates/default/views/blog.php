<?php

    global $template_path;

    // Get Query Parameters for Filters
    $category_id = get_query_var('cat') ? intval(get_query_var('cat')) : '';
    $tag_id      = get_query_var('tag_id') ? intval(get_query_var('tag_id')) : '';
    $month       = get_query_var('monthnum') ? intval(get_query_var('monthnum')) : '';
    $year        = get_query_var('year') ? intval(get_query_var('year')) : '';
    $keywords    = get_query_var('s') ? sanitize_text_field(get_query_var('s')) : '';

    $args = array(
        'post_type'      => 'post',
        'posts_per_page' => 15,
        'paged'          => max(1, get_query_var('paged')),
        'orderby'        => 'date',
        'order'          => 'DESC',
        'cat'            => $category_id,
        'tag_id'         => $tag_id,
        'monthnum'       => $month,
        'year'           => $year,
        's'              => $keywords,
    );

    $the_query = new WP_Query($args);

    // Get Categories and Tags
    $categories = get_categories(array(
        'orderby' => 'name',
        'order'   => 'ASC',
    ));

    $tags = get_tags(array(
        'orderby' => 'name',
        'order'   => 'ASC',
    ));
?>

<h1><?php echo esc_html($pageTitle); ?></h1>

<?php echo apply_filters('the_content', $postContent); ?>

<div id="blog-filter">
    <div id="blog-filter-head">
        <img src="<?php echo esc_url($template_path . '/images/filter.webp'); ?>" alt="<?php esc_attr_e('Filter'); ?>">
    </div>
    <div id="blog-filter-options">
        <div>
            <h3><?php esc_html_e('Filter Options'); ?></h3>
            <div id="blog-filter-options-wrapper">
                <select id="category-filter">
                    <option value=""><?php esc_html_e('All Categories'); ?></option>
                    <?php foreach ($categories as $category) { ?>
                        <option value="<?php echo esc_attr($category->term_id); ?>">
                            <?php echo esc_html($category->name); ?>
                        </option>
                    <?php } ?>
                </select>

                <select id="tag-filter">
                    <option value=""><?php esc_html_e('All Tags'); ?></option>
                    <?php foreach ($tags as $tag) { ?>
                        <option value="<?php echo esc_attr($tag->term_id); ?>">
                            <?php echo esc_html($tag->name); ?>
                        </option>
                    <?php } ?>
                </select>

                <select id="month-filter">
                    <option value=""><?php esc_html_e('All Months'); ?></option>
                    <?php for ($m = 1; $m <= 12; $m++) { ?>
                        <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html(date_i18n('M', mktime(0, 0, 0, $m, 1))); ?></option>
                    <?php } ?>
                </select>

                <select id="year-filter">
                    <option value=""><?php esc_html_e('All Years'); ?></option>
                    <?php
                    $currentYear = date('Y');
                    $startYear   = $currentYear - 10;
                    for ($yearOption = $currentYear; $yearOption >= $startYear; $yearOption--) { ?>
                        <option value="<?php echo esc_attr($yearOption); ?>"><?php echo esc_html($yearOption); ?></option>
                    <?php } ?>
                </select>

                <div id="blog-search-text">
                    <input type="text" id="blog-filter-keywords" placeholder="<?php esc_attr_e('Keywords'); ?>">
                </div>

                <div id="blog-filter-submit">
                    <input type="submit" id="blog-filter-submit-btn" value="<?php esc_attr_e('Filter Results'); ?>">
                </div>
            </div>
        </div>
    </div>
</div>

<div class="blogs">
    <?php
    if ($the_query->have_posts()) {
        while ($the_query->have_posts()) {
            $the_query->the_post(); ?>
            <div class="blog-short">
                <?php if (has_post_thumbnail()) { ?>
                    <div class="featured-img-container">
                        <img src="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'full')); ?>" class="featured-img" alt="<?php the_title_attribute(); ?>">
                    </div>
                <?php } ?>
                <h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <h2>By: <?php echo get_blog_poster_name(); ?></h2>
                <div><?php the_excerpt(); ?></div>
                <hr>
            </div>
        <?php }
        wp_reset_postdata();
    } else {
        echo '<p>' . esc_html__('No posts found.') . '</p>';
    }
    ?>

    <img id="more-blogs-loader" class="loader" src="<?php echo esc_url($template_path . '/images/loading.gif'); ?>" alt="<?php esc_attr_e('Loading'); ?>">
    <a id="blogs-end" name="blogs-end"></a>
</div>
