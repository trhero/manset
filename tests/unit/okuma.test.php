<?php
/**
 * 1.2-13 — Okuma ayarları ve karanlık mod (Ajan-G).
 *
 * Burada ölçülen şey PHP tarafı: parçaların ürettiği işaretleme, beş temanın
 * kancayı gerçekten çağırıp çağırmadığı ve stil dosyalarının sözleşmeyi tutup
 * tutmadığı. Tarayıcı davranışı (localStorage, prefers-color-scheme, kontrast)
 * ayrıca çalışan kurulumda ölçüldü — gelistirme/1.2-ajan-g.md.
 *
 * NEDEN tema dosyalarını metin olarak denetliyoruz: CONTRACTS §5.5 "her tema
 * aynı kancaları uygulamak zorunda" diyor ama bunu zorlayan bir kapı yok.
 * 1.1'de tam da bu yüzden üç tema sessizce özellik düşürdü (Okur Ö-3). Bu test
 * o kapıyı koyar: yeni bir tema eklenirse ya kancayı uygular ya kırmızı olur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

$temalar = ['gazete', 'kagit', 'bulten', 'gece', 'yerel'];

// --------------------------------------------------------------- parça çıktısı

test('okuma-ayarlari: üç yazı ölçeği ve üç görünüm seçeneği basılır', function () {
    $GLOBALS['manset_okuma_betik'] = null;
    $html = part_capture('okuma-ayarlari', ['yer' => 'haber']);
    foreach (['kucuk', 'orta', 'buyuk'] as $d) {
        icerir($html, 'value="' . $d . '"', 'yazı ölçeği kademesi eksik: ' . $d);
    }
    foreach (['sistem', 'acik', 'koyu'] as $d) {
        icerir($html, 'value="' . $d . '"', 'görünüm seçeneği eksik: ' . $d);
    }
    // Yalnız <input> etiketleri sayılır; aynı öznitelik adı davranış betiğinde de geçiyor.
    esit(3, preg_match_all('/<input[^>]*data-olcek-sec/', $html), 'ölçek tam 3 kademe olmalı');
    esit(3, preg_match_all('/<input[^>]*data-tema-sec/', $html), 'görünüm tam 3 durumlu olmalı (sistem/açık/koyu)');
});

test('okuma-ayarlari: iki örnek çakışmayan radyo grubu adı alır', function () {
    $GLOBALS['manset_okuma_betik'] = null;
    $ust = part_capture('okuma-ayarlari', ['yer' => 'haber']);
    $alt = part_capture('okuma-ayarlari', ['yer' => 'alt']);
    icerir($ust, 'name="okuma-haber-tema"');
    icerir($alt, 'name="okuma-alt-tema"');
    icermez($alt, 'name="okuma-haber-tema"', 'alt bilgideki kutu haberdeki grubun adını kullanmamalı');
});

test('okuma-ayarlari: davranış betiği sayfada yalnız bir kez basılır', function () {
    // NEDEN önemli: kutu hem haber meta satırında hem alt bilgide var; betik
    // iki kez basılsaydı dinleyiciler iki kez bağlanırdı.
    $GLOBALS['manset_okuma_betik'] = null;
    $ilk   = part_capture('okuma-ayarlari', ['yer' => 'haber']);
    $ikinci = part_capture('okuma-ayarlari', ['yer' => 'alt']);
    icerir($ilk, 'manset:olcek');
    icermez($ikinci, 'manset:olcek', 'ikinci örnek betiği tekrar basmamalı');
});

test('okuma-ayarlari: geçersiz yer değeri "haber"e düşer', function () {
    $GLOBALS['manset_okuma_betik'] = null;
    $html = part_capture('okuma-ayarlari', ['yer' => '"><script>']);
    icerir($html, 'name="okuma-haber-tema"');
    icermez($html, '<script>alert', 'yer parametresi işaretlemeye sızmamalı');
});

test('okuma tercihi sunucuya gitmez: parçalarda çerez izi yok', function () {
    // Sözleşme §1: anonim ziyaretçi çerez almaz. Tercih localStorage'da kalmalı.
    $GLOBALS['manset_okuma_betik'] = null;
    $html = part_capture('okuma-ayarlari', ['yer' => 'haber']) . part_capture('okuma-bas');
    icermez($html, 'document.cookie', 'okuma tercihi çereze yazılmamalı');
    icermez($html, 'setcookie', 'okuma tercihi çereze yazılmamalı');
    icerir($html, 'localStorage');
});

test('okuma-bas: seçim ile etkin görünüm ayrı özniteliklerde çözülür', function () {
    $html = part_capture('okuma-bas');
    icerir($html, 'data-tema');
    icerir($html, 'data-goruntu');
    icerir($html, 'prefers-color-scheme: dark');
    icerir($html, "data-okuma','1'", 'betik çalıştığını işaretlemeli');
});

test('JavaScript kapalıyken okuma ayarı kutusu hiç gösterilmez', function () use ($temalar) {
    // NEDEN: tercih localStorage'da uygulanıyor. Betik çalışmazsa kutu görünür
    // ama hiçbir şey yapmaz — çalışmayan bir denetim okuru yanıltır.
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/style.css');
        icerir($s, '.okuma-ayar{display:none;', $t . ': kutu JS yokken de görünüyor');
        icerir($s, 'html[data-okuma="1"] .okuma-ayar{display:inline-flex}', $t . ': kutuyu açan işaret kuralı yok');
    }
});

// ------------------------------------------------- beş temada kanca zorunluluğu

test('beş temanın layout.php dosyası okuma ön yükleyicisini çağırır', function () use ($temalar) {
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/layout.php');
        icerir($s, "part('okuma-bas')", $t . ': <head> ön yükleyicisi yok (FOUC)');
        icerir($s, 'data-tema="<?= esc($okumaTema) ?>"', $t . ': <html> data-tema basmıyor');
        icerir($s, 'data-goruntu="<?= esc($okumaGoruntu) ?>"', $t . ': <html> data-goruntu basmıyor');
        icerir($s, 'data-olcek="orta"', $t . ': <html> data-olcek basmıyor');
    }
});

test('beş temanın haber ve alt bilgi şablonu okuma ayarı kutusunu basar', function () use ($temalar) {
    foreach ($temalar as $t) {
        $tek = (string)file_get_contents(THEMES_DIR . '/' . $t . '/single.php');
        $alt = (string)file_get_contents(THEMES_DIR . '/' . $t . '/parts/footer.php');
        icerir($tek, "part('okuma-ayarlari'", $t . '/single.php: okuma ayarı kutusu yok');
        icerir($alt, "part('okuma-ayarlari'", $t . '/parts/footer.php: okuma ayarı kutusu yok');
    }
});

test('beş temanın stil dosyası üç yazı ölçeği kademesini tanımlar', function () use ($temalar) {
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/style.css');
        icerir($s, 'html[data-olcek="kucuk"]', $t . ': küçük kademe yok');
        icerir($s, 'html[data-olcek="orta"]', $t . ': orta kademe yok');
        icerir($s, 'html[data-olcek="buyuk"]', $t . ': büyük kademe yok');
        icerir($s, '--okuma-olcek', $t . ': ölçek çarpanı yok');
    }
});

test('beş temanın stil dosyası 72ch satır genişliğini uygular', function () use ($temalar) {
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/style.css');
        icerir($s, '72ch', $t . ': haber gövdesinde satır genişliği sınırı yok');
    }
});

test('beş temada koyu/açık görünüm ve color-scheme tanımlı', function () use ($temalar) {
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/style.css');
        icerir($s, 'html[data-goruntu="koyu"]', $t . ': koyu görünüm seçicisi yok');
        icerir($s, 'html[data-goruntu="acik"]', $t . ': açık görünüm seçicisi yok');
        icerir($s, 'color-scheme', $t . ': color-scheme bildirimi yok (form ve kaydırma çubuğu)');
        // Gece'nin TABANI koyu olduğu için yedeği açık yönde kurulur; ikisinden
        // biri bulunmalı.
        dogru(strpos($s, 'prefers-color-scheme: dark') !== false
            || strpos($s, 'prefers-color-scheme: light') !== false,
            $t . ': JavaScript kapalı sistem tercihi yedeği yok');
    }
});

test('beş temada hareketi azalt tercihi karşılanır', function () use ($temalar) {
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/style.css');
        icerir($s, 'prefers-reduced-motion', $t . ': hareketi azalt kuralı yok');
    }
});

test('okuma ayarı kutusu yazdırmada gizlenir', function () use ($temalar) {
    foreach ($temalar as $t) {
        $s = (string)file_get_contents(THEMES_DIR . '/' . $t . '/style.css');
        icerir($s, '.okuma-ayar{display:none!important}', $t . ': kutu kâğıda basılıyor');
    }
});
