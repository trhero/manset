<?php
/**
 * Geliştirme kurulumu — proje kökünde çalışan bir örnek oluşturur.
 * Kullanım:  php tests/dev-install.php [--force]
 * Üretilen config.php ve db/*.sqlite dosyaları .gitignore kapsamındadır.
 *
 * YALNIZ komut satırından çalışır. Bu kapı olmadan, register_argc_argv açık bir
 * sunucuda `GET /tests/dev-install.php?--force` veritabanını silip bilinen bir
 * yönetici parolasıyla yeniden kurabilirdi (denetim tur 2, B03).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Yalnız komut satırı.
";
    exit(1);
}
require_once dirname(__DIR__) . '/inc/bootstrap.php';

$force = in_array('--force', $argv, true);
$configFile = ROOT_DIR . '/config.php';
$lockFile = ROOT_DIR . '/install/.locked';

if (is_file($configFile) && !$force) {
    echo "config.php zaten var. Yeniden kurmak için: php tests/dev-install.php --force\n";
    exit(0);
}
if ($force) {
    foreach ((array)glob(DB_DIR . '/manset-*.sqlite*') as $f) { @unlink($f); }
    @unlink($configFile);
    @unlink($lockFile);
}

$config = [
    'db_driver' => 'sqlite',
    'db_path'   => DB_DIR . '/manset-dev.sqlite',
    'db_host' => '', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'base_url'  => '',
    'cron_key'  => 'gelistirme-cron-anahtari',
    'app_key'   => random_key(32),
    'trust_proxy' => false,
];
$GLOBALS['manset_config'] = $config;

require_once ROOT_DIR . '/inc/schema.php';
schema_install();
schema_seed('Geliştirici', 'dev@manset.test', 'gelistirme123', 'Manşet Geliştirme', 'gazete');
setting_set('use_rewrite', '1');
setting_set('ai_test_mode', '1');

file_put_contents($configFile, "<?php\n// Geliştirme yapılandırması — tests/dev-install.php üretti\nreturn " . var_export($config, true) . ";\n");
file_put_contents($lockFile, "gelistirme kurulumu " . date('c') . "\n");

echo "Geliştirme kurulumu hazır.\n";
echo "  Panel : http://127.0.0.1:<port>/admin/\n";
echo "  Giriş : dev@manset.test / gelistirme123\n";
echo "  Sunucu: php -S 127.0.0.1:<port> -t . tests/router.php\n";
