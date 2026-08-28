<?php
/**
 * Manşet — bülten uçları (Ajan-F, 1.2-11). Ad alanı: newsletter.*
 *
 *   newsletter.subscribe   (public,  POST) çift onaylı kayıt — onay postası gider
 *   newsletter.list        (newsletter.manage, POST/GET) süzgeçli abone listesi
 *   newsletter.export      (newsletter.manage, GET) Mailchimp/Sendy/ham CSV
 *   newsletter.delete      (newsletter.manage, POST) tek abone silme (KVKK talebi)
 *   newsletter.segment     (newsletter.manage, POST) abonenin kategori ilgisi
 *
 * ---------------------------------------------------------------------------
 * BİLEREK EZİLEN İKİ UÇ
 * `inc/api/kunye_api.php` (Ajan-9) `newsletter.subscribe` ve `newsletter.export`
 * uçlarını 1.1'de kaydetmişti. api.php inc/api/*.php dosyalarını GLOB SIRASIYLA
 * yükler ve api_register() aynı anahtarı ÜZERİNE yazar; `kunye_api.php` <
 * `newsletter_api.php` olduğu için buradaki tanımlar geçerli olur. Başkasının
 * dosyasına dokunmadan (1.2 sözleşmesi §6) davranışı düzeltmenin yolu budur.
 *
 * Neden ezilmeleri gerekti:
 *   · subscribe → eski uç `confirmed = 0` yazıp bırakıyordu; onay akışı yoktu.
 *   · export    → eski uç `settings.manage` istiyordu (yalnız admin) ve denetim
 *                 kaydı tutmuyordu. KVKK'da listeyi kimin ne zaman indirdiği
 *                 bilinmelidir; izin de `newsletter.manage` olmalıdır.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (is_file(INC_DIR . '/newsletter.php')) { require_once INC_DIR . '/newsletter.php'; }

/** JSON gövdesi ya da form alanı (kunye_api_field ile aynı davranış). */
function newsletter_api_field($key, $default = '') {
    $body = json_body();
    if (is_array($body) && array_key_exists($key, $body)) { return $body[$key]; }
    return inp($key, $default);
}

/** Süzgeç dizisi (liste ve dışa aktarma aynı süzgeci kullanır). */
function newsletter_api_filter() {
    $durum = (string)newsletter_api_field('durum', 'hepsi');
    if (!in_array($durum, ['hepsi', 'onayli', 'bekleyen'], true)) { $durum = 'hepsi'; }
    return [
        'durum'    => $durum,
        'kategori' => (int)newsletter_api_field('kategori', 0),
        'q'        => sanitize_line((string)newsletter_api_field('q', ''), 120),
    ];
}

// ================================================================ subscribe
/**
 * İstek : {email:string, website:string (honeypot), kategoriler:int[], _csrf}
 * Yanıt : {ok:true, message:'Kaydınız alındı. Onay bağlantısını…'}
 *
 * Varlık sızdırılmaz: kayıtlı/onaylı adres için de aynı mesaj döner.
 * Ham onay token'ı yanıtta ASLA yer almaz (yoksa e-postaya gerek kalmaz,
 * çift onay anlamını yitirirdi).
 */
api_register('newsletter.subscribe', function () {
    $genelOk = ['message' => 'Kaydınız alındı. Onay bağlantısını e-postanıza gönderdik; '
                           . 'bağlantıya tıklayana kadar listeye eklenmezsiniz.'];

    // CONTRACTS §3.1 zorunlu kova
    if (!rate_limit('newsletter:' . client_ip(), 3, 900)) {
        json_err('Çok sık deneme yapıldı. Lütfen bir süre sonra tekrar deneyin.', 429);
    }
    if (trim((string)newsletter_api_field('website', '')) !== '') { return $genelOk; }   // honeypot

    if (!function_exists('newsletter_subscribe')) {
        json_err('Bülten listesi bu kurulumda hazır değil.', 503);
    }

    $res = newsletter_subscribe([
        'email'      => newsletter_api_field('email', ''),
        'categories' => newsletter_api_field('kategoriler', []),
        'source'     => 'form',
    ]);
    if (!$res['ok']) { json_err($res['error'], (int)$res['code']); }
    return ['message' => $res['message']];
}, ['perm' => 'public', 'methods' => ['POST']]);

// ================================================================ list
api_register('newsletter.list', function () {
    if (!function_exists('newsletter_query')) { return json_err('Bülten modülü yüklü değil.', 500); }
    $f = newsletter_api_filter();
    $f['limit']  = (int)newsletter_api_field('limit', 50);
    $f['offset'] = (int)newsletter_api_field('offset', 0);
    $res = newsletter_query($f);
    $items = [];
    foreach ($res['items'] as $r) {
        $items[] = [
            'id'         => (int)$r['id'],
            'email'      => (string)$r['email'],
            'confirmed'  => (int)$r['confirmed'],
            'confirmed_at' => (string)arr($r, 'confirmed_at', ''),
            'categories' => array_values(newsletter_categories_labels(arr($r, 'categories', ''))),
            'source'     => (string)arr($r, 'source', ''),
            'created_at' => (string)$r['created_at'],
        ];
    }
    return ['items' => $items, 'total' => $res['total'], 'stats' => newsletter_stats()];
}, ['perm' => 'newsletter.manage', 'methods' => ['POST', 'GET']]);

// ================================================================ export
/**
 * Abone listesi CSV indirmesi. Kendi çıktısını verir (json_out kullanılmaz).
 * GET olduğu için dağıtıcı CSRF aramaz; izin denetimi yapılır.
 *
 * KVKK: her indirme `audit_log`'a yazılır — kimin, ne zaman, hangi süzgeçle
 * ve kaç satır indirdiği bilinir.
 */
api_register('newsletter.export', function () {
    if (!function_exists('newsletter_export_rows')) { return json_err('Bülten modülü yüklü değil.', 500); }

    $format = (string)inp_s('bicim', 'ham');
    if (!isset(newsletter_export_formats()[$format])) { $format = 'ham'; }

    $f = newsletter_api_filter();
    $rows = newsletter_export_rows($f);

    $user = current_user();
    if ($user && function_exists('api_admin_audit')) {
        api_admin_audit($user, 'newsletter.export', 'bicim:' . $format,
            count($rows) . ' satır · süzgeç: durum=' . $f['durum']
            . ' kategori=' . (int)$f['kategori'] . ' arama=' . ($f['q'] !== '' ? 'var' : 'yok'));
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . newsletter_export_filename($format) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $sep = newsletter_export_sep($format);
    echo "\xEF\xBB\xBF";                       // Excel'in UTF-8 tanıması için BOM
    $fh = fopen('php://output', 'w');
    fputcsv($fh, newsletter_export_header($format), $sep);
    foreach ($rows as $r) { fputcsv($fh, newsletter_export_row($r, $format), $sep); }
    fclose($fh);
}, ['perm' => 'newsletter.manage', 'methods' => ['GET']]);

// ================================================================ delete
api_register('newsletter.delete', function () {
    if (!function_exists('newsletter_delete')) { return json_err('Bülten modülü yüklü değil.', 500); }
    $id = (int)newsletter_api_field('id', 0);
    $email = newsletter_delete($id);
    if ($email === false) { return json_err('Abone bulunamadı.', 404); }

    $user = current_user();
    if ($user && function_exists('api_admin_audit')) {
        api_admin_audit($user, 'newsletter.delete', 'abone#' . $id, (string)$email);
    }
    return ['message' => 'Abone listeden silindi.'];
}, ['perm' => 'newsletter.manage', 'methods' => ['POST']]);

// ================================================================ segment
/** Abonenin kategori ilgisini panelden düzenler. */
api_register('newsletter.segment', function () {
    if (!function_exists('newsletter_categories_clean')) { return json_err('Bülten modülü yüklü değil.', 500); }
    $id = (int)newsletter_api_field('id', 0);
    if ($id <= 0) { return json_err('Abone seçilmedi.', 400); }
    if (!q1('SELECT id FROM newsletter_subscribers WHERE id = :i', [':i' => $id])) {
        return json_err('Abone bulunamadı.', 404);
    }
    $cats = newsletter_categories_clean(newsletter_api_field('kategoriler', []));
    q('UPDATE newsletter_subscribers SET categories = :c, updated_at = :n WHERE id = :i',
      [':c' => $cats, ':n' => now(), ':i' => $id]);
    return ['message' => 'Kategori ilgisi güncellendi.',
            'categories' => array_values(newsletter_categories_labels($cats))];
}, ['perm' => 'newsletter.manage', 'methods' => ['POST']]);
