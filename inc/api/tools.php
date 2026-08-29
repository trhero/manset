<?php
/**
 * Manşet — araçlar / bakım API uçları (Ajan-10).
 * Ad alanı (CONTRACTS §7): tools.*  backup.*  cache.*  logs.*  (+ media.stats/regen/orphans)
 *
 * Bu dosya iki bağlamda yüklenir:
 *   1) api.php  → glob('inc/api/*.php'); aşağıdaki api_register() çağrıları uçları kaydeder.
 *   2) admin/pages/{tools,logs}.php → require_once; ortak yardımcılar (günlük ayrıştırma,
 *      sistem bilgisi) tek yerde dursun diye.
 *
 * GÜVENLİK (Ajan-11 denetimi için özet):
 *   · Tüm uçlar 'tools.manage' ister → can() gereği yalnız admin rolü.
 *   · filename parametreleri asla yol içermez: basename() + katı desen +
 *     realpath() ile DB_DIR / UPLOAD_DIR altında olduğu doğrulanır (backup_resolve,
 *     media_safe_path).
 *   · media.delete_orphan yalnız media tablosunda KAYDI OLMAYAN dosyayı siler;
 *     .htaccess / index.html ve uploads/cache dokunulmazdır.
 *   · backup.restore yönetici parolasını yeniden ister (password_verify) ve
 *     hız sınırına bağlıdır.
 *   · İndirme uçları kendi çıktısını verir; yanıt no-store başlıklarıyla gider.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/schema.php';
require_once INC_DIR . '/media.php';
// api.php bu iki modülü kendi listesinde yüklemiyor; uçlar için burada zorunlu.
if (is_file(INC_DIR . '/cache.php'))  { require_once INC_DIR . '/cache.php'; }
if (is_file(INC_DIR . '/backup.php')) { require_once INC_DIR . '/backup.php'; }

// ============================================================ ortak yardımcılar

/** İstek alanı: JSON gövdesi öncelikli, yoksa POST/GET. */
function tools_in($key, $default = null) {
    $d = json_body();
    if (array_key_exists($key, $d)) { return $d[$key]; }
    $v = inp($key, null);
    return $v === null ? $default : $v;
}
function tools_in_s($key, $default = '') { $v = tools_in($key, $default); return is_scalar($v) ? trim((string)$v) : (string)$default; }
function tools_in_i($key, $default = 0)  { $v = tools_in($key, $default); return is_scalar($v) ? (int)$v : (int)$default; }

/** Hata günlüğü dosyası. */
function tools_log_file() { return DB_DIR . '/error.log'; }

/** Günlük türü etiketleri (filtre listesi). */
function tools_log_types() {
    return ['UNCAUGHT' => 'Yakalanmamış hata', 'PHP' => 'PHP uyarısı', 'CSRF' => 'CSRF reddi',
            'CRON' => 'Zamanlanmış görev', 'DIGER' => 'Diğer'];
}

/** Bir günlük mesajından tür çıkarır. */
function tools_log_type_of($message) {
    $m = (string)$message;
    if (strncmp($m, 'UNCAUGHT', 8) === 0) { return 'UNCAUGHT'; }
    if (strncmp($m, 'PHP[', 4) === 0)     { return 'PHP'; }
    if (strncmp($m, 'CRON', 4) === 0)     { return 'CRON'; }
    if (stripos($m, 'csrf') !== false)    { return 'CSRF'; }
    return 'DIGER';
}

/**
 * Günlüğü SONDAN geriye doğru okur — dosya çok büyük olsa da tamamı belleğe alınmaz.
 * @return array ham satırlar (dosyadaki sırayla, en eskiden yeniye)
 */
function tools_log_raw_tail($file, $wantLines = 500, $maxBytes = 1048576) {
    if (!is_file($file)) { return []; }
    $fh = @fopen($file, 'rb');
    if (!$fh) { return []; }
    $size = (int)@filesize($file);
    $pos = $size;
    $data = '';
    $chunk = 16384;
    while ($pos > 0 && strlen($data) < $maxBytes && substr_count($data, "\n") <= $wantLines) {
        $read = min($chunk, $pos);
        $pos -= $read;
        if (fseek($fh, $pos) !== 0) { break; }
        $data = (string)fread($fh, $read) . $data;
    }
    fclose($fh);
    $lines = preg_split('/\r\n|\n|\r/', $data);
    if (!is_array($lines)) { return []; }
    // Baştaki satır yarım kalmış olabilir (dosyanın başına ulaşmadıysak)
    if ($pos > 0 && count($lines) > 1) { array_shift($lines); }
    return $lines;
}

/**
 * Günlük satırlarını kayıtlara ayrıştırır (en yeni üstte).
 * Satır biçimi: [Y-m-d H:i:s] mesaj | bağlam
 *
 * @return array [['date','type','type_label','message','context'], …]
 */
function tools_log_entries($limit = 50, $type = '', $q = '') {
    $lines = tools_log_raw_tail(tools_log_file(), max(200, (int)$limit * 10));
    $entries = [];
    foreach ($lines as $line) {
        $line = rtrim((string)$line);
        if ($line === '') { continue; }
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s?(.*)$/', $line, $m)) {
            $rest = (string)$m[2];
            $context = '';
            $sep = strrpos($rest, ' | ');
            if ($sep !== false) { $context = substr($rest, $sep + 3); $rest = substr($rest, 0, $sep); }
            $entries[] = [
                'date'    => $m[1],
                'message' => $rest,
                'context' => $context,
                'type'    => tools_log_type_of($rest),
            ];
        } elseif ($entries) {
            // Çok satırlı mesajın devamı
            $entries[count($entries) - 1]['message'] .= "\n" . $line;
        }
    }
    $entries = array_reverse($entries); // en yeni üstte

    $type = strtoupper(trim((string)$type));
    $types = tools_log_types();
    $q = trim((string)$q);
    $out = [];
    foreach ($entries as $e) {
        if ($type !== '' && isset($types[$type]) && $e['type'] !== $type) { continue; }
        if ($q !== '' && stripos($e['message'] . ' ' . $e['context'], $q) === false) { continue; }
        $e['type_label'] = isset($types[$e['type']]) ? $types[$e['type']] : $e['type'];
        $out[] = $e;
        if (count($out) >= (int)$limit) { break; }
    }
    return $out;
}

/** Veritabanı tablolarının satır sayıları. */
function tools_table_counts() {
    $out = [];
    foreach (array_keys(schema_tables()) as $t) {
        try { $out[$t] = (int)qv('SELECT COUNT(*) FROM ' . $t, [], 0); }
        catch (Throwable $e) { $out[$t] = -1; }
    }
    return $out;
}

/** Veritabanının yaklaşık disk boyutu (bayt). */
function tools_db_size() {
    if (db_driver() === 'sqlite') {
        $p = (string)cfg('db_path', DB_DIR . '/manset.sqlite');
        $n = (int)@filesize($p);
        foreach (['-wal', '-shm'] as $suf) { if (is_file($p . $suf)) { $n += (int)@filesize($p . $suf); } }
        return $n;
    }
    try {
        return (int)qv('SELECT COALESCE(SUM(data_length + index_length), 0)
                        FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', [], 0);
    } catch (Throwable $e) { return 0; }
}

/** Sistem/ortam özeti (panel "Sistem" sekmesi ve tools.system ucu). */
function tools_system_info() {
    $exts = [];
    foreach (['gd', 'mbstring', 'pdo_sqlite', 'pdo_mysql', 'curl', 'openssl',
              'fileinfo', 'exif', 'simplexml', 'zip', 'zlib', 'json'] as $e) {
        $exts[$e] = extension_loaded($e);
    }
    $dirs = [];
    foreach (['db' => DB_DIR, 'uploads' => UPLOAD_DIR, 'uploads/cache' => CACHE_DIR, 'themes' => THEMES_DIR] as $label => $d) {
        $dirs[$label] = ['path' => $d, 'exists' => is_dir($d), 'writable' => is_dir($d) && is_writable($d)];
    }
    $free = disk_free_bytes(ROOT_DIR);
    return [
        'manset_version' => MANSET_VERSION,
        'php_version'    => PHP_VERSION,
        'sapi'           => PHP_SAPI,
        'os'             => PHP_OS_FAMILY,
        'extensions'     => $exts,
        'ini' => [
            'memory_limit'        => (string)ini_get('memory_limit'),
            'max_execution_time'  => (string)ini_get('max_execution_time'),
            'upload_max_filesize' => (string)ini_get('upload_max_filesize'),
            'post_max_size'       => (string)ini_get('post_max_size'),
            'display_errors'      => (string)ini_get('display_errors'),
        ],
        'dirs'            => $dirs,
        'disk_free'       => $free === null ? 0 : (int)$free,
        'disk_free_text'  => $free === null ? 'bilinmiyor' : backup_human_size((int)$free),
        'db_driver'       => db_driver(),
        'db_size'         => tools_db_size(),
        'db_size_text'    => backup_human_size(tools_db_size()),
        'sqlite_version'  => db_driver() === 'sqlite' ? backup_sqlite_version() : '',
        'cron_last_run'   => setting('cron_last_run', ''),
        'cron_last_report' => setting('cron_last_report', ''),
        'sef'             => sef_enabled(),
        'tables'          => tools_table_counts(),
        'log_size'        => is_file(tools_log_file()) ? (int)filesize(tools_log_file()) : 0,
    ];
}

/** VACUUM (SQLite) / OPTIMIZE TABLE (MySQL). */
function tools_vacuum_run() {
    $before = tools_db_size();
    try {
        if (db_driver() === 'sqlite') {
            db()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            db()->exec('VACUUM');
            $label = 'VACUUM';
        } else {
            $tables = [];
            foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) {
                $tables[] = backup_mysql_ident((string)$r[0]);
            }
            if ($tables) { db()->exec('OPTIMIZE TABLE ' . implode(', ', $tables)); }
            $label = 'OPTIMIZE TABLE';
        }
    } catch (Throwable $e) {
        log_error('tools.vacuum: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Bakım komutu çalıştırılamadı: ' . $e->getMessage()];
    }
    $after = tools_db_size();
    return [
        'ok' => true, 'command' => $label,
        'before' => $before, 'after' => $after,
        'saved' => max(0, $before - $after),
        'message' => $label . ' tamamlandı. ' . backup_human_size($before) . ' → ' . backup_human_size($after),
    ];
}

// ============================================================ önbellek uçları

api_register('cache.status', function () {
    if (!function_exists('cache_stats')) { return json_err('Önbellek modülü yüklü değil.', 500); }
    $s = cache_stats();
    return [
        'enabled'   => $s['enabled'],
        'files'     => $s['files'],
        'bytes'     => $s['bytes'],
        'size_text' => $s['size_text'],
        'oldest'    => $s['oldest'],
        'newest'    => $s['newest'],
        'ttl_home'  => $s['ttl_home'],
        'ttl_post'  => $s['ttl_post'],
        'max_mb'    => $s['max_mb'],
        'writable'  => $s['writable'],
        'dir'       => $s['dir'],
    ];
}, ['perm' => 'tools.manage', 'methods' => ['POST', 'GET']]);

api_register('cache.flush', function () {
    if (!function_exists('cache_purge')) { return json_err('Önbellek modülü yüklü değil.', 500); }
    $scope = tools_in_s('scope', '');
    // Beyaz liste: bilinmeyen kapsam gelirse tamamı temizlenir (cache_purge güvenli tarafta)
    if ($scope !== '' && !preg_match('/^(home|category|tag|search|page|post:\d+)$/', $scope)) {
        return json_err('Geçersiz kapsam.', 400);
    }
    $n = cache_purge($scope === '' ? null : $scope);
    return ['deleted' => $n, 'message' => $n . ' önbellek dosyası temizlendi.'];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

api_register('cache.sweep', function () {
    if (!function_exists('cache_sweep')) { return json_err('Önbellek modülü yüklü değil.', 500); }
    $summary = (string)cache_sweep();
    return ['summary' => $summary, 'message' => $summary];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

// ============================================================ yedekleme uçları

api_register('backup.create', function () {
    if (!function_exists('backup_create')) { return json_err('Yedekleme modülü yüklü değil.', 500); }
    $r = backup_create();
    if (!$r['ok']) { return json_err($r['error'] !== '' ? $r['error'] : 'Yedek alınamadı.', 400); }
    return [
        'filename'  => $r['filename'],
        'size'      => $r['size'],
        'size_text' => backup_human_size($r['size']),
        'method'    => $r['method'],
        'message'   => 'Yedek alındı: ' . $r['filename'] . ' (' . backup_human_size($r['size']) . ')',
    ];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

api_register('backup.list', function () {
    if (!function_exists('backup_list')) { return json_err('Yedekleme modülü yüklü değil.', 500); }
    return ['items' => backup_list(), 'supported' => backup_supported()];
}, ['perm' => 'tools.manage', 'methods' => ['POST', 'GET']]);

api_register('backup.delete', function () {
    $name = tools_in_s('filename', '');
    if (!backup_is_valid_name(basename($name))) { return json_err('Geçersiz dosya adı.', 400); }
    $r = backup_delete(basename($name));
    if (!$r['ok']) { return json_err($r['error'], 400); }
    return ['message' => 'Yedek silindi.'];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

api_register('backup.download', function () {
    $name = basename((string)inp('filename', ''));
    if (!backup_is_valid_name($name)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Geçersiz dosya adı.\n";
        exit;
    }
    backup_stream($name);
    exit;
}, ['perm' => 'tools.manage', 'methods' => ['GET'], 'csrf' => false]);

api_register('backup.restore', function () {
    if (!function_exists('backup_restore')) { return json_err('Yedekleme modülü yüklü değil.', 500); }

    // Parola denemesini sınırla
    if (!rate_limit('restore:' . client_ip(), 5, 900)) {
        return json_err('Çok fazla deneme. 15 dakika sonra tekrar deneyin.', 429);
    }
    $user = current_user();
    if (!$user) { return json_err('Oturum açmanız gerekiyor.', 401); }
    $pass = (string)inp('password', '');
    if ($pass === '') { return json_err('Yönetici parolası gerekli.', 400); }
    $hash = (string)qv('SELECT pass_hash FROM users WHERE id = :i', [':i' => (int)$user['id']], '');
    if ($hash === '' || !password_verify($pass, $hash)) {
        log_error('backup.restore: parola doğrulaması başarısız', $user['email'] . ' ip=' . client_ip());
        return json_err('Parola hatalı.', 403);
    }

    if (empty($_FILES['dosya']) || !is_array($_FILES['dosya'])) { return json_err('Dosya seçilmedi.', 400); }
    $f = $_FILES['dosya'];
    if ((int)$f['error'] !== UPLOAD_ERR_OK) {
        return json_err(function_exists('media_upload_error_text')
            ? media_upload_error_text((int)$f['error']) : 'Dosya yüklenemedi.', 400);
    }
    $tmp = (string)$f['tmp_name'];
    if ($tmp === '' || !is_uploaded_file($tmp)) { return json_err('Geçerli bir yükleme değil.', 400); }

    $r = backup_restore($tmp);
    @unlink($tmp);
    if (!$r['ok']) { return json_err($r['error'], 400, ['auto_backup' => $r['auto_backup']]); }
    return [
        'message'     => 'Geri yükleme tamamlandı. ' . $r['message'],
        'auto_backup' => $r['auto_backup'],
    ];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

// ============================================================ medya bakım uçları

api_register('media.stats', function () {
    return media_storage_stats();
}, ['perm' => 'tools.manage', 'methods' => ['POST', 'GET']]);

api_register('media.regen', function () {
    $offset = max(0, tools_in_i('offset', 0));
    $limit = tools_in_i('limit', 25);
    $r = media_regenerate_all($limit, $offset);
    if (function_exists('cache_flush') && $r['regenerated'] > 0) { cache_flush(); }
    return $r;
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

api_register('media.orphans', function () {
    $limit = tools_in_i('limit', 200);
    $items = media_orphan_files($limit);
    $bytes = 0;
    foreach ($items as $i) { $bytes += (int)$i['size']; }
    return ['items' => $items, 'count' => count($items),
            'bytes' => $bytes, 'size_text' => media_human_size($bytes)];
}, ['perm' => 'tools.manage', 'methods' => ['POST', 'GET']]);

api_register('media.delete_orphan', function () {
    $name = tools_in_s('filename', '');
    if ($name === '') { return json_err('Dosya adı gerekli.', 400); }
    $r = media_delete_orphan($name);
    if (!$r['ok']) { return json_err($r['error'], 400); }
    return ['message' => 'Dosya silindi: ' . $r['filename'], 'filename' => $r['filename']];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

// ============================================================ sistem / bakım uçları

api_register('tools.system', function () {
    return tools_system_info();
}, ['perm' => 'tools.manage', 'methods' => ['POST', 'GET']]);

api_register('tools.vacuum', function () {
    $r = tools_vacuum_run();
    if (!$r['ok']) { return json_err($r['error'], 500); }
    return $r;
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

api_register('tools.migrate', function () {
    try {
        $done = migrations_run();
    } catch (Throwable $e) {
        return json_err('Göç uygulanamadı: ' . $e->getMessage(), 500);
    }
    return [
        'applied' => $done,
        'message' => $done ? (count($done) . ' göç uygulandı: ' . implode(', ', $done))
                           : 'Tüm göçler zaten uygulanmış.',
    ];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

// ============================================================ günlük uçları

api_register('logs.tail', function () {
    $lines = max(1, min(500, tools_in_i('lines', 50)));
    $type = tools_in_s('type', '');
    $q = tools_in_s('q', '');
    $file = tools_log_file();
    return [
        'items'  => tools_log_entries($lines, $type, $q),
        'exists' => is_file($file),
        'size'   => is_file($file) ? (int)filesize($file) : 0,
        'types'  => tools_log_types(),
    ];
}, ['perm' => 'tools.manage', 'methods' => ['POST', 'GET']]);

api_register('logs.clear', function () {
    $file = tools_log_file();
    if (is_file($file)) {
        if (@file_put_contents($file, '') === false) {
            return json_err('Günlük dosyası temizlenemedi (izin denetleyin).', 400);
        }
    }
    if (is_file($file . '.1')) { @unlink($file . '.1'); }
    log_error('Hata günlüğü panelden temizlendi', 'kullanıcı=' . (string)arr(current_user(), 'email', ''));
    return ['message' => 'Günlük temizlendi.'];
}, ['perm' => 'tools.manage', 'methods' => ['POST']]);

api_register('logs.download', function () {
    $file = tools_log_file();
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!is_file($file)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Günlük dosyası yok.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="manset-error-' . date('Ymd-His') . '.log"');
    header('Content-Length: ' . (int)filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    file_stream_out($file);
    exit;
}, ['perm' => 'tools.manage', 'methods' => ['GET'], 'csrf' => false]);
