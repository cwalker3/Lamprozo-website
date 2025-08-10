<?php

    // theme/footer.php - Template Control File

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

    // Ensure customize model is loaded
    if (!function_exists('firefly_collective_get_active_template')) {
        require_once get_template_directory() . '/models/customize.php';
    }

    // Ensure $args is available
    if (!isset($args) || !is_array($args)) {
        $args = array();
    }

    // Try to load the footer from the active template
    if (firefly_collective_load_template_file('footer.php', $args)) {
        // Template loaded successfully
        return;
    }
    
    // If we get here, the template failed to load
    // Try to load the default template as fallback
    $default_footer_path = firefly_collective_get_template_file_path('footer.php', FIREFLY_COLLECTIVE_DEFAULT_TEMPLATE);
    if ($default_footer_path) {
        // Extract args for use in template
        if (!empty($args)) {
            extract($args, EXTR_SKIP);
        }
        include $default_footer_path;
        return;
    }
    
    // Ultimate fallback: Basic footer if even default template fails
    ?>
        </div> <!-- Close .content -->
    </main>
    
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html(get_bloginfo('name')); ?></p>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
    <?php