<?php
/**
 * Manşet — üyelik uç noktaları (Faz 7c/7d).
 * Ad alanı: members.* (ön yüz) · admin_members.* (panel)
 *
 * Ön yüz uçları `perm => 'public'` ile kayıtlıdır; oturum gerektirenler
 * kendi içinde member_current() denetimi yapar. Panel uçları `members.manage`
 * izniyle korunur ve hedef sorgularında `AND role = 'member'` sabitini kullanır
 * (USER_TYPES_PLAN §8 risk #7 — moderatörün yetki yükseltmesi engellenir).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/members.php';

/** İstek gövdesinden alan okur (JSON önce, klasik form gönderimi yedek). */
function members_field($key, $default = '') {
    $body = json_body();
    if (array_key_exists($key, $body)) { return $body[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    if (isset($_GET[$key])) { return $_GET[$key]; }
    return $default;
}

/** members.php dönüşünü API yanıtına çevirir; hata varsa json_err ile sonlandırır. */
function members_result(array $r, array $extra = []) {
    if (empty($r['ok'])) {
        $code = isset($r['code']) ? (int)$r['code'] : 400;
        json_err(isset($r['error']) ? (string)$r['error'] : 'İşlem tamamlanamadı.', $code);
    }
    unset($r['code']);
    return $r + $extra;
}

/** Oturumdaki üyeyi döndürür; yoksa 401. */
function members_require_login() {
    $u = member_current();
    // login_required: istemci (assets/uye.js) bu işarete bakıp kullanıcıyı
    // giriş sayfasına yönlendirir. Eskiden hiç üretilmiyordu, yönlendirme ölüydü.
    if (!$u) {
        json_err('Bu işlem için giriş yapmanız gerekiyor.', 401, ['login_required' => true]);
    }
    return $u;
}

/** Panel uçlarında hedefin ÜYE olduğunu doğrular (yetki yükseltme kalkanı). */
function members_admin_target($userId) {
    $userId = (int)$userId;
    $row = $userId > 0
        ? q1('SELECT id, name, email, active FROM users WHERE id = :i AND role = \'member\'', [':i' => $userId])
        : null;
    if (!$row) { json_err('Üye bulunamadı.', 404); }
    return $row;
}

// ================================================================ ön yüz uçları

/**
 * members.register
 * İstek : {email, password, kvkk, display_name?, website (tuzak), _csrf}
 * Yanıt : {ok:true, user_id:int, needs_verify:bool, message:string}
 * Hata  : 400 doğrulama · 429 hız sınırı (register:<ip> 5/900)
 */
api_register('members.register', function () {
    return members_result(member_register([
        'email'        => members_field('email', ''),
        'password'     => members_field('password', ''),
        'kvkk'         => members_field('kvkk', ''),
        'display_name' => members_field('display_name', ''),
        'website'      => members_field('website', ''),
        // DİKKAT: `role` bilerek OKUNMAZ — kayıt her zaman 'member' açar.
    ]));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.login
 * İstek : {email, password, _csrf}
 * Yanıt : {ok:true, user_id, name, verified:bool, csrf:string, message}
 * Hata  : 401 hatalı bilgi · 403 personel hesabı / askıda · 429 hız sınırı
 */
api_register('members.login', function () {
    $r = member_login((string)members_field('email', ''), (string)members_field('password', ''));
    return members_result($r, ['redirect' => member_url('profil')]);
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.logout
 * Yanıt : {ok:true, redirect:string}
 */
api_register('members.logout', function () {
    $redirect = member_url('giris');
    member_logout();
    return ['message' => 'Çıkış yapıldı.', 'redirect' => $redirect];
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.verify
 * İstek : {token}
 * Yanıt : {ok:true, user_id, message}
 */
api_register('members.verify', function () {
    return members_result(member_verify((string)members_field('token', '')));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.reset_request
 * İstek : {email}
 * Yanıt : {ok:true, sent:bool, message}   — varlık sızdırmaz, yanıt hep aynıdır
 */
api_register('members.reset_request', function () {
    return members_result(member_request_reset((string)members_field('email', '')));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.reset_apply
 * İstek : {token, password}
 * Yanıt : {ok:true, message}
 */
api_register('members.reset_apply', function () {
    return members_result(member_reset_password(
        (string)members_field('token', ''),
        (string)members_field('password', '')
    ));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.profile_save  (oturum gerekir)
 * İstek : {display_name, bio}
 * Yanıt : {ok:true, display_name, bio, message}
 */
api_register('members.profile_save', function () {
    $u = members_require_login();
    return members_result(member_update_profile((int)$u['id'], [
        'display_name' => members_field('display_name', ''),
        'bio'          => members_field('bio', ''),
    ]));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.password  (oturum gerekir)
 * İstek : {old, new}
 * Yanıt : {ok:true, message}
 */
api_register('members.password', function () {
    $u = members_require_login();
    return members_result(member_change_password(
        (int)$u['id'], (string)members_field('old', ''), (string)members_field('new', '')
    ));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.bookmark  (oturum gerekir)
 * İstek : {post_id}
 * Yanıt : {ok:true, saved:bool, message}
 */
api_register('members.bookmark', function () {
    $u = members_require_login();
    return members_result(member_bookmark_toggle((int)$u['id'], (int)members_field('post_id', 0)));
}, ['perm' => 'public', 'methods' => ['POST']]);

/**
 * members.bookmarks  (oturum gerekir)
 * İstek : {page?}
 * Yanıt : {ok:true, items:[{id,title,url,spot,saved_at}], page:int}
 */
api_register('members.bookmarks', function () {
    $u = members_require_login();
    $sayfa = max(1, (int)members_field('page', 1));
    $perPage = 20;
    $items = [];
    foreach (member_bookmarks((int)$u['id'], $perPage, ($sayfa - 1) * $perPage) as $p) {
        $items[] = [
            'id'       => (int)$p['id'],
            'title'    => (string)$p['title'],
            'url'      => url_post($p),
            'spot'     => (string)arr($p, 'spot', ''),
            'saved_at' => (string)arr($p, 'saved_at', ''),
        ];
    }
    return ['items' => $items, 'page' => $sayfa];
}, ['perm' => 'public', 'methods' => ['POST']]);

// ================================================================ panel uçları

/**
 * admin_members.list
 * İstek : {q?, tier?('free'|'premium'), status?('verified'|'unverified'|'suspended'), page?}
 * Yanıt : {ok:true, items:[…], total:int, page:int, per_page:int}
 */
api_register('admin_members.list', function () {
    $sayfa = max(1, (int)members_field('page', 1));
    $perPage = 30;
    $filters = [
        'q'      => (string)members_field('q', ''),
        'tier'   => (string)members_field('tier', ''),
        'status' => (string)members_field('status', ''),
    ];
    $rows = member_list($filters, $perPage, ($sayfa - 1) * $perPage);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'            => (int)$r['id'],
            'name'          => (string)$r['name'],
            'email'         => (string)$r['email'],
            'active'        => (int)$r['active'] === 1,
            'verified'      => (int)$r['verified_count'] > 0,
            'tier'          => (int)$r['premium_active'] === 1 ? 'premium' : 'free',
            'stored_tier'   => (string)arr($r, 'stored_tier', 'free'),
            'valid_until'   => (string)arr($r, 'valid_until', ''),
            'note'          => (string)arr($r, 'note', ''),
            'comment_count' => (int)$r['comment_count'],
            'created_at'    => (string)$r['created_at'],
        ];
    }
    return ['items' => $items, 'total' => member_count($filters), 'page' => $sayfa, 'per_page' => $perPage];
}, ['perm' => 'members.manage', 'methods' => ['POST']]);

/**
 * admin_members.set_tier
 * İstek : {user_id, tier:'free'|'premium', valid_until?, note?}
 * Yanıt : {ok:true, tier, valid_until, message}
 */
api_register('admin_members.set_tier', function () {
    $hedef = members_admin_target(members_field('user_id', 0));
    $tier  = (string)members_field('tier', 'free');
    $until = (string)members_field('valid_until', '');

    $sonuc = members_result(member_set_subscription(
        (int)$hedef['id'], $tier, $until, (string)members_field('note', '')
    ));

    // DENETİM KAYDI (denetim turu 4, O-03).
    // Bu uç PARA DEĞERİ olan bir şey dağıtıyor: `members.manage` yetkisi olan
    // bir personel istediği hesaba yıllarca premium yazabilir. Yetkinin kendisi
    // meşru (yayıncı bilerek abonelik hediye edebilmeli), eksik olan İZ'di —
    // denetçi bunu 2099'a kadar premium yazarak gösterdi ve hiçbir kayıt kalmadı.
    // Tahsilat ekranı `payments.manage` ile kilitliyken bu kapının izsiz kalması,
    // kilidi anlamsız kılıyordu.
    if (function_exists('api_admin_audit')) {
        api_admin_audit((array)current_user(), 'members.set_tier',
            'user:' . (int)$hedef['id'], $tier . ($until !== '' ? ' → ' . $until : ''));
    }
    return $sonuc;
}, ['perm' => 'members.manage', 'methods' => ['POST']]);

/**
 * admin_members.verify  — elle doğrulama (mail() çalışmayan kurulumlar için)
 * İstek : {user_id}
 * Yanıt : {ok:true, message}
 */
api_register('admin_members.verify', function () {
    $hedef = members_admin_target(members_field('user_id', 0));
    if (!member_mark_verified((int)$hedef['id'])) {
        json_err('Doğrulama kaydedilemedi.', 500);
    }
    return ['message' => 'Üyenin e-postası doğrulanmış olarak işaretlendi.'];
}, ['perm' => 'members.manage', 'methods' => ['POST']]);

/**
 * admin_members.suspend
 * İstek : {user_id, active:0|1}
 * Yanıt : {ok:true, active:bool, message}
 */
api_register('admin_members.suspend', function () {
    $hedef = members_admin_target(members_field('user_id', 0));
    $active = member_flag(members_field('active', 0)) ? 1 : 0;
    q('UPDATE users SET active = :a WHERE id = :i AND role = \'member\'',
        [':a' => $active, ':i' => (int)$hedef['id']]);
    return [
        'active'  => $active === 1,
        'message' => $active === 1 ? 'Üyelik yeniden etkinleştirildi.' : 'Üye askıya alındı.',
    ];
}, ['perm' => 'members.manage', 'methods' => ['POST']]);

/**
 * admin_members.delete
 * İstek : {user_id}
 * Yanıt : {ok:true, message}
 *
 * Üyenin yorumları SİLİNMEZ; yalnız hesap bağı kopar (user_id = 0) — yayımlanmış
 * içerikte boşluk oluşmasın diye.
 */
api_register('admin_members.delete', function () {
    $hedef = members_admin_target(members_field('user_id', 0));
    $id = (int)$hedef['id'];
    try {
        q('UPDATE comments SET user_id = 0 WHERE user_id = :i', [':i' => $id]);
        q('DELETE FROM bookmarks WHERE user_id = :i', [':i' => $id]);
        q('DELETE FROM member_tokens WHERE user_id = :i', [':i' => $id]);
        q('DELETE FROM memberships WHERE user_id = :i', [':i' => $id]);
        q('DELETE FROM users WHERE id = :i AND role = \'member\'', [':i' => $id]);
    } catch (Throwable $e) {
        log_error('admin_members.delete: ' . $e->getMessage());
        json_err('Üye silinemedi.', 500);
    }
    return ['message' => 'Üye silindi.'];
}, ['perm' => 'members.manage', 'methods' => ['POST']]);
