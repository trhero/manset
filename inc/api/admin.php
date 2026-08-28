<?php
/**
 * Manşet — panel içerik API uçları (Ajan-2).
 *
 * Kayıtlı uçlar:
 *   posts.list · posts.get · posts.save · posts.delete · posts.restore · posts.purge
 *   posts.bulk · posts.slug_check · posts.takedown
 *   categories.save · categories.delete · categories.reorder
 *   pages.save · pages.delete
 *   comments.moderate · comments.bulk
 *   dashboard.stats
 *
 * Sözleşme: CONTRACTS.md §7. Yanıt biçimi { ok:true, … } / { ok:false, error:'…' }.
 *
 * Sunucu tarafı zorunlu kurallar (istemciye güvenilmez):
 *   · Gövde HTML'i daima sanitize_html($body, true) ile temizlenip kaydedilir.
 *   · Başlık/spot/etiket/SEO alanları sanitize_line() / sanitize_plain() ile süzülür.
 *   · Slug unique_slug() ile üretilir.
 *   · posts.edit_any yoksa yalnız kendi kayıtları (IDOR koruması).
 *   · posts.publish yoksa istenen durum ne olursa olsun 'pending' yazılır.
 *   · 1.1 — posts.delete artık YUMUŞAK silme (çöp kutusu): kayıt durur, deleted_at damgalanır.
 *     Kalıcı silme yalnız posts.purge ucundan ve yalnız admin rolüyle yapılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/schema.php';
require_once INC_DIR . '/media.php';

// ============================================================ ortak yardımcılar

/** Yazma sonrası önbellek temizliği (inc/cache.php varsa). */
/**
 * Sayfa önbelleğini düşürür.
 *
 * KAPSAM (1.3-12): `$scope` verilmezse TÜM önbellek silinir — 1.2'ye kadarki
 * davranış budur ve kapsamı bilinmeyen çağrılar için doğru olan da budur:
 * eksik düşen bir önbellek okura ESKİ HABER gösterir, fazla düşen yalnız
 * birkaç sayfayı yeniden ürettirir. Şüphede geniş düş.
 *
 * Ama kapsamı BİLDİĞİMİZ yerde dar düşmek gerçek bir kazançtır: bu yardımcı
 * 15 ayrı ucu besliyor ve her biri bugüne kadar tek bir haber düzenlemesinde
 * bütün sitenin önbelleğini siliyordu.
 *
 * @param string|array|null $scope 'post:12' · 'category' · ['post:12','category'] · null
 */
function api_admin_flush($scope = null) {
    if (!function_exists('cache_flush')) { return; }
    if ($scope === null) { cache_flush(); return; }
    cache_flush($scope);
}

/** Oturumdaki kullanıcı — api.php yetki kapısından geçtiği için daima doludur. */
function api_admin_user() {
    $u = current_user();
    if (!$u) { json_err('Oturum açmanız gerekiyor.', 401); }
    return $u;
}

/** posts.status beyaz listesi. */
function api_admin_post_statuses() {
    return ['draft', 'pending', 'scheduled', 'published', 'archived'];
}

/** posts.type beyaz listesi. */
function api_admin_post_types() {
    return ['haber', 'makale', 'video'];
}

/** 'Y-m-d\TH:i' / 'Y-m-d H:i:s' girdisini veritabanı biçimine çevirir; geçersizse ''. */
function api_admin_datetime($value) {
    $v = trim((string)$value);
    if ($v === '') { return ''; }
    $v = str_replace('T', ' ', $v);
    $ts = strtotime($v);
    if ($ts === false || $ts <= 0) { return ''; }
    return date('Y-m-d H:i:s', $ts);
}

/** Görsel alanı: uploads içi göreli dosya adı ya da http(s) adresi. */
function api_admin_image_value($value) {
    $v = trim((string)$value);
    if ($v === '') { return ''; }
    if (preg_match('#^https?://#i', $v)) {
        // GÜVENLİK (denetim turu 3, B06): uzak görsel adresi YALNIZ tam
        // düzenleme yetkisi olana açıktır. Üstveri kipindeki bir SEO editörü
        // HER haberin öne çıkan görselini üçüncü taraf bir adrese çevirebiliyordu;
        // o görsel manşette basıldığı sürece her ziyaretçinin IP'si, tarayıcı
        // bilgisi ve Referer'ı o sunucuya gider ve içerik istendiği an
        // değiştirilebilir. Kendi medya kütüphanesindeki dosyalar serbesttir.
        $uzakIzinli = function_exists('current_user')
            && ($cu = current_user()) !== null
            && (can($cu, 'posts.edit_any') || can($cu, 'posts.create'));
        if (!$uzakIzinli) { return ''; }
        return sanitize_is_safe_url($v, false) ? sanitize_line($v, 255) : '';
    }
    $v = ltrim(str_replace('\\', '/', $v), '/');
    if (strpos($v, '..') !== false) { return ''; }
    return preg_match('#^[A-Za-z0-9/_\-\.]{1,255}$#', $v) ? $v : '';
}

/**
 * Haber ajans/YZ kaynaklı mı? (1.1-02)
 * Otomasyon editörünün iş kolu budur: AI ve RSS taslaklarının yazarı daima ilk
 * yönetici olarak yazılır (ai_first_admin_id / inc/rss.php), bu yüzden sahiplik
 * denetimi wire_editor'ü kendi çıktısından bile dışarıda bırakıyordu.
 */
function api_admin_is_wire_post($post) {
    // GUVENLIK (denetim turu 3, B13): bu bir YETKI KARARIDIR, dolayisiyla yalniz
    // SISTEMIN yazdigi alanlara dayanmalidir. `source_url` panelde ELLE
    // doldurulabilen bir kullanici girdisidir: bir yazar kendi haberine herhangi
    // bir adres yazarak onu "ajans haberi" ilan edip Otomasyon Editoru'nun
    // erisimine acabiliyordu.
    //
    // `is_wire` (goc 014) yalniz ice aktaricilar tarafindan yazilir; panel formu
    // bu alani hic gondermez. `ai_generated` de sistem sahiplidir.
    if ((int)arr($post, 'is_wire', 0) === 1) { return true; }
    if ((int)arr($post, 'ai_generated', 0) === 1) { return true; }

    // Goc 014 uygulanmamis kurulumda eski sezgisel kurala dusulur (geriye donuk
    // uyum). Goc uygulandiktan sonra bu dal calismaz.
    if (function_exists('schema_has_column') && schema_has_column('posts', 'is_wire')) {
        return false;
    }
    return trim((string)arr($post, 'source_url', '')) !== '';
}

/**
 * Üstveri kipi (1.1-02): kullanıcı haberin metnine dokunamaz, yalnız üstveriyi düzenler.
 * posts.seo var ama posts.edit_any / posts.edit_own yoksa bu kip devrededir.
 */
function api_admin_metadata_only(array $user) {
    return can($user, 'posts.seo')
        && !can($user, 'posts.edit_any')
        && !can($user, 'posts.edit_own');
}

/** Üstveri kipinde yazılmasına izin verilen alanlar. */
function api_admin_metadata_fields() {
    return ['seo_title', 'seo_desc', 'tags', 'slug', 'image'];
}

/**
 * Yıkıcı işlemleri denetim kaydına yazar (denetim turu 3, B05).
 *
 * NEDEN: manşet sırası değişikliği loglanıyordu ama haberin çöpe atılması,
 * geri alınması, KALICI silinmesi (yorum ve düzeltme geçmişiyle birlikte)
 * ve yayından kaldırılması hiçbir iz bırakmıyordu. Çok yazarlı bir haber
 * odasında "bu haberi kim kaldırdı?" sorusunun cevabı olmalıdır; m.14
 * düzeltme süreçlerinde de gerekebilir.
 *
 * Tablo göç 012'de gelir; yoksa sessizce atlanır (geriye dönük uyum).
 * Kayıt YAZILAMAZSA asıl işlem ENGELLENMEZ — denetim kaydı bir güvence
 * katmanıdır, hizmet kesici değil.
 */
function api_admin_audit(array $user, $action, $target, $detail = '') {
    try {
        if (!function_exists('schema_has_table') || !schema_has_table('audit_log')) { return; }
        db_insert('audit_log', [
            'user_id'    => (int)arr($user, 'id', 0),
            'action'     => sanitize_line((string)$action, 60),
            'target'     => sanitize_line((string)$target, 120),
            'detail'     => sanitize_line((string)$detail, 500),
            'ip'         => client_ip(),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) { log_error('denetim kaydı: ' . $e->getMessage()); }
}

/**
 * Haber kaydını yükler ve IDOR denetimi yapar.
 * posts.edit_any yoksa yalnız author_id = kullanıcının id'si olan kayda izin verilir.
 */
function api_admin_load_post($id, array $user) {
    $id = (int)$id;
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    // GÜVENLİK (denetim turu 3, B01 — KRİTİK): burada ÇIPLAK can('posts.seo')
    // sorulamaz. Üstveri kipinin tanımı api_admin_metadata_only()'dir:
    // "posts.seo VAR **ve** edit_any/edit_own YOK". Çıplak sorulunca
    // `posts.edit_own` + `posts.seo` olan bir kullanıcı hem bu sahiplik
    // kapısını aşıyor hem de üstveri kipine DÜŞMÜYOR (metadata_only false),
    // yani posts.save onu tam yetkili editör sayıyordu. Rol matrisinde en dar
    // görünen "Yalnız üstveri (SEO)" izni, fiilen "her haberi düzenle"ye
    // dönüşüyordu. Kapı ile kip AYNI koşulu kullanmak ZORUNDA.
    if (!can($user, 'posts.edit_any')
        && (int)$post['author_id'] !== (int)$user['id']
        && !(can($user, 'posts.edit_wire') && api_admin_is_wire_post($post))
        && !api_admin_metadata_only($user)) {
        json_err('Yalnız kendi haberlerinizi düzenleyebilirsiniz.', 403);
    }

    // GÜVENLİK (denetim tur 2, B06): sahiplik denetimi tek başına yetmez —
    // haber YAYIMLANDIKTAN sonra düzeltmek ayrı bir izindir. Bu kural eskiden
    // yalnız hizli.php arayüzünde (bir bağlantıyı gizlemek için) uygulanıyordu;
    // muhabir api.php?a=posts.save ile yayındaki haberini hem yeniden yazabiliyor
    // hem status='draft' yapıp siteden düşürebiliyordu. USER_TYPES_PLAN §5'te
    // bunun sunucu tarafında da reddedileceği yazılıydı; kapı burada.
    // posts.seo bu kapıdan muaftır: üstveri kipi metne/duruma DOKUNAMAZ (bkz. posts.save),
    // yalnız SEO alanlarını yazar. SEO editörünün işi zaten yayındaki haberlerdir.
    if ((string)arr($post, 'status', '') === 'published'
        && !can($user, 'posts.edit_any')
        && !api_admin_metadata_only($user)
        && !can($user, 'posts.edit_own_published')) {
        json_err('Yayımlanmış haberi değiştiremezsiniz. Düzeltme için editörünüze başvurun.', 403);
    }

    return $post;
}

/**
 * Çöp kutusu için kaydı yükler — IDOR denetimi yapar ama "yayımlanmış" kapısını
 * uygulamaz. Silinmiş bir haberi geri almak metnini düzenlemek değildir; posts.delete
 * izni olan kendi haberini geri alabilmelidir (yayımlanmışken silinmiş olabilir).
 */
function api_admin_load_post_for_trash($id, array $user, $yayinKapisi = true) {
    $id = (int)$id;
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    if (!can($user, 'posts.edit_any') && (int)$post['author_id'] !== (int)$user['id']) {
        json_err('Yalnız kendi haberlerinizi silebilirsiniz.', 403);
    }

    // GÜVENLİK (denetim turu 3, B04): çöp yolu, tur 2'de kapatılan
    // "yayımlanmış haberi değiştirme" kapısını atlatıyordu. Denetçi kanıtladı:
    // posts.save ile 403 alan bir muhabir, posts.delete + posts.restore ile
    // kendi yayımlanmış haberini istediği an siteden düşürüp geri koyabiliyordu
    // — yani yayımlama yetkisi olmadan fiilî bir yayın/kaldırma anahtarı.
    // Yayımlanmış bir haberi çöpe atmak da bir YAYINDAN KALDIRMA işlemidir.
    if ($yayinKapisi
        && (string)arr($post, 'status', '') === 'published'
        && !can($user, 'posts.edit_any')
        && !can($user, 'posts.publish')
        && !can($user, 'posts.takedown')
        && !can($user, 'posts.edit_own_published')) {
        json_err('Yayımlanmış haberi kaldırma yetkiniz yok. Editörünüze başvurun.', 403);
    }

    return $post;
}

/**
 * Yumuşak silme (1.1-09): kayıt silinmez, deleted_at damgalanır.
 * Bağımlı kayıtlara (yorum, düzeltme) DOKUNULMAZ — geri alınca haber eksiksiz döner.
 * Ön yüz koruması çekirdekte: published_where() zaten deleted_at = '' arar.
 * Sütun yoksa (göç 013 uygulanmamışsa) eski davranışa düşülür.
 */
function api_admin_soft_delete_post($id) {
    $id = (int)$id;
    if (!function_exists('published_supports_trash') || !published_supports_trash()) {
        api_admin_purge_post($id);
        return;
    }
    q('UPDATE posts SET deleted_at = :now, updated_at = :now2 WHERE id = :i',
      [':now' => now(), ':now2' => now(), ':i' => $id]);
}

/** Yorum sayısını bozmadan haberin bağımlı kayıtlarını temizler (KALICI silme). */
function api_admin_purge_post($id) {
    $id = (int)$id;
    q('DELETE FROM comments WHERE post_id = :i', [':i' => $id]);
    if (schema_has_table('corrections')) {
        q('DELETE FROM corrections WHERE post_id = :i', [':i' => $id]);
    }
    q('DELETE FROM posts WHERE id = :i', [':i' => $id]);
}

// ============================================================ posts.list

/**
 * İstek : {q?, status?, category_id?, author_id?, ai_only?:0|1, trash?:0|1, page?:int, per_page?:int}
 * Yanıt : {ok:true, items:[…], total:int, page:int, per_page:int}
 */
api_register('posts.list', function () {
    $u = api_admin_user();
    $d = json_body();

    $page = max(1, (int)arr($d, 'page', 1));
    $perPage = max(5, min(100, (int)arr($d, 'per_page', 20)));
    $where = ['1 = 1'];
    $p = [];

    if (!can($u, 'posts.edit_any')) {
        $where[] = 'p.author_id = :me';
        $p[':me'] = (int)$u['id'];
    }
    $status = (string)arr($d, 'status', '');
    if ($status !== '' && in_array($status, api_admin_post_statuses(), true)) {
        $where[] = 'p.status = :st';
        $p[':st'] = $status;
    }
    $catId = (int)arr($d, 'category_id', 0);
    if ($catId > 0) { $where[] = 'p.category_id = :cid'; $p[':cid'] = $catId; }

    $authorId = (int)arr($d, 'author_id', 0);
    if ($authorId > 0) { $where[] = 'p.author_id = :aid'; $p[':aid'] = $authorId; }

    if ((int)arr($d, 'ai_only', 0) === 1) { $where[] = 'p.ai_generated = 1'; }

    // Çöp kutusu (1.1-09): varsayılan liste silinmişleri GÖSTERMEZ; trash=1 yalnız çöpü verir.
    if (function_exists('published_supports_trash') && published_supports_trash()) {
        $where[] = (int)arr($d, 'trash', 0) === 1 ? 'p.deleted_at <> :bos' : 'p.deleted_at = :bos';
        $p[':bos'] = '';
    }

    $q = media_like_term((string)arr($d, 'q', ''));
    if ($q !== '') {
        $where[] = '(p.title LIKE :q OR p.slug LIKE :q OR p.spot LIKE :q)';
        $p[':q'] = '%' . $q . '%';
    }

    $sqlWhere = ' WHERE ' . implode(' AND ', $where);
    $total = (int)qv('SELECT COUNT(*) FROM posts p' . $sqlWhere, $p, 0);
    $offset = ($page - 1) * $perPage;

    $rows = qa('SELECT p.id, p.title, p.slug, p.status, p.type, p.image, p.category_id, p.author_id,
            p.ai_generated, p.view_count, p.published_at, p.created_at,
            c.name AS category_name, u.name AS author_name
        FROM posts p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN users u ON u.id = p.author_id'
        . $sqlWhere . ' ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
        LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset, $p);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'            => (int)$r['id'],
            'title'         => (string)$r['title'],
            'slug'          => (string)$r['slug'],
            'status'        => (string)$r['status'],
            'type'          => (string)$r['type'],
            'image'         => (string)$r['image'],
            'thumb'         => $r['image'] !== '' ? post_image($r, 'thumb') : '',
            'category_id'   => (int)$r['category_id'],
            'category_name' => (string)arr($r, 'category_name', ''),
            'author_id'     => (int)$r['author_id'],
            'author_name'   => (string)arr($r, 'author_name', ''),
            'ai_generated'  => (int)$r['ai_generated'],
            'view_count'    => (int)$r['view_count'],
            'published_at'  => (string)arr($r, 'published_at', ''),
            'created_at'    => (string)arr($r, 'created_at', ''),
        ];
    }
    return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ posts.get

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, post:{…tüm alanlar…}}
 */
api_register('posts.get', function () {
    $u = api_admin_user();
    $d = json_body();
    $post = api_admin_load_post((int)arr($d, 'id', 0), $u);
    $post['image_url'] = $post['image'] !== '' ? post_image($post, 'medium') : '';
    return ['post' => $post];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ posts.save

/**
 * İstek : {id?:int, title, slug?, spot?, body?, image?, category_id?, type?,
 *          status?, published_at?, source_name?, source_url?, tags?, seo_title?, seo_desc?}
 * Yanıt : {ok:true, id:int, slug:string, status:string, published_at:string, message:string}
 */
api_register('posts.save', function () {
    $u = api_admin_user();
    $d = json_body();
    $id = (int)arr($d, 'id', 0);

    // Uç izni 'posts.view'a çekildi ki üstveri kipi (posts.seo) de girebilsin — posts.seo
    // olan rolde posts.create YOKTUR. Gerçek kapı burada, AÇIKÇA: salt okuyan bir rol
    // (yalnız posts.view) hiçbir şey yazamaz.
    $ustveriKipi = api_admin_metadata_only($u);
    if (!$ustveriKipi
        && !can($u, 'posts.create') && !can($u, 'posts.edit_any')
        && !can($u, 'posts.edit_own') && !can($u, 'posts.edit_wire')) {
        json_err('Haber düzenleme yetkiniz yok.', 403);
    }
    if ($id <= 0 && !can($u, 'posts.create')) { json_err('Haber oluşturma yetkiniz yok.', 403); }

    $existing = null;
    if ($id > 0) { $existing = api_admin_load_post($id, $u); }

    // ---------------------------------------------------------------- üstveri kipi
    // posts.seo var, düzenleme izni yok: YALNIZ üstveri yazılır. Metin/durum/kategori
    // girdileri sessizce yok sayılır — hata döndürmüyoruz ki arayüz sade kalsın ve
    // formun tamamını gönderen istemci gereksiz uyarı almasın.
    if ($ustveriKipi) {
        if (!$existing) { json_err('Üstveri kipinde yeni haber oluşturulamaz.', 403); }

        // KISMİ GÖNDERİM VERİ KAYBETMEZ (denetim turu 3, B08).
        // Eskiden gönderilmeyen her alan BOŞALTILIYOR, boş slug ise başlıktan
        // YENİDEN ÜRETİLİYORDU: yalnız `seo_title` göndermek isteyen bir SEO
        // editörü, farkında olmadan elle yazılmış slug'ı, etiketleri ve görseli
        // siliyordu. Panel formu artık tüm alanları taşıyor ama üçüncü taraf ya da
        // otomatik bir istemci bunu yapmak zorunda değil — kapı SUNUCUDA olmalı.
        // Kural: yalnız İSTEKTE BULUNAN anahtarlar yazılır.
        $ustveri = ['updated_at' => now()];

        $yeniSlug = (string)$existing['slug'];
        if (array_key_exists('slug', $d)) {
            $slugIn = sanitize_line((string)arr($d, 'slug', ''), 200);
            // Boş slug gönderildiğinde başlıktan yeniden üretmek, elle verilmiş
            // adresi sessizce değiştirir ve eski adres 301 geçmişine düşer.
            // Boş gelirse MEVCUT adres korunur.
            if ($slugIn !== '') {
                $yeniSlug = unique_slug('posts', $slugIn, $id);
                if ((string)$existing['slug'] !== $yeniSlug && function_exists('seo_remember_slug')) {
                    seo_remember_slug((int)$id, (string)$existing['slug']);
                }
                $ustveri['slug'] = $yeniSlug;
            }
        }
        if (array_key_exists('image', $d)) {
            $gorsel = api_admin_image_value(arr($d, 'image', ''));
            // Geçersiz görsel değeri (ör. izinsiz uzak adres) MEVCUDU SİLMEZ.
            if ($gorsel !== '' || trim((string)arr($d, 'image', '')) === '') {
                $ustveri['image'] = $gorsel;
            }
        }
        if (array_key_exists('tags', $d)) {
            $ustveri['tags'] = sanitize_line(tags_to_string(arr($d, 'tags', '')), 500);
        }
        if (array_key_exists('seo_title', $d)) {
            $ustveri['seo_title'] = sanitize_line((string)arr($d, 'seo_title', ''), 255);
        }
        if (array_key_exists('seo_desc', $d)) {
            $ustveri['seo_desc'] = sanitize_plain((string)arr($d, 'seo_desc', ''), 500);
        }
        db_update('posts', $ustveri, 'id = :id', [':id' => $id]);
        api_admin_flush();
        return [
            'id'           => (int)$id,
            'slug'         => $yeniSlug,
            'status'       => (string)$existing['status'],
            'published_at' => (string)arr($existing, 'published_at', ''),
            'url'          => url_post(['id' => (int)$id, 'slug' => $yeniSlug]),
            'message'      => 'Üstveri güncellendi.',
        ];
    }

    $title = sanitize_line((string)arr($d, 'title', ''), 255);
    if ($title === '') { json_err('Başlık zorunlu.', 400); }

    // --- slug
    $slugIn = sanitize_line((string)arr($d, 'slug', ''), 200);
    $slugBase = $slugIn !== '' ? $slugIn : $title;
    $slug = unique_slug('posts', $slugBase, $id);

    // Slug değiştiyse eski adresi geçmişe yaz — eski bağlantılar 301 ile korunur.
    // (Orkestratör entegrasyonu, Faz 3: inc/seo.php · Ajan-8)
    if ($id > 0 && $existing && (string)$existing['slug'] !== $slug && function_exists('seo_remember_slug')) {
        seo_remember_slug((int)$id, (string)$existing['slug']);
    }

    // --- durum (posts.publish yoksa zorla pending)
    $status = (string)arr($d, 'status', 'draft');
    if (!in_array($status, api_admin_post_statuses(), true)) { $status = 'draft'; }
    if (!can($u, 'posts.publish') && in_array($status, ['published', 'scheduled'], true)) {
        $status = 'pending';
    }

    // --- yayın zamanı
    $publishedAt = api_admin_datetime(arr($d, 'published_at', ''));
    if ($publishedAt === '' && $existing) { $publishedAt = (string)arr($existing, 'published_at', ''); }

    if ($status === 'scheduled') {
        // Gelecekte değilse doğrudan yayımla
        if ($publishedAt === '' || strtotime($publishedAt) <= time()) {
            $status = 'published';
            if ($publishedAt === '') { $publishedAt = now(); }
        }
    }
    if ($status === 'published' && $publishedAt === '') { $publishedAt = now(); }

    // --- tür
    $type = (string)arr($d, 'type', 'haber');
    if (!in_array($type, api_admin_post_types(), true)) { $type = 'haber'; }

    // --- kategori
    $catId = (int)arr($d, 'category_id', 0);
    if ($catId > 0 && !qv('SELECT 1 FROM categories WHERE id = :i', [':i' => $catId])) { $catId = 0; }

    // --- metin alanları
    $spot = sanitize_plain((string)arr($d, 'spot', ''), 500);
    $body = sanitize_html((string)arr($d, 'body', ''), true);
    $tags = sanitize_line(tags_to_string(arr($d, 'tags', '')), 500);
    $seoTitle = sanitize_line((string)arr($d, 'seo_title', ''), 255);
    $seoDesc = sanitize_plain((string)arr($d, 'seo_desc', ''), 500);
    $sourceName = sanitize_line((string)arr($d, 'source_name', ''), 190);
    $sourceUrl = trim((string)arr($d, 'source_url', ''));
    if ($sourceUrl !== '' && !preg_match('#^https?://#i', $sourceUrl)) { $sourceUrl = ''; }
    if ($sourceUrl !== '') { $sourceUrl = sanitize_line($sourceUrl, 500); }

    $data = [
        'title'        => $title,
        'slug'         => $slug,
        'spot'         => $spot,
        'body'         => $body,
        'image'        => api_admin_image_value(arr($d, 'image', '')),
        'category_id'  => $catId,
        'status'       => $status,
        'type'         => $type,
        'source_name'  => $sourceName,
        'source_url'   => $sourceUrl,
        'seo_title'    => $seoTitle,
        'seo_desc'     => $seoDesc,
        'tags'         => $tags,
        'published_at' => $publishedAt !== '' ? $publishedAt : null,
        'updated_at'   => now(),
    ];

    // Faz 7d — içerik kilidi. Sütunlar göç 011 ile gelir; yoksa alanlar atlanır.
    if (function_exists('post_visibility_levels')) {
        $vis = (string)arr($d, 'visibility', '');
        if ($vis !== '' && in_array($vis, post_visibility_levels(), true)) {
            $data['visibility'] = $vis;
            $data['teaser'] = sanitize_plain((string)arr($d, 'teaser', ''), 600);
        }
    }

    if ($id > 0) {
        db_update('posts', $data, 'id = :id', [':id' => $id]);
        $msg = 'Haber güncellendi.';
    } else {
        $data['author_id'] = (int)$u['id'];
        $data['created_at'] = now();
        $data['ai_generated'] = (int)arr($d, 'ai_generated', 0) === 1 ? 1 : 0;
        $id = db_insert('posts', $data);
        $msg = 'Haber oluşturuldu.';
    }

    // Haberin kendi sayfası + liste sayfaları (anasayfa/kategori/etiket/arama).
    // Metin düzenlemesi de liste sayfalarının HTML'inde geçtiği için `post:<id>`
    // etiketiyle zaten yakalanır; `category` durum/kategori/tarih değişimi içindir.
    api_admin_flush(['post:' . (int)$id, 'category']);

    // ARAMA MOTORU BİLDİRİMİ (1.2-07): haber ŞU AN yayında ise adresi kuyruğa gir.
    // cron keşfi `posts.id` su işaretine bakıyor; var olan bir taslak yayına
    // alındığında id DEĞİŞMEDİĞİ için o keşif onu hiç görmez. Kuyruk zaten
    // yinelenen adresi elediği için buradan gelen fazladan çağrı zararsızdır —
    // yayın akışı beklemiyor, yalnızca satır yazılıyor.
    if ($status === 'published' && function_exists('seo_ping_enqueue')) {
        seo_ping_enqueue(url_post(['id' => (int)$id, 'slug' => $slug]), 'indexnow');
    }

    return [
        'id'           => (int)$id,
        'slug'         => $slug,
        'status'       => $status,
        'published_at' => $publishedAt,
        'url'          => url_post(['id' => (int)$id, 'slug' => $slug]),
        'message'      => $msg,
    ];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ posts.delete (yumuşak)

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, message:string}
 * Not   : Kayıt çöp kutusuna taşınır; geri almak için posts.restore.
 */
api_register('posts.delete', function () {
    $u = api_admin_user();
    $d = json_body();
    $post = api_admin_load_post_for_trash((int)arr($d, 'id', 0), $u);
    api_admin_soft_delete_post((int)$post['id']);
    api_admin_flush(['post:' . (int)$post['id'], 'category']);
    return ['id' => (int)$post['id'], 'message' => 'Haber çöp kutusuna taşındı.'];
}, ['perm' => 'posts.delete', 'methods' => ['POST']]);

// ============================================================ posts.restore

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, message:string}
 * Not   : Çöp kutusundaki haberi geri alır. Durum alanına dokunulmaz —
 *         yayındaki bir haber silinmişse geri alınca yine yayına döner.
 */
api_register('posts.restore', function () {
    $u = api_admin_user();
    $d = json_body();
    if (!function_exists('published_supports_trash') || !published_supports_trash()) {
        json_err('Çöp kutusu bu kurulumda etkin değil.', 400);
    }
    $post = api_admin_load_post_for_trash((int)arr($d, 'id', 0), $u);
    if ((string)arr($post, 'deleted_at', '') === '') {
        json_err('Bu haber çöp kutusunda değil.', 400);
    }
    q('UPDATE posts SET deleted_at = :bos, updated_at = :now WHERE id = :i',
      [':bos' => '', ':now' => now(), ':i' => (int)$post['id']]);
    api_admin_flush(['post:' . (int)$post['id'], 'category']);
    return ['id' => (int)$post['id'], 'message' => 'Haber geri alındı.'];
}, ['perm' => 'posts.delete', 'methods' => ['POST']]);

// ============================================================ posts.purge

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, message:string}
 * Not   : KALICI silme — haber, yorumları ve düzeltmeleri geri dönüşsüz gider.
 */
api_register('posts.purge', function () {
    $u = api_admin_user();

    // GEREKÇE: kalıcı silme geri alınamaz ve yorum/düzeltme geçmişini de götürür.
    // posts.delete izni artık yalnız "çöp kutusuna taşı" anlamına geliyor; editör
    // yanlışlıkla sildiğinde geri alabilsin diye. Kaydı gerçekten yok etme kararı
    // yalnız site sahibinin olmalı, o yüzden izin değil ROL kapısı koyuldu:
    // can($u,'posts.delete') burada yetmez.
    if ((string)arr($u, 'role', '') !== 'admin') {
        json_err('Kalıcı silme yalnız yöneticiye açıktır.', 403);
    }

    $d = json_body();
    // yayinKapisi=false: admin zaten her şeyi yapabilir, ikinci kapı gereksiz.
    $post = api_admin_load_post_for_trash((int)arr($d, 'id', 0), $u, false);

    // GÜVENLİK (denetim turu 3, B12): kalıcı silme YALNIZ çöpteki kayda uygulanır.
    // Eskiden canlı bir haber tek istekle, çöp kutusuna hiç uğramadan, yorum ve
    // düzeltme geçmişiyle birlikte yok edilebiliyordu. Çöp kutusunun varlık
    // sebebi bu iki adımlı olmasıdır: önce kaldır, sonra bilinçli olarak imha et.
    if (function_exists('published_supports_trash') && published_supports_trash()
        && trim((string)arr($post, 'deleted_at', '')) === '') {
        json_err('Önce çöp kutusuna taşıyın. Kalıcı silme yalnız çöpteki habere uygulanır.', 409);
    }

    api_admin_audit($u, 'posts.purge', 'post#' . (int)$post['id'], (string)arr($post, 'title', ''));
    api_admin_purge_post((int)$post['id']);
    api_admin_flush(['post:' . (int)$post['id'], 'category']);
    return ['id' => (int)$post['id'], 'message' => 'Haber kalıcı olarak silindi.'];
}, ['perm' => 'posts.delete', 'methods' => ['POST']]);

// ============================================================ posts.bulk

/**
 * İstek : {action:'publish'|'draft'|'archive'|'delete', ids:[int]}
 * Yanıt : {ok:true, action:string, count:int, message:string}
 */
api_register('posts.bulk', function () {
    $u = api_admin_user();
    $d = json_body();
    $action = (string)arr($d, 'action', '');
    if (!in_array($action, ['publish', 'draft', 'archive', 'delete'], true)) {
        json_err('Bilinmeyen toplu işlem.', 400);
    }
    if ($action === 'publish' && !can($u, 'posts.publish')) { json_err('Yayımlama yetkiniz yok.', 403); }
    if ($action === 'delete' && !can($u, 'posts.delete')) { json_err('Silme yetkiniz yok.', 403); }

    $ids = arr($d, 'ids', []);
    if (!is_array($ids) || !$ids) { json_err('Hiç kayıt seçilmedi.', 400); }
    $clean = [];
    foreach ($ids as $one) { $n = (int)$one; if ($n > 0) { $clean[$n] = $n; } }
    if (!$clean) { json_err('Hiç kayıt seçilmedi.', 400); }
    if (count($clean) > 200) { json_err('Tek seferde en çok 200 kayıt işlenebilir.', 400); }

    $count = 0;
    foreach ($clean as $one) {
        $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $one]);
        if (!$post) { continue; }
        if (!can($u, 'posts.edit_any') && (int)$post['author_id'] !== (int)$u['id']) { continue; }
        // Çöptekinin durumu toplu işlemle değiştirilmez; önce geri alınmalı.
        if ($action !== 'delete' && (string)arr($post, 'deleted_at', '') !== '') { continue; }

        if ($action === 'delete') {
            // Toplu silme de yumuşak — 200 kaydı geri dönüşsüz yok etmek felaket olur.
            api_admin_soft_delete_post((int)$post['id']);
            $count++;
            continue;
        }
        $map = ['publish' => 'published', 'draft' => 'draft', 'archive' => 'archived'];
        $new = $map[$action];
        $set = ['status' => $new, 'updated_at' => now()];
        if ($new === 'published' && (string)arr($post, 'published_at', '') === '') { $set['published_at'] = now(); }
        db_update('posts', $set, 'id = :id', [':id' => (int)$post['id']]);
        // Eski bir taslak yayına alındığında id'si su işaretinin altında kalır ve
        // cron keşfi onu görmez (bkz. cron_publish_scheduled'daki aynı gerekçe).
        if ($new === 'published' && function_exists('seo_ping_enqueue') && function_exists('url_post')) {
            seo_ping_enqueue(url_post($post), 'indexnow');
        }
        $count++;
    }

    api_admin_flush();
    $labels = ['publish' => 'yayımlandı', 'draft' => 'taslağa alındı', 'archive' => 'arşivlendi',
               'delete' => 'çöp kutusuna taşındı'];
    return ['action' => $action, 'count' => $count, 'message' => $count . ' haber ' . $labels[$action] . '.'];
}, ['perm' => 'posts.edit_any', 'methods' => ['POST']]);

// ============================================================ posts.slug_check

/**
 * İstek : {slug?:string, title?:string, id?:int}
 * Yanıt : {ok:true, slug:string, available:bool, suggestion:string}
 */
api_register('posts.slug_check', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    $raw = (string)arr($d, 'slug', '');
    if (trim($raw) === '') { $raw = (string)arr($d, 'title', ''); }
    $slug = slugify($raw, 200);
    $clash = q1('SELECT id FROM posts WHERE slug = :s AND id <> :i LIMIT 1', [':s' => $slug, ':i' => $id]);
    $suggestion = unique_slug('posts', $slug, $id);
    return ['slug' => $slug, 'available' => !$clash, 'suggestion' => $suggestion];
    // İzin posts.view: salt okuma ve üstveri kipindeki SEO editörünün posts.create'i yok.
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ posts.takedown

/**
 * İstek : {id:int, reason?:string}
 * Yanıt : {ok:true, id:int, status:string, message:string}
 *
 * Yayından çekme (1.1-02). posts.save üzerinden DEĞİL ayrı bir uç: bu yetki, tam
 * düzenleme hakkı olmayan birinin (hukuk/nöbetçi) hukuki ya da acil bir durumda
 * içeriği hızla siteden düşürebilmesi içindir. Metne dokunmaz, silmez —
 * yalnız status = 'archived' yapar; haber panelde durur, geri yayımlanabilir.
 */
api_register('posts.takedown', function () {
    $u = api_admin_user();
    $d = json_body();
    $id = (int)arr($d, 'id', 0);

    // GÜVENLİK (denetim turu 3, B03): bu uç YIKICI bir işlemdir — haberi
    // siteden düşürür. Eskiden ne sahiplik ne de okuma izni arıyordu; yalnız
    // `posts.takedown` grantı verilmiş bir moderator, id sayarak tüm siteyi
    // yayından kaldırabiliyordu. Kapı ortak yükleyiciye bağlandı: sahiplik ve
    // görünürlük denetimi orada. Ayrıca hız sınırı ve denetim kaydı eklendi.
    if (!can($u, 'posts.view')) { json_err('Bu işlem için yetkiniz yok.', 403); }
    if (!rate_limit('takedown:' . (int)$u['id'], 20, 600)) {
        json_err('Çok fazla yayından kaldırma isteği. Lütfen bekleyin.', 429);
    }
    $post = api_admin_load_post($id, $u);

    if ((string)$post['status'] === 'archived') {
        return ['id' => $id, 'status' => 'archived', 'message' => 'Haber zaten yayında değil.'];
    }
    $sebep = sanitize_line((string)arr($d, 'reason', ''), 200);
    db_update('posts', ['status' => 'archived', 'updated_at' => now()], 'id = :id', [':id' => $id]);
    api_admin_audit($u, 'posts.takedown', 'post#' . $id,
        $sebep !== '' ? $sebep : (string)arr($post, 'title', ''));
    api_admin_flush(['post:' . $id, 'category']);
    return ['id' => $id, 'status' => 'archived', 'message' => 'Haber yayından kaldırıldı.'];
}, ['perm' => 'posts.takedown', 'methods' => ['POST']]);

// ============================================================ categories.save

/**
 * İstek : {id?:int, name, slug?, color?, sort?, in_menu?:0|1, active?:0|1}
 * Yanıt : {ok:true, id:int, slug:string, message:string}
 */
api_register('categories.save', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id > 0 && !qv('SELECT 1 FROM categories WHERE id = :i', [':i' => $id])) {
        json_err('Kategori bulunamadı.', 404);
    }
    $name = sanitize_line((string)arr($d, 'name', ''), 120);
    if ($name === '') { json_err('Kategori adı zorunlu.', 400); }

    $slugIn = sanitize_line((string)arr($d, 'slug', ''), 140);
    $slug = unique_slug('categories', $slugIn !== '' ? $slugIn : $name, $id);

    $color = strtoupper(trim((string)arr($d, 'color', '#C0392B')));
    if (!preg_match('/^#([0-9A-F]{3}|[0-9A-F]{6})$/', $color)) { $color = '#C0392B'; }

    $data = [
        'name'    => $name,
        'slug'    => $slug,
        'color'   => $color,
        'in_menu' => (int)arr($d, 'in_menu', 1) === 1 ? 1 : 0,
        'active'  => (int)arr($d, 'active', 1) === 1 ? 1 : 0,
    ];
    if (array_key_exists('sort', $d)) { $data['sort'] = max(0, min(9999, (int)$d['sort'])); }

    if ($id > 0) {
        db_update('categories', $data, 'id = :id', [':id' => $id]);
        $msg = 'Kategori güncellendi.';
    } else {
        if (!isset($data['sort'])) {
            $data['sort'] = (int)qv('SELECT COALESCE(MAX(sort), 0) + 1 FROM categories', [], 1);
        }
        $id = db_insert('categories', $data);
        $msg = 'Kategori eklendi.';
    }
    api_admin_flush();
    return ['id' => (int)$id, 'slug' => $slug, 'message' => $msg];
}, ['perm' => 'categories.manage', 'methods' => ['POST']]);

// ============================================================ categories.delete

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, moved:int, message:string}
 * Not   : Kategorideki haberler silinmez; category_id = 0 yapılır (veri kaybı yok).
 */
api_register('categories.delete', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    $cat = q1('SELECT id, name FROM categories WHERE id = :i', [':i' => $id]);
    if (!$cat) { json_err('Kategori bulunamadı.', 404); }

    $moved = (int)qv('SELECT COUNT(*) FROM posts WHERE category_id = :i', [':i' => $id], 0);
    if ($moved > 0) {
        q('UPDATE posts SET category_id = 0 WHERE category_id = :i', [':i' => $id]);
    }
    if (schema_has_table('rss_sources')) {
        q('UPDATE rss_sources SET category_id = 0 WHERE category_id = :i', [':i' => $id]);
    }
    q('DELETE FROM categories WHERE id = :i', [':i' => $id]);

    api_admin_flush();
    $msg = 'Kategori silindi.';
    if ($moved > 0) { $msg .= ' ' . $moved . ' haber kategorisiz bırakıldı.'; }
    return ['id' => $id, 'moved' => $moved, 'message' => $msg];
}, ['perm' => 'categories.manage', 'methods' => ['POST']]);

// ============================================================ categories.reorder

/**
 * İstek : {ids:[int,…]}  (tam sıra)  ya da  {id:int, dir:'up'|'down'}
 * Yanıt : {ok:true, message:string}
 */
api_register('categories.reorder', function () {
    $d = json_body();
    $ids = arr($d, 'ids', null);

    if (is_array($ids) && $ids) {
        $sort = 1;
        foreach ($ids as $one) {
            $cid = (int)$one;
            if ($cid <= 0) { continue; }
            db_update('categories', ['sort' => $sort], 'id = :id', [':id' => $cid]);
            $sort++;
        }
        api_admin_flush();
        return ['message' => 'Sıralama kaydedildi.'];
    }

    $id = (int)arr($d, 'id', 0);
    $dir = (string)arr($d, 'dir', '');
    if ($id <= 0 || !in_array($dir, ['up', 'down'], true)) { json_err('Geçersiz sıralama isteği.', 400); }

    // Mevcut sırayı normalize et, sonra komşu ile takas et
    $all = qa('SELECT id FROM categories ORDER BY sort ASC, name ASC');
    $order = [];
    foreach ($all as $i => $row) { $order[] = (int)$row['id']; }
    $pos = array_search($id, $order, true);
    if ($pos === false) { json_err('Kategori bulunamadı.', 404); }
    $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
    if ($swap < 0 || $swap >= count($order)) { return ['message' => 'Sıra değişmedi.']; }
    $tmp = $order[$swap];
    $order[$swap] = $order[$pos];
    $order[$pos] = $tmp;

    $sort = 1;
    foreach ($order as $cid) {
        db_update('categories', ['sort' => $sort], 'id = :id', [':id' => $cid]);
        $sort++;
    }
    api_admin_flush();
    return ['message' => 'Sıralama güncellendi.'];
}, ['perm' => 'categories.manage', 'methods' => ['POST']]);

// ============================================================ pages.save

/** Kullanıcının alamayacağı rezerve slug'lar (SEF desenleriyle çakışır). */
function api_admin_reserved_page_slugs() {
    return ['kunye', 'arama', 'haber', 'kategori', 'etiket', 'sayfa', 'rss', 'feed', 'sitemap', 'robots'];
}

/**
 * İstek : {id?:int, title, slug?, body?, in_footer?:0|1, sort?:int}
 * Yanıt : {ok:true, id:int, slug:string, url:string, message:string}
 */
api_register('pages.save', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id > 0 && !qv('SELECT 1 FROM pages WHERE id = :i', [':i' => $id])) {
        json_err('Sayfa bulunamadı.', 404);
    }
    $title = sanitize_line((string)arr($d, 'title', ''), 190);
    if ($title === '') { json_err('Başlık zorunlu.', 400); }

    $slugIn = sanitize_line((string)arr($d, 'slug', ''), 200);
    $wanted = slugify($slugIn !== '' ? $slugIn : $title, 200);
    if (in_array($wanted, api_admin_reserved_page_slugs(), true)) {
        json_err('“' . $wanted . '” adresi sistem tarafından ayrılmıştır, başka bir adres seçin.', 400);
    }
    $slug = unique_slug('pages', $wanted, $id);

    $data = [
        'title'      => $title,
        'slug'       => $slug,
        'body'       => sanitize_html((string)arr($d, 'body', ''), true),
        'in_footer'  => (int)arr($d, 'in_footer', 1) === 1 ? 1 : 0,
        'sort'       => max(0, min(9999, (int)arr($d, 'sort', 0))),
        'updated_at' => now(),
    ];

    if ($id > 0) {
        db_update('pages', $data, 'id = :id', [':id' => $id]);
        $msg = 'Sayfa güncellendi.';
    } else {
        $data['created_at'] = now();
        $id = db_insert('pages', $data);
        $msg = 'Sayfa oluşturuldu.';
    }
    api_admin_flush();
    return ['id' => (int)$id, 'slug' => $slug, 'url' => url_page(['slug' => $slug]), 'message' => $msg];
}, ['perm' => 'pages.manage', 'methods' => ['POST']]);

// ============================================================ pages.delete

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, message:string}
 */
api_register('pages.delete', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if (!qv('SELECT 1 FROM pages WHERE id = :i', [':i' => $id])) { json_err('Sayfa bulunamadı.', 404); }
    q('DELETE FROM pages WHERE id = :i', [':i' => $id]);
    api_admin_flush();
    return ['id' => $id, 'message' => 'Sayfa silindi.'];
}, ['perm' => 'pages.manage', 'methods' => ['POST']]);

// ============================================================ comments.moderate

/** Yorum işlemini uygular; işlenen kayıt sayısını döndürür. */
function api_admin_comment_apply($id, $action) {
    $id = (int)$id;
    if (!qv('SELECT 1 FROM comments WHERE id = :i', [':i' => $id])) { return 0; }
    if ($action === 'delete') {
        // YANITLARI DA SİL (1.3-07). Kökü silip yanıtları bırakmak veri düzeyinde
        // ARTIK üretir: ön yüz onları göstermez (ebeveyni yok), panel işaretler,
        // ama satırlar sonsuza dek kalır ve moderatör sildiğini sanır.
        // Zincir TEK SEVİYE olduğu için bir geçiş yeterlidir; daha derin bir
        // zincir olsaydı burada özyineleme ya da döngü gerekirdi.
        $yanit = 0;
        if (function_exists('schema_has_column') && schema_has_column('comments', 'parent_id')) {
            $yanit = (int)q('DELETE FROM comments WHERE parent_id = :i', [':i' => $id])->rowCount();
        }
        q('DELETE FROM comments WHERE id = :i', [':i' => $id]);
        return 1 + $yanit;
    }
    $map = ['approve' => 'approved', 'spam' => 'spam', 'pending' => 'pending'];
    if (!isset($map[$action])) { return 0; }
    db_update('comments', ['status' => $map[$action]], 'id = :id', [':id' => $id]);
    return 1;
}

/**
 * İstek : {id:int, action:'approve'|'spam'|'pending'|'delete'}
 * Yanıt : {ok:true, id:int, action:string, message:string}
 */
api_register('comments.moderate', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    $action = (string)arr($d, 'action', '');
    if (!in_array($action, ['approve', 'spam', 'pending', 'delete'], true)) {
        json_err('Bilinmeyen yorum işlemi.', 400);
    }
    if (!api_admin_comment_apply($id, $action)) { json_err('Yorum bulunamadı.', 404); }
    api_admin_flush();
    $labels = ['approve' => 'Yorum onaylandı.', 'spam' => 'Yorum spam olarak işaretlendi.',
               'pending' => 'Yorum beklemeye alındı.', 'delete' => 'Yorum silindi.'];
    return ['id' => $id, 'action' => $action, 'message' => $labels[$action]];
}, ['perm' => 'comments.moderate', 'methods' => ['POST']]);

// ============================================================ comments.bulk

/**
 * İstek : {ids:[int], action:'approve'|'spam'|'pending'|'delete'}
 * Yanıt : {ok:true, count:int, message:string}
 */
api_register('comments.bulk', function () {
    $d = json_body();
    $action = (string)arr($d, 'action', '');
    if (!in_array($action, ['approve', 'spam', 'pending', 'delete'], true)) {
        json_err('Bilinmeyen yorum işlemi.', 400);
    }
    $ids = arr($d, 'ids', []);
    if (!is_array($ids) || !$ids) { json_err('Hiç yorum seçilmedi.', 400); }
    if (count($ids) > 300) { json_err('Tek seferde en çok 300 yorum işlenebilir.', 400); }

    $count = 0;
    foreach ($ids as $one) { $count += api_admin_comment_apply((int)$one, $action); }
    api_admin_flush();
    $labels = ['approve' => 'onaylandı', 'spam' => 'spam olarak işaretlendi',
               'pending' => 'beklemeye alındı', 'delete' => 'silindi'];
    return ['count' => $count, 'message' => $count . ' yorum ' . $labels[$action] . '.'];
}, ['perm' => 'comments.moderate', 'methods' => ['POST']]);

// ============================================================ dashboard.stats

/**
 * İstek : {} (gövde gerekmez)
 * Yanıt : {ok:true, published, draft, pending_posts, comments_pending, rss_new,
 *          ai_queue, views_total, media_count, media_size}
 */
api_register('dashboard.stats', function () {
    $u = api_admin_user();
    $mine = can($u, 'posts.edit_any') ? '' : ' AND author_id = ' . (int)$u['id'];
    // Çöp kutusundaki haber sayaçlara girmesin (1.1-09).
    if (function_exists('published_supports_trash') && published_supports_trash()) {
        $mine .= ' AND deleted_at = \'\'';
    }

    $out = [
        'published'        => (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'published\'' . $mine, [], 0),
        'draft'            => (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'draft\'' . $mine, [], 0),
        'pending_posts'    => (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'pending\'' . $mine, [], 0),
        'scheduled'        => (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'scheduled\'' . $mine, [], 0),
        'comments_pending' => 0,
        'rss_new'          => 0,
        'ai_queue'         => 0,
        'views_total'      => (int)qv('SELECT COALESCE(SUM(view_count), 0) FROM posts', [], 0),
        'media_count'      => (int)qv('SELECT COUNT(*) FROM media', [], 0),
        'media_size'       => media_total_size(),
    ];
    if (can($u, 'comments.moderate')) {
        $out['comments_pending'] = (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'pending\'', [], 0);
    }
    if (schema_has_table('rss_items')) {
        $out['rss_new'] = (int)qv('SELECT COUNT(*) FROM rss_items WHERE status = \'new\'', [], 0);
    }
    if (schema_has_table('ai_jobs')) {
        $out['ai_queue'] = (int)qv('SELECT COUNT(*) FROM ai_jobs WHERE status IN (\'queued\', \'running\')', [], 0);
    }
    return $out;
}, ['perm' => 'admin.access', 'methods' => ['POST']]);
