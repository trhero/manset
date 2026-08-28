<?php
/**
 * inc/bootstrap.php — slugify(): Türkçe haber başlığından URL parçası.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

test('slugify: Türkçe harfler sadeleşir', function () {
    esit('cgiosu', slugify('çğıöşü'));
    esit('cgiosu', slugify('ÇĞIÖŞÜ'));
    esit('istanbul-bogazi', slugify('İstanbul Boğazı'));
    esit('sisli-belediyesi', slugify('Şişli Belediyesi'));
    esit('uskudar', slugify('Üsküdar'));
    esit('gunes-acti', slugify('Güneş açtı'));
    esit('aciklama-yapildi', slugify('Açıklama Yapıldı'));
});

test('slugify: kesme işareti ayraç değildir', function () {
    esit('igdirda', slugify("İğdır'da"));
    esit('istanbulda-yagmur', slugify("İstanbul'da yağmur"));
    esit('turkiyenin', slugify('Türkiye’nin'), 'tipografik kesme işareti de silinmeli');
    esit('ankaranin', slugify('Ankara`nın'));
    esit('izmirde', slugify('İzmir´de'));
});

test('slugify: ardışık ve baş/son tireler sadeleşir', function () {
    esit('bir-iki', slugify('bir   iki'));
    esit('bir-iki', slugify('bir --- iki'));
    esit('bir-iki', slugify('  bir // iki  '));
    esit('bir-iki', slugify('---bir---iki---'));
    esit('a-b-c', slugify('a.b,c'));
    esit('50-artis', slugify('%50 artış'), 'baştaki simge tire bırakmadan düşmeli');
});

test('slugify: boş ve anlamsız girdi', function () {
    esit('icerik', slugify(''), 'boş girdi yedek slug vermeli');
    esit('icerik', slugify('   '));
    esit('icerik', slugify('!!!???'));
    esit('icerik', slugify('---'));
    esit('icerik', slugify(null), 'null girdi çökmemeli');
    esit('123', slugify('123'), 'rakamlar korunur');
});

test('slugify: uzunluk sınırı ve tire ile bitmeme', function () {
    $uzun = str_repeat('haber ', 40);                  // 240 karakter
    $s = slugify($uzun);
    dogru(mb_strlen($s) <= 90, 'varsayılan sınır 90 olmalı, gerçek ' . mb_strlen($s));
    yanlis(substr($s, -1) === '-', 'slug tire ile bitmemeli');
    yanlis(substr($s, 0, 1) === '-', 'slug tire ile başlamamalı');
    icermez($s, '--');

    $k = slugify('bir iki üç dört beş', 10);
    dogru(mb_strlen($k) <= 10, 'özel sınır uygulanmalı');
    yanlis(substr($k, -1) === '-', 'kesme noktası tire ile bitmemeli');

    esit('c', slugify('ç', 1), 'tek harflik sınır çökmemeli');
});

test('slugify: yabancı harf eşlemesi', function () {
    esit('cafe', slugify('café'));
    esit('cafe', slugify('CAFÉ'), 'büyük É de eşlenmeli');
    esit('strasse', slugify('straße'));
    esit('manana', slugify('mañana'));
    esit('ost', slugify('øst'));

    /*
     * BULGU B-D2 (gerçek hata, kaynak kodda — bu iş kolunda düzeltilemez).
     * slugify() strtr(map) çağrısını mb_strtolower()'DAN ÖNCE yapar; harita ise
     * Ø / Å / Æ / Ñ için yalnız KÜÇÜK harf girdisi taşıyordu. Harita
     * mb_strtolower'DAN ÖNCE uygulandığı için (Türkçe 'İ' için bilinçli bir sıra:
     * mb_strtolower('İ') birleşik noktalı 'i̇' üretir ve slug bozulur) eksik büyük
     * harfler sonraki [^a-z0-9] süzgecinde SESSİZCE siliniyordu: "Øslo" → "slo".
     *
     * B-D2 birim testiyle bulundu ve haritaya büyük karşılıklar eklenerek
     * düzeltildi (inc/bootstrap.php, slugify). Aşağıdaki iddialar artık DOĞRU
     * davranışı bekler — regresyon olursa yeniden kırılırlar.
     */
    esit('oslo',  slugify('Øslo'),  'büyük Ø sadeleşmeli');
    esit('aland', slugify('Åland'), 'büyük Å sadeleşmeli');
    esit('aeon',  slugify('Æon'),   'büyük Æ sadeleşmeli');
    esit('nu',    slugify('Ñu'),    'büyük Ñ sadeleşmeli');
    // Küçük biçimler zaten çalışıyordu; ikisi birlikte sınanmalı ki
    // düzeltme yanlışlıkla küçük harf yolunu bozmasın.
    esit('ost',   slugify('øst'),   'küçük ø sadeleşmeli');
    // Türkçe yol korunmalı: harita sırası bu yüzden değiştirilmedi.
    esit('igdirda', slugify('İğdır\'da'), 'Türkçe büyük İ ve kesme işareti');
    esit('urgup',   slugify('Ürgüp'),      'Türkçe Ü');
});

test('slugify: XSS ve yol karakterleri geçmez', function () {
    $s = slugify('<script>alert(1)</script>');
    icermez($s, '<');
    icermez($s, '>');
    dogru((bool)preg_match('/^[a-z0-9-]+$/', $s), 'slug yalnız a-z0-9- içermeli: ' . $s);

    $y = slugify('../../etc/passwd');
    icermez($y, '.');
    icermez($y, '/');
    dogru((bool)preg_match('/^[a-z0-9-]+$/', $y), 'yol karakterleri temizlenmeli: ' . $y);
});
