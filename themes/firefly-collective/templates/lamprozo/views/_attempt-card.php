<?php
/**
 * Single attempt card partial.
 * Expects: $attempt (array), $status_labels (array), $attempt_index (int)
 */
$vods  = $attempt['vods']  ?? [];
$split = $attempt['split'] ?? '';
$notes = $attempt['notes'] ?? '';
$is_ongoing = $attempt['status'] === 'ongoing';

if (!function_exists('lamprozo_yt_id')) {
    function lamprozo_yt_id($url) {
        preg_match('/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([a-zA-Z0-9_-]{11})/', $url, $m);
        return $m[1] ?? null;
    }
}

// Pre-fetch all durations and compute total
$vod_durations = [];
$total_seconds = 0;
if (!empty($vods)) {
    foreach ($vods as $vi => $vod) {
        $dur = !empty($vod['duration'])
            ? $vod['duration']
            : (function_exists('lamprozo_yt_duration') ? lamprozo_yt_duration($vod['url'] ?? '') : null);
        $vod_durations[$vi] = $dur;
        if ($dur) {
            $parts = explode(':', $dur);
            $parts = array_map('intval', $parts);
            if (count($parts) === 3) {
                $total_seconds += $parts[0] * 3600 + $parts[1] * 60 + $parts[2];
            } elseif (count($parts) === 2) {
                $total_seconds += $parts[0] * 60 + $parts[1];
            }
        }
    }
}
$total_duration = null;
if ($total_seconds > 0) {
    $h = (int)($total_seconds / 3600);
    $m = (int)(($total_seconds % 3600) / 60);
    $s = $total_seconds % 60;
    $total_duration = $h > 0
        ? sprintf('%dh %dm', $h, $m)
        : sprintf('%dm %ds', $m, $s);
}

// Parse fragsheet upfront so both header and body can use it
$fragsheet_alive = [];
$fragsheet_dead  = [];
$fragsheet = $attempt['fragsheet'] ?? null;
if (!empty($fragsheet)) {
    foreach ($fragsheet as $species => $mon) {
        if ($mon['hide'] ?? false) continue;
        $ivs = $mon['setData']['My Box']['ivs'] ?? [];
        $entry = [
            'species'   => $species,
            'nickname'  => $mon['nn'] ?? null,
            'fragCount' => $mon['fragCount'] ?? 0,
            'frags'     => $mon['frags'] ?? [],
            'met'       => $mon['setData']['My Box']['met'] ?? '',
            'nature'    => $mon['setData']['My Box']['nature'] ?? '',
            'ability'   => $mon['setData']['My Box']['ability'] ?? '',
            'alive'     => $mon['alive'] ?? false,
            'ivs'       => $ivs,
        ];
        if ($entry['alive']) $fragsheet_alive[] = $entry;
        else                 $fragsheet_dead[]  = $entry;
    }
    usort($fragsheet_alive, fn($a, $b) => $b['fragCount'] - $a['fragCount']);
    usort($fragsheet_dead,  fn($a, $b) => $b['fragCount'] - $a['fragCount']);
}
?>
<div class="attempt-card attempt-card--<?php echo esc_attr($attempt['status']); ?><?php echo $is_ongoing ? ' attempt-card--open' : ''; ?>">
    <button class="attempt-card__header" onclick="this.closest('.attempt-card').classList.toggle('attempt-card--open')" aria-expanded="<?php echo $is_ongoing ? 'true' : 'false'; ?>">
        <div class="attempt-card__left">
            <span class="attempt-card__number">Attempt #<?php echo $attempt['number']; ?></span>
            <span class="status-badge status-badge--<?php echo esc_attr($attempt['status']); ?>">
                <?php echo esc_html($status_labels[$attempt['status']] ?? $attempt['status']); ?>
            </span>
            <?php if ($split): ?>
            <span class="attempt-card__split">📍 <?php echo esc_html($split); ?></span>
            <?php endif; ?>
        </div>
        <div class="attempt-card__meta-right">
            <?php if (!empty($fragsheet_dead)): ?>
            <span class="attempt-card__vod-count">💀 <?php echo count($fragsheet_dead); ?></span>
            <?php endif; ?>
            <?php if (!empty($vods)): ?>
            <span class="attempt-card__vod-count"><?php echo count($vods); ?> VOD<?php echo count($vods) !== 1 ? 's' : ''; ?><?php if ($total_duration): ?> · <?php echo esc_html($total_duration); ?><?php endif; ?></span>
            <?php endif; ?>
            <span class="attempt-card__chevron">▼</span>
        </div>
    </button>

    <div class="attempt-card__body">
        <?php if ($notes): ?>
        <p class="attempt-card__notes"><?php echo esc_html($notes); ?></p>
        <?php endif; ?>

        <?php if (!empty($vods)): ?>
        <div class="attempt-card__vod-grid">
            <?php foreach ($vods as $vi => $vod):
                $yt_id = lamprozo_yt_id($vod['url']);
                $thumb = $yt_id ? "https://img.youtube.com/vi/{$yt_id}/hqdefault.jpg" : null;
                $custom_label = trim($vod['label'] ?? '');
                $label = ($custom_label && $custom_label !== 'VOD')
                    ? $custom_label
                    : (lamprozo_yt_title($vod['url']) ?? $custom_label ?: 'VOD');
                $duration = $vod_durations[$vi] ?? null;
            ?>
            <?php if ($yt_id): ?>
            <button class="vod-thumb" onclick="lamprozoOpenVod('<?php echo esc_js($yt_id); ?>', <?php echo esc_attr(json_encode($label)); ?>)">
            <?php else: ?>
            <a class="vod-thumb" href="<?php echo esc_url($vod['url']); ?>" target="_blank">
            <?php endif; ?>
                <?php if ($thumb): ?>
                <div class="vod-thumb__img" style="background-image:url('<?php echo esc_url($thumb); ?>')">
                    <span class="vod-thumb__num"><?php echo $vi + 1; ?></span>
                    <span class="vod-thumb__play">▶</span>
                    <?php if ($duration): ?>
                    <span class="vod-thumb__duration"><?php echo esc_html($duration); ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="vod-thumb__img vod-thumb__img--no-thumb">
                    <span class="vod-thumb__num"><?php echo $vi + 1; ?></span>
                    <span class="vod-thumb__play">▶</span>
                    <?php if ($duration): ?>
                    <span class="vod-thumb__duration"><?php echo esc_html($duration); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <span class="vod-thumb__label"><?php echo esc_html($label); ?></span>
            <?php if ($yt_id): ?></button><?php else: ?></a><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php elseif (!$notes): ?>
        <p class="attempt-card__empty">No VODs or notes for this attempt.</p>
        <?php endif; ?>

        <?php if (!empty($fragsheet_alive) || !empty($fragsheet_dead)): ?>
        <div class="fragsheet">
            <?php
            $render_mons = function($mons) {
                foreach ($mons as $mon):
                    $ivs     = $mon['ivs'] ?? [];
                    $iv_str  = implode('/', array_map(fn($k) => $ivs[$k] ?? '?', ['hp','at','df','sa','sd','sp']));
                    $tooltip = json_encode([
                        'nature'  => $mon['nature'],
                        'ability' => $mon['ability'],
                        'met'     => $mon['met'],
                        'ivs'     => $ivs,
                    ]);
                    $sprite_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $mon['species']));
            ?>
                    <div class="frag-mon frag-mon--<?php echo $mon['alive'] ? 'alive' : 'dead'; ?>"
                         data-mon="<?php echo esc_attr($tooltip); ?>"
                         onmouseenter="lamprozoShowMonTooltip(this)"
                         onmouseleave="lamprozoHideMonTooltip()">
                        <img class="frag-mon__sprite" src="https://img.pokemondb.net/sprites/heartgold-soulsilver/normal/<?php echo esc_attr($sprite_slug); ?>.png"
                             alt="<?php echo esc_attr($mon['species']); ?>" onerror="this.style.display='none'">
                        <span class="frag-mon__name"><?php echo esc_html($mon['nickname'] ?? $mon['species']); ?></span>
                        <?php if ($mon['fragCount'] > 0): ?>
                        <span class="frag-mon__kills">💀 <?php echo $mon['fragCount']; ?></span>
                        <?php endif; ?>
                    </div>
            <?php endforeach; };
            ?>
            <?php if (!empty($fragsheet_alive)): ?>
            <div class="fragsheet__section">
                <h4 class="fragsheet__heading">Box</h4>
                <div class="fragsheet__grid"><?php $render_mons($fragsheet_alive); ?></div>
            </div>
            <?php endif; ?>
            <?php if (!empty($fragsheet_dead)): ?>
            <div class="fragsheet__section">
                <h4 class="fragsheet__heading">Deaths</h4>
                <div class="fragsheet__grid"><?php $render_mons($fragsheet_dead); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!defined('LAMPROZO_MON_TOOLTIP_RENDERED')): define('LAMPROZO_MON_TOOLTIP_RENDERED', true); ?>
<div id="mon-tooltip" class="mon-tooltip" style="display:none">
    <div class="mon-tooltip__name"></div>
    <div class="mon-tooltip__nature"></div>
    <div class="mon-tooltip__ability"></div>
    <div class="mon-tooltip__met"></div>
    <table class="mon-tooltip__ivs">
        <thead><tr><th>HP</th><th>Atk</th><th>Def</th><th>SpA</th><th>SpD</th><th>Spe</th></tr></thead>
        <tbody><tr>
            <td class="iv-hp"></td><td class="iv-at"></td><td class="iv-df"></td>
            <td class="iv-sa"></td><td class="iv-sd"></td><td class="iv-sp"></td>
        </tr></tbody>
    </table>
    <div class="mon-tooltip__kills"></div>
</div>
<script>
function lamprozoShowMonTooltip(el) {
    const data = JSON.parse(el.dataset.mon || '{}');
    const tip  = document.getElementById('mon-tooltip');
    tip.querySelector('.mon-tooltip__name').textContent    = el.querySelector('.frag-mon__name')?.textContent || '';
    tip.querySelector('.mon-tooltip__nature').textContent  = data.nature  || '';
    tip.querySelector('.mon-tooltip__ability').textContent = data.ability || '';
    tip.querySelector('.mon-tooltip__met').textContent     = data.met ? 'Caught: ' + data.met : '';
    const ivs = data.ivs || {};
    ['hp','at','df','sa','sd','sp'].forEach(k => {
        const td = tip.querySelector('.iv-' + k);
        const v  = ivs[k] ?? '?';
        td.textContent = v;
        td.className   = 'iv-' + k + (v === 31 ? ' iv--max' : v === 0 ? ' iv--zero' : '');
    });
    const killEl = tip.querySelector('.mon-tooltip__kills');
    killEl.innerHTML = '';

    tip.style.display = 'block';
    const rect = el.getBoundingClientRect();
    tip.style.top  = (window.scrollY + rect.bottom + 6) + 'px';
    tip.style.left = Math.min(window.scrollX + rect.left, window.innerWidth - 220) + 'px';
}
function lamprozoHideMonTooltip() {
    document.getElementById('mon-tooltip').style.display = 'none';
}
</script>
<?php endif; ?>

<?php if (!defined('LAMPROZO_VOD_MODAL_RENDERED')): define('LAMPROZO_VOD_MODAL_RENDERED', true); ?>
<div id="vod-modal" class="vod-modal" onclick="if(event.target===this)lamprozoCloseVod()">
    <div class="vod-modal__box">
        <div class="vod-modal__header">
            <span id="vod-modal-title" class="vod-modal__title"></span>
            <button class="vod-modal__close" onclick="lamprozoCloseVod()">✕</button>
        </div>
        <div class="vod-modal__embed">
            <iframe id="vod-modal-iframe" src="" frameborder="0" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>
        </div>
    </div>
</div>
<script>
function lamprozoOpenVod(ytId, title) {
    document.getElementById('vod-modal-title').textContent = title;
    document.getElementById('vod-modal-iframe').src = 'https://www.youtube.com/embed/' + ytId + '?autoplay=1';
    document.getElementById('vod-modal').classList.add('vod-modal--open');
    document.body.style.overflow = 'hidden';
}
function lamprozoCloseVod() {
    document.getElementById('vod-modal').classList.remove('vod-modal--open');
    document.getElementById('vod-modal-iframe').src = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') lamprozoCloseVod(); });
</script>
<?php endif; ?>
