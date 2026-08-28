<?php
/**
 * Manşet — paylaşım metni ve paylaşım görseli (1.2-12). Ajan-B.
 *
 * KAPSAM DIŞI OLAN, BİLEREK YAPILMAYAN ŞEY (CONTRACTS §13):
 * sosyal medyaya OTOMATİK GÖNDERİM. Bu dosya hiçbir sosyal ağ API'sine
 * bağlanmaz, hiçbir yerde erişim anahtarı saklamaz. Ürettiği tek şey
 * editörün KOPYALAYIP YAPIŞTIRACAĞI metin ve indirebileceği bir görseldir.
 *
 * İKİ PARÇA:
 *   1) share_texts()  — X / Instagram / WhatsApp uzunluklarında metin.
 *      Yapay zekâ yapılandırılmışsa ondan, değilse ŞABLONDAN üretilir.
 *      Şablon yolu bilinçli: YZ olmayan bir kurulumda özellik ölü kalmasın.
 *   2) share_image_*() — GD ile başlık + logo yerleşimi (paylas-gorsel.php).
 *      GD yoksa özellik ZARİFÇE KAPANIR; hata basmaz, düğme gösterilmez.
 *
 * YZ ALTYAPISI BU DOSYANIN DEĞİL: `inc/ai.php` yalnız ÇAĞRILIR (ai_complete,
 * ai_neutralize, ai_extract_json). Oradaki hiçbir şey değiştirilmez; bütçe
 * tavanı, günlük kaydı ve test modu olduğu gibi geçerlidir.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ platformlar

/**
 * Desteklenen platformlar: anahtar => [etiket, karakter sınırı, ipucu].
 *
 * Sınırlar YAYIN TARİHİNDEKİ pratik değerlerdir ve platformlar bunları
 * değiştirebilir; bu yüzden sayaç "sınır" değil "hedef uzunluk" olarak
 * gösterilir ve metin sınırı aşarsa uyarı verilir, kesilmez.
 */
function share_platforms() {
    return [
        'x'         => ['X', 260, 'Kısa, tek cümlelik kanca + bağlantı.'],
        'instagram' => ['Instagram', 420, 'Bağlantı tıklanamaz; metin kendi başına anlamlı olmalı.'],
        'whatsapp'  => ['WhatsApp', 300, 'Sohbete yapıştırılır; bağlantı önizlemesi kendiliğinden çıkar.'],
    ];
}

/** Platform anahtarı geçerli mi? */
function share_platform_exists($key) {
    return array_key_exists((string)$key, share_platforms());
}

// ============================================================ girdi

/** Haberin kanonik adresi. */
function share_post_url(array $post) {
    return url_post($post);
}

/**
 * Paylaşım için haberden alınan çekirdek: başlık, spot, kategori, adres.
 * Gövde HTML'i düz metne indirilir ve kırpılır (YZ'ye giden yük küçülsün).
 */
function share_post_core(array $post) {
    $body = (string)arr($post, 'body', '');
    $body = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
    if (mb_strlen($body) > 1200) { $body = mb_substr($body, 0, 1200) . '…'; }

    $kat = '';
    $catId = (int)arr($post, 'category_id', 0);
    if ($catId > 0 && function_exists('category_by_id')) {
        $c = category_by_id($catId);
        if ($c) { $kat = (string)arr($c, 'name', ''); }
    }

    return [
        'title'    => (string)arr($post, 'title', ''),
        'spot'     => (string)arr($post, 'spot', ''),
        'body'     => $body,
        'category' => $kat,
        'url'      => share_post_url($post),
        'tags'     => function_exists('tags_to_array') ? tags_to_array(arr($post, 'tags', '')) : [],
    ];
}

// ============================================================ şablon (YZ'siz yol)

/** Etiketleri hashtag'e çevirir (en çok 3 tane, Türkçe karakterler sadeleşir). */
function share_hashtags(array $tags, $enCok = 3) {
    $out = [];
    foreach ($tags as $t) {
        $s = str_replace('-', '', slugify((string)$t, 30));
        if ($s === '') { continue; }
        $out[] = '#' . $s;
        if (count($out) >= $enCok) { break; }
    }
    return $out;
}

/**
 * YZ olmadan üretilen metinler.
 * Kural: metin ASLA uydurmaz — yalnız başlık, spot ve adresi yeniden dizer.
 */
function share_template_texts(array $core) {
    $baslik = trim($core['title']);
    $spot   = trim($core['spot']);
    $url    = $core['url'];
    $etiket = share_hashtags($core['tags']);
    $etiketStr = $etiket ? ' ' . implode(' ', $etiket) : '';

    $kisaSpot = $spot !== '' ? excerpt($spot, 120) : '';

    return [
        'x'         => trim($baslik . ($kisaSpot !== '' ? "\n" . $kisaSpot : '') . "\n" . $url . $etiketStr),
        'instagram' => trim($baslik . "\n\n" . ($spot !== '' ? excerpt($spot, 260) . "\n\n" : '')
                        . 'Haberin tamamı profilimizdeki bağlantıda.' . ($etiketStr !== '' ? "\n" . trim($etiketStr) : '')),
        'whatsapp'  => trim($baslik . ($kisaSpot !== '' ? "\n" . $kisaSpot : '') . "\n" . $url),
    ];
}

// ============================================================ YZ yolu

/** YZ'ye gidecek kullanıcı mesajı. Dış metin ai_neutralize() ile geçirilir. */
function share_ai_prompt(array $core) {
    $p = share_platforms();
    return "Aşağıdaki haber için sosyal medya paylaşım metinleri yaz.\n\n"
        . "Kurallar:\n"
        . "- Türkçe yaz. Haberde OLMAYAN hiçbir bilgi ekleme, abartma, tıklama tuzağı kurma.\n"
        . "- Her metin kendi başına anlamlı olsun.\n"
        . "- Yanıtı YALNIZ şu anahtarları içeren bir JSON nesnesi olarak ver: x, instagram, whatsapp.\n"
        . "- Uzunluk hedefleri: x en çok " . $p['x'][1] . " karakter, instagram en çok " . $p['instagram'][1]
        . " karakter, whatsapp en çok " . $p['whatsapp'][1] . " karakter.\n"
        . "- x ve whatsapp metinlerinin sonuna haberin adresini ekle. Instagram metnine adres KOYMA.\n\n"
        . "Haber adresi: " . ai_neutralize($core['url']) . "\n"
        . "Kategori: " . ai_neutralize($core['category']) . "\n"
        . "Başlık: " . ai_neutralize($core['title']) . "\n"
        . "Spot: " . ai_neutralize($core['spot']) . "\n"
        . "Metin: " . ai_neutralize($core['body']) . "\n";
}

/**
 * Paylaşım metinlerini üretir.
 *
 * @param array $post  posts satırı
 * @param bool  $useAi YZ denensin mi (kapalıysa/yapılandırılmamışsa şablona düşer)
 * @return array ['source'=>'ai'|'sablon', 'note'=>string, 'items'=>[key=>[...]]]
 */
function share_texts(array $post, $useAi = true) {
    $core = share_post_core($post);
    $metinler = share_template_texts($core);
    $kaynak = 'sablon';
    $not = 'Yapay zekâ kullanılmadı; metinler başlık ve spottan üretildi.';

    if ($useAi && function_exists('ai_complete') && ai_configured()) {
        $res = ai_complete(share_ai_prompt($core), [
            'action'      => 'share_text',
            'max_tokens'  => 700,
            'temperature' => 0.7,
        ]);
        if (!empty($res['ok'])) {
            $json = ai_extract_json((string)$res['text']);
            $bulunan = 0;
            if (is_array($json)) {
                foreach (array_keys(share_platforms()) as $k) {
                    if (isset($json[$k]) && is_string($json[$k]) && trim($json[$k]) !== '') {
                        // Model HTML döndürebilir; paylaşım metni düz metindir.
                        $metinler[$k] = sanitize_plain((string)$json[$k], 1000);
                        $bulunan++;
                    }
                }
            }
            if ($bulunan > 0) {
                $kaynak = 'ai';
                $not = $bulunan . ' metin yapay zekâ ile üretildi. Yayımlamadan önce okuyun.';
            } else {
                $not = 'Yapay zekâ beklenen biçimde yanıt vermedi; şablon metinleri gösteriliyor.';
            }
        } else {
            $not = 'Yapay zekâ çağrısı başarısız: ' . (string)arr($res, 'error', 'bilinmeyen hata')
                 . ' — şablon metinleri gösteriliyor.';
        }
    } elseif ($useAi) {
        $not = 'Yapay zekâ yapılandırılmadı; metinler başlık ve spottan üretildi.';
    }

    $items = [];
    foreach (share_platforms() as $k => $bilgi) {
        $t = isset($metinler[$k]) ? (string)$metinler[$k] : '';
        $items[$k] = [
            'key'    => $k,
            'label'  => $bilgi[0],
            'limit'  => $bilgi[1],
            'hint'   => $bilgi[2],
            'text'   => $t,
            'length' => mb_strlen($t),
            'over'   => mb_strlen($t) > $bilgi[1],
        ];
    }
    return ['source' => $kaynak, 'note' => $not, 'url' => $core['url'], 'items' => $items];
}

// ============================================================ paylaşım görseli

/**
 * GD ile görsel üretilebilir mi?
 * Eksikse özellik gösterilmez — hata basmak yerine sessizce kapanır.
 */
function share_image_available() {
    return function_exists('imagecreatetruecolor')
        && function_exists('imagepng')
        && function_exists('imagecopyresampled')
        && function_exists('imagestring');
}

/** Paylaşım görselinin ölçüsü (açık grafik standardı 1.91:1). */
function share_image_size() { return [1200, 630]; }

/** Görselin genel adresi. */
function share_image_url(array $post) {
    return base_url() . '/paylas-gorsel.php?id=' . (int)arr($post, 'id', 0);
}

/** '#rrggbb' → [r,g,b]. Geçersizse varsayılan. */
function share_hex_rgb($hex, array $default = [17, 17, 17]) {
    $h = ltrim(trim((string)$hex), '#');
    if (strlen($h) === 3) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $h)) { return $default; }
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
}

/** Metni verilen sütun genişliğine göre satırlara böler (kelime bazlı). */
function share_wrap($metin, $sutun = 40) {
    $metin = trim(preg_replace('/\s+/u', ' ', (string)$metin));
    if ($metin === '') { return []; }
    $satirlar = [];
    $simdi = '';
    foreach (explode(' ', $metin) as $kelime) {
        $aday = $simdi === '' ? $kelime : $simdi . ' ' . $kelime;
        if (mb_strlen($aday) <= $sutun) { $simdi = $aday; continue; }
        if ($simdi !== '') { $satirlar[] = $simdi; }
        $simdi = mb_strlen($kelime) > $sutun ? mb_substr($kelime, 0, $sutun) : $kelime;
    }
    if ($simdi !== '') { $satirlar[] = $simdi; }
    return $satirlar;
}

/** Yapılandırılmış TTF yazı tipinin mutlak yolu; yoksa boş dize. */
function share_font_path() {
    if (!function_exists('imagettftext')) { return ''; }
    $ad = trim((string)setting('share_font_file', ''));
    if ($ad === '' || strpos($ad, '..') !== false || strpos($ad, ':') !== false || strpos($ad, "\0") !== false) { return ''; }
    $yol = UPLOAD_DIR . '/' . ltrim(str_replace('\\', '/', $ad), '/');
    return is_file($yol) ? $yol : '';
}

/**
 * Başlık metnini tuvale basar.
 *
 * İKİ YOL:
 *   a) TTF tanımlıysa `imagettftext` — düzgün tipografi.
 *   b) Değilse GD'nin gömülü bitmap yazı tipi küçük bir tuvale basılıp
 *      `imagecopyresampled` ile büyütülür. Sonuç yumuşak ama okunur;
 *      hiç metin basmamaktan iyidir ve hiçbir dış dosya gerektirmez.
 *      Gömülü yazı tipi Latin-1'dir: Türkçe harfler ASCII karşılığına düşürülür.
 */
function share_draw_title($img, $metin, array $renk, $x, $y, $genislik, $olcek = 4, $enCokSatir = 4) {
    $font = share_font_path();
    $puan    = 10 * $olcek;          // TTF punto, bitmap ölçeğiyle orantılı
    $satirYuk = (int)round($puan * 1.55);

    if ($font !== '') {
        $satirlar = share_wrap($metin, (int)floor($genislik / ($puan * 0.55)));
        $satirlar = array_slice($satirlar, 0, $enCokSatir);
        $c = imagecolorallocate($img, $renk[0], $renk[1], $renk[2]);
        foreach ($satirlar as $i => $s) {
            imagettftext($img, $puan, 0, (int)$x, (int)($y + ($i + 1) * $satirYuk), $c, $font, $s);
        }
        return;
    }

    // Bitmap yedeği: küçük tuvale bas, büyüterek kopyala.
    $satirlar = share_wrap(share_ascii_downgrade($metin), (int)floor($genislik / (imagefontwidth(5) * $olcek)));
    $satirlar = array_slice($satirlar, 0, $enCokSatir);
    if (!$satirlar) { return; }

    $kw = (int)ceil($genislik / $olcek);
    $kh = (int)ceil(count($satirlar) * (imagefontheight(5) + 4));
    $kucuk = imagecreatetruecolor(max(1, $kw), max(1, $kh));
    imagealphablending($kucuk, false);
    imagesavealpha($kucuk, true);
    imagefill($kucuk, 0, 0, imagecolorallocatealpha($kucuk, 0, 0, 0, 127));
    imagealphablending($kucuk, true);
    $c = imagecolorallocate($kucuk, $renk[0], $renk[1], $renk[2]);
    foreach ($satirlar as $i => $s) {
        imagestring($kucuk, 5, 0, $i * (imagefontheight(5) + 4), $s, $c);
    }
    imagecopyresampled($img, $kucuk, (int)$x, (int)$y, 0, 0,
        $kw * $olcek, $kh * $olcek, $kw, $kh);
    imagedestroy($kucuk);
}

/** Türkçe harfleri gömülü bitmap yazı tipinin anlayacağı ASCII'ye indirir. */
function share_ascii_downgrade($metin) {
    $harita = ['ğ' => 'g', 'Ğ' => 'G', 'ü' => 'u', 'Ü' => 'U', 'ş' => 's', 'Ş' => 'S',
               'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ç' => 'c', 'Ç' => 'C',
               'â' => 'a', 'Â' => 'A', 'î' => 'i', 'û' => 'u', '’' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-'];
    $m = strtr((string)$metin, $harita);
    return preg_replace('/[^\x20-\x7E]/', '', $m);
}

/**
 * Paylaşım görselini üretir ve GD kaynağı döndürür.
 * @return resource|GdImage|null
 */
function share_image_render(array $post) {
    if (!share_image_available()) { return null; }
    list($W, $H) = share_image_size();

    $img = imagecreatetruecolor($W, $H);

    $zemin = share_hex_rgb(setting('share_image_bg', ''), [17, 20, 26]);
    $yazi  = share_hex_rgb(setting('share_image_fg', ''), [255, 255, 255]);
    $vurgu = share_hex_rgb(theme_setting('color_primary', '#c0392b'), [192, 57, 43]);

    imagefilledrectangle($img, 0, 0, $W, $H, imagecolorallocate($img, $zemin[0], $zemin[1], $zemin[2]));
    // Üst şerit — marka rengi
    imagefilledrectangle($img, 0, 0, $W, 14, imagecolorallocate($img, $vurgu[0], $vurgu[1], $vurgu[2]));

    // Logo (varsa) — sol üst, yüksekliği 70 pikselle sınırlı
    $altY = $H - 90;
    $logo = trim((string)setting('site_logo', ''));
    $logoBasildi = false;
    if ($logo !== '' && function_exists('media_safe_path') && function_exists('media_gd_load')) {
        $yol = media_safe_path($logo);
        if ($yol !== '' && is_file($yol)) {
            $tur = function_exists('mime_content_type') ? (string)@mime_content_type($yol) : '';
            $kaynak = @media_gd_load($yol, $tur);
            if ($kaynak) {
                $lw = imagesx($kaynak); $lh = imagesy($kaynak);
                if ($lw > 0 && $lh > 0) {
                    $hedefH = 70;
                    $hedefW = (int)round($lw * ($hedefH / $lh));
                    if ($hedefW > 420) { $hedefW = 420; $hedefH = (int)round($lh * (420 / $lw)); }
                    imagealphablending($img, true);
                    imagecopyresampled($img, $kaynak, 70, $altY, 0, 0, $hedefW, $hedefH, $lw, $lh);
                    $logoBasildi = true;
                }
                imagedestroy($kaynak);
            }
        }
    }
    if (!$logoBasildi) {
        // Logo yoksa site adı yazılır — görsel imzasız kalmasın.
        share_draw_title($img, (string)setting('site_title', 'Manşet'), $vurgu, 70, $altY + 6, $W - 140, 2, 1);
    }

    share_draw_title($img, (string)arr($post, 'title', ''), $yazi, 70, 110, $W - 140, 4, 4);

    return $img;
}

/** Görseli PNG olarak çıktı akışına basar ve kaynağı serbest bırakır. */
function share_image_output($img) {
    header('Content-Type: image/png');
    imagepng($img);
    imagedestroy($img);
}
