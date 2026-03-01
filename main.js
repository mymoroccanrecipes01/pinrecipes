// Recipe Website JavaScript - Compatible avec le système de routage

document.addEventListener('DOMContentLoaded', function() {
   // // console.log('Main.js chargé - Le routeur gère l\'initialisation des pages');
});

// Fonctionnalités spécifiques à la page des recettes
window.initRecipesPageFeatures = function() {
   // // console.log('Initialisation des fonctionnalités de la page Recipes');
    
    // Filtres de recettes
    const filterBtns = document.querySelectorAll('.filter-btn');
    const recipeCards = document.querySelectorAll('.recipe-card[data-category]');
    
    if (filterBtns.length > 0) {
        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                // Enlever la classe active des autres boutons
                filterBtns.forEach(b => b.classList.remove('active'));
                // Ajouter la classe active au bouton cliqué
                this.classList.add('active');
                
                const filter = this.getAttribute('data-filter');
                filterRecipes(filter, recipeCards);
            });
        });
    }
    
    // Pagination
    const pageButtons = document.querySelectorAll('.page-btn');
    pageButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            if (!this.classList.contains('next') && !this.classList.contains('prev')) {
                pageButtons.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });
};

// Filtrer les recettes par catégorie
function filterRecipes(category, cards) {
    let visibleCount = 0;
    
    cards.forEach(card => {
        const cardCategories = card.getAttribute('data-category')?.split(' ') || [];
        
        if (category === 'all' || cardCategories.includes(category)) {
            card.style.display = 'block';
            card.style.animation = 'fadeInUp 0.5s ease forwards';
            visibleCount++;
        } else {
            card.style.display = 'none';
        }
    });
    
    // Mettre à jour le compteur
    const countElement = document.getElementById('recipe-count');
    if (countElement) {
        countElement.textContent = visibleCount;
    }
}

// Fonctionnalités spécifiques à la page de contact
window.initContactPageFeatures = function() {
   // // console.log('Initialisation des fonctionnalités de la page Contact');
    
    // Validation du formulaire de contact
    const form = document.querySelector('.contact-form');
    if (form) {
        const inputs = form.querySelectorAll('input[required], textarea[required]');
        
        inputs.forEach(input => {
            input.addEventListener('blur', function() {
                validateField(this);
            });
            
            input.addEventListener('input', function() {
                clearFieldError(this);
            });
        });
    }
    
    // Animation des éléments de contact
    const contactItems = document.querySelectorAll('.contact-item');
    contactItems.forEach((item, index) => {
        item.style.opacity = '0';
        item.style.transform = 'translateX(-20px)';
        item.style.transition = `opacity 0.6s ease ${index * 0.1}s, transform 0.6s ease ${index * 0.1}s`;
        
        setTimeout(() => {
            item.style.opacity = '1';
            item.style.transform = 'translateX(0)';
        }, 300);
    });
};

// Validation d'un champ de formulaire
function validateField(field) {
    const value = field.value.trim();
    let isValid = true;
    let errorMessage = '';
    
    // Enlever les erreurs précédentes
    clearFieldError(field);
    
    // Validation en fonction du type de champ
    if (field.hasAttribute('required') && !value) {
        isValid = false;
        errorMessage = 'Ce champ est obligatoire';
    } else if (field.type === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            errorMessage = 'Veuillez entrer une adresse email valide';
        }
    }
    
    // Afficher l'erreur si nécessaire
    if (!isValid) {
        showFieldError(field, errorMessage);
    }
    
    return isValid;
}

// Afficher une erreur sur un champ
function showFieldError(field, message) {
    field.style.borderColor = '#ff6b6b';
    
    let errorElement = field.parentNode.querySelector('.field-error');
    if (!errorElement) {
        errorElement = document.createElement('span');
        errorElement.className = 'field-error';
        errorElement.style.cssText = `
            color: #ff6b6b;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        `;
        field.parentNode.appendChild(errorElement);
    }
    
    errorElement.textContent = message;
}

// Effacer l'erreur d'un champ
function clearFieldError(field) {
    field.style.borderColor = '#e9ecef';
    
    const errorElement = field.parentNode.querySelector('.field-error');
    if (errorElement) {
        errorElement.remove();
    }
}

// Soumettre le formulaire de contact
window.submitContactForm = function() {
    const form = document.querySelector('.contact-form');
    if (!form) return;
    
    const inputs = form.querySelectorAll('input[required], textarea[required]');
    let isFormValid = true;
    
    // Valider tous les champs
    inputs.forEach(input => {
        if (!validateField(input)) {
            isFormValid = false;
        }
    });
    
    if (isFormValid) {
        // Simuler l'envoi du formulaire
        const submitBtn = document.querySelector('.submit-btn');
        if (!submitBtn) return;
        
        const originalText = submitBtn.textContent;
        
        submitBtn.textContent = 'Envoi en cours...';
        submitBtn.disabled = true;
        
        setTimeout(() => {
            alert('Message envoyé avec succès! Nous vous répondrons bientôt.');
            
            // Réinitialiser le formulaire
            inputs.forEach(input => {
                input.value = '';
                clearFieldError(input);
            });
            
            // Réinitialiser le select
            const selectElement = form.querySelector('select');
            if (selectElement) {
                selectElement.selectedIndex = 0;
            }
            
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }, 2000);
    } else {
        alert('Veuillez corriger les erreurs dans le formulaire.');
    }
};

// Fonctionnalités spécifiques à la page À propos
window.initAboutPageFeatures = function() {
   // // console.log('Initialisation des fonctionnalités de la page About');
    
    // Animation des membres de l'équipe
    const teamMembers = document.querySelectorAll('.team-member');
    teamMembers.forEach((member, index) => {
        member.style.opacity = '0';
        member.style.transform = 'translateY(30px)';
        member.style.transition = `opacity 0.6s ease ${index * 0.2}s, transform 0.6s ease ${index * 0.2}s`;
        
        setTimeout(() => {
            member.style.opacity = '1';
            member.style.transform = 'translateY(0)';
        }, 500);
    });
};

// Fonctionnalités spécifiques à la page d'accueil
window.initHomePageFeatures = function() {
   // // console.log('Initialisation des fonctionnalités de la page Home');
    
    // Animation du hero
    const hero = document.querySelector('.hero');
    if (hero) {
        hero.style.opacity = '0';
        hero.style.transform = 'translateY(20px)';
        hero.style.transition = 'opacity 1s ease, transform 1s ease';
        
        setTimeout(() => {
            hero.style.opacity = '1';
            hero.style.transform = 'translateY(0)';
        }, 300);
    }
    
    // Animation des cartes au scroll
    animateCardsOnScroll();
};

// Animer les cartes au scroll
function animateCardsOnScroll() {
    const cards = document.querySelectorAll('.recipe-card, .category-card, .pick-card');
    
    // Créer l'observateur d'intersection
    const cardObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    });
    
    // Appliquer l'animation initiale et observer chaque carte
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = `opacity 0.6s ease ${index * 0.05}s, transform 0.6s ease ${index * 0.05}s`;
        cardObserver.observe(card);
    });
}

// Smooth scrolling pour les ancres
function initializeSmoothScrolling() {
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href^="#"]');
        if (link && !link.getAttribute('href').includes('?page=')) {
            e.preventDefault();
            const target = document.querySelector(link.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    });
}

// Initialiser le smooth scrolling dès le chargement
initializeSmoothScrolling();

// Fonction utilitaire pour débouncer les événements
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Optimiser le scroll listener
const optimizedScrollHandler = debounce(() => {
    const scrollTop = window.pageYOffset;
    const scrollBtn = document.querySelector('.scroll-to-top');
    
    if (scrollBtn) {
        if (scrollTop > 300) {
            scrollBtn.style.opacity = '1';
            scrollBtn.style.pointerEvents = 'auto';
        } else {
            scrollBtn.style.opacity = '0';
            scrollBtn.style.pointerEvents = 'none';
        }
    }
}, 10);

// Ajouter le listener de scroll optimisé
window.addEventListener('scroll', optimizedScrollHandler);

// Fonctions utilitaires globales
window.utils = {
    fadeIn: function(element, duration = 300) {
        element.style.opacity = '0';
        element.style.display = 'block';
        element.style.transition = `opacity ${duration}ms ease`;
        
        setTimeout(() => {
            element.style.opacity = '1';
        }, 10);
    },
    
    fadeOut: function(element, duration = 300) {
        element.style.transition = `opacity ${duration}ms ease`;
        element.style.opacity = '0';
        
        setTimeout(() => {
            element.style.display = 'none';
        }, duration);
    },
    
    staggerAnimation: function(elements, delay = 100) {
        elements.forEach((element, index) => {
            element.style.opacity = '0';
            element.style.transform = 'translateY(20px)';
            element.style.transition = `opacity 0.6s ease ${index * delay}ms, transform 0.6s ease ${index * delay}ms`;
            
            setTimeout(() => {
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }, 50);
        });
    }
};

// console.log('Main.js complètement chargé - Toutes les fonctionnalités sont disponibles');

