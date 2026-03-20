let currentFiles = [];  // list of {filename, rows} from server
let isInjecting  = false;

// ── Fetch list of pending CSV files ──────────────────────────────────────────
function loadCsvList(baseUrl, callback) {
    const url = baseUrl.replace(/\/$/, '') + '/posts-liste.php?action=check_daily_csv';
    chrome.runtime.sendMessage({ type: 'fetchJson', url }, response => {
        if (response && response.ok && response.data && response.data.files) {
            callback(response.data.files);
        } else {
            callback([]);
        }
    });
}

// ── Delete a CSV file from server after successful upload ─────────────────────
function deleteCsv(baseUrl, filename, callback) {
    const url = baseUrl.replace(/\/$/, '') + '/posts-liste.php?action=delete_csv&file=' + encodeURIComponent(filename);
    chrome.runtime.sendMessage({ type: 'fetchJson', url }, response => {
        callback(response && response.ok && response.data && response.data.success);
    });
}

// ── Inject a CSV file into Pinterest's file input ─────────────────────────────
function injectCsv(baseUrl, filename, onSuccess, onError) {
    const input = document.getElementById('csv-input');
    if (!input) { onError('Input Pinterest introuvable'); return; }

    const url = baseUrl.replace(/\/$/, '') + '/downloads/' + filename;
    chrome.runtime.sendMessage({ type: 'fetchCsv', url }, response => {
        if (!response || !response.ok) {
            onError(response?.error || 'Erreur chargement');
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
        onSuccess();
    });
}

// ── Build / refresh the UI panel ─────────────────────────────────────────────
function renderPanel(baseUrl, files) {
    currentFiles = files;
    let wrap = document.getElementById('pint-auto-wrap');
    if (!wrap) return;

    if (files.length === 0) {
        wrap.innerHTML = `
            <div style="font-weight:700;color:#333;font-size:15px;margin-bottom:6px">📌 Auto CSV Import</div>
            <div style="color:#16a34a;font-size:13px">✅ Tous les CSV uploadés</div>
        `;
        return;
    }

    const rows = files.map((f, i) => `
        <div id="csv-row-${i}" style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <div style="flex:1;min-width:0">
                <div style="font-size:12px;font-weight:700;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">📄 ${f.filename}</div>
                <div style="font-size:11px;color:#aaa">${f.rows} pins · ${f.date}</div>
            </div>
            <button id="csv-btn-${i}" data-idx="${i}" style="
                padding:7px 12px;background:#e60023;color:#fff;border:none;border-radius:8px;
                cursor:pointer;font-size:12px;font-weight:700;white-space:nowrap;flex-shrink:0
            ">⚡ Upload</button>
        </div>
    `).join('');

    wrap.innerHTML = `
        <div style="font-weight:700;color:#333;font-size:15px;margin-bottom:10px">📌 CSV à uploader (${files.length})</div>
        ${rows}
    `;

    files.forEach((f, i) => {
        document.getElementById('csv-btn-' + i).addEventListener('click', function() {
            if (isInjecting) return;
            const btn = this;
            const row = document.getElementById('csv-row-' + i);
            isInjecting = true;
            btn.textContent = '⏳...';
            btn.disabled = true;

            injectCsv(baseUrl, f.filename,
                () => {
                    // Success — delete from server then refresh list
                    row.style.opacity = '0.5';
                    btn.textContent = '✅ OK';
                    deleteCsv(baseUrl, f.filename, deleted => {
                        isInjecting = false;
                        // Remove from list and re-render
                        const remaining = currentFiles.filter((_, j) => j !== i);
                        renderPanel(baseUrl, remaining);
                    });
                },
                (err) => {
                    btn.textContent = '❌ ' + err;
                    btn.disabled = false;
                    isInjecting = false;
                }
            );
        });
    });
}

// ── Create the floating panel ─────────────────────────────────────────────────
function addUI() {
    if (document.getElementById('pint-auto-wrap')) return;
    if (!document.getElementById('csv-input')) return;

    const wrap = document.createElement('div');
    wrap.id = 'pint-auto-wrap';
    wrap.style.cssText = [
        'position:fixed', 'bottom:24px', 'right:24px', 'z-index:99999',
        'background:#fff', 'border-radius:14px', 'padding:16px 18px',
        'box-shadow:0 4px 24px rgba(0,0,0,.18)', 'min-width:260px', 'max-width:320px',
        'font-family:-apple-system,sans-serif', 'font-size:14px', 'border:1.5px solid #eee'
    ].join(';');
    wrap.innerHTML = '<div style="color:#aaa;font-size:12px">⏳ Chargement CSV...</div>';
    document.body.appendChild(wrap);

    chrome.storage.sync.get(['localUrl', 'autoInject'], ({ localUrl = 'http://localhost/SitePinterset/pinrecipes', autoInject }) => {
        loadCsvList(localUrl, files => {
            renderPanel(localUrl, files);

            // Auto-inject first file if ?auto=1 and autoInject enabled
            if (autoInject && files.length > 0 && new URLSearchParams(window.location.search).get('auto') === '1') {
                setTimeout(() => {
                    document.getElementById('csv-btn-0')?.click();
                }, 1800);
            }
        });
    });
}

// ── Pinterest SPA — observe DOM ───────────────────────────────────────────────
const observer = new MutationObserver(() => {
    if (document.getElementById('csv-input')) addUI();
});
observer.observe(document.body, { childList: true, subtree: true });
setTimeout(addUI, 600);
