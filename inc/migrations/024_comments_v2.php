<?php
/**
 * 024 — Yorum sistemi v2 (1.3-07, Ajan-B).
 *
 * Dört ihtiyaç tek göçte toplandı; hepsi 1.3 ile birlikte geliyor ve ayrı ayrı
 * uygulanmaları yayıncı için anlamsız bir adım daha demek olurdu.
 *
 * 1) `comments.parent_id` — TEK SEVİYE YANIT.
 *    Bugün yorumlar düz bir liste; okur bir yoruma cevap veremiyor, veremeyince
 *    tartışma haberin altında değil sosyal medyada sürüyor. Zincir BİLEREK tek
 *    seviyeyle sınırlı: derin zincirde moderatör "hangi dala ne oldu" sorusunu
 *    yanıtlayamaz (bir kökü spam yapmak on cevabı sessizce görünmez kılar) ve
 *    mobilde girinti üçüncü seviyede okunmaz hale gelir. Sütunun kendisi derin
 *    zinciri fiziksel olarak engellemez; kuralı `comments_parent_resolve()`
 *    uygular ve yanıtın yanıtını KÖKE bağlar.
 *
 * 2) `comments.author_role` — ÜYE / PERSONEL ROZETİ (NOTES.md ertelenen madde).
 *    Ertelenme gerekçesi şuydu: okur `display_name` alanına "Editör Ayşe" yazıp
 *    personel taklidi yapabiliyor, ad kara listesi ise hem aşılabilir hem meşru
 *    adları engeller. Doğru çözüm rolü GÖRÜNÜR kılmak. Rol yorumun yanına
 *    YAZILDIĞI ANDA yazılır, okunurken sorgulanmaz — çünkü ön yüz oturum
 *    açmadan basılır (CONTRACTS §3.1): rozet için `users` tablosuna bakmak,
 *    yorum listesi başına bir sorgu daha ve önbellekten sunulan sayfada
 *    çözülemeyecek bir bağımlılık demekti.
 *    Anlık görüntü olması ayrıca DOĞRU davranıştır: yorum yazıldığı gün kişi
 *    editördü; sonradan ayrılması geçmiş yorumun bağlamını değiştirmez.
 *
 * 3) `comments.report_count` / `reported_at` — BİLDİR.
 *    Okurun uygunsuz yorumu işaretlemesi. Sayaç yorumun üzerinde durur ki panel
 *    kuyruğu tek sorguyla sıralayabilsin; ayrıntı `comment_reports` içinde.
 *
 * 4) `comment_blacklist` + `comment_reports` — yeni tablolar.
 *
 * KVKK NOTU: `comments.ip` HAM IP tutmaya devam ediyor (NOTES.md "Ham IP hâlâ
 * yedekle site dışına gidiyor" maddesi — bilinen ve kayıtlı sınır). Bu göç onu
 * DEĞİŞTİRMEZ. Ama yeni gelen `comment_reports` tablosu ham IP TUTMAZ:
 * bildirenin kimliği yalnız `app_key` ile türetilmiş 32 haneli bir özettir ve
 * tek işlevi aynı kişinin aynı yorumu tekrar bildirmesini engellemektir.
 * Yeni bir tablo eski bir sınırı miras almak zorunda değildir.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    /* 1) Yanıt zinciri + rozet + bildirim sayacı ------------------------- */
    schema_add_column('comments', 'parent_id',    '{INT} DEFAULT 0');
    schema_add_column('comments', 'author_role',  'VARCHAR(20) NOT NULL DEFAULT \'\'');
    schema_add_column('comments', 'report_count', '{INT} DEFAULT 0');
    schema_add_column('comments', 'reported_at',  'VARCHAR(19) NOT NULL DEFAULT \'\'');

    // Ön yüz her haber sayfasında (post_id, status, parent_id) üçlüsünü sorar.
    schema_create_index('comments', 'idx_comments_post_status', '(post_id, status)');
    schema_create_index('comments', 'idx_comments_parent', '(parent_id)');
    // Panelin "bildirilenler" kuyruğu bu sütuna göre sıralar.
    schema_create_index('comments', 'idx_comments_reports', '(report_count)');

    /* 2) Kara liste ------------------------------------------------------ */
    // kind: 'email' | 'ip' | 'word'  · value: normalleştirilmiş (küçük harf) desen
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS comment_blacklist (
        id {ID},
        kind VARCHAR(10) NOT NULL DEFAULT \'word\',
        value VARCHAR(190) NOT NULL DEFAULT \'\',
        note VARCHAR(200) NOT NULL DEFAULT \'\',
        created_by {INT} DEFAULT 0,
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    // Aynı desenin iki kez girilmesi sessiz bir kafa karışıklığıdır: yayıncı
    // kaldırdığını sanır, ikinci satır eşleşmeye devam eder.
    schema_create_index('comment_blacklist', 'idx_cbl_kind_value', 'UNIQUE (kind, value)');

    /* 3) Bildirimler ----------------------------------------------------- */
    // reporter_hash: hash_hmac(sha256, ip, app_key)[0..32) — HAM IP DEĞİL.
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS comment_reports (
        id {ID},
        comment_id {INT} DEFAULT 0,
        reporter_hash VARCHAR(32) NOT NULL DEFAULT \'\',
        reason VARCHAR(20) NOT NULL DEFAULT \'\',
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    // Aynı kişi aynı yorumu bir kez bildirir; sayaç böylece anlamlı kalır.
    schema_create_index('comment_reports', 'idx_crep_uniq', 'UNIQUE (comment_id, reporter_hash)');
    schema_create_index('comment_reports', 'idx_crep_comment', '(comment_id)');
};
