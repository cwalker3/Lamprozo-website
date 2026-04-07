<?php
/**
 * Home view — renders page content then injects dynamic active challenge block.
 */

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
        <p class="active-challenge__label">Current Challenge</p>
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

// Inject challenge block just before the stream section
if ($challenge_html) {
    echo str_replace('<section class="stream-section">', $challenge_html . '<section class="stream-section">', $content);
} else {
    echo $content;
}
