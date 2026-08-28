<?php
/**
 * Panel — Muhabir hızlı giriş ekranı (mobil öncelikli).
 *
 * Sahadaki muhabir telefondan 60 saniyede haber geçer: başlık, spot, fotoğraf,
 * üç paragraf. Zengin editör YOK — sahada gereksiz ve yavaş.
 *
 * Muhabirde `posts.publish` izni yoktur; sunucu (inc/api/admin.php) durumu zaten
 * `pending`'e zorlar. Bu ekran da yalnız "Masaya gönder" sunar.
 *
 * Taslak 20 saniyede bir otomatik kaydedilir; bağlantı koparsa localStorage'a
 * yazılır ve şebeke gelince gönderilir — sahada kapsama zayıf olur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('posts.create');

$adminPageTitle = 'Hızlı Giriş';
$benim = current_user();

$kategoriler = all_categories();
$yayimlayabilir = can($benim, 'posts.publish');
$yayinSonrasiDuzenler = can($benim, 'posts.edit_own_published');

// Çöp kutusu (1.1-09) etkinse silinmiş haber bu ekranda hiç görünmesin.
$copSuz = (function_exists('published_supports_trash') && published_supports_trash())
    ? ' AND p.deleted_at = \'\'' : '';

// Muhabirin son gönderdikleri
$sonlar = qa('SELECT p.id, p.title, p.status, p.created_at, p.slug
              FROM posts p WHERE p.author_id = :a' . $copSuz . '
              ORDER BY p.id DESC LIMIT 10', [':a' => (int)$benim['id']]);

/* ---------------------------------------------------------------- düzenleme kipi (1.1-03)
 * "Düzenle" bağlantısı ?duzenle=ID üretiyordu ama bu dosyada okunmuyordu: muhabir kendi
 * taslağını açamıyordu. Kapı burada — üç şart birden aranır:
 *   1) kayıt var,  2) yazarı ben,  3) durumu 'published' DEĞİL.
 * Yayımlanmışı bu ekrandan açmıyoruz: düzeltmek posts.edit_own_published ister ve
 * sunucu (api_admin_load_post) zaten reddeder — kullanıcıyı boşuna forma sokmayalım.
 */
$duzenleId = inp_i('duzenle', 0);
$duzenlenen = null;
if ($duzenleId > 0) {
    $aday = q1('SELECT * FROM posts WHERE id = :i', [':i' => $duzenleId]);
    if (!$aday || (int)$aday['author_id'] !== (int)$benim['id']) {
        flash('err', 'Yalnız kendi haberlerinizi düzenleyebilirsiniz.');
        header('Location: ' . admin_url('hizli'), true, 302);
        exit;
    }
    if ($copSuz !== '' && (string)arr($aday, 'deleted_at', '') !== '') {
        flash('err', 'Bu haber silinmiş.');
        header('Location: ' . admin_url('hizli'), true, 302);
        exit;
    }
    if ((string)$aday['status'] === 'published') {
        flash('err', 'Yayımlanmış haberi bu ekrandan düzenleyemezsiniz. Düzeltme için masaya başvurun.');
        header('Location: ' . admin_url('hizli'), true, 302);
        exit;
    }
    $duzenlenen = $aday;
}

/** Gövdedeki <figure> bloklarından ek fotoğrafları çıkarır (hızlı giriş çoklu foto). */
function hizli_body_figures($html) {
    $out = [];
    if (!preg_match_all('#<figure\b[^>]*>(.*?)</figure>#is', (string)$html, $m)) { return $out; }
    foreach ($m[1] as $parca) {
        if (!preg_match('#<img\b[^>]*src=["\']([^"\']+)["\']#i', $parca, $g)) { continue; }
        $alt = preg_match('#<img\b[^>]*alt=["\']([^"\']*)["\']#i', $parca, $a) ? $a[1] : '';
        $out[] = ['url' => html_entity_decode($g[1], ENT_QUOTES, 'UTF-8'),
                  'alt' => html_entity_decode($alt, ENT_QUOTES, 'UTF-8')];
    }
    return $out;
}

/** Kaydedilmiş gövde HTML'ini hızlı giriş kutusunun düz metnine geri çevirir. */
function hizli_html_to_metin($html) {
    $s = (string)$html;
    // Ek fotoğraf blokları yazı kutusuna girmez; ayrı tutulur (bkz. hizli_body_figures).
    $s = preg_replace('#<figure\b[^>]*>.*?</figure>#is', '', $s);
    $s = preg_replace('#<br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</p\s*>#i', "\n\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
    $s = str_replace("\r\n", "\n", $s);
    return trim(preg_replace('/\n{3,}/', "\n\n", $s));
}

$dv = function ($key, $default = '') use ($duzenlenen) {
    return $duzenlenen !== null ? (string)arr($duzenlenen, $key, $default) : $default;
};
$duzenlenenGorsel = $dv('image');
?>

<div class="hizli-sarma">
  <div class="sayfa-basligi">
    <h1>Hızlı Giriş</h1>
    <a class="dugme" href="<?= esc(admin_url('posts')) ?>">Tüm haberlerim →</a>
  </div>

  <div class="uyari bilgi hizli-bilgi">
    Sahadan hızlı haber geçmek için. Kaydettikçe otomatik taslak oluşur;
    <strong>“Masaya gönder”</strong> dediğinizde editör onayına düşer.
    <?php if (!$yayimlayabilir): ?>Yayımlama kararını masa verir.<?php endif; ?>
  </div>

  <?php if ($duzenlenen): ?>
    <div class="uyari warn hizli-bilgi">
      <strong>Taslağınızı düzenliyorsunuz.</strong> Kaydedince aynı haber güncellenir.
      <a href="<?= esc(admin_url('hizli')) ?>">Yeni habere geç</a>
    </div>
  <?php endif; ?>

  <form id="hizliForm" class="hizli-form" autocomplete="off">
    <input type="hidden" name="id" id="hzId" value="<?= (int)($duzenlenen ? $duzenlenen['id'] : 0) ?>">

    <div class="form-alan">
      <label for="hzBaslik">Başlık</label>
      <input type="text" id="hzBaslik" name="title" maxlength="200" required
             placeholder="Ne oldu? Tek cümlede yazın" enterkeyhint="next"
             value="<?= esc($dv('title')) ?>">
    </div>

    <div class="form-alan">
      <label for="hzSpot">Spot <span class="soluk">(2 cümle)</span></label>
      <textarea id="hzSpot" name="spot" rows="2" maxlength="400"
                placeholder="Kim, nerede, ne zaman?"><?= esc($dv('spot')) ?></textarea>
    </div>

    <div class="form-alan">
      <label for="hzFoto">Fotoğraf</label>
      <input type="file" id="hzFoto" accept="image/*" capture="environment" multiple>
      <span class="form-yardim">Telefonda doğrudan kamerayı açar. Birden çok seçebilirsiniz:
        ilki öne çıkan görsel olur, kalanlar haberin sonuna eklenir.</span>
      <div id="hzFotoDurum" class="mini soluk"></div>
      <div id="hzFotoOnizleme" class="gorsel-onizleme" <?= $duzenlenenGorsel === '' ? 'hidden' : '' ?>>
        <img src="<?= esc($duzenlenenGorsel !== '' ? post_image($duzenlenen, 'thumb') : '') ?>" alt="">
      </div>
      <input type="hidden" name="image" id="hzImage" value="<?= esc($duzenlenenGorsel) ?>">
    </div>

    <div class="form-alan">
      <label for="hzGovde">Haber</label>
      <textarea id="hzGovde" name="body" rows="8"
                placeholder="Üç paragraf yeterli. Gördüğünüzü yazın, yorum katmayın."><?= esc(hizli_html_to_metin($dv('body'))) ?></textarea>
    </div>

    <div class="form-alan">
      <label for="hzKategori">Kategori</label>
      <select id="hzKategori" name="category_id">
        <?php foreach ($kategoriler as $k): ?>
          <option value="<?= (int)$k['id'] ?>"<?= (int)$dv('category_id', '0') === (int)$k['id'] ? ' selected' : '' ?>><?= esc($k['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="hizli-alt">
      <span id="hzDurum" class="mini soluk" role="status">—</span>
      <div class="dugme-grup">
        <button type="button" class="dugme" id="hzYeni">Yeni haber</button>
        <button type="submit" class="dugme birincil" id="hzGonder">Masaya gönder</button>
      </div>
    </div>
  </form>

  <?php if ($sonlar): ?>
    <div class="kart">
      <div class="kart-baslik">Son gönderdikleriniz</div>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr><th>Başlık</th><th>Durum</th><th>Tarih</th><th class="islem"></th></tr></thead>
          <tbody>
            <?php foreach ($sonlar as $s):
              list($etiket, $ton) = admin_status_label((string)$s['status']);
              $kilitli = ($s['status'] === 'published' && !$yayinSonrasiDuzenler); ?>
              <tr>
                <td><?= esc($s['title']) ?></td>
                <td class="dar"><?= admin_badge($etiket, $ton) ?></td>
                <td class="dar mini"><?= esc(tr_ago($s['created_at'])) ?></td>
                <td class="islem">
                  <?php if ($kilitli): ?>
                    <span class="mini soluk" title="Yayımlanmış haberi yalnız masa düzenleyebilir">yayında · kilitli</span>
                  <?php else: ?>
                    <a class="dugme kucuk" href="<?= esc(admin_url('hizli', ['duzenle' => (int)$s['id']])) ?>">Düzenle</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<script>
M.hazir(function () {
  'use strict';

  var form    = document.getElementById('hizliForm');
  var durumEl = document.getElementById('hzDurum');
  var idEl    = document.getElementById('hzId');
  var kategori = document.getElementById('hzKategori');
  var TASLAK_ANAHTAR = 'manset_hizli_taslak';      // 1.0 tek taslak (göç için okunur)
  var KUYRUK_ANAHTAR = 'manset_hizli_kuyruk';      // 1.1 çevrimdışı kuyruk (dizi)
  var KATEGORI_ANAHTAR = 'manset_hizli_kategori';
  var duzenleKipi = <?= $duzenlenen ? 'true' : 'false' ?>;
  var kirli = false, gonderiliyor = false, zamanlayici = null;
  // Bu formdaki taslağın kuyruktaki kimliği. Sunucu id'si 0 iken bile ikinci habere
  // geçildiğinde birincinin yedeği ezilmesin diye yereldir ve haber başına benzersizdir.
  var yerelId = 'y' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);

  function durum(metin, ton) {
    durumEl.textContent = metin;
    durumEl.className = 'mini ' + (ton === 'err' ? 'soluk hz-hata' : 'soluk');
  }

  function saat() {
    var d = new Date();
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }

  // Öne çıkan görselden SONRAKİ fotoğraflar: gövdenin sonuna <figure> olarak eklenir.
  // Yazı kutusuna yazılmazlar; orası düz metin ve metinToHtml her şeyi kaçışlar.
  var ekFotolar = <?= json_encode($duzenlenen ? hizli_body_figures($dv('body')) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

  /** Gövde HTML'i: yazı kutusundaki metin + ek fotoğrafların figure blokları. */
  function govdeHtml() {
    var html = metinToHtml(document.getElementById('hzGovde').value);
    ekFotolar.forEach(function (f) {
      html += '\n<figure><img src="' + M.esc(f.url) + '" alt="' + M.esc(f.alt || '') + '"></figure>';
    });
    return html;
  }

  /** Alanları düz nesneye çevirir; muhabir yayımlayamaz, durum daima taslak/onay. */
  function veriTopla(gonder) {
    return {
      id: parseInt(idEl.value, 10) || 0,
      title: document.getElementById('hzBaslik').value.trim(),
      spot: document.getElementById('hzSpot').value.trim(),
      body: govdeHtml(),
      ek_fotolar: ekFotolar,
      image: document.getElementById('hzImage').value,
      category_id: parseInt(kategori.value, 10) || 0,
      type: 'haber',
      status: gonder ? 'pending' : 'draft'
    };
  }

  /** Düz metni paragraflara çevirir (zengin editör yok). */
  function metinToHtml(metin) {
    var parcalar = String(metin || '').split(/\n{2,}/);
    return parcalar.map(function (p) {
      p = p.trim();
      if (!p) { return ''; }
      return '<p>' + p.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                      .replace(/\n/g, '<br>') + '</p>';
    }).filter(Boolean).join('\n');
  }

  /** metinToHtml'in tersi — kuyruktan geri yüklenen gövdeyi yazı kutusuna döker. */
  function htmlToMetin(html) {
    var s = String(html || '')
      .replace(/<figure[\s\S]*?<\/figure>/gi, '')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/p\s*>/gi, '\n\n')
      .replace(/<[^>]+>/g, '');
    var kutu = document.createElement('textarea');
    kutu.innerHTML = s;
    return kutu.value.replace(/\n{3,}/g, '\n\n').trim();
  }

  /* ---------------------------------------------------- çevrimdışı kuyruk (1.1-03)
   * Eskiden tek anahtar vardı: muhabir ikinci habere başlayınca birincinin yedeği
   * siliniyordu. Artık kuyruk bir DİZİ; her haber kendi yerel kimliğiyle durur ve
   * şebeke gelince sırayla gönderilip kuyruktan düşer.
   */
  function kuyrukOku() {
    var liste;
    try { liste = JSON.parse(window.localStorage.getItem(KUYRUK_ANAHTAR) || '[]'); }
    catch (e) { return []; }
    return Array.isArray(liste) ? liste : [];
  }
  function kuyrukYaz(liste) {
    try { window.localStorage.setItem(KUYRUK_ANAHTAR, JSON.stringify(liste)); }
    catch (e) { /* kota dolu olabilir, yok say */ }
  }
  /** 1.0'dan kalan tek taslağı kuyruğa taşır (bir kez). */
  function kuyrugaGoc() {
    var eski = null;
    try { eski = JSON.parse(window.localStorage.getItem(TASLAK_ANAHTAR) || 'null'); }
    catch (e) { eski = null; }
    if (!eski || !eski.title) {
      try { window.localStorage.removeItem(TASLAK_ANAHTAR); } catch (e) { }
      return;
    }
    var liste = kuyrukOku();
    eski.yerel_id = 'gocmus-' + Date.now();
    liste.push(eski);
    kuyrukYaz(liste);
    try { window.localStorage.removeItem(TASLAK_ANAHTAR); } catch (e) { }
  }
  /** Formdaki taslağı kuyruğa yazar/günceller (aynı yerel kimlik ezilir). */
  function yerelYaz() {
    var veri = veriTopla(false);
    veri.yerel_id = yerelId;
    var liste = kuyrukOku(), bulundu = false, i;
    for (i = 0; i < liste.length; i++) {
      if (liste[i] && liste[i].yerel_id === yerelId) { liste[i] = veri; bulundu = true; break; }
    }
    if (!bulundu) { liste.push(veri); }
    if (liste.length > 20) { liste = liste.slice(-20); }
    kuyrukYaz(liste);
  }
  /** Bu taslağı kuyruktan düşürür (sunucuya yazıldı). */
  function yerelSil(kimlik) {
    var hedef = kimlik || yerelId;
    kuyrukYaz(kuyrukOku().filter(function (t) { return t && t.yerel_id !== hedef; }));
  }
  /** Formdaki taslağın kuyruktaki kaydı (varsa). */
  function yerelOku() {
    var liste = kuyrukOku(), i;
    for (i = 0; i < liste.length; i++) {
      if (liste[i] && liste[i].yerel_id === yerelId) { return liste[i]; }
    }
    return null;
  }
  /** Formdaki taslağın dışındaki bekleyenler. */
  function bekleyenler() {
    return kuyrukOku().filter(function (t) { return t && t.title && t.yerel_id !== yerelId; });
  }
  /** Bekleyenleri SIRAYLA gönderir; başarılı olanı kuyruktan düşer. */
  function kuyruguGonder() {
    var sira = bekleyenler();
    if (!sira.length) { return Promise.resolve(0); }
    var sayi = 0;
    return sira.reduce(function (zincir, taslak) {
      return zincir.then(function () {
        var veri = {
          id: taslak.id || 0, title: taslak.title, spot: taslak.spot || '',
          body: taslak.body || '', image: taslak.image || '',
          category_id: taslak.category_id || 0, type: 'haber', status: taslak.status || 'draft'
        };
        return M.api('posts.save', veri).then(function (r) {
          if (r && r.ok) { yerelSil(taslak.yerel_id); sayi++; }
        }).catch(function () { /* hâlâ şebeke yok — kuyrukta kalsın */ });
      });
    }, Promise.resolve()).then(function () {
      if (sayi) { M.toast('ok', sayi + ' bekleyen haber gönderildi.'); }
      return sayi;
    });
  }

  function kaydet(gonder) {
    var veri = veriTopla(gonder);
    if (!veri.title) {
      if (gonder) { durum('Başlık gerekli.', 'err'); }
      return Promise.resolve(false);
    }
    durum(gonder ? 'Gönderiliyor…' : 'Kaydediliyor…');
    return M.api('posts.save', veri).then(function (r) {
      if (!r.ok) {
        durum('Kaydedilemedi — çevrimdışı yedeklendi', 'err');
        yerelYaz();
        if (gonder) { M.toast('err', r.error || 'Gönderilemedi.'); }
        return false;
      }
      idEl.value = r.id;
      kirli = false;
      yerelSil();
      if (gonder) {
        M.toast('ok', 'Masaya gönderildi.');
        durum('Masaya gönderildi · ' + saat());
        setTimeout(function () { location.href = M.cfg.base + '/admin/?p=hizli'; }, 900);
      } else {
        durum('Kaydedildi ' + saat());
      }
      return true;
    }).catch(function () {
      durum('Bağlantı yok — çevrimdışı yedeklendi', 'err');
      yerelYaz();
      return false;
    });
  }

  /* -------------------------------------------------- otomatik kayıt */
  function zamanlaKayit() {
    kirli = true;
    clearTimeout(zamanlayici);
    zamanlayici = setTimeout(function () { if (kirli && !gonderiliyor) { kaydet(false); } }, 20000);
    yerelYaz();
  }
  ['hzBaslik', 'hzSpot', 'hzGovde'].forEach(function (id) {
    document.getElementById(id).addEventListener('input', zamanlaKayit);
  });
  kategori.addEventListener('change', function () {
    try { window.localStorage.setItem(KATEGORI_ANAHTAR, kategori.value); } catch (e) { }
    zamanlaKayit();
  });

  /* -------------------------------------------------- fotoğraf */
  var foto = document.getElementById('hzFoto');
  foto.addEventListener('change', function () {
    if (!foto.files || !foto.files.length) { return; }
    // media.upload ucu dosya[] ile ÇOKLU kabul eder; hepsini tek istekte gönderiyoruz.
    var fd = new FormData(), i;
    for (i = 0; i < foto.files.length; i++) { fd.append('dosya[]', foto.files[i]); }
    var bilgi = document.getElementById('hzFotoDurum');
    bilgi.textContent = foto.files.length > 1
      ? foto.files.length + ' fotoğraf yükleniyor…' : 'Yükleniyor…';
    M.api('media.upload', null, { formData: fd }).then(function (r) {
      if (!r.ok || !r.items || !r.items.length) {
        bilgi.textContent = (r.error || 'Fotoğraf yüklenemedi') + ' — haberi yine de gönderebilirsiniz.';
        return;
      }
      // İlk fotoğraf öne çıkan görsel; kalanlar gövde sonuna <figure> olarak eklenir.
      var ilk = r.items[0];
      document.getElementById('hzImage').value = ilk.filename;
      var on = document.getElementById('hzFotoOnizleme');
      on.hidden = false;
      on.querySelector('img').src = ilk.thumb || ilk.url;

      r.items.slice(1).forEach(function (o) {
        ekFotolar.push({ url: o.url, alt: o.alt || '' });
      });
      bilgi.textContent = ekFotolar.length
        ? 'Fotoğraf eklendi · haber sonuna ' + ekFotolar.length + ' fotoğraf daha.'
        : 'Fotoğraf eklendi.';
      if (r.hatalar && r.hatalar.length) {
        bilgi.textContent += ' (' + r.hatalar.length + ' dosya yüklenemedi)';
      }
      zamanlaKayit();
    }).catch(function () {
      bilgi.textContent = 'Fotoğraf yüklenemedi — haberi yine de gönderebilirsiniz.';
    });
  });

  /* -------------------------------------------------- gönder / yeni */
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    gonderiliyor = true;
    kaydet(true).then(function () { gonderiliyor = false; });
  });
  document.getElementById('hzYeni').addEventListener('click', function () {
    if (kirli && !M.onay('Bu haber gönderilmediyse kuyrukta beklemeye alınacak. Yeni habere geçilsin mi?')) { return; }
    // Gönderilmemiş taslak kuyrukta KALIR; yalnız yeni bir yerel kimliğe geçeriz.
    if (kirli) { yerelYaz(); }
    yerelId = 'y' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
    form.reset();
    idEl.value = '0';
    ekFotolar = [];
    document.getElementById('hzImage').value = '';
    document.getElementById('hzFotoOnizleme').hidden = true;
    document.getElementById('hzFotoDurum').textContent = '';
    kirli = false;
    durum('Yeni haber');
    if (navigator.onLine) { kuyruguGonder(); }
  });

  /* -------------------------------------------------- açılış */
  // Düzenleme kipinde kategori sunucudan gelir; son kullanılan kategori onu ezmemeli.
  if (!duzenleKipi) {
    try {
      var sonKategori = window.localStorage.getItem(KATEGORI_ANAHTAR);
      if (sonKategori) { kategori.value = sonKategori; }
    } catch (e) { }
  }

  kuyrugaGoc();

  // Kuyrukta bekleyen ilk taslağı forma al — HEPSİ geri yüklenir. (1.1-03 hatası:
  // eskiden yalnız title/spot/id yazılıyordu, haberin METNİ ve fotoğrafı kayboluyordu.)
  if (!duzenleKipi) {
    var sira = kuyrukOku().filter(function (t) { return t && t.title; });
    if (sira.length) {
      var bekleyen = sira[0];
      yerelId = bekleyen.yerel_id || yerelId;
      document.getElementById('hzBaslik').value = bekleyen.title || '';
      document.getElementById('hzSpot').value = bekleyen.spot || '';
      document.getElementById('hzGovde').value = htmlToMetin(bekleyen.body || '');
      ekFotolar = Array.isArray(bekleyen.ek_fotolar) ? bekleyen.ek_fotolar : [];
      idEl.value = bekleyen.id || 0;
      if (bekleyen.category_id) { kategori.value = bekleyen.category_id; }
      if (bekleyen.image) {
        document.getElementById('hzImage').value = bekleyen.image;
        var geriOn = document.getElementById('hzFotoOnizleme');
        geriOn.hidden = false;
        geriOn.querySelector('img').src = (M.cfg.uploads || '') + bekleyen.image;
      }
      durum('Gönderilemeyen taslak geri yüklendi'
            + (sira.length > 1 ? ' (' + (sira.length - 1) + ' haber daha bekliyor)' : ''), 'err');
      if (navigator.onLine) { kaydet(false).then(function () { kuyruguGonder(); }); }
    }
  } else if (navigator.onLine) {
    kuyruguGonder();
  }

  window.addEventListener('online', function () {
    durum('Bağlantı geldi, gönderiliyor…');
    kuyruguGonder().then(function () {
      if (yerelOku()) { kaydet(false); }
    });
  });

  window.addEventListener('beforeunload', function (e) {
    if (!kirli) { return; }
    e.preventDefault();
    e.returnValue = '';
  });
});
</script>

<style>
/* Hızlı giriş — MOBİL ÖNCELİKLİ. Sahada tek elle kullanılır: büyük hedefler, tek sütun. */
.hizli-sarma{max-width:640px;min-width:0}
/* Dar ekranda form sarmalayıcısını aşıyordu: kutu modeli ve taşma sabitlendi. */
.hizli-form{box-sizing:border-box;max-width:100%}
.hizli-bilgi{font-size:14px}
.hizli-form{background:var(--yuzey);border:1px solid var(--cizgi);border-radius:var(--kose);padding:18px;margin-bottom:18px}
.hizli-form input[type=text],.hizli-form textarea,.hizli-form select{font-size:16px;padding:12px 13px}
.hizli-form input[type=file]{font-size:15px;padding:10px 0}
.hizli-form .form-alan{margin-bottom:18px}
.hizli-form label{font-size:15px}
.hizli-alt{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  border-top:1px solid var(--cizgi);padding-top:14px}
.hizli-alt .dugme{min-height:46px;padding:12px 20px;font-size:15px}
.hz-hata{color:var(--olumsuz)}
@media(max-width:600px){
  .hizli-sarma .sayfa-basligi{margin-bottom:12px}
  .hizli-form{padding:14px;border-radius:0;margin-left:-14px;margin-right:-14px;border-left:0;border-right:0}
  .hizli-alt{position:sticky;bottom:0;background:var(--yuzey);margin:0 -14px -14px;padding:12px 14px;z-index:5}
  .hizli-alt .dugme-grup{width:100%}
  .hizli-alt .dugme{flex:1;justify-content:center}
}
</style>
