<?php
/**
 * Sözleşme denetimi — CONTRACTS §0: "SQL içinde yalnız tek tırnak (')".
 *
 * NEDEN KURAL: çift tırnaklı bir dizeye ileride eklenen bir değişken PHP
 * tarafından sessizce genişletilir ve tek satırlık masum bir düzenleme
 * enjeksiyona dönüşür. Tek tırnak bu sınıfı baştan kapatır. Kural biçimseldir;
 * ihlali tek başına açık değildir ama açığın önkoşuludur.
 *
 * Kullanım: php tests/kural-sql.php <kök>
 * Çıkış   : 0 temiz, 1 ihlal var (ihlaller stdout'a yazılır)
 *
 * token_get_all() kullanılır ki yalnız gerçek dize belirteçleri denetlensin;
 * yorumlarda ya da HTML parçalarında geçen "SELECT" sözcüğü ihlal sayılmaz.
 *
 * YALNIZ komut satırından çalışır ve dağıtım paketine girmez.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Yalnız komut satırı.\n";
    exit(1);
}

$root = isset($argv[1]) ? rtrim(str_replace('\\', '/', $argv[1]), '/') : '.';
if (!is_dir($root)) {
    fwrite(STDERR, 'Kök bulunamadı: ' . $root . "\n");
    exit(1);
}

/* Bilerek muaf tutulanlar: SQL SORGUSU değil, dosyaya YAZILAN metin üretirler ve
   içlerinde kaçış dizisi (\n) taşıdıkları için tek tırnağa çevrilemezler. */
$muaf = [
    'inc/backup.php',   // yedek dökümüne INSERT satırı yazar
];

$anahtar = '/\b(SELECT|INSERT\s+INTO|UPDATE\s+\w|DELETE\s+FROM|ORDER\s+BY|GROUP\s+BY)\b/i';

$ihlal = [];
$tarandi = 0;

$yigin = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($yigin as $dosya) {
    $yol = str_replace('\\', '/', $dosya->getPathname());
    $rel = ltrim(substr($yol, strlen($root)), '/');

    if (substr($rel, -4) !== '.php') { continue; }
    if (strpos($rel, '.git/') === 0 || strpos($rel, 'tests/.run/') === 0
        || strpos($rel, 'dist/') === 0 || strpos($rel, 'audit2/') === 0) { continue; }
    if (in_array($rel, $muaf, true)) { continue; }

    $tarandi++;
    $tokens = @token_get_all((string)file_get_contents($yol));
    if (!is_array($tokens)) { continue; }

    $satir = 0;
    foreach ($tokens as $t) {
        if (!is_array($t)) { continue; }
        if (isset($t[2])) { $satir = (int)$t[2]; }
        if ($t[0] !== T_CONSTANT_ENCAPSED_STRING) { continue; }
        if ($t[1] === '' || $t[1][0] !== '"') { continue; }
        if (!preg_match($anahtar, $t[1])) { continue; }
        $ihlal[] = $rel . ':' . $satir;
    }
}

if ($ihlal) {
    echo "CONTRACTS §0 ihlali — çift tırnaklı SQL dizesi (" . count($ihlal) . " adet):\n";
    foreach (array_slice($ihlal, 0, 20) as $i) { echo '  ' . $i . "\n"; }
    if (count($ihlal) > 20) { echo '  … ve ' . (count($ihlal) - 20) . " tane daha\n"; }
    exit(1);
}

echo 'temiz (' . $tarandi . " dosya tarandı)\n";
exit(0);
