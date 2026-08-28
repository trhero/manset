<?php
/**
 * Panel — genel bakış (Ajan-2).
 * Sayaçlar, son haberler, son yorumlar, sistem durumu ve hızlı işlemler.
 * Henüz kurulmamış modüllerin tabloları yoksa hata vermez (schema_has_table).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('admin.access');

$adminPageTitle = 'Panel';
$me = current_user();

// ---------------------------------------------------------------- sayaçlar
$sadeceKendi = can($me, 'posts.edit_any') ? '' : ' AND author_id = ' . (int)$me['id'];

/* NEDEN (denetim 3 · cop-izin B08→B09): Bu ekranın sayaçları ve "Son haberler"
 * listesi çöp kutusunu süzmüyordu; silinmiş haber hâlâ "Yayında/Taslak" olarak
 * sayılıyor ve listeden düzenlemeye açılabiliyordu. API karşılığı
 * (dashboard.stats) zaten `deleted_at = ''` süzüyor — iki ekran aynı veriyi
 * farklı gösteriyordu. Sütun yoksa (göç 013 uygulanmamış kurulum) koşul HİÇ
 * eklenmez; bu yüzden schema_has_column ile korunuyor ve sorgu çökmez.
 * `admin/pages/hizli.php` içindeki $copSuz kalıbının aynısı. */
$copSuz = (function_exists('schema_has_column') && schema_has_column('posts', 'deleted_at'))
    ? ' AND deleted_at = \'\'' : '';
$copSuzP = $copSuz !== '' ? ' AND p.deleted_at = \'\'' : '';

$sayacPublished = (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'published\'' . $sadeceKendi . $copSuz, [], 0);
$sayacDraft     = (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'draft\'' . $sadeceKendi . $copSuz, [], 0);
$sayacPending   = (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'pending\'' . $sadeceKendi . $copSuz, [], 0);
$sayacScheduled = (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'scheduled\'' . $sadeceKendi . $copSuz, [], 0);
$sayacViews     = (int)qv('SELECT COALESCE(SUM(view_count), 0) FROM posts WHERE 1 = 1' . $copSuz, [], 0);

$sayacYorum = 0;
if (can($me, 'comments.moderate') && schema_has_table('comments')) {
    $sayacYorum = (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'pending\'', [], 0);
}
$sayacRss = null;
if (schema_has_table('rss_items')) {
    $sayacRss = (int)qv('SELECT COUNT(*) FROM rss_items WHERE status = \'new\'', [], 0);
}
$sayacAi = null;
if (schema_has_table('ai_jobs')) {
    $sayacAi = (int)qv('SELECT COUNT(*) FROM ai_jobs WHERE status IN (\'queued\', \'running\')', [], 0);
}

// ---------------------------------------------------------------- son haberler
$sonHaberler = qa('SELECT p.id, p.title, p.slug, p.status, p.image, p.view_count, p.published_at, p.created_at,
        p.ai_generated, c.name AS category_name, u.name AS author_name
    FROM posts p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN users u ON u.id = p.author_id
    WHERE 1 = 1' . ($sadeceKendi !== '' ? ' AND p.author_id = ' . (int)$me['id'] : '') . $copSuzP . '
    ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT 8');

// ---------------------------------------------------------------- son yorumlar
$sonYorumlar = [];
if (can($me, 'comments.moderate') && schema_has_table('comments')) {
    $sonYorumlar = qa('SELECT k.id, k.name, k.body, k.status, k.created_at, k.post_id, p.title AS post_title, p.slug AS post_slug
        FROM comments k LEFT JOIN posts p ON p.id = k.post_id
        ORDER BY k.id DESC LIMIT 5');
}

// ---------------------------------------------------------------- sistem durumu
$cronSon = setting('cron_last_run', '');
$cronRapor = setting('cron_last_report', '');
$aiDurum = ai_status_text();
$medyaSayi = (int)qv('SELECT COUNT(*) FROM media', [], 0);
$medyaBoyut = function_exists('media_total_size') ? media_total_size() : 0;
?>

<div class="sayfa-basligi">
  <h1>Merhaba, <?= esc($me['name']) ?></h1>
  <div class="dugme-grup">
    <?php if (can($me, 'posts.create')): ?>
      <a class="dugme birincil" href="<?= esc(admin_url('posts', ['yeni' => 1])) ?>">+ Yeni haber</a>
    <?php endif; ?>
    <?php if (can($me, 'media.manage')): ?>
      <a class="dugme" href="<?= esc(admin_url('media')) ?>">Medya yükle</a>
    <?php endif; ?>
    <a class="dugme" href="<?= esc(base_url()) ?>/" target="_blank" rel="noopener">Siteyi gör ↗</a>
  </div>
</div>

<div class="izgara-4 bosluk-alt">
  <a class="sayac vurgulu" href="<?= esc(admin_url('posts', ['durum' => 'published'])) ?>">
    <div class="deger"><?= number_format($sayacPublished, 0, ',', '.') ?></div>
    <div class="etiket">Yayındaki haber</div>
  </a>
  <a class="sayac" href="<?= esc(admin_url('posts', ['durum' => 'draft'])) ?>">
    <div class="deger"><?= number_format($sayacDraft, 0, ',', '.') ?></div>
    <div class="etiket">Taslak</div>
  </a>
  <?php if (can($me, 'comments.moderate')): ?>
    <a class="sayac" href="<?= esc(admin_url('comments', ['durum' => 'pending'])) ?>">
      <div class="deger"><?= number_format($sayacYorum, 0, ',', '.') ?></div>
      <div class="etiket">Bekleyen yorum</div>
    </a>
  <?php endif; ?>
  <?php if ($sayacRss !== null && can($me, 'rss.manage')): ?>
    <a class="sayac" href="<?= esc(admin_url('rss')) ?>">
      <div class="deger"><?= number_format($sayacRss, 0, ',', '.') ?></div>
      <div class="etiket">RSS havuzunda bekleyen</div>
    </a>
  <?php endif; ?>
  <?php if ($sayacAi !== null && can($me, 'ai.use')): ?>
    <a class="sayac" href="<?= esc(admin_url('ai')) ?>">
      <div class="deger"><?= number_format($sayacAi, 0, ',', '.') ?></div>
      <div class="etiket">Yapay zekâ kuyruğu</div>
    </a>
  <?php endif; ?>
  <div class="sayac">
    <div class="deger"><?= number_format($sayacViews, 0, ',', '.') ?></div>
    <div class="etiket">Toplam görüntülenme</div>
  </div>
</div>

<?php if ($sayacPending > 0 && can($me, 'posts.publish')): ?>
  <div class="uyari warn">
    <?= (int)$sayacPending ?> haber onay bekliyor.
    <a href="<?= esc(admin_url('posts', ['durum' => 'pending'])) ?>">Onay kuyruğunu aç →</a>
  </div>
<?php endif; ?>

<div class="iki-kolon">
  <div>
    <div class="kart">
      <div class="kart-baslik">
        <span>Son haberler</span>
        <a class="kucuk" href="<?= esc(admin_url('posts')) ?>">Tümü →</a>
      </div>
      <?php if (!$sonHaberler): ?>
        <div class="bos-durum">
          <span class="buyuk" aria-hidden="true">✎</span>
          <h3>Henüz haber yok</h3>
          <p>İlk haberinizi oluşturarak başlayın.</p>
          <?php if (can($me, 'posts.create')): ?>
            <a class="dugme birincil" href="<?= esc(admin_url('posts', ['yeni' => 1])) ?>">+ Yeni haber</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="tablo-sarma" style="border:0">
          <table class="tablo">
            <thead><tr><th></th><th>Başlık</th><th>Durum</th><th class="dar">Görüntülenme</th><th class="dar">Tarih</th></tr></thead>
            <tbody>
              <?php foreach ($sonHaberler as $h):
                list($etiket, $ton) = admin_status_label((string)$h['status']); ?>
                <tr>
                  <td class="dar">
                    <?php if ($h['image'] !== ''): ?>
                      <img class="kucuk-gorsel" src="<?= esc(post_image($h, 'thumb')) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <span class="kucuk-gorsel" aria-hidden="true"></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="<?= esc(admin_url('posts', ['duzenle' => (int)$h['id']])) ?>"><?= esc($h['title']) ?></a>
                    <?php if ((int)$h['ai_generated'] === 1): ?> <?= admin_badge('YZ', 'ai') ?><?php endif; ?>
                    <span class="satir-alt"><?= esc(arr($h, 'category_name', '') ?: 'Kategorisiz') ?> · <?= esc(arr($h, 'author_name', '')) ?></span>
                  </td>
                  <td class="dar"><?= admin_badge($etiket, $ton) ?></td>
                  <td class="dar"><?= number_format((int)$h['view_count'], 0, ',', '.') ?></td>
                  <td class="dar mini soluk"><?= esc(tr_ago($h['published_at'] ?: $h['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if (can($me, 'comments.moderate')): ?>
      <div class="kart">
        <div class="kart-baslik">
          <span>Son yorumlar</span>
          <a class="kucuk" href="<?= esc(admin_url('comments')) ?>">Tümü →</a>
        </div>
        <?php if (!$sonYorumlar): ?>
          <p class="soluk kucuk">Henüz yorum yok.</p>
        <?php else: ?>
          <div class="tablo-sarma" style="border:0">
            <table class="tablo">
              <tbody>
                <?php foreach ($sonYorumlar as $y):
                  $ton = $y['status'] === 'approved' ? 'olumlu' : ($y['status'] === 'spam' ? 'olumsuz' : 'uyari');
                  $durumAd = $y['status'] === 'approved' ? 'onaylı' : ($y['status'] === 'spam' ? 'spam' : 'bekliyor'); ?>
                  <tr>
                    <td>
                      <strong><?= esc($y['name']) ?></strong> <?= admin_badge($durumAd, $ton) ?>
                      <span class="satir-alt"><?= esc(excerpt((string)$y['body'], 110)) ?></span>
                      <span class="satir-alt">
                        <?php if ($y['post_title']): ?>
                          <a href="<?= esc(url_post(['id' => (int)$y['post_id'], 'slug' => (string)$y['post_slug']])) ?>" target="_blank" rel="noopener"><?= esc($y['post_title']) ?></a> ·
                        <?php endif; ?>
                        <?= esc(tr_ago($y['created_at'])) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="kart">
      <div class="kart-baslik">Sistem durumu</div>
      <table class="tablo" style="min-width:0">
        <tr>
          <th>Zamanlanmış görev</th>
          <td><?= $cronSon !== '' ? esc(tr_ago($cronSon)) : admin_badge('hiç çalışmadı', 'uyari') ?></td>
        </tr>
        <tr>
          <th>Yapay zekâ</th>
          <td><?= admin_badge($aiDurum, $aiDurum === 'hazır' ? 'olumlu' : ($aiDurum === 'test modu' ? 'bilgi' : 'uyari')) ?></td>
        </tr>
        <tr>
          <th>Temiz adres</th>
          <td><?= sef_enabled() ? admin_badge('açık', 'olumlu') : admin_badge('kapalı', 'uyari') ?></td>
        </tr>
        <tr>
          <th>Zamanlanmış haber</th>
          <td><?= (int)$sayacScheduled ?></td>
        </tr>
        <tr>
          <th>Medya</th>
          <td><?= (int)$medyaSayi ?> dosya · <?= esc(function_exists('media_human_size') ? media_human_size($medyaBoyut) : '') ?></td>
        </tr>
      </table>
      <?php if ($cronRapor !== ''): ?>
        <details class="bosluk-ust">
          <summary class="kucuk">Son cron raporu</summary>
          <pre class="mini" style="white-space:pre-wrap;margin:8px 0 0"><?= esc($cronRapor) ?></pre>
        </details>
      <?php endif; ?>
    </div>

    <div class="kart">
      <div class="kart-baslik">Hızlı işlemler</div>
      <div class="dugme-grup">
        <?php if (can($me, 'posts.create')): ?>
          <a class="dugme" href="<?= esc(admin_url('posts', ['yeni' => 1])) ?>">+ Haber</a>
        <?php endif; ?>
        <?php if (can($me, 'categories.manage')): ?>
          <a class="dugme" href="<?= esc(admin_url('categories')) ?>">Kategoriler</a>
        <?php endif; ?>
        <?php if (can($me, 'media.manage')): ?>
          <a class="dugme" href="<?= esc(admin_url('media')) ?>">Medya</a>
        <?php endif; ?>
        <?php if (can($me, 'pages.manage')): ?>
          <a class="dugme" href="<?= esc(admin_url('pages')) ?>">Sabit sayfalar</a>
        <?php endif; ?>
        <?php if (can($me, 'comments.moderate')): ?>
          <a class="dugme" href="<?= esc(admin_url('comments', ['durum' => 'pending'])) ?>">Yorum kuyruğu</a>
        <?php endif; ?>
        <?php if (can($me, 'settings.manage')): ?>
          <a class="dugme" href="<?= esc(admin_url('settings')) ?>">Ayarlar</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<style>
/* Sayaç kutuları bağlantı olarak da kullanılıyor; admin.css'te .sayac için
   bağlantı görünümü tanımlı olmadığından yalnız metin rengi sabitleniyor. */
a.sayac { display:block; color:inherit; }
a.sayac:hover { text-decoration:none; border-color:var(--ana); }
</style>
