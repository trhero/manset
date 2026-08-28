<?php
/**
 * 019 — SEO paketi (Ajan-E / 1.2-06, 1.2-07, 1.2-08).
 *
 * Üç ihtiyaç:
 *
 *  1. `redirects` — ELLE yazılan kalıcı yönlendirmeler. `slug_history` yalnız
 *     haber slug'ı değişince otomatik çalışır; eski bir siteden taşınan
 *     "/2019/eski-yazi" gibi serbest adresler için yayıncının kendi kuralı gerekir.
 *     `src_path` benzersizdir: aynı kaynak iki kez tanımlanamaz (belirsiz 301 olmaz).
 *
 *  2. Kategori ve etiket üstverisi. Kategoride sütun eklenir; etiketler serbest
 *     metin olduğu ve kendi tablosu bulunmadığı için `tag_seo` tablosu slug
 *     üzerinden eşler (slugify(etiket) = satır anahtarı).
 *
 *  3. `seo_pings` — IndexNow / sitemap ping / WebSub bildirim KUYRUĞU.
 *     Yayın anında dış servise HTTP isteği atılmaz: editör dış servisi beklemez
 *     ve servis çökerse yayın düşmez. Kuyruğu cron (`seo_ping_tick`) boşaltır.
 *     Ayarlarda JSON dizi yerine tablo kullanılmasının nedeni eşzamanlılık:
 *     tek bir `settings` satırını okuyup yazan iki istek birbirinin kaydını siler.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    // ---------------------------------------------------------- kategori SEO
    schema_add_column('categories', 'seo_title', "VARCHAR(255) NOT NULL DEFAULT ''");
    schema_add_column('categories', 'seo_desc',  "VARCHAR(500) NOT NULL DEFAULT ''");

    // ---------------------------------------------------------- etiket SEO
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS tag_seo (
        id {ID},
        slug VARCHAR(190) NOT NULL DEFAULT \'\',
        label VARCHAR(190) NOT NULL DEFAULT \'\',
        seo_title VARCHAR(255) NOT NULL DEFAULT \'\',
        seo_desc VARCHAR(500) NOT NULL DEFAULT \'\',
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('tag_seo', 'idx_tagseo_slug', 'UNIQUE (slug)');

    // ---------------------------------------------------------- elle 301
    // target YALNIZ site içi bir yoldur (bkz. seo_redirect_clean_target()).
    // Dış adres kabul edilmez: panele giren bir saldırgan aksi hâlde siteyi
    // kendi alan adına kalıcı olarak (301, tarayıcıda süresiz saklanır)
    // yönlendirebilirdi — klasik açık yönlendirme açığı.
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS redirects (
        id {ID},
        src_path VARCHAR(400) NOT NULL DEFAULT \'\',
        target VARCHAR(400) NOT NULL DEFAULT \'\',
        code {INT} DEFAULT 301,
        active {INT} DEFAULT 1,
        hits {INT} DEFAULT 0,
        note VARCHAR(190) NOT NULL DEFAULT \'\',
        created_at {DT},
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('redirects', 'idx_redirects_src', 'UNIQUE (src_path)');
    schema_create_index('redirects', 'idx_redirects_active', '(active)');

    // ---------------------------------------------------------- ping kuyruğu
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS seo_pings (
        id {ID},
        kind VARCHAR(20) NOT NULL DEFAULT \'indexnow\',
        url VARCHAR(400) NOT NULL DEFAULT \'\',
        status VARCHAR(20) NOT NULL DEFAULT \'queued\',
        attempts {INT} DEFAULT 0,
        error VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at {DT},
        sent_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('seo_pings', 'idx_seopings_status', '(status, id)');
    schema_create_index('seo_pings', 'idx_seopings_url', '(kind, url)');

    // ---------------------------------------------------------- ayar varsayılanları
    // Onay kutusu ayarları BOŞ değerle ekranda "kapalı" görünür. Kodda varsayılanı
    // açık olan üç ayar (sayfalı liste noindex, görsel haritası, tam metin besleme)
    // bu yüzden burada AÇIKÇA yazılır; yoksa yayıncı Ayarlar'ı ilk kez kaydettiğinde
    // hiç dokunmadığı üç davranış sessizce tersine dönerdi.
    $varsayilan = [
        'seo_paged_noindex'    => '1',
        'seo_sitemap_images'   => '1',
        'seo_feed_full'        => '1',
        'seo_feed_items'       => '30',
        'seo_feed_ttl'         => '900',
        'seo_slug_sync'        => '0',
        'seo_indexnow_enabled' => '0',
        'seo_sitemap_ping'     => '0',
        'seo_redirect_count'   => '0',
    ];
    $mevcut = settings_all(true);
    foreach ($varsayilan as $k => $v) {
        if (!array_key_exists($k, $mevcut)) { setting_set($k, $v); }
    }
    settings_all(true);
};
