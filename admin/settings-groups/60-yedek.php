<?php
/**
 * Ayarlar → Yedekleme (Ajan-F, 1.2-09).
 *
 * Zamanlı yedeğin ve uzak (FTP) hedefin ayarları. Yedeğin DURUMU burada değil,
 * Araçlar → Yedekleme ekranında gösterilir (yaş, son hata, uzak gönderim).
 *
 * FTP PAROLASI `secret` tipiyle saklanır (1.2 sözleşmesi §2.1): ekrana asla
 * basılmaz ve alan boş gönderilirse mevcut parola KORUNUR. Günlüğe de yazılmaz —
 * `backup_ftp_put_target()` hata metinlerine parolayı hiç koymaz.
 *
 * `backup_ftp_host` bir SSRF hedefidir: `backup_ftp_target()` içinde projenin
 * mevcut kalkanı `safe_remote_target()` ile doğrulanır (iç ağ adresleri reddedilir).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

return [
    'key'   => 'yedek',
    'title' => 'Yedekleme',
    'perm'  => 'tools.manage',
    'note'  => 'Zamanlı yedek cron ile çalışır. Cron kurulu değilse yedek de alınmaz — '
             . 'Araçlar → Yedekleme ekranındaki "yedek yaşı" bunu gösterir.',
    'fields' => [
        'backup_auto_db'      => ['bool',   'Zamanlı veritabanı yedeği', 'Cron turunda sırası gelince yedek alınır.'],
        'backup_db_days'      => ['number', 'Veritabanı yedeği sıklığı (gün)', '1 = her gün. 1–30.'],
        'backup_auto_uploads' => ['bool',   'Zamanlı uploads arşivi', 'Görsel klasörünün tar arşivi. Yalnız gerçek cron ile çalışır.'],
        'backup_uploads_days' => ['number', 'Uploads arşivi sıklığı (gün)', '7 = haftalık. 1–90.'],
        'backup_keep'         => ['number', 'Saklanacak yedek adedi', 'Tür başına (veritabanı ve uploads ayrı ayrı). Eskiler silinir; disk dolmaz.'],

        'backup_remote'       => ['bool',   'Uzak yedek (FTP)', 'Alınan yedek FTP ile başka bir sunucuya kopyalanır.'],
        'backup_ftp_host'     => ['text',   'FTP sunucusu', 'Örn. yedek.ornek.com. Yerel/iç ağ adresleri güvenlik gereği reddedilir.'],
        'backup_ftp_port'     => ['number', 'FTP portu', 'Varsayılan 21.'],
        'backup_ftp_user'     => ['text',   'FTP kullanıcı adı', ''],
        'backup_ftp_pass'     => ['secret', 'FTP parolası', 'Boş bırakırsanız mevcut parola korunur.'],
        'backup_ftp_dir'      => ['text',   'Uzak klasör', 'Örn. mansetyedek. Boş bırakılırsa ana klasöre yazılır.'],
        'backup_ftp_passive'  => ['bool',   'Pasif kip (PASV)', 'Paylaşımlı hostinglerin çoğunda açık olmalıdır.'],
        'backup_ftp_ssl'      => ['bool',   'FTPS (açık SSL)', 'Sunucunuz destekliyorsa açın.'],
        'backup_ftp_timeout'  => ['number', 'FTP zaman aşımı (sn)', '5–120.'],
    ],
    'clamp' => function (array $saved) {
        if (isset($saved['backup_db_days']))      { $saved['backup_db_days']      = (string)max(1, min(30, (int)$saved['backup_db_days'])); }
        if (isset($saved['backup_uploads_days'])) { $saved['backup_uploads_days'] = (string)max(1, min(90, (int)$saved['backup_uploads_days'])); }
        if (isset($saved['backup_keep']))         { $saved['backup_keep']         = (string)max(1, min(60, (int)$saved['backup_keep'])); }
        if (isset($saved['backup_ftp_port']))     { $saved['backup_ftp_port']     = (string)max(1, min(65535, (int)$saved['backup_ftp_port'])); }
        if (isset($saved['backup_ftp_timeout']))  { $saved['backup_ftp_timeout']  = (string)max(5, min(120, (int)$saved['backup_ftp_timeout'])); }
        return $saved;
    },
];
