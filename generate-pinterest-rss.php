<?php
/**
 * Générateur de fichiers RSS Pinterest par catégorie
 *
 * Ce script génère des fichiers RSS séparés pour chaque catégorie
 * depuis la table mn_pinrss
 *
 * Usage:
 * - generate-pinterest-rss.php (génère tous les RSS)
 * - generate-pinterest-rss.php?category=comfort-food (génère un seul RSS)
 * - generate-pinterest-rss.php?format=json (retourne JSON au lieu de HTML)
 */

require_once 'config.php';

// Fonction pour générer RSS par catégorie depuis mn_pinrss
function generatePinterestRSSByCategory($pdo, $categorySlug = null) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = HOST_NAME;
    $siteUrl = $protocol . '://' . $host;

    // Si pas de catégorie spécifiée, générer pour toutes
    if ($categorySlug === null) {
        // Récupérer toutes les catégories distinctes
        $stmt = $pdo->query("SELECT DISTINCT CategorySlug FROM mn_pinrss WHERE CategorySlug IS NOT NULL AND CategorySlug != '' AND IsDelete = 0");
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $results = [];
        foreach ($categories as $cat) {
            $results[$cat] = generatePinterestRSSByCategory($pdo, $cat);
        }
        return $results;
    }

    // Récupérer les items de cette catégorie
    $stmt = $pdo->prepare("
        SELECT * FROM mn_pinrss
        WHERE CategorySlug = ? AND IsDelete = 0
        ORDER BY CreateAt DESC
        LIMIT 50
    ");
    $stmt->execute([$categorySlug]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        return ['success' => false, 'message' => 'Aucun item pour cette catégorie'];
    }

    // Construire le RSS
    $categoryName = $items[0]['category'] ?? $categorySlug;
    $currentDate = date('r');

    $rssXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $rssXml .= '<rss version="2.0"
     xmlns:content="http://purl.org/rss/1.0/modules/content/"
     xmlns:media="http://search.yahoo.com/mrss/"
     xmlns:dc="http://purl.org/dc/elements/1.1/"
     xmlns:atom="http://www.w3.org/2005/Atom">' . PHP_EOL;

    $rssXml .= '  <channel>' . PHP_EOL;
    $rssXml .= '    <title>' . htmlspecialchars($categoryName . ' - Pinterest Feed', ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
    $rssXml .= '    <link>' . htmlspecialchars($siteUrl, ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
    $rssXml .= '    <description>' . htmlspecialchars('Latest ' . $categoryName . ' posts and content', ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
    $rssXml .= '    <language>en-US</language>' . PHP_EOL;
    $rssXml .= '    <pubDate>' . $currentDate . '</pubDate>' . PHP_EOL;
    $rssXml .= '    <lastBuildDate>' . $currentDate . '</lastBuildDate>' . PHP_EOL;
    $rssXml .= '    <atom:link href="' . $siteUrl . '/rss/pinterest-' . $categorySlug . '.xml" rel="self" type="application/rss+xml" />' . PHP_EOL;

    foreach ($items as $item) {
        $pubDate = date('r', strtotime($item['CreateAt']));

        // Remplacer localhost par le domaine configuré dans les URLs
        // et enlever le chemin du projet local
        $itemLink = $item['link'];
        $itemImage = $item['image'];

        // Remplacer localhost par le domaine
        $itemLink = str_replace('localhost', HOST_NAME, $itemLink);
        $itemImage = str_replace('localhost', HOST_NAME, $itemImage);

        // Enlever le chemin du projet (ex: /SitePinterset/mollykitchendaily-main)
        // pour garder seulement /posts/... ou /images/...
        $itemLink = preg_replace('#^(https?://[^/]+)/.*?/(posts|categories|images)/#i', '$1/$2/', $itemLink);
        $itemImage = preg_replace('#^(https?://[^/]+)/.*?/(posts|categories|images)/#i', '$1/$2/', $itemImage);

        // Extraire les hashtags de la description
        $hashtags = [];
        if (!empty($item['description'])) {
            preg_match_all('/#(\w+)/u', $item['description'], $matches);
            if (!empty($matches[1])) {
                $hashtags = $matches[1];
            }
        }

        $rssXml .= '    <item>' . PHP_EOL;
        $rssXml .= '      <title>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</title>' . PHP_EOL;
        $rssXml .= '      <link>' . htmlspecialchars($itemLink, ENT_XML1, 'UTF-8') . '</link>' . PHP_EOL;
        $rssXml .= '      <description>' . htmlspecialchars($item['description'], ENT_XML1, 'UTF-8') . '</description>' . PHP_EOL;
        $rssXml .= '      <pubDate>' . $pubDate . '</pubDate>' . PHP_EOL;
        $rssXml .= '      <dc:date>' . date('c', strtotime($item['CreateAt'])) . '</dc:date>' . PHP_EOL;
        $rssXml .= '      <dc:creator>' . htmlspecialchars(SITE_MANAGER, ENT_XML1, 'UTF-8') . '</dc:creator>' . PHP_EOL;
        $rssXml .= '      <guid isPermaLink="true">' . htmlspecialchars($itemLink, ENT_XML1, 'UTF-8') . '</guid>' . PHP_EOL;
        $rssXml .= '      <category>' . htmlspecialchars($item['category'], ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;

        // Ajouter les hashtags comme catégories supplémentaires
        foreach ($hashtags as $tag) {
            $rssXml .= '      <category>' . htmlspecialchars($tag, ENT_XML1, 'UTF-8') . '</category>' . PHP_EOL;
        }

        if (!empty($item['image'])) {
            $rssXml .= '      <enclosure url="' . htmlspecialchars($itemImage, ENT_XML1, 'UTF-8') . '" type="image/webp" />' . PHP_EOL;
            $rssXml .= '      <media:content url="' . htmlspecialchars($itemImage, ENT_XML1, 'UTF-8') . '" medium="image" type="image/webp">' . PHP_EOL;
            $rssXml .= '        <media:title>' . htmlspecialchars($item['title'], ENT_XML1, 'UTF-8') . '</media:title>' . PHP_EOL;
            $rssXml .= '        <media:description>' . htmlspecialchars($item['description'], ENT_XML1, 'UTF-8') . '</media:description>' . PHP_EOL;
            $rssXml .= '      </media:content>' . PHP_EOL;
        }

        $rssXml .= '    </item>' . PHP_EOL;
    }

    $rssXml .= '  </channel>' . PHP_EOL;
    $rssXml .= '</rss>' . PHP_EOL;

    // Créer le dossier rss s'il n'existe pas
    $rssDir = './rss';
    if (!is_dir($rssDir)) {
        mkdir($rssDir, 0755, true);
    }

    // Sauvegarder le fichier RSS
    $rssPath = $rssDir . '/pinterest-' . $categorySlug . '.xml';

    // Supprimer l'ancien fichier s'il existe
    if (file_exists($rssPath)) {
        @unlink($rssPath);
    }

    $result = file_put_contents($rssPath, $rssXml);

    if ($result !== false) {
        @chmod($rssPath, 0644);
        return [
            'success' => true,
            'path' => $rssPath,
            'url' => $siteUrl . '/rss/pinterest-' . $categorySlug . '.xml',
            'itemsCount' => count($items),
            'category' => $categoryName,
            'categorySlug' => $categorySlug
        ];
    } else {
        return ['success' => false, 'message' => 'Erreur écriture fichier'];
    }
}

// Connexion à la base de données
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Vérifier si une catégorie spécifique est demandée
    $categorySlug = $_GET['category'] ?? null;
    $format = $_GET['format'] ?? 'html';

    // Générer le(s) RSS
    $results = generatePinterestRSSByCategory($pdo, $categorySlug);

    // Format de sortie
    if ($format === 'json') {
        header('Content-Type: application/json');
        echo json_encode($results, JSON_PRETTY_PRINT);
        exit;
    }

    // Format HTML
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Pinterest RSS Generator</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, #E60023 0%, #bd081c 100%);
                padding: 20px;
                min-height: 100vh;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                overflow: hidden;
            }

            .header {
                background: linear-gradient(135deg, #E60023 0%, #bd081c 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }

            .header h1 {
                font-size: 2.5em;
                margin-bottom: 10px;
            }

            .content {
                padding: 30px;
            }

            .success-card {
                background: #d4edda;
                border: 2px solid #c3e6cb;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 20px;
            }

            .success-card h3 {
                color: #155724;
                margin-bottom: 15px;
            }

            .rss-item {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 10px;
                border-left: 4px solid #E60023;
            }

            .rss-item h4 {
                color: #E60023;
                margin-bottom: 10px;
            }

            .rss-item p {
                margin: 5px 0;
                color: #495057;
            }

            .rss-link {
                display: inline-block;
                background: linear-gradient(135deg, #E60023 0%, #bd081c 100%);
                color: white;
                padding: 10px 20px;
                border-radius: 5px;
                text-decoration: none;
                margin-top: 10px;
                transition: transform 0.3s;
            }

            .rss-link:hover {
                transform: translateY(-2px);
            }

            .error-card {
                background: #f8d7da;
                border: 2px solid #f5c6cb;
                border-radius: 10px;
                padding: 20px;
                margin-bottom: 20px;
                color: #721c24;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>📌 Pinterest RSS Generator</h1>
                <p>Fichiers RSS générés automatiquement par catégorie</p>
            </div>

            <div class="content">
                <?php if (is_array($results)): ?>
                    <?php if (isset($results['success'])): ?>
                        <!-- Un seul résultat -->
                        <?php if ($results['success']): ?>
                            <div class="success-card">
                                <h3>✅ RSS généré avec succès!</h3>
                                <div class="rss-item">
                                    <h4><?php echo htmlspecialchars($results['category']); ?></h4>
                                    <p><strong>Slug:</strong> <?php echo htmlspecialchars($results['categorySlug']); ?></p>
                                    <p><strong>Fichier:</strong> <?php echo htmlspecialchars($results['path']); ?></p>
                                    <p><strong>Nombre d'items:</strong> <?php echo $results['itemsCount']; ?></p>
                                    <a href="<?php echo htmlspecialchars($results['url']); ?>" target="_blank" class="rss-link">
                                        📡 Voir le flux RSS
                                    </a>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="error-card">
                                <h3>❌ Erreur</h3>
                                <p><?php echo htmlspecialchars($results['message']); ?></p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Plusieurs résultats -->
                        <div class="success-card">
                            <h3>✅ <?php echo count($results); ?> fichiers RSS générés avec succès!</h3>
                            <?php foreach ($results as $slug => $result): ?>
                                <?php if ($result['success']): ?>
                                    <div class="rss-item">
                                        <h4><?php echo htmlspecialchars($result['category']); ?></h4>
                                        <p><strong>Slug:</strong> <?php echo htmlspecialchars($result['categorySlug']); ?></p>
                                        <p><strong>Fichier:</strong> <?php echo htmlspecialchars($result['path']); ?></p>
                                        <p><strong>Nombre d'items:</strong> <?php echo $result['itemsCount']; ?></p>
                                        <a href="<?php echo htmlspecialchars($result['url']); ?>" target="_blank" class="rss-link">
                                            📡 Voir le flux RSS
                                        </a>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div style="margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 10px;">
                    <h3>📖 Comment utiliser ces flux RSS avec Pinterest</h3>
                    <ol style="margin-top: 15px; line-height: 2;">
                        <li>Allez sur <a href="https://www.pinterest.com" target="_blank" style="color: #E60023;">Pinterest.com</a></li>
                        <li>Créez un tableau (Board) pour chaque catégorie</li>
                        <li>Dans les paramètres du tableau, ajoutez le flux RSS correspondant</li>
                        <li>Pinterest publiera automatiquement les nouveaux items</li>
                    </ol>
                    <p style="margin-top: 15px;"><strong>URLs des flux RSS générés:</strong></p>
                    <?php if (isset($results['url'])): ?>
                        <code style="background: #fff; padding: 10px; display: block; margin-top: 10px; border-radius: 5px;">
                            <?php echo htmlspecialchars($results['url']); ?>
                        </code>
                    <?php else: ?>
                        <?php foreach ($results as $result): ?>
                            <?php if ($result['success']): ?>
                                <code style="background: #fff; padding: 10px; display: block; margin-top: 10px; border-radius: 5px;">
                                    <?php echo htmlspecialchars($result['url']); ?>
                                </code>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php

} catch (PDOException $e) {
    if (($_GET['format'] ?? 'html') === 'json') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erreur base de données: ' . $e->getMessage()]);
    } else {
        echo '<h1>Erreur de connexion à la base de données</h1>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    }
}
