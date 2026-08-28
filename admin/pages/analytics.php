<?php
/**
 * Panel — Okunma Raporu (1.2-01, Ajan-A).
 *
 * Kendi çerezsiz ölçümümüzün raporu. Ham veri (IP, referrer adresi,
 * user-agent) burada GÖSTERİLMEZ çünkü hiç SAKLANMAZ; bkz. inc/analytics.php.
 *
 * Grafikler satır içi SVG'dir — CONTRACTS §0 dış JS/CSS kütüphanesini yasaklar.
 * Ayrıca SVG yazdırmada ve ekran okuyucuda da bir şey ifade eder; canvas etmez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('analytics.view');

$adminPageTitle = 'Okunma Raporu';

// 7 ya da 30. Başka bir değer kabul edilmez: rapor sorguları gün aralığını
// tarar, serbest bir sayı panelden ağır sorgu tetiklemenin kolay yolu olurdu.
$gun = inp_i('gun', 7) === 30 ? 30 : 7;

$hazir  = function_exists('analytics_series') && analytics_table_exists('hit_daily');
$seri   = $hazir ? analytics_series($gun) : [];
$top    = $hazir ? analytics_top_posts($gun, 10) : [];
$refler = $hazir ? analytics_ref_breakdown($gun) : [];
$cihaz  = $hazir ? analytics_device_breakdown($gun) : [];
$huni   = $hazir ? analytics_funnel(30) : [];

$toplamGoruntuleme = 0;
$toplamZiyaretci   = 0;
foreach ($seri as $s) { $toplamGoruntuleme += (int)$s['hits']; $toplamZiyaretci += (int)$s['visitors']; }
$refToplam = array_sum($refler);
$cihazToplam = array_sum($cihaz);
$refAdlar = function_exists('analytics_ref_types') ? analytics_ref_types() : [];
?>

<div class="sayfa-basligi">
  <h1>Okunma Raporu</h1>
  <div class="sekmeler">
    <a href="<?= esc(admin_url('analytics', ['gun' => 7])) ?>" class="<?= $gun === 7 ? 'etkin' : '' ?>">Son 7 gün</a>
    <a href="<?= esc(admin_url('analytics', ['gun' => 30])) ?>" class="<?= $gun === 30 ? 'etkin' : '' ?>">Son 30 gün</a>
  </div>
</div>

<?php if (!$hazir): ?>
  <div class="uyari warn">
    Analitik tabloları bulunamadı. Yükseltme göçleri (<code>015_analytics</code>) henüz
    uygulanmamış olabilir; panele yönetici olarak girdiğinizde kendiliğinden uygulanır.
  </div>
<?php elseif (!analytics_has_data()): ?>
  <div class="bos-durum buyuk">
    <span class="buyuk" aria-hidden="true">◪</span>
    <h3>Henüz ölçüm yok</h3>
    <p>Ölçüm, siteyi ziyaret eden okurların haber sayfalarını açmasıyla birikir.
       Sayaç tarayıcıda çalışır; henüz kimse haber okumadıysa ya da ölçüm ayarlardan
       kapatıldıysa bu ekran boş kalır.</p>
    <a class="dugme" href="<?= esc(base_url()) ?>/" target="_blank" rel="noopener">Siteyi gör ↗</a>
  </div>
<?php else: ?>

<div class="izgara-4 bosluk-alt">
  <div class="sayac vurgulu">
    <div class="deger"><?= number_format($toplamGoruntuleme, 0, ',', '.') ?></div>
    <div class="etiket">Görüntülenme (<?= (int)$gun ?> gün)</div>
  </div>
  <div class="sayac">
    <div class="deger"><?= number_format($toplamZiyaretci, 0, ',', '.') ?></div>
    <div class="etiket">Tekil ziyaretçi (<?= (int)$gun ?> gün)</div>
  </div>
  <div class="sayac">
    <div class="deger"><?= $cihazToplam > 0 ? (int)round(arr($cihaz, 'mobil', 0) * 100 / $cihazToplam) : 0 ?>%</div>
    <div class="etiket">Mobil okur oranı</div>
  </div>
  <div class="sayac">
    <div class="deger"><?= $refToplam > 0 ? (int)round(arr($refler, 'arama', 0) * 100 / $refToplam) : 0 ?>%</div>
    <div class="etiket">Aramadan gelen</div>
  </div>
</div>

<div class="kart bosluk-alt">
  <div class="kart-baslik">
    <span>Günlük görüntülenme</span>
    <span class="kucuk soluk">Koyu kırmızı: görüntülenme · Lacivert: tekil ziyaretçi</span>
  </div>
  <?= analytics_bar_svg($seri, 'hits', 170) ?>
  <div class="an-ayirac"></div>
  <?= analytics_bar_svg($seri, 'visitors', 110) ?>
</div>

<div class="iki-kolon">
  <div>
    <div class="kart">
      <div class="kart-baslik">
        <span>En çok okunanlar</span>
        <span class="kucuk soluk">son <?= (int)$gun ?> gün</span>
      </div>
      <?php if (!$top): ?>
        <p class="soluk kucuk">Bu aralıkta haber kırılımı yok.</p>
      <?php else: ?>
        <div class="tablo-sarma" style="border:0">
          <table class="tablo">
            <thead><tr><th class="dar">#</th><th>Haber</th><th class="dar">Okunma</th></tr></thead>
            <tbody>
              <?php foreach ($top as $i => $r):
                $baslik = (string)arr($r, 'title', '');
                $silinmis = ($baslik === ''); ?>
                <tr>
                  <td class="dar soluk"><?= $i + 1 ?></td>
                  <td>
                    <?php if ($silinmis): ?>
                      <span class="soluk">Silinmiş haber (#<?= (int)$r['post_id'] ?>)</span>
                    <?php else: ?>
                      <a href="<?= esc(admin_url('posts', ['duzenle' => (int)$r['post_id']])) ?>"><?= esc($baslik) ?></a>
                      <span class="satir-alt">
                        <a href="<?= esc(url_post(['id' => (int)$r['post_id'], 'slug' => (string)arr($r, 'slug', '')])) ?>"
                           target="_blank" rel="noopener">Sitede aç ↗</a>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="dar"><strong><?= number_format((int)$r['hits'], 0, ',', '.') ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="kart">
      <div class="kart-baslik">Nereden geliyorlar</div>
      <p class="kucuk soluk">
        Ham adres saklanmaz, yalnız tür. Bir okurun hangi forumda ya da hangi arama
        teriminde olduğu bu tabloda yoktur ve hiç kaydedilmemiştir.
      </p>
      <?php foreach ($refler as $k => $n):
        if ($n === 0 && $k !== 'arama') { continue; }
        $yuzde = $refToplam > 0 ? ($n * 100 / $refToplam) : 0; ?>
        <div class="an-satir">
          <span class="an-ad"><?= esc(arr($refAdlar, $k, $k)) ?></span>
          <span class="an-cubuk"><span style="width:<?= esc(round($yuzde, 1)) ?>%"></span></span>
          <span class="an-deger"><?= number_format($n, 0, ',', '.') ?> · %<?= esc(number_format($yuzde, 0)) ?></span>
        </div>
      <?php endforeach; ?>
      <?php if ((int)arr($refler, 'bilinmiyor', 0) > 0): ?>
        <p class="kucuk soluk bosluk-ust">
          <strong>“Bilinmiyor”</strong> gerçek bir ölçüm sonucudur, eksik veri değildir:
          sayaç tarayıcıdan çağrıldığı için okurun siteye nereden geldiği bilgisi
          isteğin başlığında yer almaz. Bilinmeyeni “doğrudan” saymak, olmayan bir
          bilgiyi varmış gibi göstermek olurdu.
        </p>
      <?php endif; ?>
    </div>

    <div class="kart">
      <div class="kart-baslik">Cihaz</div>
      <?php foreach (analytics_device_types() as $k => $ad):
        $n = (int)arr($cihaz, $k, 0);
        $yuzde = $cihazToplam > 0 ? ($n * 100 / $cihazToplam) : 0; ?>
        <div class="an-satir">
          <span class="an-ad"><?= esc($ad) ?></span>
          <span class="an-cubuk"><span style="width:<?= esc(round($yuzde, 1)) ?>%"></span></span>
          <span class="an-deger"><?= number_format($n, 0, ',', '.') ?> · %<?= esc(number_format($yuzde, 0)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="kart">
      <div class="kart-baslik">
        <span>Abone hunisi</span>
        <span class="kucuk soluk">son 30 gün</span>
      </div>
      <?php
      $ziyaret = (int)arr($huni, 'ziyaret', 0);
      $uye     = (int)arr($huni, 'uye', 0);
      $premium = (int)arr($huni, 'premium', 0);
      $basamak = [
          ['Ziyaret (tekil)', $ziyaret, 'Sayfa görüntülemesi değil, tekil ziyaretçi. Çok sayfa gezen tek okur bir kez sayılır.'],
          ['Üye',             $uye,     'Etkin üye hesabı sayısı (toplam, anlık).'],
          ['Premium',         $premium, 'Süresi geçerli premium üyelik sayısı (anlık).'],
      ];
      $enBuyuk = max(1, $ziyaret, $uye, $premium);
      foreach ($basamak as $b): ?>
        <div class="an-satir" title="<?= esc($b[2]) ?>">
          <span class="an-ad"><?= esc($b[0]) ?></span>
          <span class="an-cubuk"><span style="width:<?= esc(round($b[1] * 100 / $enBuyuk, 1)) ?>%"></span></span>
          <span class="an-deger"><?= number_format((int)$b[1], 0, ',', '.') ?></span>
        </div>
      <?php endforeach; ?>
      <p class="kucuk soluk bosluk-ust">
        Dönüşüm: ziyaretçilerin <strong>%<?= $ziyaret > 0 ? esc(number_format($uye * 100 / $ziyaret, 2)) : '0' ?></strong>'i üye,
        üyelerin <strong>%<?= $uye > 0 ? esc(number_format($premium * 100 / $uye, 1)) : '0' ?></strong>'i premium.
        Son 30 günde <strong><?= (int)arr($huni, 'yeni_uye', 0) ?></strong> yeni üye kaydı var.
      </p>
      <p class="kucuk soluk">
        Not: ilk basamak bir DÖNEM toplamı, diğer ikisi ANLIK sayıdır; oran kaba bir
        büyüklük göstergesidir, muhasebe değildir.
      </p>
    </div>
  </div>
</div>

<?php endif; ?>

<style>
/* Sayfaya özel — admin.css sınıf sözlüğüne (CONTRACTS §8.2) yeni sınıf
   eklemek yerine burada duruyor; yalnız bu ekranın grafik yerleşimi için.
   Ön ek `an-` (analitik) çakışmayı önler. */
.analitik-grafik { display:block; width:100%; height:auto; }
.an-ayirac { height:1px; background:var(--cizgi); margin:14px 0 10px; }
.an-satir { display:flex; align-items:center; gap:10px; margin:7px 0; font-size:13px; }
.an-ad { flex:0 0 108px; color:var(--soluk); }
.an-cubuk { flex:1; height:9px; background:#eef1f4; border-radius:5px; overflow:hidden; min-width:40px; }
.an-cubuk > span { display:block; height:100%; background:var(--ana); border-radius:5px; }
.an-deger { flex:0 0 auto; white-space:nowrap; font-variant-numeric:tabular-nums; }
@media(max-width:600px){ .an-ad { flex-basis:78px; } }
</style>
