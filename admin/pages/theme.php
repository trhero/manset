<?php
/**
 * Panel — Tema Editörü (Ajan-7).
 *
 * Solda sekmeli ayar paneli, sağda canlı önizleme (iframe).
 * Tüm yazma işleri inc/api/theme_api.php uçlarından geçer; bu dosya
 * yalnız iskeleti basar ve şemayı window.__TEMA ile assets/theme-editor.js'e verir.
 *
 * Sözleşme: CONTRACTS.md §5 (tema motoru) · §8 (panel) · §8.2 (sınıf sözlüğü)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('theme.manage');

$adminPageTitle = 'Tema Editörü';

// theme_api_* yardımcıları ve uç tanımları (api_register API bağlamı dışında yalnız diziye yazar)
require_once INC_DIR . '/api/theme_api.php';

$aktifTema = theme_name();

// -------------------------------------------------------------- kurulu temalar
$temalar = [];
foreach (array_keys(theme_list()) as $ad) { $temalar[$ad] = theme_api_meta($ad); }

// -------------------------------------------------------------- etkin temanın şeması
$schema = [];
foreach (theme_setting_schema($aktifTema) as $f) {
    if (empty($f['key'])) { continue; }
    $schema[] = [
        'key'        => (string)$f['key'],
        'label'      => (string)arr($f, 'label', $f['key']),
        'type'       => (string)arr($f, 'type', 'text'),
        'default'    => (string)arr($f, 'default', ''),
        'css_var'    => (string)arr($f, 'css_var', ''),
        'group'      => (string)arr($f, 'group', 'Gelişmiş'),
        'unit'       => (string)arr($f, 'unit', ''),
        'help'       => (string)arr($f, 'help', ''),
        'min'        => (string)arr($f, 'min', ''),
        'max'        => (string)arr($f, 'max', ''),
        'options'    => is_array(arr($f, 'options', null)) ? $f['options'] : [],
        'admin_only' => !empty($f['admin_only']),
    ];
}
$values = [];
foreach ($schema as $f) { $values[$f['key']] = (string)theme_setting($f['key'], $f['default'], $aktifTema); }

// -------------------------------------------------------------- blok dizilimi
$bloklar = theme_api_blocks_raw();
$kategoriler = [];
foreach (all_categories(false) as $c) {
    $kategoriler[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'active' => (int)$c['active']];
}
$slotlar = [];
foreach (theme_api_ad_slot_labels() as $v => $l) { $slotlar[] = ['value' => $v, 'label' => $l]; }

// -------------------------------------------------------------- JS'e geçirilecek paket
$temaPaket = [
    'aktif'        => $aktifTema,
    'temalar'      => $temalar,
    'schema'       => $schema,
    'values'       => $values,
    'supportsDark' => !empty(theme_json($aktifTema)['supports_dark']),
    'fonts'        => theme_api_font_stacks(),
    'units'        => theme_api_size_units(),
    'cssLimit'     => theme_api_css_limit(),
    'blocks'       => $bloklar,
    'blockLabels'  => home_block_labels(),
    'blockTypes'   => theme_api_block_types(),
    'categories'   => $kategoriler,
    'slots'        => $slotlar,
    'site'         => [
        'logo'    => (string)setting('site_logo', ''),
        'favicon' => (string)setting('site_favicon', ''),
    ],
    'onizlemeUrl' => base_url() . '/',
    'uploads'     => base_url() . '/uploads/',
    'yonetici'    => can($adminUser, 'theme.manage'),
];
?>

<div class="sayfa-basligi">
  <h1>Tema Editörü</h1>
  <div class="dugme-grup">
    <span class="rozet bilgi" id="temaDurum">Düzenlenen tema: <?= esc($temalar[$aktifTema]['name']) ?></span>
    <button type="button" class="dugme" id="temaYenile">Önizlemeyi yenile</button>
    <button type="button" class="dugme birincil" id="temaKaydet">Kaydet</button>
  </div>
</div>

<div class="te-duzen">

  <!-- ================================================== SOL: ayar paneli -->
  <div class="te-panel">

    <!-- 1) Tema seçimi -->
    <div class="kart">
      <div class="kart-baslik">Tema</div>
      <div class="te-tema-izgara">
        <?php foreach ($temalar as $ad => $t): ?>
          <div class="te-tema<?= $t['active'] ? ' etkin' : '' ?>" data-tema="<?= esc($ad) ?>">
            <span class="te-serit" style="background:<?= esc($t['preview']) ?>"></span>
            <div class="te-tema-govde">
              <div class="esnek-arasi">
                <strong><?= esc($t['name']) ?></strong>
                <?php if ($t['active']): ?><?= admin_badge('etkin', 'olumlu') ?><?php endif; ?>
              </div>
              <p class="mini soluk"><?= esc($t['description'] !== '' ? excerpt($t['description'], 120) : 'Açıklama girilmemiş.') ?></p>
              <div class="esnek">
                <?php if ($t['supports_dark']): ?><span class="rozet notr mini">karanlık mod</span><?php endif; ?>
                <span class="rozet notr mini"><?= esc($ad) ?></span>
              </div>
              <?php if (!$t['active']): ?>
                <button type="button" class="dugme kucuk te-kullan" data-tema="<?= esc($ad) ?>">Bu temayı kullan</button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mini soluk bosluk-ust">Her tema kendi ayar profilini saklar
        (<code>theme:&lt;tema&gt;:&lt;anahtar&gt;</code>). Tema değiştirdiğinizde diğerinin ayarları silinmez.</p>
    </div>

    <!-- 2) Ayarlar (şemadan otomatik üretilir) -->
    <div class="kart">
      <div class="kart-baslik">
        Ayarlar
        <span class="mini soluk" id="temaSayaci"></span>
      </div>
      <div class="sekmeler" id="temaSekmeler" role="tablist"></div>
      <form id="temaForm" autocomplete="off">
        <div id="temaAlanlar"><p class="soluk">Yükleniyor…</p></div>
      </form>
    </div>

    <!-- 3) Logo / favicon (site ayarı — tema ayarı değil) -->
    <div class="kart">
      <div class="kart-baslik">Logo ve favicon</div>
      <p class="mini soluk">Bunlar <strong>site</strong> ayarıdır; tema değiştirildiğinde korunur.</p>

      <div class="form-alan">
        <label class="form-etiket" for="siteLogo">Logo</label>
        <div class="esnek">
          <input type="text" id="siteLogo" value="<?= esc(setting('site_logo', '')) ?>" readonly style="flex:1">
          <button type="button" class="dugme kucuk" data-medya="siteLogo">Seç</button>
          <button type="button" class="dugme kucuk" data-medya-sil="siteLogo">Kaldır</button>
        </div>
        <div class="gorsel-onizleme bosluk-ust" id="siteLogoOnizleme"<?= setting('site_logo', '') === '' ? ' hidden' : '' ?>>
          <?php if (setting('site_logo', '') !== ''): ?>
            <img src="<?= esc(url_upload(setting('site_logo'))) ?>" alt="">
          <?php endif; ?>
        </div>
      </div>

      <div class="form-alan">
        <label class="form-etiket" for="siteFavicon">Favicon</label>
        <div class="esnek">
          <input type="text" id="siteFavicon" value="<?= esc(setting('site_favicon', '')) ?>" readonly style="flex:1">
          <button type="button" class="dugme kucuk" data-medya="siteFavicon">Seç</button>
          <button type="button" class="dugme kucuk" data-medya-sil="siteFavicon">Kaldır</button>
        </div>
        <div class="gorsel-onizleme bosluk-ust" id="siteFaviconOnizleme"<?= setting('site_favicon', '') === '' ? ' hidden' : '' ?>>
          <?php if (setting('site_favicon', '') !== ''): ?>
            <img src="<?= esc(url_upload(setting('site_favicon'))) ?>" alt="" style="max-height:64px">
          <?php endif; ?>
        </div>
        <span class="form-yardim">32×32 PNG önerilir.</span>
      </div>

      <button type="button" class="dugme" id="siteKaydet">Logo/favicon kaydet</button>
    </div>

    <!-- 4) Anasayfa blok dizilimi -->
    <div class="kart">
      <div class="kart-baslik">
        Anasayfa blokları
        <span class="mini soluk">settings['home_blocks']</span>
      </div>
      <p class="mini soluk">Sıralama <strong>▲ / ▼</strong> düğmeleriyle değiştirilir. Kapatılan blok
        anasayfada basılmaz. Tema desteklemediği bloğu sessizce atlar.</p>

      <div id="blokListe"><p class="soluk">Yükleniyor…</p></div>

      <div class="arac-cubugu bosluk-ust">
        <select id="blokEkleTip" style="min-width:180px"></select>
        <button type="button" class="dugme kucuk" id="blokEkle">Blok ekle</button>
        <span class="sag">
          <button type="button" class="dugme birincil kucuk" id="blokKaydet">Blokları kaydet</button>
        </span>
      </div>
    </div>

    <!-- 5) Varsayılana dön -->
    <div class="kart">
      <div class="kart-baslik">Varsayılana dön</div>
      <p class="mini soluk">Sıfırlama yalnız ilgili kayıtları siler; içerik ve diğer temaların
        ayarları etkilenmez.</p>
      <div class="dugme-grup">
        <button type="button" class="dugme tehlike" id="temaSifirla"
          data-onay="Bu temanın tüm ayarları silinecek ve theme.json varsayılanlarına dönülecek. Onaylıyor musunuz?">
          Bu temanın ayarlarını sıfırla
        </button>
        <button type="button" class="dugme tehlike" id="blokSifirla"
          data-onay="Anasayfa blok dizilimi varsayılana dönecek. Onaylıyor musunuz?">
          Blok dizilimini sıfırla
        </button>
      </div>
    </div>
  </div>

  <!-- ================================================== SAĞ: canlı önizleme -->
  <div class="te-onizleme-sarma">
    <div class="kart sikisik te-onizleme-kart">
      <div class="arac-cubugu" style="margin-bottom:10px">
        <div class="dugme-grup" role="group" aria-label="Cihaz genişliği">
          <button type="button" class="dugme kucuk etkin" data-cihaz="masaustu">Masaüstü</button>
          <button type="button" class="dugme kucuk" data-cihaz="tablet">Tablet</button>
          <button type="button" class="dugme kucuk" data-cihaz="mobil">Mobil</button>
        </div>
        <span class="sag mini soluk" id="onizlemeDurum">önizleme hazırlanıyor…</span>
      </div>

      <div class="te-cerceve" id="onizlemeCerceve">
        <iframe id="onizlemeCerceveIc" title="Site önizlemesi" src="about:blank"
                referrerpolicy="same-origin" loading="eager"></iframe>
      </div>

      <p class="mini soluk" style="margin:10px 0 0">
        Renk ve ölçü değişiklikleri önizlemeye <strong>anında</strong> yansır.
        Yerleşim seçimleri ve blok dizilimi için “Önizlemeyi yenile” düğmesini kullanın.
      </p>
    </div>
  </div>
</div>

<style>
/* Tema Editörü'ne özgü yerleşim. Ortak bileşenler assets/admin.css sınıflarını
   kullanır (§8.2); burada yalnız bu ekrana ait `te-` önekli kurallar var. */
.te-duzen{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:20px;align-items:start}
@media(max-width:1100px){.te-duzen{grid-template-columns:1fr}}
.te-panel{min-width:0}
.te-onizleme-sarma{min-width:0;position:sticky;top:calc(var(--ust) + 16px)}
@media(max-width:1100px){.te-onizleme-sarma{position:static}}
.te-onizleme-kart{margin-bottom:18px}
.te-cerceve{
  background:var(--yuzey-2);border:1px solid var(--cizgi);border-radius:var(--kose);
  padding:10px;display:flex;justify-content:center;overflow:hidden;
}
.te-cerceve iframe{
  width:100%;max-width:100%;height:74vh;min-height:460px;border:0;background:#fff;
  border-radius:4px;box-shadow:0 1px 5px rgba(16,24,40,.12);transition:width .18s ease;
}
@media(max-width:1100px){.te-cerceve iframe{height:60vh}}

.te-tema-izgara{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.te-tema{border:1px solid var(--cizgi);border-radius:var(--kose);overflow:hidden;background:var(--yuzey)}
.te-tema.etkin{border-color:var(--ana);box-shadow:0 0 0 1px var(--ana) inset}
.te-serit{display:block;height:6px}
.te-tema-govde{padding:10px 12px 12px}
.te-tema-govde p{margin:6px 0 8px}
.te-tema-govde .esnek{gap:6px;margin-bottom:9px}
.te-tema-govde .rozet.mini{font-size:10.5px;padding:1px 7px}

.te-alan-grup{margin-bottom:4px}
.te-sinir{display:flex;gap:9px;align-items:center}
.te-sinir input[type=range]{flex:1;min-width:90px;width:auto;padding:0}
.te-sinir input[type=number]{width:92px;flex:0 0 92px}
.te-birim{font-size:12px;color:var(--soluk);min-width:26px}
.te-css-sayac{font-size:12px;color:var(--soluk);text-align:right;margin-top:3px}
.te-css-sayac.dolu{color:var(--olumsuz);font-weight:600}

.te-blok{
  display:grid;grid-template-columns:auto 1fr auto;gap:10px;align-items:start;
  border:1px solid var(--cizgi);border-radius:var(--kose);padding:10px 12px;margin-bottom:9px;background:var(--yuzey);
}
.te-blok.kapali{opacity:.55;background:var(--yuzey-2)}
.te-blok-sira{display:flex;flex-direction:column;gap:3px}
.te-blok-sira button{
  border:1px solid var(--cizgi);background:var(--yuzey);border-radius:4px;cursor:pointer;
  width:26px;height:22px;line-height:1;font-size:11px;color:var(--soluk);padding:0;
}
.te-blok-sira button:hover{background:var(--yuzey-2);color:var(--metin)}
.te-blok-orta{min-width:0}
.te-blok-ad{font-weight:600;font-size:14px;margin-bottom:6px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.te-blok-alanlar{display:flex;gap:8px;flex-wrap:wrap}
.te-blok-alanlar label{font-size:11.5px;color:var(--soluk);font-weight:600;display:block}
.te-blok-alanlar input,.te-blok-alanlar select{font-size:13px;padding:5px 8px;min-width:0}
.te-blok-alanlar .te-w-baslik{flex:1 1 150px;min-width:130px}
.te-blok-alanlar .te-w-adet{flex:0 0 78px}
.te-blok-alanlar .te-w-secim{flex:1 1 140px;min-width:120px}
.te-blok-sag{display:flex;flex-direction:column;gap:6px;align-items:flex-end}
</style>

<script>window.__TEMA = <?= json_encode($temaPaket, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="<?= esc(url_asset('theme-editor.js')) ?>?v=<?= esc(MANSET_VERSION) ?>"></script>
