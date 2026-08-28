<?php
/**
 * Manşet — hediye bağlantısı giriş noktası (1.2-05, Ajan-D).
 *
 *   GET  hediye.php?t=<token>     → bağlantıyı TÜKETİR ve habere yönlendirir
 *   POST hediye.php  (_csrf, haber) → abone için yeni hediye bağlantısı üretir
 *
 * -----------------------------------------------------------------------------
 * NEDEN AYRI BİR KÖK DOSYASI
 * -----------------------------------------------------------------------------
 * Hediye bağlantısı üçüncü kişiye WhatsApp/e-posta ile gönderilir; kısa, sabit
 * ve SEF ayarından bağımsız bir adres olmalıdır. index.php'ye yeni bir yol
 * eklemek orkestratörün dosyasına yazmak demekti (1.2 sözleşmesi §6).
 *
 * -----------------------------------------------------------------------------
 * ÇEREZ VE ÖNBELLEK
 * -----------------------------------------------------------------------------
 * Tüketim başarılıysa imzalı bir BAĞIŞ ÇEREZİ yazılır (inc/paywall.php,
 * `paywall_gift_grant_add`). Bu, çıktı başlamadan ve yönlendirmeden ÖNCE
 * yapılır — `session_boot()` gibi sessizce dönen bir yol yoktur, dönüş değeri
 * denetlenir. PHP oturumu AÇILMAZ.
 *
 * Bu dosyanın hiçbir yanıtı önbelleğe alınabilir değildir: yönlendirme
 * `no-store` ile işaretlenir.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    header('Location: ' . base_url() . '/install/', true, 302);
    exit;
}

require_once __DIR__ . '/inc/view.php';
foreach (['seo', 'members', 'kunye', 'widgets', 'cache', 'roles'] as $hediyeMod) {
    $hediyeDosya = __DIR__ . '/inc/' . $hediyeMod . '.php';
    if (is_file($hediyeDosya)) { require_once $hediyeDosya; }
}
manset_load_feature_modules();

if (!function_exists('paywall_gift_redeem')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ödeme duvarı modülü kurulu değil.';
    exit;
}

// Oturum çerezi olan ziyaretçi için oturumu ÇIKTIDAN ÖNCE açarız (index.php ile
// aynı gerekçe: session_boot() headers_sent() sonrası sessizce döner).
// Anonim ziyaretçide oturum AÇILMAZ.
if (has_session_cookie()) { session_boot(); }

/** Bu dosyanın her yanıtı kişiye özeldir. */
function hediye_no_store() {
    if (headers_sent()) { return; }
    header('Cache-Control: private, no-store, max-age=0');
    header('Vary: Cookie');
}

/** Basit hata sayfası (tema çerçevesinde). */
function hediye_hata($mesaj, $kod = 400) {
    hediye_no_store();
    render('page', ['page' => [
        'id' => 0, 'slug' => 'hediye', 'title' => 'Hediye bağlantısı',
        'seo_title' => 'Hediye bağlantısı — ' . setting('site_title', 'Manşet'),
        'seo_desc'  => '',
        'body'      => '<p>' . esc($mesaj) . '</p>'
                     . '<p><a href="' . esc(base_url() . '/') . '">Anasayfaya dön</a></p>',
    ]], $kod);
    if (function_exists('cache_abort')) { cache_abort(); }
    exit;
}

$yontem = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';

// ================================================================ POST işlemleri
if ($yontem === 'POST') {
    csrf_guard();
    $uye = function_exists('member_current') ? member_current() : null;
    if (!$uye) { hediye_hata('Bu işlem için giriş yapmanız gerekiyor.', 401); }

    // DENEME BAŞLATMA — neden burada?
    // Ön yüzde JS'siz çalışan, CSRF korumalı POST kabul eden tek dosyam bu.
    // Kilit kutusundaki "deneyin" düğmesinin JS gerektirmemesi gerekiyordu
    // (CONTRACTS §3.1 ruhu: ön yüz JS'siz de çalışır). Ayrı bir kök dosyası
    // açmak 1.2 sözleşmesindeki dosya listesini aşardı.
    if (inp_s('eylem', '') === 'deneme') {
        if (!rate_limit('paywall-trial:' . client_ip(), 5, 900)) {
            hediye_hata('Çok fazla deneme. Biraz sonra tekrar deneyin.', 429);
        }
        $dn = paywall_trial_start((int)$uye['id']);
        if (empty($dn['ok'])) { hediye_hata((string)arr($dn, 'error', 'Deneme başlatılamadı.')); }
        hediye_no_store();
        $geri = inp_i('haber', 0);
        $hedef = base_url() . '/';
        if ($geri > 0) {
            $p = q1('SELECT id, slug FROM posts WHERE id = :i', [':i' => $geri]);
            if ($p) { $hedef = url_post($p); }
        }
        header('Location: ' . $hedef, true, 302);
        exit;
    }

    if (!rate_limit('paywall-gift:' . client_ip(), 10, 300)) {
        hediye_hata('Çok fazla deneme. Biraz sonra tekrar deneyin.', 429);
    }

    $sonuc = paywall_gift_create((int)$uye['id'], inp_i('haber', 0));
    if (empty($sonuc['ok'])) { hediye_hata((string)arr($sonuc, 'error', 'Bağlantı üretilemedi.')); }

    // Adres BİR KEZ gösterilir: veritabanında yalnız özeti var, yeniden
    // gösterilemez. Bağlantı `page` şablonundan geçer; sanitize_html <a href>
    // etiketini korur, form/script'i değil — bu yüzden gövde güvenle basılabilir.
    $adres = (string)$sonuc['url'];
    hediye_no_store();
    render('page', ['page' => [
        'id' => 0, 'slug' => 'hediye', 'title' => 'Hediye bağlantınız hazır',
        'seo_title' => 'Hediye bağlantısı — ' . setting('site_title', 'Manşet'),
        'seo_desc'  => '',
        'body' => '<p>Aşağıdaki bağlantı <strong>tek kullanımlıktır</strong> ve '
                . esc(tr_date((string)$sonuc['expires_at'])) . ' tarihine kadar geçerlidir. '
                . 'Bu sayfayı kapattığınızda adres bir daha gösterilemez; kopyalayın.</p>'
                . '<p><a href="' . esc($adres) . '">' . esc($adres) . '</a></p>'
                . '<p>Bu ay kalan hediye hakkınız: ' . (int)arr($sonuc, 'kalan', 0) . '</p>',
    ]]);
    if (function_exists('cache_abort')) { cache_abort(); }
    exit;
}

// ================================================================ TÜKETİM (GET)
$token = '';
foreach (['t', 'token'] as $ad) {
    if (isset($_GET[$ad]) && is_string($_GET[$ad]) && $_GET[$ad] !== '') { $token = (string)$_GET[$ad]; break; }
}
if ($token === '') { hediye_hata('Bağlantı eksik.', 404); }

// Kaba kuvvet kalkanı: token 64 hane onaltılıktır, ama sayaç yine de gerekir —
// hız sınırı denemeyi KARARINDAN ÖNCE yazar (CONTRACTS §3.2).
if (!rate_limit('gift-redeem:' . client_ip(), 20, 300)) {
    hediye_hata('Çok fazla deneme. Biraz sonra tekrar deneyin.', 429);
}

$sonuc = paywall_gift_redeem($token);
if (empty($sonuc['ok'])) { hediye_hata((string)arr($sonuc, 'error', 'Bağlantı kullanılamadı.'), 410); }

$postId = (int)$sonuc['post_id'];
$post = q1('SELECT id, slug, title FROM posts WHERE id = :i', [':i' => $postId]);
if (!$post) { hediye_hata('Hediye edilen haber bulunamadı.', 404); }

// BAĞIŞ ÇEREZİ — çıktı başlamadan yazılır. Yazılamazsa okur yönlendirildiği
// sayfada yine kilitle karşılaşırdı; bunu sessizce geçmeyiz.
if (!paywall_gift_grant_add($postId)) {
    hediye_hata('Hediye açılamadı (yanıt başlıkları gönderilmiş). Lütfen tekrar deneyin.', 500);
}

hediye_no_store();
header('Location: ' . url_post($post), true, 302);
exit;
