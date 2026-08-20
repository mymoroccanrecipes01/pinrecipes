// js/home-sections.js — Homepage-only sections: Best posts / Latest by category.
// Independent from PostLoader (posts-categorys.js) — own fetches, own render targets
// (#best-posts-row, #latest-by-category-groups), toggled via globalThis.home* flags
// (config.js, generated from site-config.json by config-ui.php).
(function () {
    let _cachedPosts = null;
    let _cachedCats  = null;

    function fetchJSON(url) {
        return fetch(url).then(r => r.ok ? r.json() : null).catch(() => null);
    }

    function placeholderImg() {
        return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"><rect width="400" height="300" fill="%23f8f9fa"/><text x="200" y="150" font-family="Arial" font-size="18" fill="%236c757d" text-anchor="middle">No image</text></svg>';
    }

    function truncate(s, n) {
        s = (s || '').toString();
        return s.length > n ? s.slice(0, n - 1).trim() + '…' : s;
    }

    async function loadCategories() {
        if (_cachedCats !== null) return _cachedCats;
        const idx = await fetchJSON('categories/index.json');
        const folders = (idx && idx.folders) || {};
        const slugs = Object.keys(folders);
        const cats = await Promise.all(slugs.map(async (slug) => {
            const c = await fetchJSON(`categories/${slug}/category.json`);
            if (!c) return null;
            return {
                id: c.id ?? folders[slug],
                slug,
                name: c.name || slug,
                description: c.description || '',
                image: c.image_url || c.image || '',
            };
        }));
        _cachedCats = cats.filter(Boolean);
        return _cachedCats;
    }

    async function loadPosts() {
        if (_cachedPosts !== null) return _cachedPosts;
        const idx = await fetchJSON('posts/index.json');
        _cachedPosts = ((idx && idx.posts) || []).filter((p) => p.isOnline === true);
        return _cachedPosts;
    }

    function postCard(post, extraClass) {
        const img = post.image ? './' + post.image : placeholderImg();
        const badge = post.rating
            ? `<span class="hs-card__badge">★ ${post.rating.value} <small>(${post.rating.count})</small></span>`
            : '';
        return `
            <a class="hs-card ${extraClass || ''}" href="posts/${post.slug}" title="${post.title}">
                <div class="hs-card__img">
                    <img src="${img}" alt="${post.title}" loading="lazy" decoding="async" width="300" height="225"
                         onerror="this.src='${placeholderImg()}'">
                    ${badge}
                </div>
                <div class="hs-card__body">
                    <h3 class="hs-card__title">${post.title}</h3>
                    <p class="hs-card__desc">${truncate(post.description, 90)}</p>
                </div>
            </a>`;
    }

    async function renderBestPosts() {
        const section = document.getElementById('best-posts-section');
        const row     = document.getElementById('best-posts-row');
        if (!section || !row) return;
        if (!globalThis.homeBestPostsActive) { section.style.display = 'none'; return; }

        const posts = await loadPosts();
        const count = globalThis.homeBestPostsCount || 6;
        const best = posts
            .slice()
            .sort((a, b) => (((b.rating || {}).value || 0) * ((b.rating || {}).count || 0))
                           - (((a.rating || {}).value || 0) * ((a.rating || {}).count || 0)))
            .slice(0, count);

        if (best.length === 0) { section.style.display = 'none'; return; }
        section.style.display = '';
        row.innerHTML = best.map((p) => postCard(p, 'hs-card--best')).join('');
    }

    async function renderLatestByCategory() {
        const section   = document.getElementById('latest-by-category-section');
        const container = document.getElementById('latest-by-category-groups');
        if (!section || !container) return;
        if (!globalThis.homeLatestByCategoryActive) { section.style.display = 'none'; return; }

        const [posts, cats] = await Promise.all([loadPosts(), loadCategories()]);
        const perCat  = globalThis.homeLatestByCategoryPerCat  || 4;
        const maxCats = globalThis.homeLatestByCategoryMaxCats || 4;
        const catById = {};
        cats.forEach((c) => { catById[c.id] = c; });

        // posts déjà triées newest-first côté serveur (index.json) — l'ordre est conservé.
        const byCategory = {};
        posts.forEach((p) => {
            if (p.category_id == null || !catById[p.category_id]) return;
            (byCategory[p.category_id] = byCategory[p.category_id] || []).push(p);
        });
        const rankedCatIds = Object.keys(byCategory)
            .sort((a, b) => byCategory[b].length - byCategory[a].length)
            .slice(0, maxCats);

        if (rankedCatIds.length === 0) { section.style.display = 'none'; return; }
        section.style.display = '';
        container.innerHTML = rankedCatIds.map((catId) => {
            const cat      = catById[catId];
            const catPosts = byCategory[catId].slice(0, perCat);
            return `
                <div class="hs-latest-group">
                    <div class="hs-latest-group__head">
                        <h3>${cat.name}</h3>
                        <a href="?page=posts-category/${cat.slug}" class="hs-latest-group__more"
                           onclick="window.location.href=this.href;return false;">See all →</a>
                    </div>
                    <div class="hs-latest-group__row">
                        ${catPosts.map((p) => postCard(p, 'hs-card--compact')).join('')}
                    </div>
                </div>`;
        }).join('');
    }

    window.initHomeSections = function () {
        renderBestPosts();
        renderLatestByCategory();
    };
})();
