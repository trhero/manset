<?php
/**
 * 018 — Ölçülü ödeme duvarı: hediye bağlantısı, deneme, hatırlatma defteri (1.2-05).
 *
 * TASARIM NOTLARI
 *
 * `gift_tokens`  Bir abonenin abone olmayan birine TEK bir haberi açtığı
 *                bağlantı. HAM token SAKLANMAZ; yalnız `hash('sha256', $ham)`
 *                yazılır — `member_tokens` deseninin aynısı (inc/members.php
 *                member_token_create/consume). Veritabanı sızarsa hiçbir
 *                bağlantı yeniden üretilemez.
 *
 *                `token_hash` BENZERSİZDİR. Tek kullanımlık olma güvencesi ise
 *                indeksle değil, tüketimde atomik KARŞILAŞTIR-VE-DEĞİŞTİR ile
 *                sağlanır:
 *                  UPDATE gift_tokens SET used_at = :n
 *                   WHERE id = :i AND (used_at IS NULL OR used_at = '')
 *                ve yalnız `rowCount() === 1` olan istek kabul edilir.
 *                "Önce SELECT, sonra UPDATE" eşzamanlı iki isteğe açıktı;
 *                tek deyimlik UPDATE veritabanı düzeyinde atomiktir ve hem
 *                SQLite hem MySQL'de aynı çalışır (Ajan-C'nin T4/T17
 *                karşılığıyla aynı desen).
 *
 * `paywall_trials`  Deneme süresi. `user_id` BENZERSİZDİR: deneme HESAP BAŞINA
 *                BİR KEZDİR. Süresi dolduktan sonra satır silinmez — silinseydi
 *                okur denemeyi sonsuz kez yeniden başlatabilirdi. "Deneme bitti"
 *                bilgisi, "deneme hiç olmadı" bilgisinden farklıdır.
 *
 * `paywall_notices`  Yenileme hatırlatmasının gönderim defteri. (user_id, kind,
 *                period_key) BENZERSİZDİR: aynı bitiş tarihi için ikinci kez
 *                e-posta gönderilemez. Cron'un iki kez koşması (panel tetiklemesi
 *                + gerçek cron) normaldir; yinelenen e-posta okurun gözünde
 *                yayıncıyı spam'e çevirir.
 *
 * SAYAÇ TABLOSU YOK — BİLEREK
 * Aylık ücretsiz haber sayacı SUNUCUDA TUTULMAZ. Okur başına satır açmak,
 * giriş yapmamış ziyaretçiyi tanımlamak için kalıcı bir tanımlayıcı (ya da IP)
 * saklamak demekti; KVKK açısından çerezden daha ağır bir izdir ve sözleşmenin
 * "sunucuda satır tutma" yönergesine aykırıdır. Sayaç, `app_key` ile imzalanmış
 * bir çerezde durur (bkz. inc/paywall.php, "İMZALI SAYAÇ ÇEREZİ").
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    // ------------------------------------------------------------ hediye bağlantıları
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS gift_tokens (
        id {ID},
        token_hash VARCHAR(64) NOT NULL DEFAULT \'\',
        post_id {INT} DEFAULT 0,
        giver_id {INT} DEFAULT 0,
        period_key VARCHAR(7) NOT NULL DEFAULT \'\',
        used_at {DT},
        expires_at {DT},
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('gift_tokens', 'idx_gift_hash', 'UNIQUE (token_hash)');
    // Aylık hediye kotası bu indeksten sayılır.
    schema_create_index('gift_tokens', 'idx_gift_giver', '(giver_id, period_key)');
    schema_create_index('gift_tokens', 'idx_gift_expires', '(expires_at)');

    // ------------------------------------------------------------ deneme
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS paywall_trials (
        id {ID},
        user_id {INT} DEFAULT 0,
        days {INT} DEFAULT 0,
        source VARCHAR(30) NOT NULL DEFAULT \'self\',
        started_at {DT},
        ends_at {DT}
    )' . $ph['{SUF}'], $ph));
    // HESAP BAŞINA BİR DENEME — indeks düzeyinde.
    schema_create_index('paywall_trials', 'idx_trial_user', 'UNIQUE (user_id)');

    // ------------------------------------------------------------ hatırlatma defteri
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS paywall_notices (
        id {ID},
        user_id {INT} DEFAULT 0,
        kind VARCHAR(20) NOT NULL DEFAULT \'renew\',
        period_key VARCHAR(40) NOT NULL DEFAULT \'\',
        sent_at {DT}
    )' . $ph['{SUF}'], $ph));
    // Aynı dönem için ikinci gönderim veritabanı düzeyinde reddedilir.
    schema_create_index('paywall_notices', 'idx_notice_once', 'UNIQUE (user_id, kind, period_key)');
};
