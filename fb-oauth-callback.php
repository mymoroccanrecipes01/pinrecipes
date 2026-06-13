<?php
/**
 * Facebook OAuth Callback
 * Échange le code → long-lived token → Page Access Token → sauvegarde dans site-config.json
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
auth_check();

$configFile  = __DIR__ . '/site-config.json';
$cfg         = file_exists($configFile) ? (json_decode(file_get_contents($configFile), true) ?? []) : [];
$appId       = $cfg['FACEBOOK_APP_ID']     ?? '';
$appSecret   = $cfg['FACEBOOK_APP_SECRET'] ?? '';
$redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . strtok($_SERVER['REQUEST_URI'], '?');

// ── Erreur FB ──────────────────────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $msg = htmlspecialchars($_GET['error_description'] ?? $_GET['error']);
    die('<p style="font-family:sans-serif;color:red">❌ Erreur Facebook : ' . $msg . ' — <a href="config-ui.php">Retour</a></p>');
}

// ── Sélection page (étape 2) ──────────────────────────────────────────────────
if (isset($_POST['page_token'], $_POST['page_id'])) {
    $cfg['FACEBOOK_PAGE_ID']      = trim($_POST['page_id']);
    $cfg['FACEBOOK_ACCESS_TOKEN'] = trim($_POST['page_token']);
    file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    header('Location: config-ui.php?saved=fb');
    exit;
}

// ── Pas de code → redirect vers FB OAuth ─────────────────────────────────────
if (!isset($_GET['code'])) {
    if (!$appId) die('<p style="font-family:sans-serif;color:red">❌ Configure App ID dans Config UI d\'abord — <a href="config-ui.php">Retour</a></p>');
    $scope = 'pages_manage_posts,pages_manage_engagement,pages_read_engagement,pages_show_list,pages_manage_videos';
    $url   = 'https://www.facebook.com/v19.0/dialog/oauth'
           . '?client_id='    . urlencode($appId)
           . '&redirect_uri=' . urlencode($redirectUri)
           . '&scope='        . urlencode($scope)
           . '&response_type=code';
    header('Location: ' . $url);
    exit;
}

// ── Échange code → short-lived token ─────────────────────────────────────────
$ch  = curl_init('https://graph.facebook.com/v19.0/oauth/access_token'
    . '?client_id='     . urlencode($appId)
    . '&client_secret=' . urlencode($appSecret)
    . '&redirect_uri='  . urlencode($redirectUri)
    . '&code='          . urlencode($_GET['code']));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$r1 = json_decode(curl_exec($ch), true);
curl_close($ch);
if (isset($r1['error'])) die('<p style="font-family:sans-serif;color:red">❌ ' . htmlspecialchars($r1['error']['message']) . ' — <a href="config-ui.php">Retour</a></p>');

// ── Short-lived → long-lived token ───────────────────────────────────────────
$ch2 = curl_init('https://graph.facebook.com/v19.0/oauth/access_token'
    . '?grant_type=fb_exchange_token'
    . '&client_id='          . urlencode($appId)
    . '&client_secret='      . urlencode($appSecret)
    . '&fb_exchange_token='  . urlencode($r1['access_token']));
curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$r2 = json_decode(curl_exec($ch2), true);
curl_close($ch2);
if (isset($r2['error'])) die('<p style="font-family:sans-serif;color:red">❌ ' . htmlspecialchars($r2['error']['message']) . ' — <a href="config-ui.php">Retour</a></p>');
$longToken = $r2['access_token'];

// ── Récupérer les pages ───────────────────────────────────────────────────────
$ch3 = curl_init('https://graph.facebook.com/v19.0/me/accounts?access_token=' . urlencode($longToken));
curl_setopt_array($ch3, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
$r3 = json_decode(curl_exec($ch3), true);
curl_close($ch3);
if (isset($r3['error'])) die('<p style="font-family:sans-serif;color:red">❌ ' . htmlspecialchars($r3['error']['message']) . ' — <a href="config-ui.php">Retour</a></p>');

$pages = $r3['data'] ?? [];

// ── Si une seule page → sauvegarde directe ───────────────────────────────────
if (count($pages) === 1) {
    $cfg['FACEBOOK_PAGE_ID']      = $pages[0]['id'];
    $cfg['FACEBOOK_ACCESS_TOKEN'] = $pages[0]['access_token'];
    file_put_contents($configFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    header('Location: config-ui.php?saved=fb');
    exit;
}

// ── Plusieurs pages → sélection ──────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Sélectionner une page Facebook</title>
<style>
body { font-family: sans-serif; max-width: 500px; margin: 60px auto; padding: 0 20px; }
h2 { color: #1877F2; margin-bottom: 20px; }
.page-btn { display: block; width: 100%; text-align: left; padding: 14px 16px; margin-bottom: 10px;
    background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; font-size: 14px; }
.page-btn:hover { background: #f0f4ff; border-color: #1877F2; }
.page-name { font-weight: 600; color: #1e293b; }
.page-id { font-size: 11px; color: #94a3b8; font-family: monospace; }
</style>
</head>
<body>
<h2>Sélectionne ta Page Facebook</h2>
<form method="POST">
<?php foreach ($pages as $page): ?>
<button type="submit" name="page_id" value="<?= htmlspecialchars($page['id']) ?>" class="page-btn"
    onclick="document.getElementById('pt').value='<?= htmlspecialchars($page['access_token']) ?>'">
    <div class="page-name"><?= htmlspecialchars($page['name']) ?></div>
    <div class="page-id"><?= htmlspecialchars($page['id']) ?></div>
</button>
<?php endforeach; ?>
<input type="hidden" name="page_token" id="pt" value="">
</form>
</body>
</html>
