<?php
/**
 * Manşet — sürüm geçmişi, otomatik kayıt, düzenleme kilidi, habere bağlı notlar.
 * (Ajan-F · 1.3-01, 1.3-02)
 *
 * Bu modül `manset_feature_modules()` listesindedir; dört giriş noktası
 * (index.php, api.php, cron.php, admin/index.php) yükler. Göç 028 uygulanmamış
 * bir kurulumda her işlev sessizce "yok" davranır — hiçbir akış kırılmaz.
 *
 * ===========================================================================
 * OTOMATİK KAYIT YAYIMLANMIŞ İÇERİĞE DOKUNMAZ — NEDEN VE NASIL
 * ===========================================================================
 * Otomatik kayıt `posts` tablosuna HİÇ YAZMAZ. Ne yayımlanmış habere, ne
 * taslağa. Yalnız `post_revisions` tablosuna `kind = 'auto'` bir satır ekler.
 *
 * Alternatifler ve neden seçilmedi:
 *
 *   (a) "Yayımlanmışsa yazma, taslaksa yaz."  Kural iki yerde (arayüz ve uç)
 *       ayrı ayrı doğru uygulanmak zorunda; durum düzenleme SIRASINDA
 *       değişebilir (editör açıkken haber cron ile zamanlı yayına girebilir) ve
 *       o anda yarım cümle canlıya çıkar. Ayrıca yalnız `status` alanına
 *       bakmak `visibility` ve `deleted_at` gibi başka canlılık koşullarını
 *       görmez.
 *   (b) "Gölge bir taslak satırı aç."  `posts` içinde ikinci bir satır demek,
 *       slug benzersizliği, listeler, besleme, site haritası, arama ve manşet
 *       sırası dâhil ON'DAN fazla sorgunun bu satırı dışlamayı bilmesi demek.
 *       Unutulan tek sorgu yarım haberi canlıya çıkarır.
 *   (c) Seçilen: otomatik kayıt bir KAYIT DEĞİL, KURTARMA ANLIK GÖRÜNTÜSÜDÜR.
 *       `post_revisions` zaten ön yüzün hiç okumadığı bir tablodur; oraya
 *       yazmak tanım gereği yayına çıkamaz. "Yayımlanmışı değiştirme" kuralı
 *       bir koşula değil, VERİ YOLUNA gömülüdür — yanlış uygulanacak bir
 *       koşul yoktur.
 *
 * Editör sayfayı yeniden açtığında, `posts.updated_at`'ten YENİ bir `auto`
 * sürüm varsa "kurtarılabilir otomatik kayıt" uyarısı çıkar; editör önizler
 * ve isterse geri yükler. Yani yazdıkları kaybolmaz, ama canlıya kendiliğinden
 * de çıkmaz.
 *
 * ===========================================================================
 * KİLİT BİR YARIŞ SORUNUDUR
 * ===========================================================================
 * "Önce SELECT ile kilitli mi diye bak, boşsa UPDATE ile al" deseni iki
 * editörün ikisine birden kilidi verir: aradaki boşlukta ikisi de "boş"
 * cevabını okur. Karar veritabanına iner ve TEK bir koşullu UPDATE olur;
 * yalnız `rowCount() === 1` olan süreç kilidi almıştır. Aynı desen 1.2'de
 * hediye kotası için kullanıldı (`inc/paywall.php`).
 *
 * MySQL NOTU: bağlantıda `MYSQL_ATTR_FOUND_ROWS` AÇIK DEĞİL, yani rowCount()
 * "eşleşen" değil "DEĞİŞEN" satırı sayar. Aynı kullanıcı aynı saniye içinde
 * kilidi tazelerse satır hiç değişmez ve rowCount 0 döner. Bu yüzden 0
 * sonucunda satır bir kez daha okunur: `locked_by` BENİM kimliğimse kilit
 * bendedir. Bu geri düşüş yarışı geri getirmez — çünkü yalnız "sahibi zaten
 * benim" durumunu başarıya çevirir, başkasının kilidini asla.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// Modül dört giriş noktasından yükleniyor; hepsinde `sanitize.php` yüklü
// olduğuna güvenilmez (ön yüz yolunda görünürlük sırası değişebilir).
require_once INC_DIR . '/schema.php';
require_once INC_DIR . '/sanitize.php';

// ---------------------------------------------------------------- sabitler

/** Bir haberde saklanacak en fazla sürüm sayısı. */
if (!defined('REVISIONS_KEEP')) { define('REVISIONS_KEEP', 20); }

/** Kilit zaman aşımı (saniye) — varsayılan 10 dakika. */
function revisions_lock_ttl() {
    $v = (int)setting('revisions_lock_ttl', 600);
    // Çok kısa bir zaman aşımı kilidi anlamsız kılar (yazarken devralınır);
    // çok uzunu tarayıcısı çöken editörün haberi saatlerce tutmasıdır.
    if ($v < 60) { $v = 60; }
    if ($v > 7200) { $v = 7200; }
    return $v;
}

/** Otomatik kayıt aralığı (saniye) — arayüz bu değeri okur. */
function revisions_autosave_interval() {
    $v = (int)setting('revisions_autosave_sec', 30);
    if ($v < 10) { $v = 10; }
    if ($v > 600) { $v = 600; }
    return $v;
}

/** Göç 028 uygulanmış mı? Uygulanmadıysa modül sessizce devre dışıdır. */
function revisions_ready() {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $ok = function_exists('schema_has_table')
        && schema_has_table('post_revisions')
        && schema_has_table('post_notes')
        && schema_has_column('posts', 'locked_by');
    return $ok;
}

/** `post_notes` kullanılabilir mi? */
function revisions_notes_ready() {
    return function_exists('schema_has_table') && schema_has_table('post_notes');
}

// ---------------------------------------------------------------- sürüm alanları

/**
 * Bir sürümde saklanan alanlar.
 * `posts` satırında olmayan alanlar (göç uygulanmamış kurulum) varsayılanla dolar.
 */
function revisions_fields() {
    return [
        'title'        => '',
        'slug'         => '',
        'spot'         => '',
        'body'         => '',
        'teaser'       => '',
        'tags'         => '',
        'seo_title'    => '',
        'seo_desc'     => '',
        'image'        => '',
        'category_id'  => 0,
        'type'         => 'haber',
        'status'       => 'draft',
        'visibility'   => 'public',
        'source_name'  => '',
        'source_url'   => '',
        'published_at' => '',
    ];
}

/** Bir `posts` satırından (ya da istek gövdesinden) sürüm alanlarını süzer. */
function revisions_extract($row) {
    $out = [];
    foreach (revisions_fields() as $k => $def) {
        $v = arr($row, $k, $def);
        if ($v === null) { $v = $def; }
        $out[$k] = is_int($def) ? (int)$v : (string)$v;
    }
    return $out;
}

/**
 * İçerik özeti. İki sürüm aynı özete sahipse ikincisi hiç yazılmaz —
 * boşta bekleyen bir sekmenin 30 saniyede bir aynı metni kaydedip 20 sürümlük
 * pencereyi doldurması engellenir.
 */
function revisions_hash(array $fields) {
    $parcalar = [];
    foreach (revisions_fields() as $k => $def) {
        $parcalar[] = $k . '=' . (string)(isset($fields[$k]) ? $fields[$k] : $def);
    }
    return hash('sha256', implode("\x1f", $parcalar));
}

// ---------------------------------------------------------------- yazma

/**
 * Sürüm yazar. Aynı içerik en son sürümle birebir aynıysa 0 döner (yazılmaz).
 *
 * @param int    $postId
 * @param int    $userId  sürümü üreten kullanıcı
 * @param string $kind    manual | auto | restore | publish | bulk
 * @param array  $fields  revisions_extract() çıktısı
 * @param string $note    kısa açıklama (geri yükleme kaynağı, toplu işlem adı…)
 * @return int   yeni sürüm kimliği ya da 0
 */
function revisions_store($postId, $userId, $kind, array $fields, $note = '') {
    if (!revisions_ready()) { return 0; }
    $postId = (int)$postId;
    if ($postId <= 0) { return 0; }

    $kind = in_array($kind, ['manual', 'auto', 'restore', 'publish', 'bulk'], true) ? $kind : 'manual';
    $hash = revisions_hash($fields);

    // Aynı içerik zaten en üstteyse yazma. `auto` için bu kural hayati; elle
    // kaydetmede de kullanıcıyı boş sürümle uğraştırmamak için aynısı geçerli.
    $sonHash = (string)qv('SELECT content_hash FROM post_revisions WHERE post_id = :p ORDER BY id DESC LIMIT 1',
        [':p' => $postId], '');
    if ($sonHash !== '' && $sonHash === $hash) { return 0; }

    $satir = $fields;
    $satir['post_id']      = $postId;
    $satir['user_id']      = (int)$userId;
    $satir['kind']         = $kind;
    $satir['content_hash'] = $hash;
    $satir['note']         = sanitize_line((string)$note, 200);
    $satir['created_at']   = now();

    try {
        $id = (int)db_insert('post_revisions', $satir);
    } catch (Throwable $e) {
        // Sürüm yazılamazsa asıl işlem ENGELLENMEZ; geçmiş bir güvence
        // katmanıdır, hizmet kesici değil (api_admin_audit ile aynı ilke).
        log_error('sürüm yazılamadı: ' . $e->getMessage());
        return 0;
    }

    revisions_prune($postId, (int)$userId, $kind);
    return $id;
}

/**
 * Haberin ŞU ANKİ veritabanı hâlini sürüm olarak saklar.
 * Kaydetmeden ÖNCE çağrılır: geri almak istediğimiz şey önceki hâldir.
 */
function revisions_capture($postId, $userId, $kind = 'manual', $note = '') {
    if (!revisions_ready()) { return 0; }
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => (int)$postId]);
    if (!$post) { return 0; }
    return revisions_store((int)$postId, (int)$userId, $kind, revisions_extract($post), $note);
}

/**
 * Budama.
 *
 * İKİ AŞAMA — NEDEN:
 *   1) Otomatik kayıtlar YIĞILMAZ. Bir kullanıcının bir haberdeki `auto`
 *      sürümlerinden yalnız EN YENİSİ saklanır. 30 saniyede bir üretilen yarım
 *      cümleler "geçmiş" değildir; kurtarma değeri yalnız en sonuncudadır ve
 *      saklanırlarsa 20 sürümlük pencereyi doldurup gerçek kayıtları budarlar.
 *   2) Sonra haber başına toplam REVISIONS_KEEP satır kalır (en yeniler).
 */
function revisions_prune($postId, $userId = 0, $kind = '') {
    if (!revisions_ready()) { return; }
    $postId = (int)$postId;
    if ($postId <= 0) { return; }

    try {
        if ($kind === 'auto' && (int)$userId > 0) {
            $enYeni = (int)qv('SELECT id FROM post_revisions
                                WHERE post_id = :p AND kind = :k AND user_id = :u
                                ORDER BY id DESC LIMIT 1',
                [':p' => $postId, ':k' => 'auto', ':u' => (int)$userId], 0);
            if ($enYeni > 0) {
                q('DELETE FROM post_revisions
                    WHERE post_id = :p AND kind = :k AND user_id = :u AND id < :i',
                  [':p' => $postId, ':k' => 'auto', ':u' => (int)$userId, ':i' => $enYeni]);
            }
        }

        // Toplam pencere. LIMIT/OFFSET ile eşiği bulup tek DELETE ile kesiyoruz;
        // "DELETE ... IN (SELECT ... LIMIT)" MySQL'de kabul edilmez.
        $esik = (int)qv('SELECT id FROM post_revisions WHERE post_id = :p
                          ORDER BY id DESC LIMIT 1 OFFSET ' . (int)REVISIONS_KEEP,
            [':p' => $postId], 0);
        if ($esik > 0) {
            q('DELETE FROM post_revisions WHERE post_id = :p AND id <= :i',
              [':p' => $postId, ':i' => $esik]);
        }
    } catch (Throwable $e) {
        log_error('sürüm budama: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------- okuma

/** Bir haberin sürümleri (en yeni önce). Gövde DÖNMEZ — liste hafif kalsın. */
function revisions_list($postId, $limit = 40) {
    if (!revisions_ready()) { return []; }
    $limit = max(1, min(100, (int)$limit));
    return qa('SELECT r.id, r.post_id, r.user_id, r.kind, r.title, r.status, r.note, r.created_at,
                      u.name AS user_name
                 FROM post_revisions r
                 LEFT JOIN users u ON u.id = r.user_id
                WHERE r.post_id = :p
                ORDER BY r.id DESC
                LIMIT ' . $limit, [':p' => (int)$postId]);
}

/** Tek sürüm (gövdesiyle). */
function revisions_get($id) {
    if (!revisions_ready()) { return null; }
    return q1('SELECT * FROM post_revisions WHERE id = :i', [':i' => (int)$id]);
}

/**
 * Bu kullanıcıya ait, canlı metne UYGULANMAMIŞ bir otomatik kayıt var mı?
 * Varsa editör açılışında "kurtarılabilir" uyarısı gösterilir; yoksa null.
 *
 * KARŞILAŞTIRMA ZAMAN DAMGASIYLA DEĞİL, İÇERİK ÖZETİYLE YAPILIR.
 * İlk sürüm "`created_at` > `posts.updated_at`" diye soruyordu ve BU YANLIŞTI:
 * damgalar saniye çözünürlüğünde tutuluyor, kaydetmek ile otomatik kayıt aynı
 * saniyeye düştüğünde karşılaştırma sessizce false döndürüyordu — yani
 * kurtarılabilir metin VARDI ama editöre hiç gösterilmiyordu. (Birim testi
 * `revisions.test.php` bunu ilk koşuda yakaladı.)
 *
 * Özet karşılaştırması saniyeden bağımsızdır ve doğru soruyu sorar: otomatik
 * kayıttaki içerik, veritabanındaki içerikten FARKLI MI?
 */
function revisions_recoverable($postId, $userId) {
    if (!revisions_ready()) { return null; }
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => (int)$postId]);
    if (!$post) { return null; }
    $r = q1('SELECT id, created_at, title, content_hash FROM post_revisions
              WHERE post_id = :p AND kind = :k AND user_id = :u
              ORDER BY id DESC LIMIT 1',
        [':p' => (int)$postId, ':k' => 'auto', ':u' => (int)$userId]);
    if (!$r) { return null; }
    if ((string)$r['content_hash'] === revisions_hash(revisions_extract($post))) { return null; }
    return $r;
}

// ---------------------------------------------------------------- düzenleme kilidi

/**
 * Kilidi ATOMİK olarak almaya çalışır.
 *
 * @param int  $postId
 * @param int  $userId
 * @param int  $seenBy  DEVRALMA: arayüzün ekranda GÖRDÜĞÜ sahip kimliği.
 *                      -1 ise devralma istenmiyor demektir.
 * @return array ['ok'=>bool, 'by'=>int, 'by_name'=>string, 'at'=>string, 'expired'=>bool]
 */
function revisions_lock_acquire($postId, $userId, $seenBy = -1) {
    if (!revisions_ready()) { return ['ok' => true, 'by' => 0, 'by_name' => '', 'at' => '', 'expired' => false]; }
    $postId = (int)$postId;
    $userId = (int)$userId;
    if ($postId <= 0 || $userId <= 0) { return ['ok' => false, 'by' => 0, 'by_name' => '', 'at' => '', 'expired' => false]; }

    $simdi = now();
    $kesim = date('Y-m-d H:i:s', time() - revisions_lock_ttl());

    if ((int)$seenBy >= 0) {
        // DEVRALMA. "Koşulsuz UPDATE" değil: arayüzün gördüğü sahibe karşı
        // KARŞILAŞTIR-VE-DEĞİŞTİR. İki editör aynı anda devralmaya kalkarsa
        // ikisi de aynı sahibi görmüş olur ve yalnız BİRİNİN UPDATE'i satırı
        // değiştirir; ikincisi 0 alır ve artık yeni sahibi görür.
        $etkilenen = q('UPDATE posts SET locked_by = :me, locked_at = :now
                         WHERE id = :i AND locked_by = :seen',
            [':me' => $userId, ':now' => $simdi, ':i' => $postId, ':seen' => (int)$seenBy])->rowCount();
    } else {
        // NORMAL ALIM — tek atomik işlem. Boşsa, zaten benimse ya da zaman
        // aşımına uğramışsa alınır. `locked_at = ''` (damgasız kilit) da
        // süresi dolmuş sayılır.
        $etkilenen = q('UPDATE posts SET locked_by = :me, locked_at = :now
                         WHERE id = :i
                           AND (locked_by = 0 OR locked_by = :me2 OR locked_at = :bos OR locked_at < :kesim)',
            [':me' => $userId, ':now' => $simdi, ':i' => $postId,
             ':me2' => $userId, ':bos' => '', ':kesim' => $kesim])->rowCount();
    }

    if ($etkilenen < 1) {
        // MySQL rowCount() DEĞİŞEN satırı sayar: aynı kullanıcı aynı saniye
        // içinde tazelediğinde satır değişmez ve 0 döner. Satırı bir kez daha
        // okuyup "sahibi zaten benim" durumunu başarıya çeviriyoruz. Başkasının
        // kilidi asla başarıya çevrilmez — yarış geri gelmez.
        $durum = revisions_lock_state($postId);
        if ((int)$durum['by'] === $userId && !$durum['expired']) { $durum['ok'] = true; return $durum; }
        $durum['ok'] = false;
        return $durum;
    }

    return ['ok' => true, 'by' => $userId, 'by_name' => '', 'at' => $simdi, 'expired' => false];
}

/** Kilit durumu (yazmadan okur). */
function revisions_lock_state($postId) {
    $bos = ['ok' => false, 'by' => 0, 'by_name' => '', 'at' => '', 'expired' => true];
    if (!revisions_ready()) { return $bos; }
    $r = q1('SELECT p.locked_by, p.locked_at, u.name AS locked_name
               FROM posts p LEFT JOIN users u ON u.id = p.locked_by
              WHERE p.id = :i', [':i' => (int)$postId]);
    if (!$r) { return $bos; }
    $by = (int)arr($r, 'locked_by', 0);
    $at = (string)arr($r, 'locked_at', '');
    $suresiDoldu = ($by <= 0) || ($at === '') || (strtotime($at) < time() - revisions_lock_ttl());
    return [
        'ok'      => false,
        'by'      => $by,
        'by_name' => (string)arr($r, 'locked_name', ''),
        'at'      => $at,
        'expired' => $suresiDoldu,
    ];
}

/**
 * Kilidi bırakır. Yalnız SAHİBİ bırakabilir — başkası "bırakma" isteğiyle
 * kilidi sessizce düşürüp devralma uyarısını atlatamasın.
 */
function revisions_lock_release($postId, $userId) {
    if (!revisions_ready()) { return false; }
    $n = q('UPDATE posts SET locked_by = 0, locked_at = :bos WHERE id = :i AND locked_by = :me',
        [':bos' => '', ':i' => (int)$postId, ':me' => (int)$userId])->rowCount();
    return $n > 0;
}

/**
 * Süresi dolmuş kilitleri temizler (cron).
 * Kilit zaten okunurken de süresi dolmuş sayılır; bu görev yalnız satırları
 * derli toplu tutar ve "kim düzenliyor" listelerinin ölü kayıt göstermesini
 * engeller. Ağa çıkmaz — ucuz görev.
 */
function revisions_cron_unlock() {
    if (!revisions_ready()) { return ['ok' => true, 'not' => 'sürüm tabloları yok']; }
    $kesim = date('Y-m-d H:i:s', time() - revisions_lock_ttl());
    $n = q('UPDATE posts SET locked_by = 0, locked_at = :bos
             WHERE locked_by <> 0 AND (locked_at = :bos2 OR locked_at < :kesim)',
        [':bos' => '', ':bos2' => '', ':kesim' => $kesim])->rowCount();
    return ['ok' => true, 'temizlenen' => (int)$n];
}
if (function_exists('cron_register')) {
    cron_register('kilit', 'revisions_cron_unlock', true);
}

// ---------------------------------------------------------------- notlar

/** Habere bağlı notlar (eskiden yeniye — yazışma sırası). */
function revisions_notes($postId, $limit = 100) {
    if (!revisions_notes_ready()) { return []; }
    $limit = max(1, min(200, (int)$limit));
    return qa('SELECT n.id, n.post_id, n.user_id, n.kind, n.body, n.created_at, u.name AS user_name
                 FROM post_notes n LEFT JOIN users u ON u.id = n.user_id
                WHERE n.post_id = :p ORDER BY n.id ASC LIMIT ' . $limit, [':p' => (int)$postId]);
}

/** Not ekler; yeni notun kimliğini döndürür (0 = yazılamadı). */
function revisions_note_add($postId, $userId, $body, $kind = 'note') {
    if (!revisions_notes_ready()) { return 0; }
    $body = sanitize_plain((string)$body, 2000);
    if (trim($body) === '') { return 0; }
    $kind = in_array($kind, ['note', 'revision_request', 'approve'], true) ? $kind : 'note';
    try {
        return (int)db_insert('post_notes', [
            'post_id'    => (int)$postId,
            'user_id'    => (int)$userId,
            'kind'       => $kind,
            'body'       => $body,
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        log_error('not yazılamadı: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Bu haber için son işlem "revizyon iste" mi?
 *
 * AYRI SÜTUN AÇILMADI — NEDEN: durum zaten `posts.status` ile taşınıyor.
 * "Revizyon istendi" = son not `revision_request` **ve** haber hâlâ taslakta.
 * Muhabir yeniden onaya gönderdiğinde (`status = 'pending'`) koşul kendiliğinden
 * düşer; temizlenmesi gereken ve unutulunca yalan söyleyen bir bayrak kalmaz.
 * (`posts.save` bizim dosyamız değil; oraya bayrak temizleme kancası koymak
 * zorunda kalmamak da bilinçli bir tasarım tercihi.)
 */
function revisions_revision_requested($post) {
    if (!revisions_notes_ready()) { return null; }
    $postId = (int)arr($post, 'id', 0);
    if ($postId <= 0) { return null; }
    if ((string)arr($post, 'status', '') !== 'draft') { return null; }
    $n = q1('SELECT n.id, n.body, n.created_at, n.kind, u.name AS user_name
               FROM post_notes n LEFT JOIN users u ON u.id = n.user_id
              WHERE n.post_id = :p ORDER BY n.id DESC LIMIT 1', [':p' => $postId]);
    if (!$n || (string)$n['kind'] !== 'revision_request') { return null; }
    return $n;
}

// ---------------------------------------------------------------- sorumluluk izi

/**
 * Yayımlayanı damgalar. `published_by` sütunu yoksa sessizce atlanır.
 * Yalnız BOŞ olan damga yazılır: bir haberi ikinci kez yayına almak ilk
 * yayımlayanın izini silmemeli.
 */
function revisions_mark_published($postId, $userId) {
    if (!function_exists('schema_has_column') || !schema_has_column('posts', 'published_by')) { return false; }
    $n = q('UPDATE posts SET published_by = :u WHERE id = :i AND (published_by = 0 OR published_by IS NULL)',
        [':u' => (int)$userId, ':i' => (int)$postId])->rowCount();
    return $n > 0;
}

/** Yayımlayan kullanıcının adı (yoksa ''). */
function revisions_publisher_name($post) {
    $id = (int)arr($post, 'published_by', 0);
    if ($id <= 0) { return ''; }
    return (string)qv('SELECT name FROM users WHERE id = :i', [':i' => $id], '');
}

// ---------------------------------------------------------------- erişim kapısı

/**
 * Bu kullanıcı bu habere DOKUNABİLİR mi? (sürüm/kilit/not uçlarının ortak kapısı)
 *
 * `api_admin_load_post()` ile aynı ölçütler, ama iki fark var ve ikisi de
 * bilinçli:
 *   · Burada "yayımlanmış haberi düzenleyemezsin" kapısı YOK; bu işlevi
 *     çağıran uç, yazma yapıyorsa o kapıyı ayrıca uygular. Sürüm listesini
 *     OKUMAK ya da not yazmak, metni değiştirmek değildir.
 *   · Üstveri kipindeki (posts.seo) kullanıcı okuyabilir, ama gövdeyi geri
 *     yükleyemez — geri yükleme ucu ayrıca `posts.edit_*` arar.
 */
function revisions_can_touch($post, $user) {
    if (!$post || !$user) { return false; }
    if (can($user, 'posts.edit_any')) { return true; }
    if ((int)arr($post, 'author_id', 0) === (int)arr($user, 'id', -1)) { return true; }
    if (can($user, 'posts.edit_wire') && function_exists('api_admin_is_wire_post')
        && api_admin_is_wire_post($post)) { return true; }
    if (function_exists('api_admin_metadata_only') && api_admin_metadata_only($user)) { return true; }
    return false;
}

// ---------------------------------------------------------------- takvim

/**
 * Yayın takvimi: verilen tarih aralığındaki zamanlanmış / yayımlanmış haberler.
 * Gün anahtarı 'Y-m-d'; her gün için haber dizisi döner.
 */
function revisions_calendar($basTarih, $bitTarih, array $user) {
    $bas = (string)$basTarih . ' 00:00:00';
    $bit = (string)$bitTarih . ' 23:59:59';

    $where = ['p.published_at IS NOT NULL', 'p.published_at <> :bos',
              'p.published_at >= :bas', 'p.published_at <= :bit'];
    $prm = [':bos' => '', ':bas' => $bas, ':bit' => $bit];

    if (function_exists('published_supports_trash') && published_supports_trash()) {
        $where[] = 'p.deleted_at = :silbos';
        $prm[':silbos'] = '';
    }
    if (!can($user, 'posts.edit_any')) {
        $where[] = 'p.author_id = :me';
        $prm[':me'] = (int)arr($user, 'id', 0);
    }

    $satirlar = qa('SELECT p.id, p.title, p.slug, p.status, p.published_at, p.category_id,
                           p.author_id, c.name AS category_name, c.color AS category_color,
                           u.name AS author_name
                      FROM posts p
                      LEFT JOIN categories c ON c.id = p.category_id
                      LEFT JOIN users u ON u.id = p.author_id
                     WHERE ' . implode(' AND ', $where) . '
                     ORDER BY p.published_at ASC', $prm);

    $gunler = [];
    foreach ($satirlar as $s) {
        $gun = substr((string)$s['published_at'], 0, 10);
        if (!isset($gunler[$gun])) { $gunler[$gun] = []; }
        $gunler[$gun][] = $s;
    }
    return $gunler;
}
