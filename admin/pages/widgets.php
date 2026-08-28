<?php
/**
 * Panel — piyasa şeridi ve hava durumu widget'ları + bülten aboneleri. (Ajan-9)
 *
 * Kaydetme, test ve önbellek temizliği widgets.* uçlarından geçer.
 * Harici adresler her istekten önce safe_remote_url() kapısından geçer.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('settings.manage');

$adminPageTitle = 'Widget\'lar';

$marketMode  = setting('widget_market_mode', 'manual') === 'remote' ? 'remote' : 'manual';
$weatherMode = setting('widget_weather_mode', 'manual') === 'remote' ? 'remote' : 'manual';
$marketData  = widgets_setting_json('widget_market_data');
$weatherData = widgets_setting_json('widget_weather_data');
$marketCache = widgets_cache_status('market');
$weatherCache = widgets_cache_status('weather');

// Anasayfa blok diziliminde ilgili bloklar açık mı?
$acikBloklar = [];
foreach (home_blocks() as $b) { $acikBloklar[(string)$b['type']] = true; }

// Bülten aboneleri (migration uygulanmadıysa tablo yok)
$aboneVar = schema_has_table('newsletter_subscribers');
$aboneSayisi = 0;
$aboneler = [];
if ($aboneVar) {
    $aboneSayisi = (int)qv('SELECT COUNT(*) FROM newsletter_subscribers', [], 0);
    $aboneler = qa('SELECT id, email, ip, created_at FROM newsletter_subscribers ORDER BY id DESC LIMIT 20');
}
?>

<div class="sayfa-basligi">
  <h1>Widget'lar</h1>
  <div class="dugme-grup">
    <button type="button" class="dugme birincil" id="widgetKaydet">Kaydet</button>
  </div>
</div>

<div class="uyari bilgi">
  Bu widget'lar anasayfa blok diziliminde <strong>Piyasa Şeridi</strong> / <strong>Hava Durumu</strong>
  blokları açıksa görünür. Blok dizilimini <a href="<?= esc(admin_url('theme')) ?>">Tema Editörü</a>'nden düzenleyin.
  Şu an: Piyasa Şeridi <?= empty($acikBloklar['market']) ? admin_badge('kapalı', 'notr') : admin_badge('açık', 'olumlu') ?>,
  Hava Durumu <?= empty($acikBloklar['weather']) ? admin_badge('kapalı', 'notr') : admin_badge('açık', 'olumlu') ?>.
  Veri boşsa tema bloğu hiç basmaz.
</div>

<div class="izgara-2">

  <!-- ============================================================ piyasa -->
  <div class="kart">
    <div class="kart-baslik">Piyasa şeridi</div>

    <div class="form-alan">
      <label for="marketMode">Veri kaynağı</label>
      <select id="marketMode" data-mod="market">
        <?= admin_options(['manual' => 'Elle giriş (panelden)', 'remote' => 'Harici JSON adresi'], $marketMode) ?>
      </select>
    </div>

    <div data-kip="market-manual">
      <div class="form-alan">
        <label for="mUSD">USD</label>
        <input type="text" id="mUSD" maxlength="40" value="<?= esc((string)arr($marketData, 'USD', '')) ?>">
      </div>
      <div class="form-alan">
        <label for="mEUR">EUR</label>
        <input type="text" id="mEUR" maxlength="40" value="<?= esc((string)arr($marketData, 'EUR', '')) ?>">
      </div>
      <div class="form-alan">
        <label for="mGRAM">Gram altın</label>
        <input type="text" id="mGRAM" maxlength="40" value="<?= esc((string)arr($marketData, 'GRAM_ALTIN', '')) ?>">
      </div>
      <p class="mini soluk">Yön oku (▲/▼) bir önceki kayıtlı değerle karşılaştırılarak üretilir.</p>
    </div>

    <div data-kip="market-remote">
      <div class="form-alan">
        <label for="marketUrl">Harici JSON adresi</label>
        <input type="url" id="marketUrl" maxlength="500" value="<?= esc(setting('widget_market_url', '')) ?>"
               placeholder="https://ornek.com/piyasa.json">
        <span class="form-yardim">Yalnız http/https ve genel IP adresleri kabul edilir;
          localhost, özel ve ayrılmış ağlar reddedilir. Veri 15 dakika önbelleğe alınır.</span>
      </div>
      <div class="form-alan">
        <label for="marketMap">Anahtar eşlemesi <span class="soluk">(isteğe bağlı)</span></label>
        <textarea id="marketMap" rows="3" class="kod-alan"
                  placeholder="USD=data.usd.selling&#10;EUR=data.eur.selling"><?= esc(setting('widget_market_map', '')) ?></textarea>
        <span class="form-yardim">Servis farklı bir biçim döndürüyorsa satır satır
          <code>HEDEF=nokta.yolu</code> yazın. Boş bırakılırsa gelen JSON olduğu gibi kullanılır.</span>
      </div>
      <div class="dugme-grup">
        <button type="button" class="dugme" data-test="market">Şimdi çek ve test et</button>
        <button type="button" class="dugme" data-temizle="market">Önbelleği temizle</button>
      </div>
      <p class="mini soluk bosluk-ust">
        Önbellek:
        <?php if ($marketCache['var']): ?>
          <?= esc($marketCache['cached_at']) ?> (<?= esc($marketCache['yas']) ?> önce)
          <?= $marketCache['taze'] ? admin_badge('taze', 'olumlu') : admin_badge('süresi geçti', 'uyari') ?>
        <?php else: ?>
          <span class="soluk">yok</span>
        <?php endif; ?>
      </p>
      <div id="marketTest" class="bosluk-ust" hidden></div>
    </div>

    <details class="mini bosluk-ust">
      <summary>Beklenen JSON biçimi</summary>
      <pre class="kod-alan" style="white-space:pre-wrap;margin:8px 0 0"><?= esc(widgets_sample_json('market')) ?></pre>
    </details>
  </div>

  <!-- ============================================================ hava durumu -->
  <div class="kart">
    <div class="kart-baslik">Hava durumu</div>

    <div class="form-alan">
      <label for="weatherMode">Veri kaynağı</label>
      <select id="weatherMode" data-mod="weather">
        <?= admin_options(['manual' => 'Elle giriş (panelden)', 'remote' => 'Harici JSON adresi'], $weatherMode) ?>
      </select>
    </div>

    <div data-kip="weather-manual">
      <div class="form-alan">
        <label for="wSehir">Şehir</label>
        <input type="text" id="wSehir" maxlength="60" value="<?= esc((string)arr($weatherData, 'sehir', '')) ?>">
      </div>
      <div class="form-alan">
        <label for="wDerece">Derece</label>
        <input type="text" id="wDerece" maxlength="10" value="<?= esc((string)arr($weatherData, 'derece', '')) ?>">
      </div>
      <div class="form-alan">
        <label for="wDurum">Durum</label>
        <input type="text" id="wDurum" maxlength="60" value="<?= esc((string)arr($weatherData, 'durum', '')) ?>"
               placeholder="Parçalı bulutlu">
        <span class="form-yardim">Simge, durum metninden kendiliğinden seçilir (☀ ⛅ ☁ 🌧 ❄ 🌫 ⛈).</span>
      </div>
    </div>

    <div data-kip="weather-remote">
      <div class="form-alan">
        <label for="weatherUrl">Harici JSON adresi</label>
        <input type="url" id="weatherUrl" maxlength="500" value="<?= esc(setting('widget_weather_url', '')) ?>"
               placeholder="https://ornek.com/hava.json">
        <span class="form-yardim">Yalnız http/https ve genel IP adresleri kabul edilir. Veri 15 dakika önbelleğe alınır.</span>
      </div>
      <div class="form-alan">
        <label for="weatherMap">Anahtar eşlemesi <span class="soluk">(isteğe bağlı)</span></label>
        <textarea id="weatherMap" rows="3" class="kod-alan"
                  placeholder="sehir=city.name&#10;derece=main.temp"><?= esc(setting('widget_weather_map', '')) ?></textarea>
        <span class="form-yardim">Satır satır <code>HEDEF=nokta.yolu</code>. Hedefler: <code>sehir</code>,
          <code>derece</code>, <code>durum</code>, <code>ikon</code>.</span>
      </div>
      <div class="dugme-grup">
        <button type="button" class="dugme" data-test="weather">Şimdi çek ve test et</button>
        <button type="button" class="dugme" data-temizle="weather">Önbelleği temizle</button>
      </div>
      <p class="mini soluk bosluk-ust">
        Önbellek:
        <?php if ($weatherCache['var']): ?>
          <?= esc($weatherCache['cached_at']) ?> (<?= esc($weatherCache['yas']) ?> önce)
          <?= $weatherCache['taze'] ? admin_badge('taze', 'olumlu') : admin_badge('süresi geçti', 'uyari') ?>
        <?php else: ?>
          <span class="soluk">yok</span>
        <?php endif; ?>
      </p>
      <div id="weatherTest" class="bosluk-ust" hidden></div>
    </div>

    <details class="mini bosluk-ust">
      <summary>Beklenen JSON biçimi</summary>
      <pre class="kod-alan" style="white-space:pre-wrap;margin:8px 0 0"><?= esc(widgets_sample_json('weather')) ?></pre>
    </details>
  </div>
</div>

<div class="arac-cubugu">
  <button type="button" class="dugme birincil" id="widgetKaydet2">Kaydet</button>
  <button type="button" class="dugme" data-temizle="">Tüm widget önbelleğini temizle</button>
</div>

<!-- ============================================================ bülten aboneleri -->
<div class="kart">
  <div class="kart-baslik">Bülten aboneleri</div>
  <?php if (!$aboneVar): ?>
    <div class="uyari warn">Abone tablosu henüz oluşturulmamış. Göç (migration) çalıştırıldığında görünecektir.</div>
  <?php else: ?>
    <p class="kucuk soluk">
      Toplam <strong><?= (int)$aboneSayisi ?></strong> abone. Manşet yalnız abone <strong>toplar</strong>;
      toplu e-posta gönderim motoru kapsam dışıdır. Listeyi dışa aktarıp kendi gönderim aracınızda kullanabilirsiniz.
    </p>
    <div class="dugme-grup bosluk-alt">
      <a class="dugme" href="<?= esc(base_url()) ?>/api.php?a=newsletter.export">CSV indir</a>
    </div>
    <?php if (!$aboneler): ?>
      <div class="bos-durum"><h3>Henüz abone yok</h3>
        <p>Anasayfa blok diziliminde “Bülten Kaydı” bloğu açıksa form ön yüzde görünür.</p></div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th>E-posta</th><th class="dar">IP</th><th class="dar">Tarih</th></tr></thead>
          <tbody>
            <?php foreach ($aboneler as $a): ?>
              <tr>
                <td><?= esc((string)$a['email']) ?></td>
                <td class="dar mini soluk"><?= esc((string)$a['ip']) ?></td>
                <td class="dar mini soluk"><?= esc(tr_date((string)$a['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($aboneSayisi > count($aboneler)): ?>
        <p class="mini soluk">Son <?= count($aboneler) ?> kayıt gösteriliyor; tamamı için CSV indirin.</p>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
M.hazir(function () {
  /* ------------------------------------------------ kip görünürlüğü */
  function kipGuncelle(tur) {
    var sec = document.getElementById(tur + 'Mode');
    if (!sec) { return; }
    var mod = sec.value === 'remote' ? 'remote' : 'manual';
    M.qsa('[data-kip="' + tur + '-manual"]').forEach(function (el) { el.hidden = (mod !== 'manual'); });
    M.qsa('[data-kip="' + tur + '-remote"]').forEach(function (el) { el.hidden = (mod !== 'remote'); });
  }
  ['market', 'weather'].forEach(function (tur) {
    var sec = document.getElementById(tur + 'Mode');
    if (sec) { sec.addEventListener('change', function () { kipGuncelle(tur); }); }
    kipGuncelle(tur);
  });

  function deger(id) {
    var el = document.getElementById(id);
    return el ? String(el.value || '').trim() : '';
  }

  /* ------------------------------------------------ kaydet */
  function kaydet(btn) {
    btn.disabled = true;
    M.api('widgets.save', {
      market_mode:  deger('marketMode'),
      market_url:   deger('marketUrl'),
      market_map:   deger('marketMap'),
      market_data:  { USD: deger('mUSD'), EUR: deger('mEUR'), GRAM_ALTIN: deger('mGRAM') },
      weather_mode: deger('weatherMode'),
      weather_url:  deger('weatherUrl'),
      weather_map:  deger('weatherMap'),
      weather_data: { sehir: deger('wSehir'), derece: deger('wDerece'), durum: deger('wDurum') }
    }).then(function (r) {
      if (M.sonuc(r, 'Widget ayarları kaydedildi.')) { setTimeout(function () { location.reload(); }, 500); }
      else { btn.disabled = false; }
    });
  }
  ['widgetKaydet', 'widgetKaydet2'].forEach(function (id) {
    var b = document.getElementById(id);
    if (b) { b.addEventListener('click', function () { kaydet(b); }); }
  });

  /* ------------------------------------------------ çek ve test et */
  M.qsa('[data-test]').forEach(function (b) {
    b.addEventListener('click', function () {
      var tur = b.getAttribute('data-test');
      var kutu = document.getElementById(tur + 'Test');
      var url = deger(tur + 'Url');
      if (!url) { M.toast('warn', 'Önce harici JSON adresini girin.'); return; }
      b.disabled = true;
      kutu.hidden = false;
      kutu.innerHTML = '<span class="yukleniyor">Çekiliyor…</span>';
      M.api('widgets.test', { tur: tur, url: url }).then(function (r) {
        b.disabled = false;
        if (!r.ok) {
          kutu.innerHTML = '<div class="uyari err">' + M.esc(r.error || 'Veri çekilemedi.') + '</div>';
          return;
        }
        var html = '';
        if (r.uyari) { html += '<div class="uyari warn">' + M.esc(r.uyari) + '</div>'; }
        html += '<div class="uyari ok">Veri çekildi. Önbellek: ' + M.esc(r.cached_at || '-') + '</div>'
              + '<p class="mini soluk">Gelen ham JSON</p>'
              + '<pre class="kod-alan" style="white-space:pre-wrap;max-height:220px;overflow:auto">'
              + M.esc(r.ham || '') + '</pre>'
              + '<p class="mini soluk">Eşlenmiş sonuç</p>'
              + '<pre class="kod-alan" style="white-space:pre-wrap;max-height:180px;overflow:auto">'
              + M.esc(JSON.stringify(r.eslenmis, null, 2)) + '</pre>';
        kutu.innerHTML = html;
      });
    });
  });

  /* ------------------------------------------------ önbellek temizle */
  M.qsa('[data-temizle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var tur = b.getAttribute('data-temizle');
      b.disabled = true;
      M.api('widgets.clear_cache', { tur: tur }).then(function (r) {
        b.disabled = false;
        if (M.sonuc(r, 'Önbellek temizlendi.')) { setTimeout(function () { location.reload(); }, 450); }
      });
    });
  });
});
</script>
