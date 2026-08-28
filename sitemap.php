<?php
/**
 * Manşet — site haritası.
 *
 * Giriş noktasıdır: çekirdeği kendisi yükler.
 *
 * Adresler:
 *   /sitemap.php                      → site haritası dizini (sitemapindex)
 *   /sitemap.php?tur=haber&sayfa=N    → haberler (sayfa başına en çok 1000)
 *   /sitemap.php?tur=kategori         → kategoriler
 *   /sitemap.php?tur=sayfa            → sabit sayfalar + künye
 *   /sitemap.php?tur=etiket           → etiketler (en çok 500)
 *   /sitemap.php?tur=haberler         → Google News haritası (son 48 saat, en çok 1000)
 *
 * Bellek: hiçbir harita tümüyle bellekte kurulmaz; satırlar LIMIT/OFFSET ile
 * öbek öbek çekilip doğrudan çıkışa yazılır.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kurulum tamamlanmamış.\n";
    exit;
}

require_once __DIR__ . '/inc/view.php';
if (is_file(__DIR__ . '/inc/seo.php')) { require_once __DIR__ . '/inc/seo.php'; }

// ============================================================ ayarlar

/** Bir haber haritasındaki en çok URL sayısı. */
function sitemap_page_size() { return 1000; }
/** Veritabanından tek seferde çekilecek satır sayısı (akış öbeği). */
function sitemap_chunk_size() { return 200; }
/** Etiket haritasındaki en çok etiket sayısı. */
function sitemap_tag_limit() { return 500; }
/** Google News haritasındaki en çok haber sayısı. */
function sitemap_news_limit() { return 1000; }

/**
 * Besleme/harita önbellek süresi (saniye).
 * Ajan-10'un önbellek modülü gelene kadar yalnız HTTP başlığı olarak kullanılır.
 */
function sitemap_ttl() {
    $ttl = (int)setting('seo_feed_ttl', '900');
    return max(0, min(86400, $ttl));
}

// ============================================================ çıkış yardımcıları

/** XML metin kaçışı (denetim karakterleri atılır). */
function sitemap_x($s) {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** Akışa yazar ve tamponu boşaltır (bellekte biriktirmemek için). */
function sitemap_out($s) {
    echo $s;
    if (ob_get_level() === 0) { @flush(); }
}

/** Ortak yanıt başlıkları. */
function sitemap_headers($contentType = 'application/xml; charset=utf-8') {
    if (headers_sent()) { return; }
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');           // haritanın kendisi indekslenmesin
    header('Cache-Control: public, max-age=' . sitemap_ttl());
}

/** Geçersiz tür / sayfa → 404. */
function sitemap_404($message = 'Böyle bir site haritası yok.') {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
    }
    echo $message . "\n";
    exit;
}

/** sitemap.php üzerine kurulu alt harita adresi. */
function sitemap_self_url(array $query = []) {
    $url = base_url() . '/sitemap.php';
    if ($query) { $url .= '?' . http_build_query($query); }
    return $url;
}

/** Tek <url> girdisi basar. */
function sitemap_url_node($loc, $lastmod = '', $changefreq = '', $priority = '') {
    $s = '<url><loc>' . sitemap_x($loc) . '</loc>';
    if ($lastmod !== '')    { $s .= '<lastmod>' . sitemap_x($lastmod) . '</lastmod>'; }
    if ($changefreq !== '') { $s .= '<changefreq>' . sitemap_x($changefreq) . '</changefreq>'; }
    if ($priority !== '')   { $s .= '<priority>' . sitemap_x($priority) . '</priority>'; }
    return $s . '</url>' . "\n";
}

/**
 * Haberin görsel düğümü (`image:image`).
 *
 * Google Görseller ve Keşfet için haber haritasındaki tek güçlü sinyal budur;
 * 1.1'e kadar hiç basılmıyordu. Haber başına YALNIZ ÖNE ÇIKAN görsel yazılır:
 * gövdedeki görselleri de eklemek harita boyutunu birkaç katına çıkarır ve
 * Google zaten sayfayı taradığında onları görür.
 *
 * @return string boş görselde boş dize
 */
function sitemap_image_node($row) {
    if (!function_exists('seo_sitemap_images_enabled') || !seo_sitemap_images_enabled()) { return ''; }
    if (trim((string)arr($row, 'image', '')) === '') { return ''; }
    $url = post_image($row, 'large');
    if ($url === '') { return ''; }
    $s = '<image:image><image:loc>' . sitemap_x($url) . '</image:loc>';
    $baslik = trim((string)arr($row, 'title', ''));
    if ($baslik !== '') { $s .= '<image:title>' . sitemap_x($baslik) . '</image:title>'; }
    return $s . '</image:image>';
}

/** 'Y-m-d H:i:s' → W3C tarih biçimi. */
function sitemap_date($dt) {
    if (!$dt) { return ''; }
    $ts = strtotime((string)$dt);
    return $ts ? date('c', $ts) : '';
}

/** Haberin yaşına göre changefreq + priority. */
function sitemap_freshness($publishedAt) {
    $ts = $publishedAt ? strtotime((string)$publishedAt) : 0;
    $age = $ts ? (time() - $ts) : PHP_INT_MAX;
    if ($age < 2 * 86400)  { return ['hourly',  '0.9']; }
    if ($age < 7 * 86400)  { return ['daily',   '0.8']; }
    if ($age < 30 * 86400) { return ['weekly',  '0.6']; }
    return ['monthly', '0.5'];
}

// ============================================================ sorgu yardımcıları

/** Yayında olan haberler için WHERE parçası (inc/view.php ile aynı ölçüt). */
function sitemap_published_where() { return published_where('p'); }

/** Yayındaki haber sayısı. */
function sitemap_post_count() {
    return (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . sitemap_published_where(), [':nowts' => now()], 0);
}

/** Yayındaki haberlerin en son güncelleme zamanı. */
function sitemap_post_lastmod() {
    return (string)qv('SELECT MAX(p.updated_at) FROM posts p WHERE ' . sitemap_published_where(),
        [':nowts' => now()], '');
}

// ============================================================ harita dizini

function sitemap_render_index() {
    sitemap_headers();
    $total = sitemap_post_count();
    $pages = max(1, (int)ceil($total / sitemap_page_size()));
    $postsMod = sitemap_date(sitemap_post_lastmod());
    $pagesMod = sitemap_date((string)qv('SELECT MAX(updated_at) FROM pages', [], ''));

    sitemap_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

    $node = function ($query, $lastmod) {
        $s = '<sitemap><loc>' . sitemap_x(sitemap_self_url($query)) . '</loc>';
        if ($lastmod !== '') { $s .= '<lastmod>' . sitemap_x($lastmod) . '</lastmod>'; }
        return $s . '</sitemap>' . "\n";
    };

    for ($i = 1; $i <= $pages; $i++) {
        sitemap_out($node(['tur' => 'haber', 'sayfa' => $i], $postsMod));
    }
    sitemap_out($node(['tur' => 'kategori'], $postsMod));
    sitemap_out($node(['tur' => 'sayfa'], $pagesMod));
    sitemap_out($node(['tur' => 'etiket'], $postsMod));
    sitemap_out($node(['tur' => 'haberler'], $postsMod));

    sitemap_out('</sitemapindex>' . "\n");
}

// ============================================================ haber haritası

function sitemap_render_posts($page) {
    $page = max(1, (int)$page);
    $total = sitemap_post_count();
    $offset = ($page - 1) * sitemap_page_size();
    if ($page > 1 && $offset >= $total) { sitemap_404('Bu sayfada haber yok.'); }

    sitemap_headers();
    sitemap_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
        . '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n");

    $remaining = min(sitemap_page_size(), max(0, $total - $offset));
    $chunk = sitemap_chunk_size();
    $where = sitemap_published_where();

    while ($remaining > 0) {
        $take = min($chunk, $remaining);
        // id sırası kararlıdır: LIMIT/OFFSET sayfalamasında satır kaymaz.
        $rows = qa('SELECT p.id, p.slug, p.title, p.image, p.published_at, p.updated_at
                    FROM posts p WHERE ' . $where . '
                    ORDER BY p.id ASC LIMIT ' . (int)$take . ' OFFSET ' . (int)$offset,
            [':nowts' => now()]);
        if (!$rows) { break; }
        foreach ($rows as $r) {
            list($freq, $prio) = sitemap_freshness(arr($r, 'published_at', ''));
            $lastmod = sitemap_date(arr($r, 'updated_at', '') ?: arr($r, 'published_at', ''));
            $node = sitemap_url_node(url_post($r), $lastmod, $freq, $prio);
            $img = sitemap_image_node($r);
            // Görsel düğümü </url>'den ÖNCE girmeli (şema sırası).
            if ($img !== '') { $node = str_replace('</url>', $img . '</url>', $node); }
            sitemap_out($node);
        }
        $got = count($rows);
        $offset += $got;
        $remaining -= $got;
        unset($rows);
        if ($got < $take) { break; }
    }

    sitemap_out('</urlset>' . "\n");
}

// ============================================================ kategori haritası

function sitemap_render_categories() {
    sitemap_headers();
    sitemap_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

    foreach (all_categories(true) as $cat) {
        $lastmod = sitemap_date((string)qv(
            'SELECT MAX(p.published_at) FROM posts p WHERE p.category_id = :c AND ' . sitemap_published_where(),
            [':c' => (int)$cat['id'], ':nowts' => now()], ''));
        sitemap_out(sitemap_url_node(url_category($cat), $lastmod, 'hourly', '0.7'));
    }

    sitemap_out('</urlset>' . "\n");
}

// ============================================================ sabit sayfa haritası

function sitemap_render_pages() {
    sitemap_headers();
    sitemap_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

    // Anasayfa
    sitemap_out(sitemap_url_node(base_url() . '/', sitemap_date(sitemap_post_lastmod()), 'hourly', '1.0'));

    $offset = 0;
    $chunk = sitemap_chunk_size();
    while (true) {
        $rows = qa('SELECT id, slug, updated_at, created_at FROM pages
                    ORDER BY id ASC LIMIT ' . $chunk . ' OFFSET ' . (int)$offset);
        if (!$rows) { break; }
        foreach ($rows as $r) {
            $lastmod = sitemap_date(arr($r, 'updated_at', '') ?: arr($r, 'created_at', ''));
            sitemap_out(sitemap_url_node(url_page($r), $lastmod, 'monthly', '0.4'));
        }
        $offset += count($rows);
        if (count($rows) < $chunk) { break; }
        unset($rows);
    }

    // Künye (BİK gereği ana sayfadan doğrudan ulaşılabilir sabit adres)
    sitemap_out(sitemap_url_node(url('kunye'), '', 'monthly', '0.5'));

    sitemap_out('</urlset>' . "\n");
}

// ============================================================ etiket haritası

function sitemap_render_tags() {
    sitemap_headers();
    sitemap_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");

    $seen = [];          // slug => true (bellek sınırı: en çok 500 kayıt)
    $limit = sitemap_tag_limit();
    $offset = 0;
    $chunk = 500;        // etiket taraması yalnız tek sütun okur, öbek büyük olabilir
    $scanned = 0;
    $maxScan = 10000;    // 10 bin+ haberde taramayı sınırla (en yeniden geriye)
    $where = sitemap_published_where();

    while (count($seen) < $limit && $scanned < $maxScan) {
        $rows = qa('SELECT p.tags FROM posts p WHERE ' . $where . ' AND p.tags <> \'\'
                    ORDER BY p.id DESC LIMIT ' . $chunk . ' OFFSET ' . (int)$offset,
            [':nowts' => now()]);
        if (!$rows) { break; }
        foreach ($rows as $r) {
            foreach (tags_to_array($r['tags']) as $tag) {
                $slug = slugify($tag);
                if ($slug === '' || isset($seen[$slug])) { continue; }
                $seen[$slug] = true;
                sitemap_out(sitemap_url_node(url_tag($tag), '', 'weekly', '0.3'));
                if (count($seen) >= $limit) { break 2; }
            }
        }
        $scanned += count($rows);
        $offset += count($rows);
        if (count($rows) < $chunk) { break; }
        unset($rows);
    }

    sitemap_out('</urlset>' . "\n");
}

// ============================================================ Google News haritası

function sitemap_render_news() {
    sitemap_headers();
    sitemap_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
        . '        xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"' . "\n"
        . '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n");

    $siteName = sitemap_x((string)site('title'));
    $since = date('Y-m-d H:i:s', time() - 48 * 3600);
    $where = sitemap_published_where();
    $limit = sitemap_news_limit();
    $chunk = sitemap_chunk_size();
    $offset = 0;
    $left = $limit;

    while ($left > 0) {
        $take = min($chunk, $left);
        $rows = qa('SELECT p.id, p.slug, p.title, p.image, p.published_at
                    FROM posts p WHERE ' . $where . ' AND p.published_at >= :since
                    ORDER BY p.published_at DESC, p.id DESC LIMIT ' . (int)$take . ' OFFSET ' . (int)$offset,
            [':nowts' => now(), ':since' => $since]);
        if (!$rows) { break; }
        foreach ($rows as $r) {
            $date = sitemap_date(arr($r, 'published_at', ''));
            sitemap_out('<url><loc>' . sitemap_x(url_post($r)) . '</loc>'
                . '<news:news>'
                . '<news:publication><news:name>' . $siteName . '</news:name>'
                . '<news:language>tr</news:language></news:publication>'
                . ($date !== '' ? '<news:publication_date>' . sitemap_x($date) . '</news:publication_date>' : '')
                . '<news:title>' . sitemap_x((string)arr($r, 'title', '')) . '</news:title>'
                . '</news:news>' . sitemap_image_node($r) . '</url>' . "\n");
        }
        $got = count($rows);
        $offset += $got;
        $left -= $got;
        unset($rows);
        if ($got < $take) { break; }
    }

    sitemap_out('</urlset>' . "\n");
}

// ============================================================ yönlendirme

$tur = isset($_GET['tur']) ? strtolower(trim((string)$_GET['tur'])) : '';
$sayfa = max(1, (int)(isset($_GET['sayfa']) ? $_GET['sayfa'] : 1));

switch ($tur) {
    case '':
        // Slug geçmişindeki eksik kayıtlar burada tamamlanır (ayarla kapatılabilir).
        // posts.save ucuna kanca konamadığı için tek fırsat noktası budur.
        // Varsayılan KAPALI: bu bir GET ucudur ve her arama motoru ziyaretinde yazma
        // tetiklerdi (10k haberde ~340 ms). Eşitlemeyi cron.php üstlenir
        // (cron_task('slug', ...)); cron kurulamayan sunucularda seo_slug_sync=1 ile açılır.
        if (function_exists('seo_sync_slug_history') && setting('seo_slug_sync', '0') === '1') {
            try { seo_sync_slug_history(200); } catch (Throwable $e) { }
        }
        sitemap_render_index();
        break;
    case 'haber':
        sitemap_render_posts($sayfa);
        break;
    case 'kategori':
        sitemap_render_categories();
        break;
    case 'sayfa':
        sitemap_render_pages();
        break;
    case 'etiket':
        sitemap_render_tags();
        break;
    case 'haberler':
        sitemap_render_news();
        break;
    default:
        sitemap_404();
}
