<?php
/**
 * inc/comments.php — yorum sistemi v2 (1.3-07, Ajan-B).
 *
 * Sınanan iddialar (hepsi GÜVENLİK ya da MODERASYON iddiasıdır):
 *
 *   1. YANIT ZİNCİRİ BAŞKA HABERE SIÇRAMAZ. B haberinin yorumu, A haberinin
 *      altına ebeveyn olarak verilemez.
 *   2. YAYINDA OLMAYAN yoruma yanıt yazılamaz (bekleyen · spam · silinmiş).
 *      Aksi hâlde moderatörün gizlediği yorum, alıntılı yanıtla geri gelirdi.
 *   3. ZİNCİR TEK SEVİYE. Yanıtın yanıtı REDDEDİLMEZ, KÖKE bağlanır — derinlik
 *      hiçbir yoldan 1'i geçmez.
 *   4. KENDİNE YANIT DÖNGÜSÜ okuma tarafında kırılır (elle kurcalanmış satır).
 *   5. KARA LİSTE üç türde de eşleşir ve önek/alan adı kuralları sınırlıdır
 *      (`1.2.3` deseni `11.2.3` adresini yakalamaz).
 *   6. ROZET yorum satırından üretilir: fonksiyona verilen dizide `author_role`
 *      dışında hiçbir şey yoktur, yani veritabanı ve oturum GEREKMEZ.
 *   7. SAYFALAMA kök yorumları sayar; yanıtlar kökleriyle birlikte gelir ve
 *      sayfa sınırını taşırmaz.
 *   8. Bildiren kimliği HAM IP DEĞİLDİR ve geri döndürülemez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/roles.php';
require_once INC_DIR . '/comments.php';

// ------------------------------------------------------------ ortam
setting_set('comments_enabled', '1');
setting_set('comments_replies', '1');
setting_set('comments_report', '1');
setting_set('comments_per_page', '5');
settings_all(true);

/** Test haberi üretir ve kimliğini döndürür. */
function y_haber($baslik = 'Yorum testi') {
    static $n = 0;
    $n++;
    return (int)db_insert('posts', [
        'title' => $baslik . ' ' . $n, 'slug' => 'yorum-testi-' . $n . '-' . getmypid(),
        'spot' => '', 'body' => '', 'image' => '', 'category_id' => 0, 'author_id' => 0,
        'status' => 'published', 'type' => 'haber', 'published_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Yorum satırı üretir. */
function y_yorum($postId, $opt = []) {
    $satir = [
        'post_id' => (int)$postId, 'user_id' => 0,
        'name' => isset($opt['name']) ? $opt['name'] : 'Okur',
        'email' => isset($opt['email']) ? $opt['email'] : 'okur@ornek.test',
        'body' => isset($opt['body']) ? $opt['body'] : 'Yorum gövdesi',
        'ip' => isset($opt['ip']) ? $opt['ip'] : '127.0.0.1',
        'status' => isset($opt['status']) ? $opt['status'] : 'approved',
        'created_at' => isset($opt['created_at']) ? $opt['created_at'] : now(),
        'parent_id' => isset($opt['parent_id']) ? (int)$opt['parent_id'] : 0,
        'author_role' => isset($opt['author_role']) ? $opt['author_role'] : '',
    ];
    return (int)db_insert('comments', $satir);
}

/** Kara listeyi tamamen boşaltır (testler birbirini etkilemesin). */
function y_kara_temizle() {
    q('DELETE FROM comment_blacklist');
    comments_blacklist_all(true);
}

// ============================================================ göç

test('göç 024 uygulandı: parent_id / author_role / report_count var', function () {
    dogru(comments_supports_v2(), 'comments_supports_v2()');
    dogru(schema_has_column('comments', 'author_role'));
    dogru(schema_has_column('comments', 'report_count'));
    dogru(schema_has_table('comment_blacklist'));
    dogru(schema_has_table('comment_reports'));
});

// ============================================================ yanıt zinciri

test('yanıt zinciri: başka haberin yorumu ebeveyn OLAMAZ', function () {
    $a = y_haber('A');
    $b = y_haber('B');
    $bYorum = y_yorum($b);
    // A haberinin altına B haberinin yorumu ebeveyn verilirse reddedilir.
    esit(-1, comments_parent_resolve($a, $bYorum), 'çapraz haber yanıtı reddedilmeli');
    // Kendi haberinde aynı kimlik geçerlidir — kapı kimliğe değil, EŞLEŞMEYE bakıyor.
    esit($bYorum, comments_parent_resolve($b, $bYorum));
});

test('yanıt zinciri: yayında olmayan yoruma yanıt yazılamaz', function () {
    $p = y_haber();
    $bekleyen = y_yorum($p, ['status' => 'pending']);
    $spam     = y_yorum($p, ['status' => 'spam']);
    $onayli   = y_yorum($p, ['status' => 'approved']);
    esit(-1, comments_parent_resolve($p, $bekleyen), 'bekleyen yoruma yanıt');
    esit(-1, comments_parent_resolve($p, $spam), 'spam yoruma yanıt');
    esit($onayli, comments_parent_resolve($p, $onayli));
    // Silinmiş (hiç var olmayan) kimlik
    esit(-1, comments_parent_resolve($p, 99999999), 'olmayan yoruma yanıt');
    // 0 ve negatif: üst düzey yorum
    esit(0, comments_parent_resolve($p, 0));
    esit(0, comments_parent_resolve($p, -5));
});

test('yanıt zinciri: yanıtın yanıtı KÖKE bağlanır (derinlik daima 1)', function () {
    $p = y_haber();
    $kok    = y_yorum($p);
    $yanit  = y_yorum($p, ['parent_id' => $kok]);
    // Yanıta yanıt verilmek istendi → kök döner, yanıt DEĞİL.
    esit($kok, comments_parent_resolve($p, $yanit), 'ikinci seviye köke inmeli');

    // Elle kurcalanmış üçüncü seviye satır bile ağaçta ikinci seviyeye çıkamaz.
    $ucuncu = y_yorum($p, ['parent_id' => $yanit]);
    $veri = comments_thread($p, 1);
    esit(1, count($veri['items']), 'yalnız bir kök');
    // $yanit ve $ucuncu'nün ikisi de doğrudan KÖKÜN altında görünmez:
    // $ucuncu'nün parent_id'si $yanit, o da kök listesinde değil → gizlenir.
    $altKimlikler = array_map(function ($r) { return (int)$r['id']; }, $veri['items'][0]['replies']);
    icerir($altKimlikler, $yanit);
    icermez($altKimlikler, $ucuncu, 'üçüncü seviye satır ağaca girmemeli');
});

test('yanıt zinciri: kendine yanıt döngüsü okuma tarafında kırılır', function () {
    $p = y_haber();
    $kok = y_yorum($p);
    // Elle kurcalanmış satır: kendi kendinin yanıtı.
    db_update('comments', ['parent_id' => $kok], 'id = :id', [':id' => $kok]);
    $veri = comments_thread($p, 1);
    // Kök artık parent_id<>0 olduğu için kök listesinde yok; hiçbir yerde
    // kendini çocuk olarak da göstermiyor — sonsuz döngü kurulamaz.
    esit(0, count($veri['items']), 'kurcalanmış satır kök sayılmaz');
    db_update('comments', ['parent_id' => 0], 'id = :id', [':id' => $kok]);
    $veri2 = comments_thread($p, 1);
    esit(1, count($veri2['items']));
    esit(0, count($veri2['items'][0]['replies']), 'kendi kendinin yanıtı listelenmez');
});

test('yanıt zinciri: yanıtlar kapalıyken hiçbir ebeveyn kabul edilmez', function () {
    $p = y_haber();
    $kok = y_yorum($p);
    setting_set('comments_replies', '0'); settings_all(true);
    esit(-1, comments_parent_resolve($p, $kok));
    esit(0, comments_parent_resolve($p, 0), 'üst düzey yorum yine yazılabilir');
    setting_set('comments_replies', '1'); settings_all(true);
});

// ============================================================ sayfalama

test('sayfalama: kök yorumlar sayfalanır, yanıtlar köküyle gelir', function () {
    $p = y_haber();
    $kokler = [];
    for ($i = 0; $i < 7; $i++) {
        $kokler[] = y_yorum($p, ['created_at' => '2026-01-0' . ($i + 1) . '10:00:00']);
    }
    // 3. köke iki yanıt
    y_yorum($p, ['parent_id' => $kokler[2]]);
    y_yorum($p, ['parent_id' => $kokler[2]]);

    $s1 = comments_thread($p, 1, 5);
    esit(7, $s1['total'], 'kök sayısı');
    esit(9, $s1['count'], 'toplam onaylı yorum (yanıtlar dâhil)');
    esit(2, $s1['pages']);
    esit(5, count($s1['items']), 'ilk sayfa 5 kök');
    esit(2, count($s1['items'][2]['replies']), 'yanıtlar köküyle geldi');

    $s2 = comments_thread($p, 2, 5);
    esit(2, count($s2['items']), 'ikinci sayfa 2 kök');

    // Sınır dışı sayfa son sayfaya sabitlenir (uydurma boş sayfa üretilmez).
    $s9 = comments_thread($p, 9, 5);
    esit(2, $s9['page']);
    esit(2, count($s9['items']));
});

test('sayfalama: bekleyen ve spam yorumlar ön yüzde hiç görünmez', function () {
    $p = y_haber();
    $kok = y_yorum($p);
    y_yorum($p, ['status' => 'pending']);
    y_yorum($p, ['status' => 'spam']);
    y_yorum($p, ['parent_id' => $kok, 'status' => 'spam']);
    $v = comments_thread($p, 1);
    esit(1, $v['total']);
    esit(1, $v['count']);
    esit(0, count($v['items'][0]['replies']), 'spam yanıt gizli');
});

// ============================================================ kara liste

test('kara liste: e-posta, alan adı, IP, IP öneki ve sözcük eşleşmesi', function () {
    y_kara_temizle();
    dogru(comments_blacklist_add('email', 'Spam@Ornek.Test')['ok']);
    dogru(comments_blacklist_add('email', '@kotu.test')['ok']);
    dogru(comments_blacklist_add('ip', '203.0.113.7')['ok']);
    dogru(comments_blacklist_add('ip', '198.51.100.')['ok']);
    dogru(comments_blacklist_add('word', 'bahis sitesi')['ok']);

    // Büyük/küçük harf normalleştirilir.
    icerir(comments_blacklist_match('SPAM@ornek.test', '10.0.0.1', 'merhaba'), 'e-posta');
    icerir(comments_blacklist_match('biri@kotu.test', '10.0.0.1', 'merhaba'), 'e-posta alanı');
    icerir(comments_blacklist_match('temiz@ornek.test', '203.0.113.7', 'merhaba'), 'IP');
    icerir(comments_blacklist_match('temiz@ornek.test', '198.51.100.42', 'merhaba'), 'IP öneki');
    icerir(comments_blacklist_match('temiz@ornek.test', '10.0.0.1', 'en iyi BAHİS SİTESİ burada'), 'sözcük');
    // Ad alanı da taranır: "Bahis Sitesi" adıyla yazan da yakalanır.
    icerir(comments_blacklist_match('temiz@ornek.test', '10.0.0.1', 'selam', 'Bahis Sitesi'), 'sözcük');
    esit('', comments_blacklist_match('temiz@ornek.test', '10.0.0.1', 'sıradan bir yorum'), 'temiz gönderim');
    y_kara_temizle();
});

test('kara liste: IP öneki nokta olmadan komşu adresi yakalamaz', function () {
    y_kara_temizle();
    comments_blacklist_add('ip', '203.0.11');            // sondaki nokta YOK
    esit('', comments_blacklist_match('a@b.test', '203.0.113.7', 'x'),
        'noktasız desen önek sayılmamalı');
    comments_blacklist_add('ip', '203.0.11.');           // sondaki nokta VAR
    icerir(comments_blacklist_match('a@b.test', '203.0.11.9', 'x'), 'IP öneki');
    y_kara_temizle();
});

test('kara liste: aynı desen iki kez eklenemez, tek harflik sözcük reddedilir', function () {
    y_kara_temizle();
    dogru(comments_blacklist_add('word', 'yasakli')['ok']);
    yanlis(comments_blacklist_add('word', 'YASAKLI')['ok'], 'normalleştirilmiş kopya');
    yanlis(comments_blacklist_add('word', 'ab')['ok'], 'üç karakterden kısa sözcük');
    yanlis(comments_blacklist_add('sacma', 'x')['ok'], 'bilinmeyen tür');
    yanlis(comments_blacklist_add('word', '   ')['ok'], 'boş desen');
    y_kara_temizle();
});

test('kara liste süpürgesi: sonradan eklenen kural yayındaki yorumu spam yapar', function () {
    y_kara_temizle();
    $p = y_haber();
    $temiz = y_yorum($p, ['body' => 'Güzel haber']);
    $kotu  = y_yorum($p, ['body' => 'ucuz kredi burada']);
    comments_blacklist_add('word', 'ucuz kredi');
    $rapor = comments_cron_blacklist_sweep();
    icerir($rapor, 'yorum kara listesi');
    esit('spam', qv('SELECT status FROM comments WHERE id = :i', [':i' => $kotu]));
    esit('approved', qv('SELECT status FROM comments WHERE id = :i', [':i' => $temiz]));
    y_kara_temizle();
});

// ============================================================ rozet

test('rozet: yalnız yorum satırından üretilir (veritabanı/oturum gerekmez)', function () {
    // Fonksiyona verilen dizide `author_role` dışında hiçbir alan yok.
    esit(null, comments_badge(['author_role' => '']), 'konuk yorumu rozetsiz');
    esit(null, comments_badge([]), 'sütun yoksa rozetsiz');

    $uye = comments_badge(['author_role' => 'member']);
    esit('Üye', $uye['etiket']);
    esit('uye', $uye['tur']);

    $editor = comments_badge(['author_role' => 'editor']);
    esit('personel', $editor['tur']);
    dogru($editor['etiket'] !== '', 'personel etiketi boş olmamalı');

    // Uydurma rol rozet üretmez — "Editör Ayşe" taklidi buradan da geçemez.
    esit(null, comments_badge(['author_role' => 'baseditor']));
    esit(null, comments_badge(['author_role' => '<b>Editör</b>']));
});

test('rozet: panel erişimi alınmış personel rozet taşımaz', function () {
    esit('editor', comments_author_role(['role' => 'editor', 'is_staff' => 1]));
    esit('member', comments_author_role(['role' => 'editor', 'is_staff' => 0]),
        'is_staff kapalıysa personel rozeti verilmez');
    esit('member', comments_author_role(['role' => 'member', 'is_staff' => 0]));
    esit('', comments_author_role(null), 'konuk');
    esit('', comments_author_role(['role' => 'uydurma_rol', 'is_staff' => 1]));
});

// ============================================================ bildir

test('bildir: bildiren kimliği ham IP değildir ve geri döndürülemez', function () {
    $h = comments_reporter_hash('203.0.113.9');
    esit(32, strlen($h));
    icermez($h, '203.0.113.9', 'ham IP özet içinde geçmemeli');
    esit($h, comments_reporter_hash('203.0.113.9'), 'aynı IP aynı özeti verir');
    dogru($h !== comments_reporter_hash('203.0.113.8'), 'farklı IP farklı özet');
});

test('bildir: aynı kişi aynı yorumu iki kez sayamaz', function () {
    $p = y_haber();
    $c = y_yorum($p);
    $h = comments_reporter_hash('198.51.100.5');
    db_insert('comment_reports', ['comment_id' => $c, 'reporter_hash' => $h,
        'reason' => 'spam', 'created_at' => now()]);
    atar(function () use ($c, $h) {
        db_insert('comment_reports', ['comment_id' => $c, 'reporter_hash' => $h,
            'reason' => 'hakaret', 'created_at' => now()]);
    }, 'benzersiz indeks ikinci bildirimi engellemeli');
});

test('bildir eşiği varsayılan olarak KAPALI', function () {
    setting_set('comments_report_threshold', ''); settings_all(true);
    esit(0, comments_report_threshold(), 'varsayılan 0 olmalı (brigading kalkanı)');
});

// ============================================================ spam + askıya al

test('spam + askıya al: yorum spam olur, yazarı kara listeye girer, yanıtlar izler', function () {
    y_kara_temizle();
    $p = y_haber();
    $kok = y_yorum($p, ['email' => 'taciz@ornek.test', 'ip' => '192.0.2.44']);
    $y1  = y_yorum($p, ['parent_id' => $kok]);

    $r = comments_spam_and_suspend($kok, 1, true);
    dogru($r['ok']);
    esit('spam', qv('SELECT status FROM comments WHERE id = :i', [':i' => $kok]));
    esit('spam', qv('SELECT status FROM comments WHERE id = :i', [':i' => $y1]), 'yanıt da spam');
    esit(1, (int)$r['yanit']);

    // Aynı kişi tekrar yazarsa kara listeye takılır.
    icerir(comments_blacklist_match('taciz@ornek.test', '10.0.0.1', 'yine ben'), 'e-posta');
    icerir(comments_blacklist_match('baska@ornek.test', '192.0.2.44', 'yine ben'), 'IP');
    y_kara_temizle();
});

test('spam + askıya al: IP kuralı istenmezse eklenmez (paylaşılan çıkış IP kalkanı)', function () {
    y_kara_temizle();
    $p = y_haber();
    $c = y_yorum($p, ['email' => 'tek@ornek.test', 'ip' => '192.0.2.55']);
    comments_spam_and_suspend($c, 1, false);
    icerir(comments_blacklist_match('tek@ornek.test', '10.0.0.1', 'x'), 'e-posta');
    esit('', comments_blacklist_match('baska@ornek.test', '192.0.2.55', 'x'),
        'IP kuralı eklenmemeli');
    y_kara_temizle();
});
