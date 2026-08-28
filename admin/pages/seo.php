<?php
/**
 * Panel — SEO (Ajan-E / 1.2-06, 1.2-07).
 *
 * Dört bölüm:
 *   yonlendirme  Elle 301/302/410 kuralları (slug geçmişinin üstüne)
 *   kategori     Kategori başına SEO başlığı / açıklaması
 *   etiket       Etiket başına SEO başlığı / açıklaması
 *   bildirim     IndexNow / sitemap ping / WebSub kuyruk durumu
 *
 * Kategori ve etiket üstverisi NEDEN BURADA: `admin/pages/categories.php`
 * Ajan-2'nin dosyası, etiketlerin ise kendi ekranı hiç yok. SEO alanlarının
 * tek bir yerde toplanması yayıncı için de daha anlaşılır.
 *
 * Genel SEO ayarları (başlık şablonları, robots.txt, beslemeler, IndexNow
 * anahtarı) Ayarlar ekranındaki "SEO ve dağıtım" grubundadır —
 * admin/settings-groups/30-seo.php.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('seo.manage');

$adminPageTitle = 'SEO';

$bolum = (string)inp_s('bolum', 'yonlendirme');
if (!in_array($bolum, ['yonlendirme', 'kategori', 'etiket', 'bildirim'], true)) { $bolum = 'yonlendirme'; }

$seoHazir = function_exists('seo_redirects_ready') && seo_redirects_ready();

// ---------------------------------------------------------------- POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_guard();
    $do = (string)inp_s('do', '');

    if ($do === 'redirect_save') {
        $res = seo_redirect_save([
            'id'       => inp_i('id', 0),
            'src_path' => inp_s('src_path', ''),
            'target'   => inp_s('target', ''),
            'code'     => inp_i('code', 301),
            'active'   => inp('active') ? 1 : 0,
            'note'     => inp_s('note', ''),
        ]);
        if ($res['ok']) { flash('ok', 'Yönlendirme kaydedildi.'); }
        else { flash('err', $res['error']); }
        header('Location: ' . admin_url('seo', ['bolum' => 'yonlendirme']), true, 302);
        exit;
    }

    if ($do === 'redirect_delete') {
        seo_redirect_delete(inp_i('id', 0));
        flash('ok', 'Yönlendirme silindi.');
        header('Location: ' . admin_url('seo', ['bolum' => 'yonlendirme']), true, 302);
        exit;
    }

    if ($do === 'category_save') {
        $ids = (array)inp('cat_id', []);
        $sayi = 0;
        foreach ($ids as $cid) {
            $cid = (int)$cid;
            if ($cid <= 0) { continue; }
            $t = sanitize_line((string)inp('cat_title_' . $cid, ''), 255);
            $d = sanitize_line((string)inp('cat_desc_' . $cid, ''), 500);
            db_update('categories', ['seo_title' => $t, 'seo_desc' => $d], 'id = :i', [':i' => $cid]);
            $sayi++;
        }
        if (function_exists('cache_flush')) { cache_flush(); }
        flash('ok', $sayi . ' kategori güncellendi.');
        header('Location: ' . admin_url('seo', ['bolum' => 'kategori']), true, 302);
        exit;
    }

    if ($do === 'tag_save') {
        $etiket = (string)inp_s('tag', '');
        if (trim($etiket) === '') {
            flash('err', 'Etiket adı zorunlu.');
        } elseif (seo_tag_meta_save($etiket, inp_s('tag_title', ''), inp_s('tag_desc', ''))) {
            if (function_exists('cache_flush')) { cache_flush(); }
            flash('ok', 'Etiket üstverisi kaydedildi.');
        } else {
            flash('err', 'Etiket üstverisi kaydedilemedi.');
        }
        header('Location: ' . admin_url('seo', ['bolum' => 'etiket']), true, 302);
        exit;
    }

    if ($do === 'ping_run') {
        $ozet = function_exists('seo_ping_tick') ? (string)seo_ping_tick() : 'Modül yok.';
        flash('ok', 'Kuyruk işlendi: ' . $ozet);
        header('Location: ' . admin_url('seo', ['bolum' => 'bildirim']), true, 302);
        exit;
    }

    if ($do === 'slug_sync') {
        $n = function_exists('seo_sync_slug_history') ? (int)seo_sync_slug_history(500) : 0;
        flash('ok', $n . ' adres slug geçmişine eklendi.');
        header('Location: ' . admin_url('seo', ['bolum' => 'yonlendirme']), true, 302);
        exit;
    }
}

$duzenle = null;
if (inp_i('duzenle', 0) > 0 && $seoHazir) {
    $duzenle = q1('SELECT * FROM redirects WHERE id = :i', [':i' => inp_i('duzenle', 0)]);
}
?>

<div class="sayfa-basligi">
  <h1>SEO</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('settings')) ?>#grup-seo">Ayarlar → SEO ve dağıtım</a>
    <a class="dugme" href="<?= esc(base_url() . '/sitemap.php') ?>" target="_blank" rel="noopener">Site haritası ↗</a>
  </div>
</div>

<?php if (!$seoHazir): ?>
  <div class="uyari err">
    <strong>019 göçü uygulanmamış.</strong> Yönlendirme ve etiket tabloları yok.
    Panelden çıkıp yeniden girin ya da <code>php cron.php</code> çalıştırın; yükseltme
    yolu göçleri ilk yönetici girişinde uygular.
  </div>
<?php endif; ?>

<div class="sekmeler">
  <a href="<?= esc(admin_url('seo', ['bolum' => 'yonlendirme'])) ?>" class="<?= $bolum === 'yonlendirme' ? 'etkin' : '' ?>">Yönlendirmeler</a>
  <a href="<?= esc(admin_url('seo', ['bolum' => 'kategori'])) ?>" class="<?= $bolum === 'kategori' ? 'etkin' : '' ?>">Kategori SEO</a>
  <a href="<?= esc(admin_url('seo', ['bolum' => 'etiket'])) ?>" class="<?= $bolum === 'etiket' ? 'etkin' : '' ?>">Etiket SEO</a>
  <a href="<?= esc(admin_url('seo', ['bolum' => 'bildirim'])) ?>" class="<?= $bolum === 'bildirim' ? 'etkin' : '' ?>">Arama motoru bildirimi</a>
</div>

<?php if ($bolum === 'yonlendirme'): ?>

  <div class="kart">
    <div class="kart-baslik"><?= $duzenle ? 'Yönlendirmeyi düzenle' : 'Yeni yönlendirme' ?></div>
    <p class="kucuk soluk">
      Haber adresi değiştiğinde eski adres <strong>kendiliğinden</strong> yeni adrese gider
      (slug geçmişi). Buradaki kurallar bunun dışındaki serbest adresler içindir —
      örneğin başka bir siteden taşınan <code>2019/eski-yazi</code> gibi.
      <strong>Hedef yalnız bu sitenin içinde olabilir</strong>; dış adrese yönlendirme
      yazılamaz (kalıcı 301 geri alınamaz ve alan adı kaçırmaya açık kapı bırakırdı).
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="redirect_save">
      <input type="hidden" name="id" value="<?= (int)arr($duzenle, 'id', 0) ?>">
      <div class="izgara-2">
        <div class="form-alan">
          <label for="r_src">Kaynak adres</label>
          <input type="text" id="r_src" name="src_path" maxlength="400" required
                 placeholder="2019/eski-yazi" value="<?= esc(arr($duzenle, 'src_path', '')) ?>">
          <span class="form-yardim">Site kökünden itibaren, başında eğik çizgi olmadan.</span>
        </div>
        <div class="form-alan">
          <label for="r_target">Hedef adres</label>
          <input type="text" id="r_target" name="target" maxlength="400"
                 placeholder="kategori/gundem" value="<?= esc(arr($duzenle, 'target', '')) ?>">
          <span class="form-yardim">Boş bırakırsanız anasayfaya gider. 410 seçilirse yok sayılır.</span>
        </div>
      </div>
      <div class="izgara-2">
        <div class="form-alan">
          <label for="r_code">Yanıt kodu</label>
          <select id="r_code" name="code">
            <?= admin_options([
                  '301' => '301 — kalıcı taşındı',
                  '302' => '302 — geçici',
                  '410' => '410 — kalıcı olarak kaldırıldı',
                ], (string)arr($duzenle, 'code', '301')) ?>
          </select>
        </div>
        <div class="form-alan">
          <label for="r_note">Not</label>
          <input type="text" id="r_note" name="note" maxlength="190" value="<?= esc(arr($duzenle, 'note', '')) ?>">
        </div>
      </div>
      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" name="active" value="1" <?= (int)arr($duzenle, 'active', 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Etkin</span>
        </label>
      </div>
      <div class="dugme-grup">
        <button type="submit" class="dugme birincil"><?= $duzenle ? 'Güncelle' : 'Ekle' ?></button>
        <?php if ($duzenle): ?>
          <a class="dugme" href="<?= esc(admin_url('seo', ['bolum' => 'yonlendirme'])) ?>">Vazgeç</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <?php $kurallar = $seoHazir ? seo_redirect_list(200) : []; ?>
  <div class="kart">
    <div class="kart-baslik">Kurallar (<?= count($kurallar) ?>)</div>
    <?php if (!$kurallar): ?>
      <div class="bos-durum">Henüz elle tanımlanmış yönlendirme yok.</div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th>Kaynak</th><th>Hedef</th><th class="dar">Kod</th><th class="dar">İsabet</th><th class="dar">Durum</th><th class="islem"></th></tr></thead>
          <tbody>
          <?php foreach ($kurallar as $r): ?>
            <tr>
              <td class="tek-satir"><code><?= esc('/' . $r['src_path']) ?></code>
                <?php if ($r['note'] !== ''): ?><div class="satir-alt soluk"><?= esc($r['note']) ?></div><?php endif; ?>
              </td>
              <td class="tek-satir"><code><?= esc('/' . $r['target']) ?></code></td>
              <td class="dar"><?= (int)$r['code'] ?></td>
              <td class="dar"><?= (int)$r['hits'] ?></td>
              <td class="dar"><?= (int)$r['active'] ? admin_badge('etkin', 'olumlu') : admin_badge('kapalı', 'notr') ?></td>
              <td class="islem">
                <a class="dugme kucuk" href="<?= esc(admin_url('seo', ['bolum' => 'yonlendirme', 'duzenle' => (int)$r['id']])) ?>">Düzenle</a>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="do" value="redirect_delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button type="submit" class="dugme kucuk tehlike" data-onay="Bu yönlendirme silinsin mi?">Sil</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="kart sikisik">
    <div class="kart-baslik">Slug geçmişi</div>
    <p class="kucuk soluk">
      Yayındaki haberlerin güncel adresleri geçmişe yazılır; böylece adres sonradan
      değiştiğinde eskisi 301 ile yeniye gider. Normalde cron yapar.
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="slug_sync">
      <button type="submit" class="dugme">Şimdi eşitle</button>
    </form>
  </div>

<?php elseif ($bolum === 'kategori'): ?>

  <div class="kart">
    <div class="kart-baslik">Kategori üstverisi</div>
    <p class="kucuk soluk">
      Boş bırakılan alanlarda başlık şablonu ve kalıplı açıklama kullanılır
      (Ayarlar → SEO ve dağıtım).
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="category_save">
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th class="dar">Kategori</th><th>SEO başlığı</th><th>SEO açıklaması</th></tr></thead>
          <tbody>
          <?php foreach (all_categories(false) as $cat):
            $satir = category_by_id((int)$cat['id']); ?>
            <tr>
              <td class="tek-satir">
                <input type="hidden" name="cat_id[]" value="<?= (int)$cat['id'] ?>">
                <strong><?= esc($cat['name']) ?></strong>
                <div class="satir-alt soluk"><code><?= esc('/kategori/' . $cat['slug']) ?></code></div>
              </td>
              <td><input type="text" name="cat_title_<?= (int)$cat['id'] ?>" maxlength="255"
                         value="<?= esc(arr($satir, 'seo_title', '')) ?>" placeholder="Boşsa şablon"></td>
              <td><input type="text" name="cat_desc_<?= (int)$cat['id'] ?>" maxlength="500"
                         value="<?= esc(arr($satir, 'seo_desc', '')) ?>" placeholder="Boşsa kalıplı açıklama"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="dugme-grup bosluk-ust">
        <button type="submit" class="dugme birincil">Kaydet</button>
      </div>
    </form>
  </div>

<?php elseif ($bolum === 'etiket'): ?>

  <div class="kart">
    <div class="kart-baslik">Etiket üstverisi</div>
    <p class="kucuk soluk">
      Etiketler serbest metindir; eşleme adres parçası (slug) üzerinden yapılır.
      Her iki alanı da boşaltırsanız kayıt silinir ve kalıplı metne dönülür.
    </p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="tag_save">
      <div class="form-alan">
        <label for="t_tag">Etiket</label>
        <input type="text" id="t_tag" name="tag" maxlength="190" required
               value="<?= esc(inp_s('etiket', '')) ?>" placeholder="deprem">
      </div>
      <div class="izgara-2">
        <div class="form-alan">
          <label for="t_title">SEO başlığı</label>
          <input type="text" id="t_title" name="tag_title" maxlength="255">
        </div>
        <div class="form-alan">
          <label for="t_desc">SEO açıklaması</label>
          <input type="text" id="t_desc" name="tag_desc" maxlength="500">
        </div>
      </div>
      <button type="submit" class="dugme birincil">Kaydet</button>
    </form>
  </div>

  <?php $etiketler = $seoHazir ? seo_tag_meta_all(200) : []; ?>
  <div class="kart">
    <div class="kart-baslik">Tanımlı etiketler (<?= count($etiketler) ?>)</div>
    <?php if (!$etiketler): ?>
      <div class="bos-durum">Hiçbir etikete özel üstveri yazılmamış.</div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th class="dar">Etiket</th><th>SEO başlığı</th><th>SEO açıklaması</th></tr></thead>
          <tbody>
          <?php foreach ($etiketler as $t): ?>
            <tr>
              <td class="tek-satir"><strong><?= esc($t['label'] !== '' ? $t['label'] : $t['slug']) ?></strong>
                <div class="satir-alt soluk"><code><?= esc('/etiket/' . $t['slug']) ?></code></div>
              </td>
              <td><?= esc($t['seo_title']) ?></td>
              <td class="soluk"><?= esc($t['seo_desc']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else:
  $stats = function_exists('seo_ping_stats') ? seo_ping_stats() : ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
  $anahtar = function_exists('seo_indexnow_key') ? seo_indexnow_key() : '';
?>

  <div class="izgara-4">
    <div class="sayac"><span class="deger"><?= (int)$stats['queued'] ?></span><span class="etiket">Kuyrukta</span></div>
    <div class="sayac"><span class="deger"><?= (int)$stats['sent'] ?></span><span class="etiket">Gönderildi</span></div>
    <div class="sayac"><span class="deger"><?= (int)$stats['failed'] ?></span><span class="etiket">Başarısız</span></div>
    <div class="sayac"><span class="deger"><?= (int)$stats['skipped'] ?></span><span class="etiket">Atlandı</span></div>
  </div>

  <div class="kart">
    <div class="kart-baslik">IndexNow</div>
    <p class="kucuk soluk">
      Yayımlanan haberler <strong>yayın anında değil</strong>, cron turunda bildirilir:
      “Yayımla” düğmesine basan editör dış servisi beklemez ve servis çökerse yayın düşmez.
      IndexNow'ı Bing, Yandex ve Naver kullanır; Google desteklemez.
    </p>
    <div class="form-alan">
      <label>Anahtar dosyası</label>
      <input type="text" readonly value="<?= esc(base_url() . '/' . $anahtar . '.txt') ?>">
      <span class="form-yardim">
        Bu adresin <code><?= esc($anahtar) ?></code> içeriğini döndürmesi gerekir.
        Adres site kökünden sunulur; sunucunuzda temiz adresler kapalıysa
        <code>index.php?r=<?= esc($anahtar) ?>.txt</code> biçimi de çalışır.
      </span>
    </div>
    <div class="dugme-grup">
      <a class="dugme" href="<?= esc(base_url() . '/' . $anahtar . '.txt') ?>" target="_blank" rel="noopener">Anahtar dosyasını sına ↗</a>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="ping_run">
        <button type="submit" class="dugme birincil">Kuyruğu şimdi işle</button>
      </form>
    </div>
    <p class="kucuk soluk bosluk-ust">
      Durum: IndexNow <?= seo_indexnow_enabled() ? admin_badge('açık', 'olumlu') : admin_badge('kapalı', 'notr') ?> ·
      sitemap ping <?= seo_sitemap_ping_enabled() ? admin_badge('açık', 'olumlu') : admin_badge('kapalı', 'notr') ?> ·
      WebSub <?= seo_websub_hub() !== '' ? admin_badge('açık', 'olumlu') : admin_badge('kapalı', 'notr') ?>
      — Ayarlar → SEO ve dağıtım.
    </p>
  </div>

  <?php $son = function_exists('seo_ping_recent') ? seo_ping_recent(25) : []; ?>
  <div class="kart">
    <div class="kart-baslik">Son bildirimler</div>
    <?php if (!$son): ?>
      <div class="bos-durum">Kuyruk boş.</div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th class="dar">Tür</th><th>Adres</th><th class="dar">Durum</th><th class="dar">Zaman</th></tr></thead>
          <tbody>
          <?php foreach ($son as $r):
            $ton = ['sent' => 'olumlu', 'queued' => 'bilgi', 'failed' => 'olumsuz', 'skipped' => 'notr'];
            $t = isset($ton[$r['status']]) ? $ton[$r['status']] : 'notr'; ?>
            <tr>
              <td class="dar"><?= esc($r['kind']) ?></td>
              <td class="tek-satir"><code><?= esc($r['url']) ?></code>
                <?php if ($r['error'] !== ''): ?><div class="satir-alt soluk"><?= esc($r['error']) ?></div><?php endif; ?>
              </td>
              <td class="dar"><?= admin_badge((string)$r['status'], $t) ?></td>
              <td class="dar mini soluk"><?= esc((string)($r['sent_at'] ?: $r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
