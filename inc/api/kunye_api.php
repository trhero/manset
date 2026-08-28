<?php
/**
 * Manşet — Künye/BİK, düzeltme-cevap, widget ve bülten uçları. (Ajan-9)
 * Ad alanı: kunye.* corrections.* widgets.* newsletter.*  (CONTRACTS.md §7)
 *
 * Kayıtlı uçlar:
 *   kunye.save            (settings.manage) künye alanlarını kaydeder
 *   corrections.list      (corrections.triage)  talep listesi + sayaçlar
 *   corrections.publish   (corrections.publish) düzeltme/cevap metnini yayımlar
 *   corrections.reject    (corrections.triage)  talebi reddeder (dâhilî not)
 *   corrections.delete    (corrections.triage)  talebi siler
 *   corrections.settings  (settings.manage) yasal süre eşiği + bildirim ayarı
 *   corrections.update_add    (posts.edit_any) habere editoryal güncelleme notu ekler
 *   corrections.update_delete (posts.edit_any) güncelleme notunu siler
 *   widgets.save          (settings.manage) widget ayarları
 *   widgets.test          (settings.manage) harici JSON adresini çeker ve gösterir
 *   widgets.clear_cache   (settings.manage) widget önbelleğini temizler
 *   newsletter.export     (settings.manage, GET) abone listesi CSV
 *   public.correction     (public) ön yüz düzeltme talebi formu
 *   newsletter.subscribe  (public) bülten abone kaydı (gönderim YOK)
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once dirname(__DIR__) . '/kunye.php';
require_once dirname(__DIR__) . '/widgets.php';

/** İstek gövdesinden alan okur: önce JSON gövdesi, sonra klasik form gönderimi. */
function kunye_api_field($key, $default = '') {
    $body = json_body();
    if (array_key_exists($key, $body)) { return $body[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    if (isset($_GET[$key])) { return $_GET[$key]; }
    return $default;
}

/** Değeri diziye çevirir (JSON metni de kabul edilir). */
function kunye_api_array($value) {
    if (is_array($value)) { return $value; }
    if (is_string($value) && trim($value) !== '') {
        $d = json_decode($value, true);
        if (is_array($d)) { return $d; }
    }
    return [];
}

// ================================================================ kunye.save
/**
 * İstek : {kunye_adres:…, kunye_eposta:…, …, bik_eids_kodu:…}
 *         Yalnız kunye_field_defs() şemasındaki anahtarlar yazılır.
 * Yanıt : {ok:true, message:'Künye bilgileri kaydedildi.', missing:[…]}
 */
api_register('kunye.save', function () {
    $saved = [];
    foreach (kunye_field_defs() as $f) {
        $key = $f['key'];
        $raw = kunye_api_field($key, null);
        if ($raw === null) { continue; }                 // gönderilmeyen alan dokunulmaz

        if (!empty($f['raw'])) {
            // BİK/EİDS doğrulama kodu: TEMİZLENMEZ, olduğu gibi saklanır.
            // Yalnız settings.manage (admin) izniyle buraya gelinebilir.
            $code = (string)$raw;
            if (mb_strlen($code) > KUNYE_BIK_MAX) {
                json_err('Doğrulama kodu en fazla ' . KUNYE_BIK_MAX . ' karakter olabilir.', 400);
            }
            $saved[$key] = trim($code);
            continue;
        }

        $saved[$key] = $f['type'] === 'textarea'
            ? sanitize_plain((string)$raw, 2000)
            : sanitize_line((string)$raw, 255);
    }

    if ($saved) { setting_set_many($saved); }
    settings_all(true);
    if (function_exists('cache_flush')) { cache_flush(); }

    return [
        'message' => 'Künye bilgileri kaydedildi.',
        'missing' => kunye_missing_required(),
    ];
}, ['perm' => 'kunye.manage', 'methods' => ['POST']]);

// ================================================================ corrections.list
/**
 * İstek : {status?:'pending'|'answered'|'rejected'|'all', page?:int, q?:string}
 * Yanıt : {ok:true, items:[…], total:int, counts:{pending,answered,rejected,all}, page, perPage}
 */
api_register('corrections.list', function () {
    $status = (string)kunye_api_field('status', 'pending');
    if (!in_array($status, ['pending', 'answered', 'rejected', 'all', CORRECTION_KIND_UPDATE], true)) {
        $status = 'pending';
    }
    $page = max(1, (int)kunye_api_field('page', 1));
    $perPage = 30;
    $filters = ['status' => $status, 'q' => sanitize_line((string)kunye_api_field('q', ''), 80)];

    $rows = corrections_list($filters, $perPage, ($page - 1) * $perPage);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'         => (int)$r['id'],
            'post_id'    => (int)$r['post_id'],
            'post_title' => (string)arr($r, 'post_title', ''),
            'name'       => (string)$r['name'],
            'email'      => (string)$r['email'],
            'message'    => (string)$r['message'],
            'answer'     => (string)arr($r, 'answer', ''),
            'note'       => (string)arr($r, 'note', ''),
            'status'     => (string)$r['status'],
            'created_at' => (string)$r['created_at'],
            'publish_at' => (string)arr($r, 'publish_at', ''),
            // Kim işlem yaptı (göç 012 · corrections.handled_by)
            'handled_by'   => (int)arr($r, 'handled_by', 0),
            'handled_name' => (string)arr($r, 'handled_name', ''),
            'sure'       => corrections_deadline_state($r),
        ];
    }

    return [
        'items'   => $items,
        'total'   => corrections_count($filters),
        'counts'  => corrections_counts(),
        'page'    => $page,
        'perPage' => $perPage,
        // Panelin rozet eşiğini ayarla aynı yerden okuması için
        'deadline_hours' => corrections_deadline_hours(),
        'overdue'        => corrections_overdue_count(),
    ];
}, ['perm' => 'corrections.triage', 'methods' => ['POST']]);

// ================================================================ corrections.publish
/**
 * İstek : {id:int, answer:string}
 * Yanıt : {ok:true, message:'Düzeltme metni yayımlandı. …'}
 * Not   : Metin haberin gövdesine yazılmaz; süre dolunca kendiliğinden kalkar.
 */
api_register('corrections.publish', function () {
    $res = corrections_publish((int)kunye_api_field('id', 0), (string)kunye_api_field('answer', ''));
    if (!$res['ok']) { json_err($res['error'], (int)$res['code']); }
    return ['message' => $res['message']];
    // Düzeltme/cevap METNİNİ yayımlamak ayrı ve daha ağır izindir: sorumlu müdür
    // atanmışsa roles_can() bunu yalnız ona açar (Basın Kanunu m.14 akışı).
}, ['perm' => 'corrections.publish', 'methods' => ['POST']]);

// ================================================================ corrections.reject
/**
 * İstek : {id:int, note:string}   (not dâhilîdir, yayımlanmaz)
 * Yanıt : {ok:true, message:'…'}
 */
api_register('corrections.reject', function () {
    $res = corrections_reject((int)kunye_api_field('id', 0), (string)kunye_api_field('note', ''));
    if (!$res['ok']) { json_err($res['error'], (int)$res['code']); }
    return ['message' => $res['message']];
}, ['perm' => 'corrections.triage', 'methods' => ['POST']]);

// ================================================================ corrections.delete
/**
 * İstek : {id:int}
 * Yanıt : {ok:true, message:'Talep silindi.'}
 */
api_register('corrections.delete', function () {
    $id = (int)kunye_api_field('id', 0);
    if ($id <= 0 || !corrections_get($id)) { json_err('Düzeltme talebi bulunamadı.', 404); }
    q('DELETE FROM corrections WHERE id = :i', [':i' => $id]);
    if (function_exists('cache_flush')) { cache_flush(); }
    return ['message' => 'Talep silindi.'];
}, ['perm' => 'corrections.triage', 'methods' => ['POST']]);

// ================================================================ corrections.settings
/**
 * İstek : {deadline_hours:int(1-720), notify:'0'|'1'}
 * Yanıt : {ok:true, message:'…', deadline_hours:int}
 *
 * Yasal süre eşiği KODA GÖMÜLMEZ: mevzuat değişebileceği için yayıncı
 * güncelleyebilmelidir (docs/BIK-NOTLARI.md §4 özet "bir gün" der; yürürlükteki
 * metni yayıncı resmî kaynaktan teyit etmelidir).
 */
api_register('corrections.settings', function () {
    $h = (int)kunye_api_field('deadline_hours', corrections_deadline_hours());
    if ($h < 1 || $h > 720) { json_err('Yanıt süresi 1–720 saat aralığında olmalı.', 400); }

    setting_set_many([
        'corrections_deadline_hours' => (string)$h,
        'corrections_notify_enabled' => (string)kunye_api_field('notify', '1') === '1' ? '1' : '0',
    ]);
    settings_all(true);
    if (function_exists('cache_flush')) { cache_flush(); }

    return ['message' => 'Düzeltme ayarları kaydedildi.', 'deadline_hours' => corrections_deadline_hours()];
}, ['perm' => 'settings.manage', 'methods' => ['POST']]);

// ================================================================ corrections.update_add
/**
 * İstek : {post_id:int, text:string}
 * Yanıt : {ok:true, message:'…', id:int}
 *
 * EDİTORYAL güncelleme notu — düzeltme/tekzip DEĞİLDİR. Yasal süreye tabi
 * olmadığı için `corrections.publish` (sorumlu müdür) kapısı ARANMAZ; haber
 * düzenleme yetkisi yeterlidir.
 */
api_register('corrections.update_add', function () {
    $res = post_update_note_add((int)kunye_api_field('post_id', 0), (string)kunye_api_field('text', ''));
    if (!$res['ok']) { json_err($res['error'], (int)$res['code']); }
    return ['message' => $res['message'], 'id' => (int)$res['id']];
}, ['perm' => 'posts.edit_any', 'methods' => ['POST']]);

// ================================================================ corrections.update_delete
/**
 * İstek : {id:int}
 * Yanıt : {ok:true, message:'Güncelleme notu silindi.'}
 */
api_register('corrections.update_delete', function () {
    $res = post_update_note_delete((int)kunye_api_field('id', 0));
    if (!$res['ok']) { json_err($res['error'], (int)$res['code']); }
    return ['message' => $res['message']];
}, ['perm' => 'posts.edit_any', 'methods' => ['POST']]);

// ================================================================ widgets.save
/**
 * İstek : {market_mode, market_url, market_data, market_map,
 *          weather_mode, weather_url, weather_data, weather_map}
 *   *_data  → nesne ya da JSON metni: {"USD":"41,25",…} / {"sehir":"…","derece":"…","durum":"…"}
 *   *_map   → satır satır "HEDEF=nokta.yolu" eşlemesi (isteğe bağlı)
 * Yanıt : {ok:true, message:'Widget ayarları kaydedildi.'}
 */
api_register('widgets.save', function () {
    $pairs = [];

    foreach (['market', 'weather'] as $type) {
        $mode = (string)kunye_api_field($type . '_mode', 'manual');
        $pairs['widget_' . $type . '_mode'] = $mode === 'remote' ? 'remote' : 'manual';

        $url = sanitize_line((string)kunye_api_field($type . '_url', ''), 500);
        if ($url !== '' && safe_remote_url($url) === false) {
            json_err(($type === 'market' ? 'Piyasa' : 'Hava durumu') . ' adresi güvenlik nedeniyle reddedildi '
                . '(yalnız http/https ve genel IP adresleri; localhost ve özel ağlar kabul edilmez).', 400);
        }
        $pairs['widget_' . $type . '_url'] = $url;

        $pairs['widget_' . $type . '_map'] = sanitize_plain((string)kunye_api_field($type . '_map', ''), 2000);
    }

    // Piyasa değerleri — önceki değerler yön oku için saklanır
    $marketRaw = kunye_api_array(kunye_api_field('market_data', ''));
    $market = [];
    foreach ($marketRaw as $k => $v) {
        $key = preg_replace('/[^A-Za-z0-9_]/', '', (string)$k);
        if ($key === '') { continue; }
        $market[$key] = sanitize_line(is_scalar($v) ? (string)$v : '', 40);
    }
    $prev = widgets_setting_json('widget_market_data');
    if ($market !== $prev) { $pairs['widget_market_prev'] = json_encode($prev, JSON_UNESCAPED_UNICODE); }
    $pairs['widget_market_data'] = json_encode($market, JSON_UNESCAPED_UNICODE);
    $pairs['widget_market_saved_at'] = now();

    // Hava durumu değerleri
    $weatherRaw = kunye_api_array(kunye_api_field('weather_data', ''));
    $weather = [];
    foreach (['sehir' => 60, 'derece' => 10, 'durum' => 60, 'ikon' => 8] as $k => $max) {
        $v = arr($weatherRaw, $k, '');
        $weather[$k] = sanitize_line(is_scalar($v) ? (string)$v : '', $max);
    }
    $pairs['widget_weather_data'] = json_encode($weather, JSON_UNESCAPED_UNICODE);
    $pairs['widget_weather_saved_at'] = now();

    setting_set_many($pairs);
    settings_all(true);
    if (function_exists('cache_flush')) { cache_flush(); }

    return ['message' => 'Widget ayarları kaydedildi.'];
}, ['perm' => 'settings.manage', 'methods' => ['POST']]);

// ================================================================ widgets.test
/**
 * İstek : {tur:'market'|'weather', url:string}
 * Yanıt : {ok:true, ham:string(JSON), eslenmis:{…}, cached_at, stale, uyari}
 * Adres safe_remote_url()'den geçmezse istek yapılmaz, 400 döner.
 */
api_register('widgets.test', function () {
    $type = (string)kunye_api_field('tur', 'market');
    if ($type !== 'weather') { $type = 'market'; }
    $url = sanitize_line((string)kunye_api_field('url', ''), 500);
    if ($url === '') { json_err('Önce harici JSON adresini girin.', 400); }
    if (safe_remote_url($url) === false) {
        json_err('Bu adres güvenlik nedeniyle reddedildi (yalnız http/https ve genel IP adresleri; '
            . 'localhost, özel ve ayrılmış ağlar kabul edilmez).', 400);
    }

    // Test her zaman taze çekim yapsın diye önbellek kaydı düşürülür
    widgets_clear_cache(widgets_cache_key($url));

    $res = widgets_fetch_remote($url, WIDGET_TTL);
    if (!$res['ok']) { json_err($res['error'] !== '' ? $res['error'] : 'Veri çekilemedi.', 400); }

    $mapped = widgets_apply_map($res['data'], setting('widget_' . $type . '_map', ''));
    $ham = json_encode($res['data'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    return [
        'ham'       => is_string($ham) ? mb_substr($ham, 0, 4000) : '',
        'eslenmis'  => $mapped,
        'cached_at' => (string)$res['cached_at'],
        'stale'     => !empty($res['stale']),
        'uyari'     => !empty($res['stale']) ? 'Uzak servise ulaşılamadı; önbellekteki eski veri gösteriliyor.' : '',
        'message'   => 'Veri çekildi.',
    ];
}, ['perm' => 'settings.manage', 'methods' => ['POST']]);

// ================================================================ widgets.clear_cache
/**
 * İstek : {tur?:'market'|'weather'}  — boşsa tüm widget önbelleği silinir
 * Yanıt : {ok:true, message:'Önbellek temizlendi.'}
 */
api_register('widgets.clear_cache', function () {
    $type = (string)kunye_api_field('tur', '');
    widgets_clear_cache(in_array($type, ['market', 'weather'], true) ? $type : null);
    return ['message' => 'Widget önbelleği temizlendi.'];
}, ['perm' => 'settings.manage', 'methods' => ['POST']]);

// ================================================================ newsletter.export
/**
 * Abone listesi CSV indirmesi. Kendi çıktısını verir (json_out kullanılmaz).
 * Yöntem GET olduğu için dağıtıcı CSRF aramaz; izin denetimi yapılır.
 */
api_register('newsletter.export', function () {
    $rows = qa('SELECT email, ip, confirmed, created_at FROM newsletter_subscribers ORDER BY id DESC');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bulten-aboneleri-' . date('Y-m-d') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";                       // Excel'in UTF-8 tanıması için BOM
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['E-posta', 'IP', 'Onaylı', 'Kayıt tarihi'], ';');
    foreach ($rows as $r) {
        fputcsv($fh, [
            (string)$r['email'],
            (string)$r['ip'],
            (int)$r['confirmed'] === 1 ? 'evet' : 'hayır',
            (string)$r['created_at'],
        ], ';');
    }
    fclose($fh);
}, ['perm' => 'settings.manage', 'methods' => ['GET']]);

// ================================================================ public.correction
/**
 * İstek : {post_id:int, name:string, email:string, message:string,
 *          website:string (honeypot — boş olmalı), _csrf:string}
 * Yanıt : {ok:true, message:'Talebiniz yayın kuruluşuna iletildi. …'}
 * Hata  : {ok:false, error:'…'} · 400 doğrulama · 404 haber yok · 429 hız sınırı
 *
 * Honeypot dolu gelirse kayıt açılmaz ama ok:true döner (bota geri bildirim verilmez).
 */
api_register('public.correction', function () {
    $res = corrections_submit([
        'post_id' => kunye_api_field('post_id', 0),
        'name'    => kunye_api_field('name', ''),
        'email'   => kunye_api_field('email', ''),
        'message' => kunye_api_field('message', ''),
        'website' => kunye_api_field('website', ''),
    ]);
    if (!$res['ok']) { json_err($res['error'], (int)$res['code']); }
    return ['message' => $res['message']];
}, ['perm' => 'public', 'methods' => ['POST']]);

// ================================================================ newsletter.subscribe
/**
 * İstek : {email:string, website:string (honeypot), _csrf:string}
 * Yanıt : {ok:true, message:'Kaydınız alındı.'}
 *
 * Zaten kayıtlı e-posta için de aynı başarı mesajı döner (varlık sızdırılmaz).
 * Bülten GÖNDERİM motoru kapsam dışıdır (CONTRACTS §13) — yalnız abone toplanır.
 */
api_register('newsletter.subscribe', function () {
    $ok = ['message' => 'Kaydınız alındı.'];

    if (!rate_limit('newsletter:' . client_ip(), 3, 900)) {
        json_err('Çok sık deneme yapıldı. Lütfen bir süre sonra tekrar deneyin.', 429);
    }
    if (trim((string)kunye_api_field('website', '')) !== '') { return $ok; }   // honeypot

    $email = mb_strtolower(trim((string)kunye_api_field('email', '')), 'UTF-8');
    if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('Geçerli bir e-posta adresi girin.', 400);
    }

    $exists = (int)qv('SELECT id FROM newsletter_subscribers WHERE email = :e', [':e' => $email], 0);
    if (!$exists) {
        try {
            db_insert('newsletter_subscribers', [
                'email'      => $email,
                'ip'         => client_ip(),
                'confirmed'  => 0,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // UNIQUE çakışması (eşzamanlı istek) — kullanıcıya yine başarı döner
            log_error('Bülten kaydı: ' . $e->getMessage());
        }
    }
    return $ok;
}, ['perm' => 'public', 'methods' => ['POST']]);
