<?php
/**
 * inc/embed.php — gömme çözümleme, önizleme belirteci ve fark motoru (1.3, Ajan-G).
 *
 * AĞA ÇIKMAZ. `embed_fetch_meta()` çağrılmaz; `embed_render()` ve `embed_detect()`
 * saf işlevlerdir. Ağ yolunun kendisi e2e'de ölçülür (yerel fikstür sunucusu).
 *
 * Koşucu (tests/unit/run.php) ortamı kurar; bu dosya doğrudan çalıştırılmaz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/sanitize.php';
require_once INC_DIR . '/embed.php';
require_once INC_DIR . '/api/ai_tools_api.php';


/**
 * Üretilen HTML'i TARAYICININ GÖRDÜĞÜ GİBİ denetler.
 *
 * NEDEN DİZE ARAMASI DEĞİL: kaçırılmış metin (`&lt;b onclick=...&gt;`) dizede
 * `onclick=` içerir ama tarayıcı için o sadece METİNDİR, öznitelik değildir.
 * Dize araması bu yüzden hem yanlış alarm verir hem de gerçek bir enjeksiyonu
 * gizleyebilir. Burada DOM kurulur ve GERÇEK düğüm/öznitelik listesi denetlenir.
 *
 * @return string[] bulunan tehlike açıklamaları (boşsa temiz)
 */
function embed_test_tehlike($html) {
    $bulgu = [];
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="k">' . $html . '</div>');
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    foreach ($xp->query('//*') as $el) {
        $ad = strtolower($el->nodeName);
        if (in_array($ad, ['script', 'style', 'object', 'embed', 'form', 'base', 'meta', 'svg'], true)) {
            $bulgu[] = 'tehlikeli etiket: <' . $ad . '>';
        }
        if (!$el->attributes) { continue; }
        foreach ($el->attributes as $a) {
            $an = strtolower($a->nodeName);
            if (strpos($an, 'on') === 0) { $bulgu[] = 'olay özniteliği: ' . $ad . '@' . $an; }
            if ($an === 'style') { $bulgu[] = 'style özniteliği: ' . $ad; }
            if (($an === 'src' || $an === 'href') && !sanitize_is_safe_url((string)$a->nodeValue, true)) {
                $bulgu[] = 'güvensiz şema: ' . $ad . '@' . $an;
            }
            if ($ad === 'iframe' && $an === 'src') {
                $host = strtolower((string)parse_url((string)$a->nodeValue, PHP_URL_HOST));
                if (!in_array($host, sanitize_allowed_iframe_hosts(), true)) {
                    $bulgu[] = 'beyaz liste dışı iframe host: ' . $host;
                }
            }
        }
    }
    return $bulgu;
}

// ==================================================== adres tanıma

test('embed_detect: YouTube adres biçimlerini tanır', function () {
    foreach ([
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://youtube.com/watch?v=dQw4w9WgXcQ&t=30s',
        'https://youtu.be/dQw4w9WgXcQ',
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
        'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    ] as $u) {
        $h = embed_detect($u);
        dogru(is_array($h), 'tanınmalı: ' . $u);
        esit('youtube', $h['provider'], $u);
        esit('dQw4w9WgXcQ', $h['id'], $u);
    }
});

test('embed_detect: Vimeo / X / Instagram', function () {
    $v = embed_detect('https://vimeo.com/76979871');
    esit('vimeo', $v['provider']);
    esit('76979871', $v['id']);

    $x = embed_detect('https://x.com/anadoluajansi/status/1234567890123');
    esit('x', $x['provider']);
    esit('1234567890123', $x['id']);
    esit('anadoluajansi', $x['user']);
    icerir($x['permalink'], 'x.com/anadoluajansi/status/1234567890123');

    $t = embed_detect('https://twitter.com/kullanici/statuses/9999999999');
    esit('x', $t['provider']);

    $i = embed_detect('https://www.instagram.com/reel/Cx1_yZ-abc/');
    esit('instagram', $i['provider']);
    esit('Cx1_yZ-abc', $i['id']);
});

test('embed_detect: desteklenmeyen ve kötü niyetli adresleri reddeder', function () {
    foreach ([
        '', 'merhaba', 'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>',
        'file:///etc/passwd', 'http://evil.example/watch?v=abc123',
        'https://youtube.com.evil.example/watch?v=abc123',
        'https://evil.example/?x=https://youtube.com/watch?v=abc123',
        'https://vimeo.com/', 'https://x.com/kullanici',
        'ftp://youtube.com/watch?v=abc123',
    ] as $kotu) {
        esit(null, embed_detect($kotu), 'reddedilmeli: ' . $kotu);
    }
    // Kullanıcı adı yerine enjeksiyon denemesi: kimlik dar kümeye kısıtlı
    esit(null, embed_detect('https://x.com/"><script>/status/1'));
});

// ==================================================== işaretleme üretimi

test('embed_render: YouTube gömmesi nocookie iframe üretir ve temizleyiciden geçer', function () {
    $h = embed_detect('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    $r = embed_render($h, ['title' => 'Bakan açıklaması', 'author' => 'Kanal', 'text' => '', 'thumb' => '']);
    dogru($r['ok'], 'üretilmeli');
    icerir($r['html'], 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    icerir($r['html'], 'class="manset-embed"');
    icerir($r['html'], 'loading="lazy"');
    icermez($r['html'], '<script');
    // sanitize_html() ikinci kez uygulandığında BOZULMAMALI (kararlı çıktı)
    esit($r['html'], sanitize_html($r['html'], true), 'çıktı temizleyici altında kararlı olmalı');
});

test('embed_render: Vimeo gömmesi player.vimeo.com kullanır', function () {
    $h = embed_detect('https://vimeo.com/76979871');
    $r = embed_render($h, ['title' => 'Belgesel', 'author' => '', 'text' => '', 'thumb' => '']);
    dogru($r['ok']);
    icerir($r['html'], 'https://player.vimeo.com/video/76979871');
});

test('embed_render: X ve Instagram BETİKSİZ kart üretir (iframe YOK)', function () {
    $x = embed_detect('https://x.com/kullanici/status/1234567890');
    $r = embed_render($x, ['title' => '', 'author' => '@kullanici', 'text' => "Birinci satır\nİkinci satır", 'thumb' => '']);
    dogru($r['ok']);
    icermez($r['html'], '<iframe', 'X için iframe üretilmemeli');
    icermez($r['html'], '<script', 'X için betik üretilmemeli');
    icerir($r['html'], 'Birinci satır');
    icerir($r['html'], 'https://x.com/kullanici/status/1234567890');
    icerir($r['html'], 'rel="nofollow noopener noreferrer"');

    $i = embed_detect('https://www.instagram.com/p/Abc123xyz/');
    $ri = embed_render($i, ['title' => '', 'author' => '', 'text' => '', 'thumb' => '']);
    dogru($ri['ok']);
    icermez($ri['html'], '<iframe');
    icermez($ri['html'], '<script');
    icerir($ri['html'], 'instagram.com/p/Abc123xyz/');
});

test('embed_render: sağlayıcı üstverisi HTML enjekte edemez', function () {
    $x = embed_detect('https://x.com/kullanici/status/1234567890');
    $r = embed_render($x, [
        'title'  => '<img src=x onerror=alert(1)>',
        'author' => '"><script>alert(2)</script>',
        'text'   => '<script>alert(3)</script> ve <b onclick="alert(4)">kalın</b>',
        'thumb'  => '',
    ]);
    dogru($r['ok']);
    // ÖLÇÜT: tehlikeli dizeler çıktıda KAÇIRILMIŞ METİN olarak bulunabilir
    // (bu zararsızdır); ÇALIŞTIRILABİLİR işaretleme bulunamaz.
    esit([], embed_test_tehlike($r['html']), 'DOM icinde calistirilabilir hicbir sey olmamali');
    icerir($r['html'], '&lt;script&gt;', 'yalnız kaçırılmış metin olarak görünmeli');
    esit($r['html'], sanitize_html($r['html'], true), 'çıktı temizleyici altında kararlı olmalı');

    $y = embed_detect('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    $ry = embed_render($y, ['title' => '"><iframe src="https://evil.example"></iframe>', 'author' => '', 'text' => '', 'thumb' => '']);
    dogru($ry['ok']);
    // İKİNCİ BİR IFRAME AÇILMADI: çıktıda tek iframe var ve kaynağı nocookie.
    esit(1, substr_count($ry['html'], '<iframe'), 'yalnız tek iframe olmalı');
    esit([], embed_test_tehlike($ry['html']), 'ikinci bir iframe/olay açılmamalı');
    icerir($ry['html'], 'src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"');
    esit($ry['html'], sanitize_html($ry['html'], true), 'çıktı temizleyici altında kararlı olmalı');
});

test('embed_plain_from_html: sağlayıcı HTML\'i düz metne iner, betik gövdesi kalmaz', function () {
    $ham = '<blockquote class="twitter-tweet"><p lang="tr" dir="ltr">Merhaba dünya</p>'
         . '&mdash; Ad (@kullanici) <a href="https://twitter.com/k/status/1">1 Ocak 2026</a></blockquote>'
         . '<script async src="https://platform.twitter.com/widgets.js"></script>';
    $m = embed_plain_from_html($ham);
    icerir($m, 'Merhaba dünya');
    icermez($m, '<');
    icermez($m, 'widgets.js');
    icermez($m, 'platform.twitter');
});

// ==================================================== sağlayıcı beyanı

test('embed_providers: iframe üreten her sağlayıcının host\'u beyaz listede', function () {
    $beyaz = sanitize_allowed_iframe_hosts();
    foreach (embed_providers() as $ad => $p) {
        if (empty($p['iframe'])) {
            esit('', (string)$p['host'], $ad . ' iframe üretmiyorsa host boş olmalı');
            continue;
        }
        dogru(in_array((string)$p['host'], $beyaz, true),
              $ad . ' host beyaz listede olmalı: ' . $p['host']);
    }
    // Instagram için DIŞ İSTEK YOK (belirteç gerektirir)
    esit('', (string)embed_providers()['instagram']['endpoint']);
});

// ==================================================== önizleme belirteci

test('embed_preview: belirteç ham saklanmaz, süresi dolar, yalnız kendi haberini açar', function () {
    $pid = db_insert('posts', [
        'title' => 'Taslak haber', 'slug' => 'taslak-haber-' . getmypid(), 'spot' => '',
        'body' => '<p>gövde</p>', 'status' => 'draft', 'type' => 'haber',
        'author_id' => 1, 'category_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $pid2 = db_insert('posts', [
        'title' => 'Baska taslak', 'slug' => 'baska-taslak-' . getmypid(), 'spot' => '',
        'body' => '<p>x</p>', 'status' => 'draft', 'type' => 'haber',
        'author_id' => 1, 'category_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $r = embed_preview_create($pid, 1, 2);
    dogru($r['ok'], 'bağlantı üretilmeli: ' . arr($r, 'error', ''));
    icerir($r['url'], 'onizleme.php?t=');

    // Ham belirteci adresten çıkar
    $ham = '';
    if (preg_match('/t=([0-9a-f]{64})/', $r['url'], $m)) { $ham = $m[1]; }
    esit(64, strlen($ham), 'belirteç 64 hane onaltılık olmalı');

    // VERİTABANINDA HAM BELİRTEÇ YOK
    $satir = q1('SELECT * FROM post_previews WHERE post_id = :p', [':p' => $pid]);
    dogru(is_array($satir), 'satır yazılmalı');
    icermez(implode('|', array_map('strval', $satir)), $ham, 'ham belirteç saklanmamalı');
    esit(hash('sha256', $ham), (string)$satir['token_hash'], 'yalnız sha256 özeti saklanmalı');

    // Doğru belirteç → doğru haber
    $c = embed_preview_consume($ham);
    dogru($c['ok'], 'geçerli belirteç açılmalı');
    esit($pid, (int)$c['post_id'], 'yalnız kendi haberini açmalı');
    yanlis((int)$c['post_id'] === (int)$pid2, 'başka habere geçilememeli');

    // Kurcalanmış belirteç
    $kurcali = substr($ham, 0, 63) . (($ham[63] === 'a') ? 'b' : 'a');
    $k = embed_preview_consume($kurcali);
    yanlis($k['ok'], 'kurcalanmış belirteç reddedilmeli');
    esit(404, (int)$k['code']);

    // Biçimsiz belirteçler
    foreach (['', 'abc', str_repeat('z', 64), $ham . 'a', '../../etc/passwd', "1' OR '1'='1"] as $kotu) {
        $x = embed_preview_consume($kotu);
        yanlis($x['ok'], 'biçimsiz belirteç reddedilmeli: ' . $kotu);
    }

    // Kullanım sayacı işliyor (denetim izi)
    esit(1, (int)qv('SELECT uses FROM post_previews WHERE post_id = :p', [':p' => $pid], 0));
});

test('embed_preview: süresi dolmuş belirteç 410 döner, geri çekilen de', function () {
    $pid = db_insert('posts', [
        'title' => 'Suresi dolan', 'slug' => 'suresi-dolan-' . getmypid(), 'spot' => '',
        'body' => '<p>x</p>', 'status' => 'draft', 'type' => 'haber',
        'author_id' => 1, 'category_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $r = embed_preview_create($pid, 1, 1);
    preg_match('/t=([0-9a-f]{64})/', $r['url'], $m);
    $ham = $m[1];

    // Süreyi geçmişe çek
    q('UPDATE post_previews SET expires_at = :e WHERE token_hash = :h',
      [':e' => date('Y-m-d H:i:s', time() - 60), ':h' => hash('sha256', $ham)]);
    $c = embed_preview_consume($ham);
    yanlis($c['ok'], 'süresi dolmuş belirteç açılmamalı');
    esit(410, (int)$c['code']);
    icerir($c['error'], 'süresi', 'mesaj "süresi dolmuş" demeli');

    // Geri çekme
    $r2 = embed_preview_create($pid, 1, 4);
    preg_match('/t=([0-9a-f]{64})/', $r2['url'], $m2);
    esit(1, embed_preview_active_count($pid), 'tek etkin bağlantı olmalı');
    dogru(embed_preview_revoke_post($pid) >= 1, 'geri çekilmeli');
    $c2 = embed_preview_consume($m2[1]);
    yanlis($c2['ok'], 'geri çekilen belirteç açılmamalı');
    esit(410, (int)$c2['code']);
    esit(0, embed_preview_active_count($pid));
});

test('embed_preview: ömür 1..168 saat aralığına kıstırılır', function () {
    $pid = db_insert('posts', [
        'title' => 'Omur', 'slug' => 'omur-' . getmypid(), 'spot' => '',
        'body' => '<p>x</p>', 'status' => 'draft', 'type' => 'haber',
        'author_id' => 1, 'category_id' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    esit(168, (int)embed_preview_create($pid, 1, 99999)['hours'], 'üst sınır 168 saat');
    esit(1, (int)embed_preview_create($pid, 1, -5)['hours'] > 0 ? 1 : 0, 'negatif ömür varsayılana düşmeli');
    yanlis(embed_preview_create(0, 1, 4)['ok'], 'haber seçilmeden bağlantı üretilmemeli');
    yanlis(embed_preview_create(999999, 1, 4)['ok'], 'olmayan habere bağlantı üretilmemeli');
});

// ==================================================== önbellek

test('embed_cache: yazılır, okunur ve süresi dolunca görünmez', function () {
    $hit = embed_detect('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    embed_cache_put($hit, ['title' => 'Başlık', 'author' => 'K', 'thumb' => ''],
                    '<figure class="manset-embed"></figure>', 'ok', '');
    $satir = embed_cache_get($hit['canonical']);
    dogru(is_array($satir), 'önbellekten okunmalı');
    esit('youtube', (string)$satir['provider']);
    esit(hash('sha256', $hit['canonical']), (string)$satir['url_hash']);

    q('UPDATE embed_cache SET expires_at = :e WHERE url_hash = :h',
      [':e' => date('Y-m-d H:i:s', time() - 10), ':h' => embed_cache_key($hit['canonical'])]);
    esit(null, embed_cache_get($hit['canonical']), 'süresi dolan satır görünmemeli');
});

// ==================================================== fark motoru

test('ai_tools_diff_words: ekleme / silme / değişiklik doğru işaretlenir', function () {
    $f = ai_tools_diff_words('bir iki üç dört', 'bir iki üç dört beş');
    $son = $f[count($f) - 1];
    esit('ekle', $son['op'], 'sona eklenen kelime "ekle" olmalı');
    icerir($son['text'], 'beş');

    $f2 = ai_tools_diff_words('bir iki üç dört', 'bir üç dört');
    $silinen = '';
    foreach ($f2 as $p) { if ($p['op'] === 'sil') { $silinen .= $p['text']; } }
    icerir($silinen, 'iki');

    $f3 = ai_tools_diff_words('Vali kentte açıklama yaptı', 'Vali ilde açıklama yaptı');
    $s = ai_tools_diff_stats($f3);
    esit(1, $s['sil'], 'tek kelime silinmeli');
    esit(1, $s['ekle'], 'tek kelime eklenmeli');
    esit(3, $s['esit'], 'kalan üç kelime aynı olmalı');

    // Aynı metin → hiç fark yok
    $f4 = ai_tools_diff_words('aynı metin', 'aynı metin');
    esit(0, ai_tools_diff_stats($f4)['ekle']);
    esit(0, ai_tools_diff_stats($f4)['sil']);

    // Boş taraflar
    esit([], ai_tools_diff_words('', ''));
    esit('ekle', ai_tools_diff_words('', 'yeni metin')[0]['op']);
    esit('sil', ai_tools_diff_words('eski metin', '')[0]['op']);
});

test('ai_tools_diff_html: metin KAÇIRILIR, işaretleme sızmaz', function () {
    $f = ai_tools_diff_words('<script>alert(1)</script> ortak',
                             '<img src=x onerror=alert(2)> ortak');
    $h = ai_tools_diff_html($f);
    // ÇALIŞTIRILABİLİR işaretleme yok; tehlikeli dizeler yalnız KAÇIRILMIŞ METİN olarak var.
    icermez($h, '<script');
    icermez($h, '<img');
    icerir($h, '&lt;script&gt;', 'kaynak metin kaçırılmış olmalı');
    icerir($h, '&lt;img src=x onerror=alert(2)&gt;', 'ikinci taraf da kaçırılmış olmalı');
    icerir($h, '<del class="fark-sil"');
    icerir($h, '<ins class="fark-ekle"');

    // Taraf süzgeci
    icermez(ai_tools_diff_html($f, 'eski'), '<ins');
    icermez(ai_tools_diff_html($f, 'yeni'), '<del');
});

test('ai_tools_diff_words: çok büyük girdide blok farkına düşer (bellek kalkanı)', function () {
    $a = trim(str_repeat('kelime ', 1500));
    $b = trim(str_repeat('baska ', 1500));
    $f = ai_tools_diff_words($a, $b);
    dogru(count($f) <= 4, 'blok farkı az sayıda parça üretmeli, üretilen: ' . count($f));
    $s = ai_tools_diff_stats($f);
    dogru($s['ekle'] > 0 && $s['sil'] > 0, 'iki taraf da işaretlenmeli');
});
