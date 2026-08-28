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
 * @param string     $absPath  Orijinalin mutlak yolu
 * @param string     $filename UPLOAD_DIR'e göre göreli ad, örn '2026/08/foto-a1b2c3d4.jpg'
 * @param array|null $only     YALNIZ bu varyant anahtarları üretilsin (['thumb'] gibi).
 *                             null = hepsi. 1.3-08: yükleme isteği yalnız 'thumb'
 *                             üretir, kalanı cron kuyruğunda üretilir.
 * @param bool       $withWebp Ek WebP kopyası da üretilsin mi? (yüklemede false)
 * @return array ['thumb'=>'…-thumb.jpg', 'medium'=>…, 'large'=>…, 'webp'=>['thumb'=>'…-thumb.webp', …]]
 */
function media_make_variants($absPath, $filename, $only = null, $withWebp = true) {
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
    $canWebp = $withWebp && function_exists('imagewebp');
    $sadece = is_array($only) ? $only : null;

    foreach (media_variant_widths() as $key => $targetW) {
        if ($sadece !== null && !in_array($key, $sadece, true)) { continue; }
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

    /* --- 8) Varyantlar — 1.3-08: YÜKLEMEDE YALNIZ KÜÇÜK ÖNİZLEME
     *
     * Eskiden burada altı iş birden yapılıyordu (thumb/medium/large + üç WebP).
     * 4000×3000 bir fotoğrafta bu, yükleme isteğini saniyelerce tutuyor ve
     * paylaşımlı hostingin `max_execution_time` sınırında yükleme başarısız
     * görünürken dosya diske yazılmış oluyordu. Artık istek içinde yalnız
     * `thumb` üretilir (panelin ızgarası onu kullanır), kalanı kuyruğa girer.
     *
     * KIRIK GÖRSEL RİSKİ YOK: ön yüzdeki `media_variant_file()` (inc/view.php)
     * dosyanın diskte OLUP OLMADIĞINA bakar; yoksa `post_image()` özgün
     * dosyaya düşer. Kuyruk işlenene kadar görsel büyük gelir, ama gelir. */
    $variants = media_make_variants($abs, $rel, ['thumb'], false);

    // --- 9) Veritabanı
    $row = [
        'filename'   => $rel,
        'alt'        => sanitize_line((string)$alt, 255),
        'mime'       => $mime,
        'width'      => $w,
        'height'     => $h,
        'size'       => $size,
        'variants'   => $variants ? (string)json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        'created_at' => now(),
    ];
    // Göç 025 uygulanmamış eski kurulumda sütunlar yoktur; INSERT patlamasın.
    if (media_has_queue()) {
        $row['variant_state'] = media_gd_available() ? 'queued' : 'skipped';
        $row['variant_at'] = '';
        $row['variant_tries'] = 0;
        $row['focus_x'] = 50;
        $row['focus_y'] = 50;
    }
    $id = db_insert('media', $row);

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

/**
 * Göç 025 uygulandı mı? (varyant kuyruğu + odak noktası sütunları)
 * İstek boyunca bir kez sorulur; sorgular buna göre sütun listesi seçer.
 */
function media_has_queue() {
    static $var = null;
    if ($var !== null) { return $var; }
    if (function_exists('schema_has_column')) {
        $var = schema_has_column('media', 'variant_state') && schema_has_column('media', 'focus_x');
    } else {
        try { qv('SELECT variant_state FROM media LIMIT 1'); $var = true; }
        catch (Throwable $e) { $var = false; }
    }
    return $var;
}

/** Listeleme sorgularının sütun listesi (göç 025 uygulanmışsa yeni alanlarla). */
function media_select_columns() {
    $base = 'id, filename, alt, mime, width, height, size, variants, created_at';
    return media_has_queue()
        ? $base . ', variant_state, variant_at, variant_tries, focus_x, focus_y'
        : $base;
}

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

    // 1.3-08 alanları — göç 025 yoksa güvenli varsayılan.
    $durum = (string)arr($row, 'variant_state', '');
    $row['variant_state'] = $durum;
    $row['variant_ready'] = ($durum === 'done' || $durum === 'skipped'
        || (isset($v['medium']) || isset($v['large'])));
    $row['variant_label'] = media_variant_state_label($durum, $v);
    $row['focus_x'] = media_focus_clamp(arr($row, 'focus_x', 50));
    $row['focus_y'] = media_focus_clamp(arr($row, 'focus_y', 50));
    $row['focus_css'] = $row['focus_x'] . '% ' . $row['focus_y'] . '%';
    $row['alt_missing'] = (trim((string)arr($row, 'alt', '')) === '');
    return $row;
}

/** Odak yüzdesini 0..100 aralığına sıkıştırır (varsayılan 50 = merkez). */
function media_focus_clamp($v) {
    if ($v === null || $v === '') { return 50; }
    return max(0, min(100, (int)$v));
}

/** Varyant durumunun panelde gösterilecek kısa karşılığı. */
function media_variant_state_label($state, array $variants) {
    switch ((string)$state) {
        case 'queued':  return 'kuyrukta';
        case 'done':    return $variants ? 'türevli' : 'türev gerekmedi';
        case 'failed':  return 'üretilemedi';
        case 'skipped': return 'atlandı';
    }
    return $variants ? 'türevli' : '—';
}

/**
 * Odak noktasının CSS karşılığı: 'object-position: 50% 30%'.
 * Temalar kırpılan (object-fit:cover) görsellerde bunu kullanmalıdır.
 * @param array|int $media media satırı ya da media.id
 */
function media_focus_css($media) {
    if (!is_array($media)) {
        $media = q1('SELECT ' . media_select_columns() . ' FROM media WHERE id = :i', [':i' => (int)$media]);
        if (!$media) { return 'object-position: 50% 50%'; }
    }
    return 'object-position: ' . media_focus_clamp(arr($media, 'focus_x', 50)) . '% '
        . media_focus_clamp(arr($media, 'focus_y', 50)) . '%';
}

/** Odak noktasını kaydeder. */
function media_set_focus($id, $x, $y) {
    $id = (int)$id;
    if (!media_has_queue()) { return ['ok' => false, 'error' => 'Odak noktası için 025 numaralı güncelleme gerekiyor.']; }
    if (!qv('SELECT 1 FROM media WHERE id = :i', [':i' => $id])) {
        return ['ok' => false, 'error' => 'Kayıt bulunamadı.'];
    }
    $fx = media_focus_clamp($x);
    $fy = media_focus_clamp($y);
    db_update('media', ['focus_x' => $fx, 'focus_y' => $fy], 'id = :id', [':id' => $id]);
    return ['ok' => true, 'error' => '', 'focus_x' => $fx, 'focus_y' => $fy];
}

/** Arama teriminden LIKE joker karakterlerini ayıklar. */
function media_like_term($q) {
    $q = sanitize_line((string)$q, 120);
    return str_replace(['%', '_'], ' ', $q);
}

/**
 * Liste/say sorgularının ortak WHERE'i.
 * @param string $q      Arama terimi
 * @param string $filter '' | 'noalt' (alt metni boş) | 'queued' (varyant bekleyen)
 * @return array [' WHERE …' ya da '', bağ dizisi]
 */
function media_where($q = '', $filter = '') {
    $term = media_like_term($q);
    $kos = [];
    $p = [];
    if ($term !== '') {
        $kos[] = '(filename LIKE :q OR alt LIKE :q)';
        $p[':q'] = '%' . $term . '%';
    }
    if ($filter === 'noalt') {
        // NULL da "alt metni yok" sayılır: sütun 1.0'da NOT NULL DEFAULT ''
        // ama elle içe aktarılmış kayıtlarda NULL görülebiliyor.
        $kos[] = '(alt IS NULL OR alt = \'\')';
    } elseif ($filter === 'queued' && media_has_queue()) {
        $kos[] = '(variant_state = \'queued\' OR variant_state = \'\' OR variant_state IS NULL)';
    }
    return [$kos ? ' WHERE ' . implode(' AND ', $kos) : '', $p];
}

/** Medya listesi (yeniden eskiye). */
function media_list($q = '', $limit = 60, $offset = 0, $filter = '') {
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    list($where, $p) = media_where($q, (string)$filter);
    $rows = qa('SELECT ' . media_select_columns() . ' FROM media'
        . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
    $out = [];
    foreach ($rows as $r) { $out[] = media_decorate($r); }
    return $out;
}

/** Aramaya uyan medya sayısı. */
function media_count($q = '', $filter = '') {
    list($where, $p) = media_where($q, (string)$filter);
    return (int)qv('SELECT COUNT(*) FROM media' . $where, $p, 0);
}

/** Alt metni boş görsel sayısı (erişilebilirlik uyarısı). */
function media_missing_alt_count() { return media_count('', 'noalt'); }

/** Kütüphanedeki toplam bayt. */
function media_total_size() {
    return (int)qv('SELECT COALESCE(SUM(size), 0) FROM media', [], 0);
}

/** Tek kayıt (zenginleştirilmiş). */
function media_get($id) {
    $row = q1('SELECT ' . media_select_columns() . ' FROM media WHERE id = :i', [':i' => (int)$id]);
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
    // 1.3-08: elle yenileme de kuyruk durumunu kapatır, yoksa cron aynı kaydı
    // bir daha işlemeye kalkar.
    if (media_has_queue()) {
        $data['variant_state'] = 'done';
        $data['variant_at'] = now();
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

// ============================================================================
// 1.3-08 — VARYANT KUYRUĞU
//
// NEDEN: yükleme isteği artık yalnız `thumb` üretiyor (bkz. media_save_upload
// adım 8). Kalan boyutlar ve WebP kopyaları burada, cron turunda üretilir.
// Kuyruk cron BÜTÇESİNE saygılıdır: her kayıttan önce `cron_over_budget()`
// sorulur ve rezerv kadar süre kalmadıysa döngü BAŞTAN durur — yarım kalan
// bir GD işlemi bozuk dosya bırakabilirdi.
// ============================================================================

/** Tek bir kaydın varyant üretimi ne kadar sürebilir (sn)? Bütçe rezervi. */
function media_queue_reserve() { return 6; }

/** Bir cron turunda en çok kaç kayıt işlenir? */
function media_queue_batch() {
    $v = (int)setting('media_queue_batch', '8');
    return max(1, min(50, $v ?: 8));
}

/** Kuyrukta bekleyen kayıt sayısı (varyantı hiç üretilmemiş olanlar dahil). */
function media_queue_size() {
    if (!media_has_queue()) { return 0; }
    return (int)qv('SELECT COUNT(*) FROM media
        WHERE (variant_state = \'queued\' OR variant_state = \'\' OR variant_state IS NULL)', [], 0);
}

/** Kuyruk özeti: durum => adet. */
function media_queue_stats() {
    $out = ['queued' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0];
    if (!media_has_queue()) { return $out; }
    foreach (qa('SELECT variant_state, COUNT(*) AS n FROM media GROUP BY variant_state') as $r) {
        $k = (string)$r['variant_state'];
        if ($k === '') { $k = 'queued'; }
        if (!isset($out[$k])) { $out[$k] = 0; }
        $out[$k] += (int)$r['n'];
    }
    return $out;
}

/** Sıradaki bekleyen kayıtlar (en az denenmiş önce, sonra en yeni yükleme). */
function media_variants_pending($limit = 8) {
    if (!media_has_queue()) { return []; }
    $limit = max(1, min(50, (int)$limit));
    return qa('SELECT id, filename, mime, variants, variant_tries FROM media
        WHERE (variant_state = \'queued\' OR variant_state = \'\' OR variant_state IS NULL)
        ORDER BY variant_tries ASC, id DESC LIMIT ' . $limit);
}

/**
 * Tek kaydın eksik varyantlarını üretir ve kuyruk durumunu kapatır.
 * MEVCUT varyantların üstüne yazmaz — yüklemede üretilen `thumb` korunur,
 * JSON birleştirilir.
 *
 * @return array ['ok'=>bool,'state'=>string,'note'=>string,'made'=>int]
 */
function media_variant_worker($id) {
    $id = (int)$id;
    $row = q1('SELECT id, filename, mime, variants, variant_tries FROM media WHERE id = :i', [':i' => $id]);
    if (!$row) { return ['ok' => false, 'state' => '', 'note' => 'Kayıt yok.', 'made' => 0]; }
    if (!media_has_queue()) { return ['ok' => false, 'state' => '', 'note' => 'Göç 025 uygulanmamış.', 'made' => 0]; }

    $rel = (string)$row['filename'];
    $tries = (int)arr($row, 'variant_tries', 0) + 1;
    $eski = [];
    if (!empty($row['variants'])) {
        $d = json_decode((string)$row['variants'], true);
        if (is_array($d)) { $eski = $d; }
    }

    $kapat = function ($state, $note, $variants, $made) use ($id, $tries) {
        db_update('media', [
            'variants'      => $variants ? (string)json_encode($variants, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
            'variant_state' => $state,
            'variant_at'    => now(),
            'variant_tries' => $tries,
        ], 'id = :id', [':id' => $id]);
        return ['ok' => $state !== 'failed', 'state' => $state, 'note' => $note, 'made' => $made];
    };

    if (!media_gd_available()) { return $kapat('skipped', 'GD yok.', $eski, 0); }

    $abs = media_safe_path($rel);
    if ($abs === '' || !is_file($abs)) {
        // Dosya diskte yok: bir daha denemenin anlamı yok.
        return $kapat('failed', 'Özgün dosya diskte yok.', $eski, 0);
    }
    if (strtolower((string)arr($row, 'mime', '')) === 'image/gif') {
        // Hareketli GIF küçültülürse animasyon kaybolur (Ajan-2 kararı korunur).
        return $kapat('skipped', 'GIF — animasyon korunuyor.', $eski, 0);
    }
    if ($tries > 3) {
        return $kapat('failed', '3 denemede üretilemedi.', $eski, 0);
    }

    $yeni = media_make_variants($abs, $rel);   // hepsi + WebP
    if (!$yeni) {
        // Görsel eşiklerin altında ya da GD dosyayı açamadı. İkisi de kalıcı
        // durum sayılır: 'done' işaretlenir, ön yüz özgün dosyayı kullanır.
        return $kapat('done', 'Küçültme gerekmedi.', $eski, 0);
    }

    // Birleştir: yeni üretim eskiyi ezer, eskide olup yenide olmayan korunur.
    $birlesik = $eski;
    foreach ($yeni as $k => $v) {
        if ($k === 'webp' && is_array($v)) {
            $mevcut = (isset($birlesik['webp']) && is_array($birlesik['webp'])) ? $birlesik['webp'] : [];
            $birlesik['webp'] = $v + $mevcut;
            continue;
        }
        $birlesik[$k] = $v;
    }
    $sayi = 0;
    foreach ($yeni as $k => $v) { $sayi += is_array($v) ? count($v) : 1; }
    return $kapat('done', $sayi . ' türev üretildi.', $birlesik, $sayi);
}

/**
 * Cron görevi: bekleyen varyantları üretir.
 * cron.php'nin modül listesinde 'media' zaten var (cron_modules_load).
 */
function media_cron_variants() {
    if (!media_has_queue()) { return ['note' => 'kuyruk yok (göç 025 uygulanmamış)', 'items' => 0]; }
    if (!media_gd_available()) { return ['note' => 'GD yok — kuyruk beklemede', 'items' => 0]; }

    $islenen = 0;
    $hata = 0;
    foreach (media_variants_pending(media_queue_batch()) as $r) {
        // BÜTÇE: bir kaydı işleyecek kadar süre kalmadıysa hiç başlama.
        if (function_exists('cron_over_budget') && cron_over_budget(media_queue_reserve())) { break; }
        $res = media_variant_worker((int)$r['id']);
        if (empty($res['ok'])) { $hata++; }
        $islenen++;
    }
    $kalan = media_queue_size();
    if ($islenen > 0 && function_exists('cache_flush')) { cache_flush(); }
    return [
        'items' => $islenen,
        'note'  => $islenen . ' görsel işlendi, ' . $kalan . ' kuyrukta'
                   . ($hata ? ', ' . $hata . ' hata' : ''),
    ];
}

// ============================================================================
// 1.3-08 — KULLANILMAYAN GÖRSEL BULUCU
//
// DİKKAT — SİLME YIKICIDIR. Bu bölüm YALNIZ ADAY ÜRETİR; silme ayrı bir uçta,
// açık onayla ve `media.delete_any` izniyle yapılır.
//
// YANLIŞ POZİTİF RİSKİ (raporda da yazılı):
//   · Gövde HTML'i METİN OLARAK taranır: src, srcset, href, data-* ve CSS
//     url() içinde geçen "uploads/…" benzeri her yol yakalanır. Yani görseli
//     JS ile kuran, adresi parçalayan ya da yalnız medya id'si saklayan bir
//     tema/eklenti kullanımı GÖRÜLMEZ → dosya "kullanılmıyor" sanılır.
//   · Taranan tablolar aşağıda SABİT bir liste. Yeni bir modül görsel adını
//     kendi tablosuna yazarsa bu liste güncellenmedikçe onun kullandığı
//     dosyalar kullanılmıyor görünür.
//   · Tema dosyaları, elle yazılmış HTML ve dışarıdan (başka site, bülten,
//     sosyal medya paylaşımı) verilen doğrudan bağlantılar HİÇ taranmaz.
// Bu yüzden arayüz sayıyı ve listeyi ÖNCE gösterir, silmeyi ayrı bir adımda
// ve açık onayla ister.
// ============================================================================

/**
 * Tablo var mı? `schema_has_table()` inc/schema.php'de tanımlıdır ve o dosya
 * HER bağlamda yüklenmez (inc/media.php yalnız bootstrap + sanitize ister).
 * 1.3 geliştirmesinde bu, kullanılmayan görsel taramasını ölümcül hatayla
 * düşürdü — ölçümle yakalandı. Yoksa sorgu deneyerek karar verilir.
 */
function media_table_exists($table) {
    if (function_exists('schema_has_table')) { return schema_has_table($table); }
    try { qv('SELECT 1 FROM ' . preg_replace('/[^a-z0-9_]/i', '', $table) . ' LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

/** Sütun var mı? (aynı gerekçe) */
function media_column_exists($table, $column) {
    if (function_exists('schema_has_column')) { return schema_has_column($table, $column); }
    try {
        qv('SELECT ' . preg_replace('/[^a-z0-9_]/i', '', $column)
            . ' FROM ' . preg_replace('/[^a-z0-9_]/i', '', $table) . ' LIMIT 1');
        return true;
    } catch (Throwable $e) { return false; }
}

/** Metin içinde geçen görsel yollarını toplar (küçük harfe indirgenmiş). */
function media_scan_text_refs($text, array &$acc) {
    $t = (string)$text;
    if ($t === '') { return; }
    // JSON içinde eğik çizgi kaçışlı olabilir: "2026\/08\/foto.jpg"
    $t = str_replace('\\/', '/', $t);
    // HTML varlıkları: &amp; ve &#47; gibi kaçışlar yolu bozmasın.
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    if (!preg_match_all('#[A-Za-z0-9][A-Za-z0-9/_\-\.]{0,250}\.(?:jpe?g|png|gif|webp)#i', $t, $m)) { return; }
    foreach ($m[0] as $yol) {
        $yol = strtolower(ltrim(str_replace('\\', '/', $yol), '/'));
        $acc[$yol] = true;
        // 'uploads/2026/08/foto.jpg' → '2026/08/foto.jpg' (media.filename biçimi)
        $p = strpos($yol, 'uploads/');
        if ($p !== false) { $acc[substr($yol, $p + 8)] = true; }
        // Yalnız dosya adı da eşleşsin: adres parçalanmış olabilir.
        $acc[basename($yol)] = true;
    }
}

/**
 * Görsel adının geçtiği yerlerin dizini.
 * @return array ['2026/08/foto.jpg' => true, 'foto.jpg' => true, …]
 */
function media_usage_index() {
    $acc = [];

    // 1) Haber görseli + gövde + spot + SEO açıklaması (çöptekiler DAHİL:
    //    geri alınabilecek bir haberin görseli kullanılıyor sayılır).
    foreach (qa('SELECT image, body, spot, seo_desc FROM posts') as $r) {
        media_scan_text_refs(arr($r, 'image', ''), $acc);
        media_scan_text_refs(arr($r, 'body', ''), $acc);
        media_scan_text_refs(arr($r, 'spot', ''), $acc);
        media_scan_text_refs(arr($r, 'seo_desc', ''), $acc);
    }

    // 2) Sabit sayfalar
    if (media_table_exists('pages')) {
        foreach (qa('SELECT body FROM pages') as $r) { media_scan_text_refs(arr($r, 'body', ''), $acc); }
    }

    // 3) Reklamlar (görsel reklam + ham HTML kodu)
    if (media_table_exists('ads')) {
        foreach (qa('SELECT image, html FROM ads') as $r) {
            media_scan_text_refs(arr($r, 'image', ''), $acc);
            media_scan_text_refs(arr($r, 'html', ''), $acc);
        }
    }

    // 4) Kategoriler (kapak görseli olan kurulumlar)
    if (media_table_exists('categories') && media_column_exists('categories', 'image')) {
        foreach (qa('SELECT image FROM categories') as $r) { media_scan_text_refs(arr($r, 'image', ''), $acc); }
    }

    // 5) Ayarlar: logo, favicon, varsayılan paylaşım görseli, blok dizilimi
    //    JSON'u, künye… Değerin tamamı taranır, anahtar adı önemsizdir.
    foreach (qa('SELECT sval FROM settings') as $r) { media_scan_text_refs(arr($r, 'sval', ''), $acc); }

    return $acc;
}

/**
 * Hiçbir yerde geçmeyen medya kayıtları.
 *
 * @param int $limit En çok kaç aday döndürülsün
 * @return array ['items'=>[…], 'total'=>int, 'scanned'=>int, 'bytes'=>int]
 */
function media_unused($limit = 100) {
    $limit = max(1, min(500, (int)$limit));
    $kullanim = media_usage_index();

    $items = [];
    $toplam = 0;
    $tarandi = 0;
    $bayt = 0;
    foreach (qa('SELECT ' . media_select_columns() . ' FROM media ORDER BY id DESC') as $r) {
        $tarandi++;
        $fn = strtolower(ltrim(str_replace('\\', '/', (string)$r['filename']), '/'));
        if ($fn === '') { continue; }
        if (isset($kullanim[$fn]) || isset($kullanim[basename($fn)])) { continue; }
        $toplam++;
        $bayt += (int)arr($r, 'size', 0);
        if (count($items) < $limit) { $items[] = media_decorate($r); }
    }
    return ['items' => $items, 'total' => $toplam, 'scanned' => $tarandi,
            'bytes' => $bayt, 'bytes_text' => media_human_size($bayt)];
}

// ============================================================================
// 1.3-08 — TOPLU İŞLEMLER
// ============================================================================

/** İstekten gelen id listesini temizler (tekilleştirir, en çok 200 kayıt). */
function media_clean_ids($ids) {
    if (is_string($ids)) { $ids = explode(',', $ids); }
    if (!is_array($ids)) { return []; }
    $out = [];
    foreach ($ids as $x) {
        if (!is_scalar($x)) { continue; }
        $i = (int)$x;
        if ($i > 0 && !in_array($i, $out, true)) { $out[] = $i; }
        if (count($out) >= 200) { break; }
    }
    return $out;
}

/**
 * Toplu silme. Her kayıt için `media_delete()` çağrılır (türevler de gider).
 * ÇAĞIRAN, `media.delete_any` iznini VE kullanıcının onayını doğrulamış olmalıdır.
 *
 * @return array ['ok','deleted'=>int,'failed'=>int,'errors'=>[…]]
 */
function media_delete_many($ids) {
    $ids = media_clean_ids($ids);
    $silinen = 0;
    $hata = 0;
    $mesajlar = [];
    foreach ($ids as $id) {
        $r = media_delete($id);
        if (!empty($r['ok'])) { $silinen++; continue; }
        $hata++;
        if (count($mesajlar) < 10) { $mesajlar[] = '#' . $id . ': ' . (string)arr($r, 'error', ''); }
    }
    return ['ok' => true, 'deleted' => $silinen, 'failed' => $hata, 'errors' => $mesajlar];
}

/** Toplu alt metin ataması (aynı metin birden çok kayda). */
function media_update_alt_many($ids, $alt) {
    $ids = media_clean_ids($ids);
    $clean = sanitize_line((string)$alt, 255);
    $n = 0;
    foreach ($ids as $id) {
        $r = media_update_alt($id, $clean);
        if (!empty($r['ok'])) { $n++; }
    }
    return ['ok' => true, 'updated' => $n, 'alt' => $clean];
}

// ============================================================================
// KAYIT DEFTERLERİ
// ============================================================================

// Varyant kuyruğu — 'ucuz' DEĞİL: GD ile görsel küçültmek saniyeler sürer ve
// bağlantıyı kapatamayan SAPI'lerde panel isteğini bekletir (cron.php B07).
cron_register('medya', 'media_cron_variants', false);

/* KÖPRÜ (1.3-09) — zamanlı manşet görevi `inc/api/headlines_api.php` içinde
 * tanımlıdır. cron.php'nin modül listesinde (cron_modules_load) 'media' VAR,
 * 'headlines' YOK; o dosya orkestratöre ait olduğu için oraya eklenemedi. Bu
 * `require_once` olmadan `cron_register('manset', …)` hiç çalışmaz ve zamanlı
 * manşet sessizce ölür. Orkestratörden modül listesine eklenmesi istendi
 * (gelistirme/1.3-ajan-c.md → İstekler); eklendiğinde bu satır silinebilir.
 * Yinelenmeye karşı güvenli: api.php dosyayı zaten glob ile yükler ve
 * headlines_api.php, api_register tanımlı değilse kayıt bloğunu atlar. */
if (defined('MANSET_BOOTSTRAPPED') && is_file(INC_DIR . '/api/headlines_api.php')) {
    require_once INC_DIR . '/api/headlines_api.php';
}
