// theme/assets/js/auth.js

function handleGoogleAuth () {
    const googleSigninBtn = document.getElementById('google-signin');
    if (googleSigninBtn) {
        googleSigninBtn.addEventListener('click', function(e) {
            let gapiEndPoint 
            if (!isPWA) gapiEndPoint  = myApi.gapiDomain  + '/wp-json/custom-api/v1/google-auth-init';
            if (isPWA || websiteApp)  gapiEndPoint  = window.gapiDomain + '/wp-json/custom-api/v1/google-auth-init';
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
            if (isPWA || websiteApp) {
                // From app.js
                loginUser(event.data.auth_id);
                return;
            }

            // Check if we're in campaign mode (set by dashboard when Google button is clicked)
            if (window.campaignMode) {
                // Handle campaign Google auth success
                handleCampaignGoogleAuth(event.data);
                return;
            }

            // Regular website login (existing behavior)
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

// New function to handle campaign Google authentication
function handleCampaignGoogleAuth(eventData) {
    try {
        // Parse the auth_id to get user details if needed
        const authId = eventData.auth_id;
        
        // Create auth data object
        const authData = {
            auth_id: authId,
            email: eventData.email || 'Google User',
            message: eventData.message
        };
        
        // Call the dashboard handler if it exists
        if (typeof window.handleCampaignGoogleSuccess === 'function') {
            window.handleCampaignGoogleSuccess(authData);
        }
        
        // Reset campaign mode flag
        window.campaignMode = false;
        
        console.log('Campaign Google auth successful:', authData);
        
    } catch (error) {
        console.error('Error handling campaign Google auth:', error);
        alert('Authentication successful, but there was an error processing it. Please try again.');
        window.campaignMode = false;
    }
}

handleGoogleAuth();