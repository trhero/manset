<?php
/**
 * inc/cache.php — sayfa önbelleğinin SAF yardımcıları.
 * Dosya sistemine ya da çıktı tamponuna dokunan işlevler (cache_serve, cache_store)
 * burada değil, tests/e2e.sh içinde gerçek sunucuyla denenir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/cache.php';

// ------------------------------------------------------------ üstveri çözümü
test('cache_parse_meta: geçerli satır çözülür', function () {
    $line = cache_meta_prefix() . '{"exp":1700000300,"t":1700000000,"scope":"home","ms":12,"ct":"text/html; charset=utf-8"}-->';
    $m = cache_parse_meta($line);
    dogru(is_array($m), 'geçerli üstveri dizi dönmeli');
    esit(1700000300, $m['exp']);
    esit(1700000000, $m['t']);
    esit('home', $m['scope']);
    esit(12, $m['ms']);
    icerir($m['ct'], 'text/html');
});

test('cache_parse_meta: eksik alanlar varsayılana düşer', function () {
    $line = cache_meta_prefix() . '{"exp":"1700000300","t":"1700000000"}-->';
    $m = cache_parse_meta($line);
    dogru(is_array($m));
    esit(1700000300, $m['exp'], 'exp tamsayıya çevrilmeli');
    esit(1700000000, $m['t']);
    esit('other', $m['scope'], 'kapsam yoksa other');
    esit(0, $m['ms']);
    icerir($m['ct'], 'text/html', 'içerik türü varsayılanı');
});

test('cache_parse_meta: bozuk girdi null döner', function () {
    esit(null, cache_parse_meta(''), 'boş satır');
    esit(null, cache_parse_meta('<!doctype html>'), 'ön ek yok');
    esit(null, cache_parse_meta(cache_meta_prefix() . '{"exp":1,"t":2}'), 'kapanış yok');
    esit(null, cache_parse_meta(cache_meta_prefix() . 'bu json değil-->'), 'bozuk JSON');
    esit(null, cache_parse_meta(cache_meta_prefix() . '{"t":2}-->'), 'exp eksik');
    esit(null, cache_parse_meta(cache_meta_prefix() . '{"exp":1}-->'), 't eksik');
    esit(null, cache_parse_meta(cache_meta_prefix() . '[1,2,3]-->'), 'dizi nesne değil');
    esit(null, cache_parse_meta(' ' . cache_meta_prefix() . '{"exp":1,"t":2}-->'), 'başta boşluk ön eki bozar');
});

// ------------------------------------------------------------ kapsam
test('cache_scope_for: yol → kapsam', function () {
    esit('home', cache_scope_for(''));
    esit('home', cache_scope_for('/'));
    esit('home', cache_scope_for('///'));
    esit('category', cache_scope_for('kategori/gundem'));
    esit('category', cache_scope_for('/kategori/gundem/'));
    esit('tag', cache_scope_for('etiket/deprem'));
    esit('search', cache_scope_for('arama'));
    esit('page', cache_scope_for('sayfa/hakkimizda'));
    esit('page', cache_scope_for('kunye'));
    esit('post:42', cache_scope_for('haber/bir-baslik-42'));
    esit('post:7', cache_scope_for('haber/x-7'));
    esit('other', cache_scope_for('haber/kimliksiz-slug'), 'kimlik yoksa other');
    esit('other', cache_scope_for('admin/panel'));
    esit('other', cache_scope_for('rastgele/yol'));
});

test('cache_list_scopes: liste kapsamları', function () {
    esit(['home', 'category', 'tag', 'search'], cache_list_scopes());
    icermez(cache_list_scopes(), 'page', 'sabit sayfa liste kapsamı değil');
});

test('cache_ttl_for: kapsam ömürleri', function () {
    setting_set('cache_ttl_post', '300');
    setting_set('cache_ttl_home', '60');
    settings_all(true);
    esit(300, cache_ttl_for('post:1'));
    esit(300, cache_ttl_for('page'));
    esit(60, cache_ttl_for('home'));
    esit(60, cache_ttl_for('category'));
    esit(60, cache_ttl_for('search'));
    esit(0, cache_ttl_for('other'), 'bilinmeyen kapsam önbelleğe alınmaz');
    esit(0, cache_ttl_for(''));

    // Sınırlar: haber en çok 1 gün, liste en çok 1 saat, negatif değer 0
    setting_set('cache_ttl_post', '999999');
    setting_set('cache_ttl_home', '999999');
    settings_all(true);
    esit(86400, cache_ttl_for('post:1'), 'haber ömrü 86400 ile sınırlı');
    esit(3600, cache_ttl_for('home'), 'liste ömrü 3600 ile sınırlı');

    setting_set('cache_ttl_post', '-5');
    settings_all(true);
    esit(0, cache_ttl_for('post:1'), 'negatif ömür 0 olmalı');

    setting_set('cache_ttl_post', '300');
    settings_all(true);
});

// ------------------------------------------------------------ sorgu süzgeci
test('cache_query_is_cacheable: beyaz liste', function () {
    dogru(cache_query_is_cacheable([]));
    dogru(cache_query_is_cacheable(['q' => 'deprem']));
    dogru(cache_query_is_cacheable(['sayfa' => '2']));
    dogru(cache_query_is_cacheable(['r' => 'kategori/gundem']));
    dogru(cache_query_is_cacheable(['utm_source' => 'x', 'fbclid' => 'y', 'gclid' => 'z']),
          'izleme parametreleri sessizce yok sayılmalı');
    yanlis(cache_query_is_cacheable(['onizleme' => '1']), 'bilinmeyen parametre önbelleği atlatmalı');
    yanlis(cache_query_is_cacheable(['_p' => '1']));
    yanlis(cache_query_is_cacheable(['q' => 'x', 'gizli' => '1']), 'tek bilinmeyen parametre yeter');
    yanlis(cache_query_is_cacheable('dizi değil'));
    yanlis(cache_query_is_cacheable(null));
});

// ------------------------------------------------------------ anahtar
test('cache_key_for: anahtar üretimi ve ayrışma', function () {
    setting_set('cache_enabled', '1');
    setting_set('cache_ttl_home', '60');
    settings_all(true);

    $a = cache_key_for('/', []);
    esit(40, strlen($a), 'anahtar sha1 uzunluğunda olmalı');
    dogru((bool)preg_match('/^[a-f0-9]{40}$/', $a));
    esit($a, cache_key_for('///', []), 'yol normalleştirilmeli');

    $b = cache_key_for('kategori/gundem', []);
    dogru($a !== $b, 'farklı yol farklı anahtar vermeli');

    $s1 = cache_key_for('/', ['sayfa' => '2']);
    dogru($a !== $s1, 'sayfa numarası anahtara girmeli');

    $q1 = cache_key_for('arama', ['q' => 'deprem']);
    $q2 = cache_key_for('arama', ['q' => 'sel']);
    dogru($q1 !== $q2, 'arama sorgusu anahtara girmeli');

    esit('', cache_key_for('/', ['onizleme' => '1']), 'süzgeçten geçmeyen sorgu anahtarsız');
    esit('', cache_key_for('admin/panel', []), 'ömrü 0 olan kapsam anahtarsız');
    esit('', cache_key_for('haber/kimliksiz', []), 'other kapsamı anahtarsız');

    // İzleme parametreleri anahtarı DEĞİŞTİRMEMELİ
    esit($a, cache_key_for('/', ['utm_source' => 'twitter']), 'utm_* anahtarı bölmemeli');

    setting_set('cache_enabled', '0');
    settings_all(true);
    esit('', cache_key_for('/', []), 'önbellek kapalıyken anahtar üretilmez');
    setting_set('cache_enabled', '1');
    settings_all(true);
});

test('cache_file: anahtardan dosya yolu', function () {
    $k = str_repeat('ab', 20);                    // 40 haneli geçerli sha1 biçimi
    $f = cache_file($k);
    icerir($f, '/ab/ab/' . $k . '.html');
    icerir($f, cache_root());

    esit('', cache_file('kisa'), 'geçersiz uzunluk boş dönmeli');
    esit('', cache_file(''), 'boş anahtar boş dönmeli');
    esit('', cache_file('../../etc/passwd'), 'yol geçişi denemesi reddedilmeli');
    esit($f, cache_file(strtoupper($k)), 'anahtar küçük harfe indirgenmeli');
});

// ------------------------------------------------------------ biçimleme
test('cache_human_size: okunur boyut', function () {
    esit('0 B', cache_human_size(0));
    esit('512 B', cache_human_size(512));
    // Tam sayıya oturan değerlerde ondalık yazılmaz ("1 KB"), ondalık varsa
    // Türkçe ayraç kullanılır ("1,5 KB") — media/backup yardımcılarıyla tutarlı.
    esit('1 KB', cache_human_size(1024));
    esit('1,5 KB', cache_human_size(1536), 'Türkçe ondalık ayracı virgül olmalı');
    esit('2,5 KB', cache_human_size(2560));
    esit('1 MB', cache_human_size(1048576));
    esit('1,5 MB', cache_human_size(1572864), 'MB katında da virgül kullanılmalı');
    esit('1 GB', cache_human_size(1073741824));
    icermez(cache_human_size(1536), '.', 'nokta ondalık ayracı kullanılmamalı');
    esit('0 B', cache_human_size(-5), 'negatif boyut 0 sayılmalı');
    icerir(cache_human_size(20971520), 'MB');
});

test('cache_query_whitelist / ignored kümeleri ayrık', function () {
    $ok = cache_query_whitelist();
    $yok = cache_query_ignored();
    esit([], array_intersect($ok, $yok), 'beyaz liste ile yok sayılanlar kesişmemeli');
    icerir($ok, 'q');
    icerir($ok, 'sayfa');
    icerir($yok, 'utm_source');
    icerir($yok, 'fbclid');
});

// ============================================================ kapsam indeksi (1.3-12)
// Dosya sistemine dokunan indeks işlevleri (cache_index_add/read/clear) burada
// DEĞİL: birim koşusu proje kökündeki gerçek uploads/cache klasörünü kullanır ve
// bir testin geliştirme önbelleğini silmesi kabul edilemez. Onlar yalıtılmış bir
// kurulumda ölçüldü (gelistirme/1.3-ajan-d.md, paralel süreç turu dâhil).

test('cache_tag_clean: etiket normalleştirme', function () {
    esit('post:12', cache_tag_clean('Post:12'));
    esit('cat:gundem', cache_tag_clean(' cat:gundem '));
    esit('', cache_tag_clean(''));
    esit('', cache_tag_clean('cat:gün dem'), 'boşluk içeren etiket reddedilmeli');
    esit('', cache_tag_clean('../../etc/passwd'), 'yol geçişi reddedilmeli');
    esit('', cache_tag_clean('cat:<script>'), 'işaret içeren etiket reddedilmeli');
});

test('cache_path_tags: sayfanın kendi kimliği', function () {
    $ana = cache_path_tags('');
    icerir($ana, 'home');
    icerir($ana, 'scope:home');

    $kat = cache_path_tags('kategori/gundem');
    icerir($kat, 'cat:gundem', 'kategori sayfası kendi kısa adını taşımalı');
    icerir($kat, 'scope:category');
    icermez($kat, 'home', 'kategori sayfası anasayfa etiketi taşımamalı');

    $etk = cache_path_tags('etiket/deprem');
    icerir($etk, 'tag:deprem');

    $hbr = cache_path_tags('haber/bir-baslik-42');
    icerir($hbr, 'post:42');
    icerir($hbr, 'scope:post:42');
});

test('cache_html_tags: çıktıdaki haberler etiket olur', function () {
    $html = '<a href="/haber/ankarada-yangin-12">Y</a>'
          . '<a href="https://ornek.test/haber/izmir-4">İ</a>'
          . '<a href="/?r=haber/sef-kapali-99">S</a>'
          . '<a href="/kategori/gundem">G</a><a href="/etiket/deprem">D</a>';
    $t = cache_html_tags($html);
    icerir($t, 'post:12');
    icerir($t, 'post:4');
    icerir($t, 'post:99', 'SEF kapalı adres de yakalanmalı');
    // Kategori/etiket bağlantıları BİLEREK etikete çevrilmez: menü her sayfada
    // bütün kategorileri listeliyor, çevrilseydi 'cat:*' kapsamı tüm önbelleği
    // düşürürdü (ölçüldü). Kategori etiketi yalnız sayfanın kendi yolundan gelir.
    icermez($t, 'cat:gundem', 'menü bağlantısı kategori kapsamını global yapmamalı');
    icermez($t, 'tag:deprem');
});

test('cache_html_tags: haber geçmeyen çıktı etiket üretmez', function () {
    esit([], cache_html_tags('<p>hiç bağlantı yok</p>'));
    esit([], cache_html_tags(''));
});

test('cache_html_tags: etiket sayısı sınırlı', function () {
    $html = '';
    for ($i = 1; $i <= cache_max_tags() + 50; $i++) { $html .= '<a href="/haber/x-' . $i . '">x</a>'; }
    $t = cache_html_tags($html);
    dogru(count($t) <= cache_max_tags(), 'etiket sayısı tavanı aşmamalı: ' . count($t));
});

test('cache_store_tags: yol + çıktı birleşir', function () {
    $ctx = ['path' => '', 'scope' => 'home'];
    $t = cache_store_tags($ctx, '<a href="/haber/a-5">a</a><a href="/kategori/spor">s</a>');
    icerir($t, 'home', 'anasayfa kendi etiketini taşımalı');
    icerir($t, 'post:5', 'anasayfa listelediği haberin etiketini de taşımalı');
    icermez($t, 'cat:spor', 'menüdeki kategori bağlantısı etiket üretmemeli');

    // Kategori sayfası kendi kısa adını YOLDAN alır
    $k = cache_store_tags(['path' => 'kategori/spor'], '<a href="/haber/b-6">b</a>');
    icerir($k, 'cat:spor');
    icerir($k, 'post:6');
});

test('cache_scope_targets: kapsam → hedef etiketler', function () {
    esit(['post:12'], cache_scope_targets('post:12'));
    esit(['cat:gundem'], cache_scope_targets('cat:gundem'));
    esit(['tag:deprem'], cache_scope_targets('tag:deprem'));
    esit(['home'], cache_scope_targets('home'));
    esit(['scope:page'], cache_scope_targets('page'));
    // 1.2 adları: tek liste türü verilse de bütün liste sayfaları düşer
    $eski = cache_scope_targets('category');
    icerir($eski, 'home');
    icerir($eski, 'scope:category');
    icerir($eski, 'scope:tag');
    icerir($eski, 'scope:search');
    // Bilinmeyen kapsam → null = "hepsini sil" (güvenli taraf)
    esit(null, cache_scope_targets('bilinmeyen'), 'bilinmeyen kapsam geniş düşmeli');
    esit(null, cache_scope_targets('post:0'), 'kimliksiz haber kapsamı geniş düşmeli');
    esit(null, cache_scope_targets(''), 'boş kapsam geniş düşmeli');
});

test('cache_parse_meta: kapsam etiketleri okunur, yoksa null kalır', function () {
    $l = cache_meta_prefix() . '{"exp":9999999999,"t":1,"scope":"home","sc":["home","post:5"]}-->';
    $m = cache_parse_meta($l);
    esit(['home', 'post:5'], $m['sc']);

    $eski = cache_meta_prefix() . '{"exp":9999999999,"t":1,"scope":"home"}-->';
    $m2 = cache_parse_meta($eski);
    esit(null, $m2['sc'], '1.2 üstverisi "bilinmiyor" sayılmalı (hedefli temizlikte silinir)');
});

test('cache_meta_max_bytes: üstveri satırı için okuma tavanı büyütüldü', function () {
    dogru(cache_meta_max_bytes() >= 8192, 'etiket listesi 2 KB satıra sığmaz');
});

test('temizlik damgası mikrosaniye duyarlıdır (aynı saniye tuzağı)', function () {
    // Damga `filemtime()` ile okunduğunda SANİYE çözünürlüklüydü ve karşılaştırma
    // `>=` olduğu için, temizlikle aynı saniye içinde BAŞLAYAN — yani temizlikten
    // hemen SONRAKİ — istek sayfayı önbelleğe hiç yazamıyordu.
    //
    // e2e'de yalnız "anasayfa ikinci istek HIT beklendi" olarak göründü, ama asıl
    // etkisi genişti: canlı sitede her içerik yazımı bir temizlik tetikler, yani
    // o saniyede üretilen HER sayfa boşa üretiliyordu.
    cache_flush_marker_touch();
    $damga = cache_flush_marker_time();
    dogru($damga > 0, 'damga yazılmalı');
    dogru($damga != floor($damga), 'damga kesirli olmalı (saniyeye yuvarlanmış değil): ' . $damga);

    // Temizlikten SONRA başlayan üretim kabul edilmeli.
    usleep(2000);
    $sonra = microtime(true);
    yanlis(cache_flush_marker_time() >= $sonra, 'temizlikten sonra başlayan üretim yazılabilmeli');
});

test('üretim SIRASINDA gelen temizlik bayat sayfayı yazdırmaz', function () {
    // Bu denetimin varlık nedeni: yarım kalmış bir üretim, temizlikten sonra
    // diske düşerse okura ESKİ içerik sunulur. Mikrosaniye düzeltmesi bu
    // korumayı zayıflatmamalı.
    $uretimBasi = microtime(true);
    usleep(3000);
    cache_flush_marker_touch();
    dogru(cache_flush_marker_time() >= $uretimBasi, 'araya giren temizlik yazmayı reddetmeli');
});
