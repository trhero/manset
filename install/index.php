<?php
/**
 * Manşet — 3 adımlı kurulum sihirbazı.
 * Adım 1: gereksinim denetimi · Adım 2: site + yönetici + veritabanı + tema · Adım 3: kur ve kilitle
 *
 * Not: Tüm çıktı ob_start() tamponunda üretilir; POST sonrası header() çalışabilsin.
 */

ob_start();
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/schema.php';
require_once dirname(__DIR__) . '/inc/view.php';

// .htaccess işaretçileri DOSYANIN BAŞINDA tanımlanmalı: kurulum işleyicisi
// (POST do=install) bu dosyanın çok yukarısında çalışır ve
// install_write_htaccess() çağırır. Tanımlar aşağıda kalırsa `define()` henüz
// yürütülmemiş olur; PHP 8'de "Undefined constant" ÖLÜMCÜL hatadır ve
// kurulum yarıda kalır.
if (!defined('INSTALL_HT_BEGIN')) { define('INSTALL_HT_BEGIN', '# BEGIN MANSET-KURULUM'); }
if (!defined('INSTALL_HT_END'))   { define('INSTALL_HT_END',   '# END MANSET-KURULUM'); }

$lockFile = __DIR__ . '/.locked';
$configFile = ROOT_DIR . '/config.php';

session_boot();

// Kurulum bittikten hemen sonra 3. adım özet sayfası gösterilebilsin diye kilit
// denetimi, taze kurulum oturumu varken atlanır.
$justInstalled = !empty($_SESSION['install_done']);

// SECURITY_AUDIT B-08: install/.locked silinse bile, çalışan bir kurulum varsa
// (config.php + kullanıcı kaydı) sihirbaz veriyi ezmemelidir.
$hasLiveInstall = false;
if (!$justInstalled && is_file($configFile)) {
    try { $hasLiveInstall = (int)qv('SELECT COUNT(*) FROM users', [], 0) > 0; }
    catch (Throwable $e) { $hasLiveInstall = false; }
}
if ($hasLiveInstall && !is_file(__DIR__ . '/.allow-reinstall')) {
    install_layout('Kurulum zaten yapılmış', '<div class="box warn"><h2>Bu sitede çalışan bir kurulum var</h2>'
        . '<p><code>config.php</code> mevcut ve veritabanında kullanıcı kayıtları bulunuyor. '
        . 'Sihirbazın verileri ezmesini önlemek için kurulum durduruldu.</p>'
        . '<p>Gerçekten sıfırdan kurmak istiyorsanız sunucuda <code>config.php</code> dosyasını silin '
        . '(veritabanınızı önce yedekleyin) ya da <code>install/.allow-reinstall</code> adlı boş bir dosya oluşturun.</p>'
        . '<p><a class="btn" href="' . esc(base_url()) . '/admin/">Yönetim paneli</a> '
        . '<a class="btn ghost" href="' . esc(base_url()) . '/">Siteye git</a></p></div>');
    exit;
}

if (is_file($lockFile) && !$justInstalled) {
    install_layout('Kurulum tamamlanmış', '<div class="box ok"><h2>Kurulum zaten tamamlanmış</h2>'
        . '<p>Yeniden kurmak için sunucudaki <code>install/.locked</code> dosyasını ve <code>config.php</code> dosyasını silin.</p>'
        . '<p><a class="btn" href="' . esc(base_url()) . '/">Siteye git</a> '
        . '<a class="btn ghost" href="' . esc(base_url()) . '/admin/">Yönetim paneli</a></p></div>');
    exit;
}

$step = max(1, min(3, (int)(isset($_GET['adim']) ? $_GET['adim'] : 1)));
$errors = [];

// ---------------------------------------------------------------- yardımcılar

/** "128M" / "2G" / "512K" gibi ini değerini bayta çevirir. -1 (sınırsız) → PHP_INT_MAX. */
function install_ini_bytes($value) {
    $v = trim((string)$value);
    if ($v === '') { return 0; }
    if ((int)$v < 0) { return PHP_INT_MAX; }                  // -1 = sınırsız
    $son = strtolower(substr($v, -1));
    $sayi = (float)$v;
    if ($son === 'g') { return (int)($sayi * 1024 * 1024 * 1024); }
    if ($son === 'm') { return (int)($sayi * 1024 * 1024); }
    if ($son === 'k') { return (int)($sayi * 1024); }
    return (int)$sayi;
}

/** Baytı okunur biçime çevirir. */
function install_bytes_human($bytes) {
    $b = (float)$bytes;
    if ($b >= PHP_INT_MAX) { return 'sınırsız'; }
    $birim = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($birim) - 1) { $b /= 1024; $i++; }
    return round($b, $b < 10 ? 1 : 0) . ' ' . $birim[$i];
}

/** Sunucu yazılımı adı (Apache / nginx / IIS / bilinmiyor). */
function install_server_software() {
    $s = strtolower((string)(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : ''));
    if ($s === '') { return 'bilinmiyor'; }
    if (strpos($s, 'apache') !== false) { return 'apache'; }
    if (strpos($s, 'nginx') !== false)  { return 'nginx'; }
    if (strpos($s, 'iis') !== false || strpos($s, 'microsoft-httpapi') !== false) { return 'iis'; }
    if (strpos($s, 'litespeed') !== false) { return 'litespeed'; }   // .htaccess'i destekler
    return 'bilinmiyor';
}

/**
 * Alt dizine kurulumda gereken RewriteBase yolu.
 * install/index.php → <kök>/install/index.php olduğundan kök, betiğin
 * iki üst klasörüdür. Kök dizinde kurulumda '/' döner.
 */
function install_rewrite_base() {
    $script = (string)(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
    $dir = str_replace('\\', '/', dirname(dirname($script)));      // .../install/index.php → ...
    // GÜVENLİK (denetim turu 3, B11-05): bu değer .htaccess'e YAZILIYOR.
    // SCRIPT_NAME normalde var olan bir betiğe çözülür, ama adında boşluk
    // ya da tırnak olan bir alt dizinde `RewriteBase /my site/` üretilir ve
    // TÜM SİTE 500 verir. Yalnız yol için anlamlı karakterler bırakılır.
    $dir = preg_replace('#[^A-Za-z0-9/_\.\-]#', '', $dir);
    $dir = str_replace('..', '', $dir);
    $dir = '/' . trim($dir, '/');
    if ($dir === '/.' || $dir === '//') { $dir = '/'; }
    if ($dir !== '/') { $dir .= '/'; }
    return $dir;
}

/**
 * Kurulum sırasında sitenin kök adresi — AYARLARA BAKMADAN.
 *
 * `base_url()` `site_url` ayarını okur, yani veritabanına bağlanır.
 * Kurulumda henüz `config.php` yoktur; bu bağlantı VARSAYILAN dosyaya
 * kurulur ve belleğe alınır, sonra şema yanlış dosyaya yazılır.
 * Bu yüzden kurulum ekranı adresi doğrudan $_SERVER'dan türetir.
 */
function install_self_url() {
    $sema = install_is_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);
    $yol  = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    $kok  = rtrim(str_replace('\\', '/', dirname(dirname($yol))), '/');
    if ($kok === '.' || $kok === '/') { $kok = ''; }
    return $sema . '://' . $host . $kok;
}

/** Şu anki istek HTTPS üzerinden mi geldi? */
function install_is_https() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') { return true; }
    if ((int)(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0) === 443) { return true; }
    $proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) : '';
    return $proto === 'https';
}

/** mod_rewrite yüklü mü? (yalnız Apache mod_php'de kesin bilinir) */
function install_mod_rewrite_loaded() {
    if (function_exists('apache_get_modules')) {
        return in_array('mod_rewrite', apache_get_modules(), true);
    }
    return null;   // bilinmiyor — SEF sondası karar verir
}

// ---------------------------------------------------------------- gereksinimler
/**
 * Sunucu gereksinim listesi.
 * Her satır: ['ad', zorunlu(bool), sağlanıyor(bool), 'not', 'çözüm'].
 * `çözüm` EKSİKSE NE YAPILACAĞINI anlatır — özellikle paylaşımlı hosting için.
 */
function install_requirements() {
    static $cache = null;
    if ($cache !== null) { return $cache; }

    $req = [];
    $ekle = function ($ad, $zorunlu, $ok, $not, $cozum = '') use (&$req) {
        $req[] = [$ad, (bool)$zorunlu, (bool)$ok, $not, $cozum];
    };

    // ---- PHP ve eklentiler
    $ekle('PHP 8.0 veya üzeri', true, PHP_VERSION_ID >= 80000, 'Kurulu sürüm: ' . PHP_VERSION,
        'Hosting panelinizde (cPanel → “Select PHP Version” / Plesk → “PHP Settings”) sürümü '
        . '8.0 veya üzerine yükseltin. Sağlayıcınız izin vermiyorsa destek talebi açın.');

    $ekle('PDO eklentisi', true, class_exists('PDO'), 'Veritabanı erişimi için zorunlu',
        'cPanel → “Select PHP Version” → Extensions listesinden pdo kutusunu işaretleyin.');

    $sqlite = class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    $mysql  = class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true);
    $ekle('PDO sürücüsü (SQLite veya MySQL)', true, $sqlite || $mysql,
        'SQLite: ' . ($sqlite ? 'var' : 'yok') . ' · MySQL: ' . ($mysql ? 'var' : 'yok'),
        'Eklenti listesinden pdo_sqlite ya da pdo_mysql açın. Yalnız biri yeterlidir; '
        . 'MySQL varsa 2. adımda MySQL seçebilirsiniz.');

    $ekle('SimpleXML', true, class_exists('SimpleXMLElement'), 'RSS çekimi için gerekli',
        'Eklenti listesinden simplexml (bazı panellerde xml) açın.');

    // fileinfo: yüklenen dosyanın gerçek MIME türünü doğrulamak için (güvenlik)
    $fileinfo = extension_loaded('fileinfo') && function_exists('finfo_open');
    $ekle('fileinfo', false, $fileinfo,
        $fileinfo
            ? 'Yüklenen dosyaların gerçek MIME türü doğrulanabiliyor.'
            : 'Kapalı. Medya yüklemesinde dosyanın gerçek türü doğrulanamaz; yalnız uzantı ve '
              . 'imza denetimine güvenilir. Güvenlik açısından açılması önerilir.',
        'cPanel → “Select PHP Version” → Extensions → fileinfo kutusunu işaretleyin. '
        . 'Kapalı kalırsa medya yükleme çalışır ama tür denetimi zayıflar; yükleme iznini '
        . 'yalnız güvendiğiniz kullanıcılara verin.');

    // openssl: güvenli belirteç (token) üretimi ve https istekleri
    $openssl = extension_loaded('openssl');
    $ekle('openssl', false, $openssl,
        $openssl
            ? 'Belirteç üretimi ve HTTPS istekleri için hazır.'
            : 'Kapalı. Belirteçler yine güvenli üretilir (random_bytes), ancak https:// adreslere '
              . 'RSS/yapay zekâ isteği atılamayabilir.',
        'Eklenti listesinden openssl açın. Açılamıyorsa yalnız http:// kaynakları kullanın; '
        . 'yapay zekâ sağlayıcıları https zorunlu kıldığı için o özellik kapalı kalır.');

    $ekle('mbstring', false, function_exists('mb_substr') && extension_loaded('mbstring'),
        'Önerilir. Kapalıysa Manşet kendi yedek fonksiyonlarını kullanır.',
        'Eklenti listesinden mbstring açın. Kapalı kalırsa Türkçe karakterlerde kırpma '
        . 'hataları görülebilir ama site çalışır.');

    $ekle('GD', false, function_exists('imagecreatetruecolor'),
        'Önerilir. Kapalıysa görsel boyutlandırma yapılamaz, yüklenen görsel olduğu gibi kullanılır.',
        'Eklenti listesinden gd açın. Kapalı kalırsa görselleri yüklemeden önce kendiniz '
        . 'küçültün (genişlik 1600 piksel önerilir).');

    $ekle('cURL veya allow_url_fopen', false,
        function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'RSS ve yapay zekâ çağrıları için gerekli',
        'Eklenti listesinden curl açın ya da php.ini içinde allow_url_fopen=On yapın. '
        . 'İkisi de yoksa RSS otomatik çekimi ve yapay zekâ kapalı kalır; site elle haber '
        . 'girişiyle tam çalışır.');

    // ---- SEF / mod_rewrite (bugün geç fark ediliyordu: adım 1'e alındı)
    $sunucu = install_server_software();
    $modYuklu = install_mod_rewrite_loaded();
    $sonda = install_probe_rewrite();
    $sefOk = $sonda || $modYuklu === true;
    if ($sunucu === 'nginx' || $sunucu === 'iis') {
        $sefNot = 'Sunucu: ' . $sunucu . '. .htaccess okunmaz; SEF yönergesini sunucu '
                . 'yapılandırmasına elle eklemeniz gerekir (kurulum sonunda kopyalanabilir '
                . 'kod bloğu verilecek).';
    } else {
        $sefNot = 'Sunucu: ' . $sunucu . ' · mod_rewrite: '
                . ($modYuklu === null ? 'bilinmiyor' : ($modYuklu ? 'yüklü' : 'yüklü değil'))
                . ' · SEF sondası: ' . ($sonda ? 'başarılı' : 'başarısız');
    }
    $ekle('SEF (temiz URL) / mod_rewrite', false, $sefOk, $sefNot,
        'SEF çalışmazsa site yine tam çalışır; adresler ?r=haber/ornek biçiminde üretilir. '
        . 'Apache’de: hosting panelinden mod_rewrite’ı açtırın ve kök .htaccess dosyasının '
        . 'sunucuya yüklendiğinden emin olun (FTP istemcinizde gizli dosyaları göstermeyi açın). '
        . 'nginx/IIS’te: kurulum sonundaki hazır yapılandırma bloğunu sunucu ayarlarınıza ekleyin.');

    // ---- PHP ini değerleri: DÜŞÜKSE UYARI, engelleme YOK
    $iniler = [
        ['upload_max_filesize', 8 * 1024 * 1024,  'Yüklenebilecek en büyük tek dosya.',
         'php.ini ya da .user.ini dosyanıza upload_max_filesize = 16M yazın; '
         . 'cPanel’de “MultiPHP INI Editor” aynı işi yapar. Değiştiremiyorsanız görselleri '
         . 'yüklemeden önce küçültün.'],
        ['post_max_size',       8 * 1024 * 1024,  'Form gönderiminin toplam boyut sınırı; '
         . 'upload_max_filesize’dan büyük olmalıdır.',
         'post_max_size = 20M yapın (upload_max_filesize’dan en az bir kademe büyük). '
         . 'Küçük kalırsa büyük görsel yüklemeleri sessizce başarısız olur.'],
        ['memory_limit',        96 * 1024 * 1024, 'Görsel boyutlandırma ve yedekleme için bellek.',
         'memory_limit = 128M yapın. Yükseltemiyorsanız yedekleme ve toplu görsel işlemleri '
         . 'küçük gruplar hâlinde yapın.'],
    ];
    foreach ($iniler as $t) {
        $ham = (string)@ini_get($t[0]);
        $bayt = install_ini_bytes($ham);
        $ekle('PHP ' . $t[0], false, $bayt >= $t[1],
            'Şu anki değer: ' . ($ham !== '' ? $ham : 'okunamadı')
            . ' · tavsiye edilen en az: ' . install_bytes_human($t[1]) . '. ' . $t[2],
            $t[3]);
    }

    // max_execution_time: 0 = sınırsız (CLI/bazı yapılandırmalar)
    $met = (int)@ini_get('max_execution_time');
    $ekle('PHP max_execution_time', false, ($met === 0 || $met >= 30),
        'Şu anki değer: ' . ($met === 0 ? 'sınırsız' : $met . ' sn') . ' · tavsiye edilen en az: 30 sn. '
        . 'RSS çekimi ve yedek alma uzun sürebilir.',
        'php.ini/.user.ini içinde max_execution_time = 60 yapın. Değiştiremiyorsanız '
        . 'zamanlanmış görevi (cron) daha sık çalıştırın; her koşuda daha az iş yapılır.');

    // ---- yazılabilirlik (mevcut denetimler korunur)
    foreach ([
        ['db', DB_DIR, 'SQLite veritabanı ve hata günlüğü burada tutulur.'],
        ['uploads', UPLOAD_DIR, 'Yüklenen görseller burada tutulur.'],
        ['kök klasör (config.php için)', ROOT_DIR, 'Kurulum config.php dosyasını buraya yazar.'],
    ] as $d) {
        ensure_dir($d[1]);
        $ekle($d[0] . ' yazılabilir', true, is_writable($d[1]), $d[1] . ' — ' . $d[2],
            'FTP istemcinizde klasöre sağ tıklayıp “Dosya izinleri” (CHMOD) 755, '
            . 'çalışmazsa 775 yapın. Paylaşımlı hostingde 777 vermeyin — çoğu sunucu bunu reddeder. '
            . 'Klasör yoksa kendiniz oluşturun.');
    }

    // ---- kök .htaccess yazılabilir mi? (RewriteBase / HTTPS kuralı için)
    if ($sunucu !== 'nginx' && $sunucu !== 'iis') {
        $ht = ROOT_DIR . '/.htaccess';
        $htOk = is_file($ht) ? is_writable($ht) : is_writable(ROOT_DIR);
        $ekle('.htaccess yazılabilir', false, $htOk,
            $htOk ? 'Kurulum, alt dizin (RewriteBase) ve HTTPS kurallarını kendisi yazabilir.'
                  : 'Kurulum .htaccess dosyasına yazamıyor; kuralları elle eklemeniz istenecek.',
            'FTP’den .htaccess dosyasının iznini 644 yapın. Yazılamazsa sorun değil: '
            . 'kurulum sonunda eklenecek satırlar ekranda gösterilir, elle yapıştırırsınız.');
    }

    // ---- disk boş alanı
    $bos = disk_free_bytes(ROOT_DIR);
    if ($bos !== false && $bos !== null) {
        $ekle('Disk boş alanı', false, $bos >= 100 * 1024 * 1024,
            'Boş alan: ' . install_bytes_human($bos) . ' · tavsiye edilen en az: 100 MB '
            . '(veritabanı, görseller ve yedekler için).',
            'Hosting panelinden kullanılmayan yedekleri ve e-posta arşivini temizleyin ya da '
            . 'paketinizi yükseltin. Disk dolarken SQLite yazma hatası verir.');
    }

    return $cache = $req;
}

/** Zorunlu gereksinimlerin tümü sağlanıyor mu? */
function install_requirements_ok() {
    foreach (install_requirements() as $r) { if ($r[1] && !$r[2]) { return false; } }
    return true;
}

// ---------------------------------------------------------------- adım 2 gönderimi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do']) && $_POST['do'] === 'install') {
    if (!csrf_verify(isset($_POST['_csrf']) ? $_POST['_csrf'] : '')) {
        $errors[] = 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
    }
    if (!install_requirements_ok()) {
        $errors[] = 'Zorunlu gereksinimler sağlanmadan kurulum yapılamaz.';
    }

    $siteTitle = trim((string)inp('site_title', ''));
    $adminName = trim((string)inp('admin_name', ''));
    $adminEmail = trim((string)inp('admin_email', ''));
    $adminPass = (string)inp('admin_pass', '');
    $adminPass2 = (string)inp('admin_pass2', '');
    $theme = (string)inp('theme', 'gazete');
    $driver = inp('db_driver', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';

    if ($siteTitle === '' || mb_strlen($siteTitle) > 80) { $errors[] = 'Site adı 1–80 karakter olmalı.'; }
    if ($adminName === '' || mb_strlen($adminName) > 80) { $errors[] = 'Yönetici adı 1–80 karakter olmalı.'; }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Geçerli bir yönetici e-postası girin.'; }
    if (mb_strlen($adminPass) < 8) { $errors[] = 'Yönetici parolası en az 8 karakter olmalı.'; }
    if ($adminPass !== $adminPass2) { $errors[] = 'Parolalar eşleşmiyor.'; }
    if (!preg_match('/^[a-z0-9_\-]{1,40}$/', $theme) || !is_file(THEMES_DIR . '/' . $theme . '/layout.php')) {
        $errors[] = 'Geçerli bir tema seçin.';
    }

    // SQLite dosya adına rastgele ek: .htaccess desteklemeyen sunucularda
    // (nginx vb.) dosyanın adresi tahmin edilerek indirilemesin.
    $config = [
        'db_driver' => $driver,
        'db_path'   => DB_DIR . '/manset-' . bin2hex(random_bytes(6)) . '.sqlite',
        'db_host'   => '', 'db_port' => 3306, 'db_name' => '', 'db_user' => '', 'db_pass' => '',
        'base_url'  => '',
        'cron_key'  => random_key(24),
        'app_key'   => random_key(32),
        'trust_proxy' => false,
    ];
    if ($driver === 'mysql') {
        $config['db_host'] = trim((string)inp('db_host', 'localhost'));
        $config['db_port'] = max(1, min(65535, (int)inp('db_port', 3306)));
        $config['db_name'] = trim((string)inp('db_name', ''));
        $config['db_user'] = trim((string)inp('db_user', ''));
        $config['db_pass'] = (string)inp('db_pass', '');
        if ($config['db_host'] === '' || $config['db_name'] === '' || $config['db_user'] === '') {
            $errors[] = 'MySQL için sunucu, veritabanı adı ve kullanıcı zorunludur.';
        }
    }

    if (!$errors) {
        // Bağlantıyı yapılandırmayı yazmadan önce dene
        $GLOBALS['manset_config'] = $config;
        // Yapılandırma DEĞİŞTİ: daha önce (ör. bir gereksinim denetimi yüzünden)
        // varsayılan yola bağlanılmış olabilir ve db() onu belleğe almış olur.
        // Tazelemezsek şema YANLIŞ dosyaya kurulur — 1.1'de tam olarak bu oldu.
        if (function_exists('db_reset')) { db_reset(); }
        try {
            db();
        } catch (Throwable $e) {
            $errors[] = 'Veritabanına bağlanılamadı: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            schema_install();
            schema_seed($adminName, $adminEmail, $adminPass, $siteTitle, $theme);

            // Alt dizin (RewriteBase) ve HTTPS kuralı .htaccess'e yazılır.
            // .htaccess yazılamazsa kurulum DURMAZ; blok 3. adımda elle eklenmek
            // üzere gösterilir.
            $rewriteBase = install_rewrite_base();
            $forceHttps = (string)inp('force_https', '') === '1';
            $htRes = ['ok' => false, 'error' => '', 'block' => install_htaccess_block($rewriteBase, $forceHttps)];
            if (install_server_software() !== 'nginx' && install_server_software() !== 'iis') {
                $htRes = install_write_htaccess($rewriteBase, $forceHttps);
            }

            // SEF sondası: rewrite çalışıyor mu? (.htaccess yazıldıktan SONRA ölçülür)
            $useRewrite = install_probe_rewrite() ? '1' : '0';
            setting_set('use_rewrite', $useRewrite);
            setting_set('theme', $theme);
            // Sürüm damgası: taze kurulum "yükseltme bekliyor" gibi görünmesin.
            // Damga olmadan ilk panel ziyareti upgrade_run() tetikler ve
            // yeni kurulan boş veritabanının yedeğini almaya kalkar.
            setting_set('installed_version', MANSET_VERSION);
            // Kanonik adres: Host başlığı zehirlenmesine karşı referans (SECURITY_AUDIT B-06)
            setting_set('site_url', base_url());

            // config.php yaz
            $php = "<?php\n"
                 . "// Manşet yapılandırması — kurulum sihirbazı tarafından oluşturuldu (" . date('Y-m-d H:i') . ")\n"
                 . "// Bu dosyayı sürüm kontrolüne eklemeyin.\n"
                 . "return " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $php) === false) {
                $errors[] = 'config.php yazılamadı. Kök klasörün yazma izinlerini denetleyin.';
            } else {
                @chmod($configFile, 0640);
                @file_put_contents($lockFile, "kurulum tamamlandi " . date('c') . "\n");
                $_SESSION['install_done'] = [
                    'cron_key'     => $config['cron_key'],
                    'use_rewrite'  => $useRewrite,
                    'email'        => $adminEmail,
                    'rewrite_base' => $rewriteBase,
                    'force_https'  => $forceHttps,
                    'server'       => install_server_software(),
                    'ht_ok'        => !empty($htRes['ok']),
                    'ht_error'     => (string)arr($htRes, 'error', ''),
                    'ht_block'     => (string)arr($htRes, 'block', ''),
                ];
                header('Location: ?adim=3', true, 302);
                ob_end_flush();
                exit;
            }
        } catch (Throwable $e) {
            $errors[] = 'Kurulum sırasında hata: ' . $e->getMessage();
            log_error('Kurulum hatası: ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
        }
    }
    $step = 2;
}

/** index.php'nin SEF sondasına istek atarak rewrite'ın çalıştığını sınar. */
/**
 * SEF (temiz URL) çalışıyor mu? Sunucuya KENDİ ÜZERİNDEN bir istek atar.
 *
 * GÜVENLİK (denetim turu 3, B11-02): bağlantı DAİMA 127.0.0.1'e kurulur;
 * sanal sunucu yönlendirmesi için `Host` başlığı ayrıca gönderilir.
 * Eskiden adres doğrudan `HTTP_HOST`'tan kuruluyordu — yani İSTEMCİNİN
 * yazdığı değerden. `Host: 127.0.0.1:6379` gibi bir başlıkla sunucu, kimlik
 * doğrulaması olmayan bir SSRF aracına dönüşüyordu (kurulum kilidi henüz
 * yokken, yani FTP yüklemesiyle kurulum arasındaki pencerede).
 * Port da istemciden değil, sunucunun gerçekten dinlediği SERVER_PORT'tan alınır.
 */
function install_probe_rewrite() {
    $port = (int)(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 80);
    if ($port < 1 || $port > 65535) { $port = 80; }
    $https = install_is_https();
    $sema  = $https ? 'https' : 'http';

    // Host başlığı yalnız sanal sunucu seçimi için taşınır; BAĞLANTI hedefi değil.
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-:\[\]]/', '', $host);
    if ($host === '') { $host = 'localhost'; }

    $yol = rtrim(install_rewrite_base(), '/') . '/probe-rewrite';
    $url = $sema . '://127.0.0.1:' . $port . $yol;

    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 6, 'ignore_errors' => true,
            'header' => 'Host: ' . $host . "\r\n",
        ], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    return is_string($body) && strpos($body, 'MANSET_REWRITE_OK') !== false;
}

// ---------------------------------------------------------------- .htaccess / SEF yönergeleri


/**
 * Kurulumun yönettiği .htaccess bloğu (RewriteBase + isteğe bağlı HTTPS yönlendirmesi).
 * Her direktif <IfModule> ile sarılıdır: modül yoksa sunucu 500 vermez, blok sessizce atlanır.
 */
function install_htaccess_block($base, $forceHttps) {
    $satir = [];
    $satir[] = INSTALL_HT_BEGIN . ' — kurulum sihirbazı yazdı, elle düzenlemeyin';
    $satir[] = '<IfModule mod_rewrite.c>';
    $satir[] = '  RewriteEngine On';
    if ((string)$base !== '/' && (string)$base !== '') {
        $satir[] = '  # Alt dizine kurulum: kök yol otomatik hesaplandı';
        $satir[] = '  RewriteBase ' . $base;
    }
    if ($forceHttps) {
        $satir[] = '  # HTTPS zorlaması (kurulumda seçildi)';
        // GÜVENLİK/DAYANIKLILIK (denetim turu 3, B11-04):
        //  · SERVER_PORT 443 denetimi ŞART. Bazı ters vekiller X-Forwarded-Proto
        //    göndermez; yalnız HTTPS+XFP'ye bakan kural KALICI 301 DÖNGÜSÜ üretir
        //    ve site yalnız FTP ile kurtarılabilir hâle gelir.
        //  · Yönlendirme hedefi %{HTTP_HOST} DEĞİL %{SERVER_NAME}: Host istemci
        //    girdisidir, catch-all sanal sunucuda açık yönlendirme olur.
        $satir[] = '  RewriteCond %{HTTPS} !=on';
        $satir[] = '  RewriteCond %{SERVER_PORT} !=443';
        $satir[] = '  RewriteCond %{HTTP:X-Forwarded-Proto} !=https';
        $satir[] = '  RewriteCond %{HTTP:X-Forwarded-Ssl} !=on';
        $satir[] = '  RewriteRule ^(.*)$ https://%{SERVER_NAME}%{REQUEST_URI} [R=301,L]';
    }
    $satir[] = '</IfModule>';
    $satir[] = INSTALL_HT_END;
    return implode("\n", $satir) . "\n\n";
}

/**
 * Yönetilen bloğu kök .htaccess içine yazar/günceller.
 * Var olan içerik KORUNUR: yalnız iki işaretçi arasındaki bölüm değiştirilir,
 * işaretçi yoksa blok dosyanın başına eklenir.
 *
 * @return array ['ok'=>bool, 'error'=>string, 'block'=>string]
 */
function install_write_htaccess($base, $forceHttps) {
    $blok = install_htaccess_block($base, $forceHttps);
    $yol = ROOT_DIR . '/.htaccess';
    $mevcut = is_file($yol) ? (string)@file_get_contents($yol) : '';

    if ($mevcut !== '' && strpos($mevcut, INSTALL_HT_BEGIN) !== false
        && strpos($mevcut, INSTALL_HT_END) !== false) {
        $bas = strpos($mevcut, INSTALL_HT_BEGIN);
        $son = strpos($mevcut, INSTALL_HT_END) + strlen(INSTALL_HT_END);
        // Satır sonundaki artıkları da al
        while ($son < strlen($mevcut) && ($mevcut[$son] === "\r" || $mevcut[$son] === "\n")) { $son++; }
        $yeni = substr($mevcut, 0, $bas) . $blok . substr($mevcut, $son);
    } else {
        $yeni = $blok . "\n" . $mevcut;
    }

    if (@file_put_contents($yol, $yeni) === false) {
        return ['ok' => false, 'block' => $blok,
                'error' => '.htaccess yazılamadı; kuralları elle eklemeniz gerekiyor.'];
    }
    return ['ok' => true, 'error' => '', 'block' => $blok];
}

/** nginx için SEF yönergesi (kopyalanabilir). */
function install_nginx_conf($base, $forceHttps) {
    $kok = rtrim((string)$base, '/');
    $konum = $kok === '' ? '/' : $kok . '/';
    $out = "# Manşet — nginx SEF yapılandırması\n"
         . "# server { … } bloğunuzun içine ekleyin, ardından: nginx -t && systemctl reload nginx\n\n";
    if ($forceHttps) {
        $out .= "# HTTPS zorlaması (ayrı bir 80 portu server bloğunda)\n"
              . "# server { listen 80; server_name ornek.com; return 301 https://\$host\$request_uri; }\n\n";
    }
    $out .= "location " . $konum . " {\n"
          . "    try_files \$uri \$uri/ " . $konum . "index.php?\$query_string;\n"
          . "}\n\n"
          . "# Veritabanı ve çekirdek kod dışarıya kapalı\n"
          . "location ~ ^" . $kok . "/(db|inc|install)/ { deny all; return 404; }\n"
          . "location ~ ^" . $kok . "/.*\\.(sqlite|sqlite-wal|sqlite-shm|log|md|sh)$ { deny all; return 404; }\n\n"
          . "# Yüklenen dosyalarda PHP ÇALIŞTIRILMAZ (uploads/.htaccess'in nginx karşılığı)\n"
          . "location ~ ^" . $kok . "/uploads/.*\\.(php|phtml|phar|cgi|pl|py)$ { deny all; return 404; }\n\n"
          . "# Statik dosyalar için uzun önbellek\n"
          . "location ~ ^" . $kok . "/(assets|uploads)/ {\n"
          . "    expires 1y;\n"
          . "    add_header Cache-Control \"public, immutable\";\n"
          . "}\n";
    return $out;
}

/** IIS (web.config) için SEF yönergesi (kopyalanabilir). */
function install_iis_conf($base, $forceHttps) {
    $https = $forceHttps
        ? "        <rule name=\"Manset HTTPS\" stopProcessing=\"true\">\n"
        . "          <match url=\"(.*)\" />\n"
        . "          <conditions>\n"
        . "            <add input=\"{HTTPS}\" pattern=\"^OFF$\" />\n"
        . "          </conditions>\n"
        . "          <action type=\"Redirect\" url=\"https://{HTTP_HOST}{REQUEST_URI}\" redirectType=\"Permanent\" />\n"
        . "        </rule>\n"
        : '';
    return "<!-- Manşet — IIS web.config (sitenin kök klasörüne web.config adıyla kaydedin) -->\n"
        . "<!-- URL Rewrite modülü kurulu olmalıdır: iis.net/downloads/microsoft/url-rewrite -->\n"
        . "<configuration>\n"
        . "  <system.webServer>\n"
        . "    <rewrite>\n"
        . "      <rules>\n"
        . $https
        . "        <rule name=\"Manset SEF\" stopProcessing=\"true\">\n"
        . "          <match url=\"^(.*)$\" />\n"
        . "          <conditions logicalGrouping=\"MatchAll\">\n"
        . "            <add input=\"{REQUEST_FILENAME}\" matchType=\"IsFile\" negate=\"true\" />\n"
        . "            <add input=\"{REQUEST_FILENAME}\" matchType=\"IsDirectory\" negate=\"true\" />\n"
        . "          </conditions>\n"
        . "          <action type=\"Rewrite\" url=\"index.php\" />\n"
        . "        </rule>\n"
        . "      </rules>\n"
        . "    </rewrite>\n"
        . "    <security>\n"
        . "      <requestFiltering>\n"
        . "        <hiddenSegments>\n"
        . "          <add segment=\"db\" />\n"
        . "          <add segment=\"inc\" />\n"
        . "          <add segment=\"install\" />\n"
        . "        </hiddenSegments>\n"
        . "      </requestFiltering>\n"
        . "    </security>\n"
        . "  </system.webServer>\n"
        . "  <!-- uploads klasöründe PHP çalışmasın -->\n"
        . "  <location path=\"uploads\">\n"
        . "    <system.webServer>\n"
        . "      <handlers accessPolicy=\"Read\" />\n"
        . "    </system.webServer>\n"
        . "  </location>\n"
        . "</configuration>\n";
}

// ---------------------------------------------------------------- görünüm
if ($step === 3 && !empty($_SESSION['install_done'])) {
    $info = $_SESSION['install_done'];
    unset($_SESSION['install_done']);
    $cronUrl = base_url() . '/cron.php?key=' . rawurlencode($info['cron_key']);
    $html = '<div class="box ok"><h2>Kurulum tamamlandı</h2>'
        . '<p>Manşet kullanıma hazır. <strong>install/</strong> klasörünü sunucudan silmeniz önerilir '
        . '(kurulum <code>install/.locked</code> dosyasıyla zaten kilitlendi).</p></div>'
        . '<div class="box"><h3>Sırada ne var?</h3><ol>'
        . '<li><strong>Yönetim paneline girin:</strong> <a href="' . esc(base_url()) . '/admin/">' . esc(base_url()) . '/admin/</a> — kullanıcı: ' . esc($info['email']) . '</li>'
        . '<li><strong>Zamanlanmış görevi kurun</strong> (RSS çekimi ve yapay zekâ kuyruğu için, 10 dakikada bir):<br>'
        . '<code class="wrap">*/10 * * * * curl -s "' . esc($cronUrl) . '"</code><br>'
        . '<small>Bu anahtar <code>config.php</code> içindeki <code>cron_key</code> değeridir; kaybederseniz oradan okuyabilirsiniz.</small></li>'
        . '<li><strong>Yapay zekâ sağlayıcısı</strong> tanımlayın: Panel → Yapay Zekâ. Anahtar girilmezse AI özellikleri kapalı kalır, site tam çalışır.</li>'
        . '<li><strong>Künye bilgilerini doldurun</strong>: Panel → Ayarlar → Künye. Yürürlükteki mevzuata uygunluğu yayıncı teyit etmelidir.</li>'
        . '</ol></div>'
        . '<div class="box ' . ($info['use_rewrite'] === '1' ? 'ok' : 'warn') . '"><h3>SEF (temiz) URL durumu</h3>'
        . ($info['use_rewrite'] === '1'
            ? '<p>URL yeniden yazma <strong>çalışıyor</strong>. Adresler <code>/haber/ornek-1</code> biçiminde üretilecek.</p>'
            : '<p>URL yeniden yazma <strong>algılanamadı</strong>. Adresler <code>?r=haber/ornek-1</code> biçiminde üretilecek. '
              . 'Hosting sağlayıcınızda <code>mod_rewrite</code> etkinse Panel → Ayarlar ekranından yeniden deneyebilirsiniz.</p>')
        . '</div>';

    // ---- .htaccess durumu (Apache/LiteSpeed)
    $sunucu = (string)arr($info, 'server', 'bilinmiyor');
    $temel  = (string)arr($info, 'rewrite_base', '/');
    $https  = !empty($info['force_https']);

    if ($sunucu !== 'nginx' && $sunucu !== 'iis') {
        if (!empty($info['ht_ok'])) {
            $html .= '<div class="box ok"><h3>.htaccess güncellendi</h3>'
                . '<p>Kök <code>.htaccess</code> dosyasına aşağıdaki blok yazıldı'
                . ($temel !== '/' ? ' (alt dizin: <code>' . esc($temel) . '</code>)' : '')
                . ($https ? ' · HTTPS yönlendirmesi <strong>açık</strong>' : '')
                . '. Mevcut kurallarınız korundu.</p>'
                . '<code class="wrap">' . esc((string)arr($info, 'ht_block', '')) . '</code></div>';
        } else {
            $html .= '<div class="box warn"><h3>.htaccess elle güncellenmeli</h3>'
                . '<p>' . esc((string)arr($info, 'ht_error', '.htaccess yazılamadı.')) . ' '
                . 'Aşağıdaki bloğu kök <code>.htaccess</code> dosyanızın <strong>en üstüne</strong> '
                . 'yapıştırın (FTP istemcinizde gizli dosyaları göstermeyi açmanız gerekebilir):</p>'
                . '<code class="wrap">' . esc((string)arr($info, 'ht_block', '')) . '</code></div>';
        }
    }

    // ---- Apache dışı sunucular: hazır yapılandırma
    $vurgu = ($sunucu === 'nginx' || $sunucu === 'iis') ? ' warn' : '';
    $html .= '<div class="box' . $vurgu . '"><h3>Apache dışı sunucular için SEF yapılandırması</h3>'
        . '<p>' . ($sunucu === 'nginx' || $sunucu === 'iis'
            ? '<strong>Sunucunuz ' . esc($sunucu) . ' olarak algılandı; <code>.htaccess</code> okunmaz.</strong> '
              . 'Aşağıdaki bloğu sunucu yapılandırmanıza ekleyin, yoksa adresler yalnız '
              . '<code>?r=</code> biçiminde çalışır.'
            : 'Siteyi ileride nginx ya da IIS üzerine taşırsanız aşağıdaki karşılıkları kullanın.')
        . '</p>'
        . '<h4>nginx</h4><code class="wrap">' . esc(install_nginx_conf($temel, $https)) . '</code>'
        . '<h4>IIS (web.config)</h4><code class="wrap">' . esc(install_iis_conf($temel, $https)) . '</code>'
        . '<p><small>Her iki blok da <code>db/</code>, <code>inc/</code> ve <code>install/</code> '
        . 'erişimini kapatır ve <code>uploads/</code> altında PHP çalıştırılmasını engeller — '
        . 'Apache’de bu işi <code>.htaccess</code> dosyaları yapar.</small></p>'
        . '</div>'
        . '<p><a class="btn" href="' . esc(base_url()) . '/admin/">Yönetim paneline git</a> '
        . '<a class="btn ghost" href="' . esc(base_url()) . '/">Siteyi görüntüle</a></p>';
    install_layout('Kurulum tamamlandı', $html);
    exit;
}

if ($step === 3) { header('Location: ?adim=1', true, 302); ob_end_flush(); exit; }

ob_start();
?>
<div class="steps">
  <span class="<?= $step === 1 ? 'on' : '' ?>">1 · Gereksinimler</span>
  <span class="<?= $step === 2 ? 'on' : '' ?>">2 · Bilgiler</span>
  <span>3 · Kurulum</span>
</div>

<?php if ($errors): ?>
  <div class="box err"><h3>Kurulum tamamlanamadı</h3><ul>
    <?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<?php if ($step === 1): ?>
  <div class="box">
    <h2>Sunucu gereksinimleri</h2>
    <p><small>✕ zorunlu ve eksik · ! önerilen ama eksik (kurulumu engellemez) · ✓ tamam.
      Eksik maddelerin altında <strong>ne yapmanız gerektiği</strong> yazılıdır.</small></p>
    <table class="req">
      <?php foreach (install_requirements() as $r): ?>
        <tr class="<?= $r[2] ? 'ok' : ($r[1] ? 'bad' : 'warn') ?>">
          <td class="mark"><?= $r[2] ? '✓' : ($r[1] ? '✕' : '!') ?></td>
          <td>
            <strong><?= esc($r[0]) ?></strong><?= $r[1] ? '' : ' <em>(önerilen)</em>' ?><br>
            <small><?= esc($r[3]) ?></small>
            <?php if (!$r[2] && isset($r[4]) && $r[4] !== ''): ?>
              <div class="fix"><strong>Ne yapmalı?</strong> <?= esc($r[4]) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>

    <div class="box warn" style="margin:16px 0 0">
      <h3>Kurulum yolu</h3>
      <p>Manşet <code><?= esc(install_rewrite_base()) ?></code> yolunda kurulacak.
      <?php if (install_rewrite_base() !== '/'): ?>
        Alt dizine kurulum algılandı; SEF kuralı için gereken
        <code>RewriteBase <?= esc(install_rewrite_base()) ?></code> satırı kurulum sırasında
        <code>.htaccess</code> dosyasına otomatik yazılacaktır.
      <?php else: ?>
        Kök dizine kurulum algılandı; ek bir <code>RewriteBase</code> ayarı gerekmez.
      <?php endif; ?>
      </p>
      <p><small>Sunucu: <?= esc(install_server_software()) ?> ·
        Bağlantı: <?= install_is_https() ? 'HTTPS' : 'HTTP' ?></small></p>
    </div>

    <?php if (install_requirements_ok()): ?>
      <p><a class="btn" href="?adim=2">Devam et →</a></p>
    <?php else: ?>
      <p class="err-text">Zorunlu (✕ işaretli) maddeler giderilmeden kuruluma devam edilemez.</p>
      <p><a class="btn ghost" href="?adim=1">Yeniden denetle</a></p>
    <?php endif; ?>
  </div>
<?php else: ?>
  <form method="post" action="?adim=2" class="box" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="install">

    <h2>Site bilgileri</h2>
    <label>Site adı
      <input type="text" name="site_title" required maxlength="80" value="<?= esc(inp('site_title', 'Manşet')) ?>">
    </label>

    <h2>Yönetici hesabı</h2>
    <label>Ad soyad
      <input type="text" name="admin_name" required maxlength="80" value="<?= esc(inp('admin_name', '')) ?>">
    </label>
    <label>E-posta
      <input type="email" name="admin_email" required maxlength="190" value="<?= esc(inp('admin_email', '')) ?>">
    </label>
    <div class="row">
      <label>Parola (en az 8 karakter)
        <input type="password" name="admin_pass" required minlength="8" autocomplete="new-password">
      </label>
      <label>Parola (tekrar)
        <input type="password" name="admin_pass2" required minlength="8" autocomplete="new-password">
      </label>
    </div>

    <h2>Veritabanı</h2>
    <label class="inline"><input type="radio" name="db_driver" value="sqlite" checked onclick="document.getElementById('mysqlBox').hidden=true"> SQLite <small>(önerilen — kurulum gerektirmez)</small></label>
    <label class="inline"><input type="radio" name="db_driver" value="mysql" <?= inp('db_driver') === 'mysql' ? 'checked' : '' ?> onclick="document.getElementById('mysqlBox').hidden=false"> MySQL / MariaDB</label>
    <div id="mysqlBox" <?= inp('db_driver') === 'mysql' ? '' : 'hidden' ?>>
      <div class="row">
        <label>Sunucu <input type="text" name="db_host" value="<?= esc(inp('db_host', 'localhost')) ?>"></label>
        <label>Port <input type="number" name="db_port" value="<?= esc(inp('db_port', '3306')) ?>"></label>
      </div>
      <label>Veritabanı adı <input type="text" name="db_name" value="<?= esc(inp('db_name', '')) ?>"></label>
      <div class="row">
        <label>Kullanıcı <input type="text" name="db_user" value="<?= esc(inp('db_user', '')) ?>"></label>
        <label>Parola <input type="password" name="db_pass" autocomplete="new-password"></label>
      </div>
      <p><small>Veritabanının önceden oluşturulmuş olması gerekir. Karakter seti utf8mb4 önerilir.</small></p>
    </div>

    <h2>Tema</h2>
    <div class="themes">
      <?php
      $themes = theme_list();
      if (!$themes) { $themes = ['gazete' => ['name' => 'Gazete', 'description' => 'Klasik haber düzeni', 'preview' => '#c0392b']]; }
      $i = 0;
      foreach ($themes as $key => $meta): $i++; ?>
        <label class="theme-card">
          <input type="radio" name="theme" value="<?= esc($key) ?>" <?= $i === 1 ? 'checked' : '' ?>>
          <span class="swatch" style="background:<?= esc(preg_match('/^#[0-9a-f]{3,6}$/i', (string)$meta['preview']) ? $meta['preview'] : '#c0392b') ?>"></span>
          <strong><?= esc($meta['name']) ?></strong>
          <small><?= esc(isset($meta['description']) ? $meta['description'] : '') ?></small>
        </label>
      <?php endforeach; ?>
    </div>
    <p><small>Temayı kurulumdan sonra Panel → Tema ekranından değiştirebilirsiniz.</small></p>

    <h2>Adres ve güvenlik</h2>
    <p><small>Kurulum yolu: <code><?= esc(install_rewrite_base()) ?></code>
      <?php if (install_rewrite_base() !== '/'): ?>
        — alt dizin algılandı, <code>RewriteBase</code> otomatik yazılacak.
      <?php endif; ?>
    </small></p>
    <label class="inline">
      <input type="checkbox" name="force_https" value="1"
             <?= (inp('force_https', install_is_https() ? '1' : '') === '1') ? 'checked' : '' ?>>
      HTTPS’e yönlendir
      <small>(<?= install_is_https()
            ? 'şu an HTTPS kullanıyorsunuz — işaretli gelmesi önerilir'
            : 'yalnız geçerli bir SSL sertifikanız varsa işaretleyin' ?>)</small>
    </label>
    <p><small>İşaretlenirse kök <code>.htaccess</code> dosyasına kalıcı (301) HTTPS yönlendirme
      kuralı yazılır. Kural <code>&lt;IfModule mod_rewrite.c&gt;</code> içine alınır; modül yoksa
      sessizce yok sayılır. Sertifikanız yokken işaretlerseniz site açılmaz — bu durumda
      <code>.htaccess</code> içindeki <code>MANSET-KURULUM</code> bloğunu silmeniz yeterlidir.</small></p>

    <p><button class="btn" type="submit">Kurulumu tamamla</button>
       <a class="btn ghost" href="?adim=1">← Geri</a></p>
  </form>
<?php endif;

install_layout('Manşet kurulumu', (string)ob_get_clean());

/** Kurulum sayfalarının ortak çerçevesi. */
function install_layout($title, $bodyHtml) {
    ?><!doctype html>
<html lang="tr"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= esc($title) ?> — Manşet</title>
<style>
*{box-sizing:border-box}
body{font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;margin:0;background:#f4f5f7;color:#1c1f23}
.wrap{max-width:760px;margin:0 auto;padding:32px 20px 64px}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.brand b{font-size:28px;letter-spacing:-.5px}
.brand span{background:#c0392b;color:#fff;padding:4px 10px;border-radius:4px;font-weight:700;font-size:13px;letter-spacing:.08em}
.steps{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
.steps span{flex:1;min-width:140px;padding:10px 12px;background:#fff;border:1px solid #dfe3e8;border-radius:8px;font-size:14px;color:#6b7280}
.steps span.on{background:#1c1f23;color:#fff;border-color:#1c1f23}
.box{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:22px;margin-bottom:18px}
.box.ok{border-color:#b7e0c0;background:#f2fbf5}
.box.err{border-color:#f0b6b6;background:#fdf3f3}
.box.warn{border-color:#f2ddb0;background:#fffaef}
h2{margin:0 0 14px;font-size:19px}
h3{margin:0 0 10px;font-size:17px}
label{display:block;margin:0 0 14px;font-size:14px;font-weight:600}
label.inline{display:inline-flex;align-items:center;gap:8px;font-weight:500;margin-right:18px}
label small{font-weight:400;color:#6b7280}
input[type=text],input[type=email],input[type=password],input[type=number]{width:100%;margin-top:6px;padding:10px 12px;border:1px solid #cdd3da;border-radius:7px;font:inherit;font-weight:400}
input:focus{outline:2px solid #1c1f23;outline-offset:1px}
.row{display:flex;gap:14px;flex-wrap:wrap}
.row>label{flex:1;min-width:180px}
.btn{display:inline-block;background:#c0392b;color:#fff;border:0;padding:11px 20px;border-radius:7px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:#1c1f23;border:1px solid #cdd3da}
table.req{width:100%;border-collapse:collapse}
table.req td{padding:9px 8px;border-bottom:1px solid #eef1f4;vertical-align:top}
table.req td.mark{width:30px;font-weight:700;font-size:18px;text-align:center}
table.req tr.ok .mark{color:#1e8e3e}
table.req tr.bad .mark{color:#c0392b}
table.req tr.warn .mark{color:#b8860b}
small{color:#6b7280}
.err-text{color:#c0392b;font-weight:600}
.themes{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
.theme-card{display:block;border:1px solid #cdd3da;border-radius:9px;padding:12px;cursor:pointer;font-weight:500;margin:0}
.theme-card:has(input:checked){border-color:#1c1f23;box-shadow:0 0 0 2px #1c1f23 inset}
.theme-card .swatch{display:block;height:34px;border-radius:5px;margin:8px 0}
.theme-card strong{display:block;font-size:15px}
code{background:#eef1f4;padding:2px 6px;border-radius:4px;font-size:13px}
code.wrap{display:block;padding:9px 11px;margin:6px 0;word-break:break-all;white-space:pre-wrap;
  max-height:340px;overflow:auto;line-height:1.5}
ol li{margin-bottom:12px}
h4{margin:16px 0 4px;font-size:15px}
.fix{margin-top:6px;padding:8px 10px;background:#fffaef;border-left:3px solid #d9a441;
  border-radius:0 5px 5px 0;font-size:13px;color:#4a4133}
table.req tr.ok .fix{display:none}
</style>
</head><body><div class="wrap">
<div class="brand"><span>MANŞET</span><b>Kurulum</b></div>
<?= $bodyHtml ?>
<p style="text-align:center;margin-top:28px"><small>Manşet <?= esc(MANSET_VERSION) ?> — açık kaynak haber portalı</small></p>
</div></body></html><?php
}
