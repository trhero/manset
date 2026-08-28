<?php
/**
 * Ajan-F — bülten çift onayı (1.2-11) ve zamanlı/uzak yedek (1.2-09).
 *
 * Buradaki testler veritabanına DOKUNAN ama ağa çıkmayan yolları sınar.
 * Gerçek FTP aktarımı, cron turu ve web'den indirme denemesi burada değil,
 * çalışan bir kurulumda ölçülmüştür (bkz. gelistirme/1.2-ajan-f.md).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

require_once INC_DIR . '/sanitize.php';
require_once INC_DIR . '/newsletter.php';
require_once INC_DIR . '/backup.php';

// İşlemsel e-posta kapalı: test mail() çağırmasın (SMTP beklemesi olmasın).
setting_set('member_mail_enabled', '0');

/** Testler arası temiz zemin. */
function bf_temizle() {
    q('DELETE FROM newsletter_subscribers');
}

/** Kaydolur ve ham onay token'ını döndürür (yalnız test içinde erişilebilir). */
function bf_kaydol($email, $kategoriler = []) {
    $r = newsletter_subscribe(['email' => $email, 'categories' => $kategoriler, 'source' => 'birim']);
    dogru($r['ok'], 'kayıt başarılı olmalı');
    return (string)$r['token'];
}

// =========================================================== çift onay

// YEDEK TESTLERİ GEÇİCİ BİR DİZİNDE ÇALIŞIR.
// Budamayı ölçen testler dizindeki `yedek-*` dosyalarını siliyor; yönlendirme
// olmadan bu, proje kökündeki GERÇEK geliştirme yedeklerini de siliyordu.
$GLOBALS['manset_backup_dir_override'] = sys_get_temp_dir() . '/manset-yedek-test-' . getmypid();
@mkdir($GLOBALS['manset_backup_dir_override'], 0777, true);
register_shutdown_function(function () {
    $d = $GLOBALS['manset_backup_dir_override'];
    foreach ((array)glob($d . '/*') as $f) { @unlink($f); }
    @rmdir($d);
});

test('bülten: kayıt confirmed = 0 ile başlar ve ham token DB’de durmaz', function () {
    bf_temizle();
    $token = bf_kaydol('okur@ornek.test');
    dogru($token !== '', 'ham token üretilmeli');

    $row = q1('SELECT confirmed, confirm_hash, unsub_hash FROM newsletter_subscribers WHERE email = :e',
              [':e' => 'okur@ornek.test']);
    dogru(is_array($row), 'kayıt oluşmalı');
    esit(0, (int)$row['confirmed'], 'onaydan önce confirmed 0 olmalı');
    esit(hash('sha256', $token), (string)$row['confirm_hash'], 'DB’de yalnız SHA-256 özeti durmalı');
    icermez((string)$row['confirm_hash'], $token, 'ham token saklanmamalı');
    dogru((string)$row['unsub_hash'] !== '', 'çıkış token özeti de kayıt anında üretilmeli');
});

test('bülten: onay bağlantısı confirmed sütununu 1 yapar', function () {
    bf_temizle();
    $token = bf_kaydol('onayli@ornek.test');

    $r = newsletter_confirm($token);
    dogru($r['ok'], 'onay başarılı olmalı');
    esit('onayli@ornek.test', $r['email']);

    $row = q1('SELECT confirmed, confirmed_at, confirm_hash FROM newsletter_subscribers WHERE email = :e',
              [':e' => 'onayli@ornek.test']);
    esit(1, (int)$row['confirmed'], 'confirmed 1 olmalı — göç 003’ten beri olmayan davranış');
    dogru(trim((string)$row['confirmed_at']) !== '', 'onay anı damgalanmalı');
    esit('', (string)$row['confirm_hash'], 'token tek kullanımlık: özet silinmeli');
});

test('bülten: aynı onay bağlantısı ikinci kez çalışmaz', function () {
    bf_temizle();
    $token = bf_kaydol('tekrar@ornek.test');
    dogru(newsletter_confirm($token)['ok']);
    $iki = newsletter_confirm($token);
    yanlis($iki['ok'], 'ikinci kullanım reddedilmeli');
    icerir($iki['error'], 'geçersiz');
});

test('bülten: süresi dolmuş onay bağlantısı reddedilir', function () {
    bf_temizle();
    $token = bf_kaydol('suresi@ornek.test');
    q('UPDATE newsletter_subscribers SET confirm_expires = :d WHERE email = :e',
      [':d' => date('Y-m-d H:i:s', time() - 3600), ':e' => 'suresi@ornek.test']);
    $r = newsletter_confirm($token);
    yanlis($r['ok']);
    icerir($r['error'], 'süresi');
    esit(0, (int)qv('SELECT confirmed FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'suresi@ornek.test'], 0));
});

test('bülten: uydurma token hiçbir kaydı onaylamaz', function () {
    bf_temizle();
    bf_kaydol('guvenli@ornek.test');
    foreach (['', 'abc', str_repeat('f', 64), 'DROP TABLE'] as $sahte) {
        yanlis(newsletter_confirm($sahte)['ok'], 'sahte token: ' . $sahte);
    }
    esit(0, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE confirmed = 1', [], 0));
});

test('bülten: yeniden kaydolmak eski onay bağlantısını geçersizleştirir', function () {
    bf_temizle();
    $eski = bf_kaydol('yeniden@ornek.test');
    $yeni = bf_kaydol('yeniden@ornek.test');
    dogru($eski !== $yeni, 'yeni token üretilmeli');
    yanlis(newsletter_confirm($eski)['ok'], 'eski bağlantı ölmeli');
    dogru(newsletter_confirm($yeni)['ok'], 'yeni bağlantı çalışmalı');
    esit(1, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'yeniden@ornek.test'], 0), 'kopya kayıt açılmamalı');
});

test('bülten: onaylı adres için yeni onay postası üretilmez (varlık sızmaz)', function () {
    bf_temizle();
    $t = bf_kaydol('zaten@ornek.test');
    newsletter_confirm($t);
    $r = newsletter_subscribe(['email' => 'zaten@ornek.test']);
    dogru($r['ok']);
    esit('already', $r['state']);
    esit('', $r['token'], 'onaylı aboneye yeni token verilmez');
});

// =========================================================== çıkış

test('bülten: çıkış bağlantısı kaydı SİLER', function () {
    bf_temizle();
    $onay = bf_kaydol('cikan@ornek.test');
    newsletter_confirm($onay);

    // Ham çıkış token'ı yalnız e-postadadır; testte özetten geri gidilemez, bu
    // yüzden bilinen bir token'ın özetini yazıp bağlantıyı taklit ediyoruz.
    $ham = bin2hex(random_bytes(32));
    q('UPDATE newsletter_subscribers SET unsub_hash = :h WHERE email = :e',
      [':h' => hash('sha256', $ham), ':e' => 'cikan@ornek.test']);

    dogru(is_array(newsletter_unsub_peek($ham)), 'onay ekranı adresi bulmalı');
    esit(1, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers', [], 0), 'peek silmemeli');

    $r = newsletter_unsubscribe($ham);
    dogru($r['ok']);
    esit('cikan@ornek.test', $r['email']);
    esit(0, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'cikan@ornek.test'], 0), 'kayıt pasife değil, SİLİNMİŞ olmalı');
});

test('bülten: bir abonenin çıkış bağlantısı başkasını etkilemez', function () {
    bf_temizle();
    bf_kaydol('a@ornek.test');
    bf_kaydol('b@ornek.test');
    $ham = bin2hex(random_bytes(32));
    q('UPDATE newsletter_subscribers SET unsub_hash = :h WHERE email = :e',
      [':h' => hash('sha256', $ham), ':e' => 'a@ornek.test']);
    newsletter_unsubscribe($ham);
    esit(1, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'b@ornek.test'], 0), 'diğer abone durmalı');
});

// =========================================================== segment

test('bülten: kategori segmenti yalnız var olan kategorileri kabul eder', function () {
    bf_temizle();
    $kat = db_insert('categories', ['name' => 'Gündem', 'slug' => 'gundem-bf',
        'sort' => 1, 'color' => '', 'in_menu' => 1, 'active' => 1]);
    esit(',' . $kat . ',', newsletter_categories_clean([$kat]));
    esit('', newsletter_categories_clean([999999]), 'olmayan kategori atılmalı');
    esit('', newsletter_categories_clean('abc'));
    esit(',' . $kat . ',', newsletter_categories_clean([$kat, $kat, 999999]), 'yineleme tekilleşmeli');
    esit([$kat], newsletter_categories_list(',' . $kat . ','));

    bf_kaydol('segment@ornek.test', [$kat]);
    $res = newsletter_query(['kategori' => $kat]);
    esit(1, $res['total'], 'kategori süzgeci aboneyi bulmalı');
    $res2 = newsletter_query(['kategori' => $kat + 1000]);
    esit(0, $res2['total']);
    q('DELETE FROM categories WHERE id = :i', [':i' => $kat]);
});

// =========================================================== dışa aktarma

test('bülten: Mailchimp ve Sendy sütunları biçimlerine uyar', function () {
    esit(['Email Address', 'First Name', 'Last Name', 'Tags'], newsletter_export_header('mailchimp'));
    esit(['Name', 'Email'], newsletter_export_header('sendy'));
    esit(',', newsletter_export_sep('mailchimp'), 'Mailchimp virgül bekler');
    esit(',', newsletter_export_sep('sendy'));
    esit(';', newsletter_export_sep('ham'), 'ham liste Excel için noktalı virgül');

    $satir = ['email' => 'okur@ornek.test', 'confirmed' => 1, 'confirmed_at' => '2026-08-01 10:00:00',
              'categories' => '', 'source' => 'form', 'ip' => '', 'created_at' => '2026-08-01 09:00:00'];
    esit(['okur@ornek.test', '', '', ''], newsletter_export_row($satir, 'mailchimp'));
    esit(['okur', 'okur@ornek.test'], newsletter_export_row($satir, 'sendy'));
    esit(7, count(newsletter_export_row($satir, 'ham')));
});

test('bülten: dışa aktarma süzgeci onaylı/bekleyen ayrımını korur', function () {
    bf_temizle();
    $t = bf_kaydol('onay1@ornek.test');
    newsletter_confirm($t);
    bf_kaydol('bekle1@ornek.test');

    esit(1, count(newsletter_export_rows(['durum' => 'onayli'])));
    esit(1, count(newsletter_export_rows(['durum' => 'bekleyen'])));
    esit(2, count(newsletter_export_rows(['durum' => 'hepsi'])));
});

// =========================================================== bakım görevi

test('bülten: cron görevi eski onaylanmamış kayıtları siler, onaylıya dokunmaz', function () {
    bf_temizle();
    setting_set('newsletter_unconfirmed_days', '30');
    $t = bf_kaydol('kalici@ornek.test');
    newsletter_confirm($t);
    bf_kaydol('eski@ornek.test');
    q('UPDATE newsletter_subscribers SET created_at = :d WHERE email = :e',
      [':d' => date('Y-m-d H:i:s', time() - 40 * 86400), ':e' => 'eski@ornek.test']);
    bf_kaydol('yeni@ornek.test');

    newsletter_cron_tick();
    esit(0, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'eski@ornek.test'], 0), 'eski onaysız kayıt silinmeli');
    esit(1, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'kalici@ornek.test'], 0), 'onaylı abone korunmalı');
    esit(1, (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE email = :e',
                    [':e' => 'yeni@ornek.test'], 0), 'yeni onaysız kayıt korunmalı');
    bf_temizle();
});

// =========================================================== yedek: ad ve budama

test('yedek: dosya adı deseni .tar kabul eder, yol bileşenini reddeder', function () {
    dogru(backup_is_valid_name('yedek-20260101-000000-aabbccdd.sqlite'));
    dogru(backup_is_valid_name('yedek-20260101-000000-aabbccdd.sql'));
    dogru(backup_is_valid_name('yedek-20260101-000000-aabbccdd-uploads.tar'));
    yanlis(backup_is_valid_name('../config.php'));
    yanlis(backup_is_valid_name('yedek-x.sqlite/../../config.php'));
    yanlis(backup_is_valid_name('db/yedek-x.sqlite'));
    yanlis(backup_is_valid_name('config.php'));
    yanlis(backup_is_valid_name('yedek-x.php'), 'php uzantısı yedek değildir');
    yanlis(backup_is_valid_name("yedek-x.sqlite\0.png"), 'boş bayt reddedilmeli');
});

test('yedek: ad tahmin edilemez (8 bayt rastgele) ve tür son eki doğru', function () {
    $a = backup_new_name('sqlite');
    $b = backup_new_name('sqlite');
    dogru($a !== $b, 'iki ad aynı olmamalı');
    dogru((bool)preg_match('/-[0-9a-f]{16}\.sqlite$/', $a), '16 haneli hex son ek: ' . $a);
    icerir(backup_new_name('tar'), '-uploads.tar');
    icerir(backup_new_name('sql'), '.sql');
});

test('yedek: budama türleri AYRI sayar (uploads arşivi DB yedeklerine ezdirilmez)', function () {
    foreach ((array)glob(backup_dir() . '/yedek-*.*') as $f) { @unlink($f); }
    // 4 DB yedeği + 2 uploads arşivi, hepsi ayrı zaman damgasıyla
    $t = time() - 1000;
    foreach ([['sqlite', 4], ['tar', 2]] as $c) {
        for ($i = 0; $i < $c[1]; $i++) {
            $ad = backup_dir() . '/' . backup_new_name($c[0]);
            file_put_contents($ad, 'x');
            touch($ad, $t);
            $t += 10;
        }
    }
    esit(6, count(backup_list()));
    backup_prune(2);
    $kalan = ['db' => 0, 'uploads' => 0];
    foreach (backup_list() as $b) { $kalan[$b['kind']]++; }
    esit(2, $kalan['db'], 'DB yedeklerinden 2 kalmalı');
    esit(2, $kalan['uploads'], 'uploads arşivlerinden 2 kalmalı');
    foreach ((array)glob(backup_dir() . '/yedek-*.*') as $f) { @unlink($f); }
});

test('yedek: yaş metni ve tonu yedek yokken uyarır', function () {
    foreach ((array)glob(backup_dir() . '/yedek-*.*') as $f) { @unlink($f); }
    esit(-1, backup_age_seconds('db'));
    esit('hiç alınmadı', backup_age_text('db'));
    esit('olumsuz', backup_age_tone('db'));

    $ad = backup_dir() . '/' . backup_new_name('sqlite');
    file_put_contents($ad, 'x');
    touch($ad, time() - 120);
    icerir(backup_age_text('db'), 'dakika önce');
    esit('olumlu', backup_age_tone('db'));
    @unlink($ad);
});

// =========================================================== yedek: tar

test('yedek: tar başlığı 512 bayt ve sağlama toplamı doğru', function () {
    $h = backup_tar_header('uploads/deneme.jpg', 1234, 1700000000);
    dogru(is_string($h));
    esit(512, strlen($h));
    icerir(substr($h, 257, 5), 'ustar');
    esit('0', $h[156], 'düz dosya tipi');

    // Sağlama: 148-155 arası boşluk sayılarak yeniden hesaplanır (tar kuralı)
    $ham = substr($h, 0, 148) . '        ' . substr($h, 156);
    $toplam = 0;
    for ($i = 0; $i < 512; $i++) { $toplam += ord($ham[$i]); }
    esit($toplam, octdec(trim(substr($h, 148, 8))), 'sağlama toplamı tutmalı');
    esit(1234, octdec(trim(substr($h, 124, 12))), 'boyut sekizlik yazılmalı');
});

test('yedek: 100 karakterden uzun ad prefix alanına bölünür', function () {
    $uzun = 'uploads/' . str_repeat('klasor/', 15) . 'dosya.jpg';
    dogru(strlen($uzun) > 100);
    $h = backup_tar_header($uzun, 0, 0);
    dogru(is_string($h), 'bölünebilen ad kabul edilmeli');
    esit(512, strlen($h));

    $bolunemez = 'uploads/' . str_repeat('a', 120) . '.jpg';
    yanlis(backup_tar_header($bolunemez, 0, 0), 'bölünemeyen ad atlanmalı (false)');
});

// =========================================================== yedek: FTP / SSRF

test('yedek: FTP ana adı temizlenir', function () {
    esit('yedek.ornek.com', backup_ftp_host_clean('ftp://yedek.ornek.com/klasor'));
    esit('yedek.ornek.com', backup_ftp_host_clean('  YEDEK.ornek.com:21 '));
    esit('yedek.ornek.com', backup_ftp_host_clean('yedek.ornek.com/'));
    esit('', backup_ftp_host_clean(''));
});

test('yedek: SSRF kalkanı iç ağ FTP hedeflerini reddeder', function () {
    foreach (['127.0.0.1', 'localhost', '10.0.0.5', '192.168.1.10', '169.254.169.254',
              '172.16.0.1', '0.0.0.0', '[::1]', 'yok.gecersiz.ornek'] as $kotu) {
        yanlis(backup_ftp_target($kotu), 'reddedilmeli: ' . $kotu);
    }
});

test('yedek: uzak klasör adı yol geçişine izin vermez', function () {
    esit('yedek', backup_ftp_clean_dir('/yedek/'));
    esit('yedek/alt', backup_ftp_clean_dir('yedek//alt'));
    icermez(backup_ftp_clean_dir('../../etc'), '..');
    esit('', backup_ftp_clean_dir('   '));
});

test('yedek: ftp uzantısı yoksa özellik zarifçe kapanır ve nedenini söyler', function () {
    $st = backup_ftp_status();
    dogru(is_array($st) && array_key_exists('ok', $st) && array_key_exists('reason', $st));
    if (!function_exists('ftp_connect')) {
        yanlis($st['ok'], 'uzantı yokken uzak yedek kapalı olmalı');
        icerir($st['reason'], 'ftp uzantısı');
    } else {
        // Uzantı var: ayar kapalıyken de kapalı olmalı, sebebi söylenmeli
        setting_set('backup_remote', '0');
        $st2 = backup_ftp_status();
        yanlis($st2['ok']);
        icerir($st2['reason'], 'kapalı');
    }
});

test('yedek: uzak gönderim, hedef doğrulanamazsa dosyaya dokunmaz', function () {
    setting_set('backup_remote', '1');
    setting_set('backup_ftp_host', '127.0.0.1');
    setting_set('backup_ftp_user', 'kullanici');
    setting_set('backup_ftp_pass', 'gizli-parola');
    $r = backup_remote_send('yedek-20260101-000000-aabbccdd.sqlite');
    yanlis($r['ok']);
    icermez($r['error'], 'gizli-parola', 'hata metni parola sızdırmamalı');
    setting_set('backup_remote', '0');
    setting_set('backup_ftp_host', '');
    setting_set('backup_ftp_user', '');
    setting_set('backup_ftp_pass', '');
});

// =========================================================== yedek: zamanlama

test('yedek: sıklık kapısı (backup_due) ayara uyar', function () {
    setting_set('bf_test_last', '');
    dogru(backup_due('bf_test_last', 1), 'hiç çalışmadıysa sırası gelmiştir');
    setting_set('bf_test_last', now());
    yanlis(backup_due('bf_test_last', 1), 'bugün çalıştıysa sırası değil');
    setting_set('bf_test_last', date('Y-m-d H:i:s', time() - 2 * 86400));
    dogru(backup_due('bf_test_last', 1), '2 gün geçtiyse sırası gelmiştir');
    yanlis(backup_due('bf_test_last', 7), 'haftalık görev için henüz erken');
});

test('yedek: kapalıyken cron görevi hiçbir şey yapmaz', function () {
    setting_set('backup_auto_db', '0');
    $r = backup_cron_tick();
    esit(0, (int)$r['items']);
    esit('kapalı', $r['note']);
    setting_set('backup_auto_uploads', '0');
    $r2 = backup_uploads_cron_tick();
    esit('kapalı', $r2['note']);
    setting_set('backup_auto_db', '1');
    setting_set('backup_auto_uploads', '1');
});

test('yedek: cron görevleri kayıt defterine bildirilmiş', function () {
    $gorevler = cron_registered_tasks();
    dogru(isset($gorevler['yedek']), 'yedek görevi kayıtlı olmalı');
    esit('backup_cron_tick', $gorevler['yedek']['fn']);
    dogru(isset($gorevler['yedek-uploads']), 'uploads arşivi görevi kayıtlı olmalı');
    yanlis($gorevler['yedek-uploads']['ucuz'], 'disk taraması ucuz sayılmamalı');
    dogru(isset($gorevler['bulten']), 'bülten bakım görevi kayıtlı olmalı');
    dogru($gorevler['bulten']['ucuz'], 'bülten bakımı ucuz (ağa çıkmaz)');
});
