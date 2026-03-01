/**
 * AdSense Auto-Injection System
 * 100% Config-driven - just add placements to the array, no code changes needed!
 * Works with both standalone recipe pages and SPA (base.html) pages
 */

(function() {
    'use strict';

    // ==================== CONFIGURATION ====================
    const ADS_CONFIG = {
        publisherId: 'ca-pub-3666818985097490',
        enabled: true,
        injectionDelay: 300,

        /**
         * AD PLACEMENTS - Just add/remove entries here!
         *
         * Each placement needs:
         *   - slot:      Your AdSense slot ID
         *   - selector:  CSS selector to find the target element
         *   - position:  'after' | 'before' | 'inside-top' | 'inside-bottom'
         *   - format:    'auto' | 'in-article' | 'horizontal'
         *   - pages:     'recipe' | 'spa' | 'all'  (where this ad appears)
         *
         * Optional:
         *   - nthChild:  For repeated elements (e.g., story-section), pick which one (1-based)
         *                 Use fractions: '1/3' = at 1/3 of total, '2/3' = at 2/3, 'last' = last one
         *   - className: Extra CSS class for styling
         */
        placements: [
            // -------- RECIPE PAGE ADS --------
            {
                slot: '8684648378',
                selector: '.recipe-description',
                position: 'after',
                format: 'auto',
                pages: 'recipe',
                className: 'ad-after-description',
            },
            {
                slot: '4055138220',
                selector: '.recipe-story .story-section',
                position: 'after',
                format: 'in-article',
                pages: 'recipe',
                nthChild: '1/3',
                className: 'ad-in-article',
            },
            {
                slot: '4055138220',
                selector: '.recipe-story .story-section',
                position: 'after',
                format: 'in-article',
                pages: 'recipe',
                nthChild: '2/3',
                className: 'ad-in-article',
            },
            {
                slot: '4055138220',
                selector: '.recipe-boxs',
                position: 'after',
                format: 'auto',
                pages: 'recipe',
                className: 'ad-after-recipe-box',
            },
            {
                slot: '4055138220',
                selector: 'footer.footer',
                position: 'before',
                format: 'horizontal',
                pages: 'all',
                className: 'ad-before-footer',
            },

            // -------- SPA PAGE ADS --------
            {
                slot: '4055138220',
                selector: '#main-content .container',
                position: 'inside-top',
                format: 'auto',
                pages: 'spa',
                className: 'ad-top-content',
            },

            // ============================================================
            //  ZIDACH PLACEMENT JDID HENA - JUST COPY/PASTE & MODIFY!
            // ============================================================
            // Example: Ad after breadcrumb
            // {
            //     slot: '1234567896',
            //     selector: '.breadcrumb',
            //     position: 'after',
            //     format: 'auto',
            //     pages: 'recipe',
            //     className: 'ad-after-breadcrumb',
            // },
            //
            // Example: Ad between ingredients and instructions
            // {
            //     slot: '1234567897',
            //     selector: '.ingredients-section',
            //     position: 'after',
            //     format: 'in-article',
            //     pages: 'recipe',
            //     className: 'ad-between-sections',
            // },
            //
            // Example: Ad after the 5th story section
            // {
            //     slot: '1234567898',
            //     selector: '.recipe-story .story-section',
            //     position: 'after',
            //     format: 'in-article',
            //     pages: 'recipe',
            //     nthChild: 5,
            //     className: 'ad-in-article',
            // },
            //
            // Example: Ad after recipe tips
            // {
            //     slot: '1234567899',
            //     selector: '.recipe-tips',
            //     position: 'after',
            //     format: 'auto',
            //     pages: 'recipe',
            //     className: 'ad-after-tips',
            // },
        ],
    };

    // ==================== ENGINE (no need to touch below) ====================

    function createAdUnit(slotId, format, className) {
        var container = document.createElement('div');
        container.className = 'ad-container ' + (className || '');
        container.setAttribute('data-ad-slot', slotId);

        var label = document.createElement('span');
        label.className = 'ad-label';
        label.textContent = 'Advertisement';

        var adIns = document.createElement('ins');
        adIns.className = 'adsbygoogle';
        adIns.style.display = 'block';
        adIns.setAttribute('data-ad-client', ADS_CONFIG.publisherId);
        adIns.setAttribute('data-ad-slot', slotId);

        if (format === 'auto') {
            adIns.setAttribute('data-ad-format', 'auto');
            adIns.setAttribute('data-full-width-responsive', 'true');
        } else if (format === 'in-article') {
            adIns.setAttribute('data-ad-format', 'fluid');
            adIns.setAttribute('data-ad-layout', 'in-article');
            adIns.style.textAlign = 'center';
        } else if (format === 'horizontal') {
            adIns.setAttribute('data-ad-format', 'horizontal');
            adIns.setAttribute('data-full-width-responsive', 'true');
        }

        container.appendChild(label);
        container.appendChild(adIns);
        return container;
    }

    function pushAd() {
        try {
            (window.adsbygoogle = window.adsbygoogle || []).push({});
        } catch (e) {}
    }

    function resolveNthChild(elements, nthChild) {
        if (!elements.length) return null;
        if (typeof nthChild === 'number') {
            return elements[Math.min(nthChild - 1, elements.length - 1)] || null;
        }
        if (nthChild === 'last') {
            return elements[elements.length - 1];
        }
        if (typeof nthChild === 'string' && nthChild.includes('/')) {
            var parts = nthChild.split('/');
            var idx = Math.floor(elements.length * (parseInt(parts[0]) / parseInt(parts[1])));
            return elements[Math.min(idx, elements.length - 1)] || null;
        }
        return elements[0];
    }

    function insertAd(placement, target) {
        var ad = createAdUnit(placement.slot, placement.format, placement.className);

        switch (placement.position) {
            case 'before':
                target.parentNode.insertBefore(ad, target);
                break;
            case 'after':
                target.parentNode.insertBefore(ad, target.nextSibling);
                break;
            case 'inside-top':
                if (target.firstElementChild) {
                    target.insertBefore(ad, target.firstElementChild.nextSibling);
                } else {
                    target.appendChild(ad);
                }
                break;
            case 'inside-bottom':
                target.appendChild(ad);
                break;
            default:
                target.parentNode.insertBefore(ad, target.nextSibling);
        }

        pushAd();
    }

    function getPageType() {
        var isRecipe = window.location.pathname.includes('/recipes/') &&
                       document.querySelector('.recipe-detail') !== null;
        var isSPA = document.getElementById('main-content') !== null &&
                    !isRecipe;
        return isRecipe ? 'recipe' : (isSPA ? 'spa' : 'generic');
    }

    function removeExistingAds() {
        document.querySelectorAll('.ad-container').forEach(function(el) {
            el.remove();
        });
    }

    function injectAds() {
        if (!ADS_CONFIG.enabled) return;

        removeExistingAds();

        var pageType = getPageType();

        ADS_CONFIG.placements.forEach(function(placement) {
            // Check if this placement applies to current page
            if (placement.pages !== 'all' && placement.pages !== pageType) return;
            // For SPA showing a recipe detail, skip 'spa'-only placements
            if (placement.pages === 'spa' && document.querySelector('.recipe-detail')) return;

            var elements = document.querySelectorAll(placement.selector);
            if (!elements.length) return;

            if (placement.nthChild !== undefined) {
                // Handle nthChild - pick specific element from the list
                var target = resolveNthChild(elements, placement.nthChild);
                if (target) insertAd(placement, target);
            } else {
                // Use first matching element
                insertAd(placement, elements[0]);
            }
        });
    }

    // ==================== SPA NAVIGATION OBSERVER ====================

    function observeSPANavigation() {
        var mainContent = document.getElementById('main-content');
        if (!mainContent) return;

        var observer = new MutationObserver(function(mutations) {
            var hasNewContent = mutations.some(function(m) {
                return m.addedNodes.length > 0;
            });
            if (hasNewContent) {
                setTimeout(injectAds, ADS_CONFIG.injectionDelay);
            }
        });

        observer.observe(mainContent, { childList: true, subtree: false });
    }

    // ==================== BOOTSTRAP ====================

    function init() {
        if (!ADS_CONFIG.enabled) return;

        setTimeout(function() {
            injectAds();
            if (document.getElementById('main-content')) {
                observeSPANavigation();
            }
        }, ADS_CONFIG.injectionDelay);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
