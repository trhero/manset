<?php
/**
 * Manşet — taslak önizleme giriş noktası (1.3-03, Ajan-G).
 *
 *   GET onizleme.php?t=<belirteç>   → yayımlanmamış haberi tema çatısında gösterir
 *
 * =============================================================================
 * BU DOSYA BİR YETKİ ATLATMA YÜZEYİDİR — KURALLARI
 * =============================================================================
 * 1. BELİRTEÇ TAHMİN EDİLEMEZ.  32 bayt (256 bit) `random_bytes` → 64 haneli
 *    onaltılık. Veritabanında YALNIZ `sha256` özeti durur (inc/embed.php,
 *    `embed_preview_create`). Yedek dosyası ya da SQL sızıntısı hiçbir
 *    bağlantıyı yeniden üretemez.
 * 2. SÜRESİ DOLAR.  `expires_at` zorunludur (varsayılan 48 saat, en çok 168).
 *    Karşılaştırma burada, okuma anında yapılır — süresi dolan satır SİLİNMEZ
 *    ki editöre "süresi dolmuş" diyebilelim, "böyle bir bağlantı yok" değil.
 * 3. YALNIZ O HABERİ AÇAR.  Haber kimliği ADRESTEN OKUNMAZ; belirtecin
 *    satırından gelir. `onizleme.php?t=X&id=5` diye bir şey yoktur, yani
 *    belirteç değiştirmeden başka habere geçmek mümkün değildir.
 * 4. ARAMA MOTORUNA GİRMEZ.  `X-Robots-Tag: noindex, nofollow, noarchive`
 *    başlığı + `<meta name="robots">` etiketi. Yanıt ayrıca `private, no-store`.
 * 5. OTURUM AÇMAZ.  `session_boot()` ÇAĞRILMAZ, `current_user()` ÇAĞRILMAZ.
 *    Bağlantıyı açan kişi anonim kalır ve çerez ALMAZ (CONTRACTS §3.1).
 *    Yayın kurulundaki biri bağlantıyı kaynağa gönderdiğinde kaynak, sitenin
 *    panelinde hiçbir kimlik kazanmaz.
 * 6. ÖDEME DUVARINI AÇMAZ.  Aşağıda YAYIMLANMIŞ haber açıkça REDDEDİLİR.
 *    Belirteç zaten yalnız yayımlanmamış habere üretilir (inc/api/embed_api.php)
 *    ama kapı iki yerde de vardır: bir haberi taslakken önizleyip sonra
 *    "premium" olarak yayımlamak, o belirteci bir ödeme duvarı anahtarına
 *    çevirmemelidir.
 *
 * -----------------------------------------------------------------------------
 * NEDEN AYRI KÖK DOSYASI
 * -----------------------------------------------------------------------------
 * Bağlantı dışarıya (kaynağa, hukuk müşavirine, yayın yönetmenine) gönderilir;
 * kısa ve SEF ayarından bağımsız olmalıdır. `index.php` orkestratörün dosyasıdır
 * (1.3 sözleşmesi §6), oraya yol eklenemez. Aynı gerekçe `hediye.php` için de
 * geçerliydi; desen ondan alındı.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    header('Location: ' . base_url() . '/install/', true, 302);
    exit;
}

require_once __DIR__ . '/inc/view.php';
foreach (['seo', 'members', 'kunye', 'widgets', 'cache', 'roles'] as $onizlemeMod) {
    $onizlemeDosya = __DIR__ . '/inc/' . $onizlemeMod . '.php';
    if (is_file($onizlemeDosya)) { require_once $onizlemeDosya; }
}
manset_load_feature_modules();

// DİKKAT: session_boot() BURADA YOK ve olmamalı. hediye.php oturum çerezi olan
// ziyaretçi için oturumu açıyordu çünkü üye kimliği gerekiyordu; önizlemede
// kimlik GEREKMEZ ve istenmez (yukarıda 5. kural).

/** Bu dosyanın hiçbir yanıtı önbelleğe alınabilir ya da indekslenebilir değildir. */
function onizleme_basliklar() {
    if (headers_sent()) { return false; }
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Referrer-Policy: no-referrer');
    return true;
}

/** Basit hata sayfası (tema çatısında). */
function onizleme_hata($mesaj, $kod = 404) {
    onizleme_basliklar();
    render('page', ['page' => [
        'id' => 0, 'slug' => 'onizleme', 'title' => 'Taslak önizleme',
        'seo_title' => 'Taslak önizleme',
        'seo_desc'  => '',
        'body'      => '<p>' . esc($mesaj) . '</p>'
                     . '<p><a href="' . esc(base_url() . '/') . '">Anasayfaya dön</a></p>',
    ]], $kod);
    if (function_exists('cache_abort')) { cache_abort(); }
    exit;
}

if (!function_exists('embed_preview_consume')) {
    onizleme_hata('Önizleme modülü kurulu değil.', 503);
}

$yontem = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
if ($yontem !== 'GET' && $yontem !== 'HEAD') {
    header('Allow: GET, HEAD');
    onizleme_hata('Bu adres yalnız görüntüleme içindir.', 405);
}

$token = isset($_GET['t']) && is_string($_GET['t']) ? (string)$_GET['t'] : '';
if ($token === '') { onizleme_hata('Önizleme bağlantısı eksik.', 404); }

// KABA KUVVET KALKANI. Belirteç 256 bitliktir, yani tahmin pratikte imkânsızdır;
// sayaç yine de gerekir çünkü "imkânsız" bir varsayımdır ve bu uç oturumsuzdur.
// Sayaç KARARDAN ÖNCE yazar (CONTRACTS §3.2).
if (!rate_limit('preview-open:' . client_ip(), 30, 300)) {
    onizleme_hata('Çok fazla deneme. Biraz sonra tekrar deneyin.', 429);
}

$sonuc = embed_preview_consume($token);
if (empty($sonuc['ok'])) {
    onizleme_hata((string)arr($sonuc, 'error', 'Önizleme bağlantısı geçersiz.'),
                  (int)arr($sonuc, 'code', 404));
}

$postId = (int)$sonuc['post_id'];
// `true` = her durumdaki haber (taslak dâhil). Kategori/yazar birleşimlerini
// çekirdek yapar; kendi SQL'imi yazsaydım tema alanları eksik kalabilirdi.
$post = post_by_id($postId, true);
if (!$post) { onizleme_hata('Önizlenen haber bulunamadı.', 404); }

// Çöp kutusundaki haber önizlenmez (göç 013 uygulanmamışsa sütun yoktur).
if (trim((string)arr($post, 'deleted_at', '')) !== '') {
    onizleme_hata('Bu haber çöp kutusunda; önizleme gösterilemez.', 410);
}

// 6. KURAL — ödeme duvarı anahtarına dönüşmesin.
if ((string)arr($post, 'status', '') === 'published') {
    onizleme_hata('Bu haber yayımlanmış; önizleme bağlantısı artık geçerli değil.', 410);
}

/*
 * GÖRÜNÜRLÜK BELLEKTE 'public' YAPILIR — VERİTABANINA DOKUNULMAZ.
 *
 * Neden: `post_body_html()` içindeki okur kapısı `paywall_allow()`e devreder ve
 * o işlev YAN ETKİLİDİR (imzalı sayaç çerezi yazar, ücretsiz hak düşürür).
 * Önizleme anonim ve çerezsiz olmalı (5. kural), üstelik amacı zaten TAM METNİ
 * göstermektir. Bu bir duvar atlatma DEĞİLDİR: yukarıdaki kapı yayımlanmış
 * haberi çoktan reddetti, yani buraya gelen metin hiçbir okurun parayla
 * erişebileceği bir metin değil — henüz yayımlanmamış bir taslaktır.
 */
$post['visibility'] = 'public';

// Yorum ve ilgili haber sorgusu YAPILMAZ: taslağın yorumu yoktur ve önizleme
// gezinme sayfası değildir. Boş dizi vermek şablonların ikisini de atlamasını sağlar.
$onizlemeVars = ['post' => $post, 'related' => [], 'comments' => []];

$sonTarih = (string)arr($sonuc, 'expires_at', '');

/*
 * ÇIKTI TAMPONLANIR.
 * `render()` başlıklarını yalnız `headers_sent()` false iken yazar ve oturum
 * çerezi yoksa `Cache-Control: public, s-maxage=60` koyar — yani bu sayfayı bir
 * CDN'de paylaşılabilir yapardı. Tamponla çıktı akmadığı için başlıklar hâlâ
 * değiştirilebilir; render'dan SONRA kendi başlıklarımızı yazıp üzerine geçeriz.
 */
ob_start();
render('single', $onizlemeVars);
$html = (string)ob_get_clean();

if (function_exists('cache_abort')) { cache_abort(); }
onizleme_basliklar();

/*
 * <head> içindeki robots etiketi DEĞİŞTİRİLİR, eklenmez.
 * Tema `seo_head_tags()` ile `index,follow` basıyor; kendi etiketimizi yanına
 * koysaydık sayfada BİRBİRİYLE ÇELİŞEN iki `meta robots` olurdu. Tarayıcıya ve
 * tarayıcı botuna çelişkili talimat vermek, doğru talimatı vermemekle aynı
 * kapıya çıkar — mevcut etiket önce silinir.
 * (Asıl güvence yine `X-Robots-Tag` başlığıdır; etiket ikinci kemerdir.)
 */
$meta = '<meta name="robots" content="noindex,nofollow,noarchive,nosnippet">';
$html = (string)preg_replace('#<meta\s[^>]*name=["\']robots["\'][^>]*>#i', '', $html, 1);
$konum = stripos($html, '</head>');
if ($konum !== false) { $html = substr($html, 0, $konum) . $meta . substr($html, $konum); }
else { $html = $meta . $html; }

// Gövdenin en üstüne "bu bir taslaktır" şeridi. Tema dosyalarında kanca yok ve
// `themes/` Ajan-G'nin dosyası değil; şerit bu yüzden çıktıya burada eklenir.
// Satır içi stil kullanılır: yeni bir CSS dosyası eklemek `assets/` altında
// başkasının dosyasına dokunmak olurdu.
$serit = '<div role="status" style="background:#8a1c1c;color:#fff;padding:10px 16px;'
       . 'font:600 14px/1.4 system-ui,sans-serif;text-align:center">'
       . 'TASLAK ÖNİZLEME — bu haber yayımlanmadı, arama motorlarına kapalıdır.'
       . ($sonTarih !== '' ? ' Bağlantı ' . esc(tr_date($sonTarih)) . ' tarihinde geçersiz olur.' : '')
       . '</div>';
if (preg_match('/<body[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
    $kes = (int)$m[0][1] + strlen($m[0][0]);
    $html = substr($html, 0, $kes) . $serit . substr($html, $kes);
} else {
    $html = $serit . $html;
}

echo $html;
