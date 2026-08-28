<?php
/**
 * Manşet — medya API uçları (Ajan-2 · 1.3-08 ekleri Ajan-C).
 * Kayıtlı uçlar: media.list · media.upload · media.update · media.delete
 *                media.variants · media.unused · media.bulk_delete · media.bulk_alt
 * Sözleşme: CONTRACTS.md §7
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/media.php';

/**
 * Bir medya satırının API biçimi.
 * 1.0'daki alanlar AYNEN korunur (M.medyaSec ve assets/editor.js bunlara bakar);
 * 1.3-08 alanları eklenmiştir.
 */
function media_api_item(array $m) {
    return [
        'id'            => (int)$m['id'],
        'filename'      => (string)$m['filename'],
        'url'           => (string)$m['url'],
        'thumb'         => (string)$m['thumb'],
        'alt'           => (string)$m['alt'],
        'width'         => (int)$m['width'],
        'height'        => (int)$m['height'],
        'size'          => (int)$m['size'],
        'size_text'     => (string)$m['size_text'],
        'created_at'    => (string)arr($m, 'created_at', ''),
        // 1.3-08
        'variant_state' => (string)arr($m, 'variant_state', ''),
        'variant_label' => (string)arr($m, 'variant_label', ''),
        'variant_ready' => !empty($m['variant_ready']),
        'alt_missing'   => !empty($m['alt_missing']),
        'focus_x'       => (int)arr($m, 'focus_x', 50),
        'focus_y'       => (int)arr($m, 'focus_y', 50),
    ];
}

// ---------------------------------------------------------------- media.list
/**
 * İstek : {q?:string, limit?:int, offset?:int}
 * Yanıt : {ok:true, items:[{id,filename,url,thumb,alt,width,height,size,size_text,created_at}], total:int}
 */
api_register('media.list', function () {
    $d = json_body();
    $q = sanitize_line((string)arr($d, 'q', ''), 120);
    $limit = (int)arr($d, 'limit', 60);
    $offset = (int)arr($d, 'offset', 0);
    // 1.3-08: '' | 'noalt' (alt metni boş) | 'queued' (varyant bekleyen)
    $filter = (string)arr($d, 'filter', '');
    if (!in_array($filter, ['', 'noalt', 'queued'], true)) { $filter = ''; }

    $items = [];
    foreach (media_list($q, $limit, $offset, $filter) as $m) {
        $items[] = media_api_item($m);
    }
    return [
        'items'         => $items,
        'total'         => media_count($q, $filter),
        'filter'        => $filter,
        'missing_alt'   => media_missing_alt_count(),
        'queue_pending' => media_queue_size(),
    ];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.upload
/**
 * İstek : multipart/form-data · alan adı dosya[] (çoklu) · alt (isteğe bağlı)
 *         CSRF X-CSRF başlığından okunur (json_body() multipart'ta çalışmaz).
 * Yanıt : {ok:true, items:[…], hatalar:[…], message:'…'}
 */
api_register('media.upload', function () {
    if (empty($_FILES['dosya']) || !is_array($_FILES['dosya'])) {
        json_err('Dosya alınamadı. Alan adı "dosya[]" olmalı.', 400);
    }
    $f = $_FILES['dosya'];
    $alt = sanitize_line((string)inp('alt', ''), 255);

    // Tek ve çoklu yükleme yapısını aynı biçime indir
    $files = [];
    if (is_array($f['name'])) {
        $n = count($f['name']);
        for ($i = 0; $i < $n; $i++) {
            $files[] = [
                'name'     => (string)$f['name'][$i],
                'tmp_name' => (string)$f['tmp_name'][$i],
                'error'    => (int)$f['error'][$i],
                'size'     => (int)$f['size'][$i],
            ];
        }
    } else {
        $files[] = [
            'name'     => (string)$f['name'],
            'tmp_name' => (string)$f['tmp_name'],
            'error'    => (int)$f['error'],
            'size'     => (int)$f['size'],
        ];
    }
    if (count($files) > 20) { json_err('Tek seferde en çok 20 dosya yükleyebilirsiniz.', 400); }

    $items = [];
    $errors = [];
    foreach ($files as $one) {
        if ((int)$one['error'] === UPLOAD_ERR_NO_FILE) { continue; }
        $r = media_save_upload($one, $alt);
        if (!empty($r['ok'])) {
            $row = media_get((int)$r['id']);
            $items[] = $row ? media_api_item($row) : [
                'id'        => (int)$r['id'],
                'filename'  => (string)$r['filename'],
                'url'       => (string)$r['url'],
                'thumb'     => (string)$r['url'],
                'alt'       => $alt,
                'width'     => 0, 'height' => 0, 'size' => 0, 'size_text' => '',
            ];
        } else {
            $errors[] = sanitize_line((string)$one['name'], 80) . ': ' . (string)$r['error'];
        }
    }

    if (!$items && $errors) { json_err(implode(' · ', $errors), 400); }
    if (!$items) { json_err('Yüklenecek dosya bulunamadı.', 400); }

    $msg = count($items) . ' dosya yüklendi.';
    if ($errors) { $msg .= ' ' . count($errors) . ' dosya reddedildi: ' . implode(' · ', $errors); }
    // 1.3-08: büyük boyutlar artık kuyrukta üretiliyor; editör bunu bilsin.
    $bekleyen = media_queue_size();
    if ($bekleyen > 0) {
        $msg .= ' Büyük boyutlar arka planda üretilecek (' . $bekleyen . ' kuyrukta); '
              . 'o zamana kadar sayfalarda özgün görsel kullanılır.';
    }
    return ['items' => $items, 'hatalar' => $errors, 'queue_pending' => $bekleyen, 'message' => $msg];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.update
/**
 * İstek : {id:int, alt?:string, focus_x?:int, focus_y?:int}
 * Yanıt : {ok:true, id:int, alt:string, focus_x:int, focus_y:int, message:'…'}
 *
 * `alt` gönderilmezse alt metne DOKUNULMAZ (odak noktası tek başına
 * kaydedilebilsin); 1.0 istemcileri her zaman `alt` gönderdiği için
 * eski davranış birebir korunur.
 */
api_register('media.update', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id <= 0) { json_err('Geçersiz kayıt.', 400); }

    $mesaj = [];
    $alt = null;
    if (array_key_exists('alt', $d)) {
        $r = media_update_alt($id, (string)arr($d, 'alt', ''));
        if (empty($r['ok'])) { json_err((string)$r['error'], 404); }
        $alt = (string)$r['alt'];
        $mesaj[] = 'Alt metin kaydedildi.';
    }
    if (array_key_exists('focus_x', $d) || array_key_exists('focus_y', $d)) {
        $mevcut = media_get($id);
        if (!$mevcut) { json_err('Kayıt bulunamadı.', 404); }
        $fr = media_set_focus($id,
            array_key_exists('focus_x', $d) ? arr($d, 'focus_x', 50) : $mevcut['focus_x'],
            array_key_exists('focus_y', $d) ? arr($d, 'focus_y', 50) : $mevcut['focus_y']);
        if (empty($fr['ok'])) { json_err((string)$fr['error'], 400); }
        $mesaj[] = 'Odak noktası kaydedildi.';
    }
    if (!$mesaj) { json_err('Değiştirilecek alan gönderilmedi.', 400); }

    if (function_exists('cache_flush')) { cache_flush(); }
    $son = media_get($id);
    return [
        'id'      => $id,
        'alt'     => $alt !== null ? $alt : ($son ? (string)$son['alt'] : ''),
        'focus_x' => $son ? (int)$son['focus_x'] : 50,
        'focus_y' => $son ? (int)$son['focus_y'] : 50,
        'message' => implode(' ', $mesaj),
    ];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.delete
/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, message:'…'}
 * Not   : v1'de media tablosunda "yükleyen" alanı olmadığı için silme yalnız
 *         media.delete_any iznine bağlıdır (yazar rolü silemez).
 */
api_register('media.delete', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id <= 0) { json_err('Geçersiz kayıt.', 400); }
    $r = media_delete($id);
    if (empty($r['ok'])) { json_err((string)$r['error'], 404); }
    if (function_exists('cache_flush')) { cache_flush(); }
    return ['id' => $id, 'message' => 'Dosya silindi.'];
}, ['perm' => 'media.delete_any', 'methods' => ['POST']]);

// ============================================================================
// 1.3-08 ekleri (Ajan-C)
// ============================================================================

// ---------------------------------------------------------------- media.variants
/**
 * Bekleyen varyantları ŞİMDİ üretir (cron kurulu olmayan hosting için elle tetik).
 * İstek : {}
 * Yanıt : {ok:true, processed:int, pending:int, stats:{…}, message:'…'}
 *
 * Kuyruğun normal yolu cron'dur (`cron_register('medya', …)`). Bu uç, gerçek
 * cron kuramayan yayıncının panelden aynı işi başlatabilmesi içindir. Toplu
 * yenileme (`media_regenerate_all`) DEĞİLDİR: yalnız BEKLEYENLER işlenir ve
 * parti boyutu `media_queue_batch()` ile sınırlıdır — istek zaman aşımına
 * uğramasın diye.
 */
api_register('media.variants', function () {
    if (!media_gd_available()) {
        json_err('Sunucuda GD eklentisi yok; küçültülmüş türev üretilemiyor.', 400);
    }
    $islenen = 0;
    foreach (media_variants_pending(media_queue_batch()) as $r) {
        media_variant_worker((int)$r['id']);
        $islenen++;
    }
    if ($islenen > 0 && function_exists('cache_flush')) { cache_flush(); }
    $kalan = media_queue_size();
    return [
        'processed' => $islenen,
        'pending'   => $kalan,
        'stats'     => media_queue_stats(),
        'message'   => $islenen . ' görselin türevleri üretildi. '
                       . ($kalan > 0 ? $kalan . ' görsel kuyrukta kaldı; yeniden çalıştırın.' : 'Kuyruk boş.'),
    ];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.unused
/**
 * Hiçbir haberde/sayfada/ayarda/reklamda geçmeyen görseller.
 * İstek : {limit?:int}
 * Yanıt : {ok:true, items:[…], total:int, scanned:int, bytes:int, warning:string}
 *
 * SALT OKUNUR. Silme burada YAPILMAZ; `media.bulk_delete` ayrı bir uçtur ve
 * `media.delete_any` ister. Yanıt her zaman yanlış pozitif uyarısını taşır —
 * arayüz bunu editöre göstermek zorundadır.
 */
api_register('media.unused', function () {
    $d = json_body();
    $limit = (int)arr($d, 'limit', 100);
    $r = media_unused($limit);

    $items = [];
    foreach ($r['items'] as $m) { $items[] = media_api_item($m); }

    return [
        'items'      => $items,
        'total'      => (int)$r['total'],
        'scanned'    => (int)$r['scanned'],
        'bytes'      => (int)$r['bytes'],
        'bytes_text' => (string)$r['bytes_text'],
        'warning'    => 'Bu liste METİN TARAMASIYLA üretilir: haber gövdesindeki adresler '
                        . 'düz metin olarak aranır. Görseli JavaScript ile kuran, adresi '
                        . 'parçalayan ya da tema dosyasında sabit yazılmış kullanımlar '
                        . 'görülmez. Silmeden önce her dosyayı gözle denetleyin.',
        'message'    => $r['total'] . ' görsel hiçbir yerde kullanılmıyor görünüyor ('
                        . $r['scanned'] . ' kayıt tarandı, ' . $r['bytes_text'] . ').',
    ];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.bulk_delete
/**
 * Toplu silme — YIKICI.
 * İstek : {ids:[int], confirm:true}
 * Yanıt : {ok:true, deleted:int, failed:int, errors:[…], message:'…'}
 *
 * ÜÇ KAPI:
 *   1. `media.delete_any` izni (media.delete ucuyla aynı; yazar rolü silemez).
 *   2. `confirm` alanı AÇIKÇA true olmalı — yanlışlıkla gönderilen bir istek
 *      ya da eski bir istemci dosya silemesin.
 *   3. En çok 200 kayıt (media_clean_ids). Tek istekle kütüphane süpürülemez.
 * Silinen her dosya `audit_log`'a yazılır (tablo yoksa sessizce atlanır).
 */
api_register('media.bulk_delete', function () {
    $d = json_body();
    $ids = media_clean_ids(arr($d, 'ids', []));
    if (!$ids) { json_err('Silinecek kayıt seçilmedi.', 400); }
    if (arr($d, 'confirm', false) !== true) {
        json_err('Toplu silme onaylanmadı. Bu işlem geri alınamaz.', 400);
    }

    // Denetim kaydı ÖNCE: silinen dosyaların adları kayıtta kalsın.
    $adlar = [];
    foreach (qa('SELECT id, filename FROM media WHERE id IN (' . implode(',', $ids) . ')') as $r) {
        $adlar[] = (string)$r['filename'];
    }
    if (function_exists('vitrin_audit')) {
        vitrin_audit('media.bulk_delete', 'media:' . count($ids), implode(' ', array_slice($adlar, 0, 50)));
    }

    $r = media_delete_many($ids);
    if (function_exists('cache_flush')) { cache_flush(); }
    return [
        'deleted' => (int)$r['deleted'],
        'failed'  => (int)$r['failed'],
        'errors'  => $r['errors'],
        'message' => $r['deleted'] . ' dosya ve türevleri kalıcı olarak silindi.'
                     . ($r['failed'] ? ' ' . $r['failed'] . ' dosya silinemedi.' : ''),
    ];
}, ['perm' => 'media.delete_any', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.bulk_alt
/**
 * Seçili görsellere aynı alt metni yazar (erişilebilirlik toplu düzeltmesi).
 * İstek : {ids:[int], alt:string}
 * Yanıt : {ok:true, updated:int, alt:string, message:'…'}
 */
api_register('media.bulk_alt', function () {
    $d = json_body();
    $ids = media_clean_ids(arr($d, 'ids', []));
    if (!$ids) { json_err('Kayıt seçilmedi.', 400); }
    $alt = sanitize_line((string)arr($d, 'alt', ''), 255);
    if ($alt === '') { json_err('Alt metin boş olamaz.', 400); }

    $r = media_update_alt_many($ids, $alt);
    if (function_exists('cache_flush')) { cache_flush(); }
    return [
        'updated' => (int)$r['updated'],
        'alt'     => (string)$r['alt'],
        'message' => $r['updated'] . ' görselin alt metni güncellendi.',
    ];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);
