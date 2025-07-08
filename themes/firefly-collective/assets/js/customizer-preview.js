/**
 * Live preview for Theme Customizer
 * File: /assets/js/customizer-preview.js
 */
(function() {
    // Listen for login slug changes
    wp.customize('custom_login_slug', function(value) {
        value.bind(function(newval) {
            // Get the home URL (assuming it's available in wp.customize)
            const homeUrl = wp.customize.settings.url.home;
            
            // Update any login links on the page (if visible)
            const loginLinks = document.querySelectorAll('a[href*="wp-login.php"], a[href*="/admin"]');
            loginLinks.forEach(function(link) {
                const href = link.getAttribute('href');
                
                // Only update if it's a login-related link
                if (href.indexOf('wp-login.php') !== -1 || href === homeUrl + '/admin') {
                    link.setAttribute('href', homeUrl + '/' + newval + '/');
                }
            });
            
            // Show a notice in the preview
            let notice = document.getElementById('login-url-preview-notice');
            
            if (!notice) {
                // Create the notice element
                notice = document.createElement('div');
                notice.id = 'login-url-preview-notice';
                notice.style.cssText = 'position: fixed; bottom: 20px; right: 20px; ' +
                    'background: #333; color: #fff; padding: 15px; ' +
                    'border-radius: 5px; z-index: 99999; font-size: 14px; ' +
                    'transition: opacity 0.3s ease;';
                
                notice.innerHTML = 'New login URL will be: <strong>' + homeUrl + '/' + newval + '/</strong>' +
                    '<br><small>Save & refresh to apply changes.</small>';
                
                document.body.appendChild(notice);
            } else {
                // Update existing notice
                const strongElement = notice.querySelector('strong');
                if (strongElement) {
                    strongElement.textContent = homeUrl + '/' + newval + '/';
                }
                
                // Make sure it's visible
                notice.style.opacity = '1';
                notice.style.display = 'block';
            }
            
            // Clear any existing timeout
            if (window.loginUrlNoticeTimeout) {
                clearTimeout(window.loginUrlNoticeTimeout);
            }
            
            // Auto-hide after 5 seconds
            window.loginUrlNoticeTimeout = setTimeout(function() {
                if (notice) {
                    notice.style.opacity = '0';
                    setTimeout(function() {
                        notice.style.display = 'none';
                    }, 300);
                }
            }, 5000);
        });
    });
})();