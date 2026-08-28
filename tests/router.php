<?php
/**
 * PHP yerleşik sunucusu için yönlendirici — .htaccess rewrite davranışını taklit eder.
 * Yalnız testlerde kullanılır:  php -S 127.0.0.1:8917 -t tests/.run tests/router.php
 */

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
$full = $root . $path;

// Var olan dosya doğrudan sunulsun (statik dosya ya da .php betiği)
if ($path !== '/' && is_file($full)) { return false; }

// Klasör isteği: içindeki index.php
if (is_dir($full)) {
    $idx = rtrim($full, '/') . '/index.php';
    if (is_file($idx)) {
        $_SERVER['SCRIPT_NAME'] = rtrim($path, '/') . '/index.php';
        $_SERVER['SCRIPT_FILENAME'] = $idx;
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        chdir(dirname($idx));
        require $idx;
        return true;
    }
}

// Diğer her şey ön yüz yönlendiricisine
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
chdir($root);
require $root . '/index.php';
return true;
