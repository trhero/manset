<?php
/**
 * Ayar grubu — Reklam (1.2-03). Ajan-B.
 *
 * ETİKET METNİ DEĞİŞTİRİLEBİLİR, ETİKETİN KENDİSİ KAPATILAMAZ.
 * Alanlar boş bırakılırsa `ads_label_text()` / `ads_sponsored_label()`
 * varsayılana düşer; yani "etiketi sil" diye bir yol yoktur. Reklamın ve
 * sponsorlu içeriğin okuyucuya açıkça belirtilmesi teknik olarak zorunlu
 * tutulur. Hangi düzenlemenin hangi hükmünün geçerli olduğunu YAYINCI
 * yürürlükteki mevzuattan teyit etmelidir — bu yazılım madde numarası iddia
 * etmez.
 */
return [
    'key'   => 'reklam',
    'title' => 'Reklam ve paylaşım',
    'perm'  => 'settings.manage',
    'note'  => 'Reklam etiketi, gösterim sayacı, gövde içi reklam yerleşimi ve paylaşım görseli. '
             . 'Reklamların kendisi Panel → Reklamlar ekranından yönetilir.',
    'fields' => [
        'ads_label_text' => ['text', 'Reklam etiketi',
            'Reklam kutusunun üstünde görünür. Boş bırakılırsa "Reklam" kullanılır — etiket kapatılamaz.'],
        'ads_sponsored_label' => ['text', 'Sponsorlu içerik etiketi',
            'Sponsorlu işaretli kayıtlarda görünür. Boş bırakılırsa "Sponsorlu içerik" kullanılır.'],
        // ANAHTAR NEDEN OLUMSUZ: ayar ekranı bir onay kutusunu `setting($k,'') === '1'`
        // ile çizer. Olumlu bir anahtar (ads_count_enabled) henüz veritabanında
        // yokken kutu BOŞ görünür ve ilk kaydedişte sessizce '0' yazılırdı —
        // yayıncı hiçbir şey yapmadan sayaç kapanırdı. Olumsuz anahtarda
        // "tanımsız" ile "kapalı değil" aynı şeydir.
        'ads_count_disabled' => ['bool', 'Gösterim ve tıklama sayacını KAPAT',
            'Varsayılan: açık. Kapatılırsa reklam kutularına sayaç işareti konmaz ve tarayıcı '
            . 'hiçbir istek atmaz. Sayım zaten çerezsizdir: giriş yapmamış ziyaretçiye çerez verilmez.'],
        'ads_inbody_paragraph' => ['number', 'Gövde içi reklam: kaçıncı paragraftan sonra',
            '0 = kapalı. Örneğin 3 yazarsanız haber gövdesindeki üçüncü paragraftan sonra '
            . '"in_article" alanı bir kez daha basılır. Haberde en az bu sayıdan fazla paragraf yoksa basılmaz.'],
        'ads_stats_keep_days' => ['number', 'İstatistik saklama süresi (gün)',
            'Bu süreden eski günlük sayaç satırları zamanlı görevle silinir. En az 30, en çok 1825.'],

        // ---- 1.2-12 paylaşım görseli ----
        'share_font_file' => ['text', 'Paylaşım görseli yazı tipi',
            'uploads klasöründeki bir .ttf dosyasının adı (örn. "yazitipi.ttf"). Boş bırakılırsa GD\'nin '
            . 'gömülü yazı tipi kullanılır; o durumda Türkçe harfler sadeleşir (ş→s, ğ→g).'],
        'share_image_bg' => ['text', 'Paylaşım görseli zemin rengi',
            '#rrggbb biçiminde. Boş bırakılırsa koyu varsayılan kullanılır.'],
        'share_image_fg' => ['text', 'Paylaşım görseli yazı rengi',
            '#rrggbb biçiminde. Boş bırakılırsa beyaz kullanılır.'],
    ],
    'clamp' => function (array $saved) {
        if (array_key_exists('ads_inbody_paragraph', $saved)) {
            $saved['ads_inbody_paragraph'] = (string)max(0, min(30, (int)$saved['ads_inbody_paragraph']));
        }
        if (array_key_exists('ads_stats_keep_days', $saved)) {
            $saved['ads_stats_keep_days'] = (string)max(30, min(1825, (int)$saved['ads_stats_keep_days']));
        }
        return $saved;
    },
];
