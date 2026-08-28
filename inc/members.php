<?php
/**
 * Manşet — üyelik çekirdeği (Faz 7c/7d, USER_TYPES_PLAN.md §6).
 *
 * KAPSAM: kayıt, giriş, e-posta doğrulama, parola sıfırlama, profil, kaydedilen
 * haberler, abonelik kademesi ve panel için üye listeleme.
 *
 * TEMEL KURALLAR
 *  - `role` alanı kayıt girdisinden ASLA okunmaz; sabit 'member' yazılır ve
 *    `is_staff = 0` verilir (§8 risk #6 — rol enjeksiyonu).
 *  - `member_tokens` tablosuna yalnız `hash('sha256', $token)` yazılır; ham token
 *    hiçbir yerde saklanmaz, yalnız e-posta bağlantısında gider (§8 risk #9).
 *  - Premium bir ROL DEĞİLDİR: süresi `memberships.valid_until` ile biten bir
 *    kademedir ve okuma anında değerlendirilir (`member_tier()` — inc/roles.php).
 *  - Panel tarafındaki tüm hedef sorguları `AND role = 'member'` sabitiyle
 *    sınırlanır (§8 risk #7 — moderatörün yetki yükseltmesi).
 *
 * E-POSTA: toplu bülten motoru CONTRACTS §13'te kapsam dışıdır. Buradaki
 * gönderim TEKİL İŞLEMSEL e-postadır (doğrulama / parola sıfırlama). `mail()`
 * yoksa ya da başarısızsa kayıt yine tamamlanır, `needs_verify = true` döner ve
 * yönetici panelindeki "Bekleyen doğrulamalar" listesinden elle onaylanır.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';

// ============================================================ sabitler / yardımcılar

/** Kayıt sonucunda daima aynı mesaj döner — e-posta varlığı sızdırılmaz. */
function member_register_message() {
    return 'Kaydınız alındı. E-posta adresinize doğrulama bağlantısı gönderildiyse '
         . 'bağlantıya tıklayarak hesabınızı etkinleştirebilirsiniz.';
}

/**
 * Kayıt ucunun DEĞİŞMEZ yanıtı.
 *
 * Hesabın var olup olmadığına, doğrulanmış olup olmadığına veya o hesaba
 * posta gidip gitmediğine göre HİÇBİR alan değişmez; yalnız sitenin posta
 * gönderimi açık mı kapalı mı olduğuna bakılır — bu site geneli bir olgudur,
 * hesaba özel değildir, dolayısıyla hesap sayımına yaramaz.
 */
function member_register_response() {
    $posta = member_mail_available();
    return [
        'ok'           => true,
        'needs_verify' => $posta,
        'message'      => $posta
            ? member_register_message()
            : 'Kaydınız alındı. Bu sitede e-posta gönderimi kapalı olduğu için hesabınız '
              . 'yönetici onayından sonra doğrulanmış sayılacak. Şimdiden giriş yapabilirsiniz.',
    ];
}

/** Onay kutusu / bayrak değerlerini tek biçime indirger. */
function member_flag($v) {
    return ($v === 1 || $v === '1' || $v === true || $v === 'on' || $v === 'true' || $v === 'evet');
}

/** Standart hata dönüşü. */
function member_error($message, $code = 400) {
    return ['ok' => false, 'error' => $message, 'code' => (int)$code];
}

/**
 * Üye sayfası adresi.
 *
 * Yönlendiriciye `/hesap` ve `/uye/...` yolları eklenmiş kurulumlarda
 * (settings['member_pretty_urls'] = '1') güzel adresler üretilir; aksi hâlde
 * her kurulumda çalışan doğrudan giriş noktası (`hesap.php`) kullanılır.
 */
function member_url($section = '', array $q = []) {
    $section = preg_replace('/[^a-z]/', '', (string)$section);
    if (setting('member_pretty_urls', '0') === '1') {
        $map = [
            'giris'  => 'uye/giris',
            'kayit'  => 'uye/kayit',
            'dogrula' => 'uye/dogrula',
            'parola' => 'uye/parola',
        ];
        if ($section === '') { return url('hesap', $q); }
        if (isset($map[$section])) { return url($map[$section], $q); }
        return url('hesap', array_merge(['s' => $section], $q));
    }
    if ($section !== '') { $q = array_merge(['s' => $section], $q); }
    return base_url() . '/hesap.php' . ($q ? '?' . http_build_query($q) : '');
}

/**
 * users tablosunda sütun var mı? (Faz 7 göçleri uygulanmamış kurulumlar için.)
 * Sütun adı YALNIZ kodda sabit listeden gelir; girdiden asla gelmez.
 */
function member_users_has_column($col) {
    static $cache = [];
    $izinli = ['is_staff', 'is_responsible', 'display_name', 'bio', 'avatar', 'last_login_at'];
    if (!in_array($col, $izinli, true)) { return false; }
    if (isset($cache[$col])) { return $cache[$col]; }
    try { qv('SELECT ' . $col . ' FROM users LIMIT 1'); return $cache[$col] = true; }
    catch (Throwable $e) { return $cache[$col] = false; }
}

/** Üyelik tabloları hazır mı? (göç 011 uygulanmadan sorgu yapmayalım) */
function member_tables_ready() {
    static $ready = null;
    if ($ready !== null) { return $ready; }
    try {
        qv('SELECT COUNT(*) FROM member_tokens', [], 0);
        qv('SELECT COUNT(*) FROM memberships', [], 0);
        return $ready = true;
    } catch (Throwable $e) { return $ready = false; }
}

/**
 * Üye e-postasını doğrulamış mı?
 *
 * Şemada ayrı bir `verified` sütunu yoktur; doğrulama kanıtı KULLANILMIŞ bir
 * `kind='verify'` token kaydıdır. Elle onayda da (panel) böyle bir kayıt yazılır.
 */
function member_is_verified($userId) {
    $userId = (int)$userId;
    if ($userId <= 0 || !member_tables_ready()) { return false; }
    try {
        return (int)qv('SELECT COUNT(*) FROM member_tokens
            WHERE user_id = :u AND kind = \'verify\' AND used_at IS NOT NULL AND used_at <> \'\'',
            [':u' => $userId], 0) > 0;
    } catch (Throwable $e) { return false; }
}

/** E-postaya göre kullanıcı satırı (rol süzgeci yok — çakışma denetimi için). */
function member_find_by_email($email) {
    $email = mb_strtolower(trim((string)$email), 'UTF-8');
    if ($email === '') { return null; }
    return q1('SELECT id, name, email, role, active FROM users WHERE email = :e', [':e' => $email]);
}

/** Üyenin görünen adı (display_name → name → e-posta yerel bölümü). */
function member_display_name($user) {
    $ad = trim((string)arr($user, 'display_name', ''));
    if ($ad === '') { $ad = trim((string)arr($user, 'name', '')); }
    if ($ad === '') {
        $mail = (string)arr($user, 'email', '');
        $ad = $mail !== '' ? substr($mail, 0, strpos($mail . '@', '@')) : 'Üye';
    }
    return $ad;
}

// ============================================================ oturum

/**
 * Oturumdaki ÜYE (rol='member'). Personel hesabı için null döner.
 * current_user_if_session() kullanılır: çerezi olmayan ziyaretçiye oturum
 * açılmaz, böylece sayfa önbelleği bozulmaz (CONTRACTS §3.1).
 */
function member_current() {
    $u = current_user_if_session();
    if (!$u || (string)arr($u, 'role', '') !== 'member') { return null; }
    return $u;
}

/** Oturumu kapatır (çerez dâhil). */
function member_logout() {
    session_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    @session_destroy();
}

// ============================================================ kayıt

/**
 * Yeni üye kaydı.
 *
 * @param array $in ['email','password','kvkk','website'(tuzak),'display_name']
 * @return array ['ok','user_id','needs_verify','message','error']
 */
function member_register(array $in) {
    if (!member_tables_ready()) {
        return member_error('Üyelik sistemi henüz kurulmadı. Yöneticiyle iletişime geçin.', 503);
    }

    // 1) Hız sınırı — USER_TYPES_PLAN §6: register:<ip> 5 / 900 sn
    if (!rate_limit('register:' . client_ip(), 5, 900)) {
        return member_error('Çok fazla kayıt denemesi yapıldı. Lütfen 15 dakika sonra tekrar deneyin.', 429);
    }

    // 2) Tuzak alan (honeypot) — bota geri bildirim verilmez
    if (trim((string)arr($in, 'website', '')) !== '') {
        // GÜVENLİK (denetim tur 2, B02): yanıt, e-postanın kayıtlı olup olmadığından
        // BAĞIMSIZ olmalı. Aşağıdaki yeni-kayıt dalıyla birebir aynı gövde dönülür.
        return member_register_response();
    }

    // 3) KVKK onayı
    if (!member_flag(arr($in, 'kvkk', ''))) {
        return member_error('Devam edebilmek için kişisel verilerin işlenmesini onaylamanız gerekiyor.');
    }

    // 4) Alan doğrulaması
    $email = mb_strtolower(trim((string)arr($in, 'email', '')), 'UTF-8');
    if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return member_error('Geçerli bir e-posta adresi girin.');
    }
    $pass = (string)arr($in, 'password', '');
    if (mb_strlen($pass) < 8)   { return member_error('Parola en az 8 karakter olmalı.'); }
    if (mb_strlen($pass) > 200) { return member_error('Parola en fazla 200 karakter olabilir.'); }

    $ad = sanitize_line((string)arr($in, 'display_name', ''), 120);
    if ($ad === '') { $ad = substr($email, 0, strpos($email, '@')); }
    if (mb_strlen($ad) > 80) { $ad = mb_substr($ad, 0, 80); }

    // 5) E-posta zaten kayıtlı mı? VARLIK SIZDIRILMAZ: aynı başarı mesajı döner,
    //    ikinci kayıt açılmaz; mevcut ve doğrulanmamış üyeye yeni bağlantı gider.
    $mevcut = member_find_by_email($email);
    if ($mevcut) {
        if ((string)$mevcut['role'] === 'member' && (int)$mevcut['active'] === 1
            && !member_is_verified((int)$mevcut['id'])) {
            $t = member_token_create((int)$mevcut['id'], 'verify', 172800);
            if ($t !== '') { member_send_verify_mail((int)$mevcut['id'], $t); }
        }
        // GÜVENLİK (denetim tur 2, B02): yanıt, e-postanın kayıtlı olup olmadığından
        // BAĞIMSIZ olmalı. Aşağıdaki yeni-kayıt dalıyla birebir aynı gövde dönülür.
        return member_register_response();
    }

    // 6) Kayıt. DİKKAT: `role` ve `is_staff` girdiden ALINMAZ (§8 risk #6).
    $data = [
        'name'       => $ad,
        'email'      => $email,
        'pass_hash'  => password_hash($pass, PASSWORD_DEFAULT),
        'role'       => 'member',
        'active'     => 1,
        'created_at' => now(),
    ];
    if (member_users_has_column('is_staff'))     { $data['is_staff'] = 0; }
    if (member_users_has_column('display_name')) { $data['display_name'] = $ad; }

    try {
        $uid = (int)db_insert('users', $data);
    } catch (Throwable $e) {
        log_error('member_register: ' . $e->getMessage());
        return member_error('Kayıt tamamlanamadı. Lütfen daha sonra tekrar deneyin.', 500);
    }

    // 7) Doğrulama token'ı + e-posta
    $token = member_token_create($uid, 'verify', 172800);
    $mail = member_send_verify_mail($uid, $token);
    $gonderildi = !empty($mail['ok']);

    // Yanıt gövdesi, hesabın yeni mi mevcut mu olduğunu ele vermemeli (B02).
    // $gonderildi yalnız kayda yazılır.
    if (!$gonderildi) { log_error('member_register: doğrulama postası gönderilemedi (uid=' . $uid . ').'); }
    return member_register_response();
}

// ============================================================ giriş

/**
 * Üye girişi. YALNIZ rol='member' hesaplar bu uçtan giriş yapabilir;
 * personel `/admin/` girişini kullanır (USER_TYPES_PLAN §1).
 */
function member_login($email, $pass) {
    $ip = client_ip();
    // Normalleştirme hız sınırından ÖNCE yapılır: aksi hâlde 'A@x' ve 'a@x'
    // ayrı kovalara düşer ve hesap kovası büyük/küçük harf oynayarak aşılır.
    $email = mb_strtolower(trim((string)$email), 'UTF-8');
    // Hesap başına ikinci kova (denetim tur 2, B06): tek kova IP başına olduğu
    // için botnet/proxy havuzu tek hesabı sınırsız deneyebiliyordu. Hesap kovası
    // daha geniş tutulur (meşru kullanıcı kendi IP kovasına zaten takılır),
    // ama dağıtık kaba kuvveti kullanılabilir olmaktan çıkarır.
    if (!rate_limit('memberlogin-acct:' . hash('sha256', $email), 20, 900)) {
        return member_error('Bu hesap için çok fazla deneme yapıldı. 15 dakika sonra tekrar deneyin.', 429);
    }
    if (!rate_limit('memberlogin:' . $ip, 8, 300)) {
        return member_error('Çok fazla giriş denemesi yapıldı. Lütfen 5 dakika sonra tekrar deneyin.', 429);
    }

    $row = q1('SELECT id, name, email, pass_hash, role, active FROM users WHERE email = :e', [':e' => $email]);

    // Kullanıcı yoksa da bir hash doğrulaması çalıştırılır ki yanıt süresi
    // hesabın var olup olmadığını sızdırmasın (SECURITY_AUDIT B-03 deseni).
    $hash = $row ? (string)$row['pass_hash'] : '$2y$10$usesomesillystringforeverysillystringnotarealhash.aBcDe';
    $parolaOk = password_verify((string)$pass, $hash);

    if (!$row || !$parolaOk) {
        log_error('Başarısız üye girişi', $email . ' ip=' . $ip);
        return member_error('E-posta veya parola hatalı.', 401);
    }
    if ((string)$row['role'] !== 'member') {
        return member_error('Bu hesap yönetim ekibine aittir; yönetim panelinden giriş yapın.', 403);
    }
    if ((int)$row['active'] !== 1) {
        return member_error('Hesabınız askıya alınmış. Yöneticiyle iletişime geçin.', 403);
    }

    if (password_needs_rehash((string)$row['pass_hash'], PASSWORD_DEFAULT)) {
        try {
            q('UPDATE users SET pass_hash = :h WHERE id = :i',
                [':h' => password_hash((string)$pass, PASSWORD_DEFAULT), ':i' => (int)$row['id']]);
        } catch (Throwable $e) { log_error('member_login rehash: ' . $e->getMessage()); }
    }

    session_boot();
    session_regenerate_id(true);
    $guncelHash = (string)qv('SELECT pass_hash FROM users WHERE id = :i', [':i' => (int)$row['id']], '');
    session_bind_user((int)$row['id'], $guncelHash);
    rate_limit_clear('memberlogin:' . $ip);

    if (member_users_has_column('last_login_at')) {
        try { q('UPDATE users SET last_login_at = :n WHERE id = :i', [':n' => now(), ':i' => (int)$row['id']]); }
        catch (Throwable $e) { }
    }

    return [
        'ok'       => true,
        'user_id'  => (int)$row['id'],
        'name'     => member_display_name($row),
        'verified' => member_is_verified((int)$row['id']),
        // Oturum kimliği yenilendiği için istemcideki CSRF anahtarı tazelenir.
        'csrf'     => $_SESSION['csrf'],
        'message'  => 'Giriş yapıldı.',
    ];
}

// ============================================================ token

/**
 * Tek kullanımlık, süreli token üretir. HAM token yalnız dönüş değeridir;
 * veritabanına SHA-256 özeti yazılır.
 *
 * @param string $kind 'verify' | 'reset'
 */
function member_token_create($userId, $kind, $ttl = 86400) {
    $userId = (int)$userId;
    $kind = in_array((string)$kind, ['verify', 'reset'], true) ? (string)$kind : 'verify';
    if ($userId <= 0 || !member_tables_ready()) { return ''; }

    $token = bin2hex(random_bytes(32));
    try {
        // Aynı türdeki kullanılmamış eski kayıtlar geçersizleşir (tek etkin bağlantı)
        q('DELETE FROM member_tokens WHERE user_id = :u AND kind = :k AND (used_at IS NULL OR used_at = \'\')',
            [':u' => $userId, ':k' => $kind]);
        db_insert('member_tokens', [
            'user_id'    => $userId,
            'kind'       => $kind,
            'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + max(60, (int)$ttl)),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        log_error('member_token_create: ' . $e->getMessage());
        return '';
    }
    return $token;
}

/**
 * Token'ı tüketir: geçerliyse `used_at` damgalanır ve satır döner, aksi hâlde null.
 * Süresi dolmuş, kullanılmış ya da türü tutmayan token kabul edilmez.
 */
function member_token_consume($token, $kind) {
    $token = (string)$token;
    $kind = (string)$kind;
    if (!preg_match('/^[a-f0-9]{32,128}$/i', $token) || !member_tables_ready()) { return null; }

    try {
        $row = q1('SELECT id, user_id, kind, expires_at, used_at FROM member_tokens
                   WHERE token_hash = :h AND kind = :k', [':h' => hash('sha256', $token), ':k' => $kind]);
    } catch (Throwable $e) { return null; }
    if (!$row) { return null; }

    $used = trim((string)arr($row, 'used_at', ''));
    if ($used !== '') { return null; }                       // tek kullanımlık
    $exp = trim((string)arr($row, 'expires_at', ''));
    if ($exp !== '' && strtotime($exp) < time()) { return null; }   // süreli

    try { q('UPDATE member_tokens SET used_at = :n WHERE id = :i', [':n' => now(), ':i' => (int)$row['id']]); }
    catch (Throwable $e) { return null; }

    $row['used_at'] = now();
    return $row;
}

/** Doğrulanmış say: kullanılmış bir `verify` kaydı yoksa oluşturur (elle onay). */
function member_mark_verified($userId) {
    $userId = (int)$userId;
    if ($userId <= 0 || !member_tables_ready()) { return false; }
    if (member_is_verified($userId)) { return true; }
    try {
        $bekleyen = q1('SELECT id FROM member_tokens WHERE user_id = :u AND kind = \'verify\'
                        AND (used_at IS NULL OR used_at = \'\') ORDER BY id DESC', [':u' => $userId]);
        if ($bekleyen) {
            q('UPDATE member_tokens SET used_at = :n WHERE id = :i', [':n' => now(), ':i' => (int)$bekleyen['id']]);
        } else {
            db_insert('member_tokens', [
                'user_id'    => $userId,
                'kind'       => 'verify',
                'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
                'expires_at' => now(),
                'used_at'    => now(),
                'created_at' => now(),
            ]);
        }
    } catch (Throwable $e) {
        log_error('member_mark_verified: ' . $e->getMessage());
        return false;
    }
    return true;
}

// ============================================================ doğrulama / parola

/** E-posta doğrulama bağlantısını işler. */
function member_verify($token) {
    $row = member_token_consume($token, 'verify');
    if (!$row) {
        return member_error('Doğrulama bağlantısı geçersiz ya da süresi dolmuş. Yeni bağlantı isteyin.');
    }
    $uid = (int)$row['user_id'];
    return [
        'ok'      => true,
        'user_id' => $uid,
        'message' => 'E-posta adresiniz doğrulandı. Artık yorum yazabilir ve hesabınızı kullanabilirsiniz.',
    ];
}

/** Parola sıfırlama bağlantısı ister. Varlık sızdırmaz: yanıt hep aynıdır. */
function member_request_reset($email) {
    if (!rate_limit('memberreset:' . client_ip(), 5, 900)) {
        return member_error('Çok fazla istek gönderildi. Lütfen 15 dakika sonra tekrar deneyin.', 429);
    }
    $email = mb_strtolower(trim((string)$email), 'UTF-8');
    $genel = 'Bu e-posta adresi kayıtlıysa parola sıfırlama bağlantısı gönderildi.';

    // Hesap başına kova (denetim tur 2, B06/B09): tek kova IP başınaydı, bu yüzden
    // dağıtık IP havuzuyla aynı adrese sınırsız sıfırlama postası yollanabiliyordu.
    // Kova dolduğunda da GENEL mesaj döner — yanıt hesabın varlığını sızdırmamalı.
    if (!rate_limit('memberreset-acct:' . hash('sha256', $email), 5, 3600)) {
        return ['ok' => true, 'message' => $genel];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return member_error('Geçerli bir e-posta adresi girin.');
    }
    $row = q1('SELECT id, email, active, role FROM users WHERE email = :e AND role = \'member\'', [':e' => $email]);
    $gonderildi = false;
    if ($row && (int)$row['active'] === 1) {
        $t = member_token_create((int)$row['id'], 'reset', 3600);
        if ($t !== '') {
            $mail = member_send_reset_mail((int)$row['id'], $t);
            $gonderildi = !empty($mail['ok']);
        }
    }
    if (!member_mail_available()) {
        return ['ok' => true, 'sent' => false,
                'message' => 'Bu sitede e-posta gönderimi kapalı. Parolanızı sıfırlamak için site yöneticisiyle iletişime geçin.'];
    }
    // GÜVENLİK (denetim tur 2, B01): yanıt hesabın kayıtlı olup olmadığına göre
    // DEĞİŞMEMELİ. Eskiden buradaki `sent` bayrağı tek istekle kesin bir
    // hesap-sayım oracle'ıydı. $gonderildi yalnız kayda yazılır, dışa verilmez.
    if (!$gonderildi) { log_error('member_request_reset: sıfırlama postası gönderilemedi veya hesap yok.'); }
    return ['ok' => true, 'message' => $genel];
}

/** Sıfırlama token'ıyla yeni parola belirler. */
function member_reset_password($token, $newPass) {
    $newPass = (string)$newPass;
    if (mb_strlen($newPass) < 8)   { return member_error('Yeni parola en az 8 karakter olmalı.'); }
    if (mb_strlen($newPass) > 200) { return member_error('Parola en fazla 200 karakter olabilir.'); }

    $row = member_token_consume($token, 'reset');
    if (!$row) {
        return member_error('Sıfırlama bağlantısı geçersiz ya da süresi dolmuş. Yeni bağlantı isteyin.');
    }
    $uid = (int)$row['user_id'];
    $user = q1('SELECT id FROM users WHERE id = :i AND role = \'member\'', [':i' => $uid]);
    if (!$user) { return member_error('Hesap bulunamadı.'); }

    try {
        q('UPDATE users SET pass_hash = :h WHERE id = :i',
            [':h' => password_hash($newPass, PASSWORD_DEFAULT), ':i' => $uid]);
        // Açık kalan diğer sıfırlama bağlantıları geçersizleşir
        q('DELETE FROM member_tokens WHERE user_id = :u AND kind = \'reset\' AND (used_at IS NULL OR used_at = \'\')',
            [':u' => $uid]);
    } catch (Throwable $e) {
        log_error('member_reset_password: ' . $e->getMessage());
        return member_error('Parola güncellenemedi.', 500);
    }
    // Bağlantıya erişmiş olmak e-posta sahipliğini kanıtlar; hesap doğrulanmış sayılır.
    member_mark_verified($uid);

    return ['ok' => true, 'user_id' => $uid, 'message' => 'Parolanız güncellendi. Şimdi giriş yapabilirsiniz.'];
}

// ============================================================ profil

/** Görünen ad ve kısa özgeçmiş güncellemesi (avatar yüklemesi kapsam dışı). */
function member_update_profile($userId, array $in) {
    $userId = (int)$userId;
    $user = q1('SELECT id FROM users WHERE id = :i AND role = \'member\'', [':i' => $userId]);
    if (!$user) { return member_error('Hesap bulunamadı.', 404); }

    $ad = sanitize_line((string)arr($in, 'display_name', ''), 120);
    if (mb_strlen($ad) < 2)  { return member_error('Görünen ad en az 2 karakter olmalı.'); }
    if (mb_strlen($ad) > 80) { $ad = mb_substr($ad, 0, 80); }
    $bio = sanitize_plain((string)arr($in, 'bio', ''), 1000);

    // `name` de eşitlenir: yorum formu ve yazar adı bu sütunu okur.
    $data = ['name' => $ad];
    if (member_users_has_column('display_name')) { $data['display_name'] = $ad; }
    if (member_users_has_column('bio'))          { $data['bio'] = $bio; }

    try { db_update('users', $data, 'id = :id', [':id' => $userId]); }
    catch (Throwable $e) {
        log_error('member_update_profile: ' . $e->getMessage());
        return member_error('Profil kaydedilemedi.', 500);
    }
    return ['ok' => true, 'display_name' => $ad, 'bio' => $bio, 'message' => 'Profiliniz güncellendi.'];
}

/** Parola değiştirme (eski parola zorunlu). */
function member_change_password($userId, $old, $new) {
    $userId = (int)$userId;
    $row = q1('SELECT id, pass_hash FROM users WHERE id = :i AND role = \'member\'', [':i' => $userId]);
    if (!$row) { return member_error('Hesap bulunamadı.', 404); }
    if (!password_verify((string)$old, (string)$row['pass_hash'])) {
        return member_error('Mevcut parolanız hatalı.', 403);
    }
    $oturumdaki = function_exists('current_user') ? current_user() : null;
    $new = (string)$new;
    if (mb_strlen($new) < 8)   { return member_error('Yeni parola en az 8 karakter olmalı.'); }
    if (mb_strlen($new) > 200) { return member_error('Parola en fazla 200 karakter olabilir.'); }

    $yeniHash = password_hash($new, PASSWORD_DEFAULT);
    try {
        q('UPDATE users SET pass_hash = :h WHERE id = :i', [':h' => $yeniHash, ':i' => $userId]);
    } catch (Throwable $e) {
        log_error('member_change_password: ' . $e->getMessage());
        return member_error('Parola güncellenemedi.', 500);
    }

    // Parola damgası değişti: BAŞKA cihazlardaki oturumlar bu andan sonra
    // geçersiz (denetim tur 2, B04). Kullanıcının KENDİ oturumu yeni damgayla
    // tazelenir ki parolasını değiştirdiği için kapı dışarı edilmesin.
    if ($oturumdaki && (int)$oturumdaki['id'] === (int)$userId) {
        session_boot();
        session_regenerate_id(true);
        session_bind_user((int)$userId, $yeniHash);
    }

    return ['ok' => true, 'message' => 'Parolanız güncellendi. Diğer cihazlardaki oturumlarınız kapatıldı.',
            'csrf' => isset($_SESSION['csrf']) ? $_SESSION['csrf'] : ''];
}

// ============================================================ abonelik kademesi

/**
 * Üyenin abonelik durumu.
 * `tier` ETKİN kademedir: süresi dolmuş premium okuma anında 'free' döner
 * (cron gerekmez — USER_TYPES_PLAN §6).
 */
function member_subscription($userId) {
    $userId = (int)$userId;
    $bos = ['tier' => 'free', 'valid_until' => '', 'active' => false,
            'stored_tier' => 'free', 'expired' => false, 'note' => ''];
    if ($userId <= 0 || !member_tables_ready()) { return $bos; }

    try {
        $row = q1('SELECT tier, valid_until, note FROM memberships WHERE user_id = :u', [':u' => $userId]);
    } catch (Throwable $e) { return $bos; }
    if (!$row) { return $bos; }

    $stored = (string)arr($row, 'tier', 'free');
    $until  = trim((string)arr($row, 'valid_until', ''));
    $expired = ($stored === 'premium' && $until !== '' && strtotime($until) < time());
    $tier = ($stored === 'premium' && !$expired) ? 'premium' : 'free';

    return [
        'tier'        => $tier,
        'valid_until' => $until,
        'active'      => ($tier === 'premium'),
        'stored_tier' => $stored,
        'expired'     => $expired,
        'note'        => (string)arr($row, 'note', ''),
    ];
}

/**
 * Abonelik kademesi ver / kaldır.
 * Hedef sorgusu `AND role = 'member'` ile sınırlanır (§8 risk #7).
 */
function member_set_subscription($userId, $tier, $validUntil, $note = '') {
    $userId = (int)$userId;
    $tier = (string)$tier;
    if (!in_array($tier, ['free', 'premium'], true)) { return member_error('Geçersiz üyelik kademesi.'); }
    if (!member_tables_ready()) { return member_error('Üyelik tabloları kurulmadı.', 503); }

    $hedef = q1('SELECT id FROM users WHERE id = :i AND role = \'member\'', [':i' => $userId]);
    if (!$hedef) { return member_error('Üye bulunamadı.', 404); }

    $until = null;
    $raw = trim((string)$validUntil);
    if ($raw !== '') {
        $ts = strtotime(str_replace('T', ' ', $raw));
        if ($ts === false) { return member_error('Bitiş tarihi okunamadı.'); }
        // Yalnız gün verildiyse günün sonuna çekilir
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) { $ts = strtotime(date('Y-m-d', $ts) . ' 23:59:59'); }
        $until = date('Y-m-d H:i:s', $ts);
    }
    $note = sanitize_line((string)$note, 190);

    try {
        $var = qv('SELECT id FROM memberships WHERE user_id = :u', [':u' => $userId]);
        if ($var) {
            db_update('memberships', [
                'tier' => $tier, 'valid_until' => $until, 'note' => $note, 'updated_at' => now(),
            ], 'user_id = :u', [':u' => $userId]);
        } else {
            db_insert('memberships', [
                'user_id' => $userId, 'tier' => $tier, 'valid_until' => $until,
                'note' => $note, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    } catch (Throwable $e) {
        log_error('member_set_subscription: ' . $e->getMessage());
        return member_error('Üyelik kaydedilemedi.', 500);
    }

    return ['ok' => true, 'tier' => $tier, 'valid_until' => (string)$until,
            'message' => $tier === 'premium' ? 'Abonelik tanımlandı.' : 'Abonelik kaldırıldı.'];
}

// ============================================================ kaydedilen haberler

/** Haberi kaydedilenlere ekler / listeden çıkarır. */
function member_bookmark_toggle($userId, $postId) {
    $userId = (int)$userId;
    $postId = (int)$postId;
    if ($userId <= 0 || $postId <= 0) { return member_error('Geçersiz istek.'); }
    if (!member_tables_ready()) { return member_error('Üyelik tabloları kurulmadı.', 503); }

    $post = q1('SELECT id, title FROM posts WHERE id = :i', [':i' => $postId]);
    if (!$post) { return member_error('Haber bulunamadı.', 404); }

    try {
        $var = qv('SELECT id FROM bookmarks WHERE user_id = :u AND post_id = :p',
            [':u' => $userId, ':p' => $postId]);
        if ($var) {
            q('DELETE FROM bookmarks WHERE id = :i', [':i' => (int)$var]);
            return ['ok' => true, 'saved' => false, 'message' => 'Haber kaydedilenlerden çıkarıldı.'];
        }
        db_insert('bookmarks', ['user_id' => $userId, 'post_id' => $postId, 'created_at' => now()]);
    } catch (Throwable $e) {
        log_error('member_bookmark_toggle: ' . $e->getMessage());
        return member_error('İşlem tamamlanamadı.', 500);
    }
    return ['ok' => true, 'saved' => true, 'message' => 'Haber kaydedildi.'];
}

/** Üyenin kaydettiği yayındaki haberler. */
function member_bookmarks($userId, $limit = 20, $offset = 0) {
    $userId = (int)$userId;
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    if ($userId <= 0 || !member_tables_ready()) { return []; }
    try {
        return qa('SELECT p.id, p.title, p.slug, p.spot, p.image, p.visibility, p.published_at,
                          c.name AS category_name, c.slug AS category_slug, b.created_at AS saved_at
            FROM bookmarks b
            JOIN posts p ON p.id = b.post_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE b.user_id = :u
              AND p.status = \'published\' AND (p.published_at IS NULL OR p.published_at <= :nowts)
            ORDER BY b.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [':u' => $userId, ':nowts' => now()]);
    } catch (Throwable $e) { return []; }
}

/** Üyenin yorumları (durumuyla birlikte). */
function member_comments($userId, $limit = 20, $offset = 0) {
    $userId = (int)$userId;
    $limit = max(1, min(100, (int)$limit));
    $offset = max(0, (int)$offset);
    if ($userId <= 0) { return []; }
    try {
        return qa('SELECT k.id, k.post_id, k.body, k.status, k.created_at,
                          p.title AS post_title, p.slug AS post_slug
            FROM comments k LEFT JOIN posts p ON p.id = k.post_id
            WHERE k.user_id = :u ORDER BY k.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
            [':u' => $userId]);
    } catch (Throwable $e) { return []; }
}

// ============================================================ panel listeleri

/**
 * member_list/member_count için ortak WHERE üretici.
 * TÜM sorgular `u.role = 'member'` sabitiyle başlar (§8 risk #7).
 */
function member_filter_sql(array $filters) {
    $where = "u.role = 'member'";
    $p = [];

    $q = trim((string)arr($filters, 'q', ''));
    if ($q !== '') {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        $where .= ' AND (u.email LIKE :q OR u.name LIKE :q)';
        $p[':q'] = $like;
    }

    $premium = "(m.tier = 'premium' AND (m.valid_until IS NULL OR m.valid_until = '' OR m.valid_until >= :nowm))";
    $tier = (string)arr($filters, 'tier', '');
    if ($tier === 'premium')  { $where .= ' AND ' . $premium; $p[':nowm'] = now(); }
    elseif ($tier === 'free') { $where .= ' AND NOT ' . $premium; $p[':nowm'] = now(); }

    $dogrulanmis = 'EXISTS (SELECT 1 FROM member_tokens t WHERE t.user_id = u.id
                    AND t.kind = \'verify\' AND t.used_at IS NOT NULL AND t.used_at <> \'\')';
    $status = (string)arr($filters, 'status', '');
    if ($status === 'unverified')    { $where .= ' AND u.active = 1 AND NOT ' . $dogrulanmis; }
    elseif ($status === 'verified')  { $where .= ' AND ' . $dogrulanmis; }
    elseif ($status === 'suspended') { $where .= ' AND u.active = 0'; }

    return [$where, $p];
}

/** Panel üye listesi. */
function member_list(array $filters = [], $limit = 30, $offset = 0) {
    if (!member_tables_ready()) { return []; }
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    list($where, $p) = member_filter_sql($filters);
    $p[':nowt'] = now();
    try {
        return qa('SELECT u.id, u.name, u.email, u.active, u.created_at,
                m.tier AS stored_tier, m.valid_until, m.note,
                (SELECT COUNT(*) FROM comments c WHERE c.user_id = u.id) AS comment_count,
                (SELECT COUNT(*) FROM bookmarks b WHERE b.user_id = u.id) AS bookmark_count,
                (SELECT COUNT(*) FROM member_tokens t WHERE t.user_id = u.id AND t.kind = \'verify\'
                    AND t.used_at IS NOT NULL AND t.used_at <> \'\') AS verified_count,
                (CASE WHEN m.tier = \'premium\' AND (m.valid_until IS NULL OR m.valid_until = \'\'
                    OR m.valid_until >= :nowt) THEN 1 ELSE 0 END) AS premium_active
            FROM users u LEFT JOIN memberships m ON m.user_id = u.id
            WHERE ' . $where . '
            ORDER BY u.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
    } catch (Throwable $e) {
        log_error('member_list: ' . $e->getMessage());
        return [];
    }
}

/** Süzgece uyan üye sayısı. */
function member_count(array $filters = []) {
    if (!member_tables_ready()) { return 0; }
    list($where, $p) = member_filter_sql($filters);
    try {
        return (int)qv('SELECT COUNT(*) FROM users u LEFT JOIN memberships m ON m.user_id = u.id
                        WHERE ' . $where, $p, 0);
    } catch (Throwable $e) { return 0; }
}

/**
 * E-postası doğrulanmamış üyeler — `mail()` çalışmayan kurulumlarda panelden
 * elle onaylanır (USER_TYPES_PLAN §6, kayıt akışı 3. madde).
 */
function member_pending_verifications($limit = 50) {
    return member_list(['status' => 'unverified'], max(1, min(200, (int)$limit)), 0);
}

// ============================================================ e-posta

/**
 * İşlemsel e-posta gönderilebilir mi?
 * CONTRACTS §13 toplu bülten GÖNDERİMİNİ kapsam dışı bırakır; buradaki
 * gönderim tekil ve işlemseldir (doğrulama / parola sıfırlama).
 */
function member_mail_available() {
    if (setting('member_mail_enabled', '1') !== '1') { return false; }
    if (!function_exists('mail')) { return false; }
    $kapali = array_map('trim', explode(',', (string)@ini_get('disable_functions')));
    if (in_array('mail', $kapali, true)) { return false; }
    return true;
}

/** Gönderici adresi (ayarlarda yoksa alan adından türetilir). */
function member_mail_from() {
    $from = trim((string)setting('site_email', ''));
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) { return $from; }
    $host = (string)parse_url(base_url(), PHP_URL_HOST);
    if ($host === '') { $host = 'localhost'; }
    return 'noreply@' . preg_replace('/^www\./i', '', $host);
}

/** Tek işlemsel e-posta gönderir (UTF-8 başlıklı düz metin). */
function member_mail_send($to, $subject, $body) {
    if (!member_mail_available()) { return ['ok' => false, 'error' => 'E-posta gönderimi kapalı.']; }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'Geçersiz alıcı.']; }

    // Başlık enjeksiyonuna karşı: satır sonları temizlenir
    $subject = str_replace(["\r", "\n"], ' ', (string)$subject);
    $from = member_mail_send_clean(member_mail_from());
    $headers = 'From: ' . $from . "\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";
    $konu = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $ok = false;
    try { $ok = @mail($to, $konu, (string)$body, $headers); }
    catch (Throwable $e) { log_error('member_mail_send: ' . $e->getMessage()); $ok = false; }

    if (!$ok) { return ['ok' => false, 'error' => 'E-posta gönderilemedi.']; }
    return ['ok' => true];
}

/** Başlık alanına yazılacak adresi temizler. */
function member_mail_send_clean($v) { return str_replace(["\r", "\n"], '', (string)$v); }

/** Doğrulama e-postası. */
function member_send_verify_mail($userId, $token) {
    $u = q1('SELECT id, name, email FROM users WHERE id = :i', [':i' => (int)$userId]);
    if (!$u || (string)$token === '') { return ['ok' => false, 'error' => 'Gönderim yapılamadı.']; }

    $site = setting('site_title', 'Manşet');
    $link = member_url('dogrula', ['token' => $token]);
    $body = "Merhaba,\n\n"
          . $site . " sitesinde üyelik kaydınızı tamamlamak için aşağıdaki bağlantıya tıklayın:\n\n"
          . $link . "\n\n"
          . "Bağlantı 48 saat geçerlidir ve yalnız bir kez kullanılabilir.\n"
          . "Bu kaydı siz yapmadıysanız bu e-postayı yok sayabilirsiniz.\n\n"
          . $site . "\n";
    return member_mail_send((string)$u['email'], $site . ' — e-posta doğrulama', $body);
}

/** Parola sıfırlama e-postası. */
function member_send_reset_mail($userId, $token) {
    $u = q1('SELECT id, name, email FROM users WHERE id = :i', [':i' => (int)$userId]);
    if (!$u || (string)$token === '') { return ['ok' => false, 'error' => 'Gönderim yapılamadı.']; }

    $site = setting('site_title', 'Manşet');
    $link = member_url('parola', ['token' => $token]);
    $body = "Merhaba,\n\n"
          . $site . " hesabınız için parola sıfırlama isteği alındı. Yeni parolanızı belirlemek için:\n\n"
          . $link . "\n\n"
          . "Bağlantı 1 saat geçerlidir ve yalnız bir kez kullanılabilir.\n"
          . "İsteği siz yapmadıysanız bu e-postayı yok sayın; parolanız değişmez.\n\n"
          . $site . "\n";
    return member_mail_send((string)$u['email'], $site . ' — parola sıfırlama', $body);
}
