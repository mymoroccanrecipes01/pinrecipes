<?php
require_once 'config.php';

$REPO_PATH    = REPO_PATH;
$BRANCH       = BRANCH;
$GITHUB_REPO  = GITHUB_REPO;
$GITHUB_USER  = GITHUB_USER;
$PASSWORD     = GITHUB_PASSWORD;
$GIT_MODE     = GIT_MODE; // 'ssh' ou 'https'
$SSH_KEY      = SSH_KEY;

// ✅ TRAITER LA REQUÊTE AJAX EN PREMIER (avant tout HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Nettoyer le buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $message = "Update from web: " . date('Y-m-d H:i:s');
        
        if (!is_dir($REPO_PATH)) {
            echo json_encode([
                'success' => false,
                'message' => '❌ Dossier non trouvé: ' . $REPO_PATH
            ]);
            exit;
        }
        
        chdir($REPO_PATH);
        
        $allOutput = [];
        
        // 1. Vérifier Git
        $output = [];
        exec('git --version 2>&1', $output, $return);
        if ($return !== 0) {
            echo json_encode([
                'success' => false,
                'message' => '❌ Git n\'est pas installé'
            ]);
            exit;
        }
        $allOutput[] = "Git: " . implode(' ', $output);
        
        // 2. Vérifier le remote
        $output = [];
        exec('git remote -v 2>&1', $output);
        $allOutput[] = "\n=== Remote ===";
        $allOutput = array_merge($allOutput, $output);
        
        $hasOrigin = false;
        foreach ($output as $line) {
            if (strpos($line, 'origin') !== false) {
                $hasOrigin = true;
                break;
            }
        }
        
        // Préparer l'URL du remote et la commande SSH selon le mode
        $sshCmd     = '';
        $tmpKeyFile = null;
        $remoteUrl  = $GITHUB_REPO;

        if ($GIT_MODE === 'ssh') {
            // Mode SSH — utiliser la clé privée
            if (!empty($SSH_KEY)) {
                $tmpKeyFile = tempnam(sys_get_temp_dir(), 'git_ssh_');
                $keyContent = str_replace(["\r\n", "\r"], "\n", $SSH_KEY);
                if (!str_ends_with($keyContent, "\n")) $keyContent .= "\n";
                file_put_contents($tmpKeyFile, $keyContent);
                chmod($tmpKeyFile, 0600);
                $sshCmd = 'GIT_SSH_COMMAND=' . escapeshellarg('ssh -i ' . $tmpKeyFile . ' -o StrictHostKeyChecking=no -o BatchMode=yes') . ' ';
            }
            $allOutput[] = "Mode: SSH";
        } else {
            // Mode HTTPS — injecter user:password dans l'URL
            if (!empty($GITHUB_USER) && !empty($PASSWORD)) {
                $remoteUrl = preg_replace(
                    '#^https://#',
                    'https://' . urlencode($GITHUB_USER) . ':' . urlencode($PASSWORD) . '@',
                    $GITHUB_REPO
                );
            }
            $allOutput[] = "Mode: HTTPS";
        }

        // 3. Mettre à jour / ajouter le remote
        if (!$hasOrigin) {
            $output = [];
            exec($sshCmd . 'git remote add origin ' . escapeshellarg($remoteUrl) . ' 2>&1', $output);
            $allOutput[] = "\n=== Ajout du remote ===";
            $allOutput = array_merge($allOutput, $output);
        } else {
            exec('git remote set-url origin ' . escapeshellarg($remoteUrl) . ' 2>&1');
        }

        // 4. Git status
        $output = [];
        exec('git status --short 2>&1', $output);
        $allOutput[] = "\n=== Status ===";
        $allOutput = array_merge($allOutput, empty($output) ? ['Rien à commiter'] : $output);

        // 5. Git add
        $output = [];
        exec('git add -A 2>&1', $output);
        $allOutput[] = "\n=== Git add ===";
        $allOutput[] = "Fichiers ajoutés";

        // 6. Git commit
        $output = [];
        exec('git commit -m ' . escapeshellarg($message) . ' 2>&1', $output);
        $allOutput[] = "\n=== Git commit ===";
        $allOutput = array_merge($allOutput, $output);

        // 7. Git push force
        $output = [];
        exec($sshCmd . 'git push origin ' . escapeshellarg($BRANCH) . ' --force 2>&1', $output);
        $allOutput[] = "\n=== Git push ===";
        $allOutput = array_merge($allOutput, $output);
        
        // Supprimer le fichier SSH temporaire
        if ($tmpKeyFile && file_exists($tmpKeyFile)) {
            unlink($tmpKeyFile);
        }

        $result = implode("\n", $allOutput);
        
        $hasError = (
            stripos($result, 'fatal:') !== false || 
            stripos($result, 'error:') !== false
        );
        
        // "nothing to commit" n'est pas une erreur
        if (stripos($result, 'nothing to commit') !== false) {
            $hasError = false;
        }
        
        // 🔄 Sync GitHub satellites
        $satelliteResults = [];
        foreach (SATELLITE_PROJECTS as $satellite) {
            $ch = curl_init($satellite['url'] . '/push.php');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => 'action=push',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
            ]);
            $satResponse = curl_exec($ch);
            $satCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $satData = json_decode($satResponse, true);
            $satelliteResults[$satellite['url']] = $satData['message'] ?? "HTTP $satCode";
        }

        echo json_encode([
            'success'    => !$hasError,
            'message'    => !$hasError ? '✅ synchronisation réussie!' : '❌ Erreur détectée',
            'output'     => $result,
            'satellites' => $satelliteResults,
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => '❌ Exception: ' . $e->getMessage()
        ]);
    }
    
    exit;
}

// ✅ HTML SEULEMENT SI CE N'EST PAS POST
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>synchronisation</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            /* min-height: 100vh; */
            display: flex;
            justify-content: center;
            align-items: center;
         
        }
        
        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 7px;
            max-width: 600px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }
        
        .spinner {
            border: 2px solid rgba(255,255,255,0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            width: 16px;
            height: 16px;
            animation: spin 0.8s linear infinite;
            display: none;
        }
        
        .btn:disabled .spinner {
            display: inline-block;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .result.show {
            display: block;
        }
        
        .result.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        
        .result.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        
        .result strong {
            display: block;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .output {
            background: rgba(0,0,0,0.05);
            padding: 12px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            margin-top: 10px;
        }
        
        .info {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- <h1>🚀 Git Force Push</h1>
        <p class="subtitle">Push automatique vers GitHub</p> -->
        
        <?php
        chdir($REPO_PATH);
        $remoteInfo = [];
        exec('git remote -v 2>&1', $remoteInfo);
        if (!empty($remoteInfo)) {
            echo '<div class="info"><strong>✓ Remote configuré</strong><br>' . 
                 htmlspecialchars($remoteInfo[0]) . '</div>';
        } else {
            echo '<div class="info">⚠️ <strong>Aucun remote</strong><br>' .
                 'Sera ajouté automatiquement</div>';
        }
        ?>
        
        <form id="pushForm">
            <button type="submit" class="btn" id="pushBtn">
                <span class="spinner"></span>
                <span class="btn-text">🚀 synchronisation GitHub</span>
            </button>
        </form>
        <div id="result" class="result"></div>
        
    </div>

    <script>
        const form = document.getElementById('pushForm');
        const btn = document.getElementById('pushBtn');
        const btnText = btn.querySelector('.btn-text');
        const result = document.getElementById('result');
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            btn.disabled = true;
            btnText.textContent = 'Push en cours...';
            result.classList.remove('show', 'success', 'error');
            
            const formData = new FormData();
            formData.append('action', 'push');
            
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                });
                
                const text = await response.text();
                console.log('Response brute:', text);
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Réponse invalide: ' + text.substring(0, 200));
                }
                
                result.className = 'result show ' + (data.success ? 'success' : 'error');
                result.innerHTML = `
                    <strong>${data.message}</strong>
                    ${data.output ? `<div class="output">${escapeHtml(data.output)}</div>` : ''}
                `;
                
            } catch (error) {
                console.error('Error:', error);
                result.className = 'result show error';
                result.innerHTML = `<strong>❌ Erreur</strong><div class="output">${escapeHtml(error.message)}</div>`;
            } finally {
                btn.disabled = false;
                btnText.textContent = '🚀 Force Push sur GitHub';
            }
        });
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>