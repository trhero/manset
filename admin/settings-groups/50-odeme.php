<?php
/**
 * Ayar grubu — Ödeme ve abonelik (1.2-04).
 *
 * `perm` KİLİTLİ `payments.manage` iznidir: sağlayıcı anahtarlarını yalnız
 * admin görebilir. `secret` tipindeki alanlar EKRANA BASILMAZ; boş
 * gönderildiklerinde mevcut değer korunur (bkz. admin/pages/settings.php).
 *
 * Bildirim (webhook) adresi burada gösterilmez çünkü ayar grubu yalnız alan
 * listesi döndürür; adres Panel → Ödemeler ekranının üst kartındadır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

return [
    'key'   => 'odeme',
    'title' => 'Ödeme ve abonelik',
    'perm'  => 'payments.manage',
    'note'  => 'Kart bilgisi bu sisteme hiçbir zaman girmez; ödeme sağlayıcının '
             . 'kendi barındırdığı sayfada alınır. Sağlayıcı anahtarları maskelidir, '
             . 'ekranda ve günlükte görünmez.',
    'fields' => [
        'payment_enabled' => ['bool', 'Çevrimiçi abonelik açık',
            'Kapalıyken ödeme sayfası ziyaretçiye kapalıdır ve webhook hiçbir aboneliği uzatmaz.'],

        'payment_provider' => ['select', 'Ödeme yöntemi', 'Barındırılan ödeme sayfası kullanılır.', [
            'manual_iban' => 'Havale / EFT (dekont onayı)',
            'paytr'       => 'PayTR (barındırılan ödeme sayfası)',
        ]],

        'payment_manual_enabled' => ['bool', 'Kart yanında havale de kabul edilsin',
            'PayTR seçiliyken okura ikinci bir yol olarak havale sunulur.'],

        'payment_test_mode' => ['bool', 'Test modu',
            'Sağlayıcıya gerçek çağrı yapılmaz. Gelen bildirimin imza doğrulaması test modunda da AYNEN çalışır.'],

        'payment_iban'      => ['text', 'IBAN', 'Havale/EFT için. Okur açıklama alanına sipariş numarasını yazar.'],
        'payment_iban_name' => ['text', 'Hesap sahibi', ''],
        'payment_bank'      => ['text', 'Banka adı', ''],

        'payment_paytr_merchant_id'    => ['text', 'PayTR üye işyeri numarası', 'Gizli değildir; anahtar ve salt aşağıdadır.'],
        'payment_paytr_merchant_key'   => ['secret', 'PayTR merchant_key', 'Boş bırakırsanız mevcut anahtar korunur.'],
        'payment_paytr_merchant_salt'  => ['secret', 'PayTR merchant_salt', 'Boş bırakırsanız mevcut değer korunur.'],
        'payment_paytr_sandbox'        => ['bool', 'PayTR sandbox (test) işyeri',
            'Sağlayıcıya test bayrağı gönderilir. Canlıya geçerken kapatın.'],

        'payment_timeout' => ['number', 'Sağlayıcı zaman aşımı (sn)', '5–60 arası.'],

        'payment_terms_url' => ['text', 'Mesafeli satış / abonelik koşulları sayfası',
            'Abonelik satışında bilgilendirme yükümlülükleri için yayıncı yürürlükteki düzenlemeyi teyit etmelidir.'],
    ],

    /** Sınır düzeltmeleri — panelden gelen değerler makul aralığa çekilir. */
    'clamp' => function (array $saved) {
        if (array_key_exists('payment_timeout', $saved)) {
            $saved['payment_timeout'] = (string)max(5, min(60, (int)$saved['payment_timeout']));
        }
        if (array_key_exists('payment_iban', $saved)) {
            // Boşluklar atılır, yalnız harf/rakam kalır; büyük harfe çevrilir.
            $iban = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$saved['payment_iban']));
            $saved['payment_iban'] = substr($iban, 0, 34);
        }
        return $saved;
    },
];
