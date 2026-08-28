<?php
/**
 * Manşet — çerezsiz, kendi barındırdığımız analitik (1.2-01) ve panel
 * "bekleyen iş" rozetleri + sağlık ölçümleri (1.2-02).  Sahip: Ajan-A.
 *
 * ---------------------------------------------------------------------------
 * NEDEN BU MODÜL VAR
 * ---------------------------------------------------------------------------
 * Yayıncının "dün kaç kişi geldi, trafiğin ne kadarı aramadan geliyor, mobil
 * okur oranı nedir" sorularına bugün yanıt veren tek şey `posts.view_count`
 * — tek bir kümülatif sayı. Dışarıdan bir analitik takmak ise ziyaretçiye
 * üçüncü taraf çerezi verir; bu hem CONTRACTS §3.1'i (anonim ziyaretçi ÇEREZ
 * ALMAZ) hem sayfa önbelleğini öldürür.
 *
 * ---------------------------------------------------------------------------
 * KVKK — NE SAKLANMAZ (bu modülün en önemli kısmı)
 * ---------------------------------------------------------------------------
 *  · HAM IP hiçbir sütuna yazılmaz. Tekilleştirme için GÜNLÜK DEĞİŞEN bir
 *    tuzla üretilmiş 16 haneli özet kullanılır; tuz her gün yenilenir ve
 *    eskisi silinir, yani dünkü özetler bugün artık bir IP'ye bağlanamaz ve
 *    günler arası birleştirilemez (kalıcı bir ziyaretçi kimliği YOKTUR).
 *  · HAM REFERRER URL saklanmaz — yalnız tür (arama/sosyal/doğrudan/iç/diğer).
 *    Ham adres okurun hangi forumda ya da hangi arama teriminde olduğunu
 *    sızdırır; tür bu bilgiyi taşımaz.
 *  · HAM İSTEK SATIRI, USER-AGENT, ekran ölçüsü, dil, saat dilimi saklanmaz.
 *    Cihazdan geriye yalnız iki değerli bir sınıf kalır (mobil/masaüstü).
 *  · Bu modül ziyaretçiye ÇEREZ VERMEZ ve oturum AÇMAZ. `session_boot()`
 *    çağrısı bilerek yoktur.
 *
 * Yazma yolu: `post_register_view()` (inc/view.php) → `analytics_record_view()`.
 * O kanca zaten IP + haber temelli 6 saatlik bir tekilleştirme kapısının
 * ARKASINDADIR; bu modül aynı kapıyı miras alır, ikinci bir kapı kurmaz.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ sabitler

/** Yönlendiren türleri — DB'de saklanan ASCII kodlar. */
function analytics_ref_types() {
    return [
        'arama'      => 'Arama motoru',
        'sosyal'     => 'Sosyal medya',
        'dogrudan'   => 'Doğrudan',
        'ic'         => 'Site içi',
        'diger'      => 'Diğer site',
        // Ölçülemeyen durum kendi kutusunda durur. Bilinmeyeni "doğrudan"
        // saymak, yayıncıya olmayan bir bilgiyi varmış gibi gösterirdi.
        'bilinmiyor' => 'Bilinmiyor',
    ];
}

/** Cihaz sınıfları. */
function analytics_device_types() {
    return ['mobil' => 'Mobil', 'masaustu' => 'Masaüstü'];
}

/** Analitik kaydı açık mı? Kapalıyken yazma yolu HİÇ SQL çalıştırmaz. */
function analytics_enabled() {
    return setting('analytics_enabled', '1') === '1';
}

/** Gün başına ayrıntı (haber kırılımı) kaç gün saklanır. */
function analytics_detail_days() {
    return max(7, min(400, (int)setting('analytics_detail_days', '60')));
}

/** Günlük satırlar toptan kaç gün sonra silinir. */
function analytics_keep_days() {
    return max(30, min(1200, (int)setting('analytics_keep_days', '365')));
}

/**
 * Tablo var mı?
 *
 * NEDEN `schema_has_table()` DOĞRUDAN ÇAĞRILMIYOR: o işlev inc/schema.php
 * içindedir ve schema.php `api.php` bağlamında YÜKLENMEZ. Yazma yolumuz tam
 * da o bağlamda koşuyor (assets/site.js → api.php?a=public.view). Faz 7'de
 * `inc/roles.php` aynı bağımlılık yüzünden ön yüzde sessizce ölmüştü
 * (NOTES.md); aynı hataya düşmemek için burada bağımsız bir yedek var.
 * Sonuç istek boyunca hatırlanır, yani en çok bir kez sorulur.
 */
function analytics_table_exists($table) {
    static $bellek = [];
    $table = (string)$table;
    if (isset($bellek[$table])) { return $bellek[$table]; }
    if (function_exists('schema_has_table')) {
        $bellek[$table] = (bool)schema_has_table($table);
        return $bellek[$table];
    }
    try {
        q('SELECT 1 FROM ' . preg_replace('/[^a-z_]/', '', $table) . ' LIMIT 1');
        $bellek[$table] = true;
    } catch (Throwable $e) {
        $bellek[$table] = false;
    }
    return $bellek[$table];
}

// ============================================================ sınıflandırma

/**
 * Ziyaretçi özeti — HAM IP YERİNE saklanan tek şey.
 *
 * NEDEN HMAC + GÜNLÜK TUZ: düz `sha256(ip)` geri döndürülebilir; IPv4 uzayı
 * 4 milyar, bir dizüstünde saniyeler sürer. Gizli tuz bunu kapatır. Tuzun
 * GÜNLÜK olması ise ikinci ve asıl korumadır: tuz yenilendiğinde eskisi
 * silindiği için dünkü satırlar artık HİÇ KİMSE tarafından (veritabanına
 * tam erişimi olan biri dâhil) bir IP'ye bağlanamaz ve iki günün özetleri
 * eşleştirilerek "aynı kişi" izi çıkarılamaz.
 *
 * @return string 16 hane onaltılık
 */
function analytics_visitor_hash($ip) {
    return substr(hash_hmac('sha256', (string)$ip, analytics_daily_salt()), 0, 16);
}

/**
 * Günlük tuz. `settings` içinde tutulur; gün değişince YENİSİ YAZILIR ve
 * eskisi kaybolur (üzerine yazılır) — geri getirilemez.
 *
 * Eşzamanlılık notu: gün dönümünde iki istek aynı anda tuzu tazeleyebilir.
 * Sonuç en kötü ihtimalle o dakikada birkaç ziyaretçinin iki kez sayılmasıdır;
 * kilit maliyetine değmez, çünkü bu bir muhasebe değil eğilim ölçümüdür.
 *
 * GÜN PARAMETRESİ BİLEREK YOK: yalnız BUGÜNÜN tuzu vardır. Geçmiş bir gün için
 * tuz istemek, saklanan tek tuzu o güne çevirip bugünkü özetleri kendi içinde
 * tutarsız hâle getirirdi. Geçmiş günlerin özetleri zaten çözülemez olmalı —
 * modülün amacı tam olarak budur.
 */
function analytics_daily_salt() {
    $day = date('Y-m-d');
    static $bellek = [];
    if (isset($bellek[$day])) { return $bellek[$day]; }

    $kayitliGun = setting('analytics_salt_day', '');
    $tuz        = setting('analytics_salt', '');
    if ($kayitliGun !== $day || $tuz === '') {
        $tuz = bin2hex(random_bytes(16));
        setting_set('analytics_salt', $tuz);
        setting_set('analytics_salt_day', $day);
    }
    // app_key ile karıştırılır: veritabanı yedeği tek başına ele geçse bile
    // (config.php olmadan) özetler yeniden üretilemez.
    $bellek[$day] = $tuz . '|' . (string)cfg('app_key', '');
    return $bellek[$day];
}

/**
 * Cihaz sınıfı. User-Agent OKUNUR ama SAKLANMAZ — geriye iki değerli bir
 * sınıf kalır. Tablet bilinçli olarak "masaüstü" sayılır: yayıncının bu
 * ölçümle aldığı karar (mobil yerleşim, yazı boyu, reklam ölçüsü) tablette
 * masaüstü yerleşimiyle aynıdır.
 */
function analytics_device_class($ua = null) {
    $ua = $ua === null ? (string)arr($_SERVER, 'HTTP_USER_AGENT', '') : (string)$ua;
    if ($ua === '') { return 'masaustu'; }
    if (preg_match('/iPad|Tablet|Silk|PlayBook/i', $ua)) { return 'masaustu'; }
    if (preg_match('/Mobi|Android|iPhone|iPod|Windows Phone|IEMobile|Opera Mini/i', $ua)) { return 'mobil'; }
    return 'masaustu';
}

/** Bilinen arama motoru alan adı parçaları. */
function analytics_search_hosts() {
    return ['google.', 'bing.com', 'yandex.', 'yahoo.', 'duckduckgo.com', 'ecosia.org',
            'search.brave.com', 'baidu.com', 'startpage.com', 'qwant.com', 'search.marimba'];
}

/** Bilinen sosyal ağ alan adı parçaları. */
function analytics_social_hosts() {
    return ['facebook.com', 'fb.com', 'l.facebook', 'instagram.com', 'twitter.com', 'x.com',
            't.co', 'linkedin.com', 'lnkd.in', 'reddit.com', 'youtube.com', 'youtu.be',
            'tiktok.com', 'pinterest.', 'whatsapp.com', 'wa.me', 't.me', 'telegram.'];
}

/**
 * Ham referrer → tür. HAM ADRES DÖNMEZ, yalnız tür kodu döner; çağıran zaten
 * ham adresi hiçbir yere yazmaz.
 *
 * @param string $raw      Ham referrer (boş dize = doğrudan giriş)
 * @param string $selfHost Kendi alan adımız (site içi ayrımı için)
 */
function analytics_referrer_class($raw, $selfHost = '') {
    $raw = trim((string)$raw);
    if ($raw === '') { return 'dogrudan'; }

    $host = (string)parse_url($raw, PHP_URL_HOST);
    if ($host === '') { return 'diger'; }
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) { $host = substr($host, 4); }

    if ($selfHost === '') { $selfHost = strtolower((string)parse_url(base_url(), PHP_URL_HOST)); }
    if (strpos($selfHost, 'www.') === 0) { $selfHost = substr($selfHost, 4); }
    if ($selfHost !== '' && $host === $selfHost) { return 'ic'; }

    foreach (analytics_search_hosts() as $p) { if (strpos($host, $p) !== false) { return 'arama'; } }
    foreach (analytics_social_hosts() as $p) { if (strpos($host, $p) !== false) { return 'sosyal'; } }
    return 'diger';
}

/**
 * Bu isteğin yönlendiren türü.
 *
 * DİKKAT — BİLİNEN SINIR (raporda da yazılı):
 * Sayaç CONTRACTS §3.1 gereği istemciden çağrılır (`assets/site.js` →
 * `api.php?a=public.view`). O fetch isteğinin `Referer` başlığı HABERİN
 * KENDİ ADRESİDİR; okurun siteye nereden geldiği o başlıkta YOKTUR. Yani
 * `HTTP_REFERER`'a bakarak "site içi" demek, ölçmediğimiz bir şeyi ölçmüş
 * gibi göstermek olurdu. Bu durumda tür `bilinmiyor` yazılır.
 *
 * İstek gövdesinde `ref` alanı VARSA o alan yetkilidir (boş dize = doğrudan).
 * `assets/site.js` bu alanı gönderecek şekilde tek satırla genişletilebilir:
 *     body: JSON.stringify({ id: id, ref: document.referrer })
 * O dosya bu ajanın sahipliğinde değildir; orkestratörden istendi.
 */
function analytics_request_ref_class() {
    $govde = json_body();
    if (is_array($govde) && array_key_exists('ref', $govde)) {
        return analytics_referrer_class((string)$govde['ref']);
    }
    if (isset($_POST['ref'])) {
        return analytics_referrer_class((string)$_POST['ref']);
    }

    $ham = (string)arr($_SERVER, 'HTTP_REFERER', '');
    // İç ping mi? (api.php üzerinden geldi ve referrer kendi alan adımız)
    $script = (string)arr($_SERVER, 'SCRIPT_NAME', '');
    if (strpos($script, 'api.php') !== false && analytics_referrer_class($ham) === 'ic') {
        return 'bilinmiyor';
    }
    return analytics_referrer_class($ham);
}

// ============================================================ yazma yolu

/**
 * Okunma kaydı. HER HABER GÖRÜNTÜLEMESİNDE çalışır — pahalı olamaz.
 *
 * MALİYET (ölçüldü, bkz. gelistirme/1.2-ajan-a.md):
 *   · 1 YAZMA  → hit_daily UPSERT (gün × haber × yönlendiren × cihaz sayacı)
 *   · 1 OKUMA  → hit_visitors denetimi (UNIQUE indeks üzerinden, ~0,04 ms)
 *   · + ziyaretçinin o günkü İLK görüntülemesinde 1 ek yazma
 *
 * Yani sürekli hâlde YAZMA SAYISI BİRDİR — var olan `posts.view_count`
 * sayacıyla aynı. Ölçüm: tek COMMIT ~3,2-3,8 ms (SQLite/WAL, synchronous=FULL,
 * Windows), indeksli okuma ~0,04 ms.
 *
 * Ziyaretçi denetimi neden var: abone hunisinin (ziyaret → üye → premium) ilk
 * basamağı TEKİL ZİYARETÇİ olmak zorunda. Görüntülenme sayısıyla kurulan bir
 * huni, çok sayfa gezen tek okuru kalabalık gibi gösterir ve dönüşüm oranını
 * sistematik olarak küçük yanlış hesaplar.
 *
 * TEKİLLEŞTİRME: burada kurulmaz. Çağıran `post_register_view()` zaten
 * `viewuniq:<ip>:<haber>` kovasıyla 6 saatlik bir kapı uygular; ikinci bir
 * kapı hem gereksiz bir SQL hem tutarsız iki sayı demek olurdu.
 */
function analytics_record_view($postId) {
    $postId = (int)$postId;
    if ($postId <= 0 || !analytics_enabled()) { return; }
    // Tablo VARLIĞI burada SORULMAZ. `analytics_table_exists()` schema.php
    // yüklü olmayan bağlamda (api.php — yani tam da bu yol) bir sonda SELECT'i
    // çalıştırırdı; bu, her haber görüntülemesine üçüncü bir ifade eklemek
    // demekti. Göç uygulanmamış kurulumda UPSERT zaten hata verir; aşağıdaki
    // yakalayıcı modülü o istek boyunca kapatır ve günlüğe TEK satır yazar.
    if (!empty($GLOBALS['manset_analytics_off'])) { return; }

    $day = date('Y-m-d');
    $ref = analytics_request_ref_class();
    $dev = analytics_device_class();
    if (!isset(analytics_ref_types()[$ref])) { $ref = 'diger'; }
    if (!isset(analytics_device_types()[$dev])) { $dev = 'masaustu'; }

    try {
        analytics_bump($day, $postId, $ref, $dev, 1);
    } catch (Throwable $e) {
        // Tablo yoksa (göç uygulanmamış) ya da yazma başarısızsa: modülü bu
        // istek boyunca sustur. Aksi hâlde her görüntüleme hata günlüğüne bir
        // satır yazar ve arıza, disk dolduran ikinci bir arızaya dönüşür.
        $GLOBALS['manset_analytics_off'] = true;
        log_error('analytics_record_view kapatıldı: ' . $e->getMessage());
        return;
    }

    // Tekil ziyaretçi: HAM IP DEĞİL, günlük tuzlu özet.
    //
    // NEDEN ÖNCE OKUMA, SONRA (GEREKİRSE) YAZMA — ölçülerek seçildi:
    // `INSERT OR IGNORE` her çağrıda bir COMMIT'tir; SQLite/WAL + synchronous=FULL
    // altında ölçülen maliyeti ~3,2 ms (yani var olan `posts.view_count`
    // sayacının maliyeti kadar). Aynı satır zaten varken yaptığı iş ise HİÇBİR
    // ŞEY. UNIQUE indeks üzerinden yapılan denetim okuması ~0,04 ms — seksen
    // kat ucuz. Ziyaretçinin o günkü İLK görüntülemesinde bir yazma yapılır,
    // sonraki her görüntülemede yalnız okuma.
    //
    // YARIŞ: iki eşzamanlı ilk-görüntüleme ikisi de boş okuyup yazmayı
    // deneyebilir; UNIQUE indeks ikincisini reddeder ve hata yutulur —
    // çift satır oluşamaz.
    try {
        $vh = analytics_visitor_hash(client_ip());
        $varMi = (int)qv('SELECT 1 FROM hit_visitors WHERE day = :d AND vhash = :h',
                         [':d' => $day, ':h' => $vh], 0);
        if ($varMi === 0) {
            $sql = db_driver() === 'mysql'
                ? 'INSERT IGNORE INTO hit_visitors (day, vhash) VALUES (:d, :h)'
                : 'INSERT OR IGNORE INTO hit_visitors (day, vhash) VALUES (:d, :h)';
            q($sql, [':d' => $day, ':h' => $vh]);
        }
    } catch (Throwable $e) {
        // Ziyaretçi sayımı kaybolabilir; okunma sayacı kaybolmasın.
        log_error('analytics ziyaretçi: ' . $e->getMessage());
    }
}

/**
 * hit_daily sayacını artırır — TEK ifade.
 *
 * Sürücüye göre iki ayrı UPSERT: SQLite `ON CONFLICT … DO UPDATE`,
 * MySQL `ON DUPLICATE KEY UPDATE`. Ortak bir sözdizimi yok; "önce UPDATE,
 * satır yoksa INSERT" ise yarış durumunda çift satır üretir ve yazma yolunu
 * iki ifadeye çıkarır. Çok eski bir SQLite (< 3.24) `ON CONFLICT`
 * tanımadığında yedek yola düşülür — o kurulumda maliyet 2 ifadedir.
 */
function analytics_bump($day, $postId, $ref, $dev, $n = 1) {
    $p = [':d' => $day, ':p' => (int)$postId, ':r' => $ref, ':v' => $dev, ':n' => (int)$n];
    $sql = db_driver() === 'mysql'
        ? 'INSERT INTO hit_daily (day, post_id, ref, dev, hits) VALUES (:d, :p, :r, :v, :n)
           ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)'
        : 'INSERT INTO hit_daily (day, post_id, ref, dev, hits) VALUES (:d, :p, :r, :v, :n)
           ON CONFLICT (day, post_id, ref, dev) DO UPDATE SET hits = hit_daily.hits + :n';
    try {
        q($sql, $p);
    } catch (Throwable $e) {
        // Yedek yol (eski SQLite): önce UPDATE, satır yoksa INSERT.
        $etkilenen = q('UPDATE hit_daily SET hits = hits + :n
                        WHERE day = :d AND post_id = :p AND ref = :r AND dev = :v', $p)->rowCount();
        if ($etkilenen === 0) {
            q('INSERT INTO hit_daily (day, post_id, ref, dev, hits) VALUES (:d, :p, :r, :v, :n)', $p);
        }
    }
}

// ============================================================ zamanlı görev

/**
 * Toplama ve budama.
 *
 * ÜÇ İŞ, ÜÇ FARKLI GEREKÇE:
 *  1. DÜNÜ ÖZETLE: `hit_visitors` yalnız tekilleştirme için var. Gün kapandığı
 *     anda satırların taşıdığı tek bilgi "kaç tanesi vardı" olur; sayıya
 *     indirgenip SİLİNİRLER. Böylece tablo bir günden fazla büyümez ve
 *     ziyaretçi özetleri kalıcı bir ize dönüşmez (KVKK).
 *  2. AYRINTIYI KATLA: ayrıntı penceresinden eski günlerin haber kırılımı
 *     `post_id = 0` satırına toplanır. Trafik eğilimi ve yönlendiren dağılımı
 *     korunur, "üç yıl önce hangi haber kaç okundu" ayrıntısı düşer.
 *  3. TOPTAN BUDA: saklama penceresinden eski her şey silinir.
 *
 * `$ucuz = true` ile kayıtlıdır: ağa çıkmaz, yalnız yerel SQL çalıştırır.
 */
function analytics_cron_rollup() {
    if (!analytics_table_exists('hit_daily')) {
        return 'analitik: tablolar yok (göç 015 uygulanmamış)';
    }
    $bugun = date('Y-m-d');
    $rapor = [];

    /* 1) Dünün ve daha eski günlerin tekil ziyaretçilerini özete indir ----- */
    $gunler = qa('SELECT day, COUNT(*) AS n FROM hit_visitors WHERE day < :b GROUP BY day', [':b' => $bugun]);
    foreach ($gunler as $g) {
        analytics_summary_set((string)$g['day'], ['visitors' => (int)$g['n']]);
    }
    if ($gunler) {
        q('DELETE FROM hit_visitors WHERE day < :b', [':b' => $bugun]);
        $rapor[] = count($gunler) . ' gün ziyaretçi özeti';
    }

    /* 2) Gün toplamları + huni anlık görüntüsü ---------------------------- */
    // YALNIZ BUGÜN VE DÜN tazelenir. Daha eski bir günün toplamı bir daha
    // değişmez (katlama toplamı korur), ama tüm günleri dolaşmak cron'un her
    // turunda saklama penceresi kadar (varsayılan 365) çift sorgu demekti —
    // hiçbir sayıyı değiştirmeyen saf maliyet. Dün de dâhil, çünkü cron gece
    // yarısını geçtikten sonraki ilk turda dünün son yazımlarını toplamalı.
    $dun = date('Y-m-d', strtotime('-1 day'));
    // `OR day NOT IN (…)`: cron bir hafta durmuşsa aradaki günler de bir kez
    // doldurulur — ama yalnız ÖZETİ HİÇ OLMAYANLAR, yani gap kapatma tek seferlik.
    foreach (qa('SELECT day, SUM(hits) AS n FROM hit_daily
                 WHERE day >= :d OR day NOT IN (SELECT day FROM hit_summary)
                 GROUP BY day', [':d' => $dun]) as $g) {
        analytics_summary_set((string)$g['day'], ['hits' => (int)$g['n']]);
    }
    // Bugünün tekilleri henüz silinmedi; canlı sayılır.
    $bugunTekil = (int)qv('SELECT COUNT(*) FROM hit_visitors WHERE day = :b', [':b' => $bugun], 0);
    analytics_summary_set($bugun, ['visitors' => $bugunTekil] + analytics_member_snapshot());

    /* 3) Ayrıntıyı katla --------------------------------------------------- */
    $katlaOncesi = date('Y-m-d', strtotime('-' . analytics_detail_days() . ' days'));
    $katlanacak = qa('SELECT day, ref, dev, SUM(hits) AS n FROM hit_daily
                      WHERE day < :k AND post_id > 0 GROUP BY day, ref, dev',
                      [':k' => $katlaOncesi]);
    if ($katlanacak) {
        q('DELETE FROM hit_daily WHERE day < :k AND post_id > 0', [':k' => $katlaOncesi]);
        foreach ($katlanacak as $r) {
            analytics_bump((string)$r['day'], 0, (string)$r['ref'], (string)$r['dev'], (int)$r['n']);
        }
        $rapor[] = count($katlanacak) . ' satır özete katlandı';
    }

    /* 4) Toptan buda ------------------------------------------------------- */
    $sinir = date('Y-m-d', strtotime('-' . analytics_keep_days() . ' days'));
    $silinen = q('DELETE FROM hit_daily WHERE day < :s', [':s' => $sinir])->rowCount();
    $silinen += q('DELETE FROM hit_summary WHERE day < :s', [':s' => $sinir])->rowCount();
    if ($silinen > 0) { $rapor[] = $silinen . ' eski satır silindi'; }

    return 'analitik: ' . ($rapor ? implode(', ', $rapor) : 'yapılacak iş yok');
}

/** Gün özeti satırını oluşturur ya da verilen alanlarını günceller. */
function analytics_summary_set($day, array $alanlar) {
    if (!$alanlar) { return; }
    $var = (int)qv('SELECT COUNT(*) FROM hit_summary WHERE day = :d', [':d' => $day], 0);
    $alanlar['updated_at'] = now();
    if ($var > 0) {
        db_update('hit_summary', $alanlar, 'day = :d', [':d' => $day]);
    } else {
        db_insert('hit_summary', ['day' => (string)$day] + $alanlar);
    }
}

/** Üye ve premium sayısı — huninin ikinci ve üçüncü basamağı. */
function analytics_member_snapshot() {
    $uye = 0;
    $premium = 0;
    try {
        if (analytics_table_exists('users')) {
            $uye = (int)qv('SELECT COUNT(*) FROM users WHERE role = \'member\' AND active = 1', [], 0);
        }
        if (analytics_table_exists('memberships')) {
            $premium = (int)qv('SELECT COUNT(*) FROM memberships
                                WHERE tier = \'premium\'
                                  AND (valid_until IS NULL OR valid_until = \'\' OR valid_until >= :n)',
                                [':n' => now()], 0);
        }
    } catch (Throwable $e) { /* modül yoksa huni üyesiz çizilir */ }
    return ['members' => $uye, 'premium' => $premium];
}

// ============================================================ rapor sorguları

/**
 * Gün gün seri. Eksik günler 0 ile doldurulur ki grafik boşluk göstermesin
 * (boş gün "veri yok" değil, "o gün kimse gelmedi" demektir).
 *
 * @return array [ ['day'=>'2026-08-20','hits'=>12,'visitors'=>5], … ] eskiden yeniye
 */
function analytics_series($days = 7) {
    $days = max(1, min(365, (int)$days));
    $bas = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

    $hits = [];
    foreach (qa('SELECT day, SUM(hits) AS n FROM hit_daily WHERE day >= :b GROUP BY day', [':b' => $bas]) as $r) {
        $hits[(string)$r['day']] = (int)$r['n'];
    }
    $vis = [];
    foreach (qa('SELECT day, visitors FROM hit_summary WHERE day >= :b', [':b' => $bas]) as $r) {
        $vis[(string)$r['day']] = (int)$r['visitors'];
    }
    // Bugünün tekilleri henüz özete geçmemiş olabilir (cron gece döner).
    $bugun = date('Y-m-d');
    try {
        $canli = (int)qv('SELECT COUNT(*) FROM hit_visitors WHERE day = :b', [':b' => $bugun], 0);
        if ($canli > 0) { $vis[$bugun] = $canli; }
    } catch (Throwable $e) { /* yok say */ }

    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $g = date('Y-m-d', strtotime('-' . $i . ' days'));
        $out[] = [
            'day'      => $g,
            'hits'     => isset($hits[$g]) ? $hits[$g] : 0,
            'visitors' => isset($vis[$g]) ? $vis[$g] : 0,
        ];
    }
    return $out;
}

/** En çok okunanlar (verilen gün aralığında). */
function analytics_top_posts($days = 7, $limit = 10) {
    $days = max(1, min(365, (int)$days));
    $limit = max(1, min(50, (int)$limit));
    $bas = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    // LIMIT gömülü: değer yukarıda tam sayıya zorlandı, kullanıcı girdisi değil.
    return qa('SELECT h.post_id, SUM(h.hits) AS hits, p.title, p.slug, p.status
               FROM hit_daily h LEFT JOIN posts p ON p.id = h.post_id
               WHERE h.day >= :b AND h.post_id > 0
               GROUP BY h.post_id, p.title, p.slug, p.status
               ORDER BY hits DESC LIMIT ' . $limit, [':b' => $bas]);
}

/** Yönlendiren türü dağılımı → ['arama'=>123, …] */
function analytics_ref_breakdown($days = 7) {
    $days = max(1, min(365, (int)$days));
    $bas = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $out = [];
    foreach (array_keys(analytics_ref_types()) as $k) { $out[$k] = 0; }
    foreach (qa('SELECT ref, SUM(hits) AS n FROM hit_daily WHERE day >= :b GROUP BY ref', [':b' => $bas]) as $r) {
        $k = (string)$r['ref'];
        if (!isset($out[$k])) { $out[$k] = 0; }
        $out[$k] += (int)$r['n'];
    }
    return $out;
}

/** Cihaz sınıfı dağılımı → ['mobil'=>…, 'masaustu'=>…] */
function analytics_device_breakdown($days = 7) {
    $days = max(1, min(365, (int)$days));
    $bas = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $out = ['mobil' => 0, 'masaustu' => 0];
    foreach (qa('SELECT dev, SUM(hits) AS n FROM hit_daily WHERE day >= :b GROUP BY dev', [':b' => $bas]) as $r) {
        $k = (string)$r['dev'];
        if (!isset($out[$k])) { $out[$k] = 0; }
        $out[$k] += (int)$r['n'];
    }
    return $out;
}

/**
 * Abone hunisi: ziyaret → üye → premium.
 *
 * "Ziyaret" basamağı TEKİL ZİYARETÇİ sayısıdır (görüntülenme değil); aksi
 * hâlde çok sayfa gezen tek okur kalabalık görünür ve dönüşüm oranı
 * sistematik olarak olduğundan küçük çıkardı.
 */
function analytics_funnel($days = 30) {
    $days = max(1, min(365, (int)$days));
    $bas = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    // BUGÜN ÖZETTEN DIŞLANIR: cron gün içinde de koştuğu için bugünün satırı
    // özette zaten olabilir; canlı sayıyı üstüne eklemek bugünü İKİ KEZ sayardı.
    $bugun = date('Y-m-d');
    $ziyaret = (int)qv('SELECT COALESCE(SUM(visitors), 0) FROM hit_summary WHERE day >= :b AND day < :g',
        [':b' => $bas, ':g' => $bugun], 0);
    try {
        $ziyaret += (int)qv('SELECT COUNT(*) FROM hit_visitors WHERE day = :b', [':b' => $bugun], 0);
    } catch (Throwable $e) { /* yok say */ }

    $snap = analytics_member_snapshot();
    $yeniUye = 0;
    try {
        if (analytics_table_exists('users')) {
            $yeniUye = (int)qv('SELECT COUNT(*) FROM users WHERE role = \'member\' AND created_at >= :b',
                [':b' => $bas . ' 00:00:00'], 0);
        }
    } catch (Throwable $e) { /* yok say */ }

    return [
        'gun'         => $days,
        'ziyaret'     => $ziyaret,
        'uye'         => (int)$snap['members'],
        'premium'     => (int)$snap['premium'],
        'yeni_uye'    => $yeniUye,
    ];
}

/** Analitik tabloları hiç veri gördü mü? (panelde "henüz veri yok" için) */
function analytics_has_data() {
    try { return (int)qv('SELECT COUNT(*) FROM hit_daily', [], 0) > 0; }
    catch (Throwable $e) { return false; }
}

// ============================================================ dış analitik kodu

/**
 * Yayıncının panele yapıştırdığı dış analitik kodu (Plausible/Matomo/GA).
 *
 * NEDEN AYRI BİR ALAN: bazı yayıncılar reklam ajansı ya da BİK raporlaması
 * için dış bir ölçümcü kullanmak zorunda. Bunu kodu düzenleyerek yapmaları
 * (tema layout'una elle script eklemek) her tema güncellemesinde kaybolur.
 *
 * GÜVENLİK: alan `settings.manage` (yalnız admin) ister — reklam HTML'i ve
 * tema özel CSS'iyle aynı sınıfta bir yetki yükseltme yüzeyidir.
 * Bkz. admin/settings-groups/20-analitik.php.
 *
 * !!! BU İŞLEV ŞU AN HİÇBİR ŞABLONDAN ÇAĞRILMIYOR. Ön yüz layout dosyaları
 * (her temanın layout.php dosyası) ve index.php bu ajanın sahipliğinde değil; kanca
 * orkestratörden istendi (bkz. gelistirme/1.2-ajan-a.md). Kodun saklanması
 * ve panelde gösterilmesi çalışıyor, BASILMASI çalışmıyor.
 */
function analytics_external_html() {
    if (setting('analytics_external_on', '0') !== '1') { return ''; }
    return (string)setting('analytics_external_code', '');
}

// ============================================================ 1.2-02 · menü rozetleri

/**
 * Panel menüsü "bekleyen iş" rozetleri.  admin/index.php bunu HER SAYFADA
 * çağırır — bu yüzden ucuz olmak zorundadır.
 *
 * NEDEN ÖNBELLEK: rozetler beş ayrı tabloda beş `COUNT(*)` demek. Beşi de
 * indeksli ve hızlı, ama panelde gezinen bir editör dakikada onlarca sayfa
 * açar; her açılışta beş sorgu, hiçbir karar değiştirmeyen saf bir maliyettir.
 * Sayılar `settings` içinde 60 saniye tutulur: bir editörün "yeni yorum var"
 * bilgisini 60 saniye gecikmeli görmesi hiçbir işi bozmaz, ama sorgu sayısını
 * pratikte sıfıra indirir (settings zaten istek başına tek seferde okunur).
 *
 * NEDEN KULLANICIYA GÖRE SÜZÜLÜR: sayılar SİTE GENELİdir (önbellek herkes
 * için ortak olsun diye), ama çıktı yalnız kullanıcının menüsünde GÖRÜNEN
 * sayfalar için üretilir. Aksi hâlde rozet, göremeyeceği bir kuyruğun
 * varlığını sızdırırdı.
 *
 * @param array|null $user   current_user()
 * @param array      $groups admin_layout()'un süzdüğü menü: grup => [slug => …]
 * @return array slug => sayı
 */
function admin_nav_badges($user, $groups) {
    $gorunur = [];
    foreach ((array)$groups as $items) {
        foreach ((array)$items as $slug => $_) { $gorunur[$slug] = true; }
    }
    if (!$gorunur) { return []; }

    $sayilar = analytics_badge_counts();
    $out = [];
    foreach ($sayilar as $slug => $n) {
        if ($n > 0 && isset($gorunur[$slug])) { $out[$slug] = (int)$n; }
    }
    return $out;
}

/**
 * Rozet sayaçlarının kısa ömürlü önbelleği (60 sn).
 *
 * İki katman: istek içi bellek (aynı istekte iki kez sorulmasın) ve
 * `settings` içinde 60 saniyelik ortak önbellek (istekler arası).
 * Bellek katmanı `$GLOBALS` üzerinde tutuluyor ki
 * `analytics_badge_reset()` ile sıfırlanabilsin — `static` olsaydı veri
 * değiştiren bir işlemin ardından aynı istekte tazelemek imkânsızdı.
 */
function analytics_badge_counts() {
    if (isset($GLOBALS['manset_badge_counts'])) { return $GLOBALS['manset_badge_counts']; }

    $ham = (string)setting('nav_badge_cache', '');
    if ($ham !== '') {
        $c = json_decode($ham, true);
        if (is_array($c) && isset($c['t']) && (time() - (int)$c['t']) < 60 && isset($c['d']) && is_array($c['d'])) {
            $GLOBALS['manset_badge_counts'] = $c['d'];
            return $GLOBALS['manset_badge_counts'];
        }
    }
    $taze = analytics_badge_counts_fresh();
    setting_set('nav_badge_cache', json_encode(['t' => time(), 'd' => $taze]));
    $GLOBALS['manset_badge_counts'] = $taze;
    return $taze;
}

/** Rozet önbelleğini düşürür (her iki katman). */
function analytics_badge_reset() {
    unset($GLOBALS['manset_badge_counts']);
    setting_set('nav_badge_cache', '');
}

/**
 * Sayaçları gerçekten hesaplar. Beş `COUNT(*)`; tablosu olmayan modül atlanır.
 * Çöp kutusundaki haber sayılmaz (013 göçü) — panelin geri kalanıyla tutarlı.
 */
function analytics_badge_counts_fresh() {
    $out = ['posts' => 0, 'comments' => 0, 'ai' => 0, 'rss' => 0, 'corrections' => 0];
    $var = 'analytics_table_exists';
    try {
        if ($var('posts')) {
            $cop = (function_exists('schema_has_column') && schema_has_column('posts', 'deleted_at'))
                ? ' AND deleted_at = \'\'' : '';
            $out['posts'] = (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'pending\'' . $cop, [], 0);
        }
        if ($var('comments')) {
            $out['comments'] = (int)qv('SELECT COUNT(*) FROM comments WHERE status = \'pending\'', [], 0);
        }
        if ($var('ai_jobs')) {
            $out['ai'] = (int)qv('SELECT COUNT(*) FROM ai_jobs WHERE status = \'failed\'', [], 0);
        }
        if ($var('rss_sources')) {
            // "Hatalı kaynak" = art arda hata biriktirmiş kaynak. Tek seferlik
            // ağ hıçkırığı rozet üretmesin diye eşik 1 değil 2.
            $out['rss'] = (int)qv('SELECT COUNT(*) FROM rss_sources WHERE error_count >= 2', [], 0);
        }
        if ($var('corrections')) {
            $out['corrections'] = (int)qv('SELECT COUNT(*) FROM corrections WHERE status = \'pending\'', [], 0);
        }
    } catch (Throwable $e) {
        log_error('rozet sayacı: ' . $e->getMessage());
    }
    return $out;
}

// ============================================================ 1.2-02 · sağlık kartı

/**
 * Panel sağlık kartı ölçümleri.
 *
 * @return array [ ['ad'=>…, 'deger'=>…, 'ton'=>'olumlu|uyari|olumsuz|notr', 'not'=>…], … ]
 */
function analytics_health_checks() {
    $out = [];

    /* Cron bayatlığı — otomasyonun tamamı buna bağlı ------------------------ */
    $son = '';
    try {
        if (analytics_table_exists('cron_runs')) {
            $son = (string)qv('SELECT MAX(started_at) FROM cron_runs', [], '');
        }
    } catch (Throwable $e) { /* yok say */ }
    if ($son === '') { $son = (string)setting('cron_last_run', ''); }
    if ($son === '') {
        $out[] = ['ad' => 'Zamanlı görev', 'deger' => 'hiç çalışmadı', 'ton' => 'olumsuz',
                  'not' => 'RSS, YZ kuyruğu, zamanlanmış yayın ve yedek budama çalışmıyor demektir.'];
    } else {
        $yas = time() - (int)strtotime($son);
        $ton = $yas > 86400 ? 'olumsuz' : ($yas > 7200 ? 'uyari' : 'olumlu');
        $out[] = ['ad' => 'Zamanlı görev', 'deger' => tr_ago($son), 'ton' => $ton,
                  'not' => 'Son cron turu. 2 saatten eskiyse zamanlayıcı durmuş olabilir.'];
    }

    /* Veritabanı boyutu ---------------------------------------------------- */
    $dbBoyut = analytics_db_size();
    if ($dbBoyut > 0) {
        $ton = $dbBoyut > 1073741824 ? 'uyari' : 'olumlu';
        $out[] = ['ad' => 'Veritabanı', 'deger' => analytics_human_size($dbBoyut), 'ton' => $ton,
                  'not' => 'Yedek alma süresi ve paylaşımlı hosting kotası buna bağlı.'];
    }

    /* Disk ----------------------------------------------------------------- */
    $bos = @disk_free_space(ROOT_DIR);
    if ($bos !== false && $bos > 0) {
        $ton = $bos < 209715200 ? 'olumsuz' : ($bos < 1073741824 ? 'uyari' : 'olumlu');
        $out[] = ['ad' => 'Boş disk', 'deger' => analytics_human_size((int)$bos), 'ton' => $ton,
                  'not' => 'Disk dolduğunda görsel yüklemesi ve yedek alma sessizce başarısız olur.'];
    }

    /* Yedek yaşı ----------------------------------------------------------- */
    if (function_exists('backup_list')) {
        $yedekler = [];
        try { $yedekler = (array)backup_list(); } catch (Throwable $e) { $yedekler = []; }
        if (!$yedekler) {
            $out[] = ['ad' => 'Yedek', 'deger' => 'hiç alınmamış', 'ton' => 'olumsuz',
                      'not' => 'Yanlışlıkla silinen içerik ya da bozulan bir güncelleme geri alınamaz.'];
        } else {
            $enYeni = 0;
            foreach ($yedekler as $y) {
                $t = (int)arr($y, 'mtime', 0);
                if ($t === 0 && isset($y['created_at'])) { $t = (int)strtotime((string)$y['created_at']); }
                if ($t > $enYeni) { $enYeni = $t; }
            }
            $yas = $enYeni > 0 ? time() - $enYeni : PHP_INT_MAX;
            $ton = $yas > 2592000 ? 'olumsuz' : ($yas > 604800 ? 'uyari' : 'olumlu');
            $out[] = ['ad' => 'Yedek', 'deger' => $enYeni > 0 ? tr_ago(date('Y-m-d H:i:s', $enYeni)) : 'bilinmiyor',
                      'ton' => $ton, 'not' => 'En son alınan yedek. Bir haftadan eskiyse veri kaybı riski büyür.'];
        }
    }

    /* YZ hata oranı (son 24 saat) ------------------------------------------ */
    try {
        if (analytics_table_exists('ai_logs')) {
            $sinir = date('Y-m-d H:i:s', time() - 86400);
            $top = (int)qv('SELECT COUNT(*) FROM ai_logs WHERE created_at >= :s', [':s' => $sinir], 0);
            $hata = (int)qv('SELECT COUNT(*) FROM ai_logs WHERE created_at >= :s AND ok = 0', [':s' => $sinir], 0);
            if ($top > 0) {
                $oran = (int)round($hata * 100 / $top);
                $ton = $oran >= 50 ? 'olumsuz' : ($oran >= 15 ? 'uyari' : 'olumlu');
                $out[] = ['ad' => 'YZ hata oranı', 'deger' => $oran . '% (' . $hata . '/' . $top . ')', 'ton' => $ton,
                          'not' => 'Son 24 saat. Yüksekse anahtar, kota ya da sağlayıcı sorunu vardır.'];
            } else {
                $out[] = ['ad' => 'YZ hata oranı', 'deger' => 'çağrı yok', 'ton' => 'notr',
                          'not' => 'Son 24 saatte yapay zekâ çağrısı yapılmadı.'];
            }
        }
    } catch (Throwable $e) { /* yok say */ }

    return $out;
}

/** Veritabanı boyutu (bayt). SQLite'ta dosya, MySQL'de information_schema. */
function analytics_db_size() {
    try {
        if (db_driver() === 'mysql') {
            $n = qv('SELECT SUM(data_length + index_length) FROM information_schema.tables
                     WHERE table_schema = :s', [':s' => (string)cfg('db_name', '')], 0);
            return (int)$n;
        }
        $yol = (string)cfg('db_path', '');
        if ($yol !== '' && is_file($yol)) {
            $n = (int)@filesize($yol);
            foreach (['-wal', '-shm'] as $ek) {
                if (is_file($yol . $ek)) { $n += (int)@filesize($yol . $ek); }
            }
            return $n;
        }
    } catch (Throwable $e) { /* yok say */ }
    return 0;
}

/** İnsan okunur bayt. (inc/backup.php yüklü olmayabilir; kendi kopyamız.) */
function analytics_human_size($bytes) {
    $bytes = (float)$bytes;
    $birim = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($birim) - 1) { $bytes /= 1024; $i++; }
    return number_format($bytes, $i === 0 ? 0 : 1, ',', '.') . ' ' . $birim[$i];
}

// ============================================================ inline SVG grafik

/**
 * Gün gün çubuk grafiği — DIŞ KÜTÜPHANE YOK, satır içi SVG.
 *
 * Neden SVG ve neden burada: CONTRACTS §0 dış JS/CSS kütüphanesini yasaklar.
 * Canvas ile çizim JavaScript gerektirir ve yazdırma/ekran okuyucu için ölüdür;
 * SVG hem statiktir hem `<title>` ile erişilebilir.
 *
 * @param array  $seri  analytics_series() çıktısı
 * @param string $alan  'hits' | 'visitors'
 */
function analytics_bar_svg(array $seri, $alan = 'hits', $yukseklik = 160) {
    $n = count($seri);
    if ($n === 0) { return ''; }
    $genislik = 720;
    $ustBosluk = 14;
    $altBosluk = 22;
    $cizimY = $yukseklik - $ustBosluk - $altBosluk;
    $adim = $genislik / $n;
    $kalinlik = max(2, $adim * 0.62);

    $enBuyuk = 0;
    foreach ($seri as $s) { $enBuyuk = max($enBuyuk, (int)arr($s, $alan, 0)); }
    if ($enBuyuk <= 0) { $enBuyuk = 1; }

    $svg = '<svg class="analitik-grafik" viewBox="0 0 ' . $genislik . ' ' . $yukseklik . '" '
         . 'role="img" preserveAspectRatio="none" aria-label="' . esc($alan === 'hits' ? 'Günlük görüntülenme' : 'Günlük tekil ziyaretçi') . '">';
    // Yatay kılavuz çizgileri — göz, çubukları bir ölçeğe oturtabilsin.
    for ($k = 0; $k <= 2; $k++) {
        $y = $ustBosluk + ($cizimY * $k / 2);
        $svg .= '<line x1="0" y1="' . round($y, 1) . '" x2="' . $genislik . '" y2="' . round($y, 1)
              . '" stroke="#e5e9ee" stroke-width="1"/>';
    }
    foreach ($seri as $i => $s) {
        $v = (int)arr($s, $alan, 0);
        $h = $v > 0 ? max(2, ($v / $enBuyuk) * $cizimY) : 0;
        $x = ($i * $adim) + (($adim - $kalinlik) / 2);
        $y = $ustBosluk + $cizimY - $h;
        if ($h > 0) {
            $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($kalinlik, 1)
                  . '" height="' . round($h, 1) . '" rx="2" fill="' . ($alan === 'hits' ? '#c0392b' : '#1f4e79') . '">'
                  . '<title>' . esc(tr_date($s['day'] . ' 00:00:00', false) . ': ' . $v) . '</title></rect>';
        }
        // Etiket: yalnız ilk, son ve ortadaki gün (30 günde eksen okunmaz olurdu).
        if ($i === 0 || $i === $n - 1 || $i === (int)floor($n / 2)) {
            $svg .= '<text x="' . round(($i * $adim) + ($adim / 2), 1) . '" y="' . ($yukseklik - 6)
                  . '" text-anchor="middle" font-size="11" fill="#6b7280">'
                  . esc(date('j.n', (int)strtotime($s['day']))) . '</text>';
        }
    }
    $svg .= '</svg>';
    return $svg;
}

// ============================================================ zamanlı görev kaydı

// $ucuz = true: yalnız yerel SQL çalıştırır, ağa çıkmaz — web tetiklemeli
// cron turunda da güvenle koşabilir.
cron_register('analitik', 'analytics_cron_rollup', true);
