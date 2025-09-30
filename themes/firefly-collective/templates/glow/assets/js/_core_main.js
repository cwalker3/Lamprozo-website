// theme/assets/js/_core_main.js

// Remove empty paragraph tags immediately to prevent layout shift
function removeEmptyParagraphs() {
    const emptyParagraphs = document.querySelectorAll('p');
    emptyParagraphs.forEach(p => {
        // Remove if completely empty or only contains whitespace
        if (!p.textContent.trim() && !p.children.length) {
            p.remove();
        }
    });
}

// Run immediately if DOM is already loaded
if (document.readyState === 'loading') {
    // If still loading, run as soon as DOM is ready (but before images/styles finish)
    document.addEventListener('DOMContentLoaded', removeEmptyParagraphs);
} else {
    // DOM already loaded, run immediately
    removeEmptyParagraphs();
}

// Also run a cleanup after everything loads as a fallback
window.addEventListener('load', removeEmptyParagraphs);

(function () {
    function initFooterContactForm() {
        var form = document.querySelector('.footer-contact-form');
        if (!form) {
            return;
        }

        var nameInput = form.querySelector('#footer-contact-name');
        var emailInput = form.querySelector('#footer-contact-email');
        var messageInput = form.querySelector('#footer-contact-message');
        var button = form.querySelector('#footer-contact-submit');
        var statusEl = document.querySelector('.footer-contact-status');
        var api = window.myApi || {};

        if (!nameInput || !emailInput || !messageInput || !button || !statusEl) {
            return;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
        });

        button.addEventListener('click', function () {
            clearStatus();

            if (!validateFields()) {
                setStatus('Please complete all fields with a valid email address.', true);
                return;
            }

            setLoading(true);

            var formData = new FormData();
            formData.append('name', nameInput.value.trim());
            formData.append('email', emailInput.value.trim());
            formData.append('message', messageInput.value.trim());

            var endpoint = String(api.api_url || '');

            fetch(endpoint + 'submit-contact', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': api.nonce || ''
                },
                body: formData
            })
                .then(function (response) {
                    if (!response.ok) {
                        return response.json().then(function (data) {
                            var message = data && data.message ? data.message : 'An error occurred.';
                            throw new Error(message);
                        });
                    }
                    return response.json();
                })
                .then(function (data) {
                    var message = data && data.message ? data.message : 'Message sent successfully.';
                    setStatus(message, false);
                    button.disabled = true;
                    button.textContent = 'Message Sent';
                    form.reset();
                    [nameInput, emailInput, messageInput].forEach(function (field) {
                        field.classList.remove('valid', 'invalid');
                    });
                })
                .catch(function (error) {
                    console.error('Footer contact error:', error);
                    setStatus(error.message || 'An error occurred.', true);
                })
                .finally(function () {
                    setLoading(false);
                });
        });

        function validateFields() {
            var emailPattern = /^(?:[a-zA-Z0-9_'^&\/+{}\-]+(?:\.[a-zA-Z0-9_'^&\/+{}\-]+)*)@(?:(?:[a-zA-Z0-9-]+\.)+[a-zA-Z]{2,})$/;
            var valid = true;

            [
                [nameInput, function (value) { return value.length > 0; }],
                [emailInput, function (value) { return emailPattern.test(value); }],
                [messageInput, function (value) { return value.length > 0; }]
            ].forEach(function (entry) {
                var field = entry[0];
                var test = entry[1];
                var value = field.value.trim();
                var passes = test(value);

                field.classList.toggle('valid', passes);
                field.classList.toggle('invalid', !passes);

                if (!passes) {
                    valid = false;
                }
            });

            return valid;
        }

        function setLoading(isLoading) {
            button.classList.toggle('loading', isLoading);
            if (isLoading) {
                button.textContent = 'Sending…';
            } else if (!button.disabled) {
                button.textContent = 'Send Message';
            }
        }

        function setStatus(message, isError) {
            statusEl.removeAttribute('hidden');
            statusEl.textContent = message;
            statusEl.setAttribute('data-state', isError ? 'error' : 'success');
        }

        function clearStatus() {
            statusEl.textContent = '';
            statusEl.setAttribute('data-state', '');
            statusEl.setAttribute('hidden', 'hidden');
        }

        clearStatus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFooterContactForm);
    } else {
        initFooterContactForm();
    }
})();
