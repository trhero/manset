<?php
/**
 * Panel — Bülten abone listesi (Ajan-F, 1.2-11).
 *
 * İzin: `newsletter.manage` (1.2 sözleşmesi §4 — chief_editor + admin).
 *
 * KVKK: bu ekrandaki her satır kişisel veridir. Dışa aktarma `audit_log`'a
 * yazılır (`newsletter.export` ucu); silme de öyle. Ham onay/çıkış token'ları
 * veritabanında YOKTUR (yalnız SHA-256 özetleri) — bu yüzden panel bir abonenin
 * çıkış bağlantısını gösteremez, yalnız kaydı silebilir. Bu bilinçlidir.
 *
 * Toplu GÖNDERİM yoktur (CONTRACTS §13): ekranda "bülten gönder" düğmesi
 * aranmamalıdır, kapsam dışıdır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('newsletter.manage');

$adminPageTitle = 'Bülten';

if (is_file(INC_DIR . '/newsletter.php')) { require_once INC_DIR . '/newsletter.php'; }

$hazir = function_exists('newsletter_ready') && newsletter_ready();
$istatistik = $hazir ? newsletter_stats() : ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'last' => ''];

$durum = (string)inp_s('durum', 'hepsi');
if (!in_array($durum, ['hepsi', 'onayli', 'bekleyen'], true)) { $durum = 'hepsi'; }
$kategori = inp_i('kategori', 0);
$ara = sanitize_line((string)inp_s('q', ''), 120);
$sayfa = max(1, inp_i('sayfa', 1));
$perPage = 50;

$sonuc = $hazir
    ? newsletter_query(['durum' => $durum, 'kategori' => $kategori, 'q' => $ara,
                        'limit' => $perPage, 'offset' => ($sayfa - 1) * $perPage])
    : ['items' => [], 'total' => 0];

$kategoriler = $hazir ? qa('SELECT id, name FROM categories ORDER BY sort, name') : [];
$kategoriSecenek = [];
foreach ($kategoriler as $k) { $kategoriSecenek[(int)$k['id']] = (string)$k['name']; }

$disaAktarBaglanti = function ($bicim) use ($durum, $kategori, $ara) {
    return base_url() . '/api.php?a=newsletter.export&bicim=' . rawurlencode($bicim)
         . '&durum=' . rawurlencode($durum)
         . '&kategori=' . (int)$kategori
         . '&q=' . rawurlencode($ara);
};
?>

<div class="sayfa-basligi">
  <h1>Bülten</h1>
  <a class="dugme" href="<?= esc(admin_url('settings')) ?>">Bülten ayarları &rarr;</a>
</div>

<?php if (!$hazir): ?>
  <div class="uyari err">
    Bülten tablosu hazır değil. <a href="<?= esc(admin_url('tools')) ?>">Araçlar → Bakım</a> ekranından
    "Göçleri uygula" düğmesine basın (göç <code>020_newsletter_backup</code>).
  </div>
<?php else: ?>

<div class="izgara-4 bosluk-alt">
  <div class="sayac vurgulu"><div class="deger"><?= (int)$istatistik['confirmed'] ?></div><div class="etiket">Onaylı abone</div></div>
  <div class="sayac"><div class="deger"><?= (int)$istatistik['pending'] ?></div><div class="etiket">Onay bekleyen</div></div>
  <div class="sayac"><div class="deger"><?= (int)$istatistik['total'] ?></div><div class="etiket">Toplam kayıt</div></div>
  <div class="sayac"><div class="deger"><?= esc($istatistik['last'] !== '' ? tr_ago($istatistik['last']) : '—') ?></div><div class="etiket">Son kayıt</div></div>
</div>

<div class="uyari bilgi">
  <strong>Çift onay açık.</strong> Formdan gelen adrese onay bağlantısı gönderilir;
  bağlantıya tıklanana kadar <code>confirmed</code> sütunu 0 kalır ve adres "onaylı" listesine girmez.
  Onaylanmamış kayıtlar <?= (int)setting('newsletter_unconfirmed_days', '30') ?> gün sonra
  kendiliğinden silinir.
  <br>Toplu bülten <em>gönderimi</em> bu yazılımın kapsamı dışındadır; liste dışa aktarılıp
  Mailchimp/Sendy gibi bir servise verilir.
</div>

<div class="kart">
  <div class="kart-baslik">Süzgeç</div>
  <form method="get" action="<?= esc(admin_url('newsletter')) ?>" class="arac-cubugu">
    <input type="hidden" name="p" value="newsletter">
    <div class="form-alan">
      <label for="fDurum">Durum</label>
      <select id="fDurum" name="durum">
        <?= admin_options(['hepsi' => 'Hepsi', 'onayli' => 'Onaylı', 'bekleyen' => 'Onay bekleyen'], $durum) ?>
      </select>
    </div>
    <div class="form-alan">
      <label for="fKategori">Kategori ilgisi</label>
      <select id="fKategori" name="kategori">
        <?= admin_options($kategoriSecenek, $kategori, 'Farketmez') ?>
      </select>
    </div>
    <div class="form-alan">
      <label for="fAra">E-posta ara</label>
      <input type="search" id="fAra" name="q" value="<?= esc($ara) ?>" maxlength="120">
    </div>
    <button type="submit" class="dugme">Uygula</button>
  </form>
</div>

<div class="kart">
  <div class="kart-baslik">Dışa aktarma (CSV)</div>
  <p class="kucuk soluk">
    Süzgeçte ne varsa o indirilir. <strong>Her indirme denetim kaydına yazılır</strong>
    (kim, ne zaman, kaç satır) — abone e-postası kişisel veridir.
  </p>
  <div class="dugme-grup">
    <a class="dugme birincil" href="<?= esc($disaAktarBaglanti('mailchimp')) ?>">Mailchimp CSV</a>
    <a class="dugme" href="<?= esc($disaAktarBaglanti('sendy')) ?>">Sendy CSV</a>
    <a class="dugme" href="<?= esc($disaAktarBaglanti('ham')) ?>">Ham liste (tüm sütunlar)</a>
  </div>
  <p class="mini soluk bosluk-ust">
    Mailchimp dosyası <code>Email Address, First Name, Last Name, Tags</code> sütunlarını taşır;
    kategori ilgisi <code>Tags</code> alanına yazılır, böylece içe aktarımdan sonra segment kurulabilir.
    Sendy dosyası <code>Name, Email</code> bekler.
  </p>
</div>

<div class="kart">
  <div class="kart-baslik">Aboneler (<?= (int)$sonuc['total'] ?>)</div>
  <div class="tablo-sarma">
    <table class="tablo">
      <thead>
        <tr><th>E-posta</th><th class="dar">Durum</th><th>Kategori ilgisi</th>
            <th class="dar">Kaynak</th><th class="dar">Kayıt</th><th class="islem">İşlem</th></tr>
      </thead>
      <tbody>
      <?php if (!$sonuc['items']): ?>
        <tr><td colspan="6" class="bos-durum">Süzgece uyan abone yok.</td></tr>
      <?php endif; ?>
      <?php foreach ($sonuc['items'] as $r): ?>
        <?php $etiketler = newsletter_categories_labels(arr($r, 'categories', '')); ?>
        <tr data-abone="<?= (int)$r['id'] ?>">
          <td class="tek-satir"><?= esc($r['email']) ?></td>
          <td class="dar">
            <?= (int)$r['confirmed'] === 1
                  ? admin_badge('onaylı', 'olumlu')
                  : admin_badge('bekliyor', 'uyari') ?>
          </td>
          <td class="kucuk">
            <?= $etiketler ? esc(implode(', ', $etiketler)) : '<span class="soluk">—</span>' ?>
          </td>
          <td class="dar kucuk soluk"><?= esc((string)arr($r, 'source', '')) ?></td>
          <td class="dar kucuk soluk"><?= esc(tr_date((string)$r['created_at'], false)) ?></td>
          <td class="islem">
            <button type="button" class="dugme kucuk" data-segment="<?= (int)$r['id'] ?>">Kategoriler</button>
            <button type="button" class="dugme kucuk tehlike" data-sil="<?= (int)$r['id'] ?>"
              data-onay="Bu abone listeden kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?= admin_paginate((int)$sonuc['total'], $perPage, $sayfa, 'newsletter',
        ['durum' => $durum, 'kategori' => $kategori, 'q' => $ara]) ?>
</div>

<script>
M.hazir(function () {
  var kategoriler = <?= json_encode($kategoriSecenek, JSON_UNESCAPED_UNICODE) ?>;

  M.qsa('[data-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-sil'), 10);
      M.api('newsletter.delete', { id: id }).then(function (r) {
        if (M.sonuc(r, 'Abone silindi.')) {
          var satir = M.qs('[data-abone="' + id + '"]');
          if (satir) { satir.remove(); }
        }
      });
    });
  });

  M.qsa('[data-segment]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-segment'), 10);
      var satir = M.qs('[data-abone="' + id + '"]');
      var mevcut = satir ? (satir.children[2].textContent || '').trim() : '';
      var html = '<p class="kucuk soluk">Abonenin hangi kategorilerle ilgilendiğini işaretleyin. '
               + 'Bu bilgi dışa aktarmada etiket olarak çıkar.</p><div class="form-alan">';
      Object.keys(kategoriler).forEach(function (k) {
        var ad = kategoriler[k];
        var secili = mevcut.indexOf(ad) !== -1 ? ' checked' : '';
        html += '<label class="onay-satir"><input type="checkbox" value="' + k + '"' + secili + '> '
              + M.esc(ad) + '</label>';
      });
      html += '</div><button type="button" class="dugme birincil" data-kaydet>Kaydet</button>';
      var govde = M.modal.ac('Kategori ilgisi', html, 420);
      M.qs('[data-kaydet]', govde).addEventListener('click', function () {
        var secilenler = [];
        M.qsa('input[type=checkbox]', govde).forEach(function (c) {
          if (c.checked) { secilenler.push(parseInt(c.value, 10)); }
        });
        M.api('newsletter.segment', { id: id, kategoriler: secilenler }).then(function (r) {
          if (M.sonuc(r, 'Kategori ilgisi güncellendi.')) {
            if (satir) { satir.children[2].textContent = (r.categories || []).join(', ') || '—'; }
            M.modal.kapat();
          }
        });
      });
    });
  });
});
</script>

<?php endif; ?>
