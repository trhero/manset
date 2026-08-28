<?php
/**
 * Manşet — panel içerik API uçları (Ajan-2).
 *
 * Kayıtlı uçlar:
 *   posts.list · posts.get · posts.save · posts.delete · posts.bulk · posts.slug_check
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
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/schema.php';
require_once INC_DIR . '/media.php';

// ============================================================ ortak yardımcılar

/** Yazma sonrası önbellek temizliği (inc/cache.php varsa). */
function api_admin_flush() {
    if (function_exists('cache_flush')) { cache_flush(); }
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
        return sanitize_is_safe_url($v, false) ? sanitize_line($v, 255) : '';
    }
    $v = ltrim(str_replace('\\', '/', $v), '/');
    if (strpos($v, '..') !== false) { return ''; }
    return preg_match('#^[A-Za-z0-9/_\-\.]{1,255}$#', $v) ? $v : '';
}

/**
 * Haber kaydını yükler ve IDOR denetimi yapar.
 * posts.edit_any yoksa yalnız author_id = kullanıcının id'si olan kayda izin verilir.
 */
function api_admin_load_post($id, array $user) {
    $id = (int)$id;
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    if (!can($user, 'posts.edit_any') && (int)$post['author_id'] !== (int)$user['id']) {
        json_err('Yalnız kendi haberlerinizi düzenleyebilirsiniz.', 403);
    }

    // GÜVENLİK (denetim tur 2, B06): sahiplik denetimi tek başına yetmez —
    // haber YAYIMLANDIKTAN sonra düzeltmek ayrı bir izindir. Bu kural eskiden
    // yalnız hizli.php arayüzünde (bir bağlantıyı gizlemek için) uygulanıyordu;
    // muhabir api.php?a=posts.save ile yayındaki haberini hem yeniden yazabiliyor
    // hem status='draft' yapıp siteden düşürebiliyordu. USER_TYPES_PLAN §5'te
    // bunun sunucu tarafında da reddedileceği yazılıydı; kapı burada.
    if ((string)arr($post, 'status', '') === 'published'
        && !can($user, 'posts.edit_any')
        && !can($user, 'posts.edit_own_published')) {
        json_err('Yayımlanmış haberi değiştiremezsiniz. Düzeltme için editörünüze başvurun.', 403);
    }

    return $post;
}

/** Yorum sayısını bozmadan haberin bağımlı kayıtlarını temizler. */
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
 * İstek : {q?, status?, category_id?, author_id?, ai_only?:0|1, page?:int, per_page?:int}
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

    $existing = null;
    if ($id > 0) { $existing = api_admin_load_post($id, $u); }

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

    api_admin_flush();
    return [
        'id'           => (int)$id,
        'slug'         => $slug,
        'status'       => $status,
        'published_at' => $publishedAt,
        'url'          => url_post(['id' => (int)$id, 'slug' => $slug]),
        'message'      => $msg,
    ];
}, ['perm' => 'posts.create', 'methods' => ['POST']]);

// ============================================================ posts.delete

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, message:string}
 */
api_register('posts.delete', function () {
    $u = api_admin_user();
    $d = json_body();
    $post = api_admin_load_post((int)arr($d, 'id', 0), $u);
    api_admin_purge_post((int)$post['id']);
    api_admin_flush();
    return ['id' => (int)$post['id'], 'message' => 'Haber silindi.'];
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
        $post = q1('SELECT id, author_id, status, published_at FROM posts WHERE id = :i', [':i' => $one]);
        if (!$post) { continue; }
        if (!can($u, 'posts.edit_any') && (int)$post['author_id'] !== (int)$u['id']) { continue; }

        if ($action === 'delete') {
            api_admin_purge_post((int)$post['id']);
            $count++;
            continue;
        }
        $map = ['publish' => 'published', 'draft' => 'draft', 'archive' => 'archived'];
        $new = $map[$action];
        $set = ['status' => $new, 'updated_at' => now()];
        if ($new === 'published' && (string)arr($post, 'published_at', '') === '') { $set['published_at'] = now(); }
        db_update('posts', $set, 'id = :id', [':id' => (int)$post['id']]);
        $count++;
    }

    api_admin_flush();
    $labels = ['publish' => 'yayımlandı', 'draft' => 'taslağa alındı', 'archive' => 'arşivlendi', 'delete' => 'silindi'];
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
}, ['perm' => 'posts.create', 'methods' => ['POST']]);

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
        q('DELETE FROM comments WHERE id = :i', [':i' => $id]);
        return 1;
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
