<?php

    // theme/template/default/footer.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

?>
        </div> <!-- Close .content -->
    </main>

    <footer>
        <h2><?php esc_html_e('Footer'); ?></h2>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>