<?php
/**
 * Manşet — abonelik / ödeme giriş noktası (1.2-04).
 *
 * Bölümler:  ?s=planlar (varsayılan) · ?s=havale&ref=… · ?s=donus&ref=… · ?s=webhook&p=…
 *
 * ÜÇ KURAL
 *
 * 1) WEBHOOK EN ÜSTTE İŞLENİR ve oturum AÇMAZ. Dış sistemden gelen isteğe
 *    çerez verilmez (CONTRACTS §3.1) ve CSRF beklenmez — tek savunma imza
 *    doğrulamasıdır (`payment_webhook_handle`).
 *
 * 2) OKUR TARAFI OTURUM İSTER. Ödeme akışına giren okur zaten giriş yapmıştır;
 *    giriş yoksa üye giriş sayfasına yönlendirilir. Anonim ziyaretçi bu
 *    dosyadan çerez ALMAZ (yönlendirme oturum açılmadan yapılır).
 *
 * 3) KART VERİSİ BU DOSYADAN GEÇMEZ. Kart alanları yalnız sağlayıcının
 *    barındırdığı sayfada doldurulur; buradan yalnız yönlendirme yapılır.
 *
 * NOT (orkestratöre): sayfa gövdesi kendi kabuğunu basar. Temalara bir
 * `odeme` şablonu ya da `hesap.php` içine bir `odeme` bölümü eklenirse
 * burası ona devredilmelidir — bkz. gelistirme/1.2-ajan-c.md.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    header('Location: ' . base_url() . '/install/', true, 302);
    exit;
}

require_once __DIR__ . '/inc/view.php';
foreach (['seo', 'members', 'cache', 'payment'] as $mod) {
    $f = __DIR__ . '/inc/' . $mod . '.php';
    if (is_file($f)) { require_once $f; }
}

if (!function_exists('payment_start')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ödeme modülü kurulu değil.';
    exit;
}

$bolum = preg_replace('/[^a-z]/', '', (string)(isset($_GET['s']) ? $_GET['s'] : ''));
if ($bolum === '') { $bolum = 'planlar'; }

// ---------------------------------------------------------------- 1) WEBHOOK
// Oturum, çerez, CSRF ve tema YOK. En üstte, her şeyden önce.
if ($bolum === 'webhook') {
    $saglayici = (string)(isset($_GET['p']) ? $_GET['p'] : '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        header('Allow: POST');
        echo 'POST';
        exit;
    }
    // ÖDEME KAPALIYSA BİLDİRİM İŞLENMEZ (denetim turu 4, O-02).
    // Kapı aşağıda vardı ama webhook dalı ondan ÖNCE dönüyordu: denetçi
    // `payment_enabled = 0` iken geçerli imzalı bir bildirimle bir ödemeyi
    // "ödendi" yapıp aboneliği 365 gün uzattı. Panel metni yayıncıya bunun
    // tersini söylüyor — yani anahtar, kapattığını sandığı şeyi kapatmıyordu.
    //
    // 503 bilinçli: sağlayıcılar 5xx'i "sonra tekrar dene" diye yorumlar.
    // 200 dönseydi sağlayıcı bildirimi başarılı sayıp bir daha göndermezdi ve
    // yayıncı ödemeyi yeniden açtığında o tahsilat KALICI olarak kaybolurdu.
    if (!payment_enabled()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo 'Odeme kapali.';
        exit;
    }

    $veri = $_POST;
    if (!$veri) {
        $j = json_body();
        if ($j) { $veri = $j; }
    }
    $r = payment_webhook_handle($saglayici, (array)$veri);
    http_response_code((int)$r['code']);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    echo (string)$r['body'];
    exit;
}

// ---------------------------------------------------------------- 2) okur kapısı
// Oturum çerezi varsa çıktıdan ÖNCE aç (session_boot() headers_sent() sonrası
// sessizce döner — 1.1'de üç hata bu yüzden oluştu).
if (has_session_cookie()) { session_boot(); }

$uye = function_exists('member_current') ? member_current() : null;
if (!$uye) {
    // Anonim ziyaretçiye çerez VERİLMEZ: yönlendirme oturum açmadan yapılır.
    $geri = function_exists('member_url') ? member_url('giris', ['geri' => 'abonelik'])
                                          : base_url() . '/hesap.php?s=giris';
    header('Location: ' . $geri, true, 302);
    exit;
}
$uyeId = (int)$uye['id'];

if (!payment_enabled()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Çevrimiçi abonelik şu anda kapalı.';
    exit;
}

// Ödeme ekranları KİŞİYE ÖZELDİR: hiçbir ara katman önbelleklemesin.
header('Cache-Control: private, no-store, max-age=0');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

$mesajOk = '';
$mesajErr = '';
$ref = (string)preg_replace('/[^A-Za-z0-9]/', '', (string)(isset($_GET['ref']) ? $_GET['ref'] : ''));

// ---------------------------------------------------------------- 3) eylemler
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_guard();
    $eylem = inp_s('do', '');

    if ($eylem === 'basla') {
        $r = payment_start($uyeId, inp_s('plan', ''), inp_s('yontem', 'auto'));
        if (empty($r['ok'])) {
            $mesajErr = (string)arr($r, 'error', 'Ödeme başlatılamadı.');
        } elseif ((string)$r['mode'] === 'redirect') {
            // Sağlayıcının BARINDIRDIĞI sayfaya gidilir; kart verisi orada alınır.
            header('Location: ' . (string)$r['url'], true, 302);
            exit;
        } else {
            header('Location: ' . payment_page_url(['s' => 'havale', 'ref' => (string)$r['ref']]), true, 302);
            exit;
        }
    } elseif ($eylem === 'dekont') {
        $dosya = isset($_FILES['dekont']) && is_array($_FILES['dekont']) ? $_FILES['dekont'] : [];
        $r = payment_receipt_upload($uyeId, inp_s('ref', ''), $dosya);
        if (empty($r['ok'])) { $mesajErr = (string)arr($r, 'error', 'Dekont yüklenemedi.'); }
        else { $mesajOk = (string)arr($r, 'message', 'Dekontunuz alındı.'); }
        $ref = (string)preg_replace('/[^A-Za-z0-9]/', '', inp_s('ref', ''));
        $bolum = 'havale';
    }
}

// ---------------------------------------------------------------- 4) veri
$planlar = payment_plans(true);
$abonelik = function_exists('member_subscription') ? member_subscription($uyeId)
          : ['tier' => 'free', 'valid_until' => ''];

/** Sipariş SAHİPLİK denetimi — başkasının kaydı gösterilmez. */
$odeme = null;
if ($ref !== '') {
    $o = payment_by_ref($ref);
    if ($o && (int)$o['user_id'] === $uyeId) { $odeme = $o; }
}
if (in_array($bolum, ['havale', 'donus'], true) && !$odeme) {
    $bolum = 'planlar';
    if ($mesajErr === '') { $mesajErr = 'Ödeme kaydı bulunamadı.'; }
}

$siteAdi = setting('site_title', 'Manşet');
$baslik = ['planlar' => 'Abonelik', 'havale' => 'Havale ile ödeme', 'donus' => 'Ödeme sonucu'];
$sayfaBaslik = isset($baslik[$bolum]) ? $baslik[$bolum] : 'Abonelik';
$temaCss = function_exists('theme_url') ? theme_url('style.css') : '';
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= esc($sayfaBaslik . ' — ' . $siteAdi) ?></title>
<?php if ($temaCss !== ''): ?><link rel="stylesheet" href="<?= esc($temaCss) ?>"><?php endif; ?>
<style>
.odeme-sarma{max-width:760px;margin:0 auto;padding:24px 16px;font:16px/1.55 system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
.odeme-sarma h1{font-size:1.6rem;margin:0 0 4px}
.odeme-kart{border:1px solid #d8dce3;border-radius:8px;padding:16px;margin:0 0 14px;background:#fff}
.odeme-kart h2{font-size:1.1rem;margin:0 0 6px}
.odeme-fiyat{font-size:1.35rem;font-weight:700}
.odeme-uyari{border-radius:6px;padding:10px 12px;margin:0 0 14px}
.odeme-uyari.ok{background:#e7f6ec;border:1px solid #9bd3ae}
.odeme-uyari.err{background:#fdeceb;border:1px solid #efb1ac}
.odeme-dugme{display:inline-block;border:0;border-radius:6px;padding:9px 16px;background:#c0392b;color:#fff;font:inherit;cursor:pointer;text-decoration:none}
.odeme-dugme.ikincil{background:#5a6472}
.odeme-kucuk{font-size:.86rem;color:#5a6472}
.odeme-iban{font-family:ui-monospace,Consolas,monospace;font-size:1.05rem;word-break:break-all}
</style>
</head>
<body>
<div class="odeme-sarma">

  <p class="odeme-kucuk"><a href="<?= esc(base_url()) ?>/">← <?= esc($siteAdi) ?></a></p>
  <h1><?= esc($sayfaBaslik) ?></h1>

  <?php if ($mesajOk !== ''): ?><div class="odeme-uyari ok"><?= esc($mesajOk) ?></div><?php endif; ?>
  <?php if ($mesajErr !== ''): ?><div class="odeme-uyari err"><?= esc($mesajErr) ?></div><?php endif; ?>

  <?php if ((string)arr($abonelik, 'tier', 'free') === 'premium'): ?>
    <div class="odeme-uyari ok">
      Aboneliğiniz etkin<?= trim((string)arr($abonelik, 'valid_until', '')) !== ''
        ? ' — bitiş: ' . esc(tr_date((string)$abonelik['valid_until'], false)) : '' ?>.
      Yeni bir ödeme süreyi UZATIR, sıfırlamaz.
    </div>
  <?php endif; ?>

<?php if ($bolum === 'planlar'): ?>

  <?php if (!$planlar): ?>
    <div class="odeme-kart"><p>Şu anda satışta abonelik planı yok.</p></div>
  <?php endif; ?>

  <?php foreach ($planlar as $p): ?>
    <div class="odeme-kart">
      <h2><?= esc($p['name']) ?></h2>
      <p class="odeme-fiyat"><?= esc(payment_amount_label($p['price_cents'], $p['currency'])) ?>
        <span class="odeme-kucuk">/ <?= (int)$p['days'] ?> gün</span></p>
      <?php if (trim((string)$p['description']) !== ''): ?>
        <p class="odeme-kucuk"><?= esc($p['description']) ?></p>
      <?php endif; ?>

      <?php if (payment_provider() !== 'manual_iban' && payment_configured()): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="basla">
          <input type="hidden" name="yontem" value="auto">
          <input type="hidden" name="plan" value="<?= esc($p['code']) ?>">
          <button type="submit" class="odeme-dugme">Kartla öde</button>
        </form>
      <?php endif; ?>

      <?php if (payment_manual_enabled() && payment_iban() !== ''): ?>
        <form method="post" style="display:inline">
          <?= csrf_field() ?>
          <input type="hidden" name="do" value="basla">
          <input type="hidden" name="yontem" value="manual_iban">
          <input type="hidden" name="plan" value="<?= esc($p['code']) ?>">
          <button type="submit" class="odeme-dugme ikincil">Havale ile öde</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

  <p class="odeme-kucuk">
    Kart bilgileriniz bu siteye girilmez; ödeme sağlayıcının kendi güvenli sayfasında alınır.
    <?php $kosul = trim((string)setting('payment_terms_url', '')); if ($kosul !== ''): ?>
      <a href="<?= esc($kosul) ?>">Abonelik koşulları</a>.
    <?php endif; ?>
    Abonelik satışına ilişkin bilgilendirme ve cayma yükümlülükleri için yayıncı
    yürürlükteki düzenlemeyi teyit etmelidir.
  </p>

<?php elseif ($bolum === 'havale'): ?>

  <div class="odeme-kart">
    <h2>Havale / EFT bilgileri</h2>
    <p><strong>Tutar:</strong> <?= esc(payment_amount_label($odeme['amount_cents'], $odeme['currency'])) ?>
       — <?= esc((string)$odeme['plan_name']) ?> (<?= (int)$odeme['days'] ?> gün)</p>
    <p><strong>Alıcı:</strong> <?= esc(payment_iban_name()) ?>
       <?= payment_bank_name() !== '' ? ' · ' . esc(payment_bank_name()) : '' ?></p>
    <p class="odeme-iban"><strong>IBAN:</strong> <?= esc(payment_iban()) ?></p>
    <p><strong>Açıklama alanına yazın:</strong> <span class="odeme-iban"><?= esc((string)$odeme['ref']) ?></span></p>
    <p class="odeme-kucuk">Açıklamayı yazmazsanız ödemeniz eşleştirilemez.</p>
  </div>

  <div class="odeme-kart">
    <h2>Dekont yükleyin</h2>
    <p class="odeme-kucuk">JPG, PNG, WEBP ya da PDF · en fazla 4 MB.
      Dekontunuz yalnız yöneticinin görebileceği kapalı bir klasörde saklanır.</p>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="dekont">
      <input type="hidden" name="ref" value="<?= esc((string)$odeme['ref']) ?>">
      <p><input type="file" name="dekont" accept=".jpg,.jpeg,.png,.webp,.pdf" required></p>
      <button type="submit" class="odeme-dugme">Gönder</button>
    </form>
    <?php $d = payment_status_label((string)$odeme['status']); ?>
    <p class="odeme-kucuk">Durum: <strong><?= esc($d[0]) ?></strong></p>
  </div>

<?php elseif ($bolum === 'donus'): ?>

  <?php
  // DÖNÜŞ SAYFASI HİÇBİR ŞEYE KARAR VERMEZ. Tarayıcıdan gelen `d`
  // parametresi kullanıcı elindedir; aboneliği YALNIZ imzalı webhook uzatır.
  // Burada yalnız veritabanındaki gerçek durum gösterilir.
  $durum = (string)$odeme['status'];
  $etiket = payment_status_label($durum);
  ?>
  <div class="odeme-kart">
    <h2>Sipariş <?= esc((string)$odeme['ref']) ?></h2>
    <p><strong>Durum:</strong> <?= esc($etiket[0]) ?></p>
    <p><strong>Tutar:</strong> <?= esc(payment_amount_label($odeme['amount_cents'], $odeme['currency'])) ?></p>
    <?php if ($durum === 'paid'): ?>
      <p>Aboneliğiniz açıldı<?= trim((string)$odeme['granted_until']) !== ''
        ? ' — bitiş: ' . esc(tr_date((string)$odeme['granted_until'], false)) : '' ?>.</p>
    <?php elseif ($durum === 'pending'): ?>
      <p>Ödemeniz sağlayıcı tarafından henüz onaylanmadı. Onay bildirimi geldiğinde
         aboneliğiniz kendiliğinden açılır; bu sayfayı birkaç dakika sonra yenileyin.</p>
    <?php else: ?>
      <p>Ödeme tamamlanmadı. Dilerseniz yeniden deneyebilirsiniz.</p>
    <?php endif; ?>
    <p><a class="odeme-dugme ikincil" href="<?= esc(payment_page_url()) ?>">Planlara dön</a></p>
  </div>

<?php endif; ?>

  <p class="odeme-kucuk">
    <a href="<?= esc(function_exists('member_url') ? member_url('abonelik') : base_url() . '/hesap.php?s=abonelik') ?>">Aboneliğim</a>
  </p>
</div>
</body>
</html>
