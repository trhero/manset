<?php
/**
 * Manşet — RSS uç noktaları (Ajan-4).
 * Ad alanı: rss.*  (CONTRACTS §7)
 *
 * Tüm uçlar `rss.manage` izni ve CSRF gerektirir; yanıtlar Türkçe `message` taşır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once __DIR__ . '/../rss.php';

/** Kısayol: uçlarda tekrar eden seçenekler. */
function rss_api_opts() {
    return ['perm' => 'rss.manage', 'methods' => ['POST']];
}

/** İstek gövdesinden alan okur (JSON gövdesi + POST yedeği). */
function rss_api_field($key, $default = '') {
    $body = json_body();
    if (array_key_exists($key, $body)) { return $body[$key]; }
    if (isset($_POST[$key])) { return $_POST[$key]; }
    return $default;
}

// ---------------------------------------------------------------- rss.list
api_register('rss.list', function () {
    $rows = qa('SELECT s.*, c.name AS category_name,
                (SELECT COUNT(*) FROM rss_items i WHERE i.source_id = s.id) AS item_count,
                (SELECT COUNT(*) FROM rss_items i WHERE i.source_id = s.id AND i.status = \'new\') AS new_count
                FROM rss_sources s LEFT JOIN categories c ON c.id = s.category_id
                ORDER BY s.active DESC, s.name ASC');
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'            => (int)$r['id'],
            'name'          => (string)$r['name'],
            'url'           => (string)$r['url'],
            'category_id'   => (int)$r['category_id'],
            'category_name' => (string)($r['category_name'] ?? ''),
            'auto_publish'  => (int)$r['auto_publish'],
            'ai_rewrite'    => (int)$r['ai_rewrite'],
            'active'        => (int)$r['active'],
            'error_count'   => (int)$r['error_count'],
            'interval_min'  => (int)($r['interval_min'] ?? 0),
            'next_check_at' => (string)($r['next_check_at'] ?? ''),
            'last_fetch_at' => (string)($r['last_fetch_at'] ?? ''),
            'fetch_error'   => (string)($r['fetch_error'] ?? ''),
            'item_count'    => (int)$r['item_count'],
            'new_count'     => (int)$r['new_count'],
        ];
    }
    return ['items' => $items, 'message' => count($items) . ' kaynak listelendi.'];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.save
api_register('rss.save', function () {
    $id   = (int)rss_api_field('id', 0);
    $name = sanitize_line((string)rss_api_field('name', ''), 190);
    $url  = trim((string)rss_api_field('url', ''));
    $categoryId  = (int)rss_api_field('category_id', 0);
    // GÜVENLİK (denetim tur 2, B03): otomatik yayın, beslemeden gelen metni
    // İNSAN ONAYI OLMADAN siteye basar — yani fiilen yayımlama yetkisidir.
    // `rss.autopublish` izni olmayan (ör. wire_editor) bunu açamaz; mevcut
    // kaynağı düzenlerken de eski değer korunur, sessizce kapatılmaz.
    $autoPublish = (int)rss_api_field('auto_publish', 0) ? 1 : 0;
    if ($autoPublish === 1 && !can(current_user(), 'rss.autopublish')) {
        $oncekiOtomatik = $id > 0
            ? (int)qv('SELECT auto_publish FROM rss_sources WHERE id = :i', [':i' => $id], 0)
            : 0;
        if ($oncekiOtomatik !== 1) {
            json_err('Otomatik yayın açma yetkiniz yok. Kaynak, haberleri havuzda onaya bırakır.', 403);
        }
    }
    $aiRewrite   = (int)rss_api_field('ai_rewrite', 0) ? 1 : 0;
    $active      = (int)rss_api_field('active', 1) ? 1 : 0;

    if ($name === '') { return json_err('Kaynak adı zorunlu.'); }
    if ($url === '')  { return json_err('Besleme adresi zorunlu.'); }
    if (strlen($url) > 500) { return json_err('Besleme adresi çok uzun (en çok 500 karakter).'); }

    // SSRF kapısı: kaydetmeden önce adres doğrulanır
    if (rss_guard_url($url) === false) { return json_err(rss_rejected_message()); }

    if ($categoryId > 0 && !qv('SELECT id FROM categories WHERE id = :i', [':i' => $categoryId], 0)) {
        return json_err('Seçilen kategori bulunamadı.');
    }

    $clash = qv('SELECT id FROM rss_sources WHERE url = :u AND id <> :i', [':u' => $url, ':i' => $id], 0);
    if ($clash) { return json_err('Bu besleme adresi zaten kayıtlı.'); }

    $data = [
        'name'         => $name,
        'url'          => $url,
        'category_id'  => $categoryId,
        'auto_publish' => $autoPublish,
        'ai_rewrite'   => $aiRewrite,
        'active'       => $active,
    ];

    // Kaynak başına kontrol aralığı (dk). 0 = varsayılan (RSS_DEFAULT_INTERVAL).
    // Sütun göç 013 ile geldi; uygulanmadıysa alan sessizce yok sayılır.
    if (rss_has_backoff_columns()) {
        $interval = (int)rss_api_field('interval_min', 0);
        if ($interval < 0) { $interval = 0; }
        if ($interval > 10080) { $interval = 10080; }   // en çok 7 gün
        $data['interval_min'] = $interval;
        // Aralık değişince bir sonraki kontrol hemen yapılabilsin.
        $data['next_check_at'] = '';
    }

    if ($id > 0) {
        if (!qv('SELECT id FROM rss_sources WHERE id = :i', [':i' => $id], 0)) {
            return json_err('Kaynak bulunamadı.', 404);
        }
        db_update('rss_sources', $data, 'id = :id', [':id' => $id]);
        return ['id' => $id, 'message' => 'Kaynak güncellendi.'];
    }

    $data['error_count'] = 0;
    $data['fetch_error'] = '';
    $newId = db_insert('rss_sources', $data);
    return ['id' => (int)$newId, 'message' => 'Kaynak eklendi.'];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.delete
api_register('rss.delete', function () {
    $id = (int)rss_api_field('id', 0);
    if ($id <= 0) { return json_err('Geçersiz kaynak.'); }
    if (!qv('SELECT id FROM rss_sources WHERE id = :i', [':i' => $id], 0)) {
        return json_err('Kaynak bulunamadı.', 404);
    }
    $items = (int)qv('SELECT COUNT(*) FROM rss_items WHERE source_id = :i', [':i' => $id], 0);
    q('DELETE FROM rss_items WHERE source_id = :i', [':i' => $id]);
    q('DELETE FROM rss_sources WHERE id = :i', [':i' => $id]);
    return ['message' => 'Kaynak silindi' . ($items > 0 ? ' (' . $items . ' havuz ögesiyle birlikte).' : '.')];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.test
api_register('rss.test', function () {
    $url = trim((string)rss_api_field('url', ''));
    $id  = (int)rss_api_field('id', 0);
    if ($url === '' && $id > 0) { $url = (string)qv('SELECT url FROM rss_sources WHERE id = :i', [':i' => $id], ''); }
    if ($url === '') { return json_err('Besleme adresi zorunlu.'); }
    if (rss_guard_url($url) === false) { return json_err(rss_rejected_message()); }

    // dryRun: hiçbir şey kaydedilmez, yalnız besleme okunur
    $r = rss_fetch_source(['id' => 0, 'url' => $url], true);
    if (!$r['ok']) { return json_err($r['error'] !== '' ? $r['error'] : 'Besleme okunamadı.'); }

    return [
        'count'       => (int)$r['toplam'],
        'samples'     => array_values($r['ornekler']),
        'image_found' => (bool)$r['gorsel_var'],
        'feed_title'  => (string)$r['kaynak_adi'],
        'message'     => $r['toplam'] . ' öge bulundu.',
    ];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.fetch_now
api_register('rss.fetch_now', function () {
    $id = (int)rss_api_field('id', 0);
    $source = $id > 0 ? q1('SELECT * FROM rss_sources WHERE id = :i', [':i' => $id]) : null;
    if (!$source) { return json_err('Kaynak bulunamadı.', 404); }

    $r = rss_fetch_source($source, false);
    if (!$r['ok']) {
        return json_err($r['error'] !== '' ? $r['error'] : 'Çekim başarısız.', 400, [
            'yeni' => 0, 'toplam' => 0,
        ]);
    }
    $tekrar = (int)arr($r, 'tekrar', 0);
    return [
        'yeni'    => (int)$r['yeni'],
        'tekrar'  => $tekrar,
        'toplam'  => (int)$r['toplam'],
        'error'   => '',
        'message' => $r['toplam'] . ' öge okundu, ' . $r['yeni'] . ' yeni öge havuza eklendi.'
                   . ($tekrar > 0 ? ' ' . $tekrar . ' öge başka kaynakta zaten vardı, tekrar olarak işaretlendi.' : ''),
    ];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.pool
api_register('rss.pool', function () {
    $filters = [
        'source_id' => (int)rss_api_field('source_id', 0),
        'status'    => (string)rss_api_field('status', ''),
        'q'         => (string)rss_api_field('q', ''),
        'dup'       => (int)rss_api_field('dup', 0) ? 1 : 0,
    ];
    $page = max(1, (int)rss_api_field('page', 1));
    $perPage = 30;
    $total = rss_pool_count($filters);
    $rows = rss_pool($filters, $perPage, ($page - 1) * $perPage);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'           => (int)$r['id'],
            'source_id'    => (int)$r['source_id'],
            'source_name'  => (string)($r['source_name'] ?? ''),
            'title'        => (string)$r['title'],
            'link'         => (string)$r['link'],
            'image'        => (string)$r['image'],
            'summary'      => (string)($r['summary'] ?? ''),
            'published_at' => (string)($r['published_at'] ?? ''),
            'fetched_at'   => (string)($r['fetched_at'] ?? ''),
            'status'       => (string)$r['status'],
            'dup_of'       => (int)($r['dup_of'] ?? 0),
        ];
    }
    return [
        'items'   => $items,
        'total'   => $total,
        'page'    => $page,
        'message' => $total . ' öge bulundu.',
    ];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.item_preview
/*
 * HAVUZDA GÖVDE ÖNİZLEME (1.3-05).
 * Editör bugün havuzda yalnız başlığı ve 110 karakterlik özeti görüyor; "Taslak
 * yap" dedikten sonra metnin gerçekte ne olduğunu öğreniyor. Beslemeden gelen
 * gövdenin yarısı reklam kutusu ya da tek satırlık teaser olabilir. Önizleme,
 * içeri almadan önce metni gösterir.
 *
 * Gövde `sanitize_html($body, false)` ile ikinci kez süzülür: veritabanındaki
 * kayıt zaten süzülmüştü ama önizleme panelde HAM basılacak; ikinci süzgeç,
 * eski (1.0) kayıtların da aynı beyaz listeden geçmesini garanti eder.
 */
api_register('rss.item_preview', function () {
    $id = (int)rss_api_field('id', 0);
    if ($id <= 0) { return json_err('Geçersiz öge.'); }
    $it = q1('SELECT i.*, s.name AS source_name FROM rss_items i
              LEFT JOIN rss_sources s ON s.id = i.source_id WHERE i.id = :i', [':i' => $id]);
    if (!$it) { return json_err('Öge bulunamadı.', 404); }

    $body = sanitize_html((string)$it['content'], false);
    if (trim(strip_tags($body)) === '') {
        $sum = (string)$it['summary'];
        $body = $sum !== '' ? '<p>' . esc($sum) . '</p>' : '';
    }

    $dupOf = (int)($it['dup_of'] ?? 0);
    $dupTitle = '';
    $dupSource = '';
    if ($dupOf > 0) {
        $d = q1('SELECT i.title, s.name AS source_name FROM rss_items i
                 LEFT JOIN rss_sources s ON s.id = i.source_id WHERE i.id = :i', [':i' => $dupOf]);
        if ($d) {
            $dupTitle = (string)$d['title'];
            $dupSource = (string)($d['source_name'] ?? '');
        }
    }

    return [
        'id'          => $id,
        'title'       => (string)$it['title'],
        'link'        => (string)$it['link'],
        'image'       => (string)$it['image'],
        'source_name' => (string)($it['source_name'] ?? ''),
        'published_at' => (string)($it['published_at'] ?? ''),
        'status'      => (string)$it['status'],
        'body'        => $body,
        'chars'       => mb_strlen(trim(strip_tags($body)), 'UTF-8'),
        'dup_of'      => $dupOf,
        'dup_title'   => $dupTitle,
        'dup_source'  => $dupSource,
        'message'     => 'Önizleme hazır.',
    ];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.item_action
api_register('rss.item_action', function () {
    $action = (string)rss_api_field('action', '');
    if (!in_array($action, ['ai_rewrite', 'draft', 'skip'], true)) {
        return json_err('Geçersiz işlem.');
    }

    $ids = rss_api_field('ids', []);
    if (!is_array($ids)) { $ids = []; }
    $single = (int)rss_api_field('id', 0);
    if ($single > 0) { $ids[] = $single; }

    $clean = [];
    foreach ($ids as $v) { $v = (int)$v; if ($v > 0 && !in_array($v, $clean, true)) { $clean[] = $v; } }
    if (!$clean) { return json_err('İşlem için öge seçilmedi.'); }
    if (count($clean) > 100) { $clean = array_slice($clean, 0, 100); }

    // 'ai_rewrite' için modül şartı — kurulu değilse hiç uğraşmadan açık hata
    if ($action === 'ai_rewrite' && !function_exists('ai_job_enqueue')) {
        return json_err('Yapay zekâ modülü kurulu değil.');
    }

    $done = 0;
    $failed = 0;
    $lastPostId = 0;
    $lastError = '';

    foreach ($clean as $itemId) {
        $item = q1('SELECT id, status FROM rss_items WHERE id = :i', [':i' => $itemId]);
        if (!$item) { $failed++; $lastError = 'Öge bulunamadı.'; continue; }

        if ($action === 'skip') {
            db_update('rss_items', ['status' => 'skipped'], 'id = :id', [':id' => $itemId]);
            $done++;
            continue;
        }

        if ($action === 'ai_rewrite') {
            try {
                ai_job_enqueue($itemId, 'rss_rewrite');
                db_update('rss_items', ['status' => 'queued'], 'id = :id', [':id' => $itemId]);
                $done++;
            } catch (Throwable $e) {
                $failed++;
                $lastError = 'Kuyruğa alınamadı.';
                log_error('rss.item_action ai_rewrite: ' . $e->getMessage(), 'oge=' . $itemId);
            }
            continue;
        }

        // action === 'draft'
        $r = rss_item_to_draft($itemId);
        if ($r['ok']) { $done++; $lastPostId = (int)$r['post_id']; }
        else { $failed++; $lastError = $r['error']; }
    }

    if ($done === 0) {
        return json_err($lastError !== '' ? $lastError : 'Hiçbir öge işlenemedi.');
    }

    $labels = [
        'ai_rewrite' => $done . ' öge yapay zekâ kuyruğuna alındı.',
        'draft'      => $done . ' öge için taslak haber oluşturuldu.',
        'skip'       => $done . ' öge atlandı.',
    ];
    $out = ['message' => $labels[$action] . ($failed > 0 ? ' ' . $failed . ' öge işlenemedi.' : '')];
    if ($lastPostId > 0) {
        $out['post_id'] = $lastPostId;
        $out['post_url'] = base_url() . '/admin/?p=posts&duzenle=' . $lastPostId;
    }
    return $out;
}, rss_api_opts());

// ---------------------------------------------------------------- rss.stats
api_register('rss.stats', function () {
    $s = rss_stats();
    return $s + ['message' => 'Sayaçlar güncellendi.'];
}, rss_api_opts());

// ---------------------------------------------------------------- rss.katalog
/**
 * Örnek kaynak kataloğunu döndürür ve seçilenleri ekler.
 *
 * İstek : {do:'liste'} → katalog + hangileri zaten ekli
 *         {do:'ekle', urls:[...]} → seçilenleri ekler
 *
 * Katalog dışı bir adres bu uçtan EKLENEMEZ (rss_katalog_ekle() denetler):
 * aksi halde bu uç, keyfi bir adresi kaynak yapmanın kısa yolu olurdu ve
 * `rss.save`'deki doğrulamaları atlardı.
 */
api_register('rss.katalog', function () {
    if (!function_exists('rss_katalog')) {
        json_err('Örnek kaynak kataloğu bu kurulumda yok.', 503);
    }
    $d  = json_body();
    $do = (string)arr($d, 'do', 'liste');

    // Zaten ekli adresler (tekrar eklemeyi engellemek ve arayüzde göstermek için)
    $ekli = [];
    foreach (qa('SELECT url FROM rss_sources') as $r) { $ekli[strtolower((string)$r['url'])] = true; }

    if ($do === 'ekle') {
        $urls = arr($d, 'urls', []);
        if (!is_array($urls) || !$urls) { json_err('Kaynak seçilmedi.', 400); }
        // ÜST SINIR: tek istekte 50'den fazla kaynak eklemek, ilk cron turunda
        // onlarca dış siteye aynı anda gitmek demektir.
        if (count($urls) > 50) { json_err('Tek seferde en çok 50 kaynak eklenebilir.', 400); }

        $eklendi = 0; $atlanan = 0; $hatalar = [];
        foreach ($urls as $u) {
            $r = rss_katalog_ekle((string)$u);
            if (!empty($r['ok'])) { $eklendi++; }
            else { $atlanan++; if (count($hatalar) < 5) { $hatalar[] = (string)$r['error']; } }
        }
        if (function_exists('cache_flush')) { cache_flush(); }
        return [
            'eklendi' => $eklendi, 'atlanan' => $atlanan, 'hatalar' => $hatalar,
            'message' => $eklendi . ' kaynak eklendi'
                       . ($atlanan ? ', ' . $atlanan . ' atlandı (zaten ekli ya da geçersiz)' : '') . '.',
        ];
    }

    $liste = [];
    foreach (rss_katalog() as $k) {
        $liste[] = [
            'ad'       => (string)$k[0],
            'url'      => (string)$k[1],
            'kategori' => (string)$k[2],
            'aralik'   => (int)$k[3],
            'ekli'     => isset($ekli[strtolower((string)$k[1])]),
        ];
    }
    return ['items' => $liste, 'toplam' => count($liste)];
}, ['perm' => 'rss.manage', 'methods' => ['POST']]);
