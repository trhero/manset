<?php
/**
 * Panel — Ödemeler ve abonelik planları (1.2-04).
 *
 * `payments.manage` KİLİTLİ bir izindir (yalnız admin): sağlayıcı anahtarı ve
 * tahsilat kaydı rol geçersiz kılmalarıyla açılamaz.
 *
 * Bu ekran ham anahtar GÖSTERMEZ — yalnız `payment_secret_masked()` çıktısı.
 * Bütün yazma işlemleri POST + `csrf_guard()` + yönlendirme desenindedir
 * (ob_start() tamponu admin/index.php'de açılır).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('payments.manage');

$adminPageTitle = 'Ödemeler';

if (!function_exists('payment_plans')) {
    echo '<div class="uyari warn"><code>inc/payment.php</code> yüklü değil; ödeme modülü kapalı.</div>';
    return;
}
if (!payment_tables_ready()) {
    echo '<div class="uyari warn">Ödeme tabloları henüz kurulmadı. Panel → Araçlar ekranından '
       . 'veritabanı göçlerini uygulayın (<code>017_payments.php</code>).</div>';
    return;
}

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $do = inp_s('do', '');
    $adminId = (int)arr($adminUser, 'id', 0);

    if ($do === 'plan_kaydet') {
        $r = payment_plan_save([
            'id'          => inp_i('id', 0),
            'code'        => inp_s('code', ''),
            'name'        => inp_s('name', ''),
            'days'        => inp_i('days', 30),
            'price'       => inp_s('price', '0'),
            'currency'    => inp_s('currency', 'TRY'),
            'description' => inp_s('description', ''),
            'active'      => inp('active') ? 1 : 0,
            'sort'        => inp_i('sort', 0),
        ]);
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)arr($r, !empty($r['ok']) ? 'message' : 'error', ''));

    } elseif ($do === 'plan_sil') {
        $r = payment_plan_delete(inp_i('id', 0));
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)arr($r, !empty($r['ok']) ? 'message' : 'error', ''));

    } elseif ($do === 'onayla') {
        $r = payment_manual_approve(inp_i('id', 0), $adminId, inp_s('note', ''));
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)arr($r, !empty($r['ok']) ? 'message' : 'error', ''));

    } elseif ($do === 'reddet') {
        $r = payment_manual_reject(inp_i('id', 0), $adminId, inp_s('note', ''));
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)arr($r, !empty($r['ok']) ? 'message' : 'error', ''));

    } elseif ($do === 'iade') {
        $r = payment_refund(inp_i('id', 0), $adminId, inp_s('note', ''));
        flash(!empty($r['ok']) ? 'ok' : 'err', (string)arr($r, !empty($r['ok']) ? 'message' : 'error', ''));
    }

    header('Location: ' . admin_url('payments', array_filter(['durum' => inp_s('durum', '')])), true, 302);
    exit;
}

// ---------------------------------------------------------------- veri
$durumSuzgec = inp_s('durum', '');
if ($durumSuzgec !== '' && !array_key_exists($durumSuzgec, payment_statuses())) { $durumSuzgec = ''; }

$sayfa = max(1, inp_i('sayfa', 1));
$perPage = 25;
$toplam = payment_count(['status' => $durumSuzgec]);
$kayitlar = payment_list(['status' => $durumSuzgec], $perPage, ($sayfa - 1) * $perPage);

$bekleyenDekont = payment_list(['status' => 'review'], 25, 0);
$planlar = payment_plans(false);
$duzenle = null;
$duzenleId = inp_i('plan', 0);
if ($duzenleId > 0) { $duzenle = payment_plan_by_id($duzenleId); }

$odenenSayi = payment_count(['status' => 'paid']);
$bekleyenSayi = payment_count(['status' => 'pending']);

/** Toplam tahsilat (kuruş) — yalnız `paid` kayıtlar. */
$tahsilat = 0;
try { $tahsilat = (int)qv('SELECT COALESCE(SUM(amount_cents), 0) FROM payments WHERE status = \'paid\'', [], 0); }
catch (Throwable $e) { $tahsilat = 0; }
?>

<div class="sayfa-basligi">
  <h1>Ödemeler</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('members')) ?>">Üyeler →</a>
    <a class="dugme" href="<?= esc(admin_url('settings')) ?>">Ödeme ayarları →</a>
  </div>
</div>

<div class="izgara-4 bosluk-alt">
  <div class="sayac vurgulu"><div class="deger"><?= (int)$odenenSayi ?></div><div class="etiket">Ödenmiş</div></div>
  <div class="sayac"><div class="deger"><?= (int)$bekleyenSayi ?></div><div class="etiket">Bekleyen</div></div>
  <div class="sayac"><div class="deger"><?= count($bekleyenDekont) ?></div><div class="etiket">Dekont onayı</div></div>
  <div class="sayac"><div class="deger"><?= esc(payment_cents_to_text($tahsilat)) ?></div><div class="etiket">Toplam tahsilat</div></div>
</div>

<div class="kart">
  <div class="kart-baslik">Sağlayıcı</div>
  <table class="tablo" style="min-width:0">
    <tr><th>Durum</th><td><?= admin_badge(payment_status_text(),
        payment_status_text() === 'hazır' ? 'olumlu' : (payment_status_text() === 'test modu' ? 'bilgi' : 'uyari')) ?></td></tr>
    <tr><th>Yöntem</th><td><?= esc(arr(payment_providers(), payment_provider(), payment_provider())) ?></td></tr>
    <?php if (payment_provider() === 'paytr'): ?>
      <tr><th>Üye işyeri no</th><td><?= esc(payment_paytr_merchant_id() !== '' ? payment_paytr_merchant_id() : '—') ?></td></tr>
      <?php /* HAM ANAHTAR EKRANA BASILMAZ — yalnız maskeli özet. */ ?>
      <tr><th>merchant_key</th><td><code><?= esc(payment_secret_masked(payment_paytr_key()) ?: 'tanımlı değil') ?></code></td></tr>
      <tr><th>merchant_salt</th><td><code><?= esc(payment_secret_masked(payment_paytr_salt()) ?: 'tanımlı değil') ?></code></td></tr>
      <tr><th>Sandbox</th><td><?= payment_paytr_sandbox() ? admin_badge('açık', 'bilgi') : admin_badge('kapalı', 'notr') ?></td></tr>
      <tr><th>Bildirim (webhook) adresi</th>
          <td><code style="word-break:break-all"><?= esc(payment_webhook_url('paytr')) ?></code>
          <div class="mini soluk">Bu adresi sağlayıcı panelinizdeki "Bildirim URL" alanına yazın.
            İmzası doğrulanmayan bildirim hiçbir kayıt oluşturmaz.</div></td></tr>
    <?php endif; ?>
    <?php if (payment_manual_enabled()): ?>
      <tr><th>IBAN</th><td><code><?= esc(payment_iban() !== '' ? payment_iban() : 'tanımlı değil') ?></code></td></tr>
    <?php endif; ?>
  </table>
  <?php if (payment_test_mode()): ?>
    <div class="uyari bilgi bosluk-ust">Test modu açık: sağlayıcıya gerçek çağrı yapılmaz.
      Gelen bildirimin imza doğrulaması test modunda da aynen çalışır.</div>
  <?php endif; ?>
</div>

<?php // ------------------------------------------------------------ bekleyen dekontlar ?>
<?php if ($bekleyenDekont): ?>
  <div class="kart">
    <div class="kart-baslik">Onay bekleyen dekontlar (<?= count($bekleyenDekont) ?>)</div>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead><tr><th>Sipariş</th><th>Üye</th><th>Tutar</th><th>Plan</th><th>Dekont</th><th class="islem">İşlem</th></tr></thead>
        <tbody>
        <?php foreach ($bekleyenDekont as $d): ?>
          <tr>
            <td class="tek-satir mini"><?= esc((string)$d['ref']) ?></td>
            <td><?= esc((string)arr($d, 'user_email', '—')) ?></td>
            <td class="dar"><?= esc(payment_amount_label($d['amount_cents'], $d['currency'])) ?></td>
            <td class="dar"><?= esc((string)$d['plan_name']) ?> · <?= (int)$d['days'] ?> gün</td>
            <td class="dar">
              <?php if (trim((string)$d['receipt_file']) !== ''): ?>
                <a href="<?= esc(base_url()) ?>/api.php?a=payment.receipt&amp;id=<?= (int)$d['id'] ?>"
                   target="_blank" rel="noopener noreferrer">Görüntüle</a>
              <?php else: ?><span class="soluk">yok</span><?php endif; ?>
            </td>
            <td class="islem">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="onayla">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="dugme kucuk birincil"
                        data-onay="Ödeme onaylanacak ve abonelik <?= (int)$d['days'] ?> gün uzatılacak. Emin misiniz?">Onayla</button>
              </form>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="do" value="reddet">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="dugme kucuk tehlike" data-onay="Bu dekont reddedilecek.">Reddet</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="iki-kolon">
  <div>
    <?php // ------------------------------------------------------ ödeme kayıtları ?>
    <div class="sekmeler">
      <a href="<?= esc(admin_url('payments')) ?>" class="<?= $durumSuzgec === '' ? 'etkin' : '' ?>">Tümü</a>
      <?php foreach (payment_statuses() as $k => $v): ?>
        <a href="<?= esc(admin_url('payments', ['durum' => $k])) ?>"
           class="<?= $durumSuzgec === $k ? 'etkin' : '' ?>"><?= esc($v[0]) ?></a>
      <?php endforeach; ?>
    </div>

    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr><th>Sipariş</th><th>Üye</th><th>Tutar</th><th>Yöntem</th><th>Durum</th><th>Bitiş</th><th>Tarih</th><th class="islem">İşlem</th></tr>
        </thead>
        <tbody>
        <?php if (!$kayitlar): ?>
          <tr><td colspan="8" class="bos-durum">Kayıt yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($kayitlar as $k): $et = payment_status_label((string)$k['status']); ?>
          <tr>
            <td class="mini tek-satir"><?= esc((string)$k['ref']) ?></td>
            <td class="mini"><?= esc((string)arr($k, 'user_email', '—')) ?></td>
            <td class="dar"><?= esc(payment_amount_label($k['amount_cents'], $k['currency'])) ?></td>
            <td class="dar mini"><?= esc(arr(payment_providers(), (string)$k['provider'], (string)$k['provider'])) ?></td>
            <td class="dar"><?= admin_badge($et[0], $et[1]) ?></td>
            <td class="dar mini"><?= esc(trim((string)$k['granted_until']) !== '' ? tr_date((string)$k['granted_until'], false) : '—') ?></td>
            <td class="dar mini"><?= esc(tr_date((string)$k['created_at'], false)) ?></td>
            <td class="islem">
              <?php if ((string)$k['status'] === 'paid'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="iade">
                  <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                  <input type="hidden" name="durum" value="<?= esc($durumSuzgec) ?>">
                  <button type="submit" class="dugme kucuk tehlike"
                          data-onay="Abonelik süresi <?= (int)$k['days'] ?> gün geri alınacak. Para iadesini sağlayıcı panelinden ayrıca yapmalısınız. Emin misiniz?">İade</button>
                </form>
              <?php elseif (in_array((string)$k['status'], ['pending', 'review'], true) && (string)$k['provider'] === 'manual_iban'): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="onayla">
                  <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                  <input type="hidden" name="durum" value="<?= esc($durumSuzgec) ?>">
                  <button type="submit" class="dugme kucuk" data-onay="Ödeme onaylanacak.">Onayla</button>
                </form>
              <?php else: ?><span class="soluk mini">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= admin_paginate($toplam, $perPage, $sayfa, 'payments', array_filter(['durum' => $durumSuzgec])) ?>
  </div>

  <div>
    <?php // ------------------------------------------------------ plan formu ?>
    <div class="kart">
      <div class="kart-baslik"><?= $duzenle ? 'Planı düzenle' : 'Yeni plan' ?></div>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="plan_kaydet">
        <input type="hidden" name="id" value="<?= (int)arr($duzenle, 'id', 0) ?>">

        <div class="form-alan">
          <label for="p_name">Plan adı</label>
          <input type="text" id="p_name" name="name" maxlength="120" required
                 value="<?= esc((string)arr($duzenle, 'name', '')) ?>">
        </div>
        <div class="form-alan">
          <label for="p_code">Kod</label>
          <input type="text" id="p_code" name="code" maxlength="40" required
                 value="<?= esc((string)arr($duzenle, 'code', '')) ?>">
          <span class="form-yardim">Küçük harf, rakam ve tire. Adreste ve ödeme kaydında geçer.</span>
        </div>
        <div class="form-satir">
          <div class="form-alan">
            <label for="p_price">Fiyat</label>
            <input type="text" id="p_price" name="price" maxlength="15"
                   value="<?= esc($duzenle ? payment_cents_to_text(arr($duzenle, 'price_cents', 0)) : '') ?>">
          </div>
          <div class="form-alan">
            <label for="p_cur">Para birimi</label>
            <select id="p_cur" name="currency">
              <?= admin_options(payment_currencies(), (string)arr($duzenle, 'currency', 'TRY')) ?>
            </select>
          </div>
          <div class="form-alan">
            <label for="p_days">Süre (gün)</label>
            <input type="number" id="p_days" name="days" min="1" max="3650"
                   value="<?= (int)arr($duzenle, 'days', 30) ?>">
          </div>
        </div>
        <div class="form-alan">
          <label for="p_desc">Kısa açıklama</label>
          <input type="text" id="p_desc" name="description" maxlength="190"
                 value="<?= esc((string)arr($duzenle, 'description', '')) ?>">
        </div>
        <div class="form-alan">
          <label for="p_sort">Sıra</label>
          <input type="number" id="p_sort" name="sort" min="0" max="999"
                 value="<?= (int)arr($duzenle, 'sort', 0) ?>">
        </div>
        <div class="form-alan">
          <label class="anahtar">
            <input type="checkbox" name="active" value="1" <?= (int)arr($duzenle, 'active', 0) === 1 ? 'checked' : '' ?>>
            <span class="kaydirak"></span><span>Satışta</span>
          </label>
        </div>
        <div class="dugme-grup">
          <button type="submit" class="dugme birincil">Kaydet</button>
          <?php if ($duzenle): ?>
            <a class="dugme" href="<?= esc(admin_url('payments')) ?>">Vazgeç</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="kart">
      <div class="kart-baslik">Planlar</div>
      <?php if (!$planlar): ?><div class="bos-durum">Henüz plan yok.</div><?php endif; ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <tbody>
          <?php foreach ($planlar as $p): ?>
            <tr>
              <td>
                <a href="<?= esc(admin_url('payments', ['plan' => (int)$p['id']])) ?>"><?= esc($p['name']) ?></a>
                <div class="mini soluk"><?= esc($p['code']) ?> · <?= (int)$p['days'] ?> gün</div>
              </td>
              <td class="dar"><?= esc(payment_amount_label($p['price_cents'], $p['currency'])) ?></td>
              <td class="dar"><?= (int)$p['active'] === 1 ? admin_badge('satışta', 'olumlu') : admin_badge('kapalı', 'notr') ?></td>
              <td class="islem">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="plan_sil">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button type="submit" class="dugme kucuk tehlike" data-onay="Plan silinecek. Emin misiniz?">Sil</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="kart">
      <div class="kart-baslik">Okur bağlantısı</div>
      <p class="kucuk soluk">Abonelik sayfası:</p>
      <code style="display:block;padding:8px;word-break:break-all"><?= esc(payment_page_url()) ?></code>
      <p class="mini soluk bosluk-ust">Kart bilgisi bu sisteme hiçbir zaman girmez, saklanmaz.
        Dekontlar yalnız bu panelden görüntülenebilen kapalı bir klasörde tutulur.</p>
    </div>
  </div>
</div>
