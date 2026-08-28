<?php
/**
 * Panel — genel ayarlar.
 * Sahip: orkestratör. Künye alanları admin/pages/kunye.php (Ajan-9) içindedir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('settings.manage');

$adminPageTitle = 'Ayarlar';

/** Bu ekranda yönetilen ayar anahtarları: anahtar => ['tip', 'etiket', 'yardım'] */
function settings_fields() {
    return [
        'site' => [
            'site_title'   => ['text', 'Site adı', ''],
            'site_slogan'  => ['text', 'Slogan', 'Logonun altında görünür.'],
            'site_desc'    => ['textarea', 'Site açıklaması', 'Arama motorlarında ve RSS beslemesinde kullanılır.'],
            'site_email'   => ['text', 'İletişim e-postası', ''],
            'site_logo'    => ['image', 'Logo', 'Şeffaf PNG veya SVG önerilir (SVG yüklemesi kapalıdır, PNG kullanın).'],
            'site_favicon' => ['image', 'Favicon', '32×32 PNG.'],
        ],
        'okuma' => [
            'posts_per_page' => ['number', 'Sayfa başına haber', '3–50 arası.'],
            'headline_limit' => ['number', 'Anasayfa manşet sayısı', 'En çok 12.'],
            'use_rewrite'    => ['bool', 'Temiz (SEF) adresler', 'Kapalıysa adresler ?r=… biçiminde üretilir. Sunucunuzda mod_rewrite yoksa kapalı bırakın.'],
        ],
        'yorum' => [
            'comments_enabled'   => ['bool', 'Yorumlar açık', ''],
            'comments_moderate'  => ['bool', 'Yorumlar onaydan geçsin', 'Kapatılırsa yorumlar anında yayımlanır.'],
            'comments_anonymous' => ['bool', 'Üye olmayanlar yorum yazabilsin', ''],
        ],
        'yasal' => [
            'corrections_enabled' => ['bool', 'Düzeltme talebi formu açık', 'Haber altında “Düzeltme talep et” bölümü görünür.'],
            'cookie_notice'       => ['bool', 'Çerez bildirimi çubuğu', ''],
            'cookie_notice_text'  => ['textarea', 'Çerez bildirimi metni', ''],
        ],
        'performans' => [
            'cache_enabled'  => ['bool', 'Sayfa önbelleği', 'Oturum açmamış ziyaretçilere hazır sayfa sunulur.'],
            'cache_ttl_home' => ['number', 'Anasayfa/kategori önbellek süresi (sn)', ''],
            'cache_ttl_post' => ['number', 'Haber sayfası önbellek süresi (sn)', ''],
        ],
    ];
}

// ---------------------------------------------------------------- kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'save') {
    csrf_guard();
    $saved = [];
    foreach (settings_fields() as $group) {
        foreach ($group as $key => $def) {
            list($type, $label,) = $def;
            switch ($type) {
                case 'bool':
                    $saved[$key] = inp($key) ? '1' : '0';
                    break;
                case 'number':
                    $saved[$key] = (string)max(0, (int)inp_i($key, 0));
                    break;
                case 'textarea':
                    $saved[$key] = sanitize_plain((string)inp($key, ''), 2000);
                    break;
                case 'image':
                    $v = sanitize_line((string)inp($key, ''), 255);
                    // Yalnız uploads içindeki dosya adı kabul edilir
                    $saved[$key] = preg_match('#^[A-Za-z0-9._\-/]{0,255}$#', $v) && strpos($v, '..') === false ? $v : '';
                    break;
                default:
                    $saved[$key] = sanitize_line((string)inp($key, ''), 255);
            }
        }
    }
    // Sınır düzeltmeleri
    $saved['posts_per_page'] = (string)max(3, min(50, (int)$saved['posts_per_page']));
    $saved['headline_limit'] = (string)max(1, min(12, (int)$saved['headline_limit']));
    $saved['cache_ttl_home'] = (string)max(0, min(3600, (int)$saved['cache_ttl_home']));
    $saved['cache_ttl_post'] = (string)max(0, min(86400, (int)$saved['cache_ttl_post']));

    setting_set_many($saved);
    if (function_exists('cache_flush')) { cache_flush(); }
    flash('ok', 'Ayarlar kaydedildi.');
    header('Location: ' . admin_url('settings'), true, 302);
    exit;
}

// ---------------------------------------------------------------- SEF yeniden sınama
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'probe') {
    csrf_guard();
    $url = base_url() . '/probe-rewrite';
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false]);
        $body = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]));
    }
    if (is_string($body) && strpos($body, 'MANSET_REWRITE_OK') !== false) {
        setting_set('use_rewrite', '1');
        flash('ok', 'URL yeniden yazma çalışıyor. Temiz adresler açıldı.');
    } else {
        flash('warn', 'Sonda yanıt vermedi. Sunucu tek işçiyle çalışıyorsa bu sonuç yanıltıcı olabilir; '
            . 'temiz bir adresi (örn. ' . esc(base_url()) . '/kategori/gundem) tarayıcıda açmayı deneyin — çalışıyorsa ayar kendiliğinden açılır.');
    }
    header('Location: ' . admin_url('settings'), true, 302);
    exit;
}

$cronKey = (string)cfg('cron_key', '');
?>

<div class="sayfa-basligi">
  <h1>Ayarlar</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('kunye')) ?>">Künye / BİK →</a>
    <button type="submit" form="ayarForm" class="dugme birincil">Kaydet</button>
  </div>
</div>

<form method="post" id="ayarForm" class="iki-kolon">
  <div>
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">

    <?php
    $groupTitles = [
      'site' => 'Site kimliği', 'okuma' => 'Okuma ve adresler', 'yorum' => 'Yorumlar',
      'yasal' => 'Yasal ve bildirimler', 'performans' => 'Performans',
    ];
    foreach (settings_fields() as $groupKey => $fields): ?>
      <div class="kart">
        <div class="kart-baslik"><?= esc($groupTitles[$groupKey]) ?></div>
        <?php foreach ($fields as $key => $def):
          list($type, $label, $help) = $def;
          $val = setting($key, ''); ?>
          <?php if ($type === 'bool'): ?>
            <div class="form-alan">
              <label class="anahtar">
                <input type="checkbox" name="<?= esc($key) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>>
                <span class="kaydirak"></span>
                <span><?= esc($label) ?></span>
              </label>
              <?php if ($help): ?><span class="form-yardim"><?= esc($help) ?></span><?php endif; ?>
            </div>
          <?php elseif ($type === 'textarea'): ?>
            <div class="form-alan">
              <label for="f_<?= esc($key) ?>"><?= esc($label) ?></label>
              <textarea id="f_<?= esc($key) ?>" name="<?= esc($key) ?>" rows="3"><?= esc($val) ?></textarea>
              <?php if ($help): ?><span class="form-yardim"><?= esc($help) ?></span><?php endif; ?>
            </div>
          <?php elseif ($type === 'image'): ?>
            <div class="form-alan">
              <label for="f_<?= esc($key) ?>"><?= esc($label) ?></label>
              <div class="esnek">
                <input type="text" id="f_<?= esc($key) ?>" name="<?= esc($key) ?>" value="<?= esc($val) ?>" readonly style="flex:1">
                <button type="button" class="dugme kucuk" data-medya-sec="f_<?= esc($key) ?>">Seç</button>
                <button type="button" class="dugme kucuk" data-medya-temizle="f_<?= esc($key) ?>">Kaldır</button>
              </div>
              <?php if ($val): ?>
                <div class="gorsel-onizleme bosluk-ust"><img src="<?= esc(url_upload($val)) ?>" alt=""></div>
              <?php endif; ?>
              <?php if ($help): ?><span class="form-yardim"><?= esc($help) ?></span><?php endif; ?>
            </div>
          <?php else: ?>
            <div class="form-alan">
              <label for="f_<?= esc($key) ?>"><?= esc($label) ?></label>
              <input type="<?= $type === 'number' ? 'number' : 'text' ?>" id="f_<?= esc($key) ?>"
                     name="<?= esc($key) ?>" value="<?= esc($val) ?>" maxlength="255">
              <?php if ($help): ?><span class="form-yardim"><?= esc($help) ?></span><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <button type="submit" class="dugme birincil">Kaydet</button>
  </div>

  <div>
    <div class="kart">
      <div class="kart-baslik">Sistem</div>
      <table class="tablo" style="min-width:0">
        <tr><th>Manşet sürümü</th><td><?= esc(MANSET_VERSION) ?></td></tr>
        <tr><th>PHP</th><td><?= esc(PHP_VERSION) ?></td></tr>
        <tr><th>Veritabanı</th><td><?= esc(db_driver() === 'mysql' ? 'MySQL' : 'SQLite') ?></td></tr>
        <tr><th>GD</th><td><?= function_exists('imagecreatetruecolor') ? 'var' : 'yok' ?></td></tr>
        <tr><th>mbstring</th><td><?= extension_loaded('mbstring') ? 'var' : 'yok (yedek kullanılıyor)' ?></td></tr>
        <tr><th>Temiz adres</th><td><?= sef_enabled() ? admin_badge('açık', 'olumlu') : admin_badge('kapalı', 'uyari') ?></td></tr>
        <tr><th>Son cron</th><td><?= esc(setting('cron_last_run', '') ? tr_date(setting('cron_last_run')) : 'hiç çalışmadı') ?></td></tr>
      </table>
    </div>

    <div class="kart">
      <div class="kart-baslik">Temiz adres sondası</div>
      <p class="kucuk soluk">Sunucuda <code>mod_rewrite</code> etkinleştirildiyse burayı kullanarak temiz adresleri açabilirsiniz.</p>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="probe">
        <button type="submit" class="dugme">Yeniden sına</button>
      </form>
    </div>

    <div class="kart">
      <div class="kart-baslik">Zamanlanmış görev</div>
      <p class="kucuk soluk">RSS çekimi, yapay zekâ kuyruğu ve önbellek bakımı için 10 dakikada bir çalıştırın:</p>
      <?php if ($cronKey !== ''): ?>
        <code style="display:block;padding:9px;word-break:break-all;white-space:pre-wrap">*/10 * * * * curl -s "<?= esc(base_url()) ?>/cron.php?key=<?= esc($cronKey) ?>"</code>
        <p class="mini soluk bosluk-ust">Anahtar <code>config.php</code> içindeki <code>cron_key</code> değeridir.</p>
      <?php else: ?>
        <div class="uyari warn">config.php içinde <code>cron_key</code> tanımlı değil.</div>
      <?php endif; ?>
      <?php if (setting('cron_last_report', '')): ?>
        <details class="bosluk-ust"><summary class="kucuk">Son tur raporu</summary>
          <pre class="mini" style="white-space:pre-wrap;margin:8px 0 0"><?= esc(setting('cron_last_report')) ?></pre>
        </details>
      <?php endif; ?>
    </div>
  </div>
</form>

<script>
// Medya seçici bağlantıları (assets/admin.js içindeki ortak seçiciyi kullanır)
M.qsa('[data-medya-sec]').forEach(function (b) {
  b.addEventListener('click', function () {
    var hedef = document.getElementById(b.getAttribute('data-medya-sec'));
    M.medyaSec(function (o) { hedef.value = o.filename; });
  });
});
M.qsa('[data-medya-temizle]').forEach(function (b) {
  b.addEventListener('click', function () {
    document.getElementById(b.getAttribute('data-medya-temizle')).value = '';
  });
});
</script>
