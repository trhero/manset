<?php
/**
 * Manşet — keşif sayfaları (1.3-10, Ajan-E).
 *
 * Okur bir haberi bitirdiğinde gidecek yeri olsun diye üç adres ve iki haber
 * sonu bileşeni üretir:
 *
 *   /yazar/{slug}          discover_author_view()     — yazarın haberleri + künye rolü
 *   /arsiv/2026            discover_archive_view()    — yıl arşivi
 *   /arsiv/2026/08         discover_archive_view()    — ay arşivi
 *   /etiketler             discover_tagcloud_view()   — etiket bulutu
 *   parts/haber-gezinme    discover_adjacent_posts()  — önceki / sonraki haber
 *   (temalar çağırır)      discover_related_posts()   — etiket kesişimli ilgili haber
 *
 * Rotalar index.php'de ORKESTRATÖRCE açıldı; bu dosya yoksa üç adres de 404
 * döner. Yani modül tek başına hiçbir davranışı değiştirmez.
 *
 * ÜÇ KURAL (hepsi ölçülerek korunuyor):
 *
 *  1. ÇEREZ YOK. Buradaki hiçbir yol oturum açmaz, çerez yazmaz
 *     (CONTRACTS §3.1). Sayfalar anonim gezilir ve önbelleğe girer.
 *  2. YAYINDA OLMAYAN İÇERİK SIZMAZ. Her sorgu published_where() kullanır.
 *     Etiket bulutu bunun en kolay kaçırılan yeri: çöp kutusundaki ya da
 *     taslak bir haberin özel etiketi bulutta görünürse silinen haberin
 *     varlığı ve konusu dışarı çıkar. Bu tam olarak denetim turu 3'te (B11)
 *     kapatılan sızıntıydı; index.php'deki `etiket` rotası aynı kuralı
 *     etiket ADINI çözerken de uyguluyor.
 *  3. ÜYE ADI SIZMAZ. /yazar/ yalnız PERSONEL hesapları tanır. Üye (member)
 *     rolündeki kayıtlı okurların adı hiçbir slug ile açılmaz — 1.2'de aynı
 *     kural yazar beslemesine (rss.php ?yazar=N) konulmuştu; orada koyup
 *     burada koymamak, kapıyı kapatıp pencereyi açık bırakmak olurdu.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ================================================================ yazarlar

/**
 * `users` tablosunda bir sütun var mı? (istek boyunca saklanır)
 *
 * NEDEN: display_name/bio/is_staff sütunları 010 göçüyle geldi. Göçü henüz
 * uygulamamış bir kurulumda bunları SELECT etmek ölümcül hata olurdu; sütun
 * yoksa alan sessizce boş kabul edilir.
 */
function discover_users_has_column($col) {
    static $bilinen = [];
    $col = (string)$col;
    if (isset($bilinen[$col])) { return $bilinen[$col]; }
    if (function_exists('schema_has_column')) {
        return $bilinen[$col] = (bool)schema_has_column('users', $col);
    }
    try { qv('SELECT ' . $col . ' FROM users LIMIT 1'); $bilinen[$col] = true; }
    catch (Throwable $e) { $bilinen[$col] = false; }
    return $bilinen[$col];
}

/**
 * Ön yüzde görünebilecek yazarlar: aktif PERSONEL hesapları.
 *
 * Üye (member) hesapları burada YOKTUR. `role_is_staff()` varsa rol tablosu
 * üzerinden, yoksa `role <> 'member'` ile elenir — roles.php yüklenmemiş bir
 * bağlamda da (cron, api) kural düşmez.
 *
 * @return array id => ['id','name','slug','bio','role','role_label','avatar']
 */
function discover_authors() {
    static $liste = null;
    if ($liste !== null) { return $liste; }

    $secim = ['u.id', 'u.name', 'u.role'];
    foreach (['display_name', 'bio', 'avatar', 'is_staff'] as $ek) {
        if (discover_users_has_column($ek)) { $secim[] = 'u.' . $ek; }
    }
    // pass_hash / email BİLEREK seçilmiyor: bu satırlar şablona gidiyor.
    $sql = 'SELECT ' . implode(', ', $secim) . ' FROM users u
            WHERE u.active = 1 AND u.role <> \'member\' ORDER BY u.id ASC';
    try { $rows = qa($sql); } catch (Throwable $e) { $rows = []; }

    $liste = [];
    foreach ($rows as $r) {
        $rol = (string)arr($r, 'role', '');
        // İkinci kapı: rol tablosu personel demiyorsa hesap yazar sayılmaz.
        if (function_exists('role_is_staff') && !role_is_staff($rol)) { continue; }
        if (array_key_exists('is_staff', $r) && (int)$r['is_staff'] === 0) { continue; }

        $ad = trim((string)arr($r, 'display_name', ''));
        if ($ad === '') { $ad = trim((string)arr($r, 'name', '')); }
        if ($ad === '') { continue; }               // adsız hesabın sayfası olmaz

        $liste[(int)$r['id']] = [
            'id'         => (int)$r['id'],
            'name'       => $ad,
            'slug'       => slugify($ad),
            'bio'        => trim((string)arr($r, 'bio', '')),
            'avatar'     => (string)arr($r, 'avatar', ''),
            'role'       => $rol,
            'role_label' => function_exists('role_label') ? role_label($rol) : $rol,
        ];
    }
    return $liste;
}

/** Yazarın yayında kaç haberi var? */
function discover_author_post_count($authorId) {
    return (int)qv('SELECT COUNT(*) FROM posts p WHERE p.author_id = :a AND ' . published_where('p'),
        [':a' => (int)$authorId, ':nowts' => now()], 0);
}

/**
 * Slug'a göre yazar. Bulunamazsa null.
 *
 * Aynı slug'a düşen iki personel varsa (aynı görünen ad) en çok yayını olan
 * seçilir; eşitlikte küçük id. Karar deterministik olmalı — yoksa aynı adres
 * iki farklı sayfa gösterir ve arama motoru ikisini de indeksler.
 */
function discover_author_by_slug($slug) {
    $slug = slugify((string)$slug);
    if ($slug === '') { return null; }
    $aday = null; $adaySayi = -1;
    foreach (discover_authors() as $a) {
        if ($a['slug'] !== $slug) { continue; }
        $sayi = discover_author_post_count($a['id']);
        if ($sayi > $adaySayi) { $aday = $a; $adaySayi = $sayi; }
    }
    if ($aday === null) { return null; }
    $aday['post_count'] = max(0, $adaySayi);
    return $aday;
}

/** Id'ye göre yazar (personel değilse null). */
function discover_author_by_id($id) {
    $all = discover_authors();
    return isset($all[(int)$id]) ? $all[(int)$id] : null;
}

/**
 * Yazar sayfasının adresi; hesap personel değilse ya da yayını yoksa ''.
 * Temalar bunu function_exists() ile çağırır (modül yoksa bağlantı basılmaz).
 */
function discover_author_url($post) {
    $id = is_array($post) ? (int)arr($post, 'author_id', 0) : (int)$post;
    $a = discover_author_by_id($id);
    if (!$a || $a['slug'] === '') { return ''; }
    if (discover_author_post_count($a['id']) < 1) { return ''; }
    return url('yazar/' . $a['slug']);
}

/** Yazarın yayındaki haberleri (sayfalı). */
function discover_author_posts($authorId, $limit = 12, $offset = 0) {
    $limit = max(1, min(50, (int)$limit));
    $offset = max(0, (int)$offset);
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.author_id = :a AND ' . published_where() . '
        ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        [':a' => (int)$authorId, ':nowts' => now()]);
}

/**
 * ROTA: /yazar/{slug}
 *
 * Pasif hesap, üye hesabı, personel olmayan hesap ve yayında hiç haberi
 * olmayan hesap: hepsi 404. Boş bir yazar sayfası basmak, personel listesini
 * slug denemesiyle taranabilir hâle getirirdi.
 */
function discover_author_view($slug, $page, $perPage) {
    $yazar = discover_author_by_slug($slug);
    if (!$yazar || $yazar['post_count'] < 1) { render_404('Yazar bulunamadı.'); }

    $page = max(1, (int)$page);
    $perPage = max(3, min(50, (int)$perPage));
    $sablon = view_resolve('kesif-yazar') ? 'kesif-yazar' : 'search';

    render($sablon, [
        'author'  => $yazar,
        'heading' => $yazar['name'],
        'term'    => $yazar['name'],
        'isTag'   => false,
        'posts'   => discover_author_posts($yazar['id'], $perPage, ($page - 1) * $perPage),
        'total'   => $yazar['post_count'],
        'page'    => $page,
        'perPage' => $perPage,
        'baseUrl' => url('yazar/' . $yazar['slug']),
    ]);
}

// ================================================================ arşiv

/** Türkçe ay adları (1..12). */
function discover_month_names() {
    return [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
            'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
}

/**
 * Arşivde gerçekten içerik olan yıllar → ['2026' => 12, '2025' => 40] (yeni→eski).
 *
 * NEDEN veriden üretiliyor: sabit bir alt sınır (örn. 1990) yazsaydık
 * /arsiv/1991 … /arsiv/2025 arası onlarca boş ama 200 dönen adres olurdu —
 * tarayıcı için sonsuz sığ bir gezinme ağacı. Veri yoksa adres yoktur.
 */
function discover_archive_years() {
    static $yillar = null;
    if ($yillar !== null) { return $yillar; }
    // SUBSTR + GROUP BY hem SQLite hem MySQL'de aynı çalışır. Sayım
    // veritabanında yapılıyor: bu işlev ALT BİLGİDEN de çağrılıyor, yani her
    // sayfada. Tüm published_at değerlerini PHP'ye çekmek büyük arşivde her
    // isteğe binlerce satır maliyeti bindirirdi.
    $rows = qa('SELECT SUBSTR(p.published_at, 1, 4) AS yil, COUNT(*) AS adet FROM posts p
        WHERE ' . published_where() . ' AND p.published_at IS NOT NULL AND p.published_at <> \'\'
        GROUP BY SUBSTR(p.published_at, 1, 4)', [':nowts' => now()]);
    $yillar = [];
    foreach ($rows as $r) {
        $y = (int)$r['yil'];
        if ($y < 1000) { continue; }
        $yillar[$y] = (int)$r['adet'];
    }
    krsort($yillar);
    return $yillar;
}

/** Bir yılın ay sayaçları → [1=>0, …, 12=>3]. */
function discover_archive_months($yil) {
    $yil = (int)$yil;
    $rows = qa('SELECT SUBSTR(p.published_at, 6, 2) AS ay, COUNT(*) AS adet FROM posts p
        WHERE ' . published_where() . ' AND p.published_at >= :bas AND p.published_at < :son
        GROUP BY SUBSTR(p.published_at, 6, 2)',
        [':nowts' => now(), ':bas' => $yil . '-01-01 00:00:00', ':son' => ($yil + 1) . '-01-01 00:00:00']);
    $aylar = array_fill(1, 12, 0);
    foreach ($rows as $r) {
        $m = (int)$r['ay'];
        if ($m >= 1 && $m <= 12) { $aylar[$m] = (int)$r['adet']; }
    }
    return $aylar;
}

/**
 * Yıl/ay için [başlangıç, bitiş) tarih penceresi.
 * Ay 0 ise tüm yıl. Dizeyle karşılaştırılır: 'Y-m-d H:i:s' hem SQLite'ta hem
 * MySQL'de sıralanabilir biçimdedir, ayrı bir tarih işlevi gerekmez.
 */
function discover_archive_range($yil, $ay) {
    $yil = (int)$yil; $ay = (int)$ay;
    if ($ay >= 1 && $ay <= 12) {
        $bas = sprintf('%04d-%02d-01 00:00:00', $yil, $ay);
        $sonY = $ay === 12 ? $yil + 1 : $yil;
        $sonA = $ay === 12 ? 1 : $ay + 1;
        return [$bas, sprintf('%04d-%02d-01 00:00:00', $sonY, $sonA)];
    }
    return [sprintf('%04d-01-01 00:00:00', $yil), sprintf('%04d-01-01 00:00:00', $yil + 1)];
}

/** Arşiv penceresindeki haber sayısı. */
function discover_archive_count($yil, $ay = 0) {
    list($bas, $son) = discover_archive_range($yil, $ay);
    return (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . published_where() . '
        AND p.published_at >= :bas AND p.published_at < :son',
        [':nowts' => now(), ':bas' => $bas, ':son' => $son], 0);
}

/** Arşiv penceresindeki haberler (sayfalı). */
function discover_archive_posts($yil, $ay = 0, $limit = 12, $offset = 0) {
    $limit = max(1, min(50, (int)$limit));
    $offset = max(0, (int)$offset);
    list($bas, $son) = discover_archive_range($yil, $ay);
    return qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE ' . published_where() . ' AND p.published_at >= :bas AND p.published_at < :son
        ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
        [':nowts' => now(), ':bas' => $bas, ':son' => $son]);
}

/**
 * ROTA: /arsiv/{yil}[/{ay}]
 *
 * 404 halleri: içeriği olmayan yıl, 1–12 dışı ay, GELECEK tarih. Gelecek ay
 * kapısı önemli: /arsiv/2026/12 bugün boş bir sayfa olurdu ama zamanlanmış
 * haberlerin varlığını da ele verebilirdi.
 */
function discover_archive_view($yil, $ay, $page, $perPage) {
    $yil = (int)$yil; $ay = (int)$ay;
    $yillar = discover_archive_years();

    if ($yil <= 0 || !isset($yillar[$yil])) { render_404('Arşivde bu tarih yok.'); }
    if ($ay !== 0 && ($ay < 1 || $ay > 12)) { render_404('Arşivde bu tarih yok.'); }
    // Gelecek: bu yılın gelecek ayı ya da gelecek yıl.
    $buYil = (int)date('Y'); $buAy = (int)date('n');
    if ($yil > $buYil || ($yil === $buYil && $ay > $buAy)) { render_404('Arşivde bu tarih yok.'); }

    $page = max(1, (int)$page);
    $perPage = max(3, min(50, (int)$perPage));
    $aylar = discover_month_names();
    $baslik = $ay ? ($aylar[$ay] . ' ' . $yil) : ($yil . ' arşivi');
    $taban = url('arsiv/' . $yil . ($ay ? '/' . sprintf('%02d', $ay) : ''));
    $sablon = view_resolve('kesif-arsiv') ? 'kesif-arsiv' : 'search';

    render($sablon, [
        'yil'       => $yil,
        'ay'        => $ay,
        'yillar'    => $yillar,
        'aySayilar' => discover_archive_months($yil),
        'heading'   => $baslik,
        'term'      => $baslik,
        'isTag'     => false,
        'posts'     => discover_archive_posts($yil, $ay, $perPage, ($page - 1) * $perPage),
        'total'     => discover_archive_count($yil, $ay),
        'page'      => $page,
        'perPage'   => $perPage,
        'baseUrl'   => $taban,
    ]);
}

// ================================================================ etiket bulutu

/**
 * Etiket sayaçları → [ ['label','slug','count','weight'], … ] (çok kullanılan önce).
 *
 * YALNIZ YAYINDAKİ haberlerin etiketleri sayılır: published_where() hem
 * taslağı ve zamanlanmışı hem de ÇÖP KUTUSUNDAKİ haberi eler (deleted_at).
 * Bu, denetim turu 3'te (B11) etiket sayfası başlığında kapatılan sızıntının
 * aynısıdır — bulut, aynı sızıntının çok daha geniş yüzeyidir.
 *
 * Etiketler serbest metin olarak `posts.tags` içinde durduğu için sayım
 * PHP tarafında yapılır; slug aynı yazımın büyük/küçük farklarını birleştirir.
 */
function discover_tag_counts($enFazla = 120) {
    $enFazla = max(1, min(500, (int)$enFazla));
    $rows = qa('SELECT p.tags FROM posts p WHERE p.tags <> \'\' AND ' . published_where('p') . ' LIMIT 5000',
        [':nowts' => now()]);

    $sayac = [];
    foreach ($rows as $r) {
        // Aynı haberde tekrarlanan etiket bir kez sayılır.
        $gorulen = [];
        foreach (tags_to_array($r['tags']) as $t) {
            $slug = slugify($t);
            if ($slug === '' || isset($gorulen[$slug])) { continue; }
            $gorulen[$slug] = true;
            if (!isset($sayac[$slug])) { $sayac[$slug] = ['label' => $t, 'slug' => $slug, 'count' => 0]; }
            $sayac[$slug]['count']++;
        }
    }
    if (!$sayac) { return []; }

    uasort($sayac, function ($a, $b) {
        if ($a['count'] === $b['count']) { return strcasecmp($a['label'], $b['label']); }
        return $b['count'] - $a['count'];
    });
    $sayac = array_slice(array_values($sayac), 0, $enFazla);

    return discover_tag_weights($sayac);
}

/**
 * Etiket listesine 1..5 ağırlığı ekler (saf işlev — testi veritabanı istemez).
 *
 * Ölçek LOGARİTMİKTİR: tek bir çok popüler etiket, doğrusal ölçekte kalan her
 * şeyi en küçük puntoya ezerdi.
 *
 * Bütün etiketler AYNI sayıda kullanılmışsa ağırlık hiçbir şey ayırt etmez;
 * o durumda hepsi orta ağırlık alır. Yeni bir sitede her etiket bir kez geçer
 * ve hepsini en büyük puntoyla basmak "bunlar popüler" yalanını söylerdi.
 *
 * @param array $liste [['label','slug','count'], …]
 * @return array aynı liste, her öğeye 'weight' eklenmiş
 */
function discover_tag_weights(array $liste) {
    if (!$liste) { return []; }
    $enBuyuk = 1; $enKucuk = PHP_INT_MAX;
    foreach ($liste as $s) {
        $c = (int)arr($s, 'count', 0);
        if ($c > $enBuyuk) { $enBuyuk = $c; }
        if ($c < $enKucuk) { $enKucuk = $c; }
    }
    if ($enBuyuk === $enKucuk) {
        foreach ($liste as $i => $s) { $liste[$i]['weight'] = 2; }
        return $liste;
    }
    $ustSinir = log($enBuyuk + 1);
    foreach ($liste as $i => $s) {
        $oran = $ustSinir > 0 ? (log((int)arr($s, 'count', 0) + 1) / $ustSinir) : 0;
        $liste[$i]['weight'] = max(1, min(5, (int)ceil($oran * 5)));
    }
    return $liste;
}

/** ROTA: /etiketler */
function discover_tagcloud_view() {
    $etiketler = discover_tag_counts(120);
    $sablon = view_resolve('kesif-etiketler') ? 'kesif-etiketler' : 'search';
    render($sablon, [
        'tags'    => $etiketler,
        'heading' => 'Etiketler',
        'term'    => '',
        'isTag'   => false,
        'posts'   => [],
        'total'   => count($etiketler),
        'page'    => 1,
        'perPage' => 50,
    ]);
}

// ================================================================ haber sonu gezinme

/**
 * Önceki (daha eski) ve sonraki (daha yeni) haber → ['prev'=>?array,'next'=>?array].
 *
 * Sıralama anahtarı (published_at, id) ÇİFTİDİR: aynı dakikada girilen iki
 * haber tek anahtarla karşılaştırılsaydı biri diğerinin hem öncesi hem
 * sonrası olur, gezinme kendi üstüne kapanırdı.
 *
 * @param bool $ayniKategori true ise yalnız aynı kategori içinde gezinir.
 */
function discover_adjacent_posts($post, $ayniKategori = false) {
    $bos = ['prev' => null, 'next' => null];
    if (!is_array($post) || (int)arr($post, 'id', 0) <= 0) { return $bos; }

    $id = (int)$post['id'];
    $pa = (string)arr($post, 'published_at', '');
    $sec = 'SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
            FROM posts p LEFT JOIN categories c ON c.id = p.category_id WHERE ';
    $params = [':nowts' => now(), ':id' => $id];
    $kat = '';
    if ($ayniKategori && (int)arr($post, 'category_id', 0) > 0) {
        $kat = ' AND p.category_id = :kat';
        $params[':kat'] = (int)$post['category_id'];
    }

    if ($pa === '') {
        // Tarihsiz haber (published_at NULL): tek anahtar olarak id kullanılır.
        $prev = qa($sec . published_where() . $kat . ' AND p.id < :id ORDER BY p.id DESC LIMIT 1', $params);
        $next = qa($sec . published_where() . $kat . ' AND p.id > :id ORDER BY p.id ASC LIMIT 1', $params);
    } else {
        $params[':pa'] = $pa;
        $prev = qa($sec . published_where() . $kat . '
            AND (p.published_at < :pa OR (p.published_at = :pa AND p.id < :id))
            ORDER BY p.published_at DESC, p.id DESC LIMIT 1', $params);
        $next = qa($sec . published_where() . $kat . '
            AND (p.published_at > :pa OR (p.published_at = :pa AND p.id > :id))
            ORDER BY p.published_at ASC, p.id ASC LIMIT 1', $params);
    }
    return [
        'prev' => $prev ? $prev[0] : null,
        'next' => $next ? $next[0] : null,
    ];
}

/**
 * AKILLI İLGİLİ HABERLER — etiket kesişimi, kategoriye düşerek.
 *
 * inc/view.php'deki related_posts() yalnız kategoriye bakar: "Gündem"
 * kategorisindeki bir deprem haberinin altında aynı kategorideki en yeni dört
 * haber çıkar, konuyla ilgisi olmasa da. Etiket kesişimi konuyu yakalar.
 *
 * Puanlama (yüksek → daha ilgili):
 *   ortak etiket sayısı × 10  +  aynı kategori 3  +  tazelik 0..2
 * Ortak etiket YOKSA aday listeye hiç girmez; eksik kalan yerler kategoriden
 * (related_posts mantığıyla) doldurulur. Böylece kutu hiçbir zaman boşalmaz —
 * bugünkü davranış en kötü ihtimalle korunur, tipik olarak iyileşir.
 *
 * related_posts() BOZULMADI ve yerinde duruyor: inc/view.php Ajan-E'nin dosyası
 * değil ve üçüncü taraf temalar onu çağırıyor.
 */
function discover_related_posts($post, $limit = 4) {
    if (!is_array($post)) { return []; }
    $limit = max(1, min(12, (int)$limit));
    $id = (int)arr($post, 'id', 0);
    $etiketler = tags_to_array(arr($post, 'tags', ''));

    $secilen = [];
    $puanli = [];

    if ($etiketler) {
        // En çok 6 etiket sorgulanır: her etiket bir LIKE demek, uzun etiket
        // listesi olan bir haberde sorgu gereksiz büyür.
        $kisa = array_slice($etiketler, 0, 6);
        $kosul = []; $params = [':nowts' => now(), ':id' => $id];
        foreach ($kisa as $i => $t) {
            $kosul[] = 'p.tags LIKE :t' . $i;
            $params[':t' . $i] = '%' . str_replace(['%', '_'], ['\%', '\_'], (string)$t) . '%';
        }
        // LIKE geniş eler ("spor" → "e-spor" de gelir); gerçek kesişim aşağıda
        // PHP tarafında tam etiket eşleşmesiyle hesaplanır.
        $adaylar = qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
            FROM posts p LEFT JOIN categories c ON c.id = p.category_id
            WHERE ' . published_where() . ' AND p.id <> :id AND (' . implode(' OR ', $kosul) . ')
            ORDER BY p.published_at DESC LIMIT 60', $params);

        $kendiSlug = [];
        foreach ($etiketler as $t) { $s = slugify($t); if ($s !== '') { $kendiSlug[$s] = true; } }
        $simdi = time();

        foreach ($adaylar as $aday) {
            $ortak = 0;
            foreach (tags_to_array(arr($aday, 'tags', '')) as $t) {
                $s = slugify($t);
                if ($s !== '' && isset($kendiSlug[$s])) { $ortak++; }
            }
            if ($ortak < 1) { continue; }                       // LIKE yanılması
            $puan = $ortak * 10;
            if ((int)arr($aday, 'category_id', 0) === (int)arr($post, 'category_id', -1)) { $puan += 3; }
            // Tazelik: son 7 günde 2, son 30 günde 1, öncesi 0.
            $ts = strtotime((string)arr($aday, 'published_at', '')) ?: 0;
            $gun = $ts > 0 ? ($simdi - $ts) / 86400 : 9999;
            $puan += $gun <= 7 ? 2 : ($gun <= 30 ? 1 : 0);
            $puanli[] = ['puan' => $puan, 'ts' => $ts, 'post' => $aday];
        }
        usort($puanli, function ($a, $b) {
            if ($a['puan'] === $b['puan']) { return $b['ts'] <=> $a['ts']; }
            return $b['puan'] - $a['puan'];
        });
        foreach ($puanli as $p) {
            if (count($secilen) >= $limit) { break; }
            $secilen[(int)$p['post']['id']] = $p['post'];
        }
    }

    // Eksik kalırsa aynı kategoriden tamamla (bugünkü davranış).
    if (count($secilen) < $limit && (int)arr($post, 'category_id', 0) > 0) {
        $kalan = $limit - count($secilen);
        $ek = qa('SELECT ' . post_select_columns() . ', c.name AS category_name, c.slug AS category_slug, c.color AS category_color
            FROM posts p LEFT JOIN categories c ON c.id = p.category_id
            WHERE p.category_id = :c AND p.id <> :i AND ' . published_where() . '
            ORDER BY p.published_at DESC LIMIT ' . ($limit + count($secilen) + 4),
            [':c' => (int)$post['category_id'], ':i' => $id, ':nowts' => now()]);
        foreach ($ek as $e) {
            if ($kalan <= 0) { break; }
            $eid = (int)$e['id'];
            if (isset($secilen[$eid])) { continue; }
            $secilen[$eid] = $e; $kalan--;
        }
    }
    return array_values($secilen);
}
