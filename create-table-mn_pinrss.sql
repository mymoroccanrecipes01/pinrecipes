-- ============================================
-- Table: mn_pinrss
-- Description: Stockage des recettes pour publication automatique sur Pinterest via RSS
-- Date: 2025-12-28
-- ============================================

-- Créer la table si elle n'existe pas
CREATE TABLE IF NOT EXISTS `mn_pinrss` (
  `ID` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL COMMENT 'Titre de la recette/article',
  `description` text COMMENT 'Description complète avec hashtags',
  `link` varchar(500) NOT NULL COMMENT 'URL de la page de la recette',
  `image` varchar(500) DEFAULT NULL COMMENT 'URL de l\'image principale',
  `category` varchar(100) DEFAULT NULL COMMENT 'Nom de la catégorie (ex: Comfort Food)',
  `CategorySlug` varchar(100) DEFAULT NULL COMMENT 'Slug de la catégorie pour RSS (ex: comfort-food)',
  `IsPublish` tinyint(1) DEFAULT 0 COMMENT '0=brouillon, 1=publié',
  `IsDelete` tinyint(1) DEFAULT 0 COMMENT '0=actif, 1=supprimé',
  `CreateAt` datetime DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de création',
  PRIMARY KEY (`ID`),
  KEY `idx_category_slug` (`CategorySlug`),
  KEY `idx_is_delete` (`IsDelete`),
  KEY `idx_create_at` (`CreateAt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Table pour gestion Pinterest RSS';

-- ============================================
-- Données de test (optionnel)
-- ============================================

-- Décommentez les lignes ci-dessous pour insérer des données de test

/*
INSERT INTO `mn_pinrss` (`title`, `description`, `link`, `image`, `category`, `CategorySlug`, `IsPublish`, `IsDelete`) VALUES
('Delicious Chocolate Cake', 'Rich and moist chocolate cake recipe. Perfect for any celebration!\n#chocolate #cake #dessert #baking', 'http://example.com/recipes/chocolate-cake', 'http://example.com/images/chocolate-cake.jpg', 'Desserts', 'desserts', 0, 0),
('Quick Chicken Pasta', 'Easy 30-minute chicken pasta recipe for busy weeknights.\n#pasta #chicken #quickmeals #dinner', 'http://example.com/recipes/chicken-pasta', 'http://example.com/images/chicken-pasta.jpg', 'Quick Meals', 'quick-meals', 0, 0),
('Healthy Green Smoothie', 'Nutritious green smoothie packed with vitamins and minerals.\n#smoothie #healthy #breakfast #vegan', 'http://example.com/recipes/green-smoothie', 'http://example.com/images/green-smoothie.jpg', 'Healthy Recipes', 'healthy-recipes', 0, 0);
*/

-- ============================================
-- Vérifications
-- ============================================

-- Compter le nombre d'enregistrements
-- SELECT COUNT(*) as total_records FROM mn_pinrss WHERE IsDelete = 0;

-- Voir les catégories distinctes
-- SELECT DISTINCT CategorySlug, COUNT(*) as count
-- FROM mn_pinrss
-- WHERE IsDelete = 0
-- GROUP BY CategorySlug
-- ORDER BY count DESC;

-- Voir les 10 derniers items
-- SELECT ID, title, category, CategorySlug, CreateAt
-- FROM mn_pinrss
-- WHERE IsDelete = 0
-- ORDER BY CreateAt DESC
-- LIMIT 10;

-- ============================================
-- Maintenance
-- ============================================

-- Supprimer les items marqués comme supprimés (soft delete)
-- DELETE FROM mn_pinrss WHERE IsDelete = 1;

-- Marquer un item comme supprimé (au lieu de supprimer)
-- UPDATE mn_pinrss SET IsDelete = 1 WHERE ID = ?;

-- Publier un item
-- UPDATE mn_pinrss SET IsPublish = 1 WHERE ID = ?;

-- ============================================
-- Nettoyage (DANGER - Utiliser avec précaution)
-- ============================================

-- Supprimer tous les enregistrements (DANGER!)
-- TRUNCATE TABLE mn_pinrss;

-- Supprimer la table complètement (DANGER!)
-- DROP TABLE IF EXISTS mn_pinrss;
