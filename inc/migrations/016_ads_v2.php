<?php
/**
 * 016 — Reklam v2 (1.2-03): gösterim/tıklama sayacı ve sponsorlu içerik bayrağı.
 *
 * İKİ EKLEME, TEK GÖÇ:
 *
 * 1) `ad_stats` — GÜNLÜK TOPLU SAYAÇ.
 *    Satır başına bir gösterim yazmak (ham günlük) küçük bir sitede bile günde
 *    yüz binlerce satır demek; paylaşımlı hostingde SQLite dosyası şişer ve
 *    yedek alınamaz hâle gelir. Bu yüzden kayıt (reklam, gün) çiftinde
 *    TOPLANIR: her istek tek bir UPDATE ile sayacı artırır, satır sayısı
 *    reklam × gün ile sınırlı kalır. Rapor ekranı da doğrudan bu tabloyu okur.
 *
 *    `slot_key` satırda TEKRARLANIR (ads tablosundan JOIN ile de bulunabilirdi):
 *    reklam silindikten sonra bile geçmiş rapor okunabilsin diye. Reklam kaydı
 *    gidince istatistiği anlamsız bir kimliğe düşmez.
 *
 * 2) `ads.is_sponsored` — SPONSORLU İÇERİK BAYRAĞI.
 *    Reklam ile haber görsel olarak ayırt edilemediğinde okuyucu yanılır.
 *    Bayrak açık olan kayıt ön yüzde "Sponsorlu içerik" etiketiyle basılır
 *    (bkz. inc/ads.php → ads_slot_decorate). Etiketin metni ve görünürlüğü
 *    ayarlardan değiştirilebilir ama KAPATILAMAZ bir varsayılanı vardır.
 *
 *    NOT (mevzuat): reklam ve sponsorlu içeriğin açıkça belirtilmesi
 *    yükümlülüğü Türkiye'de birden çok düzenlemede (ticari iletişim, reklam
 *    kurulu kararları, basın mevzuatı) geçer. Burada MADDE NUMARASI YAZILMAZ;
 *    yayıncı yürürlükteki düzenlemeyi kendi hukuk danışmanıyla teyit etmelidir.
 *    Yazılımın verdiği güvence yalnız teknik: etiket basılır ve kapatılamaz.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    /* 1) Günlük toplu sayaç ---------------------------------------------- */
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS ad_stats (
        id {ID},
        ad_id {INT} DEFAULT 0,
        day VARCHAR(10) NOT NULL DEFAULT \'\',
        slot_key VARCHAR(40) NOT NULL DEFAULT \'\',
        impressions {INT} DEFAULT 0,
        clicks {INT} DEFAULT 0,
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));

    // (reklam, gün) TEKİL: artırma "önce UPDATE, tutmazsa INSERT" ile yapılır;
    // yarışta ikinci INSERT bu kısıta takılır ve tekrar UPDATE'e düşer.
    schema_create_index('ad_stats', 'idx_adstats_ad_day', 'UNIQUE (ad_id, day)');
    schema_create_index('ad_stats', 'idx_adstats_day', '(day)');

    /* 2) Sponsorlu içerik bayrağı ---------------------------------------- */
    schema_add_column('ads', 'is_sponsored', '{INT} DEFAULT 0');
};
