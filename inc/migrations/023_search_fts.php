<?php
/**
 * 023 — Tam metin arama dizini + etiket normalizasyonu (Ajan-A / 1.3-06).
 *
 * Üç iş:
 *
 *  1. **Arama dizini.** SQLite'ta FTS5 sanal tablosu (`search_index`) ve onu
 *     `posts` ile aynı işlemde güncel tutan üç tetikleyici; MySQL'de doğrudan
 *     `posts` üzerinde FULLTEXT dizin. Kullanılabilirlik VARSAYILMAZ, denenir:
 *     FTS5 PHP'nin SQLite derlemesinde olmayabilir, MySQL sürücüsü ALTER TABLE
 *     yetkisi vermeyebilir. Başarısızlık sessizdir — `search_fts_query()` null
 *     döner ve arama 1.2'deki LIKE sorgusuyla çalışmaya devam eder.
 *
 *  2. **Etiket tabloları.** `tags` + `post_tags`. Bunlar TÜRETİLMİŞ veridir:
 *     kaynak hâlâ `posts.tags` serbest metnidir ve `tag_posts()` (inc/view.php)
 *     değişmeden çalışmayı sürdürür. Göç var olan etiketleri geriye dönük
 *     doldurur.
 *
 *  3. **Ayar varsayılanları.** Onay kutusu ayarları BOŞ değerle ekranda
 *     "kapalı" görünür; kodda varsayılanı açık olan iki ayar bu yüzden burada
 *     AÇIKÇA yazılır (bkz. 019 göçündeki aynı tuzak).
 *
 * NOT: Dizin kurma mantığı `inc/search.php` içindeki `search_index_ensure()`
 * işlevindedir; göç ile panelin "yeniden kur" ucu AYNI kodu çalıştırsın diye.
 * İki kopya zamanla ayrışırdı.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    // ------------------------------------------------------------ etiketler
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS tags (
        id {ID},
        name VARCHAR(190) NOT NULL DEFAULT \'\',
        slug VARCHAR(190) NOT NULL DEFAULT \'\',
        post_count {INT} DEFAULT 0,
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('tags', 'idx_tags_slug', 'UNIQUE (slug)');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS post_tags (
        id {ID},
        post_id {INT} DEFAULT 0,
        tag_id {INT} DEFAULT 0
    )' . $ph['{SUF}'], $ph));
    schema_create_index('post_tags', 'idx_posttags_pair', 'UNIQUE (post_id, tag_id)');
    schema_create_index('post_tags', 'idx_posttags_tag', '(tag_id)');

    // ------------------------------------------------------------ ayarlar
    $varsayilan = [
        'search_fts_enabled' => '1',
        'search_highlight'   => '1',
        'search_tags_synced_at' => '',
    ];
    $mevcut = settings_all(true);
    foreach ($varsayilan as $k => $v) {
        if (!array_key_exists($k, $mevcut)) { setting_set($k, $v); }
    }
    settings_all(true);

    // ------------------------------------------------------------ dizin
    require_once INC_DIR . '/search.php';
    $motor = search_index_ensure();
    if ($motor === 'fts5') { search_index_rebuild(); }
    search_engine_reset();

    // ------------------------------------------------------------ geriye dönük etiket doldurma
    // Sınır YOK: göç bir kez çalışır ve etiketlerin TAMAMI dolmalıdır; yarım
    // dolmuş bir etiket bulutu, yayıncının fark edemeyeceği bir eksikliktir.
    search_tags_sync_all(0, '');
    setting_set('search_tags_synced_at', now());
};
