<?php
/**
 * Manşet — kamuya açık uç noktalar (Ajan-6).
 * Ad alanı: public.*  (CONTRACTS.md §7)
 *
 * Kayıtlı uçlar:
 *   public.comment          → ziyaretçi yorumu (hız sınırı + honeypot + KVKK onayı)
 *   public.search_suggest   → arama kutusu canlı önerisi (en çok 6 sonuç)
 *
 * Bu dosyadaki uçlar oturum gerektirmez (`perm => 'public'`), bu yüzden
 * her biri kendi hız sınırını uygular.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

/**
 * İstek gövdesinden alan okur.
 * JSON gövdesi önce, klasik form gönderimi yedek olarak denenir
 * (JS kapalıyken de aynı uç kullanılabilsin).
 */
function public_field($key, $default = '') {
    $body = json_body();
    if (array_key_exists($key, $body)) { return $body[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    return $default;
}

/** Gövdedeki bağlantı sayısı — basit spam sezgisi için. */
function public_link_count($text) {
    $n = preg_match_all('#(https?://|www\.)#i', (string)$text, $m);
    return $n === false ? 0 : (int)$n;
}

// ---------------------------------------------------------------- public.comment
/**
 * İstek : {post_id:int, name:string, email:string, body:string,
 *          kvkk:1, website:string (honeypot — dolu olmalı DEĞİL), _csrf:string}
 * Yanıt : {ok:true, message:'Yorumunuz alındı, editör onayından sonra yayımlanacak.'}
 *         moderasyon kapalıysa message = 'Yorumunuz yayımlandı.'
 * Hata  : {ok:false, error:'…'}  · 400 doğrulama · 429 hız sınırı
 *
 * Not: Spam sezgisine takılan ve honeypot'a düşen gönderimler de `ok:true`
 * döner — bota/spam robotuna geri bildirim verilmez.
 */
api_register('public.comment', function () {
    // 1.3-07 DEVRİ: yanıt zinciri, personel rozeti ve KARA LİSTE tek yerde
    // uygulanır. Bu uç 1.0'dan beri duruyor ve temalar artık `comments.submit`e
    // gönderiyor; ama uç KAYITLI kaldığı sürece üçüncü taraf bir istemci ya da
    // eski bir tema onu çağırabilir — ve bu sürüm kara listeyi BİLMİYOR.
    // Yani susturulmuş bir e-posta buradan yorum yazmaya devam ederdi.
    // Hız sınırı kovası ikisinde de aynı (`comment:<ip>`): ayrı olsaydı bir
    // robot iki ucu sırayla kullanıp sınırı ikiye katlardı.
    if (function_exists('comments_submit_handle')) {
        $r = comments_submit_handle('public_field');
        return ['message' => $r['message']];
    }

    $ip = client_ip();

    // 1) Hız sınırı — CONTRACTS §3: comment:<ip> 3 / 300 sn
    if (!rate_limit('comment:' . $ip, 3, 300)) {
        json_err('Çok sık yorum gönderiyorsunuz. Lütfen birkaç dakika sonra tekrar deneyin.', 429);
    }

    // 2) Honeypot — dolu ise sessizce "başarılı" dön, kaydetme
    $honey = trim((string)public_field('website', ''));
    if ($honey !== '') {
        return ['message' => 'Yorumunuz alındı, editör onayından sonra yayımlanacak.'];
    }

    // 3) Yorumlar açık mı?
    if (setting('comments_enabled', '1') !== '1') {
        json_err('Yorumlar şu anda kapalı.', 400);
    }

    // 4) Üyeliksiz yorum kapalıysa oturum şart (üyelik fazına hazırlık)
    $user = current_user();
    if (setting('comments_anonymous', '1') !== '1' && !$user) {
        json_err('Yorum yapabilmek için giriş yapmanız gerekiyor.', 401);
    }

    // 4b) YORUM YAZMA İZNİ (denetim turu 3, B07).
    // `comments.write` katalogda tanımlıydı ve rol matrisinde açılıp
    // kapatılabiliyordu ama HİÇBİR KAPIDA okunmuyordu: yönetici taciz eden
    // bir üyeyi susturmak için izni kapatıyor, kayıt yazılıyor ve hiçbir şey
    // olmuyordu. Matrisin sunduğu bir düğmenin işlevsiz olması, güvenlik
    // açığından beter: yayıncı önlem aldığını sanır.
    // Yalnız OTURUMLU kullanıcıya uygulanır; anonim yolu comments_anonymous yönetir.
    if ($user && !can($user, 'comments.write')) {
        json_err('Yorum yazma yetkiniz kaldırılmış.', 403);
    }

    // 5) KVKK onayı
    $kvkk = public_field('kvkk', '');
    $kvkkOk = ($kvkk === 1 || $kvkk === '1' || $kvkk === true || $kvkk === 'on' || $kvkk === 'true');
    if (!$kvkkOk) {
        json_err('Devam edebilmek için kişisel verilerin işlenmesini onaylamanız gerekiyor.', 400);
    }

    // 6) Haber var ve yayında mı?
    $postId = (int)public_field('post_id', 0);
    $post = $postId > 0 ? post_by_id($postId) : null;
    if (!$post) {
        json_err('Yorum yapılacak haber bulunamadı.', 404);
    }

    // 7) Alan doğrulaması
    // (sanitize_* kırpma yaptığı için üst sınır ham girdide denetlenir)
    $nameRaw = sanitize_line((string)public_field('name', ''), 200);
    if (mb_strlen($nameRaw) < 2)  { json_err('Adınız en az 2 karakter olmalı.', 400); }
    if (mb_strlen($nameRaw) > 60) { json_err('Adınız en fazla 60 karakter olabilir.', 400); }
    $name = $nameRaw;

    $email = trim((string)public_field('email', ''));
    if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('Geçerli bir e-posta adresi girin. (Yayımlanmaz.)', 400);
    }

    $body = sanitize_plain((string)public_field('body', ''), 4000);
    $len = mb_strlen($body);
    if ($len < 5)    { json_err('Yorumunuz en az 5 karakter olmalı.', 400); }
    if ($len > 2000) { json_err('Yorumunuz en fazla 2000 karakter olabilir.', 400); }

    // 8) Durum: moderasyon + basit spam sezgisi
    $moderate = setting('comments_moderate', '1') === '1';
    $status = $moderate ? 'pending' : 'approved';
    $spam = public_link_count($body) > 3;
    if ($spam) { $status = 'spam'; }

    // FAZ 7f — Oturum açmış üyenin kimliği HESABINDAN alınır; formdan gelen ad ve
    // e-posta yok sayılır. Aksi hâlde bir üye başkasının adıyla yorum yazabilirdi.
    if ($user) {
        $hesapAd = function_exists('member_display_name')
            ? member_display_name($user)
            : (string)arr($user, 'name', '');
        if (trim($hesapAd) !== '') { $name = sanitize_line($hesapAd, 60); }
        $hesapMail = (string)arr($user, 'email', '');
        if ($hesapMail !== '') { $email = $hesapMail; }
        // Doğrulanmış üyenin yorumu, ayar açıksa doğrudan yayına girer.
        if ($status === 'pending' && setting('comments_member_auto', '0') === '1'
            && function_exists('member_is_verified') && member_is_verified((int)$user['id'])) {
            $status = 'approved';
        }
    }

    db_insert('comments', [
        'post_id'    => (int)$post['id'],
        'user_id'    => $user ? (int)$user['id'] : 0,
        'name'       => $name,
        'email'      => $email,
        'body'       => $body,
        'ip'         => $ip,
        'status'     => $status,
        'created_at' => now(),
    ]);

    // Onaylı yorum doğrudan yayına girdiyse haberin önbelleği tazelenir
    if ($status === 'approved' && function_exists('cache_flush')) {
        cache_flush('post:' . (int)$post['id']);
    }

    // Spam'e düşen gönderime de normal mesaj verilir (geri bildirim sızdırılmaz)
    $message = ($moderate || $spam)
        ? 'Yorumunuz alındı, editör onayından sonra yayımlanacak.'
        : 'Yorumunuz yayımlandı.';

    return ['message' => $message];
}, ['perm' => 'public', 'methods' => ['POST']]);

// ---------------------------------------------------------------- public.search_suggest
/**
 * İstek : {q:string}   (en az 2 karakter)
 * Yanıt : {ok:true, items:[{title:string, url:string}]}   — en çok 6 kayıt
 *
 * Salt okunur bir uçtur; CSRF aranmaz. Böylece arama kutusu, oturum
 * açılmadan (ve sayfa önbelleği bozulmadan) çalışabilir.
 * Hız sınırı: search:<ip> 30 / 60 sn (CONTRACTS §3).
 */
api_register('public.search_suggest', function () {
    if (!rate_limit('search:' . client_ip(), 30, 60)) {
        json_err('Çok sık arama yapıyorsunuz. Bir dakika sonra tekrar deneyin.', 429);
    }

    $q = trim((string)public_field('q', ''));
    if ($q === '') { $q = trim((string)inp_s('q', '')); }
    $q = sanitize_line($q, 80);
    if (mb_strlen($q) < 2) { return ['items' => []]; }

    $res = search_posts($q, 1, 6);
    $items = [];
    foreach ($res['items'] as $p) {
        $items[] = [
            'title' => (string)$p['title'],
            'url'   => url_post($p),
        ];
        if (count($items) >= 6) { break; }
    }
    return ['items' => $items, 'total' => (int)$res['total']];
}, ['perm' => 'public', 'methods' => ['POST'], 'csrf' => false]);

/**
 * Tembel CSRF anahtarı — kamuya açık formlar için.
 * Sayfa HTML'i oturum açmadan basılır (önbelleğe alınabilir); anahtar yalnız
 * kullanıcı forma dokunduğunda bu uçtan alınır. Bkz. csrf_field_lazy().
 */
api_register('public.csrf', function () {
    return ['csrf' => csrf_public_token()];
}, ['perm' => 'public', 'methods' => ['GET', 'POST'], 'csrf' => false]);

/**
 * Görüntülenme sayacı — istemciden çağrılır (assets/site.js).
 *
 * Sunucu tarafında sayılsaydı post_register_view() her anonim ziyaretçiye
 * oturum çerezi verir ve haber sayfaları önbelleğe alınamazdı. Bu uç sayesinde
 * sayfa HTML'i oturumsuz üretilir, sayaç yalnız gerçek tarayıcılar için işler.
 * Tekilleştirme yine oturum bazlıdır (post_register_view içinde).
 */
api_register('public.view', function () {
    $d = json_body();
    $id = (int)(isset($d['id']) ? $d['id'] : inp_i('id', 0));
    if ($id <= 0) { return ['ok' => true, 'counted' => false]; }
    if (!rate_limit('view:' . client_ip(), 120, 300)) { return ['ok' => true, 'counted' => false]; }
    $row = q1('SELECT id FROM posts p WHERE p.id = :i AND ' . published_where(), [':i' => $id, ':nowts' => now()]);
    if (!$row) { return ['ok' => true, 'counted' => false]; }
    post_register_view($id);
    return ['ok' => true, 'counted' => true];
}, ['perm' => 'public', 'methods' => ['POST'], 'csrf' => false]);

/**
 * public.health — dış izleyici için sağlık özeti.
 *
 * ANAHTARLI: `health_key` ayarı boşsa uç KAPALIDIR (varsayılan kapalı).
 * Anahtar `?key=` ile ya da `X-Health-Key` başlığıyla verilir. Anahtarsız
 * açık bırakmak disk/sürüm/kuyruk bilgisini herkese açardı.
 *
 * Dönen alanlar bilinçli olarak SAYISAL/ÖZET — dosya yolu, sürüm dizini,
 * kullanıcı adı gibi ayrıntı sızdırmaz.
 */
api_register('public.health', function () {
    $anahtar = (string)setting('health_key', '');
    if ($anahtar === '') { json_err('Sağlık ucu kapalı.', 404); }

    // Hız sınırı ZORUNLU (denetim turu 3): anahtar kaba kuvvete açıktı ve
    // yanlış denemeler hiçbir yere yazılmıyordu. Diğer kimlik uçlarında
    // (panel girişi, cron) ikisi de var; burada yoktu.
    if (!rate_limit('health:' . client_ip(), 10, 300)) {
        json_err('Çok fazla deneme.', 429);
    }
    $gelen = (string)(isset($_GET['key']) ? $_GET['key'] : '');
    if ($gelen === '' && isset($_SERVER['HTTP_X_HEALTH_KEY'])) { $gelen = (string)$_SERVER['HTTP_X_HEALTH_KEY']; }
    if (!hash_equals($anahtar, $gelen)) {
        log_error('public.health: yanlış anahtar', client_ip());
        json_err('Yetkisiz.', 403);
    }

    $simdi = time();

    // Cron bayatlığı: son çalışma üstünden geçen dakika.
    $sonCron = (string)setting('cron_last_run', '');
    $cronYas = $sonCron !== '' ? max(0, (int)round(($simdi - strtotime($sonCron)) / 60)) : -1;

    // Disk ve veritabanı boyutu
    $bosDisk = disk_free_bytes(ROOT_DIR);
    $dbBoyut = 0;
    if (db_driver() === 'sqlite') {
        $yol = (string)cfg('db_path', DB_DIR . '/manset.sqlite');
        if (is_file($yol)) { $dbBoyut = (int)@filesize($yol); }
    }

    // Bekleyen kuyruklar (tablo yoksa 0)
    $sayac = function ($sql) {
        try { return (int)qv($sql, [], 0); } catch (Throwable $e) { return 0; }
    };

    return [
        'surum'          => MANSET_VERSION,
        'kurulu_surum'   => setting('installed_version', ''),
        'goc_bekliyor'   => function_exists('upgrade_pending') ? (upgrade_pending() ? 1 : 0) : 0,
        'cron_yas_dk'    => $cronYas,
        'disk_bos_mb'    => $bosDisk === null ? -1 : (int)round($bosDisk / 1048576),
        'db_mb'          => (int)round($dbBoyut / 1048576),
        'bekleyen_haber' => $sayac('SELECT COUNT(*) FROM posts WHERE status = \'pending\''),
        'bekleyen_yorum' => $sayac('SELECT COUNT(*) FROM comments WHERE status = \'pending\''),
        'ai_bekleyen'    => $sayac('SELECT COUNT(*) FROM ai_jobs WHERE status = \'pending\''),
        'ai_hatali'      => $sayac('SELECT COUNT(*) FROM ai_jobs WHERE status = \'failed\''),
        'zaman'          => now(),
    ];
}, ['perm' => 'public', 'methods' => ['GET'], 'csrf' => false]);