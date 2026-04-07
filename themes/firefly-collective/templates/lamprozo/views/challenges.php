<?php
/**
 * Challenges listing — dynamically rendered from wp_options.
 */
$challenges = lamprozo_get_challenges_data();

// Build groups
$groups = ['active' => [], 'completed' => [], 'on_hold' => []];
foreach ($challenges as $slug => $challenge) {
    $s = $challenge['status'] ?? 'active';
    $attempts  = lamprozo_get_attempts($slug);
    $total     = count($attempts);
    $ongoing   = array_filter($attempts, fn($a) => $a['status'] === 'ongoing');
    $completed = array_filter($attempts, fn($a) => $a['status'] === 'completed');

    if ($s === 'completed') {
        $card_class = $badge_class = 'completed';
        $badge_text = 'Completed';
    } elseif ($s === 'on_hold') {
        $card_class = $badge_class = 'failed';
        $badge_text = 'On Hold';
    } else {
        if (!empty($ongoing))         { $card_class = $badge_class = 'ongoing';   $badge_text = 'Active'; }
        elseif (!empty($completed))   { $card_class = $badge_class = 'completed'; $badge_text = 'Completed'; }
        else                          { $card_class = $badge_class = 'failed';    $badge_text = 'Wiped'; }
    }

    $groups[$s][$slug] = compact('challenge', 'slug', 'total', 'card_class', 'badge_class', 'badge_text');
}

$group_labels = ['active' => 'Active', 'completed' => 'Completed', 'on_hold' => 'On Hold'];

function render_challenge_card($entry) { ?>
    <div class="run-card run-card--<?php echo esc_attr($entry['card_class']); ?>">
        <div class="run-card__status">
            <span class="status-badge status-badge--<?php echo esc_attr($entry['badge_class']); ?>">
                <?php echo esc_html($entry['badge_text']); ?>
            </span>
        </div>
        <div class="run-card__body">
            <h2 class="run-card__title"><?php echo esc_html($entry['challenge']['title']); ?></h2>
            <p class="run-card__meta">
                <?php echo esc_html($entry['challenge']['type']); ?> &middot; Gen <?php echo esc_html($entry['challenge']['gen']); ?>
                <?php if ($entry['total']): ?>&middot; <?php echo $entry['total']; ?> attempt<?php echo $entry['total'] !== 1 ? 's' : ''; ?><?php endif; ?>
            </p>
        </div>
        <div class="run-card__footer">
            <a class="run-card__link" href="/<?php echo esc_attr($entry['slug']); ?>">View Attempts</a>
        </div>
    </div>
<?php }
?>
<div class="runs-page">
    <div class="runs-header">
        <h1 class="runs-title">Challenges</h1>
        <p class="runs-subtitle">Every attempt. Every loss. Every victory.</p>
    </div>

    <?php foreach ($groups as $group_key => $entries):
        if (empty($entries)) continue; ?>
    <div class="challenges-group">
        <h2 class="challenges-group__title"><?php echo $group_labels[$group_key]; ?></h2>
        <div class="runs-grid">
            <?php foreach ($entries as $entry): render_challenge_card($entry); endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
