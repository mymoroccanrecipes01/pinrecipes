class AuthorsLoader {
    constructor() {
        this.authorsPath = './authors/authors.json';
        this.teamContainer = null;
        this.initialized = false;
    }

    async init() {
        if (this.initialized) return;

        try {
            await this.waitForContainer();
            
            if (!this.teamContainer) {
               // console.error('Team container not found');
                return;
            }

           // // console.log('Loading authors from:', this.authorsPath);
            
            const authors = await this.loadAuthorsData();
            
            if (!authors || authors.length === 0) {
                this.showError('No authors found');
                return;
            }

            this.displayTeamMembers(authors);
            this.initialized = true;

        } catch (error) {
           // console.error('Error loading authors:', error);
            this.showError('Error loading team members');
        }
    }

    async waitForContainer() {
        const maxAttempts = 50;
        const baseDelay = 100;
        
        for (let i = 0; i < maxAttempts; i++) {
            // Try different possible container selectors
            this.teamContainer = document.querySelector('.team-grid') || 
                                document.getElementById('team-grid') ||
                                document.querySelector('[data-team-container]');
            
            if (this.teamContainer) {
               // // console.log(`Team container found after ${i + 1} attempt(s)`);
                return;
            }
            
            const delay = baseDelay * (i < 10 ? 1 : 2);
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }

    async loadAuthorsData() {
        try {
            const response = await fetch(this.authorsPath);
            
            if (!response.ok) {
                console.warn(`Unable to load ${this.authorsPath}`);
                return null;
            }
            
            const authorsData = await response.json();
            
            // Filter and sort authors
            return authorsData
                .filter(author => author && author.name) // Only valid authors
                .sort((a, b) => {
                    // Active authors first
                    if (a.active && !b.active) return -1;
                    if (!a.active && b.active) return 1;
                    // Then by name
                    return (a.name || '').localeCompare(b.name || '');
                });
            
        } catch (error) {
           // console.error('Error fetching authors:', error);
            return null;
        }
    }

    displayTeamMembers(authors) {
        if (!this.teamContainer) {
           // console.error('Team container not available');
            return;
        }

        const teamHTML = authors.map(author => this.createTeamMemberCard(author)).join('');
        
        this.teamContainer.innerHTML = teamHTML;
        
       // // console.log(`Displayed ${authors.length} team members`);
    }

    createTeamMemberCard(author) {
        const {
            name = 'Unknown Chef',
            bio = 'Team Member',
            imagePath = 'https://images.unsplash.com/photo-1607990281513-2c110a25bd8c?w=250&h=250&fit=crop',
            active = false,
            role = 'Chef',
            speciality = '',
            social = {}
        } = author;

        // Generate role/title display
        const roleDisplay = role || 'Chef';
        const titleDisplay = speciality ? `${roleDisplay} - ${speciality}` : roleDisplay;
        
        // Active indicator
        const activeIndicator = active ? '<div class="active-badge">Active</div>' : '';
        
        // Social media links
        const socialLinks = this.generateSocialLinks(social);

        return `
            <div class="team-member ${active ? 'active-member' : ''}">
                ${activeIndicator}
                <div class="member-image">
                    <img src="${imagePath}" 
                         alt="${name}" 
                         onerror="this.src='https://images.unsplash.com/photo-1607990281513-2c110a25bd8c?w=250&h=250&fit=crop'">
                </div>
                <div class="member-info">
                    <h3 class="member-name">${name}</h3>
                    <p class="member-title">${titleDisplay}</p>
                    ${bio ? `<p class="member-bio">${bio}</p>` : ''}
                    ${socialLinks}
                </div>
            </div>
        `;
    }

    generateSocialLinks(social) {
        if (!social || Object.keys(social).length === 0) {
            return '';
        }

        const socialHTML = Object.entries(social)
            .filter(([platform, url]) => url && url.trim() !== '')
            .map(([platform, url]) => {
                const icon = this.getSocialIcon(platform);
                return `<a href="${url}" target="_blank" rel="noopener" class="social-link" title="${platform}">${icon}</a>`;
            })
            .join('');

        return socialHTML ? `<div class="social-links">${socialHTML}</div>` : '';
    }

    getSocialIcon(platform) {
        const icons = {
            instagram: '📷',
            facebook: '👤',
            twitter: '🐦',
            linkedin: '💼',
            youtube: '📺',
            website: '🌐',
            email: '✉️'
        };
        
        return icons[platform.toLowerCase()] || '🔗';
    }

    showError(message) {
        if (this.teamContainer) {
            this.teamContainer.innerHTML = `
                <div class="team-error" style="
                    grid-column: 1 / -1;
                    background: #fff5f5;
                    border: 1px solid #fed7d7;
                    color: #c53030;
                    padding: 20px;
                    border-radius: 8px;
                    text-align: center;
                    margin: 20px 0;
                ">
                    <h3>Loading Error</h3>
                    <p>${message}</p>
                </div>
            `;
        }
    }

    // Public method to refresh the team display
    async refresh() {
        this.initialized = false;
        await this.init();
    }

    // Public method to get active author
    async getActiveAuthor() {
        const authors = await this.loadAuthorsData();
        return authors ? authors.find(author => author.active === true) : null;
    }

    // Public method to get all authors
    async getAllAuthors() {
        return await this.loadAuthorsData();
    }
}

// Global variables
let authorsLoader;
let authorsInitAttempts = 0;
const maxAuthorsInitAttempts = 20;

// Main initialization function
function initAuthorsLoader() {
    authorsInitAttempts++;
    
    if (authorsLoader && authorsLoader.initialized) {
       // // console.log('AuthorsLoader already initialized');
        return;
    }

    const container = document.querySelector('.team-grid') || 
                     document.getElementById('team-grid') ||
                     document.querySelector('[data-team-container]');
    
    if (container) {
       // // console.log('Team container found, initializing authors loader...');
        authorsLoader = new AuthorsLoader();
        authorsLoader.init();
    } else if (authorsInitAttempts < maxAuthorsInitAttempts) {
       // // console.log(`Team container not found, attempt ${authorsInitAttempts}/${maxAuthorsInitAttempts}`);
        setTimeout(initAuthorsLoader, 200);
    } else {
       // console.error('Team container not found after', maxAuthorsInitAttempts, 'attempts');
    }
}

// Initialization points
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuthorsLoader);
} else {
    setTimeout(initAuthorsLoader, 100);
}

window.addEventListener('load', () => {
    if (!authorsLoader) {
        setTimeout(initAuthorsLoader, 200);
    }
});

// Observer for SPA (Single Page Applications)
if (typeof MutationObserver !== 'undefined') {
    const authorsObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'childList') {
                const container = document.querySelector('.team-grid') || 
                                 document.getElementById('team-grid') ||
                                 document.querySelector('[data-team-container]');
                if (container && !authorsLoader) {
                    initAuthorsLoader();
                }
            }
        });
    });

    if (document.body) {
        authorsObserver.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
}

// Force initialization function (for debugging)
window.forceInitAuthorsLoader = function() {
   // // console.log('Force authors initialization...');
    authorsLoader = null;
    authorsInitAttempts = 0;
    initAuthorsLoader();
};

// Additional CSS styles for the team grid
const teamStyles = ``;

// Inject styles if not already present
if (!document.querySelector('#team-grid-styles')) {
    const styleElement = document.createElement('div');
    styleElement.id = 'team-grid-styles';
    styleElement.innerHTML = teamStyles;
    document.head.appendChild(styleElement);
}