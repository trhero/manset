<?php
/**
 * Manşet — gömme ve taslak önizleme uçları (1.3-03, Ajan-G).
 *
 *   embed.resolve    posts.create   adresi statik gömme HTML'ine çevirir
 *   preview.create   posts.create   taslak için imzalı/süreli önizleme bağlantısı
 *   preview.revoke   posts.create   haberin etkin önizleme bağlantılarını geri çeker
 *
 * -----------------------------------------------------------------------------
 * NEDEN `posts.create` KAPISI
 * -----------------------------------------------------------------------------
 * Üçü de EDİTÖR ARACIDIR: haber yazan kişi kullanır. `embed.resolve` ayrıca
 * SUNUCUDAN DIŞARI İSTEK DOĞURUR (oEmbed), yani yetkisiz bırakılırsa siteyi
 * üçüncü taraflara istek atan bir aracıya çevirir. `preview.create` ise
 * YAYIMLANMAMIŞ METNİ herkese açan bir adres üretir — bu, yetki atlatma
 * yüzeyidir ve haber yazma yetkisinden aşağı bir kapıya konamaz.
 *
 * YENİ İZİN EKLENMEDİ (1.3 sözleşmesi §4).
 *
 * -----------------------------------------------------------------------------
 * HIZ SINIRI
 * -----------------------------------------------------------------------------
 * `embed.resolve` dış istek üretir (bant genişliği + sağlayıcı kotası),
 * `preview.create` belirteç basar. İkisi de sayaçlıdır ve sayaç KARARDAN ÖNCE
 * yazılır (CONTRACTS §3.2). Kova anahtarı KULLANICI kimliğidir: üçü de oturum
 * ister, yani IP paylaşan bir yazı işleri odasında tek kişinin taşkınlığı
 * bütün masayı kilitlemez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/sanitize.php';
if (is_file(INC_DIR . '/embed.php')) { require_once INC_DIR . '/embed.php'; }

/** Uçların ortak modül denetimi. */
function embed_api_ready() {
    if (!function_exists('embed_resolve')) {
        json_err('Gömme modülü kurulu değil (inc/embed.php).', 503);
    }
}

/**
 * Önizleme bağlantısı üretilecek haberi yükler ve IDOR denetimi yapar.
 *
 * Kendi kapımı yazıyorum, `api_admin_load_post()`'u çağırmıyorum: o işlev
 * "yayımlanmış haberi değiştiremezsiniz" kapısını da uygular ve önizleme
 * DEĞİŞTİRME değildir. Buradaki kural daha dar ve daha açıktır:
 *   • `posts.edit_any` yoksa yalnız KENDİ haberin,
 *   • YAYIMLANMIŞ habere önizleme bağlantısı ÜRETİLMEZ — o adres zaten
 *     herkese açıktır; süreli bir belirteç basmak yalnız gereksiz bir
 *     kimlik doğrulamasız yüzey açardı.
 */
function embed_api_post_for_preview($id, array $user) {
    $id = (int)$id;
    if ($id <= 0) { json_err('Haber seçilmedi.'); }
    $post = q1('SELECT id, title, status, author_id FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    if (!can($user, 'posts.edit_any') && (int)arr($post, 'author_id', 0) !== (int)$user['id']) {
        json_err('Yalnız kendi haberlerinizin önizlemesini paylaşabilirsiniz.', 403);
    }
    if ((string)arr($post, 'status', '') === 'published') {
        json_err('Haber zaten yayımda; önizleme bağlantısı gerekmez.');
    }
    return $post;
}

api_register('embed.resolve', function () {
    embed_api_ready();
    $u = current_user();
    // Sayaç KARARDAN ÖNCE yazılır (CONTRACTS §3.2).
    if (!rate_limit('embed:' . (int)$u['id'], 30, 300)) {
        json_err('Çok fazla gömme isteği. Biraz sonra tekrar deneyin.', 429);
    }

    $b = json_body();
    $url = trim((string)(array_key_exists('url', $b) ? $b['url'] : inp('url', '')));
    if ($url === '') { json_err('Gömme adresi boş.'); }
    if (mb_strlen($url) > 1000) { json_err('Gömme adresi çok uzun.'); }

    $tazele = array_key_exists('refresh', $b) ? (int)$b['refresh'] === 1 : (inp('refresh', '0') === '1');

    $sonuc = embed_resolve($url, $tazele);
    if (empty($sonuc['ok'])) {
        json_err((string)arr($sonuc, 'error', 'Gömme çözümlenemedi.'));
    }
    return [
        'provider' => (string)$sonuc['provider'],
        'html'     => (string)$sonuc['html'],
        'title'    => (string)$sonuc['title'],
        'cached'   => !empty($sonuc['cached']),
    ];
}, ['perm' => 'posts.create', 'methods' => ['POST']]);

api_register('preview.create', function () {
    embed_api_ready();
    $u = current_user();
    if (!rate_limit('preview:' . (int)$u['id'], 20, 900)) {
        json_err('Çok fazla önizleme bağlantısı istendi. Biraz sonra tekrar deneyin.', 429);
    }

    $b = json_body();
    $id = (int)(array_key_exists('id', $b) ? $b['id'] : inp_i('id', 0));
    $post = embed_api_post_for_preview($id, $u);

    $saat = (int)(array_key_exists('hours', $b) ? $b['hours'] : inp_i('hours', 0));
    $sonuc = embed_preview_create((int)$post['id'], (int)$u['id'], $saat);
    if (empty($sonuc['ok'])) { json_err((string)arr($sonuc, 'error', 'Bağlantı üretilemedi.')); }

    // Denetim izi: yayımlanmamış metni dışarı açan bir işlemdir.
    if (function_exists('api_admin_audit')) {
        api_admin_audit($u, 'preview.create', 'post#' . (int)$post['id'],
                        'Önizleme bağlantısı üretildi (' . (int)$sonuc['hours'] . ' saat).');
    }

    return [
        'url'        => (string)$sonuc['url'],
        'expires_at' => (string)$sonuc['expires_at'],
        'hours'      => (int)$sonuc['hours'],
        // HAM BELİRTEÇ YALNIZ BURADA GÖRÜNÜR: veritabanında özeti var,
        // yeniden gösterilemez.
        'note'       => 'Bu adres bir kez gösterilir; kopyalayın. Süresi dolunca çalışmaz.',
    ];
}, ['perm' => 'posts.create', 'methods' => ['POST']]);

api_register('preview.revoke', function () {
    embed_api_ready();
    $u = current_user();
    $b = json_body();
    $id = (int)(array_key_exists('id', $b) ? $b['id'] : inp_i('id', 0));
    if ($id <= 0) { json_err('Haber seçilmedi.'); }
    $post = q1('SELECT id, status, author_id FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    if (!can($u, 'posts.edit_any') && (int)arr($post, 'author_id', 0) !== (int)$u['id']) {
        json_err('Yalnız kendi haberlerinizin bağlantılarını geri çekebilirsiniz.', 403);
    }

    $n = embed_preview_revoke_post($id);
    if (function_exists('api_admin_audit')) {
        api_admin_audit($u, 'preview.revoke', 'post#' . $id, $n . ' bağlantı geri çekildi.');
    }
    return ['revoked' => $n, 'message' => $n . ' önizleme bağlantısı geri çekildi.'];
}, ['perm' => 'posts.create', 'methods' => ['POST']]);
