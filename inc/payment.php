<?php
/**
 * Manşet — ödeme sağlayıcı adaptörü (1.2-04).
 *
 * DESEN: inc/ai.php ile birebir aynı. Sağlayıcı seçimi tek bir ayar
 * (`payment_provider`), her sağlayıcı için ince bir uyarlayıcı, ortak bir
 * sonuç biçimi ve HTTP çağrısını SSRF kalkanından geçiren tek bir yardımcı.
 *
 * DESTEKLENEN SAĞLAYICILAR
 *   'manual_iban'  Havale/EFT. Okur dekont yükler, yönetici panelden onaylar.
 *                  Dış bağımlılık YOK; her yayıncı kullanabilir.
 *   'paytr'        PayTR — BARINDIRILAN ödeme sayfası (iFrame token) + callback.
 *
 * NEDEN PayTR (iyzico değil): iyzico'nun ödeme formu akışı resmî SDK üzerinden
 * kurgulanmıştır; Composer bu projede YOK (CONTRACTS §0). PayTR'ın akışı ise
 * saf HTTP'dir: form kodlu tek bir istek → token, tokenla barındırılan sayfa,
 * geri bildirim POST'unda TEK ve deterministik bir HMAC-SHA256 imzası.
 * Elle yazılabilir ve elle DOĞRULANABİLİR olması belirleyici oldu.
 *
 * KART VERİSİ BU SİSTEME GİRMEZ. Kart alanları yalnız sağlayıcının kendi
 * barındırdığı sayfada doldurulur; Manşet ne görür, ne saklar, ne iletir.
 * PCI-DSS kapsamına girilmez.
 *
 * GÜVENLİK ÖZETİ (ayrıntı: gelistirme/1.2-ajan-c.md)
 *   - Webhook imzası `hash_equals` ile sabit zamanlı doğrulanır. İmzası
 *     tutmayan istek HİÇBİR KAYIT YAZMAZ ve hiçbir aboneliğe dokunmaz.
 *   - Çift işlem: `payment_events.event_key` BENZERSİZ indeks. Aynı olay
 *     ikinci kez geldiğinde INSERT veritabanı düzeyinde reddedilir.
 *   - Yarış: `UPDATE … WHERE id = :i AND status = 'pending'` atomik
 *     karşılaştır-ve-değiştir. `rowCount() === 1` olan tek istek uzatma yapar.
 *   - Tutar/para birimi: webhook'un söylediği tutar KENDİ kaydımızla tam
 *     eşitlikle karşılaştırılır (kuruş, tam sayı). Tutmazsa uzatma yok.
 *   - Sağlayıcı anahtarları `secret` tipiyle saklanır; ekrana basılmaz,
 *     günlüğe yazılmaz (`payment_secret_masked()`).
 *   - Dış istek `safe_remote_target()` + `curl_pin_resolved_ip()` üzerinden.
 *
 * TEST MODU: `MANSET_PAYMENT_FIXTURE` ortam değişkeni ya da
 * `payment_test_mode` ayarı '1' ise sağlayıcıya HTTP çağrısı YAPILMAZ.
 * DİKKAT: test modu yalnız DIŞA GİDEN çağrıyı atlar; gelen webhook'un
 * imza doğrulaması aynen çalışır. Sahte sağlayıcı da gerçek HMAC üretir.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';
if (is_file(__DIR__ . '/members.php')) { require_once __DIR__ . '/members.php'; }

/** Bekleyen bir ödemenin ölü sayılacağı süre (gün). */
if (!defined('PAYMENT_PENDING_TTL_DAYS')) { define('PAYMENT_PENDING_TTL_DAYS', 14); }
/** Dekont dosyası üst sınırı (bayt). */
if (!defined('PAYMENT_RECEIPT_MAX_BYTES')) { define('PAYMENT_RECEIPT_MAX_BYTES', 4 * 1024 * 1024); }

// ============================================================ yapılandırma

/** Tanımlı sağlayıcılar: anahtar => insan okunur ad. */
function payment_providers() {
    return [
        'manual_iban' => 'Havale / EFT (dekont onayı)',
        'paytr'       => 'PayTR (barındırılan ödeme sayfası)',
    ];
}

/** Etkin sağlayıcı. Bilinmeyen değer güvenli varsayılana düşer. */
function payment_provider() {
    $p = (string)setting('payment_provider', 'manual_iban');
    return array_key_exists($p, payment_providers()) ? $p : 'manual_iban';
}

/** Ödeme modülü açık mı? */
function payment_enabled() { return setting('payment_enabled', '0') === '1'; }

/** Test (sahte sağlayıcı) modu — dışa giden çağrı yapılmaz. */
function payment_test_mode() {
    if (getenv('MANSET_PAYMENT_FIXTURE')) { return true; }
    return setting('payment_test_mode', '0') === '1';
}

/** Havale/EFT yolu okura açık mı? */
function payment_manual_enabled() {
    if (!payment_enabled()) { return false; }
    if (payment_provider() === 'manual_iban') { return true; }
    return setting('payment_manual_enabled', '0') === '1';
}

/** PayTR üye işyeri kimliği (gizli değil, panelde görünür). */
function payment_paytr_merchant_id() { return trim((string)setting('payment_paytr_merchant_id', '')); }
/** PayTR merchant_key — GİZLİ. */
function payment_paytr_key() { return trim((string)setting('payment_paytr_merchant_key', '')); }
/** PayTR merchant_salt — GİZLİ. */
function payment_paytr_salt() { return trim((string)setting('payment_paytr_merchant_salt', '')); }
/** PayTR test (sandbox) bayrağı — sağlayıcıya gönderilir. */
function payment_paytr_sandbox() { return setting('payment_paytr_sandbox', '1') === '1' ? 1 : 0; }

/** PayTR taban adresi (SSRF kalkanından geçer). */
function payment_paytr_base() {
    $u = trim((string)setting('payment_paytr_base_url', ''));
    return $u !== '' ? rtrim($u, '/') : 'https://www.paytr.com';
}

/** Dış istek zaman aşımı (sn). */
function payment_timeout() { return max(5, min(60, (int)setting('payment_timeout', '20'))); }

/**
 * Gizli değerin panelde gösterilebilir maskesi.
 * HAM DEĞER HİÇBİR YERDE EKRANA BASILMAZ (ai_api_key_masked() deseni).
 */
function payment_secret_masked($raw) {
    $k = (string)$raw;
    if ($k === '') { return ''; }
    $n = mb_strlen($k);
    if ($n <= 8) { return str_repeat('•', $n); }
    return mb_substr($k, 0, 3) . str_repeat('•', max(4, min(20, $n - 6))) . mb_substr($k, -3);
}

/** Etkin sağlayıcı yapılandırılmış mı? */
function payment_configured() {
    if (!payment_enabled()) { return false; }
    if (payment_provider() === 'manual_iban') { return payment_iban() !== ''; }
    if (payment_provider() === 'paytr') {
        return payment_paytr_merchant_id() !== '' && payment_paytr_key() !== '' && payment_paytr_salt() !== '';
    }
    return false;
}

/** Panelde gösterilecek durum metni (ai_status_text() karşılığı). */
function payment_status_text() {
    if (!payment_enabled()) { return 'kapalı'; }
    if (payment_test_mode()) { return 'test modu'; }
    if (!payment_configured()) { return 'yapılandırılmadı'; }
    return 'hazır';
}

/** Havale bilgileri. */
function payment_iban()      { return sanitize_line((string)setting('payment_iban', ''), 40); }
function payment_iban_name() { return sanitize_line((string)setting('payment_iban_name', ''), 120); }
function payment_bank_name() { return sanitize_line((string)setting('payment_bank', ''), 120); }

/** Tablolar hazır mı? (göç 017 uygulanmadan sorgu atmayalım.) */
function payment_tables_ready() {
    static $hazir = null;
    if ($hazir !== null) { return $hazir; }
    try {
        qv('SELECT COUNT(*) FROM plans', [], 0);
        qv('SELECT COUNT(*) FROM payments', [], 0);
        qv('SELECT COUNT(*) FROM payment_events', [], 0);
        return $hazir = true;
    } catch (Throwable $e) { return $hazir = false; }
}

/** Standart hata dönüşü (member_error() deseni). */
function payment_error($message, $code = 400) {
    return ['ok' => false, 'error' => (string)$message, 'code' => (int)$code];
}

// ============================================================ para birimi / tutar

/** Desteklenen para birimleri. */
function payment_currencies() { return ['TRY' => '₺ TRY', 'USD' => '$ USD', 'EUR' => '€ EUR']; }

/** Serbest girdiyi geçerli bir para birimine indirger. */
function payment_currency_clamp($c) {
    $c = strtoupper(sanitize_line((string)$c, 3));
    return array_key_exists($c, payment_currencies()) ? $c : 'TRY';
}

/**
 * '99,90' / '99.90' → 9990 (kuruş).
 * Para KURUŞ olarak saklanır; kayan nokta karşılaştırması yapılmaz.
 */
function payment_amount_to_cents($v) {
    $s = trim(str_replace([' ', "\xc2\xa0"], '', (string)$v));
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) { return 0; }
    return (int)round(((float)$s) * 100);
}

/** 9990 → '99,90' (arayüz). */
function payment_cents_to_text($cents) {
    return number_format(((int)$cents) / 100, 2, ',', '.');
}

/** '99,90 ₺' biçiminde tam etiket. */
function payment_amount_label($cents, $currency = 'TRY') {
    $sim = ['TRY' => '₺', 'USD' => '$', 'EUR' => '€'];
    $cur = payment_currency_clamp($currency);
    return payment_cents_to_text($cents) . ' ' . (isset($sim[$cur]) ? $sim[$cur] : $cur);
}

// ============================================================ planlar

/** Etkin (satıştaki) planlar. */
function payment_plans($onlyActive = true) {
    if (!payment_tables_ready()) { return []; }
    try {
        $sql = 'SELECT id, code, name, tier, days, price_cents, currency, description, active, sort
                FROM plans';
        if ($onlyActive) { $sql .= ' WHERE active = 1'; }
        $sql .= ' ORDER BY sort ASC, id ASC';
        return qa($sql);
    } catch (Throwable $e) { return []; }
}

/** Koda göre plan. */
function payment_plan_by_code($code) {
    if (!payment_tables_ready()) { return null; }
    $code = payment_plan_code_clean($code);
    if ($code === '') { return null; }
    try {
        return q1('SELECT id, code, name, tier, days, price_cents, currency, description, active
                   FROM plans WHERE code = :c', [':c' => $code]);
    } catch (Throwable $e) { return null; }
}

/** Kimliğe göre plan. */
function payment_plan_by_id($id) {
    if (!payment_tables_ready()) { return null; }
    try {
        return q1('SELECT id, code, name, tier, days, price_cents, currency, description, active
                   FROM plans WHERE id = :i', [':i' => (int)$id]);
    } catch (Throwable $e) { return null; }
}

/** Plan kodu: yalnız küçük harf, rakam ve tire. */
function payment_plan_code_clean($code) {
    $c = strtolower(sanitize_line((string)$code, 40));
    $c = (string)preg_replace('/[^a-z0-9\-]/', '', $c);
    return substr($c, 0, 40);
}

/**
 * Plan kaydeder (yeni ya da güncelleme).
 * @param array $in ['id','code','name','days','price','currency','description','active','sort']
 */
function payment_plan_save(array $in) {
    if (!payment_tables_ready()) { return payment_error('Ödeme tabloları kurulmadı.', 503); }

    $id   = (int)arr($in, 'id', 0);
    $code = payment_plan_code_clean(arr($in, 'code', ''));
    $name = sanitize_line((string)arr($in, 'name', ''), 120);
    if ($code === '') { return payment_error('Plan kodu gerekli (küçük harf, rakam, tire).'); }
    if ($name === '') { return payment_error('Plan adı gerekli.'); }

    $days = max(1, min(3650, (int)arr($in, 'days', 30)));
    $cents = max(0, min(100000000, payment_amount_to_cents(arr($in, 'price', '0'))));
    $cur = payment_currency_clamp(arr($in, 'currency', 'TRY'));

    // Kod BENZERSİZ: başka bir planın kodunu çalamaz.
    try {
        $cakisma = qv('SELECT id FROM plans WHERE code = :c AND id <> :i', [':c' => $code, ':i' => $id], 0);
    } catch (Throwable $e) { $cakisma = 0; }
    if ($cakisma) { return payment_error('Bu plan kodu zaten kullanılıyor.'); }

    $data = [
        'code'        => $code,
        'name'        => $name,
        // `tier` GİRDİDEN ALINMAZ: satılan tek kademe premium'dur.
        'tier'        => 'premium',
        'days'        => $days,
        'price_cents' => $cents,
        'currency'    => $cur,
        'description' => sanitize_line((string)arr($in, 'description', ''), 190),
        'active'      => arr($in, 'active', '') ? 1 : 0,
        'sort'        => max(0, min(999, (int)arr($in, 'sort', 0))),
        'updated_at'  => now(),
    ];

    try {
        if ($id > 0) {
            db_update('plans', $data, 'id = :id', [':id' => $id]);
        } else {
            $data['created_at'] = now();
            $id = (int)db_insert('plans', $data);
        }
    } catch (Throwable $e) {
        log_error('payment_plan_save: ' . $e->getMessage());
        return payment_error('Plan kaydedilemedi.', 500);
    }
    return ['ok' => true, 'id' => $id, 'message' => 'Plan kaydedildi.'];
}

/** Planı siler. Ödemesi olan plan SİLİNMEZ, yalnız kapatılır (kayıt bütünlüğü). */
function payment_plan_delete($id) {
    if (!payment_tables_ready()) { return payment_error('Ödeme tabloları kurulmadı.', 503); }
    $id = (int)$id;
    try {
        $kullanim = (int)qv('SELECT COUNT(*) FROM payments WHERE plan_id = :p', [':p' => $id], 0);
        if ($kullanim > 0) {
            db_update('plans', ['active' => 0, 'updated_at' => now()], 'id = :id', [':id' => $id]);
            return ['ok' => true, 'message' => 'Bu plana ait ödeme kayıtları var; plan silinmedi, satıştan kaldırıldı.'];
        }
        q('DELETE FROM plans WHERE id = :i', [':i' => $id]);
    } catch (Throwable $e) {
        log_error('payment_plan_delete: ' . $e->getMessage());
        return payment_error('Plan silinemedi.', 500);
    }
    return ['ok' => true, 'message' => 'Plan silindi.'];
}

// ============================================================ ödeme kaydı

/** Ödeme durumları: anahtar => [etiket, ton]. */
function payment_statuses() {
    return [
        'pending'   => ['Bekliyor', 'uyari'],
        'review'    => ['Dekont incelemede', 'bilgi'],
        'paid'      => ['Ödendi', 'olumlu'],
        'failed'    => ['Başarısız', 'olumsuz'],
        'rejected'  => ['Reddedildi', 'olumsuz'],
        'refunded'  => ['İade edildi', 'notr'],
        'expired'   => ['Süresi doldu', 'notr'],
    ];
}

/** Durum etiketi + ton. */
function payment_status_label($status) {
    $m = payment_statuses();
    return isset($m[$status]) ? $m[$status] : [(string)$status, 'notr'];
}

/**
 * Sipariş numarası üretir.
 * YALNIZ büyük harf + rakam: PayTR `merchant_oid` alfanümerik ister ve
 * imza dizesinde birebir yer aldığı için ayırıcı karakter taşımamalıdır.
 */
function payment_new_ref() {
    return 'MNS' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

/** Kimliğe göre ödeme. */
function payment_by_id($id) {
    if (!payment_tables_ready()) { return null; }
    try { return q1('SELECT * FROM payments WHERE id = :i', [':i' => (int)$id]); }
    catch (Throwable $e) { return null; }
}

/** Sipariş numarasına göre ödeme. */
function payment_by_ref($ref) {
    if (!payment_tables_ready()) { return null; }
    $ref = (string)preg_replace('/[^A-Za-z0-9]/', '', (string)$ref);
    if ($ref === '' || strlen($ref) > 64) { return null; }
    try { return q1('SELECT * FROM payments WHERE ref = :r', [':r' => $ref]); }
    catch (Throwable $e) { return null; }
}

/** Panel listesi. */
function payment_list(array $filters = [], $limit = 30, $offset = 0) {
    if (!payment_tables_ready()) { return []; }
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    list($where, $p) = payment_filter_sql($filters);
    try {
        return qa('SELECT p.*, u.email AS user_email, u.name AS user_name
                   FROM payments p LEFT JOIN users u ON u.id = p.user_id
                   WHERE ' . $where . '
                   ORDER BY p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $p);
    } catch (Throwable $e) {
        log_error('payment_list: ' . $e->getMessage());
        return [];
    }
}

/** Süzgece uyan ödeme sayısı. */
function payment_count(array $filters = []) {
    if (!payment_tables_ready()) { return 0; }
    list($where, $p) = payment_filter_sql($filters);
    try { return (int)qv('SELECT COUNT(*) FROM payments p WHERE ' . $where, $p, 0); }
    catch (Throwable $e) { return 0; }
}

/** Ortak süzgeç üretici — durum anahtarı YALNIZ tanımlı kümeden gelir. */
function payment_filter_sql(array $filters) {
    $where = '1 = 1';
    $p = [];
    $st = (string)arr($filters, 'status', '');
    if ($st !== '' && array_key_exists($st, payment_statuses())) {
        $where .= ' AND p.status = :st';
        $p[':st'] = $st;
    }
    $prov = (string)arr($filters, 'provider', '');
    if ($prov !== '' && array_key_exists($prov, payment_providers())) {
        $where .= ' AND p.provider = :pr';
        $p[':pr'] = $prov;
    }
    $uid = (int)arr($filters, 'user_id', 0);
    if ($uid > 0) { $where .= ' AND p.user_id = :uid'; $p[':uid'] = $uid; }
    return [$where, $p];
}

/** Onay bekleyen dekont sayısı (panel rozeti / kart). */
function payment_pending_receipt_count() {
    if (!payment_tables_ready()) { return 0; }
    try { return (int)qv('SELECT COUNT(*) FROM payments WHERE status = \'review\'', [], 0); }
    catch (Throwable $e) { return 0; }
}

/** Üyenin kendi ödeme geçmişi. */
function payment_user_history($userId, $limit = 20) {
    if (!payment_tables_ready()) { return []; }
    $userId = (int)$userId;
    if ($userId <= 0) { return []; }
    $limit = max(1, min(100, (int)$limit));
    try {
        return qa('SELECT id, ref, provider, status, amount_cents, currency, days, plan_name,
                          granted_until, created_at
                   FROM payments WHERE user_id = :u ORDER BY id DESC LIMIT ' . $limit,
            [':u' => $userId]);
    } catch (Throwable $e) { return []; }
}

// ============================================================ olay defteri

/**
 * Olay defterine yazar. `event_key` BENZERSİZ olduğu için aynı olay ikinci
 * kez yazılamaz — çift işlem kalkanı budur ve ATOMİKTİR.
 *
 * @return int|false Yeni kayıt kimliği, olay zaten işlendiyse false.
 */
function payment_event_claim($eventKey, $provider, $paymentId, $kind) {
    if (!payment_tables_ready()) { return false; }
    $eventKey = sanitize_line((string)$eventKey, 190);
    if ($eventKey === '') { return false; }
    try {
        return (int)db_insert('payment_events', [
            'payment_id' => (int)$paymentId,
            'provider'   => sanitize_line((string)$provider, 30),
            'event_key'  => $eventKey,
            'kind'       => sanitize_line((string)$kind, 30),
            'ok'         => 0,
            'message'    => '',
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        // Benzersiz indeks ihlali = olay zaten işlenmiş. Beklenen durumdur,
        // hata değildir; sağlayıcılar webhook'u tekrar gönderir.
        //
        // Başka bir veritabanı hatası da AYNI yanıtı verir (false) — yani
        // uzatma yapılmaz. Bu bilinçli: şüphe hâlinde fazladan uzatmaktansa
        // hiç uzatmamak yeğdir. Ama sessiz kalmaz, günlüğe yazılır ki
        // yayıncı "ödedim, açılmadı" şikâyetinin izini bulabilsin.
        $m = strtolower($e->getMessage());
        if (strpos($m, 'unique') === false && strpos($m, 'duplicate') === false
            && strpos($m, 'constraint') === false) {
            log_error('payment_event_claim (tekrar DEĞİL, gerçek hata): ' . $e->getMessage());
        }
        return false;
    }
}

/** Olay kaydının sonucunu damgalar. */
function payment_event_finish($eventId, $ok, $message = '') {
    if ((int)$eventId <= 0) { return; }
    try {
        db_update('payment_events',
            ['ok' => $ok ? 1 : 0, 'message' => sanitize_line((string)$message, 255)],
            'id = :id', [':id' => (int)$eventId]);
    } catch (Throwable $e) { log_error('payment_event_finish: ' . $e->getMessage()); }
}

/**
 * İmzasız/geçersiz istekler için REDDEDİLDİ kaydı.
 *
 * DİKKAT: bu kayıt `payments` tablosuna DOKUNMAZ ve hiçbir aboneliği
 * uzatmaz; yalnız `db/error.log` üzerinden yayıncıya görünür. Doğrulanmamış
 * girdiyi tabloya yazmak, saldırgana kayıt şişirme imkânı verirdi.
 */
function payment_reject_log($provider, $reason) {
    log_error('Ödeme webhook reddedildi [' . sanitize_line((string)$provider, 30) . ']: '
        . sanitize_line((string)$reason, 200) . ' ip=' . client_ip());
}

/** Bir ödemenin olay geçmişi (panel). */
function payment_events_for($paymentId, $limit = 30) {
    if (!payment_tables_ready()) { return []; }
    $limit = max(1, min(100, (int)$limit));
    try {
        return qa('SELECT id, provider, event_key, kind, ok, message, created_at
                   FROM payment_events WHERE payment_id = :p ORDER BY id DESC LIMIT ' . $limit,
            [':p' => (int)$paymentId]);
    } catch (Throwable $e) { return []; }
}

// ============================================================ ödeme başlatma

/**
 * Okur için ödeme başlatır.
 *
 * @param int    $userId    Oturumdaki ÜYE kimliği (çağıran doğrular)
 * @param string $planCode  Plan kodu
 * @param string $method    'auto' (etkin sağlayıcı) | 'manual_iban'
 * @return array ['ok', 'ref', 'mode'=>'iban'|'redirect', 'url', 'payment_id', 'error']
 */
function payment_start($userId, $planCode, $method = 'auto') {
    $userId = (int)$userId;
    if (!payment_enabled())        { return payment_error('Çevrimiçi abonelik şu anda kapalı.', 503); }
    if (!payment_tables_ready())   { return payment_error('Ödeme tabloları kurulmadı.', 503); }
    if ($userId <= 0)              { return payment_error('Önce giriş yapmalısınız.', 401); }

    // Hedef YALNIZ üye olabilir: personel hesabına abonelik satılmaz (§8 risk #7 deseni).
    $uye = q1('SELECT id, email, name FROM users WHERE id = :i AND role = \'member\' AND active = 1',
        [':i' => $userId]);
    if (!$uye) { return payment_error('Üye hesabı bulunamadı.', 404); }

    // Hız sınırı: sipariş numarası üretimi ücretsiz ama sınırsız değil.
    if (!rate_limit('payment-start:' . $userId, 10, 900)) {
        return payment_error('Çok fazla ödeme denemesi yapıldı. Lütfen biraz sonra tekrar deneyin.', 429);
    }

    $plan = payment_plan_by_code($planCode);
    if (!$plan || (int)$plan['active'] !== 1) { return payment_error('Seçilen plan satışta değil.', 404); }
    if ((int)$plan['price_cents'] <= 0) { return payment_error('Bu planın fiyatı tanımlanmamış.', 400); }

    $saglayici = ((string)$method === 'manual_iban') ? 'manual_iban' : payment_provider();
    if ($saglayici === 'manual_iban' && !payment_manual_enabled()) {
        return payment_error('Havale ile ödeme kapalı.', 403);
    }
    if ($saglayici !== 'manual_iban' && !payment_configured()) {
        return payment_error('Ödeme sağlayıcısı yapılandırılmadı.', 503);
    }

    // Tutar, gün sayısı ve para birimi PLANDAN KOPYALANIR. Plan sonradan
    // değişse bile bu ödemenin koşulları sabittir; webhook doğrulaması
    // geçmişe dönük bozulmaz.
    $ref = payment_new_ref();
    try {
        $pid = (int)db_insert('payments', [
            'ref'          => $ref,
            'user_id'      => $userId,
            'plan_id'      => (int)$plan['id'],
            'provider'     => $saglayici,
            'status'       => 'pending',
            'amount_cents' => (int)$plan['price_cents'],
            'currency'     => payment_currency_clamp($plan['currency']),
            'days'         => max(1, (int)$plan['days']),
            'plan_name'    => sanitize_line((string)$plan['name'], 120),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    } catch (Throwable $e) {
        log_error('payment_start: ' . $e->getMessage());
        return payment_error('Ödeme kaydı açılamadı.', 500);
    }

    if ($saglayici === 'manual_iban') {
        return [
            'ok'         => true,
            'mode'       => 'iban',
            'ref'        => $ref,
            'payment_id' => $pid,
            'message'    => 'Havale bilgileri hazır. Açıklama alanına sipariş numarasını yazın.',
        ];
    }

    $r = payment_paytr_create($pid, $ref, $plan, $uye);
    if (empty($r['ok'])) {
        try {
            db_update('payments', ['status' => 'failed', 'note' => sanitize_line((string)arr($r, 'error', ''), 190),
                                   'updated_at' => now()], 'id = :id', [':id' => $pid]);
        } catch (Throwable $e) { }
        return payment_error((string)arr($r, 'error', 'Ödeme başlatılamadı.'), 502);
    }
    return ['ok' => true, 'mode' => 'redirect', 'url' => $r['url'], 'ref' => $ref, 'payment_id' => $pid];
}

// ============================================================ PayTR uyarlayıcısı

/**
 * PayTR imza dizesini HMAC-SHA256 + base64 ile üretir.
 * Anahtar HİÇBİR günlüğe yazılmaz; yalnız burada kullanılır.
 */
function payment_paytr_sign($data, $key) {
    return base64_encode(hash_hmac('sha256', (string)$data, (string)$key, true));
}

/**
 * Barındırılan ödeme sayfası için token alır.
 *
 * KART VERİSİ BU İSTEKTE YOKTUR: yalnız sipariş numarası, tutar, e-posta ve
 * dönüş adresleri gider. Kart alanları PayTR'ın kendi sayfasında doldurulur.
 *
 * @return array ['ok'=>bool, 'url'=>string, 'error'=>string]
 */
function payment_paytr_create($paymentId, $ref, array $plan, array $uye) {
    $mid  = payment_paytr_merchant_id();
    $key  = payment_paytr_key();
    $salt = payment_paytr_salt();
    if ($mid === '' || $key === '' || $salt === '') {
        return ['ok' => false, 'url' => '', 'error' => 'PayTR anahtarları eksik.'];
    }

    $tutar = (int)$plan['price_cents'];                 // kuruş
    $ip    = client_ip();
    $mail  = (string)$uye['email'];
    // PayTR para birimi kodu: TRY için 'TL'.
    $cur   = payment_currency_clamp($plan['currency']);
    $paraKodu = ($cur === 'TRY') ? 'TL' : $cur;

    $sepet = base64_encode(json_encode(
        [[sanitize_line((string)$plan['name'], 80), payment_cents_to_text($tutar), 1]],
        JSON_UNESCAPED_UNICODE));
    $taksitYok = '1';   // no_installment
    $enFazla   = '0';   // max_installment
    $test      = (string)payment_paytr_sandbox();

    // İmza dizesi: alanların BİRLEŞTİRİLMESİ. Sıra sağlayıcı belgesindekiyle
    // birebir aynı olmalıdır; yayıncı sürüm yükseltmesinde teyit etmelidir.
    $imzaMetni = $mid . $ip . $ref . $mail . $tutar . $sepet
               . $taksitYok . $enFazla . $paraKodu . $test;
    $token = payment_paytr_sign($imzaMetni, $key . $salt);

    $alanlar = [
        'merchant_id'     => $mid,
        'user_ip'         => $ip,
        'merchant_oid'    => $ref,
        'email'           => $mail,
        'payment_amount'  => $tutar,
        'paytr_token'     => $token,
        'user_basket'     => $sepet,
        'debug_on'        => 0,
        'no_installment'  => $taksitYok,
        'max_installment' => $enFazla,
        'user_name'       => sanitize_line((string)arr($uye, 'name', 'Abone'), 60),
        'user_address'    => 'Dijital abonelik',
        'user_phone'      => '0000000000',
        'merchant_ok_url'   => payment_return_url($ref, 'ok'),
        'merchant_fail_url' => payment_return_url($ref, 'fail'),
        'timeout_limit'   => 30,
        'currency'        => $paraKodu,
        'test_mode'       => $test,
    ];

    // TEST MODU: dışa çıkılmaz. İmza ÜRETİMİ yine gerçektir; yalnız ağ atlanır.
    if (payment_test_mode()) {
        return ['ok' => true, 'error' => '',
                'url' => payment_return_url($ref, 'sandbox')];
    }

    $r = payment_http_post_form(payment_paytr_base() . '/odeme/api/get-token', $alanlar);
    if (empty($r['ok'])) { return ['ok' => false, 'url' => '', 'error' => (string)$r['error']]; }

    $d = json_decode((string)$r['body'], true);
    if (!is_array($d)) { return ['ok' => false, 'url' => '', 'error' => 'Sağlayıcı yanıtı çözümlenemedi.']; }
    if ((string)arr($d, 'status', '') !== 'success') {
        // Sağlayıcı hata metni kullanıcıya AYNEN yansıtılmaz (bilgi sızıntısı);
        // kısaltılmış biçimde günlüğe yazılır.
        log_error('PayTR get-token reddi: ' . sanitize_line((string)arr($d, 'reason', ''), 200));
        return ['ok' => false, 'url' => '', 'error' => 'Ödeme sağlayıcısı isteği reddetti.'];
    }
    $tok = (string)arr($d, 'token', '');
    if (!preg_match('/^[A-Za-z0-9_\-=+\/]{8,255}$/', $tok)) {
        return ['ok' => false, 'url' => '', 'error' => 'Sağlayıcı geçersiz bir oturum anahtarı döndürdü.'];
    }
    try {
        db_update('payments', ['provider_ref' => sanitize_line($tok, 120), 'updated_at' => now()],
            'id = :id', [':id' => (int)$paymentId]);
    } catch (Throwable $e) { }

    return ['ok' => true, 'error' => '', 'url' => payment_paytr_base() . '/odeme/guvenli/' . rawurlencode($tok)];
}

/**
 * PayTR geri bildirimini DOĞRULAR.
 *
 * İmza: base64( HMAC-SHA256( merchant_oid + merchant_salt + status + total_amount,
 *                            merchant_key ) )
 *
 * Karşılaştırma `hash_equals` iledir (zamanlama saldırısı). Doğrulama
 * başarısızsa dönüş `ok=false`'tur ve ÇAĞIRAN HİÇBİR KAYIT YAZMAZ.
 *
 * @return array ['ok','ref','status','amount_cents','event_key','error']
 */
function payment_paytr_verify(array $post) {
    $bos = ['ok' => false, 'ref' => '', 'status' => '', 'amount_cents' => 0, 'event_key' => '', 'error' => ''];

    $key  = payment_paytr_key();
    $salt = payment_paytr_salt();
    if ($key === '' || $salt === '') {
        return array_merge($bos, ['error' => 'Sağlayıcı anahtarı tanımlı değil.']);
    }

    $ref    = (string)arr($post, 'merchant_oid', '');
    $status = (string)arr($post, 'status', '');
    $tutar  = (string)arr($post, 'total_amount', '');
    $hash   = (string)arr($post, 'hash', '');

    // Biçim kapısı: imza doğrulamasından ÖNCE. Beklenmeyen biçimdeki bir
    // alan imza dizesine girip karşılaştırmayı anlamsızlaştırmasın.
    if (!preg_match('/^[A-Za-z0-9]{1,64}$/', $ref))       { return array_merge($bos, ['error' => 'Geçersiz sipariş numarası.']); }
    if (!in_array($status, ['success', 'failed'], true))  { return array_merge($bos, ['error' => 'Geçersiz durum alanı.']); }
    if (!preg_match('/^[0-9]{1,12}$/', $tutar))           { return array_merge($bos, ['error' => 'Geçersiz tutar alanı.']); }
    if ($hash === '' || strlen($hash) > 128)              { return array_merge($bos, ['error' => 'İmza yok.']); }

    $beklenen = payment_paytr_sign($ref . $salt . $status . $tutar, $key);

    // SABİT ZAMANLI karşılaştırma. `==` kullanılsaydı imza baytları
    // ölçülerek tahmin edilebilirdi (zamanlama saldırısı).
    if (!hash_equals($beklenen, $hash)) {
        return array_merge($bos, ['error' => 'İmza doğrulanamadı.']);
    }

    return [
        'ok'           => true,
        'ref'          => $ref,
        'status'       => $status,
        'amount_cents' => (int)$tutar,
        // Aynı sipariş + aynı durum = AYNI OLAY. İkinci gelişte benzersiz
        // indeks reddeder ve abonelik ikinci kez uzamaz.
        'event_key'    => 'paytr:' . $ref . ':' . $status,
        'error'        => '',
    ];
}

// ============================================================ webhook

/**
 * Sağlayıcı geri bildirimini işler. CSRF YOKTUR (istek dış sistemden gelir);
 * bu yüzden TEK savunma imza doğrulamasıdır ve doğrulanmadan HİÇBİR ŞEY yazılmaz.
 *
 * Bu işlev OTURUM AÇMAZ: anonim isteğe çerez verilmez (CONTRACTS §3.1).
 *
 * @return array ['ok'=>bool,'body'=>string(sağlayıcıya dönecek gövde),'code'=>int]
 */
function payment_webhook_handle($provider, array $post) {
    $provider = (string)$provider;
    if (!payment_tables_ready()) {
        payment_reject_log($provider, 'tablolar hazır değil');
        return ['ok' => false, 'body' => 'ERR', 'code' => 503];
    }
    // Kaba kuvvet imza denemesine karşı geniş bir kova. Sağlayıcı tekrarları
    // meşrudur, bu yüzden sınır bilerek yüksek tutuldu.
    if (!rate_limit('payment-webhook:' . client_ip(), 120, 60)) {
        payment_reject_log($provider, 'hız sınırı');
        return ['ok' => false, 'body' => 'ERR', 'code' => 429];
    }

    if ($provider !== 'paytr') {
        payment_reject_log($provider, 'bilinmeyen sağlayıcı');
        return ['ok' => false, 'body' => 'ERR', 'code' => 400];
    }

    $v = payment_paytr_verify($post);
    if (empty($v['ok'])) {
        // İMZASIZ / GEÇERSİZ İSTEK: hiçbir tabloya yazılmaz.
        payment_reject_log('paytr', (string)$v['error']);
        return ['ok' => false, 'body' => 'ERR', 'code' => 403];
    }

    $odeme = payment_by_ref($v['ref']);
    if (!$odeme) {
        payment_reject_log('paytr', 'bilinmeyen sipariş numarası');
        return ['ok' => false, 'body' => 'ERR', 'code' => 404];
    }
    if ((string)$odeme['provider'] !== 'paytr') {
        payment_reject_log('paytr', 'sağlayıcı uyuşmuyor');
        return ['ok' => false, 'body' => 'ERR', 'code' => 409];
    }

    if ($v['status'] !== 'success') {
        payment_mark_failed($odeme, $v['event_key'], 'paytr', 'Sağlayıcı ödemeyi başarısız bildirdi.');
        // Sağlayıcı "aldım" yanıtı bekler; aksi hâlde sonsuza dek tekrar gönderir.
        return ['ok' => true, 'body' => 'OK', 'code' => 200];
    }

    $r = payment_apply($odeme, $v['event_key'], 'paytr', (int)$v['amount_cents'],
        (string)$odeme['currency'], (string)arr($post, 'payment_type', ''));

    // Tutar tutmadıysa bile sağlayıcıya OK dönülür: tekrar göndermesi
    // sorunu çözmez, yalnız günlüğü şişirir. Yayıncı panelde görür.
    return ['ok' => !empty($r['ok']), 'body' => 'OK', 'code' => 200];
}

/** Başarısız bildirim: abonelik dokunulmadan durum düşürülür. */
function payment_mark_failed(array $odeme, $eventKey, $provider, $mesaj) {
    $ev = payment_event_claim($eventKey, $provider, (int)$odeme['id'], 'failed');
    if ($ev === false) { return ['ok' => true, 'duplicate' => true]; }
    try {
        // Yalnız BEKLEYEN bir ödeme başarısıza düşer. Ödenmiş bir kaydı
        // sahte bir "failed" bildirimiyle geri almak mümkün olmamalıdır.
        db_update('payments', ['status' => 'failed', 'note' => sanitize_line($mesaj, 190), 'updated_at' => now()],
            'id = :id AND status IN (\'pending\', \'review\')', [':id' => (int)$odeme['id']]);
    } catch (Throwable $e) { log_error('payment_mark_failed: ' . $e->getMessage()); }
    payment_event_finish($ev, true, $mesaj);
    return ['ok' => true];
}

/**
 * ÖDEMEYİ UYGULAR — aboneliği uzatan TEK yol.
 *
 * Üç ayrı kalkan sırayla çalışır:
 *   1) TUTAR + PARA BİRİMİ: sağlayıcının söylediği tutar kendi kaydımızla
 *      tam eşitlikle karşılaştırılır. Tutmazsa uzatma YOK.
 *      (Aksi hâlde 1 TL ödeyip yıllık abonelik alınırdı.)
 *   2) ÇİFT İŞLEM: `event_key` benzersiz indeksi. Aynı olay iki kez gelirse
 *      ikincisi INSERT aşamasında düşer.
 *   3) YARIŞ: `UPDATE … WHERE status = 'pending'` atomik karşılaştır-ve-değiştir.
 *      Eşzamanlı iki webhook'tan yalnız `rowCount() === 1` alan uzatır.
 *
 * @return array ['ok','duplicate','error','valid_until']
 */
function payment_apply(array $odeme, $eventKey, $provider, $amountCents, $currency, $providerRef = '') {
    $pid = (int)$odeme['id'];

    // ---- 1) TUTAR VE PARA BİRİMİ DOĞRULAMASI (uzatmadan ÖNCE)
    $beklenenTutar = (int)$odeme['amount_cents'];
    $beklenenPara  = payment_currency_clamp($odeme['currency']);
    if ((int)$amountCents !== $beklenenTutar || payment_currency_clamp($currency) !== $beklenenPara) {
        $ev = payment_event_claim($eventKey . ':tutarsiz', $provider, $pid, 'amount_mismatch');
        $mesaj = 'Tutar uyuşmadı: beklenen ' . $beklenenTutar . ' kuruş, bildirilen ' . (int)$amountCents . ' kuruş.';
        if ($ev !== false) { payment_event_finish($ev, false, $mesaj); }
        log_error('Ödeme tutarı uyuşmadı [' . $provider . ' ref=' . (string)$odeme['ref'] . ']: ' . $mesaj);
        try {
            db_update('payments', ['status' => 'failed', 'note' => sanitize_line($mesaj, 190), 'updated_at' => now()],
                'id = :id AND status IN (\'pending\', \'review\')', [':id' => $pid]);
        } catch (Throwable $e) { }
        return ['ok' => false, 'duplicate' => false, 'error' => $mesaj];
    }

    // ---- 2) ÇİFT İŞLEM KALKANI (atomik: benzersiz indeks)
    $ev = payment_event_claim($eventKey, $provider, $pid, 'paid');
    if ($ev === false) {
        return ['ok' => true, 'duplicate' => true, 'error' => '', 'valid_until' => (string)arr($odeme, 'granted_until', '')];
    }

    // ---- 3) YARIŞ KALKANI (atomik: koşullu UPDATE)
    try {
        $etkilenen = db_update('payments', [
            'status'       => 'paid',
            'applied_at'   => now(),
            'provider_ref' => sanitize_line((string)$providerRef, 120),
            'updated_at'   => now(),
        ], 'id = :id AND status IN (\'pending\', \'review\')', [':id' => $pid]);
    } catch (Throwable $e) {
        log_error('payment_apply: ' . $e->getMessage());
        payment_event_finish($ev, false, 'Kayıt güncellenemedi.');
        return ['ok' => false, 'duplicate' => false, 'error' => 'Ödeme kaydı güncellenemedi.'];
    }
    if ((int)$etkilenen !== 1) {
        // Başka bir istek aynı ödemeyi zaten uygulamış.
        payment_event_finish($ev, true, 'Zaten uygulanmış (yarış).');
        return ['ok' => true, 'duplicate' => true, 'error' => '', 'valid_until' => ''];
    }

    // ---- ABONELİĞİ UZAT
    $until = payment_extend_membership((int)$odeme['user_id'], (int)$odeme['days'], (string)$odeme['ref']);
    if ($until === '') {
        payment_event_finish($ev, false, 'Abonelik uzatılamadı.');
        return ['ok' => false, 'duplicate' => false, 'error' => 'Abonelik uzatılamadı.'];
    }
    try {
        db_update('payments', ['granted_until' => $until, 'updated_at' => now()], 'id = :id', [':id' => $pid]);
    } catch (Throwable $e) { }
    payment_event_finish($ev, true, 'Abonelik ' . $until . ' tarihine uzatıldı.');

    return ['ok' => true, 'duplicate' => false, 'error' => '', 'valid_until' => $until];
}

/**
 * Aboneliği uzatır ve YENİ bitiş tarihini döndürür.
 *
 * Uzatma noktası: mevcut bitiş ile ŞİMDİ'nin büyük olanı. Süresi dolmuş
 * abonelik geçmişten değil bugünden uzar; süresi dolmamış abonelik ise
 * kalan hakkını KAYBETMEZ.
 */
function payment_extend_membership($userId, $days, $ref = '') {
    $userId = (int)$userId;
    $days = max(1, min(3650, (int)$days));
    if ($userId <= 0 || !function_exists('member_set_subscription')) { return ''; }

    $taban = time();
    if (function_exists('member_subscription')) {
        $s = member_subscription($userId);
        $mevcut = trim((string)arr($s, 'valid_until', ''));
        if ((string)arr($s, 'stored_tier', '') === 'premium' && $mevcut !== '') {
            $ts = strtotime($mevcut);
            if ($ts !== false && $ts > $taban) { $taban = $ts; }
        }
    }
    $until = date('Y-m-d H:i:s', $taban + $days * 86400);

    $r = member_set_subscription($userId, 'premium', $until,
        'Ödeme: ' . sanitize_line((string)$ref, 60));
    if (empty($r['ok'])) {
        log_error('payment_extend_membership: ' . (string)arr($r, 'error', 'bilinmeyen hata'));
        return '';
    }
    return $until;
}

// ============================================================ iade / iptal

/**
 * İade / iptal: `valid_until` GERİ ALINIR.
 *
 * Para iadesi sağlayıcının kendi panelinden yapılır (bu sistem para hareketi
 * başlatmaz); burada yapılan, verilen sürenin geri alınmasıdır. Ödemenin
 * verdiği gün sayısı bitiş tarihinden düşülür; sonuç bugünden geriye
 * düşerse kademe `free`'ye iner.
 */
function payment_refund($paymentId, $adminId = 0, $note = '') {
    if (!payment_tables_ready()) { return payment_error('Ödeme tabloları kurulmadı.', 503); }
    $odeme = payment_by_id($paymentId);
    if (!$odeme) { return payment_error('Ödeme kaydı bulunamadı.', 404); }
    if ((string)$odeme['status'] !== 'paid') {
        return payment_error('Yalnız ödenmiş bir kayıt iade edilebilir.');
    }

    $pid = (int)$odeme['id'];
    // Atomik: iki yönetici aynı anda iade tuşuna basarsa süre iki kez düşmez.
    try {
        $etkilenen = db_update('payments', [
            'status'      => 'refunded',
            'note'        => sanitize_line((string)$note, 190),
            'approved_by' => (int)$adminId,
            'updated_at'  => now(),
        ], 'id = :id AND status = \'paid\'', [':id' => $pid]);
    } catch (Throwable $e) {
        log_error('payment_refund: ' . $e->getMessage());
        return payment_error('İade kaydedilemedi.', 500);
    }
    if ((int)$etkilenen !== 1) { return payment_error('Kayıt bu sırada değişti; sayfayı yenileyin.', 409); }

    $yeni = payment_reduce_membership((int)$odeme['user_id'], (int)$odeme['days'], (string)$odeme['ref']);
    $ev = payment_event_claim('refund:' . (string)$odeme['ref'] . ':' . $pid, (string)$odeme['provider'], $pid, 'refund');
    if ($ev !== false) { payment_event_finish($ev, true, 'İade — yeni bitiş: ' . ($yeni === '' ? 'yok' : $yeni)); }

    return ['ok' => true, 'valid_until' => $yeni,
            'message' => 'İade işlendi; abonelik süresi geri alındı. Para iadesini sağlayıcı panelinden yapmayı unutmayın.'];
}

/** Aboneliği verilen gün kadar geri alır. */
function payment_reduce_membership($userId, $days, $ref = '') {
    $userId = (int)$userId;
    $days = max(1, min(3650, (int)$days));
    if ($userId <= 0 || !function_exists('member_subscription') || !function_exists('member_set_subscription')) { return ''; }

    $s = member_subscription($userId);
    $mevcut = trim((string)arr($s, 'valid_until', ''));
    if ($mevcut === '') {
        member_set_subscription($userId, 'free', '', 'İade: ' . sanitize_line((string)$ref, 60));
        return '';
    }
    $ts = strtotime($mevcut);
    if ($ts === false) { return ''; }
    $yeniTs = $ts - $days * 86400;

    if ($yeniTs <= time()) {
        member_set_subscription($userId, 'free', date('Y-m-d H:i:s', time()),
            'İade: ' . sanitize_line((string)$ref, 60));
        return '';
    }
    $yeni = date('Y-m-d H:i:s', $yeniTs);
    member_set_subscription($userId, 'premium', $yeni, 'İade: ' . sanitize_line((string)$ref, 60));
    return $yeni;
}

// ============================================================ havale / dekont

/**
 * Dekont dosyasını kaydeder.
 *
 * NEDEN `db/` ALTINDA: dekont finansal bir belgedir; medya kütüphanesine
 * girmez ve tahmin edilebilir bir adla yayımlanmaz. `uploads/` HTTP'den
 * okunabilir bir dizindir (yalnız PHP çalıştırması kapalıdır) — oraya konan
 * bir dekont, adı sızarsa doğrudan indirilebilirdi. `db/` ise kök
 * `.htaccess` ve kendi `.htaccess`'i ile TÜMDEN kapalıdır; SQLite veritabanı
 * da orada durur, yani dekont veritabanının kendisiyle aynı korumayı alır.
 *
 * Üç katmanlı savunma: (1) kapalı dizin, (2) tahmin edilemez 32 haneli
 * rastgele ad, (3) görüntüleme yalnız `payments.manage` izinli uçtan.
 *
 * SINIR: `.htaccess` yalnız Apache'de geçerlidir. nginx kullanan yayıncı,
 * `db/` dizinine dışarıdan erişimi kendi yapılandırmasında kapatmak
 * zorundadır — bu zaten veritabanı için de geçerli olan mevcut varsayımdır.
 */
function payment_receipt_dir() { return DB_DIR . '/dekont'; }

/** Dekont klasörünü hazırlar (erişim kapalı). */
function payment_receipt_dir_ready() {
    $dir = payment_receipt_dir();
    if (!ensure_dir($dir)) { return false; }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "# Dekontlar HTTP ile doğrudan okunamaz; yalnız panelden görüntülenir.\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) { @file_put_contents($dir . '/index.html', ''); }
    return true;
}

/** Kabul edilen dekont türleri: uzantı => MIME. */
function payment_receipt_types() {
    return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'webp' => 'image/webp', 'pdf' => 'application/pdf'];
}

/**
 * Okurun yüklediği dekontu ödemeye bağlar.
 *
 * @param int   $userId Oturumdaki üye (sahiplik burada doğrulanır)
 * @param string $ref   Sipariş numarası
 * @param array $file   $_FILES girdisi
 */
function payment_receipt_upload($userId, $ref, array $file) {
    $userId = (int)$userId;
    if (!payment_manual_enabled()) { return payment_error('Havale ile ödeme kapalı.', 403); }
    if (!payment_tables_ready())   { return payment_error('Ödeme tabloları kurulmadı.', 503); }
    if (!rate_limit('payment-receipt:' . $userId, 6, 900)) {
        return payment_error('Çok fazla yükleme denemesi. Lütfen biraz sonra tekrar deneyin.', 429);
    }

    $odeme = payment_by_ref($ref);
    // SAHİPLİK: başkasının siparişine dekont yüklenemez.
    if (!$odeme || (int)$odeme['user_id'] !== $userId) { return payment_error('Ödeme kaydı bulunamadı.', 404); }
    if (!in_array((string)$odeme['status'], ['pending', 'review'], true)) {
        return payment_error('Bu ödeme için dekont kabul edilmiyor.');
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
        return payment_error('Dosya alınamadı.');
    }
    $boyut = (int)arr($file, 'size', 0);
    if ($boyut <= 0 || $boyut > PAYMENT_RECEIPT_MAX_BYTES) {
        return payment_error('Dekont en fazla 4 MB olabilir.');
    }

    $ad = (string)arr($file, 'name', '');
    $uzanti = strtolower((string)pathinfo($ad, PATHINFO_EXTENSION));
    $turler = payment_receipt_types();
    if (!isset($turler[$uzanti])) {
        return payment_error('Yalnız JPG, PNG, WEBP veya PDF yükleyebilirsiniz.');
    }
    // Uzantıya değil İÇERİĞE bakılır.
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) { $mime = (string)@finfo_file($fi, (string)$file['tmp_name']); @finfo_close($fi); }
    }
    if ($mime !== '' && $mime !== $turler[$uzanti]) {
        return payment_error('Dosya içeriği uzantısıyla uyuşmuyor.');
    }
    if ($mime === '' && $uzanti !== 'pdf' && @getimagesize((string)$file['tmp_name']) === false) {
        return payment_error('Görsel dosya okunamadı.');
    }

    if (!payment_receipt_dir_ready()) { return payment_error('Dekont klasörü oluşturulamadı.', 500); }
    // Ad TAHMİN EDİLEMEZ: dosya adı sipariş numarasından türetilmez.
    $hedefAd = bin2hex(random_bytes(16)) . '.' . $uzanti;
    $hedef = payment_receipt_dir() . '/' . $hedefAd;
    if (!@move_uploaded_file((string)$file['tmp_name'], $hedef)) {
        return payment_error('Dekont kaydedilemedi.', 500);
    }
    @chmod($hedef, 0644);

    // Önceki dekont varsa diskten silinir (yer şişmesin).
    $eski = sanitize_line((string)$odeme['receipt_file'], 120);
    if ($eski !== '' && preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $eski)) {
        @unlink(payment_receipt_dir() . '/' . $eski);
    }

    try {
        db_update('payments', [
            'receipt_file' => $hedefAd,
            'status'       => 'review',
            'updated_at'   => now(),
        ], 'id = :id AND user_id = :u AND status IN (\'pending\', \'review\')',
           [':id' => (int)$odeme['id'], ':u' => $userId]);
    } catch (Throwable $e) {
        log_error('payment_receipt_upload: ' . $e->getMessage());
        return payment_error('Dekont kaydedilemedi.', 500);
    }

    return ['ok' => true, 'message' => 'Dekontunuz alındı. Yönetici onayından sonra aboneliğiniz açılacak.'];
}

/** Dekont dosyasının disk yolu (yalnız güvenli adlar). */
function payment_receipt_path($file) {
    $f = (string)$file;
    if (!preg_match('/^[a-f0-9]{32}\.[a-z]{3,4}$/', $f)) { return ''; }
    $p = payment_receipt_dir() . '/' . $f;
    return is_file($p) ? $p : '';
}

/**
 * Yöneticinin dekont onayı — aboneliği uzatan ikinci meşru yol.
 * Webhook ile AYNI kalkanlardan geçer (olay defteri + koşullu UPDATE).
 */
function payment_manual_approve($paymentId, $adminId, $note = '') {
    if (!payment_tables_ready()) { return payment_error('Ödeme tabloları kurulmadı.', 503); }
    $odeme = payment_by_id($paymentId);
    if (!$odeme) { return payment_error('Ödeme kaydı bulunamadı.', 404); }
    if (!in_array((string)$odeme['status'], ['pending', 'review'], true)) {
        return payment_error('Bu kayıt onaylanacak durumda değil.');
    }
    if ((string)$odeme['provider'] !== 'manual_iban') {
        return payment_error('Yalnız havale kayıtları elle onaylanır.');
    }

    try {
        db_update('payments', ['approved_by' => (int)$adminId, 'note' => sanitize_line((string)$note, 190),
                               'updated_at' => now()], 'id = :id', [':id' => (int)$odeme['id']]);
    } catch (Throwable $e) { }

    $r = payment_apply($odeme, 'manual:' . (string)$odeme['ref'] . ':approve', 'manual_iban',
        (int)$odeme['amount_cents'], (string)$odeme['currency'], 'admin:' . (int)$adminId);
    if (empty($r['ok'])) { return payment_error((string)arr($r, 'error', 'Onaylanamadı.'), 500); }
    if (!empty($r['duplicate'])) { return ['ok' => true, 'message' => 'Bu ödeme zaten uygulanmıştı.']; }

    return ['ok' => true, 'valid_until' => (string)arr($r, 'valid_until', ''),
            'message' => 'Ödeme onaylandı, abonelik uzatıldı.'];
}

/** Yöneticinin dekont reddi. Abonelik DEĞİŞMEZ. */
function payment_manual_reject($paymentId, $adminId, $note = '') {
    if (!payment_tables_ready()) { return payment_error('Ödeme tabloları kurulmadı.', 503); }
    $odeme = payment_by_id($paymentId);
    if (!$odeme) { return payment_error('Ödeme kaydı bulunamadı.', 404); }
    if (!in_array((string)$odeme['status'], ['pending', 'review'], true)) {
        return payment_error('Bu kayıt reddedilecek durumda değil.');
    }
    try {
        db_update('payments', ['status' => 'rejected', 'approved_by' => (int)$adminId,
                               'note' => sanitize_line((string)$note, 190), 'updated_at' => now()],
            'id = :id AND status IN (\'pending\', \'review\')', [':id' => (int)$odeme['id']]);
    } catch (Throwable $e) {
        log_error('payment_manual_reject: ' . $e->getMessage());
        return payment_error('Kayıt güncellenemedi.', 500);
    }
    return ['ok' => true, 'message' => 'Ödeme reddedildi.'];
}

// ============================================================ adresler

/** Ödeme sayfası adresi. */
function payment_page_url(array $q = []) {
    return base_url() . '/odeme.php' . ($q ? '?' . http_build_query($q) : '');
}

/** Sağlayıcının okuru geri göndereceği adres. */
function payment_return_url($ref, $sonuc) {
    return payment_page_url(['s' => 'donus', 'ref' => (string)$ref,
                             'd' => in_array($sonuc, ['ok', 'fail', 'sandbox'], true) ? $sonuc : 'ok']);
}

/** Sağlayıcı paneline yazılacak bildirim (webhook) adresi. */
function payment_webhook_url($provider = null) {
    $p = $provider === null ? payment_provider() : (string)$provider;
    return base_url() . '/odeme.php?s=webhook&p=' . rawurlencode($p);
}

// ============================================================ HTTP (SSRF kalkanlı)

/**
 * Form kodlu POST. Adres `safe_remote_target()` SSRF kalkanından geçer ve
 * çözülen IP curl'e sabitlenir (DNS yeniden bağlama kalkanı — inc/ai.php ile
 * aynı desen). Composer/dış kütüphane yok.
 *
 * @return array ['ok','body','status','error']
 */
function payment_http_post_form($url, array $fields) {
    $hedef = safe_remote_target($url);
    if ($hedef === false) {
        return ['ok' => false, 'body' => '', 'status' => 0, 'error' => 'Geçersiz veya izin verilmeyen sağlayıcı adresi.'];
    }
    $safe = $hedef['url'];
    $body = http_build_query($fields);
    $timeout = payment_timeout();

    if (function_exists('curl_init')) {
        $ch = curl_init($safe);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['content-type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_FOLLOWLOCATION => false,   // yönlendirme izlenmez: kalkan atlanamasın
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Manset/' . MANSET_VERSION,
        ]);
        if (function_exists('curl_pin_resolved_ip')) { curl_pin_resolved_ip($ch, $hedef); }
        $out = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out === false) { return ['ok' => false, 'body' => '', 'status' => $status, 'error' => 'Bağlantı hatası: ' . sanitize_line($err, 120)]; }
        if ($status >= 400) { return ['ok' => false, 'body' => (string)$out, 'status' => $status, 'error' => 'HTTP ' . $status]; }
        return ['ok' => true, 'body' => (string)$out, 'status' => $status, 'error' => ''];
    }

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content'       => $body,
        'timeout'       => $timeout,
        'follow_location' => 0,
        'ignore_errors' => true,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $out = @file_get_contents($safe, false, $ctx);
    if ($out === false) { return ['ok' => false, 'body' => '', 'status' => 0, 'error' => 'Bağlantı kurulamadı.']; }
    return ['ok' => true, 'body' => (string)$out, 'status' => 200, 'error' => ''];
}

// ============================================================ zamanlı görev

/**
 * Ölü bekleyen ödemeleri kapatır. Ağa çıkmaz, ucuz görevdir.
 * Abonelik verisine DOKUNMAZ; yalnız `pending` kayıtları `expired` yapar.
 */
function payment_cron_tick() {
    if (!payment_tables_ready()) { return 'ödeme: tablolar yok'; }
    $sinir = date('Y-m-d H:i:s', time() - PAYMENT_PENDING_TTL_DAYS * 86400);
    try {
        $n = q('UPDATE payments SET status = \'expired\', updated_at = :n
                WHERE status = \'pending\' AND created_at < :s',
            [':n' => now(), ':s' => $sinir])->rowCount();
    } catch (Throwable $e) {
        log_error('payment_cron_tick: ' . $e->getMessage());
        return 'ödeme: hata';
    }
    return 'ödeme: ' . (int)$n . ' bekleyen kayıt süre aşımına uğradı';
}

cron_register('odeme', 'payment_cron_tick', true);
