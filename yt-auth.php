<?php
/**
 * yt-auth.php — One-time OAuth2 setup for YouTube API
 *
 * HOW TO USE:
 *   1. Create a project at https://console.cloud.google.com/
 *   2. Enable "YouTube Data API v3"
 *   3. Create OAuth2 credentials → "Desktop app" → download client_secrets
 *   4. Fill YOUTUBE_CLIENT_ID and YOUTUBE_CLIENT_SECRET in site-config.json
 *   5. Run: php yt-auth.php
 *   6. Open the printed URL in your browser, authorize, paste the code here
 *   7. The refresh_token will be saved automatically to site-config.json
 *
 * After this one-time setup, yt-auto-post.php works fully automatically.
 */

require_once __DIR__ . '/config.php';

$clientId     = defined('YOUTUBE_CLIENT_ID')     ? YOUTUBE_CLIENT_ID     : '';
$clientSecret = defined('YOUTUBE_CLIENT_SECRET') ? YOUTUBE_CLIENT_SECRET : '';

if (!$clientId || !$clientSecret) {
    echo "❌ YOUTUBE_CLIENT_ID et YOUTUBE_CLIENT_SECRET doivent être dans site-config.json\n";
    exit(1);
}

$redirectUri = 'http://localhost/SitePinterset/pinrecipes/yt-callback.php';
$scope       = 'https://www.googleapis.com/auth/youtube.upload';

$authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => $scope,
    'access_type'   => 'offline',
    'prompt'        => 'consent',
]);

echo "=== YouTube OAuth2 Setup ===\n\n";
echo "1. Ouvre ce lien dans ton navigateur:\n\n";
echo $authUrl . "\n\n";
echo "2. Autorise l'accès, puis copie le code affiché.\n";
echo "3. Colle le code ici et appuie sur Entrée: ";

$code = trim(fgets(STDIN));
if (!$code) {
    echo "❌ Aucun code fourni.\n";
    exit(1);
}

// Exchange code for tokens
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'code'          => $code,
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
if (empty($data['refresh_token'])) {
    echo "❌ Erreur lors de l'échange du code:\n";
    echo $response . "\n";
    exit(1);
}

$refreshToken = $data['refresh_token'];
echo "\n✅ refresh_token obtenu: " . substr($refreshToken, 0, 30) . "...\n";

// Save to site-config.json
$configFile = __DIR__ . '/site-config.json';
$config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
$config['YOUTUBE_REFRESH_TOKEN'] = $refreshToken;
file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

echo "✅ refresh_token sauvegardé dans site-config.json\n";
echo "\nMaintenant tu peux lancer: php yt-auto-post.php\n";
