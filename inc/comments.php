<?php
/**
 * Manşet — yorum sistemi v2 (1.3-07, Ajan-B).
 *
 * Beş iş, tek modül:
 *   1. TEK SEVİYE YANIT   `parent_id` — yanıtın yanıtı KÖKE bağlanır.
 *   2. SAYFALAMA          uzun tartışma haber sayfasını şişirmez.
 *   3. ÜYE / PERSONEL ROZETİ  yorum satırından üretilir, oturum AÇMADAN.
 *   4. BİLDİR             anonim, çerezsiz, hız sınırlı.
 *   5. KARA LİSTE         e-posta / IP / sözcük → eşleşen yorum doğrudan `spam`.
 *
 * -----------------------------------------------------------------------------
 * NEDEN ZİNCİR TEK SEVİYE
 * -----------------------------------------------------------------------------
 * Derin zincir iki şeyi birden bozar. Moderasyon tarafında: bir kökü spam
 * yapmak altındaki bütün dalı sessizce görünmez kılar, moderatör neyin neden
 * kaybolduğunu göremez. Okuma tarafında: 320px genişlikte üçüncü girinti
 * seviyesi satır başına 4-5 karakter bırakır. İkisi de kod yazılarak
 * düzeltilebilecek şeyler değil, veri modelinin sonucudur — bu yüzden model
 * sınırlanır.
 *
 * Sınır DB kısıtıyla değil, TEK GİRİŞ NOKTASIYLA uygulanır
 * (`comments_parent_resolve()`): yanıtın yanıtı reddedilmez, kökün altına
 * taşınır. Reddetmek okur için anlamsız bir hata mesajıdır ("neden olmuyor?");
 * köke taşımak beklediği sonucu verir ve derinliği 1'de tutar.
 *
 * -----------------------------------------------------------------------------
 * YANIT ZİNCİRİ GÜVENLİĞİ — üç saldırı, üç kapı
 * -----------------------------------------------------------------------------
 *   a) BAŞKA HABERİN YORUMUNA YANIT.  `parent_id` istekten gelir; ham hâliyle
 *      yazılsaydı A haberinin altına B haberinin yorumu iliştirilebilirdi.
 *      Kapı: ebeveyn satırı YÜKLENİR ve `post_id` eşitliği aranır.
 *      İkinci kapı okuma tarafında: `comments_thread()` yanıtları da
 *      `post_id = :p` süzgeciyle çeker, yani kayıt bir şekilde yanlış habere
 *      bağlansa bile o haberin altında GÖRÜNMEZ.
 *   b) SİLİNMİŞ / SPAM / BEKLEYEN YORUMA YANIT.  Ebeveyn `approved` değilse
 *      yanıt kabul edilmez. Aksi hâlde moderatörün gizlediği bir yorum,
 *      altına yazılan alıntılı yanıtla geri getirilebilirdi.
 *   c) KENDİNE YANIT / DÖNGÜ.  Yeni kayıt henüz var olmadığı için kendini
 *      gösteremez; yine de okuma tarafında `id <> parent_id` süzgeci var
 *      (elle kurcalanmış bir satır sonsuz döngü kurmasın).
 *
 * -----------------------------------------------------------------------------
 * ÇEREZSİZLİK (CONTRACTS §3.1)
 * -----------------------------------------------------------------------------
 *   · Rozet yorum satırından okunur; `current_user()` ÇAĞRILMAZ.
 *   · Sayfalama sunucuda basılır ve mevcut `?sayfa=` parametresini kullanır —
 *     bu parametre `cache_query_whitelist()` içinde olduğu için ikinci yorum
 *     sayfası da önbelleğe girebilir. Yeni bir parametre uydurmak sayfayı
 *     önbellek dışına atardı.
 *   · Bildir ucu CSRF İSTEMEZ. İstese `public.csrf` çağrılırdı, o da oturum
 *     açar; oturum açan okur önbellekten hiç yararlanamaz. Aynı ödünç
 *     `public.view` ucunda da verildi ve NOTES.md'de kayıtlıdır. Karşılığında
 *     uç dar tutuldu: yalnız sayaç artırır, hiçbir yorumu yayından kaldırmaz.
 *
 * -----------------------------------------------------------------------------
 * KARA LİSTE VE IP (KVKK)
 * -----------------------------------------------------------------------------
 * `comments.ip` ham IP tutmaya devam ediyor — bu NOTES.md'de kayıtlı, bilinen
 * ve bu iş paketinde DEĞİŞTİRİLMEYEN bir sınırdır. Kara listenin `ip` türü bu
 * ham değeri KULLANIR: gelen isteğin `client_ip()` çıktısı, kayıtlı desenle
 * karşılaştırılır (tam eşleşme ya da `1.2.3.` biçiminde önek). Yeni bir yerde
 * IP SAKLANMAZ — kara liste satırında duran şey yöneticinin ELLE girdiği
 * desendir, otomatik toplanmış bir ziyaretçi izi değil.
 * Yeni gelen `comment_reports` tablosu ise ham IP tutmaz (bkz. göç 024).
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ ayarlar

/** Bir yorum sayfasında kaç KÖK yorum gösterilir. */
function comments_per_page() {
    $n = (int)setting('comments_per_page', '20');
    return $n >= 5 && $n <= 200 ? $n : 20;
}

/** Yanıtlar açık mı? */
function comments_replies_enabled() { return setting('comments_replies', '1') === '1'; }

/** Bildir düğmesi açık mı? */
function comments_report_enabled() { return setting('comments_report', '1') === '1'; }

/**
 * Kaç bildirimde yorum otomatik beklemeye alınsın? 0 = hiç (VARSAYILAN).
 *
 * NEDEN VARSAYILAN KAPALI: eşik açıkken, örgütlü birkaç kişi beğenmediği her
 * yorumu yayından kaldırabilir — bildir düğmesi sansür düğmesine dönüşür.
 * Kuyruk zaten moderatöre gösteriliyor; kararı insan versin. Yayıncı isterse
 * eşiği açabilir, ama bilerek açmış olur.
 */
function comments_report_threshold() {
    $n = (int)setting('comments_report_threshold', '0');
    return $n > 0 && $n <= 100 ? $n : 0;
}

/** Göç 024 uygulandı mı? (eski kurulumda modül sessizce v1 gibi davranır) */
function comments_supports_v2() {
    static $var = null;
    if ($var === null) {
        $var = function_exists('schema_has_column') && schema_has_column('comments', 'parent_id');
    }
    return $var;
}

// ============================================================ rozet

/**
 * Yorum satırından rozet üretir. VERİTABANINA VE OTURUMA DOKUNMAZ.
 *
 * @return array|null ['etiket' => 'Editör', 'ton' => 'olumlu', 'tur' => 'personel']
 */
function comments_badge($row) {
    $rol = trim((string)arr((array)$row, 'author_role', ''));
    if ($rol === '') { return null; }               // konuk yorumu — rozet yok
    if ($rol === 'member') {
        return ['etiket' => 'Üye', 'ton' => 'bilgi', 'tur' => 'uye'];
    }
    // Personel rolleri: etiketi rol kataloğundan al, yoksa genel "Personel".
    $personel = function_exists('role_is_staff') ? role_is_staff($rol) : false;
    if (!$personel) { return null; }
    $etiket = function_exists('role_label') ? (string)role_label($rol) : '';
    if ($etiket === '' || $etiket === $rol) { $etiket = 'Personel'; }
    return ['etiket' => $etiket, 'ton' => 'olumlu', 'tur' => 'personel'];
}

/**
 * Yorumla birlikte SAKLANACAK rol anlık görüntüsü.
 * Personel olmayan oturumlu kullanıcı 'member' sayılır; konuk boş dize alır.
 */
function comments_author_role($user) {
    if (!$user || !is_array($user)) { return ''; }
    $rol = (string)arr($user, 'role', '');
    if ($rol === '') { return ''; }
    if (function_exists('role_exists') && !role_exists($rol)) { return ''; }
    // Panel erişimi geri alınmış personel hesabı rozet TAŞIMAMALI (CONTRACTS §3.2:
    // is_staff bayrağı bütün izinleri kapatır; rozet de bir yetki göstergesidir).
    if (function_exists('role_is_staff') && role_is_staff($rol) && (int)arr($user, 'is_staff', 0) !== 1) {
        return 'member';
    }
    return $rol;
}

// ============================================================ kara liste

/** Kara liste satırları (bellekte tutulur; istek başına bir sorgu). */
function comments_blacklist_all($reload = false) {
    static $liste = null;
    if ($liste === null || $reload) {
        $liste = [];
        try {
            if (!function_exists('schema_has_table') || schema_has_table('comment_blacklist')) {
                $liste = qa('SELECT id, kind, value, note, created_by, created_at
                    FROM comment_blacklist ORDER BY kind ASC, value ASC');
            }
        } catch (Throwable $e) { $liste = []; }
    }
    return $liste;
}

/**
 * Sözcük karşılaştırması için Türkçe katlaması.
 *
 * NEDEN GEREKLİ: `mb_strtolower('İ')` UTF-8'de 'i' + birleşen nokta üretir,
 * yani "BAHİS" küçültüldüğünde "bahis" ile EŞİT OLMAZ. Bu bir incelik değil,
 * kuralın Türkçe metinde sessizce hiç çalışmaması demektir — birim test tam
 * bunu yakaladı.
 *
 * Katlama i/ı/İ/I harflerini tek harfe indirir. Yan etkisi bilinçlidir:
 * spam gönderen "bahıs" yazarak kuralı atlatamaz.
 */
function comments_fold($s) {
    $s = strtr((string)$s, [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ş' => 'ş', 'Ğ' => 'ğ', 'Ü' => 'ü', 'Ö' => 'ö', 'Ç' => 'ç',
    ]);
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** Kara liste değerini normalleştirir (karşılaştırma daima küçük harf). */
function comments_blacklist_norm($kind, $value) {
    $v = trim((string)$value);
    if ($v === '') { return ''; }
    if ($kind === 'ip') {
        return substr(strtolower(preg_replace('/[^0-9a-f:.\*]/i', '', $v)), 0, 190);
    }
    // E-posta ve sözcük aynı katlamadan geçer: e-posta yerel kısmında da
    // Türkçe harf bulunabilir ve iki taraf aynı işlemden geçmelidir.
    return substr(comments_fold($v), 0, 190);
}

/**
 * Gönderim kara listeye takılıyor mu?
 *
 * @return string Eşleşen kuralın kısa açıklaması ('' = temiz).
 */
function comments_blacklist_match($email, $ip, $body, $name = '') {
    $e = comments_blacklist_norm('email', $email);
    $i = comments_blacklist_norm('ip', $ip);

    // ARANAN METİN KIRPILMAZ (denetim turu 5, D2-02).
    // `comments_blacklist_norm()` girdiyi 190 bayta kırpar — bu, KURAL DEĞERİ
    // için doğrudur (sütun sınırı), ama İÇİNDE ARANAN metin için felakettir:
    // yasaklı sözcüğü 190. bayttan sonra yazan bir yorum hem gönderim anında hem
    // cron süpürgesinde görünmez oluyordu. Denetçi ölçtü: aynı sözcük kısa
    // yorumda `spam`, dolgu metniyle uzatılmış yorumda `approved` ve yayında.
    //
    // Moderasyon aracının sessizce çalışmaması, hiç olmamasından beterdir:
    // yayıncı sözcüğü yasakladığını sanır.
    $metin = comments_fold($name . ' ' . $body);

    foreach (comments_blacklist_all() as $k) {
        $tur = (string)$k['kind'];
        $desen = (string)$k['value'];
        if ($desen === '') { continue; }

        if ($tur === 'email') {
            // Tam adres ya da '@alanadi.com' biçiminde alan adı kuralı.
            if ($e === $desen) { return 'e-posta: ' . $desen; }
            if ($desen[0] === '@' && $e !== '' && substr($e, -strlen($desen)) === $desen) {
                return 'e-posta alanı: ' . $desen;
            }
            continue;
        }
        if ($tur === 'ip') {
            // Tam eşleşme ya da '1.2.3.' biçiminde önek (sondaki nokta ZORUNLU;
            // '1.2.3' deseni 11.2.3x adresini yakalamasın diye).
            if ($i === $desen) { return 'IP: ' . $desen; }
            if (substr($desen, -1) === '.' && $i !== '' && strpos($i, $desen) === 0) {
                return 'IP öneki: ' . $desen;
            }
            continue;
        }
        // word — sözcük/ifade içerme. Girdi zaten sanitize_plain'den geçtiği için
        // HTML yok; düz alt dize araması yeterli ve öngörülebilir.
        if ($metin !== '' && strpos($metin, $desen) !== false) {
            return 'sözcük: ' . $desen;
        }
    }
    return '';
}

/** Kara listeye kayıt ekler. @return array ['ok'=>bool,'error'=>string,'id'=>int] */
function comments_blacklist_add($kind, $value, $note = '', $userId = 0) {
    $kind = in_array($kind, ['email', 'ip', 'word'], true) ? $kind : '';
    if ($kind === '') { return ['ok' => false, 'error' => 'Geçersiz kara liste türü.']; }
    $v = comments_blacklist_norm($kind, $value);
    if ($v === '') { return ['ok' => false, 'error' => 'Boş bir desen kaydedilemez.']; }
    // Tek karakterlik sözcük kuralı bütün siteyi susturur; bu bir kaza, kural değil.
    if ($kind === 'word' && mb_strlen($v) < 3) {
        return ['ok' => false, 'error' => 'Sözcük kuralı en az 3 karakter olmalı.'];
    }
    $var = (int)qv('SELECT id FROM comment_blacklist WHERE kind = :k AND value = :v',
        [':k' => $kind, ':v' => $v], 0);
    if ($var > 0) { return ['ok' => false, 'error' => 'Bu desen zaten kara listede.', 'id' => $var]; }

    $id = db_insert('comment_blacklist', [
        'kind'       => $kind,
        'value'      => $v,
        'note'       => sanitize_line((string)$note, 200),
        'created_by' => (int)$userId,
        'created_at' => now(),
    ]);
    comments_blacklist_all(true);
    return ['ok' => true, 'id' => (int)$id, 'error' => ''];
}

/** Kara liste kaydını siler. */
function comments_blacklist_remove($id) {
    $id = (int)$id;
    if ($id <= 0) { return false; }
    $n = q('DELETE FROM comment_blacklist WHERE id = :id', [':id' => $id])->rowCount();
    comments_blacklist_all(true);
    return $n > 0;
}

// ============================================================ yanıt zinciri

/**
 * İstenen `parent_id` için GÜVENLİ kök kimliğini çözer.
 *
 * @return int  0 = üst düzey yorum · >0 = geçerli kök · -1 = reddedilmeli
 */
function comments_parent_resolve($postId, $parentId) {
    $postId   = (int)$postId;
    $parentId = (int)$parentId;
    if ($parentId <= 0) { return 0; }
    if (!comments_supports_v2() || !comments_replies_enabled()) { return -1; }

    $p = q1('SELECT id, post_id, parent_id, status FROM comments WHERE id = :id', [':id' => $parentId]);
    if (!$p) { return -1; }                                   // silinmiş / hiç olmamış
    if ((int)$p['post_id'] !== $postId) { return -1; }         // BAŞKA HABERİN yorumu
    if ((string)$p['status'] !== 'approved') { return -1; }    // bekleyen / spam
    // Yanıtın yanıtı: köke bağla (derinlik daima 1).
    $kok = (int)$p['parent_id'];
    if ($kok > 0 && $kok !== (int)$p['id']) {
        $g = q1('SELECT id, post_id, status, parent_id FROM comments WHERE id = :id', [':id' => $kok]);
        if (!$g || (int)$g['post_id'] !== $postId || (string)$g['status'] !== 'approved') { return -1; }
        return (int)$g['id'];
    }
    return (int)$p['id'];
}

// ============================================================ gönderim

/**
 * Ortak yorum gönderim mantığı. `comments.submit` ucundan çağrılır.
 * Hata durumunda json_err() ile SONLANIR (uçların sözleşmesi budur).
 *
 * @return array ['message'=>string, 'status'=>string]
 */
function comments_submit_handle($alan) {
    $ip = client_ip();

    // 1) Hız sınırı — CONTRACTS §3.1: comment:<ip> 3/300 sn. Kova adı
    //    public.comment ile ORTAK: iki uç aynı bütçeyi paylaşmalı, yoksa
    //    spam robotu ikisini sırayla kullanıp sınırı ikiye katlar.
    if (!rate_limit('comment:' . $ip, 3, 300)) {
        json_err('Çok sık yorum gönderiyorsunuz. Lütfen birkaç dakika sonra tekrar deneyin.', 429);
    }

    // 2) Honeypot — dolu ise sessizce "başarılı" dön, kaydetme.
    if (trim((string)$alan('website', '')) !== '') {
        return ['message' => 'Yorumunuz alındı, editör onayından sonra yayımlanacak.', 'status' => 'spam'];
    }

    if (setting('comments_enabled', '1') !== '1') { json_err('Yorumlar şu anda kapalı.', 400); }

    $user = current_user();
    if (setting('comments_anonymous', '1') !== '1' && !$user) {
        json_err('Yorum yapabilmek için giriş yapmanız gerekiyor.', 401);
    }
    if ($user && !can($user, 'comments.write')) {
        json_err('Yorum yazma yetkiniz kaldırılmış.', 403);
    }

    // 3) KVKK onayı
    $kvkk = $alan('kvkk', '');
    if (!($kvkk === 1 || $kvkk === '1' || $kvkk === true || $kvkk === 'on' || $kvkk === 'true')) {
        json_err('Devam edebilmek için kişisel verilerin işlenmesini onaylamanız gerekiyor.', 400);
    }

    // 4) Haber
    $postId = (int)$alan('post_id', 0);
    $post = $postId > 0 ? post_by_id($postId) : null;
    if (!$post) { json_err('Yorum yapılacak haber bulunamadı.', 404); }
    $postId = (int)$post['id'];

    // 5) Yanıt zinciri
    $parentId = comments_parent_resolve($postId, $alan('parent_id', 0));
    if ($parentId < 0) {
        json_err('Yanıtlamak istediğiniz yorum artık yayında değil. Sayfayı yenileyip tekrar deneyin.', 400);
    }

    // 6) Alanlar
    $nameRaw = sanitize_line((string)$alan('name', ''), 200);
    if (mb_strlen($nameRaw) < 2)  { json_err('Adınız en az 2 karakter olmalı.', 400); }
    if (mb_strlen($nameRaw) > 60) { json_err('Adınız en fazla 60 karakter olabilir.', 400); }
    $name = $nameRaw;

    $email = trim((string)$alan('email', ''));
    if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('Geçerli bir e-posta adresi girin. (Yayımlanmaz.)', 400);
    }

    $body = sanitize_plain((string)$alan('body', ''), 4000);
    $len = mb_strlen($body);
    if ($len < 5)    { json_err('Yorumunuz en az 5 karakter olmalı.', 400); }
    if ($len > 2000) { json_err('Yorumunuz en fazla 2000 karakter olabilir.', 400); }

    // 7) Kimlik: oturumlu kullanıcının adı/e-postası HESABINDAN alınır
    //    (FAZ 7f — formdan gelen ad yok sayılır, yoksa başkasının adıyla yazılır).
    $rol = '';
    if ($user) {
        $hesapAd = function_exists('member_display_name')
            ? member_display_name($user) : (string)arr($user, 'name', '');
        if (trim($hesapAd) !== '') { $name = sanitize_line($hesapAd, 60); }
        $hesapMail = (string)arr($user, 'email', '');
        if ($hesapMail !== '') { $email = $hesapMail; }
        $rol = comments_author_role($user);
    }

    // 8) Durum
    $moderate = setting('comments_moderate', '1') === '1';
    $status = $moderate ? 'pending' : 'approved';

    // Bağlantı yoğunluğu — public.comment ile aynı sezgi.
    $baglanti = preg_match_all('#(https?://|www\.)#i', $body, $m);
    if ($baglanti !== false && $baglanti > 3) { $status = 'spam'; }

    // KARA LİSTE — her şeyin üstünde. Personel dâhil kimse muaf değildir:
    // muafiyet, ele geçirilmiş bir personel hesabını kara listenin dışına
    // çıkarırdı ve tam da o hesap en çok zarar verebilecek olandır.
    $kural = comments_blacklist_match($email, $ip, $body, $name);
    if ($kural !== '') { $status = 'spam'; }

    if ($user && $status === 'pending' && setting('comments_member_auto', '0') === '1'
        && function_exists('member_is_verified') && member_is_verified((int)$user['id'])) {
        $status = 'approved';
    }

    $satir = [
        'post_id'    => $postId,
        'user_id'    => $user ? (int)$user['id'] : 0,
        'name'       => $name,
        'email'      => $email,
        'body'       => $body,
        'ip'         => $ip,
        'status'     => $status,
        'created_at' => now(),
    ];
    if (comments_supports_v2()) {
        $satir['parent_id']   = $parentId;
        $satir['author_role'] = $rol;
    }
    db_insert('comments', $satir);

    // 9) Önbellek — yorum yayına girdiyse haberin sayfası bayatladı.
    //    cache_flush() imzası Ajan-D'nin; yalnız ÇAĞIRIYORUZ.
    if ($status === 'approved' && function_exists('cache_flush')) {
        cache_flush('post:' . $postId);
    }

    $message = ($status !== 'approved')
        ? 'Yorumunuz alındı, editör onayından sonra yayımlanacak.'
        : 'Yorumunuz yayımlandı.';
    // Spam'e düşene de aynı mesaj verilir: kara listeye takıldığını söylemek,
    // spam gönderene hangi sözcüğün yasaklı olduğunu öğretmektir.
    return ['message' => $message, 'status' => $status];
}

// ============================================================ okuma (ön yüz)

/** Bir haberin onaylı yorum sayısı (yanıtlar dâhil). */
function comments_count_all($postId) {
    return (int)qv('SELECT COUNT(*) FROM comments WHERE post_id = :p AND status = \'approved\'',
        [':p' => (int)$postId], 0);
}

/**
 * Sayfalanmış yorum ağacı. Derinlik en çok 1.
 *
 * @return array ['items'=>[['comment'=>[], 'replies'=>[]]], 'total'=>int (kök),
 *                'count'=>int (tümü), 'page'=>int, 'pages'=>int, 'per_page'=>int]
 */
function comments_thread($postId, $page = 1, $perPage = 0) {
    $postId  = (int)$postId;
    $perPage = (int)$perPage > 0 ? (int)$perPage : comments_per_page();
    $page    = max(1, (int)$page);

    $sutunlar = comments_supports_v2()
        ? 'id, name, body, created_at, author_role, parent_id'
        : 'id, name, body, created_at';
    $kokSuzgec = comments_supports_v2() ? ' AND parent_id = 0' : '';

    $total = (int)qv('SELECT COUNT(*) FROM comments
        WHERE post_id = :p AND status = \'approved\'' . $kokSuzgec, [':p' => $postId], 0);
    $pages = max(1, (int)ceil($total / $perPage));
    if ($page > $pages) { $page = $pages; }
    $offset = ($page - 1) * $perPage;

    // LIMIT/OFFSET tamsayıya zorlanmış yerel değerlerdir (bazı sürücüler
    // bunları bağlı parametre olarak kabul etmez).
    $koklar = $total > 0 ? qa('SELECT ' . $sutunlar . ' FROM comments
        WHERE post_id = :p AND status = \'approved\'' . $kokSuzgec . '
        ORDER BY created_at ASC, id ASC
        LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset, [':p' => $postId]) : [];

    $items = [];
    $indeks = [];
    foreach ($koklar as $k) {
        $items[] = ['comment' => $k, 'replies' => []];
        $indeks[(int)$k['id']] = count($items) - 1;
    }

    if ($indeks && comments_supports_v2()) {
        $ph = [];
        $par = [':p' => $postId];
        $i = 0;
        foreach (array_keys($indeks) as $kid) {
            $ad = ':k' . $i; $ph[] = $ad; $par[$ad] = (int)$kid; $i++;
        }
        // post_id süzgeci BİLEREK burada da var: kayıt bir şekilde yanlış habere
        // bağlanmış olsa bile bu haberin altında görünmez (ikinci kapı).
        // id <> parent_id: elle kurcalanmış bir satır kendi kendinin yanıtı olmasın.
        $yanitlar = qa('SELECT ' . $sutunlar . ' FROM comments
            WHERE post_id = :p AND status = \'approved\'
              AND id <> parent_id
              AND parent_id IN (' . implode(', ', $ph) . ')
            ORDER BY created_at ASC, id ASC', $par);
        foreach ($yanitlar as $y) {
            $pid = (int)$y['parent_id'];
            if (!isset($indeks[$pid])) { continue; }
            $items[$indeks[$pid]]['replies'][] = $y;
        }
    }

    return [
        'items'    => $items,
        'total'    => $total,
        'count'    => comments_count_all($postId),
        'page'     => $page,
        'pages'    => $pages,
        'per_page' => $perPage,
    ];
}

// ============================================================ bildir

/**
 * Bildiren kişinin takma kimliği. HAM IP DEĞİL, geri döndürülemez özet.
 * Tuz `app_key`tir: veritabanını ele geçiren biri IPv4 uzayını tarayarak
 * bildirenleri çözemez, çünkü anahtar config.php'dedir.
 */
function comments_reporter_hash($ip) {
    $anahtar = (string)cfg('app_key', '') . '|yorum-bildirim';
    return substr(hash_hmac('sha256', (string)$ip, $anahtar), 0, 32);
}

/** Bildirim gerekçeleri (panelde okunur etiketleriyle). */
function comments_report_reasons() {
    return [
        'hakaret' => 'Hakaret / nefret söylemi',
        'spam'    => 'Spam / reklam',
        'alakasiz'=> 'Konuyla alakasız',
        'diger'   => 'Diğer',
    ];
}

/**
 * Okurun bildirimini kaydeder. Hata durumunda json_err() ile sonlanır.
 * @return array ['message'=>string]
 */
function comments_report_handle($commentId, $reason) {
    $ip = client_ip();
    // Hız sınırı — CONTRACTS §3.2: önce yaz, sonra say (rate_limit bunu yapar).
    if (!rate_limit('comment_report:' . $ip, 10, 300)) {
        json_err('Çok fazla bildirim gönderdiniz. Lütfen biraz sonra tekrar deneyin.', 429);
    }
    if (!comments_report_enabled()) { json_err('Bildirim özelliği kapalı.', 400); }
    if (!comments_supports_v2())    { json_err('Bildirim özelliği kullanılamıyor.', 400); }

    $id = (int)$commentId;
    $c = $id > 0 ? q1('SELECT id, post_id, status FROM comments WHERE id = :id', [':id' => $id]) : null;
    // Yalnız YAYINDA olan yorum bildirilebilir. Aksi hâlde uç, bekleyen bir
    // yorumun var olup olmadığını sızdıran bir sorgulama aracına dönerdi.
    if (!$c || (string)$c['status'] !== 'approved') {
        json_err('Bildirilecek yorum bulunamadı.', 404);
    }

    $gerekce = (string)$reason;
    if (!isset(comments_report_reasons()[$gerekce])) { $gerekce = 'diger'; }
    $hash = comments_reporter_hash($ip);

    try {
        db_insert('comment_reports', [
            'comment_id'    => $id,
            'reporter_hash' => $hash,
            'reason'        => $gerekce,
            'created_at'    => now(),
        ]);
    } catch (Throwable $e) {
        // Benzersiz indeks: aynı kişi aynı yorumu ikinci kez bildirdi.
        // Ona da "aldık" denir — kaç kişinin bildirdiği bilgisi sızmasın.
        return ['message' => 'Bildiriminiz alındı. Teşekkür ederiz.'];
    }

    $sayi = (int)qv('SELECT COUNT(*) FROM comment_reports WHERE comment_id = :c', [':c' => $id], 0);
    db_update('comments', ['report_count' => $sayi, 'reported_at' => now()], 'id = :id', [':id' => $id]);

    // Eşik AÇIKSA yorum beklemeye alınır (varsayılan kapalı — bkz. yukarıdaki not).
    $esik = comments_report_threshold();
    if ($esik > 0 && $sayi >= $esik) {
        db_update('comments', ['status' => 'pending'], 'id = :id AND status = \'approved\'', [':id' => $id]);
        if (function_exists('cache_flush')) { cache_flush('post:' . (int)$c['post_id']); }
    }

    return ['message' => 'Bildiriminiz alındı. Teşekkür ederiz.'];
}

// ============================================================ moderasyon yardımcıları

/**
 * "Spam + askıya al": yorumu spam yapar VE yazarını kara listeye alır.
 *
 * Tek düğme olmasının nedeni sıralamadır: iki ayrı adımda moderatör önce
 * yorumu siler, sonra e-postayı kopyalayacak yer kalmaz — pratikte askıya alma
 * adımı hep atlanır ve aynı kişi beş dakika sonra geri gelir.
 *
 * @return array ['ok'=>bool,'error'=>string,'kurallar'=>[], 'yanit'=>int]
 */
function comments_spam_and_suspend($commentId, $userId = 0, $ipDe = true) {
    $id = (int)$commentId;
    $c = $id > 0 ? q1('SELECT id, post_id, email, ip, parent_id FROM comments WHERE id = :id', [':id' => $id]) : null;
    if (!$c) { return ['ok' => false, 'error' => 'Yorum bulunamadı.', 'kurallar' => [], 'yanit' => 0]; }

    db_update('comments', ['status' => 'spam'], 'id = :id', [':id' => $id]);

    // Kök yorum spam'e düşerse altındaki yanıtlar bağlamsız kalır ve ön yüzde
    // zaten görünmez; onları da spam'e almak panelde tutarlı bir tablo verir.
    $yanit = 0;
    if (comments_supports_v2() && (int)$c['parent_id'] === 0) {
        $yanit = q('UPDATE comments SET status = \'spam\' WHERE parent_id = :id AND id <> :id2',
            [':id' => $id, ':id2' => $id])->rowCount();
    }

    $kurallar = [];
    $e = comments_blacklist_add('email', (string)$c['email'], 'Yorum #' . $id . ' ile askıya alındı', $userId);
    if (!empty($e['ok'])) { $kurallar[] = 'e-posta'; }
    if ($ipDe) {
        $i = comments_blacklist_add('ip', (string)$c['ip'], 'Yorum #' . $id . ' ile askıya alındı', $userId);
        if (!empty($i['ok'])) { $kurallar[] = 'IP'; }
    }

    if (function_exists('cache_flush')) { cache_flush('post:' . (int)$c['post_id']); }
    return ['ok' => true, 'error' => '', 'kurallar' => $kurallar, 'yanit' => (int)$yanit];
}

/** Bir yorumun bildirim dökümü (panel için). */
function comments_reports_for($commentId) {
    if (!comments_supports_v2()) { return []; }
    return qa('SELECT reason, COUNT(*) AS adet FROM comment_reports
        WHERE comment_id = :c GROUP BY reason ORDER BY adet DESC', [':c' => (int)$commentId]);
}

/** Bildirim sayacını sıfırlar (moderatör "incelendi" der). */
function comments_reports_clear($commentId) {
    $id = (int)$commentId;
    if ($id <= 0 || !comments_supports_v2()) { return false; }
    q('DELETE FROM comment_reports WHERE comment_id = :c', [':c' => $id]);
    db_update('comments', ['report_count' => 0, 'reported_at' => ''], 'id = :id', [':id' => $id]);
    return true;
}

// ============================================================ cron

/**
 * Kara liste süpürgesi.
 *
 * NEDEN GEREKLİ: kara liste gönderim ANINDA uygulanır, ama iki durum bunu
 * atlar. (1) Yayıncı bir deseni yorum GELDİKTEN SONRA ekler — zaten yayında
 * olan aynı spam kalır. (2) `public.comment` ucu (Ajan-6'nın dosyası) hâlâ
 * kendi eski yolundan yazıyor ve kara listeyi bilmiyor; o uç
 * `comments_submit_handle()`e devredene kadar bu süpürge tek kapıdır.
 *
 * Ucuz görev: ağa çıkmaz, yalnız son 500 kaydı tarar.
 */
function comments_cron_blacklist_sweep() {
    if (!comments_supports_v2()) { return 'yorum kara listesi: göç uygulanmamış'; }
    if (!comments_blacklist_all()) { return 'yorum kara listesi: kural yok'; }

    $satirlar = qa('SELECT id, post_id, name, email, body, ip FROM comments
        WHERE status IN (\'pending\', \'approved\') ORDER BY id DESC LIMIT 500');
    $sayi = 0;
    $kapsam = [];
    foreach ($satirlar as $s) {
        if (comments_blacklist_match((string)$s['email'], (string)$s['ip'],
                                     (string)$s['body'], (string)$s['name']) === '') { continue; }
        db_update('comments', ['status' => 'spam'], 'id = :id', [':id' => (int)$s['id']]);
        $kapsam['post:' . (int)$s['post_id']] = true;
        $sayi++;
    }
    if ($sayi && function_exists('cache_flush')) {
        foreach (array_keys($kapsam) as $k) { cache_flush($k); }
    }
    return 'yorum kara listesi: ' . $sayi . ' kayıt spam yapıldı';
}

cron_register('yorum-kara-liste', 'comments_cron_blacklist_sweep', true);
