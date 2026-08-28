<?php
/**
 * Manşet — vitrin uçları: manşet sırası, son dakika, editörün seçimi, reklam alanları.
 * Sahip: Ajan-3.  Ad alanı (CONTRACTS §7): headlines.*  ads.*
 *
 * Bu dosya iki bağlamda yüklenir:
 *   1) api.php  →  glob('inc/api/*.php') ile; aşağıdaki api_register() çağrıları uçları kaydeder.
 *   2) admin/pages/{headlines,ads}.php  →  require_once ile; orada api_register() tanımlı
 *      olmadığından kayıt bloğu atlanır, yalnız ortak yardımcı fonksiyonlar kullanılır.
 *      Böylece slot sözlüğü ve sınır değerleri tek yerde durur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ sabitler

/** Manşette en çok kaç haber bulunabilir. */
function vitrin_headline_max() { return 12; }

/** Editörün seçiminde en çok kaç haber bulunabilir. */
function vitrin_picks_max() { return 12; }

/** Reklam kodu uzunluk sınırı (karakter). */
function vitrin_ad_html_max() { return 20000; }

/** Arama sonuçlarında sayfa başına kayıt. */
function vitrin_per_page() { return 10; }

/**
 * Reklam alanları — CONTRACTS §2'deki sabit liste.
 * anahtar => [kısa ad, panelde gösterilen açıklama]
 */
function vitrin_ad_slots() {
    return [
        'header'     => ['Üst banner', 'İçerik alanının en üstünde, menünün hemen altında basılır. Genelde geniş banner (ör. 970×90 / 728×90) için kullanılır.'],
        'sidebar_1'  => ['Kenar çubuğu — üst', 'Kenar çubuğunun ilk sırası. En çok görüntülenen alandır; dikdörtgen (ör. 300×250) reklamlar buraya uygundur.'],
        'sidebar_2'  => ['Kenar çubuğu — alt', 'Kenar çubuğunun alt sırası. Sayfa aşağı kaydırıldıkça görünür; gökdelen (ör. 300×600) reklamlar için uygundur.'],
        'in_article' => ['Haber içi', 'Haber gövdesinin hemen altında basılır. Anasayfada “Reklam Alanı” bloğunun varsayılan alanıdır.'],
        'footer'     => ['Alt bilgi', 'Sayfanın en altında, alt bilgi bloğunun içinde basılır.'],
    ];
}

/** Ayar anahtarı: editörün seçimi id listesi (virgülle ayrık, sıra korunur). */
function vitrin_picks_key() { return 'editor_picks'; }

// ============================================================ istek okuma

/** İstek alanı: JSON gövdesi öncelikli, yoksa POST/GET. */
function vitrin_in($key, $default = null) {
    $d = json_body();
    if (array_key_exists($key, $d)) { return $d[$key]; }
    $v = inp($key, null);
    return $v === null ? $default : $v;
}
function vitrin_in_i($key, $default = 0) { $v = vitrin_in($key, $default); return is_scalar($v) ? (int)$v : (int)$default; }
function vitrin_in_s($key, $default = '') { $v = vitrin_in($key, $default); return is_scalar($v) ? trim((string)$v) : (string)$default; }

/** {ids:[…]} biçimindeki tamsayı dizisini okur; tekilleştirir, sırayı korur. */
function vitrin_in_ids($key) {
    $v = vitrin_in($key, []);
    if (is_string($v)) { $v = explode(',', $v); }
    if (!is_array($v)) { return []; }
    $out = [];
    foreach ($v as $x) {
        if (!is_scalar($x)) { continue; }
        $i = (int)$x;
        if ($i > 0 && !in_array($i, $out, true)) { $out[] = $i; }
    }
    return $out;
}

// ============================================================ ortak yardımcılar

/** İçerik değiştikten sonra ön yüz önbelleğini boşaltır (modül varsa). */
function vitrin_flush() { if (function_exists('cache_flush')) { cache_flush(); } }

/** Tarihi 'Y-m-d H:i:s' biçimine getirir; boş/geçersizse null döner. */
function vitrin_datetime($value) {
    $v = trim((string)$value);
    if ($v === '') { return null; }
    $v = str_replace('T', ' ', $v);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $d = DateTime::createFromFormat($format, $v);
        if (!($d instanceof DateTime)) { continue; }
        $err = DateTime::getLastErrors();
        if (is_array($err) && (!empty($err['error_count']) || !empty($err['warning_count']))) { continue; }
        if ($format === 'Y-m-d') { $d->setTime(0, 0, 0); }
        return $d->format('Y-m-d H:i:s');
    }
    return null;
}

/** Haber satırı yayında mı? (status + published_at) */
function vitrin_is_published($row) {
    if (!is_array($row) || (string)arr($row, 'status', '') !== 'published') { return false; }
    // ÇÖP KUTUSU (denetim turu 3, B10): silinmiş haber manşete/son dakikaya
    // ALINAMAZ. Panel artık uyarıyor ama kapı SUNUCUDA olmalı — denetçi
    // headlines.add ucunun çöpteki haber için hâlâ ok:true döndüğünü ölçtü.
    // Sütun yoksa (göç 013 uygulanmamış) koşul atlanır.
    if (trim((string)arr($row, 'deleted_at', '')) !== '') { return false; }
    $pa = (string)arr($row, 'published_at', '');
    return $pa === '' || $pa <= now();
}

/** Panelde ve JS'te kullanılan haber özeti. */
function vitrin_item($row) {
    return [
        'id'            => (int)$row['id'],
        'title'         => (string)$row['title'],
        'image'         => post_image($row, 'thumb'),
        'category'      => (string)arr($row, 'category_name', ''),
        'category_id'   => (int)arr($row, 'category_id', 0),
        'date'          => arr($row, 'published_at', '') ? tr_date($row['published_at']) : '—',
        'is_headline'   => (int)arr($row, 'is_headline', 0),
        'is_breaking'   => (int)arr($row, 'is_breaking', 0),
        'headline_sort' => (int)arr($row, 'headline_sort', 0),
        'url'           => url_post($row),
    ];
}

/** Liste sorguları için ortak SELECT gövdesi. */
function vitrin_select() {
    return 'SELECT ' . post_select_columns() . ', c.name AS category_name, c.color AS category_color
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id';
}

/** Manşetteki (ve yayında olan) haberler, sırayla. */
function vitrin_headline_rows() {
    return qa(vitrin_select() . ' WHERE p.is_headline = 1 AND ' . published_where() . '
        ORDER BY p.headline_sort ASC, p.published_at DESC, p.id ASC', [':nowts' => now()]);
}

/** Verilen id'lerin yayındaki satırları (sırasız). */
function vitrin_rows_by_ids(array $ids) {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) { return []; }
    return qa(vitrin_select() . ' WHERE p.id IN (' . implode(',', $ids) . ') AND ' . published_where(),
        [':nowts' => now()]);
}

/** settings['editor_picks'] → tamsayı id listesi (sıra korunur). */
function vitrin_picks_ids() {
    $out = [];
    foreach (explode(',', (string)setting(vitrin_picks_key(), '')) as $x) {
        $i = (int)trim((string)$x);
        if ($i > 0 && !in_array($i, $out, true)) { $out[] = $i; }
    }
    return $out;
}

/** Editörün seçimi — panel listesi (yayında olmayanlar elenir, sıra korunur). */
function vitrin_picks_items() {
    $ids = vitrin_picks_ids();
    if (!$ids) { return []; }
    $byId = [];
    foreach (vitrin_rows_by_ids($ids) as $r) { $byId[(int)$r['id']] = $r; }
    $out = [];
    foreach ($ids as $i) { if (isset($byId[$i])) { $out[] = vitrin_item($byId[$i]); } }
    return $out;
}

/** Sayfa başındaki dört sayaç. */
function vitrin_counts() {
    $n = now();
    return [
        'headline'  => (int)qv('SELECT COUNT(*) FROM posts p WHERE p.is_headline = 1 AND ' . published_where(), [':nowts' => $n], 0),
        'breaking'  => (int)qv('SELECT COUNT(*) FROM posts p WHERE p.is_breaking = 1 AND ' . published_where(), [':nowts' => $n], 0),
        'picks'     => count(vitrin_picks_items()),
        'published' => (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . published_where(), [':nowts' => $n], 0),
    ];
}

/** Manşet uçlarının ortak yanıt gövdesi. */
function vitrin_headline_payload() {
    $items = [];
    foreach (vitrin_headline_rows() as $r) { $items[] = vitrin_item($r); }
    return [
        'items'      => $items,
        'count'      => count($items),
        'max'        => vitrin_headline_max(),
        'picks'      => vitrin_picks_items(),
        'picks_max'  => vitrin_picks_max(),
        'counts'     => vitrin_counts(),
    ];
}

/** headline_sort değerlerini 1..N olarak yeniden yazar. */
function vitrin_renumber(array $orderedIds) {
    $sort = 0;
    foreach ($orderedIds as $id) {
        $sort++;
        db_update('posts', ['headline_sort' => $sort], 'id = :id', [':id' => (int)$id]);
    }
}

/** Mevcut manşet sırasını sıkıştırır (boşluk kalmasın). */
function vitrin_compact() {
    $ids = [];
    foreach (vitrin_headline_rows() as $r) { $ids[] = (int)$r['id']; }
    vitrin_renumber($ids);
}

// ============================================================ reklam yardımcıları

/** Geçerli reklam türleri (USER_TYPES_PLAN §8 risk #1). */
function vitrin_ad_kinds() { return ['image', 'html']; }

/**
 * Oturumdaki kullanıcı ham HTML/JS reklam kodunu görebilir/yazabilir mi?
 * `ads.html` KİLİTLİ bir izindir — yalnız `admin` rolünde bulunur, veritabanından
 * (role_overrides / user_grants) hiçbir role dağıtılamaz.
 */
function vitrin_ad_can_html() {
    static $var = null;
    if ($var !== null) { return $var; }
    return $var = can(current_user(), 'ads.html');
}

/**
 * Denetim kaydı yazar (göç 012 → audit_log).
 * Kaydın kendisi asla isteği düşürmemeli: tablo yoksa ya da yazım başarısızsa yutulur.
 */
function vitrin_audit($action, $target, $detail = '') {
    try {
        if (!is_scalar($detail)) { $detail = json_encode($detail, JSON_UNESCAPED_UNICODE); }
        db_insert('audit_log', [
            'user_id'    => (int)arr(current_user(), 'id', 0),
            'action'     => substr((string)$action, 0, 60),
            'target'     => substr((string)$target, 0, 120),
            'detail'     => (string)$detail,
            'ip'         => substr(client_ip(), 0, 64),
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        log_error('vitrin_audit: ' . $e->getMessage());
    }
}

/**
 * Görsel reklamın dosya adı: YALNIZ uploads/ altındaki göreli ad.
 * Mutlak yol, sürücü harfi, şema (http:, data:) ve `..` reddedilir.
 * (media_save_upload() '2026/08/ad-9f3c1a2b.jpg' biçiminde ad üretir; alt klasöre izin var.)
 */
function vitrin_ad_image_value($value) {
    $v = trim((string)$value);
    if ($v === '') { return ''; }
    $v = str_replace('\\', '/', $v);
    if (strpos($v, "\0") !== false) { return ''; }
    if (strpos($v, '..') !== false) { return ''; }
    if (strpos($v, ':') !== false) { return ''; }        // şema ya da sürücü harfi
    if (strpos($v, '/') === 0) { return ''; }            // mutlak yol
    if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9/_\-\.]{0,254}$#', $v)) { return ''; }
    return $v;
}

/** Reklamın hedef bağlantısı: yalnız mutlak http/https. Geçersizse boş dize. */
function vitrin_ad_link_value($value) {
    $v = trim((string)$value);
    if ($v === '') { return ''; }
    if (!preg_match('#^https?://#i', $v)) { return ''; }
    if (!sanitize_is_safe_url($v, false)) { return ''; }
    return sanitize_line($v, 500);
}

/** Reklamın o andaki durumu: [anahtar, etiket, rozet tonu] */
function vitrin_ad_state($row) {
    if ((int)arr($row, 'active', 0) !== 1) { return ['passive', 'Pasif', 'notr']; }
    $n = now();
    $s = (string)arr($row, 'starts_at', '');
    $e = (string)arr($row, 'ends_at', '');
    if ($s !== '' && $s > $n) { return ['pending', 'Henüz başlamamış', 'bilgi']; }
    if ($e !== '' && $e < $n) { return ['expired', 'Süresi geçmiş', 'uyari']; }
    return ['active', 'Aktif', 'olumlu'];
}

/**
 * Panelde ve JS'te kullanılan reklam özeti.
 * `html` alanı YALNIZ `ads.html` izni olana döner; diğerlerine boş dize gider
 * (kod istemciye hiç sızmasın). Kaydın kod içerip içermediği `has_html` ile bildirilir.
 */
function vitrin_ad_item($row) {
    $slots  = vitrin_ad_slots();
    $key    = (string)$row['slot_key'];
    $state  = vitrin_ad_state($row);
    $canHtml = vitrin_ad_can_html();

    $kind = (string)arr($row, 'kind', 'html');
    if (!in_array($kind, vitrin_ad_kinds(), true)) { $kind = 'html'; }

    $html     = (string)arr($row, 'html', '');
    $image    = (string)arr($row, 'image', '');
    $approved = (int)arr($row, 'approved_by', 0);

    // Ham HTML kaydı onaysızken ön yüzde BASILMAZ (inc/view.php → ad_render_row).
    $needsApproval = ($kind === 'html' && trim($html) !== '' && $approved <= 0);

    return [
        'id'              => (int)$row['id'],
        'slot_key'        => $key,
        'slot_label'      => isset($slots[$key]) ? $slots[$key][0] : $key,
        'title'           => (string)$row['title'],
        'kind'            => $kind,
        'kind_label'      => $kind === 'image' ? 'Görsel' : 'Ham HTML',
        'html'            => $canHtml ? $html : '',
        'has_html'        => trim($html) !== '',
        'html_len'        => mb_strlen($html),
        'image'           => $image,
        'image_url'       => $image !== '' ? url_upload($image) : '',
        'link_url'        => (string)arr($row, 'link_url', ''),
        'alt_text'        => (string)arr($row, 'alt_text', ''),
        'approved_by'     => $approved,
        'approved'        => $approved > 0,
        'needs_approval'  => $needsApproval,
        'active'          => (int)$row['active'],
        'sort'            => (int)$row['sort'],
        'starts_at'       => (string)$row['starts_at'],
        'ends_at'         => (string)$row['ends_at'],
        'state'           => $state[0],
        'state_label'     => $state[1],
        'state_tone'      => $state[2],
    ];
}

/** Tüm reklamlar (slot, sıra, id). */
function vitrin_ad_items() {
    $out = [];
    foreach (qa('SELECT * FROM ads ORDER BY slot_key ASC, sort ASC, id ASC') as $r) { $out[] = vitrin_ad_item($r); }
    return $out;
}

/** ads.* uçlarının ortak yanıt gövdesi. */
function vitrin_ad_payload(array $extra = []) {
    return $extra + [
        'items'    => vitrin_ad_items(),
        'can_html' => vitrin_ad_can_html(),
        'html_max' => vitrin_ad_html_max(),
    ];
}

// ============================================================ uç noktalar

// admin/pages/*.php bu dosyayı yalnız yardımcılar için yükler; orada api_register yoktur.
if (!function_exists('api_register')) { return; }

// ---------------------------------------------------------------- manşet

api_register('headlines.list', function () {
    return vitrin_headline_payload() + ['message' => 'Manşet listesi yenilendi.'];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

api_register('headlines.add', function () {
    $id = vitrin_in_i('id', 0);
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.'); }
    if (!vitrin_is_published($post)) { json_err('Yalnız yayındaki haberler manşete alınabilir.'); }

    if ((int)$post['is_headline'] === 1) {
        return vitrin_headline_payload() + ['message' => 'Bu haber zaten manşette.'];
    }
    if (count(vitrin_headline_rows()) >= vitrin_headline_max()) {
        json_err('Manşet dolu: en çok ' . vitrin_headline_max() . ' haber olabilir. Önce birini manşetten çıkarın.');
    }

    $max = (int)qv('SELECT MAX(headline_sort) FROM posts WHERE is_headline = 1', [], 0);
    db_update('posts', ['is_headline' => 1, 'headline_sort' => $max + 1], 'id = :id', [':id' => $id]);
    vitrin_compact();
    vitrin_flush();
    return vitrin_headline_payload() + ['message' => 'Haber manşete eklendi.'];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

api_register('headlines.remove', function () {
    $id = vitrin_in_i('id', 0);
    $post = q1('SELECT id, is_headline FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.'); }
    if ((int)$post['is_headline'] !== 1) { json_err('Bu haber zaten manşette değil.'); }

    db_update('posts', ['is_headline' => 0, 'headline_sort' => 0], 'id = :id', [':id' => $id]);
    vitrin_compact();
    vitrin_flush();
    return vitrin_headline_payload() + ['message' => 'Haber manşetten çıkarıldı.'];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

api_register('headlines.reorder', function () {
    $ids = vitrin_in_ids('ids');
    if (!$ids) { json_err('Sıralanacak haber listesi boş.'); }
    if (count($ids) > vitrin_headline_max()) {
        json_err('Manşette en çok ' . vitrin_headline_max() . ' haber olabilir.');
    }

    // Gelen dizinin tamamı gerçekten manşette mi? Değilse hiçbir şey yazma.
    $rows = qa('SELECT id, is_headline FROM posts WHERE id IN (' . implode(',', $ids) . ')');
    if (count($rows) !== count($ids)) { json_err('Sıralama listesinde bulunamayan haber var. Sayfayı yenileyin.'); }
    foreach ($rows as $r) {
        if ((int)$r['is_headline'] !== 1) { json_err('Sıralama listesinde manşette olmayan haber var. Sayfayı yenileyin.'); }
    }

    vitrin_renumber($ids);
    vitrin_flush();
    return vitrin_headline_payload() + ['message' => 'Manşet sırası güncellendi.'];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

api_register('headlines.breaking', function () {
    $id = vitrin_in_i('id', 0);
    $on = vitrin_in_i('on', 0) === 1 ? 1 : 0;
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { json_err('Haber bulunamadı.'); }
    if ($on === 1 && !vitrin_is_published($post)) {
        json_err('Yalnız yayındaki haberler son dakika olarak işaretlenebilir.');
    }

    db_update('posts', ['is_breaking' => $on], 'id = :id', [':id' => $id]);
    vitrin_flush();
    return [
        'id'      => $id,
        'on'      => $on,
        'counts'  => vitrin_counts(),
        'message' => $on ? 'Haber son dakika şeridine alındı.' : 'Haber son dakika şeridinden çıkarıldı.',
    ];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

api_register('headlines.picks', function () {
    $ids = vitrin_in_ids('ids');
    if (count($ids) > vitrin_picks_max()) {
        json_err('Editörün seçiminde en çok ' . vitrin_picks_max() . ' haber olabilir.');
    }
    if ($ids) {
        $found = [];
        foreach (vitrin_rows_by_ids($ids) as $r) { $found[] = (int)$r['id']; }
        foreach ($ids as $i) {
            if (!in_array($i, $found, true)) { json_err('Editörün seçimine yalnız yayındaki haberler eklenebilir.'); }
        }
    }

    setting_set(vitrin_picks_key(), implode(',', $ids));
    vitrin_flush();
    return [
        'picks'     => vitrin_picks_items(),
        'picks_max' => vitrin_picks_max(),
        'counts'    => vitrin_counts(),
        'message'   => $ids ? 'Editörün seçimi kaydedildi.' : 'Editörün seçimi temizlendi.',
    ];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

api_register('headlines.search', function () {
    $q      = sanitize_line(vitrin_in_s('q', ''), 100);
    $catId  = vitrin_in_i('category_id', 0);
    $page   = max(1, vitrin_in_i('page', 1));
    $per    = vitrin_per_page();

    $where  = published_where();
    $params = [':nowts' => now()];
    if ($q !== '') {
        $where .= ' AND (p.title LIKE :q OR p.spot LIKE :q)';
        $params[':q'] = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
    }
    if ($catId > 0) { $where .= ' AND p.category_id = :cat'; $params[':cat'] = $catId; }

    $total  = (int)qv('SELECT COUNT(*) FROM posts p WHERE ' . $where, $params, 0);
    $pages  = (int)max(1, ceil($total / $per));
    $page   = min($page, $pages);
    $offset = ($page - 1) * $per;

    $items = [];
    foreach (qa(vitrin_select() . ' WHERE ' . $where . '
        ORDER BY p.published_at DESC, p.id DESC LIMIT ' . $per . ' OFFSET ' . $offset, $params) as $r) {
        $items[] = vitrin_item($r);
    }

    return [
        'items'          => $items,
        'total'          => $total,
        'page'           => $page,
        'pages'          => $pages,
        'per_page'       => $per,
        'headline_count' => count(vitrin_headline_rows()),
        'max'            => vitrin_headline_max(),
        'counts'         => vitrin_counts(),
        'message'        => $total > 0 ? $total . ' haber bulundu.' : 'Ölçüte uyan haber bulunamadı.',
    ];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

// ---------------------------------------------------------------- reklamlar

/**
 * İstek : {}
 * Yanıt : {ok:true, items:[…], can_html:bool, html_max:int}
 * `html` alanı yalnız `ads.html` izni olana dolu döner; diğerlerine boş dize +
 * `has_html: true` bayrağı gider.
 */
api_register('ads.list', function () {
    return vitrin_ad_payload(['message' => 'Reklam listesi yenilendi.']);
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/**
 * İstek : {id?, kind:'image'|'html', slot_key, title, active, sort, starts_at, ends_at,
 *          image?, link_url?, alt_text?, html?}
 * Yanıt : {ok:true, id:int, item:{…}, items:[…], message:'…'}
 *
 * GÜVENLİK (USER_TYPES_PLAN §8 risk #1):
 *   · `html` alanı gönderilmişse `ads.html` izni ŞARTTIR; yoksa 403 ve HİÇBİR ŞEY yazılmaz.
 *   · İzni olmayan biri mevcut ham HTML reklamını kaydederse `html` sütunu değişmeden kalır
 *     (yalnız zamanlama/aktiflik/sıra/başlık güncellenir).
 *   · `html` içeriği değişirse `approved_by` sıfırlanır — yeniden onay gerekir.
 */
api_register('ads.save', function () {
    $u  = current_user();
    $id = vitrin_in_i('id', 0);

    $existing = null;
    if ($id > 0) {
        $existing = q1('SELECT * FROM ads WHERE id = :i', [':i' => $id]);
        if (!$existing) { json_err('Reklam bulunamadı.', 404); }
    }

    $canHtml = can($u, 'ads.html');
    $htmlRaw = vitrin_in('html', null);
    $htmlSent = ($htmlRaw !== null);

    // --- tür
    $kind = vitrin_in_s('kind', '');
    if ($kind === '') {
        if ($existing) { $kind = (string)arr($existing, 'kind', 'html'); }
        else { $kind = ($htmlSent && trim((string)$htmlRaw) !== '') ? 'html' : 'image'; }
    }
    if (!in_array($kind, vitrin_ad_kinds(), true)) { json_err('Geçersiz reklam türü.'); }

    // --- ham kod kapısı: izin yoksa BURADA dur, tek bir sütun bile yazılmasın
    if ($htmlSent && !$canHtml) {
        vitrin_audit('ads.denied', $id > 0 ? 'ad:' . $id : 'ad:new', 'kind=' . $kind . ' ham HTML denemesi');
        json_err('Ham HTML/JS reklam kodunu yalnız Sistem Yöneticisi girebilir. '
            . 'Bu kısıt, reklam kodunun sitede JavaScript çalıştırabilmesinden kaynaklanır.', 403);
    }

    // --- alan
    $slot = vitrin_in_s('slot_key', '');
    if ($slot === '' && $existing) { $slot = (string)$existing['slot_key']; }
    if (!isset(vitrin_ad_slots()[$slot])) { json_err('Geçersiz reklam alanı.'); }

    $title = sanitize_line(vitrin_in_s('title', ''), 190);
    if ($title === '') { json_err('Reklam başlığı zorunlu.'); }

    $starts = vitrin_datetime(vitrin_in_s('starts_at', ''));
    $ends   = vitrin_datetime(vitrin_in_s('ends_at', ''));
    if ($starts !== null && $ends !== null && $starts > $ends) {
        json_err('Başlangıç tarihi bitiş tarihinden sonra olamaz.');
    }

    $data = [
        'kind'      => $kind,
        'slot_key'  => $slot,
        'title'     => $title,
        'active'    => vitrin_in_i('active', 0) === 1 ? 1 : 0,
        'sort'      => max(0, min(999, vitrin_in_i('sort', 0))),
        'starts_at' => $starts,
        'ends_at'   => $ends,
    ];

    // --- görsel reklam alanları
    if ($kind === 'image') {
        $image = vitrin_ad_image_value(vitrin_in_s('image', $existing ? (string)arr($existing, 'image', '') : ''));
        if ($image === '') { json_err('Görsel reklam için geçerli bir görsel seçmelisiniz.'); }

        $linkIn = vitrin_in_s('link_url', '');
        $link   = vitrin_ad_link_value($linkIn);
        if ($linkIn !== '' && $link === '') {
            json_err('Hedef bağlantı yalnız http:// ya da https:// ile başlayan tam adres olabilir.');
        }

        // Kreatif (görsel/bağlantı/alt metin) değiştiriliyorsa ads.creative gerekir;
        // yalnız zamanlama değiştiren biri (ads.schedule) buna takılmaz.
        $kreatifDegisti = !$existing
            || $image !== (string)arr($existing, 'image', '')
            || $link  !== (string)arr($existing, 'link_url', '')
            || sanitize_line(vitrin_in_s('alt_text', ''), 190) !== (string)arr($existing, 'alt_text', '');
        if ($kreatifDegisti && !can($u, 'ads.creative')) {
            json_err('Görsel reklam kurma yetkiniz yok.', 403);
        }

        $data['image']    = $image;
        $data['link_url'] = $link;
        $data['alt_text'] = sanitize_line(vitrin_in_s('alt_text', ''), 190);
    }

    // --- ham HTML alanı: yalnız ads.html izniyle ve yalnız gönderildiyse yazılır
    $onayDusuruldu = false;
    if ($htmlSent) {
        $html = is_scalar($htmlRaw) ? (string)$htmlRaw : '';
        if (mb_strlen($html) > vitrin_ad_html_max()) {
            json_err('Reklam kodu en çok ' . vitrin_ad_html_max() . ' karakter olabilir.');
        }
        // DİKKAT: kod bilerek temizlenmez — ad_slot() siteye olduğu gibi basar.
        // Güvenlik kapısı temizleme değil, ONAY akışıdır (approved_by).
        $data['html'] = $html;
        if ((string)arr($existing, 'html', '') !== $html) {
            $data['approved_by'] = 0;      // içerik değişti → yeniden onay
            $onayDusuruldu = true;
        }
    } elseif (!$existing) {
        $data['html'] = '';                // yeni kayıt, kod gönderilmedi
    }
    // $existing var ve html gönderilmediyse: html sütununa DOKUNULMAZ.

    if ($id > 0) {
        db_update('ads', $data, 'id = :id', [':id' => $id]);
        $message = 'Reklam güncellendi.';
    } else {
        $id = db_insert('ads', $data);
        $message = 'Reklam eklendi.';
    }
    if ($onayDusuruldu) {
        $message .= ' Kod değiştiği için onay sıfırlandı; yönetici onaylayana kadar yayında görünmez.';
    }

    vitrin_audit('ads.save', 'ad:' . $id,
        'kind=' . $kind . ' slot=' . $slot . ' active=' . $data['active']
        . ($htmlSent ? ' html=yazildi' : ' html=degismedi')
        . ($onayDusuruldu ? ' onay=sifirlandi' : ''));
    vitrin_flush();

    $row = q1('SELECT * FROM ads WHERE id = :i', [':i' => $id]);
    return vitrin_ad_payload([
        'id'      => (int)$id,
        'item'    => $row ? vitrin_ad_item($row) : null,
        'message' => $message,
    ]);
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/**
 * İstek : {id:int}
 * Yanıt : {ok:true, id:int, items:[…], message:'…'}
 */
api_register('ads.delete', function () {
    $id = vitrin_in_i('id', 0);
    $row = q1('SELECT id, title, kind FROM ads WHERE id = :i', [':i' => $id]);
    if (!$row) { json_err('Reklam bulunamadı.', 404); }
    q('DELETE FROM ads WHERE id = :i', [':i' => $id]);
    vitrin_audit('ads.delete', 'ad:' . $id, 'kind=' . (string)arr($row, 'kind', '') . ' baslik=' . (string)$row['title']);
    vitrin_flush();
    return vitrin_ad_payload(['id' => $id, 'message' => 'Reklam silindi.']);
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/**
 * İstek : {id:int, on:0|1}
 * Yanıt : {ok:true, id:int, on:int, item:{…}, items:[…], message:'…'}
 */
api_register('ads.toggle', function () {
    $id = vitrin_in_i('id', 0);
    $on = vitrin_in_i('on', 0) === 1 ? 1 : 0;
    if (!q1('SELECT id FROM ads WHERE id = :i', [':i' => $id])) { json_err('Reklam bulunamadı.', 404); }
    db_update('ads', ['active' => $on], 'id = :id', [':id' => $id]);
    vitrin_audit('ads.toggle', 'ad:' . $id, 'active=' . $on);
    vitrin_flush();

    $row = q1('SELECT * FROM ads WHERE id = :i', [':i' => $id]);
    return vitrin_ad_payload([
        'id'      => $id,
        'on'      => $on,
        'item'    => $row ? vitrin_ad_item($row) : null,
        'message' => $on ? 'Reklam yayına alındı.' : 'Reklam yayından kaldırıldı.',
    ]);
}, ['perm' => 'ads.schedule', 'methods' => ['POST']]);

/**
 * İstek : {id:int, on:0|1}
 * Yanıt : {ok:true, id:int, approved:bool, item:{…}, items:[…], message:'…'}
 *
 * Ham HTML/JS reklamın yayın onayı. `ads.html` KİLİTLİ izni gerekir (yalnız admin).
 * Onaysız kayıt ön yüzde basılmaz (inc/view.php → ad_render_row).
 */
api_register('ads.approve', function () {
    $u  = current_user();
    $id = vitrin_in_i('id', 0);
    $on = vitrin_in_i('on', 1) === 1 ? 1 : 0;

    $row = q1('SELECT * FROM ads WHERE id = :i', [':i' => $id]);
    if (!$row) { json_err('Reklam bulunamadı.', 404); }
    if ((string)arr($row, 'kind', 'html') !== 'html') {
        json_err('Yalnız ham HTML/JS reklamlar onay gerektirir.');
    }
    if ($on === 1 && trim((string)arr($row, 'html', '')) === '') {
        json_err('Kodu boş bir reklam onaylanamaz.');
    }

    db_update('ads', ['approved_by' => $on ? (int)arr($u, 'id', 0) : 0], 'id = :id', [':id' => $id]);
    vitrin_audit('ads.approve', 'ad:' . $id, $on ? 'onaylandi' : 'onay=geri_alindi');
    vitrin_flush();

    $row = q1('SELECT * FROM ads WHERE id = :i', [':i' => $id]);
    return vitrin_ad_payload([
        'id'       => $id,
        'approved' => $on === 1,
        'item'     => $row ? vitrin_ad_item($row) : null,
        'message'  => $on ? 'Reklam kodu onaylandı; artık ön yüzde basılıyor.'
                          : 'Onay geri alındı; reklam ön yüzde basılmayacak.',
    ]);
}, ['perm' => 'ads.html', 'methods' => ['POST']]);
