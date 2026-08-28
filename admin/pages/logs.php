<?php
/**
 * Panel — Günlükler: db/error.log kayıtları, yapay zekâ günlüğü özeti, cron raporu.
 *
 * Günlük dosyası sondan geriye doğru okunur (tools_log_raw_tail); çok büyük
 * dosyalarda bile bellek şişmez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('tools.manage');

$adminPageTitle = 'Günlükler';

$logFile = DB_DIR . '/error.log';
$logVar = is_file($logFile);
$logBoyut = $logVar ? (int)filesize($logFile) : 0;
$logTurleri = function_exists('tools_log_types')
    ? tools_log_types()
    : ['UNCAUGHT' => 'Yakalanmamış hata', 'PHP' => 'PHP uyarısı', 'CSRF' => 'CSRF reddi', 'CRON' => 'Zamanlanmış görev', 'DIGER' => 'Diğer'];

$cronSon = setting('cron_last_run', '');
$cronRapor = setting('cron_last_report', '');

$aiOzet = null;
if (function_exists('ai_logs_summary') && schema_has_table('ai_logs')) {
    try { $aiOzet = ai_logs_summary(30); } catch (Throwable $e) { $aiOzet = null; }
}
?>

<div class="sayfa-basligi">
  <h1>Günlükler</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('tools')) ?>">&larr; Araçlar</a>
    <?php if ($logVar): ?>
      <a class="dugme" href="<?= esc(base_url()) ?>/api.php?a=logs.download">Günlüğü indir</a>
      <button type="button" class="dugme tehlike" id="logTemizle"
        data-onay="Hata günlüğü tamamen silinecek. Onaylıyor musunuz?">Günlüğü temizle</button>
    <?php endif; ?>
  </div>
</div>

<div class="izgara-3 bosluk-alt">
  <div class="sayac <?= $logBoyut > 1048576 ? 'vurgulu' : '' ?>">
    <div class="deger"><?= esc(function_exists('backup_human_size') ? backup_human_size($logBoyut) : $logBoyut . ' B') ?></div>
    <div class="etiket">Hata günlüğü boyutu</div>
  </div>
  <div class="sayac">
    <div class="deger"><?= $cronSon !== '' ? esc(tr_ago($cronSon)) : '—' ?></div>
    <div class="etiket">Son cron turu</div>
  </div>
  <div class="sayac">
    <div class="deger"><?= $aiOzet ? (int)$aiOzet['calls'] : '—' ?></div>
    <div class="etiket">Yapay zekâ çağrısı (30 gün)</div>
  </div>
</div>

<?php if ($cronSon === ''): ?>
  <div class="uyari warn">
    <strong>Zamanlanmış görev hiç çalışmamış.</strong>
    RSS çekimi, yapay zekâ kuyruğu ve önbellek bakımı için
    <a href="<?= esc(admin_url('settings')) ?>">Ayarlar</a> ekranındaki cron komutunu hosting panelinize ekleyin.
  </div>
<?php endif; ?>

<div class="kart">
  <div class="kart-baslik">Hata günlüğü</div>

  <?php if (!$logVar): ?>
    <div class="bos-durum">
      <span class="buyuk" aria-hidden="true">✓</span>
      <h3>Günlük dosyası yok</h3>
      <p>Henüz kayda değer bir hata oluşmamış.</p>
    </div>
  <?php else: ?>
    <div class="arac-cubugu">
      <label class="gizli" for="logTur">Tür</label>
      <select id="logTur">
        <option value="">Tüm türler</option>
        <?php foreach ($logTurleri as $k => $v): ?>
          <option value="<?= esc($k) ?>"><?= esc($v) ?></option>
        <?php endforeach; ?>
      </select>
      <label class="gizli" for="logAra">Ara</label>
      <input type="search" id="logAra" placeholder="Mesajda ara…" maxlength="80">
      <label class="gizli" for="logAdet">Kayıt sayısı</label>
      <select id="logAdet">
        <option value="50" selected>Son 50</option>
        <option value="100">Son 100</option>
        <option value="250">Son 250</option>
      </select>
      <button type="button" class="dugme sag" id="logYenile">Yenile</button>
    </div>

    <div class="tablo-sarma">
      <table class="tablo">
        <thead><tr><th style="width:150px">Tarih</th><th style="width:110px">Tür</th><th>Mesaj</th></tr></thead>
        <tbody id="logListe"><tr><td colspan="3" class="soluk">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
    <p class="mini soluk bosluk-ust">
      En yeni kayıt üstte. Günlük 2 MB'ı aştığında kendiliğinden devredilir
      (<code>error.log.1</code>).
    </p>
  <?php endif; ?>
</div>

<?php if ($cronRapor !== ''): ?>
  <div class="kart">
    <div class="kart-baslik">Son cron turu raporu</div>
    <p class="mini soluk"><?= esc($cronSon !== '' ? tr_date($cronSon) : '') ?></p>
    <pre class="mini" style="white-space:pre-wrap;margin:0"><?= esc($cronRapor) ?></pre>
  </div>
<?php endif; ?>

<?php if ($aiOzet): ?>
  <div class="kart">
    <div class="kart-baslik">Yapay zekâ kullanımı (son 30 gün)</div>
    <table class="tablo" style="min-width:0">
      <tr><th>Çağrı</th><td><?= (int)$aiOzet['calls'] ?></td></tr>
      <tr><th>Giriş jetonu</th><td><?= number_format((int)$aiOzet['tokens_in'], 0, ',', '.') ?></td></tr>
      <tr><th>Çıkış jetonu</th><td><?= number_format((int)$aiOzet['tokens_out'], 0, ',', '.') ?></td></tr>
      <tr><th>Hata</th><td><?= (int)$aiOzet['errors'] ?></td></tr>
      <tr><th>Ortalama süre</th><td><?= (int)$aiOzet['avg_ms'] ?> ms</td></tr>
    </table>
    <p class="mini soluk bosluk-ust">
      Ayrıntılı kayıtlar ve maliyet tahmini için
      <a href="<?= esc(admin_url('ai')) ?>">Yapay Zekâ &rarr; Günlükler</a> ekranına bakın.
    </p>
  </div>
<?php endif; ?>

<script>
M.hazir(function () {
  'use strict';

  var listeGovde = document.getElementById('logListe');
  if (!listeGovde) { return; }

  var turSecim = document.getElementById('logTur');
  var araAlan = document.getElementById('logAra');
  var adetSecim = document.getElementById('logAdet');

  var tonlar = { UNCAUGHT: 'olumsuz', PHP: 'uyari', CSRF: 'bilgi', CRON: 'notr', DIGER: 'notr' };

  function ciz(items) {
    if (!items.length) {
      listeGovde.innerHTML = '<tr><td colspan="3" class="soluk">Ölçütlere uyan kayıt yok.</td></tr>';
      return;
    }
    listeGovde.innerHTML = items.map(function (k) {
      var ton = tonlar[k.type] || 'notr';
      var mesaj = '<div class="log-mesaj">' + M.esc(k.message) + '</div>';
      if (k.context) { mesaj += '<span class="satir-alt">' + M.esc(k.context) + '</span>'; }
      return '<tr>'
        + '<td class="dar mini">' + M.esc(k.date) + '</td>'
        + '<td class="dar"><span class="rozet ' + ton + '">' + M.esc(k.type) + '</span></td>'
        + '<td>' + mesaj + '</td>'
        + '</tr>';
    }).join('');
  }

  function yukle() {
    listeGovde.innerHTML = '<tr><td colspan="3" class="soluk">Yükleniyor…</td></tr>';
    M.api('logs.tail', {
      lines: parseInt(adetSecim.value, 10) || 50,
      type: turSecim.value,
      q: araAlan.value
    }).then(function (r) {
      if (!r.ok) {
        listeGovde.innerHTML = '<tr><td colspan="3" class="uyari err">' + M.esc(r.error || 'Günlük okunamadı.') + '</td></tr>';
        return;
      }
      ciz(r.items || []);
    });
  }

  var zaman;
  araAlan.addEventListener('input', function () {
    clearTimeout(zaman);
    zaman = setTimeout(yukle, 300);
  });
  turSecim.addEventListener('change', yukle);
  adetSecim.addEventListener('change', yukle);
  var yenile = document.getElementById('logYenile');
  if (yenile) { yenile.addEventListener('click', yukle); }

  var temizle = document.getElementById('logTemizle');
  if (temizle) {
    temizle.addEventListener('click', function () {
      M.api('logs.clear', {}).then(function (r) {
        if (M.sonuc(r, 'Günlük temizlendi.')) { yukle(); }
      });
    });
  }

  yukle();
});
</script>

<style>
/* Günlük mesajları çok satırlı olabiliyor; tabloyu taşırmadan okunur kalsın. */
.log-mesaj{white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.5;max-height:6em;overflow:auto}
</style>
