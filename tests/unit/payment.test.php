<?php
/**
 * inc/payment.php — ödeme sağlayıcı adaptörü (1.2-04).
 *
 * Bu dosya işin GÜVENLİK İDDİALARINI sınar; biçimsel yardımcılar yalnız
 * yan üründür. Sınanan dört iddia:
 *
 *   1. İmzası tutmayan webhook HİÇBİR kayıt yazmaz ve aboneliğe dokunmaz.
 *   2. Aynı webhook iki kez gelirse abonelik BİR kez uzar (çift işlem).
 *   3. Webhook'un bildirdiği tutar kendi kaydımızla tutmuyorsa uzatma YOK.
 *   4. İade `valid_until`'i geri alır.
 *
 * İmza ÜRETİMİ testte gerçektir: sahte sağlayıcı, uygulamanın beklediği
 * HMAC-SHA256'yı bağımsız olarak hesaplar. Yani test, doğrulamanın
 * gerçekten çalıştığını gösterir — atlandığını değil.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/payment.php';

// ------------------------------------------------------------ ortam

/** Sahte sağlayıcı anahtarları — yalnız bu test veritabanında. */
const P_KEY  = 'birim-test-merchant-key';
const P_SALT = 'birim-test-merchant-salt';

setting_set('payment_enabled', '1');
setting_set('payment_provider', 'paytr');
setting_set('payment_test_mode', '1');
setting_set('payment_paytr_merchant_id', '123456');
setting_set('payment_paytr_merchant_key', P_KEY);
setting_set('payment_paytr_merchant_salt', P_SALT);
setting_set('payment_iban', 'TR000000000000000000000000');
setting_set('payment_iban_name', 'Örnek Yayıncılık');
setting_set('payment_manual_enabled', '1');
settings_all(true);

/**
 * Test üyesi oluşturur (rol sabittir: member).
 *
 * E-posta koşuya özel bir önekle üretilir: Windows'ta koşucu, açık kalan
 * PDO tanıtıcısı yüzünden test veritabanını silemeyebiliyor (run.php'nin
 * kendi notu). Önek olmadan devreden bir dosya `users.email` benzersizlik
 * kısıtına çarpıp testi sahte biçimde kırıyordu.
 */
function p_kosu_oneki() {
    static $o = null;
    if ($o === null) { $o = substr(hash('sha256', (string)getmypid() . microtime(true)), 0, 6); }
    return $o;
}

function p_uye_olustur($email) {
    $email = 'p' . p_kosu_oneki() . '-' . $email;
    $var = qv('SELECT id FROM users WHERE email = :e', [':e' => $email], 0);
    if ($var) { return (int)$var; }
    return (int)db_insert('users', [
        'name' => 'Test Üye', 'email' => $email,
        'pass_hash' => password_hash('parola12345', PASSWORD_DEFAULT),
        'role' => 'member', 'active' => 1, 'created_at' => now(),
    ]);
}

/** Test planı. */
function p_plan_olustur($code, $cents, $days) {
    $code = p_kosu_oneki() . '-' . $code;
    $r = payment_plan_save([
        'code' => $code, 'name' => 'Plan ' . $code, 'days' => $days,
        'price' => number_format($cents / 100, 2, ',', ''), 'currency' => 'TRY', 'active' => 1,
    ]);
    return $r;
}

/** Bekleyen bir ödeme kaydı açar ve satırı döndürür. */
function p_odeme_ac($userId, $cents, $days, $provider = 'paytr') {
    $ref = payment_new_ref();
    db_insert('payments', [
        'ref' => $ref, 'user_id' => (int)$userId, 'plan_id' => 0, 'provider' => $provider,
        'status' => 'pending', 'amount_cents' => (int)$cents, 'currency' => 'TRY',
        'days' => (int)$days, 'plan_name' => 'Birim testi planı',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    return payment_by_ref($ref);
}

/**
 * SAHTE SAĞLAYICI — gerçek imzayı bağımsız olarak üretir.
 * Uygulamanın kodunu çağırmaz; belgedeki formülü kendisi uygular ki
 * doğrulamanın gerçekten sınandığından emin olalım.
 */
function p_sahte_bildirim($ref, $status, $totalAmount, $bozukImza = false) {
    $imza = base64_encode(hash_hmac('sha256', $ref . P_SALT . $status . $totalAmount, P_KEY, true));
    if ($bozukImza) { $imza = base64_encode(hash_hmac('sha256', $ref . 'yanlis-salt' . $status . $totalAmount, 'yanlis-key', true)); }
    return [
        'merchant_oid' => $ref,
        'status'       => $status,
        'total_amount' => (string)$totalAmount,
        'hash'         => $imza,
        'payment_type' => 'card',
    ];
}

/** Bir üyenin etkin bitiş tarihi (yoksa boş). */
function p_bitis($userId) {
    $s = member_subscription((int)$userId);
    return trim((string)arr($s, 'valid_until', ''));
}

// ------------------------------------------------------------ biçimsel yardımcılar

test('payment: tutar kuruşa çevrilir, kayan nokta saklanmaz', function () {
    esit(9990, payment_amount_to_cents('99,90'));
    esit(9990, payment_amount_to_cents('99.90'));
    esit(100, payment_amount_to_cents('1'));
    esit(0, payment_amount_to_cents(''));
    esit(0, payment_amount_to_cents('bedava'));
    esit('99,90', payment_cents_to_text(9990));
});

test('payment: para birimi yalnız tanımlı kümeden gelir', function () {
    esit('TRY', payment_currency_clamp('TRY'));
    esit('USD', payment_currency_clamp('usd'));
    esit('TRY', payment_currency_clamp('XXX'), 'bilinmeyen para birimi güvenli varsayılana düşmeli');
    esit('TRY', payment_currency_clamp(''));
});

test('payment: sağlayıcı anahtarı hiçbir yerde ham dönmez', function () {
    $maske = payment_secret_masked(P_KEY);
    icermez($maske, P_KEY, 'maskeli çıktı ham anahtarı içermemeli');
    icerir($maske, '•');
    esit('', payment_secret_masked(''), 'anahtar yoksa maske de boş');
});

test('payment: bilinmeyen sağlayıcı güvenli varsayılana düşer', function () {
    setting_set('payment_provider', 'bilinmeyen_saglayici');
    settings_all(true);
    esit('manual_iban', payment_provider(), 'tanımsız sağlayıcı fail-safe olmalı');
    setting_set('payment_provider', 'paytr');
    settings_all(true);
});

// ------------------------------------------------------------ 1) İMZA

test('webhook: geçerli imza kabul edilir (gerçek HMAC)', function () {
    $uid = p_uye_olustur('imza-ok@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);
    $post = p_sahte_bildirim($o['ref'], 'success', 4900);

    $v = payment_paytr_verify($post);
    dogru(!empty($v['ok']), 'doğru imzalı bildirim kabul edilmeli');
    esit($o['ref'], $v['ref']);
    esit(4900, $v['amount_cents']);
    esit('paytr:' . $o['ref'] . ':success', $v['event_key']);
});

test('webhook: BOZUK imza reddedilir ve HİÇBİR kayıt yazılmaz', function () {
    $uid = p_uye_olustur('imza-bozuk@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);

    $olayOnce  = (int)qv('SELECT COUNT(*) FROM payment_events', [], 0);
    $post = p_sahte_bildirim($o['ref'], 'success', 4900, true);

    $v = payment_paytr_verify($post);
    yanlis(!empty($v['ok']), 'yanlış anahtarla üretilen imza kabul edilmemeli');

    $r = payment_webhook_handle('paytr', $post);
    yanlis(!empty($r['ok']));
    esit(403, (int)$r['code']);

    $sonra = payment_by_ref($o['ref']);
    esit('pending', (string)$sonra['status'], 'reddedilen bildirim ödeme durumunu değiştirmemeli');
    esit($olayOnce, (int)qv('SELECT COUNT(*) FROM payment_events', [], 0), 'reddedilen bildirim olay defterine YAZMAMALI');
    esit('', p_bitis($uid), 'reddedilen bildirim aboneliğe dokunmamalı');
});

test('webhook: imza alanı eksik ya da biçimsiz istek reddedilir', function () {
    $uid = p_uye_olustur('imza-eksik@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);

    yanlis(!empty(payment_paytr_verify([])['ok']), 'boş gövde kabul edilmemeli');
    $p1 = p_sahte_bildirim($o['ref'], 'success', 4900);
    unset($p1['hash']);
    yanlis(!empty(payment_paytr_verify($p1)['ok']), 'imzasız istek kabul edilmemeli');

    $p2 = p_sahte_bildirim($o['ref'], 'success', 4900);
    $p2['merchant_oid'] = $o['ref'] . '/../yol';
    yanlis(!empty(payment_paytr_verify($p2)['ok']), 'biçimsiz sipariş numarası kabul edilmemeli');

    $p3 = p_sahte_bildirim($o['ref'], 'basarili', 4900);
    yanlis(!empty(payment_paytr_verify($p3)['ok']), 'tanımsız durum değeri kabul edilmemeli');

    $p4 = p_sahte_bildirim($o['ref'], 'success', 4900);
    $p4['total_amount'] = '49,00';
    yanlis(!empty(payment_paytr_verify($p4)['ok']), 'tam sayı olmayan tutar kabul edilmemeli');
});

// ------------------------------------------------------------ 2) ÇİFT İŞLEM

test('webhook: aynı bildirim iki kez gelirse abonelik BİR kez uzar', function () {
    $uid = p_uye_olustur('cift@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);
    $post = p_sahte_bildirim($o['ref'], 'success', 4900);

    $r1 = payment_webhook_handle('paytr', $post);
    dogru(!empty($r1['ok']), 'ilk bildirim işlenmeli');
    $bitis1 = p_bitis($uid);
    dogru($bitis1 !== '', 'ilk bildirimden sonra bitiş tarihi olmalı');
    esit('paid', (string)payment_by_ref($o['ref'])['status']);

    // Sağlayıcılar webhook'u TEKRAR gönderir; bu normaldir.
    $r2 = payment_webhook_handle('paytr', $post);
    dogru(!empty($r2['ok']), 'tekrar gönderime de OK dönmeli (yoksa sonsuza dek tekrarlar)');
    $bitis2 = p_bitis($uid);
    esit($bitis1, $bitis2, 'İKİNCİ KEZ UZAMAMALI');

    $r3 = payment_webhook_handle('paytr', $post);
    esit($bitis1, p_bitis($uid), 'üçüncü tekrarda da uzamamalı');

    // Olay defterinde bu anahtardan TEK kayıt olmalı.
    $sayi = (int)qv('SELECT COUNT(*) FROM payment_events WHERE event_key = :k',
        [':k' => 'paytr:' . $o['ref'] . ':success'], 0);
    esit(1, $sayi, 'benzersiz olay anahtarı ikinci kaydı engellemeli');
});

test('yarış: ödeme zaten uygulanmışsa ikinci uygulama uzatmaz', function () {
    $uid = p_uye_olustur('yaris@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);

    $r1 = payment_apply($o, 'yaris:' . $o['ref'] . ':1', 'paytr', 4900, 'TRY');
    dogru(!empty($r1['ok']));
    yanlis(!empty($r1['duplicate']));
    $bitis1 = p_bitis($uid);

    // Aynı ödeme, FARKLI olay anahtarı: çift işlem kalkanı devreye girmez,
    // yarış kalkanı (koşullu UPDATE) devreye girer.
    $r2 = payment_apply($o, 'yaris:' . $o['ref'] . ':2', 'paytr', 4900, 'TRY');
    dogru(!empty($r2['duplicate']), 'ikinci uygulama yarış kalkanına takılmalı');
    esit($bitis1, p_bitis($uid), 'ikinci uygulama süreyi uzatmamalı');
});

// ------------------------------------------------------------ 3) TUTAR

test('webhook: eksik tutar bildirilirse abonelik AÇILMAZ', function () {
    $uid = p_uye_olustur('tutar@birim.test');
    $o = p_odeme_ac($uid, 49900, 365);           // 499 TL / yıllık

    // 1 TL ödemiş gibi, ama İMZA GEÇERLİ (saldırgan kendi tutarını imzalatabilir
    // varsayımı: sağlayıcı gerçekten 100 kuruş tahsil etmiş olabilir).
    $post = p_sahte_bildirim($o['ref'], 'success', 100);
    dogru(!empty(payment_paytr_verify($post)['ok']), 'imza geçerli olmalı — kalkan tutar denetimi');

    payment_webhook_handle('paytr', $post);

    esit('', p_bitis($uid), 'tutar tutmuyorsa abonelik açılmamalı');
    esit('failed', (string)payment_by_ref($o['ref'])['status'], 'kayıt başarısıza düşmeli');

    // Doğru tutar sonradan gelse bile kayıt artık pending değildir: uzatma yok.
    $post2 = p_sahte_bildirim($o['ref'], 'success', 49900);
    payment_webhook_handle('paytr', $post2);
    esit('', p_bitis($uid), 'başarısıza düşmüş kayıt sonradan uzatma yapmamalı');
});

test('webhook: farklı para biriminde bildirim uzatma yapmaz', function () {
    $uid = p_uye_olustur('para@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);
    $r = payment_apply($o, 'para:' . $o['ref'], 'paytr', 4900, 'USD');
    yanlis(!empty($r['ok']), 'para birimi tutmuyorsa uygulanmamalı');
    esit('', p_bitis($uid));
});

// ------------------------------------------------------------ 4) UZATMA / İADE

test('uzatma: süresi dolmamış abonelik kalan hakkını kaybetmez', function () {
    $uid = p_uye_olustur('uzatma@birim.test');

    $o1 = p_odeme_ac($uid, 4900, 30);
    payment_webhook_handle('paytr', p_sahte_bildirim($o1['ref'], 'success', 4900));
    $b1 = strtotime(p_bitis($uid));
    dogru($b1 > time(), 'ilk ödeme geleceğe bitiş vermeli');

    $o2 = p_odeme_ac($uid, 4900, 30);
    payment_webhook_handle('paytr', p_sahte_bildirim($o2['ref'], 'success', 4900));
    $b2 = strtotime(p_bitis($uid));

    // İkinci 30 gün, BUGÜNÜN değil mevcut bitişin üstüne eklenmeli.
    dogru($b2 - $b1 >= 29 * 86400, 'ikinci ödeme mevcut bitişin üstüne eklenmeli');
});

test('iade: valid_until geri alınır', function () {
    $uid = p_uye_olustur('iade@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);
    payment_webhook_handle('paytr', p_sahte_bildirim($o['ref'], 'success', 4900));
    $oncesi = strtotime(p_bitis($uid));
    dogru($oncesi > time());

    $pid = (int)payment_by_ref($o['ref'])['id'];
    $r = payment_refund($pid, 1, 'birim testi');
    dogru(!empty($r['ok']), 'ödenmiş kayıt iade edilebilmeli');
    esit('refunded', (string)payment_by_id($pid)['status']);
    esit('free', (string)member_subscription($uid)['tier'], '30 günlük tek ödeme iade edilince kademe free olmalı');

    // İkinci iade denemesi kabul edilmemeli (süre iki kez düşmesin).
    $r2 = payment_refund($pid, 1, 'ikinci deneme');
    yanlis(!empty($r2['ok']), 'aynı ödeme iki kez iade edilememeli');
});

test('iade: uzun abonelikte yalnız o ödemenin günü düşer', function () {
    $uid = p_uye_olustur('iade2@birim.test');
    $o1 = p_odeme_ac($uid, 4900, 90);
    payment_webhook_handle('paytr', p_sahte_bildirim($o1['ref'], 'success', 4900));
    $o2 = p_odeme_ac($uid, 4900, 30);
    payment_webhook_handle('paytr', p_sahte_bildirim($o2['ref'], 'success', 4900));
    $tepe = strtotime(p_bitis($uid));

    $pid2 = (int)payment_by_ref($o2['ref'])['id'];
    payment_refund($pid2, 1, '');
    $sonra = strtotime(p_bitis($uid));
    dogru($sonra > time(), '90 günlük abonelik iadeden sonra da sürmeli');
    dogru($tepe - $sonra >= 29 * 86400, 'yalnız iade edilen ödemenin günü düşmeli');
});

// ------------------------------------------------------------ bilinmeyen sipariş / sağlayıcı

test('webhook: bilinmeyen sipariş numarası kayıt açmaz', function () {
    $sahteRef = 'MNS' . date('YmdHis') . 'DEADBEEF';
    $once = (int)qv('SELECT COUNT(*) FROM payments', [], 0);
    $r = payment_webhook_handle('paytr', p_sahte_bildirim($sahteRef, 'success', 4900));
    yanlis(!empty($r['ok']));
    esit(404, (int)$r['code']);
    esit($once, (int)qv('SELECT COUNT(*) FROM payments', [], 0), 'webhook yeni ödeme kaydı OLUŞTURMAMALI');
});

test('webhook: tanımsız sağlayıcı adı reddedilir', function () {
    $r = payment_webhook_handle('stripe', ['merchant_oid' => 'X', 'status' => 'success']);
    yanlis(!empty($r['ok']));
    esit(400, (int)$r['code']);
});

test('webhook: başarısız bildirim aboneliğe dokunmaz', function () {
    $uid = p_uye_olustur('basarisiz@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);
    $r = payment_webhook_handle('paytr', p_sahte_bildirim($o['ref'], 'failed', 4900));
    dogru(!empty($r['ok']), 'sağlayıcıya OK dönmeli');
    esit('failed', (string)payment_by_ref($o['ref'])['status']);
    esit('', p_bitis($uid));
});

test('webhook: ödenmiş kayıt sahte failed bildirimiyle geri alınamaz', function () {
    $uid = p_uye_olustur('gerial@birim.test');
    $o = p_odeme_ac($uid, 4900, 30);
    payment_webhook_handle('paytr', p_sahte_bildirim($o['ref'], 'success', 4900));
    $bitis = p_bitis($uid);

    payment_webhook_handle('paytr', p_sahte_bildirim($o['ref'], 'failed', 4900));
    esit('paid', (string)payment_by_ref($o['ref'])['status'], 'ödenmiş kayıt failed bildirimiyle düşmemeli');
    esit($bitis, p_bitis($uid), 'abonelik geri alınmamalı');
});

// ------------------------------------------------------------ planlar / başlatma

test('plan: kod benzersiz, kademe girdiden alınmaz', function () {
    $r = p_plan_olustur('birim-aylik', 4900, 30);
    dogru(!empty($r['ok']), 'plan kaydedilmeli');
    $p = payment_plan_by_code(p_kosu_oneki() . '-birim-aylik');
    esit('premium', (string)$p['tier'], 'kademe daima premium olmalı');

    $r2 = payment_plan_save(['code' => p_kosu_oneki() . '-birim-aylik', 'name' => 'Çakışan', 'days' => 30, 'price' => '10']);
    yanlis(!empty($r2['ok']), 'aynı kod ikinci kez alınamamalı');
});

test('payment_start: kapalı planla ödeme başlatılamaz', function () {
    $uid = p_uye_olustur('baslat@birim.test');
    p_plan_olustur('birim-kapali', 4900, 30);
    payment_plan_save(['id' => (int)payment_plan_by_code(p_kosu_oneki() . '-birim-kapali')['id'],
        'code' => p_kosu_oneki() . '-birim-kapali', 'name' => 'Kapalı', 'days' => 30, 'price' => '49', 'active' => 0]);

    $r = payment_start($uid, p_kosu_oneki() . '-birim-kapali', 'manual_iban');
    yanlis(!empty($r['ok']), 'satışta olmayan plan reddedilmeli');
    $r2 = payment_start($uid, 'olmayan-plan', 'manual_iban');
    yanlis(!empty($r2['ok']));
});

test('payment_start: personel hesabına abonelik satılmaz', function () {
    $adminId = (int)db_insert('users', [
        'name' => 'Personel', 'email' => p_kosu_oneki() . '-personel-odeme@birim.test',
        'pass_hash' => 'x', 'role' => 'editor', 'active' => 1, 'created_at' => now(),
    ]);
    p_plan_olustur('birim-satis', 4900, 30);
    $r = payment_start($adminId, p_kosu_oneki() . '-birim-satis', 'manual_iban');
    yanlis(!empty($r['ok']), 'rol member değilse ödeme başlatılmamalı');
});

test('payment_start: havale kaydı açılır ve sipariş numarası benzersizdir', function () {
    $uid = p_uye_olustur('havale@birim.test');
    p_plan_olustur('birim-havale', 4900, 30);
    $r = payment_start($uid, p_kosu_oneki() . '-birim-havale', 'manual_iban');
    dogru(!empty($r['ok']), 'havale akışı açılmalı');
    esit('iban', (string)$r['mode']);
    dogru(preg_match('/^MNS[0-9]{14}[0-9A-F]{8}$/', (string)$r['ref']) === 1, 'sipariş numarası alfanümerik olmalı');

    $o = payment_by_ref($r['ref']);
    esit($uid, (int)$o['user_id']);
    esit(4900, (int)$o['amount_cents'], 'tutar plandan kopyalanmalı');
    esit('pending', (string)$o['status']);
});

test('dekont: başkasının siparişine dekont yüklenemez', function () {
    $a = p_uye_olustur('dekont-a@birim.test');
    $b = p_uye_olustur('dekont-b@birim.test');
    $o = p_odeme_ac($a, 4900, 30, 'manual_iban');
    // Dosya hiç okunmadan sahiplik kapısına takılmalı.
    $r = payment_receipt_upload($b, $o['ref'], ['tmp_name' => '', 'name' => 'x.jpg', 'size' => 10]);
    yanlis(!empty($r['ok']), 'sahiplik kapısı başkasının siparişini reddetmeli');
    esit(404, (int)arr($r, 'code', 0));
});

test('dekont: yol adı yalnız güvenli desende açılır', function () {
    esit('', payment_receipt_path('../../config.php'), 'yol geçişi reddedilmeli');
    esit('', payment_receipt_path('dekont.php'), 'desen dışı ad reddedilmeli');
    esit('', payment_receipt_path(''));
});

test('elle onay: yalnız havale kaydı onaylanır ve iki kez uzatmaz', function () {
    $uid = p_uye_olustur('elle@birim.test');
    $o = p_odeme_ac($uid, 4900, 30, 'manual_iban');

    $r = payment_manual_approve((int)$o['id'], 1, 'dekont görüldü');
    dogru(!empty($r['ok']));
    $bitis = p_bitis($uid);
    dogru($bitis !== '');

    $r2 = payment_manual_approve((int)$o['id'], 1, 'ikinci kez');
    yanlis(!empty($r2['ok']), 'ödenmiş kayıt yeniden onaylanamamalı');
    esit($bitis, p_bitis($uid), 'ikinci onay süreyi uzatmamalı');

    // Kart ödemesi elle onaylanamaz.
    $k = p_odeme_ac($uid, 4900, 30, 'paytr');
    yanlis(!empty(payment_manual_approve((int)$k['id'], 1, '')['ok']),
        'sağlayıcı ödemesi panelden elle ödendi yapılamamalı');
});
