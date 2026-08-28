<?php
/**
 * inc/seo.php — başlık şablonları, elle 301 yöneticisi, ping kuyruğu (Ajan-E).
 *
 * Koşucu yalıtılmış bir SQLite örneği kurar ve migrations_run() 019 göçünü de
 * uygular; bu yüzden redirects / tag_seo / seo_pings tabloları burada gerçektir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

require_once INC_DIR . '/seo.php';

// ---------------------------------------------------------------- şablonlar

test('seo_apply_template: yer tutucular dolar', function () {
    esit('Deprem — Manşet', seo_apply_template('%baslik% — %site%', [
        'baslik' => 'Deprem', 'site' => 'Manşet',
    ]));
});

test('seo_apply_template: boş yer tutucu ayracı da götürür', function () {
    esit('Manşet', seo_apply_template('%site% — %slogan%', ['site' => 'Manşet', 'slogan' => '']),
        'slogansız sitede başlık " — " ile bitmemeli');
    esit('Manşet', seo_apply_template('%slogan% — %site%', ['site' => 'Manşet', 'slogan' => '']));
    esit('A — B', seo_apply_template('%a% — %bos% — %b%', ['a' => 'A', 'bos' => '', 'b' => 'B']),
        'ortadaki boş alan çift ayraç bırakmamalı');
});

test('seo_apply_template: tanınmayan yer tutucu basılmaz', function () {
    icermez(seo_apply_template('%baslik% %uydurma%', ['baslik' => 'X']), '%uydurma%');
    esit('X', seo_apply_template('%baslik% %uydurma%', ['baslik' => 'X']));
});

test('seo_apply_template: boşalan tırnak çifti temizlenir', function () {
    esit('Manşet', seo_apply_template('"%arama%" — %site%', ['arama' => '', 'site' => 'Manşet']));
});

test('seo_apply_template: HTML girdisi metne indirgenir', function () {
    icermez(seo_apply_template('%baslik%', ['baslik' => '<b>Kalın</b>']), '<b>');
});

test('seo_title_templates: ayar şablonu varsayılanı ezer', function () {
    setting_set('seo_title_tpl_post', '%site%: %baslik%');
    settings_all(true);
    $t = seo_title_templates();
    esit('%site%: %baslik%', $t['post']);
    setting_set('seo_title_tpl_post', '');
    settings_all(true);
    esit('%baslik% — %site%', seo_title_templates()['post'], 'boş ayar varsayılana döner');
});

// ---------------------------------------------------------------- 301 kaynak/hedef

test('seo_redirect_clean_source: normalleştirme', function () {
    esit('eski/yol', seo_redirect_clean_source('/eski/yol/'));
    esit('eski/yol', seo_redirect_clean_source('eski//yol'));
    esit('eski/yol', seo_redirect_clean_source('/eski/yol?x=1#z'));
    esit('eski yol', seo_redirect_clean_source('/eski%20yol'));
});

test('seo_redirect_clean_source: korunan yollar reddedilir', function () {
    esit('', seo_redirect_clean_source('/admin/'), 'panel yönlendirilemez');
    esit('', seo_redirect_clean_source('api.php'));
    esit('', seo_redirect_clean_source('/admin/index.php?p=users'));
    esit('', seo_redirect_clean_source('/uploads/a.jpg'));
    esit('', seo_redirect_clean_source('/'), 'anasayfa kaynak olamaz');
    esit('', seo_redirect_clean_source('../../etc/passwd'));
});

test('seo_redirect_clean_target: DIŞ adres reddedilir (açık yönlendirme)', function () {
    yanlis(seo_redirect_clean_target('https://kotu-site.example/giris') !== false,
        'dış http adresi kabul edilmemeli');
    yanlis(seo_redirect_clean_target('//kotu-site.example/x') !== false,
        'protokole göreli adres dış hedeftir');
    yanlis(seo_redirect_clean_target('javascript:alert(1)') !== false);
    yanlis(seo_redirect_clean_target('data:text/html,x') !== false);
    yanlis(seo_redirect_clean_target('/\\kotu-site.example') !== false
           && strpos((string)seo_redirect_clean_target('/\\kotu-site.example'), '//') === 0,
        'ters eğik çizgi ile protokole göreli adres kaçamamalı');
});

test('seo_redirect_clean_target: site içi adres kabul edilir', function () {
    esit('kategori/gundem', seo_redirect_clean_target('/kategori/gundem'));
    esit('kategori/gundem', seo_redirect_clean_target('kategori/gundem'));
    esit('arama?q=deprem', seo_redirect_clean_target('/arama?q=deprem'));
    esit('', seo_redirect_clean_target('/'), 'kök hedefi boş yol olur');
    esit('kategori/gundem', seo_redirect_clean_target(base_url() . '/kategori/gundem'),
        'kendi ana adımızla yazılmış tam adres yola indirgenir');
});

// ---------------------------------------------------------------- 301 kayıt

test('seo_redirect_save: kural yazılır ve çözülür', function () {
    q('DELETE FROM redirects');
    seo_redirect_refresh_count();

    $r = seo_redirect_save(['src_path' => '/2019/eski-yazi', 'target' => '/kategori/gundem', 'code' => 301]);
    dogru($r['ok'], 'kural kaydedilmeli: ' . $r['error']);

    $c = seo_redirect_resolve('2019/eski-yazi');
    esit('kategori/gundem', $c['target']);
    esit(301, $c['code']);
    esit('1', setting('seo_redirect_count', '0'), 'aktif kural sayacı ayara yazılmalı');
});

test('seo_redirect_save: dış hedef reddedilir', function () {
    $r = seo_redirect_save(['src_path' => 'tuzak', 'target' => 'https://kotu-site.example/']);
    yanlis($r['ok'], 'dış hedef kabul edilmemeli');
    icerir($r['error'], 'Dış adrese');
});

test('seo_redirect_save: kendine yönlendirme reddedilir', function () {
    $r = seo_redirect_save(['src_path' => 'a/b', 'target' => '/a/b']);
    yanlis($r['ok']);
});

test('seo_redirect_save: DÖNGÜ reddedilir ve kayıt geri alınır', function () {
    q('DELETE FROM redirects');
    seo_redirect_refresh_count();

    dogru(seo_redirect_save(['src_path' => 'x', 'target' => '/y'])['ok']);
    $r = seo_redirect_save(['src_path' => 'y', 'target' => '/x']);
    yanlis($r['ok'], 'x→y varken y→x döngüdür');
    icerir($r['error'], 'döngü');
    esit(0, (int)qv('SELECT COUNT(*) FROM redirects WHERE src_path = \'y\'', [], 0),
        'reddedilen kural tabloda kalmamalı');
});

test('seo_redirect_resolve: zincir son hedefe iner', function () {
    q('DELETE FROM redirects');
    seo_redirect_refresh_count();
    seo_redirect_save(['src_path' => 'bir', 'target' => '/iki']);
    seo_redirect_save(['src_path' => 'iki', 'target' => '/uc']);
    $c = seo_redirect_resolve('bir');
    esit('uc', $c['target'], 'iki adımlı zincir son hedefi vermeli');
});

test('seo_redirect_delete: sayaç güncellenir', function () {
    q('DELETE FROM redirects');
    seo_redirect_refresh_count();
    $r = seo_redirect_save(['src_path' => 'silinecek', 'target' => '/kategori/gundem']);
    dogru(seo_redirect_delete($r['id']));
    esit('0', setting('seo_redirect_count', '-1'));
});

// ---------------------------------------------------------------- etiket üstverisi

test('seo_tag_meta: yazma, okuma ve silme', function () {
    dogru(seo_tag_meta_save('İstanbul Depremi', 'İstanbul depremi haberleri', 'Son gelişmeler.'));
    $m = seo_tag_meta('İstanbul Depremi');
    esit('İstanbul depremi haberleri', $m['seo_title']);
    esit('istanbul-depremi', $m['slug']);

    // İki alan da boş → kayıt silinir
    dogru(seo_tag_meta_save('İstanbul Depremi', '', ''));
    esit(0, (int)qv('SELECT COUNT(*) FROM tag_seo WHERE slug = \'istanbul-depremi\'', [], 0));
});

// ---------------------------------------------------------------- ping kuyruğu

test('seo_ping_enqueue: yalnız http(s) ve tekil', function () {
    q('DELETE FROM seo_pings');
    dogru(seo_ping_enqueue('https://birim.test/haber/a-1'));
    yanlis(seo_ping_enqueue('https://birim.test/haber/a-1'), 'aynı adres iki kez kuyruğa girmemeli');
    yanlis(seo_ping_enqueue('javascript:alert(1)'));
    yanlis(seo_ping_enqueue('ftp://birim.test/x'));
    esit(1, (int)qv('SELECT COUNT(*) FROM seo_pings', [], 0));
});

test('seo_ping_site_public: yerel adreslere ping atılmaz', function () {
    // Birim testi base_url = http://birim.test → .test uzantısı yerel sayılır
    yanlis(seo_ping_site_public(), 'test alan adı dış dünyaya açık sayılmamalı');
});

test('seo_ping_tick: yerel kurulumda ağa ÇIKMAZ, kuyruk atlanır', function () {
    q('DELETE FROM seo_pings');
    setting_set('seo_ping_last_post_id', '0');
    settings_all(true);
    seo_ping_enqueue('https://birim.test/haber/b-2');
    $ozet = seo_ping_tick();
    icerir($ozet, 'atlandı');
    esit('skipped', (string)qv('SELECT status FROM seo_pings ORDER BY id DESC LIMIT 1', [], ''));
});

test('seo_indexnow_key: üretilir ve kalıcıdır', function () {
    setting_set('seo_indexnow_key', '');
    settings_all(true);
    $k = seo_indexnow_key();
    dogru((bool)preg_match('/^[a-f0-9]{32}$/', $k), 'anahtar 32 haneli onaltılık olmalı: ' . $k);
    esit($k, seo_indexnow_key(), 'ikinci çağrı aynı anahtarı vermeli');
});

// ---------------------------------------------------------------- sameAs / doğrulama

test('seo_same_as: yalnız http(s), en çok 12', function () {
    setting_set('seo_same_as', "https://x.example/manset\njavascript:alert(1)\nhttps://y.example/manset\nsaçma");
    settings_all(true);
    $s = seo_same_as();
    esit(2, count($s));
    icerir($s, 'https://x.example/manset');
    icermez(implode(' ', $s), 'javascript:');
    setting_set('seo_same_as', '');
    settings_all(true);
});

test('seo_verification_metas: biçimsiz kod basılmaz', function () {
    setting_set('seo_verify_google', 'abc<script>');
    settings_all(true);
    icermez(array_keys(seo_verification_metas()), 'google-site-verification');

    setting_set('seo_verify_google', 'AbC123_-.=xyz');
    settings_all(true);
    esit('AbC123_-.=xyz', seo_verification_metas()['google-site-verification']);
    setting_set('seo_verify_google', '');
    settings_all(true);
});

// ---------------------------------------------------------------- robots

test('seo_robots: sayfalı liste ayarla indekse açılabilir', function () {
    $ctx = ['template' => 'category', 'vars' => ['category' => ['id' => 1, 'name' => 'Gündem', 'slug' => 'gundem'], 'page' => 2]];
    setting_set('seo_paged_noindex', '1');
    settings_all(true);
    esit('noindex,follow', seo_robots($ctx));
    setting_set('seo_paged_noindex', '0');
    settings_all(true);
    esit('index,follow', seo_robots($ctx));
    setting_set('seo_paged_noindex', '1');
    settings_all(true);
});
