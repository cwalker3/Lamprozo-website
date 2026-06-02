<?php
/**
 * Home view — renders page content with dynamic active challenge + Twitch iframe injection.
 *
 * The Twitch iframe is rendered server-side here (rather than from the snippet)
 * because wp_kses strips <script> and <iframe> tags from post content during
 * the firefly-projects cross-environment sync.
 */

$twitch_channel = 'lamprozo';

// Find the active challenge with an ongoing attempt
$active_challenge = null;
$active_attempts  = [];

if (function_exists('lamprozo_get_challenges_data')) {
    foreach (lamprozo_get_challenges_data() as $slug => $challenge) {
        if (($challenge['status'] ?? 'active') !== 'active') continue;
        $attempts = lamprozo_get_attempts($slug);
        $ongoing  = array_filter($attempts, fn($a) => $a['status'] === 'ongoing');
        if (!empty($ongoing)) {
            $active_challenge = $challenge;
            $active_attempts  = array_values($ongoing);
            break;
        }
    }
}

$challenge_html = '';
if ($active_challenge):
    $attempt = $active_attempts[0];
    ob_start();
?>
<section class="active-challenge">
    <div class="active-challenge__inner">
        <p class="active-challenge__label">Current Nuzlocke</p>
        <h2 class="active-challenge__title"><?php echo esc_html($active_challenge['title']); ?></h2>
        <p class="active-challenge__meta">
            <?php echo esc_html($active_challenge['type']); ?> &middot; Gen <?php echo esc_html($active_challenge['gen']); ?>
            &middot; Attempt #<?php echo $attempt['number']; ?>
            <?php if (!empty($attempt['split'])): ?>&middot; <?php echo esc_html($attempt['split']); ?><?php endif; ?>
        </p>
        <a class="btn btn-primary" href="/<?php echo esc_attr($active_challenge['slug']); ?>">View Attempts</a>
    </div>
</section>
<?php
    $challenge_html = ob_get_clean();
endif;

// Build the Twitch iframe — uses host from request so it works across
// localhost, dev, and prod without hardcoding the parent domain.
$parent_host  = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-zA-Z0-9.\-:]/', '', $_SERVER['HTTP_HOST']) : '';
$parent_param = $parent_host ? '&parent=' . rawurlencode(preg_replace('/:\d+$/', '', $parent_host)) : '';
$twitch_iframe = '<iframe src="https://player.twitch.tv/?channel=' . rawurlencode($twitch_channel) . $parent_param . '&autoplay=false" '
               . 'allowfullscreen="true" frameborder="0" scrolling="no" '
               . 'class="twitch-iframe" title="Lamprozo on Twitch"></iframe>';

// Inject Twitch iframe in place of the embed placeholder
$content = str_replace(
    '<div id="twitch-embed"></div>',
    $twitch_iframe,
    $content
);

// Inject challenge block just before the stream section (match on opening
// class — tolerant of any extra attributes added to the <section>)
if ($challenge_html) {
    $content = preg_replace(
        '/<section\s+class="stream-section"/',
        $challenge_html . '<section class="stream-section"',
        $content,
        1
    );
}

echo $content;
