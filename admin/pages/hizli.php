<?php
/**
 * Panel — Muhabir hızlı giriş ekranı (mobil öncelikli).
 *
 * Sahadaki muhabir telefondan 60 saniyede haber geçer: başlık, spot, fotoğraf,
 * üç paragraf. Zengin editör YOK — sahada gereksiz ve yavaş.
 *
 * Muhabirde `posts.publish` izni yoktur; sunucu (inc/api/admin.php) durumu zaten
 * `pending`'e zorlar. Bu ekran da yalnız "Masaya gönder" sunar.
 *
 * Taslak 20 saniyede bir otomatik kaydedilir; bağlantı koparsa localStorage'a
 * yazılır ve şebeke gelince gönderilir — sahada kapsama zayıf olur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('posts.create');

$adminPageTitle = 'Hızlı Giriş';
$benim = current_user();

$kategoriler = all_categories();
$yayimlayabilir = can($benim, 'posts.publish');
$yayinSonrasiDuzenler = can($benim, 'posts.edit_own_published');

// Muhabirin son gönderdikleri
$sonlar = qa('SELECT p.id, p.title, p.status, p.created_at, p.slug
              FROM posts p WHERE p.author_id = :a
              ORDER BY p.id DESC LIMIT 10', [':a' => (int)$benim['id']]);
?>

<div class="hizli-sarma">
  <div class="sayfa-basligi">
    <h1>Hızlı Giriş</h1>
    <a class="dugme" href="<?= esc(admin_url('posts')) ?>">Tüm haberlerim →</a>
  </div>

  <div class="uyari bilgi hizli-bilgi">
    Sahadan hızlı haber geçmek için. Kaydettikçe otomatik taslak oluşur;
    <strong>“Masaya gönder”</strong> dediğinizde editör onayına düşer.
    <?php if (!$yayimlayabilir): ?>Yayımlama kararını masa verir.<?php endif; ?>
  </div>

  <form id="hizliForm" class="hizli-form" autocomplete="off">
    <input type="hidden" name="id" id="hzId" value="0">

    <div class="form-alan">
      <label for="hzBaslik">Başlık</label>
      <input type="text" id="hzBaslik" name="title" maxlength="200" required
             placeholder="Ne oldu? Tek cümlede yazın" enterkeyhint="next">
    </div>

    <div class="form-alan">
      <label for="hzSpot">Spot <span class="soluk">(2 cümle)</span></label>
      <textarea id="hzSpot" name="spot" rows="2" maxlength="400"
                placeholder="Kim, nerede, ne zaman?"></textarea>
    </div>

    <div class="form-alan">
      <label for="hzFoto">Fotoğraf</label>
      <input type="file" id="hzFoto" accept="image/*" capture="environment">
      <span class="form-yardim">Telefonda doğrudan kamerayı açar.</span>
      <div id="hzFotoDurum" class="mini soluk"></div>
      <div id="hzFotoOnizleme" class="gorsel-onizleme" hidden><img src="" alt=""></div>
      <input type="hidden" name="image" id="hzImage" value="">
    </div>

    <div class="form-alan">
      <label for="hzGovde">Haber</label>
      <textarea id="hzGovde" name="body" rows="8"
                placeholder="Üç paragraf yeterli. Gördüğünüzü yazın, yorum katmayın."></textarea>
    </div>

    <div class="form-alan">
      <label for="hzKategori">Kategori</label>
      <select id="hzKategori" name="category_id">
        <?php foreach ($kategoriler as $k): ?>
          <option value="<?= (int)$k['id'] ?>"><?= esc($k['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hizli-alt">
      <span id="hzDurum" class="mini soluk" role="status">—</span>
      <div class="dugme-grup">
        <button type="button" class="dugme" id="hzYeni">Yeni haber</button>
        <button type="submit" class="dugme birincil" id="hzGonder">Masaya gönder</button>
      </div>
    </div>
  </form>

  <?php if ($sonlar): ?>
    <div class="kart">
      <div class="kart-baslik">Son gönderdikleriniz</div>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th>Başlık</th><th>Durum</th><th>Tarih</th><th class="islem"></th></tr></thead>
          <tbody>
            <?php foreach ($sonlar as $s):
              list($etiket, $ton) = admin_status_label((string)$s['status']);
              $kilitli = ($s['status'] === 'published' && !$yayinSonrasiDuzenler); ?>
              <tr>
                <td><?= esc($s['title']) ?></td>
                <td class="dar"><?= admin_badge($etiket, $ton) ?></td>
                <td class="dar mini"><?= esc(tr_ago($s['created_at'])) ?></td>
                <td class="islem">
                  <?php if ($kilitli): ?>
                    <span class="mini soluk" title="Yayımlanmış haberi yalnız masa düzenleyebilir">yayında · kilitli</span>
                  <?php else: ?>
                    <a class="dugme kucuk" href="<?= esc(admin_url('hizli', ['duzenle' => (int)$s['id']])) ?>">Düzenle</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
M.hazir(function () {
  'use strict';

  var form    = document.getElementById('hizliForm');
  var durumEl = document.getElementById('hzDurum');
  var idEl    = document.getElementById('hzId');
  var kategori = document.getElementById('hzKategori');
  var TASLAK_ANAHTAR = 'manset_hizli_taslak';
  var KATEGORI_ANAHTAR = 'manset_hizli_kategori';
  var kirli = false, gonderiliyor = false, zamanlayici = null;

  function durum(metin, ton) {
    durumEl.textContent = metin;
    durumEl.className = 'mini ' + (ton === 'err' ? 'soluk hz-hata' : 'soluk');
  }

  function saat() {
    var d = new Date();
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }

  /** Alanları düz nesneye çevirir; muhabir yayımlayamaz, durum daima taslak/onay. */
  function veriTopla(gonder) {
    return {
      id: parseInt(idEl.value, 10) || 0,
      title: document.getElementById('hzBaslik').value.trim(),
      spot: document.getElementById('hzSpot').value.trim(),
      body: metinToHtml(document.getElementById('hzGovde').value),
      image: document.getElementById('hzImage').value,
      category_id: parseInt(kategori.value, 10) || 0,
      type: 'haber',
      status: gonder ? 'pending' : 'draft'
    };
  }

  /** Düz metni paragraflara çevirir (zengin editör yok). */
  function metinToHtml(metin) {
    var parcalar = String(metin || '').split(/\n{2,}/);
    return parcalar.map(function (p) {
      p = p.trim();
      if (!p) { return ''; }
      return '<p>' + p.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                      .replace(/\n/g, '<br>') + '</p>';
    }).filter(Boolean).join('\n');
  }

  /** Çevrimdışı yedek */
  function yerelYaz() {
    try {
      window.localStorage.setItem(TASLAK_ANAHTAR, JSON.stringify(veriTopla(false)));
    } catch (e) { /* kota dolu olabilir, yok say */ }
  }
  function yerelSil() {
    try { window.localStorage.removeItem(TASLAK_ANAHTAR); } catch (e) { }
  }
  function yerelOku() {
    try { return JSON.parse(window.localStorage.getItem(TASLAK_ANAHTAR) || 'null'); }
    catch (e) { return null; }
  }

  function kaydet(gonder) {
    var veri = veriTopla(gonder);
    if (!veri.title) {
      if (gonder) { durum('Başlık gerekli.', 'err'); }
      return Promise.resolve(false);
    }
    durum(gonder ? 'Gönderiliyor…' : 'Kaydediliyor…');
    return M.api('posts.save', veri).then(function (r) {
      if (!r.ok) {
        durum('Kaydedilemedi — çevrimdışı yedeklendi', 'err');
        yerelYaz();
        if (gonder) { M.toast('err', r.error || 'Gönderilemedi.'); }
        return false;
      }
      idEl.value = r.id;
      kirli = false;
      yerelSil();
      if (gonder) {
        M.toast('ok', 'Masaya gönderildi.');
        durum('Masaya gönderildi · ' + saat());
        setTimeout(function () { location.href = M.cfg.base + '/admin/?p=hizli'; }, 900);
      } else {
        durum('Kaydedildi ' + saat());
      }
      return true;
    }).catch(function () {
      durum('Bağlantı yok — çevrimdışı yedeklendi', 'err');
      yerelYaz();
      return false;
    });
  }

  /* -------------------------------------------------- otomatik kayıt */
  function zamanlaKayit() {
    kirli = true;
    clearTimeout(zamanlayici);
    zamanlayici = setTimeout(function () { if (kirli && !gonderiliyor) { kaydet(false); } }, 20000);
    yerelYaz();
  }
  ['hzBaslik', 'hzSpot', 'hzGovde'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', zamanlaKayit);
  });
  kategori.addEventListener('change', function () {
    try { window.localStorage.setItem(KATEGORI_ANAHTAR, kategori.value); } catch (e) { }
    zamanlaKayit();
  });

  /* -------------------------------------------------- fotoğraf */
  var foto = document.getElementById('hzFoto');
  foto.addEventListener('change', function () {
    if (!foto.files || !foto.files.length) { return; }
    var fd = new FormData();
    fd.append('dosya[]', foto.files[0]);
    var bilgi = document.getElementById('hzFotoDurum');
    bilgi.textContent = 'Yükleniyor…';
    M.api('media.upload', null, { formData: fd }).then(function (r) {
      if (!r.ok || !r.items || !r.items.length) {
        bilgi.textContent = (r.error || 'Fotoğraf yüklenemedi') + ' — haberi yine de gönderebilirsiniz.';
        return;
      }
      var o = r.items[0];
      document.getElementById('hzImage').value = o.filename;
      var on = document.getElementById('hzFotoOnizleme');
      on.hidden = false;
      on.querySelector('img').src = o.thumb || o.url;
      bilgi.textContent = 'Fotoğraf eklendi.';
      zamanlaKayit();
    }).catch(function () {
      bilgi.textContent = 'Fotoğraf yüklenemedi — haberi yine de gönderebilirsiniz.';
    });
  });

  /* -------------------------------------------------- gönder / yeni */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    gonderiliyor = true;
    kaydet(true).then(function () { gonderiliyor = false; });
  });
  document.getElementById('hzYeni').addEventListener('click', function () {
    if (kirli && !window.confirm('Kaydedilmemiş değişiklikler var. Yeni habere geçilsin mi?')) { return; }
    form.reset();
    idEl.value = '0';
    document.getElementById('hzImage').value = '';
    document.getElementById('hzFotoOnizleme').hidden = true;
    document.getElementById('hzFotoDurum').textContent = '';
    yerelSil();
    kirli = false;
    durum('Yeni haber');
  });

  /* -------------------------------------------------- açılış */
  try {
    var sonKategori = window.localStorage.getItem(KATEGORI_ANAHTAR);
    if (sonKategori) { kategori.value = sonKategori; }
  } catch (e) { }

  var bekleyen = yerelOku();
  if (bekleyen && bekleyen.title) {
    document.getElementById('hzBaslik').value = bekleyen.title || '';
    document.getElementById('hzSpot').value = bekleyen.spot || '';
    idEl.value = bekleyen.id || 0;
    durum('Gönderilemeyen taslak geri yüklendi', 'err');
    // Şebeke geri geldiyse hemen dene
    if (navigator.onLine) { kaydet(false); }
  }
  window.addEventListener('online', function () {
    if (yerelOku()) { durum('Bağlantı geldi, gönderiliyor…'); kaydet(false); }
  });

  window.addEventListener('beforeunload', function (e) {
    if (!kirli) { return; }
    e.preventDefault();
    e.returnValue = '';
  });
});
</script>

<style>
/* Hızlı giriş — MOBİL ÖNCELİKLİ. Sahada tek elle kullanılır: büyük hedefler, tek sütun. */
.hizli-sarma{max-width:640px;min-width:0}
/* Dar ekranda form sarmalayıcısını aşıyordu: kutu modeli ve taşma sabitlendi. */
.hizli-form{box-sizing:border-box;max-width:100%}
.hizli-bilgi{font-size:14px}
.hizli-form{background:var(--yuzey);border:1px solid var(--cizgi);border-radius:var(--kose);padding:18px;margin-bottom:18px}
.hizli-form input[type=text],.hizli-form textarea,.hizli-form select{font-size:16px;padding:12px 13px}
.hizli-form input[type=file]{font-size:15px;padding:10px 0}
.hizli-form .form-alan{margin-bottom:18px}
.hizli-form label{font-size:15px}
.hizli-alt{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  border-top:1px solid var(--cizgi);padding-top:14px}
.hizli-alt .dugme{min-height:46px;padding:12px 20px;font-size:15px}
.hz-hata{color:var(--olumsuz)}
@media(max-width:600px){
  .hizli-sarma .sayfa-basligi{margin-bottom:12px}
  .hizli-form{padding:14px;border-radius:0;margin-left:-14px;margin-right:-14px;border-left:0;border-right:0}
  .hizli-alt{position:sticky;bottom:0;background:var(--yuzey);margin:0 -14px -14px;padding:12px 14px;z-index:5}
  .hizli-alt .dugme-grup{width:100%}
  .hizli-alt .dugme{flex:1;justify-content:center}
}
</style>
