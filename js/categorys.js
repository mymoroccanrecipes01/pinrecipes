// Chargement dynamique des catégories depuis le fichier index.json

// Stockage des données de toutes les catégories
let toutesLesCategories = [];

// Fonction pour charger la liste des dossiers depuis index.json
async function chargerIndexJson() {
   // // console.log('Chargement de index.json...');
    
    try {
        const response = await fetch('./categories/index.json');
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const indexData = await response.json();
       // // console.log('Index.json chargé:', indexData);
        
        // MODIFIÉ: Adapter pour la nouvelle structure objet
        if (indexData.folders && typeof indexData.folders === 'object') {
            // Convertir l'objet en array de {nom, id}
            return Object.entries(indexData.folders).map(([nom, id]) => ({
                nom: nom,        // "box-dessert" -> utilisé comme slug
                id: id          // "cat_1758296513_957" -> ID original
            }));
        }
        
        return [];
    } catch (error) {
       // console.error('Erreur chargement index.json:', error);
        return await decovrirDossiersFallback();
    }
}

// Fonction de fallback si index.json n'est pas disponible
async function decovrirDossiersFallback() {
    const nomsCommuns = [];
    
   // // console.log('Test de fallback sur noms communs...');
    const dossiersExistants = [];
    
    for (const nom of nomsCommuns) {
        try {
            const response = await fetch(`./categories/${nom}/category.json`, {
                method: 'HEAD'
            });
            if (response.ok) {
                dossiersExistants.push({ nom: nom, id: null });
            }
        } catch (error) {
            // Dossier n'existe pas, continuer
        }
    }
    
   // // console.log(`Fallback: ${dossiersExistants.length} dossiers trouvés:`, dossiersExistants);
    return dossiersExistants;
}

// Fonction pour charger une catégorie spécifique
async function chargerUneCategorie(dossierInfo) {
    const nomDossier = dossierInfo.nom;
    const idFromIndex = dossierInfo.id;
    const chemin = `./categories/${nomDossier}/`;
    
    try {
        // Charger les 3 fichiers en parallèle
        const [categoryResponse, imageResponse, infoResponse] = await Promise.all([
            fetch(chemin + 'category.json'),
            fetch(chemin + 'image.webp'),
            fetch(chemin + 'image_info.txt')
        ]);

        // Traiter category.json
        let categoryData = {};
        if (categoryResponse.ok) {
            categoryData = await categoryResponse.json();
        } else {
            console.warn(`category.json manquant pour ${nomDossier}`);
        }

        // Traiter image.webp
        let imageUrl = null;
        if (imageResponse.ok) {
            const imageBlob = await imageResponse.blob();
            imageUrl = URL.createObjectURL(imageBlob);
        } else {
            console.warn(`image.webp manquante pour ${nomDossier}`);
        }

        // Traiter image_info.txt
        let imageInfo = '';
        if (infoResponse.ok) {
            imageInfo = await infoResponse.text();
        }

        return {
            nom: nomDossier,
            data: categoryData,
            imageUrl: imageUrl,
            info: imageInfo,
            isValid: categoryResponse.ok,
            // Utiliser l'ID du JSON s'il existe, sinon celui de l'index, sinon le nom
            categoryId: categoryData.id || idFromIndex || nomDossier
        };
        
    } catch (error) {
       // console.error(`Erreur chargement ${nomDossier}:`, error);
        return null;
    }
}

// Fonction principale pour charger toutes les catégories
async function chargerToutesLesCategories() {
   // // console.log('Chargement de toutes les catégories depuis index.json...');
    
    // 1. Charger la liste des dossiers depuis index.json
    const dossiersInfo = await chargerIndexJson();
    
    if (dossiersInfo.length === 0) {
        console.warn('Aucun dossier de catégorie trouvé !');
        return;
    }
    
   // // console.log(`${dossiersInfo.length} dossiers à charger:`, dossiersInfo);
    
    // 2. Charger toutes les catégories en parallèle
    const promesses = dossiersInfo.map(dossierInfo => chargerUneCategorie(dossierInfo));
    const resultats = await Promise.all(promesses);
    
    // 3. Filtrer les résultats valides
    toutesLesCategories = resultats.filter(categorie => categorie !== null && categorie.isValid);
    
   // // console.log(`${toutesLesCategories.length} catégories chargées avec succès`);
    
    // 4. Générer le HTML
    genererHTMLCategories();
}

// Fonction pour générer le HTML dynamiquement
function genererHTMLCategories() {
    const container = document.querySelector('.categories-grid');
    if (!container) {
       // console.error('Container .categories-grid non trouvé');
        return;
    }
    
    // Vider le container existant
    container.innerHTML = '';
    
    if (toutesLesCategories.length === 0) {
        container.innerHTML = '<p>Aucune catégorie disponible.</p>';
        return;
    }
    
    // Créer une card pour chaque catégorie
    toutesLesCategories.forEach(categorie => {
        const card = document.createElement('div');
        card.className = 'category-card';
        
        // Utiliser le titre du JSON ou le nom du dossier
        const titre = categorie.data.title || categorie.data.name || categorie.nom;
        const description = categorie.data.description || '';
        const isHome = categorie.data.Is_home || false;
        
        // Vérifier la page actuelle
        const params = new URLSearchParams(window.location.search);
        const page = params.get("page") || "home";
               
        if(!isHome && page === "home") {
            card.style.display = 'none';
            return;
        }
        
        // Image par défaut si pas d'image
        const imageHtml = categorie.imageUrl ? 
            `<img src="${categorie.imageUrl}" alt="${titre}" onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzAwIiBoZWlnaHQ9IjIwMCIgdmlld0JveD0iMCAwIDMwMCAyMDAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIzMDAiIGhlaWdodD0iMjAwIiBmaWxsPSIjZjBmMGYwIi8+Cjx0ZXh0IHg9IjE1MCIgeT0iMTAwIiB0ZXh0LWFuY2hvcj0ibWlkZGxlIiBmaWxsPSIjOTk5IiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiPkltYWdlIG5vbiB0cm91dsOpZTwvdGV4dD4KPHN2Zz4=';">` :
            `<div class="placeholder-image">📁</div>`;
        
        card.innerHTML = `
            ${imageHtml}
            <div class="category-content">
                <h3>${titre}</h3>
            </div>
        `;
        
        // Ajouter des données personnalisées - utiliser le categoryId calculé
        card.dataset.categoryId = categorie.categoryId;
        card.dataset.categoryFolder = categorie.nom;
        
        // Ajouter un événement click
        card.addEventListener('click', () => {
            ouvrirCategorie(categorie);
        });
        
        container.appendChild(card);
    });
    
   // // console.log(`${toutesLesCategories.length} catégories affichées`);
}

// Fonction pour gérer le clic sur une catégorie
// Fonction pour gérer le clic sur une catégorie
function ouvrirCategorie(categorie) {
   // // console.log('Ouverture de la catégorie:', categorie);
    
    // NOUVEAU: Utiliser le nom du dossier comme slug au lieu de l'ID
    const categorySlug = categorie.nom; // "box-dessert" au lieu de "cat_1757766010_988"
    
   // // console.log('Slug de catégorie utilisé:', categorySlug);
    
    // NOUVEAU FORMAT: posts-category/slug
    const baseUrl = window.location.origin + window.location.pathname.split('?')[0];
    const newUrl = `${baseUrl}?page=posts-category/${categorySlug}`;
    
   // // console.log('Redirection vers:', newUrl);
    window.location.href = newUrl;
}
// Fonction pour rafraîchir les catégories
async function rafraichirCategories() {
   // // console.log('Rafraîchissement des catégories...');
    toutesLesCategories = [];
    await chargerToutesLesCategories();
}

// Fonction pour forcer la régénération de l'index.json
async function regenererIndex() {
    try {
       // // console.log('Régénération de index.json...');
        const response = await fetch('./generate-categoryJson.php');
        
        if (response.ok) {
            const message = await response.text();
           // // console.log('Index régénéré:', message);
            
            // Recharger les catégories
            await rafraichirCategories();
            return true;
        } else {
           // console.error('Erreur régénération index');
            return false;
        }
    } catch (error) {
       // console.error('Erreur lors de la régénération:', error);
        return false;
    }
}

// Lancement automatique au chargement de la page
window.addEventListener('load', chargerToutesLesCategories);

// API publique pour utilisation externe
window.CategoriesManager = {
    getData: () => toutesLesCategories,
    refresh: rafraichirCategories,
    regenerateIndex: regenererIndex,
    loadFromIndex: chargerIndexJson,
    regenerateHTML: genererHTMLCategories
};

// Debug: afficher les infos dans la console
// console.log('Categories Manager chargé. API disponible via window.CategoriesManager');