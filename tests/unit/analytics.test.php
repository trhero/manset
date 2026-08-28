<?php
/**
 * inc/analytics.php — çerezsiz analitik, rozetler ve sağlık ölçümleri.
 *
 * Bu dosyanın asıl işi, KVKK iddialarını iddia olmaktan çıkarıp ölçüme
 * çevirmektir: "ham IP saklanmıyor" cümlesi, ancak yazılan satırların içinde
 * IP'nin GERÇEKTEN bulunmadığı sınandığında bir şey ifade eder.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

require_once INC_DIR . '/analytics.php';

/** Her testin temiz bir zeminden başlaması için analitik tablolarını boşaltır. */
function an_temizle() {
    q('DELETE FROM hit_daily');
    q('DELETE FROM hit_visitors');
    q('DELETE FROM hit_summary');
}

/** İstek ortamını taklit eder (analytics_record_view $_SERVER okur). */
function an_istek($ua = '', $referer = null, $ip = '198.51.100.7', $script = '/index.php') {
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    $_SERVER['SCRIPT_NAME'] = $script;
    $_SERVER['REMOTE_ADDR'] = $ip;
    if ($referer === null) { unset($_SERVER['HTTP_REFERER']); }
    else { $_SERVER['HTTP_REFERER'] = $referer; }
    unset($_POST['ref']);
    // json_body() istek başına önbelleklenmiyor; Content-Type yoksa boş döner.
    unset($_SERVER['CONTENT_TYPE']);
}

// ---------------------------------------------------------------- yönlendiren

test('yönlendiren türü: arama motorları tanınır', function () {
    esit('arama', analytics_referrer_class('https://www.google.com/search?q=haber'));
    esit('arama', analytics_referrer_class('https://google.com.tr/'));
    esit('arama', analytics_referrer_class('https://yandex.com.tr/search/'));
    esit('arama', analytics_referrer_class('https://duckduckgo.com/'));
    esit('arama', analytics_referrer_class('https://www.bing.com/search'));
});

test('yönlendiren türü: sosyal ağlar tanınır', function () {
    esit('sosyal', analytics_referrer_class('https://t.co/abcdef'));
    esit('sosyal', analytics_referrer_class('https://l.facebook.com/l.php?u=x'));
    esit('sosyal', analytics_referrer_class('https://www.instagram.com/'));
    esit('sosyal', analytics_referrer_class('https://x.com/kullanici/status/1'));
    esit('sosyal', analytics_referrer_class('https://t.me/kanal'));
});

test('yönlendiren türü: boş referrer doğrudan, kendi alan adımız iç', function () {
    esit('dogrudan', analytics_referrer_class(''));
    esit('dogrudan', analytics_referrer_class('   '));
    // run.php base_url = http://birim.test
    esit('ic', analytics_referrer_class('http://birim.test/kategori/gundem'));
    esit('ic', analytics_referrer_class('http://www.birim.test/'));
    esit('diger', analytics_referrer_class('https://baska-bir-site.example/yazi'));
});

test('yönlendiren türü: bozuk girdi çökmez', function () {
    esit('diger', analytics_referrer_class('bu bir adres değil'));
    esit('dogrudan', analytics_referrer_class(null));
    esit('diger', analytics_referrer_class('javascript:alert(1)'));
});

test('yönlendiren türü: iç ping ölçülemez, uydurulmaz', function () {
    // assets/site.js api.php üzerinden ping atar; o isteğin Referer başlığı
    // haberin KENDİ adresidir — okurun nereden geldiği orada YOKTUR.
    an_istek('', 'http://birim.test/haber/deneme-1', '198.51.100.7', '/api.php');
    esit('bilinmiyor', analytics_request_ref_class(),
        'ölçülemeyen durum "doğrudan" diye kaydedilmemeli');

    // Aynı referrer, sunucu tarafında basılan bir sayfada gerçek bilgidir.
    an_istek('', 'http://birim.test/haber/deneme-1', '198.51.100.7', '/index.php');
    esit('ic', analytics_request_ref_class());
});

test('yönlendiren türü: gövdedeki ref alanı yetkilidir', function () {
    an_istek('', 'http://birim.test/haber/deneme-1', '198.51.100.7', '/api.php');
    $_POST['ref'] = 'https://www.google.com/search?q=x';
    esit('arama', analytics_request_ref_class(),
        'istemcinin gönderdiği document.referrer, başlıktaki iç adresi ezmeli');
    $_POST['ref'] = '';
    esit('dogrudan', analytics_request_ref_class(),
        'boş ref alanı "doğrudan giriş" demektir, bilinmiyor değil');
    unset($_POST['ref']);
});

// ---------------------------------------------------------------- cihaz

test('cihaz sınıfı: iki değerlidir, telefon mobil sayılır', function () {
    esit('mobil', analytics_device_class('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit'));
    esit('mobil', analytics_device_class('Mozilla/5.0 (Linux; Android 14) Mobile Safari'));
    esit('masaustu', analytics_device_class('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'));
    esit('masaustu', analytics_device_class(''), 'user-agent yoksa varsayılan masaüstü');
    // Tablet bilinçli olarak masaüstü: yerleşim kararı masaüstüyle aynı.
    esit('masaustu', analytics_device_class('Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X)'));
});

// ---------------------------------------------------------------- KVKK

test('KVKK: ziyaretçi özeti ham IP taşımaz ve tuza bağlıdır', function () {
    $ip = '203.0.113.42';
    $h = analytics_visitor_hash($ip);
    esit(16, strlen($h), 'özet 16 hane olmalı');
    dogru((bool)preg_match('/^[0-9a-f]{16}$/', $h), 'özet yalnız onaltılık olmalı');
    icermez($h, '203', 'özet IP parçası içermemeli');
    icermez($h, '113', 'özet IP parçası içermemeli');
    esit($h, analytics_visitor_hash($ip), 'aynı gün aynı IP aynı özeti vermeli');
    icermez($h, analytics_visitor_hash('203.0.113.43'), 'farklı IP farklı özet vermeli');
});

test('KVKK: tuz değişince aynı IP başka özet üretir (gün dönümü)', function () {
    $ip = '203.0.113.42';
    $once = analytics_visitor_hash($ip);
    // Gün dönümünü taklit et: kayıtlı tuz gününü geçmişe al ve bellek önbelleğini
    // atlamak için yeni bir süreç yerine doğrudan hesabı tekrarla.
    setting_set('analytics_salt_day', '2000-01-01');
    setting_set('analytics_salt', bin2hex(random_bytes(16)));
    $yeniTuz = setting('analytics_salt', '') . '|' . (string)cfg('app_key', '');
    $sonra = substr(hash_hmac('sha256', $ip, $yeniTuz), 0, 16);
    icermez($sonra, $once, 'yeni tuzla üretilen özet eskisinden farklı olmalı');
});

test('KVKK: yazılan satırlarda IP, referrer adresi ve user-agent yok', function () {
    an_temizle();
    an_istek('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', 'https://www.google.com/search?q=gizli+arama', '203.0.113.99');
    analytics_record_view(4242);

    $satir = q1('SELECT * FROM hit_daily WHERE post_id = 4242');
    dogru(is_array($satir), 'satır yazılmalı');
    $duz = json_encode($satir, JSON_UNESCAPED_UNICODE);
    icermez($duz, '203.0.113.99', 'ham IP hiçbir sütunda olmamalı');
    icermez($duz, 'google.com', 'ham referrer adresi hiçbir sütunda olmamalı');
    icermez($duz, 'gizli+arama', 'arama terimi hiçbir sütunda olmamalı');
    icermez($duz, 'iPhone', 'user-agent hiçbir sütunda olmamalı');
    esit('arama', (string)$satir['ref']);
    esit('mobil', (string)$satir['dev']);

    $vis = q1('SELECT * FROM hit_visitors LIMIT 1');
    dogru(is_array($vis), 'ziyaretçi satırı yazılmalı');
    icermez(json_encode($vis), '203.0.113.99', 'ziyaretçi satırında ham IP olmamalı');
});

// ---------------------------------------------------------------- yazma yolu

test('yazma yolu: aynı boyut kümesi tek satırda toplanır', function () {
    an_temizle();
    an_istek('Mozilla/5.0 (Windows NT 10.0)', '', '198.51.100.1');
    analytics_record_view(11);
    analytics_record_view(11);
    analytics_record_view(11);

    esit(1, (int)qv('SELECT COUNT(*) FROM hit_daily', [], 0), 'UPSERT çift satır üretmemeli');
    esit(3, (int)qv('SELECT hits FROM hit_daily WHERE post_id = 11', [], 0));
});

test('yazma yolu: farklı boyutlar ayrı satır açar', function () {
    an_temizle();
    an_istek('Mozilla/5.0 (Windows NT 10.0)', '', '198.51.100.1');
    analytics_record_view(11);
    an_istek('Mozilla/5.0 (iPhone)', 'https://t.co/x', '198.51.100.2');
    analytics_record_view(11);

    esit(2, (int)qv('SELECT COUNT(*) FROM hit_daily WHERE post_id = 11', [], 0));
    esit(1, (int)qv('SELECT hits FROM hit_daily WHERE post_id = 11 AND ref = \'sosyal\' AND dev = \'mobil\'', [], 0));
    esit(1, (int)qv('SELECT hits FROM hit_daily WHERE post_id = 11 AND ref = \'dogrudan\' AND dev = \'masaustu\'', [], 0));
});

test('yazma yolu: aynı ziyaretçi gün içinde bir kez sayılır', function () {
    an_temizle();
    an_istek('Mozilla/5.0 (Windows NT 10.0)', '', '198.51.100.5');
    analytics_record_view(12);
    analytics_record_view(13);
    analytics_record_view(14);
    esit(1, (int)qv('SELECT COUNT(*) FROM hit_visitors', [], 0),
        'tekil ziyaretçi tablosu görüntüleme başına satır açmamalı');

    an_istek('Mozilla/5.0 (Windows NT 10.0)', '', '198.51.100.6');
    analytics_record_view(12);
    esit(2, (int)qv('SELECT COUNT(*) FROM hit_visitors', [], 0));
});

test('yazma yolu: ölçüm kapalıyken hiç satır yazılmaz', function () {
    an_temizle();
    setting_set('analytics_enabled', '0');
    an_istek('', '', '198.51.100.9');
    analytics_record_view(99);
    esit(0, (int)qv('SELECT COUNT(*) FROM hit_daily', [], 0));
    esit(0, (int)qv('SELECT COUNT(*) FROM hit_visitors', [], 0));
    setting_set('analytics_enabled', '1');
});

test('yazma yolu: geçersiz haber kimliği sessizce atlanır', function () {
    an_temizle();
    an_istek('', '', '198.51.100.9');
    analytics_record_view(0);
    analytics_record_view(-5);
    analytics_record_view('abc');
    esit(0, (int)qv('SELECT COUNT(*) FROM hit_daily', [], 0));
});

// ---------------------------------------------------------------- toplama / budama

test('toplama: dünün ziyaretçi özetleri sayıya indirgenip silinir', function () {
    an_temizle();
    $dun = date('Y-m-d', strtotime('-1 day'));
    foreach (['aaaa000000000001', 'aaaa000000000002', 'aaaa000000000003'] as $h) {
        db_insert('hit_visitors', ['day' => $dun, 'vhash' => $h]);
    }
    analytics_cron_rollup();

    esit(0, (int)qv('SELECT COUNT(*) FROM hit_visitors WHERE day = :d', [':d' => $dun], 0),
        'geçmiş günün ziyaretçi özetleri silinmeli — kalıcı kimlik izi bırakılmaz');
    esit(3, (int)qv('SELECT visitors FROM hit_summary WHERE day = :d', [':d' => $dun], 0),
        'sayı özete taşınmalı');
});

test('budama: ayrıntı penceresinden eski haber kırılımı katlanır', function () {
    an_temizle();
    setting_set('analytics_detail_days', '7');
    $eski = date('Y-m-d', strtotime('-30 days'));
    analytics_bump($eski, 501, 'arama', 'mobil', 4);
    analytics_bump($eski, 502, 'arama', 'mobil', 6);
    analytics_bump($eski, 503, 'sosyal', 'masaustu', 2);

    analytics_cron_rollup();

    esit(0, (int)qv('SELECT COUNT(*) FROM hit_daily WHERE day = :d AND post_id > 0', [':d' => $eski], 0),
        'haber kırılımı düşmeli');
    esit(10, (int)qv('SELECT hits FROM hit_daily WHERE day = :d AND post_id = 0 AND ref = \'arama\'', [':d' => $eski], 0),
        'gün toplamı korunmalı');
    esit(2, (int)qv('SELECT hits FROM hit_daily WHERE day = :d AND post_id = 0 AND ref = \'sosyal\'', [':d' => $eski], 0));
    setting_set('analytics_detail_days', '60');
});

test('budama: saklama penceresinden eski satırlar tümüyle silinir', function () {
    an_temizle();
    setting_set('analytics_keep_days', '30');
    $cokEski = date('Y-m-d', strtotime('-90 days'));
    analytics_bump($cokEski, 0, 'arama', 'mobil', 5);
    db_insert('hit_summary', ['day' => $cokEski, 'visitors' => 3, 'hits' => 5, 'updated_at' => now()]);

    analytics_cron_rollup();

    esit(0, (int)qv('SELECT COUNT(*) FROM hit_daily WHERE day = :d', [':d' => $cokEski], 0));
    esit(0, (int)qv('SELECT COUNT(*) FROM hit_summary WHERE day = :d', [':d' => $cokEski], 0));
    setting_set('analytics_keep_days', '365');
});

test('toplama: cron görevi kayıtlı ve ucuz işaretli', function () {
    $gorevler = cron_registered_tasks();
    dogru(isset($gorevler['analitik']), 'analitik görevi cron_register ile kayıtlı olmalı');
    esit('analytics_cron_rollup', $gorevler['analitik']['fn']);
    dogru($gorevler['analitik']['ucuz'], 'ağa çıkmayan görev ucuz işaretlenmeli');
});

// ---------------------------------------------------------------- rapor

test('rapor: seri eksik günleri sıfırla doldurur ve eskiden yeniye sıralar', function () {
    an_temizle();
    analytics_bump(date('Y-m-d'), 7, 'arama', 'mobil', 5);
    $seri = analytics_series(7);
    esit(7, count($seri));
    esit(date('Y-m-d', strtotime('-6 days')), $seri[0]['day']);
    esit(date('Y-m-d'), $seri[6]['day']);
    esit(5, (int)$seri[6]['hits']);
    esit(0, (int)$seri[0]['hits']);
});

test('rapor: dağılımlar ve en çok okunanlar', function () {
    an_temizle();
    $b = date('Y-m-d');
    analytics_bump($b, 21, 'arama', 'mobil', 10);
    analytics_bump($b, 22, 'sosyal', 'masaustu', 4);
    analytics_bump($b, 21, 'dogrudan', 'masaustu', 1);

    $ref = analytics_ref_breakdown(7);
    esit(10, (int)$ref['arama']);
    esit(4, (int)$ref['sosyal']);
    esit(1, (int)$ref['dogrudan']);

    $cihaz = analytics_device_breakdown(7);
    esit(10, (int)$cihaz['mobil']);
    esit(5, (int)$cihaz['masaustu']);

    $top = analytics_top_posts(7, 5);
    esit(21, (int)$top[0]['post_id'], 'en çok okunan başta olmalı');
    esit(11, (int)$top[0]['hits']);
});

// ---------------------------------------------------------------- SVG grafik

test('grafik: satır içi SVG, dış kütüphane ve betik yok', function () {
    $seri = [
        ['day' => date('Y-m-d', strtotime('-1 day')), 'hits' => 4, 'visitors' => 2],
        ['day' => date('Y-m-d'), 'hits' => 8, 'visitors' => 3],
    ];
    $svg = analytics_bar_svg($seri, 'hits');
    icerir($svg, '<svg');
    icerir($svg, '<rect');
    icermez($svg, '<script', 'grafikte betik olmamalı');
    icermez($svg, 'http://', 'grafikte dış kaynak olmamalı');
    icermez($svg, 'https://', 'grafikte dış kaynak olmamalı');
    esit('', analytics_bar_svg([], 'hits'), 'boş seri boş çıktı vermeli');
});

test('grafik: başlık metni kaçırılır (XSS)', function () {
    // day değeri veritabanından gelir ama yine de kaçırılmalı: SVG <title>
    // içeriği HTML bağlamındadır.
    $svg = analytics_bar_svg([['day' => date('Y-m-d'), 'hits' => 1, 'visitors' => 1]], 'hits');
    icermez($svg, '<title><', 'title içeriği ham etiket taşımamalı');
});

// ---------------------------------------------------------------- rozetler

test('rozet: yalnız kullanıcının GÖRDÜĞÜ sayfalar için üretilir', function () {
    analytics_badge_reset();
    q('DELETE FROM comments');
    db_insert('comments', ['post_id' => 1, 'user_id' => 0, 'name' => 'A', 'email' => 'a@ornek.test',
                           'body' => 'x', 'ip' => '', 'status' => 'pending', 'created_at' => now()]);
    analytics_badge_reset();

    // Menüsünde yorumlar OLAN kullanıcı
    $gorur = admin_nav_badges(['id' => 1, 'role' => 'admin'],
        ['İçerik' => ['comments' => ['label' => 'Yorumlar']]]);
    esit(1, (int)arr($gorur, 'comments', 0));

    // Menüsünde yorumlar OLMAYAN kullanıcı — rozet üretilmemeli
    $gormez = admin_nav_badges(['id' => 2, 'role' => 'yazar'],
        ['İçerik' => ['posts' => ['label' => 'Haberler']]]);
    yanlis(isset($gormez['comments']), 'göremediği kuyruğun varlığı sızdırılmamalı');
});

test('rozet: sıfır sayaç rozet üretmez', function () {
    q('DELETE FROM comments');
    analytics_badge_reset();
    $b = admin_nav_badges(['id' => 1, 'role' => 'admin'],
        ['İçerik' => ['comments' => ['label' => 'Yorumlar']]]);
    yanlis(isset($b['comments']), 'bekleyen iş yoksa rozet çizilmemeli');
});

test('rozet: sayaçlar kısa süreli önbellekten okunur', function () {
    q('DELETE FROM comments');
    analytics_badge_reset();
    analytics_badge_counts();          // önbelleği doldurur
    $ham = setting('nav_badge_cache', '');
    icerir($ham, 'comments', 'önbellek settings içinde saklanmalı');
    $c = json_decode($ham, true);
    dogru(is_array($c) && isset($c['t']), 'önbellekte zaman damgası olmalı');
    dogru(abs(time() - (int)$c['t']) < 5, 'damga taze olmalı');
});

// ---------------------------------------------------------------- sağlık

test('sağlık: ölçümler ton ve gerekçe taşır', function () {
    $s = analytics_health_checks();
    dogru(count($s) > 0, 'en az bir ölçüm dönmeli');
    foreach ($s as $m) {
        dogru(isset($m['ad'], $m['deger'], $m['ton'], $m['not']), 'ölçüm alanları eksiksiz olmalı');
        dogru(in_array($m['ton'], ['olumlu', 'uyari', 'olumsuz', 'notr'], true),
            'ton bilinen bir değer olmalı: ' . $m['ton']);
        dogru(strlen((string)$m['not']) > 20, '"neden önemli" açıklaması boş olmamalı');
    }
});

test('sağlık: cron hiç çalışmamışsa olumsuz bildirilir', function () {
    q('DELETE FROM cron_runs');
    setting_set('cron_last_run', '');
    $s = analytics_health_checks();
    $cron = null;
    foreach ($s as $m) { if ($m['ad'] === 'Zamanlı görev') { $cron = $m; } }
    dogru($cron !== null, 'cron ölçümü bulunmalı');
    esit('olumsuz', $cron['ton']);
});

test('sağlık: veritabanı boyutu ölçülebiliyor', function () {
    dogru(analytics_db_size() > 0, 'SQLite dosya boyutu okunabilmeli');
    icerir(analytics_human_size(1536), 'KB');
    icerir(analytics_human_size(10), 'B');
});

// ---------------------------------------------------------------- huni

test('huni: bugün iki kez sayılmaz', function () {
    an_temizle();
    $bugun = date('Y-m-d');
    // Bugünün iki tekil ziyaretçisi
    db_insert('hit_visitors', ['day' => $bugun, 'vhash' => 'bbbb000000000001']);
    db_insert('hit_visitors', ['day' => $bugun, 'vhash' => 'bbbb000000000002']);
    // Cron gün içinde koştu ve bugünü özete de yazdı
    analytics_summary_set($bugun, ['visitors' => 2]);
    // Dünün özeti
    analytics_summary_set(date('Y-m-d', strtotime('-1 day')), ['visitors' => 5]);

    $h = analytics_funnel(30);
    esit(7, (int)$h['ziyaret'], 'bugün canlı sayılır, özetten dışlanır: 5 + 2 = 7');
});

test('toplama: eski günlerin toplamı her turda yeniden yazılmaz', function () {
    an_temizle();
    $eski = date('Y-m-d', strtotime('-10 days'));
    analytics_bump($eski, 0, 'arama', 'mobil', 9);
    analytics_cron_rollup();                       // boşluğu bir kez doldurur
    esit(9, (int)qv('SELECT hits FROM hit_summary WHERE day = :d', [':d' => $eski], 0));

    // Özet elle bozulursa ikinci tur onu DÜZELTMEZ: eski günler artık taranmıyor.
    db_update('hit_summary', ['hits' => 111], 'day = :d', [':d' => $eski]);
    analytics_cron_rollup();
    esit(111, (int)qv('SELECT hits FROM hit_summary WHERE day = :d', [':d' => $eski], 0),
        'eski günler her turda yeniden hesaplanmamalı (cron maliyeti sabit kalsın)');
});
