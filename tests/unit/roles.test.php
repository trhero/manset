<?php
/**
 * inc/roles.php — yetki çekirdeği.
 * Kullanıcılar sahte dizilerdir; DB yalnız rol geçersiz kılmaları ve üyelik için kullanılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/roles.php';

/** Sahte personel kullanıcısı — is_staff varsayılan 1. */
function u_personel($role, $ek = []) {
    return array_merge(['id' => 900, 'role' => $role, 'is_staff' => 1, 'is_responsible' => 0], $ek);
}
/** Sahte üye. */
function u_uye($ek = []) {
    return array_merge(['id' => 901, 'role' => 'member', 'is_staff' => 0], $ek);
}

// ------------------------------------------------------------ admin
test('roles_can: admin her izne sahiptir', function () {
    $admin = u_personel('admin');
    foreach (array_keys(roles_permissions()) as $perm) {
        if (!roles_can($admin, $perm)) { dogru(false, 'admin şu izni alamadı: ' . $perm); return; }
    }
    dogru(true, 'admin tüm izinleri aldı');
    dogru(roles_can($admin, 'settings.manage'), 'kilitli izin de admin`de olmalı');
    dogru(roles_can($admin, 'ads.html'));
});

// ------------------------------------------------------------ fail-closed
test('roles_can: bilinmeyen rol fail-closed', function () {
    $hayalet = u_personel('supervizor');
    yanlis(roles_can($hayalet, 'posts.view'));
    yanlis(roles_can($hayalet, 'admin.access'), 'bilinmeyen rol personel sayılmaz');
    yanlis(roles_can($hayalet, 'settings.manage'));
    yanlis(roles_can(null, 'posts.view'), 'kullanıcı yoksa hiçbir izin yok');
    yanlis(roles_can([], 'posts.view'), 'boş dizi izin almamalı');
    yanlis(roles_can('admin', 'posts.view'), 'dizi olmayan girdi izin almamalı');
    yanlis(roles_can(u_personel('editor'), 'olmayan.izin'), 'tanımsız izin anahtarı false dönmeli');
});

// ------------------------------------------------------------ rol kümeleri
test('roles_can: rol kümeleri USER_TYPES_PLAN ile uyumlu', function () {
    $editor = u_personel('editor');
    dogru(roles_can($editor, 'posts.publish'), 'editör yayımlar');
    dogru(roles_can($editor, 'posts.edit_any'));
    yanlis(roles_can($editor, 'settings.manage'), 'editör ayarlara dokunamaz');
    yanlis(roles_can($editor, 'ads.html'), 'editör ham reklam kodu giremez');
    yanlis(roles_can($editor, 'users.manage'));

    $yazar = u_personel('yazar');
    dogru(roles_can($yazar, 'posts.edit_own'));
    dogru(roles_can($yazar, 'posts.edit_own_published'), 'yazar yayımlanmış kendi yazısını düzeltir');
    yanlis(roles_can($yazar, 'posts.publish'), 'yazar yayımlayamaz');
    yanlis(roles_can($yazar, 'posts.edit_any'));

    $muhabir = u_personel('reporter');
    dogru(roles_can($muhabir, 'posts.create'));
    yanlis(roles_can($muhabir, 'posts.edit_own_published'), 'muhabir yayım sonrası dokunamaz');

    $mod = u_personel('moderator');
    dogru(roles_can($mod, 'comments.moderate'));
    yanlis(roles_can($mod, 'posts.view'), 'moderatör habere erişemez');

    $wire = u_personel('wire_editor');
    dogru(roles_can($wire, 'rss.manage'));
    yanlis(roles_can($wire, 'posts.publish'), 'otomasyon editörü yayımlayamaz');

    $seo = u_personel('seo_editor');
    dogru(roles_can($seo, 'posts.seo'));
    yanlis(roles_can($seo, 'posts.edit_any'), 'SEO editörü gövdeye dokunamaz');

    $reklam = u_personel('ads_manager');
    dogru(roles_can($reklam, 'ads.creative'));
    yanlis(roles_can($reklam, 'ads.html'), 'reklamcı ham HTML/JS giremez');
});

// ------------------------------------------------------------ is_staff kilidi
test('roles_can: is_staff=0 personelin TÜM izinlerini kapatır', function () {
    $askida = u_personel('editor', ['is_staff' => 0]);
    yanlis(roles_can($askida, 'admin.access'), 'panel kapalı');
    yanlis(roles_can($askida, 'posts.publish'), 'API yolu da kapalı olmalı (denetim tur 2, B04)');
    yanlis(roles_can($askida, 'posts.delete'));
    yanlis(roles_can($askida, 'media.manage'));
    yanlis(roles_can($askida, 'comments.write'));

    // Bayrak hiç yoksa (eski kayıt) rol kümesi geçerlidir
    $eski = ['id' => 902, 'role' => 'editor'];
    dogru(roles_can($eski, 'posts.publish'), 'is_staff alanı yoksa davranış değişmemeli');

    // Üye rolü bu bayraktan etkilenmez
    dogru(roles_can(u_uye(), 'comments.write'), 'üye yorum yazabilmeli');
    yanlis(roles_can(u_uye(), 'admin.access'), 'üye panele giremez');
});

// ------------------------------------------------------------ kilitli izinler
test('permission_is_locked: kilitli küme', function () {
    dogru(permission_is_locked('settings.manage'));
    dogru(permission_is_locked('users.manage'));
    dogru(permission_is_locked('roles.manage'));
    dogru(permission_is_locked('tools.manage'));
    dogru(permission_is_locked('ads.html'));
    dogru(permission_is_locked('ai.configure'));
    dogru(permission_is_locked('theme.css'));
    yanlis(permission_is_locked('posts.publish'));
    yanlis(permission_is_locked('olmayan.izin'));
});

test('role_override_set: kilitli izin DB üzerinden verilemez', function () {
    $r = role_override_set('editor', 'settings.manage', true);
    yanlis($r['ok'], 'kilitli izin role atanamamalı');
    icerir($r['error'], 'Sistem Yöneticisinde');

    $r2 = role_override_set('editor', 'olmayan.izin', true);
    yanlis($r2['ok'], 'bilinmeyen izin anahtarı reddedilmeli');

    $r3 = role_override_set('admin', 'posts.publish', false);
    yanlis($r3['ok'], 'admin rolü düzenlenemez');

    $r4 = role_override_set('hayalet_rol', 'posts.publish', true);
    yanlis($r4['ok'], 'bilinmeyen rol reddedilmeli');

    // Kilitli izin elle DB'ye yazılsa bile roles_permissions_for onu süzer
    try {
        q('DELETE FROM role_overrides WHERE role = :r', [':r' => 'yazar']);
        q('INSERT INTO role_overrides (role, permission, allowed) VALUES (:r, :p, 1)',
          [':r' => 'yazar', ':p' => 'settings.manage']);
        roles_cache_reset();
        yanlis(roles_can(u_personel('yazar'), 'settings.manage'),
               'DB`ye elle yazılan kilitli izin etkisiz kalmalı');
        q('DELETE FROM role_overrides WHERE role = :r', [':r' => 'yazar']);
        roles_cache_reset();
    } catch (Throwable $e) {
        dogru(false, 'role_overrides tablosuna yazılamadı: ' . $e->getMessage());
    }
});

/**
 * DİKKAT — roles_permissions_for() içindeki `static $memo` roles_cache_reset()
 * tarafından TEMİZLENMEZ (inc/roles.php, "istek ömürlüdür" notu). Bu yüzden bir
 * rolün izin kümesi bu süreçte bir kez sorgulandıktan sonra geçersiz kılma
 * yazmak roles_can() sonucunu DEĞİŞTİRMEZ. Aşağıdaki test bu nedenle daha önce
 * hiç sorgulanmamış bir rol (`chief_editor`) üzerinde çalışır ve sıfırlama
 * sonrası roles_can() iddiası yapılmaz. Ayrıntı: rapordaki bulgu B-D1.
 */
test('role_override_set: kilitsiz izin yazılır ve geri alınır', function () {
    $r = role_override_set('chief_editor', 'theme.manage', true);
    dogru($r['ok'], 'kilitsiz izin atanabilmeli');

    $ovr = role_overrides_all(true);
    dogru(isset($ovr['chief_editor']['theme.manage']), 'geçersiz kılma satırı yazılmalı');
    esit(true, $ovr['chief_editor']['theme.manage']);
    dogru(roles_can(u_personel('chief_editor'), 'theme.manage'),
          'geçersiz kılma, rol ilk kez çözüldüğünde etkili olmalı');

    $n = role_reset_overrides('chief_editor');
    dogru($n >= 1, 'sıfırlama en az bir satır silmeli');
    $ovr2 = role_overrides_all(true);
    yanlis(isset($ovr2['chief_editor']['theme.manage']), 'satır silinmiş olmalı');
});

test('user_grant_set: kullanıcıya özel ek izin', function () {
    $r = user_grant_set(5100, 'posts.publish', true);
    dogru($r['ok'], 'kilitsiz izin kullanıcıya verilebilmeli');
    icerir(user_extra_grants(5100), 'posts.publish');
    dogru(roles_can(u_personel('yazar', ['id' => 5100]), 'posts.publish'),
          'ek izin rol kümesinin üstüne binmeli');

    $k = user_grant_set(5100, 'settings.manage', true);
    yanlis($k['ok'], 'kilitli izin kullanıcıya da verilemez');
    icermez(user_extra_grants(5100), 'settings.manage');

    user_grant_set(5100, 'posts.publish', false);
    icermez(user_extra_grants(5100), 'posts.publish', 'ek izin geri alınabilmeli');
    yanlis(roles_can(u_personel('yazar', ['id' => 5100]), 'posts.publish'));

    // Ek izin, is_staff kilidini AŞMAMALI
    user_grant_set(5101, 'posts.publish', true);
    yanlis(roles_can(u_personel('yazar', ['id' => 5101, 'is_staff' => 0]), 'posts.publish'),
           'askıya alınmış personel ek izinle de yayımlayamamalı');
    user_grant_set(5101, 'posts.publish', false);
});

// ------------------------------------------------------------ okuma matrisi
test('post_can_read_full: görünürlük × ziyaretçi matrisi', function () {
    $public   = ['id' => 1, 'visibility' => 'public'];
    $members  = ['id' => 2, 'visibility' => 'members'];
    $premium  = ['id' => 3, 'visibility' => 'premium'];
    $bossuz   = ['id' => 4];                       // visibility alanı yok → public

    // anonim
    dogru(post_can_read_full($public, null), 'anonim genel haberi okur');
    dogru(post_can_read_full($bossuz, null), 'görünürlük yoksa genel sayılır');
    yanlis(post_can_read_full($members, null), 'anonim üyeye özel haberi okuyamaz');
    yanlis(post_can_read_full($premium, null), 'anonim aboneye özel haberi okuyamaz');

    // ücretsiz üye (memberships kaydı yok → free)
    $uye = u_uye(['id' => 5001]);
    dogru(post_can_read_full($public, $uye));
    dogru(post_can_read_full($members, $uye), 'giriş yapmış üye members içeriğini okur');
    yanlis(post_can_read_full($premium, $uye), 'ücretsiz üye premium okuyamaz');

    // abone
    $abone = u_uye(['id' => 5002]);
    q('DELETE FROM memberships WHERE user_id = :u', [':u' => 5002]);
    q('INSERT INTO memberships (user_id, tier, valid_until) VALUES (:u, :t, :v)',
      [':u' => 5002, ':t' => 'premium', ':v' => date('Y-m-d H:i:s', time() + 86400)]);
    dogru(post_can_read_full($premium, $abone), 'geçerli abone premium okur');
    esit('premium', member_tier($abone));

    // süresi geçmiş abone
    $bitmis = u_uye(['id' => 5003]);
    q('DELETE FROM memberships WHERE user_id = :u', [':u' => 5003]);
    q('INSERT INTO memberships (user_id, tier, valid_until) VALUES (:u, :t, :v)',
      [':u' => 5003, ':t' => 'premium', ':v' => date('Y-m-d H:i:s', time() - 86400)]);
    esit('free', member_tier($bitmis), 'süresi dolan abonelik okuma anında düşmeli');
    yanlis(post_can_read_full($premium, $bitmis), 'süresi dolmuş abone premium okuyamaz');

    // personel — posts.view izniyle her şeyi görür (panel önizlemesi)
    $editor = u_personel('editor');
    dogru(post_can_read_full($premium, $editor), 'editör premium haberi önizleyebilmeli');
    dogru(post_can_read_full($members, $editor));

    // panelde ama posts.view izni olmayan personel (moderatör) → üye gibi davranır
    $mod = u_personel('moderator');
    yanlis(can($mod, 'posts.view'), 'moderatörde posts.view yok');
    dogru(post_can_read_full($members, $mod), 'moderatör en azından üye sayılır');
});

test('post_is_locked: post_can_read_full`ın tersi', function () {
    yanlis(post_is_locked(['visibility' => 'public'], u_uye()));
    dogru(post_is_locked(['visibility' => 'premium'], u_uye(['id' => 5004])));
    yanlis(post_is_locked(['visibility' => 'premium'], u_personel('admin')));
});

test('post_visibility_levels: geçerli düzeyler', function () {
    esit(['public', 'members', 'premium'], post_visibility_levels());
    icerir(post_visibility_levels(), 'premium');
    icermez(post_visibility_levels(), 'gizli');
});

test('role_is_staff / role_exists', function () {
    dogru(role_exists('admin'));
    dogru(role_exists('member'));
    yanlis(role_exists('supervizor'));
    dogru(role_is_staff('editor'));
    dogru(role_is_staff('moderator'));
    yanlis(role_is_staff('member'), 'üye personel değildir');
    yanlis(role_is_staff('supervizor'), 'bilinmeyen rol personel değildir');
});
