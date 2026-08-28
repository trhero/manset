<?php
/**
 * 025 — Medya v2 (1.3-08): varyant kuyruğu, odak noktası, alt metin denetimi.
 *
 * NEDEN KUYRUK: 1.0'dan beri `media_save_upload()` yükleme İSTEĞİNİN İÇİNDE
 * bütün boyutları (thumb/medium/large + üç WebP kopyası) üretiyordu. 4000×3000
 * bir fotoğrafta bu, altı ayrı `imagecopyresampled()` + altı kodlama demek;
 * paylaşımlı hostingde `max_execution_time` (çoğunlukla 30 sn) yetmeyebiliyor
 * ve editör "yükleme başarısız" görüyor — üstelik dosya diske YAZILMIŞ oluyor,
 * yani yarım kalan yükleme sahipsiz dosya bırakıyor. Artık yüklemede yalnız
 * küçük önizleme (thumb) üretilir, kalanı cron kuyruğuna alınır.
 *
 * KIRIK GÖRSEL OLMAZ: ön yüzdeki `media_variant_file()` (inc/view.php) zaten
 * `is_file()` ile diske bakar; varyant henüz yoksa `post_image()` özgün dosyaya
 * düşer. Yani kuyruk işlenmeden de sayfa doğru çalışır, yalnız görsel büyüktür.
 *
 * SÜTUNLAR
 *   variant_state  '' | 'queued' | 'done' | 'failed' | 'skipped'
 *                  Boş dize = 1.3 öncesi kayıt; kuyruk onları da toplar
 *                  (`media_variants_pending()` boş durumu "işlenmemiş" sayar).
 *   variant_at     Son kuyruk denemesinin damgası (VARCHAR(19), boş = hiç).
 *   variant_tries  Deneme sayısı; 3'ü geçen kayıt 'failed' olur ve kuyruğu
 *                  sonsuza dek tıkamaz.
 *   focus_x/focus_y  Kırpmada korunacak nokta, YÜZDE (0..100). Varsayılan 50/50
 *                  yani merkez — eski davranışla birebir aynı.
 *
 * NEDEN YÜZDE: kırpma her varyantta farklı piksel ölçeğinde yapılır; mutlak
 * piksel saklamak her ölçekte yeniden hesap ister ve özgün dosya değişirse
 * (elle üzerine yazma) anlamsızlaşır. Yüzde ölçekten bağımsızdır.
 *
 * `alt` sütunu 1.0'dan beri var; yeni sütun gerekmez. Alt metin uyarısı
 * `alt = ''` sorgusuyla çalışır, bunun için indeks eklenir.
 */
return function (PDO $pdo, $driver) {
    // Varyant kuyruğu
    schema_add_column('media', 'variant_state', "VARCHAR(16) NOT NULL DEFAULT ''");
    schema_add_column('media', 'variant_at',    "VARCHAR(19) NOT NULL DEFAULT ''");
    schema_add_column('media', 'variant_tries', '{INT} DEFAULT 0');

    // Odak noktası (yüzde)
    schema_add_column('media', 'focus_x', '{INT} DEFAULT 50');
    schema_add_column('media', 'focus_y', '{INT} DEFAULT 50');

    // Kuyruk taraması ve alt metin denetimi için indeksler
    schema_create_index('media', 'idx_media_vstate', '(variant_state, id)');
    schema_create_index('media', 'idx_media_alt', '(alt)');

    /* GERİYE DÖNÜK UYUM: kurulumda hâlihazırda VARYANTI OLAN kayıtlar 'done'
       sayılır ki kuyruk ilk turda bütün kütüphaneyi yeniden üretmeye kalkmasın.
       `variants` sütunu boş olanlar '' kalır; kuyruk onları sırayla toplar. */
    $pdo->exec('UPDATE media SET variant_state = \'done\' WHERE variant_state = \'\' AND variants <> \'\'');
};
