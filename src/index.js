// src/index.js - Cloudflare Worker API
export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const path = url.pathname;
    const method = request.method;

    // Configuration CORS
    const corsHeaders = {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization',
    };

    // Gestion des requêtes OPTIONS (CORS preflight)
    if (method === 'OPTIONS') {
      return new Response(null, { headers: corsHeaders });
    }

    try {
      // Router principal
      if (path.startsWith('/api/categories')) {
        return await handleCategoriesAPI(request, env, corsHeaders);
      }

      // Page principale (si demandée)
      if (path === '/' || path === '/index.html') {
        return await handleMainPage();
      }

      return new Response('Not Found', { status: 404, headers: corsHeaders });
    } catch (error) {
      console.error('Erreur:', error);
      return new Response(JSON.stringify({ error: 'Erreur serveur' }), {
        status: 500,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }
  }
};

// Gestionnaire des API categories
async function handleCategoriesAPI(request, env, corsHeaders) {
  const url = new URL(request.url);
  const path = url.pathname;
  const method = request.method;
  const segments = path.split('/').filter(s => s);

  // GET /api/categories - Lister toutes les catégories
  if (method === 'GET' && segments.length === 2) {
    return await getCategories(env, url.searchParams, corsHeaders);
  }

  // GET /api/categories/:id - Récupérer une catégorie
  if (method === 'GET' && segments.length === 3) {
    const id = segments[2];
    return await getCategory(env, id, corsHeaders);
  }

  // POST /api/categories - Créer une catégorie
  if (method === 'POST' && segments.length === 2) {
    return await createCategory(request, env, corsHeaders);
  }

  // PUT /api/categories/:id - Modifier une catégorie
  if (method === 'PUT' && segments.length === 3) {
    const id = segments[2];
    return await updateCategory(request, env, id, corsHeaders);
  }

  // DELETE /api/categories/:id - Supprimer une catégorie
  if (method === 'DELETE' && segments.length === 3) {
    const id = segments[2];
    return await deleteCategory(env, id, corsHeaders);
  }

  return new Response('Endpoint non trouvé', { status: 404, headers: corsHeaders });
}

// Lister les catégories avec recherche et pagination
async function getCategories(env, searchParams, corsHeaders) {
  try {
    const search = searchParams.get('search') || '';
    const page = parseInt(searchParams.get('page')) || 1;
    const limit = parseInt(searchParams.get('limit')) || 20;
    const offset = (page - 1) * limit;

    let query = `
      SELECT id, name, description, image_url, slug, status, created_at, updated_at 
      FROM categories 
      WHERE status = 'active'
    `;
    let params = [];

    // Recherche
    if (search) {
      query += ` AND (name LIKE ? OR description LIKE ?)`;
      params.push(`%${search}%`, `%${search}%`);
    }

    query += ` ORDER BY created_at DESC LIMIT ? OFFSET ?`;
    params.push(limit, offset);

    const { results } = await env.DB.prepare(query).bind(...params).all();

    // Compter le total pour la pagination
    let countQuery = `SELECT COUNT(*) as total FROM categories WHERE status = 'active'`;
    let countParams = [];

    if (search) {
      countQuery += ` AND (name LIKE ? OR description LIKE ?)`;
      countParams.push(`%${search}%`, `%${search}%`);
    }

    const { results: countResult } = await env.DB.prepare(countQuery).bind(...countParams).all();
    const total = countResult[0].total;

    return new Response(JSON.stringify({
      success: true,
      data: results,
      pagination: {
        page,
        limit,
        total,
        pages: Math.ceil(total / limit)
      }
    }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  } catch (error) {
    console.error('Erreur getCategories:', error);
    return new Response(JSON.stringify({ error: 'Erreur lors de la récupération des catégories' }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  }
}

// Récupérer une catégorie par ID
async function getCategory(env, id, corsHeaders) {
  try {
    const { results } = await env.DB.prepare(
      'SELECT * FROM categories WHERE id = ? AND status = "active"'
    ).bind(id).all();

    if (results.length === 0) {
      return new Response(JSON.stringify({ error: 'Catégorie non trouvée' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    return new Response(JSON.stringify({
      success: true,
      data: results[0]
    }), {
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  } catch (error) {
    console.error('Erreur getCategory:', error);
    return new Response(JSON.stringify({ error: 'Erreur lors de la récupération de la catégorie' }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  }
}

// Créer une nouvelle catégorie
async function createCategory(request, env, corsHeaders) {
  try {
    const data = await request.json();
    
    // Validation
    if (!data.name || data.name.trim() === '') {
      return new Response(JSON.stringify({ error: 'Le nom est requis' }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    // Générer un slug
    const slug = generateSlug(data.name);

    // Vérifier l'unicité du nom et du slug
    const { results: existing } = await env.DB.prepare(
      'SELECT id FROM categories WHERE (name = ? OR slug = ?) AND status = "active"'
    ).bind(data.name.trim(), slug).all();

    if (existing.length > 0) {
      return new Response(JSON.stringify({ error: 'Une catégorie avec ce nom existe déjà' }), {
        status: 409,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    // Valider l'URL de l'image si fournie
    if (data.image_url && !isValidUrl(data.image_url)) {
      return new Response(JSON.stringify({ error: 'URL d\'image invalide' }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    // Insérer la nouvelle catégorie
    const result = await env.DB.prepare(`
      INSERT INTO categories (name, description, image_url, slug, status, created_at, updated_at)
      VALUES (?, ?, ?, ?, 'active', datetime('now'), datetime('now'))
    `).bind(
      data.name.trim(),
      data.description?.trim() || null,
      data.image_url?.trim() || null,
      slug
    ).run();

    if (result.success) {
      // Récupérer la catégorie créée
      const { results } = await env.DB.prepare(
        'SELECT * FROM categories WHERE id = ?'
      ).bind(result.meta.last_row_id).all();

      return new Response(JSON.stringify({
        success: true,
        data: results[0],
        message: 'Catégorie créée avec succès'
      }), {
        status: 201,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    throw new Error('Échec de la création');
  } catch (error) {
    console.error('Erreur createCategory:', error);
    return new Response(JSON.stringify({ error: 'Erreur lors de la création de la catégorie' }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  }
}

// Modifier une catégorie
async function updateCategory(request, env, id, corsHeaders) {
  try {
    const data = await request.json();

    // Vérifier que la catégorie existe
    const { results: existing } = await env.DB.prepare(
      'SELECT * FROM categories WHERE id = ? AND status = "active"'
    ).bind(id).all();

    if (existing.length === 0) {
      return new Response(JSON.stringify({ error: 'Catégorie non trouvée' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    const currentCategory = existing[0];

    // Validation
    if (!data.name || data.name.trim() === '') {
      return new Response(JSON.stringify({ error: 'Le nom est requis' }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    // Générer un nouveau slug si le nom a changé
    let slug = currentCategory.slug;
    if (data.name.trim() !== currentCategory.name) {
      slug = generateSlug(data.name);
      
      // Vérifier l'unicité du nouveau nom/slug
      const { results: duplicates } = await env.DB.prepare(
        'SELECT id FROM categories WHERE (name = ? OR slug = ?) AND id != ? AND status = "active"'
      ).bind(data.name.trim(), slug, id).all();

      if (duplicates.length > 0) {
        return new Response(JSON.stringify({ error: 'Une catégorie avec ce nom existe déjà' }), {
          status: 409,
          headers: { ...corsHeaders, 'Content-Type': 'application/json' }
        });
      }
    }

    // Valider l'URL de l'image si fournie
    if (data.image_url && !isValidUrl(data.image_url)) {
      return new Response(JSON.stringify({ error: 'URL d\'image invalide' }), {
        status: 400,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    // Mettre à jour la catégorie
    const result = await env.DB.prepare(`
      UPDATE categories 
      SET name = ?, description = ?, image_url = ?, slug = ?, updated_at = datetime('now')
      WHERE id = ?
    `).bind(
      data.name.trim(),
      data.description?.trim() || null,
      data.image_url?.trim() || null,
      slug,
      id
    ).run();

    if (result.success) {
      // Récupérer la catégorie mise à jour
      const { results } = await env.DB.prepare(
        'SELECT * FROM categories WHERE id = ?'
      ).bind(id).all();

      return new Response(JSON.stringify({
        success: true,
        data: results[0],
        message: 'Catégorie mise à jour avec succès'
      }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    throw new Error('Échec de la mise à jour');
  } catch (error) {
    console.error('Erreur updateCategory:', error);
    return new Response(JSON.stringify({ error: 'Erreur lors de la mise à jour de la catégorie' }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  }
}

// Supprimer une catégorie (soft delete)
async function deleteCategory(env, id, corsHeaders) {
  try {
    // Vérifier que la catégorie existe
    const { results: existing } = await env.DB.prepare(
      'SELECT * FROM categories WHERE id = ? AND status = "active"'
    ).bind(id).all();

    if (existing.length === 0) {
      return new Response(JSON.stringify({ error: 'Catégorie non trouvée' }), {
        status: 404,
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    // Soft delete (marquer comme supprimée)
    const result = await env.DB.prepare(`
      UPDATE categories 
      SET status = 'deleted', updated_at = datetime('now')
      WHERE id = ?
    `).bind(id).run();

    if (result.success) {
      return new Response(JSON.stringify({
        success: true,
        message: 'Catégorie supprimée avec succès'
      }), {
        headers: { ...corsHeaders, 'Content-Type': 'application/json' }
      });
    }

    throw new Error('Échec de la suppression');
  } catch (error) {
    console.error('Erreur deleteCategory:', error);
    return new Response(JSON.stringify({ error: 'Erreur lors de la suppression de la catégorie' }), {
      status: 500,
      headers: { ...corsHeaders, 'Content-Type': 'application/json' }
    });
  }
}

// Utilitaires
function generateSlug(text) {
  return text
    .toLowerCase()
    .trim()
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
    .replace(/^-|-$/g, '');
}

function isValidUrl(string) {
  try {
    const url = new URL(string);
    return url.protocol === 'http:' || url.protocol === 'https:';
  } catch (_) {
    return false;
  }
}

// Servir la page principale
async function handleMainPage() {
  const html = `<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestionnaire de Catégories</title>
</head>
<body>
    <h1>API de gestion des catégories</h1>
    <p>Utilisez les endpoints suivants :</p>
    <ul>
        <li>GET /api/categories - Lister les catégories</li>
        <li>GET /api/categories/:id - Récupérer une catégorie</li>
        <li>POST /api/categories - Créer une catégorie</li>
        <li>PUT /api/categories/:id - Modifier une catégorie</li>
        <li>DELETE /api/categories/:id - Supprimer une catégorie</li>
    </ul>
</body>
</html>`;

  return new Response(html, {
    headers: { 'Content-Type': 'text/html' }
  });
}