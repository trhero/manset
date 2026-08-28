<?php
/**
 * Panel — yorum denetimi (1.3-07, Ajan-B; Ajan-2'nin Faz 2 sürümünden devralındı).
 *
 * 1.3 ile gelenler:
 *   · "Bildirilenler" sekmesi — okurun işaretlediği yorumlar, en çok bildirilen üstte.
 *   · HABERE GÖRE SÜZME — tek bir haber altındaki tartışma bir arada okunur.
 *     Moderasyon pratikte haber haber yapılır: bir haberde tartışma kızıştığında
 *     moderatör o başlığın altını baştan sona okumak ister, tarih sırasına
 *     karışmış 25 satırı değil.
 *   · "Spam + askıya al" — tek düğmede yorumu spam yapar ve yazarını kara listeye
 *     alır. İki ayrı adımda askıya alma hep atlanıyordu: moderatör yorumu
 *     sildikten sonra e-postayı kopyalayacak yer kalmıyor.
 *   · Kara liste kartı (e-posta / IP / sözcük).
 *   · Yanıtlar kökünün altında girintili gösterilir; kökü olmayan yanıt
 *     ("yetim") işaretlenir — ön yüzde görünmez, panelde kaybolmaz.
 *
 * Tekil/toplu durum işlemleri hâlâ Ajan-2'nin `comments.moderate` / `comments.bulk`
 * uçlarından geçer; bu iş paketi onları DEĞİŞTİRMEZ, yanına yenilerini koyar.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('comments.moderate');

$adminPageTitle = 'Yorumlar';

$v2 = function_exists('comments_supports_v2') && comments_supports_v2();

$durumlar = [
    'pending'  => 'Bekleyen',
    'approved' => 'Onaylı',
    'spam'     => 'Spam',
];
if ($v2) { $durumlar['reported'] = 'Bildirilenler'; }
$durumlar['all'] = 'Tümü';

$durum = (string)inp('durum', 'pending');
if (!isset($durumlar[$durum])) { $durum = 'pending'; }

$haberId = inp_i('haber', 0);
$sayfa   = max(1, inp_i('sayfa', 1));
$perPage = 25;

// ---------------------------------------------------------------- kara liste POST
// Kara liste formu KLASİK POST ile çalışır (JSON ucu da var, ama bu ekranda
// JS kapalıyken de yayıncı kendini savunabilmeli). admin/index.php ob_start()
// tamponunu açtığı için redirect güvenlidir.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') !== '') {
    csrf_guard();
    $eylem = (string)inp('do');
    if ($eylem === 'kara_ekle' && function_exists('comments_blacklist_add')) {
        $r = comments_blacklist_add((string)inp('kind', ''), (string)inp('value', ''),
                                    (string)inp('note', ''), (int)$adminUser['id']);
        if (!empty($r['ok'])) { flash('ok', 'Kara liste kuralı eklendi.'); }
        else { flash('err', (string)$r['error']); }
    } elseif ($eylem === 'kara_sil' && function_exists('comments_blacklist_remove')) {
        if (comments_blacklist_remove(inp_i('id', 0))) { flash('ok', 'Kural kaldırıldı.'); }
        else { flash('err', 'Kural bulunamadı.'); }
    }
    redirect(admin_url('comments', ['durum' => $durum, 'haber' => $haberId]));
}

// ---------------------------------------------------------------- sayaçlar
$sayaclar = [
    'pending'  => (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'pending\'', [], 0),
    'approved' => (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'approved\'', [], 0),
    'spam'     => (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'spam\'', [], 0),
];
$sayaclar['all'] = $sayaclar['pending'] + $sayaclar['approved'] + $sayaclar['spam'];
if ($v2) {
    $sayaclar['reported'] = (int)qv('SELECT COUNT(*) FROM comments WHERE report_count > 0', [], 0);
}

// ---------------------------------------------------------------- süzgeç
$kosul = [];
$p = [];
if ($durum === 'reported') {
    $kosul[] = 'k.report_count > 0';
} elseif ($durum !== 'all') {
    $kosul[] = 'k.status = :st';
    $p[':st'] = $durum;
}
if ($haberId > 0) { $kosul[] = 'k.post_id = :hid'; $p[':hid'] = $haberId; }
$where = $kosul ? ' WHERE ' . implode(' AND ', $kosul) : '';

// Bildirilenler kuyruğu en çok bildirilen üstte; diğerleri en yeni üstte.
$sira = $durum === 'reported' ? ' ORDER BY k.report_count DESC, k.id DESC' : ' ORDER BY k.id DESC';

$sutun = 'k.id, k.post_id, k.user_id, k.name, k.email, k.body, k.ip, k.status, k.created_at'
       . ($v2 ? ', k.parent_id, k.author_role, k.report_count, k.reported_at' : '');

$toplam = (int)qv('SELECT COUNT(*) FROM comments k' . $where, $p, 0);
$yorumlar = qa('SELECT ' . $sutun . ', p.title AS post_title, p.slug AS post_slug
    FROM comments k LEFT JOIN posts p ON p.id = k.post_id'
    . $where . $sira . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($sayfa - 1) * $perPage), $p);

// Yanıtların kökü: tek sorguda çekilir (satır başına sorgu açmamak için).
$kokAdi = [];
if ($v2) {
    $kokIds = [];
    foreach ($yorumlar as $y) { if ((int)$y['parent_id'] > 0) { $kokIds[(int)$y['parent_id']] = true; } }
    if ($kokIds) {
        $ph = []; $kp = []; $i = 0;
        foreach (array_keys($kokIds) as $kid) { $ph[] = ':k' . $i; $kp[':k' . $i] = (int)$kid; $i++; }
        foreach (qa('SELECT id, name FROM comments WHERE id IN (' . implode(', ', $ph) . ')', $kp) as $k) {
            $kokAdi[(int)$k['id']] = (string)$k['name'];
        }
    }
}

// Habere göre süzme listesi: yalnız yorumu OLAN haberler.
$haberSecenek = [];
foreach (qa('SELECT p.id, p.title, COUNT(k.id) AS adet
        FROM comments k JOIN posts p ON p.id = k.post_id
        GROUP BY p.id, p.title ORDER BY adet DESC, p.id DESC LIMIT 100') as $h) {
    $haberSecenek[(int)$h['id']] = excerpt((string)$h['title'], 60) . ' (' . (int)$h['adet'] . ')';
}

$karaListe = ($v2 && function_exists('comments_blacklist_all')) ? comments_blacklist_all(true) : [];
$karaTurler = ['email' => 'E-posta', 'ip' => 'IP', 'word' => 'Sözcük'];

/** comments.status → [etiket, ton] */
function comments_status_label($s) {
    $map = [
        'pending'  => ['Bekliyor', 'uyari'],
        'approved' => ['Onaylı', 'olumlu'],
        'spam'     => ['Spam', 'olumsuz'],
    ];
    return isset($map[$s]) ? $map[$s] : [$s, 'notr'];
}
?>

<div class="sayfa-basligi">
  <h1>Yorumlar</h1>
  <?php if (setting('comments_enabled', '1') !== '1'): ?>
    <span class="rozet uyari">Yorumlar site genelinde kapalı</span>
  <?php endif; ?>
  <?php if (!$v2): ?>
    <span class="rozet uyari">Göç 024 uygulanmamış — yanıt, rozet ve kara liste kapalı</span>
  <?php endif; ?>
</div>

<nav class="sekmeler" aria-label="Yorum durumu">
  <?php foreach ($durumlar as $k => $etiket): ?>
    <a href="<?= esc(admin_url('comments', ['durum' => $k, 'haber' => $haberId])) ?>"
       class="<?= $k === $durum ? 'etkin' : '' ?>">
      <?= esc($etiket) ?> <span class="soluk">(<?= (int)$sayaclar[$k] ?>)</span>
    </a>
  <?php endforeach; ?>
</nav>

<form method="get" action="<?= esc(admin_url('comments')) ?>" class="arac-cubugu">
  <input type="hidden" name="p" value="comments">
  <input type="hidden" name="durum" value="<?= esc($durum) ?>">
  <label class="form-etiket" for="haberSuzgec">Habere göre süz</label>
  <select name="haber" id="haberSuzgec" onchange="this.form.submit()">
    <?= admin_options($haberSecenek, $haberId, 'Tüm haberler') ?>
  </select>
  <noscript><button type="submit" class="dugme kucuk">Süz</button></noscript>
  <?php if ($haberId > 0): ?>
    <a class="dugme kucuk ikincil" href="<?= esc(admin_url('comments', ['durum' => $durum])) ?>">Süzgeci kaldır</a>
  <?php endif; ?>
</form>

<?php if (!$yorumlar): ?>
  <div class="bos-durum">
    <span class="buyuk" aria-hidden="true">❝</span>
    <h3><?= $durum === 'pending' ? 'Bekleyen yorum yok' : 'Bu listede yorum yok' ?></h3>
    <p>Yeni yorumlar geldikçe burada listelenir.</p>
  </div>
<?php else: ?>

  <div class="arac-cubugu">
    <label class="onay-satir" style="margin:0">
      <input type="checkbox" id="tumunuSec" aria-label="Tümünü seç">
      <span class="kucuk">Tümünü seç</span>
    </label>
    <select id="topluIslem" aria-label="Toplu işlem">
      <option value="">Toplu işlem…</option>
      <option value="approve">Onayla</option>
      <option value="spam">Spam işaretle</option>
      <option value="pending">Beklemeye al</option>
      <?php if ($v2): ?><option value="suspend">Spam + askıya al</option><?php endif; ?>
      <option value="delete">Sil</option>
    </select>
    <button type="button" class="dugme" id="topluUygula">Uygula</button>
    <span class="sag kucuk soluk"><?= (int)$toplam ?> yorum</span>
  </div>

  <div class="tablo-sarma">
    <table class="tablo">
      <thead>
        <tr>
          <th class="dar"><span class="gizli">Seç</span></th>
          <th>Yorum</th>
          <th>Yazan</th>
          <th class="dar">Haber</th>
          <th class="dar">Tarih</th>
          <th class="islem">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($yorumlar as $y):
          list($etiket, $ton) = comments_status_label((string)$y['status']);
          $pid = $v2 ? (int)$y['parent_id'] : 0;
          $rozet = ($v2 && function_exists('comments_badge')) ? comments_badge($y) : null;
          $bildirim = $v2 ? (int)$y['report_count'] : 0; ?>
          <tr data-yorum="<?= (int)$y['id'] ?>">
            <td class="dar">
              <input type="checkbox" class="yorum-sec" value="<?= (int)$y['id'] ?>"
                     aria-label="<?= esc($y['name']) ?> yorumunu seç">
            </td>
            <td>
              <?= admin_badge($etiket, $ton) ?>
              <?php if ($bildirim > 0): ?>
                <?= admin_badge($bildirim . ' bildirim', 'olumsuz') ?>
              <?php endif; ?>
              <?php if ($pid > 0): ?>
                <?php if (isset($kokAdi[$pid])): ?>
                  <span class="rozet notr">↳ <?= esc($kokAdi[$pid]) ?> yanıtı</span>
                <?php else: ?>
                  <span class="rozet uyari">↳ kökü silinmiş yanıt</span>
                <?php endif; ?>
              <?php endif; ?>
              <div class="yorum-govde"><?= nl2br(esc((string)$y['body'])) ?></div>
              <?php if ($bildirim > 0 && function_exists('comments_reports_for')):
                $gerekceler = comments_reports_for((int)$y['id']);
                $etiketler = function_exists('comments_report_reasons') ? comments_report_reasons() : []; ?>
                <div class="satir-alt mini">
                  Gerekçe:
                  <?php $parcalar = [];
                  foreach ($gerekceler as $g) {
                      $ad = isset($etiketler[$g['reason']]) ? $etiketler[$g['reason']] : (string)$g['reason'];
                      $parcalar[] = esc($ad) . ' ×' . (int)$g['adet'];
                  }
                  echo implode(' · ', $parcalar); ?>
                  <button type="button" class="dugme kucuk ikincil" data-bildirim-temizle="<?= (int)$y['id'] ?>">İncelendi</button>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= esc($y['name'] !== '' ? $y['name'] : 'Anonim') ?></strong>
              <?php if ($rozet): ?> <?= admin_badge($rozet['etiket'], $rozet['ton']) ?><?php endif; ?>
              <span class="satir-alt tek-satir"><?= esc($y['email']) ?></span>
              <span class="satir-alt mini">IP: <?= esc($y['ip']) ?></span>
            </td>
            <td class="dar">
              <?php if ($y['post_title']): ?>
                <a href="<?= esc(url_post(['id' => (int)$y['post_id'], 'slug' => (string)$y['post_slug']])) ?>"
                   target="_blank" rel="noopener" class="tek-satir" style="display:inline-block;max-width:180px">
                  <?= esc($y['post_title']) ?> ↗
                </a>
                <span class="satir-alt">
                  <a href="<?= esc(admin_url('comments', ['durum' => $durum, 'haber' => (int)$y['post_id']])) ?>">bu haberi süz</a>
                </span>
              <?php else: ?>
                <span class="soluk mini">silinmiş haber</span>
              <?php endif; ?>
            </td>
            <td class="dar mini soluk" title="<?= esc(tr_date($y['created_at'])) ?>"><?= esc(tr_ago($y['created_at'])) ?></td>
            <td class="islem">
              <?php if ($y['status'] !== 'approved'): ?>
                <button type="button" class="dugme kucuk" data-yorum-islem="approve" data-id="<?= (int)$y['id'] ?>">Onayla</button>
              <?php endif; ?>
              <?php if ($y['status'] !== 'spam'): ?>
                <button type="button" class="dugme kucuk" data-yorum-islem="spam" data-id="<?= (int)$y['id'] ?>">Spam</button>
              <?php endif; ?>
              <?php if ($y['status'] !== 'pending'): ?>
                <button type="button" class="dugme kucuk" data-yorum-islem="pending" data-id="<?= (int)$y['id'] ?>">Beklet</button>
              <?php endif; ?>
              <?php if ($v2): ?>
                <button type="button" class="dugme kucuk tehlike" data-yorum-askiya="<?= (int)$y['id'] ?>"
                        data-onay="Yorum spam yapılacak; yazarın e-postası ve IP'si kara listeye eklenecek. Onaylıyor musunuz?">Spam + askıya al</button>
              <?php endif; ?>
              <button type="button" class="dugme kucuk tehlike" data-yorum-islem="delete" data-id="<?= (int)$y['id'] ?>"
                      data-onay="Yorum kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= admin_paginate($toplam, $perPage, $sayfa, 'comments', ['durum' => $durum, 'haber' => $haberId]) ?>
<?php endif; ?>

<?php if ($v2): ?>
<div class="kart bosluk-ust">
  <div class="kart-baslik">
    <h2>Kara liste</h2>
    <span class="soluk kucuk">Eşleşen yorum doğrudan <strong>spam</strong> olur; gönderene bunu söylenmez.</span>
  </div>

  <form method="post" class="form-satir" action="<?= esc(admin_url('comments', ['durum' => $durum, 'haber' => $haberId])) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="kara_ekle">
    <div class="form-alan">
      <label class="form-etiket" for="karaTur">Tür</label>
      <select name="kind" id="karaTur">
        <?= admin_options($karaTurler, 'word') ?>
      </select>
    </div>
    <div class="form-alan">
      <label class="form-etiket" for="karaDeger">Desen</label>
      <input type="text" name="value" id="karaDeger" maxlength="190" required
             placeholder="ornek@spam.test · @spam.test · 203.0.113. · yasakli ifade">
      <span class="form-yardim">E-posta: tam adres ya da <code>@alanadi</code>. IP: tam adres ya da
        <code>203.0.113.</code> biçiminde önek (nokta zorunlu). Sözcük: en az 3 karakter, yorum
        gövdesinde ve adında aranır.</span>
    </div>
    <div class="form-alan">
      <label class="form-etiket" for="karaNot">Not</label>
      <input type="text" name="note" id="karaNot" maxlength="200" placeholder="isteğe bağlı">
    </div>
    <button type="submit" class="dugme birincil">Ekle</button>
  </form>

  <?php if (!$karaListe): ?>
    <p class="soluk kucuk">Kara listede kayıt yok.</p>
  <?php else: ?>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead><tr><th class="dar">Tür</th><th>Desen</th><th>Not</th><th class="dar">Tarih</th><th class="islem">İşlem</th></tr></thead>
        <tbody>
          <?php foreach ($karaListe as $k): ?>
            <tr>
              <td class="dar"><?= esc(isset($karaTurler[$k['kind']]) ? $karaTurler[$k['kind']] : $k['kind']) ?></td>
              <td class="tek-satir"><code><?= esc($k['value']) ?></code></td>
              <td class="mini soluk"><?= esc($k['note']) ?></td>
              <td class="dar mini soluk"><?= esc(tr_date($k['created_at'], false)) ?></td>
              <td class="islem">
                <form method="post" action="<?= esc(admin_url('comments', ['durum' => $durum, 'haber' => $haberId])) ?>" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="kara_sil">
                  <input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
                  <button type="submit" class="dugme kucuk tehlike" data-onay="Kural kaldırılacak. Onaylıyor musunuz?">Kaldır</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
/* Yorum metni tabloda okunabilir kalsın diye satır genişliği sınırlanıyor;
   admin.css'te uzun metin bloğu için hazır bir sınıf yok. */
.yorum-govde { margin-top:5px; font-size:13.5px; line-height:1.55; max-width:520px; white-space:pre-wrap; word-break:break-word; }
</style>

<script>
M.hazir(function () {
  function secilenler() {
    return M.qsa('.yorum-sec:checked').map(function (c) { return parseInt(c.value, 10); });
  }

  var hepsi = document.getElementById('tumunuSec');
  if (hepsi) {
    hepsi.addEventListener('change', function () {
      M.qsa('.yorum-sec').forEach(function (c) { c.checked = hepsi.checked; });
    });
  }

  function yenile() { setTimeout(function () { location.reload(); }, 450); }

  /* ------------------------------------------------ tekil durum işlemi */
  M.qsa('[data-yorum-islem]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-id'), 10);
      var islem = b.getAttribute('data-yorum-islem');
      b.disabled = true;
      M.api('comments.moderate', { id: id, action: islem }).then(function (r) {
        if (M.sonuc(r, 'İşlem tamam.')) { yenile(); } else { b.disabled = false; }
      });
    });
  });

  /* ------------------------------------------------ spam + askıya al */
  M.qsa('[data-yorum-askiya]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-yorum-askiya'), 10);
      b.disabled = true;
      M.api('comments.suspend', { id: id, ip: true }).then(function (r) {
        if (M.sonuc(r, 'Yorum spam yapıldı ve yazar kara listeye alındı.')) { yenile(); }
        else { b.disabled = false; }
      });
    });
  });

  /* ------------------------------------------------ bildirimleri temizle */
  M.qsa('[data-bildirim-temizle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-bildirim-temizle'), 10);
      b.disabled = true;
      M.api('comments.reports_clear', { id: id }).then(function (r) {
        if (M.sonuc(r, 'Bildirimler temizlendi.')) { yenile(); } else { b.disabled = false; }
      });
    });
  });

  /* ------------------------------------------------ toplu işlem */
  var uygula = document.getElementById('topluUygula');
  if (uygula) {
    uygula.addEventListener('click', function () {
      var islem = document.getElementById('topluIslem').value;
      if (!islem) { M.toast('warn', 'Önce bir işlem seçin.'); return; }
      var ids = secilenler();
      if (!ids.length) { M.toast('warn', 'Hiç yorum seçmediniz.'); return; }
      if (islem === 'delete' && !M.onay(ids.length + ' yorum kalıcı olarak silinecek. Onaylıyor musunuz?')) { return; }
      if (islem === 'suspend' && !M.onay(ids.length + ' yorum spam yapılacak ve yazarları kara listeye alınacak. Onaylıyor musunuz?')) { return; }
      uygula.disabled = true;
      var istek = (islem === 'suspend')
        ? M.api('comments.suspend', { ids: ids, ip: true })
        : M.api('comments.bulk', { ids: ids, action: islem });
      istek.then(function (r) {
        if (M.sonuc(r, 'İşlem tamam.')) { setTimeout(function () { location.reload(); }, 500); }
        else { uygula.disabled = false; }
      });
    });
  }
});
</script>
