<?php
/**
 * Manşet — piyasa şeridi ve hava durumu widget'ları. (Ajan-9)
 *
 * İki çalışma kipi vardır (her widget için ayrı):
 *   manual → değerler panelden elle girilir (settings['widget_*_data'] JSON'u)
 *   remote → harici bir JSON adresinden çekilir ve 15 dakika dosya önbelleğine alınır
 *
 * Güvenlik (SSRF):
 *   • Her dış istekten önce safe_remote_url() çağrılır; false dönerse istek YAPILMAZ.
 *   • curl yönlendirme takibi kapalıdır; Location elle ve yeniden doğrulanarak
 *     en çok 1 kez izlenir.
 *   • Bağlantı 6 sn, toplam 12 sn zaman aşımı; indirme 256 KB ile sınırlıdır.
 *   • Yanıt JSON olarak çözülür; dizi değilse hata döner. Değerler sanitize_line()
 *     süzgecinden geçirilir.
 *   • Uzak servis çökerse süresi geçmiş olsa bile son geçerli önbellek kullanılır
 *     ve "guncelleme" alanında verinin eski olduğu belirtilir.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';

// ---------------------------------------------------------------- sabitler
if (!defined('WIDGET_TTL'))             { define('WIDGET_TTL', 900); }        // 15 dakika
if (!defined('WIDGET_MAX_BYTES'))       { define('WIDGET_MAX_BYTES', 262144); } // 256 KB
if (!defined('WIDGET_CONNECT_TIMEOUT')) { define('WIDGET_CONNECT_TIMEOUT', 6); }
if (!defined('WIDGET_TOTAL_TIMEOUT'))   { define('WIDGET_TOTAL_TIMEOUT', 12); }
if (!defined('WIDGET_MAX_REDIRECTS'))   { define('WIDGET_MAX_REDIRECTS', 1); } // en çok 1 atlama

/** Piyasa şeridinde beklenen para birimi anahtarları ve etiketleri. */
function widgets_market_keys() {
    return ['USD' => 'USD', 'EUR' => 'EUR', 'GRAM_ALTIN' => 'Gram altın'];
}

/** Panelde ve belgelerde gösterilen beklenen JSON biçimi örnekleri. */
function widgets_sample_json($type) {
    if ($type === 'weather') {
        return '{"sehir":"İstanbul","derece":"24","durum":"Parçalı bulutlu"}';
    }
    return '{"USD":"41,25","EUR":"44,80","GRAM_ALTIN":"4310"}';
}

// ================================================================ önbellek

/** Önbellek dosyası yolu: uploads/cache/widget-<anahtar>.json */
function widgets_cache_path($key) {
    $key = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$key);
    if ($key === '') { $key = 'bilinmeyen'; }
    return CACHE_DIR . '/widget-' . $key . '.json';
}

/** URL'den türetilen önbellek anahtarı (adres değişince önbellek kendiliğinden düşer). */
function widgets_cache_key($url) {
    return 'u' . substr(sha1((string)$url), 0, 20);
}

/**
 * Önbelleği siler.
 * null           → tüm widget önbellek dosyaları
 * 'market'|'weather' → o widget'ın geçerli adresine ait dosya
 * diğer          → doğrudan anahtar
 */
function widgets_clear_cache($key = null) {
    if ($key === null || $key === '' || $key === 'all') {
        foreach ((array)glob(CACHE_DIR . '/widget-*.json') as $f) { @unlink($f); }
        return;
    }
    if ($key === 'market' || $key === 'weather') {
        $url = trim((string)setting('widget_' . $key . '_url', ''));
        if ($url !== '') { @unlink(widgets_cache_path(widgets_cache_key($url))); }
        @unlink(widgets_cache_path($key));
        return;
    }
    @unlink(widgets_cache_path($key));
}

/** Önbellek dosyasını okur: ['cached_at'=>string,'data'=>array,'prev'=>array] ya da null. */
function widgets_cache_read($key) {
    $file = widgets_cache_path($key);
    if (!is_file($file)) { return null; }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') { return null; }
    $env = json_decode($raw, true);
    if (!is_array($env) || !isset($env['data']) || !is_array($env['data'])) { return null; }
    return [
        'cached_at' => (string)arr($env, 'cached_at', ''),
        'data'      => $env['data'],
        'prev'      => is_array(arr($env, 'prev', [])) ? $env['prev'] : [],
    ];
}

/** Önbellek dosyasını yazar; bir önceki veri "prev" olarak saklanır (yön oku için). */
function widgets_cache_write($key, array $data, array $prev = []) {
    ensure_dir(CACHE_DIR);
    $env = ['cached_at' => now(), 'data' => $data, 'prev' => $prev];
    $json = json_encode($env, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) { return false; }
    return @file_put_contents(widgets_cache_path($key), $json, LOCK_EX) !== false;
}

// ================================================================ dış istek

/**
 * Harici JSON adresinden veri çeker ve önbelleğe alır.
 *
 * @param string $url        Harici JSON adresi
 * @param int    $ttlSeconds Önbellek ömrü (varsayılan 900 sn = 15 dk)
 * @return array ['ok'=>bool,'data'=>array,'error'=>string,'cached_at'=>string,
 *                'stale'=>bool,'prev'=>array]
 */
function widgets_fetch_remote($url, $ttlSeconds = 900) {
    $res = ['ok' => false, 'data' => [], 'error' => '', 'cached_at' => '', 'stale' => false, 'prev' => []];
    $ttlSeconds = max(60, min(86400, (int)$ttlSeconds));
    $url = trim((string)$url);
    if ($url === '') {
        $res['error'] = 'Harici JSON adresi girilmedi.';
        return $res;
    }

    // SSRF kapısı — reddedilen adres için istek yapılmaz ve önbellek de sunulmaz.
    $safe = safe_remote_url($url);
    if ($safe === false) {
        $res['error'] = 'Bu adres güvenlik nedeniyle reddedildi (yalnız http/https ve genel IP adresleri).';
        return $res;
    }

    $key = widgets_cache_key($url);
    $cache = widgets_cache_read($key);

    // 1) Taze önbellek varsa dış isteğe hiç çıkılmaz
    if ($cache !== null) {
        $age = time() - (int)strtotime($cache['cached_at']);
        if ($cache['cached_at'] !== '' && $age >= 0 && $age < $ttlSeconds) {
            return ['ok' => true, 'data' => $cache['data'], 'error' => '',
                    'cached_at' => $cache['cached_at'], 'stale' => false, 'prev' => $cache['prev']];
        }
    }

    // 2) Uzak servisten çek
    $http = widgets_http_get($safe);
    if ($http['ok']) {
        $decoded = json_decode((string)$http['body'], true);
        if (!is_array($decoded)) {
            $http['ok'] = false;
            $http['error'] = 'Adresten geçerli JSON gelmedi.';
        } else {
            $prev = $cache !== null ? $cache['data'] : [];
            widgets_cache_write($key, $decoded, $prev);
            return ['ok' => true, 'data' => $decoded, 'error' => '',
                    'cached_at' => now(), 'stale' => false, 'prev' => $prev];
        }
    }

    // 3) Servis çöktüyse süresi geçmiş önbelleğe düşülür
    if ($cache !== null) {
        return ['ok' => true, 'data' => $cache['data'], 'error' => (string)$http['error'],
                'cached_at' => $cache['cached_at'], 'stale' => true, 'prev' => $cache['prev']];
    }

    $res['error'] = (string)$http['error'];
    return $res;
}

/**
 * Tek JSON isteği. Yönlendirme TAKİP EDİLMEZ; Location elle ve
 * safe_remote_url()'den yeniden geçirilerek en çok WIDGET_MAX_REDIRECTS kez izlenir.
 * @return array ['ok'=>bool,'body'=>string,'status'=>int,'error'=>string]
 */
function widgets_http_get($url) {
    $out = ['ok' => false, 'body' => '', 'status' => 0, 'error' => ''];
    $target = (string)$url;

    for ($hop = 0; $hop <= WIDGET_MAX_REDIRECTS; $hop++) {
        // Her atlamada (ilk istek dâhil) adres yeniden kapıdan geçer
        $safeT = safe_remote_target($target);
        if ($safeT === false) {
            $out['error'] = 'Yönlendirilen adres güvenlik nedeniyle reddedildi.';
            return $out;
        }
        $safe = $safeT['url'];

        $one = function_exists('curl_init')
            ? widgets_http_once_curl($safe, $safeT)
            : widgets_http_once_stream($safe);

        $out['status'] = (int)$one['status'];
        if ($one['error'] !== '') { $out['error'] = $one['error']; return $out; }

        if (in_array($out['status'], [301, 302, 303, 307, 308], true)) {
            $next = widgets_absolute_url((string)$one['location'], $safe);
            if ($next === '') { $out['error'] = 'Adres yönlendirdi ama hedef okunamadı.'; return $out; }
            $target = $next;
            continue;
        }

        if ($out['status'] === 0)    { $out['error'] = 'Adres yanıt vermedi.'; return $out; }
        if ($out['status'] >= 400)   { $out['error'] = 'Adres yanıt vermedi (HTTP ' . $out['status'] . ').'; return $out; }
        if (trim((string)$one['body']) === '') { $out['error'] = 'Adresten boş yanıt geldi.'; return $out; }

        $out['ok'] = true;
        $out['body'] = (string)$one['body'];
        return $out;
    }

    $out['error'] = 'Çok fazla yönlendirme (' . WIDGET_MAX_REDIRECTS . ' sınırı aşıldı).';
    return $out;
}

/** Göreli Location başlığını mutlak adrese çevirir. */
function widgets_absolute_url($location, $baseUrl) {
    $location = trim((string)$location);
    if ($location === '') { return ''; }
    if (preg_match('#^https?://#i', $location)) { return $location; }
    $b = parse_url($baseUrl);
    if (!$b || empty($b['scheme']) || empty($b['host'])) { return ''; }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . (int)$b['port'] : '');
    if (strpos($location, '//') === 0) { return $b['scheme'] . ':' . $location; }
    if (strpos($location, '/') === 0)  { return $origin . $location; }
    $dir = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';
    return $origin . $dir . $location;
}

/** curl uygulaması — yönlendirme takibi kapalı, 256 KB tavanı. */
function widgets_http_once_curl($url, $target = null) {
    $out = ['status' => 0, 'body' => '', 'error' => '', 'location' => ''];
    $body = '';
    $tooBig = false;
    $location = '';

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_FOLLOWLOCATION => false,   // SSRF: yönlendirme elle izlenir
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_CONNECTTIMEOUT => WIDGET_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => WIDGET_TOTAL_TIMEOUT,
        CURLOPT_MAXFILESIZE    => WIDGET_MAX_BYTES,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Manset/' . MANSET_VERSION . ' (+widget)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json, text/json;q=0.9, */*;q=0.5'],
        CURLOPT_HEADERFUNCTION => function ($c, $header) use (&$location) {
            if (stripos($header, 'location:') === 0) { $location = trim(substr($header, 9)); }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$body, &$tooBig) {
            $len = strlen($chunk);
            if (strlen($body) + $len > WIDGET_MAX_BYTES) { $tooBig = true; return 0; }
            $body .= $chunk;
            return $len;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $opts[CURLOPT_PROTOCOLS_STR] = 'http,https';
    } elseif (defined('CURLPROTO_HTTP')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($ch, $opts);
    // DNS rebinding kalkanı: doğrulanan IP'ye sabitle (SECURITY_AUDIT B-01)
    if (is_array($target) && !empty($target['resolve'])) { curl_pin_resolved_ip($ch, $target); }

    $ok = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    $out['status'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $out['location'] = $location;
    $out['body'] = $body;

    if ($tooBig) { $out['error'] = 'Yanıt çok büyük (256 KB sınırı aşıldı).'; return $out; }
    if ($ok === false && $errNo !== 0) {
        if ($errNo === CURLE_OPERATION_TIMEOUTED)      { $out['error'] = 'Adres zamanında yanıt vermedi.'; }
        elseif ($errNo === CURLE_COULDNT_RESOLVE_HOST) { $out['error'] = 'Alan adı çözülemedi.'; }
        elseif ($errNo === CURLE_FILESIZE_EXCEEDED)    { $out['error'] = 'Yanıt çok büyük (256 KB sınırı aşıldı).'; }
        else { $out['error'] = 'Adres yanıt vermedi. (' . sanitize_line($errStr, 120) . ')'; }
        log_error('Widget indirme hatası: ' . $errStr, $url);
    }
    return $out;
}

/** curl yoksa akış sarmalayıcı yedeği — yönlendirme takibi yine kapalı. */
function widgets_http_once_stream($url) {
    $out = ['status' => 0, 'body' => '', 'error' => '', 'location' => ''];
    if (!ini_get('allow_url_fopen')) {
        $out['error'] = 'Sunucuda dış bağlantı kapalı (curl yok, allow_url_fopen kapalı).';
        return $out;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'           => 'GET',
            'follow_location'  => 0,
            'max_redirects'    => 0,
            'timeout'          => WIDGET_TOTAL_TIMEOUT,
            'ignore_errors'    => true,
            'protocol_version' => 1.1,
            'header'           => "Accept: application/json, */*;q=0.5\r\n"
                                . 'User-Agent: Manset/' . MANSET_VERSION . " (+widget)\r\nConnection: close\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fh = @fopen($url, 'rb', false, $ctx);
    if (!$fh) { $out['error'] = 'Adres yanıt vermedi.'; return $out; }

    $headers = isset($http_response_header) ? (array)$http_response_header : [];
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $out['status'] = (int)$m[1]; }
        elseif (stripos($h, 'location:') === 0) { $out['location'] = trim(substr($h, 9)); }
    }

    $body = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 16384);
        if ($chunk === false) { break; }
        $body .= $chunk;
        if (strlen($body) > WIDGET_MAX_BYTES) {
            fclose($fh);
            $out['error'] = 'Yanıt çok büyük (256 KB sınırı aşıldı).';
            return $out;
        }
    }
    fclose($fh);
    $out['body'] = $body;
    return $out;
}

// ================================================================ eşleme

/**
 * Nokta yolu ile iç içe diziden değer okur: "data.usd.selling"
 * Sayısal dizin de kullanılabilir: "items.0.value"
 */
function widgets_dot_get(array $data, $path) {
    $path = trim((string)$path);
    if ($path === '') { return null; }
    $node = $data;
    foreach (explode('.', $path) as $seg) {
        $seg = trim($seg);
        if (!is_array($node) || !array_key_exists($seg, $node)) { return null; }
        $node = $node[$seg];
    }
    return is_scalar($node) ? $node : null;
}

/**
 * Basit anahtar eşlemesi. Eşleme metni satır satır "HEDEF=nokta.yolu" biçimindedir:
 *   USD=data.usd.selling
 *   EUR=data.eur.selling
 * Boş eşleme verilirse ham veri olduğu gibi döner.
 */
function widgets_apply_map(array $raw, $mapText) {
    $mapText = trim((string)$mapText);
    if ($mapText === '') { return $raw; }
    $out = [];
    foreach (preg_split('/[\r\n]+/', $mapText) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '=') === false) { continue; }
        list($target, $path) = explode('=', $line, 2);
        $target = trim($target);
        if ($target === '') { continue; }
        $value = widgets_dot_get($raw, $path);
        if ($value !== null && $value !== '') { $out[$target] = $value; }
    }
    return $out ? $out : $raw;
}

/** "4.310,25" / "41,25" / "4310.25" → float ya da null. */
function widgets_number($value) {
    $s = trim((string)$value);
    if ($s === '') { return null; }
    $s = preg_replace('/[^0-9.,\-]/', '', $s);
    if ($s === '' || $s === '-') { return null; }
    $lastComma = strrpos($s, ',');
    $lastDot   = strrpos($s, '.');
    if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
        // Türkçe biçim: nokta binlik, virgül ondalık — "4.310,25"
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif ($lastComma === false && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
        // Yalnız üçerli gruplar: nokta binlik ayracıdır — "4.310" = 4310
        $s = str_replace('.', '', $s);
    } else {
        // İngilizce biçim: virgül binlik, nokta ondalık — "4,310.25"
        $s = str_replace(',', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

/**
 * İki değer arasındaki farkı işaretli metne çevirir: "+0,15" / "-12". Bilinmiyorsa ''.
 * inc/view.php → widget_market_rows() yön okunu bu metnin işaretinden türetir.
 */
function widgets_change_text($current, $previous) {
    $a = widgets_number($current);
    $b = widgets_number($previous);
    if ($a === null || $b === null) { return ''; }
    $d = $a - $b;
    if (abs($d) < 0.00001) { return ''; }
    $s = number_format(abs($d), 2, ',', '.');
    if (substr($s, -3) === ',00') { $s = substr($s, 0, -3); }
    return ($d > 0 ? '+' : '-') . $s;
}

/** İki değeri karşılaştırıp yön verir: yukari | asagi | sabit. */
function widgets_direction($current, $previous) {
    $a = widgets_number($current);
    $b = widgets_number($previous);
    if ($a === null || $b === null) { return 'sabit'; }
    if ($a > $b) { return 'yukari'; }
    if ($a < $b) { return 'asagi'; }
    return 'sabit';
}

/** JSON ayar değerini diziye çevirir (bozuksa boş dizi). */
function widgets_setting_json($key) {
    $raw = trim((string)setting($key, ''));
    if ($raw === '') { return []; }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/** "guncelleme" metni üretir. */
function widgets_updated_text($when, $stale = false, $manual = false) {
    $when = trim((string)$when);
    if ($when === '') { return $manual ? 'Elle girildi' : ''; }
    $t = tr_date($when);
    if ($stale) { return $t . ' (eski veri — servise ulaşılamadı)'; }
    return $manual ? $t . ' (elle girildi)' : $t;
}

// ================================================================ widget verisi

/**
 * Piyasa şeridi verisi — inc/view.php → widget_market() bunu çağırır.
 *
 * @return array ['USD'=>['deger'=>'41,25','yon'=>'yukari|asagi|sabit'], 'EUR'=>…,
 *                'GRAM_ALTIN'=>…, 'guncelleme'=>'…', 'items'=>[…]]
 *  'items' anahtarı inc/view.php → widget_market_rows() içindir; oradaki
 *  indirgeyici 'items' varsa yalnız onu okur, böylece 'guncelleme' satır sanılmaz.
 */
function widgets_market_data() {
    $mode = setting('widget_market_mode', 'manual') === 'remote' ? 'remote' : 'manual';
    $prev = [];
    $stale = false;
    $when = '';

    if ($mode === 'remote') {
        $url = trim((string)setting('widget_market_url', ''));
        $res = widgets_fetch_remote($url, WIDGET_TTL);
        if (!$res['ok']) { return []; }   // veri yok → tema bloğu basmaz
        $raw = widgets_apply_map($res['data'], setting('widget_market_map', ''));
        $prev = $res['prev'] ? widgets_apply_map($res['prev'], setting('widget_market_map', '')) : [];
        $stale = !empty($res['stale']);
        $when = (string)$res['cached_at'];
    } else {
        $raw = widgets_setting_json('widget_market_data');
        $prev = widgets_setting_json('widget_market_prev');
        $when = (string)setting('widget_market_saved_at', '');
    }
    if (!$raw) { return []; }

    // Bilinen anahtarlar önce, ek anahtarlar sonra
    $order = array_keys(widgets_market_keys());
    foreach (array_keys($raw) as $k) {
        if (!in_array((string)$k, $order, true)) { $order[] = (string)$k; }
    }

    // Üstveri anahtarları şeride satır olarak girmez
    $reserved = ['guncelleme', 'updated', 'updated_at', 'kaynak', 'source', 'tarih', 'date', 'time', 'timestamp', 'items'];

    $labels = widgets_market_keys();
    $out = [];
    $items = [];
    foreach ($order as $k) {
        if (!array_key_exists($k, $raw)) { continue; }
        if (in_array(mb_strtolower((string)$k, 'UTF-8'), $reserved, true)) { continue; }
        $v = $raw[$k];
        if (is_array($v)) { $v = arr($v, 'deger', arr($v, 'value', '')); }
        $deger = sanitize_line((string)$v, 40);
        // Şerit yalnız sayısal değer basar; metin üstverisi atlanır
        if ($deger === '' || widgets_number($deger) === null) { continue; }
        $prevVal = arr($prev, $k, '');
        if (is_array($prevVal)) { $prevVal = arr($prevVal, 'deger', arr($prevVal, 'value', '')); }
        $yon = widgets_direction($deger, (string)$prevVal);
        $degisim = widgets_change_text($deger, (string)$prevVal);
        $out[$k] = ['deger' => $deger, 'yon' => $yon, 'degisim' => $degisim];
        $items[$k] = [
            'label'   => isset($labels[$k]) ? $labels[$k] : str_replace('_', ' ', $k),
            'deger'   => $deger,
            'degisim' => $degisim,
        ];
    }
    if (!$out) { return []; }

    $out['guncelleme'] = widgets_updated_text($when, $stale, $mode === 'manual');
    $out['items'] = $items;
    return $out;
}

/**
 * Hava durumu verisi — inc/view.php → widget_weather() bunu çağırır.
 * @return array ['sehir'=>…,'derece'=>…,'durum'=>…,'ikon'=>…,'guncelleme'=>…]
 */
function widgets_weather_data() {
    $mode = setting('widget_weather_mode', 'manual') === 'remote' ? 'remote' : 'manual';
    $stale = false;
    $when = '';

    if ($mode === 'remote') {
        $url = trim((string)setting('widget_weather_url', ''));
        $res = widgets_fetch_remote($url, WIDGET_TTL);
        if (!$res['ok']) { return []; }
        $raw = widgets_apply_map($res['data'], setting('widget_weather_map', ''));
        $stale = !empty($res['stale']);
        $when = (string)$res['cached_at'];
    } else {
        $raw = widgets_setting_json('widget_weather_data');
        $when = (string)setting('widget_weather_saved_at', '');
    }
    if (!$raw) { return []; }

    $sehir  = sanitize_line((string)arr($raw, 'sehir', arr($raw, 'city', '')), 60);
    $derece = sanitize_line((string)arr($raw, 'derece', arr($raw, 'temp', '')), 10);
    $durum  = sanitize_line((string)arr($raw, 'durum', arr($raw, 'text', '')), 60);
    if ($sehir === '' && $derece === '') { return []; }

    $ikon = sanitize_line((string)arr($raw, 'ikon', arr($raw, 'icon', '')), 8);
    if ($ikon === '') { $ikon = widgets_weather_icon($durum); }

    return [
        'sehir'      => $sehir,
        'derece'     => $derece,
        'durum'      => $durum,
        'ikon'       => $ikon,
        'guncelleme' => widgets_updated_text($when, $stale, $mode === 'manual'),
    ];
}

/** Durum metninden basit simge seçer (dış kütüphane/ikon seti yok). */
function widgets_weather_icon($durum) {
    $d = mb_strtolower((string)$durum, 'UTF-8');
    $map = [
        'fırtına' => '⛈', 'firtina' => '⛈', 'gök gürültülü' => '⛈',
        'kar' => '❄', 'sulu' => '🌨',
        'sağanak' => '🌧', 'saganak' => '🌧', 'yağmur' => '🌧', 'yagmur' => '🌧',
        'sis' => '🌫', 'puslu' => '🌫',
        'parçalı' => '⛅', 'parcali' => '⛅', 'az bulut' => '⛅',
        'bulut' => '☁', 'kapalı' => '☁', 'kapali' => '☁',
        'açık' => '☀', 'acik' => '☀', 'güneş' => '☀', 'gunes' => '☀',
    ];
    foreach ($map as $needle => $icon) {
        if ($d !== '' && mb_strpos($d, $needle) !== false) { return $icon; }
    }
    return '☁';
}

/**
 * Önbellek durumu (panelde gösterilir).
 * @return array ['var'=>bool,'cached_at'=>string,'yas'=>string,'dosya'=>string,'taze'=>bool]
 */
function widgets_cache_status($type) {
    $url = trim((string)setting('widget_' . $type . '_url', ''));
    if ($url === '') { return ['var' => false, 'cached_at' => '', 'yas' => '', 'dosya' => '', 'taze' => false]; }
    $key = widgets_cache_key($url);
    $cache = widgets_cache_read($key);
    if ($cache === null) {
        return ['var' => false, 'cached_at' => '', 'yas' => '', 'dosya' => basename(widgets_cache_path($key)), 'taze' => false];
    }
    $age = time() - (int)strtotime($cache['cached_at']);
    return [
        'var'       => true,
        'cached_at' => $cache['cached_at'],
        'yas'       => $age < 60 ? $age . ' saniye' : (int)floor($age / 60) . ' dakika',
        'dosya'     => basename(widgets_cache_path($key)),
        'taze'      => $age < WIDGET_TTL,
    ];
}
