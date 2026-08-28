<?php
/**
 * Manşet — paylaşım uç noktaları (1.2-12). Ajan-B.
 *
 *   share.text   ai.use   X / Instagram / WhatsApp metinlerini üretir
 *   share.image  ai.use   paylaşım görselinin durumunu ve adresini bildirir
 *
 * OTOMATİK GÖNDERİM YOK (CONTRACTS §13). Uçlar hiçbir sosyal ağa istek atmaz;
 * yalnız kopyalanacak metni ve görselin adresini döndürür.
 *
 * İZİN: `ai.use`. Gerekçe — uç, yapılandırılmışsa gerçek bir YZ çağrısı yapar,
 * yani para harcar. Aynı kapı ai.suggest_title ile aynıdır. YZ kapalıyken uç
 * yine çalışır (şablon metinleri döner) ama kapı değişmez: kapıyı izne göre
 * gevşetmek, YZ'yi açtığı anda sessizce yetki genişletmek olurdu.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/ai.php';
require_once INC_DIR . '/share.php';

/** İstekten haber kaydını okur; bulunamazsa 404. */
function share_api_post() {
    $b = json_body();
    $id = (int)(array_key_exists('id', $b) ? $b['id'] : inp_i('id', 0));
    if ($id <= 0) { json_err('Haber seçilmedi.'); }
    $post = post_by_id($id, true);
    if (!$post) { json_err('Haber bulunamadı.', 404); }
    return $post;
}

api_register('share.text', function () {
    $post = share_api_post();

    $b = json_body();
    // `ai` alanı gönderilmemişse YZ denenir; 0 gönderilirse şablona zorlanır.
    $useAi = array_key_exists('ai', $b) ? (int)$b['ai'] === 1 : (inp('ai', '1') !== '0');

    $sonuc = share_texts($post, $useAi);
    $sonuc['post_id'] = (int)$post['id'];
    $sonuc['title']   = (string)$post['title'];
    return $sonuc;
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('share.image', function () {
    $post = share_api_post();
    list($w, $h) = share_image_size();
    return [
        'available' => share_image_available(),
        'url'       => share_image_url($post),
        'width'     => $w,
        'height'    => $h,
        'font'      => share_font_path() !== '' ? 'ttf' : 'gomulu',
        'note'      => share_image_available()
            ? (share_font_path() !== ''
                ? 'Görsel, ayarlarda tanımlı yazı tipiyle üretiliyor.'
                : 'TTF yazı tipi tanımlı değil; başlık GD\'nin gömülü yazı tipiyle basılır ve Türkçe harfler sadeleşir. '
                  . 'Ayarlar → Reklam altındaki "Paylaşım görseli yazı tipi" alanına uploads içindeki bir .ttf dosyasının '
                  . 'adını yazarak düzeltebilirsiniz.')
            : 'Sunucuda GD görüntü kütüphanesi yok; paylaşım görseli üretilemiyor.',
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);
