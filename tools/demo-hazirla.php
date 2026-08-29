<?php
/**
 * Manşet — kısıtlı demo paketi üreticisi
 *
 * Kullanım:  php tools/demo-hazirla.php [--cikti=dist/manset-demo.zip]
 *
 * NE ÜRETİR
 * Sunucuya açıp doğrudan kullanılabilen, KURULUM İSTEMEYEN bir demo:
 *   · demo içeriği yüklü (haber, kategori, yorum, üye, reklam)
 *   · `config.php` içinde `demo_mode => true`
 *   · `db/demo-temiz.sqlite` — zamanlı sıfırlamanın geri döneceği anlık görüntü
 *   · `install/.locked` — sihirbaz yeniden çalışmasın
 *
 * NEDEN AYRI BİR ÜRETİCİ
 * Normal dağıtım paketi (`tools/build-dist.php`) veritabanı ve `config.php`
 * İÇERMEZ; bu doğrudur, çünkü herkes kendi kurulumunu yapar. Demo ise tam
 * tersini ister: veri dolu ve kurulmuş gelmeli ki ziyaretçi hiçbir şey
 * yapmadan gezebilsin.
 *
 * GÜVENLİK NOTU
 * Üretilen zip, İÇİNDE ÇALIŞAN BİR VERİTABANI ve `app_key` taşır. Bu dosya
 * herkese açık bir demo içindir; **gerçek bir sitenin paketi değildir.** Aynı
 * zip'i iki farklı yere kurarsanız ikisi de aynı `app_key`'i kullanır.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Yalnız komut satırı.\n";
    exit(1);
}

$kok = dirname(__DIR__);
require_once $kok . '/inc/bootstrap.php';

$cikti = $kok . '/dist/manset-demo-' . MANSET_VERSION . '.zip';
foreach ($argv as $a) {
    if (strpos($a, '--cikti=') === 0) { $cikti = substr($a, 8); }
}

$gecici = sys_get_temp_dir() . '/manset-demo-' . bin2hex(random_bytes(4));
echo "Çalışma dizini: {$gecici}\n";

/** Dizini özyinelemeli kopyalar; $atla önekleri atlanır. */
function demo_kopyala($kaynak, $hedef, array $atla, array $desen = []) {
    @mkdir($hedef, 0755, true);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($kaynak, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST);
    $n = 0;
    foreach ($it as $yol) {
        $rel = str_replace('\\', '/', substr($yol->getPathname(), strlen($kaynak) + 1));
        foreach ($atla as $p) {
            if ($rel === $p || strpos($rel, rtrim($p, '/') . '/') === 0) { continue 2; }
        }
        // DESEN HARİÇ TUTMA — ad listesi yetmiyor.
        // `tests/.run-8921` gibi e2e çalışma kopyaları PORTA GÖRE adlandırılır,
        // yani adları önceden bilinemez. İlk üretimde bunlar pakete sızdı:
        // 499 dosya, 2 adet `config.php` (app_key dâhil) ve 4 veritabanı —
        // her biri parolası bilinen bir yönetici hesabı taşıyordu.
        foreach ($desen as $d) { if (preg_match($d, $rel)) { continue 2; } }
        $hed = $hedef . '/' . $rel;
        if ($yol->isDir()) { @mkdir($hed, 0755, true); }
        else { @mkdir(dirname($hed), 0755, true); if (@copy($yol->getPathname(), $hed)) { $n++; } }
    }
    return $n;
}

// Kaynak ağaçtan kopyala. `tests/` DEMO PAKETİNE GİRER: demo tohumu ve
// geliştirme kurulumu buradan çalışır. Ama denetim/geliştirme belgeleri girmez.
$atla = [
    '.git', '.github', 'dist', 'tools', 'gelistirme', 'audit2', 'audit3', 'audit4', 'audit5',
    'proposals', 'config.php', 'db', 'uploads', 'install/.locked',
    'NOTES.md', 'CONTRACTS.md', 'GELISTIRME_PLANI.md', 'SECURITY_AUDIT.md', 'USER_TYPES_PLAN.md',
];
$adet = demo_kopyala($kok, $gecici, $atla, ['#^tests/\.#', '#(^|/)\.run#']);
echo "  {$adet} dosya kopyalandı\n";

@mkdir($gecici . '/db', 0755, true);
@mkdir($gecici . '/uploads/cache', 0755, true);
foreach (['db/.htaccess', 'uploads/.htaccess', 'uploads/index.html', 'uploads/cache/index.html'] as $k) {
    if (is_file($kok . '/' . $k)) { @copy($kok . '/' . $k, $gecici . '/' . $k); }
}

// Kurulum: dev-install demo için yeterli, ama config'i biz yazacağız.
echo "Kurulum yapılıyor…\n";
$kurulumCikti = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($gecici . '/tests/dev-install.php') . ' --force 2>&1', $kurulumCikti, $kod);
if ($kod !== 0) { fwrite(STDERR, "Kurulum başarısız:\n" . implode("\n", $kurulumCikti) . "\n"); exit(1); }

echo "Demo içeriği yükleniyor…\n";
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($gecici . '/tests/demo-seed.php') . ' 2>&1', $tohumCikti, $kod2);
if ($kod2 !== 0) { fwrite(STDERR, "Demo tohumu başarısız.\n"); exit(1); }

// TEMİZ ADRESLERİ KAPAT — demo BİLİNMEYEN bir sunucuya açılacak.
//
// `dev-install.php` `use_rewrite=1` yazar; bu, BU makinede ölçülmüş bir
// doğrudur ve pakete konduğunda hedef sunucu hakkında bir VARSAYIMA dönüşür.
// Varsayım tuttuğunda kimse fark etmez, tutmadığında sonuç şudur: ana sayfa
// açılır, tıklanan her bağlantı sunucunun 404 sayfasını verir. Bir kullanıcının
// hostinginde tam olarak bu oldu (kök .htaccess yüklenmemişti).
//
// `?r=…` adresleri mod_rewrite olmadan da HER sunucuda çalışır. Demoyu kuran
// kişi isterse Ayarlar'dan temiz adresleri açar; oradaki sonda gerçekten
// çalışıp çalışmadığını ÖLÇER. Çirkin ama çalışan, güzel ama kırık'tan iyidir.
$sefBetik = $gecici . '/.sef-kapat.php';
file_put_contents($sefBetik,
    "<?php
require __DIR__ . '/inc/bootstrap.php';
setting_set('use_rewrite', '0');
");
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($sefBetik) . ' 2>&1', $sefCikti, $kod3);
@unlink($sefBetik);
if ($kod3 !== 0) { fwrite(STDERR, "use_rewrite kapatilamadi:
" . implode("
", $sefCikti) . "
"); exit(1); }
echo "  temiz adresler KAPALI (demo bilinmeyen sunucuya kurulacak)
";

// config.php'yi demo için yeniden yaz: göreli veritabanı yolu + demo_mode.
$cfgYol = $gecici . '/config.php';
$cfg = require $cfgYol;
$cfg['db_path'] = '__DB__';                 // aşağıda göreli hâle getirilir
$cfg['demo_mode'] = true;
$cfg['demo_reset_hours'] = 6;
$cfg['base_url'] = '';                      // otomatik algılansın
$satir = "<?php\n"
       . "/**\n"
       . " * Manşet — DEMO yapılandırması.\n"
       . " *\n"
       . " * `demo_mode` AÇIK: yapay zekâ çağrıları, e-posta, yedek işlemleri,\n"
       . " * kullanıcı/rol yönetimi, ham HTML reklam, özel CSS ve ödeme ayarları\n"
       . " * kapalıdır. Ayrıntı: inc/demo.php\n"
       . " *\n"
       . " * Bu dosya bir DEMO içindir. Gerçek bir site kurmak için bu paketi\n"
       . " * kullanmayın; normal dağıtım paketini kurun ve kendi anahtarlarınızı\n"
       . " * üretin (buradaki app_key herkese açıktır).\n"
       . " */\n"
       . "return [\n";
foreach ($cfg as $k => $v) {
    if ($k === 'db_path') {
        $satir .= "    'db_path' => __DIR__ . '/db/manset-demo.sqlite',\n";
        continue;
    }
    $satir .= "    " . var_export($k, true) . " => " . var_export($v, true) . ",\n";
}
$satir .= "];\n";
file_put_contents($cfgYol, $satir);

// Veritabanını demo adına taşı ve TEMİZ KOPYAYI üret.
$eski = glob($gecici . '/db/*.sqlite');
if (!$eski) { fwrite(STDERR, "Veritabanı bulunamadı.\n"); exit(1); }
rename($eski[0], $gecici . '/db/manset-demo.sqlite');
foreach (glob($gecici . '/db/*.sqlite-wal') as $f) { @unlink($f); }
foreach (glob($gecici . '/db/*.sqlite-shm') as $f) { @unlink($f); }
copy($gecici . '/db/manset-demo.sqlite', $gecici . '/db/demo-temiz.sqlite');
echo "  temiz kopya üretildi (zamanlı sıfırlama buna döner)\n";

// Kurulum sihirbazını kilitle.
@mkdir($gecici . '/install', 0755, true);
file_put_contents($gecici . '/install/.locked', date('c') . " demo\n");

// Zip
@mkdir(dirname($cikti), 0755, true);
if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive yok. Dizin hazır: {$gecici}\n");
    exit(1);
}
@unlink($cikti);
$zip = new ZipArchive();
if ($zip->open($cikti, ZipArchive::CREATE) !== true) { fwrite(STDERR, "Zip açılamadı.\n"); exit(1); }
$kokAd = 'manset-demo-' . MANSET_VERSION;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($gecici, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST);
$say = 0;
foreach ($it as $yol) {
    $rel = str_replace('\\', '/', substr($yol->getPathname(), strlen($gecici) + 1));
    if ($yol->isDir()) { $zip->addEmptyDir($kokAd . '/' . $rel); }
    else { $zip->addFile($yol->getPathname(), $kokAd . '/' . $rel); $say++; }
}
$zip->close();

printf("\nDemo paketi hazır: %s\n  dosya: %d\n  boyut: %.2f MB\n",
       $cikti, $say, filesize($cikti) / 1048576);
echo "\nKurulum: zip'i sunucuya açın. Kurulum sihirbazı ÇALIŞMAZ (kilitli).\n";
echo "Giriş  : dev@manset.test / gelistirme123\n";
