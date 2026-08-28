<?php
/**
 * Panel — Araçlar: önbellek, yedekleme, medya bakımı, sistem, bakım komutları.
 *
 * Tüm işlemler inc/api/tools.php uçlarına M.api() ile gider; bu sayfa yalnız
 * arayüzü çizer. Uzun süren işler (varyant üretimi) parça parça çalıştığı için
 * sayfa yenilemesiyle zaman aşımına uğramaz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('tools.manage');

$adminPageTitle = 'Araçlar';

$cacheStats = function_exists('cache_stats') ? cache_stats() : null;
$backupInfo = function_exists('backup_supported')
    ? backup_supported()
    : ['sqlite' => false, 'mysql' => false, 'reason' => 'Yedekleme modülü yüklü değil.'];
$mediaStats = function_exists('media_storage_stats') ? media_storage_stats() : null;
$driver = db_driver();
$yedekVar = ($driver === 'sqlite' ? !empty($backupInfo['sqlite']) : !empty($backupInfo['mysql']));
?>

<div class="sayfa-basligi">
  <h1>Araçlar</h1>
  <a class="dugme" href="<?= esc(admin_url('logs')) ?>">Günlükler &rarr;</a>
</div>

<div class="sekmeler" id="araclarSekme">
  <button type="button" class="etkin" data-sekme="onbellek">Önbellek</button>
  <button type="button" data-sekme="yedek">Yedekleme</button>
  <button type="button" data-sekme="medya">Medya bakımı</button>
  <button type="button" data-sekme="sistem">Sistem</button>
  <button type="button" data-sekme="bakim">Bakım</button>
</div>

<section data-panel="onbellek">
  <?php if (!$cacheStats): ?>
    <div class="uyari warn"><code>inc/cache.php</code> yüklü değil; sayfa önbelleği devre dışı.</div>
  <?php else: ?>
    <div class="izgara-4 bosluk-alt">
      <div class="sayac vurgulu">
        <div class="deger" id="obDurum"><?= $cacheStats['enabled'] ? 'Açık' : 'Kapalı' ?></div>
        <div class="etiket">Önbellek durumu</div>
      </div>
      <div class="sayac"><div class="deger" id="obDosya"><?= (int)$cacheStats['files'] ?></div><div class="etiket">Önbellek dosyası</div></div>
      <div class="sayac"><div class="deger" id="obBoyut"><?= esc($cacheStats['size_text']) ?></div><div class="etiket">Toplam boyut</div></div>
      <div class="sayac"><div class="deger"><?= (int)$cacheStats['ttl_home'] ?>/<?= (int)$cacheStats['ttl_post'] ?></div><div class="etiket">TTL liste/haber (sn)</div></div>
    </div>

    <?php if (empty($cacheStats['writable'])): ?>
      <div class="uyari err"><strong>Önbellek klasörü yazılabilir değil.</strong>
        <code><?= esc($cacheStats['dir']) ?></code> — site çalışmaya devam eder, ancak önbellek devre dışıdır.</div>
    <?php endif; ?>

    <div class="kart">
      <div class="kart-baslik">Önbellek işlemleri</div>
      <p class="kucuk soluk">
        Oturum açmamış ziyaretçilere hazır sayfa sunulur. İçerik kaydedildiğinde ilgili sayfaların
        önbelleği kendiliğinden düşer; buradaki düğmeler elle müdahale içindir.
      </p>
      <div class="dugme-grup">
        <button type="button" class="dugme birincil" data-ob-temizle="">Tümünü temizle</button>
        <button type="button" class="dugme" data-ob-temizle="home">Anasayfa</button>
        <button type="button" class="dugme" data-ob-temizle="category">Kategori sayfaları</button>
        <button type="button" class="dugme" id="obSupur">Süresi geçenleri temizle</button>
        <button type="button" class="dugme" id="obYenile">Durumu yenile</button>
      </div>
      <p class="mini soluk bosluk-ust">
        En eski kayıt: <span id="obEski"><?= esc($cacheStats['oldest'] ? $cacheStats['oldest'] : '—') ?></span> ·
        En yeni: <span id="obYeni"><?= esc($cacheStats['newest'] ? $cacheStats['newest'] : '—') ?></span> ·
        Üst sınır: <?= (int)$cacheStats['max_mb'] ?> MB
      </p>
      <p class="mini soluk">TTL değerlerini <a href="<?= esc(admin_url('settings')) ?>">Ayarlar</a> ekranından değiştirebilirsiniz.</p>
    </div>
  <?php endif; ?>
</section>

<section data-panel="yedek" hidden>
  <?php if (!$yedekVar): ?>
    <div class="uyari warn">Bu sunucuda yedekleme kullanılamıyor: <?= esc((string)$backupInfo['reason']) ?></div>
  <?php endif; ?>

  <div class="kart">
    <div class="kart-baslik">Veritabanı yedeği</div>
    <p class="kucuk soluk">
      Yedekler <code>db/</code> klasöründe, tahmin edilemez adlarla saklanır ve
      <code>.htaccess</code> ile dışarıya kapalıdır.
      <strong>Tam yedek için</strong> ayrıca <code>config.php</code> ve <code>uploads/</code> klasörünü de saklayın.
    </p>
    <div class="dugme-grup">
      <button type="button" class="dugme birincil" id="yedekAl" <?= $yedekVar ? '' : 'disabled' ?>>Yedek al</button>
      <button type="button" class="dugme" id="yedekYenile">Listeyi yenile</button>
    </div>
    <div class="tablo-sarma bosluk-ust">
      <table class="tablo">
        <thead><tr><th>Dosya</th><th>Tarih</th><th>Boyut</th><th class="islem">İşlem</th></tr></thead>
        <tbody id="yedekListe"><tr><td colspan="4" class="soluk">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
  </div>

  <div class="kart">
    <div class="kart-baslik">Geri yükleme</div>
    <div class="uyari err">
      <strong>Dikkat:</strong> Geri yükleme mevcut veritabanının <em>tamamını</em> değiştirir.
      İşlemden önce otomatik olarak bir güvenlik yedeği alınır.
    </div>
    <form id="geriYukleForm">
      <div class="form-satir">
        <div class="form-alan">
          <label for="gyDosya">Yedek dosyası</label>
          <input type="file" id="gyDosya" name="dosya" accept=".sqlite,.sql" required>
          <span class="form-yardim">En çok 200 MB. SQLite (.sqlite) veya SQL dökümü (.sql).</span>
        </div>
        <div class="form-alan">
          <label for="gyParola">Yönetici parolanız</label>
          <input type="password" id="gyParola" name="password" required autocomplete="current-password">
          <span class="form-yardim">Kimliğinizi doğrulamak için yeniden istenir.</span>
        </div>
      </div>
      <button type="submit" class="dugme tehlike"
        data-onay="Veritabanı geri yüklenecek ve mevcut veriler değiştirilecek. Onaylıyor musunuz?">Geri yükle</button>
      <span id="gySonuc" class="kucuk soluk"></span>
    </form>
  </div>
</section>

<section data-panel="medya" hidden>
  <div class="izgara-4 bosluk-alt">
    <div class="sayac"><div class="deger" id="mdDosya"><?= $mediaStats ? (int)$mediaStats['files'] : 0 ?></div><div class="etiket">Kayıtlı görsel</div></div>
    <div class="sayac"><div class="deger" id="mdVaryant"><?= $mediaStats ? (int)$mediaStats['variants'] : 0 ?></div><div class="etiket">Varyant dosyası</div></div>
    <div class="sayac"><div class="deger" id="mdWebp"><?= $mediaStats ? (int)$mediaStats['webp'] : 0 ?></div><div class="etiket">WebP</div></div>
    <div class="sayac"><div class="deger" id="mdBoyut"><?= $mediaStats ? esc(media_human_size((int)$mediaStats['bytes'])) : '0 B' ?></div><div class="etiket">Toplam boyut</div></div>
  </div>

  <div class="kart">
    <div class="kart-baslik">Varyantları yeniden üret</div>
    <p class="kucuk soluk">
      Görsel boyutlarını (thumb/medium/large) ve WebP kopyalarını yeniden üretir.
      Zaman aşımına uğramaması için parça parça çalışır.
      <?php if (!function_exists('imagecreatetruecolor')): ?>
        <strong>GD eklentisi yok — bu işlem atlanacak.</strong>
      <?php endif; ?>
    </p>
    <div class="dugme-grup">
      <button type="button" class="dugme birincil" id="mdYeniden">Yeniden üret</button>
      <span id="mdIlerleme" class="kucuk soluk"></span>
    </div>
    <div class="olcek bosluk-ust" id="mdOlcekKap" hidden><span id="mdOlcek" style="width:0"></span></div>
  </div>

  <div class="kart">
    <div class="kart-baslik">Sahipsiz dosyalar</div>
    <p class="kucuk soluk">
      <code>uploads/</code> içinde olup medya kütüphanesinde kaydı bulunmayan dosyalar.
      Silme işlemi geri alınamaz; tek tek onaylanır.
    </p>
    <div class="dugme-grup">
      <button type="button" class="dugme" id="mdSahipsiz">Tara</button>
      <span id="mdSahipsizOzet" class="kucuk soluk"></span>
    </div>
    <div class="tablo-sarma bosluk-ust" id="mdSahipsizKap" hidden>
      <table class="tablo">
        <thead><tr><th>Dosya</th><th>Boyut</th><th class="islem">İşlem</th></tr></thead>
        <tbody id="mdSahipsizListe"></tbody>
      </table>
    </div>
  </div>
</section>

<section data-panel="sistem" hidden>
  <div class="kart">
    <div class="kart-baslik">Sistem bilgisi</div>
    <div id="sistemBilgi"><p class="soluk">Yükleniyor…</p></div>
  </div>
</section>

<section data-panel="bakim" hidden>
  <div class="kart">
    <div class="kart-baslik">Veritabanı bakımı</div>
    <p class="kucuk soluk">
      <?php if ($driver === 'sqlite'): ?>
        SQLite için <code>VACUUM</code> çalıştırır: silinen kayıtların bıraktığı boşluğu geri kazanır.
      <?php else: ?>
        MySQL için <code>OPTIMIZE TABLE</code> çalıştırır.
      <?php endif; ?>
      Büyük veritabanlarında birkaç saniye sürebilir.
    </p>
    <div class="dugme-grup">
      <button type="button" class="dugme birincil" id="bakimVacuum"
        data-onay="Veritabanı bakımı çalıştırılacak. Devam edilsin mi?">Bakımı çalıştır</button>
      <span id="bakimSonuc" class="kucuk soluk"></span>
    </div>
  </div>

  <div class="kart">
    <div class="kart-baslik">Şema göçleri</div>
    <p class="kucuk soluk">
      Sürüm yükseltmelerinden sonra bekleyen şema değişikliklerini uygular.
      Zaten uygulanmış göçler tekrar çalıştırılmaz.
    </p>
    <div class="dugme-grup">
      <button type="button" class="dugme" id="bakimMigrate">Göçleri uygula</button>
      <span id="migrateSonuc" class="kucuk soluk"></span>
    </div>
  </div>
</section>

<script>
M.hazir(function () {
  'use strict';

  /* ---------------------------------------------------------- sekmeler */
  var sekmeler = M.qsa('#araclarSekme button');
  sekmeler.forEach(function (b) {
    b.addEventListener('click', function () {
      var ad = b.getAttribute('data-sekme');
      sekmeler.forEach(function (x) { x.classList.toggle('etkin', x === b); });
      M.qsa('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== ad; });
      if (ad === 'yedek') { yedekListele(); }
      if (ad === 'sistem') { sistemYukle(); }
    });
  });

  /* ---------------------------------------------------------- önbellek */
  function obDurumYenile() {
    M.api('cache.status', {}).then(function (r) {
      if (!r.ok) { return; }
      var d = document.getElementById('obDurum'); if (d) { d.textContent = r.enabled ? 'Açık' : 'Kapalı'; }
      var f = document.getElementById('obDosya'); if (f) { f.textContent = r.files; }
      var b = document.getElementById('obBoyut'); if (b) { b.textContent = r.size_text; }
      var e = document.getElementById('obEski'); if (e) { e.textContent = r.oldest || '—'; }
      var y = document.getElementById('obYeni'); if (y) { y.textContent = r.newest || '—'; }
    });
  }
  M.qsa('[data-ob-temizle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var kapsam = b.getAttribute('data-ob-temizle');
      M.api('cache.flush', kapsam ? { scope: kapsam } : {}).then(function (r) {
        M.sonuc(r, 'Önbellek temizlendi.');
        obDurumYenile();
      });
    });
  });
  var obSupur = document.getElementById('obSupur');
  if (obSupur) {
    obSupur.addEventListener('click', function () {
      M.api('cache.sweep', {}).then(function (r) { M.sonuc(r, 'Süpürme tamamlandı.'); obDurumYenile(); });
    });
  }
  var obYenile = document.getElementById('obYenile');
  if (obYenile) { obYenile.addEventListener('click', obDurumYenile); }

  /* ---------------------------------------------------------- yedekleme */
  function yedekListele() {
    var govde = document.getElementById('yedekListe');
    if (!govde) { return; }
    M.api('backup.list', {}).then(function (r) {
      if (!r.ok) { govde.innerHTML = '<tr><td colspan="4" class="uyari err">' + M.esc(r.error || 'Liste alınamadı.') + '</td></tr>'; return; }
      var items = r.items || [];
      if (!items.length) {
        govde.innerHTML = '<tr><td colspan="4" class="soluk">Henüz yedek alınmamış.</td></tr>';
        return;
      }
      govde.innerHTML = items.map(function (y) {
        var indir = M.cfg.api + '?a=backup.download&filename=' + encodeURIComponent(y.filename);
        return '<tr>'
          + '<td class="tek-satir"><code>' + M.esc(y.filename) + '</code></td>'
          + '<td class="dar">' + M.esc(y.date || '') + '</td>'
          + '<td class="dar">' + M.esc(y.size_text || '') + '</td>'
          + '<td class="islem">'
          + '<a class="dugme kucuk" href="' + indir + '">İndir</a> '
          + '<button type="button" class="dugme kucuk tehlike" data-yedek-sil="' + M.esc(y.filename) + '"'
          + ' data-onay="' + M.esc(y.filename) + ' silinecek. Onaylıyor musunuz?">Sil</button>'
          + '</td></tr>';
      }).join('');
      M.qsa('[data-yedek-sil]', govde).forEach(function (b) {
        b.addEventListener('click', function () {
          M.api('backup.delete', { filename: b.getAttribute('data-yedek-sil') }).then(function (r2) {
            if (M.sonuc(r2, 'Yedek silindi.')) { yedekListele(); }
          });
        });
      });
    });
  }
  var yedekAl = document.getElementById('yedekAl');
  if (yedekAl) {
    yedekAl.addEventListener('click', function () {
      yedekAl.disabled = true;
      M.toast('bilgi', 'Yedek alınıyor…', 2000);
      M.api('backup.create', {}).then(function (r) {
        M.sonuc(r, 'Yedek alındı.');
        yedekAl.disabled = false;
        yedekListele();
      });
    });
  }
  var yedekYenile = document.getElementById('yedekYenile');
  if (yedekYenile) { yedekYenile.addEventListener('click', yedekListele); }

  var gyForm = document.getElementById('geriYukleForm');
  if (gyForm) {
    gyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var dosya = document.getElementById('gyDosya');
      var parola = document.getElementById('gyParola');
      var sonuc = document.getElementById('gySonuc');
      if (!dosya.files.length) { M.toast('err', 'Dosya seçin.'); return; }
      var fd = new FormData();
      fd.append('dosya', dosya.files[0]);
      fd.append('password', parola.value);
      sonuc.textContent = 'Geri yükleniyor…';
      M.api('backup.restore', null, { formData: fd }).then(function (r) {
        sonuc.textContent = '';
        if (M.sonuc(r, 'Geri yükleme tamamlandı.')) {
          parola.value = '';
          setTimeout(function () { location.reload(); }, 1500);
        }
      });
    });
  }

  /* ---------------------------------------------------------- medya */
  var mdYeniden = document.getElementById('mdYeniden');
  if (mdYeniden) {
    mdYeniden.addEventListener('click', function () {
      mdYeniden.disabled = true;
      var kap = document.getElementById('mdOlcekKap');
      var cubuk = document.getElementById('mdOlcek');
      var bilgi = document.getElementById('mdIlerleme');
      if (kap) { kap.hidden = false; }

      function tur(offset) {
        M.api('media.regen', { offset: offset, limit: 25 }).then(function (r) {
          if (!r.ok) { M.sonuc(r); mdYeniden.disabled = false; return; }
          var toplam = r.total || 0;
          var bitti = r.next_offset || toplam;
          if (bilgi) { bilgi.textContent = bitti + ' / ' + toplam; }
          if (cubuk && toplam > 0) { cubuk.style.width = Math.min(100, Math.round(bitti * 100 / toplam)) + '%'; }
          if (r.next_offset && r.next_offset < toplam) {
            tur(r.next_offset);
          } else {
            if (cubuk) { cubuk.style.width = '100%'; }
            M.toast('ok', 'Varyantlar yeniden üretildi.');
            mdYeniden.disabled = false;
            M.api('media.stats', {}).then(function (s) {
              if (!s.ok) { return; }
              var el;
              el = document.getElementById('mdVaryant'); if (el) { el.textContent = s.variants; }
              el = document.getElementById('mdWebp'); if (el) { el.textContent = s.webp; }
            });
          }
        });
      }
      tur(0);
    });
  }

  var mdSahipsiz = document.getElementById('mdSahipsiz');
  if (mdSahipsiz) {
    mdSahipsiz.addEventListener('click', function () {
      var kap = document.getElementById('mdSahipsizKap');
      var liste = document.getElementById('mdSahipsizListe');
      var ozet = document.getElementById('mdSahipsizOzet');
      M.api('media.orphans', {}).then(function (r) {
        if (!r.ok) { M.sonuc(r); return; }
        var items = r.items || [];
        if (ozet) { ozet.textContent = items.length + ' dosya · ' + (r.size_text || ''); }
        if (!items.length) {
          if (kap) { kap.hidden = true; }
          M.toast('ok', 'Sahipsiz dosya bulunamadı.');
          return;
        }
        if (kap) { kap.hidden = false; }
        liste.innerHTML = items.map(function (o) {
          return '<tr><td class="tek-satir"><code>' + M.esc(o.filename) + '</code></td>'
            + '<td class="dar">' + M.esc(o.size_text || '') + '</td>'
            + '<td class="islem"><button type="button" class="dugme kucuk tehlike" data-sahipsiz-sil="'
            + M.esc(o.filename) + '" data-onay="' + M.esc(o.filename)
            + ' kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button></td></tr>';
        }).join('');
        M.qsa('[data-sahipsiz-sil]', liste).forEach(function (b) {
          b.addEventListener('click', function () {
            M.api('media.delete_orphan', { filename: b.getAttribute('data-sahipsiz-sil') }).then(function (r2) {
              if (M.sonuc(r2, 'Dosya silindi.')) {
                var tr = b.closest('tr');
                if (tr && tr.parentNode) { tr.parentNode.removeChild(tr); }
              }
            });
          });
        });
      });
    });
  }

  /* ---------------------------------------------------------- sistem */
  var sistemYuklendi = false;
  function sistemYukle() {
    if (sistemYuklendi) { return; }
    var kap = document.getElementById('sistemBilgi');
    if (!kap) { return; }
    M.api('tools.system', {}).then(function (r) {
      if (!r.ok) { kap.innerHTML = '<div class="uyari err">' + M.esc(r.error || 'Bilgi alınamadı.') + '</div>'; return; }
      sistemYuklendi = true;

      function satir(k, v) { return '<tr><th>' + M.esc(k) + '</th><td>' + v + '</td></tr>'; }
      function rozet(varMi) {
        return varMi ? '<span class="rozet olumlu">var</span>' : '<span class="rozet notr">yok</span>';
      }

      var h = '<table class="tablo" style="min-width:0">';
      h += satir('Manşet sürümü', M.esc(r.manset_version));
      h += satir('PHP', M.esc(r.php_version) + ' <span class="soluk">(' + M.esc(r.sapi) + ' · ' + M.esc(r.os) + ')</span>');
      h += satir('Veritabanı', M.esc(r.db_driver) + (r.sqlite_version ? ' ' + M.esc(r.sqlite_version) : '')
        + ' · <strong>' + M.esc(r.db_size_text) + '</strong>');
      h += satir('Boş disk', M.esc(r.disk_free_text));
      h += '</table>';

      h += '<h3 class="bosluk-ust">Eklentiler</h3><p class="esnek">';
      Object.keys(r.extensions || {}).forEach(function (e) {
        h += '<span class="rozet ' + (r.extensions[e] ? 'olumlu' : 'notr') + '">' + M.esc(e) + '</span> ';
      });
      h += '</p>';

      h += '<h3 class="bosluk-ust">Klasör izinleri</h3><table class="tablo" style="min-width:0">';
      Object.keys(r.dirs || {}).forEach(function (d) {
        var info = r.dirs[d];
        h += satir(d, (info.exists ? rozet(true) : '<span class="rozet olumsuz">yok</span>')
          + ' ' + (info.writable ? '<span class="rozet olumlu">yazılabilir</span>'
                                 : '<span class="rozet olumsuz">yazılamıyor</span>'));
      });
      h += '</table>';

      h += '<h3 class="bosluk-ust">PHP ayarları</h3><table class="tablo" style="min-width:0">';
      Object.keys(r.ini || {}).forEach(function (k) { h += satir(k, '<code>' + M.esc(r.ini[k]) + '</code>'); });
      h += '</table>';

      kap.innerHTML = h;
    });
  }

  /* ---------------------------------------------------------- bakım */
  var bakimVacuum = document.getElementById('bakimVacuum');
  if (bakimVacuum) {
    bakimVacuum.addEventListener('click', function () {
      var sonuc = document.getElementById('bakimSonuc');
      bakimVacuum.disabled = true;
      if (sonuc) { sonuc.textContent = 'Çalışıyor…'; }
      M.api('tools.vacuum', {}).then(function (r) {
        bakimVacuum.disabled = false;
        if (sonuc) { sonuc.textContent = r.ok ? (r.message || '') : ''; }
        M.sonuc(r, 'Bakım tamamlandı.');
      });
    });
  }
  var bakimMigrate = document.getElementById('bakimMigrate');
  if (bakimMigrate) {
    bakimMigrate.addEventListener('click', function () {
      var sonuc = document.getElementById('migrateSonuc');
      M.api('tools.migrate', {}).then(function (r) {
        if (sonuc) { sonuc.textContent = r.ok ? (r.message || '') : ''; }
        M.sonuc(r, 'Göçler uygulandı.');
      });
    });
  }
});
</script>
