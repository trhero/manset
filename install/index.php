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

// ---------------------------------------------------------------- gereksinimler
/** Sunucu gereksinim listesi: [etiket, zorunlu mu, sağlanıyor mu, açıklama]. */
function install_requirements() {
    $req = [];
    $req[] = ['PHP 8.0 veya üzeri', true, PHP_VERSION_ID >= 80000, 'Kurulu sürüm: ' . PHP_VERSION];
    $req[] = ['PDO eklentisi', true, class_exists('PDO'), 'Veritabanı erişimi için zorunlu'];
    $sqlite = in_array('sqlite', PDO::getAvailableDrivers(), true);
    $mysql = in_array('mysql', PDO::getAvailableDrivers(), true);
    $req[] = ['PDO sürücüsü (SQLite veya MySQL)', true, $sqlite || $mysql,
        'SQLite: ' . ($sqlite ? 'var' : 'yok') . ' · MySQL: ' . ($mysql ? 'var' : 'yok')];
    $req[] = ['SimpleXML', true, class_exists('SimpleXMLElement'), 'RSS çekimi için gerekli'];
    $req[] = ['mbstring', false, function_exists('mb_substr') && extension_loaded('mbstring'),
        'Önerilir. Kapalıysa Manşet kendi yedek fonksiyonlarını kullanır.'];
    $req[] = ['GD', false, function_exists('imagecreatetruecolor'),
        'Önerilir. Kapalıysa görsel boyutlandırma yapılamaz, yüklenen görsel olduğu gibi kullanılır.'];
    $req[] = ['cURL veya allow_url_fopen', false, function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'RSS ve yapay zekâ çağrıları için gerekli'];
    foreach ([['db', DB_DIR], ['uploads', UPLOAD_DIR], ['kök klasör (config.php için)', ROOT_DIR]] as $d) {
        ensure_dir($d[1]);
        $req[] = [$d[0] . ' yazılabilir', true, is_writable($d[1]), $d[1]];
    }
    return $req;
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

            // SEF sondası: rewrite çalışıyor mu?
            $useRewrite = install_probe_rewrite() ? '1' : '0';
            setting_set('use_rewrite', $useRewrite);
            setting_set('theme', $theme);
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
                    'cron_key'    => $config['cron_key'],
                    'use_rewrite' => $useRewrite,
                    'email'       => $adminEmail,
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
function install_probe_rewrite() {
    $url = base_url() . '/probe-rewrite';
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
    }
    return is_string($body) && strpos($body, 'MANSET_REWRITE_OK') !== false;
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
    <table class="req">
      <?php foreach (install_requirements() as $r): ?>
        <tr class="<?= $r[2] ? 'ok' : ($r[1] ? 'bad' : 'warn') ?>">
          <td class="mark"><?= $r[2] ? '✓' : ($r[1] ? '✕' : '!') ?></td>
          <td><strong><?= esc($r[0]) ?></strong><?= $r[1] ? '' : ' <em>(önerilen)</em>' ?><br><small><?= esc($r[3]) ?></small></td>
        </tr>
      <?php endforeach; ?>
    </table>
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
code.wrap{display:block;padding:9px 11px;margin:6px 0;word-break:break-all;white-space:pre-wrap}
ol li{margin-bottom:12px}
</style>
</head><body><div class="wrap">
<div class="brand"><span>MANŞET</span><b>Kurulum</b></div>
<?= $bodyHtml ?>
<p style="text-align:center;margin-top:28px"><small>Manşet <?= esc(MANSET_VERSION) ?> — açık kaynak haber portalı</small></p>
</div></body></html><?php
}
