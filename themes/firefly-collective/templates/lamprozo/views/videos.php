<?php
$videos = get_option('lamprozo_videos', []);

function lamprozo_youtube_id($url) {
    preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([a-zA-Z0-9_-]{11})/', $url, $m);
    return $m[1] ?? null;
}
?>

<div class="media-page">
    <div class="media-header">
        <h1 class="media-title">Videos</h1>
        <p class="media-subtitle">Full runs, highlights, and more on YouTube.</p>
        <a class="btn btn-youtube" href="https://www.youtube.com/@Lamprozo1" target="_blank">YouTube Channel</a>
    </div>

    <?php if (!empty($videos)): ?>
    <div class="media-grid">
        <?php foreach ($videos as $video):
            $vid_id = lamprozo_youtube_id($video['url']);
        ?>
        <div class="media-card">
            <?php if ($vid_id): ?>
            <div class="media-card__embed">
                <iframe src="https://www.youtube.com/embed/<?php echo esc_attr($vid_id); ?>"
                    frameborder="0" allowfullscreen
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">
                </iframe>
            </div>
            <?php endif; ?>
            <div class="media-card__body">
                <h2 class="media-card__title"><?php echo esc_html($video['title']); ?></h2>
                <?php if (!empty($video['description'])): ?>
                <p class="media-card__desc"><?php echo esc_html($video['description']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="media-empty">
        <p>No videos yet — add some in WP Admin → Media.</p>
    </div>
    <?php endif; ?>
</div>
