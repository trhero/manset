<?php
/**
 * Panel — Kurulum Kontrolü (1.2-10, Ajan-A).
 *
 * NEDEN KALICI BİR LİSTE, NEDEN "KURULUM SİHİRBAZI" DEĞİL:
 * Kurulum sırasında gösterilen bir uyarı bir kez okunur ve unutulur. Bu
 * ekrandaki maddelerin hepsi ise ZAMANLA BOZULABİLİR — alan adı değişir,
 * cron kaldırılır, yedek almayı bırakırsınız, demo verisi canlıda unutulur.
 * Bu yüzden liste kalıcıdır ve her açılışta yeniden ÖLÇÜLÜR; kaydedilmiş bir
 * "tamamlandı" işareti bilerek YOKTUR (yayıncı kendini kandırmasın).
 *
 * Her madde üç şey söyler: durum, NEDEN önemli olduğu, ve düzeltmenin yeri.
 * "Neden" olmadan liste bir bürokrasi; onunla bir eğitim aracıdır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('settings.manage');

$adminPageTitle = 'Kurulum Kontrolü';

// ============================================================ demo verisi tespiti

/**
 * Demo tohumunun ürettiği haber başlıkları.
 *
 * !!! DÜRÜSTLÜK NOTU — DEMO KAYITLARINDA DAMGA YOKTUR.
 * `tests/demo-seed.php` incelendi: ürettiği haberlere, yorumlara ve reklamlara
 * "bu demo verisidir" diyen bir SÜTUN ya da işaret YAZMIYOR. Betiğin kendi
 * `--reset` seçeneği bile ayrım yapmıyor; `DELETE FROM posts` ile TÜM haberleri
 * siliyor. Yani gerçek içerikle demo içeriğini ayıran hazır bir damga yok.
 *
 * Bu yüzden burada damga UYDURULMADI; bunun yerine iki yıkıcı olmayan yol
 * birlikte kullanılıyor:
 *   1) Başlıklar `tests/demo-seed.php` KAYNAĞINDAN okunuyor (dosya
 *      çalıştırılmıyor, yalnız metni ayrıştırılıyor). Eşleşme TAM başlık
 *      üzerindendir; benzeri bir başlık silinmez.
 *   2) `.test` uzantılı e-posta ve `demo-` önekli dosya adı gibi, tohumun
 *      bıraktığı ve gerçek içerikte bulunması İMKÂNSIZ izler
 *      (RFC 2606: `.test` alan adı hiçbir zaman gerçek olamaz).
 *
 * Dağıtım paketinde `tests/` bulunmaz; o kurulumda (1) devre dışı kalır ve
 * ekranda bu açıkça yazar. Silme her hâlükârda YALNIZ eşleşen satırları
 * hedefler ve öncesinde sayıp gösterir.
 */
function kontrol_demo_titles() {
    static $bellek = null;
    if ($bellek !== null) { return $bellek; }
    $bellek = [];
    $dosya = ROOT_DIR . '/tests/demo-seed.php';
    if (!is_file($dosya)) { return $bellek; }
    $src = (string)file_get_contents($dosya);
    $bas = strpos($src, 'function demo_articles(');
    if ($bas === false) { return $bellek; }
    $son = strpos($src, "\n}", $bas);
    $govde = substr($src, $bas, $son === false ? 20000 : ($son - $bas));
    // Her öğe ['Başlık', 'Spot'] biçiminde; ilk dizeyi alıyoruz.
    if (preg_match_all('/\[\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*,\s*\'/', $govde, $m)) {
        foreach ($m[1] as $t) { $bellek[] = stripcslashes($t); }
    }
    $bellek = array_values(array_unique($bellek));
    return $bellek;
}

/** Demo başlıkları için IN (...) parçası ve parametreleri. */
function kontrol_title_in($titles, $prefix = ':t') {
    $ph = [];
    $params = [];
    foreach (array_values($titles) as $i => $t) {
        $ph[] = $prefix . $i;
        $params[$prefix . $i] = $t;
    }
    return [implode(', ', $ph), $params];
}

/** Demo haber kimlikleri (yalnız TAM başlık eşleşmesi). */
function kontrol_demo_post_ids() {
    $ids = [];
    $titles = kontrol_demo_titles();
    if ($titles) {
        list($in, $p) = kontrol_title_in($titles);
        foreach (qa('SELECT id FROM posts WHERE title IN (' . $in . ')', $p) as $r) { $ids[] = (int)$r['id']; }
    }
    // Tohumun YZ örneği haberleri ayrıca ölçülemez bir adres taşır.
    foreach (qa('SELECT id FROM posts WHERE source_url LIKE :u', [':u' => 'https://ornek-ajans.test/%']) as $r) {
        $ids[] = (int)$r['id'];
    }
    return array_values(array_unique($ids));
}

/**
 * Kurulum sihirbazının bıraktığı örnek haberler.
 *
 * BUNLARIN GERÇEK BİR DAMGASI VAR (uydurma değil, kodda okundu):
 * `schema_seed()` bu altı haberi `tags = 'örnek, manşet'` ile ve gövdesinde
 * “Bu içerik kurulum sırasında örnek olarak oluşturulmuştur” cümlesiyle yazar.
 * İki koşul BİRLİKTE arandığı için gerçek bir haberin yanlışlıkla eşleşmesi
 * pratikte imkânsızdır. Demo tohumundan farklı olarak bu haberler HER
 * kurulumda oluşur — dağıtım paketinde `tests/` bulunmasa bile.
 */
function kontrol_seed_post_ids() {
    $ids = [];
    foreach (qa('SELECT id FROM posts WHERE tags = :t AND body LIKE :b',
                [':t' => 'örnek, manşet', ':b' => '%kurulum sırasında örnek olarak oluşturulmuştur%']) as $r) {
        $ids[] = (int)$r['id'];
    }
    return $ids;
}

/** Demo + kurulum örneği haberlerin birleşimi (silme bunu hedefler). */
function kontrol_sample_post_ids() {
    return array_values(array_unique(array_merge(kontrol_demo_post_ids(), kontrol_seed_post_ids())));
}

/** Kimlik listesinden en çok $n başlık. */
function kontrol_titles_of(array $ids, $n = 4) {
    $out = [];
    foreach (array_slice($ids, 0, $n) as $id) {
        $t = (string)qv('SELECT title FROM posts WHERE id = :i', [':i' => (int)$id], '');
        if ($t !== '') { $out[] = $t; }
    }
    return $out;
}

/** Silinecek her şeyin dökümü: grup => ['sayi'=>n, 'ornek'=>[…]] */
function kontrol_demo_scan() {
    $out = [];
    $seedIds = kontrol_seed_post_ids();
    // İki küme KESİŞİR: `schema_seed()` örneklerinden üçünün başlığı demo
    // tohumunda da geçiyor. Ayrıştırmazsak toplam, gerçekte silinecek satır
    // sayısından fazla görünür — yıkıcı bir işlemde gösterilen sayı, silinen
    // sayıyla birebir tutmalıdır.
    $demoIds = array_values(array_diff(kontrol_demo_post_ids(), $seedIds));

    $out['haber'] = ['ad' => 'Demo haber (demo tohumu)', 'sayi' => count($demoIds),
                     'ornek' => kontrol_titles_of($demoIds)];
    $out['kurulum'] = ['ad' => 'Kurulum örnek haberi', 'sayi' => count($seedIds),
                       'ornek' => kontrol_titles_of($seedIds)];

    $out['yorum'] = ['ad' => 'Demo yorum', 'ornek' => [],
        'sayi' => (int)qv('SELECT COUNT(*) FROM comments WHERE email LIKE :e', [':e' => '%@ornek.test'], 0)];

    $out['kullanici'] = ['ad' => 'Demo hesap (@ornek.test)', 'ornek' => [],
        'sayi' => (int)qv('SELECT COUNT(*) FROM users WHERE email LIKE :e AND role <> \'admin\'',
                          [':e' => '%@ornek.test'], 0)];
    foreach (qa('SELECT email FROM users WHERE email LIKE :e AND role <> \'admin\' ORDER BY id LIMIT 5',
                [':e' => '%@ornek.test']) as $r) {
        $out['kullanici']['ornek'][] = (string)$r['email'];
    }

    // Dosya adı yıl/ay klasörüyle saklanır (2026/08/demo-…): iki desen de gerekli.
    // Yalnız DOSYA ADI 'demo-' ile başlayanlar sayılır; '%demo-%' deseni
    // yayıncının 'urun-demo-1.jpg' gibi gerçek bir dosyasını da yakalardı.
    $out['medya'] = ['ad' => 'Demo görsel (dosya adı demo- ile başlayan)', 'ornek' => [],
        'sayi' => (int)qv('SELECT COUNT(*) FROM media WHERE filename LIKE :f OR filename LIKE :g',
                          [':f' => 'demo-%', ':g' => '%/demo-%'], 0)];

    $out['reklam'] = ['ad' => 'Örnek reklam kutusu', 'ornek' => [],
        'sayi' => (int)qv('SELECT COUNT(*) FROM ads WHERE title LIKE :t', [':t' => '%(örnek)'], 0)];

    $out['rss'] = ['ad' => 'Örnek RSS kaynağı (yerel test adresi)', 'ornek' => [],
        'sayi' => (int)qv('SELECT COUNT(*) FROM rss_sources WHERE url LIKE :u', [':u' => '%/tests/fixtures/%'], 0)];

    return $out;
}

/** Toplam silinecek satır sayısı. */
function kontrol_demo_total(array $scan) {
    $n = 0;
    foreach ($scan as $g) { $n += (int)$g['sayi']; }
    return $n;
}

/**
 * Demo verisini siler. YALNIZ kontrol_demo_scan()'in saydığı satırlar.
 * Yönetici hesabı, gerçek haberler ve elle yazılmış her şey dokunulmaz.
 */
function kontrol_demo_delete() {
    $silinen = [];
    $postIds = kontrol_sample_post_ids();

    if ($postIds) {
        // Parçalı IN: çok sayıda kimlikte hazır ifade sınırına takılmayalım.
        foreach (array_chunk($postIds, 200) as $parca) {
            $ph = [];
            $p = [];
            foreach ($parca as $i => $id) { $ph[] = ':i' . $i; $p[':i' . $i] = (int)$id; }
            $in = implode(', ', $ph);
            // Bağlı kayıtlar önce: yetim yorum/analitik satırı kalmasın.
            $silinen['yorum'] = (int)arr($silinen, 'yorum', 0)
                + q('DELETE FROM comments WHERE post_id IN (' . $in . ')', $p)->rowCount();
            q('DELETE FROM corrections WHERE post_id IN (' . $in . ')', $p);
            if (function_exists('analytics_table_exists') && analytics_table_exists('hit_daily')) {
                q('DELETE FROM hit_daily WHERE post_id IN (' . $in . ')', $p);
            }
            if (function_exists('schema_has_table') && schema_has_table('bookmarks')) {
                q('DELETE FROM bookmarks WHERE post_id IN (' . $in . ')', $p);
            }
            $silinen['haber'] = (int)arr($silinen, 'haber', 0)
                + q('DELETE FROM posts WHERE id IN (' . $in . ')', $p)->rowCount();
        }
    }

    // Haberiyle birlikte düşmemiş demo yorumlar (ör. gerçek bir habere yazılmış).
    $silinen['yorum'] = (int)arr($silinen, 'yorum', 0)
        + q('DELETE FROM comments WHERE email LIKE :e', [':e' => '%@ornek.test'])->rowCount();

    // Demo hesaplar — YÖNETİCİ ASLA SİLİNMEZ.
    $uids = [];
    foreach (qa('SELECT id FROM users WHERE email LIKE :e AND role <> \'admin\'',
                [':e' => '%@ornek.test']) as $r) { $uids[] = (int)$r['id']; }
    if ($uids) {
        foreach (array_chunk($uids, 200) as $parca) {
            $ph = [];
            $p = [];
            foreach ($parca as $i => $id) { $ph[] = ':u' . $i; $p[':u' . $i] = (int)$id; }
            $in = implode(', ', $ph);
            foreach (['memberships', 'member_tokens', 'bookmarks', 'user_grants'] as $t) {
                if (function_exists('schema_has_table') && schema_has_table($t)) {
                    q('DELETE FROM ' . $t . ' WHERE user_id IN (' . $in . ')', $p);
                }
            }
            // Demo yazarın gerçek haberi varsa haber SİLİNMEZ, yalnız sahipsiz kalır.
            q('UPDATE posts SET author_id = 0 WHERE author_id IN (' . $in . ')', $p);
            $silinen['kullanici'] = (int)arr($silinen, 'kullanici', 0)
                + q('DELETE FROM users WHERE id IN (' . $in . ')', $p)->rowCount();
        }
    }

    // Görseller: dosyayı da düşürmek için media_delete() kullanılır.
    $silinen['medya'] = 0;
    foreach (qa('SELECT id FROM media WHERE filename LIKE :f OR filename LIKE :g',
                [':f' => 'demo-%', ':g' => '%/demo-%']) as $m) {
        try {
            if (function_exists('media_delete')) { media_delete((int)$m['id']); }
            else { q('DELETE FROM media WHERE id = :i', [':i' => (int)$m['id']]); }
            $silinen['medya']++;
        } catch (Throwable $e) { /* dosya yoksa satırı yine de düşür */ }
    }

    $silinen['reklam'] = q('DELETE FROM ads WHERE title LIKE :t', [':t' => '%(örnek)'])->rowCount();

    foreach (qa('SELECT id FROM rss_sources WHERE url LIKE :u', [':u' => '%/tests/fixtures/%']) as $s) {
        q('DELETE FROM rss_items WHERE source_id = :s', [':s' => (int)$s['id']]);
    }
    $silinen['rss'] = q('DELETE FROM rss_sources WHERE url LIKE :u', [':u' => '%/tests/fixtures/%'])->rowCount();

    if (function_exists('cache_flush')) { cache_flush(); }
    return $silinen;
}

// ============================================================ POST işlemleri

if ($_SERVER['REQUEST_METHOD'] === 'POST' && inp('do') === 'demo_temizle') {
    csrf_guard();
    // İKİNCİ KAPI: onay kutusu. Yıkıcı bir işlemde tek tıklık bir düğme yeterli
    // değil — kullanıcı ne sildiğini okuduğunu açıkça beyan etmeli.
    if (inp('onay') !== '1') {
        flash('err', 'Silme onaylanmadı. Onay kutusunu işaretlemeniz gerekiyor.');
    } else {
        $once = kontrol_demo_total(kontrol_demo_scan());
        if ($once === 0) {
            flash('warn', 'Silinecek demo verisi bulunamadı.');
        } else {
            try {
                $s = kontrol_demo_delete();
                $parcalar = [];
                foreach ($s as $k => $n) { if ((int)$n > 0) { $parcalar[] = $k . ': ' . (int)$n; } }
                flash('ok', 'Demo verisi silindi — ' . ($parcalar ? implode(', ', $parcalar) : 'kayıt yok') . '.');
            } catch (Throwable $e) {
                log_error('demo temizleme: ' . $e->getMessage());
                flash('err', 'Silme sırasında hata oluştu: ' . $e->getMessage());
            }
        }
    }
    header('Location: ' . admin_url('kontrol'), true, 302);
    exit;
}

// ============================================================ kontrol maddeleri

$demoScan  = kontrol_demo_scan();
$demoTotal = kontrol_demo_total($demoScan);

/** Tek madde. $ton: olumlu | uyari | olumsuz */
function kontrol_madde($ad, $ton, $durum, $neden, $link = '', $linkAd = 'Düzelt →', $disBag = false) {
    return ['ad' => $ad, 'ton' => $ton, 'durum' => $durum, 'neden' => $neden,
            'link' => $link, 'link_ad' => $linkAd, 'dis' => (bool)$disBag];
}

$maddeler = [];

/* 1) Künye — Basın Kanunu m.4 -------------------------------------------- */
$eksikKunye = function_exists('kunye_missing_required') ? kunye_missing_required() : [];
$maddeler[] = kontrol_madde(
    'Künye bilgileri',
    $eksikKunye ? 'olumsuz' : 'olumlu',
    $eksikKunye ? count($eksikKunye) . ' zorunlu alan boş: ' . implode(', ', array_slice($eksikKunye, 0, 4)) : 'tamam',
    'Basın Kanunu m.4, internet haber sitesinin ana sayfasından doğrudan ulaşılabilir bir '
  . 'künye bulundurmasını ve orada iş yeri adresi, ticari unvan, elektronik tebligat (KEP) '
  . 'adresi ile yer sağlayıcı bilgisinin yer almasını arıyor. Eksik künye, BİK ilan hakkı '
  . 'başvurusunda ilk elenme nedenlerinden biridir. Yürürlükteki metni ve süreleri '
  . 'yayıncının kendisi teyit etmelidir.',
    admin_url('kunye'));

/* 2) Site adresi ---------------------------------------------------------- */
$siteUrl = base_url();
$cfgUrl  = trim((string)cfg('base_url', ''));
$httpsMi = strpos($siteUrl, 'https://') === 0;
$maddeler[] = kontrol_madde(
    'Site adresi',
    $httpsMi ? ($cfgUrl === '' ? 'uyari' : 'olumlu') : 'olumsuz',
    $siteUrl . ($cfgUrl === '' ? ' (otomatik algılandı)' : ' (config.php içinde sabit)'),
    'Kanonik adres, site haritası ve RSS beslemesindeki bağlantılar bu değerden üretilir. '
  . 'Yanlışsa arama motoru sitenizi iki ayrı site sanır ve sıralamanız bölünür. '
  . 'Otomatik algılama paylaşımlı hostingde genellikle doğru çalışır, ancak ters vekil '
  . '(reverse proxy) arkasında yanılabilir; kesin çözüm config.php içindeki base_url '
  . 'alanını doldurmaktır. HTTPS ise BİK teknik esaslarında açıkça aranıyor.',
    admin_url('settings'), 'Ayarlar →');

/* 3) Temiz adres ---------------------------------------------------------- */
$maddeler[] = kontrol_madde(
    'Temiz (SEF) adresler',
    sef_enabled() ? 'olumlu' : 'uyari',
    sef_enabled() ? 'açık' : 'kapalı — adresler ?r=… biçiminde',
    'Kapalıyken adresler okunabilir olmaz ve paylaşıldığında güven vermez. '
  . 'Sunucunuzda mod_rewrite yoksa bu bilinçli bir tercih olabilir; Ayarlar ekranındaki '
  . 'sonda düğmesi durumu yeniden sınar.',
    admin_url('settings'), 'Ayarlar →');

/* 4) robots.txt ----------------------------------------------------------- */
$robots = function_exists('seo_robots_txt') ? seo_robots_txt() : '';
$robotsOk = $robots !== '' && strpos($robots, 'Disallow: /admin/') !== false;
$maddeler[] = kontrol_madde(
    'robots.txt',
    $robotsOk ? 'olumlu' : 'uyari',
    $robotsOk ? 'üretiliyor, /admin/ kapalı' : 'üretilemiyor ya da yönetim paneli kapalı değil',
    'Yönetim paneli ve API adresleri arama motoru dizinine girmemeli; girerse giriş '
  . 'ekranınız arama sonuçlarında listelenir. Ayrıca site haritası bağlantısı bu dosyadan '
  . 'duyurulur — eksikse yeni haberler daha geç dizine girer.',
    base_url() . '/robots.txt', 'Dosyayı gör ↗', true);

/* 5) Favicon / logo ------------------------------------------------------- */
$favicon = trim((string)setting('site_favicon', ''));
$maddeler[] = kontrol_madde(
    'Favicon',
    $favicon !== '' ? 'olumlu' : 'uyari',
    $favicon !== '' ? esc($favicon) : 'yüklenmemiş',
    'Sekme simgesi, okurun onlarca açık sekme arasında sitenizi tanıdığı tek işarettir; '
  . 'ayrıca haber bağlantısı sosyal medyada paylaşıldığında görünür. Küçük bir ayrıntı '
  . 'gibi durur ama eksikliği amatör izlenimi bırakır.',
    admin_url('settings'), 'Ayarlar →');

/* 6) Yapay zekâ anahtarı -------------------------------------------------- */
$aiDurum = ai_status_text();
$maddeler[] = kontrol_madde(
    'Yapay zekâ anahtarı',
    $aiDurum === 'hazır' ? 'olumlu' : ($aiDurum === 'test modu' ? 'uyari' : 'notr'),
    $aiDurum,
    'İsteğe bağlıdır: anahtar olmadan sistemin geri kalanı tam çalışır. Ancak RSS '
  . 'yeniden yazma, başlık ve spot önerisi gibi otomasyonlar anahtarsız sessizce boşta '
  . 'durur — panelde iş kuyruğu birikiyorsa nedeni genellikle budur. '
  . '“Test modu” gerçek çağrı yapmaz; canlıda bırakılmamalıdır.',
    admin_url('ai'), 'Yapay Zekâ →');

/* 7) Yedek ---------------------------------------------------------------- */
$yedekler = function_exists('backup_list') ? (array)backup_list() : [];
$sonYedek = $yedekler ? (int)arr($yedekler[0], 'mtime', 0) : 0;
$yedekYas = $sonYedek > 0 ? time() - $sonYedek : PHP_INT_MAX;
$maddeler[] = kontrol_madde(
    'Yedek',
    $sonYedek === 0 ? 'olumsuz' : ($yedekYas > 604800 ? 'uyari' : 'olumlu'),
    $sonYedek === 0 ? 'hiç yedek alınmamış' : ('son yedek ' . tr_ago(date('Y-m-d H:i:s', $sonYedek))),
    'Yanlışlıkla silinen bir haberi, bozulan bir güncellemeyi ya da sunucu arızasını geri '
  . 'alabileceğiniz tek şey yedektir. Panelden alınan yedek sunucunun kendisinde durur; '
  . 'gerçek koruma için indirip başka bir yerde saklayın.',
    admin_url('tools'), 'Araçlar →');

/* 8) Cron ----------------------------------------------------------------- */
$cronSon = '';
if (function_exists('schema_has_table') && schema_has_table('cron_runs')) {
    $cronSon = (string)qv('SELECT MAX(started_at) FROM cron_runs', [], '');
}
if ($cronSon === '') { $cronSon = (string)setting('cron_last_run', ''); }
$cronYas = $cronSon !== '' ? time() - (int)strtotime($cronSon) : PHP_INT_MAX;
$maddeler[] = kontrol_madde(
    'Zamanlı görev (cron)',
    $cronSon === '' ? 'olumsuz' : ($cronYas > 86400 ? 'olumsuz' : ($cronYas > 7200 ? 'uyari' : 'olumlu')),
    $cronSon === '' ? 'hiç çalışmamış' : ('son tur ' . tr_ago($cronSon)),
    'Zamanlanmış haberlerin yayımlanması, RSS çekimi, yapay zekâ kuyruğu, önbellek '
  . 'süpürmesi, analitik toplaması, medya varyantlarının üretilmesi ve etiket '
  . 'eşitlemesi buna bağlıdır. Çalışmıyorsa hiçbiri hata vermez, yalnız hiç olmaz — '
  . 'bu yüzden en sinsi arızadır. Hosting panelinizden cron.php adresini '
  . 'dakikada/saatte bir çağırmanız gerekir.',
    admin_url('tools'), 'Araçlar →');

/* 8b) İlk cron turu bekleyen işler ---------------------------------------- */
// NEDEN AYRI MADDE: cron "kurulu" ama HENÜZ KOŞMAMIŞ bir sitede etiket bulutu
// boş, görsel varyantları eksik görünür. Yayıncı bunu "özellik çalışmıyor" diye
// okur ve aramaya başlar. Toplu doldurmayı kuruluma yıkmak yerine cron'a
// bırakmak doğru karardır — ama bunu SÖYLEMEK gerekir.
$bekleyen = [];
if (schema_has_table('post_tags') && schema_has_table('posts')) {
    $etiketliHaber = (int)qv('SELECT COUNT(*) FROM posts WHERE tags <> \'\'', [], 0);
    $esitlenen     = (int)qv('SELECT COUNT(DISTINCT post_id) FROM post_tags', [], 0);
    if ($etiketliHaber > 0 && $esitlenen < $etiketliHaber) {
        $bekleyen[] = 'etiket bulutu (' . ($etiketliHaber - $esitlenen) . ' haber)';
    }
}
if (schema_has_column('media', 'variant_state')) {
    $kuyruk = (int)qv('SELECT COUNT(*) FROM media WHERE variant_state = \'queued\'', [], 0);
    if ($kuyruk > 0) { $bekleyen[] = 'görsel varyantı (' . $kuyruk . ' dosya)'; }
}
if ($bekleyen) {
    $maddeler[] = kontrol_madde(
        'İlk cron turunu bekleyenler',
        'uyari',
        implode(' · ', $bekleyen),
        'Bu işler bilerek arka plana bırakılır: kurulum ya da toplu içerik aktarımı '
      . 'sırasında hepsini yapmak isteği dakikalarca bekletir ve paylaşımlı hostingde '
      . 'zaman aşımına düşürür. Cron bir kez çalıştığında kendiliğinden tamamlanırlar. '
      . 'O ana kadar etiket bulutu eksik, bazı görseller özgün boyutunda görünür — '
      . 'site çalışır, yalnız tamamlanmamıştır.',
        admin_url('tools'), 'Araçlar →');
}

/* 9) Analitik ------------------------------------------------------------- */
// inc/analytics.php yüklenmemiş bir kurulumda (modül dosyası silinmişse) bu
// ekran çökmemeli: kontrol listesinin işi zaten "neyin eksik olduğunu" söylemek.
$olcumVar = function_exists('analytics_enabled') && analytics_enabled();
$olcumModulu = function_exists('analytics_enabled');
$maddeler[] = kontrol_madde(
    'Okunma ölçümü',
    !$olcumModulu ? 'olumsuz' : ($olcumVar ? 'olumlu' : 'uyari'),
    !$olcumModulu ? 'modül yüklü değil (inc/analytics.php)' : ($olcumVar ? 'açık (çerezsiz, IP saklanmıyor)' : 'kapalı'),
    'Kendi ölçümümüz çerez vermez ve ham IP saklamaz, bu yüzden ziyaretçi onayı '
  . 'gerektirmez. Kapalıyken hangi haberin okunduğunu, trafiğin nereden geldiğini ve '
  . 'mobil okur oranını göremezsiniz.',
    admin_url('analytics'), 'Rapor →');

/* 10) Demo verisi --------------------------------------------------------- */
$maddeler[] = kontrol_madde(
    'Demo verisi',
    $demoTotal > 0 ? 'uyari' : 'olumlu',
    $demoTotal > 0 ? $demoTotal . ' kayıt hâlâ duruyor' : 'temiz',
    'Demo haberleri canlıda kalırsa arama motoru onları da dizine alır ve okur uydurma '
  . 'içerikle karşılaşır; “Örnek Medya A.Ş.” künyesi ise BİK başvurusunda doğrudan sorun '
  . 'çıkarır. Aşağıdaki bölümden temizleyebilirsiniz.',
    '', '');

$sayac = ['olumlu' => 0, 'uyari' => 0, 'olumsuz' => 0, 'notr' => 0];
foreach ($maddeler as $m) { $sayac[$m['ton']] = (int)arr($sayac, $m['ton'], 0) + 1; }
?>

<div class="sayfa-basligi">
  <h1>Kurulum Kontrolü</h1>
  <div class="dugme-grup">
    <?= admin_badge($sayac['olumlu'] . ' tamam', 'olumlu') ?>
    <?php if ($sayac['uyari'] > 0): ?><?= admin_badge($sayac['uyari'] . ' göz atın', 'uyari') ?><?php endif; ?>
    <?php if ($sayac['olumsuz'] > 0): ?><?= admin_badge($sayac['olumsuz'] . ' eksik', 'olumsuz') ?><?php endif; ?>
  </div>
</div>

<p class="soluk kucuk bosluk-alt">
  Bu liste her açılışta yeniden ölçülür; “tamamlandı” işareti tutulmaz. Bir madde
  bugün yeşilse yarın kırmızı olabilir — alan adı değişir, cron kaldırılır, yedek almayı
  bırakırsınız. Listeyi arada bir açmak, bu üç arızanın sessizce sürmesini önler.
</p>

<div class="kart">
  <div class="kart-baslik">Kontrol listesi</div>
  <div class="tablo-sarma" style="border:0">
    <table class="tablo">
      <tbody>
        <?php foreach ($maddeler as $m): ?>
          <tr>
            <td class="dar"><?= admin_badge($m['ton'] === 'olumlu' ? '✓' : ($m['ton'] === 'olumsuz' ? '!' : '•'), $m['ton']) ?></td>
            <td>
              <strong><?= esc($m['ad']) ?></strong> — <?= esc($m['durum']) ?>
              <span class="satir-alt"><?= esc($m['neden']) ?></span>
            </td>
            <td class="dar islem">
              <?php if ($m['link'] !== ''): ?>
                <a class="dugme kucuk" href="<?= esc($m['link']) ?>"
                   <?= !empty($m['dis']) ? 'target="_blank" rel="noopener"' : '' ?>><?= esc($m['link_ad']) ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="kart">
  <div class="kart-baslik">
    <span>Demo verisini temizle</span>
    <?= $demoTotal > 0 ? admin_badge($demoTotal . ' kayıt', 'uyari') : admin_badge('temiz', 'olumlu') ?>
  </div>

  <?php if (!kontrol_demo_titles()): ?>
    <div class="uyari bilgi">
      <strong>Sınırlı tarama.</strong> Demo haber başlıkları <code>tests/demo-seed.php</code>
      dosyasından okunur; bu kurulumda o dosya yok (dağıtım paketinde <code>tests/</code>
      bulunmaz). Bu yüzden demo <em>haberleri</em> tespit edilemiyor; aşağıda yalnız
      e-posta ve dosya adı izinden tanınan kayıtlar listelenir. Demo haberleri elle
      silmeniz gerekir.
    </div>
  <?php endif; ?>

  <p class="kucuk soluk">
    Bu işlem <strong>geri alınamaz</strong>. Yalnız aşağıda sayılan satırlar silinir:
    kurulumun bıraktığı örnek haberler (gövdesinde “kurulum sırasında örnek olarak
    oluşturulmuştur” yazan ve etiketi <code>örnek, manşet</code> olanlar),
    tam başlığı demo tohumundakiyle birebir aynı olan haberler, <code>@ornek.test</code>
    adresli hesap ve yorumlar, <code>demo-</code> önekli görseller, “(örnek)” başlıklı
    reklam kutuları ve yerel test adresine bakan RSS kaynakları.
    <strong>Yönetici hesabınız ve elle yazdığınız hiçbir içerik silinmez.</strong>
    Yine de önce <a href="<?= esc(admin_url('tools')) ?>">bir yedek alın</a>.
  </p>

  <div class="tablo-sarma bosluk-ust" style="border:0">
    <table class="tablo">
      <thead><tr><th>Grup</th><th class="dar">Silinecek</th><th>Örnek</th></tr></thead>
      <tbody>
        <?php foreach ($demoScan as $g): ?>
          <tr>
            <td><?= esc($g['ad']) ?></td>
            <td class="dar"><strong><?= (int)$g['sayi'] ?></strong></td>
            <td class="mini soluk"><?= esc($g['ornek'] ? implode(' · ', $g['ornek']) . ((int)$g['sayi'] > count($g['ornek']) ? ' …' : '') : '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <th>Toplam</th>
          <th class="dar"><?= (int)$demoTotal ?></th>
          <th></th>
        </tr>
      </tbody>
    </table>
  </div>

  <?php if ($demoTotal > 0): ?>
    <form method="post" class="bosluk-ust">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="demo_temizle">
      <div class="onay-satir">
        <label>
          <input type="checkbox" name="onay" value="1" required>
          Yukarıdaki <strong><?= (int)$demoTotal ?></strong> kaydın kalıcı olarak
          silineceğini okudum ve onaylıyorum.
        </label>
      </div>
      <button type="submit" class="dugme tehlike"
              data-onay="<?= esc($demoTotal . ' demo kaydı kalıcı olarak silinecek. Devam edilsin mi?') ?>">
        Demo verisini sil
      </button>
    </form>
  <?php else: ?>
    <p class="kucuk soluk bosluk-ust">Silinecek demo kaydı bulunmuyor.</p>
  <?php endif; ?>
</div>
