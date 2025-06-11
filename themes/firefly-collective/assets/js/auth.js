// theme/assets/js/auth.js

function handleGoogleAuth () {
    const googleSigninBtn = document.getElementById('google-signin');
    if (googleSigninBtn) {
        googleSigninBtn.addEventListener('click', function(e) {
            let gapiEndPoint 
            if (!isPWA) gapiEndPoint  = myApi.gapiDomain  + '/wp-json/custom-api/v1/google-auth-init';
            if (isPWA)  gapiEndPoint  = window.gapiDomain + '/wp-json/custom-api/v1/google-auth-init';
            let googleAuthUrl = gapiEndPoint;
            let width  = Math.min(1024, screen.width * 0.95);
            let height = Math.min(1024, screen.height * 0.95);
            let left = (screen.width - width) / 2;
            let top  = (screen.height - height) / 2;
            window.open(googleAuthUrl, 'Google Signin', `width=${width},height=${height},top=${top},left=${left}`);
        });
    }

    window.addEventListener('message', function(event) {
        if (event.data && event.data.type === 'googleSignupSuccess') {

            // App login
            if (isPWA) {
                // From app.js
                loginUser(event.data.auth_id);
                return;
            }

            // Website login
            const googleSignInBtnEle = document.querySelector('#google-signin-btn');
            if (googleSignInBtnEle) {
                googleSignInBtnEle.innerHTML = event.data.message;
            }
            // For local host use only
            // if (event.data.auth_id) {
            //     let cookieStr = "auth_id=" + event.data.auth_id + "; path=/; samesite=Lax";
            //     if (window.location.protocol === "https:") {
            //         cookieStr += "; secure";
            //     }
            //     document.cookie = cookieStr;
            // }
            window.location.href = '/dashboard';
        }
    });
}

handleGoogleAuth();