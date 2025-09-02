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
