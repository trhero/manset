<?php
/**
 * Manşet — üye hesap sayfaları (ön yüz giriş noktası).
 *
 * Bölümler: ?s=giris | kayit | dogrula | parola | profil | kaydedilenler
 *           | yorumlarim | abonelik | cikis
 *
 * Sayfa gövdesi tema şablonu `hesap` ile basılır (themes/gazete/hesap.php);
 * temada yoksa view_resolve() gazete temasına düşer. Gövde HAM basılır çünkü
 * form içerir ve `page` şablonundaki sanitize_html() formu yok ederdi.
 *
 * OTURUMSUZ ÖN YÜZ KURALI (CONTRACTS §3.1): buradaki formlar csrf_field_lazy()
 * kullanır; oturum yalnız kullanıcı gerçekten etkileşime girince açılır.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    header('Location: ' . base_url() . '/install/', true, 302);
    exit;
}

require_once __DIR__ . '/inc/view.php';
foreach (['seo', 'members', 'kunye', 'widgets', 'cache'] as $mod) {
    $f = __DIR__ . '/inc/' . $mod . '.php';
    if (is_file($f)) { require_once $f; }
}

if (!function_exists('member_current')) {
    http_response_code(503);
    echo 'Üyelik modülü kurulu değil.';
    exit;
}

// Oturum çerezi varsa oturumu çıktıdan önce aç (bkz. index.php açıklaması).
if (has_session_cookie()) { session_boot(); }

$bolum = preg_replace('/[^a-z]/', '', (string)(isset($_GET['s']) ? $_GET['s'] : ''));
if ($bolum === '') { $bolum = member_current() ? 'profil' : 'giris'; }

$gecerli = ['giris', 'kayit', 'dogrula', 'parola', 'profil', 'kaydedilenler', 'yorumlarim', 'abonelik', 'cikis'];
if (!in_array($bolum, $gecerli, true)) { $bolum = 'giris'; }

$uye = member_current();

// Oturum gerektiren bölümler
$oturumGerekli = ['profil', 'kaydedilenler', 'yorumlarim', 'abonelik'];
if (in_array($bolum, $oturumGerekli, true) && !$uye) {
    header('Location: ' . member_url('giris', ['geri' => $bolum]), true, 302);
    exit;
}
// Oturum açıkken anlamsız bölümler
if (in_array($bolum, ['giris', 'kayit'], true) && $uye) {
    header('Location: ' . member_url('profil'), true, 302);
    exit;
}

// Çıkış — CSRF korumalı POST ister (denetim tur 2, B08).
// GET ile gelinirse oturum düşürülmez; kullanıcıya onay düğmesi gösterilir.
// Böylece üçüncü taraf bir sayfadaki <img src=".../hesap?s=cikis"> ile
// zorla çıkış yaptırılamaz.
if ($bolum === 'cikis') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_guard();
        member_logout();
        header('Location: ' . base_url() . '/', true, 302);
        exit;
    }
    // GET: onay ekranı (aşağıdaki render akışına düşer)
}

// E-posta ve parola bağlantılarındaki token.
// member_send_verify_mail() / member_send_reset_mail() bağlantıyı `token`
// adıyla üretir; kısa `t` adı elle yazılan bağlantılar için korunur.
$tokenGet = '';
foreach (['token', 't'] as $ad) {
    if (isset($_GET[$ad]) && is_string($_GET[$ad]) && $_GET[$ad] !== '') {
        $tokenGet = (string)$_GET[$ad];
        break;
    }
}

// E-posta doğrulama bağlantısı (GET ile gelir)
$dogrulamaSonuc = null;
if ($bolum === 'dogrula' && $tokenGet !== '') {
    $dogrulamaSonuc = member_verify($tokenGet);
}

// Parola sıfırlama bağlantısı (token varsa yeni parola formu gösterilir)
$sifirlamaToken = ($bolum === 'parola') ? $tokenGet : '';

$basliklar = [
    'giris'         => 'Giriş yap',
    'kayit'         => 'Üye ol',
    'dogrula'       => 'E-posta doğrulama',
    'parola'        => 'Parola sıfırlama',
    'profil'        => 'Hesabım',
    'kaydedilenler' => 'Kaydettiklerim',
    'yorumlarim'    => 'Yorumlarım',
    'abonelik'      => 'Aboneliğim',
];
$baslik = isset($basliklar[$bolum]) ? $basliklar[$bolum] : 'Hesabım';

// Bölüme göre veri hazırlığı
$veri = [];
if ($uye) {
    $veri['abonelik'] = member_subscription((int)$uye['id']);
    if ($bolum === 'kaydedilenler') { $veri['kayitlar'] = member_bookmarks((int)$uye['id'], 30); }
    if ($bolum === 'yorumlarim')    { $veri['yorumlar'] = member_comments((int)$uye['id'], 30); }
}

$sablon = view_resolve('hesap') ? 'hesap' : 'page';

render($sablon, [
    'page' => [
        'id'        => 0,
        'title'     => $baslik,
        'slug'      => 'hesap',
        'seo_title' => $baslik . ' — ' . setting('site_title', 'Manşet'),
        'seo_desc'  => setting('site_title', 'Manşet') . ' üye hesabı.',
        'body'      => '',
    ],
    'bolum'            => $bolum,
    'uye'              => $uye,
    'veri'             => $veri,
    'dogrulamaSonuc'   => $dogrulamaSonuc,
    'sifirlamaToken'   => $sifirlamaToken,
    'geriBolum'        => preg_replace('/[^a-z]/', '', (string)(isset($_GET['geri']) ? $_GET['geri'] : '')),
    'isHesap'          => true,
]);
