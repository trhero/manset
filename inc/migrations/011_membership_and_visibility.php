<?php
/**
 * 011 — Üyelik ve içerik görünürlüğü.
 *
 * `posts.visibility` üç düzey alır: public | members | premium.
 * Premium bir ROL DEĞİLDİR — süresi dolabilen bir abonelik kademesidir
 * (`memberships.tier` + `valid_until`); süre okuma anında değerlendirilir,
 * cron'a bağımlı değildir. Bkz. USER_TYPES_PLAN.md §6.
 */
return function (PDO $pdo, $driver) {
    $ph = schema_ph($driver);

    schema_add_column('posts', 'visibility', "VARCHAR(20) NOT NULL DEFAULT 'public'");
    schema_add_column('posts', 'teaser', '{TXT}');
    schema_create_index('posts', 'idx_posts_visibility', '(visibility, status)');

    // comments.user_id şemada zaten var; olmayan kurulumlar için güvence
    schema_add_column('comments', 'user_id', '{INT} DEFAULT 0');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS memberships (
        id {ID},
        user_id {INT} DEFAULT 0,
        tier VARCHAR(20) NOT NULL DEFAULT \'free\',
        valid_until {DT},
        note VARCHAR(190) NOT NULL DEFAULT \'\',
        created_at {DT},
        updated_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('memberships', 'idx_membership_user', 'UNIQUE (user_id)');
    schema_create_index('memberships', 'idx_membership_valid', '(tier, valid_until)');

    // E-posta doğrulama ve parola sıfırlama: yalnız token'ın SHA-256 özeti saklanır
    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS member_tokens (
        id {ID},
        user_id {INT} DEFAULT 0,
        kind VARCHAR(20) NOT NULL DEFAULT \'verify\',
        token_hash VARCHAR(64) NOT NULL DEFAULT \'\',
        expires_at {DT},
        used_at {DT},
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('member_tokens', 'idx_token_hash', 'UNIQUE (token_hash)');
    schema_create_index('member_tokens', 'idx_token_user', '(user_id, kind)');

    $pdo->exec(strtr('CREATE TABLE IF NOT EXISTS bookmarks (
        id {ID},
        user_id {INT} DEFAULT 0,
        post_id {INT} DEFAULT 0,
        created_at {DT}
    )' . $ph['{SUF}'], $ph));
    schema_create_index('bookmarks', 'idx_bookmark', 'UNIQUE (user_id, post_id)');

    // Mevcut haberlerin tamamı herkese açık kalır (geriye dönük kırılma yok)
    $pdo->exec('UPDATE posts SET visibility = \'public\' WHERE visibility IS NULL OR visibility = \'\'');
};
