<?php
/**
 * Panel — kategoriler (Ajan-2).
 * CRUD + sıralama. Tüm yazma işlemleri categories.* API uçlarından geçer.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('categories.manage');

$adminPageTitle = 'Kategoriler';

$editId = inp_i('duzenle', 0);
$editCat = $editId > 0 ? q1('SELECT * FROM categories WHERE id = :i', [':i' => $editId]) : null;

$kategoriler = qa('SELECT c.id, c.name, c.slug, c.sort, c.color, c.in_menu, c.active,
        (SELECT COUNT(*) FROM posts p WHERE p.category_id = c.id) AS post_count
    FROM categories c ORDER BY c.sort ASC, c.name ASC');
$sonIndeks = count($kategoriler) - 1;
?>

<div class="sayfa-basligi">
  <h1>Kategoriler</h1>
  <?php if ($editCat): ?>
    <a class="dugme" href="<?= esc(admin_url('categories')) ?>">+ Yeni kategori</a>
  <?php endif; ?>
</div>

<div class="iki-kolon">
  <div>
    <?php if (!$kategoriler): ?>
      <div class="bos-durum">
        <span class="buyuk" aria-hidden="true">☰</span>
        <h3>Henüz kategori yok</h3>
        <p>Sağdaki formdan ilk kategorinizi ekleyin.</p>
      </div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead>
            <tr>
              <th class="dar">Sıra</th>
              <th>Ad</th>
              <th class="dar">Renk</th>
              <th class="dar">Haber</th>
              <th class="dar">Menü</th>
              <th class="dar">Durum</th>
              <th class="islem">İşlem</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($kategoriler as $i => $c): ?>
              <tr>
                <td class="dar">
                  <div class="dugme-grup" style="flex-wrap:nowrap">
                    <button type="button" class="dugme kucuk" data-sira="up" data-id="<?= (int)$c['id'] ?>"
                            title="Yukarı taşı" aria-label="<?= esc($c['name']) ?> yukarı taşı"
                            <?= $i === 0 ? 'disabled' : '' ?>>↑</button>
                    <button type="button" class="dugme kucuk" data-sira="down" data-id="<?= (int)$c['id'] ?>"
                            title="Aşağı taşı" aria-label="<?= esc($c['name']) ?> aşağı taşı"
                            <?= $i === $sonIndeks ? 'disabled' : '' ?>>↓</button>
                  </div>
                </td>
                <td>
                  <a href="<?= esc(admin_url('categories', ['duzenle' => (int)$c['id']])) ?>"><strong><?= esc($c['name']) ?></strong></a>
                  <span class="satir-alt">/<?= esc($c['slug']) ?></span>
                </td>
                <td class="dar">
                  <span class="renk-ornek" style="background:<?= esc($c['color']) ?>" title="<?= esc($c['color']) ?>"></span>
                </td>
                <td class="dar"><?= (int)$c['post_count'] ?></td>
                <td class="dar"><?= (int)$c['in_menu'] === 1 ? admin_badge('menüde', 'bilgi') : admin_badge('gizli', 'notr') ?></td>
                <td class="dar"><?= (int)$c['active'] === 1 ? admin_badge('etkin', 'olumlu') : admin_badge('pasif', 'notr') ?></td>
                <td class="islem">
                  <a class="dugme kucuk" href="<?= esc(url_category($c)) ?>" target="_blank" rel="noopener" title="Ön yüzde aç">↗</a>
                  <a class="dugme kucuk" href="<?= esc(admin_url('categories', ['duzenle' => (int)$c['id']])) ?>">Düzenle</a>
                  <button type="button" class="dugme kucuk tehlike" data-kat-sil="<?= (int)$c['id'] ?>"
                          data-onay="<?= esc($c['name']) ?> silinecek.<?= (int)$c['post_count'] > 0 ? ' ' . (int)$c['post_count'] . ' haber kategorisiz kalacak (silinmez).' : '' ?> Onaylıyor musunuz?">Sil</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="kart">
      <div class="kart-baslik"><?= $editCat ? 'Kategoriyi düzenle' : 'Yeni kategori' ?></div>
      <form id="katForm" autocomplete="off">
        <input type="hidden" name="id" value="<?= (int)($editCat ? $editCat['id'] : 0) ?>">

        <div class="form-alan">
          <label for="k_name">Ad</label>
          <input type="text" id="k_name" name="name" required maxlength="120" value="<?= esc($editCat ? $editCat['name'] : '') ?>">
        </div>

        <div class="form-alan">
          <label for="k_slug">Adres (slug)</label>
          <input type="text" id="k_slug" name="slug" maxlength="140" value="<?= esc($editCat ? $editCat['slug'] : '') ?>">
          <span class="form-yardim">Boş bırakılırsa addan üretilir. Çakışırsa sonuna sayı eklenir.</span>
        </div>

        <div class="form-alan">
          <span class="form-etiket">Renk</span>
          <div class="renk-alan">
            <input type="color" id="k_color_secici" value="<?= esc($editCat ? $editCat['color'] : '#C0392B') ?>" aria-label="Renk seçici">
            <input type="text" id="k_color" name="color" maxlength="7" value="<?= esc($editCat ? $editCat['color'] : '#C0392B') ?>" aria-label="Renk kodu">
          </div>
          <span class="form-yardim">Kategori rozetlerinde ve tema vurgularında kullanılır.</span>
        </div>

        <div class="form-alan">
          <label class="anahtar">
            <input type="checkbox" name="in_menu" value="1" <?= (!$editCat || (int)$editCat['in_menu'] === 1) ? 'checked' : '' ?>>
            <span class="kaydirak"></span><span>Üst menüde göster</span>
          </label>
        </div>
        <div class="form-alan">
          <label class="anahtar">
            <input type="checkbox" name="active" value="1" <?= (!$editCat || (int)$editCat['active'] === 1) ? 'checked' : '' ?>>
            <span class="kaydirak"></span><span>Kategori etkin</span>
          </label>
        </div>

        <div class="dugme-grup">
          <button type="submit" class="dugme birincil"><?= $editCat ? 'Güncelle' : 'Ekle' ?></button>
          <?php if ($editCat): ?><a class="dugme" href="<?= esc(admin_url('categories')) ?>">Vazgeç</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="uyari bilgi mini">
      Kategori silindiğinde içindeki haberler <strong>silinmez</strong>; yalnızca kategorisiz kalır
      ve panelden yeni bir kategoriye taşınabilir.
    </div>
  </div>
</div>

<style>
/* admin.css'te renk örneği için hazır sınıf yok; tablo hücresinde küçük bir
   renk kutusu göstermek üzere tek kural eklendi. */
.renk-ornek { display:inline-block; width:20px; height:20px; border-radius:4px; border:1px solid var(--cizgi); vertical-align:-4px; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var listeAdres = <?= json_encode(admin_url('categories')) ?>;

  /* ------------------------------------------------ renk alanı senkronu */
  var renkSecici = document.getElementById('k_color_secici');
  var renkMetin = document.getElementById('k_color');
  if (renkSecici && renkMetin) {
    renkSecici.addEventListener('input', function () { renkMetin.value = renkSecici.value.toUpperCase(); });
    renkMetin.addEventListener('input', function () {
      var v = renkMetin.value.trim();
      if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(v)) { renkSecici.value = v; }
    });
  }

  /* ------------------------------------------------ addan slug üretimi */
  function slugla(s) {
    var harita = { 'ğ':'g','Ğ':'g','ü':'u','Ü':'u','ş':'s','Ş':'s','ı':'i','İ':'i','ö':'o','Ö':'o','ç':'c','Ç':'c','â':'a','î':'i','û':'u' };
    return String(s || '').replace(/[ğĞüÜşŞıİöÖçÇâîû]/g, function (c) { return harita[c] || c; })
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  var adAlan = document.getElementById('k_name');
  var slugAlan = document.getElementById('k_slug');
  var slugElle = slugAlan && slugAlan.value !== '';
  if (slugAlan) { slugAlan.addEventListener('input', function () { slugElle = true; }); }
  if (adAlan && slugAlan) {
    adAlan.addEventListener('input', function () { if (!slugElle) { slugAlan.value = slugla(adAlan.value); } });
  }

  /* ------------------------------------------------ kaydet */
  var form = document.getElementById('katForm');
  if (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var v = M.formVerisi(form);
      v.id = parseInt(v.id, 10) || 0;
      if (!String(v.name || '').trim()) { M.toast('err', 'Kategori adı zorunlu.'); return; }
      M.api('categories.save', v).then(function (r) {
        if (M.sonuc(r, 'Kaydedildi.')) { setTimeout(function () { location.href = listeAdres; }, 500); }
      });
    });
  }

  /* ------------------------------------------------ sıralama */
  M.qsa('[data-sira]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      M.api('categories.reorder', { id: parseInt(b.getAttribute('data-id'), 10), dir: b.getAttribute('data-sira') })
        .then(function (r) {
          if (M.sonuc(r, 'Sıra güncellendi.')) { location.reload(); } else { b.disabled = false; }
        });
    });
  });

  /* ------------------------------------------------ sil (data-onay ile korumalı) */
  M.qsa('[data-kat-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      M.api('categories.delete', { id: parseInt(b.getAttribute('data-kat-sil'), 10) }).then(function (r) {
        if (M.sonuc(r, 'Silindi.')) { setTimeout(function () { location.href = listeAdres; }, 600); }
      });
    });
  });
});
</script>
