<?php
/**
 * Index des flux RSS Pinterest
 * Liste tous les fichiers RSS disponibles
 */

$rssFiles = glob('pinterest-*.xml');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pinterest RSS Feeds</title>
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
            max-width: 900px;
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

        .rss-list {
            list-style: none;
        }

        .rss-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #E60023;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s;
        }

        .rss-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(230, 0, 35, 0.2);
        }

        .rss-item h3 {
            color: #E60023;
            margin-bottom: 5px;
        }

        .rss-item p {
            color: #6c757d;
            font-size: 0.9em;
        }

        .rss-link {
            background: linear-gradient(135deg, #E60023 0%, #bd081c 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            transition: transform 0.3s;
            display: inline-block;
        }

        .rss-link:hover {
            transform: translateY(-2px);
        }

        .no-feeds {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .generate-btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            border-radius: 10px;
            text-decoration: none;
            margin-top: 20px;
            transition: transform 0.3s;
        }

        .generate-btn:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📌 Pinterest RSS Feeds</h1>
            <p>Flux RSS par catégorie pour publication automatique</p>
        </div>

        <div class="content">
            <?php if (empty($rssFiles)): ?>
                <div class="no-feeds">
                    <h2>📭 Aucun flux RSS disponible</h2>
                    <p>Générez vos premiers flux RSS pour commencer</p>
                    <a href="../generate-pinterest-rss.php" class="generate-btn">
                        🚀 Générer les flux RSS
                    </a>
                </div>
            <?php else: ?>
                <h2 style="margin-bottom: 20px;">📡 Flux RSS Disponibles (<?php echo count($rssFiles); ?>)</h2>
                <ul class="rss-list">
                    <?php foreach ($rssFiles as $file): ?>
                        <?php
                            // Extraire le nom de la catégorie depuis le nom du fichier
                            $categorySlug = str_replace(['pinterest-', '.xml'], '', $file);
                            $categoryName = ucwords(str_replace('-', ' ', $categorySlug));

                            // Obtenir la taille du fichier
                            $fileSize = filesize($file);
                            $fileSizeKB = round($fileSize / 1024, 2);

                            // Obtenir la date de modification
                            $lastModified = date('d/m/Y H:i', filemtime($file));
                        ?>
                        <li class="rss-item">
                            <div>
                                <h3><?php echo htmlspecialchars($categoryName); ?></h3>
                                <p>
                                    📄 <?php echo htmlspecialchars($file); ?>
                                    (<?php echo $fileSizeKB; ?> KB)
                                    | 🕐 Mis à jour: <?php echo $lastModified; ?>
                                </p>
                            </div>
                            <a href="<?php echo htmlspecialchars($file); ?>" class="rss-link" target="_blank">
                                📡 Voir le flux
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <div style="margin-top: 30px; text-align: center;">
                    <a href="../generate-pinterest-rss.php" class="generate-btn">
                        🔄 Régénérer tous les flux
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
