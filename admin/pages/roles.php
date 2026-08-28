<?php
/**
 * Panel — rol ve izin matrisi (Faz 7b, USER_TYPES_PLAN §2 ve §5).
 *
 * Ekran iki sekmeden oluşur:
 *   1) İzin matrisi  → rol kartları + rol × izin onay kutusu ızgarası
 *   2) Kullanıcıya özel → ek izinler (user_grants) + masa kapsamı (user_categories)
 *
 * Satır ve sütunlar ELLE yazılmaz; roles_permissions() ve roles_all() üzerinden
 * üretilir — böylece kod ile ekran ayrışamaz. Yazma işlemleri inc/api/roles_api.php
 * uçlarına M.api() ile gider.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('roles.manage');

// Uçlarla ORTAK yardımcılar (roles_matrix_user, roles_default_has) tek kaynakta dursun.
// api_register() çağrıları burada yalnız bir diziye yazar; yan etkisi yoktur (CONTRACTS §7).
require_once INC_DIR . '/api/roles_api.php';

$adminPageTitle = 'Roller ve İzinler';

$roller  = roles_all();
$izinler = roles_permissions();

// İzinleri grup sırasına göre kümele (grup sırası roles_permissions() yazım sırasıdır)
$gruplar = [];
foreach ($izinler as $anahtar => $tanim) { $gruplar[$tanim[1]][] = $anahtar; }

// Etkin matris + kod varsayılanları
$matris = [];
$varsayilan = [];
foreach ($roller as $rol => $meta) {
    $sanal = roles_matrix_user($rol);
    foreach ($izinler as $izin => $tanim) {
        $matris[$rol][$izin]     = roles_can($sanal, $izin);
        $varsayilan[$rol][$izin] = roles_default_has($rol, $izin);
    }
}

// Rol başına kullanıcı sayısı ve geçersiz kılma sayısı
$sayilar = [];
foreach ($roller as $rol => $meta) { $sayilar[$rol] = 0; }
foreach (qa('SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $satir) {
    $r = (string)$satir['role'];
    if (isset($sayilar[$r])) { $sayilar[$r] = (int)$satir['n']; }
}
$sapmaSayisi = [];
foreach ($roller as $rol => $meta) { $sapmaSayisi[$rol] = 0; }
foreach (role_overrides_all() as $rol => $satirlar) {
    if (!isset($sapmaSayisi[$rol])) { continue; }
    foreach ($satirlar as $izin => $izinli) {
        if (!isset($izinler[$izin]) || permission_is_locked($izin)) { continue; }  // yok sayılan kayıt
        $sapmaSayisi[$rol]++;
    }
}

// Kullanıcı sekmesi verileri
$kategoriler = qa('SELECT id, name FROM categories ORDER BY sort ASC, name ASC');
$kullanicilar = qa('SELECT id, name, email, role, active FROM users ORDER BY role ASC, name ASC');
$personel = [];
$uyeler = [];
foreach ($kullanicilar as $k) {
    if ((string)$k['role'] === 'member') { $uyeler[] = $k; } else { $personel[] = $k; }
}
$sorumlu = responsible_editor_user();

$kilitMetni = 'Bu izin yalnız Sistem Yöneticisinde bulunabilir. Panelden atanamaz; '
            . 'veritabanına elle yazılsa bile yok sayılır.';

$jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$jsIzinler = [];
foreach ($izinler as $anahtar => $tanim) {
    $jsIzinler[$anahtar] = ['label' => $tanim[0], 'group' => $tanim[1], 'locked' => (bool)$tanim[2]];
}
?>

<div class="sayfa-basligi">
  <h1>Roller ve İzinler</h1>
  <span class="soluk kucuk"><?= count($roller) ?> rol · <?= count($izinler) ?> izin anahtarı</span>
</div>

<div class="uyari bilgi">
  <strong>İzin modeli nasıl çalışır?</strong><br>
  Rol → izin haritası <strong>kodda</strong> durur (<code>inc/roles.php</code>). Bu ekranda yaptığınız
  değişiklikler haritanın <strong>üzerine binen</strong> bir katmana (<code>role_overrides</code>) yazılır ve
  okunurken bilinen izin listesine karşı yeniden doğrulanır.
  <br><br>
  <strong>Neden bazı izinler kilitli (🔒)?</strong>
  Panelde “Araçlar → veritabanı geri yükleme” var. İzinler tümüyle veritabanında tutulsaydı, saldırganın
  hazırladığı bir yedeği geri yüklemek doğrudan yetki yükseltme olurdu. Bu yüzden yönetici-eşdeğeri izinler
  (<code>ads.html</code>, <code>theme.css</code>, <code>ai.configure</code>, <code>settings.manage</code>,
  <code>users.manage</code>, <code>roles.manage</code>, <code>tools.manage</code>) <strong>koddan</strong> gelir:
  panelden atanamaz ve <code>can()</code> onlar için veritabanına <em>hiç bakmaz</em>.
  <br><br>
  <strong>İkinci kapı:</strong> hiç kimse kendisinde bulunmayan bir izni başkasına veremez —
  yazma uçları oturumdaki kullanıcının izin kümesiyle kesişim alır.
  <span class="mini soluk">(Ayrıntı: <code>USER_TYPES_PLAN.md</code> §5.2)</span>
</div>

<div class="sekmeler" id="rolSekme" role="tablist">
  <button type="button" class="etkin" data-sekme="matris">İzin matrisi</button>
  <button type="button" data-sekme="kullanici">Kullanıcıya özel izinler</button>
</div>

<!-- ============================================================ 1) matris -->
<section data-panel="matris">

  <h2>Roller</h2>
  <div class="izgara-3 bosluk-alt">
    <?php foreach ($roller as $rol => $meta): ?>
      <div class="kart sikisik">
        <strong><?= esc($meta['label']) ?></strong>
        <?= $meta['staff'] ? admin_badge('panel erişimi', 'olumlu') : admin_badge('panel yok', 'notr') ?>
        <div class="satir-alt"><code><?= esc($rol) ?></code></div>
        <p class="kucuk soluk rol-aciklama"><?= esc($meta['desc']) ?></p>
        <div class="esnek-arasi">
          <span class="kucuk">
            <strong><?= (int)$sayilar[$rol] ?></strong> kullanıcı
            <?php if ($sapmaSayisi[$rol] > 0): ?>
              · <span class="rozet uyari"><?= (int)$sapmaSayisi[$rol] ?> değişiklik</span>
            <?php endif; ?>
          </span>
          <?php if ($rol !== 'admin'): ?>
            <button type="button" class="dugme kucuk" data-rol-sifirla="<?= esc($rol) ?>"
                    data-onay="<?= esc($meta['label']) ?> rolündeki tüm izin değişiklikleri silinecek ve kod varsayılanına dönülecek. Onaylıyor musunuz?">
              Varsayılana döndür
            </button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>İzin matrisi</h2>
  <p class="kucuk soluk">
    Onay kutusunu değiştirdiğinizde kayıt <strong>anında</strong> yapılır.
    Kod varsayılanından ayrılan hücreler çerçeveyle işaretlenir; fareyle üzerine gelince varsayılan değeri yazar.
    🔒 işaretli satırlar panelden atanamaz.
    <?php if ($sorumlu): ?>
      <br><strong>Not:</strong> sistemde sorumlu yazı işleri müdürü işaretli
      (<?= esc($sorumlu['name']) ?>). Bu yüzden <code>corrections.publish</code> izni, matriste açık görünse bile
      <strong>yalnız o kişide</strong> çalışır (Basın Kanunu m.14).
    <?php endif; ?>
  </p>

  <div class="tablo-sarma">
    <table class="tablo matris" id="izinMatris">
      <thead>
        <tr>
          <th class="izin-ad" scope="col">İzin</th>
          <?php foreach ($roller as $rol => $meta): ?>
            <th class="rol-basi<?= $rol === 'admin' ? ' soluk' : '' ?>" scope="col" title="<?= esc($meta['desc']) ?>">
              <?= esc($meta['label']) ?>
              <span class="satir-alt"><code><?= esc($rol) ?></code></span>
            </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($gruplar as $grupAdi => $grupIzinleri): ?>
          <tr class="grup-satir">
            <th class="izin-ad" scope="colgroup" colspan="<?= count($roller) + 1 ?>"><?= esc($grupAdi) ?></th>
          </tr>
          <?php foreach ($grupIzinleri as $izin):
              $tanim = $izinler[$izin];
              $kilitli = (bool)$tanim[2]; ?>
            <tr class="<?= $kilitli ? 'kilitli' : '' ?>">
              <th class="izin-ad" scope="row">
                <?php if ($kilitli): ?><span class="kilit" title="<?= esc($kilitMetni) ?>" aria-label="Kilitli izin">🔒</span> <?php endif; ?>
                <?= esc($tanim[0]) ?>
                <span class="satir-alt"><code><?= esc($izin) ?></code><?= $kilitli ? ' — yalnız Sistem Yöneticisi' : '' ?></span>
              </th>
              <?php foreach ($roller as $rol => $meta):
                  $acik = !empty($matris[$rol][$izin]);
                  $vars = !empty($varsayilan[$rol][$izin]);
                  $sapma = (!$kilitli && $rol !== 'admin' && $acik !== $vars);
                  $pasif = ($kilitli || $rol === 'admin');
                  if ($kilitli)          { $ipucu = $kilitMetni; }
                  elseif ($rol === 'admin') { $ipucu = 'Sistem Yöneticisi tüm izinlere sahiptir; bu sütun değiştirilemez.'; }
                  else {
                      $ipucu = 'varsayılan: ' . ($vars ? 'açık' : 'kapalı')
                             . ($sapma ? ' — şu an ' . ($acik ? 'açık' : 'kapalı') . ' (elle değiştirildi)' : '');
                  } ?>
                <td class="hucre<?= $sapma ? ' sapma' : '' ?><?= $pasif ? ' soluk' : '' ?>">
                  <input type="checkbox"
                         data-rol="<?= esc($rol) ?>" data-izin="<?= esc($izin) ?>"
                         <?= $acik ? 'checked' : '' ?> <?= $pasif ? 'disabled' : '' ?>
                         title="<?= esc($ipucu) ?>"
                         aria-label="<?= esc($meta['label'] . ' — ' . $tanim[0]) ?>">
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ============================================================ 2) kullanıcıya özel -->
<section data-panel="kullanici" hidden>

  <div class="arac-cubugu">
    <label for="kullaniciSec">Kullanıcı</label>
    <select id="kullaniciSec" class="genis-secim">
      <option value="">— seçin —</option>
      <?php if ($personel): ?>
        <optgroup label="Personel">
          <?php foreach ($personel as $k): ?>
            <option value="<?= (int)$k['id'] ?>"><?= esc($k['name']) ?> — <?= esc(role_label($k['role'])) ?><?= (int)$k['active'] === 1 ? '' : ' (pasif)' ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>
      <?php if ($uyeler): ?>
        <optgroup label="Üyeler">
          <?php foreach ($uyeler as $k): ?>
            <option value="<?= (int)$k['id'] ?>"><?= esc($k['name']) ?> — <?= esc($k['email']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endif; ?>
    </select>
    <span class="sag kucuk soluk" id="kullaniciDurum"></span>
  </div>

  <div id="kullaniciBos" class="bos-durum">
    <span class="buyuk">☺</span>
    <h3>Kullanıcı seçin</h3>
    <p>Seçtiğiniz kişinin rolünden gelen izinleri görebilir, rolünün dışında ek izin verebilir
       ve masa editörü kategori kapsamını belirleyebilirsiniz.</p>
  </div>

  <div id="kullaniciAlan" hidden>
    <div class="kart">
      <div class="kart-baslik">
        <span id="kullaniciAd">—</span>
        <span id="kullaniciRozet"></span>
      </div>

      <h3>Rolden gelen izinler <span class="soluk kucuk">(salt okunur)</span></h3>
      <p class="kucuk soluk">Bu izinler rolün kendisinden gelir. Değiştirmek için “İzin matrisi” sekmesini kullanın.</p>
      <div id="rolIzinleri" class="rozet-liste"></div>

      <h3 class="bosluk-ust">Ek izinler</h3>
      <p class="kucuk soluk" id="ekOzet">Bu kullanıcı rolünün dışında hiçbir izne sahip değil.</p>
      <div id="ekIzinler"></div>
    </div>

    <div class="kart" id="kapsamKart">
      <div class="kart-baslik">Kategori kapsamı (masa editörü)</div>
      <p class="kucuk soluk">
        Kullanıcının hangi kategorilerde yazabileceğini sınırlar.
        <strong>Hiçbiri seçilmezse sınır yoktur</strong> — kullanıcı, rolünün izin verdiği tüm kategorilerde çalışabilir.
      </p>
      <?php if (!$kategoriler): ?>
        <div class="uyari warn mini">Henüz kategori tanımlanmamış.</div>
      <?php else: ?>
        <div class="izgara-3" id="kapsamListe">
          <?php foreach ($kategoriler as $c): ?>
            <label class="onay-satir">
              <input type="checkbox" data-kategori="<?= (int)$c['id'] ?>">
              <span><?= esc($c['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="dugme-grup bosluk-ust">
          <button type="button" class="dugme birincil" id="kapsamKaydet">Kapsamı kaydet</button>
          <button type="button" class="dugme" id="kapsamTemizle">Tümünü kaldır (sınır yok)</button>
          <span class="kucuk soluk" id="kapsamOzet"></span>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
M.hazir(function () {
  'use strict';

  var IZINLER   = <?= json_encode($jsIzinler, $jsFlags) ?>;
  var VARSAYILAN = <?= json_encode($varsayilan, $jsFlags) ?>;
  var GRUPLAR   = <?= json_encode(array_keys($gruplar), $jsFlags) ?>;
  var ROL_ADI   = <?= json_encode(array_map(function ($m) { return $m['label']; }, $roller), $jsFlags) ?>;

  /* ---------------------------------------------------------- sekmeler */
  var sekmeler = M.qsa('#rolSekme button');
  sekmeler.forEach(function (b) {
    b.addEventListener('click', function () {
      var ad = b.getAttribute('data-sekme');
      sekmeler.forEach(function (x) { x.classList.toggle('etkin', x === b); });
      M.qsa('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== ad; });
    });
  });

  /* ---------------------------------------------------------- matris hücreleri */
  function hucreIsaretle(td, cb, rol, izin) {
    var vars = !!(VARSAYILAN[rol] && VARSAYILAN[rol][izin]);
    var sapma = cb.checked !== vars;
    td.classList.toggle('sapma', sapma);
    cb.title = 'varsayılan: ' + (vars ? 'açık' : 'kapalı')
             + (sapma ? ' — şu an ' + (cb.checked ? 'açık' : 'kapalı') + ' (elle değiştirildi)' : '');
  }

  M.qsa('#izinMatris input[type=checkbox]').forEach(function (cb) {
    if (cb.disabled) { return; }
    cb.addEventListener('change', function () {
      var rol = cb.getAttribute('data-rol');
      var izin = cb.getAttribute('data-izin');
      var istenen = cb.checked;
      var td = cb.parentNode;
      cb.disabled = true;
      M.api('roles.set', { role: rol, permission: izin, allowed: istenen ? 1 : 0 }).then(function (r) {
        cb.disabled = false;
        if (!r.ok) {
          cb.checked = !istenen;               // hata → eski hâline dön
          hucreIsaretle(td, cb, rol, izin);
          M.toast('err', r.error || 'İzin kaydedilemedi.');
          return;
        }
        cb.checked = !!r.effective;            // sunucunun söylediği etkin değer geçerlidir
        hucreIsaretle(td, cb, rol, izin);
        M.toast('ok', r.message || 'İzin kaydedildi.');
      }).catch(function () {
        cb.disabled = false;
        cb.checked = !istenen;
        hucreIsaretle(td, cb, rol, izin);
        M.toast('err', 'Sunucuya ulaşılamadı.');
      });
    });
  });

  /* ---------------------------------------------------------- rolü varsayılana döndür */
  M.qsa('[data-rol-sifirla]').forEach(function (b) {
    b.addEventListener('click', function () {
      var rol = b.getAttribute('data-rol-sifirla');
      b.disabled = true;
      M.api('roles.reset', { role: rol }).then(function (r) {
        b.disabled = false;
        if (M.sonuc(r, r.message || 'Rol varsayılana döndürüldü.')) {
          setTimeout(function () { location.reload(); }, 500);
        }
      }).catch(function () { b.disabled = false; M.toast('err', 'Sunucuya ulaşılamadı.'); });
    });
  });

  /* ---------------------------------------------------------- kullanıcıya özel izinler */
  var secim   = document.getElementById('kullaniciSec');
  var alan    = document.getElementById('kullaniciAlan');
  var bosluk  = document.getElementById('kullaniciBos');
  var durum   = document.getElementById('kullaniciDurum');
  var adKutu  = document.getElementById('kullaniciAd');
  var rozetler = document.getElementById('kullaniciRozet');
  var rolListe = document.getElementById('rolIzinleri');
  var ekListe  = document.getElementById('ekIzinler');
  var ekOzet   = document.getElementById('ekOzet');
  var kapsamOzet = document.getElementById('kapsamOzet');
  var kapsamKart = document.getElementById('kapsamKart');
  var seciliId = 0;

  function rozet(metin, ton) {
    return '<span class="rozet ' + (ton || 'notr') + '">' + M.esc(metin) + '</span> ';
  }

  function ekOzetYaz(grants) {
    if (!grants.length) {
      ekOzet.innerHTML = 'Bu kullanıcı rolünün dışında hiçbir izne sahip değil.';
      return;
    }
    var adlar = grants.map(function (p) { return IZINLER[p] ? IZINLER[p].label : p; });
    ekOzet.innerHTML = '<strong>Bu kullanıcı rolünün dışında şu izinlere de sahip:</strong> '
                     + M.esc(adlar.join(', ')) + '.';
  }

  function kullaniciYukle(id) {
    seciliId = parseInt(id, 10) || 0;
    if (!seciliId) { alan.hidden = true; bosluk.hidden = false; durum.textContent = ''; return; }
    durum.textContent = 'yükleniyor…';
    M.api('users.grants', { user_id: seciliId }).then(function (r) {
      if (!r.ok) { durum.textContent = ''; M.toast('err', r.error || 'Kullanıcı okunamadı.'); return; }
      durum.textContent = '';
      bosluk.hidden = true;
      alan.hidden = false;

      var u = r.user;
      adKutu.textContent = u.name + ' · ' + u.email;
      var h = rozet(u.role_label, u.role === 'admin' ? 'olumsuz' : 'bilgi');
      h += u.active ? rozet('etkin', 'olumlu') : rozet('pasif', 'notr');
      h += u.is_staff ? rozet('panel erişimi', 'olumlu') : rozet('panel yok', 'notr');
      if (u.is_responsible) { h += rozet('sorumlu yazı işleri müdürü', 'uyari'); }
      if (u.tier === 'premium') { h += rozet('premium üye', 'ai'); }
      rozetler.innerHTML = h;

      /* rolden gelen izinler — salt okunur */
      var rp = r.role_perms || [];
      rolListe.innerHTML = rp.length
        ? rp.map(function (p) {
            return '<span class="rozet notr soluk-rozet" title="' + M.esc(p) + '">'
                 + M.esc(IZINLER[p] ? IZINLER[p].label : p) + '</span>';
          }).join(' ')
        : '<span class="kucuk soluk">Bu rolün hiçbir izni yok.</span>';

      /* ek izinler — yalnız rolde OLMAYAN ve kilitli OLMAYAN izinler */
      var grants = r.grants || [];
      if (r.is_admin) {
        ekListe.innerHTML = '<div class="uyari warn mini">Sistem Yöneticisi zaten tüm izinlere sahiptir; '
                          + 'ek izin verilemez.</div>';
        ekOzet.textContent = '';
      } else {
        var html = '';
        GRUPLAR.forEach(function (g) {
          var satirlar = '';
          Object.keys(IZINLER).forEach(function (p) {
            var d = IZINLER[p];
            if (d.group !== g || d.locked) { return; }
            if (rp.indexOf(p) !== -1) { return; }          // rolde zaten var
            var isaretli = grants.indexOf(p) !== -1;
            satirlar += '<label class="onay-satir"><input type="checkbox" data-ek-izin="' + M.esc(p) + '"'
                      + (isaretli ? ' checked' : '') + '>'
                      + '<span>' + M.esc(d.label) + ' <code class="mini">' + M.esc(p) + '</code></span></label>';
          });
          if (satirlar) {
            html += '<h4 class="grup-basligi">' + M.esc(g) + '</h4><div class="izgara-3">' + satirlar + '</div>';
          }
        });
        ekListe.innerHTML = html || '<p class="kucuk soluk">Verilebilecek başka izin kalmadı.</p>';
        ekOzetYaz(grants);
        ekIzinBagla();
      }

      /* kategori kapsamı */
      var kapsam = r.categories || [];
      M.qsa('#kapsamListe input[data-kategori]').forEach(function (cb) {
        cb.checked = kapsam.indexOf(parseInt(cb.getAttribute('data-kategori'), 10)) !== -1;
      });
      kapsamOzetYaz(kapsam.length);
      if (kapsamKart) { kapsamKart.hidden = false; }
    }).catch(function () { durum.textContent = ''; M.toast('err', 'Sunucuya ulaşılamadı.'); });
  }

  function kapsamOzetYaz(n) {
    if (!kapsamOzet) { return; }
    kapsamOzet.textContent = n > 0 ? (n + ' kategori seçili.') : 'Sınır yok — tüm kategoriler.';
  }

  function ekIzinBagla() {
    M.qsa('#ekIzinler input[data-ek-izin]').forEach(function (cb) {
      cb.addEventListener('change', function () {
        var izin = cb.getAttribute('data-ek-izin');
        var istenen = cb.checked;
        cb.disabled = true;
        M.api('users.grant_set', { user_id: seciliId, permission: izin, allowed: istenen ? 1 : 0 })
          .then(function (r) {
            cb.disabled = false;
            if (!r.ok) {
              cb.checked = !istenen;
              M.toast('err', r.error || 'İzin kaydedilemedi.');
              return;
            }
            ekOzetYaz(r.grants || []);
            M.toast('ok', r.message || 'İzin güncellendi.');
          }).catch(function () {
            cb.disabled = false; cb.checked = !istenen; M.toast('err', 'Sunucuya ulaşılamadı.');
          });
      });
    });
  }

  if (secim) {
    secim.addEventListener('change', function () { kullaniciYukle(secim.value); });
  }

  var kapsamKaydet = document.getElementById('kapsamKaydet');
  if (kapsamKaydet) {
    kapsamKaydet.addEventListener('click', function () {
      if (!seciliId) { return; }
      var ids = [];
      M.qsa('#kapsamListe input[data-kategori]').forEach(function (cb) {
        if (cb.checked) { ids.push(parseInt(cb.getAttribute('data-kategori'), 10)); }
      });
      kapsamKaydet.disabled = true;
      M.api('users.scope_set', { user_id: seciliId, category_ids: ids }).then(function (r) {
        kapsamKaydet.disabled = false;
        if (M.sonuc(r, r.message || 'Kapsam kaydedildi.')) { kapsamOzetYaz((r.categories || []).length); }
      }).catch(function () { kapsamKaydet.disabled = false; M.toast('err', 'Sunucuya ulaşılamadı.'); });
    });
  }

  var kapsamTemizle = document.getElementById('kapsamTemizle');
  if (kapsamTemizle) {
    kapsamTemizle.addEventListener('click', function () {
      M.qsa('#kapsamListe input[data-kategori]').forEach(function (cb) { cb.checked = false; });
      kapsamOzetYaz(0);
    });
  }
});
</script>

<style>
/* Sayfaya özel stil — ortak sözlükte (assets/admin.css §8.2) karşılığı OLMAYAN üç ihtiyaç:
   1) 11 sütunlu matriste yatay kaydırırken ilk sütunun sabit kalması,
   2) hücre içinde ortalanmış onay kutusu ızgarası,
   3) "kod varsayılanından sapmış hücre" işareti.
   Genel sınıf sözlüğüne eklenmedi; yalnız bu ekranda anlamlıdır. */

/* — sabit ilk sütun (mobilde de izin adı görünür kalsın) — */
#izinMatris { min-width: 0; }
#izinMatris .izin-ad {
  position: sticky; left: 0; z-index: 2;
  background: var(--yuzey); text-transform: none; letter-spacing: 0;
  white-space: normal; color: var(--metin); font-size: 13px;
  min-width: 240px; border-right: 1px solid var(--cizgi);
}
#izinMatris thead .izin-ad { background: var(--yuzey-2); z-index: 3; }
#izinMatris .grup-satir .izin-ad {
  background: var(--yuzey-2); color: var(--soluk); font-size: 11px;
  text-transform: uppercase; letter-spacing: .08em; font-weight: 700;
}
#izinMatris tbody tr:hover .izin-ad { background: var(--yuzey-2); }

/* — rol sütun başlıkları: uzun adlar sarılabilsin (ortak th nowrap + uppercase) — */
#izinMatris .rol-basi {
  white-space: normal; text-transform: none; letter-spacing: 0;
  min-width: 96px; max-width: 116px; text-align: center;
  color: var(--metin); font-size: 12px; line-height: 1.25;
}
#izinMatris .rol-basi .satir-alt { text-align: center; }

/* — hücreler — */
#izinMatris td.hucre { text-align: center; padding: 6px 4px; }
#izinMatris td.hucre input { width: 17px; height: 17px; margin: 0; cursor: pointer; }
#izinMatris td.hucre input:disabled { cursor: not-allowed; opacity: .45; }
#izinMatris tr.kilitli .izin-ad,
#izinMatris td.hucre.soluk { opacity: .62; }
#izinMatris .kilit { cursor: help; }

/* — kod varsayılanından sapan hücre — */
#izinMatris td.hucre.sapma {
  box-shadow: inset 0 0 0 2px var(--uyari-renk);
  border-radius: 4px; background: #fffaef;
}

/* — rol kartı ve kullanıcı seçici — */
.rol-aciklama { margin: 8px 0; }
.genis-secim { min-width: 280px; }

/* — kullanıcı sekmesi: rozet listesi ve grup başlıkları — */
.rozet-liste { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.rozet-liste .soluk-rozet { opacity: .7; }
.grup-basligi { font-size: 12px; text-transform: uppercase; letter-spacing: .06em;
  color: var(--soluk); margin: 14px 0 6px; }
</style>
