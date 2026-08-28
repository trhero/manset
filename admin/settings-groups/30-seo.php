<?php
/**
 * Ayarlar → SEO ve dağıtım grubu (Ajan-E / 1.2-06).
 *
 * BU DOSYA NEDEN VAR: `seo_default_image`, `seo_robots_extra`, `seo_feed_items`,
 * `seo_feed_ttl` ve `seo_slug_sync` 1.1'e kadar KODDA okunuyordu ama hiçbir
 * ekranda alanı yoktu — yayıncı bunları ancak veritabanına elle yazarak
 * değiştirebiliyordu. Buradaki her anahtarın kodda bir okuyucusu vardır:
 *
 *   seo_default_image      inc/seo.php  → seo_default_image()
 *   seo_robots_extra       inc/seo.php  → seo_robots_txt()
 *   seo_feed_items         rss.php      → feed_default_count()
 *   seo_feed_ttl           rss.php / sitemap.php → feed_ttl(), sitemap_ttl()
 *   seo_slug_sync          sitemap.php  → dizin isteğinde slug eşitleme
 *   seo_title_tpl_*        inc/seo.php  → seo_title_templates()
 *   seo_paged_noindex      inc/seo.php  → seo_robots()
 *   seo_same_as            inc/seo.php  → seo_same_as() → Organization.sameAs
 *   seo_verify_*           inc/seo.php  → seo_verification_metas()
 *   seo_sitemap_images     sitemap.php  → görsel site haritası
 *   seo_feed_full          rss.php      → tam metin / özet beslemesi
 *   seo_indexnow_*         inc/seo.php  → seo_ping_tick()
 *   seo_sitemap_ping       inc/seo.php  → seo_ping_tick()
 *   seo_websub_hub         inc/seo.php  → seo_websub_hub()
 *
 * `perm` => `seo.manage`: SEO editörü bu grubu görebilir, genel ayarlar
 * ekranının tamamı ise `settings.manage` (kilitli) ister. İki izin birbirini
 * kesmez; grup yalnız ikisi de varsa görünür.
 */
return [
    'key'   => 'seo',
    'title' => 'SEO ve dağıtım',
    'perm'  => 'seo.manage',
    'note'  => 'Başlık şablonlarında kullanılabilecek yer tutucular: %baslik% %site% %slogan% '
             . '%kategori% %etiket% %arama% %sayfa%. Karşılığı olmayan yer tutucu ve ondan artan '
             . 'ayraç çıktıdan otomatik silinir.',
    'fields' => [
        // ---- arama motoru görünümü
        'seo_title_tpl_home'     => ['text', 'Başlık şablonu — anasayfa', 'Varsayılan: %site% — %slogan%'],
        'seo_title_tpl_post'     => ['text', 'Başlık şablonu — haber', 'Varsayılan: %baslik% — %site%. Haberin kendi SEO başlığı doluysa şablon uygulanmaz.'],
        'seo_title_tpl_category' => ['text', 'Başlık şablonu — kategori', 'Varsayılan: %kategori% Haberleri — %site%'],
        'seo_title_tpl_tag'      => ['text', 'Başlık şablonu — etiket', 'Varsayılan: %etiket% Etiketli Haberler — %site%'],
        'seo_title_tpl_search'   => ['text', 'Başlık şablonu — arama', 'Varsayılan: "%arama%" için arama sonuçları — %site%'],
        'seo_default_image'      => ['image', 'Varsayılan paylaşım görseli', 'Habere görsel eklenmediğinde og:image olarak kullanılır. Boşsa site logosu.'],
        'seo_paged_noindex'      => ['bool', 'Listelerin 2. ve sonraki sayfaları noindex', 'Kapatırsanız arşiv sayfaları da indekse açılır.'],
        'seo_same_as'            => ['textarea', 'Sosyal profil adresleri', 'Satır başına bir adres. Organization → sameAs alanına yazılır (bilgi paneli için). Yalnız http/https kabul edilir, en çok 12 adres.'],
        'seo_verify_google'      => ['text', 'Google Search Console doğrulama kodu', 'Yalnız kodun kendisi (meta etiketinin content değeri).'],
        'seo_verify_bing'        => ['text', 'Bing Webmaster doğrulama kodu', ''],
        'seo_verify_yandex'      => ['text', 'Yandex Webmaster doğrulama kodu', ''],
        'seo_robots_extra'       => ['textarea', 'robots.txt ek satırları', 'Dosyanın sonuna olduğu gibi eklenir.'],

        // ---- site haritası
        'seo_sitemap_images'     => ['bool', 'Site haritasına görselleri ekle', 'Google Görseller ve Keşfet için image:image düğümü basılır.'],
        'seo_slug_sync'          => ['bool', 'Site haritası isteğinde slug geçmişini eşitle', 'Cron kuramayan sunucular için. Açıksa her harita isteği veritabanına yazar.'],

        // ---- beslemeler
        'seo_feed_full'          => ['bool', 'Beslemelerde tam metin', 'Kapalıysa yalnız spot/özet yayımlanır. İçerik kazıyıcılara karşı kapatılabilir; kilitli haberlerde gövde zaten hiç basılmaz.'],
        'seo_feed_items'         => ['number', 'Besleme öğe sayısı', '1–50 arası.'],
        'seo_feed_ttl'           => ['number', 'Besleme / harita önbellek süresi (sn)', '0–86400.'],
        'seo_websub_hub'         => ['text', 'WebSub hub adresi', 'Boş bırakılırsa bildirim gönderilmez. Örnek bir açık hub: https://pubsubhubbub.appspot.com/'],

        // ---- arama motoru bildirimi
        'seo_indexnow_enabled'   => ['bool', 'IndexNow bildirimi', 'Yayımlanan haberler Bing/Yandex/Naver ağına bildirilir. Bildirim CRON turunda gönderilir, yayın anında değil.'],
        'seo_indexnow_key'       => ['text', 'IndexNow anahtarı', 'Boş bırakırsanız otomatik üretilir. Anahtar dosyası site kökünden sunulur.'],
        'seo_sitemap_ping'       => ['bool', 'Google site haritası ping', 'Google bu ucu kullanımdan kaldırdığını duyurdu; kapalı gelir. Açarsanız her yayında sitemap adresi bildirilir.'],
    ],

    /**
     * Sınır düzeltmeleri. `$saved` TÜM grupların değerlerini taşır; yalnız
     * kendi anahtarlarımıza dokunuruz.
     */
    'clamp' => function (array $saved) {
        if (isset($saved['seo_feed_items'])) {
            $saved['seo_feed_items'] = (string)max(1, min(50, (int)$saved['seo_feed_items']));
        }
        if (isset($saved['seo_feed_ttl'])) {
            $saved['seo_feed_ttl'] = (string)max(0, min(86400, (int)$saved['seo_feed_ttl']));
        }
        // Anahtar boş ya da biçimsizse yeniden üretilir: IndexNow anahtarı
        // hem gövdede hem kökteki dosyada aynı olmak zorundadır.
        if (isset($saved['seo_indexnow_key'])) {
            $k = strtolower(trim((string)$saved['seo_indexnow_key']));
            $saved['seo_indexnow_key'] = preg_match('/^[a-f0-9]{16,64}$/', $k) ? $k : bin2hex(random_bytes(16));
        }
        // WebSub hub yalnız http(s) olabilir.
        if (isset($saved['seo_websub_hub'])) {
            $u = trim((string)$saved['seo_websub_hub']);
            $saved['seo_websub_hub'] = preg_match('#^https?://[^\s<>"\']+$#i', $u) ? $u : '';
        }
        return $saved;
    },
];
