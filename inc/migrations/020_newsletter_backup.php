<?php
/**
 * 020 — Bülten çift onay + zamanlı/uzak yedek. (Ajan-F, 1.2-09 / 1.2-11)
 *
 * BÜLTEN
 * Göç 003 `newsletter_subscribers` tablosunu `confirmed` sütunuyla açtı ama
 * onay akışı hiç yazılmadı: `inc/api/kunye_api.php:393` her kaydı `confirmed = 0`
 * yazıyor ve hiçbir yerde 1 yapılmıyordu. Yani sütun her zaman 0'dı ve "onaylı
 * abone" diye bir şey yoktu. Bu göç çift onay (double opt-in) ve abonelikten
 * çıkma için gereken sütunları ekler:
 *
 *   confirm_hash     onay bağlantısı token'ının SHA-256 ÖZETİ (ham token DEĞİL)
 *   confirm_expires  onay bağlantısının son kullanma anı
 *   unsub_hash       çıkış bağlantısı token'ının SHA-256 özeti (süresiz)
 *   confirmed_at     onayın alındığı an (KVKK: rızanın kanıtı)
 *   categories       ilgi segmenti — ',3,7,' biçiminde kategori kimlikleri
 *   source           kaydın geldiği yer (form, panel…)
 *   updated_at       son değişiklik
 *
 * Ham token saklanmaz; desen `member_tokens` ile aynıdır (inc/members.php:345,
 * `hash('sha256', $token)`). Veritabanı sızsa bile kimsenin aboneliği başkası
 * adına onaylanamaz ya da iptal edilemez.
 *
 * YEDEK
 * Zamanlı yedeğin durumu (son çalışma, son hata, uzak gönderim) `settings`
 * anahtarlarında tutulur (`backup_*`); yeni tablo gerekmez. Bu göç yalnız
 * varsayılan ayarları, YOKSA, yazar — var olan kurulumun ayarını EZMEZ.
 */

return function (PDO $pdo, $driver) {
    schema_add_column('newsletter_subscribers', 'confirm_hash', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
    schema_add_column('newsletter_subscribers', 'confirm_expires', '{DT}');
    schema_add_column('newsletter_subscribers', 'unsub_hash', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
    schema_add_column('newsletter_subscribers', 'confirmed_at', '{DT}');
    schema_add_column('newsletter_subscribers', 'categories', '{TXT}');
    schema_add_column('newsletter_subscribers', 'source', 'VARCHAR(60) NOT NULL DEFAULT \'\'');
    schema_add_column('newsletter_subscribers', 'updated_at', '{DT}');

    // Onay ve çıkış bağlantıları özet üzerinden aranır; indekssiz tam tarama olurdu.
    schema_create_index('newsletter_subscribers', 'idx_newsletter_confirm', '(confirm_hash)');
    schema_create_index('newsletter_subscribers', 'idx_newsletter_unsub', '(unsub_hash)');
    schema_create_index('newsletter_subscribers', 'idx_newsletter_state', '(confirmed, created_at)');

    // Yedekleme varsayılanları — yalnız hiç yazılmamışsa.
    $varsayilan = [
        'backup_keep'           => '5',      // saklanacak yedek adedi (disk dolmasın)
        'backup_auto_db'        => '1',      // günlük veritabanı yedeği
        'backup_auto_uploads'   => '1',      // haftalık uploads arşivi
        'backup_remote'         => '0',      // uzak (FTP) gönderim kapalı gelir
    ];
    foreach ($varsayilan as $k => $v) {
        $var = qv('SELECT skey FROM settings WHERE skey = :k', [':k' => $k], '');
        if ((string)$var === '') { setting_set($k, $v); }
    }
};
