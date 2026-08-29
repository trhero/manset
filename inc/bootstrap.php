<?php
/**
 * Manşet — çekirdek önyükleme
 * Tüm giriş noktaları (index.php, api.php, admin, cron, install) bu dosyayı yükler.
 * Kural: framework yok, composer yok. Yalnız PDO + prepared statement.
 */

if (defined('MANSET_BOOTSTRAPPED')) { return; }
define('MANSET_BOOTSTRAPPED', true);
define('MANSET_VERSION', '1.4.1');
define('ROOT_DIR', dirname(__DIR__));
define('INC_DIR', ROOT_DIR . '/inc');
define('DB_DIR', ROOT_DIR . '/db');
define('UPLOAD_DIR', ROOT_DIR . '/uploads');
define('CACHE_DIR', UPLOAD_DIR . '/cache');
define('THEMES_DIR', ROOT_DIR . '/themes');

// ---------------------------------------------------------------- mb polyfill
// Paylaşımlı hostinglerde mbstring kapalı olabilir; asgari yedek uygulamalar.
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) {
        $a = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        $a = array_slice($a, $start, $length === null ? null : $length);
        return implode('', $a);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) {
        return count(preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY));
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtolower((string)$s); }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper($s, $enc = null) { return strtoupper((string)$s); }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos($h, $n, $o = 0, $enc = null) { return strpos((string)$h, (string)$n, $o); }
}
if (!function_exists('mb_str_split')) {
    function mb_str_split($s, $len = 1, $enc = null) {
        $a = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return array_map(function ($c) { return implode('', $c); }, array_chunk($a, max(1, $len)));
    }
}
if (function_exists('mb_internal_encoding')) { @mb_internal_encoding('UTF-8'); }

// ---------------------------------------------------------------- hata günlüğü
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

/** Hata satırını db/error.log dosyasına yazar (dosya erişilemezse sessiz geçer). */
/**
 * Günlük satırından SIRLARI temizler.
 *
 * NEDEN: istisna mesajları çağıranın verdiği metni yankılayabilir. PDO bağlantı
 * hatası DSN'i (host, kullanıcı) döndürür; bir HTTP istemcisi hata gövdesinde
 * `Authorization` başlığını ya da adresteki belirteci taşıyabilir. Günlük
 * dosyası yedeğe girer ve yedek 1.2'den beri FTP ile SİTE DIŞINA gidiyor —
 * yani günlüğe sızan bir sır, üçüncü bir sunucuya kopyalanır.
 *
 * Kapsam dürüstçe: bu bir tarama, kanıt değil. Bilinen desenleri maskeler;
 * tanımadığı bir biçim geçebilir. Asıl kural, sırrı hata mesajına HİÇ
 * KOYMAMAKTIR — bu yalnız son savunma.
 */
function log_scrub($text) {
    $t = (string)$text;
    $desen = [
        // Sağlayıcı anahtarları (OpenAI, Google, GitHub, Slack)
        '/(sk|rk)-[A-Za-z0-9_\-]{16,}/',
        '/AIza[A-Za-z0-9_\-]{20,}/',
        '/gh[pousr]_[A-Za-z0-9]{20,}/',
        '/xox[baprs]-[A-Za-z0-9\-]{10,}/',
        // Anahtar=değer biçimindeki sırlar
        '/((?:pass|passwd|password|parola|secret|token|api[_-]?key|salt|authorization)\s*[=:]\s*)(\S+)/i',
        // Şifreli ayar damgası (zaten şifreli ama günlükte durmasın)
        '/enc:v1:[A-Za-z0-9+\/=]{16,}/',
        // Bağlantı dizesindeki parola:  ://kullanici:PAROLA@
        '/(:\/\/[^:\/\s]+:)([^@\s]+)(@)/',
    ];
    $yerine = ['[SIR]', '[SIR]', '[SIR]', '[SIR]', '$1[SIR]', '[SIR]', '$1[SIR]$3'];
    $t = preg_replace($desen, $yerine, $t);
    return $t === null ? (string)$text : $t;
}

function log_error($message, $context = '') {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . log_scrub(trim((string)$message));
    if ($context !== '') {
        $line .= ' | ' . log_scrub(is_scalar($context) ? (string)$context : (string)json_encode($context, JSON_UNESCAPED_UNICODE));
    }
    $file = DB_DIR . '/error.log';
    if (!is_dir(DB_DIR)) { @mkdir(DB_DIR, 0755, true); }
    // 2 MB üstünde dosyayı devir
    if (is_file($file) && @filesize($file) > 2097152) { @rename($file, $file . '.1'); }
    @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) { return false; }
    log_error('PHP[' . $no . '] ' . $str, basename((string)$file) . ':' . $line);
    return false;
});
set_exception_handler(function ($e) {
    log_error('UNCAUGHT ' . get_class($e) . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    echo '<!doctype html><meta charset="utf-8"><title>Sunucu hatası</title>'
       . '<div style="font:16px system-ui;padding:40px;max-width:640px;margin:auto">'
       . '<h1>Bir hata oluştu</h1><p>İşlem tamamlanamadı. Yönetici günlüğü inceleyebilir.</p></div>';
    exit;
});

// ---------------------------------------------------------------- yapılandırma
$GLOBALS['manset_config'] = [];
if (is_file(ROOT_DIR . '/config.php')) {
    $mansetLoadedConfig = require ROOT_DIR . '/config.php';
    if (is_array($mansetLoadedConfig)) { $GLOBALS['manset_config'] = $mansetLoadedConfig; }
}
define('MANSET_INSTALLED', is_file(ROOT_DIR . '/config.php') && is_file(ROOT_DIR . '/install/.locked'));

/** config.php içindeki değeri okur. */
function cfg($key, $default = null) {
    return array_key_exists($key, $GLOBALS['manset_config']) ? $GLOBALS['manset_config'][$key] : $default;
}

// ---------------------------------------------------------------- veritabanı
/**
 * Tekil PDO bağlantısı. SQLite'ta WAL + busy_timeout uygulanır.
 *
 * $reset = true: bağlantıyı DÜŞÜRÜR ve null döner. Yalnız yapılandırma
 * istek ortasında değiştiğinde gerekir — pratikte tek yer kurulum
 * sihirbazıdır: yapılandırmayı yazmadan önce bir şey veritabanına
 * dokunmuşsa bağlantı VARSAYILAN yola kilitlenmiş olur ve şema yanlış
 * dosyaya kurulur (1.1'de tam olarak bu yaşandı).
 */
function db($reset = false) {
    static $pdo = null;
    if ($reset) { $pdo = null; return null; }
    if ($pdo instanceof PDO) { return $pdo; }
    $driver = cfg('db_driver', 'sqlite');
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if ($driver === 'mysql') {
        $dsn = 'mysql:host=' . cfg('db_host', 'localhost')
             . ';port=' . (int)cfg('db_port', 3306)
             . ';dbname=' . cfg('db_name', '')
             . ';charset=utf8mb4';
        $pdo = new PDO($dsn, (string)cfg('db_user', ''), (string)cfg('db_pass', ''), $opts);
        $pdo->exec('SET sql_mode = \'NO_ENGINE_SUBSTITUTION\'');
    } else {
        $path = cfg('db_path', DB_DIR . '/manset.sqlite');
        if (!is_dir(dirname($path))) { @mkdir(dirname($path), 0755, true); }
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/** Aktif sürücü adı: 'sqlite' | 'mysql' */
function db_driver() { return cfg('db_driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite'; }

/** Bağlantıyı düşürür (bkz. db($reset)). */
function db_reset() { db(true); }

/** Sorgu çalıştırır, PDOStatement döndürür. Parametreler daima bağlanır. */
function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
/** Tek satır döndürür ya da null. */
function q1($sql, $params = []) { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
/** Tüm satırları döndürür. */
function qa($sql, $params = []) { return q($sql, $params)->fetchAll(); }
/** İlk sütunun ilk değerini döndürür. */
function qv($sql, $params = [], $default = null) {
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? $default : $v;
}
/** INSERT sonrası son id. */
function last_id() { return (int)db()->lastInsertId(); }

/** Dizi anahtar/değerinden güvenli INSERT üretir, yeni id döndürür. */
function db_insert($table, array $data) {
    $cols = array_keys($data);
    $ph = [];
    $params = [];
    foreach ($data as $c => $v) { $ph[] = ':' . $c; $params[':' . $c] = $v; }
    $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
    q($sql, $params);
    return last_id();
}
/** Dizi anahtar/değerinden güvenli UPDATE üretir, etkilenen satır sayısı döndürür. */
function db_update($table, array $data, $where, array $whereParams = []) {
    $set = [];
    $params = [];
    foreach ($data as $c => $v) { $set[] = $c . ' = :s_' . $c; $params[':s_' . $c] = $v; }
    foreach ($whereParams as $k => $v) { $params[$k] = $v; }
    $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return q($sql, $params)->rowCount();
}

// ---------------------------------------------------------------- ayarlar
/** settings tablosunu tek seferde belleğe alır. */
/**
 * SIRLARIN DİNLENME HÂLİNDE ŞİFRELENMESİ (denetim turu 4, YÜKSEK-1)
 * ---------------------------------------------------------------------------
 * 1.2'ye kadar API anahtarları `settings` tablosunda düz metin duruyordu ve bu
 * savunulabilirdi: veritabanına erişen zaten her şeye erişiyordu.
 *
 * 1.2 bunu değiştirdi. Zamanlı uzak yedek (1.2-09) veritabanını DÜZENLİ OLARAK
 * ÜÇÜNCÜ TARAF BİR FTP SUNUCUSUNA gönderiyor. Denetçi gerçek bir yedek alıp
 * içinden ödeme sağlayıcı tuzunu okudu ve o tuzla GEÇERLİ İMZALI sahte bir
 * ödeme bildirimi üretip bekleyen bir ödemeyi "ödendi" yaptırdı. Yani özelliğin
 * kendisi sızıntının yarıçapını büyütüyordu.
 *
 * KAZANCIN SINIRI DÜRÜSTÇE: anahtar `config.php` içindeki `app_key`'dir ve o
 * DOSYADIR, veritabanında değildir — yedeğe girmez (ölçüldü). Yani bu şifreleme
 * "yalnızca veritabanı/yedek sızdı" durumunda korur. Saldırgan dosyaları da ele
 * geçirdiyse anahtar da onundur ve şifreleme bir şey kazandırmaz. 1.2 ile en
 * olası senaryo tam olarak birincisidir.
 *
 * ÇÖZÜM YAZMA DEĞİL OKUMA TARAFINDA SAYDAM: `setting()` işaretli değeri
 * kendiliğinden çözer. Böylece anahtarı okuyan onlarca çağrı yerinde kalır;
 * her birini tek tek değiştirmek, birini atlayıp sessizce bozmak demekti.
 */

/** Şifrelenmiş değerin başındaki işaret. */
function setting_secret_marker() { return 'enc:v1:'; }

/** Bu anahtar bir sır mı? (yazarken şifrelenir) */
function setting_is_secret($key) {
    $key = (string)$key;
    // Ayarlar ekranındaki `secret` tipli alanlar buraya düşer. Desen tabanlı,
    // çünkü sağlayıcı anahtarları eklendikçe liste elle güncellenmeyi unutur —
    // ve unutulan bir anahtar sessizce düz metin kalırdı.
    foreach (['_key', '_pass', '_secret', '_salt', '_token'] as $sonek) {
        if (substr($key, -strlen($sonek)) === $sonek) { return true; }
    }
    return false;
}

/** Şifreleme kullanılabilir mi? (openssl + app_key) */
function setting_secret_ready() {
    static $hazir = null;
    if ($hazir !== null) { return $hazir; }
    return $hazir = (function_exists('openssl_encrypt')
        && in_array('aes-256-gcm', array_map('strtolower', (array)openssl_get_cipher_methods()), true)
        && (string)cfg('app_key', '') !== '');
}

/** app_key'den türetilen 32 baytlık şifreleme anahtarı. */
function setting_secret_key() {
    return hash('sha256', 'manset-setting-v1|' . (string)cfg('app_key', ''), true);
}

/** Değeri şifreler. Şifreleme yoksa DEĞERİ OLDUĞU GİBİ döndürür (sessizce kaybetmez). */
function setting_secret_encode($plain) {
    $plain = (string)$plain;
    if ($plain === '' || !setting_secret_ready()) { return $plain; }
    // Zaten şifreliyse tekrar şifreleme (çift kodlama veriyi kurtarılamaz yapar).
    if (strpos($plain, setting_secret_marker()) === 0) { return $plain; }
    $iv = random_bytes(12);
    $etiket = '';
    $sifreli = openssl_encrypt($plain, 'aes-256-gcm', setting_secret_key(), OPENSSL_RAW_DATA, $iv, $etiket);
    if ($sifreli === false) { return $plain; }
    return setting_secret_marker() . base64_encode($iv . $etiket . $sifreli);
}

/** İşaretliyse çözer, değilse olduğu gibi döndürür (eski düz metin değerler çalışmaya devam eder). */
function setting_secret_decode($value) {
    $value = (string)$value;
    $isaret = setting_secret_marker();
    if (strpos($value, $isaret) !== 0) { return $value; }
    if (!setting_secret_ready()) {
        // Anahtar yok ama değer şifreli: BOŞ dönmek "anahtar tanımlı değil" gibi
        // görünür ve yayıncı yenisini girip eskisini kalıcı olarak kaybeder.
        log_error('Şifreli ayar çözülemedi: app_key ya da openssl yok.');
        return '';
    }
    $ham = base64_decode(substr($value, strlen($isaret)), true);
    if ($ham === false || strlen($ham) < 29) { return ''; }
    $iv = substr($ham, 0, 12);
    $etiket = substr($ham, 12, 16);
    $govde = substr($ham, 28);
    $cozulen = openssl_decrypt($govde, 'aes-256-gcm', setting_secret_key(), OPENSSL_RAW_DATA, $iv, $etiket);
    return $cozulen === false ? '' : $cozulen;
}

function settings_all($reload = false) {
    static $cacheSettings = null;
    if ($cacheSettings === null || $reload) {
        $cacheSettings = [];
        try {
            foreach (qa('SELECT skey, sval FROM settings') as $r) {
                // Çözme OKUMA tarafında ve saydam: anahtarı okuyan çağrılar
                // değişmeden çalışır.
                $cacheSettings[$r['skey']] = setting_secret_decode($r['sval']);
            }
        } catch (Throwable $e) { $cacheSettings = []; }
    }
    return $cacheSettings;
}
/** Tek ayar okur. */
function setting($key, $default = '') {
    $all = settings_all();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}
/** Ayar yazar (upsert) ve bellek kopyasını tazeler. */
function setting_set($key, $value) {
    // DEMO KİPİ: kurulumu bozan ya da sınırları aşan anahtarlar yazılamaz.
    // Sessizce yok sayılır — bir demo ziyaretçisine hata döndürmek yerine
    // değişikliğin etkisiz kalması yeterlidir ve akışı bozmaz.
    if (function_exists('demo_setting_locked') && demo_setting_locked($key)) { return; }

    // Sır niteliğindeki anahtarlar DİSKE şifreli yazılır (yukarıdaki açıklama).
    if (setting_is_secret($key)) { $value = setting_secret_encode($value); }
    $exists = qv('SELECT 1 FROM settings WHERE skey = :k', [':k' => $key]);
    if ($exists) { q('UPDATE settings SET sval = :v WHERE skey = :k', [':v' => (string)$value, ':k' => $key]); }
    else { q('INSERT INTO settings (skey, sval) VALUES (:k, :v)', [':k' => $key, ':v' => (string)$value]); }
    settings_all(true);
}
/** Toplu ayar yazımı. */
function setting_set_many(array $pairs) { foreach ($pairs as $k => $v) { setting_set($k, $v); } }

// ---------------------------------------------------------------- oturum
/** Güvenli çerez parametreleriyle oturum başlatır. */
function session_boot() {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    if (headers_sent()) { return; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $params = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) { @session_set_cookie_params($params); }
    else { @session_set_cookie_params(0, '/; samesite=Lax', '', $https, true); }
    @session_name('manset_sid');
    @session_start();
}

// ---------------------------------------------------------------- kaçış / metin
/** HTML kaçışı. Şablonlarda çıktının tek geçiş noktası. */
/**
 * GÜVENLİK BAŞLIKLARI — tek kaynak.
 *
 * Üç giriş noktası da (ön yüz `render()`, panel, JSON API) buradan geçer.
 * `.htaccess` aynı başlıkların bir bölümünü sunucu düzeyinde de yazar; ama
 * `mod_headers` olmayan ya da nginx kullanan bir kurulumda o dosya hiç okunmaz,
 * bu yüzden PHP tarafı ASIL kaynaktır.
 *
 * @param string $tur 'html' | 'json'
 */
function manset_security_headers($tur = 'html') {
    if (headers_sent()) { return; }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');

    // Tarayıcı özellikleri: hiçbiri kullanılmıyor, hepsi kapatılır. Gömülü
    // videolar `frame-src` ile ayrıca yönetilir; bu başlık ÜST belgeye bakar.
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

    // HSTS — YALNIZ istek HTTPS ise. HTTP üzerinden gönderilmesi anlamsızdır
    // (tarayıcı yok sayar) ve http-only bir geliştirme kurulumunda gönderilirse
    // o alan adı tarayıcıda kilitlenip siteyi erişilemez yapar.
    // `preload` BİLEREK YOK: preload listesine girmek geri alınamaz bir karardır
    // ve yayıncının bilinçli tercihi olmalıdır, kütüphanenin varsayılanı değil.
    if (manset_request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if ($tur === 'json') {
        // JSON yanıtı hiçbir şey gömmez, hiçbir şeye gömülmez.
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        return;
    }

    header('Content-Security-Policy: ' . manset_csp_value());
}

/** İstek HTTPS üzerinden mi geldi? */
function manset_request_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') { return true; }
    if ((int)(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0) === 443) { return true; }
    // Vekil başlığına YALNIZ trust_proxy açıkken güvenilir (bkz. NOTES.md).
    if (cfg('trust_proxy', false)) {
        $p = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
        if ($p === 'https') { return true; }
    }
    return false;
}

/**
 * HTML sayfaları için CSP değeri.
 *
 * DÜRÜST SINIR: `script-src` ve `style-src` içinde `'unsafe-inline'` VAR ve
 * kaldırılamıyor — panelde 36, temalarda 6 satır içi `<script>` bloğu ve 97
 * satır içi `style` özniteliği var. Bunları nonce'a çevirmek çekirdek bir
 * yeniden yazımdır. Dolayısıyla bu CSP **XSS savunması değildir**; XSS savunması
 * `inc/sanitize.php`'dir.
 *
 * Yine de gerçek şeyler kapatır ve bunlar ölçülebilir:
 *   · DIŞ betik yüklenemez (proje zaten hiç dış kaynak kullanmaz — ölçüldü: 0)
 *   · `object`/`embed` tümüyle yasak
 *   · `<base>` etiketiyle göreli adresler kaçırılamaz
 *   · Form başka bir siteye gönderilemez
 *   · Sayfa yabancı bir siteye gömülemez (tıklama hırsızlığı)
 *   · `iframe` yalnız beyaz listedeki video sağlayıcılarına açık
 */
function manset_csp_value() {
    static $csp = null;
    if ($csp !== null) { return $csp; }

    $frame = "'self'";
    if (function_exists('sanitize_allowed_iframe_hosts')) {
        foreach (sanitize_allowed_iframe_hosts() as $h) { $frame .= ' https://' . $h; }
    }

    // Tema Google Fonts kullanabiliyor (theme_font_link) — başka dış köken yok.
    $yazitipi = "'self' data: https://fonts.gstatic.com";
    $stil     = "'self' 'unsafe-inline' https://fonts.googleapis.com";

    // `img-src` GENİŞ: haber görseli, reklam görseli ve RSS'ten içe aktarılan
    // görsel uzak adres olabilir. Görsel yükleme kod çalıştırmaz; buradaki
    // genişlik betik genişliğiyle aynı şey değildir.
    return $csp = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src " . $stil . "; "
        . "font-src " . $yazitipi . "; "
        . "img-src 'self' data: https: http:; "
        . "media-src 'self' https:; "
        . "connect-src 'self'; "
        . "frame-src " . $frame . "; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'self'";
}


function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Öznitelik kaçışı (esc ile aynı, okunabilirlik için ayrı ad). */
function esc_attr($s) { return esc($s); }

/** Türkçe karakterleri sadeleştirip URL-güvenli slug üretir. */
function slugify($text, $maxLen = 90) {
    $text = (string)$text;
    $map = [
        'ğ' => 'g', 'Ğ' => 'g', 'ü' => 'u', 'Ü' => 'u', 'ş' => 's', 'Ş' => 's',
        'ı' => 'i', 'İ' => 'i', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
        'â' => 'a', 'Â' => 'a', 'î' => 'i', 'Î' => 'i', 'û' => 'u', 'Û' => 'u',
        'é' => 'e', 'É' => 'e', 'ñ' => 'n', 'Ñ' => 'n', 'ß' => 'ss',
        // Büyük biçimler de haritada OLMALI: harita mb_strtolower'dan ÖNCE
        // çalışıyor (Türkçe 'İ' için bilinçli), dolayısıyla eksik büyük harf
        // sonraki süzgeçte sessizce silinir — 'Øslo' → 'slo' oluyordu.
        'æ' => 'ae', 'Æ' => 'ae', 'ø' => 'o', 'Ø' => 'o', 'å' => 'a', 'Å' => 'a',
    ];
    $text = strtr($text, $map);
    // Kesme işareti ayraç sayılmaz: "İstanbul'da" → "istanbulda" (Türkçe haber başlıklarında çok yaygın)
    $text = str_replace(["'", "’", "‘", "`", "´"], '', $text);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    $text = trim((string)$text, '-');
    if ($text === '') { $text = 'icerik'; }
    if (mb_strlen($text) > $maxLen) { $text = trim(mb_substr($text, 0, $maxLen), '-'); }
    return $text;
}

/**
 * Tabloda benzersiz slug üretir; çakışırsa -2, -3 … ekler.
 * $ignoreId verilirse o kayıt çakışma sayılmaz (güncelleme senaryosu).
 */
function unique_slug($table, $base, $ignoreId = 0) {
    $slug = slugify($base);
    $try = $slug;
    $i = 1;
    while (true) {
        $row = q1('SELECT id FROM ' . $table . ' WHERE slug = :s AND id <> :i LIMIT 1', [':s' => $try, ':i' => (int)$ignoreId]);
        if (!$row) { return $try; }
        $i++;
        $try = $slug . '-' . $i;
        if ($i > 500) { return $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 5); }
    }
}

/** Çok baytlı strrpos yedeği. */
function mb_strrpos_compat($haystack, $needle) {
    if (function_exists('mb_strrpos')) { return mb_strrpos($haystack, $needle, 0, 'UTF-8'); }
    return strrpos($haystack, $needle);
}

/** Metni kelime sınırında kısaltır. */
function excerpt($text, $len = 160) {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (mb_strlen($text) <= $len) { return $text; }
    $cut = mb_substr($text, 0, $len);
    $sp = mb_strrpos_compat($cut, ' ');
    if ($sp !== false && $sp > 40) { $cut = mb_substr($cut, 0, $sp); }
    return $cut . '…';
}

/** Şu anın veritabanı biçimli zaman damgası. */
function now() { return date('Y-m-d H:i:s'); }

/** '2026-08-22 09:30:00' → '22 Ağustos 2026, 09:30' */
function tr_date($dt, $withTime = true) {
    if (!$dt) { return ''; }
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    if (!$ts) { return ''; }
    $aylar = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $s = date('j', $ts) . ' ' . $aylar[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($withTime) { $s .= ', ' . date('H:i', $ts); }
    return $s;
}

/** '3 saat önce' biçimi. */
function tr_ago($dt) {
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    if (!$ts) { return ''; }
    $d = time() - $ts;
    if ($d < 60) { return 'az önce'; }
    if ($d < 3600) { return floor($d / 60) . ' dakika önce'; }
    if ($d < 86400) { return floor($d / 3600) . ' saat önce'; }
    if ($d < 2592000) { return floor($d / 86400) . ' gün önce'; }
    return tr_date($dt, false);
}

// ---------------------------------------------------------------- URL
/** Sitenin taban URL'i (sondaki bölü çizgisi olmadan). */
/**
 * Sitenin kök YOLU (ana ad olmadan): '' ya da '/haber' gibi.
 *
 * Göreli yönlendirmeler için. base_url() ana adı istemcinin gönderdiği
 * `HTTP_HOST` başlığından türetir; kalıcı (301) bir yönlendirmede bu,
 * zehirli bir başlıkla ziyaretçiyi saldırganın adresine SÜRESİZ kilitleyebilir
 * (denetim turu 3, B04). Yalnız yol kullanıldığında ana ad denklemden çıkar.
 * SCRIPT_NAME sunucu tarafından belirlenir, istemci girdisi değildir.
 */
function site_path() {
    static $p = null;
    if ($p !== null) { return $p; }
    $fromCfg = trim((string)cfg('base_url', ''));
    if ($fromCfg !== '') {
        $yol = (string)parse_url(rtrim($fromCfg, '/'), PHP_URL_PATH);
        return $p = rtrim((string)$yol, '/');
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.') { $dir = ''; }
    $dir = preg_replace('#/(admin|install)(/pages)?$#', '', $dir);
    return $p = $dir;
}

function base_url() {
    static $b = null;
    if ($b !== null) { return $b; }
    $fromCfg = trim((string)cfg('base_url', ''));
    if ($fromCfg !== '') { return $b = rtrim($fromCfg, '/'); }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);
    $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '.') { $dir = ''; }
    // /admin ve /install alt klasörlerinden çağrıldığında köke geri dön
    $dir = preg_replace('#/(admin|install)(/pages)?$#', '', $dir);
    $computed = ($https ? 'https://' : 'http://') . $host . $dir;

    // SECURITY_AUDIT B-06: Host başlığı istemciden gelir. Kurulumda saklanan
    // kanonik adresle ANA AD karşılaştırılır; uyuşmuyorsa saklanan adres kullanılır.
    // (Şema ve alt klasör istekten korunur ki http/https geçişi bozulmasın.)
    $stored = trim((string)setting('site_url', ''));
    if ($stored !== '') {
        $storedHost = strtolower((string)parse_url($stored, PHP_URL_HOST));
        $reqHost = strtolower((string)parse_url($computed, PHP_URL_HOST));
        if ($storedHost !== '' && $storedHost !== $reqHost) {
            return $b = rtrim($stored, '/');
        }
    }
    return $b = $computed;
}

/** SEF (rewrite) açık mı? Kapalıysa ?r= yedeği kullanılır. */
function sef_enabled() {
    static $v = null;
    if ($v !== null) { return $v; }
    return $v = (setting('use_rewrite', '0') === '1');
}

/** Yol parçasından tam URL üretir; SEF kapalıysa ?r= biçimine düşer. */
function url($path = '', $query = []) {
    $path = ltrim((string)$path, '/');
    if ($path === '') {
        return base_url() . '/' . ($query ? '?' . http_build_query($query) : '');
    }
    if (sef_enabled()) {
        return base_url() . '/' . $path . ($query ? '?' . http_build_query($query) : '');
    }
    return base_url() . '/index.php?r=' . rawurlencode($path) . ($query ? '&' . http_build_query($query) : '');
}

function url_post($post) {
    $slug = is_array($post) ? $post['slug'] : '';
    $id = is_array($post) ? (int)$post['id'] : (int)$post;
    return url('haber/' . $slug . '-' . $id);
}
function url_category($cat) { return url('kategori/' . (is_array($cat) ? $cat['slug'] : $cat)); }
function url_tag($tag) { return url('etiket/' . slugify($tag)); }
function url_page($page) { return url('sayfa/' . (is_array($page) ? $page['slug'] : $page)); }
function url_search($qs) { return url('arama', ['q' => $qs]); }
function url_admin($path = '') {
    $path = ltrim((string)$path, '/');
    return base_url() . '/admin/' . ($path !== '' ? '?p=' . rawurlencode($path) : '');
}
/** Yükleme klasöründeki dosyanın genel URL'i. */
function url_upload($filename) {
    if ($filename === '' || $filename === null) { return ''; }
    if (preg_match('#^https?://#i', $filename)) { return $filename; }
    return base_url() . '/uploads/' . ltrim(str_replace('\\', '/', $filename), '/');
}
function url_asset($file) { return base_url() . '/assets/' . ltrim($file, '/'); }

/** 302 yönlendirme ve çıkış. */
function redirect($to) {
    if (!preg_match('#^https?://#i', $to)) { $to = (strpos($to, '/') === 0) ? base_url() . $to : url($to); }
    if (!headers_sent()) { header('Location: ' . $to, true, 302); }
    else { echo '<script>location.href=' . json_encode($to) . ';</script>'; }
    exit;
}

// ---------------------------------------------------------------- CSRF
/** Oturuma bağlı CSRF anahtarı. */
function csrf_token() {
    session_boot();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
/** Verilen anahtarı sabit zamanlı karşılaştırır. */
function csrf_verify($token) {
    session_boot();
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}
/** İstekten anahtarı çıkarır: X-CSRF başlığı ya da _csrf alanı. */
function csrf_from_request() {
    if (!empty($_SERVER['HTTP_X_CSRF'])) { return (string)$_SERVER['HTTP_X_CSRF']; }
    if (!empty($_POST['_csrf'])) { return (string)$_POST['_csrf']; }
    $body = json_body();
    if (!empty($body['_csrf'])) { return (string)$body['_csrf']; }
    return '';
}
/** Yazma uçlarında zorunlu kapı. Başarısızsa 403 ile sonlanır. */
function csrf_guard() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($method === 'GET' || $method === 'HEAD') { return true; }
    if (!csrf_verify(csrf_from_request())) {
        log_error('CSRF reddedildi', (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '') . ' ip=' . client_ip());
        if (is_json_request()) { json_err('Oturum doğrulaması başarısız. Sayfayı yenileyin.', 403); }
        http_response_code(403);
        echo 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
        exit;
    }
    return true;
}
/** Formlara gömülecek gizli alan (oturum açar — panel formları için). */
function csrf_field() { return '<input type="hidden" name="_csrf" value="' . esc(csrf_token()) . '">'; }

/**
 * Kamuya açık ön yüz formları için TEMBEL CSRF alanı.
 *
 * Neden: csrf_token() oturum başlatır. Haber sayfasındaki yorum/düzeltme
 * formları yüzünden her anonim ziyaretçiye çerez verilirdi ve sayfa önbelleği
 * (Faz 4) tam da en çok işe yarayacağı yerde devre dışı kalırdı.
 *
 * Bu alan boş basılır; assets/site.js kullanıcı forma dokunduğunda ya da
 * gönderdiğinde `api.php?a=public.csrf` ucundan anahtarı alıp doldurur.
 * Böylece oturum yalnız gerçekten etkileşen ziyaretçi için açılır.
 */
function csrf_field_lazy() {
    return '<input type="hidden" name="_csrf" value="" data-csrf>';
}

/** public.csrf ucu için: oturumu açar ve anahtarı döndürür. */
function csrf_public_token() { return csrf_token(); }

// ---------------------------------------------------------------- istek yardımcıları
function is_json_request() {
    $a = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
    $x = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
    $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string)$_SERVER['SCRIPT_NAME']) : '';
    return strpos($a, 'application/json') !== false || $x === 'XMLHttpRequest' || $script === 'api.php';
}
/** İstemci IP'si (ters vekil başlıklarına körü körüne güvenilmez). */
function client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    if (cfg('trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $cand = trim($parts[0]);
        if (filter_var($cand, FILTER_VALIDATE_IP)) { $ip = $cand; }
    }
    return $ip;
}
/** GET/POST okuma kısayolları. */
function inp($key, $default = '') {
    if (isset($_POST[$key])) { return $_POST[$key]; }
    if (isset($_GET[$key])) { return $_GET[$key]; }
    return $default;
}
function inp_s($key, $default = '') { $v = inp($key, $default); return is_string($v) ? trim($v) : (string)$default; }
function inp_i($key, $default = 0) { $v = inp($key, $default); return is_scalar($v) ? (int)$v : (int)$default; }
/** JSON gövdesini dizi olarak okur. */
function json_body() {
    static $cached = null;
    if ($cached !== null) { return $cached; }
    $ct = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (strpos($ct, 'application/json') === false) { return $cached = []; }
    $raw = file_get_contents('php://input');
    $d = json_decode((string)$raw, true);
    return $cached = (is_array($d) ? $d : []);
}
/** JSON yanıtı yazıp sonlandırır. */
function json_out($data, $code = 200) {
    // JSON yanıtı hiçbir şey gömmez ve hiçbir şeye gömülmemeli.
    manset_security_headers('json');
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_ok($data = []) { return json_out(['ok' => true] + (array)$data); }
function json_err($message, $code = 400, $extra = []) { return json_out(['ok' => false, 'error' => $message] + (array)$extra, $code); }

// ---------------------------------------------------------------- hız sınırı
/**
 * Basit sayaç tabanlı hız sınırı. İzin varsa true döner.
 * Örn: rate_limit('login:' . $ip, 8, 300)
 */
function rate_limit($bucket, $max, $windowSeconds) {
    try {
        $cut = date('Y-m-d H:i:s', time() - (int)$windowSeconds);
        q('DELETE FROM rate_limits WHERE created_at < :c', [':c' => date('Y-m-d H:i:s', time() - 86400)]);
        // ÖNCE YAZ, SONRA SAY (denetim tur 2, B05).
        // Eskiden sıra terstİ: SELECT COUNT → karar → INSERT. Araya kilit
        // konmadığı için eşzamanlı istekler aynı düşük sayıyı okuyup hepsi
        // geçiyordu; ~50 paralel istekle 8/300 sınırı birkaç katına çıkıyordu.
        // Denemeyi önce kaydedince her istek kendi satırını da sayar, sayaç
        // monoton artar ve yarış kapanır. Reddedilen deneme de bir denemedir,
        // slot tüketmesi istenen davranıştır; başarılı girişte zaten
        // rate_limit_clear() ile kova sıfırlanır.
        q('INSERT INTO rate_limits (bucket, created_at) VALUES (:b, :n)', [':b' => $bucket, ':n' => now()]);
        $n = (int)qv('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b AND created_at >= :c', [':b' => $bucket, ':c' => $cut], 0);
        return $n <= (int)$max;
    } catch (Throwable $e) {
        log_error('rate_limit hatası: ' . $e->getMessage());
        return true; // sayaç bozuksa hizmeti kesme
    }
}
/** Sayaç kaydını sıfırlar (başarılı girişten sonra). */
function rate_limit_clear($bucket) {
    try { q('DELETE FROM rate_limits WHERE bucket = :b', [':b' => $bucket]); } catch (Throwable $e) { }
}

// ---------------------------------------------------------------- hata yakalama
/**
 * Yakalanmamış istisna/ölümcül hata işleyicisi.
 *
 * NEDEN: 1.0'da yakalanmamış bir istisna API isteğinde bile HTML yığın izi
 * basıyordu. İki ayrı sorun: (a) istemci JSON beklerken HTML alıyor ve
 * "Bağlantı kurulamadı" gibi yanıltıcı bir hata gösteriyor; (b) yığın izi
 * dosya yollarını ve sorgu parçalarını sızdırıyor.
 *
 * Ayrıntı YALNIZ hata günlüğüne yazılır; kullanıcıya sabit bir mesaj döner.
 */
function manset_exception_handler($e) {
    $mesaj = ($e instanceof Throwable) ? $e->getMessage() : 'bilinmeyen';
    $yer   = ($e instanceof Throwable) ? (basename($e->getFile()) . ':' . $e->getLine()) : '';

    // OLAY NUMARASI. Ziyaretçiye gösterilen mesaj ayrıntı SIZDIRMAZ - bu
    // doğrudur - ama ziyaretçi ile günlük satırı arasında hiçbir bağ da
    // bırakmıyordu: "hata alıyorum" diyen okurun hangi satırı kastettiği
    // günlükte bulunamıyordu. Numara yalnız bir EŞLEŞTİRME anahtarıdır;
    // içeriğe dair hiçbir şey söylemez, tahmin edilmesi de bir işe yaramaz.
    try { $ref = strtoupper(bin2hex(random_bytes(3))); }
    catch (Throwable $x) { $ref = strtoupper(substr(md5($yer . microtime()), 0, 6)); }

    log_error('Yakalanmamış istisna [' . $ref . ']: ' . $mesaj . ' @ ' . $yer);

    if (headers_sent()) {
        // Çıktı başlamışsa durum kodu değiştirilemez: ziyaretçi YARIM bir sayfa
        // ve HTTP 200 görür (denetim turu 3, B10). En azından sayfanın kesildiği
        // görünür olsun — sessizce yarım kalan sayfa, hatasız sanılır ve
        // yanlış içerik (ör. eksik düzeltme metni) doğru gibi okunur.
        echo "\n<!-- manset: istek yarıda kesildi -->\n"
           . '<p style="font:14px system-ui;padding:16px;border-top:2px solid #b91c1c;'
           . 'color:#b91c1c">Bu sayfa bir hata yüzünden eksik kaldı. Lütfen yenileyin. '
           . '<span style="opacity:.7">(Olay no: ' . $ref . ')</span></p>';
        return;
    }
    http_response_code(500);

    if (function_exists('is_json_request') && is_json_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Sunucu hatası. Lütfen tekrar deneyin.', 'ref' => $ref],
                         JSON_UNESCAPED_UNICODE);
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Sunucu hatası</title>'
       . '<p style="font:16px system-ui;padding:40px">Beklenmeyen bir hata oluştu. '
       . 'Ayrıntı site yöneticisinin hata günlüğüne yazıldı.<br>'
       . '<small style="opacity:.7">Olay no: ' . $ref . ' — yöneticiye bildirirken bu numarayı verin.</small></p>';
}
set_exception_handler('manset_exception_handler');

/**
 * ŞEMA YOKLAMA YARDIMCILARI — 1.3'te inc/schema.php'den BURAYA taşındı.
 *
 * NEDEN: bu iki işlev "sütun/tablo var mı" diye sorar ve göç uygulanmamış
 * kurulumlarda kodun doğru dala düşmesini sağlar. Ama inc/schema.php her
 * bağlamda YÜKLENMİYORDU (bootstrap onu çekmez; yalnız kurulum, göç ve panel
 * araçları çeker). Sonuç: 43 ayrı çağrı `function_exists()` ile korunuyordu ve
 * koruma tuttuğunda kod SESSİZCE YANLIŞ DALA düşüyordu — hiçbir hata vermeden.
 *
 * Somut sonuçlar: `trash_filter_sql()` çöp kutusu süzgecini hiç eklemiyordu
 * (silinmiş haberler panel sayımlarına giriyordu) ve yorum silme yanıtları
 * arkada bırakıyordu. Bir ajan da çıplak CLI'da ölümcül hata aldı.
 *
 * İkisi de yalnız db_driver()/qa()/q1()/qv() kullanır; bootstrap'ta olmaları
 * yeni bir bağımlılık getirmez.
 */

/** Bir tabloda sütun var mı? */
function schema_has_column($table, $column) {
    try {
        if (db_driver() === 'sqlite') {
            foreach (qa('PRAGMA table_info(' . preg_replace('/[^a-z0-9_]/i', '', $table) . ')') as $c) {
                if (strcasecmp($c['name'], $column) === 0) { return true; }
            }
            return false;
        }
        $r = q1('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            [':t' => $table, ':c' => $column]);
        return (bool)$r;
    } catch (Throwable $e) { return false; }
}

/** Bir tablo var mı? */
function schema_has_table($table) {
    try {
        if (db_driver() === 'sqlite') {
            return (bool)qv('SELECT name FROM sqlite_master WHERE type = \'table\' AND name = :t', [':t' => $table]);
        }
        return (bool)qv('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => $table]);
    } catch (Throwable $e) { return false; }
}

/**
 * Çöp kutusunu eleyen SQL parçası: " AND p.deleted_at = ''" ya da ''.
 *
 * Panel sayaçları `published_where()` kullanmaz (yayımlanmamışları da sayarlar),
 * bu yüzden yumuşak silme kapısını ELLE eklemek zorundadırlar. Aynı koşulun
 * beş ayrı yerde tekrar yazılması, birinin unutulmasıyla sonuçlanıyordu
 * (denetim turu 3, B09). Sütun yoksa boş dize döner — göç 013 uygulanmamış
 * kurulumda sorgular aynen çalışır.
 *
 * @param string $alias Tablo takma adı ('p') ya da boş dize (takma ad yoksa).
 */
function trash_filter_sql($alias = 'p') {
    static $var = null;
    if ($var === null) {
        $var = function_exists('schema_has_column') ? schema_has_column('posts', 'deleted_at') : false;
    }
    if (!$var) { return ''; }
    $on = $alias !== '' ? $alias . '.' : '';
    return " AND " . $on . "deleted_at = ''";
}

/**
 * Cron görev kaydı.
 *
 * cron.php'deki sabit liste çekirdeğin altı görevini tutar; modüller kendi
 * görevlerini YÜKLENİRKEN buraya bildirir. Böylece yeni bir zamanlı iş eklemek
 * cron.php'ye dokunmayı gerektirmez — birden çok iş akışı aynı anda görev
 * ekleyebilir.
 *
 * @param string $ad    Görev anahtarı (cron_runs.task ve 'sadece' filtresinde geçer).
 * @param string $fn    Çağrılacak işlev adı.
 * @param bool   $ucuz  Ağa çıkmıyor, ücret üretmiyor, milisaniyeler sürüyorsa true.
 *                      Yalnız ucuz görevler bağlantı kapatamayan SAPI'lerde çalışır.
 */
function cron_register($ad, $fn, $ucuz = false) {
    if (!isset($GLOBALS['manset_cron_tasks'])) { $GLOBALS['manset_cron_tasks'] = []; }
    $GLOBALS['manset_cron_tasks'][(string)$ad] = ['fn' => (string)$fn, 'ucuz' => (bool)$ucuz];
}

/** Kayıtlı modül görevleri: ad => ['fn'=>…, 'ucuz'=>bool]. */
function cron_registered_tasks() {
    return isset($GLOBALS['manset_cron_tasks']) ? (array)$GLOBALS['manset_cron_tasks'] : [];
}

/**
 * 1.2 ile gelen özellik modülleri.
 *
 * NEDEN TEK KAYNAK: bu modüller kancalarını (`ads_slot_decorate`,
 * `analytics_record_view`, `paywall_allow`, `cron_register`) YÜKLENDİKLERİNDE
 * tanımlar. Yani yüklenmeyen modülün kancası sessizce hiç çalışmaz — hata da
 * vermez, çünkü kanca `function_exists()` ile korunuyor.
 *
 * 1.2 geliştirmesinde bu tam iki kez oldu: `api.php` analitik modülünü
 * yüklemiyordu (hiçbir ziyaret kaydedilmedi) ve `index.php` reklam modülünü
 * yüklemiyordu (gösterim sayacı ön yüzde hiç çalışmadı). İkisi de kod okunarak
 * görünmüyordu; canlı ölçümle bulundu.
 *
 * Dört giriş noktası (index.php, api.php, cron.php, admin/index.php) artık
 * AYNI listeyi okur. Yeni modül eklerken tek yer değişir.
 */
function manset_feature_modules() {
    return [
        // 1.2 "Yayıncı paketi"
        'analytics', 'ads', 'payment', 'paywall', 'newsletter',
        // 1.3 "Haber odası"
        'search',      // tam metin arama (FTS5 / FULLTEXT), search_posts() devreder
        'discover',    // yazar sayfası, tarih arşivi, etiket bulutu
        'revisions',   // sürüm geçmişi, otomatik kayıt, düzenleme kilidi
        'comments',    // yorum yanıtları, kara liste, moderasyon
        'embed',       // oEmbed gömme (YouTube/Vimeo/X/Instagram)
        // 1.4
        'rss_katalog', // örnek RSS kaynak kataloğu (öneri listesi, otomatik eklenmez)
        'demo',        // demo kipi kısıtları (config.php: demo_mode)
    ];
}

/** Özellik modüllerini yükler (dosya yoksa sessizce atlanır). */
function manset_load_feature_modules() {
    foreach (manset_feature_modules() as $mod) {
        $f = INC_DIR . '/' . $mod . '.php';
        if (is_file($f)) { require_once $f; }
    }
}

// ---------------------------------------------------------------- yükseltme
/**
 * Kurulu şema sürümü ile kod sürümü aynı mı?
 *
 * NEDEN GEREKLİ: 1.0'da `migrations_run()` yalnız kurulumda ve panelde bir
 * düğmeyle çalışıyordu (inc/schema.php, inc/api/tools.php). Yeni sürümün
 * zip'ini FTP ile üstüne atan yönetici o düğmeyi bilmezse site yarı çalışır:
 * kod yeni sütunları bekler, veritabanında yoktur. Kod bunu beş ayrı yerde
 * iç içe try/catch ile kurtarmaya çalışıyordu — sessiz ve kırılgan.
 */
function upgrade_pending() {
    if (!MANSET_INSTALLED) { return false; }
    return setting('installed_version', '') !== MANSET_VERSION;
}

/**
 * Veritabanında korunmaya değer veri var mı?
 *
 * Yükseltme yedeğinin alınıp alınmayacağına karar verir. Taze kurulumda
 * (henüz içerik yok) yedek almanın anlamı yoktur; dolu bir kurulumda ise
 * göç geri alınamaz olduğu için yedek TEK güvencedir.
 */
function upgrade_has_data() {
    try {
        if ((int)qv('SELECT COUNT(*) FROM posts', [], 0) > 0) { return true; }
        return (int)qv('SELECT COUNT(*) FROM users', [], 0) > 1;
    } catch (Throwable $e) {
        // Tablo okunamıyorsa güvenli taraf: yedek almayı DENE.
        return true;
    }
}

/**
 * Yükseltmeyi uygular: yedek → göç → önbellek temizliği → damga.
 *
 * YALNIZ YETKİLİ BAĞLAMDAN çağrılır (panel girişi, cron). Anonim ön yüz
 * isteğinde şema değiştirmek yarış koşulu yaratır ve ilk ziyaretçiye
 * saniyeler süren bir istek olarak yansır. Ön yüz bekleyen göçle de
 * çalışmaya devam eder (mevcut try/catch yedekleri korunuyor).
 *
 * Kilit: iki panel isteği aynı anda gelirse göç iki kez koşmasın. Göçler
 * idempotent yazılıyor ama ALTER TABLE yarışı sürücüde hataya düşebilir.
 *
 * @return array ['ok'=>bool, 'from'=>string, 'to'=>string, 'applied'=>array, 'error'=>string]
 */
function upgrade_run() {
    $from = setting('installed_version', '');
    $sonuc = ['ok' => false, 'from' => $from, 'to' => MANSET_VERSION, 'applied' => [], 'error' => ''];

    $kilit = DB_DIR . '/.upgrading';
    // GÜVENLİK/DAYANIKLILIK (denetim turu 3, B05): kilit dosyası SİLİNMEZ.
    // Eski kod 10 dakikadan eski dosyayı @unlink ile siliyordu; iki istek aynı
    // anda silip YENİ ve FARKLI birer inode oluşturabiliyor, ikisi de flock'u
    // alıp göçü aynı anda koşabiliyordu. Oysa flock zaten yarışsızdır ve süreç
    // çökse bile işletim sistemi kilidi BIRAKIR — yani "ölü flock" diye bir şey
    // yoktur. Dosyayı silmek sorunu çözmüyor, yaratıyordu.
    $fh = @fopen($kilit, 'c');
    if (!$fh) { $sonuc['error'] = 'Kilit dosyası açılamadı: ' . $kilit; return $sonuc; }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        $sonuc['error'] = 'Yükseltme başka bir istekte sürüyor.';
        return $sonuc;
    }

    try {
        // Yükseltme öncesi yedek: göç geri alınamaz, tek güvence budur.
        // DENETİM TURU 3 (B02): `function_exists('backup_create')` HİÇBİR ZAMAN
        // doğru değildi — inc/backup.php ne panel ne cron bağlamında yüklüdür.
        // "Yükseltme öncesi yedek alınır" vaadi sessizce hiç gerçekleşmiyordu.
        // Modül burada AÇIKÇA yüklenir; yedek alınamazsa yükseltme yine sürer
        // (yedek yokluğu yükseltmeyi engellememeli) ama kayda düşülür.
        // Yedek kararı `$from`'a DEĞİL, veritabanında gerçek veri olup
        // olmadığına bakar. Sürüm damgası 1.1 ile geldiği için 1.0'dan
        // yükselten kurulumda `$from` BOŞTUR; eski koşul tam da yedeğe en çok
        // ihtiyaç duyulan durumda yedeği atlıyordu (uçtan uca denemede yakalandı).
        if (upgrade_has_data()) {
            try {
                if (!function_exists('backup_create') && is_file(INC_DIR . '/backup.php')) {
                    require_once INC_DIR . '/backup.php';
                }
                if (function_exists('backup_create')) {
                    $yedek = backup_create();
                    $sonuc['backup'] = (is_array($yedek) && !empty($yedek['ok']))
                        ? (string)arr($yedek, 'file', '?') : '';
                    if ($sonuc['backup'] === '') { log_error('upgrade: yedek alınamadı'); }
                } else {
                    log_error('upgrade: backup modülü bulunamadı, yedeksiz sürüldü');
                }
            } catch (Throwable $e) { log_error('upgrade yedek: ' . $e->getMessage()); }
        }
        // migrations_run() inc/schema.php icinde tanimli ve o dosya her baglamda
        // yuklu DEGIL (panel ve cron yalniz bootstrap yukler). Yuklemeden cagirmak
        // "Call to undefined function" veriyordu: goc hic kosmuyor, tablolar
        // olusmuyor ve site komple duzuyordu.
        if (!function_exists('migrations_run')) {
            require_once INC_DIR . '/schema.php';
        }
        $sonuc['applied'] = migrations_run();
        if (function_exists('cache_flush')) { cache_flush(); }
        setting_set('installed_version', MANSET_VERSION);
        setting_set('upgraded_at', now());
        settings_all(true);   // ayar önbelleğini tazele
        $sonuc['ok'] = true;
    } catch (Throwable $e) {
        $sonuc['error'] = $e->getMessage();
        log_error('upgrade_run: ' . $e->getMessage());
    }

    @flock($fh, LOCK_UN);
    fclose($fh);
    // Kilit dosyasi SILINMEZ (bkz. yukaridaki B05 aciklamasi). Silmek, ayni
    // dosyayi acmis baska bir surecin elinde ARTIK VAR OLMAYAN bir inode
    // birakir; o surec kilidi "alir" ama kimseyle paylasmaz. Bos bir dosyanin
    // diskte durmasinin maliyeti yok.
    return $sonuc;
}

// ---------------------------------------------------------------- kullanıcı / rol
/**
 * Oturumu parola özetine bağlayan damga.
 *
 * GÜVENLİK (denetim tur 2, B04): oturumda yalnız `uid` tutulunca, parola
 * değiştirildikten SONRA da çalınmış çerez geçerli kalıyordu — "parolamı
 * değiştirdim, artık güvendeyim" beklentisi karşılanmıyordu. Damga parola
 * özetinden türetilir; parola değişince özet değişir, damga tutmaz ve tüm
 * eski oturumlar düşer. Özetin kendisi oturuma YAZILMAZ, yalnız kısa türevi.
 */
function session_pw_stamp($passHash) {
    return substr(hash('sha256', 'manset-oturum|' . (string)$passHash), 0, 32);
}

/** Girişten sonra oturuma kimlik + parola damgası yazar. */
function session_bind_user($id, $passHash) {
    $_SESSION['uid']  = (int)$id;
    $_SESSION['pw']   = session_pw_stamp($passHash);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** Oturumdaki kullanıcı ya da null. */
function current_user() {
    static $u = false;
    if ($u !== false) { return $u; }
    session_boot();
    if (empty($_SESSION['uid'])) { return $u = null; }
    try {
        // Faz 7 sütunları (is_staff, is_responsible) göç uygulanmadan yoktur;
        // bu yüzden önce genişletilmiş sorgu denenir, olmazsa v1 sorgusuna düşülür.
        try {
            $row = q1('SELECT id, name, email, role, active, is_staff, is_responsible, display_name, pass_hash
                        FROM users WHERE id = :i', [':i' => (int)$_SESSION['uid']]);
        } catch (Throwable $inner) {
            $row = q1('SELECT id, name, email, role, active, pass_hash FROM users WHERE id = :i', [':i' => (int)$_SESSION['uid']]);
        }
    } catch (Throwable $e) { return $u = null; }
    if (!$row || (int)$row['active'] !== 1) { return $u = null; }

    // Parola damgası tutmuyorsa oturum ölüdür (parola değişmiş ya da çerez
    // başka bir kuruluma ait). Damga hiç yoksa da kabul edilmez: giriş akışı
    // her zaman yazar, eksikliği elle kurcalanmış oturum demektir.
    $damga = isset($_SESSION['pw']) ? (string)$_SESSION['pw'] : '';
    if (!hash_equals(session_pw_stamp((string)$row['pass_hash']), $damga)) {
        $_SESSION = [];
        return $u = null;
    }

    // pass_hash çağıranlara SIZDIRILMAZ.
    unset($row['pass_hash']);
    return $u = $row;
}

/** Oturum çerezi var mı? Oturumu AÇMAZ (önbelleklenebilirlik için kritik). */
function has_session_cookie() {
    return !empty($_COOKIE["manset_sid"]);
}

/**
 * Yalnızca oturum çerezi varsa kullanıcıyı yükler; yoksa null döner ve oturum AÇMAZ.
 *
 * Ön yüz şablonları current_user() yerine BUNU çağırmalıdır: current_user()
 * session_boot() tetikler, bu da her anonim ziyaretçiye çerez verip sayfa
 * önbelleğini devre dışı bırakır (bkz. CONTRACTS §3.1).
 */
function current_user_if_session() {
    return has_session_cookie() ? current_user() : null;
}
/**
 * v1 yetki kapısı. BİTİŞ SONRASI FAZ bunu izin matrisiyle değiştirir;
 * imza sabit kalır: can($user, 'posts.publish').
 */
function can($user, $permission) {
    if (!$user) { return false; }

    // DEMO KİPİ — İZİN KATMANINDA (1.4).
    //
    // Demo kısıtlarını uç noktası ADIYLA saymak kırılgandır: ölçüm sırasında
    // kara listeye yazdığım adların altısı gerçekte YOKTU (`ai.run`,
    // `users.save`, `theme.save_css`, `payment.save` …) ve o girişler hiçbir
    // şeyi engellemiyordu — yalnız engellemiş gibi görünüyordu.
    //
    // İzin katmanı bu hatayı yapılamaz kılar: her API ucu, her panel sayfası ve
    // her form zaten buradan geçer. Bir izin kapatıldığında ona bağlı HER yol
    // kapanır — ucun adı ne olursa olsun, ileride hangi uç eklenirse eklensin.
    if (function_exists('demo_denied_permission') && demo_denied_permission($permission)) {
        return false;
    }

    // Faz 7: genişletilmiş rol sistemi kuruluysa karar oraya devredilir.
    // inc/roles.php yoksa aşağıdaki v1 haritası geçerli kalır (geriye dönük uyum).
    if (function_exists('roles_can')) { return roles_can($user, $permission); }
    $role = isset($user['role']) ? $user['role'] : '';
    if ($role === 'admin') { return true; }
    $map = [
        'editor' => [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit_any', 'posts.publish', 'posts.delete',
            'categories.manage', 'media.manage', 'media.delete_any', 'pages.manage',
            'comments.moderate', 'headlines.manage', 'rss.manage', 'ai.use',
            'corrections.triage', 'corrections.publish',
        ],
        'yazar' => ['admin.access', 'posts.view', 'posts.create', 'posts.edit_own', 'media.manage', 'ai.use'],
    ];
    return isset($map[$role]) && in_array($permission, $map[$role], true);
}
/** Yetki yoksa 403 ile sonlandırır. */
function require_can($permission) {
    $u = current_user();
    if (!can($u, $permission)) {
        if (is_json_request()) { json_err('Bu işlem için yetkiniz yok.', 403); }
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Yetkisiz</title><p style="font:16px system-ui;padding:40px">Bu işlem için yetkiniz yok.</p>';
        exit;
    }
    return $u;
}

// ---------------------------------------------------------------- API kayıt defteri
/**
 * JSON API uç noktası kaydeder. Dağıtımı api.php yapar.
 *
 * Burada (api.php'de değil) tanımlıdır ki inc/api/*.php dosyaları panel
 * sayfalarından da güvenle require edilebilsin (ortak sabit ve yardımcıları
 * tek kaynakta tutmak için). API bağlamı dışında kayıt yalnız diziye yazılır.
 *
 * @param string   $action  'posts.save' gibi benzersiz ad
 * @param callable $handler Dizi döndürürse JSON olarak basılır
 * @param array    $opts    ['perm'=>'posts.create'|'public', 'methods'=>['POST'], 'csrf'=>true]
 */
function api_register($action, $handler, array $opts = []) {
    $GLOBALS['manset_api'][$action] = [
        'handler' => $handler,
        'perm'    => isset($opts['perm']) ? $opts['perm'] : 'admin.access',
        'methods' => isset($opts['methods']) ? (array)$opts['methods'] : ['POST'],
        'csrf'    => isset($opts['csrf']) ? (bool)$opts['csrf'] : true,
    ];
}
if (!isset($GLOBALS['manset_api'])) { $GLOBALS['manset_api'] = []; }

// ---------------------------------------------------------------- flash mesaj
function flash($type, $message) {
    session_boot();
    if (!isset($_SESSION['flash'])) { $_SESSION['flash'] = []; }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}
function flash_take() {
    session_boot();
    $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- çeşitli
/** Dizi/anahtar okuma kısayolu. */
function arr($a, $k, $d = null) { return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $d; }
/** Rastgele anahtar üretir (cron key, app key…). */
function random_key($bytes = 24) { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
/** Klasörü yoksa oluşturur. */
function ensure_dir($dir) { if (!is_dir($dir)) { @mkdir($dir, 0755, true); } return is_dir($dir); }

/** Etiket dizesini normalize edilmiş diziye çevirir. */
function tags_to_array($tags) {
    if (is_array($tags)) { $parts = $tags; }
    else { $parts = preg_split('/[,;]+/u', (string)$tags); }
    $out = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', (string)$p));
        if ($p === '') { continue; }
        $key = mb_strtolower($p, 'UTF-8');
        if (!isset($out[$key])) { $out[$key] = $p; }
        if (count($out) >= 15) { break; }
    }
    return array_values($out);
}
function tags_to_string($tags) { return implode(', ', tags_to_array($tags)); }

// Yükleme klasörleri hazır olsun
ensure_dir(UPLOAD_DIR);
ensure_dir(CACHE_DIR);

// Genişletilmiş rol sistemi (Faz 7). Varsa can() buna devreder.
if (is_file(INC_DIR . '/roles.php')) { require_once INC_DIR . '/roles.php'; }
