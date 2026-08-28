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

/**
 * Başlık şablonlarının varsayılanları (panelde `seo_title_tpl_*` ile ezilir).
 *
 * Yer tutucular — hepsi her şablonda kullanılabilir, karşılığı olmayan
 * yer tutucu çıktıdan SESSİZCE atılır (bkz. seo_apply_template()):
 *
 *   %site%      Site adı
 *   %slogan%    Site sloganı (yoksa açıklamanın ilk cümlesi)
 *   %baslik%    Haberin / sabit sayfanın başlığı
 *   %kategori%  Kategori adı
 *   %etiket%    Etiket adı
 *   %arama%     Arama terimi
 *   %sayfa%     'Sayfa N' (1. sayfada boş)
 *
 * @return array kind => şablon
 */
function seo_title_templates() {
    $var = [
        'home'     => '%site% — %slogan%',
        'post'     => '%baslik% — %site%',
        'category' => '%kategori% Haberleri — %site%',
        'tag'      => '%etiket% Etiketli Haberler — %site%',
        'search'   => '"%arama%" için arama sonuçları — %site%',
    ];
    foreach ($var as $k => $def) {
        $custom = trim((string)setting('seo_title_tpl_' . $k, ''));
        if ($custom !== '') { $var[$k] = $custom; }
    }
    return $var;
}

/**
 * Şablonu doldurur. Boş kalan yer tutucular ve onlardan artan ayraçlar temizlenir:
 * slogansız bir sitede '%site% — %slogan%' şablonu ' — ' ile bitmez.
 */
function seo_apply_template($tpl, array $vars) {
    $map = [];
    foreach ($vars as $k => $val) { $map['%' . $k . '%'] = seo_clean_text($val); }
    $out = strtr((string)$tpl, $map);
    // Tanınmayan yer tutucu (yazım hatası ya da başka şablonun anahtarı) basılmaz.
    $out = (string)preg_replace('/%[a-z_]+%/u', '', $out);
    $out = (string)preg_replace('/\s+/u', ' ', $out);
    // İçi boşalmış tırnak / parantez çifti ÖNCE atılır: ayraç kırpması onların
    // ardındaki ' — ' parçasını ancak bundan sonra görebilir.
    $out = (string)preg_replace('/["“”]\s*["“”]|\(\s*\)|\[\s*\]/u', '', $out);
    // Ardışık ve baştaki/sondaki ayraçlar
    $out = (string)preg_replace('/(\s*[—\-–|·]\s*){2,}/u', ' — ', $out);
    $out = trim($out, " \t\n\r—-–|·");
    return trim((string)preg_replace('/\s+/u', ' ', $out));
}

/** Sayfa başlığı (<title> ve og:title tabanı). */
function seo_title(array $ctx) {
    $v = seo_view($ctx);
    $site = (string)site('title');
    $d = seo_meta_defaults();
    $tpl = seo_title_templates();
    $sayfa = $v['page'] > 1 ? 'Sayfa ' . (int)$v['page'] : '';

    switch ($v['kind']) {
        case 'post':
            $custom = seo_clean_text(arr($v['post'], 'seo_title', ''));
            $title = $custom !== ''
                ? $custom
                : seo_apply_template($tpl['post'], [
                    'baslik' => arr($v['post'], 'title', ''),
                    'site'   => $site,
                    'slogan' => $d['slogan'],
                    'sayfa'  => '',
                  ]);
            break;
        case 'category':
            // Kategorinin kendi SEO başlığı şablonu EZER (019 göçü).
            $custom = seo_clean_text(arr($v['category'], 'seo_title', ''));
            $name = seo_clean_text(arr($v['category'], 'name', ''));
            $title = $custom !== '' ? $custom : seo_apply_template($tpl['category'], [
                'kategori' => $name !== '' ? $name : 'Kategori',
                'baslik'   => $name,
                'site'     => $site,
                'slogan'   => $d['slogan'],
                'sayfa'    => $sayfa,
            ]);
            if ($custom !== '' && $sayfa !== '') { $title .= ' — ' . $sayfa; }
            break;
        case 'tag':
            $term = $v['term'];
            $meta = seo_tag_meta($term);
            $custom = seo_clean_text(arr($meta, 'seo_title', ''));
            $title = $custom !== '' ? $custom : seo_apply_template($tpl['tag'], [
                'etiket' => $term !== '' ? $term : 'Etiket',
                'baslik' => $term,
                'site'   => $site,
                'slogan' => $d['slogan'],
                'sayfa'  => $sayfa,
            ]);
            if ($custom !== '' && $sayfa !== '') { $title .= ' — ' . $sayfa; }
            break;
        case 'search':
            $title = $v['term'] !== ''
                ? seo_apply_template($tpl['search'], [
                    'arama'  => $v['term'],
                    'baslik' => $v['term'],
                    'site'   => $site,
                    'slogan' => $d['slogan'],
                    'sayfa'  => $sayfa,
                  ])
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
            $title = seo_apply_template($tpl['home'], [
                'site'   => $site,
                'slogan' => $d['slogan'],
                'baslik' => $site,
                'sayfa'  => $sayfa,
            ]);
            if ($title === '') { $title = $d['title']; }
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
            // Kategorinin kendi SEO açıklaması (019 göçü) kalıplı metni EZER.
            $text = seo_clean_text(arr($v['category'], 'seo_desc', ''));
            if ($text === '') {
                $name = seo_clean_text(arr($v['category'], 'name', ''));
                $text = ($name !== '' ? $name : 'Bu') . ' kategorisindeki son dakika haberleri, '
                      . 'güncel gelişmeler ve analizler ' . $site . ' sayfasında.';
            }
            break;
        case 'tag':
            $text = seo_clean_text(arr(seo_tag_meta($v['term']), 'seo_desc', ''));
            if ($text === '') {
                $text = ($v['term'] !== '' ? $v['term'] : 'Bu etiket') . ' etiketiyle ilişkili tüm haberler ve '
                      . 'güncel gelişmeler ' . $site . ' arşivinde.';
            }
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
    // Sayfalı listeler: varsayılan noindex (ince içerik). Arşiv keşfi isteyen
    // yayıncı `seo_paged_noindex` ayarını kapatıp 2+ sayfaları indekse açabilir.
    if ($v['page'] > 1 && setting('seo_paged_noindex', '1') !== '0') { return 'noindex,follow'; }
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

/**
 * Sosyal profil adresleri (Organization.sameAs).
 *
 * Kaynak: `seo_same_as` ayarı — satır başına bir adres. Künyede sosyal profil
 * alanı YOKTUR (bkz. kunye_field_defs()), bu yüzden bilgi künyeden okunamaz;
 * kimlik bilgilerinin künyeden geldiği yer aşağıdaki seo_publisher_node().
 *
 * Yalnız http(s) kabul edilir: `javascript:` ya da `data:` bir JSON-LD alanında
 * zararsız görünse de, aynı değer panelde bağlantı olarak basılırsa değildir.
 *
 * @return array benzersiz, en çok 12 adres
 */
function seo_same_as() {
    $raw = (string)setting('seo_same_as', '');
    $raw = str_replace(["\r\n", "\r", ',', ' '], "\n", $raw);
    $out = [];
    foreach (explode("\n", $raw) as $line) {
        $u = trim($line);
        if ($u === '' || !preg_match('#^https?://[^\s<>"\']+$#i', $u)) { continue; }
        if (mb_strlen($u) > 300) { continue; }
        $out[$u] = true;
        if (count($out) >= 12) { break; }
    }
    return array_keys($out);
}

/**
 * Yayıncı (Organization). Logo yoksa logo alanı hiç basılmaz.
 *
 * Kimlik alanları KÜNYEDEN beslenir (inc/kunye.php salt okunur): ticari unvan,
 * adres, telefon, e-posta ve vergi/MERSİS numarası zaten Basın Kanunu m.4 için
 * toplanıyor; ikinci kez sorulması yayıncıyı iki yerde güncellemeye zorlardı.
 */
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

    $unvan = seo_clean_text(setting('kunye_ticaret_unvani', ''));
    if ($unvan !== '') { $node['legalName'] = $unvan; }

    $tel = seo_clean_text(setting('kunye_telefon', ''));
    if ($tel !== '') { $node['telephone'] = $tel; }

    $eposta = seo_clean_text(setting('kunye_eposta', ''));
    if ($eposta !== '' && strpos($eposta, '@') !== false) { $node['email'] = $eposta; }

    $adres = seo_clean_text(setting('kunye_adres', ''));
    if ($adres !== '') {
        $node['address'] = [
            '@type'          => 'PostalAddress',
            'streetAddress'  => seo_trim($adres, 200),
            'addressCountry' => 'TR',
        ];
    }

    $mersis = seo_clean_text(setting('kunye_mersis', ''));
    $vergi  = seo_clean_text(setting('kunye_vergi_no', ''));
    if ($mersis !== '') {
        $node['identifier'] = ['@type' => 'PropertyValue', 'name' => 'MERSIS', 'value' => $mersis];
    } elseif ($vergi !== '') {
        $node['identifier'] = ['@type' => 'PropertyValue', 'name' => 'VKN', 'value' => $vergi];
    }

    $same = seo_same_as();
    if ($same) { $node['sameAs'] = $same; }

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

    // --- arama motoru site doğrulama etiketleri
    // Bu alan olmadan yayıncılar doğrulama kodunu ham HTML reklam slotuna
    // ya da tema özel CSS'ine sıkıştırıyordu; ikisi de yanlış yer.
    foreach (seo_verification_metas() as $ad => $deger) {
        $out .= seo_meta_name($ad, $deger);
    }
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
    $out .= '<link rel="alternate" type="application/feed+json" title="'
          . esc($d['site_name'] . ' — JSON Feed') . '" href="' . esc($d['feed_url'] . '?tur=json') . '">' . "\n";
    $hub = seo_websub_hub();
    if ($hub !== '') { $out .= '<link rel="hub" href="' . esc($hub) . '">' . "\n"; }
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

// ============================================================ doğrulama etiketleri

/**
 * Arama motoru site doğrulama meta etiketleri (ad => içerik).
 * Boş ayarlar hiç basılmaz.
 */
function seo_verification_metas() {
    $map = [
        'google-site-verification' => 'seo_verify_google',
        'msvalidate.01'            => 'seo_verify_bing',
        'yandex-verification'      => 'seo_verify_yandex',
    ];
    $out = [];
    foreach ($map as $ad => $anahtar) {
        $v = trim((string)setting($anahtar, ''));
        // Doğrulama kodları alfanümeriktir; başka bir şey gelirse hiç basılmaz.
        if ($v === '' || !preg_match('/^[A-Za-z0-9_\-\.=]{8,200}$/', $v)) { continue; }
        $out[$ad] = $v;
    }
    return $out;
}

// ============================================================ etiket üstverisi

/** `tag_seo` tablosu kurulu mu? (019 göçü uygulanmadan sessiz kalır) */
function seo_tag_seo_ready() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try { qv('SELECT 1 FROM tag_seo LIMIT 1'); return $ready = true; }
    catch (Throwable $e) { return $ready = false; }
}

/**
 * Etiketin özel SEO üstverisi.
 * Etiketler serbest metindir ve kendi tabloları yoktur; eşleme slugify() iledir.
 *
 * @return array ['slug','label','seo_title','seo_desc'] — kayıt yoksa boş alanlar
 */
function seo_tag_meta($term) {
    static $cache = [];
    $slug = slugify((string)$term);
    $bos = ['slug' => $slug, 'label' => (string)$term, 'seo_title' => '', 'seo_desc' => ''];
    if ($slug === '' || !seo_tag_seo_ready()) { return $bos; }
    if (isset($cache[$slug])) { return $cache[$slug]; }
    try {
        $row = q1('SELECT slug, label, seo_title, seo_desc FROM tag_seo WHERE slug = :s', [':s' => $slug]);
    } catch (Throwable $e) { return $bos; }
    return $cache[$slug] = ($row ? $row : $bos);
}

/**
 * Etiket üstverisini yazar (boş başlık + boş açıklama → kayıt silinir).
 * @return bool
 */
function seo_tag_meta_save($term, $title, $desc) {
    if (!seo_tag_seo_ready()) { return false; }
    $slug = slugify((string)$term);
    if ($slug === '') { return false; }
    $title = sanitize_line((string)$title, 255);
    $desc  = sanitize_line((string)$desc, 500);
    $label = sanitize_line((string)$term, 190);
    try {
        $id = (int)qv('SELECT id FROM tag_seo WHERE slug = :s', [':s' => $slug], 0);
        if ($title === '' && $desc === '') {
            if ($id > 0) { q('DELETE FROM tag_seo WHERE id = :i', [':i' => $id]); }
            return true;
        }
        if ($id > 0) {
            q('UPDATE tag_seo SET label = :l, seo_title = :t, seo_desc = :d, updated_at = :u WHERE id = :i',
                [':l' => $label, ':t' => $title, ':d' => $desc, ':u' => now(), ':i' => $id]);
        } else {
            q('INSERT INTO tag_seo (slug, label, seo_title, seo_desc, updated_at) VALUES (:s, :l, :t, :d, :u)',
                [':s' => $slug, ':l' => $label, ':t' => $title, ':d' => $desc, ':u' => now()]);
        }
        return true;
    } catch (Throwable $e) {
        log_error('seo_tag_meta_save: ' . $e->getMessage());
        return false;
    }
}

/** Kayıtlı etiket üstverileri. */
function seo_tag_meta_all($limit = 200) {
    if (!seo_tag_seo_ready()) { return []; }
    $limit = max(1, min(1000, (int)$limit));
    try { return qa('SELECT * FROM tag_seo ORDER BY slug ASC LIMIT ' . $limit); }
    catch (Throwable $e) { return []; }
}

// ============================================================ elle 301 yönetimi

/** `redirects` tablosu kurulu mu? */
function seo_redirects_ready() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try { qv('SELECT 1 FROM redirects LIMIT 1'); return $ready = true; }
    catch (Throwable $e) { return $ready = false; }
}

/** Yönlendirme kurallarına kapalı yollar (kendi kendini kilitlemeyi önler). */
function seo_redirect_reserved() {
    return ['admin', 'install', 'api.php', 'cron.php', 'index.php', 'sitemap.php', 'rss.php',
            'feed.php', 'robots.txt', 'probe-rewrite', 'uploads', 'assets', 'db', 'inc', 'themes'];
}

/**
 * Kaynak yolu normalleştirir: '/Eski/Yol/?x=1' → 'Eski/Yol'
 * Geçersizse boş dize döner.
 */
function seo_redirect_clean_source($path) {
    $p = (string)$path;
    if ($p === '') { return ''; }
    // Tam adres yapıştırıldıysa yalnız yol kısmı alınır.
    if (preg_match('#^https?://#i', $p)) { $p = (string)parse_url($p, PHP_URL_PATH); }
    $p = (string)preg_replace('/[?#].*$/', '', $p);
    $p = rawurldecode($p);
    $p = str_replace('\\', '/', $p);
    $p = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $p);
    $p = trim((string)preg_replace('#/+#', '/', $p), '/');
    if ($p === '' || strpos($p, '..') !== false) { return ''; }
    if (mb_strlen($p) > 400) { return ''; }
    $parcalar = explode('/', $p);
    if (in_array(strtolower($parcalar[0]), seo_redirect_reserved(), true)) { return ''; }
    return $p;
}

/**
 * Hedefi normalleştirir. YALNIZ SİTE İÇİ hedef kabul edilir.
 *
 * KARAR (açık yönlendirme): dış adrese yönlendirme HİÇ yazılamaz — "yalnız admin
 * yazabilir" biçiminde bir istisna da konmadı. Gerekçe:
 *   • 301 tarayıcıda süresiz saklanır; yanlış kayıt fiilen geri alınamaz.
 *   • Panelde veritabanı geri yükleme özelliği var (CONTRACTS §12.5): dış hedefe
 *     izin verilseydi hazırlanmış bir yedek doğrudan alan adı kaçırmaya dönerdi.
 *   • Yayıncının gerçek ihtiyacı olan "başka alan adına taşınma" web sunucusu
 *     düzeyinde (.htaccess / DNS) çözülür, panelden değil.
 * Tam adres yapıştırılırsa ana ad KENDİ adımızla eşleşmelidir; eşleşiyorsa yol
 * kısmına indirgenir, eşleşmiyorsa reddedilir.
 *
 * @return string|false '' = kök, false = geçersiz
 */
function seo_redirect_clean_target($target) {
    $t = trim((string)$target);
    if ($t === '' || $t === '/') { return ''; }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $t)) {
        // Şemalı adres: yalnız kendi http(s) kökenimiz kabul edilir.
        if (!preg_match('#^https?://#i', $t)) { return false; }
        $selfHost = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        $host = strtolower((string)parse_url($t, PHP_URL_HOST));
        if ($host === '' || $selfHost === '' || $host !== $selfHost) { return false; }
        $path = (string)parse_url($t, PHP_URL_PATH);
        $qs   = (string)parse_url($t, PHP_URL_QUERY);
        $t = $path . ($qs !== '' ? '?' . $qs : '');
    }
    // Protokole göreli adres (//baska-site.example) dış hedeftir.
    if (strpos($t, '//') === 0) { return false; }
    $t = str_replace('\\', '/', $t);
    $t = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $t);
    $t = ltrim($t, '/');
    if (strpos($t, '..') !== false) { return false; }
    if (mb_strlen($t) > 400) { return false; }
    return $t;
}

/** Yolun kural karşılığı (yoksa null). */
function seo_redirect_find($path) {
    if (!seo_redirects_ready()) { return null; }
    $p = seo_redirect_clean_source($path);
    if ($p === '') { return null; }
    try {
        return q1('SELECT id, src_path, target, code, active FROM redirects WHERE src_path = :s AND active = 1',
            [':s' => $p]);
    } catch (Throwable $e) { return null; }
}

/** Aktif kural sayısını ayara yazar — istek başına gereksiz sorguyu engeller. */
function seo_redirect_refresh_count() {
    if (!seo_redirects_ready()) { return 0; }
    try { $n = (int)qv('SELECT COUNT(*) FROM redirects WHERE active = 1', [], 0); }
    catch (Throwable $e) { return 0; }
    setting_set('seo_redirect_count', (string)$n);
    return $n;
}

/**
 * Zinciri izler; DÖNGÜ ya da 5 sıçramadan uzun zincir varsa null döner.
 * @return array|null ['target'=>string,'code'=>int,'id'=>int]
 */
function seo_redirect_resolve($path, $maxHops = 5) {
    $gorulen = [];
    $cur = seo_redirect_clean_source($path);
    if ($cur === '') { return null; }
    $son = null;
    for ($i = 0; $i < $maxHops; $i++) {
        if (isset($gorulen[$cur])) { return null; }          // döngü
        $gorulen[$cur] = true;
        $row = seo_redirect_find($cur);
        if (!$row) { return $son; }
        $hedef = (string)$row['target'];
        $son = ['target' => $hedef, 'code' => (int)$row['code'], 'id' => (int)$row['id']];
        if ((int)$row['code'] === 410) { return $son; }
        $next = seo_redirect_clean_source($hedef);
        if ($next === '' || $next === $cur) { return $son; }
        $cur = $next;
    }
    return null;   // 5 sıçramada bitmeyen zincir güvenli değildir
}

/**
 * Kural ekler / günceller.
 * @return array ['ok'=>bool, 'error'=>string, 'id'=>int]
 */
function seo_redirect_save(array $in) {
    if (!seo_redirects_ready()) {
        return ['ok' => false, 'error' => 'Yönlendirme tablosu kurulu değil (019 göçü uygulanmamış).', 'id' => 0];
    }
    $id  = (int)arr($in, 'id', 0);
    $src = seo_redirect_clean_source(arr($in, 'src_path', ''));
    if ($src === '') {
        return ['ok' => false, 'error' => 'Kaynak adres geçersiz. Panel, besleme ve yükleme yolları yönlendirilemez.', 'id' => 0];
    }
    $target = seo_redirect_clean_target(arr($in, 'target', ''));
    if ($target === false) {
        return ['ok' => false, 'error' => 'Hedef yalnız bu sitenin içindeki bir adres olabilir. Dış adrese yönlendirme yazılamaz.', 'id' => 0];
    }
    $code = (int)arr($in, 'code', 301);
    if (!in_array($code, [301, 302, 410], true)) { $code = 301; }
    if ($code !== 410 && $src === seo_redirect_clean_source($target)) {
        return ['ok' => false, 'error' => 'Kaynak ve hedef aynı — bu sonsuz döngü olurdu.', 'id' => 0];
    }
    $active = (int)arr($in, 'active', 1) ? 1 : 0;
    $note = sanitize_line((string)arr($in, 'note', ''), 190);

    try {
        $mevcut = q1('SELECT id FROM redirects WHERE src_path = :s', [':s' => $src]);
        if ($mevcut && (int)$mevcut['id'] !== $id) {
            if ($id > 0) { return ['ok' => false, 'error' => 'Bu kaynak adres için zaten bir kural var.', 'id' => 0]; }
            $id = (int)$mevcut['id'];
        }
        $data = ['src_path' => $src, 'target' => $target, 'code' => $code,
                 'active' => $active, 'note' => $note, 'updated_at' => now()];
        if ($id > 0) {
            db_update('redirects', $data, 'id = :i', [':i' => $id]);
        } else {
            $data['created_at'] = now();
            $data['hits'] = 0;
            $id = db_insert('redirects', $data);
        }
        // DÖNGÜ DENETİMİ kayıttan SONRA yapılır: kural zinciri kapatıyorsa geri alınır.
        if ($active === 1 && $code !== 410 && seo_redirect_resolve($src) === null) {
            q('DELETE FROM redirects WHERE id = :i', [':i' => (int)$id]);
            seo_redirect_refresh_count();
            return ['ok' => false, 'error' => 'Bu kural bir yönlendirme döngüsü oluşturuyor; kaydedilmedi.', 'id' => 0];
        }
        seo_redirect_refresh_count();
        return ['ok' => true, 'error' => '', 'id' => (int)$id];
    } catch (Throwable $e) {
        log_error('seo_redirect_save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Kural kaydedilemedi.', 'id' => 0];
    }
}

/** Kuralı siler. */
function seo_redirect_delete($id) {
    if (!seo_redirects_ready()) { return false; }
    $id = (int)$id;
    if ($id <= 0) { return false; }
    try {
        q('DELETE FROM redirects WHERE id = :i', [':i' => $id]);
        seo_redirect_refresh_count();
        return true;
    } catch (Throwable $e) { return false; }
}

/** Kural listesi. */
function seo_redirect_list($limit = 100, $offset = 0) {
    if (!seo_redirects_ready()) { return []; }
    $limit = max(1, min(500, (int)$limit));
    $offset = max(0, (int)$offset);
    try {
        return qa('SELECT * FROM redirects ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    } catch (Throwable $e) { return []; }
}

/** Toplam kural sayısı. */
function seo_redirect_count() {
    if (!seo_redirects_ready()) { return 0; }
    try { return (int)qv('SELECT COUNT(*) FROM redirects', [], 0); }
    catch (Throwable $e) { return 0; }
}

/**
 * İstek yolunu kurallara karşı sınar ve gerekirse yönlendirir (exit eder).
 * Eşleşme yoksa sessizce döner.
 *
 * Location başlığı GÖRELİ verilir: base_url() ana adı HTTP_HOST'tan türetebiliyor;
 * zehirli bir Host başlığı 301 ile kalıcı zarar verirdi (index.php B04 notu).
 */
function seo_apply_redirect($path) {
    if ((int)setting('seo_redirect_count', '0') <= 0) { return; }
    $hit = seo_redirect_resolve($path);
    if (!$hit) { return; }
    try { q('UPDATE redirects SET hits = hits + 1 WHERE id = :i', [':i' => (int)$hit['id']]); }
    catch (Throwable $e) { }

    if ((int)$hit['code'] === 410) {
        if (!headers_sent()) {
            http_response_code(410);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
        }
        echo "Bu içerik kalıcı olarak kaldırıldı.\n";
        exit;
    }
    $to = site_path() . '/' . ltrim((string)$hit['target'], '/');
    if (!headers_sent()) { header('Location: ' . $to, true, (int)$hit['code']); }
    exit;
}

// ============================================================ IndexNow / ping kuyruğu

/** Kuyruk tablosu kurulu mu? */
function seo_pings_ready() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try { qv('SELECT 1 FROM seo_pings LIMIT 1'); return $ready = true; }
    catch (Throwable $e) { return $ready = false; }
}

/** IndexNow anahtarı (yoksa üretilir). 32 haneli onaltılık. */
function seo_indexnow_key() {
    $k = strtolower(trim((string)setting('seo_indexnow_key', '')));
    if (preg_match('/^[a-f0-9]{16,64}$/', $k)) { return $k; }
    $k = bin2hex(random_bytes(16));
    setting_set('seo_indexnow_key', $k);
    return $k;
}

/** Anahtar dosyasının adresi (site kökünden sunulur). */
function seo_indexnow_key_url() {
    return base_url() . '/' . seo_indexnow_key() . '.txt';
}

function seo_indexnow_enabled() { return setting('seo_indexnow_enabled', '0') === '1'; }
function seo_sitemap_ping_enabled() { return setting('seo_sitemap_ping', '0') === '1'; }

/** WebSub hub adresi (yalnız http(s), yoksa boş). */
function seo_websub_hub() {
    $u = trim((string)setting('seo_websub_hub', ''));
    if ($u === '' || !preg_match('#^https?://[^\s<>"\']+$#i', $u)) { return ''; }
    return $u;
}

/**
 * Site adresi dış dünyadan erişilebilir mi?
 * localhost / özel IP ise ping GÖNDERİLMEZ: arama motoru zaten doğrulayamaz,
 * geliştirme ve test kurulumları da boşuna ağa çıkmaz.
 */
function seo_ping_site_public() {
    $host = (string)parse_url(base_url(), PHP_URL_HOST);
    if ($host === '') { return false; }
    if (strcasecmp($host, 'localhost') === 0) { return false; }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return (bool)filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    // Nokta içermeyen ad yerel ağ adıdır (ör. 'sunucu', 'localhost.localdomain' değil).
    if (strpos($host, '.') === false) { return false; }
    if (preg_match('/\.(test|local|localhost|invalid|example)$/i', $host)) { return false; }
    return true;
}

/**
 * Kuyruğa bir adres ekler. Aynı adres beklemedeyse ikinci kez yazılmaz.
 *
 * YAYIN ANINDA HTTP İSTEĞİ ATILMAZ: "Yayımla" düğmesine basan editör dış
 * servisi beklemez ve servis çökerse yayın düşmez. Kuyruğu cron boşaltır.
 *
 * @return bool eklendi mi
 */
function seo_ping_enqueue($url, $kind = 'indexnow') {
    if (!seo_pings_ready()) { return false; }
    $url = trim((string)$url);
    $kind = in_array($kind, ['indexnow', 'sitemap', 'websub'], true) ? $kind : 'indexnow';
    if ($url === '' || !preg_match('#^https?://#i', $url) || mb_strlen($url) > 400) { return false; }
    try {
        $ayni = (int)qv('SELECT COUNT(*) FROM seo_pings WHERE kind = :k AND url = :u AND status = \'queued\'',
            [':k' => $kind, ':u' => $url], 0);
        if ($ayni > 0) { return false; }
        // Kuyruk sınırı: cron kurulmamış bir sunucuda sonsuza kadar büyümesin.
        $bekleyen = (int)qv('SELECT COUNT(*) FROM seo_pings WHERE status = \'queued\'', [], 0);
        if ($bekleyen >= 500) { return false; }
        q('INSERT INTO seo_pings (kind, url, status, attempts, error, created_at)
           VALUES (:k, :u, \'queued\', 0, \'\', :c)',
            [':k' => $kind, ':u' => $url, ':c' => now()]);
        return true;
    } catch (Throwable $e) {
        log_error('seo_ping_enqueue: ' . $e->getMessage());
        return false;
    }
}

/**
 * Son turdan beri yayına giren haberleri kuyruğa alır.
 *
 * NEDEN KANCA DEĞİL: `posts.save` ucu Ajan-2'nin dosyasındadır, oraya çağrı
 * eklenemez. Su işareti (`seo_ping_last_post_id`) ile keşif kanca kadar kesindir
 * ve hiçbir yayın akışını yavaşlatmaz. İlk turda arşivin tamamı kuyruğa
 * girmesin diye su işareti yalnız kurulur, hiçbir şey gönderilmez.
 *
 * @return int kuyruğa eklenen adres sayısı
 */
function seo_ping_queue_new_posts($limit = 50) {
    if (!seo_pings_ready()) { return 0; }
    $limit = max(1, min(200, (int)$limit));
    try {
        $enBuyuk = (int)qv('SELECT MAX(p.id) FROM posts p WHERE ' . published_where('p'), [':nowts' => now()], 0);
        $isaret = (string)setting('seo_ping_last_post_id', '');
        if ($isaret === '') {
            setting_set('seo_ping_last_post_id', (string)$enBuyuk);
            return 0;
        }
        $isaret = (int)$isaret;
        if ($enBuyuk <= $isaret) { return 0; }
        $rows = qa('SELECT p.id, p.slug FROM posts p WHERE ' . published_where('p') . ' AND p.id > :son
                    ORDER BY p.id ASC LIMIT ' . $limit, [':nowts' => now(), ':son' => $isaret]);
        $n = 0;
        $enSon = $isaret;
        foreach ($rows as $r) {
            if (seo_ping_enqueue(url_post($r), 'indexnow')) { $n++; }
            $enSon = max($enSon, (int)$r['id']);
        }
        setting_set('seo_ping_last_post_id', (string)$enSon);
        return $n;
    } catch (Throwable $e) {
        log_error('seo_ping_queue_new_posts: ' . $e->getMessage());
        return 0;
    }
}

/**
 * HTTP isteği. IndexNow ve Google hedefleri KODDA sabittir; WebSub hub'ı ayardan
 * gelir, bu yüzden hepsi safe_remote_url() kalkanından geçirilir.
 *
 * @return array ['ok'=>bool, 'code'=>int, 'error'=>string]
 */
function seo_http_send($url, $payload = null, $contentType = '') {
    $url = (string)$url;
    if (function_exists('safe_remote_url')) {
        $guvenli = safe_remote_url($url);
        if ($guvenli === false) { return ['ok' => false, 'code' => 0, 'error' => 'Adres reddedildi.']; }
        $url = $guvenli;
    }
    $basliklar = ['User-Agent: Manset/' . MANSET_VERSION];
    if ($contentType !== '') { $basliklar[] = 'Content-Type: ' . $contentType; }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $basliklar,
        ];
        if ($payload !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = $payload; }
        curl_setopt_array($ch, $opt);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hata = ($body === false) ? (string)curl_error($ch) : '';
        curl_close($ch);
        return ['ok' => ($code >= 200 && $code < 300), 'code' => $code, 'error' => $hata];
    }
    if (!ini_get('allow_url_fopen')) {
        return ['ok' => false, 'code' => 0, 'error' => 'cURL yok ve allow_url_fopen kapalı.'];
    }
    $ctx = ['http' => [
        'method'        => $payload !== null ? 'POST' : 'GET',
        'header'        => implode("\r\n", $basliklar),
        'timeout'       => 8,
        'ignore_errors' => true,
    ]];
    if ($payload !== null) { $ctx['http']['content'] = $payload; }
    $res = @file_get_contents($url, false, stream_context_create($ctx));
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string)$http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return ['ok' => ($code >= 200 && $code < 300), 'code' => $code,
            'error' => $res === false ? 'İstek başarısız.' : ''];
}

/** Kuyruk satırlarını sonuca göre işaretler. 3 denemede başarısız olan bırakılır. */
function seo_ping_mark(array $ids, array $res) {
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) { continue; }
        try {
            if (!empty($res['ok'])) {
                q('UPDATE seo_pings SET status = \'sent\', sent_at = :s, error = \'\', attempts = attempts + 1
                   WHERE id = :i', [':s' => now(), ':i' => $id]);
            } else {
                $deneme = (int)qv('SELECT attempts FROM seo_pings WHERE id = :i', [':i' => $id], 0) + 1;
                $durum = $deneme >= 3 ? 'failed' : 'queued';
                q('UPDATE seo_pings SET status = :d, attempts = :a, error = :e WHERE id = :i', [
                    ':d' => $durum, ':a' => $deneme,
                    ':e' => sanitize_line('HTTP ' . (int)arr($res, 'code', 0) . ' ' . (string)arr($res, 'error', ''), 255),
                    ':i' => $id,
                ]);
            }
        } catch (Throwable $e) { }
    }
}

/** Eski (gönderilmiş/atlanmış/başarısız) kuyruk satırlarını budar. */
function seo_ping_prune($tut = 200) {
    if (!seo_pings_ready()) { return; }
    try {
        $esik = (int)qv('SELECT id FROM seo_pings WHERE status <> \'queued\'
                         ORDER BY id DESC LIMIT 1 OFFSET ' . max(0, (int)$tut), [], 0);
        if ($esik > 0) { q('DELETE FROM seo_pings WHERE status <> \'queued\' AND id <= :i', [':i' => $esik]); }
    } catch (Throwable $e) { }
}

/**
 * Cron görevi: kuyruğu boşaltır.
 * Her tur en çok 100 IndexNow adresi (tek istekte), 5 sitemap ping, 5 WebSub bildirimi.
 *
 * @return string tur özeti
 */
function seo_ping_tick() {
    if (!seo_pings_ready()) { return 'ping: tablo yok (019 göçü uygulanmamış)'; }

    $yeni = seo_ping_queue_new_posts(50);
    if ($yeni > 0 && seo_sitemap_ping_enabled()) { seo_ping_enqueue(base_url() . '/sitemap.php', 'sitemap'); }
    $hub = seo_websub_hub();
    if ($yeni > 0 && $hub !== '') { seo_ping_enqueue(base_url() . '/rss.php', 'websub'); }

    $bekleyen = (int)qv('SELECT COUNT(*) FROM seo_pings WHERE status = \'queued\'', [], 0);
    if ($bekleyen === 0) { return 'ping: kuyruk boş (' . $yeni . ' yeni)'; }

    if (!seo_ping_site_public()) {
        q('UPDATE seo_pings SET status = \'skipped\', error = :e, sent_at = :s WHERE status = \'queued\'',
            [':e' => 'Site adresi dış dünyadan erişilebilir değil.', ':s' => now()]);
        return 'ping: ' . $bekleyen . ' adres atlandı (yerel adres)';
    }

    $ozet = [];

    // ---- IndexNow (Bing / Yandex / Naver; Google IndexNow'ı desteklemiyor)
    if (seo_indexnow_enabled()) {
        $rows = qa('SELECT id, url FROM seo_pings WHERE kind = \'indexnow\' AND status = \'queued\'
                    ORDER BY id ASC LIMIT 100');
        if ($rows) {
            $liste = [];
            $idler = [];
            foreach ($rows as $r) { $liste[] = (string)$r['url']; $idler[] = (int)$r['id']; }
            $govde = json_encode([
                'host'        => (string)parse_url(base_url(), PHP_URL_HOST),
                'key'         => seo_indexnow_key(),
                'keyLocation' => seo_indexnow_key_url(),
                'urlList'     => $liste,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $res = seo_http_send('https://api.indexnow.org/indexnow', $govde, 'application/json; charset=utf-8');
            seo_ping_mark($idler, $res);
            $ozet[] = 'IndexNow ' . count($liste) . ' adres → ' . ($res['ok'] ? 'tamam' : 'HTTP ' . $res['code']);
        }
    } else {
        $n = (int)qv('SELECT COUNT(*) FROM seo_pings WHERE kind = \'indexnow\' AND status = \'queued\'', [], 0);
        if ($n > 0) {
            q('UPDATE seo_pings SET status = \'skipped\', error = :e, sent_at = :s
               WHERE kind = \'indexnow\' AND status = \'queued\'',
                [':e' => 'IndexNow kapalı.', ':s' => now()]);
            $ozet[] = 'IndexNow kapalı, ' . $n . ' adres atlandı';
        }
    }

    // ---- Google sitemap ping
    foreach (qa('SELECT id, url FROM seo_pings WHERE kind = \'sitemap\' AND status = \'queued\' ORDER BY id ASC LIMIT 5') as $r) {
        $res = seo_http_send('https://www.google.com/ping?sitemap=' . rawurlencode((string)$r['url']));
        seo_ping_mark([(int)$r['id']], $res);
        $ozet[] = 'sitemap ping → ' . ($res['ok'] ? 'tamam' : 'HTTP ' . $res['code']);
    }

    // ---- WebSub
    if ($hub !== '') {
        foreach (qa('SELECT id, url FROM seo_pings WHERE kind = \'websub\' AND status = \'queued\' ORDER BY id ASC LIMIT 5') as $r) {
            $res = seo_http_send($hub, http_build_query(['hub.mode' => 'publish', 'hub.url' => (string)$r['url']]),
                'application/x-www-form-urlencoded');
            seo_ping_mark([(int)$r['id']], $res);
            $ozet[] = 'WebSub → ' . ($res['ok'] ? 'tamam' : 'HTTP ' . $res['code']);
        }
    }

    seo_ping_prune();
    return 'ping: ' . ($ozet ? implode(', ', $ozet) : 'iş yok') . ' (' . $yeni . ' yeni)';
}

/** Panel için kuyruk sayaçları. */
function seo_ping_stats() {
    $out = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
    if (!seo_pings_ready()) { return $out; }
    try {
        foreach (qa('SELECT status, COUNT(*) AS n FROM seo_pings GROUP BY status') as $r) {
            $k = (string)$r['status'];
            if (isset($out[$k])) { $out[$k] = (int)$r['n']; }
        }
    } catch (Throwable $e) { }
    return $out;
}

/** Son kuyruk satırları (panel listesi). */
function seo_ping_recent($limit = 20) {
    if (!seo_pings_ready()) { return []; }
    $limit = max(1, min(100, (int)$limit));
    try { return qa('SELECT * FROM seo_pings ORDER BY id DESC LIMIT ' . $limit); }
    catch (Throwable $e) { return []; }
}

// ============================================================ ön yüz kapısı

/** Görsel site haritası açık mı? (sitemap.php okur) */
function seo_sitemap_images_enabled() { return setting('seo_sitemap_images', '1') !== '0'; }

/** İstek yolu — index.php route_path() ile aynı öncelik sırası. */
function seo_request_path() {
    if (isset($_GET['r']) && is_string($_GET['r'])) {
        return trim((string)preg_replace('#/+#', '/', $_GET['r']), '/');
    }
    if (!empty($_SERVER['PATH_INFO'])) {
        return trim((string)preg_replace('#/+#', '/', (string)$_SERVER['PATH_INFO']), '/');
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
    $uri = (string)parse_url($uri, PHP_URL_PATH);
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/index.php';
    $baseDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($baseDir !== '' && $baseDir !== '/' && strpos($uri, $baseDir) === 0) {
        $uri = substr($uri, strlen($baseDir));
    }
    $uri = (string)preg_replace('#^/index\.php#', '', (string)$uri);
    return trim((string)preg_replace('#/+#', '/', (string)$uri), '/');
}


/**
 * Ön yüz kapısı — index.php tarafından AÇIKÇA çağrılır.
 *
 * İki iş yapar:
 *   1. IndexNow anahtar dosyasını site kökünden sunar (`/<anahtar>.txt`).
 *   2. Elle tanımlı 301 / 302 / 410 kurallarını uygular.
 *
 * ÇAĞRI YERİ: index.php, modüller yüklendikten hemen sonra — yönlendirme
 * çözülmeden ve sayfa önbelleği okunmadan önce. Taşınmış bir adres için
 * önbellekten eski sayfanın sunulmaması buna bağlıdır.
 *
 * ÇEREZ: oturum açılmaz, hiçbir kod yolu `Set-Cookie` üretmez.
 */
function seo_front_gate() {
    if (headers_sent()) { return; }
    $yontem = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($yontem !== 'GET' && $yontem !== 'HEAD') { return; }

    $path = seo_request_path();
    if ($path === '') { return; }

    // --- IndexNow anahtar dosyası
    if (preg_match('/^([a-f0-9]{16,64})\.txt$/i', $path, $m)) {
        $key = strtolower(trim((string)setting('seo_indexnow_key', '')));
        if ($key !== '' && hash_equals($key, strtolower($m[1]))) {
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Robots-Tag: noindex');
            header('Cache-Control: public, max-age=86400');
            echo $key;
            exit;
        }
        return;
    }

    seo_apply_redirect($path);
}

// Cron: kuyruğu boşaltan görev. `ucuz = false` — ağa çıkar.
if (function_exists('cron_register')) {
    cron_register('seo_ping', 'seo_ping_tick', false);
}

// NOT: Kapı BURADAN ÇAĞRILMAZ. Çağrı index.php içinde, açıkça yapılır.
// Yükleme anında yan etkisi olan bir modül, aynı dosyayı require eden
// api.php / cron.php / admin panelinde de sessizce çalışırdı.
