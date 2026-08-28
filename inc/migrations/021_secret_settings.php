<?php
/**
 * 021 — Var olan kurulumlardaki sırları şifrele (denetim turu 4, YÜKSEK-1).
 *
 * NEDEN GÖÇ GEREKİYOR: `setting_set()` bundan sonra sırları şifreli yazacak,
 * ama VAR OLAN satırlar düz metin olarak kalır. Yayıncı YZ anahtarını bir daha
 * kaydetmezse o anahtar sonsuza dek düz metin durur — ve 1.2'nin zamanlı uzak
 * yedeği onu her gün üçüncü taraf bir sunucuya göndermeye devam eder.
 *
 * TEK SEFERLİK VE GERİ ALINABİLİR OLMAYAN BİR İŞ DEĞİL: değerler `app_key` ile
 * şifrelenir, `setting()` okurken saydam biçimde çözer. Sürüm geri alınırsa
 * (1.2 → 1.1) eski kod şifreli dizeyi olduğu gibi okur ve anahtar ÇALIŞMAZ;
 * bu durumda yayıncının anahtarları panelden yeniden girmesi gerekir. Bunu
 * CHANGELOG'a yazmak, sessizce bozulmasından iyidir.
 *
 * ŞİFRELEME YOKSA (openssl eksik ya da `app_key` boş) göç HİÇBİR ŞEY YAPMAZ ve
 * hata vermez: değerler düz metin kalır, sistem çalışmaya devam eder. Sessiz
 * bir veri kaybı, korumasız bir anahtardan çok daha kötüdür.
 */
return function (PDO $pdo, $driver) {
    if (!function_exists('setting_is_secret') || !setting_secret_ready()) {
        // Sonda: burada durmak bilinçli. Aşağıdaki döngü şifrelemeden yazsaydı
        // hiçbir şey değişmezdi; en azından durum günlüğe düşsün.
        log_error('Göç 021: sır şifrelemesi kullanılamıyor (openssl ya da app_key yok); ayarlar düz metin bırakıldı.');
        return;
    }

    $isaret = setting_secret_marker();
    $rows = $pdo->query('SELECT skey, sval FROM settings')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $anahtar = (string)$r['skey'];
        $deger   = (string)$r['sval'];

        if ($deger === '') { continue; }
        if (!setting_is_secret($anahtar)) { continue; }
        // Zaten şifreli olanı tekrar şifrelemek değeri kurtarılamaz yapar.
        if (strpos($deger, $isaret) === 0) { continue; }

        $sifreli = setting_secret_encode($deger);
        // encode başarısız olursa girdiyi AYNEN döndürür; o hâlde yazmaya gerek yok.
        if ($sifreli === $deger) { continue; }

        $st = $pdo->prepare('UPDATE settings SET sval = :v WHERE skey = :k');
        $st->execute([':v' => $sifreli, ':k' => $anahtar]);
    }

    // Bellekteki önbellek düz metin sürümü tutuyor olabilir.
    if (function_exists('settings_all')) { settings_all(true); }
};
