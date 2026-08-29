<?php
/**
 * JSON API — ödeme uçları (1.2-04).
 *
 * İki uç vardır ve ikisi de bilinçli olarak dardır:
 *
 *  payment.webhook   Sağlayıcı geri bildirimi. CSRF YOKTUR — istek dış
 *                    sistemden gelir, tarayıcı oturumu taşımaz. Bu yüzden
 *                    TEK savunma imza doğrulamasıdır (`payment_paytr_verify`,
 *                    `hash_equals`). Oturum AÇILMAZ, çerez verilmez.
 *                    Yanıt gövdesi sağlayıcının beklediği düz metindir.
 *
 *  payment.receipt   Dekont görüntüleme. `payments.manage` (KİLİTLİ, yalnız
 *                    admin) izniyle korunur; dosya adı yalnız 32 haneli
 *                    onaltılık desene uyuyorsa açılır (yol geçişi kapalı).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/payment.php';

/**
 * Sağlayıcı geri bildirimi.
 *
 * Dönüş `json_out` DEĞİLDİR: sağlayıcılar düz metin bekler (PayTR gövdede
 * yalnız `OK` görmek ister; başka bir şey görürse bildirimi tekrar gönderir).
 */
api_register('payment.webhook', function () {
    $saglayici = (string)(isset($_GET['p']) ? $_GET['p'] : '');
    // Gövde form kodlu gelir; JSON gönderen sağlayıcılar için yedek okuma.
    $veri = $_POST;
    if (!$veri) {
        $j = json_body();
        if ($j) { $veri = $j; }
    }
    $r = payment_webhook_handle($saglayici, (array)$veri);

    if (!headers_sent()) {
        http_response_code((int)$r['code']);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }
    echo (string)$r['body'];
    exit;
}, ['perm' => 'public', 'methods' => ['POST'], 'csrf' => false]);

/** Dekont dosyasını panele akıtır (yalnız payments.manage). */
api_register('payment.receipt', function () {
    $id = inp_i('id', 0);
    $odeme = payment_by_id($id);
    if (!$odeme) { json_err('Kayıt bulunamadı.', 404); }

    $yol = payment_receipt_path((string)$odeme['receipt_file']);
    if ($yol === '') { json_err('Dekont bulunamadı.', 404); }

    $uzanti = strtolower((string)pathinfo($yol, PATHINFO_EXTENSION));
    $turler = payment_receipt_types();
    $mime = isset($turler[$uzanti]) ? $turler[$uzanti] : 'application/octet-stream';

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($yol));
        // Tarayıcıda gömülü çalıştırma yüzeyini kapat.
        header('Content-Disposition: inline; filename="dekont-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$odeme['ref']) . '.' . $uzanti . '"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
        header('Cache-Control: private, no-store');
    }
    file_stream_out($yol);
    exit;
}, ['perm' => 'payments.manage', 'methods' => ['GET']]);
