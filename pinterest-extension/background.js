// Fetch CSV from localhost — bypasses CORS restrictions of content scripts
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
    if (msg.type === 'checkCsv') {
        fetch(msg.url, { method: 'HEAD' })
            .then(r => sendResponse({ exists: r.ok }))
            .catch(() => sendResponse({ exists: false }));
        return true;
    }

    if (msg.type === 'fetchCsv') {
        fetch(msg.url)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status + ' — fichier introuvable');
                return r.arrayBuffer();
            })
            .then(buf => sendResponse({ ok: true, data: Array.from(new Uint8Array(buf)) }))
            .catch(e => sendResponse({ ok: false, error: e.message }));
        return true;
    }

    if (msg.type === 'fetchJson') {
        fetch(msg.url)
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => sendResponse({ ok: true, data }))
            .catch(e => sendResponse({ ok: false, error: e.message }));
        return true;
    }

    if (msg.type === 'openPinterest') {
        chrome.tabs.create({ url: 'https://www.pinterest.com/settings/bulk-create-pins/' });
    }

    if (msg.type === 'setupAlarm') {
        scheduleAlarm(msg.importHour ?? 9, msg.importMinute ?? 0);
    }
});

// Open Pinterest at scheduled time if autoOpen is enabled
chrome.alarms.onAlarm.addListener(alarm => {
    if (alarm.name !== 'daily-pinterest-import') return;
    chrome.storage.sync.get(['autoOpen'], data => {
        if (data.autoOpen) {
            chrome.tabs.create({ url: 'https://www.pinterest.com/settings/bulk-create-pins/' });
        }
    });
});

chrome.runtime.onInstalled.addListener(init);
chrome.runtime.onStartup.addListener(init);

function init() {
    chrome.storage.sync.get(['importHour', 'importMinute'], data => {
        scheduleAlarm(data.importHour ?? 9, data.importMinute ?? 0);
    });
}

function scheduleAlarm(hour, minute) {
    const now = new Date();
    const next = new Date();
    next.setHours(hour, minute ?? 0, 0, 0);
    if (next <= now) next.setDate(next.getDate() + 1);

    chrome.alarms.clear('daily-pinterest-import', () => {
        chrome.alarms.create('daily-pinterest-import', {
            when: next.getTime(),
            periodInMinutes: 24 * 60
        });
    });
}
