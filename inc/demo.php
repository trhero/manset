<?php
/**
 * Manşet — DEMO KİPİ (1.4)
 *
 * ---------------------------------------------------------------------------
 * NE İŞE YARAR
 * ---------------------------------------------------------------------------
 * Herkese açık bir tanıtım kurulumunda ziyaretçiye yönetici hesabı verilir ki
 * sistemi gerçekten deneyebilsin. Ama o hesap, kısıtlanmazsa şunları yapabilir:
 * sunucudan e-posta gönderir (spam), yayıncının parasıyla yapay zekâ çağrısı
 * yapar, `ads.html` ile siteye ham JavaScript enjekte eder, yedeği indirip
 * bütün veriyi alır, uzak sunucuya FTP bağlantısı açar, ödeme anahtarlarını
 * okur ve site adresini değiştirip kurulumu erişilemez yapar.
 *
 * Yani demo kipi bir "görünüm ayarı" değil, bir GÜVENLİK SINIRIDIR.
 *
 * ---------------------------------------------------------------------------
 * TASARIM KARARI: KAPI SUNUCUDA VE TEK YERDE
 * ---------------------------------------------------------------------------
 * Düğmeleri gizlemek kısıt değildir — uç noktalar `api.php` üzerinden doğrudan
 * çağrılabilir. Bu yüzden engelleme, yetki kapısının hemen ardında, TEK bir
 * noktada yapılır (`api.php`) ve panel formları için ikinci bir kapı vardır
 * (`admin/index.php`). Arayüzdeki gizleme yalnız nezakettir.
 *
 * BEYAZ LİSTE DEĞİL KARA LİSTE — VE NEDENİ:
 * Demonun amacı sistemi göstermektir; yeni bir özellik eklendiğinde varsayılan
 * "denenebilir" olmalıdır, yoksa demo her sürümde eskir. Buna karşılık kara
 * liste, yeni bir TEHLİKELİ uç eklendiğinde onu kaçırma riski taşır. Bu riski
 * iki şey azaltır: (1) liste ADLA değil DESENLE de çalışır (`*.export`,
 * `backup.*` gibi), (2) `tests/probe.php demo_gate` sondası, kara listede
 * olmayan ama tehlikeli görünen uçları her koşuda bildirir.
 *
 * ---------------------------------------------------------------------------
 * AÇMAK
 * ---------------------------------------------------------------------------
 * `config.php` içine:  'demo_mode' => true,
 * Veritabanı ayarı DEĞİL — panelden açılıp kapanabilseydi, demo ziyaretçisi
 * ilk iş onu kapatırdı.
 */

/** Demo kipi açık mı? */
function demo_mode() {
    static $d = null;
    if ($d !== null) { return $d; }
    return $d = (bool)cfg('demo_mode', false);
}

/**
 * Demoda ENGELLİ API uçları.
 *
 * Her satırın yanında NEDEN engellendiği yazılıdır; gerekçesiz bir engel,
 * sonradan "bu niye kapalı?" diye açılır.
 */
function demo_blocked_actions() {
    // NOT: Asıl kapı `demo_denied_permissions()`. Buradaki liste, İZNE bağlı
    // olmayan ama yine de dışarı çıkan / kaynak tüketen uçlar içindir.
    // Her ad `inc/api/*.php` içinde GERÇEKTEN kayıtlıdır — `probe demo_gate`
    // sondası hayali ad kalmadığını her koşuda doğrular.
    return [
        // --- Dışarıya çıkan / ücret üreten ----------------------------------
        'rss.fetch_now'  => 'Dış sunuculara istek atar; kötüye kullanımda demo bir tarayıcı gibi kullanılır.',
        'rss.test'       => 'Dış sunucuya istek atar.',
        'rss.save'       => 'Serbest adres eklemek, demoyu iç ağ taraması için araç yapardı (SSRF).',
        'rss.katalog'    => 'Kaynak listesi demoda sabittir.',
        'seo.ping_run'   => 'Arama motorlarına gerçek bildirim gönderir.',
        'share.text'     => 'Yapay zekâ çağrısı ücret üretir.',
        'share.image'    => 'Sunucu kaynağı tüketir.',

        // --- Kod çalıştırma / dosya yazma yüzeyi ----------------------------
        'ads.approve'    => 'Ham HTML reklamı onaylamak onu yayına çıkarır — keyfi betik demektir.',
        'ads.txt_save'   => 'ads.txt içeriği demoda sabittir.',
        'ads.txt_static' => 'Site köküne dosya yazar.',

        // --- Kurulumu bozan --------------------------------------------------
        'seo.redirect_save' => 'Yönlendirme kuralı, demonun ana sayfasını başka yere taşıyabilir.',
        'theme.reset'       => 'Tema ayarlarını sıfırlar; demo görünümü bozulur.',
        'theme.reset_blocks'=> 'Anasayfa blok dizilimini sıfırlar.',
    ];
}

/**
 * Demoda KAPATILAN izinler — asıl kapı budur.
 *
 * NEDEN İZİN, NEDEN UÇ ADI DEĞİL:
 * Uç adlarını elle saymak kırılgandır. Bu dosyanın ilk hâlinde kara listeye
 * yazılan adların ALTISI gerçekte yoktu (`ai.run`, `users.save`,
 * `theme.save_css`, `payment.save`, `tools.flush_logs`, `members.export`);
 * ölçüm bunu gösterdi. O girişler hiçbir şeyi engellemiyor, yalnız
 * engelliyormuş gibi görünüyordu — güvenlikte en kötü durum budur.
 *
 * İzin katmanı bu hatayı yapılamaz kılar: `can()` her API ucundan, her panel
 * sayfasından ve her formdan geçer. Bir izin kapandığında ona bağlı HER yol
 * kapanır; ileride eklenecek uçlar dâhil.
 *
 * `settings.manage` BİLEREK açık: Ayarlar ekranı yayıncı adayının göreceği en
 * anlatıcı ekranlardan biridir ve gezilebilmelidir. Oradaki tehlikeli
 * anahtarlar `demo_setting_locked()` ile ayrıca yazmaya kapalıdır.
 */
function demo_denied_permissions() {
    return [
        'theme.css'       => 'Özel CSS sayfaya keyfi içerik enjekte eder.',
        'ads.html'        => 'Ham HTML/JS reklam kodu siteye keyfi betik enjekte eder.',
        'ai.configure'    => 'Sağlayıcı anahtarı demoda değiştirilemez.',
        'payments.manage' => 'Ödeme sağlayıcı bilgileri demoda görüntülenemez.',
        'users.manage'    => 'Yeni yönetici açmak demoyu ele geçirmektir.',
        'roles.manage'    => 'İzin matrisini değiştirmek tüm kısıtları aşmanın yoludur.',
        'tools.manage'    => 'Yedek indirme/geri yükleme ve şema işlemleri demoda kapalıdır.',
    ];
}

/** Bu izin demoda kapalı mı? */
function demo_denied_permission($permission) {
    if (!demo_mode()) { return false; }
    return isset(demo_denied_permissions()[(string)$permission]);
}

/**
 * Riskli DESENE uyan ama BİLEREK açık bırakılan uçlar.
 *
 * `tests/probe.php demo_gate` sondası, tehlikeli görünen her ucu bildirir.
 * Salt okunur uçlar (matrisi göster, kullanıcının izinlerini listele) demoda
 * AÇIK olmalıdır — yayıncı adayının göreceği en anlatıcı ekranlardan biri
 * rol matrisidir.
 *
 * Bu liste sondanın gürültüsünü susturmak için DEĞİL, kararı KAYDA GEÇİRMEK
 * içindir: her satır "bakıldı, yazmıyor, açık kalsın" demektir. Sonda bu
 * listeyi çıkarır; böylece boş kalır ve yeni eklenen riskli bir uç hemen
 * görünür.
 */
function demo_reviewed_open() {
    return [
        // Görsel reklam kurmak denenmeye değer bir özelliktir ve zararsızdır.
        // TEHLİKELİ olan ham HTML/JS kodudur; o `ads.html` KİLİTLİ iznine
        // bağlıdır (inc/api/vitrin.php) ve demo kipinde `can()` o izni
        // reddeder — yani asıl kapı izin katmanındadır, uç adında değil.
        'ads.save' => 'ham HTML `ads.html` iznine bağlı; o izin demoda kapalı',
    ];
}

/**
 * Desen tabanlı engeller — yeni eklenen tehlikeli uçları da yakalar.
 *
 * Ad listesi bir sürüm eskiyebilir; desen eskimeye daha dayanıklıdır. Örneğin
 * ileride `backup.rotate` eklenirse `backup.` deseni onu da kapsar.
 */
function demo_blocked_patterns() {
    return [
        // İKİNCİ KATMAN. Bu aileler zaten `demo_denied_permissions()` ile kapalı;
        // desenler, izni olmayan bir uç ailesi eklenirse diye durur.
        '/^backup\./'    => 'Yedek işlemleri demoda kapalıdır.',
        '/\.export$/'    => 'Dışa aktarma demoda kapalıdır (kişisel veri).',
        '/^payment\./'   => 'Ödeme işlemleri demoda kapalıdır.',
        '/^ai\./'        => 'Yapay zekâ çağrıları ücret ürettiği için demoda kapalıdır.',
        '/^tools\./'     => 'Sistem araçları demoda kapalıdır.',
        '/^users\./'     => 'Kullanıcı ve yetki işlemleri demoda kapalıdır.',
        '/^roles\./'     => 'Rol ve izin işlemleri demoda kapalıdır.',
    ];
}

/** Bu uç demoda engelli mi? Engelliyse gerekçe döner, değilse ''. */
function demo_block_reason($action) {
    if (!demo_mode()) { return ''; }
    $action = (string)$action;

    $liste = demo_blocked_actions();
    if (isset($liste[$action])) { return (string)$liste[$action]; }

    foreach (demo_blocked_patterns() as $desen => $neden) {
        if (preg_match($desen, $action)) { return (string)$neden; }
    }
    return '';
}

/**
 * Demoda değiştirilemeyen ayar anahtarları.
 *
 * Ayarlar ekranı tümüyle kapatılmadı — yayıncının göreceği en önemli ekranlardan
 * biri ve denenebilmeli. Yalnız kurulumu bozan ya da sınırları aşan anahtarlar
 * yazmaya kapalıdır; gerisi serbesttir ve zaten düzenli olarak sıfırlanır.
 */
function demo_locked_settings() {
    return [
        'site_url', 'base_url', 'use_rewrite',
        'ai_api_key', 'ai_provider', 'ai_base_url', 'ai_enabled', 'ai_model',
        'backup_ftp_host', 'backup_ftp_user', 'backup_ftp_pass', 'backup_ftp_path',
        'backup_remote_enabled', 'backup_schedule',
        'smtp_host', 'smtp_user', 'smtp_pass', 'smtp_port', 'mail_from',
        'analytics_external', 'analytics_external_html',
        'payment_enabled',
    ];
}

/** Ayar anahtarı demoda kilitli mi? */
function demo_setting_locked($key) {
    if (!demo_mode()) { return false; }
    $key = (string)$key;
    if (in_array($key, demo_locked_settings(), true)) { return true; }
    // Sağlayıcı anahtarları ve parolalar: desenle de yakala.
    foreach (['_key', '_pass', '_secret', '_salt', '_token'] as $sonek) {
        if (substr($key, -strlen($sonek)) === $sonek) { return true; }
    }
    return false;
}

/**
 * Demoda POST kabul etmeyen panel sayfaları.
 *
 * Bu sayfalar formu doğrudan kendileri işler (API'den geçmez), bu yüzden
 * `api.php` kapısı onları görmez ve ikinci bir kapı gerekir.
 */
function demo_blocked_pages() {
    return [
        'users'    => 'Kullanıcı yönetimi demoda kapalıdır.',
        'tools'    => 'Araçlar (yedek, göç, günlük) demoda kapalıdır.',
        'payments' => 'Ödeme ayarları demoda kapalıdır.',
    ];
}

/** Demo kipinde e-posta gönderimi kapalıdır (spam aracı olmasın). */
function demo_mail_blocked() { return demo_mode(); }

/**
 * Panelde gösterilecek demo şeridi.
 *
 * Ziyaretçi neyin kapalı olduğunu ÖNCEDEN bilmeli; bir düğmeye basıp hata
 * almak, ürünün bozuk olduğu izlenimi verir.
 */
function demo_banner_html() {
    if (!demo_mode()) { return ''; }
    $sifirlama = (int)cfg('demo_reset_hours', 6);
    return '<div class="uyari bilgi" style="margin:0 0 16px">'
         . '<strong>Bu bir demo kurulumudur.</strong> İçerik oluşturabilir, düzenleyebilir ve '
         . 'panelin tamamını gezebilirsiniz. Güvenlik ve maliyet nedeniyle kapalı olanlar: '
         . 'yapay zekâ çağrıları, e-posta gönderimi, yedek işlemleri, kullanıcı ve rol yönetimi, '
         . 'ham HTML reklam kodu, özel CSS, ödeme ayarları ve dış RSS kaynağı ekleme. '
         . 'Veriler <strong>' . $sifirlama . ' saatte bir</strong> sıfırlanır.'
         . '</div>';
}

// ============================================================ zamanlı sıfırlama

/**
 * Demo verisini sıfırlar: veritabanını temiz bir kopyadan geri yükler.
 *
 * NEDEN DOSYA KOPYASI, NEDEN "DELETE FROM" DEĞİL:
 * Tablo tablo silmek, yeni bir tablo eklendiğinde unutulur ve demo zamanla
 * çöp biriktirir. Dosya kopyası şemadan bağımsızdır ve her zaman aynı temiz
 * duruma döner.
 *
 * Anlık görüntü `db/demo-temiz.sqlite` dosyasıdır ve demo kurulurken bir kez
 * üretilir (`tools/demo-hazirla.php`). Dosya yoksa sıfırlama SESSİZCE atlanır —
 * yanlışlıkla boş bir veritabanı yazmaktansa hiç yazmamak doğrudur.
 */
function demo_reset_run() {
    if (!demo_mode()) { return 'demo kipi kapalı'; }
    if (db_driver() !== 'sqlite') { return 'yalnız SQLite kurulumda çalışır'; }

    $temiz = DB_DIR . '/demo-temiz.sqlite';
    $canli = (string)cfg('db_path', DB_DIR . '/manset.sqlite');
    if (!is_file($temiz)) { return 'temiz kopya yok, atlandı'; }

    $saat = max(1, (int)cfg('demo_reset_hours', 6));
    $son  = (int)setting('demo_last_reset', '0');
    if ($son > 0 && (time() - $son) < $saat * 3600) { return 'sırası değil'; }

    // Bağlantıyı kapat: Windows'ta açık dosyanın üzerine yazılamaz.
    db_reset();
    $ok = @copy($temiz, $canli);
    foreach (['-wal', '-shm'] as $ek) { @unlink($canli . $ek); }
    db_reset();

    if (!$ok) { log_error('demo sıfırlama: kopyalanamadı'); return 'kopyalanamadı'; }

    setting_set('demo_last_reset', (string)time());
    if (function_exists('cache_flush')) { cache_flush(); }

    // Yüklenen dosyalar da temizlenir; yoksa demo diski dolar.
    $n = 0;
    foreach ((array)glob(UPLOAD_DIR . '/*/*/*') as $f) {
        if (is_file($f) && strpos(basename($f), 'demo-') !== 0) { @unlink($f); $n++; }
    }
    return 'sıfırlandı (' . $n . ' yüklenen dosya silindi)';
}

if (function_exists('cron_register')) {
    cron_register('demo_sifirla', 'demo_reset_run', true);
}
