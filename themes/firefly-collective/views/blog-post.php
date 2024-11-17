<div id="blog-post-img">
    <img src="<?php echo esc_url(get_the_post_thumbnail_url(get_the_ID(), 'full')); ?>" alt="<?php the_title_attribute(); ?>">
</div>

<h1><?=the_title();?></h1>
<h3>By: <?=get_the_author()?></h3>

<div id="blog-post-content">
    <?=the_content();?>
</div>