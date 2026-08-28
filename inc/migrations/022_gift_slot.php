<?php
/**
 * 022 — Hediye kotasını veritabanı kısıtına bağla (denetim turu 4, O-01).
 *
 * SORUN: `paywall_gift_create()` önce "bu ay kaç hediye verilmiş" diye SORUYOR,
 * sonra YAZIYOR. Aradaki boşlukta ikinci bir istek aynı cevabı alır. Denetçi
 * ardışık isteklerde kotanın tuttuğunu, ama PARALEL PHP süreçleriyle kota 2 iken
 * 10 eşzamanlı isteğin 10 bağlantı ürettiğini ölçtü.
 *
 * Not: HTTP üzerinden denendiğinde kota "tutuyor" görünüyordu — yerleşik PHP
 * sunucusu istekleri sıraya sokuyor. Yani bu hata, gerçek bir sunucuda (birden
 * çok işçi) ortaya çıkıp geliştirme ortamında GİZLENEN türden.
 *
 * ÇÖZÜM: sayma işini uygulamadan alıp veritabanına vermek. Her hediyeye bir
 * `slot` numarası verilir (0 … kota-1) ve `(giver_id, period_key, slot)`
 * BENZERSİZ olur. İki istek aynı slotu hesaplarsa ikincisinin INSERT'i
 * veritabanı düzeyinde reddedilir — kontrol ile yazma arasında boşluk kalmaz.
 *
 * Neden sayaç sütunu değil: ayrı bir sayaç tablosunu artırmak da aynı yarışı
 * taşır (okuma + yazma). Benzersiz kısıt, kararı tek bir atomik işleme indirger
 * ve sürücüden bağımsızdır — SQLite'ta da MySQL'de de aynı davranır.
 */
return function (PDO $pdo, $driver) {
    if (!function_exists('schema_has_table') || !schema_has_table('gift_tokens')) { return; }

    // Var olan satırlar için slot: -1 "eski kayıt" demektir. Benzersiz indeks
    // NULL'ları saymaz ama -1'leri sayar; bu yüzden geçmiş kayıtlara satır
    // numarası vermek yerine her birine KENDİ kimliğini negatif olarak yazıyoruz
    // — böylece hiçbir eski kayıt bir diğeriyle çakışmaz ve indeks kurulabilir.
    schema_add_column('gift_tokens', 'slot', '{INT} DEFAULT 0');

    try {
        $pdo->exec('UPDATE gift_tokens SET slot = -id WHERE slot = 0');
    } catch (Throwable $e) {
        log_error('Göç 022: eski hediye kayıtlarına slot verilemedi: ' . $e->getMessage());
    }

    schema_create_index('gift_tokens', 'idx_gift_slot', 'UNIQUE (giver_id, period_key, slot)');
};
