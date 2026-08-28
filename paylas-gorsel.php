<?php
/**
 * Manşet — paylaşım görseli (1.2-12). Ajan-B.
 *
 * Kullanım:  /paylas-gorsel.php?id=<haber id>
 * Çıktı:     1200×630 PNG — başlık + logo/site adı.
 *
 * KURALLAR
 * - ÇEREZ VERMEZ: bu dosya session_boot(), current_user(), csrf_token()
 *   çağırmaz. Ön yüz oturum kuralı (CONTRACTS §3.1) korunur.
 * - YALNIZ YAYINDAKİ haber: taslak/zamanlanmış/çöp kutusundaki haberin başlığı
 *   bu adresten sızmaz. (`post_by_id($id)` — $anyStatus vermeden.)
 * - Kilitli içerikte de yalnız BAŞLIK basılır; gövde hiç okunmaz, ödeme duvarı
 *   etkilenmez.
 * - GD yoksa 404 döner. Hata sayfası basmaz; çağıran taraf (panel) özelliği
 *   `share.image` ucundan sorup gizler.
 * - Hız sınırı vardır: görsel üretimi CPU harcar, açık bir uçtur.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) { http_response_code(503); exit; }

require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/media.php';
require_once __DIR__ . '/inc/share.php';

/** Görsel yerine düz bir durum kodu döndürür. */
function paylas_gorsel_dur($kod) {
    http_response_code((int)$kod);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo "gorsel uretilemedi\n";
    exit;
}

if (!share_image_available()) { paylas_gorsel_dur(404); }

$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
if ($id <= 0) { paylas_gorsel_dur(400); }

// Dakikada 30 görsel: bir editörün önizlemesi için fazlasıyla yeterli,
// döngüye sokup sunucuyu ısıtmak için yetersiz.
if (!rate_limit('shareimg:' . client_ip(), 30, 60)) { paylas_gorsel_dur(429); }

$post = post_by_id($id);            // $anyStatus YOK — yalnız yayındaki haber
if (!$post) { paylas_gorsel_dur(404); }

$img = share_image_render($post);
if (!$img) { paylas_gorsel_dur(404); }

// Görsel yalnız başlıktan ve site kimliğinden türer; kişiye özel hiçbir şey yok.
// Bu yüzden paylaşılabilir önbellek güvenlidir.
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
share_image_output($img);
