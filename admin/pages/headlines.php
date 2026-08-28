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

$bootstrap = [
    'headlines'  => $payload['items'],
    'picks'      => $payload['picks'],
    'counts'     => $payload['counts'],
    'max'        => $payload['max'],
    'picksMax'   => $payload['picks_max'],
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

<div class="sekmeler" role="tablist">
  <button type="button" role="tab" id="sekme-sira"  aria-controls="panel-sira"  aria-selected="true"  class="etkin" data-sekme="sira">Manşet sırası</button>
  <button type="button" role="tab" id="sekme-sec"   aria-controls="panel-sec"   aria-selected="false" data-sekme="sec">Haber seç</button>
  <button type="button" role="tab" id="sekme-picks" aria-controls="panel-picks" aria-selected="false" data-sekme="picks">Editörün seçimi</button>
</div>

<!-- ============================================================ A) Manşet sırası -->
<div id="panel-sira" role="tabpanel" aria-labelledby="sekme-sira" data-panel="sira">
  <div id="mansetUyari"></div>
  <div class="kart">
    <div class="kart-baslik">
      <span>Manşetteki haberler</span>
      <span class="kucuk soluk"><strong id="mansetSayi"><?= (int)$payload['count'] ?></strong> / <?= (int)$payload['max'] ?></span>
    </div>
    <p class="kucuk soluk">En üstteki haber ön yüzde büyük görselle <strong>ana manşet</strong> olarak basılır.
      Sıralama <em>▲ / ▼</em> düğmeleriyle değişir ve anında kaydedilir.</p>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr><th>#</th><th>Görsel</th><th>Başlık</th><th>Kategori</th><th>Tarih</th><th class="islem">İşlem</th></tr>
        </thead>
        <tbody id="mansetGovde"></tbody>
      </table>
    </div>
  </div>
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

<script>
/* Manşet yönetimi — tüm veri alışverişi headlines.* uçları üzerinden yapılır. */
(function () {
  'use strict';

  var VERI = JSON.parse(<?= headlines_js_data($bootstrap) ?>);
  var MAX = VERI.max;
  var PICKS_MAX = VERI.picksMax;
  var durum = { headlines: VERI.headlines || [], picks: VERI.picks || [], counts: VERI.counts || {} };
  var paneller = {};

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

  function mansetCiz() {
    var liste = durum.headlines;
    var govde = M.qs('#mansetGovde');
    M.qs('#mansetSayi').textContent = liste.length;

    if (!liste.length) {
      govde.innerHTML = bosSatir(6, 'Manşette haber yok',
        '“Haber seç” sekmesinden yayındaki haberleri manşete ekleyin.');
    } else {
      govde.innerHTML = liste.map(function (o, i) {
        return '<tr data-id="' + o.id + '">'
          + '<td class="dar"><strong>' + (i + 1) + '</strong></td>'
          + '<td class="dar">' + gorsel(o) + '</td>'
          + '<td>' + baslikHucre(o)
          + (i === 0 ? ' <span class="rozet olumsuz">ANA MANŞET</span>' : '')
          + (o.is_breaking ? ' <span class="rozet uyari">son dakika</span>' : '')
          + '</td>'
          + '<td class="dar">' + kategoriHucre(o) + '</td>'
          + '<td class="dar"><span class="kucuk soluk">' + M.esc(o.date) + '</span></td>'
          + '<td class="islem">'
          + '<button type="button" class="dugme kucuk" data-yukari="' + o.id + '"'
          + (i === 0 ? ' disabled' : '') + ' title="Yukarı taşı" aria-label="Yukarı taşı">▲</button> '
          + '<button type="button" class="dugme kucuk" data-asagi="' + o.id + '"'
          + (i === liste.length - 1 ? ' disabled' : '') + ' title="Aşağı taşı" aria-label="Aşağı taşı">▼</button> '
          + '<button type="button" class="dugme kucuk tehlike" data-cikar="' + o.id + '"'
          + ' data-onay="' + M.esc(o.title) + ' manşetten çıkarılacak. Onaylıyor musunuz?">Manşetten çıkar</button>'
          + '</td></tr>';
      }).join('');
    }

    M.qs('#mansetUyari').innerHTML = liste.length >= MAX
      ? '<div class="uyari warn">Manşet dolu (' + liste.length + '/' + MAX + '). '
        + 'Yeni haber eklemek için önce listeden birini manşetten çıkarın.</div>'
      : '';

    if (paneller.sec) { paneller.sec.ciz(); }
  }

  function payloadUygula(r) {
    if (r.items) { durum.headlines = r.items; }
    if (r.picks) { durum.picks = r.picks; }
    sayaclariCiz(r.counts);
    mansetCiz();
    picksCiz();
  }

  function mansetTasi(id, yon) {
    var ids = idListesi(durum.headlines);
    var i = ids.indexOf(id);
    var j = i + yon;
    if (i < 0 || j < 0 || j >= ids.length) { return; }
    var gecici = ids[i]; ids[i] = ids[j]; ids[j] = gecici;
    M.api('headlines.reorder', { ids: ids }).then(function (r) {
      if (M.sonuc(r, 'Sıra güncellendi.')) { payloadUygula(r); }
    });
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
      + '[data-pick-ekle],[data-pick-cikar],[data-pick-yukari],[data-pick-asagi],[data-sayfa]') : null;
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
    }
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
    paneller.sec.getir(1);
    paneller.picks.getir(1);
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', baslat); }
  else { baslat(); }
})();
</script>
