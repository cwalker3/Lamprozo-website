<?php

    // theme/template/default/footer.php

    if (!defined('ABSPATH')) {
        exit; // Exit if accessed directly
    }

?>
        </div> <!-- Close .content -->
    </main>

    <footer class="site-footer" role="contentinfo" itemscope itemtype="https://schema.org/WPFooter">
        <div class="footer-background">
            <div class="footer-overlay"></div>
        </div>
        <div class="footer-content" itemscope itemtype="https://schema.org/Organization">
            <meta itemprop="name" content="<?php echo esc_attr( get_bloginfo('name') ); ?>">
            <div class="footer-main">
                <div class="footer-section footer-brand">
                    <div class="footer-logo">
                        <?php if (has_custom_logo()) : ?>
                            <?php the_custom_logo(); ?>
                        <?php else : ?>
                            <h2 class="footer-site-name" itemprop="legalName"><?php bloginfo('name'); ?></h2>
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
                        <li><a href="/request-an-appointment"><?php esc_html_e('Book Appointment'); ?></a></li>
                    </ul>
                    <div class="footer-newsletter">
                        <h4><?php esc_html_e('Stay Updated'); ?></h4>
                        <form class="newsletter-form" id="newsletter-form" method="post">
                            <input type="email" name="email" placeholder="<?php esc_attr_e('Your email'); ?>" required>
                            <button type="submit"><?php esc_html_e('Subscribe'); ?></button>
                        </form>
                        <div class="newsletter-message" id="newsletter-message" role="status" aria-live="polite"></div>
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
    <script>
    (function(){
        var form = document.getElementById('newsletter-form');
        var msg  = document.getElementById('newsletter-message');
        if (!form || !msg) return;

        form.addEventListener('submit', function(e){
            e.preventDefault();
            var input = form.querySelector('input[name="email"]');
            var btn   = form.querySelector('button[type="submit"]');
            var email = (input.value || '').trim();
            if (!email) return;

            msg.textContent = '';
            msg.className   = 'newsletter-message';
            btn.disabled = true;

            fetch(myApi.api_url + 'submit-newsletter', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': myApi.nonce },
                body: JSON.stringify({ email: email })
            })
            .then(function(r){ return r.json().then(function(d){ return { ok: r.ok, body: d }; }); })
            .then(function(res){
                btn.disabled = false;
                msg.textContent = (res.body && res.body.message) || (res.ok ? 'Subscribed.' : 'Something went wrong.');
                msg.className   = 'newsletter-message ' + (res.ok ? 'success' : 'error');
                if (res.ok) form.reset();
            })
            .catch(function(){
                btn.disabled = false;
                msg.textContent = 'Network error. Please try again.';
                msg.className   = 'newsletter-message error';
            });
        });
    })();
    </script>
</body>
</html>