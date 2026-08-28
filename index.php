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
// Üyelik modülü ÖN YÜZDE de gerekli (1.1): temalar member_current() varlığına
// bakıp "Kaydet" düğmesini ve üye kutusunu basıyor. Modül yüklenmediği için
// bu düğme beş temada da sessizce hiç basılmıyordu — üyeliğin görünür tek
// getirisi ölü kalıyordu. Modül OTURUM AÇMAZ; member_current() içeride
// has_session_cookie() kapısından geçer, yani önbellek kuralı korunur.
if (is_file(__DIR__ . '/inc/members.php')) { require_once __DIR__ . '/inc/members.php'; }
if (is_file(__DIR__ . '/inc/roles.php'))   { require_once __DIR__ . '/inc/roles.php'; }

// 1.2 ÖZELLİK MODÜLLERİ — kancaları burada tanımlanır (reklam sayacı, okunma
// kaydı, ödeme duvarı). Yüklenmezlerse kancalar sessizce hiç çalışmaz.
// Liste inc/bootstrap.php'de TEK yerde durur; dört giriş noktası da onu okur.
// Modüller OTURUM AÇMAZ — ön yüz çerez kuralı (CONTRACTS §3.1) korunur.
manset_load_feature_modules();

// SEO ÖN YÜZ KAPISI (1.2): elle tanımlı 301/302/410 kuralları ve IndexNow
// anahtar dosyası. Yönlendirme çözülmeden ve sayfa önbelleği okunmadan ÖNCE
// çalışmalı — yoksa taşınmış bir adres için önbellekten eski sayfa sunulur.
//
// Çağrı BURADA, açıkça yapılıyor. Ajan-E bunu geçici olarak inc/seo.php'nin
// YÜKLENME anına bağlamıştı (o dosya kendisinin, index.php değil). Yükleme
// anında yan etkisi olan bir modül, aynı dosyayı require eden api.php/cron.php/
// admin panelinde de sessizce çalışır; bugün bir koruma bunu engelliyor olsa
// bile o koruma tek bir yeniden düzenlemeyle kaybolur. Kapı yalnız burada.
if (function_exists('seo_front_gate')) { seo_front_gate(); }

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
        // ÖLÇÜLÜ ÖDEME DUVARI (1.2-05): karar ÇIKTIDAN ÖNCE verilmeli.
        // Sayaç çerezi ve `private, no-store` başlığı yalnız burada yazılabilir;
        // şablonun içinde headers_sent() true olur, başlık sessizce düşer ve
        // karar kilide döner — okur hakkı olduğu halde duvara çarpar.
        if (function_exists('paywall_front_gate')) { paywall_front_gate($post); }
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
        // Çöp kutusundaki ve yayımlanmamış haberlerin etiketleri ADI ÇÖZERKEN de
        // görünmemeli (denetim turu 3, B11): silinen bir haberin özel etiketi
        // etiket sayfasının başlığında sızıyordu.
        $etiketSorgu = 'SELECT tags FROM posts p WHERE p.tags <> \'\' AND ' . published_where('p') . ' LIMIT 500';
        foreach (qa($etiketSorgu, [':nowts' => now()]) as $row) {
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
        // 301: bu kısayol KALICI bir adres eşlemesidir. 302, arama motorlarına
        // "hedef geçici, eski adresi indekste tut" der ve besleme URL'si
        // sonsuza dek ikili kalır. Apache'de .htaccess zaten 301 basıyor;
        // burası mod_rewrite kapalı kurulumlar ve nginx/IIS için yedek yol.
        //
        // GÜVENLİK (denetim turu 3, B04): hedef GÖRELİ verilir. base_url() ana adı
        // `HTTP_HOST`'tan türetir ve B-06 koruması yalnız `site_url` ayarı DOLUYKEN
        // devreye girer; ayar boşken zehirli bir Host başlığı ziyaretçiyi saldırganın
        // adresine KALICI olarak (301, tarayıcıda süresiz saklanır) gönderebiliyordu.
        // Göreli Location başlığı RFC 7231'de geçerlidir ve ana adı denklemden çıkarır.
        header('Location: ' . site_path() . '/rss.php', true, 301);
        exit;

    case 'sitemap.xml':
        header('Location: ' . site_path() . '/sitemap.php', true, 301);
        exit;

    // ads.txt reklam ağlarınca site KÖKÜNDEN istenir (IAB kuralı); alt yola
    // taşınamaz. mod_rewrite kapalı kurulumlarda ve nginx/IIS'te bu yedek yol
    // olmadan dosya hiç sunulmaz.
    case 'ads.txt':
        if (is_file(__DIR__ . '/ads.txt.php')) { require __DIR__ . '/ads.txt.php'; exit; }
        break;

    case 'feed.json':
        // feed.php biçim parametresini KENDİSİ kurar; buradan bir şey geçirilmez.
        if (is_file(__DIR__ . '/feed.php')) { require __DIR__ . '/feed.php'; exit; }
        break;

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
