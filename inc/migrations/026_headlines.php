<?php
/**
 * 026 — Manşet v2 (1.3-09): zamanlı manşet ve manşete özel kısa başlık.
 *
 * SÜTUNLAR (hepsi `posts` üzerinde)
 *   headline_title      Manşette basılacak KISA başlık. Boşsa özgün `title`
 *                       kullanılır — yani varsayılan davranış değişmez.
 *                       Haber sayfası HER ZAMAN özgün başlığı gösterir.
 *   headline_starts_at  Manşete otomatik GİRİŞ zamanı ('Y-m-d H:i:s', boş = yok)
 *   headline_ends_at    Manşetten otomatik ÇIKIŞ zamanı (boş = süresiz)
 *
 * NEDEN BAYRAK DEĞİL DE CRON: ön yüzün manşet sorgusu (`headline_posts()`,
 * inc/view.php) `is_headline = 1` bakar ve o dosya bu iş akışının sahipliğinde
 * DEĞİL. Zamanı sorguya gömmek beş temada ve iki ayrı sorguda değişiklik
 * isterdi. Bunun yerine zamanı gelen kayıtta `is_headline` bayrağını CRON
 * çevirir (`headlines_cron_schedule()`); ön yüz hiç değişmez, sayfa önbelleği
 * de bayrak değiştiği anda `cache_flush()` ile boşaltılır.
 *
 * NEDEN VARCHAR(19), DATETIME DEĞİL: proje genelinde zaman damgaları
 * 'Y-m-d H:i:s' dizesi olarak tutuluyor (bkz. `posts.deleted_at`, göç 013;
 * `ads.starts_at`, göç 012). Dize karşılaştırması SQLite ve MySQL'de aynı
 * sonucu verir; NULL yerine boş dize kullanmak sürücü farkını da kapatır.
 *
 * DİKKAT — GÜVENLİK: zamanlama tek başına yayın kapısı DEĞİLDİR. Cron manşete
 * alırken haberin gerçekten yayında ve çöpte olmadığını yeniden doğrular
 * (`vitrin_is_published()` ile aynı ölçüt). Zamanlanmış bir haber araya çöpe
 * atılırsa manşete ÇIKMAZ.
 */
return function (PDO $pdo, $driver) {
    schema_add_column('posts', 'headline_title',     "VARCHAR(255) NOT NULL DEFAULT ''");
    schema_add_column('posts', 'headline_starts_at', "VARCHAR(19) NOT NULL DEFAULT ''");
    schema_add_column('posts', 'headline_ends_at',   "VARCHAR(19) NOT NULL DEFAULT ''");

    // Cron her turda "zamanı gelen var mı" diye sorar; tam tarama olmasın.
    schema_create_index('posts', 'idx_posts_hl_start', '(headline_starts_at)');
    schema_create_index('posts', 'idx_posts_hl_end', '(headline_ends_at)');
};
