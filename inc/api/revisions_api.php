<?php
/**
 * Manşet — sürüm geçmişi / düzenleme kilidi / onay akışı API uçları. (Ajan-F · 1.3-01, 1.3-02, 1.3-11)
 *
 * Kayıtlı uçlar:
 *   revisions.lock · revisions.unlock · revisions.autosave · revisions.snapshot
 *   revisions.list · revisions.get · revisions.restore
 *   revisions.note · revisions.request_changes · revisions.approve
 *   posts.bulk_assign
 *
 * Yanıt biçimi CONTRACTS §7: { ok:true, … } / { ok:false, error:'…' }.
 *
 * YETKİ: uçların `perm` değeri `posts.view`tir — üstveri kipindeki SEO editörü
 * de sürüm listesini görebilmeli ve `posts.seo` rolünde `posts.create` YOKTUR
 * (aynı gerekçe `posts.save` ucunda da yazılı). Gerçek kapı her ucun içinde,
 * AÇIKÇA uygulanır; salt okuyan bir rol hiçbir şey yazamaz.
 *
 * `api_admin_load_post()` ve `api_admin_metadata_only()` `inc/api/admin.php`
 * içindedir ve `api.php` bu dizini bütün olarak yükler. O dosya bizim değil;
 * yalnız ÇAĞIRIYORUZ — sahiplik ve "yayımlanmışı düzenleme" kapılarının tek bir
 * yerde tanımlı kalması için kopyalamıyoruz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/revisions.php';

// ============================================================ ortak yardımcılar

/** Oturumdaki kullanıcı. */
function revapi_user() {
    $u = current_user();
    if (!$u) { json_err('Oturum açmanız gerekiyor.', 401); }
    return $u;
}

/** Göç 028 uygulanmamışsa uçlar açıkça hata döner (sessiz "başarılı" olmaz). */
function revapi_require_tables() {
    if (!revisions_ready()) {
        json_err('Sürüm geçmişi tabloları kurulmamış. Panelde yükseltmeyi çalıştırın.', 503);
    }
}

/** Habere DOKUNMA (okuma/not) kapısı — yazma kapısı ayrıca uygulanır. */
function revapi_post_or_403($id, array $u) {
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => (int)$id]);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    if (!revisions_can_touch($post, $u)) {
        json_err('Bu haber üzerinde yetkiniz yok.', 403);
    }
    return $post;
}

/** Kilit durumunu arayüzün anlayacağı biçimde döndürür. */
function revapi_lock_payload($postId, array $u) {
    $d = revisions_lock_state((int)$postId);
    return [
        'locked_by'   => (int)$d['by'],
        'locked_name' => (string)$d['by_name'],
        'locked_at'   => (string)$d['at'],
        'expired'     => (bool)$d['expired'],
        'mine'        => ((int)$d['by'] === (int)arr($u, 'id', 0) && !$d['expired']),
        'ttl'         => revisions_lock_ttl(),
    ];
}

// ============================================================ revisions.lock

/**
 * İstek : {post_id:int, takeover?:0|1, seen_by?:int}
 * Yanıt : {ok:true, acquired:bool, lock:{…}, interval:int}
 *
 * Kilidi ATOMİK olarak almaya çalışır. `takeover=1` gönderildiğinde arayüzün
 * EKRANDA GÖRDÜĞÜ sahip (`seen_by`) ile karşılaştır-ve-değiştir yapılır: iki
 * editör aynı anda devralmaya kalkarsa yalnız biri kazanır.
 */
api_register('revisions.lock', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);

    $devral = (int)arr($d, 'takeover', 0) === 1;
    $gorulen = $devral ? max(0, (int)arr($d, 'seen_by', 0)) : -1;

    // Devralma yıkıcı bir işlemdir (başkasının ekranını kilitten düşürür):
    // hız sınırı ve denetim kaydı, `posts.takedown` ucundaki desenin aynısı.
    if ($devral) {
        if (!rate_limit('revlock:' . (int)$u['id'], 30, 600)) {
            json_err('Çok fazla devralma isteği. Lütfen bekleyin.', 429);
        }
    }

    $sonuc = revisions_lock_acquire((int)$post['id'], (int)$u['id'], $gorulen);
    if ($devral && $sonuc['ok'] && function_exists('api_admin_audit')) {
        api_admin_audit($u, 'revisions.takeover', 'post#' . (int)$post['id'],
            'önceki sahip #' . $gorulen);
    }

    return [
        'acquired' => (bool)$sonuc['ok'],
        'lock'     => revapi_lock_payload((int)$post['id'], $u),
        'interval' => revisions_autosave_interval(),
    ];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.unlock

/**
 * İstek : {post_id:int}
 * Yanıt : {ok:true, released:bool}
 * Yalnız kilidin SAHİBİ bırakabilir.
 */
api_register('revisions.unlock', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);
    return ['released' => revisions_lock_release((int)$post['id'], (int)$u['id'])];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.autosave

/**
 * İstek : {post_id:int, title, slug, spot, body, tags, seo_title, seo_desc, image,
 *          category_id, type, visibility, teaser, source_name, source_url}
 * Yanıt : {ok:true, revision_id:int, saved:bool, lock:{…}, at:string}
 *
 * ÖNEMLİ: bu uç `posts` tablosuna **HİÇ YAZMAZ**. Gelen içerik yalnız
 * `post_revisions` içine `kind = 'auto'` olarak düşer. Gerekçe `inc/revisions.php`
 * başlığındadır: yayımlanmış içeriğin yarım cümleyle değişmesi bir KOŞULLA
 * değil, VERİ YOLUYLA engellenir.
 *
 * `status` ve `published_at` istemciden HİÇ OKUNMAZ — anlık görüntüye
 * veritabanındaki gerçek değerler yazılır. Böylece bir otomatik kayıt geri
 * yüklendiğinde yayın durumunu da yanlışlıkla geri sarmaz.
 */
api_register('revisions.autosave', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);

    // Üstveri kipi (posts.seo) gövdeye dokunamaz; otomatik kaydı da olamaz.
    if (function_exists('api_admin_metadata_only') && api_admin_metadata_only($u)) {
        json_err('Üstveri kipinde otomatik kayıt yoktur.', 403);
    }
    if (!can($u, 'posts.edit_any') && !can($u, 'posts.edit_own') && !can($u, 'posts.edit_wire')) {
        json_err('Haber düzenleme yetkiniz yok.', 403);
    }
    if (!rate_limit('revauto:' . (int)$u['id'], 240, 600)) {
        json_err('Çok fazla otomatik kayıt isteği.', 429);
    }

    $alanlar = revisions_extract($d);
    // Gövde ve metin alanları sunucuda temizlenir — istemciye güvenilmez.
    $alanlar['title']       = sanitize_line((string)$alanlar['title'], 255);
    $alanlar['slug']        = sanitize_line((string)$alanlar['slug'], 200);
    $alanlar['spot']        = sanitize_plain((string)$alanlar['spot'], 500);
    $alanlar['body']        = sanitize_html((string)$alanlar['body'], true);
    $alanlar['teaser']      = sanitize_plain((string)$alanlar['teaser'], 600);
    $alanlar['tags']        = sanitize_line((string)$alanlar['tags'], 500);
    $alanlar['seo_title']   = sanitize_line((string)$alanlar['seo_title'], 255);
    $alanlar['seo_desc']    = sanitize_plain((string)$alanlar['seo_desc'], 500);
    $alanlar['source_name'] = sanitize_line((string)$alanlar['source_name'], 190);
    $alanlar['source_url']  = sanitize_line((string)$alanlar['source_url'], 500);
    $alanlar['image']       = sanitize_line((string)$alanlar['image'], 255);
    // Yayın durumu ANLIK GÖRÜNTÜDE de veritabanındakiyle aynıdır.
    $alanlar['status']       = (string)arr($post, 'status', 'draft');
    $alanlar['published_at'] = (string)arr($post, 'published_at', '');
    $alanlar['visibility']   = (string)arr($post, 'visibility', 'public');

    $rid = revisions_store((int)$post['id'], (int)$u['id'], 'auto', $alanlar, 'otomatik kayıt');

    // Otomatik kayıt aynı zamanda kilidin CANLILIK DAMGASIDIR: editör yazmaya
    // devam ettiği sürece kilit zaman aşımına uğramaz, sekme kapanınca uğrar.
    revisions_lock_acquire((int)$post['id'], (int)$u['id'], -1);

    return [
        'revision_id' => (int)$rid,
        'saved'       => $rid > 0,           // false = içerik değişmemiş, yazılmadı
        'at'          => now(),
        'lock'        => revapi_lock_payload((int)$post['id'], $u),
    ];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.snapshot

/**
 * İstek : {post_id:int, note?:string}
 * Yanıt : {ok:true, revision_id:int}
 *
 * Haberin ŞU ANKİ veritabanı hâlini elle bir sürüm olarak saklar. Panel editörü
 * bunu `posts.save`'den HEMEN ÖNCE çağırır: geri almak istenen şey, üzerine
 * yazılmadan önceki hâldir. İçerik istemciden okunmaz — kaynak daima veritabanıdır.
 */
api_register('revisions.snapshot', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);
    $rid = revisions_capture((int)$post['id'], (int)$u['id'], 'manual',
        sanitize_line((string)arr($d, 'note', ''), 200));
    return ['revision_id' => (int)$rid, 'saved' => $rid > 0];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.list

/**
 * İstek : {post_id:int}
 * Yanıt : {ok:true, items:[…], notes:[…], lock:{…}, recoverable:{…}|null,
 *          keep:int, interval:int, revision_request:{…}|null}
 */
api_register('revisions.list', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);
    $pid = (int)$post['id'];

    return [
        'items'            => revisions_list($pid, 40),
        'notes'            => revisions_notes($pid),
        'lock'             => revapi_lock_payload($pid, $u),
        'recoverable'      => revisions_recoverable($pid, (int)$u['id']),
        'revision_request' => revisions_revision_requested($post),
        'published_by'     => revisions_publisher_name($post),
        'keep'             => REVISIONS_KEEP,
        'interval'         => revisions_autosave_interval(),
    ];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.get

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, revision:{…}}   — önizleme için gövde dâhil.
 */
api_register('revisions.get', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $r = revisions_get((int)arr($d, 'id', 0));
    if (!$r) { json_err('Sürüm bulunamadı.', 404); }
    // Sürümün habere erişimi, HABERİN kapısından geçer (IDOR koruması).
    revapi_post_or_403((int)$r['post_id'], $u);
    return ['revision' => $r];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.restore

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, post_id:int, revision_id:int, backup_id:int, slug:string}
 *
 * Bir sürümü geri yükler. Üç kural:
 *
 *  1. GERİ YÜKLEME DE BİR SÜRÜM ÜRETİR. Yazmadan önce mevcut hâl `restore`
 *     türünde saklanır; yani geri yükleme de geri alınabilir.
 *  2. YAYIN DURUMU GERİ SARILMAZ. `status` ve `published_at` DOKUNULMAZ:
 *     eski bir metni geri getirmek, haberi yayından düşürmek ya da yayına
 *     almak DEĞİLDİR. (Aksi hâlde yayımlama yetkisi olmayan biri, taslak
 *     dönemine ait bir sürümü geri yükleyerek haberi siteden düşürebilirdi.)
 *  3. KİLİT ARANIR. Geri yükleme `posts` tablosuna yazar; başkası düzenlerken
 *     yapılamaz. Kilit atomik olarak alınmaya çalışılır, alınamazsa 409.
 */
api_register('revisions.restore', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $r = revisions_get((int)arr($d, 'id', 0));
    if (!$r) { json_err('Sürüm bulunamadı.', 404); }
    $pid = (int)$r['post_id'];

    // Sahiplik + "yayımlanmışı düzenleme" kapısı tek kaynaktan (inc/api/admin.php).
    $post = api_admin_load_post($pid, $u);
    if (function_exists('api_admin_metadata_only') && api_admin_metadata_only($u)) {
        json_err('Üstveri kipinde sürüm geri yüklenemez.', 403);
    }
    if (!can($u, 'posts.edit_any') && !can($u, 'posts.edit_own') && !can($u, 'posts.edit_wire')) {
        json_err('Haber düzenleme yetkiniz yok.', 403);
    }

    $kilit = revisions_lock_acquire($pid, (int)$u['id'], -1);
    if (!$kilit['ok']) {
        json_err('Bu haberi şu anda ' . ((string)$kilit['by_name'] !== '' ? $kilit['by_name'] : 'başka bir editör')
            . ' düzenliyor. Önce kilidi devralın.', 409, ['lock' => revapi_lock_payload($pid, $u)]);
    }

    // 1) Mevcut hâli sakla — geri yükleme de geri alınabilsin.
    $yedek = revisions_capture($pid, (int)$u['id'], 'restore',
        'geri yükleme öncesi (#' . (int)$r['id'] . ')');

    // 2) Slug: değişiyorsa eski adres 301 geçmişine yazılır.
    $slug = sanitize_line((string)$r['slug'], 200);
    if ($slug === '') { $slug = (string)$post['slug']; }
    $slug = unique_slug('posts', $slug, $pid);
    if ((string)$post['slug'] !== $slug && function_exists('seo_remember_slug')) {
        seo_remember_slug($pid, (string)$post['slug']);
    }

    // 3) Yaz. `status` / `published_at` LİSTEDE YOK — bilerek (yukarıdaki 2. kural).
    $veri = [
        'title'       => sanitize_line((string)$r['title'], 255),
        'slug'        => $slug,
        'spot'        => sanitize_plain((string)$r['spot'], 500),
        'body'        => sanitize_html((string)$r['body'], true),
        'tags'        => sanitize_line((string)$r['tags'], 500),
        'seo_title'   => sanitize_line((string)$r['seo_title'], 255),
        'seo_desc'    => sanitize_plain((string)$r['seo_desc'], 500),
        'image'       => function_exists('api_admin_image_value')
                            ? api_admin_image_value((string)$r['image'])
                            : sanitize_line((string)$r['image'], 255),
        'category_id' => (int)$r['category_id'],
        'type'        => (string)$r['type'],
        'source_name' => sanitize_line((string)$r['source_name'], 190),
        'source_url'  => sanitize_line((string)$r['source_url'], 500),
        'updated_at'  => now(),
    ];
    if (function_exists('post_visibility_levels') && schema_has_column('posts', 'teaser')) {
        $veri['teaser'] = sanitize_plain((string)$r['teaser'], 600);
    }
    db_update('posts', $veri, 'id = :id', [':id' => $pid]);

    if (function_exists('api_admin_audit')) {
        api_admin_audit($u, 'revisions.restore', 'post#' . $pid, 'sürüm #' . (int)$r['id']);
    }
    if (function_exists('cache_flush')) { cache_flush(); }

    return [
        'post_id'     => $pid,
        'revision_id' => (int)$r['id'],
        'backup_id'   => (int)$yedek,
        'slug'        => $slug,
        'message'     => 'Sürüm geri yüklendi.',
    ];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.note

/**
 * İstek : {post_id:int, body:string}
 * Yanıt : {ok:true, id:int, notes:[…]}
 * Editör ↔ muhabir yazışması. Not yazmak metni değiştirmez; bu yüzden
 * "yayımlanmışı düzenleme" kapısı UYGULANMAZ.
 */
api_register('revisions.note', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);
    if (!rate_limit('revnote:' . (int)$u['id'], 60, 600)) {
        json_err('Çok fazla not. Lütfen bekleyin.', 429);
    }
    $id = revisions_note_add((int)$post['id'], (int)$u['id'], (string)arr($d, 'body', ''), 'note');
    if ($id <= 0) { json_err('Not boş olamaz.', 400); }
    return ['id' => $id, 'notes' => revisions_notes((int)$post['id']), 'message' => 'Not eklendi.'];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.request_changes

/**
 * İstek : {post_id:int, body:string}
 * Yanıt : {ok:true, status:'draft', notes:[…]}
 *
 * "Revizyon iste": editör yayımlamak yerine haberi notla geri gönderir.
 * Haber taslağa döner; muhabir düzeltip yeniden onaya gönderir.
 *
 * Yetki `posts.publish`: geri göndermek, yayımlama kararının diğer yarısıdır.
 * Not ZORUNLUDUR — gerekçesiz geri gönderim muhabire hiçbir şey söylemez.
 */
api_register('revisions.request_changes', function () {
    $u = revapi_user();
    revapi_require_tables();
    if (!can($u, 'posts.publish')) { json_err('Revizyon isteme yetkiniz yok.', 403); }
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);

    $metin = sanitize_plain((string)arr($d, 'body', ''), 2000);
    if (trim($metin) === '') { json_err('Revizyon isterken gerekçe yazmalısınız.', 400); }

    // Mevcut hâli sakla: geri gönderilen metin sonradan değişse de görülebilsin.
    revisions_capture((int)$post['id'], (int)$u['id'], 'manual', 'revizyon istendi');

    q('UPDATE posts SET status = :st, updated_at = :n WHERE id = :i',
      [':st' => 'draft', ':n' => now(), ':i' => (int)$post['id']]);
    revisions_note_add((int)$post['id'], (int)$u['id'], $metin, 'revision_request');
    if (function_exists('api_admin_audit')) {
        api_admin_audit($u, 'revisions.request_changes', 'post#' . (int)$post['id'], $metin);
    }
    if (function_exists('cache_flush')) { cache_flush(); }

    return ['status' => 'draft', 'notes' => revisions_notes((int)$post['id']),
            'message' => 'Haber revizyon için geri gönderildi.'];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ revisions.approve

/**
 * İstek : {post_id:int, note?:string}
 * Yanıt : {ok:true, status:'published', published_at:string, published_by:int}
 *
 * Onay akışının yayımlama ucu. `posts.save`'in kopyası DEĞİLDİR: metne
 * dokunmaz, yalnız onaya gönderilmiş bir haberi yayına alır ve **kimin
 * yayımladığını damgalar** (`published_by`). Bugün yalnız `author_id` vardı;
 * yayımlayan kayıtlı değildi, yani sorumluluk izi yayımda kesiliyordu.
 */
api_register('revisions.approve', function () {
    $u = revapi_user();
    revapi_require_tables();
    if (!can($u, 'posts.publish')) { json_err('Yayımlama yetkiniz yok.', 403); }
    $d = json_body();
    $post = revapi_post_or_403((int)arr($d, 'post_id', 0), $u);

    if ((string)$post['status'] === 'published') {
        return ['status' => 'published', 'published_at' => (string)arr($post, 'published_at', ''),
                'published_by' => (int)arr($post, 'published_by', 0), 'message' => 'Haber zaten yayında.'];
    }

    revisions_capture((int)$post['id'], (int)$u['id'], 'publish', 'yayımlanmadan önce');

    $ne = (string)arr($post, 'published_at', '');
    if ($ne === '' || strtotime($ne) === false || strtotime($ne) > time()) { $ne = now(); }
    q('UPDATE posts SET status = :st, published_at = :pa, updated_at = :n WHERE id = :i',
      [':st' => 'published', ':pa' => $ne, ':n' => now(), ':i' => (int)$post['id']]);
    revisions_mark_published((int)$post['id'], (int)$u['id']);

    $not = sanitize_plain((string)arr($d, 'note', ''), 2000);
    if (trim($not) !== '') { revisions_note_add((int)$post['id'], (int)$u['id'], $not, 'approve'); }

    if (function_exists('api_admin_audit')) {
        api_admin_audit($u, 'revisions.approve', 'post#' . (int)$post['id'], (string)$post['title']);
    }
    if (function_exists('seo_ping_enqueue') && function_exists('url_post')) {
        seo_ping_enqueue(url_post($post), 'indexnow');
    }
    if (function_exists('cache_flush')) { cache_flush(); }

    return ['status' => 'published', 'published_at' => $ne,
            'published_by' => (int)$u['id'], 'message' => 'Haber yayımlandı.'];
}, ['perm' => 'posts.view', 'methods' => ['POST']]);

// ============================================================ posts.bulk_assign

/**
 * İstek : {ids:[int], category_id?:int, tags?:string, tag_mode?:'add'|'remove'|'replace',
 *          confirm:true, expected?:int}
 * Yanıt : {ok:true, count:int, skipped:int, message:string}
 *
 * Toplu kategori / etiket atama (1.3-11).
 *
 * NEDEN AYRI UÇ: `posts.bulk` yalnız durum değiştirir (yayınla/taslak/arşiv/sil)
 * ve bizim dosyamız değil. Bu uç İÇERİK ÜSTVERİSİNİ yazar; farklı bir risk
 * sınıfıdır ve kendi güvenceleri vardır:
 *
 *   · `confirm` ZORUNLU. Arayüz kaç kayda uygulanacağını yazıp onay ister;
 *     sunucu da onaysız isteği reddeder (üçüncü taraf istemci de onaysız
 *     yapamasın).
 *   · `expected` gönderilmişse seçim sayısıyla BİREBİR eşleşmeli. Kullanıcının
 *     ekranda gördüğü sayı ile isteğe giden sayı ayrışmışsa (sayfa değişmiş,
 *     seçim kaymış) işlem yapılmaz.
 *   · HER KAYDA SÜRÜM YAZILIR. Toplu işlem yıkıcıdır; 200 haberin etiketini
 *     tek tıkla değiştirmek geri alınamaz olmamalı. Sürüm geçmişinden tek tek
 *     geri yüklenebilir.
 *   · Etiket varsayılanı `add` — YIKICI OLMAYAN. `replace` mevcut etiketleri
 *     siler ve ancak açıkça seçilirse çalışır.
 *   · Kilitli (başkası düzenliyor) haberler ATLANIR ve sayısı bildirilir.
 */
api_register('posts.bulk_assign', function () {
    $u = revapi_user();
    revapi_require_tables();
    $d = json_body();

    if (arr($d, 'confirm', false) !== true) {
        json_err('Toplu atama onaylanmadı.', 400);
    }

    $ids = arr($d, 'ids', []);
    if (!is_array($ids) || !$ids) { json_err('Hiç kayıt seçilmedi.', 400); }
    $temiz = [];
    foreach ($ids as $one) { $n = (int)$one; if ($n > 0) { $temiz[$n] = $n; } }
    if (!$temiz) { json_err('Hiç kayıt seçilmedi.', 400); }
    if (count($temiz) > 200) { json_err('Tek seferde en çok 200 kayıt işlenebilir.', 400); }

    $beklenen = (int)arr($d, 'expected', 0);
    if ($beklenen > 0 && $beklenen !== count($temiz)) {
        json_err('Seçim değişmiş görünüyor (' . count($temiz) . ' kayıt geldi, ' . $beklenen
            . ' bekleniyordu). Sayfayı yenileyip tekrar deneyin.', 409);
    }

    $katId = array_key_exists('category_id', $d) ? (int)arr($d, 'category_id', 0) : -1;
    if ($katId > 0 && !qv('SELECT 1 FROM categories WHERE id = :i', [':i' => $katId])) {
        json_err('Kategori bulunamadı.', 400);
    }
    $etiketGiris = array_key_exists('tags', $d) ? (string)arr($d, 'tags', '') : '';
    $kip = (string)arr($d, 'tag_mode', 'add');
    if (!in_array($kip, ['add', 'remove', 'replace'], true)) { $kip = 'add'; }
    $etiketler = tags_to_array($etiketGiris);

    if ($katId < 0 && !$etiketler && $kip !== 'replace') {
        json_err('Uygulanacak bir değişiklik yok.', 400);
    }
    if (!rate_limit('bulkassign:' . (int)$u['id'], 20, 600)) {
        json_err('Çok fazla toplu işlem isteği. Lütfen bekleyin.', 429);
    }

    $sayi = 0;
    $atlanan = 0;
    foreach ($temiz as $one) {
        $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $one]);
        if (!$post) { $atlanan++; continue; }
        if (!can($u, 'posts.edit_any') && (int)$post['author_id'] !== (int)$u['id']) { $atlanan++; continue; }
        if (function_exists('published_supports_trash') && published_supports_trash()
            && (string)arr($post, 'deleted_at', '') !== '') { $atlanan++; continue; }

        // Başkasının açık düzenlemesinin üstüne yazma.
        $kilit = revisions_lock_state((int)$post['id']);
        if ((int)$kilit['by'] > 0 && (int)$kilit['by'] !== (int)$u['id'] && !$kilit['expired']) {
            $atlanan++; continue;
        }

        $yeni = ['updated_at' => now()];
        if ($katId >= 0) { $yeni['category_id'] = $katId; }

        if ($etiketler || $kip === 'replace') {
            $mevcut = tags_to_array((string)arr($post, 'tags', ''));
            if ($kip === 'replace') {
                $sonuc = $etiketler;
            } elseif ($kip === 'remove') {
                $cikar = [];
                foreach ($etiketler as $e) { $cikar[mb_strtolower($e, 'UTF-8')] = true; }
                $sonuc = [];
                foreach ($mevcut as $e) {
                    if (!isset($cikar[mb_strtolower($e, 'UTF-8')])) { $sonuc[] = $e; }
                }
            } else {
                $sonuc = tags_to_array(array_merge($mevcut, $etiketler));
            }
            $yeni['tags'] = sanitize_line(tags_to_string($sonuc), 500);
        }

        if (count($yeni) <= 1) { $atlanan++; continue; }

        // GERİ ALINABİLİRLİK: yazmadan önce sürüm.
        revisions_capture((int)$post['id'], (int)$u['id'], 'bulk', 'toplu atama');
        db_update('posts', $yeni, 'id = :id', [':id' => (int)$post['id']]);
        $sayi++;
    }

    if (function_exists('api_admin_audit')) {
        api_admin_audit($u, 'posts.bulk_assign', $sayi . ' haber',
            'kategori=' . $katId . ' etiket=' . $kip . ':' . $etiketGiris);
    }
    if (function_exists('cache_flush')) { cache_flush(); }

    return [
        'count'   => $sayi,
        'skipped' => $atlanan,
        'message' => $sayi . ' habere uygulandı'
                     . ($atlanan > 0 ? ' · ' . $atlanan . ' kayıt atlandı (yetki, çöp ya da kilit).' : '.'),
    ];
}, ['perm' => 'posts.edit_any', 'methods' => ['POST']]);
