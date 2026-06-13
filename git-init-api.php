<?php
require_once 'config.php';
require_once __DIR__ . '/auth.php';
auth_check();
header('Content-Type: application/json');

$action  = $_POST['action'] ?? '';
$repoDir = defined('REPO_PATH') && REPO_PATH ? REPO_PATH : __DIR__;

function runCmd(string $cmd, string $cwd = ''): array {
    $prevDir = getcwd();
    if ($cwd) chdir($cwd);
    exec($cmd . ' 2>&1', $out, $code);
    if ($cwd) chdir($prevDir);
    return ['code' => $code, 'out' => implode("\n", $out)];
}


function phpUser(): string {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        return posix_getpwuid(posix_geteuid())['name'] ?? 'www-data';
    }
    return trim(shell_exec('whoami 2>/dev/null') ?: 'www-data');
}

function getRemoteUrl(): string {
    $url = defined('GITHUB_REPO') ? GITHUB_REPO : '';
    if (!$url) return '';
    if (defined('GIT_MODE') && GIT_MODE === 'https' && defined('GITHUB_USER') && GITHUB_USER && defined('GITHUB_PASSWORD') && GITHUB_PASSWORD) {
        if (strpos($url, '@') !== false) $url = preg_replace('#^https://[^@]+@#', 'https://', $url);
        $url = str_replace('https://', 'https://' . urlencode(GITHUB_USER) . ':' . urlencode(GITHUB_PASSWORD) . '@', $url);
    }
    return $url;
}

switch ($action) {

    case 'status':
        $isGit    = is_dir($repoDir . '/.git');
        $remote   = $isGit ? runCmd('git remote get-url origin', $repoDir) : ['code' => 1, 'out' => ''];
        $branch   = $isGit ? runCmd('git branch --show-current', $repoDir) : ['code' => 1, 'out' => ''];
        $status   = $isGit ? runCmd('git status --short', $repoDir) : ['code' => 1, 'out' => ''];
        $perms    = is_dir($repoDir . '/.git/objects') ? decoct(fileperms($repoDir . '/.git/objects') & 0777) : '?';
        $owner    = is_dir($repoDir . '/.git') ? (function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($repoDir . '/.git'))['name'] ?? '?') : '?') : '?';
        $phpUser  = phpUser();
        echo json_encode([
            'success'   => true,
            'is_git'    => $isGit,
            'repo_dir'  => $repoDir,
            'remote'    => $remote['out'] ? preg_replace('/:[^@:\/]+@/', ':***@', $remote['out']) : '—',
            'branch'    => trim($branch['out']) ?: '—',
            'dirty'     => trim($status['out']),
            'git_owner' => $owner,
            'php_user'  => $phpUser,
            'perms_ok'  => ($owner === $phpUser),
            'git_perms' => $perms,
            'repo_path' => $repoDir,
            'git_mode'  => defined('GIT_MODE') ? GIT_MODE : '—',
            'github_repo' => defined('GITHUB_REPO') ? GITHUB_REPO : '—',
            'branch_cfg' => defined('BRANCH') ? BRANCH : '—',
        ]);
        break;

    case 'init':
        $r = runCmd('git init', $repoDir);
        runCmd('git config user.email "www-data@server"', $repoDir);
        runCmd('git config user.name "www-data"', $repoDir);
        echo json_encode(['success' => $r['code'] === 0, 'output' => $r['out']]);
        break;

    case 'set_remote':
        $url = getRemoteUrl();
        if (!$url) { echo json_encode(['success' => false, 'output' => 'GITHUB_REPO non configuré']); break; }
        $existing = runCmd('git remote', $repoDir);
        if (strpos($existing['out'], 'origin') !== false) {
            $r = runCmd('git remote set-url origin ' . escapeshellarg($url), $repoDir);
        } else {
            $r = runCmd('git remote add origin ' . escapeshellarg($url), $repoDir);
        }
        $display = preg_replace('/:[^@:\/]+@/', ':***@', $url);
        echo json_encode(['success' => $r['code'] === 0, 'output' => ($r['code'] === 0 ? 'Remote set: ' . $display : $r['out'])]);
        break;

    case 'fix_perms':
        $r1 = runCmd('chown -R ' . escapeshellarg(phpUser()) . ' ' . escapeshellarg($repoDir . '/.git'));
        $r2 = runCmd('chmod -R 775 ' . escapeshellarg($repoDir . '/.git'));
        echo json_encode(['success' => $r1['code'] === 0, 'output' => $r1['out'] . "\n" . $r2['out']]);
        break;

    case 'pull':
        $branch = defined('BRANCH') ? BRANCH : 'main';
        $r = runCmd('git pull origin ' . escapeshellarg($branch), $repoDir);
        echo json_encode(['success' => $r['code'] === 0, 'output' => $r['out']]);
        break;

    case 'test_push':
        $url = getRemoteUrl();
        if (!$url) { echo json_encode(['success' => false, 'output' => 'GITHUB_REPO non configuré']); break; }
        $r = runCmd('git ls-remote --heads origin', $repoDir);
        echo json_encode(['success' => $r['code'] === 0, 'output' => $r['code'] === 0 ? '✅ Auth OK — accès GitHub confirmé' : $r['out']]);
        break;

    case 'full_init':
        $log = [];
        // 1. git init
        if (!is_dir($repoDir . '/.git')) {
            $r = runCmd('git init', $repoDir); $log[] = 'git init: ' . ($r['code'] === 0 ? '✅' : '❌ ' . $r['out']);
        } else {
            $log[] = 'git init: ✅ déjà initialisé';
        }
        // 2. config user
        runCmd('git config user.email "www-data@server"', $repoDir);
        runCmd('git config user.name "www-data"', $repoDir);
        $log[] = 'git config: ✅';
        // 3. remote
        $url = getRemoteUrl();
        if ($url) {
            $existing = runCmd('git remote', $repoDir);
            if (strpos($existing['out'], 'origin') !== false) {
                $r = runCmd('git remote set-url origin ' . escapeshellarg($url), $repoDir);
            } else {
                $r = runCmd('git remote add origin ' . escapeshellarg($url), $repoDir);
            }
            $log[] = 'remote: ' . ($r['code'] === 0 ? '✅' : '❌ ' . $r['out']);
        } else {
            $log[] = 'remote: ⚠️ GITHUB_REPO non configuré';
        }
        // 4. fix perms
        $phpUser = phpUser();
        runCmd('chown -R ' . escapeshellarg($phpUser) . ' ' . escapeshellarg($repoDir . '/.git'));
        runCmd('chmod -R 775 ' . escapeshellarg($repoDir . '/.git'));
        $log[] = 'permissions: ✅';
        // 5. pull
        $branch = defined('BRANCH') ? BRANCH : 'main';
        $r = runCmd('git pull origin ' . escapeshellarg($branch), $repoDir);
        $log[] = 'git pull: ' . ($r['code'] === 0 ? '✅' : '⚠️ ' . mb_substr($r['out'], 0, 150));
        echo json_encode(['success' => true, 'output' => implode("\n", $log)]);
        break;

    case 'test_init_push':
        $repoName = trim($_POST['repo_name'] ?? '');
        $branch   = trim($_POST['branch']    ?? 'main') ?: 'main';
        if (!$repoName) { echo json_encode(['success' => false, 'output' => 'repo_name manquant']); break; }
        $ghUser  = defined('GITHUB_USER')     ? GITHUB_USER     : '';
        $ghToken = defined('GITHUB_PASSWORD') ? GITHUB_PASSWORD : '';
        if (!$ghUser || !$ghToken) { echo json_encode(['success' => false, 'output' => '❌ GITHUB_USER ou GITHUB_PASSWORD non configuré — sauvegarder la config d\'abord']); break; }
        $repoUrl = 'https://' . rawurlencode($ghUser) . ':' . rawurlencode($ghToken) . '@github.com/' . $ghUser . '/' . $repoName . '.git';

        $log  = [];
        $step = function(string $label, array $r) use (&$log): bool {
            $ok = $r['code'] === 0;
            $log[] = ($ok ? '✅' : '❌') . ' ' . $label . ($ok || !$r['out'] ? '' : "\n   " . trim($r['out']));
            return $ok;
        };

        // 1. git init
        $r = runCmd('git init', $repoDir);
        $step('git init', $r);

        // 2. identity (required for commit)
        runCmd('git config user.email "deploy@server"', $repoDir);
        runCmd('git config user.name "deploy"', $repoDir);

        // 3. remote — add or update
        $existing = runCmd('git remote', $repoDir);
        if (strpos($existing['out'], 'origin') !== false) {
            $r = runCmd('git remote set-url origin ' . escapeshellarg($repoUrl), $repoDir);
            $step('git remote set-url origin', $r);
        } else {
            $r = runCmd('git remote add origin ' . escapeshellarg($repoUrl), $repoDir);
            $step('git remote add origin', $r);
        }

        // 4. create a tiny test file (no secrets, not in .gitignore)
        $testFile = $repoDir . '/.git-deploy-check';
        file_put_contents($testFile, date('Y-m-d H:i:s') . "\n");
        $r = runCmd('git add -f ' . escapeshellarg('.git-deploy-check'), $repoDir);
        $step('git add .git-deploy-check', $r);

        // 5. commit
        $r = runCmd('git commit -m "chore: verify git connectivity" --allow-empty', $repoDir);
        $step('git commit', $r);

        // 6. branch
        $r = runCmd('git branch -M ' . escapeshellarg($branch), $repoDir);
        $step('git branch -M ' . $branch, $r);

        // 7. push (HTTPS only — token embedded in repo_url)
        $r = runCmd('git push -u origin ' . escapeshellarg($branch), $repoDir);
        $pushOk = $step('git push -u origin ' . $branch, $r);

        // 8. cleanup test file from repo
        if ($pushOk) {
            runCmd('git rm --cached ' . escapeshellarg('.git-deploy-check'), $repoDir);
            runCmd('git commit -m "chore: cleanup deploy check file" --allow-empty', $repoDir);
            runCmd('git push', $repoDir);
            @unlink($testFile);
            $log[] = '🧹 Fichier test supprimé du repo';
        }

        echo json_encode(['success' => $pushOk, 'output' => implode("\n", $log)]);
        break;

    case 'fix_app_perms':
        $phpUser = phpUser();
        // Try chown — will fail if files owned by root
        $r = runCmd('chown -R ' . escapeshellarg($phpUser) . ':' . escapeshellarg($phpUser) . ' ' . escapeshellarg($repoDir));
        if ($r['code'] === 0) {
            runCmd('find ' . escapeshellarg($repoDir) . ' -maxdepth 5 -type d -exec chmod 775 {} +');
            runCmd('find ' . escapeshellarg($repoDir) . ' -maxdepth 5 -type f -exec chmod 664 {} +');
            echo json_encode(['success' => true, 'output' => '✅ Permissions fixées pour ' . $phpUser]);
        } else {
            // Files owned by root — generate a fix script in /tmp that root can run
            $scriptPath = '/tmp/fix-perms-' . basename($repoDir) . '.sh';
            $script = "#!/bin/bash\n"
                . "chown -R {$phpUser}:{$phpUser} " . escapeshellarg($repoDir) . "\n"
                . "find " . escapeshellarg($repoDir) . " -type d -exec chmod 775 {} +\n"
                . "find " . escapeshellarg($repoDir) . " -type f -exec chmod 664 {} +\n"
                . "echo '✅ Done'\n";
            file_put_contents($scriptPath, $script);
            chmod($scriptPath, 0644);
            echo json_encode([
                'success'     => false,
                'needs_root'  => true,
                'script_path' => $scriptPath,
                'fix_cmd'     => 'sudo bash ' . $scriptPath,
                'output'      => "❌ Fichiers owned par root — www-data ne peut pas faire chown.\n\nScript généré dans: {$scriptPath}\n\nExécutez cette commande en SSH (une seule fois) :\n\nsudo bash {$scriptPath}",
            ]);
        }
        break;

    case 'run_commands':
        $raw = trim($_POST['commands'] ?? '');
        if (!$raw) { echo json_encode(['success' => false, 'output' => 'Aucune commande fournie']); break; }
        $lines  = explode("\n", str_replace("\r\n", "\n", $raw));
        $log    = [];
        $hasErr = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $r = runCmd($line, $repoDir);
            $log[] = '$ ' . $line . "\n" . trim($r['out']);
            if ($r['code'] !== 0) { $hasErr = true; $log[] = '⚠️  exit ' . $r['code']; }
        }
        // Fix permissions after commands (VPS: www-data or current php user)
        if (is_dir($repoDir . '/.git')) {
            $phpUser = phpUser();
            $gitDir  = escapeshellarg($repoDir . '/.git');
            $repoEsc = escapeshellarg($repoDir);
            runCmd('chown -R ' . escapeshellarg($phpUser) . ':' . escapeshellarg($phpUser) . ' ' . $gitDir);
            runCmd('chmod -R 775 ' . $gitDir);
            runCmd('chmod g+s ' . $gitDir);
            // Also fix repo dir ownership so php can write files
            runCmd('chown ' . escapeshellarg($phpUser) . ':' . escapeshellarg($phpUser) . ' ' . $repoEsc);
            $log[] = '🔑 Permissions .git fixées pour ' . $phpUser;
        }
        echo json_encode(['success' => !$hasErr, 'output' => implode("\n\n", $log)]);
        break;

    default:
        echo json_encode(['success' => false, 'output' => 'Action inconnue']);
}
