<?php
/**
 * Index des outils Pinterest RSS
 * Point d'entrée principal pour la gestion Pinterest
 */
require_once __DIR__ . '/auth.php';
auth_check();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pinterest RSS Tools - Menu Principal</title>
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
        }

        .header {
            background: white;
            border-radius: 15px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 30px;
        }

        .header h1 {
            color: #E60023;
            font-size: 3em;
            margin-bottom: 10px;
        }

        .header p {
            color: #6c757d;
            font-size: 1.2em;
        }

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .tool-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            transition: transform 0.3s, box-shadow 0.3s;
            border-top: 5px solid #E60023;
        }

        .tool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.3);
        }

        .tool-card h2 {
            color: #E60023;
            font-size: 1.8em;
            margin-bottom: 15px;
        }

        .tool-card p {
            color: #6c757d;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .tool-card .icon {
            font-size: 3em;
            margin-bottom: 15px;
        }

        .tool-card .btn {
            display: inline-block;
            background: linear-gradient(135deg, #E60023 0%, #bd081c 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.3s;
        }

        .tool-card .btn:hover {
            transform: translateY(-2px);
        }

        .documentation-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .documentation-section h2 {
            color: #E60023;
            margin-bottom: 20px;
        }

        .documentation-section ul {
            list-style: none;
            padding-left: 0;
        }

        .documentation-section li {
            padding: 12px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #E60023;
        }

        .documentation-section a {
            color: #E60023;
            text-decoration: none;
            font-weight: 600;
        }

        .documentation-section a:hover {
            text-decoration: underline;
        }

        .stats-bar {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
        }

        .stat-item {
            text-align: center;
            flex: 1;
            min-width: 150px;
        }

        .stat-number {
            font-size: 2.5em;
            color: #E60023;
            font-weight: bold;
        }

        .stat-label {
            color: #6c757d;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📌 Pinterest RSS Tools</h1>
            <p>Système de gestion et publication automatique pour Pinterest</p>
        </div>

        <?php
        // Statistiques
        try {
            require_once 'config.php';
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $totalItems = $pdo->query("SELECT COUNT(*) FROM mn_pinrss WHERE IsDelete = 0")->fetchColumn();
            $totalCategories = $pdo->query("SELECT COUNT(DISTINCT CategorySlug) FROM mn_pinrss WHERE CategorySlug IS NOT NULL AND IsDelete = 0")->fetchColumn();
            $rssFiles = count(glob('./rss/pinterest-*.xml'));

            ?>
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-number"><?php echo $totalItems; ?></div>
                    <div class="stat-label">Items dans BDD</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $totalCategories; ?></div>
                    <div class="stat-label">Catégories</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?php echo $rssFiles; ?></div>
                    <div class="stat-label">Flux RSS générés</div>
                </div>
            </div>
            <?php
        } catch (Exception $e) {
            // Silencieux si erreur BDD
        }
        ?>

        <div class="tools-grid">
            <!-- Outil 1: Liste des posts -->
            <div class="tool-card">
                <div class="icon">📋</div>
                <h2>Liste des Posts</h2>
                <p>Interface principale pour gérer vos posts et insérer du contenu dans Pinterest RSS. Sélectionnez une post, choisissez une image et publiez!</p>
                <a href="posts-liste.php" class="btn">Ouvrir la liste</a>
            </div>

            <!-- Outil 2: Générateur RSS -->
            <div class="tool-card">
                <div class="icon">🚀</div>
                <h2>Générateur RSS</h2>
                <p>Générez manuellement les fichiers RSS par catégorie. Permet de regénérer tous les flux ou un flux spécifique.</p>
                <a href="generate-pinterest-rss.php" class="btn">Générer RSS</a>
            </div>

            <!-- Outil 3: Flux RSS -->
            <div class="tool-card">
                <div class="icon">📡</div>
                <h2>Flux RSS Disponibles</h2>
                <p>Consultez la liste de tous les flux RSS générés par catégorie. Copiez les URLs pour les utiliser avec IFTTT ou Zapier.</p>
                <a href="rss/" class="btn">Voir les flux</a>
            </div>

            <!-- Outil 4: Test Système -->
            <div class="tool-card">
                <div class="icon">🧪</div>
                <h2>Test du Système</h2>
                <p>Vérifiez que tout fonctionne correctement: connexion BDD, dossiers, permissions, fichiers RSS, etc.</p>
                <a href="test-rss-system.php" class="btn">Tester le système</a>
            </div>
        </div>

        <div class="documentation-section">
            <h2>📖 Documentation</h2>
            <ul>
                <li>
                    <strong>📘 Guide Complet</strong>
                    <br>
                    <a href="RSS-PINTEREST-GUIDE.md" target="_blank">RSS-PINTEREST-GUIDE.md</a>
                    <br>
                    <small>Documentation complète du système avec configuration Pinterest, IFTTT, et CRON</small>
                </li>
                <li>
                    <strong>✅ Guide d'Implémentation</strong>
                    <br>
                    <a href="IMPLEMENTATION-COMPLETE.md" target="_blank">IMPLEMENTATION-COMPLETE.md</a>
                    <br>
                    <small>Résumé de l'installation, fichiers créés, et prochaines étapes</small>
                </li>
                <li>
                    <strong>🗄️ Script SQL</strong>
                    <br>
                    <a href="create-table-mn_pinrss.sql" target="_blank">create-table-mn_pinrss.sql</a>
                    <br>
                    <small>Script pour créer la table mn_pinrss dans votre base de données</small>
                </li>
            </ul>
        </div>

        <div style="margin-top: 30px; background: white; border-radius: 15px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <h2 style="color: #E60023; margin-bottom: 20px;">🎯 Comment utiliser ce système</h2>
            <ol style="line-height: 2.5; padding-left: 25px; color: #495057;">
                <li><strong>Testez le système</strong> avec l'outil de test pour vérifier que tout fonctionne</li>
                <li><strong>Ajoutez des posts</strong> via la liste des posts en cliquant sur les images</li>
                <li><strong>Vérifiez les flux RSS</strong> générés automatiquement dans le dossier /rss/</li>
                <li><strong>Configurez IFTTT ou Zapier</strong> avec les URLs des flux RSS</li>
                <li><strong>Configurez un CRON</strong> (optionnel) pour régénérer automatiquement les RSS</li>
            </ol>
        </div>

        <div style="margin-top: 30px; background: rgba(255,255,255,0.9); border-radius: 15px; padding: 20px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
            <p style="color: #6c757d; margin-bottom: 15px;">
                <strong>💡 Astuce:</strong> Marquez cette page en favori pour accéder rapidement à tous les outils
            </p>
            <p style="color: #6c757d; font-size: 0.9em;">
                Système Pinterest RSS - Version 1.0 | Créé le <?php echo date('d/m/Y'); ?>
            </p>
        </div>
    </div>
</body>
</html>
