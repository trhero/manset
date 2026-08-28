<?php
/**
 * Manşet — rol ve izin sistemi (USER_TYPES_PLAN.md §2, §5).
 *
 * MODEL: Kodda sabit rol→izin haritası + veritabanında DOĞRULANAN geçersiz kılma katmanı.
 *
 *   can($user, $perm)
 *     ├─ admin ise                → true
 *     ├─ KİLİTLİ izin mi?         → yalnız kod haritası karar verir (DB YOK SAYILIR)
 *     ├─ kod haritası             → varsayılan
 *     ├─ role_overrides           → ekle/kaldır (yalnız kilitli olmayan izinler)
 *     └─ user_grants              → yalnız EKLE (yalnız kilitli olmayan izinler)
 *
 * NEDEN kilitli çekirdek: Panelde "Araçlar → veritabanı geri yükleme" var. İzinler
 * tamamen veritabanında tutulsaydı, saldırganın hazırladığı bir yedeği geri yüklemek
 * doğrudan yetki yükseltme olurdu. Bu yüzden yönetici-eşdeğeri izinler koddan gelir.
 */

require_once __DIR__ . '/bootstrap.php';

// ============================================================ tanımlar

/**
 * Tüm roller. `staff` = panele girebilir mi (üyeler giremez).
 * v1 anahtarları (`admin`, `editor`, `yazar`) BİLEREK korunmuştur: yeniden
 * adlandırma, eski yedekler geri yüklendiğinde sessiz yetki kaymasına yol açardı.
 */
function roles_all() {
    return [
        'admin' => [
            'label' => 'Sistem Yöneticisi', 'staff' => true, 'sort' => 1,
            'desc'  => 'Tüm izinler. Ayarlar, yedek, yapay zekâ anahtarı ve ham reklam kodu yalnız bu roldedir.',
        ],
        'chief_editor' => [
            'label' => 'Genel Yayın Yönetmeni', 'staff' => true, 'sort' => 2,
            'desc'  => 'Her haberi yayımlar, siler, ekibe hesap açar. Teknik ayarlara ve reklam koduna dokunamaz.',
        ],
        'editor' => [
            'label' => 'Editör (Masa)', 'staff' => true, 'sort' => 3,
            'desc'  => 'Masa editörü. Haberleri düzenler ve yayımlar.',
        ],
        'yazar' => [
            'label' => 'Yazar / Köşe Yazarı', 'staff' => true, 'sort' => 4,
            'desc'  => 'Yalnız kendi içeriği. Yayımlanmış yazısını da düzeltebilir, ama yayımlayamaz.',
        ],
        'reporter' => [
            'label' => 'Muhabir (Saha)', 'staff' => true, 'sort' => 5,
            'desc'  => 'Mobil hızlı giriş. Yalnız kendi taslağı; yayımlandıktan sonra dokunamaz.',
        ],
        'wire_editor' => [
            'label' => 'Otomasyon Editörü', 'staff' => true, 'sort' => 6,
            'desc'  => 'RSS havuzu ve yapay zekâ kuyruğunun sahibi. Yayımlama yetkisi yoktur.',
        ],
        'seo_editor' => [
            'label' => 'Vitrin ve SEO Editörü', 'staff' => true, 'sort' => 7,
            'desc'  => 'Manşet sırası ve üstveri. Haber gövdesine dokunamaz.',
        ],
        'ads_manager' => [
            'label' => 'Reklam Yöneticisi', 'staff' => true, 'sort' => 8,
            'desc'  => 'Kampanya zamanlar, görsel reklam kurar. Ham HTML/JS giremez.',
        ],
        'moderator' => [
            'label' => 'Topluluk Moderatörü', 'staff' => true, 'sort' => 9,
            'desc'  => 'Paneldeki en dar rol: yalnız yorum kuyruğu, üye yönetimi ve düzeltme talebi ön elemesi. Hiçbir habere erişemez.',
        ],
        'member' => [
            'label' => 'Üye (Kayıtlı Okur)', 'staff' => false, 'sort' => 10,
            'desc'  => 'Panele giremez. Yorum yazar, haber kaydeder; abonelik kademesi ayrıca tutulur.',
        ],
    ];
}

/** Rol var mı? */
function role_exists($role) { return isset(roles_all()[(string)$role]); }

/** Rol personel rolü mü (panele girebilir mi)? */
function role_is_staff($role) {
    $all = roles_all();
    return isset($all[$role]) ? (bool)$all[$role]['staff'] : false;
}

/** Rolün görünen adı. */
function role_label($role) {
    $all = roles_all();
    return isset($all[$role]) ? $all[$role]['label'] : (string)$role;
}

/**
 * Bilinen tüm izinler: anahtar => ['label', 'group', 'locked'].
 * `locked` izinler panelden HİÇBİR role atanamaz (bkz. dosya başı açıklaması).
 */
function roles_permissions() {
    return [
        // İçerik
        'admin.access'             => ['Panele giriş', 'Genel', false],
        'posts.view'               => ['Haberleri görme', 'İçerik', false],
        'posts.create'             => ['Haber oluşturma', 'İçerik', false],
        'posts.edit_own'           => ['Kendi haberini düzenleme', 'İçerik', false],
        'posts.edit_own_published' => ['Yayımlanmış kendi haberini düzenleme', 'İçerik', false],
        'posts.edit_any'           => ['Her haberi düzenleme', 'İçerik', false],
        'posts.edit_wire'          => ['Kaynaklı/YZ haberlerini düzenleme', 'İçerik', false],
        'posts.seo'                => ['Yalnız üstveri (SEO) düzenleme', 'İçerik', false],
        'posts.publish'            => ['Haber yayımlama', 'İçerik', false],
        'posts.delete'             => ['Haber silme', 'İçerik', false],
        'posts.takedown'           => ['Yayından çekme (hukuki)', 'İçerik', false],
        'categories.manage'        => ['Kategori yönetimi', 'İçerik', false],
        'media.manage'             => ['Medya yükleme', 'İçerik', false],
        'media.delete_any'         => ['Medya silme', 'İçerik', false],
        'pages.manage'             => ['Sabit sayfa yönetimi', 'İçerik', false],
        // Topluluk
        'comments.moderate'        => ['Yorum moderasyonu', 'Topluluk', false],
        'comments.write'           => ['Ön yüzde yorum yazma', 'Topluluk', false],
        'members.manage'           => ['Üye yönetimi', 'Topluluk', false],
        // Vitrin
        'headlines.manage'         => ['Manşet ve son dakika', 'Vitrin', false],
        'ads.schedule'             => ['Reklam zamanlama', 'Vitrin', false],
        'ads.creative'             => ['Görsel reklam kurma', 'Vitrin', false],
        'ads.html'                 => ['Ham HTML/JS reklam kodu', 'Vitrin', true],
        // Otomasyon
        'rss.manage'               => ['RSS kaynak ve havuz yönetimi', 'Otomasyon', false],
        'rss.autopublish'          => ['Kaynağa otomatik yayın açma', 'Otomasyon', false],
        'ai.use'                   => ['Yapay zekâ araçlarını kullanma', 'Otomasyon', false],
        'ai.configure'             => ['Yapay zekâ sağlayıcı ve anahtarı', 'Otomasyon', true],
        // Yasal
        'corrections.triage'       => ['Düzeltme taleplerini sınıflandırma', 'Yasal', false],
        'corrections.publish'      => ['Düzeltme/cevap yayımlama', 'Yasal', false],
        'kunye.manage'             => ['Künye düzenleme', 'Yasal', false],
        // Görünüm
        'theme.manage'             => ['Tema ayarları ve blok dizilimi', 'Görünüm', false],
        'theme.css'                => ['Tema özel CSS', 'Görünüm', true],
        // Sistem
        'settings.manage'          => ['Genel ayarlar', 'Sistem', true],
        'users.manage'             => ['Kullanıcı yönetimi', 'Sistem', true],
        'roles.manage'             => ['Rol ve izin matrisi', 'Sistem', true],
        'tools.manage'             => ['Araçlar, yedek, günlükler', 'Sistem', true],
        // İçerik erişimi
        'content.premium'          => ['Aboneye özel içeriği okuma', 'Üyelik', false],

        // --- 1.2 "Yayıncı paketi" ---
        // Okunma verisi editoryal bir karar aracıdır: hangi haber tuttu, hangi
        // kaynak trafik getiriyor. Yayın yönetmeni ve SEO editörü görmeli;
        // muhabirin işine yaramaz, gösterilmesi de yarış duygusu üretir.
        'analytics.view'           => ['Okunma ve trafik raporu', 'Büyüme', false],
        // SEO ayarları site geneli davranışı değiştirir (başlık şablonu, 301,
        // sitemap). `posts.seo` tek haberin üstverisidir; bu ondan daha geniştir.
        'seo.manage'               => ['SEO ayarları ve yönlendirmeler', 'Büyüme', false],
        // Bülten listesi kişisel veridir (KVKK): abonelerin e-postalarını
        // görmek ve dışa aktarmak ayrı bir yetkidir.
        'newsletter.manage'        => ['Bülten listesi ve dışa aktarma', 'Büyüme', false],
        // KİLİTLİ: ödeme sağlayıcı anahtarları ve ödeme kayıtları. Panelden
        // hiçbir role atanamaz — yalnız site sahibi (admin) erişir.
        'payments.manage'          => ['Ödeme sağlayıcı ve abonelik tahsilatı', 'Üyelik', true],
    ];
}

/** Panelden atanamayan (kilitli) izin anahtarları. */
function roles_locked_permissions() {
    static $locked = null;
    if ($locked !== null) { return $locked; }
    $locked = [];
    foreach (roles_permissions() as $key => $def) { if (!empty($def[2])) { $locked[] = $key; } }
    return $locked;
}

/** İzin kilitli mi? */
function permission_is_locked($perm) {
    return in_array((string)$perm, roles_locked_permissions(), true);
}

/**
 * Kodda tanımlı varsayılan rol→izin haritası (USER_TYPES_PLAN §2).
 * `admin` burada yer almaz; can() onun için erken true döner.
 */
function roles_default_map() {
    return [
        'chief_editor' => [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit_own', 'posts.edit_own_published',
            'posts.edit_any', 'posts.edit_wire', 'posts.seo', 'posts.publish', 'posts.delete', 'posts.takedown',
            'categories.manage', 'media.manage', 'media.delete_any', 'pages.manage',
            'comments.moderate', 'comments.write', 'members.manage', 'headlines.manage',
            'ads.schedule', 'ads.creative', 'rss.manage', 'rss.autopublish', 'ai.use',
            'corrections.triage', 'corrections.publish', 'kunye.manage', 'users.manage', 'content.premium',
            'analytics.view', 'seo.manage', 'newsletter.manage',
        ],

        // v1 `editor` kümesi birebir korunur + zararsız eklemeler
        'editor' => [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit_own', 'posts.edit_own_published',
            'posts.edit_any', 'posts.edit_wire', 'posts.seo', 'posts.publish', 'posts.delete',
            'categories.manage', 'media.manage', 'media.delete_any', 'pages.manage',
            'comments.moderate', 'comments.write', 'headlines.manage', 'ai.use',
            'corrections.triage', 'content.premium', 'analytics.view',
        ],
        // v1 `yazar` kümesi + yayın sonrası kendi içeriğini düzeltme
        'yazar' => [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit_own', 'posts.edit_own_published',
            'media.manage', 'ai.use', 'comments.write', 'content.premium',
        ],
        'reporter' => [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit_own',
            'media.manage', 'comments.write', 'content.premium',
        ],
        'wire_editor' => [
            'admin.access', 'posts.view', 'posts.create', 'posts.edit_own', 'posts.edit_wire',
            'media.manage', 'rss.manage', 'ai.use', 'comments.write', 'content.premium',
        ],
        'seo_editor' => [
            'admin.access', 'posts.view', 'posts.seo', 'headlines.manage', 'media.manage',
            'theme.manage', 'ai.use', 'comments.write', 'content.premium',
            'analytics.view', 'seo.manage',
        ],
        'ads_manager' => [
            'admin.access', 'ads.schedule', 'ads.creative', 'media.manage', 'comments.write', 'content.premium',
            'analytics.view',
        ],
        'moderator' => [
            'admin.access', 'comments.moderate', 'comments.write', 'members.manage',
            'corrections.triage', 'content.premium',
        ],
        'member' => ['comments.write'],
    ];
}

// ============================================================ geçersiz kılma katmanı

/** Şema hazır mı? (göç uygulanmadan sorgu yapmayalım) */
function roles_tables_ready() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    // inc/schema.php ön yüzde yüklü olmayabilir; şema yardımcılarına bağlanmak yerine
    // doğrudan hafif bir sorgu deneriz. Tablo yoksa istisna gelir ve false döneriz.
    try {
        qv('SELECT COUNT(*) FROM role_overrides', [], 0);
        qv('SELECT COUNT(*) FROM user_grants', [], 0);
        return $ready = true;
    } catch (Throwable $e) { return $ready = false; }
}

/** role_overrides tablosu: [rol][izin] => bool. İstek başına tek sorgu. */
function role_overrides_all($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) { return $cache; }
    $cache = [];
    if (!roles_tables_ready()) { return $cache; }
    try {
        foreach (qa('SELECT role, permission, allowed FROM role_overrides') as $r) {
            $cache[$r['role']][$r['permission']] = ((int)$r['allowed'] === 1);
        }
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

/** user_grants tablosu: [user_id] => [izin, …]. İstek başına tek sorgu. */
function user_grants_all($reload = false) {
    static $cache = null;
    if ($cache !== null && !$reload) { return $cache; }
    $cache = [];
    if (!roles_tables_ready()) { return $cache; }
    try {
        foreach (qa('SELECT user_id, permission FROM user_grants') as $r) {
            $cache[(int)$r['user_id']][] = $r['permission'];
        }
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

/**
 * Rolün etkin izin kümesi: kod haritası + doğrulanmış geçersiz kılmalar.
 * KİLİTLİ izinler için veritabanı YOK SAYILIR.
 */
function roles_permissions_for($role, $reset = false) {
    static $memo = [];
    // $reset: roles_cache_reset() memo'yu da temizleyebilsin diye.
    // Eskiden yalnız override/grant önbellekleri tazeleniyordu; cevabı asıl
    // üreten BU memo bayat kalıyordu. Kısa ömürlü web isteğinde zararsızdı
    // (yazma sonrası sayfa yenileniyor) ama fonksiyonun adı yanıltıcıydı ve
    // cron/CLI gibi uzun ömürlü süreçlerde gerçekten bayat sonuç veriyordu.
    if ($reset) { $memo = []; return []; }
    $role = (string)$role;
    if (isset($memo[$role])) { return $memo[$role]; }

    if ($role === 'admin') { return $memo[$role] = array_keys(roles_permissions()); }

    $map = roles_default_map();
    // Bilinmeyen rol → fail-closed (v1 davranışı korunur)
    $set = isset($map[$role]) ? array_flip($map[$role]) : [];

    $ovr = role_overrides_all();
    if (isset($ovr[$role])) {
        foreach ($ovr[$role] as $perm => $allowed) {
            if (!isset(roles_permissions()[$perm])) { continue; }   // bilinmeyen anahtar yazılmaz
            if (permission_is_locked($perm)) { continue; }          // kilitli izin DB'den gelemez
            if ($allowed) { $set[$perm] = true; } else { unset($set[$perm]); }
        }
    }
    return $memo[$role] = array_keys($set);
}

/** Kullanıcıya özel ek izinler (yalnız toplamalı, kilitli olanlar süzülür). */
function user_extra_grants($userId) {
    $all = user_grants_all();
    $list = isset($all[(int)$userId]) ? $all[(int)$userId] : [];
    $out = [];
    foreach ($list as $perm) {
        if (!isset(roles_permissions()[$perm])) { continue; }
        if (permission_is_locked($perm)) { continue; }
        $out[] = $perm;
    }
    return $out;
}

/**
 * can() gerçek uygulaması. inc/bootstrap.php bu fonksiyon varsa buraya devreder.
 */
function roles_can($user, $permission) {
    if (!$user || !is_array($user)) { return false; }
    $role = isset($user['role']) ? (string)$user['role'] : '';
    if ($role === 'admin') { return true; }

    $permission = (string)$permission;

    // Personel olmayan rol panele giremez — çift kilit (rol + is_staff)
    if ($permission === 'admin.access' && !role_is_staff($role)) { return false; }

    // GÜVENLİK (denetim tur 2, B04): is_staff = 0, panel erişimi GERİ ALINMIŞ
    // personel demektir. Eskiden bu bayrak yalnız `admin.access` içinde
    // denetleniyordu; askıya alınan personel /admin/ ekranını göremiyordu ama
    // api.php üzerinden rolünün tüm yetkilerini (haber yayımlama, silme…)
    // kullanmaya devam edebiliyordu. Bayrak artık personel rollerinin TÜM
    // izinlerini kapatır. Üye rolleri bu bayrağı zaten taşımaz.
    if (role_is_staff($role)
        && array_key_exists('is_staff', $user) && (int)$user['is_staff'] !== 1) {
        return false;
    }

    // Basın Kanunu m.14 yükümlülüğü ŞAHSA bağlıdır. Sistemde "sorumlu yazı işleri
    // müdürü" işaretli etkin bir kullanıcı varsa, düzeltme/cevap yayımlama yetkisi
    // yalnız o kişide kalır — izin birden çok kişide olsa bile. Kimse işaretlenmemişse
    // kural uygulanmaz (yeni kurulumu kilitlemesin).
    if ($permission === 'corrections.publish' && responsible_user_exists()
        && (int)arr($user, 'is_responsible', 0) !== 1) {
        return false;
    }

    if (in_array($permission, roles_permissions_for($role), true)) { return true; }
    return in_array($permission, user_extra_grants((int)arr($user, 'id', 0)), true);
}

// ============================================================ yazma işlemleri

/** Rol için izin geçersiz kılma yazar/siler. Kilitli izin kabul edilmez. */
function role_override_set($role, $permission, $allowed) {
    if (!role_exists($role) || $role === 'admin') { return ['ok' => false, 'error' => 'Geçersiz rol.']; }
    if (!isset(roles_permissions()[$permission])) { return ['ok' => false, 'error' => 'Bilinmeyen izin anahtarı.']; }
    if (permission_is_locked($permission)) {
        return ['ok' => false, 'error' => 'Bu izin yalnız Sistem Yöneticisinde bulunabilir; role atanamaz.'];
    }
    if (!roles_tables_ready()) { return ['ok' => false, 'error' => 'Rol tabloları henüz kurulmadı (göçleri uygulayın).']; }

    $varsayilan = in_array($permission, isset(roles_default_map()[$role]) ? roles_default_map()[$role] : [], true);
    $allowed = (bool)$allowed;

    try {
        if ($allowed === $varsayilan) {
            // Varsayılana dönüldü → geçersiz kılma kaydını sil
            q('DELETE FROM role_overrides WHERE role = :r AND permission = :p', [':r' => $role, ':p' => $permission]);
        } else {
            $var = qv('SELECT id FROM role_overrides WHERE role = :r AND permission = :p',
                [':r' => $role, ':p' => $permission]);
            if ($var) {
                q('UPDATE role_overrides SET allowed = :a, updated_at = :u WHERE id = :i',
                    [':a' => $allowed ? 1 : 0, ':u' => now(), ':i' => (int)$var]);
            } else {
                db_insert('role_overrides', [
                    'role' => $role, 'permission' => $permission,
                    'allowed' => $allowed ? 1 : 0, 'updated_at' => now(),
                ]);
            }
        }
    } catch (Throwable $e) {
        log_error('role_override_set: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'İzin kaydedilemedi.'];
    }
    roles_cache_reset();
    return ['ok' => true];
}

/** Rolün tüm geçersiz kılmalarını siler (varsayılana dön). */
function role_reset_overrides($role) {
    if (!roles_tables_ready()) { return 0; }
    $n = q('DELETE FROM role_overrides WHERE role = :r', [':r' => (string)$role])->rowCount();
    roles_cache_reset();
    return $n;
}

/** Kullanıcıya ek izin verir/alır. */
function user_grant_set($userId, $permission, $allowed) {
    if (!isset(roles_permissions()[$permission])) { return ['ok' => false, 'error' => 'Bilinmeyen izin anahtarı.']; }
    if (permission_is_locked($permission)) {
        return ['ok' => false, 'error' => 'Bu izin kullanıcıya ayrıca verilemez.'];
    }
    if (!roles_tables_ready()) { return ['ok' => false, 'error' => 'Rol tabloları henüz kurulmadı.']; }
    try {
        if ($allowed) {
            if (!qv('SELECT id FROM user_grants WHERE user_id = :u AND permission = :p',
                    [':u' => (int)$userId, ':p' => $permission])) {
                db_insert('user_grants', ['user_id' => (int)$userId, 'permission' => $permission, 'created_at' => now()]);
            }
        } else {
            q('DELETE FROM user_grants WHERE user_id = :u AND permission = :p',
                [':u' => (int)$userId, ':p' => $permission]);
        }
    } catch (Throwable $e) {
        log_error('user_grant_set: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'İzin kaydedilemedi.'];
    }
    roles_cache_reset();
    return ['ok' => true];
}

/** Statik önbellekleri tazeler (yazma sonrası). */
function roles_cache_reset() {
    role_overrides_all(true);
    user_grants_all(true);
    roles_permissions_for('', true);   // çözülmüş izin kümesi memo'sunu da düşür
}

// ============================================================ kapsam ve üyelik

/** Masa editörünün yazabildiği kategoriler (boşsa sınır yok). */
function user_scope_categories($userId) {
    static $cache = [];
    $userId = (int)$userId;
    if (isset($cache[$userId])) { return $cache[$userId]; }
    try {
        $rows = qa('SELECT category_id FROM user_categories WHERE user_id = :u', [':u' => $userId]);
    } catch (Throwable $e) { return $cache[$userId] = []; }
    return $cache[$userId] = array_map(function ($r) { return (int)$r['category_id']; }, $rows);
}

/**
 * Üyelik kademesi: 'free' | 'premium'.
 * Süre okuma anında değerlendirilir — cron kurulmamış olabilir (5/5 danışman uyarısı).
 */
function member_tier($user) {
    if (!$user || !is_array($user)) { return 'free'; }
    static $cache = [];
    $id = (int)arr($user, 'id', 0);
    if ($id <= 0) { return 'free'; }
    if (isset($cache[$id])) { return $cache[$id]; }
    try {
        $row = q1('SELECT tier, valid_until FROM memberships WHERE user_id = :u', [':u' => $id]);
    } catch (Throwable $e) { return $cache[$id] = 'free'; }
    if (!$row || (string)$row['tier'] !== 'premium') { return $cache[$id] = 'free'; }
    $until = (string)arr($row, 'valid_until', '');
    if ($until !== '' && strtotime($until) < time()) { return $cache[$id] = 'free'; }
    return $cache[$id] = 'premium';
}

/** Haberin görünürlük düzeyi geçerli mi? */
function post_visibility_levels() { return ['public', 'members', 'premium']; }

/**
 * Kullanıcı bu haberin TAM metnini görebilir mi?
 * Personel (posts.view izni olan) her zaman görür — panelde önizleme yapabilmeli.
 */
function post_can_read_full($post, $user) {
    $vis = is_array($post) ? (string)arr($post, 'visibility', 'public') : 'public';
    if ($vis === '' || $vis === 'public') { return true; }
    if (!$user) { return false; }

    // `content.premium` = "aboneye özel içeriği okuma". Katalogda tam bunun için
    // tanımlıydı ama hiçbir kapıda okunmuyordu (denetim turu 3, B07 sınıfı):
    // yayıncı bir rolden bu izni kaldırsa da o rol premium içeriği okumaya devam
    // ediyordu, çünkü kapı yalnız `posts.view`e bakıyordu. Personelin ödeme
    // duvarını aşabilmesi PANELDE ÖNİZLEME yapabilmek içindir; hangi rolün bunu
    // yapabileceği yayıncının kararı olmalıdır.
    if (can($user, 'content.premium')) { return true; }

    if ($vis === 'members') { return true; }              // giriş yapmış her üye
    return member_tier($user) === 'premium';               // 'premium'
}

/**
 * Ödeme duvarı kapısı: TAM metin görünsün mü?
 *
 * post_can_read_full() ABONELİĞE bakar ve giriş yapmamış okura her zaman
 * 'hayır' der. Ölçülü duvar (1.2-05) bunun ÜSTÜNE binen ayrı bir karardır:
 * "bu ay N ücretsiz haber", "hediye bağlantısı", "deneme". Kapı ayrı
 * tutuldu çünkü abonelik kuralı KİMLİKLE, ölçülü duvar SAYAÇLA çalışır ve
 * ikisi karışırsa hangi okurun neden içeri girdiği izlenemez hale gelir.
 *
 * inc/paywall.php yoksa davranış 1.1'dekiyle birebir aynıdır.
 */
function post_reader_can_read_full($post, $user) {
    if (post_can_read_full($post, $user)) { return true; }
    if (function_exists('paywall_allow')) { return (bool)paywall_allow($post, $user); }
    return false;
}

/** Haber kilitli mi (geçerli ziyaretçi için)? */
function post_is_locked($post, $user = null) {
    // OTURUMSUZ ÖN YÜZ (CONTRACTS §3.1): current_user() session_boot() tetikler ve
    // her anonim ziyaretçiye çerez verirdi — sayfa önbelleği devre dışı kalırdı.
    if ($user === null) { $user = current_user_if_session(); }
    return !post_can_read_full($post, $user);
}

/** Sistemde "sorumlu yazı işleri müdürü" işaretli etkin bir kullanıcı var mı? */
function responsible_user_exists() {
    static $var = null;
    if ($var !== null) { return $var; }
    // Sütun göç uygulanmadan yoktur; sorguyu deneyip istisnayı yutarız.
    try { $var = (int)qv('SELECT COUNT(*) FROM users WHERE is_responsible = 1 AND active = 1', [], 0) > 0; }
    catch (Throwable $e) { $var = false; }
    return $var;
}

/** Künyede adı geçecek sorumlu yazı işleri müdürü (yoksa null). */
function responsible_editor_user() {
    if (!responsible_user_exists()) { return null; }
    try {
        return q1('SELECT id, name, email, display_name FROM users
                   WHERE is_responsible = 1 AND active = 1 ORDER BY id ASC LIMIT 1');
    } catch (Throwable $e) { return null; }
}
