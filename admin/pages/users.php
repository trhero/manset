<?php
/**
 * Panel — kullanıcı yönetimi.
 *
 * Faz 7b: rol listesi artık ELLE yazılmaz, roles_all() üzerinden gelir (10 rol).
 * İki yeni alan eklendi:
 *   is_staff        → panele girebilir mi (rolün yanında ÇİFT KİLİT; üyeler asla giremez)
 *   is_responsible  → Basın Kanunu m.14 sorumlu yazı işleri müdürü işareti (tek kişi)
 *
 * İzin matrisi bu ekranda DEĞİL, "Roller ve İzinler" (admin/pages/roles.php)
 * ekranındadır; burada yalnız hesap kaydı tutulur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('users.manage');

$adminPageTitle = 'Kullanıcılar';
$me = current_user();

/** Rol açılır listesi: tanımlayıcı => arayüz adı (kaynak: inc/roles.php). */
function users_roles() {
    $out = [];
    foreach (roles_all() as $anahtar => $meta) { $out[$anahtar] = $meta['label']; }
    return $out;
}

/** Rolün açıklaması (form altındaki yardım metni ve bilgi kutusu için). */
function users_role_descs() {
    $out = [];
    foreach (roles_all() as $anahtar => $meta) { $out[$anahtar] = $meta['desc']; }
    return $out;
}

$editId = inp_i('duzenle', 0);
$editUser = $editId
    ? q1('SELECT id, name, email, role, active, is_staff, is_responsible FROM users WHERE id = :i', [':i' => $editId])
    : null;

// ---------------------------------------------------------------- kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'save') {
    csrf_guard();
    $id = inp_i('id', 0);
    $name = sanitize_line((string)inp('name', ''), 80);
    $email = mb_strtolower(trim((string)inp('email', '')), 'UTF-8');
    $role = (string)inp('role', 'yazar');
    $active = inp('active') ? 1 : 0;
    $pass = (string)inp('password', '');
    $errors = [];

    $eski = $id > 0 ? q1('SELECT id, role, active, is_staff, is_responsible FROM users WHERE id = :i', [':i' => $id]) : null;

    // Onay kutuları gönderilmediğinde "kapalı" sayılır; ancak bu ekranı kullanmayan
    // eski/otomatik gönderimler (kurulum betiği, testler) alanı hiç göndermez.
    // Bu yüzden formda gizli bir işaret alanı taşınır: yoksa makul varsayılan uygulanır.
    $alanlarGeldi = (string)inp('fields', '') === 'v2';
    if ($alanlarGeldi) {
        $isStaff = inp('is_staff') ? 1 : 0;
        $isResponsible = inp('is_responsible') ? 1 : 0;
    } else {
        $isStaff = $eski ? (int)arr($eski, 'is_staff', 1) : (role_is_staff($role) ? 1 : 0);
        $isResponsible = $eski ? (int)arr($eski, 'is_responsible', 0) : 0;
    }

    // Üye rolü panele ASLA giremez (rol + is_staff çift kilidi, USER_TYPES_PLAN §0)
    if (!role_is_staff($role)) { $isStaff = 0; $isResponsible = 0; }

    if ($name === '') { $errors[] = 'Ad soyad zorunlu.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Geçerli bir e-posta girin.'; }
    if (!role_exists($role)) { $errors[] = 'Geçersiz rol.'; }
    if ($id === 0 && mb_strlen($pass) < 8) { $errors[] = 'Yeni kullanıcı için en az 8 karakterlik parola gerekli.'; }
    if ($pass !== '' && mb_strlen($pass) < 8) { $errors[] = 'Parola en az 8 karakter olmalı.'; }

    $clash = q1('SELECT id FROM users WHERE email = :e AND id <> :i', [':e' => $email, ':i' => $id]);
    if ($clash) { $errors[] = 'Bu e-posta başka bir kullanıcıya ait.'; }

    // Kendi yöneticiliğini ya da son yöneticiyi düşürme koruması
    if ($id > 0 && ($role !== 'admin' || !$active)) {
        $adminCount = (int)qv('SELECT COUNT(*) FROM users WHERE role = \'admin\' AND active = 1 AND id <> :i', [':i' => $id], 0);
        $wasAdmin = (string)qv('SELECT role FROM users WHERE id = :i', [':i' => $id], '') === 'admin';
        if ($wasAdmin && $adminCount === 0) { $errors[] = 'Sistemde en az bir etkin yönetici kalmalı.'; }
    }
    if ($id === (int)$me['id'] && !$active) { $errors[] = 'Kendi hesabınızı pasifleştiremezsiniz.'; }
    // Yetki yükseltme kapısı: kimse kendi rolünü değiştiremez.
    if ($id === (int)$me['id'] && $eski && (string)$eski['role'] !== $role) {
        $errors[] = 'Kendi rolünüzü değiştiremezsiniz. Bunu başka bir yönetici yapmalıdır.';
    }
    if ($id === (int)$me['id'] && $eski && (int)arr($eski, 'is_staff', 1) === 1 && $isStaff === 0) {
        $errors[] = 'Kendi panel erişiminizi kapatamazsınız.';
    }

    // YETKİ YÜKSELTME KAPISI (denetim tur 2, B01 — kritik).
    // `users.manage` kilitli bir izindir ama kod haritasında chief_editor'e de
    // açıktır (ekibine hesap açabilmesi için). Kapı olmadan chief_editor kendine
    // `admin` rolünde ikinci bir hesap açıp sistemi tümüyle devralabiliyordu.
    // KURAL: kimse KENDİSİNDE OLMAYAN bir yetkiyi başkasına veremez.
    if ((string)arr($me, 'role', '') !== 'admin') {
        if ($role === 'admin') {
            $errors[] = 'Yönetici hesabı yalnız bir yönetici açabilir.';
        }
        if ($eski && (string)arr($eski, 'role', '') === 'admin') {
            $errors[] = 'Yönetici hesabını yalnız bir yönetici düzenleyebilir.';
        }
        if (function_exists('roles_permissions_for')) {
            $bende  = roles_permissions_for((string)arr($me, 'role', ''));
            $hedefte = roles_permissions_for($role);
            $fazla  = array_values(array_diff($hedefte, $bende));
            if ($fazla) {
                $errors[] = 'Kendinizde olmayan yetkileri içeren bir rol atayamazsınız: '
                          . implode(', ', array_slice($fazla, 0, 4))
                          . (count($fazla) > 4 ? '…' : '') . '.';
            }
        }
        // Sorumlu müdür işareti yalnız yöneticinin atayabileceği bir yasal roldür.
        if ($isResponsible === 1 && (!$eski || (int)arr($eski, 'is_responsible', 0) !== 1)) {
            $errors[] = 'Sorumlu müdür işaretini yalnız bir yönetici atayabilir.';
        }
    }

    if ($errors) {
        foreach ($errors as $e) { flash('err', $e); }
        header('Location: ' . admin_url('users', $id ? ['duzenle' => $id] : []), true, 302);
        exit;
    }

    $data = [
        'name' => $name, 'email' => $email, 'role' => $role, 'active' => $active,
        'is_staff' => $isStaff, 'is_responsible' => $isResponsible,
    ];
    if ($pass !== '') { $data['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT); }
    // Parola değişince o kullanıcının TÜM oturumları düşer (damga tutmaz).
    // Kendi parolasını değiştiren kişi kapı dışarı edilmesin diye damgası
    // burada tazelenir (denetim tur 2, B04).
    $kendiParolasi = ($pass !== '' && $id === (int)$me['id']);

    if ($id > 0) {
        db_update('users', $data, 'id = :id', [':id' => $id]);
        flash('ok', 'Kullanıcı güncellendi.');
    } else {
        $data['created_at'] = now();
        $id = db_insert('users', $data);
        flash('ok', 'Kullanıcı eklendi.');
    }

    // Sorumlu yazı işleri müdürü TEK kişidir: bu kişi işaretlendiyse diğerlerinin işareti kalkar.
    if ($isResponsible === 1 && $id > 0) {
        q('UPDATE users SET is_responsible = 0 WHERE id <> :i', [':i' => (int)$id]);
        flash('warn', 'Sorumlu yazı işleri müdürü işareti bu kişiye taşındı; varsa önceki işaret kaldırıldı.');
    }

    // Kendi parolasını değiştirdiyse oturumunu yeni damgayla tazele; yoksa
    // bir sonraki istekte current_user() onu düşürür ve giriş ekranına atar.
    if ($kendiParolasi && function_exists('session_bind_user')) {
        session_regenerate_id(true);
        session_bind_user((int)$id, (string)$data['pass_hash']);
    }

    if (function_exists('roles_cache_reset')) { roles_cache_reset(); }
    if (function_exists('cache_flush')) { cache_flush(); }

    header('Location: ' . admin_url('users'), true, 302);
    exit;
}

// ---------------------------------------------------------------- sil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'delete') {
    csrf_guard();
    $id = inp_i('id', 0);
    if ($id === (int)$me['id']) {
        flash('err', 'Kendi hesabınızı silemezsiniz.');
    } else {
        $target = q1('SELECT role FROM users WHERE id = :i', [':i' => $id]);
        $adminCount = (int)qv('SELECT COUNT(*) FROM users WHERE role = \'admin\' AND active = 1 AND id <> :i', [':i' => $id], 0);
        if ($target && $target['role'] === 'admin' && $adminCount === 0) {
            flash('err', 'Sistemde en az bir etkin yönetici kalmalı.');
        } elseif ($target && (string)$target['role'] === 'admin'
                  && (string)arr($me, 'role', '') !== 'admin') {
            // B01'in silme tarafı: yönetici hesabına yalnız yönetici dokunur.
            flash('err', 'Yönetici hesabını yalnız bir yönetici silebilir.');
        } else {
            $postCount = (int)qv('SELECT COUNT(*) FROM posts WHERE author_id = :i', [':i' => $id], 0);
            q('DELETE FROM users WHERE id = :i', [':i' => $id]);
            // Kullanıcıya bağlı izin/kapsam kayıtları da düşer (yetim satır kalmasın)
            foreach (['user_grants', 'user_categories'] as $tablo) {
                try { q('DELETE FROM ' . $tablo . ' WHERE user_id = :i', [':i' => $id]); }
                catch (Throwable $e) { /* tablo yoksa göç uygulanmamıştır */ }
            }
            // Haberler sahipsiz kalmasın: mevcut yöneticiye devret
            if ($postCount > 0) {
                q('UPDATE posts SET author_id = :new WHERE author_id = :old', [':new' => (int)$me['id'], ':old' => $id]);
                flash('warn', $postCount . ' haberin yazarı size devredildi.');
            }
            if (function_exists('roles_cache_reset')) { roles_cache_reset(); }
            flash('ok', 'Kullanıcı silindi.');
        }
    }
    header('Location: ' . admin_url('users'), true, 302);
    exit;
}

// ---------------------------------------------------------------- liste
$sekme = (string)inp('sekme', 'personel');
if (!in_array($sekme, ['personel', 'uye'], true)) { $sekme = 'personel'; }
$ara = sanitize_line((string)inp('ara', ''), 80);
$sayfa = max(1, inp_i('sayfa', 1));
$perPage = 30;

$kosul = $sekme === 'uye' ? "u.role = 'member'" : "u.role <> 'member'";
$par = [];
if ($ara !== '') {
    // İki ayrı yer tutucu: aynı adlı parametrenin iki kez kullanımı MySQL'de
    // emülasyon kapalıyken desteklenmiyor.
    $kosul .= ' AND (u.name LIKE :q1 OR u.email LIKE :q2)';
    $par[':q1'] = '%' . $ara . '%';
    $par[':q2'] = '%' . $ara . '%';
}

$toplam = (int)qv('SELECT COUNT(*) FROM users u WHERE ' . $kosul, $par, 0);
$personelSayi = (int)qv('SELECT COUNT(*) FROM users WHERE role <> \'member\'', [], 0);
$uyeSayi = (int)qv('SELECT COUNT(*) FROM users WHERE role = \'member\'', [], 0);

$users = qa('SELECT u.id, u.name, u.email, u.role, u.active, u.is_staff, u.is_responsible, u.created_at,
    (SELECT COUNT(*) FROM posts p WHERE p.author_id = u.id) AS post_count
    FROM users u WHERE ' . $kosul . ' ORDER BY u.role ASC, u.name ASC
    LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($sayfa - 1) * $perPage), $par);

$rolAdlari = users_roles();
$rolAciklamalari = users_role_descs();
$sorumlu = responsible_editor_user();
// Sorumlu müdür işaretli kişinin rolü düzeltme yayımlayabiliyor mu? (footgun uyarısı)
$sorumluYetkili = $sorumlu
    ? in_array('corrections.publish', roles_permissions_for((string)qv('SELECT role FROM users WHERE id = :i', [':i' => (int)$sorumlu['id']], '')), true)
    : true;

$jsFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$staffHarita = [];
foreach (roles_all() as $r => $m) { $staffHarita[$r] = (bool)$m['staff']; }
?>

<div class="sayfa-basligi">
  <h1>Kullanıcılar</h1>
  <a class="dugme birincil" href="<?= esc(admin_url('users', ['duzenle' => 0, 'yeni' => 1])) ?>">+ Yeni kullanıcı</a>
</div>

<?php if ($sorumlu && !$sorumluYetkili): ?>
  <div class="uyari err" role="alert">
    <strong>Dikkat:</strong> sorumlu yazı işleri müdürü olarak işaretli kişinin
    (<?= esc($sorumlu['name']) ?>) rolünde <code>corrections.publish</code> izni yok.
    Bu durumda düzeltme/cevap yazısını <strong>hiç kimse</strong> yayımlayamaz.
    İşareti yetkili bir kişiye taşıyın ya da bu kişinin rolüne izni verin.
  </div>
<?php endif; ?>

<nav class="sekmeler" aria-label="Kullanıcı türü">
  <a href="<?= esc(admin_url('users', ['sekme' => 'personel'])) ?>" class="<?= $sekme === 'personel' ? 'etkin' : '' ?>">
    Personel <span class="soluk">(<?= $personelSayi ?>)</span></a>
  <a href="<?= esc(admin_url('users', ['sekme' => 'uye'])) ?>" class="<?= $sekme === 'uye' ? 'etkin' : '' ?>">
    Üyeler <span class="soluk">(<?= $uyeSayi ?>)</span></a>
</nav>

<div class="iki-kolon">
  <div>
    <form class="arac-cubugu" method="get" action="<?= esc(base_url()) ?>/admin/">
      <input type="hidden" name="p" value="users">
      <input type="hidden" name="sekme" value="<?= esc($sekme) ?>">
      <input type="search" name="ara" value="<?= esc($ara) ?>" placeholder="Ad veya e-posta ara…" aria-label="Kullanıcı ara">
      <button class="dugme" type="submit">Ara</button>
      <?php if ($ara !== ''): ?>
        <a class="dugme kucuk" href="<?= esc(admin_url('users', ['sekme' => $sekme])) ?>">Temizle</a>
      <?php endif; ?>
      <span class="sag kucuk soluk"><?= $toplam ?> kayıt</span>
    </form>

    <?php if (!$users): ?>
      <div class="bos-durum">
        <span class="buyuk">☺</span>
        <h3><?= $ara !== '' ? 'Eşleşen kullanıcı yok' : 'Kayıt yok' ?></h3>
        <p><?= $sekme === 'uye' ? 'Henüz kayıtlı üye bulunmuyor.' : 'Bu listede kullanıcı yok.' ?></p>
      </div>
    <?php else: ?>
      <div class="tablo-sarma">
        <table class="tablo">
          <thead><tr>
            <th>Ad</th><th>E-posta</th><th>Rol</th><th>Panel</th>
            <?php if ($sekme === 'uye'): ?><th>Kademe</th><?php else: ?><th>Haber</th><?php endif; ?>
            <th>Durum</th><th class="islem">İşlem</th>
          </tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <tr>
                <td>
                  <strong><?= esc($u['name']) ?></strong>
                  <?= (int)$u['id'] === (int)$me['id'] ? ' <span class="rozet bilgi">siz</span>' : '' ?>
                  <?php if ((int)arr($u, 'is_responsible', 0) === 1): ?>
                    <span class="rozet uyari" title="Basın Kanunu m.14 — düzeltme/cevap yayımlamakla yükümlü kişi">sorumlu müdür</span>
                  <?php endif; ?>
                </td>
                <td class="tek-satir"><?= esc($u['email']) ?></td>
                <td><?= admin_badge(role_label($u['role']),
                        $u['role'] === 'admin' ? 'olumsuz' : ($u['role'] === 'member' ? 'notr' : 'bilgi')) ?></td>
                <td class="dar"><?= (int)arr($u, 'is_staff', 0) === 1
                        ? admin_badge('var', 'olumlu') : admin_badge('yok', 'notr') ?></td>
                <?php if ($sekme === 'uye'): ?>
                  <td class="dar"><?= member_tier($u) === 'premium'
                        ? admin_badge('premium', 'ai') : admin_badge('ücretsiz', 'notr') ?></td>
                <?php else: ?>
                  <td class="dar"><?= (int)$u['post_count'] ?></td>
                <?php endif; ?>
                <td class="dar"><?= (int)$u['active'] === 1 ? admin_badge('etkin', 'olumlu') : admin_badge('pasif', 'notr') ?></td>
                <td class="islem">
                  <a class="dugme kucuk" href="<?= esc(admin_url('users', ['duzenle' => $u['id'], 'sekme' => $sekme])) ?>">Düzenle</a>
                  <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                    <form method="post" class="satir-ici">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                      <button class="dugme kucuk tehlike" data-onay="<?= esc($u['name']) ?> silinecek. Haberleri size devredilecek. Onaylıyor musunuz?">Sil</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?= admin_paginate($toplam, $perPage, $sayfa, 'users', ['sekme' => $sekme, 'ara' => $ara]) ?>
    <?php endif; ?>
  </div>

  <div class="kart">
    <div class="kart-baslik"><?= $editUser ? 'Kullanıcıyı düzenle' : 'Yeni kullanıcı' ?></div>
    <form method="post" id="kullaniciForm">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <?php /* Yeni onay kutularının gönderildiğini bildirir; yoksa makul varsayılan uygulanır. */ ?>
      <input type="hidden" name="fields" value="v2">
      <input type="hidden" name="id" value="<?= (int)($editUser ? $editUser['id'] : 0) ?>">

      <div class="form-alan">
        <label for="u_name">Ad soyad</label>
        <input type="text" id="u_name" name="name" required maxlength="80" value="<?= esc($editUser ? $editUser['name'] : '') ?>">
      </div>
      <div class="form-alan">
        <label for="u_email">E-posta</label>
        <input type="email" id="u_email" name="email" required maxlength="190" value="<?= esc($editUser ? $editUser['email'] : '') ?>">
      </div>
      <div class="form-alan">
        <label for="u_role">Rol</label>
        <select id="u_role" name="role" <?= ($editUser && (int)$editUser['id'] === (int)$me['id']) ? 'disabled' : '' ?>>
          <?= admin_options($rolAdlari, $editUser ? $editUser['role'] : 'yazar') ?>
        </select>
        <?php if ($editUser && (int)$editUser['id'] === (int)$me['id']): ?>
          <input type="hidden" name="role" value="<?= esc($editUser['role']) ?>">
          <p class="form-yardim">Kendi rolünüzü değiştiremezsiniz; bunu başka bir yönetici yapmalıdır.</p>
        <?php else: ?>
          <p class="form-yardim" id="rolAciklama"></p>
        <?php endif; ?>
      </div>
      <div class="form-alan">
        <label for="u_pass">Parola</label>
        <input type="password" id="u_pass" name="password" minlength="8" autocomplete="new-password"
               placeholder="<?= $editUser ? 'Değiştirmek istemiyorsanız boş bırakın' : 'En az 8 karakter' ?>">
      </div>

      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" name="active" value="1" <?= (!$editUser || (int)$editUser['active'] === 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Hesap etkin</span>
        </label>
      </div>

      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" id="u_staff" name="is_staff" value="1"
                 <?= (!$editUser || (int)arr($editUser, 'is_staff', 1) === 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Yönetim paneline girebilir</span>
        </label>
        <p class="form-yardim" id="staffYardim">
          Panel girişi <strong>çift kilitlidir</strong>: rolün <code>admin.access</code> izni olmalı
          <em>ve</em> bu anahtar açık olmalıdır. <strong>Üye</strong> rolünde anahtar zorla kapalıdır.
        </p>
      </div>

      <div class="form-alan">
        <label class="anahtar">
          <input type="checkbox" id="u_responsible" name="is_responsible" value="1"
                 <?= ($editUser && (int)arr($editUser, 'is_responsible', 0) === 1) ? 'checked' : '' ?>>
          <span class="kaydirak"></span><span>Sorumlu yazı işleri müdürü</span>
        </label>
        <p class="form-yardim">
          Basın Kanunu m.14 uyarınca düzeltme-cevabı yayımlamakla yükümlü kişi.
          İşaretlendiğinde düzeltme yayımlama yetkisi <strong>yalnız bu kişide</strong> çalışır.
          İşaret <strong>tek kişide</strong> bulunabilir; yenisi işaretlenince eskisi kalkar.
          <?php if ($sorumlu): ?>
            <br><span class="soluk">Şu an işaretli: <strong><?= esc($sorumlu['name']) ?></strong></span>
          <?php else: ?>
            <br><span class="soluk">Şu an kimse işaretli değil; kural uygulanmıyor.</span>
          <?php endif; ?>
        </p>
      </div>

      <div class="dugme-grup">
        <button type="submit" class="dugme birincil"><?= $editUser ? 'Güncelle' : 'Ekle' ?></button>
        <?php if ($editUser): ?><a class="dugme" href="<?= esc(admin_url('users', ['sekme' => $sekme])) ?>">Vazgeç</a><?php endif; ?>
        <?php if ($editUser && admin_page_exists('roles')): ?>
          <a class="dugme kucuk" href="<?= esc(admin_url('roles')) ?>">İzinleri düzenle →</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="uyari bilgi bosluk-ust mini">
      <strong>Rol yetkileri</strong>
      <dl class="rol-sozluk">
        <?php foreach ($rolAdlari as $anahtar => $etiket): ?>
          <dt><?= esc($etiket) ?> <code><?= esc($anahtar) ?></code>
            <?= role_is_staff($anahtar) ? '' : '<span class="rozet notr">panel yok</span>' ?></dt>
          <dd><?= esc($rolAciklamalari[$anahtar]) ?></dd>
        <?php endforeach; ?>
      </dl>
      <?php if (admin_page_exists('roles')): ?>
        Her rolün tam izin listesi ve düzenlenebilir matris:
        <a href="<?= esc(admin_url('roles')) ?>">Roller ve İzinler</a>.
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
M.hazir(function () {
  'use strict';

  var ACIKLAMA = <?= json_encode($rolAciklamalari, $jsFlags) ?>;
  var STAFF    = <?= json_encode($staffHarita, $jsFlags) ?>;

  var rol = document.getElementById('u_role');
  var staff = document.getElementById('u_staff');
  var aciklama = document.getElementById('rolAciklama');
  var staffYardim = document.getElementById('staffYardim');

  /* Rol değişince: açıklamayı yaz, personel olmayan rolde panel anahtarını kilitle. */
  function rolDegisti() {
    if (!rol) { return; }
    var r = rol.value;
    if (aciklama) { aciklama.textContent = ACIKLAMA[r] || ''; }
    if (!staff) { return; }
    if (STAFF[r] === false) {
      staff.checked = false;
      staff.disabled = true;
      if (staffYardim) { staffYardim.classList.add('kilitli-yardim'); }
    } else {
      staff.disabled = false;
      if (staffYardim) { staffYardim.classList.remove('kilitli-yardim'); }
    }
  }

  if (rol) { rol.addEventListener('change', rolDegisti); }
  rolDegisti();
});
</script>

<style>
/* Sayfaya özel stil — ortak sözlükte (assets/admin.css §8.2) karşılığı olmayan iki ihtiyaç:
   satır içi silme formu ve rol açıklama sözlüğü. */
.satir-ici { display: inline; }
.rol-sozluk { margin: 8px 0 0; }
.rol-sozluk dt { font-weight: 600; margin-top: 7px; }
.rol-sozluk dd { margin: 1px 0 0; color: var(--soluk); }
.kilitli-yardim { opacity: .6; }
</style>
