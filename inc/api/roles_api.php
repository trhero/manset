<?php
/**
 * Manşet — rol ve izin matrisi API uçları (Faz 7b).
 * Ad alanı (CONTRACTS §7): roles.*  users.*   → "Kullanıcı tipi fazı"
 *
 * Uçlar:
 *   roles.matrix     → rol × izin matrisi, varsayılanlar, kilitliler, kullanıcı sayıları
 *   roles.set        → tek hücre yaz (role_overrides katmanı)
 *   roles.reset      → rolün tüm geçersiz kılmalarını sil
 *   users.grants     → kullanıcının rolden gelen izinleri + ek izinleri + kategori kapsamı
 *   users.grant_set  → kullanıcıya ek izin ver / al
 *   users.scope_set  → kullanıcının yazabildiği kategoriler
 *
 * GÜVENLİK (USER_TYPES_PLAN §5.2 ve §8 — Ajan-11 denetimi için özet):
 *   1. Tüm uçlar 'roles.manage' ister; bu izin KİLİTLİ olduğu için fiilen yalnız admin.
 *   2. Kilitli izinler hiçbir role/kullanıcıya atanamaz. inc/roles.php zaten reddeder;
 *      burada ayrıca açık Türkçe hata ve 403 döndürülür (uçtan zorlama denemesi kayda geçer).
 *   3. 'admin' rolünün izinleri değiştirilemez — can() onun için erken true döner,
 *      veritabanına yazılan bir satır yanıltıcı olurdu.
 *   4. İKİNCİ KAPI: kimse kendi sahip olmadığı bir izni dağıtamaz/geri alamaz
 *      (roles_actor_may_touch → oturumdaki kullanıcının izin kümesiyle kesişim).
 *      Bugün bu ekrana yalnız admin girebiliyor; kural ileride 'roles.manage'
 *      başka bir role verilirse koruma sağlasın diye kodda durur.
 *   5. users.grant_set hedefi 'admin' ise reddedilir (gereksiz; zaten tüm izinlere sahip).
 *   6. users.scope_set yalnız VAR OLAN kategori kimliklerini kabul eder.
 *   7. Her yazmadan sonra roles_cache_reset() + cache_flush() çağrılır ve
 *      audit_log tablosuna kayıt düşülür.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/roles.php';

// ============================================================ ortak yardımcılar

/** İstek alanı: JSON gövdesi öncelikli, yoksa POST/GET. */
function roles_in($key, $default = null) {
    $d = json_body();
    if (array_key_exists($key, $d)) { return $d[$key]; }
    $v = inp($key, null);
    return $v === null ? $default : $v;
}
function roles_in_s($key, $default = '') { $v = roles_in($key, $default); return is_scalar($v) ? trim((string)$v) : (string)$default; }
function roles_in_i($key, $default = 0)  { $v = roles_in($key, $default); return is_scalar($v) ? (int)$v : (int)$default; }

/** Mantıksal alan: JSON true/false, form '1'/'0'/'on' biçimlerini kabul eder. */
function roles_in_b($key, $default = false) {
    $v = roles_in($key, $default);
    if (is_bool($v)) { return $v; }
    if (is_numeric($v)) { return ((int)$v === 1); }
    return in_array(mb_strtolower(trim((string)$v), 'UTF-8'), ['1', 'true', 'on', 'evet'], true);
}

/** Tamsayı listesi alanı (JSON dizisi ya da form dizisi). */
function roles_in_ids($key) {
    $v = roles_in($key, []);
    if (is_string($v)) { $v = $v === '' ? [] : explode(',', $v); }
    if (!is_array($v)) { return []; }
    $out = [];
    foreach ($v as $x) {
        if (!is_scalar($x)) { continue; }
        $n = (int)$x;
        if ($n > 0) { $out[$n] = $n; }
    }
    return array_values($out);
}

/**
 * Matris hücresi için sanal kullanıcı.
 *
 * `is_responsible => 1` BİLEREK verilir: Basın Kanunu m.14 kuralı ŞAHSA bağlıdır
 * (roles_can() içinde `corrections.publish` yalnız işaretli kişide çalışır).
 * Rol matrisi ise rolün kendi yetkisini gösterir; kişi kuralı matrisi çarpıtmamalı.
 * Ekranda bu ayrım ilgili satırda ayrıca yazılıdır.
 */
function roles_matrix_user($role) {
    return ['id' => 0, 'role' => (string)$role, 'active' => 1, 'is_staff' => 1, 'is_responsible' => 1];
}

/** Rolün kod varsayılanında bu izin var mı? */
function roles_default_has($role, $permission) {
    if ($role === 'admin') { return true; }
    $map = roles_default_map();
    return isset($map[$role]) && in_array($permission, $map[$role], true);
}

/**
 * İKİNCİ KAPI (USER_TYPES_PLAN §5.2): oturumdaki kullanıcı, kendisinde bulunmayan
 * bir izni ne dağıtabilir ne de geri alabilir — izin kümesiyle kesişim alınır.
 */
function roles_actor_may_touch($permission) {
    $me = current_user();
    if (!$me) { return false; }
    if ((string)arr($me, 'role', '') === 'admin') { return true; }
    return can($me, $permission);
}

/** Yazma sonrası: statik izin önbellekleri + sayfa önbelleği. */
function roles_after_write() {
    roles_cache_reset();
    if (function_exists('cache_flush')) { cache_flush(); }
}

/** Denetim kaydı (inc/migrations/012_audit_and_ads.php). Hata yutulur, işlem bozulmaz. */
function roles_audit($action, $target, $detail = '') {
    try {
        $me = current_user();
        if (!is_scalar($detail)) { $detail = json_encode($detail, JSON_UNESCAPED_UNICODE); }
        db_insert('audit_log', [
            'user_id'    => (int)arr($me, 'id', 0),
            'action'     => mb_substr((string)$action, 0, 60, 'UTF-8'),
            'target'     => mb_substr((string)$target, 0, 120, 'UTF-8'),
            'detail'     => (string)$detail,
            'ip'         => mb_substr(client_ip(), 0, 64, 'UTF-8'),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        log_error('roles_audit: ' . $e->getMessage());
    }
}

/** Uçların ortak izin doğrulaması. Hata varsa json_err ile sonlandırır. */
function roles_guard_permission($permission) {
    if (!isset(roles_permissions()[$permission])) {
        json_err('Bilinmeyen izin anahtarı: ' . sanitize_line((string)$permission, 60), 400);
    }
    if (permission_is_locked($permission)) {
        roles_audit('roles.locked_denied', (string)$permission, 'Kilitli izin atanmaya çalışıldı.');
        json_err('Bu izin yalnız Sistem Yöneticisinde bulunabilir. Panelden atanamaz; '
               . 'veritabanına elle yazılsa bile yok sayılır.', 403);
    }
    if (!roles_actor_may_touch($permission)) {
        roles_audit('roles.escalation_denied', (string)$permission, 'Kendinde olmayan izni dağıtma denemesi.');
        json_err('Kendinizde bulunmayan bir izni başkasına veremez ya da geri alamazsınız.', 403);
    }
}

/** Kullanıcı satırını okur; yoksa 404. */
function roles_load_user($id) {
    $u = q1('SELECT id, name, email, role, active, is_staff, is_responsible
             FROM users WHERE id = :i', [':i' => (int)$id]);
    if (!$u) { json_err('Kullanıcı bulunamadı.', 404); }
    return $u;
}

// ============================================================ roles.matrix

api_register('roles.matrix', function () {
    $roles = roles_all();
    $perms = roles_permissions();

    // Roller: arayüzün ihtiyacı olan alanlar (sıra korunur)
    $rolesOut = [];
    foreach ($roles as $key => $meta) {
        $rolesOut[$key] = [
            'label' => $meta['label'],
            'staff' => (bool)$meta['staff'],
            'sort'  => (int)$meta['sort'],
            'desc'  => $meta['desc'],
        ];
    }

    // İzinler + grup sırası
    $permsOut = [];
    $groups = [];
    foreach ($perms as $key => $def) {
        $permsOut[$key] = ['label' => $def[0], 'group' => $def[1], 'locked' => (bool)$def[2]];
        if (!in_array($def[1], $groups, true)) { $groups[] = $def[1]; }
    }

    // Etkin matris + kod varsayılanları
    $matrix = [];
    $defaults = [];
    foreach ($roles as $role => $meta) {
        $sanal = roles_matrix_user($role);
        foreach ($perms as $perm => $def) {
            $matrix[$role][$perm]   = roles_can($sanal, $perm);
            $defaults[$role][$perm] = roles_default_has($role, $perm);
        }
    }

    // Geçersiz kılma katmanının ham hâli (hangi hücre elle değiştirilmiş)
    $overrides = [];
    foreach (role_overrides_all() as $role => $satirlar) {
        foreach ($satirlar as $perm => $allowed) {
            if (!isset($perms[$perm]) || permission_is_locked($perm)) { continue; }  // yok sayılan kayıt
            $overrides[$role][$perm] = (bool)$allowed;
        }
    }

    // Rol başına kullanıcı sayısı
    $counts = [];
    foreach ($roles as $role => $meta) { $counts[$role] = 0; }
    foreach (qa('SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $row) {
        $r = (string)$row['role'];
        if (isset($counts[$r])) { $counts[$r] = (int)$row['n']; }
    }

    $sorumlu = responsible_editor_user();

    return [
        'roles'       => $rolesOut,
        'permissions' => $permsOut,
        'groups'      => $groups,
        'matrix'      => $matrix,
        'defaults'    => $defaults,
        'overrides'   => $overrides,
        'locked'      => roles_locked_permissions(),
        'counts'      => $counts,
        'responsible' => $sorumlu ? ['id' => (int)$sorumlu['id'], 'name' => (string)$sorumlu['name']] : null,
    ];
}, ['perm' => 'roles.manage', 'methods' => ['POST', 'GET']]);

// ============================================================ roles.set

api_register('roles.set', function () {
    $role    = roles_in_s('role');
    $perm    = roles_in_s('permission');
    $allowed = roles_in_b('allowed', false);

    if (!role_exists($role)) { json_err('Geçersiz rol: ' . sanitize_line($role, 40), 400); }
    if ($role === 'admin') {
        json_err('Sistem Yöneticisi rolü değiştirilemez; tanımı gereği tüm izinlere sahiptir.', 400);
    }
    roles_guard_permission($perm);   // bilinmeyen / kilitli / kendinde olmayan izin → sonlandırır

    $res = role_override_set($role, $perm, $allowed);
    if (empty($res['ok'])) { json_err((string)arr($res, 'error', 'İzin kaydedilemedi.'), 400); }

    roles_after_write();

    // roles_permissions_for() memo'su istek ömürlüdür ve yazma sonrası tazelenmez;
    // etkin değeri taze override katmanından hesaplıyoruz.
    $ovr = role_overrides_all();
    $varsayilan = roles_default_has($role, $perm);
    $effective = isset($ovr[$role][$perm]) ? (bool)$ovr[$role][$perm] : $varsayilan;

    roles_audit('roles.set', $role . '/' . $perm,
        ($allowed ? 'verildi' : 'kaldırıldı') . '; varsayılan=' . ($varsayilan ? 'açık' : 'kapalı'));

    return [
        'message'   => role_label($role) . ' rolünde “' . roles_permissions()[$perm][0] . '” izni '
                     . ($effective ? 'açıldı.' : 'kapatıldı.'),
        'effective' => $effective,
        'default'   => $varsayilan,
        'override'  => isset($ovr[$role][$perm]),
    ];
}, ['perm' => 'roles.manage', 'methods' => ['POST']]);

// ============================================================ roles.reset

api_register('roles.reset', function () {
    $role = roles_in_s('role');
    if (!role_exists($role)) { json_err('Geçersiz rol: ' . sanitize_line($role, 40), 400); }
    if ($role === 'admin') { json_err('Sistem Yöneticisi rolünde geçersiz kılma bulunmaz.', 400); }

    // Kesişim kuralı sıfırlamada da geçerli: yalnız kendisinde bulunan izinlerin
    // geçersiz kılmalarını temizleyebilir.
    $ovr = role_overrides_all();
    foreach (isset($ovr[$role]) ? $ovr[$role] : [] as $perm => $allowed) {
        if (!isset(roles_permissions()[$perm]) || permission_is_locked($perm)) { continue; }
        if (!roles_actor_may_touch($perm)) {
            json_err('Bu rolde, sizde bulunmayan bir izin geçersiz kılınmış ('
                   . sanitize_line($perm, 60) . '). Sıfırlama yapılamaz.', 403);
        }
    }

    $silinen = role_reset_overrides($role);
    roles_after_write();
    roles_audit('roles.reset', $role, $silinen . ' geçersiz kılma silindi.');

    return [
        'message' => $silinen > 0
            ? role_label($role) . ' rolü varsayılana döndürüldü (' . $silinen . ' değişiklik geri alındı).'
            : role_label($role) . ' rolü zaten varsayılan durumdaydı.',
        'removed' => $silinen,
    ];
}, ['perm' => 'roles.manage', 'methods' => ['POST']]);

// ============================================================ users.grants

api_register('users.grants', function () {
    $u = roles_load_user(roles_in_i('user_id', 0));
    $uid = (int)$u['id'];
    $role = (string)$u['role'];

    $rolePerms = roles_permissions_for($role);
    $grants    = user_extra_grants($uid);

    // Kapsam: yalnız hâlâ var olan kategorileri göster (silinmiş kategori kalıntısı sızmasın)
    $gecerli = [];
    foreach (qa('SELECT id FROM categories') as $c) { $gecerli[(int)$c['id']] = true; }
    $kapsam = [];
    foreach (user_scope_categories($uid) as $cid) { if (isset($gecerli[$cid])) { $kapsam[] = (int)$cid; } }

    // Kişinin etkin izin kümesi (rol + ek izin + kişi kuralları)
    $effective = [];
    foreach (roles_permissions() as $perm => $def) {
        if (roles_can($u, $perm)) { $effective[] = $perm; }
    }

    return [
        'user' => [
            'id'             => $uid,
            'name'           => (string)$u['name'],
            'email'          => (string)$u['email'],
            'role'           => $role,
            'role_label'     => role_label($role),
            'active'         => (int)arr($u, 'active', 0) === 1,
            'is_staff'       => (int)arr($u, 'is_staff', 0) === 1,
            'is_responsible' => (int)arr($u, 'is_responsible', 0) === 1,
            'tier'           => member_tier($u),
        ],
        'role'       => $role,
        'role_perms' => $rolePerms,
        'grants'     => $grants,
        'categories' => $kapsam,
        'effective'  => $effective,
        'is_admin'   => ($role === 'admin'),
    ];
}, ['perm' => 'roles.manage', 'methods' => ['POST', 'GET']]);

// ============================================================ users.grant_set

api_register('users.grant_set', function () {
    $u = roles_load_user(roles_in_i('user_id', 0));
    $uid = (int)$u['id'];
    $perm = roles_in_s('permission');
    $allowed = roles_in_b('allowed', false);

    if ((string)$u['role'] === 'admin') {
        json_err('Sistem Yöneticisine ek izin verilmesi gereksizdir; zaten tüm izinlere sahiptir.', 400);
    }
    roles_guard_permission($perm);

    $res = user_grant_set($uid, $perm, $allowed);
    if (empty($res['ok'])) { json_err((string)arr($res, 'error', 'İzin kaydedilemedi.'), 400); }

    roles_after_write();

    $grants = user_extra_grants($uid);
    $rolePerms = roles_permissions_for((string)$u['role']);
    $effective = in_array($perm, $rolePerms, true) || in_array($perm, $grants, true);

    roles_audit('users.grant_set', '#' . $uid . '/' . $perm,
        ($allowed ? 'verildi' : 'kaldırıldı') . '; kullanıcı=' . $u['email'] . '; rol=' . $u['role']);

    return [
        'message'   => $u['name'] . ' kullanıcısına “' . roles_permissions()[$perm][0] . '” izni '
                     . ($allowed ? 'verildi.' : 'geri alındı.'),
        'effective' => $effective,
        'grants'    => $grants,
        'in_role'   => in_array($perm, $rolePerms, true),
    ];
}, ['perm' => 'roles.manage', 'methods' => ['POST']]);

// ============================================================ users.scope_set

api_register('users.scope_set', function () {
    $u = roles_load_user(roles_in_i('user_id', 0));
    $uid = (int)$u['id'];
    $istenen = roles_in_ids('category_ids');

    // Yalnız VAR OLAN kategori kimlikleri kabul edilir (§8 — uydurma id yazılmasın)
    $gecerli = [];
    foreach (qa('SELECT id FROM categories') as $c) { $gecerli[(int)$c['id']] = true; }
    $kabul = [];
    $reddedilen = 0;
    foreach ($istenen as $cid) {
        if (isset($gecerli[$cid])) { $kabul[] = $cid; } else { $reddedilen++; }
    }

    try {
        q('DELETE FROM user_categories WHERE user_id = :u', [':u' => $uid]);
        foreach ($kabul as $cid) {
            db_insert('user_categories', ['user_id' => $uid, 'category_id' => $cid]);
        }
    } catch (Throwable $e) {
        log_error('users.scope_set: ' . $e->getMessage());
        json_err('Kategori kapsamı kaydedilemedi.', 500);
    }

    roles_after_write();
    roles_audit('users.scope_set', '#' . $uid,
        'kategoriler=[' . implode(',', $kabul) . ']; kullanıcı=' . $u['email']);

    $mesaj = $kabul
        ? $u['name'] . ' için ' . count($kabul) . ' kategori kapsamı kaydedildi.'
        : $u['name'] . ' için kapsam sınırı kaldırıldı (tüm kategorilerde yazabilir).';
    if ($reddedilen > 0) { $mesaj .= ' ' . $reddedilen . ' geçersiz kategori yok sayıldı.'; }

    return ['message' => $mesaj, 'categories' => $kabul, 'ignored' => $reddedilen];
}, ['perm' => 'roles.manage', 'methods' => ['POST']]);
