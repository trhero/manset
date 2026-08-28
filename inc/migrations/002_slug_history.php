<?php
/**
 * 002 — slug geçmişi tablosu (Ajan-8 / SEO).
 *
 * Haberin slug'ı değiştiğinde eski adres kaybolmasın diye eski slug → post_id
 * eşlemesi burada tutulur. index.php eski slug ile gelen isteği yakalayıp
 * kanonik adrese 301 verebilir (bkz. seo_lookup_slug()).
 *
 * Bu göç YALNIZCA tabloyu kurar; mevcut haberlerin güncel slug'larını buraya
 * yazmaz (gereksiz veri çoğaltması). İhtiyaç hâlinde seo_sync_slug_history()
 * eksik satırları sonradan tamamlar.
 */
return function (PDO $pdo, string $driver) {
    $ph = schema_ph($driver);

    $sql = 'CREATE TABLE IF NOT EXISTS slug_history (
        id {ID},
        post_id {INT} DEFAULT 0,
        slug VARCHAR(200) NOT NULL DEFAULT \'\',
        created_at {DT}
    )' . $ph['{SUF}'];
    $pdo->exec(strtr($sql, $ph));

    // Aynı slug iki kez tutulmaz; arama daima slug üzerinden yapılır.
    schema_create_index('slug_history', 'idx_slughist_slug', 'UNIQUE (slug)');
    // Bir haberin tüm eski adreslerini listelemek için.
    schema_create_index('slug_history', 'idx_slughist_post', '(post_id)');
};
