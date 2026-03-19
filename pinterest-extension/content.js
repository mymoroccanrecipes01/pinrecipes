let injected = false;

function getToday() {
    return new Date().toISOString().split('T')[0];
}

function getCsvUrl(baseUrl) {
    return baseUrl.replace(/\/$/, '') + '/downloads/pinterest_daily_' + getToday() + '.csv';
}

function injectCsv(btn) {
    const input = document.getElementById('csv-input');
    if (!input) { setBtn(btn, '❌ Input introuvable', false); return; }

    chrome.storage.sync.get(['localUrl'], ({ localUrl = 'http://localhost/SitePinterset/pinrecipes' }) => {
        const url = getCsvUrl(localUrl);
        setBtn(btn, '⏳ Chargement...', true);

        chrome.runtime.sendMessage({ type: 'fetchCsv', url }, response => {
            if (!response || !response.ok) {
                setBtn(btn, '❌ ' + (response?.error || 'Erreur'), false);
                return;
            }

            const bytes = new Uint8Array(response.data);
            const blob  = new Blob([bytes], { type: 'text/csv' });
            const file  = new File([blob], 'pinterest_daily_' + getToday() + '.csv', { type: 'text/csv' });
            const dt    = new DataTransfer();
            dt.items.add(file);

            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new Event('input',  { bubbles: true }));

            injected = true;
            setBtn(btn, '✅ CSV injecté — fermeture...', false);
            setTimeout(() => window.close(), 2000);
        });
    });
}

function setBtn(btn, text, disabled) {
    if (!btn) return;
    btn.textContent = text;
    btn.disabled    = disabled;
}

function addUI() {
    if (document.getElementById('pint-auto-wrap')) return;
    const input = document.getElementById('csv-input');
    if (!input) return;

    const today = getToday();
    const wrap  = document.createElement('div');
    wrap.id = 'pint-auto-wrap';
    wrap.style.cssText = [
        'position:fixed', 'bottom:24px', 'right:24px', 'z-index:99999',
        'background:#fff', 'border-radius:14px', 'padding:16px 18px',
        'box-shadow:0 4px 24px rgba(0,0,0,.18)', 'min-width:230px',
        'font-family:-apple-system,sans-serif', 'font-size:14px', 'border:1.5px solid #eee'
    ].join(';');

    wrap.innerHTML = `
        <div style="font-weight:700;margin-bottom:4px;color:#333;font-size:15px">📌 Auto CSV Import</div>
        <div style="color:#aaa;font-size:11px;margin-bottom:14px;font-family:monospace">pinterest_daily_${today}.csv</div>
        <button id="pint-auto-btn" style="
            width:100%;padding:11px;background:#e60023;color:#fff;
            border:none;border-radius:9px;cursor:pointer;font-size:14px;font-weight:700;
        ">⚡ Importer le CSV du jour</button>
    `;
    document.body.appendChild(wrap);

    const btn = document.getElementById('pint-auto-btn');
    btn.addEventListener('click', e => injectCsv(e.currentTarget));

    // Auto-inject seulement si lancé via le .bat (?auto=1)
    if (new URLSearchParams(window.location.search).get('auto') === '1' && !injected) {
        setTimeout(() => injectCsv(btn), 1800);
    }
}

// Pinterest SPA — observe DOM for the input
const observer = new MutationObserver(() => {
    if (document.getElementById('csv-input')) addUI();
});
observer.observe(document.body, { childList: true, subtree: true });
setTimeout(addUI, 600);
