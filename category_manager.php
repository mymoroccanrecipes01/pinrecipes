<?php
require_once __DIR__ . '/auth.php';
auth_check();

// Traiter l'action si c'est un GET
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'generate_index') {
    $categoriesDir = './categories';
    
    // Créer le dossier s'il n'existe pas
    if (!is_dir($categoriesDir)) {
        mkdir($categoriesDir, 0755, true);
    }
    
    // Scanner le dossier categories
$validFolders = []; // Changer en array associatif
$handle = opendir($categoriesDir);
if ($handle) {
    while (($item = readdir($handle)) !== false) {
        if ($item === '.' || $item === '..') continue;
        
        $itemPath = $categoriesDir . '/' . $item;
        if (is_dir($itemPath) && file_exists($itemPath . '/category.json')) {
            // Lire le fichier category.json pour récupérer l'ID
            $categoryJsonPath = $itemPath . '/category.json';
            $categoryData = json_decode(file_get_contents($categoryJsonPath), true);
            
            if ($categoryData && isset($categoryData['id'])) {
                $validFolders[$item] = $categoryData['id'];
            } else {
                // Si pas d'ID trouvé, utiliser le nom du dossier comme fallback
                $validFolders[$item] = $item;
            }
        }
    }
    closedir($handle);
}

// Tri alphabétique par clé (nom de dossier)
ksort($validFolders);

// Créer index.json avec la nouvelle structure
$indexData = [
    'generated' => date('Y-m-d H:i:s'),
    'count' => count($validFolders),
    'folders' => $validFolders
];
    
    $jsonContent = json_encode($indexData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $indexPath = $categoriesDir . '/index.json';
    
    // Écrire le fichier
    if (file_put_contents($indexPath, $jsonContent) !== false) {
         echo "index.json créé avec succès - " . count($validFolders) . " dossiers trouvés";

    } else {       
            echo "Erreur lors de la création d'index.json";    
    }
    
}



// Suppression complète du système de sessions - tout est géré par les fichiers
$categories = [];
$categoriesDir = './categories';

// Fonction pour charger toutes les catégories depuis les dossiers
function loadCategoriesFromFolders() {
    global $categoriesDir;
    $categories = [];
    
    if (!is_dir($categoriesDir)) {
        mkdir($categoriesDir, 0755, true);
        return $categories;
    }
    
    $categoryFolders = array_filter(glob($categoriesDir . '/*'), 'is_dir');
    
    foreach ($categoryFolders as $folder) {
        $jsonFile = $folder . '/category.json';
        if (file_exists($jsonFile)) {
            $categoryData = json_decode(file_get_contents($jsonFile), true);
            if ($categoryData && isset($categoryData['id'])) {
                // Ajouter le chemin du dossier et vérifier l'image
                $categoryData['folderPath'] = $folder;
                $categoryData['hasFolder'] = true;
                
                // Vérifier si l'image WebP existe
                $webpPath = $folder . '/image.webp';
                if (file_exists($webpPath)) {
                    $categoryData['image'] = 'image.webp';
                    $categoryData['image_path'] = $webpPath;
                }
                
                $categories[] = $categoryData;
            }
        }
    }
    
    // Trier par date de création (plus récent en premier)
    usort($categories, function($a, $b) {
        $dateA = strtotime($a['createdAt'] ?? '1970-01-01');
        $dateB = strtotime($b['createdAt'] ?? '1970-01-01');
        return $dateB - $dateA;
    });
    
    return $categories;
}

// Charger les catégories existantes
$categories = loadCategoriesFromFolders();

$message = '';
$messageType = '';

// Fonction pour créer un slug
function createSlug($name) {
    $slug = strtolower($name);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

// Fonction pour vérifier si un slug existe déjà
function slugExists($slug, $excludeId = null) {
    global $categoriesDir;
    
    $folderPath = $categoriesDir . '/' . $slug;
    if (file_exists($folderPath)) {
        $jsonFile = $folderPath . '/category.json';
        if (file_exists($jsonFile)) {
            $data = json_decode(file_get_contents($jsonFile), true);
            if ($data && isset($data['id']) && $data['id'] !== $excludeId) {
                return true;
            }
        } else {
            // Dossier existe mais pas de JSON (dossier orphelin)
            return true;
        }
    }
    return false;
}

// Fonction pour générer un slug unique
function generateUniqueSlug($name, $excludeId = null) {
    $baseSlug = createSlug($name);
    $slug = $baseSlug;
    $counter = 1;
    
    while (slugExists($slug, $excludeId)) {
        $slug = $baseSlug . '-' . $counter;
        $counter++;
        
        if ($counter > 100) {
            $slug = $baseSlug . '-' . time();
            break;
        }
    }
    
    return $slug;
}

// Fonction pour vérifier les conflits de noms
function checkNameConflicts($name, $excludeId = null) {
    global $categoriesDir;
    
    $conflicts = [];
    $nameToCheck = strtolower(trim($name));
    $slugToCheck = createSlug($name);
    
    if (!is_dir($categoriesDir)) {
        return $conflicts;
    }
    
    $categoryFolders = array_filter(glob($categoriesDir . '/*'), 'is_dir');
    
    foreach ($categoryFolders as $folder) {
        $jsonFile = $folder . '/category.json';
        if (file_exists($jsonFile)) {
            $categoryData = json_decode(file_get_contents($jsonFile), true);
            if ($categoryData && isset($categoryData['id']) && $categoryData['id'] !== $excludeId) {
                // Vérifier conflit de nom
                if (strtolower(trim($categoryData['name'])) === $nameToCheck) {
                    $conflicts[] = [
                        'type' => 'name',
                        'category' => $categoryData,
                        'message' => "Une catégorie '{$categoryData['name']}' existe déjà (ID: {$categoryData['id']})"
                    ];
                }
                
                // Vérifier conflit de slug
                if ($categoryData['slug'] === $slugToCheck) {
                    $conflicts[] = [
                        'type' => 'slug',
                        'category' => $categoryData,
                        'message' => "Le slug '{$slugToCheck}' est déjà utilisé par '{$categoryData['name']}'"
                    ];
                }
            }
        }
    }
    
    return $conflicts;
}

// Fonction pour télécharger et convertir une image depuis URL vers WebP
function downloadAndConvertToWebP($imageUrl, $destinationPath, $quality = 80) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
            ],
            'timeout' => 30,
            'follow_location' => true,
            'max_redirects' => 5
        ]
    ]);
    
    $imageData = @file_get_contents($imageUrl, false, $context);
    
    if ($imageData === false || strlen($imageData) === 0) {
        return ['success' => false, 'error' => 'Impossible de télécharger l\'image'];
    }
    
    $tempFile = sys_get_temp_dir() . '/temp_image_' . uniqid() . '.tmp';
    file_put_contents($tempFile, $imageData);
    
    $imageInfo = @getimagesize($tempFile);
    if ($imageInfo === false) {
        unlink($tempFile);
        return ['success' => false, 'error' => 'Format d\'image non valide'];
    }
    
    $originalSize = strlen($imageData);
    
    switch ($imageInfo[2]) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($tempFile);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($tempFile);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($tempFile);
            break;
        case IMAGETYPE_WEBP:
            copy($tempFile, $destinationPath);
            unlink($tempFile);
            return [
                'success' => true,
                'originalSize' => $originalSize,
                'webpSize' => filesize($destinationPath),
                'format' => 'WebP',
                'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
                'compression' => 0
            ];
        default:
            unlink($tempFile);
            return ['success' => false, 'error' => 'Format non supporté'];
    }
    
    if ($image === false) {
        unlink($tempFile);
        return ['success' => false, 'error' => 'Impossible de traiter l\'image'];
    }
    
    $success = imagewebp($image, $destinationPath, $quality);
    imagedestroy($image);
    unlink($tempFile);
    
    if ($success) {
        $webpSize = filesize($destinationPath);
        return [
            'success' => true,
            'originalSize' => $originalSize,
            'webpSize' => $webpSize,
            'format' => image_type_to_mime_type($imageInfo[2]),
            'dimensions' => $imageInfo[0] . 'x' . $imageInfo[1],
            'compression' => round((1 - $webpSize / $originalSize) * 100, 1)
        ];
    }
    
    return ['success' => false, 'error' => 'Échec de la conversion en WebP'];
}

// Fonction de conversion WebP
function convertToWebP($sourcePath, $destinationPath, $quality = 80) {
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) return false;
    
    switch ($imageInfo[2]) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($sourcePath);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($sourcePath);
            break;
        case IMAGETYPE_WEBP:
            copy($sourcePath, $destinationPath);
            return true;
        default:
            return false;
    }
    
    if (!$image) return false;
    
    $result = imagewebp($image, $destinationPath, $quality);
    imagedestroy($image);
    
    return $result;
}

// Fonction pour sauvegarder une catégorie dans son dossier avec upload intégré
function saveCategoryToFolder($category, $imageUrl = null, $uploadedFile = null) {
    global $categoriesDir;
    
    $categoryPath = $categoriesDir . '/' . $category['slug'];
    
    // Créer le dossier s'il n'existe pas
    if (!file_exists($categoryPath)) {
        if (!mkdir($categoryPath, 0755, true)) {
            return ['success' => false, 'message' => 'Impossible de créer le dossier'];
        }
    }
    
    // Préparer les données de la catégorie
    $categoryData = array_merge($category, [
        'folderPath' => $categoryPath,
        'updatedAt' => date('c')
    ]);
    
    // Créer createdAt si n'existe pas
    if (!isset($categoryData['createdAt'])) {
        $categoryData['createdAt'] = date('c');
    }
    
    $imageInfo = '';
    
    // Traitement de l'upload de fichier en priorité
    if ($uploadedFile && isset($uploadedFile['error']) && $uploadedFile['error'] === UPLOAD_ERR_OK) {
        $webpPath = $categoryPath . '/image.webp';
        
        if (convertToWebP($uploadedFile['tmp_name'], $webpPath)) {
            $imageInfo = "Image uploadée et convertie avec succès";
            $categoryData['image'] = 'image.webp';
        } else {
            $imageInfo = "Erreur lors de la conversion du fichier uploadé";
        }
    }
    // Sinon traitement de l'URL si fournie
    elseif (!empty($imageUrl)) {
        $webpPath = $categoryPath . '/image.webp';
        
        if (file_exists($webpPath)) {
            $imageInfo = "Image WebP existante réutilisée";
            $categoryData['image'] = 'image.webp';
        } else {
            $result = downloadAndConvertToWebP($imageUrl, $webpPath);
            
            if ($result['success']) {
                $imageInfo = "Image téléchargée et convertie avec succès";
                $categoryData['image'] = 'image.webp';
            } else {
                $imageInfo = "Erreur lors du téléchargement/conversion: " . $result['error'];
            }
        }
    }
    
    // Sauvegarder les infos sur l'image si traitée
    if (!empty($imageInfo)) {
        $imageLogContent = "Date: " . date('Y-m-d H:i:s') . "\n";
        if (!empty($imageUrl)) {
            $imageLogContent .= "URL originale: $imageUrl\n";
        }
        $imageLogContent .= "Résultat: $imageInfo\n";
        file_put_contents($categoryPath . '/image_info.txt', $imageLogContent);
    }
    
    // Sauvegarder le JSON
    $jsonContent = json_encode($categoryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if (!file_put_contents($categoryPath . '/category.json', $jsonContent)) {
        return ['success' => false, 'message' => 'Impossible de sauvegarder les données'];
    }
    
    return [
        'success' => true, 
        'message' => "Catégorie sauvegardée avec succès",
        'path' => $categoryPath,
        'imageInfo' => $imageInfo
    ];
}

// Fonction pour supprimer complètement une catégorie
function deleteCategoryCompletely($categoryId) {
    global $categoriesDir, $categories;
    
    // Trouver la catégorie
    $categoryToDelete = null;
    foreach ($categories as $cat) {
        if ($cat['id'] === $categoryId) {
            $categoryToDelete = $cat;
            break;
        }
    }
    
    if (!$categoryToDelete) {
        return ['success' => false, 'message' => 'Catégorie non trouvée'];
    }
    
    $categoryPath = $categoriesDir . '/' . $categoryToDelete['slug'];
    
    if (file_exists($categoryPath)) {
        // Fonction récursive pour supprimer un dossier et son contenu
        function deleteDirectory($dir) {
            if (!is_dir($dir)) return false;
            
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $filePath = $dir . DIRECTORY_SEPARATOR . $file;
                is_dir($filePath) ? deleteDirectory($filePath) : unlink($filePath);
            }
            
            return rmdir($dir);
        }
        
        if (deleteDirectory($categoryPath)) {
            return [
                'success' => true, 
                'message' => "Catégorie '{$categoryToDelete['name']}' et son dossier supprimés complètement"
            ];
        } else {
            return ['success' => false, 'message' => 'Erreur lors de la suppression du dossier'];
        }
    }
    
    return ['success' => true, 'message' => "Catégorie supprimée (pas de dossier à supprimer)"];
}


// Traitement des actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_category':
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $imageUrl = trim($_POST['image_url'] ?? '');
            $uploadedFile = $_FILES['uploaded_image'] ?? null;
            $isHome = isset($_POST['Is_home']) ? 1 : 0;

            if (empty($name)) {
                $message = "Le nom de la catégorie est obligatoire.";
                $messageType = 'error';
                break;
            }
            
            $conflicts = checkNameConflicts($name);
            
            if (!empty($conflicts)) {
                $conflictMessages = ["CRÉATION REFUSÉE - Conflits détectés:"];
                foreach ($conflicts as $index => $conflict) {
                    $conflictMessages[] = ($index + 1) . ". " . $conflict['message'];
                }
                $conflictMessages[] = "";
                $conflictMessages[] = "Utilisez un nom complètement différent.";
                $message = implode("\n", $conflictMessages);
                $messageType = 'error';
                break;
            }
            
            // Générer l'ID et le SLUG
            $slug = generateUniqueSlug($name);
            $id = 'cat_' . time() . '_' . rand(100, 999);
            
            $newCategory = [
                'id' => $id,
                'slug' => $slug,
                'name' => $name,
                'image' => '',
                'image_url' => $imageUrl,
                'description' => $description,
                'Is_home' => $isHome
            ];
            
            $result = saveCategoryToFolder($newCategory, $imageUrl, $uploadedFile);
            
            if ($result['success']) {
                $baseSlug = createSlug($name);
                $slugInfo = ($slug !== $baseSlug) ? " (slug ajusté: '$slug')" : "";
                $message = "Catégorie '$name' créée avec succès$slugInfo !";
                $messageType = 'success';
                
                // Recharger les catégories
                $categories = loadCategoriesFromFolders();               
            } else {
                $message = "Erreur lors de la création: {$result['message']}";
                $messageType = 'error';
            }
            break;
            
        case 'update_category':
            $id = $_POST['id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $imageUrl = trim($_POST['image_url'] ?? '');
            $uploadedFile = $_FILES['uploaded_image'] ?? null;
            $isHome = isset($_POST['Is_home']) ? 1 : 0;

            if (empty($name) || empty($id)) {
                $message = "Données invalides pour la mise à jour.";
                $messageType = 'error';
                break;
            }
            
            // Trouver la catégorie existante
            $existingCategory = null;
            foreach ($categories as $cat) {
                if ($cat['id'] === $id) {
                    $existingCategory = $cat;
                    break;
                }
            }
            
            if (!$existingCategory) {
                $message = "Catégorie non trouvée.";
                $messageType = 'error';
                break;
            }
            
            $conflicts = checkNameConflicts($name, $id);
            
            if (!empty($conflicts)) {
                $conflictMessages = ["MODIFICATION REFUSÉE - Conflits détectés:"];
                foreach ($conflicts as $index => $conflict) {
                    $conflictMessages[] = ($index + 1) . ". " . $conflict['message'];
                }
                $message = implode("\n", $conflictMessages);
                $messageType = 'error';
                break;
            }
            
            $newSlug = generateUniqueSlug($name, $id);
            $oldSlug = $existingCategory['slug'];
            $oldPath = $categoriesDir . '/' . $oldSlug;
            $newPath = $categoriesDir . '/' . $newSlug;
            
            // Préparer la catégorie mise à jour
            $updatedCategory = [
                'id' => $id,
                'slug' => $newSlug,
                'name' => $name,
                'image' => !empty($imageUrl) ? 'image.webp' : ($existingCategory['image'] ?? ''),
                'image_url' => $imageUrl,
                'description' => $description,
                'createdAt' => $existingCategory['createdAt'] ?? date('c'),
                'Is_home' => $isHome
            ];
            
            // Si le slug a changé, renommer le dossier
            if ($oldSlug !== $newSlug && file_exists($oldPath)) {
                if (!file_exists($newPath)) {
                    if (rename($oldPath, $newPath)) {
                        $message = "Catégorie '$name' mise à jour ! Dossier renommé de '$oldSlug' vers '$newSlug'";
                    } else {
                        $message = "Erreur lors du renommage du dossier.";
                        $messageType = 'error';
                        break;
                    }
                } else {
                    $message = "Impossible de renommer: le dossier '$newSlug' existe déjà.";
                    $messageType = 'error';
                    break;
                }
            }
            
            // Sauvegarder la catégorie mise à jour
            $result = saveCategoryToFolder($updatedCategory, $imageUrl, $uploadedFile);
            
            if ($result['success']) {
                if (empty($message)) {
                    $message = "Catégorie '$name' mise à jour avec succès !";
                }
                $messageType = 'success';
                
                // Recharger les catégories
                $categories = loadCategoriesFromFolders();
            } else {
                $message = "Erreur lors de la mise à jour: {$result['message']}";
                $messageType = 'error';
            }
            break;
            
        case 'delete_category':
            $id = $_POST['id'] ?? '';
            
            if (empty($id)) {
                $message = "ID de catégorie manquant.";
                $messageType = 'error';
                break;
            }
            
            $result = deleteCategoryCompletely($id);
            
            if ($result['success']) {
                $message = $result['message'];
                $messageType = 'success';
                
                // Recharger les catégories
                $categories = loadCategoriesFromFolders();
            } else {
                $message = "Erreur lors de la suppression: {$result['message']}";
                $messageType = 'error';
            }
            break;
            
        case 'create_all_missing':
            $createdCount = 0;
            $errorCount = 0;
            $results = [];
            
            foreach ($categories as $category) {
                if (!$category['hasFolder']) {
                    $result = saveCategoryToFolder($category, $category['image_url'] ?? '');
                    
                    if ($result['success']) {
                        $createdCount++;
                        $results[] = "Créé: {$category['name']}";
                    } else {
                        $errorCount++;
                        $results[] = "Erreur: {$category['name']} - {$result['message']}";
                    }
                }
            }
            
            $message = "Traitement terminé !\n";
            $message .= "Dossiers créés: $createdCount\n";
            if ($errorCount > 0) {
                $message .= "Erreurs: $errorCount\n";
            }
            $message .= "\nDétails:\n" . implode("\n", $results);
            
            $messageType = ($errorCount > 0) ? 'error' : 'success';
            
            // Recharger les catégories
            $categories = loadCategoriesFromFolders();
            break;
    }
}

// Fonction pour vérifier si un dossier existe
function folderExists($slug) {
    global $categoriesDir;
    return file_exists($categoriesDir . '/' . $slug);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Manager - Sans Session</title>
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
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            padding: 30px;
            text-align: center;
            position: relative;
        }
        .header-user {
            position: absolute; top: 12px; right: 15px;
            display: flex; align-items: center; gap: 8px;
        }
        .header-user .role-badge {
            font-size: .78rem; background: rgba(255,255,255,.15); color: #fff;
            padding: 3px 10px; border-radius: 20px; border: 1px solid rgba(255,255,255,.3);
        }
        .header-user a { font-size: .78rem; color: #e0f2fe; text-decoration: none; }
        .header-user a:hover { color: #fff; }

        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .main-content {
            padding: 30px;
        }

        .stats-bar {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-around;
            text-align: center;
        }

        .stat-item {
            flex: 1;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }

        .stat-label {
            color: #666;
            margin-top: 5px;
        }

        .controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .btn-small {
            padding: 8px 16px;
            font-size: 12px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 600;
            white-space: pre-line;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .category-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            position: relative;
        }

        .category-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }

        .category-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            overflow: hidden;
            position: relative;
        }

        .category-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .webp-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: #00ff00;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .folder-status {
            position: absolute;
            top: 10px;
            left: 10px;
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .folder-exists {
            background: #28a745;
            color: white;
        }

        .folder-missing {
            background: #ffc107;
            color: #333;
        }

        .category-content {
            padding: 25px;
        }

        .category-name {
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
        }

        .category-slug {
            font-size: 0.9rem;
            color: #666;
            background: #f8f9fa;
            padding: 4px 8px;
            border-radius: 15px;
            display: inline-block;
            margin-bottom: 12px;
            font-family: 'Courier New', monospace;
        }

        .category-id {
            font-size: 0.8rem;
            color: #999;
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 10px;
            display: inline-block;
            margin-bottom: 12px;
            font-family: 'Courier New', monospace;
        }

        .category-description {
            color: #666;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .category-meta {
            font-size: 0.8rem;
            color: #999;
            margin-bottom: 15px;
        }

        .category-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #666;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: #667eea;
        }

        .upload-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            border: 2px dashed #dee2e6;
        }

        .upload-options {
            display: flex;
            gap: 20px;
            margin-bottom: 15px;
        }

        .upload-option {
            flex: 1;
        }

        .preview-container {
            text-align: center;
            margin-top: 15px;
        }

        .preview-image {
            max-width: 200px;
            max-height: 150px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            object-fit: cover;
        }

        .hidden {
            display: none;
        }

        .no-categories {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }

        .no-categories h3 {
            margin-bottom: 15px;
            color: #333;
        }

        @media (max-width: 768px) {
            .categories-grid {
                grid-template-columns: 1fr;
            }
            
            .controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .upload-options {
                flex-direction: column;
            }

            .stats-bar {
                flex-direction: column;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-user">
                <span class="role-badge">👤 <?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
                <a href="login.php?logout=1">🚪 Déconnexion</a>
            </div>
            <h1>Category Manager</h1>
            <p>Gestion complète des catégories avec système de fichiers uniquement</p>
        </div>

        <div class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $messageType ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Barre de statistiques -->
            <div class="stats-bar">
                <div class="stat-item">
                    <div class="stat-number"><?= count($categories) ?></div>
                    <div class="stat-label">Catégories Total</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($categories, function($c) { return $c['hasFolder']; })) ?></div>
                    <div class="stat-label">Avec Dossier</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($categories, function($c) { return !empty($c['image']); })) ?></div>
                    <div class="stat-label">Avec Image</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number"><?= count(array_filter($categories, function($c) { return !$c['hasFolder']; })) ?></div>
                    <div class="stat-label">En Attente</div>
                </div>
            </div>

            <div class="controls">
                <button class="btn btn-primary" onclick="showAddModal()">
                    Ajouter Catégorie
                </button>
                <?php if (count(array_filter($categories, function($c) { return !$c['hasFolder']; })) > 0): ?>
                <button class="btn btn-success" onclick="createAllMissing()">
                    Créer Dossiers Manquants
                </button>
                <?php endif; ?>
                <a class="btn btn-info" href="?action=generate_index">
                    Sauvgarder
                </a>
            </div>

            <?php if (empty($categories)): ?>
                <div class="no-categories">
                    <h3>Aucune catégorie trouvée</h3>
                    <p>Commencez par créer votre première catégorie !</p>
                    <br>
                    <button class="btn btn-primary" onclick="showAddModal()">
                        Créer ma première catégorie
                    </button>
                </div>
            <?php else: ?>
                <div class="categories-grid">
                    <?php foreach ($categories as $category): ?>
                        <div class="category-card">
                            <div class="category-image">
                                <?php 
                                $webpPath = "./categories/{$category['slug']}/image.webp";
                                if (file_exists($webpPath)): 
                                ?>
                                    <img src="<?= $webpPath ?>?t=<?= time() ?>" 
                                         alt="<?= htmlspecialchars($category['name']) ?>"
                                         onerror="this.parentNode.innerHTML='📁';">
                                    <div class="webp-badge">WebP</div>
                                <?php elseif (!empty($category['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($category['image_url']) ?>" 
                                         alt="<?= htmlspecialchars($category['name']) ?>"
                                         onerror="this.parentNode.innerHTML='📁';">
                                <?php else: ?>
                                    📁
                                <?php endif; ?>
                                
                                <div class="folder-status <?= $category['hasFolder'] ? 'folder-exists' : 'folder-missing' ?>">
                                    <?= $category['hasFolder'] ? 'Créé' : 'Pending' ?>
                                </div>
                            </div>
                            <div class="category-content">
                                <div class="category-name"><?= htmlspecialchars($category['name']) ?></div>
                                <div class="category-slug"><?= htmlspecialchars($category['slug']) ?></div>
                                <div class="category-id"><?= htmlspecialchars($category['id']) ?></div>
                                
                                <?php if (!empty($category['description'])): ?>
                                    <div class="category-description"><?= htmlspecialchars($category['description']) ?></div>
                                <?php endif; ?>
                                
                                <div class="category-meta">
                                    <?php if (isset($category['createdAt'])): ?>
                                        Créé: <?= date('d/m/Y H:i', strtotime($category['createdAt'])) ?>
                                    <?php endif; ?>
                                    <?php if (isset($category['updatedAt']) && $category['updatedAt'] !== $category['createdAt']): ?>
                                        <br>Modifié: <?= date('d/m/Y H:i', strtotime($category['updatedAt'])) ?>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="category-actions">
                                    <button class="btn btn-primary btn-small" onclick="editCategory('<?= $category['id'] ?>')">
                                        Modifier
                                    </button>
                                    <button class="btn btn-danger btn-small" onclick="deleteCategory('<?= $category['id'] ?>', '<?= htmlspecialchars($category['name']) ?>')">
                                        Supprimer
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Ajouter/Modifier -->
    <div id="categoryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Ajouter Catégorie</h2>
                <button class="close" onclick="closeModal()">&times;</button>
            </div>
            <form id="categoryForm" method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add_category">
                <input type="hidden" name="id" id="categoryId">
                
                <div class="form-group">
                    <label for="name">Nom de la catégorie *</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                    <div id="nameStatus"></div>
                </div>

                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" class="form-control" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="image_url">URL de l'image (optionnel)</label>
                    <input type="url" name="image_url" id="image_url" class="form-control" 
                           placeholder="https://images.unsplash.com/photo-...">
                </div>

                <!-- Section Upload -->
                <div class="upload-section">
                    <h4>Ou Upload Image Directement</h4>
                    
                    <div class="upload-options">
                        <div class="upload-option">
                            <label>Fichier Local</label>
                            <input type="file" name="uploaded_image" id="uploaded_image" class="form-control" 
                                   accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                        </div>
                    </div>
                    
                    <div id="previewContainer" class="preview-container hidden">
                        <img id="previewImage" class="preview-image" alt="Preview">
                        <div style="font-size: 12px; color: #666; margin-top: 8px;" id="fileInfo"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="Is_home">In Homepage</label>
                    <input type="checkbox" name="Is_home" id="Is_home" class="form-control">
                </div>                

                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <button type="button" class="btn" onclick="closeModal()">Annuler</button>
                    <button type="submit" class="btn btn-success">Sauvegarder</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Données des catégories chargées depuis PHP
        let categories = <?= json_encode($categories) ?>;
        
        function createSlug(name) {
            return name
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        function checkNameConflicts(name, excludeId = null) {
            const conflicts = [];
            const nameToCheck = name.trim().toLowerCase();
            const slugToCheck = createSlug(name);
            
            if (!nameToCheck) return conflicts;
            
            categories.forEach(category => {
                if (category.id !== excludeId) {
                    if (category.name.trim().toLowerCase() === nameToCheck) {
                        conflicts.push({
                            type: 'name',
                            category: category,
                            message: `Une catégorie "${category.name}" existe déjà (ID: ${category.id})`
                        });
                    }
                    
                    if (category.slug === slugToCheck) {
                        conflicts.push({
                            type: 'slug',
                            category: category,
                            message: `Le slug "${slugToCheck}" est déjà utilisé par "${category.name}"`
                        });
                    }
                }
            });
            
            return conflicts;
        }

        function generateUniqueSlug(name, excludeId = null) {
            const baseSlug = createSlug(name);
            let slug = baseSlug;
            let counter = 1;
            
            while (categories.some(cat => cat.slug === slug && cat.id !== excludeId)) {
                slug = baseSlug + '-' + counter;
                counter++;
            }
            
            return slug;
        }

        function validateCategoryName(name, excludeId = null) {
            const nameField = document.getElementById('name');
            const statusDiv = document.getElementById('nameStatus');
            
            if (!name.trim()) {
                statusDiv.innerHTML = '';
                nameField.style.borderColor = '#e0e0e0';
                return true;
            }
            
            const conflicts = checkNameConflicts(name, excludeId);
            
            if (conflicts.length > 0) {
                const messages = conflicts.map(c => c.message).join('<br>');
                statusDiv.innerHTML = `<div style="color: #dc3545; font-size: 12px; margin-top: 5px;">${messages}</div>`;
                nameField.style.borderColor = '#dc3545';
                return false;
            } else {
                const suggestedSlug = generateUniqueSlug(name, excludeId);
                statusDiv.innerHTML = `<div style="color: #28a745; font-size: 12px; margin-top: 5px;">Disponible - Slug: "${suggestedSlug}"</div>`;
                nameField.style.borderColor = '#28a745';
                return true;
            }
        }

        function setupNameValidation(excludeId = null) {
            const nameInput = document.getElementById('name');
            if (nameInput) {
                // Nettoyer les anciens listeners
                nameInput.removeEventListener('input', nameInput._validateHandler);
                
                nameInput._validateHandler = function(e) {
                    validateCategoryName(e.target.value, excludeId);
                };
                
                nameInput.addEventListener('input', nameInput._validateHandler);
                
                if (nameInput.value) {
                    validateCategoryName(nameInput.value, excludeId);
                }
            }
        }

        function setupImagePreview() {
            const fileInput = document.getElementById('uploaded_image');
            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    const file = e.target.files[0];
                    if (file && file.type.startsWith('image/')) {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            let previewContainer = document.getElementById('previewContainer');
                            let previewImage = document.getElementById('previewImage');
                            let fileInfo = document.getElementById('fileInfo');
                            
                            if (previewContainer && previewImage) {
                                previewImage.src = e.target.result;
                                fileInfo.innerHTML = `${file.name}<br>${formatFileSize(file.size)}`;
                                previewContainer.classList.remove('hidden');
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
        }

        function validateForm(excludeId = null) {
            const nameInput = document.getElementById('name');
            const name = nameInput ? nameInput.value.trim() : '';
            
            if (!name) {
                alert('Le nom de la catégorie est obligatoire');
                nameInput.focus();
                return false;
            }
            
            const conflicts = checkNameConflicts(name, excludeId);
            if (conflicts.length > 0) {
                let message = 'Impossible de créer cette catégorie:\n\n';
                conflicts.forEach((conflict, index) => {
                    message += `${index + 1}. ${conflict.message}\n`;
                });
                message += '\nVeuillez choisir un nom différent.';
                
                alert(message);
                nameInput.focus();
                nameInput.select();
                return false;
            }
            
            return true;
        }

        let isSubmitting = false;

        function handleFormSubmit(event) {
            if (isSubmitting) {
                event.preventDefault();
                return false;
            }
            
            const excludeId = document.getElementById('categoryId')?.value || null;
            
            if (!validateForm(excludeId)) {
                event.preventDefault();
                return false;
            }
            
            isSubmitting = true;
            
            setTimeout(() => {
                isSubmitting = false;
            }, 2000);
            
            return true;
        }

        function showAddModal() {
            document.getElementById('modalTitle').textContent = 'Ajouter Catégorie';
            document.getElementById('formAction').value = 'add_category';
            document.getElementById('categoryForm').reset();
            document.getElementById('categoryId').value = '';
            
            // Nettoyer les status
            const nameStatus = document.getElementById('nameStatus');
            if (nameStatus) nameStatus.innerHTML = '';
            
            const previewContainer = document.getElementById('previewContainer');
            if (previewContainer) previewContainer.classList.add('hidden');
            
            document.getElementById('categoryModal').classList.add('show');
            
            setTimeout(() => {
                setupNameValidation();
                setupImagePreview();
            }, 100);
        }

        function closeModal() {
            document.getElementById('categoryModal').classList.remove('show');
        }

        function editCategory(id) {
            const category = categories.find(cat => cat.id === id);
            if (!category) {
                alert('Catégorie non trouvée');
                return;
            }

            document.getElementById('modalTitle').textContent = 'Modifier Catégorie';
            document.getElementById('formAction').value = 'update_category';
            document.getElementById('categoryId').value = id;
            document.getElementById('name').value = category.name;
            document.getElementById('description').value = category.description || '';
            document.getElementById('image_url').value = category.image_url || '';
            document.getElementById('Is_home').checked = category.Is_home || false;
            
            // Nettoyer les status
            const nameStatus = document.getElementById('nameStatus');
            if (nameStatus) nameStatus.innerHTML = '';
            
            const previewContainer = document.getElementById('previewContainer');
            if (previewContainer) previewContainer.classList.add('hidden');
            
            document.getElementById('categoryModal').classList.add('show');
            
            setTimeout(() => {
                setupNameValidation(id);
                setupImagePreview();
            }, 100);
          
        }

        function deleteCategory(id, name) {
            if (!confirm(`ATTENTION !\n\nSupprimer définitivement "${name}" ?\n\nCette action supprimera :\n- La catégorie\n- Son dossier complet\n- Tous les fichiers qu'il contient\n\nCette action est IRRÉVERSIBLE !`)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'post';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
         
        }

        function createAllMissing() {
            const missingCount = categories.filter(c => !c.hasFolder).length;
            
            if (missingCount === 0) {
                alert('Tous les dossiers sont déjà créés !');
                return;
            }
            
            if (confirm(`Créer ${missingCount} dossier(s) manquant(s) avec téléchargement des images ?`)) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = `<input type="hidden" name="action" value="create_all_missing">`;
                document.body.appendChild(form);
                form.submit();
            }

        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            const categoryForm = document.getElementById('categoryForm');
            if (categoryForm) {
                categoryForm.addEventListener('submit', handleFormSubmit);
            }
        });

        // Fermeture modale en cliquant à l'extérieur
        window.onclick = function(event) {
            const categoryModal = document.getElementById('categoryModal');
            if (event.target === categoryModal) {
                closeModal();
            }
        }

    

    </script>
</body>
</html>