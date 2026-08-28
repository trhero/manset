<?php
/**
 * Panel — medya kütüphanesi (Ajan-2 · 1.3-08 ekleri Ajan-C).
 * Yükleme (çoklu, sürükle-bırak), arama, alt metin düzenleme, silme, sayfalama.
 *
 * Silme yetkisi: v1'de media tablosunda "yükleyen" sütunu olmadığı için
 * kimin hangi dosyayı yüklediği bilinemiyor. Bu yüzden silme yalnız
 * media.delete_any iznine bağlandı (yazar rolü silemez).
 *
 * 1.3-08 EKLERİ
 *   · Varyant kuyruğu göstergesi + elle işleme düğmesi (cron kuramayan hosting).
 *   · Alt metni boş görseller süzgeci (erişilebilirlik uyarısı).
 *   · Toplu seçim → toplu alt metin, toplu silme (onaylı).
 *   · Odak noktası: kırpmada korunacak nokta, önizleme üstüne tıklanarak.
 *   · Kullanılmayan görsel bulucu — ÖNCE SAYAR VE GÖSTERİR, sonra onay ister.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('media.manage');

$adminPageTitle = 'Medya';
$me = current_user();
$silebilir = can($me, 'media.delete_any');

$ara = sanitize_line((string)inp('q', ''), 120);
$suzgec = (string)inp('suzgec', '');
if (!in_array($suzgec, ['', 'noalt', 'queued'], true)) { $suzgec = ''; }
$sayfa = max(1, inp_i('sayfa', 1));
$perPage = 24;

$toplam = media_count($ara, $suzgec);
$ogeler = media_list($ara, $perPage, ($sayfa - 1) * $perPage, $suzgec);
$toplamBoyut = media_total_size();
$toplamAdet = media_count('');
$maxMb = (int)round(media_max_bytes() / 1048576);
$gdVar = media_gd_available();

// 1.3-08 göstergeleri
$kuyrukVar = media_has_queue();
$bekleyen = media_queue_size();
$altEksik = media_missing_alt_count();

/** Süzgeç bağlantısı için sorgu dizisi. */
$suzgecSorgu = [];
if ($ara !== '') { $suzgecSorgu['q'] = $ara; }
if ($suzgec !== '') { $suzgecSorgu['suzgec'] = $suzgec; }
?>

<div class="sayfa-basligi">
  <h1>Medya kütüphanesi</h1>
  <div class="esnek kucuk soluk">
    <span><?= (int)$toplamAdet ?> dosya</span>
    <span>·</span>
    <span><?= esc(media_human_size($toplamBoyut)) ?> toplam</span>
  </div>
</div>

<?php if (!$gdVar): ?>
  <div class="uyari warn">
    Sunucuda <strong>GD</strong> eklentisi yok. Yükleme çalışır ancak küçültülmüş görsel
    türevleri (thumb/medium/large) üretilemez; her yerde orijinal dosya kullanılır.
  </div>
<?php endif; ?>

<?php if (!$kuyrukVar): ?>
  <div class="uyari warn">
    Medya güncellemesi (göç <code>025_media_v2</code>) henüz uygulanmamış. Varyant kuyruğu,
    odak noktası ve alt metin süzgeci bu güncelleme uygulanana kadar çalışmaz.
  </div>
<?php endif; ?>

<?php if ($kuyrukVar && $bekleyen > 0): ?>
  <div class="uyari bilgi" id="kuyrukKutu">
    <strong><?= (int)$bekleyen ?> görselin</strong> büyük boyutları (medium/large + WebP) arka planda
    üretilmeyi bekliyor. Yükleme isteği hızlı bitsin diye bunlar <em>zamanlı göreve</em> bırakılır.
    Bu sürede sayfalar <strong>özgün görseli</strong> kullanır — hiçbir yerde kırık görsel çıkmaz,
    yalnız dosya büyüktür.
    <?php if ($gdVar): ?>
      <button type="button" class="dugme kucuk" id="kuyrukIsle">Şimdi üret</button>
      <span class="kucuk soluk" id="kuyrukDurum" role="status"></span>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($altEksik > 0 && $suzgec !== 'noalt'): ?>
  <div class="uyari warn">
    <strong><?= (int)$altEksik ?> görselin alt metni boş.</strong>
    Alt metin, görseli göremeyen okuyucuların (ekran okuyucu, görsel yüklenmeyen bağlantı)
    haberi anlamasını sağlar; arama motoru görsel aramasında da bunu okur.
    <a href="<?= esc(admin_url('media', ['suzgec' => 'noalt'])) ?>">Eksikleri listele →</a>
  </div>
<?php endif; ?>

<div class="kart">
  <div class="kart-baslik">Yükle</div>
  <div id="birakAlani" class="birak-alani" tabindex="0" role="button"
       aria-label="Dosya seçmek için tıklayın veya dosyaları buraya sürükleyin">
    <span class="birak-ikon" aria-hidden="true">⬆</span>
    <strong>Dosyaları buraya sürükleyin</strong>
    <span class="soluk kucuk">ya da seçmek için tıklayın — JPG, PNG, GIF, WEBP · en çok <?= (int)$maxMb ?> MB</span>
    <input type="file" id="dosyaSec" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
  </div>
  <div class="form-alan bosluk-ust">
    <label for="topluAlt">Yüklenecek dosyalar için ortak alt metin (isteğe bağlı)</label>
    <input type="text" id="topluAlt" maxlength="255" placeholder="Görseli görmeyen okuyucular için kısa açıklama">
  </div>
  <div id="yuklemeDurum" class="kucuk soluk" role="status"></div>
</div>

<form class="arac-cubugu" method="get" action="<?= esc(base_url()) ?>/admin/">
  <input type="hidden" name="p" value="media">
  <input type="search" name="q" value="<?= esc($ara) ?>" placeholder="Dosya adı veya alt metin…" aria-label="Medya ara">
  <select name="suzgec" aria-label="Süzgeç">
    <option value=""<?= $suzgec === '' ? ' selected' : '' ?>>Tümü</option>
    <option value="noalt"<?= $suzgec === 'noalt' ? ' selected' : '' ?>>Alt metni boş olanlar</option>
    <option value="queued"<?= $suzgec === 'queued' ? ' selected' : '' ?>>Türevi bekleyenler</option>
  </select>
  <button type="submit" class="dugme">Ara</button>
  <?php if ($ara !== '' || $suzgec !== ''): ?>
    <a class="dugme" href="<?= esc(admin_url('media')) ?>">Temizle</a>
    <span class="kucuk soluk"><?= (int)$toplam ?> sonuç</span>
  <?php endif; ?>
</form>

<?php if ($ogeler): ?>
  <div class="arac-cubugu" id="topluCubuk">
    <label class="kucuk">
      <input type="checkbox" id="hepsiniSec"> Bu sayfadakilerin hepsini seç
    </label>
    <span class="kucuk soluk" id="secimSayi" role="status">0 seçili</span>
    <input type="text" id="topluAltMetin" maxlength="255" placeholder="Seçililere alt metin yaz…"
           aria-label="Seçili görsellere yazılacak alt metin">
    <button type="button" class="dugme kucuk" id="topluAltUygula" disabled>Alt metni uygula</button>
    <?php if ($silebilir): ?>
      <button type="button" class="dugme kucuk tehlike" id="topluSil" disabled>Seçilileri sil</button>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$ogeler): ?>
  <div class="bos-durum">
    <span class="buyuk" aria-hidden="true">▣</span>
    <h3><?= ($ara !== '' || $suzgec !== '') ? 'Eşleşen dosya yok' : 'Kütüphane boş' ?></h3>
    <p><?= ($ara !== '' || $suzgec !== '') ? 'Farklı bir arama deneyin.' : 'Yukarıdaki alana dosya sürükleyerek başlayın.' ?></p>
  </div>
<?php else: ?>
  <div class="medya-izgara">
    <?php foreach ($ogeler as $m): ?>
      <div class="medya-oge medya-kart<?= !empty($m['alt_missing']) ? ' alt-eksik' : '' ?>" data-id="<?= (int)$m['id'] ?>"
           data-fx="<?= (int)$m['focus_x'] ?>" data-fy="<?= (int)$m['focus_y'] ?>">
        <label class="medya-sec" title="Seç">
          <input type="checkbox" class="secim-kutu" value="<?= (int)$m['id'] ?>"
                 aria-label="<?= esc(basename((string)$m['filename'])) ?> seç">
        </label>
        <div class="medya-onizleme" data-odak-alan="<?= (int)$m['id'] ?>"
             title="Odak noktasını değiştirmek için görsele tıklayın">
          <img src="<?= esc($m['thumb']) ?>" alt="<?= esc($m['alt'] !== '' ? $m['alt'] : $m['filename']) ?>"
               loading="lazy" style="object-position:<?= (int)$m['focus_x'] ?>% <?= (int)$m['focus_y'] ?>%">
          <span class="odak-nisan" aria-hidden="true"
                style="left:<?= (int)$m['focus_x'] ?>%; top:<?= (int)$m['focus_y'] ?>%"></span>
        </div>
        <div class="ad" title="<?= esc($m['filename']) ?>">
          <a href="<?= esc($m['url']) ?>" target="_blank" rel="noopener"><?= esc(basename((string)$m['filename'])) ?></a>
        </div>
        <div class="medya-bilgi mini soluk">
          <?= (int)$m['width'] ?>×<?= (int)$m['height'] ?> · <?= esc($m['size_text']) ?>
          · <span data-varyant-durum><?= esc($m['variant_label']) ?></span>
        </div>
        <div class="medya-islem">
          <input type="text" class="alt-girdi" value="<?= esc($m['alt']) ?>" maxlength="255"
                 placeholder="Alt metin" aria-label="<?= esc(basename((string)$m['filename'])) ?> alt metni">
          <div class="mini soluk">Odak: <span data-odak-metin><?= (int)$m['focus_x'] ?>% / <?= (int)$m['focus_y'] ?>%</span></div>
          <div class="dugme-grup">
            <button type="button" class="dugme kucuk" data-medya-kaydet="<?= (int)$m['id'] ?>">Kaydet</button>
            <button type="button" class="dugme kucuk" data-odak-sifirla="<?= (int)$m['id'] ?>"
                    title="Odağı merkeze al">Odağı merkeze</button>
            <?php if ($silebilir): ?>
              <button type="button" class="dugme kucuk tehlike" data-medya-sil="<?= (int)$m['id'] ?>"
                      data-onay="<?= esc(basename((string)$m['filename'])) ?> ve türevleri kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?= admin_paginate($toplam, $perPage, $sayfa, 'media', $suzgecSorgu) ?>
<?php endif; ?>

<!-- ==================================================== kullanılmayan görseller -->
<div class="kart bosluk-ust">
  <div class="kart-baslik">Kullanılmayan görseller</div>
  <p class="kucuk soluk">
    Hiçbir haberde, sayfada, reklamda ya da ayarda adı geçmeyen dosyaları listeler.
    <strong>Silme geri alınamaz.</strong> Bu yüzden önce yalnız sayılır ve listelenir;
    silmek ayrı bir adımdır ve <code>media.delete_any</code> yetkisi ister.
  </p>
  <div class="uyari warn mini">
    <strong>Yanlış pozitif uyarısı.</strong> Tarama METİN eşleşmesiyle çalışır: haber gövdesindeki
    <code>&lt;img src="…"&gt;</code> adresleri düz metin olarak aranır. Görseli JavaScript ile kuran,
    adresi parçalayan, yalnız medya kimliği saklayan ya da doğrudan tema dosyasına yazılmış
    kullanımlar <strong>görülmez</strong> — o dosyalar burada "kullanılmıyor" görünür.
    Silmeden önce her dosyayı açıp gözle denetleyin.
  </div>
  <div class="dugme-grup">
    <button type="button" class="dugme" id="kullanilmayanTara">Tara ve say</button>
    <?php if ($silebilir): ?>
      <button type="button" class="dugme tehlike" id="kullanilmayanSil" disabled>Listelenenleri sil</button>
    <?php endif; ?>
  </div>
  <div id="kullanilmayanSonuc" class="bosluk-ust" role="status"></div>
</div>

<?php if (!$silebilir): ?>
  <div class="uyari bilgi mini bosluk-ust">
    Dosya silme yetkisi (<code>media.delete_any</code>) hesabınızda yok. Silinmesi gereken dosyaları
    bir editöre ya da yöneticiye bildirin.
  </div>
<?php endif; ?>

<style>
/* admin.css'te bir "sürükle-bırak" kutusu ve medya kartı alt bölümü tanımlı değil.
   Aşağıdaki kurallar yalnız bu ekranın yükleme alanı ve kart altı için gereklidir;
   renkler mevcut değişkenlerden alınır. */
.birak-alani { display:flex; flex-direction:column; align-items:center; gap:6px; text-align:center;
  padding:28px 18px; border:2px dashed var(--cizgi); border-radius:var(--kose);
  background:var(--yuzey-2); cursor:pointer; transition:border-color .15s, background .15s; }
.birak-alani:hover, .birak-alani:focus-visible { border-color:var(--ana); outline:none; }
.birak-alani.uzerinde { border-color:var(--ana); background:#fdf0ee; }
.birak-ikon { font-size:26px; opacity:.5; }
.medya-kart { cursor:default; display:flex; flex-direction:column; position:relative; }
.medya-bilgi { padding:0 8px 4px; }
.medya-islem { padding:6px 8px 8px; margin-top:auto; display:flex; flex-direction:column; gap:6px; }
.medya-islem .alt-girdi { font-size:12px; padding:5px 7px; }
.medya-kart.siliniyor { opacity:.4; pointer-events:none; }
/* 1.3-08 — toplu seçim, odak noktası, alt metin uyarısı */
.medya-kart.alt-eksik { outline:2px solid var(--uyari, #d98324); outline-offset:-2px; }
.medya-kart.secili { box-shadow:0 0 0 3px var(--ana) inset; }
.medya-sec { position:absolute; top:6px; left:6px; z-index:2; background:var(--yuzey);
  border-radius:4px; padding:2px 4px; line-height:1; }
.medya-onizleme { position:relative; cursor:crosshair; }
.medya-onizleme img { display:block; width:100%; }
.odak-nisan { position:absolute; width:14px; height:14px; margin:-7px 0 0 -7px; border-radius:50%;
  border:2px solid #fff; box-shadow:0 0 0 2px rgba(0,0,0,.55); pointer-events:none; }
.medya-kart .ad a { text-decoration:none; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var alan = document.getElementById('birakAlani');
  var girdi = document.getElementById('dosyaSec');
  var durum = document.getElementById('yuklemeDurum');
  var altAlan = document.getElementById('topluAlt');

  function bildir(metin) { if (durum) { durum.textContent = metin || ''; } }

  function yukle(dosyalar) {
    if (!dosyalar || !dosyalar.length) { return; }
    var fd = new FormData();
    var sayi = 0;
    Array.prototype.forEach.call(dosyalar, function (f) {
      if (sayi >= 20) { return; }
      fd.append('dosya[]', f);
      sayi++;
    });
    if (!sayi) { return; }
    if (altAlan && altAlan.value.trim() !== '') { fd.append('alt', altAlan.value.trim()); }

    bildir(sayi + ' dosya yükleniyor…');
    var t0 = (window.performance && performance.now) ? performance.now() : Date.now();
    M.api('media.upload', null, { formData: fd }).then(function (r) {
      var t1 = (window.performance && performance.now) ? performance.now() : Date.now();
      // Yükleme süresi ölçülebilir kalsın: 1.3-08'in amacı bu sayıyı düşürmekti.
      bildir(r && r.ok ? ('Yükleme ' + Math.round(t1 - t0) + ' ms sürdü.') : '');
      if (M.sonuc(r, 'Yüklendi.')) { setTimeout(function () { location.reload(); }, 900); }
    });
  }

  if (alan && girdi) {
    alan.addEventListener('click', function () { girdi.click(); });
    alan.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); girdi.click(); }
    });
    girdi.addEventListener('change', function () { yukle(girdi.files); girdi.value = ''; });

    ['dragenter', 'dragover'].forEach(function (ad) {
      alan.addEventListener(ad, function (e) { e.preventDefault(); e.stopPropagation(); alan.classList.add('uzerinde'); });
    });
    ['dragleave', 'drop'].forEach(function (ad) {
      alan.addEventListener(ad, function (e) { e.preventDefault(); e.stopPropagation(); alan.classList.remove('uzerinde'); });
    });
    alan.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) { yukle(e.dataTransfer.files); }
    });
  }

  // Sayfanın geri kalanına bırakılan dosya tarayıcıda açılmasın
  ['dragover', 'drop'].forEach(function (ad) {
    window.addEventListener(ad, function (e) {
      if (alan && alan.contains(e.target)) { return; }
      e.preventDefault();
    });
  });

  /* ------------------------------------------------ alt metin kaydet */
  M.qsa('[data-medya-kaydet]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kart = b.closest('.medya-kart');
      var deger = M.qs('.alt-girdi', kart).value;
      b.disabled = true;
      M.api('media.update', { id: parseInt(b.getAttribute('data-medya-kaydet'), 10), alt: deger })
        .then(function (r) {
          M.sonuc(r, 'Alt metin kaydedildi.');
          if (r && r.ok) { kart.classList.toggle('alt-eksik', String(deger).trim() === ''); }
          b.disabled = false;
        });
    });
  });

  /* ------------------------------------------------ sil */
  M.qsa('[data-medya-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kart = b.closest('.medya-kart');
      kart.classList.add('siliniyor');
      M.api('media.delete', { id: parseInt(b.getAttribute('data-medya-sil'), 10) }).then(function (r) {
        if (M.sonuc(r, 'Silindi.')) { kart.parentNode.removeChild(kart); secimTazele(); }
        else { kart.classList.remove('siliniyor'); }
      });
    });
  });

  /* ================================================ 1.3-08: odak noktası
     Kırpılan görsellerde (object-fit:cover) hangi noktanın korunacağını
     belirler. Önizlemeye tıklanan yer YÜZDE olarak saklanır — böylece her
     varyant genişliğinde aynı nokta korunur. */
  function odakYaz(kart, fx, fy) {
    var id = parseInt(kart.getAttribute('data-id'), 10);
    kart.setAttribute('data-fx', fx);
    kart.setAttribute('data-fy', fy);
    var img = M.qs('.medya-onizleme img', kart);
    var nisan = M.qs('.odak-nisan', kart);
    if (img) { img.style.objectPosition = fx + '% ' + fy + '%'; }
    if (nisan) { nisan.style.left = fx + '%'; nisan.style.top = fy + '%'; }
    var metin = M.qs('[data-odak-metin]', kart);
    if (metin) { metin.textContent = fx + '% / ' + fy + '%'; }
    M.api('media.update', { id: id, focus_x: fx, focus_y: fy }).then(function (r) {
      M.sonuc(r, 'Odak noktası kaydedildi.');
    });
  }

  M.qsa('[data-odak-alan]').forEach(function (alanEl) {
    alanEl.addEventListener('click', function (e) {
      var kutu = alanEl.getBoundingClientRect();
      if (!kutu.width || !kutu.height) { return; }
      var fx = Math.max(0, Math.min(100, Math.round(((e.clientX - kutu.left) / kutu.width) * 100)));
      var fy = Math.max(0, Math.min(100, Math.round(((e.clientY - kutu.top) / kutu.height) * 100)));
      odakYaz(alanEl.closest('.medya-kart'), fx, fy);
    });
  });

  M.qsa('[data-odak-sifirla]').forEach(function (b) {
    b.addEventListener('click', function () { odakYaz(b.closest('.medya-kart'), 50, 50); });
  });

  /* ================================================ 1.3-08: toplu seçim */
  function seciliIdler() {
    return M.qsa('.secim-kutu:checked').map(function (k) { return parseInt(k.value, 10); });
  }

  function secimTazele() {
    var n = seciliIdler().length;
    var sayacEl = M.qs('#secimSayi');
    if (sayacEl) { sayacEl.textContent = n + ' seçili'; }
    ['#topluAltUygula', '#topluSil'].forEach(function (sel) {
      var b = M.qs(sel);
      if (b) { b.disabled = (n === 0); }
    });
    M.qsa('.secim-kutu').forEach(function (k) {
      k.closest('.medya-kart').classList.toggle('secili', k.checked);
    });
  }

  M.qsa('.secim-kutu').forEach(function (k) { k.addEventListener('change', secimTazele); });

  var hepsi = M.qs('#hepsiniSec');
  if (hepsi) {
    hepsi.addEventListener('change', function () {
      M.qsa('.secim-kutu').forEach(function (k) { k.checked = hepsi.checked; });
      secimTazele();
    });
  }

  var topluAltDugme = M.qs('#topluAltUygula');
  if (topluAltDugme) {
    topluAltDugme.addEventListener('click', function () {
      var ids = seciliIdler();
      var metin = M.qs('#topluAltMetin').value.trim();
      if (!ids.length) { return; }
      if (metin === '') { M.toast('err', 'Önce uygulanacak alt metni yazın.'); return; }
      topluAltDugme.disabled = true;
      M.api('media.bulk_alt', { ids: ids, alt: metin }).then(function (r) {
        topluAltDugme.disabled = false;
        if (M.sonuc(r, 'Alt metinler güncellendi.')) { setTimeout(function () { location.reload(); }, 700); }
      });
    });
  }

  var topluSilDugme = M.qs('#topluSil');
  if (topluSilDugme) {
    topluSilDugme.addEventListener('click', function () {
      var ids = seciliIdler();
      if (!ids.length) { return; }
      // İKİ AŞAMALI ONAY: silme geri alınamaz.
      if (!M.onay(ids.length + ' dosya ve bütün türevleri KALICI olarak silinecek. '
        + 'Bu işlem geri alınamaz. Onaylıyor musunuz?')) { return; }
      topluSilDugme.disabled = true;
      M.api('media.bulk_delete', { ids: ids, confirm: true }).then(function (r) {
        topluSilDugme.disabled = false;
        if (M.sonuc(r, 'Silindi.')) { setTimeout(function () { location.reload(); }, 700); }
      });
    });
  }

  secimTazele();

  /* ================================================ 1.3-08: varyant kuyruğu */
  var kuyrukDugme = M.qs('#kuyrukIsle');
  if (kuyrukDugme) {
    kuyrukDugme.addEventListener('click', function () {
      var d = M.qs('#kuyrukDurum');
      kuyrukDugme.disabled = true;
      if (d) { d.textContent = 'Üretiliyor…'; }
      M.api('media.variants', {}).then(function (r) {
        kuyrukDugme.disabled = false;
        if (d) { d.textContent = (r && r.ok) ? (r.message || '') : ''; }
        if (M.sonuc(r, 'Türevler üretildi.') && r.pending === 0) {
          setTimeout(function () { location.reload(); }, 900);
        }
      });
    });
  }

  /* ================================================ 1.3-08: kullanılmayanlar */
  var kullanilmayanIdler = [];

  var taraDugme = M.qs('#kullanilmayanTara');
  if (taraDugme) {
    taraDugme.addEventListener('click', function () {
      var kap = M.qs('#kullanilmayanSonuc');
      taraDugme.disabled = true;
      kap.innerHTML = '<span class="yukleniyor"></span> Taranıyor…';
      M.api('media.unused', { limit: 100 }).then(function (r) {
        taraDugme.disabled = false;
        if (!r || !r.ok) { kap.innerHTML = '<div class="uyari err">' + M.esc((r && r.error) || 'Tarama başarısız.') + '</div>'; return; }
        kullanilmayanIdler = (r.items || []).map(function (o) { return o.id; });
        var silDugme = M.qs('#kullanilmayanSil');
        if (silDugme) { silDugme.disabled = (kullanilmayanIdler.length === 0); }

        if (!r.items || !r.items.length) {
          kap.innerHTML = '<div class="uyari ok">Kullanılmayan görsel bulunamadı ('
            + M.esc(String(r.scanned)) + ' kayıt tarandı).</div>';
          return;
        }
        var h = '<div class="uyari bilgi"><strong>' + M.esc(String(r.total)) + ' aday</strong> — '
          + M.esc(r.bytes_text || '') + ' yer kaplıyor. ' + M.esc(String(r.scanned)) + ' kayıt tarandı.</div>'
          + '<div class="uyari warn mini">' + M.esc(r.warning || '') + '</div>'
          + '<div class="tablo-sarma"><table class="tablo"><thead><tr>'
          + '<th>Görsel</th><th>Dosya</th><th>Boyut</th><th>Yüklendi</th></tr></thead><tbody>';
        r.items.forEach(function (o) {
          h += '<tr><td class="dar"><img class="kucuk-gorsel" src="' + M.esc(o.thumb) + '" alt="" loading="lazy"></td>'
            + '<td><a href="' + M.esc(o.url) + '" target="_blank" rel="noopener">' + M.esc(o.filename) + '</a></td>'
            + '<td class="dar">' + M.esc(o.size_text) + '</td>'
            + '<td class="dar"><span class="kucuk soluk">' + M.esc(o.created_at) + '</span></td></tr>';
        });
        kap.innerHTML = h + '</tbody></table></div>';
      });
    });
  }

  var kullanilmayanSil = M.qs('#kullanilmayanSil');
  if (kullanilmayanSil) {
    kullanilmayanSil.addEventListener('click', function () {
      if (!kullanilmayanIdler.length) { return; }
      if (!M.onay('Listelenen ' + kullanilmayanIdler.length + ' dosya ve türevleri KALICI olarak '
        + 'silinecek. Liste metin taramasıyla üretildi ve yanlış pozitif içerebilir. '
        + 'Her dosyayı gözle denetlediniz mi?')) { return; }
      if (!M.onay('Son onay: ' + kullanilmayanIdler.length + ' dosya siliniyor. Geri alınamaz.')) { return; }
      kullanilmayanSil.disabled = true;
      M.api('media.bulk_delete', { ids: kullanilmayanIdler, confirm: true }).then(function (r) {
        kullanilmayanSil.disabled = false;
        if (M.sonuc(r, 'Silindi.')) { setTimeout(function () { location.reload(); }, 900); }
      });
    });
  }
});
</script>
