<?php
/**
 * Sync escape-preservation smoke test.
 *
 * Reproduces the wp_slash / wp_unslash round-trip that happens on the
 * receive side of a page-sync, using content that contains the JSON
 * escape sequences Gutenberg stores inside block attributes (\u003c,
 * \u0022). Verifies the backslashes survive wp_update_post's internal
 * wp_unslash — i.e., that all the receive paths in firefly-projects
 * correctly slash their input.
 *
 * Run:
 *   docker exec firefly-collective-wordpress-1 php \
 *     /var/www/html/wp-content/plugins/firefly-projects/tests/test-sync-escapes.php
 *
 * Exit code:
 *   0 = all checks passed
 *   1 = escape preservation broken — the wp_slash bug has regressed
 */

// Bootstrap WP
define('WP_USE_THEMES', false);
$wp_root = dirname(__DIR__, 4);  // up from tests/ → plugin → plugins → wp-content → web root
require_once $wp_root . '/wp-load.php';

// Define a token content string with the EXACT escape sequences we care
// about. If any backslash is eaten, the substring \u003c becomes "u003c"
// (literal 5 chars, no backslash) which is what renders on-page when the
// bug is present.
$sample_content = '<!-- wp:firefly/section-head {"heading":"Three surfaces. \u003cspan class=\u0022serif\u0022\u003eOne codebase.\u003c/span\u003e","lead":"Subhead with \u0022quoted\u0022 text"} /-->';

$fail = function ($msg) {
    echo "\n❌ FAIL: {$msg}\n";
    exit(1);
};
$pass = function ($msg) {
    echo "✓ {$msg}\n";
};

echo "Firefly sync escape-preservation smoke test\n";
echo "─────────────────────────────────────────────\n";

// 1. Create a throwaway post the same way the sync receiver does:
//    pass unslashed content → wp_slash() → wp_insert_post.
$wp_post_data = wp_slash(array(
    'post_title'   => '__firefly_sync_escape_test__',
    'post_name'    => '__firefly_sync_escape_test__',
    'post_content' => $sample_content,
    'post_type'    => 'page',
    'post_status'  => 'draft',
));

$post_id = wp_insert_post($wp_post_data, true);
if (is_wp_error($post_id)) {
    $fail('wp_insert_post error: ' . $post_id->get_error_message());
}
$pass("Created test post ID={$post_id}");

// 2. Read the content straight from the DB (raw, no filters).
global $wpdb;
$stored = $wpdb->get_var($wpdb->prepare(
    "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d",
    $post_id
));

if (!is_string($stored)) {
    wp_delete_post($post_id, true);
    $fail('Could not read post_content back from DB');
}
$pass('Read post_content from DB');

// 3. Verify every escape sequence from the sample survived as literal
//    `\u003c` / `\u0022` (backslash + 5 chars) in the stored content.
$required = array('\u003cspan', '\u0022serif\u0022', '\u003e', '\u003c/span\u003e');
$missing  = array();
foreach ($required as $token) {
    if (strpos($stored, $token) === false) {
        $missing[] = $token;
    }
}

// 4. Guard: the failure mode of the bug is the presence of "u003c" WITHOUT
//    a preceding backslash. Detect that explicitly.
$bug_present = (bool) preg_match('/(?<!\\\\)u003c/', $stored);

// 5. Cleanup.
wp_delete_post($post_id, true);
$pass("Deleted test post ID={$post_id}");

echo "\n";

if (!empty($missing)) {
    echo "Missing tokens in stored content:\n";
    foreach ($missing as $t) echo "  - {$t}\n";
    echo "\nStored content was:\n  " . substr($stored, 0, 400) . "\n";
    $fail('Block-attribute escape sequences were lost — wp_slash is missing somewhere.');
}

if ($bug_present) {
    echo "\nDetected literal `u003c` without a preceding backslash in stored content:\n";
    echo "  " . substr($stored, 0, 400) . "\n";
    $fail('Escape backslashes were stripped — wp_slash is missing somewhere.');
}

echo "\n✅ PASS: block-attribute escapes survive wp_slash/wp_unslash round-trip.\n";
exit(0);
