<?php
/**
 * Manşet — zamanlanmış görev girişi VE yeniden kullanılabilir cron kütüphanesi.
 *
 * Kullanım (hosting cron paneli):
 *   *\/10 * * * *  curl -s "https://siteniz.com/cron.php?key=CRON_ANAHTARI"
 * Komut satırından:  php cron.php --key=CRON_ANAHTARI
 *
 * Görevler: RSS çekimi → AI kuyruğu → zamanlanmış yayın → slug → önbellek → bakım.
 *
 * -------------------------------------------------------------------------
 * DOSYA İKİYE AYRILIR
 *   1) KÜTÜPHANE  — dosya nereden yüklenirse yüklensin yalnız işlev tanımlar.
 *   2) GİRİŞ      — YALNIZ cron.php doğrudan çağrıldığında çalışır
 *                   (anahtar denetimi → yükseltme kancası → cron_tick_all()).
 *
 * Başka bir dosyadan güvenle yüklenebilmesi için giriş bölümü hem
 * `MANSET_CRON_LIB` sabitine hem de "giriş betiği ben miyim" denetimine bakar.
 *
 * -------------------------------------------------------------------------
 * PANELDEN ÇAĞRI — "poor man's cron" (1.1-08)
 *
 * Gerçek cron kuramayan paylaşımlı hostingler için `cron_web_tick` ayarı
 * açıldığında görevler PANEL isteğinin SONUNDA bir kez tetiklenir.
 * ÖN YÜZDE ÇAĞRILMAZ: anonim istek süresi ve sayfa önbelleği korunmalıdır.
 *
 * Bağlama deseni (admin/index.php'nin EN SONU, çıktı gönderildikten sonra):
 *
 *     if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
 *     define('MANSET_CRON_LIB', 1);            // giriş bölümü çalışmasın
 *     require_once ROOT_DIR . '/cron.php';
 *     cron_web_tick_maybe();                   // ayar kapalıysa hiçbir şey yapmaz
 *
 * `cron_web_tick_maybe()` koşulları kendisi denetler: ayar açık mı, isteği
 * yapan kullanıcının YETKİSİ var mı, son deneme 15 dakikadan eski mi, kilit
 * boşta mı. Hiçbiri sağlanmazsa tek bir SELECT maliyetiyle geri döner.
 *
 * -------------------------------------------------------------------------
 * DENETİM TURU 3 DÜZELTMELERİ (1.1 ikinci dalga)
 *
 * B03 — Varsayılan 8 sn'lik web bütçesi RSS (20 sn rezerv) ve AI (45 sn rezerv)
 *       görevlerinin rezerv eşiğinin ALTINDAydı: görevler her turda iş yapmadan
 *       çıkıyor, buna rağmen `cron_last_run` damgası atılıyordu → `public.health`
 *       "sağlıklı" diyordu. Artık (a) web bütçesinin alt sınırı görevlerin
 *       çalışabileceği düzeye çekildi, (b) bir görev bütçe yüzünden HİÇ iş
 *       yapmadan çıktıysa tur "tam" sayılmaz ve `cron_last_run` damgası ATILMAZ;
 *       yalnız `cron_last_attempt` tazelenir. Sağlık göstergesi böylece dürüst
 *       kalır, panel de "web tetiklemesi yetersiz" uyarısını gösterebilir.
 *
 * B04 — Web tetiklemesi artık YETKİ ister (`tools.manage`). Eskiden en dar
 *       yetkili panel kullanıcısı (moderatör) bile RSS çekimini ve ücretli AI
 *       kuyruğunu başlatabiliyordu.
 *
 * B05 — Ölü kilit artık `@unlink` ile temizlenmiyor (unlink flock'u bozmaz,
 *       yeni inode üzerinde ikinci tur koşabiliyordu). flock'un kendisi yeterli.
 *
 * B07 — `fastcgi_finish_request()` yoksa (mod_php) bağlantı kapatılamaz; bu
 *       durumda YALNIZ ucuz/yan etkisiz görevler ve KATI bir süre sınırıyla
 *       çalışılır. `set_time_limit(0)` web yolunda artık kullanılmaz.
 *
 * -------------------------------------------------------------------------
 * AYARLAR (hepsi settings tablosunda, varsayılanlarıyla)
 *   cron_task_budget_sec  25   Görev başına azami süre (sn). Süre dolunca
 *                              RSS/AI döngüleri nazikçe durur, kalan iş bir
 *                              sonraki tura kalır.
 *   cron_web_tick         0    Panel isteği sonunda tetikleme (0 kapalı).
 *   cron_web_budget_sec   30   Panel tetiklemesinde görev başına azami süre.
 *                              CRON_WEB_BUDGET_MIN'in altına inemez: altında
 *                              RSS görevi rezerv eşiğini geçemez ve HİÇ çalışmaz.
 *
 * DAMGALAR (settings)
 *   cron_last_run       Son TAM tur (hiçbir görev bütçe yüzünden aç kalmadı).
 *   cron_last_attempt   Son deneme (tam olmasa da). Web kapısı bunu kullanır.
 *   cron_last_partial   Son eksik/kısmi tur.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/sanitize.php';

// ================================================================ sabitler

/**
 * Bu süreden uzun süren tur "uzun" sayılır (panelde uyarı için).
 * NOT (B05): artık kilit SİLMEK için kullanılmaz — ölü kilit temizliği kavramı
 * kaldırıldı, flock'un kendisi yeterlidir. Yalnız tanı/uyarı eşiğidir.
 */
if (!defined('CRON_LOCK_STALE_SEC'))  { define('CRON_LOCK_STALE_SEC', 600); }
/** cron_runs tablosunda tutulacak en çok kayıt. */
if (!defined('CRON_RUNS_KEEP'))       { define('CRON_RUNS_KEEP', 200); }
/** cron_runs kayıtlarının azami yaşı (gün). */
if (!defined('CRON_RUNS_KEEP_DAYS'))  { define('CRON_RUNS_KEEP_DAYS', 30); }
/** Panel tetiklemesi için en az bu kadar süre geçmiş olmalı (sn). */
if (!defined('CRON_WEB_TICK_AGE'))    { define('CRON_WEB_TICK_AGE', 900); }

/*
 * NEDEN (B03): 1.1'de web bütçesi varsayılan 8 sn'ydi; RSS görevi turun başında
 * RSS_TOTAL_TIMEOUT (20 sn) rezerv ister, AI görevi de çağrı zaman aşımı kadar.
 * 8 sn ile ikisi de HİÇ başlamıyordu. Alt sınır RSS rezervinin üstüne çekildi.
 */
/** Panel tetiklemesinde görev başına varsayılan süre (sn). */
if (!defined('CRON_WEB_BUDGET_DEFAULT')) { define('CRON_WEB_BUDGET_DEFAULT', 30); }
/** Panel tetiklemesinde görev bütçesinin alt sınırı (sn). */
if (!defined('CRON_WEB_BUDGET_MIN'))     { define('CRON_WEB_BUDGET_MIN', 22); }
/** Panel tetiklemesinde görev bütçesinin üst sınırı (sn). */
if (!defined('CRON_WEB_BUDGET_MAX'))     { define('CRON_WEB_BUDGET_MAX', 60); }
/*
 * NEDEN (B07): fastcgi_finish_request() yoksa (mod_php, php-cgi) yanıt gövdesi
 * gönderilmiş olsa bile bağlantı betik bitene kadar açık kalır ve PHP işçisi
 * meşguldür. Bu SAPI'lerde ağa çıkan/ücret üreten görevler ÇALIŞTIRILMAZ;
 * yalnız ucuz görevler, aşağıdaki KATI sınırlarla koşar.
 */
/** Bağlantı kapatılamayan SAPI'de görev başına azami süre (sn). */
if (!defined('CRON_WEB_CHEAP_BUDGET'))   { define('CRON_WEB_CHEAP_BUDGET', 3); }
/** Bağlantı kapatılamayan SAPI'de tur başına azami süre (sn). */
if (!defined('CRON_WEB_CHEAP_TOTAL'))    { define('CRON_WEB_CHEAP_TOTAL', 6); }
/** Kilit damgasının tazelenme aralığı (sn) — bkz. cron_lock_stamp(). */
if (!defined('CRON_LOCK_REFRESH_SEC'))   { define('CRON_LOCK_REFRESH_SEC', 30); }

// ================================================================ görev bütçesi
/*
 * Görev bütçesi tek bir "son an" (deadline) damgasıdır. cron_task() her görevin
 * başında damgayı kurar; RSS/AI döngüleri her yinelemede cron_over_budget()
 * sorar ve süre dolduysa kaldıkları yeri veritabanına bırakıp çıkarlar.
 * Böylece paylaşımlı hostingin max_execution_time sınırına yakalanmak yerine
 * iş bir sonraki turda kaldığı yerden sürer.
 */

/** Görev başına azami süre (saniye). */
function cron_budget_sec() {
    $v = (int)setting('cron_task_budget_sec', '25');
    if ($v <= 0) { $v = 25; }
    return max(5, min(300, $v));
}

/** Bütçe damgasını kurar. */
function cron_budget_start($sec = 0) {
    $sec = (int)$sec > 0 ? (int)$sec : cron_budget_sec();
    $GLOBALS['manset_cron_deadline'] = microtime(true) + $sec;
    return $GLOBALS['manset_cron_deadline'];
}

/** Bütçe damgasını kaldırır (görev dışı kod sınırsız çalışsın). */
function cron_budget_clear() { $GLOBALS['manset_cron_deadline'] = 0; }

/** Şu an bir bütçe yürürlükte mi? */
function cron_budget_active() {
    return isset($GLOBALS['manset_cron_deadline']) && $GLOBALS['manset_cron_deadline'] > 0;
}

/** Kalan süre (sn). Bütçe yoksa -1 (sınırsız). */
function cron_time_left() {
    if (!cron_budget_active()) { return -1.0; }
    return (float)$GLOBALS['manset_cron_deadline'] - microtime(true);
}

/**
 * Bütçe doldu mu? $reserve, "bir tur daha dönmek için gereken tahmini süre"dir;
 * döngüler bunu vererek yarıda kesilmek yerine baştan durabilir.
 */
function cron_over_budget($reserve = 0) {
    if (!cron_budget_active()) { return false; }
    // NEDEN (B05): uzun turlarda kilit dosyasının damgası tazelensin ki
    // "kaç dakikadır sürüyor" bilgisi izlenebilir kalsın (dosya silinmez).
    cron_lock_refresh();
    $doldu = (cron_time_left() - (float)$reserve) <= 0;
    // NEDEN (B03): bütçe yüzünden kesilen tur "tam tur" sayılmamalı; damga
    // atılırsa sağlık göstergesi hiç çalışmayan otomasyonu "sağlıklı" gösterir.
    if ($doldu) { $GLOBALS['manset_cron_budget_cut'] = true; }
    return $doldu;
}

/** Bu görevde/turda bütçe kesintisi yaşandı mı? */
function cron_budget_cut() { return !empty($GLOBALS['manset_cron_budget_cut']); }

/** Bütçe kesintisi bayrağını sıfırlar (görev/tur başında). */
function cron_budget_cut_reset() { $GLOBALS['manset_cron_budget_cut'] = false; }

// ================================================================ kilit
/*
 * NEDEN: cron.php 1.0'da kilitsizdi. Hosting cron'u 10 dakikada bir çağırırken
 * bir tur 10 dakikadan uzun sürerse (yavaş besleme, AI zaman aşımı) iki tur
 * üst üste biner ve aynı RSS ögesi iki kez alınır. flock bunu tek satırda
 * kapatır; kilit alınamayan tur sessizce ve BAŞARIYLA (çıkış kodu 0) döner —
 * hosting cron'u hata bildirimi yağdırmasın.
 */

/**
 * Kilidi almayı dener.
 * @return resource|false Kilit tutamacı ya da alınamadıysa false
 */
function cron_lock_open() {
    $path = DB_DIR . '/.cronlock';
    if (!is_dir(DB_DIR)) { @mkdir(DB_DIR, 0755, true); }

    /*
     * NEDEN (B05): 1.1'de mtime'ı CRON_LOCK_STALE_SEC'ten eski olan kilit
     * @unlink ile siliniyordu. Bu YARIŞ AÇIYORDU: unlink flock'u bozmaz,
     * yalnız dosya ADINI kaldırır; A süreci hâlâ 1 numaralı inode'u kilitli
     * tutarken B süreci aynı adda YENİ bir inode açıp onun üzerinde kilidi
     * alabiliyordu → iki tur aynı anda koşuyordu. Üstelik damga tur boyunca
     * tazelenmediği için 10 dakikayı aşan HER meşru tur "ölü" görünüyordu.
     *
     * flock zaten yarışsızdır ve süreç ölürse çekirdek kilidi kendiliğinden
     * bırakır; "ölü kilit temizliği" diye bir şeye gerek yoktur. Bu yüzden
     * kilit dosyası ARTIK HİÇ SİLİNMEZ (inode sabit kalır), içeriği ise tur
     * boyunca cron_lock_refresh() ile tazelenir.
     */
    $fh = @fopen($path, 'c');
    if (!$fh) { log_error('cron kilidi açılamadı: ' . $path); return false; }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) { fclose($fh); return false; }

    $GLOBALS['manset_cron_lock'] = $fh;
    cron_lock_stamp($fh);
    return $fh;
}

/**
 * Kilit dosyasına "hâlâ yaşıyorum" damgası yazar. Dosya SİLİNMEZ, yalnız
 * içeriği kısaltılıp yeniden yazılır; inode değişmediği için başka bir sürecin
 * flock'u geçersizleşmez.
 */
function cron_lock_stamp($fh) {
    if (!is_resource($fh)) { return; }
    @ftruncate($fh, 0);
    @rewind($fh);
    @fwrite($fh, now() . ' pid=' . (function_exists('getmypid') ? (int)getmypid() : 0) . "\n");
    @fflush($fh);
    @touch(DB_DIR . '/.cronlock');
    $GLOBALS['manset_cron_lock_ts'] = time();
}

/** Damgayı en çok CRON_LOCK_REFRESH_SEC'te bir tazeler (döngülerden çağrılır). */
function cron_lock_refresh() {
    if (empty($GLOBALS['manset_cron_lock']) || !is_resource($GLOBALS['manset_cron_lock'])) { return; }
    $son = isset($GLOBALS['manset_cron_lock_ts']) ? (int)$GLOBALS['manset_cron_lock_ts'] : 0;
    if ((time() - $son) < CRON_LOCK_REFRESH_SEC) { return; }
    cron_lock_stamp($GLOBALS['manset_cron_lock']);
}

/** Kilidi bırakır. Dosya silinmez (bkz. cron_lock_open() NEDEN notu). */
function cron_lock_close($fh) {
    if (is_resource($fh)) { @flock($fh, LOCK_UN); @fclose($fh); }
    $GLOBALS['manset_cron_lock'] = null;
    $GLOBALS['manset_cron_lock_ts'] = 0;
}

/**
 * Kilidin içindeki damgadan turun ne zaman başladığını okur (panel/tanı için).
 * @return string 'Y-m-d H:i:s' ya da ''
 */
function cron_lock_started_at() {
    $path = DB_DIR . '/.cronlock';
    if (!is_file($path)) { return ''; }
    $s = (string)@file_get_contents($path, false, null, 0, 64);
    return preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $s, $m) ? $m[1] : '';
}

// ================================================================ görev sarmalayıcısı

/** Metnin başındaki ilk tam sayıyı verir ("12 yeni öge" → 12). */
function cron_first_int($text) {
    return preg_match('/\d+/', (string)$text, $m) ? (int)$m[0] : 0;
}

/**
 * Görevi çalıştırır, raporu toplar, cron_runs'a yazar; hata tüm turu düşürmez.
 *
 * Görev şunları döndürebilir:
 *   - dize  : rapor notu; işlenen öge sayısı metindeki ilk sayıdan okunur,
 *   - dizi  : ['note'=>string, 'items'=>int, 'ok'=>bool] (denetim tam sizde).
 *
 * @return array cron_runs satırı
 */
function cron_task($label, $fn, $budgetSec = 0) {
    if (!isset($GLOBALS['manset_cron_report'])) { $GLOBALS['manset_cron_report'] = []; }
    if (!isset($GLOBALS['manset_cron_runs']))   { $GLOBALS['manset_cron_runs'] = []; }

    $label = (string)$label;
    if (!is_callable($fn)) {
        $GLOBALS['manset_cron_report'][] = $label . ': atlandı (modül yok)';
        return ['task' => $label, 'ok' => 1, 'items' => 0, 'ms' => 0, 'note' => 'atlandı (modül yok)'];
    }

    $startedAt = now();
    $t = microtime(true);
    $ok = 1;
    $items = 0;
    $note = '';

    cron_budget_start($budgetSec);
    // NEDEN (B03): bu görev bütçe yüzünden HİÇ iş yapmadan mı çıktı? Turun
    // "tam" sayılıp sayılmayacağı buna bakar (bkz. cron_tick_all damgaları).
    cron_budget_cut_reset();
    try {
        $res = $fn();
        if (is_array($res)) {
            $items = (int)arr($res, 'items', 0);
            $note  = (string)arr($res, 'note', '');
            if (array_key_exists('ok', $res)) { $ok = arr($res, 'ok') ? 1 : 0; }
            if ($note === '') { $note = (string)json_encode($res, JSON_UNESCAPED_UNICODE); }
        } else {
            $note = is_scalar($res) ? (string)$res : '';
            $items = cron_first_int($note);
        }
        if (trim($note) === '' || $note === 'null') { $note = 'tamam'; }
    } catch (Throwable $e) {
        $ok = 0;
        $note = 'HATA — ' . $e->getMessage();
        log_error('cron[' . $label . '] ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    }
    // "Aç kaldı": bütçe kesildi VE görev hiç iş yapamadı. Sağlıklı bir turda
    // bütçe kesintisi olabilir (ör. 3 kaynak çekildi, 4.'ye süre yetmedi);
    // asıl sorun görevin turun başında rezervi geçemeyip HİÇ başlayamamasıdır.
    $ac = (cron_budget_cut() && $items <= 0) ? 1 : 0;
    cron_budget_clear();

    $ms = (int)round((microtime(true) - $t) * 1000);
    $run = ['task' => $label, 'started_at' => $startedAt, 'ok' => $ok,
            'items' => $items, 'ms' => $ms, 'note' => $note, 'ac' => $ac];

    $GLOBALS['manset_cron_report'][] = $label . ': ' . $note . ' (' . $ms . ' ms)';
    $GLOBALS['manset_cron_runs'][] = $run;
    cron_run_record($run);
    return $run;
}

/**
 * Görev sonucunu cron_runs tablosuna yazar.
 * GERİYE DÖNÜK UYUM: göç 013 uygulanmadıysa tablo yoktur; sessizce atlanır.
 */
function cron_run_record(array $run) {
    static $varMi = null;
    if ($varMi === null) {
        $varMi = function_exists('schema_has_table') ? schema_has_table('cron_runs') : false;
    }
    if (!$varMi) { return; }
    try {
        db_insert('cron_runs', [
            'task'        => mb_substr((string)arr($run, 'task', ''), 0, 40),
            'started_at'  => (string)arr($run, 'started_at', now()),
            'finished_at' => now(),
            'ok'          => (int)arr($run, 'ok', 0),
            'items'       => (int)arr($run, 'items', 0),
            'ms'          => (int)arr($run, 'ms', 0),
            'note'        => mb_substr(sanitize_line((string)arr($run, 'note', ''), 600), 0, 600),
        ]);
    } catch (Throwable $e) {
        log_error('cron_runs yazılamadı: ' . $e->getMessage());
    }
}

/** cron_runs budaması: son CRON_RUNS_KEEP kayıt ya da CRON_RUNS_KEEP_DAYS gün. */
function cron_runs_prune() {
    if (!function_exists('schema_has_table') || !schema_has_table('cron_runs')) { return 0; }
    $n = 0;
    try {
        $n += q('DELETE FROM cron_runs WHERE started_at < :c',
            [':c' => date('Y-m-d H:i:s', time() - CRON_RUNS_KEEP_DAYS * 86400)])->rowCount();
        // MySQL aynı tablodan alt sorguyla silmeye izin vermez: eşik iki adımda bulunur.
        $maxId = (int)qv('SELECT MAX(id) FROM cron_runs', [], 0);
        if ($maxId > CRON_RUNS_KEEP) {
            $n += q('DELETE FROM cron_runs WHERE id <= :i', [':i' => $maxId - CRON_RUNS_KEEP])->rowCount();
        }
    } catch (Throwable $e) {
        log_error('cron_runs budama: ' . $e->getMessage());
    }
    return $n;
}

/** Son cron görev kayıtları (panel sağlık kartı için). */
function cron_runs_recent($limit = 20) {
    if (!function_exists('schema_has_table') || !schema_has_table('cron_runs')) { return []; }
    $limit = max(1, min(200, (int)$limit));
    try {
        return qa('SELECT * FROM cron_runs ORDER BY id DESC LIMIT ' . $limit);
    } catch (Throwable $e) { return []; }
}

// ================================================================ modüller

/** Görevlerin ihtiyaç duyduğu modülleri bir kez yükler. */
function cron_modules_load() {
    static $yuklendi = false;
    if ($yuklendi) { return; }
    $yuklendi = true;

    $v = __DIR__ . '/inc/view.php';
    if (is_file($v)) { require_once $v; }
    // Not: 'ai_jobs' burada olmazsa hem YZ kuyruğu işlenmez hem de inc/rss.php
    // içindeki ai_job_enqueue() kancası sessizce atlanır (Ajan-5 raporu, Faz 2).
    foreach (['rss', 'ai', 'ai_jobs', 'cache', 'seo', 'widgets', 'kunye', 'media', 'backup', 'members'] as $mod) {
        $f = __DIR__ . '/inc/' . $mod . '.php';
        if (is_file($f)) { require_once $f; }
    }
}

// ================================================================ tur

/**
 * Bütün görevleri bir kez çalıştırır. Kilit, bütçe, cron_runs kaydı ve
 * yükseltme kancası içeridedir; çağıran yalnız sonucu okur.
 *
 * @param array $secenekler [
 *     'kilit'   => bool     (varsayılan true)   kilit alınamazsa hiç çalışma
 *     'butce'   => int      (varsayılan 0)      görev başına saniye; 0 = ayardan
 *     'toplam'  => int      (varsayılan 0)      TUR başına azami saniye; 0 = sınırsız
 *     'sadece'  => string[] (varsayılan null)   yalnız bu görevleri çalıştır
 *     'kaynak'  => string   (varsayılan 'cron') 'cron' | 'panel'
 *     'yukselt' => bool     (varsayılan true)   bekleyen göçü uygula
 * ]
 * @return array ['ok'=>bool,'calisti'=>bool,'tam'=>bool,'neden'=>string,'ms'=>int,
 *                'rapor'=>string[],'gorevler'=>array[],'atlanan'=>string[],
 *                'ac'=>string[],'ozet'=>string]
 */
function cron_tick_all(array $secenekler = []) {
    $kilitli  = arr($secenekler, 'kilit', true) !== false;
    $butce    = (int)arr($secenekler, 'butce', 0);
    $toplam   = (int)arr($secenekler, 'toplam', 0);
    $kaynak   = (string)arr($secenekler, 'kaynak', 'cron');
    $yukselt  = arr($secenekler, 'yukselt', true) !== false;

    $sadece = arr($secenekler, 'sadece', null);
    $sadece = is_array($sadece) ? array_map('strval', $sadece) : null;

    $sonuc = ['ok' => false, 'calisti' => false, 'tam' => false, 'neden' => '', 'ms' => 0,
              'rapor' => [], 'gorevler' => [], 'atlanan' => [], 'ac' => [], 'ozet' => ''];

    $kilit = null;
    if ($kilitli) {
        $kilit = cron_lock_open();
        if ($kilit === false) {
            $sonuc['ok'] = true;                 // hata değil: başka tur sürüyor
            $sonuc['neden'] = 'zaten çalışıyor';
            $sonuc['ozet'] = 'Cron zaten çalışıyor; bu tur atlandı.';
            return $sonuc;
        }
    }

    $GLOBALS['manset_cron_report'] = [];
    $GLOBALS['manset_cron_runs'] = [];
    $started = microtime(true);

    try {
        // YÜKSELTME KANCASI — görevlerden ÖNCE: yeni sütun/tablolar hazır olsun.
        if ($yukselt && MANSET_INSTALLED && function_exists('upgrade_pending') && upgrade_pending()) {
            upgrade_run();
        }

        cron_modules_load();

        $liste = [
            // 1) RSS kaynaklarından çekim
            'rss'         => function_exists('rss_cron_tick') ? 'rss_cron_tick' : null,
            // 2) AI iş kuyruğu (eşzamanlılık sınırı modülün içinde)
            'ai'          => function_exists('ai_jobs_tick') ? 'ai_jobs_tick' : null,
            // 3) Zamanı gelen taslakların yayımı
            'zamanlanmis' => 'cron_publish_scheduled',
            // 4) Slug geçmişi eşitleme (eski adreslerin 301 ile korunması için)
            'slug'        => function_exists('seo_sync_slug_history') ? 'seo_sync_slug_history' : null,
            // 5) Önbellek süpürme
            'cache'       => function_exists('cache_sweep') ? 'cache_sweep' : null,
            // 6) Bakım: eski hız sınırı kayıtları, log ve cron geçmişi budaması
            'bakim'       => 'cron_maintenance',
        ];

        // NEDEN (B07): tur başına toplam süre sınırı. Panel yolunda PHP işçisinin
        // 6 görev × görev bütçesi kadar (dakikalarca) tutulmasını engeller.
        $turSonu = ($toplam > 0) ? (microtime(true) + $toplam) : 0.0;

        foreach ($liste as $ad => $fn) {
            if ($sadece !== null && !in_array($ad, $sadece, true)) {
                $sonuc['atlanan'][] = $ad;
                continue;
            }
            if ($turSonu > 0 && microtime(true) >= $turSonu) {
                $sonuc['atlanan'][] = $ad;
                $GLOBALS['manset_cron_report'][] = $ad . ': atlandı (tur süresi doldu)';
                continue;
            }
            // Görev bütçesi tur bütçesini AŞAMAZ.
            $gButce = $butce;
            if ($turSonu > 0) {
                $kalan = (int)floor($turSonu - microtime(true));
                $gButce = ($gButce > 0) ? min($gButce, max(1, $kalan)) : max(1, $kalan);
            }
            $run = cron_task($ad, $fn, $gButce);
            if (!empty($run['ac'])) { $sonuc['ac'][] = $ad; }
        }

        $sonuc['calisti'] = true;
        $sonuc['ok'] = true;
        // TAM TUR: her görev çalıştı ve hiçbiri bütçe yüzünden aç kalmadı.
        $sonuc['tam'] = (!$sonuc['atlanan'] && !$sonuc['ac']);
    } catch (Throwable $e) {
        $sonuc['neden'] = $e->getMessage();
        log_error('cron turu düştü: ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    }

    cron_budget_clear();
    $sonuc['ms'] = (int)round((microtime(true) - $started) * 1000);
    $sonuc['rapor'] = $GLOBALS['manset_cron_report'];
    $sonuc['gorevler'] = $GLOBALS['manset_cron_runs'];
    $sonuc['ozet'] = now() . ' cron turu (' . $sonuc['ms'] . ' ms, ' . $kaynak
                   . ($sonuc['tam'] ? '' : ', EKSİK TUR') . ') — '
                   . implode(' | ', $sonuc['rapor']);
    if (!$sonuc['tam'] && $sonuc['calisti']) {
        $eksik = array_merge($sonuc['ac'], $sonuc['atlanan']);
        $sonuc['ozet'] .= ' || çalışamayan görev(ler): ' . implode(', ', array_unique($eksik))
                        . ' — süre bütçesi yetersiz; gerçek cron kurmanız önerilir.';
    }

    try {
        log_error('CRON ' . $sonuc['ozet']);
        /*
         * NEDEN (B03): `cron_last_run` damgası eskiden KOŞULSUZ atılıyordu.
         * RSS ve AI görevleri bütçe yüzünden hiç çalışamasa bile damga tazeleniyor,
         * `public.health` "cron_yas_dk" küçük olduğu için sistemi SAĞLIKLI
         * gösteriyordu → otomasyonun sessizce durduğu fark edilmiyordu.
         * Artık `cron_last_run` YALNIZ tam turda tazelenir. Her deneme ise
         * `cron_last_attempt`e yazılır: web tetikleme kapısı bunu kullanır,
         * böylece eksik turlar her istekte yeniden denenip siteyi yavaşlatmaz.
         */
        setting_set('cron_last_attempt', now());
        setting_set('cron_last_report', implode("\n", $sonuc['rapor']));
        setting_set('cron_last_source', $kaynak);
        if ($sonuc['tam']) {
            setting_set('cron_last_run', now());
        } else {
            setting_set('cron_last_partial', now());
        }
    } catch (Throwable $e) {
        log_error('cron damgası yazılamadı: ' . $e->getMessage());
    }

    cron_lock_close($kilit);
    return $sonuc;
}

/**
 * "Poor man's cron" (1.1-08). PANEL isteğinin sonunda çağrılır; ön yüzde ASLA.
 * Ayar kapalıysa ya da son tur 15 dakikadan yeniyse hiçbir şey yapmaz.
 *
 * @return array cron_tick_all() sonucu (çalışmadıysa 'calisti' => false)
 */
function cron_web_tick_maybe() {
    $bos = ['ok' => true, 'calisti' => false, 'tam' => false, 'neden' => '', 'ms' => 0,
            'rapor' => [], 'gorevler' => [], 'atlanan' => [], 'ac' => [], 'ozet' => ''];

    if (!MANSET_INSTALLED) { $bos['neden'] = 'kurulum yok'; return $bos; }
    if (setting('cron_web_tick', '0') !== '1') { $bos['neden'] = 'kapalı'; return $bos; }

    /*
     * YETKİ KAPISI — NEDEN (B04): bu işlev yalnız ayara ve zamana bakıyordu,
     * isteği YAPANA bakmıyordu. Sonuç: `rss.manage`, `ai.use` ve `tools.manage`
     * izinlerinin hiçbirine sahip olmayan `moderator` rolü, hatta 403 aldığı bir
     * panel sayfasını isteyerek bile, dış ağa çıkan RSS çekimini ve ÜCRETLİ AI
     * kuyruğunu başlatabiliyordu (para harcatma + yetkisiz kaynak tüketimi).
     *
     * Kapı olarak `tools.manage` seçildi: cron/bakım/araçlar ekranının izni
     * budur, yani "zamanlanmış görevleri yönetmek" kavramsal olarak oraya aittir.
     * `rss.manage` ya da `ai.use` seçilseydi tur, o izne sahip ama bakımdan
     * sorumlu olmayan kullanıcılarca da tetiklenirdi; `admin.access` ise
     * B04'ün ta kendisi olurdu (moderatör de ona sahip).
     */
    $u = function_exists('current_user_if_session') ? current_user_if_session() : null;
    if (!function_exists('can') || !can($u, 'tools.manage')) {
        $bos['neden'] = 'yetki yok';
        return $bos;
    }

    // 15 dakika kapısı: TAM tur damgası değil, son DENEME damgası kullanılır.
    // (B03 sonrası eksik turlarda cron_last_run tazelenmez; kapı ona bakarsaydı
    //  her panel isteği yeni bir tur denerdi.)
    $son = trim((string)setting('cron_last_attempt', ''));
    if ($son === '') { $son = trim((string)setting('cron_last_run', '')); }
    if ($son !== '') {
        $ts = strtotime($son);
        if ($ts !== false && (time() - $ts) < CRON_WEB_TICK_AGE) {
            $bos['neden'] = 'daha yeni çalıştı';
            return $bos;
        }
    }

    $d = cron_web_tick_plan();

    /*
     * NEDEN (B07): `set_time_limit(0)` web yolunda ARTIK KULLANILMIYOR. Sınırsız
     * çalışma hakkı, takılan bir görevle birlikte PHP işçisini süresiz meşgul
     * ediyordu. Sınır tur bütçesinden türetilir; `ignore_user_abort` ise yalnız
     * bağlantı gerçekten kapatılabildiğinde (fastcgi_finish_request) anlamlıdır.
     */
    @set_time_limit((int)$d['toplam'] + 30);
    if (!empty($d['arka_plan'])) { @ignore_user_abort(true); }

    return cron_tick_all([
        'butce'  => (int)$d['butce'],
        'toplam' => (int)$d['toplam'],
        'sadece' => $d['sadece'],
        'kaynak' => 'panel',
    ]);
}

/** Panel tetiklemesi bağlantıyı kapatıp arka planda çalışabiliyor mu? */
function cron_web_background_ok() {
    return function_exists('fastcgi_finish_request');
}

/**
 * Ucuz görevler: ağa çıkmaz, ücret üretmez, milisaniyeler sürer.
 * Bağlantı kapatılamayan SAPI'lerde (mod_php) YALNIZ bunlar çalıştırılır.
 */
function cron_web_cheap_tasks() { return ['zamanlanmis', 'slug', 'cache', 'bakim']; }

/**
 * Panel tetiklemesinin çalışma planı (bütçe, tur sınırı, görev kümesi).
 * Panelde de gösterilebilsin diye ayrı işlev.
 *
 * @return array ['arka_plan'=>bool,'mod'=>'tam'|'ucuz','butce'=>int,'toplam'=>int,'sadece'=>array|null]
 */
function cron_web_tick_plan() {
    $arkaPlan = cron_web_background_ok();

    if (!$arkaPlan) {
        // mod_php / php-cgi: bağlantı betik bitene kadar açık kalır.
        return ['arka_plan' => false, 'mod' => 'ucuz',
                'butce' => CRON_WEB_CHEAP_BUDGET, 'toplam' => CRON_WEB_CHEAP_TOTAL,
                'sadece' => cron_web_cheap_tasks()];
    }

    $butce = (int)setting('cron_web_budget_sec', (string)CRON_WEB_BUDGET_DEFAULT);
    if ($butce <= 0) { $butce = CRON_WEB_BUDGET_DEFAULT; }
    // Alt sınır CRON_WEB_BUDGET_MIN: altında RSS görevi rezerv eşiğini geçemez
    // ve turun hiçbirinde çalışmaz (B03).
    $butce = max(CRON_WEB_BUDGET_MIN, min(CRON_WEB_BUDGET_MAX, $butce));

    return ['arka_plan' => true, 'mod' => 'tam',
            'butce' => $butce, 'toplam' => min(180, $butce * 3), 'sadece' => null];
}

/**
 * Panel/tanı için web tetikleme durumu. Kullanıcıya "neden haberler gelmiyor"
 * sorusunun yanıtını verir (B03/B07 görünürlüğü).
 *
 * @return array ['acik','arka_plan','mod','butce','toplam','son_tam_tur',
 *                'son_deneme','son_eksik','uyari']
 */
function cron_web_tick_status() {
    $acik = (setting('cron_web_tick', '0') === '1');
    $plan = cron_web_tick_plan();

    $uyari = '';
    if ($acik && $plan['mod'] === 'ucuz') {
        $uyari = 'Bu sunucuda arka plan tetiklemesi desteklenmiyor (fastcgi_finish_request yok). '
               . 'Panel isteğiyle yalnız zamanlanmış yayın, önbellek ve bakım görevleri çalışır; '
               . 'RSS çekimi ve yapay zekâ kuyruğu ÇALIŞMAZ. Bunlar için hosting panelinden '
               . 'gerçek bir cron görevi kurmanız gerekir.';
    } elseif ($acik) {
        $uyari = 'Panel tetiklemesi açık: görevler panel isteğinden sonra arka planda çalışır. '
               . 'Bu bir cron yerine geçmez; sitenize kimse panelden girmezse hiçbir görev çalışmaz.';
    }

    return [
        'acik'        => $acik,
        'arka_plan'   => (bool)$plan['arka_plan'],
        'mod'         => $acik ? $plan['mod'] : 'kapali',
        'butce'       => (int)$plan['butce'],
        'toplam'      => (int)$plan['toplam'],
        'son_tam_tur' => (string)setting('cron_last_run', ''),
        'son_deneme'  => (string)setting('cron_last_attempt', ''),
        'son_eksik'   => (string)setting('cron_last_partial', ''),
        'uyari'       => $uyari,
    ];
}

// ================================================================ görevler

/** published_at zamanı gelen 'scheduled' haberleri yayına alır. */
function cron_publish_scheduled() {
    $rows = qa('SELECT id FROM posts WHERE status = \'scheduled\' AND published_at IS NOT NULL AND published_at <= :n',
        [':n' => now()]);
    foreach ($rows as $r) {
        q('UPDATE posts SET status = \'published\', updated_at = :u WHERE id = :i', [':u' => now(), ':i' => (int)$r['id']]);
    }
    if ($rows && function_exists('cache_flush')) { cache_flush(); }
    return count($rows) . ' haber yayına alındı';
}

/** Eski kayıtların temizliği ve vitrin tutarlılığı. */
function cron_maintenance() {
    $n = 0;
    try {
        // Yayından çıkmış haberler manşette/editör seçiminde asılı kalmasın
        $n += q('UPDATE posts SET is_headline = 0, headline_sort = 0, is_breaking = 0
                 WHERE status <> \'published\' AND (is_headline = 1 OR is_breaking = 1)')->rowCount();
        $picks = array_values(array_filter(array_map('intval', explode(',', (string)setting('editor_picks', '')))));
        if ($picks) {
            $rows = qa('SELECT id FROM posts WHERE status = \'published\' AND id IN (' . implode(',', $picks) . ')');
            $live = array_map(function ($r) { return (int)$r['id']; }, $rows);
            $kept = array_values(array_intersect($picks, $live));
            if (count($kept) !== count($picks)) { setting_set('editor_picks', implode(',', $kept)); $n++; }
        }
        $n += q('DELETE FROM rate_limits WHERE created_at < :c', [':c' => date('Y-m-d H:i:s', time() - 86400)])->rowCount();
        $n += q('DELETE FROM ai_logs WHERE created_at < :c', [':c' => date('Y-m-d H:i:s', time() - 60 * 86400)])->rowCount();
        $n += q('DELETE FROM rss_items WHERE status IN (\'processed\',\'skipped\') AND fetched_at < :c',
            [':c' => date('Y-m-d H:i:s', time() - 45 * 86400)])->rowCount();
        $n += cron_runs_prune();
    } catch (Throwable $e) { log_error('cron bakım: ' . $e->getMessage()); }
    return $n . ' eski kayıt silindi';
}

// ================================================================ GİRİŞ
/*
 * Buradan aşağısı YALNIZ cron.php doğrudan çağrıldığında çalışır. Dosya
 * başka bir betikten yüklendiğinde (panel tetiklemesi) yukarıdaki işlevler
 * tanımlanır ve dosya sessizce biter.
 */

$mansetCronGiris = !defined('MANSET_CRON_LIB');
if ($mansetCronGiris) {
    $mansetCronBetik = isset($_SERVER['SCRIPT_FILENAME']) ? @realpath((string)$_SERVER['SCRIPT_FILENAME']) : '';
    $mansetCronBen = @realpath(__FILE__);
    if ($mansetCronBetik !== '' && $mansetCronBetik !== false && $mansetCronBen !== false) {
        $mansetCronGiris = ($mansetCronBetik === $mansetCronBen);
    }
}

if ($mansetCronGiris) {
    if (!MANSET_INSTALLED) {
        http_response_code(503);
        echo "Kurulum tamamlanmamış.\n";
        exit;
    }

    $isCli = (PHP_SAPI === 'cli');
    $givenKey = '';
    if ($isCli) {
        foreach ($argv as $a) { if (strpos($a, '--key=') === 0) { $givenKey = substr($a, 6); } }
    } else {
        $givenKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
    }

    $cronKey = (string)cfg('cron_key', '');
    if ($cronKey === '' || !hash_equals($cronKey, $givenKey)) {
        if (!$isCli) {
            // Kaba kuvvet denemesini yavaşlat
            rate_limit('cron:' . client_ip(), 20, 300);
            http_response_code(403);
        }
        echo "Geçersiz cron anahtarı.\n";
        log_error('cron.php geçersiz anahtar', client_ip());
        exit;
    }

    @set_time_limit(0);
    if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

    // YÜKSELTME KANCASI (1.1) — panel kancasıyla aynı desen, görevlerden ÖNCE.
    if (MANSET_INSTALLED && function_exists('upgrade_pending') && upgrade_pending()) {
        upgrade_run();
    }

    $mansetCronSonuc = cron_tick_all(['kaynak' => 'cron']);

    if (!$mansetCronSonuc['calisti']) {
        // Kilit alınamadı ya da tur düştü: hosting cron'una hata döndürmeyiz.
        echo ($mansetCronSonuc['ozet'] !== '' ? $mansetCronSonuc['ozet'] : $mansetCronSonuc['neden']) . "\n";
        exit(0);
    }

    echo $mansetCronSonuc['ozet'] . "\n";
}
