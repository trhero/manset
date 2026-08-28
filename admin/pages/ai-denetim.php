<?php
/**
 * Panel — Yapay Zekâ Denetimi (1.3-04, Ajan-G).
 *
 * İki bölüm:
 *   1) DENETİM   Kaynak metin ↔ YZ çıktısı YAN YANA, kelime bazlı fark vurgulu.
 *   2) ARAÇLAR   Seçili metni yeniden yaz / kısalt, özet, kategori+etiket, alt metin.
 *
 * =============================================================================
 * "İNSAN ONAYI OLMADAN YAYIN" ROZETİ — NEDEN EN ÜSTTE
 * =============================================================================
 * `rss_sources.auto_publish = 1` VE `ai_rewrite = 1` olan bir kaynakta zincir
 * şudur: besleme çekilir → iş kuyruğa girer → model haberi yazar →
 * `ai_create_post_from_result()` `status`'ü doğrudan `published` yapar
 * (inc/ai_jobs.php). Yani model çıktısı, HİÇBİR İNSAN OKUMADAN sitede yayımlanır.
 *
 * Bugüne dek arayüz bunu hiçbir yerde SÖYLEMİYORDU: RSS ekranında iki ayrı
 * onay kutusu var, ikisinin BİRLİKTE ne anlama geldiğini kimse yazmamıştı.
 * Sorumlu yazı işleri müdürünün hukuki sorumluluğu bu ayarla değişmez —
 * yayımlanan her metin onun sorumluluğundadır. Bu yüzden uyarı bir dipnot
 * değil, sayfanın ilk gördüğü şeydir ve hangi kaynakların açık olduğunu ADIYLA
 * sayar.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('ai.use');

$adminPageTitle = 'YZ Denetimi';

if (!function_exists('ai_configured')) { require_once INC_DIR . '/ai.php'; }
if (!function_exists('ai_jobs_list')) { require_once INC_DIR . '/ai_jobs.php'; }
// Fark motoru uç nokta dosyasında yaşar. CONTRACTS §7: api_register()
// bootstrap'te tanımlıdır, bu yüzden inc/api/*.php panelden güvenle require edilir.
require_once INC_DIR . '/api/ai_tools_api.php';

$sekmeler = ['denetim' => 'Kaynak ↔ Çıktı', 'araclar' => 'Metin araçları'];
$sekme = (string)inp('t', 'denetim');
if (!isset($sekmeler[$sekme])) { $sekme = 'denetim'; }

/* ------------------------------------------------------------------ uyarı verisi */

/**
 * `auto_publish` + `ai_rewrite` birlikte açık olan kaynaklar.
 * Tablo yoksa (kurulum eski) boş dizi döner; ekran yine çalışır.
 */
function ai_denetim_riskli_kaynaklar() {
    try {
        return qa('SELECT id, name, auto_publish, ai_rewrite FROM rss_sources
                   WHERE auto_publish = 1 AND ai_rewrite = 1 ORDER BY name ASC');
    } catch (Throwable $e) { return []; }
}

/** YZ üretimi olup YAYIMDA olan haber sayısı (denetim izi). */
function ai_denetim_yayinda_sayisi() {
    try {
        return (int)qv('SELECT COUNT(*) FROM posts WHERE ai_generated = 1 AND status = \'published\'', [], 0);
    } catch (Throwable $e) { return 0; }
}

/** HTML gövdeyi karşılaştırılabilir düz metne indirir. */
function ai_denetim_duz($html) {
    $s = (string)$html;
    $s = (string)preg_replace('#<\s*(script|style)\b[^>]*>.*?<\s*/\s*\1\s*>#is', ' ', $s);
    $s = (string)preg_replace('#</\s*(p|div|li|h[1-6]|tr|blockquote)\s*>#i', "\n", $s);
    $s = (string)preg_replace('#<\s*br\s*/?>#i', "\n", $s);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = (string)preg_replace('/[ \t]+/u', ' ', $s);
    $s = (string)preg_replace('/\n{2,}/u', "\n", $s);
    return trim(sanitize_plain($s, 40000));
}

/**
 * Denetlenebilir işler: tamamlanmış, bir haber üretmiş ve kaynak ögesi duran işler.
 * Kaynak metin `rss_items.content` (yoksa `summary`), çıktı `posts.body`.
 */
function ai_denetim_isler($limit = 40) {
    try {
        return qa('SELECT j.id, j.status, j.finished_at, j.result_post_id,
                          i.title AS kaynak_baslik, i.link AS kaynak_link,
                          p.title AS haber_baslik, p.status AS haber_durum, p.slug AS haber_slug,
                          s.name AS kaynak_ad
                   FROM ai_jobs j
                   LEFT JOIN rss_items i ON i.id = j.rss_item_id
                   LEFT JOIN posts p ON p.id = j.result_post_id
                   LEFT JOIN rss_sources s ON s.id = i.source_id
                   WHERE j.result_post_id > 0
                   ORDER BY j.id DESC LIMIT ' . (int)$limit);
    } catch (Throwable $e) { return []; }
}

/** Tek işin karşılaştırma verisi. */
function ai_denetim_is($jobId) {
    try {
        $j = q1('SELECT j.*, i.title AS kaynak_baslik, i.link AS kaynak_link,
                        i.summary AS kaynak_ozet, i.content AS kaynak_icerik,
                        s.name AS kaynak_ad, s.auto_publish, s.ai_rewrite
                 FROM ai_jobs j
                 LEFT JOIN rss_items i ON i.id = j.rss_item_id
                 LEFT JOIN rss_sources s ON s.id = i.source_id
                 WHERE j.id = :i', [':i' => (int)$jobId]);
    } catch (Throwable $e) { return null; }
    if (!$j) { return null; }
    $post = null;
    if ((int)arr($j, 'result_post_id', 0) > 0) {
        try { $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => (int)$j['result_post_id']]); }
        catch (Throwable $e) { $post = null; }
    }
    $kaynak = trim((string)arr($j, 'kaynak_icerik', ''));
    if ($kaynak === '') { $kaynak = (string)arr($j, 'kaynak_ozet', ''); }
    return [
        'is'      => $j,
        'post'    => $post,
        'kaynak'  => ai_denetim_duz($kaynak),
        'cikti'   => $post ? ai_denetim_duz((string)arr($post, 'body', '')) : '',
    ];
}

$riskli   = ai_denetim_riskli_kaynaklar();
$yayinda  = ai_denetim_yayinda_sayisi();
$secilenId = inp_i('is', 0);
$secilen  = $secilenId > 0 ? ai_denetim_is($secilenId) : null;
$isler    = $sekme === 'denetim' ? ai_denetim_isler(40) : [];

$fark = null;
$farkOzet = ['esit' => 0, 'ekle' => 0, 'sil' => 0];
if ($secilen && ($secilen['kaynak'] !== '' || $secilen['cikti'] !== '')) {
    $fark = ai_tools_diff_words($secilen['kaynak'], $secilen['cikti']);
    $farkOzet = ai_tools_diff_stats($fark);
}
?>

<style>
/* SAYFAYA ÖZEL — assets/admin.css'e yeni sınıf EKLENMEDİ (CONTRACTS §8.2).
   Bu blok yalnız bu ekranın fark sütunlarını renklendirir. */
.fark-sutun { white-space: pre-wrap; word-break: break-word; line-height: 1.7;
  max-height: 60vh; overflow: auto; font-size: 14px; }
ins.fark-ekle { background: #dff6e2; color: #14532d; text-decoration: none; padding: 0 2px; border-radius: 2px; }
del.fark-sil  { background: #fde2e2; color: #7f1d1d; padding: 0 2px; border-radius: 2px; }
</style>

<div class="sayfa-basligi">
  <h1>YZ Denetimi</h1>
  <div class="dugme-grup">
    <?= admin_badge('YZ: ' . ai_status_text(), ai_status_text() === 'hazır' ? 'olumlu' : (ai_status_text() === 'test modu' ? 'ai' : 'uyari')) ?>
    <?= admin_badge('Yayımda YZ haberi: ' . $yayinda, $yayinda > 0 ? 'bilgi' : 'notr') ?>
  </div>
</div>

<?php /* ============================ İNSAN ONAYI OLMADAN YAYIN ROZETİ ========= */ ?>
<?php if ($riskli): ?>
  <div class="uyari err">
    <p><strong><?= admin_badge('İNSAN ONAYI OLMADAN YAYIN', 'olumsuz') ?></strong></p>
    <p>
      Aşağıdaki <?= count($riskli) ?> kaynakta <strong>otomatik yayın</strong> ve
      <strong>yapay zekâ ile yeniden yazım</strong> aynı anda açık. Bu kaynaklardan gelen
      haberler model tarafından yazıldıktan sonra <strong>hiç kimse okumadan doğrudan
      yayımlanıyor</strong> — taslağa düşmüyor, onay kuyruğuna girmiyor.
    </p>
    <p>
      Yayımlanan metnin hukuki sorumluluğu bu ayarla değişmez; sorumlu yazı işleri
      müdüründedir. Yayıncı yürürlükteki düzenlemeyi teyit etmelidir.
    </p>
    <ul>
      <?php foreach ($riskli as $r): ?>
        <li>
          <strong><?= esc((string)$r['name']) ?></strong> —
          <?= admin_badge('otomatik yayın', 'olumsuz') ?>
          <?= admin_badge('YZ yeniden yazım', 'ai') ?>
          <?php if (can($adminUser, 'rss.manage')): ?>
            <a href="<?= esc(admin_url('rss', ['duzenle' => (int)$r['id']])) ?>">kaynağı düzenle</a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
    <p class="soluk">
      Onaylı akış için kaynağın <em>otomatik yayın</em> kutusunu kapatın: YZ haberi
      yine üretilir ama <strong>taslak</strong> olarak bekler.
    </p>
  </div>
<?php else: ?>
  <div class="uyari ok">
    <strong>İnsan onayı zinciri sağlam.</strong>
    Hiçbir kaynakta “otomatik yayın” ile “YZ yeniden yazım” birlikte açık değil;
    yapay zekânın ürettiği her haber yayımlanmadan önce taslak olarak bekliyor.
  </div>
<?php endif; ?>

<div class="sekmeler">
  <?php foreach ($sekmeler as $k => $etiket): ?>
    <a class="<?= $sekme === $k ? 'etkin' : '' ?>" href="<?= esc(admin_url('ai-denetim', ['t' => $k])) ?>"><?= esc($etiket) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($sekme === 'denetim'): ?>

  <?php if (!$isler): ?>
    <div class="bos-durum">
      <p>Karşılaştırılacak yapay zekâ işi yok.</p>
      <p class="soluk">RSS havuzundan bir ögeyi “YZ ile yeniden yaz” ile kuyruğa aldığınızda
      iş burada listelenir ve kaynak metinle çıktı yan yana karşılaştırılabilir.</p>
    </div>
  <?php else: ?>

    <div class="kart">
      <div class="kart-baslik">Tamamlanan işler</div>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead>
            <tr><th>#</th><th>Kaynak</th><th>Özgün başlık</th><th>Üretilen haber</th><th class="dar">Durum</th><th class="dar islem">Karşılaştır</th></tr>
          </thead>
          <tbody>
          <?php foreach ($isler as $j): ?>
            <tr<?= (int)$j['id'] === $secilenId ? ' style="outline:2px solid var(--vurgu,#c0392b)"' : '' ?>>
              <td class="dar"><?= (int)$j['id'] ?></td>
              <td><?= esc((string)arr($j, 'kaynak_ad', '—')) ?></td>
              <td class="tek-satir"><?= esc((string)arr($j, 'kaynak_baslik', '—')) ?></td>
              <td class="tek-satir"><?= esc((string)arr($j, 'haber_baslik', '—')) ?></td>
              <td class="dar">
                <?php $hd = (string)arr($j, 'haber_durum', ''); ?>
                <?= $hd === 'published' ? admin_badge('yayımda', 'olumsuz') : admin_badge($hd === '' ? '—' : $hd, 'notr') ?>
              </td>
              <td class="dar islem">
                <a class="dugme kucuk" href="<?= esc(admin_url('ai-denetim', ['t' => 'denetim', 'is' => (int)$j['id']])) ?>">Göster</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($secilen === null && $secilenId > 0): ?>
      <div class="uyari warn">Seçilen iş bulunamadı.</div>
    <?php endif; ?>

    <?php if ($secilen): ?>
      <div class="kart">
        <div class="kart-baslik">
          Kaynak ↔ Çıktı karşılaştırması (iş #<?= (int)$secilen['is']['id'] ?>)
        </div>

        <div class="dugme-grup bosluk-alt">
          <?= admin_badge($farkOzet['esit'] . ' kelime aynı', 'notr') ?>
          <?= admin_badge($farkOzet['sil'] . ' kelime kaynakta kaldı', 'uyari') ?>
          <?= admin_badge($farkOzet['ekle'] . ' kelime modelde yeni', 'bilgi') ?>
          <?php if ((int)arr($secilen['is'], 'auto_publish', 0) === 1 && (int)arr($secilen['is'], 'ai_rewrite', 0) === 1): ?>
            <?= admin_badge('bu iş insan onayı olmadan yayımlandı', 'olumsuz') ?>
          <?php endif; ?>
        </div>

        <?php if ($secilen['kaynak'] === ''): ?>
          <div class="uyari warn">Kaynak öge silinmiş; yalnız çıktı gösterilebiliyor.</div>
        <?php endif; ?>

        <div class="izgara-2">
          <div>
            <h3>Kaynak metin
              <?php if (trim((string)arr($secilen['is'], 'kaynak_link', '')) !== ''): ?>
                <a class="kucuk" href="<?= esc((string)$secilen['is']['kaynak_link']) ?>"
                   target="_blank" rel="nofollow noopener noreferrer">özgün haber</a>
              <?php endif; ?>
            </h3>
            <div class="fark-sutun"><?= $fark ? ai_tools_diff_html($fark, 'eski') : '' ?></div>
          </div>
          <div>
            <h3>Yapay zekâ çıktısı
              <?php if ($secilen['post']): ?>
                <a class="kucuk" href="<?= esc(admin_url('posts', ['duzenle' => (int)$secilen['post']['id']])) ?>">haberi aç</a>
              <?php endif; ?>
            </h3>
            <div class="fark-sutun"><?= $fark ? ai_tools_diff_html($fark, 'yeni') : '' ?></div>
          </div>
        </div>

        <p class="soluk kucuk">
          Yeşil: yalnız YZ çıktısında olan kelimeler. Kırmızı: yalnız kaynakta olan kelimeler.
          Fark <strong>kelime</strong> bazlıdır ve sunucuda üretilir (dış kütüphane yok).
        </p>
      </div>
    <?php endif; ?>

  <?php endif; ?>

<?php else: ?>

  <?php /* ============================ METİN ARAÇLARI ========================= */ ?>
  <div class="kart">
    <div class="kart-baslik">Metin araçları</div>
    <p class="soluk">
      Buraya yapıştırdığınız metin üzerinde çalışırsınız; sonuç <strong>otomatik
      uygulanmaz</strong>, farkı görüp kopyalarsınız. Her çağrı ücretlidir ve
      <code>ai.use</code> izniyle sınırlıdır.
    </p>

    <div class="form-alan">
      <label for="atMetin">Kaynak metin</label>
      <textarea id="atMetin" rows="10" placeholder="Üzerinde çalışılacak metni yapıştırın…"></textarea>
    </div>

    <div class="form-satir">
      <div class="form-alan">
        <label for="atBaslik">Haber başlığı (kategori/etiket ve alt metin için)</label>
        <input type="text" id="atBaslik">
      </div>
      <div class="form-alan">
        <label for="atDosya">Görsel dosya adı (alt metin için)</label>
        <input type="text" id="atDosya" placeholder="2026/08/miting.jpg">
      </div>
    </div>

    <div class="dugme-grup">
      <button type="button" class="dugme birincil" data-at="rewrite">Yeniden yaz</button>
      <button type="button" class="dugme" data-at="shorten">Kısalt</button>
      <button type="button" class="dugme" data-at="summary">Özet</button>
      <button type="button" class="dugme" data-at="taxonomy">Kategori + etiket</button>
      <button type="button" class="dugme" data-at="alt">Görsel alt metni</button>
    </div>

    <div id="atDurum" class="soluk" role="status"></div>
  </div>

  <div class="kart" id="atSonucKart" hidden>
    <div class="kart-baslik">Sonuç</div>
    <div id="atRozet" class="dugme-grup bosluk-alt"></div>
    <div class="izgara-2">
      <div><h3>Girdi</h3><div class="fark-sutun" id="atEski"></div></div>
      <div><h3>Çıktı</h3><div class="fark-sutun" id="atYeni"></div></div>
    </div>
    <div class="dugme-grup">
      <button type="button" class="dugme" id="atKopyala">Çıktıyı panoya kopyala</button>
    </div>
  </div>

<?php endif; ?>

<script>
M.hazir(function () {
  var dugmeler = M.qsa('[data-at]');
  if (!dugmeler.length) { return; }

  var durum   = document.getElementById('atDurum');
  var kart    = document.getElementById('atSonucKart');
  var rozetEl = document.getElementById('atRozet');
  var eskiEl  = document.getElementById('atEski');
  var yeniEl  = document.getElementById('atYeni');
  var sonCikti = '';

  /* Fark parçalarını DOM ile basar. innerHTML KULLANILMAZ: metin sunucudan
     gelen model çıktısıdır, textContent ile yazmak kaçış hatası olasılığını
     sıfırlar (M.esc unutulabilir; createTextNode unutulamaz). */
  function farkBas(hedef, parcalar, taraf) {
    hedef.textContent = '';
    (parcalar || []).forEach(function (p) {
      if (p.op === 'sil' && taraf === 'yeni') { return; }
      if (p.op === 'ekle' && taraf === 'eski') { return; }
      var dugum;
      if (p.op === 'esit') { dugum = document.createTextNode(p.text); }
      else {
        dugum = document.createElement(p.op === 'ekle' ? 'ins' : 'del');
        dugum.className = p.op === 'ekle' ? 'fark-ekle' : 'fark-sil';
        dugum.textContent = p.text;
      }
      hedef.appendChild(dugum);
    });
  }

  function duzBas(hedef, metin) {
    hedef.textContent = String(metin == null ? '' : metin);
  }

  function rozet(metin, ton) {
    var s = document.createElement('span');
    s.className = 'rozet ' + (ton || 'notr');
    s.textContent = metin;
    rozetEl.appendChild(s);
  }

  function kilit(acik) {
    dugmeler.forEach(function (b) { b.disabled = acik; });
  }

  function calistir(eylem) {
    var metin  = document.getElementById('atMetin').value;
    var baslik = document.getElementById('atBaslik').value;
    var dosya  = document.getElementById('atDosya').value;

    var uc, veri;
    if (eylem === 'rewrite' || eylem === 'shorten') { uc = 'aitools.rewrite'; veri = { text: metin, mode: eylem }; }
    else if (eylem === 'summary')  { uc = 'aitools.summary';  veri = { text: metin }; }
    else if (eylem === 'taxonomy') { uc = 'aitools.taxonomy'; veri = { text: metin, title: baslik }; }
    else { uc = 'aitools.alt_text'; veri = { title: baslik, filename: dosya, context: metin }; }

    kilit(true);
    durum.textContent = 'Yapay zekâ çalışıyor…';
    M.api(uc, veri).then(function (r) {
      kilit(false);
      if (!r || !r.ok) { durum.textContent = (r && r.error) ? r.error : 'İşlem başarısız.'; return; }
      durum.textContent = '';
      kart.hidden = false;
      rozetEl.textContent = '';

      if (uc === 'aitools.rewrite') {
        sonCikti = r.text || '';
        rozet((r.stats ? r.stats.ekle : 0) + ' kelime yeni', 'bilgi');
        rozet((r.stats ? r.stats.sil : 0) + ' kelime çıkarıldı', 'uyari');
        farkBas(eskiEl, r.diff, 'eski');
        farkBas(yeniEl, r.diff, 'yeni');
        return;
      }
      if (uc === 'aitools.summary') {
        sonCikti = r.text || '';
        rozet('özet', 'ai');
        duzBas(eskiEl, r.source || metin);
        duzBas(yeniEl, r.text || '');
        return;
      }
      if (uc === 'aitools.taxonomy') {
        sonCikti = (r.category_name || r.category_raw || '') + '\n' + ((r.tags || []).join(', '));
        rozet(r.matched ? 'kategori eşleşti' : 'kategori eşleşmedi', r.matched ? 'olumlu' : 'uyari');
        duzBas(eskiEl, metin);
        duzBas(yeniEl, 'Kategori: ' + (r.category_name || r.category_raw || '—')
          + '\nEtiketler: ' + ((r.tags || []).join(', ') || '—')
          + (r.matched ? '' : '\n\n(Model listede olmayan bir kategori önerdi; uygulanmaz.)'));
        return;
      }
      sonCikti = r.alt || '';
      rozet('alt metin', 'ai');
      duzBas(eskiEl, (baslik ? 'Başlık: ' + baslik + '\n' : '') + (dosya ? 'Dosya: ' + dosya + '\n' : '') + metin);
      duzBas(yeniEl, (r.alt || '') + '\n\n' + (r.note || ''));
    });
  }

  dugmeler.forEach(function (b) {
    b.addEventListener('click', function () { calistir(b.getAttribute('data-at')); });
  });

  var kop = document.getElementById('atKopyala');
  if (kop) {
    kop.addEventListener('click', function () {
      if (!sonCikti) { return; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(sonCikti).then(function () { M.toast('ok', 'Kopyalandı.'); },
                                                     function () { M.toast('err', 'Kopyalanamadı.'); });
      } else {
        M.toast('err', 'Tarayıcı pano erişimine izin vermiyor; metni elle seçin.');
      }
    });
  }
});
</script>
