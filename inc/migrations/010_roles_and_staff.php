<?php
/**
 * 010 — Rol altyapısı: personel işareti, izin geçersiz kılma katmanı,
 * kullanıcıya özel ek izinler ve masa editörü kategori kapsamı.
 * Bkz. USER_TYPES_PLAN.md §4.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    schema_add_column('users', 'is_staff',       '{INT} DEFAULT 1');
    schema_add_column('users', 'is_responsible', '{INT} DEFAULT 0');
    schema_add_column('users', 'display_name',   "VARCHAR(120) NOT NULL DEFAULT ''");
    schema_add_column('users', 'bio',            '{TXT}');
    schema_add_column('users', 'avatar',         "VARCHAR(255) NOT NULL DEFAULT ''");
    schema_add_column('users', 'last_login_at',  '{DT}');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS role_overrides (
        id {ID},
        role VARCHAR(40) NOT NULL DEFAULT \'\',
        permission VARCHAR(60) NOT NULL DEFAULT \'\',
        allowed {INT} DEFAULT 0,
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('role_overrides', 'idx_role_ovr', 'UNIQUE (role, permission)');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS user_grants (
        id {ID},
        user_id {INT} DEFAULT 0,
        permission VARCHAR(60) NOT NULL DEFAULT \'\',
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('user_grants', 'idx_user_grant', 'UNIQUE (user_id, permission)');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS user_categories (
        id {ID},
        user_id {INT} DEFAULT 0,
        category_id {INT} DEFAULT 0
    )' . $ph['{SUF}'], $ph));
    schema_create_index('user_categories', 'idx_user_cat', 'UNIQUE (user_id, category_id)');

    // Geçiş: mevcut roller korunur. Yalnız üyeler personel değildir.
    $pdo->exec('UPDATE users SET is_staff = 0 WHERE role = \'member\'');
    $pdo->exec('UPDATE users SET is_staff = 1 WHERE role <> \'member\'');
};
