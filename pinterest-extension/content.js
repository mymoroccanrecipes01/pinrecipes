// Capture ?auto=1 immediately — Pinterest SPA strips query params later
const autoMode = new URLSearchParams(window.location.search).get('auto') === '1';
let injected = false;
let autoTotalPins = 0;        // pins uploaded this session
const AUTO_PIN_LIMIT = 90;    // Pinterest max is 100 — stay safe

function setBtn(btn, text, disabled) {
    if (!btn) return;
    btn.textContent = text;
    btn.disabled    = disabled;
}

function injectCsv(btn, fileUrl, filename, baseUrl, onDone) {
    const input = document.getElementById('csv-input');
    if (!input) { setBtn(btn, '❌ Input introuvable', false); return; }

    setBtn(btn, '⏳ Chargement...', true);

    chrome.runtime.sendMessage({ type: 'fetchCsv', url: fileUrl }, response => {
        if (!response || !response.ok) {
            setBtn(btn, '❌ ' + (response?.error || 'Erreur'), false);
            return;
        }

        const bytes = new Uint8Array(response.data);
        const blob  = new Blob([bytes], { type: 'text/csv' });
        const file  = new File([blob], filename, { type: 'text/csv' });
        const dt    = new DataTransfer();
        dt.items.add(file);

        input.files = dt.files;
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.dispatchEvent(new Event('input',  { bubbles: true }));

        setBtn(btn, '✅ Injecté !', true);

        // Delete from server after success
        chrome.runtime.sendMessage({
            type: 'fetchJson',
            url: baseUrl + '/csv-api.php?action=delete&file=' + encodeURIComponent(filename)
        }, () => {});

        injected = true;
        if (onDone) setTimeout(onDone, 1500);
    });
}

function addUI() {
    if (document.getElementById('pint-auto-wrap')) return;
    if (!document.getElementById('csv-input')) return;

    const wrap = document.createElement('div');
    wrap.id = 'pint-auto-wrap';
    wrap.style.cssText = [
        'position:fixed', 'bottom:24px', 'right:24px', 'z-index:99999',
        'background:#fff', 'border-radius:14px', 'padding:16px 18px',
        'box-shadow:0 4px 24px rgba(0,0,0,.18)', 'min-width:240px',
        'font-family:-apple-system,sans-serif', 'font-size:14px', 'border:1.5px solid #eee'
    ].join(';');
    wrap.innerHTML = '<div style="color:#aaa;font-size:12px">⏳ Chargement...</div>';
    document.body.appendChild(wrap);

    chrome.storage.sync.get(['localUrl'], function(data) {
        const baseUrl = (data.localUrl || 'http://localhost/SitePinterset/pinrecipes').replace(/\/$/, '');

        chrome.runtime.sendMessage({ type: 'fetchJson', url: baseUrl + '/csv-api.php?action=list' }, function(response) {
            const files = (response && response.ok && response.data && response.data.files) ? response.data.files : [];
            const today = (response && response.ok && response.data) ? (response.data.date || '') : '';
            renderPanel(wrap, baseUrl, files, today);
        });
    });
}

function renderPanel(wrap, baseUrl, files, today) {
    if (files.length === 0) {
        wrap.innerHTML = [
            '<div style="font-weight:700;color:#333;font-size:15px;margin-bottom:6px">📌 Pinterest CSV</div>',
            '<div style="color:#888;font-size:13px">Aucun CSV disponible</div>'
        ].join('');
        return;
    }

    var remaining100 = AUTO_PIN_LIMIT - autoTotalPins;

    var rows = files.map(function(f, i) {
        var isToday   = today && f.date === today;
        var overLimit = isToday && autoMode && (f.rows > remaining100);
        var badge     = isToday ? ' <span style="font-size:10px;background:#dcfce7;color:#16a34a;border-radius:4px;padding:1px 5px">Aujourd\'hui</span>' : '';
        var limitBadge = overLimit ? ' <span style="font-size:10px;background:#fee2e2;color:#dc2626;border-radius:4px;padding:1px 5px">⚠ +' + f.rows + ' pins</span>' : '';
        return [
            '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">',
            '  <div style="flex:1">',
            '    <div style="font-size:12px;font-weight:700;color:#333">' + (f.label || f.date) + badge + limitBadge + '</div>',
            '    <div style="font-size:11px;color:#aaa">' + f.rows + ' pins</div>',
            '  </div>',
            '  <button id="csv-btn-' + i + '" style="padding:7px 12px;background:#e60023;color:#fff;border:none;border-radius:8px;cursor:pointer;font-size:12px;font-weight:700">⚡ Upload</button>',
            '</div>'
        ].join('');
    }).join('');

    // Show remaining capacity
    var capacityBar = autoMode
        ? '<div style="font-size:11px;color:#888;margin-bottom:10px">Capacité restante : <b style="color:' + (remaining100 < 20 ? '#dc2626' : '#16a34a') + '">' + remaining100 + ' / ' + AUTO_PIN_LIMIT + ' pins</b></div>'
        : '';

    wrap.innerHTML = '<div style="font-weight:700;color:#333;font-size:15px;margin-bottom:6px">📌 Pinterest CSV (' + files.length + ')</div>'
        + capacityBar + rows;

    files.forEach(function(f, i) {
        var btn = document.getElementById('csv-btn-' + i);
        if (!btn) return;
        btn.addEventListener('click', function() {
            autoTotalPins += f.rows;  // count pins when uploaded (manual or auto)
            var fileUrl = baseUrl + '/downloads/' + f.filename;
            injectCsv(btn, fileUrl, f.filename, baseUrl, function() {
                var remaining = files.filter(function(_, j) { return j !== i; });
                renderPanel(wrap, baseUrl, remaining, today);
            });
        });
    });

    // Auto-click: today's files only, stop when remaining capacity exhausted
    if (autoMode) {
        var nextIdx = -1;
        for (var i = 0; i < files.length; i++) {
            if (today && files[i].date === today && files[i].rows <= (AUTO_PIN_LIMIT - autoTotalPins)) {
                nextIdx = i;
                break;
            }
        }
        if (nextIdx >= 0) {
            setTimeout(function() {
                var btn = document.getElementById('csv-btn-' + nextIdx);
                if (btn && !btn.disabled) btn.click();
            }, 1500);
        } else if (autoMode && files.some(function(f) { return today && f.date === today; })) {
            // Today's files exist but all exceed limit — show warning
            var limitDiv = document.createElement('div');
            limitDiv.style.cssText = 'margin-top:8px;padding:8px 10px;background:#fee2e2;border-radius:8px;font-size:12px;color:#dc2626;font-weight:700';
            limitDiv.textContent = '⚠ Limite 100 pins atteinte — upload manuel requis';
            wrap.appendChild(limitDiv);
        }
    }
}

// Pinterest SPA — observe DOM for the file input
// Guard required: script runs at document_start, body may not exist yet
function startObserver() {
    var observer = new MutationObserver(function() {
        if (document.getElementById('csv-input')) addUI();
    });
    observer.observe(document.body, { childList: true, subtree: true });
    setTimeout(addUI, 600);
}

if (document.body) {
    startObserver();
} else {
    document.addEventListener('DOMContentLoaded', startObserver);
}
