<?php
/**
 * inc/revisions.php — sürüm geçmişi, otomatik kayıt, düzenleme kilidi. (Ajan-F · 1.3-01, 1.3-02)
 *
 * Sınanan iddialar:
 *
 *   1. Sürüm TAM KOPYADIR: gövde ve üstveri birebir geri okunur.
 *   2. Aynı içerik ikinci kez YAZILMAZ (`content_hash`) — boşta bekleyen bir
 *      sekme 20 sürümlük pencereyi aynı satırlarla doldurmasın.
 *   3. Budama otomatiktir: haber başına en çok REVISIONS_KEEP sürüm kalır.
 *   4. Otomatik kayıtlar YIĞILMAZ: kullanıcı başına yalnız EN YENİ `auto` durur.
 *   5. **Otomatik kayıt `posts` satırını DEĞİŞTİRMEZ** — 1.3-01'in asıl güvencesi.
 *      Yayımlanmış bir haberin gövdesi, otomatik kayıttan sonra da aynıdır.
 *   6. Kilit ATOMİKTİR: aynı kilidi iki farklı kullanıcı alamaz.
 *   7. Kilit ZAMAN AŞIMINA UĞRAR: damgası eskiyen kilit devralınabilir.
 *   8. Devralma KARŞILAŞTIR-VE-DEĞİŞTİR'dir: ekranda görülen sahip artık sahip
 *      değilse devralma başarısız olur (iki eşzamanlı devralmadan biri kazanır).
 *   9. Kilidi yalnız SAHİBİ bırakabilir.
 *  10. "Revizyon istendi" durumu bayrak sütunu olmadan, son nottan okunur ve
 *      haber yeniden onaya gönderilince kendiliğinden düşer.
 *  11. `published_by` bir kez yazılır; ikinci yayımlama ilk izi SİLMEZ.
 *
 * ÖLÇÜM NOTU: gerçek YARIŞ (paralel süreç) burada ölçülemez — birim koşucusu
 * tek süreçtir. Yarış ölçümü `gelistirme/1.3-ajan-f.md` içinde, yalıtılmış bir
 * kurulumda 10 paralel PHP süreciyle yapılmıştır. Buradaki 6–9 numaralı testler
 * kilidin MANTIĞINI (kim alır, ne zaman düşer) sabitler.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/roles.php';
require_once INC_DIR . '/revisions.php';

// ------------------------------------------------------------ ortam
if (!revisions_ready()) {
    fwrite(STDERR, "Göç 028 uygulanmamış; sürüm testleri atlanıyor.\n");
    return;
}

/** Test için haber üretir; id döndürür. */
function rev_haber($baslik, $status = 'draft', $authorId = 1) {
    return (int)db_insert('posts', [
        'title' => $baslik, 'slug' => 'test-' . bin2hex(random_bytes(4)),
        'spot' => 'spot', 'body' => '<p>ilk gövde</p>', 'image' => '',
        'category_id' => 0, 'author_id' => $authorId, 'status' => $status,
        'type' => 'haber', 'tags' => 'a, b', 'seo_title' => '', 'seo_desc' => '',
        'published_at' => $status === 'published' ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Test için kullanıcı üretir; id döndürür. */
function rev_kullanici($ad, $rol = 'editor') {
    return (int)db_insert('users', [
        'name' => $ad, 'email' => strtolower($ad) . '-' . bin2hex(random_bytes(3)) . '@ornek.test',
        'pass_hash' => 'x', 'role' => $rol, 'active' => 1, 'created_at' => now(),
    ]);
}

// ============================================================ 1 · tam kopya

test('sürüm tam kopyadır: gövde ve üstveri birebir geri okunur', function () {
    $pid = rev_haber('Kopya sınaması');
    $u = rev_kullanici('Ayse');

    $rid = revisions_capture($pid, $u, 'manual', 'ilk');
    dogru($rid > 0, 'sürüm yazılmalı');

    $r = revisions_get($rid);
    esit('Kopya sınaması', (string)$r['title']);
    esit('<p>ilk gövde</p>', (string)$r['body'], 'gövde tam kopya olmalı');
    esit('a, b', (string)$r['tags']);
    esit('manual', (string)$r['kind']);
    esit('ilk', (string)$r['note']);
    esit($pid, (int)$r['post_id']);
});

// ============================================================ 2 · aynı içerik yazılmaz

test('aynı içerik ikinci kez yazılmaz (content_hash)', function () {
    $pid = rev_haber('Tekrar sınaması');
    $u = rev_kullanici('Bora');

    $ilk = revisions_capture($pid, $u, 'auto');
    $ikinci = revisions_capture($pid, $u, 'auto');
    dogru($ilk > 0, 'ilk sürüm yazılmalı');
    esit(0, $ikinci, 'değişmemiş içerik ikinci kez yazılmamalı');
    esit(1, (int)qv('SELECT COUNT(*) FROM post_revisions WHERE post_id = :p', [':p' => $pid], 0));

    // İçerik değişince yeniden yazılır.
    q('UPDATE posts SET body = :b WHERE id = :i', [':b' => '<p>yeni</p>', ':i' => $pid]);
    dogru(revisions_capture($pid, $u, 'manual') > 0, 'değişen içerik yazılmalı');
});

// ============================================================ 3 · budama

test('budama: haber başına en çok REVISIONS_KEEP sürüm kalır', function () {
    $pid = rev_haber('Budama sınaması');
    $u = rev_kullanici('Cem');

    // KEEP + 8 farklı sürüm üret (her biri farklı gövdeyle).
    $hedef = REVISIONS_KEEP + 8;
    for ($i = 0; $i < $hedef; $i++) {
        $alanlar = revisions_extract(['title' => 'B' . $i, 'body' => '<p>' . $i . '</p>']);
        revisions_store($pid, $u, 'manual', $alanlar);
    }
    $kalan = (int)qv('SELECT COUNT(*) FROM post_revisions WHERE post_id = :p', [':p' => $pid], 0);
    esit(REVISIONS_KEEP, $kalan, 'budama otomatik olmalı');

    // Kalanlar EN YENİLERİ olmalı.
    $enEski = (string)qv('SELECT title FROM post_revisions WHERE post_id = :p ORDER BY id ASC LIMIT 1',
        [':p' => $pid], '');
    esit('B' . ($hedef - REVISIONS_KEEP), $enEski, 'eski sürümler budanmalı, yeniler kalmalı');
});

// ============================================================ 4 · otomatik kayıtlar yığılmaz

test('otomatik kayıtlar yığılmaz: kullanıcı başına yalnız en yeni auto durur', function () {
    $pid = rev_haber('Oto sınaması');
    $u = rev_kullanici('Deniz');

    for ($i = 0; $i < 6; $i++) {
        revisions_store($pid, $u, 'auto', revisions_extract(['title' => 'O' . $i, 'body' => '<p>' . $i . '</p>']));
    }
    $otoSayi = (int)qv('SELECT COUNT(*) FROM post_revisions WHERE post_id = :p AND kind = :k',
        [':p' => $pid, ':k' => 'auto'], 0);
    esit(1, $otoSayi, 'yalnız en yeni otomatik kayıt kalmalı');
    esit('O5', (string)qv('SELECT title FROM post_revisions WHERE post_id = :p AND kind = :k',
        [':p' => $pid, ':k' => 'auto'], ''));

    // Elle kayıtlar budanmaz — otomatik kayıt gerçek geçmişi yemez.
    revisions_store($pid, $u, 'manual', revisions_extract(['title' => 'E1', 'body' => '<p>e1</p>']));
    revisions_store($pid, $u, 'auto', revisions_extract(['title' => 'O6', 'body' => '<p>o6</p>']));
    esit(1, (int)qv('SELECT COUNT(*) FROM post_revisions WHERE post_id = :p AND kind = :k',
        [':p' => $pid, ':k' => 'manual'], 0), 'elle kayıt korunmalı (auto budaması onu yemez)');
    esit(1, (int)qv('SELECT COUNT(*) FROM post_revisions WHERE post_id = :p AND kind = :k',
        [':p' => $pid, ':k' => 'auto'], 0), 'yeni auto yine tek kalmalı');
});

// ============================================================ 5 · otomatik kayıt canlıya dokunmaz

test('otomatik kayıt YAYIMLANMIŞ haberin gövdesini DEĞİŞTİRMEZ', function () {
    $pid = rev_haber('Canlı haber', 'published');
    $u = rev_kullanici('Elif');

    $oncekiGovde = (string)qv('SELECT body FROM posts WHERE id = :i', [':i' => $pid], '');
    $oncekiDurum = (string)qv('SELECT status FROM posts WHERE id = :i', [':i' => $pid], '');
    $oncekiBaslik = (string)qv('SELECT title FROM posts WHERE id = :i', [':i' => $pid], '');

    // Editörün yarım cümlesi otomatik kayda düşüyor. (Anlık görüntü, ucun
    // yaptığı gibi, veritabanındaki satırdan türetilir; yalnız yazılan iki alan
    // farklıdır.)
    $canli = q1('SELECT * FROM posts WHERE id = :i', [':i' => $pid]);
    $alanlar = revisions_extract($canli);
    $alanlar['title'] = 'Canlı haber YARIM BAŞLIK';
    $alanlar['body']  = '<p>yarım cüm</p>';
    $rid = revisions_store($pid, $u, 'auto', $alanlar);
    dogru($rid > 0, 'otomatik kayıt sürüm olarak yazılmalı');

    esit($oncekiGovde, (string)qv('SELECT body FROM posts WHERE id = :i', [':i' => $pid], ''),
        'canlı gövde DEĞİŞMEMELİ');
    esit($oncekiBaslik, (string)qv('SELECT title FROM posts WHERE id = :i', [':i' => $pid], ''),
        'canlı başlık DEĞİŞMEMELİ');
    esit($oncekiDurum, (string)qv('SELECT status FROM posts WHERE id = :i', [':i' => $pid], ''),
        'yayın durumu DEĞİŞMEMELİ');

    // Ama kurtarılabilir olarak görünmeli. (Bu iddia ilk koşuda KALDI: eski
    // gerçekleştirim `created_at > updated_at` diye soruyordu ve iki damga aynı
    // saniyeye düştüğü için kurtarılabilir metni gizliyordu. Karşılaştırma
    // içerik özetine çevrildi.)
    $kurtar = revisions_recoverable($pid, $u);
    dogru($kurtar !== null, 'otomatik kayıt kurtarılabilir olmalı');
    esit($rid, (int)$kurtar['id']);

    // İçerik canlıya uygulandığında kurtarılacak bir şey kalmaz.
    q('UPDATE posts SET title = :t, body = :b WHERE id = :i',
      [':t' => 'Canlı haber YARIM BAŞLIK', ':b' => '<p>yarım cüm</p>', ':i' => $pid]);
    esit(null, revisions_recoverable($pid, $u), 'içerik eşitse kurtarma uyarısı olmamalı');
});

// ============================================================ 6 · kilit atomik

test('kilidi aynı anda iki kullanıcı alamaz', function () {
    $pid = rev_haber('Kilit sınaması');
    $a = rev_kullanici('KilitA');
    $b = rev_kullanici('KilitB');

    $r1 = revisions_lock_acquire($pid, $a);
    dogru($r1['ok'], 'ilk kullanıcı kilidi almalı');

    $r2 = revisions_lock_acquire($pid, $b);
    yanlis($r2['ok'], 'ikinci kullanıcı kilidi ALMAMALI');
    esit($a, (int)$r2['by'], 'ikinciye gerçek sahip bildirilmeli');

    // Sahip kendi kilidini tazeleyebilir.
    dogru(revisions_lock_acquire($pid, $a)['ok'], 'sahip kilidi tazeleyebilmeli');
});

// ============================================================ 7 · zaman aşımı

test('zaman aşımına uğrayan kilit devralınabilir', function () {
    $pid = rev_haber('Zaman aşımı sınaması');
    $a = rev_kullanici('EskiA');
    $b = rev_kullanici('YeniB');

    dogru(revisions_lock_acquire($pid, $a)['ok']);
    yanlis(revisions_lock_acquire($pid, $b)['ok'], 'taze kilit devralınmamalı');

    // Damgayı zaman aşımının ötesine it.
    $eski = date('Y-m-d H:i:s', time() - revisions_lock_ttl() - 60);
    q('UPDATE posts SET locked_at = :t WHERE id = :i', [':t' => $eski, ':i' => $pid]);

    $durum = revisions_lock_state($pid);
    dogru($durum['expired'], 'kilit süresi dolmuş sayılmalı');
    dogru(revisions_lock_acquire($pid, $b)['ok'], 'süresi dolmuş kilit alınabilmeli');
    esit($b, (int)revisions_lock_state($pid)['by']);
});

// ============================================================ 8 · devralma CAS

test('devralma karşılaştır-ve-değiştir: görülen sahip değişmişse başarısız', function () {
    $pid = rev_haber('Devralma sınaması');
    $a = rev_kullanici('DevA');
    $b = rev_kullanici('DevB');
    $c = rev_kullanici('DevC');

    dogru(revisions_lock_acquire($pid, $a)['ok']);

    // B ekranda A'yı görüyor ve devralıyor: başarılı.
    dogru(revisions_lock_acquire($pid, $b, $a)['ok'], 'doğru sahibi gören devralabilmeli');
    esit($b, (int)revisions_lock_state($pid)['by']);

    // C de ekranında A'yı görüyordu (bayat bilgi): devralamamalı.
    yanlis(revisions_lock_acquire($pid, $c, $a)['ok'], 'bayat sahip bilgisiyle devralınmamalı');
    esit($b, (int)revisions_lock_state($pid)['by'], 'kilit B\'de kalmalı');
});

// ============================================================ 9 · bırakma

test('kilidi yalnız sahibi bırakabilir', function () {
    $pid = rev_haber('Bırakma sınaması');
    $a = rev_kullanici('BirA');
    $b = rev_kullanici('BirB');

    dogru(revisions_lock_acquire($pid, $a)['ok']);
    yanlis(revisions_lock_release($pid, $b), 'başkası kilidi düşürememeli');
    esit($a, (int)revisions_lock_state($pid)['by']);

    dogru(revisions_lock_release($pid, $a), 'sahip bırakabilmeli');
    esit(0, (int)revisions_lock_state($pid)['by']);
});

// ============================================================ 10 · revizyon isteği

test('revizyon isteği bayrak sütunu olmadan son nottan okunur', function () {
    $pid = rev_haber('Onay sınaması', 'draft');
    $ed = rev_kullanici('EditorX');

    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $pid]);
    esit(null, revisions_revision_requested($post), 'not yokken istek de yok');

    revisions_note_add($pid, $ed, 'Kaynak eksik, ikinci kaynak ekleyin.', 'revision_request');
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $pid]);
    $istek = revisions_revision_requested($post);
    dogru($istek !== null, 'taslak + son not revision_request → istek var');
    icerir((string)$istek['body'], 'ikinci kaynak');

    // Muhabir yeniden onaya gönderince koşul kendiliğinden düşer.
    q('UPDATE posts SET status = :s WHERE id = :i', [':s' => 'pending', ':i' => $pid]);
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $pid]);
    esit(null, revisions_revision_requested($post), 'onaya gönderilince istek düşmeli');

    // Serbest not, revizyon isteğini gölgeler (son not artık istek değil).
    q('UPDATE posts SET status = :s WHERE id = :i', [':s' => 'draft', ':i' => $pid]);
    revisions_note_add($pid, $ed, 'Teşekkürler.', 'note');
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $pid]);
    esit(null, revisions_revision_requested($post));
});

// ============================================================ 11 · published_by

test('published_by bir kez yazılır, ikinci yayımlama ilk izi silmez', function () {
    $pid = rev_haber('Sorumluluk izi', 'pending');
    $ilk = rev_kullanici('YayimlayanBir');
    $ikinci = rev_kullanici('YayimlayanIki');

    esit(0, (int)qv('SELECT published_by FROM posts WHERE id = :i', [':i' => $pid], 0));
    dogru(revisions_mark_published($pid, $ilk), 'ilk damga yazılmalı');
    esit($ilk, (int)qv('SELECT published_by FROM posts WHERE id = :i', [':i' => $pid], 0));

    yanlis(revisions_mark_published($pid, $ikinci), 'ikinci damga yazılmamalı');
    esit($ilk, (int)qv('SELECT published_by FROM posts WHERE id = :i', [':i' => $pid], 0),
        'ilk yayımlayanın izi korunmalı');
});

// ============================================================ 12 · notlar sırası

test('notlar eskiden yeniye sıralanır (yazışma sırası)', function () {
    $pid = rev_haber('Not sırası');
    $u = rev_kullanici('NotU');
    revisions_note_add($pid, $u, 'birinci');
    revisions_note_add($pid, $u, 'ikinci');
    revisions_note_add($pid, $u, 'üçüncü');
    $n = revisions_notes($pid);
    esit(3, count($n));
    esit('birinci', (string)$n[0]['body']);
    esit('üçüncü', (string)$n[2]['body']);
    // Boş not yazılmaz.
    esit(0, revisions_note_add($pid, $u, '   '), 'boş not reddedilmeli');
});
