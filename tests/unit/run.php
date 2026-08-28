<?php
/**
 * Manşet — birim test koşucusu (Composer/PHPUnit YOK).
 *
 * Kullanım:  php tests/unit/run.php [--filtre=<desen>] [--renksiz]
 * Çıkış     : 0 tüm iddialar geçti · 1 en az bir iddia kaldı
 *
 * ---------------------------------------------------------------------------
 * TEST ORTAMI — NEDEN BÖYLE
 * ---------------------------------------------------------------------------
 * Test edilen fonksiyonların çoğu saf (sanitize_html, slugify, cache_parse_meta)
 * ama bir kısmı veritabanına dokunur (rate_limit, roles_can → role_overrides).
 * Bu yüzden koşucu KENDİ yalıtılmış örneğini kurar:
 *
 *   1. inc/bootstrap.php yüklenir (sabitler, mb yedekleri, hata günlüğü).
 *      bootstrap kökteki config.php'yi okur — buna DOKUNULMAZ.
 *   2. Hemen ardından $GLOBALS['manset_config'] tamamen değiştirilir:
 *      db/manset-unit.sqlite. db() tembel ve statiktir; ilk çağrı bizim
 *      ayarımızla olur, yani projenin gerçek veritabanı hiç açılmaz.
 *   3. schema_install() ile tablolar kurulur. schema_seed() ÇAĞRILMAZ:
 *      boş bir users tablosu, roles_can() testlerinin istediği yalın zemindir
 *      (responsible_user_exists() false döner, kural devreye girmez).
 *
 * Yani tests/dev-install.php'ye ihtiyaç YOKTUR ve config.php üretilmez.
 * Test veritabanı her koşuda sıfırdan yaratılır (.gitignore kapsamında).
 *
 * YALNIZ komut satırından çalışır — diğer test betiklerindeki kapının aynısı.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Yalnız komut satırı.\n";
    exit(1);
}

// ---------------------------------------------------------------- seçenekler
// Koşu durumu tek bir yerde tutulur: $GLOBALS['manset_unit'].
// (PHP 8.1'den beri $GLOBALS'a başvuru BAĞLAMAK kısıtlıdır; bu yüzden yerel bir
//  takma ad tutulmaz, her yerde doğrudan bu girdi okunup yazılır.)
$GLOBALS['manset_unit'] = [
    'filtre'  => '',
    'renk'    => (getenv('NO_COLOR') === false),
    'dosya'   => '',      // o an yürütülen test dosyası (rapor için)
    'testler' => 0,
    'iddia'   => 0,
    'gecen'   => 0,
    'kalan'   => [],      // [ ['test'=>…, 'yer'=>…, 'mesaj'=>…], … ]
    'atlanan' => 0,
];
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--filtre=') === 0) { $GLOBALS['manset_unit']['filtre'] = substr($arg, 9); continue; }
    if ($arg === '--renksiz') { $GLOBALS['manset_unit']['renk'] = false; continue; }
    if ($arg === '--yardim' || $arg === '-h') {
        echo "Kullanım: php tests/unit/run.php [--filtre=<desen>] [--renksiz]
";
        exit(0);
    }
    fwrite(STDERR, 'Bilinmeyen seçenek: ' . $arg . "
");
    exit(1);
}

// ---------------------------------------------------------------- renkler
function u_renk($metin, $kod) {
    return $GLOBALS['manset_unit']['renk'] ? "\033[" . $kod . 'm' . $metin . "\033[0m" : $metin;
}
function u_yesil($s) { return u_renk($s, '32'); }
function u_kirmizi($s) { return u_renk($s, '31'); }
function u_soluk($s) { return u_renk($s, '2'); }
function u_baslik($s) { printf("\n%s\n", u_renk($s, '1')); }

// ---------------------------------------------------------------- ortam
require_once dirname(__DIR__, 2) . '/inc/bootstrap.php';

$unitDb = DB_DIR . '/manset-unit.sqlite';
foreach ([$unitDb, $unitDb . '-wal', $unitDb . '-shm'] as $eski) { @unlink($eski); }

$GLOBALS['manset_config'] = [
    'db_driver'   => 'sqlite',
    'db_path'     => $unitDb,
    'db_host'     => '', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'base_url'    => 'http://birim.test',
    'cron_key'    => 'birim-test',
    'app_key'     => 'birim-test-anahtari-0123456789',
    'trust_proxy' => false,
];

require_once INC_DIR . '/schema.php';
try {
    schema_install();
    if (function_exists('migrations_run')) { migrations_run(false); }
} catch (Throwable $e) {
    fwrite(STDERR, "Test veritabanı kurulamadı: " . $e->getMessage() . "\n");
    exit(1);
}
settings_all(true);

// ---------------------------------------------------------------- API
/** Bir test bloğu tanımlar ve hemen yürütür. */
function test($ad, callable $govde) {
    $U = &$GLOBALS['manset_unit'];
    if ($U['filtre'] !== '' && stripos($ad, $U['filtre']) === false
        && stripos($U['dosya'], $U['filtre']) === false) {
        $U['atlanan']++;
        return;
    }
    $U['testler']++;
    $U['test_adi'] = $ad;
    $oncekiKalan = count($U['kalan']);
    try {
        $govde();
    } catch (Throwable $e) {
        u_kaydet(false, 'BEKLENMEYEN İSTİSNA ' . get_class($e) . ': ' . $e->getMessage(),
                 basename($e->getFile()) . ':' . $e->getLine());
    }
    $yeniKalan = count($U['kalan']) - $oncekiKalan;
    if ($yeniKalan === 0) {
        printf("  %s %s\n", u_yesil('✓'), $ad);
    } else {
        printf("  %s %s %s\n", u_kirmizi('✕'), $ad, u_soluk('(' . $yeniKalan . ' iddia kaldı)'));
    }
}

/** İddia sonucunu kaydeder. $yer verilmezse çağıran satır bulunur. */
function u_kaydet($basarili, $ayrinti = '', $yer = null) {
    $U = &$GLOBALS['manset_unit'];
    $U['iddia']++;
    if ($basarili) { $U['gecen']++; return true; }
    if ($yer === null) {
        $iz = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $kare = isset($iz[1]) ? $iz[1] : (isset($iz[0]) ? $iz[0] : []);
        $yer = isset($kare['file'])
            ? basename($kare['file']) . ':' . (isset($kare['line']) ? $kare['line'] : '?')
            : '?';
    }
    $U['kalan'][] = [
        'test'   => isset($U['test_adi']) ? $U['test_adi'] : '(test dışı)',
        'yer'    => $yer,
        'mesaj'  => $ayrinti,
    ];
    printf("      %s %s\n", u_kirmizi('→'), $ayrinti);
    printf("        %s\n", u_soluk($yer));
    return false;
}

/** Değeri rapor için okunur kısa metne çevirir. */
function u_goster($v, $enCok = 300) {
    if (is_bool($v))   { return $v ? 'true' : 'false'; }
    if ($v === null)   { return 'null'; }
    if (is_array($v))  { $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    if (is_object($v)) { $v = get_class($v); }
    $s = (string)$v;
    $s = str_replace(["\n", "\r", "\t"], ['\\n', '\\r', '\\t'], $s);
    if (strlen($s) > $enCok) { $s = substr($s, 0, $enCok) . '…'; }
    return "'" . $s . "'";
}

/** Katı eşitlik (===). */
function esit($beklenen, $gercek, $mesaj = '') {
    if ($beklenen === $gercek) { return u_kaydet(true); }
    return u_kaydet(false, ($mesaj !== '' ? $mesaj . ' — ' : '')
        . 'beklenen ' . u_goster($beklenen) . ', gerçek ' . u_goster($gercek));
}

/** Değer doğru mu (gevşek). */
function dogru($x, $mesaj = '') {
    if ($x) { return u_kaydet(true); }
    return u_kaydet(false, ($mesaj !== '' ? $mesaj . ' — ' : '') . 'doğru bekleniyordu, gerçek ' . u_goster($x));
}

/** Değer yanlış mı (gevşek). */
function yanlis($x, $mesaj = '') {
    if (!$x) { return u_kaydet(true); }
    return u_kaydet(false, ($mesaj !== '' ? $mesaj . ' — ' : '') . 'yanlış bekleniyordu, gerçek ' . u_goster($x));
}

/** Metin ya da dizi iğneyi içeriyor mu. */
function icerir($samanlik, $igne, $mesaj = '') {
    $var = is_array($samanlik) ? in_array($igne, $samanlik, true)
                               : (strpos((string)$samanlik, (string)$igne) !== false);
    if ($var) { return u_kaydet(true); }
    return u_kaydet(false, ($mesaj !== '' ? $mesaj . ' — ' : '')
        . u_goster($igne) . ' içermesi bekleniyordu: ' . u_goster($samanlik));
}

/** Metin ya da dizi iğneyi içermiyor mu. */
function icermez($samanlik, $igne, $mesaj = '') {
    $var = is_array($samanlik) ? in_array($igne, $samanlik, true)
                               : (strpos((string)$samanlik, (string)$igne) !== false);
    if (!$var) { return u_kaydet(true); }
    return u_kaydet(false, ($mesaj !== '' ? $mesaj . ' — ' : '')
        . u_goster($igne) . ' İÇERMEMESİ bekleniyordu: ' . u_goster($samanlik));
}

/** Çağrılabilir istisna atmalı. */
function atar(callable $fn, $mesaj = '') {
    try {
        $fn();
    } catch (Throwable $e) {
        return u_kaydet(true);
    }
    return u_kaydet(false, ($mesaj !== '' ? $mesaj . ' — ' : '') . 'istisna bekleniyordu, atılmadı');
}

// ---------------------------------------------------------------- koşu
$dosyalar = glob(__DIR__ . '/*.test.php');
sort($dosyalar);
if (!$dosyalar) {
    fwrite(STDERR, "tests/unit/ içinde *.test.php bulunamadı.\n");
    exit(1);
}

printf("%s %s\n", u_renk('Manşet birim testleri', '1'), u_soluk('(PHP ' . PHP_VERSION . ' · ' . db_driver() . ')'));
if ($GLOBALS['manset_unit']['filtre'] !== '') { printf("%s\n", u_soluk('filtre: ' . $GLOBALS['manset_unit']['filtre'])); }

$basla = microtime(true);
foreach ($dosyalar as $dosya) {
    $GLOBALS['manset_unit']['dosya'] = basename($dosya);
    $oncekiTest = $GLOBALS['manset_unit']['testler'];
    ob_start();
    require $dosya;
    $cikti = ob_get_clean();
    if ($GLOBALS['manset_unit']['testler'] > $oncekiTest) {
        u_baslik(basename($dosya));
        echo $cikti;
    }
}
$sure = round((microtime(true) - $basla) * 1000);

// ---------------------------------------------------------------- özet
$kalan = count($GLOBALS['manset_unit']['kalan']);
if ($kalan) {
    u_baslik('Kalan iddialar');
    foreach ($GLOBALS['manset_unit']['kalan'] as $i => $k) {
        printf("  %2d. %s\n      %s\n      %s\n",
            $i + 1, u_kirmizi($k['test']), $k['mesaj'], u_soluk($k['yer']));
    }
}

printf("\n%s  %s test · %s iddia · %s · %s%s\n",
    $kalan ? u_kirmizi('BAŞARISIZ') : u_yesil('TAMAM'),
    $GLOBALS['manset_unit']['testler'],
    $GLOBALS['manset_unit']['iddia'],
    u_yesil($GLOBALS['manset_unit']['gecen'] . ' geçti'),
    $kalan ? u_kirmizi($kalan . ' kaldı') : u_soluk('0 kaldı'),
    u_soluk(' · ' . $sure . ' ms')
);

// Test veritabanını temizlemeye çalış. db() içindeki PDO tekili kapatılamadığı
// için Windows'ta .sqlite dosyası açık kalır ve silinemez; sorun değil:
// /db/*.sqlite .gitignore kapsamındadır ve her koşu başında yeniden silinir.
foreach ([$unitDb . '-wal', $unitDb . '-shm', $unitDb] as $iz) { @unlink($iz); }

exit($kalan ? 1 : 0);
