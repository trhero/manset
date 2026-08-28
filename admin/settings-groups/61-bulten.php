<?php
/**
 * Ayarlar → Bülten (Ajan-F, 1.2-11).
 *
 * Yalnız abone toplama ve onay akışının ayarları. Toplu bülten GÖNDERİMİ
 * kapsam dışıdır (CONTRACTS §13) — burada gönderim ayarı yoktur, olmayacaktır.
 *
 * Onaylanmamış kayıtların silinmesi KVKK gerekçelidir: rızası doğrulanmamış bir
 * e-posta adresini süresiz saklamak için hukuki bir dayanak yoktur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

return [
    'key'   => 'bulten',
    'title' => 'Bülten',
    'perm'  => 'newsletter.manage',
    'note'  => 'Abone listesi Bülten ekranındadır. Kayıt çift onaylıdır: '
             . 'onay bağlantısına tıklanmadan hiçbir adres listeye girmez.',
    'fields' => [
        'newsletter_confirm_hours'   => ['number', 'Onay bağlantısı geçerlilik süresi (saat)',
                                          'Varsayılan 72. 1–720.'],
        'newsletter_unconfirmed_days' => ['number', 'Onaylanmamış kaydı sil (gün)',
                                          'Varsayılan 30. 0 yazarsanız silinmez (KVKK açısından önerilmez).'],
    ],
    'clamp' => function (array $saved) {
        if (isset($saved['newsletter_confirm_hours'])) {
            $saved['newsletter_confirm_hours'] = (string)max(1, min(720, (int)$saved['newsletter_confirm_hours']));
        }
        if (isset($saved['newsletter_unconfirmed_days'])) {
            $saved['newsletter_unconfirmed_days'] = (string)max(0, min(3650, (int)$saved['newsletter_unconfirmed_days']));
        }
        return $saved;
    },
];
