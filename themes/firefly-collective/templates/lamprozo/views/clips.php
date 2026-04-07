<?php
$clips = get_option('lamprozo_clips', []);

function lamprozo_twitch_clip_id($url) {
    preg_match('/clips\.twitch\.tv\/([a-zA-Z0-9_-]+)/', $url, $m);
    if (!empty($m[1])) return $m[1];
    preg_match('/twitch\.tv\/[^\/]+\/clip\/([a-zA-Z0-9_-]+)/', $url, $m);
    return $m[1] ?? null;
}
?>

<div class="media-page">
    <div class="media-header">
        <h1 class="media-title">Clips</h1>
        <p class="media-subtitle">The best moments from the runs.</p>
        <a class="btn btn-twitch" href="https://twitch.tv/lamprozo" target="_blank">Twitch Channel</a>
    </div>

    <?php if (!empty($clips)): ?>
    <div class="media-grid">
        <?php foreach ($clips as $clip):
            $clip_id = lamprozo_twitch_clip_id($clip['url']);
            $hostname = $_SERVER['HTTP_HOST'] ?? 'localhost';
        ?>
        <div class="media-card">
            <?php if ($clip_id): ?>
            <div class="media-card__embed">
                <iframe src="https://clips.twitch.tv/embed?clip=<?php echo esc_attr($clip_id); ?>&parent=<?php echo esc_attr($hostname); ?>"
                    frameborder="0" allowfullscreen scrolling="no">
                </iframe>
            </div>
            <?php endif; ?>
            <div class="media-card__body">
                <h2 class="media-card__title"><?php echo esc_html($clip['title']); ?></h2>
                <?php if (!empty($clip['description'])): ?>
                <p class="media-card__desc"><?php echo esc_html($clip['description']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="media-empty">
        <p>No clips yet — add some in WP Admin → Media.</p>
    </div>
    <?php endif; ?>
</div>
