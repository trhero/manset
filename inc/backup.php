<?php
/**
 * Manşet — veritabanı yedekleme ve geri yükleme (Ajan-10).
 *
 * Hedef ortam paylaşımlı hostingtir: `exec()` kapalı, `mysqldump` yok, ZipArchive
 * olmayabilir. Bu yüzden her iki sürücüde de yedek **saf PHP** ile üretilir.
 *
 *   · SQLite → `VACUUM INTO '<hedef>'` (SQLite 3.27+). Desteklenmiyorsa
 *     `PRAGMA wal_checkpoint(TRUNCATE)` + dosya kopyası.
 *   · MySQL  → tablo tablo `SHOW CREATE TABLE` + öbekli `INSERT`; değerler
 *     `PDO::quote()` ile kaçılır.
 *
 * Yedekler `db/` altına `yedek-<tarih>-<rastgele>.sqlite|.sql` adıyla yazılır.
 * `db/.htaccess` klasörü kapatır; ad ayrıca tahmin edilemez olsun diye rastgele
 * son ek taşır (nginx gibi .htaccess okumayan sunucular için ikinci katman).
 *
 * GÜVENLİK
 *   · Dosya adı hiçbir zaman yol içermez: basename() + katı beyaz liste deseni +
 *     realpath() ile DB_DIR altında olduğu doğrulanır.
 *   · Geri yükleme yönetici parolasını yeniden ister (uç noktada doğrulanır) ve
 *     ÖNCE mevcut veritabanının otomatik yedeğini alır.
 *   · Yüklenen dosya imzasıyla doğrulanır; 200 MB üstü reddedilir.
 */

require_once __DIR__ . '/bootstrap.php';

// ============================================================ sabitler / yardımcılar

/** Yedek dosyalarının bulunduğu klasör. */
function backup_dir() { return DB_DIR; }

/** Yükleme/geri yükleme boyut sınırı (bayt). */
function backup_max_bytes() { return 200 * 1024 * 1024; }

/** Geçerli yedek dosyası adı deseni (yol bileşeni İÇEREMEZ). */
function backup_name_pattern() { return '/^yedek-[0-9A-Za-z_\-\.]+\.(sqlite|sql)$/'; }

/** Ad güvenli mi? */
function backup_is_valid_name($name) {
    $name = (string)$name;
    if ($name === '' || strpos($name, "\0") !== false) { return false; }
    if (basename($name) !== $name) { return false; }
    if (strpos($name, '..') !== false) { return false; }
    return (bool)preg_match(backup_name_pattern(), $name);
}

/** Yeni yedek adı üretir. */
function backup_new_name($ext) {
    $ext = ($ext === 'sql') ? 'sql' : 'sqlite';
    return 'yedek-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
}

/**
 * Dosya adını (ya da tam yolu) DB_DIR altındaki gerçek yola çevirir.
 * Doğrulanamazsa '' döner — çağıranlar bunu 404 sayar.
 */
function backup_resolve($nameOrPath) {
    $name = basename(str_replace('\\', '/', (string)$nameOrPath));
    if (!backup_is_valid_name($name)) { return ''; }
    $root = realpath(backup_dir());
    if ($root === false) { return ''; }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $abs = realpath($root . '/' . $name);
    if ($abs === false || !is_file($abs)) { return ''; }
    $abs = str_replace('\\', '/', $abs);
    if (strpos($abs, $root . '/') !== 0) { return ''; }
    return $abs;
}

/** Bayt sayısını okunur metne çevirir. */
function backup_human_size($bytes) {
    if (function_exists('cache_human_size')) { return cache_human_size($bytes); }
    $b = (float)max(0, (int)$bytes);
    if ($b < 1024) { return (int)$b . ' B'; }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    do { $b /= 1024; $i++; } while ($b >= 1024 && $i < 3);
    return str_replace('.', ',', (string)round($b, $b < 10 ? 1 : 0)) . ' ' . $units[$i];
}

/** SQLite dize sabiti kaçışı (yalnız sunucu üretimi yollar için kullanılır). */
function backup_sqlite_quote($s) {
    return str_replace("'", "''", str_replace('\\', '/', (string)$s));
}

/** MySQL tanımlayıcı kaçışı. */
function backup_mysql_ident($s) {
    return '`' . str_replace('`', '``', (string)$s) . '`';
}

/** Etkin SQLite veritabanı dosyasının yolu. */
function backup_sqlite_path() {
    return (string)cfg('db_path', DB_DIR . '/manset.sqlite');
}

// ============================================================ destek durumu

/**
 * Yedekleme bu kurulumda çalışır mı?
 * @return array ['sqlite'=>bool, 'mysql'=>bool, 'reason'=>string]
 */
function backup_supported() {
    $out = ['sqlite' => false, 'mysql' => false, 'reason' => ''];
    $dir = backup_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        $out['reason'] = 'db/ klasörü yazılabilir değil; yedek dosyası oluşturulamaz.';
        return $out;
    }
    if (db_driver() === 'sqlite') {
        $path = backup_sqlite_path();
        if (!is_file($path) || !is_readable($path)) {
            $out['reason'] = 'SQLite veritabanı dosyası okunamıyor: ' . basename($path);
            return $out;
        }
        $out['sqlite'] = true;
        $ver = backup_sqlite_version();
        $out['reason'] = version_compare($ver, '3.27.0', '>=')
            ? 'SQLite ' . $ver . ' — tutarlı kopya için VACUUM INTO kullanılıyor.'
            : 'SQLite ' . $ver . ' — VACUUM INTO yok; WAL birleştirme + dosya kopyası kullanılıyor.';
        return $out;
    }
    $out['mysql'] = true;
    $out['reason'] = 'MySQL dökümü PHP ile üretiliyor (mysqldump gerekmiyor).';
    return $out;
}

/** SQLite kitaplık sürümü. */
function backup_sqlite_version() {
    try { return (string)db()->getAttribute(PDO::ATTR_SERVER_VERSION); }
    catch (Throwable $e) { return '0'; }
}

// ============================================================ yedek alma

/**
 * Yeni yedek üretir.
 * @return array ['ok'=>bool,'path'=>string,'filename'=>string,'size'=>int,'error'=>string,'method'=>string]
 */
function backup_create() {
    $sup = backup_supported();
    if (!$sup['sqlite'] && !$sup['mysql']) {
        return backup_fail($sup['reason'] !== '' ? $sup['reason'] : 'Yedekleme bu sunucuda desteklenmiyor.');
    }
    try {
        $res = (db_driver() === 'sqlite') ? backup_create_sqlite() : backup_create_mysql();
    } catch (Throwable $e) {
        log_error('backup_create: ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
        return backup_fail('Yedek alınamadı: ' . $e->getMessage());
    }
    if ($res['ok']) {
        $keep = (int)setting('backup_keep', '5');
        if ($keep > 0) { backup_prune($keep); }
    }
    return $res;
}

/** Ortak hata dönüşü. */
function backup_fail($message) {
    return ['ok' => false, 'path' => '', 'filename' => '', 'size' => 0, 'error' => $message, 'method' => ''];
}

/** SQLite yedeği. */
function backup_create_sqlite() {
    $name = backup_new_name('sqlite');
    $dest = rtrim(str_replace('\\', '/', backup_dir()), '/') . '/' . $name;
    $pdo = db();
    $method = '';

    try {
        // SQLite 3.27+: WAL dâhil tutarlı, sıkıştırılmış kopya
        $pdo->exec("VACUUM INTO '" . backup_sqlite_quote($dest) . "'");
        $method = 'VACUUM INTO';
    } catch (Throwable $e) {
        // Eski SQLite: WAL'i ana dosyaya işleyip düz kopya al
        @unlink($dest);
        try { $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e2) { /* yok say */ }
        $src = backup_sqlite_path();
        if (!@copy($src, $dest)) {
            return backup_fail('Veritabanı dosyası kopyalanamadı. (VACUUM INTO: ' . $e->getMessage() . ')');
        }
        $method = 'dosya kopyası';
    }

    if (!is_file($dest)) { return backup_fail('Yedek dosyası oluşmadı.'); }
    @chmod($dest, 0640);
    return [
        'ok' => true, 'path' => $dest, 'filename' => $name,
        'size' => (int)filesize($dest), 'error' => '', 'method' => $method,
    ];
}

/** MySQL SQL dökümü (saf PHP, mysqldump gerektirmez). */
function backup_create_mysql() {
    $name = backup_new_name('sql');
    $dest = rtrim(str_replace('\\', '/', backup_dir()), '/') . '/' . $name;
    $fh = @fopen($dest, 'wb');
    if (!$fh) { return backup_fail('Yedek dosyası açılamadı.'); }

    $pdo = db();
    $ok = true;
    try {
        fwrite($fh, "-- Manşet " . MANSET_VERSION . " MySQL yedeği\n");
        fwrite($fh, "-- Tarih: " . now() . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS = 0;\n");
        fwrite($fh, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $r) { $tables[] = (string)$r[0]; }

        foreach ($tables as $t) {
            $qt = backup_mysql_ident($t);
            $create = $pdo->query('SHOW CREATE TABLE ' . $qt)->fetch(PDO::FETCH_NUM);
            fwrite($fh, "\n-- ---------------------------------------------- " . $t . "\n");
            fwrite($fh, 'DROP TABLE IF EXISTS ' . $qt . ";\n");
            fwrite($fh, (string)$create[1] . ";\n");

            // Satırlar öbekler hâlinde (bellek dolmasın)
            $offset = 0;
            $chunk = 200;
            while (true) {
                $rows = $pdo->query('SELECT * FROM ' . $qt . ' LIMIT ' . $chunk . ' OFFSET ' . $offset)
                            ->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) { break; }
                $cols = array_map('backup_mysql_ident', array_keys($rows[0]));
                $values = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $v) {
                        if ($v === null) { $vals[] = 'NULL'; }
                        elseif (is_int($v) || is_float($v)) { $vals[] = (string)$v; }
                        else { $vals[] = $pdo->quote((string)$v); }
                    }
                    $values[] = '(' . implode(',', $vals) . ')';
                }
                fwrite($fh, 'INSERT INTO ' . $qt . ' (' . implode(',', $cols) . ") VALUES\n"
                          . implode(",\n", $values) . ";\n");
                $offset += $chunk;
                if (count($rows) < $chunk) { break; }
            }
        }
        fwrite($fh, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
    } catch (Throwable $e) {
        $ok = false;
        fclose($fh);
        @unlink($dest);
        return backup_fail('Döküm üretilemedi: ' . $e->getMessage());
    }
    fclose($fh);
    if (!$ok || !is_file($dest)) { return backup_fail('Yedek dosyası oluşmadı.'); }
    @chmod($dest, 0640);
    return [
        'ok' => true, 'path' => $dest, 'filename' => $name,
        'size' => (int)filesize($dest), 'error' => '', 'method' => 'PHP SQL dökümü',
    ];
}

// ============================================================ listeleme / silme

/** db/ altındaki yedekleri yeniden eskiye döndürür. */
function backup_list() {
    $out = [];
    foreach ((array)glob(rtrim(backup_dir(), '/\\') . '/yedek-*.*') as $f) {
        $name = basename($f);
        if (!backup_is_valid_name($name) || !is_file($f)) { continue; }
        $mt = (int)@filemtime($f);
        $size = (int)@filesize($f);
        $out[] = [
            'filename'  => $name,
            'size'      => $size,
            'size_text' => backup_human_size($size),
            'mtime'     => $mt,
            'created_at' => $mt ? date('Y-m-d H:i:s', $mt) : '',
            'type'      => substr($name, -4) === '.sql' ? 'sql' : 'sqlite',
        ];
    }
    usort($out, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    return $out;
}

/** Tek yedeği siler. */
function backup_delete($filename) {
    $abs = backup_resolve($filename);
    if ($abs === '') { return ['ok' => false, 'error' => 'Yedek bulunamadı.']; }
    if (!@unlink($abs)) { return ['ok' => false, 'error' => 'Dosya silinemedi (izin denetleyin).']; }
    return ['ok' => true, 'error' => ''];
}

/** En yeni $keep yedeği bırakıp gerisini siler; silinen sayısını döndürür. */
function backup_prune($keep = 5) {
    $keep = max(1, (int)$keep);
    $list = backup_list();
    $n = 0;
    foreach (array_slice($list, $keep) as $b) {
        $abs = backup_resolve($b['filename']);
        if ($abs !== '' && @unlink($abs)) { $n++; }
    }
    return $n;
}

// ============================================================ indirme

/**
 * Yedeği tarayıcıya indirtir. Kendi başlıklarını verir; çağıran sonrasında exit eder.
 * Yetki denetimi (tools.manage) uç noktada yapılır.
 */
function backup_stream($path) {
    $abs = backup_resolve($path);
    if ($abs === '') {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Yedek bulunamadı.\n";
        return;
    }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($abs) . '"');
        header('Content-Length: ' . (int)filesize($abs));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
    }
    readfile($abs);
}

// ============================================================ geri yükleme

/** Metin bir SQL dökümüne benziyor mu? */
function backup_looks_like_sql($head) {
    $h = ltrim((string)$head);
    if ($h === '') { return false; }
    if (strncmp($h, "SQLite format 3\0", 16) === 0) { return false; }
    // İlk 4 KB'de tanıdık bir SQL anahtar sözcüğü aranır
    return (bool)preg_match('/\b(CREATE\s+TABLE|INSERT\s+INTO|DROP\s+TABLE|SET\s+NAMES|SET\s+FOREIGN_KEY_CHECKS)\b/i', $h);
}

/**
 * Yüklenen dosyadan veritabanını geri yükler.
 * Parola doğrulaması uç noktada yapılır (bu fonksiyon dosya işini üstlenir).
 *
 * @return array ['ok'=>bool,'error'=>string,'message'=>string,'auto_backup'=>string]
 */
function backup_restore($tmpPath) {
    $err = function ($m) { return ['ok' => false, 'error' => $m, 'message' => '', 'auto_backup' => '']; };

    $tmpPath = (string)$tmpPath;
    if ($tmpPath === '' || !is_file($tmpPath)) { return $err('Yüklenen dosya bulunamadı.'); }
    $size = (int)@filesize($tmpPath);
    if ($size <= 0) { return $err('Yüklenen dosya boş.'); }
    if ($size > backup_max_bytes()) {
        return $err('Dosya 200 MB sınırını aşıyor (' . backup_human_size($size) . ').');
    }

    $fh = @fopen($tmpPath, 'rb');
    if (!$fh) { return $err('Yüklenen dosya okunamadı.'); }
    $head = (string)fread($fh, 4096);
    fclose($fh);

    $isSqlite = (strncmp($head, "SQLite format 3\0", 16) === 0);
    $isSql = !$isSqlite && backup_looks_like_sql($head);
    if (!$isSqlite && !$isSql) {
        return $err('Dosya tanınmadı: geçerli bir SQLite yedeği ya da SQL dökümü değil.');
    }

    $driver = db_driver();
    if ($driver === 'sqlite' && !$isSqlite) {
        return $err('Etkin veritabanı SQLite. Yalnız .sqlite yedeği geri yüklenebilir.');
    }
    if ($driver === 'mysql' && !$isSql) {
        return $err('Etkin veritabanı MySQL. Yalnız .sql dökümü geri yüklenebilir.');
    }

    // 1) Mevcut durumun güvenlik yedeği — geri yüklemeden ÖNCE
    $auto = backup_create();
    if (!$auto['ok']) {
        return $err('Geri yükleme öncesi güvenlik yedeği alınamadı, işlem durduruldu: ' . $auto['error']);
    }

    // 2) Uygula
    $res = ($driver === 'sqlite') ? backup_restore_sqlite($tmpPath) : backup_restore_mysql($tmpPath);
    if (!$res[0]) {
        return ['ok' => false, 'error' => $res[1], 'message' => '', 'auto_backup' => $auto['filename']];
    }

    settings_all(true);
    log_error('Veritabanı geri yüklendi', 'güvenlik yedeği: ' . $auto['filename']);
    return ['ok' => true, 'error' => '', 'message' => $res[1], 'auto_backup' => $auto['filename']];
}

/**
 * SQLite geri yükleme.
 *
 * Not (bilinçli sapma): Yedek dosyasını canlı dosyanın ÜZERİNE kopyalamak yerine
 * ATTACH + tek işlem (transaction) içinde şema+veri aktarımı yapılır. Gerekçe:
 * `db()` PDO nesnesini bir fonksiyon statiğinde tutar; dışarıdan kapatmanın yolu
 * yoktur ve Windows'ta açık bir SQLite dosyasının üzerine yazmak başarısız olur.
 * ATTACH yolu hem bağlantı kapatmayı gereksiz kılar hem de daha güvenlidir:
 * herhangi bir hata ROLLBACK ile eski veriyi olduğu gibi bırakır. Ayrıca çağıran
 * (backup_restore) bu adımdan önce otomatik yedek almıştır.
 */
function backup_restore_sqlite($srcFile) {
    // Kaynağı bağımsız bir bağlantıyla doğrula
    try {
        $probe = new PDO('sqlite:' . $srcFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $chk = (string)$probe->query('PRAGMA quick_check')->fetchColumn();
        if (strtolower($chk) !== 'ok') { return [false, 'Yedek dosyası bozuk görünüyor: ' . $chk]; }
        $hasSettings = $probe->query('SELECT name FROM sqlite_master WHERE type = \'table\' AND name = \'settings\'')->fetchColumn();
        if (!$hasSettings) { return [false, 'Yedekte settings tablosu yok — bu bir Manşet yedeği değil.']; }
        $probe = null;
    } catch (Throwable $e) {
        return [false, 'Yedek dosyası açılamadı: ' . $e->getMessage()];
    }

    $pdo = db();
    $attached = false;
    $tableCount = 0;
    try {
        // PRAGMA'lar işlem dışında verilmeli
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec("ATTACH DATABASE '" . backup_sqlite_quote($srcFile) . "' AS yedek");
        $attached = true;

        $objects = $pdo->query('SELECT type, name, sql FROM yedek.sqlite_master
                                WHERE sql IS NOT NULL AND name NOT LIKE \'sqlite@_%\' ESCAPE \'@\'')
                       ->fetchAll(PDO::FETCH_ASSOC);
        $tables = [];
        foreach ($objects as $o) { if ($o['type'] === 'table') { $tables[] = (string)$o['name']; } }
        if (!$tables) { throw new RuntimeException('Yedekte hiç tablo yok.'); }
        $tableCount = count($tables);

        $pdo->beginTransaction();

        // Mevcut nesneleri düşür: önce görünüm/tetikleyici/indeks, sonra tablolar
        $current = $pdo->query('SELECT type, name FROM main.sqlite_master
                                WHERE name NOT LIKE \'sqlite@_%\' ESCAPE \'@\'')->fetchAll(PDO::FETCH_ASSOC);
        $order = ['trigger' => 0, 'view' => 1, 'index' => 2, 'table' => 3];
        usort($current, function ($a, $b) use ($order) {
            $ka = isset($order[$a['type']]) ? $order[$a['type']] : 9;
            $kb = isset($order[$b['type']]) ? $order[$b['type']] : 9;
            return $ka <=> $kb;
        });
        foreach ($current as $o) {
            $kind = strtoupper((string)$o['type']);
            if (!in_array($kind, ['TABLE', 'INDEX', 'TRIGGER', 'VIEW'], true)) { continue; }
            $q = '"' . str_replace('"', '""', (string)$o['name']) . '"';
            try { $pdo->exec('DROP ' . $kind . ' IF EXISTS main.' . $q); } catch (Throwable $e) { /* bağımlı nesne zaten düştü */ }
        }

        // Yedekteki tabloları yarat ve doldur
        foreach ($objects as $o) {
            if ($o['type'] !== 'table') { continue; }
            $pdo->exec((string)$o['sql']);
        }
        foreach ($tables as $t) {
            $q = '"' . str_replace('"', '""', $t) . '"';
            $pdo->exec('INSERT INTO main.' . $q . ' SELECT * FROM yedek.' . $q);
        }
        // İndeks / görünüm / tetikleyiciler en son (veri yüklemesi hızlansın)
        foreach ($objects as $o) {
            if ($o['type'] === 'table') { continue; }
            try { $pdo->exec((string)$o['sql']); } catch (Throwable $e) { /* yinelenen indeks vb. */ }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable $e2) { /* yok say */ }
        if ($attached) { try { $pdo->exec('DETACH DATABASE yedek'); } catch (Throwable $e2) { /* yok say */ } }
        try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (Throwable $e2) { /* yok say */ }
        log_error('backup_restore_sqlite: ' . $e->getMessage());
        return [false, 'Geri yükleme başarısız; veritabanı değiştirilmedi. (' . $e->getMessage() . ')'];
    }

    try { $pdo->exec('DETACH DATABASE yedek'); } catch (Throwable $e) { /* yok say */ }
    try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (Throwable $e) { /* yok say */ }
    if (function_exists('cache_flush')) { cache_flush(); }
    return [true, $tableCount . ' tablo geri yüklendi.'];
}

/**
 * SQL dökümünü ifade ifade çalıştırır (MySQL).
 * MySQL'de DDL işlem dışıdır; bu yüzden çağıran önce otomatik yedek alır.
 */
function backup_restore_mysql($srcFile) {
    $pdo = db();
    $count = 0;
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (backup_sql_statements($srcFile) as $sql) {
            $sql = trim($sql);
            if ($sql === '') { continue; }
            $pdo->exec($sql);
            $count++;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $e) {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e2) { /* yok say */ }
        log_error('backup_restore_mysql: ' . $e->getMessage());
        return [false, 'Geri yükleme ' . $count . '. ifadede durdu: ' . $e->getMessage()
                     . ' — otomatik güvenlik yedeğinden dönebilirsiniz.'];
    }
    if (function_exists('cache_flush')) { cache_flush(); }
    return [true, $count . ' SQL ifadesi çalıştırıldı.'];
}

/**
 * SQL dosyasını ifadelere böler (generator).
 * Tırnak, ters tırnak, kaçış ve yorum satırlarına duyarlı basit ayrıştırıcı;
 * kendi ürettiğimiz dökümler için fazlasıyla yeterlidir.
 */
function backup_sql_statements($file) {
    $fh = @fopen($file, 'rb');
    if (!$fh) { return; }
    $buf = '';
    $quote = '';   // ' " veya `
    $escaped = false;
    while (($chunk = fread($fh, 65536)) !== false && $chunk !== '') {
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $c = $chunk[$i];
            if ($quote !== '') {
                $buf .= $c;
                if ($escaped) { $escaped = false; continue; }
                if ($c === '\\' && $quote !== '`') { $escaped = true; continue; }
                if ($c === $quote) { $quote = ''; }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $buf .= $c; continue; }
            if ($c === ';') {
                $stmt = backup_sql_strip_comments($buf);
                $buf = '';
                if (trim($stmt) !== '') { yield $stmt; }
                continue;
            }
            $buf .= $c;
        }
        if (feof($fh)) { break; }
    }
    fclose($fh);
    $stmt = backup_sql_strip_comments($buf);
    if (trim($stmt) !== '') { yield $stmt; }
}

/** İfade başındaki `--` ve `#` yorum satırlarını atar. */
function backup_sql_strip_comments($sql) {
    $out = [];
    foreach (preg_split('/\r\n|\n|\r/', (string)$sql) as $line) {
        $t = ltrim($line);
        if ($t === '' || strncmp($t, '--', 2) === 0 || strncmp($t, '#', 1) === 0) { continue; }
        $out[] = $line;
    }
    return implode("\n", $out);
}
