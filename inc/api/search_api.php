<?php
/**
 * JSON uçları — arama (1.3-06, Ajan-A).
 *
 *   search.query    (public)        tam metin arama + süzgeç + vurgu
 *   search.reindex  (tools.manage)  dizini ve etiket tablolarını yeniden kur
 *
 * `public.search_suggest` (Ajan-6) DEĞİŞMEDEN kalır; o uç `search_posts()`
 * çağırdığı için tam metin iyileştirmesinden kendiliğinden yararlanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ---------------------------------------------------------------- search.query
/**
 * İstek : {q, sayfa, kategori, baslangic, bitis}
 * Yanıt : {ok, items:[{id,title,url,title_html,excerpt_html,category,published_at}],
 *          total, sayfa, engine}
 *
 * ÇEREZSİZ: uç `public` yetkisiyle kayıtlıdır ve CSRF aramaz; oturum açan
 * hiçbir çağrı yapılmaz (`csrf_token()` YOK, `current_user()` YOK). Anonim
 * ziyaretçi çerez almaz — CONTRACTS §3.1.
 *
 * HIZ SINIRI: `search:<ip>` 30/60 sn — CONTRACTS §3'teki kova; arama ucu
 * anonim ve pahalıdır (tam metin dizini taranır), bu yüzden kova
 * `public.search_suggest` ile PAYLAŞILIR: iki uç aynı kaynağı tükettiği için
 * ayrı kovalar sınırı fiilen ikiye katlardı.
 */
api_register('search.query', function () {
    if (!rate_limit('search:' . client_ip(), 30, 60)) {
        json_err('Çok sık arama yapıyorsunuz. Bir dakika sonra tekrar deneyin.', 429);
    }

    $body = json_body();
    $al = function ($k, $d = '') use ($body) {
        if (array_key_exists($k, $body)) { return is_scalar($body[$k]) ? (string)$body[$k] : $d; }
        return inp_s($k, $d);
    };

    $q = sanitize_line((string)$al('q', ''), 80);
    if (mb_strlen($q) < 2) { return ['items' => [], 'total' => 0, 'sayfa' => 1, 'engine' => 'none']; }

    $sayfa = max(1, min(100, (int)$al('sayfa', '1')));
    $res = search_query($q, [
        'page' => $sayfa,
        'per_page' => max(1, min(30, (int)$al('adet', '12'))),
        'category' => search_category_id($al('kategori', '')),
        'from' => $al('baslangic', ''),
        'to' => $al('bitis', ''),
        'highlight' => true,
    ]);

    $items = [];
    foreach ($res['items'] as $p) {
        $items[] = [
            'id'           => (int)$p['id'],
            'title'        => (string)$p['title'],
            'url'          => url_post($p),
            // Vurgulu alanlar GÜVENLİ HTML'dir: search_highlight_html() metnin
            // her parçasını esc()'ten geçirir, yalnız <mark> ekler.
            'title_html'   => (string)arr($p, 'search_title_html', ''),
            'excerpt_html' => (string)arr($p, 'search_excerpt_html', ''),
            'category'     => (string)arr($p, 'category_name', ''),
            'published_at' => (string)arr($p, 'published_at', ''),
        ];
    }
    return ['items' => $items, 'total' => (int)$res['total'], 'sayfa' => $sayfa,
            'engine' => (string)arr($res, 'engine', 'like')];
}, ['perm' => 'public', 'methods' => ['GET', 'POST'], 'csrf' => false]);

// ---------------------------------------------------------------- search.reindex
/**
 * Dizini sıfırdan kurar ve etiket tablolarını tazeler.
 *
 * NE ZAMAN GEREKİR: normalde HİÇ. Dizin tetikleyiciyle güncel kalır. Bu uç,
 * veritabanı yedekten geri yüklendiğinde (tetikleyiciler dökümde olmayabilir)
 * ya da yayıncı dizini elle bozduğunda kullanılır.
 *
 * İzin `tools.manage` — KİLİTLİ izin (CONTRACTS §12.5), yalnız admin. Tüm
 * gövdeleri okuyup yeniden yazan bir işlemdir; yazma izni olan her editöre
 * açılırsa ucuz bir kaynak tüketme yüzeyi olurdu.
 */
api_register('search.reindex', function () {
    $motor = search_index_ensure();
    $satir = $motor === 'fts5' ? search_index_rebuild() : 0;
    search_engine_reset();
    $etiket = search_tags_sync_all(0, '');
    setting_set('search_tags_synced_at', now());
    return [
        'engine'  => $motor === '' ? 'like' : $motor,
        'indexed' => (int)$satir,
        'tagged'  => (int)$etiket,
        'message' => $motor === ''
            ? 'Bu kurulumda tam metin dizini kullanılamıyor; arama başlık, spot ve etikette çalışıyor.'
            : 'Arama dizini yeniden kuruldu (' . (int)$satir . ' haber, ' . (int)$etiket . ' etiket eşitlemesi).',
    ];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);
