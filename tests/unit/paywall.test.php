<?php
/**
 * inc/paywall.php — ölçülü ödeme duvarı (1.2-05).
 *
 * Sınanan iddialar (hepsi GÜVENLİK ya da PARA iddiasıdır):
 *
 *   1. Sayaç çerezi İMZALIDIR: okur "kalan hakkım 99" yazamaz. Kurcalanan
 *      çerez taze sayaç olarak okunur, kurcalayana avantaj sağlamaz.
 *   2. Ücretsiz hak biter ve KİLİT gelir; aynı habere geri dönmek hak yakmaz.
 *   3. Sayaç YALNIZ haberin kendi sayfasında işler — liste bağlamında değil.
 *      (Anasayfada çerez verilmemesinin kod tarafındaki dayanağı budur.)
 *   4. Hediye token'ı TEK KULLANIMLIKTIR: ikinci tüketim reddedilir.
 *   5. Hediye token'ının HAM hâli veritabanında YOKTUR, yalnız SHA-256 özeti var.
 *   6. Deneme HESAP BAŞINA BİR KEZDİR (benzersiz indeks).
 *   7. Süresi dolmuş hediye bağlantısı kullanılamaz.
 *
 * ORTAM NOTU: birim koşucusunda HTTP başlığı yoktur; `paywall_cookie_put()`
 * CLI'da `headers_sent()` false döndüğü için setcookie() uyarı üretmeden çalışır
 * ve `$_COOKIE` güncellenir. Testler sayacı bu yolla, gerçek imzayla sınar.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/members.php';
require_once INC_DIR . '/roles.php';
require_once INC_DIR . '/paywall.php';

// ------------------------------------------------------------ ortam
setting_set('paywall_meter_enabled', '1');
setting_set('paywall_meter_free', '3');
setting_set('paywall_meter_scope', 'premium');
setting_set('paywall_gift_enabled', '1');
setting_set('paywall_gift_monthly', '5');
setting_set('paywall_gift_ttl_hours', '72');
setting_set('paywall_trial_days', '7');
settings_all(true);

/** Sayaç/karar belleğini ve çerezleri sıfırlar (her senaryo temiz başlasın). */
function pw_sifirla() {
    unset($_COOKIE[PAYWALL_METER_COOKIE], $_COOKIE[PAYWALL_GIFT_COOKIE]);
    paywall_memo_reset();
}

/** İsteği "şu haberin kendi sayfası" gibi gösterir (istek belleğini tazeler). */
function pw_istek($postId) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['r'] = 'haber/ornek-baslik-' . (int)$postId;
    paywall_memo_reset();
}

/**
 * Sayacı doğrudan (imzalı) çereze yazar.
 *
 * NEDEN paywall_meter_write() DEĞİL: birim koşucusu CLI'da çıktı bastığı için
 * `headers_sent()` TRUE döner ve paywall_cookie_put() bilerek yazmayı reddeder.
 * Bu davranışın kendisi ayrıca sınanıyor ("çerez yazılamıyorsa kilit").
 */
function pw_yaz(array $ids) {
    $yuk = '1|' . paywall_period() . '|' . implode(',', $ids);
    $_COOKIE[PAYWALL_METER_COOKIE] = $yuk . '|' . paywall_sign($yuk);
    paywall_memo_reset();
}

/** Test haberi (yalnız dizi — veritabanı gerekmez). */
function pw_post($id, $vis = 'premium') {
    return ['id' => (int)$id, 'visibility' => $vis, 'title' => 'Test', 'slug' => 'ornek-baslik'];
}

/** Test üyesi oluşturur ve kimliğini döndürür. */
function pw_uye($ek = '', $dogrulanmis = true) {
    $mail = 'duvar-' . getmypid() . '-' . $ek . '-' . bin2hex(random_bytes(3)) . '@ornek.test';
    $id = (int)db_insert('users', [
        'name' => 'Duvar Test', 'email' => $mail,
        'pass_hash' => password_hash('parola12345', PASSWORD_DEFAULT),
        'role' => 'member', 'active' => 1, 'created_at' => now(),
    ]);
    // DENEME E-POSTA DOĞRULAMASI İSTER (denetim turu 4, Y-01). Bu testler
    // eskiden doğrulanmamış hesapla deneme başlatabiliyordu — yani güvensiz
    // davranışı sabitliyorlardı. Varsayılan artık doğrulanmış üye;
    // doğrulanmamış hâli ayrıca ve BİLEREK sınanıyor (aşağıda).
    if ($dogrulanmis && function_exists('member_mark_verified')) { member_mark_verified($id); }
    return $id;
}

// ================================================================ 1) imza

test('sayaç çerezi imzalıdır: kurcalanan değer taze sayaç olarak okunur', function () {
    pw_sifirla();

    // Gerçek imzayla üretilmiş bir değer okunabilmeli
    $yuk = '1|' . paywall_period() . '|11,22';
    $_COOKIE[PAYWALL_METER_COOKIE] = $yuk . '|' . paywall_sign($yuk);
    paywall_memo_reset();
    $st = paywall_meter_read();
    esit([11, 22], $st['ids'], 'geçerli imzalı çerez okunuyor');

    // Okurun elle yazdığı değer (imza yok / yanlış)
    $_COOKIE[PAYWALL_METER_COOKIE] = '1|' . paywall_period() . '|99|deadbeefdeadbeefdeadbeefdeadbeef';
    paywall_memo_reset();
    $st = paywall_meter_read();
    esit([], $st['ids'], 'imzası tutmayan çerez YOK SAYILIR');

    // Doğru imza + BAŞKA yük (imza yükle bağlı olmalı)
    $sahte = '1|' . paywall_period() . '|1,2,3';
    $_COOKIE[PAYWALL_METER_COOKIE] = '1|' . paywall_period() . '|4,5,6|' . paywall_sign($sahte);
    paywall_memo_reset();
    $st = paywall_meter_read();
    esit([], $st['ids'], 'imza başka bir yüke aitse reddedilir');

    // Geçmiş ay → sayaç sıfırlanır
    $eski = '1|2020-01|7,8,9';
    $_COOKIE[PAYWALL_METER_COOKIE] = $eski . '|' . paywall_sign($eski);
    paywall_memo_reset();
    $st = paywall_meter_read();
    esit([], $st['ids'], 'geçen ayın sayacı bu ayı etkilemez');
});

test('paywall_sign_ok sabit zamanlı karşılaştırma yapar ve boş imzayı reddeder', function () {
    yanlis(paywall_sign_ok('x', ''), 'boş imza kabul edilmez');
    yanlis(paywall_sign_ok('x', 'kisa'), 'kısa imza kabul edilmez');
    dogru(paywall_sign_ok('x', paywall_sign('x')), 'kendi imzası kabul edilir');
    esit(32, strlen(paywall_sign('x')), 'imza 32 hane');
});

// ================================================================ 2) ölçülü sayaç

test('ücretsiz hak tükenince kilit gelir; aynı habere dönmek hak yakmaz', function () {
    pw_sifirla();
    $_COOKIE[PAYWALL_METER_COOKIE] = '';

    // Hak 3. Üç ayrı haber okunur.
    $okunan = [];
    foreach ([101, 102, 103] as $id) {
        pw_istek($id);
        $k = paywall_decide(pw_post($id), null);
        dogru($k['allow'], 'haber ' . $id . ' ücretsiz hakla açılmalı');
        esit('olculu', $k['neden']);
        dogru($k['yaz'], 'yeni hak düşülürken çerez yazılmalı');
        $okunan = $k['ids'];
        pw_yaz($okunan);
    }

    // Dördüncü haber: hak bitti.
    pw_istek(104);
    $k = paywall_decide(pw_post(104), null);
    yanlis($k['allow'], 'dördüncü haber KİLİTLİ olmalı');
    esit('olculu-bitti', $k['neden']);

    // Okunmuş bir habere geri dönmek: açık, ama yeni hak YAKMAZ.
    pw_istek(102);
    $k = paywall_decide(pw_post(102), null);
    dogru($k['allow'], 'daha önce okunan haber yine açılmalı');
    esit('olculu-tekrar', $k['neden']);
    yanlis($k['yaz'], 'tekrar okumada çerez yeniden yazılmaz');
});

test('sayaç yalnız haberin KENDİ sayfasında işler (liste bağlamında değil)', function () {
    pw_sifirla();
    $_COOKIE[PAYWALL_METER_COOKIE] = '';

    // Anasayfa isteği gibi davran
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['r'] = '';
    $k = paywall_decide(pw_post(201), null);
    yanlis($k['allow'], 'liste bağlamında ölçülü hak KULLANILMAZ');
    esit('sayfa-disi', $k['neden']);
    yanlis($k['yaz'], 'liste bağlamında çerez YAZILMAZ');

    // Başka bir haberin sayfasındayken de bu haberin hakkı düşülmez
    pw_istek(999);
    $k = paywall_decide(pw_post(201), null);
    esit('sayfa-disi', $k['neden'], 'başka haberin sayfasında da hak düşülmez');
});

test('kapsam ayarı: premium seçiliyken members haberi ölçülü haktan yararlanmaz', function () {
    pw_sifirla();
    $_COOKIE[PAYWALL_METER_COOKIE] = '';
    setting_set('paywall_meter_scope', 'premium');
    settings_all(true);

    pw_istek(301);
    $k = paywall_decide(pw_post(301, 'members'), null);
    yanlis($k['allow'], 'kapsam dışı görünürlük ölçülü hakla açılmaz');

    setting_set('paywall_meter_scope', 'all');
    settings_all(true);
    $k = paywall_decide(pw_post(301, 'members'), null);
    dogru($k['allow'], 'kapsam genişletilince members da ölçülür');

    setting_set('paywall_meter_scope', 'premium');
    settings_all(true);
});

test('ölçülü duvar kapalıyken hiçbir hak verilmez (1.1 davranışı)', function () {
    pw_sifirla();
    $_COOKIE[PAYWALL_METER_COOKIE] = '';
    setting_set('paywall_meter_enabled', '0');
    settings_all(true);

    pw_istek(401);
    $k = paywall_decide(pw_post(401), null);
    yanlis($k['allow'], 'duvar kapalıyken ölçülü hak yok');

    setting_set('paywall_meter_enabled', '1');
    settings_all(true);
});

// ================================================================ 3) hediye

test('hediye token`ı: ham hâli veritabanında YOK, yalnız SHA-256 özeti var', function () {
    $veren = pw_uye('veren');
    member_set_subscription($veren, 'premium', date('Y-m-d', time() + 86400 * 30));

    $postId = (int)db_insert('posts', [
        'title' => 'Hediye testi', 'slug' => 'hediye-testi-' . bin2hex(random_bytes(3)),
        'body' => '<p>gövde</p>', 'status' => 'published', 'visibility' => 'premium',
        'created_at' => now(), 'published_at' => now(),
    ]);

    $r = paywall_gift_create($veren, $postId);
    dogru(!empty($r['ok']), 'abone hediye bağlantısı üretebilmeli: ' . (string)arr($r, 'error', ''));
    $token = (string)arr($r, 'token', '');
    esit(64, strlen($token), 'token 64 hane onaltılık');

    // HAM token hiçbir sütunda geçmemeli
    $satir = q1('SELECT token_hash FROM gift_tokens WHERE post_id = :p AND giver_id = :g',
                [':p' => $postId, ':g' => $veren]);
    dogru($satir !== null, 'kayıt açılmış olmalı');
    esit(hash('sha256', $token), (string)$satir['token_hash'], 'saklanan değer SHA-256 özeti');
    icermez((string)$satir['token_hash'], $token, 'ham token saklanmıyor');
});

test('hediye bağlantısı TEK KULLANIMLIK: ikinci tüketim reddedilir', function () {
    $veren = pw_uye('veren2');
    member_set_subscription($veren, 'premium', date('Y-m-d', time() + 86400 * 30));
    $postId = (int)db_insert('posts', [
        'title' => 'Tek kullanım', 'slug' => 'tek-kullanim-' . bin2hex(random_bytes(3)),
        'body' => '<p>gövde</p>', 'status' => 'published', 'visibility' => 'premium',
        'created_at' => now(), 'published_at' => now(),
    ]);

    $r = paywall_gift_create($veren, $postId);
    $token = (string)$r['token'];

    $bir = paywall_gift_redeem($token);
    dogru(!empty($bir['ok']), 'ilk kullanım geçerli');
    esit($postId, (int)$bir['post_id']);

    $iki = paywall_gift_redeem($token);
    yanlis(!empty($iki['ok']), 'İKİNCİ kullanım reddedilmeli');
    icerir((string)arr($iki, 'error', ''), 'kullanılmış', 'ikinci kullanımın gerekçesi bildiriliyor');

    // used_at gerçekten damgalanmış olmalı (atomik UPDATE`in kanıtı)
    $satir = q1('SELECT used_at FROM gift_tokens WHERE token_hash = :h', [':h' => hash('sha256', $token)]);
    dogru(trim((string)arr($satir, 'used_at', '')) !== '', 'used_at damgalanmış');
});

test('süresi dolmuş hediye bağlantısı kullanılamaz', function () {
    $veren = pw_uye('veren3');
    member_set_subscription($veren, 'premium', date('Y-m-d', time() + 86400 * 30));
    $postId = (int)db_insert('posts', [
        'title' => 'Süresi dolmuş', 'slug' => 'suresi-dolmus-' . bin2hex(random_bytes(3)),
        'body' => '<p>gövde</p>', 'status' => 'published', 'visibility' => 'premium',
        'created_at' => now(), 'published_at' => now(),
    ]);
    $r = paywall_gift_create($veren, $postId);
    $token = (string)$r['token'];

    // Süreyi geriye çek
    q('UPDATE gift_tokens SET expires_at = :e WHERE token_hash = :h',
      [':e' => date('Y-m-d H:i:s', time() - 3600), ':h' => hash('sha256', $token)]);

    $s = paywall_gift_redeem($token);
    yanlis(!empty($s['ok']), 'süresi dolmuş bağlantı reddedilmeli');
    // Reddedilen tüketim used_at yazmamalı (kayıt hâlâ kullanılmamış görünmeli)
    $satir = q1('SELECT used_at FROM gift_tokens WHERE token_hash = :h', [':h' => hash('sha256', $token)]);
    esit('', trim((string)arr($satir, 'used_at', '')), 'başarısız tüketim used_at yazmaz');
});

test('ücretsiz üye hediye bağlantısı üretemez, kota aşılamaz', function () {
    $ucretsiz = pw_uye('ucretsiz');
    $postId = (int)db_insert('posts', [
        'title' => 'Kota', 'slug' => 'kota-' . bin2hex(random_bytes(3)),
        'body' => '<p>gövde</p>', 'status' => 'published', 'visibility' => 'premium',
        'created_at' => now(), 'published_at' => now(),
    ]);
    $r = paywall_gift_create($ucretsiz, $postId);
    yanlis(!empty($r['ok']), 'ücretsiz üye hediye üretemez');

    // Kota 1`e indirilince ikinci hediye reddedilmeli
    setting_set('paywall_gift_monthly', '1');
    settings_all(true);
    $abone = pw_uye('kota');
    member_set_subscription($abone, 'premium', date('Y-m-d', time() + 86400 * 30));
    $ilk = paywall_gift_create($abone, $postId);
    dogru(!empty($ilk['ok']), 'kotanın ilk hediyesi üretilir');
    $ikinci = paywall_gift_create($abone, $postId);
    yanlis(!empty($ikinci['ok']), 'kota dolunca ikinci hediye reddedilir');

    setting_set('paywall_gift_monthly', '5');
    settings_all(true);
});

test('açık (public) habere hediye bağlantısı üretilmez', function () {
    $abone = pw_uye('acik');
    member_set_subscription($abone, 'premium', date('Y-m-d', time() + 86400 * 30));
    $postId = (int)db_insert('posts', [
        'title' => 'Açık haber', 'slug' => 'acik-haber-' . bin2hex(random_bytes(3)),
        'body' => '<p>gövde</p>', 'status' => 'published', 'visibility' => 'public',
        'created_at' => now(), 'published_at' => now(),
    ]);
    $r = paywall_gift_create($abone, $postId);
    yanlis(!empty($r['ok']), 'zaten açık habere hediye üretilmez');
});

// ================================================================ 4) deneme

test('DOĞRULANMAMIŞ e-posta ile deneme başlatılamaz', function () {
    // Denetim turu 4, Y-01. Kayıt hiçbir doğrulama istemiyor ve hesap giriş
    // anında açılıyor; deneme de doğrulama sormuyordu. Denetçi tek IP'den bir
    // dakikada dört sahte hesapla dört kez 7 günlük TAM erişim aldı — ölçülü
    // duvar, hediye kotası ve abonelik ücretinin üçünü birden atlayarak.
    // Diğer üç kapı SAYIYOR; bu kapı KİMLİĞE güveniyordu.
    $u = pw_uye('dogrulanmamis', false);
    $r = paywall_trial_start($u);
    yanlis(!empty($r['ok']), 'doğrulanmamış hesap deneme başlatamaz');
    icerir((string)arr($r, 'error', ''), 'doğrulayın');

    // Doğrulandıktan sonra AYNI hesap başlatabilmeli — kapı e-postaya bakar,
    // hesabı kalıcı olarak cezalandırmaz.
    member_mark_verified($u);
    $r2 = paywall_trial_start($u);
    dogru(!empty($r2['ok']), 'doğrulanan hesap deneme başlatabilir: ' . (string)arr($r2, 'error', ''));
});

test('deneme hesap başına BİR KEZ (benzersiz indeks)', function () {
    $u = pw_uye('deneme');
    $ilk = paywall_trial_start($u);
    dogru(!empty($ilk['ok']), 'ilk deneme başlar: ' . (string)arr($ilk, 'error', ''));
    esit(7, (int)$ilk['days']);

    $iki = paywall_trial_start($u);
    yanlis(!empty($iki['ok']), 'ikinci deneme reddedilir');

    // Deneme bitse bile yeniden başlatılamaz — satır SİLİNMEZ.
    q('UPDATE paywall_trials SET ends_at = :e WHERE user_id = :u',
      [':e' => date('Y-m-d H:i:s', time() - 86400), ':u' => $u]);
    paywall_memo_reset();
    $uc = paywall_trial_start($u);
    yanlis(!empty($uc['ok']), 'süresi dolmuş deneme yeniden başlatılamaz');
});

test('etkin deneme kilitli haberi açar, süresi dolan açmaz', function () {
    pw_sifirla();
    $_COOKIE[PAYWALL_METER_COOKIE] = '';
    setting_set('paywall_meter_enabled', '0');   // yalnız denemeyi ölçelim
    settings_all(true);

    $u = pw_uye('deneme2');
    paywall_trial_start($u);
    $kullanici = ['id' => $u, 'role' => 'member', 'active' => 1];

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['r'] = '';
    $k = paywall_decide(pw_post(501), $kullanici);
    dogru($k['allow'], 'etkin deneme kilidi açar');
    esit('deneme', $k['neden']);

    q('UPDATE paywall_trials SET ends_at = :e WHERE user_id = :u',
      [':e' => date('Y-m-d H:i:s', time() - 60), ':u' => $u]);
    paywall_memo_reset();
    $k = paywall_decide(pw_post(501), $kullanici);
    yanlis($k['allow'], 'süresi dolan deneme kilidi açmaz');

    setting_set('paywall_meter_enabled', '1');
    settings_all(true);
});

// ================================================================ 5) yenileme hatırlatması

test('yenileme hatırlatması aynı dönem için iki kez kaydedilemez', function () {
    $u = pw_uye('hatirlatma');
    $donem = '2026-12-31 23:59:59';
    $id = (int)db_insert('paywall_notices', [
        'user_id' => $u, 'kind' => 'renew', 'period_key' => $donem, 'sent_at' => now(),
    ]);
    dogru($id > 0, 'ilk kayıt açılır');
    atar(function () use ($u, $donem) {
        db_insert('paywall_notices', [
            'user_id' => $u, 'kind' => 'renew', 'period_key' => $donem, 'sent_at' => now(),
        ]);
    }, 'aynı dönem için ikinci kayıt veritabanı düzeyinde reddedilir');
});

test('çerez yazılamıyorsa hak DÜŞÜLEMEZ ve okur içeri alınmaz (güvenli taraf)', function () {
    pw_sifirla();
    $_COOKIE[PAYWALL_METER_COOKIE] = '';
    setting_set('paywall_meter_enabled', '1');
    settings_all(true);

    pw_istek(601);
    $k = paywall_decide(pw_post(601), null);
    dogru($k['allow'], 'karar aşamasında hak var');
    dogru($k['yaz'], 'karar çerez yazılmasını gerektiriyor');

    // Birim koşucusu CLI'da çıktı basmıştır → headers_sent() TRUE.
    dogru(headers_sent(), 'ortam gereği çıktı başlamış olmalı');
    yanlis(paywall_cookie_put(PAYWALL_METER_COOKIE, 'x', 60), 'çıktı başladıysa çerez YAZILMAZ');

    $uygulanan = paywall_apply($k);
    yanlis($uygulanan['allow'], 'sayaç yazılamayınca karar KİLİDE döner');
    esit('sayac-yazilamadi', $uygulanan['neden']);
});
