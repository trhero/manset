<?php
/**
 * Manşet — dosya tabanlı sayfa önbelleği (Ajan-10).
 * Sözleşme: CONTRACTS.md §11 — imzalar sabittir, index.php ve cron.php bu adları çağırır.
 *
 * Tasarım kararları
 * ------------------
 * 1. Depo: uploads/cache/<ab>/<cd>/<40 hane sha1>.html — iki seviyeli alt klasör.
 *    Tek klasörde on binlerce dosya biriktiğinde dizin taraması (ve bazı dosya
 *    sistemlerinde açma) yavaşlar; ilk dört haneye göre dağıtım 65 536 kovaya böler.
 *
 * 2. Üstveri AYRI .meta dosyasında DEĞİL, HTML'in ilk satırında bir yorum satırında
 *    durur:  <!--MANSET-CACHE {"t":…,"exp":…,"scope":"post:12","ms":37,"ct":"…"}-->
 *    Gerekçe:
 *      · Yazma tek dosyaya yapılır, dolayısıyla tek rename() ile hem içerik hem
 *        üstveri atomik olarak yerine oturur. İki dosyada içerik/üstveri çiftinin
 *        yarıda kalma (yetim .meta) ihtimali vardır.
 *      · HIT yolunda tek fopen() yeter: fgets() üstveriyi, fpassthru() gövdeyi verir.
 *        İki dosyalı tasarımda her istek iki stat + iki açma demektir.
 *      · Kapsam bazlı temizlikte yalnız ilk satır okunur (birkaç yüz bayt).
 *    Bedeli: gövde diskte 1 satır kaydırılmış durur; fpassthru offset ile başlar.
 *
 * 3. Kilit yok. İki istek aynı sayfayı aynı anda üretirse ikisi de geçici dosyaya
 *    yazıp rename() eder; rename atomik olduğu için okuyucu daima bütün bir dosya görür.
 *
 * 4. Disk dolu / klasör yazılamaz durumunda önbellek sessizce devre dışı kalır;
 *    site çalışmaya devam eder, hata saatte bir kez log_error() ile bildirilir.
 *
 * GÜVENLİK — oturum sızıntısı kapıları (cache_end içinde):
 *    · HTTP durumu 200 değilse yazılmaz (404/410/301 önbelleğe girmez).
 *    · Çıktıda dolu bir CSRF anahtarı varsa yazılmaz (kişiye özel değer sızmasın).
 *    · Yanıtta Set-Cookie varsa yazılmaz.
 *    · İstek sırasında PHP oturumu açılmışsa yazılmaz.
 *    Sunma tarafında ise oturum çerezi olan hiçbir istek önbelleğe hiç uğramaz.
 */

require_once __DIR__ . '/bootstrap.php';

// ============================================================ ayarlar / kök

/** Önbellek ayar olarak açık mı? */
function cache_enabled() {
    return setting('cache_enabled', '1') === '1';
}

/** Önbellek kök klasörü (uploads/cache). */
function cache_root() { return CACHE_DIR; }

/** Üstveri satırının ön eki. */
function cache_meta_prefix() { return '<!--MANSET-CACHE '; }

/**
 * Kök klasörü ve koruma dosyalarını bir kez hazırlar.
 * uploads/ genel bir klasör olduğu için önbellek dosyaları doğrudan indirilemesin diye
 * .htaccess bırakılır (nginx'te etkisizdir; anahtar sha1 olduğu için tahmin edilemez).
 */
function cache_ensure_root() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $root = cache_root();
    if (!ensure_dir($root)) { return $ready = false; }
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $idx = $root . '/index.html';
    if (!is_file($idx)) { @file_put_contents($idx, "<!doctype html><title>Manşet</title>\n"); }
    return $ready = is_dir($root);
}

/** Bayt sayısını okunur metne çevirir (media.php'den bağımsız olsun diye ayrı). */
function cache_human_size($bytes) {
    $b = (float)max(0, (int)$bytes);
    if ($b < 1024) { return (int)$b . ' B'; }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    do { $b /= 1024; $i++; } while ($b >= 1024 && $i < 3);
    $r = round($b, $b < 10 ? 1 : 0);
    return str_replace('.', ',', (string)$r) . ' ' . $units[$i];
}

/** Yazma hatasını saatte en çok bir kez günlüğe düşürür (log bombası olmasın). */
function cache_report_write_error($detail) {
    static $doneThisRequest = false;
    if ($doneThisRequest) { return; }
    $doneThisRequest = true;
    $last = (int)setting('cache_error_at', '0');
    if ($last > time() - 3600) { return; }
    try { setting_set('cache_error_at', (string)time()); } catch (Throwable $e) { /* yok say */ }
    log_error('Sayfa önbelleği yazamıyor, devre dışı kaldı: ' . $detail, cache_root());
}

// ============================================================ istek denetimi

/** Ziyaretçide oturum çerezi var mı? (current_user() ÇAĞRILMAZ — o oturum açar.) */
function cache_has_session_cookie() {
    if (!empty($_COOKIE['manset_sid'])) { return true; }
    $n = (string)@session_name();
    if ($n !== '' && !empty($_COOKIE[$n])) { return true; }
    return false;
}

/** Önbelleğe dâhil edilen (çıktıyı değiştiren) sorgu parametreleri. */
function cache_query_whitelist() {
    // r = SEF kapalı hostinglerde yolun kendisidir, cache_key_for zaten yolu alıyor.
    return ['q', 'sayfa', 'r'];
}

/** Çıktıyı etkilemeyen, sessizce yok sayılan izleme parametreleri. */
function cache_query_ignored() {
    return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_id', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];
}

/** Sorgu dizesi önbelleğe uygun mu? Bilinmeyen her parametre (_p, onizleme…) atlatır. */
function cache_query_is_cacheable($get) {
    if (!is_array($get)) { return false; }
    $ok = cache_query_whitelist();
    $ignore = cache_query_ignored();
    foreach ($get as $k => $v) {
        if (in_array($k, $ok, true) || in_array($k, $ignore, true)) { continue; }
        return false;
    }
    return true;
}

/**
 * Bu isteğe hazır sayfa sunulabilir mi? (CONTRACTS §11)
 * Tüm koşullar sağlanmalı: ayar açık · GET · oturum çerezi yok · açık oturum yok ·
 * sorgu dizesi beyaz listede.
 */
function cache_should_serve() {
    if (!cache_enabled()) { return false; }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET') { return false; }
    if (session_status() === PHP_SESSION_ACTIVE) { return false; }
    if (cache_has_session_cookie()) { return false; }
    if (!cache_query_is_cacheable($_GET)) { return false; }
    return true;
}

// ============================================================ kapsam ve anahtar

/**
 * Yoldan kapsam etiketi üretir.
 * 'home' | 'category' | 'tag' | 'search' | 'page' | 'post:<id>' | 'other'
 * ('other' TTL'i 0'dır → önbelleğe alınmaz: robots.txt, bilinmeyen yol, 404 vb.)
 */
function cache_scope_for($path) {
    $path = trim(preg_replace('#/+#', '/', (string)$path), '/');
    if ($path === '') { return 'home'; }
    $seg = explode('/', $path);
    $head = $seg[0];
    if ($head === 'haber') {
        $rest = isset($seg[1]) ? $seg[1] : '';
        if (preg_match('/-(\d+)$/', $rest, $m)) { return 'post:' . (int)$m[1]; }
        return 'other';
    }
    if ($head === 'kategori') { return 'category'; }
    if ($head === 'etiket')   { return 'tag'; }
    if ($head === 'arama')    { return 'search'; }
    if ($head === 'sayfa' || $head === 'kunye') { return 'page'; }
    return 'other';
}

/** Liste sayfası kapsamları — 'home' ve 'category' temizliği bunların hepsini düşürür. */
function cache_list_scopes() { return ['home', 'category', 'tag', 'search']; }

/** Kapsamın saniye cinsinden yaşam süresi. 0 → önbelleğe alınmaz. */
function cache_ttl_for($scope) {
    $scope = (string)$scope;
    if (strpos($scope, 'post:') === 0 || $scope === 'page') {
        return max(0, min(86400, (int)setting('cache_ttl_post', '300')));
    }
    if (in_array($scope, cache_list_scopes(), true)) {
        return max(0, min(3600, (int)setting('cache_ttl_home', '60')));
    }
    return 0;
}

/**
 * İstek için önbellek anahtarı üretir (sha1). Önbelleğe alınmayacak istekte '' döner.
 * Anahtara giren bileşenler: yol · beyaz listedeki sorgu parametreleri · etkin tema ·
 * yazılım sürümü · taban adres · SEF durumu.
 * Tema veya SEF ayarı değişince anahtar da değişir; eski dosyalar kendiliğinden ölür.
 */
function cache_key_for($path, $get) {
    if (!cache_enabled()) { return ''; }
    $get = is_array($get) ? $get : [];
    if (!cache_query_is_cacheable($get)) { return ''; }

    $path = trim(preg_replace('#/+#', '/', (string)$path), '/');
    $scope = cache_scope_for($path);
    if (cache_ttl_for($scope) <= 0) { return ''; }

    $theme = function_exists('theme_name') ? theme_name() : setting('theme', 'gazete');
    $parts = [
        'p=' . $path,
        'q=' . (isset($get['q']) ? (string)$get['q'] : ''),
        's=' . (isset($get['sayfa']) ? (int)$get['sayfa'] : 1),
        'th=' . $theme,
        'v=' . MANSET_VERSION,
        'b=' . base_url(),
        'sef=' . ((function_exists('sef_enabled') && sef_enabled()) ? '1' : '0'),
    ];
    $key = sha1(implode("\n", $parts));

    // Kapsamı cache_begin() için sakla (routeHead haber kimliğini taşımıyor).
    $GLOBALS['manset_cache_scope'] = $scope;
    return $key;
}

/** Anahtardan dosya yolu: uploads/cache/ab/cd/<anahtar>.html */
function cache_file($key) {
    $key = preg_replace('/[^a-f0-9]/', '', strtolower((string)$key));
    if (strlen($key) !== 40) { return ''; }
    return cache_root() . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key . '.html';
}

// ============================================================ üstveri

/** Üstveri satırını çözer; geçersizse null. */
function cache_parse_meta($line) {
    $line = (string)$line;
    $pre = cache_meta_prefix();
    if (strncmp($line, $pre, strlen($pre)) !== 0) { return null; }
    $end = strpos($line, '-->');
    if ($end === false) { return null; }
    $json = substr($line, strlen($pre), $end - strlen($pre));
    $m = json_decode((string)$json, true);
    if (!is_array($m) || !isset($m['exp']) || !isset($m['t'])) { return null; }
    $m['exp']   = (int)$m['exp'];
    $m['t']     = (int)$m['t'];
    $m['scope'] = isset($m['scope']) ? (string)$m['scope'] : 'other';
    $m['ms']    = isset($m['ms']) ? (int)$m['ms'] : 0;
    $m['ct']    = isset($m['ct']) ? (string)$m['ct'] : 'text/html; charset=utf-8';
    return $m;
}

/** Dosyanın yalnız ilk satırını okuyup üstveriyi çözer. */
function cache_read_meta_file($file) {
    $fh = @fopen($file, 'rb');
    if (!$fh) { return null; }
    $line = fgets($fh, 2048);
    fclose($fh);
    return cache_parse_meta($line);
}

// ============================================================ sunma

/** İstemcinin kopyası hâlâ taze mi? (If-None-Match / If-Modified-Since) */
function cache_client_has_fresh_copy($etag, $created) {
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($inm !== '') {
        if ($inm === '*') { return true; }
        foreach (explode(',', $inm) as $cand) {
            $cand = trim($cand);
            if (stripos($cand, 'W/') === 0) { $cand = trim(substr($cand, 2)); }
            if ($cand === $etag) { return true; }
        }
        return false; // ETag gönderilmiş ama tutmuyor → tam yanıt ver
    }
    $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string)$_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';
    if ($ims !== '') {
        $ts = strtotime($ims);
        if ($ts !== false && $ts >= (int)$created) { return true; }
    }
    return false;
}

/**
 * Hazır sayfayı basar. Basıldıysa true döner (index.php exit eder).
 * Taze dosya yoksa hiçbir çıktı vermeden false döner.
 */
function cache_serve($key) {
    if (!cache_enabled()) { return false; }
    $file = cache_file($key);
    if ($file === '' || !is_file($file)) { return false; }

    $fh = @fopen($file, 'rb');
    if (!$fh) { return false; }
    $meta = cache_parse_meta(fgets($fh, 2048));
    if (!$meta || $meta['exp'] <= time()) { fclose($fh); return false; }

    $offset = (int)ftell($fh);
    $size = (int)@filesize($file);
    $len = max(0, $size - $offset);
    if ($len <= 0) { fclose($fh); return false; }

    $created = $meta['t'];
    $etag = '"' . substr((string)$key, 0, 16) . '-' . dechex($created) . '"';

    if (!headers_sent()) {
        // render() ile aynı güvenlik başlıkları — bu yol render()'ı atladığı için
        // başlıklar burada yeniden verilmek zorunda.
        header('Content-Type: ' . $meta['ct']);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Manset-Cache: HIT');
        header('X-Manset-Cache-Age: ' . max(0, time() - $created));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $created) . ' GMT');
        header('ETag: ' . $etag);
        // Tarayıcı her seferinde doğrulasın: içerik güncellenince anında düşsün,
        // değişmediyse 304 ile gövde hiç aktarılmasın.
        header('Cache-Control: public, max-age=0, must-revalidate');
        // Cookie de VARY listesinde olmalı (denetim tur 2, paywall B04): bu yanıt
        // yalnız OTURUMSUZ ziyaretçi için üretildi; araya giren bir vekil onu
        // giriş yapmış birine sunmamalı.
        header('Vary: Accept-Encoding, Cookie');
    }

    if (cache_client_has_fresh_copy($etag, $created)) {
        fclose($fh);
        if (!headers_sent()) {
            http_response_code(304);
            @header_remove('Content-Type');
        }
        return true;
    }

    if (!headers_sent()) { header('Content-Length: ' . $len); }
    fpassthru($fh);
    fclose($fh);
    return true;
}

// ============================================================ üretme

/** Üretim bağlamı (tek istek boyunca). */
function cache_ctx() {
    if (!isset($GLOBALS['manset_cache_ctx'])) { $GLOBALS['manset_cache_ctx'] = null; }
    return $GLOBALS['manset_cache_ctx'];
}

/**
 * Sayfa üretimini tampona alır. Çıktı cache_end() ile hem istemciye hem diske gider.
 * @param string $key       cache_key_for() çıktısı
 * @param string $routeHead Yolun ilk parçası (kapsam yedeği)
 */
function cache_begin($key, $routeHead) {
    if (!cache_enabled()) { return; }
    $file = cache_file($key);
    if ($file === '' || !cache_ensure_root()) { return; }

    $scope = isset($GLOBALS['manset_cache_scope']) ? (string)$GLOBALS['manset_cache_scope'] : '';
    if ($scope === '') { $scope = cache_scope_for((string)$routeHead); }
    $ttl = cache_ttl_for($scope);
    if ($ttl <= 0) { return; }

    if (!headers_sent()) { header('X-Manset-Cache: MISS'); }

    $GLOBALS['manset_cache_ctx'] = [
        'key'    => $key,
        'file'   => $file,
        'scope'  => $scope,
        'ttl'    => $ttl,
        'start'  => microtime(true),
        'level'  => ob_get_level(),
        'active' => true,
    ];
    ob_start();
}

/** Tamponu istemciye boşaltır ve içeriği döndürür. */
function cache_flush_buffers(array $ctx) {
    while (ob_get_level() > (int)$ctx['level'] + 1) { @ob_end_flush(); }
    if (ob_get_level() <= (int)$ctx['level']) { return null; } // tampon başkasınca kapatılmış
    $html = (string)ob_get_contents();
    @ob_end_flush();
    return $html;
}

/**
 * Çıktıda kişiye özel bir CSRF anahtarı var mı?
 * Ön yüz formları csrf_field_lazy() ile BOŞ alan basar; dolu bir değer görmek
 * oturumun açıldığı (ya da bir temanın csrf_token() kullandığı) anlamına gelir.
 * Böyle bir sayfa asla önbelleğe yazılmaz — aksi hâlde bir ziyaretçinin anahtarı
 * başka ziyaretçilere servis edilirdi.
 */
function cache_output_has_session_leak($html) {
    if (preg_match('/data-csrf\s*=\s*"[^"]+"/i', $html)) { return true; }
    if (preg_match("/data-csrf\\s*=\\s*'[^']+'/i", $html)) { return true; }
    if (preg_match_all('/<input\b[^>]*>/i', $html, $mm)) {
        foreach ($mm[0] as $tag) {
            if (!preg_match('/name\s*=\s*["\']?_csrf["\']?/i', $tag)) { continue; }
            if (preg_match('/value\s*=\s*"([^"]+)"/i', $tag)) { return true; }
            if (preg_match("/value\\s*=\\s*'([^']+)'/i", $tag)) { return true; }
        }
    }
    return false;
}

/** Yanıtta Set-Cookie var mı? */
function cache_response_sets_cookie() {
    foreach ((array)headers_list() as $h) {
        if (stripos($h, 'set-cookie:') === 0) { return true; }
    }
    return false;
}

/** Üretilen sayfayı diske yazar (geçici dosya + rename ile atomik). */
function cache_store(array $ctx, $html) {
    $file = (string)$ctx['file'];
    $dir = dirname($file);
    if (!ensure_dir($dir)) { cache_report_write_error('klasör oluşturulamadı: ' . $dir); return false; }

    $now = time();
    $meta = [
        'v'     => 1,
        't'     => $now,
        'exp'   => $now + (int)$ctx['ttl'],
        'scope' => (string)$ctx['scope'],
        'ms'    => (int)round((microtime(true) - (float)$ctx['start']) * 1000),
        'ct'    => 'text/html; charset=utf-8',
    ];
    $line = cache_meta_prefix()
          . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
          . "-->\n";

    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $bytes = @file_put_contents($tmp, $line . $html);
    if ($bytes === false || $bytes < strlen($line)) {
        @unlink($tmp);
        cache_report_write_error('geçici dosya yazılamadı: ' . basename($tmp));
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        cache_report_write_error('rename başarısız: ' . basename($file));
        return false;
    }
    @chmod($file, 0644);
    return true;
}

/** Üretimi bitirir: çıktıyı basar ve uygunsa önbelleğe yazar. */
function cache_end() {
    $ctx = cache_ctx();
    if (!$ctx || empty($ctx['active'])) { return; }
    $GLOBALS['manset_cache_ctx']['active'] = false;

    $html = cache_flush_buffers($ctx);
    if ($html === null) { return; }

    // --- güvenlik ve doğruluk kapıları
    $code = http_response_code();
    if ($code !== false && (int)$code !== 200) { return; }          // 404/410/301 önbelleğe girmez
    if (trim($html) === '') { return; }
    if (session_status() === PHP_SESSION_ACTIVE) { return; }        // istek sırasında oturum açılmış
    if (cache_response_sets_cookie()) { return; }
    if (cache_output_has_session_leak($html)) {
        log_error('Önbellek yazımı iptal: çıktıda dolu CSRF anahtarı var', (string)$ctx['scope']);
        return;
    }

    cache_store($ctx, $html);
}

/** Üretimi iptal eder: çıktı istemciye gider ama diske YAZILMAZ. */
function cache_abort() {
    $ctx = cache_ctx();
    if (!$ctx || empty($ctx['active'])) { return; }
    $GLOBALS['manset_cache_ctx']['active'] = false;
    while (ob_get_level() > (int)$ctx['level']) { @ob_end_flush(); }
}

// ============================================================ dosya gezinme

/** Önbellekteki tüm sayfa dosyalarının yollarını döndürür. */
function cache_files() {
    $out = [];
    $root = cache_root();
    if (!is_dir($root)) { return $out; }
    foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $d1) {
        foreach ((array)glob($d1 . '/*', GLOB_ONLYDIR) as $d2) {
            foreach ((array)glob($d2 . '/*.html') as $f) { $out[] = $f; }
        }
    }
    return $out;
}

/** Boşalan iki seviyeli klasörleri toplar; silinen klasör sayısını döndürür. */
function cache_prune_empty_dirs() {
    $root = cache_root();
    if (!is_dir($root)) { return 0; }
    $n = 0;
    foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $d1) {
        foreach ((array)glob($d1 . '/*', GLOB_ONLYDIR) as $d2) {
            $items = @scandir($d2);
            if (is_array($items) && count($items) <= 2 && @rmdir($d2)) { $n++; }
        }
        $items = @scandir($d1);
        if (is_array($items) && count($items) <= 2 && @rmdir($d1)) { $n++; }
    }
    return $n;
}

// ============================================================ temizleme

/**
 * Verilen kapsamı siler ve silinen dosya sayısını döndürür.
 * cache_flush() bunun void sarmalayıcısıdır (sözleşme imzası).
 */
function cache_purge($scope = null) {
    $root = cache_root();
    if (!is_dir($root)) { return 0; }

    $scope = ($scope === null || $scope === '') ? null : (string)$scope;
    $targets = null; // null = hepsi
    if ($scope !== null) {
        $lists = cache_list_scopes();
        if (strpos($scope, 'post:') === 0) {
            // Haber sayfası + haber listelerde göründüğü için tüm liste sayfaları
            $targets = array_merge([$scope], $lists);
        } elseif (in_array($scope, $lists, true)) {
            $targets = $lists;
        } elseif ($scope === 'page') {
            $targets = ['page'];
        } else {
            $targets = null; // bilinmeyen kapsam → güvenli taraf: hepsini düşür
        }
    }

    $n = 0;
    foreach (cache_files() as $f) {
        if ($targets === null) { if (@unlink($f)) { $n++; } continue; }
        $m = cache_read_meta_file($f);
        if ($m === null || in_array((string)$m['scope'], $targets, true)) {
            // Üstverisi okunamayan dosya bozuktur; o da gitsin.
            if (@unlink($f)) { $n++; }
        }
    }
    if ($n > 0) { cache_prune_empty_dirs(); }
    return $n;
}

/**
 * Önbelleği temizler (CONTRACTS §11 imzası — dönüş değeri yoktur).
 * @param string|null $scope 'home' | 'category' | 'post:<id>' | null = hepsi
 */
function cache_flush($scope = null) {
    try { cache_purge($scope); } catch (Throwable $e) { log_error('cache_flush: ' . $e->getMessage()); }
}

/**
 * Cron süpürmesi: süresi geçmiş dosyaları siler, boyut sınırını uygular,
 * boş klasörleri toplar. Özet metin döndürür.
 */
function cache_sweep() {
    $root = cache_root();
    if (!is_dir($root)) { return 'önbellek klasörü yok'; }

    $now = time();
    $expired = 0;
    $bytes = 0;
    $rows = [];
    foreach (cache_files() as $f) {
        $m = cache_read_meta_file($f);
        $size = (int)@filesize($f);
        if ($m === null || $m['exp'] <= $now) {
            if (@unlink($f)) { $expired++; }
            continue;
        }
        $bytes += $size;
        $rows[] = ['f' => $f, 'size' => $size, 't' => $m['t']];
    }

    // Boyut sınırı: en eskiden başlayarak buda (hedef, sınırın %80'i)
    $maxMb = (int)setting('cache_max_mb', '200');
    if ($maxMb <= 0) { $maxMb = 200; }
    $max = $maxMb * 1048576;
    $trimmed = 0;
    if ($bytes > $max) {
        usort($rows, function ($a, $b) { return $a['t'] <=> $b['t']; });
        $target = (int)($max * 0.8);
        foreach ($rows as $r) {
            if ($bytes <= $target) { break; }
            if (@unlink($r['f'])) { $bytes -= $r['size']; $trimmed++; }
        }
    }

    $dirs = cache_prune_empty_dirs();
    $kept = count($rows) - $trimmed;
    return $expired . ' süresi geçmiş + ' . $trimmed . ' boyut aşımı dosyası silindi; '
         . max(0, $kept) . ' dosya kaldı (' . cache_human_size($bytes) . '), '
         . $dirs . ' boş klasör toplandı';
}

// ============================================================ durum

/** Panel/API için önbellek durumu. */
function cache_stats() {
    $files = 0;
    $bytes = 0;
    $oldest = 0;
    $newest = 0;
    foreach (cache_files() as $f) {
        $files++;
        $bytes += (int)@filesize($f);
        $t = (int)@filemtime($f);
        if ($t > 0) {
            if ($oldest === 0 || $t < $oldest) { $oldest = $t; }
            if ($t > $newest) { $newest = $t; }
        }
    }
    $root = cache_root();
    return [
        'enabled'   => cache_enabled(),
        'dir'       => $root,
        'writable'  => is_dir($root) && is_writable($root),
        'files'     => $files,
        'bytes'     => $bytes,
        'size_text' => cache_human_size($bytes),
        'oldest'    => $oldest ? date('Y-m-d H:i:s', $oldest) : '',
        'newest'    => $newest ? date('Y-m-d H:i:s', $newest) : '',
        'ttl_home'  => (int)setting('cache_ttl_home', '60'),
        'ttl_post'  => (int)setting('cache_ttl_post', '300'),
        'max_mb'    => (int)setting('cache_max_mb', '200'),
    ];
}
