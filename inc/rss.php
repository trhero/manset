<?php
/**
 * Manşet — RSS/Atom çekim motoru (Ajan-4).
 *
 * Sorumluluk: dış beslemeleri güvenle indirmek, ayrıştırmak, `rss_items` havuzuna
 * mükerrersiz yazmak ve istendiğinde havuz ögesinden haber taslağı üretmek.
 *
 * Güvenlik notu (SSRF): her dış istek öncesi `safe_remote_url()` çağrılır,
 * curl yönlendirme takibi kapalıdır, yönlendirmeler elle ve yeniden doğrulanarak
 * en çok 2 kez izlenir, indirme 3 MB ile sınırlıdır.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';
// schema_has_column(): göç 013'ün sütunları var mı diye bakarız (geriye dönük uyum).
require_once __DIR__ . '/schema.php';

// ---------------------------------------------------------------- sabitler
if (!defined('RSS_MAX_BYTES'))        { define('RSS_MAX_BYTES', 3145728); }   // 3 MB indirme tavanı
if (!defined('RSS_CONNECT_TIMEOUT'))  { define('RSS_CONNECT_TIMEOUT', 8); }   // bağlantı zaman aşımı (sn)
if (!defined('RSS_TOTAL_TIMEOUT'))    { define('RSS_TOTAL_TIMEOUT', 20); }    // toplam zaman aşımı (sn)
if (!defined('RSS_MAX_REDIRECTS'))    { define('RSS_MAX_REDIRECTS', 2); }     // elle izlenecek yönlendirme sayısı
if (!defined('RSS_MAX_ERRORS'))       { define('RSS_MAX_ERRORS', 5); }        // bu kadar üst üste hatada kaynak kapatılır
if (!defined('RSS_SOURCES_PER_TICK')) { define('RSS_SOURCES_PER_TICK', 5); }  // cron turu başına kaynak sayısı
if (!defined('RSS_MAX_ITEMS'))        { define('RSS_MAX_ITEMS', 100); }       // tek beslemeden alınacak en çok öge
if (!defined('RSS_DEFAULT_INTERVAL')) { define('RSS_DEFAULT_INTERVAL', 10); }  // kaynak başına varsayılan çekim aralığı (dk)

/**
 * GERİ ÇEKİLME MERDİVENİ (1.1-07).
 *
 * NEDEN: 1.0'da 5 hata alan kaynak `active = 0` yapılıyordu ve bir daha HİÇ
 * denenmiyordu. Kaynağın sunucusu on dakika çökse, ajansın beslemesi kalıcı
 * olarak ölüyordu; yayıncı bunu ancak günler sonra fark ediyordu.
 *
 * Yerine "beklemede" davranışı: hata sayısına göre bir sonraki denemenin saati
 * (`rss_sources.next_check_at`) ileri atılır. 5 hatadan sonra 24 saatte bir
 * denenmeye devam eder; başarılı ilk çekimde sayaç sıfırlanır ve kaynak normal
 * aralığına döner. `active` sütunu YALNIZ yayıncının elle kapatmasına ayrılır.
 */
function rss_backoff_steps() {
    return [5, 15, 60, 360, 1440];   // dakika: 5dk → 15dk → 1sa → 6sa → 24sa
}

/** Hata sayısına göre bekleme süresi (dakika). */
function rss_backoff_minutes($errorCount) {
    $steps = rss_backoff_steps();
    $i = max(1, (int)$errorCount) - 1;
    if ($i >= count($steps)) { $i = count($steps) - 1; }
    return (int)$steps[$i];
}

/** Geri çekilme sütunları (göç 013) var mı? */
function rss_has_backoff_columns() {
    static $var = null;
    if ($var === null) {
        $var = function_exists('schema_has_column')
            && schema_has_column('rss_sources', 'next_check_at')
            && schema_has_column('rss_sources', 'interval_min');
    }
    return $var;
}

/** Kaynağın çekim aralığı (dk). 0/boş ise varsayılan kullanılır. */
function rss_source_interval($source) {
    $v = is_array($source) ? (int)arr($source, 'interval_min', 0) : (int)$source;
    if ($v <= 0) { return RSS_DEFAULT_INTERVAL; }
    return max(1, min(10080, $v));   // 1 dk – 7 gün
}

// XML ad alanları (önek yerine URI kullanılır — beslemeler önekleri serbestçe seçer)
if (!defined('RSS_NS_CONTENT')) { define('RSS_NS_CONTENT', 'http://purl.org/rss/1.0/modules/content/'); }
if (!defined('RSS_NS_MEDIA'))   { define('RSS_NS_MEDIA',   'http://search.yahoo.com/mrss/'); }
if (!defined('RSS_NS_DC'))      { define('RSS_NS_DC',      'http://purl.org/dc/elements/1.1/'); }
if (!defined('RSS_NS_ATOM'))    { define('RSS_NS_ATOM',    'http://www.w3.org/2005/Atom'); }
if (!defined('RSS_NS_RSS1'))    { define('RSS_NS_RSS1',    'http://purl.org/rss/1.0/'); }

/** `rss_items.status` için izinli değerler (CONTRACTS §2). */
function rss_statuses() {
    return ['new' => 'Yeni', 'queued' => 'Kuyrukta', 'processed' => 'İşlendi', 'skipped' => 'Atlandı'];
}

// ================================================================ SSRF kalkanı

/**
 * Dış adres kapısı. Geçerliyse normalize URL, değilse false döner.
 *
 * Test ortamında yerel besleme dosyalarına izin verilir (MANSET_RSS_ALLOW_LOCAL=1).
 * Üretimde bu değişken tanımlı olmadığından SSRF koruması tam yürürlüktedir:
 * `safe_remote_url()` yalnız http/https şemasını kabul eder, localhost / özel /
 * ayrılmış IP'leri ve çözülemeyen alan adlarını reddeder.
 */
function rss_guard_url($url) {
    $t = rss_guard_target($url);
    return $t === false ? false : $t['url'];
}

/**
 * rss_guard_url()'in ayrıntılı sürümü: doğrulanan IP'yi de döndürür.
 * Bağlantı bu IP'ye sabitlenir (curl_pin_resolved_ip) — DNS rebinding kalkanı.
 */
function rss_guard_target($url) {
    $url = trim((string)$url);
    if ($url === '' || strlen($url) > 2000) { return false; }

    // Test ortamında yerel besleme dosyalarına izin verilir (MANSET_RSS_ALLOW_LOCAL=1).
    // Üretimde bu değişken tanımlı olmadığından SSRF koruması tam yürürlüktedir.
    $allowLocal = (bool)getenv('MANSET_RSS_ALLOW_LOCAL');

    if ($allowLocal) {
        // YALNIZ test kaçış noktası: şema denetimi yapılır, IP/DNS denetimi atlanır.
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) { return false; }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') { return false; }
        if (!empty($parts['user']) || !empty($parts['pass'])) { return false; }
        // Test yolunda IP sabitleme yapılmaz (host zaten 127.0.0.1).
        return ['url' => $url, 'host' => strtolower($parts['host']), 'ip' => '',
                'ips' => [], 'port' => 0, 'scheme' => $scheme, 'resolve' => ''];
    }

    // Üretim yolu: tam SSRF kalkanı (inc/sanitize.php).
    return safe_remote_target($url);
}

/** Kullanıcıya gösterilecek standart ret mesajı. */
function rss_rejected_message() {
    return 'Bu adres güvenlik nedeniyle reddedildi (yalnız http/https ve genel IP adresleri).';
}

/** Göreli Location başlığını mutlak adrese çevirir. */
function rss_absolute_url($location, $baseUrl) {
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

// ================================================================ HTTP

/**
 * Beslemeyi indirir.
 *
 * @return array ['ok'=>bool,'body'=>string,'status'=>int,'error'=>string,'content_type'=>string]
 */
function rss_http_get($url, $timeout = 20) {
    $res = ['ok' => false, 'body' => '', 'status' => 0, 'error' => '', 'content_type' => ''];
    $timeout = max(5, min(60, (int)$timeout));
    $target = (string)$url;

    for ($hop = 0; $hop <= RSS_MAX_REDIRECTS; $hop++) {
        // SSRF: her atlamada (ilk istek dâhil) adres yeniden kapıdan geçirilir
        $safeT = rss_guard_target($target);
        if ($safeT === false) {
            $res['error'] = rss_rejected_message();
            return $res;
        }
        $safe = $safeT['url'];

        $one = function_exists('curl_init')
            ? rss_http_once_curl($safe, $timeout, $safeT)
            : rss_http_once_stream($safe, $timeout);

        $res['status'] = (int)$one['status'];
        $res['content_type'] = (string)$one['content_type'];

        if ($one['error'] !== '') { $res['error'] = $one['error']; return $res; }

        // 301/302/303/307/308 → Location yeniden doğrulanarak elle izlenir
        if (in_array($res['status'], [301, 302, 303, 307, 308], true)) {
            $next = rss_absolute_url($one['location'], $safe);
            if ($next === '') { $res['error'] = 'Adres yönlendirdi ama hedef okunamadı.'; return $res; }
            $target = $next;
            continue;
        }

        if ($res['status'] === 0) { $res['error'] = 'Adres yanıt vermedi.'; return $res; }
        if ($res['status'] === 404) { $res['error'] = 'Adres bulunamadı (HTTP 404).'; return $res; }
        if ($res['status'] === 403 || $res['status'] === 401) { $res['error'] = 'Sunucu erişimi reddetti (HTTP ' . $res['status'] . ').'; return $res; }
        if ($res['status'] >= 400) { $res['error'] = 'Adres yanıt vermedi (HTTP ' . $res['status'] . ').'; return $res; }

        if (trim((string)$one['body']) === '') { $res['error'] = 'Besleme boş geldi.'; return $res; }

        $res['ok'] = true;
        $res['body'] = (string)$one['body'];
        return $res;
    }

    $res['error'] = 'Çok fazla yönlendirme (' . RSS_MAX_REDIRECTS . ' sınırı aşıldı).';
    return $res;
}

/** Tek HTTP isteği — curl uygulaması. Yönlendirme TAKİP EDİLMEZ. */
function rss_http_once_curl($url, $timeout, $target = null) {
    $out = ['status' => 0, 'body' => '', 'error' => '', 'content_type' => '', 'location' => ''];
    $body = '';
    $tooBig = false;
    $location = '';

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_FOLLOWLOCATION => false,  // SSRF: yönlendirme atlaması engellenir, elle izlenir
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_CONNECTTIMEOUT => RSS_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_MAXFILESIZE    => RSS_MAX_BYTES,   // Content-Length bilinirse baştan keser
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'Manset/' . MANSET_VERSION . ' (+RSS okuyucu)',
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.5'],
        CURLOPT_HEADERFUNCTION => function ($c, $header) use (&$location) {
            if (stripos($header, 'location:') === 0) { $location = trim(substr($header, 9)); }
            return strlen($header);
        },
        // Yazma geri çağrısı: 3 MB üstünde aktarım kesilir
        CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$body, &$tooBig) {
            $len = strlen($chunk);
            if (strlen($body) + $len > RSS_MAX_BYTES) { $tooBig = true; return 0; }
            $body .= $chunk;
            return $len;
        },
    ];
    // Şema kilidi: yalnız http/https (curl sürümüne göre uygun seçenek)
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
    $out['content_type'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    $out['location'] = $location;
    $out['body'] = $body;

    if ($tooBig) { $out['error'] = 'Besleme çok büyük (3 MB sınırı aşıldı).'; return $out; }
    if ($ok === false && $errNo !== 0) {
        if ($errNo === CURLE_OPERATION_TIMEOUTED) { $out['error'] = 'Adres zamanında yanıt vermedi.'; }
        elseif ($errNo === CURLE_COULDNT_RESOLVE_HOST) { $out['error'] = 'Alan adı çözülemedi.'; }
        elseif ($errNo === CURLE_FILESIZE_EXCEEDED) { $out['error'] = 'Besleme çok büyük (3 MB sınırı aşıldı).'; }
        else { $out['error'] = 'Adres yanıt vermedi. (' . sanitize_line($errStr, 120) . ')'; }
        log_error('RSS indirme hatası: ' . $errStr, $url);
    }
    return $out;
}

/** Tek HTTP isteği — curl yoksa akış sarmalayıcı yedeği. Yönlendirme TAKİP EDİLMEZ. */
function rss_http_once_stream($url, $timeout) {
    $out = ['status' => 0, 'body' => '', 'error' => '', 'content_type' => '', 'location' => ''];
    if (!ini_get('allow_url_fopen')) {
        $out['error'] = 'Sunucuda dış bağlantı kapalı (curl yok, allow_url_fopen kapalı).';
        return $out;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'          => 'GET',
            'follow_location' => 0,   // SSRF: yönlendirme takibi kapalı
            'max_redirects'   => 0,
            'timeout'         => $timeout,
            'ignore_errors'   => true,
            'protocol_version' => 1.1,
            'header'          => "Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.5\r\n"
                               . 'User-Agent: Manset/' . MANSET_VERSION . " (+RSS okuyucu)\r\nConnection: close\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $fh = @fopen($url, 'rb', false, $ctx);
    if (!$fh) { $out['error'] = 'Adres yanıt vermedi.'; return $out; }

    $headers = isset($http_response_header) ? (array)$http_response_header : [];
    foreach ($headers as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $out['status'] = (int)$m[1]; }
        elseif (stripos($h, 'location:') === 0) { $out['location'] = trim(substr($h, 9)); }
        elseif (stripos($h, 'content-type:') === 0) { $out['content_type'] = trim(substr($h, 13)); }
    }

    $body = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 16384);
        if ($chunk === false) { break; }
        $body .= $chunk;
        if (strlen($body) > RSS_MAX_BYTES) {  // 3 MB tavanı
            fclose($fh);
            $out['error'] = 'Besleme çok büyük (3 MB sınırı aşıldı).';
            return $out;
        }
    }
    fclose($fh);
    $out['body'] = $body;
    return $out;
}

// ================================================================ karakter seti

/**
 * Gövdeyi UTF-8'e çevirir. Kaynak: Content-Type başlığındaki charset ya da XML bildirimi.
 * mbstring yoksa iconv, o da yoksa gövde olduğu gibi bırakılır.
 */
function rss_normalize_charset($body, $contentType = '') {
    $body = (string)$body;
    if ($body === '') { return ''; }

    // UTF-8 BOM temizliği
    if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) { $body = substr($body, 3); }

    $charset = '';
    if ($contentType !== '' && preg_match('/charset\s*=\s*["\']?([A-Za-z0-9_\-]+)/i', (string)$contentType, $m)) {
        $charset = $m[1];
    }
    if ($charset === '' && preg_match('/<\?xml[^>]*?encoding\s*=\s*["\']([A-Za-z0-9_\-]+)["\']/i', substr($body, 0, 300), $m)) {
        $charset = $m[1];
    }
    if ($charset === '') { $charset = 'UTF-8'; }

    $norm = strtoupper(str_replace('_', '-', trim($charset)));
    if ($norm === 'UTF8') { $norm = 'UTF-8'; }

    if ($norm !== 'UTF-8') {
        $converted = false;
        if (function_exists('mb_convert_encoding') && extension_loaded('mbstring')) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $norm);
        }
        if ((!is_string($converted) || $converted === '') && function_exists('iconv')) {
            $converted = @iconv($norm, 'UTF-8//IGNORE', $body);
        }
        if (is_string($converted) && $converted !== '') { $body = $converted; }
        // mbstring de iconv da yoksa gövde olduğu gibi bırakılır
    }

    // Gövde artık UTF-8: XML bildirimi de bunu söylemeli, yoksa libxml yeniden çözer
    $body = preg_replace('/(<\?xml[^>]*?encoding\s*=\s*["\'])([A-Za-z0-9_\-]+)(["\'])/i', '${1}UTF-8${3}', $body, 1);

    return $body;
}

// ================================================================ ayrıştırma

/**
 * RSS 2.0 / Atom / RDF (RSS 1.0) beslemesini normalize öge dizisine çevirir.
 *
 * @return array ['ok'=>bool,'items'=>array,'error'=>string,'kaynak_adi'=>string]
 */
function rss_parse($xml) {
    $out = ['ok' => false, 'items' => [], 'error' => '', 'kaynak_adi' => ''];

    $xml = (string)$xml;
    if (strncmp($xml, "\xEF\xBB\xBF", 3) === 0) { $xml = substr($xml, 3); }
    $xml = ltrim($xml);
    if ($xml === '') { $out['error'] = 'Besleme boş geldi.'; return $out; }

    if (!function_exists('simplexml_load_string')) {
        $out['error'] = 'Sunucuda SimpleXML eklentisi yok; RSS okunamıyor.';
        return $out;
    }
    // XXE savunması: DOCTYPE/ENTITY bildirimi olan belge hiç ayrıştırılmaz
    if (preg_match('/<!DOCTYPE|<!ENTITY/i', substr($xml, 0, 2000))) {
        $out['error'] = 'Besleme güvenlik nedeniyle reddedildi (DOCTYPE bildirimi içeriyor).';
        return $out;
    }
    if (strncmp($xml, '<', 1) !== 0) {
        $out['error'] = 'Adres XML değil, bir web sayfası döndürdü. Besleme adresini doğrulayın.';
        return $out;
    }

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    // LIBXML_NOENT KULLANILMAZ (varlık genişletme = XXE riski).
    // LIBXML_NONET dış varlık/DTD indirmesini kapatır. PHP 8 varsayılanı da güvenlidir.
    $flags = LIBXML_NOCDATA | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR;
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', $flags);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($sx === false) {
        $detail = ($errors && isset($errors[0])) ? sanitize_line($errors[0]->message, 120) : '';
        $out['error'] = 'Besleme çözümlenemedi: geçerli bir XML değil.' . ($detail !== '' ? ' (' . $detail . ')' : '');
        return $out;
    }

    $root = strtolower($sx->getName());
    $raw = [];

    if ($root === 'rss' && isset($sx->channel)) {
        // RSS 2.0 / 0.9x
        $out['kaynak_adi'] = sanitize_line((string)$sx->channel->title, 190);
        foreach ($sx->channel->item as $it) { $raw[] = rss_read_rss_item($it); if (count($raw) >= RSS_MAX_ITEMS) { break; } }
    } elseif ($root === 'feed') {
        // Atom 1.0
        $out['kaynak_adi'] = sanitize_line(rss_node_html($sx, 'title'), 190);
        foreach ($sx->entry as $e) { $raw[] = rss_read_atom_entry($e); if (count($raw) >= RSS_MAX_ITEMS) { break; } }
    } elseif ($root === 'rdf') {
        // RDF / RSS 1.0 — ögeler kök altında, rss1 ad alanında
        $c1 = $sx->children(RSS_NS_RSS1);
        if (isset($c1->channel->title)) { $out['kaynak_adi'] = sanitize_line((string)$c1->channel->title, 190); }
        foreach ($c1->item as $it) { $raw[] = rss_read_rss_item($it, RSS_NS_RSS1); if (count($raw) >= RSS_MAX_ITEMS) { break; } }
    } elseif ($root === 'channel' && isset($sx->item)) {
        // Bazı beslemeler kökte doğrudan channel verir
        $out['kaynak_adi'] = sanitize_line((string)$sx->title, 190);
        foreach ($sx->item as $it) { $raw[] = rss_read_rss_item($it); if (count($raw) >= RSS_MAX_ITEMS) { break; } }
    } else {
        $out['error'] = 'Tanınmayan besleme biçimi (RSS 2.0, Atom veya RSS 1.0 bekleniyordu).';
        return $out;
    }

    $items = [];
    foreach ($raw as $r) {
        $item = rss_normalize_item($r);
        if ($item['title'] === '' && $item['link'] === '') { continue; }
        $items[] = $item;
    }

    if (!$items) { $out['error'] = 'Beslemede öge bulunamadı.'; return $out; }

    $out['ok'] = true;
    $out['items'] = $items;
    return $out;
}

/** SimpleXML alt düğümünün metnini/HTML'ini verir (xhtml içerik dâhil). */
function rss_node_html($parent, $name, $ns = null) {
    $holder = $ns === null ? $parent : $parent->children($ns);
    if ($holder === null || !isset($holder->{$name})) { return ''; }
    $node = $holder->{$name};
    $attrs = $node->attributes();
    $type = $attrs !== null ? strtolower((string)$attrs['type']) : '';
    $kids = $node->children();
    if ($type === 'xhtml' || ($kids !== null && $kids->count() > 0)) {
        $raw = (string)$node->asXML();
        $raw = preg_replace('#^<[^>]*>#s', '', $raw, 1);
        $raw = preg_replace('#</[^>]*>$#s', '', $raw, 1);
        return (string)$raw;
    }
    return (string)$node;
}

/** RSS 2.0 / RSS 1.0 <item> düğümünü ham diziye çevirir. */
function rss_read_rss_item($it, $ns = null) {
    $core = $ns === null ? $it : $it->children($ns);

    $link = isset($core->link) ? trim((string)$core->link) : '';
    if ($link === '') {
        // Bazı RSS beslemeleri atom:link kullanır
        $atom = $it->children(RSS_NS_ATOM);
        if (isset($atom->link)) {
            foreach ($atom->link as $l) {
                $a = $l->attributes();
                $rel = strtolower((string)$a['rel']);
                if ($rel === '' || $rel === 'alternate') { $link = (string)$a['href']; break; }
            }
        }
    }

    $guid = isset($core->guid) ? trim((string)$core->guid) : '';
    if ($guid === '' && isset($it->guid)) { $guid = trim((string)$it->guid); }

    $date = isset($core->pubDate) ? (string)$core->pubDate : '';
    if ($date === '') {
        $dc = $it->children(RSS_NS_DC);
        if (isset($dc->date)) { $date = (string)$dc->date; }
    }
    if ($date === '' && isset($it->pubDate)) { $date = (string)$it->pubDate; }

    $content = '';
    $ce = $it->children(RSS_NS_CONTENT);
    if (isset($ce->encoded)) { $content = (string)$ce->encoded; }

    // enclosure: yalnız image/* kabul edilir
    $enclosure = '';
    if (isset($it->enclosure)) {
        foreach ($it->enclosure as $en) {
            $a = $en->attributes();
            $u = trim((string)$a['url']);
            $t = strtolower(trim((string)$a['type']));
            if ($u === '') { continue; }
            if (strpos($t, 'image/') === 0 || ($t === '' && preg_match('/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', $u))) {
                $enclosure = $u;
                break;
            }
        }
    }

    return [
        'title'     => isset($core->title) ? (string)$core->title : '',
        'link'      => $link,
        'guid'      => $guid,
        'summary'   => isset($core->description) ? (string)$core->description : '',
        'content'   => $content,
        'date'      => $date,
        'enclosure' => $enclosure,
        'media'     => rss_read_media($it),
    ];
}

/** Atom <entry> düğümünü ham diziye çevirir. */
function rss_read_atom_entry($e) {
    $link = '';
    $enclosure = '';
    if (isset($e->link)) {
        foreach ($e->link as $l) {
            $a = $l->attributes();
            $rel = strtolower((string)$a['rel']);
            $href = trim((string)$a['href']);
            if ($href === '') { continue; }
            if (($rel === '' || $rel === 'alternate') && $link === '') { $link = $href; }
            if ($rel === 'enclosure' && $enclosure === '' && strpos(strtolower((string)$a['type']), 'image/') === 0) {
                $enclosure = $href;
            }
        }
    }

    $date = isset($e->published) ? (string)$e->published : '';
    if ($date === '' && isset($e->updated)) { $date = (string)$e->updated; }

    return [
        'title'     => rss_node_html($e, 'title'),
        'link'      => $link,
        'guid'      => isset($e->id) ? trim((string)$e->id) : '',
        'summary'   => rss_node_html($e, 'summary'),
        'content'   => rss_node_html($e, 'content'),
        'date'      => $date,
        'enclosure' => $enclosure,
        'media'     => rss_read_media($e),
    ];
}

/** media:content / media:thumbnail görsel adayını okur. */
function rss_read_media($node) {
    $m = $node->children(RSS_NS_MEDIA);
    if (!$m) { return ''; }
    if (isset($m->content)) {
        foreach ($m->content as $mc) {
            $a = $mc->attributes();
            $u = trim((string)$a['url']);
            $t = strtolower(trim((string)$a['type']));
            $medium = strtolower(trim((string)$a['medium']));
            if ($u === '') { continue; }
            if (strpos($t, 'image/') === 0 || $medium === 'image' || ($t === '' && $medium === '')) { return $u; }
        }
    }
    if (isset($m->thumbnail)) {
        foreach ($m->thumbnail as $mt) {
            $u = trim((string)$mt->attributes()['url']);
            if ($u !== '') { return $u; }
        }
    }
    return '';
}

/** Ham öge dizisini temizlenmiş/normalize öge dizisine çevirir. */
function rss_normalize_item(array $r) {
    $title = sanitize_line(rss_decode_entities((string)arr($r, 'title', '')), 255);

    $link = trim((string)arr($r, 'link', ''));
    if ($link !== '' && !preg_match('#^https?://#i', $link)) { $link = ''; }
    if (strlen($link) > 500) { $link = substr($link, 0, 500); }

    $summaryRaw = (string)arr($r, 'summary', '');
    $summary = sanitize_plain(rss_decode_entities(strip_tags($summaryRaw)), 2000);

    $contentRaw = (string)arr($r, 'content', '');
    if (trim($contentRaw) === '') { $contentRaw = $summaryRaw; }
    if (trim($contentRaw) !== '' && strip_tags($contentRaw) === $contentRaw) {
        // Düz metin gövde: okunabilir paragraflara çevrilir
        $contentRaw = rss_text_to_html(rss_decode_entities($contentRaw));
    }
    // Gövde HTML'i beyaz listeden geçer; iframe RSS'te kabul edilmez
    $content = sanitize_html($contentRaw, false);

    $item = [
        'title'        => $title,
        'link'         => $link,
        'guid'         => sanitize_line((string)arr($r, 'guid', ''), 500),
        'summary'      => $summary,
        'content'      => $content,
        'published_at' => rss_parse_date((string)arr($r, 'date', '')),
        'enclosure'    => (string)arr($r, 'enclosure', ''),
        'media'        => (string)arr($r, 'media', ''),
    ];
    $item['image'] = rss_pick_image($item, $content);
    return $item;
}

/** Düz metni paragraflı HTML'e çevirir (etiketsiz beslemeler için). */
function rss_text_to_html($text) {
    $text = trim(str_replace(["\r\n", "\r"], "\n", (string)$text));
    if ($text === '') { return ''; }
    $out = '';
    foreach (preg_split('/\n{2,}/', $text) as $par) {
        $par = trim($par);
        if ($par === '') { continue; }
        $out .= '<p>' . esc(str_replace("\n", ' ', $par)) . "</p>\n";
    }
    return trim($out);
}

/** CDATA içinde kalmış HTML varlıklarını çözer. */
function rss_decode_entities($text) {
    return html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

/** RFC822 / ISO8601 tarihini 'Y-m-d H:i:s' biçimine çevirir; okunamazsa ''. */
function rss_parse_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') { return ''; }
    $ts = strtotime($raw);
    if ($ts === false || $ts <= 0) { return ''; }
    // Aşırı sapmış tarihleri kabul etme (bozuk beslemeler sıralamayı bozmasın)
    if ($ts > time() + 86400 * 2) { $ts = time(); }
    if ($ts < 946684800) { return ''; } // 2000-01-01 öncesi
    return date('Y-m-d H:i:s', $ts);
}

/**
 * Görsel adayını seçer: enclosure (image/*) → media:content/thumbnail → içerikteki ilk <img src>.
 * Görsel İNDİRİLMEZ; yalnız URL saklanır ve SSRF kapısından geçirilir.
 */
function rss_pick_image(array $rawItem, $html = '') {
    $cands = [];
    $enc = trim((string)arr($rawItem, 'enclosure', ''));
    if ($enc !== '') { $cands[] = $enc; }
    $media = trim((string)arr($rawItem, 'media', ''));
    if ($media !== '') { $cands[] = $media; }

    $probe = (string)$html;
    if (trim($probe) === '') { $probe = (string)arr($rawItem, 'content', '') . ' ' . (string)arr($rawItem, 'summary', ''); }
    if (preg_match('/<img\b[^>]*?\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s">]+))/i', $probe, $m)) {
        $found = $m[1] !== '' ? $m[1] : (isset($m[2]) && $m[2] !== '' ? $m[2] : (isset($m[3]) ? $m[3] : ''));
        $found = trim(rss_decode_entities($found));
        if ($found !== '') { $cands[] = $found; }
    }

    foreach ($cands as $c) {
        if (strlen($c) > 500) { continue; }
        // Görsel indirilmez ama URL yine de kapıdan geçer (SSRF + şema denetimi)
        $safe = rss_guard_url($c);
        if ($safe !== false) { return $safe; }
    }
    return '';
}

/** Mükerrer engeli için sabit anahtar: kaynak + link + guid + başlık. */
function rss_guid_hash(array $item, $sourceId) {
    $link  = trim((string)arr($item, 'link', ''));
    $guid  = trim((string)arr($item, 'guid', ''));
    $title = trim((string)arr($item, 'title', ''));
    $seed = (int)$sourceId . '|' . $link . '|' . $guid . '|' . mb_strtolower($title, 'UTF-8');
    return sha1($seed);
}

// ================================================================ kaynak çekimi

/**
 * Bir kaynağı çeker. $dryRun true ise hiçbir şey yazılmaz (yalnız "Test et").
 *
 * @return array ['ok'=>bool,'yeni'=>int,'toplam'=>int,'error'=>string,'ornekler'=>array]
 *               + ek: 'gorsel_var'(bool), 'kaynak_adi'(string), 'yeni_idler'(array)
 */
function rss_fetch_source(array $source, $dryRun = false) {
    $out = [
        'ok' => false, 'yeni' => 0, 'toplam' => 0, 'error' => '', 'ornekler' => [],
        'gorsel_var' => false, 'kaynak_adi' => '', 'yeni_idler' => [],
    ];
    $sourceId = (int)arr($source, 'id', 0);
    $url = trim((string)arr($source, 'url', ''));

    if ($url === '') {
        $out['error'] = 'Besleme adresi boş.';
        if (!$dryRun && $sourceId > 0) { rss_source_mark_error($sourceId, $out['error']); }
        return $out;
    }

    $http = rss_http_get($url, RSS_TOTAL_TIMEOUT);
    if (!$http['ok']) {
        $out['error'] = $http['error'] !== '' ? $http['error'] : 'Adres yanıt vermedi';
        if (!$dryRun && $sourceId > 0) { rss_source_mark_error($sourceId, $out['error']); }
        return $out;
    }

    $body = rss_normalize_charset($http['body'], $http['content_type']);
    $parsed = rss_parse($body);
    if (!$parsed['ok']) {
        $out['error'] = $parsed['error'] !== '' ? $parsed['error'] : 'Beslemede öge bulunamadı.';
        if (!$dryRun && $sourceId > 0) { rss_source_mark_error($sourceId, $out['error']); }
        return $out;
    }

    $items = $parsed['items'];
    $out['toplam'] = count($items);
    $out['kaynak_adi'] = $parsed['kaynak_adi'];

    foreach ($items as $i => $it) {
        if ($i < 5) { $out['ornekler'][] = $it['title'] !== '' ? $it['title'] : $it['link']; }
        if ($it['image'] !== '') { $out['gorsel_var'] = true; }
    }

    if ($dryRun) { $out['ok'] = true; return $out; }

    // ---- havuz yazımı (mükerrer engeli: rss_items.guid_hash UNIQUE)
    $now = now();
    foreach ($items as $it) {
        $hash = rss_guid_hash($it, $sourceId);
        $exists = qv('SELECT id FROM rss_items WHERE guid_hash = :h', [':h' => $hash], 0);
        if ($exists) { continue; }
        try {
            $id = db_insert('rss_items', [
                'source_id'    => $sourceId,
                'guid_hash'    => $hash,
                'title'        => mb_substr($it['title'], 0, 255),
                'link'         => $it['link'],
                'image'        => $it['image'],
                'summary'      => $it['summary'],
                'content'      => $it['content'],
                'published_at' => $it['published_at'] !== '' ? $it['published_at'] : $now,
                'fetched_at'   => $now,
                'status'       => 'new',
            ]);
            $out['yeni_idler'][] = (int)$id;
        } catch (PDOException $e) {
            // Benzersizlik ihlali = yarış durumunda aynı öge; sessizce atlanır
            if (stripos($e->getMessage(), 'unique') === false && stripos($e->getMessage(), 'duplicate') === false) {
                log_error('RSS öge yazımı: ' . $e->getMessage(), 'kaynak=' . $sourceId);
            }
        }
    }
    $out['yeni'] = count($out['yeni_idler']);
    $out['ok'] = true;

    // Başarılı çekim: hata sayacı sıfırlanır, kaynak normal aralığına döner
    if ($sourceId > 0) {
        rss_source_mark_ok($sourceId, $source, $now);
    }
    return $out;
}

/**
 * Başarılı çekim: hata sayacı sıfırlanır ve bir sonraki kontrol saati yazılır.
 */
function rss_source_mark_ok($sourceId, $source = [], $now = '') {
    $sourceId = (int)$sourceId;
    if ($sourceId <= 0) { return; }
    if ($now === '') { $now = now(); }
    $data = ['error_count' => 0, 'fetch_error' => '', 'last_fetch_at' => $now];
    if (rss_has_backoff_columns()) {
        $data['next_check_at'] = date('Y-m-d H:i:s', time() + rss_source_interval($source) * 60);
    }
    db_update('rss_sources', $data, 'id = :id', [':id' => $sourceId]);
}

/**
 * Hatalı çekimi işaretler ve kaynağı geri çekilme merdivenine bindirir.
 *
 * `active` DEĞİŞTİRİLMEZ: kaynağı yalnız yayıncı elle pasifleştirir. Kaynak
 * 5 hatadan sonra da 24 saatte bir denenmeye devam eder; kesinti geçtiğinde
 * kendiliğinden toparlanır.
 *
 * GERİYE DÖNÜK UYUM: göç 013 uygulanmadıysa (next_check_at yok) beklemenin
 * saklanacağı yer olmadığından 1.0 davranışına düşülür — RSS_MAX_ERRORS
 * hatadan sonra kaynak pasifleştirilir.
 */
function rss_source_mark_error($sourceId, $error) {
    $sourceId = (int)$sourceId;
    if ($sourceId <= 0) { return; }
    $count = (int)qv('SELECT error_count FROM rss_sources WHERE id = :i', [':i' => $sourceId], 0) + 1;
    $data = [
        'error_count'   => $count,
        'fetch_error'   => sanitize_line($error, 500),
        'last_fetch_at' => now(),
    ];

    if (rss_has_backoff_columns()) {
        $dk = rss_backoff_minutes($count);
        $data['next_check_at'] = date('Y-m-d H:i:s', time() + $dk * 60);
        if ($count === RSS_MAX_ERRORS) {
            log_error('RSS kaynağı beklemeye alındı (' . $count . ' hata, ' . $dk . ' dk sonra yeniden denenecek)',
                'kaynak=' . $sourceId . ' hata=' . $error);
        }
    } elseif ($count >= RSS_MAX_ERRORS) {
        $data['active'] = 0;
        log_error('RSS kaynağı pasifleştirildi (' . $count . ' hata)', 'kaynak=' . $sourceId . ' hata=' . $error);
    }

    db_update('rss_sources', $data, 'id = :id', [':id' => $sourceId]);
}

/** 1.0 adı — geriye dönük uyum için korunuyor (artık pasifleştirmez, geri çekilir). */
function rss_source_disable_if_failing($sourceId, $error) {
    rss_source_mark_error($sourceId, $error);
}

// ================================================================ havuz

/** Havuz filtresini WHERE parçasına çevirir. */
function rss_pool_where(array $filters) {
    $where = [];
    $params = [];
    $sourceId = (int)arr($filters, 'source_id', 0);
    if ($sourceId > 0) { $where[] = 'i.source_id = :sid'; $params[':sid'] = $sourceId; }

    $status = (string)arr($filters, 'status', '');
    if ($status !== '' && isset(rss_statuses()[$status])) { $where[] = 'i.status = :st'; $params[':st'] = $status; }

    $qs = trim((string)arr($filters, 'q', ''));
    if ($qs !== '') {
        $where[] = '(i.title LIKE :q OR i.summary LIKE :q)';
        $params[':q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $qs) . '%';
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $params];
}

/** Havuz listesi (kaynak adıyla birlikte). */
function rss_pool(array $filters = [], $limit = 30, $offset = 0) {
    list($where, $params) = rss_pool_where($filters);
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    $sql = 'SELECT i.*, s.name AS source_name, s.url AS source_url
            FROM rss_items i LEFT JOIN rss_sources s ON s.id = i.source_id'
         . $where
         . ' ORDER BY COALESCE(i.published_at, i.fetched_at) DESC, i.id DESC'
         . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    return qa($sql, $params);
}

/** Havuz satır sayısı (aynı filtrelerle). */
function rss_pool_count(array $filters = []) {
    list($where, $params) = rss_pool_where($filters);
    return (int)qv('SELECT COUNT(*) FROM rss_items i' . $where, $params, 0);
}

/** Geri çekilme nedeniyle bekleyen (kontrol saati gelmemiş) kaynak sayısı. */
function rss_waiting_count() {
    if (!rss_has_backoff_columns()) { return 0; }
    try {
        return (int)qv('SELECT COUNT(*) FROM rss_sources WHERE active = 1 AND next_check_at > :n',
            [':n' => now()], 0);
    } catch (Throwable $e) { return 0; }
}

/** Panel sayaçları. */
function rss_stats() {
    return [
        'beklemede'      => rss_waiting_count(),
        'aktif_kaynak'   => (int)qv('SELECT COUNT(*) FROM rss_sources WHERE active = 1', [], 0),
        'toplam_kaynak'  => (int)qv('SELECT COUNT(*) FROM rss_sources', [], 0),
        'bekleyen'       => (int)qv('SELECT COUNT(*) FROM rss_items WHERE status = \'new\'', [], 0),
        'kuyrukta'       => (int)qv('SELECT COUNT(*) FROM rss_items WHERE status = \'queued\'', [], 0),
        'bugun'          => (int)qv('SELECT COUNT(*) FROM rss_items WHERE fetched_at >= :d', [':d' => date('Y-m-d') . ' 00:00:00'], 0),
        'hatali_kaynak'  => (int)qv('SELECT COUNT(*) FROM rss_sources WHERE error_count > 0', [], 0),
    ];
}

// ================================================================ taslak üretimi

/** Cron'da oturum yoktur: yazar olarak ilk etkin yönetici kullanılır. */
function rss_default_author_id() {
    $u = current_user();
    if ($u) { return (int)$u['id']; }
    $id = (int)qv('SELECT id FROM users WHERE role = \'admin\' AND active = 1 ORDER BY id ASC LIMIT 1', [], 0);
    if ($id > 0) { return $id; }
    return (int)qv('SELECT id FROM users ORDER BY id ASC LIMIT 1', [], 0);
}

/**
 * Havuz ögesinden "olduğu gibi" haber taslağı üretir.
 * Gövde sonuna zorunlu kaynak bloğu eklenir (CONTRACTS §6).
 *
 * @return array ['ok'=>bool,'post_id'=>int,'error'=>string]
 */
function rss_item_to_draft($itemId) {
    $out = ['ok' => false, 'post_id' => 0, 'error' => ''];
    $itemId = (int)$itemId;
    $item = q1('SELECT * FROM rss_items WHERE id = :i', [':i' => $itemId]);
    if (!$item) { $out['error'] = 'Havuz ögesi bulunamadı.'; return $out; }

    $src = (int)$item['source_id'] > 0
        ? q1('SELECT * FROM rss_sources WHERE id = :i', [':i' => (int)$item['source_id']])
        : null;

    $title = sanitize_line((string)$item['title'], 200);
    if ($title === '') { $title = 'Başlıksız haber'; }

    $sourceName = $src ? sanitize_line((string)$src['name'], 190) : '';
    if ($sourceName === '') { $sourceName = 'Kaynak'; }

    $link = (string)$item['link'];

    // Gövde: temizlenmiş içerik (iframe yok)
    $body = sanitize_html((string)$item['content'], false);
    if (trim(strip_tags($body)) === '') {
        $sum = (string)$item['summary'];
        $body = $sum !== '' ? '<p>' . esc($sum) . '</p>' : '';
    }

    // ZORUNLU kaynak bloğu — sanitize_html'den SONRA eklenir ki
    // class="kaynak" ve rel="nofollow noopener" sözleşmedeki gibi kalsın.
    if ($link !== '' && sanitize_is_safe_url($link, false)) {
        $body .= "\n" . '<p class="kaynak">Kaynak: <a href="' . esc($link)
               . '" rel="nofollow noopener" target="_blank">' . esc($sourceName) . '</a></p>';
    } else {
        $body .= "\n" . '<p class="kaynak">Kaynak: ' . esc($sourceName) . '</p>';
    }

    $spotSource = trim((string)$item['summary']) !== '' ? (string)$item['summary'] : strip_tags($body);
    $spot = excerpt($spotSource, 300);

    /*
     * NEDEN (denetim turu 3, B13): `posts.edit_wire` YETKİ kararı eskiden
     * `source_url` alanına bakıyordu; o alan panelde elle doldurulabilen bir
     * KULLANICI GİRDİSİ. Bir yazar kendi haberine adres yazarak onu "ajans
     * haberi" ilan edebiliyordu. Yetki kararı yalnız SİSTEMİN yazdığı alana
     * dayanmalı: `is_wire` bayrağını YALNIZ içe aktarıcılar yazar.
     *
     * GERİYE DÖNÜK UYUM: göç 014 uygulanmamış kurulumda sütun yoktur; alan
     * db_insert'e hiç eklenmez, çekim çökmek yerine eski davranışı sürdürür.
     */
    $alanlar = [
        'title'        => $title,
        'slug'         => unique_slug('posts', $title),
        'spot'         => $spot,
        'body'         => $body,
        'image'        => (string)$item['image'],   // mutlak URL; post_image() http ile başlayanı olduğu gibi döndürür
        'category_id'  => $src ? (int)$src['category_id'] : 0,
        'author_id'    => rss_default_author_id(),
        'status'       => 'draft',
        'type'         => 'haber',
        'source_name'  => $sourceName,
        'source_url'   => $link,
        'ai_generated' => 0,
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
    if (function_exists('schema_has_column') && schema_has_column('posts', 'is_wire')) {
        $alanlar['is_wire'] = 1;
    }

    try {
        $postId = db_insert('posts', $alanlar);
    } catch (Throwable $e) {
        log_error('RSS taslak üretimi: ' . $e->getMessage(), 'oge=' . $itemId);
        $out['error'] = 'Taslak oluşturulamadı.';
        return $out;
    }

    db_update('rss_items', ['status' => 'processed'], 'id = :id', [':id' => $itemId]);

    $out['ok'] = true;
    $out['post_id'] = (int)$postId;
    return $out;
}

// ================================================================ cron

/**
 * 1.0'da otomatik pasifleştirilmiş kaynakları BİR KEZ beklemeye alır.
 *
 * Yükseltmeden önce `active = 0` yapılmış kaynaklar yeni geri çekilme
 * düzeninde bir daha hiç denenmezdi. Bu kurtarma yalnız otomatik kapatma
 * imzası taşıyanlara (error_count >= RSS_MAX_ERRORS) dokunur ve tek sefer
 * çalışır (settings.rss_backoff_migrated), yayıncının elle kapattığı
 * kaynakları diriltmez.
 */
function rss_revive_auto_disabled() {
    if (!rss_has_backoff_columns()) { return 0; }
    if (setting('rss_backoff_migrated', '0') === '1') { return 0; }
    $n = 0;
    try {
        $n = q('UPDATE rss_sources SET active = 1, next_check_at = :t
                WHERE active = 0 AND error_count >= :e',
            [':t' => date('Y-m-d H:i:s', time() + 60), ':e' => RSS_MAX_ERRORS])->rowCount();
        setting_set('rss_backoff_migrated', '1');
        if ($n > 0) { log_error('RSS: ' . $n . ' otomatik pasifleştirilmiş kaynak beklemeye alındı'); }
    } catch (Throwable $e) {
        log_error('rss_revive_auto_disabled: ' . $e->getMessage());
    }
    return $n;
}

/**
 * cron.php her turda çağırır. Tur başına en çok RSS_SOURCES_PER_TICK kaynak,
 * `last_fetch_at` en eski olandan başlayarak işlenir.
 *
 * 1.1: kontrol saati gelmemiş kaynaklar (`next_check_at > now`) atlanır —
 * hem kaynak başına aralık (`interval_min`) hem de hata geri çekilmesi bu
 * sütun üzerinden yürür. Cron görev bütçesi dolarsa döngü nazikçe durur ve
 * kalan kaynaklar bir sonraki tura kalır.
 *
 * @return string "3 kaynak, 12 yeni öge, 1 hata"
 */
function rss_cron_tick() {
    rss_revive_auto_disabled();

    $now = now();
    if (rss_has_backoff_columns()) {
        $sources = qa('SELECT * FROM rss_sources
                       WHERE active = 1 AND (next_check_at IS NULL OR next_check_at = \'\' OR next_check_at <= :n)
                       ORDER BY COALESCE(next_check_at, \'\') ASC, COALESCE(last_fetch_at, \'\') ASC, id ASC
                       LIMIT ' . RSS_SOURCES_PER_TICK, [':n' => $now]);
        $bekleyen = (int)qv('SELECT COUNT(*) FROM rss_sources
                             WHERE active = 1 AND next_check_at > :n', [':n' => $now], 0);
    } else {
        $sources = qa('SELECT * FROM rss_sources WHERE active = 1
                       ORDER BY COALESCE(last_fetch_at, \'\') ASC, id ASC
                       LIMIT ' . RSS_SOURCES_PER_TICK);
        $bekleyen = 0;
    }

    $srcCount = 0;
    $newTotal = 0;
    $errCount = 0;
    $published = 0;
    $kalan = 0;

    foreach ($sources as $s) {
        // Görev bütçesi: bir kaynak çekimi en kötü ihtimalle RSS_TOTAL_TIMEOUT
        // sürer; o kadar süre kalmadıysa hiç başlamayız.
        if (function_exists('cron_over_budget') && cron_over_budget(RSS_TOTAL_TIMEOUT)) {
            $kalan = count($sources) - $srcCount;
            break;
        }
        $srcCount++;
        try {
            $r = rss_fetch_source($s, false);
        } catch (Throwable $e) {
            $errCount++;
            log_error('RSS çekim istisnası: ' . $e->getMessage(), 'kaynak=' . (int)$s['id']);
            rss_source_mark_error((int)$s['id'], 'Beklenmeyen hata: ' . $e->getMessage());
            continue;
        }
        if (!$r['ok']) { $errCount++; continue; }
        $newTotal += (int)$r['yeni'];

        if ((int)$s['auto_publish'] !== 1) { continue; }  // havuzda 'new' olarak bekler

        foreach ($r['yeni_idler'] as $itemId) {
            if ((int)$s['ai_rewrite'] === 1 && function_exists('ai_job_enqueue')) {
                // Yapay zekâ modülü kuruluysa yeniden yazım kuyruğuna alınır
                try {
                    ai_job_enqueue($itemId, 'rss_rewrite');
                    db_update('rss_items', ['status' => 'queued'], 'id = :id', [':id' => (int)$itemId]);
                } catch (Throwable $e) {
                    log_error('RSS → AI kuyruğu: ' . $e->getMessage(), 'oge=' . $itemId);
                }
                continue;
            }
            // YZ yoksa/kapalıysa: olduğu gibi taslak yapılıp doğrudan yayımlanır
            $d = rss_item_to_draft($itemId);
            if (!$d['ok']) { continue; }
            db_update('posts', ['status' => 'published', 'published_at' => now(), 'updated_at' => now()],
                'id = :id', [':id' => (int)$d['post_id']]);
            $published++;
        }
    }

    if ($published > 0 && function_exists('cache_flush')) { cache_flush(); }

    return $srcCount . ' kaynak, ' . $newTotal . ' yeni öge, ' . $errCount . ' hata'
         . ($published > 0 ? ', ' . $published . ' yayımlandı' : '')
         . ($kalan > 0 ? ', süre doldu — ' . $kalan . ' kaynak sonraki tura kaldı' : '')
         . ($bekleyen > 0 ? ', ' . $bekleyen . ' kaynak beklemede' : '');
}
