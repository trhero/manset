<?php
/**
 * e2e yardımcı betiği — kurulu bir örneğe soru sorar.
 *
 * Kullanım:
 *   php tests/probe.php <kök> can <rol> <izin> [is_responsible]
 *   php tests/probe.php <kök> tier <user_id>
 *   php tests/probe.php <kök> locked
 *   php tests/probe.php <kök> roles
 *   php tests/probe.php <kök> session_after_body
 *   php tests/probe.php <kök> hash_token <ham>
 *   php tests/probe.php <kök> missing_perms
 *   php tests/probe.php <kök> unused_perms
 *   php tests/probe.php <kök> pw_stamp_binds
 *   php tests/probe.php <kök> rate_limit_order
 *   php tests/probe.php <kök> srcset_gate
 *   php tests/probe.php <kök> dup_api
 *   php tests/probe.php <kök> backup_secret
 *   php tests/probe.php <kök> mysql_ping
 *   php tests/probe.php <kök> paywall_leak
 *   php tests/probe.php <kök> guvenlik_basliklari
 *   php tests/probe.php <kök> cors
 *   php tests/probe.php <kök> bagimlilik
 *   php tests/probe.php <kök> demo_gate
 *
 * NEDEN AYRI BETİK: kabuktan `php -r` ile kod göndermek Git Bash'te kırılgan —
 * MSYS yol dönüşümü yalnız ARGÜMAN konumundaki yolları çevirir, kod dizesinin
 * içine gömülü `/d/manset/...` yolunu Windows PHP çözemez. Kök yolu burada
 * argüman olarak alınır ve doğru şekilde dönüştürülür.
 *
 * YALNIZ komut satırından çalışır ve dağıtım paketine girmez.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Yalnız komut satırı.\n";
    exit(1);
}

$root = isset($argv[1]) ? rtrim(str_replace('\\', '/', $argv[1]), '/') : '';
if ($root === '' || !is_file($root . '/inc/bootstrap.php')) {
    fwrite(STDERR, "Kurulum kökü bulunamadı: " . $root . "\n");
    exit(1);
}
require_once $root . '/inc/bootstrap.php';

$soru = isset($argv[2]) ? (string)$argv[2] : '';

switch ($soru) {

    // Bir rolün belirli izne sahip olup olmadığı
    case 'can':
        $rol  = isset($argv[3]) ? (string)$argv[3] : '';
        $izin = isset($argv[4]) ? (string)$argv[4] : '';
        $sorumlu = isset($argv[5]) ? (int)$argv[5] : 0;
        $kullanici = [
            'id' => 0, 'role' => $rol, 'active' => 1,
            'is_staff' => 1, 'is_responsible' => $sorumlu,
        ];
        echo can($kullanici, $izin) ? 'ACIK' : 'KAPALI';
        break;

    // Üyelik kademesi
    case 'tier':
        $uid = isset($argv[3]) ? (int)$argv[3] : 0;
        if (!function_exists('member_tier')) { echo 'yok'; break; }
        echo member_tier(['id' => $uid, 'role' => 'member', 'active' => 1]);
        break;

    // Kilitli izin listesi
    case 'locked':
        echo function_exists('roles_locked_permissions') ? implode(',', roles_locked_permissions()) : '';
        break;

    // Rol listesi
    case 'roles':
        echo function_exists('roles_all') ? implode(',', array_keys(roles_all())) : '';
        break;

    // Haber gövdesi basıldıktan sonra oturum açılıyor mu? (önbelleklenebilirlik kapısı)
    case 'session_after_body':
        require_once $root . '/inc/view.php';
        $id = (int)qv('SELECT id FROM posts WHERE status = \'published\' ORDER BY id ASC LIMIT 1', [], 0);
        $post = post_by_id($id);
        if ($post) { post_body_html($post); }
        echo session_status() === PHP_SESSION_ACTIVE ? 'ACIK' : 'KAPALI';
        break;

    // Bir dizeyi uygulamanın üye token'ları için kullandığı biçimde özetler
    case 'hash_token':
        echo hash('sha256', isset($argv[3]) ? (string)$argv[3] : '');
        break;

    // Kapı olarak KULLANILAN ama katalogda TANIMLI OLMAYAN izinler.
    // Denetim tur 2 B07: `ads.manage` ve `corrections.manage` böyle ölmüştü —
    // fail-closed oldukları için hiçbir hata vermeden iki rolü işlevsiz bıraktılar.
    case 'missing_perms':
        $tanimli = array_keys(roles_permissions());
        $kullanilan = [];
        $tarama = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($tarama as $dosya) {
            $yol = str_replace('\\', '/', $dosya->getPathname());
            if (substr($yol, -4) !== '.php') { continue; }
            if (strpos($yol, '/tests/') !== false) { continue; }
            $src = (string)file_get_contents($yol);
            $desenler = [
                "/require_can\(\s*'([a-z_]+\.[a-z_]+)'/",
                "/'perm'\s*=>\s*'([a-z_]+\.[a-z_]+)'/",
                "/\bcan\(\s*\\\$[a-zA-Z_]+\s*,\s*'([a-z_]+\.[a-z_]+)'/",
            ];
            foreach ($desenler as $d) {
                if (preg_match_all($d, $src, $m)) {
                    $kullanilan = array_merge($kullanilan, $m[1]);
                }
            }
        }
        $eksik = array_values(array_unique(array_diff($kullanilan, $tanimli)));
        sort($eksik);
        echo implode(',', $eksik);
        break;

    // Katalogda TANIMLI ama hiçbir kapıda KULLANILMAYAN izinler.
    //
    // missing_perms'in TERSİ. Tur 2'nin iki kritiği de "kural vardı, kapı yoktu"
    // sınıfındandı: `theme.css` izni tanımlıydı ama okunmuyordu, ödeme duvarı
    // temalarda çalışıyordu ama beslemeler kapıyı çağırmıyordu. Tanımsız kapı
    // fail-closed davranır (missing_perms onu yakalar); kapısız izin ise
    // SESSİZCE ÖLÜ bir yetkidir — rol matrisinde görünür, hiçbir şey yapmaz.
    // Yayıncı o izni açar, hiçbir şey değişmez, kimse fark etmez.
    case 'unused_perms':
        $tanimli = array_keys(roles_permissions());
        $kullanilan = [];
        $tarama = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($tarama as $dosya) {
            $yol = str_replace('\\', '/', $dosya->getPathname());
            if (substr($yol, -4) !== '.php') { continue; }
            if (strpos($yol, '/tests/') !== false) { continue; }
            $src = (string)file_get_contents($yol);
            $desenler = [
                "/require_can\(\s*'([a-z_]+\.[a-z_]+)'/",
                "/'perm'\s*=>\s*'([a-z_]+\.[a-z_]+)'/",
                "/\bcan\(\s*\\\$[a-zA-Z_]+\s*,\s*'([a-z_]+\.[a-z_]+)'/",
                "/\bcan\(\s*[a-z_]+\([^)]*\)\s*,\s*'([a-z_]+\.[a-z_]+)'/",
            ];
            foreach ($desenler as $d) {
                if (preg_match_all($d, $src, $m)) {
                    $kullanilan = array_merge($kullanilan, $m[1]);
                }
            }
        }
        // admin.access ve public kapı değil, çatı kavramlarıdır.
        $muaf = ['admin.access'];
        $olu = array_values(array_diff($tanimli, array_unique($kullanilan), $muaf));
        sort($olu);
        echo implode(',', $olu);
        break;
    // Oturum parola özetine bağlı mı? Yanlış damgayla oturum ölmeli (B04).
    case 'pw_stamp_binds':
        if (!function_exists('session_pw_stamp') || !function_exists('session_bind_user')) {
            echo 'EKSIK'; break;
        }
        if (session_pw_stamp('birinci') === session_pw_stamp('ikinci')) { echo 'CAKISMA'; break; }
        $uid = (int)qv('SELECT id FROM users WHERE role = \'admin\' ORDER BY id ASC LIMIT 1', [], 0);
        if ($uid <= 0) { echo 'KULLANICI_YOK'; break; }
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $_SESSION['uid'] = $uid;
        $_SESSION['pw']  = session_pw_stamp('eski-parolanin-ozeti');
        echo current_user() === null ? 'TAMAM' : 'OTURUM_AYAKTA';
        break;

    // Hız sınırı denemeyi kararından ÖNCE kaydediyor mu? (yarış kapısı, B05)
    // max=2 ile üç deneme 1,1,0 vermeli. Eski sırada (say-sonra-yaz) üçüncü de
    // geçebiliyordu çünkü sayaç kendi satırını görmüyordu.
    case 'rate_limit_order':
        $kova = 'e2e-sinav-' . getmypid();
        $sonuc = '';
        for ($i = 0; $i < 3; $i++) { $sonuc .= rate_limit($kova, 2, 300) ? '1' : '0'; }
        rate_limit_clear($kova);
        echo $sonuc === '110' ? 'TAMAM' : ('BEKLENMEDIK:' . $sonuc);
        break;

    // srcset şema kapısı canlı mı? Desen derlenmezse kapı sessizce ölür (B05/paywall).
    case 'srcset_gate':
        require_once $root . '/inc/sanitize.php';
        // Sekme ile parçalanmış şema: kapı DESENİ bozukken bu değer
        // hiçbir denetime takılmadan geçiyordu (NUL baytlı sürüm DOM
        // ayrıştırıcısında zaten kırpıldığı için kapıyı sınamıyor).
        $kirli = '<img src="/a.png" srcset="java' . chr(9) . 'script:alert(1) 2x">';
        $temiz = sanitize_html($kirli, true);
        echo (stripos((string)$temiz, 'srcset') === false) ? 'TAMAM' : 'KAPI_OLU';
        break;

    case 'dup_api':
        // AYNI UÇ NOKTA İKİ KEZ KAYDEDİLMİŞ Mİ?
        //
        // api_register() aynı anahtarı SESSİZCE üzerine yazar ve api.php
        // inc/api/*.php dosyalarını ALFABETİK yükler. Yani iki dosya aynı ucu
        // kaydettiğinde hangisinin geçerli olduğu yalnız DOSYA ADINA bağlıdır.
        //
        // Bu 1.2'de gerçekten oldu: eski `newsletter.export` (izin
        // `settings.manage`, abone IP'sini de veren, denetim kaydı YAZMAYAN
        // sürüm) `kunye_api.php` içinde duruyordu; yeni ve KVKK kapılı sürümün
        // kazanması yalnızca "kunye_api" < "newsletter_api" sıralamasındandı.
        // Bir dosya yeniden adlandırılsa kapı sessizce geri alınırdı.
        //
        // Kayıtları ÇALIŞTIRMADAN, metinden okuruz: amaç çakışmayı yüklemeden
        // önce görmek.
        $gorulen = [];
        $cift = [];
        foreach ((array)glob($root . '/inc/api/*.php') as $dosya) {
            $metin = (string)file_get_contents($dosya);
            if (preg_match_all('/api_register\(\s*.([a-z0-9_]+\.[a-z0-9_]+)./i', $metin, $m)) {
                foreach ($m[1] as $uc) {
                    if (isset($gorulen[$uc])) {
                        $cift[] = $uc . ' (' . basename($gorulen[$uc]) . ' + ' . basename($dosya) . ')';
                    } else {
                        $gorulen[$uc] = $dosya;
                    }
                }
            }
        }
        echo implode("
", array_unique($cift));
        break;

    case 'backup_secret':
        // SIRLAR YEDEKTE DÜZ METİN DURUYOR MU? (denetim turu 4, YÜKSEK-1)
        //
        // 1.2 ile gelen zamanlı uzak yedek veritabanını üçüncü taraf bir FTP
        // sunucusuna gönderiyor. Denetçi gerçek bir yedekten ödeme sağlayıcı
        // tuzunu okudu ve onunla GEÇERLİ İMZALI sahte bir ödeme bildirimi
        // üretip bekleyen bir ödemeyi "ödendi" yaptırdı.
        //
        // Kazancın sınırı: anahtar `config.php` içindeki `app_key`'dir ve
        // yedeğe GİRMEZ. Yani koruma "yalnız veritabanı sızdı" durumunda
        // gerçektir — 1.2 ile en olası senaryo tam olarak budur.
        require_once $root . '/inc/backup.php';
        $sir = 'SONDA-DUZ-METIN-SIRRI-' . bin2hex(random_bytes(4));
        setting_set('ai_api_key', $sir);

        $r = backup_create('sqlite');
        if (empty($r['ok']) || !is_file($r['path'])) { echo 'YEDEK_ALINAMADI'; break; }

        $ham = (string)file_get_contents($r['path']);
        @unlink($r['path']);

        if (strpos($ham, $sir) !== false) { echo 'DUZ_METIN'; break; }
        // Şifreli olması yetmez: uygulama onu OKUYABİLMELİ de. Okunamayan bir
        // anahtar "korumalı" değil, kayıptır.
        settings_all(true);
        echo (setting('ai_api_key') === $sir) ? 'TAMAM' : 'COZULEMEDI';
        break;

    case 'mysql_ping':
        // MySQL sunucusuna ERKEN bağlanma denemesi (e2e --driver=mysql).
        //
        // NEDEN SONDA: bağlantı bilgisi ortam değişkenlerinden gelir ve kabuktan
        // `php -r` ile gömülü yol/parola göndermek Git Bash'te kırılgandır
        // (bkz. bu dosyanın başlığı). Ayrıca parola komut satırında geçseydi
        // `ps` çıktısında görünürdü.
        $h = getenv('MANSET_DB_HOST') ?: '127.0.0.1';
        $pt = (int)(getenv('MANSET_DB_PORT') ?: 3306);
        $d = getenv('MANSET_DB_NAME') ?: 'manset_test';
        $u = getenv('MANSET_DB_USER') ?: 'root';
        $w = getenv('MANSET_DB_PASS');
        if ($w === false) { $w = ''; }
        if (!extension_loaded('pdo_mysql')) { echo 'HATA: pdo_mysql eklentisi yok'; break; }
        try {
            new PDO('mysql:host=' . $h . ';port=' . $pt . ';dbname=' . $d . ';charset=utf8mb4', $u, $w,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            echo 'TAMAM';
        } catch (Throwable $e) {
            // Parola mesaja SIZMAMALI: sürücü bazen bağlantı dizesini yankılar.
            $m = str_replace([$w === '' ? chr(0) : $w], ['***'], $e->getMessage());
            echo 'HATA: ' . $m;
        }
        break;

    case 'paywall_leak':
        // KİLİTLİ GÖVDE HANGİ YOLLARDAN SIZIYOR?
        //
        // Bu sınıf İKİ KEZ tekrarladı: tur 2'de beslemelerden, tur 5'te arama
        // ucundan (`search.query` gövde alıntısı anonime dönüyordu ve arama terimi
        // kaydırılarak metin yürüyerek çıkarılabiliyordu). Her yeni "içerikten
        // parça göster" özelliği bu kapıyı yeniden açma riski taşır.
        //
        // Sonda ÇALIŞTIRIR: kilitli bir haber uydurur, gövdesine benzersiz bir
        // damga koyar ve metin üreten yolları anonim olarak çağırır.
        require_once $root . '/inc/view.php';
        if (function_exists('manset_load_feature_modules')) { manset_load_feature_modules(); }
        require_once $root . '/inc/roles.php';

        $damga = 'PAYWALLSIZINTI' . bin2hex(random_bytes(4));
        $pid = (int)qv('SELECT id FROM posts WHERE status = \'published\' ORDER BY id LIMIT 1', [], 0);
        if ($pid <= 0) { echo 'HABER_YOK'; break; }

        $yedek = q1('SELECT visibility, teaser, body FROM posts WHERE id = :i', [':i' => $pid]);
        q('UPDATE posts SET visibility = \'premium\', teaser = \'Onizleme.\', body = :b WHERE id = :i',
          [':b' => '<p>' . $damga . ' abonelere ozel.</p>', ':i' => $pid]);

        $sizinti = [];
        // 1) Arama süsleme yolu (1.3-06)
        if (function_exists('search_decorate_items')) {
            $rows = qa('SELECT ' . post_select_columns() . ' FROM posts p WHERE p.id = :i', [':i' => $pid]);
            $d = search_decorate_items($rows, [strtolower($damga)]);
            foreach ((array)$d as $r) {
                if (strpos((string)arr($r, 'search_excerpt_html', ''), $damga) !== false) { $sizinti[] = 'search'; }
            }
        }
        // 2) Gövde oluşturma yolu (tema ve besleme buradan geçer)
        if (function_exists('post_body_html')) {
            $p = q1('SELECT * FROM posts WHERE id = :i', [':i' => $pid]);
            if (strpos((string)post_body_html($p), $damga) !== false) { $sizinti[] = 'body'; }
        }

        // Haberi geri al
        q('UPDATE posts SET visibility = :v, teaser = :t, body = :b WHERE id = :i',
          [':v' => (string)arr($yedek, 'visibility', 'public'), ':t' => (string)arr($yedek, 'teaser', ''),
           ':b' => (string)arr($yedek, 'body', ''), ':i' => $pid]);

        echo implode(',', array_unique($sizinti));
        break;

    case 'guvenlik_basliklari':
        // GUVENLIK BASLIKLARI KAYNAKTA TANIMLI MI?
        //
        // Baslik degerleri PHP tarafinda uretilir (manset_security_headers).
        // Sonda HTTP atmaz — e2e zaten canli olcum yapiyor; buradaki is,
        // baslik uretiminin KODDAN silinmedigini/bozulmadigini yakalamak.
        $eksik = [];
        if (!function_exists('manset_security_headers')) { echo 'ISLEV_YOK'; break; }
        $csp = manset_csp_value();
        $q = chr(39);
        foreach (['default-src',
                  'object-src ' . $q . 'none' . $q,
                  'base-uri ' . $q . 'self' . $q,
                  'form-action ' . $q . 'self' . $q,
                  'frame-ancestors ' . $q . 'self' . $q] as $parca) {
            if (strpos($csp, $parca) === false) { $eksik[] = $parca; }
        }
        // Gomulu video saglayicilari frame-src'de olmali, yoksa gomme kirilir.
        if (function_exists('sanitize_allowed_iframe_hosts')) {
            foreach (sanitize_allowed_iframe_hosts() as $h) {
                if (strpos($csp, $h) === false) { $eksik[] = 'frame-src:' . $h; break; }
            }
        }
        echo implode(',', $eksik);
        break;

    case 'cors':
        // CORS ACIK MI?
        //
        // Proje HICBIR yerde Access-Control-Allow-* yazmaz; tarayici varsayilani
        // ayni-kokendir ve JSON uclari boylece kilitlidir. Bu bir KARAR, kaza
        // degil: birinin ileride "kolay olsun" diye yildiz koymasi, oturum
        // cerezli her ucu her siteye acardi.
        $bulunan = [];
        $tara = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($tara as $dosya) {
            $yol = str_replace('\\', '/', $dosya->getPathname());
            if (substr($yol, -4) !== '.php') { continue; }
            if (strpos($yol, '/tests/') !== false) { continue; }
            $src = (string)file_get_contents($yol);
            if (stripos($src, 'Access-Control-Allow') !== false) { $bulunan[] = basename($yol); }
        }
        echo implode(',', array_unique($bulunan));
        break;

    case 'bagimlilik':
        // DIS BAGIMLILIK SIZDI MI?
        //
        // CONTRACTS 0: Composer/npm YOK. "Paketleri denetle" maddesinin bu
        // projedeki karsiligi, denetlenecek paket OLMADIGINI dogrulamaktir —
        // bagimlilik sayisi sifirsa tedarik zinciri yuzeyi de sifirdir.
        $iz = [];
        foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json',
                  'yarn.lock', 'vendor', 'node_modules'] as $ad) {
            if (file_exists($root . '/' . $ad)) { $iz[] = $ad; }
        }
        echo implode(',', $iz);
        break;

    case 'demo_gate':
        // DEMO KARA LISTESI EKSIK MI?
        //
        // Demo kipi kara liste kullanir (beyaz liste degil): amac sistemi
        // GOSTERMEK, yani yeni bir ozellik varsayilan olarak denenebilir olmali.
        // Bunun bedeli, yeni eklenen TEHLIKELI bir ucun listeye yazilmayi
        // unutmasidir. Bu sonda tam o riski olcer.
        //
        // "Tehlikeli" olcutu: disariya cikan, ucret ureten, kod calistiran,
        // kisisel veri disa aktaran ya da kurulumu ele geciren uclar.
        require_once $root . '/inc/demo.php';
        if (!function_exists('demo_block_reason')) { echo 'MODUL_YOK'; break; }

        // Uc adlarini kaynaktan topla (calistirmadan).
        $uclar = [];
        foreach ((array)glob($root . '/inc/api/*.php') as $dosya) {
            $metin = (string)file_get_contents($dosya);
            if (preg_match_all('/api_register\(\s*.([a-z0-9_]+\.[a-z0-9_]+)./i', $metin, $m)) {
                foreach ($m[1] as $u) { $uclar[$u] = true; }
            }
        }

        // Tehlike belirtisi: uc adinda gecen sozcukler.
        $riskli = ['/^ai\./', '/^backup\./', '/^payment\./', '/\.export$/',
                   '/^users\./', '/^roles\./', '/^theme\.save_css$/', '/^ads\.save$/',
                   '/^share\./', '/fetch_now$/', '/^tools\.(migrate|flush)/'];

        // demo_mode() kapaliyken demo_block_reason() daima bos doner; sonda
        // KARARI kendisi vermeli, yoksa her zaman temiz gorunur.
        $liste = demo_blocked_actions();
        $desen = demo_blocked_patterns();
        $acik = [];
        foreach (array_keys($uclar) as $u) {
            $tehlikeli = false;
            foreach ($riskli as $r) { if (preg_match($r, $u)) { $tehlikeli = true; break; } }
            if (!$tehlikeli) { continue; }
            if (isset($liste[$u])) { continue; }
            // Bakilmis ve bilerek acik birakilmis salt-okunur uclar.
            if (function_exists('demo_reviewed_open') && isset(demo_reviewed_open()[$u])) { continue; }
            $kapali = false;
            foreach (array_keys($desen) as $d) { if (preg_match($d, $u)) { $kapali = true; break; } }
            if (!$kapali) { $acik[] = $u; }
        }
        // HAYALI GIRIS DENETIMI.
        // Kara listeye var OLMAYAN bir uc adi yazmak, engellemis gibi gorunup
        // hicbir sey engellememektir - guvenlikte en kotu durum. Bu dosyanin
        // ilk halinde ALTI hayali ad vardi ve olcum onlari boyle buldu.
        $hayali = [];
        foreach (array_keys($liste) as $u) {
            if (!isset($uclar[$u])) { $hayali[] = 'HAYALI:' . $u; }
        }
        sort($hayali);
        sort($acik);
        echo implode(',', array_merge($hayali, $acik));
        break;

    default:
        fwrite(STDERR, "Bilinmeyen soru: " . $soru . "\n");
        exit(1);
}
