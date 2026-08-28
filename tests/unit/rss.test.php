<?php
/**
 * inc/rss.php — çapraz kaynak tekrar tespiti (1.3-05).
 *
 * Ağa çıkan işlevler (rss_http_get, rss_import_image) burada DEĞİL: onlar
 * tests/e2e.sh içinde gerçek bir fikstür sunucusuyla denenir. Buradaki testler
 * normalleştirme ve karar mantığını, yani yanlış pozitif/negatif riskini ölçer.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/rss.php';

// ------------------------------------------------------------ URL normalizasyonu
test('rss_normalize_link: izleme parametreleri atılır', function () {
    $a = rss_normalize_link('https://ajans.test/haber/deprem-oldu?utm_source=twitter&utm_medium=social');
    $b = rss_normalize_link('https://ajans.test/haber/deprem-oldu');
    esit($b, $a, 'utm_* kuyruğu adresi farklılaştırmamalı');

    $c = rss_normalize_link('http://www.ajans.test/haber/deprem-oldu/?fbclid=XYZ#basa-don');
    esit($b, $c, 'www, sondaki /, çapa ve fbclid atılmalı; http/https ayrımı yok sayılmalı');
});

test('rss_normalize_link: anlamlı parametre KORUNUR', function () {
    $a = rss_normalize_link('https://ajans.test/haber.php?id=42');
    $b = rss_normalize_link('https://ajans.test/haber.php?id=43');
    dogru($a !== $b, 'kimlik taşıyan parametre atılırsa iki farklı haber aynı sayılırdı');

    // Sıra farkı adresi farklılaştırmamalı
    esit(rss_normalize_link('https://a.test/x?b=2&a=1'), rss_normalize_link('https://a.test/x?a=1&b=2'));
});

test('rss_link_hash: boş adres karma üretmez', function () {
    esit('', rss_link_hash(''));
    esit('', rss_link_hash('   '));
    esit(40, strlen(rss_link_hash('https://a.test/x')));
});

// ------------------------------------------------------------ başlık normalizasyonu
test('rss_normalize_title: Türkçe harfler ve noktalama katlanır', function () {
    esit(rss_normalize_title('İSTANBUL’DA ŞİDDETLİ YAĞMUR'), rss_normalize_title('Istanbul\'da siddetli yagmur'));
    esit('deprem oldu', rss_normalize_title('  Deprem   oldu!!! '));
    esit('', rss_normalize_title(''));
});

test('rss_title_key: aynı başlık aynı anahtar', function () {
    // Kıvrık ve düz kesme işareti aynı anahtarı vermeli (kaynaklar ikisini de yazar)
    esit(rss_title_key("Ankara'da patlama"), rss_title_key('Ankara’da patlama'));
    esit(rss_title_key('ANKARA’DA PATLAMA!'), rss_title_key('ankara’da patlama'));
    dogru(rss_title_key('Ankara’da patlama') !== rss_title_key('Ankara’da yangın'));
    esit('', rss_title_key(''));
});

// ------------------------------------------------------------ benzerlik
test('rss_title_similarity: aynı haber, farklı yazım → yüksek', function () {
    $p = rss_title_similarity(
        'Ankara’da metro istasyonunda yangın çıktı, seferler durduruldu',
        'Ankara metro istasyonunda yangın: seferler durduruldu');
    dogru($p >= rss_dedupe_threshold(), 'aynı haber eşiği geçmeli, ölçülen: ' . $p);
});

test('rss_title_similarity: özne/nesne yer değiştirmesi AYNI haber sayılmaz', function () {
    // Yalnız sözcük kümesine bakan bir ölçüt bu çifti %100 sayardı; sıra cezası
    // (bigram bileşeni) tam bu yüzden var.
    $p = rss_title_similarity('Galatasaray Fenerbahçe maçını kazandı ve lider oldu',
                              'Fenerbahçe Galatasaray maçını kazandı ve lider oldu');
    dogru($p < rss_dedupe_threshold(), 'ters haber elenmemeli, ölçülen: %' . $p);
});

test('rss_title_similarity: ağır yeniden yazım BİLEREK yakalanmaz', function () {
    // Aynı haberi tamamen farklı kuran iki başlık benzerlik yoluyla eşleşmez.
    // Bu kabul edilmiş bir yanlış negatiftir: bu çifti eşleştirecek kadar gevşek
    // bir eşik, farklı haberleri de eşleştirirdi. Böyle ögeler bağlantı
    // eşleşmesiyle ya da editörün gözüyle elenir.
    $p = rss_title_similarity('Merkez Bankası faiz kararını açıkladı bugün',
                              'Bugün açıklandı: Merkez Bankası faiz kararı');
    dogru($p < rss_dedupe_threshold(), 'ölçülen: %' . $p);
});

test('rss_title_similarity: FARKLI haberler eşiği geçmemeli', function () {
    $esik = rss_dedupe_threshold();
    $ciftler = [
        ['Ankara’da metro istasyonunda yangın çıktı', 'Ankara’da metro istasyonunda patlama oldu'],
        ['Galatasaray Fenerbahçe maçını kazandı ve lider oldu', 'Fenerbahçe Galatasaray maçını kazandı ve lider oldu'],
        ['İzmir’de sağanak yağış bekleniyor', 'İzmir’de kar yağışı bekleniyor'],
        ['Bakan Ankara’da açıklama yaptı', 'Bakan İstanbul’da açıklama yaptı'],
        ['Dolar kuru yükselişini sürdürüyor bugün', 'Altın fiyatları yükselişini sürdürüyor bugün'],
    ];
    foreach ($ciftler as $c) {
        $p = rss_title_similarity($c[0], $c[1]);
        dogru($p < $esik, 'yanlış pozitif: “' . $c[0] . '” ≈ “' . $c[1] . '” = %' . $p);
    }
});

test('rss_title_similarity: kısa başlıklar benzerlik yoluyla ELENMEZ', function () {
    // "Deprem" ile "Deprem" %100 benzerdir ama farklı iki deprem olabilir.
    esit(0, rss_title_similarity('Deprem', 'Deprem'), 'tek sözcüklü başlık benzerlikle eşleşmemeli');
    esit(0, rss_title_similarity('Son dakika deprem', 'Son dakika deprem'), '3 sözcük de yetmemeli');
    esit(0, rss_title_similarity('', ''));
});

test('rss_dedupe_threshold / window: ayarlar sınırlara kırpılır', function () {
    setting_set('rss_dedupe_threshold', '5');
    esit(50, rss_dedupe_threshold(), 'alt sınır 50');
    setting_set('rss_dedupe_threshold', '400');
    esit(100, rss_dedupe_threshold(), 'üst sınır 100');
    setting_set('rss_dedupe_threshold', '82');
    esit(82, rss_dedupe_threshold());

    setting_set('rss_dedupe_window_hours', '0');
    esit(1, rss_dedupe_window_hours());
    setting_set('rss_dedupe_window_hours', '48');
    esit(48, rss_dedupe_window_hours());
});

// ------------------------------------------------------------ karar (veritabanlı)
test('rss_find_duplicate: aynı bağlantı, BAŞKA kaynak → tekrar', function () {
    dogru(rss_has_dedupe_columns(), 'göç 027 uygulanmış olmalı');
    setting_set('rss_dedupe', '1');
    q('DELETE FROM rss_items');

    $ilk = db_insert('rss_items', [
        'source_id' => 1, 'guid_hash' => sha1('a1'), 'title' => 'Ankara metro yangını seferleri durdurdu',
        'link' => 'https://a.test/haber/metro-yangini',
        'link_hash' => rss_link_hash('https://a.test/haber/metro-yangini'),
        'title_key' => rss_title_key('Ankara metro yangını seferleri durdurdu'),
        'fetched_at' => now(), 'published_at' => now(), 'status' => 'new',
    ]);

    $yeni = ['title' => 'Bambaşka bir başlık', 'link' => 'https://a.test/haber/metro-yangini?utm_source=x'];
    $d = rss_find_duplicate($yeni, 2);
    dogru(is_array($d), 'utm kuyruklu aynı adres tekrar sayılmalı');
    esit((int)$ilk, (int)$d['id']);
    esit('baglanti', $d['reason']);

    // AYNI kaynak karşılaştırılmaz (kaynak içi mükerrer guid_hash işidir)
    esit(null, rss_find_duplicate($yeni, 1), 'aynı kaynak elenmemeli');
});

test('rss_find_duplicate: benzer başlık → tekrar, farklı haber → değil', function () {
    setting_set('rss_dedupe', '1');
    q('DELETE FROM rss_items');
    $ilk = db_insert('rss_items', [
        'source_id' => 1, 'guid_hash' => sha1('b1'),
        'title' => 'Ankara’da metro istasyonunda yangın çıktı, seferler durduruldu',
        'link' => 'https://a.test/haber/1', 'link_hash' => rss_link_hash('https://a.test/haber/1'),
        'title_key' => rss_title_key('Ankara’da metro istasyonunda yangın çıktı, seferler durduruldu'),
        'fetched_at' => now(), 'published_at' => now(), 'status' => 'new',
    ]);

    $ayni = ['title' => 'Ankara metro istasyonunda yangın: seferler durduruldu',
             'link' => 'https://b.test/haber/9'];
    $d = rss_find_duplicate($ayni, 2);
    dogru(is_array($d), 'aynı haber ikinci kaynaktan elenmeli');
    esit((int)$ilk, (int)$d['id']);

    $farkli = ['title' => 'İzmir’de sağanak yağış nedeniyle okullar tatil edildi',
               'link' => 'https://b.test/haber/10'];
    esit(null, rss_find_duplicate($farkli, 2), 'farklı haber ELENMEMELİ');
    q('DELETE FROM rss_items');
});

test('rss_find_duplicate: ayar kapalıyken hiç bakmaz', function () {
    setting_set('rss_dedupe', '0');
    yanlis(rss_dedupe_enabled());
    esit(null, rss_find_duplicate(['title' => 'x', 'link' => 'https://a.test/x'], 9));
    setting_set('rss_dedupe', '1');
});

test('rss_find_duplicate: pencere dışındaki öge karşılaştırılmaz', function () {
    setting_set('rss_dedupe', '1');
    setting_set('rss_dedupe_window_hours', '1');
    q('DELETE FROM rss_items');
    db_insert('rss_items', [
        'source_id' => 1, 'guid_hash' => sha1('c1'), 'title' => 'Eski haber başlığı burada duruyor',
        'link' => 'https://a.test/eski', 'link_hash' => rss_link_hash('https://a.test/eski'),
        'title_key' => rss_title_key('Eski haber başlığı burada duruyor'),
        'fetched_at' => date('Y-m-d H:i:s', time() - 7200), 'published_at' => now(), 'status' => 'new',
    ]);
    esit(null, rss_find_duplicate(['title' => 'Eski haber başlığı burada duruyor',
                                   'link' => 'https://a.test/eski'], 2),
        '2 saat önceki öge 1 saatlik pencerede görünmemeli');
    setting_set('rss_dedupe_window_hours', '48');
    q('DELETE FROM rss_items');
});

// ------------------------------------------------------------ görsel içe aktarma
test('rss_image: ayar varsayılanları ve sınırlar', function () {
    // Varsayılan KAPALI: yükseltme davranışı kendiliğinden değiştirmez.
    setting_set('rss_image_import', '0');
    yanlis(rss_image_import_enabled(), 'görsel indirme varsayılanı kapalı olmalı');

    setting_set('rss_image_max_kb', '1');
    esit(64 * 1024, rss_image_max_bytes(), 'alt sınır 64 KB');
    setting_set('rss_image_max_kb', '999999');
    esit(10240 * 1024, rss_image_max_bytes(), 'üst sınır 10 MB');
    setting_set('rss_image_max_kb', '2048');
    esit(2048 * 1024, rss_image_max_bytes());

    icermez(array_keys(rss_image_mimes()), 'image/svg+xml', 'SVG kabul edilmemeli (betik taşır)');
    icerir(array_keys(rss_image_mimes()), 'image/jpeg');
});

test('rss_import_image: SSRF kapısı görsel yolunda da kapalı', function () {
    $r = rss_import_image('http://169.254.169.254/latest/meta-data/');
    yanlis($r['ok'], 'bulut üstveri adresi reddedilmeli');
    $r2 = rss_import_image('file:///etc/passwd');
    yanlis($r2['ok'], 'file:// şeması reddedilmeli');
    $r3 = rss_import_image('');
    yanlis($r3['ok']);
});

// ------------------------------------------------------------ kaynak aralığı
test('rss_source_interval: sınırlar', function () {
    esit(RSS_DEFAULT_INTERVAL, rss_source_interval(['interval_min' => 0]), '0 = varsayılan');
    esit(RSS_DEFAULT_INTERVAL, rss_source_interval(['interval_min' => -5]), 'negatif değer de varsayılana düşer');
    esit(60, rss_source_interval(['interval_min' => 60]));
    esit(10080, rss_source_interval(['interval_min' => 999999]), 'en çok 7 gün');
});
