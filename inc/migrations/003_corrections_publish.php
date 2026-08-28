<?php
/**
 * 003 — Düzeltme-cevap yayımı + bülten aboneleri. (Ajan-9)
 *
 * corrections tablosuna yayım alanları eklenir:
 *   answer          → yayımlanacak düzeltme/cevap metni (temizlenmiş HTML)
 *   publish_at      → yayımlandığı an
 *   homepage_until  → ana sayfada görünme bitişi (yayım + 24 saat)
 *   visible_until   → haber altında görünme bitişi (yayım + 7 gün)
 *
 * Süreler docs/BIK-NOTLARI.md §4 (Basın Kanunu m.14 özeti) uyarınca seçilmiştir.
 * Ayrıca bülten abone toplama tablosu oluşturulur (gönderim motoru kapsam dışı).
 */

return function (PDO $pdo, $driver) {
    // Var olan sütun tekrar eklenmez (schema_add_column önce denetler).
    schema_add_column('corrections', 'answer', '{LONG}');
    schema_add_column('corrections', 'publish_at', '{DT}');
    schema_add_column('corrections', 'homepage_until', '{DT}');
    schema_add_column('corrections', 'visible_until', '{DT}');

    // Panel listeleri ve ana sayfa şeridi bu ikiliyi birlikte sorgular.
    schema_create_index('corrections', 'idx_corr_status_pub', '(status, publish_at)');

    $ph = schema_ph($driver);
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS newsletter_subscribers (
        id {ID},
        email VARCHAR(190) NOT NULL DEFAULT \'\',
        ip VARCHAR(64) NOT NULL DEFAULT \'\',
        confirmed {INT} DEFAULT 0,
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('newsletter_subscribers', 'idx_newsletter_email', 'UNIQUE (email)');
};
