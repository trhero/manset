<?php
/**
 * inc/api/headlines_api.php — 1.3-09 mantığı: zamanlı manşet, manşete özel
 * kısa başlık, cron devri.
 *
 * Uçların HTTP kapıları (izin, CSRF) tests/e2e.sh içinde denenir; burada
 * yalnız iş mantığı sınanır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/api/headlines_api.php';

/** Test haberi ekler. */
function ht_haber($baslik, $extra = []) {
    static $n = 0;
    $n++;
    return (int)db_insert('posts', array_merge([
        'title'        => $baslik,
        'slug'         => 'birim-' . getmypid() . '-' . $n,
        'status'       => 'published',
        'published_at' => now(),
        'created_at'   => now(),
        'is_headline'  => 0,
    ], $extra));
}

function ht_temizle() { q('DELETE FROM posts'); }

/** 'Y-m-d H:i:s' — şimdiden $saat saat önce/sonra. */
function ht_zaman($saatFarki) { return date('Y-m-d H:i:s', time() + ($saatFarki * 3600)); }

// ------------------------------------------------------------ şema kapısı
test('headlines_v2_ready: göç 026 uygulanmış birim veritabanında açık', function () {
    dogru(headlines_v2_ready());
    esit(12, headlines_max(), 'vitrin.php yüklü değilse varsayılan 12');
});

// ------------------------------------------------------------ zaman çözümü
test('headlines_datetime: kabul edilen biçimler', function () {
    esit('2026-08-24 14:30:00', headlines_datetime('2026-08-24 14:30:00'));
    esit('2026-08-24 14:30:00', headlines_datetime('2026-08-24 14:30'), 'saniyesiz');
    esit('2026-08-24 14:30:00', headlines_datetime('2026-08-24T14:30'), 'datetime-local biçimi');
    esit('2026-08-24 00:00:00', headlines_datetime('2026-08-24'), 'yalnız tarih → gün başı');
    esit('', headlines_datetime(''), 'boş → boş');
    esit('', headlines_datetime('yarın'), 'anlaşılmayan metin → boş');
    esit('', headlines_datetime('2026-13-45 99:99'), 'geçersiz tarih → boş');
});

// ------------------------------------------------------------ yayın kapısı
test('headlines_row_publishable: taslak, çöp ve gelecek tarih elenir', function () {
    dogru(headlines_row_publishable(['status' => 'published', 'published_at' => ht_zaman(-1), 'deleted_at' => '']));
    dogru(headlines_row_publishable(['status' => 'published', 'published_at' => '', 'deleted_at' => '']));
    yanlis(headlines_row_publishable(['status' => 'draft', 'published_at' => '', 'deleted_at' => '']), 'taslak');
    yanlis(headlines_row_publishable(['status' => 'published', 'published_at' => ht_zaman(5), 'deleted_at' => '']), 'gelecek');
    yanlis(headlines_row_publishable(['status' => 'published', 'published_at' => '', 'deleted_at' => ht_zaman(-1)]), 'çöpte');
});

// ------------------------------------------------------------ kısa başlık
test('headlines_set_title: temizler, sınırlar, boşaltılabilir', function () {
    ht_temizle();
    $id = ht_haber('Belediye meclisi bütçe görüşmelerinde uzlaşmaya vardı');

    $r = headlines_set_title($id, "  Bütçede\nuzlaşma  ");
    dogru($r['ok']);
    icermez($r['headline_title'], "\n", 'satır sonu temizlenir');
    esit('Bütçede uzlaşma', $r['headline_title']);

    $uzun = headlines_set_title($id, str_repeat('a', 400));
    dogru(mb_strlen($uzun['headline_title']) <= headlines_title_max(), 'uzunluk sınırı uygulanır');

    $bos = headlines_set_title($id, '');
    esit('', $bos['headline_title'], 'boşaltmak özgün başlığa döner');

    yanlis(headlines_set_title(999999, 'x')['ok'], 'olmayan haber reddedilir');
});

test('headlines_extra: yalnız istenen id’leri döndürür', function () {
    ht_temizle();
    $a = ht_haber('Bir', ['headline_title' => 'Kısa A']);
    $b = ht_haber('İki');
    $m = headlines_extra([$a, $b, 999999]);
    esit('Kısa A', $m[$a]['headline_title']);
    esit('', $m[$b]['headline_title']);
    yanlis(isset($m[999999]), 'olmayan id eşlemede yer almaz');
});

// ------------------------------------------------------------ zamanlama
test('headlines_set_schedule: doğrulama', function () {
    ht_temizle();
    $id = ht_haber('Zamanlanacak haber');

    $ters = headlines_set_schedule($id, ht_zaman(5), ht_zaman(2));
    yanlis($ters['ok'], 'başlangıç bitişten sonra olamaz');
    icerir($ters['error'], 'sonra olamaz');

    $bozuk = headlines_set_schedule($id, 'gelecek hafta', '');
    yanlis($bozuk['ok'], 'anlaşılmayan zaman reddedilir');

    $ok = headlines_set_schedule($id, ht_zaman(2), ht_zaman(6));
    dogru($ok['ok']);
    esit(headlines_datetime(ht_zaman(2)), $ok['headline_starts_at']);

    $temiz = headlines_set_schedule($id, '', '');
    dogru($temiz['ok']);
    esit('', $temiz['headline_starts_at']);
    esit('', $temiz['headline_ends_at']);
});

test('headlines_set_schedule: manşetteki haberin girişi yazılmaz', function () {
    ht_temizle();
    $id = ht_haber('Zaten manşette', ['is_headline' => 1, 'headline_sort' => 1]);
    $r = headlines_set_schedule($id, ht_zaman(3), ht_zaman(9));
    dogru($r['ok']);
    esit('', $r['headline_starts_at'], 'manşetteki haberin “girişi” anlamsız');
    esit(headlines_datetime(ht_zaman(9)), $r['headline_ends_at'], 'çıkış yazılır');
});

// ------------------------------------------------------------ cron
test('headlines_cron_schedule: zamanı gelen manşete alınır', function () {
    ht_temizle();
    $id = ht_haber('Zamanı geldi', ['headline_starts_at' => ht_zaman(-1)]);

    $r = headlines_cron_schedule();
    esit(1, $r['items']);
    icerir($r['note'], '1 manşete alındı');

    $row = q1('SELECT is_headline, headline_sort, headline_starts_at FROM posts WHERE id = :i', [':i' => $id]);
    esit(1, (int)$row['is_headline']);
    dogru((int)$row['headline_sort'] > 0, 'sıra numarası verilmeli');
    esit('', (string)$row['headline_starts_at'], 'uygulanan zamanlama temizlenir');
});

test('headlines_cron_schedule: zamanı gelmemiş haber beklemede kalır', function () {
    ht_temizle();
    $id = ht_haber('Yarın', ['headline_starts_at' => ht_zaman(24)]);
    $r = headlines_cron_schedule();
    esit(0, $r['items']);
    esit(0, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $id], 0));
    esit(1, count(headlines_pending_items()), 'panelde bekleyen olarak görünür');
});

test('headlines_cron_schedule: yayında olmayan haber manşete ALINMAZ', function () {
    ht_temizle();
    $taslak = ht_haber('Taslak', ['status' => 'draft', 'headline_starts_at' => ht_zaman(-1)]);
    $cop = ht_haber('Çöpte', ['deleted_at' => ht_zaman(-2), 'headline_starts_at' => ht_zaman(-1)]);

    $r = headlines_cron_schedule();
    esit(0, $r['items'], 'hiçbiri açılmamalı');
    icerir($r['note'], 'beklemede');
    esit(0, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $taslak], 0));
    esit(0, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $cop], 0));

    // Zamanlama SİLİNMEZ: haber yayına girince bir sonraki tur alır.
    dogru((string)qv('SELECT headline_starts_at FROM posts WHERE id = :i', [':i' => $taslak], '') !== '');
});

test('headlines_cron_schedule: süresi dolan manşetten çıkar', function () {
    ht_temizle();
    $id = ht_haber('Süresi doldu', [
        'is_headline' => 1, 'headline_sort' => 1, 'headline_ends_at' => ht_zaman(-1),
    ]);
    $r = headlines_cron_schedule();
    esit(1, $r['items']);
    icerir($r['note'], '1 manşetten çıktı');

    $row = q1('SELECT is_headline, headline_sort, headline_ends_at FROM posts WHERE id = :i', [':i' => $id]);
    esit(0, (int)$row['is_headline']);
    esit(0, (int)$row['headline_sort']);
    esit('', (string)$row['headline_ends_at'], 'uygulanan zamanlama temizlenir');
});

test('headlines_cron_schedule: bitişi de geçmiş bir giriş hiç açılmaz', function () {
    ht_temizle();
    $id = ht_haber('Pencere kapandı', [
        'headline_starts_at' => ht_zaman(-5), 'headline_ends_at' => ht_zaman(-2),
    ]);
    $r = headlines_cron_schedule();
    esit(0, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $id], 0),
        'geçmiş bir pencere manşeti kirletmemeli');
    esit('', (string)qv('SELECT headline_starts_at FROM posts WHERE id = :i', [':i' => $id], ''),
        'ölü zamanlama temizlenir');
});

test('headlines_cron_schedule: manşet doluysa yeni haber beklemede kalır', function () {
    ht_temizle();
    for ($i = 0; $i < headlines_max(); $i++) {
        ht_haber('Dolu ' . $i, ['is_headline' => 1, 'headline_sort' => $i + 1]);
    }
    $id = ht_haber('Sıradaki', ['headline_starts_at' => ht_zaman(-1)]);

    $r = headlines_cron_schedule();
    esit(0, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $id], 0));
    icerir($r['note'], 'beklemede');
    dogru((string)qv('SELECT headline_starts_at FROM posts WHERE id = :i', [':i' => $id], '') !== '',
        'zamanlama korunur, yer açılınca uygulanır');
});

test('headlines_cron_schedule: aynı turda çıkan yerine yeni haber girer', function () {
    ht_temizle();
    for ($i = 0; $i < headlines_max() - 1; $i++) {
        ht_haber('Dolu ' . $i, ['is_headline' => 1, 'headline_sort' => $i + 1]);
    }
    $cikan = ht_haber('Süresi dolan', [
        'is_headline' => 1, 'headline_sort' => headlines_max(), 'headline_ends_at' => ht_zaman(-1),
    ]);
    $giren = ht_haber('Yerine geçen', ['headline_starts_at' => ht_zaman(-1)]);

    headlines_cron_schedule();
    esit(0, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $cikan], 0));
    esit(1, (int)qv('SELECT is_headline FROM posts WHERE id = :i', [':i' => $giren], 0),
        'çıkışlar önce işlendiği için yer açılır');
});

ht_temizle();
