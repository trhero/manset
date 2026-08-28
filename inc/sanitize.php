<?php
/**
 * Manşet — HTML temizleyici (beyaz liste).
 * Zengin metin editöründen gelen gövde HTML'i sunucuda BURADA temizlenir.
 * Kural: beyaz listede olmayan etiket/öznitelik atılır; şema doğrulaması yapılır.
 */

require_once __DIR__ . '/bootstrap.php';

/** İzin verilen etiket => izin verilen öznitelikler. */
function sanitize_allowed_tags() {
    return [
        'p'          => ['class'],
        'br'         => [],
        'hr'         => [],
        'strong'     => [],
        'b'          => [],
        'em'         => [],
        'i'          => [],
        'u'          => [],
        's'          => [],
        'sub'        => [],
        'sup'        => [],
        'h2'         => [],
        'h3'         => [],
        'h4'         => [],
        'ul'         => [],
        'ol'         => [],
        'li'         => [],
        'dl'         => ['class'],
        'dt'         => [],
        'dd'         => [],
        'small'      => [],
        'blockquote' => ['cite'],
        'a'          => ['href', 'title', 'target', 'rel'],
        'img'        => ['src', 'alt', 'title', 'width', 'height', 'loading', 'srcset', 'sizes'],
        'figure'     => ['class'],
        'figcaption' => [],
        'table'      => [],
        // GENİŞLETME (1.3-03, Ajan-G): `caption` ve `th@scope`.
        // NEDEN GÜVENLİ: `caption` ÖZNİTELİK ALMAZ (boş liste) ve yalnız metin
        // taşır — yeni bir URL, olay ya da stil yüzeyi açmaz. `scope` ise
        // kapalı bir sözcük kümesidir (row/col/rowgroup/colgroup) ve aşağıda
        // TAM EŞLEŞME ile doğrulanır; eşleşmeyen değer özniteliği düşürür.
        // Erişilebilir veri tablosu (WCAG 1.3.1) başlık hücresini `scope` ile
        // ilan etmek zorundadır; olmadan ekran okuyucu sütunu okuyamaz.
        'caption'    => [],
        'thead'      => [],
        'tbody'      => [],
        'tfoot'      => [],
        'tr'         => [],
        'th'         => ['colspan', 'rowspan', 'scope'],
        'td'         => ['colspan', 'rowspan'],
        'iframe'     => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title', 'frameborder'],
        'span'       => ['class'],
        'div'        => ['class'],
        'code'       => [],
        'pre'        => [],
    ];
}

/**
 * figure/span/div/p için izin verilen sınıf adları.
 * 'kaynak' ve 'duzeltme' sınıfları mevzuat gereği zorunlu bloklara aittir
 * (kaynak gösterimi ve düzeltme-cevap metni) — beyaz listeden çıkarılmamalıdır.
 */
function sanitize_allowed_classes() {
    return [
        'manset-embed', 'manset-figure', 'manset-quote', 'manset-lead',
        'kaynak', 'duzeltme', 'guncelleme', 'ai-notu',
        'text-center', 'text-right',
    ];
}

/** Bağlantılarda korunmasına izin verilen rel değerleri. */
function sanitize_allowed_rel() {
    return ['nofollow', 'noopener', 'noreferrer', 'ugc', 'sponsored'];
}

/** iframe yalnız bu alan adlarından gömülebilir. */
function sanitize_allowed_iframe_hosts() {
    return [
        'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
        'player.vimeo.com', 'www.dailymotion.com', 'geo.dailymotion.com',
        'open.spotify.com', 'w.soundcloud.com',
    ];
}

/** Bağlantı şemasının güvenli olup olmadığını denetler. */
function sanitize_is_safe_url($url, $allowRelative = true) {
    $url = trim((string)$url);
    if ($url === '') { return false; }
    // Görünmez karakter / kontrol karakteri hilelerini temizle
    $probe = preg_replace('/[\x00-\x20\x7F]|&#x?0*(9|10|13|A|D);?/i', '', $url);
    $probe = str_replace(["\xE2\x80\x8B", "\xEF\xBB\xBF"], '', $probe);
    if (preg_match('/^\s*(javascript|vbscript|data|file|about|blob)\s*:/i', $probe)) { return false; }
    if (preg_match('#^(https?:)?//#i', $probe)) { return true; }
    if (preg_match('/^(mailto|tel):/i', $probe)) { return true; }
    if ($allowRelative && (strpos($probe, ':') === false || strpos($probe, '/') === 0 || strpos($probe, '#') === 0)) { return true; }
    return false;
}

/**
 * Gövde HTML'ini beyaz listeye göre temizler.
 * @param string $html Ham HTML
 * @param bool   $allowIframe Video gömme izni (yalnız beyaz listeli sunuculardan)
 */
function sanitize_html($html, $allowIframe = true) {
    $html = (string)$html;
    if (trim($html) === '') { return ''; }

    // Tehlikeli blokları baştan kaldır (DOM ayrıştırıcı öncesi ucuz temizlik)
    $html = preg_replace('#<\s*(script|style|noscript|template|object|embed|applet|form|input|button|select|textarea|link|meta|base|svg|math)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html);
    $html = preg_replace('#<\s*(script|style|noscript|template|object|embed|applet|form|input|button|select|textarea|link|meta|base|svg|math)\b[^>]*/?>#is', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    if (!class_exists('DOMDocument')) {
        // DOM YOKSA HİÇ ETİKET GEÇMEZ (denetim turu 5, B1).
        //
        // Buradaki eski yedek `strip_tags()` + olay özniteliklerini silen bir
        // düzenli ifadeydi. Denetçi onu birebir çalıştırıp DÖRT atlatma kanıtladı:
        //   <a href="javascript:alert(1)">          geçiyordu
        //   <iframe src="https://evil.test/">       geçiyordu
        //   srcset="javascript: 1x"                 geçiyordu
        //   <a href="/x"onmouseover=alert(1)>       BOŞLUKSUZ öznitelik, geçiyordu
        // Sonuncusu doğrudan saklanmış XSS'tir.
        //
        // Düzenli ifadeyle HTML temizlemek güvenli hâle GETİRİLEMEZ; kapatılan her
        // desen için yenisi bulunur. Bu yüzden yol iyileştirilmedi, KAPATILDI:
        // etiketlerin tümü atılır ve düz metin döner. Biçimlendirme kaybı görünür
        // ve şikâyet edilir; sessiz bir XSS kapısı görünmez.
        //
        // `ext/dom` artık kurulum sihirbazında ZORUNLU aranıyor — bu dal yalnız
        // eklentisi sonradan kaldırılan bir kurulumda çalışır.
        log_error('sanitize_html: ext/dom yok - HTML duz metne indirgendi. '
                . 'Bicimlendirme kaybi yasamamak icin dom eklentisini acin.');
        return esc(trim(strip_tags($html)));
    }

    $allowedTags = sanitize_allowed_tags();
    if (!$allowIframe) { unset($allowedTags['iframe']); }
    $allowedClasses = sanitize_allowed_classes();
    $iframeHosts = sanitize_allowed_iframe_hosts();

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $wrapped = '<?xml encoding="UTF-8"><div id="manset-root">' . $html . '</div>';
    $flags = 0;
    if (defined('LIBXML_HTML_NOIMPLIED')) { $flags |= LIBXML_HTML_NOIMPLIED; }
    if (defined('LIBXML_HTML_NODEFDTD')) { $flags |= LIBXML_HTML_NODEFDTD; }
    $ok = $doc->loadHTML($wrapped, $flags);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) { return esc(strip_tags($html)); }

    $root = $doc->getElementById('manset-root');
    if (!$root) {
        $divs = $doc->getElementsByTagName('div');
        $root = $divs->length ? $divs->item(0) : $doc->documentElement;
    }
    if (!$root) { return ''; }

    sanitize_walk($root, $allowedTags, $allowedClasses, $iframeHosts);

    $out = '';
    foreach ($root->childNodes as $child) { $out .= $doc->saveHTML($child); }
    // Boş paragrafları sadeleştir
    $out = preg_replace('#<p>\s*(&nbsp;|\xC2\xA0)?\s*</p>#u', '', $out);
    return trim($out);
}

/** DOM ağacını dolaşıp izin verilmeyen düğüm ve öznitelikleri kaldırır. */
function sanitize_walk(DOMNode $node, array $allowedTags, array $allowedClasses, array $iframeHosts) {
    // Sondan başa yürü: kaldırma sırasında dizin kaymasın
    for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
        $child = $node->childNodes->item($i);
        if (!$child) { continue; }

        if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) { continue; }
        if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
            $node->removeChild($child);
            continue;
        }
        if ($child->nodeType !== XML_ELEMENT_NODE) { $node->removeChild($child); continue; }

        $tag = strtolower($child->nodeName);

        if (!isset($allowedTags[$tag])) {
            // Etiketi at, içeriğini koru (metin kaybolmasın)
            sanitize_walk($child, $allowedTags, $allowedClasses, $iframeHosts);
            while ($child->firstChild) { $node->insertBefore($child->firstChild, $child); }
            $node->removeChild($child);
            continue;
        }

        // Öznitelik süzgeci
        $allowedAttrs = $allowedTags[$tag];
        for ($j = $child->attributes->length - 1; $j >= 0; $j--) {
            $attr = $child->attributes->item($j);
            if (!$attr) { continue; }
            $an = strtolower($attr->nodeName);
            $av = (string)$attr->nodeValue;

            if (!in_array($an, $allowedAttrs, true)) { $child->removeAttribute($attr->nodeName); continue; }
            if (strpos($an, 'on') === 0) { $child->removeAttribute($attr->nodeName); continue; }

            if ($an === 'href' || $an === 'src') {
                // Göreli adres hem href hem src için geçerlidir (/uploads/foo.jpg gibi).
                // Şema kara listesi (javascript:, data: …) her iki durumda da uygulanır;
                // iframe için ayrıca aşağıda mutlak adres + host beyaz listesi şartı var.
                if (!sanitize_is_safe_url($av, true)) { $child->removeAttribute($attr->nodeName); continue; }
                if ($tag === 'iframe' || ($tag === 'img' && $an === 'src')) {
                    if (preg_match('#^(https?:)?//#i', $av)) {
                        $host = strtolower((string)parse_url((strpos($av, '//') === 0 ? 'https:' : '') . $av, PHP_URL_HOST));
                        if ($tag === 'iframe' && !in_array($host, $iframeHosts, true)) {
                            $child->parentNode->removeChild($child);
                            continue 2;
                        }
                    } elseif ($tag === 'iframe') {
                        $child->parentNode->removeChild($child);
                        continue 2;
                    }
                }
            }
            // SECURITY_AUDIT B-04: srcset içindeki her URL de şema denetiminden geçmeli
            if ($an === 'srcset') {
                // data:/javascript: URI'leri virgül içerebildiği için önce HAM değeri
                // denetle; tehlikeli şema varsa özniteliğin tamamını at (parçalama hilesi).
                if (preg_match('/(javascript|vbscript|data|file|about|blob)\s*:/i',
                        // GÜVENLİK (denetim tur 2, B05): bu karakter sınıfında eskiden
                        // HAM NUL ve DEL baytları vardı; PCRE deseni 'range out of order'
                        // ile reddedip null döndürüyor, kapı tamamen ölü kalıyordu.
                        // Kaçışlı biçim: tüm denetim karakterleri ve boşluk atılır.
                        preg_replace('/[\x00-\x20\x7F]/', '', $av))) {
                    $child->removeAttribute('srcset');
                    continue;
                }
                $parcalar = [];
                foreach (explode(',', $av) as $aday) {
                    $aday = trim($aday);
                    if ($aday === '') { continue; }
                    $bolum = preg_split('/\s+/', $aday, 2);
                    if (!sanitize_is_safe_url($bolum[0], true)) { continue; }
                    $tanim = isset($bolum[1]) && preg_match('/^\d+(\.\d+)?[wx]$/', trim($bolum[1]))
                        ? ' ' . trim($bolum[1]) : '';
                    $parcalar[] = $bolum[0] . $tanim;
                }
                if ($parcalar) { $child->setAttribute('srcset', implode(', ', $parcalar)); }
                else { $child->removeAttribute('srcset'); }
                continue;
            }
            if ($an === 'sizes') {
                // Yalnız güvenli medya sorgusu karakterleri
                $temiz = preg_replace('/[^0-9a-zA-Z\s,\(\)\.\-:%vwhminaxpxem]/', '', $av);
                if (trim($temiz) !== '') { $child->setAttribute('sizes', trim($temiz)); }
                else { $child->removeAttribute('sizes'); }
                continue;
            }
            if ($an === 'class') {
                $keep = [];
                foreach (preg_split('/\s+/', $av) as $cls) {
                    if ($cls !== '' && in_array($cls, $allowedClasses, true)) { $keep[] = $cls; }
                }
                if ($keep) { $child->setAttribute('class', implode(' ', $keep)); }
                else { $child->removeAttribute('class'); }
                continue;
            }
            if (in_array($an, ['width', 'height', 'colspan', 'rowspan'], true)) {
                $n = (int)preg_replace('/\D+/', '', $av);
                if ($n <= 0) { $child->removeAttribute($attr->nodeName); }
                else { $child->setAttribute($an, (string)min($n, 4000)); }
                continue;
            }
            if ($an === 'scope') {
                // KAPALI SÖZCÜK KÜMESİ — dizeden hiçbir parça korunmaz,
                // yalnız tam eşleşen bilinen değerlerden biri yeniden yazılır.
                $sv = strtolower(trim($av));
                if (in_array($sv, ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                    $child->setAttribute('scope', $sv);
                } else {
                    $child->removeAttribute($attr->nodeName);
                }
                continue;
            }
            if ($an === 'target') {
                $child->setAttribute('target', $av === '_blank' ? '_blank' : '_self');
                continue;
            }
            if ($an === 'loading') {
                $child->setAttribute('loading', $av === 'eager' ? 'eager' : 'lazy');
                continue;
            }
        }

        // Dış bağlantılara güvenli rel — yazarın koyduğu nofollow/sponsored korunur,
        // güvenlik için gereken noopener/noreferrer üstüne eklenir.
        if ($tag === 'a') {
            $href = (string)$child->getAttribute('href');
            if ($href === '') { $child->removeAttribute('target'); }
            $rel = [];
            foreach (preg_split('/\s+/', mb_strtolower((string)$child->getAttribute('rel'), 'UTF-8')) as $token) {
                if ($token !== '' && in_array($token, sanitize_allowed_rel(), true) && !in_array($token, $rel, true)) {
                    $rel[] = $token;
                }
            }
            $isExternal = (bool)preg_match('#^https?://#i', $href);
            if ($child->getAttribute('target') === '_blank') {
                foreach (['noopener', 'noreferrer'] as $t) { if (!in_array($t, $rel, true)) { $rel[] = $t; } }
            } elseif ($isExternal && !in_array('noopener', $rel, true)) {
                $rel[] = 'noopener';
            }
            if ($rel) { $child->setAttribute('rel', implode(' ', $rel)); }
            else { $child->removeAttribute('rel'); }
        }
        if ($tag === 'iframe') {
            $child->setAttribute('loading', 'lazy');
            $child->setAttribute('allowfullscreen', 'allowfullscreen');
            $child->removeAttribute('frameborder');
        }
        if ($tag === 'img') {
            $child->setAttribute('loading', 'lazy');
        }

        sanitize_walk($child, $allowedTags, $allowedClasses, $iframeHosts);
    }
}

/**
 * Geçersiz UTF-8 dizilerini atar.
 *
 * NEDEN: preg_replace('/…/u', …) geçersiz UTF-8 girdide sessizce NULL döndürür;
 * ardından gelen trim()/mb_substr() çağrıları "passing null" uyarısı üretir ve
 * metin tamamen kaybolur. Dış kaynaklı veri (RSS, form, curl) her zaman geçerli
 * UTF-8 değildir. (SECURITY_AUDIT tur 1 sonrası, Faz 7b raporu.)
 */
function sanitize_utf8($text) {
    $text = (string)$text;
    if ($text === '') { return ''; }
    if (function_exists('mb_check_encoding') && mb_check_encoding($text, 'UTF-8')) { return $text; }
    if (function_exists('mb_convert_encoding')) {
        $prev = ini_get('mbstring.substitute_character');
        @ini_set('mbstring.substitute_character', 'none');
        $out = (string)@mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($prev !== false) { @ini_set('mbstring.substitute_character', $prev); }
        return $out;
    }
    if (function_exists('iconv')) { return (string)@iconv('UTF-8', 'UTF-8//IGNORE', $text); }
    // Son çare: yalnız ASCII'yi koru
    return (string)preg_replace('/[^	
 -~]/', '', $text);
}

/** Yorum gövdesi: HTML yok, satır sonları korunur. */
function sanitize_plain($text, $maxLen = 4000) {
    $text = strip_tags(sanitize_utf8($text));
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);
    $text = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = trim($text);
    if (mb_strlen($text) > $maxLen) { $text = mb_substr($text, 0, $maxLen); }
    return $text;
}

/** Tek satırlık düz metin (başlık, ad, alt metin…). */
function sanitize_line($text, $maxLen = 255) {
    $text = strip_tags(sanitize_utf8($text));
    $text = (string)preg_replace('/\s+/u', ' ', $text);
    $text = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    $text = trim($text);
    if (mb_strlen($text) > $maxLen) { $text = mb_substr($text, 0, $maxLen); }
    return $text;
}

/**
 * Dış istek (RSS / harici JSON) için URL doğrulaması — SSRF kalkanı.
 * Yalnız http/https, yalnız genel IP'ler. Geçerliyse normalize URL döner, değilse false.
 *
 * NOT: Bu fonksiyon yalnız DOĞRULAR. İstemi asıl yapan kod, DNS yeniden bağlama
 * (rebinding) saldırısını kapatmak için safe_remote_target() ile çözülen IP'yi
 * sabitlemelidir — aksi hâlde curl kendi DNS çözümünü yapar ve doğrulama ile
 * bağlantı arasında ad başka bir IP'ye çözülebilir (TOCTOU).
 */
function safe_remote_url($url) {
    $t = safe_remote_target($url);
    return $t === false ? false : $t['url'];
}

/**
 * safe_remote_url()'in ayrıntılı sürümü — SSRF kalkanı + IP sabitleme bilgisi.
 *
 * @return array|false [
 *   'url'    => doğrulanmış URL,
 *   'host'   => ana ad (küçük harf),
 *   'ip'     => doğrulanan İLK güvenli IP — bağlantı bu IP'ye kurulmalıdır,
 *   'ips'    => doğrulanan tüm IP'ler,
 *   'port'   => etkin port (varsayılan şemaya göre),
 *   'scheme' => 'http' | 'https',
 *   'resolve'=> curl CURLOPT_RESOLVE için hazır satır: "host:port:ip",
 * ]
 */
function safe_remote_target($url) {
    $url = trim((string)$url);
    if ($url === '' || mb_strlen($url) > 2000) { return false; }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) { return false; }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') { return false; }
    if (!empty($parts['user']) || !empty($parts['pass'])) { return false; }
    $host = strtolower($parts['host']);
    if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local' || substr($host, -10) === '.localhost') { return false; }
    if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443, 8080, 8443], true)) { return false; }

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]*[a-z0-9])?$/i', $host)) { return false; }
        $records = @gethostbynamel($host);
        if (is_array($records)) { $ips = $records; }
        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) { foreach ($v6 as $r) { if (!empty($r['ipv6'])) { $ips[] = $r['ipv6']; } } }
        if (!$ips) { return false; } // çözülemeyen ad reddedilir
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) { return false; }

    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
    $pin = $ips[0];
    // IPv6 CURLOPT_RESOLVE söz diziminde köşeli parantez ister
    $pinForCurl = (strpos($pin, ':') !== false && $pin[0] !== '[') ? '[' . $pin . ']' : $pin;

    return [
        'url'     => $url,
        'host'    => $host,
        'ip'      => $pin,
        'ips'     => $ips,
        'port'    => $port,
        'scheme'  => $scheme,
        'resolve' => $host . ':' . $port . ':' . $pinForCurl,
    ];
}

/**
 * Doğrulanan IP'yi curl tanıtıcısına sabitler (DNS rebinding kalkanı).
 * $target, safe_remote_target() çıktısıdır. Ana ad zaten bir IP ise bir şey yapmaz.
 */
function curl_pin_resolved_ip($ch, array $target) {
    if (!function_exists('curl_setopt')) { return; }
    if (filter_var($target['host'], FILTER_VALIDATE_IP)) { return; }   // zaten IP, çözüm yok
    @curl_setopt($ch, CURLOPT_RESOLVE, [$target['resolve']]);
}
