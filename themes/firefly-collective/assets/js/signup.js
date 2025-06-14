// theme/assets/js/signup.js

(function() {
    // Store initialization state
    let isInitialized = false;
    
    // Main initialization function
    window.initializeSignup = function() {
        // Prevent multiple initializations
        if (isInitialized) return;

        if (isPWA || websiteApp) {
            myApi = {
                nonce: window.nonce,
                api_url: window.api_url
            }
            themePath = window.theme_path;
        }

        let usernameValid = true;
        let emailValid = true;

        const signUpBtn = document.getElementById('signup-btn');
        if (signUpBtn) {
            signUpBtn.addEventListener('click', signup);
        }
        const signupMethodRadios = document.getElementsByName('signup-method');
        for (let radio of signupMethodRadios) {
            radio.addEventListener('change', function() {
                if (this.value === 'direct') {
                    document.getElementById('direct-signup-fields').style.display = 'grid';
                    document.getElementById('third-party-signup-fields').style.display = 'none';
                } else if (this.value === 'third') {
                    document.getElementById('direct-signup-fields').style.display = 'none';
                    document.getElementById('third-party-signup-fields').style.display = 'grid';
                }
            });
        }
        const enableUsernamePassword = document.getElementById('enable-username-password');
        if (enableUsernamePassword) {
            enableUsernamePassword.addEventListener('change', function() {
                const fields = document.getElementById('username-password-fields');
                fields.style.display = this.checked ? 'grid' : 'none';
                if (!this.checked) {
                    usernameValid = true;
                    const usernameInput = document.getElementById('signup-form-username');
                    if (usernameInput) {
                        usernameInput.classList.remove('error');
                    }
                    updateJoinButtonState();
                }
            });
        }
        const togglePassword = document.getElementById('toggle-password');
        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                const passwordInput = document.getElementById('signup-form-password');
                passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
            });
        }
        const emailInput = document.getElementById('signup-form-email');
        if (emailInput) {
            emailInput.addEventListener('blur', function() {
                const errorTxt = document.getElementById('error-txt');
                const email = emailInput.value.trim();
                if (email.length > 0) {
                    fetch(`${myApi.api_url}check-email?email=${encodeURIComponent(email)}`, {
                        method: 'GET',
                        headers: { 'X-WP-Nonce': myApi.nonce }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            errorTxt.innerHTML = 'Email already exists.';
                            emailInput.classList.add('error');
                            emailValid = false;
                        } else {
                            if (errorTxt.innerHTML === 'Email already exists.') {
                                errorTxt.innerHTML = '';
                            }
                            emailInput.classList.remove('error');
                            emailValid = true;
                        }
                        updateJoinButtonState();
                    })
                    .catch(error => {
                        console.error('Error checking email:', error);
                    });
                }
            });
        }
        const usernameInput = document.getElementById('signup-form-username');
        if (usernameInput) {
            usernameInput.addEventListener('blur', function() {
                const errorTxt = document.getElementById('error-txt');
                const username = usernameInput.value.trim();
                if (username.length > 0 && enableUsernamePassword.checked) {
                    fetch(`${myApi.api_url}check-username?username=${encodeURIComponent(username)}`, {
                        method: 'GET',
                        headers: { 'X-WP-Nonce': myApi.nonce }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.exists) {
                            errorTxt.innerHTML = 'Username already exists.';
                            usernameInput.classList.add('error');
                            usernameValid = false;
                        } else {
                            if (errorTxt.innerHTML === 'Username already exists.') {
                                errorTxt.innerHTML = '';
                            }
                            usernameInput.classList.remove('error');
                            usernameValid = true;
                        }
                        updateJoinButtonState();
                    })
                    .catch(error => {
                        console.error('Error checking username:', error);
                    });
                }
            });
        }

        function signup() {
            const fnameInput = document.getElementById('signup-form-fname');
            const lnameInput = document.getElementById('signup-form-lname');
            const phoneInput = document.getElementById('signup-form-phone');
            const emailInput = document.getElementById('signup-form-email');
            const errorTxt = document.getElementById('error-txt');
            const signUpBtn = document.getElementById('signup-btn');

            let fname = fnameInput.value.trim();
            let lname = lnameInput.value.trim();
            let phone = phoneInput.value.trim();
            let email = emailInput.value.trim();

            errorTxt.innerHTML = '';

            let validationErrors = validateSignup(fname, lname, email, phone);
            
            const enableUsernamePassword = document.getElementById('enable-username-password');
            let username = '';
            let password = '';
            if (enableUsernamePassword.checked) {
                const usernameInput = document.getElementById('signup-form-username');
                const passwordInput = document.getElementById('signup-form-password');
                username = usernameInput.value.trim();
                password = passwordInput.value.trim();
                if (!username) validationErrors.push('Username is required.');
                if (!password) validationErrors.push('Password is required.');
                else if (password.length < 6) validationErrors.push('Password must be at least 6 characters.');
            }

            if (validationErrors.length > 0) {
                errorTxt.innerHTML = validationErrors.join('<br>');
                return;
            }

            signUpBtn.innerHTML = `<img class="loader" src="${themePath}/images/loading.gif" alt="Loading">`;

            let formData = new FormData();
            formData.append('fname', fname);
            formData.append('lname', lname);
            formData.append('phone', phone);
            formData.append('email', email);
            if (enableUsernamePassword.checked) {
                formData.append('username', username);
                formData.append('password', password);
            }

            fetch(`${myApi.api_url}submit-signup`, {
                method: 'POST',
                headers: { 'X-WP-Nonce': myApi.nonce },
                body: formData,
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => {
                        throw new Error(data.message || 'An error occurred.');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.redirect) {
                    // App login
                    if (isPWA || websiteApp) {
                        // From app.js
                        loginUser(data.auth_id);
                    }
                    else {
                        // Website login
                        window.location.href = data.redirect;
                    }
                } else {
                    throw new Error('No redirect specified.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorTxt.textContent = error.message || 'An error occurred.';
                if (error.message.indexOf('Username already exists') !== -1) {
                    const usernameInput = document.getElementById('signup-form-username');
                    if (usernameInput) {
                        usernameInput.classList.add('error');
                        usernameInput.focus();
                    }
                }
                signUpBtn.textContent = 'Join Now';
            });
        }

        function validateSignup(fname, lname, email, phone) {
            let errors = [];
            if (!fname) errors.push('First name is required.');
            if (!lname) errors.push('Last name is required.');
            if (!isValidEmail(email)) errors.push('Valid email is required.');
            if (phone && !isValidPhoneNumber(phone)) errors.push('Valid phone number is required.');
            return errors;
        }

        function updateJoinButtonState() {
            const signUpBtn = document.getElementById('signup-btn');
            if (signUpBtn) {
                signUpBtn.disabled = !(usernameValid && emailValid);
            }
        }
    }
        // Reset function to allow re-initialization if needed
    window.resetsignup = function() {
        isInitialized = false;
    };
    
    // Still run on DOMContentLoaded in case the HTML is already there
    document.addEventListener('DOMContentLoaded', function() {
        // Only initialize if the signup container exist
        window.initializeSignup();
    });
})();