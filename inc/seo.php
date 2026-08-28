<?php
/**
 * Manşet — SEO modülü.
 *
 * Sorumluluk alanı:
 *   • <head> üstverileri (title, description, canonical, robots, prev/next)
 *   • OpenGraph + Twitter kartları
 *   • JSON-LD yapısal verisi (NewsArticle, WebSite, Organization,
 *     CollectionPage, WebPage, BreadcrumbList)
 *   • robots.txt gövdesi
 *   • slug geçmişi (eski adreslerin güncel habere eşlenmesi)
 *
 * Bağlanma noktaları:
 *   inc/view.php → seo_head_tags()  → seo_render_head(view_context())
 *   index.php    → /robots.txt      → seo_robots_txt()
 *   index.php    → /haber/... 404   → seo_lookup_slug()  (orkestratör bağlar)
 *
 * Bu dosya bir giriş noktası değildir; çekirdeği kendisi yükler.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/view.php';

// ============================================================ varsayılanlar

/**
 * Modülün varsayılan üstveri değerleri. Şablonlar ve panel bunları
 * referans olarak okuyabilir.
 *
 * @return array
 */
function seo_meta_defaults() {
    $slogan = trim((string)site('slogan'));
    if ($slogan === '') { $slogan = seo_first_sentence((string)site('desc')); }
    return [
        'site_name'    => (string)site('title'),
        'slogan'       => $slogan,
        'locale'       => 'tr_TR',
        'language'     => 'tr-TR',
        'title'        => $slogan !== '' ? site('title') . ' — ' . $slogan : (string)site('title'),
        'description'  => seo_trim(seo_clean_text((string)site('desc')), 160),
        'canonical'    => base_url() . '/',
        'image'        => seo_default_image(),
        'robots'       => 'index,follow',
        'og_type'      => 'website',
        'twitter_card' => 'summary_large_image',
        'title_limit'  => 65,
        'desc_limit'   => 160,
        'feed_url'     => base_url() . '/rss.php',
        'sitemap_url'  => base_url() . '/sitemap.php',
    ];
}

// ============================================================ metin yardımcıları

/** HTML'i atıp boşlukları tekleştirir. */
function seo_clean_text($text) {
    $t = strip_tags((string)$text);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', (string)$t);
    return trim((string)preg_replace('/\s+/u', ' ', (string)$t));
}

/** Metni kelime sınırında $max karaktere kırpar. */
function seo_trim($text, $max) {
    $text = (string)$text;
    $max = max(10, (int)$max);
    if (mb_strlen($text) <= $max) { return $text; }
    $cut = mb_substr($text, 0, $max);
    $sp = mb_strrpos_compat($cut, ' ');
    if ($sp !== false && $sp > (int)($max * 0.4)) { $cut = mb_substr($cut, 0, $sp); }
    return rtrim($cut, " \t\n\r.,;:—–-") . '…';
}

/** Metnin ilk cümlesi (nokta/ünlem/soru işaretine kadar). */
function seo_first_sentence($text) {
    $t = seo_clean_text($text);
    if ($t === '') { return ''; }
    if (preg_match('/^(.+?[\.\!\?])(\s|$)/u', $t, $m)) { return trim($m[1], " .!?"); }
    return seo_trim($t, 120);
}

/** 'Y-m-d H:i:s' → ISO 8601. Boşsa boş dize. */
function seo_iso_date($dt) {
    if ($dt === null || $dt === '') { return ''; }
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    if (!$ts) { return ''; }
    return date('c', $ts);
}

/** <meta name="…" content="…"> satırı. */
function seo_meta_name($name, $content) {
    $content = (string)$content;
    if ($content === '') { return ''; }
    return '<meta name="' . esc($name) . '" content="' . esc($content) . '">' . "\n";
}

/** <meta property="…" content="…"> satırı (OpenGraph). */
function seo_meta_property($property, $content) {
    $content = (string)$content;
    if ($content === '') { return ''; }
    return '<meta property="' . esc($property) . '" content="' . esc($content) . '">' . "\n";
}

// ============================================================ görünüm bağlamı

/**
 * view_context() çıktısını SEO için normalleştirir.
 *
 * @return array ['kind','post','category','pageRow','term','page','total','perPage','posts']
 */
function seo_view(array $ctx) {
    $tpl  = (string)arr($ctx, 'template', '');
    $vars = (array)arr($ctx, 'vars', []);

    // 'page' değişkeni sabit sayfa şablonunda DİZİ, listelerde sayfa numarasıdır.
    $pageVar = arr($vars, 'page', null);
    $pageNo  = is_numeric($pageVar)
        ? max(1, (int)$pageVar)
        : max(1, (int)(isset($_GET['sayfa']) ? $_GET['sayfa'] : 1));

    $v = [
        'kind'     => 'other',
        'post'     => null,
        'category' => null,
        'pageRow'  => null,
        'term'     => '',
        'page'     => $pageNo,
        'total'    => (int)arr($vars, 'total', 0),
        'perPage'  => max(1, (int)arr($vars, 'perPage', (int)setting('posts_per_page', '12'))),
        'posts'    => is_array(arr($vars, 'posts')) ? $vars['posts'] : [],
    ];

    switch ($tpl) {
        case 'single':
            $v['kind'] = 'post';
            $v['post'] = is_array(arr($vars, 'post')) ? $vars['post'] : null;
            break;
        case 'category':
            $v['kind'] = 'category';
            $v['category'] = is_array(arr($vars, 'category')) ? $vars['category'] : null;
            break;
        case 'search':
            $v['kind'] = !empty($vars['isTag']) ? 'tag' : 'search';
            $v['term'] = seo_clean_text(arr($vars, 'term', ''));
            break;
        case 'page':
            $v['kind'] = 'page';
            $v['pageRow'] = is_array($pageVar) ? $pageVar : null;
            break;
        case 'notfound':
            $v['kind'] = 'notfound';
            break;
        case 'home':
        case '':
            $v['kind'] = 'home';
            break;
    }
    return $v;
}

/** Listelerin (kategori/etiket/arama) N. sayfasının adresi. */
function seo_list_url(array $v, $page) {
    $q = ((int)$page > 1) ? ['sayfa' => (int)$page] : [];
    if ($v['kind'] === 'category' && $v['category']) {
        return url('kategori/' . (string)arr($v['category'], 'slug', ''), $q);
    }
    if ($v['kind'] === 'tag') {
        return url('etiket/' . slugify($v['term']), $q);
    }
    if ($v['kind'] === 'search') {
        return url('arama', array_merge(['q' => $v['term']], $q));
    }
    return url('', $q);
}

/** Listenin toplam sayfa sayısı (sayfalama yoksa 1). */
function seo_page_count(array $v) {
    if ($v['total'] <= 0 || $v['perPage'] <= 0) { return 1; }
    return max(1, (int)ceil($v['total'] / $v['perPage']));
}

// ============================================================ tekil üstveriler

/** Sayfa başlığı (<title> ve og:title tabanı). */
function seo_title(array $ctx) {
    $v = seo_view($ctx);
    $site = (string)site('title');
    $d = seo_meta_defaults();

    switch ($v['kind']) {
        case 'post':
            $custom = seo_clean_text(arr($v['post'], 'seo_title', ''));
            $title = $custom !== ''
                ? $custom
                : seo_clean_text(arr($v['post'], 'title', '')) . ' — ' . $site;
            break;
        case 'category':
            $name = seo_clean_text(arr($v['category'], 'name', ''));
            $title = ($name !== '' ? $name . ' Haberleri' : 'Kategori') . ' — ' . $site;
            break;
        case 'tag':
            $title = ($v['term'] !== '' ? $v['term'] . ' Etiketli Haberler' : 'Etiket') . ' — ' . $site;
            break;
        case 'search':
            $title = $v['term'] !== ''
                ? '"' . $v['term'] . '" için arama sonuçları — ' . $site
                : 'Arama — ' . $site;
            break;
        case 'page':
            $name = seo_clean_text(arr($v['pageRow'], 'title', ''));
            $title = ($name !== '' ? $name : 'Sayfa') . ' — ' . $site;
            break;
        case 'notfound':
            $title = 'Sayfa bulunamadı — ' . $site;
            break;
        default:
            $title = $d['title'];
    }
    return seo_trim($title, (int)$d['title_limit']);
}

/** Paylaşım başlığı: haberde site adı eklenmeden yalın manşet. */
function seo_share_title(array $ctx) {
    $v = seo_view($ctx);
    if ($v['kind'] === 'post' && $v['post']) {
        $custom = seo_clean_text(arr($v['post'], 'seo_title', ''));
        $plain = $custom !== '' ? $custom : seo_clean_text(arr($v['post'], 'title', ''));
        return seo_trim($plain, 90);
    }
    return seo_title($ctx);
}

/** Meta açıklaması. Haberde: seo_desc → spot → gövde özeti. */
function seo_description(array $ctx) {
    $v = seo_view($ctx);
    $site = (string)site('title');
    $d = seo_meta_defaults();
    $text = '';

    switch ($v['kind']) {
        case 'post':
            $text = seo_clean_text(arr($v['post'], 'seo_desc', ''));
            if ($text === '') { $text = seo_clean_text(arr($v['post'], 'spot', '')); }
            // Kilitli haberde gövdeden ASLA özet çıkarılmaz: meta description,
            // og:/twitter: ve JSON-LD hepsi anonim çıktıdır ve önbelleğe yazılır
            // (denetim tur 2, B03). Yayıncının kendi yazdığı teaser kullanılır.
            if ($text === '') {
                $kilitli = function_exists('post_is_locked') && post_is_locked($v['post'], null);
                $text = $kilitli
                    ? seo_clean_text(arr($v['post'], 'teaser', ''))
                    : excerpt((string)arr($v['post'], 'body', ''), 155);
            }
            break;
        case 'category':
            $name = seo_clean_text(arr($v['category'], 'name', ''));
            $text = ($name !== '' ? $name : 'Bu') . ' kategorisindeki son dakika haberleri, '
                  . 'güncel gelişmeler ve analizler ' . $site . ' sayfasında.';
            break;
        case 'tag':
            $text = ($v['term'] !== '' ? $v['term'] : 'Bu etiket') . ' etiketiyle ilişkili tüm haberler ve '
                  . 'güncel gelişmeler ' . $site . ' arşivinde.';
            break;
        case 'search':
            $text = $v['term'] !== ''
                ? '"' . $v['term'] . '" araması için ' . $site . ' haber arşivinde bulunan sonuçlar.'
                : $site . ' haber arşivinde arama yapın.';
            break;
        case 'page':
            $text = seo_clean_text(arr($v['pageRow'], 'title', ''));
            $body = excerpt((string)arr($v['pageRow'], 'body', ''), 155);
            if ($body !== '') { $text = $body; }
            break;
        case 'notfound':
            $text = 'Aradığınız sayfa bulunamadı. ' . $site . ' anasayfasından güncel haberlere ulaşabilirsiniz.';
            break;
        default:
            $text = seo_clean_text((string)site('desc'));
            if ($text === '') { $text = trim($site . ' — ' . $d['slogan'], ' —'); }
    }
    return seo_trim(seo_clean_text($text), (int)$d['desc_limit']);
}

/** Kanonik adres. Sayfalı listelerde ?sayfa=N korunur. */
function seo_canonical(array $ctx) {
    $v = seo_view($ctx);
    switch ($v['kind']) {
        case 'post':
            return $v['post'] ? url_post($v['post']) : base_url() . '/';
        case 'category':
        case 'tag':
        case 'search':
            return seo_list_url($v, $v['page']);
        case 'page':
            $row = $v['pageRow'];
            if (!$row) { return base_url() . '/'; }
            $slug = (string)arr($row, 'slug', '');
            if ($slug === 'kunye' && (int)arr($row, 'id', 0) === 0) { return url('kunye'); }
            return url('sayfa/' . $slug);
        case 'notfound':
            return ''; // 404 sayfasına kanonik verilmez
        default:
            return url('', $v['page'] > 1 ? ['sayfa' => $v['page']] : []);
    }
}

/** robots meta değeri. */
function seo_robots(array $ctx) {
    $v = seo_view($ctx);
    if ($v['kind'] === 'search' || $v['kind'] === 'notfound') { return 'noindex,follow'; }
    if ($v['page'] > 1) { return 'noindex,follow'; }
    return 'index,follow';
}

/** Paylaşım görselinin mutlak adresi (yoksa boş dize). */
function seo_og_image(array $ctx) {
    $v = seo_view($ctx);
    if ($v['kind'] === 'post' && $v['post']) {
        $img = post_image($v['post'], 'large');
        if ($img !== '') { return $img; }
    }
    return seo_default_image();
}

/** Habere özel görsel yoksa kullanılacak varsayılan (ayar → site logosu). */
function seo_default_image() {
    $custom = trim((string)setting('seo_default_image', ''));
    if ($custom !== '') { return url_upload($custom); }
    return (string)site('logo');
}

/**
 * Yerel bir görselin boyutlarını okur (yalnız uploads/ altındaki dosyalar).
 * @return array ['width'=>int,'height'=>int,'mime'=>string] ya da []
 */
function seo_image_dimensions($url) {
    static $cache = [];
    $url = (string)$url;
    if ($url === '') { return []; }
    if (isset($cache[$url])) { return $cache[$url]; }

    $out = [];
    $prefix = base_url() . '/uploads/';
    if (strpos($url, $prefix) === 0) {
        $rel = str_replace('\\', '/', rawurldecode(substr($url, strlen($prefix))));
        if ($rel !== '' && strpos($rel, '..') === false) {
            $abs = UPLOAD_DIR . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                $info = @getimagesize($abs);
                if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
                    $out = [
                        'width'  => (int)$info[0],
                        'height' => (int)$info[1],
                        'mime'   => (string)arr($info, 'mime', ''),
                    ];
                }
            }
        }
    }
    return $cache[$url] = $out;
}

// ============================================================ JSON-LD

/** JSON-LD kaçış bayrakları (script bloğu içinde XSS'e kapalı çıktı). */
function seo_json_flags() {
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
         | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
}

/** Dizi → <script type="application/ld+json"> bloğu. */
function seo_jsonld_script(array $data) {
    $json = json_encode($data, seo_json_flags());
    if ($json === false || $json === '') { return ''; }
    return '<script type="application/ld+json">' . $json . '</script>' . "\n";
}

/** Yayıncı (Organization). Logo yoksa logo alanı hiç basılmaz. */
function seo_publisher_node() {
    $node = [
        '@type' => 'Organization',
        'name'  => (string)site('title'),
        'url'   => base_url() . '/',
    ];
    $logo = (string)site('logo');
    if ($logo !== '') {
        $img = ['@type' => 'ImageObject', 'url' => $logo];
        $dim = seo_image_dimensions($logo);
        if ($dim) { $img['width'] = $dim['width']; $img['height'] = $dim['height']; }
        $node['logo'] = $img;
    }
    return $node;
}

/** WebSite düğümü (kısa gönderme biçimi). */
function seo_website_ref() {
    return ['@type' => 'WebSite', 'name' => (string)site('title'), 'url' => base_url() . '/'];
}

/** [['name'=>…, 'url'=>…], …] → BreadcrumbList. */
function seo_breadcrumb_node(array $items) {
    $list = [];
    $i = 0;
    foreach ($items as $it) {
        $name = seo_clean_text(arr($it, 'name', ''));
        if ($name === '') { continue; }
        $i++;
        $node = ['@type' => 'ListItem', 'position' => $i, 'name' => $name];
        $url = (string)arr($it, 'url', '');
        if ($url !== '') { $node['item'] = $url; }
        $list[] = $node;
    }
    if (!$list) { return []; }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list];
}

/** Haber sayfası için NewsArticle düğümü. */
function seo_newsarticle_node(array $ctx, array $v) {
    $p = $v['post'];
    if (!$p) { return []; }
    $url = url_post($p);

    $images = [];
    $large = post_image($p, 'large');
    $orig  = post_image($p, 'orig');
    if ($large !== '') { $images[] = $large; }
    if ($orig !== '' && $orig !== $large) { $images[] = $orig; }
    if (!$images) {
        $fallback = seo_default_image();
        if ($fallback !== '') { $images[] = $fallback; }
    }

    $published = seo_iso_date(arr($p, 'published_at', '') ?: arr($p, 'created_at', ''));
    $modified  = seo_iso_date(arr($p, 'updated_at', '') ?: arr($p, 'published_at', ''));
    $author    = seo_clean_text(arr($p, 'author_name', ''));
    if ($author === '') { $author = (string)site('title'); }

    $node = [
        '@context'         => 'https://schema.org',
        '@type'            => 'NewsArticle',
        'headline'         => seo_trim(seo_clean_text(arr($p, 'title', '')), 110),
        'description'      => seo_description($ctx),
        'url'              => $url,
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
        'inLanguage'       => 'tr-TR',
        'author'           => ['@type' => 'Person', 'name' => $author],
        'publisher'        => seo_publisher_node(),
    ];
    if ($images)          { $node['image'] = $images; }
    if ($published !== '') { $node['datePublished'] = $published; }
    if ($modified !== '')  { $node['dateModified'] = $modified; }

    $section = seo_clean_text(arr($p, 'category_name', ''));
    if ($section !== '') { $node['articleSection'] = $section; }

    $tags = post_tags($p);
    if ($tags) { $node['keywords'] = array_values($tags); }

    // Kaynağı belirtilen (ör. YZ destekli) haberlerde kaynak kuruluş bilgisi
    $srcName = seo_clean_text(arr($p, 'source_name', ''));
    $srcUrl  = trim((string)arr($p, 'source_url', ''));
    if ($srcName !== '') {
        $src = ['@type' => 'Organization', 'name' => $srcName];
        if ($srcUrl !== '' && preg_match('#^https?://#i', $srcUrl)) { $src['url'] = $srcUrl; }
        $node['sourceOrganization'] = $src;
    }
    return $node;
}

/** Anasayfa: WebSite + SearchAction. */
function seo_website_node(array $ctx) {
    // http_build_query yer tutucuyu bozmasın diye önce güvenli bir belirteç konur.
    $target = str_replace('MANSETARAMATERIMI', '{search_term_string}', url_search('MANSETARAMATERIMI'));
    return [
        '@context'        => 'https://schema.org',
        '@type'           => 'WebSite',
        'name'            => (string)site('title'),
        'url'             => base_url() . '/',
        'description'     => seo_description($ctx),
        'inLanguage'      => 'tr-TR',
        'publisher'       => seo_publisher_node(),
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => ['@type' => 'EntryPoint', 'urlTemplate' => $target],
            'query-input' => 'required name=search_term_string',
        ],
    ];
}

/** Kategori / etiket / arama: CollectionPage. */
function seo_collection_node(array $ctx, array $v) {
    $node = [
        '@context'    => 'https://schema.org',
        '@type'       => 'CollectionPage',
        'name'        => seo_title($ctx),
        'description' => seo_description($ctx),
        'url'         => seo_canonical($ctx),
        'inLanguage'  => 'tr-TR',
        'isPartOf'    => seo_website_ref(),
    ];
    $items = [];
    $i = 0;
    foreach ((array)$v['posts'] as $row) {
        if (!is_array($row) || empty($row['id'])) { continue; }
        $i++;
        $items[] = [
            '@type'    => 'ListItem',
            'position' => $i,
            'url'      => url_post($row),
            'name'     => seo_trim(seo_clean_text(arr($row, 'title', '')), 110),
        ];
        if ($i >= 20) { break; }
    }
    if ($items) {
        $node['mainEntity'] = [
            '@type'           => 'ItemList',
            'numberOfItems'   => $v['total'] > 0 ? (int)$v['total'] : count($items),
            'itemListElement' => $items,
        ];
    }
    return $node;
}

/** Sabit sayfa: WebPage. */
function seo_webpage_node(array $ctx) {
    return [
        '@context'    => 'https://schema.org',
        '@type'       => 'WebPage',
        'name'        => seo_title($ctx),
        'description' => seo_description($ctx),
        'url'         => seo_canonical($ctx),
        'inLanguage'  => 'tr-TR',
        'isPartOf'    => seo_website_ref(),
        'publisher'   => seo_publisher_node(),
    ];
}

/**
 * Sayfa türüne göre tüm JSON-LD bloklarını üretir.
 * @return string bir ya da birkaç <script type="application/ld+json"> bloğu
 */
function seo_jsonld(array $ctx) {
    $v = seo_view($ctx);
    $home = ['name' => 'Anasayfa', 'url' => base_url() . '/'];
    $nodes = [];

    switch ($v['kind']) {
        case 'post':
            $p = $v['post'];
            $nodes[] = seo_newsarticle_node($ctx, $v);
            $crumbs = [$home];
            $catName = seo_clean_text(arr($p, 'category_name', ''));
            $catSlug = (string)arr($p, 'category_slug', '');
            if ($catName !== '' && $catSlug !== '') {
                $crumbs[] = ['name' => $catName, 'url' => url_category($catSlug)];
            }
            $crumbs[] = ['name' => seo_clean_text(arr($p, 'title', '')), 'url' => url_post($p)];
            $nodes[] = seo_breadcrumb_node($crumbs);
            break;

        case 'category':
        case 'tag':
        case 'search':
            $nodes[] = seo_collection_node($ctx, $v);
            $label = $v['kind'] === 'category'
                ? seo_clean_text(arr($v['category'], 'name', ''))
                : ($v['kind'] === 'tag' ? 'Etiket: ' . $v['term'] : 'Arama: ' . $v['term']);
            $nodes[] = seo_breadcrumb_node([$home, ['name' => $label, 'url' => seo_canonical($ctx)]]);
            break;

        case 'page':
            $nodes[] = seo_webpage_node($ctx);
            $nodes[] = seo_breadcrumb_node([
                $home,
                ['name' => seo_clean_text(arr($v['pageRow'], 'title', '')), 'url' => seo_canonical($ctx)],
            ]);
            break;

        case 'notfound':
            break; // 404 sayfasında yapısal veri basılmaz

        default:
            $nodes[] = seo_website_node($ctx);
            $nodes[] = ['@context' => 'https://schema.org'] + seo_publisher_node();
    }

    $out = '';
    foreach ($nodes as $n) {
        if (is_array($n) && $n) { $out .= seo_jsonld_script($n); }
    }
    return $out;
}

// ============================================================ <head> çıktısı

/**
 * inc/view.php → seo_head_tags() bu fonksiyonu çağırır.
 * $ctx = view_context() → ['template'=>…, 'vars'=>…]
 */
function seo_render_head(array $ctx = []) {
    $v     = seo_view($ctx);
    $d     = seo_meta_defaults();
    $title = seo_title($ctx);
    $desc  = seo_description($ctx);
    $canon = seo_canonical($ctx);
    $image = seo_og_image($ctx);
    $share = seo_share_title($ctx);

    $out  = '<title>' . esc($title) . '</title>' . "\n";
    $out .= seo_meta_name('description', $desc);
    $out .= seo_meta_name('robots', seo_robots($ctx));
    if ($canon !== '') { $out .= '<link rel="canonical" href="' . esc($canon) . '">' . "\n"; }

    // --- sayfalı listelerde prev/next
    if (in_array($v['kind'], ['category', 'tag', 'search'], true)) {
        $pages = seo_page_count($v);
        if ($pages > 1) {
            if ($v['page'] > 1) {
                $out .= '<link rel="prev" href="' . esc(seo_list_url($v, $v['page'] - 1)) . '">' . "\n";
            }
            if ($v['page'] < $pages) {
                $out .= '<link rel="next" href="' . esc(seo_list_url($v, $v['page'] + 1)) . '">' . "\n";
            }
        }
    }

    // --- OpenGraph
    $out .= seo_meta_property('og:type', $v['kind'] === 'post' ? 'article' : 'website');
    $out .= seo_meta_property('og:title', $share);
    $out .= seo_meta_property('og:description', $desc);
    $out .= seo_meta_property('og:url', $canon !== '' ? $canon : base_url() . '/');
    $out .= seo_meta_property('og:site_name', $d['site_name']);
    $out .= seo_meta_property('og:locale', $d['locale']);
    if ($image !== '') {
        $out .= seo_meta_property('og:image', $image);
        $dim = seo_image_dimensions($image);
        if ($dim) {
            $out .= seo_meta_property('og:image:width', (string)$dim['width']);
            $out .= seo_meta_property('og:image:height', (string)$dim['height']);
            if ($dim['mime'] !== '') { $out .= seo_meta_property('og:image:type', $dim['mime']); }
        }
        $out .= seo_meta_property('og:image:alt', $share);
    }
    if ($v['kind'] === 'post' && $v['post']) {
        $p = $v['post'];
        $pub = seo_iso_date(arr($p, 'published_at', '') ?: arr($p, 'created_at', ''));
        $mod = seo_iso_date(arr($p, 'updated_at', '') ?: arr($p, 'published_at', ''));
        $out .= seo_meta_property('article:published_time', $pub);
        $out .= seo_meta_property('article:modified_time', $mod);
        $out .= seo_meta_property('article:section', seo_clean_text(arr($p, 'category_name', '')));
        foreach (post_tags($p) as $tag) { $out .= seo_meta_property('article:tag', $tag); }
        $author = seo_clean_text(arr($p, 'author_name', ''));
        if ($author !== '') { $out .= seo_meta_property('article:author', $author); }
    }

    // --- Twitter kartı
    $out .= seo_meta_name('twitter:card', $image !== '' ? 'summary_large_image' : 'summary');
    $out .= seo_meta_name('twitter:title', $share);
    $out .= seo_meta_name('twitter:description', $desc);
    if ($image !== '') { $out .= seo_meta_name('twitter:image', $image); }

    // --- besleme bağlantıları
    $out .= '<link rel="alternate" type="application/rss+xml" title="'
          . esc($d['site_name'] . ' — Tüm Haberler') . '" href="' . esc($d['feed_url']) . '">' . "\n";
    if ($v['kind'] === 'category' && $v['category']) {
        $catSlug = (string)arr($v['category'], 'slug', '');
        if ($catSlug !== '') {
            $out .= '<link rel="alternate" type="application/rss+xml" title="'
                  . esc($d['site_name'] . ' — ' . seo_clean_text(arr($v['category'], 'name', '')))
                  . '" href="' . esc(base_url() . '/rss.php?kategori=' . rawurlencode($catSlug)) . '">' . "\n";
        }
    }

    // --- yapısal veri
    $out .= seo_jsonld($ctx);
    return $out;
}

// ============================================================ robots.txt

/** index.php /robots.txt yolunda basılan gövde. */
function seo_robots_txt() {
    $lines = [
        'User-agent: *',
        'Disallow: /admin/',
        'Disallow: /install/',
        'Disallow: /api.php',
        'Disallow: /cron.php',
        'Disallow: /arama',
        'Allow: /',
        '',
        'Sitemap: ' . base_url() . '/sitemap.php',
    ];
    $out = implode("\n", $lines) . "\n";

    $extra = (string)setting('seo_robots_extra', '');
    $extra = str_replace(["\r\n", "\r"], "\n", $extra);
    $extra = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $extra);
    $extra = trim((string)$extra);
    if ($extra !== '') { $out .= "\n" . $extra . "\n"; }

    return $out;
}

// ============================================================ slug geçmişi

/** slug_history tablosu kurulu mu? (002_slug_history.php uygulanmadan sessiz kalır) */
function seo_slug_history_ready() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        qv('SELECT 1 FROM slug_history LIMIT 1');
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/**
 * Haberin slug'ı değiştiğinde ESKİ slug'ı geçmişe yazar.
 * Aynı slug başka habere aitse kayıt güncellenir (son sahip kazanır).
 */
function seo_remember_slug(int $postId, string $oldSlug) {
    $postId = (int)$postId;
    $slug = trim($oldSlug);
    if ($postId <= 0 || $slug === '') { return; }
    if (mb_strlen($slug) > 200) { $slug = mb_substr($slug, 0, 200); }
    if (!seo_slug_history_ready()) { return; }

    try {
        $row = q1('SELECT id, post_id FROM slug_history WHERE slug = :s', [':s' => $slug]);
        if ($row) {
            if ((int)$row['post_id'] !== $postId) {
                q('UPDATE slug_history SET post_id = :p, created_at = :c WHERE id = :i',
                    [':p' => $postId, ':c' => now(), ':i' => (int)$row['id']]);
            }
            return;
        }
        q('INSERT INTO slug_history (post_id, slug, created_at) VALUES (:p, :s, :c)',
            [':p' => $postId, ':s' => $slug, ':c' => now()]);
    } catch (Throwable $e) {
        log_error('seo_remember_slug: ' . $e->getMessage());
    }
}

/**
 * Verilen slug geçmişte hangi habere aitti?
 * @return array|null ['post_id'=>int] ya da null
 */
function seo_lookup_slug(string $slug) {
    $slug = trim($slug);
    if ($slug === '' || !seo_slug_history_ready()) { return null; }
    try {
        $row = q1('SELECT post_id FROM slug_history WHERE slug = :s', [':s' => $slug]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$row || (int)$row['post_id'] <= 0) { return null; }
    return ['post_id' => (int)$row['post_id']];
}

/**
 * Eski bir slug ile gelen isteği güncel habere 301'ler.
 * Eşleşme yoksa hiçbir şey yapmaz (çağıran 404 üretmeye devam eder).
 *
 * index.php içinden şöyle çağrılır:
 *   if (function_exists('seo_redirect_old_slug')) { seo_redirect_old_slug($slug); }
 */
function seo_redirect_old_slug(string $slug) {
    $hit = seo_lookup_slug($slug);
    if (!$hit) { return; }
    $post = post_by_id($hit['post_id']);
    if (!$post) { return; }                       // yayında değilse 404/410 akışı sürsün
    if ((string)$post['slug'] === trim($slug)) { return; } // zaten kanonik
    if (!headers_sent()) {
        header('Location: ' . url_post($post), true, 301);
    }
    exit;
}

/**
 * Slug değişimini yakalamak için posts tablosunu izleyen hafif kanca.
 * inc/api/admin.php içinden çağrılamadığından (Ajan-2'nin dosyası), güncel slug ile
 * geçmiş arasındaki farkı cron/sitemap üretimi sırasında da yakalayabilmek için
 * seo_sync_slug_history() var.
 *
 * Yalnız MEVCUT slug'ı geçmişe ekler; kaybolmuş eski slug'lar uydurulamaz.
 * @return int eklenen satır sayısı
 */
function seo_sync_slug_history(int $limit = 500) {
    $limit = max(1, min(5000, (int)$limit));
    if (!seo_slug_history_ready()) { return 0; }

    $added = 0;
    try {
        $rows = qa('SELECT p.id, p.slug FROM posts p
                    LEFT JOIN slug_history h ON h.slug = p.slug
                    WHERE p.slug <> \'\' AND h.slug IS NULL
                    ORDER BY p.id ASC LIMIT ' . $limit);
        foreach ($rows as $r) {
            try {
                q('INSERT INTO slug_history (post_id, slug, created_at) VALUES (:p, :s, :c)',
                    [':p' => (int)$r['id'], ':s' => (string)$r['slug'], ':c' => now()]);
                $added++;
            } catch (Throwable $e) {
                // Eşzamanlı istekte benzersizlik çakışması olabilir; o satırı atla.
            }
        }
    } catch (Throwable $e) {
        log_error('seo_sync_slug_history: ' . $e->getMessage());
    }
    return $added;
}
