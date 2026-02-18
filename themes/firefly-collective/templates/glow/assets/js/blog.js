document.addEventListener('DOMContentLoaded', function () {

    const maxBlogsPerPage = parseInt(myApi.maxBlogs);
    let blogPageNum = 2;
    let blogFilterOptions = {
        category_id: '',
        tag_id: '',
        month: '',
        year: '',
        keywords: ''
    };

    const target = document.getElementById('blogs-end');
        const blogKeywordsInput = document.querySelector('#blog-filter-keywords');
        const blogFilterBtn = document.getElementById('blog-filter-head');
        const blogFilterSubmitBtn = document.getElementById('blog-filter-submit-btn');
        const loader = document.getElementById('more-blogs-loader');
        const blogElements = document.querySelectorAll('.blog-short');
        let numBlogs = 0;
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
        } else {
            imgHTML = `<div class="featured-img-container">
                        <!-- Placeholder gradient if no featured image -->
                      </div>`;
        }

        // Match the PHP structure exactly
        blogShort.innerHTML = `${imgHTML}
                               <div class="blog-content">
                                   <h2><a href="${blog.permalink}">${blog.title}</a></h2>
                                   <div class="blog-meta">By: ${blog.author} • ${blog.date || ''}</div>
                                   <div class="blog-excerpt">${blog.excerpt}</div>
                               </div>`;
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
            blogFilterHead.innerHTML = `<img src="${myApi.template_path}/images/filter.webp" alt="Filter">`;
        } else {
            element.style.maxHeight = element.scrollHeight + 'px';
            blogFilterHead.innerHTML = `<img src="${myApi.template_path}/images/close-filter.webp" alt="Close Filter">`;
        }
    }
});