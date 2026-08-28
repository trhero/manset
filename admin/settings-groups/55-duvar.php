<?php
/**
 * Ayar grubu — Ölçülü ödeme duvarı (1.2-05, Ajan-D).
 *
 * NEDEN `settings.manage`
 * Buradaki her alan PARAYA dokunur: ücretsiz hak sayısını yükseltmek aboneliği
 * anlamsızlaştırır, hediye kotasını yükseltmek duvarı hediye ucundan deler.
 * `settings.manage` KİLİTLİ bir izindir (CONTRACTS §12.5, yalnız `admin`) ve
 * grup `perm` alanı sayesinde yetkisi olmayana alanlar HİÇ BASILMAZ.
 *
 * VARSAYILANLAR KAPALI
 * Üç özellik de (`paywall_meter_enabled`, `paywall_gift_enabled`,
 * `paywall_trial_days=0`, `paywall_renew_enabled`) kapalı gelir. Kayıt
 * noktalarının kuralı budur (CONTRACTS §14): kayıt yapılmazsa davranış
 * 1.1'dekiyle birebir aynıdır. Yükseltme yapan yayıncının ödeme duvarı bir
 * gecede kendiliğinden gevşememelidir.
 */

return [
    'key'   => 'duvar',
    'title' => 'Ödeme duvarı (ölçülü)',
    'perm'  => 'settings.manage',
    'note'  => 'Ölçülü duvarın sayacı imzalı bir ÇEREZDE tutulur; sunucuda okur başına '
             . 'satır ya da IP saklanmaz. Çerez yalnız kilitli haber sayfasında verilir '
             . 've o sayfa zaten önbelleğe alınmaz. Okur çerezi silerse sayaç sıfırlanır — '
             . 'bu bilinen ve kabul edilmiş bir sınırdır: ölçülü duvar bir kilit değil, '
             . 'bir nezaket sınırıdır. Gerçek kilit abonelik denetimidir.',

    'fields' => [
        'paywall_meter_enabled' => ['bool', 'Ölçülü duvar açık',
            'Kapalıyken kilitli haberler yalnız aboneliğe göre açılır (1.1 davranışı).'],
        'paywall_meter_free' => ['number', 'Ayda kaç ücretsiz kilitli haber',
            'Takvim ayı başında sıfırlanır. 0–30. Aynı habere geri dönmek yeni hak yakmaz.'],
        'paywall_meter_scope' => ['select', 'Sayaç hangi içerikleri kapsasın', '', [
            'premium' => 'Yalnız abonelere özel',
            'all'     => 'Üyelere ve abonelere özel',
        ]],

        'paywall_gift_enabled' => ['bool', 'Hediye bağlantısı açık',
            'Abone, tek bir haberi abone olmayan birine açabilir. Bağlantı TEK KULLANIMLIKTIR.'],
        'paywall_gift_monthly' => ['number', 'Abone başına aylık hediye hakkı',
            'Kotanın anlamı: hediye ucu ödeme duvarının en zayıf noktasıdır. 0–200.'],
        'paywall_gift_ttl_hours' => ['number', 'Hediye bağlantısı kaç saat geçerli',
            'Süre dolunca bağlantı kullanılamaz. 1–720.'],

        'paywall_trial_days' => ['number', 'Deneme süresi (gün)',
            '0 → deneme kapalı. Deneme HESAP BAŞINA BİR KEZDİR ve süresi dolduktan sonra '
          . 'yeniden başlatılamaz. 0–365.'],

        'paywall_renew_enabled' => ['bool', 'Yenileme hatırlatması gönder',
            'Aboneliği bitmek üzere olan üyeye TEK TEK işlemsel e-posta gider. '
          . 'Toplu bülten gönderimi bu sürümün kapsamı dışındadır (CONTRACTS §13).'],
        'paywall_renew_days' => ['number', 'Bitişten kaç gün önce hatırlatılsın',
            'Aynı bitiş tarihi için yalnız bir kez gönderilir. 1–60.'],
    ],

    // Sayısal sınırlar inc/paywall.php ile AYNI aralıkta tutulur; iki yerde
    // farklı sınır olsaydı ekranda kaydedilen değer kodda sessizce kırpılırdı.
    'clamp' => function (array $saved) {
        $sinir = [
            'paywall_meter_free'     => [0, 30],
            'paywall_gift_monthly'   => [0, 200],
            'paywall_gift_ttl_hours' => [1, 720],
            'paywall_trial_days'     => [0, 365],
            'paywall_renew_days'     => [1, 60],
        ];
        foreach ($sinir as $anahtar => $aralik) {
            if (!array_key_exists($anahtar, $saved)) { continue; }
            $saved[$anahtar] = (string)max($aralik[0], min($aralik[1], (int)$saved[$anahtar]));
        }
        return $saved;
    },
];
