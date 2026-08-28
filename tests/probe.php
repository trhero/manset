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

    default:
        fwrite(STDERR, "Bilinmeyen soru: " . $soru . "\n");
        exit(1);
}
