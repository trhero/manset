<?php
/**
 * Manşet — dosya tabanlı sayfa önbelleği (Ajan-10).
 * Sözleşme: CONTRACTS.md §11 — imzalar sabittir, index.php ve cron.php bu adları çağırır.
 *
 * Tasarım kararları
 * ------------------
 * 1. Depo: uploads/cache/<ab>/<cd>/<40 hane sha1>.html — iki seviyeli alt klasör.
 *    Tek klasörde on binlerce dosya biriktiğinde dizin taraması (ve bazı dosya
 *    sistemlerinde açma) yavaşlar; ilk dört haneye göre dağıtım 65 536 kovaya böler.
 *
 * 2. Üstveri AYRI .meta dosyasında DEĞİL, HTML'in ilk satırında bir yorum satırında
 *    durur:  <!--MANSET-CACHE {"t":…,"exp":…,"scope":"post:12","ms":37,"ct":"…"}-->
 *    Gerekçe:
 *      · Yazma tek dosyaya yapılır, dolayısıyla tek rename() ile hem içerik hem
 *        üstveri atomik olarak yerine oturur. İki dosyada içerik/üstveri çiftinin
 *        yarıda kalma (yetim .meta) ihtimali vardır.
 *      · HIT yolunda tek fopen() yeter: fgets() üstveriyi, fread() döngüsü gövdeyi
 *        verir (fpassthru DEĞİL — bkz. cache_serve() sonundaki not).
 *        İki dosyalı tasarımda her istek iki stat + iki açma demektir.
 *      · Kapsam bazlı temizlikte yalnız ilk satır okunur (birkaç yüz bayt).
 *    Bedeli: gövde diskte 1 satır kaydırılmış durur; fpassthru offset ile başlar.
 *
 * 3. Kilit yok. İki istek aynı sayfayı aynı anda üretirse ikisi de geçici dosyaya
 *    yazıp rename() eder; rename atomik olduğu için okuyucu daima bütün bir dosya görür.
 *
 * 4. Disk dolu / klasör yazılamaz durumunda önbellek sessizce devre dışı kalır;
 *    site çalışmaya devam eder, hata saatte bir kez log_error() ile bildirilir.
 *
 * 5. KAPSAM İNDEKSİ (1.3-12). Bir sayfa yalnız bulunduğu YOLUN kapsamına değil,
 *    İÇİNDE GEÇEN her habere/kategoriye de aittir. Anasayfa 12 haber listeliyorsa
 *    o 12 haberin her biri anasayfayı "kirletebilir". Bu ilişki diskte tutulur:
 *      uploads/cache/_idx/<ab>/<sha1(etiket)>.idx  →  satır satır önbellek anahtarı
 *    Böylece `cache_flush('post:12')` bütün önbelleği değil, YALNIZ 12 numaralı
 *    haberin geçtiği sayfaları düşürür. Ayrıntı: cache_store_tags() başlığı.
 *
 * GÜVENLİK — oturum sızıntısı kapıları (cache_end içinde):
 *    · HTTP durumu 200 değilse yazılmaz (404/410/301 önbelleğe girmez).
 *    · Çıktıda dolu bir CSRF anahtarı varsa yazılmaz (kişiye özel değer sızmasın).
 *    · Yanıtta Set-Cookie varsa yazılmaz.
 *    · İstek sırasında PHP oturumu açılmışsa yazılmaz.
 *    Sunma tarafında ise oturum çerezi olan hiçbir istek önbelleğe hiç uğramaz.
 */

require_once __DIR__ . '/bootstrap.php';

// ============================================================ ayarlar / kök

/** Önbellek ayar olarak açık mı? */
function cache_enabled() {
    return setting('cache_enabled', '1') === '1';
}

/** Önbellek kök klasörü (uploads/cache). */
function cache_root() { return CACHE_DIR; }

/** Üstveri satırının ön eki. */
function cache_meta_prefix() { return '<!--MANSET-CACHE '; }

/**
 * Üstveri satırı için okuma tavanı.
 * 1.3'te satıra kapsam etiketleri de girdi; 2 KB'lik eski tavan kalabalık bir
 * anasayfada satırı ORTADAN KESERDİ ve dosya "bozuk" sayılıp her istekte yeniden
 * üretilirdi (sessiz bir önbellek kaybı). Yazma tarafı da aynı tavana uyar.
 */
function cache_meta_max_bytes() { return 16384; }

/**
 * Kök klasörü ve koruma dosyalarını bir kez hazırlar.
 * uploads/ genel bir klasör olduğu için önbellek dosyaları doğrudan indirilemesin diye
 * .htaccess bırakılır (nginx'te etkisizdir; anahtar sha1 olduğu için tahmin edilemez).
 */
function cache_ensure_root() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $root = cache_root();
    if (!ensure_dir($root)) { return $ready = false; }
    $ht = $root . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    $idx = $root . '/index.html';
    if (!is_file($idx)) { @file_put_contents($idx, "<!doctype html><title>Manşet</title>\n"); }
    return $ready = is_dir($root);
}

/** Bayt sayısını okunur metne çevirir (media.php'den bağımsız olsun diye ayrı). */
function cache_human_size($bytes) {
    $b = (float)max(0, (int)$bytes);
    if ($b < 1024) { return (int)$b . ' B'; }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $i = -1;
    do { $b /= 1024; $i++; } while ($b >= 1024 && $i < 3);
    $r = round($b, $b < 10 ? 1 : 0);
    return str_replace('.', ',', (string)$r) . ' ' . $units[$i];
}

/** Yazma hatasını saatte en çok bir kez günlüğe düşürür (log bombası olmasın). */
function cache_report_write_error($detail) {
    static $doneThisRequest = false;
    if ($doneThisRequest) { return; }
    $doneThisRequest = true;
    $last = (int)setting('cache_error_at', '0');
    if ($last > time() - 3600) { return; }
    try { setting_set('cache_error_at', (string)time()); } catch (Throwable $e) { /* yok say */ }
    log_error('Sayfa önbelleği yazamıyor, devre dışı kaldı: ' . $detail, cache_root());
}

// ============================================================ istek denetimi

/** Ziyaretçide oturum çerezi var mı? (current_user() ÇAĞRILMAZ — o oturum açar.) */
function cache_has_session_cookie() {
    if (!empty($_COOKIE['manset_sid'])) { return true; }
    $n = (string)@session_name();
    if ($n !== '' && !empty($_COOKIE[$n])) { return true; }
    // ÖDEME DUVARI ÇEREZİ (1.2-05): bu çerezi taşıyan ziyaretçinin yanıtı kişiye
    // özeldir — kalan hakkına göre tam metin ya da kilit görür. Önbellek anahtarı
    // bu çerezi bilmediği için ona hazır sayfa servis EDİLEMEZ; edilseydi bir
    // okurun kilitli sayfası hakkı olan okura, ya da tersi, sunulurdu.
    // Kapı dar: çerezsiz ziyaretçi ve botlar önbellekten almaya devam eder.
    if (function_exists('paywall_has_cookie') && paywall_has_cookie()) { return true; }
    return false;
}

/** Önbelleğe dâhil edilen (çıktıyı değiştiren) sorgu parametreleri. */
function cache_query_whitelist() {
    // r = SEF kapalı hostinglerde yolun kendisidir, cache_key_for zaten yolu alıyor.
    return ['q', 'sayfa', 'r'];
}

/** Çıktıyı etkilemeyen, sessizce yok sayılan izleme parametreleri. */
function cache_query_ignored() {
    return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_id', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];
}

/** Sorgu dizesi önbelleğe uygun mu? Bilinmeyen her parametre (_p, onizleme…) atlatır. */
function cache_query_is_cacheable($get) {
    if (!is_array($get)) { return false; }
    $ok = cache_query_whitelist();
    $ignore = cache_query_ignored();
    foreach ($get as $k => $v) {
        if (in_array($k, $ok, true) || in_array($k, $ignore, true)) { continue; }
        return false;
    }
    return true;
}

/**
 * Bu isteğe hazır sayfa sunulabilir mi? (CONTRACTS §11)
 * Tüm koşullar sağlanmalı: ayar açık · GET · oturum çerezi yok · açık oturum yok ·
 * sorgu dizesi beyaz listede.
 */
function cache_should_serve() {
    if (!cache_enabled()) { return false; }
    $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
    if ($method !== 'GET') { return false; }
    if (session_status() === PHP_SESSION_ACTIVE) { return false; }
    if (cache_has_session_cookie()) { return false; }
    if (!cache_query_is_cacheable($_GET)) { return false; }
    return true;
}

// ============================================================ kapsam ve anahtar

/**
 * Yoldan kapsam etiketi üretir.
 * 'home' | 'category' | 'tag' | 'search' | 'page' | 'post:<id>' | 'other'
 * ('other' TTL'i 0'dır → önbelleğe alınmaz: robots.txt, bilinmeyen yol, 404 vb.)
 */
function cache_scope_for($path) {
    $path = trim(preg_replace('#/+#', '/', (string)$path), '/');
    if ($path === '') { return 'home'; }
    $seg = explode('/', $path);
    $head = $seg[0];
    if ($head === 'haber') {
        $rest = isset($seg[1]) ? $seg[1] : '';
        if (preg_match('/-(\d+)$/', $rest, $m)) { return 'post:' . (int)$m[1]; }
        return 'other';
    }
    if ($head === 'kategori') { return 'category'; }
    if ($head === 'etiket')   { return 'tag'; }
    // KEŞİF SAYFALARI (1.3-10): yazar, tarih arşivi ve etiket bulutu.
    // Bunlar da tamamen genel, oturumsuz LİSTE sayfalarıdır; `other` kapsamına
    // düşüp TTL 0 aldıkları için hiç önbelleğe alınmıyorlardı (Ajan-E ölçtü).
    // `tag` ile aynı ömrü paylaşırlar ve `cache_list_scopes()` içinde olduğu
    // için bir haber değiştiğinde onlarla birlikte düşerler.
    if ($head === 'yazar' || $head === 'arsiv' || $head === 'etiketler') { return 'tag'; }
    // ARAMA SONUCU ÖNBELLEĞE ALINMAZ (denetim turu 5, D2-03).
    //
    // Her farklı `?q=` ayrı bir önbellek dosyası üretiyordu ve arama yolunda hız
    // sınırı yok. Denetçi ölçtü: 60 anonim istek → 61 dosya / 1,5 MB. Bu tek
    // başına zararsız görünür, ama `cache_sweep()` boyut tavanına gelince EN
    // ESKİYİ atar — yani gerçek trafiğin ısıttığı anasayfa ve haber sayfaları,
    // bir saldırganın ürettiği anlamsız arama sayfaları tarafından tahliye edilir.
    // Önbelleğin zarar verdiği tek yer burasıdır.
    //
    // Terimsiz `/arama` (boş form sayfası) sabittir ve önbelleğe alınabilir.
    // `cache_list_scopes()` içinde 'search' KALIR: eski önbellek girdileri ve
    // hedefli temizlik çağrıları çalışmaya devam etsin.
    if ($head === 'arama') {
        $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
        return $q === '' ? 'search' : 'other';   // 'other' → TTL 0 → yazılmaz
    }
    if ($head === 'sayfa' || $head === 'kunye') { return 'page'; }
    return 'other';
}

/** Liste sayfası kapsamları — 'home' ve 'category' temizliği bunların hepsini düşürür. */
function cache_list_scopes() { return ['home', 'category', 'tag', 'search']; }

/** Kapsamın saniye cinsinden yaşam süresi. 0 → önbelleğe alınmaz. */
function cache_ttl_for($scope) {
    $scope = (string)$scope;
    if (strpos($scope, 'post:') === 0 || $scope === 'page') {
        return max(0, min(86400, (int)setting('cache_ttl_post', '300')));
    }
    if (in_array($scope, cache_list_scopes(), true)) {
        return max(0, min(3600, (int)setting('cache_ttl_home', '60')));
    }
    return 0;
}

// ==================================================== kapsam etiketleri (1.3-12)

/**
 * KAPSAM ETİKETİ NEDİR
 * --------------------
 * `scope` bir sayfanın TÜRÜdür ('home', 'category', 'post:12'). Etiket ise
 * sayfanın İÇERİĞİYLE kurduğu bağdır: anasayfa `home` etiketini taşır ama aynı
 * zamanda listelediği her haberin (`post:12`) ve her kategorinin (`cat:gundem`)
 * etiketini de taşır.
 *
 * Etiket sözlüğü:
 *   home            anasayfa
 *   post:<id>       bu haberin metni ya da bağlantısı sayfada geçiyor
 *   cat:<slug>      bu kategori sayfada geçiyor (kategori sayfasının kendisi dâhil)
 *   tag:<slug>      bu etiket sayfada geçiyor
 *   scope:<tür>     sayfanın kendi türü — eski `cache_flush('category')` çağrıları
 *                   bu etiketle karşılanır (geriye dönük uyum)
 */
function cache_tag_clean($tag) {
    $tag = strtolower(trim((string)$tag));
    if ($tag === '') { return ''; }
    // Etiket bir dosya adına çevrilmeden önce sha1'lenir; yine de kontrolsüz
    // uzunluk ve boşluk kabul edilmez.
    if (strlen($tag) > 120) { $tag = substr($tag, 0, 120); }
    if (!preg_match('/^[a-z0-9:_\-\.%]+$/', $tag)) { return ''; }
    return $tag;
}

/** Yoldan doğan etiketler (sayfanın KENDİ kimliği). */
function cache_path_tags($path) {
    $path = trim(preg_replace('#/+#', '/', (string)$path), '/');
    $scope = cache_scope_for($path);
    $tags = ['scope:' . $scope];
    if ($scope === 'home') { $tags[] = 'home'; }
    if (strpos($scope, 'post:') === 0) { $tags[] = $scope; }
    $seg = explode('/', $path);
    if (isset($seg[0]) && isset($seg[1]) && $seg[1] !== '') {
        if ($seg[0] === 'kategori') { $tags[] = 'cat:' . $seg[1]; }
        if ($seg[0] === 'etiket')   { $tags[] = 'tag:' . $seg[1]; }
    }
    $out = [];
    foreach ($tags as $t) { $t = cache_tag_clean($t); if ($t !== '' && !in_array($t, $out, true)) { $out[] = $t; } }
    return $out;
}

/** Bir sayfanın taşıyabileceği en çok etiket sayısı (indeks yazımını sınırlar). */
function cache_max_tags() { return 300; }

/**
 * ÇIKTIDAN ETİKET ÇIKARMA — neden HTML taranıyor?
 *
 * Hangi haberin hangi sayfada göründüğünü en iyi bilen yer, o sayfayı üreten
 * koddur; ama tema ve `inc/view.php` bu ajanın dosyası DEĞİL ve her tema kendi
 * biçimini kuruyor. Bunun yerine üretilmiş HTML taranır: bir haber bir sayfada
 * görünüyorsa o sayfada ona giden bir bağlantı (`/haber/<slug>-<id>`) de vardır —
 * başlık, "devamı", görsel bağlantısı, JSON-LD, RSS bağlantısı… En az biri.
 *
 * DOĞRULUK YÖNÜ: tarama GENİŞ tutuldu. Yanlış pozitif (ilgisiz sayfayı da
 * düşürmek) yalnız bir MISS'e mal olur; yanlış negatif ise okura ESKİ HABER
 * gösterir. Bu yüzden `haber/…-12` kalıbı yol, sorgu dizesi (`?r=haber/…`),
 * kanonik etiket, JSON-LD, hepsinde aynı biçimde geçtiği için tek bir metin
 * araması bütün biçimleri yakalar.
 *
 * YAKALANMAYAN DURUM (bilerek): bir tema haberin metnini basıp ona HİÇ bağlantı
 * vermezse o sayfa `post:<id>` etiketi almaz. Bu yüzden yeni haber yayımlama /
 * silme gibi LİSTEYİ değiştiren işlemler `['post:<id>', 'home', 'cat:<slug>']`
 * gibi çoklu kapsamla düşürülmelidir (raporda çağrı listesi var).
 */
function cache_html_tags($html) {
    $html = (string)$html;
    $tags = [];
    $ekle = function ($t) use (&$tags) {
        $t = cache_tag_clean($t);
        if ($t !== '' && !isset($tags[$t]) && count($tags) < cache_max_tags()) { $tags[$t] = true; }
    };
    if (preg_match_all('#haber/[^\s"\'<>]*?-(\d+)(?![0-9])#', $html, $m)) {
        foreach ($m[1] as $id) { $id = (int)$id; if ($id > 0) { $ekle('post:' . $id); } }
    }
    /*
     * KATEGORİ VE ETİKET, ÇIKTIDAN ÇIKARILMAZ — ÖLÇÜMLE ALINMIŞ KARAR.
     * İlk uygulamada `/kategori/<slug>` bağlantıları da etikete çevriliyordu.
     * Sonuç: menü her sayfada bütün kategorileri listelediği için her sayfa her
     * kategorinin etiketini taşıdı ve `cache_flush('cat:spor')` önbelleğin
     * TAMAMINI düşürdü (ölçüm: 5 sayfanın 5'i). Yani kapsam vardı ama boştu.
     *
     * Kategori/etiket etiketi artık YALNIZ sayfanın kendi yolundan gelir
     * (cache_path_tags): 'cat:spor' = /kategori/spor sayfasının kendisi.
     * Kayıp yok, çünkü "kategori listesi değişti" durumunun iki hâli de zaten
     * karşılanıyor: haber DEĞİŞTİYSE liste sayfası o haberin `post:<id>`
     * etiketini taşır; haber YENİYSE hiçbir sayfa onu tanımaz ve zaten geniş
     * düşülmesi gerekir (cache_flush('category')).
     */
    return array_keys($tags);
}

/** Kapsam indeksi kök klasörü. Sayfa dosyalarının iki seviyeli ağacının DIŞINDA. */
function cache_index_dir() { return cache_root() . '/_idx'; }

/**
 * İndeks klasörünü hazırlar.
 *
 * KLASÖR YOKSA MEVCUT ÖNBELLEK BİR KEZ SİLİNİR. Neden: indeks yokken yazılmış
 * sayfaların hangi habere ait olduğu bilinmez; onlar diskte kalırsa hedefli bir
 * temizlik onları ATLAR ve okur eski haber görür. Önbellek yeniden üretilebilir
 * bir veridir; bir kerelik boşaltmanın bedeli birkaç MISS'tir.
 */
function cache_index_ensure() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $dir = cache_index_dir();
    if (is_dir($dir)) { return $ready = true; }
    if (!cache_ensure_root()) { return $ready = false; }
    $vardi = count(cache_files()) > 0;
    if (!ensure_dir($dir)) { return $ready = false; }
    if ($vardi) {
        foreach (cache_files() as $f) { @unlink($f); }
        cache_prune_empty_dirs();
    }
    return $ready = true;
}

/** Etiketin indeks dosyası. */
function cache_index_file($tag) {
    $tag = cache_tag_clean($tag);
    if ($tag === '') { return ''; }
    $h = sha1($tag);
    return cache_index_dir() . '/' . substr($h, 0, 2) . '/' . $h . '.idx';
}

/**
 * Anahtarı etiketin indeksine ekler.
 *
 * EŞZAMANLILIK: dosya 'ab' (append) kipinde açılır ve LOCK_EX ile kilitlenir;
 * her satır sabit uzunlukta (40 onaltılık hane + \n). Kilit alınamazsa satır
 * yine de yazılır — POSIX'te O_APPEND yazımı zaten atomiktir; kilit Windows
 * tarafı için ek güvencedir. Yarım satır oluşmaz, dolayısıyla eşzamanlı yazma
 * indeksi bozamaz. (Ölçüm: tests/unit/cache.test.php + paralel süreç turu.)
 */
function cache_index_add($tag, $key) {
    $file = cache_index_file($tag);
    if ($file === '') { return false; }
    $key = preg_replace('/[^a-f0-9]/', '', strtolower((string)$key));
    if (strlen($key) !== 40) { return false; }
    if (!ensure_dir(dirname($file))) { return false; }
    $fh = @fopen($file, 'ab');
    if (!$fh) { return false; }
    @flock($fh, LOCK_EX);
    $ok = @fwrite($fh, $key . "\n");
    @fflush($fh);
    @flock($fh, LOCK_UN);
    fclose($fh);
    return $ok !== false;
}

/** Etiketin indeksindeki anahtarlar (tekrarsız). İndeks dosyası yoksa null. */
function cache_index_read($tag) {
    $file = cache_index_file($tag);
    if ($file === '' || !is_file($file)) { return null; }
    $fh = @fopen($file, 'rb');
    if (!$fh) { return null; }
    @flock($fh, LOCK_SH);
    $keys = [];
    while (($line = fgets($fh, 64)) !== false) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        if (strlen($line) === 40 && ctype_xdigit($line)) { $keys[$line] = true; }
    }
    @flock($fh, LOCK_UN);
    fclose($fh);
    return array_keys($keys);
}

/**
 * Etiketin indeksini boşaltır — dosya SİLİNMEZ, sıfırlanır.
 * Dosyanın var olması "bu etiket indeksleniyor" demektir; silinseydi bir sonraki
 * temizlik indeksi eksik sanıp tüm önbelleği taramak zorunda kalırdı.
 */
function cache_index_clear($tag) {
    $file = cache_index_file($tag);
    if ($file === '' || !is_file($file)) { return; }
    $fh = @fopen($file, 'r+b');
    if (!$fh) { return; }
    @flock($fh, LOCK_EX);
    @ftruncate($fh, 0);
    @flock($fh, LOCK_UN);
    fclose($fh);
}

/** İndekste kayıtlı (dolu) etiket sayısı — panel/durum ekranı için. */
function cache_index_count() {
    $dir = cache_index_dir();
    if (!is_dir($dir)) { return 0; }
    $n = 0;
    foreach ((array)glob($dir . '/*', GLOB_ONLYDIR) as $d) {
        foreach ((array)glob($d . '/*.idx') as $f) { if ((int)@filesize($f) > 0) { $n++; } }
    }
    return $n;
}

/** Tüm indeksi siler (tam temizlikte). */
function cache_index_drop_all() {
    $dir = cache_index_dir();
    if (!is_dir($dir)) { return; }
    foreach ((array)glob($dir . '/*', GLOB_ONLYDIR) as $d) {
        foreach ((array)glob($d . '/*.idx') as $f) { @unlink($f); }
        @rmdir($d);
    }
}

/**
 * İndeksi budar: artık diskte olmayan anahtarların satırlarını atar.
 * Süresi dolan sayfalar cache_sweep() ile silinir ama indekste adları kalır;
 * budanmazsa indeks dosyası sonsuza kadar büyür. Yeniden yazım geçici dosya +
 * rename ile atomiktir, bu sırada gelen bir ekleme en kötü ihtimalle kaybolur —
 * kaybolan satır yalnız o sayfanın hedefli temizlikte atlanması demek olurdu,
 * bu yüzden budama YALNIZ cron süpürmesinde (cache_sweep) yapılır ve budamadan
 * sonra temizlik damgası atılır (o an üretilmekte olan sayfalar yazılmaz).
 *
 * @return int budanan satır sayısı
 */
function cache_index_compact() {
    $dir = cache_index_dir();
    if (!is_dir($dir)) { return 0; }
    $atilan = 0;
    foreach ((array)glob($dir . '/*', GLOB_ONLYDIR) as $d) {
        foreach ((array)glob($d . '/*.idx') as $f) {
            $fh = @fopen($f, 'r+b');
            if (!$fh) { continue; }
            @flock($fh, LOCK_EX);
            $kalan = [];
            $vardi = 0;
            while (($line = fgets($fh, 64)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') { continue; }
                $vardi++;
                if (isset($kalan[$line])) { continue; }
                $pf = cache_file($line);
                if ($pf !== '' && is_file($pf)) { $kalan[$line] = true; }
            }
            if (count($kalan) < $vardi) {
                $atilan += $vardi - count($kalan);
                @ftruncate($fh, 0);
                @rewind($fh);
                if ($kalan) { @fwrite($fh, implode("\n", array_keys($kalan)) . "\n"); }
            }
            @flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
    if ($atilan > 0) { cache_flush_marker_touch(); }
    return $atilan;
}

/** Son temizlik damgası dosyası (yarış kalkanı — cache_store başlığına bakın). */
function cache_flush_marker_file() { return cache_index_dir() . '/.flushed'; }

/**
 * Son temizliğin zamanı (float epoch). Damga yoksa 0.0.
 *
 * MİKROSANİYE ÇÖZÜNÜRLÜK ZORUNLU. Damga eskiden `filemtime()` ile okunuyordu ve
 * o SANİYE çözünürlüklüdür; `cache_store()` karşılaştırması ise `>=`. Sonuç:
 * temizlikle AYNI SANİYE içinde başlayan — yani temizlikten hemen SONRAKİ —
 * istek, sayfayı önbelleğe hiç yazmıyordu.
 *
 * Bu, e2e'de "anasayfa ikinci istek HIT beklendi" olarak göründü ama asıl etkisi
 * çok daha genişti: canlı bir sitede her içerik yazımı bir temizlik tetikler,
 * dolayısıyla o saniye içinde üretilen HER sayfa boşa üretiliyordu. 1.3-12'nin
 * amacı tam da önbelleği daha çok kullanmaktı.
 *
 * Zaman dosyanın İÇİNE yazılır; `filemtime` yalnız eski damgalar için yedektir.
 */
function cache_flush_marker_time() {
    $f = cache_flush_marker_file();
    clearstatcache(true, $f);
    if (!is_file($f)) { return 0.0; }
    $ham = trim((string)@file_get_contents($f));
    if ($ham !== '' && is_numeric($ham)) { return (float)$ham; }
    $t = (int)@filemtime($f);
    return $t > 0 ? (float)$t : 0.0;
}

/** Temizlik damgasını 'şimdi'ye çeker (mikrosaniye duyarlı). */
function cache_flush_marker_touch() {
    $f = cache_flush_marker_file();
    if (!ensure_dir(dirname($f))) { return; }
    // Dosyanın İÇERİĞİ otoritedir; touch() yalnız mtime'ı da güncel tutmak için.
    @file_put_contents($f, sprintf('%.6F', microtime(true)), LOCK_EX);
    clearstatcache(true, $f);
}

/**
 * Kapsam adını hedef etiket listesine çevirir.
 * null dönerse "hepsini sil" demektir (bilinmeyen kapsamda güvenli taraf).
 */
function cache_scope_targets($scope) {
    $scope = cache_tag_clean($scope);
    if ($scope === '') { return null; }
    if (strpos($scope, 'post:') === 0 && (int)substr($scope, 5) > 0) { return [$scope]; }
    if (strpos($scope, 'cat:') === 0 && strlen($scope) > 4)          { return [$scope]; }
    if (strpos($scope, 'tag:') === 0 && strlen($scope) > 4)          { return [$scope]; }
    if ($scope === 'home')  { return ['home']; }
    if ($scope === 'page')  { return ['scope:page']; }
    // 1.2 adları: tek bir liste türü verilse de bütün liste sayfaları düşer —
    // eski çağrıların anlamı buydu, korunuyor.
    if (in_array($scope, cache_list_scopes(), true)) {
        return ['home', 'scope:category', 'scope:tag', 'scope:search'];
    }
    return null;
}

/**
 * İstek için önbellek anahtarı üretir (sha1). Önbelleğe alınmayacak istekte '' döner.
 * Anahtara giren bileşenler: yol · beyaz listedeki sorgu parametreleri · etkin tema ·
 * yazılım sürümü · taban adres · SEF durumu.
 * Tema veya SEF ayarı değişince anahtar da değişir; eski dosyalar kendiliğinden ölür.
 */
function cache_key_for($path, $get) {
    if (!cache_enabled()) { return ''; }
    $get = is_array($get) ? $get : [];
    if (!cache_query_is_cacheable($get)) { return ''; }

    $path = trim(preg_replace('#/+#', '/', (string)$path), '/');
    $scope = cache_scope_for($path);
    if (cache_ttl_for($scope) <= 0) { return ''; }

    $theme = function_exists('theme_name') ? theme_name() : setting('theme', 'gazete');
    $parts = [
        'p=' . $path,
        'q=' . (isset($get['q']) ? (string)$get['q'] : ''),
        's=' . (isset($get['sayfa']) ? (int)$get['sayfa'] : 1),
        'th=' . $theme,
        'v=' . MANSET_VERSION,
        'b=' . base_url(),
        'sef=' . ((function_exists('sef_enabled') && sef_enabled()) ? '1' : '0'),
    ];
    $key = sha1(implode("\n", $parts));

    // Kapsamı ve YOLU cache_begin() için sakla (routeHead haber kimliğini de,
    // kategori kısa adını da taşımıyor; kapsam indeksi ikisine de muhtaç).
    $GLOBALS['manset_cache_scope'] = $scope;
    $GLOBALS['manset_cache_path'] = $path;
    return $key;
}

/** Anahtardan dosya yolu: uploads/cache/ab/cd/<anahtar>.html */
function cache_file($key) {
    $key = preg_replace('/[^a-f0-9]/', '', strtolower((string)$key));
    if (strlen($key) !== 40) { return ''; }
    return cache_root() . '/' . substr($key, 0, 2) . '/' . substr($key, 2, 2) . '/' . $key . '.html';
}

// ============================================================ üstveri

/** Üstveri satırını çözer; geçersizse null. */
function cache_parse_meta($line) {
    $line = (string)$line;
    $pre = cache_meta_prefix();
    if (strncmp($line, $pre, strlen($pre)) !== 0) { return null; }
    $end = strpos($line, '-->');
    if ($end === false) { return null; }
    $json = substr($line, strlen($pre), $end - strlen($pre));
    $m = json_decode((string)$json, true);
    if (!is_array($m) || !isset($m['exp']) || !isset($m['t'])) { return null; }
    $m['exp']   = (int)$m['exp'];
    $m['t']     = (int)$m['t'];
    $m['scope'] = isset($m['scope']) ? (string)$m['scope'] : 'other';
    $m['ms']    = isset($m['ms']) ? (int)$m['ms'] : 0;
    $m['ct']    = isset($m['ct']) ? (string)$m['ct'] : 'text/html; charset=utf-8';
    // 'sc' = kapsam etiketleri (1.3-12). Alan YOKSA null bırakılır: "bilinmiyor"
    // ile "etiketi yok" ayrı şeylerdir — bilinmeyen dosya hedefli temizlikte
    // güvenli tarafa düşer (silinir).
    $m['sc'] = (isset($m['sc']) && is_array($m['sc'])) ? array_values(array_filter(array_map('strval', $m['sc']))) : null;
    return $m;
}

/** Dosyanın yalnız ilk satırını okuyup üstveriyi çözer. */
function cache_read_meta_file($file) {
    $fh = @fopen($file, 'rb');
    if (!$fh) { return null; }
    $line = fgets($fh, cache_meta_max_bytes());
    fclose($fh);
    return cache_parse_meta($line);
}

// ============================================================ sunma

/** İstemcinin kopyası hâlâ taze mi? (If-None-Match / If-Modified-Since) */
function cache_client_has_fresh_copy($etag, $created) {
    $inm = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) : '';
    if ($inm !== '') {
        if ($inm === '*') { return true; }
        foreach (explode(',', $inm) as $cand) {
            $cand = trim($cand);
            if (stripos($cand, 'W/') === 0) { $cand = trim(substr($cand, 2)); }
            if ($cand === $etag) { return true; }
        }
        return false; // ETag gönderilmiş ama tutmuyor → tam yanıt ver
    }
    $ims = isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) ? (string)$_SERVER['HTTP_IF_MODIFIED_SINCE'] : '';
    if ($ims !== '') {
        $ts = strtotime($ims);
        if ($ts !== false && $ts >= (int)$created) { return true; }
    }
    return false;
}

/**
 * Hazır sayfayı basar. Basıldıysa true döner (index.php exit eder).
 * Taze dosya yoksa hiçbir çıktı vermeden false döner.
 */
function cache_serve($key) {
    if (!cache_enabled()) { return false; }
    $file = cache_file($key);
    if ($file === '' || !is_file($file)) { return false; }

    $fh = @fopen($file, 'rb');
    if (!$fh) { return false; }
    $meta = cache_parse_meta(fgets($fh, cache_meta_max_bytes()));
    if (!$meta || $meta['exp'] <= time()) { fclose($fh); return false; }

    $offset = (int)ftell($fh);
    $size = (int)@filesize($file);
    $len = max(0, $size - $offset);
    if ($len <= 0) { fclose($fh); return false; }

    $created = $meta['t'];
    $etag = '"' . substr((string)$key, 0, 16) . '-' . dechex($created) . '"';

    if (!headers_sent()) {
        // render() ile aynı güvenlik başlıkları — bu yol render()'ı atladığı için
        // başlıklar burada yeniden verilmek zorunda.
        header('Content-Type: ' . $meta['ct']);
        // Bu yol render()'ı ATLAR; başlıklar burada yeniden verilmek zorunda.
        // Tek kaynaktan çağrılır, yoksa önbellekten sunulan sayfa CSP'siz kalırdı
        // — yani sitenin ÇOĞU sayfası korumasız olurdu.
        manset_security_headers('html');
        header('X-Manset-Cache: HIT');
        header('X-Manset-Cache-Age: ' . max(0, time() - $created));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $created) . ' GMT');
        header('ETag: ' . $etag);
        // Tarayıcı her seferinde doğrulasın: içerik güncellenince anında düşsün,
        // değişmediyse 304 ile gövde hiç aktarılmasın.
        header('Cache-Control: public, max-age=0, must-revalidate');
        // Cookie de VARY listesinde olmalı (denetim tur 2, paywall B04): bu yanıt
        // yalnız OTURUMSUZ ziyaretçi için üretildi; araya giren bir vekil onu
        // giriş yapmış birine sunmamalı.
        header('Vary: Accept-Encoding, Cookie');
    }

    if (cache_client_has_fresh_copy($etag, $created)) {
        fclose($fh);
        if (!headers_sent()) {
            http_response_code(304);
            @header_remove('Content-Type');
        }
        return true;
    }

    if (!headers_sent()) { header('Content-Length: ' . $len); }

    // NEDEN fpassthru() DEĞİL:
    // Paylaşımlı hostinglerin bir kısmı `fpassthru`yu `disable_functions` ile
    // KAPATIR (readfile/passthru ailesiyle birlikte toptan kapatılır). Kapalı
    // olduğunda bu satır ölümcül hata verir ve sonuç şuydu: sayfanın İLK
    // ziyareti çalışır (önbellek yazma yolu), YENİLEMESİ hata sayfası döner
    // (önbellek okuma yolu). Yani sitenin çoğu sayfası ikinci istekte ölür.
    // Bir kullanıcı bunu canlı hostingde bildirdi.
    //
    // fread/echo döngüsü aynı işi yapar, hiçbir yerde kapatılmaz ve belleği de
    // aynı şekilde korur (dosya tek seferde belleğe alınmaz).
    while (!feof($fh)) {
        $parca = fread($fh, 65536);
        if ($parca === false || $parca === '') { break; }
        echo $parca;
    }
    fclose($fh);
    return true;
}

// ============================================================ üretme

/** Üretim bağlamı (tek istek boyunca). */
function cache_ctx() {
    if (!isset($GLOBALS['manset_cache_ctx'])) { $GLOBALS['manset_cache_ctx'] = null; }
    return $GLOBALS['manset_cache_ctx'];
}

/**
 * Sayfa üretimini tampona alır. Çıktı cache_end() ile hem istemciye hem diske gider.
 * @param string $key       cache_key_for() çıktısı
 * @param string $routeHead Yolun ilk parçası (kapsam yedeği)
 */
function cache_begin($key, $routeHead) {
    if (!cache_enabled()) { return; }
    $file = cache_file($key);
    if ($file === '' || !cache_ensure_root()) { return; }

    $scope = isset($GLOBALS['manset_cache_scope']) ? (string)$GLOBALS['manset_cache_scope'] : '';
    if ($scope === '') { $scope = cache_scope_for((string)$routeHead); }
    $ttl = cache_ttl_for($scope);
    if ($ttl <= 0) { return; }

    if (!headers_sent()) { header('X-Manset-Cache: MISS'); }

    $path = isset($GLOBALS['manset_cache_path']) ? (string)$GLOBALS['manset_cache_path'] : (string)$routeHead;

    $GLOBALS['manset_cache_ctx'] = [
        'key'    => $key,
        'file'   => $file,
        'scope'  => $scope,
        'path'   => $path,
        'ttl'    => $ttl,
        'start'  => microtime(true),
        'level'  => ob_get_level(),
        'active' => true,
    ];
    ob_start();
}

/** Tamponu istemciye boşaltır ve içeriği döndürür. */
function cache_flush_buffers(array $ctx) {
    while (ob_get_level() > (int)$ctx['level'] + 1) { @ob_end_flush(); }
    if (ob_get_level() <= (int)$ctx['level']) { return null; } // tampon başkasınca kapatılmış
    $html = (string)ob_get_contents();
    @ob_end_flush();
    return $html;
}

/**
 * Çıktıda kişiye özel bir CSRF anahtarı var mı?
 * Ön yüz formları csrf_field_lazy() ile BOŞ alan basar; dolu bir değer görmek
 * oturumun açıldığı (ya da bir temanın csrf_token() kullandığı) anlamına gelir.
 * Böyle bir sayfa asla önbelleğe yazılmaz — aksi hâlde bir ziyaretçinin anahtarı
 * başka ziyaretçilere servis edilirdi.
 */
function cache_output_has_session_leak($html) {
    if (preg_match('/data-csrf\s*=\s*"[^"]+"/i', $html)) { return true; }
    if (preg_match("/data-csrf\\s*=\\s*'[^']+'/i", $html)) { return true; }
    if (preg_match_all('/<input\b[^>]*>/i', $html, $mm)) {
        foreach ($mm[0] as $tag) {
            if (!preg_match('/name\s*=\s*["\']?_csrf["\']?/i', $tag)) { continue; }
            if (preg_match('/value\s*=\s*"([^"]+)"/i', $tag)) { return true; }
            if (preg_match("/value\\s*=\\s*'([^']+)'/i", $tag)) { return true; }
        }
    }
    return false;
}

/** Yanıtta Set-Cookie var mı? */
function cache_response_sets_cookie() {
    foreach ((array)headers_list() as $h) {
        if (stripos($h, 'set-cookie:') === 0) { return true; }
    }
    return false;
}

/**
 * Sayfanın etiketlerini hesaplar: yolun kimliği + çıktıda geçen haber/kategori.
 * (Ayrı işlev, çünkü birim testte HTML verip doğrulanabilmesi gerekiyor.)
 */
function cache_store_tags(array $ctx, $html) {
    $tags = cache_path_tags(isset($ctx['path']) ? (string)$ctx['path'] : '');
    foreach (cache_html_tags($html) as $t) {
        if (!in_array($t, $tags, true)) { $tags[] = $t; }
        if (count($tags) >= cache_max_tags()) { break; }
    }
    return $tags;
}

/**
 * Üretilen sayfayı diske yazar (geçici dosya + rename ile atomik).
 *
 * YARIŞ KALKANI. Bir sayfa üretilirken araya bir temizlik girerse, üretim
 * temizlikten ÖNCEKİ veriyle başlamış olabilir; onu temizlikten sonra diske
 * yazmak okura eski haberi TTL boyunca göstermek demektir. Bu yüzden yazımdan
 * hemen önce son temizlik damgasına bakılır: damga bu isteğin başlangıcından
 * yeni ya da aynı saniyedeyse sayfa YAZILMAZ (bedeli tek bir MISS).
 * Yerleşik sunucu istekleri sıraya soktuğu için bu yarış HTTP üzerinden
 * ölçülemez; paralel PHP süreçleriyle ölçüldü (1.3 sözleşmesi §1.3).
 */
function cache_store(array $ctx, $html) {
    $file = (string)$ctx['file'];
    $dir = dirname($file);
    if (!ensure_dir($dir)) { cache_report_write_error('klasör oluşturulamadı: ' . $dir); return false; }
    cache_index_ensure();

    // Araya temizlik girdiyse bu üretim BAYATTIR ve yazılmamalıdır.
    // Karşılaştırma float: saniyeye yuvarlamak, temizlikten hemen sonraki
    // isteği de yanlışlıkla eleyordu (yukarıdaki açıklama).
    $basladi = (float)$ctx['start'];
    if (cache_flush_marker_time() >= $basladi) { return false; }

    // Etiketler ÖNCE indekse yazılır, sayfa SONRA yerine oturur. Ters sırada
    // yazılsaydı, indekse girmemiş bir sayfa hedefli temizlikte atlanabilirdi.
    $tags = cache_store_tags($ctx, $html);
    foreach ($tags as $t) { cache_index_add($t, (string)$ctx['key']); }

    $now = time();
    $meta = [
        'v'     => 2,
        't'     => $now,
        'exp'   => $now + (int)$ctx['ttl'],
        'scope' => (string)$ctx['scope'],
        'sc'    => $tags,
        'ms'    => (int)round((microtime(true) - (float)$ctx['start']) * 1000),
        'ct'    => 'text/html; charset=utf-8',
    ];
    $line = cache_meta_prefix()
          . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
          . "-->\n";
    if (strlen($line) > cache_meta_max_bytes() - 64) {
        // Etiket listesi satıra sığmadı. Satırı KESMEK yerine listeyi tümden
        // düşürürüz: yarım liste "bu haber burada yok" yalanını söyler ve okura
        // eski haber gösterir. Listesiz dosya ise hedefli temizlikte "bilinmiyor"
        // sayılıp silinir — geniş düşmek, eksik düşmekten iyidir.
        unset($meta['sc']);
        $line = cache_meta_prefix()
              . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
              . "-->\n";
    }

    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $bytes = @file_put_contents($tmp, $line . $html);
    if ($bytes === false || $bytes < strlen($line)) {
        @unlink($tmp);
        cache_report_write_error('geçici dosya yazılamadı: ' . basename($tmp));
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        cache_report_write_error('rename başarısız: ' . basename($file));
        return false;
    }
    @chmod($file, 0644);
    return true;
}

/** Üretimi bitirir: çıktıyı basar ve uygunsa önbelleğe yazar. */
function cache_end() {
    $ctx = cache_ctx();
    if (!$ctx || empty($ctx['active'])) { return; }
    $GLOBALS['manset_cache_ctx']['active'] = false;

    $html = cache_flush_buffers($ctx);
    if ($html === null) { return; }

    // --- güvenlik ve doğruluk kapıları
    $code = http_response_code();
    if ($code !== false && (int)$code !== 200) { return; }          // 404/410/301 önbelleğe girmez
    if (trim($html) === '') { return; }
    if (session_status() === PHP_SESSION_ACTIVE) { return; }        // istek sırasında oturum açılmış
    if (cache_response_sets_cookie()) { return; }
    if (cache_output_has_session_leak($html)) {
        log_error('Önbellek yazımı iptal: çıktıda dolu CSRF anahtarı var', (string)$ctx['scope']);
        return;
    }

    cache_store($ctx, $html);
}

/** Üretimi iptal eder: çıktı istemciye gider ama diske YAZILMAZ. */
function cache_abort() {
    $ctx = cache_ctx();
    if (!$ctx || empty($ctx['active'])) { return; }
    $GLOBALS['manset_cache_ctx']['active'] = false;
    while (ob_get_level() > (int)$ctx['level']) { @ob_end_flush(); }
}

// ============================================================ dosya gezinme

/** Önbellekteki tüm sayfa dosyalarının yollarını döndürür. */
function cache_files() {
    $out = [];
    $root = cache_root();
    if (!is_dir($root)) { return $out; }
    foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $d1) {
        // '_' ile başlayan klasörler yönetim içindir (_idx), sayfa taşımaz.
        if (strncmp(basename($d1), '_', 1) === 0) { continue; }
        foreach ((array)glob($d1 . '/*', GLOB_ONLYDIR) as $d2) {
            foreach ((array)glob($d2 . '/*.html') as $f) { $out[] = $f; }
        }
    }
    return $out;
}

/** Boşalan iki seviyeli klasörleri toplar; silinen klasör sayısını döndürür. */
function cache_prune_empty_dirs() {
    $root = cache_root();
    if (!is_dir($root)) { return 0; }
    $n = 0;
    foreach ((array)glob($root . '/*', GLOB_ONLYDIR) as $d1) {
        // _idx TOPLANMAZ: silinseydi bir sonraki istek "indeks yok" sanıp
        // önbelleğin tamamını boşaltırdı (cache_index_ensure).
        if (strncmp(basename($d1), '_', 1) === 0) { continue; }
        foreach ((array)glob($d1 . '/*', GLOB_ONLYDIR) as $d2) {
            $items = @scandir($d2);
            if (is_array($items) && count($items) <= 2 && @rmdir($d2)) { $n++; }
        }
        $items = @scandir($d1);
        if (is_array($items) && count($items) <= 2 && @rmdir($d1)) { $n++; }
    }
    return $n;
}

// ============================================================ temizleme

/** Anahtar listesini siler; silinen dosya sayısını döndürür. */
function cache_delete_keys(array $keys) {
    $n = 0;
    foreach ($keys as $k) {
        $f = cache_file($k);
        if ($f !== '' && is_file($f) && @unlink($f)) { $n++; }
    }
    return $n;
}

/**
 * İndeks olmadan, üstveriyi okuyarak etiket eşleşen dosyaları siler.
 * Yalnız etiketin indeks dosyası HİÇ yoksa (ya da üstverisi etiketsiz dosyalar
 * varsa) çalışır. Üstverisi okunamayan ya da etiket listesi olmayan dosya
 * "bilinmiyor" sayılır ve SİLİNİR — eksik düşmektense geniş düşmek.
 */
function cache_purge_scan(array $tags) {
    $n = 0;
    foreach (cache_files() as $f) {
        $m = cache_read_meta_file($f);
        if ($m === null || $m['sc'] === null) { if (@unlink($f)) { $n++; } continue; }
        foreach ($tags as $t) {
            if (in_array($t, $m['sc'], true)) { if (@unlink($f)) { $n++; } break; }
        }
    }
    return $n;
}

/**
 * Verilen kapsamı siler ve silinen dosya sayısını döndürür.
 * cache_flush() bunun void sarmalayıcısıdır (sözleşme imzası).
 *
 * @param string|array|null $scope null → HEPSİ (1.2 davranışı, aynen korunur)
 *                                 'post:12' | 'cat:gundem' | 'tag:deprem' | 'home' | 'page'
 *                                 dizi → birden çok kapsam tek turda
 */
function cache_purge($scope = null) {
    $root = cache_root();
    if (!is_dir($root)) { return 0; }

    // --- kapsam listesini normalleştir
    $scopes = is_array($scope) ? $scope : (($scope === null || $scope === '') ? [] : [$scope]);
    $targets = [];
    $hepsi = ($scopes === []);
    foreach ($scopes as $s) {
        $t = cache_scope_targets($s);
        if ($t === null) { $hepsi = true; break; }   // bilinmeyen kapsam → güvenli taraf
        foreach ($t as $tag) { if (!in_array($tag, $targets, true)) { $targets[] = $tag; } }
    }

    // Damga ÖNCE atılır: bu an ile silme arasında biten bir üretim, kendi
    // başlangıcını damgadan eski görüp diske yazmaktan vazgeçer.
    cache_flush_marker_touch();

    if ($hepsi) {
        $n = 0;
        foreach (cache_files() as $f) { if (@unlink($f)) { $n++; } }
        cache_index_drop_all();
        cache_prune_empty_dirs();
        return $n;
    }

    $n = 0;
    $scanTags = [];
    foreach ($targets as $tag) {
        $keys = cache_index_read($tag);
        if ($keys === null) {
            // İndeks dosyası yok: bu etiket hiç yazılmamış olabilir de, indeks
            // kaybolmuş da olabilir. Ayırt edemeyiz → tarayarak temizleriz.
            $scanTags[] = $tag;
            continue;
        }
        $n += cache_delete_keys($keys);
        cache_index_clear($tag);
    }
    if ($scanTags) { $n += cache_purge_scan($scanTags); }
    if ($n > 0) { cache_prune_empty_dirs(); }
    return $n;
}

/**
 * Önbelleği temizler (CONTRACTS §11 imzası — dönüş değeri yoktur).
 * @param string|array|null $scope 'home' | 'post:<id>' | 'cat:<slug>' | 'tag:<slug>' |
 *                                 'page' | 1.2 adları ('category','tag','search') |
 *                                 bunların dizisi | null = hepsi
 */
function cache_flush($scope = null) {
    try { cache_purge($scope); } catch (Throwable $e) { log_error('cache_flush: ' . $e->getMessage()); }
}

/**
 * Cron süpürmesi: süresi geçmiş dosyaları siler, boyut sınırını uygular,
 * boş klasörleri toplar. Özet metin döndürür.
 */
function cache_sweep() {
    $root = cache_root();
    if (!is_dir($root)) { return 'önbellek klasörü yok'; }

    $now = time();
    $expired = 0;
    $bytes = 0;
    $rows = [];
    foreach (cache_files() as $f) {
        $m = cache_read_meta_file($f);
        $size = (int)@filesize($f);
        if ($m === null || $m['exp'] <= $now) {
            if (@unlink($f)) { $expired++; }
            continue;
        }
        $bytes += $size;
        $rows[] = ['f' => $f, 'size' => $size, 't' => $m['t']];
    }

    // Boyut sınırı: en eskiden başlayarak buda (hedef, sınırın %80'i)
    $maxMb = (int)setting('cache_max_mb', '200');
    if ($maxMb <= 0) { $maxMb = 200; }
    $max = $maxMb * 1048576;
    $trimmed = 0;
    if ($bytes > $max) {
        usort($rows, function ($a, $b) { return $a['t'] <=> $b['t']; });
        $target = (int)($max * 0.8);
        foreach ($rows as $r) {
            if ($bytes <= $target) { break; }
            if (@unlink($r['f'])) { $bytes -= $r['size']; $trimmed++; }
        }
    }

    $dirs = cache_prune_empty_dirs();
    $budanan = cache_index_compact();
    $kept = count($rows) - $trimmed;
    return $expired . ' süresi geçmiş + ' . $trimmed . ' boyut aşımı dosyası silindi; '
         . max(0, $kept) . ' dosya kaldı (' . cache_human_size($bytes) . '), '
         . $dirs . ' boş klasör toplandı, '
         . $budanan . ' ölü kapsam kaydı budandı';
}

// ============================================================ durum

/** Panel/API için önbellek durumu. */
function cache_stats() {
    $files = 0;
    $bytes = 0;
    $oldest = 0;
    $newest = 0;
    foreach (cache_files() as $f) {
        $files++;
        $bytes += (int)@filesize($f);
        $t = (int)@filemtime($f);
        if ($t > 0) {
            if ($oldest === 0 || $t < $oldest) { $oldest = $t; }
            if ($t > $newest) { $newest = $t; }
        }
    }
    $root = cache_root();
    return [
        'enabled'   => cache_enabled(),
        'dir'       => $root,
        'writable'  => is_dir($root) && is_writable($root),
        'files'     => $files,
        'bytes'     => $bytes,
        'size_text' => cache_human_size($bytes),
        'oldest'    => $oldest ? date('Y-m-d H:i:s', $oldest) : '',
        'newest'    => $newest ? date('Y-m-d H:i:s', $newest) : '',
        'scopes'    => cache_index_count(),
        'ttl_home'  => (int)setting('cache_ttl_home', '60'),
        'ttl_post'  => (int)setting('cache_ttl_post', '300'),
        'max_mb'    => (int)setting('cache_max_mb', '200'),
    ];
}
