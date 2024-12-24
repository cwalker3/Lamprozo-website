document.addEventListener('DOMContentLoaded', () => {
    const buttons = document.querySelectorAll('.update-project-button');

    buttons.forEach(button => {
        button.addEventListener('click', () => {
            const projectName = button.getAttribute('data-project-name');

            // Reference to the corresponding message container
            let messageContainer = button.parentElement.querySelector('.update-message-container');
            if (!messageContainer) {
                messageContainer = document.createElement('div'); // Use div for block-level
                messageContainer.classList.add('update-message-container');
                messageContainer.setAttribute('aria-live', 'polite'); // For accessibility
                button.parentElement.appendChild(messageContainer);
            }
            
            // Clear any previous messages
            messageContainer.innerHTML = '';

            // Show the loading gif
            const loader = document.createElement('img');
            loader.src = `${window.location.origin}/wp-content/plugins/firefly-collective/includes/apps/backend/images/loading.gif`;
            loader.alt = 'Loading...';
            loader.classList.add('loading-gif'); // Optional: Add a class for additional styling
            messageContainer.appendChild(loader);
            
            // Disable the button to prevent multiple clicks while updating
            button.disabled = true;
            
            const payload = {
                project_name: projectName
            };

            fetch(`${projectData.apiUrl}update-project`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': projectData.nonce
                },
                body: JSON.stringify(payload)
            })
            .then(response => response.json())
            .then(function (data) {
                // Remove the loader
                messageContainer.removeChild(loader);

                if (data.success) {
                    // Show success message
                    const successMsg = document.createElement('span');
                    successMsg.classList.add('success-message');
                    successMsg.textContent = ` Project "${projectName}" updated successfully!`;
                    messageContainer.appendChild(successMsg);
                } else {
                    // Show error message
                    const errorMsg = document.createElement('span');
                    errorMsg.classList.add('error-message');
                    errorMsg.textContent = data.message ? ` Error: ${data.message}` : 'An error occurred.';
                    messageContainer.appendChild(errorMsg);
                }
            })
            .catch(function (error) {
                // Remove the loader
                messageContainer.removeChild(loader);

                // Show error message
                const errorMsg = document.createElement('span');
                errorMsg.classList.add('error-message');
                errorMsg.textContent = ` Network Error: ${error}`;
                messageContainer.appendChild(errorMsg);
            })
            .finally(() => {
                // Re-enable the button regardless of success or failure
                button.disabled = false;
            });
        });
    });
});