<?php
/**
 * Pinterest CSV Keyword Researcher
 * Génère un CSV "Keyword","Title" via OpenAI + Google Trends
 * et le sauvegarde dans KEYWORDS_PIN_DIR pour auto-pipeline.php
 *
 * Usage CLI: php pinterest-keyword-researcher.php [--count=20]
 * Inclus automatiquement par auto-pipeline.php
 */

if (!defined('PINTEREST_KR_INCLUDED')) {
    define('PINTEREST_KR_INCLUDED', true);
}

if (!defined('OPENAI_API_KEY')) {
    require_once __DIR__ . '/config.php';
}

$pkr_isCli = php_sapi_name() === 'cli';

// Lire min/max depuis settings.json (même source que auto-pipeline.php)
$pkr_settings = [];
$pkr_settingsFile = __DIR__ . '/settings.json';
if (file_exists($pkr_settingsFile)) {
    $pkr_settings = json_decode(file_get_contents($pkr_settingsFile), true) ?: [];
}
$pkr_limitMin = (int)($pkr_settings['pipelineLimitMin'] ?? 10);
$pkr_limitMax = (int)($pkr_settings['pipelineLimitMax'] ?? 20);
$pkr_count    = rand($pkr_limitMin, $pkr_limitMax);

// Override via CLI si besoin
if ($pkr_isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--count=(\d+)$/', $arg, $m)) {
            $pkr_count = (int)$m[1];
        }
    }
}

function pkr_log($msg) {
    global $pkr_isCli;
    if ($pkr_isCli) echo "  [PKR] $msg\n";
    // Append to pipeline log si disponible
    $logFile = __DIR__ . '/processing.log';
    $line = "[" . date('Y-m-d H:i:s') . "] [PINTEREST-KR] [INFO] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
}

// ── 1. Dossier de sortie ─────────────────────────────────────────────────────
$pkr_outDir = defined('KEYWORDS_PIN_DIR') ? KEYWORDS_PIN_DIR : (dirname(__DIR__) . '/keywordsPIN');
if (!is_dir($pkr_outDir)) {
    mkdir($pkr_outDir, 0755, true);
}
// Resolve any '..' so glob() works correctly on Linux
$pkr_outDir = realpath($pkr_outDir) ?: $pkr_outDir;

// Supprimer tout CSV existant avant de générer le nouveau
$pkr_todayFile  = $pkr_outDir . '/pinterest_' . date('Y-m-d') . '.csv';
$pkr_oldCsvFiles = glob($pkr_outDir . '/*.csv') ?: [];
foreach ($pkr_oldCsvFiles as $pkr_oldFile) {
    unlink($pkr_oldFile);
    pkr_log("🗑️ Ancien CSV supprimé: " . basename($pkr_oldFile));
}

// ── Blacklist helpers ────────────────────────────────────────────────────────
/**
 * Charge la blacklist des titres récemment générés (90 jours glissants).
 * Retourne un array de titres normalisés (lowercase) pour comparaison rapide.
 */
function pkr_loadBlacklist() {
    $file = __DIR__ . '/downloads/used-keywords.json';
    if (!file_exists($file)) return [];

    $entries  = json_decode(file_get_contents($file), true) ?: [];
    $cutoff   = date('Y-m-d', strtotime('-90 days'));
    $titles   = [];

    foreach ($entries as $e) {
        if (($e['date'] ?? '') >= $cutoff) {
            $titles[] = strtolower(trim($e['title'] ?? ''));
        }
    }
    return array_filter($titles);
}

/**
 * Ajoute les nouveaux titres générés dans la blacklist (fenêtre 90 jours).
 */
function pkr_saveToBlacklist(array $rows) {
    $file    = __DIR__ . '/downloads/used-keywords.json';
    $entries = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $cutoff  = date('Y-m-d', strtotime('-90 days'));
    $today   = date('Y-m-d');
    $site    = defined('SITE_FOLDER') ? SITE_FOLDER : 'default';

    // Purger les entrées > 90 jours
    $entries = array_values(array_filter($entries, fn($e) => ($e['date'] ?? '') >= $cutoff));

    foreach ($rows as $row) {
        $title = trim($row[1] ?? '');
        if ($title) {
            $entries[] = ['date' => $today, 'site' => $site, 'title' => $title, 'keyword' => trim($row[0] ?? '')];
        }
    }

    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    file_put_contents($file, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    pkr_log("Blacklist mise à jour: " . count($entries) . " entrées (90j)");
}

// ── 2. Saison + ingrédients saisonniers ─────────────────────────────────────
$pkr_month = (int)date('n');
if ($pkr_month >= 3 && $pkr_month <= 5) {
    $pkr_season      = 'Spring';
    $pkr_ingredients = ['Asparagus', 'Strawberries', 'Peas', 'Artichokes', 'Spinach', 'Lamb', 'Lemon', 'Radishes'];
} elseif ($pkr_month >= 6 && $pkr_month <= 8) {
    $pkr_season      = 'Summer';
    $pkr_ingredients = ['Tomatoes', 'Zucchini', 'Corn', 'Peaches', 'Blueberries', 'Basil', 'Cucumber', 'Watermelon'];
} elseif ($pkr_month >= 9 && $pkr_month <= 11) {
    $pkr_season      = 'Fall';
    $pkr_ingredients = ['Pumpkin', 'Apples', 'Sweet Potatoes', 'Brussels Sprouts', 'Cranberries', 'Butternut Squash', 'Cinnamon', 'Pecans'];
} else {
    $pkr_season      = 'Winter';
    $pkr_ingredients = ['Citrus', 'Root Vegetables', 'Kale', 'Butternut Squash', 'Pomegranate', 'Ginger', 'Clementines', 'Leeks'];
}

// ── Fonction principale : retourne [['keyword','title'], ...] selon KEYWORD_SOURCE ──
/**
 * Récupère les keywords/titres selon la source configurée (KEYWORD_SOURCE).
 * 'prompt'         → OpenAI/Anthropic génère le CSV complet
 * 'google_suggest' → Google Autocomplete API fournit les keywords, titres nettoyés
 *
 * @return array  Tableau de [$keyword, $title] | tableau vide si échec
 */
function pkr_fetchKeywords($count, $season, $ingredients, $isCli) {
    $source    = defined('KEYWORD_SOURCE') ? KEYWORD_SOURCE : 'prompt';
    $blacklist = pkr_loadBlacklist();
    $site      = defined('SITE_FOLDER') ? SITE_FOLDER : 'default';
    pkr_log("Source keywords: $source | Site: $site | Blacklist: " . count($blacklist) . " titres");

    if ($source === 'pinterest_import') {
        $rows = pkr_fetchFromImportedTrends($count, $blacklist);
        if (!empty($rows)) return $rows;
        pkr_log("⚠️  Import Pinterest vide/expiré — fallback Google Suggest");
        $rows = pkr_fetchFromGoogleSuggest($count, $ingredients, $blacklist);
        if (!empty($rows)) return $rows;
        return pkr_fetchFromPrompt($count, $season, $ingredients, $blacklist);
    }

    if ($source === 'google_suggest') {
        return pkr_fetchFromGoogleSuggest($count, $ingredients, $blacklist);
    }

    if ($source === 'pinterest_trends') {
        $rows = pkr_fetchFromPinterestTrends($count, $blacklist);
        if (!empty($rows)) return $rows;
        // Fallback silencieux si tous les endpoints Pinterest échouent
        pkr_log("⚠️  Pinterest Trends indisponible — fallback automatique Google Suggest");
        $rows = pkr_fetchFromGoogleSuggest($count, $ingredients, $blacklist);
        if (!empty($rows)) return $rows;
        pkr_log("⚠️  Google Suggest aussi indisponible — fallback Prompt IA");
        return pkr_fetchFromPrompt($count, $season, $ingredients, $blacklist);
    }

    if ($source === 'auto') {
        return pkr_fetchSmartAuto($count, $season, $ingredients, $blacklist);
    }

    return pkr_fetchFromPrompt($count, $season, $ingredients, $blacklist);
}

/**
 * Récupère le modifieur du jour depuis Google Suggest sur "recipe".
 * Google retourne ce qui est trending → le modifieur change naturellement chaque jour.
 * L'index est déterministe par date → même run = même modifieur, lendemain = différent.
 *
 * Exemples: "easy", "homemade", "creamy", "quick", "baked"...
 */
function pkr_fetchDailyModifier() {
    $fallback = ['easy', 'homemade', 'quick', 'best', 'simple', 'healthy', 'creamy',
                 'crispy', 'baked', 'grilled', 'one pot', 'slow cooker', 'air fryer',
                 'sheet pan', '30 minute', 'no bake', 'vegan', 'classic', 'spicy', 'cheesy'];

    // Fetch Google Suggest pour "recipe" — retourne ce qui est trending
    $ch = curl_init('https://suggestqueries.google.com/complete/search?output=toolbar&hl=en&q=recipe');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $xml  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $modifiers = [];

    if ($code === 200 && $xml) {
        preg_match_all('/<suggestion\s+data="([^"]+)"/i', $xml, $matches);
        foreach ($matches[1] ?? [] as $s) {
            // Extraire le mot/phrase AVANT "recipe" dans la suggestion
            // ex: "easy recipe" → "easy" | "one pot recipe" → "one pot"
            if (preg_match('/^(.+?)\s+recipe/i', trim($s), $m)) {
                $mod = strtolower(trim($m[1]));
                if (strlen($mod) >= 3 && strlen($mod) <= 20) {
                    $modifiers[] = $mod;
                }
            }
        }
    }

    // Fallback si Google n'a pas retourné de résultats utilisables
    if (empty($modifiers)) {
        pkr_log("Modifieur: fallback liste interne (Google indisponible)");
        $modifiers = $fallback;
    }

    // Index déterministe par date → change chaque jour, stable pour tous les runs du même jour
    $index    = abs(crc32(date('Y-m-d'))) % count($modifiers);
    $modifier = $modifiers[$index];

    pkr_log("Modifieur du jour: \"$modifier\" (index $index/" . count($modifiers) . " depuis Google Suggest)");
    return $modifier;
}

/**
 * Source 'google_suggest' — modifieur journalier + seeds configurés → suggestions variées chaque jour.
 * Flux: pkr_fetchDailyModifier() → "{modifier} {seed} recipe" → Google Suggest → pkr_formatTitle()
 */
function pkr_fetchFromGoogleSuggest($count, $ingredients, array $blacklist = []) {
    // ── Modifieur du jour (vient de Google, change chaque jour) ─────────────
    $modifier = pkr_fetchDailyModifier();

    // ── Seeds depuis config UI + ingrédients saisonniers ────────────────────
    $configSeeds = [];
    if (defined('KEYWORD_SUGGEST_SEEDS') && KEYWORD_SUGGEST_SEEDS) {
        $configSeeds = array_values(array_filter(array_map('trim', explode("\n", KEYWORD_SUGGEST_SEEDS))));
    }
    if (empty($configSeeds)) {
        $configSeeds = ['chicken', 'pasta', 'salad', 'soup', 'dessert', 'cake', 'cookies', 'dinner'];
    }
    $seeds = array_values(array_unique(array_merge($configSeeds, array_map('strtolower', $ingredients))));

    // Shuffle déterministe par date (ordre différent chaque jour)
    srand(abs(crc32(date('Y-m-d'))));
    shuffle($seeds);
    srand();
    pkr_log("Seeds: " . implode(', ', $seeds));

    $rows    = [];
    $skipped = 0;

    foreach ($seeds as $seed) {
        if (count($rows) >= $count) break;

        // Query = "{modifier} {seed} recipe" → suggestions différentes chaque jour
        $query = "$modifier $seed recipe";
        $url   = 'https://suggestqueries.google.com/complete/search?output=toolbar&hl=en&q='
               . urlencode($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$xml) {
            pkr_log("Google Suggest: échec pour '$query' (HTTP $code)");
            continue;
        }

        preg_match_all('/<suggestion\s+data="([^"]+)"/i', $xml, $matches);

        foreach ($matches[1] ?? [] as $suggestion) {
            if (count($rows) >= $count) break;

            $keyword = trim($suggestion);
            $title   = pkr_formatTitle($keyword);
            if (strlen($title) < 10) continue;

            if (in_array(strtolower($title), $blacklist, true)) {
                $skipped++;
                continue;
            }

            $rows[] = [$keyword, $title];
        }
    }

    pkr_log("Google Suggest: " . count($rows) . " collectées ($skipped ignorées — déjà générées)");
    return $rows;
}

/**
 * Source 'pinterest_trends' — Bing Suggest API (public, sans auth, sans clé API).
 * Retourne des suggestions de recherche de haute qualité similaires aux tendances Pinterest.
 * Endpoint: api.bing.com/osjson.aspx — format OpenSearch JSON standard.
 */
function pkr_fetchFromPinterestTrends($count, array $blacklist = []) {
    $niche   = defined('PINTEREST_TRENDS_INCLUDE_KEYWORD') ? trim(PINTEREST_TRENDS_INCLUDE_KEYWORD) : 'recipe';
    if (empty($niche)) $niche = 'recipe';

    // Mapper le pays Pinterest vers le market Bing (ex: US→en-US, FR→fr-FR, MA→ar-MA)
    $country = defined('PINTEREST_TRENDS_COUNTRY') ? PINTEREST_TRENDS_COUNTRY : 'US';
    $marketMap = ['US'=>'en-US','GB'=>'en-GB','CA'=>'en-CA','AU'=>'en-AU','FR'=>'fr-FR','MA'=>'fr-MA','DE'=>'de-DE'];
    $market  = $marketMap[$country] ?? 'en-US';

    // Seeds configurés + type de tendance pour varier les queries
    $configSeeds = [];
    if (defined('KEYWORD_SUGGEST_SEEDS') && KEYWORD_SUGGEST_SEEDS) {
        $configSeeds = array_values(array_filter(array_map('trim', explode("\n", KEYWORD_SUGGEST_SEEDS))));
    }
    if (empty($configSeeds)) {
        $configSeeds = ['chicken', 'pasta', 'salad', 'soup', 'cake', 'cookies', 'dinner', 'breakfast', 'healthy', 'easy'];
    }

    // Modifier les seeds selon le trend type configuré
    $trendType = defined('PINTEREST_TRENDS_TYPE') ? PINTEREST_TRENDS_TYPE : 'growing';
    $modByType = ['growing' => 'trending', 'seasonal' => date('F'), 'top_monthly' => 'popular', 'top_yearly' => 'best'];
    $trendMod  = $modByType[$trendType] ?? 'easy';

    // Shuffle déterministe par date
    srand(abs(crc32(date('Y-m-d') . 'bing_trends')));
    shuffle($configSeeds);
    srand();

    $seeds   = array_slice($configSeeds, 0, 14);
    $rows    = [];
    $skipped = 0;

    foreach ($seeds as $seed) {
        if (count($rows) >= $count) break;

        // Alterner entre "{seed} {niche}" et "{trendMod} {seed} {niche}"
        $query = (count($rows) % 2 === 0)
            ? trim("$seed $niche")
            : trim("$trendMod $seed $niche");

        $url = 'https://api.bing.com/osjson.aspx?query=' . urlencode($query) . '&market=' . urlencode($market);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) {
            pkr_log("Bing Suggest: HTTP $code pour '$query' — skip");
            continue;
        }

        // Format OpenSearch: ["query", ["suggestion1", "suggestion2", ...]]
        $json = json_decode($resp, true);
        $suggestions = $json[1] ?? [];
        if (!is_array($suggestions)) continue;

        foreach ($suggestions as $suggestion) {
            if (count($rows) >= $count) break;

            $term = trim((string)$suggestion);
            if (strlen($term) < 5) continue;

            $title = pkr_formatTitle($term);
            if (strlen($title) < 10) continue;

            if (in_array(strtolower($title), $blacklist, true)) {
                $skipped++;
                continue;
            }

            $rows[] = [$term, $title];
        }
    }

    pkr_log("Bing Suggest (Pinterest-style): " . count($rows) . " collectées ($skipped ignorées) | market=$market | type=$trendType");
    return $rows;
}

/**
 * Source 'pinterest_import' — lit un CSV exporté manuellement depuis trends.pinterest.com.
 * Format attendu: Keyword,Search volume,Weekly change,Monthly change,Yearly change
 * Trie par Monthly change (croissance maximale en premier).
 * Expire après PINTEREST_TRENDS_IMPORT_MAX_DAYS jours → fallback automatique.
 */
function pkr_fetchFromImportedTrends($count, array $blacklist = []) {
    $importFile = defined('PINTEREST_TRENDS_IMPORT_PATH')
        ? PINTEREST_TRENDS_IMPORT_PATH
        : (__DIR__ . '/downloads/pinterest-trends-import.csv');
    $maxDays = defined('PINTEREST_TRENDS_IMPORT_MAX_DAYS') ? (int)PINTEREST_TRENDS_IMPORT_MAX_DAYS : 7;

    if (!file_exists($importFile)) {
        pkr_log("Pinterest Import: aucun fichier trouvé");
        return [];
    }

    $age = floor((time() - filemtime($importFile)) / 86400);
    if ($age > $maxDays) {
        pkr_log("Pinterest Import: fichier expiré ($age j > max $maxDays j) — fallback");
        return [];
    }
    pkr_log("Pinterest Import: lecture fichier ($age j, expire dans " . ($maxDays - $age) . " j)");

    $fh = fopen($importFile, 'r');
    if (!$fh) { pkr_log("Pinterest Import: impossible d'ouvrir le fichier"); return []; }

    // Le CSV Pinterest Trends exporte plusieurs lignes de métadonnées avant l'en-tête réel.
    // Format: Rank, Trend, Weekly change, Monthly change, Yearly change, [dates...]
    // On cherche la ligne qui contient "trend" ou "keyword" comme colonne.
    $header   = null;
    $keyCol   = 1;    // "Trend" est en position 1 par défaut (après "Rank")
    $monthCol = null;
    $rawItems = [];

    $headerRaw = null; // ligne d'en-tête originale (pour réécriture)

    while (($row = fgetcsv($fh)) !== false) {
        if ($header === null) {
            $normalized = array_map('strtolower', array_map('trim', $row));
            // Chercher la ligne d'en-tête réelle (contient "trend" ou "keyword")
            $trendPos   = array_search('trend',   $normalized);
            $keywordPos = array_search('keyword', $normalized);
            if ($trendPos !== false || $keywordPos !== false) {
                $header    = $normalized;
                $headerRaw = $row;
                $keyCol    = ($trendPos !== false) ? $trendPos : $keywordPos;
                $monthCol  = array_search('monthly change', $normalized);
            }
            // Sinon ignorer la ligne (métadonnées)
            continue;
        }
        $term = trim($row[$keyCol] ?? '');
        if (empty($term) || is_numeric($term)) continue; // ignorer lignes vides ou numériques (ranks)
        $growth = 0;
        if ($monthCol !== null && isset($row[$monthCol])) {
            $growth = (int)str_replace(['%', '+', ',', '-'], '', trim($row[$monthCol]));
            // Préserver le signe négatif
            if (strpos(trim($row[$monthCol] ?? ''), '-') === 0) $growth = -$growth;
        }
        $rawItems[] = ['term' => $term, 'growth' => $growth, 'row' => $row];
    }
    fclose($fh);

    if (empty($rawItems)) { pkr_log("Pinterest Import: fichier vide"); return []; }

    // Trier par croissance mensuelle descendante
    if ($monthCol !== null) {
        usort($rawItems, fn($a, $b) => $b['growth'] - $a['growth']);
    }

    // Shuffle déterministe par date (varier chaque jour sur le même import)
    srand(abs(crc32(date('Y-m-d') . 'import')));
    $top = array_slice($rawItems, 0, min(60, count($rawItems)));
    shuffle($top);
    srand();

    $rows     = [];
    $skipped  = 0;
    $consumed = []; // termes (lowercase) effectivement utilisés → à retirer du fichier

    foreach ($top as $item) {
        if (count($rows) >= $count) break;
        $title = pkr_formatTitle($item['term']);
        if (strlen($title) < 10) continue;
        if (in_array(strtolower($title), $blacklist, true)) { $skipped++; continue; }
        $rows[] = [$item['term'], $title];
        $consumed[strtolower($item['term'])] = true;
    }

    // ── Retirer les keywords consommés du fichier import (anti-répétition) ────
    if (!empty($consumed)) {
        pkr_removeUsedFromImport($importFile, $headerRaw, $rawItems, $keyCol, $consumed);
    }

    pkr_log("Pinterest Import: " . count($rows) . " collectées ($skipped ignorées) depuis " . count($rawItems) . " keywords importés");
    return $rows;
}

/**
 * Réécrit le CSV import en retirant les lignes des keywords consommés.
 * Conserve l'en-tête (Rank,Trend,...) + toutes les lignes non utilisées.
 * Si plus aucun keyword ne reste, supprime le fichier (→ fallback auto).
 */
function pkr_removeUsedFromImport(string $importFile, ?array $headerRaw, array $rawItems, int $keyCol, array $consumed): void {
    $remaining = array_values(array_filter(
        $rawItems,
        fn($it) => !isset($consumed[strtolower(trim($it['row'][$keyCol] ?? $it['term']))])
    ));

    // Plus rien à garder → supprimer le fichier pour déclencher le fallback
    if (empty($remaining)) {
        @unlink($importFile);
        pkr_log("Pinterest Import: tous les keywords consommés — fichier supprimé (fallback au prochain run)");
        return;
    }

    $tmp = $importFile . '.tmp';
    $fh  = fopen($tmp, 'w');
    if (!$fh) { pkr_log("Pinterest Import: impossible de réécrire le fichier (skip purge)"); return; }

    if ($headerRaw) fputcsv($fh, $headerRaw);
    foreach ($remaining as $it) {
        fputcsv($fh, $it['row']);
    }
    fclose($fh);

    if (@rename($tmp, $importFile)) {
        pkr_log("Pinterest Import: " . count($consumed) . " keywords retirés, " . count($remaining) . " restants dans le fichier");
    } else {
        @unlink($tmp);
        pkr_log("Pinterest Import: échec du remplacement du fichier (purge ignorée)");
    }
}

/**
 * Source 'auto' — cascade automatique : Pinterest Trends → Google Suggest → Prompt IA.
 * Écrit downloads/last-keyword-source.json pour le logging pipeline.
 */
function pkr_fetchSmartAuto($count, $season, $ingredients, array $blacklist = []) {
    $sourceUsed = 'prompt';
    $rows       = [];

    // 0. Priorité 1 : Import CSV Pinterest Trends (données réelles si disponible)
    $rows = pkr_fetchFromImportedTrends($count, $blacklist);
    if (count($rows) >= 5) {
        $sourceUsed = 'pinterest_import';
        pkr_log("Auto: Import Pinterest OK (" . count($rows) . " rows)");
    } else {
        pkr_log("Auto: Import Pinterest absent/expiré — essai Bing Trends");

        // 1. Priorité 2 : Bing Suggest (Pinterest-style)
        $rows = pkr_fetchFromPinterestTrends($count, $blacklist);
        if (count($rows) >= 5) {
            $sourceUsed = 'pinterest_trends';
            pkr_log("Auto: Bing Trends OK (" . count($rows) . " rows)");
        } else {
            pkr_log("Auto: Bing Trends insuffisant (" . count($rows) . " rows), fallback Google Suggest");

            // 2. Priorité 3 : Google Suggest
            $rows = pkr_fetchFromGoogleSuggest($count, $ingredients, $blacklist);
            if (count($rows) >= 5) {
                $sourceUsed = 'google_suggest';
                pkr_log("Auto: Google Suggest OK (" . count($rows) . " rows)");
            } else {
                pkr_log("Auto: Google Suggest insuffisant (" . count($rows) . " rows), fallback Prompt IA");

                // 3. Priorité 4 : Prompt IA
                $rows       = pkr_fetchFromPrompt($count, $season, $ingredients, $blacklist);
                $sourceUsed = 'prompt';
                pkr_log("Auto: Prompt IA utilisé (" . count($rows) . " rows)");
            }
        }
    }

    // Écrire le sidecar pour le pipeline logger
    $sidecarFile = __DIR__ . '/downloads/last-keyword-source.json';
    if (!is_dir(dirname($sidecarFile))) mkdir(dirname($sidecarFile), 0755, true);
    file_put_contents($sidecarFile, json_encode([
        'source' => $sourceUsed,
        'date'   => date('Y-m-d H:i:s'),
        'count'  => count($rows),
    ], JSON_PRETTY_PRINT));

    return $rows;
}

/**
 * Reformate une suggestion Google en titre de post lisible, sans IA.
 * "easy homemade chicken recipe baked" → "Baked Easy Homemade Chicken Recipe"
 * "creamy pasta recipe from scratch"   → "Creamy Pasta Recipe From Scratch"
 */
function pkr_formatTitle($suggestion) {
    $s = strtolower(trim($suggestion));

    // Modifieurs qui doivent aller au DÉBUT s'ils traînent après "recipe"
    $frontMods = [
        'easy', 'quick', 'simple', 'best', 'healthy', 'homemade', 'classic',
        'authentic', 'perfect', 'ultimate', 'amazing', 'delicious', 'creamy',
        'crispy', 'moist', 'crunchy', 'fluffy', 'rich', 'cheesy', 'spicy',
        'one pot', 'no bake', 'no cook', 'air fryer', 'instant pot',
        'slow cooker', 'sheet pan', '30 minute', '5 ingredient', 'meal prep',
        'keto', 'vegan', 'gluten free', 'dairy free', 'low carb',
    ];

    if (preg_match('/\brecipe\b/', $s)) {
        // "recipe" exact (pas "recipes") — split et reformater
        $parts  = preg_split('/\brecipe\b/', $s, 2);
        $before = trim($parts[0]);
        $after  = trim($parts[1] ?? '');

        // Si modifieur traîne APRÈS "recipe" → le déplacer au début
        $prefix = '';
        foreach ($frontMods as $mod) {
            if ($after === $mod || strpos($after, $mod . ' ') === 0) {
                $prefix = $mod;
                $after  = trim(substr($after, strlen($mod)));
                break;
            }
        }

        $title = $prefix
            ? ucwords("$prefix $before recipe" . ($after ? " $after" : ''))
            : ucwords("$before recipe" . ($after ? " $after" : ''));
    } else {
        $title = ucwords($s);
    }

    return trim(preg_replace('/\s+/', ' ', $title));
}

/**
 * Source 'prompt' — OpenAI (avec fallback Anthropic) génère le CSV complet.
 */
function pkr_fetchFromPrompt($count, $season, $ingredients, array $blacklist = []) {
    // ── Shuffle ingrédients (diversité jour/site) ────────────────────────────
    $daySeed = crc32(date('Y-m-d') . (defined('SITE_FOLDER') ? SITE_FOLDER : 'default'));
    srand($daySeed);
    shuffle($ingredients);
    srand();

    // ── Google Trends RSS ────────────────────────────────────────────────────
    pkr_log("Fetching Google Trends RSS...");
    $trends = [];
    $ch = curl_init('https://trends.google.com/trends/trendingsearches/daily/rss?geo=US');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $rss  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $rss) {
        preg_match_all('/<title><!\[CDATA\[(.*?)\]\]><\/title>|<title>(.*?)<\/title>/s', $rss, $m);
        $all    = array_filter(array_map(fn($a, $b) => trim($a ?: $b), $m[1], $m[2]));
        $trends = array_values(array_slice($all, 1, 15));
        pkr_log("Google Trends: " . count($trends) . " topics");
    } else {
        pkr_log("Google Trends indisponible (HTTP $code)");
    }

    // ── Construire le prompt ─────────────────────────────────────────────────
    $promptTpl = defined('PINTEREST_CSV_PROMPT') && PINTEREST_CSV_PROMPT
        ? PINTEREST_CSV_PROMPT
        : 'You are a data-driven SEO and Pinterest food trends expert.
Generate a CSV of trending recipe keywords and titles.
Context: month={MONTH}, season={SEASON}, ingredients={INGREDIENTS}, trends={TRENDS}
Output ONLY CSV:
"Keyword","Title"
Generate at least {COUNT} rows.';

    $prompt = str_replace(
        ['{MONTH}', '{SEASON}', '{INGREDIENTS}', '{TRENDS}', '{COUNT}'],
        [date('F'), $season, json_encode($ingredients, JSON_UNESCAPED_UNICODE),
         json_encode(array_values($trends), JSON_UNESCAPED_UNICODE), $count],
        $promptTpl
    );

    // ── Inject blacklist pour éviter les répétitions ─────────────────────────
    if (!empty($blacklist)) {
        $sample = array_slice($blacklist, -60); // max 60 exemples pour ne pas surcharger
        $prompt .= "\n\nIMPORTANT — Do NOT generate titles similar to these already-published ones:\n"
                 . implode("\n", array_map(fn($t) => "- $t", $sample));
    }

    // ── Appel OpenAI ─────────────────────────────────────────────────────────
    $useApi = defined('GENERATION_API') ? GENERATION_API : 'openai';
    $text   = '';

    if ($useApi !== 'anthropic') {
        pkr_log("Appel OpenAI pour générer $count keywords/titles...");
        $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
        $model  = defined('OPENAI_CONTENT_MODEL') ? OPENAI_CONTENT_MODEL : 'gpt-4o-mini';

        $ch2 = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch2, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => $model,
                'messages'    => [['role' => 'user', 'content' => $prompt]],
                'max_tokens'  => 2000,
                'temperature' => 0.85,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $resp     = curl_exec($ch2);
        $httpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch2);
        curl_close($ch2);

        if ($httpCode === 200) {
            $data = json_decode($resp, true);
            $text = trim($data['choices'][0]['message']['content'] ?? '');
        } else {
            $errDetail = $curlErr ? "cURL: $curlErr" : substr($resp ?? '', 0, 150);
            pkr_log("ERREUR OpenAI HTTP $httpCode — $errDetail — fallback Anthropic...");
            $useApi = 'anthropic';
        }
    }

    // ── Appel Anthropic (direct ou fallback) ──────────────────────────────────
    if ($useApi === 'anthropic' && empty($text)) {
        pkr_log("Appel Anthropic pour générer $count keywords/titles...");
        $antKey   = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
        $antModel = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-3-5-haiku-20241022';
        if ($antModel === 'claude-3-haiku-20240307') $antModel = 'claude-3-5-haiku-20241022';

        if (empty($antKey)) { pkr_log("ERREUR: ANTHROPIC_API_KEY non défini"); return []; }

        $ch3 = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch3, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'      => $antModel,
                'max_tokens' => 2000,
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $antKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $antResp = curl_exec($ch3);
        $antCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
        $antErr  = curl_error($ch3);
        curl_close($ch3);

        if ($antCode === 200) {
            $antData = json_decode($antResp, true);
            $text    = trim($antData['content'][0]['text'] ?? '');
        } else {
            $errDetail = $antErr ? "cURL: $antErr" : substr($antResp ?? '', 0, 150);
            pkr_log("ERREUR Anthropic HTTP $antCode — $errDetail");
            return [];
        }
    }

    if (empty($text)) { pkr_log("ERREUR: réponse API vide"); return []; }

    // ── Parser le CSV reçu ───────────────────────────────────────────────────
    $rows   = [];
    $header = true;
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '```') === 0) continue;

        $parsed  = str_getcsv($line);
        if (count($parsed) < 2) continue;
        $keyword = trim($parsed[0]);
        $title   = trim($parsed[1]);

        if ($header && strtolower($keyword) === 'keyword') { $header = false; continue; }
        $header = false;

        if ($keyword && $title) $rows[] = [$keyword, $title];
    }

    if (empty($rows)) { pkr_log("ERREUR: aucune ligne parsée depuis la réponse API"); return []; }

    return $rows;
}

// ── 3. Générer les keywords/titres ───────────────────────────────────────────
pkr_log("Count: $pkr_count titres (min=$pkr_limitMin / max=$pkr_limitMax)");

$pkr_rows = pkr_fetchKeywords($pkr_count, $pkr_season, $pkr_ingredients, $pkr_isCli);

if (empty($pkr_rows)) {
    pkr_log("ERREUR: aucun keyword/titre généré");
    return;
}

// Limiter strictement au count demandé
if (count($pkr_rows) > $pkr_count) {
    $pkr_rows = array_slice($pkr_rows, 0, $pkr_count);
}

pkr_log(count($pkr_rows) . " titres (limité à $pkr_count)");

// ── 7. Écrire le CSV dans KEYWORDS_PIN_DIR ──────────────────────────────────
$pkr_fh = fopen($pkr_todayFile, 'w');
if (!$pkr_fh) {
    pkr_log("ERREUR: impossible de créer $pkr_todayFile");
    return;
}

// Header
fputcsv($pkr_fh, ['Keyword', 'Title']);

foreach ($pkr_rows as $row) {
    fputcsv($pkr_fh, $row);
}
fclose($pkr_fh);

pkr_log("CSV sauvegardé: " . basename($pkr_todayFile) . " (" . count($pkr_rows) . " titres)");

// ── Sauvegarder les titres générés dans la blacklist (anti-répétition) ───────
pkr_saveToBlacklist($pkr_rows);

if ($pkr_isCli) {
    echo "\n  Titres générés:\n";
    foreach ($pkr_rows as $row) {
        echo "    [{$row[0]}] {$row[1]}\n";
    }
    echo "\n";
}
