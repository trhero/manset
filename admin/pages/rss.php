<?php
/**
 * Panel — RSS kaynakları ve öge havuzu (Ajan-4).
 * İki sekme: "Kaynaklar" (rss_sources) ve "Havuz" (rss_items).
 * Yazma işlemleri api.php?a=rss.* uçlarına gider (inc/api/rss_api.php).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('rss.manage');

$adminPageTitle = 'RSS Kaynakları';

// inc/rss.php admin/index.php tarafından yüklenir; tek başına açılırsa yedek
if (!function_exists('rss_pool')) { require_once dirname(dirname(__DIR__)) . '/inc/rss.php'; }

$tab = inp_s('sekme', 'kaynaklar') === 'havuz' ? 'havuz' : 'kaynaklar';

// ---------------------------------------------------------------- ortak veri
$stats = rss_stats();
$categories = [];
foreach (qa('SELECT id, name FROM categories ORDER BY sort ASC, name ASC') as $c) {
    $categories[(int)$c['id']] = $c['name'];
}

$sources = qa('SELECT s.*, c.name AS category_name,
    (SELECT COUNT(*) FROM rss_items i WHERE i.source_id = s.id) AS item_count
    FROM rss_sources s LEFT JOIN categories c ON c.id = s.category_id
    ORDER BY s.active DESC, s.name ASC');

$sourceOptions = [];
foreach ($sources as $s) { $sourceOptions[(int)$s['id']] = $s['name']; }

$editId = inp_i('duzenle', 0);
$editSource = null;
if ($editId > 0) {
    $editSource = q1('SELECT * FROM rss_sources WHERE id = :i', [':i' => $editId]);
}

$aiReady = function_exists('ai_configured') ? ai_configured() : false;
$aiStatus = function_exists('ai_status_text') ? ai_status_text() : 'yapılandırılmadı';
$aiQueueReady = function_exists('ai_job_enqueue');

// ---------------------------------------------------------------- havuz verisi
$poolFilters = [
    'source_id' => inp_i('kaynak', 0),
    'status'    => inp_s('durum', ''),
    'q'         => inp_s('ara', ''),
    'dup'       => inp_i('tekrar', 0) ? 1 : 0,
];
// Tekrar tespiti sütunları (göç 027) yoksa rozet/süzgeç arayüzü hiç basılmaz.
$dupVar = function_exists('rss_has_dedupe_columns') ? rss_has_dedupe_columns() : false;
$poolPage = max(1, inp_i('sayfa', 1));
$poolPerPage = 25;
$poolTotal = 0;
$poolItems = [];
if ($tab === 'havuz') {
    $poolTotal = rss_pool_count($poolFilters);
    $poolItems = rss_pool($poolFilters, $poolPerPage, ($poolPage - 1) * $poolPerPage);
}

$statusTones = ['new' => 'bilgi', 'queued' => 'uyari', 'processed' => 'olumlu', 'skipped' => 'notr'];
$cronNeverRan = (setting('cron_last_run', '') === '');
// Geri çekilme sütunları (göç 013) yoksa aralık/beklemede arayüzü gösterilmez.
$backoffVar = function_exists('rss_has_backoff_columns') ? rss_has_backoff_columns() : false;
$simdiZaman = now();
?>

<div class="sayfa-basligi">
  <h1>RSS Kaynakları</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('rss', ['sekme' => 'havuz'])) ?>">Havuza git →</a>
    <button type="button" class="dugme birincil" id="rssYeniKaynak">+ Yeni kaynak</button>
  </div>
</div>

<?php if ($cronNeverRan): ?>
  <div class="uyari warn">
    RSS çekimi için zamanlanmış görev gerekli — Ayarlar ekranındaki komutu hosting panelinize ekleyin.
    <a href="<?= esc(admin_url('settings')) ?>">Ayarlara git</a>
  </div>
<?php endif; ?>

<div class="izgara-4 bosluk-alt">
  <div class="sayac vurgulu"><div class="deger"><?= (int)$stats['aktif_kaynak'] ?></div><div class="etiket">Aktif kaynak</div></div>
  <div class="sayac"><div class="deger"><?= (int)$stats['bekleyen'] ?></div><div class="etiket">Bekleyen öge</div></div>
  <div class="sayac"><div class="deger"><?= (int)$stats['kuyrukta'] ?></div><div class="etiket">Kuyrukta</div></div>
  <div class="sayac"><div class="deger"><?= (int)$stats['bugun'] ?></div><div class="etiket">Bugün çekilen</div></div>
</div>

<?php if ($backoffVar && (int)arr($stats, 'beklemede', 0) > 0): ?>
  <div class="uyari bilgi">
    <?= (int)$stats['beklemede'] ?> kaynak beklemede: hata aldıkları için bir süre atlanıyorlar.
    Pasifleştirilmediler; sıradaki denemede kendiliğinden toparlanabilirler.
  </div>
<?php endif; ?>

<nav class="sekmeler">
  <a href="<?= esc(admin_url('rss', ['sekme' => 'kaynaklar'])) ?>" class="<?= $tab === 'kaynaklar' ? 'etkin' : '' ?>">Kaynaklar</a>
  <a href="<?= esc(admin_url('rss', ['sekme' => 'havuz'])) ?>" class="<?= $tab === 'havuz' ? 'etkin' : '' ?>">Havuz<?= $stats['bekleyen'] > 0 ? ' (' . (int)$stats['bekleyen'] . ')' : '' ?></a>
</nav>

<?php if ($tab === 'kaynaklar'): ?>
<!-- ============================================================ A) Kaynaklar -->
<div class="iki-kolon">
  <div>
    <?php if (!$sources): ?>
      <div class="bos-durum">
        <span class="buyuk" aria-hidden="true">⟳</span>
        <h3>Henüz RSS kaynağı yok</h3>
        <p class="soluk">Sağdaki formdan bir besleme adresi ekleyin. Eklemeden önce “Adresi sına” ile test edebilirsiniz.</p>
      </div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead>
            <tr>
              <th>Kaynak</th><th>Kategori</th><th>Otomatik</th><th>YZ</th>
              <th>Durum</th><th>Son çekim</th><th class="islem">İşlem</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($sources as $s):
            $sid = (int)$s['id'];
            $err = trim((string)($s['fetch_error'] ?? ''));
            $shortUrl = mb_strlen($s['url']) > 46 ? mb_substr($s['url'], 0, 44) . '…' : $s['url']; ?>
            <tr data-kaynak="<?= $sid ?>">
              <td>
                <strong><?= esc($s['name']) ?></strong>
                <span class="satir-alt tek-satir" title="<?= esc($s['url']) ?>"><?= esc($shortUrl) ?></span>
                <?php if ($err !== ''): ?>
                  <span class="rozet olumsuz" title="<?= esc($err) ?>">Son hata</span>
                <?php endif; ?>
              </td>
              <td class="dar"><?= esc($s['category_name'] !== null && $s['category_name'] !== '' ? $s['category_name'] : '—') ?></td>
              <td class="dar"><?= (int)$s['auto_publish'] === 1 ? admin_badge('açık', 'olumlu') : admin_badge('kapalı', 'notr') ?></td>
              <td class="dar"><?= (int)$s['ai_rewrite'] === 1 ? admin_badge('yeniden yaz', 'ai') : admin_badge('—', 'notr') ?></td>
              <td class="dar">
                <?php
                  $sonraki = $backoffVar ? trim((string)($s['next_check_at'] ?? '')) : '';
                  $bekliyor = ((int)$s['active'] === 1 && $sonraki !== '' && $sonraki > $simdiZaman);
                ?>
                <?= (int)$s['active'] === 1 ? admin_badge('aktif', 'olumlu') : admin_badge('pasif', 'notr') ?>
                <?php if ($bekliyor): ?>
                  <span class="rozet bilgi" title="Bir sonraki kontrol: <?= esc($sonraki) ?>">beklemede</span>
                <?php endif; ?>
                <?php if ((int)$s['error_count'] > 0): ?>
                  <span class="rozet uyari" title="Üst üste hata sayısı<?= $bekliyor ? ' — kaynak pasifleştirilmez, geri çekilerek yeniden denenir' : '' ?>"><?= (int)$s['error_count'] ?> hata</span>
                <?php endif; ?>
              </td>
              <td class="dar"><span class="kucuk soluk"><?= esc($s['last_fetch_at'] ? tr_ago($s['last_fetch_at']) : 'hiç') ?></span></td>
              <td class="islem">
                <button type="button" class="dugme kucuk" data-rss-test="<?= $sid ?>">Test et</button>
                <button type="button" class="dugme kucuk" data-rss-cek="<?= $sid ?>">Şimdi çek</button>
                <a class="dugme kucuk" href="<?= esc(admin_url('rss', ['duzenle' => $sid])) ?>">Düzenle</a>
                <button type="button" class="dugme kucuk tehlike" data-rss-sil="<?= $sid ?>"
                        data-ad="<?= esc($s['name']) ?>">Sil</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="kart" id="rssFormKart">
    <div class="kart-baslik"><?= $editSource ? 'Kaynağı düzenle' : 'Yeni kaynak' ?></div>
    <form id="rssForm" autocomplete="off">
      <input type="hidden" name="id" value="<?= (int)($editSource ? $editSource['id'] : 0) ?>">

      <div class="form-alan">
        <label for="rss_name">Kaynak adı</label>
        <input type="text" id="rss_name" name="name" maxlength="190" required
               value="<?= esc($editSource ? $editSource['name'] : '') ?>" placeholder="Örn. Anadolu Ajansı — Gündem">
      </div>

      <div class="form-alan">
        <label for="rss_url">Besleme adresi (RSS / Atom)</label>
        <input type="url" id="rss_url" name="url" maxlength="500" required
               value="<?= esc($editSource ? $editSource['url'] : '') ?>" placeholder="https://ornek.com/rss.xml">
        <span class="form-yardim">Kaydetmeden önce “Adresi sına” ile beslemenin okunabildiğini doğrulamanız önerilir.</span>
      </div>

      <div class="form-satir">
        <div class="form-alan">
          <label for="rss_cat">Hedef kategori</label>
          <select id="rss_cat" name="category_id">
            <?= admin_options($categories, $editSource ? (int)$editSource['category_id'] : 0, 'Kategorisiz') ?>
          </select>
        </div>
        <div class="form-alan">
          <label for="rss_interval">Kontrol aralığı (dk)</label>
          <input type="number" id="rss_interval" name="interval_min" min="0" max="10080" step="1"
                 value="<?= (int)($editSource ? ($editSource['interval_min'] ?? 0) : 0) ?>"
                 <?= $backoffVar ? '' : 'disabled' ?>>
          <span class="form-yardim">0 = varsayılan (<?= (int)RSS_DEFAULT_INTERVAL ?> dk).
            <?= $backoffVar ? 'Bu süre dolmadan kaynak yeniden çekilmez.' : 'Bu alan için veritabanı güncellemesi gerekiyor.' ?></span>
        </div>
      </div>

      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" name="auto_publish" value="1" <?= ($editSource && (int)$editSource['auto_publish'] === 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Otomatik yayın</span>
        </label>
        <span class="form-yardim">Açıksa yeni ögeler cron turunda haber olarak yayımlanır. Kapalıysa havuzda bekler.</span>
      </div>

      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" name="ai_rewrite" value="1" <?= ($editSource && (int)$editSource['ai_rewrite'] === 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Yapay zekâ ile yeniden yaz</span>
        </label>
        <?php if (!$aiReady || !$aiQueueReady): ?>
          <span class="form-yardim">Yapay zekâ yapılandırılmadı (durum: <?= esc($aiStatus) ?>). Bu anahtar açık kalsa da
            ögeler olduğu gibi işlenir.</span>
        <?php else: ?>
          <span class="form-yardim">Otomatik yayın açıkken ögeler yeniden yazım kuyruğuna alınır.</span>
        <?php endif; ?>
      </div>

      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" name="active" value="1" <?= (!$editSource || (int)$editSource['active'] === 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Kaynak aktif</span>
        </label>
        <span class="form-yardim">
          <?php if ($backoffVar): ?>
            Hata alan kaynak pasifleştirilmez; giderek seyrelen aralıklarla (5 dk → 15 dk → 1 sa → 6 sa → 24 sa)
            yeniden denenir. İlk başarılı çekimde sayaç sıfırlanır. Bu anahtar yalnız sizin elinizdedir.
          <?php else: ?>
            Üst üste <?= (int)RSS_MAX_ERRORS ?> hata alan kaynak kendiliğinden pasifleşir.
          <?php endif; ?>
        </span>
      </div>

      <div class="dugme-grup">
        <button type="submit" class="dugme birincil"><?= $editSource ? 'Güncelle' : 'Ekle' ?></button>
        <button type="button" class="dugme" id="rssSina">Adresi sına</button>
        <?php if ($editSource): ?><a class="dugme" href="<?= esc(admin_url('rss')) ?>">Vazgeç</a><?php endif; ?>
      </div>
    </form>

    <?php if ($editSource && trim((string)($editSource['fetch_error'] ?? '')) !== ''): ?>
      <div class="uyari err bosluk-ust mini"><strong>Son hata:</strong> <?= esc($editSource['fetch_error']) ?></div>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<!-- ============================================================ B) Havuz -->
<form class="arac-cubugu" method="get" action="<?= esc(base_url()) ?>/admin/">
  <input type="hidden" name="p" value="rss">
  <input type="hidden" name="sekme" value="havuz">
  <select name="kaynak" aria-label="Kaynak"><?= admin_options($sourceOptions, $poolFilters['source_id'], 'Tüm kaynaklar') ?></select>
  <select name="durum" aria-label="Durum"><?= admin_options(rss_statuses(), $poolFilters['status'], 'Tüm durumlar') ?></select>
  <input type="search" name="ara" value="<?= esc($poolFilters['q']) ?>" placeholder="Başlıkta ara…">
  <?php if ($dupVar): ?>
    <label class="onay-satir" title="Başka bir kaynakta aynısı bulunduğu için işaretlenen ögeler">
      <input type="checkbox" name="tekrar" value="1" <?= $poolFilters['dup'] ? 'checked' : '' ?>>
      <span>Yalnız tekrarlar<?= (int)arr($stats, 'tekrar', 0) > 0 ? ' (' . (int)$stats['tekrar'] . ')' : '' ?></span>
    </label>
  <?php endif; ?>
  <button type="submit" class="dugme">Süz</button>
  <?php if ($poolFilters['source_id'] || $poolFilters['status'] !== '' || $poolFilters['q'] !== '' || $poolFilters['dup']): ?>
    <a class="dugme kucuk" href="<?= esc(admin_url('rss', ['sekme' => 'havuz'])) ?>">Temizle</a>
  <?php endif; ?>
  <span class="sag kucuk soluk"><?= (int)$poolTotal ?> öge</span>
</form>

<?php if (!$poolItems): ?>
  <div class="bos-durum">
    <span class="buyuk" aria-hidden="true">☰</span>
    <h3>Havuzda öge yok</h3>
    <p class="soluk">Kaynaklar sekmesinden “Şimdi çek” diyerek besleme okuyabilirsiniz.</p>
  </div>
<?php else: ?>
  <div class="arac-cubugu">
    <label class="onay-satir"><input type="checkbox" id="rssHepsi"> <span>Tümünü seç</span></label>
    <select id="rssTopluEylem" aria-label="Toplu işlem">
      <option value="">Toplu işlem…</option>
      <option value="ai_rewrite">YZ ile yeniden yaz</option>
      <option value="draft">Olduğu gibi taslak yap</option>
      <option value="skip">Atla</option>
    </select>
    <button type="button" class="dugme" id="rssTopluUygula">Uygula</button>
    <span class="sag kucuk soluk" id="rssSecimSayisi">0 seçili</span>
  </div>

  <div class="tablo-sarma">
    <table class="tablo">
      <thead>
        <tr><th class="dar"><span class="gizli">Seç</span></th><th class="dar">Görsel</th><th>Başlık</th>
            <th>Kaynak</th><th>Tarih</th><th class="dar">Durum</th><th class="islem">İşlem</th></tr>
      </thead>
      <tbody>
      <?php foreach ($poolItems as $it):
        $iid = (int)$it['id'];
        $st = (string)$it['status'];
        $tone = isset($statusTones[$st]) ? $statusTones[$st] : 'notr';
        $stLabel = isset(rss_statuses()[$st]) ? rss_statuses()[$st] : $st; ?>
        <tr data-oge="<?= $iid ?>">
          <td class="dar"><input type="checkbox" class="rss-sec" value="<?= $iid ?>" aria-label="Ögeyi seç"></td>
          <td class="dar">
            <?php if (trim((string)$it['image']) !== ''): ?>
              <img class="kucuk-gorsel" src="<?= esc($it['image']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?>
              <span class="mini soluk">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (trim((string)$it['link']) !== ''): ?>
              <a href="<?= esc($it['link']) ?>" target="_blank" rel="nofollow noopener"><?= esc($it['title']) ?> ↗</a>
            <?php else: ?>
              <strong><?= esc($it['title']) ?></strong>
            <?php endif; ?>
            <?php if (trim((string)$it['summary']) !== ''): ?>
              <span class="satir-alt"><?= esc(excerpt($it['summary'], 110)) ?></span>
            <?php endif; ?>
          </td>
          <td class="dar"><span class="kucuk"><?= esc($it['source_name'] !== null && $it['source_name'] !== '' ? $it['source_name'] : '—') ?></span></td>
          <td class="dar"><span class="kucuk soluk"><?= esc(tr_date($it['published_at'] ? $it['published_at'] : $it['fetched_at'])) ?></span></td>
          <td class="dar">
            <?= admin_badge($stLabel, $tone) ?>
            <?php if ($dupVar && (int)($it['dup_of'] ?? 0) > 0): ?>
              <span class="rozet uyari" title="Bu haberin aynısı #<?= (int)$it['dup_of'] ?> numaralı ögede zaten var. Yanlışsa “Taslak yap” ile yine de alabilirsiniz.">tekrar</span>
            <?php endif; ?>
          </td>
          <td class="islem">
            <button type="button" class="dugme kucuk" data-oge-onizle="<?= $iid ?>">Önizle</button>
            <button type="button" class="dugme kucuk" data-oge-eylem="ai_rewrite" data-id="<?= $iid ?>">YZ ile yaz</button>
            <button type="button" class="dugme kucuk" data-oge-eylem="draft" data-id="<?= $iid ?>">Taslak yap</button>
            <button type="button" class="dugme kucuk" data-oge-eylem="skip" data-id="<?= $iid ?>">Atla</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= admin_paginate($poolTotal, $poolPerPage, $poolPage, 'rss', [
        'sekme'  => 'havuz',
        'kaynak' => $poolFilters['source_id'],
        'durum'  => $poolFilters['status'],
        'ara'    => $poolFilters['q'],
      ]) ?>
<?php endif; ?>
<?php endif; ?>

<script>
/* RSS paneli — tüm yazma işlemleri api.php?a=rss.* uçlarına gider. */
(function () {
  'use strict';
  var AI_HAZIR = <?= json_encode((bool)($aiReady && $aiQueueReady)) ?>;
  var SEKME = <?= json_encode($tab) ?>;

  /* ---------------------------------------------------- kaynak formu */
  var form = M.qs('#rssForm');

  function formuOku() {
    var d = M.formVerisi(form);
    return {
      id: parseInt(d.id, 10) || 0,
      name: d.name || '',
      url: d.url || '',
      category_id: parseInt(d.category_id, 10) || 0,
      interval_min: parseInt(d.interval_min, 10) || 0,
      auto_publish: d.auto_publish ? 1 : 0,
      ai_rewrite: d.ai_rewrite ? 1 : 0,
      active: d.active ? 1 : 0
    };
  }

  /* Test sonucunu modalde gösterir. */
  function testSonucu(url, r) {
    var h = '<p class="kucuk soluk tek-satir">' + M.esc(url) + '</p>';
    if (!r.ok) {
      h += '<div class="uyari err">' + M.esc(r.error || 'Besleme okunamadı.') + '</div>';
      M.modal.ac('Besleme testi', h);
      return;
    }
    h += '<div class="uyari ok"><strong>' + r.count + '</strong> öge bulundu.'
       + (r.feed_title ? ' Besleme adı: <strong>' + M.esc(r.feed_title) + '</strong>' : '') + '</div>';
    h += '<div class="uyari ' + (r.image_found ? 'bilgi' : 'warn') + ' mini">'
       + (r.image_found ? 'Ögelerde görsel bulundu.' : 'Ögelerde görsel bulunamadı — haberler görselsiz oluşturulur.')
       + '</div>';
    var s = r.samples || [];
    if (s.length) {
      h += '<p class="kucuk"><strong>İlk ' + s.length + ' başlık:</strong></p><ol class="kucuk">';
      for (var i = 0; i < s.length; i++) { h += '<li>' + M.esc(s[i]) + '</li>'; }
      h += '</ol>';
    }
    M.modal.ac('Besleme testi', h);
  }

  function adresiSina(url, id) {
    M.toast('bilgi', 'Besleme okunuyor…', 1500);
    M.api('rss.test', { url: url || '', id: id || 0 }).then(function (r) { testSonucu(url, r); });
  }

  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = formuOku();
      if (!d.name) { M.toast('err', 'Kaynak adı zorunlu.'); return; }
      if (!d.url) { M.toast('err', 'Besleme adresi zorunlu.'); return; }
      M.api('rss.save', d).then(function (r) {
        if (M.sonuc(r, 'Kaynak kaydedildi.')) {
          setTimeout(function () { location.href = <?= json_encode(admin_url('rss')) ?>; }, 500);
        }
      });
    });

    var sinaDugme = M.qs('#rssSina');
    if (sinaDugme) {
      sinaDugme.addEventListener('click', function () {
        var d = formuOku();
        if (!d.url) { M.toast('err', 'Önce bir besleme adresi girin.'); return; }
        adresiSina(d.url, 0);
      });
    }
  }

  var yeniDugme = M.qs('#rssYeniKaynak');
  if (yeniDugme) {
    yeniDugme.addEventListener('click', function () {
      var ad = M.qs('#rss_name');
      // Havuz sekmesindeysek ya da düzenleme açıksa boş forma dön
      if (SEKME !== 'kaynaklar' || !ad || M.qs('#rssForm [name="id"]').value !== '0') {
        location.href = <?= json_encode(admin_url('rss')) ?>;
        return;
      }
      M.qs('#rssFormKart').scrollIntoView({ behavior: 'smooth', block: 'center' });
      ad.focus();
    });
  }

  /* ---------------------------------------------------- satır işlemleri */
  M.qsa('[data-rss-test]').forEach(function (b) {
    b.addEventListener('click', function () {
      adresiSina('', parseInt(b.getAttribute('data-rss-test'), 10));
    });
  });

  M.qsa('[data-rss-cek]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-rss-cek'), 10);
      b.disabled = true;
      b.textContent = 'Çekiliyor…';
      M.api('rss.fetch_now', { id: id }).then(function (r) {
        b.disabled = false;
        b.textContent = 'Şimdi çek';
        if (M.sonuc(r, 'Çekim tamamlandı.')) {
          setTimeout(function () { location.reload(); }, 900);
        }
      });
    });
  });

  M.qsa('[data-rss-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-rss-sil'), 10);
      var ad = b.getAttribute('data-ad') || 'Kaynak';
      if (!M.onay(ad + ' silinecek. Bu kaynağa bağlı havuz ögeleri de silinir. Onaylıyor musunuz?')) { return; }
      M.api('rss.delete', { id: id }).then(function (r) {
        if (M.sonuc(r, 'Kaynak silindi.')) { setTimeout(function () { location.reload(); }, 600); }
      });
    });
  });

  /* ---------------------------------------------------- havuz işlemleri */
  function secililer() {
    return M.qsa('.rss-sec').filter(function (c) { return c.checked; })
      .map(function (c) { return parseInt(c.value, 10); });
  }

  function secimSayisiniYaz() {
    var el = M.qs('#rssSecimSayisi');
    if (el) { el.textContent = secililer().length + ' seçili'; }
  }

  function eylemUygula(action, ids) {
    if (action === 'ai_rewrite' && !AI_HAZIR) {
      M.toast('err', 'Yapay zekâ modülü kurulu değil.');
      return;
    }
    M.api('rss.item_action', { ids: ids, action: action }).then(function (r) {
      if (!M.sonuc(r, 'İşlem tamamlandı.')) { return; }
      if (r.post_id && r.post_url) {
        var g = M.modal.ac('Taslak oluşturuldu',
          '<p>' + M.esc(r.message) + '</p>'
          + '<p><a class="dugme birincil" href="' + M.esc(r.post_url) + '">Haberi düzenle →</a></p>');
        if (g) { g.setAttribute('data-hazir', '1'); }
      }
      setTimeout(function () { location.reload(); }, r.post_id ? 2600 : 700);
    });
  }

  /* Gövde önizleme: editör içeri almadan önce metnin tamamını görür.
     Gövde sunucuda sanitize_html() beyaz listesinden geçirilip gönderilir;
     burada HAM basılır, başlık/kaynak gibi alanlar ise M.esc ile kaçırılır. */
  M.qsa('[data-oge-onizle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-oge-onizle'), 10);
      b.disabled = true;
      M.api('rss.item_preview', { id: id }).then(function (r) {
        b.disabled = false;
        if (!r || !r.ok) { M.toast('err', (r && r.error) || 'Önizleme alınamadı.'); return; }
        var h = '<p class="kucuk soluk">' + M.esc(r.source_name || 'Kaynak')
              + (r.published_at ? ' · ' + M.esc(r.published_at) : '')
              + ' · ' + (r.chars || 0) + ' karakter</p>';
        if (r.dup_of) {
          h += '<div class="uyari warn mini">Bu öge <strong>tekrar</strong> olarak işaretlendi'
             + (r.dup_title ? ': “' + M.esc(r.dup_title) + '”' : '')
             + (r.dup_source ? ' (' + M.esc(r.dup_source) + ')' : '')
             + '. Yanlışsa “Taslak yap” ile yine de alabilirsiniz.</div>';
        }
        if (r.image) {
          h += '<p><img src="' + M.esc(r.image) + '" alt="" referrerpolicy="no-referrer" '
             + 'style="max-width:100%;height:auto;border-radius:6px"></p>';
        }
        h += '<div class="rss-onizleme">' + (r.body || '<p class="soluk">Gövde boş geldi.</p>') + '</div>';
        if (r.link) {
          h += '<p class="kucuk"><a href="' + M.esc(r.link) + '" target="_blank" rel="nofollow noopener">Kaynakta aç ↗</a></p>';
        }
        h += '<p><button type="button" class="dugme birincil" data-onizle-taslak="' + id + '">Taslak yap</button></p>';
        var g = M.modal.ac(r.title || 'Önizleme', h);
        if (g) {
          var t = g.querySelector('[data-onizle-taslak]');
          if (t) { t.addEventListener('click', function () { eylemUygula('draft', [id]); }); }
        }
      });
    });
  });

  M.qsa('[data-oge-eylem]').forEach(function (b) {
    b.addEventListener('click', function () {
      eylemUygula(b.getAttribute('data-oge-eylem'), [parseInt(b.getAttribute('data-id'), 10)]);
    });
  });

  var hepsi = M.qs('#rssHepsi');
  if (hepsi) {
    hepsi.addEventListener('change', function () {
      M.qsa('.rss-sec').forEach(function (c) { c.checked = hepsi.checked; });
      secimSayisiniYaz();
    });
  }
  M.qsa('.rss-sec').forEach(function (c) { c.addEventListener('change', secimSayisiniYaz); });

  var topluDugme = M.qs('#rssTopluUygula');
  if (topluDugme) {
    topluDugme.addEventListener('click', function () {
      var action = M.qs('#rssTopluEylem').value;
      var ids = secililer();
      if (!action) { M.toast('warn', 'Önce bir toplu işlem seçin.'); return; }
      if (!ids.length) { M.toast('warn', 'Hiç öge seçilmedi.'); return; }
      if (!M.onay(ids.length + ' öge için işlem uygulanacak. Onaylıyor musunuz?')) { return; }
      eylemUygula(action, ids);
    });
  }
})();
</script>
