<?php
/**
 * yt-auth-web.php — OAuth2 YouTube via browser
 * Ouvre directement dans le navigateur : http://localhost/SitePinterset/pinrecipes/yt-auth-web.php
 */
require_once __DIR__ . '/auth.php';
auth_check();
require_once __DIR__ . '/config.php';

$clientId     = defined('YOUTUBE_CLIENT_ID')     ? YOUTUBE_CLIENT_ID     : '';
$clientSecret = defined('YOUTUBE_CLIENT_SECRET') ? YOUTUBE_CLIENT_SECRET : '';

// URL de callback — hardcodée pour éviter tout mismatch
$redirectUri = 'http://localhost/SitePinterset/pinrecipes/yt-auth-web.php';

// ── Étape 2 : Google a redirigé avec ?code=xxx ────────────────────────────────
if (isset($_GET['code'])) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $_GET['code'],
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

    if (!empty($data['refresh_token'])) {
        $configFile = __DIR__ . '/site-config.json';
        $config     = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?: []) : [];
        $config['YOUTUBE_REFRESH_TOKEN'] = $data['refresh_token'];
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        ?>
        <!DOCTYPE html><html><head><meta charset="UTF-8"><title>YouTube Auth</title>
        <style>body{font-family:sans-serif;max-width:500px;margin:80px auto;text-align:center}</style></head><body>
        <h1 style="color:#16a34a">✅ YouTube connecté !</h1>
        <p>Refresh token sauvegardé automatiquement.</p>
        <a href="index-facebook-tools.php" style="display:inline-block;margin-top:24px;background:#ff0000;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold">▶️ Aller aux outils</a>
        </body></html>
        <?php
    } else {
        echo '<h2 style="color:red">❌ Erreur</h2><pre>' . htmlspecialchars($response) . '</pre>';
    }
    exit;
}

// ── Étape 1 : erreur Google ───────────────────────────────────────────────────
if (isset($_GET['error'])) {
    echo '<h2 style="color:red">❌ ' . htmlspecialchars($_GET['error']) . '</h2>';
    exit;
}

// ── Étape 0 : pas encore de credentials ──────────────────────────────────────
if (!$clientId || !$clientSecret) {
    echo '<h2 style="color:orange">⚠️ YOUTUBE_CLIENT_ID ou YOUTUBE_CLIENT_SECRET manquant dans site-config.json</h2>';
    exit;
}

// ── Rediriger vers Google ─────────────────────────────────────────────────────
$authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'https://www.googleapis.com/auth/youtube.upload',
    'access_type'   => 'offline',
    'prompt'        => 'consent',
]);
?>
<!DOCTYPE html><html><head><meta charset="UTF-8"><title>YouTube Auth</title>
<style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px}
code{background:#f1f5f9;padding:4px 8px;border-radius:4px;font-size:14px;word-break:break-all}
.btn{display:inline-block;margin-top:20px;background:#ff0000;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;font-size:16px}
</style></head><body>
<h2>▶️ YouTube OAuth2</h2>
<p>Avant de continuer — assure-toi que cette URI exacte est dans Google Cloud Console :</p>
<p><code><?= htmlspecialchars($redirectUri) ?></code></p>
<p style="font-size:13px;color:#6b7280">APIs & Services → Credentials → OAuth 2.0 Client → Authorized redirect URIs</p>
<a href="<?= htmlspecialchars($authUrl) ?>" class="btn">Connecter YouTube →</a>
</body></html>
<?php
exit;
