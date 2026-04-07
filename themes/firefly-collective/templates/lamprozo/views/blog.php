<?php
$paged = get_query_var('paged') ?: 1;
$posts = new WP_Query([
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 10,
    'paged'          => $paged,
]);
?>

<div class="blog-page">
    <div class="blog-header">
        <h1 class="blog-title">Blog</h1>
        <p class="blog-subtitle">Thoughts on Nuzlockes, runs, and everything in between.</p>
    </div>

    <?php if ($posts->have_posts()): ?>
    <div class="blog-list">
        <?php while ($posts->have_posts()): $posts->the_post(); ?>
        <article class="blog-card">
            <div class="blog-card__meta">
                <span class="blog-card__date"><?php echo get_the_date('F j, Y'); ?></span>
            </div>
            <h2 class="blog-card__title">
                <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
            </h2>
            <p class="blog-card__excerpt"><?php echo wp_trim_words(get_the_excerpt(), 30); ?></p>
            <a class="blog-card__link" href="<?php the_permalink(); ?>">Read more</a>
        </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>

    <div class="blog-pagination">
        <?php if ($paged > 1): ?>
            <a class="btn-page" href="<?php echo get_pagenum_link($paged - 1); ?>">&larr; Newer</a>
        <?php endif; ?>
        <?php if ($paged < $posts->max_num_pages): ?>
            <a class="btn-page" href="<?php echo get_pagenum_link($paged + 1); ?>">Older &rarr;</a>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="blog-empty">
        <p>No posts yet. Write your first post in WP Admin → Posts → Add New.</p>
    </div>
    <?php endif; ?>
</div>
