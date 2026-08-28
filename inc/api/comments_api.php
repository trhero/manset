<?php
/**
 * Manşet — yorum uçları (1.3-07, Ajan-B).
 *
 * Kamuya açık:
 *   comments.submit            → yorum / yanıt gönderimi (CSRF + hız sınırı)
 *   comments.report            → uygunsuz yorum bildirimi (CSRF YOK — aşağıya bak)
 *
 * Panel (izin: comments.moderate):
 *   comments.suspend           → "spam + askıya al" birleşik eylemi
 *   comments.blacklist_add     → kara listeye desen ekle
 *   comments.blacklist_remove  → kara listeden desen sil
 *   comments.reports_clear     → bildirim sayacını sıfırla ("incelendi")
 *
 * -----------------------------------------------------------------------------
 * NEDEN AYRI BİR GÖNDERİM UCU (comments.submit)
 * -----------------------------------------------------------------------------
 * `public.comment` Ajan-6'nın dosyasındadır (inc/api/public.php) ve bu iş
 * paketinin dosya sahipliği dışındadır. Aynı ucu buradan ikinci kez kaydetmek
 * teknik olarak mümkündü ama YANLIŞ olurdu: `api.php` dosyaları alfabetik
 * yükler ve `api_register()` sessizce üzerine yazar — hangi sürümün geçerli
 * olduğu yalnız dosya adına kalırdı. `probe dup_api` tam bunu arıyor
 * (CONTRACTS §7). Bu yüzden yeni yol yeni adla açıldı; eski uç dokunulmadan
 * çalışmaya devam ediyor.
 *
 * Eski uçtan gelen gönderimler `parent_id` ve `author_role` taşımaz ve kara
 * listeyi bilmez. Bu boşluk iki yerden kapatılıyor: temalar artık yeni uca
 * gönderiyor, ve `comments_cron_blacklist_sweep()` her cron turunda eski
 * yoldan girmiş kara liste eşleşmelerini spam'e çeviriyor.
 * Kalıcı çözüm için orkestratörden istek raporda.
 *
 * -----------------------------------------------------------------------------
 * NEDEN comments.report CSRF İSTEMİYOR
 * -----------------------------------------------------------------------------
 * CSRF anahtarı `public.csrf` ucundan alınır ve o uç `csrf_token()` çağırdığı
 * için OTURUM AÇAR. Oturum açan okur sayfa önbelleğinden bir daha yararlanamaz
 * (CONTRACTS §3.1). Bir "bildir" düğmesine dokunmanın bedeli, okurun geri
 * kalan gezintisinin önbelleksiz kalması olamaz.
 *
 * Aynı ödünç `public.view` ucunda da verilmişti ve NOTES.md'de kayıtlıdır.
 * Karşılığında uç DAR tutuldu:
 *   · yalnız bir sayacı artırır, hiçbir yorumu yayından kaldırmaz
 *     (otomatik eşik varsayılan olarak KAPALI),
 *   · aynı kişi aynı yorumu bir kez sayabilir (benzersiz indeks),
 *   · IP başına 10/300 sn.
 * Yani kötüye kullanımın en fazla etkisi, moderatörün kuyruğunda gereksiz bir
 * satır görmesidir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

/** İstek gövdesinden alan okur (JSON önce, klasik form gönderimi yedek). */
function comments_api_field($key, $default = '') {
    $body = json_body();
    if (array_key_exists($key, $body)) { return $body[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    return $default;
}

/** Modül yüklenmemişse (dosya silinmiş) uçlar sessizce kapanır. */
if (!function_exists('comments_submit_handle')) { return; }

// ============================================================ comments.submit

/**
 * İstek : {post_id:int, parent_id:int, name, email, body, kvkk:1,
 *          website:'' (honeypot), _csrf}
 * Yanıt : {ok:true, message:string}
 * Hata  : 400 doğrulama · 401 oturum gerek · 403 yetki · 404 haber · 429 hız
 */
api_register('comments.submit', function () {
    $r = comments_submit_handle('comments_api_field');
    return ['message' => (string)$r['message']];
}, ['perm' => 'public', 'methods' => ['POST']]);

// ============================================================ comments.report

/**
 * İstek : {id:int, reason:'hakaret'|'spam'|'alakasiz'|'diger'}
 * Yanıt : {ok:true, message:string}
 */
api_register('comments.report', function () {
    return comments_report_handle(
        (int)comments_api_field('id', 0),
        (string)comments_api_field('reason', 'diger')
    );
}, ['perm' => 'public', 'methods' => ['POST'], 'csrf' => false]);

// ============================================================ comments.suspend

/**
 * "Spam + askıya al". Tek yorum ya da toplu.
 * İstek : {ids:[int]|id:int, ip:bool (IP kuralı da eklensin mi, varsayılan true)}
 * Yanıt : {ok:true, count:int, message:string}
 */
api_register('comments.suspend', function () {
    $d = json_body();
    $ids = arr($d, 'ids', null);
    if (!is_array($ids)) { $ids = [(int)arr($d, 'id', 0)]; }
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) { json_err('Hiç yorum seçilmedi.', 400); }
    if (count($ids) > 100) { json_err('Tek seferde en çok 100 yorum askıya alınabilir.', 400); }

    // IP kuralı isteğe bağlı: paylaşılan bir çıkış IP'si (okul, kurum, mobil
    // operatör NAT) yüzlerce masum okuru birden susturabilir. Karar moderatörün.
    $ipDe = !array_key_exists('ip', $d) || !empty($d['ip']);

    $u = current_user();
    $sayi = 0;
    $kural = [];
    foreach ($ids as $id) {
        $r = comments_spam_and_suspend($id, (int)arr((array)$u, 'id', 0), $ipDe);
        if (empty($r['ok'])) { continue; }
        $sayi++;
        foreach ($r['kurallar'] as $k) { $kural[$k] = true; }
    }
    if (!$sayi) { json_err('Yorum bulunamadı.', 404); }

    if (function_exists('api_admin_audit') && $u) {
        api_admin_audit($u, 'comment.suspend', implode(',', array_slice($ids, 0, 20)),
            $sayi . ' yorum spam + kara liste (' . implode('/', array_keys($kural)) . ')');
    }
    $ek = $kural ? ' Kara listeye eklendi: ' . implode(', ', array_keys($kural)) . '.' : '';
    return ['count' => $sayi, 'message' => $sayi . ' yorum spam yapıldı.' . $ek];
}, ['perm' => 'comments.moderate', 'methods' => ['POST']]);

// ============================================================ comments.blacklist_add

/**
 * İstek : {kind:'email'|'ip'|'word', value:string, note:string}
 * Yanıt : {ok:true, id:int, message:string}
 */
api_register('comments.blacklist_add', function () {
    $d = json_body();
    $u = current_user();
    $r = comments_blacklist_add(
        (string)arr($d, 'kind', ''),
        (string)arr($d, 'value', ''),
        (string)arr($d, 'note', ''),
        (int)arr((array)$u, 'id', 0)
    );
    if (empty($r['ok'])) { json_err((string)$r['error'], 400); }
    if (function_exists('api_admin_audit') && $u) {
        api_admin_audit($u, 'comment.blacklist_add', (string)arr($d, 'kind', ''), (string)arr($d, 'value', ''));
    }
    return ['id' => (int)$r['id'], 'message' => 'Kara liste kuralı eklendi.'];
}, ['perm' => 'comments.moderate', 'methods' => ['POST']]);

// ============================================================ comments.blacklist_remove

/** İstek : {id:int} */
api_register('comments.blacklist_remove', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if (!comments_blacklist_remove($id)) { json_err('Kural bulunamadı.', 404); }
    $u = current_user();
    if (function_exists('api_admin_audit') && $u) {
        api_admin_audit($u, 'comment.blacklist_remove', (string)$id, '');
    }
    return ['message' => 'Kural kaldırıldı.'];
}, ['perm' => 'comments.moderate', 'methods' => ['POST']]);

// ============================================================ comments.reports_clear

/** İstek : {id:int} — bildirim sayacını sıfırlar, yorumun durumuna dokunmaz. */
api_register('comments.reports_clear', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if (!comments_reports_clear($id)) { json_err('Yorum bulunamadı.', 404); }
    return ['message' => 'Bildirimler temizlendi.'];
}, ['perm' => 'comments.moderate', 'methods' => ['POST']]);
