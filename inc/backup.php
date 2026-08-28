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
/**
 * Yedeklerin durduğu dizin.
 *
 * Normalde DB_DIR. Birim testler bunu geçici bir dizine YÖNLENDİREBİLİR:
 * budama davranışını ölçen testler dizindeki `yedek-*` dosyalarını siliyor ve
 * proje kökündeki gerçek geliştirme yedekleri de bu silmeye giriyordu
 * (Ajan-F kendi raporunda bildirdi). Bir testin, ölçtüğü şeyin dışında kalıcı
 * bir şeyi yok etmesi kabul edilemez.
 *
 * Yönlendirme YALNIZ komut satırında geçerlidir: web isteğinde global
 * ayarlansa bile yok sayılır, böylece bu kanca bir saldırı yüzeyi açmaz.
 */
function backup_dir() {
    if (PHP_SAPI === 'cli' && !empty($GLOBALS['manset_backup_dir_override'])) {
        $d = (string)$GLOBALS['manset_backup_dir_override'];
        if (is_dir($d)) { return $d; }
    }
    return DB_DIR;
}

/** Yükleme/geri yükleme boyut sınırı (bayt). */
function backup_max_bytes() { return 200 * 1024 * 1024; }

/**
 * Geçerli yedek dosyası adı deseni (yol bileşeni İÇEREMEZ).
 * `.tar` 1.2-09 ile eklendi: haftalık `uploads` arşivi de db/ altında durur.
 */
function backup_name_pattern() { return '/^yedek-[0-9A-Za-z_\-\.]+\.(sqlite|sql|tar)$/'; }

/** Ad güvenli mi? */
function backup_is_valid_name($name) {
    $name = (string)$name;
    if ($name === '' || strpos($name, "\0") !== false) { return false; }
    if (basename($name) !== $name) { return false; }
    if (strpos($name, '..') !== false) { return false; }
    return (bool)preg_match(backup_name_pattern(), $name);
}

/**
 * Yeni yedek adı üretir.
 *
 * Rastgele son ek 1.2-09'da 4 bayttan 8 bayta çıkarıldı: `db/.htaccess` okumayan
 * sunucularda (nginx) tahmin edilemezlik TEK savunma katmanıdır; 8 haneli hex
 * saniye damgasıyla birlikte kaba kuvvetle bulunabilirdi.
 */
function backup_new_name($ext) {
    $ext = in_array($ext, ['sql', 'tar'], true) ? $ext : 'sqlite';
    $ek = ($ext === 'tar') ? '-uploads' : '';
    return 'yedek-' . date('Ymd-His') . '-' . bin2hex(random_bytes(8)) . $ek . '.' . $ext;
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
        if ($keep > 0) { backup_prune($keep, 'db'); }
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
            'type'      => backup_file_type($name),
            'kind'      => substr($name, -4) === '.tar' ? 'uploads' : 'db',
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

/** Dosya adından tür: 'sql' | 'tar' | 'sqlite'. */
function backup_file_type($name) {
    if (substr($name, -4) === '.sql') { return 'sql'; }
    if (substr($name, -4) === '.tar') { return 'tar'; }
    return 'sqlite';
}

/**
 * Saklama politikası: her TÜR için en yeni $keep yedeği bırakır, gerisini siler.
 *
 * NEDEN TÜR TÜR (1.2-09): tek bir havuzda budanınca, günlük veritabanı yedekleri
 * kısa sürede haftalık `uploads` arşivini listeden itip sildiriyordu — yani
 * `uploads` yedeği pratikte hiç durmuyordu. `backup_keep = 5` artık "5 DB + 5
 * uploads" demektir. Sınırsız büyüme yok, ama tür kaybı da yok.
 *
 * @param int         $keep Tür başına saklanacak adet.
 * @param string|null $kind Yalnız bu türü buda ('db' | 'uploads'); null = hepsi.
 */
function backup_prune($keep = 5, $kind = null) {
    $keep = max(1, (int)$keep);
    $gruplar = ['db' => [], 'uploads' => []];
    foreach (backup_list() as $b) {
        $k = (substr($b['filename'], -4) === '.tar') ? 'uploads' : 'db';
        $gruplar[$k][] = $b;
    }
    $n = 0;
    foreach ($gruplar as $k => $liste) {
        if ($kind !== null && $kind !== $k) { continue; }
        foreach (array_slice($liste, $keep) as $b) {
            $abs = backup_resolve($b['filename']);
            if ($abs !== '' && @unlink($abs)) { $n++; }
        }
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

// =====================================================================
// 1.2-09 — ZAMANLI VE UZAK YEDEK (Ajan-F)
// =====================================================================
/**
 * Buraya kadarki bölüm (Ajan-10) yedeği ELLE almayı çözüyordu. Bir yayıncı
 * bunu düzenli yapmaz; ilk veri kaybında yedek yoktur. Aşağıdaki bölüm üç şey
 * ekler:
 *
 *   1. GÜNLÜK veritabanı yedeği + HAFTALIK `uploads` arşivi — cron_register().
 *   2. UZAK HEDEF: saf PHP `ftp_*`. Paylaşımlı hostingde en yaygın seçenek.
 *      `ftp` uzantısı yoksa özellik zarifçe kapanır ve panel NEDENİNİ söyler.
 *   3. Saklama politikası ve yedek YAŞI (panelde görünür).
 *
 * ---------------------------------------------------------------------
 * SQLITE YEDEĞİ NEDEN TUTARLI (WAL sorusu)
 * WAL kipinde `manset.sqlite` dosyasını kopyalamak YETMEZ: en son işlemler
 * `-wal` dosyasındadır, kopya yarım kalır. Bu yüzden birincil yol
 * `VACUUM INTO` (SQLite 3.27+): kaynağı bir okuma işlemi içinde tarar, WAL'deki
 * değişiklikler DÂHİL bütünlüklü tek bir dosya üretir. Eski SQLite'ta yedek yol
 * `PRAGMA wal_checkpoint(TRUNCATE)` ile WAL'i ana dosyaya işleyip kopyalar.
 * (İkisi de yukarıdaki backup_create_sqlite() içinde, Ajan-10'un yazdığı gibi;
 * 1.2-09 bunu değiştirmez, ÖLÇER — bkz. gelistirme/1.2-ajan-f.md.)
 *
 * MYSQL YEDEĞİ `mysqldump` OLMADAN
 * `SHOW CREATE TABLE` + 200'lük öbeklerle `SELECT *` -> `INSERT`; değerler
 * `PDO::quote()` ile kaçılır. exec() kapalı paylaşımlı hostingde tek yol budur.
 *
 * ---------------------------------------------------------------------
 * CRON BÜTÇESİ
 * `cron_task()` her göreve bir "son an" damgası kurar (cron.php'deki bütçe
 * mekanizması). Buradaki döngüler her adımda cron_over_budget() sorar ve
 * süre dolmadan durur; `uploads` arşivi DURUM DOSYASI ile kaldığı yerden
 * devam eder. Böylece 10 bin dosyalık bir klasör bile turu kilitlemez.
 */

// ------------------------------------------------------------ yedek yaşı

/** Verilen türdeki en yeni yedek ('db' | 'uploads'), yoksa null. */
function backup_latest($kind = 'db') {
    foreach (backup_list() as $b) {
        $k = (substr($b['filename'], -4) === '.tar') ? 'uploads' : 'db';
        if ($k === $kind) { return $b; }
    }
    return null;
}

/** En yeni yedeğin yaşı (saniye). Hiç yedek yoksa -1. */
function backup_age_seconds($kind = 'db') {
    $b = backup_latest($kind);
    if (!$b || (int)$b['mtime'] <= 0) { return -1; }
    return max(0, time() - (int)$b['mtime']);
}

/** Yedek yaşının okunur metni — panelde bu gösterilir. */
function backup_age_text($kind = 'db') {
    $s = backup_age_seconds($kind);
    if ($s < 0) { return 'hiç alınmadı'; }
    if ($s < 3600) { return max(1, (int)round($s / 60)) . ' dakika önce'; }
    if ($s < 86400) { return (int)round($s / 3600) . ' saat önce'; }
    return (int)round($s / 86400) . ' gün önce';
}

/**
 * Yedek yaşı uyarı düzeyi: 'olumlu' | 'uyari' | 'olumsuz'.
 * Eşik, ayarlanan sıklığın katlarıdır: bir tur kaçmak uyarı değildir,
 * üç tur kaçmak cron'un çalışmadığını gösterir.
 */
function backup_age_tone($kind = 'db') {
    $s = backup_age_seconds($kind);
    if ($s < 0) { return 'olumsuz'; }
    $gun = ($kind === 'uploads') ? backup_uploads_every_days() : backup_db_every_days();
    $sinir = $gun * 86400;
    if ($s <= $sinir * 1.5) { return 'olumlu'; }
    if ($s <= $sinir * 3) { return 'uyari'; }
    return 'olumsuz';
}

// ------------------------------------------------------------ zamanlama ayarları

/** Günlük DB yedeği açık mı? */
function backup_auto_db_on() { return setting('backup_auto_db', '1') === '1'; }
/** Kaç günde bir DB yedeği? */
function backup_db_every_days() { return max(1, min(30, (int)setting('backup_db_days', '1'))); }
/** Haftalık uploads arşivi açık mı? */
function backup_auto_uploads_on() { return setting('backup_auto_uploads', '1') === '1'; }
/** Kaç günde bir uploads arşivi? */
function backup_uploads_every_days() { return max(1, min(90, (int)setting('backup_uploads_days', '7'))); }

/** Bir zamanlı görevin sırası geldi mi? */
function backup_due($lastKey, $days) {
    $last = trim((string)setting($lastKey, ''));
    if ($last === '') { return true; }
    $ts = strtotime($last);
    if ($ts === false) { return true; }
    return (time() - $ts) >= ((int)$days * 86400);
}

// ------------------------------------------------------------ cron bütçesi köprüsü

/**
 * Bütçe doldu mu? cron dışında çalışırken (panel düğmesi) daima false döner.
 * @param int $reserve Bu kadar saniye kalmadan "doldu" say.
 */
function backup_over_budget($reserve = 3) {
    if (!function_exists('cron_over_budget')) { return false; }
    return (bool)cron_over_budget($reserve);
}

// ------------------------------------------------------------ FTP: yapılandırma

/** FTP ayarları. Parola `secret` tipiyle saklanır ve HİÇBİR YERDE basılmaz. */
function backup_ftp_config() {
    return [
        'host'    => trim((string)setting('backup_ftp_host', '')),
        'port'    => max(1, min(65535, (int)setting('backup_ftp_port', '21'))),
        'user'    => trim((string)setting('backup_ftp_user', '')),
        'pass'    => (string)setting('backup_ftp_pass', ''),
        'dir'     => trim((string)setting('backup_ftp_dir', '')),
        'passive' => setting('backup_ftp_passive', '1') === '1',
        'ssl'     => setting('backup_ftp_ssl', '0') === '1',
        'timeout' => max(5, min(120, (int)setting('backup_ftp_timeout', '20'))),
    ];
}

/** Uzak gönderim ayarı açık mı? */
function backup_remote_on() { return setting('backup_remote', '0') === '1'; }

/**
 * Kullanıcının yazdığı adresten ana adı çıkarır.
 * "ftp://sunucu.example/yedek" ya da "sunucu.example:21" -> "sunucu.example"
 */
function backup_ftp_host_clean($raw) {
    $h = trim((string)$raw);
    $h = preg_replace('#^[a-z0-9+.-]+://#i', '', $h);
    $h = preg_replace('#[/\\\\?].*$#', '', $h);
    $h = preg_replace('#:\d+$#', '', $h);
    return strtolower(trim($h));
}

/**
 * SSRF kalkanı — FTP hedefini doğrular.
 *
 * FTP hedefi KULLANICIDAN gelen bir adrestir. Doğrulanmazsa panelde
 * `169.254.169.254` ya da `10.0.0.5` yazan biri sunucuyu iç ağa bağlanmaya
 * zorlayabilir. Projede zaten bir SSRF kalkanı var: `safe_remote_target()`
 * (inc/sanitize.php) — YENİSİ YAZILMAZ, o kullanılır. Kalkan URL bekler,
 * bu yüzden ana ad `http://` ile sarılıp verilir; kalkandan dönen değerlerden
 * yalnız DOĞRULANMIŞ IP kullanılır (port ve şema FTP tarafında ayrıdır).
 *
 * Bağlantı ana ada değil, kalkanın döndürdüğü IP'ye kurulur: aradaki DNS
 * yeniden bağlama (rebinding) penceresi böyle kapanır.
 *
 * @return array|false ['host'=>…, 'ip'=>…]
 */
function backup_ftp_target($rawHost) {
    $host = backup_ftp_host_clean($rawHost);
    if ($host === '') { return false; }
    if (!function_exists('safe_remote_target')) {
        if (!is_file(INC_DIR . '/sanitize.php')) { return false; }
        require_once INC_DIR . '/sanitize.php';
    }
    $t = safe_remote_target('http://' . $host . '/');
    if (!$t || empty($t['ip'])) { return false; }
    return ['host' => (string)$t['host'], 'ip' => (string)$t['ip']];
}

/**
 * Uzak yedek bu kurulumda çalışır mı?
 * @return array ['ok'=>bool, 'reason'=>string]  reason KULLANICIYA gösterilir.
 */
function backup_ftp_status() {
    if (!function_exists('ftp_connect')) {
        return ['ok' => false, 'reason' => 'PHP ftp uzantısı bu sunucuda yüklü değil; uzak yedek kapalı. '
              . 'Hosting panelinizden "ftp" uzantısını açtırabilirsiniz. '
              . 'O zamana kadar yedekler yalnız sunucudaki db/ klasöründe tutulur.'];
    }
    if (!backup_remote_on()) {
        return ['ok' => false, 'reason' => 'Uzak yedek ayarlardan kapalı.'];
    }
    $cfg = backup_ftp_config();
    if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
        return ['ok' => false, 'reason' => 'FTP sunucusu, kullanıcı adı ve parola eksiksiz girilmeli.'];
    }
    if ($cfg['ssl'] && !function_exists('ftp_ssl_connect')) {
        return ['ok' => false, 'reason' => 'FTPS istendi ama PHP ftp_ssl_connect desteği yok.'];
    }
    if (backup_ftp_target($cfg['host']) === false) {
        return ['ok' => false, 'reason' => 'FTP sunucu adresi doğrulanamadı. Çözülemeyen adlar ile '
              . 'yerel/iç ağ adresleri (localhost, 10.x, 192.168.x, 169.254.x) güvenlik gereği reddedilir.'];
    }
    return ['ok' => true, 'reason' => 'FTP hedefi hazır.'];
}

// ------------------------------------------------------------ FTP: gönderim

/** Uzak klasör adını temizler (yol geçişi ve boşluk hilesi yok). */
function backup_ftp_clean_dir($dir) {
    $d = trim(str_replace('\\', '/', (string)$dir));
    $d = preg_replace('#[^A-Za-z0-9_\-/\.]#', '', $d);
    $d = preg_replace('#\.\.+#', '.', $d);
    $d = preg_replace('#/+#', '/', $d);
    return trim($d, '/');
}

/**
 * Doğrulanmış hedefe tek dosya yükler — SAF `ftp_*` mekaniği.
 *
 * Kalkan ve ayar okuma ÇAĞIRANIN işidir; bu işlev yalnız protokolü konuşur.
 * (Ayrılmasının nedeni ölçülebilirlik: SSRF kalkanı yerel bir test sunucusunu
 * doğru biçimde reddettiği için, protokol yolu ancak böyle sınanabiliyor.)
 *
 * Parola hiçbir dönüş değerinde, günlükte ya da istisna metninde geçmez.
 *
 * @return array ['ok'=>bool,'error'=>string,'remote'=>string]
 */
function backup_ftp_put_target(array $target, array $cfg, $localPath) {
    $err = function ($m) { return ['ok' => false, 'error' => $m, 'remote' => '']; };
    if (!is_file($localPath)) { return $err('Gönderilecek dosya bulunamadı.'); }
    if (!function_exists('ftp_connect')) { return $err('PHP ftp uzantısı yok.'); }

    $ip = (string)arr($target, 'ip', '');
    if ($ip === '') { return $err('Hedef IP doğrulanmadı.'); }
    $timeout = max(5, (int)arr($cfg, 'timeout', 20));
    $port = max(1, (int)arr($cfg, 'port', 21));

    $conn = !empty($cfg['ssl']) && function_exists('ftp_ssl_connect')
        ? @ftp_ssl_connect($ip, $port, $timeout)
        : @ftp_connect($ip, $port, $timeout);
    if (!$conn) { return $err('FTP sunucusuna bağlanılamadı (' . $port . '/tcp).'); }

    try {
        if (!@ftp_login($conn, (string)arr($cfg, 'user', ''), (string)arr($cfg, 'pass', ''))) {
            // Parola ASLA mesaja konmaz.
            return $err('FTP oturumu açılamadı: kullanıcı adı ya da parola kabul edilmedi.');
        }
        @ftp_set_option($conn, FTP_TIMEOUT_SEC, $timeout);
        if (!empty($cfg['passive'])) { @ftp_pasv($conn, true); }

        $dir = backup_ftp_clean_dir(arr($cfg, 'dir', ''));
        if ($dir !== '' && !@ftp_chdir($conn, $dir)) {
            if (!@ftp_mkdir($conn, $dir) || !@ftp_chdir($conn, $dir)) {
                return $err('Uzak klasör açılamadı: ' . $dir);
            }
        }

        $uzak = basename(str_replace('\\', '/', (string)$localPath));
        $gecici = $uzak . '.part';
        if (!@ftp_put($conn, $gecici, $localPath, FTP_BINARY)) {
            return $err('Dosya yüklenemedi (uzak disk dolu ya da yazma izni yok olabilir).');
        }
        // Yarım kalan bir yükleme "yedek" sanılmasın: ad ancak tamamlanınca konur.
        @ftp_delete($conn, $uzak);
        if (!@ftp_rename($conn, $gecici, $uzak)) {
            return $err('Yükleme tamamlandı ama dosya adlandırılamadı.');
        }
        return ['ok' => true, 'error' => '', 'remote' => ($dir !== '' ? $dir . '/' : '') . $uzak];
    } catch (Throwable $e) {
        return $err('FTP hatası: ' . $e->getMessage());
    } finally {
        @ftp_close($conn);
    }
}

/**
 * Ayarlardaki hedefe bir yedek dosyası gönderir (kalkan + mekanik birlikte).
 * @param string $filename db/ altındaki yedek adı.
 */
function backup_remote_send($filename) {
    $st = backup_ftp_status();
    if (!$st['ok']) { return ['ok' => false, 'error' => $st['reason'], 'remote' => '']; }
    $abs = backup_resolve($filename);
    if ($abs === '') { return ['ok' => false, 'error' => 'Yedek bulunamadı.', 'remote' => '']; }

    $cfg = backup_ftp_config();
    $target = backup_ftp_target($cfg['host']);
    if ($target === false) { return ['ok' => false, 'error' => 'Hedef adres doğrulanamadı.', 'remote' => '']; }

    $r = backup_ftp_put_target($target, $cfg, $abs);
    setting_set('backup_remote_last', now());
    setting_set('backup_remote_last_ok', $r['ok'] ? '1' : '0');
    // NOT: hata metni ayarlara yazılır ve panelde görünür; parola İÇERMEZ.
    setting_set('backup_remote_last_error', $r['ok'] ? '' : (string)$r['error']);
    if (!$r['ok']) { log_error('uzak yedek: ' . $r['error'], basename((string)$filename)); }
    return $r;
}

// ------------------------------------------------------------ uploads arşivi (tar)

/** Arşiv durum dosyası (db/ altında — dışarıya kapalı). */
function backup_uploads_state_file() {
    return rtrim(str_replace('\\', '/', backup_dir()), '/') . '/.uploads-arsiv.json';
}
/** Dosya listesi (satır satır göreli yol). */
function backup_uploads_list_file() {
    return rtrim(str_replace('\\', '/', backup_dir()), '/') . '/.uploads-arsiv-liste.txt';
}

/** Yarım kalmış arşiv durumu; yoksa null. */
function backup_uploads_state() {
    $f = backup_uploads_state_file();
    if (!is_file($f)) { return null; }
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d) || empty($d['part']) || empty($d['final'])) { return null; }
    return $d;
}

/** Durumu yazar. */
function backup_uploads_state_save(array $s) {
    @file_put_contents(backup_uploads_state_file(), (string)json_encode($s, JSON_UNESCAPED_UNICODE));
}

/** Durumu ve (istenirse) yarım dosyayı siler. */
function backup_uploads_state_clear($alsoPart = true) {
    $s = backup_uploads_state();
    if ($alsoPart && $s && !empty($s['part'])) { @unlink((string)$s['part']); }
    @unlink(backup_uploads_state_file());
    @unlink(backup_uploads_list_file());
}

/**
 * `uploads/` altındaki dosyaları liste dosyasına yazar; adet döner.
 * Tek geçiş, bellekte tutulmaz — on binlerce dosyada da güvenlidir.
 */
function backup_uploads_collect() {
    $kok = rtrim(str_replace('\\', '/', UPLOAD_DIR), '/');
    if (!is_dir($kok)) { return 0; }
    $fh = @fopen(backup_uploads_list_file(), 'wb');
    if (!$fh) { return 0; }
    $n = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) { continue; }
            $yol = str_replace('\\', '/', $f->getPathname());
            if (strpos($yol, $kok . '/') !== 0) { continue; }
            $rel = substr($yol, strlen($kok) + 1);
            if ($rel === '') { continue; }
            // `uploads/cache/` sayfa önbelleğidir: yeniden üretilebilir, yedeğe
            // girerse arşivi şişirir. `uploads/.htaccess` ise ARŞİVE GİRER —
            // geri yüklerken unutulursa klasörde PHP çalıştırma koruması gider.
            if (strncmp($rel, 'cache/', 6) === 0) { continue; }
            fwrite($fh, $rel . "\n");
            $n++;
            if ($n >= 200000) { break; }
        }
    } catch (Throwable $e) {
        log_error('backup_uploads_collect: ' . $e->getMessage());
    }
    fclose($fh);
    return $n;
}

/**
 * Tek bir tar (ustar) başlığı üretir. ZipArchive gerektirmez — paylaşımlı
 * hostingde `zip` uzantısı çoğu zaman yoktur, tar ise saf PHP ile yazılabilir
 * ve her yerde açılır (`tar -xf`, 7-Zip, Windows 10+ `tar`).
 *
 * @return string|false 512 baytlık başlık; ad sığmıyorsa false.
 */
function backup_tar_header($name, $size, $mtime) {
    $name = str_replace('\\', '/', (string)$name);
    $prefix = '';
    if (strlen($name) > 100) {
        $kes = strrpos(substr($name, 0, 156), '/');
        if ($kes === false) { return false; }
        $prefix = substr($name, 0, $kes);
        $name = substr($name, $kes + 1);
        if (strlen($name) > 100 || strlen($prefix) > 155) { return false; }
    }
    $h  = str_pad($name, 100, "\0");
    $h .= '0000644' . "\0";                                      // mode
    $h .= '0000000' . "\0";                                      // uid
    $h .= '0000000' . "\0";                                      // gid
    $h .= str_pad(decoct((int)$size), 11, '0', STR_PAD_LEFT) . "\0";
    $h .= str_pad(decoct((int)$mtime), 11, '0', STR_PAD_LEFT) . "\0";
    $h .= '        ';                                            // sağlama yeri
    $h .= '0';                                                   // düz dosya
    $h .= str_repeat("\0", 100);                                 // linkname
    $h .= "ustar\0" . '00';
    $h .= str_repeat("\0", 32) . str_repeat("\0", 32);           // uname / gname
    $h .= str_repeat("\0", 8) . str_repeat("\0", 8);             // dev
    $h .= str_pad($prefix, 155, "\0");
    $h = str_pad($h, 512, "\0");

    $toplam = 0;
    for ($i = 0; $i < 512; $i++) { $toplam += ord($h[$i]); }
    $chk = str_pad(decoct($toplam), 6, '0', STR_PAD_LEFT) . "\0" . ' ';
    return substr($h, 0, 148) . $chk . substr($h, 156);
}

/**
 * `uploads` arşivini bir adım ilerletir; bütçe dolunca kaldığı yerde bırakır.
 *
 * @param int $maxSeconds Bu çağrının üst süre sınırı (0 = yalnız cron bütçesi).
 * @return array ['ok','done','written','filename','error','remaining']
 */
function backup_uploads_tick($maxSeconds = 0) {
    $son = ((int)$maxSeconds > 0) ? (microtime(true) + (int)$maxSeconds) : 0.0;
    $bitti = function () use ($son) {
        if ($son > 0 && microtime(true) >= $son) { return true; }
        return backup_over_budget(3);
    };
    $cikti = ['ok' => true, 'done' => false, 'written' => 0, 'filename' => '',
              'error' => '', 'remaining' => 0];

    $kok = rtrim(str_replace('\\', '/', UPLOAD_DIR), '/');
    if (!is_dir($kok)) { $cikti['ok'] = false; $cikti['error'] = 'uploads klasörü yok.'; return $cikti; }
    if (!is_dir(backup_dir()) || !is_writable(backup_dir())) {
        $cikti['ok'] = false; $cikti['error'] = 'db/ klasörü yazılabilir değil.'; return $cikti;
    }

    $s = backup_uploads_state();
    if (!$s) {
        $adet = backup_uploads_collect();
        if ($adet <= 0) { $cikti['done'] = true; $cikti['error'] = 'Arşivlenecek dosya yok.'; return $cikti; }
        $ad = backup_new_name('tar');
        $s = [
            'final' => rtrim(str_replace('\\', '/', backup_dir()), '/') . '/' . $ad,
            'part'  => rtrim(str_replace('\\', '/', backup_dir()), '/') . '/' . $ad . '.part',
            'index' => 0,
            'total' => $adet,
            'start' => now(),
        ];
        @unlink($s['part']);
        backup_uploads_state_save($s);
    }

    $liste = @fopen(backup_uploads_list_file(), 'rb');
    if (!$liste) {
        backup_uploads_state_clear();
        $cikti['ok'] = false;
        $cikti['error'] = 'Dosya listesi okunamadı; arşiv sıfırlandı.';
        return $cikti;
    }

    // Kaldığı satıra kadar atla
    for ($i = 0; $i < (int)$s['index']; $i++) { if (fgets($liste) === false) { break; } }

    $out = @fopen($s['part'], 'ab');
    if (!$out) {
        fclose($liste);
        $cikti['ok'] = false;
        $cikti['error'] = 'Arşiv dosyası açılamadı.';
        return $cikti;
    }

    $yazilan = 0;
    while (($satir = fgets($liste)) !== false) {
        $rel = rtrim($satir, "\r\n");
        $s['index'] = (int)$s['index'] + 1;
        if ($rel !== '') {
            $abs = $kok . '/' . $rel;
            if (is_file($abs) && is_readable($abs)) {
                $boyut = (int)filesize($abs);
                $bas = backup_tar_header('uploads/' . $rel, $boyut, (int)@filemtime($abs));
                if ($bas === false) {
                    log_error('tar: ad çok uzun, atlandı', $rel);
                } else {
                    $in = @fopen($abs, 'rb');
                    if ($in) {
                        fwrite($out, $bas);
                        $gonderilen = 0;
                        while (!feof($in) && $gonderilen < $boyut) {
                            $p = fread($in, 262144);
                            if ($p === false || $p === '') { break; }
                            if ($gonderilen + strlen($p) > $boyut) { $p = substr($p, 0, $boyut - $gonderilen); }
                            fwrite($out, $p);
                            $gonderilen += strlen($p);
                        }
                        fclose($in);
                        // Dosya okurken küçüldüyse başlıkla tutarlı kal
                        if ($gonderilen < $boyut) { fwrite($out, str_repeat("\0", $boyut - $gonderilen)); }
                        $art = $boyut % 512;
                        if ($art !== 0) { fwrite($out, str_repeat("\0", 512 - $art)); }
                        $yazilan++;
                    }
                }
            }
        }
        if ($bitti()) { break; }
    }
    $son_mu = feof($liste);
    fclose($liste);

    if ($son_mu) {
        fwrite($out, str_repeat("\0", 1024));   // tar bitiş bloğu
        fclose($out);
        if (@rename($s['part'], $s['final'])) {
            @chmod($s['final'], 0640);
            backup_uploads_state_clear(false);
            $keep = (int)setting('backup_keep', '5');
            if ($keep > 0) { backup_prune($keep, 'uploads'); }
            $cikti['done'] = true;
            $cikti['filename'] = basename($s['final']);
            $cikti['written'] = $yazilan;
            return $cikti;
        }
        backup_uploads_state_clear();
        $cikti['ok'] = false;
        $cikti['error'] = 'Arşiv adlandırılamadı.';
        return $cikti;
    }

    fclose($out);
    backup_uploads_state_save($s);
    $cikti['written'] = $yazilan;
    $cikti['remaining'] = max(0, (int)$s['total'] - (int)$s['index']);
    return $cikti;
}

// ------------------------------------------------------------ zamanlı görevler

/**
 * Günlük veritabanı yedeği (+ istenirse uzak gönderim).
 * cron_task() bütçesi içinde çalışır; sırası gelmediyse hiçbir şey yapmaz.
 */
function backup_cron_tick() {
    if (!backup_auto_db_on()) { return ['items' => 0, 'note' => 'kapalı']; }
    if (!backup_due('backup_last_db', backup_db_every_days())) {
        return ['items' => 0, 'note' => 'sırada değil (son: ' . backup_age_text('db') . ')'];
    }
    if (backup_over_budget(5)) { return ['items' => 0, 'note' => 'bütçe yetmedi, sonraki tura kaldı']; }

    $r = backup_create();
    if (!$r['ok']) {
        setting_set('backup_last_error', (string)$r['error']);
        return ['items' => 0, 'ok' => false, 'note' => 'HATA — ' . $r['error']];
    }
    setting_set('backup_last_db', now());
    setting_set('backup_last_error', '');
    $not = '1 veritabanı yedeği (' . backup_human_size($r['size']) . ', ' . $r['method'] . ')';

    if (backup_remote_on() && !backup_over_budget(5)) {
        $u = backup_remote_send($r['filename']);
        $not .= $u['ok'] ? ' · uzağa gönderildi' : ' · uzak gönderim başarısız';
    }
    return ['items' => 1, 'note' => $not];
}

/**
 * Haftalık `uploads` arşivi. Bütçeye sığmazsa kaldığı yerden devam eder,
 * arşiv ancak TAMAMLANINCA `.tar` adını alır (yarım dosya yedek sayılmaz).
 */
function backup_uploads_cron_tick() {
    if (!backup_auto_uploads_on()) { return ['items' => 0, 'note' => 'kapalı']; }
    $devam = backup_uploads_state() !== null;
    if (!$devam && !backup_due('backup_last_uploads', backup_uploads_every_days())) {
        return ['items' => 0, 'note' => 'sırada değil (son: ' . backup_age_text('uploads') . ')'];
    }
    if (backup_over_budget(5)) { return ['items' => 0, 'note' => 'bütçe yetmedi, sonraki tura kaldı']; }

    $r = backup_uploads_tick(0);
    if (!$r['ok']) { return ['items' => 0, 'ok' => false, 'note' => 'HATA — ' . $r['error']]; }
    if (!$r['done']) {
        return ['items' => (int)$r['written'],
                'note'  => (int)$r['written'] . ' dosya arşivlendi, ' . (int)$r['remaining'] . ' kaldı (sonraki tur)'];
    }
    setting_set('backup_last_uploads', now());
    if ($r['filename'] === '') { return ['items' => 0, 'note' => (string)$r['error']]; }

    $not = 'uploads arşivi tamam: ' . $r['filename'];
    if (backup_remote_on() && !backup_over_budget(5)) {
        $u = backup_remote_send($r['filename']);
        $not .= $u['ok'] ? ' · uzağa gönderildi' : ' · uzak gönderim başarısız';
    }
    return ['items' => max(1, (int)$r['written']), 'note' => $not];
}

/**
 * Görev kaydı.
 *
 * `$ucuz` (ağa çıkmaz) yalnız uzak gönderim KAPALIYKEN doğrudur — panel
 * tetiklemeli "poor man's cron" yalnız ucuz görevleri çalıştırır ve bir FTP
 * yüklemesini yönetici isteğinin kuyruğunda yapmak sayfayı bekletirdi.
 * `uploads` arşivi hiçbir zaman ucuz sayılmaz: disk taraması uzundur ve
 * gerçek cron ister.
 */
$backupUzakAcik = true;
try { $backupUzakAcik = backup_remote_on(); } catch (Throwable $e) { $backupUzakAcik = true; }
cron_register('yedek', 'backup_cron_tick', !$backupUzakAcik);
cron_register('yedek-uploads', 'backup_uploads_cron_tick', false);
unset($backupUzakAcik);
