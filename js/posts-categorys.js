class PostLoader {
    constructor(containerId = 'items') {
        this.containerId = containerId;
        this.postsContainer = null;
        this.postsPath = './posts/';
        this.categoriesPath = './categories/'; // NOUVEAU: Chemin vers les catégories
        this.allPosts = [];
        this.filteredPosts = [];
        this.displayedPosts = [];
        this.currentPage = 0;
        this.postsPerPage = 6;
        this.isLoading = false;
        this.hasMorePosts = true;
        this.initialized = false;
        this.currentCategorySlug = null;
        this.categoryMapping = {}; // NOUVEAU: Stockage du mapping des catégories
    }

    async init() {
        if (this.initialized) return;
        
        this.waitForContainer();
        
        if (!this.postsContainer) {
            // console.error(`Container avec l'ID '${this.containerId}' non trouvé`);
            return false;
        }

        // NOUVEAU: Charger le mapping des catégories en premier
        await this.loadCategoryMapping();
        
        // Charger toutes les recettes
        await this.loadAllPosts();
        
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
                // console.log('PostLoader: Catégorie reçue du router:', categorySlug);
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
            this.postsContainer = document.getElementById(this.containerId);
            if (this.postsContainer) {
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
        
        this.filteredPosts = this.allPosts.filter(post => {
            if (!post.category_id && !post.category) {
                // console.log(`✗ Recette "${post.title}" - pas de catégorie définie`);
                return false;
            }
            
            // console.log(`Vérification recette "${post.title}":`, {
            //     category_id: post.category_id,
            //     category: post.category,
            //     targetSlug: categorySlug,
            //     targetId: categoryId
            // });
            
            // 1. Correspondance exacte avec l'ID mappé
            if (post.category_id === categoryId) {
                // console.log(`✓ Correspondance ID mappé: "${post.title}"`);
                return true;
            }
            
            // 2. Correspondance directe avec le slug (fallback)
            if (post.category_id === categorySlug) {
                // console.log(`✓ Correspondance slug direct: "${post.title}"`);
                return true;
            }
            
            // 3. Correspondance avec le nom de catégorie slugifié
            if (post.category && this.slugify(post.category) === categorySlug) {
                // console.log(`✓ Correspondance nom slugifié: "${post.title}"`);
                return true;
            }
            
            // 4. Correspondance partielle (fallback pour compatibilité)
            if (post.category_id && post.category_id.toLowerCase().includes(categorySlug.toLowerCase())) {
                // console.log(`✓ Correspondance partielle ID: "${post.title}"`);
                return true;
            }
            
            if (post.category && post.category.toLowerCase().includes(categorySlug.toLowerCase())) {
                // console.log(`✓ Correspondance partielle nom: "${post.title}"`);
                return true;
            }
            
            // console.log(`✗ Aucune correspondance: "${post.title}"`);
            return false;
        });
        
        // console.log(`Filtrage terminé: ${this.filteredPosts.length} recettes trouvées pour "${categorySlug}"`);
        // console.log('==============================');
        
        this.hasMorePosts = this.filteredPosts.length > 0;
        this.displayInitialposts();
        this.updateFilterInfo({ category: categorySlug }, this.filteredPosts.length);
    }

    debugCategories() {
        // console.log('=== DEBUG CATEGORIES & MAPPING ===');
        // console.log('Mapping chargé:', this.categoryMapping);
        // console.log('Nombre total de recettes:', this.allPosts.length);
        
        const categories = new Set();
        const categoryDetails = [];
        
        this.allPosts.forEach(post => {
            if (post.category_id) categories.add(post.category_id);
            if (post.category) categories.add(post.category);
            
            categoryDetails.push({
                title: post.title,
                category_id: post.category_id,
                category: post.category,
                category_slugified: post.category ? this.slugify(post.category) : null
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
            const matchingposts = this.allPosts.filter(r => r.category_id === id);
            // console.log(`Slug "${slug}" (ID: ${id}) -> ${matchingposts.length} recettes`);
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
                if (this.isLoading || !this.hasMorePosts) {
                    return;
                }

                const scrollPosition = window.scrollY + window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                
                // Charger plus de recettes quand on est à 200px du bas
                if (page !== "home" && scrollPosition >= documentHeight - 200) {
                    this.loadMoreposts();
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
        this.displayedPosts = [];
        this.hasMorePosts = true;
        this.isLoading = false;
        
        // Vider le container
        if (this.postsContainer) {
            this.postsContainer.innerHTML = '';
        }
    }

    // MODIFIÉ: Parser les paramètres URL pour supporter le nouveau format
    getUrlParams() {
        const urlParams = new URLSearchParams(window.location.search);
        const pageParam = urlParams.get('page') || 'home';
        
        let category = urlParams.get('category'); // Ancien format
        let categorySlug = null;
        
        // NOUVEAU: Parser le format posts-category/slug
        if (pageParam.includes('/')) {
            const parts = pageParam.split('/');
            if (parts[0] === 'posts-category' && parts[1]) {
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
        let filteredPosts = [...this.allPosts];
        if (!params.category)
            params.category = '';      
        // // console.log('Slugs disponibles dans le mapping:', params.category);
        // // console.log('IDs dans le mapping:', Object.values(params.category));
        // // console.log('keys dans le mapping:', this.getValueByKey(this.categoryMapping, params.category));
        
        // Vérifier les correspondances
        // // console.log('=== VÉRIFICATION CORRESPONDANCES ===');
        // Object.keys(this.categoryMapping).forEach(slug => {
        //     const id = this.categoryMapping[slug];
        //     const matchingposts = this.allPosts.filter(r => r.category_id === id);
        //     // console.log(`Slug "${slug}" (ID: ${id}) -> ${matchingposts.length} recettes`);
        // });



        // console.log('=== APPLICATION DES FILTRES URL ===');
        // console.log('Paramètres détectés:', params.category);
        // MODIFIÉ: Filtrer par catégorie (nouveau format prioritaire)
        if (params.categorySlug || params.category) {
            const categoryToFilter = this.getValueByKey(this.categoryMapping, params.category);
            this.currentCategorySlug = categoryToFilter;
            
            filteredPosts = filteredPosts.filter(post => {
                if (!post.category_id && !post.category) return false;
                
                // Correspondance exacte avec category_id
                if (post.category_id === categoryToFilter) {
                    return true;
                }
                
                // Correspondance avec nom slugifié
                if (this.slugify(post.category || '') === categoryToFilter) {
                    return true;
                }
                
                // Correspondance partielle
                if (post.category_id && post.category_id.includes(categoryToFilter)) {
                    return true;
                }
                
                if (post.category && post.category.toLowerCase().includes(categoryToFilter.toLowerCase())) {
                    return true;
                }
                
                return false;
            });
            
            // console.log(`Filtrage par catégorie "${categoryToFilter}": ${filteredPosts.length} recettes trouvées`);
        }

        // Filtrer par recherche
        if (params.search) {
            const searchTerm = params.search.toLowerCase();
            filteredPosts = filteredPosts.filter(post => 
                post.title.toLowerCase().includes(searchTerm) ||
                post.description.toLowerCase().includes(searchTerm) ||
                post.category.toLowerCase().includes(searchTerm) ||
                (post.ingredients && post.ingredients.some(ing => 
                    ing.toLowerCase().includes(searchTerm)
                )) ||
                (post.tips && post.tips.toLowerCase().includes(searchTerm))
            );
        }

        // Filtrer par difficulté
        if (params.difficulty) {
            filteredPosts = filteredPosts.filter(post => 
                post.difficulty && 
                post.difficulty.toLowerCase() === params.difficulty.toLowerCase()
            );
        }

        // Mettre à jour les recettes filtrées
        this.filteredPosts = filteredPosts;
        this.hasMorePosts = this.filteredPosts.length > 0;
        
        // Afficher les premières recettes
        this.displayInitialposts();
        this.updateFilterInfo(params, this.filteredPosts.length);
    }

    // Afficher les premières recettes selon la page
    displayInitialposts() {     
        this.resetPagination();
        this.loadMoreposts();        
    }

    // Charger plus de recettes (6 par 6)
    async loadMoreposts() {
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

        if (this.isLoading || !this.hasMorePosts) {
            return;
        }

        this.isLoading = true;
        this.showLoadingIndicator();

        try {
            const startIndex = this.currentPage * this.postsPerPage;
            const endIndex = startIndex + this.postsPerPage;
            const newposts = this.filteredPosts.slice(startIndex, endIndex);
            
            if (newposts.length === 0) {
                this.hasMorePosts = false;
                return;
            }

            // Simuler un petit délai pour le loading
            await new Promise(resolve => setTimeout(resolve, 300));     
            
            // Ajouter les nouvelles recettes
            this.displayedPosts.push(...newposts);
            this.appendpostsToDOM(newposts);
            
            this.currentPage++;
            this.hasMorePosts = endIndex < this.filteredPosts.length;

            // console.log(`Page ${this.currentPage} chargée: ${newposts.length} recettes (${this.displayedPosts.length}/${this.filteredPosts.length} total)`);
            
        } catch (error) {
            // console.error('Erreur lors du chargement de plus de recettes:', error);
            this.showError('Erreur lors du chargement des recettes supplémentaires');
        } finally {
            this.hideLoadingIndicator();
            this.isLoading = false;
        }
    }

    // Ajouter les recettes au DOM
    appendpostsToDOM(posts) {
        if (!this.postsContainer) {
            // console.error('Container des recettes non disponible');
            return;
        }

        if (posts.length === 0) {
            if (this.displayedPosts.length === 0) {
                const categoryInfo = this.currentCategorySlug ? 
                    ` pour la catégorie "${this.currentCategorySlug}"` : '';
                
                this.postsContainer.innerHTML = `
                    <div class="no-posts">
                        <h3>Aucune recette trouvée</h3>
                        <p>Aucune recette ne correspond aux filtres sélectionnés${categoryInfo}</p>
                        ${this.currentCategorySlug ? `
                            <button onclick="window.router.loadPage('posts')" class="btn-secondary" style="
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

       
        const postsHTML = posts.map(post => this.createpostHTML(post)).join('');             
        this.postsContainer.insertAdjacentHTML('beforeend', postsHTML);
          
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

        if (this.postsContainer && this.postsContainer.parentNode) {
            this.postsContainer.parentNode.appendChild(loader);
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

        if (activeFilters.length > 0 || resultCount !== this.allPosts.length) {
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
            //             <button class="clear-filters" onclick="postLoader.clearFilters()" style="
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
            if (this.postsContainer && this.postsContainer.parentNode) {
                this.postsContainer.parentNode.insertBefore(filterInfo, this.postsContainer);
            }
        }
    }

    clearFilters() {
        // Rediriger vers la page posts normale
        if (window.router && window.router.loadPage) {
            window.router.loadPage('posts');
        } else {
            window.history.pushState({}, '', window.location.pathname + '?page=posts');
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

    async getpostFolders() {
        try {
            const indexResponse = await fetch(`${this.postsPath}index.json`);
            if (indexResponse.ok) {

                const indexData = await indexResponse.json();
                return indexData.folders || indexData;
            }
        } catch (error) {

            // console.log('Fichier index.json non trouvé, scan automatique...');
        }

        return await this.scanpostFolders();
    }

    async scanpostFolders() {
        const folders = [];
        
        const commonpostNames = [
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

        for (const folderName of commonpostNames) {
            try {
                const response = await fetch(`${this.postsPath}${folderName}/post.json`, {
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

    async loadAllPosts() {
        try {
            const params = new URLSearchParams(window.location.search);
            const pageParam = params.get("page") || "home";
            
            // Détecter la page actuelle
            let currentPage = pageParam;
            if (pageParam.includes('/')) {
                currentPage = pageParam.split('/')[0];
            }
            
            const postFolders = await this.getpostFolders();
            
            if (postFolders.length === 0) {
                this.showNoposts();
                return;
            }

            // console.log(`${postFolders.length} dossiers de recettes trouvés pour la page "${currentPage}":`, postFolders);

            const postPromises = postFolders.map(folder => 
                this.loadPostData(folder)
            );
            
            const posts = await Promise.all(postPromises);
            const validposts = posts.filter(post => post !== null && post.isOnline === true);
            
            
            if (validposts.length === 0) {
                this.showError('Aucune recette valide trouvée dans les dossiers spécifiés');
                return;
            }

            // Trier par date de création (plus récent en premier)
            validposts.sort((a, b) => {
                const dateA = new Date(a.createdAt || a.updatedAt || Date.now());
                const dateB = new Date(b.createdAt || b.updatedAt || Date.now());
                return dateB - dateA; // Ordre décroissant (plus récent en premier)
            });
            
            // Sur la page home, prendre seulement les 6 premières après tri par date
            if (currentPage === "home") {
                this.allPosts = validposts.slice(0, 6);
                // console.log(`Page home: ${this.allPosts.length} recettes les plus récentes affichées`);
            } else {
                this.allPosts = validposts;
            }
            
            // console.log(`Recettes triées par date de création (${this.allPosts.length} recettes)`);

        } catch (error) {
            // console.error('Erreur lors du chargement des recettes:', error);
            this.showError('Erreur lors du chargement des recettes');
        }
    }

    // Méthode displayInitialposts modifiée pour gérer la page home et les catégories
    displayInitialposts() {
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
            // (qui sont déjà limitées à 6 dans loadAllPosts)
            this.displayedPosts = [...this.filteredPosts];
            this.appendpostsToDOM(this.displayedPosts);
            this.hasMorePosts = false; // Pas de load more sur home
            // console.log(`Page home: ${this.displayedPosts.length} recettes affichées (pas de pagination)`);
        } else {
            // Sur les autres pages, utiliser la pagination normale
            this.loadMoreposts();
        }
    }

    async loadPostData(folderName) {
        try {
            const jsonUrl = `${this.postsPath}${folderName}/post.json`;
            const jsonResponse = await fetch(jsonUrl);
            
            if (!jsonResponse.ok) {
                // console.warn(`Impossible de charger ${folderName}/post.json`);
                return null;
            }
            
            const postData = await jsonResponse.json();
            
            if (!postData.title) {
                // console.warn(`Recette ${folderName}: titre manquant`);
                return null;
            }
            
            const mainImage = this.getMainImageFromData(postData);
            const prepTime = postData.prep_time ? `${postData.prep_time} min` : null;
            const cookTime = postData.cook_time ? `${postData.cook_time} min` : null;
            const totalTime = postData.total_time ? `${postData.total_time} min` : null;
            
            return {
                id: postData.id,
                slug: postData.slug || folderName,
                folderName,
                title: postData.title,
                description: postData.description || 'Description non disponible',
                category: this.getCategoryName(postData.category_id) || 'Général',
                category_id: postData.category_id, // IMPORTANT: Garder l'ID original
                difficulty: postData.difficulty || 'Non spécifié',
                prepTime,
                cookTime,
                totalTime,
                servings: postData.servings,
                ingredients: postData.ingredients || [],
                instructions: postData.instructions || [],
                tips: postData.tips,
                mainImage,
                images: postData.images || [],
                hasRichStructure: postData.has_rich_structure || false,
                createdAt: postData.createdAt,
                updatedAt: postData.updatedAt,
                ...postData
            };
            
        } catch (error) {
            // console.error(`Erreur lors du chargement de la recette ${folderName}:`, error);
            return null;
        }
    }

    getMainImageFromData(postData) {
        if (postData.image_path) {
            return `./${postData.image_path}`;
        }
        
        if (postData.images && Array.isArray(postData.images)) {
            const mainImg = postData.images.find(img => img.type === 'main');
            if (mainImg && mainImg.filePath) {
                return `./${mainImg.filePath}`;
            }
            
            if (postData.images.length > 0 && postData.images[0].filePath) {
                return `./${postData.images[0].filePath}`;
            }
        }
        
        if (postData.image) {
            const imageDir = postData.image_dir || `${postData.slug || postData.folderName}/images`;
            return `./posts/${imageDir}/${postData.image}`;
        }
        
        return this.findMainImage(postData.slug || postData.folderName);
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
        
        const imagesPath = `${this.postsPath}${folderName}/images/`;
        
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

createpostHTML(post) {
    // Utiliser des valeurs par défaut pour éviter les erreurs de déstructuration
    const slug = post.slug || post.folderName || post.id || 'post';
    const folderName = post.folderName || post.slug || post.id || 'post';
    const title = post.title || 'Titre non disponible';
    const description = post.description || 'Description non disponible';
    const category = post.category || 'Général';
    const difficulty = post.difficulty || 'Non spécifié';
    const mainImage = post.mainImage || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"><rect width="400" height="300" fill="%23f8f9fa"/><text x="200" y="150" font-family="Arial" font-size="18" fill="%236c757d" text-anchor="middle">Image non disponible</text></svg>';

    const postUrl = `posts/${slug}`;
    
    return `
        <div class="entry" data-category="${this.slugify(category)}" data-difficulty="${difficulty.toLowerCase()}">
            <a class="entry__img" href="${postUrl}" title="${title}">
                <img alt="${title}" 
                     loading="lazy" 
                     decoding="async" 
                     width="400" 
                     height="300" 
                     src="${mainImage}"
                     onerror="this.src='data:image/svg+xml,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;400&quot; height=&quot;300&quot; viewBox=&quot;0 0 400 300&quot;><rect width=&quot;400&quot; height=&quot;300&quot; fill=&quot;%23f8f9fa&quot;/><text x=&quot;200&quot; y=&quot;150&quot; font-family=&quot;Arial&quot; font-size=&quot;18&quot; fill=&quot;%236c757d&quot; text-anchor=&quot;middle&quot;>Image non disponible</text></svg>'">
            </a>
            
            <div class="entry__body">
                <a href="${postUrl}" title="${title}" class="entry__title">
                    ${title}
                </a>
                <p class="entry__description">${description}</p>
            </div>
            
            <div class="entry__footer">
                <a class="entry__footer-link" href="${postUrl}" title="${title}">
                    View the post
                </a>
            </div>
        </div>
    `;
}

    showError(message) {
        this.postsContainer.innerHTML = `<div class="error">${message}</div>`;
    }

    showNoposts() {
        this.postsContainer.innerHTML = `
            <div class="no-posts">
                <h3>Sorry, no posts found</h3>
                <p>Please make sure your post folders contain <code>post.json</code> files.</p>
                <p><strong>Tip:</strong> Create a <code>posts/index.json</code> file with the list of your folders:</p>
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
    getFilteredposts() {
        return this.filteredPosts;
    }

    // NOUVEAU: Méthode pour réinitialiser complètement le loader
    reset() {
        this.resetPagination();
        this.currentCategorySlug = null;
        this.filteredPosts = [...this.allPosts];
        this.hasMorePosts = true;
    }
}

// Variables globales
let postLoader;
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
                this.initializePostLoader();
            } else if (this.attempts >= this.maxAttempts) {
                // console.warn('Arrêt de la surveillance après', this.maxAttempts, 'tentatives');
                this.stopWatching();
            }
        }, this.baseInterval);
    }

    async initializePostLoader() {
        if (this.initialized) return;
        
        this.stopWatching();
        this.initialized = true;
        
        try {
            // console.log('Initialisation du PostLoader avec support des catégories slug...');
            postLoader = new PostLoader('items');
            
            // Rendre accessible globalement
            window.postLoader = postLoader;
            
            const success = await postLoader.init();
            
            if (success) {
                // console.log('PostLoader initialisé avec succès - Support des catégories slug activé');
            } else {
                // console.error('Échec de l\'initialisation du PostLoader');
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
function initPostsCategoryPageFeatures(categorySlug) {
    // console.log('=== INIT CATEGORY FEATURES ===');
    // console.log('Category slug reçu:', categorySlug);
    // console.log('PostLoader exists:', !!postLoader);
    // console.log('PostLoader initialized:', postLoader?.initialized);
    // console.log('Nombre de recettes totales:', postLoader?.allPosts?.length);
    
    if (postLoader && postLoader.initialized) {
        setTimeout(() => {
            // console.log('Applying filter...');
            postLoader.filterByCategory(categorySlug);
        }, 100);
    } else {
        // console.log('PostLoader pas prêt, attente...');
        // Reste du code...
    }
}
// Exposer la fonction globalement pour le router
window.initPostsCategoryPageFeatures = initPostsCategoryPageFeatures;

function initpostsystem() {
    if (!pageLoadWatcher) {
        pageLoadWatcher = new PageLoadWatcher();
    }
    pageLoadWatcher.startWatching();
}

// Points d'entrée multiples pour assurer l'initialisation
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initpostsystem);
} else {
    setTimeout(initpostsystem, 50);
}

window.addEventListener('load', () => {
    setTimeout(initpostsystem, 100);
});

// Observer les changements DOM
if (typeof MutationObserver !== 'undefined') {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
                const container = document.getElementById('items');
                if (container && !postLoader) {
                    initpostsystem();
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
    if (!postLoader) {
        // console.log('Fallback: Tentative d\'initialisation après 3 secondes');
        initpostsystem();
    }
}, 3000);

// Fonction de recherche
function searchposts() {
    if (!postLoader || !postLoader.initialized) {
        // console.warn('PostLoader pas encore initialisé');
        initpostsystem();
        return;
    }
    
    const searchInput = document.getElementById('search-input') || document.getElementById('post-search');
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
        newUrl = `${window.location.pathname}?page=posts-category/${categorySlug}`;
        if (otherParams) {
            newUrl += `&${otherParams}`;
        }
    } else {
        newUrl = params.toString() ? 
               `${window.location.pathname}?page=posts&${params.toString()}` : 
               `${window.location.pathname}?page=posts`;
    }
    
    // Naviguer vers la nouvelle URL
    if (window.router && window.router.navigateTo) {
        if (params.has('category')) {
            window.router.navigateTo('posts-category', { categorySlug: params.get('category') });
        } else {
            window.history.pushState({}, '', newUrl);
            postLoader.resetPagination();
            postLoader.applyUrlFilters();
        }
    } else {
        window.history.pushState({}, '', newUrl);
        postLoader.resetPagination();
        postLoader.applyUrlFilters();
    }
}

// Fonction de force init
function forceInitPostLoader() {
    // console.log('Force l\'initialisation du PostLoader...');
    
    if (pageLoadWatcher) {
        pageLoadWatcher.reset();
    }
    
    if (postLoader) {
        postLoader.destroy();
        postLoader = null;
    }
    
    window.postLoader = null;
    
    // Réinitialiser complètement
    pageLoadWatcher = new PageLoadWatcher();
    initpostsystem();
}

// NOUVEAU: Fonction pour naviguer vers une catégorie
function navigateToCategory(categorySlug) {
    if (window.router && window.router.loadCategoryPage) {
        window.router.loadCategoryPage(categorySlug);
    } else {
        window.location.href = window.createCategoryUrl ? 
                              window.createCategoryUrl(categorySlug) : 
                              `base.html?page=posts-category/${categorySlug}`;
    }
}

// NOUVEAU: Fonction pour obtenir les statistiques de recettes
function getpoststats() {
    if (!postLoader) return null;
    
    return {
        total: postLoader.allPosts.length,
        filtered: postLoader.filteredPosts.length,
        displayed: postLoader.displayedPosts.length,
        currentCategory: postLoader.currentCategorySlug,
        hasMore: postLoader.hasMorePosts,
        isLoading: postLoader.isLoading
    };
}

// Exposer toutes les fonctions publiques
window.searchposts = searchposts;
window.forceInitPostLoader = forceInitPostLoader;
window.navigateToCategory = navigateToCategory;
window.getpoststats = getpoststats;

// Debug: Log de l'état du système
// console.log('PostLoader system loaded with category slug support');
// console.log('Available functions:', {
//     searchposts: typeof searchposts,
//     forceInitPostLoader: typeof forceInitPostLoader,
//     navigateToCategory: typeof navigateToCategory,
//     getpoststats: typeof getpoststats,
//     initPostsCategoryPageFeatures: typeof initPostsCategoryPageFeatures
// });