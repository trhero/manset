<?php
/**
 * Manşet — gömme (oEmbed) modülü. 1.3-03, Ajan-G.
 *
 * =============================================================================
 * TEMEL KARAR: GÖMME ÇIKTISI ASLA SAĞLAYICIDAN GELMEZ
 * =============================================================================
 * oEmbed sağlayıcıları `html` alanında hazır işaretleme döndürür. X (Twitter) ve
 * Instagram bu alanda `<script src="//platform.twitter.com/widgets.js">` gönderir.
 * O betiği gövdeye koymak, haber metnine ÜÇÜNCÜ TARAFIN keyfi JavaScript'ini
 * eklemek demektir: sayfadaki her şeyi (oturum çerezi yoksa da panel bağlantıları,
 * DOM, tıklama olayları) okuyabilir ve yarın içeriği değişebilir. Bu, temizleyicinin
 * üç denetim turunda kapattığı bütün kapıları tek hamlede açardı.
 *
 * Bu yüzden:
 *   1. Sağlayıcının `html` alanı KULLANILMAZ. Yalnız ÜSTVERİ okunur
 *      (`title`, `author_name`, `thumbnail_url`) ve o da düz metne indirilir.
 *   2. Gövdeye giren işaretlemeyi BİZ üretiriz (`embed_render()`), yalnız
 *      temizleyicinin ZATEN izin verdiği etiket/öznitelik/host kümesinden.
 *   3. Ürettiğimiz HTML, döndürülmeden önce `sanitize_html()`'den GEÇİRİLİR.
 *      Temizleyici onu kırparsa uç nokta hata döndürür — yani "temizleyiciyi
 *      gevşetmek" bu modülde teknik olarak da işe yaramaz.
 *
 * SONUÇ: `sanitize_allowed_iframe_hosts()` beyaz listesi BÜYÜTÜLMEDİ.
 * YouTube ve Vimeo zaten listede. X ve Instagram için iframe HİÇ üretilmez.
 *
 * -----------------------------------------------------------------------------
 * X VE INSTAGRAM: NEDEN "KART", NEDEN "ÖNCE İZİN KUTUSU" DEĞİL
 * -----------------------------------------------------------------------------
 * Tıklayınca yüklenen izin kutusu, ÖN YÜZDE çalışan JavaScript ister (kutuyu
 * iframe ile değiştirecek kod) ve o kod `themes/` altında yaşar. `themes/`
 * Ajan-G'nin dosyası değildir (1.3 sözleşmesi §6); oraya yazmadan izin kutusu
 * gerçekten çalışmaz — yalnız çalışıyormuş gibi görünürdü. Üstelik kutunun
 * taşıyacağı `data-*` öznitelikleri temizleyicinin beyaz listesinde yok, yani
 * kutuyu ayakta tutmak için `sanitize.php`'yi GEVŞETMEK gerekirdi.
 *
 * Kart ise:
 *   • hiç JavaScript istemez,          • hiçbir dış istek doğurmaz (izleme yok),
 *   • yeni öznitelik istemez,          • KVKK açısından en temiz seçenek,
 *   • içeriği oEmbed'den gelen gerçek üstveridir (yazar + metin + bağlantı).
 * Instagram oEmbed'i 2020'den beri Facebook uygulama belirteci ister; belirteçsiz
 * çağrı zaten çalışmaz, bu yüzden Instagram için DIŞ İSTEK HİÇ YAPILMAZ — kart
 * doğrudan adresten kurulur.
 *
 * -----------------------------------------------------------------------------
 * SSRF
 * -----------------------------------------------------------------------------
 * Yeni bir HTTP istemcisi YAZILMADI. Dış istek `rss_http_get()` üzerinden gider;
 * o da `safe_remote_target()` (inc/sanitize.php) kalkanını her atlamada yeniden
 * uygular, doğrulanan IP'yi `curl_pin_resolved_ip()` ile sabitler (DNS rebinding),
 * yönlendirmeyi elle izler, boyutu sınırlar. Ek olarak burada uç nokta adresi
 * SABİT bir listeden gelir: kullanıcının verdiği adres yalnız `url=` sorgu
 * parametresi olarak KODLANIR, uç noktanın kendisi olamaz.
 *
 * -----------------------------------------------------------------------------
 * ÖNBELLEK
 * -----------------------------------------------------------------------------
 * Sayfa görüntülemesinde dış istek YOKTUR — çünkü gömme, editörde çözülür ve
 * ÜRETİLEN STATİK HTML haber gövdesine yazılır. `embed_cache` tablosu ise aynı
 * adresin ikinci kez gömülmesinde (ya da editörün yeniden denemesinde) çağrıyı
 * tekrarlamamak içindir. Hatalı sonuç da kısa süreyle önbelleklenir.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/sanitize.php';

/** Başarılı gömme üstverisinin önbellek ömrü (saniye). */
if (!defined('EMBED_TTL_OK'))    { define('EMBED_TTL_OK', 30 * 86400); }
/** Başarısız denemenin önbellek ömrü (saniye) — istek selini kırar. */
if (!defined('EMBED_TTL_ERROR')) { define('EMBED_TTL_ERROR', 1800); }
/** oEmbed isteği zaman aşımı (saniye). */
if (!defined('EMBED_TIMEOUT'))   { define('EMBED_TIMEOUT', 10); }

// =============================================================== sağlayıcı tanımı

/**
 * Desteklenen sağlayıcılar.
 *
 * `endpoint` boşsa DIŞ İSTEK YAPILMAZ (Instagram) — kart yalnız adresten kurulur.
 * `iframe` true ise çıktı iframe'dir ve `host` temizleyicinin beyaz listesinde
 * OLMAK ZORUNDADIR; `embed_render()` bunu ayrıca doğrular.
 */
function embed_providers() {
    return [
        'youtube' => [
            'label'    => 'YouTube',
            'endpoint' => 'https://www.youtube.com/oembed',
            'params'   => ['format' => 'json'],
            'iframe'   => true,
            'host'     => 'www.youtube-nocookie.com',
        ],
        'vimeo' => [
            'label'    => 'Vimeo',
            'endpoint' => 'https://vimeo.com/api/oembed.json',
            'params'   => [],
            'iframe'   => true,
            'host'     => 'player.vimeo.com',
        ],
        'x' => [
            'label'    => 'X',
            // omit_script=1 + dnt=true: sağlayıcıya betik istemediğimizi ve
            // izleme istemediğimizi bildirir. Yanıtın `html` alanı ZATEN
            // kullanılmıyor; bu parametreler yalnız kibarlıktır.
            'endpoint' => 'https://publish.twitter.com/oembed',
            'params'   => ['omit_script' => '1', 'dnt' => 'true', 'lang' => 'tr'],
            'iframe'   => false,
            'host'     => '',
        ],
        'instagram' => [
            'label'    => 'Instagram',
            // BOŞ: oEmbed uç noktası uygulama belirteci ister. Dış istek yok.
            'endpoint' => '',
            'params'   => [],
            'iframe'   => false,
            'host'     => '',
        ],
    ];
}

/**
 * Adresi tanır.
 *
 * @return array|null ['provider'=>…, 'id'=>…, 'canonical'=>…, 'permalink'=>…]
 *
 * GÜVENLİK: adres önce `safe_remote_url()` KAPISINDAN GEÇMEZ — burada yalnız
 * BİÇİM tanınır. Ağ kapısı `embed_fetch_meta()` içindedir ve orada uç nokta
 * adresi sabittir. Kimlik (`id`) daima dar bir karakter kümesine kısıtlanır;
 * çıktı adresi ondan KURULUR, kullanıcı dizesinden kopyalanmaz.
 */
function embed_detect($url) {
    $url = trim((string)$url);
    if ($url === '' || mb_strlen($url) > 1000) { return null; }
    if (!preg_match('#^https?://#i', $url)) { return null; }

    $p = @parse_url($url);
    if (!$p || empty($p['host'])) { return null; }
    $host = strtolower($p['host']);
    if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }
    $path = isset($p['path']) ? (string)$p['path'] : '';
    $query = [];
    if (!empty($p['query'])) { parse_str((string)$p['query'], $query); }

    // ------------------------------------------------------------- YouTube
    if ($host === 'youtu.be') {
        if (preg_match('#^/([A-Za-z0-9_-]{6,20})#', $path, $m)) { return embed_hit('youtube', $m[1]); }
        return null;
    }
    if ($host === 'youtube.com' || $host === 'm.youtube.com' || $host === 'youtube-nocookie.com') {
        if (isset($query['v']) && is_string($query['v']) && preg_match('#^[A-Za-z0-9_-]{6,20}$#', $query['v'])) {
            return embed_hit('youtube', $query['v']);
        }
        if (preg_match('#^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{6,20})#', $path, $m)) {
            return embed_hit('youtube', $m[1]);
        }
        return null;
    }

    // ------------------------------------------------------------- Vimeo
    if ($host === 'vimeo.com' || $host === 'player.vimeo.com') {
        if (preg_match('#(?:^|/)(?:video/)?([0-9]{6,12})#', $path, $m)) { return embed_hit('vimeo', $m[1]); }
        return null;
    }

    // ------------------------------------------------------------- X / Twitter
    if ($host === 'x.com' || $host === 'twitter.com' || $host === 'mobile.twitter.com' || $host === 'mobile.x.com') {
        if (preg_match('#^/([A-Za-z0-9_]{1,20})/status(?:es)?/([0-9]{6,25})#', $path, $m)) {
            return embed_hit('x', $m[2], $m[1]);
        }
        return null;
    }

    // ------------------------------------------------------------- Instagram
    if ($host === 'instagram.com' || substr($host, -16) === '.instagram.com') {
        if (preg_match('#^/(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,30})#', $path, $m)) {
            return embed_hit('instagram', $m[1]);
        }
        return null;
    }

    return null;
}

/** embed_detect() yardımcısı: kanonik adresleri kurar. */
function embed_hit($provider, $id, $extra = '') {
    switch ($provider) {
        case 'youtube':
            $canonical = 'https://www.youtube.com/watch?v=' . $id;
            $permalink = $canonical;
            break;
        case 'vimeo':
            $canonical = 'https://vimeo.com/' . $id;
            $permalink = $canonical;
            break;
        case 'x':
            $kullanici = preg_match('#^[A-Za-z0-9_]{1,20}$#', (string)$extra) ? (string)$extra : 'i';
            $canonical = 'https://twitter.com/' . $kullanici . '/status/' . $id;
            $permalink = 'https://x.com/' . $kullanici . '/status/' . $id;
            break;
        case 'instagram':
            $canonical = 'https://www.instagram.com/p/' . $id . '/';
            $permalink = $canonical;
            break;
        default:
            return null;
    }
    return ['provider' => $provider, 'id' => (string)$id, 'user' => (string)$extra,
            'canonical' => $canonical, 'permalink' => $permalink];
}

// =============================================================== oEmbed çağrısı

/**
 * Sağlayıcıdan ÜSTVERİ ister. Sağlayıcının `html` alanı OKUNMAZ.
 *
 * @return array ['ok'=>bool, 'title'=>…, 'author'=>…, 'thumb'=>…, 'error'=>…]
 */
function embed_fetch_meta(array $hit) {
    $bos = ['ok' => false, 'title' => '', 'author' => '', 'thumb' => '', 'error' => ''];

    $tanim = embed_providers();
    $prov = $tanim[$hit['provider']];
    if ((string)$prov['endpoint'] === '') {
        // Instagram: uç nokta belirteç ister; istek hiç yapılmaz.
        $bos['error'] = 'Bu sağlayıcı için üstveri çağrısı yapılmaz.';
        return $bos;
    }

    // Dış istek RSS modülünün istemcisiyle yapılır: SSRF kalkanı, IP sabitleme,
    // yönlendirme yeniden doğrulaması ve boyut sınırı orada UYGULANMIŞ hâlde.
    if (!function_exists('rss_http_get')) {
        $f = INC_DIR . '/rss.php';
        if (is_file($f)) { require_once $f; }
    }
    if (!function_exists('rss_http_get')) {
        $bos['error'] = 'Dış istek modülü yüklenemedi.';
        return $bos;
    }

    // UÇ NOKTA SABİTTİR. Kullanıcının adresi yalnız KODLANMIŞ sorgu değeri olur.
    $params = array_merge(['url' => $hit['canonical']], (array)$prov['params']);
    $endpoint = $prov['endpoint'] . '?' . http_build_query($params);

    // Kemer + askı: sabit uç nokta da olsa kalkandan bir kez daha geçir.
    if (safe_remote_url($endpoint) === false && !getenv('MANSET_RSS_ALLOW_LOCAL')) {
        $bos['error'] = 'Gömme sunucusuna ulaşılamıyor.';
        return $bos;
    }

    $res = rss_http_get($endpoint, EMBED_TIMEOUT);
    if (empty($res['ok'])) {
        $bos['error'] = sanitize_line((string)arr($res, 'error', 'Sağlayıcı yanıt vermedi.'), 200);
        return $bos;
    }

    $data = json_decode((string)$res['body'], true);
    if (!is_array($data)) {
        $bos['error'] = 'Sağlayıcı yanıtı çözümlenemedi.';
        return $bos;
    }

    // BURADA `html` ALANINA DOKUNULMAZ — bilerek. Yalnız üstveri okunur ve
    // hepsi düz metne indirilir (sanitize_line etiketleri soyar).
    $thumb = trim((string)arr($data, 'thumbnail_url', ''));
    if ($thumb !== '' && (!preg_match('#^https://#i', $thumb) || !sanitize_is_safe_url($thumb, false))) {
        $thumb = '';
    }

    return [
        'ok'     => true,
        'title'  => sanitize_line((string)arr($data, 'title', ''), 300),
        'author' => sanitize_line((string)arr($data, 'author_name', ''), 120),
        // X'in kart metni: sağlayıcının `html` alanındaki metin DÜZ METNE indirilir.
        'text'   => embed_plain_from_html((string)arr($data, 'html', '')),
        'thumb'  => $thumb,
        'error'  => '',
    ];
}

/**
 * Sağlayıcı HTML'inden yalnız GÖRÜNÜR METNİ çıkarır.
 *
 * NEDEN strip_tags DEĞİL DE ÖNCE BLOK SİLME: `<script>…</script>` içindeki kod
 * gövdesi strip_tags'ten sonra METİN olarak kalırdı; kart metnine JavaScript
 * kaynağı basmak saçma olurdu (zararsız ama gürültü). Önce betik/stil blokları
 * içeriğiyle atılır, sonra kalan etiketler soyulur, sonuç sanitize_plain()'den
 * geçer (HTML yok, kontrol karakteri yok, uzunluk sınırlı).
 */
function embed_plain_from_html($html) {
    $html = (string)$html;
    if ($html === '') { return ''; }
    $html = (string)preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', ' ', $html);
    $html = (string)preg_replace('#<\s*br\s*/?>|</\s*p\s*>#i', "\n", $html);
    $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Twitter kartının kuyruğundaki "— Ad (@kullanici) 1 Ocak 2026" satırı atılır.
    $html = (string)preg_replace('/\x{2014}\s*[^\n]*\(@[A-Za-z0-9_]+\)[^\n]*$/u', '', trim($html));
    $metin = sanitize_plain($html, 600);
    return trim((string)preg_replace('/\n{2,}/', "\n", $metin));
}

// =============================================================== işaretleme üretimi

/**
 * Gövdeye girecek STATİK gömme HTML'ini üretir ve temizleyiciden geçirir.
 *
 * @return array ['ok'=>bool, 'html'=>string, 'error'=>string]
 */
function embed_render(array $hit, array $meta) {
    $prov = embed_providers()[$hit['provider']];
    $baslik = (string)arr($meta, 'title', '');
    if ($baslik === '') { $baslik = $prov['label'] . ' gömmesi'; }

    if (!empty($prov['iframe'])) {
        // İFRAME HOST'U BEYAZ LİSTEDE Mİ? Değilse HİÇ üretmeyiz — temizleyiciye
        // "bunu da kabul et" dedirtmek yerine burada dururuz.
        if (!in_array((string)$prov['host'], sanitize_allowed_iframe_hosts(), true)) {
            return ['ok' => false, 'html' => '', 'error' => 'Gömme sunucusu beyaz listede değil.'];
        }
        if ($hit['provider'] === 'youtube') {
            // youtube-nocookie: izleme çerezi yalnız oynatıldığında yazılır.
            $src = 'https://www.youtube-nocookie.com/embed/' . $hit['id'];
        } else {
            $src = 'https://player.vimeo.com/video/' . $hit['id'];
        }
        $html = '<figure class="manset-embed">'
              . '<iframe src="' . esc($src) . '" width="560" height="315"'
              . ' title="' . esc($baslik) . '"'
              . ' allow="accelerometer; encrypted-media; picture-in-picture; fullscreen"'
              . ' allowfullscreen loading="lazy"></iframe>'
              . '<figcaption>' . esc($baslik) . '</figcaption>'
              . '</figure>';
    } else {
        // ---------------------------------------------------- KART (X / Instagram)
        $metin = trim((string)arr($meta, 'text', ''));
        $yazar = trim((string)arr($meta, 'author', ''));
        if ($yazar === '' && $hit['user'] !== '') { $yazar = '@' . $hit['user']; }

        $ic = '';
        if ($metin !== '') {
            foreach (preg_split('/\n+/', $metin) as $satir) {
                $satir = trim($satir);
                if ($satir !== '') { $ic .= '<p>' . esc($satir) . '</p>'; }
            }
        }
        if ($ic === '') { $ic = '<p>' . esc($baslik) . '</p>'; }

        $etiket = $prov['label'] . ' gönderisini aç';
        if ($yazar !== '') { $etiket = $yazar . ' — ' . $etiket; }

        $html = '<figure class="manset-embed">'
              . '<blockquote>' . $ic . '</blockquote>'
              . '<figcaption><a href="' . esc($hit['permalink'])
              . '" target="_blank" rel="nofollow noopener noreferrer">' . esc($etiket) . '</a></figcaption>'
              . '</figure>';
    }

    // SON KAPI: ürettiğimizi temizleyiciye veririz. Temizleyici kırparsa
    // (ör. host beyaz listeden çıkarılmışsa) uç nokta hata döndürür — gövdeye
    // hiçbir zaman "temizlenince bozulan" bir parça yazılmaz.
    $temiz = sanitize_html($html, true);
    if (trim($temiz) === '') {
        return ['ok' => false, 'html' => '', 'error' => 'Gömme temizleyiciden geçemedi.'];
    }
    if (!empty($prov['iframe']) && stripos($temiz, '<iframe') === false) {
        return ['ok' => false, 'html' => '', 'error' => 'Gömme temizleyiciden geçemedi (iframe kaldırıldı).'];
    }
    return ['ok' => true, 'html' => $temiz, 'error' => ''];
}

// =============================================================== önbellek

/** Önbellek anahtarı — kanonik adresin sha256 özeti. */
function embed_cache_key($canonical) { return hash('sha256', (string)$canonical); }

/** Süresi geçmemiş önbellek satırı ya da null. */
function embed_cache_get($canonical) {
    if (!function_exists('schema_has_table') || !schema_has_table('embed_cache')) { return null; }
    try {
        $row = q1('SELECT * FROM embed_cache WHERE url_hash = :h', [':h' => embed_cache_key($canonical)]);
    } catch (Throwable $e) { return null; }
    if (!$row) { return null; }
    $son = (string)arr($row, 'expires_at', '');
    if ($son === '' || strtotime($son) <= time()) { return null; }
    return $row;
}

/** Önbelleğe yazar (varsa günceller). */
function embed_cache_put(array $hit, array $meta, $html, $status, $error) {
    if (!function_exists('schema_has_table') || !schema_has_table('embed_cache')) { return; }
    $ttl = ($status === 'ok') ? EMBED_TTL_OK : EMBED_TTL_ERROR;
    $alanlar = [
        'url_hash'    => embed_cache_key($hit['canonical']),
        'provider'    => (string)$hit['provider'],
        'url'         => (string)$hit['canonical'],
        'title'       => (string)arr($meta, 'title', ''),
        'author_name' => (string)arr($meta, 'author', ''),
        'thumb'       => (string)arr($meta, 'thumb', ''),
        'html'        => (string)$html,
        'status'      => $status === 'ok' ? 'ok' : 'error',
        'error'       => sanitize_line((string)$error, 200),
        'fetched_at'  => now(),
        'expires_at'  => date('Y-m-d H:i:s', time() + $ttl),
    ];
    try {
        $var = qv('SELECT id FROM embed_cache WHERE url_hash = :h', [':h' => $alanlar['url_hash']], 0);
        if ((int)$var > 0) { db_update('embed_cache', $alanlar, 'id = :i', [':i' => (int)$var]); }
        else { db_insert('embed_cache', $alanlar); }
    } catch (Throwable $e) {
        // Yarış: iki istek aynı anda aynı adresi yazabilir. Benzersiz indeks
        // ikincisini reddeder; bu bir HATA DEĞİL, önbellek zaten dolmuş demektir.
        log_error('embed_cache yazılamadı: ' . $e->getMessage(), (string)$hit['canonical']);
    }
}

// =============================================================== dış API

/**
 * Adresi gömmeye çevirir. Önbellek → (gerekiyorsa) oEmbed → statik HTML.
 *
 * @param string $url  Kullanıcının yapıştırdığı adres
 * @param bool   $tazele  true ise önbellek atlanır
 * @return array ['ok'=>bool,'provider'=>…,'html'=>…,'title'=>…,'cached'=>bool,'error'=>…]
 */
function embed_resolve($url, $tazele = false) {
    $hit = embed_detect($url);
    if (!$hit) {
        return ['ok' => false, 'provider' => '', 'html' => '', 'title' => '', 'cached' => false,
                'error' => 'Bu adres desteklenen bir gömme değil (YouTube, Vimeo, X, Instagram).'];
    }

    if (!$tazele) {
        $row = embed_cache_get($hit['canonical']);
        if ($row && (string)arr($row, 'status', '') === 'ok' && trim((string)arr($row, 'html', '')) !== '') {
            return ['ok' => true, 'provider' => (string)$row['provider'],
                    'html' => (string)$row['html'], 'title' => (string)arr($row, 'title', ''),
                    'cached' => true, 'error' => ''];
        }
        if ($row && (string)arr($row, 'status', '') === 'error') {
            return ['ok' => false, 'provider' => (string)$row['provider'], 'html' => '', 'title' => '',
                    'cached' => true, 'error' => (string)arr($row, 'error', 'Gömme çözümlenemedi.')];
        }
    }

    $meta = embed_fetch_meta($hit);
    // ÜSTVERİ ALINAMAZSA GÖMME YİNE ÜRETİLİR: video kimliği adresten güvenle
    // çıkarıldı, iframe'i kurmak için sağlayıcıya sormaya gerek yok. Kart
    // sağlayıcıları için de bağlantı hâlâ doğru. Kaybedilen yalnız başlıktır.
    if (empty($meta['ok'])) {
        $meta = ['ok' => false, 'title' => '', 'author' => '', 'text' => '', 'thumb' => '',
                 'error' => (string)arr($meta, 'error', '')];
    }

    $ciz = embed_render($hit, $meta);
    if (empty($ciz['ok'])) {
        embed_cache_put($hit, $meta, '', 'error', (string)$ciz['error']);
        return ['ok' => false, 'provider' => $hit['provider'], 'html' => '', 'title' => '',
                'cached' => false, 'error' => (string)$ciz['error']];
    }

    embed_cache_put($hit, $meta, (string)$ciz['html'], 'ok', '');
    return ['ok' => true, 'provider' => $hit['provider'], 'html' => (string)$ciz['html'],
            'title' => (string)arr($meta, 'title', ''), 'cached' => false, 'error' => ''];
}

// =============================================================== önizleme belirteci

/** Önizleme bağlantısının varsayılan ömrü (saat). */
function embed_preview_ttl_hours() {
    $s = (int)setting('preview_ttl_hours', '48');
    return max(1, min(168, $s));
}

/** `onizleme.php?t=…` adresi. */
function embed_preview_url($token) {
    return base_url() . '/onizleme.php?t=' . rawurlencode((string)$token);
}

/**
 * Yayımlanmamış haber için paylaşılabilir önizleme bağlantısı üretir.
 *
 * GÜVENLİK
 * - Belirteç 32 bayt (256 bit) kriptografik rastgeledir → tahmin edilemez.
 * - Veritabanına yalnız `sha256` ÖZETİ yazılır → sızıntı bağlantıyı vermez.
 * - Satır `post_id` taşır → belirteç YALNIZ o haberi açar.
 * - `expires_at` zorunludur → süresiz bağlantı üretilemez.
 *
 * @return array ['ok'=>bool,'url'=>…,'expires_at'=>…,'error'=>…]
 */
function embed_preview_create($postId, $userId, $hours = 0) {
    $postId = (int)$postId;
    if ($postId <= 0) { return ['ok' => false, 'error' => 'Haber seçilmedi.']; }
    if (!function_exists('schema_has_table') || !schema_has_table('post_previews')) {
        return ['ok' => false, 'error' => 'Önizleme tablosu yok (göç 029 uygulanmamış).'];
    }
    $post = q1('SELECT id, status FROM posts WHERE id = :i', [':i' => $postId]);
    if (!$post) { return ['ok' => false, 'error' => 'Haber bulunamadı.']; }

    $saat = (int)$hours > 0 ? min(168, max(1, (int)$hours)) : embed_preview_ttl_hours();
    $token = bin2hex(random_bytes(32));
    $son = date('Y-m-d H:i:s', time() + $saat * 3600);

    try {
        db_insert('post_previews', [
            'post_id'      => $postId,
            'token_hash'   => hash('sha256', $token),
            'created_by'   => (int)$userId,
            'uses'         => 0,
            'last_used_at' => null,
            'revoked_at'   => null,
            'expires_at'   => $son,
            'created_at'   => now(),
        ]);
    } catch (Throwable $e) {
        log_error('önizleme belirteci yazılamadı: ' . $e->getMessage(), 'haber=' . $postId);
        return ['ok' => false, 'error' => 'Önizleme bağlantısı üretilemedi.'];
    }

    return ['ok' => true, 'url' => embed_preview_url($token), 'expires_at' => $son,
            'hours' => $saat, 'error' => ''];
}

/**
 * Belirteci çözer. Yan etkisi YALNIZ kullanım sayacıdır — hak düşürmez,
 * çerez yazmaz, oturum açmaz.
 *
 * @return array ['ok'=>bool,'post_id'=>int,'error'=>…,'code'=>http kodu]
 */
function embed_preview_consume($token) {
    $token = trim((string)$token);
    // Biçim kapısı: 64 haneli onaltılık. Uymayan hiç sorgulanmaz.
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return ['ok' => false, 'post_id' => 0, 'error' => 'Önizleme bağlantısı geçersiz.', 'code' => 404, 'expires_at' => ''];
    }
    if (!function_exists('schema_has_table') || !schema_has_table('post_previews')) {
        return ['ok' => false, 'post_id' => 0, 'error' => 'Önizleme bağlantısı geçersiz.', 'code' => 404];
    }
    $row = q1('SELECT * FROM post_previews WHERE token_hash = :h', [':h' => hash('sha256', $token)]);
    if (!$row) {
        return ['ok' => false, 'post_id' => 0, 'error' => 'Önizleme bağlantısı geçersiz.', 'code' => 404];
    }
    if (trim((string)arr($row, 'revoked_at', '')) !== '') {
        return ['ok' => false, 'post_id' => 0, 'error' => 'Bu önizleme bağlantısı geri çekilmiş.', 'code' => 410];
    }
    $son = (string)arr($row, 'expires_at', '');
    if ($son === '' || strtotime($son) <= time()) {
        return ['ok' => false, 'post_id' => 0, 'error' => 'Bu önizleme bağlantısının süresi dolmuş.', 'code' => 410];
    }

    // BAĞLANTIYI ÜRETEN HESAP HÂLÂ ETKİN Mİ? (denetim turu 5, B2)
    //
    // Önizleme bağlantısı, onu üreten personelin yetkisinin uzantısıdır. İşten
    // ayrılan ya da askıya alınan bir kullanıcının hesabı kapatıldığında elindeki
    // bağlantı 168 saate kadar yayımlanmamış içeriği servis etmeye devam
    // ediyordu — denetçi bunu `UPDATE users SET active = 0` sonrası ölçtü.
    //
    // Hesabı kapatmak, o kişinin erişimini kesmek demektir; bağlantının bunu
    // hayatta tutması, kapatma işlemini sessizce eksik bırakır.
    $sahip = (int)arr($row, 'created_by', 0);
    if ($sahip > 0) {
        $etkin = qv('SELECT 1 FROM users WHERE id = :i AND active = 1', [':i' => $sahip]);
        if (!$etkin) {
            return ['ok' => false, 'post_id' => 0,
                    'error' => 'Bu önizleme bağlantısı artık geçerli değil.', 'code' => 410];
        }
    }

    try {
        q('UPDATE post_previews SET uses = uses + 1, last_used_at = :n WHERE id = :i',
          [':n' => now(), ':i' => (int)$row['id']]);
    } catch (Throwable $e) { /* sayaç denetim izidir, akışı durdurmaz */ }

    return ['ok' => true, 'post_id' => (int)$row['post_id'], 'error' => '', 'code' => 200,
            'expires_at' => $son];
}

/** Bir haberin etkin önizleme bağlantılarını geri çeker. */
function embed_preview_revoke_post($postId) {
    if (!function_exists('schema_has_table') || !schema_has_table('post_previews')) { return 0; }
    try {
        $st = q('UPDATE post_previews SET revoked_at = :n WHERE post_id = :p AND revoked_at IS NULL',
                [':n' => now(), ':p' => (int)$postId]);
        return (int)$st->rowCount();
    } catch (Throwable $e) { return 0; }
}

/** Bir haberin etkin (süresi dolmamış, geri çekilmemiş) bağlantı sayısı. */
function embed_preview_active_count($postId) {
    if (!function_exists('schema_has_table') || !schema_has_table('post_previews')) { return 0; }
    try {
        return (int)qv('SELECT COUNT(*) FROM post_previews WHERE post_id = :p AND revoked_at IS NULL AND expires_at > :n',
                       [':p' => (int)$postId, ':n' => now()], 0);
    } catch (Throwable $e) { return 0; }
}

// =============================================================== bakım

/** Eskimiş önbellek ve belirteç satırlarını siler (ucuz görev — ağa çıkmaz). */
function embed_cron_cleanup() {
    $silinen = 0;
    try {
        if (function_exists('schema_has_table') && schema_has_table('embed_cache')) {
            $st = q('DELETE FROM embed_cache WHERE expires_at < :c',
                    [':c' => date('Y-m-d H:i:s', time() - 7 * 86400)]);
            $silinen += (int)$st->rowCount();
        }
        if (function_exists('schema_has_table') && schema_has_table('post_previews')) {
            // 30 günden eski ve çoktan geçersiz belirteçler atılır. Yakın geçmiş
            // KORUNUR: "süresi dolmuş" mesajını verebilmek için satır gerekir.
            $st = q('DELETE FROM post_previews WHERE expires_at < :c',
                    [':c' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
            $silinen += (int)$st->rowCount();
        }
    } catch (Throwable $e) {
        log_error('embed temizliği: ' . $e->getMessage());
    }
    return ['ok' => true, 'silinen' => $silinen];
}

cron_register('gomme', 'embed_cron_cleanup', true);
