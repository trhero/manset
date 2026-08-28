<?php
/**
 * Panel — Yapay Zekâ (Ajan-5).
 *
 * Dört bölüm: Durum & Sağlayıcı · İstem şablonları · İş kuyruğu · Günlükler.
 * Sağlayıcı/anahtar ve şablon bölümleri yalnız 'ai.configure' (yönetici) iznine açıktır.
 * Yazma işlemleri inc/api/ai_api.php uçlarına M.api() ile gider.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('ai.use');

$adminPageTitle = 'Yapay Zekâ';

if (!function_exists('ai_jobs_tick')) { require_once INC_DIR . '/ai_jobs.php'; }

$canConfigure = can($adminUser, 'ai.configure');

$aiTabs = [
    'durum'  => 'Durum & Sağlayıcı',
    'istem'  => 'İstem şablonları',
    'kuyruk' => 'İş kuyruğu',
    'gunluk' => 'Günlükler',
];
$tab = (string)inp('t', 'durum');
if (!isset($aiTabs[$tab])) { $tab = 'durum'; }

/** Panelde binlik ayraçlı sayı. */
function ai_panel_num($n) { return number_format((float)$n, 0, ',', '.'); }

/** ai_jobs.status → [etiket, ton] */
function ai_job_status_label($status) {
    $map = [
        'queued'  => ['Kuyrukta', 'notr'],
        'running' => ['Çalışıyor', 'bilgi'],
        'done'    => ['Tamam', 'olumlu'],
        'failed'  => ['Hata', 'olumsuz'],
    ];
    return isset($map[$status]) ? $map[$status] : [$status, 'notr'];
}

/** İki zaman damgası arasındaki süreyi okunur biçimde verir. */
function ai_job_duration($startedAt, $finishedAt) {
    if (!$startedAt || !$finishedAt) { return '—'; }
    $s = strtotime((string)$startedAt);
    $f = strtotime((string)$finishedAt);
    if (!$s || !$f || $f < $s) { return '—'; }
    $d = $f - $s;
    return $d < 60 ? $d . ' sn' : floor($d / 60) . ' dk ' . ($d % 60) . ' sn';
}

/** Para birimi olmadan tutar (yayıncı hangi para biriminde çalışıyorsa o). */
function ai_panel_tutar($n) { return number_format((float)$n, 2, ',', '.'); }

$ozet = ai_logs_summary(30);
$stats = ai_jobs_stats();
$butce = ai_budget_status();

/*
 * Web (panel) tetikleme durumu — B03/B07 görünürlüğü.
 * cron.php yalnız KÜTÜPHANE olarak yüklenir (MANSET_CRON_LIB), giriş bölümü
 * çalışmaz. Dosya yoksa ya da işlev tanımlı değilse kart hiç gösterilmez
 * (geriye dönük uyum: eski kurulumda çökmez).
 */
$webTick = null;
if (is_file(ROOT_DIR . '/cron.php')) {
    if (!defined('MANSET_CRON_LIB')) { define('MANSET_CRON_LIB', 1); }
    require_once ROOT_DIR . '/cron.php';
    if (function_exists('cron_web_tick_status')) { $webTick = cron_web_tick_status(); }
}
$promptLabels = [
    'ai_prompt_rewrite' => 'Yeniden yazım (RSS → haber)',
    'ai_prompt_title'   => 'Başlık önerisi',
    'ai_prompt_spot'    => 'Spot önerisi',
    'ai_prompt_seo'     => 'SEO alanları',
];
?>

<div class="sayfa-basligi">
  <h1>Yapay Zekâ</h1>
  <div class="dugme-grup">
    <?= admin_badge('Durum: ' . ai_status_text(), ai_status_text() === 'hazır' ? 'olumlu' : (ai_status_text() === 'test modu' ? 'ai' : 'uyari')) ?>
    <?php if ($canConfigure): ?>
      <button type="button" class="dugme" id="aiTestBtn">Bağlantıyı test et</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($butce['doldu'])): ?>
  <div class="uyari err">
    <strong>Aylık bütçe doldu.</strong>
    Bu ay <?= esc(ai_panel_tutar($butce['harcanan'])) ?> harcandı; tavan <?= esc(ai_panel_tutar($butce['tavan'])) ?>.
    Yeni yapay zekâ çağrısı yapılmıyor — kuyruktaki işler <strong>kaybolmadı</strong>, ay dönünce ya da
    tavanı yükselttiğinizde kaldıkları yerden sürer.
    <?php if ($canConfigure): ?>
      <a href="<?= esc(admin_url('ai', ['t' => 'gunluk'])) ?>">Tavanı düzenle →</a>
    <?php endif; ?>
  </div>
<?php elseif (!empty($butce['aktif']) && (int)$butce['oran'] >= 80): ?>
  <div class="uyari warn">
    Aylık bütçenin %<?= (int)$butce['oran'] ?>'i kullanıldı
    (<?= esc(ai_panel_tutar($butce['harcanan'])) ?> / <?= esc(ai_panel_tutar($butce['tavan'])) ?>).
    Tavan dolduğunda kuyruk durur, işler beklemeye alınır.
  </div>
<?php endif; ?>

<?php /* B08: çağrı tavanı — birim fiyat girilmemiş kurulumlarda tek koruma. */ ?>
<?php if (!empty($butce['cagri_doldu'])): ?>
  <div class="uyari err">
    <strong>Aylık çağrı tavanı doldu.</strong>
    Bu ay <?= esc(ai_panel_num($butce['cagri_sayisi'])) ?> çağrı yapıldı; tavan
    <?= esc(ai_panel_num($butce['cagri_tavan'])) ?>. Yeni yapay zekâ çağrısı yapılmıyor —
    kuyruktaki işler <strong>kaybolmadı</strong>.
    <?php if ($canConfigure): ?>
      <a href="<?= esc(admin_url('ai', ['t' => 'gunluk'])) ?>">Tavanı düzenle →</a>
    <?php endif; ?>
  </div>
<?php elseif ((int)$butce['cagri_tavan'] > 0 && (int)$butce['cagri_oran'] >= 80): ?>
  <div class="uyari warn">
    Aylık çağrı tavanının %<?= (int)$butce['cagri_oran'] ?>'i kullanıldı
    (<?= esc(ai_panel_num($butce['cagri_sayisi'])) ?> / <?= esc(ai_panel_num($butce['cagri_tavan'])) ?> çağrı).
  </div>
<?php endif; ?>

<?php if (!empty($butce['olculemiyor'])): ?>
  <div class="uyari warn">
    <strong>Aylık bütçe tavanı ölçülemiyor.</strong>
    <?= esc(ai_panel_tutar($butce['tavan'])) ?> tutarında bir tavan girilmiş, ancak birim fiyat
    (giriş/çıkış jetonu) girilmediği için harcama hesaplanamıyor — bu tavan <strong>hiçbir çağrıyı
    engellemez</strong>. Tavanın işe yaraması için birim fiyatlardan en az birini girin.
    <?php if ((int)$butce['cagri_tavan'] > 0): ?>
      Şu an korumayı yalnız aylık çağrı tavanı (<?= esc(ai_panel_num($butce['cagri_tavan'])) ?>) sağlıyor.
    <?php else: ?>
      Aylık çağrı tavanı da 0 (sınırsız) — şu an <strong>hiçbir üst sınır yok</strong>.
    <?php endif; ?>
    <?php if ($canConfigure): ?>
      <a href="<?= esc(admin_url('ai', ['t' => 'gunluk'])) ?>">Birim fiyat gir →</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<nav class="sekmeler" aria-label="Yapay zekâ bölümleri">
  <?php foreach ($aiTabs as $k => $label): ?>
    <a href="<?= esc(admin_url('ai', ['t' => $k])) ?>" class="<?= $k === $tab ? 'etkin' : '' ?>"><?= esc($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'durum'): // ============================== A) Durum & Sağlayıcı ?>

  <div class="izgara-4 bosluk-alt">
    <div class="sayac vurgulu">
      <div class="deger" style="font-size:20px"><?= esc(ai_status_text()) ?></div>
      <div class="etiket"><?= esc((string)arr(ai_provider_def(), 'ad', ai_provider())) ?> · <?= esc(ai_model()) ?></div>
    </div>
    <div class="sayac">
      <div class="deger"><?= esc(ai_panel_num($ozet['calls'])) ?></div>
      <div class="etiket">Son 30 günde çağrı</div>
    </div>
    <div class="sayac">
      <div class="deger"><?= esc(ai_panel_num($ozet['tokens_in'] + $ozet['tokens_out'])) ?></div>
      <div class="etiket">Toplam jeton (giriş+çıkış)</div>
    </div>
    <div class="sayac">
      <div class="deger"><?= esc(ai_panel_num($ozet['errors'])) ?></div>
      <div class="etiket">Hatalı çağrı · ort. <?= esc(ai_panel_num($ozet['avg_ms'])) ?> ms</div>
    </div>
  </div>

  <div class="iki-kolon">
    <div>
      <?php if (!$canConfigure): ?>
        <div class="kart">
          <div class="kart-baslik">Sağlayıcı ayarları</div>
          <div class="uyari bilgi">Yapay zekâ ayarlarını yalnız yönetici değiştirebilir.</div>
          <p class="kucuk soluk">Yeniden yazım, başlık ve spot önerisi gibi özellikleri kullanabilirsiniz;
            sağlayıcı seçimi ve API anahtarı yönetici hesabına açıktır.</p>
        </div>
      <?php else: ?>
        <form class="kart" id="aiAyarForm" autocomplete="off">
          <div class="kart-baslik">Sağlayıcı ve anahtar</div>

          <div class="form-satir">
            <div class="form-alan">
              <label for="ai_provider">Sağlayıcı</label>
              <?php
                // Liste KATALOGDAN uretilir (inc/ai.php > ai_providers()).
                // Elle yazilan bir liste, katalogda tanimli bir saglayiciyi
                // secilemez birakirdi.
                $saglayiciSecenek = [];
                foreach (ai_providers() as $sk => $sd) { $saglayiciSecenek[$sk] = (string)$sd['ad']; }
              ?>
              <select id="ai_provider" name="provider">
                <?= admin_options($saglayiciSecenek, ai_provider()) ?>
              </select>
              <?php $sagDef = ai_provider_def(); ?>
              <span class="form-yardim" id="saglayiciNot"><?= esc((string)arr($sagDef, 'not', '')) ?></span>
              <?php
                // Her saglayicinin varsayilanlarini JS'e ver: secim degisince
                // model ve taban adres yer tutuculari da degissin.
                $sagVeri = [];
                foreach (ai_providers() as $sk => $sd) {
                    $sagVeri[$sk] = [
                        'base'       => (string)$sd['base'],
                        'model'      => (string)$sd['model'],
                        'not'        => (string)arr($sd, 'not', ''),
                        'serbest'    => !empty($sd['serbest']),
                        'anahtarsiz' => !empty($sd['anahtarsiz']),
                        'belge'      => (string)arr($sd, 'belge', ''),
                    ];
                }
              ?>
              <script>window.MANSET_AI_SAGLAYICI = <?= json_encode($sagVeri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;</script>
            </div>
            <div class="form-alan">
              <label for="ai_model">Model</label>
              <input type="text" id="ai_model" name="model" value="<?= esc(setting('ai_model', '')) ?>"
                     maxlength="120" placeholder="<?= esc(ai_model()) ?>">
              <span class="form-yardim">Boş bırakılırsa sağlayıcının varsayılanı kullanılır.</span>
            </div>
          </div>

          <div class="form-alan" id="satirBaseUrl">
            <label for="ai_base_url">Taban adres (base URL)</label>
            <input type="url" id="ai_base_url" name="base_url" value="<?= esc(setting('ai_base_url', '')) ?>"
                   maxlength="255" placeholder="<?= esc((string)arr($sagDef, 'base', '')) ?>">
            <span class="form-yardim">
              Boş bırakırsanız seçtiğiniz sağlayıcının varsayılan adresi kullanılır.
              Vekil sunucu ya da kendi uç noktanız varsa buraya yazın;
              <code>/chat/completions</code> bu adresin sonuna eklenir.
            </span>
          </div>

          <div class="form-alan">
            <label for="ai_api_key">API anahtarı</label>
            <input type="password" id="ai_api_key" name="api_key" value="" maxlength="300" autocomplete="new-password"
                   placeholder="<?= esc(ai_api_key_masked() !== '' ? ai_api_key_masked() : 'Anahtar girilmedi') ?>">
            <span class="form-yardim">Boş bırakırsanız kayıtlı anahtar korunur. Anahtar panelde yalnız maskeli görünür.</span>
            <label class="onay-satir">
              <input type="checkbox" name="clear_key" value="1" id="ai_clear_key">
              <span>Kayıtlı anahtarı sil</span>
            </label>
          </div>

          <div class="form-satir">
            <div class="form-alan">
              <label for="ai_timeout">Zaman aşımı (sn)</label>
              <input type="number" id="ai_timeout" name="timeout" min="5" max="180"
                     value="<?= esc((string)ai_timeout()) ?>">
            </div>
            <div class="form-alan">
              <label for="ai_max_concurrent">Eşzamanlı iş sayısı</label>
              <input type="number" id="ai_max_concurrent" name="max_concurrent" min="1" max="8"
                     value="<?= esc((string)ai_max_concurrent()) ?>">
              <span class="form-yardim">Cron turunda aynı anda çalışacak en çok iş.</span>
            </div>
          </div>

          <div class="form-alan">
            <label class="anahtar">
              <input type="checkbox" name="enabled" value="1" <?= setting('ai_enabled', '0') === '1' ? 'checked' : '' ?>>
              <span class="kaydirak"></span>
              <span>Yapay zekâyı etkinleştir</span>
            </label>
            <?php if (ai_test_mode()): ?>
              <span class="form-yardim">Şu anda <strong>test modu</strong> açık (<code>ai_test_mode=1</code>);
                gerçek API çağrısı yapılmaz, sabit örnek yanıt üretilir.</span>
            <?php endif; ?>
          </div>

          <div class="dugme-grup">
            <button type="submit" class="dugme birincil">Kaydet</button>
            <button type="button" class="dugme" id="aiTestBtn2">Bağlantıyı test et</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div>
      <div class="kart">
        <div class="kart-baslik">Etkin yapılandırma</div>
        <table class="tablo" style="min-width:0">
          <tr><th>Durum</th><td><?= esc(ai_status_text()) ?></td></tr>
          <tr><th>Sağlayıcı</th><td><?= esc(ai_provider()) ?></td></tr>
          <tr><th>Model</th><td><?= esc(ai_model()) ?></td></tr>
          <tr><th>Taban adres</th><td class="tek-satir"><?= esc(ai_base_url()) ?></td></tr>
          <tr><th>API anahtarı</th><td><?= esc(ai_api_key_masked() !== '' ? ai_api_key_masked() : 'girilmedi') ?></td></tr>
          <tr><th>Zaman aşımı</th><td><?= esc((string)ai_timeout()) ?> sn</td></tr>
          <tr><th>Eşzamanlı iş</th><td><?= esc((string)ai_max_concurrent()) ?></td></tr>
          <tr><th>Kuyruk</th><td><?= esc(ai_panel_num($stats['queued'])) ?> bekliyor · <?= esc(ai_panel_num($stats['failed'])) ?> hata<?= (int)arr($stats, 'deferred', 0) > 0 ? ' · ' . esc(ai_panel_num($stats['deferred'])) . ' geri çekilmede' : '' ?></td></tr>
          <tr><th>Aylık bütçe</th>
            <td>
              <?php if (!empty($butce['aktif'])): ?>
                <?= esc(ai_panel_tutar($butce['harcanan'])) ?> / <?= esc(ai_panel_tutar($butce['tavan'])) ?>
                (%<?= (int)$butce['oran'] ?>)
              <?php elseif ((float)$butce['tavan'] > 0): ?>
                <?= esc(ai_panel_tutar($butce['tavan'])) ?> — birim fiyat girilmediği için ölçülemiyor
              <?php else: ?>
                sınırsız
              <?php endif; ?>
            </td></tr>
          <tr><th>Aylık çağrı tavanı</th>
            <td>
              <?php if ((int)$butce['cagri_tavan'] > 0): ?>
                <?= esc(ai_panel_num($butce['cagri_sayisi'])) ?> / <?= esc(ai_panel_num($butce['cagri_tavan'])) ?>
                (%<?= (int)$butce['cagri_oran'] ?>)
              <?php else: ?>
                sınırsız
              <?php endif; ?>
            </td></tr>
        </table>
      </div>

      <div class="uyari bilgi">
        Her yeniden yazım yaklaşık 1.500–3.000 jeton tüketir. Sağlayıcınızın fiyatlandırmasına göre
        maliyet oluşur; günlük iş sayısını RSS kaynaklarınızın otomatik yayın ayarıyla sınırlayabilirsiniz.
      </div>

      <div class="kart sikisik">
        <div class="kart-baslik">Güvenlik</div>
        <p class="kucuk soluk">Dış kaynaklı metin modele yalnız <strong>veri</strong> olarak gönderilir;
          sistem talimatı ayrı kanaldan iletilir. Üretilen gövde HTML'i beyaz listeye göre temizlenir,
          gömme (iframe) izni verilmez ve her habere kaynak künyesi eklenir.</p>
      </div>
    </div>
  </div>

<?php elseif ($tab === 'istem'): // ============================== B) İstem şablonları ?>

  <?php if (!$canConfigure): ?>
    <div class="kart">
      <div class="kart-baslik">İstem şablonları</div>
      <div class="uyari bilgi">Yapay zekâ ayarlarını yalnız yönetici değiştirebilir.</div>
    </div>
  <?php else: ?>
    <div class="uyari bilgi">
      Dış kaynaklı metin şablona yalnız veri olarak girer; sistem talimatı ayrı kanaldan gönderilir.
    </div>

    <form id="aiIstemForm">
      <?php foreach ($promptLabels as $key => $label):
        $val = setting($key, '');
        if (trim($val) === '') { $val = (string)arr(schema_default_settings(), $key, ''); } ?>
        <div class="kart">
          <div class="kart-baslik esnek-arasi">
            <span><?= esc($label) ?></span>
            <button type="button" class="dugme kucuk" data-varsayilan="<?= esc($key) ?>"
                    data-onay="Bu şablon varsayılan hâline döndürülecek. Onaylıyor musunuz?">Varsayılana dön</button>
          </div>
          <div class="form-alan">
            <textarea class="kod-alan" id="f_<?= esc($key) ?>" name="<?= esc($key) ?>" rows="8"
                      aria-label="<?= esc($label) ?> istem şablonu"><?= esc($val) ?></textarea>
            <span class="form-yardim">Kullanılabilir yer tutucular:
              <code>{baslik}</code> <code>{icerik}</code> <code>{kategori}</code> <code>{kaynak}</code></span>
          </div>
        </div>
      <?php endforeach; ?>
      <button type="submit" class="dugme birincil">Şablonları kaydet</button>
    </form>
  <?php endif; ?>

<?php elseif ($tab === 'kuyruk'): // ============================== C) İş kuyruğu
  $durum = (string)inp('durum', '');
  if (!in_array($durum, ai_job_statuses(), true)) { $durum = ''; }
  $sayfa = max(1, inp_i('sayfa', 1));
  $perPage = 30;
  $filters = $durum !== '' ? ['status' => $durum] : [];
  $toplam = ai_jobs_count($filters);
  $isler = ai_jobs_list($filters, $perPage, ($sayfa - 1) * $perPage);
  ?>

  <div class="izgara-4 bosluk-alt">
    <div class="sayac"><div class="deger"><?= esc(ai_panel_num($stats['queued'])) ?></div><div class="etiket">Kuyrukta<?= (int)arr($stats, 'deferred', 0) > 0 ? ' (' . esc(ai_panel_num($stats['deferred'])) . ' geri çekilmede)' : '' ?></div></div>
    <div class="sayac"><div class="deger"><?= esc(ai_panel_num($stats['running'])) ?></div><div class="etiket">Çalışıyor</div></div>
    <div class="sayac"><div class="deger"><?= esc(ai_panel_num($stats['done'])) ?></div><div class="etiket">Tamamlandı</div></div>
    <div class="sayac<?= $stats['failed'] > 0 ? ' vurgulu' : '' ?>"><div class="deger"><?= esc(ai_panel_num($stats['failed'])) ?></div><div class="etiket">Hata</div></div>
  </div>

  <?php if ((int)arr($stats, 'deferred', 0) > 0): ?>
    <div class="uyari bilgi mini">
      <?= esc(ai_panel_num($stats['deferred'])) ?> iş geri çekilmede: sağlayıcı geçici bir hata verdiği için
      üstel aralıklarla (1, 2, 4, 8… dakika) yeniden denenecekler. İşler kaybolmadı.
    </div>
  <?php endif; ?>

  <?php if (setting('cron_last_run', '') === ''): ?>
    <div class="uyari warn">Zamanlanmış görev (cron) hiç <strong>tam</strong> tur çalıştırmamış. Kuyruktaki işler
      kendiliğinden işlenmez — <a href="<?= esc(admin_url('settings')) ?>">Ayarlar</a> ekranındaki cron komutunu
      hosting panelinize ekleyin. Bu ekrandaki “Şimdi çalıştır” düğmesiyle işleri elle de başlatabilirsiniz.
      <?php if ($webTick !== null && $webTick['son_deneme'] !== ''): ?>
        <br>Son deneme: <?= esc(tr_date($webTick['son_deneme'])) ?> — tur tamamlanamadı
        (süre bütçesi yetersiz ya da görevler çalıştırılamadı).
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php /* B03/B07 görünürlüğü: "poor man's cron" açıkken gerçekte ne çalışıyor? */ ?>
  <?php if ($webTick !== null && !empty($webTick['acik'])): ?>
    <div class="uyari <?= $webTick['mod'] === 'ucuz' ? 'warn' : 'bilgi' ?>">
      <strong>Panel tetiklemesi açık.</strong> <?= esc($webTick['uyari']) ?>
      <?php if ($webTick['mod'] === 'tam'): ?>
        <br>Görev başına <?= (int)$webTick['butce'] ?> sn, tur başına en çok <?= (int)$webTick['toplam'] ?> sn.
        Tetikleme yalnız <em>tools.manage</em> yetkisi olan kullanıcıların panel isteğinde çalışır.
      <?php endif; ?>
      <?php if ($webTick['son_eksik'] !== '' && $webTick['son_eksik'] > $webTick['son_tam_tur']): ?>
        <br>Son tur <strong>eksik</strong> tamamlandı (<?= esc(tr_date($webTick['son_eksik'])) ?>):
        bazı görevler süre bütçesi yüzünden hiç çalışamadı. Bu yüzden “son cron” damgası tazelenmedi.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form class="arac-cubugu" method="get" action="<?= esc(base_url()) ?>/admin/">
    <input type="hidden" name="p" value="ai">
    <input type="hidden" name="t" value="kuyruk">
    <label for="f_durum" class="form-etiket">Durum</label>
    <select id="f_durum" name="durum" onchange="this.form.submit()">
      <?= admin_options(['queued' => 'Kuyrukta', 'running' => 'Çalışıyor', 'done' => 'Tamamlandı', 'failed' => 'Hata'], $durum, 'Tümü') ?>
    </select>
    <noscript><button type="submit" class="dugme kucuk">Süz</button></noscript>
    <span class="sag kucuk soluk"><?= esc(ai_panel_num($toplam)) ?> kayıt</span>
  </form>

  <?php if (!$isler): ?>
    <div class="bos-durum">
      <span class="buyuk" aria-hidden="true">◆</span>
      <h3>Kuyrukta iş yok</h3>
      <p class="soluk">RSS kaynaklarında “yapay zekâ ile yeniden yaz” seçeneği açıkken çekilen ögeler burada listelenir.</p>
    </div>
  <?php else: ?>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr>
            <th class="dar">#</th>
            <th>Kaynak öge</th>
            <th class="dar">Tür</th>
            <th class="dar">Durum</th>
            <th class="dar">Deneme</th>
            <th class="dar">Süre</th>
            <th>Üretilen haber</th>
            <th>Hata</th>
            <th class="islem">İşlem</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($isler as $j):
          list($sLabel, $sTone) = ai_job_status_label((string)$j['status']);
          $err = (string)arr($j, 'error', '');
          $postId = (int)arr($j, 'result_post_id', 0); ?>
          <tr>
            <td class="dar"><?= (int)$j['id'] ?></td>
            <td>
              <span class="tek-satir" title="<?= esc((string)arr($j, 'item_title', '')) ?>">
                <?= esc(excerpt((string)arr($j, 'item_title', '') !== '' ? (string)$j['item_title'] : '(öge silinmiş)', 70)) ?>
              </span>
              <span class="satir-alt mini soluk"><?= esc((string)arr($j, 'source_name', '') !== '' ? (string)$j['source_name'] : 'kaynak yok') ?></span>
            </td>
            <td class="dar"><?= esc((string)$j['kind']) ?></td>
            <td class="dar"><?= admin_badge($sLabel, $sTone) ?></td>
            <td class="dar">
              <?php $bekleme = trim((string)arr($j, 'retry_after', '')); ?>
              <?= (int)$j['attempts'] ?>/<?= AI_JOB_MAX_RETRIES ?>
              <?php if ($bekleme !== '' && $bekleme > now() && (string)$j['status'] === 'queued'): ?>
                <span class="satir-alt mini soluk" title="Bir sonraki deneme: <?= esc($bekleme) ?>">geri çekilmede</span>
              <?php endif; ?>
            </td>
            <td class="dar"><?= esc(ai_job_duration(arr($j, 'started_at'), arr($j, 'finished_at'))) ?></td>
            <td>
              <?php if ($postId > 0 && (string)arr($j, 'post_title', '') !== ''): ?>
                <a href="<?= esc(admin_url('posts', ['duzenle' => $postId])) ?>" class="tek-satir"
                   title="<?= esc((string)$j['post_title']) ?>"><?= esc(excerpt((string)$j['post_title'], 50)) ?></a>
                <span class="satir-alt mini soluk"><?= esc((string)arr($j, 'post_status', '')) ?></span>
              <?php elseif ($postId > 0): ?>
                <span class="soluk mini">#<?= $postId ?> (silinmiş)</span>
              <?php else: ?>
                <span class="soluk mini">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($err !== ''): ?>
                <span class="tek-satir mini" title="<?= esc($err) ?>" style="max-width:220px;display:inline-block"><?= esc(excerpt($err, 60)) ?></span>
              <?php else: ?>
                <span class="soluk mini">—</span>
              <?php endif; ?>
            </td>
            <td class="islem">
              <div class="dugme-grup">
                <?php if ($j['status'] === 'queued' || $j['status'] === 'failed'): ?>
                  <button type="button" class="dugme kucuk" data-is-calistir="<?= (int)$j['id'] ?>">Şimdi çalıştır</button>
                <?php endif; ?>
                <?php if ($j['status'] === 'failed'): ?>
                  <button type="button" class="dugme kucuk" data-is-yeniden="<?= (int)$j['id'] ?>">Yeniden dene</button>
                <?php endif; ?>
                <button type="button" class="dugme kucuk tehlike" data-is-sil="<?= (int)$j['id'] ?>"
                        data-onay="Bu iş kaydı silinecek. Onaylıyor musunuz?">Sil</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= admin_paginate($toplam, $perPage, $sayfa, 'ai', ['t' => 'kuyruk'] + ($durum !== '' ? ['durum' => $durum] : [])) ?>
  <?php endif; ?>

<?php else: // ============================== D) Günlükler
  $loglar = ai_logs_list(50, 0);
  $toplamJeton = $ozet['tokens_in'] + $ozet['tokens_out'];
  $tutar = ai_estimated_cost($ozet['tokens_in'], $ozet['tokens_out']);
  ?>

  <div class="izgara-4 bosluk-alt">
    <div class="sayac"><div class="deger"><?= esc(ai_panel_num($ozet['calls'])) ?></div><div class="etiket">30 günde çağrı</div></div>
    <div class="sayac"><div class="deger"><?= esc(ai_panel_num($ozet['tokens_in'])) ?></div><div class="etiket">Giriş jetonu</div></div>
    <div class="sayac"><div class="deger"><?= esc(ai_panel_num($ozet['tokens_out'])) ?></div><div class="etiket">Çıkış jetonu</div></div>
    <div class="sayac<?= $tutar !== null ? ' vurgulu' : '' ?>">
      <div class="deger"><?= $tutar !== null ? esc(number_format($tutar, 2, ',', '.')) : esc(ai_panel_num($toplamJeton)) ?></div>
      <div class="etiket"><?= $tutar !== null ? 'Tahmini tutar (30 gün)' : 'Toplam jeton (birim fiyat girilmedi)' ?></div>
    </div>
  </div>

  <div class="kart">
    <div class="kart-baslik">Bu ayki harcama (<?= esc($butce['ay']) ?>)</div>
    <?php if (!empty($butce['olculebilir'])): ?>
      <p><strong style="font-size:20px"><?= esc(ai_panel_tutar($butce['harcanan'])) ?></strong>
        <?php if ((float)$butce['tavan'] > 0): ?>
          <span class="soluk">/ <?= esc(ai_panel_tutar($butce['tavan'])) ?> tavan</span>
          <?= admin_badge('%' . (int)$butce['oran'], !empty($butce['doldu']) ? 'olumsuz' : ((int)$butce['oran'] >= 80 ? 'uyari' : 'olumlu')) ?>
        <?php else: ?>
          <span class="soluk">— tavan yok (sınırsız)</span>
        <?php endif; ?>
      </p>
      <?php if ((float)$butce['tavan'] > 0): ?>
        <div class="cubuk" role="img"
             aria-label="Bütçenin yüzde <?= (int)$butce['oran'] ?>'i kullanıldı"
             style="height:8px;border-radius:4px;background:rgba(128,128,128,.2);overflow:hidden">
          <div style="height:8px;width:<?= (int)min(100, $butce['oran']) ?>%;background:currentColor;opacity:.55"></div>
        </div>
        <p class="mini soluk bosluk-ust">Kalan: <?= esc(ai_panel_tutar($butce['kalan'])) ?>.
          Tavan dolduğunda yeni çağrı yapılmaz; kuyruk durur, işler kuyrukta bekler.</p>
      <?php endif; ?>
    <?php else: ?>
      <p class="kucuk soluk">Harcama izlenemiyor: aşağıdaki birim fiyatlar girilmeden tutar hesaplanamaz,
        bu yüzden <strong>tutar cinsinden</strong> aylık bütçe tavanı uygulanmaz.
        Bu kurulumda korumayı aylık <strong>çağrı</strong> tavanı sağlar.</p>
    <?php endif; ?>

    <?php /* B08: çağrı tavanı her kurulumda ölçülebilir; fiyat gerektirmez. */ ?>
    <p class="kucuk">Bu ay <strong><?= esc(ai_panel_num($butce['cagri_sayisi'])) ?></strong> çağrı
      <?php if ((int)$butce['cagri_tavan'] > 0): ?>
        / <?= esc(ai_panel_num($butce['cagri_tavan'])) ?> tavan
        <?= admin_badge('%' . (int)$butce['cagri_oran'], !empty($butce['cagri_doldu']) ? 'olumsuz' : ((int)$butce['cagri_oran'] >= 80 ? 'uyari' : 'olumlu')) ?>
      <?php else: ?>
        <span class="soluk">— çağrı tavanı yok (sınırsız)</span>
      <?php endif; ?>
    </p>
  </div>

  <?php if ($canConfigure): ?>
    <form class="kart" id="aiFiyatForm">
      <div class="kart-baslik">Birim fiyat ve aylık bütçe</div>
      <p class="kucuk soluk">Sağlayıcınızın güncel fiyatını buraya girerseniz tahmini tutar hesaplanır.
        Boş bırakılırsa yalnız jeton sayısı gösterilir. Manşet fiyat bilgisi saklamaz.</p>
      <div class="form-satir">
        <div class="form-alan">
          <label for="ai_price_in">Giriş jetonu birim fiyatı</label>
          <input type="text" id="ai_price_in" name="price_in" inputmode="decimal"
                 value="<?= esc(setting('ai_price_in', '')) ?>" maxlength="20" placeholder="örn. 3">
        </div>
        <div class="form-alan">
          <label for="ai_price_out">Çıkış jetonu birim fiyatı</label>
          <input type="text" id="ai_price_out" name="price_out" inputmode="decimal"
                 value="<?= esc(setting('ai_price_out', '')) ?>" maxlength="20" placeholder="örn. 15">
        </div>
      </div>
      <div class="form-alan">
        <label for="ai_monthly_budget">Aylık bütçe tavanı</label>
        <input type="text" id="ai_monthly_budget" name="monthly_budget" inputmode="decimal"
               value="<?= esc(setting('ai_monthly_budget', '0')) ?>" maxlength="20" placeholder="0 = sınırsız">
        <span class="form-yardim">Bu ayki toplam tutar tavanı aşarsa yeni yapay zekâ çağrısı yapılmaz:
          kuyruk durur, işler kaybolmaz. <strong>0</strong> yazarsanız sınır uygulanmaz.
          <strong>Dikkat:</strong> bu tavan yalnız yukarıdaki birim fiyatlardan en az biri girilmişse
          ölçülebilir; fiyat girilmemişse hiçbir çağrıyı engellemez.</span>
      </div>
      <div class="form-alan">
        <label for="ai_monthly_calls">Aylık çağrı tavanı</label>
        <input type="text" id="ai_monthly_calls" name="monthly_calls" inputmode="numeric"
               value="<?= esc(setting('ai_monthly_calls', (string)AI_MONTHLY_CALLS_DEFAULT)) ?>"
               maxlength="7" placeholder="0 = sınırsız">
        <span class="form-yardim">Birim fiyattan <strong>bağımsız</strong> ikinci tavan: bu ay yapılan
          yapay zekâ çağrısı sayısı bu değere ulaşırsa yeni çağrı yapılmaz. Fiyat girilmemiş kurulumlarda
          harcamayı sınırlayan tek koruma budur, bu yüzden varsayılanı
          <?= esc(ai_panel_num(AI_MONTHLY_CALLS_DEFAULT)) ?>'dir. <strong>0</strong> yazarsanız sınır uygulanmaz.</span>
      </div>
      <button type="submit" class="dugme birincil">Kaydet</button>
    </form>
  <?php endif; ?>

  <?php if (!$loglar): ?>
    <div class="bos-durum">
      <span class="buyuk" aria-hidden="true">☰</span>
      <h3>Henüz günlük kaydı yok</h3>
      <p class="soluk">Yapay zekâya yapılan her çağrı burada listelenir.</p>
    </div>
  <?php else: ?>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr>
            <th class="dar">Tarih</th>
            <th class="dar">Eylem</th>
            <th>Model</th>
            <th class="dar">Giriş</th>
            <th class="dar">Çıkış</th>
            <th class="dar">Süre</th>
            <th class="dar">Sonuç</th>
            <th>Hata</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($loglar as $l): $lerr = (string)arr($l, 'error', ''); ?>
          <tr>
            <td class="dar mini"><?= esc(tr_date((string)arr($l, 'created_at', ''))) ?></td>
            <td class="dar"><?= esc((string)arr($l, 'action', '')) ?></td>
            <td class="tek-satir"><?= esc((string)arr($l, 'model', '')) ?></td>
            <td class="dar"><?= esc(ai_panel_num(arr($l, 'tokens_in', 0))) ?></td>
            <td class="dar"><?= esc(ai_panel_num(arr($l, 'tokens_out', 0))) ?></td>
            <td class="dar"><?= esc(ai_panel_num(arr($l, 'duration_ms', 0))) ?> ms</td>
            <td class="dar"><?= (int)arr($l, 'ok', 0) === 1 ? admin_badge('tamam', 'olumlu') : admin_badge('hata', 'olumsuz') ?></td>
            <td>
              <?php if ($lerr !== ''): ?>
                <span class="tek-satir mini" title="<?= esc($lerr) ?>" style="max-width:260px;display:inline-block"><?= esc(excerpt($lerr, 70)) ?></span>
              <?php else: ?>
                <span class="soluk mini">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="mini soluk bosluk-ust">Toplam <?= esc(ai_panel_num(ai_logs_count())) ?> kayıt; en yeni 50 tanesi gösteriliyor.
      Kayıtlar 60 günden sonra cron bakım turunda silinir.</p>
  <?php endif; ?>

<?php endif; ?>

<script>
(function () {
  'use strict';

  /* ---------------------------------------------- bağlantı testi */
  function baglantiTest(dugme) {
    var eskiMetin = dugme.textContent;
    dugme.disabled = true;
    dugme.textContent = 'Deneniyor…';
    M.api('ai.test', {}).then(function (r) {
      dugme.disabled = false;
      dugme.textContent = eskiMetin;
      var govde = '<p>' + (r.ok ? 'Bağlantı başarılı.' : 'Bağlantı kurulamadı.') + '</p>';
      if (r.ms) { govde += '<p class="kucuk soluk">Süre: ' + M.esc(String(r.ms)) + ' ms</p>'; }
      if (r.text) { govde += '<p class="kucuk">Yanıt: <code>' + M.esc(r.text) + '</code></p>'; }
      if (r.error) { govde += '<div class="uyari err">' + M.esc(r.error) + '</div>'; }
      M.modal.ac('Bağlantı testi', govde, '520px');
    });
  }
  M.qsa('#aiTestBtn, #aiTestBtn2').forEach(function (b) {
    b.addEventListener('click', function () { baglantiTest(b); });
  });

  /* ---------------------------------------------- sağlayıcı ayarları */
  var ayarForm = M.qs('#aiAyarForm');
  if (ayarForm) {
    var saglayici = M.qs('#ai_provider', ayarForm);
    var modelAlan = M.qs('#ai_model', ayarForm);
    var baseAlan  = M.qs('#ai_base_url', ayarForm);
    var notAlan   = M.qs('#saglayiciNot', ayarForm);
    var anahtarAlan = M.qs('#ai_api_key', ayarForm);
    var veri = window.MANSET_AI_SAGLAYICI || {};

    /* Sağlayıcı değişince YER TUTUCULAR güncellenir — girilen değerler DEĞİL.
       Yayıncının yazdığı bir modeli ya da vekil adresini seçim değiştirdi diye
       silmek, sessiz veri kaybı olurdu. Yer tutucu "boş bırakırsan bu kullanılır"
       demektir; alan doluysa ona dokunulmaz. */
    function saglayiciUygula() {
      var d = veri[saglayici.value];
      if (!d) { return; }
      if (modelAlan) { modelAlan.placeholder = d.model || ''; }
      if (baseAlan)  { baseAlan.placeholder  = d.base  || ''; }
      if (notAlan) {
        notAlan.textContent = d.not || '';
        if (d.belge) {
          var a = document.createElement('a');
          a.href = d.belge; a.target = '_blank'; a.rel = 'noopener';
          a.textContent = ' Belgeler ↗';
          notAlan.appendChild(a);
        }
      }
      /* Ollama gibi yerel uçlar anahtar istemez. */
      if (anahtarAlan) {
        anahtarAlan.placeholder = d.anahtarsiz ? 'bu sağlayıcı anahtar istemiyor' : '';
      }
    }
    saglayici.addEventListener('change', saglayiciUygula);
    saglayiciUygula();

    ayarForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = M.formVerisi(ayarForm);
      M.api('ai.save_settings', d).then(function (r) {
        if (M.sonuc(r, 'Ayarlar kaydedildi.')) { setTimeout(function () { location.reload(); }, 700); }
      });
    });
  }

  /* ---------------------------------------------- istem şablonları */
  var istemForm = M.qs('#aiIstemForm');
  if (istemForm) {
    istemForm.addEventListener('submit', function (e) {
      e.preventDefault();
      M.api('ai.save_prompts', M.formVerisi(istemForm)).then(function (r) {
        M.sonuc(r, 'Şablonlar kaydedildi.');
      });
    });
    M.qsa('[data-varsayilan]', istemForm).forEach(function (b) {
      b.addEventListener('click', function () {
        var anahtar = b.getAttribute('data-varsayilan');
        M.api('ai.reset_prompt', { key: anahtar }).then(function (r) {
          if (!M.sonuc(r, 'Şablon varsayılana döndürüldü.')) { return; }
          var alan = document.getElementById('f_' + anahtar);
          if (alan) { alan.value = r.value || ''; }
        });
      });
    });
  }

  /* ---------------------------------------------- kuyruk işlemleri */
  function isEylemi(dugme, uc, basari) {
    var id = parseInt(dugme.getAttribute('data-is-calistir')
      || dugme.getAttribute('data-is-yeniden')
      || dugme.getAttribute('data-is-sil'), 10);
    dugme.disabled = true;
    M.api(uc, { id: id }).then(function (r) {
      if (M.sonuc(r, basari)) { setTimeout(function () { location.reload(); }, 800); }
      else { dugme.disabled = false; }
    });
  }
  M.qsa('[data-is-calistir]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.textContent = 'Çalışıyor…';
      isEylemi(b, 'ai.run_now', 'İş tamamlandı.');
    });
  });
  M.qsa('[data-is-yeniden]').forEach(function (b) {
    b.addEventListener('click', function () { isEylemi(b, 'ai.retry', 'İş yeniden kuyruğa alındı.'); });
  });
  M.qsa('[data-is-sil]').forEach(function (b) {
    b.addEventListener('click', function () { isEylemi(b, 'ai.delete_job', 'İş silindi.'); });
  });

  /* ---------------------------------------------- birim fiyatlar */
  var fiyatForm = M.qs('#aiFiyatForm');
  if (fiyatForm) {
    fiyatForm.addEventListener('submit', function (e) {
      e.preventDefault();
      M.api('ai.save_settings', M.formVerisi(fiyatForm)).then(function (r) {
        if (M.sonuc(r, 'Birim fiyat ve bütçe kaydedildi.')) { setTimeout(function () { location.reload(); }, 700); }
      });
    });
  }
})();
</script>
