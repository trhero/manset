<?php
/**
 * 029 — Editör altyapısı: gömme önbelleği + taslak önizleme belirteci (1.3-03, Ajan-G).
 *
 * TASARIM NOTLARI
 *
 * `embed_cache`   oEmbed sağlayıcısından dönen ÜSTVERİ (başlık, yazar, küçük
 *                 görsel) ve bizim ürettiğimiz statik gömme HTML'i burada
 *                 durur. Anahtar `url_hash` = sha256(kanonik adres) — ham adres
 *                 de saklanır ama İNDEKS ÖZETTEDİR: MySQL'de 700+ karakterlik
 *                 bir VARCHAR'a benzersiz indeks konamaz (767 bayt sınırı,
 *                 utf8mb4'te 191 karakter). Özet sabit 64 karakterdir.
 *
 *                 BAŞARISIZ SONUÇ DA ÖNBELLEKLENİR (`status = 'error'`), daha
 *                 kısa bir süreyle. Aksi hâlde yanıt vermeyen bir sağlayıcı,
 *                 editör her denediğinde yeni bir dış istek doğururdu; bu hem
 *                 yavaşlık hem de kendi sunucumuzdan çıkan bir istek seli olur.
 *
 *                 Satırlar `expires_at` ile eskir; cron temizler.
 *
 * `post_previews` Yayımlanmamış haberin paylaşılabilir önizleme bağlantısı.
 *                 HAM BELİRTEÇ SAKLANMAZ; yalnız `hash('sha256', $ham)` yazılır
 *                 — `gift_tokens` / `member_tokens` deseninin aynısı. Veritabanı
 *                 sızarsa hiçbir önizleme bağlantısı yeniden üretilemez.
 *
 *                 `token_hash` BENZERSİZDİR. `post_id` satırda tutulur, yani bir
 *                 belirteç YALNIZ kendi haberini açar; adresteki haber kimliğine
 *                 hiç bakılmaz (bkz. onizleme.php — istek yalnız `t` alır).
 *
 *                 `expires_at` ZORUNLU alandır ve okuma tarafında kesin karşılaştırma
 *                 yapılır. Süresi dolan satır SİLİNMEZ, `revoked_at` da ayrıdır:
 *                 "süresi doldu" ile "geri çekildi" farklı olaylardır ve ikisi de
 *                 "hiç yoktu"dan farklıdır (yanlış bağlantıyı paylaşan editöre
 *                 doğru mesajı verebilmek için).
 *
 *                 `uses` / `last_used_at` denetim izidir: bir taslak önizlemesi
 *                 beklenenden çok açıldıysa yayıncı bunu görebilmelidir.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    // ------------------------------------------------------------ gömme önbelleği
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS embed_cache (
        id {ID},
        url_hash VARCHAR(64) NOT NULL DEFAULT \'\',
        provider VARCHAR(20) NOT NULL DEFAULT \'\',
        url {TXT},
        title {TXT},
        author_name VARCHAR(190) NOT NULL DEFAULT \'\',
        thumb {TXT},
        html {LONG},
        status VARCHAR(10) NOT NULL DEFAULT \'ok\',
        error {TXT},
        fetched_at {DT},
        expires_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('embed_cache', 'idx_embed_hash', 'UNIQUE (url_hash)');
    schema_create_index('embed_cache', 'idx_embed_expires', '(expires_at)');

    // ------------------------------------------------------------ önizleme belirteçleri
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS post_previews (
        id {ID},
        post_id {INT} DEFAULT 0,
        token_hash VARCHAR(64) NOT NULL DEFAULT \'\',
        created_by {INT} DEFAULT 0,
        uses {INT} DEFAULT 0,
        last_used_at {DT},
        revoked_at {DT},
        expires_at {DT},
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('post_previews', 'idx_preview_hash', 'UNIQUE (token_hash)');
    schema_create_index('post_previews', 'idx_preview_post', '(post_id)');
    schema_create_index('post_previews', 'idx_preview_expires', '(expires_at)');
};
