<?php
/**
 * Manşet — şema kurulumu, göç (migration) yürütücüsü ve tohum veri.
 * Tek DDL kaynağı: aşağıdaki schema_tables(). Yer tutucular sürücüye göre çevrilir.
 */

require_once __DIR__ . '/bootstrap.php';

/** Sürücüye göre DDL yer tutucu çevirisi. */
function schema_ph($driver = null) {
    $driver = $driver ?: db_driver();
    if ($driver === 'mysql') {
        return [
            '{ID}'   => 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            '{INT}'  => 'INT NOT NULL',
            '{TXT}'  => 'TEXT',
            '{LONG}' => 'LONGTEXT',
            '{DT}'   => 'DATETIME NULL DEFAULT NULL',
            '{SUF}'  => ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
    return [
        '{ID}'   => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        '{INT}'  => 'INTEGER NOT NULL',
        '{TXT}'  => 'TEXT',
        '{LONG}' => 'TEXT',
        '{DT}'   => 'TEXT NULL DEFAULT NULL',
        '{SUF}'  => '',
    ];
}

/** Tablo adı => sütun gövdesi (yer tutuculu). */
function schema_tables() {
    return [
        'settings' => "
            skey VARCHAR(120) NOT NULL PRIMARY KEY,
            sval {LONG}
        ",
        'migrations' => "
            name VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at {DT}
        ",
        'users' => "
            id {ID},
            name VARCHAR(120) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            pass_hash VARCHAR(255) NOT NULL DEFAULT '',
            role VARCHAR(40) NOT NULL DEFAULT 'yazar',
            active {INT} DEFAULT 1,
            created_at {DT}
        ",
        'categories' => "
            id {ID},
            name VARCHAR(120) NOT NULL DEFAULT '',
            slug VARCHAR(140) NOT NULL DEFAULT '',
            sort {INT} DEFAULT 0,
            color VARCHAR(20) NOT NULL DEFAULT '#c0392b',
            in_menu {INT} DEFAULT 1,
            active {INT} DEFAULT 1
        ",
        'posts' => "
            id {ID},
            title VARCHAR(255) NOT NULL DEFAULT '',
            slug VARCHAR(200) NOT NULL DEFAULT '',
            spot {TXT},
            body {LONG},
            image VARCHAR(255) NOT NULL DEFAULT '',
            category_id {INT} DEFAULT 0,
            author_id {INT} DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            type VARCHAR(20) NOT NULL DEFAULT 'haber',
            source_name VARCHAR(190) NOT NULL DEFAULT '',
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            ai_generated {INT} DEFAULT 0,
            seo_title VARCHAR(255) NOT NULL DEFAULT '',
            seo_desc VARCHAR(500) NOT NULL DEFAULT '',
            tags VARCHAR(500) NOT NULL DEFAULT '',
            view_count {INT} DEFAULT 0,
            is_headline {INT} DEFAULT 0,
            headline_sort {INT} DEFAULT 0,
            is_breaking {INT} DEFAULT 0,
            published_at {DT},
            created_at {DT},
            updated_at {DT}
        ",
        'media' => "
            id {ID},
            filename VARCHAR(255) NOT NULL DEFAULT '',
            alt VARCHAR(255) NOT NULL DEFAULT '',
            mime VARCHAR(100) NOT NULL DEFAULT '',
            width {INT} DEFAULT 0,
            height {INT} DEFAULT 0,
            size {INT} DEFAULT 0,
            variants {TXT},
            created_at {DT}
        ",
        'rss_sources' => "
            id {ID},
            name VARCHAR(190) NOT NULL DEFAULT '',
            url VARCHAR(500) NOT NULL DEFAULT '',
            category_id {INT} DEFAULT 0,
            auto_publish {INT} DEFAULT 0,
            ai_rewrite {INT} DEFAULT 0,
            active {INT} DEFAULT 1,
            error_count {INT} DEFAULT 0,
            last_fetch_at {DT},
            fetch_error {TXT}
        ",
        'rss_items' => "
            id {ID},
            source_id {INT} DEFAULT 0,
            guid_hash VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            link VARCHAR(500) NOT NULL DEFAULT '',
            image VARCHAR(500) NOT NULL DEFAULT '',
            summary {TXT},
            content {LONG},
            published_at {DT},
            fetched_at {DT},
            status VARCHAR(20) NOT NULL DEFAULT 'new'
        ",
        'ai_jobs' => "
            id {ID},
            rss_item_id {INT} DEFAULT 0,
            kind VARCHAR(40) NOT NULL DEFAULT 'rss_rewrite',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            attempts {INT} DEFAULT 0,
            result_post_id {INT} DEFAULT 0,
            error {TXT},
            created_at {DT},
            started_at {DT},
            finished_at {DT}
        ",
        'comments' => "
            id {ID},
            post_id {INT} DEFAULT 0,
            user_id {INT} DEFAULT 0,
            name VARCHAR(120) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            body {TXT},
            ip VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at {DT}
        ",
        'ads' => "
            id {ID},
            slot_key VARCHAR(40) NOT NULL DEFAULT '',
            title VARCHAR(190) NOT NULL DEFAULT '',
            html {LONG},
            active {INT} DEFAULT 1,
            sort {INT} DEFAULT 0,
            starts_at {DT},
            ends_at {DT}
        ",
        'pages' => "
            id {ID},
            title VARCHAR(190) NOT NULL DEFAULT '',
            slug VARCHAR(200) NOT NULL DEFAULT '',
            body {LONG},
            in_footer {INT} DEFAULT 1,
            sort {INT} DEFAULT 0,
            created_at {DT},
            updated_at {DT}
        ",
        'corrections' => "
            id {ID},
            post_id {INT} DEFAULT 0,
            name VARCHAR(120) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            message {TXT},
            note {TXT},
            ip VARCHAR(64) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at {DT}
        ",
        'ai_logs' => "
            id {ID},
            provider VARCHAR(40) NOT NULL DEFAULT '',
            model VARCHAR(120) NOT NULL DEFAULT '',
            action VARCHAR(60) NOT NULL DEFAULT '',
            prompt_summary {TXT},
            tokens_in {INT} DEFAULT 0,
            tokens_out {INT} DEFAULT 0,
            duration_ms {INT} DEFAULT 0,
            ok {INT} DEFAULT 1,
            error {TXT},
            created_at {DT}
        ",
        'rate_limits' => "
            id {ID},
            bucket VARCHAR(190) NOT NULL DEFAULT '',
            created_at {DT}
        ",
    ];
}

/** Tablo adı => oluşturulacak indeksler [indeks_adı => 'UNIQUE?(sütunlar)']. */
function schema_indexes() {
    return [
        'users'       => ['idx_users_email' => 'UNIQUE (email)'],
        'categories'  => ['idx_cat_slug' => 'UNIQUE (slug)', 'idx_cat_sort' => '(sort)'],
        'posts'       => [
            'idx_posts_slug'   => 'UNIQUE (slug)',
            'idx_posts_status' => '(status, published_at)',
            'idx_posts_cat'    => '(category_id, status, published_at)',
            'idx_posts_head'   => '(is_headline, headline_sort)',
            'idx_posts_author' => '(author_id)',
            'idx_posts_views'  => '(view_count)',
        ],
        'pages'       => ['idx_pages_slug' => 'UNIQUE (slug)'],
        'comments'    => ['idx_comments_post' => '(post_id, status)'],
        'rss_items'   => ['idx_rssitems_guid' => 'UNIQUE (guid_hash)', 'idx_rssitems_src' => '(source_id, status)'],
        'ai_jobs'     => ['idx_aijobs_status' => '(status)'],
        'ai_logs'     => ['idx_ailogs_created' => '(created_at)'],
        'rate_limits' => ['idx_rl_bucket' => '(bucket, created_at)'],
        'ads'         => ['idx_ads_slot' => '(slot_key, active)'],
        'media'       => ['idx_media_created' => '(created_at)'],
        'corrections' => ['idx_corr_status' => '(status)'],
    ];
}

/** Tüm tabloları ve indeksleri oluşturur (varsa dokunmaz). */
function schema_install() {
    $pdo = db();
    $ph = schema_ph();
    foreach (schema_tables() as $table => $body) {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . $table . " (\n" . trim($body) . "\n)" . $ph['{SUF}'];
        $sql = strtr($sql, $ph);
        $pdo->exec($sql);
    }
    foreach (schema_indexes() as $table => $indexes) {
        foreach ($indexes as $name => $def) {
            schema_create_index($table, $name, $def);
        }
    }
    return true;
}

/** Sürücü farkını gizleyerek indeks oluşturur. */
function schema_create_index($table, $name, $def) {
    $pdo = db();
    $unique = stripos($def, 'UNIQUE') === 0;
    $cols = trim(preg_replace('/^UNIQUE\s*/i', '', $def));
    $ifNotExists = (db_driver() === 'sqlite') ? 'IF NOT EXISTS ' : '';
    $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . $ifNotExists . $name . ' ON ' . $table . ' ' . $cols;
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        // MySQL'de "IF NOT EXISTS" yok; zaten varsa yok say.
        if (stripos($e->getMessage(), 'duplicate') === false && stripos($e->getMessage(), 'exists') === false) {
            log_error('indeks oluşturulamadı ' . $name . ': ' . $e->getMessage());
        }
    }
}

/** Bir tabloda sütun var mı? */
function schema_has_column($table, $column) {
    try {
        if (db_driver() === 'sqlite') {
            foreach (qa('PRAGMA table_info(' . preg_replace('/[^a-z0-9_]/i', '', $table) . ')') as $c) {
                if (strcasecmp($c['name'], $column) === 0) { return true; }
            }
            return false;
        }
        $r = q1('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
            [':t' => $table, ':c' => $column]);
        return (bool)$r;
    } catch (Throwable $e) { return false; }
}

/** Sütun yoksa ekler. $def yer tutucu içerebilir. */
function schema_add_column($table, $column, $def) {
    if (schema_has_column($table, $column)) { return false; }
    $sql = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . strtr($def, schema_ph());
    db()->exec($sql);
    return true;
}

/** Bir tablo var mı? */
function schema_has_table($table) {
    try {
        if (db_driver() === 'sqlite') {
            return (bool)qv('SELECT name FROM sqlite_master WHERE type = \'table\' AND name = :t', [':t' => $table]);
        }
        return (bool)qv('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t', [':t' => $table]);
    } catch (Throwable $e) { return false; }
}

/**
 * inc/migrations/*.php dosyalarını ada göre sıralı uygular.
 * Her dosya `return function (PDO $pdo, string $driver) { ... };` döndürür.
 * Uygulananlar migrations tablosuna yazılır.
 */
function migrations_run($verbose = false) {
    schema_install();
    $applied = [];
    foreach (qa('SELECT name FROM migrations') as $r) { $applied[$r['name']] = true; }
    $files = glob(INC_DIR . '/migrations/*.php');
    if (!$files) { return []; }
    sort($files, SORT_STRING);
    $done = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (isset($applied[$name])) { continue; }
        $fn = require $file;
        try {
            if (is_callable($fn)) { $fn(db(), db_driver()); }
            q('INSERT INTO migrations (name, applied_at) VALUES (:n, :a)', [':n' => $name, ':a' => now()]);
            $done[] = $name;
            if ($verbose) { echo 'migration uygulandı: ' . $name . "\n"; }
        } catch (Throwable $e) {
            log_error('Migration hatası ' . $name . ': ' . $e->getMessage());
            if ($verbose) { echo 'HATA ' . $name . ': ' . $e->getMessage() . "\n"; }
            throw $e;
        }
    }
    return $done;
}

/** Kurulumda yazılan varsayılan ayarlar. */
function schema_default_settings($siteTitle = 'Manşet', $theme = 'gazete', $email = '') {
    return [
        'site_title'        => $siteTitle,
        'site_slogan'       => 'Gündemin nabzı',
        'site_desc'         => $siteTitle . ' — güncel haberler, analizler ve son dakika gelişmeleri.',
        'site_email'        => $email,
        'site_logo'         => '',
        'site_favicon'      => '',
        'theme'             => $theme,
        'posts_per_page'    => '12',
        'headline_limit'    => '6',
        'use_rewrite'       => '0',
        'comments_enabled'  => '1',
        'comments_moderate' => '1',
        'comments_anonymous' => '1',
        'comments_member_auto' => '0',
        'cookie_notice'     => '1',
        'cookie_notice_text' => 'Bu sitede deneyiminizi iyileştirmek için zorunlu çerezler kullanılır.',
        'corrections_enabled' => '1',
        'cache_enabled'     => '1',
        'cache_ttl_home'    => '60',
        'cache_ttl_post'    => '300',
        // AI
        'ai_enabled'        => '0',
        'ai_provider'       => 'anthropic',
        'ai_api_key'        => '',
        'ai_model'          => 'claude-sonnet-5',
        'ai_base_url'       => '',
        'ai_timeout'        => '45',
        'ai_max_concurrent' => '2',
        'ai_price_in'       => '',
        'ai_price_out'      => '',
        'media_max_mb'      => '8',
        // Künye (BİK) — alanlar yayıncı tarafından doldurulur
        'kunye_yayin_adi'          => $siteTitle,
        'kunye_imtiyaz_sahibi'     => '',
        'kunye_sorumlu_mudur'      => '',
        'kunye_yayin_turu'         => 'Yaygın süreli yayın (internet haber sitesi)',
        'kunye_adres'              => '',
        'kunye_telefon'            => '',
        'kunye_eposta'             => $email,
        'kunye_ticaret_unvani'     => '',
        'kunye_vergi_dairesi'      => '',
        'kunye_vergi_no'           => '',
        'kunye_mersis'             => '',
        'kunye_hosting'            => '',
        'kunye_hosting_adres'      => '',
        'kunye_hosting_telefon'    => '',
        'kunye_kep'                => '',
        'kunye_ek_notlar'          => '',
        'bik_eids_kodu'            => '',
        // Widget
        'widget_market_mode'  => 'manual',
        'widget_market_url'   => '',
        'widget_market_data'  => '{"USD":"","EUR":"","GRAM_ALTIN":""}',
        'widget_weather_mode' => 'manual',
        'widget_weather_url'  => '',
        'widget_weather_data' => '{"sehir":"İstanbul","derece":"","durum":""}',
        // AI istem şablonları
        'ai_prompt_rewrite'  => "Aşağıdaki haber metnini Türkçe olarak, özgün cümlelerle yeniden yaz.\nKurallar: tıklama tuzağı başlık kullanma, abartı sıfat kullanma, yorum ekleme, uydurma bilgi ekleme.\nSadece verilen metindeki olguları kullan.\n\nKategori: {kategori}\nÖzgün başlık: {baslik}\nÖzgün metin:\n{icerik}",
        'ai_prompt_title'    => "Aşağıdaki haber için tıklama tuzağı olmayan, olgusal, en fazla 12 kelimelik 5 başlık öner.\n\n{baslik}\n{icerik}",
        'ai_prompt_spot'     => "Aşağıdaki haber için 2 cümlelik, olgusal bir spot (özet) yaz.\n\n{baslik}\n{icerik}",
        'ai_prompt_seo'      => "Aşağıdaki haber için SEO başlığı (en fazla 60 karakter), meta açıklaması (en fazla 155 karakter) ve 5 etiket üret.\n\n{baslik}\n{icerik}",
        // Anasayfa blok dizilimi (tema editörü değiştirir)
        'home_blocks'        => '[{"type":"breaking","on":1},{"type":"headline","on":1},{"type":"category","on":1,"category_id":0},{"type":"most_read","on":1},{"type":"editor_pick","on":1},{"type":"ad","on":1,"slot":"footer"}]',
    ];
}

/** Kurulum sonrası tohum veri: kategoriler + örnek haberler + yönetici. */
function schema_seed($adminName, $adminEmail, $adminPass, $siteTitle = 'Manşet', $theme = 'gazete') {
    $pdo = db();
    schema_install();

    foreach (schema_default_settings($siteTitle, $theme, $adminEmail) as $k => $v) {
        if (qv('SELECT 1 FROM settings WHERE skey = :k', [':k' => $k]) === null) {
            q('INSERT INTO settings (skey, sval) VALUES (:k, :v)', [':k' => $k, ':v' => (string)$v]);
        }
    }
    setting_set('cron_key_hint', 'config.php içindeki cron_key değerini kullanın');
    settings_all(true);

    // Yönetici
    $adminId = (int)qv('SELECT id FROM users WHERE email = :e', [':e' => $adminEmail], 0);
    if (!$adminId) {
        $adminId = db_insert('users', [
            'name'       => $adminName,
            'email'      => $adminEmail,
            'pass_hash'  => password_hash($adminPass, PASSWORD_DEFAULT),
            'role'       => 'admin',
            'active'     => 1,
            'created_at' => now(),
        ]);
    }

    // Kategoriler
    $cats = [
        ['Gündem', '#c0392b', 1],
        ['Ekonomi', '#27632a', 2],
        ['Spor', '#1f4e79', 3],
    ];
    $catIds = [];
    foreach ($cats as $i => $c) {
        $slug = slugify($c[0]);
        $id = (int)qv('SELECT id FROM categories WHERE slug = :s', [':s' => $slug], 0);
        if (!$id) {
            $id = db_insert('categories', [
                'name' => $c[0], 'slug' => $slug, 'sort' => $c[2],
                'color' => $c[1], 'in_menu' => 1, 'active' => 1,
            ]);
        }
        $catIds[] = $id;
    }

    // Örnek haberler
    if ((int)qv('SELECT COUNT(*) FROM posts', [], 0) === 0) {
        $samples = [
            ['Şehir merkezinde yeni ulaşım hattı hizmete girdi', 0, 'Belediye tarafından yürütülen çalışma kapsamında açılan hat, günlük 40 bin yolcuya hizmet verecek.'],
            ['Kış turizminde rezervasyonlar geçen yılı aştı', 1, 'Sektör temsilcileri, erken rezervasyon oranının önceki sezona göre yüzde 18 arttığını bildirdi.'],
            ['Yerel takım deplasmanda 2-1 kazandı', 2, 'Karşılaşmanın golleri ikinci yarıda geldi; ligde çıkış arayan ekip üç puanla döndü.'],
            ['Üniversitede araştırma merkezi açıldı', 0, 'Merkez, tarım teknolojileri alanında lisansüstü projelere ev sahipliği yapacak.'],
            ['Esnaf odasından fiyat etiketi uyarısı', 1, 'Oda başkanı, etiketlerde şeffaflığın tüketici güveni için belirleyici olduğunu vurguladı.'],
            ['Gençlik merkezi yaz kursları başvuruya açıldı', 2, 'Kurslara katılım ücretsiz; başvurular ay sonuna kadar sürecek.'],
        ];
        $t = time();
        foreach ($samples as $i => $s) {
            $title = $s[0];
            $body = '<p>' . esc($s[2]) . '</p>'
                  . '<p>Bu içerik kurulum sırasında örnek olarak oluşturulmuştur. Yönetim panelinden düzenleyebilir veya silebilirsiniz.</p>'
                  . '<p>Manşet, haber üretim akışını RSS toplama, yapay zekâ destekli yeniden yazım ve editör onayı adımlarıyla birleştirir.</p>';
            db_insert('posts', [
                'title'         => $title,
                'slug'          => unique_slug('posts', $title),
                'spot'          => $s[2],
                'body'          => $body,
                'image'         => '',
                'category_id'   => $catIds[$s[1]],
                'author_id'     => $adminId,
                'status'        => 'published',
                'type'          => 'haber',
                'source_name'   => '',
                'source_url'    => '',
                'ai_generated'  => 0,
                'seo_title'     => '',
                'seo_desc'      => excerpt($s[2], 150),
                'tags'          => 'örnek, manşet',
                'view_count'    => 0,
                'is_headline'   => $i < 3 ? 1 : 0,
                'headline_sort' => $i < 3 ? $i + 1 : 0,
                'is_breaking'   => $i === 0 ? 1 : 0,
                'published_at'  => date('Y-m-d H:i:s', $t - ($i * 5400)),
                'created_at'    => date('Y-m-d H:i:s', $t - ($i * 5400)),
                'updated_at'    => date('Y-m-d H:i:s', $t - ($i * 5400)),
            ]);
        }
    }

    // Zorunlu sabit sayfalar
    $defaultPages = [
        ['Hakkımızda', 'hakkimizda', '<p>Yayın kuruluşunuzu bu sayfada tanıtın.</p>'],
        ['İletişim', 'iletisim', '<p>İletişim bilgilerinizi bu sayfada paylaşın.</p>'],
        ['Yayın İlkeleri', 'yayin-ilkeleri', manset_default_editorial_policy()],
        ['Gizlilik ve KVKK', 'gizlilik', '<p>Kişisel verilerin işlenmesine ilişkin aydınlatma metninizi buraya ekleyin.</p>'],
    ];
    foreach ($defaultPages as $p) {
        if (!qv('SELECT 1 FROM pages WHERE slug = :s', [':s' => $p[1]])) {
            db_insert('pages', [
                'title' => $p[0], 'slug' => $p[1], 'body' => $p[2],
                'in_footer' => 1, 'sort' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    migrations_run();
    settings_all(true);
    return $adminId;
}

/** Yayın ilkeleri sayfası için başlangıç şablonu (yayıncı düzenler). */
function manset_default_editorial_policy() {
    return "<h2>Yayın İlkelerimiz</h2>\n"
        . "<p>Bu metin başlangıç şablonudur; yayın kuruluşunuzun ilkelerine göre düzenleyiniz.</p>\n"
        . "<ul>\n"
        . "<li>Haberlerimizde doğruluk, tarafsızlık ve kamu yararı esastır.</li>\n"
        . "<li>Kaynağı doğrulanmamış bilgi yayımlanmaz; alıntı içeriklerde kaynak açıkça belirtilir.</li>\n"
        . "<li>Kişilik haklarına, özel hayatın gizliliğine ve masumiyet karinesine saygı gösterilir.</li>\n"
        . "<li>Çocukların ve suç mağdurlarının kimliği korunur.</li>\n"
        . "<li>Hata yapıldığında düzeltme aynı görünürlükte ve gecikmeksizin yayımlanır.</li>\n"
        . "<li>Yapay zekâ desteğiyle hazırlanan içerikler editör denetiminden geçer ve ilgili haberde belirtilir.</li>\n"
        . "<li>Reklam ve haber içeriği okuyucunun ayırt edebileceği biçimde birbirinden ayrılır.</li>\n"
        . "</ul>\n"
        . "<p>Düzeltme ve cevap talepleriniz için haber sayfalarındaki “Düzeltme talep et” formunu kullanabilirsiniz.</p>";
}
