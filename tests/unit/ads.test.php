<?php
/**
 * inc/ads.php + inc/share.php — 1.2-03 ve 1.2-12 birim testleri (Ajan-B).
 *
 * Buradaki testler SAF mantığı ve veritabanına dokunan sayaç yolunu kapsar.
 * Tarayıcı tarafı (beacon'ın gerçekten atılması) ve önbellek etkileşimi
 * burada denenemez — onlar rapordaki canlı ölçümle gösterildi.
 *
 * SIRA ÖNEMLİ: ads_eligible_by_slot() ve ad_slot() istek başına bir kez sorgu
 * çalıştırıp statik olarak saklar. Bu yüzden test verisi dosyanın EN BAŞINDA,
 * ilk çağrıdan önce yazılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

require_once INC_DIR . '/view.php';
require_once INC_DIR . '/sanitize.php';
require_once INC_DIR . '/ads.php';
require_once INC_DIR . '/share.php';

// ------------------------------------------------------------ test verisi
$B_GORSEL = db_insert('ads', [
    'slot_key' => 'in_article', 'title' => 'Görsel reklam', 'html' => '',
    'kind' => 'image', 'image' => 'test-reklam.png', 'link_url' => 'https://reklamveren.example/kampanya',
    'alt_text' => 'Kampanya', 'approved_by' => 0, 'active' => 1, 'sort' => 0,
    'starts_at' => '', 'ends_at' => '', 'is_sponsored' => 0,
]);
$B_SPONSOR = db_insert('ads', [
    'slot_key' => 'sidebar_1', 'title' => 'Sponsorlu yazı', 'html' => '',
    'kind' => 'image', 'image' => 'sponsor.png', 'link_url' => 'https://sponsor.example/yazi',
    'alt_text' => 'Sponsor', 'approved_by' => 0, 'active' => 1, 'sort' => 0,
    'starts_at' => '', 'ends_at' => '', 'is_sponsored' => 1,
]);
// Onaysız ham kod: ön yüzde BASILMAMALI (1.1 kuralı korunuyor mu?)
$B_HAM = db_insert('ads', [
    'slot_key' => 'footer', 'title' => 'Onaysız kod', 'html' => '<script>alert(1)</script>',
    'kind' => 'html', 'image' => '', 'link_url' => '', 'alt_text' => '',
    'approved_by' => 0, 'active' => 1, 'sort' => 0, 'starts_at' => '', 'ends_at' => '', 'is_sponsored' => 0,
]);
// Adresi bozuk kayıt: tıklama ucu buna ASLA yönlendirmemeli
$B_KOTU = db_insert('ads', [
    'slot_key' => 'header', 'title' => 'Bozuk adres', 'html' => '',
    'kind' => 'image', 'image' => 'x.png', 'link_url' => 'javascript:alert(1)',
    'alt_text' => '', 'approved_by' => 0, 'active' => 0, 'sort' => 0,
    'starts_at' => '', 'ends_at' => '', 'is_sponsored' => 0,
]);

// ============================================================ ads.txt

test('ads_txt_clean: denetim karakterleri atılır, satır sonu normalleşir', function () {
    esit("a\nb", ads_txt_clean("a\r\nb"));
    esit('ornek.com, 1, DIRECT', ads_txt_clean("  ornek.com, 1, DIRECT\x00\x07  "));
    esit('', ads_txt_clean("   \n\n  "));
});

test('ads_txt_clean: boyut sınırlanır', function () {
    $uzun = str_repeat('a', 70000);
    esit(65536, strlen(ads_txt_clean($uzun)));
});

test('ads_txt_line_count: yorum ve boş satırlar sayılmaz', function () {
    esit(2, ads_txt_line_count("# yorum\n\nornek.com, 1, DIRECT\n  \ndiger.com, 2, RESELLER"));
    esit(0, ads_txt_line_count("# yalnizca yorum\n\n"));
});

// ============================================================ etiket

test('ads_slot_label: reklam ve sponsorlu ayrı ayrı etiketlenir', function () {
    esit('Reklam', ads_slot_label([['is_sponsored' => 0]]));
    esit('Sponsorlu içerik', ads_slot_label([['is_sponsored' => 1]]));
    esit('Reklam · Sponsorlu içerik', ads_slot_label([['is_sponsored' => 0], ['is_sponsored' => 1]]));
});

test('ads_slot_label: etiket boş ayarla KAPATILAMAZ', function () {
    setting_set('ads_label_text', '');
    setting_set('ads_sponsored_label', '   ');
    settings_all(true);
    esit('Reklam', ads_label_text());
    esit('Sponsorlu içerik', ads_sponsored_label());
    icerir(ads_slot_label([['is_sponsored' => 1]]), 'Sponsorlu');
});

test('ads_slot_label: yayıncı metni değiştirebilir', function () {
    setting_set('ads_label_text', 'İLAN');
    settings_all(true);
    esit('İLAN', ads_label_text());
    setting_set('ads_label_text', '');
    settings_all(true);
});

// ============================================================ tıklama çıpası

test('ads_rewrite_click: görsel reklamın çıpası sayaç ucuna çevrilir', function () use ($B_GORSEL) {
    $row = ['id' => $B_GORSEL, 'kind' => 'image', 'link_url' => 'https://reklamveren.example/kampanya'];
    $html = '<a href="https://reklamveren.example/kampanya" rel="nofollow noopener sponsored" target="_blank">'
          . '<img src="/uploads/test-reklam.png" alt="Kampanya"></a>';
    $yeni = ads_rewrite_click($html, $row);
    icerir($yeni, 'a=ads.click&amp;id=' . $B_GORSEL);
    icermez($yeni, 'href="https://reklamveren.example/kampanya"');
    icerir($yeni, 'rel="nofollow noopener sponsored"', 'rel korunmalı');
    icerir($yeni, '<img src="/uploads/test-reklam.png"', 'görsel etiketi bozulmamalı');
});

test('ads_rewrite_click: ham HTML reklamına HİÇ dokunulmaz', function () {
    $html = '<a href="https://ucuncu-taraf.example/x">tıkla</a>';
    $row = ['id' => 99, 'kind' => 'html', 'link_url' => 'https://ucuncu-taraf.example/x'];
    esit($html, ads_rewrite_click($html, $row), 'üçüncü taraf kodu yeniden yazılmamalı');
});

test('ads_rewrite_click: güvensiz adres yeniden yazılmaz', function () {
    $html = '<a href="javascript:alert(1)" rel="nofollow noopener sponsored" target="_blank">x</a>';
    $row = ['id' => 5, 'kind' => 'image', 'link_url' => 'javascript:alert(1)'];
    esit($html, ads_rewrite_click($html, $row));
});

// ============================================================ açık yönlendirme

test('ads_click_target: yalnız kayıtlı adres döner', function () use ($B_GORSEL) {
    esit('https://reklamveren.example/kampanya', ads_click_target($B_GORSEL));
});

test('ads_click_target: javascript: adresi reddedilir', function () use ($B_KOTU) {
    esit('', ads_click_target($B_KOTU), 'açık yönlendirme açığı olmamalı');
});

test('ads_click_target: ham HTML reklamı ve olmayan kayıt boş döner', function () use ($B_HAM) {
    esit('', ads_click_target($B_HAM));
    esit('', ads_click_target(999999));
    esit('', ads_click_target(0));
    esit('', ads_click_target(-3));
});

// ============================================================ sayaç

test('ads_stat_bump: satır açılır, sonraki çağrılar AYNI satırı artırır', function () use ($B_GORSEL) {
    // Fark ölçülür: bu reklamın sayacı önceki testlerde de artmış olabilir.
    $oku = function () use ($B_GORSEL) {
        $r = q1('SELECT impressions, clicks FROM ad_stats WHERE ad_id = :a AND day = :d',
            [':a' => $B_GORSEL, ':d' => ads_today()]);
        return $r ? [(int)$r['impressions'], (int)$r['clicks']] : [0, 0];
    };
    list($i0, $c0) = $oku();

    dogru(ads_stat_bump($B_GORSEL, 'impressions', 'in_article'));
    dogru(ads_stat_bump($B_GORSEL, 'impressions', 'in_article'));
    dogru(ads_stat_bump($B_GORSEL, 'clicks', 'in_article'));

    list($i1, $c1) = $oku();
    esit(2, $i1 - $i0, 'iki gösterim eklenmeli');
    esit(1, $c1 - $c0, 'bir tıklama eklenmeli');
    esit(1, (int)qv('SELECT COUNT(*) FROM ad_stats WHERE ad_id = :a', [':a' => $B_GORSEL], 0),
        '(reklam, gün) başına TEK satır olmalı');
});

test('ads_stat_bump: bilinmeyen alan ve geçersiz kimlik reddedilir', function () use ($B_GORSEL) {
    yanlis(ads_stat_bump($B_GORSEL, 'views'), 'sütun adı sabit kümeden gelmeli');
    yanlis(ads_stat_bump(0, 'clicks'));
});

test('ads_record_impressions: uydurma kimlik sayılmaz', function () use ($B_SPONSOR) {
    $once = (int)qv('SELECT COUNT(*) FROM ad_stats', [], 0);
    esit(0, ads_record_impressions([999999, 0, -1, 'abc']), 'yayında olmayan kimlik sayılmamalı');
    esit($once, (int)qv('SELECT COUNT(*) FROM ad_stats', [], 0), 'yeni satır açılmamalı');

    esit(1, ads_record_impressions([$B_SPONSOR, $B_SPONSOR]), 'aynı kimlik tek istekte bir kez sayılır');
});

test('ads_stats_summary: silinmiş reklam raporda kaybolmaz', function () {
    ads_stat_bump(4242, 'impressions', 'header');
    $rapor = ads_stats_summary(7);
    $bulundu = false;
    foreach ($rapor as $r) { if ($r['ad_id'] === 4242) { $bulundu = true; icerir($r['title'], 'silinmiş'); } }
    dogru($bulundu, 'kaydı olmayan reklam da raporda görünmeli');
});

// ============================================================ gövde içi reklam

test('ads_body_inject: kapalıyken gövde değişmez', function () {
    setting_set('ads_inbody_paragraph', '0');
    settings_all(true);
    $g = '<p>bir</p><p>iki</p><p>üç</p><p>dört</p>';
    esit($g, ads_body_inject($g));
});

test('ads_body_inject: N. paragraftan sonra basılır, HTML bozulmaz', function () {
    setting_set('ads_inbody_paragraph', '2');
    settings_all(true);
    $g = '<p>bir</p><p>iki</p><p>üç</p><p>dört</p>';
    $y = ads_body_inject($g);
    dogru(strlen($y) > strlen($g), 'reklam eklenmiş olmalı');
    icerir($y, '<p>bir</p><p>iki</p>', 'ilk iki paragraf bitişik kalmalı');
    icerir($y, '<p>üç</p><p>dört</p>', 'kalan paragraflar bitişik kalmalı');
    esit(4, substr_count($y, '</p>') - substr_count(ad_slot('in_article'), '</p>'),
        'paragraf sayısı değişmemeli');
    icerir($y, 'ad-kutu', 'reklam kutusu basılmalı');
});

test('ads_body_inject: yeterli paragraf yoksa basılmaz', function () {
    setting_set('ads_inbody_paragraph', '5');
    settings_all(true);
    $g = '<p>bir</p><p>iki</p>';
    esit($g, ads_body_inject($g), 'reklam gövdenin sonuna düşmemeli');
    setting_set('ads_inbody_paragraph', '0');
    settings_all(true);
});

// ============================================================ kanca çıktısı

test('ads_slot_decorate: etiket, sayaç işareti ve kutu basılır', function () use ($B_SPONSOR) {
    $ham = '<div class="ad-slot ad-sidebar_1"><img src="/uploads/sponsor.png" alt="Sponsor"></div>';
    $y = ads_slot_decorate('sidebar_1', $ham);
    icerir($y, 'data-ad-slot="sidebar_1"');
    dogru((bool)preg_match('/data-ad-ids="([0-9,]+)"/', $y, $m), 'sayaç işareti basılmalı');
    icerir(explode(',', isset($m[1]) ? $m[1] : ''), (string)$B_SPONSOR, 'kayıt kimliği işarete girmeli');
    icerir($y, 'Sponsorlu içerik', 'sponsorlu rozeti basılmalı');
    icerir($y, 'class="ad-kutu"');
});

test('ads_slot_decorate: boş çıktı sarmalanmaz', function () {
    esit('', ads_slot_decorate('footer', ''));
    esit('   ', ads_slot_decorate('footer', '   '));
});

test('ads_slot_decorate: onaysız ham kod ön yüze ÇIKMAZ (1.1 kuralı)', function () {
    // ad_slot() onaysız kaydı hiç basmaz; dekoratör de bir şey uyduramaz.
    esit('', ad_slot('footer'), 'approved_by boşken ham kod basılmamalı');
});

test('ads_beacon_script: çerez gönderilmez ve bir kez basılır', function () {
    // ads_slot_decorate önceki testte betiği zaten bastı; kaynak metni doğrula.
    $kaynak = (string)file_get_contents(INC_DIR . '/ads.php');
    icerir($kaynak, 'credentials:"omit"', 'beacon çerez göndermemeli');
    icermez($kaynak, 'session_boot', 'reklam modülü oturum açmamalı');
});

// ============================================================ paylaşım metni

test('share_wrap: kelime bazlı satırlama', function () {
    esit(['bir iki', 'uc dort'], share_wrap('bir iki uc dort', 8));
    esit([], share_wrap('   '));
    esit(['uzunbi'], share_wrap('uzunbirkelime', 6), 'sığmayan kelime kırpılır');
});

test('share_hashtags: en çok 3, Türkçe sadeleşir', function () {
    esit(['#gundem', '#secim', '#ekonomi'],
        share_hashtags(['gündem', 'seçim', 'ekonomi', 'spor']));
    esit([], share_hashtags([]));
});

test('share_template_texts: YZ olmadan da metin üretilir ve uydurmaz', function () {
    $core = ['title' => 'Deneme başlığı', 'spot' => 'Kısa spot metni.', 'body' => '',
             'category' => 'Gündem', 'url' => 'https://ornek.test/haber/deneme-1', 'tags' => ['gündem']];
    $t = share_template_texts($core);
    icerir($t['x'], 'Deneme başlığı');
    icerir($t['x'], 'https://ornek.test/haber/deneme-1');
    icerir($t['whatsapp'], 'https://ornek.test/haber/deneme-1');
    icermez($t['instagram'], 'https://ornek.test/haber/deneme-1', 'Instagram metninde bağlantı olmamalı');
    icerir($t['x'], '#gundem');
});

test('share_platforms: üç platform ve sınırları tanımlı', function () {
    $p = share_platforms();
    dogru(isset($p['x'], $p['instagram'], $p['whatsapp']));
    dogru($p['x'][1] > 0 && $p['instagram'][1] > 0 && $p['whatsapp'][1] > 0);
    dogru(share_platform_exists('x'));
    yanlis(share_platform_exists('facebook'));
});

test('share_ascii_downgrade: gömülü yazı tipi için sadeleştirme', function () {
    esit('Gunes cikti: Ismail sasirdi', share_ascii_downgrade('Güneş çıktı: İsmail şaşırdı'));
});

test('share_hex_rgb: geçersiz değer varsayılana düşer', function () {
    esit([255, 0, 0], share_hex_rgb('#ff0000'));
    esit([255, 0, 0], share_hex_rgb('f00'));
    esit([1, 2, 3], share_hex_rgb('yesil', [1, 2, 3]));
    esit([1, 2, 3], share_hex_rgb('', [1, 2, 3]));
});

test('share_image_available: GD yoksa özellik zarifçe kapanır', function () {
    // Bu kurulumda GD var; test yalnız işlevin bool döndüğünü ve
    // eksik eklentide yanlış dönecek koşulu okuduğunu doğrular.
    dogru(is_bool(share_image_available()));
    $kaynak = (string)file_get_contents(INC_DIR . '/share.php');
    icerir($kaynak, 'function_exists(\'imagecreatetruecolor\')');
});

test('inc/share.php: sosyal ağa otomatik gönderim YOK (kapsam dışı)', function () {
    $kaynak = (string)file_get_contents(INC_DIR . '/share.php')
            . (string)file_get_contents(INC_DIR . '/api/share_api.php');
    icermez($kaynak, 'api.twitter.com');
    icermez($kaynak, 'graph.facebook.com');
    icermez($kaynak, 'curl_exec');
});
