<?php
/**
 * Manşet — yönetim paneli tek giriş noktası.
 *
 * Sorumluluk: oturum, giriş/çıkış, rol denetimi, menü, ortak çatı ve sayfa yükleme.
 * Sayfa içerikleri admin/pages/<slug>.php dosyalarındadır (bkz. CONTRACTS.md §8).
 *
 * Tüm çıktı ob_start() tamponunda üretilir; sayfa dosyaları POST işledikten sonra
 * header('Location: …') çağırabilsin.
 */

ob_start();

require_once dirname(__DIR__) . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    header('Location: ' . base_url() . '/install/', true, 302);
    ob_end_flush();
    exit;
}

require_once dirname(__DIR__) . '/inc/schema.php';
require_once dirname(__DIR__) . '/inc/sanitize.php';
require_once dirname(__DIR__) . '/inc/view.php';
require_once dirname(__DIR__) . '/inc/ai.php';
foreach (['media', 'rss', 'ai_jobs', 'seo', 'cache', 'backup', 'widgets', 'kunye', 'members'] as $mod) {
    $f = dirname(__DIR__) . '/inc/' . $mod . '.php';
    if (is_file($f)) { require_once $f; }
}

session_boot();

// ============================================================ menü tanımı

/**
 * Panel menüsü. Sayfa dosyası yoksa ya da izin yoksa öğe gizlenir.
 * @return array slug => ['label'=>…, 'perm'=>…, 'group'=>…, 'icon'=>…]
 */
function admin_nav() {
    return [
        'dashboard'   => ['label' => 'Panel',              'perm' => 'admin.access',       'group' => 'İçerik',    'icon' => '▦'],
        'posts'       => ['label' => 'Haberler',           'perm' => 'posts.view',         'group' => 'İçerik',    'icon' => '✎'],
        'categories'  => ['label' => 'Kategoriler',        'perm' => 'categories.manage',  'group' => 'İçerik',    'icon' => '☰'],
        'media'       => ['label' => 'Medya',              'perm' => 'media.manage',       'group' => 'İçerik',    'icon' => '▣'],
        'pages'       => ['label' => 'Sabit Sayfalar',     'perm' => 'pages.manage',       'group' => 'İçerik',    'icon' => '❐'],
        'comments'    => ['label' => 'Yorumlar',           'perm' => 'comments.moderate',  'group' => 'İçerik',    'icon' => '❝'],

        'headlines'   => ['label' => 'Manşet Yönetimi',    'perm' => 'headlines.manage',   'group' => 'Vitrin',    'icon' => '★'],
        'ads'         => ['label' => 'Reklamlar',          'perm' => 'ads.schedule',         'group' => 'Vitrin',    'icon' => '▤'],

        'rss'         => ['label' => 'RSS Kaynakları',     'perm' => 'rss.manage',         'group' => 'Otomasyon', 'icon' => '⟳'],
        'ai'          => ['label' => 'Yapay Zekâ',         'perm' => 'ai.use',             'group' => 'Otomasyon', 'icon' => '◆'],

        'hizli'       => ['label' => 'Hızlı Giriş',        'perm' => 'posts.create',       'group' => 'İçerik',    'icon' => '⚡'],

        'theme'       => ['label' => 'Tema Editörü',       'perm' => 'theme.manage',       'group' => 'Görünüm',   'icon' => '◐'],

        'settings'    => ['label' => 'Ayarlar',            'perm' => 'settings.manage',    'group' => 'Ayarlar',   'icon' => '⚙'],
        'kunye'       => ['label' => 'Künye / BİK',        'perm' => 'settings.manage',    'group' => 'Ayarlar',   'icon' => '§'],
        'widgets'     => ['label' => 'Widget\'lar',        'perm' => 'settings.manage',    'group' => 'Ayarlar',   'icon' => '◱'],
        'corrections' => ['label' => 'Düzeltme Talepleri', 'perm' => 'corrections.triage', 'group' => 'Ayarlar',   'icon' => '⚖'],
        'users'       => ['label' => 'Kullanıcılar',       'perm' => 'users.manage',       'group' => 'Ayarlar',   'icon' => '☺'],
        'roles'       => ['label' => 'Roller ve İzinler',  'perm' => 'roles.manage',       'group' => 'Ayarlar',   'icon' => '⚿'],
        'members'     => ['label' => 'Üyeler',             'perm' => 'members.manage',     'group' => 'Ayarlar',   'icon' => '♣'],

        'tools'       => ['label' => 'Araçlar',            'perm' => 'tools.manage',       'group' => 'Araçlar',   'icon' => '⚒'],
        'logs'        => ['label' => 'Günlükler',          'perm' => 'tools.manage',       'group' => 'Araçlar',   'icon' => '☰'],
    ];
}

/** Panel sayfası adresi. */
function admin_url($slug = 'dashboard', $q = []) {
    $base = base_url() . '/admin/?p=' . rawurlencode($slug);
    return $q ? $base . '&' . http_build_query($q) : $base;
}

/** Sayfa dosyası var mı? */
function admin_page_exists($slug) {
    return preg_match('/^[a-z0-9_\-]{1,40}$/', (string)$slug) && is_file(__DIR__ . '/pages/' . $slug . '.php');
}

/** Flash mesajlarını HTML olarak basar. */
function admin_flash_render() {
    $out = '';
    foreach (flash_take() as $f) {
        $cls = in_array($f['type'], ['ok', 'err', 'warn'], true) ? $f['type'] : 'ok';
        $out .= '<div class="uyari ' . $cls . '" role="status">' . esc($f['message']) . '</div>';
    }
    return $out;
}

/** Sayfalama bağlantıları (panel listelerinde ortak). */
function admin_paginate($total, $perPage, $current, $slug, $extraQuery = []) {
    return paginate($total, $perPage, $current, function ($n) use ($slug, $extraQuery) {
        return admin_url($slug, $extraQuery + ['sayfa' => $n]);
    });
}

/** <select> seçenekleri üretir. */
function admin_options(array $options, $selected, $emptyLabel = null) {
    $out = '';
    if ($emptyLabel !== null) { $out .= '<option value="">' . esc($emptyLabel) . '</option>'; }
    foreach ($options as $value => $label) {
        $out .= '<option value="' . esc($value) . '"' . ((string)$value === (string)$selected ? ' selected' : '') . '>'
              . esc($label) . '</option>';
    }
    return $out;
}

/** Durum rozeti. */
function admin_badge($text, $tone = 'notr') {
    return '<span class="rozet ' . esc($tone) . '">' . esc($text) . '</span>';
}

/** posts.status → [etiket, ton] */
function admin_status_label($status) {
    $map = [
        'draft'     => ['Taslak', 'notr'],
        'pending'   => ['Onay bekliyor', 'uyari'],
        'scheduled' => ['Zamanlanmış', 'bilgi'],
        'published' => ['Yayında', 'olumlu'],
        'archived'  => ['Arşiv', 'notr'],
    ];
    return isset($map[$status]) ? $map[$status] : [$status, 'notr'];
}

// ============================================================ giriş / çıkış

$page = isset($_GET['p']) ? (string)$_GET['p'] : 'dashboard';
$adminUser = current_user();

// Çıkış
if ($page === 'logout') {
    if (csrf_verify(isset($_GET['t']) ? $_GET['t'] : '')) {
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        @session_destroy();
    }
    header('Location: ' . base_url() . '/admin/?p=login', true, 302);
    ob_end_flush();
    exit;
}

// Giriş
if (!$adminUser) {
    $loginError = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'login') {
        $ip = client_ip();
        if (!csrf_verify((string)inp('_csrf', ''))) {
            $loginError = 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
        } elseif (!rate_limit('login:' . $ip, 8, 300)) {
            $loginError = 'Çok fazla başarısız deneme. 5 dakika sonra tekrar deneyin.';
            log_error('Giriş hız sınırı aşıldı', $ip);
        } else {
            $email = mb_strtolower(trim((string)inp('email', '')), 'UTF-8');
            $pass = (string)inp('password', '');
            // is_staff Faz 7 sütunudur; göç uygulanmamış kurulumda sorgu düşer.
            try {
                $row = q1('SELECT id, name, email, pass_hash, role, active, is_staff
                            FROM users WHERE email = :e', [':e' => $email]);
            } catch (Throwable $eStaff) {
                $row = q1('SELECT id, name, email, pass_hash, role, active FROM users WHERE email = :e', [':e' => $email]);
            }
            // SECURITY_AUDIT B-03: kullanıcı yoksa da bir hash doğrulaması çalıştır ki
            // yanıt süresi kullanıcının var olup olmadığını sızdırmasın.
            $hashToCheck = $row ? (string)$row['pass_hash']
                : '$2y$10$usesomesillystringforeverysillystringnotarealhash.aBcDe';
            $passOk = password_verify($pass, $hashToCheck);
            // Panel girişi YALNIZ etkin personele açıktır (denetim tur 2, B04):
            // üyeler /hesap üzerinden girer, panel erişimi geri alınmış personel
            // hiç oturum açamaz. Hata mesajı aynı kalır — hesap sayımı sızmasın.
            $personelMi = $row
                && (!function_exists('role_is_staff') || role_is_staff((string)$row['role']))
                && (!array_key_exists('is_staff', $row) || (int)$row['is_staff'] === 1);
            if ($row && $personelMi && (int)$row['active'] === 1 && $passOk) {
                if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
                    q('UPDATE users SET pass_hash = :h WHERE id = :i',
                        [':h' => password_hash($pass, PASSWORD_DEFAULT), ':i' => (int)$row['id']]);
                }
                session_regenerate_id(true);
                // Parola değişince eski oturumlar düşsün diye damga da yazılır.
                $guncelHash = (string)qv('SELECT pass_hash FROM users WHERE id = :i', [':i' => (int)$row['id']], '');
                session_bind_user((int)$row['id'], $guncelHash);
                rate_limit_clear('login:' . $ip);
                $to = (string)inp('next', '');
                header('Location: ' . ($to !== '' && strpos($to, '/') === 0 ? base_url() . $to : admin_url('dashboard')), true, 302);
                ob_end_flush();
                exit;
            }
            // Kullanıcı var mı yok mu bilgisini sızdırma
            $loginError = 'E-posta veya parola hatalı.';
            log_error('Başarısız giriş denemesi', $email . ' ip=' . $ip);
        }
    }
    admin_login_screen(isset($loginError) ? $loginError : '');
    ob_end_flush();
    exit;
}

// Panel erişimi
if (!can($adminUser, 'admin.access')) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Yetkisiz</title>'
       . '<p style="font:16px system-ui;padding:40px">Hesabınızın yönetim paneline erişim yetkisi yok.</p>';
    ob_end_flush();
    exit;
}

// ============================================================ sayfa yükleme

$nav = admin_nav();
if ($page === 'login') { $page = 'dashboard'; }
if (!admin_page_exists($page)) {
    if (isset($nav[$page])) {
        // Menüde tanımlı ama dosyası henüz yok (yapım aşamasındaki modül)
        $adminPageTitle = $nav[$page]['label'];
        $adminMissing = true;
    } else {
        $page = 'dashboard';
    }
}
if (isset($nav[$page]) && !can($adminUser, $nav[$page]['perm'])) {
    http_response_code(403);
    $adminPageTitle = 'Yetkisiz';
    $adminForbidden = true;
}

$adminPage = $page;
$adminPageTitle = isset($nav[$page]['label']) ? $nav[$page]['label'] : 'Panel';

ob_start();
if (!empty($adminForbidden)) {
    echo '<div class="uyari err">Bu sayfaya erişim yetkiniz yok.</div>';
} elseif (!empty($adminMissing)) {
    echo '<h1>' . esc($adminPageTitle) . '</h1>'
       . '<div class="uyari warn">Bu modül henüz kurulmamış. <code>admin/pages/' . esc($page) . '.php</code> dosyası bulunamadı.</div>';
} else {
    include __DIR__ . '/pages/' . $page . '.php';
}
$adminContent = (string)ob_get_clean();

admin_layout($adminPageTitle, $adminContent, $adminPage, $adminUser, $nav);
ob_end_flush();

// ============================================================ çatı

/** Panelin ortak HTML çerçevesi. */
function admin_layout($title, $content, $activePage, $user, array $nav) {
    $groups = [];
    foreach ($nav as $slug => $item) {
        if (!can($user, $item['perm'])) { continue; }
        if (!admin_page_exists($slug)) { continue; }
        $groups[$item['group']][$slug] = $item;
    }
    $aiStatus = ai_status_text();
    ?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= esc($title) ?> — <?= esc(setting('site_title', 'Manşet')) ?> Yönetim</title>
<link rel="stylesheet" href="<?= esc(url_asset('admin.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
<?php /* Yapılandırma ve ortak yardımcılar <head> içinde ve defer'siz yüklenir:
         sayfa gövdesindeki satır içi <script> blokları window.M'yi hazır bulmalıdır. */ ?>
<script>window.MANSET = {
  api: <?= json_encode(base_url() . '/api.php') ?>,
  csrf: <?= json_encode(csrf_token()) ?>,
  base: <?= json_encode(base_url()) ?>,
  uploads: <?= json_encode(base_url() . '/uploads/') ?>,
  role: <?= json_encode($user['role']) ?>
};</script>
<script src="<?= esc(url_asset('admin.js')) ?>?v=<?= esc(MANSET_VERSION) ?>"></script>
</head>
<body class="yonetim">

<a class="atla" href="#anaicerik">İçeriğe geç</a>

<header class="ust-cubuk">
  <button type="button" class="menu-dugme" id="menuAc" aria-label="Menüyü aç">☰</button>
  <a class="marka" href="<?= esc(admin_url('dashboard')) ?>"><span>MANŞET</span> Yönetim</a>
  <div class="ust-sag">
    <span class="ai-durum ai-<?= esc(preg_replace('/[^a-z]/', '', str_replace(['ı', 'â'], 'i', $aiStatus))) ?>">YZ: <?= esc($aiStatus) ?></span>
    <a class="dis-bag" href="<?= esc(base_url()) ?>/" target="_blank" rel="noopener">Siteyi gör ↗</a>
    <span class="kullanici"><?= esc($user['name']) ?> <small><?= esc($user['role']) ?></small></span>
    <a class="cikis" href="<?= esc(base_url()) ?>/admin/?p=logout&amp;t=<?= esc(csrf_token()) ?>">Çıkış</a>
  </div>
</header>

<div class="govde">
  <nav class="yan-menu" id="yanMenu" aria-label="Yönetim menüsü">
    <?php foreach ($groups as $groupName => $items): ?>
      <div class="menu-grup">
        <h2><?= esc($groupName) ?></h2>
        <ul>
          <?php foreach ($items as $slug => $item): ?>
            <li><a href="<?= esc(admin_url($slug)) ?>" class="<?= $slug === $activePage ? 'etkin' : '' ?>">
              <span class="ikon" aria-hidden="true"><?= $item['icon'] ?></span><?= esc($item['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </nav>

  <main class="icerik" id="anaicerik">
    <?= admin_flash_render() ?>
    <?= $content ?>
  </main>
</div>

<div class="ortu" id="ortu" hidden></div>
</body>
</html><?php
}

/** Giriş ekranı. */
function admin_login_screen($error = '') {
    ?><!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Giriş — <?= esc(setting('site_title', 'Manşet')) ?></title>
<link rel="stylesheet" href="<?= esc(url_asset('admin.css')) ?>?v=<?= esc(MANSET_VERSION) ?>">
</head>
<body class="giris-sayfasi">
<form class="giris-kutu" method="post" action="<?= esc(base_url()) ?>/admin/?p=login" autocomplete="on">
  <div class="marka-buyuk"><span>MANŞET</span></div>
  <h1>Yönetim paneli</h1>
  <?php if ($error !== ''): ?><div class="uyari err"><?= esc($error) ?></div><?php endif; ?>
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="login">
  <label>E-posta
    <input type="email" name="email" required autocomplete="username" value="<?= esc(inp('email', '')) ?>">
  </label>
  <label>Parola
    <input type="password" name="password" required autocomplete="current-password">
  </label>
  <button type="submit">Giriş yap</button>
  <p class="alt-not"><a href="<?= esc(base_url()) ?>/">← Siteye dön</a></p>
</form>
</body>
</html><?php
}
