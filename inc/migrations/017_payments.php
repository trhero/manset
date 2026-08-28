<?php
/**
 * 017 — Abonelik planları, ödeme kayıtları ve webhook olay defteri (1.2-04).
 *
 * TASARIM NOTLARI
 *
 * `plans`   Yayıncının sattığı abonelik paketleri. Fiyat KURUŞ (tam sayı)
 *           tutulur; kayan noktalı para asla saklanmaz (0.1 + 0.2 sorunu
 *           tahsilat karşılaştırmasında doğrudan güvenlik sorunudur —
 *           webhook tutarı kendi kaydımızla TAM eşitlikle karşılaştırılır).
 *
 * `payments` Her tahsilat girişimi. `ref` BİZİM ürettiğimiz sipariş
 *           numarasıdır (sağlayıcıya `merchant_oid` olarak gider) ve
 *           BENZERSİZDİR. Tutar/para birimi/gün sayısı plandan KOPYALANIR:
 *           plan sonradan değişse bile ödeme kendi koşullarını taşır ve
 *           webhook doğrulaması geçmişe dönük bozulmaz.
 *           `applied_at` + `granted_until` iade akışının dayanağıdır:
 *           hangi ödemenin aboneliği nereye kadar uzattığı kayıtlıdır.
 *
 * `payment_events` Webhook/olay defteri. `event_key` BENZERSİZDİR ve
 *           ÇİFT İŞLEM KALKANININ ta kendisidir: aynı olay ikinci kez
 *           geldiğinde INSERT veritabanı düzeyinde reddedilir. Sağlayıcılar
 *           webhook'u tekrar gönderir — bu kural dışı değil, normaldir.
 *           Kalkanı uygulama katmanındaki "önce SELECT sonra INSERT"e
 *           bırakmak eşzamanlı iki webhook'ta yarışa açıktır; benzersiz
 *           indeks atomiktir.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    // ------------------------------------------------------------ planlar
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS plans (
        id {ID},
        code VARCHAR(40) NOT NULL DEFAULT \'\',
        name VARCHAR(120) NOT NULL DEFAULT \'\',
        tier VARCHAR(20) NOT NULL DEFAULT \'premium\',
        days {INT} DEFAULT 30,
        price_cents {INT} DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT \'TRY\',
        description VARCHAR(190) NOT NULL DEFAULT \'\',
        active {INT} DEFAULT 1,
        sort {INT} DEFAULT 0,
        created_at {DT},
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('plans', 'idx_plans_code', 'UNIQUE (code)');
    schema_create_index('plans', 'idx_plans_active', '(active, sort)');

    // ------------------------------------------------------------ ödemeler
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS payments (
        id {ID},
        ref VARCHAR(64) NOT NULL DEFAULT \'\',
        user_id {INT} DEFAULT 0,
        plan_id {INT} DEFAULT 0,
        provider VARCHAR(30) NOT NULL DEFAULT \'manual_iban\',
        status VARCHAR(20) NOT NULL DEFAULT \'pending\',
        amount_cents {INT} DEFAULT 0,
        currency VARCHAR(3) NOT NULL DEFAULT \'TRY\',
        days {INT} DEFAULT 0,
        plan_name VARCHAR(120) NOT NULL DEFAULT \'\',
        provider_ref VARCHAR(120) NOT NULL DEFAULT \'\',
        receipt_file VARCHAR(120) NOT NULL DEFAULT \'\',
        note VARCHAR(190) NOT NULL DEFAULT \'\',
        approved_by {INT} DEFAULT 0,
        granted_until {DT},
        applied_at {DT},
        created_at {DT},
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    // ref BENZERSİZ: aynı sipariş numarası iki kez açılamaz.
    schema_create_index('payments', 'idx_payments_ref', 'UNIQUE (ref)');
    schema_create_index('payments', 'idx_payments_user', '(user_id, status)');
    schema_create_index('payments', 'idx_payments_status', '(status, created_at)');

    // ------------------------------------------------------------ olay defteri
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS payment_events (
        id {ID},
        payment_id {INT} DEFAULT 0,
        provider VARCHAR(30) NOT NULL DEFAULT \'\',
        event_key VARCHAR(190) NOT NULL DEFAULT \'\',
        kind VARCHAR(30) NOT NULL DEFAULT \'\',
        ok {INT} DEFAULT 0,
        message VARCHAR(255) NOT NULL DEFAULT \'\',
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    // ÇİFT İŞLEM KALKANI — atomik. Bkz. dosya başındaki açıklama.
    schema_create_index('payment_events', 'idx_payevent_key', 'UNIQUE (event_key)');
    schema_create_index('payment_events', 'idx_payevent_payment', '(payment_id, id)');

    // ------------------------------------------------------------ başlangıç planı
    // Yayıncı panelden düzenler; kurulum sonrası ekran boş kalmasın diye
    // KAPALI (active = 0) bir örnek plan bırakılır. Kapalı olması bilinçlidir:
    // yayıncı fiyatı gözden geçirmeden hiçbir plan satışa çıkmaz.
    $var = $pdo->query('SELECT COUNT(*) FROM plans')->fetchColumn();
    if ((int)$var === 0) {
        $st = $pdo->prepare('INSERT INTO plans
            (code, name, tier, days, price_cents, currency, description, active, sort, created_at, updated_at)
            VALUES (:c, :n, :t, :d, :p, :cur, :desc, 0, 10, :ca, :ua)');
        $st->execute([
            ':c' => 'aylik', ':n' => 'Aylık abonelik', ':t' => 'premium',
            ':d' => 30, ':p' => 9900, ':cur' => 'TRY',
            ':desc' => 'Örnek plan — fiyatı gözden geçirip etkinleştirin.',
            ':ca' => now(), ':ua' => now(),
        ]);
    }
};
