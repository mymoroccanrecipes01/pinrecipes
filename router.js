// Système de routage corrigé pour éviter le rechargement de page

// Configuration des pages
const pages = {
    home: {
        title: globalThis.homepageTitle + ' - ' + globalThis.homepageTagline,
        content: 'pages/home-content.html'
    },
    about: {
        title: 'About Us - ' + globalThis.homepageTitle,
        content: 'pages/about-content.html'
    },
    posts: {
        title: 'Posts - ' + globalThis.homepageTitle,
        content: 'pages/posts-content.html'
    },
   'posts-category': {
        title: 'Post Categories - ' + globalThis.homepageTitle,
        content: 'pages/posts-category-content.html',
        // Fonction pour extraire le slug de la catégorie
        getParams: function(pageValue) {
            // pageValue = "posts-category/box-dessert"
            const parts = pageValue.split('/');

            if (parts.length > 1) {

                return { categorySlug: parts[1] };
            }
            return {};
        }
    },
    contact: {
        title: 'Contact - ' + globalThis.homepageTitle,
        content: 'pages/contact-content.html'
    },
    'privacy-policy': {
        title: 'Privacy Policy - ' + globalThis.homepageTitle,
        content: 'pages/privacy-policy-content.html'
    },
    'post-detail': {
        title: 'Post Detail - ' + globalThis.homepageTitle,
        content: 'pages/post-detail-content.html'
    }
};

// Variables globales
let currentPage = 'home';
let currentPostId = null;
let currentCategory = null;

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    initializeRouter();
});

// Initialiser le routeur
function initializeRouter() {
    // Intercepter TOUS les clics sur les liens
    document.addEventListener('click', handleLinkClick);
    
    // Récupérer la page et les paramètres depuis l'URL
    const urlParams = new URLSearchParams(window.location.search);
    const requestedPage = urlParams.get('page') || 'home';
    const postId = urlParams.get('id');
    const category = urlParams.get('cat'); // Ancien format pour compatibilité
    
    // NOUVEAU: Parsing du format slug category
    let pageName = requestedPage;
    let categorySlug = null;
    
    // Si la page contient un slash (ex: posts-category/box-dessert)
    if (requestedPage.includes('/')) {
        const parts = requestedPage.split('/');
        pageName = parts[0]; // "posts-category"
        categorySlug = parts[1]; // "box-dessert"
    }
    
    // Si c'est une page de détail de recette
    if (pageName === 'post-detail' && postId) {
        currentPostId = parseInt(postId);
        loadPostDetailPage(postId, false);
    } else if (pageName === 'posts-category' && (categorySlug || category)) {
        // Si c'est une page de catégorie de recettes (nouveau format ou ancien)
        const categoryToLoad = categorySlug || category;
        currentCategory = categoryToLoad;
        loadCategoryPageInternal(categoryToLoad, false);
    } else {
        // Charger la page normale
        loadPage(pageName, false);
    }
    
    // Écouter les changements d'URL (bouton retour)
    window.addEventListener('popstate', function(event) {
        const urlParams = new URLSearchParams(window.location.search);
        const pageParam = urlParams.get('page') || 'home';
        const postId = urlParams.get('id');
        const category = urlParams.get('cat'); // Ancien format
        
        // NOUVEAU: Parsing du format slug
        let pageName = pageParam;
        let categorySlug = null;
        
        if (pageParam.includes('/')) {
            const parts = pageParam.split('/');
            pageName = parts[0];
            categorySlug = parts[1];
        }
        
        if (pageName === 'post-detail' && postId) {
            loadPostDetailPage(postId, false);
        } else if (pageName === 'posts-category' && (categorySlug || category)) {
            const categoryToLoad = categorySlug || category;
            loadCategoryPageInternal(categoryToLoad, false);
        } else {
            loadPage(pageName, false);
        }
    });
}

// Gestionnaire centralisé pour tous les clics de liens
function handleLinkClick(event) {
    const link = event.target.closest('a');
    if (!link) return;
    
    // Récupérer l'URL du lien
    const href = link.getAttribute('href');
    if (!href) return;
    
    // NOUVEAU: Vérifier si c'est le format posts-category/slug
    if (href.includes('page=posts-category/')) {
        event.preventDefault();
        
        const url = new URL(href, window.location.origin);
        const pageParam = url.searchParams.get('page');
        
        if (pageParam && pageParam.includes('/')) {
            const parts = pageParam.split('/');
            const categorySlug = parts[1];
            if (categorySlug) {
                loadCategoryPageInternal(categorySlug);
            }
        }
        return;
    }
    
    // Vérifier si c'est un lien interne avec paramètre page
    if (href.startsWith('base.html?') || href.startsWith('?page=')) {
       
        
        const url = new URL(href, window.location.origin);
        const page = url.searchParams.get('page');
        const postId = url.searchParams.get('id');
        const category = url.searchParams.get('cat'); // Ancien format
        
        if (page === 'post-detail' && postId) {
            loadPostDetailPage(postId);
        } else if (page === 'posts-category' && category) {
            loadCategoryPageInternal(category);
        } else if (page) {
            loadPage(page);
        }
        return;
    }
    
    // Vérifier si c'est un lien avec data-page
    const dataPage = link.getAttribute('data-page');
    if (dataPage) {
        event.preventDefault();
        loadPage(dataPage);
        return;
    }
    
    // Vérifier si c'est un lien JavaScript (onclick)
    const onclick = link.getAttribute('onclick');
    if (onclick && onclick.includes('openPost')) {
        event.preventDefault();
        // Extraire l'ID de la recette depuis onclick
        const match = onclick.match(/openPost\((\d+)\)/);
        if (match) {
            loadPostDetailPage(match[1]);
        }
        return;
    }
    
    // Vérifier si c'est un lien JavaScript pour les catégories
    if (onclick && onclick.includes('loadCategoryPage')) {
        event.preventDefault();
        // Extraire la catégorie depuis onclick
        const match = onclick.match(/loadCategoryPage\('([^']+)'\)/);
        if (match) {
            loadCategoryPageInternal(match[1]);
        }
        return;
    }
    
    // Pour tous les autres liens internes, empêcher le rechargement
    if (href.startsWith('#') || href.startsWith('/') || href.includes(window.location.hostname)) {
        // Laisser passer les liens externes et les ancres
        if (href.startsWith('http') && !href.includes(window.location.hostname)) {
            return; // Lien externe, laisser la navigation normale
        }
        if (href.startsWith('#')) {
            return; // Ancre, laisser le comportement normal
        }
    }
}


// Charger une page normale
async function loadPage(pageName, addToHistory = true) {
    if (!pages[pageName]) {
        console.error(`Page "${pageName}" not found`);
        pageName = 'home';
    }
    
    const pageConfig = pages[pageName];
    
    try {
        showLoadingIndicator();
        
        const response = await fetch(pageConfig.content);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const content = await response.text();
        
        // Mettre à jour le contenu
        const mainContent = document.getElementById('main-content');
        mainContent.innerHTML = content;       
        // Mettre à jour le titre
        document.title = pageConfig.title;
        
        // Mettre à jour l'URL
        if (addToHistory) {
            const newUrl = `${window.location.pathname}?page=${pageName}`;
            window.history.pushState({ page: pageName }, pageConfig.title, newUrl);
        }
        
        // Mettre à jour la navigation active
        updateActiveNavigation(pageName);
        
        currentPage = pageName;
        currentPostId = null;
        currentCategory = null;
        
        // Initialiser les fonctionnalités spécifiques
        initializePageFeatures(pageName);        
        hideLoadingIndicator();
        window.scrollTo(0, 0);
        
    } catch (error) {
        console.error('Erreur lors du chargement de la page:', error);
        hideLoadingIndicator();
        showErrorPage(pageName);
    }
}


// Charger une page de catégorie de recettes
async function loadCategoryPageInternal(category, addToHistory = true) {
    try {
        showLoadingIndicator();
        
        // Charger le contenu de la page de catégorie
        const response = await fetch('pages/posts-category-content.html');
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const content = await response.text();
        
        // Mettre à jour le contenu
        const mainContent = document.getElementById('main-content');
        mainContent.innerHTML = content;
        
        // Mettre à jour le titre avec le nom de la catégorie
        document.title = `Catégorie ${category} - Simple posts`;
        
        // NOUVEAU: Mettre à jour l'URL avec le format slug
        if (addToHistory) {
            const newUrl = `${window.location.pathname}?page=posts-category/${category}`;
            window.history.pushState({ 
                page: 'posts-category', 
                categorySlug: category 
            }, `Catégorie ${category}`, newUrl);
        }
        
        // Enlever la classe active de tous les liens
        const navLinks = document.querySelectorAll('.nav a');
        navLinks.forEach(link => link.classList.remove('active'));
        
        currentPage = 'posts-category';
        currentCategory = category;
        currentPostId = null;
        
        // NOUVEAU: Déclencher l'événement avec le slug de catégorie
        window.dispatchEvent(new CustomEvent('pageLoaded', { 
            detail: { params: { categorySlug: category } }
        }));
        
        // Initialiser la page de catégorie avec la catégorie
        if (typeof window.initPostsCategoryPageFeatures === 'function') {
            window.initPostsCategoryPageFeatures(category);
        }
        
        // Si le postLoader est disponible, filtrer les recettes par catégorie
        if (window.postLoader) {
            setTimeout(() => {
                window.postLoader.filterByCategory(category);
            }, 100);
        }
        
        hideLoadingIndicator();
        window.scrollTo(0, 0);
        
    } catch (error) {
        console.error('Erreur lors du chargement de la page de catégorie:', error);
        hideLoadingIndicator();
        showErrorPage('posts-category');
    }
}

// Charger une page de détail de recette
async function loadPostDetailPage(postId, addToHistory = true) {
    try {
        showLoadingIndicator();
        
        // Charger le contenu de la page de détail
        const response = await fetch('pages/post-detail-content.html');
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const content = await response.text();
        
        // Mettre à jour le contenu
        const mainContent = document.getElementById('main-content');
        mainContent.innerHTML = content;
        
        // Mettre à jour l'URL
        if (addToHistory) {
            const newUrl = `${window.location.pathname}posts/${postId}`;
            window.history.pushState({ page: 'post-detail', recipe: postId }, 'Recipe Detail', newUrl);
        }
        
        // Enlever la classe active de tous les liens
        const navLinks = document.querySelectorAll('.nav a');
        navLinks.forEach(link => link.classList.remove('active'));
        
        currentPage = 'post-detail';
        currentPostId = postId;
        currentCategory = null;
        
        // Initialiser la page de recette avec l'ID
        if (typeof window.initializePostDetailPage === 'function') {
            window.initializePostDetailPage(postId);
        }
        
        hideLoadingIndicator();
        window.scrollTo(0, 0);
        
    } catch (error) {
        console.error('Erreur lors du chargement de la page de recette:', error);
        hideLoadingIndicator();
        showErrorPage('post-detail');
    }
}

// Mettre à jour la navigation active
function updateActiveNavigation(pageName) {
    const navLinks = document.querySelectorAll('.nav a, nav a');
    navLinks.forEach(link => {
        link.classList.remove('active');
        
        // Vérifier data-page
        if (link.getAttribute('data-page') === pageName) {
            link.classList.add('active');
        }
        
        // Vérifier href avec ?page=
        const href = link.getAttribute('href');
        if (href && href.includes(`page=${pageName}`)) {
            link.classList.add('active');
        }
    });
}

// Initialiser les fonctionnalités spécifiques à chaque page
function initializePageFeatures(pageName) {
    // Attendre que le DOM soit mis à jour
    setTimeout(() => {
        switch (pageName) {
            case 'home':
                if (typeof window.initHomePageFeatures === 'function') {
                    window.initHomePageFeatures();
                }
                break;
            case 'posts':
                if (typeof window.initPostsPageFeatures === 'function') {
                    window.initPostsPageFeatures();
                }
                // Ajouter les gestionnaires d'événements pour les cartes de post
                setupPostCardListeners();
                break;
            case 'posts-category':
                if (typeof window.initPostsCategoryPageFeatures === 'function') {
                    window.initPostsCategoryPageFeatures(currentCategory);
                }
                setupPostCardListeners();
                break;
            case 'about':
                if (typeof window.initAboutPageFeatures === 'function') {
                    window.initAboutPageFeatures();
                }
                break;
            case 'contact':
                if (typeof window.initContactPageFeatures === 'function') {
                    window.initContactPageFeatures();
                }
                break;
        }
        
        // Toujours configurer les liens de post après le chargement
        setupPostCardListeners();
    }, 100);
}

// Configurer les listeners pour les cartes de post
function setupPostCardListeners() {
    setTimeout(() => {
        const postCards = document.querySelectorAll('.post-card');
        postCards.forEach(card => {
            // Vérifier si le listener n'est pas déjà ajouté
            if (!card.hasAttribute('data-router-handled')) {
                card.setAttribute('data-router-handled', 'true');
                
                card.addEventListener('click', function(e) {
                    // Empêcher la propagation si c'est déjà géré par un lien
                    if (e.target.closest('a')) return;
                    
                    // Essayer de trouver l'ID de la recette
                    let postId = this.getAttribute('data-post-id');
                    
                    // Si pas d'attribut data-post-id, essayer d'extraire depuis onclick
                    if (!postId && this.getAttribute('onclick')) {
                        const onclickValue = this.getAttribute('onclick');
                        const match = onclickValue.match(/openPost\((\d+)\)/);
                        if (match) {
                            postId = match[1];
                        }
                    }
                    
                    // Si c'est l'ID textuel, utiliser le slug de la recette
                    if (!postId) {
                        postId = this.getAttribute('data-post-slug') || 
                                  this.querySelector('[data-post-id]')?.getAttribute('data-post-id');
                    }
                    
                    if (postId) {
                        loadPostDetailPage(postId);
                    }
                });
            }
        });
    }, 200);
}

// Fonction globale pour ouvrir une recette (utilisée dans les pages)
window.openPost = function(postId) {
    loadPostDetailPage(postId);
};

// Fonction globale pour charger une catégorie (utilisée dans les pages)
// ✅ CORRECTION - Utilisez le nom complet pour éviter la confusion
window.loadCategoryPage = function(category) {
    // Utiliser le router directement pour éviter la récursion
    if (window.router && window.router.loadCategoryPage) {
        window.router.loadCategoryPageInternal(category);
    } else {
        // Si pas de router, navigation manuelle
        window.location.href = `base.html?page=posts-category/${category}`;
    }
};

// Fonction pour naviguer programmatiquement
window.navigateTo = function(page, params = {}) {
    if (page === 'post-detail' && (params.id || params.recipe)) {
        loadPostDetailPage(params.id || params.recipe);
    } else if (page === 'posts-category' && (params.category || params.cat || params.categorySlug)) {
        loadCategoryPageInternal(params.category || params.cat || params.categorySlug);
    } else {
        loadPage(page);
    }
};

// NOUVEAU: Fonction utilitaire pour créer des URLs de catégorie avec le format slug
window.createCategoryUrl = function(categorySlug) {
    return `base.html?page=posts-category/${categorySlug}`;
};

// Fonction utilitaire pour créer des URLs de recette
window.createPostUrl = function(postId) {
    return `posts/${postId}`;
};

// Afficher l'indicateur de chargement
function showLoadingIndicator() {
    let loader = document.getElementById('page-loader');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'page-loader';
        loader.innerHTML = `
            <div class="loader-content">
                <div class="spinner"></div>
                <p>Chargement...</p>
            </div>
        `;
        loader.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.3s ease;
        `;
        
        const loaderContent = loader.querySelector('.loader-content');
        loaderContent.style.cssText = `
            text-align: center;
            color: #333;
        `;
        
        const spinner = loader.querySelector('.spinner');
        spinner.style.cssText = `
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #ff6b6b;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        `;
        
        // Ajouter l'animation CSS
        if (!document.getElementById('loader-styles')) {
            const style = document.createElement('style');
            style.id = 'loader-styles';
            style.textContent = `
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            `;
            document.head.appendChild(style);
        }
        
        document.body.appendChild(loader);
    }
    
    loader.style.display = 'flex';
    setTimeout(() => {
        loader.style.opacity = '1';
    }, 10);
}

// Masquer l'indicateur de chargement
function hideLoadingIndicator() {
    const loader = document.getElementById('page-loader');
    if (loader) {
        loader.style.opacity = '0';
        setTimeout(() => {
            loader.style.display = 'none';
        }, 300);
    }
}

// Afficher une page d'erreur
function showErrorPage(attemptedPage) {
    const mainContent = document.getElementById('main-content');
    mainContent.innerHTML = `
        <section class="error-page">
            <div class="container">
                <div class="error-content">
                    <h1>Oops! Quelque chose s'est mal passé</h1>
                    <p>Nous n'avons pas pu charger la page "${attemptedPage}". Veuillez réessayer.</p>
                    <div class="error-actions">
                        <button onclick="loadPage('home')" class="btn btn-primary">
                            Retour à l'accueil
                        </button>
                        <button onclick="location.reload()" class="btn btn-secondary">
                            Recharger la page
                        </button>
                    </div>
                </div>
            </div>
        </section>
    `;
    
    // Ajouter les styles pour la page d'erreur
    if (!document.getElementById('error-page-styles')) {
        const style = document.createElement('style');
        style.id = 'error-page-styles';
        style.textContent = `
            .error-page {
                padding: 100px 0;
                text-align: center;
                min-height: 60vh;
                display: flex;
                align-items: center;
            }
            .error-content h1 {
                font-size: 36px;
                color: #333;
                margin-bottom: 20px;
            }
            .error-content p {
                font-size: 18px;
                color: #666;
                margin-bottom: 30px;
            }
            .error-actions {
                display: flex;
                gap: 20px;
                justify-content: center;
                flex-wrap: wrap;
            }
            .error-actions .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
                display: inline-block;
                transition: all 0.3s ease;
            }
            .error-actions .btn-primary {
                background: #ff6b6b;
                color: white;
            }
            .error-actions .btn-primary:hover {
                background: #ff5252;
            }
            .error-actions .btn-secondary {
                background: white;
                color: #333;
                border: 2px solid #e9ecef;
            }
            .error-actions .btn-secondary:hover {
                border-color: #ff6b6b;
                color: #ff6b6b;
            }
        `;
        document.head.appendChild(style);
    }
}

// Fonction utilitaire pour obtenir les paramètres d'URL
function getUrlParams() {
    return new URLSearchParams(window.location.search);
}

// Fonction utilitaire pour obtenir la page actuelle
function getCurrentPage() {
    return currentPage;
}

// Fonction utilitaire pour obtenir l'ID de recette actuel
function getCurrentPostId() {
    return currentPostId;
}

// Fonction utilitaire pour obtenir la catégorie actuelle
function getCurrentCategory() {
    return currentCategory;
}

// Exporter les fonctions utiles
window.router = {
    loadPage,
    loadPostDetailPage,
    loadCategoryPageInternal,
    getCurrentPage,
    getCurrentPostId,
    getCurrentCategory,
    getUrlParams,
    navigateTo: window.navigateTo,
    createCategoryUrl: window.createCategoryUrl,
    createPostUrl: window.createPostUrl
};

// console.log('Router.js adapté chargé avec support du format posts-category/slug');