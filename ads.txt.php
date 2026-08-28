<?php
/**
 * Manşet — ads.txt üreticisi (1.2-03). Ajan-B.
 *
 * ads.txt, yayıncının hangi reklam satıcılarına envanterini satma yetkisi
 * verdiğini bildiren düz metin bir dosyadır ve SİTE KÖKÜNDEN sunulmalıdır.
 * İçerik panelden düzenlenir (Panel → Reklamlar → ads.txt), burada basılır.
 *
 * ---------------------------------------------------------------------------
 * /ads.txt ADRESİ NASIL ÇALIŞIR — ÜÇ YOL, ÖNCELİK SIRASIYLA
 * ---------------------------------------------------------------------------
 * 1) Kökte GERÇEK bir `ads.txt` dosyası varsa Apache onu doğrudan sunar; bu
 *    dosya hiç çalışmaz. Panelde "Kök dizine yaz" düğmesi bunu üretir.
 *    URL yeniden yazma KAPALI hostinglerde çalışan tek yol budur.
 * 2) `index.php` yönlendiricisi `ads.txt` yolunu bu dosyaya bağlarsa
 *    (orkestratörden istendi, bkz. gelistirme/1.2-ajan-b.md) — istek
 *    .htaccess üzerinden index.php'ye düştüğü için ek kural gerekmez.
 * 3) Doğrudan `/ads.txt.php` adresi her zaman çalışır.
 *
 * Bu dosya index.php içinden `require` EDİLEBİLİR olmalıdır: bootstrap zaten
 * yüklüyse yeniden yüklenmez, çıktı basılıp `exit` edilir.
 */

if (!defined('MANSET_BOOTSTRAPPED')) {
    require_once __DIR__ . '/inc/bootstrap.php';
}
if (!MANSET_INSTALLED) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# Kurulum tamamlanmamis.\n";
    exit;
}
require_once __DIR__ . '/inc/ads.php';

$icerik = ads_txt_clean(ads_txt_content());

// Boş ads.txt yayımlamak, "hiçbir satıcı yetkili değil" demek DEĞİLDİR; tarayıcı
// için anlamsız bir dosyadır. İçerik girilmemişse 404 doğru yanıttır — yayıncı
// dosyayı hiç yayımlamamış sayılır.
if ($icerik === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "# ads.txt tanimlanmamis.\n";
    exit;
}

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
// Reklam doğrulayıcıları dosyayı günde birkaç kez çeker; bir saatlik önbellek
// hem yükü düşürür hem değişikliği makul sürede yayar.
header('Cache-Control: public, max-age=3600');
echo $icerik . "\n";
exit;
