<?php
/**
 * Manşet — dağıtım paketi üreticisi.
 *
 * Kullanım:  php tools/build-dist.php [--out=dist] [--name=manset-1.0.0]
 *
 * Üretilen zip, paylaşımlı hostinge FTP ile açılıp doğrudan kurulabilir hâldedir.
 * Dışarıda bırakılanlar: config.php, db/*.sqlite*, db/error.log, install/.locked,
 * tests/, tools/, proposals/, audit2/, audit3/, gelistirme/, .git/, NOTES.md,
 * CONTRACTS.md, uploads içeriği.
 * docs/ PAKETE GİRER — README oradaki BİK notlarına bağlantı veriyor.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Bu betik yalnız komut satırından çalıştırılır.\n";
    exit(1);
}

$root = dirname(__DIR__);
$outDir = $root . '/dist';
$name = 'manset';

foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--out=') === 0) { $outDir = $root . '/' . trim(substr($arg, 6), '/'); }
    if (strpos($arg, '--name=') === 0) { $name = preg_replace('/[^A-Za-z0-9._-]/', '', substr($arg, 7)); }
}

// Sürümü inc/bootstrap.php'den oku
$version = '0.0.0';
$bootstrap = @file_get_contents($root . '/inc/bootstrap.php');
if ($bootstrap && preg_match("/define\('MANSET_VERSION',\s*'([^']+)'\)/", $bootstrap, $m)) {
    $version = $m[1];
}
if ($name === 'manset') { $name = 'manset-' . $version; }

/** Pakete girmeyecek yollar (kökten göreli, önek eşleşmesi). */
function dist_excluded_prefixes() {
    return [
        '.git/', '.github/', 'dist/', 'tests/', 'tools/', 'proposals/', 'audit2/', 'audit3/', 'gelistirme/',
        'node_modules/', '.vscode/', '.idea/',
    ];
}

/** Pakete girmeyecek tam dosya yolları. */
function dist_excluded_files() {
    return [
        'config.php', 'install/.locked', 'db/error.log', 'db/error.log.1',
        'NOTES.md', 'CONTRACTS.md', 'USER_TYPES_PLAN.md', 'SECURITY_AUDIT.md', 'GELISTIRME_PLANI.md',
        '.gitattributes', '.gitignore',
    ];
}

/** Desene göre dışlananlar. */
function dist_excluded_patterns() {
    return [
        '#^db/.*\.sqlite(-wal|-shm)?$#',
        '#^uploads/(?!\.htaccess$|index\.html$|cache/index\.html$).+#',
        '#(^|/)\.DS_Store$#',
        '#(^|/)Thumbs\.db$#',
        '#\.log$#',
    ];
}

/** Bu yol pakete girsin mi? */
function dist_included($rel) {
    $rel = str_replace('\\', '/', $rel);
    foreach (dist_excluded_prefixes() as $p) { if (strpos($rel, $p) === 0) { return false; } }
    if (in_array($rel, dist_excluded_files(), true)) { return false; }
    foreach (dist_excluded_patterns() as $re) { if (preg_match($re, $rel)) { return false; } }
    return true;
}

/**
 * Kök altındaki tüm dosyaları göreli yol olarak toplar.
 * Dışlanan klasörlerin içine hiç girilmez (büyük .git klasörü boşuna taranmasın).
 */
function dist_collect($root, $rel = '') {
    $out = [];
    $dir = $rel === '' ? $root : $root . '/' . $rel;
    $entries = @scandir($dir);
    if (!$entries) { return $out; }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $childRel = $rel === '' ? $entry : $rel . '/' . $entry;
        $abs = $root . '/' . $childRel;
        if (is_dir($abs)) {
            if (!dist_included($childRel . '/')) { continue; }
            $out = array_merge($out, dist_collect($root, $childRel));
        } elseif (dist_included($childRel)) {
            $out[] = $childRel;
        }
    }
    sort($out);
    return $out;
}

if (!is_dir($outDir) && !@mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "Çıktı klasörü oluşturulamadı: {$outDir}\n");
    exit(1);
}

$files = dist_collect($root);
if (!$files) { fwrite(STDERR, "Paketlenecek dosya bulunamadı.\n"); exit(1); }

// Söz dizimi denetimi — bozuk paket çıkmasın
$phpBin = PHP_BINARY;
$lintFail = [];
foreach ($files as $rel) {
    if (substr($rel, -4) !== '.php') { continue; }
    $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1';
    exec($cmd, $lines, $code);
    if ($code !== 0) { $lintFail[] = $rel . ' — ' . trim(implode(' ', $lines)); }
    $lines = [];
}
if ($lintFail) {
    fwrite(STDERR, "Söz dizimi hatası olan dosyalar var, paket üretilmedi:\n");
    foreach ($lintFail as $l) { fwrite(STDERR, '  ' . $l . "\n"); }
    exit(1);
}

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive eklentisi yok. Dosya listesi dist/dosyalar.txt olarak yazıldı.\n");
    file_put_contents($outDir . '/dosyalar.txt', implode("\n", $files) . "\n");
    exit(1);
}

$zipPath = $outDir . '/' . $name . '.zip';
@unlink($zipPath);
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Zip oluşturulamadı: {$zipPath}\n");
    exit(1);
}

$prefix = $name . '/';
$total = 0;
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, $prefix . $rel);
    $total += (int)@filesize($root . '/' . $rel);
}
// Boş ama gerekli klasörler
foreach (['uploads/cache', 'db'] as $dir) { $zip->addEmptyDir($prefix . $dir); }

// Kurulum hatırlatması
$zip->addFromString($prefix . 'KURULUM.txt',
      "MANŞET " . $version . " — KURULUM\r\n"
    . "================================\r\n\r\n"
    . "1. Bu klasörün İÇİNDEKİ dosyaları sunucunuzun web köküne yükleyin\r\n"
    . "   (genellikle public_html/ veya htdocs/).\r\n"
    . "2. Tarayıcıdan sitenizin adresini açın; kurulum sihirbazı karşılayacak.\r\n"
    . "3. Kurulum bitince install/ klasörünü sunucudan silin.\r\n"
    . "4. Panel > Ayarlar ekranındaki cron komutunu hosting panelinize ekleyin.\r\n\r\n"
    . "Gereksinimler: PHP 8.0+, PDO (SQLite veya MySQL), SimpleXML.\r\n"
    . "Önerilen: mbstring, GD, cURL, mod_rewrite.\r\n\r\n"
    . "Yazma izni gereken yollar: db/, uploads/, ve kök klasör (config.php için).\r\n\r\n"
    . "Belgeler: README.md · Lisans: MIT (LICENSE)\r\n");

$zip->close();

$zipSize = (int)@filesize($zipPath);
printf("Paket hazır: %s\n", str_replace($root . '/', '', str_replace('\\', '/', $zipPath)));
printf("  dosya sayısı : %d\n", count($files));
printf("  ham boyut    : %.1f MB\n", $total / 1048576);
printf("  zip boyutu   : %.1f MB\n", $zipSize / 1048576);
printf("  sürüm        : %s\n", $version);
