document.addEventListener('DOMContentLoaded', function () {
    const page = window.location.pathname.split('/')[1];
    const themePath = myApi.themePath;
    const logoNameEle = document.querySelector('#logo-name');
    const maxBlogsPerPage = parseInt(myApi.maxBlogs);
    let usernameValid = true;
    let emailValid = true;
    let blogPageNum = 2;
    let blogFilterOptions = {
        category_id: '',
        tag_id: '',
        month: '',
        year: '',
        keywords: ''
    };

    logoNameEle.addEventListener('pointerup', ()=>{window.location="/"});
    handleContactSticky();

    switch (page) {

        case 'contact':
            const sendMessageBtn = document.getElementById('send-message-btn');
            if (sendMessageBtn) {
                sendMessageBtn.addEventListener('click', sendContactMessage);
            }
            contactSticky.style.opacity = '0';
            break;

        case 'signup':
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
            break;
        
        case 'request-an-appointment':
            let bookAnAppointmentBtn = document.querySelector('#book-an-appointment-btn');
            bookAnAppointmentBtn.addEventListener('pointerup', bookAnAppointment);
    
            saveCalendarData(calData.calendar);
            renderCalendar('calendar-container');
    
            const typesSelect = document.querySelector('#book-an-appointment-type');
            if (typesSelect && calData.booking_types) {
                calData.booking_types.forEach(type => {
                    const option = document.createElement('option');
                    option.value = type.type_title;
                    option.textContent = type.type_title;
                    typesSelect.appendChild(option);
                });
            }
            break;

        case 'blog':
            const target = document.getElementById('blogs-end');
            const blogKeywordsInput = document.querySelector('#blog-filter-keywords');
            const blogFilterBtn = document.getElementById('blog-filter-head');
            const blogFilterSubmitBtn = document.getElementById('blog-filter-submit-btn');
            const loader = document.getElementById('more-blogs-loader');
            const blogElements = document.querySelectorAll('blog-short');
            const numBlogs = 0;
            if (blogElements.length > 0) numBlogs = blogElements.length;
            if (target && loader) {
                const observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            observer.unobserve(target);
                            if (numBlogs < maxBlogsPerPage) {
                                return;
                            } else {
                                loader.style.display = 'block';

                                let params = new URLSearchParams(blogFilterOptions);
                                params.append('page', blogPageNum);

                                fetch(`${myApi.api_url}get-more-blogs?${params.toString()}`, {
                                    method: 'GET',
                                    headers: {
                                        'X-WP-Nonce': myApi.nonce
                                    }
                                })
                                    .then(response => response.json())
                                    .then(function (data) {
                                        data.forEach(blog => {
                                            prependBlogHTML(blog);
                                        });
                                        loader.style.display = 'none';
                                        blogPageNum++;
                                        if (data.length === maxBlogsPerPage) observer.observe(target);
                                    })
                                    .catch(function (error) {
                                        console.error('Error:', error);
                                        loader.style.display = 'none';
                                    });
                            }
                        }
                    });
                }, {
                    root: null,
                    rootMargin: '0px',
                    threshold: 0.1
                });

                observer.observe(target);

                if (blogKeywordsInput) {
                    blogKeywordsInput.addEventListener('keyup', (event) => {
                        if (event.key === 'Enter' || event.keyCode === 13) {
                            applyBlogFilter(target, observer);
                            blogKeywordsInput.blur();
                        }
                    });
                }            

                if (blogFilterBtn) {
                    blogFilterBtn.addEventListener('click', function () {
                        expandContent('#blog-filter-options');
                    });
                }

                if (blogFilterSubmitBtn) {
                    blogFilterSubmitBtn.addEventListener('click', function () {
                        applyBlogFilter(target, observer);
                    });
                }
            }
            break;
    }

    function sendContactMessage() {
        const nameInput = document.getElementById('contact-form-name');
        const emailInput = document.getElementById('contact-form-email');
        const messageInput = document.getElementById('contact-form-message');

        let name = nameInput.value.trim();
        let email = emailInput.value.trim();
        let message = messageInput.value.trim();

        if (!name || !email || !message) {
            alert('Please fill out all fields.');
            return;
        }

        if (!isValidEmail(email)) {
            alert('Please enter a valid email address.');
            return;
        }

        this.innerHTML = `<img class="loader" src="${themePath}/images/loading.gif" alt="Loading">`;

        let formData = new FormData();
        formData.append('name', name);
        formData.append('email', email);
        formData.append('message', message);

        fetch(`${myApi.api_url}submit-contact`, {
            method: 'POST',
            headers: {
                'X-WP-Nonce': myApi.nonce
            },
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
                if (data.message) {
                    this.textContent = data.message;
                    this.style.cursor = 'auto';
                    this.removeEventListener('click', sendContactMessage);
                } else {
                    throw new Error('An error occurred.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                this.textContent = 'Send';
                alert(error.message || 'An error occurred.');
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
                window.location.href = data.redirect;
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

    function prependBlogHTML(blog) {
        let blogsContainer = document.querySelector('.blogs');
        let moreBlogsLoader = document.getElementById('more-blogs-loader');

        let blogShort = document.createElement('div');
        blogShort.classList.add('blog-short');

        let imgHTML = '';
        if (blog.featured_image) {
            imgHTML = `<div class="featured-img-container">
                        <img src="${blog.featured_image}" class="featured-img" alt="${blog.title}">
                      </div>`;
        }

        blogShort.innerHTML = `<h2><a href="${blog.permalink}" target="_blank">${blog.title}</a></h2>
                               <h2>By: ${blog.author}</h2>
                               ${imgHTML}
                               <div><p>${blog.excerpt}</p></div>
                               <hr>`;
        blogsContainer.insertBefore(blogShort, moreBlogsLoader);
    }

    function applyBlogFilter(target, observer) {
        const categoriesSelect = document.getElementById('category-filter');
        const tagsSelect = document.getElementById('tag-filter');
        const monthsSelect = document.getElementById('month-filter');
        const yearSelect = document.getElementById('year-filter');
        const keywordsInput = document.getElementById('blog-filter-keywords');
        const blogsContainer = document.querySelector('.blogs');
        const loader = document.getElementById('more-blogs-loader');

        blogFilterOptions.category_id = categoriesSelect.value;
        blogFilterOptions.tag_id = tagsSelect.value;
        blogFilterOptions.month = monthsSelect.value;
        blogFilterOptions.year = yearSelect.value;
        blogFilterOptions.keywords = keywordsInput.value;

        observer.unobserve(target);
        loader.style.display = 'block';

        let blogShortElements = blogsContainer.querySelectorAll('.blog-short')
        blogShortElements.forEach(element => {
            element.remove();
        });

        blogPageNum = 1;

        let params = new URLSearchParams(blogFilterOptions);
        params.append('page', blogPageNum);

        fetch(`${myApi.api_url}filter-blogs?${params.toString()}`, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': myApi.nonce
            }
        })
            .then(response => response.json())
            .then(data => {
                data.forEach(blog => {
                    prependBlogHTML(blog);
                });

                let noBlogs = blogsContainer.querySelector('.no-blogs');
                if (noBlogs) noBlogs.remove();

                if (data.length === 0) {
                    let noBlogsMessage = document.createElement('p');
                    noBlogsMessage.classList.add('no-blogs');
                    noBlogsMessage.textContent = 'No blogs found.';
                    blogsContainer.insertBefore(noBlogsMessage, loader);
                }

                loader.style.display = 'none';
                blogPageNum = 2;
                if (data.length === maxBlogsPerPage) observer.observe(target);
            })
            .catch(error => {
                console.error('Error:', error);
                loader.style.display = 'none';
            });
    }

    function expandContent(selector) {
        const element = document.querySelector(selector);
        const blogFilterHead = document.getElementById('blog-filter-head');
        if (element.style.maxHeight) {
            element.style.maxHeight = null;
            blogFilterHead.innerHTML = `<img src="${themePath}/images/filter.webp" alt="Filter">`;
        } else {
            element.style.maxHeight = element.scrollHeight + 'px';
            blogFilterHead.innerHTML = `<img src="${themePath}/images/close-filter.webp" alt="Close Filter">`;
        }
    }

    function isValidEmail(email) {
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailPattern.test(email);
    }

    function isValidPhoneNumber(phoneNumber) {
        if (!phoneNumber) return true;
        const phonePattern = /^[0-9\-\+\s\(\)]+$/;
        return phonePattern.test(phoneNumber);
    }

    function handleContactSticky() {
        if (page === 'contact' || page === 'request-an-appointment' || page === 'dashboard') return;
        const contactSticky = document.querySelector('#contact-sticky');
        contactSticky.addEventListener('pointerup', ()=>{window.location='/request-a-quote';});
        let isVisible = false;
        function toggleContactSticky() {
            if (!isVisible && window.scrollY > 1000) {
                contactSticky.style.display = 'block';
                isVisible = true;
            }
            if (window.scrollY + window.innerHeight > document.body.offsetHeight - 200) {
                contactSticky.style.display = 'none';
                isVisible = false;
            }
        }
        window.addEventListener('scroll', toggleContactSticky);
        toggleContactSticky();
    }
});
