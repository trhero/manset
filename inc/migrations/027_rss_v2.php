<?php
/**
 * 027 — RSS v2 (1.3-05, Ajan-D): çapraz kaynak tekrar tespiti ve görsel içe aktarma.
 *
 * NEDEN
 * -----
 * 1) `rss_items.guid_hash` mükerrer engeli KAYNAK BAŞINADIR (`sha1(source_id|link|guid|title)`).
 *    Aynı haber iki ajanstan geldiğinde iki ayrı `guid_hash` üretir ve HAVUZA
 *    İKİ KEZ girer; otomatik yayın açıksa site aynı haberi iki kez yayımlar.
 *    Tespit iki ayağa dayanır ve ikisi de aranabilir olmalı:
 *      · `link_hash` — izleme parametreleri (utm_*, fbclid…) atıldıktan sonra
 *        normalleştirilmiş bağlantının sha1'i. Kaynaklar aynı habere aynı adresi
 *        farklı kampanya kuyruklarıyla verir; kuyruk atılınca adres aynılaşır.
 *      · `title_key` — normalleştirilmiş başlığın sha1'i. Tam eşleşme için hızlı
 *        yol; benzerlik hesabı yalnız bu tutmadığında çalışır.
 *
 * 2) `dup_of` — TESPİT SİLMEZ, İŞARETLER. Yanlış pozitif bir eşleşme farklı bir
 *    haberi sessizce yutardı; bu yüzden ikinci öge havuza YAZILIR, `status`
 *    'skipped' olur ve `dup_of` hangi ögenin kopyası sayıldığını söyler. Editör
 *    havuzda görür, yanlışsa tek tıkla taslak yapar. Kayıp yok, gürültü yok.
 *
 * 3) `media_id` — uzak görsel indirilip kütüphaneye alındığında oluşan medya
 *    kaydının kimliği. Sıfırsa görsel hâlâ uzak adrestedir (1.2 davranışı).
 *
 * NOT: `rss_sources.interval_min` ve `next_check_at` göç 013'te GELDİ, burada
 * yeniden eklenmez.
 */
return function (PDO $pdo, $driver) {
    // Boş dize "hesaplanmadı" demektir: NULL yerine dize kullanılıyor ki
    // sürücüden bağımsız karşılaştırılabilsin (013'teki deleted_at kalıbı).
    schema_add_column('rss_items', 'link_hash', "VARCHAR(40) NOT NULL DEFAULT ''");
    schema_add_column('rss_items', 'title_key', "VARCHAR(40) NOT NULL DEFAULT ''");
    schema_add_column('rss_items', 'dup_of',    '{INT} DEFAULT 0');
    schema_add_column('rss_items', 'media_id',  '{INT} DEFAULT 0');

    // Tekrar taraması "son N saatteki ögeler" üzerinde çalışır; sıralama ve
    // eşleşme bu üç indeksle tarama yapmadan yürür.
    schema_create_index('rss_items', 'idx_rssitems_link',  '(link_hash)');
    schema_create_index('rss_items', 'idx_rssitems_tkey',  '(title_key)');
    schema_create_index('rss_items', 'idx_rssitems_fetch', '(fetched_at)');
};
