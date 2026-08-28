<?php
/**
 * Manşet — ön yüz yönlendirici.
 * Tüm genel istekler buradan geçer. SEF açıksa .htaccess bu dosyaya yönlendirir,
 * kapalıysa ?r=<yol> parametresi kullanılır.
 */

require_once __DIR__ . '/inc/bootstrap.php';

// Kurulum tamamlanmadıysa sihirbaza gönder
if (!MANSET_INSTALLED) {
    if (is_dir(__DIR__ . '/install')) {
        header('Location: ' . base_url() . '/install/', true, 302);
        exit;
    }
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Kurulum gerekli</title><p style="font:16px system-ui;padding:40px">Kurulum tamamlanmamış ve <code>install/</code> klasörü bulunamadı.</p>';
    exit;
}

require_once __DIR__ . '/inc/view.php';
if (is_file(__DIR__ . '/inc/seo.php'))     { require_once __DIR__ . '/inc/seo.php'; }
if (is_file(__DIR__ . '/inc/cache.php'))   { require_once __DIR__ . '/inc/cache.php'; }
if (is_file(__DIR__ . '/inc/widgets.php')) { require_once __DIR__ . '/inc/widgets.php'; }
if (is_file(__DIR__ . '/inc/kunye.php'))   { require_once __DIR__ . '/inc/kunye.php'; }

/**
 * İstek yolunu çözer.
 * Öncelik: ?r= parametresi → PATH_INFO → REQUEST_URI (taban klasör çıkarılarak)
 */
function route_path() {
    if (isset($_GET['r']) && is_string($_GET['r'])) {
        return trim(preg_replace('#/+#', '/', $_GET['r']), '/');
    }
    if (!empty($_SERVER['PATH_INFO'])) {
        return trim(preg_replace('#/+#', '/', $_SERVER['PATH_INFO']), '/');
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $uri = (string)parse_url($uri, PHP_URL_PATH);
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $baseDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($baseDir !== '' && $baseDir !== '/' && strpos($uri, $baseDir) === 0) {
        $uri = substr($uri, strlen($baseDir));
    }
    $uri = preg_replace('#^/index\.php#', '', (string)$uri);
    return trim(preg_replace('#/+#', '/', (string)$uri), '/');
}

$path = route_path();

/**
 * SEF otomatik algılama (kendi kendini onaran):
 * İstek ?r= parametresi ve PATH_INFO olmadan, dolu bir yol ile geldiyse
 * sunucuda URL yeniden yazma çalışıyor demektir. Ayarı bir kez açarız.
 * Kurulum sırasındaki sonda tek iş parçacıklı sunucularda başarısız olabildiği
 * için bu yedek algılama önemlidir.
 */
$requestPath = (string)parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
if ($path !== '' && !isset($_GET['r'])
    && strpos($requestPath, '/index.php') === false
    && setting('use_rewrite', '0') !== '1') {
    setting_set('use_rewrite', '1');
}

$segments = $path === '' ? [] : explode('/', $path);
$head = isset($segments[0]) ? rawurldecode($segments[0]) : '';
$page = max(1, (int)(isset($_GET['sayfa']) ? $_GET['sayfa'] : 1));
$perPage = max(3, min(50, (int)setting('posts_per_page', '12')));

// SEF kurulum sondası — install sihirbazı rewrite'ın çalışıp çalışmadığını böyle anlar
if ($head === 'probe-rewrite') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'MANSET_REWRITE_OK';
    exit;
}

// Oturum çerezi olan ziyaretçi için oturumu ÇIKTIDAN ÖNCE açarız.
// NEDEN: session_boot() headers_sent() olduğunda sessizce geri döner. Tema,
// gövdeyi <!doctype html> basıldıktan SONRA oluşturduğu için o noktada açılan
// oturum boş kalırdı ve giriş yapmış kullanıcı kilitli içeriği göremezdi.
// Anonim ziyaretçide çerez olmadığı için oturum açılmaz — önbellek korunur.
if (function_exists('has_session_cookie') && has_session_cookie()) { session_boot(); }

// Faz 4 sayfa önbelleği: oturumu olmayan ziyaretçilere hazır sayfa sunulur
$cacheKey = '';
if (function_exists('cache_should_serve') && cache_should_serve()) {
    $cacheKey = function_exists('cache_key_for') ? cache_key_for($path, $_GET) : '';
    if ($cacheKey !== '' && function_exists('cache_serve') && cache_serve($cacheKey)) { exit; }
    if ($cacheKey !== '' && function_exists('cache_begin')) { cache_begin($cacheKey, $head); }
}

/** 404 sayfası basıp sonlandırır. */
function render_404($message = 'Aradığınız sayfa bulunamadı.') {
    render('notfound', ['message' => $message], 404);
    if (function_exists('cache_abort')) { cache_abort(); }
    exit;
}

switch ($head) {

    // ---------------------------------------------------------------- anasayfa
    case '':
        render('home', [
            'headlines' => headline_posts((int)setting('headline_limit', '6')),
            'latest'    => latest_posts($perPage),
            'blocks'    => home_blocks(),
        ]);
        break;

    // ---------------------------------------------------------------- haber
    case 'haber':
        $rest = isset($segments[1]) ? rawurldecode($segments[1]) : '';
        if (!preg_match('/^(.*)-(\d+)$/', $rest, $m)) {
            // Kimliksiz ya da eski biçimli adres: slug geçmişinde eşleşme varsa 301
            if (function_exists('seo_redirect_old_slug')) { seo_redirect_old_slug($rest); }
            render_404();
        }
        $slug = $m[1];
        $id = (int)$m[2];
        $post = post_by_id($id);
        if (!$post) {
            // Silinmiş/arşivlenmiş içerik için 410, hiç olmayan için 404
            $exists = q1('SELECT id, status FROM posts WHERE id = :i', [':i' => $id]);
            if ($exists && $exists['status'] === 'archived') {
                render('notfound', ['message' => 'Bu içerik yayından kaldırıldı.'], 410);
                exit;
            }
            // Haber id'si tutmuyor ama slug geçmişte kullanılmışsa güncel adrese 301
            if (function_exists('seo_redirect_old_slug')) { seo_redirect_old_slug($slug); }
            render_404();
        }
        // Kanonik olmayan slug → 301
        if ($slug !== $post['slug']) {
            header('Location: ' . url_post($post), true, 301);
            exit;
        }
        // Görüntülenme sayacı sunucuda DEĞİL, istemciden sayılır (public.view ucu).
        // Neden: post_register_view() oturum açar; her anonim ziyaretçiye çerez
        // verilseydi haber sayfaları hiçbir zaman önbelleğe alınamazdı. Ayrıca
        // JS çalıştırmayan botlar sayaca girmiyor (BİK teknik esasları §5).
        render('single', [
            'post'     => $post,
            'related'  => related_posts($post, 4),
            'comments' => comments_for($id),
        ]);
        break;

    // ---------------------------------------------------------------- kategori
    case 'kategori':
        $slug = isset($segments[1]) ? rawurldecode($segments[1]) : '';
        $cat = category_by_slug($slug);
        if (!$cat) { render_404('Kategori bulunamadı.'); }
        $total = posts_count_by_category($cat['id']);
        render('category', [
            'category' => $cat,
            'posts'    => posts_by_category($cat, $perPage, ($page - 1) * $perPage),
            'total'    => $total,
            'page'     => $page,
            'perPage'  => $perPage,
        ]);
        break;

    // ---------------------------------------------------------------- etiket
    case 'etiket':
        $slug = isset($segments[1]) ? rawurldecode($segments[1]) : '';
        if ($slug === '') { render_404(); }
        // Slug'tan görünen etiket adını bul (etiketler serbest metin olarak saklanır)
        $label = $slug;
        foreach (qa('SELECT tags FROM posts WHERE tags <> \'\' LIMIT 500') as $row) {
            foreach (tags_to_array($row['tags']) as $t) {
                if (slugify($t) === $slug) { $label = $t; break 2; }
            }
        }
        $total = tag_posts_count($label);
        render('search', [
            'heading' => 'Etiket: ' . $label,
            'term'    => $label,
            'isTag'   => true,
            'posts'   => tag_posts($label, $perPage, ($page - 1) * $perPage),
            'total'   => $total,
            'page'    => $page,
            'perPage' => $perPage,
        ]);
        break;

    // ---------------------------------------------------------------- sabit sayfa
    case 'sayfa':
        $slug = isset($segments[1]) ? rawurldecode($segments[1]) : '';
        $pg = page_by_slug($slug);
        if (!$pg) { render_404('Sayfa bulunamadı.'); }
        render('page', ['page' => $pg]);
        break;

    // ---------------------------------------------------------------- künye
    case 'kunye':
        // Künye gövdesi güvenilir sunucu koduyla üretilir (inc/kunye.php).
        // Temada özel bir 'kunye' şablonu varsa gövde HAM basılır — böylece
        // BİK/EİDS doğrulama kodu ikinci bir temizleyiciye takılmaz.
        // Şablon yoksa 'page' şablonuna düşülür (gövde orada temizlenir).
        $kunyeTemplate = view_resolve('kunye') ? 'kunye' : 'page';
        render($kunyeTemplate, [
            'page' => [
                'id'        => 0,
                'title'     => 'Künye',
                'slug'      => 'kunye',
                'seo_title' => 'Künye — ' . setting('site_title', 'Manşet'),
                'seo_desc'  => setting('site_title', 'Manşet') . ' künyesi: imtiyaz sahibi, sorumlu yazı işleri müdürü, '
                             . 'iletişim ve yer sağlayıcı bilgileri.',
                'body'      => function_exists('kunye_render_html')
                                ? kunye_render_html()
                                : '<p>Künye bilgileri henüz girilmedi. Panel → Künye / BİK ekranından doldurabilirsiniz.</p>',
            ],
            'isKunye' => true,
        ]);
        break;

    // ---------------------------------------------------------------- üye hesabı
    case 'hesap':
    case 'uye':
        // /hesap · /uye/giris · /uye/kayit · /uye/dogrula · /uye/parola
        if (!is_file(__DIR__ . '/hesap.php')) { render_404(); }
        $bolum = isset($segments[1]) ? preg_replace('/[^a-z]/', '', rawurldecode($segments[1])) : '';
        if ($head === 'uye' && $bolum === '') { $bolum = 'giris'; }
        if ($bolum !== '' && !isset($_GET['s'])) { $_GET['s'] = $bolum; }
        require __DIR__ . '/hesap.php';
        exit;

    // ---------------------------------------------------------------- arama
    case 'arama':
        $term = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
        $res = search_posts($term, $page, $perPage);
        render('search', [
            'heading' => $term === '' ? 'Arama' : 'Arama: ' . $term,
            'term'    => $term,
            'isTag'   => false,
            'posts'   => $res['items'],
            'total'   => $res['total'],
            'page'    => $page,
            'perPage' => $perPage,
        ]);
        break;

    // ---------------------------------------------------------------- besleme kısayolları
    case 'rss.xml':
    case 'feed':
        header('Location: ' . base_url() . '/rss.php', true, 302);
        exit;

    case 'sitemap.xml':
        header('Location: ' . base_url() . '/sitemap.php', true, 302);
        exit;

    case 'robots.txt':
        header('Content-Type: text/plain; charset=utf-8');
        echo function_exists('seo_robots_txt') ? seo_robots_txt()
            : "User-agent: *\nAllow: /\nSitemap: " . base_url() . "/sitemap.php\n";
        exit;

    default:
        render_404();
}

// Önbelleğe yaz (etkinse)
if ($cacheKey !== '' && function_exists('cache_end')) { cache_end(); }
