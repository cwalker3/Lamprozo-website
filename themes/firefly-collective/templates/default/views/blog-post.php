<?php
/**
 * Blog Post Single View Template
 */

// Get post data
$post_id = get_the_ID();
$author_id = get_post_field('post_author', $post_id);
$author_name = get_the_author_meta('display_name', $author_id);
$author_bio = get_the_author_meta('description', $author_id);
$author_avatar = get_avatar($author_id, 80);
$post_date = get_the_date('F j, Y');
$post_time = get_the_time('g:i a');
$categories = get_the_category();
$tags = get_the_tags();
$reading_time = ceil(str_word_count(strip_tags(get_the_content())) / 200); // Assuming 200 words per minute

// Check for featured image
$has_featured_image = has_post_thumbnail();
$featured_image_url = $has_featured_image ? get_the_post_thumbnail_url($post_id, 'full') : '';
?>

<article class="blog-post-single">
    
    <?php if ($has_featured_image) : ?>
        <!-- Hero Featured Image -->
        <div class="blog-post-hero">
            <img src="<?php echo esc_url($featured_image_url); ?>" alt="<?php the_title_attribute(); ?>" class="blog-post-featured-image">
            <div class="blog-post-hero-overlay">
                <div class="blog-post-hero-content">
                    <h1 class="blog-post-title"><?php the_title(); ?></h1>
                    <div class="blog-post-meta-hero">
                        <span class="blog-post-date"><?php echo esc_html($post_date); ?></span>
                        <span class="blog-post-separator">•</span>
                        <span class="blog-post-reading-time"><?php echo esc_html($reading_time); ?> min read</span>
                    </div>
                </div>
            </div>
        </div>
    <?php else : ?>
        <!-- Standard Header without Featured Image -->
        <div class="blog-post-header">
            <h1 class="blog-post-title"><?php the_title(); ?></h1>
        </div>
    <?php endif; ?>
    
    <!-- Post Meta Information -->
    <div class="blog-post-meta-container">
        <div class="blog-post-author">
            <div class="blog-post-author-avatar">
                <?php echo $author_avatar; ?>
            </div>
            <div class="blog-post-author-info">
                <div class="blog-post-author-name">
                    <?php echo esc_html($author_name); ?>
                </div>
                <div class="blog-post-meta-details">
                    <time datetime="<?php echo get_the_date('c'); ?>" class="blog-post-date">
                        <?php echo esc_html($post_date); ?> at <?php echo esc_html($post_time); ?>
                    </time>
                    <?php if (!$has_featured_image) : ?>
                        <span class="blog-post-separator">•</span>
                        <span class="blog-post-reading-time"><?php echo esc_html($reading_time); ?> min read</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Share Buttons (optional) -->
        <div class="blog-post-share">
            <button class="share-button" data-share="twitter" title="Share on Twitter">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/>
                </svg>
            </button>
            <button class="share-button" data-share="facebook" title="Share on Facebook">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                </svg>
            </button>
            <button class="share-button" data-share="linkedin" title="Share on LinkedIn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/>
                </svg>
            </button>
        </div>
    </div>
    
    <!-- Categories and Tags -->
    <?php if ($categories || $tags) : ?>
        <div class="blog-post-taxonomy">
            <?php if ($categories) : ?>
                <div class="blog-post-categories">
                    <span class="taxonomy-label">Categories:</span>
                    <?php foreach ($categories as $category) : ?>
                        <a href="<?php echo esc_url(get_category_link($category->term_id)); ?>" class="category-link">
                            <?php echo esc_html($category->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($tags) : ?>
                <div class="blog-post-tags">
                    <span class="taxonomy-label">Tags:</span>
                    <?php foreach ($tags as $tag) : ?>
                        <a href="<?php echo esc_url(get_tag_link($tag->term_id)); ?>" class="tag-link">
                            #<?php echo esc_html($tag->name); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <!-- Post Content -->
    <div class="blog-post-content">
        <?php the_content(); ?>
    </div>
    
    <!-- Author Bio Box -->
    <?php if ($author_bio) : ?>
        <div class="blog-post-author-bio">
            <div class="author-bio-avatar">
                <?php echo get_avatar($author_id, 100); ?>
            </div>
            <div class="author-bio-content">
                <h3 class="author-bio-title">About <?php echo esc_html($author_name); ?></h3>
                <p class="author-bio-text"><?php echo esc_html($author_bio); ?></p>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Post Navigation -->
    <nav class="blog-post-navigation">
        <?php
        $prev_post = get_previous_post();
        $next_post = get_next_post();
        ?>
        
        <?php if ($prev_post) : ?>
            <a href="<?php echo esc_url(get_permalink($prev_post->ID)); ?>" class="post-nav-link post-nav-prev">
                <span class="post-nav-label">← Previous Post</span>
                <span class="post-nav-title"><?php echo esc_html(get_the_title($prev_post->ID)); ?></span>
            </a>
        <?php endif; ?>
        
        <?php if ($next_post) : ?>
            <a href="<?php echo esc_url(get_permalink($next_post->ID)); ?>" class="post-nav-link post-nav-next">
                <span class="post-nav-label">Next Post →</span>
                <span class="post-nav-title"><?php echo esc_html(get_the_title($next_post->ID)); ?></span>
            </a>
        <?php endif; ?>
    </nav>
    
</article>

<script>
// Share functionality
document.addEventListener('DOMContentLoaded', function() {
    const shareButtons = document.querySelectorAll('.share-button');
    const pageUrl = encodeURIComponent(window.location.href);
    const pageTitle = encodeURIComponent(document.title);
    
    shareButtons.forEach(button => {
        button.addEventListener('click', function() {
            const network = this.getAttribute('data-share');
            let shareUrl = '';
            
            switch(network) {
                case 'twitter':
                    shareUrl = `https://twitter.com/intent/tweet?url=${pageUrl}&text=${pageTitle}`;
                    break;
                case 'facebook':
                    shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${pageUrl}`;
                    break;
                case 'linkedin':
                    shareUrl = `https://www.linkedin.com/sharing/share-offsite/?url=${pageUrl}`;
                    break;
            }
            
            if (shareUrl) {
                window.open(shareUrl, '_blank', 'width=600,height=400');
            }
        });
    });
});
</script>