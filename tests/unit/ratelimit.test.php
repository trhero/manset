<?php
/**
 * inc/bootstrap.php — rate_limit(): "ÖNCE YAZ SONRA SAY" kuralı (denetim tur 2, B05).
 * Kova adları test başına benzersizdir; testler birbirini etkilemez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }

/** Testler arası çakışmasız kova adı. */
function rl_kova($ek = '') {
    static $n = 0;
    $n++;
    return 'birim:' . $n . ':' . $ek;
}

test('rate_limit: max=2 ile üç deneme 1,1,0', function () {
    $k = rl_kova('login');
    esit(true,  rate_limit($k, 2, 300), '1. deneme geçmeli');
    esit(true,  rate_limit($k, 2, 300), '2. deneme geçmeli');
    esit(false, rate_limit($k, 2, 300), '3. deneme reddedilmeli');
    esit(false, rate_limit($k, 2, 300), '4. deneme de reddedilmeli');
});

test('rate_limit: reddedilen deneme de slot tüketir', function () {
    $k = rl_kova('brute');
    rate_limit($k, 1, 300);                       // 1 → geçer
    yanlis(rate_limit($k, 1, 300), '2. deneme reddedilir');
    // Reddedilen deneme yazıldığı için sayaç 3'e çıkar; sınır gevşemez
    yanlis(rate_limit($k, 1, 300));
    // Üç rate_limit() çağrısı → üç satır: reddedilen deneme de yazılır.
    $n = (int)qv('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b', [':b' => $k], 0);
    esit(3, $n, 'her deneme (reddedilen dâhil) bir satır yazmalı');
});

test('rate_limit: max=0 hiçbir denemeye izin vermez', function () {
    $k = rl_kova('kapali');
    yanlis(rate_limit($k, 0, 300), 'max=0 ilk denemeyi bile reddetmeli');
});

test('rate_limit_clear: kovayı sıfırlar', function () {
    $k = rl_kova('clear');
    rate_limit($k, 2, 300);
    rate_limit($k, 2, 300);
    yanlis(rate_limit($k, 2, 300), 'sınır dolmalı');

    rate_limit_clear($k);
    esit(0, (int)qv('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b', [':b' => $k], 0),
         'temizlik sonrası satır kalmamalı');
    dogru(rate_limit($k, 2, 300), 'temizlik sonrası yeniden izin verilmeli');

    // Olmayan kovayı temizlemek hata vermemeli
    rate_limit_clear('hic-olmayan-kova');
    dogru(true, 'olmayan kova temizliği sessiz geçmeli');
});

test('rate_limit: ayrı kovalar birbirini etkilemez', function () {
    $a = rl_kova('ip-a');
    $b = rl_kova('ip-b');
    dogru(rate_limit($a, 1, 300));
    yanlis(rate_limit($a, 1, 300), 'A kovası doldu');
    dogru(rate_limit($b, 1, 300), 'B kovası A`dan etkilenmemeli');
    yanlis(rate_limit($b, 1, 300));

    rate_limit_clear($a);
    dogru(rate_limit($a, 1, 300), 'A temizlendi');
    yanlis(rate_limit($b, 1, 300), 'B hâlâ dolu olmalı');
});

test('rate_limit: pencere dışındaki denemeler sayılmaz', function () {
    $k = rl_kova('pencere');
    // Pencere dışına düşen eski satırlar elle yazılır
    for ($i = 0; $i < 5; $i++) {
        q('INSERT INTO rate_limits (bucket, created_at) VALUES (:b, :n)',
          [':b' => $k, ':n' => date('Y-m-d H:i:s', time() - 600)]);
    }
    dogru(rate_limit($k, 2, 300), '10 dakika öncesi 5 dakikalık pencerede sayılmamalı');
    dogru(rate_limit($k, 2, 300));
    yanlis(rate_limit($k, 2, 300), 'pencere içi sayaç yine de dolmalı');
});

test('rate_limit: 24 saatten eski satırlar süpürülür', function () {
    $k = rl_kova('supurge');
    q('INSERT INTO rate_limits (bucket, created_at) VALUES (:b, :n)',
      [':b' => $k, ':n' => date('Y-m-d H:i:s', time() - 90000)]);
    esit(1, (int)qv('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b', [':b' => $k], 0));
    rate_limit(rl_kova('tetik'), 5, 300);        // herhangi bir çağrı süpürmeyi tetikler
    esit(0, (int)qv('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b', [':b' => $k], 0),
         '24 saati aşan kayıt silinmeli');
});

test('q(): hatalı SQL istisna atar (ERRMODE_EXCEPTION sözleşmesi)', function () {
    atar(function () { q('SELECT * FROM kesinlikle_olmayan_tablo'); },
         'bilinmeyen tablo istisna vermeli');
    atar(function () { q('BU GEÇERLİ SQL DEĞİL'); }, 'bozuk sözdizimi istisna vermeli');
    // Sessizce false dönmediğini de doğrula: geçerli sorgu istisna atmamalı
    esit(0, (int)qv('SELECT COUNT(*) FROM rate_limits WHERE bucket = :b', [':b' => 'yok'], 0));
});
