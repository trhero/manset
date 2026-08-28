<?php
/**
 * inc/search.php — tam metin arama, kaçış, vurgu, etiket normalizasyonu (Ajan-A).
 *
 * Koşucu yalıtılmış bir SQLite örneği kurar ve `migrations_run()` 023 göçünü de
 * uygular; bu yüzden `search_index` (FTS5), `tags` ve `post_tags` burada
 * GERÇEKTİR. FTS5 derlemede yoksa göç sessizce vazgeçer — o durumda tam metine
 * bağlı testler atlanır ve LIKE davranışı sınanır, çünkü sözleşmenin asıl
 * iddiası "arama hiçbir kurulumda bozulmaz"dır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

require_once INC_DIR . '/view.php';
require_once INC_DIR . '/search.php';

$ftsVar = (search_engine() === 'fts5');

/** Test haberi ekler ve kimliğini döndürür. */
function s_haber($baslik, $spot, $govde, $etiket = '', $durum = 'published') {
    return db_insert('posts', [
        'title' => $baslik, 'slug' => unique_slug('posts', slugify($baslik)),
        'spot' => $spot, 'body' => $govde, 'image' => '',
        'category_id' => 1, 'author_id' => 1, 'status' => $durum, 'type' => 'haber',
        'tags' => $etiket, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// ---------------------------------------------------------------- katlama

test('search_fold: Türkçe harfler ASCII karşılığına katlanır', function () {
    esit('isik', search_fold('Işık'));
    esit('isik', search_fold('ışık'));
    esit('isik', search_fold('ISIK'));
    esit('istanbul', search_fold('İSTANBUL'));
    esit('cicek gogus uzum sarki', search_fold('Çiçek göğüs üzüm şarkı'));
});

test('search_fold: eşleme 1:1 — karakter sayısı korunur', function () {
    // Vurgu, katlanmış metinde bulduğu konumu HAM metinde kullanır; sayı
    // kayarsa <mark> yanlış harflere düşer.
    foreach (['Işık kirliliği', 'ÇĞİÖŞÜçğıöşü', 'Ankara 2026', 'Ünlü şarkıcı'] as $m) {
        esit(mb_strlen($m), mb_strlen(search_fold($m)), $m);
    }
});

test('search_fold: SQL katlama ifadesi PHP ile aynı sonucu verir', function () {
    // İki taraf ayrışırsa dizindeki biçimle sorgudaki biçim tutmaz ve arama
    // sessizce boş döner — bu testin varlık nedeni tam olarak budur.
    foreach (['Işık', 'İSTANBUL', 'Çiçek Göğüs', 'ÜZÜM şarkı', 'Ali Veli 2026'] as $m) {
        $sql = qv('SELECT ' . search_sqlite_fold_expr(':m'), [':m' => $m], '');
        esit(search_fold($m), (string)$sql, $m);
    }
});

// ---------------------------------------------------------------- belirteçler

test('search_tokens: FTS5/boolean işleçleri girdiden düşer', function () {
    esit(['or', 'x'], search_tokens('" OR x'));
    esit([], search_tokens('*'));
    esit([], search_tokens('"'));
    esit([], search_tokens('- -'));
    esit(['near', '3', 'abc'], search_tokens('NEAR/3 abc'));
    esit([], search_tokens('🙂🙂'));
    esit([], search_tokens(''));
    esit(['drop', 'table', 'posts'], search_tokens('\'; DROP TABLE posts--'));
});

test('search_tokens: uzunluk ve sayı sınırlıdır', function () {
    $uzun = search_tokens(str_repeat('a', 5000));
    esit(1, count($uzun));
    esit(32, mb_strlen($uzun[0]), 'tek belirteç 32 karakterle sınırlı');
    esit(8, count(search_tokens('a b c d e f g h i j k l')), 'en çok 8 belirteç');
    esit(['ali'], search_tokens('Ali ali ALİ'), 'katlandıktan sonra tekrarlar birleşir');
});

// ---------------------------------------------------------------- vurgu (XSS)

test('search_highlight_html: eşleşme <mark> ile işaretlenir', function () {
    $h = search_highlight_html('Belediye meclisi toplandı', ['meclisi']);
    icerir($h, '<mark>meclisi</mark>');
});

test('search_highlight_html: Türkçe eşleşme doğru harflerin üstüne düşer', function () {
    $h = search_highlight_html('Kentte ışık kirliliği arttı', search_tokens('ISIK'));
    icerir($h, '<mark>ışık</mark>');
});

test('search_highlight_html: HTML ve arama ifadesi kaçırılır (XSS)', function () {
    $h = search_highlight_html('<p>zehirli "><svg onload=alert(1)> kalabalik</p>', ['kalabalik']);
    icermez($h, '<svg');
    icermez($h, 'onload=alert');
    icermez($h, '<p>');
    icerir($h, '<mark>kalabalik</mark>');

    // Arama ifadesinin KENDİSİ çıktıya konmaz; yalnız metindeki eşleşen parça.
    $h2 = search_highlight_html('sıradan bir metin', ['<script>alert(1)</script>']);
    icermez($h2, '<script>');

    // Metindeki < > çıktıda kaçırılmış olmalı.
    $h3 = search_highlight_html('5 < 7 ve 9 > 3 kalabalik', ['kalabalik']);
    icermez($h3, ' < ');
});

test('search_highlight_html: eşleşme yoksa da güvenli metin döner', function () {
    $h = search_highlight_html('<b>düz</b> metin', ['bulunmayan']);
    icermez($h, '<b>');
    icermez($h, '<mark>');
});

// ---------------------------------------------------------------- sorgu

test('search_query: bozuk girdi istisna fırlatmaz ve tablo bozulmaz', function () {
    $once = (int)qv('SELECT COUNT(*) FROM posts', [], 0);
    foreach (['" OR x', '*', 'NEAR/3 abc', '"', str_repeat('a', 5000), '🙂', '',
              '\'; DROP TABLE posts--', 'ab*"'] as $b) {
        $r = search_query($b);
        dogru(is_array($r) && array_key_exists('items', $r) && array_key_exists('total', $r), $b);
    }
    esit($once, (int)qv('SELECT COUNT(*) FROM posts', [], 0), 'posts tablosu duruyor');
});

test('search_query: gövdede geçen sözcük bulunur (1.3 kazanımı)', function () use ($ftsVar) {
    if (!$ftsVar) { dogru(true, 'FTS5 yok — atlandı'); return; }
    s_haber('Belediye meclisi toplandı', 'İmar planı görüşüldü',
        '<p>Toplantıda konuşan <strong>Zeynep Karaduman</strong> ışık kirliliğine değindi.</p>', 'meclis, imar');

    esit(1, search_query('Karaduman')['total'], 'gövdedeki isim bulunmalı');
    esit(1, search_query('ISIK')['total'], 'Türkçe katlama: ISIK → ışık');
    esit(1, search_query('ışık')['total']);
    esit(1, search_query('zeynep karaduman')['total'], 'iki belirteç AND ile birleşir');
    esit(0, search_query('karaduman bulunmayankelime')['total'], 'AND: eksik belirteç sonucu düşürür');
});

test('search_query: vurgulu alanlar sonuçlara eklenir', function () use ($ftsVar) {
    if (!$ftsVar) { dogru(true, 'FTS5 yok — atlandı'); return; }
    $r = search_query('Karaduman', ['highlight' => true]);
    dogru(!empty($r['items']), 'sonuç var');
    if (empty($r['items'])) { return; }
    icerir($r['items'][0]['search_excerpt_html'], '<mark>Karaduman</mark>');
    // Kart şablonlarının bastığı alanlar DEĞİŞMEZ (ham HTML sızmasın).
    icermez((string)$r['items'][0]['title'], '<mark>');
});

test('search_query: taslak ve çöp kutusundaki haber sonuçlara girmez', function () use ($ftsVar) {
    if (!$ftsVar) { dogru(true, 'FTS5 yok — atlandı'); return; }
    $id = s_haber('Taslak haber', '', '<p>gizlikelimebir</p>', '', 'draft');
    esit(0, search_query('gizlikelimebir')['total'], 'taslak görünmemeli');
    db_update('posts', ['status' => 'published'], 'id = :i', [':i' => $id]);
    esit(1, search_query('gizlikelimebir')['total'], 'yayına alınınca görünmeli');
    if (schema_has_column('posts', 'deleted_at')) {
        db_update('posts', ['deleted_at' => now()], 'id = :i', [':i' => $id]);
        esit(0, search_query('gizlikelimebir')['total'], 'çöp kutusundaki görünmemeli');
    }
});

test('search_index: dizin ekleme/düzenleme/silmede güncel kalır', function () use ($ftsVar) {
    if (!$ftsVar) { dogru(true, 'FTS5 yok — atlandı'); return; }
    $id = s_haber('Gecici haber', '', '<p>portakalbahcesi</p>');
    esit(1, search_query('portakalbahcesi')['total'], 'ekleme tetikleyicisi');
    db_update('posts', ['body' => '<p>mandalinabahcesi</p>'], 'id = :i', [':i' => $id]);
    esit(0, search_query('portakalbahcesi')['total'], 'düzenleme eski metni düşürür');
    esit(1, search_query('mandalinabahcesi')['total'], 'düzenleme yeni metni ekler');
    q('DELETE FROM posts WHERE id = :i', [':i' => $id]);
    esit(0, search_query('mandalinabahcesi')['total'], 'silme tetikleyicisi');
    esit(0, (int)qv('SELECT COUNT(*) FROM search_index WHERE rowid = :i', [':i' => $id], 0),
        'dizinde artık satır kalmamalı');
});

test('search_query: kategori ve tarih süzgeci', function () use ($ftsVar) {
    if (!$ftsVar) { dogru(true, 'FTS5 yok — atlandı'); return; }
    $id = s_haber('Suzgec denemesi', '', '<p>suzgeckelimesi</p>');
    db_update('posts', ['category_id' => 42], 'id = :i', [':i' => $id]);
    esit(1, search_query('suzgeckelimesi', ['category' => 42])['total']);
    esit(0, search_query('suzgeckelimesi', ['category' => 43])['total']);
    $dun = date('Y-m-d', time() - 86400);
    $yarin = date('Y-m-d', time() + 86400);
    esit(1, search_query('suzgeckelimesi', ['from' => $dun])['total']);
    esit(0, search_query('suzgeckelimesi', ['from' => $yarin])['total']);
    esit(1, search_query('suzgeckelimesi', ['to' => $yarin])['total']);
    esit(0, search_query('suzgeckelimesi', ['to' => $dun])['total']);
    // Bozuk tarih sessizce yok sayılır (SQL'e hiç ulaşmaz).
    esit(1, search_query('suzgeckelimesi', ['from' => '2026-13-99 OR 1=1'])['total']);
});

test('search_fts_query: motor kapalıyken null döner (çekirdek LIKE\'a düşer)', function () {
    setting_set('search_fts_enabled', '0');
    settings_all(true);
    search_engine_reset();
    esit('', search_engine());
    esit(null, search_fts_query('karaduman', 1, 12), 'süzgeçsiz aramada null → çekirdek LIKE');

    // Kapalıyken bile arama çalışır: başlık LIKE ile bulunur.
    dogru(search_posts('Belediye', 1, 12)['total'] >= 1, 'LIKE yolu başlığı bulur');

    setting_set('search_fts_enabled', '1');
    settings_all(true);
    search_engine_reset();
});

test('search_posts: kanca sonrası sözleşme aynı (items + total)', function () {
    $r = search_posts('Belediye', 1, 5);
    dogru(is_array($r) && isset($r['items']) && isset($r['total']));
    dogru(is_array($r['items']));
    if ($r['items']) {
        foreach (['id', 'title', 'slug', 'spot', 'image', 'category_id', 'type', 'published_at', 'visibility'] as $c) {
            dogru(array_key_exists($c, $r['items'][0]), 'kart şablonunun beklediği sütun: ' . $c);
        }
    }
});

// ---------------------------------------------------------------- etiketler

test('search_tags: posts.tags → tags + post_tags normalize edilir', function () {
    dogru(search_tags_ready(), 'göç tabloları kurulu');
    $id = s_haber('Etiketli haber', '', '<p>etiketgovde</p>', 'Deprem, Acil Durum, Deprem');
    esit(2, search_tags_sync_post($id), 'tekrar eden etiket bir kez sayılır');
    $slugs = [];
    foreach (qa('SELECT t.slug FROM tags t JOIN post_tags pt ON pt.tag_id = t.id WHERE pt.post_id = :i',
                [':i' => $id]) as $r) { $slugs[] = $r['slug']; }
    sort($slugs);
    esit(['acil-durum', 'deprem'], $slugs);

    // Etiket düzenlenince eski bağ kopar.
    db_update('posts', ['tags' => 'Deprem'], 'id = :i', [':i' => $id]);
    search_tags_sync_post($id);
    esit(1, (int)qv('SELECT COUNT(*) FROM post_tags WHERE post_id = :i', [':i' => $id], 0));
});

test('search_tags: göç var olan etiketleri geriye dönük doldurdu', function () {
    // Göç sırasında eklenmiş haber yoktu; burada toplu eşitlemeyi sınıyoruz.
    $id = s_haber('Geriye donuk', '', '<p>geriyedonuk</p>', 'Ekonomi');
    esit(0, (int)qv('SELECT COUNT(*) FROM post_tags WHERE post_id = :i', [':i' => $id], 0),
        'eşitlemeden önce satır yok');
    search_tags_sync_all(0, '');
    esit(1, (int)qv('SELECT COUNT(*) FROM post_tags WHERE post_id = :i', [':i' => $id], 0),
        'toplu eşitleme dolduruyor');
});

test('search_tag_cloud: yayında olmayan haber sayılmaz', function () {
    $id = s_haber('Bulut taslagi', '', '<p>bulutgovde</p>', 'BulutEtiketi', 'draft');
    search_tags_sync_post($id);
    $bulut = search_tag_cloud(200);
    $bulunan = 0;
    foreach ($bulut as $t) { if ($t['slug'] === 'bulutetiketi') { $bulunan = (int)$t['post_count']; } }
    esit(0, $bulunan, 'taslak haber etiket bulutunda sayılmamalı');
    db_update('posts', ['status' => 'published'], 'id = :i', [':i' => $id]);
    $bulut = search_tag_cloud(200);
    foreach ($bulut as $t) { if ($t['slug'] === 'bulutetiketi') { $bulunan = (int)$t['post_count']; } }
    esit(1, $bulunan, 'yayına alınınca sayılmalı');
});

test('tag_posts: 1.2 davranışı bozulmadı (posts.tags hâlâ kaynak)', function () {
    $id = s_haber('Eski yol', '', '<p>eskiyolgovde</p>', 'Deprem');
    $liste = tag_posts('Deprem', 20, 0);
    $idler = array_map(function ($r) { return (int)$r['id']; }, $liste);
    dogru(in_array($id, $idler, true), 'tag_posts posts.tags üzerinden bulmalı');
    dogru(tag_posts_count('Deprem') >= 1);
});

test('search_cron_tick: düşen tetikleyiciyi yeniden kurar', function () use ($ftsVar) {
    if (!$ftsVar) { dogru(true, 'FTS5 yok — atlandı'); return; }
    db()->exec('DROP TRIGGER IF EXISTS search_index_au');
    yanlis(search_sqlite_triggers_ok(), 'tetikleyici düşürüldü');
    search_cron_tick();
    dogru(search_sqlite_triggers_ok(), 'cron turu tetikleyicileri geri kurdu');
    // Dizin de tazelenmiş olmalı.
    $id = s_haber('Cron sonrasi', '', '<p>cronsonrasigovde</p>');
    esit(1, search_query('cronsonrasigovde')['total']);
    q('DELETE FROM posts WHERE id = :i', [':i' => $id]);
});
