class RecipeLoader {
    constructor(containerId = 'items') {
        this.containerId = containerId;
        this.recipesContainer = null;
        this.recipesPath = './recipes/';
        this.categoriesPath = './categories/'; // NOUVEAU: Chemin vers les catégories
        this.allRecipes = [];
        this.filteredRecipes = [];
        this.displayedRecipes = [];
        this.currentPage = 0;
        this.recipesPerPage = 6;
        this.isLoading = false;
        this.hasMoreRecipes = true;
        this.initialized = false;
        this.currentCategorySlug = null;
        this.categoryMapping = {}; // NOUVEAU: Stockage du mapping des catégories
    }

    async init() {
        if (this.initialized) return;
        
        this.waitForContainer();
        
        if (!this.recipesContainer) {
            // console.error(`Container avec l'ID '${this.containerId}' non trouvé`);
            return false;
        }

        // NOUVEAU: Charger le mapping des catégories en premier
        await this.loadCategoryMapping();
        
        // Charger toutes les recettes
        await this.loadAllRecipes();
        
        // Appliquer les filtres initiaux (y compris catégorie depuis l'URL)
        this.applyUrlFilters();
        
        // Configurer le scroll infini
        this.setupInfiniteScroll();
        
        // Écouter les changements d'URL et les événements de page
        window.addEventListener('popstate', () => {
            this.resetPagination();
            this.applyUrlFilters();
        });

        // Écouter l'événement pageLoaded du router
        window.addEventListener('pageLoaded', (event) => {
            if (event.detail && event.detail.params && event.detail.params.categorySlug) {
                const categorySlug = event.detail.params.categorySlug;
                // console.log('RecipeLoader: Catégorie reçue du router:', categorySlug);
                this.filterByCategory(categorySlug);
            }
        });

        this.initialized = true;
        return true;
    }

    async loadCategoryMapping() {
        try {
            // console.log('Chargement du mapping des catégories...');
            const response = await fetch(`${this.categoriesPath}index.json`);
            
            if (!response.ok) {
                // console.warn('Fichier categories/index.json non trouvé, utilisation du mapping par défaut');
                this.categoryMapping = {};
                return;
            }
            
            const data = await response.json();
            
            if (data.folders && typeof data.folders === 'object') {
                this.categoryMapping = data.folders;
                // console.log('Mapping des catégories chargé:', this.categoryMapping);
            } else {
                // console.warn('Format invalide dans categories/index.json');
                this.categoryMapping = {};
            }
            
        } catch (error) {
            // console.error('Erreur lors du chargement du mapping des catégories:', error);
            this.categoryMapping = {};
        }
    }

    waitForContainer() {
        const maxAttempts = 50;
        const baseDelay = 100;
        
        for (let i = 0; i < maxAttempts; i++) {
            this.recipesContainer = document.getElementById(this.containerId);
            if (this.recipesContainer) {
                // console.log(`Container '${this.containerId}' trouvé après ${i + 1} tentative(s)`);
                return;
            }
            
            const delay = baseDelay * (i < 10 ? 1 : 2);
            if (i % 10 === 0) {
                // console.log(`Tentative ${i + 1}/${maxAttempts} - Container '${this.containerId}' non trouvé, attente...`);
            }
        }
        // console.error(`Container '${this.containerId}' non trouvé après ${maxAttempts} tentatives`);
    }

     getIdFromSlug(categorySlug) {
        // Utiliser le mapping chargé depuis categories/index.json
        const mappedId = this.categoryMapping[categorySlug];
        
        if (mappedId) {
            // console.log(`Mapping trouvé: "${categorySlug}" -> "${mappedId}"`);
            return mappedId;
        }
        
        // console.log(`Aucun mapping trouvé pour "${categorySlug}", utilisation du slug comme ID`);
        return categorySlug;
    }

    // NOUVEAU: Méthode pour filtrer par slug de catégorie
 filterByCategory(categorySlug) {
        // console.log('=== FILTRAGE PAR CATÉGORIE ===');
        // console.log('Slug de catégorie:', categorySlug);
        // console.log('Mapping disponible:', this.categoryMapping);
        
        this.currentCategorySlug = categorySlug;
        
        // Convertir le slug en ID en utilisant le mapping chargé
        const categoryId = this.getIdFromSlug(categorySlug);
        // console.log(`Conversion finale: "${categorySlug}" -> "${categoryId}"`);
        
        this.resetPagination();
        
        this.filteredRecipes = this.allRecipes.filter(recipe => {
            if (!recipe.category_id && !recipe.category) {
                // console.log(`✗ Recette "${recipe.title}" - pas de catégorie définie`);
                return false;
            }
            
            // console.log(`Vérification recette "${recipe.title}":`, {
            //     category_id: recipe.category_id,
            //     category: recipe.category,
            //     targetSlug: categorySlug,
            //     targetId: categoryId
            // });
            
            // 1. Correspondance exacte avec l'ID mappé
            if (recipe.category_id === categoryId) {
                // console.log(`✓ Correspondance ID mappé: "${recipe.title}"`);
                return true;
            }
            
            // 2. Correspondance directe avec le slug (fallback)
            if (recipe.category_id === categorySlug) {
                // console.log(`✓ Correspondance slug direct: "${recipe.title}"`);
                return true;
            }
            
            // 3. Correspondance avec le nom de catégorie slugifié
            if (recipe.category && this.slugify(recipe.category) === categorySlug) {
                // console.log(`✓ Correspondance nom slugifié: "${recipe.title}"`);
                return true;
            }
            
            // 4. Correspondance partielle (fallback pour compatibilité)
            if (recipe.category_id && recipe.category_id.toLowerCase().includes(categorySlug.toLowerCase())) {
                // console.log(`✓ Correspondance partielle ID: "${recipe.title}"`);
                return true;
            }
            
            if (recipe.category && recipe.category.toLowerCase().includes(categorySlug.toLowerCase())) {
                // console.log(`✓ Correspondance partielle nom: "${recipe.title}"`);
                return true;
            }
            
            // console.log(`✗ Aucune correspondance: "${recipe.title}"`);
            return false;
        });
        
        // console.log(`Filtrage terminé: ${this.filteredRecipes.length} recettes trouvées pour "${categorySlug}"`);
        // console.log('==============================');
        
        this.hasMoreRecipes = this.filteredRecipes.length > 0;
        this.displayInitialRecipes();
        this.updateFilterInfo({ category: categorySlug }, this.filteredRecipes.length);
    }

    debugCategories() {
        // console.log('=== DEBUG CATEGORIES & MAPPING ===');
        // console.log('Mapping chargé:', this.categoryMapping);
        // console.log('Nombre total de recettes:', this.allRecipes.length);
        
        const categories = new Set();
        const categoryDetails = [];
        
        this.allRecipes.forEach(recipe => {
            if (recipe.category_id) categories.add(recipe.category_id);
            if (recipe.category) categories.add(recipe.category);
            
            categoryDetails.push({
                title: recipe.title,
                category_id: recipe.category_id,
                category: recipe.category,
                category_slugified: recipe.category ? this.slugify(recipe.category) : null
            });
        });
        
        // console.log('Catégories uniques dans les recettes:', [...categories]);
        // console.log('Slugs disponibles dans le mapping:', Object.keys(this.categoryMapping));
        // console.log('IDs dans le mapping:', Object.values(this.categoryMapping));
        // console.log('Détails par recette:', categoryDetails);
        
        // Vérifier les correspondances
        // console.log('=== VÉRIFICATION CORRESPONDANCES ===');
        Object.keys(this.categoryMapping).forEach(slug => {
            const id = this.categoryMapping[slug];
            const matchingRecipes = this.allRecipes.filter(r => r.category_id === id);
            // console.log(`Slug "${slug}" (ID: ${id}) -> ${matchingRecipes.length} recettes`);
        });
        
        // console.log('==================================');
        
        return { 
            mapping: this.categoryMapping,
            categories: [...categories], 
            details: categoryDetails 
        };
    }
    

    // Configuration du scroll infini
    setupInfiniteScroll() {
        let scrollTimeout;
        const params = new URLSearchParams(window.location.search);
        const page = params.get("page") || "home";
        
        const handleScroll = () => {
            if (scrollTimeout) {
                clearTimeout(scrollTimeout);
            }
            
            scrollTimeout = setTimeout(() => {
                if (this.isLoading || !this.hasMoreRecipes) {
                    return;
                }

                const scrollPosition = window.scrollY + window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                
                // Charger plus de recettes quand on est à 200px du bas
                if (page !== "home" && scrollPosition >= documentHeight - 200) {
                    this.loadMoreRecipes();
                }
            }, 100);
        };

        window.addEventListener('scroll', handleScroll);
        
        // Nettoyer l'event listener si nécessaire
        this.scrollCleanup = () => {
            window.removeEventListener('scroll', handleScroll);
            if (scrollTimeout) {
                clearTimeout(scrollTimeout);
            }
        };
    }

    // Réinitialiser la pagination
    resetPagination() {
        this.currentPage = 0;
        this.displayedRecipes = [];
        this.hasMoreRecipes = true;
        this.isLoading = false;
        
        // Vider le container
        if (this.recipesContainer) {
            this.recipesContainer.innerHTML = '';
        }
    }

    // MODIFIÉ: Parser les paramètres URL pour supporter le nouveau format
    getUrlParams() {
        const urlParams = new URLSearchParams(window.location.search);
        const pageParam = urlParams.get('page') || 'home';
        
        let category = urlParams.get('category'); // Ancien format
        let categorySlug = null;
        
        // NOUVEAU: Parser le format recipes-category/slug
        if (pageParam.includes('/')) {
            const parts = pageParam.split('/');
            if (parts[0] === 'recipes-category' && parts[1]) {
                categorySlug = parts[1];
            }
        }
        
        // Utiliser le slug si disponible, sinon l'ancien format
        const finalCategory = categorySlug || category;
        
        return {
            category: finalCategory,
            categorySlug: categorySlug,
            search: urlParams.get('search'),
            difficulty: urlParams.get('difficulty')
        };
    }

    getValueByKey(object, key) {
        return object.hasOwnProperty(key) ? object[key] : undefined;
    }

    applyUrlFilters() {
        const params = this.getUrlParams();
        let filteredRecipes = [...this.allRecipes];
        if (!params.category)
            params.category = '';      
        // // console.log('Slugs disponibles dans le mapping:', params.category);
        // // console.log('IDs dans le mapping:', Object.values(params.category));
        // // console.log('keys dans le mapping:', this.getValueByKey(this.categoryMapping, params.category));
        
        // Vérifier les correspondances
        // // console.log('=== VÉRIFICATION CORRESPONDANCES ===');
        // Object.keys(this.categoryMapping).forEach(slug => {
        //     const id = this.categoryMapping[slug];
        //     const matchingRecipes = this.allRecipes.filter(r => r.category_id === id);
        //     // console.log(`Slug "${slug}" (ID: ${id}) -> ${matchingRecipes.length} recettes`);
        // });



        // console.log('=== APPLICATION DES FILTRES URL ===');
        // console.log('Paramètres détectés:', params.category);
        // MODIFIÉ: Filtrer par catégorie (nouveau format prioritaire)
        if (params.categorySlug || params.category) {
            const categoryToFilter = this.getValueByKey(this.categoryMapping, params.category);
            this.currentCategorySlug = categoryToFilter;
            
            filteredRecipes = filteredRecipes.filter(recipe => {
                if (!recipe.category_id && !recipe.category) return false;
                
                // Correspondance exacte avec category_id
                if (recipe.category_id === categoryToFilter) {
                    return true;
                }
                
                // Correspondance avec nom slugifié
                if (this.slugify(recipe.category || '') === categoryToFilter) {
                    return true;
                }
                
                // Correspondance partielle
                if (recipe.category_id && recipe.category_id.includes(categoryToFilter)) {
                    return true;
                }
                
                if (recipe.category && recipe.category.toLowerCase().includes(categoryToFilter.toLowerCase())) {
                    return true;
                }
                
                return false;
            });
            
            // console.log(`Filtrage par catégorie "${categoryToFilter}": ${filteredRecipes.length} recettes trouvées`);
        }

        // Filtrer par recherche
        if (params.search) {
            const searchTerm = params.search.toLowerCase();
            filteredRecipes = filteredRecipes.filter(recipe => 
                recipe.title.toLowerCase().includes(searchTerm) ||
                recipe.description.toLowerCase().includes(searchTerm) ||
                recipe.category.toLowerCase().includes(searchTerm) ||
                (recipe.ingredients && recipe.ingredients.some(ing => 
                    ing.toLowerCase().includes(searchTerm)
                )) ||
                (recipe.tips && recipe.tips.toLowerCase().includes(searchTerm))
            );
        }

        // Filtrer par difficulté
        if (params.difficulty) {
            filteredRecipes = filteredRecipes.filter(recipe => 
                recipe.difficulty && 
                recipe.difficulty.toLowerCase() === params.difficulty.toLowerCase()
            );
        }

        // Mettre à jour les recettes filtrées
        this.filteredRecipes = filteredRecipes;
        this.hasMoreRecipes = this.filteredRecipes.length > 0;
        
        // Afficher les premières recettes
        this.displayInitialRecipes();
        this.updateFilterInfo(params, this.filteredRecipes.length);
    }

    // Afficher les premières recettes selon la page
    displayInitialRecipes() {     
        this.resetPagination();
        this.loadMoreRecipes();        
    }

    // Charger plus de recettes (6 par 6)
    async loadMoreRecipes() {
        const params = new URLSearchParams(window.location.search);
        const pageParam = params.get("page") || "home";
        
        // Détecter la page actuelle (y compris le format slug)
        let currentPage = pageParam;
        if (pageParam.includes('/')) {
            currentPage = pageParam.split('/')[0];
        }
        
        // Ne jamais charger plus sur la page home
        if (currentPage === "home") {
            return;
        }

        if (this.isLoading || !this.hasMoreRecipes) {
            return;
        }

        this.isLoading = true;
        this.showLoadingIndicator();

        try {
            const startIndex = this.currentPage * this.recipesPerPage;
            const endIndex = startIndex + this.recipesPerPage;
            const newRecipes = this.filteredRecipes.slice(startIndex, endIndex);
            
            if (newRecipes.length === 0) {
                this.hasMoreRecipes = false;
                return;
            }

            // Simuler un petit délai pour le loading
            await new Promise(resolve => setTimeout(resolve, 300));     
            
            // Ajouter les nouvelles recettes
            this.displayedRecipes.push(...newRecipes);
            this.appendRecipesToDOM(newRecipes);
            
            this.currentPage++;
            this.hasMoreRecipes = endIndex < this.filteredRecipes.length;

            // console.log(`Page ${this.currentPage} chargée: ${newRecipes.length} recettes (${this.displayedRecipes.length}/${this.filteredRecipes.length} total)`);
            
        } catch (error) {
            // console.error('Erreur lors du chargement de plus de recettes:', error);
            this.showError('Erreur lors du chargement des recettes supplémentaires');
        } finally {
            this.hideLoadingIndicator();
            this.isLoading = false;
        }
    }

    // Ajouter les recettes au DOM
    appendRecipesToDOM(recipes) {
        if (!this.recipesContainer) {
            // console.error('Container des recettes non disponible');
            return;
        }

        if (recipes.length === 0) {
            if (this.displayedRecipes.length === 0) {
                const categoryInfo = this.currentCategorySlug ? 
                    ` pour la catégorie "${this.currentCategorySlug}"` : '';
                
                this.recipesContainer.innerHTML = `
                    <div class="no-recipes">
                        <h3>Aucune recette trouvée</h3>
                        <p>Aucune recette ne correspond aux filtres sélectionnés${categoryInfo}</p>
                        ${this.currentCategorySlug ? `
                            <button onclick="window.router.loadPage('recipes')" class="btn-secondary" style="
                                background: #007bff; color: white; border: none; padding: 10px 20px; 
                                border-radius: 5px; cursor: pointer; margin-top: 15px;
                            ">
                                Voir toutes les recettes
                            </button>
                        ` : ''}
                    </div>
                `;
            }
            return;
        }

       
        const recipesHTML = recipes.map(recipe => this.createRecipeHTML(recipe)).join('');             
        this.recipesContainer.insertAdjacentHTML('beforeend', recipesHTML);
          
    }

    // Afficher l'indicateur de chargement
    showLoadingIndicator() {
        // Supprimer l'ancien indicateur s'il existe
        const existingLoader = document.querySelector('.loading-more');
        if (existingLoader) {
            existingLoader.remove();
        }

        const loader = document.createElement('div');
        loader.className = 'loading-more';
        loader.innerHTML = `
            <div style="text-align: center; padding: 20px;">
                <div class="spinner" style="
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid #3498db;
                    border-radius: 50%;
                    width: 30px;
                    height: 30px;
                    animation: spin 1s linear infinite;
                    margin: 0 auto 10px;
                "></div>
                <p>Loading more ...</p>
            </div>
            <style>
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        `;

        if (this.recipesContainer && this.recipesContainer.parentNode) {
            this.recipesContainer.parentNode.appendChild(loader);
        }
    }

    // Masquer l'indicateur de chargement
    hideLoadingIndicator() {
        const loader = document.querySelector('.loading-more');
        if (loader) {
            loader.remove();
        }
    }

    // MODIFIÉ: Info de filtrage améliorée pour les catégories
    updateFilterInfo(params, resultCount) {
        // Supprimer l'ancienne info
        const existingInfo = document.querySelector('.filter-info');
        if (existingInfo) {
            existingInfo.remove();
        }

        // Créer l'info des filtres si actifs
        const activeFilters = [];
        if (params.categorySlug || params.category) {
            const categoryName = params.categorySlug || params.category;
            activeFilters.push(`${categoryName}`);
        }
        if (params.search) activeFilters.push(`Recherche: "${params.search}"`);
        if (params.difficulty) activeFilters.push(`Difficulté: ${params.difficulty}`);

        if (activeFilters.length > 0 || resultCount !== this.allRecipes.length) {
            const filterInfo = document.createElement('section');
            filterInfo.className = 'category-hero';
            filterInfo.innerHTML = `
                
                    <div class="container" bis_skin_checked="1">
                        <h1 style="text-transform: uppercase;">${activeFilters.map(filter => `${filter}`).join('')}
                        </h1>                              
                    </div>
                `;
            // filterInfo.innerHTML = `
            //     <div class="filter-tags" style="
            //         background: #f8f9fa;
            //         padding: 15px;
            //         margin-bottom: 20px;
            //         border-radius: 8px;
            //         border-left: 4px solid #007bff;
            //     ">
            //         <span class="filter-count" style="font-weight: 600; margin-right: 15px;">
            //             ${resultCount} recette(s) trouvée(s) 
            //         </span>
            //         ${activeFilters.map(filter => `
            //             <span class="filter-tag" style="
            //                 background: #007bff;
            //                 color: white;
            //                 padding: 4px 8px;
            //                 border-radius: 12px;
            //                 font-size: 0.9em;
            //                 margin-right: 8px;
            //             ">${filter}</span>
            //         `).join('')}
            //         ${activeFilters.length > 0 ? `
            //             <button class="clear-filters" onclick="recipeLoader.clearFilters()" style="
            //                 background: #dc3545;
            //                 color: white;
            //                 border: none;
            //                 padding: 4px 12px;
            //                 border-radius: 4px;
            //                 cursor: pointer;
            //                 font-size: 0.9em;
            //             ">Effacer les filtres</button>
            //         ` : ''}
            //     </div>
            // `;
            
            // Insérer avant le container de recettes
            if (this.recipesContainer && this.recipesContainer.parentNode) {
                this.recipesContainer.parentNode.insertBefore(filterInfo, this.recipesContainer);
            }
        }
    }

    clearFilters() {
        // Rediriger vers la page recipes normale
        if (window.router && window.router.loadPage) {
            window.router.loadPage('recipes');
        } else {
            window.history.pushState({}, '', window.location.pathname + '?page=recipes');
            window.location.reload();
        }
    }

    slugify(text) {
        return text
            .toLowerCase()
            .replace(/[àáâãäå]/g, 'a')
            .replace(/[èéêë]/g, 'e')
            .replace(/[ìíîï]/g, 'i')
            .replace(/[òóôõö]/g, 'o')
            .replace(/[ùúûü]/g, 'u')
            .replace(/[ç]/g, 'c')
            .replace(/[ñ]/g, 'n')
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .trim('-');
    }

    async getRecipeFolders() {
        try {
            const indexResponse = await fetch(`${this.recipesPath}index.json`);
            if (indexResponse.ok) {

                const indexData = await indexResponse.json();
                return indexData.folders || indexData;
            }
        } catch (error) {

            // console.log('Fichier index.json non trouvé, scan automatique...');
        }

        return await this.scanRecipeFolders();
    }

    async scanRecipeFolders() {
        const folders = [];
        
        const commonRecipeNames = [
            'cattle-ranch-casserole', 'cattle-ranch-casserole-2',
            'slow-cooker-cowboy-casserole', 'slow-cooker-cowboy-casserole-1',
            'red-lobster-shrimp-scampi-1',
            'apple-harvest-squares', 'chocolate-chip-cookies', 'pasta-carbonara',
            'chicken-tikka-masala', 'banana-bread', 'beef-stew', 'caesar-salad',
            'pancakes', 'pizza-margherita', 'tiramisu', 'lasagna', 'tacos',
            'burger', 'sandwich', 'curry', 'stir-fry', 'grilled-chicken',
            'chocolate-cake', 'apple-pie', 'french-toast', 'omelette',
            'beef-bourguignon', 'chicken-soup', 'vegetable-soup',
            'casserole', 'cowboy-casserole', 'ranch-style', 'slow-cooker-beef',
            'comfort-food', 'hearty-meal', 'family-dinner'
        ];

        for (const folderName of commonRecipeNames) {
            try {
                const response = await fetch(`${this.recipesPath}${folderName}/recipe.json`, {
                    method: 'HEAD'
                });
                if (response.ok) {
                    folders.push(folderName);
                }
            } catch (error) {
                continue;
            }
        }

        return folders;
    }

    async loadAllRecipes() {
        try {
            const params = new URLSearchParams(window.location.search);
            const pageParam = params.get("page") || "home";
            
            // Détecter la page actuelle
            let currentPage = pageParam;
            if (pageParam.includes('/')) {
                currentPage = pageParam.split('/')[0];
            }
            
            const recipeFolders = await this.getRecipeFolders();
            
            if (recipeFolders.length === 0) {
                this.showNoRecipes();
                return;
            }

            // console.log(`${recipeFolders.length} dossiers de recettes trouvés pour la page "${currentPage}":`, recipeFolders);

            const recipePromises = recipeFolders.map(folder => 
                this.loadRecipeData(folder)
            );
            
            const recipes = await Promise.all(recipePromises);
            const validRecipes = recipes.filter(recipe => recipe !== null && recipe.isOnline === true);
            
            
            if (validRecipes.length === 0) {
                this.showError('Aucune recette valide trouvée dans les dossiers spécifiés');
                return;
            }

            // Trier par date de création (plus récent en premier)
            validRecipes.sort((a, b) => {
                const dateA = new Date(a.createdAt || a.updatedAt || Date.now());
                const dateB = new Date(b.createdAt || b.updatedAt || Date.now());
                return dateB - dateA; // Ordre décroissant (plus récent en premier)
            });
            
            // Sur la page home, prendre seulement les 6 premières après tri par date
            if (currentPage === "home") {
                this.allRecipes = validRecipes.slice(0, 6);
                // console.log(`Page home: ${this.allRecipes.length} recettes les plus récentes affichées`);
            } else {
                this.allRecipes = validRecipes;
            }
            
            // console.log(`Recettes triées par date de création (${this.allRecipes.length} recettes)`);

        } catch (error) {
            // console.error('Erreur lors du chargement des recettes:', error);
            this.showError('Erreur lors du chargement des recettes');
        }
    }

    // Méthode displayInitialRecipes modifiée pour gérer la page home et les catégories
    displayInitialRecipes() {
        const params = new URLSearchParams(window.location.search);
        const pageParam = params.get("page") || "home";
        
        // Détecter la page actuelle
        let currentPage = pageParam;
        if (pageParam.includes('/')) {
            currentPage = pageParam.split('/')[0];
        }
        
        this.resetPagination();
        
        if (currentPage === "home") {
            // Sur la page home, afficher directement toutes les recettes filtrées
            // (qui sont déjà limitées à 6 dans loadAllRecipes)
            this.displayedRecipes = [...this.filteredRecipes];
            this.appendRecipesToDOM(this.displayedRecipes);
            this.hasMoreRecipes = false; // Pas de load more sur home
            // console.log(`Page home: ${this.displayedRecipes.length} recettes affichées (pas de pagination)`);
        } else {
            // Sur les autres pages, utiliser la pagination normale
            this.loadMoreRecipes();
        }
    }

    async loadRecipeData(folderName) {
        try {
            const jsonUrl = `${this.recipesPath}${folderName}/recipe.json`;
            const jsonResponse = await fetch(jsonUrl);
            
            if (!jsonResponse.ok) {
                // console.warn(`Impossible de charger ${folderName}/recipe.json`);
                return null;
            }
            
            const recipeData = await jsonResponse.json();
            
            if (!recipeData.title) {
                // console.warn(`Recette ${folderName}: titre manquant`);
                return null;
            }
            
            const mainImage = this.getMainImageFromData(recipeData);
            const prepTime = recipeData.prep_time ? `${recipeData.prep_time} min` : null;
            const cookTime = recipeData.cook_time ? `${recipeData.cook_time} min` : null;
            const totalTime = recipeData.total_time ? `${recipeData.total_time} min` : null;
            
            return {
                id: recipeData.id,
                slug: recipeData.slug || folderName,
                folderName,
                title: recipeData.title,
                description: recipeData.description || 'Description non disponible',
                category: this.getCategoryName(recipeData.category_id) || 'Général',
                category_id: recipeData.category_id, // IMPORTANT: Garder l'ID original
                difficulty: recipeData.difficulty || 'Non spécifié',
                prepTime,
                cookTime,
                totalTime,
                servings: recipeData.servings,
                ingredients: recipeData.ingredients || [],
                instructions: recipeData.instructions || [],
                tips: recipeData.tips,
                mainImage,
                images: recipeData.images || [],
                hasRichStructure: recipeData.has_rich_structure || false,
                createdAt: recipeData.createdAt,
                updatedAt: recipeData.updatedAt,
                ...recipeData
            };
            
        } catch (error) {
            // console.error(`Erreur lors du chargement de la recette ${folderName}:`, error);
            return null;
        }
    }

    getMainImageFromData(recipeData) {
        if (recipeData.image_path) {
            return `./${recipeData.image_path}`;
        }
        
        if (recipeData.images && Array.isArray(recipeData.images)) {
            const mainImg = recipeData.images.find(img => img.type === 'main');
            if (mainImg && mainImg.filePath) {
                return `./${mainImg.filePath}`;
            }
            
            if (recipeData.images.length > 0 && recipeData.images[0].filePath) {
                return `./${recipeData.images[0].filePath}`;
            }
        }
        
        if (recipeData.image) {
            const imageDir = recipeData.image_dir || `${recipeData.slug || recipeData.folderName}/images`;
            return `./recipes/${imageDir}/${recipeData.image}`;
        }
        
        return this.findMainImage(recipeData.slug || recipeData.folderName);
    }

    getCategoryName(categoryId) {
        const categoryMap = {
            // Ajouter votre mapping de catégories ici si nécessaire
        };
        
        return categoryMap[categoryId] || categoryId;
    }

    async findMainImage(folderName) {
        const commonImageNames = [
            'main.jpg', 'main.jpeg', 'main.png',
            'featured.jpg', 'featured.jpeg', 'featured.png',
            'image.jpg', 'image.jpeg', 'image.png',
            'cover.jpg', 'cover.jpeg', 'cover.png',
            'hero.jpg', 'hero.jpeg', 'hero.png'
        ];
        
        const imagesPath = `${this.recipesPath}${folderName}/images/`;
        
        for (const imageName of commonImageNames) {
            try {
                const imageUrl = imagesPath + imageName;
                const response = await fetch(imageUrl, { method: 'HEAD' });
                if (response.ok) {
                    return imageUrl;
                }
            } catch (error) {
                continue;
            }
        }
        
        return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"><rect width="400" height="300" fill="%23f8f9fa"/><text x="200" y="150" font-family="Arial" font-size="18" fill="%236c757d" text-anchor="middle">Image non disponible</text></svg>';
    }

createRecipeHTML(recipe) {
    // Utiliser des valeurs par défaut pour éviter les erreurs de déstructuration
    const slug = recipe.slug || recipe.folderName || recipe.id || 'recipe';
    const folderName = recipe.folderName || recipe.slug || recipe.id || 'recipe';
    const title = recipe.title || 'Titre non disponible';
    const description = recipe.description || 'Description non disponible';
    const category = recipe.category || 'Général';
    const difficulty = recipe.difficulty || 'Non spécifié';
    const mainImage = recipe.mainImage || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"><rect width="400" height="300" fill="%23f8f9fa"/><text x="200" y="150" font-family="Arial" font-size="18" fill="%236c757d" text-anchor="middle">Image non disponible</text></svg>';

    const recipeUrl = `recipes/${slug}`;
    
    return `
        <div class="entry" data-category="${this.slugify(category)}" data-difficulty="${difficulty.toLowerCase()}">
            <a class="entry__img" href="${recipeUrl}" title="${title}">
                <img alt="${title}" 
                     loading="lazy" 
                     decoding="async" 
                     width="400" 
                     height="300" 
                     src="${mainImage}"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;400&quot; height=&quot;300&quot; viewBox=&quot;0 0 400 300&quot;><rect width=&quot;400&quot; height=&quot;300&quot; fill=&quot;%23f8f9fa&quot;/><text x=&quot;200&quot; y=&quot;150&quot; font-family=&quot;Arial&quot; font-size=&quot;18&quot; fill=&quot;%236c757d&quot; text-anchor=&quot;middle&quot;>Image non disponible</text></svg>'">
            </a>
            
            <div class="entry__body">
                <a href="${recipeUrl}" title="${title}" class="entry__title">
                    ${title}
                </a>
                <p class="entry__description">${description}</p>
            </div>
            
            <div class="entry__footer">
                <a class="entry__footer-link" href="${recipeUrl}" title="${title}">
                    View the recipe
                </a>
            </div>
        </div>
    `;
}

    showError(message) {
        this.recipesContainer.innerHTML = `<div class="error">${message}</div>`;
    }

    showNoRecipes() {
        this.recipesContainer.innerHTML = `
            <div class="no-recipes">
                <h3>Sorry, no recipes found</h3>
                <p>Please make sure your recipe folders contain <code>recipe.json</code> files.</p>
                <p><strong>Tip:</strong> Create a <code>recipes/index.json</code> file with the list of your folders:</p>
                <pre style="background: #f8f9fa; padding: 12px; border-radius: 4px; font-size: 0.9em; margin-top: 12px;">["cattle-ranch-casserole", "slow-cooker-cowboy-casserole"]</pre>
            </div>
        `;
    }

    // Méthode pour nettoyer les event listeners
    destroy() {
        if (this.scrollCleanup) {
            this.scrollCleanup();
        }
    }

    // NOUVEAU: Méthode publique pour obtenir la catégorie actuelle
    getCurrentCategory() {
        return this.currentCategorySlug;
    }

    // NOUVEAU: Méthode publique pour obtenir les recettes filtrées
    getFilteredRecipes() {
        return this.filteredRecipes;
    }

    // NOUVEAU: Méthode pour réinitialiser complètement le loader
    reset() {
        this.resetPagination();
        this.currentCategorySlug = null;
        this.filteredRecipes = [...this.allRecipes];
        this.hasMoreRecipes = true;
    }
}

// Variables globales
let recipeLoader;
let pageLoadWatcher;

class PageLoadWatcher {
    constructor() {
        this.initialized = false;
        this.attempts = 0;
        this.maxAttempts = 100;
        this.baseInterval = 100;
        this.watchInterval = null;
    }

    startWatching() {
        if (this.initialized) return;

        // console.log('Début de surveillance du chargement de page...');
        
        this.watchInterval = setInterval(() => {
            this.attempts++;
            
            const container = document.getElementById('items');
            const hasContent = container && container.innerHTML && !container.innerHTML.includes('Chargement des recettes');
            
            if (container) {
                this.initializeRecipeLoader();
            } else if (this.attempts >= this.maxAttempts) {
                // console.warn('Arrêt de la surveillance après', this.maxAttempts, 'tentatives');
                this.stopWatching();
            }
        }, this.baseInterval);
    }

    async initializeRecipeLoader() {
        if (this.initialized) return;
        
        this.stopWatching();
        this.initialized = true;
        
        try {
            // console.log('Initialisation du RecipeLoader avec support des catégories slug...');
            recipeLoader = new RecipeLoader('items');
            
            // Rendre accessible globalement
            window.recipeLoader = recipeLoader;
            
            const success = await recipeLoader.init();
            
            if (success) {
                // console.log('RecipeLoader initialisé avec succès - Support des catégories slug activé');
            } else {
                // console.error('Échec de l\'initialisation du RecipeLoader');
            }
        } catch (error) {
            // console.error('Erreur lors de l\'initialisation:', error);
        }
    }

    stopWatching() {
        if (this.watchInterval) {
            clearInterval(this.watchInterval);
            this.watchInterval = null;
        }
    }

    reset() {
        this.initialized = false;
        this.attempts = 0;
        this.stopWatching();
    }
}

// NOUVEAU: Fonction d'initialisation pour les pages de catégorie
function initRecipesCategoryPageFeatures(categorySlug) {
    // console.log('=== INIT CATEGORY FEATURES ===');
    // console.log('Category slug reçu:', categorySlug);
    // console.log('RecipeLoader exists:', !!recipeLoader);
    // console.log('RecipeLoader initialized:', recipeLoader?.initialized);
    // console.log('Nombre de recettes totales:', recipeLoader?.allRecipes?.length);
    
    if (recipeLoader && recipeLoader.initialized) {
        setTimeout(() => {
            // console.log('Applying filter...');
            recipeLoader.filterByCategory(categorySlug);
        }, 100);
    } else {
        // console.log('RecipeLoader pas prêt, attente...');
        // Reste du code...
    }
}
// Exposer la fonction globalement pour le router
window.initRecipesCategoryPageFeatures = initRecipesCategoryPageFeatures;

function initRecipeSystem() {
    if (!pageLoadWatcher) {
        pageLoadWatcher = new PageLoadWatcher();
    }
    pageLoadWatcher.startWatching();
}

// Points d'entrée multiples pour assurer l'initialisation
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRecipeSystem);
} else {
    setTimeout(initRecipeSystem, 50);
}

window.addEventListener('load', () => {
    setTimeout(initRecipeSystem, 100);
});

// Observer les changements DOM
if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
                const container = document.getElementById('items');
                if (container && !recipeLoader) {
                    initRecipeSystem();
                }
            }
        });
    });

    if (document.body) {
        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
}

// Fallback d'urgence
setTimeout(() => {
    if (!recipeLoader) {
        // console.log('Fallback: Tentative d\'initialisation après 3 secondes');
        initRecipeSystem();
    }
}, 3000);

// Fonction de recherche
function searchRecipes() {
    if (!recipeLoader || !recipeLoader.initialized) {
        // console.warn('RecipeLoader pas encore initialisé');
        initRecipeSystem();
        return;
    }
    
    const searchInput = document.getElementById('search-input') || document.getElementById('recipe-search');
    const categorySelect = document.getElementById('category-select') || document.getElementById('category-filter');
    const difficultySelect = document.getElementById('difficulty-select') || document.getElementById('difficulty-filter');
    
    const params = new URLSearchParams();
    
    if (searchInput && searchInput.value.trim()) {
        params.set('search', searchInput.value.trim());
    }
    
    if (categorySelect && categorySelect.value && categorySelect.value !== 'all') {
        params.set('category', categorySelect.value);
    }
    
    if (difficultySelect && difficultySelect.value && difficultySelect.value !== 'all') {
        params.set('difficulty', difficultySelect.value);
    }
    
    // Construire la nouvelle URL
    let newUrl;
    if (params.has('category')) {
        // Utiliser le nouveau format slug pour les catégories
        const categorySlug = params.get('category');
        params.delete('category');
        
        const otherParams = params.toString();
        newUrl = `${window.location.pathname}?page=recipes-category/${categorySlug}`;
        if (otherParams) {
            newUrl += `&${otherParams}`;
        }
    } else {
        newUrl = params.toString() ? 
               `${window.location.pathname}?page=recipes&${params.toString()}` : 
               `${window.location.pathname}?page=recipes`;
    }
    
    // Naviguer vers la nouvelle URL
    if (window.router && window.router.navigateTo) {
        if (params.has('category')) {
            window.router.navigateTo('recipes-category', { categorySlug: params.get('category') });
        } else {
            window.history.pushState({}, '', newUrl);
            recipeLoader.resetPagination();
            recipeLoader.applyUrlFilters();
        }
    } else {
        window.history.pushState({}, '', newUrl);
        recipeLoader.resetPagination();
        recipeLoader.applyUrlFilters();
    }
}

// Fonction de force init
function forceInitRecipeLoader() {
    // console.log('Force l\'initialisation du RecipeLoader...');
    
    if (pageLoadWatcher) {
        pageLoadWatcher.reset();
    }
    
    if (recipeLoader) {
        recipeLoader.destroy();
        recipeLoader = null;
    }
    
    window.recipeLoader = null;
    
    // Réinitialiser complètement
    pageLoadWatcher = new PageLoadWatcher();
    initRecipeSystem();
}

// NOUVEAU: Fonction pour naviguer vers une catégorie
function navigateToCategory(categorySlug) {
    if (window.router && window.router.loadCategoryPage) {
        window.router.loadCategoryPage(categorySlug);
    } else {
        window.location.href = window.createCategoryUrl ? 
                              window.createCategoryUrl(categorySlug) : 
                              `base.html?page=recipes-category/${categorySlug}`;
    }
}

// NOUVEAU: Fonction pour obtenir les statistiques de recettes
function getRecipeStats() {
    if (!recipeLoader) return null;
    
    return {
        total: recipeLoader.allRecipes.length,
        filtered: recipeLoader.filteredRecipes.length,
        displayed: recipeLoader.displayedRecipes.length,
        currentCategory: recipeLoader.currentCategorySlug,
        hasMore: recipeLoader.hasMoreRecipes,
        isLoading: recipeLoader.isLoading
    };
}

// Exposer toutes les fonctions publiques
window.searchRecipes = searchRecipes;
window.forceInitRecipeLoader = forceInitRecipeLoader;
window.navigateToCategory = navigateToCategory;
window.getRecipeStats = getRecipeStats;

// Debug: Log de l'état du système
// console.log('RecipeLoader system loaded with category slug support');
// console.log('Available functions:', {
//     searchRecipes: typeof searchRecipes,
//     forceInitRecipeLoader: typeof forceInitRecipeLoader,
//     navigateToCategory: typeof navigateToCategory,
//     getRecipeStats: typeof getRecipeStats,
//     initRecipesCategoryPageFeatures: typeof initRecipesCategoryPageFeatures
// });