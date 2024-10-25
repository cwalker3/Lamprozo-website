document.addEventListener('DOMContentLoaded', function () {
    const page = window.location.pathname.split('/')[1];
    const themePath = myApi.themePath;
    let blogPageNum = 2;
    let blogFilterOptions = {
        category_id: '',
        tag_id: '',
        month: '',
        year: '',
        keywords: ''
    };

    switch (page) {
        case 'contact':
            const sendMessageBtn = document.getElementById('send-message-btn');
            if (sendMessageBtn) {
                sendMessageBtn.addEventListener('click', sendContactMessage);
            }
            break;

        case 'signup':
            const signupBtn = document.getElementById('signup-btn');
            if (signupBtn) {
                signupBtn.addEventListener('click', signup);
            }
            break;

        case 'blog':
            const target = document.getElementById('blogs-end');
            const blogFilterBtn = document.getElementById('blog-filter-head');
            const blogFilterSubmitBtn = document.getElementById('blog-filter-submit-btn');
            const loader = document.getElementById('more-blogs-loader');

            if (target && loader) {
                const observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (entry.isIntersecting) {
                            observer.unobserve(target);
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
                                    if (data.length === 15) observer.observe(target);
                                })
                                .catch(function (error) {
                                    console.error('Error:', error);
                                    loader.style.display = 'none';
                                });
                        }
                    });
                }, {
                    root: null,
                    rootMargin: '0px',
                    threshold: 0.1
                });

                observer.observe(target);

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
        const signupButton = this;

        let fname = fnameInput.value.trim();
        let lname = lnameInput.value.trim();
        let phone = phoneInput.value.trim();
        let email = emailInput.value.trim();

        errorTxt.innerHTML = '';

        let validationErrors = validateSignup(fname, lname, email, phone);
        if (validationErrors.length > 0) {
            errorTxt.innerHTML = validationErrors.join('<br>');
            return;
        }

        signupButton.innerHTML = `<img class="loader" src="${themePath}/images/loading.gif" alt="Loading">`;

        let formData = new FormData();
        formData.append('fname', fname);
        formData.append('lname', lname);
        formData.append('phone', phone);
        formData.append('email', email);

        fetch(`${myApi.api_url}submit-signup`, {
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
                if (data.message === 'Signup successful!') {
                    signupButton.textContent = data.message;
                    signupButton.style.cursor = 'auto';
                    signupButton.removeEventListener('click', signup);
                } else {
                    throw new Error(data.message || 'An error occurred.');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                errorTxt.textContent = error.message || 'An error occurred.';
                signupButton.textContent = 'Join Now';
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
                               ${imgHTML}
                               <div>${blog.excerpt}</div>
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

        let blogShortElements = blogsContainer.querySelectorAll('.blog-short');
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
                if (data.length === 15) observer.observe(target);
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
});
