<?php
/**
 * Manşet — ölçülü ödeme duvarı uç noktaları (1.2-05, Ajan-D).
 * Ad alanı: paywall.*
 *
 * Kayıtlı uçlar:
 *   paywall.status       → ziyaretçinin kalan ücretsiz hakkı (salt okuma)
 *   paywall.gift_create  → abone bir haber için hediye bağlantısı üretir
 *   paywall.trial_start  → üye deneme süresini başlatır
 *
 * HEPSİ `perm => 'public'`; oturum gerektirenler kendi içinde member_current()
 * denetimi yapar (members_api.php ile aynı desen). Panel izni istenmiyor çünkü
 * bunlar OKURUN kendi hesabıyla yaptığı işlerdir.
 *
 * BU UÇLAR ÇEREZ YAZMAZ. Sayaç çerezi yalnız kilitli haber sayfasında verilir
 * (CONTRACTS §3.1 istisnası dar tutulur). `paywall.status` sayacı yalnız OKUR,
 * yazmaz — API isteği bir "okuma" değildir ve hak düşürmez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// api.php iş modüllerini manset_feature_modules() üzerinden yükler ve `paywall`
// o listede vardır. Yine de require_once ile bağımlılık burada AÇIKÇA duruyor:
// panel sayfalarından bu dosyayı require eden bir yol açılırsa kanca sessizce
// kaybolmasın (1.2-01'de analitik modülü tam bu yüzden hiç çalışmamıştı).
if (is_file(dirname(__DIR__) . '/paywall.php')) {
    require_once dirname(__DIR__) . '/paywall.php';
}
if (is_file(dirname(__DIR__) . '/members.php')) {
    require_once dirname(__DIR__) . '/members.php';
}

/** İstek gövdesinden alan okur (JSON önce, form yedek). */
function paywall_api_field($key, $default = '') {
    $body = json_body();
    if (array_key_exists($key, $body)) { return $body[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    if (isset($_GET[$key]))  { return $_GET[$key]; }
    return $default;
}

/** Oturumdaki üye; yoksa 401 (assets/uye.js `login_required` işaretine bakar). */
function paywall_api_login() {
    $u = function_exists('member_current') ? member_current() : null;
    if (!$u) { json_err('Bu işlem için giriş yapmanız gerekiyor.', 401, ['login_required' => true]); }
    return $u;
}

// ---------------------------------------------------------------- paywall.status
/**
 * İstek : —
 * Yanıt : {ok:true, olculu:bool, toplam:int, kalan:int, hediye:bool, deneme_gun:int}
 *
 * Salt okuma. Tema "bu ay N ücretsiz haberiniz kaldı" yazmak isterse buradan
 * alır; sayfanın kendisi önbelleğe alınabilir kalır.
 */
api_register('paywall.status', function () {
    return [
        'olculu'     => paywall_meter_on(),
        'toplam'     => paywall_meter_free(),
        'kalan'      => paywall_meter_left(),
        'hediye'     => paywall_gift_on(),
        'deneme_gun' => paywall_trial_days(),
    ];
}, ['perm' => 'public', 'methods' => ['GET', 'POST'], 'csrf' => false]);

// ---------------------------------------------------------------- paywall.gift_create
/**
 * İstek : {post_id, _csrf}      (oturum gerekir, abone olmak gerekir)
 * Yanıt : {ok:true, url:string, expires_at:string, kalan:int}
 * Hata  : 401 oturum · 400 kota/kapalı/uygunsuz haber · 429 hız sınırı
 *
 * Dönen `url` HAM token'ı içerir ve TEK KEZ görünür: veritabanında yalnız
 * SHA-256 özeti durur (inc/members.php `member_token_create()` deseni).
 * Bağlantıyı kaybeden abone yenisini üretir, eskisi kotadan düşmüş kalır —
 * aksi hâlde "yeniden göster" ucu, sızmış bir yedeği yeniden kullanılabilir
 * hale getirirdi.
 */
api_register('paywall.gift_create', function () {
    $u = paywall_api_login();
    if (!rate_limit('paywall-gift:' . client_ip(), 10, 300)) {
        json_err('Çok fazla deneme. Biraz sonra tekrar deneyin.', 429);
    }
    $r = paywall_gift_create((int)$u['id'], (int)paywall_api_field('post_id', 0));
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'Hediye bağlantısı üretilemedi.'), 400); }
    // HAM token yanıtta ayrıca DÖNDÜRÜLMEZ; yalnız tam adres döner.
    return ['url' => (string)$r['url'], 'expires_at' => (string)$r['expires_at'],
            'kalan' => (int)arr($r, 'kalan', 0)];
}, ['perm' => 'public', 'methods' => ['POST']]);

// ---------------------------------------------------------------- paywall.trial_start
/**
 * İstek : {_csrf}              (oturum gerekir)
 * Yanıt : {ok:true, ends_at:string, days:int}
 * Hata  : 401 oturum · 400 deneme kapalı / daha önce kullanılmış · 429
 *
 * Tekillik `paywall_trials.user_id` BENZERSİZ indeksindedir; iki eşzamanlı
 * istek gelirse ikincisinin INSERT'i veritabanı düzeyinde reddedilir.
 */
api_register('paywall.trial_start', function () {
    $u = paywall_api_login();
    if (!rate_limit('paywall-trial:' . client_ip(), 5, 900)) {
        json_err('Çok fazla deneme. Biraz sonra tekrar deneyin.', 429);
    }
    $r = paywall_trial_start((int)$u['id']);
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'Deneme başlatılamadı.'), 400); }
    return ['ends_at' => (string)$r['ends_at'], 'days' => (int)$r['days']];
}, ['perm' => 'public', 'methods' => ['POST']]);
