<?php
/**
 * Panel — Künye / BİK bilgileri. (Ajan-9)
 *
 * Alanlar kunye_field_defs() tanımından otomatik üretilir; şemada olmayan
 * anahtar yazılmaz. BİK/EİDS doğrulama kodu ham saklanır ve YALNIZ bu ekrandan
 * (settings.manage izniyle) girilebilir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('settings.manage');

$adminPageTitle = 'Künye / BİK';

// ---------------------------------------------------------------- kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'save') {
    csrf_guard();
    $saved = [];
    $hata = '';
    foreach (kunye_field_defs() as $f) {
        $key = $f['key'];
        $raw = inp($key, null);
        if ($raw === null) { continue; }
        if (!empty($f['raw'])) {
            // Doğrulama kodu temizlenmez; yalnız uzunluk sınırlanır.
            $code = trim((string)$raw);
            if (mb_strlen($code) > KUNYE_BIK_MAX) {
                $hata = 'Doğrulama kodu en fazla ' . KUNYE_BIK_MAX . ' karakter olabilir.';
                continue;
            }
            $saved[$key] = $code;
            continue;
        }
        $saved[$key] = $f['type'] === 'textarea'
            ? sanitize_plain((string)$raw, 2000)
            : sanitize_line((string)$raw, 255);
    }
    setting_set_many($saved);
    settings_all(true);
    if (function_exists('cache_flush')) { cache_flush(); }

    if ($hata !== '') { flash('warn', $hata . ' Diğer alanlar kaydedildi.'); }
    else { flash('ok', 'Künye bilgileri kaydedildi.'); }
    header('Location: ' . admin_url('kunye'), true, 302);
    exit;
}

$eksik = kunye_missing_required();
$gruplar = kunye_field_groups();

// Alanları gruba göre kümele
$alanlar = [];
foreach (kunye_field_defs() as $f) { $alanlar[$f['group']][] = $f; }

// Sağ kolondaki önizleme — /kunye gövdesinin birebir çıktısı.
// BİK kodu panelde ÇALIŞTIRILMAZ; yerine yer tutucu konur.
$onizleme = kunye_render_html();
$bikKod = trim(kunye_bik_embed());
if ($bikKod !== '') {
    $onizleme = str_replace(
        '<div class="kunye-bik">' . $bikKod . '</div>',
        '<div class="kunye-bik"><em>[Basın İlan Kurumu doğrulama kodu burada görünür]</em></div>',
        $onizleme
    );
}

// Canlı önizleme için alan üstverisi (JS tarafında yeniden çizilir)
$jsAlanlar = [];
foreach (kunye_field_defs() as $f) {
    if (!empty($f['raw'])) { continue; }
    $jsAlanlar[] = ['key' => $f['key'], 'label' => $f['label'], 'group' => $f['group'],
                    'type' => $f['type'], 'required' => !empty($f['required'])];
}
$jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<div class="sayfa-basligi">
  <h1>Künye / BİK</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(url('kunye')) ?>" target="_blank" rel="noopener">Ön yüzdeki künye ↗</a>
    <button type="submit" form="kunyeForm" class="dugme birincil">Kaydet</button>
  </div>
</div>

<div id="eksikUyari" class="uyari warn" <?= $eksik ? '' : 'hidden' ?>>
  <strong>Doldurulmamış zorunlu alanlar var.</strong>
  Basın Kanunu m.4 uyarınca ana sayfadan doğrudan erişilebilir olması beklenen bilgiler
  (bkz. <code>docs/BIK-NOTLARI.md</code> §2):
  <ul id="eksikListe" style="margin:8px 0 0 18px">
    <?php foreach ($eksik as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?>
  </ul>
</div>

<div class="izgara-2">
  <div>
    <form method="post" id="kunyeForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">

      <?php foreach ($gruplar as $grup => $not): ?>
        <?php if (empty($alanlar[$grup])) { continue; } ?>
        <div class="kart">
          <div class="kart-baslik"><?= esc($grup) ?></div>
          <p class="kucuk soluk" style="margin-top:-4px"><?= esc($not) ?></p>

          <?php foreach ($alanlar[$grup] as $f): ?>
            <?php if (!empty($f['raw'])) { continue; }   // kod alanı ayrı kartta ?>
            <div class="form-alan">
              <label for="k_<?= esc($f['key']) ?>">
                <?= esc($f['label']) ?>
                <?php if (!empty($f['required'])): ?><span class="rozet uyari mini">zorunlu</span><?php endif; ?>
              </label>
              <?php if ($f['type'] === 'textarea'): ?>
                <textarea id="k_<?= esc($f['key']) ?>" name="<?= esc($f['key']) ?>" rows="3"
                          data-kunye="<?= esc($f['key']) ?>"><?= esc(setting($f['key'], '')) ?></textarea>
              <?php else: ?>
                <input type="<?= esc($f['type'] === 'email' ? 'email' : ($f['type'] === 'tel' ? 'tel' : 'text')) ?>"
                       id="k_<?= esc($f['key']) ?>" name="<?= esc($f['key']) ?>" maxlength="255"
                       data-kunye="<?= esc($f['key']) ?>" value="<?= esc(setting($f['key'], '')) ?>">
              <?php endif; ?>
              <?php if (!empty($f['help'])): ?><span class="form-yardim"><?= esc($f['help']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>

      <?php $bik = kunye_field_def('bik_eids_kodu'); ?>
      <div class="kart">
        <div class="kart-baslik">Basın İlan Kurumu / EİDS doğrulama kodu</div>
        <div class="uyari warn">
          Bu alandaki kod siteye <strong>olduğu gibi</strong> eklenir.
          Yalnız Basın İlan Kurumu’nun verdiği doğrulama kodunu yapıştırın.
        </div>
        <div class="form-alan">
          <label for="k_bik_eids_kodu"><?= esc($bik ? $bik['label'] : 'Doğrulama kodu') ?></label>
          <textarea id="k_bik_eids_kodu" name="bik_eids_kodu" rows="5" class="kod-alan"
                    spellcheck="false"><?= esc(setting('bik_eids_kodu', '')) ?></textarea>
          <span class="form-yardim">
            Kod künye sayfasının sonunda basılır. En fazla <?= (int)KUNYE_BIK_MAX ?> karakter.
            Boş bırakılırsa hiçbir şey eklenmez.
          </span>
        </div>
      </div>

      <button type="submit" class="dugme birincil">Kaydet</button>
    </form>
  </div>

  <div>
    <div class="kart">
      <div class="kart-baslik">Önizleme — <code>/kunye</code></div>
      <p class="mini soluk">Boş bırakılan alanlar künyede basılmaz. Alanları yazdıkça önizleme güncellenir;
        kalıcı olması için <strong>Kaydet</strong> demeniz gerekir.</p>
      <div id="kunyeOnizleme" class="kunye-onizleme"><?= $onizleme ?></div>
    </div>

    <div class="uyari bilgi">
      <strong>Yayıncı sorumluluğu.</strong>
      Mevzuat değişebilir. Yürürlükteki güncel Basın Kanunu ve Basın İlan Kurumu düzenlemelerini
      resmî kaynaklardan teyit etmek yayıncının sorumluluğundadır.
      Bu ekrandaki alan listesi <code>docs/BIK-NOTLARI.md</code> belgesindeki derlemeye dayanır ve
      hukuki görüş değildir.
    </div>

    <div class="kart">
      <div class="kart-baslik">İlgili ekranlar</div>
      <p class="kucuk"><a href="<?= esc(admin_url('corrections')) ?>">Düzeltme Talepleri →</a></p>
      <p class="kucuk"><a href="<?= esc(admin_url('pages')) ?>">Sabit Sayfalar (Yayın İlkeleri) →</a></p>
      <p class="kucuk"><a href="<?= esc(admin_url('settings')) ?>">Ayarlar →</a></p>
    </div>
  </div>
</div>

<style>
/* Künye önizlemesi ön yüz gövdesini taklit eder; admin.css'te tanım listesi stili yok. */
.kunye-onizleme{font-size:14px;line-height:1.6;max-height:640px;overflow:auto}
.kunye-onizleme h2{font-size:15px;margin:16px 0 6px;padding-bottom:5px;border-bottom:1px solid var(--cizgi)}
.kunye-onizleme h2:first-child{margin-top:0}
.kunye-onizleme dl{margin:0}
.kunye-onizleme dt{margin-top:8px;font-size:13px;color:var(--soluk)}
.kunye-onizleme dd{margin:0 0 4px}
.kunye-onizleme dd p{margin:0}
.kunye-onizleme .kunye-bik{margin-top:14px;padding:9px;border:1px dashed var(--cizgi);border-radius:6px;font-size:12px}
.rozet.mini{font-size:10px;padding:1px 6px;margin-left:6px;vertical-align:middle}
</style>

<script>
M.hazir(function () {
  var ALANLAR = <?= json_encode($jsAlanlar, $jsFlags) ?>;
  var GRUPLAR = <?= json_encode($gruplar, $jsFlags) ?>;
  var SABIT   = <?= json_encode([
      'policy' => (function_exists('page_by_slug') && page_by_slug('yayin-ilkeleri')) ? 'var' : '',
      'bik'    => $bikKod !== '' ? 'var' : '',
  ], $jsFlags) ?>;

  var onizleme = document.getElementById('kunyeOnizleme');
  var eksikKutu = document.getElementById('eksikUyari');
  var eksikListe = document.getElementById('eksikListe');
  if (!onizleme) { return; }

  function deger(key) {
    var el = document.querySelector('[data-kunye="' + key + '"]');
    return el ? String(el.value || '').trim() : '';
  }

  function satirDeger(alan, v) {
    if (alan.type === 'email') { return '<a href="mailto:' + M.esc(v) + '">' + M.esc(v) + '</a>'; }
    if (alan.type === 'tel')   { return '<a href="tel:' + M.esc(v.replace(/[^0-9+]/g, '')) + '">' + M.esc(v) + '</a>'; }
    if (alan.type === 'textarea') { return M.esc(v).replace(/\n/g, '<br>'); }
    return M.esc(v);
  }

  function ciz() {
    var html = '';
    var doluVar = false;
    Object.keys(GRUPLAR).forEach(function (grup) {
      var satirlar = '';
      ALANLAR.forEach(function (a) {
        if (a.group !== grup) { return; }
        var v = deger(a.key);
        if (!v) { return; }
        doluVar = true;
        satirlar += '<dt><strong>' + M.esc(a.label) + '</strong></dt>'
                  + '<dd><p>' + satirDeger(a, v) + '</p></dd>';
      });
      if (!satirlar) { return; }
      html += '<h2>' + M.esc(grup) + '</h2>'
            + '<p><small>' + M.esc(GRUPLAR[grup]) + '</small></p>'
            + '<dl>' + satirlar + '</dl>';
    });
    if (!doluVar) {
      html = '<p>Künye bilgileri henüz girilmedi. Yönetim panelinden <strong>Künye / BİK</strong> ekranını doldurabilirsiniz.</p>';
    }
    if (SABIT.policy) {
      html += '<h2>Yayın ilkeleri</h2><p>Yayın İlkeleri sayfasında yayın kuruluşunun izlediği ilkeler yer alır.</p>';
    }
    html += '<h2>Düzeltme ve cevap hakkı</h2>'
          + '<p>Basın Kanunu m.14 uyarınca, kişilik haklarının zarar gördüğünü düşünen okuyucular '
          + 'düzeltme ve cevap hakkını kullanabilir. Talepler, haber sayfalarının altındaki '
          + '<strong>“Düzeltme talep et”</strong> formundan iletilir.</p>';
    if (document.getElementById('k_bik_eids_kodu') && document.getElementById('k_bik_eids_kodu').value.trim()) {
      html += '<div class="kunye-bik"><em>[Basın İlan Kurumu doğrulama kodu burada görünür]</em></div>';
    }
    onizleme.innerHTML = html;
  }

  function eksikGuncelle() {
    var eksik = ALANLAR.filter(function (a) { return a.required && !deger(a.key); });
    if (!eksikKutu || !eksikListe) { return; }
    eksikListe.innerHTML = eksik.map(function (a) { return '<li>' + M.esc(a.label) + '</li>'; }).join('');
    eksikKutu.hidden = eksik.length === 0;
  }

  M.qsa('[data-kunye]').forEach(function (el) {
    el.addEventListener('input', function () { ciz(); eksikGuncelle(); });
  });
  var kodAlan = document.getElementById('k_bik_eids_kodu');
  if (kodAlan) { kodAlan.addEventListener('input', ciz); }
});
</script>
