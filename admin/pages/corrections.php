<?php
/**
 * Panel — düzeltme ve cevap talepleri. (Ajan-9)
 *
 * Basın Kanunu m.14 özeti için bkz. docs/BIK-NOTLARI.md §4.
 * Yayımlanan metin haberin gövdesine YAZILMAZ; corrections tablosunda durur ve
 * süresi (ana sayfada 24 saat, haber altında 7 gün) dolunca kendiliğinden kalkar.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
// Faz 7'de `corrections.manage` ikiye ayrıldı: triage (sınıflandırma) ve
// publish (yayımlama, sorumlu müdür kapısıyla). Ekrana giriş triage ister.
require_can('corrections.triage');

$adminPageTitle = 'Düzeltme Talepleri';

$durumlar = [
    'pending'  => 'Bekleyen',
    'answered' => 'Yanıtlanan',
    'rejected' => 'Reddedilen',
    'all'      => 'Tümü',
    CORRECTION_KIND_UPDATE => 'Güncelleme notları',
];

$durum = (string)inp('durum', 'pending');
if (!isset($durumlar[$durum])) { $durum = 'pending'; }
$guncellemeSekmesi = ($durum === CORRECTION_KIND_UPDATE);

// ---------------------------------------------------------------- ayar kaydı
// Yasal süre eşiği KODA GÖMÜLÜ DEĞİLDİR (mevzuat değişebilir). Yalnız genel
// ayar yetkisi olan kullanıcı değiştirebilir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'sure_ayar') {
    csrf_guard();
    require_can('settings.manage');
    $saat = max(1, min(720, inp_i('deadline_hours', corrections_deadline_hours())));
    setting_set_many([
        'corrections_deadline_hours' => (string)$saat,
        'corrections_notify_enabled' => inp('notify', '') === '1' ? '1' : '0',
    ]);
    settings_all(true);
    if (function_exists('cache_flush')) { cache_flush(); }
    flash('ok', 'Düzeltme ayarları kaydedildi. Yanıt süresi eşiği: ' . $saat . ' saat.');
    header('Location: ' . admin_url('corrections', ['durum' => $durum]), true, 302);
    exit;
}

$esikSaat   = corrections_deadline_hours();
$bildirimAcik = corrections_mail_available();

$sayfa   = max(1, inp_i('sayfa', 1));
$perPage = 30;
$filtre  = ['status' => $durum];

$sayaclar = corrections_counts();
$toplam   = corrections_count($filtre);
$talepler = corrections_list($filtre, $perPage, ($sayfa - 1) * $perPage);
$geciken  = corrections_overdue_count();

// Modal için satır verisi (JS tarafında kullanılır)
$jsTalepler = [];
foreach ($talepler as $t) {
    $jsTalepler[(string)(int)$t['id']] = [
        'id'         => (int)$t['id'],
        'name'       => (string)$t['name'],
        'email'      => (string)$t['email'],
        'message'    => (string)$t['message'],
        'answer'     => (string)arr($t, 'answer', ''),
        'note'       => (string)arr($t, 'note', ''),
        'status'     => (string)$t['status'],
        'post_title' => (string)arr($t, 'post_title', ''),
        'created_at' => tr_date((string)$t['created_at']),
    ];
}
$jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?php if ($geciken > 0): ?>
  <div class="uyari err" role="alert">
    <strong>GECİKTİ — süresi geçmiş <?= (int)$geciken ?> bekleyen talep var.</strong>
    Sitede tanımlı yanıt süresi eşiği <strong><?= (int)$esikSaat ?> saat</strong>tir; bu talepler
    eşiği aşmıştır. Düzeltme ve cevap yazısının yayımlanması yasal bir yükümlülüktür
    (bkz. <code>docs/BIK-NOTLARI.md</code> §4).
  </div>
<?php endif; ?>

<div class="sayfa-basligi">
  <h1>Düzeltme Talepleri</h1>
  <?php if (setting('corrections_enabled', '1') !== '1'): ?>
    <span class="rozet uyari">Talep formu site genelinde kapalı</span>
  <?php endif; ?>
</div>

<?php if ($guncellemeSekmesi): ?>
  <div class="uyari bilgi">
    <strong>Güncelleme notu, düzeltme/tekzip DEĞİLDİR.</strong>
    Yayımlanmış bir haber sonradan değiştirildiğinde okura görünür kılınan
    <em>editoryal</em> açıklamadır; yasal yanıt süresine tabi değildir ve süresiz görünür.
    Okuyucunun düzeltme ve cevap talebi ise diğer sekmelerde yönetilir.
  </div>
<?php else: ?>
  <div class="uyari warn">
    Basın Kanunu m.14 uyarınca düzeltme ve cevap yazısı, alındığı tarihten itibaren
    <strong>süresi içinde</strong>, aynı puntolarla ve URL bağlantısı sağlanarak yayımlanır.
    Kaynak belgedeki özet süre <strong>bir gün</strong>dür; bu ekranda kullanılan eşik
    <strong><?= (int)$esikSaat ?> saat</strong> olarak ayarlanmıştır.
    Yayımlanan metin ilgili haberin altında <strong>bir hafta</strong>, ana sayfada ise
    <strong>ilk 24 saat</strong> görünür kalır.
    <span class="mini soluk">(Özet: <code>docs/BIK-NOTLARI.md</code> §4 — hukuki görüş değildir;
    <strong>yayıncı yürürlükteki güncel yönetmeliği teyit etmelidir</strong>.)</span>
  </div>
<?php endif; ?>

<?php if (can(current_user(), 'settings.manage')): ?>
  <details class="kart bosluk-alt">
    <summary><strong>Süre ve bildirim ayarları</strong></summary>
    <form method="post" action="<?= esc(admin_url('corrections', ['durum' => $durum])) ?>" class="form-satir">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="sure_ayar">
      <label class="form-alan" style="max-width:280px">
        Yanıt süresi eşiği (saat)
        <input type="number" name="deadline_hours" min="1" max="720" value="<?= (int)$esikSaat ?>">
        <span class="form-yardim">Süre rozetleri ve “GECİKTİ” uyarısı bu değere göre hesaplanır.
        Mevzuat değişirse buradan güncelleyin; koda gömülü değildir.</span>
      </label>
      <label class="form-alan">
        <input type="checkbox" name="notify" value="1"
               <?= setting('corrections_notify_enabled', '1') === '1' ? 'checked' : '' ?>>
        Başvurana sonuç e-postası gönder
        <span class="form-yardim">Tek alıcılı, işlemsel bildirim: talep yayımlandığında ya da
        reddedildiğinde başvurana gönderilir. Toplu gönderim yapılmaz.
        <?= $bildirimAcik ? '' : '<strong>Şu anda sunucuda e-posta gönderimi kapalı; gönderim sessizce atlanır.</strong>' ?></span>
      </label>
      <p><button type="submit" class="dugme birincil">Kaydet</button></p>
    </form>
  </details>
<?php endif; ?>

<nav class="sekmeler" aria-label="Talep durumu">
  <?php foreach ($durumlar as $k => $etiket): ?>
    <a href="<?= esc(admin_url('corrections', ['durum' => $k])) ?>" class="<?= $k === $durum ? 'etkin' : '' ?>">
      <?= esc($etiket) ?> <span class="soluk">(<?= (int)arr($sayaclar, $k, 0) ?>)</span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($guncellemeSekmesi && can(current_user(), 'posts.edit_any')): ?>
  <p class="bosluk-alt">
    <button type="button" class="dugme birincil" id="notEkle">Güncelleme notu ekle</button>
    <span class="mini soluk">Haber numarasını Haberler ekranından alabilirsiniz.</span>
  </p>
<?php endif; ?>

<?php if (!$talepler): ?>
  <div class="bos-durum">
    <span class="buyuk" aria-hidden="true"><?= $guncellemeSekmesi ? '✎' : '⚖' ?></span>
    <?php if ($guncellemeSekmesi): ?>
      <h3>Güncelleme notu yok</h3>
      <p>Yayımlanmış bir haber sonradan değiştirildiğinde, okura görünür bir
         “Güncelleme” notu düşebilirsiniz. Bu not düzeltme/tekzip değildir.</p>
    <?php else: ?>
      <h3><?= $durum === 'pending' ? 'Bekleyen talep yok' : 'Bu listede talep yok' ?></h3>
      <p>Okuyucular haber sayfalarındaki “Düzeltme talep et” formunu kullandığında talepler burada listelenir.</p>
    <?php endif; ?>
  </div>
<?php else: ?>

  <div class="arac-cubugu">
    <span class="kucuk soluk"><?= (int)$toplam ?> <?= $guncellemeSekmesi ? 'güncelleme notu' : 'talep' ?></span>
    <?php if (!$guncellemeSekmesi): ?>
      <span class="sag kucuk soluk">Süre rozeti, talebin alındığı andan itibaren
        <?= (int)$esikSaat ?> saatlik yanıt süresini gösterir.</span>
    <?php endif; ?>
  </div>

  <div class="tablo-sarma">
    <table class="tablo">
      <thead>
        <tr>
          <th class="dar"><?= $guncellemeSekmesi ? 'Durum' : 'Süre / yaş' ?></th>
          <th><?= $guncellemeSekmesi ? 'Not' : 'Talep' ?></th>
          <th class="dar"><?= $guncellemeSekmesi ? 'Ekleyen' : 'Başvuran' ?></th>
          <th class="dar">Haber</th>
          <th class="dar">Tarih</th>
          <th class="islem">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($talepler as $t):
          $sure = corrections_deadline_state($t);
          list($dEtiket, $dTon) = corrections_status_label((string)$t['status']);
          $kisa = excerpt((string)$t['message'], 140);
          $uzunMu = mb_strlen((string)$t['message']) > mb_strlen($kisa); ?>
          <tr data-talep="<?= (int)$t['id'] ?>">
            <td class="dar">
              <?php if ($sure['durum'] === 'gecti' && (string)$t['status'] === 'pending'): ?>
                <?= admin_badge('GECİKTİ', 'olumsuz') ?>
              <?php endif; ?>
              <?= admin_badge($sure['etiket'], $sure['ton']) ?>
              <?php if (!$guncellemeSekmesi): ?>
                <span class="satir-alt mini soluk">Yaş: <?= esc((string)arr($sure, 'yas', '')) ?></span>
              <?php endif; ?>
              <span class="satir-alt mini"><?= admin_badge($dEtiket, $dTon) ?></span>
              <?php $isleyen = trim((string)arr($t, 'handled_name', '')); ?>
              <?php if ($isleyen !== ''): ?>
                <span class="satir-alt mini soluk" title="corrections.handled_by">
                  İşlem: <?= esc($isleyen) ?>
                </span>
              <?php elseif ((int)arr($t, 'handled_by', 0) > 0): ?>
                <span class="satir-alt mini soluk">İşlem: #<?= (int)$t['handled_by'] ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div class="talep-govde"><?= nl2br(esc($kisa)) ?></div>
              <?php if ($uzunMu): ?>
                <details class="mini">
                  <summary>Tamamını göster</summary>
                  <div class="talep-govde"><?= nl2br(esc((string)$t['message'])) ?></div>
                </details>
              <?php endif; ?>
              <?php if (trim((string)arr($t, 'answer', '')) !== ''): ?>
                <details class="mini bosluk-ust">
                  <summary>Yayımlanan düzeltme metni</summary>
                  <div class="talep-govde"><?= sanitize_html((string)$t['answer'], false) ?></div>
                  <p class="mini soluk">
                    Yayım: <?= esc(tr_date((string)arr($t, 'publish_at', ''))) ?> ·
                    Ana sayfada: <?= esc(tr_date((string)arr($t, 'homepage_until', ''))) ?>'e kadar ·
                    Haber altında: <?= esc(tr_date((string)arr($t, 'visible_until', ''))) ?>'e kadar
                  </p>
                </details>
              <?php endif; ?>
              <?php if (trim((string)arr($t, 'note', '')) !== ''): ?>
                <p class="mini soluk">Dâhilî not: <?= esc((string)$t['note']) ?></p>
              <?php endif; ?>
            </td>
            <td class="dar">
              <strong><?= esc((string)$t['name']) ?></strong>
              <?php if (trim((string)$t['email']) !== ''): ?>
                <span class="satir-alt tek-satir"><?= esc((string)$t['email']) ?></span>
              <?php endif; ?>
              <?php if (trim((string)$t['ip']) !== ''): ?>
                <span class="satir-alt mini">IP: <?= esc((string)$t['ip']) ?></span>
              <?php endif; ?>
            </td>
            <td class="dar">
              <?php if ((string)arr($t, 'post_title', '') !== ''): ?>
                <a href="<?= esc(url_post(['id' => (int)$t['post_id'], 'slug' => (string)$t['post_slug']])) ?>"
                   target="_blank" rel="noopener" class="tek-satir" style="display:inline-block;max-width:180px">
                  <?= esc((string)$t['post_title']) ?> ↗
                </a>
                <span class="satir-alt">
                  <a href="<?= esc(admin_url('posts', ['duzenle' => (int)$t['post_id']])) ?>">düzenle</a>
                </span>
              <?php else: ?>
                <span class="soluk mini">silinmiş haber</span>
              <?php endif; ?>
            </td>
            <td class="dar mini soluk" title="<?= esc(tr_date((string)$t['created_at'])) ?>">
              <?= esc(tr_ago((string)$t['created_at'])) ?>
            </td>
            <td class="islem">
              <?php if ($guncellemeSekmesi): ?>
                <button type="button" class="dugme kucuk tehlike" data-notsil="<?= (int)$t['id'] ?>">Notu sil</button>
              <?php else: ?>
                <button type="button" class="dugme kucuk birincil" data-yanitla="<?= (int)$t['id'] ?>">
                  <?= (string)$t['status'] === 'answered' ? 'Yanıtı düzenle' : 'Yanıtla' ?>
                </button>
                <?php if ((string)$t['status'] !== 'rejected'): ?>
                  <button type="button" class="dugme kucuk" data-reddet="<?= (int)$t['id'] ?>">Reddet</button>
                <?php endif; ?>
                <button type="button" class="dugme kucuk tehlike" data-sil="<?= (int)$t['id'] ?>"
                        data-onay="Talep kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= admin_paginate($toplam, $perPage, $sayfa, 'corrections', ['durum' => $durum]) ?>
<?php endif; ?>

<style>
/* Uzun talep metni tabloda okunabilir kalsın; admin.css'te metin bloğu sınıfı yok. */
.talep-govde{margin-top:5px;font-size:13.5px;line-height:1.55;max-width:560px;word-break:break-word}
.talep-govde p{margin:0 0 6px}
</style>

<script>
M.hazir(function () {
  var TALEPLER = <?= json_encode($jsTalepler, $jsFlags) ?>;

  function kutu(baslik, icerik) {
    return '<div class="uyari bilgi" style="margin-bottom:12px"><strong>' + M.esc(baslik) + '</strong>'
         + '<div style="margin-top:6px;white-space:pre-wrap">' + M.esc(icerik) + '</div></div>';
  }

  /* ------------------------------------------------ yanıtla / yayımla */
  M.qsa('[data-yanitla]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-yanitla');
      var t = TALEPLER[id];
      if (!t) { return; }
      var govde = M.modal.ac('Düzeltme ve cevap metnini yayımla', ''
        + kutu(t.name + ' · ' + t.email + ' · ' + t.created_at, t.message)
        + '<div class="form-alan">'
        + '  <label for="cevapMetni">Yayımlanacak düzeltme / cevap metni</label>'
        + '  <textarea id="cevapMetni" rows="8"></textarea>'
        + '  <span class="form-yardim">Metin haberin gövdesine yazılmaz; ayrı tutulur ve süresi dolunca '
        + '  kendiliğinden kalkar. En az 20 karakter.</span>'
        + '</div>'
        + '<div class="uyari bilgi">Yayımlanınca metin <strong>haber altında 7 gün</strong>, '
        + '<strong>ana sayfada 24 saat</strong> görünecek.</div>'
        + '<div class="dugme-grup"><button type="button" class="dugme birincil" id="cevapYayimla">Yayımla</button>'
        + '<button type="button" class="dugme" id="cevapVazgec">Vazgeç</button></div>', '660px');

      var alan = govde.querySelector('#cevapMetni');
      alan.value = t.answer ? t.answer.replace(/<[^>]*>/g, '') : '';
      alan.focus();
      govde.querySelector('#cevapVazgec').addEventListener('click', M.modal.kapat);
      govde.querySelector('#cevapYayimla').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        M.api('corrections.publish', { id: parseInt(id, 10), answer: alan.value }).then(function (r) {
          if (M.sonuc(r, 'Düzeltme metni yayımlandı.')) {
            M.modal.kapat();
            setTimeout(function () { location.reload(); }, 500);
          } else { btn.disabled = false; }
        });
      });
    });
  });

  /* ------------------------------------------------ reddet */
  M.qsa('[data-reddet]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.getAttribute('data-reddet');
      var t = TALEPLER[id];
      if (!t) { return; }
      var govde = M.modal.ac('Talebi reddet', ''
        + kutu(t.name + ' · ' + t.email + ' · ' + t.created_at, t.message)
        + '<div class="form-alan">'
        + '  <label for="retNotu">Ret gerekçesi (dâhilî not)</label>'
        + '  <textarea id="retNotu" rows="4"></textarea>'
        + '  <span class="form-yardim">Bu not <strong>yayımlanmaz</strong>, yalnız panelde görünür.</span>'
        + '</div>'
        + '<div class="dugme-grup"><button type="button" class="dugme tehlike" id="retOnay">Reddet</button>'
        + '<button type="button" class="dugme" id="retVazgec">Vazgeç</button></div>', '600px');

      var alan = govde.querySelector('#retNotu');
      alan.value = t.note || '';
      alan.focus();
      govde.querySelector('#retVazgec').addEventListener('click', M.modal.kapat);
      govde.querySelector('#retOnay').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        M.api('corrections.reject', { id: parseInt(id, 10), note: alan.value }).then(function (r) {
          if (M.sonuc(r, 'Talep reddedildi.')) {
            M.modal.kapat();
            setTimeout(function () { location.reload(); }, 500);
          } else { btn.disabled = false; }
        });
      });
    });
  });

  /* ------------------------------------------------ güncelleme notu ekle
     Editoryal not — düzeltme/tekzip DEĞİLDİR, yasal süreye tabi değildir. */
  var notEkleDugme = document.getElementById('notEkle');
  if (notEkleDugme) {
    notEkleDugme.addEventListener('click', function () {
      var govde = M.modal.ac('Güncelleme notu ekle', ''
        + '<div class="uyari bilgi">Bu not <strong>düzeltme/tekzip değildir</strong>. '
        + 'Yayımlanmış bir haberin sonradan değiştirildiğini okura bildirir ve haberin altında '
        + 'süresiz görünür.</div>'
        + '<div class="form-alan">'
        + '  <label for="notPostId">Haber numarası (ID)</label>'
        + '  <input type="number" id="notPostId" min="1" step="1">'
        + '</div>'
        + '<div class="form-alan">'
        + '  <label for="notMetni">Güncelleme notu</label>'
        + '  <textarea id="notMetni" rows="5" '
        + '    placeholder="Örn. Haberin ikinci paragrafındaki rakam kurum açıklamasıyla güncellendi."></textarea>'
        + '  <span class="form-yardim">En az 5 karakter. Düz metin girin; satır sonları korunur.</span>'
        + '</div>'
        + '<div class="dugme-grup"><button type="button" class="dugme birincil" id="notKaydet">Ekle</button>'
        + '<button type="button" class="dugme" id="notVazgec">Vazgeç</button></div>', '620px');

      govde.querySelector('#notVazgec').addEventListener('click', M.modal.kapat);
      govde.querySelector('#notKaydet').addEventListener('click', function () {
        var btn = this;
        btn.disabled = true;
        M.api('corrections.update_add', {
          post_id: parseInt(govde.querySelector('#notPostId').value, 10) || 0,
          text: govde.querySelector('#notMetni').value
        }).then(function (r) {
          if (M.sonuc(r, 'Güncelleme notu eklendi.')) {
            M.modal.kapat();
            setTimeout(function () { location.reload(); }, 500);
          } else { btn.disabled = false; }
        });
      });
    });
  }

  /* ------------------------------------------------ güncelleme notunu sil */
  M.qsa('[data-notsil]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (!confirm('Güncelleme notu silinecek. Onaylıyor musunuz?')) { return; }
      b.disabled = true;
      M.api('corrections.update_delete', { id: parseInt(b.getAttribute('data-notsil'), 10) }).then(function (r) {
        if (M.sonuc(r, 'Güncelleme notu silindi.')) { setTimeout(function () { location.reload(); }, 450); }
        else { b.disabled = false; }
      });
    });
  });

  /* ------------------------------------------------ sil */
  M.qsa('[data-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-sil'), 10);
      b.disabled = true;
      M.api('corrections.delete', { id: id }).then(function (r) {
        if (M.sonuc(r, 'Talep silindi.')) { setTimeout(function () { location.reload(); }, 450); }
        else { b.disabled = false; }
      });
    });
  });
});
</script>
