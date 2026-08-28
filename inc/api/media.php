<?php
/**
 * Manşet — medya API uçları (Ajan-2).
 * Kayıtlı uçlar: media.list · media.upload · media.update · media.delete
 * Sözleşme: CONTRACTS.md §7
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/media.php';

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

    $items = [];
    foreach (media_list($q, $limit, $offset) as $m) {
        $items[] = [
            'id'         => (int)$m['id'],
            'filename'   => (string)$m['filename'],
            'url'        => (string)$m['url'],
            'thumb'      => (string)$m['thumb'],
            'alt'        => (string)$m['alt'],
            'width'      => (int)$m['width'],
            'height'     => (int)$m['height'],
            'size'       => (int)$m['size'],
            'size_text'  => (string)$m['size_text'],
            'created_at' => (string)$m['created_at'],
        ];
    }
    return ['items' => $items, 'total' => media_count($q)];
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
            $items[] = [
                'id'        => (int)$r['id'],
                'filename'  => (string)$r['filename'],
                'url'       => (string)$r['url'],
                'thumb'     => $row ? (string)$row['thumb'] : (string)$r['url'],
                'alt'       => $row ? (string)$row['alt'] : $alt,
                'width'     => $row ? (int)$row['width'] : 0,
                'height'    => $row ? (int)$row['height'] : 0,
                'size'      => $row ? (int)$row['size'] : 0,
                'size_text' => $row ? (string)$row['size_text'] : '',
            ];
        } else {
            $errors[] = sanitize_line((string)$one['name'], 80) . ': ' . (string)$r['error'];
        }
    }

    if (!$items && $errors) { json_err(implode(' · ', $errors), 400); }
    if (!$items) { json_err('Yüklenecek dosya bulunamadı.', 400); }

    $msg = count($items) . ' dosya yüklendi.';
    if ($errors) { $msg .= ' ' . count($errors) . ' dosya reddedildi: ' . implode(' · ', $errors); }
    return ['items' => $items, 'hatalar' => $errors, 'message' => $msg];
}, ['perm' => 'media.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- media.update
/**
 * İstek : {id:int, alt:string}
 * Yanıt : {ok:true, id:int, alt:string, message:'…'}
 */
api_register('media.update', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id <= 0) { json_err('Geçersiz kayıt.', 400); }
    $r = media_update_alt($id, (string)arr($d, 'alt', ''));
    if (empty($r['ok'])) { json_err((string)$r['error'], 404); }
    if (function_exists('cache_flush')) { cache_flush(); }
    return ['id' => $id, 'alt' => (string)$r['alt'], 'message' => 'Alt metin kaydedildi.'];
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
