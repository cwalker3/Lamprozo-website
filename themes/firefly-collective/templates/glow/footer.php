<?php

    // theme/template/default/footer.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

?>
        </div> <!-- Close .content -->
    </main>

    <footer class="site-footer">
        <div class="footer-background">
            <div class="footer-overlay"></div>
        </div>
        <div class="footer-content">
            <div class="footer-main">
                <div class="footer-section footer-brand">
                    <div class="footer-logo">
                        <?php if (has_custom_logo()) : ?>
                            <?php the_custom_logo(); ?>
                        <?php else : ?>
                            <h2 class="footer-site-name"><?php bloginfo('name'); ?></h2>
                        <?php endif; ?>
                    </div>
                    <p class="footer-tagline"><?php bloginfo('description'); ?></p>
                    <div class="footer-social">
                        <!-- Social media icons can be added here -->
                    </div>
                </div>
                
                <div class="footer-section footer-navigation">
                    <h3><?php esc_html_e('Quick Links'); ?></h3>
                    <?php
                    wp_nav_menu(array(
                        'theme_location' => 'website-menu',
                        'menu_class'     => 'footer-menu',
                        'depth'          => 2,
                        'container'      => false,
                        'fallback_cb'    => false,
                    ));
                    ?>
                </div>
                
                <div class="footer-section footer-resources">
                    <h3><?php esc_html_e('Resources'); ?></h3>
                    <ul class="footer-menu">
                        <li><a href="/blog"><?php esc_html_e('Blog'); ?></a></li>
                        <li><a href="/privacy-policy"><?php esc_html_e('Privacy Policy'); ?></a></li>
                        <li><a href="/terms-of-service"><?php esc_html_e('Terms of Service'); ?></a></li>
                        <li><a href="/sitemap"><?php esc_html_e('Sitemap'); ?></a></li>
                    </ul>
                </div>
                
                <div class="footer-section footer-contact">
                    <h3><?php esc_html_e('Get in Touch'); ?></h3>
                    <ul class="footer-contact-list">
                        <li><a href="/contact"><?php esc_html_e('Contact Us'); ?></a></li>
                        <li><a href="/signup"><?php esc_html_e('Book Appointment'); ?></a></li>
                    </ul>
                    <div class="footer-contact-box">
                        <h4><?php esc_html_e('Send a Message'); ?></h4>
                        <form class="footer-contact-form" novalidate>
                            <label class="footer-contact-field">
                                <span class="screen-reader-text"><?php esc_html_e('Name', 'firefly-collective'); ?></span>
                                <input type="text" id="footer-contact-name" placeholder="<?php esc_attr_e('Your name', 'firefly-collective'); ?>" autocomplete="name">
                            </label>
                            <label class="footer-contact-field">
                                <span class="screen-reader-text"><?php esc_html_e('Email', 'firefly-collective'); ?></span>
                                <input type="email" id="footer-contact-email" placeholder="<?php esc_attr_e('you@example.com', 'firefly-collective'); ?>" autocomplete="email">
                            </label>
                            <label class="footer-contact-field footer-contact-field--textarea">
                                <span class="screen-reader-text"><?php esc_html_e('Message', 'firefly-collective'); ?></span>
                                <textarea id="footer-contact-message" rows="3" placeholder="<?php esc_attr_e('How can we help?', 'firefly-collective'); ?>"></textarea>
                            </label>
                            <div class="footer-contact-submit">
                                <button type="button" id="footer-contact-submit"><?php esc_html_e('Send Message', 'firefly-collective'); ?></button>
                            </div>
                        </form>
                        <div class="footer-contact-status" aria-live="polite"></div>
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="footer-bottom-content">
                    <div class="footer-copyright">
                        <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('All rights reserved.'); ?></p>
                    </div>
                    <div class="footer-legal">
                        <a href="/privacy-policy"><?php esc_html_e('Privacy'); ?></a>
                        <span class="separator">|</span>
                        <a href="/terms-of-service"><?php esc_html_e('Terms'); ?></a>
                        <span class="separator">|</span>
                        <a href="/cookies"><?php esc_html_e('Cookies'); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <?php wp_footer(); ?>
</body>
</html>
