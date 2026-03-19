const $ = id => document.getElementById(id);

// Load saved settings
chrome.storage.sync.get(['localUrl', 'importHour', 'autoOpen', 'autoInject'], data => {
    $('localUrl').value      = data.localUrl    || 'http://localhost/SitePinterset/pinrecipes';
    $('importHour').value    = data.importHour  ?? 9;
    $('autoOpen').checked    = !!data.autoOpen;
    $('autoInject').checked  = !!data.autoInject;
});

// Save
$('saveBtn').addEventListener('click', () => {
    const settings = {
        localUrl:    $('localUrl').value.trim(),
        importHour:  parseInt($('importHour').value) || 9,
        autoOpen:    $('autoOpen').checked,
        autoInject:  $('autoInject').checked,
    };
    chrome.storage.sync.set(settings, () => {
        chrome.runtime.sendMessage({ type: 'setupAlarm', ...settings });
        $('saved').style.display = 'block';
        setTimeout(() => $('saved').style.display = 'none', 2500);
    });
});

// Open Pinterest bulk upload page
$('openBtn').addEventListener('click', () => {
    chrome.tabs.create({ url: 'https://www.pinterest.com/settings/bulk-create-pins/' });
    window.close();
});
