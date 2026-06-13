<?php
/**
 * tasks-api.php — Gestion des tâches cron (Linux) ou Task Scheduler (Windows)
 * Actions: status | create | delete | enable | disable | run
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$IS_WINDOWS = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

// Site-specific prefix for Windows Task Scheduler names (owner-site)
$_winPrefix = implode('-', array_slice(
    array_values(array_filter(explode(DIRECTORY_SEPARATOR, __DIR__))),
    -2
));
$TASK_DEFS = [
    'pipeline'    => ['name' => $_winPrefix . '-Pipeline',    'bat' => 'run-pipeline.bat',        'sh' => 'run-pipeline.sh',    'php' => 'auto-pipeline.php',      'label' => 'Pipeline (génération posts)'],
    'facebook'    => ['name' => $_winPrefix . '-Facebook',    'bat' => 'fb-auto-post.bat',         'sh' => 'fb-auto-post.sh',    'php' => 'fb-auto-post.php',       'label' => 'Facebook Reels'],
    'youtube'     => ['name' => $_winPrefix . '-YouTube',     'bat' => 'yt-auto-post.bat',         'sh' => 'yt-auto-post.sh',    'php' => 'yt-auto-post.php',       'label' => 'YouTube Auto Post'],
    'pinterest'   => ['name' => $_winPrefix . '-Pinterest',   'bat' => '..\publish-pinterest.bat', 'sh' => '',                   'php' => '',                       'label' => 'Pinterest Bulk Upload'],
];

$action  = $_POST['action'] ?? $_GET['action'] ?? '';
$taskKey = trim($_POST['task'] ?? $_GET['task'] ?? '');
$time    = trim($_POST['time'] ?? '02:00');

// ── Cron helpers ──────────────────────────────────────────────────────────────

function cron_site_id(): string {
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', __DIR__))));
    $n     = count($parts);
    $slug  = implode('-', array_slice($parts, max(0, $n - 2)));
    return preg_replace('/[^a-z0-9]+/', '-', strtolower($slug));
}

function cron_tag(string $key): string {
    return '# pinsite-task-' . $key . '-' . cron_site_id();
}

function cron_get_all(): string {
    exec('crontab -l 2>/dev/null', $lines);
    return implode("\n", $lines);
}

function cron_save(string $content): bool {
    $tmp = tempnam(sys_get_temp_dir(), 'cron_');
    file_put_contents($tmp, trim($content) . "\n");
    exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);
    return $code === 0;
}

function cron_status(string $key): array {
    $crontab = cron_get_all();
    $tag     = cron_tag($key);

    foreach (explode("\n", $crontab) as $line) {
        $line = trim($line);
        if (strpos($line, $tag) !== false && !empty($line) && $line[0] !== '#') {
            // Parse time from cron expression: MM HH * * *
            preg_match('/^(\d+)\s+(\d+)/', $line, $m);
            $startTime = isset($m[2], $m[1]) ? sprintf('%02d:%02d', $m[2], $m[1]) : '';
            return ['exists' => true, 'status' => 'ready', 'next_run' => '', 'start_time' => $startTime];
        }
        if (strpos($line, $tag) !== false && str_starts_with(ltrim($line), '#')) {
            // Line is commented = disabled
            preg_match('/(\d+)\s+(\d+)/', $line, $m);
            $startTime = isset($m[2], $m[1]) ? sprintf('%02d:%02d', $m[2], $m[1]) : '';
            return ['exists' => true, 'status' => 'disabled', 'next_run' => '', 'start_time' => $startTime];
        }
    }
    return ['exists' => false, 'status' => 'not_created', 'next_run' => '', 'start_time' => ''];
}

function cron_create(string $key, string $shFile, string $time): array {
    [$hour, $min] = array_map('intval', explode(':', $time . ':00'));
    $dir     = __DIR__;
    $shPath  = $dir . '/' . $shFile;
    $logFile = $dir . '/logs/cron_' . $key . '.log';
    $tag     = cron_tag($key);

    // Remove existing entry for this task
    $lines = array_filter(
        explode("\n", cron_get_all()),
        fn($l) => strpos($l, $tag) === false
    );

    $newLine = sprintf('%d %d * * * bash %s >> %s 2>&1 %s',
        $min, $hour,
        escapeshellarg($shPath),
        escapeshellarg($logFile),
        $tag
    );

    $lines[] = $newLine;
    $success = cron_save(implode("\n", $lines));
    return ['success' => $success, 'output' => $newLine];
}

function cron_delete(string $key): array {
    $lines = array_filter(
        explode("\n", cron_get_all()),
        fn($l) => strpos($l, cron_tag($key)) === false
    );
    $success = cron_save(implode("\n", $lines));
    return ['success' => $success, 'output' => 'Supprimé'];
}

function cron_toggle(string $key, bool $enable): array {
    $tag   = cron_tag($key);
    $lines = explode("\n", cron_get_all());
    $found = false;

    foreach ($lines as &$line) {
        if (strpos($line, $tag) === false) continue;
        $found = true;
        $trimmed = ltrim($line);
        if ($enable && str_starts_with($trimmed, '#')) {
            $line = ltrim(ltrim($line, '#'), ' ');
        } elseif (!$enable && !str_starts_with($trimmed, '#')) {
            $line = '# ' . $line;
        }
    }
    unset($line);

    if (!$found) return ['success' => false, 'output' => 'Tâche introuvable dans crontab'];
    $success = cron_save(implode("\n", $lines));
    return ['success' => $success, 'output' => $enable ? 'Activée' : 'Désactivée'];
}

function cron_run(string $shFile): array {
    $dir     = __DIR__;
    $shPath  = $dir . '/' . $shFile;
    $logFile = $dir . '/logs/cron_manual.log';
    $cmd     = 'bash ' . escapeshellarg($shPath) . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &';
    exec($cmd);
    return ['success' => true, 'output' => 'Lancé en arrière-plan'];
}

// ── Windows fallback helpers ──────────────────────────────────────────────────

function win_task_status(string $taskName): array {
    exec('schtasks /query /tn ' . escapeshellarg($taskName) . ' /fo LIST /v 2>&1', $output, $code);
    if ($code !== 0) return ['exists' => false, 'status' => 'not_created', 'next_run' => '', 'start_time' => ''];
    $text   = win_utf8(implode("\n", $output));
    $status = 'ready';
    if (preg_match('/(?:Status|Statut)\s*:\s*(.+)/iu', $text, $m)) {
        $s = mb_strtolower(trim($m[1]));
        if (strpos($s, 'disabled') !== false || strpos($s, 'sactiv') !== false) $status = 'disabled';
        elseif (strpos($s, 'running') !== false || strpos($s, 'cours') !== false) $status = 'running';
    }
    $nextRun = $startTime = '';
    if (preg_match('/(?:Next Run Time|Prochaine\s+ex[eé]cution)\s*:\s*(.+)/iu', $text, $m)) $nextRun   = trim($m[1]);
    if (preg_match('/(?:Start Time|Heure de d[eé]but)\s*:\s*(.+)/iu',           $text, $m)) $startTime = substr(trim($m[1]), 0, 5);
    return ['exists' => true, 'status' => $status, 'next_run' => $nextRun, 'start_time' => $startTime];
}

function win_utf8(string $s): string {
    if (mb_detect_encoding($s, 'UTF-8', true) === false) {
        return mb_convert_encoding($s, 'UTF-8', 'CP850');
    }
    return $s;
}

function win_exec(string $cmd): array {
    exec($cmd . ' 2>&1', $out, $code);
    return ['success' => $code === 0, 'output' => win_utf8(trim(implode("\n", $out)))];
}

// ── Ensure logs dir ───────────────────────────────────────────────────────────
if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0755, true);

// ── Router ────────────────────────────────────────────────────────────────────

switch ($action) {

    case 'status':
        $result = [];
        foreach ($TASK_DEFS as $key => $def) {
            $windowsOnly = !$IS_WINDOWS && empty($def['sh']);
            $info = $windowsOnly
                ? ['exists' => false, 'status' => 'windows_only', 'next_run' => '', 'start_time' => '']
                : ($IS_WINDOWS ? win_task_status($def['name']) : cron_status($key));
            $info['windows_only'] = $windowsOnly ?? false;
            $result[$key] = array_merge(['key' => $key], $def, $info);
        }
        echo json_encode(['success' => true, 'tasks' => $result, 'os' => $IS_WINDOWS ? 'windows' : 'linux']);
        break;

    case 'create':
        if (!isset($TASK_DEFS[$taskKey])) { echo json_encode(['success' => false, 'error' => 'Tâche inconnue']); break; }
        $def = $TASK_DEFS[$taskKey];
        if ($IS_WINDOWS) {
            $batRaw  = $def['bat'];
            $batPath = (strpos($batRaw, '..') === 0 || strpos($batRaw, '/') === false && strpos($batRaw, '\\') === false)
                ? realpath(__DIR__ . DIRECTORY_SEPARATOR . $batRaw) ?: (__DIR__ . DIRECTORY_SEPARATOR . $batRaw)
                : $batRaw;
            $r = win_exec('schtasks /create /tn ' . escapeshellarg($def['name']) . ' /tr ' . escapeshellarg($batPath) . ' /sc daily /st ' . escapeshellarg($time) . ' /f');
        } else {
            if (empty($def['sh'])) { echo json_encode(['success' => false, 'error' => 'Tâche Windows uniquement']); break; }
            $r = cron_create($taskKey, $def['sh'], $time);
        }
        echo json_encode($r);
        break;

    case 'delete':
        if (!isset($TASK_DEFS[$taskKey])) { echo json_encode(['success' => false, 'error' => 'Tâche inconnue']); break; }
        $r = $IS_WINDOWS
            ? win_exec('schtasks /delete /tn ' . escapeshellarg($TASK_DEFS[$taskKey]['name']) . ' /f')
            : cron_delete($taskKey);
        echo json_encode($r);
        break;

    case 'enable':
    case 'disable':
        if (!isset($TASK_DEFS[$taskKey])) { echo json_encode(['success' => false, 'error' => 'Tâche inconnue']); break; }
        $enable = ($action === 'enable');
        if ($IS_WINDOWS) {
            $r = win_exec('schtasks /change /tn ' . escapeshellarg($TASK_DEFS[$taskKey]['name']) . ($enable ? ' /enable' : ' /disable'));
        } else {
            $r = cron_toggle($taskKey, $enable);
        }
        echo json_encode($r);
        break;

    case 'run':
        if (!isset($TASK_DEFS[$taskKey])) { echo json_encode(['success' => false, 'error' => 'Tâche inconnue']); break; }
        if ($IS_WINDOWS) {
            $r = win_exec('schtasks /run /tn ' . escapeshellarg($TASK_DEFS[$taskKey]['name']));
        } else {
            if (empty($TASK_DEFS[$taskKey]['sh'])) { echo json_encode(['success' => false, 'error' => 'Tâche Windows uniquement']); break; }
            $r = cron_run($TASK_DEFS[$taskKey]['sh']);
        }
        echo json_encode($r);
        break;

    // ── Générer publish-pinterest-generated.bat depuis profiles.json ─────────────
    case 'generate':
        if ($IS_WINDOWS) { echo json_encode(['success' => false, 'error' => 'Windows : utilise directement publish-pinterest.bat']); break; }
        $genScript = dirname(__DIR__) . '/publish-pinterest-gen.php';
        if (!file_exists($genScript)) { echo json_encode(['success' => false, 'error' => 'publish-pinterest-gen.php introuvable']); break; }
        ob_start();
        include $genScript;
        $batContent = ob_get_clean();
        $outFile = dirname(__DIR__) . '/publish-pinterest-generated.bat';
        if (file_put_contents($outFile, $batContent) === false) {
            echo json_encode(['success' => false, 'error' => 'Impossible d\'écrire le fichier .bat']);
            break;
        }
        echo json_encode(['success' => true, 'output' => 'publish-pinterest-generated.bat régénéré (' . strlen($batContent) . ' octets)']);
        break;

    // ── Télécharger un fichier généré (bat ou profiles.json) ─────────────────────
    case 'download':
        $allowed = ['bat'      => dirname(__DIR__) . '/publish-pinterest-generated.bat',
                    'profiles' => __DIR__ . '/profiles.json'];  // profil local du site uniquement
        $which = $_GET['file'] ?? $_POST['file'] ?? '';
        if (!isset($allowed[$which])) { http_response_code(400); echo json_encode(['error' => 'Fichier inconnu']); break; }
        $path = $allowed[$which];
        if (!file_exists($path)) { http_response_code(404); echo json_encode(['error' => 'Fichier non trouvé — génère-le d\'abord']); break; }
        $name = basename($path);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    default:
        echo json_encode(['success' => false, 'error' => 'Action inconnue']);
}
