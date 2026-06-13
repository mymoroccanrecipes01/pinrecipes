<?php

// ── Load site-config.json overrides ──────────────────────────────────────────
$_siteConfig     = [];
$_siteConfigFile = __DIR__ . '/site-config.json';
if (file_exists($_siteConfigFile)) {
    $_siteConfig = json_decode(file_get_contents($_siteConfigFile), true) ?: [];
}
function _cfg(string $key, $default) {
    global $_siteConfig;
    return array_key_exists($key, $_siteConfig) ? $_siteConfig[$key] : $default;
}

// ── Load prompts.json — fichier source dédié aux prompts (jamais effacé par reset) ──
$_promptsConfig     = [];
$_promptsConfigFile = __DIR__ . '/prompts.json';
if (file_exists($_promptsConfigFile)) {
    $_promptsConfig = json_decode(file_get_contents($_promptsConfigFile), true) ?: [];
}
function _prompt(string $key, string $default): string {
    global $_promptsConfig;
    return (isset($_promptsConfig[$key]) && $_promptsConfig[$key] !== '') ? $_promptsConfig[$key] : $default;
}
// ─────────────────────────────────────────────────────────────────────────────

define('PASSWORD',    _cfg('PASSWORD',    'MN@123456@mn'));

// Auth — login roles
define('ADMIN_EMAIL',    _cfg('ADMIN_EMAIL',    'admin@gmail.com'));
define('ADMIN_PASSWORD', _cfg('ADMIN_PASSWORD', 'MN@123456@mn'));
define('USER_EMAIL',     _cfg('USER_EMAIL',     'user@gmail.com'));
define('USER_PASSWORD',  _cfg('USER_PASSWORD',  'user123'));
define('REPO_PATH',   __DIR__);
define('CLI_SECRET',  _cfg('CLI_SECRET', md5(__DIR__ . 'cli-bypass-2025')));
define('BRANCH',      _cfg('BRANCH',      'main'));
define('GITHUB_REPO',     _cfg('GITHUB_REPO',     'https://github.com/mymoroccanposts01/pinposts.git'));
define('GITHUB_USER',     _cfg('GITHUB_USER',     ''));
define('GITHUB_PASSWORD', _cfg('GITHUB_PASSWORD', ''));
define('GIT_MODE',        _cfg('GIT_MODE',        'https'));
define('SSH_KEY',         _cfg('SSH_KEY',         ''));

define('ANTHROPIC_API_KEY', _cfg('ANTHROPIC_API_KEY', ''));
define('OPENAI_API_KEY',    _cfg('OPENAI_API_KEY',    ''));

// API used for post.json generation: 'openai' or 'anthropic'
define('GENERATION_API',           _cfg('GENERATION_API',           'openai'));
define('ANTHROPIC_MODEL',          _cfg('ANTHROPIC_MODEL',          'claude-3-haiku-20240307'));
define('OPENAI_CONTENT_MODEL',     _cfg('OPENAI_CONTENT_MODEL',     'gpt-4.1-mini'));
define('OPENAI_CONTENT_MAX_TOKENS',_cfg('OPENAI_CONTENT_MAX_TOKENS', 12000));
define('OPENAI_IMAGE_MODEL',       _cfg('OPENAI_IMAGE_MODEL',       'gpt-image-1-mini'));
define('OPENAI_IMAGE_QUALITY',     _cfg('OPENAI_IMAGE_QUALITY',     'medium'));
define('OPENAI_IMAGE_SIZE',        _cfg('OPENAI_IMAGE_SIZE',        '1024x1536'));
define('OPENAI_IMAGE_COST',        _cfg('OPENAI_IMAGE_COST',        0.015));

define('SATELLITE_PROJECTS', _cfg('SATELLITE_PROJECTS', [
    ['path' => '../LummyPosts', 'url' => 'http://localhost/SitePinterset/LummyPosts'],
]));

define('POSTS_DIR',      _cfg('POSTS_DIR',      null));
define('POSTS_BASE_URL', _cfg('POSTS_BASE_URL', null)); // null = use own BASE_URL for source images
define('LINK_PIN_ACTIVE',          _cfg('LINK_PIN_ACTIVE',          true));
define('RECIPE_CARD_LINK_ACTIVE',  _cfg('recipe_card_LINK_ACTIVE',  false));
define('OVERLAY_LIST_LINK_ACTIVE', _cfg('overlay_list_LINK_ACTIVE', false));
define('PINTEREST_DOMAIN_VERIFY', _cfg('PINTEREST_DOMAIN_VERIFY', ''));
define('CHROME_PROFILE',          _cfg('CHROME_PROFILE', ''));

define('NICHE', _cfg('NICHE', 'general'));

define('POST_META_STATS', _cfg('POST_META_STATS', [
    ['field' => 'prep_time',  'label' => 'Prep Time',  'suffix' => 'min', 'icon' => '⏱'],
    ['field' => 'cook_time',  'label' => 'Time',       'suffix' => 'min', 'icon' => '🔥'],
    ['field' => 'total_time', 'label' => 'Total Time', 'suffix' => 'min', 'icon' => '⏰'],
    ['field' => 'servings',   'label' => 'Servings',   'suffix' => '',    'icon' => '🍽'],
    ['field' => 'duration',   'label' => 'Duration',   'suffix' => '',    'icon' => '📅'],
    ['field' => 'difficulty', 'label' => 'Difficulty', 'suffix' => '',    'icon' => '📊'],
    ['field' => 'budget',     'label' => 'Budget',     'suffix' => '',    'icon' => '💰'],
    ['field' => 'level',      'label' => 'Level',      'suffix' => '',    'icon' => '🎯'],
]));

define('POST_SECTION_LABELS', _cfg('POST_SECTION_LABELS', [
    'why_this_works'  => 'Why This Works',
    'ingredients'     => "What You'll Need",
    'instructions'    => 'How To Do It',
    'pro_tips'        => 'Pro Tips',
    'common_mistakes' => 'Common Mistakes to Avoid',
    'variations'      => 'Variations',
    'nutrition'       => 'Nutrition',
    'storage'         => 'Storage & Tips',
    'faq'             => 'FAQ',
    'conclusion'      => 'Final Thoughts',
    'introduction'    => 'Introduction',
]));

define('POST_LAYOUT', _cfg('POST_LAYOUT', ['breadcrumb','header','image_1','description','introduction','story','why_this_works','image_2','ingredients','instructions','image_3','pro_tips','common_mistakes','variations','nutrition','storage','faq','conclusion','tips']));

define('PIN_SCHEDULE_START', _cfg('PIN_SCHEDULE_START', 16));
define('PIN_SCHEDULE_END',   _cfg('PIN_SCHEDULE_END',   4));

define('FACEBOOK_PAGE_URL',       _cfg('FACEBOOK_PAGE_URL',       ''));
define('FACEBOOK_CTA_TEXT',       _cfg('FACEBOOK_CTA_TEXT',       'Get the full recipe at'));
define('FACEBOOK_HASHTAGS',       _cfg('FACEBOOK_HASHTAGS',       ''));
define('FACEBOOK_FRAME_DURATION', _cfg('FACEBOOK_FRAME_DURATION', 4));
define('FACEBOOK_FFMPEG_PATH',    _cfg('FACEBOOK_FFMPEG_PATH',    'ffmpeg'));
define('FACEBOOK_PAGE_ID',        _cfg('FACEBOOK_PAGE_ID',        ''));
define('FACEBOOK_ACCESS_TOKEN',   _cfg('FACEBOOK_ACCESS_TOKEN',   ''));
define('FACEBOOK_POST_HOUR_START',_cfg('FACEBOOK_POST_HOUR_START', 16));
define('FACEBOOK_POST_HOUR_END',  _cfg('FACEBOOK_POST_HOUR_END',   4));
define('FACEBOOK_DAILY_COUNT',    _cfg('FACEBOOK_DAILY_COUNT',     5));
define('FACEBOOK_CROSSPOST_ACTIVE', (bool)_cfg('FACEBOOK_CROSSPOST_ACTIVE', true));
define('FACEBOOK_POST_TYPE',       _cfg('FACEBOOK_POST_TYPE',       'photo')); // 'photo' or 'video'

define('YOUTUBE_CLIENT_ID',        _cfg('YOUTUBE_CLIENT_ID',        ''));
define('YOUTUBE_CLIENT_SECRET',    _cfg('YOUTUBE_CLIENT_SECRET',    ''));
define('YOUTUBE_REFRESH_TOKEN',    _cfg('YOUTUBE_REFRESH_TOKEN',    ''));
define('YOUTUBE_CHANNEL_ID',       _cfg('YOUTUBE_CHANNEL_ID',       ''));
define('YOUTUBE_DAILY_COUNT',      _cfg('YOUTUBE_DAILY_COUNT',      3));
define('YOUTUBE_POST_HOUR_START',  _cfg('YOUTUBE_POST_HOUR_START',  10));
define('YOUTUBE_POST_HOUR_END',    _cfg('YOUTUBE_POST_HOUR_END',    20));
define('YOUTUBE_MIN_GAP_MINUTES',  _cfg('YOUTUBE_MIN_GAP_MINUTES',  60));
define('YOUTUBE_PRIVACY_STATUS',   _cfg('YOUTUBE_PRIVACY_STATUS',   'public'));
define('YOUTUBE_CATEGORY_ID',      _cfg('YOUTUBE_CATEGORY_ID',      '26'));
define('YOUTUBE_CTA_TEXT',         _cfg('YOUTUBE_CTA_TEXT',         'Full recipe at'));
define('YOUTUBE_HASHTAGS',         _cfg('YOUTUBE_HASHTAGS',         '#recipes #food #easyrecipes'));
define('YOUTUBE_TITLE_SUFFIX',     _cfg('YOUTUBE_TITLE_SUFFIX',     '| Easy Recipe'));

define('HOST_NAME',        _cfg('HOST_NAME',        'www.pinposts.org'));
define('HOMEPAGE_TITLE',   _cfg('HOMEPAGE_TITLE',   'Pin Posts'));
define('HOMEPAGE_TAGLINE', _cfg('HOMEPAGE_TAGLINE', 'Pin Posts - Discover great content'));
define('SITE_MANAGER',     _cfg('SITE_MANAGER',     'info@pinposts.org'));
define('SITE_WEBMASTER', _cfg('SITE_WEBMASTER', 'webmaster@pinposts.org'));
define('SITE_LANGUAGE',  _cfg('SITE_LANGUAGE',  'en-US'));
define('SITE_CSS',       _cfg('SITE_CSS',       'style.css')); // Active CSS theme (style.css | tasty.css | ...)
define('SITE_FOLDER', basename(__DIR__)); // auto-derived from project folder name

define('KEYWORDS_PIN_DIR', _cfg('KEYWORDS_PIN_DIR', realpath(__DIR__ . '/keywordsPIN') ?: __DIR__ . '/keywordsPIN'));

// Source des keywords: 'prompt' | 'google_suggest' | 'pinterest_trends' | 'auto'
define('KEYWORD_SOURCE', _cfg('KEYWORD_SOURCE', 'pinterest_trends'));

// Pinterest Trends options (utilisés si KEYWORD_SOURCE = 'pinterest_trends' ou 'auto')
define('PINTEREST_TRENDS_TYPE',            _cfg('PINTEREST_TRENDS_TYPE',            'growing'));   // growing | seasonal | top_monthly | top_yearly
define('PINTEREST_TRENDS_INTEREST',        _cfg('PINTEREST_TRENDS_INTEREST',        'FOOD_AND_DRINKS'));
define('PINTEREST_TRENDS_GENDER',          _cfg('PINTEREST_TRENDS_GENDER',          'female'));    // female | male | all
define('PINTEREST_TRENDS_AGES',            _cfg('PINTEREST_TRENDS_AGES',            '50-54,55-64,65+'));
define('PINTEREST_TRENDS_COUNTRY',         _cfg('PINTEREST_TRENDS_COUNTRY',         'US'));
define('PINTEREST_TRENDS_MOMENTS',         _cfg('PINTEREST_TRENDS_MOMENTS',         ''));          // ex: Summer,Christmas,Thanksgiving (vide = aucun filtre)
define('PINTEREST_TRENDS_INCLUDE_KEYWORD', _cfg('PINTEREST_TRENDS_INCLUDE_KEYWORD', 'recipe'));   // mot-clé de niche injecté dans chaque query (ex: recipe, healthy, easy)
define('PINTEREST_TRENDS_IMPORT_PATH',     _cfg('PINTEREST_TRENDS_IMPORT_PATH',     __DIR__ . '/downloads/pinterest-trends-import.csv'));
define('PINTEREST_TRENDS_IMPORT_MAX_DAYS', _cfg('PINTEREST_TRENDS_IMPORT_MAX_DAYS', 7));          // jours avant expiration du fichier importé

// Seeds de base pour Google Suggest (une par ligne, complétées par les ingrédients saisonniers)
define('KEYWORD_SUGGEST_SEEDS', _cfg('KEYWORD_SUGGEST_SEEDS',
    "chicken\npasta\nsalad\nsoup\ndesert\ncake\ncookies\ndinner\nbreakfast\nsnack\ncasserole\nstir fry\nsheet pan\ninstant pot\nair fryer"
));

define('POST_PROMPT', _prompt('POST_PROMPT', 'Create a natural, authentic post post in English that feels written by a real food blogger, not AI.

    CRITICAL: Vary your writing style, length, and structure for EACH post to appear authentically human-written.

    JSON STRUCTURE:
    {
        "title": "Natural post title (40-100 characters, vary the length):
        
        Authentic Styles (rotate between these):
        - Classic: Grandma\'s Chocolate Chip Cookies
        - Benefit: Easy 30-Minute Chicken Stir Fry
        - Descriptive: Creamy Garlic Parmesan Pasta
        - Simple: The Best Cornbread Post
        
        Rules:
        ✓ Sound natural, not SEO-stuffed
        ✓ Vary length: some short (40 chars), some longer (90 chars)
        ✓ Use conversational language",
        
        "promptIMG": "Food photography: [dish name], appetizing presentation, natural lighting, kitchen setting, documentary style, realistic colors --ar 4:3 --quality 2",
        
        "description": "Write 150-250 character natural description (VARY the length):
        
        Natural Formula:
        - What it is + why it\'s good
        - One special ingredient or technique
        - When to serve it
        
        Example: This creamy chicken pasta is comfort food at its best. Ready in 30 minutes with a rich garlic sauce. Perfect for busy weeknights!
        
        ✓ Write like texting a friend
        ✓ No formulaic structure
        ✓ Be genuine and specific",

        "hashtags": "3-5 relevant hashtags (VARY the number):
        #postname #category #benefit
        Keep it simple and natural",

        "pinterest_boards": {
            "classic": "Board name for Template 1 (sandwich layout) — REUSE from the provided existing boards list if any match. Only create a new name if none fit. Focus on the main recipe category (e.g. \"Easy Bread Recipes\", \"Comfort Food Dinners\", \"Quick Pasta Dishes\")",
            "header": "Board name for Template 2 (header layout) — REUSE from the provided existing boards list if any match. Only create a new name if none fit. Focus on technique or meal context (e.g. \"Sourdough Baking Tips\", \"Healthy Weeknight Meals\", \"30-Minute Dinners\")",
            "cinematic": "Board name for Template 3 (cinematic layout) — REUSE from the provided existing boards list if any match. Only create a new name if none fit. Broader lifestyle angle (e.g. \"Artisan Bread For Beginners\", \"Family Dinner Ideas\", \"Weekend Baking Projects\")"
        },

        "difficulty": "Easy|Medium|Hard",
        "prep_time": 15,
        "cook_time": 30,
        "servings": 4,
        
        "ingredients": [
            "Clear measurements + ingredient + state",
            "In order of use when logical"
        ],

        "yield": "e.g. Approximately 6 cups, 4 servings",

        "introduction": "Write 2-4 paragraphs (150-250 words total) personal introduction about this post:
        - Open with your personal connection, backstory, or when you first made/discovered this dish
        - Explain what makes this version better or different from standard posts
        - Mention 1-2 specific techniques or secrets that make it succeed
        - Set expectations: what the finished result tastes/feels/looks like
        ✓ First person, conversational tone (\'I\'ve been making this...\', \'the trick I learned...\')
        ✓ Include at least one honest detail (a past failure, a discovery, a family memory)
        ✓ Sound like a home cook sharing with a friend, not a post developer pitching",

        "instructions": [
            {
                "step": 1,
                "instruction": "80-130 words: detailed action + technique explanation + WHY this step matters + what to watch for + common mistake to avoid at this step. Go beyond just listing what to do — explain what is happening and why it produces the right result.",
                "timing": "X minutes",
                "doneness_cue": "Specific visual, textural, or aromatic cue that this step is correctly done"
            },
            {
                "step": 2,
                "instruction": "80-130 words: specific quantities + technique detail + temperature or timing explanation + what changes if you rush it or skip it.",
                "timing": "X minutes",
                "doneness_cue": "How to know this step is complete — be precise and sensory (color, sound, texture, smell)"
            }
        ],

        "why_this_post_works": {
            "section": "Natural section title like \'Understanding Why This Formula Succeeds\' or \'the Science Behind This Post\'",
            "content": "<p>3-4 detailed paragraphs (400-600 words total) explaining the science or technique that makes this post succeed. Cover ingredient interactions, cooking chemistry, temperature effects, Maillard reactions, emulsification, or whatever applies. Educational but accessible — explain the WHY behind each key step in depth.</p>",
            "ingredient_notes": {
                "key_ingredient_name": "70-100 word note: what to look for when buying, why this specific type/form works best, how it functions in the post, any important handling details or substitution guidance",
                "second_key_ingredient": "70-100 word explanation covering its role in the post, selection tips, why it matters to the final result, and any common mistakes with this ingredient",
                "third_ingredient": "70-100 word note on technique, substitutions allowed vs not, and why it affects texture, flavor, or structure",
                "fourth_ingredient": "70-100 word note explaining its chemical or textural role, how quality affects results, and what happens if you substitute or omit it",
                "fifth_ingredient_if_applicable": "70-100 word note covering origin, how to select best quality, and how it interacts with other ingredients"
            }
        },

        "pro_tips": [
            {
                "tip": "Actionable tip in 1-2 sentences — specific and practical",
                "reason": "70-100 words: explain the cooking science behind this tip, what happens if you ignore it, and why it matters to the final result"
            },
            {
                "tip": "Another practical tip that makes a real difference",
                "reason": "70-100 words: logic, science, or technique behind it — make the reader understand WHY, not just WHAT"
            },
            {
                "tip": "A third tip covering equipment, timing, or temperature",
                "reason": "70-100 words: detailed explanation with real consequences of not following it"
            }
        ],

        "common_mistakes_to_avoid": [
            {
                "mistake": "Name the common mistake clearly",
                "why_it_happens": "50-70 words: explain the psychology or assumption behind why cooks make this error",
                "fix": "70-100 words: specific, actionable fix with exact technique, temperature, or timing correction"
            }
        ],

        "variations": [
            {
                "name": "Variation name",
                "description": "80-110 words: how to adapt the post — ingredient swaps, technique changes, flavor profile shift, and what to expect differently in texture and taste",
                "technical_note": "40-60 words: technique adjustment, timing change, or texture difference to watch for"
            }
        ],

        "nutrition": {
            "calories_per_serving": "420 kcal",
            "protein_grams": "28g",
            "carbohydrates_grams": "35g",
            "fat_grams": "18g",
            "fiber_grams": "4g",
            "sodium_mg": "580mg",
            "note": "Nutritional values are estimates and may vary based on specific ingredients used."
        },

        "storage_and_reheating": {
            "room_temperature": "How long it safely keeps at room temperature and how to cover/store it",
            "refrigerator": "Store in airtight container for up to X days. Note how flavor or texture changes when served cold and whether to reheat or serve cold.",
            "freezer": "Freeze for up to X months. How to wrap properly, prevent freezer burn, thaw safely, and restore texture after freezing.",
            "reheating": "Specific reheating method (stovetop/microwave/oven), ideal temperature, time, what to add if needed (splash of liquid, etc.), and what to avoid",
            "make_ahead_strategy": "What steps can be done 1-2 days ahead, how to store the partially prepared dish, and what to do the day of serving"
        },

        "faq": [
            {
                "question": "A common question readers have about this specific post (troubleshooting, substitutions, timing, storage)",
                "answer": "80-120 words: specific, practical answer that genuinely solves the problem. Include the WHY, not just the WHAT. 8-10 FAQ entries required."
            },
            {
                "question": "Another practical question about technique, ingredients, or results",
                "answer": "80-120 words: helpful, detailed answer based on real cooking knowledge"
            }
        ],

        "conclusion": "1-2 sentence warm closing. Invite readers to try the post and share results.",

        "seo": {
            "primary_keyword": "main 2-5 word search phrase for this specific post (e.g. \'southern pecan pie post\')",
            "secondary_keywords": [
                "related keyword phrase 1",
                "related keyword phrase 2",
                "how to make [post name]",
                "best [post name] post",
                "easy [post name]"
            ],
            "internal_links": [
                {"text": "related post or technique text", "anchor": "anchor_slug_here"}
            ],
            "focus_keyword_density": "1.0-1.5%",
            "reading_level": "8th grade",
            "word_count_total": "approximately X words"
        },

        "pin_variations": [
            {
                "title": "Variation 1 (Original): exact recipe title, max 60 chars",
                "description": "Keyword-rich Pinterest description with SEO terms + 3-5 hashtags, max 500 chars"
            },
            {
                "title": "Variation 2 (Curiosity hook): rewrite title with a curiosity gap — secret, surprising fact, or common mistake. Max 60 chars.",
                "description": "Pinterest description highlighting the surprising angle + 3-5 hashtags, max 500 chars"
            },
            {
                "title": "Variation 3 (Value/Number hook): rewrite title with a number or did-you-know hook. Max 60 chars.",
                "description": "Pinterest description emphasizing the tip/value + 3-5 hashtags, max 500 chars"
            },
            {
                "title": "Variation 4 (Emotional/Seasonal): rewrite title targeting an emotion, occasion, or season (cozy, family, holiday, quick). Max 60 chars.",
                "description": "Pinterest description evoking emotion or occasion + 3-5 hashtags, max 500 chars"
            }
        ]
    }

    CRITICAL AUTHENTICITY RULES:
    ✓ Use NATURAL language - write like a food blogger, not SEO copywriter
    ✓ Keyword density: 4-8 times MAXIMUM (not 10-15!)
    ✓ Imperfect is better - sound human, not AI
    ✓ Personal touches - "I love", "My family", "Works great when"
    ✓ Vary paragraph length - some 1 sentence, some 4 sentences
    ✓ Use contractions: "it\'s", "you\'ll", "don\'t"
    ✓ Occasional opinions: "I think", "in my experience"

    MANDATORY MINIMUM CONTENT PER FIELD (NON-NEGOTIABLE):
    ✓ "introduction": REQUIRED string, 250-350 words — NEVER null, NEVER omit this field
    ✓ "why_this_post_works": REQUIRED OBJECT (never a plain string!) with keys:
        - "section": section title string
        - "content": 400-600 words HTML string (3-4 paragraphs cooking science)
        - "ingredient_notes": object with 5-8 key ingredient names, each 70-100 words
    ✓ instructions: MINIMUM 9 steps, each step 100-150 words (include technique rationale, not just actions)
    ✓ pro_tips: 7-10 tips, each reason field 70-100 words of explanation
    ✓ common_mistakes_to_avoid: 5-7 mistakes, each fix 70-100 words
    ✓ variations: 5-7 variations, each description 80-110 words with technical_note
    ✓ storage_and_reheating: each field (room_temperature, refrigerator, freezer, reheating, make_ahead_strategy) 80-120 words
    ✓ faq: 8-10 Q&A pairs, each answer 80-120 words (troubleshooting, substitutions, timing)
    ✓ TOTAL: 4000-6000 words of actual content (ALL fields combined)

    NATURAL WRITING STYLE:
    ✓ First person occasionally: "I like to...", "I usually..."
    ✓ Second person casually: "You\'ll love...", "Try adding..."
    ✓ Mix sentence lengths
    ✓ Some shorter paragraphs (2 sentences)
    ✓ Some longer paragraphs (5 sentences)
    ✓ Transition words naturally: "Also,", "Plus,", "Another thing,"
    ✓ Questions occasionally: "Want to make it spicier?"

    JSON VALIDATION (CRITICAL):
    ✓ Valid JSON only
    ✓ Double quotes for strings
    ✓ Escape quotes: \"
    ✓ Numbers without quotes: "prep_time": 15
    ✓ No trailing commas
    ✓ Boolean: true/false (no quotes)

    ALLOWED HTML:
    ✓ <p> <ul> <li> <ol> <strong> <em> <dl> <dt> <dd>
    ✗ No heading tags, div, span, or inline CSS

    INTELLIGENCE:
    ✓ Fill missing details logically
    ✓ Authentic techniques for cuisine type
    ✓ Realistic timing
    ✓ Appropriate suggestions
    ✓ Cultural accuracy

    Source Text:
{SOURCE_TEXT}'));




define('REWRITE_POST_PROMPT', _prompt('REWRITE_POST_PROMPT', '
    You are an expert food blogger and SEO content writer. Rewrite this post article to meet premium ad network standards (AdSense/Ezoic) and Google\'s E-E-A-T quality guidelines.

    ═══════════════════════════════════════
    🎯 VOICE & AUTHENTICITY
    ═══════════════════════════════════════
    - Write in first-person as an experienced home cook
    - Conversational but knowledgeable tone
    - Include sensory details: smells, textures, sounds, colors
    - Reference real occasions, seasons, or cooking memories naturally
    - Vary paragraph length (2-5 sentences)
    - Neutral, editorial style — NOT Pinterest captions or influencer tone

    ❌ BANNED AI PHRASES (never use):
    "elevate," "whip up," "game-changer," "mouthwatering," "delightful," 
    "burst of flavor," "culinary journey," "taste buds," "perfect for," 
    "look no further," "dive in," "let\'s get started"

    ═══════════════════════════════════════
    📊 CONTENT QUALITY (E-E-A-T + AdSense)
    ═══════════════════════════════════════
    - Demonstrate hands-on experience ("I\'ve tested this 15+ times...")
    - Explain WHY techniques work (heat, fat, moisture, timing, chemistry)
    - Add real value: cooking logic, common failures, ingredient behavior
    - Every section must provide unique, helpful information
    - No thin content, no filler, no repetition
    - No duplicate phrasing across sections
    - Minimum: 1,000–1,300 words of original content

    ═══════════════════════════════════════
    🔎 SEO STRUCTURE
    ═══════════════════════════════════════
    - H1: Post name with primary keyword
    - H2: All major sections
    - H3: Subsections where logical
    - Front-load important info in each section
    - Use semantic keywords naturally (don\'t force)
    - Numbered lists for instructions
    - Bullet points for tips and lists
    - No repeated section titles

    ═══════════════════════════════════════
    📝 REQUIRED SECTIONS (ALL MANDATORY)
    ═══════════════════════════════════════

    ### 1. INTRODUCTION (100-150 words)
    - Hook with personal, real context (how/when you make it)
    - What makes THIS version special or different
    - Who it\'s perfect for and best occasions
    - Smooth, natural transition to the post

    ### 2. WHY THIS POST WORKS
    - Explain the cooking science in simple terms
    - Cover: texture, flavor balance, moisture, structure
    - Help readers understand the "why" behind steps
    - Written clearly for home cooks, not chefs

    ### 3. INGREDIENTS
    - Exact measurements (metric + imperial if possible)
    - Group by component for complex posts
    - Inline substitutions in parentheses (only if technically accurate)
    - Note ingredient quality/freshness when it matters
    - Format: clean list, easy to scan

    ### 4. INSTRUCTIONS 
    Each step must include:
    - ONE main action per step
    - Timing (approximate minutes)
    - Visual/sensory doneness cues ("until edges turn golden brown")
    - Embedded technique tips where helpful
    - Clear indicators: color changes, aromas, textures, sounds

    ### 5. PRO TIPS (4-6 items)
    - Based on real cooking experience
    - Specific and actionable (not generic advice)
    - Explain the reasoning behind each tip
    - Format: bullet points

    ### 6. COMMON MISTAKES TO AVOID (3-4 items)
    - Describe the mistake clearly
    - Explain WHY it happens
    - Give the fix or prevention method
    - Use cooking logic, not just rules

    ### 7. VARIATIONS (3-5 options)
    - Dietary: gluten-free, vegan, dairy-free, low-sodium
    - Flavor twists: spices, herbs, regional styles
    - Seasonal adaptations
    - Only include realistic, tested substitutions

    ### 8. NUTRITION INFORMATION
    - Calories per serving (approximate)
    - Key macros if relevant (protein, carbs, fat)
    - State clearly: "Values are estimates and may vary"
    - NO health claims or medical advice

    ### 9. STORAGE, REHEATING & MAKE-AHEAD
    - Refrigerator: exact days
    - Freezer: duration + freezing instructions (if applicable)
    - Best reheating method with specific instructions
    - One practical make-ahead tip

    ### 10. FAQ (4-6 questions)
    Write questions exactly as users would search Google:
    - "Can I make [post] ahead of time?"
    - "Why is my [post] too [problem]?"
    - "Can I use [substitute] instead of [ingredient]?"
    - "How long does [post] last in the fridge?"
    Keep answers: short, direct, helpful (2-3 sentences max)

    ### 11. CONCLUSION (40-60 words)
    - Warm, human closing
    - Invite readers to comment or share feedback
    - No promotional language or sales pitch

    ═══════════════════════════════════════
    🛠 TECHNICAL REQUIREMENTS
    ═══════════════════════════════════════
    - Maintain existing JSON structure
    - Include meta fields:
    • prep_time
    • cook_time  
    • total_time
    • servings
    • difficulty (easy/medium/hard)
    - Write descriptive alt text for images
    - Suggest internal links: [LINK: related post or topic]
    - Content must be schema.org post-ready

    ═══════════════════════════════════════
    🚫 STRICTLY AVOID
    ═══════════════════════════════════════
    - Keyword stuffing
    - Repetitive phrases or words
    - Over-casual slang or emojis
    - Influencer/Pinterest voice
    - Promotional or affiliate language
    - Unsupported health/medical claims
    - Filler content that doesn\'t help the reader
    - Clickbait or exaggerated claims
    - Plagiarized or thin content

    ═══════════════════════════════════════
    📤 OUTPUT FORMAT
    ═══════════════════════════════════════
    Return a complete JSON object with all sections, not change strecture json ans paths img and slug , ready for publishing.
    The article should read as genuinely written by an experienced cook,
    not generated by AI. Every paragraph must add value.

    ⚠️ MANDATORY JSON FIELDS — NEVER OMIT THESE:
    • "introduction"  → string, 150-250 words, personal story + technique insight (NEVER null)
    • "why_this_post_works" → OBJECT with keys: "section" (string), "content" (HTML string), "ingredient_notes" (object with 3-5 ingredient keys each 60-80 words) — NOT a plain string
    • "storage_and_reheating" → OBJECT with EXACTLY these keys: "room_temperature", "refrigerator", "freezer", "reheating", "make_ahead_strategy"
    • "pro_tips" → array of objects each with "tip" and "reason" keys (5-7 items)
    • "common_mistakes_to_avoid" → array of objects each with "mistake", "why_it_happens", "fix" keys
    • "variations" → array of objects each with "name", "description", "technical_note" keys
    • "faq" → array of objects each with "question" and "answer" keys (5-6 items)
    • "pin_variations" → array of EXACTLY 4 objects, each with "title" (max 60 chars) and "description" (max 500 chars + 3-5 hashtags) keys:
      - Variation 1 (Original): exact recipe title + keyword-rich description
      - Variation 2 (Curiosity hook): rewrite title with curiosity gap (secret, surprising fact, common mistake)
      - Variation 3 (Value/Number hook): rewrite title with number or tip hook
      - Variation 4 (Emotional/Seasonal): rewrite title targeting emotion, occasion, or season

    Now rewrite the following post:
    [PASTE POST HERE]

    EX Otpout file JSON : 
    {
    "title": "Beef Stew: Building Flavor and Managing Protein Structure",
    "promptIMG": "Professional food photography prompt: \"hearty beef stew, served in rustic bowl, natural food photography, balanced sharp focus throughout entire image, zero blur, infinite depth of field, realistic food textures, modern kitchen background in sharp focus, natural window lighting, kitchen countertop visible, even focus distribution, documentary food photography style, professional food styling, appetizing presentation --no blur, depth of field, bokeh, over-sharpening, artificial enhancement --quality 2 --ar 4:3 --stylize 300\"",
    "isOnline": true,
    "description": "A guide to making beef stew that extracts maximum flavor from the beef and builds a properly textured broth. Learn why specific cuts work, how browning creates flavor compounds, and how to time vegetable additions for ideal texture in each component.",
    "hashtags": "#beefstew #braisedbeef #comfortfood #slowcooking #homemade",
    "pinterest_boards": {
        "classic": "Hearty Beef Stew Posts",
        "header": "Slow Cooker Comfort Food",
        "cinematic": "Easy Family Dinner Ideas"
    },

    "difficulty": "Medium",
    "prep_time": 20,
    "cook_time": 120,
    "total_time": 140,
    "servings": 6,
    "yield": "Approximately 8 cups of stew, 6 servings",
    "ingredients": [
        "2 lbs (900g) beef chuck, cut into 1.5-inch (4cm) pieces",
        "3 tbsp (45ml) neutral oil or bacon fat, divided",
        "1 large yellow onion (300g), cut into 1-inch (2.5cm) pieces",
        "4 cloves (20g) garlic, sliced",
        "2 tbsp (30g) tomato paste",
        "4 cups (960ml) beef broth, unsalted preferred",
        "1 cup (240ml) water or additional broth",
        "2 tsp (10g) fine sea salt, divided",
        "1/2 tsp (1g) black pepper",
        "1.5 tsp (3g) dried thyme",
        "1 tsp (2g) dried rosemary, crushed",
        "1 bay leaf",
        "1 tbsp (15ml) Worcestershire sauce",
        "3 large carrots (350g), cut into 1.5-inch (4cm) pieces",
        "3 medium potatoes (450g), cut into 1-inch (2.5cm) cubes",
        "1 cup (150g) frozen peas, added near end",
        "Optional: 1 tbsp (8g) cornstarch mixed with 2 tbsp (30ml) cold water for thickening"
    ],
    "introduction": "I\'ve been making beef stew since my mother taught me that the browning step matters most. For years I rushed it, and the stew always tasted flat. Once I learned to give the beef proper time in a ripping-hot pan — working in batches, never crowding — everything changed. The deep, savory flavor that develops during those 15-20 minutes of browning is irreplaceable. No amount of seasoning compensates for skipping it. This post builds on that lesson: high heat for flavor development first, then low and slow for tenderness. The result is a stew with a rich, gelatin-thick broth and beef that falls apart without ever feeling mushy.",

    "why_this_post_works": {
        "section": "Why This Beef Stew Post Works",
        "content": "<p>Beef stew\'s success depends on understanding two key transformations. First is the Maillard reaction, which creates flavor compounds when beef is browned at high temperature. When amino acids and sugars react above 300°F (149°C), they form hundreds of new compounds that create the savory, complex flavors that make stew taste rich and developed. Without proper browning, stew tastes flat and one-dimensional — no amount of added seasoning compensates.</p><p>The second transformation is collagen breakdown. Beef chuck is ideal because it contains high amounts of collagen and connective tissue. When exposed to moist heat at 160-180°F (71-82°C) for extended time, this collagen breaks down into gelatin, which dissolves into the broth and creates body and silky mouthfeel. This process cannot be rushed — boiling causes muscle fibers to contract and squeeze out moisture, making the meat tough and dry.</p>",
        "ingredient_notes": {
            "beef chuck": "Chuck is the only cut worth using here. It contains roughly 25-30% collagen by weight, which converts to gelatin during the long braise and gives the stew its signature silky broth. Leaner cuts like sirloin have minimal collagen and turn dry and stringy after extended cooking. Buy pre-cut stew beef or ask the butcher for 1.5-inch pieces.",
            "beef broth": "Unsalted broth gives you full control over final saltiness. During 2 hours of simmering, liquid reduces by 20-30%, concentrating any salt already present. Starting with pre-salted broth frequently causes an oversalted dish. Add salt early in the process, taste at the end, and adjust only then.",
            "tomato paste": "Caramelizing tomato paste for 1-2 minutes transforms its flavor completely. Raw paste tastes sharp and tinny. Caramelized paste develops a sweeter, deeper umami note that anchors the stew\'s flavor profile. The color darkens and the paste sticks to the pan slightly — that\'s exactly the right sign."
        }
    },
    "instructions": [
        {
            "step": 1,
            "instruction": "Remove the beef from the refrigerator 15-20 minutes before cooking. Cold beef will cool the pan when added, reducing the surface temperature and preventing proper browning. While the beef comes to room temperature, pat it completely dry using paper towels. Moisture on the surface prevents the beef from reaching the high temperature needed for the Maillard reaction. You\'ll notice that dry beef browns much more visibly and quickly than moist beef.",
            "timing": "15-20 minutes",
            "doneness_cue": "Beef feels cool to the touch but not cold, surface is completely dry with no moisture visible"
        },
        {
            "step": 2,
            "instruction": "Place a large, heavy-bottomed Dutch oven or pot over medium-high heat. Add 1 tablespoon (15ml) of oil and let it heat until it shimmers and small wisps of smoke appear—this indicates the oil has reached approximately 350-375°F (175-190°C), the temperature needed for the Maillard reaction. Working in batches to avoid crowding, add beef pieces in a single layer without touching or overlapping. Leave them undisturbed for 2-3 minutes to allow the bottom surface to brown and develop a crust. Crowding the pan lowers the temperature and prevents browning; it\'s better to cook three batches of beef than two crowded batches.",
            "timing": "2-3 minutes per side, 3 batches total (approximately 18-21 minutes)",
            "doneness_cue": "Beef develops deep golden-brown crust on contact surface, meat does not stick to pan, visible browning on all sides after flipping and cooking second side"
        },
        {
            "step": 3,
            "instruction": "Once each batch is browned on multiple sides, transfer it to a plate. Once all beef is browned and set aside, add 1 more tablespoon (15ml) of oil to the pot if the pan appears dry. Add the diced onion and a pinch of salt (about 1/4 teaspoon). The salt draws out moisture from the onion, helping it release its juices and cook more quickly. Sauté the onion, stirring occasionally, for 4-5 minutes until it becomes translucent and softened. Add the sliced garlic and cook for another minute until fragrant.",
            "timing": "5-6 minutes",
            "doneness_cue": "Onion is translucent and softened, garlic releases strong aroma, no raw garlic smell remains"
        },
        {
            "step": 4,
            "instruction": "Add the tomato paste to the pot and stir it constantly for 1-2 minutes. This step, called \"caramelizing\" tomato paste, allows the paste\'s compounds to break down slightly and concentrate. The paste will darken slightly in color and deepen in aroma. This step is crucial because tomato paste contributes umami (savory flavor) and acidity that enhances all other flavors in the stew. Skipping this step results in a flatter-tasting final product.",
            "timing": "1-2 minutes",
            "doneness_cue": "Tomato paste darkens in color, intensifies in aroma, mixture becomes slightly thicker"
        },
        {
            "step": 5,
            "instruction": "Return all browned beef to the pot and stir to coat it with the tomato paste mixture. Then pour in the beef broth and water. The liquid should come about halfway to three-quarters of the way up the beef pieces. Add the bay leaf, thyme, rosemary, Worcestershire sauce, and 1.5 teaspoons (9g) of salt. The salt should be added now, before simmering, not at the end. This allows the salt to penetrate the meat as it braises. Stir everything together well, then increase the heat to medium-high and bring the liquid to a simmer. A simmer means small bubbles break the surface of the liquid at a steady but gentle rate—not a rolling boil where large bubbles burst aggressively. A simmer, not a boil, is essential because boiling causes the beef muscle fibers to contract too tightly and squeeze out moisture.",
            "timing": "5-10 minutes to reach simmer",
            "doneness_cue": "Liquid steams, small bubbles form at the bottom and rise gently, surface is mostly still with occasional small bubbles breaking through"
        },
        {
            "step": 6,
            "instruction": "Once simmering, cover the pot with a lid and reduce the heat to low. You want the stew to maintain a gentle simmer—never a boil. If you see large bubbles bursting at the surface, reduce the heat further. This gentle, low-temperature environment allows the collagen in the beef to break down slowly into gelatin. Let the stew simmer gently, covered, for 1.5 hours. After 1.5 hours, test the beef by piercing a piece with a fork—it should offer no resistance. If the fork slides through easily, the beef is ready for vegetables. If there\'s still some resistance, continue simmering for another 15-30 minutes and check again.",
            "timing": "1.5 hours, potentially longer",
            "doneness_cue": "Fork slides through beef pieces with zero resistance, meat falls apart if pressed, liquid has reduced by approximately 20%, flavor is rich and complex"
        },
        {
            "step": 7,
            "instruction": "Remove the lid and add the carrot and potato pieces. The pot is now at approximately 1.75 hours of total cooking time. The vegetables will cook in the simmering liquid for about 25-30 minutes. Stir well to distribute vegetables evenly throughout the stew. Do not cover the pot for the vegetable cooking phase, as moisture needs to evaporate and concentrate the broth\'s flavors.",
            "timing": "0 minutes (vegetables being added)",
            "doneness_cue": "Vegetables are distributed throughout liquid, beef is submerged"
        },
        {
            "step": 8,
            "instruction": "After 25-30 minutes of cooking with vegetables, check for doneness by testing a potato piece with a fork. The potato should be tender but not falling apart. The carrot should also be tender but still maintain its shape. This is approximately 2 hours of total cooking time. At this point, taste the stew and assess the liquid consistency. If the stew seems thin and brothy, you can create a slurry by mixing 1 tablespoon (8g) of cornstarch with 2 tablespoons (30ml) of cold water, stirring until smooth, then adding it to the pot while stirring constantly. The liquid will thicken over 1-2 minutes. If you prefer a thinner stew, this step is optional.",
            "timing": "25-30 minutes",
            "doneness_cue": "Potato fork-tender, carrot fork-tender but holding shape, onion has completely softened and distributed throughout"
        },
        {
            "step": 9,
            "instruction": "Add the frozen peas and stir well. These only need 3-5 minutes to cook—any longer and they become mushy and lose their bright color. Simmer uncovered for exactly 3-5 minutes. Taste the stew now and adjust seasoning with additional salt and pepper if needed. Remember you added salt early in the cooking process, so taste before adding more. The Worcestershire sauce should provide some umami depth, but you can add an additional 1/2 teaspoon if the stew tastes flat.",
            "timing": "3-5 minutes",
            "doneness_cue": "Peas are heated through and bright green, stew is at full temperature, flavors are well-developed"
        },
        {
            "step": 10,
            "instruction": "Remove from heat and serve immediately in bowls. If serving later, keep the stew at a gentle simmer over low heat or transfer to a slow cooker set to warm. Stew is best served hot with crusty bread for soaking up the broth. The longer the stew sits after cooking, the more flavors meld and develop, so stew tastes even better the next day.",
            "timing": "0 minutes (serving time)",
            "doneness_cue": "Stew is steaming, all ingredients are tender, flavors are fully developed"
        }
    ],
    "pro_tips": [
        {
            "tip": "Use beef chuck specifically. Avoid leaner cuts like sirloin or tenderloin. Chuck contains high collagen content that becomes gelatin during cooking, creating the stew\'s characteristic body and silky mouthfeel.",
            "reason": "Leaner cuts have minimal connective tissue and collagen. When braised, they become dry and tough because there\'s no collagen breakdown to keep them tender. Chuck\'s high fat content also contributes flavor. This is why expensive, lean cuts actually perform poorly in stew."
        },
        {
            "tip": "Ensure beef is completely browned on all sides before moving to the next step. Don\'t skip browning or rush through it to save time.",
            "reason": "The Maillard reaction creates hundreds of flavor compounds that don\'t exist in unbrowned beef. These compounds create the savory, complex flavor that defines good stew. Unbrowned beef stew tastes thin and one-dimensional no matter what else you do."
        },
        {
            "tip": "Use unsalted broth if possible. This gives you control over final salt content and prevents over-salting. Taste the finished stew before adding additional salt.",
            "reason": "Many store-bought broths are very salty. During the 2-hour cooking process, liquid reduces by approximately 20-30%, concentrating the salt. Starting with salted broth can result in an oversalted final product. Unsalted broth lets you control the salt precisely."
        },
        {
            "tip": "Maintain a gentle simmer throughout cooking, never a boil. If you see vigorous bubbling, reduce the heat immediately.",
            "reason": "Boiling causes beef muscle fibers to contract tightly and squeeze out moisture, making the meat tough. The ideal temperature for collagen breakdown (160-180°F / 71-82°C) is below boiling point anyway. A gentle simmer achieves both collagen breakdown and tender, moist beef."
        },
        {
            "tip": "Add vegetables in stages rather than all at once. Potatoes and carrots need 25-30 minutes; peas need only 3-5 minutes. This ensures each component reaches optimal tenderness.",
            "reason": "Vegetables contain different amounts of cellulose and water, causing them to cook at different rates. Adding all at once means some will be overdone while waiting for others. Staggered addition ensures every element is perfect at serving time."
        },
        {
            "tip": "Make stew a day ahead if possible. The flavor improves as ingredients meld overnight. Cool completely before refrigerating, then reheat gently over low heat.",
            "reason": "Extended time allows flavors to penetrate throughout the stew. Fat and gelatin have time to solidify and redistribute, creating a more cohesive flavor. The beef also absorbs broth flavors over time, becoming more tender in taste."
        }
    ],
    "common_mistakes_to_avoid": [
        {
            "mistake": "Skipping the browning step or browning half-heartedly to save time",
            "why_it_happens": "Browning takes 15-20 minutes, which feels like a long time when the whole dish already requires 2+ hours. People try to speed up the process by crowding the pan or not waiting for proper browning.",
            "fix": "Commit to proper browning. Work in batches if needed. The browning step is not optional—it\'s what transforms a bland stew into one with complex, savory flavor. The 15-20 minutes spent browning pays for itself in flavor development."
        },
        {
            "mistake": "Using a boil instead of a simmer for the braise",
            "why_it_happens": "Higher heat seems like it should cook the stew faster and more thoroughly. Vigorous boiling makes it look like cooking is happening faster.",
            "fix": "Maintain a gentle simmer throughout. If you see large bubbles bursting aggressively at the surface, reduce heat immediately. The stew will taste noticeably better—the beef will be tender and moist rather than tough and dry."
        },
        {
            "mistake": "Adding all vegetables at the beginning of cooking",
            "why_it_happens": "It seems efficient to add everything at once. By the time you realize some vegetables are overcooked, it\'s too late to fix.",
            "fix": "Add beef first and simmer for 1.5 hours until very tender. Then add potatoes and carrots for 25-30 minutes. Finally, add peas for only 3-5 minutes. This timing ensures each component is cooked perfectly."
        },
        {
            "mistake": "Tasting and adding salt at the very end, after significant liquid reduction",
            "why_it_happens": "People often wait to salt until the end, thinking it\'s easier to adjust seasoning then. But by then, the salt doesn\'t penetrate the beef as effectively.",
            "fix": "Add salt early when the beef goes into the broth. This allows salt to penetrate the meat gradually during cooking. Taste at the end and adjust, but most of the salt should be added early."
        },
        {
            "mistake": "Adding too much liquid at the start or ending with a stew that\'s too thin",
            "why_it_happens": "People often worry about the stew drying out and add extra liquid. Or they don\'t account for the 20-30% reduction that occurs during 2 hours of simmering.",
            "fix": "Liquid should come about three-quarters of the way up the beef pieces, not completely submerge it. During cooking, this liquid reduces and concentrates. If the final stew is too thin, thicken it with a cornstarch slurry, but this is uncommon if you start with the right amount."
        }
    ],
    "variations": [
        {
            "name": "Burgundy-Style Beef Stew",
            "description": "Add 1 cup (240ml) of dry red wine (such as Burgundy or Pinot Noir) along with the broth. Reduce the broth amount to 3 cups (720ml) so the total liquid remains the same. The wine adds acidity and subtle fruit notes that complement the beef. This variation requires caramelizing the tomato paste slightly longer (2-3 minutes) to compensate for the wine\'s acidity.",
            "technical_note": "The wine\'s alcohol cooks off during the long braise, leaving behind its flavor compounds. The acidity tenderizes the beef slightly and brightens the overall flavor profile. This is the classic French approach to beef stew."
        },
        {
            "name": "Root Vegetable Stew",
            "description": "Replace potatoes and carrots with other root vegetables: parsnips, celery root, and turnips, cut into the same size pieces. These vegetables have different flavor profiles—parsnips are sweeter, celery root is earthier, turnips are slightly peppery. The cooking time remains the same (25-30 minutes for all root vegetables).",
            "technical_note": "Root vegetables vary in density. Parsnips and carrots are similar and cook in the same time. Celery root and turnips are slightly denser and may need 1-2 extra minutes. Test with a fork to verify doneness."
        },
        {
            "name": "Mushroom and Beef Stew",
            "description": "Add 12 oz (340g) of mushrooms (cremini or baby bella), quartered, along with the potatoes and carrots in step 7. The mushrooms contribute umami and earthy notes that complement the beef. They require the same cooking time (25-30 minutes) as potatoes and carrots.",
            "technical_note": "Mushrooms release moisture as they cook, which contributes additional liquid to the stew. This is beneficial as it maintains broth consistency. If you prefer a thicker final stew, reduce the added mushrooms to 8 oz (225g)."
        },
        {
            "name": "Irish-Style Stew",
            "description": "This variation uses only beef, potatoes, onions, and carrots with no tomato paste. Increase the broth to 5 cups (1200ml) and omit the tomato paste entirely. The stew relies on the beef\'s natural flavor and the vegetables sweetness rather than added acidity. Add 2 additional teaspoons (4g) of thyme to compensate for the missing tomato paste\'s umami.",
            "technical_note": "Without tomato paste, the stew will be lighter in color and more subtle in flavor. The long braise still develops rich flavor from the beef\'s browning and the vegetable\'s sweetness, but the overall intensity is less than the tomato-paste version."
        },
        {
            "name": "Pressure Cooker Beef Stew",
            "description": "Brown the beef and vegetables following the same technique, but use a pressure cooker for the braising step. After browning beef and sautéing aromatics, add the liquid and close the pressure cooker. Cook at high pressure for 25 minutes, then allow natural pressure release for 10 minutes. Add vegetables after pressure release, then bring to pressure again for 3 minutes. This method reduces total cooking time from 2.5 hours to approximately 45 minutes.",
            "technical_note": "Pressure cooking accelerates collagen breakdown significantly through high temperature and steam. However, the high temperature can make meat slightly less tender than a long, gentle braise because muscle fiber contraction occurs more rapidly. The stew still turns out well but with a slightly different texture."
        }
    ],
    "nutrition": {
        "servings": 6,
        "calories_per_serving": "approximately 425",
        "protein_grams": "42g",
        "carbohydrates_grams": "28g",
        "fat_grams": "16g",
        "fiber_grams": "5g",
        "sodium_mg": "850mg",
        "note": "These values are estimates and will vary based on exact beef trim level and broth saltiness. Values assume medium-sized vegetables and standard beef chuck (not ultra-lean). If using thickening slurry, carbohydrates increase slightly."
    },
    "storage_and_reheating": {
        "room_temperature": "Do not leave stew at room temperature for more than 2 hours after cooking. Transfer to shallow containers immediately to speed cooling and prevent bacterial growth.",
        "refrigerator": "Store completely cooled stew in airtight containers for up to 4 days. The fat will solidify on the surface as it cools — this is normal and protects the stew from oxidation. Flavor improves significantly after 1-2 days as ingredients meld and the gelatin sets, creating a thicker, more cohesive consistency.",
        "freezer": "Freeze stew for up to 3 months in freezer-safe containers or heavy-duty freezer bags. Leave about 1 inch (2.5cm) of headspace for expansion. Stew thaws well and maintains reasonable texture upon reheating, though it may be slightly less tender than fresh. Thaw overnight in the refrigerator before reheating — never at room temperature.",
        "reheating": "Reheat gently over low to medium heat in a pot on the stovetop, stirring occasionally. Avoid boiling during reheating as this can break down the gelatin and toughen the beef. Add a splash of broth or water if the stew has thickened too much during storage. Reheating takes approximately 20-30 minutes depending on quantity.",
        "make_ahead_strategy": "Stew can be assembled through step 6 (beef completely cooked and braised) up to 24 hours ahead and refrigerated. Cool completely before refrigerating. The next day, reheat gently until simmering, then add vegetables and proceed as directed. Flavors improve noticeably overnight."
    },
    "faq": [
        {
            "question": "Why is my beef tough and chewy even though I cooked it for 2 hours?",
            "answer": "This indicates the cooking temperature was too high (boiling rather than simmering), or possibly that you used the wrong cut of beef. Beef chuck should become very tender in 1.5-2 hours at a gentle simmer. If you used a leaner cut like sirloin or tenderloin, it will toughen as collagen breaks down because these cuts have minimal collagen to begin with. Check your heat level—the liquid should have small, gentle bubbles breaking the surface, not large, aggressive bubbles."
        },
        {
            "question": "My stew is too thin and brothy. How do I thicken it?",
            "answer": "Mix 1 tablespoon (8g) of cornstarch with 2 tablespoons (30ml) of cold water, stirring until smooth and lump-free. Add this slurry to the simmering stew while stirring constantly. The liquid will thicken noticeably within 1-2 minutes. For a thicker result, you can repeat this process with an additional slurry, but avoid adding too much starch, which creates a gluey texture. Alternatively, if you have time, simmer uncovered for an additional 30 minutes, allowing water to evaporate and the broth to concentrate."
        },
        {
            "question": "Can I use cuts other than beef chuck?",
            "answer": "Beef chuck is ideal because of its collagen content, but beef brisket, short ribs, or beef neck also work well. These cuts are all high in collagen. Leaner cuts like sirloin, tenderloin, or round will not produce the same tender result and may actually become tougher. If substituting, look for cuts described as \'stew beef or braising cuts, which are generally high-collagen selections."
        },
        {
            "question": "How do I know if my stew is done cooking?",
            "answer": "Pierce a piece of beef with a fork. If the fork slides through with no resistance and the meat falls apart slightly when pressed, the beef is done. At the same time, the broth should have reduced by approximately 20-30% and taste rich and developed. If you\'re uncertain, continue simmering for another 15 minutes and test again. Undercooked stew will have chewy, tough beef; overcooked stew (beyond 2 hours typically) will have mushy beef, but this is less common because the collagen breakdown process is gradual."
        },
        {
            "question": "Can I use a slow cooker or Instant Pot instead of stovetop?",
            "answer": "Yes. Brown the beef on the stovetop following steps 1-5, then transfer to a slow cooker on low setting for 6-8 hours. Add vegetables in the last 1-2 hours of cooking. For an Instant Pot, brown the beef using the sauté function, then add remaining ingredients and cook at high pressure for 25 minutes with natural pressure release. Both methods produce acceptable stew, though the long, gentle stovetop simmer produces the most tender beef."
        },
        {
            "question": "Is it okay if my stew doesn\'t have a lot of visible broth?",
            "answer": "That depends on your preference. A stew with less liquid is more of a thick, chunky texture, while a stew with more broth is more soupy. If you prefer thick stew, reduce the initial liquid amount by 1 cup (240ml), or simmer uncovered in the final 30 minutes to allow water to evaporate. If you prefer thinner stew, add additional broth. There\'s no single correct consistency."
        }
    ],
    "conclusion": "Beef stew teaches fundamental braising technique that applies far beyond this single post. The process of browning for flavor development, then braising at low temperature for collagen conversion, is the foundation of many cooking techniques. Once you understand why each step matters—why browning creates flavor, why gentle simmering produces tender meat, why vegetable timing ensures optimal texture—you can apply these principles to other posts with confidence. The specific ingredients change, but the technique remains constant. Mastering beef stew means mastering braising.",
    "internal_links": [
        {
            "anchor": "understanding the Maillard reaction and browning",
            "url": "/posts/maillard-reaction-browning-cooking"
        },
        {
            "anchor": "collagen breakdown and gelatin in cooking",
            "url": "/posts/collagen-gelatin-cooking-science"
        },
        {
            "anchor": "beef cuts guide and selection",
            "url": "/posts/beef-cuts-cooking-guide"
        }
    ],
    "category_id": "cat_1761229889_885",
    "generation_metadata": {
        "voice": "Experienced Home Cook",
        "complexity": "medium",
        "target_words": "1300+",
        "authenticity_focus": "high",
        "e_eat_priority": "expertise_authority_trustworthiness",
        "rewritten_at": "2026-01-14"
    },
    "author_id": "author_001",
    "images": [
        {
            "fileName": "cozy-comforting-beef-stew-for-cold-winter-nights_image_1.webp",
            "filePath": "posts/cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_1.webp",
            "relativePath": "cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_1.webp",
            "originalUrl": "http://localhost/SitePinterset/mollykitchendaily-main/tmpIMG/image_1.webp",
            "order": 1,
            "alt_text": "Beef pieces being browned in hot oil, showing golden-brown crusts developing on multiple sides"
        },
        {
            "fileName": "cozy-comforting-beef-stew-for-cold-winter-nights_image_2.webp",
            "filePath": "posts/cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_2.webp",
            "relativePath": "cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_2.webp",
            "originalUrl": "http://localhost/SitePinterset/mollykitchendaily-main/tmpIMG/image_2.webp",
            "order": 2,
            "alt_text": "Beef stew at gentle simmer stage, showing small bubbles rising from bottom with small surface disturbance"
        },
        {
            "fileName": "cozy-comforting-beef-stew-for-cold-winter-nights_image_3.webp",
            "filePath": "posts/cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_3.webp",
            "relativePath": "cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_3.webp",
            "originalUrl": "http://localhost/SitePinterset/mollykitchendaily-main/tmpIMG/image_3.webp",
            "order": 3,
            "alt_text": "Finished beef stew in white bowl showing tender beef chunks, vegetables, and rich broth, garnished with fresh herbs"
        },
        {
            "fileName": "cozy-comforting-beef-stew-for-cold-winter-nights_LummyPosts_image_4.webp",
            "filePath": "posts/cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_LummyPosts_image_4.webp",
            "relativePath": "cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_LummyPosts_image_4.webp",
            "originalUrl": "http://localhost/SitePinterset/LummyPosts/posts/cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_LummyPosts_image_4.webp",
            "order": 4,
            "type": "TEMPLATE",
            "alt_text": "Overhead view of beef stew served in rustic ceramic bowl with crusty bread on the side"
        }
    ],
    "pin_variations": [
        {
            "title": "Hearty Beef Stew: Rich Flavor Every Time",
            "description": "This classic beef stew recipe delivers deep, savory flavor through proper browning and a long, gentle braise. Tender chunks of beef chuck, carrots, and potatoes in a gelatin-rich broth. #beefstew #comfortfood #braisedbeef #winterrecipes #homemade"
        },
        {
            "title": "Why Your Beef Stew Tastes Flat (And the Fix)",
            "description": "The secret to a rich beef stew isn\'t extra seasoning — it\'s 15 minutes of proper browning. Learn the Maillard reaction trick that transforms every pot of stew. #beefstew #cookingtips #comfortfood #beefrecipes #homecooking"
        },
        {
            "title": "6-Step Beef Stew That Never Disappoints",
            "description": "Master the 6 key steps that guarantee tender beef and a silky, flavor-packed broth every time. No guesswork, just results. #beefstew #easyrecipes #dinnerideas #slowcooking #comfortfood"
        },
        {
            "title": "Cozy Winter Beef Stew the Whole Family Loves",
            "description": "On cold winter nights, nothing beats a pot of this deeply comforting beef stew. Warm, filling, and made with love for family dinners that everyone remembers. #beefstew #familydinner #wintercomfort #cozyrecipes #homemade"
        }
    ],
    "slug": "cozy-comforting-beef-stew-for-cold-winter-nights",
    "id": "post_1765300298_453",
    "image": "cozy-comforting-beef-stew-for-cold-winter-nights_image_1.webp",
    "image_path": "posts/cozy-comforting-beef-stew-for-cold-winter-nights/images/cozy-comforting-beef-stew-for-cold-winter-nights_image_1.webp",
    "image_dir": "cozy-comforting-beef-stew-for-cold-winter-nights/images",
    "generated_from_text": false,
    "has_rich_structure": true,
    "createdAt": "2026-01-04T16:29:33+01:00",
    "updatedAt": "2026-01-14T11:15:00+01:00"
}
'));



// ── Pinterest Pin Hook Generator ──────────────────────────────────────────────
define('HOOK_PROMPT', _prompt('HOOK_PROMPT', '
Generate 2 Pinterest pin hooks for this recipe: "{TITLE}"
Description: {DESCRIPTION}

Rules:
- Hook 1: curiosity gap style (start with "The secret to...", "Why most people get X wrong...", "Stop doing X when making Y", "The one ingredient that changes everything about X")
- Hook 2: informative/value style ("Did you know...", "X reasons why...", number + result, surprising fact about the recipe)
- Max 60 characters each (must fit on pin image, must be readable at thumbnail size)
- Food/recipe niche specifically
- NEVER just rephrase the recipe title
- Make people WANT to save and try this recipe

Return ONLY valid JSON, no explanation: {"hook1": "...", "hook2": "..."}
'));

// ── Template Presets ─────────────────────────────────────────────────────────
define('ACTIVE_TEMPLATE', _cfg('ACTIVE_TEMPLATE', 'classic'));

define('TEMPLATE_PRESETS', [

    // ── Layout 1: SANDWICH ────────────────────────────────────────────────────
    // image1 (top 56%) — BANNER CENTER — image2 (bottom 50%)
    // Bannière au milieu entre 2 photos. Style classique food blog.
    'classic' => [
        'name'                   => 'Classic Red',
        'preview'                => '#93043d',
        'desc'                   => 'Bannière au centre entre 2 photos',
        'layout'                 => 'sandwich',   // for diagram rendering
        'TEMPLATE_CANVAS_HEIGHT' => 2000,
        'TEMPLATE_IMG1_HEIGHT'   => 1120,   // top photo ends at 1120
        'TEMPLATE_BANNER_Y'      => 850,    // banner starts at 850 (overlap)
        'TEMPLATE_BANNER_HEIGHT' => 270,    // banner = 270px center
        'TEMPLATE_IMG2_Y'        => 1000,   // bottom photo starts at 1000
        'TEMPLATE_IMG2_HEIGHT'   => 1000,
        'TEMPLATE_BG_COLOR'      => '#F5E6D3',
        'TEMPLATE_BANNER_COLOR'  => '#93043dff',
        'TEMPLATE_TEXT_COLOR'    => '#ffffffff',
        'TEMPLATE_FONT_SIZE'     => 95,
        'TEMPLATE_FONT_FAMILY'   => '"Akaya Kanadaka", system-ui',
        'TEMPLATE_FONT_URL'      => 'https://fonts.googleapis.com/css2?family=Akaya+Kanadaka&display=swap',
        'TEMPLATE_DECOR_LINES'   => true,
    ],

    // ── Layout 2: HEADER TOP ──────────────────────────────────────────────────
    // image1 remplit TOUT le canvas (100%)
    // BANNER au SOMMET (y=0) → fade subtil en haut, puis bloc solide, puis fondu vers la photo
    // Résultat : header coloré en haut, belle photo en dessous
    'header' => [
        'name'                   => 'Header Top',
        'preview'                => '#1B4332',
        'desc'                   => 'Titre en haut, photo plein écran en bas',
        'layout'                 => 'header',
        'TEMPLATE_CANVAS_HEIGHT' => 2000,
        'TEMPLATE_IMG1_HEIGHT'   => 2000,   // photo fills entire canvas
        'TEMPLATE_BANNER_Y'      => 0,      // banner starts at very top
        'TEMPLATE_BANNER_HEIGHT' => 520,    // ~26% header zone
        'TEMPLATE_IMG2_Y'        => 1990,   // effectively invisible
        'TEMPLATE_IMG2_HEIGHT'   => 10,
        'TEMPLATE_BG_COLOR'      => '#1B4332',
        'TEMPLATE_BANNER_COLOR'  => '#1B4332ff',  // deep forest green
        'TEMPLATE_TEXT_COLOR'    => '#D8F3DCff',  // light mint
        'TEMPLATE_FONT_SIZE'     => 88,
        'TEMPLATE_FONT_FAMILY'   => '"Oswald", sans-serif',
        'TEMPLATE_FONT_URL'      => 'https://fonts.googleapis.com/css2?family=Oswald:wght@600&display=swap',
        'TEMPLATE_DECOR_LINES'   => false,
    ],

    // ── Layout 3: CINEMATIC FOOTER ────────────────────────────────────────────
    // image1 remplit TOUT le canvas (100%)
    // BANNER tout en BAS (y=1700) → mince bande au fond
    // Résultat : photo plein écran, titre discret mais élégant en bas
    'cinematic' => [
        'name'                   => 'Cinematic',
        'preview'                => '#0A0A0A',
        'desc'                   => 'Photo plein écran, titre en bas',
        'layout'                 => 'cinematic',
        'TEMPLATE_CANVAS_HEIGHT' => 2000,
        'TEMPLATE_IMG1_HEIGHT'   => 2000,   // photo fills entire canvas
        'TEMPLATE_BANNER_Y'      => 1660,   // banner at very bottom
        'TEMPLATE_BANNER_HEIGHT' => 320,    // ~16% footer zone
        'TEMPLATE_IMG2_Y'        => 1990,   // effectively invisible
        'TEMPLATE_IMG2_HEIGHT'   => 10,
        'TEMPLATE_BG_COLOR'      => '#0A0A0A',
        'TEMPLATE_BANNER_COLOR'  => '#0A0A0Aff',  // near-black, blends with bg
        'TEMPLATE_TEXT_COLOR'    => '#F8EDEBff',  // warm white/cream
        'TEMPLATE_FONT_SIZE'     => 82,
        'TEMPLATE_FONT_FAMILY'   => '"Cormorant Garant", serif',
        'TEMPLATE_FONT_URL'      => 'https://fonts.googleapis.com/css2?family=Cormorant+Garant:wght@700&display=swap',
        'TEMPLATE_DECOR_LINES'   => true,
    ],

    // ── Layout 4: RECIPE CARD (cercle + ingrédients) ──────────────────────────
    // Photo circulaire centrée en haut + titre + liste ingrédients sur fond clair
    // Style informatif — s'ajoute AUX templates normaux, ne les remplace pas
    'recipe_card' => [
        'name'                   => 'Recipe Card',
        'preview'                => '#FFF8F0',
        'desc'                   => 'Photo circulaire + liste ingrédients',
        'layout'                 => 'recipe_card',
        'enabled'                => true,    // générer ce template (admin + CSV)
        'csv'                    => true,    // inclure dans le CSV auto-daily
        // Fond
        'BG_COLOR'               => '#FFF8F0',
        // Photo circulaire
        'CIRCLE_DIAM'            => 560,
        'CIRCLE_BORDER_COLOR'    => '#8B4513',
        'CIRCLE_BORDER_WIDTH'    => 8,
        // Titre
        'TITLE_FONT_URL'         => 'https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap',
        'TITLE_FONT_SIZE'        => 82,
        'TITLE_COLOR'            => '#2C1810',
        'TITLE_MAX_WIDTH'        => 880,
        // Séparateur
        'SEP_COLOR'              => '#8B4513',
        'SEP_WIDTH'              => 3,
        // Label "INGREDIENTS"
        'LABEL_TEXT'             => 'INGREDIENTS',
        'LABEL_FONT_SIZE'        => 52,
        'LABEL_COLOR'            => '#8B4513',
        // Liste ingrédients
        'BODY_FONT_URL'          => 'https://fonts.googleapis.com/css2?family=Oswald:wght@400&display=swap',
        'ING_FONT_SIZE'          => 36,
        'ING_COLOR'              => '#2C1810',
        'ING_MAX_WIDTH'          => 840,
        'ING_MAX_ITEMS'          => 14,
        // URL branding
        'URL_COLOR'              => '#8B4513',
        'URL_FONT_SIZE'          => 28,
    ],

    // ── Layout 5: OVERLAY LIST (photo + overlay + ingrédients) ───────────────
    // Photo plein format + gradient sombre + titre + liste ingrédients
    // Style visuel fort — s'ajoute AUX templates normaux, ne les remplace pas
    'overlay_list' => [
        'name'                   => 'Overlay List',
        'preview'                => '#120800',
        'desc'                   => 'Photo plein écran + overlay + ingrédients',
        'layout'                 => 'overlay_list',
        'enabled'                => true,    // générer ce template (admin seulement)
        'csv'                    => false,   // NE PAS inclure dans le CSV auto-daily
        // Overlay gradient sombre
        'OVERLAY_START'          => 880,
        'OVERLAY_FADE_ZONE'      => 200,
        'OVERLAY_COLOR'          => '#120800',
        // Titre
        'TITLE_FONT_URL'         => 'https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap',
        'TITLE_FONT_SIZE'        => 90,
        'TITLE_COLOR'            => '#ffffff',
        'TITLE_Y'                => 1070,
        'TITLE_MAX_WIDTH'        => 900,
        // Accent line
        'ACCENT_COLOR'           => '#FFD700',
        'ACCENT_WIDTH'           => 4,
        // Label "INGREDIENTS:"
        'LABEL_TEXT'             => 'INGREDIENTS:',
        'LABEL_FONT_SIZE'        => 48,
        'LABEL_COLOR'            => '#FFD700',
        // Liste ingrédients
        'BODY_FONT_URL'          => 'https://fonts.googleapis.com/css2?family=Oswald:wght@400&display=swap',
        'ING_FONT_SIZE'          => 34,
        'ING_COLOR'              => '#ffffff',
        'ING_MAX_WIDTH'          => 860,
        'ING_MAX_ITEMS'          => 14,
        // URL branding
        'URL_COLOR'              => '#ffffff',
        'URL_FONT_SIZE'          => 28,
        'URL_ALPHA'              => 50,      // transparence (0=opaque, 127=transp GD)
    ],
]);

// ── Helper: pick preset value for the requested template (or fallback to _cfg) ─
// Priority: site-config.json override > preset default > hardcoded default
function _tpl(string $key, $default) {
    global $_siteConfig;
    $tmpl = (isset($_POST['template']) && array_key_exists($_POST['template'], TEMPLATE_PRESETS))
        ? $_POST['template']
        : ACTIVE_TEMPLATE;
    // site-config.json always wins over preset
    if (array_key_exists($key, $_siteConfig)) return $_siteConfig[$key];
    return TEMPLATE_PRESETS[$tmpl][$key] ?? $default;
}
// ─────────────────────────────────────────────────────────────────────────────

define('TEMPLATE_CONFIG_DEFAULT',
    [
        "version" => "1.0",
        "TEMPLATEName" => "Pinterest TEMPLATE",
        "slug"=>(isset($_POST['uniqueSlug'])) ? $_POST['uniqueSlug'] : "test",
        "index"=>(isset($_POST['index'])) ? $_POST['index'] : "4",
        "dimensions" => [
            "width" => 1000,
            "height" => 1500 
            // "height" => 1700
        ],
        "images" => [
            "image1" => [
                "position" => "top",
                "y" => 0,
                "height" => 675,
                // pour 1700 "height" => 750,
                "url" => "".(isset($_POST['image1'])) ? $_POST['image1'] : "image1.png".""
            ],
            "image2" => [
                "position" => "bottom",
                "y" => 825,
                // pour 1700 "y" => 900,
                "height" => 675,
                // pour 1700 "height" => 800,
                "url" => "".(isset($_POST['image2'])) ? $_POST['image2'] : "image1.png" .""
            ],
            "background" => [
                "url" => "",
                "opacity" => 0.3
            ]
        ],
        "banner" => [
            "type" => "color",
            "y" => 650,
            // pour 1700 "y" => 750,
            "height" => 200,
            "color" => "#000000ff",
            "imageUrl" => null,
            "brushStroke" => true
        ],
        "text" => [
            "content" => "".isset($_POST['title']) ? $_POST['title'] : "Banana Bread Post" ."",
            "fontFamily" => "\"Akaya Kanadaka\", system-ui",
            "fontUrl" => "https://fonts.googleapis.com/css2?family=Akaya+Kanadaka&display=swap",
            "color" => "#14c2eeff",
            "maxFontSize" => 75,
            "minFontSize" => 24,
            "maxWidth" => 850,
            "alignment" => "center",
            "bold" => true,
            "verticalAlign" => "middle"
        ],
        "colors" => [
            "backgroundColor" => "#F5E6D3",
            "bannerColor" => "",
            "textColor" => "#ffffffff"
        ]
    ]
);



// 1700

define('TEMPLATE_CONFIG', 
[
    "version" => "1.0",
    "TEMPLATEName" => "Pinterest TEMPLATE",
    "slug"=>(isset($_POST['uniqueSlug'])) ? $_POST['uniqueSlug'] : "test",
    "index"=>(isset($_POST['index'])) ? $_POST['index'] : "4",
    "folder"=>(isset($_POST['folder'])) ? $_POST['folder'] : "posts",
    "dimensions" => [
        "width" => 1000,
        "height" => (int)_tpl('TEMPLATE_CANVAS_HEIGHT', 2000),
    ],
    "images" => [
        "image1" => [
            "position" => "top",
            "y" => 0,
            "height" => (int)_tpl('TEMPLATE_IMG1_HEIGHT', 1120), // covers bannerEndY
            // pour 1700 "height" => 750,
            "url" => "".(isset($_POST['image1'])) ? $_POST['image1'] : "image1.png".""
        ],
        "image2" => [
            "position" => "bottom",
            "y" => (int)_tpl('TEMPLATE_IMG2_Y', 1000),
            "height" => (int)_tpl('TEMPLATE_IMG2_HEIGHT', 1000),
            "url" => "".(isset($_POST['image2'])) ? $_POST['image2'] : "image1.png" .""
        ],
        "background" => [
            "url" => "",
            "opacity" => 0.3
        ]
    ],
    "banner" => [
        "type" => "color",
        "y" => (int)_tpl('TEMPLATE_BANNER_Y', 850),
        "height" => (int)_tpl('TEMPLATE_BANNER_HEIGHT', 270),
        "color" => (isset($_POST['bannerColor'])) ? $_POST['bannerColor'] : _tpl('TEMPLATE_BANNER_COLOR', '#93043dff'),
        "imageUrl" => null,
        "brushStroke" => true
    ],
    "text" => [
        "content" => "".isset($_POST['title']) ? $_POST['title'] : "Banana Bread Post" ."",
        "fontFamily" => _tpl('TEMPLATE_FONT_FAMILY', '"Akaya Kanadaka", system-ui'),
        "fontUrl" => _tpl('TEMPLATE_FONT_URL', 'https://fonts.googleapis.com/css2?family=Akaya+Kanadaka&display=swap'),
        "color" => (isset($_POST['textColor'])) ? $_POST['textColor'] : _tpl('TEMPLATE_TEXT_COLOR', '#ffffffff'),
        "maxFontSize" => 1000,
        "fontWeight" => 900,
        "fontSize" => (int)_tpl('TEMPLATE_FONT_SIZE', 95),
        "minFontSize" => 50,
        "maxWidth" => 850,
        "alignment" => "center",
        "bold" => true,
        "verticalAlign" => "middle"
    ],
    "colors" => [
        "backgroundColor" => _tpl('TEMPLATE_BG_COLOR', '#F5E6D3'),
        "bannerColor" => "",
        "textColor" => "#ffffffff"
    ],
    "shadow" => [
        "enabled"  => true,
        "offsetX"  => 3,
        "offsetY"  => 3,
        "alpha"    => 85        // 0=opaque, 127=transparent (GD scale)
    ],
    "decorLines" => [
        "enabled"   => (bool)_tpl('TEMPLATE_DECOR_LINES', true),
        "margin"    => 28,      // gap between text block and line
        "edge"      => 40,      // distance from canvas edge
        "alpha"     => 50,      // line transparency (GD scale)
        "thickness" => 2
    ],
    "urlBranding" => [
        "enabled"   => true,
        "text"      => HOST_NAME,
        "fontSize"  => 30,
        "bgAlpha"   => 60,      // pill background transparency
        "textAlpha" => 10,      // text transparency (near-opaque)
        "bottomGap" => 30       // px from bottom edge
    ],
    "engagementText" => [
        "enabled"      => (bool)_tpl('TEMPLATE_ENGAGEMENT_ENABLED', true),
        "text"         => _tpl('TEMPLATE_ENGAGEMENT_TEXT', 'Save this post'),
        "style"        => _tpl('TEMPLATE_ENGAGEMENT_STYLE', 'pill'),    // pill | lines | plain
        "fontSize"     => (int)_tpl('TEMPLATE_ENGAGEMENT_FONT_SIZE', 28),
        "color"        => _tpl('TEMPLATE_ENGAGEMENT_COLOR', '#ffffff'),
        "uppercase"    => (bool)_tpl('TEMPLATE_ENGAGEMENT_UPPERCASE', true),
        "letterSpacing"=> (int)_tpl('TEMPLATE_ENGAGEMENT_LETTER_SPACING', 3),
        "gap"          => (int)_tpl('TEMPLATE_ENGAGEMENT_GAP', 42),
        // pill options
        "bgColor"      => _tpl('TEMPLATE_ENGAGEMENT_BG_COLOR', ''),     // vide = banner color
        "bgAlpha"      => (int)_tpl('TEMPLATE_ENGAGEMENT_BG_ALPHA', 60),
        "paddingH"     => (int)_tpl('TEMPLATE_ENGAGEMENT_PAD_H', 30),
        "paddingV"     => (int)_tpl('TEMPLATE_ENGAGEMENT_PAD_V', 10),
        "borderRadius" => (int)_tpl('TEMPLATE_ENGAGEMENT_RADIUS', 50),  // 50+ = pill parfaite
        "border"       => (bool)_tpl('TEMPLATE_ENGAGEMENT_BORDER', false),
        "borderColor"  => _tpl('TEMPLATE_ENGAGEMENT_BORDER_COLOR', '#ffffff'),
        // lines options
        "decorLines"   => (bool)_tpl('TEMPLATE_ENGAGEMENT_LINES', true),
        "lineAlpha"    => (int)_tpl('TEMPLATE_ENGAGEMENT_LINE_ALPHA', 55),
        "lineGap"      => (int)_tpl('TEMPLATE_ENGAGEMENT_LINE_GAP', 16),
        "lineEdge"     => (int)_tpl('TEMPLATE_ENGAGEMENT_LINE_EDGE', 45),
    ]
]
);









  // Détecter le protocole
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
    || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";

// Récupérer le nom du serveur (localhost ou domaine)
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Optionnel : récupérer le dossier racine du projet (si nécessaire)
$projectPath = isset($_SERVER['SCRIPT_NAME']) ? dirname($_SERVER['SCRIPT_NAME']) : '/SitePinterset/pinrecipes';
$projectPath = rtrim($projectPath, '/') . '/';

// En CLI : dériver l'URL depuis __DIR__
$_configuredBaseUrl = _cfg('BASE_URL', '');
if (!empty($_configuredBaseUrl)) {
    // BASE_URL explicitement configurée dans site-config.json — priorité absolue
    define('BASE_URL', rtrim($_configuredBaseUrl, '/') . '/');
} elseif (php_sapi_name() === 'cli' && !isset($_SERVER['HTTP_HOST'])) {
    $dirNorm = str_replace('\\', '/', realpath(__DIR__));
    if (preg_match('/^.+\/htdocs(\/.+)$/', $dirNorm, $_m)) {
        // Windows XAMPP
        define('BASE_URL', 'http://127.0.0.1' . rtrim($_m[1], '/') . '/');
    } elseif (preg_match('/^\/var\/www\/(.+)$/', $dirNorm, $_m)) {
        // Linux VPS — /var/www/sites-moad/demo → http://127.0.0.1/sites-moad/demo/
        define('BASE_URL', 'http://127.0.0.1/' . rtrim($_m[1], '/') . '/');
    } else {
        define('BASE_URL', 'http://127.0.0.1/');
    }
    unset($dirNorm, $_m);
} else {
    define('BASE_URL', $protocol . $host . $projectPath);
}
unset($_configuredBaseUrl);
define('IMG1', _cfg('IMG1', 1));
define('IMG2', _cfg('IMG2', 2));
define('FLIP', _cfg('FLIP', false));
define('CSV_PUBLISH_SPACING_DAYS', (int)_cfg('CSV_PUBLISH_SPACING_DAYS', 7));
define('CSV_GUARD_MIN_ROWS',       (int)_cfg('CSV_GUARD_MIN_ROWS', 5));
// Video pins Pinterest — réutilise le pipeline reel (MP4 9:16). Nécessite FFmpeg.
define('PINTEREST_VIDEO_PINS_ACTIVE', (bool)_cfg('PINTEREST_VIDEO_PINS_ACTIVE', false));
// URL publique du serveur servant les MP4 (PAS git — évite de gonfler le repo / push lent).
// Ex: https://39.113.162.145/sites-moad/pinrecipes  — vide = 'https://' . HOST_NAME
define('PINTEREST_VIDEO_BASE_URL', rtrim((string)_cfg('PINTEREST_VIDEO_BASE_URL', ''), '/'));
// Fresh-pin recycling — repin d'anciens posts online avec un design frais.
define('PINTEREST_RECYCLE_ACTIVE',   (bool)_cfg('PINTEREST_RECYCLE_ACTIVE',   false));
define('PINTEREST_RECYCLE_COUNT',    (int)_cfg('PINTEREST_RECYCLE_COUNT',    10));  // posts recyclés / run
define('PINTEREST_RECYCLE_MIN_DAYS', (int)_cfg('PINTEREST_RECYCLE_MIN_DAYS',  7));  // délai min entre 2 recyclages
define('PIN_SCHEDULE_START', (int)_cfg('PIN_SCHEDULE_START', 16)); // heure début (0-23)
define('PIN_SCHEDULE_END',   (int)_cfg('PIN_SCHEDULE_END',   4));  // heure fin   (0-23, peut dépasser minuit)
define('ZOOM', _cfg('ZOOM', '1'));

// Image generation prompts — use {title} as placeholder for the post name
// If empty string in site-config.json, genimg.php falls back to its built-in random prompts
define('IMG_PROMPT_1', _prompt('IMG_PROMPT_1',
    'Extreme close-up food photography of {title} served on a rustic hand-thrown ceramic bowl with earthy tones, placed on a worn light oak wooden table with natural grain, as if a home cook just placed it fresh from the stove. Shot from a low 30-degree angle, dish filling 85% of the frame. Warm golden afternoon sunlight casting soft natural shadows that reveal rich texture and depth. Crystal clear sharp image — no steam, no fog, no haze. Glistening sauce catching the light, caramelized crust, vibrant colors, visible herbs and spices. Background dissolves into warm creamy bokeh. Hyper-realistic food photography, not AI-looking, the kind of photo that stops a user mid-scroll and makes them hungry immediately.'
));
define('IMG_PROMPT_2', _prompt('IMG_PROMPT_2',
    'Close-up eye-level side-angle food photography of {title} served on a wide shallow white ceramic plate with thick rim, resting on a white marble countertop with subtle grey veining, as if a chef just finished plating it in a real kitchen. Food filling 80% of the frame, shot straight-on to reveal the full depth, layers and cross-section of the dish. Soft diffused morning light from a large side window creating subtle highlights on sauces and edges, enhancing volume and realism. Sharp focus across the entire front face of the food, beautiful bokeh blur behind. Rich saturated natural colors, visible herbs, spices, juices. No props, no clutter. Ultra-realistic, mouthwatering, scroll-stopping Pinterest portrait.'
));
define('KEYWORD_SYSTEM_PROMPT', _prompt('KEYWORD_SYSTEM_PROMPT',
    'You are an SEO expert for a food recipe blog. You MUST return ONLY valid JSON, no markdown, no text before or after.'
));
define('KEYWORD_USER_PROMPT', _prompt('KEYWORD_USER_PROMPT',
'Generate exactly {COUNT} unique, SEO-optimized recipe blog post titles for a food blog.

Context:
- Date: {DATE}
- Season: {SEASON}
- Currently trending topics (use as inspiration for food angles): {TRENDS}
- Available categories: {CATEGORIES}
- Already published slugs (DO NOT repeat similar topics): {EXISTING}

Requirements:
- Each title must be 6-12 words
- Include high-traffic keywords naturally (e.g. "easy", "homemade", "creamy", "30-minute", "best")
- Mix of: quick meals, desserts, dinner ideas, comfort food, healthy options, seasonal recipes
- Specific titles (not generic like "Chicken Recipe" — bad; "Creamy Garlic Butter Chicken Thighs in 30 Minutes" — good)
- Capitalize properly as blog post titles

Return ONLY this JSON:
{
  "titles": [
    "Title One Here",
    "Title Two Here"
  ]
}'
));
define('PINTEREST_CSV_PROMPT', _prompt('PINTEREST_CSV_PROMPT',
'You are a data-driven SEO and Pinterest food trends expert.

## INPUT:
You will receive a dynamic context object:
{
  "month": "{MONTH}",
  "season": "{SEASON}",
  "trending_food_style": "Quick, Easy, Healthy",
  "popular_ingredients": {INGREDIENTS},
  "trending_topics": {TRENDS}
}

## TASK:
1. Based on the context, identify 2 trending recipe keywords.
2. For each keyword, generate multiple recipe ideas (Titles).
3. Each title must be realistic, Pinterest-friendly, and specific.
4. Output the results in the following CSV format:

"Keyword","Title"
"keyword 1","Title 1"
"keyword 2","Title 2"
...

Ensure you generate at least {COUNT} rows with varied titles for the top keywords.

## RULES:
- Do NOT invent unrelated keywords
- Titles must be natural, catchy, and Pinterest-searchable
- Output ONLY CSV in the exact format above, no markdown, no extra text
- Results should change based on input context daily'
));
define('IMG_PROMPT_3', _prompt('IMG_PROMPT_3',
    'Extreme close-up macro food photography of {title} served on a deep matte black slate plate, on a dark walnut wood surface, as if a food blogger captured it moments after cooking. Food filling 80% of frame, captured at a low eye-level angle. Bright overcast natural daylight from above creating gentle highlights and shadows emphasizing depth and realism. Razor-sharp focus on the most delicious-looking section — glistening sauce, visible layers, caramelized edges, fresh herb flecks, moisture droplets. Background blurs into warm creamy bokeh. Ultra-realistic, mouthwatering, fine-dining quality, irresistible close-up that makes the viewer want to reach into the frame.'
));

/**
 * Delete all template images for a post and clean its images array.
 * Returns the cleaned images array (source images only).
 */
function deletePostTemplates(string $slug, array $images, string $baseDir): array {
    // Seules les photos source (type vide/'main'/'src') sont protégées
    // Les types générés (template/recipe_card/overlay_list) sont supprimables
    $sourceTypes = ['', 'main', 'src'];
    $protected = [];
    foreach ($images as $img) {
        if (in_array($img['type'] ?? '', $sourceTypes, true)) {
            $protected[$img['fileName'] ?? ''] = true;
        }
    }

    // 1. Delete tracked generated template files
    $generatedTypes = ['template', 'recipe_card', 'overlay_list'];
    foreach ($images as $img) {
        if (in_array($img['type'] ?? '', $generatedTypes, true)) {
            $abs = $baseDir . '/' . ltrim($img['filePath'] ?? '', '/\\');
            if (file_exists($abs)) unlink($abs);
        }
    }
    // 2. Glob for any untracked template files (SITE_FOLDER between slug and image_N)
    //    Skip files that are tracked as source images (protect food photos)
    $imgsDir = $baseDir . '/posts/' . $slug . '/images/';
    if (is_dir($imgsDir)) {
        foreach (array_merge(
            glob($imgsDir . $slug . '_*_image_*.webp') ?: [],
            glob($imgsDir . $slug . '_*_image_*.jpg')  ?: []
        ) as $f) {
            $fn = basename($f);
            if (!isset($protected[$fn])) {
                unlink($f);
            }
        }
    }
    // 3. Return source images only (exclude all generated template types)
    return array_values(array_filter($images, fn($i) => in_array($i['type'] ?? '', $sourceTypes, true)));
}

/**
 * Rebuild posts/index.json with full post metadata so the front-end
 * needs only 1 HTTP request instead of N (one per post).
 * Called after isOnline changes (pipeline + manual toggle).
 */
function _rebuild_posts_index(string $baseDir): void {
    $postsDir  = $baseDir . '/posts';
    $indexPath = $postsDir . '/index.json';
    $posts     = [];
    $folders   = [];

    foreach (glob($postsDir . '/*/post.json') ?: [] as $jsonPath) {
        $slug = basename(dirname($jsonPath));
        $data = json_decode(file_get_contents($jsonPath), true);
        if (!$data || empty($data['title'])) continue;

        $folders[] = $slug;

        // First source image (non-template)
        $image = '';
        foreach ($data['images'] ?? [] as $img) {
            if (in_array($img['type'] ?? '', ['template', 'recipe_card', 'overlay_list'], true)) continue;
            $fn = $img['fileName'] ?? basename($img['filePath'] ?? '');
            if ($fn) { $image = 'posts/' . $slug . '/images/' . $fn; break; }
        }

        $posts[] = [
            'slug'        => $slug,
            'title'       => $data['title'],
            'description' => mb_substr(trim(strip_tags($data['description'] ?? '')), 0, 220),
            'image'       => $image,
            'category_id' => $data['category_id'] ?? null,
            'createdAt'   => $data['createdAt']  ?? ($data['CreateAt'] ?? null),
            'updatedAt'   => $data['updatedAt']  ?? null,
            'isOnline'    => (bool)($data['isOnline'] ?? false),
            'difficulty'  => $data['difficulty'] ?? null,
            'prep_time'   => isset($data['prep_time'])  ? (int)$data['prep_time']  : null,
            'cook_time'   => isset($data['cook_time'])  ? (int)$data['cook_time']  : null,
            'total_time'  => isset($data['total_time']) ? (int)$data['total_time'] : null,
            'servings'    => $data['servings'] ?? null,
        ];
    }

    // Sort newest first (matches JS sort)
    usort($posts, fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));

    // Index complet (listing pages) — minifié (pas de PRETTY_PRINT → ~40% plus léger)
    file_put_contents($indexPath, json_encode([
        'generated' => date('Y-m-d H:i:s'),
        'count'     => count($folders),
        'folders'   => $folders,
        'posts'     => $posts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Index "home" léger — seulement les 12 derniers posts (chargement instantané du home)
    $homePosts = array_slice($posts, 0, 12);
    file_put_contents($postsDir . '/index-home.json', json_encode([
        'generated' => date('Y-m-d H:i:s'),
        'count'     => count($folders),
        'folders'   => array_column($homePosts, 'slug'),
        'posts'     => $homePosts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
?>