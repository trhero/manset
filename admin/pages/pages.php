<?php
/**
 * Panel — sabit sayfalar (Ajan-2).
 * Liste + zengin metin editörlü düzenleme. Yazma işlemleri pages.* uçlarından geçer.
 * 'kunye' slug'ı rezervedir; künye ekranı ayrı bir modüldür (Ajan-9).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('pages.manage');

$adminPageTitle = 'Sabit Sayfalar';

$editId = inp_i('duzenle', 0);
$yeni = inp_i('yeni', 0) === 1;
$sayfa = $editId > 0 ? q1('SELECT * FROM pages WHERE id = :i', [':i' => $editId]) : null;
$editorModu = ($sayfa !== null) || $yeni;

if ($editId > 0 && !$sayfa) {
    flash('err', 'Sayfa bulunamadı.');
    header('Location: ' . admin_url('pages'), true, 302);
    exit;
}

if (!$editorModu) {
    $liste = qa('SELECT id, title, slug, in_footer, sort, updated_at, created_at FROM pages ORDER BY sort ASC, title ASC');
}
?>

<?php if (!$editorModu): ?>

  <div class="sayfa-basligi">
    <h1>Sabit Sayfalar</h1>
    <a class="dugme birincil" href="<?= esc(admin_url('pages', ['yeni' => 1])) ?>">+ Yeni sayfa</a>
  </div>

  <?php if (!$liste): ?>
    <div class="bos-durum">
      <span class="buyuk" aria-hidden="true">❐</span>
      <h3>Henüz sabit sayfa yok</h3>
      <p>Hakkımızda, İletişim, Gizlilik gibi sayfaları buradan oluşturabilirsiniz.</p>
      <a class="dugme birincil" href="<?= esc(admin_url('pages', ['yeni' => 1])) ?>">+ Yeni sayfa</a>
    </div>
  <?php else: ?>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead>
          <tr><th class="dar">Sıra</th><th>Başlık</th><th class="dar">Alt bilgi</th><th class="dar">Güncelleme</th><th class="islem">İşlem</th></tr>
        </thead>
        <tbody>
          <?php foreach ($liste as $s): ?>
            <tr>
              <td class="dar soluk"><?= (int)$s['sort'] ?></td>
              <td>
                <a href="<?= esc(admin_url('pages', ['duzenle' => (int)$s['id']])) ?>"><strong><?= esc($s['title']) ?></strong></a>
                <span class="satir-alt">
                  <a href="<?= esc(url_page($s)) ?>" target="_blank" rel="noopener"><?= esc(url_page($s)) ?> ↗</a>
                </span>
              </td>
              <td class="dar"><?= (int)$s['in_footer'] === 1 ? admin_badge('görünür', 'olumlu') : admin_badge('gizli', 'notr') ?></td>
              <td class="dar mini soluk"><?= esc(tr_ago($s['updated_at'] ?: $s['created_at'])) ?></td>
              <td class="islem">
                <a class="dugme kucuk" href="<?= esc(admin_url('pages', ['duzenle' => (int)$s['id']])) ?>">Düzenle</a>
                <button type="button" class="dugme kucuk tehlike" data-sayfa-sil="<?= (int)$s['id'] ?>"
                        data-onay="<?= esc($s['title']) ?> kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <script>
  document.addEventListener('DOMContentLoaded', function () {
    M.qsa('[data-sayfa-sil]').forEach(function (b) {
      b.addEventListener('click', function () {
        M.api('pages.delete', { id: parseInt(b.getAttribute('data-sayfa-sil'), 10) }).then(function (r) {
          if (M.sonuc(r, 'Sayfa silindi.')) { setTimeout(function () { location.reload(); }, 500); }
        });
      });
    });
  });
  </script>

<?php else: ?>

  <script src="<?= esc(url_asset('editor.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>

  <div class="sayfa-basligi">
    <h1><?= $sayfa ? 'Sayfayı düzenle' : 'Yeni sayfa' ?></h1>
    <div class="dugme-grup">
      <a class="dugme" href="<?= esc(admin_url('pages')) ?>">← Liste</a>
      <?php if ($sayfa): ?>
        <a class="dugme" href="<?= esc(url_page($sayfa)) ?>" target="_blank" rel="noopener">Ön yüzde gör ↗</a>
      <?php endif; ?>
      <button type="submit" form="sayfaForm" class="dugme birincil">Kaydet</button>
    </div>
  </div>

  <form id="sayfaForm" class="iki-kolon" autocomplete="off">
    <input type="hidden" name="id" value="<?= (int)($sayfa ? $sayfa['id'] : 0) ?>">

    <div>
      <div class="kart">
        <div class="form-alan">
          <label for="s_title">Başlık</label>
          <input type="text" id="s_title" name="title" required maxlength="190" value="<?= esc($sayfa ? $sayfa['title'] : '') ?>">
        </div>
        <div class="form-alan">
          <label for="s_slug">Adres (slug)</label>
          <input type="text" id="s_slug" name="slug" maxlength="200" value="<?= esc($sayfa ? $sayfa['slug'] : '') ?>">
          <span class="form-yardim">
            Adres: <code><?= esc(url_page(['slug' => '…'])) ?></code> ·
            <strong>kunye</strong> adresi sistem tarafından ayrılmıştır, kullanılamaz.
          </span>
          <div id="slugUyari" class="uyari warn mini bosluk-ust" hidden></div>
        </div>
      </div>

      <div class="kart">
        <div class="kart-baslik">İçerik</div>
        <textarea id="s_body" name="body" rows="16"><?= esc($sayfa ? $sayfa['body'] : '') ?></textarea>
      </div>
    </div>

    <div>
      <div class="kart">
        <div class="kart-baslik">Yayın</div>
        <div class="form-alan">
          <label class="anahtar">
            <input type="checkbox" name="in_footer" value="1" <?= (!$sayfa || (int)$sayfa['in_footer'] === 1) ? 'checked' : '' ?>>
            <span class="kaydirak"></span><span>Alt bilgide göster</span>
          </label>
        </div>
        <div class="form-alan">
          <label for="s_sort">Sıra</label>
          <input type="number" id="s_sort" name="sort" min="0" max="9999" value="<?= (int)($sayfa ? $sayfa['sort'] : 0) ?>">
          <span class="form-yardim">Küçük değer önce görünür.</span>
        </div>
        <div class="dugme-grup">
          <button type="submit" class="dugme birincil">Kaydet</button>
          <?php if ($sayfa): ?>
            <button type="button" class="dugme tehlike" data-sayfa-sil="<?= (int)$sayfa['id'] ?>"
                    data-onay="<?= esc($sayfa['title']) ?> kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($sayfa): ?>
        <div class="kart sikisik mini soluk">
          Oluşturma: <?= esc(tr_date($sayfa['created_at'])) ?><br>
          Son güncelleme: <?= esc($sayfa['updated_at'] ? tr_date($sayfa['updated_at']) : '—') ?>
        </div>
      <?php endif; ?>
    </div>
  </form>

  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('sayfaForm');
    var listeAdres = <?= json_encode(admin_url('pages')) ?>;
    var duzenleAdres = <?= json_encode(admin_url('pages', ['duzenle' => 0])) ?>;
    var editor = window.MansetEditor
      ? MansetEditor.baglat(document.getElementById('s_body'), { bos: 'Sayfa metnini buraya yazın…', etiket: 'Sayfa içeriği' })
      : null;

    var kirli = M.kirliTakip(form);

    function slugla(s) {
      var harita = { 'ğ':'g','Ğ':'g','ü':'u','Ü':'u','ş':'s','Ş':'s','ı':'i','İ':'i','ö':'o','Ö':'o','ç':'c','Ç':'c','â':'a','î':'i','û':'u' };
      return String(s || '').replace(/[ğĞüÜşŞıİöÖçÇâîû]/g, function (c) { return harita[c] || c; })
        .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    var baslik = document.getElementById('s_title');
    var slugAlan = document.getElementById('s_slug');
    var uyari = document.getElementById('slugUyari');
    var slugElle = slugAlan.value !== '';

    function slugDenetle() {
      var v = slugla(slugAlan.value);
      var rezerve = ['kunye', 'arama', 'haber', 'kategori', 'etiket', 'sayfa', 'rss', 'feed', 'sitemap', 'robots'];
      if (v && rezerve.indexOf(v) >= 0) {
        uyari.textContent = '“' + v + '” adresi sistem tarafından ayrılmıştır. Başka bir adres seçin.';
        uyari.hidden = false;
        return false;
      }
      uyari.hidden = true;
      return true;
    }

    slugAlan.addEventListener('input', function () { slugElle = true; slugDenetle(); });
    baslik.addEventListener('input', function () {
      if (!slugElle) { slugAlan.value = slugla(baslik.value); slugDenetle(); }
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!slugDenetle()) { M.toast('err', 'Rezerve adres kullanılamaz.'); return; }
      var v = M.formVerisi(form);
      v.id = parseInt(v.id, 10) || 0;
      v.sort = parseInt(v.sort, 10) || 0;
      v.body = editor ? editor.html() : document.getElementById('s_body').value;
      if (!String(v.title || '').trim()) { M.toast('err', 'Başlık zorunlu.'); return; }

      M.api('pages.save', v).then(function (r) {
        if (!M.sonuc(r, 'Sayfa kaydedildi.')) { return; }
        kirli.temizle();
        if (v.id === 0 && r.id) {
          history.replaceState(null, '', duzenleAdres.replace(/duzenle=0$/, 'duzenle=' + r.id));
          M.qs('input[name=id]', form).value = r.id;
        }
        if (r.slug) { slugAlan.value = r.slug; }
      });
    });

    M.qsa('[data-sayfa-sil]').forEach(function (b) {
      b.addEventListener('click', function () {
        M.api('pages.delete', { id: parseInt(b.getAttribute('data-sayfa-sil'), 10) }).then(function (r) {
          if (M.sonuc(r, 'Sayfa silindi.')) {
            kirli.temizle();
            setTimeout(function () { location.href = listeAdres; }, 500);
          }
        });
      });
    });
  });
  </script>

<?php endif; ?>
