<?php
/**
 * Facebook Reels Tools — Management UI
 */
require_once __DIR__ . '/auth.php';
auth_check();
require_once __DIR__ . '/config.php';

// Load posted logs
$ytPostedLog = [];
$ytLogFile   = __DIR__ . '/yt_posted_log.json';
if (file_exists($ytLogFile)) {
    foreach (json_decode(file_get_contents($ytLogFile), true) ?? [] as $e) {
        $ytPostedLog[$e['slug']] = $e;
    }
}
$ytReady = defined('YOUTUBE_CLIENT_ID') && YOUTUBE_CLIENT_ID
        && defined('YOUTUBE_CLIENT_SECRET') && YOUTUBE_CLIENT_SECRET;

// Load post list from index.json
$indexFile = __DIR__ . '/posts/index.json';
$indexData = file_exists($indexFile) ? json_decode(file_get_contents($indexFile), true) : [];
$slugs = $indexData['folders'] ?? [];

// Build post list with status
$posts = [];
foreach ($slugs as $slug) {
    if (strpos($slug, 'BCP') === 0) continue; // skip batch subdirs
    $postFile = __DIR__ . '/posts/' . $slug . '/post.json';
    if (!file_exists($postFile)) continue;
    $post = json_decode(file_get_contents($postFile), true);
    if (empty($post['isOnline'])) continue;

    $imgDir = __DIR__ . '/posts/' . $slug . '/images/';
    $hasFrames = file_exists($imgDir . $slug . '_fb_frame_5.webp');
    $hasVideo  = file_exists($imgDir . $slug . '_reel.mp4');

    // Thumbnail: first non-template image
    $thumb = '';
    foreach ($post['images'] ?? [] as $img) {
        if (($img['type'] ?? '') !== 'template') { $thumb = $img['filePath'] ?? ''; break; }
    }
    if (!$thumb) $thumb = $post['image_path'] ?? '';

    $posts[] = [
        'slug'       => $slug,
        'title'      => $post['title'] ?? $slug,
        'thumb'      => $thumb,
        'hasFrames'  => $hasFrames,
        'hasVideo'   => $hasVideo,
        'ytPosted'   => isset($ytPostedLog[$slug]),
        'ytVideoId'  => $ytPostedLog[$slug]['video_id'] ?? '',
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Facebook Reels Tools</title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:linear-gradient(135deg,#1877F2 0%,#0d5cbf 100%); padding:20px; min-height:100vh; }
.container { max-width:1300px; margin:0 auto; }

.header { background:#fff; border-radius:14px; padding:32px 40px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.3); margin-bottom:24px; }
.header h1 { color:#1877F2; font-size:2.4em; margin-bottom:6px; }
.header p { color:#6c757d; font-size:1.05em; }
.badge { display:inline-flex; align-items:center; gap:6px; padding:5px 14px; border-radius:20px; font-size:.85em; font-weight:600; margin-top:12px; }
.badge.ready { background:#d4edda; color:#155724; }
.badge.frames-only { background:#fff3cd; color:#856404; }
.badge.loading { background:#e2e3e5; color:#383d41; }

.toolbar { background:#fff; border-radius:12px; padding:16px 24px; margin-bottom:20px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; box-shadow:0 4px 16px rgba(0,0,0,.15); }
.toolbar input { flex:1; min-width:200px; padding:8px 14px; border:1px solid #ddd; border-radius:8px; font-size:.95em; }
.btn { padding:9px 20px; border:none; border-radius:8px; cursor:pointer; font-size:.9em; font-weight:600; transition:all .2s; display:inline-flex; align-items:center; gap:6px; }
.btn-blue  { background:#1877F2; color:#fff; }
.btn-blue:hover  { background:#1464d0; }
.btn-green { background:#28a745; color:#fff; }
.btn-green:hover { background:#218838; }
.btn-orange{ background:#fd7e14; color:#fff; }
.btn-orange:hover{ background:#e06b0a; }
.btn-gray  { background:#6c757d; color:#fff; }
.btn-gray:hover  { background:#545b62; }
.btn-fb    { background:#1877f2; color:#fff; white-space:nowrap; }
.btn-fb:hover    { background:#166fe5; }
.btn-yt    { background:#ff0000; color:#fff; white-space:nowrap; }
.btn-yt:hover    { background:#cc0000; }
.btn-yt.posted   { background:#16a34a; cursor:default; }
.music-bar { display:flex;gap:8px;align-items:center;background:#2d3748;padding:10px 16px;border-radius:10px;margin-bottom:12px;flex-wrap:wrap; }
/* Video preview modal */
#video-modal { display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:1000;align-items:center;justify-content:center;flex-direction:column;gap:14px; }
#video-modal.open { display:flex; }
#video-modal video { max-width:min(400px,90vw);max-height:80vh;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.6); }
#video-modal-close { position:absolute;top:18px;right:24px;background:none;border:none;color:#fff;font-size:1.8em;cursor:pointer;line-height:1; }
.btn-preview { background:#6f42c1;color:#fff; }
.btn-preview:hover { background:#5a35a3; }
.btn:disabled { opacity:.5; cursor:not-allowed; }

.progress-bar-wrap { background:#fff; border-radius:12px; padding:16px 24px; margin-bottom:20px; display:none; box-shadow:0 4px 16px rgba(0,0,0,.15); }
.progress-bar-wrap.visible { display:block; }
.progress-bar-track { background:#e9ecef; border-radius:8px; height:18px; overflow:hidden; margin-top:8px; }
.progress-bar-fill  { background:#1877F2; height:100%; border-radius:8px; transition:width .3s; }
#progress-label { font-size:.9em; color:#495057; }

.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:16px; }
.card { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 4px 16px rgba(0,0,0,.12); transition:transform .2s; }
.card:hover { transform:translateY(-2px); }
.card-thumb { width:100%; height:170px; object-fit:cover; background:#eee; display:block; }
.card-thumb-placeholder { width:100%; height:170px; background:linear-gradient(135deg,#e9ecef,#dee2e6); display:flex; align-items:center; justify-content:center; color:#adb5bd; font-size:2em; }
.card-body  { padding:14px 16px; }
.card-title { font-size:.88em; font-weight:600; color:#212529; margin-bottom:8px; line-height:1.4; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.card-status { font-size:.78em; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.status-none   { color:#6c757d; }
.status-frames { color:#856404; }
.status-video  { color:#155724; }
.card-actions  { display:flex; gap:6px; flex-wrap:wrap; }
.card-actions .btn { padding:6px 12px; font-size:.8em; }

.no-posts { background:#fff; border-radius:12px; padding:40px; text-align:center; color:#6c757d; }

.toast { position:fixed; bottom:24px; right:24px; background:#333; color:#fff; padding:12px 20px; border-radius:8px; font-size:.9em; z-index:999; opacity:0; transition:opacity .3s; pointer-events:none; max-width:360px; }
.toast.show { opacity:1; }
.toast.error { background:#dc3545; }
.toast.success { background:#28a745; }
</style>
</head>
<body>
<div class="container">

    <!-- Header -->
    <div class="header">
        <h1>🎬 Facebook Reels Generator</h1>
        <p>Créez des Reels verticaux (1080×1920) depuis les images de vos posts</p>
        <div id="ffmpeg-badge" class="badge loading">⏳ Vérification FFmpeg...</div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <input type="text" id="search-input" placeholder="Rechercher un post..." oninput="filterCards()">
        <button class="btn btn-blue" onclick="generateAll()">⚡ Générer tout (frames)</button>
        <?php if ($ytReady): ?>
        <button class="btn btn-yt" onclick="runAutoYoutube(this)" title="Lance yt-auto-post.php — poste automatiquement N vidéos du jour">▶️ Auto YouTube</button>
        <?php else: ?>
        <span style="background:#fef3c7;color:#92400e;padding:6px 12px;border-radius:8px;font-size:.82em">⚠️ YouTube non configuré</span>
        <?php endif; ?>
        <span style="color:#fff;font-size:.9em" id="count-label">
            <?= count($posts) ?> posts en ligne
        </span>
    </div>

    <!-- Music bar -->
    <div class="music-bar" id="music-bar">
        <span style="font-weight:600;color:#fff;white-space:nowrap">🎵 Musique</span>
        <select id="music-select" style="flex:1;min-width:0;font-size:.85em;padding:6px 10px;border-radius:8px;border:none;background:#fff">
            <option value="">— Sans musique —</option>
        </select>
        <label class="btn btn-gray" style="cursor:pointer;padding:6px 14px;font-size:.82em;margin:0">
            ⬆ Upload
            <input type="file" accept=".mp3,.m4a,.aac,.wav,.ogg" style="display:none" onchange="uploadMusic(this)">
        </label>
        <button class="btn btn-gray" style="padding:6px 12px;font-size:.82em" onclick="deleteSelectedMusic()" title="Supprimer la piste sélectionnée">🗑</button>
    </div>

    <!-- Progress bar -->
    <div class="progress-bar-wrap" id="progress-wrap">
        <div id="progress-label">Génération en cours...</div>
        <div class="progress-bar-track"><div class="progress-bar-fill" id="progress-fill" style="width:0%"></div></div>
    </div>

    <!-- Grid -->
    <div class="grid" id="posts-grid">
    <?php foreach ($posts as $p):
        $statusClass = $p['hasVideo'] ? 'status-video' : ($p['hasFrames'] ? 'status-frames' : 'status-none');
        $statusIcon  = $p['hasVideo'] ? '🎬' : ($p['hasFrames'] ? '🖼️' : '⬜');
        $statusText  = $p['hasVideo'] ? 'Vidéo prête' : ($p['hasFrames'] ? 'Frames prêtes' : 'Aucun reel');
        $thumbUrl    = $p['thumb'] ? htmlspecialchars($p['thumb']) : '';
    ?>
    <div class="card" data-slug="<?= htmlspecialchars($p['slug']) ?>" data-title="<?= htmlspecialchars(strtolower($p['title'])) ?>">
        <?php if ($thumbUrl): ?>
            <img class="card-thumb" src="<?= $thumbUrl ?>" alt="" loading="lazy">
        <?php else: ?>
            <div class="card-thumb-placeholder">🍽</div>
        <?php endif; ?>
        <div class="card-body">
            <div class="card-title"><?= htmlspecialchars($p['title']) ?></div>
            <div class="card-status <?= $statusClass ?>">
                <span><?= $statusIcon ?> <?= $statusText ?></span>
            </div>
            <div class="card-actions">
                <button class="btn btn-blue" onclick="generateFrames('<?= htmlspecialchars($p['slug']) ?>', this)">🖼️ Frames</button>
                <button class="btn btn-green" id="btn-video-<?= htmlspecialchars($p['slug']) ?>" onclick="generateVideo('<?= htmlspecialchars($p['slug']) ?>', this)" <?= $p['hasFrames'] ? '' : 'disabled' ?>>🎬 Vidéo</button>
                <button class="btn btn-gray" onclick="downloadZip('<?= htmlspecialchars($p['slug']) ?>')">⬇ ZIP</button>
                <button class="btn btn-preview" id="btn-preview-<?= htmlspecialchars($p['slug']) ?>" onclick="previewVideo('<?= htmlspecialchars($p['slug']) ?>')" style="display:<?= $p['hasVideo'] ? 'inline-flex' : 'none' ?>">▶ Preview</button>
            </div>
            <div class="fb-post-row" id="fb-row-<?= htmlspecialchars($p['slug']) ?>" style="margin-top:8px;display:<?= $p['hasVideo'] ? 'flex' : 'none' ?>;gap:6px;align-items:center">
                <input type="datetime-local" id="schedule-<?= htmlspecialchars($p['slug']) ?>"
                    style="flex:1;font-size:12px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:6px;min-width:0"
                    min="<?= date('Y-m-d\TH:i', time() + 600) ?>">
                <button class="btn btn-fb" id="btn-fb-<?= htmlspecialchars($p['slug']) ?>" onclick="postToFacebook('<?= htmlspecialchars($p['slug']) ?>', this)">📤 Post FB</button>
            </div>
            <?php if ($ytReady): ?>
            <div id="yt-row-<?= htmlspecialchars($p['slug']) ?>" style="margin-top:6px;display:<?= $p['hasVideo'] ? 'flex' : 'none' ?>;gap:6px;align-items:center">
                <?php if ($p['ytPosted']): ?>
                <a href="https://youtu.be/<?= htmlspecialchars($p['ytVideoId']) ?>" target="_blank"
                   class="btn btn-yt posted" style="font-size:.8em;padding:6px 12px;text-decoration:none">✅ YouTube</a>
                <?php else: ?>
                <button class="btn btn-yt" id="btn-yt-<?= htmlspecialchars($p['slug']) ?>"
                    onclick="postToYoutube('<?= htmlspecialchars($p['slug']) ?>', this)"
                    style="font-size:.8em;padding:6px 12px">▶️ Post YT</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($posts)): ?>
    <div class="no-posts" style="grid-column:1/-1">
        Aucun post en ligne trouvé. Publiez des posts depuis posts-liste.php d'abord.
    </div>
    <?php endif; ?>
    </div>

</div>

<div class="toast" id="toast"></div>

<script>
const API    = 'generate-facebook-reel.php';
const YT_API = 'yt-api.php';

// ── Init ──────────────────────────────────────────────────────────────────────
loadMusicList();

// ── FFmpeg check ──────────────────────────────────────────────────────────────
(async () => {
    try {
        const r = await fetch(API + '?action=check');
        const d = await r.json();
        const badge = document.getElementById('ffmpeg-badge');
        if (d.ffmpeg) {
            badge.className = 'badge ready';
            badge.textContent = '✅ FFmpeg prêt — v' + (d.version || 'ok');
        } else {
            badge.className = 'badge frames-only';
            badge.textContent = '⚠️ FFmpeg absent — mode frames uniquement (ZIP)';
        }
    } catch(e) {
        document.getElementById('ffmpeg-badge').textContent = '❓ Statut inconnu';
    }
})();

// ── Toast ─────────────────────────────────────────────────────────────────────
function toast(msg, type = '') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type ? ' ' + type : '');
    clearTimeout(t._tid);
    t._tid = setTimeout(() => t.classList.remove('show'), 3500);
}

// ── Filter ────────────────────────────────────────────────────────────────────
function filterCards() {
    const q = document.getElementById('search-input').value.toLowerCase();
    document.querySelectorAll('.card').forEach(c => {
        c.style.display = c.dataset.title.includes(q) ? '' : 'none';
    });
}

// ── Generate frames for one post ──────────────────────────────────────────────
async function generateFrames(slug, btn) {
    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';
    try {
        const r = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'generate_frames', slug})
        });
        const d = await r.json();
        if (d.ok) {
            toast('✅ Frames générées : ' + slug, 'success');
            updateCardStatus(slug, 'frames');
            // Enable video button
            const vBtn = document.getElementById('btn-video-' + slug);
            if (vBtn) vBtn.disabled = false;
        } else {
            toast('❌ Erreur : ' + (d.error || 'inconnue'), 'error');
        }
    } catch(e) { toast('❌ ' + e.message, 'error'); }
    btn.disabled = false; btn.textContent = origText;
}

// ── Musique : chargement liste ────────────────────────────────────────────────
async function loadMusicList() {
    try {
        const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'list_music'})});
        const d = await r.json();
        const sel = document.getElementById('music-select');
        const prev = sel.value;
        sel.innerHTML = '<option value="">— Sans musique —</option>';
        (d.files || []).forEach(f => {
            const opt = document.createElement('option');
            opt.value = f.name;
            opt.textContent = f.name + '  (' + f.size_kb + ' KB)';
            sel.appendChild(opt);
        });
        if (prev) sel.value = prev;
    } catch(e) {}
}

// ── Musique : upload ──────────────────────────────────────────────────────────
async function uploadMusic(input) {
    if (!input.files[0]) return;
    const fd = new FormData();
    fd.append('action', 'upload_music');
    fd.append('file', input.files[0]);
    toast('⬆ Upload en cours...', 'success');
    try {
        const r = await fetch(API, {method:'POST', body: fd});
        const d = await r.json();
        if (d.ok) {
            toast('✅ ' + d.name + ' uploadé', 'success');
            await loadMusicList();
            document.getElementById('music-select').value = d.name;
        } else {
            toast('❌ ' + (d.error || 'Erreur upload'), 'error');
        }
    } catch(e) { toast('❌ ' + e.message, 'error'); }
    input.value = '';
}

// ── Musique : supprimer ───────────────────────────────────────────────────────
async function deleteSelectedMusic() {
    const sel = document.getElementById('music-select');
    const name = sel.value;
    if (!name) return;
    if (!confirm('Supprimer "' + name + '" ?')) return;
    try {
        const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'delete_music', name})});
        const d = await r.json();
        if (d.ok) { toast('🗑 Supprimé', 'success'); await loadMusicList(); }
        else toast('❌ ' + (d.error || 'Erreur'), 'error');
    } catch(e) { toast('❌ ' + e.message, 'error'); }
}

// ── Generate video for one post ───────────────────────────────────────────────
async function generateVideo(slug, btn) {
    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Génération...';

    const music = document.getElementById('music-select')?.value || '';

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 120000); // 2 min max

    try {
        const r = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'generate_video', slug, music}),
            signal: controller.signal
        });
        clearTimeout(timer);
        const raw = await r.text();
        let d;
        try { d = JSON.parse(raw); } catch(je) {
            toast('❌ Réponse invalide : ' + (raw.substring(0, 200) || '(vide)'), 'error');
            btn.disabled = false; btn.textContent = origText; return;
        }
        if (d.ok) {
            toast('🎬 Vidéo créée : ' + d.size_kb + ' KB', 'success');
            updateCardStatus(slug, 'video');
        } else {
            toast('❌ FFmpeg : ' + (d.error || d.log || 'erreur inconnue'), 'error');
        }
    } catch(e) {
        clearTimeout(timer);
        if (e.name === 'AbortError') {
            toast('⏱️ Timeout — génération trop longue (>2min). Vérifie le chemin FFmpeg dans Config.', 'error');
        } else {
            toast('❌ ' + e.message, 'error');
        }
    }
    btn.disabled = false; btn.textContent = origText;
}

// ── Post vidéo sur Facebook Page ──────────────────────────────────────────────
async function postToFacebook(slug, btn) {
    const schedInput = document.getElementById('schedule-' + slug);
    const scheduledTime = schedInput?.value || '';

    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 180000); // 3 min

    try {
        const r = await fetch(API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'post_to_facebook', slug, scheduled_time: scheduledTime}),
            signal: controller.signal
        });
        clearTimeout(timer);
        const d = await r.json();
        if (d.ok) {
            const msg = d.scheduled
                ? '✅ Planifié pour le ' + d.time
                : '✅ Publié !';
            toast(msg, 'success');
            btn.textContent = d.scheduled ? '📅 Planifié' : '✅ Publié';
            btn.style.background = '#16a34a';
        } else {
            toast('❌ ' + (d.error || 'Erreur inconnue'), 'error');
            btn.disabled = false; btn.textContent = origText;
        }
    } catch(e) {
        clearTimeout(timer);
        const msg = e.name === 'AbortError'
            ? '⏱️ Timeout — upload trop long. Réessaie.'
            : '❌ ' + e.message;
        toast(msg, 'error');
        btn.disabled = false; btn.textContent = origText;
    }
}

// ── Download ZIP ──────────────────────────────────────────────────────────────
function downloadZip(slug) {
    window.location.href = API + '?action=download_zip&slug=' + encodeURIComponent(slug);
}

// ── Post vidéo sur YouTube ────────────────────────────────────────────────────
async function postToYoutube(slug, btn) {
    if (!confirm('Uploader "' + slug + '" sur YouTube ?')) return;
    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳ Upload...';

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 300000); // 5 min

    try {
        const r = await fetch(YT_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'post_single', slug}),
            signal: controller.signal
        });
        clearTimeout(timer);
        const d = await r.json();
        if (d.ok) {
            toast('✅ YouTube : ' + d.url, 'success');
            btn.textContent = '✅ YouTube';
            btn.classList.add('posted');
            btn.onclick = () => window.open(d.url, '_blank');
            btn.disabled = false;
        } else {
            toast('❌ YouTube : ' + (d.error || 'Erreur inconnue'), 'error');
            btn.disabled = false; btn.textContent = origText;
        }
    } catch(e) {
        clearTimeout(timer);
        toast(e.name === 'AbortError' ? '⏱️ Timeout — upload trop long' : '❌ ' + e.message, 'error');
        btn.disabled = false; btn.textContent = origText;
    }
}

// ── Lancer yt-auto-post.php (auto batch) ─────────────────────────────────────
async function runAutoYoutube(btn) {
    const origText = btn.textContent;
    btn.disabled = true; btn.textContent = '⏳...';
    try {
        const r = await fetch(YT_API, {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action:'run_auto'})
        });
        const d = await r.json();
        if (d.ok) {
            toast('✅ ' + d.message, 'success');
        } else {
            toast('❌ ' + (d.error || 'Erreur'), 'error');
        }
    } catch(e) { toast('❌ ' + e.message, 'error'); }
    btn.disabled = false; btn.textContent = origText;
}

// ── Update card status UI ─────────────────────────────────────────────────────
function updateCardStatus(slug, level) {
    const card = document.querySelector(`.card[data-slug="${slug}"]`);
    if (!card) return;
    const s = card.querySelector('.card-status');
    if (!s) return;
    if (level === 'video') {
        s.className = 'card-status status-video';
        s.innerHTML = '<span>🎬 Vidéo prête</span>';
        // Afficher la ligne de post Facebook
        const fbRow = document.getElementById('fb-row-' + slug);
        if (fbRow) fbRow.style.display = 'flex';
        // Afficher la ligne YouTube
        const ytRow = document.getElementById('yt-row-' + slug);
        if (ytRow) ytRow.style.display = 'flex';
        // Activer bouton vidéo
        const btnV = document.getElementById('btn-video-' + slug);
        if (btnV) btnV.disabled = false;
    } else if (level === 'frames') {
        s.className = 'card-status status-frames';
        s.innerHTML = '<span>🖼️ Frames prêtes</span>';
    }
}

// ── Generate all (frames only) ────────────────────────────────────────────────
async function generateAll() {
    const cards = [...document.querySelectorAll('.card')].filter(c => c.style.display !== 'none');
    if (!cards.length) { toast('Aucun post visible'); return; }

    const wrap = document.getElementById('progress-wrap');
    const fill = document.getElementById('progress-fill');
    const lbl  = document.getElementById('progress-label');
    wrap.classList.add('visible');

    let done = 0;
    for (const card of cards) {
        const slug = card.dataset.slug;
        lbl.textContent = `Génération frames : ${slug} (${done + 1}/${cards.length})`;
        fill.style.width = Math.round((done / cards.length) * 100) + '%';
        try {
            const r = await fetch(API, {
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body: JSON.stringify({action:'generate_frames', slug})
            });
            const d = await r.json();
            if (d.ok) updateCardStatus(slug, 'frames');
        } catch(e) { /* continue */ }
        done++;
    }
    fill.style.width = '100%';
    lbl.textContent = `✅ Terminé — ${done} posts traités`;
    setTimeout(() => wrap.classList.remove('visible'), 4000);
    toast('✅ Génération batch terminée !', 'success');
}

// ── Pending comments auto-processor ──────────────────────────────────────────
// async function processPendingComments() {
//     try {
//         const r = await fetch(API, {
//             method: 'POST',
//             headers: {'Content-Type':'application/json'},
//             body: JSON.stringify({action:'process_pending_comments'})
//         });
//         const d = await r.json();
//         if (d.done?.length) {
//             d.done.forEach(item => {
//                 const msg = item.pinned
//                     ? '📌 Commentaire épinglé — ' + item.slug
//                     : '💬 Commentaire ajouté — ' + item.slug;
//                 toast(msg, 'success');
//             });
//         }
//         if (d.errors?.length) d.errors.forEach(e => toast('⚠️ ' + e, 'error'));
//     } catch(e) {}
// }
// processPendingComments();
// setInterval(processPendingComments, 2 * 60 * 1000);


</script>
</body>
</html>
