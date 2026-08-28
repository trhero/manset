<?php
/**
 * 012 — Denetim kaydı ve reklam türü ayrımı.
 *
 * Beş danışmanın tamamı `ads.manage` ham HTML yetkisinin fiilen `admin` yetkisi
 * olduğunu bildirdi (ad_slot() temizlemeden basar → aynı kökende JS → CSRF anahtarı
 * okunabilir). Bu yüzden reklam kaydı ikiye ayrılıyor:
 *   kind='image' → görsel + bağlantı + alt metin (güvenli, ads.creative yeter)
 *   kind='html'  → ham kod (ads.html KİLİTLİ izni + approved_by dolu olmalı)
 * Bkz. USER_TYPES_PLAN.md §8.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    schema_add_column('ads', 'kind',        "VARCHAR(20) NOT NULL DEFAULT 'html'");
    schema_add_column('ads', 'image',       "VARCHAR(255) NOT NULL DEFAULT ''");
    schema_add_column('ads', 'link_url',    "VARCHAR(500) NOT NULL DEFAULT ''");
    schema_add_column('ads', 'alt_text',    "VARCHAR(190) NOT NULL DEFAULT ''");
    schema_add_column('ads', 'approved_by', '{INT} DEFAULT 0');

    schema_add_column('corrections', 'handled_by', '{INT} DEFAULT 0');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS audit_log (
        id {ID},
        user_id {INT} DEFAULT 0,
        action VARCHAR(60) NOT NULL DEFAULT \'\',
        target VARCHAR(120) NOT NULL DEFAULT \'\',
        detail {TXT},
        ip VARCHAR(64) NOT NULL DEFAULT \'\',
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('audit_log', 'idx_audit_created', '(created_at)');
    schema_create_index('audit_log', 'idx_audit_user', '(user_id, action)');

    // Mevcut reklamlar: hepsi ham HTML kabul edilir ve YÖNETİCİ ONAYLI sayılır
    // (kurulumda zaten yalnız admin girebiliyordu — geriye dönük kırılma olmasın).
    $adminId = (int)qv('SELECT id FROM users WHERE role = \'admin\' AND active = 1 ORDER BY id ASC LIMIT 1', [], 0);
    $pdo->exec('UPDATE ads SET kind = \'html\' WHERE kind IS NULL OR kind = \'\'');
    if ($adminId > 0) {
        q('UPDATE ads SET approved_by = :a WHERE approved_by = 0', [':a' => $adminId]);
    }
};
