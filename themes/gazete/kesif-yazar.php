<?php
/**
 * Keşif — yazar sayfası (1.3-10).
 * Beklenen değişkenler: $author, $heading, $posts, $total, $page, $perPage, $baseUrl
 *
 * Bu şablon BEŞ TEMANIN ortak yedeğidir: view_resolve() temada `kesif-yazar.php`
 * bulamazsa gazete temasındaki bu dosyaya düşer. Bu yüzden burada yalnız her
 * temada tanımlı olan sınıflar (.izgara, .ana-sutun, .liste-ust) ve her temada
 * bulunan tema değişkenleri kullanılır; kart basımı part('card') ile temanın
 * kendi kartına devredilir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (empty($author) || !is_array($author)) { return; }

$posts   = isset($posts) ? (array)$posts : [];
$total   = isset($total) ? (int)$total : 0;
$page    = isset($page) ? (int)$page : 1;
$perPage = isset($perPage) ? (int)$perPage : 12;
$baseUrl = isset($baseUrl) ? (string)$baseUrl : url('yazar/' . $author['slug']);
// Avatar bugün hiçbir ekrandan doldurulmuyor (yükleme kapsam dışı). Alan elle
// ya da bir içe aktarımla dolarsa diye adres kurulmadan ÖNCE süzülür: yalnız
// düz dosya adı kabul edilir, yol/protokol içeren değer basılmaz.
$avatar  = trim((string)arr($author, 'avatar', ''));
if ($avatar !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $avatar)) { $avatar = ''; }
// Arşiv bağlantısı VERİDEN gelir: içeriği olmayan bir yıla bağlantı vermek
// okuru 404'e gönderirdi (discover_archive_view boş yılı bilerek 404'ler).
$arsivYil = 0;
if (function_exists('discover_archive_years')) {
    $ay = discover_archive_years();
    if ($ay) { $arsivYil = (int)array_key_first($ay); }
}
?>
<div class="liste iki-sutun kesif-sayfa">
  <div class="ana-sutun kesif-govde">

    <?= breadcrumbs([
      ['label' => 'Anasayfa', 'url' => url()],
      ['label' => (string)$author['name'], 'url' => $baseUrl],
    ]) ?>

    <header class="liste-ust kesif-ust">
      <div class="yazar-kunye">
        <?php if ($avatar !== ''): ?>
          <img class="yazar-avatar" src="<?= esc(url_upload($avatar)) ?>" alt=""
               width="72" height="72" loading="lazy" decoding="async">
        <?php endif; ?>
        <div class="yazar-bilgi">
          <h1><?= esc($author['name']) ?></h1>
          <?php if (!empty($author['role_label'])): ?>
            <p class="yazar-rol"><?= esc($author['role_label']) ?></p>
          <?php endif; ?>
          <?php if (!empty($author['bio'])): ?>
            <p class="yazar-bio"><?= esc($author['bio']) ?></p>
          <?php endif; ?>
          <p class="kesif-sayi"><?= (int)$total ?> haber<?= $page > 1 ? ' · ' . (int)$page . '. sayfa' : '' ?></p>
        </div>
      </div>
    </header>

    <?php if (!$posts): ?>
      <p class="kesif-bos">Bu yazarın gösterilecek haberi yok.</p>
    <?php else: ?>
      <div class="izgara kesif-izgara">
        <?php foreach ($posts as $p) { part('card', ['post' => $p, 'boyut' => 'orta']); } ?>
      </div>
      <?= paginate($total, $perPage, $page, function ($n) use ($baseUrl) {
            return $n > 1 ? $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . 'sayfa=' . (int)$n : $baseUrl;
          }) ?>
    <?php endif; ?>

    <nav class="kesif-baglantilar" aria-label="Keşif">
      <a href="<?= esc(url('etiketler')) ?>">Etiket bulutu</a>
      <?php if ($arsivYil): ?><a href="<?= esc(url('arsiv/' . $arsivYil)) ?>">Arşiv</a><?php endif; ?>
    </nav>
  </div>

  <?php part('sidebar'); ?>
</div>
