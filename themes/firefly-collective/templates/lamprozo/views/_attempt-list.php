<?php
/**
 * Shared attempt list partial.
 * Expects: $challenge (array), $attempts (array), $status_labels (array)
 */

$ongoing    = array_filter($attempts, fn($a) => $a['status'] === 'ongoing');
$finished   = array_filter($attempts, fn($a) => $a['status'] !== 'ongoing');
$total      = count($attempts);
$failures   = count(array_filter($attempts, fn($a) => $a['status'] === 'failed'));
$completed  = count(array_filter($attempts, fn($a) => $a['status'] === 'completed'));
$run_status = !empty($ongoing) ? 'ongoing' : ($completed > 0 ? 'completed' : 'wiped');

// Total time across all VODs in all attempts
$total_all_seconds = 0;
foreach ($attempts as $a) {
    foreach ($a['vods'] ?? [] as $vod) {
        $dur = !empty($vod['duration'])
            ? $vod['duration']
            : (function_exists('lamprozo_yt_duration') ? lamprozo_yt_duration($vod['url'] ?? '') : null);
        if (!$dur) continue;
        $parts = array_map('intval', explode(':', $dur));
        if (count($parts) === 3) $total_all_seconds += $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
        elseif (count($parts) === 2) $total_all_seconds += $parts[0] * 60 + $parts[1];
    }
}
$total_time = null;
if ($total_all_seconds > 0) {
    $h = (int)($total_all_seconds / 3600);
    $m = (int)(($total_all_seconds % 3600) / 60);
    $total_time = $h > 0 ? sprintf('%dh %dm', $h, $m) : sprintf('%dm', $m);
}
?>

<div class="challenge-page">
    <div class="challenge-header">
        <a class="challenge-back" href="/challenges">&larr; Challenges</a>
        <h1 class="challenge-title"><?php echo esc_html($challenge['title']); ?></h1>
        <p class="challenge-meta"><?php echo esc_html($challenge['type']); ?> &middot; Gen <?php echo esc_html($challenge['gen']); ?></p>
        <p class="challenge-description"><?php echo esc_html($challenge['description']); ?></p>
        <div class="challenge-stats">
            <div class="stat">
                <span class="stat__value"><?php echo $total; ?></span>
                <span class="stat__label">Attempts</span>
            </div>
            <?php
                $status_color = $run_status === 'ongoing' ? '#00c853' : ($run_status === 'completed' ? 'var(--color-twitch)' : 'var(--color-danger, #e53935)');
                $status_text  = $run_status === 'ongoing' ? 'Ongoing' : ($run_status === 'completed' ? 'Completed' : 'Wiped');
            ?>
            <?php if ($total_time): ?>
            <div class="stat">
                <span class="stat__value"><?php echo esc_html($total_time); ?></span>
                <span class="stat__label">Total Time</span>
            </div>
            <?php endif; ?>
            <div class="stat" style="--status-color: <?php echo $status_color; ?>">
                <span class="stat__value" style="color:var(--status-color)"><?php echo $status_text; ?></span>
                <span class="stat__label">Status</span>
            </div>
        </div>
    </div>

    <?php if (!empty($ongoing)): ?>
    <div class="attempts-section">
        <h2 class="attempts-section__title">Current Run</h2>
        <div class="attempts-list">
            <?php foreach ($ongoing as $attempt): ?>
            <?php include __DIR__ . '/_attempt-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="attempts-section">
        <h2 class="attempts-section__title">All Attempts</h2>
        <div class="attempts-list">
            <?php foreach ($finished as $attempt): ?>
            <?php include __DIR__ . '/_attempt-card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
