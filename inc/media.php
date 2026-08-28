<?php
/**
 * Manşet — medya altyapısı (yükleme, doğrulama, varyant üretimi, silme).
 * Sahip: Ajan-2.
 *
 * Güvenlik ilkeleri (Ajan-11 denetimi için özet):
 *  1. Beyaz liste HEM uzantı HEM gerçek MIME (finfo + getimagesize) ile doğrulanır.
 *     İkisi uyuşmazsa dosya reddedilir.
 *  2. SVG kesinlikle reddedilir (beyaz listede yok + ayrıca açık ret).
 *  3. Çift uzantı (x.php.jpg) reddedilir; dosya adı her hâlükârda yeniden üretilir.
 *  4. Hedef yol daima UPLOAD_DIR altındadır; realpath ile dışarı çıkılamadığı doğrulanır.
 *  5. $_FILES hata kodları ve is_uploaded_file() denetlenir.
 *  6. Boyut sınırı settings['media_max_mb'] (varsayılan 8 MB) + piksel bombası koruması.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';

// ============================================================ sabitler

/** İzin verilen gerçek MIME => tek doğru uzantı. */
function media_allowed_types() {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
}

/** Varyant genişlikleri (yalnız küçültme uygulanır). */
function media_variant_widths() {
    return ['thumb' => 400, 'medium' => 800, 'large' => 1280];
}

/** Kesin reddedilen uzantılar (savunmanın ikinci katmanı). */
function media_blocked_ext() {
    return [
        'svg', 'svgz', 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar',
        'htaccess', 'htm', 'html', 'shtml', 'xhtml', 'xml', 'js', 'mjs', 'json',
        'exe', 'com', 'bat', 'cmd', 'sh', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp',
    ];
}

/** 'jpeg' → 'jpg' gibi uzantı eş adlarını normalleştirir. */
function media_normalize_ext($ext) {
    $ext = strtolower(trim((string)$ext, " \t\n\r\0.\x0B"));
    $alias = ['jpeg' => 'jpg', 'jpe' => 'jpg', 'jfif' => 'jpg'];
    return isset($alias[$ext]) ? $alias[$ext] : $ext;
}

/** Yükleme boyut sınırı (bayt). settings['media_max_mb'], varsayılan 8. */
function media_max_bytes() {
    $mb = (int)setting('media_max_mb', '8');
    if ($mb <= 0) { $mb = 8; }
    return min($mb, 64) * 1024 * 1024;
}

/** $_FILES hata kodunu Türkçe mesaja çevirir. */
function media_upload_error_text($code) {
    switch ((int)$code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return 'Dosya sunucu sınırından büyük.';
        case UPLOAD_ERR_PARTIAL:    return 'Dosya yalnızca kısmen yüklendi, tekrar deneyin.';
        case UPLOAD_ERR_NO_FILE:    return 'Dosya seçilmedi.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Sunucuda geçici klasör yok.';
        case UPLOAD_ERR_CANT_WRITE: return 'Sunucu diske yazamadı.';
        case UPLOAD_ERR_EXTENSION:  return 'Yükleme bir PHP eklentisi tarafından durduruldu.';
    }
    return 'Dosya yüklenemedi.';
}

/** Bayt sayısını okunur metne çevirir: 1536 → '1,5 KB' */
function media_human_size($bytes) {
    $b = (float)max(0, (int)$bytes);
    if ($b < 1024) { return (int)$b . ' B'; }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    do { $b /= 1024; $i++; } while ($b >= 1024 && $i < 3);
    $r = round($b, $b < 10 ? 1 : 0);
    return str_replace('.', ',', (string)$r) . ' ' . $units[$i];
}

// ============================================================ yol yardımcıları

/** Göreli yolun UPLOAD_DIR içinde kaldığını doğrular; mutlak yolu ya da '' döndürür. */
function media_safe_path($relative) {
    $rel = ltrim(str_replace('\\', '/', (string)$relative), '/');
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, "\0") !== false) { return ''; }
    if (!preg_match('#^[A-Za-z0-9/_\-\.]{1,255}$#', $rel)) { return ''; }
    $root = realpath(UPLOAD_DIR);
    if ($root === false) { return ''; }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $abs = $root . '/' . $rel;
    // Dosya henüz yoksa üst klasör üzerinden doğrula
    $probe = is_file($abs) ? realpath($abs) : realpath(dirname($abs));
    if ($probe === false) { return ''; }
    $probe = str_replace('\\', '/', $probe);
    if (strpos($probe . '/', $root . '/') !== 0) { return ''; }
    return $abs;
}

/** Yükleme köküne göre klasörü hazırlar ve doğrular; mutlak yol ya da '' döndürür. */
function media_prepare_dir($subdir) {
    $sub = trim(str_replace('\\', '/', (string)$subdir), '/');
    if ($sub !== '' && !preg_match('#^[0-9]{4}/[0-9]{2}$#', $sub)) { return ''; }
    $dir = rtrim(UPLOAD_DIR . '/' . $sub, '/');
    if (!ensure_dir($dir)) { return ''; }
    $root = realpath(UPLOAD_DIR);
    $real = realpath($dir);
    if ($root === false || $real === false) { return ''; }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $real = rtrim(str_replace('\\', '/', $real), '/');
    if (strpos($real . '/', $root . '/') !== 0) { return ''; }
    return $dir;
}

// ============================================================ GD yardımcıları

/** GD kullanılabilir mi? */
function media_gd_available() {
    return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
}

/** Dosyayı GD kaynağına yükler (mime'a göre). Başarısızsa null. */
function media_gd_load($absPath, $mime) {
    switch ($mime) {
        case 'image/jpeg': return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($absPath) : null;
        case 'image/png':  return function_exists('imagecreatefrompng')  ? @imagecreatefrompng($absPath)  : null;
        case 'image/gif':  return function_exists('imagecreatefromgif')  ? @imagecreatefromgif($absPath)  : null;
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : null;
    }
    return null;
}

/** GD kaynağını mime'a uygun biçimde diske yazar. */
function media_gd_save($img, $absPath, $mime) {
    switch ($mime) {
        case 'image/jpeg': return function_exists('imagejpeg') ? (bool)@imagejpeg($img, $absPath, 84) : false;
        case 'image/png':  return function_exists('imagepng')  ? (bool)@imagepng($img, $absPath, 6)   : false;
        case 'image/gif':  return function_exists('imagegif')  ? (bool)@imagegif($img, $absPath)      : false;
        case 'image/webp': return function_exists('imagewebp') ? (bool)@imagewebp($img, $absPath, 82) : false;
    }
    return false;
}

/**
 * Küçültülmüş varyantları üretir. GD yoksa boş dizi döner (hata değildir).
 *
 * @param string $absPath  Orijinalin mutlak yolu
 * @param string $filename UPLOAD_DIR'e göre göreli ad, örn '2026/08/foto-a1b2c3d4.jpg'
 * @return array ['thumb'=>'…-thumb.jpg', 'medium'=>…, 'large'=>…, 'webp'=>['thumb'=>'…-thumb.webp', …]]
 */
function media_make_variants($absPath, $filename) {
    $out = [];
    if (!media_gd_available() || !is_file($absPath)) { return $out; }

    $info = @getimagesize($absPath);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) { return $out; }
    $srcW = (int)$info[0];
    $srcH = (int)$info[1];
    $mime = strtolower((string)arr($info, 'mime', ''));

    // Hareketli GIF küçültülürse animasyon kaybolur — orijinali koruyup varyant üretmiyoruz.
    if ($mime === 'image/gif') { return $out; }
    // Piksel bombası koruması (bellek tükenmesin)
    if ($srcW * $srcH > 50000000) { return $out; }

    $src = media_gd_load($absPath, $mime);
    if (!$src) { return $out; }

    $dot = strrpos($filename, '.');
    if ($dot === false) { imagedestroy($src); return $out; }
    $stemRel = substr($filename, 0, $dot);
    $ext = strtolower(substr($filename, $dot)); // '.jpg'

    $webp = [];
    $canWebp = function_exists('imagewebp');

    foreach (media_variant_widths() as $key => $targetW) {
        if ($srcW <= $targetW) { continue; } // yalnız küçültme
        $w = $targetW;
        $h = max(1, (int)round($srcH * ($targetW / $srcW)));

        $dst = @imagecreatetruecolor($w, $h);
        if (!$dst) { continue; }
        if ($mime === 'image/png' || $mime === 'image/webp') {
            @imagealphablending($dst, false);
            @imagesavealpha($dst, true);
            $tr = @imagecolorallocatealpha($dst, 0, 0, 0, 127);
            if ($tr !== false) { @imagefilledrectangle($dst, 0, 0, $w, $h, $tr); }
        }
        @imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

        // Ana varyant: inc/view.php içindeki media_variant_file() 'ad-thumb.jpg' desenini bekler
        $relV = $stemRel . '-' . $key . $ext;
        $absV = media_safe_path($relV);
        if ($absV !== '' && media_gd_save($dst, $absV, $mime)) {
            @chmod($absV, 0644);
            $out[$key] = $relV;
        }
        // Ek WEBP kopyası (kaynak zaten webp değilse)
        if ($canWebp && $ext !== '.webp') {
            $relW = $stemRel . '-' . $key . '.webp';
            $absW = media_safe_path($relW);
            if ($absW !== '' && @imagewebp($dst, $absW, 82)) {
                @chmod($absW, 0644);
                $webp[$key] = $relW;
            }
        }
        imagedestroy($dst);
    }
    imagedestroy($src);

    if ($webp) { $out['webp'] = $webp; }
    return $out;
}

// ============================================================ yükleme

/**
 * Tek dosyalık yükleme kaydeder.
 *
 * @param array  $file $_FILES['x'] biçiminde dizi (name, tmp_name, error, size)
 * @param string $alt  Alt metin
 * @return array ['ok'=>bool,'id'=>int,'filename'=>string,'url'=>string,'error'=>string]
 */
function media_save_upload(array $file, $alt = '') {
    $fail = function ($msg) {
        return ['ok' => false, 'id' => 0, 'filename' => '', 'url' => '', 'error' => $msg];
    };

    // --- 1) $_FILES hata kodu
    $err = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) { return $fail(media_upload_error_text($err)); }

    $tmp = isset($file['tmp_name']) ? (string)$file['tmp_name'] : '';
    if ($tmp === '' || !is_file($tmp)) { return $fail('Geçici dosya bulunamadı.'); }

    // --- 2) Gerçekten HTTP yüklemesi mi?
    // Yerel içe aktarım (RSS görsel indirme vb.) media_import_file() üzerinden yapılır;
    // $_FILES dizisine dışarıdan anahtar eklenemediği için bu bayrak istemciden gelemez.
    $trusted = isset($file['manset_trusted']) && $file['manset_trusted'] === true;
    if (!$trusted && !is_uploaded_file($tmp)) {
        log_error('media: is_uploaded_file başarısız', $tmp);
        return $fail('Dosya geçerli bir yükleme değil.');
    }

    // --- 3) Boyut
    $size = (int)@filesize($tmp);
    if ($size <= 0) { return $fail('Dosya boş.'); }
    $max = media_max_bytes();
    if ($size > $max) {
        return $fail('Dosya çok büyük. En fazla ' . (int)round($max / 1048576) . ' MB yükleyebilirsiniz.');
    }

    // --- 4) Dosya adı ve uzantı denetimi
    $origName = isset($file['name']) ? (string)$file['name'] : '';
    $base = basename(str_replace('\\', '/', $origName));
    if ($base === '' || strpos($base, "\0") !== false) { return $fail('Geçersiz dosya adı.'); }

    $dot = strrpos($base, '.');
    if ($dot === false || $dot === 0) { return $fail('Dosyanın uzantısı yok.'); }
    $ext = media_normalize_ext(substr($base, $dot + 1));
    $stem = substr($base, 0, $dot);

    $allowed = media_allowed_types();          // mime => uzantı
    $allowedExt = array_values($allowed);      // ['jpg','png','gif','webp']

    if (in_array($ext, media_blocked_ext(), true)) {
        return $fail($ext === 'svg' || $ext === 'svgz'
            ? 'SVG yüklemesi güvenlik nedeniyle kapalıdır. PNG kullanın.'
            : 'Bu dosya türü yüklenemez.');
    }
    if (!in_array($ext, $allowedExt, true)) {
        return $fail('Yalnız JPG, PNG, GIF ve WEBP dosyaları yüklenebilir.');
    }
    // Çift uzantı: 'x.php.jpg' → gövde 'x.php' ile biter. Sayıyla başlayan
    // parçalar (foto.2026.jpg) meşru sayılır, harfle başlayanlar reddedilir.
    if (preg_match('/\.[A-Za-z][A-Za-z0-9]{0,5}$/', $stem)) {
        return $fail('Çift uzantılı dosya adları kabul edilmiyor.');
    }

    // --- 5) Gerçek MIME: finfo + getimagesize birlikte
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)@finfo_file($fi, $tmp); @finfo_close($fi); }
    }
    if ($mime === '' && function_exists('mime_content_type')) { $mime = (string)@mime_content_type($tmp); }
    $mime = strtolower(trim(explode(';', $mime)[0]));

    $info = @getimagesize($tmp);
    if (!is_array($info) || empty($info[0]) || empty($info[1])) {
        return $fail('Dosya geçerli bir görsel değil.');
    }
    $imgMime = strtolower((string)arr($info, 'mime', ''));
    if ($mime === '') { $mime = $imgMime; } // fileinfo yoksa getimagesize belirleyicidir

    if (!isset($allowed[$mime])) { return $fail('Dosya içeriği izinli bir görsel türü değil.'); }
    if ($imgMime !== $mime) { return $fail('Dosya içeriği ile bildirilen tür uyuşmuyor.'); }
    if ($allowed[$mime] !== $ext) { return $fail('Dosya uzantısı içeriğiyle uyuşmuyor.'); }

    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w * $h > 80000000) { return $fail('Görsel çözünürlüğü çok yüksek.'); }

    // --- 6) Hedef yol (Y/m alt klasörü) — daima UPLOAD_DIR altında
    $sub = date('Y/m');
    $dir = media_prepare_dir($sub);
    if ($dir === '') { return $fail('Yükleme klasörü hazırlanamadı.'); }

    $slug = slugify($stem, 60);
    $rel = '';
    $abs = '';
    for ($try = 0; $try < 6; $try++) {
        $cand = $sub . '/' . $slug . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $candAbs = media_safe_path($cand);
        if ($candAbs === '') { return $fail('Hedef yol doğrulanamadı.'); }
        if (!file_exists($candAbs)) { $rel = $cand; $abs = $candAbs; break; }
    }
    if ($rel === '') { return $fail('Benzersiz dosya adı üretilemedi.'); }

    // --- 7) Taşı
    $moved = $trusted ? @copy($tmp, $abs) : @move_uploaded_file($tmp, $abs);
    if (!$moved || !is_file($abs)) { return $fail('Dosya kaydedilemedi. Klasör yazma izinlerini denetleyin.'); }
    @chmod($abs, 0644);

    // Yazımdan sonra son bir kez konum doğrulaması
    if (media_safe_path($rel) === '') {
        @unlink($abs);
        return $fail('Kayıt sonrası yol doğrulaması başarısız.');
    }

    // --- 8) Varyantlar (GD yoksa boş kalır, hata değildir)
    $variants = media_make_variants($abs, $rel);

    // --- 9) Veritabanı
    $id = db_insert('media', [
        'filename'   => $rel,
        'alt'        => sanitize_line((string)$alt, 255),
        'mime'       => $mime,
        'width'      => $w,
        'height'     => $h,
        'size'       => $size,
        'variants'   => $variants ? (string)json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        'created_at' => now(),
    ]);

    return [
        'ok'       => true,
        'id'       => (int)$id,
        'filename' => $rel,
        'url'      => url_upload($rel),
        'error'    => '',
    ];
}

/**
 * Sunucudaki yerel bir dosyayı kütüphaneye alır (RSS görsel indirme gibi iç kullanımlar).
 * HTTP yüklemesi olmadığı için is_uploaded_file() denetimi atlanır; dosya KOPYALANIR.
 */
function media_import_file($absTempPath, $originalName, $alt = '') {
    return media_save_upload([
        'name'            => (string)$originalName,
        'tmp_name'        => (string)$absTempPath,
        'error'           => UPLOAD_ERR_OK,
        'size'            => (int)@filesize($absTempPath),
        'manset_trusted'  => true,
    ], $alt);
}

// ============================================================ listeleme

/** Satırı arayüz için zenginleştirir (url, thumb, çözülmüş varyantlar). */
function media_decorate(array $row) {
    $v = [];
    if (!empty($row['variants'])) {
        $d = json_decode((string)$row['variants'], true);
        if (is_array($d)) { $v = $d; }
    }
    $row['variants'] = $v;
    $row['url'] = url_upload($row['filename']);

    $thumb = (isset($v['thumb']) && is_string($v['thumb'])) ? $v['thumb'] : '';
    if ($thumb === '' && function_exists('media_variant_file')) {
        $thumb = media_variant_file((string)$row['filename'], 'thumb');
    }
    $row['thumb'] = $thumb !== '' ? url_upload($thumb) : $row['url'];
    $row['size_text'] = media_human_size((int)arr($row, 'size', 0));
    return $row;
}

/** Arama teriminden LIKE joker karakterlerini ayıklar. */
function media_like_term($q) {
    $q = sanitize_line((string)$q, 120);
    return str_replace(['%', '_'], ' ', $q);
}

/** Medya listesi (yeniden eskiye). */
function media_list($q = '', $limit = 60, $offset = 0) {
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    $term = media_like_term($q);
    $where = '';
    $p = [];
    if ($term !== '') {
        $where = ' WHERE (filename LIKE :q OR alt LIKE :q)';
        $p[':q'] = '%' . $term . '%';
    }
    $rows = qa('SELECT id, filename, alt, mime, width, height, size, variants, created_at FROM media'
        . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
    $out = [];
    foreach ($rows as $r) { $out[] = media_decorate($r); }
    return $out;
}

/** Aramaya uyan medya sayısı. */
function media_count($q = '') {
    $term = media_like_term($q);
    if ($term === '') { return (int)qv('SELECT COUNT(*) FROM media', [], 0); }
    return (int)qv('SELECT COUNT(*) FROM media WHERE (filename LIKE :q OR alt LIKE :q)', [':q' => '%' . $term . '%'], 0);
}

/** Kütüphanedeki toplam bayt. */
function media_total_size() {
    return (int)qv('SELECT COALESCE(SUM(size), 0) FROM media', [], 0);
}

/** Tek kayıt (zenginleştirilmiş). */
function media_get($id) {
    $row = q1('SELECT id, filename, alt, mime, width, height, size, variants, created_at FROM media WHERE id = :i', [':i' => (int)$id]);
    return $row ? media_decorate($row) : null;
}

// ============================================================ silme

/**
 * Kaydı ve diskteki tüm türevlerini siler.
 * @return array ['ok'=>bool,'error'=>string]
 */
function media_delete($id) {
    $id = (int)$id;
    $row = q1('SELECT id, filename, variants FROM media WHERE id = :i', [':i' => $id]);
    if (!$row) { return ['ok' => false, 'error' => 'Kayıt bulunamadı.']; }

    $files = [(string)$row['filename']];

    // variants JSON'undaki yollar
    if (!empty($row['variants'])) {
        $v = json_decode((string)$row['variants'], true);
        if (is_array($v)) {
            foreach ($v as $key => $val) {
                if (is_string($val)) { $files[] = $val; }
                elseif (is_array($val)) { foreach ($val as $vv) { if (is_string($vv)) { $files[] = $vv; } } }
            }
        }
    }
    // JSON eksik/bozuksa desene göre de süpür
    $fn = (string)$row['filename'];
    $dotPos = strrpos($fn, '.');
    if ($dotPos !== false) {
        $stem = substr($fn, 0, $dotPos);
        $ext = substr($fn, $dotPos);
        foreach (array_keys(media_variant_widths()) as $key) {
            $files[] = $stem . '-' . $key . $ext;
            $files[] = $stem . '-' . $key . '.webp';
        }
    }

    foreach (array_unique($files) as $rel) {
        $abs = media_safe_path($rel);
        if ($abs !== '' && is_file($abs)) { @unlink($abs); }
    }

    q('DELETE FROM media WHERE id = :i', [':i' => $id]);
    return ['ok' => true, 'error' => ''];
}

/** Alt metni günceller. */
function media_update_alt($id, $alt) {
    $id = (int)$id;
    if (!qv('SELECT 1 FROM media WHERE id = :i', [':i' => $id])) {
        return ['ok' => false, 'error' => 'Kayıt bulunamadı.'];
    }
    $clean = sanitize_line((string)$alt, 255);
    db_update('media', ['alt' => $clean], 'id = :id', [':id' => $id]);
    return ['ok' => true, 'alt' => $clean, 'error' => ''];
}

// ============================================================================
// Ajan-10 eklemeleri — bakım araçları (varyant yenileme, sahipsiz dosya, depo)
// Ajan-2'nin imzaları değiştirilmedi; yalnız yeni fonksiyon eklendi.
// ============================================================================

/** Bakımda yok sayılan kök düzeyi klasörler ve dosyalar. */
function media_maintenance_skip() {
    return ['cache' => true, '.htaccess' => true, 'index.html' => true, 'index.php' => true, '.gitignore' => true];
}

/** Bir dosya adı varyant deseni taşıyor mu? ('foto-thumb.jpg' / 'foto-medium.webp') */
function media_is_variant_name($rel) {
    $base = basename((string)$rel);
    $keys = implode('|', array_keys(media_variant_widths()));
    return (bool)preg_match('/-(' . $keys . ')\.[A-Za-z0-9]{2,5}$/', $base);
}

/**
 * Tek kaydın varyantlarını yeniden üretir.
 *
 * @return array ['ok'=>bool,'id'=>int,'filename'=>string,'variants'=>array,
 *                'skipped'=>bool,'reason'=>string,'error'=>string]
 */
function media_regenerate_variants($id) {
    $id = (int)$id;
    $row = q1('SELECT id, filename, mime, variants FROM media WHERE id = :i', [':i' => $id]);
    $out = ['ok' => false, 'id' => $id, 'filename' => '', 'variants' => [],
            'skipped' => false, 'reason' => '', 'error' => ''];
    if (!$row) { $out['error'] = 'Kayıt bulunamadı.'; return $out; }

    $rel = (string)$row['filename'];
    $out['filename'] = $rel;

    if (!media_gd_available()) {
        $out['ok'] = true; $out['skipped'] = true;
        $out['reason'] = 'GD eklentisi yok — varyant üretimi atlandı.';
        return $out;
    }
    $abs = media_safe_path($rel);
    if ($abs === '' || !is_file($abs)) {
        $out['ok'] = true; $out['skipped'] = true;
        $out['reason'] = 'Özgün dosya diskte yok.';
        return $out;
    }
    if (strtolower((string)$row['mime']) === 'image/gif') {
        $out['ok'] = true; $out['skipped'] = true;
        $out['reason'] = 'Hareketli GIF — animasyon korunuyor, varyant üretilmiyor.';
        return $out;
    }

    $info = @getimagesize($abs);
    $variants = media_make_variants($abs, $rel);
    if (!$variants) {
        $out['ok'] = true; $out['skipped'] = true;
        $out['reason'] = 'Görsel varyant eşiklerinin altında (küçültme gerekmiyor).';
    } else {
        $out['ok'] = true;
        $out['variants'] = $variants;
    }

    // Boyut/ölçü bilgileri de tazelensin (dosya elle değiştirilmiş olabilir)
    $data = [
        'variants' => $variants ? (string)json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        'size'     => (int)@filesize($abs),
    ];
    if (is_array($info) && !empty($info[0]) && !empty($info[1])) {
        $data['width'] = (int)$info[0];
        $data['height'] = (int)$info[1];
    }
    db_update('media', $data, 'id = :id', [':id' => $id]);
    return $out;
}

/**
 * Toplu varyant yenileme. Panelden parça parça (offset ile) çağrılır ki
 * büyük kütüphanelerde istek zaman aşımına uğramasın.
 *
 * @return array ['ok','total','done','next_offset','regenerated','skipped','failed','notes']
 */
function media_regenerate_all($limit = 50, $offset = 0) {
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    $total = (int)qv('SELECT COUNT(*) FROM media', [], 0);

    $rows = qa('SELECT id FROM media ORDER BY id ASC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $regenerated = 0;
    $skipped = 0;
    $failed = 0;
    $notes = [];
    foreach ($rows as $r) {
        $res = media_regenerate_variants((int)$r['id']);
        if (!$res['ok']) { $failed++; $notes[] = '#' . $r['id'] . ': ' . $res['error']; continue; }
        if ($res['skipped']) { $skipped++; continue; }
        $regenerated++;
    }
    $done = $offset + count($rows);
    if (!media_gd_available() && $total > 0) {
        $notes[] = 'GD eklentisi bulunamadı; tüm kayıtlar atlandı.';
    }
    return [
        'ok'          => true,
        'total'       => $total,
        'done'        => $done,
        'next_offset' => ($done < $total && count($rows) > 0) ? $done : 0,
        'regenerated' => $regenerated,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'notes'       => array_slice($notes, 0, 20),
    ];
}

/** uploads/ altındaki dosyaları özyinelemeli tarar (cache ve koruma dosyaları hariç). */
function media_scan_files($dir = null, $prefix = '', $depth = 0, &$acc = null) {
    if ($acc === null) { $acc = []; }
    if ($depth > 4) { return $acc; }
    $dir = $dir === null ? UPLOAD_DIR : $dir;
    $skip = media_maintenance_skip();
    $items = @scandir($dir);
    if (!is_array($items)) { return $acc; }
    foreach ($items as $it) {
        if ($it === '.' || $it === '..') { continue; }
        if ($depth === 0 && isset($skip[$it])) { continue; }
        if ($it === '.htaccess' || $it === 'index.html') { continue; }
        $abs = $dir . '/' . $it;
        $rel = $prefix === '' ? $it : $prefix . '/' . $it;
        if (is_dir($abs)) { media_scan_files($abs, $rel, $depth + 1, $acc); continue; }
        if (!is_file($abs)) { continue; }
        $acc[$rel] = ['size' => (int)@filesize($abs), 'mtime' => (int)@filemtime($abs)];
    }
    return $acc;
}

/** media tablosunda kayıtlı tüm dosya adları (özgün + varyant + webp). */
function media_known_files() {
    $known = [];
    foreach (qa('SELECT filename, variants FROM media') as $row) {
        $fn = ltrim(str_replace('\\', '/', (string)$row['filename']), '/');
        if ($fn === '') { continue; }
        $known[strtolower($fn)] = true;
        if (!empty($row['variants'])) {
            $v = json_decode((string)$row['variants'], true);
            if (is_array($v)) {
                foreach ($v as $val) {
                    if (is_string($val)) { $known[strtolower(ltrim($val, '/'))] = true; }
                    elseif (is_array($val)) {
                        foreach ($val as $vv) { if (is_string($vv)) { $known[strtolower(ltrim($vv, '/'))] = true; } }
                    }
                }
            }
        }
        // JSON eksik/bozuk olabilir: desene göre de bilinen say
        $dot = strrpos($fn, '.');
        if ($dot !== false) {
            $stem = substr($fn, 0, $dot);
            $ext = substr($fn, $dot);
            foreach (array_keys(media_variant_widths()) as $k) {
                $known[strtolower($stem . '-' . $k . $ext)] = true;
                $known[strtolower($stem . '-' . $k . '.webp')] = true;
            }
        }
    }
    return $known;
}

/**
 * media tablosunda karşılığı olmayan dosyalar (yükleme yarıda kalmış, kayıt elle silinmiş vb.).
 * @return array [['filename','size','size_text','mtime','created_at','is_variant'], …]
 */
function media_orphan_files($limit = 200) {
    $limit = max(1, min(2000, (int)$limit));
    $known = media_known_files();
    $acc = null;
    $all = media_scan_files(null, '', 0, $acc);
    $out = [];
    foreach ($all as $rel => $meta) {
        if (isset($known[strtolower($rel)])) { continue; }
        $out[] = [
            'filename'   => $rel,
            'size'       => $meta['size'],
            'size_text'  => media_human_size($meta['size']),
            'mtime'      => $meta['mtime'],
            'created_at' => $meta['mtime'] ? date('Y-m-d H:i:s', $meta['mtime']) : '',
            'is_variant' => media_is_variant_name($rel),
        ];
        if (count($out) >= $limit) { break; }
    }
    usort($out, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
    return $out;
}

/**
 * Sahipsiz bir dosyayı siler. YALNIZ UPLOAD_DIR altındaki ve media tablosunda
 * kaydı OLMAYAN dosya silinebilir; koruma dosyaları ve cache klasörü dokunulmazdır.
 */
function media_delete_orphan($filename) {
    $rel = ltrim(str_replace('\\', '/', (string)$filename), '/');
    if ($rel === '' || strpos($rel, '..') !== false) { return ['ok' => false, 'error' => 'Geçersiz dosya adı.']; }
    $base = basename($rel);
    if ($base === '.htaccess' || $base === 'index.html' || $base === 'index.php') {
        return ['ok' => false, 'error' => 'Koruma dosyaları silinemez.'];
    }
    $skip = media_maintenance_skip();
    $first = explode('/', $rel)[0];
    if ($first !== $rel && isset($skip[$first])) {
        return ['ok' => false, 'error' => 'Bu klasör bakım araçlarının kapsamı dışında.'];
    }
    $abs = media_safe_path($rel);
    if ($abs === '' || !is_file($abs)) { return ['ok' => false, 'error' => 'Dosya bulunamadı.']; }

    $known = media_known_files();
    if (isset($known[strtolower($rel)])) {
        return ['ok' => false, 'error' => 'Bu dosyanın medya kütüphanesinde kaydı var; buradan silinemez.'];
    }
    if (!@unlink($abs)) { return ['ok' => false, 'error' => 'Dosya silinemedi (izin denetleyin).']; }
    return ['ok' => true, 'error' => '', 'filename' => $rel];
}

/**
 * Depolama istatistikleri.
 * @return array ['files'=>int,'bytes'=>int,'variants'=>int,'webp'=>int, …]
 */
function media_storage_stats() {
    $acc = null;
    $all = media_scan_files(null, '', 0, $acc);
    $files = 0;
    $bytes = 0;
    $variants = 0;
    $webp = 0;
    $originalBytes = 0;
    foreach ($all as $rel => $meta) {
        $files++;
        $bytes += $meta['size'];
        $isVariant = media_is_variant_name($rel);
        if ($isVariant) { $variants++; } else { $originalBytes += $meta['size']; }
        if (substr(strtolower($rel), -5) === '.webp') { $webp++; }
    }
    $known = media_known_files();
    $orphans = 0;
    foreach ($all as $rel => $meta) { if (!isset($known[strtolower($rel)])) { $orphans++; } }

    return [
        'files'           => $files,
        'bytes'           => $bytes,
        'variants'        => $variants,
        'webp'            => $webp,
        'size_text'       => media_human_size($bytes),
        'originals'       => max(0, $files - $variants),
        'original_bytes'  => $originalBytes,
        'records'         => (int)qv('SELECT COUNT(*) FROM media', [], 0),
        'orphans'         => $orphans,
        'gd'              => media_gd_available(),
        'webp_support'    => function_exists('imagewebp'),
        'dir'             => UPLOAD_DIR,
        'writable'        => is_dir(UPLOAD_DIR) && is_writable(UPLOAD_DIR),
    ];
}
