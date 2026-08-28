<?php
/**
 * inc/discover.php — keşif sayfaları (1.3-10, Ajan-E).
 *
 * Burada ölçülen şey, keşif yüzeyinin iki SIZINTI kapısı ve iki sıralama
 * kuralıdır:
 *   1. etiket bulutunda yalnız YAYINDAKİ haberin etiketi görünür
 *      (çöp kutusu / taslak / zamanlanmış hariç — denetim turu 3, B11);
 *   2. /yazar/ yalnız PERSONEL hesabı tanır (üye adı sızmaz);
 *   3. önceki/sonraki haber (published_at, id) ÇİFTİYLE gezinir;
 *   4. ilgili haber ortak ETİKET sayısına göre puanlanır, kategoriye düşer.
 *
 * Koşucu yalıtılmış bir SQLite örneği kurar ve göçleri uygular; posts.deleted_at
 * ve users.display_name/bio burada gerçektir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

require_once INC_DIR . '/roles.php';
require_once INC_DIR . '/view.php';
require_once INC_DIR . '/discover.php';

// ---------------------------------------------------------------- zemin

/** Test haberi ekler; varsayılan olarak yayında ve geçmiş tarihli. */
function d_haber(array $ek = []) {
    static $n = 0;
    $n++;
    $veri = [
        'title'        => 'Haber ' . $n,
        'slug'         => 'haber-' . $n,
        'spot'         => '',
        'body'         => 'gövde',
        'image'        => '',
        'category_id'  => 1,
        'author_id'    => 0,
        'status'       => 'published',
        'type'         => 'haber',
        'tags'         => '',
        'published_at' => date('Y-m-d H:i:s', time() - 86400 * $n),
        'deleted_at'   => '',
    ] + $ek;
    foreach ($ek as $k => $v) { $veri[$k] = $v; }
    return (int)db_insert('posts', $veri);
}

/** Test kullanıcısı ekler. */
function d_kullanici($ad, $rol, array $ek = []) {
    $veri = [
        'name' => $ad, 'email' => strtolower($rol) . '-' . $ad . '@ornek.test',
        'pass_hash' => 'x', 'role' => $rol, 'active' => 1, 'created_at' => now(),
    ];
    // Dizi birleşiminde SOL taraf kazanır; ek alanlar tabanı ezsin diye ayrı döngü.
    foreach ($ek as $k => $v) { $veri[$k] = $v; }
    return (int)db_insert('users', $veri);
}

// Zemin: bir kategori, iki personel, bir üye.
db_insert('categories', ['name' => 'Gündem', 'slug' => 'gundem', 'sort' => 1, 'color' => '#c0392b', 'in_menu' => 1, 'active' => 1]);
$GLOBALS['d_yazar']  = d_kullanici('Ayşe Yılmaz', 'yazar', ['display_name' => 'Ayşe Yılmaz', 'bio' => 'Ekonomi muhabiri.']);
$GLOBALS['d_pasif']  = d_kullanici('Pasif Kalem', 'yazar', ['active' => 0]);
$GLOBALS['d_uye']    = d_kullanici('Gizli Okur', 'member');

// ---------------------------------------------------------------- yazar kapısı

test('discover_authors: üye hesabı listeye GİRMEZ', function () {
    $hepsi = discover_authors();
    dogru(isset($hepsi[$GLOBALS['d_yazar']]), 'personel yazar listede olmalı');
    yanlis(isset($hepsi[$GLOBALS['d_uye']]), 'üye (member) hesabı yazar listesine giremez');
    yanlis(isset($hepsi[$GLOBALS['d_pasif']]), 'pasif hesap yazar listesine giremez');
});

test('discover_author_by_slug: üye adının slug\'ı yazar açmaz', function () {
    yanlis((bool)discover_author_by_slug('gizli-okur'), 'üye adı slug ile çözülmemeli');
    yanlis((bool)discover_author_by_slug('pasif-kalem'), 'pasif hesap slug ile çözülmemeli');
    $a = discover_author_by_slug('ayse-yilmaz');
    dogru((bool)$a, 'personel yazar slug ile çözülmeli');
    esit('Ayşe Yılmaz', $a['name']);
    esit('Yazar / Köşe Yazarı', $a['role_label'], 'künyedeki rol basılmalı');
    esit('Ekonomi muhabiri.', $a['bio']);
});

test('discover_author_url: yayında haberi olmayan hesap bağlantı almaz', function () {
    esit('', discover_author_url(['author_id' => $GLOBALS['d_yazar']]), 'haberi yokken bağlantı boş olmalı');
    d_haber(['author_id' => $GLOBALS['d_yazar'], 'title' => 'İlk imza']);
    // Birim ortamında SEF kapalı: adres ?r=yazar%2Fayse-yilmaz biçimindedir.
    icerir(rawurldecode(discover_author_url(['author_id' => $GLOBALS['d_yazar']])), 'yazar/ayse-yilmaz');
    esit('', discover_author_url(['author_id' => $GLOBALS['d_uye']]), 'üye hesabına asla bağlantı verilmez');
    esit('', discover_author_url(['author_id' => 0]));
});

test('discover_author_posts: çöp kutusundaki imza sayılmaz', function () {
    $id = $GLOBALS['d_yazar'];
    $once = discover_author_post_count($id);
    d_haber(['author_id' => $id, 'title' => 'Silinmiş imza', 'deleted_at' => now()]);
    d_haber(['author_id' => $id, 'title' => 'Taslak imza', 'status' => 'draft']);
    esit($once, discover_author_post_count($id), 'çöp kutusu ve taslak yazar sayacına girmemeli');
    foreach (discover_author_posts($id, 20) as $p) {
        icermez((string)$p['title'], 'Silinmiş imza');
        icermez((string)$p['title'], 'Taslak imza');
    }
});

// ---------------------------------------------------------------- etiket bulutu

test('discover_tag_counts: yalnız yayındaki haberin etiketi görünür', function () {
    d_haber(['tags' => 'deprem, afet', 'title' => 'Yayında 1']);
    d_haber(['tags' => 'deprem', 'title' => 'Yayında 2']);
    d_haber(['tags' => 'coptekietiket', 'title' => 'Çöpte', 'deleted_at' => now()]);
    d_haber(['tags' => 'taslaketiketi', 'title' => 'Taslak', 'status' => 'draft']);
    d_haber(['tags' => 'gelecetiketi', 'title' => 'Zamanlanmış',
             'published_at' => date('Y-m-d H:i:s', time() + 86400)]);

    $sluglar = [];
    foreach (discover_tag_counts(200) as $t) { $sluglar[$t['slug']] = $t['count']; }

    dogru(isset($sluglar['deprem']), 'yayındaki etiket bulutta olmalı');
    esit(2, $sluglar['deprem'], 'iki haberde geçen etiket 2 saymalı');
    yanlis(isset($sluglar['coptekietiket']), 'ÇÖP KUTUSUNDAKİ haberin etiketi bulutta görünemez (B11)');
    yanlis(isset($sluglar['taslaketiketi']), 'taslak haberin etiketi bulutta görünemez');
    yanlis(isset($sluglar['gelecetiketi']), 'zamanlanmış haberin etiketi bulutta görünemez');
});

test('discover_tag_counts: ağırlık 1..5 arasında ve sık etiket daha ağır', function () {
    $liste = discover_tag_counts(200);
    dogru(count($liste) > 0);
    $ilk = $liste[0];
    foreach ($liste as $t) {
        dogru($t['weight'] >= 1 && $t['weight'] <= 5, 'ağırlık 1..5 dışına çıkmamalı');
        dogru($t['count'] <= $ilk['count'], 'liste kullanıma göre azalan sırada olmalı');
    }
});

// ---------------------------------------------------------------- arşiv

test('discover_tag_weights: eşit sıklıkta ağırlık AYIRT ETMEZ', function () {
    // Yeni bir sitede her etiket bir kez geçer; hepsini en büyük puntoyla
    // basmak "bunlar popüler" yalanını söylerdi.
    $w = discover_tag_weights([
        ['label' => 'a', 'slug' => 'a', 'count' => 1],
        ['label' => 'b', 'slug' => 'b', 'count' => 1],
    ]);
    esit(2, $w[0]['weight']);
    esit(2, $w[1]['weight']);
});

test('discover_tag_weights: logaritmik ölçek tek popüler etikete ezdirmez', function () {
    $w = discover_tag_weights([
        ['label' => 'dev', 'slug' => 'dev', 'count' => 100],
        ['label' => 'orta', 'slug' => 'orta', 'count' => 10],
        ['label' => 'az', 'slug' => 'az', 'count' => 1],
    ]);
    esit(5, $w[0]['weight'], 'en sık etiket en büyük ağırlığı almalı');
    dogru($w[1]['weight'] > 1, 'doğrusal ölçekte 1 kalırdı: 10/100 → 0,5 punto');
    dogru($w[1]['weight'] < 5 && $w[2]['weight'] < $w[1]['weight'], 'sıra korunmalı');
    dogru($w[2]['weight'] >= 1);
});

test('discover_tag_weights: boş liste boş döner', function () {
    esit([], discover_tag_weights([]));
});

test('discover_archive_range: yıl ve ay penceresi', function () {
    esit(['2026-01-01 00:00:00', '2027-01-01 00:00:00'], discover_archive_range(2026, 0));
    esit(['2026-08-01 00:00:00', '2026-09-01 00:00:00'], discover_archive_range(2026, 8));
    esit(['2026-12-01 00:00:00', '2027-01-01 00:00:00'], discover_archive_range(2026, 12),
        'aralık penceresi yılı çevirmeli');
});

test('discover_archive_years: yalnız içeriği olan yıl listelenir', function () {
    $y = discover_archive_years();
    $buYil = (int)date('Y');
    dogru(isset($y[$buYil]), 'bu yılın haberleri var, yıl listede olmalı');
    yanlis(isset($y[1999]), 'içeriği olmayan yıl arşivde adres almamalı');
});

test('discover_archive_count: çöp kutusu penceresi şişirmez', function () {
    $buYil = (int)date('Y');
    $once = discover_archive_count($buYil, 0);
    d_haber(['title' => 'Arşiv çöpü', 'deleted_at' => now()]);
    esit($once, discover_archive_count($buYil, 0));
});

// ---------------------------------------------------------------- önceki / sonraki

test('discover_adjacent_posts: eski/yeni yönü doğru', function () {
    $eski = d_haber(['title' => 'Eski', 'published_at' => '2026-03-01 10:00:00']);
    $orta = d_haber(['title' => 'Orta', 'published_at' => '2026-03-02 10:00:00']);
    $yeni = d_haber(['title' => 'Yeni', 'published_at' => '2026-03-03 10:00:00']);

    $k = discover_adjacent_posts(post_by_id($orta));
    esit($eski, (int)$k['prev']['id'], 'önceki = daha ESKİ haber');
    esit($yeni, (int)$k['next']['id'], 'sonraki = daha YENİ haber');
});

test('discover_adjacent_posts: aynı dakikada girilen iki haber kendi üstüne kapanmaz', function () {
    $a = d_haber(['title' => 'Eş zaman A', 'published_at' => '2026-04-01 09:00:00']);
    $b = d_haber(['title' => 'Eş zaman B', 'published_at' => '2026-04-01 09:00:00']);
    $ka = discover_adjacent_posts(post_by_id($a));
    $kb = discover_adjacent_posts(post_by_id($b));
    esit($b, (int)$ka['next']['id'], 'eşit tarihte id küçükten büyüğe ilerlemeli');
    esit($a, (int)$kb['prev']['id']);
    dogru(empty($ka['prev']) || (int)$ka['prev']['id'] !== $b, 'B hem önceki hem sonraki olamaz');
});

test('discover_adjacent_posts: çöp kutusundaki haber komşu olarak gösterilmez', function () {
    $x = d_haber(['title' => 'Komşu zinciri 1', 'published_at' => '2026-05-01 09:00:00']);
    d_haber(['title' => 'Komşu zinciri ÇÖP', 'published_at' => '2026-05-02 09:00:00', 'deleted_at' => now()]);
    $z = d_haber(['title' => 'Komşu zinciri 3', 'published_at' => '2026-05-03 09:00:00']);
    $k = discover_adjacent_posts(post_by_id($x));
    dogru(!empty($k['next']), 'zincir kopmamalı');
    esit($z, (int)$k['next']['id'], 'silinmiş haber atlanmalı');
});

// ---------------------------------------------------------------- ilgili haber

test('discover_related_posts: ortak etiketi çok olan öne geçer', function () {
    db_insert('categories', ['name' => 'Spor', 'slug' => 'spor', 'sort' => 2, 'color' => '#27632a', 'in_menu' => 1, 'active' => 1]);
    $sporId = (int)qv('SELECT id FROM categories WHERE slug = :s', [':s' => 'spor'], 0);

    $ana = d_haber(['title' => 'Ana haber', 'tags' => 'sel, iklim, rapor', 'category_id' => 1,
                    'published_at' => '2026-06-10 10:00:00']);
    $ikiOrtak = d_haber(['title' => 'İki ortak etiket', 'tags' => 'sel, iklim', 'category_id' => $sporId,
                         'published_at' => '2026-06-09 10:00:00']);
    $birOrtak = d_haber(['title' => 'Bir ortak etiket', 'tags' => 'iklim', 'category_id' => 1,
                         'published_at' => '2026-06-08 10:00:00']);

    $ilgili = discover_related_posts(post_by_id($ana), 4);
    dogru(count($ilgili) > 0);
    esit($ikiOrtak, (int)$ilgili[0]['id'], 'iki ortak etiketli haber, bir ortak etiketliden önce gelmeli');
    $idler = array_map(function ($p) { return (int)$p['id']; }, $ilgili);
    dogru(in_array($birOrtak, $idler, true), 'bir ortak etiketli haber de listede olmalı');
    yanlis(in_array($ana, $idler, true), 'haber kendi ilgilisi olamaz');
});

test('discover_related_posts: etiket eşleşmesi yoksa kategoriye düşer', function () {
    $ana = d_haber(['title' => 'Etiketsiz ana', 'tags' => '', 'category_id' => 1,
                    'published_at' => '2026-07-01 10:00:00']);
    $ilgili = discover_related_posts(post_by_id($ana), 3);
    dogru(count($ilgili) > 0, 'etiket yoksa kutu boş kalmamalı — kategoriye düşülür');
    foreach ($ilgili as $p) { esit(1, (int)$p['category_id'], 'yedek liste aynı kategoriden gelmeli'); }
});

test('discover_related_posts: LIKE yanılması puanlanmaz', function () {
    // 'spor' etiketi 'e-spor' içinde geçer; LIKE ikisini de getirir, puanlama
    // TAM etiket eşleşmesine bakar. Kategori yedeği devreye girmesin diye
    // aday ve tuzak AYRI kategorilerde tutulur.
    $ana   = d_haber(['title' => 'Spor ana', 'tags' => 'spor', 'category_id' => 0,
                      'published_at' => '2026-07-05 10:00:00']);
    $tuzak = d_haber(['title' => 'E-spor tuzağı', 'tags' => 'e-spor', 'category_id' => 0,
                      'published_at' => '2026-07-04 10:00:00']);
    $idler = array_map(function ($p) { return (int)$p['id']; }, discover_related_posts(post_by_id($ana), 4));
    yanlis(in_array($tuzak, $idler, true), 'e-spor, spor etiketiyle kesişmiş sayılmamalı');
});

// ---------------------------------------------------------------- tema sözleşmesi

test('beş tema da haber sonu gezinmesini çağırıyor', function () {
    foreach (['gazete', 'kagit', 'bulten', 'gece', 'yerel'] as $tema) {
        $s = (string)@file_get_contents(THEMES_DIR . '/' . $tema . '/single.php');
        icerir($s, "part('haber-gezinme'", $tema . ' teması önceki/sonraki şeridini basmalı');
        icerir($s, 'discover_related_posts', $tema . ' teması akıllı ilgili haberi kullanmalı');
        icerir($s, 'discover_author_url', $tema . ' teması yazar sayfasına bağlanmalı');
    }
});

test('beş temanın alt bilgisi keşif sayfalarına bağlanıyor', function () {
    foreach (['gazete', 'kagit', 'bulten', 'gece', 'yerel'] as $tema) {
        $s = (string)@file_get_contents(THEMES_DIR . '/' . $tema . '/parts/footer.php');
        icerir($s, "url('etiketler')", $tema . ' alt bilgisi etiket bulutuna bağlanmalı');
        icerir($s, 'discover_archive_years', $tema . ' alt bilgisi arşive bağlanmalı (yıl veriden)');
    }
});

test('keşif şablonları beş temada çözülür (gazete yedeği)', function () {
    foreach (['kesif-yazar', 'kesif-arsiv', 'kesif-etiketler'] as $sablon) {
        dogru(is_file(THEMES_DIR . '/gazete/' . $sablon . '.php'), $sablon . ' ortak yedeği bulunmalı');
    }
    dogru(is_file(THEMES_DIR . '/gazete/parts/haber-gezinme.php'));
});

test('yeni keşif ögeleri beş temada da boyanıyor', function () {
    foreach (['gazete', 'kagit', 'bulten', 'gece', 'yerel'] as $tema) {
        $css = (string)@file_get_contents(THEMES_DIR . '/' . $tema . '/style.css');
        foreach (['.haber-gezinme', '.etiket-bulutu', '.arsiv-gezinme', '.kesif-izgara'] as $sinif) {
            icerir($css, $sinif, $tema . ' temasında ' . $sinif . ' kuralı olmalı');
        }
        // Kontrast kuralı: yeni ögeler sabit renk DEĞİL, tema belirteci kullanır.
        icermez(substr($css, strpos($css, 'keşif sayfaları (1.3-10)') ?: 0), 'color:#',
            $tema . ' keşif bloğunda sabit renk olmamalı (koyu modda ölçüsüz kalır)');
    }
});
