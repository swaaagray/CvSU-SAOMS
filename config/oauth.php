<?php
// Google OAuth Configuration
// You need to get these credentials from Google Cloud Console:
// 1. Go to https://console.cloud.google.com/
// 2. Create a new project or select existing one
// 3. Enable Google+ API and Gmail API
// 4. Create OAuth 2.0 credentials
// 5. Add your domain to authorized domains
// 6. Add redirect URI: http://yourdomain.com/oauth_callback.php

// TODO: Replace these with your actual Google OAuth credentials
// Get these from: https://console.cloud.google.com/ > APIs & Services > Credentials
define('GOOGLE_CLIENT_ID', '793274606022-g8c416j96klu2trv41h9figv41snb5fp.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET', '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');
define('GOOGLE_REDIRECT_URI', 'https://cvsu-saoms.bsitdashtwo.com/oauth_callback.php'); // Update this to your actual domain

// OAuth endpoints
define('GOOGLE_OAUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USER_INFO_URL', 'https://www.googleapis.com/oauth2/v1/userinfo');

// OAuth scopes
define('GOOGLE_SCOPES', 'openid email profile');

// CvSU Configuration
define('CVSU_DOMAIN', 'cvsu.edu.ph');

/**
 * Generate Google OAuth login URL
 */
function getGoogleOAuthURL() {
    $params = [
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'scope' => GOOGLE_SCOPES,
        'response_type' => 'code',
        'access_type' => 'offline',
        'prompt' => 'consent'
    ];
    
    return GOOGLE_OAUTH_URL . '?' . http_build_query($params);
}

/**
 * Exchange authorization code for access token
 */
function getGoogleAccessToken($code) {
    $data = [
        'client_id' => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
        'code' => $code
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, GOOGLE_TOKEN_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    return false;
}

/**
 * Get user information from Google
 */
function getGoogleUserInfo($accessToken) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, GOOGLE_USER_INFO_URL . '?access_token=' . $accessToken);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    }
    
    return false;
}

/**
 * Validate CvSU email format
 */
function isCvSUEmail($email) {
    $cvsuDomains = [
        'cvsu.edu.ph',
        'student.cvsu.edu.ph',
        'faculty.cvsu.edu.ph',
        'staff.cvsu.edu.ph'
    ];
    
    foreach ($cvsuDomains as $domain) {
        if (str_ends_with(strtolower($email), '@' . $domain)) {
            return true;
        }
    }
    
    return false;
}




?>