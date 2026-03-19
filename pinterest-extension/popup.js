const $ = id => document.getElementById(id);

const AUTO_URL = 'https://www.pinterest.com/settings/bulk-create-pins/?auto=1';
const MANUAL_URL = 'https://www.pinterest.com/settings/bulk-create-pins/';

// Load saved settings
chrome.storage.sync.get(['localUrl'], data => {
    $('localUrl').value = data.localUrl || 'http://localhost/SitePinterset/pinrecipes';
});

// Launch auto import (with ?auto=1)
$('autoBtn').addEventListener('click', () => {
    chrome.tabs.create({ url: AUTO_URL });
    window.close();
});

// Open manually (no auto-inject)
$('openBtn').addEventListener('click', () => {
    chrome.tabs.create({ url: MANUAL_URL });
    window.close();
});

// Save URL
$('saveBtn').addEventListener('click', () => {
    chrome.storage.sync.set({ localUrl: $('localUrl').value.trim() }, () => {
        $('saved').style.display = 'block';
        setTimeout(() => $('saved').style.display = 'none', 2000);
    });
});
