<?php
/**
 * Manşet — MySQL test kurulumu (tests/dev-install.php'nin MySQL karşılığı).
 *
 * Bağlantı bilgileri ORTAM DEĞİŞKENLERİNDEN okunur; komut satırında parola
 * geçirilmez (ps çıktısında görünürdü):
 *
 *   MANSET_DB_HOST  (varsayılan 127.0.0.1)
 *   MANSET_DB_PORT  (varsayılan 3306)
 *   MANSET_DB_NAME  (varsayılan manset_test)
 *   MANSET_DB_USER  (varsayılan root)
 *   MANSET_DB_PASS  (varsayılan boş)
 *
 * Kullanım:
 *   php tests/mysql-kur.php                    # boş DB + şema + tohum + config.php (proje kökü)
 *   php tests/mysql-kur.php --bos              # YALNIZ boş veritabanı; config.php YAZILMAZ
 *   php tests/mysql-kur.php --hedef=tests/.run # config.php'yi başka bir köke yaz
 *   php tests/mysql-kur.php --bekle=60         # sunucu ayağa kalkana dek bekle (CI servisi)
 *   php tests/mysql-kur.php --force            # var olan config.php'nin üstüne yaz
 *
 * --bos KİPİ e2e İÇİNDİR: tests/e2e.sh kurulumu KENDİ sihirbazından yapar
 * (install/?adim=2 formu). Orada gereken tek şey, sihirbazın yazabileceği BOŞ
 * bir veritabanıdır. Ayrıntılı bağlanma yolu .github/workflows/ci.yml içinde.
 *
 * YALNIZ komut satırından çalışır (bkz. tests/dev-install.php, denetim tur 2 B03):
 * register_argc_argv açık bir sunucuda GET /tests/mysql-kur.php?--force isteği
 * test veritabanını silip bilinen bir yönetici parolasıyla yeniden kurabilirdi.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Yalnız komut satırı.\n";
    exit(1);
}

// ---------------------------------------------------------------- seçenekler
$bos    = in_array('--bos', $argv, true);
$force  = in_array('--force', $argv, true);
$hedef  = '';
$bekle  = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--hedef=') === 0) { $hedef = substr($arg, 8); continue; }
    if (strpos($arg, '--bekle=') === 0) { $bekle = (int)substr($arg, 8); continue; }
    if (in_array($arg, ['--bos', '--force'], true)) { continue; }
    if ($arg === '--yardim' || $arg === '-h') {
        echo "Kullanım: php tests/mysql-kur.php [--bos] [--hedef=<dizin>] [--bekle=<sn>] [--force]\n";
        exit(0);
    }
    fwrite(STDERR, 'Bilinmeyen seçenek: ' . $arg . "\n");
    exit(1);
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

/** Ortam değişkeni oku (boş dize de geçerli bir değerdir). */
function mk_env($ad, $varsayilan) {
    $v = getenv($ad);
    return ($v === false) ? $varsayilan : $v;
}

$host = mk_env('MANSET_DB_HOST', '127.0.0.1');
$port = (int)mk_env('MANSET_DB_PORT', '3306');
$name = mk_env('MANSET_DB_NAME', 'manset_test');
$user = mk_env('MANSET_DB_USER', 'root');
$pass = mk_env('MANSET_DB_PASS', '');

// Veritabanı ADI bir tanımlayıcıdır; parametre olarak bağlanamaz. Bu yüzden
// beyaz listeyle doğrulanır — DROP/CREATE cümlesine enjeksiyon girmesin.
if (!preg_match('/^[A-Za-z0-9_]{1,60}$/', (string)$name)) {
    fwrite(STDERR, "MANSET_DB_NAME yalnız harf, rakam ve alt çizgi içerebilir: " . $name . "\n");
    exit(1);
}

$hedefKok = $hedef !== ''
    ? (preg_match('#^([A-Za-z]:)?[/\\\\]#', $hedef) ? $hedef : ROOT_DIR . '/' . ltrim($hedef, '/\\'))
    : ROOT_DIR;
$hedefKok = rtrim(str_replace('\\', '/', $hedefKok), '/');

echo 'MySQL: ' . $user . '@' . $host . ':' . $port . '/' . $name . "\n";

// ---------------------------------------------------------------- bağlantı
$opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
$sunucuDsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';

$baslangic = time();
$kok = null;
while (true) {
    try {
        $kok = new PDO($sunucuDsn, $user, $pass, $opts);
        break;
    } catch (Throwable $e) {
        if ($bekle > 0 && (time() - $baslangic) < $bekle) {
            echo "  sunucu henüz hazır değil, bekleniyor…\n";
            sleep(2);
            continue;
        }
        fwrite(STDERR, 'MySQL bağlantısı kurulamadı: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
echo "  bağlantı kuruldu\n";

// ---------------------------------------------------------------- boş veritabanı
// Tabloları tek tek silmek yerine veritabanını düşürmek, önceki koşudan kalan
// göç/indeks artıklarının da gitmesini garanti eder.
try {
    $kok->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    $kok->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Throwable $e) {
    fwrite(STDERR, 'Veritabanı hazırlanamadı: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Kullanıcının DROP/CREATE DATABASE yetkisi olmalı.\n");
    exit(1);
}
echo '  boş veritabanı hazır (utf8mb4_unicode_ci)' . "\n";

$config = [
    'db_driver'   => 'mysql',
    'db_path'     => '',
    'db_host'     => $host,
    'db_port'     => $port,
    'db_name'     => $name,
    'db_user'     => $user,
    'db_pass'     => $pass,
    'base_url'    => '',
    'cron_key'    => 'gelistirme-cron-anahtari',
    'app_key'     => random_key(32),
    'trust_proxy' => false,
];

if ($bos) {
    // e2e kipi: şema ve config.php'yi kurulum sihirbazı üretecek.
    echo "  --bos: şema ve config.php yazılmadı (kurulum sihirbazına bırakıldı).\n";
    echo "Hazır.\n";
    exit(0);
}

// ---------------------------------------------------------------- şema + tohum
$configFile = $hedefKok . '/config.php';
$lockFile   = $hedefKok . '/install/.locked';

if (is_file($configFile) && !$force) {
    fwrite(STDERR, $configFile . " zaten var. Üstüne yazmak için --force verin.\n");
    exit(1);
}

$GLOBALS['manset_config'] = $config;

require_once INC_DIR . '/schema.php';
try {
    schema_install();
    schema_seed('Geliştirici', 'dev@manset.test', 'gelistirme123', 'Manşet Geliştirme', 'gazete');
    setting_set('use_rewrite', '1');
    setting_set('ai_test_mode', '1');
} catch (Throwable $e) {
    fwrite(STDERR, 'Şema kurulamadı: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "  şema kuruldu ve tohumlandı\n";

if (!is_dir(dirname($lockFile))) { @mkdir(dirname($lockFile), 0755, true); }
$yazildi = @file_put_contents(
    $configFile,
    "<?php\n// MySQL test yapılandırması — tests/mysql-kur.php üretti\nreturn " . var_export($config, true) . ";\n"
);
if ($yazildi === false) {
    fwrite(STDERR, 'config.php yazılamadı: ' . $configFile . "\n");
    exit(1);
}
@file_put_contents($lockFile, 'mysql test kurulumu ' . date('c') . "\n");

echo '  config.php: ' . $configFile . "\n";
echo "MySQL kurulumu hazır.\n";
echo "  Giriş : dev@manset.test / gelistirme123\n";
exit(0);
