<?php
/**
 * Manşet — tema motoru ve şablon veri katmanı.
 *
 * SÖZLEŞME: Tema şablonları veritabanına ASLA doğrudan dokunmaz.
 * Şablonlar yalnızca bu dosyanın sunduğu fonksiyonları çağırır.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';

// ============================================================ tema keşfi

/** Etkin tema adı (klasör adı). */
function theme_name() {
    static $t = null;
    if ($t !== null) { return $t; }
    $name = setting('theme', 'gazete');
    if (!theme_is_valid($name)) { $name = 'gazete'; }
    return $t = $name;
}

/** Tema adı güvenli ve mevcut mu? */
function theme_is_valid($name) {
    if (!preg_match('/^[a-z0-9_\-]{1,40}$/', (string)$name)) { return false; }
    return is_file(THEMES_DIR . '/' . $name . '/layout.php');
}

/** Etkin (veya verilen) temanın disk yolu. */
function theme_dir($name = null) { return THEMES_DIR . '/' . ($name ?: theme_name()); }

/** Tema klasöründeki dosyanın genel URL'i. */
function theme_url($file = '', $name = null) {
    return base_url() . '/themes/' . ($name ?: theme_name()) . ($file !== '' ? '/' . ltrim($file, '/') : '');
}

/** theme.json içeriği (dizi). Bozuksa boş dizi. */
function theme_json($name = null) {
    static $cache = [];
    $name = $name ?: theme_name();
    if (isset($cache[$name])) { return $cache[$name]; }
    $file = THEMES_DIR . '/' . $name . '/theme.json';
    $data = [];
    if (is_file($file)) {
        $raw = file_get_contents($file);
        $d = json_decode((string)$raw, true);
        if (is_array($d)) { $data = $d; }
    }
    return $cache[$name] = $data;
}

/** Kurulu temaların listesi: [ad => theme.json]. */
function theme_list() {
    $out = [];
    foreach ((array)glob(THEMES_DIR . '/*', GLOB_ONLYDIR) as $dir) {
        $name = basename($dir);
        if (!theme_is_valid($name)) { continue; }
        $meta = theme_json($name);
        $out[$name] = $meta + [
            'name'    => isset($meta['name']) ? $meta['name'] : $name,
            'preview' => isset($meta['preview']) ? $meta['preview'] : '#c0392b',
        ];
    }
    ksort($out);
    return $out;
}

/** theme.json > settings şemasındaki alan tanımlarını düz liste olarak verir. */
function theme_setting_schema($name = null) {
    $meta = theme_json($name);
    return isset($meta['settings']) && is_array($meta['settings']) ? $meta['settings'] : [];
}

/**
 * Tema ayarı okur. Kaynak sırası:
 *   settings['theme:<tema>:<anahtar>']  →  theme.json varsayılanı  →  $default
 */
function theme_setting($key, $default = '', $name = null) {
    $name = $name ?: theme_name();
    $all = settings_all();
    $k = 'theme:' . $name . ':' . $key;
    if (array_key_exists($k, $all) && $all[$k] !== '') { return $all[$k]; }
    foreach (theme_setting_schema($name) as $field) {
        if (isset($field['key']) && $field['key'] === $key && array_key_exists('default', $field)) {
            return $field['default'];
        }
    }
    return $default;
}

/** Tema ayarı yazar. */
function theme_setting_set($key, $value, $name = null) {
    setting_set('theme:' . ($name ?: theme_name()) . ':' . $key, $value);
}

/** Temanın tüm ayarlarını [anahtar => değer] olarak döndürür (varsayılanlar dâhil). */
function theme_settings_all($name = null) {
    $name = $name ?: theme_name();
    $out = [];
    foreach (theme_setting_schema($name) as $field) {
        if (!isset($field['key'])) { continue; }
        $out[$field['key']] = theme_setting($field['key'], isset($field['default']) ? $field['default'] : '', $name);
    }
    return $out;
}

/**
 * Tema ayarlarından :root CSS değişkenleri üretir.
 * theme.json'daki `type: "color" | "size" | "font" | "text"` alanları
 * `css_var` anahtarı taşıyorsa çıktıya girer.
 */
function theme_css_vars($name = null) {
    $name = $name ?: theme_name();
    $vars = [];
    foreach (theme_setting_schema($name) as $field) {
        if (empty($field['css_var']) || empty($field['key'])) { continue; }
        $val = theme_setting($field['key'], isset($field['default']) ? $field['default'] : '', $name);
        if ($val === '' || $val === null) { continue; }
        $type = isset($field['type']) ? $field['type'] : 'text';
        if ($type === 'color') {
            if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$val)) { continue; }
        } elseif ($type === 'size') {
            $val = preg_replace('/[^0-9a-z%\.\-]/i', '', (string)$val);
            if ($val === '') { continue; }
            if (is_numeric($val)) { $val .= isset($field['unit']) ? $field['unit'] : 'px'; }
        } else {
            // Serbest metin (font yığını): CSS'ten kaçış için tehlikeli karakterleri at
            $val = str_replace(['<', '>', '{', '}', ';', '"'], '', (string)$val);
        }
        $vars[$field['css_var']] = $val;
    }
    if (!$vars) { return ''; }
    $out = ":root{\n";
    foreach ($vars as $k => $v) {
        $k = preg_replace('/[^a-z0-9\-]/i', '', $k);
        $out .= '  --' . ltrim($k, '-') . ': ' . $v . ";\n";
    }
    $out .= "}\n";
    return $out;
}

/** Yöneticinin girdiği özel CSS (yalnız admin yazabilir). */
function theme_custom_css($name = null) {
    $css = (string)theme_setting('custom_css', '', $name);
    if (trim($css) === '') { return ''; }
    // </style> kaçışını engelle
    return str_replace(['</style', '<script'], ['<\\/style', '<\\script'], $css);
}

/** Tema Google Fonts URL'i tanımladıysa <link> etiketi üretir. */
function theme_font_link($name = null) {
    $url = trim((string)theme_setting('google_fonts_url', '', $name));
    if ($url === '') { return ''; }
    if (!preg_match('#^https://fonts\.googleapis\.com/#i', $url)) { return ''; }
    return '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n"
         . '<link rel="stylesheet" href="' . esc($url) . '">';
}

/** Tema karanlık mod anahtarını destekliyor ve açık mı? */
function theme_dark_mode($name = null) {
    $meta = theme_json($name);
    if (empty($meta['supports_dark'])) { return false; }
    return theme_setting('dark_mode', '0', $name) === '1';
}

// ============================================================ oluşturma (render)

/** Geçerli görünüm bağlamı (şablon adı, değişkenler, SEO bilgisi). */
function view_context($set = null) {
    static $ctx = ['template' => '', 'vars' => [], 'seo' => []];
    if (is_array($set)) { $ctx = $set + $ctx; }
    return $ctx;
}

/** Şablon dosyasının yolunu çözer; temada yoksa gazete temasına düşer. */
function view_resolve($template) {
    $template = preg_replace('/[^a-z0-9_\-\/]/i', '', (string)$template);
    $candidates = [
        theme_dir() . '/' . $template . '.php',
        THEMES_DIR . '/gazete/' . $template . '.php',
    ];
    foreach ($candidates as $c) { if (is_file($c)) { return $c; } }
    return null;
}

/**
 * Sayfayı temanın layout.php dosyası içinde oluşturur.
 * @param string $template 'home' | 'category' | 'single' | 'page' | 'search' | 'notfound'
 * @param array  $vars     Şablona açılacak değişkenler
 */
function render($template, $vars = [], $status = 200) {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');

        // ÖNBELLEK BAŞLIKLARI (denetim tur 2, paywall B04).
        // Yanıt oturuma göre DEĞİŞİR: aynı adres anonime kilit kutusu,
        // aboneye tam metin döndürür. Vary olmadan araya giren bir vekil
        // ya da CDN ikisini karıştırabilir. Oturumu olan ziyaretçide yanıt
        // hiç paylaşılmamalı; anonimde kısa süreli paylaşım güvenlidir
        // (uygulamanın kendi dosya önbelleği zaten aynı kuralı uygular).
        header('Vary: Cookie');
        // ÖDEME DUVARI (1.2-05): oturum çerezi yoksa da yanıt kişiye özel
        // OLABİLİR — ölçülü hakla açılmış tam metin taşıyor olabilir. Bu koşul
        // olmadan o yanıt `s-maxage=60` ile CDN'de PAYLAŞILIYORDU, yani bir
        // okurun hakkıyla açtığı metin başkalarına bedava servis ediliyordu
        // (Ajan-D ölçtü).
        $mansetOzel = (function_exists('has_session_cookie') && has_session_cookie())
                   || (function_exists('paywall_response_is_private') && paywall_response_is_private());
        if ($mansetOzel) {
            header('Cache-Control: private, no-store, max-age=0');
        } else {
            header('Cache-Control: public, max-age=0, s-maxage=60');
        }
    }
    view_context(['template' => $template, 'vars' => $vars]);
    $layout = theme_dir() . '/layout.php';
    if (!is_file($layout)) { $layout = THEMES_DIR . '/gazete/layout.php'; }
    extract($vars, EXTR_SKIP);
    include $layout;
}

/** layout.php içinden çağrılır: asıl şablonu basar. */
function view_content() {
    $ctx = view_context();
    $file = view_resolve($ctx['template']);
    if (!$file) {
        echo '<p style="padding:24px">Şablon bulunamadı: ' . esc($ctx['template']) . '</p>';
        return;
    }
    extract($ctx['vars'], EXTR_SKIP);
    include $file;
}

/**
 * Tema parçasını basar: themes/<tema>/parts/<ad>.php
 * Temada yoksa gazete temasındaki eşdeğerine düşer.
 */
function part($name, $vars = []) {
    $name = preg_replace('/[^a-z0-9_\-]/i', '', (string)$name);
    $candidates = [theme_dir() . '/parts/' . $name . '.php', THEMES_DIR . '/gazete/parts/' . $name . '.php'];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            extract($vars, EXTR_SKIP);
            include $file;
            return true;
        }
    }
    return false;
}

/** part() çıktısını dize olarak döndürür. */
function part_capture($name, $vars = []) {
    ob_start();
    part($name, $vars);
    return (string)ob_get_clean();
}

// ============================================================ site verisi

/** Site geneli bilgi. Anahtar verilirse tek değer döner. */
function site($key = null) {
    static $s = null;
    if ($s === null) {
        $s = [
            'title'   => setting('site_title', 'Manşet'),
            'slogan'  => setting('site_slogan', ''),
            'desc'    => setting('site_desc', ''),
            'email'   => setting('site_email', ''),
            'logo'    => setting('site_logo', '') ? url_upload(setting('site_logo')) : '',
            'favicon' => setting('site_favicon', '') ? url_upload(setting('site_favicon')) : '',
            'url'     => base_url(),
            'year'    => date('Y'),
            'theme'   => theme_name(),
        ];
    }
    if ($key === null) { return $s; }
    return isset($s[$key]) ? $s[$key] : '';
}

/** Üst menüdeki kategoriler. */
function menu() {
    static $m = null;
    if ($m !== null) { return $m; }
    return $m = qa('SELECT id, name, slug, color FROM categories WHERE active = 1 AND in_menu = 1 ORDER BY sort ASC, name ASC');
}

/** Tüm aktif kategoriler. */
function all_categories($onlyActive = true) {
    $sql = 'SELECT id, name, slug, color, sort, in_menu, active FROM categories'
         . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY sort ASC, name ASC';
    return qa($sql);
}

/** Slug'a göre kategori. */
function category_by_slug($slug) {
    return q1('SELECT * FROM categories WHERE slug = :s AND active = 1', [':s' => (string)$slug]);
}
/** Id'ye göre kategori. */
function category_by_id($id) {
    static $cache = [];
    $id = (int)$id;
    if ($id <= 0) { return null; }
    if (array_key_exists($id, $cache)) { return $cache[$id]; }
    return $cache[$id] = q1('SELECT * FROM categories WHERE id = :i', [':i' => $id]);
}

/** Yayındaki haberler için ortak WHERE parçası. */
function published_where($alias = 'p') {
    // Çöp kutusundaki haber ön yüzde HİÇBİR yerde görünmez (1.1 yumuşak silme).
    // deleted_at boş dize = silinmemiş. Sütun yoksa (göç uygulanmamışsa)
    // koşul atlanır — eski kurulum bozulmasın.
    $silme = published_supports_trash() ? ' AND ' . $alias . ".deleted_at = ''" : '';
    return $alias . ".status = 'published' AND (" . $alias . '.published_at IS NULL OR '
         . $alias . '.published_at <= :nowts)' . $silme;
}

/** `posts.deleted_at` sütunu var mı? (bir kez sorulur, istek boyunca saklanır) */
function published_supports_trash() {
    static $var = null;
    if ($var !== null) { return $var; }
    if (!function_exists('schema_has_column')) {
        try { qv('SELECT deleted_at FROM posts LIMIT 1'); $var = true; }
        catch (Throwable $e) { $var = false; }
        return $var;
    }
    return $var = schema_has_column('posts', 'deleted_at');
}

/** Haber listesi sorgusu için ortak SELECT. */
function post_select_columns($alias = 'p') {
    return $alias . '.id, ' . $alias . '.title, ' . $alias . '.slug, ' . $alias . '.spot, ' . $alias . '.image, '
         . $alias . '.category_id, ' . $alias . '.author_id, ' . $alias . '.type, ' . $alias . '.tags, '
         . $alias . '.view_count, ' . $alias . '.is_breaking, ' . $alias . '.is_headline, ' . $alias . '.headline_sort, '
         . $alias . '.ai_generated, ' . $alias . '.source_name, ' . $alias . '.published_at, '
         // visibility listelerde kilit rozeti basmak için gerekli: okur
         // başlığa tıklamadan ÖNCE içeriğin abonelere özel olduğunu görmeli.
         . $alias . '.visibility';
}

/** Manşetteki haberler (sıralı). */
function headline_posts($limit = 6) {
    $limit = max(1, min(12, (int)$limit));
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.is_headline = 1 AND ' . published_where() . '
        ORDER BY p.headline_sort ASC, p.published_at DESC LIMIT ' . $limit,
        [':nowts' => now()]);
}

/** Son dakika işaretli haberler. */
function breaking($limit = 5) {
    $limit = max(1, min(20, (int)$limit));
    return qa('SELECT p.id, p.title, p.slug, p.published_at FROM posts p
        WHERE p.is_breaking = 1 AND ' . published_where() . '
        ORDER BY p.published_at DESC LIMIT ' . $limit, [':nowts' => now()]);
}

/** Kategoriye göre haberler. $category id ya da slug olabilir. */
function posts_by_category($category, $limit = 6, $offset = 0) {
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    $catId = 0;
    if (is_array($category)) { $catId = (int)$category['id']; }
    elseif (is_numeric($category)) { $catId = (int)$category; }
    else { $c = category_by_slug($category); $catId = $c ? (int)$c['id'] : 0; }
    if ($catId <= 0) { return []; }
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.category_id = :c AND ' . published_where() . '
        ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        [':c' => $catId, ':nowts' => now()]);
}

/** Kategorideki yayında haber sayısı. */
function posts_count_by_category($catId) {
    return (int)qv('SELECT COUNT(*) FROM posts p WHERE p.category_id = :c AND ' . published_where(),
        [':c' => (int)$catId, ':nowts' => now()], 0);
}

/** En yeni haberler. */
function latest_posts($limit = 10, $offset = 0, $excludeIds = []) {
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    $params = [':nowts' => now()];
    $ex = '';
    $ids = array_values(array_filter(array_map('intval', (array)$excludeIds)));
    if ($ids) { $ex = ' AND p.id NOT IN (' . implode(',', $ids) . ')'; }
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE ' . published_where() . $ex . '
        ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
}

/** Yayındaki toplam haber sayısı. */
function posts_count_total() {
    return (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . published_where(), [':nowts' => now()], 0);
}

/** Çok okunanlar (son $days gün). */
function most_read($limit = 5, $days = 7) {
    $limit = max(1, min(30, (int)$limit));
    $since = date('Y-m-d H:i:s', time() - max(1, (int)$days) * 86400);
    $rows = qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE ' . published_where() . ' AND p.published_at >= :since AND p.view_count > 0
        ORDER BY p.view_count DESC, p.published_at DESC LIMIT ' . $limit,
        [':nowts' => now(), ':since' => $since]);
    if (count($rows) < $limit) {
        $have = array_map(function ($r) { return (int)$r['id']; }, $rows);
        $rows = array_merge($rows, latest_posts($limit - count($rows), 0, $have));
    }
    return $rows;
}

/** Editör seçimi (panelde seçilen id listesi). */
function editor_picks($limit = 4) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)setting('editor_picks', '')))));
    if (!$ids) { return latest_posts($limit); }
    $ids = array_slice($ids, 0, max(1, min(20, (int)$limit)));
    $in = implode(',', $ids);
    $rows = qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.id IN (' . $in . ') AND ' . published_where(), [':nowts' => now()]);
    // Panelde verilen sırayı koru
    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }
    $out = [];
    foreach ($ids as $id) { if (isset($byId[$id])) { $out[] = $byId[$id]; } }
    return $out;
}

/** Video türündeki haberler. */
function video_posts($limit = 4) {
    $limit = max(1, min(30, (int)$limit));
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.type = \'video\' AND ' . published_where() . '
        ORDER BY p.published_at DESC LIMIT ' . $limit, [':nowts' => now()]);
}

/** Tek haber (yayında olan). Gövde dâhil. */
function post_by_id($id, $anyStatus = false) {
    $id = (int)$id;
    if ($id <= 0) { return null; }
    $sql = 'SELECT p.*, c.name AS category_name, c.slug AS category_slug, c.color AS category_color, u.name AS author_name
            FROM posts p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN users u ON u.id = p.author_id
            WHERE p.id = :i';
    $params = [':i' => $id];
    if (!$anyStatus) { $sql .= ' AND ' . published_where(); $params[':nowts'] = now(); }
    return q1($sql, $params);
}

/** Etikete göre haberler. */
function tag_posts($tag, $limit = 20, $offset = 0) {
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$tag) . '%';
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE ' . published_where() . ' AND p.tags LIKE :t
        ORDER BY p.published_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        [':nowts' => now(), ':t' => $like]);
}

/** Etiketle eşleşen haber sayısı. */
function tag_posts_count($tag) {
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$tag) . '%';
    return (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . published_where() . ' AND p.tags LIKE :t',
        [':nowts' => now(), ':t' => $like], 0);
}

/** Aynı kategoriden ilgili haberler. */
function related_posts($post, $limit = 4) {
    if (!is_array($post)) { return []; }
    $limit = max(1, min(12, (int)$limit));
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.category_id = :c AND p.id <> :i AND ' . published_where() . '
        ORDER BY p.published_at DESC LIMIT ' . $limit,
        [':c' => (int)$post['category_id'], ':i' => (int)$post['id'], ':nowts' => now()]);
}

/** Arama: başlık + spot + etiket. ['items'=>[], 'total'=>n] döner. */
function search_posts($term, $page = 1, $perPage = 12) {
    $term = trim((string)$term);
    if (mb_strlen($term) < 2) { return ['items' => [], 'total' => 0]; }
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
    $perPage = max(1, min(50, (int)$perPage));
    $offset = max(0, ((int)$page - 1) * $perPage);
    $where = published_where() . ' AND (p.title LIKE :t OR p.spot LIKE :t OR p.tags LIKE :t)';
    $total = (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . $where, [':nowts' => now(), ':t' => $like], 0);
    $items = qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE ' . $where . ' ORDER BY p.published_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
        [':nowts' => now(), ':t' => $like]);
    return ['items' => $items, 'total' => $total];
}

/** Slug'a göre sabit sayfa. */
function page_by_slug($slug) { return q1('SELECT * FROM pages WHERE slug = :s', [':s' => (string)$slug]); }

/** Alt bilgide gösterilecek sayfalar. */
function pages_footer() {
    return qa('SELECT id, title, slug FROM pages WHERE in_footer = 1 ORDER BY sort ASC, id ASC');
}

/** Onaylı yorumlar. */
function comments_for($postId) {
    return qa('SELECT id, name, body, created_at FROM comments WHERE post_id = :p AND status = \'approved\' ORDER BY created_at ASC',
        [':p' => (int)$postId]);
}
/** Onaylı yorum sayısı. */
function comment_count($postId) {
    return (int)qv('SELECT COUNT(*) FROM comments WHERE post_id = :p AND status = \'approved\'', [':p' => (int)$postId], 0);
}

/**
 * Reklam alanı içeriği. Yönetici tarafından girilen HTML ham basılır.
 * Geçerli slot anahtarları: header, sidebar_1, sidebar_2, in_article, footer
 */
function ad_slot($key) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            // Faz 7: reklamın türü ayrıldı.
            //   kind='image' → görsel + bağlantı, güvenle üretilir (ads.creative yeter)
            //   kind='html'  → ham kod; YALNIZ bir yönetici onayladıysa (approved_by) basılır.
            // Eski kurulumlarda bu sütunlar yoktur; sorgu düşerse v1 sorgusuna geri dönülür.
            try {
                $rows = qa('SELECT slot_key, html, kind, image, link_url, alt_text, approved_by FROM ads
                    WHERE active = 1
                      AND (starts_at IS NULL OR starts_at = \'\' OR starts_at <= :n1)
                      AND (ends_at IS NULL OR ends_at = \'\' OR ends_at >= :n2)
                    ORDER BY sort ASC, id ASC', [':n1' => now(), ':n2' => now()]);
            } catch (Throwable $inner) {
                $rows = qa('SELECT slot_key, html FROM ads
                    WHERE active = 1
                      AND (starts_at IS NULL OR starts_at = \'\' OR starts_at <= :n1)
                      AND (ends_at IS NULL OR ends_at = \'\' OR ends_at >= :n2)
                    ORDER BY sort ASC, id ASC', [':n1' => now(), ':n2' => now()]);
            }
            foreach ($rows as $r) {
                $html = ad_render_row($r);
                if ($html !== '') { $cache[$r['slot_key']][] = $html; }
            }
        } catch (Throwable $e) { $cache = []; }
    }
    if (empty($cache[$key])) { return ''; }
    $html = '<div class="ad-slot ad-' . esc($key) . '">' . implode("\n", $cache[$key]) . '</div>';
    // KANCA (1.2-03): gösterim sayacı ve sponsorlu rozeti inc/ads.php içindedir.
    // Modül yoksa reklam 1.1'deki gibi basılır.
    if (function_exists('ads_slot_decorate')) { $html = (string)ads_slot_decorate($key, $html); }
    return $html;
}

/**
 * Tek reklam kaydını HTML'e çevirir.
 * Ham HTML yalnız `approved_by` dolu olduğunda basılır — reklam yöneticisi rolü
 * ham kod giremez, girse bile yönetici onayı olmadan yayına çıkmaz (USER_TYPES_PLAN §8).
 */
function ad_render_row(array $r) {
    $kind = (string)arr($r, 'kind', 'html');

    if ($kind === 'image') {
        $img = (string)arr($r, 'image', '');
        if ($img === '') { return ''; }
        $src = url_upload($img);
        $alt = (string)arr($r, 'alt_text', '');
        $link = (string)arr($r, 'link_url', '');
        $tag = '<img src="' . esc($src) . '" alt="' . esc($alt) . '" loading="lazy" decoding="async">';
        if ($link !== '' && sanitize_is_safe_url($link, false)) {
            return '<a href="' . esc($link) . '" rel="nofollow noopener sponsored" target="_blank">' . $tag . '</a>';
        }
        return $tag;
    }

    // kind === 'html'
    $html = (string)arr($r, 'html', '');
    if (trim($html) === '') { return ''; }
    // Sütun yoksa (eski kurulum) onay şartı aranmaz — o kurulumda kodu zaten yalnız admin girebiliyordu.
    if (array_key_exists('approved_by', $r) && (int)$r['approved_by'] <= 0) { return ''; }
    return $html;
}

/** Haberin görsel URL'i; yoksa boş dize. $size: thumb|medium|large|orig */
function post_image($post, $size = 'medium') {
    $file = is_array($post) ? (string)arr($post, 'image', '') : (string)$post;
    if ($file === '') { return ''; }
    if (preg_match('#^https?://#i', $file)) { return $file; }
    if ($size !== 'orig') {
        $variant = media_variant_file($file, $size);
        if ($variant !== '') { return url_upload($variant); }
    }
    return url_upload($file);
}

/**
 * Görsel etiketi için tam öznitelik kümesi.
 *
 * İKİ AYRI HATAYI BİRDEN KAPATIR:
 *
 * 1) LCP: 1.0'da manşetin ilk büyük kartı ve haber sayfasının ana görseli de
 *    `loading="lazy"` idi (themes/gazete/single.php, parts/card.php). Ekranın
 *    en üstündeki görseli tembel yüklemek, tarayıcının onu geç keşfetmesine yol
 *    açar; Largest Contentful Paint doğrudan cezalanır ve bu Google Haberler
 *    sıralamasında bir sinyaldir. Sayfanın ilk görseli EAGER olmalıdır.
 *
 * 2) CLS: genişlik/yükseklik verilmediği için görsel yüklenince metin zıplıyordu.
 *    Varyant genişlikleri sabit (400/800/1280), yükseklik orandan hesaplanır.
 *
 * @param array  $post
 * @param string $size  thumb|medium|large|orig
 * @param bool   $eager Sayfanın LCP adayı mı? (manşetin ilk kartı, haber ana görseli)
 * @return string Kaçırılmış öznitelik dizesi; doğrudan <img …> içine basılır.
 */
function post_image_attrs($post, $size = 'medium', $eager = false) {
    $genislikler = ['thumb' => 400, 'medium' => 800, 'large' => 1280];
    $w = isset($genislikler[$size]) ? $genislikler[$size] : 0;

    $parcalar = [];
    if ($w > 0) {
        // Oran: medya kaydından gelirse gerçek oran, yoksa 16/9 varsayılır.
        $oran = 9 / 16;
        $mw = (int)arr($post, 'image_width', 0);
        $mh = (int)arr($post, 'image_height', 0);
        if ($mw > 0 && $mh > 0) { $oran = $mh / $mw; }
        $parcalar[] = 'width="' . $w . '"';
        $parcalar[] = 'height="' . (int)round($w * $oran) . '"';
    }
    if ($eager) {
        // fetchpriority: tarayıcıya "bu görsel her şeyden önce" der.
        $parcalar[] = 'loading="eager"';
        $parcalar[] = 'fetchpriority="high"';
        $parcalar[] = 'decoding="async"';
    } else {
        $parcalar[] = 'loading="lazy"';
        $parcalar[] = 'decoding="async"';
    }
    return implode(' ', $parcalar);
}

/**
 * WebP srcset'i (varyant üretilmişse).
 *
 * 1.0'da WebP varyantları ÜRETİLİYOR (inc/media.php) ama hiçbir yerde
 * SUNULMUYORDU — diskte duran, hiç kullanılmayan dosyalar. Tema bunu
 * <picture><source type="image/webp" …> ile kullanır; destek yoksa
 * tarayıcı sessizce <img> kaynağına düşer.
 *
 * @return string Boşsa WebP yok demektir; tema <source> basmamalıdır.
 */
function post_image_webp_srcset($post) {
    $file = is_array($post) ? (string)arr($post, 'image', '') : (string)$post;
    if ($file === '' || preg_match('#^https?://#i', $file)) { return ''; }
    if (!function_exists('media_variant_file')) { return ''; }

    $parcalar = [];
    foreach (['thumb' => 400, 'medium' => 800, 'large' => 1280] as $ad => $w) {
        // WebP kopyası diskte 'ad-thumb.webp' desenindedir (inc/media.php:203).
        $v = media_variant_file($file, $ad);
        if ($v === '') { continue; }
        $nokta = strrpos($v, '.');
        $wv = $nokta === false ? '' : substr($v, 0, $nokta) . '.webp';
        if ($wv !== '' && is_file(UPLOAD_DIR . '/' . $wv)) { $parcalar[] = url_upload($wv) . ' ' . $w . 'w'; }
    }
    return implode(', ', $parcalar);
}

/** Görsel için srcset dizesi (varyant varsa). */
function post_image_srcset($post) {
    $file = is_array($post) ? (string)arr($post, 'image', '') : (string)$post;
    if ($file === '' || preg_match('#^https?://#i', $file)) { return ''; }
    $map = ['thumb' => 400, 'medium' => 800, 'large' => 1280];
    $parts = [];
    foreach ($map as $size => $w) {
        $v = media_variant_file($file, $size);
        if ($v !== '') { $parts[] = url_upload($v) . ' ' . $w . 'w'; }
    }
    return implode(', ', $parts);
}

/** 'foto.jpg' + 'medium' → 'foto-medium.jpg' (dosya varsa). */
function media_variant_file($file, $size) {
    if (!in_array($size, ['thumb', 'medium', 'large'], true)) { return ''; }
    $file = ltrim(str_replace('\\', '/', (string)$file), '/');
    if (strpos($file, '..') !== false) { return ''; }
    $dot = strrpos($file, '.');
    if ($dot === false) { return ''; }
    $cand = substr($file, 0, $dot) . '-' . $size . substr($file, $dot);
    return is_file(UPLOAD_DIR . '/' . $cand) ? $cand : '';
}

/** Haber gövdesi: kayıtlıyken temizlenmiş olsa da çıkışta bir kez daha süzülür. */
function post_body_html($post) {
    $body = is_array($post) ? (string)arr($post, 'body', '') : (string)$post;

    // Faz 7 içerik kilidi — TEK KAPI. Temalara bırakılmaz ki bir tema unutunca
    // tam metin sızmasın. Kilitliyse yalnız teaser + abone kutusu döner.
    // OKUR KAPISI (1.2-05). Eskiden yalnız `post_is_locked()` soruluyordu; o
    // KİMLİĞE bakar (abone mi?). Ölçülü duvar ise SAYAÇLA çalışır: "bu ay N
    // ücretsiz haber", hediye bağlantısı, deneme. Kapı dar tutuldu — yalnız
    // burada, çünkü `paywall_allow()` yan etkilidir (hak düşürür, çerez yazar).
    // `post_is_locked()` merkezi olarak değiştirilseydi inc/seo.php beslemeleri
    // de onu çağırdığı için BESLEMEDE çerez yazılabilirdi (CONTRACTS §3.1 ihlali).
    // Ödeme duvarı modülü yoksa bu ifade `post_is_locked()` ile birebir aynıdır.
    if (is_array($post) && function_exists('post_reader_can_read_full')
        && !post_reader_can_read_full($post, current_user_if_session())) {
        return post_locked_html($post);
    }
    $html = sanitize_html($body, true);

    // PARAGRAF-İÇİ REKLAM (1.2-03). Kanca TEMİZLEYİCİDEN SONRA çalışır:
    // reklam işaretlemesi beyaz listeden geçseydi kırpılırdı. Sırası da önemli
    // — kilitli haber YUKARIDA erken döner, yani ödeme duvarının arkasındaki
    // metne reklam sokulmaz.
    if (function_exists('ads_body_inject')) { $html = (string)ads_body_inject($html); }
    return $html;
}

/** Kilitli içerik için gösterilecek önizleme + çağrı kutusu. */
function post_locked_html(array $post) {
    $teaser = trim((string)arr($post, 'teaser', ''));
    if ($teaser === '') {
        // Teaser girilmemişse gövdenin ilk paragrafı kullanılır
        $temiz = sanitize_html((string)arr($post, 'body', ''), false);
        if (preg_match('#<p[^>]*>.*?</p>#is', $temiz, $m)) { $teaser = $m[0]; }
        else { $teaser = '<p>' . esc(excerpt(arr($post, 'spot', ''), 220)) . '</p>'; }
    } else {
        $teaser = '<p>' . esc($teaser) . '</p>';
    }

    // ÖLÇÜLÜ DUVAR (1.2-05): modül kendi kutusunu döndürürse o basılır (kalan
    // hak sayısı, deneme düğmesi, hediye açıklaması). Boş dönerse çekirdeğin
    // kutusu kalır. BURADA — yani `$teaser` hesaplandıktan sonra: önizleme metni
    // her iki kutuda da aynı kuralla üretilsin.
    if (function_exists('paywall_locked_html')) {
        $kutu = (string)paywall_locked_html($post);
        if ($kutu !== '') { return $teaser . $kutu; }
    }

    $vis = (string)arr($post, 'visibility', 'public');
    // ÖNBELLEK KAPISI (denetim tur 2): burada current_user() ÇAĞRILMAZ — o
    // fonksiyon session_boot() tetikler ve kilitli haberi açan her anonim
    // ziyaretçiye çerez takardı; ziyaretçi bir daha hiçbir sayfayı önbellekten
    // alamaz, çerez saklamayan botlar da her istekte yeni oturum dosyası yaratırdı.
    $uye = current_user_if_session();
    if ($vis === 'premium') {
        $baslik = 'Bu içerik abonelere özeldir';
        $metin  = $uye ? 'Okumaya devam etmek için aboneliğinizi başlatın.'
                       : 'Okumaya devam etmek için giriş yapın veya abone olun.';
    } else {
        $baslik = 'Bu içerik üyelere özeldir';
        $metin  = $uye ? 'Hesabınızla okumaya devam edebilirsiniz.'
                       : 'Okumaya devam etmek için ücretsiz üye olun.';
    }

    $bag = $uye
        ? '<a class="dugme" href="' . esc(url('hesap')) . '">Hesabım</a>'
        : '<a class="dugme" href="' . esc(url('uye/giris')) . '">Giriş yap</a> '
          . '<a class="dugme ghost" href="' . esc(url('uye/kayit')) . '">Üye ol</a>';

    return $teaser
        . '<div class="icerik-kilit" data-kilit="' . esc($vis) . '">'
        . '<h3>' . esc($baslik) . '</h3>'
        . '<p>' . esc($metin) . '</p>'
        . '<p class="kilit-eylem">' . $bag . '</p>'
        . '</div>';
}

/** Haber kilitli mi? (temalar rozet/işaret basmak için kullanır) */
function post_locked_badge($post) {
    $vis = is_array($post) ? (string)arr($post, 'visibility', 'public') : 'public';
    if ($vis === 'premium') { return '<span class="rozet-premium" title="Abonelere özel">ABONE</span>'; }
    if ($vis === 'members') { return '<span class="rozet-uye" title="Üyelere özel">ÜYE</span>'; }
    return '';
}

/** Haberin etiket dizisi. */
function post_tags($post) { return tags_to_array(arr($post, 'tags', '')); }

/** Anasayfa blok dizilimi (tema editöründen). */
function home_blocks() {
    $raw = setting('home_blocks', '');
    $blocks = json_decode((string)$raw, true);
    if (!is_array($blocks) || !$blocks) {
        $blocks = [
            ['type' => 'breaking', 'on' => 1],
            ['type' => 'headline', 'on' => 1],
            ['type' => 'latest', 'on' => 1],
            ['type' => 'category', 'on' => 1, 'category_id' => 0],
            ['type' => 'most_read', 'on' => 1],
        ];
    }
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b) || empty($b['type'])) { continue; }
        if (isset($b['on']) && !$b['on']) { continue; }
        $out[] = $b + ['on' => 1, 'title' => '', 'limit' => 0, 'category_id' => 0, 'slot' => ''];
    }
    return $out;
}

/** Blok tipi => okunabilir ad (tema editörü ve şablonlar için ortak sözlük). */
function home_block_labels() {
    return [
        'breaking'    => 'Son Dakika Şeridi',
        'headline'    => 'Manşet',
        'latest'      => 'Son Haberler',
        'category'    => 'Kategori Bloğu',
        'most_read'   => 'Çok Okunanlar',
        'editor_pick' => 'Editörün Seçimi',
        'video'       => 'Video Haberler',
        'ad'          => 'Reklam Alanı',
        'market'      => 'Piyasa Şeridi',
        'weather'     => 'Hava Durumu',
        'newsletter'  => 'Bülten Kaydı',
    ];
}

/** Sayfalama HTML'i. $urlFn(int $page): string */
function paginate($total, $perPage, $current, $urlFn) {
    $perPage = max(1, (int)$perPage);
    $pages = (int)ceil($total / $perPage);
    if ($pages < 2) { return ''; }
    $current = max(1, min($pages, (int)$current));
    $out = '<nav class="pagination" aria-label="Sayfalama"><ul>';
    if ($current > 1) { $out .= '<li><a href="' . esc($urlFn($current - 1)) . '" rel="prev">‹ Önceki</a></li>'; }
    $from = max(1, $current - 2);
    $to = min($pages, $current + 2);
    if ($from > 1) { $out .= '<li><a href="' . esc($urlFn(1)) . '">1</a></li>' . ($from > 2 ? '<li class="gap">…</li>' : ''); }
    for ($i = $from; $i <= $to; $i++) {
        $out .= $i === $current
            ? '<li class="current"><span aria-current="page">' . $i . '</span></li>'
            : '<li><a href="' . esc($urlFn($i)) . '">' . $i . '</a></li>';
    }
    if ($to < $pages) { $out .= ($to < $pages - 1 ? '<li class="gap">…</li>' : '') . '<li><a href="' . esc($urlFn($pages)) . '">' . $pages . '</a></li>'; }
    if ($current < $pages) { $out .= '<li><a href="' . esc($urlFn($current + 1)) . '" rel="next">Sonraki ›</a></li>'; }
    return $out . '</ul></nav>';
}

/** Görüntülenme sayacı (oturum bazlı tekilleştirme). */
function post_register_view($postId) {
    $postId = (int)$postId;
    if ($postId <= 0) { return; }
    // SECURITY_AUDIT B-02: yalnız oturuma güvenmek yetmiyor — çerez döndürmeyen
    // bir istemci her istekte yeni oturum açıp sayacı şişirebiliyordu.
    // IP + haber temelli, 6 saatlik ikinci bir tekilleştirme kapısı eklendi.
    //
    // DENETIM TURU 3 (on yuz B01): burada OTURUM ACILMAZ.
    // Eskiden IP kapisinin ustune bir de $_SESSION['seen_posts'] katmani vardi.
    // assets/site.js bu ucu HER haber sayfasinda otomatik cagirdigi icin sonuc
    // sudur: her anonim okur bir cerez kapiyor ve o andan sonra hicbir sayfa
    // onbellekten sunulamiyor — Faz 4 sayfa onbellegi ziyaretin yalnizca ILK
    // sayfasina hizmet ediyordu. Ayrica cerez saklamayan botlar her istekte
    // yeni bir oturum dosyasi yaratiyordu.
    // IP + haber kapisi (6 saat) zaten BIRINCIL tekillestiricidir ve cerez
    // saklamayan istemciye karsi oturumdan DAHA guclusudur; ikinci katman
    // yalnizca maliyet getiriyordu. CONTRACTS 3.1: on yuz cerez VERMEZ.
    if (!rate_limit('viewuniq:' . client_ip() . ':' . $postId, 1, 21600)) { return; }
    try { q('UPDATE posts SET view_count = view_count + 1 WHERE id = :i', [':i' => $postId]); } catch (Throwable $e) { }

    // KANCA (1.2-01): çerezsiz günlük toplu analitik. Kapı YUKARIDA geçildi,
    // yani analitik de aynı 6 saatlik tekilleştirmeyi miras alır. IP burada
    // yalnız kapı için kullanıldı; analitik modülü IP SAKLAMAZ.
    if (function_exists('analytics_record_view')) {
        try { analytics_record_view($postId); } catch (Throwable $e) { }
    }
}

// ============================================================ eklenti kancaları

/** Piyasa şeridi verisi (inc/widgets.php sağlarsa oradan). */
function widget_market() {
    if (function_exists('widgets_market_data')) { return widgets_market_data(); }
    return [];
}
/** Hava durumu verisi (inc/widgets.php sağlarsa oradan). */
function widget_weather() {
    if (function_exists('widgets_weather_data')) { return widgets_weather_data(); }
    return [];
}
/** <head> içine SEO çıktısı (inc/seo.php sağlarsa oradan). */
function seo_head_tags() {
    if (function_exists('seo_render_head')) { return seo_render_head(view_context()); }
    $t = site('title');
    return '<title>' . esc($t) . '</title>' . "\n";
}
/** Künye bilgisi (inc/kunye.php sağlarsa oradan). */
function kunye_fields() {
    if (function_exists('kunye_data')) { return kunye_data(); }
    return [];
}
/** Çerez bildirimi çubuğu HTML'i. */
function cookie_notice_html() {
    if (setting('cookie_notice', '1') !== '1') { return ''; }
    $text = setting('cookie_notice_text', 'Bu sitede zorunlu çerezler kullanılır.');
    $privacy = page_by_slug('gizlilik');
    $link = $privacy ? ' <a href="' . esc(url_page($privacy)) . '">Ayrıntılar</a>' : '';
    return '<div class="cookie-bar" id="cookieBar" hidden>'
         . '<p>' . esc($text) . $link . '</p>'
         . '<button type="button" id="cookieOk">Tamam</button></div>';
}

/**
 * Yayincinin panele yapistirdigi dis olcumcu kodu (Plausible/Matomo/GA).
 *
 * Tema <body> kapanisindan once basar. inc/analytics.php yoksa bos doner —
 * yani bu kanca tek basina hicbir sey degistirmez.
 *
 * ONBELLEK NOTU: kod onbellege alinan HTML'in icine girer; bu dogrudur,
 * cunku olcumcu kodu ziyaretciye ozel degildir. Ziyaretciye ozel bir sey
 * basmak isteyen bir kod buraya KONMAMALIDIR.
 */
function analytics_head_html() {
    return function_exists('analytics_external_html') ? (string)analytics_external_html() : '';
}

// ============================================================ Ajan-6 eklemeleri
// Bu bölümdeki yardımcılar tüm temalarca (gazete, kagit ve Ajan-7 temaları)
// kullanılır. Mevcut imzalar değiştirilmedi; yalnız yeni fonksiyon eklendi.

/**
 * Güncelleme tarihi içerik üzerinde gösterilmeli mi?
 * Basın Kanunu m.4: ilk yayım tarihi VE sonraki güncelleme tarihleri
 * içeriğin üzerinde belirtilir (docs/BIK-NOTLARI.md §3).
 * 120 saniyelik tolerans, kaydetme anındaki küçük farkı "güncellendi" saymaz.
 */
function post_updated_visible($post) {
    if (!is_array($post)) { return false; }
    $upd = trim((string)arr($post, 'updated_at', ''));
    $pub = trim((string)arr($post, 'published_at', ''));
    if ($upd === '') { return false; }
    $u = strtotime($upd);
    if ($u === false) { return false; }
    if ($pub === '') { return false; }
    $p = strtotime($pub);
    if ($p === false) { return false; }
    return $u > $p + 120;
}

/** Tahmini okuma süresi (dakika). 200 kelime/dk, en az 1. */
function post_reading_time($post) {
    $text = '';
    if (is_array($post)) {
        $text = (string)arr($post, 'body', '');
        if (trim($text) === '') { $text = (string)arr($post, 'spot', ''); }
    } else {
        $text = (string)$post;
    }
    $text = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>'], ' ', $text));
    $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES, 'UTF-8')));
    if ($text === '') { return 1; }
    $words = count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY));
    return max(1, (int)ceil($words / 200));
}

/**
 * Kırıntı yolu (breadcrumb) verisini saklar ve/veya döndürür.
 * inc/seo.php (Ajan-8) BreadcrumbList üretmek için bunu okuyabilir.
 * @return array [['label'=>string,'url'=>string], …]
 */
function breadcrumb_items($set = null) {
    static $items = [];
    if (is_array($set)) { $items = $set; }
    return $items;
}

/**
 * Kırıntı yolu HTML'i. Öğe biçimi: ['label'=>'Ekonomi', 'url'=>'…'] ya da düz metin.
 * Son öğe bağlantısız basılır (aria-current="page").
 * SEO modülü yoksa BreadcrumbList JSON-LD'sini de buradan basar; modül varsa
 * veriyi breadcrumb_items() üzerinden ona bırakır (çift yapı basılmaz).
 */
function breadcrumbs(array $items) {
    $clean = [];
    foreach ($items as $it) {
        if (is_array($it)) {
            $label = (string)arr($it, 'label', arr($it, 'title', ''));
            $href  = (string)arr($it, 'url', arr($it, 'href', ''));
        } else {
            $label = (string)$it;
            $href  = '';
        }
        if (trim($label) === '') { continue; }
        $clean[] = ['label' => $label, 'url' => $href];
    }
    if (!$clean) { return ''; }
    breadcrumb_items($clean);

    $out = '<nav class="kirinti" aria-label="Konum"><ol>';
    $n = count($clean);
    foreach ($clean as $i => $c) {
        $last = ($i === $n - 1);
        $out .= '<li>';
        if ($c['url'] !== '' && !$last) {
            $out .= '<a href="' . esc($c['url']) . '">' . esc($c['label']) . '</a>';
        } else {
            $out .= '<span' . ($last ? ' aria-current="page"' : '') . '>' . esc($c['label']) . '</span>';
        }
        $out .= '</li>';
    }
    $out .= '</ol></nav>';

    if (!function_exists('seo_render_head')) {
        $list = [];
        foreach ($clean as $i => $c) {
            $node = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['label']];
            if ($c['url'] !== '') { $node['item'] = $c['url']; }
            $list[] = $node;
        }
        // JSON_HEX_TAG|JSON_HEX_AMP: < > & \u00XX'e çevrilir, </script> ile kaçış imkânsız olur
        $json = json_encode(
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $list],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (is_string($json)) {
            $out .= '<' . 'script type="application/ld+json">' . $json . '</' . 'script>';
        }
    }
    return $out;
}

/**
 * <body> sınıf listesi: "tema-<ad> sidebar-<konum> kart-<stil> sayfa-<sablon>"
 * Temalar bunu doğrudan basar; CSS bu sınıflara dayanır.
 *
 * `koyu-mod` SINIFI 1.2'DE KALDIRILDI. Basılıyordu ama hiçbir CSS onu
 * kullanmıyordu (Ajan-G ölçtü) ve artık YANILTICI olurdu: yayıncının tema
 * ayarını yansıtıyordu, oysa karanlık mod 1.2'den beri OKURUN kararı.
 * Etkin görünüm `<html data-goruntu="acik|koyu">` özniteliğinde durur;
 * yayıncının `theme_dark_mode()` tercihi de oraya varsayılan olarak beslenir
 * (bkz. tema `layout.php`). İki ayrı mekanizma bırakmak, ikisinin zamanla
 * ayrışması demekti.
 */
function theme_body_class() {
    $slug = function ($v, $fallback) {
        $v = preg_replace('/[^a-z0-9_\-]/i', '', (string)$v);
        return $v === '' ? $fallback : strtolower($v);
    };
    $cls = [
        'tema-' . $slug(theme_name(), 'gazete'),
        'sidebar-' . $slug(theme_setting('sidebar_position', 'right'), 'right'),
        'kart-' . $slug(theme_setting('card_style', 'shadow'), 'shadow'),
    ];
    $ctx = view_context();
    $tpl = isset($ctx['template']) ? $slug($ctx['template'], '') : '';
    if ($tpl !== '') { $cls[] = 'sayfa-' . $tpl; }
    return implode(' ', $cls);
}

/**
 * Anasayfada gösterilecek düzeltme-cevap metinleri (Basın Kanunu m.14):
 * ilk 24 saat ana sayfada. inc/kunye.php (Ajan-9) kancayı sağlarsa oradan gelir.
 * Öğe biçimi:
 *   ['id'=>int,'post_id'=>int,'post_title'=>string,'post_url'=>string,
 *    'message'=>string,'created_at'=>string]
 */
function home_correction_notices() {
    // Düzeltme-cevap metinleri (Basın Kanunu m.14): ilk 24 saat ana sayfada.
    // inc/kunye.php (Ajan-9) kancayı sağlarsa oradan gelir; yoksa boş dizi.
    if (function_exists('kunye_homepage_corrections')) { return kunye_homepage_corrections(); }
    return [];
}

/**
 * Çıktı başlamadan önce CSRF oturumunun açılması gerekip gerekmediğini belirler.
 *
 * NEDEN: csrf_token() → session_boot() headers_sent() olduğunda sessizce geri döner;
 * gövde basılmaya başladıktan sonra üretilen anahtar oturuma yazılamaz ve gönderim
 * "Oturum doğrulaması başarısız" ile reddedilir. Anasayfadaki bülten formu tam olarak
 * bu duruma düşüyordu (haber sayfasında sorun yok: post_register_view() oturumu
 * zaten açıyor).
 *
 * Oturumu YALNIZ gerektiğinde açar; aksi hâlde anasayfa oturumsuz kalır ve
 * inc/cache.php (Ajan-10) sayfayı önbellekten sunabilir.
 *
 * KULLANIM: her temanın layout.php dosyasında, ilk çıktıdan (doctype) ÖNCE çağrılır.
 */
function theme_prepare_forms() {
    // FAZ 3 ENTEGRASYONU (orkestratör): Artık oturum AÇMAZ.
    //
    // Ön yüz formları csrf_field_lazy() kullanıyor: alan boş basılıyor, anahtar
    // yalnız kullanıcı forma dokunduğunda `api.php?a=public.csrf` ucundan
    // alınıyor. Böylece hiçbir anonim ziyaretçiye çerez verilmiyor ve haber
    // sayfaları da (yorum/düzeltme formu olmasına rağmen) önbelleğe alınabiliyor.
    //
    // Fonksiyon, temalar çağırmaya devam edebilsin diye korundu; ileride
    // çıktı öncesi hazırlık gerekirse buraya eklenir.
    return;
}

/**
 * Piyasa şeridi verisini şablonların basabileceği tek biçime indirger.
 * inc/widgets.php (Ajan-9) hangi biçimi verirse versin çıktı hep:
 *   [['label'=>string,'value'=>string,'change'=>string,'dir'=>'up'|'down'|''], …]
 */
function widget_market_rows() {
    $data = widget_market();
    if (!is_array($data) || !$data) { return []; }
    if (isset($data['items']) && is_array($data['items'])) { $data = $data['items']; }
    $out = [];
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $label = (string)arr($v, 'label', arr($v, 'name', arr($v, 'kod', is_string($k) ? $k : '')));
            $value = (string)arr($v, 'value', arr($v, 'deger', arr($v, 'fiyat', '')));
            $chg   = (string)arr($v, 'change', arr($v, 'degisim', ''));
        } else {
            $label = is_string($k) ? $k : '';
            $value = (string)$v;
            $chg   = '';
        }
        $label = trim(str_replace('_', ' ', $label));
        if ($label === '' || $value === '') { continue; }
        $dir = '';
        if ($chg !== '') {
            $first = substr(ltrim($chg), 0, 1);
            if ($first === '+') { $dir = 'up'; }
            elseif ($first === '-') { $dir = 'down'; }
        }
        $out[] = ['label' => $label, 'value' => $value, 'change' => $chg, 'dir' => $dir];
    }
    return $out;
}

/**
 * Hava durumu verisini tek biçime indirger.
 *   [['city'=>string,'temp'=>string,'text'=>string], …]
 */
function widget_weather_rows() {
    $data = widget_weather();
    if (!is_array($data) || !$data) { return []; }
    if (isset($data['items']) && is_array($data['items'])) { $data = $data['items']; }
    // Tek şehir: ['sehir'=>…, 'derece'=>…] biçimi liste gibi görünmez
    $isSingle = false;
    foreach (['sehir', 'city', 'derece', 'temp'] as $probe) {
        if (array_key_exists($probe, $data)) { $isSingle = true; break; }
    }
    if ($isSingle) { $data = [$data]; }
    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) { continue; }
        $city = (string)arr($row, 'city', arr($row, 'sehir', ''));
        $temp = (string)arr($row, 'temp', arr($row, 'derece', ''));
        $text = (string)arr($row, 'text', arr($row, 'durum', ''));
        if ($city === '' && $temp === '') { continue; }
        $out[] = ['city' => $city, 'temp' => $temp, 'text' => $text];
    }
    return $out;
}

/**
 * Paylaşım bağlantıları. DIŞ API YOK — yalnız intent adresleri ve pano kopyalama.
 * @return array [['key'=>…,'label'=>…,'url'=>…], …]  ('copy' anahtarında url = haber adresi)
 */
function share_links($post) {
    if (!is_array($post)) { return []; }
    $link = url_post($post);
    $title = (string)arr($post, 'title', '');
    return [
        ['key' => 'x',        'label' => 'X (Twitter)', 'url' => 'https://twitter.com/intent/tweet?text=' . rawurlencode($title) . '&url=' . rawurlencode($link)],
        ['key' => 'facebook', 'label' => 'Facebook',    'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode($link)],
        ['key' => 'whatsapp', 'label' => 'WhatsApp',    'url' => 'https://api.whatsapp.com/send?text=' . rawurlencode($title . ' ' . $link)],
        ['key' => 'eposta',   'label' => 'E-posta',     'url' => 'mailto:?subject=' . rawurlencode($title) . '&body=' . rawurlencode($link)],
        ['key' => 'copy',     'label' => 'Bağlantıyı kopyala', 'url' => $link],
    ];
}
