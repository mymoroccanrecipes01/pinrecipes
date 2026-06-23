/**
 * AdSense Auto-Injection System
 * 100% Config-driven — edit via config-ui.php
 */

(function() {
    'use strict';

    // ==================== CONFIGURATION ====================
    const ADS_CONFIG = {
            "publisherId": "ca-pub-3666818985097490",
            "enabled": true,
            "injectionDelay": 300,
            "placements": [
                {
                    "selector": ".header",
                    "position": "after",
                    "format": "auto",
                    "pages": "all",
                    "slot": "4055138220",
                    "className": "ad-header"
                },
                {
                    "selector": ".hero",
                    "position": "after",
                    "format": "auto",
                    "pages": "all",
                    "slot": "8684648378",
                    "className": "ad-hero"
                },
                {
                    "selector": "footer.footer",
                    "position": "before",
                    "format": "horizontal",
                    "pages": "all",
                    "slot": "8684648378",
                    "className": "ad-before-footer"
                }
            ]
        };

        // ==================== ENGINE (no need to touch below) ====================

    function createAdUnit(slotId, format, className) {
        var container = document.createElement('div');
        container.className = 'ad-container ' + (className || '');
        container.setAttribute('data-ad-slot', slotId);

        var label = document.createElement('span');
        label.className = 'ad-label';
        // label.textContent = 'Advertisement';
        // label.style.display = 'none'; // Hidden until ad loads

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

        new MutationObserver(function(_, obs) {
            var status = adIns.getAttribute('data-ad-status');
            if (status === 'filled') {
                label.style.display = '';
                obs.disconnect();
            } else if (status === 'unfilled') {
                // container.style.display = 'none';
                obs.disconnect();
            }
        }).observe(adIns, { attributes: true, attributeFilter: ['data-ad-status'] });
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
        var slotId = placement.slot || (placement.slots && placement.slots[0]);
        var ad = createAdUnit(slotId, placement.format, placement.className);

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
        var isPost = window.location.pathname.includes('/posts/') &&
                       document.querySelector('.post-detail') !== null;
        var isSPA = document.getElementById('main-content') !== null &&
                    !isPost;
        return isPost ? 'post' : (isSPA ? 'spa' : 'generic');
    }

    function removeExistingAds() {
        document.querySelectorAll('.ad-container').forEach(function(el) {
            el.remove();
        });
    }

    function injectAds() {
        if (!ADS_CONFIG.enabled) return;

        // Post pages : les pubs sont rendues server-side aux positions EXACTES (POST_LAYOUT ad_N).
        // Ne rien injecter ni supprimer ici (removeExistingAds effacerait les pubs server-side).
        if (getPageType() === 'post') return;

        removeExistingAds();

        var pageType = getPageType();

        ADS_CONFIG.placements.forEach(function(placement) {
            // Check if this placement applies to current page
            if (placement.pages !== 'all' && placement.pages !== pageType) return;
            // For SPA showing a post detail, skip 'spa'-only placements
            if (placement.pages === 'spa' && document.querySelector('.post-detail')) return;

            var elements = document.querySelectorAll(placement.selector);
            if (!elements.length) return;

            if (placement.everyNth !== undefined) {
                // Inject after every Nth element, with optional maxAds cap
                var n = placement.everyNth;
                var max = placement.maxAds || 4;
                var count = 0;
                for (var i = n - 1; i < elements.length && count < max; i += n) {
                    // Use slots array (per-position) or fallback to single slot
                    var slotId = (placement.slots && placement.slots[count] !== undefined)
                        ? placement.slots[count]
                        : placement.slot;
                    var p = Object.assign({}, placement, { slot: slotId });
                    insertAd(p, elements[i]);
                    count++;
                }
            } else if (placement.nthChild !== undefined) {
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
