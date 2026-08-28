<?php
/**
 * Ayar grubu — RSS çekimi (1.3-05, Ajan-D).
 *
 * NEDEN `rss.manage`
 * Buradaki alanlar hangi ajans haberinin siteye gireceğini belirler; RSS
 * havuzunu yöneten rol bunları kendi ayarlamalıdır. Yeni izin EKLENMEDİ
 * (1.3 sözleşmesi §4) — mevcut `rss.manage` yetiyor.
 *
 * EŞİK NEDEN AYARLANABİLİR
 * Tekrar tespitinin iki hata türü var ve ikisi de yayıncıya göre değişir:
 *   · Yanlış pozitif: farklı iki haber aynı sayılır. Bedeli, ikinci haberin
 *     havuzda 'tekrar' rozetiyle beklemesidir — kaybolmaz, editör görür.
 *   · Yanlış negatif: aynı haber iki kez girer. Bedeli, sitede mükerrer haberdir.
 * Beş ajansı birden okuyan bir gazete eşiği düşürmek (daha çok eleme), tek
 * ajans okuyan bir yerel site yükseltmek ister. Varsayılan %82, ölçümle seçildi
 * (gelistirme/1.3-ajan-d.md — 20 başlık çiftinde 0 yanlış pozitif).
 *
 * GÖRSEL İÇE AKTARMA VARSAYILAN KAPALI
 * Uzak görseli indirmek disk tüketir ve TELİF kararıdır: ajansın görselini
 * kendi sunucusundan servis etmek, adresini göstermekten farklı bir şeydir.
 * Karar yayıncınındır; kayıt noktası kuralı gereği (CONTRACTS §14) yükseltme
 * bu davranışı kendiliğinden açmaz.
 */

return [
    'key'   => 'rss',
    'title' => 'RSS çekimi',
    'perm'  => 'rss.manage',
    'note'  => 'Çapraz kaynak tekrar tespiti ögeyi SİLMEZ: havuza yazar, "tekrar" olarak '
             . 'işaretler ve otomatik yayından çıkarır. Yanlış işaretlenmiş bir ögeyi '
             . 'havuzda "Taslak yap" ile yine de alabilirsiniz.',

    'fields' => [
        'rss_dedupe' => ['bool', 'Çapraz kaynak tekrar tespiti',
            'Aynı haber iki ajanstan geldiğinde ikincisi "tekrar" olarak işaretlenir.'],
        'rss_dedupe_threshold' => ['number', 'Başlık benzerlik eşiği (%)',
            'Bu oranın üstündeki başlıklar aynı haber sayılır. 50–100. Düşürmek daha çok '
          . 'eler (mükerrer az, yanlış işaretleme çok); yükseltmek tersi. Bağlantı ya da '
          . 'başlık TAM eşleşmesi bu eşikten bağımsız çalışır.'],
        'rss_dedupe_window_hours' => ['number', 'Karşılaştırma penceresi (saat)',
            'Yalnız bu kadar süre içinde çekilmiş ögelerle karşılaştırılır. 1–720. '
          . 'Pencereyi büyütmek eski bir haberin güncel bir haberi elemesine yol açabilir.'],

        'rss_image_import' => ['bool', 'Görseli sunucuya indir',
            'Kapalıyken haberin görseli kaynağın sunucusundan gösterilir (mevcut davranış). '
          . 'Açıkken taslak üretilirken görsel indirilip medya kütüphanesine alınır. '
          . 'Telif ve disk sorumluluğu yayıncıya aittir.'],
        'rss_image_max_kb' => ['number', 'İndirilecek görsel için üst sınır (KB)',
            'Bu boyutu aşan görsel indirilmez, haber uzak adresle oluşturulur. 64–10240.'],
    ],

    // Sınırlar inc/rss.php ile AYNI aralıkta tutulur; iki yerde farklı sınır,
    // ekranda kaydedilen değerin kodda sessizce kırpılması demek olurdu.
    'clamp' => function (array $saved) {
        $sinir = [
            'rss_dedupe_threshold'    => [50, 100],
            'rss_dedupe_window_hours' => [1, 720],
            'rss_image_max_kb'        => [64, 10240],
        ];
        foreach ($sinir as $anahtar => $aralik) {
            if (!array_key_exists($anahtar, $saved)) { continue; }
            $saved[$anahtar] = (string)max($aralik[0], min($aralik[1], (int)$saved[$anahtar]));
        }
        return $saved;
    },
];
