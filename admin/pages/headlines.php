<?php
/**
 * Panel — manşet sırası, son dakika ve editörün seçimi.
 * Sahip: Ajan-3.  Uçlar: inc/api/vitrin.php (headlines.*)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('headlines.manage');

// Ortak vitrin yardımcıları (sınırlar, veri biçimleri) uç nokta modülünden gelir;
// panel bağlamında api_register() tanımlı olmadığı için orada kayıt bloğu atlanır.
require_once ROOT_DIR . '/inc/api/vitrin.php';
// 1.3-09: zamanlı manşet + manşete özel kısa başlık yardımcıları (aynı desen).
require_once ROOT_DIR . '/inc/api/headlines_api.php';

$adminPageTitle = 'Manşet Yönetimi';

/** PHP verisini <script> içine güvenle taşır: JSON.parse(<?= … ?>) için JS dize sabiti üretir. */
function headlines_js_data($data) {
    return json_encode(
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );
}

$payload = vitrin_headline_payload();

$categoryOptions = [];
foreach (all_categories(false) as $c) { $categoryOptions[(int)$c['id']] = $c['name']; }

// Anasayfa blok dizilimi manşeti gösteriyor mu? (yalnız bilgilendirme amaçlı)
$headlineBlockOn = false;
foreach (home_blocks() as $b) {
    if ($b['type'] === 'headline') { $headlineBlockOn = true; break; }
}

/* NEDEN (denetim 3 · cop-izin B10): Çöpteki haber manşette/son dakikada KALIYOR.
 * vitrin_is_published() `deleted_at` bakmadığı için çöpteki bir haber manşete
 * alınabiliyor; dahası zaten manşetteyken çöpe atılan haber, listeleri süzen
 * published_where() yüzünden bu ekrandan TAMAMEN kayboluyor — editör onu
 * manşetten çıkaramıyor ve haber geri alındığı anda sessizce yeniden manşete
 * dönüyor. Seçim listesi (headlines.search) zaten published_where() ile süzüyor,
 * yani çöptekiler orada görünmüyor; eksik olan, "görünmez ama bayrağı duran"
 * kayıtların editöre "çöpte" olarak GÖSTERİLMESİ. Aşağıdaki sorgu bunu yapar.
 * Sütun yoksa (göç 013 uygulanmamış) hiç sorgulanmaz, ekran çöker de değişmez de. */
$copluBayraklar = [];
if (function_exists('schema_has_column') && schema_has_column('posts', 'deleted_at')) {
    $copluBayraklar = qa('SELECT p.id, p.title, p.is_headline, p.is_breaking, c.name AS category_name
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.deleted_at <> \'\' AND (p.is_headline = 1 OR p.is_breaking = 1)
        ORDER BY p.headline_sort ASC, p.id DESC LIMIT 20');
}

/* 1.3-09 — zamanlı manşet ve manşete özel kısa başlık.
 * `vitrin_item()` biçimi Ajan-3'ün; oraya alan EKLENMEDİ. Yeni alanlar ayrı bir
 * eşlemede (id => {…}) taşınır ve JS listeyi çizerken üstüne bindirir. */
$hlV2 = headlines_v2_ready();
$hlIds = [];
foreach ($payload['items'] as $it) { $hlIds[] = (int)$it['id']; }
$hlMeta = headlines_extra($hlIds);
$bekleyenManset = headlines_pending_items();

$bootstrap = [
    'headlines'  => $payload['items'],
    'picks'      => $payload['picks'],
    'counts'     => $payload['counts'],
    'max'        => $payload['max'],
    'picksMax'   => $payload['picks_max'],
    'meta'       => $hlMeta,
    'pending'    => $bekleyenManset,
    'v2'         => $hlV2,
    'titleMax'   => headlines_title_max(),
];
?>

<div class="sayfa-basligi">
  <h1>Manşet Yönetimi</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(base_url()) ?>/" target="_blank" rel="noopener">Ön yüzü gör ↗</a>
  </div>
</div>

<div class="izgara-4 bosluk-alt">
  <div class="sayac vurgulu">
    <div class="deger" data-sayac="headline"><?= (int)$payload['counts']['headline'] ?></div>
    <div class="etiket">Manşetteki haber</div>
  </div>
  <div class="sayac">
    <div class="deger" data-sayac="breaking"><?= (int)$payload['counts']['breaking'] ?></div>
    <div class="etiket">Son dakika</div>
  </div>
  <div class="sayac">
    <div class="deger" data-sayac="picks"><?= (int)$payload['counts']['picks'] ?></div>
    <div class="etiket">Editörün seçimi</div>
  </div>
  <div class="sayac">
    <div class="deger" data-sayac="published"><?= (int)$payload['counts']['published'] ?></div>
    <div class="etiket">Yayındaki haber</div>
  </div>
</div>

<div class="uyari bilgi">
  Manşet bloğu, anasayfa blok dizilimindeki <strong>Manşet</strong> bloğu açıksa görünür.
  Blok dizilimini Tema Editörü'nden düzenleyebilirsiniz.
  <?php if (can($adminUser, 'theme.manage') && admin_page_exists('theme')): ?>
    <a href="<?= esc(admin_url('theme')) ?>">Tema Editörü'ne git →</a>
  <?php endif; ?>
</div>

<?php if (!$headlineBlockOn): ?>
  <div class="uyari warn">Şu anda anasayfa blok diziliminde <strong>Manşet</strong> bloğu kapalı görünüyor.
    Buradaki sıralama kaydedilir, ancak blok açılana dek ön yüzde görünmez.</div>
<?php endif; ?>

<?php /* cop-izin B10 — çöpteyken manşet/son dakika bayrağı üstünde kalan haberler.
         Aşağıdaki listeler bunları göstermez (published_where() süzer); editör
         burada görüp çöp kutusundan işlem yapabilsin. */ ?>
<?php if ($copluBayraklar): ?>
  <div class="uyari warn">
    <strong>Çöp kutusundaki <?= count($copluBayraklar) ?> haberin manşet/son dakika işareti hâlâ duruyor.</strong>
    Bu haberler ön yüzde görünmez, ancak çöpten <em>geri alınırlarsa</em> doğrudan manşete/son dakika şeridine dönerler.
    <ul class="kucuk">
      <?php foreach ($copluBayraklar as $ck): ?>
        <li>
          <?= esc($ck['title']) ?>
          <span class="rozet olumsuz">çöpte</span>
          <?php if ((int)$ck['is_headline'] === 1): ?><span class="rozet uyari">manşette</span><?php endif; ?>
          <?php if ((int)$ck['is_breaking'] === 1): ?><span class="rozet uyari">son dakika</span><?php endif; ?>
          <span class="soluk"><?= esc(arr($ck, 'category_name', '') ?: 'Kategorisiz') ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (admin_page_exists('posts')): ?>
      <a href="<?= esc(admin_url('posts', ['durum' => 'cop'])) ?>">Çöp kutusunu aç →</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="sekmeler" role="tablist">
  <button type="button" role="tab" id="sekme-sira"  aria-controls="panel-sira"  aria-selected="true"  class="etkin" data-sekme="sira">Manşet sırası</button>
  <button type="button" role="tab" id="sekme-sec"   aria-controls="panel-sec"   aria-selected="false" data-sekme="sec">Haber seç</button>
  <button type="button" role="tab" id="sekme-picks" aria-controls="panel-picks" aria-selected="false" data-sekme="picks">Editörün seçimi</button>
</div>

<!-- ============================================================ A) Manşet sırası -->
<div id="panel-sira" role="tabpanel" aria-labelledby="sekme-sira" data-panel="sira">
  <div id="mansetUyari"></div>

  <?php if (!$hlV2): ?>
    <div class="uyari warn">
      Manşet güncellemesi (göç <code>026_headlines</code>) henüz uygulanmamış. Zamanlı manşet ve
      manşete özel kısa başlık bu güncelleme uygulanana kadar çalışmaz; sıralama normal çalışır.
    </div>
  <?php endif; ?>

  <div class="kart">
    <div class="kart-baslik">
      <span>Manşetteki haberler</span>
      <span class="kucuk soluk"><strong id="mansetSayi"><?= (int)$payload['count'] ?></strong> / <?= (int)$payload['max'] ?></span>
    </div>
    <p class="kucuk soluk" id="siraYardim">
      En üstteki haber ön yüzde büyük görselle <strong>ana manşet</strong> olarak basılır.
      Sıralamayı üç yolla değiştirebilirsiniz: satırı <strong>sürükleyip bırakın</strong>,
      <em>▲ / ▼</em> düğmelerine basın, ya da satırın başındaki <strong>taşıma tutamağına</strong>
      sekme ile gelip <kbd>↑</kbd> / <kbd>↓</kbd> (başa/sona için <kbd>Home</kbd> / <kbd>End</kbd>)
      tuşlarını kullanın. Değişiklik anında kaydedilir.
    </p>
    <div id="siraDuyuru" class="gizli" role="status" aria-live="polite"></div>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr><th><span class="gizli">Taşı</span></th><th>#</th><th>Görsel</th><th>Başlık</th>
            <th>Manşet başlığı</th><th>Kategori</th><th>Tarih</th><th class="islem">İşlem</th></tr>
        </thead>
        <tbody id="mansetGovde"></tbody>
      </table>
    </div>
  </div>

  <?php if ($hlV2): ?>
  <div class="kart">
    <div class="kart-baslik">
      <span>Manşete girmeyi bekleyenler</span>
      <span class="kucuk soluk"><strong id="bekleyenSayi"><?= count($bekleyenManset) ?></strong> kayıt</span>
    </div>
    <p class="kucuk soluk">
      Zamanı gelince <strong>zamanlı görev (cron)</strong> bu haberleri manşete alır. Cron çalışmıyorsa
      hiçbiri açılmaz — Araçlar ekranından cron durumunu denetleyin. Haber o an yayında değilse
      ya da çöpteyse manşete <em>alınmaz</em>, kayıt beklemede kalır.
    </p>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead><tr><th>Başlık</th><th>Kategori</th><th>Giriş</th><th>Çıkış</th><th>Durum</th><th class="islem">İşlem</th></tr></thead>
        <tbody id="bekleyenGovde"></tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ============================================================ B) Haber seç -->
<div id="panel-sec" role="tabpanel" aria-labelledby="sekme-sec" data-panel="sec" hidden>
  <div class="kart">
    <div class="kart-baslik">Yayındaki haberler</div>
    <div class="arac-cubugu">
      <input type="search" id="secMetin" placeholder="Başlık veya spot içinde ara…" aria-label="Haber ara">
      <select id="secKategori" aria-label="Kategori süz"><?= admin_options($categoryOptions, '', 'Tüm kategoriler') ?></select>
      <button type="button" class="dugme" id="secAra">Ara</button>
      <button type="button" class="dugme" id="secTemizle">Temizle</button>
      <span class="sag kucuk soluk" id="secBilgi"></span>
    </div>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr><th>Görsel</th><th>Başlık</th><th>Kategori</th><th>Tarih</th><th>Son dakika</th><th class="islem">İşlem</th></tr>
        </thead>
        <tbody id="secGovde"><tr><td colspan="6" class="soluk">Yükleniyor…</td></tr></tbody>
      </table>
    </div>
    <div id="secSayfalama" data-arama="sec"></div>
  </div>
</div>

<!-- ============================================================ C) Editörün seçimi -->
<div id="panel-picks" role="tabpanel" aria-labelledby="sekme-picks" data-panel="picks" hidden>
  <div class="izgara-2">
    <div class="kart">
      <div class="kart-baslik">
        <span>Seçili haberler</span>
        <span class="kucuk soluk"><strong id="picksSayi"><?= count($payload['picks']) ?></strong> / <?= (int)$payload['picks_max'] ?></span>
      </div>
      <p class="kucuk soluk">Sıra ön yüzde olduğu gibi korunur. Değişiklikler anında kaydedilir.</p>
      <div id="picksUyari"></div>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th>#</th><th>Başlık</th><th>Kategori</th><th class="islem">İşlem</th></tr></thead>
          <tbody id="picksGovde"></tbody>
        </table>
      </div>
    </div>

    <div class="kart">
      <div class="kart-baslik">Seçime haber ekle</div>
      <div class="arac-cubugu">
        <input type="search" id="picksMetin" placeholder="Başlık veya spot içinde ara…" aria-label="Haber ara">
        <select id="picksKategori" aria-label="Kategori süz"><?= admin_options($categoryOptions, '', 'Tüm kategoriler') ?></select>
        <button type="button" class="dugme" id="picksAra">Ara</button>
        <span class="sag kucuk soluk" id="picksBilgi"></span>
      </div>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th>Başlık</th><th>Kategori</th><th>Tarih</th><th class="islem">İşlem</th></tr></thead>
          <tbody id="picksAramaGovde"><tr><td colspan="4" class="soluk">Yükleniyor…</td></tr></tbody>
        </table>
      </div>
      <div id="picksSayfalama" data-arama="picks"></div>
    </div>
  </div>
</div>

<style>
/* 1.3-09 — sürükle-bırak geri bildirimi. admin.css'te karşılığı yok; renkler
   mevcut değişkenlerden alınır. Dış kütüphane kullanılmıyor. */
#mansetGovde tr.sira-satir { cursor:default; }
#mansetGovde tr.surukleniyor { opacity:.45; }
#mansetGovde tr.birak-ustu { outline:2px dashed var(--ana); outline-offset:-2px; }
.sira-tutamak { cursor:grab; letter-spacing:1px; }
.sira-tutamak:active { cursor:grabbing; }
.sira-tutamak:focus-visible { outline:2px solid var(--ana); outline-offset:1px; }
#mansetGovde .hl-baslik { font-size:12px; padding:5px 7px; width:100%; min-width:140px; }
</style>

<script>
/* Manşet yönetimi — tüm veri alışverişi headlines.* uçları üzerinden yapılır. */
(function () {
  'use strict';

  var VERI = JSON.parse(<?= headlines_js_data($bootstrap) ?>);
  var MAX = VERI.max;
  var PICKS_MAX = VERI.picksMax;
  var durum = { headlines: VERI.headlines || [], picks: VERI.picks || [], counts: VERI.counts || {} };
  var paneller = {};

  /* 1.3-09 — manşete özel kısa başlık ve zamanlama.
     META, haber id'sinden {headline_title, headline_starts_at, headline_ends_at}
     eşlemesidir; vitrin_item() biçimine dokunmamak için ayrı taşınır. */
  var META = VERI.meta || {};
  var BEKLEYEN = VERI.pending || [];
  var V2 = !!VERI.v2;
  var BASLIK_MAX = VERI.titleMax || 120;

  function meta(id) {
    var m = META[String(id)] || META[id];
    return m || { headline_title: '', headline_starts_at: '', headline_ends_at: '' };
  }

  /* -------------------------------------------------- ortak parçalar */

  function gorsel(o) {
    return o.image
      ? '<img class="kucuk-gorsel" src="' + M.esc(o.image) + '" alt="" loading="lazy">'
      : '<span class="kucuk-gorsel" aria-hidden="true"></span>';
  }

  function kategoriHucre(o) {
    return o.category ? '<span class="rozet notr">' + M.esc(o.category) + '</span>' : '<span class="soluk">—</span>';
  }

  function baslikHucre(o) {
    return '<a href="' + M.esc(o.url) + '" target="_blank" rel="noopener">' + M.esc(o.title) + '</a>';
  }

  function bosSatir(kolon, baslik, aciklama) {
    return '<tr><td colspan="' + kolon + '"><div class="bos-durum">'
      + '<span class="buyuk" aria-hidden="true">★</span><h3>' + M.esc(baslik) + '</h3>'
      + '<p>' + M.esc(aciklama) + '</p></div></td></tr>';
  }

  function mansetteMi(id) {
    for (var i = 0; i < durum.headlines.length; i++) { if (durum.headlines[i].id === id) { return true; } }
    return false;
  }

  function seciliPickMi(id) {
    for (var i = 0; i < durum.picks.length; i++) { if (durum.picks[i].id === id) { return true; } }
    return false;
  }

  function idListesi(liste) {
    return liste.map(function (o) { return o.id; });
  }

  /* -------------------------------------------------- sekmeler */

  function sekmeAc(ad) {
    M.qsa('.sekmeler button[data-sekme]').forEach(function (b) {
      var etkin = b.getAttribute('data-sekme') === ad;
      b.classList.toggle('etkin', etkin);
      b.setAttribute('aria-selected', etkin ? 'true' : 'false');
    });
    M.qsa('[data-panel]').forEach(function (p) {
      p.hidden = p.getAttribute('data-panel') !== ad;
    });
  }

  function sekmeleriBagla() {
    M.qsa('.sekmeler button[data-sekme]').forEach(function (b) {
      b.addEventListener('click', function () { sekmeAc(b.getAttribute('data-sekme')); });
    });
  }

  /* -------------------------------------------------- sayaçlar */

  function sayaclariCiz(c) {
    if (!c) { return; }
    durum.counts = c;
    Object.keys(c).forEach(function (k) {
      var el = M.qs('[data-sayac="' + k + '"]');
      if (el) { el.textContent = c[k]; }
    });
  }

  /* -------------------------------------------------- A) manşet sırası */

  function duyur(metin) {
    var el = M.qs('#siraDuyuru');
    if (el) { el.textContent = metin; }
  }

  /* KLAVYE ODAĞI — taşımadan sonra hangi satırın tutamağına dönüleceği.
     NEDEN BURADA: liste bir taşımada BİRDEN ÇOK kez yeniden çizilir (iyimser
     çizim → reorder yanıtı → headlines.meta yanıtı). Odak `.then()` içinde
     verilirse SONRAKİ çizim onu yok eder ve odak <body>'ye düşer; klavye
     kullanıcısı ikinci kez ↓ basamaz. Ölçümle görüldü. Bu yüzden odağı her
     çizimin SONU geri verir. */
  var odakId = 0;

  function odagiGeriVer() {
    if (!odakId) { return; }
    // Odağı YALNIZ kaybolduysa geri ver. Kullanıcı bu arada başka bir alana
    // (ör. kısa başlık kutusu) geçtiyse odağı ondan ÇALMAYALIM.
    var a = document.activeElement;
    if (a && a !== document.body && a.tagName !== 'HTML') { return; }
    var t = M.qs('[data-tutamak="' + odakId + '"]');
    if (t) { t.focus(); }
  }

  function mansetCiz() {
    var liste = durum.headlines;
    var govde = M.qs('#mansetGovde');
    M.qs('#mansetSayi').textContent = liste.length;

    if (!liste.length) {
      govde.innerHTML = bosSatir(8, 'Manşette haber yok',
        '“Haber seç” sekmesinden yayındaki haberleri manşete ekleyin.');
    } else {
      govde.innerHTML = liste.map(function (o, i) {
        var m = meta(o.id);
        var bitis = m.headline_ends_at || '';
        return '<tr data-id="' + o.id + '" data-sira="' + i + '" draggable="true" class="sira-satir">'
          /* TAŞIMA TUTAMAĞI — hem fare (HTML5 drag) hem KLAVYE.
             Düğme olduğu için sekme sırasına doğal olarak girer; ok tuşları
             burada yakalanır. Ekran okuyucu her taşımayı aria-live'dan duyar. */
          + '<td class="dar"><button type="button" class="dugme kucuk sira-tutamak"'
          + ' data-tutamak="' + o.id + '" aria-describedby="siraYardim"'
          + ' title="Taşı (ok tuşlarıyla da)" aria-label="' + M.esc(o.title)
          + ' — sırayı değiştir, ok tuşlarını kullanın">⠿</button></td>'
          + '<td class="dar"><strong>' + (i + 1) + '</strong></td>'
          + '<td class="dar">' + gorsel(o) + '</td>'
          + '<td>' + baslikHucre(o)
          + (i === 0 ? ' <span class="rozet olumsuz">ANA MANŞET</span>' : '')
          + (o.is_breaking ? ' <span class="rozet uyari">son dakika</span>' : '')
          + (bitis ? ' <span class="rozet bilgi" title="Bu zamanda manşetten çıkar">↧ ' + M.esc(bitis) + '</span>' : '')
          + '</td>'
          + '<td>' + (V2
            ? '<input type="text" class="hl-baslik" data-hl-baslik="' + o.id + '" maxlength="' + BASLIK_MAX + '"'
              + ' value="' + M.esc(m.headline_title || '') + '"'
              + ' placeholder="Boşsa özgün başlık"'
              + ' aria-label="' + M.esc(o.title) + ' için manşete özel kısa başlık">'
            : '<span class="soluk">—</span>')
          + '</td>'
          + '<td class="dar">' + kategoriHucre(o) + '</td>'
          + '<td class="dar"><span class="kucuk soluk">' + M.esc(o.date) + '</span></td>'
          + '<td class="islem">'
          + '<button type="button" class="dugme kucuk" data-yukari="' + o.id + '"'
          + (i === 0 ? ' disabled' : '') + ' title="Yukarı taşı" aria-label="Yukarı taşı">▲</button> '
          + '<button type="button" class="dugme kucuk" data-asagi="' + o.id + '"'
          + (i === liste.length - 1 ? ' disabled' : '') + ' title="Aşağı taşı" aria-label="Aşağı taşı">▼</button> '
          + (V2 ? '<button type="button" class="dugme kucuk" data-zamanla="' + o.id + '">Zamanla</button> ' : '')
          + '<button type="button" class="dugme kucuk tehlike" data-cikar="' + o.id + '"'
          + ' data-onay="' + M.esc(o.title) + ' manşetten çıkarılacak. Onaylıyor musunuz?">Manşetten çıkar</button>'
          + '</td></tr>';
      }).join('');
      dndBagla();
      odagiGeriVer();
    }

    M.qs('#mansetUyari').innerHTML = liste.length >= MAX
      ? '<div class="uyari warn">Manşet dolu (' + liste.length + '/' + MAX + '). '
        + 'Yeni haber eklemek için önce listeden birini manşetten çıkarın.</div>'
      : '';

    if (paneller.sec) { paneller.sec.ciz(); }
  }

  /* -------------------------------------------------- bekleyen zamanlamalar */

  function bekleyenCiz() {
    var govde = M.qs('#bekleyenGovde');
    if (!govde) { return; }
    var sayacEl = M.qs('#bekleyenSayi');
    if (sayacEl) { sayacEl.textContent = BEKLEYEN.length; }

    if (!BEKLEYEN.length) {
      govde.innerHTML = bosSatir(6, 'Bekleyen zamanlama yok',
        '“Haber seç” sekmesinden bir habere ileri tarihli manşet girişi verebilirsiniz.');
      return;
    }
    govde.innerHTML = BEKLEYEN.map(function (o) {
      var rozet;
      if (!o.publishable) { rozet = '<span class="rozet olumsuz">yayında değil</span>'; }
      else if (o.expired) { rozet = '<span class="rozet uyari">süresi geçmiş</span>'; }
      else if (o.overdue) { rozet = '<span class="rozet uyari">zamanı geldi — cron bekleniyor</span>'; }
      else { rozet = '<span class="rozet olumlu">bekliyor</span>'; }
      return '<tr data-id="' + o.id + '">'
        + '<td>' + M.esc(o.title)
        + (o.headline_title ? ' <span class="kucuk soluk">→ ' + M.esc(o.headline_title) + '</span>' : '')
        + '</td>'
        + '<td class="dar">' + (o.category ? '<span class="rozet notr">' + M.esc(o.category) + '</span>' : '<span class="soluk">—</span>') + '</td>'
        + '<td class="dar"><span class="kucuk">' + M.esc(o.headline_starts_at || '—') + '</span></td>'
        + '<td class="dar"><span class="kucuk">' + M.esc(o.headline_ends_at || '—') + '</span></td>'
        + '<td class="dar">' + rozet + '</td>'
        + '<td class="islem">'
        + '<button type="button" class="dugme kucuk" data-zamanla="' + o.id + '">Düzenle</button> '
        + '<button type="button" class="dugme kucuk tehlike" data-zaman-sil="' + o.id + '"'
        + ' data-onay="Zamanlama silinecek. Onaylıyor musunuz?">Zamanlamayı sil</button>'
        + '</td></tr>';
    }).join('');
  }

  function payloadUygula(r) {
    if (r.items) { durum.headlines = r.items; }
    if (r.picks) { durum.picks = r.picks; }
    sayaclariCiz(r.counts);
    mansetCiz();
    picksCiz();
    metaTazele();
  }

  /** Manşet listesinin 1.3 alanlarını sunucudan tazeler. */
  function metaTazele() {
    if (!V2) { return; }
    M.api('headlines.meta', { ids: idListesi(durum.headlines) }).then(function (r) {
      if (!r || !r.ok) { return; }
      META = r.meta || {};
      BEKLEYEN = r.pending || [];
      bekleyenCiz();
      mansetCiz();
    });
  }

  /**
   * Haberi listede yeni bir konuma taşır ve sırayı kaydeder.
   * Tek giriş noktası: sürükle-bırak, ▲/▼ ve klavye hepsi buradan geçer;
   * böylece üç yolun davranışı ayrışamaz.
   */
  function siraUygula(id, hedefIndeks, nasil) {
    var ids = idListesi(durum.headlines);
    var i = ids.indexOf(id);
    if (i < 0) { return; }
    var j = Math.max(0, Math.min(ids.length - 1, hedefIndeks));
    if (i === j) { return; }

    ids.splice(i, 1);
    ids.splice(j, 0, id);

    // İyimser çizim: kullanıcı sonucu beklemeden görsün, hata olursa sunucu
    // yanıtı listeyi geri yazar (payloadUygula her zaman sunucu sırasını basar).
    var sirali = [];
    ids.forEach(function (x) {
      durum.headlines.forEach(function (o) { if (o.id === x) { sirali.push(o); } });
    });
    durum.headlines = sirali;
    odakId = id;
    mansetCiz();

    var ad = '';
    durum.headlines.forEach(function (o) { if (o.id === id) { ad = o.title; } });
    duyur(ad + ' — ' + (j + 1) + '. sıraya taşındı (' + (nasil || 'taşındı') + ').');

    M.api('headlines.reorder', { ids: ids }).then(function (r) {
      if (M.sonuc(r, 'Sıra güncellendi.')) { payloadUygula(r); }
      else { M.api('headlines.list', {}).then(function (r2) { if (r2 && r2.ok) { payloadUygula(r2); } }); }
    });
  }

  function mansetTasi(id, yon) {
    var ids = idListesi(durum.headlines);
    var i = ids.indexOf(id);
    if (i < 0) { return; }
    siraUygula(id, i + yon, yon < 0 ? 'yukarı' : 'aşağı');
  }

  /* -------------------------------------------------- sürükle-bırak (vanilya)
     Dış kütüphane YOK. HTML5 drag-and-drop olayları doğrudan kullanılır.
     KLAVYE bunun YERİNE GEÇMEZ, YANINDADIR: sürükle-bırak yalnız fare/dokunma
     ile çalışır, bu yüzden aynı işi yapan ok tuşları ve ▲/▼ düğmeleri de
     korunur (WCAG 2.1.1 — her işlev klavyeyle erişilebilir olmalı). */

  var surukleId = 0;

  function dndBagla() {
    M.qsa('#mansetGovde tr.sira-satir').forEach(function (tr) {
      tr.addEventListener('dragstart', function (e) {
        surukleId = parseInt(tr.getAttribute('data-id'), 10);
        tr.classList.add('surukleniyor');
        if (e.dataTransfer) {
          e.dataTransfer.effectAllowed = 'move';
          // Firefox sürüklemeyi başlatmak için veri ister.
          try { e.dataTransfer.setData('text/plain', String(surukleId)); } catch (hata) { }
        }
      });
      tr.addEventListener('dragend', function () {
        tr.classList.remove('surukleniyor');
        M.qsa('#mansetGovde tr').forEach(function (x) { x.classList.remove('birak-ustu'); });
        surukleId = 0;
      });
      tr.addEventListener('dragover', function (e) {
        if (!surukleId) { return; }
        e.preventDefault();
        if (e.dataTransfer) { e.dataTransfer.dropEffect = 'move'; }
        tr.classList.add('birak-ustu');
      });
      tr.addEventListener('dragleave', function () { tr.classList.remove('birak-ustu'); });
      tr.addEventListener('drop', function (e) {
        e.preventDefault();
        tr.classList.remove('birak-ustu');
        if (!surukleId) { return; }
        var hedef = parseInt(tr.getAttribute('data-sira'), 10);
        var tasinan = surukleId;
        surukleId = 0;
        siraUygula(tasinan, hedef, 'sürüklendi');
      });
    });
  }

  /* Klavye: tutamak odaktayken ↑ ↓ Home End */
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if (!t || !t.getAttribute || !t.hasAttribute('data-tutamak')) { return; }
    var id = parseInt(t.getAttribute('data-tutamak'), 10);
    var ids = idListesi(durum.headlines);
    var i = ids.indexOf(id);
    if (i < 0) { return; }

    if (e.key === 'ArrowUp') { e.preventDefault(); siraUygula(id, i - 1, 'klavye'); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); siraUygula(id, i + 1, 'klavye'); }
    else if (e.key === 'Home') { e.preventDefault(); siraUygula(id, 0, 'klavye'); }
    else if (e.key === 'End') { e.preventDefault(); siraUygula(id, ids.length - 1, 'klavye'); }
  });

  /* -------------------------------------------------- zamanlama kutusu */

  function zamanlaAc(id, baslik, mevcut) {
    var govde = M.modal.ac('Manşet zamanlaması', ''
      + '<p class="kucuk soluk">' + M.esc(baslik) + '</p>'
      + '<div class="form-alan"><label for="zmBas">Manşete giriş</label>'
      + '<input type="datetime-local" id="zmBas" value="' + M.esc(zamanGirdi(mevcut.headline_starts_at)) + '">'
      + '<span class="kucuk soluk">Boş bırakırsanız otomatik giriş olmaz.</span></div>'
      + '<div class="form-alan"><label for="zmBit">Manşetten çıkış</label>'
      + '<input type="datetime-local" id="zmBit" value="' + M.esc(zamanGirdi(mevcut.headline_ends_at)) + '">'
      + '<span class="kucuk soluk">Boş bırakırsanız süresiz kalır.</span></div>'
      + '<div class="uyari bilgi mini">Zamanlamayı <strong>cron</strong> uygular. Cron kurulu değilse '
      + 'haber ne girer ne çıkar. Haber o an yayında değilse ya da çöpteyse manşete alınmaz.</div>'
      + '<div class="dugme-grup"><button type="button" class="dugme birincil" id="zmKaydet">Kaydet</button>'
      + '<button type="button" class="dugme" id="zmTemizle">Zamanlamayı temizle</button></div>', '520px');

    function gonder(bas, bit) {
      M.api('headlines.schedule', { id: id, starts_at: bas, ends_at: bit }).then(function (r) {
        if (M.sonuc(r)) {
          M.modal.kapat();
          M.api('headlines.list', {}).then(function (r2) { if (r2 && r2.ok) { payloadUygula(r2); } });
        }
      });
    }
    M.qs('#zmKaydet', govde).addEventListener('click', function () {
      gonder(M.qs('#zmBas', govde).value, M.qs('#zmBit', govde).value);
    });
    M.qs('#zmTemizle', govde).addEventListener('click', function () { gonder('', ''); });
  }

  /** 'Y-m-d H:i:s' → datetime-local girdisinin beklediği 'Y-m-dTH:i'. */
  function zamanGirdi(v) {
    v = String(v || '');
    if (v === '') { return ''; }
    return v.replace(' ', 'T').slice(0, 16);
  }

  function zamanlaAcId(id) {
    var baslik = '#' + id;
    durum.headlines.forEach(function (o) { if (o.id === id) { baslik = o.title; } });
    BEKLEYEN.forEach(function (o) { if (o.id === id) { baslik = o.title; } });
    if (paneller.sec) {
      paneller.sec.durum.items.forEach(function (o) { if (o.id === id) { baslik = o.title; } });
    }
    var m = meta(id);
    var bekleyen = null;
    BEKLEYEN.forEach(function (o) { if (o.id === id) { bekleyen = o; } });
    if (bekleyen) {
      m = { headline_title: bekleyen.headline_title,
            headline_starts_at: bekleyen.headline_starts_at,
            headline_ends_at: bekleyen.headline_ends_at };
    }
    zamanlaAc(id, baslik, m);
  }

  /* -------------------------------------------------- C) editörün seçimi */

  function picksCiz() {
    var liste = durum.picks;
    var govde = M.qs('#picksGovde');
    M.qs('#picksSayi').textContent = liste.length;

    if (!liste.length) {
      govde.innerHTML = bosSatir(4, 'Seçim boş',
        'Sağdaki arama kutusundan haber ekleyin. Seçim boşken tema son haberleri gösterir.');
    } else {
      govde.innerHTML = liste.map(function (o, i) {
        return '<tr data-id="' + o.id + '">'
          + '<td class="dar"><strong>' + (i + 1) + '</strong></td>'
          + '<td>' + baslikHucre(o) + '</td>'
          + '<td class="dar">' + kategoriHucre(o) + '</td>'
          + '<td class="islem">'
          + '<button type="button" class="dugme kucuk" data-pick-yukari="' + o.id + '"'
          + (i === 0 ? ' disabled' : '') + ' title="Yukarı taşı" aria-label="Yukarı taşı">▲</button> '
          + '<button type="button" class="dugme kucuk" data-pick-asagi="' + o.id + '"'
          + (i === liste.length - 1 ? ' disabled' : '') + ' title="Aşağı taşı" aria-label="Aşağı taşı">▼</button> '
          + '<button type="button" class="dugme kucuk tehlike" data-pick-cikar="' + o.id + '">Çıkar</button>'
          + '</td></tr>';
      }).join('');
    }

    M.qs('#picksUyari').innerHTML = liste.length >= PICKS_MAX
      ? '<div class="uyari warn">Editörün seçimi dolu (' + liste.length + '/' + PICKS_MAX + ').</div>'
      : '';

    if (paneller.picks) { paneller.picks.ciz(); }
  }

  function picksKaydet(ids, basariMesaji) {
    M.api('headlines.picks', { ids: ids }).then(function (r) {
      if (M.sonuc(r, basariMesaji)) {
        durum.picks = r.picks || [];
        sayaclariCiz(r.counts);
        picksCiz();
      }
    });
  }

  function picksTasi(id, yon) {
    var ids = idListesi(durum.picks);
    var i = ids.indexOf(id);
    var j = i + yon;
    if (i < 0 || j < 0 || j >= ids.length) { return; }
    var gecici = ids[i]; ids[i] = ids[j]; ids[j] = gecici;
    picksKaydet(ids, 'Sıra güncellendi.');
  }

  /* -------------------------------------------------- arama panelleri */

  function sayfalamaCiz(kap, d) {
    if (d.pages < 2) { kap.innerHTML = ''; return; }
    var h = '<nav class="sayfalama" aria-label="Sayfalama"><ul>';
    if (d.page > 1) { h += '<li><a href="#" data-sayfa="' + (d.page - 1) + '">‹ Önceki</a></li>'; }
    var bosluk = false;
    for (var i = 1; i <= d.pages; i++) {
      if (i !== 1 && i !== d.pages && Math.abs(i - d.page) > 2) {
        if (!bosluk) { h += '<li class="gap">…</li>'; bosluk = true; }
        continue;
      }
      bosluk = false;
      h += (i === d.page)
        ? '<li class="current"><span aria-current="page">' + i + '</span></li>'
        : '<li><a href="#" data-sayfa="' + i + '">' + i + '</a></li>';
    }
    if (d.page < d.pages) { h += '<li><a href="#" data-sayfa="' + (d.page + 1) + '">Sonraki ›</a></li>'; }
    kap.innerHTML = h + '</ul></nav>';
  }

  /**
   * Ortak arama paneli. cfg: {metin, kategori, dugme, govde, sayfalama, bilgi, kolon, satir}
   */
  function AramaPaneli(cfg) {
    var d = { q: '', cat: '', page: 1, items: [], total: 0, pages: 1, yuklendi: false };
    var govde = M.qs(cfg.govde);
    var metin = M.qs(cfg.metin);
    var kategori = cfg.kategori ? M.qs(cfg.kategori) : null;
    var sayfalama = M.qs(cfg.sayfalama);
    var bilgi = cfg.bilgi ? M.qs(cfg.bilgi) : null;

    function ciz() {
      if (!d.yuklendi) { return; }
      govde.innerHTML = d.items.length
        ? d.items.map(cfg.satir).join('')
        : bosSatir(cfg.kolon, 'Sonuç yok', 'Arama ölçütünü değiştirip yeniden deneyin.');
      if (bilgi) {
        bilgi.textContent = d.total ? (d.total + ' haber · sayfa ' + d.page + '/' + d.pages) : '';
      }
      sayfalamaCiz(sayfalama, d);
    }

    function getir(sayfa) {
      d.q = metin.value;
      d.cat = kategori ? kategori.value : '';
      govde.innerHTML = '<tr><td colspan="' + cfg.kolon + '" class="soluk">'
        + '<span class="yukleniyor"></span> Yükleniyor…</td></tr>';
      M.api('headlines.search', { q: d.q, category_id: d.cat || 0, page: sayfa || 1 }).then(function (r) {
        if (!r.ok) {
          govde.innerHTML = '<tr><td colspan="' + cfg.kolon + '"><div class="uyari err">'
            + M.esc(r.error || 'Arama başarısız.') + '</div></td></tr>';
          return;
        }
        d.items = r.items || [];
        d.total = r.total; d.page = r.page; d.pages = r.pages; d.yuklendi = true;
        sayaclariCiz(r.counts);
        ciz();
      });
    }

    metin.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); getir(1); }
    });
    if (kategori) { kategori.addEventListener('change', function () { getir(1); }); }
    if (cfg.dugme) { M.qs(cfg.dugme).addEventListener('click', function () { getir(1); }); }

    return { getir: getir, ciz: ciz, durum: d };
  }

  var secPaneliAyari = {
    metin: '#secMetin', kategori: '#secKategori', dugme: '#secAra',
    govde: '#secGovde', sayfalama: '#secSayfalama', bilgi: '#secBilgi', kolon: 6,
    satir: function (o) {
      var dolu = durum.headlines.length >= MAX;
      var icerde = mansetteMi(o.id);
      var islem = icerde
        ? '<span class="rozet olumlu">manşette</span> '
          + '<button type="button" class="dugme kucuk pasif" disabled>Manşete ekle</button>'
        : '<button type="button" class="dugme kucuk birincil' + (dolu ? ' pasif' : '') + '"'
          + ' data-ekle="' + o.id + '"' + (dolu ? ' disabled title="Manşet dolu"' : '') + '>Manşete ekle</button>';
      // 1.3-09: manşet dolu olsa bile ileri tarihli giriş zamanlanabilir.
      if (V2) {
        islem += ' <button type="button" class="dugme kucuk" data-zamanla="' + o.id + '"'
          + ' title="İleri tarihli manşet girişi">Zamanla</button>';
      }
      return '<tr data-id="' + o.id + '">'
        + '<td class="dar">' + gorsel(o) + '</td>'
        + '<td>' + baslikHucre(o) + '</td>'
        + '<td class="dar">' + kategoriHucre(o) + '</td>'
        + '<td class="dar"><span class="kucuk soluk">' + M.esc(o.date) + '</span></td>'
        + '<td class="dar"><label class="anahtar" title="Son dakika şeridi">'
        + '<input type="checkbox" data-sondakika="' + o.id + '"' + (o.is_breaking ? ' checked' : '') + '>'
        + '<span class="kaydirak"></span><span class="gizli">Son dakika</span></label></td>'
        + '<td class="islem">' + islem + '</td></tr>';
    }
  };

  var picksPaneliAyari = {
    metin: '#picksMetin', kategori: '#picksKategori', dugme: '#picksAra',
    govde: '#picksAramaGovde', sayfalama: '#picksSayfalama', bilgi: '#picksBilgi', kolon: 4,
    satir: function (o) {
      var dolu = durum.picks.length >= PICKS_MAX;
      var icerde = seciliPickMi(o.id);
      var islem = icerde
        ? '<span class="rozet olumlu">seçili</span>'
        : '<button type="button" class="dugme kucuk' + (dolu ? ' pasif' : '') + '"'
          + ' data-pick-ekle="' + o.id + '"' + (dolu ? ' disabled title="Seçim dolu"' : '') + '>Seçime ekle</button>';
      return '<tr data-id="' + o.id + '">'
        + '<td>' + baslikHucre(o) + '</td>'
        + '<td class="dar">' + kategoriHucre(o) + '</td>'
        + '<td class="dar"><span class="kucuk soluk">' + M.esc(o.date) + '</span></td>'
        + '<td class="islem">' + islem + '</td></tr>';
    }
  };

  /* -------------------------------------------------- olaylar (devretme) */

  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-ekle],[data-cikar],[data-yukari],[data-asagi],'
      + '[data-pick-ekle],[data-pick-cikar],[data-pick-yukari],[data-pick-asagi],[data-sayfa],'
      + '[data-zamanla],[data-zaman-sil]') : null;
    if (!el) { return; }

    if (el.hasAttribute('data-sayfa')) {
      e.preventDefault();
      var kap = el.closest('[data-arama]');
      var panel = kap ? paneller[kap.getAttribute('data-arama')] : null;
      if (panel) { panel.getir(parseInt(el.getAttribute('data-sayfa'), 10)); }
      return;
    }

    var id;
    if (el.hasAttribute('data-ekle')) {
      id = parseInt(el.getAttribute('data-ekle'), 10);
      el.disabled = true;
      M.api('headlines.add', { id: id }).then(function (r) {
        el.disabled = false;
        if (M.sonuc(r)) { payloadUygula(r); }
      });
    } else if (el.hasAttribute('data-cikar')) {
      id = parseInt(el.getAttribute('data-cikar'), 10);
      M.api('headlines.remove', { id: id }).then(function (r) {
        if (M.sonuc(r)) { payloadUygula(r); }
      });
    } else if (el.hasAttribute('data-yukari')) {
      mansetTasi(parseInt(el.getAttribute('data-yukari'), 10), -1);
    } else if (el.hasAttribute('data-asagi')) {
      mansetTasi(parseInt(el.getAttribute('data-asagi'), 10), 1);
    } else if (el.hasAttribute('data-pick-ekle')) {
      id = parseInt(el.getAttribute('data-pick-ekle'), 10);
      if (seciliPickMi(id)) { return; }
      picksKaydet(idListesi(durum.picks).concat([id]), 'Haber seçime eklendi.');
    } else if (el.hasAttribute('data-pick-cikar')) {
      id = parseInt(el.getAttribute('data-pick-cikar'), 10);
      picksKaydet(idListesi(durum.picks).filter(function (x) { return x !== id; }), 'Haber seçimden çıkarıldı.');
    } else if (el.hasAttribute('data-pick-yukari')) {
      picksTasi(parseInt(el.getAttribute('data-pick-yukari'), 10), -1);
    } else if (el.hasAttribute('data-pick-asagi')) {
      picksTasi(parseInt(el.getAttribute('data-pick-asagi'), 10), 1);
    } else if (el.hasAttribute('data-zamanla')) {
      zamanlaAcId(parseInt(el.getAttribute('data-zamanla'), 10));
    } else if (el.hasAttribute('data-zaman-sil')) {
      // data-onay kapı görevini gördü (assets/admin.js yakalama aşaması).
      id = parseInt(el.getAttribute('data-zaman-sil'), 10);
      M.api('headlines.schedule', { id: id, starts_at: '', ends_at: '' }).then(function (r) {
        if (M.sonuc(r)) {
          M.api('headlines.list', {}).then(function (r2) { if (r2 && r2.ok) { payloadUygula(r2); } });
        }
      });
    }
  });

  /* -------------------------------------------------- manşete özel kısa başlık
     Alandan çıkınca (change) kaydedilir; Enter da kaydeder. Boş bırakmak
     "özgün başlığı kullan" demektir. */
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-hl-baslik')) { return; }
    var id = parseInt(el.getAttribute('data-hl-baslik'), 10);
    el.disabled = true;
    M.api('headlines.title', { id: id, headline_title: el.value }).then(function (r) {
      el.disabled = false;
      if (M.sonuc(r)) {
        var anahtar = String(id);
        if (!META[anahtar]) { META[anahtar] = { headline_title: '', headline_starts_at: '', headline_ends_at: '' }; }
        META[anahtar].headline_title = r.headline_title;
        el.value = r.headline_title;
      }
    });
  });

  document.addEventListener('keydown', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-hl-baslik')) { return; }
    if (e.key === 'Enter') { e.preventDefault(); el.blur(); }
  });

  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.hasAttribute || !el.hasAttribute('data-sondakika')) { return; }
    var id = parseInt(el.getAttribute('data-sondakika'), 10);
    var on = el.checked ? 1 : 0;
    el.disabled = true;
    M.api('headlines.breaking', { id: id, on: on }).then(function (r) {
      el.disabled = false;
      if (M.sonuc(r)) {
        sayaclariCiz(r.counts);
        // Manşet listesindeki rozet de tazelensin
        durum.headlines.forEach(function (o) { if (o.id === id) { o.is_breaking = on; } });
        paneller.sec.durum.items.forEach(function (o) { if (o.id === id) { o.is_breaking = on; } });
        mansetCiz();
      } else {
        el.checked = !el.checked;
      }
    });
  });

  /* -------------------------------------------------- ilk çizim */

  // assets/admin.js `defer` ile yüklendiğinden window.M sayfa ayrıştırılırken
  // henüz tanımlı değildir; ilk çizim DOMContentLoaded'a bırakılır.
  function baslat() {
    sekmeleriBagla();
    paneller.sec = AramaPaneli(secPaneliAyari);
    paneller.picks = AramaPaneli(picksPaneliAyari);

    M.qs('#secTemizle').addEventListener('click', function () {
      M.qs('#secMetin').value = '';
      M.qs('#secKategori').value = '';
      paneller.sec.getir(1);
    });

    mansetCiz();
    picksCiz();
    bekleyenCiz();
    paneller.sec.getir(1);
    paneller.picks.getir(1);
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', baslat); }
  else { baslat(); }
})();
</script>
