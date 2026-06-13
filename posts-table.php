<?php
require_once __DIR__ . '/auth.php';
auth_check();
?>
<!-- multi-sources.php -->
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Générateur Multi-Sources</title>
    <style>
        .multi-source-container {
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
            border-radius: 8px;
        }
        
        .main-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .left-panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
        }
        
        .right-panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
        }
        
        .source-count-selector {
            margin-bottom: 20px;
        }
        
        .source-count-selector label {
            font-weight: bold;
            margin-right: 10px;
        }
        
        .source-count-selector select {
            padding: 8px;
            font-size: 16px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        
        .sources-table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
            background: white;
        }
        
        .sources-table th,
        .sources-table td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }
        
        .sources-table th {
            background: #4CAF50;
            color: white;
            font-weight: bold;
        }
        
        .sources-table input[type="text"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
        }
        
        .sources-table .status {
            text-align: center;
            font-weight: bold;
        }
        
        .status.pending { color: #999; }
        .status.processing { color: #2196F3; }
        .status.success { color: #4CAF50; }
        .status.error { color: #f44336; }
        
        .control-buttons {
            margin: 20px 0;
            text-align: center;
        }
        
        .control-buttons button {
            padding: 12px 30px;
            margin: 0 10px;
            font-size: 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #4CAF50;
            color: white;
        }
        
        .btn-primary:hover {
            background: #45a049;
        }
        
        .btn-secondary {
            background: #2196F3;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #0b7dda;
        }
        
        .btn-danger {
            background: #f44336;
            color: white;
        }
        
        .btn-danger:hover {
            background: #da190b;
        }
        
        .btn-primary:disabled,
        .btn-secondary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .progress-info {
            text-align: center;
            margin: 20px 0;
            font-size: 18px;
            font-weight: bold;
        }
        
        .log-container {
            max-height: 300px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
        
        .log-entry {
            margin: 5px 0;
            padding: 5px;
        }
        
        .log-info { color: #2196F3; }
        .log-success { color: #4CAF50; }
        .log-error { color: #f44336; }
        .log-warning { color: #ff9800; }
        
        /* Iframe Preview */
        .iframe-container {
            margin-top: 20px;
            border: 2px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
            background: white;
        }
        
        .iframe-header {
            background: #333;
            color: white;
            padding: 10px 15px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .iframe-status {
            font-size: 14px;
            color: #4CAF50;
        }
        
        #previewIframe {
            width: 100%;
            height: 600px;
            border: none;
            background: white;
        }
        
        .iframe-loading {
            display: none;
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
        }
        
        .iframe-loading.active {
            display: block;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #4CAF50;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 1200px) {
            .main-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="multi-source-container">
    <h2>🚀 Générateur Multi-Sources avec Prévisualisation</h2>
    
    <div class="main-layout">
        <!-- Panneau gauche: Contrôles -->
        <div class="left-panel">
            <!-- Import CSV -->
            <div style="margin-bottom:15px; padding:12px; background:#e8f5e9; border-radius:6px; border:1px solid #a5d6a7;">
                <label style="font-weight:bold; display:block; margin-bottom:8px;">📂 Importer un fichier CSV :</label>
                <input type="file" id="csvFileInput" accept=".csv" style="margin-bottom:8px; display:block;">
                <div style="font-size:12px; color:#555; margin-bottom:8px;">Colonnes attendues: <code>Keyword, Board Name, #, Title</code> — la colonne <strong>Title</strong> sera utilisée.</div>
                <button onclick="importCSV()" class="btn-secondary" style="padding:8px 18px;">📥 Charger le CSV</button>
                <span id="csvStatus" style="margin-left:10px; font-size:13px; color:#555;"></span>
            </div>

            <!-- Sélecteur de nombre de sources -->
            <div class="source-count-selector">
                <label for="sourceCount">Nombre de sources :</label>
                <select id="sourceCount">
                    <option value="5">5 sources</option>
                    <option value="10" selected>10 sources</option>
                    <option value="15">15 sources</option>
                    <option value="20">20 sources</option>
                    <option value="30">30 sources</option>
                    <option value="50">50 sources</option>
                    <option value="100">100 sources</option>
                </select>
                <button onclick="generateTable()" class="btn-secondary">Générer Table</button>
            </div>
            
            <!-- Progress Info -->
            <div class="progress-info" id="progressInfo">
                En attente...
            </div>
            
            <!-- Table des sources -->
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="sources-table" id="sourcesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Source Text</th>
                            <th>Board</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody id="sourcesTableBody">
                        <!-- Les lignes seront générées ici -->
                    </tbody>
                </table>
            </div>
            
            <!-- Boutons de contrôle -->
            <div class="control-buttons">
                <button onclick="startProcessing()" id="startBtn" class="btn-primary">
                    ▶️ Lancer le traitement
                </button>
                <button onclick="stopProcessing()" id="stopBtn" class="btn-danger" disabled>
                    ⏸️ Arrêter
                </button>
                <button onclick="resetAll()" id="resetBtn" class="btn-secondary">
                    🔄 Réinitialiser
                </button>
            </div>
            
            <!-- Log Console -->
            <h3>📋 Console de logs</h3>
            <div class="log-container" id="logContainer"></div>
        </div>
        
        <!-- Panneau droit: Prévisualisation iframe -->
        <div class="right-panel">
            <h3>👁️ Prévisualisation en temps réel</h3>
            
            <div class="iframe-container">
                <div class="iframe-header">
                    <span>🖼️ Traitement en cours</span>
                    <span class="iframe-status" id="iframeStatus">En attente...</span>
                </div>
                
                <div class="iframe-loading" id="iframeLoading">
                    <div class="spinner"></div>
                    <p>Chargement de la post...</p>
                </div>
                
                <iframe id="previewIframe" name="previewIframe"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
// Configuration globale
const CONFIG = {
    delayBetweenSources: 40000,      // 5 secondes entre sources
    totalProcessingTime: 20000,      // 25 secondes par source
    enableAutoProcess: true,
    enableAutoSave: true,
    delayBetweenImages: 1000,
    delayBeforeSave: 8000
};

// Variables globales
let isProcessing = false;
let currentIndex = 0;
let sources = [];

// Générer la table
function generateTable() {
    const count = parseInt(document.getElementById('sourceCount').value);
    const tbody = document.getElementById('sourcesTableBody');
    tbody.innerHTML = '';
    sources = [];
    
    for (let i = 1; i <= count; i++) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${i}</td>
            <td>
                <input type="text"
                       id="source_${i}"
                       placeholder="Source ${i}"
                       data-index="${i-1}"
                       data-board="">
            </td>
            <td id="board_${i}" style="font-size:12px; color:#E60023; white-space:nowrap;">—</td>
            <td class="status pending" id="status_${i}">En attente</td>
        `;
        tbody.appendChild(row);

        sources.push({
            id: i,
            text: '',
            board: '',
            status: 'pending'
        });
    }
    
    addLog('Table générée avec ' + count + ' sources', 'info');
}

// Ajouter log
function addLog(message, type = 'info') {
    const logContainer = document.getElementById('logContainer');
    const timestamp = new Date().toLocaleTimeString();
    const logEntry = document.createElement('div');
    logEntry.className = `log-entry log-${type}`;
    logEntry.textContent = `[${timestamp}] ${message}`;
    logContainer.appendChild(logEntry);
    logContainer.scrollTop = logContainer.scrollHeight;
    console.log(`[${type.toUpperCase()}] ${message}`);
}

// Mettre à jour le statut iframe
function updateIframeStatus(message, color = '#4CAF50') {
    const iframeStatus = document.getElementById('iframeStatus');
    iframeStatus.textContent = message;
    iframeStatus.style.color = color;
}

// Mettre à jour le statut
function updateStatus(index, status, message) {
    const statusCell = document.getElementById(`status_${index + 1}`);
    statusCell.className = `status ${status}`;
    statusCell.textContent = message;
    sources[index].status = status;
}

// Mettre à jour la progression
function updateProgress() {
    const total = sources.length;
    const completed = sources.filter(s => s.status === 'success').length;
    const failed = sources.filter(s => s.status === 'error').length;
    const progressInfo = document.getElementById('progressInfo');
    
    progressInfo.innerHTML = `
        📊 Progression: ${completed}/${total} réussies | 
        ❌ ${failed} échouées | 
        ⏳ En cours: ${currentIndex + 1}/${total}
    `;
}

async function processSource(index) {
    if (!isProcessing) return false;
    
    const sourceInput = document.getElementById(`source_${index + 1}`);
    const sourceText = sourceInput.value.trim();
    const boardName = sourceInput.dataset.board || '';
    
    if (!sourceText) {
        addLog(`Source ${index + 1}: Vide - ignorée`, 'warning');
        updateStatus(index, 'error', '⚠️ Vide');
        return false;
    }
    
    updateStatus(index, 'processing', '⏳ Traitement...');
    addLog(`Source ${index + 1}: ═══ DÉBUT DU TRAITEMENT ═══`, 'info');
    updateIframeStatus(`Traitement source ${index + 1}/${sources.length}`, '#2196F3');
    
    return new Promise((resolve) => {
        let processCompleted = false;
        let saveCompleted = false;
        let timeoutId = null;
        
        const iframeLoading = document.getElementById('iframeLoading');
        iframeLoading.classList.add('active');
        
        // ✅ FONCTION POUR NETTOYER ET TERMINER
        const finishProcessing = (success, message) => {
            if (processCompleted) {
                console.log(`⚠️ Source ${index + 1} déjà terminée, ignorer`);
                return;
            }
            
            processCompleted = true;
            
            // 🧹 NETTOYER TOUT
            console.log(`🧹 Nettoyage source ${index + 1}`);
            
            window.removeEventListener('message', messageHandler);
            
            if (timeoutId) {
                clearTimeout(timeoutId);
                console.log(`✅ Timeout annulé pour source ${index + 1}`);
                timeoutId = null;
            }
            
            const formToRemove = document.getElementById(`form_${index}`);
            if (formToRemove) {
                try {
                    document.body.removeChild(formToRemove);
                } catch(e) {
                    console.log('Formulaire déjà supprimé');
                }
            }
           
            updateStatus(index, 'success', '✅ Terminé');
            addLog(`Source ${index + 1}: ${message}`, 'success');
            updateIframeStatus(`Source ${index + 1} terminée ✓`, '#4CAF50');

            resolve(success);
        };
        
        // ✅ ÉCOUTER LES MESSAGES DE L'IFRAME
        const messageHandler = function(event) {
            if (event.data && event.data.type === 'post_status') {
                const { status, message, slug } = event.data;
                
                console.log(`📩 Message reçu pour source ${index + 1}:`, status, message);
                addLog(`Source ${index + 1}: 📩 ${status.toUpperCase()} - ${message}`, status === 'error' ? 'error' : 'info');
                
                if (status === 'processing') {
                    updateIframeStatus(`Source ${index + 1}: Traitement images...`, '#2196F3');
                    iframeLoading.classList.remove('active');
                }
                
                if (status === 'saving') {
                    updateIframeStatus(`Source ${index + 1}: 💾 Sauvegarde...`, '#ff9800');
                }
                
                // ✅ SAUVEGARDE COMPLÈTE → passe immédiatement à la suivante
                if (status === 'completed' && !processCompleted) {
                    console.log(`✅ Source ${index + 1} sauvegardée!`);
                    finishProcessing(true, '✅ Post sauvegardé, passage à la suivante');
                }
                
                // ❌ En cas d'erreur
                if (status === 'error' && !processCompleted) {
                    console.error(`❌ Erreur source ${index + 1}:`, message);
                    finishProcessing(false, `❌ ERREUR - ${message}`);
                }
            }
        };
        
        window.addEventListener('message', messageHandler);
        addLog(`Source ${index + 1}: 👂 Écouteur postMessage activé`, 'info');
        
        // Créer le formulaire
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'posts-client.php';
        form.target = 'previewIframe';
        form.id = `form_${index}`;
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'generate_from_text';
        form.appendChild(actionInput);
        
        const sourceTextInput = document.createElement('input');
        sourceTextInput.type = 'hidden';
        sourceTextInput.name = 'source_text';
        sourceTextInput.value = sourceText;
        form.appendChild(sourceTextInput);
        
        const categoryInput = document.createElement('input');
        categoryInput.type = 'hidden';
        categoryInput.name = 'category_id';
        categoryInput.value = '1';
        form.appendChild(categoryInput);

        const boardInput = document.createElement('input');
        boardInput.type = 'hidden';
        boardInput.name = 'board_name';
        boardInput.value = boardName;
        form.appendChild(boardInput);
        
        document.body.appendChild(form);
        
        const iframe = document.getElementById('previewIframe');
        iframe.onload = function() {
            addLog(`Source ${index + 1}: 🌐 Page chargée dans iframe`, 'info');
        };
        
        addLog(`Source ${index + 1}: 📤 Soumission formulaire vers iframe`, 'info');
        form.submit();
        
        // ⏰ TIMEOUT DE SÉCURITÉ (4 minutes)
        timeoutId = setTimeout(() => {
            if (!processCompleted) {
                console.warn(`⏱️ TIMEOUT pour source ${index + 1} après 4 minutes`);
                finishProcessing(false, '⏱️ TIMEOUT - aucune réponse après 4min');
            }
        }, 240000); // 4 minutes

        addLog(`Source ${index + 1}: ⏰ Timeout de sécurité activé (4min)`, 'info');
    });
}

// Lancer le traitement
async function startProcessing() {
    // 🧹 VIDER LES LOGS AU DÉMARRAGE
    document.getElementById('logContainer').innerHTML = '';
    
    sources.forEach((source, index) => {
        const input = document.getElementById(`source_${index + 1}`);
        source.text = input.value.trim();
    });
    
    const validSources = sources.filter(s => s.text !== '');
    
    if (validSources.length === 0) {
        alert('❌ Aucune source valide trouvée!');
        return;
    }
    
    isProcessing = true;
    currentIndex = 0;
    
    document.getElementById('startBtn').disabled = true;
    document.getElementById('stopBtn').disabled = false;
    document.getElementById('resetBtn').disabled = true;
    
    addLog(`🚀 Démarrage du traitement de ${validSources.length} sources`, 'info');
    addLog(`⏱️ Temps estimé: ~${validSources.length * 2} minutes`, 'info');
    
    // ✅ TRAITER SÉQUENTIELLEMENT - ATTEND LA COMPLETION DE CHAQUE SOURCE
    for (let i = 0; i < sources.length; i++) {
        if (!isProcessing) {
            addLog('⏸️ Traitement arrêté par l\'utilisateur', 'warning');
            break;
        }
        
        currentIndex = i;
        updateProgress();
        
        if (sources[i].text) {
            // ⏳ ATTEND QUE processSource SOIT COMPLÉTÉ (reçoit postMessage)
            const success = await processSource(i);
            
            if (!success) {
                addLog(`⚠️ Source ${i + 1} a échoué, continuation...`, 'warning');
            }
            
            // Courte pause de nettoyage avant la suivante
            if (i < sources.length - 1 && isProcessing) {
                addLog(`⏳ Démarrage source suivante...`, 'info');
                updateIframeStatus('Préparation...', '#ff9800');
                await new Promise(resolve => setTimeout(resolve, 2000));
            }
        }
    }
    
    isProcessing = false;
    document.getElementById('startBtn').disabled = false;
    document.getElementById('stopBtn').disabled = true;
    document.getElementById('resetBtn').disabled = false;
    
    // 📊 AFFICHER LE RÉSUMÉ FINAL
    const totalSources = sources.filter(s => s.text !== '').length;
    const successCount = sources.filter(s => s.status === 'success').length;
    const failedCount = sources.filter(s => s.status === 'error').length;
    
    addLog('═══════════════════════════════════', 'info');
    addLog(`🎉 TRAITEMENT TERMINÉ!`, 'success');
    addLog(`📊 Résumé: ${successCount}/${totalSources} réussies, ${failedCount} échouées`, 'info');
    addLog('═══════════════════════════════════', 'info');
    
    updateIframeStatus(`✅ Terminé: ${successCount}/${totalSources} posts générées`, '#4CAF50');
    updateProgress();
    
    // 🔔 NOTIFICATION SONORE (optionnelle)
    try {
        const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBTGH0fPTgjMGHm7A7+OZURA');
        audio.play().catch(() => {}); // Ignorer si bloqué par le navigateur
    } catch (e) {}
}

// Arrêter le traitement
function stopProcessing() {
    isProcessing = false;
    addLog('⏸️ Arrêt demandé...', 'warning');
    updateIframeStatus('Arrêté par utilisateur', '#f44336');
    document.getElementById('stopBtn').disabled = true;
    document.getElementById('startBtn').disabled = false;
    document.getElementById('resetBtn').disabled = false;
}

// Réinitialiser
function resetAll() {
    isProcessing = false;
    currentIndex = 0;
    
    sources.forEach((source, index) => {
        updateStatus(index, 'pending', 'En attente');
    });
    
    document.getElementById('progressInfo').textContent = 'En attente...';
    document.getElementById('logContainer').innerHTML = '';
    document.getElementById('startBtn').disabled = false;
    document.getElementById('stopBtn').disabled = true;
    
    // Réinitialiser l'iframe
    const iframe = document.getElementById('previewIframe');
    iframe.src = 'about:blank';
    updateIframeStatus('En attente...', '#999');
    
    addLog('🔄 Système réinitialisé', 'info');
}

// Importer CSV et remplir les sources
function importCSV() {
    const fileInput = document.getElementById('csvFileInput');
    const statusEl = document.getElementById('csvStatus');

    if (!fileInput.files || !fileInput.files[0]) {
        statusEl.textContent = '⚠️ Aucun fichier sélectionné.';
        statusEl.style.color = '#f44336';
        return;
    }

    const file = fileInput.files[0];
    const reader = new FileReader();

    reader.onload = function(e) {
        const text = e.target.result;
        const lines = text.split(/\r?\n/).filter(l => l.trim() !== '');

        if (lines.length < 2) {
            statusEl.textContent = '⚠️ CSV vide ou invalide.';
            statusEl.style.color = '#f44336';
            return;
        }

        // Détecter l'index de la colonne Title depuis l'en-tête
        const parseCSVLine = (line) => {
            const result = [];
            let inQuotes = false, current = '';
            for (let i = 0; i < line.length; i++) {
                const ch = line[i];
                if (ch === '"') { inQuotes = !inQuotes; }
                else if (ch === ',' && !inQuotes) { result.push(current.trim()); current = ''; }
                else { current += ch; }
            }
            result.push(current.trim());
            return result;
        };

        const headers = parseCSVLine(lines[0]).map(h => h.replace(/^"|"$/g, '').toLowerCase());
        const titleIndex = headers.indexOf('title');
        const boardIndex = headers.indexOf('board name');

        if (titleIndex === -1) {
            statusEl.textContent = '⚠️ Colonne "Title" introuvable dans le CSV.';
            statusEl.style.color = '#f44336';
            return;
        }

        const rows = [];
        for (let i = 1; i < lines.length; i++) {
            const cols = parseCSVLine(lines[i]);
            const title = (cols[titleIndex] || '').replace(/^"|"$/g, '').trim();
            const board = boardIndex >= 0 ? (cols[boardIndex] || '').replace(/^"|"$/g, '').trim() : '';
            if (title) rows.push({ title, board });
        }

        if (rows.length === 0) {
            statusEl.textContent = '⚠️ Aucun titre trouvé dans le CSV.';
            statusEl.style.color = '#f44336';
            return;
        }

        // Ajuster le select si possible
        const select = document.getElementById('sourceCount');
        const options = Array.from(select.options).map(o => parseInt(o.value));
        const bestOption = options.find(v => v >= rows.length) || options[options.length - 1];
        select.value = bestOption;

        // Regénérer la table avec le bon nombre
        generateTable();

        // Remplir les inputs avec title et board_name
        rows.forEach(({ title, board }, i) => {
            const input = document.getElementById(`source_${i + 1}`);
            if (input) {
                input.value = title;
                input.dataset.board = board;
            }
            const boardCell = document.getElementById(`board_${i + 1}`);
            if (boardCell) boardCell.textContent = board || '—';
            if (sources[i]) sources[i].board = board;
        });

        statusEl.textContent = `✅ ${rows.length} titres chargés depuis "${file.name}"`;
        statusEl.style.color = '#4CAF50';
        addLog(`📂 CSV importé: ${rows.length} sources chargées depuis "${file.name}"`, 'success');
    };

    reader.readAsText(file, 'UTF-8');
}

// Initialiser au chargement
window.addEventListener('DOMContentLoaded', function() {
    generateTable();
    addLog('✅ Système prêt', 'success');
    addLog('ℹ️ Chaque source prend environ 30 secondes à traiter', 'info');
    addLog('👁️ Suivez le traitement en temps réel dans l\'iframe à droite', 'info');
});
</script>

</body>
</html>