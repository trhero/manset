<?php
/**
 * Manşet — tam metin arama (1.3-06, Ajan-A).
 *
 * ---------------------------------------------------------------------------
 * NE ÇÖZER
 * ---------------------------------------------------------------------------
 * 1.2'ye kadar arama `search_posts()` içindeki LIKE sorgusuydu ve YALNIZ
 * başlık, spot ve etiket alanlarında arıyordu. Haberin gövdesinde geçen bir
 * ismi arayan okur hiçbir zaman bulamıyordu. Bu modül `search_fts_query()`
 * kancasını doldurur (CONTRACTS 1.3 §2) ve gövdeyi de arar.
 *
 * ---------------------------------------------------------------------------
 * TEMEL KURAL: ARAMA HİÇBİR KURULUMDA BOZULMAZ
 * ---------------------------------------------------------------------------
 * `search_fts_query()` **null** döndürürse `inc/view.php` sessizce eski LIKE
 * sorgusuna düşer. Bu modüldeki her tam metin yolu try/catch ile sarılıdır ve
 * en ufak aksaklıkta null döner:
 *
 *   - SQLite derlemesinde FTS5 yoksa            → null
 *   - MySQL sürümü/motoru FULLTEXT vermiyorsa   → null
 *   - Göç (023) uygulanmamışsa                  → null
 *   - Yayıncı ayarlardan kapattıysa             → null
 *   - Sorgu, MySQL'in en kısa belirteç sınırının altında kalıyorsa → null
 *   - Beklenmedik PDO hatası                    → null (ve error.log'a not)
 *
 * ---------------------------------------------------------------------------
 * NEDEN İKİ SÜRÜCÜDE İKİ FARKLI YOL
 * ---------------------------------------------------------------------------
 * **SQLite (fts5).** Türkçe, unicode61 çözücüsünün doğrudan kullanılmasını
 * engelliyor: çözücü `I` harfini `i` yapar ama `ı` harfine dokunmaz. Ölçüldü —
 * "Işık" metni `isık` olarak dizine girer, okurun yazdığı "ışık" ise `ısık`
 * olur ve KENDİ KELİMESİNİ bulamaz (bkz. gelistirme/1.3-ajan-a.md §Ölçüm).
 * Bu yüzden dizine ham metin değil, `search_fold()` ile Türkçeye göre
 * katlanmış metin yazılır. Katlama hem PHP'de (sorgu tarafı) hem SQL
 * tetikleyicisinde (dizin tarafı) AYNI eşlemeyle yapılır.
 *
 * **MySQL (FULLTEXT).** Dizin doğrudan `posts` tablosunun sütunları üzerinde
 * kurulur; ayrı gölge tablo ve tetikleyici YOKTUR. Böylece dizin tanım gereği
 * hiç bayatlamaz ve paylaşımlı hostingde TRIGGER yetkisi gerekmez. Bedeli,
 * katlamanın sütun harmanlamasına (collation) bırakılmasıdır.
 *
 * ---------------------------------------------------------------------------
 * DİZİN NASIL GÜNCEL KALIYOR (gerekçe)
 * ---------------------------------------------------------------------------
 * Yazma kancası SEÇİLMEDİ: haber yazan tek yer `inc/api/admin.php` değil —
 * RSS otomatik yayını, AI iş kuyruğu, cron'un zamanlanmış yayına alması, çöp
 * kutusu ve panelin toplu işlemleri de `posts` tablosuna yazar. Her birine
 * kanca eklemek, unutulan tek çağrıda sessizce bayat dizin demekti.
 *
 * Bu yüzden SQLite'ta **veritabanı tetikleyicisi** kullanılır: hangi PHP kodu
 * yazarsa yazsın dizin aynı işlemde güncellenir. Çöp kutusu bir UPDATE'tir,
 * tetikleyici onu da yakalar (ayrıca çöptekiler sorgu anında `published_where()`
 * ile zaten elenir). MySQL'de dizin `posts` üzerinde olduğu için sorun yoktur.
 * Cron (`search_cron_tick`) yalnız tetikleyicilerin varlığını doğrular ve
 * etiket normalizasyonunu tamamlar — arama dizini için cron'a İHTİYAÇ YOKTUR.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ---------------------------------------------------------------------------
// Türkçe katlama
// ---------------------------------------------------------------------------

/**
 * Karakter eşlemesi — TEK karakter → TEK karakter (uzunluk korunur).
 *
 * Uzunluğun korunması vurgu (highlight) için zorunludur: eşleşme konumu
 * katlanmış metinde bulunur, kesme işlemi HAM metinde yapılır. Eşleme 1:1
 * olmasaydı iki metnin karakter konumları kayar ve vurgu yanlış yere düşerdi.
 */
function search_fold_map() {
    return [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i',
        'Ş' => 's', 'ş' => 's',
        'Ğ' => 'g', 'ğ' => 'g',
        'Ç' => 'c', 'ç' => 'c',
        'Ö' => 'o', 'ö' => 'o',
        'Ü' => 'u', 'ü' => 'u',
        'Â' => 'a', 'â' => 'a',
        'Î' => 'i', 'î' => 'i',
        'Û' => 'u', 'û' => 'u',
        'É' => 'e', 'é' => 'e',
    ];
}

/**
 * Arama karşılaştırması için metni katlar: Türkçe harfler ASCII karşılığına,
 * sonra ASCII büyük harfler küçüğe.
 *
 * mb_strtolower KULLANILMAZ: mb_strtolower('İ') iki karakter üretir
 * (i + birleşik nokta) ve 1:1 eşleme bozulurdu. Ayrıca SQLite'ın `lower()`
 * işlevi yalnız ASCII katlar; PHP tarafı SQL tarafıyla birebir aynı davranmalı,
 * yoksa dizindeki biçimle sorgudaki biçim ayrışır.
 */
function search_fold($text) {
    $text = strtr((string)$text, search_fold_map());
    return strtr($text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
}

/**
 * SQLite tarafında aynı katlamayı yapan SQL ifadesi (tetikleyici ve yeniden
 * kurma için). İç içe replace() zinciri + lower().
 */
function search_sqlite_fold_expr($col) {
    $expr = $col;
    foreach (search_fold_map() as $from => $to) {
        $expr = 'replace(' . $expr . ', \'' . $from . '\', \'' . $to . '\')';
    }
    return 'lower(' . $expr . ')';
}

/**
 * Kullanıcı girdisini güvenli belirteçlere ayırır.
 *
 * GÜVENLİK (1.3 denetim konusu): FTS5 kendi sorgu dilini yorumlar — `"` dize
 * ayracı, `*` önek eki, `-` dışlama, `NEAR/3` işleç, `OR`/`AND` bağlaç. Ham
 * girdi doğrudan MATCH'e verilseydi `" OR x` sözdizimi hatası fırlatır (arama
 * 500 verir), `*` tek başına tüm dizini tarar. Aynısı MySQL boolean kipi için
 * `+ - > < ( ) ~ * " @` karakterleriyle geçerlidir.
 *
 * Çözüm kaçış DEĞİL, BEYAZ LİSTE: yalnız harf ve rakam dizileri belirteç
 * sayılır, geri kalan her şey ayraç olarak atılır. Böylece hiçbir işleç
 * karakteri sorguya ulaşamaz; emoji, tırnak ve `NEAR/` sessizce düşer.
 * En çok 8 belirteç, her biri en çok 32 karakter: çok uzun dizeyle dizin
 * taratma denemesi burada durur.
 */
function search_tokens($term, $max = 8) {
    $term = search_fold((string)$term);
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) { return []; }
    $out = [];
    foreach ($parts as $p) {
        if (mb_strlen($p) > 32) { $p = mb_substr($p, 0, 32); }
        if ($p === '') { continue; }
        $out[$p] = true;
        if (count($out) >= $max) { break; }
    }
    // strval: "2026" gibi sayısal bir belirteç PHP dizi anahtarı olurken
    // TAMSAYIYA döner; belirteçler dizeye zorlanmazsa aşağıdaki mb_* ve
    // birleştirme işlemleri tip sürprizleriyle karşılaşır.
    return array_map('strval', array_keys($out));
}

// ---------------------------------------------------------------------------
// Motor seçimi
// ---------------------------------------------------------------------------

/** Yayıncı aramayı ayarlardan kapatmış mı? */
function search_fts_enabled() {
    return setting('search_fts_enabled', '1') === '1';
}

/**
 * Bu kurulumda hangi tam metin motoru KULLANILABİLİR: '' | 'fts5' | 'fulltext'.
 * Sonuç istek boyunca saklanır; her arama için şema sorgulanmaz.
 */
function search_engine() {
    if (isset($GLOBALS['manset_search_engine'])) { return $GLOBALS['manset_search_engine']; }
    $engine = '';
    if (search_fts_enabled()) {
        try {
            if (db_driver() === 'sqlite') {
                if (schema_has_table('search_index')) { $engine = 'fts5'; }
            } elseif (search_mysql_fulltext_ready()) {
                $engine = 'fulltext';
            }
        } catch (Throwable $e) {
            $engine = '';
        }
    }
    $GLOBALS['manset_search_engine'] = $engine;
    return $engine;
}

/**
 * Motor önbelleğini sıfırlar. Ayar panelden değiştirildiğinde ya da göç
 * uygulandığında çağrılır; birim testler de bunu kullanır. (Önbellek işlev içi
 * `static` yerine global tutulur — `static` istek boyunca sıfırlanamazdı ve
 * "ayarı kapattım ama hâlâ FTS kullanıyor" gibi ölçülemez bir durum doğardı.)
 */
function search_engine_reset() {
    $GLOBALS['manset_search_engine'] = null;
    unset($GLOBALS['manset_search_engine']);
}

/** MySQL'de posts üzerinde FULLTEXT dizin var mı? */
function search_mysql_fulltext_ready() {
    try {
        return (bool)qv('SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'posts\'
              AND INDEX_NAME = \'idx_posts_fulltext\'', [], 0);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * MySQL'in en kısa dizinlenen sözcük uzunluğu.
 *
 * ÖLÇÜLMESİ GEREKEN NOKTA: InnoDB varsayılanı 3, MyISAM varsayılanı 4'tür.
 * Yani "su", "ev", "AB", "TL" gibi Türkçede sık geçen kısa sözcükler FULLTEXT
 * dizinine HİÇ girmez ve arama boş döner. Bu sessiz bir kayıptır; bu yüzden
 * sorgudaki en kısa belirteç sınırın altındaysa tam metin yolu KULLANILMAZ,
 * çekirdeğin LIKE sorgusuna düşülür — LIKE kısa sözcüğü bulur.
 */
function search_mysql_min_token() {
    static $min = null;
    if ($min !== null) { return $min; }
    $min = 3;
    try {
        $v = qv('SELECT @@innodb_ft_min_token_size', [], 0);
        if ($v !== null && (int)$v > 0) { $min = (int)$v; }
        $w = qv('SELECT @@ft_min_word_len', [], 0);
        if ($w !== null && (int)$w > $min) { $min = (int)$w; }
    } catch (Throwable $e) {
        $min = 3;
    }
    return $min;
}

// ---------------------------------------------------------------------------
// Dizin kurulumu (göç ve yeniden kurma ortak kullanır)
// ---------------------------------------------------------------------------

/**
 * Dizini (ve SQLite'ta tetikleyicileri) kurar. Dönen değer: kullanılabilir
 * motor adı ya da '' (bu kurulumda tam metin yok).
 *
 * ÇALIŞTIRARAK SINAR: FTS5 derlemede olmayabilir, bu yüzden "sürüm şu, demek
 * ki vardır" varsayımı yapılmaz — CREATE VIRTUAL TABLE denenir.
 */
function search_index_ensure() {
    $driver = db_driver();
    $pdo = db();

    if ($driver === 'sqlite') {
        try {
            $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS search_index
                USING fts5(title, spot, body, tags, tokenize = \'unicode61 remove_diacritics 2\')');
        } catch (Throwable $e) {
            // FTS5 derlemede yok — kurulum LIKE ile çalışmaya devam eder.
            log_error('FTS5 kullanılamıyor, arama LIKE ile çalışacak: ' . $e->getMessage());
            return '';
        }
        search_sqlite_triggers();
        return 'fts5';
    }

    // MySQL: dizin doğrudan posts üzerinde.
    try {
        if (!search_mysql_fulltext_ready()) {
            $pdo->exec('ALTER TABLE posts ADD FULLTEXT INDEX idx_posts_fulltext (title, spot, body, tags)');
        }
        return search_mysql_fulltext_ready() ? 'fulltext' : '';
    } catch (Throwable $e) {
        log_error('FULLTEXT dizini kurulamadı, arama LIKE ile çalışacak: ' . $e->getMessage());
        return '';
    }
}

/** SQLite tetikleyicileri (posts → search_index). Varsa yeniden kurulur. */
function search_sqlite_triggers() {
    if (db_driver() !== 'sqlite') { return false; }
    $pdo = db();
    $deger = 'new.id, ' . search_sqlite_fold_expr('new.title') . ', '
           . search_sqlite_fold_expr('new.spot') . ', '
           . search_sqlite_fold_expr('new.body') . ', '
           . search_sqlite_fold_expr('new.tags');
    $ekle = 'INSERT INTO search_index(rowid, title, spot, body, tags) VALUES (' . $deger . ');';
    try {
        foreach (['search_index_ai', 'search_index_au', 'search_index_ad'] as $t) {
            $pdo->exec('DROP TRIGGER IF EXISTS ' . $t);
        }
        $pdo->exec('CREATE TRIGGER search_index_ai AFTER INSERT ON posts BEGIN ' . $ekle . ' END');
        $pdo->exec('CREATE TRIGGER search_index_ad AFTER DELETE ON posts BEGIN
            DELETE FROM search_index WHERE rowid = old.id; END');
        $pdo->exec('CREATE TRIGGER search_index_au AFTER UPDATE ON posts BEGIN
            DELETE FROM search_index WHERE rowid = old.id; ' . $ekle . ' END');
        return true;
    } catch (Throwable $e) {
        log_error('Arama tetikleyicileri kurulamadı: ' . $e->getMessage());
        return false;
    }
}

/** SQLite tetikleyicileri yerinde mi? (cron doğrulaması) */
function search_sqlite_triggers_ok() {
    if (db_driver() !== 'sqlite') { return true; }
    try {
        $n = (int)qv('SELECT COUNT(*) FROM sqlite_master WHERE type = \'trigger\'
            AND name IN (\'search_index_ai\', \'search_index_au\', \'search_index_ad\')', [], 0);
        return $n === 3;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Dizini sıfırdan kurar (SQLite). MySQL'de dizin posts üzerinde olduğu için
 * yapılacak bir şey yoktur.
 * Dönen: dizinlenen satır sayısı.
 */
function search_index_rebuild() {
    if (db_driver() !== 'sqlite') { return (int)qv('SELECT COUNT(*) FROM posts', [], 0); }
    if (search_index_ensure() !== 'fts5') { return 0; }
    try {
        db()->exec('DELETE FROM search_index');
        db()->exec('INSERT INTO search_index(rowid, title, spot, body, tags)
            SELECT id, ' . search_sqlite_fold_expr('title') . ', ' . search_sqlite_fold_expr('spot') . ', '
            . search_sqlite_fold_expr('body') . ', ' . search_sqlite_fold_expr('tags') . ' FROM posts');
        return (int)qv('SELECT COUNT(*) FROM search_index', [], 0);
    } catch (Throwable $e) {
        log_error('Arama dizini kurulamadı: ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Sorgu
// ---------------------------------------------------------------------------

/** view.php yüklü değilse (cron/panel) aynı yayın koşulunu üretir. */
function search_published_where($alias = 'p') {
    if (function_exists('published_where')) { return published_where($alias); }
    $silme = schema_has_column('posts', 'deleted_at') ? ' AND ' . $alias . '.deleted_at = \'\'' : '';
    return $alias . '.status = \'published\' AND (' . $alias . '.published_at IS NULL OR '
         . $alias . '.published_at <= :nowts)' . $silme;
}

/** Liste sorgusunun SELECT sütunları (view.php yoksa yedeği). */
function search_select_columns() {
    if (function_exists('post_select_columns')) { return post_select_columns('p'); }
    return 'p.id, p.title, p.slug, p.spot, p.image, p.category_id, p.author_id, p.type, p.tags,
            p.view_count, p.is_breaking, p.is_headline, p.headline_sort, p.ai_generated,
            p.source_name, p.published_at, p.visibility';
}

/**
 * Süzgeçleri istekten okur: `?kategori=<slug|id>&baslangic=YYYY-MM-DD&bitis=YYYY-MM-DD`.
 *
 * NEDEN İSTEKTEN: `search_posts()` imzası CONTRACTS §5.3'te donmuştur
 * ($term, $page, $perPage) ve orkestratörün dosyası olduğu için genişletilemez.
 * Süzgeçler bu yüzden sorgu dizesinden okunur; JSON ucu (`search.query`) ise
 * aynı değerleri açık parametre olarak alır.
 */
function search_request_filters() {
    $f = ['category' => 0, 'from' => '', 'to' => ''];
    $kat = isset($_GET['kategori']) ? trim((string)$_GET['kategori']) : '';
    if ($kat !== '') { $f['category'] = search_category_id($kat); }
    $f['from'] = search_clean_date(isset($_GET['baslangic']) ? $_GET['baslangic'] : '');
    $f['to']   = search_clean_date(isset($_GET['bitis']) ? $_GET['bitis'] : '');
    return $f;
}

/** Kategori slug'ı ya da kimliği → kimlik (bulunamazsa 0). */
function search_category_id($katman) {
    $katman = trim((string)$katman);
    if ($katman === '') { return 0; }
    if (ctype_digit($katman)) { return (int)$katman; }
    try {
        return (int)qv('SELECT id FROM categories WHERE slug = :s', [':s' => $katman], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** 'YYYY-MM-DD' değilse boş dizeye indirger. */
function search_clean_date($d) {
    $d = trim((string)$d);
    if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { return ''; }
    return $d;
}

/** Süzgeç koşullarını ve parametrelerini üretir. */
function search_filter_sql($filters, &$params) {
    $sql = '';
    if (!empty($filters['category'])) {
        $sql .= ' AND p.category_id = :fcat';
        $params[':fcat'] = (int)$filters['category'];
    }
    if (!empty($filters['from'])) {
        $sql .= ' AND p.published_at >= :ffrom';
        $params[':ffrom'] = $filters['from'] . ' 00:00:00';
    }
    if (!empty($filters['to'])) {
        $sql .= ' AND p.published_at <= :fto';
        $params[':fto'] = $filters['to'] . ' 23:59:59';
    }
    return $sql;
}

/**
 * `search_posts()` kancası (CONTRACTS 1.3 §2).
 *
 * null → çekirdek LIKE sorgusunu çalıştırır (davranış 1.2 ile birebir aynı).
 * dizi → ['items' => [...], 'total' => n]
 */
function search_fts_query($term, $page = 1, $perPage = 12) {
    $filters = search_request_filters();
    $hasFilter = !empty($filters['category']) || $filters['from'] !== '' || $filters['to'] !== '';
    $engine = search_engine();

    // Tam metin yok ve süzgeç de istenmemiş → çekirdek kendi işini görsün.
    if ($engine === '' && !$hasFilter) { return null; }

    $res = search_query($term, [
        'page' => $page, 'per_page' => $perPage,
        'category' => $filters['category'], 'from' => $filters['from'], 'to' => $filters['to'],
    ]);
    return $res === null ? null : $res;
}

/**
 * Asıl arama. $opts: page, per_page, category, from, to, highlight(bool|null).
 * Dönen: ['items'=>[], 'total'=>int, 'engine'=>'fts5|fulltext|like'] ya da
 * null (yalnız kanca yolunda anlamlıdır: çağıran LIKE'a düşsün).
 */
function search_query($term, $opts = []) {
    $term = trim((string)$term);
    $page = max(1, (int)arr($opts, 'page', 1));
    $perPage = max(1, min(50, (int)arr($opts, 'per_page', 12)));
    $offset = ($page - 1) * $perPage;
    $filters = [
        'category' => (int)arr($opts, 'category', 0),
        'from' => search_clean_date(arr($opts, 'from', '')),
        'to' => search_clean_date(arr($opts, 'to', '')),
    ];
    $tokens = search_tokens($term);
    if (mb_strlen($term) < 2 || !$tokens) {
        return ['items' => [], 'total' => 0, 'engine' => 'like'];
    }

    $engine = search_engine();
    $res = null;
    if ($engine === 'fts5') {
        $res = search_query_fts5($tokens, $filters, $perPage, $offset);
    } elseif ($engine === 'fulltext') {
        $res = search_query_fulltext($tokens, $filters, $perPage, $offset);
    }
    if ($res === null) {
        $res = search_query_like($term, $filters, $perPage, $offset);
        $res['engine'] = 'like';
    }

    $vurgu = array_key_exists('highlight', $opts)
        ? (bool)$opts['highlight'] : (setting('search_highlight', '1') === '1');
    if ($vurgu) { $res['items'] = search_decorate_items($res['items'], $tokens); }
    return $res;
}

/** SQLite FTS5 yolu. Hata olursa null (çağıran LIKE'a düşer). */
function search_query_fts5($tokens, $filters, $perPage, $offset) {
    // Her belirteç FTS5 dize sabiti olarak tırnaklanır — içindeki her şey
    // düz metindir, işleç olarak yorumlanmaz. Belirteçler zaten yalnız harf
    // ve rakam içerdiği için tırnak kaçışına gerek kalmaz; yine de olası bir
    // tırnak FTS5 kuralınca iki tırnağa çevrilir.
    $parts = [];
    foreach ($tokens as $t) { $parts[] = '"' . str_replace('"', '""', $t) . '"'; }
    $match = implode(' AND ', $parts);

    $params = [':nowts' => now(), ':m' => $match];
    // FTS5 tablosuna TAKMA AD VERİLMEZ: `si MATCH :m` yazımı SQLite'ta
    // "no such column: si" hatası verir (ölçüldü) — MATCH işlecinin solunda
    // tablonun KENDİ adı bulunmalıdır. Hata sessizce LIKE'a düşürdüğü için
    // fark edilmesi zordu; birim test bu sorguyu artık doğrudan sınıyor.
    $where = 'search_index MATCH :m AND ' . search_published_where('p')
           . search_filter_sql($filters, $params);
    try {
        $total = (int)qv('SELECT COUNT(*) FROM search_index
            JOIN posts p ON p.id = search_index.rowid WHERE ' . $where, $params, 0);
        $items = qa('SELECT ' . search_select_columns() . ', c.name AS category_name,
                c.slug AS category_slug, c.color AS category_color
            FROM search_index
            JOIN posts p ON p.id = search_index.rowid
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE ' . $where . '
            ORDER BY bm25(search_index, 10.0, 5.0, 1.0, 4.0) ASC, p.published_at DESC
            LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset, $params);
        return ['items' => $items, 'total' => $total, 'engine' => 'fts5'];
    } catch (Throwable $e) {
        log_error('FTS5 sorgusu başarısız, LIKE\'a düşülüyor: ' . $e->getMessage());
        return null;
    }
}

/** MySQL FULLTEXT yolu. Hata ya da çok kısa belirteç olursa null. */
function search_query_fulltext($tokens, $filters, $perPage, $offset) {
    $min = search_mysql_min_token();
    foreach ($tokens as $t) {
        // Kısa sözcük dizine hiç girmemiştir; LIKE onu bulur, FULLTEXT bulamaz.
        if (mb_strlen($t) < $min) { return null; }
    }
    $bool = '';
    foreach ($tokens as $t) { $bool .= '+' . $t . ' '; }
    $bool = trim($bool);

    $params = [':nowts' => now(), ':m1' => $bool, ':m2' => $bool];
    $expr = 'MATCH (p.title, p.spot, p.body, p.tags) AGAINST (:m1 IN BOOLEAN MODE)';
    $exprSira = 'MATCH (p.title, p.spot, p.body, p.tags) AGAINST (:m2 IN BOOLEAN MODE)';
    $where = $expr . ' AND ' . search_published_where('p') . search_filter_sql($filters, $params);
    try {
        $total = (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . $where, $params, 0);
        $items = qa('SELECT ' . search_select_columns() . ', c.name AS category_name,
                c.slug AS category_slug, c.color AS category_color
            FROM posts p LEFT JOIN categories c ON c.id = p.category_id
            WHERE ' . $where . '
            ORDER BY ' . $exprSira . ' DESC, p.published_at DESC
            LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset, $params);
        return ['items' => $items, 'total' => $total, 'engine' => 'fulltext'];
    } catch (Throwable $e) {
        log_error('FULLTEXT sorgusu başarısız, LIKE\'a düşülüyor: ' . $e->getMessage());
        return null;
    }
}

/**
 * Süzgeçli LIKE yedeği — alan kümesi çekirdekle AYNI tutulur (başlık, spot,
 * etiket). Gövde bilerek dışarıda: FTS kapalıyken her aramada tüm gövdeleri
 * taramak paylaşımlı hostingde sayfayı dakikalarca askıya alabilir; "arama
 * yavaşladı" hatası "gövdede bulamadı"dan çok daha pahalıdır.
 */
function search_query_like($term, $filters, $perPage, $offset) {
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
    $params = [':nowts' => now(), ':t' => $like];
    $where = search_published_where('p') . ' AND (p.title LIKE :t OR p.spot LIKE :t OR p.tags LIKE :t)'
           . search_filter_sql($filters, $params);
    try {
        $total = (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . $where, $params, 0);
        $items = qa('SELECT ' . search_select_columns() . ', c.name AS category_name,
                c.slug AS category_slug, c.color AS category_color
            FROM posts p LEFT JOIN categories c ON c.id = p.category_id
            WHERE ' . $where . ' ORDER BY p.published_at DESC
            LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset, $params);
        return ['items' => $items, 'total' => $total];
    } catch (Throwable $e) {
        log_error('Arama sorgusu başarısız: ' . $e->getMessage());
        return ['items' => [], 'total' => 0];
    }
}

// ---------------------------------------------------------------------------
// Vurgu (highlight)
// ---------------------------------------------------------------------------

/**
 * Eşleşen sözcükleri <mark> ile işaretler ve GÜVENLİ HTML döndürür.
 *
 * GÜVENLİK: bu işlev HTML üretir, yani XSS'in doğal yeridir. Kural şudur —
 * çıktıya giren HER metin parçası esc()'ten geçer; eklenen tek ham şey
 * <mark> ve </mark> etiketleridir. Kullanıcının yazdığı arama ifadesi çıktıya
 * HİÇ konmaz: yalnız METİN İÇİNDEKİ eşleşen parça basılır. Böylece
 * "<script>" araması, sonuçta yalnız kaçırılmış metin üretir.
 * Girdi metni ayrıca strip_tags()'ten geçer (gövde HTML'i olabilir).
 */
function search_highlight_html($text, $tokens, $maxLen = 220) {
    $text = (string)$text;
    $text = strip_tags(str_replace(['<', '>'], [' <', '> '], $text));
    $text = trim((string)preg_replace('/\s+/u', ' ', $text));
    if ($text === '') { return ''; }
    $tokens = (array)$tokens;

    $folded = search_fold($text);
    $len = mb_strlen($text);

    // İlk eşleşmeyi bul (pencerenin merkezi).
    $ilk = -1;
    foreach ($tokens as $t) {
        if ($t === '') { continue; }
        $p = mb_stripos($folded, $t);
        if ($p !== false && ($ilk < 0 || $p < $ilk)) { $ilk = $p; }
    }

    $bas = 0;
    if ($ilk > 60) { $bas = $ilk - 60; }
    if ($bas + $maxLen > $len) { $bas = max(0, $len - $maxLen); }
    $parcaLen = min($maxLen, $len - $bas);
    $parca = mb_substr($text, $bas, $parcaLen);
    $parcaFold = mb_substr($folded, $bas, $parcaLen);

    // Eşleşme aralıklarını topla.
    $araliklar = [];
    foreach ($tokens as $t) {
        if ($t === '') { continue; }
        $tl = mb_strlen($t);
        $off = 0;
        while (($p = mb_stripos($parcaFold, $t, $off)) !== false) {
            $araliklar[] = [$p, $p + $tl];
            $off = $p + $tl;
            if (count($araliklar) > 40) { break 2; }
        }
    }
    usort($araliklar, function ($a, $b) { return $a[0] <=> $b[0]; });

    $out = '';
    $imlec = 0;
    foreach ($araliklar as $ar) {
        if ($ar[0] < $imlec) { continue; }              // çakışan eşleşmeyi atla
        $out .= esc(mb_substr($parca, $imlec, $ar[0] - $imlec));
        $out .= '<mark>' . esc(mb_substr($parca, $ar[0], $ar[1] - $ar[0])) . '</mark>';
        $imlec = $ar[1];
    }
    $out .= esc(mb_substr($parca, $imlec));

    if ($bas > 0) { $out = '…' . $out; }
    if ($bas + $parcaLen < $len) { $out .= '…'; }
    return $out;
}

/**
 * Sonuç satırlarına vurgulu alanlar ekler.
 *
 * Eklenen anahtarlar mevcut kart şablonlarınca YOK SAYILIR (hepsi esc() ile
 * kendi alanlarını basar) — yani bu ekleme hiçbir temayı bozmaz ve hiçbir
 * temada ham HTML sızdırmaz. Vurguyu basmak isteyen şablon
 * `search_title_html` / `search_excerpt_html` anahtarlarını kullanır.
 */
function search_decorate_items($items, $tokens) {
    if (!$items) { return $items; }
    $ids = [];
    foreach ($items as $r) { $ids[] = (int)$r['id']; }

    // ODEME DUVARI KAPISI (denetim turu 5, D2-01 - KRITIK).
    //
    // Bu islev govdeden alinti cikariyor ve `search.query` ucu o alintiyi ANONIME
    // donduruyordu. Hicbir gorunurluk denetimi yoktu: kilitli bir haberin govdesi,
    // arama terimi kaydirilarak yuruye yuruye tamamen cikarilabiliyordu.
    // Bu, tur 2'de beslemelerde kapatilan sizinti sinifinin YENI BIR UCTA tekrari;
    // tur 4 aramayi temiz olcmustu, cunku govde alintisi 1.3'te eklendi.
    //
    // `post_can_read_full()` KULLANILIR, `post_reader_can_read_full()` DEGIL:
    // ikincisi olculu duvar sayacini isletir ve YAN ETKILIDIR - arama sonucu
    // listelemek okurun aylik ucretsiz haber hakkini YAKMAMALIDIR.
    //
    // Kullanici `current_user_if_session()` ile okunur: oturum ACMAZ, yani
    // anonim ziyaretci cerez almaz (CONTRACTS 3.1).
    $okur = function_exists('current_user_if_session') ? current_user_if_session() : null;
    $visVar = schema_has_column('posts', 'visibility');

    $govde = [];
    if ($ids) {
        try {
            $ek = $visVar ? ', visibility' : '';
            foreach (qa('SELECT id, body' . $ek . ' FROM posts WHERE id IN (' . implode(',', $ids) . ')') as $b) {
                $satir = ['id' => (int)$b['id'], 'visibility' => (string)arr($b, 'visibility', 'public')];
                // Kilitliyse govde HIC ALINMAZ - bellege bile konmaz.
                if (function_exists('post_can_read_full') && !post_can_read_full($satir, $okur)) { continue; }
                $govde[(int)$b['id']] = (string)$b['body'];
            }
        } catch (Throwable $e) { $govde = []; }
    }

    foreach ($items as $i => $r) {
        $id = (int)$r['id'];
        $items[$i]['search_title_html'] = search_highlight_html($r['title'], $tokens, 160);
        $kaynak = (string)arr($r, 'spot', '');
        // Eslesme spotta yoksa govdeden parca cikarilir: okur, aradigi sozcugun
        // haberin NERESINDE gectigini gormeli.
        // Kilitli haberde `$govde[$id]` hic dolmadigi icin spot/teaser'da kalinir.
        if (!search_text_matches($kaynak, $tokens) && isset($govde[$id])) { $kaynak = $govde[$id]; }
        $items[$i]['search_excerpt_html'] = search_highlight_html($kaynak, $tokens, 220);
    }
    return $items;
}

/** Metinde belirteçlerden herhangi biri geçiyor mu? */
function search_text_matches($text, $tokens) {
    $f = search_fold(strip_tags((string)$text));
    foreach ((array)$tokens as $t) {
        if ($t !== '' && mb_stripos($f, $t) !== false) { return true; }
    }
    return false;
}

// ---------------------------------------------------------------------------
// Etiket normalizasyonu
// ---------------------------------------------------------------------------

/**
 * `posts.tags` serbest metni → `tags` + `post_tags`.
 *
 * NEDEN posts.tags SİLİNMİYOR: `tag_posts()` ve `tag_posts_count()`
 * (inc/view.php — orkestratörün dosyası) hâlâ `posts.tags LIKE` ile çalışır ve
 * 1.3'te değişmez. Göç uygulanmamış kurulumda ön yüz aynen çalışmaya devam
 * etmeli. Bu yüzden normalize tablolar TÜRETİLMİŞ veridir: yazma yönü tek
 * yönlüdür (posts.tags → post_tags), tersi asla.
 */
function search_tags_ready() {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $ok = schema_has_table('tags') && schema_has_table('post_tags');
    return $ok;
}

/** Tek haberin etiket satırlarını tazeler. Dönen: yazılan etiket sayısı. */
function search_tags_sync_post($postId) {
    $postId = (int)$postId;
    if ($postId <= 0 || !search_tags_ready()) { return 0; }
    try {
        $row = q1('SELECT id, tags FROM posts WHERE id = :i', [':i' => $postId]);
        if (!$row) {
            q('DELETE FROM post_tags WHERE post_id = :i', [':i' => $postId]);
            return 0;
        }
        $adlar = function_exists('tags_to_array') ? tags_to_array($row['tags']) : [];
        $ids = [];
        foreach ($adlar as $ad) {
            $ad = trim((string)$ad);
            if ($ad === '') { continue; }
            $slug = slugify($ad, 190);
            if ($slug === '') { continue; }
            $tagId = (int)qv('SELECT id FROM tags WHERE slug = :s', [':s' => $slug], 0);
            if ($tagId <= 0) {
                $tagId = db_insert('tags', ['name' => mb_substr($ad, 0, 190), 'slug' => $slug,
                    'post_count' => 0, 'created_at' => now()]);
            }
            if ($tagId > 0) { $ids[$tagId] = true; }
        }
        q('DELETE FROM post_tags WHERE post_id = :i', [':i' => $postId]);
        foreach (array_keys($ids) as $tagId) {
            q('INSERT INTO post_tags (post_id, tag_id) VALUES (:p, :t)', [':p' => $postId, ':t' => $tagId]);
        }
        return count($ids);
    } catch (Throwable $e) {
        log_error('Etiket eşitlenemedi (haber ' . $postId . '): ' . $e->getMessage());
        return 0;
    }
}

/** Etiket sayaçlarını yeniden hesaplar (yayında olan haberler). */
function search_tags_recount() {
    if (!search_tags_ready()) { return 0; }
    try {
        q('UPDATE tags SET post_count = (SELECT COUNT(*) FROM post_tags pt WHERE pt.tag_id = tags.id)');
        return (int)qv('SELECT COUNT(*) FROM tags', [], 0);
    } catch (Throwable $e) {
        log_error('Etiket sayacı güncellenemedi: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Tüm haberleri (ya da en çok $limit tanesini) eşitler.
 * $since verilirse yalnız o zamandan sonra güncellenenler.
 */
function search_tags_sync_all($limit = 0, $since = '') {
    if (!search_tags_ready()) { return 0; }
    try {
        $sql = 'SELECT id FROM posts';
        $params = [];
        if ($since !== '') {
            $sql .= ' WHERE (updated_at > :s OR created_at > :s2)';
            $params[':s'] = $since;
            $params[':s2'] = $since;
        }
        $sql .= ' ORDER BY id ASC';
        if ($limit > 0) { $sql .= ' LIMIT ' . (int)$limit; }
        $n = 0;
        foreach (qa($sql, $params) as $r) { search_tags_sync_post((int)$r['id']); $n++; }
        // Silinen haberlerin artık satırları
        q('DELETE FROM post_tags WHERE post_id NOT IN (SELECT id FROM posts)');
        search_tags_recount();
        return $n;
    } catch (Throwable $e) {
        log_error('Etiketler toplu eşitlenemedi: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Etiket bulutu için sayılı liste (Ajan-B'nin `discover_tagcloud_view()`
 * kancası bunu kullanabilir; göç uygulanmamışsa boş dizi döner).
 */
function search_tag_cloud($limit = 100) {
    if (!search_tags_ready()) { return []; }
    try {
        return qa('SELECT t.id, t.name, t.slug, COUNT(pt.post_id) AS post_count
            FROM tags t
            JOIN post_tags pt ON pt.tag_id = t.id
            JOIN posts p ON p.id = pt.post_id
            WHERE ' . search_published_where('p') . '
            GROUP BY t.id, t.name, t.slug
            ORDER BY post_count DESC, t.name ASC
            LIMIT ' . max(1, min(500, (int)$limit)), [':nowts' => now()]);
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Cron
// ---------------------------------------------------------------------------

/**
 * Ucuz bakım turu (ağa çıkmaz):
 *   1. SQLite tetikleyicileri duruyor mu (yedekten geri yükleme sonrası düşmüş
 *      olabilir) — düştüyse yeniden kurar ve dizini tazeler.
 *   2. Etiket normalizasyonunu su işaretinden ilerletir.
 *
 * Arama DİZİNİ için cron'a ihtiyaç yoktur; tetikleyici işi anında yapar.
 * Etiket tarafında tetikleyici KULLANILAMAZ: virgüllü metni ayırmak SQL
 * tetikleyicisinin yapabileceği bir iş değil. Bu yüzden etiket tablosu su
 * işaretiyle ilerler ve birkaç dakika gecikebilir — etiket bulutu için bu
 * gecikme kabul edilebilir, arama için olmazdı.
 */
function search_cron_tick() {
    $mesaj = [];
    if (db_driver() === 'sqlite' && schema_has_table('search_index') && !search_sqlite_triggers_ok()) {
        search_sqlite_triggers();
        $n = search_index_rebuild();
        $mesaj[] = 'dizin yeniden kuruldu (' . $n . ')';
    }
    if (search_tags_ready()) {
        $su = setting('search_tags_synced_at', '');
        $n = search_tags_sync_all(200, $su);
        setting_set('search_tags_synced_at', now());

        // SU İŞARETİ TEK BAŞINA YETMEZ (ölçüldü): göçten SONRA toplu tohum/içe
        // aktarımla eklenen haberlerin `updated_at` değeri su işaretinden ESKİ
        // olabilir ve o haberler hiç eşitlenmez. Bu yüzden "etiketi var ama
        // post_tags satırı yok" durumundakiler ayrıca toplanır.
        $eksik = 0;
        foreach (qa('SELECT id FROM posts WHERE tags <> \'\'
                     AND NOT EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = posts.id)
                     ORDER BY id DESC LIMIT 200') as $r) {
            search_tags_sync_post((int)$r['id']);
            $eksik++;
        }
        if ($eksik > 0) { search_tags_recount(); }
        $n += $eksik;
        if ($n > 0) { $mesaj[] = $n . ' haberin etiketi eşitlendi'; }
    }
    return $mesaj ? implode(', ', $mesaj) : 'değişiklik yok';
}

cron_register('arama', 'search_cron_tick', true);
