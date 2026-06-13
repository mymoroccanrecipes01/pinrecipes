<?php

require_once(__DIR__ . '/config.php');

$path=BASE_URL;
 
// Configuration du template
$config = TEMPLATE_CONFIG;

// Initialiser cURL
$ch = curl_init();

// Configuration de la requête
curl_setopt($ch, CURLOPT_URL, $path."/generate_pinterest.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($config));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen(json_encode($config))
]);

// Exécuter la requête
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Vérifier les erreurs
if (curl_errno($ch)) {
    // echo "Erreur cURL: " . curl_error($ch) . "\n";
    curl_close($ch);
    exit;
}

curl_close($ch);

// Afficher le résultat
// echo "Code HTTP: $httpCode\n";
// echo "Réponse: \n";
// echo $response . "\n";

// Décoder la réponse
 $result = json_decode($response, true);

// if ($result && isset($result['success']) && $result['success']) {
//     echo "\n✅ Image générée avec succès!\n";
//     echo "Fichier: " . $result['filename'] . "\n";
//     echo "URL: http://localhost/" . $result['url'] . "\n";
//     echo "Chemin: " . $result['path'] . "\n";
// } else {
//     echo "\n❌ Erreur lors de la génération\n";
//     if (isset($result['error'])) {
//         echo "Message: " . $result['error'] . "\n";
//     }
// }

if ($result && isset($result['success']) && $result['success']) {
    echo json_encode([
        "success" => true,
        "filename" => $result['filename'] ?? null,
        "url" =>   ($result['url'] ?? ''),
        "path" => $result['path'] ?? '',
        "pathrelative" => $result['pathrelative'] ?? ''
    ]);
} else {
    echo json_encode([
        "success" => false,
        "error" => $result['error'] ?? "Erreur lors de la génération"
    ]);
}