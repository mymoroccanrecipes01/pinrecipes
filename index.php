<?php
require_once __DIR__ . '/auth.php';
auth_check();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post Manager - Home</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            width: 100%;
            background: white;
            border-radius: 20px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 50px 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 3em;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            font-size: 1.2em;
            opacity: 0.95;
        }
        
        .content {
            padding: 50px 30px;
        }
        
        .welcome-text {
            text-align: center;
            color: #666;
            font-size: 1.1em;
            margin-bottom: 40px;
            line-height: 1.6;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }
        
        .action-card {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 15px;
            padding: 40px 30px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 0;
        }
        
        .action-card:hover::before {
            opacity: 0.9;
        }
        
        .action-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(102, 126, 234, 0.4);
        }
        
        .action-card:hover .card-content {
            color: white;
        }
        
        .card-content {
            position: relative;
            z-index: 1;
            transition: color 0.3s ease;
        }
        
        .action-icon {
            font-size: 4em;
            margin-bottom: 20px;
            display: block;
        }
        
        .action-title {
            font-size: 1.8em;
            font-weight: 700;
            margin-bottom: 15px;
            color: #333;
        }
        
        .action-card:hover .action-title {
            color: white;
        }
        
        .action-description {
            font-size: 1em;
            color: #666;
            line-height: 1.5;
        }
        
        .action-card:hover .action-description {
            color: rgba(255, 255, 255, 0.9);
        }
        
        .action-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .stats-bar {
            background: #f9f9f9;
            padding: 30px;
            border-radius: 15px;
            margin-top: 40px;
            display: flex;
            justify-content: space-around;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            color: #666;
            margin-top: 5px;
            font-size: 0.95em;
        }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 0.9em;
        }
        
        @media (max-width: 768px) {
            .header h1 {
                font-size: 2em;
            }
            
            .actions-grid {
                grid-template-columns: 1fr;
            }
            
            .stats-bar {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <iframe style="width: 100%; height: 250px;" src="push.php" frameborder="0"></iframe>
        <div class="header">
            <h1>🍳 Post Manager</h1>
            <p>Votre assistant de gestion de posts</p>
        </div>
        
        <div class="content">
            <div class="welcome-text">
                <strong>Bienvenue dans votre gestionnaire de posts!</strong><br>
                Créez, gérez et organisez vos posts facilement
            </div>
            
            <div class="actions-grid">
                <a href="category_manager.php" class="action-link">
                    <div class="action-card">
                        <div class="card-content">
                            <span class="action-icon">➕</span>
                            <div class="action-title">créer catégorie</div>
                        </div>
                    </div>
                </a>
                <a href="posts-table.php" class="action-link">
                    <div class="action-card">
                        <div class="card-content">
                            <span class="action-icon">➕</span>
                            <div class="action-title">generater Plusieurs posts</div>
                        </div>
                    </div>
                </a>
                <a href="posts-liste.php" class="action-link">
                    <div class="action-card">
                        <div class="card-content">
                            <span class="action-icon">📋</span>
                            <div class="action-title">Liste des Posts</div>
                            <div class="action-description">
                                Consultez, modifiez et gérez toutes vos posts existantes
                            </div>
                        </div>
                    </div>
                </a>
                
                <a href="posts-client.php" class="action-link">
                    <div class="action-card">
                        <div class="card-content">
                            <span class="action-icon">✨</span>
                            <div class="action-title">Générer une Post</div>
                            <div class="action-description">
                                Créez une nouvelle post et ajoutez-la à votre collection
                            </div>
                        </div>
                    </div>
                </a>
            </div>            
        
        <div class="footer">
            Post Manager © <?php echo date('Y'); ?> - Gestion simplifiée de vos posts
        </div>
    </div>
</body>
</html>