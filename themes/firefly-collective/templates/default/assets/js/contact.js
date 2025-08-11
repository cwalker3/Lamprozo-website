document.addEventListener('DOMContentLoaded', function () {

    const sendMessageBtn = document.getElementById('send-message-btn');
    if (sendMessageBtn) {
        sendMessageBtn.addEventListener('click', sendContactMessage);
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

});