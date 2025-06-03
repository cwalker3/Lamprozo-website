// Register service worker
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function() {
    navigator.serviceWorker
      .register('/wp-content/themes/firefly-collective/service-worker.js',
       { scope: '/wp-content/themes/firefly-collective/' })
      .then(function(registration) {
        console.log('Service worker registration succeeded:', registration);
      })
      .catch(function(error) {
        console.log('Service worker registration failed:', error);
      });
  });
}

const testBtn = document.querySelector('#test-btn');
testBtn.addEventListener('pointerup', ()=>{
    alert('hello worlds!');
});