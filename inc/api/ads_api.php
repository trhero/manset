<?php
/**
 * Manşet — Reklam v2 uç noktaları (1.2-03). Ajan-B.
 *
 * Kayıtlı uçlar:
 *   ads.beacon      public POST   gösterim sayacı (çerezsiz)
 *   ads.click       public GET    tıklama sayacı + kendi hedefine yönlendirme
 *   ads.stats       ads.schedule  sayaç raporu
 *   ads.sponsored   ads.schedule  sponsorlu içerik bayrağı
 *   ads.txt_save    ads.schedule  ads.txt gövdesini kaydet
 *   ads.txt_static  ads.schedule  kökteki statik ads.txt dosyasını yaz/sil
 *
 * NOT (ad alanı): `ads.*` öneki CONTRACTS §7'de Ajan-3'e ait. 1.2'de reklam
 * paneli Ajan-B'ye devredildiği için yeni eylemler buraya yazıldı; Ajan-3'ün
 * mevcut eylem adlarının (list/save/delete/toggle/approve) hiçbiri KULLANILMADI,
 * çakışma yok. Sözleşme güncellemesi orkestratörden istendi (rapor §İstekler).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/ads.php';

// ============================================================ yardımcı

/** Gövde alanını (JSON ya da form) okur. */
function ads_api_field($key, $default = '') {
    $b = json_body();
    if (array_key_exists($key, $b)) { return $b[$key]; }
    return inp($key, $default);
}

// ============================================================ kamuya açık uçlar

/**
 * Gösterim beacon'ı.
 *
 * ÇEREZ VERMEZ (CONTRACTS §3.1): `perm => 'public'` olduğu için api.php
 * `current_user()` çağırmaz; bu gövde de session_boot/csrf_token çağırmaz.
 * İstemci ayrıca `credentials:'omit'` gönderir.
 *
 * Tekilleştirme oturumsuz yapılamaz; bu yüzden IP başına hız sınırı vardır.
 * Sınır AŞILDIĞINDA hata dönülmez — sayaç kritik değildir, sessizce sayılmaz;
 * istemciye 429 dönmek konsolu kırmızıya boyar ve hiçbir şey kazandırmaz.
 */
api_register('ads.beacon', function () {
    $d = json_body();
    $ham = isset($d['ids']) ? $d['ids'] : ads_api_field('ids', '');
    if (is_string($ham)) { $ham = explode(',', $ham); }
    if (!is_array($ham)) { return ['ok' => true, 'counted' => 0]; }
    if (count($ham) > 20) { $ham = array_slice($ham, 0, 20); }

    // Dakikada en çok 30 beacon: normal bir okuyucu sayfa başına 1 tane atar.
    if (!rate_limit('adsview:' . client_ip(), 30, 60)) { return ['ok' => true, 'counted' => 0]; }

    return ['ok' => true, 'counted' => ads_record_impressions($ham)];
}, ['perm' => 'public', 'methods' => ['POST'], 'csrf' => false]);

/**
 * Tıklama sayacı ve yönlendirme.
 *
 * AÇIK YÖNLENDİRME KALKANI: hedef adres YALNIZ `ads.link_url` sütunundan
 * okunur. İstekte adres taşıyan hiçbir parametre kabul edilmez — `id` dışında
 * hiçbir girdi okunmaz. Kayıt yoksa, görsel reklam değilse ya da adres
 * doğrulamayı geçmiyorsa anasayfaya dönülür (dış adrese ASLA).
 *
 * Yanıt önbelleğe alınmaz: sayaç her tıklamada artmalı.
 */
api_register('ads.click', function () {
    $id = (int)ads_api_field('id', 0);

    $hedef = '';
    if ($id > 0 && rate_limit('adclick:' . client_ip(), 60, 60)) {
        $hedef = ads_click_target($id);
    }
    if ($hedef === '') { $hedef = base_url() . '/'; }

    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Location: ' . $hedef, true, 302);
    exit;
}, ['perm' => 'public', 'methods' => ['GET'], 'csrf' => false]);

// ============================================================ panel uçları

/** Sayaç raporu. */
api_register('ads.stats', function () {
    $gun = (int)ads_api_field('days', 30);
    return [
        'days'    => max(1, min(365, $gun)),
        'summary' => ads_stats_summary($gun),
        'daily'   => ads_stats_daily($gun),
        'enabled' => ads_counting_enabled(),
    ];
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/**
 * Sponsorlu içerik bayrağı.
 *
 * Bu bayrak reklamın İÇERİĞİNİ değiştirmez, yalnız okuyucuya gösterilen
 * etiketi belirler; bu yüzden `ads.creative`/`ads.html` değil `ads.schedule`
 * yeterlidir — reklam yöneticisi kendi kaydını doğru etiketleyebilmeli.
 */
api_register('ads.sponsored', function () {
    $id = (int)ads_api_field('id', 0);
    $on = (int)ads_api_field('on', 0) === 1 ? 1 : 0;
    if ($id <= 0) { json_err('Reklam bulunamadı.'); }
    $row = q1('SELECT id FROM ads WHERE id = :i', [':i' => $id]);
    if (!$row) { json_err('Reklam bulunamadı.', 404); }

    db_update('ads', ['is_sponsored' => $on], 'id = :i', [':i' => $id]);
    if (function_exists('vitrin_audit')) { vitrin_audit('ads.sponsored', 'ad:' . $id, $on ? 'acildi' : 'kapatildi'); }
    if (function_exists('cache_flush')) { cache_flush(); }

    return ['id' => $id, 'is_sponsored' => $on, 'message' => $on ? 'Sponsorlu içerik olarak işaretlendi.' : 'Sponsorlu işareti kaldırıldı.'];
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/** ads.txt gövdesini kaydeder. */
api_register('ads.txt_save', function () {
    $metin = ads_txt_clean((string)ads_api_field('content', ''));
    setting_set('ads_txt', $metin);
    if (function_exists('vitrin_audit')) { vitrin_audit('ads.txt_save', 'ads.txt', ads_txt_line_count($metin) . ' satır'); }

    // Statik kopya varsa ve yayıncı onu kullanıyorsa güncel tutulur.
    $durum = ads_txt_static_state();
    if ($durum['var']) { @file_put_contents(ads_txt_static_path(), $metin === '' ? '' : $metin . "\n"); }

    return [
        'lines'   => ads_txt_line_count($metin),
        'bytes'   => strlen($metin),
        'static'  => ads_txt_static_state(),
        'message' => 'ads.txt kaydedildi.',
    ];
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/**
 * Kök dizine statik `ads.txt` dosyası yazar ya da siler.
 *
 * NEDEN GEREKLİ: `/ads.txt` adresi kök `.htaccess` ve `index.php` üzerinden
 * yönlendirilir; ikisi de orkestratörün dosyası. Yönlendirme eklenene kadar
 * (ve URL yeniden yazma kapalı hostinglerde her zaman) tek çalışan yol,
 * gerçekten var olan bir dosyadır. Dosya PANELDEN, açık bir düğmeyle yazılır;
 * kendiliğinden oluşmaz.
 */
api_register('ads.txt_static', function () {
    $sil = (int)ads_api_field('remove', 0) === 1;
    $yol = ads_txt_static_path();

    if ($sil) {
        if (is_file($yol) && !@unlink($yol)) { json_err('Dosya silinemedi: yazma izni yok.'); }
        return ['static' => ads_txt_static_state(), 'message' => 'Statik ads.txt silindi.'];
    }

    $metin = ads_txt_clean(ads_txt_content());
    if ($metin === '') { json_err('Önce ads.txt içeriğini girin.'); }
    if (@file_put_contents($yol, $metin . "\n") === false) {
        json_err('Dosya yazılamadı. Sunucuda kök dizin yazılabilir değil; ads.txt.php yönlendirmesini kullanın.');
    }
    if (function_exists('vitrin_audit')) { vitrin_audit('ads.txt_static', 'ads.txt', 'statik dosya yazildi'); }
    return ['static' => ads_txt_static_state(), 'message' => 'Kök dizine ads.txt yazıldı.'];
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);
