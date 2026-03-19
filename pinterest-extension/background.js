// Fetch CSV from localhost — bypasses CORS restrictions of content scripts
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'fetchCsv') {
        fetch(msg.url)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status + ' — fichier introuvable');
                return r.arrayBuffer();
            })
            .then(buf => sendResponse({ ok: true, data: Array.from(new Uint8Array(buf)) }))
            .catch(e => sendResponse({ ok: false, error: e.message }));
        return true; // keep async channel open
    }

    if (msg.type === 'openPinterest') {
        chrome.tabs.create({ url: 'https://www.pinterest.com/settings/bulk-create-pins/?auto=1' });
    }
});
