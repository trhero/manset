<?php
/**
 * 014 — Sürüm 1.1: "ajans/otomasyon kaynaklı haber" için SİSTEM SAHİPLİ bayrak.
 *
 * NEDEN GEREKLİ (denetim turu 3, B13):
 * `posts.edit_wire` izni, Otomasyon Editörü'ne "ajans ve yapay zekâ kaynaklı
 * haberleri düzenleme" yetkisi verir. Bu bir YETKİ KARARIDIR. Ama karar
 * `source_url` alanına bakıyordu — o alan panelde ELLE doldurulabilen bir
 * kullanıcı girdisidir. Sonuç: bir yazar kendi haberine herhangi bir adres
 * yazarak onu "ajans haberi" ilan edip Otomasyon Editörü'nün erişimine
 * açabiliyordu; ya da tersine, kendi yetki alanını genişletmek isteyen biri
 * başkasının haberine erişmek için o alanı kullanabilirdi.
 *
 * Yetki kararı yalnız SİSTEMİN yazdığı alanlara dayanmalıdır. `ai_generated`
 * öyleydi (yalnız AI kuyruğu yazar) ama RSS içe aktarımı `ai_generated = 0`
 * yazıyor ve `rss_items` tablosunda haberi işaret eden bir sütun bulunmuyor —
 * yani RSS kaynaklı haberi tanıyan sistem sahipli hiçbir alan YOKTU.
 *
 * `is_wire` bu boşluğu kapatır: YALNIZ içe aktarıcılar (inc/rss.php,
 * inc/ai_jobs.php) yazar, panel formu bu alanı hiç göndermez.
 *
 * GERİYE DÖNÜK VERİ: bu göçten önce içe aktarılmış haberler için bayrak,
 * o günkü sezgisel kuralla bir kez doldurulur (ai_generated = 1 ya da
 * source_url dolu). Tek seferlik bir tahmindir; bundan sonrası yazılan veriye
 * dayanır.
 */
return function (PDO $pdo, $driver) {
    schema_add_column('posts', 'is_wire', '{INT} DEFAULT 0');
    schema_create_index('posts', 'idx_posts_wire', '(is_wire)');

    // Tek seferlik geriye dönük doldurma (bkz. başlıktaki açıklama).
    $pdo->exec('UPDATE posts SET is_wire = 1
                WHERE is_wire = 0
                  AND (ai_generated = 1 OR (source_url IS NOT NULL AND source_url <> \'\'))');
};
