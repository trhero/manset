<?php
/**
 * Panel — Reklam yönetimi (Faz 7g: tür ayrımı).
 *
 * İKİ TÜR:
 *   image → görsel + bağlantı + alt metin. Güvenle üretilir; `ads.creative` yeter.
 *   html  → ham kod. `ads.html` KİLİTLİ izni gerekir (yalnız Sistem Yöneticisi) VE
 *           bir yönetici onaylamadan ön yüzde basılmaz (inc/view.php → ad_render_row).
 *
 * NEDEN: `ad_slot()` HTML'i temizlemeden basar. Aynı köken üzerinde çalışan bir
 * <script>, api.php?a=public.csrf ucundan CSRF anahtarını okuyup panelde işlem
 * yapabilir — yani ham HTML yetkisi fiilen yönetici yetkisidir (5/5 danışman uyarısı,
 * USER_TYPES_PLAN §8 risk #1).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('ads.schedule');

require_once ROOT_DIR . '/inc/api/vitrin.php';   // yalnız yardımcılar (api_register orada yok)

$adminPageTitle = 'Reklamlar';
$benim   = current_user();
$hamKod  = can($benim, 'ads.html');
$gorsel  = can($benim, 'ads.creative');
$slotlar = vitrin_ad_slots();
$ogeler  = vitrin_ad_items();

$slotaGore = [];
foreach ($ogeler as $o) { $slotaGore[$o['slot_key']][] = $o; }
$onayBekleyen = 0;
foreach ($ogeler as $o) { if (!empty($o['needs_approval'])) { $onayBekleyen++; } }
?>

<div class="sayfa-basligi">
  <h1>Reklamlar</h1>
  <div class="dugme-grup">
    <?php if ($gorsel): ?>
      <button type="button" class="dugme birincil" data-reklam-yeni="image">+ Görsel reklam</button>
    <?php endif; ?>
    <?php if ($hamKod): ?>
      <button type="button" class="dugme" data-reklam-yeni="html">+ Ham kod reklamı</button>
    <?php endif; ?>
  </div>
</div>

<?php if (!$hamKod): ?>
  <div class="uyari bilgi">
    <strong>Ham HTML/JS reklam kodunu yalnız Sistem Yöneticisi girebilir.</strong>
    Bu kısıt, reklam kodunun sitede JavaScript çalıştırabilmesinden kaynaklanır —
    kod alanı bu yüzden sizin ekranınızda hiç oluşturulmaz.
    Mevcut kod reklamlarının <em>zamanlamasını</em> değiştirebilirsiniz.
  </div>
<?php endif; ?>

<?php if ($onayBekleyen > 0): ?>
  <div class="uyari warn">
    <strong><?= (int)$onayBekleyen ?> reklam kodu onay bekliyor</strong> ve ön yüzde
    <em>basılmıyor</em>. <?= $hamKod ? 'Aşağıdan onaylayabilirsiniz.' : 'Bir yöneticinin onaylaması gerekir.' ?>
  </div>
<?php endif; ?>

<?php foreach ($slotlar as $anahtar => $bilgi): ?>
  <div class="kart" data-slot="<?= esc($anahtar) ?>">
    <div class="kart-baslik">
      <span><?= esc($bilgi[0]) ?> <code><?= esc($anahtar) ?></code></span>
      <span class="mini soluk"><?= esc($bilgi[1]) ?></span>
    </div>

    <?php $liste = isset($slotaGore[$anahtar]) ? $slotaGore[$anahtar] : []; ?>
    <?php if (!$liste): ?>
      <p class="soluk mini">Bu alanda reklam yok — tema bu alanı hiç basmaz.</p>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead>
            <tr><th>Başlık</th><th>Tür</th><th>Durum</th><th>Tarih aralığı</th><th class="islem">İşlem</th></tr>
          </thead>
          <tbody>
            <?php foreach ($liste as $o): ?>
              <tr data-id="<?= (int)$o['id'] ?>">
                <td>
                  <strong><?= esc($o['title'] !== '' ? $o['title'] : '(başlıksız)') ?></strong>
                  <?php if ($o['kind'] === 'image' && $o['image_url'] !== ''): ?>
                    <span class="satir-alt"><?= esc($o['image']) ?></span>
                  <?php elseif ($o['has_html']): ?>
                    <span class="satir-alt"><?= (int)$o['html_len'] ?> karakter kod</span>
                  <?php endif; ?>
                </td>
                <td class="dar"><?= admin_badge($o['kind_label'], $o['kind'] === 'image' ? 'bilgi' : 'notr') ?></td>
                <td class="dar">
                  <?= admin_badge($o['state_label'], $o['state_tone']) ?>
                  <?php if (!empty($o['needs_approval'])): ?>
                    <?= admin_badge('onay bekliyor', 'uyari') ?>
                  <?php endif; ?>
                </td>
                <td class="dar mini">
                  <?= esc($o['starts_at'] !== '' ? tr_date($o['starts_at'], false) : '—') ?>
                  →
                  <?= esc($o['ends_at'] !== '' ? tr_date($o['ends_at'], false) : '—') ?>
                </td>
                <td class="islem">
                  <button type="button" class="dugme kucuk" data-reklam-duzenle="<?= (int)$o['id'] ?>">Düzenle</button>
                  <button type="button" class="dugme kucuk" data-reklam-onizle="<?= (int)$o['id'] ?>">Önizle</button>
                  <button type="button" class="dugme kucuk" data-reklam-ac="<?= (int)$o['id'] ?>"
                          data-on="<?= $o['active'] ? 0 : 1 ?>"><?= $o['active'] ? 'Kapat' : 'Aç' ?></button>
                  <?php if ($hamKod && $o['kind'] === 'html' && $o['has_html']): ?>
                    <button type="button" class="dugme kucuk <?= $o['approved'] ? '' : 'birincil' ?>"
                            data-reklam-onay="<?= (int)$o['id'] ?>" data-on="<?= $o['approved'] ? 0 : 1 ?>">
                      <?= $o['approved'] ? 'Onayı geri al' : 'Onayla' ?></button>
                  <?php endif; ?>
                  <button type="button" class="dugme kucuk tehlike" data-reklam-sil="<?= (int)$o['id'] ?>"
                          data-onay="Bu reklam silinecek. Onaylıyor musunuz?">Sil</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<script>
window.__REKLAM = <?= json_encode([
    'items'    => $ogeler,
    'slots'    => array_map(function ($s) { return $s[0]; }, $slotlar),
    'canHtml'  => $hamKod,
    'canImage' => $gorsel,
    'htmlMax'  => vitrin_ad_html_max(),
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
M.hazir(function () {
  'use strict';
  var CFG = window.__REKLAM;

  function ogeBul(id) {
    var r = null;
    CFG.items.forEach(function (o) { if (o.id === id) { r = o; } });
    return r;
  }

  function slotSecenek(secili) {
    return Object.keys(CFG.slots).map(function (k) {
      return '<option value="' + M.esc(k) + '"' + (k === secili ? ' selected' : '') + '>'
        + M.esc(CFG.slots[k]) + '</option>';
    }).join('');
  }

  /** Düzenleme/ekleme formu. tur = 'image' | 'html' */
  function formAc(oge, tur) {
    var d = oge || { id: 0, kind: tur, slot_key: Object.keys(CFG.slots)[0], title: '', active: 1,
                     sort: 0, starts_at: '', ends_at: '', image: '', link_url: '', alt_text: '', html: '' };
    tur = d.kind || tur;

    var h = '<input type="hidden" id="rkId" value="' + d.id + '">'
      + '<input type="hidden" id="rkKind" value="' + M.esc(tur) + '">'
      + '<div class="form-satir">'
      + '<div class="form-alan"><label for="rkBaslik">Başlık (yalnız panelde görünür)</label>'
      + '<input type="text" id="rkBaslik" maxlength="190" value="' + M.esc(d.title) + '"></div>'
      + '<div class="form-alan"><label for="rkSlot">Alan</label>'
      + '<select id="rkSlot">' + slotSecenek(d.slot_key) + '</select></div>'
      + '</div>';

    if (tur === 'image') {
      h += '<div class="form-alan"><label for="rkGorsel">Görsel</label>'
        + '<div class="esnek"><input type="text" id="rkGorsel" readonly value="' + M.esc(d.image) + '" style="flex:1">'
        + '<button type="button" class="dugme kucuk" id="rkGorselSec">Seç</button></div>'
        + (d.image_url ? '<div class="gorsel-onizleme bosluk-ust"><img src="' + M.esc(d.image_url) + '" alt=""></div>' : '')
        + '</div>'
        + '<div class="form-satir">'
        + '<div class="form-alan"><label for="rkBaglanti">Hedef bağlantı</label>'
        + '<input type="url" id="rkBaglanti" placeholder="https://…" value="' + M.esc(d.link_url) + '">'
        + '<span class="form-yardim">Yalnız https/http. Bağlantıya otomatik <code>rel="nofollow noopener sponsored"</code> eklenir.</span></div>'
        + '<div class="form-alan"><label for="rkAlt">Alt metin</label>'
        + '<input type="text" id="rkAlt" maxlength="190" value="' + M.esc(d.alt_text) + '"></div>'
        + '</div>';
    } else if (CFG.canHtml) {
      h += '<div class="uyari warn mini">Bu alana girilen kod siteye <strong>olduğu gibi</strong> eklenir '
        + 've sayfada JavaScript çalıştırabilir. Yalnız güvendiğiniz reklam ağlarının kodunu yapıştırın. '
        + 'Kod değiştirilirse onay sıfırlanır.</div>'
        + '<div class="form-alan"><label for="rkHtml">Reklam kodu</label>'
        + '<textarea id="rkHtml" class="kod-alan" rows="8" maxlength="' + CFG.htmlMax + '">' + M.esc(d.html) + '</textarea>'
        + '<span class="form-yardim">En çok ' + CFG.htmlMax + ' karakter.</span></div>';
    } else {
      h += '<div class="uyari bilgi mini">Kod alanı görüntülenemiyor — ham HTML/JS reklam kodunu '
        + 'yalnız Sistem Yöneticisi düzenleyebilir. Aşağıdaki zamanlama alanlarını değiştirebilirsiniz.</div>';
    }

    h += '<div class="form-satir">'
      + '<div class="form-alan"><label for="rkBas">Başlangıç</label>'
      + '<input type="datetime-local" id="rkBas" value="' + M.dtLocal(d.starts_at) + '"></div>'
      + '<div class="form-alan"><label for="rkBit">Bitiş</label>'
      + '<input type="datetime-local" id="rkBit" value="' + M.dtLocal(d.ends_at) + '"></div>'
      + '<div class="form-alan"><label for="rkSira">Sıra</label>'
      + '<input type="number" id="rkSira" value="' + (d.sort || 0) + '"></div>'
      + '</div>'
      + '<div class="form-alan"><label class="anahtar"><input type="checkbox" id="rkAktif"'
      + (d.active ? ' checked' : '') + '><span class="kaydirak"></span><span>Yayında</span></label></div>'
      + '<button type="button" class="dugme birincil" id="rkKaydet">Kaydet</button>';

    var govde = M.modal.ac(d.id ? 'Reklamı düzenle' : (tur === 'image' ? 'Görsel reklam' : 'Ham kod reklamı'), h);

    var gorselSec = M.qs('#rkGorselSec', govde);
    if (gorselSec) {
      gorselSec.addEventListener('click', function () {
        M.medyaSec(function (o) { M.qs('#rkGorsel', govde).value = o.filename; });
      });
    }

    M.qs('#rkKaydet', govde).addEventListener('click', function () {
      var veri = {
        id: parseInt(M.qs('#rkId', govde).value, 10) || 0,
        kind: M.qs('#rkKind', govde).value,
        slot_key: M.qs('#rkSlot', govde).value,
        title: M.qs('#rkBaslik', govde).value,
        active: M.qs('#rkAktif', govde).checked ? 1 : 0,
        sort: parseInt(M.qs('#rkSira', govde).value, 10) || 0,
        starts_at: M.dtSql(M.qs('#rkBas', govde).value),
        ends_at: M.dtSql(M.qs('#rkBit', govde).value)
      };
      var g = M.qs('#rkGorsel', govde);
      if (g) {
        veri.image = g.value;
        veri.link_url = M.qs('#rkBaglanti', govde).value;
        veri.alt_text = M.qs('#rkAlt', govde).value;
      }
      var kod = M.qs('#rkHtml', govde);
      // html alanı YALNIZ yetkiliyse gönderilir; yoksa hiç eklenmez ki sunucu
      // mevcut kodu değiştirmesin (izin yoksa alan zaten oluşturulmuyor).
      if (kod) { veri.html = kod.value; }

      M.api('ads.save', veri).then(function (r) {
        if (M.sonuc(r, 'Reklam kaydedildi.')) { M.modal.kapat(); location.reload(); }
      });
    });
  }

  M.qsa('[data-reklam-yeni]').forEach(function (b) {
    b.addEventListener('click', function () { formAc(null, b.getAttribute('data-reklam-yeni')); });
  });
  M.qsa('[data-reklam-duzenle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var o = ogeBul(parseInt(b.getAttribute('data-reklam-duzenle'), 10));
      if (o) { formAc(o, o.kind); }
    });
  });

  M.qsa('[data-reklam-onizle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var o = ogeBul(parseInt(b.getAttribute('data-reklam-onizle'), 10));
      if (!o) { return; }
      var icerik;
      if (o.kind === 'image') {
        icerik = o.image_url
          ? '<img src="' + M.esc(o.image_url) + '" alt="' + M.esc(o.alt_text) + '" style="max-width:100%">'
          : '<p>Görsel seçilmemiş.</p>';
      } else if (CFG.canHtml) {
        icerik = o.html || '<p>Kod boş.</p>';
      } else {
        icerik = '<p>Kod önizlemesi yalnız Sistem Yöneticisine gösterilir.</p>';
      }
      // Kod, panelin kendi sayfasına ENJEKTE EDİLMEZ; sandbox iframe içinde çalışır.
      var kap = M.modal.ac('Önizleme: ' + (o.title || o.slot_label),
        '<iframe class="reklam-onizleme" sandbox="allow-scripts" srcdoc=""></iframe>'
        + (o.needs_approval ? '<div class="uyari warn mini bosluk-ust">Bu reklam onaylanmadığı için '
          + 'ön yüzde <strong>basılmıyor</strong>.</div>' : ''));
      M.qs('iframe', kap).setAttribute('srcdoc',
        '<!doctype html><meta charset="utf-8"><body style="margin:0;font:14px system-ui">' + icerik);
    });
  });

  M.qsa('[data-reklam-ac]').forEach(function (b) {
    b.addEventListener('click', function () {
      M.api('ads.toggle', { id: b.getAttribute('data-reklam-ac'), on: parseInt(b.getAttribute('data-on'), 10) })
        .then(function (r) { if (M.sonuc(r, 'Durum güncellendi.')) { location.reload(); } });
    });
  });
  M.qsa('[data-reklam-onay]').forEach(function (b) {
    b.addEventListener('click', function () {
      M.api('ads.approve', { id: b.getAttribute('data-reklam-onay'), on: parseInt(b.getAttribute('data-on'), 10) })
        .then(function (r) { if (M.sonuc(r)) { location.reload(); } });
    });
  });
  M.qsa('[data-reklam-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      M.api('ads.delete', { id: b.getAttribute('data-reklam-sil') })
        .then(function (r) { if (M.sonuc(r, 'Reklam silindi.')) { location.reload(); } });
    });
  });
});
</script>

<style>
/* Reklam kodu panele enjekte edilmez; yalıtılmış iframe içinde önizlenir. */
.reklam-onizleme{width:100%;min-height:180px;border:1px dashed var(--cizgi);border-radius:var(--kose);background:#fff}
</style>
