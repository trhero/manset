<?php
/**
 * Manşet — ölçülü ödeme duvarı (1.2-05, Ajan-D).
 *
 * Dört ayrı kapı, hepsi `paywall_allow()` üzerinden:
 *   1. HEDİYE BAĞLANTISI  Bir abone tek bir haberi abone olmayan birine açar.
 *   2. DENEME             Hesap başına bir kez, N gün tam erişim.
 *   3. ÖLÇÜLÜ SAYAÇ       Ayda N ücretsiz kilitli haber (imzalı çerez).
 *   4. YENİLEME HATIRLATMASI  Bitişe yaklaşan aboneye TEK TEK işlemsel e-posta.
 *
 * -----------------------------------------------------------------------------
 * OTURUMSUZ ÖN YÜZ KURALI (CONTRACTS §3.1) — BU MODÜLÜN EN KRİTİK KISITI
 * -----------------------------------------------------------------------------
 * Giriş yapmamış ziyaretçi ÇEREZ ALMAZ; alırsa sayfa önbelleği hiçbir zaman
 * devreye giremez. Ölçülü duvarın sayacı bu kuralın TEK istisnasıdır ve istisna
 * DAR tutulur:
 *
 *   · Çerez YALNIZ kilitli bir HABER sayfasında ve YALNIZ o haber gerçekten
 *     ölçülü haktan düşüldüğünde verilir. Anasayfa, kategori, etiket, arama,
 *     besleme, sitemap — hiçbirinde yazılmaz. Kapı `paywall_request_post_id()`:
 *     istek yolu o haberin kendi adresi değilse sayaç İŞLEMEZ. Bu kapı olmasaydı
 *     bir liste şablonunun her kart için post_reader_can_read_full() çağırması
 *     anasayfada tüm aylık hakkı yakardı.
 *
 *   · PHP OTURUMU AÇILMAZ. `session_boot()` bu dosyada hiç geçmez. Çerez
 *     kendi imzalı biçimimizdir, sunucuda satır tutulmaz.
 *
 *   · Ölçülü hakla ya da hediyeyle açılan her sayfa `private, no-store` ile
 *     işaretlenir ve `cache_abort()` ile dosya önbelleğinden çıkarılır. Aksi
 *     hâlde bir okurun tam metni herkese servis edilirdi.
 *
 * -----------------------------------------------------------------------------
 * İMZALI SAYAÇ ÇEREZİ
 * -----------------------------------------------------------------------------
 * Değer:  1|<YYYY-MM>|<okunan haber kimlikleri>|<imza>
 * İmza :  hash_hmac('sha256', '1|dönem|kimlikler', app_key) ilk 32 hanesi
 * Doğrulama `hash_equals` ile sabit zamanlıdır. Okur değeri elle değiştirirse
 * imza tutmaz ve sayaç SIFIRDAN başlar (kurcalama kazanç sağlamaz; en fazla
 * çerezi silmekle aynı sonucu verir).
 *
 * Kimlik LİSTESİ tutulur, yalnız sayı değil: aynı habere üç kez dönen okur üç
 * hak yakmaz. Liste ücretsiz hak sayısı kadar öğeyle sınırlıdır.
 *
 * KABUL EDİLEN SINIR: okur çerezi silerse sayaç sıfırlanır. Çerezsiz alternatif
 * ziyaretçi başına sunucuda bir iz (IP ya da parmak izi) saklamaktı; bu KVKK
 * açısından açıkça daha ağırdır ve sözleşmenin "sunucuda satır tutma" kuralına
 * aykırıdır. Ölçülü duvar bir kilit değil, bir NEZAKET sınırıdır: gerçek kilit
 * `post_can_read_full()`tir ve ona dokunulmadı.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ sabitler

/** Ölçülü sayaç çerezinin adı. */
if (!defined('PAYWALL_METER_COOKIE')) { define('PAYWALL_METER_COOKIE', 'manset_olcum'); }
/** Hediye bağışı çerezinin adı. */
if (!defined('PAYWALL_GIFT_COOKIE'))  { define('PAYWALL_GIFT_COOKIE', 'manset_hediye'); }
/** Hediye bağışının tarayıcıda geçerli kalma süresi (sn). */
if (!defined('PAYWALL_GIFT_GRANT_TTL')) { define('PAYWALL_GIFT_GRANT_TTL', 86400); }
/** Ücretsiz hak sayısının üst sınırı (çerez boyutu ve akıl sağlığı). */
if (!defined('PAYWALL_METER_MAX')) { define('PAYWALL_METER_MAX', 30); }

// ============================================================ ayarlar

/** Ölçülü sayaç açık mı? İmza anahtarı yoksa KAPALI (güvenli taraf). */
function paywall_meter_on() {
    if (setting('paywall_meter_enabled', '0') !== '1') { return false; }
    if (paywall_meter_free() <= 0) { return false; }
    return paywall_key() !== '';
}

/** Ayda kaç ücretsiz kilitli haber. */
function paywall_meter_free() {
    return max(0, min(PAYWALL_METER_MAX, (int)setting('paywall_meter_free', '3')));
}

/** Sayaç hangi görünürlük düzeylerini kapsıyor. */
function paywall_meter_scope() {
    $v = (string)setting('paywall_meter_scope', 'premium');
    return $v === 'all' ? ['members', 'premium'] : ['premium'];
}

/** Hediye bağlantısı açık mı? */
function paywall_gift_on() {
    return setting('paywall_gift_enabled', '0') === '1' && paywall_key() !== '';
}

/** Abone başına aylık hediye hakkı. */
function paywall_gift_monthly() { return max(0, min(200, (int)setting('paywall_gift_monthly', '5'))); }

/** Hediye bağlantısının geçerlilik süresi (saat). */
function paywall_gift_ttl_hours() { return max(1, min(720, (int)setting('paywall_gift_ttl_hours', '72'))); }

/** Deneme süresi (gün). 0 → deneme kapalı. */
function paywall_trial_days() { return max(0, min(365, (int)setting('paywall_trial_days', '0'))); }

/** Yenileme hatırlatması açık mı? */
function paywall_renew_on() { return setting('paywall_renew_enabled', '0') === '1'; }

/** Bitişten kaç gün önce hatırlatılsın. */
function paywall_renew_days() { return max(1, min(60, (int)setting('paywall_renew_days', '7'))); }

/**
 * İmza anahtarı. config.php içindeki `app_key` kullanılır — ayrı bir anahtar
 * üretmek, yayıncının yedeklemeyi/taşımayı unutabileceği ikinci bir sır demekti.
 */
function paywall_key() { return (string)cfg('app_key', ''); }

// ============================================================ imza

/** Yükü imzalar (HMAC-SHA256'nın ilk 32 hanesi — çerez kısa kalsın). */
function paywall_sign($payload) {
    $k = paywall_key();
    if ($k === '') { return ''; }
    return substr(hash_hmac('sha256', (string)$payload, $k), 0, 32);
}

/** İmzayı SABİT ZAMANLI karşılaştırır. */
function paywall_sign_ok($payload, $sig) {
    $beklenen = paywall_sign($payload);
    if ($beklenen === '' || !is_string($sig) || $sig === '') { return false; }
    return hash_equals($beklenen, $sig);
}

// ============================================================ istek belleği

/*
 * NEDEN `static` DEĞİL DE $GLOBALS
 * Sayaç, hediye bağışı ve istek yolu bir istek boyunca DEĞİŞMEZ; her sorguda
 * yeniden çözümlemek gereksizdir. Ama `static` bellek süreç boyunca yaşar ve
 * birim testlerde (tek süreçte onlarca senaryo) ile CLI cron'da geri
 * döndürülemez. Bellek burada açık bir dizide durur ve `paywall_memo_reset()`
 * ile düşürülebilir. Bu, üretimdeki davranışı değiştirmez; yalnız belleği
 * denetlenebilir kılar.
 */

/** Belleğe yazar ve değeri döndürür. */
function paywall_memo_put($anahtar, $deger) {
    if (!isset($GLOBALS['manset_paywall_memo'])) { $GLOBALS['manset_paywall_memo'] = []; }
    $GLOBALS['manset_paywall_memo'][(string)$anahtar] = $deger;
    return $deger;
}

/** İstek belleğini ve verilmiş kararları düşürür. */
function paywall_memo_reset() {
    $GLOBALS['manset_paywall_memo'] = [];
    $GLOBALS['manset_paywall_kararlar'] = [];
}

// ============================================================ istek kapısı

/**
 * Bu istek HANGİ haberin kendi sayfası? (değilse 0)
 *
 * Sayaç yalnız burada dönen kimlik için işler. Liste şablonları kart başına
 * post_reader_can_read_full() çağırırsa anasayfa tüm aylık hakkı yakardı;
 * bu kapı onu imkânsız kılar. Aynı zamanda çerezin YALNIZ kilitli haber
 * sayfasında verilmesini garanti eden yerdir (CONTRACTS §3.1 istisnası).
 */
function paywall_request_post_id() {
    if (isset($GLOBALS['manset_paywall_memo']['req'])) { return $GLOBALS['manset_paywall_memo']['req']; }
    $yol = '';
    if (isset($_GET['r']) && is_string($_GET['r'])) {
        $yol = (string)$_GET['r'];
    } elseif (!empty($_SERVER['PATH_INFO'])) {
        $yol = (string)$_SERVER['PATH_INFO'];
    } elseif (isset($_SERVER['REQUEST_URI'])) {
        $yol = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }
    $yol = trim(preg_replace('#/+#', '/', $yol), '/');
    if (!preg_match('#(?:^|/)haber/[^/]*?-(\d+)$#', $yol, $m)) { return paywall_memo_put('req', 0); }
    return paywall_memo_put('req', (int)$m[1]);
}

/** İstek yöntemi GET (ya da HEAD) mi? */
function paywall_is_read_request() {
    $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    return $m === 'GET' || $m === 'HEAD';
}

// ============================================================ çerez yardımcıları

/** Ortak çerez seçenekleri (PHP 7.3+ dizi biçimi; ön yüz kuralına uyar). */
function paywall_cookie_opts($ttl) {
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    return [
        'expires'  => $ttl > 0 ? time() + (int)$ttl : time() - 3600,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Çerezi yazar. Çıktı başladıysa YAZMAZ ve false döner.
 *
 * `session_boot()` çıktı başladıktan sonra sessizce dönüyor ve 1.1'de üç ayrı
 * hataya yol açıyordu. Burada sessizlik yok: çağıran taraf false dönüşünü
 * "hak DÜŞÜLEMEDİ" diye okur ve okuru içeri ALMAZ. Sayılamayan hak,
 * sınırsız hak demektir.
 */
function paywall_cookie_put($name, $value, $ttl) {
    if (headers_sent()) { return false; }
    setcookie((string)$name, (string)$value, paywall_cookie_opts($ttl));
    $_COOKIE[(string)$name] = (string)$value;
    return true;
}

/**
 * Yanıtı paylaşılamaz işaretler.
 *
 * render() anonim ziyaretçide `public, s-maxage=60` basar. Ölçülü hakla ya da
 * hediyeyle açılan tam metin O ZİYARETÇİYE özeldir; araya giren bir vekil onu
 * başkalarına servis ederse ödeme duvarı fiilen kalkar. Ayrıca uygulamanın
 * kendi dosya önbelleği `cache_abort()` ile üretimden çıkarılır.
 *
 * SIRA ÖNEMLİ: cache_abort() tamponları boşaltır, yani çıktı istemciye gider ve
 * headers_sent() true olur. Başlık ve çerez ONDAN ÖNCE yazılmalıdır.
 */
function paywall_mark_private() {
    $GLOBALS['manset_paywall_private'] = true;
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Vary: Cookie');
    }
    if (function_exists('cache_abort')) { cache_abort(); }
}

/**
 * Bu yanıt paylaşılamaz mı? — `render()` için bayrak.
 *
 * NEDEN GEREKLİ (ölçülerek bulundu): ön yüz kapısı `paywall_front_gate()`
 * render()'dan ÖNCE koşar ve `private, no-store` yazar; ardından render()
 * `has_session_cookie()` false gördüğü için başlığı `public, s-maxage=60` ile
 * EZER. Sonuç: ölçülü hakla açılmış TAM METİN, `Vary: Cookie` altında
 * "çerezsiz" anahtarıyla bir CDN'de 60 saniye paylaşılabilir hale gelir —
 * ödeme duvarı fiilen kalkardı. Uygulamanın kendi dosya önbelleği zaten
 * korunuyordu (Set-Cookie varsa yazmıyor), sorun ARADAKİ vekildeydi.
 *
 * Bayrağın render()'da okunması için gereken satır rapor §8'de isteniyor.
 */
function paywall_response_is_private() {
    return !empty($GLOBALS['manset_paywall_private']);
}

/**
 * Ziyaretçi ödeme duvarı çerezlerinden birini taşıyor mu?
 *
 * NEDEN GEREKLİ (ölçülerek bulundu, en sinsi hata): sayfa önbelleği
 * `cache_should_serve()` YALNIZ oturum çerezine bakar. Ölçülü sayaç çerezi
 * ona görünmez olduğu için:
 *
 *   1. Hakkı tükenmiş bir okur kilitli sayfayı ister → yanıtta Set-Cookie
 *      YOKTUR → sayfa DOSYA ÖNBELLEĞİNE YAZILIR.
 *   2. Sonra gelen, hakkı DOLU başka bir okur aynı adresi ister → önbellekten
 *      o kilitli sayfa servis edilir, `paywall_allow()` hiç çağrılmaz.
 *
 * Yani ölçülü duvar, önbellek açıkken ikinci okurdan sonra sessizce ölür.
 * Kod okunarak görünmez; çalışan kurulumda ölçülerek bulundu (rapor §5.4).
 *
 * Kapı DAR: yalnız zaten ödeme duvarı çerezi TAŞIYAN ziyaretçi önbelleği
 * atlar. Çerezsiz ziyaretçi (ve arama motoru botları) her sayfayı önbellekten
 * almaya devam eder — anasayfa/kategori önbelleği etkilenmez.
 *
 * `cache_has_session_cookie()` içinde okunması gereken satır rapor §8'de.
 */
function paywall_has_cookie() {
    return !empty($_COOKIE[PAYWALL_METER_COOKIE]) || !empty($_COOKIE[PAYWALL_GIFT_COOKIE]);
}

// ============================================================ ölçülü sayaç

/** Geçerli dönem anahtarı ('2026-08'). Ay değişince sayaç kendiliğinden sıfırlanır. */
function paywall_period() { return date('Y-m'); }

/**
 * Çerezdeki sayacı okur. İmza tutmuyorsa, dönem eskiyse ya da biçim bozuksa
 * TAZE bir sayaç döner (kurcalama kazanç sağlamaz).
 *
 * @return array ['donem' => string, 'ids' => int[]]
 */
function paywall_meter_read() {
    if (isset($GLOBALS['manset_paywall_memo']['meter'])) { return $GLOBALS['manset_paywall_memo']['meter']; }
    $bos = ['donem' => paywall_period(), 'ids' => []];

    $ham = isset($_COOKIE[PAYWALL_METER_COOKIE]) ? (string)$_COOKIE[PAYWALL_METER_COOKIE] : '';
    if ($ham === '' || strlen($ham) > 400) { return paywall_memo_put('meter', $bos); }

    $parca = explode('|', $ham);
    if (count($parca) !== 4) { return paywall_memo_put('meter', $bos); }
    $sig = array_pop($parca);
    $yuk = implode('|', $parca);
    if (!paywall_sign_ok($yuk, $sig)) { return paywall_memo_put('meter', $bos); }

    if ($parca[0] !== '1') { return paywall_memo_put('meter', $bos); }
    if ($parca[1] !== paywall_period()) { return paywall_memo_put('meter', $bos); }   // ay değişti

    $ids = [];
    foreach (explode(',', (string)$parca[2]) as $p) {
        $n = (int)$p;
        if ($n > 0 && !in_array($n, $ids, true)) { $ids[] = $n; }
    }
    if (count($ids) > PAYWALL_METER_MAX) { $ids = array_slice($ids, 0, PAYWALL_METER_MAX); }
    return paywall_memo_put('meter', ['donem' => (string)$parca[1], 'ids' => $ids]);
}

/** Sayaç değerini imzalayıp çereze yazar. Çıktı başladıysa false. */
function paywall_meter_write(array $ids) {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids);
    if (count($ids) > PAYWALL_METER_MAX) { $ids = array_slice($ids, 0, PAYWALL_METER_MAX); }
    $yuk = '1|' . paywall_period() . '|' . implode(',', $ids);
    $sig = paywall_sign($yuk);
    if ($sig === '') { return false; }
    // Çerez ayın sonundan biraz sonrasına kadar yaşasın; dönem denetimi zaten
    // içeride yapılıyor, süre yalnız temizliktir.
    if (!paywall_cookie_put(PAYWALL_METER_COOKIE, $yuk . '|' . $sig, 40 * 86400)) { return false; }
    paywall_memo_put('meter', ['donem' => paywall_period(), 'ids' => $ids]);
    return true;
}

/** Bu dönemde kalan ücretsiz hak. */
function paywall_meter_left() {
    if (!paywall_meter_on()) { return 0; }
    $st = paywall_meter_read();
    return max(0, paywall_meter_free() - count($st['ids']));
}

// ============================================================ hediye — bağış çerezi

/**
 * Tüketilmiş hediyeler tarayıcıda kısa süreli bir bağış çerezinde tutulur.
 *
 * NEDEN ÇEREZ: token tek kullanımlıktır ve tüketildiği anda veritabanında
 * ölür. Okur sayfayı yenilediğinde, yazıyı yarıda bırakıp geri döndüğünde ya
 * da adresi paylaştığında token artık geçersizdir. Bağış çerezi olmasaydı
 * hediye "bir tıklık" olurdu ve okur yazının ortasında kapıda kalırdı.
 * Çerez imzalıdır; okur kendi kendine haber ekleyemez.
 */
function paywall_gift_grants() {
    if (isset($GLOBALS['manset_paywall_memo']['gift'])) { return $GLOBALS['manset_paywall_memo']['gift']; }
    $ham = isset($_COOKIE[PAYWALL_GIFT_COOKIE]) ? (string)$_COOKIE[PAYWALL_GIFT_COOKIE] : '';
    if ($ham === '' || strlen($ham) > 400) { return paywall_memo_put('gift', []); }

    $parca = explode('|', $ham);
    if (count($parca) !== 4) { return paywall_memo_put('gift', []); }
    $sig = array_pop($parca);
    $yuk = implode('|', $parca);
    if (!paywall_sign_ok($yuk, $sig)) { return paywall_memo_put('gift', []); }
    if ($parca[0] !== '1') { return paywall_memo_put('gift', []); }
    if ((int)$parca[1] < time()) { return paywall_memo_put('gift', []); }   // süresi dolmuş

    $ids = [];
    foreach (explode(',', (string)$parca[2]) as $p) {
        $n = (int)$p;
        if ($n > 0 && !in_array($n, $ids, true)) { $ids[] = $n; }
    }
    return paywall_memo_put('gift', $ids);
}

/** Bağış çerezine haber ekler (hediye tüketildiğinde, ÇIKTIDAN ÖNCE çağrılır). */
function paywall_gift_grant_add($postId) {
    $postId = (int)$postId;
    if ($postId <= 0) { return false; }
    $ids = paywall_gift_grants();
    if (!in_array($postId, $ids, true)) { $ids[] = $postId; }
    if (count($ids) > 20) { $ids = array_slice($ids, -20); }
    $son = time() + PAYWALL_GIFT_GRANT_TTL;
    $yuk = '1|' . $son . '|' . implode(',', $ids);
    $sig = paywall_sign($yuk);
    if ($sig === '') { return false; }
    if (!paywall_cookie_put(PAYWALL_GIFT_COOKIE, $yuk . '|' . $sig, PAYWALL_GIFT_GRANT_TTL)) { return false; }
    paywall_memo_put('gift', $ids);
    return true;
}

// ============================================================ hediye — token

/** Hediye tabloları kuruldu mu? */
function paywall_tables_ready() {
    static $var = null;
    if ($var !== null) { return $var; }
    try {
        qv('SELECT COUNT(*) FROM gift_tokens', [], 0);
        qv('SELECT COUNT(*) FROM paywall_trials', [], 0);
        return $var = true;
    } catch (Throwable $e) { return $var = false; }
}

/** Hediye bağlantısının adresi. */
function paywall_gift_url($token) { return base_url() . '/hediye.php?t=' . rawurlencode((string)$token); }

/** Bir abonenin bu ay kullandığı hediye sayısı. */
function paywall_gift_used($userId) {
    if (!paywall_tables_ready()) { return 0; }
    try {
        return (int)qv('SELECT COUNT(*) FROM gift_tokens WHERE giver_id = :u AND period_key = :p',
            [':u' => (int)$userId, ':p' => paywall_period()], 0);
    } catch (Throwable $e) { return 0; }
}

/**
 * Hediye bağlantısı üretir.
 *
 * HAM TOKEN YALNIZ DÖNÜŞ DEĞERİDİR — veritabanına `hash('sha256', $ham)` yazılır
 * (inc/members.php member_token_create() ile aynı desen). Veritabanı yedeği
 * sızsa bile hiçbir hediye bağlantısı yeniden üretilemez.
 *
 * @return array ['ok'=>bool, 'token'?, 'url'?, 'expires_at'?, 'error'?]
 */
/**
 * İstisna bir BENZERSİZLİK ihlali mi?
 *
 * Sürücüler farklı konuşur: SQLite "UNIQUE constraint failed", MySQL 1062
 * "Duplicate entry". PDO'nun SQLSTATE'i ikisinde de 23000'dir, ama 23000 tüm
 * bütünlük ihlallerini kapsar; metne de bakılır ki yabancı anahtar hatası
 * "yarış" sanılıp sonsuz döngüye girilmesin.
 */
function paywall_is_unique_violation(Throwable $e) {
    if ($e instanceof PDOException) {
        $kod = (string)$e->getCode();
        if ($kod !== '23000' && $kod !== '23505') { return false; }
    }
    $m = strtolower($e->getMessage());
    return strpos($m, 'unique') !== false
        || strpos($m, 'duplicate') !== false
        || strpos($m, '1062') !== false;
}

function paywall_gift_create($userId, $postId) {
    $userId = (int)$userId;
    $postId = (int)$postId;
    if (!paywall_gift_on())      { return ['ok' => false, 'error' => 'Hediye bağlantısı kapalı.']; }
    if (!paywall_tables_ready()) { return ['ok' => false, 'error' => 'Ödeme duvarı tabloları kurulmadı.']; }
    if ($userId <= 0)            { return ['ok' => false, 'error' => 'Giriş yapmanız gerekiyor.']; }

    // Hediye edebilmek ABONE olmayı gerektirir: ücretsiz üye sınırsız hediye
    // üretebilseydi ödeme duvarı hediye ucundan tamamen delinirdi.
    $veren = q1('SELECT id, role FROM users WHERE id = :i AND active = 1', [':i' => $userId]);
    if (!$veren) { return ['ok' => false, 'error' => 'Üye bulunamadı.']; }

    if (!function_exists('member_tier') || member_tier($veren) !== 'premium') {
        return ['ok' => false, 'error' => 'Hediye bağlantısı yalnız aboneler içindir.'];
    }

    $kota = paywall_gift_monthly();
    if ($kota <= 0) { return ['ok' => false, 'error' => 'Hediye bağlantısı kapalı.']; }
    if (paywall_gift_used($userId) >= $kota) {
        return ['ok' => false, 'error' => 'Bu ayki hediye hakkınız doldu.'];
    }

    // Haber gerçekten yayımda ve gerçekten kilitli olmalı: açık bir habere
    // hediye üretmek hakkı boşa yakardı.
    $post = q1('SELECT id, visibility, status FROM posts WHERE id = :i', [':i' => $postId]);
    if (!$post) { return ['ok' => false, 'error' => 'Haber bulunamadı.']; }
    if ((string)$post['status'] !== 'published') { return ['ok' => false, 'error' => 'Haber yayımda değil.']; }
    $vis = (string)arr($post, 'visibility', 'public');
    if ($vis !== 'members' && $vis !== 'premium') {
        return ['ok' => false, 'error' => 'Bu haber zaten herkese açık.']; }

    $token = bin2hex(random_bytes(32));
    $son = date('Y-m-d H:i:s', time() + paywall_gift_ttl_hours() * 3600);

    // KOTAYI VERİTABANI UYGULAR (denetim turu 4, O-01).
    // Yukarıdaki `paywall_gift_used()` denetimi kullanıcıya DÜZGÜN BİR HATA
    // MESAJI vermek içindir; kotanın kendisi ona GÜVENMEZ. Sor-sonra-yaz
    // arasındaki boşlukta ikinci bir istek aynı cevabı alıyordu: denetçi kota 2
    // iken 10 eşzamanlı istekle 10 bağlantı üretti. (Ardışık isteklerde ve
    // yerleşik sunucuda hata GÖRÜNMÜYOR — istekler sıraya giriyor.)
    //
    // Her hediye bir `slot` alır ve (giver_id, period_key, slot) benzersizdir.
    // İki istek aynı slotu hesaplarsa ikincisinin INSERT'i veritabanınca
    // reddedilir. Çakışma NORMALDİR (yarış demektir), o yüzden bir sonraki boş
    // slot denenir; kota dolduğunda döngü biter ve hak verilmez.
    $donem = paywall_period();
    $yazildi = false;
    $baslangic = (int)paywall_gift_used($userId);

    for ($slot = $baslangic; $slot < $kota; $slot++) {
        try {
            db_insert('gift_tokens', [
                'token_hash' => hash('sha256', $token),
                'post_id'    => $postId,
                'giver_id'   => $userId,
                'period_key' => $donem,
                'slot'       => $slot,
                'used_at'    => null,
                'expires_at' => $son,
                'created_at' => now(),
            ]);
            $yazildi = true;
            break;
        } catch (Throwable $e) {
            // Benzersizlik çakışması → o slot kapılmış, sonrakini dene.
            // Başka bir hata ise (ör. `slot` sütunu yok — göç 022 uygulanmamış)
            // döngüde ısrar etmek anlamsız: bırak ve hatayı bildir.
            if (!paywall_is_unique_violation($e)) {
                log_error('paywall_gift_create: ' . $e->getMessage());
                return ['ok' => false, 'error' => 'Hediye bağlantısı üretilemedi.'];
            }
        }
    }

    if (!$yazildi) {
        return ['ok' => false, 'error' => 'Bu ayki hediye hakkınız doldu.'];
    }

    return ['ok' => true, 'token' => $token, 'url' => paywall_gift_url($token),
            'expires_at' => $son, 'kalan' => max(0, $kota - paywall_gift_used($userId))];
}

/**
 * Hediye token'ını TÜKETİR. Tek kullanımlıktır.
 *
 * YARIŞ DURUMU — NASIL ÖNLENİYOR
 * "Önce SELECT (kullanılmamış mı?), sonra UPDATE" iki eşzamanlı istekte ikisine
 * birden 'kullanılmamış' der ve token iki kez tüketilir. Burada karar TEK
 * DEYİMDE verilir:
 *
 *   UPDATE gift_tokens SET used_at = :n WHERE token_hash = :h
 *     AND (used_at IS NULL OR used_at = '') AND expires_at >= :n2
 *
 * Bu deyim veritabanı düzeyinde atomiktir (SQLite'ta satır kilidi, MySQL/InnoDB'de
 * satır kilidi). `rowCount() === 1` olan İSTEK tek bir tanedir; ikincisi 0 alır ve
 * reddedilir. Aynı desen Ajan-C'nin T4/T17 karşılığında da kullanılıyor.
 *
 * @return array ['ok'=>bool, 'post_id'?, 'error'?]
 */
function paywall_gift_redeem($token) {
    $token = (string)$token;
    if (!paywall_gift_on())      { return ['ok' => false, 'error' => 'Hediye bağlantısı kapalı.']; }
    if (!paywall_tables_ready()) { return ['ok' => false, 'error' => 'Ödeme duvarı tabloları kurulmadı.']; }
    if (!preg_match('/^[a-f0-9]{64}$/i', $token)) { return ['ok' => false, 'error' => 'Bağlantı geçersiz.']; }

    $hash = hash('sha256', $token);
    $simdi = now();
    try {
        $st = q('UPDATE gift_tokens SET used_at = :n
                 WHERE token_hash = :h
                   AND (used_at IS NULL OR used_at = \'\')
                   AND (expires_at IS NULL OR expires_at >= :n2)',
                [':n' => $simdi, ':h' => $hash, ':n2' => $simdi]);
        $etkilenen = $st->rowCount();
    } catch (Throwable $e) {
        log_error('paywall_gift_redeem: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Bağlantı kullanılamadı.'];
    }

    if ($etkilenen !== 1) {
        return ['ok' => false, 'error' => 'Bu hediye bağlantısı kullanılmış ya da süresi dolmuş.'];
    }

    // Kimliği ANCAK tüketim başarılıysa okuruz: sıra tersine dönerse
    // "önce oku sonra tüket" desenine geri düşülür.
    try {
        $row = q1('SELECT post_id FROM gift_tokens WHERE token_hash = :h', [':h' => $hash]);
    } catch (Throwable $e) { $row = null; }
    if (!$row) { return ['ok' => false, 'error' => 'Bağlantı geçersiz.']; }

    return ['ok' => true, 'post_id' => (int)$row['post_id']];
}

// ============================================================ deneme

/**
 * Üyenin ETKİN denemesi var mı?
 * Süre okuma anında değerlendirilir; cron kurulmamış olabilir.
 */
function paywall_trial_of($user) {
    if (!is_array($user)) { return null; }
    $id = (int)arr($user, 'id', 0);
    if ($id <= 0 || !paywall_tables_ready()) { return null; }
    if (isset($GLOBALS['manset_paywall_memo']['trial'])
        && array_key_exists($id, $GLOBALS['manset_paywall_memo']['trial'])) {
        return $GLOBALS['manset_paywall_memo']['trial'][$id];
    }
    try {
        $row = q1('SELECT user_id, days, started_at, ends_at FROM paywall_trials WHERE user_id = :u', [':u' => $id]);
    } catch (Throwable $e) { $row = null; }
    $GLOBALS['manset_paywall_memo']['trial'][$id] = ($row ?: null);
    return $GLOBALS['manset_paywall_memo']['trial'][$id];
}

/** Deneme şu anda geçerli mi? */
function paywall_trial_active($user) {
    $row = paywall_trial_of($user);
    if (!$row) { return false; }
    $son = trim((string)arr($row, 'ends_at', ''));
    return $son !== '' && strtotime($son) >= time();
}

/**
 * Denemeyi başlatır. HESAP BAŞINA BİR KEZ.
 *
 * Tekillik uygulama katmanında değil, `paywall_trials.user_id` BENZERSİZ
 * indeksinde durur: iki eşzamanlı istek gelirse ikincisinin INSERT'i
 * veritabanı düzeyinde reddedilir.
 */
function paywall_trial_start($userId) {
    $userId = (int)$userId;
    $gun = paywall_trial_days();
    if ($gun <= 0)               { return ['ok' => false, 'error' => 'Deneme süresi kapalı.']; }
    if (!paywall_tables_ready()) { return ['ok' => false, 'error' => 'Ödeme duvarı tabloları kurulmadı.']; }
    if ($userId <= 0)            { return ['ok' => false, 'error' => 'Giriş yapmanız gerekiyor.']; }

    $u = q1('SELECT id FROM users WHERE id = :i AND active = 1 AND role = \'member\'', [':i' => $userId]);
    if (!$u) { return ['ok' => false, 'error' => 'Üye bulunamadı.']; }
    // E-POSTA DOĞRULAMASI ZORUNLU (denetim turu 4, Y-01).
    // Kayıt hiçbir doğrulama istemiyor ve hesap giriş anında açılıyordu;
    // denetçi tek IP'den bir dakikada dört sahte hesapla (`.invalid` alan adı)
    // dört kez 7 günlük tam erişim aldı. Deneme, ölçülü duvarı da hediye
    // kotasını da abonelik ücretini de tümüyle atlatan tek kapıydı — çünkü
    // diğerleri SAYIYOR, bu ise KİMLİĞE güveniyor. Doğrulanmamış bir e-posta
    // kimlik değildir; sınırsız üretilebilir.
    if (function_exists('member_is_verified') && !member_is_verified($userId)) {
        return ['ok' => false, 'error' => 'Denemeyi başlatmak için önce e-posta adresinizi doğrulayın.'];
    }

    // Zaten abone olan denemeyi harcamasın.
    if (function_exists('member_tier') && member_tier(['id' => $userId]) === 'premium') {
        return ['ok' => false, 'error' => 'Aboneliğiniz zaten etkin.'];
    }

    $son = date('Y-m-d H:i:s', time() + $gun * 86400);
    try {
        db_insert('paywall_trials', [
            'user_id'    => $userId,
            'days'       => $gun,
            'source'     => 'self',
            'started_at' => now(),
            'ends_at'    => $son,
        ]);
    } catch (Throwable $e) {
        // BENZERSİZ indeks çarptı → deneme daha önce kullanılmış.
        return ['ok' => false, 'error' => 'Deneme hakkınızı daha önce kullandınız.'];
    }

    return ['ok' => true, 'ends_at' => $son, 'days' => $gun];
}

// ============================================================ KARAR

/**
 * Ölçülü duvarın kararı (yan etkisiz).
 *
 * @return array ['allow'=>bool, 'neden'=>string, 'ids'=>int[], 'yaz'=>bool, 'ozel'=>bool]
 *   'yaz'  → sayaç çerezi yazılmalı (yeni bir hak düşülüyor)
 *   'ozel' → yanıt paylaşılamaz işaretlenmeli (tam metin bu ziyaretçiye özel)
 */
function paywall_decide($post, $user) {
    $hayir = ['allow' => false, 'neden' => 'kilitli', 'ids' => [], 'yaz' => false, 'ozel' => false];
    if (!is_array($post)) { return $hayir; }
    $id = (int)arr($post, 'id', 0);
    if ($id <= 0) { return $hayir; }
    $vis = (string)arr($post, 'visibility', 'public');
    if ($vis !== 'members' && $vis !== 'premium') { return $hayir; }

    // 1) HEDİYE — bağış çerezi bu haberi taşıyor mu?
    if (paywall_gift_on() && in_array($id, paywall_gift_grants(), true)) {
        return ['allow' => true, 'neden' => 'hediye', 'ids' => [], 'yaz' => false, 'ozel' => true];
    }

    // 2) DENEME — kimliğe bağlı, sayaca dokunmaz.
    if (paywall_trial_active($user)) {
        return ['allow' => true, 'neden' => 'deneme', 'ids' => [], 'yaz' => false, 'ozel' => true];
    }

    // 3) ÖLÇÜLÜ SAYAÇ
    if (!paywall_meter_on()) { return $hayir; }
    if (!in_array($vis, paywall_meter_scope(), true)) { return $hayir; }

    $st = paywall_meter_read();

    // Aynı haber bu ay zaten sayıldıysa hak yakılmaz (yenileme, geri dönme).
    if (in_array($id, $st['ids'], true)) {
        return ['allow' => true, 'neden' => 'olculu-tekrar', 'ids' => $st['ids'], 'yaz' => false, 'ozel' => true];
    }

    if (count($st['ids']) >= paywall_meter_free()) {
        // Bu KİLİT kararı okurun çerezine bağlıdır: hakkı dolu bir başkası aynı
        // adreste TAM METNİ görecekti. Yanıt paylaşılamaz — yoksa bu kilitli
        // sayfa önbelleğe yazılır ve hakkı olan okurlara da servis edilir.
        // (Liste bağlamında buraya gelinmez; oraya 'sayfa-disi' düşer.)
        $kendiSayfasi = paywall_is_read_request() && paywall_request_post_id() === $id;
        return ['allow' => false, 'neden' => 'olculu-bitti', 'ids' => $st['ids'],
                'yaz' => false, 'ozel' => $kendiSayfasi];
    }

    // YENİ HAK DÜŞÜLÜYOR — yalnız haberin KENDİ sayfasında.
    // Liste/besleme bağlamında sayaç işlemez ve çerez verilmez (CONTRACTS §3.1).
    if (!paywall_is_read_request() || paywall_request_post_id() !== $id) {
        return ['allow' => false, 'neden' => 'sayfa-disi', 'ids' => $st['ids'], 'yaz' => false, 'ozel' => false];
    }

    $yeni = $st['ids'];
    $yeni[] = $id;
    return ['allow' => true, 'neden' => 'olculu', 'ids' => $yeni, 'yaz' => true, 'ozel' => true];
}

/**
 * Kararı UYGULAR: çerezi yazar, yanıtı paylaşılamaz işaretler.
 *
 * Çerez yazılamıyorsa (çıktı başlamış) karar OLUMSUZA çevrilir. Sayılamayan
 * hak sınırsız haktır; güvenli taraf kilittir.
 */
function paywall_apply(array $karar) {
    // Kilit kararı da kişiye özel olabilir (bkz. 'olculu-bitti').
    if (!$karar['allow']) {
        if (!empty($karar['ozel'])) { paywall_mark_private(); }
        return $karar;
    }
    if (!empty($karar['yaz'])) {
        if (!paywall_meter_write($karar['ids'])) {
            $karar['allow'] = false;
            $karar['neden'] = 'sayac-yazilamadi';
            return $karar;
        }
    }
    if (!empty($karar['ozel'])) { paywall_mark_private(); }
    return $karar;
}

/**
 * ÇIKTIDAN ÖNCE çağrılan ön yüz kapısı.
 *
 * index.php haber yolunda render()'dan HEMEN ÖNCE çağrılmalıdır (bkz. rapor §8
 * "orkestratörden istekler"). Kararı burada vermek üç şeyi garanti eder:
 *   · setcookie() gerçekten çalışır (çıktı henüz başlamamıştır),
 *   · `Cache-Control: private, no-store` render()'ın başlığını ezebilir,
 *   · karar bir kez verilir, şablon kaç kez sorarsa sorsun değişmez.
 *
 * Çağrılmazsa modül yine çalışır ama karar şablon içinde (çıktı sırasında)
 * verilir; o noktada çerez ancak sayfa önbelleği açıkken (ob_start etkin)
 * yazılabilir. Bkz. rapor "kabul edilen sınırlar".
 */
function paywall_front_gate($post) {
    if (!is_array($post)) { return; }
    $id = (int)arr($post, 'id', 0);
    if ($id <= 0) { return; }
    // Kimlik zaten yetiyorsa ölçülü duvara hiç girilmez.
    if (function_exists('post_can_read_full')
        && post_can_read_full($post, current_user_if_session())) { return; }
    paywall_allow($post, current_user_if_session());
}

/**
 * KAYIT NOKTASI (CONTRACTS §14.2) — `post_reader_can_read_full()` bunu çağırır.
 *
 * Karar haber başına bir kez verilir ve memoize edilir: şablon aynı haberi
 * ikinci kez sorduğunda ikinci bir hak DÜŞÜLMEZ.
 */
function paywall_allow($post, $user) {
    if (!is_array($post)) { return false; }
    $id = (int)arr($post, 'id', 0);
    if ($id <= 0) { return false; }

    if (!isset($GLOBALS['manset_paywall_kararlar'])) { $GLOBALS['manset_paywall_kararlar'] = []; }
    if (isset($GLOBALS['manset_paywall_kararlar'][$id])) {
        return (bool)$GLOBALS['manset_paywall_kararlar'][$id]['allow'];
    }

    $karar = paywall_apply(paywall_decide($post, $user));
    $GLOBALS['manset_paywall_kararlar'][$id] = $karar;
    return (bool)$karar['allow'];
}

/** Bir haber için verilmiş kararı döndürür (kilit kutusu metni için). */
function paywall_reason($postId) {
    $postId = (int)$postId;
    if (isset($GLOBALS['manset_paywall_kararlar'][$postId])) {
        return (string)$GLOBALS['manset_paywall_kararlar'][$postId]['neden'];
    }
    return '';
}

// ============================================================ kilit kutusu

/**
 * Kilit kutusu HTML'i. `inc/view.php` içindeki post_locked_html() buna
 * devredebilir (bkz. rapor §8). Boş dönerse çekirdeğin kendi kutusu basılır.
 */
function paywall_locked_html($post) {
    if (!is_array($post) || !function_exists('part_capture')) { return ''; }
    if (!paywall_meter_on() && !paywall_gift_on() && paywall_trial_days() <= 0) { return ''; }

    $vis = (string)arr($post, 'visibility', 'public');
    $uye = function_exists('current_user_if_session') ? current_user_if_session() : null;

    $html = part_capture('kilit-kutu', [
        'post'      => $post,
        'vis'       => $vis,
        'uye'       => $uye,
        'kalan'     => paywall_meter_left(),
        'toplam'    => paywall_meter_free(),
        'olculu'    => paywall_meter_on() && in_array($vis, paywall_meter_scope(), true),
        'deneme'    => paywall_trial_days(),
        'denemeVar' => paywall_trial_of($uye) !== null,
        'neden'     => paywall_reason((int)arr($post, 'id', 0)),
    ]);
    return trim($html);
}

/**
 * Aboneye gösterilecek "bu haberi hediye et" formu.
 * Temalar (ya da single.php) basmak isterse hazır: JS gerektirmez,
 * ön yüz kuralına uyar (csrf_field_lazy → oturum açmaz).
 */
function paywall_gift_form_html($post, $user) {
    if (!paywall_gift_on() || !is_array($post)) { return ''; }
    $id = (int)arr($post, 'id', 0);
    if ($id <= 0) { return ''; }
    if (!is_array($user) || !function_exists('member_tier') || member_tier($user) !== 'premium') { return ''; }
    $kalan = max(0, paywall_gift_monthly() - paywall_gift_used((int)$user['id']));
    $csrf = function_exists('csrf_field_lazy') ? csrf_field_lazy() : '';
    return '<form class="hediye-form" method="post" action="' . esc(base_url() . '/hediye.php') . '">'
         . $csrf
         . '<input type="hidden" name="haber" value="' . (int)$id . '">'
         . '<button type="submit" class="dugme ghost">Bu haberi hediye et</button> '
         . '<small>Bu ay kalan hediye hakkınız: ' . (int)$kalan . '</small>'
         . '</form>';
}

// ============================================================ yenileme hatırlatması

/**
 * Bitişe yaklaşan aboneliklere TEK TEK işlemsel e-posta gönderir.
 *
 * KAPSAM DIŞI: toplu bülten gönderimi (CONTRACTS §13). Burada gönderilen şey
 * pazarlama değil, kullanıcının kendi sözleşmesinin bitişine dair İŞLEMSEL
 * bildirimdir ve alıcı listesi yoktur — her satır kendi kaydından gelir.
 *
 * ÇİFT GÖNDERİM KALKANI: (user_id, kind, period_key) BENZERSİZDİR ve
 * `period_key` aboneliğin BİTİŞ TARİHİDİR. Kayıt ÖNCE açılır (talep edilir),
 * e-posta SONRA gönderilir; gönderim başarısızsa kayıt geri alınır ki bir
 * sonraki tur yeniden denesin. Ters sıra (önce gönder sonra yaz) cron iki kez
 * koştuğunda okura iki e-posta demekti.
 */
function paywall_renew_tick($limit = 20) {
    $sonuc = ['bakilan' => 0, 'gonderilen' => 0, 'atlanan' => 0, 'hata' => 0];
    if (!paywall_renew_on() || !paywall_tables_ready()) { return $sonuc; }
    if (!function_exists('member_mail_send') || !member_mail_available()) { return $sonuc; }

    $simdi = now();
    $ust = date('Y-m-d H:i:s', time() + paywall_renew_days() * 86400);
    try {
        $satirlar = qa('SELECT u.id AS uid, u.name AS uad, u.email AS ueposta, m.valid_until AS bitis
                        FROM memberships m
                        INNER JOIN users u ON u.id = m.user_id
                        WHERE m.tier = \'premium\'
                          AND u.active = 1
                          AND u.role = \'member\'
                          AND m.valid_until IS NOT NULL
                          AND m.valid_until <> \'\'
                          AND m.valid_until >= :simdi
                          AND m.valid_until <= :ust
                        ORDER BY m.valid_until ASC
                        LIMIT ' . (int)max(1, min(200, (int)$limit)),
                       [':simdi' => $simdi, ':ust' => $ust]);
    } catch (Throwable $e) {
        log_error('paywall_renew_tick: ' . $e->getMessage());
        return $sonuc;
    }

    foreach ($satirlar as $s) {
        $sonuc['bakilan']++;
        $uid = (int)$s['uid'];
        $bitis = (string)$s['bitis'];

        // TALEP: benzersiz indeks çarparsa bu dönem için zaten gönderilmiştir.
        try {
            $notice = db_insert('paywall_notices', [
                'user_id'    => $uid,
                'kind'       => 'renew',
                'period_key' => $bitis,
                'sent_at'    => now(),
            ]);
        } catch (Throwable $e) { $sonuc['atlanan']++; continue; }

        $gonderim = paywall_send_renew_mail($uid, (string)$s['uad'], (string)$s['ueposta'], $bitis);
        if (empty($gonderim['ok'])) {
            // Gönderilemedi → talebi geri al ki bir sonraki tur yeniden denesin.
            try { q('DELETE FROM paywall_notices WHERE id = :i', [':i' => (int)$notice]); }
            catch (Throwable $e) { /* yok say */ }
            $sonuc['hata']++;
            continue;
        }
        $sonuc['gonderilen']++;
    }
    return $sonuc;
}

/** Tek bir yenileme hatırlatma e-postası (işlemsel, düz metin). */
function paywall_send_renew_mail($userId, $ad, $eposta, $bitis) {
    if (!function_exists('member_mail_send')) { return ['ok' => false, 'error' => 'E-posta modülü yok.']; }
    $site = setting('site_title', 'Manşet');
    $ad = trim((string)$ad);
    if ($ad === '') { $ad = 'Merhaba'; }
    $govde = $ad . ",\n\n"
           . $site . ' aboneliğiniz ' . tr_date($bitis, false) . " tarihinde sona eriyor.\n\n"
           . "Kesintisiz okumaya devam etmek için aboneliğinizi yenileyebilirsiniz:\n\n"
           . base_url() . "/hesap.php?s=abonelik\n\n"
           . "Bu bir bilgilendirme iletisidir; yenilemek istemiyorsanız bir şey yapmanıza gerek yok.\n\n"
           . $site . "\n";
    return member_mail_send((string)$eposta, $site . ' — aboneliğiniz yakında bitiyor', $govde);
}

/** Süresi geçmiş hediye kayıtlarını temizler (30 gün sonra). */
function paywall_gift_prune() {
    if (!paywall_tables_ready()) { return 0; }
    $esik = date('Y-m-d H:i:s', time() - 30 * 86400);
    try {
        $st = q('DELETE FROM gift_tokens WHERE expires_at IS NOT NULL AND expires_at <> \'\' AND expires_at < :e',
                [':e' => $esik]);
        return $st->rowCount();
    } catch (Throwable $e) { return 0; }
}

/** Cron görevi: hatırlatma + temizlik. */
function paywall_cron_tick() {
    $silinen = paywall_gift_prune();
    $r = paywall_renew_tick(20);
    return ['gift_pruned' => $silinen] + $r;
}

// Ağa çıkmaz ama mail() gönderir → UCUZ DEĞİL.
// `ucuz` görevler bağlantı kapatılamayan SAPI'de de koşar; SMTP bekleyen bir
// mail() çağrısı orada panel isteğini kilitleyebilir.
cron_register('duvar', 'paywall_cron_tick', false);
