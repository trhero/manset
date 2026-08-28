<?php
/**
 * Manşet — YZ editör araçları ve fark motoru (1.3-04, Ajan-G).
 *
 *   aitools.rewrite    ai.use   seçili metni yeniden yazar ya da kısaltır
 *   aitools.summary    ai.use   özet çıkarır
 *   aitools.taxonomy   ai.use   kategori + etiket önerir
 *   aitools.alt_text   ai.use   görsel alt metni önerir
 *   aitools.diff       ai.use   iki metnin kelime bazlı farkını döndürür
 *
 * =============================================================================
 * İZİN VE HIZ SINIRI — NEDEN ZORUNLU
 * =============================================================================
 * Bu uçların dördü GERÇEK BİR YZ ÇAĞRISI yapar, yani **para harcar**. 1.2'de
 * `share.*` için konan kural birebir aynıdır: kapı `ai.use`, çünkü uç
 * yapılandırılmışsa sağlayıcıya ücretli istek gider. Kapıyı "YZ kapalıyken
 * zararsız" diye gevşetmek, yayıncı YZ'yi açtığı gün SESSİZCE yetki genişletmek
 * olurdu.
 *
 * Hız sınırı iki kovalıdır ve sayaç KARARDAN ÖNCE yazar (CONTRACTS §3.2):
 *   ai-tools:<kullanıcı>   20 / 300 sn   — tek kişinin faturayı uçurmasını keser
 *   ai-tools-ip:<ip>       60 / 300 sn   — çalınan oturumla toplu tüketimi keser
 * `aitools.diff` çağrısı YZ'ye GİTMEZ (saf hesap), yalnız daha geniş bir kovaya
 * takılır — ama kapısı yine `ai.use`, çünkü karşılaştırdığı şey YZ çıktısıdır.
 *
 * =============================================================================
 * inc/ai.php VE inc/ai_jobs.php DEĞİŞTİRİLMEDİ
 * =============================================================================
 * Yalnız `ai_complete()`, `ai_prompt()`, `ai_neutralize()`, `ai_system_prompt()`,
 * `ai_extract_json()` ve `ai_configured()` ÇAĞRILIR (CONTRACTS §6 imzaları).
 * Dış metin daima `ai_neutralize()`'dan geçip KULLANICI mesajı olarak gider;
 * talimat ayrı kanaldadır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/sanitize.php';
if (is_file(INC_DIR . '/ai.php')) { require_once INC_DIR . '/ai.php'; }
if (is_file(INC_DIR . '/ai_jobs.php')) { require_once INC_DIR . '/ai_jobs.php'; }

// =============================================================== ortak kapılar

/** İki kovalı hız sınırı. `$yzCagrisi` false ise yalnız geniş kova uygulanır. */
function ai_tools_gate($yzCagrisi = true) {
    $u = current_user();
    $uid = (int)arr($u, 'id', 0);
    // Sayaç KARARDAN ÖNCE yazılır — ters sıra eşzamanlı isteklerde sınırı katlar.
    if ($yzCagrisi && !rate_limit('ai-tools:' . $uid, 20, 300)) {
        json_err('Çok fazla yapay zekâ isteği. Biraz sonra tekrar deneyin.', 429);
    }
    if (!rate_limit('ai-tools-ip:' . client_ip(), 60, 300)) {
        json_err('Çok fazla istek. Biraz sonra tekrar deneyin.', 429);
    }
    if ($yzCagrisi && !ai_configured()) {
        json_err('Yapay zekâ yapılandırılmadı. Panel → Yapay Zekâ ekranından sağlayıcı ve anahtar girin.', 503);
    }
    return $u;
}

/** İstekten metin alanı okur, düz metne indirir ve sınırlar. */
function ai_tools_text($anahtar = 'text', $enCok = 8000, $zorunlu = true) {
    $b = json_body();
    $ham = (string)(array_key_exists($anahtar, $b) ? $b[$anahtar] : inp($anahtar, ''));
    // HTML gelebilir (editörde seçili parça); YZ'ye düz metin gider.
    $metin = trim(sanitize_plain(strip_tags($ham), $enCok));
    if ($zorunlu && mb_strlen($metin) < 10) {
        json_err('Önce en az 10 karakterlik bir metin seçin.');
    }
    return $metin;
}

/** Model yanıtını gövdeye girebilecek düz paragraflara çevirir. */
function ai_tools_paragraflar($metin) {
    $metin = trim(sanitize_plain((string)$metin, 12000));
    if ($metin === '') { return ''; }
    $html = '';
    foreach (preg_split('/\n{1,}/u', $metin) as $satir) {
        $satir = trim($satir);
        if ($satir !== '') { $html .= '<p>' . esc($satir) . '</p>'; }
    }
    // Son kapı: kendi ürettiğimizi de temizleyiciden geçiririz (iframe'siz).
    return sanitize_html($html, false);
}

// =============================================================== fark motoru

/**
 * Kelime bazlı fark (LCS). DIŞ KÜTÜPHANE YOK.
 *
 * NEDEN KELİME BAZLI: satır bazlı fark, haber metninde işe yaramaz — editör
 * bir cümlenin ortasındaki tek sıfatı değiştirir ve satır bazlı fark bütün
 * paragrafı "değişti" diye boyar; okuyan kişi neyin değiştiğini göremez.
 * Karakter bazlı fark ise Türkçe'de gürültülüdür (ek değişimi harf harf yanar).
 *
 * NEDEN LCS: en uzun ortak alt dizi, "eklendi/silindi" kararını YEREL DEĞİL
 * KÜRESEL olarak verir; naif "sırayla karşılaştır" yaklaşımı bir kelime
 * eklendiğinde kalan metnin tamamını kaydırıp hepsini değişmiş gösterir.
 *
 * MALİYET SINIRI: LCS O(n·m) bellek ister. 1200 kelimeyi aşan girdide fark
 * BLOK bazına düşürülür (paragraf paragraf) — 6000 kelimelik iki metin
 * 36 milyon hücre demekti, paylaşımlı hostingde bellek sınırını aşardı.
 *
 * @return array [['op'=>'esit'|'ekle'|'sil', 'text'=>…], …]
 */
function ai_tools_diff_words($a, $b) {
    $x = ai_tools_tokens($a);
    $y = ai_tools_tokens($b);

    $n = count($x);
    $m = count($y);
    if ($n === 0 && $m === 0) { return []; }
    if ($n === 0) { return [['op' => 'ekle', 'text' => implode('', $y)]]; }
    if ($m === 0) { return [['op' => 'sil', 'text' => implode('', $x)]]; }

    // Baş ve son ortak parçaları kırp: LCS tablosu yalnız GERÇEK farkı görür.
    $bas = 0;
    while ($bas < $n && $bas < $m && $x[$bas] === $y[$bas]) { $bas++; }
    $son = 0;
    while ($son < ($n - $bas) && $son < ($m - $bas)
           && $x[$n - 1 - $son] === $y[$m - 1 - $son]) { $son++; }

    $xo = array_slice($x, $bas, $n - $bas - $son);
    $yo = array_slice($y, $bas, $m - $bas - $son);

    $parcalar = [];
    if ($bas > 0) { $parcalar[] = ['op' => 'esit', 'text' => implode('', array_slice($x, 0, $bas))]; }

    if (count($xo) * count($yo) > 1440000) {
        // ÇOK BÜYÜK: LCS yerine kaba blok farkı. Doğru ama daha kaba.
        if ($xo) { $parcalar[] = ['op' => 'sil', 'text' => implode('', $xo)]; }
        if ($yo) { $parcalar[] = ['op' => 'ekle', 'text' => implode('', $yo)]; }
    } else {
        foreach (ai_tools_lcs_diff($xo, $yo) as $p) { $parcalar[] = $p; }
    }

    if ($son > 0) { $parcalar[] = ['op' => 'esit', 'text' => implode('', array_slice($x, $n - $son))]; }
    return ai_tools_diff_birlestir($parcalar);
}

/** Metni kelime + ardındaki boşluk parçalarına böler (boşluk KORUNUR). */
function ai_tools_tokens($metin) {
    $metin = trim(preg_replace('/\r\n|\r/u', "\n", (string)$metin));
    if ($metin === '') { return []; }
    $parcalar = preg_split('/(\s+)/u', $metin, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    return is_array($parcalar) ? $parcalar : [];
}

/** Klasik LCS tablosu + geri izleme. */
function ai_tools_lcs_diff(array $x, array $y) {
    $n = count($x);
    $m = count($y);
    if ($n === 0 && $m === 0) { return []; }
    if ($n === 0) { return [['op' => 'ekle', 'text' => implode('', $y)]]; }
    if ($m === 0) { return [['op' => 'sil', 'text' => implode('', $x)]]; }

    // Tablo satır satır kurulur (SplFixedArray yok — sade dizi yeterli).
    $t = [];
    for ($i = 0; $i <= $n; $i++) { $t[$i] = array_fill(0, $m + 1, 0); }
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $t[$i][$j] = ($x[$i] === $y[$j])
                ? $t[$i + 1][$j + 1] + 1
                : max($t[$i + 1][$j], $t[$i][$j + 1]);
        }
    }

    $out = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($x[$i] === $y[$j]) { $out[] = ['op' => 'esit', 'text' => $x[$i]]; $i++; $j++; }
        elseif ($t[$i + 1][$j] >= $t[$i][$j + 1]) { $out[] = ['op' => 'sil', 'text' => $x[$i]]; $i++; }
        else { $out[] = ['op' => 'ekle', 'text' => $y[$j]]; $j++; }
    }
    while ($i < $n) { $out[] = ['op' => 'sil', 'text' => $x[$i]]; $i++; }
    while ($j < $m) { $out[] = ['op' => 'ekle', 'text' => $y[$j]]; $j++; }
    return $out;
}

/** Ardışık aynı işlemli parçaları birleştirir (çıktı okunur olsun). */
function ai_tools_diff_birlestir(array $parcalar) {
    $out = [];
    foreach ($parcalar as $p) {
        if ($p['text'] === '') { continue; }
        $sonuncu = count($out) - 1;
        if ($sonuncu >= 0 && $out[$sonuncu]['op'] === $p['op']) { $out[$sonuncu]['text'] .= $p['text']; }
        else { $out[] = $p; }
    }
    return $out;
}

/** Fark özeti: kaç kelime eklendi / silindi / aynı kaldı. */
function ai_tools_diff_stats(array $parcalar) {
    $s = ['esit' => 0, 'ekle' => 0, 'sil' => 0];
    foreach ($parcalar as $p) {
        $kelime = preg_split('/\s+/u', trim($p['text']), -1, PREG_SPLIT_NO_EMPTY);
        $s[$p['op']] += is_array($kelime) ? count($kelime) : 0;
    }
    return $s;
}

/**
 * Farkı KAÇIŞLI HTML olarak basar. Panel sayfası da bunu kullanır
 * (admin/pages/ai-denetim.php bu dosyayı require eder — CONTRACTS §7 bunun
 * güvenli olduğunu söylüyor: api_register() bootstrap'te tanımlıdır).
 *
 * Metin `esc()` ile kaçırılır; işaretleme yalnız <ins>/<del>/<span>'dir.
 */
function ai_tools_diff_html(array $parcalar, $taraf = 'her') {
    $html = '';
    foreach ($parcalar as $p) {
        $metin = nl2br(esc($p['text']), false);
        if ($p['op'] === 'esit') { $html .= $metin; continue; }
        if ($p['op'] === 'sil') {
            if ($taraf === 'yeni') { continue; }
            $html .= '<del class="fark-sil">' . $metin . '</del>';
            continue;
        }
        if ($taraf === 'eski') { continue; }
        $html .= '<ins class="fark-ekle">' . $metin . '</ins>';
    }
    return $html;
}

// =============================================================== uçlar

api_register('aitools.rewrite', function () {
    ai_tools_gate(true);
    $b = json_body();
    $kip = (string)(array_key_exists('mode', $b) ? $b['mode'] : inp('mode', 'rewrite'));
    if (!in_array($kip, ['rewrite', 'shorten'], true)) { $kip = 'rewrite'; }
    $metin = ai_tools_text('text', 8000);

    $talimat = ($kip === 'shorten')
        ? 'Aşağıdaki haber metnini ANLAMINI KORUYARAK yaklaşık yarı uzunluğa indir. '
          . 'Hiçbir olgu, sayı, ad ya da tarih ekleme veya değiştirme; yalnız gereksiz '
          . 'tekrarı ve dolgu ifadeleri at.'
        : 'Aşağıdaki haber metnini haber diliyle YENİDEN YAZ: kısa cümleler, edilgen '
          . 'yapıdan kaçın, yorum katma. Hiçbir olgu, sayı, ad ya da tarih ekleme veya '
          . 'değiştirme; uzunluğu kabaca koru.';

    $prompt = $talimat . "\n\nMETİN:\n" . ai_neutralize($metin)
            . "\n\nYalnızca sonuç metnini yaz; başlık, açıklama ya da tırnak ekleme.";

    $res = ai_complete($prompt, [
        'action' => 'tools_' . $kip, 'system' => ai_system_prompt(),
        'max_tokens' => 2000, 'temperature' => $kip === 'shorten' ? 0.2 : 0.4,
    ]);
    if (empty($res['ok'])) { json_err((string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')); }

    $yeni = trim(sanitize_plain((string)arr($res, 'text', ''), 12000));
    if (mb_strlen($yeni) < 10) { json_err('Model kullanılabilir bir metin üretmedi.'); }

    $fark = ai_tools_diff_words($metin, $yeni);
    return [
        'mode'   => $kip,
        'source' => $metin,
        'text'   => $yeni,
        'html'   => ai_tools_paragraflar($yeni),
        'diff'   => $fark,
        'stats'  => ai_tools_diff_stats($fark),
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('aitools.summary', function () {
    ai_tools_gate(true);
    $metin = ai_tools_text('text', 12000);
    $b = json_body();
    $cumle = (int)(array_key_exists('sentences', $b) ? $b['sentences'] : inp_i('sentences', 3));
    $cumle = max(1, min(6, $cumle));

    $prompt = 'Aşağıdaki haber metnini en çok ' . $cumle . ' cümlede özetle. '
            . 'Metinde OLMAYAN hiçbir bilgiyi ekleme; yorum katma.'
            . "\n\nMETİN:\n" . ai_neutralize($metin)
            . "\n\nYalnızca özeti yaz.";

    $res = ai_complete($prompt, [
        'action' => 'tools_summary', 'system' => ai_system_prompt(),
        'max_tokens' => 600, 'temperature' => 0.3,
    ]);
    if (empty($res['ok'])) { json_err((string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')); }

    $ozet = trim(preg_replace('/\s+/u', ' ', sanitize_plain((string)arr($res, 'text', ''), 1500)));
    if (mb_strlen($ozet) < 10) { json_err('Model kullanılabilir bir özet üretmedi.'); }
    return ['text' => $ozet, 'source' => $metin];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('aitools.taxonomy', function () {
    ai_tools_gate(true);
    $b = json_body();
    $baslik = sanitize_line((string)(array_key_exists('title', $b) ? $b['title'] : inp('title', '')), 300);
    $metin = ai_tools_text('text', 8000, $baslik === '');

    // Var olan kategoriler MODELE VERİLİR: uydurma kategori önerisi işe yaramaz.
    $kategoriler = [];
    try {
        foreach (qa('SELECT id, name FROM categories ORDER BY name ASC') as $c) {
            $kategoriler[(int)$c['id']] = (string)$c['name'];
        }
    } catch (Throwable $e) { $kategoriler = []; }

    $liste = $kategoriler ? implode(', ', array_values($kategoriler)) : '(tanımlı kategori yok)';
    $prompt = 'Aşağıdaki haber için EN UYGUN kategoriyi ve en çok 5 etiketi seç.'
            . "\n\nSEÇİLEBİLİR KATEGORİLER: " . ai_neutralize($liste)
            . "\n\nBAŞLIK: " . ai_neutralize($baslik)
            . "\n\nMETİN:\n" . ai_neutralize($metin)
            . "\n\n" . 'Yanıtı YALNIZCA şu JSON biçiminde ver: {"category":"…","tags":["…"]}. '
            . 'Kategori, yukarıdaki listeden BİREBİR bir ad olmalıdır.';

    $res = ai_complete($prompt, [
        'action' => 'tools_taxonomy', 'system' => ai_system_prompt(),
        'max_tokens' => 300, 'temperature' => 0.2,
    ]);
    if (empty($res['ok'])) { json_err((string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')); }

    $data = ai_extract_json((string)arr($res, 'text', ''));
    if (!is_array($data)) { $data = []; }

    // KATEGORİ ADI MODELDEN GELDİĞİ GİBİ KABUL EDİLMEZ: var olan listeye
    // eşleştirilir. Eşleşme yoksa kimlik 0 döner ve arayüz kategoriyi değiştirmez.
    $onerilen = sanitize_line((string)arr($data, 'category', ''), 120);
    $katId = 0;
    $katAd = '';
    foreach ($kategoriler as $id => $ad) {
        if (mb_strtolower($ad, 'UTF-8') === mb_strtolower($onerilen, 'UTF-8')) {
            $katId = (int)$id;
            $katAd = $ad;
            break;
        }
    }

    $etiketler = [];
    foreach ((array)arr($data, 'tags', []) as $t) {
        $t = sanitize_line((string)$t, 60);
        if ($t !== '' && !in_array($t, $etiketler, true)) { $etiketler[] = $t; }
        if (count($etiketler) >= 5) { break; }
    }

    if ($katId === 0 && !$etiketler) { json_err('Model kullanılabilir bir öneri üretmedi.'); }
    return [
        'category_id'   => $katId,
        'category_name' => $katAd,
        'category_raw'  => $onerilen,
        'tags'          => $etiketler,
        'matched'       => $katId > 0,
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('aitools.alt_text', function () {
    ai_tools_gate(true);
    $b = json_body();
    $baslik  = sanitize_line((string)(array_key_exists('title', $b) ? $b['title'] : inp('title', '')), 300);
    $dosya   = sanitize_line((string)(array_key_exists('filename', $b) ? $b['filename'] : inp('filename', '')), 190);
    $baglam  = trim(sanitize_plain(strip_tags((string)(array_key_exists('context', $b) ? $b['context'] : inp('context', ''))), 2000));

    if ($baslik === '' && $dosya === '' && $baglam === '') {
        json_err('Alt metin için en az başlık, dosya adı ya da bir bağlam metni gerekir.');
    }

    /*
     * GÖRSEL MODELE GÖNDERİLMEZ — bilerek.
     * CONTRACTS §13 "otomatik YZ görsel üretimi"ni kapsam dışı bırakıyor ve
     * `ai_complete()` yalnız metin taşıyan tek turlu bir arayüzdür (inc/ai.php,
     * benim dosyam değil). Görsel yüklemek için o dosyanın çok modlu istek
     * gövdesi kurması gerekirdi. Bu yüzden öneri, haber BAĞLAMINDAN üretilir ve
     * arayüz bunu açıkça söyler: editör alt metni GÖRSELE BAKARAK onaylar.
     */
    $prompt = 'Bir haber görselinin ALT METNİNİ yaz. Alt metin, görseli göremeyen '
            . 'okura ne gösterildiğini anlatır; en çok 125 karakter olmalı, '
            . '"görsel", "fotoğraf", "resim" sözcükleriyle BAŞLAMAMALI.'
            . "\n\nHABER BAŞLIĞI: " . ai_neutralize($baslik)
            . "\nDOSYA ADI: " . ai_neutralize($dosya)
            . "\nHABER BAĞLAMI:\n" . ai_neutralize($baglam)
            . "\n\nYalnızca alt metni yaz.";

    $res = ai_complete($prompt, [
        'action' => 'tools_alt_text', 'system' => ai_system_prompt(),
        'max_tokens' => 200, 'temperature' => 0.4,
    ]);
    if (empty($res['ok'])) { json_err((string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')); }

    $alt = sanitize_line((string)arr($res, 'text', ''), 200);
    $alt = trim($alt, " \t\"'“”«»");
    $alt = (string)preg_replace('/^(görsel|fotoğraf|resim|foto)[:\s-]+/iu', '', $alt);
    if (mb_strlen($alt) > 125) { $alt = rtrim(mb_substr($alt, 0, 122)) . '…'; }
    if (mb_strlen($alt) < 5) { json_err('Model kullanılabilir bir alt metin üretmedi.'); }

    return [
        'alt'  => $alt,
        'note' => 'Öneri haber bağlamından üretildi; görsele bakarak doğrulayın.',
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('aitools.diff', function () {
    // YZ çağrısı YOK → dar kova uygulanmaz, ama kapı yine `ai.use`.
    ai_tools_gate(false);
    $b = json_body();
    $a = trim(sanitize_plain(strip_tags((string)(array_key_exists('a', $b) ? $b['a'] : inp('a', ''))), 40000));
    $c = trim(sanitize_plain(strip_tags((string)(array_key_exists('b', $b) ? $b['b'] : inp('b', ''))), 40000));
    if ($a === '' && $c === '') { json_err('Karşılaştırılacak metin yok.'); }
    $fark = ai_tools_diff_words($a, $c);
    return ['diff' => $fark, 'stats' => ai_tools_diff_stats($fark)];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);
