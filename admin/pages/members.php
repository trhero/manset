<?php
/**
 * Panel — Üye yönetimi.
 *
 * Üyeler (rol = 'member') personel değildir; panele giremezler.
 * Tüm sorgular `AND role = 'member'` sabitiyle sınırlıdır — moderatörün bu ekran
 * üzerinden personel hesabına dokunması engellenir (USER_TYPES_PLAN §8 risk #7).
 *
 * Premium bir ROL DEĞİL, süreli abonelik kademesidir; süre okuma anında
 * değerlendirilir, cron gerekmez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('members.manage');

$adminPageTitle = 'Üyeler';

if (!function_exists('member_list')) {
    echo '<div class="uyari warn"><code>inc/members.php</code> yüklü değil; üyelik modülü kapalı.</div>';
    return;
}

$sayaclar = [
    'tumu'          => member_count([]),
    'free'          => member_count(['tier' => 'free']),
    'premium'       => member_count(['tier' => 'premium']),
    'dogrulanmamis' => member_count(['status' => 'unverified']),
    'askida'        => member_count(['status' => 'suspended']),
];
$bekleyen = function_exists('member_pending_verifications') ? member_pending_verifications(20) : [];
$mailVar  = function_exists('member_mail_available') ? member_mail_available() : false;
?>

<div class="sayfa-basligi">
  <h1>Üyeler</h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('users')) ?>">Personel hesapları →</a>
  </div>
</div>

<div class="izgara-4 bosluk-alt">
  <div class="sayac vurgulu"><div class="deger"><?= (int)$sayaclar['tumu'] ?></div><div class="etiket">Toplam üye</div></div>
  <div class="sayac"><div class="deger"><?= (int)$sayaclar['premium'] ?></div><div class="etiket">Abone (premium)</div></div>
  <div class="sayac"><div class="deger"><?= (int)$sayaclar['dogrulanmamis'] ?></div><div class="etiket">Doğrulanmamış</div></div>
  <div class="sayac"><div class="deger"><?= (int)$sayaclar['askida'] ?></div><div class="etiket">Askıda</div></div>
</div>

<?php if (!$mailVar): ?>
  <div class="uyari warn">
    <strong>Bu sunucuda e-posta gönderimi kullanılamıyor.</strong>
    Yeni üyelere doğrulama bağlantısı gönderilemez; aşağıdaki
    <em>Bekleyen doğrulamalar</em> bölümünden elle onaylayabilirsiniz.
    (Manşet toplu e-posta gönderim motoru içermez — bu tekil işlemsel bir e-postadır.)
  </div>
<?php endif; ?>

<?php if ($bekleyen): ?>
  <div class="kart">
    <div class="kart-baslik">Bekleyen doğrulamalar (<?= count($bekleyen) ?>)</div>
    <div class="tablo-sarma">
      <table class="tablo">
        <thead><tr><th>E-posta</th><th>Ad</th><th>Kayıt</th><th class="islem">İşlem</th></tr></thead>
        <tbody>
          <?php foreach ($bekleyen as $b): ?>
            <tr>
              <td class="tek-satir"><?= esc(arr($b, 'email', '')) ?></td>
              <td><?= esc(arr($b, 'name', '')) ?></td>
              <td class="dar mini"><?= esc(tr_date(arr($b, 'created_at', ''), false)) ?></td>
              <td class="islem">
                <button type="button" class="dugme kucuk" data-uye-dogrula="<?= (int)arr($b, 'id', 0) ?>">Doğrula</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="sekmeler" id="uyeSekme">
  <button type="button" class="etkin" data-tier="" data-status="">Tümü</button>
  <button type="button" data-tier="free" data-status="">Ücretsiz</button>
  <button type="button" data-tier="premium" data-status="">Abone</button>
  <button type="button" data-tier="" data-status="unverified">Doğrulanmamış</button>
  <button type="button" data-tier="" data-status="suspended">Askıda</button>
</div>

<div class="arac-cubugu">
  <label class="gizli" for="uyeAra">Ara</label>
  <input type="search" id="uyeAra" placeholder="E-posta veya ad…" maxlength="80">
  <button type="button" class="dugme sag" id="uyeYenile">Yenile</button>
</div>

<div class="tablo-sarma">
  <table class="tablo">
    <thead>
      <tr>
        <th>E-posta</th><th>Ad</th><th>Kademe</th><th>Bitiş</th>
        <th>Yorum</th><th>Kayıt</th><th>Durum</th><th class="islem">İşlem</th>
      </tr>
    </thead>
    <tbody id="uyeListe"><tr><td colspan="8" class="soluk">Yükleniyor…</td></tr></tbody>
  </table>
</div>
<div id="uyeSayfalama" class="sayfalama"></div>

<script>
M.hazir(function () {
  'use strict';

  var durum = { tier: '', status: '', q: '', page: 1 };

  function rozet(metin, ton) { return '<span class="rozet ' + ton + '">' + M.esc(metin) + '</span>'; }

  /** '2026-08-22 19:30:00' → '22.08.2026' ; boşsa em dash */
  function kisaTarih(s) {
    if (!s) { return '—'; }
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
    return m ? (m[3] + '.' + m[2] + '.' + m[1]) : String(s);
  }

  function ciz(r) {
    var govde = document.getElementById('uyeListe');
    var items = r.items || [];
    if (!items.length) {
      govde.innerHTML = '<tr><td colspan="8" class="soluk">Ölçütlere uyan üye yok.</td></tr>';
      document.getElementById('uyeSayfalama').innerHTML = '';
      return;
    }
    govde.innerHTML = items.map(function (u) {
      var kademe = (u.tier === 'premium') ? rozet('abone', 'olumlu') : rozet('ücretsiz', 'notr');
      var dogru = u.verified ? '' : ' ' + rozet('doğrulanmamış', 'uyari');
      var durumR = u.active ? rozet('etkin', 'olumlu') : rozet('askıda', 'olumsuz');
      return '<tr data-id="' + u.id + '">'
        + '<td class="tek-satir">' + M.esc(u.email) + dogru + '</td>'
        + '<td>' + M.esc(u.name || '—') + '</td>'
        + '<td class="dar">' + kademe + '</td>'
        + '<td class="dar mini">' + M.esc(kisaTarih(u.valid_until)) + '</td>'
        + '<td class="dar">' + (u.comment_count || 0) + '</td>'
        + '<td class="dar mini">' + M.esc(kisaTarih(u.created_at)) + '</td>'
        + '<td class="dar">' + durumR + '</td>'
        + '<td class="islem">'
        + '<button type="button" class="dugme kucuk" data-kademe="' + u.id + '">Kademe</button> '
        + (u.verified ? '' : '<button type="button" class="dugme kucuk" data-uye-dogrula="' + u.id + '">Doğrula</button> ')
        + '<button type="button" class="dugme kucuk" data-askiya="' + u.id + '" data-on="' + (u.active ? 0 : 1) + '">'
        + (u.active ? 'Askıya al' : 'Aç') + '</button> '
        + '<button type="button" class="dugme kucuk tehlike" data-uye-sil="' + u.id + '"'
        + ' data-onay="' + M.esc(u.email) + ' kalıcı olarak silinecek. Onaylıyor musunuz?">Sil</button>'
        + '</td></tr>';
    }).join('');
    bagla();

    var toplam = r.total || 0, perPage = r.per_page || 30;
    var sayfaSayisi = Math.ceil(toplam / perPage);
    var s = '';
    if (sayfaSayisi > 1) {
      s = '<ul>';
      for (var i = 1; i <= sayfaSayisi; i++) {
        s += i === durum.page
          ? '<li class="current"><span>' + i + '</span></li>'
          : '<li><a href="#" data-sayfa="' + i + '">' + i + '</a></li>';
      }
      s += '</ul>';
    }
    document.getElementById('uyeSayfalama').innerHTML = s;
    M.qsa('#uyeSayfalama a').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        durum.page = parseInt(a.getAttribute('data-sayfa'), 10) || 1;
        yukle();
      });
    });
  }

  function yukle() {
    M.api('admin_members.list', durum).then(function (r) {
      if (!r.ok) {
        document.getElementById('uyeListe').innerHTML =
          '<tr><td colspan="8" class="uyari err">' + M.esc(r.error || 'Liste alınamadı.') + '</td></tr>';
        return;
      }
      ciz(r);
    });
  }

  function bagla() {
    M.qsa('[data-kademe]').forEach(function (b) {
      b.addEventListener('click', function () {
        var id = b.getAttribute('data-kademe');
        var govde = M.modal.ac('Abonelik kademesi',
          '<div class="form-alan"><label for="kdTier">Kademe</label>'
          + '<select id="kdTier"><option value="free">Ücretsiz</option><option value="premium">Abone (premium)</option></select></div>'
          + '<div class="form-alan"><label for="kdTarih">Bitiş tarihi</label>'
          + '<input type="datetime-local" id="kdTarih">'
          + '<span class="form-yardim">Boş bırakılırsa süresiz. Süre dolduğunda erişim '
          + 'kendiliğinden kapanır; zamanlanmış görev gerekmez.</span></div>'
          + '<div class="form-alan"><label for="kdNot">Not</label>'
          + '<input type="text" id="kdNot" maxlength="190" placeholder="örn. yıllık abonelik, fatura no"></div>'
          + '<button type="button" class="dugme birincil" id="kdKaydet">Kaydet</button>');
        M.qs('#kdKaydet', govde).addEventListener('click', function () {
          M.api('admin_members.set_tier', {
            user_id: id,
            tier: M.qs('#kdTier', govde).value,
            valid_until: M.dtSql(M.qs('#kdTarih', govde).value),
            note: M.qs('#kdNot', govde).value
          }).then(function (r) {
            if (M.sonuc(r, 'Kademe güncellendi.')) { M.modal.kapat(); yukle(); }
          });
        });
      });
    });
    M.qsa('[data-uye-dogrula]').forEach(function (b) {
      b.addEventListener('click', function () {
        M.api('admin_members.verify', { user_id: b.getAttribute('data-uye-dogrula') }).then(function (r) {
          if (M.sonuc(r, 'Üye doğrulandı.')) { location.reload(); }
        });
      });
    });
    M.qsa('[data-askiya]').forEach(function (b) {
      b.addEventListener('click', function () {
        M.api('admin_members.suspend', {
          user_id: b.getAttribute('data-askiya'),
          active: parseInt(b.getAttribute('data-on'), 10)
        }).then(function (r) { if (M.sonuc(r, 'Durum güncellendi.')) { yukle(); } });
      });
    });
    M.qsa('[data-uye-sil]').forEach(function (b) {
      b.addEventListener('click', function () {
        M.api('admin_members.delete', { user_id: b.getAttribute('data-uye-sil') })
          .then(function (r) { if (M.sonuc(r, 'Üye silindi.')) { yukle(); } });
      });
    });
  }

  M.qsa('#uyeSekme button').forEach(function (b) {
    b.addEventListener('click', function () {
      M.qsa('#uyeSekme button').forEach(function (x) { x.classList.toggle('etkin', x === b); });
      durum.tier = b.getAttribute('data-tier') || '';
      durum.status = b.getAttribute('data-status') || '';
      durum.page = 1;
      yukle();
    });
  });

  var ara = document.getElementById('uyeAra'), zaman;
  ara.addEventListener('input', function () {
    clearTimeout(zaman);
    zaman = setTimeout(function () { durum.q = ara.value; durum.page = 1; yukle(); }, 300);
  });
  document.getElementById('uyeYenile').addEventListener('click', yukle);

  yukle();
});
</script>
