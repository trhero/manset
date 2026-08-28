<?php
/**
 * Manşet — bülten onay ve abonelikten çıkma sayfası (Ajan-F, 1.2-11).
 *
 *   /bulten.php?onay=<token>            → çift onayın 2. adımı, confirmed = 1
 *   /bulten.php?cikis=<token>           → çıkış onay ekranı (henüz silmez)
 *   /bulten.php?cikis=<token>&onayla=1  → kaydı siler
 *
 * ---------------------------------------------------------------------------
 * ÇEREZ KURALI (CONTRACTS §3.1 / 1.2 sözleşmesi §1)
 * Bu sayfayı GİRİŞ YAPMAMIŞ okur açar. `session_boot()` ÇAĞRILMAZ, CSRF
 * anahtarı istenmez, oturum açılmaz — anonim istek `Set-Cookie` almaz.
 * Bağlantıdaki token'ın kendisi kimlik kanıtıdır: tahmin edilemez (32 bayt),
 * tek aboneye özeldir ve veritabanında yalnız SHA-256 özeti durur.
 *
 * NEDEN ÇIKIŞ İKİ ADIMLI
 * Kurumsal e-posta tarayıcıları iletideki bağlantıları ÖN YÜKLER. Tek adımlı
 * bir çıkış bağlantısı, okur hiç tıklamadan aboneliği düşürürdü. Onay
 * bağlantısı ise bilerek tek adımlıdır: tıklama rızanın ta kendisidir ve
 * standart çift onay akışı böyle çalışır.
 *
 * Sayfa kişiye özeldir; `private, no-store` ile sunulur ve önbelleğe girmez.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    header('Location: ' . base_url() . '/install/', true, 302);
    exit;
}

require_once __DIR__ . '/inc/view.php';
foreach (['seo', 'kunye', 'widgets', 'newsletter'] as $bultenModul) {
    $bultenDosya = __DIR__ . '/inc/' . $bultenModul . '.php';
    if (is_file($bultenDosya)) { require_once $bultenDosya; }
}

if (!function_exists('newsletter_confirm')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Bülten modülü kurulu değil.\n";
    exit;
}

header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');

$onayToken  = isset($_GET['onay'])  && is_string($_GET['onay'])  ? (string)$_GET['onay']  : '';
$cikisToken = isset($_GET['cikis']) && is_string($_GET['cikis']) ? (string)$_GET['cikis'] : '';
$cikisOnay  = isset($_GET['onayla']) && (string)$_GET['onayla'] === '1';

$baslik = 'Bülten';
$govde  = '';
$durum  = 200;

if ($onayToken !== '') {
    // -------------------------------------------------- çift onayın 2. adımı
    $res = newsletter_confirm($onayToken);
    if ($res['ok']) {
        $baslik = 'Bülten kaydınız onaylandı';
        $govde = '<p>Teşekkürler — <strong>' . esc($res['email']) . '</strong> adresi bülten listesine eklendi.</p>'
               . '<p>Dilediğiniz zaman size gönderilen iletideki çıkış bağlantısıyla listeden ayrılabilirsiniz.</p>';
    } else {
        $durum = 400;
        $baslik = 'Onay tamamlanamadı';
        $govde = '<p>' . esc($res['error']) . '</p>'
               . '<p>Kayıt formunu yeniden doldurarak yeni bir onay bağlantısı isteyebilirsiniz.</p>';
    }

} elseif ($cikisToken !== '' && $cikisOnay) {
    // -------------------------------------------------- çıkışın 2. adımı
    $res = newsletter_unsubscribe($cikisToken);
    if ($res['ok']) {
        $baslik = 'Aboneliğiniz sonlandırıldı';
        $govde = '<p><strong>' . esc($res['email']) . '</strong> adresi bülten listesinden silindi. '
               . 'Kaydınız pasife alınmadı, tamamen kaldırıldı.</p>';
    } else {
        $durum = 400;
        $baslik = 'İşlem tamamlanamadı';
        $govde = '<p>' . esc($res['error']) . '</p>';
    }

} elseif ($cikisToken !== '') {
    // -------------------------------------------------- çıkışın 1. adımı (onay ekranı)
    $row = newsletter_unsub_peek($cikisToken);
    if ($row) {
        $baslik = 'Bülten aboneliğinden çıkış';
        $link = base_url() . '/bulten.php?cikis=' . rawurlencode($cikisToken) . '&onayla=1';
        $govde = '<p><strong>' . esc((string)$row['email']) . '</strong> adresini bülten listesinden '
               . 'çıkarmak üzeresiniz.</p>'
               . '<p><a href="' . esc($link) . '" rel="nofollow">Evet, aboneliğimi sonlandır</a></p>'
               . '<p>Yanlışlıkla geldiyseniz bu sayfayı kapatmanız yeterli; hiçbir şey değişmez.</p>';
    } else {
        $durum = 400;
        $baslik = 'Bağlantı geçersiz';
        $govde = '<p>Bu çıkış bağlantısı geçersiz ya da adres zaten listede değil.</p>';
    }

} else {
    $durum = 400;
    $baslik = 'Bülten bağlantısı eksik';
    $govde = '<p>Bu sayfa yalnız e-postanızdaki onay veya çıkış bağlantısıyla açılır.</p>';
}

/*
 * ÖNBELLEK BAŞLIĞI — NEDEN TAMPON
 * `render()` (inc/view.php) oturumsuz istekte `public, max-age=0, s-maxage=60`
 * yazar; bu sayfa ise abonenin E-POSTA ADRESİNİ basar ve araya giren bir vekil
 * ya da CDN onu 60 saniye boyunca BAŞKALARINA verebilirdi. Çıktı tamponda
 * üretilir, başlık render'dan SONRA (henüz gönderilmeden) `private, no-store`
 * ile değiştirilir. inc/view.php başkasının dosyası olduğu için çözüm burada.
 */
$siteAdi = setting('site_title', 'Manşet');
ob_start();
render('page', [
    'page' => [
        'id'        => 0,
        'title'     => $baslik,
        'slug'      => 'bulten',
        'seo_title' => $baslik . ' — ' . $siteAdi,
        'seo_desc'  => $siteAdi . ' bülten aboneliği.',
        'body'      => $govde,
    ],
    'isBulten' => true,
], $durum);
if (!headers_sent()) {
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
}
ob_end_flush();
