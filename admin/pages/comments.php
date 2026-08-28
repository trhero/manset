<?php
/**
 * Panel — yorum denetimi (Ajan-2).
 * Sekmeler + tekil/toplu işlemler. Yazma işlemleri comments.* uçlarından geçer.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('comments.moderate');

$adminPageTitle = 'Yorumlar';

$durumlar = [
    'pending'  => 'Bekleyen',
    'approved' => 'Onaylı',
    'spam'     => 'Spam',
    'all'      => 'Tümü',
];

$durum = (string)inp('durum', 'pending');
if (!isset($durumlar[$durum])) { $durum = 'pending'; }

$sayfa = max(1, inp_i('sayfa', 1));
$perPage = 25;

// Sekme sayaçları
$sayaclar = [
    'pending'  => (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'pending\'', [], 0),
    'approved' => (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'approved\'', [], 0),
    'spam'     => (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'spam\'', [], 0),
];
$sayaclar['all'] = $sayaclar['pending'] + $sayaclar['approved'] + $sayaclar['spam'];

$where = '';
$p = [];
if ($durum !== 'all') { $where = ' WHERE k.status = :st'; $p[':st'] = $durum; }

$toplam = (int)qv('SELECT COUNT(*) FROM comments k' . $where, $p, 0);
$yorumlar = qa('SELECT k.id, k.post_id, k.user_id, k.name, k.email, k.body, k.ip, k.status, k.created_at,
        p.title AS post_title, p.slug AS post_slug
    FROM comments k LEFT JOIN posts p ON p.id = k.post_id'
    . $where . ' ORDER BY k.id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($sayfa - 1) * $perPage), $p);

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
</div>

<nav class="sekmeler" aria-label="Yorum durumu">
  <?php foreach ($durumlar as $k => $etiket): ?>
    <a href="<?= esc(admin_url('comments', ['durum' => $k])) ?>" class="<?= $k === $durum ? 'etkin' : '' ?>">
      <?= esc($etiket) ?> <span class="soluk">(<?= (int)$sayaclar[$k] ?>)</span>
    </a>
  <?php endforeach; ?>
</nav>

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
          list($etiket, $ton) = comments_status_label((string)$y['status']); ?>
          <tr data-yorum="<?= (int)$y['id'] ?>">
            <td class="dar">
              <input type="checkbox" class="yorum-sec" value="<?= (int)$y['id'] ?>"
                     aria-label="<?= esc($y['name']) ?> yorumunu seç">
            </td>
            <td>
              <?= admin_badge($etiket, $ton) ?>
              <div class="yorum-govde"><?= nl2br(esc((string)$y['body'])) ?></div>
            </td>
            <td>
              <strong><?= esc($y['name'] !== '' ? $y['name'] : 'Anonim') ?></strong>
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
                  <a href="<?= esc(admin_url('posts', ['duzenle' => (int)$y['post_id']])) ?>">düzenle</a>
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
              <button type="button" class="dugme kucuk tehlike" data-yorum-islem="delete" data-id="<?= (int)$y['id'] ?>"
                      data-onay="Yorum kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= admin_paginate($toplam, $perPage, $sayfa, 'comments', ['durum' => $durum]) ?>
<?php endif; ?>

<style>
/* Yorum metni tabloda okunabilir kalsın diye satır genişliği sınırlanıyor;
   admin.css'te uzun metin bloğu için hazır bir sınıf yok. */
.yorum-govde { margin-top:5px; font-size:13.5px; line-height:1.55; max-width:520px; white-space:pre-wrap; word-break:break-word; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function secilenler() {
    return M.qsa('.yorum-sec:checked').map(function (c) { return parseInt(c.value, 10); });
  }

  var hepsi = document.getElementById('tumunuSec');
  if (hepsi) {
    hepsi.addEventListener('change', function () {
      M.qsa('.yorum-sec').forEach(function (c) { c.checked = hepsi.checked; });
    });
  }

  /* ------------------------------------------------ tekil işlem */
  M.qsa('[data-yorum-islem]').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = parseInt(b.getAttribute('data-id'), 10);
      var islem = b.getAttribute('data-yorum-islem');
      b.disabled = true;
      M.api('comments.moderate', { id: id, action: islem }).then(function (r) {
        if (M.sonuc(r, 'İşlem tamam.')) { setTimeout(function () { location.reload(); }, 450); }
        else { b.disabled = false; }
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
      uygula.disabled = true;
      M.api('comments.bulk', { ids: ids, action: islem }).then(function (r) {
        if (M.sonuc(r, 'İşlem tamam.')) { setTimeout(function () { location.reload(); }, 500); }
        else { uygula.disabled = false; }
      });
    });
  }
});
</script>
