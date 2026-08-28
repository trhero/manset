<?php
/**
 * Manşet — bülten abone listesi (Ajan-F, 1.2-11).
 *
 * KAPSAM: abone toplama, ÇİFT ONAY (double opt-in), abonelikten çıkma,
 * kategori ilgisi segmenti, Mailchimp/Sendy uyumlu CSV dışa aktarma.
 * KAPSAM DIŞI (CONTRACTS §13): toplu bülten GÖNDERİMİ. Buradaki tek e-posta
 * gönderimi, kaydolan kişinin kendi adresine giden TEKİL onay iletisidir —
 * üyelik doğrulama e-postasıyla (inc/members.php) aynı sınıf.
 *
 * ---------------------------------------------------------------------------
 * ÇÖZÜLEN SOMUT HATA
 * Göç 003 `newsletter_subscribers.confirmed` sütununu açtı; `newsletter.subscribe`
 * ucu (inc/api/kunye_api.php:393) her kaydı `confirmed = 0` yazıyordu ve sütunu
 * 1 yapan TEK BİR SATIR bile yoktu. Yani panelde "onaylı" sütunu her zaman
 * "hayır" gösteriyordu ve liste izinsiz/yanlış adreslerle doluyordu.
 *
 * ---------------------------------------------------------------------------
 * TOKEN DESENİ (projede zaten var: inc/members.php member_token_create)
 * Ham token YALNIZ e-postaya yazılır; veritabanında `hash('sha256', $token)`
 * durur. Onay token'ı süreli ve tek kullanımlık, çıkış token'ı süresizdir
 * (yıllar sonra gelen bültende de çalışması gerekir).
 *
 * ÇEREZ KURALI (CONTRACTS §3.1)
 * Onay/çıkış bağlantıları giriş yapmamış okur tarafından açılır. Bu dosyadaki
 * hiçbir işlev session_boot() ÇAĞIRMAZ; `bulten.php` de çağırmaz.
 *
 * KVKK
 * Abone e-postası kişisel veridir: liste ve dışa aktarma `newsletter.manage`
 * ister, dışa aktarma `audit_log`'a yazılır, çıkış bağlantısı kaydı SİLER
 * (pasifleştirmez).
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// Bu modül komut satırından da yüklenir (cron, bakım betiği): kullandığı
// yardımcılar bootstrap'ta değil, aşağıdaki dosyalardadır.
if (is_file(INC_DIR . '/sanitize.php')) { require_once INC_DIR . '/sanitize.php'; }
if (is_file(INC_DIR . '/schema.php'))   { require_once INC_DIR . '/schema.php'; }

// ============================================================ durum / şema

/** Bülten tablosu ve göç 020 sütunları hazır mı? */
function newsletter_ready() {
    static $hazir = null;
    if ($hazir !== null) { return $hazir; }
    $hazir = false;
    // Şema yardımcıları bootstrap'ta değil, inc/schema.php'de tanımlıdır; komut
    // satırından (cron, bakım betiği) yüklenmemiş olabilir.
    if (!function_exists('schema_has_table') && is_file(INC_DIR . '/schema.php')) {
        require_once INC_DIR . '/schema.php';
    }
    if (function_exists('schema_has_table') && schema_has_table('newsletter_subscribers')) {
        $hazir = schema_has_column('newsletter_subscribers', 'unsub_hash')
              && schema_has_column('newsletter_subscribers', 'confirm_hash');
    }
    return $hazir;
}

/** Onay bağlantısı kaç saat geçerli? */
function newsletter_confirm_ttl_hours() {
    $h = (int)setting('newsletter_confirm_hours', '72');
    return max(1, min(720, $h));
}

/** Onaylanmamış kayıt kaç gün sonra silinir? (0 = hiç) */
function newsletter_unconfirmed_days() {
    $d = (int)setting('newsletter_unconfirmed_days', '30');
    return max(0, min(3650, $d));
}

// ============================================================ token

/** Ham token üretir (yalnız e-postaya yazılır). */
function newsletter_token_new() { return bin2hex(random_bytes(32)); }

/** Ham token → veritabanında saklanan özet. */
function newsletter_token_hash($token) { return hash('sha256', (string)$token); }

/** Ham token biçimsel olarak geçerli mi? (DB'ye gitmeden eleme) */
function newsletter_token_valid($token) {
    return (bool)preg_match('/^[a-f0-9]{32,128}$/i', (string)$token);
}

/** Onay bağlantısı. bulten.php kökte durur; SEF açık olmasa da çalışır. */
function newsletter_confirm_url($rawToken) {
    return base_url() . '/bulten.php?onay=' . rawurlencode((string)$rawToken);
}

/** Abonelikten çıkma bağlantısı. */
function newsletter_unsub_url($rawToken) {
    return base_url() . '/bulten.php?cikis=' . rawurlencode((string)$rawToken);
}

// ============================================================ e-posta

/** E-posta adresini normalleştirir (küçük harf + kırpma). */
function newsletter_normalize_email($email) {
    return mb_strtolower(trim((string)$email), 'UTF-8');
}

/** Adres geçerli mi? */
function newsletter_email_valid($email) {
    $e = (string)$email;
    return $e !== '' && mb_strlen($e) <= 120 && (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
}

/**
 * Tekil işlemsel e-posta gönderir.
 * Üyelik modülü yüklüyse onun sarmalayıcısını kullanır (tek gönderim yolu,
 * tek başlık enjeksiyonu kalkanı); değilse aynı kurallarla yerel yedeği.
 */
function newsletter_mail_send($to, $subject, $body) {
    if (function_exists('member_mail_send')) { return member_mail_send($to, $subject, $body); }

    // Site genelindeki işlemsel e-posta anahtarı (inc/members.php ile aynı ayar):
    // kapalıysa hiçbir gönderim denenmez.
    if (setting('member_mail_enabled', '1') !== '1') { return ['ok' => false, 'error' => 'E-posta gönderimi kapalı.']; }
    if (!function_exists('mail')) { return ['ok' => false, 'error' => 'E-posta gönderimi kapalı.']; }
    $kapali = array_map('trim', explode(',', (string)@ini_get('disable_functions')));
    if (in_array('mail', $kapali, true)) { return ['ok' => false, 'error' => 'E-posta gönderimi kapalı.']; }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'Geçersiz alıcı.']; }

    $from = trim((string)setting('site_email', ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $host = (string)parse_url(base_url(), PHP_URL_HOST);
        $from = 'noreply@' . preg_replace('/^www\./i', '', $host !== '' ? $host : 'localhost');
    }
    $from = str_replace(["\r", "\n"], '', $from);
    $subject = str_replace(["\r", "\n"], ' ', (string)$subject);
    $headers = 'From: ' . $from . "\r\n" . 'Reply-To: ' . $from . "\r\n"
             . "MIME-Version: 1.0\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";
    $ok = false;
    try { $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', (string)$body, $headers); }
    catch (Throwable $e) { log_error('newsletter_mail_send: ' . $e->getMessage()); $ok = false; }
    return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'E-posta gönderilemedi.'];
}

/** Onay e-postasının gövdesini üretir (testten de çağrılabilsin diye ayrı). */
function newsletter_confirm_mail_body($confirmToken, $unsubToken) {
    $site = setting('site_title', 'Manşet');
    return "Merhaba,\n\n"
         . $site . " bültenine kayıt isteği aldık. Kaydı TAMAMLAMAK için aşağıdaki\n"
         . "bağlantıya tıklayın:\n\n"
         . newsletter_confirm_url($confirmToken) . "\n\n"
         . "Bağlantı " . newsletter_confirm_ttl_hours() . " saat geçerlidir.\n"
         . "Bu isteği siz yapmadıysanız hiçbir şey yapmanıza gerek yok; onaylanmayan\n"
         . "kayıtlar kendiliğinden silinir. Dilerseniz adresinizi hemen listeden\n"
         . "çıkarabilirsiniz:\n\n"
         . newsletter_unsub_url($unsubToken) . "\n\n"
         . $site . "\n";
}

/** Onay e-postasını gönderir. */
function newsletter_send_confirm_mail($email, $confirmToken, $unsubToken) {
    $site = setting('site_title', 'Manşet');
    return newsletter_mail_send($email, $site . ' — bülten kaydınızı onaylayın',
        newsletter_confirm_mail_body($confirmToken, $unsubToken));
}

// ============================================================ kategori segmenti

/**
 * Kategori kimliklerini ',3,7,' biçimine çevirir.
 *
 * NEDEN BU BİÇİM: SQL'de `categories LIKE '%,3,%'` tek bir hazır ifadeyle
 * çalışır ve hem SQLite hem MySQL'de aynı davranır (FIND_IN_SET yalnız MySQL).
 * Var olmayan kimlik atılır — segment listesi kategori tablosuyla tutarlı kalır.
 */
function newsletter_categories_clean($ids) {
    if (is_string($ids)) { $ids = preg_split('/[^0-9]+/', $ids); }
    if (!is_array($ids)) { return ''; }
    $gecerli = [];
    foreach (qa('SELECT id FROM categories') as $c) { $gecerli[(int)$c['id']] = true; }
    $out = [];
    foreach ($ids as $i) {
        $i = (int)$i;
        if ($i > 0 && isset($gecerli[$i]) && !in_array($i, $out, true)) { $out[] = $i; }
        if (count($out) >= 30) { break; }
    }
    if (!$out) { return ''; }
    sort($out);
    return ',' . implode(',', $out) . ',';
}

/** ',3,7,' → [3, 7] */
function newsletter_categories_list($stored) {
    $out = [];
    foreach (preg_split('/[^0-9]+/', (string)$stored) as $p) {
        if ($p !== '' && (int)$p > 0) { $out[] = (int)$p; }
    }
    return $out;
}

/** Kategori kimliklerini okunur adlara çevirir. */
function newsletter_categories_labels($stored) {
    $ids = newsletter_categories_list($stored);
    if (!$ids) { return []; }
    $out = [];
    foreach (qa('SELECT id, name FROM categories') as $c) {
        if (in_array((int)$c['id'], $ids, true)) { $out[(int)$c['id']] = (string)$c['name']; }
    }
    return $out;
}

// ============================================================ kayıt

/**
 * Bülten kaydı — çift onay akışının 1. adımı.
 *
 * @param array $in ['email'=>…, 'categories'=>array|string, 'source'=>string]
 * @return array ['ok'=>bool,'error'=>string,'code'=>int,'message'=>string,
 *                'mailed'=>bool,'state'=>'new'|'resent'|'already','token'=>string]
 *
 * 'token' (onay) ve 'unsub' (çıkış) ham token'ları YALNIZ çağıran koda döner —
 * e-postayı kuran katman ve ölçüm betikleri için. API katmanı bunları ASLA
 * istemciye vermez; veritabanına da yalnız SHA-256 özetleri yazılır.
 *
 * Varlık sızdırılmaz: zaten kayıtlı/onaylı adres için de aynı Türkçe mesaj döner.
 */
function newsletter_subscribe(array $in) {
    $genel = 'Kaydınız alındı. Onay bağlantısını e-postanıza gönderdik; '
           . 'bağlantıya tıklayana kadar listeye eklenmezsiniz.';
    $hata = function ($m, $c = 400) {
        return ['ok' => false, 'error' => $m, 'code' => $c, 'message' => '',
                'mailed' => false, 'state' => '', 'token' => '', 'unsub' => ''];
    };

    if (!newsletter_ready()) { return $hata('Bülten listesi bu kurulumda hazır değil.', 503); }

    $email = newsletter_normalize_email(arr($in, 'email', ''));
    if (!newsletter_email_valid($email)) { return $hata('Geçerli bir e-posta adresi girin.', 400); }

    $kategoriler = newsletter_categories_clean(arr($in, 'categories', []));
    $kaynak = sanitize_line((string)arr($in, 'source', 'form'), 60);

    $row = q1('SELECT id, confirmed FROM newsletter_subscribers WHERE email = :e', [':e' => $email]);

    // Zaten onaylı: yeni token üretilmez (onaylı bir aboneye onay postası gönderip
    // durmak, adresi bilen birinin abonesi rahatsız etmesine yol açardı).
    if ($row && (int)$row['confirmed'] === 1) {
        if ($kategoriler !== '') {
            q('UPDATE newsletter_subscribers SET categories = :c, updated_at = :n WHERE id = :i',
              [':c' => $kategoriler, ':n' => now(), ':i' => (int)$row['id']]);
        }
        return ['ok' => true, 'error' => '', 'code' => 200, 'message' => $genel,
                'mailed' => false, 'state' => 'already', 'token' => '', 'unsub' => ''];
    }

    $onay = newsletter_token_new();
    $cikis = newsletter_token_new();
    $sonKullanma = date('Y-m-d H:i:s', time() + newsletter_confirm_ttl_hours() * 3600);

    try {
        if ($row) {
            // Bekleyen kayıt: token tazelenir (eski bağlantı geçersizleşir).
            $veri = [
                'confirm_hash'    => newsletter_token_hash($onay),
                'confirm_expires' => $sonKullanma,
                'unsub_hash'      => newsletter_token_hash($cikis),
                'ip'              => client_ip(),
                'updated_at'      => now(),
            ];
            if ($kategoriler !== '') { $veri['categories'] = $kategoriler; }
            db_update('newsletter_subscribers', $veri, 'id = :i', [':i' => (int)$row['id']]);
            $durum = 'resent';
        } else {
            db_insert('newsletter_subscribers', [
                'email'           => $email,
                'ip'              => client_ip(),
                'confirmed'       => 0,
                'confirm_hash'    => newsletter_token_hash($onay),
                'confirm_expires' => $sonKullanma,
                'unsub_hash'      => newsletter_token_hash($cikis),
                'categories'      => $kategoriler,
                'source'          => $kaynak,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
            $durum = 'new';
        }
    } catch (Throwable $e) {
        // UNIQUE çakışması (eşzamanlı istek) — kullanıcıya yine başarı döner
        log_error('newsletter_subscribe: ' . $e->getMessage());
        return ['ok' => true, 'error' => '', 'code' => 200, 'message' => $genel,
                'mailed' => false, 'state' => 'already', 'token' => '', 'unsub' => ''];
    }

    $mail = newsletter_send_confirm_mail($email, $onay, $cikis);
    return ['ok' => true, 'error' => '', 'code' => 200, 'message' => $genel,
            'mailed' => !empty($mail['ok']), 'state' => $durum,
            'token' => $onay, 'unsub' => $cikis];
}

// ============================================================ onay / çıkış

/**
 * Onay bağlantısını işler → `confirmed = 1`.
 * Token tek kullanımlıktır: onaydan sonra `confirm_hash` boşaltılır.
 */
function newsletter_confirm($rawToken) {
    if (!newsletter_ready() || !newsletter_token_valid($rawToken)) {
        return ['ok' => false, 'error' => 'Onay bağlantısı geçersiz.', 'email' => '', 'unsub' => ''];
    }
    $row = q1('SELECT id, email, confirmed, confirm_expires FROM newsletter_subscribers
               WHERE confirm_hash = :h', [':h' => newsletter_token_hash($rawToken)]);
    if (!$row) {
        return ['ok' => false, 'error' => 'Onay bağlantısı geçersiz ya da daha önce kullanılmış.',
                'email' => '', 'unsub' => ''];
    }
    $exp = trim((string)arr($row, 'confirm_expires', ''));
    if ($exp !== '' && strtotime($exp) < time()) {
        return ['ok' => false, 'error' => 'Onay bağlantısının süresi dolmuş. Lütfen yeniden kaydolun.',
                'email' => '', 'unsub' => ''];
    }

    q('UPDATE newsletter_subscribers
       SET confirmed = 1, confirmed_at = :n, confirm_hash = \'\', confirm_expires = NULL, updated_at = :n2
       WHERE id = :i', [':n' => now(), ':n2' => now(), ':i' => (int)$row['id']]);

    return ['ok' => true, 'error' => '', 'email' => (string)$row['email'], 'unsub' => ''];
}

/**
 * Çıkış bağlantısını işler → kayıt SİLİNİR.
 *
 * NEDEN SİLME: KVKK'da rızanın geri alınması verinin işlenmesini durdurur;
 * "pasif" satır tutmak listeyi indiren birinin elinde yine kişisel veridir.
 */
function newsletter_unsubscribe($rawToken) {
    if (!newsletter_ready() || !newsletter_token_valid($rawToken)) {
        return ['ok' => false, 'error' => 'Bağlantı geçersiz.', 'email' => ''];
    }
    $row = q1('SELECT id, email FROM newsletter_subscribers WHERE unsub_hash = :h',
              [':h' => newsletter_token_hash($rawToken)]);
    if (!$row) {
        return ['ok' => false, 'error' => 'Bu bağlantı geçersiz ya da adres zaten listede değil.', 'email' => ''];
    }
    q('DELETE FROM newsletter_subscribers WHERE id = :i', [':i' => (int)$row['id']]);
    return ['ok' => true, 'error' => '', 'email' => (string)$row['email']];
}

/** Çıkış bağlantısını yalnız DOĞRULAR (silmez) — onay ekranı için. */
function newsletter_unsub_peek($rawToken) {
    if (!newsletter_ready() || !newsletter_token_valid($rawToken)) { return null; }
    return q1('SELECT id, email FROM newsletter_subscribers WHERE unsub_hash = :h',
              [':h' => newsletter_token_hash($rawToken)]);
}

// ============================================================ panel verisi

/** Liste sayaçları. */
function newsletter_stats() {
    if (!newsletter_ready()) { return ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'last' => '']; }
    $t = (int)qv('SELECT COUNT(*) FROM newsletter_subscribers', [], 0);
    $c = (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE confirmed = 1', [], 0);
    return [
        'total'     => $t,
        'confirmed' => $c,
        'pending'   => $t - $c,
        'last'      => (string)qv('SELECT created_at FROM newsletter_subscribers ORDER BY id DESC', [], ''),
    ];
}

/**
 * Süzgeçli abone sorgusu.
 * @param array $f ['durum'=>'hepsi|onayli|bekleyen', 'kategori'=>int, 'q'=>string,
 *                  'limit'=>int, 'offset'=>int]
 * @return array ['items'=>[], 'total'=>int]
 */
function newsletter_query(array $f = []) {
    if (!newsletter_ready()) { return ['items' => [], 'total' => 0]; }

    $where = ['1 = 1'];
    $p = [];
    $durum = (string)arr($f, 'durum', 'hepsi');
    if ($durum === 'onayli')   { $where[] = 'confirmed = 1'; }
    if ($durum === 'bekleyen') { $where[] = 'confirmed = 0'; }

    $kat = (int)arr($f, 'kategori', 0);
    if ($kat > 0) {
        $where[] = 'categories LIKE :kat';
        $p[':kat'] = '%,' . $kat . ',%';
    }
    $ara = trim((string)arr($f, 'q', ''));
    if ($ara !== '') {
        $where[] = 'email LIKE :ara';
        $p[':ara'] = '%' . $ara . '%';
    }
    $w = implode(' AND ', $where);

    $total = (int)qv('SELECT COUNT(*) FROM newsletter_subscribers WHERE ' . $w, $p, 0);
    $limit = max(1, min(1000, (int)arr($f, 'limit', 50)));
    $offset = max(0, (int)arr($f, 'offset', 0));
    $items = qa('SELECT id, email, ip, confirmed, confirmed_at, categories, source, created_at
                 FROM newsletter_subscribers WHERE ' . $w . '
                 ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
    return ['items' => $items, 'total' => $total];
}

/** Dışa aktarma için tüm satırlar (süzgeç aynı). */
function newsletter_export_rows(array $f = []) {
    $f['limit'] = 100000;
    $f['offset'] = 0;
    $res = newsletter_query($f);
    return $res['items'];
}

/** Panelden abone silme (KVKK talebi). */
function newsletter_delete($id) {
    $id = (int)$id;
    if ($id <= 0 || !newsletter_ready()) { return false; }
    $row = q1('SELECT email FROM newsletter_subscribers WHERE id = :i', [':i' => $id]);
    if (!$row) { return false; }
    q('DELETE FROM newsletter_subscribers WHERE id = :i', [':i' => $id]);
    return (string)$row['email'];
}

// ============================================================ CSV dışa aktarma

/** Desteklenen dışa aktarma biçimleri. */
function newsletter_export_formats() {
    return [
        'mailchimp' => 'Mailchimp (Email Address, First Name, Last Name, Tags)',
        'sendy'     => 'Sendy (Name, Email)',
        'ham'       => 'Ham liste (tüm sütunlar)',
    ];
}

/**
 * Bir abone satırını seçilen biçimin sütunlarına çevirir.
 * Mailchimp `Tags` alanı virgülle ayrılmış etiket listesi bekler — kategori
 * segmentini oraya yazıyoruz, böylece içe aktarımdan sonra segment kurulabilir.
 */
function newsletter_export_row(array $r, $format) {
    $etiketler = implode(',', array_values(newsletter_categories_labels(arr($r, 'categories', ''))));
    if ($format === 'mailchimp') {
        return [(string)$r['email'], '', '', $etiketler];
    }
    if ($format === 'sendy') {
        // Sendy ad sütununu zorunlu tutar; adımız yok, adresin yerel kısmını yazıyoruz.
        $ad = (string)strstr((string)$r['email'], '@', true);
        return [$ad !== '' ? $ad : (string)$r['email'], (string)$r['email']];
    }
    return [
        (string)$r['email'],
        (int)$r['confirmed'] === 1 ? 'evet' : 'hayır',
        (string)arr($r, 'confirmed_at', ''),
        $etiketler,
        (string)arr($r, 'source', ''),
        (string)arr($r, 'ip', ''),
        (string)arr($r, 'created_at', ''),
    ];
}

/** Biçimin başlık satırı. */
function newsletter_export_header($format) {
    if ($format === 'mailchimp') { return ['Email Address', 'First Name', 'Last Name', 'Tags']; }
    if ($format === 'sendy')     { return ['Name', 'Email']; }
    return ['E-posta', 'Onaylı', 'Onay tarihi', 'Kategoriler', 'Kaynak', 'IP', 'Kayıt tarihi'];
}

/**
 * Ayırıcı: Mailchimp ve Sendy VİRGÜL bekler (yükleyicileri noktalı virgülü
 * tek sütun sanar); ham liste Excel'in Türkçe yerelinde açılsın diye ';'.
 */
function newsletter_export_sep($format) {
    return ($format === 'mailchimp' || $format === 'sendy') ? ',' : ';';
}

/** Dosya adı. */
function newsletter_export_filename($format) {
    return 'bulten-' . $format . '-' . date('Y-m-d') . '.csv';
}

// ============================================================ zamanlı bakım

/**
 * Zamanlı görev: onaylanmamış eski kayıtları ve süresi dolmuş token'ları temizler.
 * Ucuz görevdir (ağa çıkmaz, yalnız iki DELETE/UPDATE).
 */
function newsletter_cron_tick() {
    if (!newsletter_ready()) { return 'bülten tablosu hazır değil'; }
    $silinen = 0;
    $gun = newsletter_unconfirmed_days();
    if ($gun > 0) {
        $sinir = date('Y-m-d H:i:s', time() - $gun * 86400);
        try {
            $st = q('DELETE FROM newsletter_subscribers
                     WHERE confirmed = 0 AND created_at < :s', [':s' => $sinir]);
            $silinen = (int)$st->rowCount();
        } catch (Throwable $e) { log_error('newsletter_cron_tick: ' . $e->getMessage()); }
    }
    // Süresi geçmiş onay token'ı kayıt silinmeden de geçersizleşsin
    try {
        q('UPDATE newsletter_subscribers SET confirm_hash = \'\'
           WHERE confirmed = 0 AND confirm_hash <> \'\' AND confirm_expires IS NOT NULL
             AND confirm_expires < :n', [':n' => now()]);
    } catch (Throwable $e) { log_error('newsletter_cron_tick token: ' . $e->getMessage()); }

    return $silinen . ' onaylanmamış kayıt silindi';
}

cron_register('bulten', 'newsletter_cron_tick', true);
