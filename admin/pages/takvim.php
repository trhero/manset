<?php
/**
 * Panel — yayın takvimi (hafta görünümü). (Ajan-F · 1.3-02)
 *
 * Zamanlanmış ve yayımlanmış haberlerin hangi güne düştüğünü gösterir.
 * Liste ekranı "ne zaman" sorusunu tarih sütunuyla yanıtlıyor ama haftanın
 * hangi gününün boş kaldığı, hangi güne üç haber yığıldığı listeden okunmuyor.
 * Takvim tam olarak bu soruyu yanıtlar.
 *
 * İZİN: `posts.view`. Takvim yalnız OKUR — hiçbir şey yazmaz — ve gösterdiği
 * her haber zaten haber listesinde görünüyor. `posts.publish` istemek, yayın
 * planını görmesi gereken muhabiri (kendi zamanlanmış haberini göremeyen
 * muhabiri) dışarıda bırakırdı. Yayımlama yetkisi olmayan kullanıcı yalnız
 * KENDİ haberlerini görür; süzgeç `revisions_calendar()` içinde, sunucuda.
 *
 * NOT: bu sayfanın menüye ve `admin_page_permission()` haritasına kaydı
 * `admin/index.php` içindedir ve o dosya Ajan-F'nin değildir — kayıt raporda
 * istenmiştir. Kayıt yapılmadan sayfa fail-closed davranır (açılmaz).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('posts.view');

$adminPageTitle = 'Yayın Takvimi';
$me = current_user();

if (!function_exists('revisions_calendar')) {
    echo '<div class="uyari err">Takvim modülü yüklenemedi (inc/revisions.php).</div>';
    return;
}

// ---------------------------------------------------------------- hafta hesabı
// Hafta pazartesi başlar (TR). `?hafta=` haftalık kaydırma (0 = bu hafta).
$kaydir = inp_i('hafta', 0);
if ($kaydir < -260 || $kaydir > 260) { $kaydir = 0; }

$bugun = new DateTimeImmutable(date('Y-m-d'));
// ISO-8601: pazartesi = 1. 'N' - 1 gün geri gidince haftanın başına düşeriz.
$haftaBasi = $bugun->modify('-' . ((int)$bugun->format('N') - 1) . ' days')
                   ->modify(($kaydir >= 0 ? '+' : '-') . abs($kaydir) . ' weeks');
$haftaSonu = $haftaBasi->modify('+6 days');

$gunler = revisions_calendar($haftaBasi->format('Y-m-d'), $haftaSonu->format('Y-m-d'), $me);

$gunAdi = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
$toplam = 0;
foreach ($gunler as $liste) { $toplam += count($liste); }
?>

<div class="sayfa-basligi">
  <h1>Yayın Takvimi <span class="soluk kucuk">(<?= (int)$toplam ?> haber)</span></h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('takvim', ['hafta' => $kaydir - 1])) ?>">← Önceki hafta</a>
    <?php if ($kaydir !== 0): ?>
      <a class="dugme" href="<?= esc(admin_url('takvim')) ?>">Bu hafta</a>
    <?php endif; ?>
    <a class="dugme" href="<?= esc(admin_url('takvim', ['hafta' => $kaydir + 1])) ?>">Sonraki hafta →</a>
  </div>
</div>

<p class="soluk kucuk">
  <?= esc(tr_date($haftaBasi->format('Y-m-d') . ' 00:00:00', false)) ?>
  – <?= esc(tr_date($haftaSonu->format('Y-m-d') . ' 00:00:00', false)) ?>
  · Zamanlanmış ve yayımlanmış haberler.
  <?php if (!can($me, 'posts.edit_any')): ?>Yalnız kendi haberleriniz gösteriliyor.<?php endif; ?>
</p>

<div class="tablo-sarma">
  <table class="tablo takvim-tablo">
    <thead>
      <tr>
        <?php for ($i = 0; $i < 7; $i++):
          $gun = $haftaBasi->modify('+' . $i . ' days'); ?>
          <th<?= $gun->format('Y-m-d') === $bugun->format('Y-m-d') ? ' class="etkin"' : '' ?>>
            <?= esc($gunAdi[$i]) ?>
            <span class="satir-alt"><?= esc($gun->format('d.m')) ?></span>
          </th>
        <?php endfor; ?>
      </tr>
    </thead>
    <tbody>
      <tr>
        <?php for ($i = 0; $i < 7; $i++):
          $anahtar = $haftaBasi->modify('+' . $i . ' days')->format('Y-m-d');
          $liste = isset($gunler[$anahtar]) ? $gunler[$anahtar] : []; ?>
          <td style="vertical-align:top;min-width:150px">
            <?php if (!$liste): ?>
              <span class="soluk mini">—</span>
            <?php else: ?>
              <?php foreach ($liste as $h):
                list($etiket, $ton) = admin_status_label((string)$h['status']); ?>
                <div style="margin-bottom:8px">
                  <div class="mini soluk"><?= esc(substr((string)$h['published_at'], 11, 5)) ?>
                    <?= admin_badge($etiket, $ton) ?></div>
                  <a class="kucuk" href="<?= esc(admin_url('posts', ['duzenle' => (int)$h['id']])) ?>"><?= esc($h['title']) ?></a>
                  <div class="mini soluk">
                    <?= esc(arr($h, 'category_name', '') ?: 'Kategorisiz') ?>
                    · <?= esc(arr($h, 'author_name', '') ?: '—') ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>
        <?php endfor; ?>
      </tr>
    </tbody>
  </table>
</div>

<p class="form-yardim">
  Zamanlanmış bir haberin saati geldiğinde cron yayımlar. Cron kurulu değilse
  panel ziyaretleri de tetikler (“poor man’s cron”), yani gecikme olabilir.
</p>
