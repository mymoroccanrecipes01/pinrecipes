<?php
/**
 * yt-callback.php — OAuth2 callback pour YouTube
 * Reçoit le code de Google et échange contre un refresh_token
 */
require_once __DIR__ . '/config.php';

$code  = $_GET['code']  ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    die('<h2 style="color:red">❌ Erreur : ' . htmlspecialchars($error) . '</h2>');
}

if (!$code) {
    die('<h2 style="color:orange">⚠️ Aucun code reçu</h2>');
}

$clientId     = defined('YOUTUBE_CLIENT_ID')     ? YOUTUBE_CLIENT_ID     : '';
$clientSecret = defined('YOUTUBE_CLIENT_SECRET') ? YOUTUBE_CLIENT_SECRET : '';
$redirectUri  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $_SERVER['HTTP_HOST'] . '/SitePinterset/pinrecipes/yt-callback.php';

// Échanger le code contre les tokens
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
    echo '<h2 style="color:red">❌ Erreur</h2><pre>' . htmlspecialchars($response) . '</pre>';
    exit;
}

$refreshToken = $data['refresh_token'];

// Sauvegarder dans site-config.json
$configFile = __DIR__ . '/site-config.json';
$config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
$config['YOUTUBE_REFRESH_TOKEN'] = $refreshToken;
file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>YouTube Auth</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;text-align:center}</style>
</head>
<body>
<h1 style="color:#16a34a">✅ YouTube connecté !</h1>
<p>Refresh token sauvegardé dans <code>site-config.json</code></p>
<p style="margin-top:30px">
    <a href="index-facebook-tools.php" style="background:#ff0000;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold">
        ▶️ Aller aux outils
    </a>
</p>
</body>
</html>
