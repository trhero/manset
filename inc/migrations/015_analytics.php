<?php
/**
 * 015 — Sürüm 1.2: kendi çerezsiz analitiğimiz (Ajan-A / 1.2-01).
 *
 * NEDEN KENDİ ANALİTİĞİMİZ, NEDEN BÖYLE BİR ŞEMA
 * ---------------------------------------------------------------------------
 * Bugün elimizde yalnız `posts.view_count` var: tek bir kümülatif sayı.
 * Onunla "dün kaç kişi geldi", "trafiğin ne kadarı aramadan geliyor",
 * "mobil okur oranı nedir" sorularının hiçbiri yanıtlanamaz. Dışarıdan bir
 * analitik (GA/Matomo) takmak ise ziyaretçiye üçüncü taraf çerezi verir —
 * CONTRACTS §3.1 ile (anonim ziyaretçi ÇEREZ ALMAZ) ve KVKK ile çakışır.
 *
 * KVKK / veri saklama kararları (bu göçün asıl gerekçesi):
 *
 *  1. **HAM IP HİÇBİR SÜTUNDA YOK.** IP yalnız "aynı kişiyi bugün iki kez
 *     saymayalım" sorusunun yanıtı için gerekiyor. Bu yüzden `hit_visitors`
 *     tablosunda IP değil, GÜNLÜK DEĞİŞEN bir tuzla üretilmiş 16 haneli kısa
 *     özet (`vhash`) durur. Tuz her gün yenilenir ve eskisi SİLİNİR; ertesi
 *     gün aynı IP başka bir özet üretir, yani özetler günler arası
 *     BİRLEŞTİRİLEMEZ ve geçmişe dönük bir kişiye bağlanamaz.
 *  2. **HAM REFERRER URL YOK.** Yalnız tür saklanır (`arama`, `sosyal`,
 *     `dogrudan`, `ic`, `diger`, `bilinmiyor`). Ham referrer, okurun hangi
 *     forumda/hangi arama teriminde olduğunu sızdırabilir.
 *  3. **HAM İSTEK SATIRI, USER-AGENT, EKRAN ÖLÇÜSÜ YOK.** Cihazdan yalnız
 *     iki değerli bir sınıf (`mobil`/`masaustu`) kalır.
 *  4. **Satır sayısı sınırlı.** Ziyaretçi özetleri gün dönünce özete
 *     indirgenip SİLİNİR (`analytics_cron_rollup`), günlük ayrıntı da
 *     yapılandırılabilir bir pencereden sonra haber kırılımını kaybeder.
 *     Sınırsız büyüyen bir tablo, paylaşımlı hostingde er geç diski doldurur.
 *
 * ŞEMA — üç tablo, üç farklı ömür:
 *
 *   hit_daily     Gün × haber × yönlendiren türü × cihaz sınıfı sayaçları.
 *                 YAZMA YOLUNDA olan tek tablo budur (tek UPSERT).
 *                 Ayrıntı penceresi dolunca `post_id = 0`'a katlanır.
 *   hit_visitors  Yalnız BUGÜNÜN tekil ziyaretçi özetleri. Ertesi gün
 *                 sayıya indirgenip silinir — kalıcı bir kimlik izi kalmaz.
 *   hit_summary   Gün başına özet: tekil ziyaretçi, görüntülenme, üye ve
 *                 premium sayısı. Abone hunisinin (ziyaret → üye → premium)
 *                 kaynağı budur; kalıcıdır ama satır başına yalnız 1 gün.
 *
 * UNIQUE indeksler yalnız "hız" için değil, YAZMA YOLUNUN KENDİSİ için var:
 * `hit_daily` UPSERT'i ve `hit_visitors` "varsa dokunma" davranışı bu
 * indekslere dayanır. İndeks düşerse yazma yolu çift satır üretir.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    /* 1) Günlük toplu sayaç — yazma yolundaki TEK tablo -------------------- */
    // day: 'YYYY-MM-DD'. Tarih sütunu yerine dize: sürücüden bağımsız
    // karşılaştırılabilir ve projenin geri kalanıyla ('Y-m-d H:i:s' dizeleri)
    // tutarlı. post_id = 0 → "haber kırılımı budandı, gün toplamı" satırı.
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS hit_daily (
        id {ID},
        day VARCHAR(10) NOT NULL DEFAULT \'\',
        post_id {INT} DEFAULT 0,
        ref VARCHAR(12) NOT NULL DEFAULT \'diger\',
        dev VARCHAR(10) NOT NULL DEFAULT \'masaustu\',
        hits {INT} DEFAULT 0
    )' . $ph['{SUF}'], $ph));
    // UPSERT'in dayandığı anahtar. Sıra bilinçli: en seçici sütun (day) başta,
    // çünkü rapor sorgularının hepsi gün aralığıyla süzer.
    schema_create_index('hit_daily', 'idx_hit_daily_key', 'UNIQUE (day, post_id, ref, dev)');
    // "En çok okunanlar" sorgusu haber kırılımını gün aralığında tarar.
    schema_create_index('hit_daily', 'idx_hit_daily_post', '(day, post_id)');

    /* 2) Günlük tekil ziyaretçi özetleri — ömrü 1 gün ---------------------- */
    // vhash: hash_hmac('sha256', ip, gunluk_tuz) çıktısının ilk 16 hanesi.
    // 16 hane (64 bit) tekilleştirme için fazlasıyla yeterli, kaba kuvvetle
    // IP'ye geri döndürmek içinse tuz bilinmeden imkânsız — ve tuz her gün
    // silindiği için dünkü satırlar bugün ARTIK ÇÖZÜLEMEZ.
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS hit_visitors (
        id {ID},
        day VARCHAR(10) NOT NULL DEFAULT \'\',
        vhash VARCHAR(16) NOT NULL DEFAULT \'\'
    )' . $ph['{SUF}'], $ph));
    schema_create_index('hit_visitors', 'idx_hit_visitors_key', 'UNIQUE (day, vhash)');

    /* 3) Gün özeti ve abone hunisi — kalıcı, satır başına 1 gün ------------ */
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS hit_summary (
        id {ID},
        day VARCHAR(10) NOT NULL DEFAULT \'\',
        visitors {INT} DEFAULT 0,
        hits {INT} DEFAULT 0,
        members {INT} DEFAULT 0,
        premium {INT} DEFAULT 0,
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('hit_summary', 'idx_hit_summary_day', 'UNIQUE (day)');

    /* 4) Varsayılan ayarlar ------------------------------------------------ */
    // NEDEN GÖÇTE YAZILIYOR: ayarlar ekranı değeri `setting($k, '')` ile okur,
    // yani kayıtlı değer yoksa alan BOŞ görünür ve ilk kaydedişte sınırın alt
    // ucuna (7 gün / 30 gün) düşerdi — yayıncı hiçbir şey değiştirmediği hâlde
    // saklama süresi sessizce kısalırdı. Varsayılanlar bu yüzden bir kez
    // gerçekten yazılır. Var olan değer EZİLMEZ (yükseltmede ayar kaybolmasın).
    $varsayilan = [
        'analytics_enabled'     => '1',
        'analytics_detail_days' => '60',
        'analytics_keep_days'   => '365',
        'analytics_external_on' => '0',
    ];
    foreach ($varsayilan as $k => $v) {
        $var = (int)qv('SELECT COUNT(*) FROM settings WHERE skey = :k', [':k' => $k], 0);
        if ($var === 0) { setting_set($k, $v); }
    }
};
