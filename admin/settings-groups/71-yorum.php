<?php
/**
 * Ayarlar — yorum sistemi v2 (1.3-07, Ajan-B).
 *
 * Çekirdek `yorum` grubu (açık/onay/anonim) `admin/pages/settings.php` içinde
 * ve EZİLEMEZ. Burada yalnız 1.3 ile gelen davranışlar var; ayrı bir kart
 * olması bilinçli: yayıncı "yorumlar açık mı" sorusunu üç alanlık kısa bir
 * kartta yanıtlamaya devam ediyor.
 */
return [
    'key'   => 'yorum_ileri',
    'title' => 'Yorum ayrıntıları',
    'perm'  => 'comments.moderate',
    'note'  => 'Yanıtlar, sayfalama ve bildirim davranışı. Kara liste kuralları Yorumlar ekranındadır.',
    'fields' => [
        'comments_replies' => ['bool', 'Yanıtlara izin ver',
            'Zincir tek seviyedir: bir yanıtın altına yazılan yanıt köke bağlanır.'],
        'comments_per_page' => ['number', 'Sayfa başına yorum',
            '5–200 arası. Yalnız kök yorumlar sayılır; yanıtlar kökleriyle birlikte gelir.'],
        'comments_report' => ['bool', 'Okur uygunsuz yorumu bildirebilsin',
            'Bildirim yorumu YAYINDAN KALDIRMAZ; yalnız moderasyon kuyruğunda işaretler.'],
        'comments_report_threshold' => ['number', 'Kaç bildirimde otomatik beklemeye alınsın',
            '0 = hiç (önerilen). Sıfırdan büyük bir değer, örgütlü birkaç kişinin '
            . 'beğenmediği yorumu yayından kaldırmasına izin verir.'],
    ],
    'clamp' => function (array $saved) {
        if (isset($saved['comments_per_page'])) {
            $saved['comments_per_page'] = (string)max(5, min(200, (int)$saved['comments_per_page']));
        }
        if (isset($saved['comments_report_threshold'])) {
            $saved['comments_report_threshold'] = (string)max(0, min(100, (int)$saved['comments_report_threshold']));
        }
        return $saved;
    },
];
