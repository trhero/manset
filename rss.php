<?php
/**
 * Manşet — RSS 2.0 / Atom 1.0 beslemesi.
 *
 * Giriş noktasıdır: çekirdeği kendisi yükler.
 *
 * Adresler:
 *   /rss.php                        → site geneli RSS 2.0 (son 30 haber)
 *   /rss.php?kategori=<slug>        → yalnız o kategori
 *   /rss.php?etiket=<slug>          → yalnız o etiket
 *   /rss.php?yazar=<id>             → yalnız o yazar (personel hesaplar)
 *   /rss.php?tur=atom               → aynı içeriğin Atom 1.0 karşılığı
 *   /rss.php?tur=json               → JSON Feed 1.1 (aynı içerik) · /feed.php kısayolu
 *   /rss.php?adet=N                 → öğe sayısı (1-50, varsayılan 30)
 *
 * Ayarlar (Panel → Ayarlar → SEO ve dağıtım):
 *   seo_feed_full  Tam metin mi özet mi · seo_feed_items · seo_feed_ttl · seo_websub_hub
 *
 * Not: bu dosya inc/rss.php (RSS TOPLAYICI, Ajan-4) ile ilgisizdir; oradaki tüm
 * fonksiyonlar rss_*, buradakiler feed_* önekiyle adlandırılmıştır.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Kurulum tamamlanmamış.\n";
    exit;
}

require_once __DIR__ . '/inc/view.php';

// ============================================================ ayarlar

/** Beslemedeki varsayılan öğe sayısı. */
function feed_default_count() {
    $n = (int)setting('seo_feed_items', '30');
    return max(1, min(50, $n > 0 ? $n : 30));
}

/** Besleme önbellek süresi (saniye) — Ajan-10 modülü gelene kadar yalnız HTTP başlığı. */
function feed_ttl() {
    return max(0, min(86400, (int)setting('seo_feed_ttl', '900')));
}

/**
 * WebSub hub adresi. inc/seo.php burada YÜKLENMEZ (besleme kendi başına ayakta
 * durmalı), bu yüzden ayar doğrudan okunur — seo_websub_hub() ile aynı kural.
 */
function feed_hub() {
    $u = trim((string)setting('seo_websub_hub', ''));
    if ($u === '' || !preg_match('#^https?://[^\s<>"\']+$#i', $u)) { return ''; }
    return $u;
}

/**
 * Etiket slug'ından görünen etiket adını bulur (etiketler serbest metindir,
 * kendi tabloları yoktur). Yalnız YAYINDAKİ haberlerin etiketleri taranır:
 * çöp kutusundaki bir haberin özel etiketi beslemenin başlığında sızmasın.
 *
 * @return string bulunamazsa boş dize
 */
function feed_tag_label($slug) {
    $slug = slugify((string)$slug);
    if ($slug === '') { return ''; }
    $rows = qa('SELECT p.tags FROM posts p WHERE p.tags <> \'\' AND ' . published_where('p') . ' LIMIT 1000',
        [':nowts' => now()]);
    foreach ($rows as $row) {
        foreach (tags_to_array($row['tags']) as $t) {
            if (slugify($t) === $slug) { return (string)$t; }
        }
    }
    return '';
}

// ============================================================ XML güvenliği

/** Geçersiz UTF-8 baytlarını ve XML'de yasak denetim karakterlerini atar. */
function feed_utf8_clean($s) {
    $s = (string)$s;
    if ($s === '') { return ''; }
    if (function_exists('mb_convert_encoding')) {
        $s = (string)@mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    } elseif (preg_match('//u', $s) !== 1) {
        // /u kipi başarısızsa dize bozuk demektir; ASCII dışını at
        $s = (string)preg_replace('/[\x80-\xFF]/', '', $s);
    }
    // XML 1.0'da yasak olan denetim karakterleri (tab, LF, CR hariç)
    return (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
}

/** XML metin kaçışı. */
function feed_x($s) {
    return htmlspecialchars(feed_utf8_clean($s), ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

/** CDATA bloğu; içerideki ']]>' dizisi bölünür. */
function feed_cdata($s) {
    $s = feed_utf8_clean($s);
    if ($s === '') { return ''; }
    $s = str_replace(']]>', ']]]]><![CDATA[>', $s);
    return '<![CDATA[' . $s . ']]>';
}

/** Akışa yazar. */
function feed_out($s) {
    echo $s;
    if (ob_get_level() === 0) { @flush(); }
}

/** Ortak yanıt başlıkları. */
function feed_headers($contentType) {
    if (headers_sent()) { return; }
    header('Content-Type: ' . $contentType);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=' . feed_ttl());
}

/** Geçersiz kategori / tür → 404. */
function feed_404($message = 'Böyle bir besleme yok.') {
    if (!headers_sent()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
    }
    echo $message . "\n";
    exit;
}

// ============================================================ veri

/**
 * Besleme kapsamı — kategori / etiket / yazar ya da site geneli.
 * @return array ['kind'=>'site|category|tag|author', 'category'=>?array, 'tag'=>string, 'author'=>?array]
 */
function feed_scope($category = null, $tag = '', $author = null) {
    if (is_array($category)) { return ['kind' => 'category', 'category' => $category, 'tag' => '', 'author' => null]; }
    if ((string)$tag !== '')  { return ['kind' => 'tag', 'category' => null, 'tag' => (string)$tag, 'author' => null]; }
    if (is_array($author))    { return ['kind' => 'author', 'category' => null, 'tag' => '', 'author' => $author]; }
    return ['kind' => 'site', 'category' => null, 'tag' => '', 'author' => null];
}

/** Beslemede gösterilecek haberler (gövde dâhil). */
function feed_posts(array $scope, $limit) {
    $limit = max(1, min(50, (int)$limit));
    $params = [':nowts' => now()];
    $extra = '';
    if ($scope['kind'] === 'category') {
        $extra = ' AND p.category_id = :cid';
        $params[':cid'] = (int)$scope['category']['id'];
    } elseif ($scope['kind'] === 'tag') {
        // LIKE ön eleme yapar; kesin eşleşme aşağıda slug karşılaştırmasıyla süzülür
        // (etiket 'spor' araması 'sporcu'yu getirmesin).
        $extra = ' AND p.tags LIKE :tg';
        $params[':tg'] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$scope['tag']) . '%';
        $limit = min(50, $limit * 3);
    } elseif ($scope['kind'] === 'author') {
        $extra = ' AND p.author_id = :aid';
        $params[':aid'] = (int)$scope['author']['id'];
    }
    // visibility/teaser GEREKLİ: kilitli haberlerin gövdesi beslemeye basılmaz.
    $rows = qa('SELECT p.id, p.title, p.slug, p.spot, p.body, p.image, p.tags,
                      p.visibility, p.teaser,
                      p.published_at, p.updated_at, p.created_at,
                      c.name AS category_name, c.slug AS category_slug,
                      u.name AS author_name
               FROM posts p
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN users u ON u.id = p.author_id
               WHERE ' . published_where('p') . $extra . '
               ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $limit, $params);

    if ($scope['kind'] !== 'tag') { return $rows; }

    $ara = slugify((string)$scope['tag']);
    $out = [];
    foreach ($rows as $r) {
        foreach (tags_to_array(arr($r, 'tags', '')) as $t) {
            if (slugify($t) === $ara) { $out[] = $r; break; }
        }
    }
    return $out;
}

/**
 * Haber beslemeye tam gövdeyle girebilir mi?
 *
 * GÜVENLİK (denetim tur 2 — iki denetçi bağımsız buldu): besleme oturumsuz ve
 * anonimdir; `members`/`premium` haberlerin gövdesi buraya ASLA basılmaz, yoksa
 * `curl site/rss.php` tek satırda ödeme duvarını devre dışı bırakır.
 * Başlık ve bağlantı gizli değildir — haber keşfi için beslemede kalır.
 */
function feed_post_is_open($p) {
    $g = trim((string)arr($p, 'visibility', 'public'));
    return ($g === '' || $g === 'public');
}

/**
 * Beslemeye basılacak gövde — kilitliyse boş.
 *
 * `seo_feed_full` KAPALIYSA tam metin hiç basılmaz; okur ve toplayıcı yalnız
 * özet görür. Ayarın varsayılanı AÇIK (1.1 davranışı korunur), ama içerik
 * kazıyıcılardan şikâyetçi yayıncı bunu panelden kapatabilir.
 */
function feed_full_text() {
    return setting('seo_feed_full', '1') !== '0';
}

/** Beslemeye basılacak gövde — kilitliyse ya da tam metin kapalıysa boş. */
function feed_body($p) {
    if (!feed_full_text()) { return ''; }
    if (!feed_post_is_open($p)) { return ''; }
    return sanitize_html((string)arr($p, 'body', ''), false);
}

/** Beslemeye basılacak özet — kilitli haberde gövdeden ASLA türetilmez. */
function feed_spot($p) {
    $spot = trim((string)arr($p, 'spot', ''));
    if ($spot !== '') { return $spot; }
    if (!feed_post_is_open($p)) {
        // Kilitli haberde yalnız yayıncının kendi yazdığı önizleme kullanılır.
        return trim((string)arr($p, 'teaser', ''));
    }
    return excerpt((string)arr($p, 'body', ''), 220);
}

/** Dosya uzantısından MIME türü. */
function feed_mime_from_name($name) {
    $ext = strtolower((string)pathinfo((string)$name, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'svg' => 'image/svg+xml', 'bmp' => 'image/bmp',
    ];
    return isset($map[$ext]) ? $map[$ext] : '';
}

/**
 * enclosure için görsel bilgisi.
 * @return array ['url'=>string,'mime'=>string,'length'=>int] ya da []
 */
function feed_image_info($post) {
    $url = post_image($post, 'large');
    if ($url === '') { return []; }
    $mime = feed_mime_from_name($url);
    $length = 0;

    $prefix = base_url() . '/uploads/';
    if (strpos($url, $prefix) === 0) {
        $rel = str_replace('\\', '/', rawurldecode(substr($url, strlen($prefix))));
        if ($rel !== '' && strpos($rel, '..') === false) {
            $abs = UPLOAD_DIR . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                $length = (int)@filesize($abs);
                $info = @getimagesize($abs);
                if (is_array($info) && !empty($info['mime'])) { $mime = (string)$info['mime']; }
            }
        }
    }
    if ($mime === '') { $mime = 'image/jpeg'; }
    return ['url' => $url, 'mime' => $mime, 'length' => max(0, $length)];
}

/** Haberin yayın zamanı (yoksa oluşturulma zamanı). */
function feed_post_time($post) {
    $raw = (string)(arr($post, 'published_at', '') ?: arr($post, 'created_at', ''));
    $ts = $raw !== '' ? strtotime($raw) : 0;
    return $ts ? $ts : time();
}

/** Haberin son değişiklik zamanı. */
function feed_post_modified($post) {
    $raw = (string)(arr($post, 'updated_at', '') ?: arr($post, 'published_at', ''));
    $ts = $raw !== '' ? strtotime($raw) : 0;
    return $ts ? $ts : feed_post_time($post);
}

/** Kapsamın sorgu parametreleri. */
function feed_scope_query(array $scope) {
    if ($scope['kind'] === 'category') { return ['kategori' => (string)$scope['category']['slug']]; }
    if ($scope['kind'] === 'tag')      { return ['etiket' => slugify((string)$scope['tag'])]; }
    if ($scope['kind'] === 'author')   { return ['yazar' => (int)$scope['author']['id']]; }
    return [];
}

/** Beslemenin kendi adresi (rel="self"). */
function feed_self_url(array $scope, $type) {
    $q = feed_scope_query($scope);
    if ($type !== 'rss') { $q['tur'] = $type; }
    return base_url() . '/rss.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Beslemenin insan tarafı adresi. */
function feed_site_url(array $scope) {
    if ($scope['kind'] === 'category') { return url_category($scope['category']); }
    if ($scope['kind'] === 'tag')      { return url_tag((string)$scope['tag']); }
    return base_url() . '/';
}

/** Besleme başlığı. */
function feed_title(array $scope) {
    $site = (string)site('title');
    switch ($scope['kind']) {
        case 'category': return $site . ' — ' . (string)$scope['category']['name'];
        case 'tag':      return $site . ' — ' . (string)$scope['tag'] . ' etiketi';
        case 'author':   return $site . ' — ' . (string)$scope['author']['name'];
        default:         return $site;
    }
}

/** Besleme açıklaması. */
function feed_description(array $scope) {
    $site = (string)site('title');
    switch ($scope['kind']) {
        case 'category':
            return (string)$scope['category']['name'] . ' kategorisindeki son haberler — ' . $site;
        case 'tag':
            return (string)$scope['tag'] . ' etiketiyle ilişkili son haberler — ' . $site;
        case 'author':
            return (string)$scope['author']['name'] . ' imzalı son haberler — ' . $site;
    }
    $d = trim((string)site('desc'));
    if ($d === '') { $d = trim((string)site('slogan')); }
    if ($d === '') { $d = $site . ' haber beslemesi'; }
    return $d;
}

// ============================================================ RSS 2.0

function feed_render_rss(array $posts, array $scope, $selfUrl) {
    feed_headers('application/rss+xml; charset=utf-8');

    $build = $posts ? feed_post_modified($posts[0]) : time();
    $logo = (string)site('logo');

    feed_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0"' . "\n"
        . '     xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n"
        . '     xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n"
        . '     xmlns:atom="http://www.w3.org/2005/Atom">' . "\n"
        . '<channel>' . "\n"
        . '<title>' . feed_x(feed_title($scope)) . '</title>' . "\n"
        . '<link>' . feed_x(feed_site_url($scope)) . '</link>' . "\n"
        . '<description>' . feed_x(feed_description($scope)) . '</description>' . "\n"
        . '<language>tr-TR</language>' . "\n"
        . '<lastBuildDate>' . feed_x(date('r', $build)) . '</lastBuildDate>' . "\n"
        . '<generator>' . feed_x('Manşet ' . MANSET_VERSION) . '</generator>' . "\n"
        . '<atom:link href="' . feed_x($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n");

    // WebSub: toplayıcı hub'ı buradan öğrenir, bildirim cron turunda gönderilir.
    $hub = feed_hub();
    if ($hub !== '') { feed_out('<atom:link rel="hub" href="' . feed_x($hub) . '"/>' . "\n"); }

    if ($logo !== '') {
        feed_out('<image><url>' . feed_x($logo) . '</url>'
            . '<title>' . feed_x(feed_title($scope)) . '</title>'
            . '<link>' . feed_x(feed_site_url($scope)) . '</link></image>' . "\n");
    }

    foreach ($posts as $p) {
        $url = url_post($p);
        $spot = feed_spot($p);
        $body = feed_body($p);
        $author = trim((string)arr($p, 'author_name', ''));
        $catName = trim((string)arr($p, 'category_name', ''));

        feed_out('<item>' . "\n"
            . '<title>' . feed_x(arr($p, 'title', '')) . '</title>' . "\n"
            . '<link>' . feed_x($url) . '</link>' . "\n"
            . '<guid isPermaLink="true">' . feed_x($url) . '</guid>' . "\n"
            . '<pubDate>' . feed_x(date('r', feed_post_time($p))) . '</pubDate>' . "\n");

        if ($catName !== '') { feed_out('<category>' . feed_x($catName) . '</category>' . "\n"); }
        if ($author !== '')  { feed_out('<dc:creator>' . feed_cdata($author) . '</dc:creator>' . "\n"); }
        if ($spot !== '')    { feed_out('<description>' . feed_cdata($spot) . '</description>' . "\n"); }
        if ($body !== '')    { feed_out('<content:encoded>' . feed_cdata($body) . '</content:encoded>' . "\n"); }

        $img = feed_image_info($p);
        if ($img) {
            feed_out('<enclosure url="' . feed_x($img['url']) . '" length="' . (int)$img['length']
                . '" type="' . feed_x($img['mime']) . '"/>' . "\n");
        }
        feed_out('</item>' . "\n");
    }

    feed_out('</channel>' . "\n" . '</rss>' . "\n");
}

// ============================================================ Atom 1.0

function feed_render_atom(array $posts, array $scope, $selfUrl) {
    feed_headers('application/atom+xml; charset=utf-8');

    $build = $posts ? feed_post_modified($posts[0]) : time();
    $logo = (string)site('logo');

    feed_out('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="tr-TR">' . "\n"
        . '<id>' . feed_x($selfUrl) . '</id>' . "\n"
        . '<title>' . feed_x(feed_title($scope)) . '</title>' . "\n"
        . '<subtitle>' . feed_x(feed_description($scope)) . '</subtitle>' . "\n"
        . '<updated>' . feed_x(date('c', $build)) . '</updated>' . "\n"
        . '<generator version="' . feed_x(MANSET_VERSION) . '">Manşet</generator>' . "\n"
        . '<link rel="self" type="application/atom+xml" href="' . feed_x($selfUrl) . '"/>' . "\n"
        . '<link rel="alternate" type="text/html" href="' . feed_x(feed_site_url($scope)) . '"/>' . "\n"
        . '<author><name>' . feed_x(site('title')) . '</name></author>' . "\n");

    $hub = feed_hub();
    if ($hub !== '') { feed_out('<link rel="hub" href="' . feed_x($hub) . '"/>' . "\n"); }

    if ($logo !== '') { feed_out('<logo>' . feed_x($logo) . '</logo>' . "\n"); }

    foreach ($posts as $p) {
        $url = url_post($p);
        $spot = feed_spot($p);
        $body = feed_body($p);
        $author = trim((string)arr($p, 'author_name', ''));
        if ($author === '') { $author = (string)site('title'); }
        $catName = trim((string)arr($p, 'category_name', ''));
        $catSlug = trim((string)arr($p, 'category_slug', ''));

        feed_out('<entry>' . "\n"
            . '<id>' . feed_x($url) . '</id>' . "\n"
            . '<title>' . feed_x(arr($p, 'title', '')) . '</title>' . "\n"
            . '<link rel="alternate" type="text/html" href="' . feed_x($url) . '"/>' . "\n"
            . '<published>' . feed_x(date('c', feed_post_time($p))) . '</published>' . "\n"
            . '<updated>' . feed_x(date('c', feed_post_modified($p))) . '</updated>' . "\n"
            . '<author><name>' . feed_x($author) . '</name></author>' . "\n");

        if ($catName !== '') {
            feed_out('<category term="' . feed_x($catSlug !== '' ? $catSlug : $catName)
                . '" label="' . feed_x($catName) . '"/>' . "\n");
        }
        if ($spot !== '') { feed_out('<summary type="text">' . feed_x($spot) . '</summary>' . "\n"); }
        if ($body !== '') { feed_out('<content type="html">' . feed_cdata($body) . '</content>' . "\n"); }

        $img = feed_image_info($p);
        if ($img) {
            feed_out('<link rel="enclosure" type="' . feed_x($img['mime']) . '" length="'
                . (int)$img['length'] . '" href="' . feed_x($img['url']) . '"/>' . "\n");
        }
        feed_out('</entry>' . "\n");
    }

    feed_out('</feed>' . "\n");
}

// ============================================================ JSON Feed 1.1

/**
 * JSON Feed 1.1 (https://jsonfeed.org/version/1.1).
 *
 * NEDEN: RSS/Atom okuyamayan ama JSON tüketen istemciler (mobil uygulama,
 * Slack/Discord köprüleri, statik site oluşturucular) için tek dosyalık karşılık.
 * XML gibi elle üretilmez — json_encode kaçışları kendisi yapar.
 */
function feed_render_json(array $posts, array $scope, $selfUrl) {
    feed_headers('application/feed+json; charset=utf-8');

    $doc = [
        'version'       => 'https://jsonfeed.org/version/1.1',
        'title'         => feed_utf8_clean(feed_title($scope)),
        'home_page_url' => feed_site_url($scope),
        'feed_url'      => $selfUrl,
        'description'   => feed_utf8_clean(feed_description($scope)),
        'language'      => 'tr-TR',
    ];
    $logo = (string)site('logo');
    if ($logo !== '') { $doc['icon'] = $logo; }
    $hub = feed_hub();
    if ($hub !== '') { $doc['hubs'] = [['type' => 'WebSub', 'url' => $hub]]; }

    $items = [];
    foreach ($posts as $p) {
        $url = url_post($p);
        $body = feed_body($p);
        $item = [
            'id'             => $url,
            'url'            => $url,
            'title'          => feed_utf8_clean((string)arr($p, 'title', '')),
            'date_published' => date('c', feed_post_time($p)),
            'date_modified'  => date('c', feed_post_modified($p)),
        ];
        $spot = feed_spot($p);
        if ($spot !== '') { $item['summary'] = feed_utf8_clean($spot); }
        if ($body !== '') { $item['content_html'] = feed_utf8_clean($body); }
        else { $item['content_text'] = feed_utf8_clean($spot); }

        $img = feed_image_info($p);
        if ($img) { $item['image'] = $img['url']; }

        $author = trim((string)arr($p, 'author_name', ''));
        if ($author !== '') { $item['authors'] = [['name' => feed_utf8_clean($author)]]; }

        $etiketler = tags_to_array(arr($p, 'tags', ''));
        $catName = trim((string)arr($p, 'category_name', ''));
        if ($catName !== '') { array_unshift($etiketler, $catName); }
        if ($etiketler) { $item['tags'] = array_values(array_unique(array_map('feed_utf8_clean', $etiketler))); }

        $items[] = $item;
    }
    $doc['items'] = $items;

    feed_out((string)json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

// ============================================================ yönlendirme

$tur = isset($_GET['tur']) ? strtolower(trim((string)$_GET['tur'])) : '';
if ($tur === '') { $tur = 'rss'; }
if (!in_array($tur, ['rss', 'atom', 'json'], true)) { feed_404('Geçersiz besleme türü.'); }

// Kapsam: kategori / etiket / yazar — aynı anda yalnız biri.
$catSlug   = isset($_GET['kategori']) ? trim((string)$_GET['kategori']) : '';
$tagSlug   = isset($_GET['etiket']) ? trim((string)$_GET['etiket']) : '';
$authorId  = isset($_GET['yazar']) ? (int)$_GET['yazar'] : 0;

$category = null;
$tagLabel = '';
$author = null;

if ($catSlug !== '') {
    $category = category_by_slug($catSlug);
    if (!$category) { feed_404('Kategori bulunamadı.'); }
} elseif ($tagSlug !== '') {
    $tagLabel = feed_tag_label($tagSlug);
    if ($tagLabel === '') { feed_404('Etiket bulunamadı.'); }
} elseif ($authorId > 0) {
    // Yalnız PERSONEL yazarların beslemesi vardır: üye hesapları (member) burada
    // listelenmez, yoksa `?yazar=N` taraması üye adlarını dışarı sızdırırdı.
    $author = q1('SELECT id, name FROM users WHERE id = :i AND active = 1 AND role <> \'member\'',
        [':i' => $authorId]);
    if (!$author) { feed_404('Yazar bulunamadı.'); }
    $yaziSayisi = (int)qv('SELECT COUNT(*) FROM posts p WHERE p.author_id = :a AND ' . published_where('p'),
        [':a' => (int)$author['id'], ':nowts' => now()], 0);
    if ($yaziSayisi === 0) { feed_404('Yazar bulunamadı.'); }
}

$scope = feed_scope($category, $tagLabel, $author);

$count = isset($_GET['adet']) ? (int)$_GET['adet'] : feed_default_count();
$count = max(1, min(50, $count > 0 ? $count : feed_default_count()));

$posts = feed_posts($scope, $count);
$self = feed_self_url($scope, $tur);

if ($tur === 'atom')      { feed_render_atom($posts, $scope, $self); }
elseif ($tur === 'json')  { feed_render_json($posts, $scope, $self); }
else                      { feed_render_rss($posts, $scope, $self); }
