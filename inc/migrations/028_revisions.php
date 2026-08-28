<?php
/**
 * 028 — Sürüm geçmişi, habere bağlı notlar, düzenleme kilidi. (Ajan-F, 1.3-01 / 1.3-02)
 *
 * ---------------------------------------------------------------------------
 * NEDEN TAM KOPYA, DİFF DEĞİL
 * ---------------------------------------------------------------------------
 * `post_revisions` her sürümde gövdenin ve üstverinin TAM kopyasını tutar.
 * Diff daha az yer kaplar ama geri yükleme "N tane yamayı sırayla ve doğru
 * uygula" işlemine döner: zincirin ortasındaki tek bir bozuk satır ondan
 * sonraki tüm sürümleri okunamaz yapar. Küçük bir yayıncıda bir haber
 * gövdesi birkaç kilobayttır; 20 sürüm ≈ 100 KB. Geri alınabilirliğin
 * bedeli olarak ucuzdur ve geri yükleme TEK bir satır okumasıdır.
 *
 * ---------------------------------------------------------------------------
 * NEDEN `content_hash`
 * ---------------------------------------------------------------------------
 * Otomatik kayıt 30 saniyede bir çalışır. Editör metne dokunmadan sekmeyi açık
 * bıraktığında aynı içerik defalarca yazılır ve 20 sürümlük pencere birbirinin
 * aynı satırlarla dolar — yani gerçek geçmiş budanır. Yeni sürüm, o haberin EN
 * SON sürümüyle aynı özete sahipse hiç yazılmaz.
 *
 * ---------------------------------------------------------------------------
 * DÜZENLEME KİLİDİ — POSTS ÜZERİNDE, AYRI TABLODA DEĞİL
 * ---------------------------------------------------------------------------
 * `locked_by` / `locked_at` doğrudan `posts` satırındadır. Ayrı bir kilit
 * tablosu, "satır yoksa ekle, varsa güncelle" demekti; bu iki adım arasındaki
 * boşluk tam olarak kaçınmak istediğimiz yarıştır (1.2'de hediye kotası bu
 * yüzden delindi). Tek satırda tutulunca kilidi almak TEK bir koşullu UPDATE
 * olur ve karar veritabanına iner:
 *
 *     UPDATE posts SET locked_by = :me, locked_at = :now
 *      WHERE id = :id AND (locked_by = 0 OR locked_by = :me
 *                          OR locked_at = '' OR locked_at < :cutoff)
 *
 * ve yalnız `rowCount() === 1` olan süreç kilidi almış sayılır. Bkz.
 * `inc/revisions.php · revisions_lock_acquire()`.
 *
 * `locked_at` bilerek VARCHAR(19) NOT NULL DEFAULT '' — NULL DEĞİL. Zaman aşımı
 * karşılaştırması (`locked_at < :cutoff`) NULL ile üç değerli mantığa düşer ve
 * SQLite ile MySQL'de aynı davranmaz; boş dize her iki sürücüde de her
 * damgadan küçüktür, yani "kilit yok" doğal olarak "süresi dolmuş" sayılır.
 *
 * ---------------------------------------------------------------------------
 * `published_by`
 * ---------------------------------------------------------------------------
 * Bugüne kadar yalnız `author_id` vardı: haberi KİMİN yazdığı biliniyor, KİMİN
 * yayımladığı bilinmiyordu. Çok yazarlı bir haber odasında sorumluluk izi
 * yayımlayanla başlar. Geçmiş kayıtlar 0 bırakılır — "bilinmiyor" ile
 * "yazarın kendisi yayımladı" farklı bilgilerdir, ikincisini uydurmuyoruz.
 */

return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    // ------------------------------------------------------------ sürümler
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS post_revisions (
        id {ID},
        post_id {INT} DEFAULT 0,
        user_id {INT} DEFAULT 0,
        kind VARCHAR(20) NOT NULL DEFAULT \'manual\',
        content_hash VARCHAR(64) NOT NULL DEFAULT \'\',
        title VARCHAR(255) NOT NULL DEFAULT \'\',
        slug VARCHAR(200) NOT NULL DEFAULT \'\',
        spot {TXT},
        body {LONG},
        teaser {TXT},
        tags VARCHAR(500) NOT NULL DEFAULT \'\',
        seo_title VARCHAR(255) NOT NULL DEFAULT \'\',
        seo_desc {TXT},
        image VARCHAR(255) NOT NULL DEFAULT \'\',
        category_id {INT} DEFAULT 0,
        type VARCHAR(20) NOT NULL DEFAULT \'haber\',
        status VARCHAR(20) NOT NULL DEFAULT \'draft\',
        visibility VARCHAR(20) NOT NULL DEFAULT \'public\',
        source_name VARCHAR(190) NOT NULL DEFAULT \'\',
        source_url VARCHAR(500) NOT NULL DEFAULT \'\',
        published_at VARCHAR(19) NOT NULL DEFAULT \'\',
        note VARCHAR(200) NOT NULL DEFAULT \'\',
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    // Sürüm listesi daima tek habere göre ve tersten okunur; budama da bu
    // indeksten yürür (post_id, id DESC → ilk 20).
    schema_create_index('post_revisions', 'idx_prev_post', '(post_id, id)');
    schema_create_index('post_revisions', 'idx_prev_kind', '(post_id, kind, id)');

    // ------------------------------------------------------------ habere bağlı notlar
    // Editör ↔ muhabir yazışması. `kind`:
    //   note              serbest not
    //   revision_request  "revizyon iste" — yayımlamak yerine geri gönderildi
    //   approve           onaylandı / yayımlandı
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS post_notes (
        id {ID},
        post_id {INT} DEFAULT 0,
        user_id {INT} DEFAULT 0,
        kind VARCHAR(20) NOT NULL DEFAULT \'note\',
        body {TXT},
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('post_notes', 'idx_pnote_post', '(post_id, id)');

    // ------------------------------------------------------------ posts sütunları
    // Kilit sahibi ve son canlılık damgası. Damga NULL DEĞİL (yukarıdaki gerekçe).
    schema_add_column('posts', 'locked_by', '{INT} DEFAULT 0');
    schema_add_column('posts', 'locked_at', 'VARCHAR(19) NOT NULL DEFAULT \'\'');
    // Sorumluluk izi: yayımlayan kullanıcı.
    schema_add_column('posts', 'published_by', '{INT} DEFAULT 0');

    // Takvim ve "başkası düzenliyor" rozeti bu sütunlardan okur.
    schema_create_index('posts', 'idx_posts_locked', '(locked_by, locked_at)');
};
