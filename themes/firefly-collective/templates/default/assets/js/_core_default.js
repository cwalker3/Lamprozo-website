// Store initialization state
let isInitialized = false;

// Define the initialize function
window.initializeDefaultJS = function() {
    if (isInitialized) return;
    isInitialized = true;
    
    // Your initialization code here
    console.log('DefaultJS initialized');
};

// console.log(templateData);