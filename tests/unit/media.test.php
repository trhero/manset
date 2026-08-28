<?php
/**
 * inc/media.php — 1.3-08 mantığı: varyant kuyruğu, odak noktası,
 * alt metin süzgeci, kullanılmayan görsel bulucu, toplu işlemler.
 *
 * Yükleme akışının kendisi ($_FILES, is_uploaded_file) burada değil,
 * tests/e2e.sh içinde gerçek sunucuyla denenir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/media.php';

/** Test kaydı ekler; eklenen id'yi döndürür. */
function mt_medya($filename, $alt = '', $extra = []) {
    $row = [
        'filename'   => $filename,
        'alt'        => $alt,
        'mime'       => 'image/jpeg',
        'width'      => 2000,
        'height'     => 1500,
        'size'       => 123456,
        'variants'   => '',
        'created_at' => now(),
    ];
    if (media_has_queue()) {
        $row['variant_state'] = 'queued';
        $row['variant_tries'] = 0;
        $row['focus_x'] = 50;
        $row['focus_y'] = 50;
    }
    return (int)db_insert('media', array_merge($row, $extra));
}

/** Testler arası temizlik: media ve posts tablolarını boşaltır. */
function mt_temizle() {
    q('DELETE FROM media');
    q('DELETE FROM posts');
}

// ------------------------------------------------------------ şema kapısı
test('media_has_queue: göç 025 uygulanmış birim veritabanında açık', function () {
    dogru(media_has_queue(), 'run.php migrations_run() çağırıyor; sütunlar olmalı');
    icerir(media_select_columns(), 'variant_state');
    icerir(media_select_columns(), 'focus_x');
});

// ------------------------------------------------------------ odak noktası
test('media_focus_clamp: 0..100 aralığına sıkışır', function () {
    esit(50, media_focus_clamp(null), 'null → merkez');
    esit(50, media_focus_clamp(''), 'boş → merkez');
    esit(0, media_focus_clamp(-30), 'eksi değer 0 olur');
    esit(100, media_focus_clamp(999), 'aşırı değer 100 olur');
    esit(37, media_focus_clamp('37'), 'dize tamsayıya çevrilir');
    esit(0, media_focus_clamp('abc'), 'sayı olmayan 0 sayılır');
});

test('media_set_focus: kaydeder ve sıkıştırır', function () {
    mt_temizle();
    $id = mt_medya('2026/08/odak-a1b2c3d4.jpg');
    $r = media_set_focus($id, 300, -5);
    dogru($r['ok']);
    esit(100, $r['focus_x'], 'üst sınır');
    esit(0, $r['focus_y'], 'alt sınır');

    $row = media_get($id);
    esit(100, $row['focus_x']);
    esit(0, $row['focus_y']);
    esit('100% 0%', $row['focus_css']);
    icerir(media_focus_css($row), 'object-position: 100% 0%');

    $yok = media_set_focus(999999, 10, 10);
    yanlis($yok['ok'], 'olmayan kayıt reddedilir');
});

// ------------------------------------------------------------ alt metin süzgeci
test('media_count/media_list: alt metni boş olanlar süzülür', function () {
    mt_temizle();
    mt_medya('2026/08/alt-var-11111111.jpg', 'Meydanda toplanan kalabalık');
    mt_medya('2026/08/alt-yok-22222222.jpg', '');
    mt_medya('2026/08/alt-yok-33333333.jpg', '');

    esit(3, media_count(''), 'toplam');
    esit(2, media_count('', 'noalt'), 'alt metni boş olanlar');
    esit(2, media_missing_alt_count());

    $liste = media_list('', 60, 0, 'noalt');
    esit(2, count($liste));
    foreach ($liste as $m) { dogru($m['alt_missing'], 'süzgeç yalnız eksikleri getirmeli'); }

    $tumu = media_list('', 60, 0);
    esit(3, count($tumu));
});

test('media_count: arama ile süzgeç birlikte çalışır', function () {
    mt_temizle();
    mt_medya('2026/08/deprem-11111111.jpg', '');
    mt_medya('2026/08/deprem-22222222.jpg', 'Enkaz alanı');
    mt_medya('2026/08/secim-33333333.jpg', '');

    esit(2, media_count('deprem'), 'arama');
    esit(1, media_count('deprem', 'noalt'), 'arama + alt metni boş');
});

// ------------------------------------------------------------ kuyruk
test('media_queue_size / media_variants_pending: bekleyenleri sayar', function () {
    mt_temizle();
    mt_medya('2026/08/bekleyen-11111111.jpg', '', ['variant_state' => 'queued']);
    mt_medya('2026/08/eski-22222222.jpg', '', ['variant_state' => '']);   // 1.3 öncesi kayıt
    mt_medya('2026/08/bitmis-33333333.jpg', '', ['variant_state' => 'done']);
    mt_medya('2026/08/atlanan-44444444.gif', '', ['variant_state' => 'skipped']);

    esit(2, media_queue_size(), 'queued + boş durum bekliyor sayılır');
    esit(2, count(media_variants_pending(10)));

    $stat = media_queue_stats();
    esit(2, $stat['queued'], 'boş durum queued kovasına düşer');
    esit(1, $stat['done']);
    esit(1, $stat['skipped']);
});

test('media_variant_worker: diskte olmayan dosya failed olur, kuyruğu tıkamaz', function () {
    mt_temizle();
    $id = mt_medya('2026/08/hic-yok-99999999.jpg');
    $r = media_variant_worker($id);
    yanlis($r['ok'], 'dosya yoksa başarısız');
    esit('failed', $r['state']);
    esit(0, media_queue_size(), 'failed kayıt bir daha kuyruğa girmez');
});

test('media_variant_worker: hareketli GIF atlanır (animasyon korunur)', function () {
    mt_temizle();
    // Dosya GERÇEKTEN diskte olmalı: diskte olmayan kayıt (doğru biçimde)
    // 'failed' sayılır, GIF kararına hiç sıra gelmez.
    $rel = 'birim-gif-' . getmypid() . '.gif';
    $abs = media_safe_path($rel);
    dogru($abs !== '', 'test dosyası yolu UPLOAD_DIR altında olmalı');
    file_put_contents($abs, "GIF89a\x01\x00\x01\x00\x00\xff\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x00;");

    $id = mt_medya($rel, '', ['mime' => 'image/gif']);
    $r = media_variant_worker($id);
    esit('skipped', $r['state']);
    esit(0, media_queue_size());

    @unlink($abs);
});

test('media_make_variants: $only verildiğinde YALNIZ o boyut üretilir', function () {
    if (!media_gd_available()) {
        // GD yoksa varyant üretimi zaten atlanır; iddia edilecek bir şey yok.
        dogru(true, 'GD yok — üretim testi atlandı');
        return;
    }
    $rel = 'birim-test-' . getmypid() . '.png';
    $abs = media_safe_path($rel);
    dogru($abs !== '', 'test dosyası yolu UPLOAD_DIR altında olmalı');

    $img = imagecreatetruecolor(1600, 900);
    imagefill($img, 0, 0, imagecolorallocate($img, 20, 90, 160));
    imagepng($img, $abs);
    imagedestroy($img);

    $temizle = function () use ($rel) {
        foreach (['', '-thumb', '-medium', '-large'] as $ek) {
            foreach (['.png', '.webp'] as $uzanti) {
                $nokta = strrpos($rel, '.');
                $ad = substr($rel, 0, $nokta) . $ek . $uzanti;
                $p = media_safe_path($ad);
                if ($p !== '' && is_file($p)) { @unlink($p); }
            }
        }
    };

    // Yükleme yolunun yaptığı iş: yalnız thumb, WebP yok.
    $v = media_make_variants($abs, $rel, ['thumb'], false);
    dogru(isset($v['thumb']), 'thumb üretilmeli');
    yanlis(isset($v['medium']), 'medium üretilmemeli');
    yanlis(isset($v['large']), 'large üretilmemeli');
    yanlis(isset($v['webp']), 'yüklemede WebP kopyası üretilmemeli');

    // Kuyruğun yaptığı iş: hepsi.
    $hepsi = media_make_variants($abs, $rel);
    dogru(isset($hepsi['thumb']) && isset($hepsi['medium']) && isset($hepsi['large']),
        'kuyruk üç boyutu da üretmeli');

    $temizle();
});

// ------------------------------------------------------------ kullanım taraması
test('media_scan_text_refs: gövde HTML’indeki adresleri yakalar', function () {
    $acc = [];
    media_scan_text_refs('<p>x</p><img src="/uploads/2026/08/foto-aaaa1111.jpg" alt="a">', $acc);
    dogru(isset($acc['2026/08/foto-aaaa1111.jpg']), 'uploads/ ön eki atılmalı');
    dogru(isset($acc['foto-aaaa1111.jpg']), 'yalnız dosya adı da bilinmeli');

    // JSON kaçışlı eğik çizgi (ayarlar tablosunda blok dizilimi böyle saklanır)
    $acc2 = [];
    media_scan_text_refs('{"logo":"uploads\\/2026\\/08\\/logo-bbbb2222.png"}', $acc2);
    dogru(isset($acc2['2026/08/logo-bbbb2222.png']), 'JSON kaçışı çözülmeli');

    // HTML varlıkları
    $acc3 = [];
    media_scan_text_refs('&lt;img src=&quot;2026/08/haber-cccc3333.webp&quot;&gt;', $acc3);
    dogru(isset($acc3['2026/08/haber-cccc3333.webp']), 'varlık kaçışı çözülmeli');

    // srcset içinde birden çok adres
    $acc4 = [];
    media_scan_text_refs('srcset="2026/08/a-1111.jpg 400w, 2026/08/a-1111-large.jpg 1280w"', $acc4);
    dogru(isset($acc4['2026/08/a-1111.jpg']));
    dogru(isset($acc4['2026/08/a-1111-large.jpg']));
});

test('media_unused: kullanılan görsel listelenmez', function () {
    mt_temizle();
    mt_medya('2026/08/kapak-aaaa1111.jpg', 'Kapak');
    mt_medya('2026/08/govde-bbbb2222.jpg', 'Gövde içi');
    mt_medya('2026/08/oksuz-cccc3333.jpg', 'Kimse kullanmıyor');

    db_insert('posts', [
        'title' => 'Deneme', 'slug' => 'deneme-' . getmypid(),
        'image' => '2026/08/kapak-aaaa1111.jpg',
        'body'  => '<p>Metin</p><img src="/uploads/2026/08/govde-bbbb2222.jpg" alt="">',
        'status' => 'published', 'published_at' => now(), 'created_at' => now(),
    ]);

    $r = media_unused(50);
    esit(1, $r['total'], 'yalnız bir dosya kullanılmıyor olmalı');
    esit(3, $r['scanned']);
    esit('2026/08/oksuz-cccc3333.jpg', $r['items'][0]['filename']);
    dogru($r['bytes'] > 0, 'kazanılacak yer hesaplanmalı');
});

test('media_unused: ayarlardaki logo kullanılıyor sayılır', function () {
    mt_temizle();
    mt_medya('2026/08/logo-dddd4444.png');
    setting_set('site_logo', '2026/08/logo-dddd4444.png');
    settings_all(true);

    $r = media_unused(50);
    esit(0, $r['total'], 'ayarlarda geçen dosya silinmeye aday olmamalı');

    setting_set('site_logo', '');
    settings_all(true);
});

// ------------------------------------------------------------ toplu işlemler
test('media_clean_ids: tekilleştirir, sıfır/negatif atar, 200 ile sınırlar', function () {
    esit([3, 1, 2], media_clean_ids([3, 1, 3, 2, 0, -4, 'x']));
    esit([5, 6], media_clean_ids('5,6,5'), 'virgüllü dize kabul edilir');
    esit([], media_clean_ids(null));
    esit(200, count(media_clean_ids(range(1, 500))), 'üst sınır 200');
});

test('media_update_alt_many: seçililere aynı alt metni yazar', function () {
    mt_temizle();
    $a = mt_medya('2026/08/toplu-a-11111111.jpg', '');
    $b = mt_medya('2026/08/toplu-b-22222222.jpg', '');
    mt_medya('2026/08/toplu-c-33333333.jpg', '');

    $r = media_update_alt_many([$a, $b], "  Seçim gecesi\nkalabalık  ");
    esit(2, $r['updated']);
    icermez($r['alt'], "\n", 'satır sonu temizlenmeli');
    esit(1, media_missing_alt_count(), 'geriye bir eksik kalmalı');
});

test('media_delete_many: kaydı siler, olmayanı hata sayar', function () {
    mt_temizle();
    $a = mt_medya('2026/08/sil-a-11111111.jpg');
    $r = media_delete_many([$a, 987654]);
    esit(1, $r['deleted']);
    esit(1, $r['failed']);
    esit(0, media_count(''), 'tablo boşalmalı');
});

mt_temizle();
