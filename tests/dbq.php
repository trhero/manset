<?php
/**
 * e2e yardımcı betiği — test veritabanına doğrudan tek satırlık sorgu çalıştırır.
 *
 * Kullanım:
 *   php tests/dbq.php <kurulum_kökü> "SELECT ..."      → ilk sütunun ilk değerini basar
 *   php tests/dbq.php <kurulum_kökü> "INSERT ..."      → etkilenen satır sayısını basar
 *   php tests/dbq.php <kurulum_kökü> --lastid "INSERT ..." → eklenen kaydın id'sini basar
 *
 * YALNIZ komut satırından çalışır ve dağıtım paketine dâhil edilmez.
 * (Kabuk içinden php -r ile SQL geçirmek Windows'ta tırnak kaçışları yüzünden kırılgan;
 *  bu betik testlerin o kırılganlıktan kurtulması için var.)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Yalnız komut satırı.\n";
    exit(1);
}

$root = isset($argv[1]) ? rtrim(str_replace('\\', '/', $argv[1]), '/') : '';
if ($root === '' || !is_file($root . '/inc/bootstrap.php')) {
    fwrite(STDERR, "Kurulum kökü bulunamadı: " . $root . "\n");
    exit(1);
}
require_once $root . '/inc/bootstrap.php';

$wantLastId = false;
$args = array_slice($argv, 2);
if (isset($args[0]) && $args[0] === '--lastid') { $wantLastId = true; array_shift($args); }
$sql = isset($args[0]) ? (string)$args[0] : '';
if (trim($sql) === '') { fwrite(STDERR, "SQL verilmedi.\n"); exit(1); }

try {
    if (stripos(ltrim($sql), 'select') === 0) {
        echo (string)qv($sql, [], '');
    } else {
        $st = q($sql);
        echo $wantLastId ? (string)last_id() : (string)$st->rowCount();
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'SQL hatası: ' . $e->getMessage() . "\n");
    exit(1);
}
