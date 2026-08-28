<?php
/**
 * 013 — Sürüm 1.1: çöp kutusu, cron görev geçmişi, otomasyon geri çekilmesi.
 *
 * ÜÇ AYRI İHTİYAÇ, TEK GÖÇ (hepsi 1.1 ile geliyor; ayrı ayrı uygulanmaları
 * kullanıcı için anlamsız bir adım daha demek olurdu):
 *
 * 1) `posts.deleted_at` — YUMUŞAK SİLME.
 *    Bugün `posts.delete` doğrudan `DELETE FROM posts` çalıştırıyor
 *    (inc/api/admin.php:106). Teknik olmayan bir editör için geri alınamaz
 *    silme en sık yaşanacak kaza; tek çare veritabanını geri yüklemek, o da
 *    tüm siteyi geri sarar — orantısız. Boş dize "silinmemiş" demektir:
 *    NULL yerine dize kullanılıyor ki sürücüden bağımsız karşılaştırılabilsin
 *    ve `published_where()` kalıbıyla tutarlı kalsın.
 *
 * 2) `cron_runs` — GÖREV GEÇMİŞİ.
 *    cron.php bugün kilitsiz çalışıyor ve yalnız son raporu tutuyor. Geçmiş
 *    olmadan "cron 2 saattir çalışmıyor" gibi bir sağlık denetimi yazılamaz;
 *    panelde gösterilecek "son çalışma" da yanıltıcı olur.
 *
 * 3) `rss_sources.next_check_at` / `interval_min` ve `ai_jobs.retry_after` —
 *    GERİ ÇEKİLME. Kaynak 5 hatada pasifleşiyor ama bir daha hiç denenmiyor;
 *    geçici bir kesinti kaynağı kalıcı olarak öldürüyor. AI işleri de üç
 *    denemede `failed` oluyor — sağlayıcı 10 dakika çökse iş çöpe gidiyor.
 *
 * Sürüm damgası (`settings.installed_version`) göç GEREKTİRMEZ: settings bir
 * anahtar/değer tablosudur, `upgrade_run()` ilk çalıştığında yazar.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    /* 1) Çöp kutusu ------------------------------------------------------ */
    schema_add_column('posts', 'deleted_at', "VARCHAR(19) NOT NULL DEFAULT ''");
    schema_create_index('posts', 'idx_posts_deleted', '(deleted_at)');

    /* 2) Cron görev geçmişi ---------------------------------------------- */
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS cron_runs (
        id {ID},
        task VARCHAR(40) NOT NULL DEFAULT \'\',
        started_at {DT},
        finished_at VARCHAR(19) NOT NULL DEFAULT \'\',
        ok {INT} DEFAULT 0,
        items {INT} DEFAULT 0,
        ms {INT} DEFAULT 0,
        note {TXT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('cron_runs', 'idx_cron_task', '(task, started_at)');
    schema_create_index('cron_runs', 'idx_cron_started', '(started_at)');

    /* 3) Otomasyon geri çekilmesi ---------------------------------------- */
    schema_add_column('rss_sources', 'next_check_at', "VARCHAR(19) NOT NULL DEFAULT ''");
    schema_add_column('rss_sources', 'interval_min',  '{INT} DEFAULT 0');
    schema_add_column('ai_jobs',     'retry_after',   "VARCHAR(19) NOT NULL DEFAULT ''");
};
