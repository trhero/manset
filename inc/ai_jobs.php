<?php
/**
 * Manşet — yapay zekâ iş kuyruğu (Ajan-5).
 *
 * Sorumluluk:
 *   - RSS ögelerini yeniden yazım işine dönüştürmek (ai_jobs tablosu),
 *   - cron turunda eşzamanlılık sınırıyla iş işlemek,
 *   - üretilen metinden kaynak künyeli haber kaydı oluşturmak,
 *   - panel için liste / sayaç / günlük özeti üretmek.
 *
 * Sağlayıcı adaptörü inc/ai.php içindedir; bu dosya onun üstünde çalışır.
 *
 * GÜVENLİK: Dış kaynaklı metin (RSS başlık/gövde) istem şablonuna yalnız VERİ
 * olarak girer. Şablonu dolduran ai_prompt() her yer tutucuyu ai_neutralize()
 * süzgecinden geçirir; sistem talimatı ayrı kanaldan (ai_system_prompt) gider.
 * Ham metin hiçbir yerde doğrudan istemin içine yazılmaz.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';
// ai_prompt() varsayılan şablonlar için schema_default_settings() çağırır;
// inc/ai.php bunu kendisi yüklemediğinden burada garantiye alınır.
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/ai.php';

// ============================================================ sabitler

/** Takılmış sayılan 'running' süresi (saniye). */
if (!defined('AI_JOB_STUCK_SECONDS')) { define('AI_JOB_STUCK_SECONDS', 600); }
/** Bir işin en çok kaç kez denenebileceği. */
if (!defined('AI_JOB_MAX_ATTEMPTS')) { define('AI_JOB_MAX_ATTEMPTS', 3); }

/** ai_jobs.kind için izinli değerler (CONTRACTS §2). */
function ai_job_kinds() {
    return ['rss_rewrite', 'title', 'spot', 'seo'];
}

/** ai_jobs.status için izinli değerler. */
function ai_job_statuses() {
    return ['queued', 'running', 'done', 'failed'];
}

/** Aynı anda çalışabilecek en çok iş sayısı (settings['ai_max_concurrent']). */
function ai_max_concurrent() {
    return max(1, min(8, (int)setting('ai_max_concurrent', '2')));
}

/**
 * Modelden istenen JSON biçimi. Yeniden yazım isteminin sonuna eklenir;
 * şablonun kullanıcı tarafından değiştirilmesinden etkilenmez.
 */
function ai_json_instruction() {
    return 'Yanıtı YALNIZCA şu JSON biçiminde ver: '
         . '{"title":"…","spot":"…","body":"<p>…</p>","seo_title":"…","seo_desc":"…","tags":["…"]}';
}

/** Yeniden yazım için ek sistem talimatı (tıklama tuzağı yasağı). */
function ai_rewrite_system_prompt() {
    return ai_system_prompt('Tıklama tuzağı (clickbait) başlık üretmen kesinlikle yasaktır: '
        . 'soru biçimli merak tuzağı, "inanamayacaksınız", "işte o an" gibi kalıplar, '
        . 'büyük harfle bağırma ve abartılı vaat kullanma. Başlık haberin özünü olduğu gibi bildirsin.');
}

// ============================================================ iş ekleme

/**
 * RSS ögesi için yeni iş kuyruğa alır. Aynı öge için bekleyen/çalışan iş varsa
 * yenisi açılmaz (mevcut işin id'si döner).
 *
 * @return int Yeni ya da mevcut iş id'si; eklenemediyse 0
 */
function ai_job_enqueue($rssItemId, $kind = 'rss_rewrite') {
    $rssItemId = (int)$rssItemId;
    $kind = in_array((string)$kind, ai_job_kinds(), true) ? (string)$kind : 'rss_rewrite';
    if ($rssItemId <= 0) { return 0; }

    try {
        $item = q1('SELECT id, status FROM rss_items WHERE id = :i', [':i' => $rssItemId]);
        if (!$item) { return 0; }

        // Zaten bekleyen/çalışan iş varsa onu döndür
        $exists = (int)qv(
            'SELECT id FROM ai_jobs WHERE rss_item_id = :i AND kind = :k AND status IN (\'queued\',\'running\') ORDER BY id ASC',
            [':i' => $rssItemId, ':k' => $kind], 0);
        if ($exists > 0) { return $exists; }

        $id = db_insert('ai_jobs', [
            'rss_item_id'    => $rssItemId,
            'kind'           => $kind,
            'status'         => 'queued',
            'attempts'       => 0,
            'result_post_id' => 0,
            'error'          => '',
            'created_at'     => now(),
            'started_at'     => null,
            'finished_at'    => null,
        ]);
        if ($id > 0) {
            q('UPDATE rss_items SET status = \'queued\' WHERE id = :i AND status = \'new\'', [':i' => $rssItemId]);
        }
        return (int)$id;
    } catch (Throwable $e) {
        log_error('ai_job_enqueue: ' . $e->getMessage());
        return 0;
    }
}

// ============================================================ cron turu

/**
 * Cron turu. Eşzamanlılık sınırını gözetir, takılmış işleri kurtarır,
 * en çok $max işi işler ve insan okunur bir özet döndürür.
 *
 * @return string '2 iş işlendi, 1 başarılı, 1 hata'
 */
function ai_jobs_tick($max = 2) {
    if (!ai_configured()) { return 'yapılandırılmadı'; }

    $max = max(1, min(20, (int)$max));

    try {
        // 1) Takılma kurtarma: uzun süredir 'running' kalan iş yeniden kuyruğa alınır.
        $cut = date('Y-m-d H:i:s', time() - AI_JOB_STUCK_SECONDS);
        $stuck = q('UPDATE ai_jobs SET status = \'queued\' WHERE status = \'running\' AND (started_at IS NULL OR started_at < :c)',
            [':c' => $cut])->rowCount();
        if ($stuck > 0) { log_error('ai_jobs: ' . $stuck . ' takılmış iş kuyruğa döndürüldü'); }

        // 2) Eşzamanlılık sınırı: 'running' sayısı sınırdaysa tur boş döner.
        $limit = ai_max_concurrent();
        $running = (int)qv('SELECT COUNT(*) FROM ai_jobs WHERE status = \'running\'', [], 0);
        $slots = min($max, $limit - $running);
        if ($slots <= 0) {
            return 'eşzamanlılık sınırı dolu (' . $running . '/' . $limit . '), iş alınmadı';
        }

        $rows = qa('SELECT id FROM ai_jobs WHERE status = \'queued\' ORDER BY id ASC LIMIT ' . (int)$slots);
        if (!$rows) { return 'bekleyen iş yok'; }
    } catch (Throwable $e) {
        log_error('ai_jobs_tick hazırlık: ' . $e->getMessage());
        return 'kuyruk okunamadı: ' . $e->getMessage();
    }

    $done = 0;
    $ok = 0;
    $err = 0;
    foreach ($rows as $r) {
        $res = ai_job_run((int)$r['id']);
        if (!empty($res['skipped'])) { continue; }   // başka bir tur işi kapmış
        $done++;
        if (!empty($res['ok'])) { $ok++; } else { $err++; }
    }

    if ($done === 0) { return 'işlenecek iş kalmadı (yarış koşulu)'; }
    return $done . ' iş işlendi, ' . $ok . ' başarılı, ' . $err . ' hata';
}

/**
 * İşi atomik olarak sahiplenir (yarış koşulu kalkanı).
 * İki cron aynı anda çalışsa bile UPDATE … WHERE status='queued' yalnız birinde
 * 1 satır etkiler; diğeri false alır ve işi atlar.
 */
function ai_job_claim($jobId) {
    $n = q('UPDATE ai_jobs SET status = \'running\', started_at = :t, attempts = attempts + 1, finished_at = NULL
            WHERE id = :i AND status = \'queued\'',
        [':t' => now(), ':i' => (int)$jobId])->rowCount();
    return $n === 1;
}

/**
 * Tek işi işler: sahiplen → yeniden yaz → haber oluştur.
 *
 * @return array ['ok'=>bool, 'post_id'=>int, 'error'=>string, 'skipped'=>bool]
 */
function ai_job_run($jobId) {
    $jobId = (int)$jobId;
    $fail = function ($msg, $permanent = false) use ($jobId) {
        return ai_job_mark_failed($jobId, $msg, $permanent);
    };

    try {
        $job = q1('SELECT * FROM ai_jobs WHERE id = :i', [':i' => $jobId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'post_id' => 0, 'error' => 'Kuyruk okunamadı: ' . $e->getMessage(), 'skipped' => false];
    }
    if (!$job) {
        return ['ok' => false, 'post_id' => 0, 'error' => 'İş bulunamadı.', 'skipped' => false];
    }
    if (!ai_configured()) {
        return ['ok' => false, 'post_id' => 0,
                'error' => 'Yapay zekâ yapılandırılmadı. Panel → Yapay Zekâ ekranından sağlayıcı ve API anahtarı girin.',
                'skipped' => true];
    }
    if (!ai_job_claim($jobId)) {
        $st = (string)qv('SELECT status FROM ai_jobs WHERE id = :i', [':i' => $jobId], '');
        return ['ok' => false, 'post_id' => 0,
                'error' => 'İş kuyrukta değil (durum: ' . ($st !== '' ? $st : 'bilinmiyor') . ').', 'skipped' => true];
    }

    try {
        $item = q1('SELECT * FROM rss_items WHERE id = :i', [':i' => (int)$job['rss_item_id']]);
        if (!$item) { return $fail('Kaynak öge bulunamadı (rss_items #' . (int)$job['rss_item_id'] . ').', true); }

        $res = ai_rewrite_item($item);
        if (empty($res['ok'])) { return $fail((string)arr($res, 'error', 'Yeniden yazım başarısız.')); }

        $created = ai_create_post_from_result($item, $res);
        if (empty($created['ok'])) {
            // Kaynak künyesi eksikse yeniden denemek anlamsızdır → kalıcı hata
            return $fail((string)arr($created, 'error', 'Haber oluşturulamadı.'), true);
        }

        $postId = (int)arr($created, 'post_id', 0);
        q('UPDATE ai_jobs SET status = \'done\', result_post_id = :p, error = \'\', finished_at = :f WHERE id = :i',
            [':p' => $postId, ':f' => now(), ':i' => $jobId]);
        q('UPDATE rss_items SET status = \'processed\' WHERE id = :i', [':i' => (int)$item['id']]);

        return ['ok' => true, 'post_id' => $postId, 'error' => '', 'skipped' => false];
    } catch (Throwable $e) {
        log_error('ai_job_run #' . $jobId . ': ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
        return $fail('Beklenmeyen hata: ' . $e->getMessage());
    }
}

/**
 * Başarısız işi işaretler.
 * Deneme hakkı kaldıysa ve hata geçiciyse iş tekrar 'queued' olur;
 * hak bittiyse ya da hata kalıcıysa 'failed' olur.
 */
function ai_job_mark_failed($jobId, $error, $permanent = false) {
    $jobId = (int)$jobId;
    $error = sanitize_line($error, 480);
    $row = q1('SELECT attempts, rss_item_id FROM ai_jobs WHERE id = :i', [':i' => $jobId]);
    $attempts = (int)arr($row, 'attempts', 0);
    $final = $permanent || $attempts >= AI_JOB_MAX_ATTEMPTS;
    try {
        if ($final) {
            q('UPDATE ai_jobs SET status = \'failed\', error = :e, finished_at = :f WHERE id = :i',
                [':e' => $error, ':f' => now(), ':i' => $jobId]);
            // Öge sonsuza dek 'queued' kalmasın; "Yeniden dene" ile tekrar işlenebilir.
            q('UPDATE rss_items SET status = \'skipped\' WHERE id = :i AND status = \'queued\'',
                [':i' => (int)arr($row, 'rss_item_id', 0)]);
        } else {
            q('UPDATE ai_jobs SET status = \'queued\', error = :e, finished_at = NULL WHERE id = :i',
                [':e' => $error, ':i' => $jobId]);
        }
    } catch (Throwable $e) {
        log_error('ai_job_mark_failed #' . $jobId . ': ' . $e->getMessage());
    }
    log_error('ai_jobs #' . $jobId . ' hata (' . $attempts . '/' . AI_JOB_MAX_ATTEMPTS . '): ' . $error);
    return ['ok' => false, 'post_id' => 0, 'error' => $error, 'skipped' => false];
}

// ============================================================ yeniden yazım

/**
 * RSS ögesini yapay zekâya yeniden yazdırır ve alanları doğrular.
 *
 * @param array $rssItem rss_items satırı
 * @return array ['ok','title','spot','body','seo_title','seo_desc','tags'=>[…],'error']
 */
function ai_rewrite_item(array $rssItem) {
    $out = ['ok' => false, 'title' => '', 'spot' => '', 'body' => '',
            'seo_title' => '', 'seo_desc' => '', 'tags' => [], 'error' => ''];

    $source = ai_source_of_item($rssItem);
    $categoryName = '';
    $catId = (int)arr($source, 'category_id', 0);
    if ($catId > 0) {
        $categoryName = (string)qv('SELECT name FROM categories WHERE id = :i', [':i' => $catId], '');
    }

    $raw = (string)arr($rssItem, 'content', '');
    if (trim(strip_tags($raw)) === '') { $raw = (string)arr($rssItem, 'summary', ''); }
    $raw = ai_text_for_prompt($raw, 10000);
    $title = (string)arr($rssItem, 'title', '');

    if (trim($raw) === '' && trim($title) === '') {
        $out['error'] = 'Kaynak ögede yeniden yazılacak metin yok.';
        return $out;
    }

    // Yer tutucular ai_prompt() içinde ai_neutralize() ile süzülür (prompt enjeksiyonu kalkanı).
    $prompt = ai_prompt('ai_prompt_rewrite', [
        'baslik'   => $title,
        'icerik'   => $raw,
        'kategori' => $categoryName,
        'kaynak'   => (string)arr($source, 'name', ''),
    ]);
    $prompt .= "\n\n" . ai_json_instruction();

    $res = ai_complete($prompt, [
        'action'     => 'rss_rewrite',
        'system'     => ai_rewrite_system_prompt(),
        'max_tokens' => 2200,
        'temperature' => 0.4,
    ]);
    if (empty($res['ok'])) {
        $out['error'] = (string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.');
        return $out;
    }

    $data = ai_extract_json((string)arr($res, 'text', ''));
    if (!is_array($data)) {
        // Ham metni gövdeye basmak yasak: JSON çözülemediyse iş hatadır.
        $out['error'] = 'Model yanıtı beklenen JSON biçiminde değil, içerik oluşturulmadı.';
        return $out;
    }

    return ai_validate_rewrite($data);
}

/**
 * Model çıktısındaki alanları doğrular ve temizler.
 * title 10–200, spot 20–500, body ≥ 100 karakter, tags en çok 5 öge.
 */
function ai_validate_rewrite(array $data) {
    $out = ['ok' => false, 'title' => '', 'spot' => '', 'body' => '',
            'seo_title' => '', 'seo_desc' => '', 'tags' => [], 'error' => ''];

    $title = sanitize_line((string)arr($data, 'title', ''), 200);
    $spot  = trim(preg_replace('/\s+/u', ' ', sanitize_plain((string)arr($data, 'spot', ''), 600)));
    // iframe yok: yapay zekâ gövdesine gömme izni verilmez
    $body  = sanitize_html((string)arr($data, 'body', ''), false);

    if (mb_strlen($title) < 10 || mb_strlen($title) > 200) {
        $out['error'] = 'Üretilen başlık geçersiz (10–200 karakter olmalı, gelen: ' . mb_strlen($title) . ').';
        return $out;
    }
    if (mb_strlen($spot) < 20 || mb_strlen($spot) > 500) {
        $out['error'] = 'Üretilen spot geçersiz (20–500 karakter olmalı, gelen: ' . mb_strlen($spot) . ').';
        return $out;
    }
    if (mb_strlen(trim(strip_tags($body))) < 100) {
        $out['error'] = 'Üretilen gövde çok kısa (en az 100 karakter olmalı).';
        return $out;
    }

    $tags = array_slice(tags_to_array(arr($data, 'tags', [])), 0, 5);

    $seoTitle = sanitize_line((string)arr($data, 'seo_title', ''), 255);
    if ($seoTitle === '') { $seoTitle = mb_substr($title, 0, 60); }
    $seoDesc = trim(preg_replace('/\s+/u', ' ', sanitize_plain((string)arr($data, 'seo_desc', ''), 500)));
    if ($seoDesc === '') { $seoDesc = excerpt($spot, 155); }

    return [
        'ok' => true, 'title' => $title, 'spot' => $spot, 'body' => $body,
        'seo_title' => $seoTitle, 'seo_desc' => $seoDesc, 'tags' => $tags, 'error' => '',
    ];
}

// ============================================================ haber oluşturma

/**
 * Yeniden yazım sonucundan haber kaydı üretir.
 * Kaynak adı ve bağlantısı ZORUNLUDUR; yoksa haber oluşturulmaz.
 *
 * @return array ['ok'=>bool, 'post_id'=>int, 'error'=>string]
 */
function ai_create_post_from_result(array $rssItem, array $result) {
    $source = ai_source_of_item($rssItem);
    $sourceName = sanitize_line((string)arr($source, 'name', ''), 190);
    $sourceUrl  = trim((string)arr($rssItem, 'link', ''));
    if (!preg_match('#^https?://#i', $sourceUrl) || !sanitize_is_safe_url($sourceUrl, false)) { $sourceUrl = ''; }
    if (mb_strlen($sourceUrl) > 500) { $sourceUrl = ''; }

    if ($sourceName === '' || $sourceUrl === '') {
        return ['ok' => false, 'post_id' => 0,
                'error' => 'Kaynak bilgisi olmadan yapay zekâ haberi oluşturulamaz.'];
    }

    $title = sanitize_line((string)arr($result, 'title', ''), 200);
    if ($title === '') { return ['ok' => false, 'post_id' => 0, 'error' => 'Başlık boş; haber oluşturulmadı.']; }

    // Gövde sanitize_html()'den geçmiş hâlde gelir; kaynak bloğu ondan SONRA eklenir
    // ki künye bağlantısı ve sınıf adı korunsun.
    $body = (string)arr($result, 'body', '') . "\n" . ai_source_block($sourceName, $sourceUrl);

    $catId = (int)arr($source, 'category_id', 0);
    $authorId = ai_first_admin_id();
    $autoPublish = (int)arr($source, 'auto_publish', 0) === 1;
    $status = $autoPublish ? 'published' : 'draft';

    $image = trim((string)arr($rssItem, 'image', ''));
    if ($image !== '' && (!sanitize_is_safe_url($image, false) || mb_strlen($image) > 255)) { $image = ''; }

    try {
        $postId = db_insert('posts', [
            'title'         => $title,
            'slug'          => unique_slug('posts', $title),
            'spot'          => (string)arr($result, 'spot', ''),
            'body'          => $body,
            'image'         => $image,
            'category_id'   => $catId,
            'author_id'     => $authorId,
            'status'        => $status,
            'type'          => 'haber',
            'source_name'   => $sourceName,
            'source_url'    => $sourceUrl,
            'ai_generated'  => 1,
            'seo_title'     => sanitize_line((string)arr($result, 'seo_title', ''), 255),
            'seo_desc'      => sanitize_line((string)arr($result, 'seo_desc', ''), 500),
            'tags'          => tags_to_string(arr($result, 'tags', [])),
            'view_count'    => 0,
            'is_headline'   => 0,
            'headline_sort' => 0,
            'is_breaking'   => 0,
            'published_at'  => $autoPublish ? now() : null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    } catch (Throwable $e) {
        log_error('ai_create_post_from_result: ' . $e->getMessage());
        return ['ok' => false, 'post_id' => 0, 'error' => 'Haber kaydedilemedi: ' . $e->getMessage()];
    }

    if ($postId <= 0) { return ['ok' => false, 'post_id' => 0, 'error' => 'Haber kaydedilemedi.']; }
    if (function_exists('cache_flush')) { cache_flush(); }

    return ['ok' => true, 'post_id' => (int)$postId, 'error' => ''];
}

/** Gövde sonuna eklenen kaynak künyesi (CONTRACTS §6). */
function ai_source_block($sourceName, $sourceUrl) {
    return '<p class="kaynak">Kaynak: <a href="' . esc($sourceUrl)
         . '" rel="nofollow noopener" target="_blank">' . esc($sourceName) . '</a></p>';
}

/** RSS ögesinin kaynak satırı (rss_sources) ya da boş dizi. */
function ai_source_of_item(array $rssItem) {
    $sid = (int)arr($rssItem, 'source_id', 0);
    if ($sid <= 0) { return []; }
    try {
        $row = q1('SELECT * FROM rss_sources WHERE id = :i', [':i' => $sid]);
    } catch (Throwable $e) { return []; }
    return is_array($row) ? $row : [];
}

/** Cron'da oturum olmadığından yazar olarak ilk etkin yönetici kullanılır. */
function ai_first_admin_id() {
    try {
        $id = (int)qv('SELECT id FROM users WHERE role = \'admin\' AND active = 1 ORDER BY id ASC', [], 0);
        if ($id > 0) { return $id; }
        return (int)qv('SELECT id FROM users WHERE active = 1 ORDER BY id ASC', [], 0);
    } catch (Throwable $e) { return 0; }
}

/** HTML gövdeyi isteme uygun düz metne indirger (uzunluk kırpması dâhil). */
function ai_text_for_prompt($html, $max = 6000) {
    $t = strip_tags((string)$html);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $t = trim(preg_replace('/[ \t]+/u', ' ', preg_replace('/(\r\n|\r)/', "\n", $t)));
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    if (mb_strlen($t) > $max) { $t = mb_substr($t, 0, $max) . '…'; }
    return $t;
}

// ============================================================ kuyruk listeleme

/** Filtre dizisinden WHERE parçası ve parametreler üretir. */
function ai_jobs_where(array $filters) {
    $w = ['1 = 1'];
    $p = [];
    $status = (string)arr($filters, 'status', '');
    if ($status !== '' && in_array($status, ai_job_statuses(), true)) {
        $w[] = 'j.status = :status';
        $p[':status'] = $status;
    }
    $kind = (string)arr($filters, 'kind', '');
    if ($kind !== '' && in_array($kind, ai_job_kinds(), true)) {
        $w[] = 'j.kind = :kind';
        $p[':kind'] = $kind;
    }
    $itemId = (int)arr($filters, 'rss_item_id', 0);
    if ($itemId > 0) {
        $w[] = 'j.rss_item_id = :rid';
        $p[':rid'] = $itemId;
    }
    return [implode(' AND ', $w), $p];
}

/**
 * Panel için iş listesi (kaynak öge başlığı ve üretilen haber bilgisiyle).
 * @return array satır dizisi
 */
function ai_jobs_list(array $filters = [], $limit = 40, $offset = 0) {
    list($where, $params) = ai_jobs_where($filters);
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    try {
        return qa('SELECT j.*, i.title AS item_title, i.link AS item_link, i.status AS item_status,
                          s.name AS source_name, p.title AS post_title, p.status AS post_status
                   FROM ai_jobs j
                   LEFT JOIN rss_items i ON i.id = j.rss_item_id
                   LEFT JOIN rss_sources s ON s.id = i.source_id
                   LEFT JOIN posts p ON p.id = j.result_post_id
                   WHERE ' . $where . '
                   ORDER BY j.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
    } catch (Throwable $e) {
        log_error('ai_jobs_list: ' . $e->getMessage());
        return [];
    }
}

/** Filtreye uyan iş sayısı. */
function ai_jobs_count(array $filters = []) {
    list($where, $params) = ai_jobs_where($filters);
    try {
        return (int)qv('SELECT COUNT(*) FROM ai_jobs j WHERE ' . $where, $params, 0);
    } catch (Throwable $e) { return 0; }
}

/** Duruma göre sayaçlar: ['queued','running','done','failed','total']. */
function ai_jobs_stats() {
    $out = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'total' => 0];
    try {
        foreach (qa('SELECT status, COUNT(*) AS n FROM ai_jobs GROUP BY status') as $r) {
            $s = (string)$r['status'];
            if (isset($out[$s])) { $out[$s] = (int)$r['n']; }
            $out['total'] += (int)$r['n'];
        }
    } catch (Throwable $e) { /* tablo yoksa sıfır */ }
    return $out;
}

/** Başarısız işi yeniden kuyruğa alır (deneme sayacı sıfırlanır). */
function ai_job_retry($jobId) {
    $jobId = (int)$jobId;
    $job = q1('SELECT id, status FROM ai_jobs WHERE id = :i', [':i' => $jobId]);
    if (!$job) { return ['ok' => false, 'error' => 'İş bulunamadı.', 'message' => '']; }
    if ($job['status'] === 'running') {
        return ['ok' => false, 'error' => 'İş şu anda çalışıyor; bitmesini bekleyin.', 'message' => ''];
    }
    if ($job['status'] === 'done') {
        return ['ok' => false, 'error' => 'Tamamlanmış iş yeniden çalıştırılamaz (haber ikinci kez üretilirdi).', 'message' => ''];
    }
    q('UPDATE ai_jobs SET status = \'queued\', attempts = 0, error = \'\', started_at = NULL, finished_at = NULL WHERE id = :i',
        [':i' => $jobId]);
    q('UPDATE rss_items SET status = \'queued\' WHERE id = :i AND status = \'skipped\'',
        [':i' => (int)qv('SELECT rss_item_id FROM ai_jobs WHERE id = :i', [':i' => $jobId], 0)]);
    return ['ok' => true, 'error' => '', 'message' => 'İş yeniden kuyruğa alındı.'];
}

/** İşi siler (üretilmiş haber silinmez). */
function ai_job_delete($jobId) {
    $jobId = (int)$jobId;
    $job = q1('SELECT id, status FROM ai_jobs WHERE id = :i', [':i' => $jobId]);
    if (!$job) { return ['ok' => false, 'error' => 'İş bulunamadı.', 'message' => '']; }
    if ($job['status'] === 'running') {
        return ['ok' => false, 'error' => 'Çalışan iş silinemez.', 'message' => ''];
    }
    q('DELETE FROM ai_jobs WHERE id = :i', [':i' => $jobId]);
    return ['ok' => true, 'error' => '', 'message' => 'İş silindi.'];
}

// ============================================================ günlükler

/** Son çağrı günlükleri. */
function ai_logs_list($limit = 50, $offset = 0) {
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    try {
        return qa('SELECT * FROM ai_logs ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    } catch (Throwable $e) { return []; }
}

/** Toplam günlük kaydı sayısı. */
function ai_logs_count() {
    try { return (int)qv('SELECT COUNT(*) FROM ai_logs', [], 0); } catch (Throwable $e) { return 0; }
}

/**
 * Son $days günün özeti.
 * @return array ['calls','tokens_in','tokens_out','errors','avg_ms']
 */
function ai_logs_summary($days = 30) {
    $days = max(1, min(365, (int)$days));
    $cut = date('Y-m-d H:i:s', time() - $days * 86400);
    $empty = ['calls' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'errors' => 0, 'avg_ms' => 0];
    try {
        $r = q1('SELECT COUNT(*) AS calls,
                        COALESCE(SUM(tokens_in), 0) AS tokens_in,
                        COALESCE(SUM(tokens_out), 0) AS tokens_out,
                        COALESCE(SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END), 0) AS errors,
                        COALESCE(AVG(duration_ms), 0) AS avg_ms
                 FROM ai_logs WHERE created_at >= :c', [':c' => $cut]);
    } catch (Throwable $e) { return $empty; }
    if (!$r) { return $empty; }
    return [
        'calls'      => (int)$r['calls'],
        'tokens_in'  => (int)$r['tokens_in'],
        'tokens_out' => (int)$r['tokens_out'],
        'errors'     => (int)$r['errors'],
        'avg_ms'     => (int)round((float)$r['avg_ms']),
    ];
}

// ============================================================ maliyet

/** 1M jeton başına giriş fiyatı (boşsa null). */
function ai_price_in() {
    $v = trim((string)setting('ai_price_in', ''));
    return ($v === '' || !is_numeric($v)) ? null : (float)$v;
}

/** 1M jeton başına çıkış fiyatı (boşsa null). */
function ai_price_out() {
    $v = trim((string)setting('ai_price_out', ''));
    return ($v === '' || !is_numeric($v)) ? null : (float)$v;
}

/**
 * Birim fiyatlar girilmişse tahmini tutarı hesaplar, girilmemişse null döner.
 * Fiyat sabitlenmez; değer tamamen yayıncının girdiğine dayanır.
 */
function ai_estimated_cost($tokensIn, $tokensOut) {
    $pi = ai_price_in();
    $po = ai_price_out();
    if ($pi === null && $po === null) { return null; }
    $cost = ((int)$tokensIn / 1000000) * ($pi === null ? 0 : $pi)
          + ((int)$tokensOut / 1000000) * ($po === null ? 0 : $po);
    return round($cost, 4);
}

// ============================================================ editör yardımcıları
// (Ajan-2'nin haber editörü bu üç işlevi ai.* uçları üzerinden çağırır.)

/**
 * Başlık önerileri. Model yanıtı satır satır ayrıştırılır; numaralı liste temizlenir.
 * @return array ['ok'=>bool, 'items'=>string[], 'error'=>string]
 */
function ai_suggest_titles($title, $body, $category = '') {
    $prompt = ai_prompt('ai_prompt_title', [
        'baslik'   => (string)$title,
        'icerik'   => ai_text_for_prompt($body, 6000),
        'kategori' => (string)$category,
        'kaynak'   => '',
    ]);
    // Şablon düzenlenirken {baslik} silinmiş olabilir; başlık her hâlükârda etiketli
    // olarak eklenir. ai_neutralize() burada da elle uygulanır (ham metin girmez).
    $prompt .= "\n\nÖzgün başlık: " . ai_neutralize((string)$title)
             . "\n\nHer öneriyi ayrı bir satıra yaz, başka açıklama ekleme.";

    $res = ai_complete($prompt, [
        'action' => 'suggest_title', 'system' => ai_rewrite_system_prompt(),
        'max_tokens' => 500, 'temperature' => 0.6,
    ]);
    if (empty($res['ok'])) {
        return ['ok' => false, 'items' => [], 'error' => (string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')];
    }

    $items = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)arr($res, 'text', '')) as $line) {
        $line = trim($line);
        if ($line === '') { continue; }
        // '1.' '1)' '- ' '* ' gibi liste imlerini temizle
        $line = preg_replace('/^\s*(\d{1,2}\s*[\.\)\-–:]|[-–*•])\s*/u', '', $line);
        $line = trim($line, " \t\"'“”«»");
        $line = sanitize_line($line, 200);
        if (mb_strlen($line) < 8) { continue; }
        if (in_array($line, $items, true)) { continue; }
        $items[] = $line;
        if (count($items) >= 5) { break; }
    }

    if (!$items) {
        return ['ok' => false, 'items' => [], 'error' => 'Model kullanılabilir bir başlık önerisi üretmedi.'];
    }
    return ['ok' => true, 'items' => $items, 'error' => ''];
}

/**
 * Spot (özet) önerisi.
 * @return array ['ok'=>bool, 'spot'=>string, 'error'=>string]
 */
function ai_suggest_spot($title, $body, $category = '') {
    $prompt = ai_prompt('ai_prompt_spot', [
        'baslik'   => (string)$title,
        'icerik'   => ai_text_for_prompt($body, 6000),
        'kategori' => (string)$category,
        'kaynak'   => '',
    ]);
    $prompt .= "\n\nÖzgün başlık: " . ai_neutralize((string)$title)
             . "\n\nYalnızca spot metnini yaz; başlık, tırnak ya da açıklama ekleme.";

    $res = ai_complete($prompt, [
        'action' => 'suggest_spot', 'system' => ai_rewrite_system_prompt(),
        'max_tokens' => 400, 'temperature' => 0.5,
    ]);
    if (empty($res['ok'])) {
        return ['ok' => false, 'spot' => '', 'error' => (string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')];
    }

    $spot = trim(preg_replace('/\s+/u', ' ', sanitize_plain((string)arr($res, 'text', ''), 800)));
    $spot = trim($spot, " \t\"'“”«»");
    if (mb_strlen($spot) > 500) { $spot = excerpt($spot, 490); }
    if (mb_strlen($spot) < 20) {
        return ['ok' => false, 'spot' => '', 'error' => 'Model kullanılabilir bir spot üretmedi.'];
    }
    return ['ok' => true, 'spot' => $spot, 'error' => ''];
}

/**
 * SEO başlığı, açıklaması ve etiketler.
 * Yanıt JSON değilse metinden satır bazlı çıkarım yapılır.
 * @return array ['ok'=>bool, 'seo_title'=>string, 'seo_desc'=>string, 'tags'=>string[], 'error'=>string]
 */
function ai_suggest_seo($title, $body, $category = '') {
    $prompt = ai_prompt('ai_prompt_seo', [
        'baslik'   => (string)$title,
        'icerik'   => ai_text_for_prompt($body, 6000),
        'kategori' => (string)$category,
        'kaynak'   => '',
    ]);
    $prompt .= "\n\nÖzgün başlık: " . ai_neutralize((string)$title)
             . "\n\n" . 'Yanıtı YALNIZCA şu JSON biçiminde ver: {"seo_title":"…","seo_desc":"…","tags":["…"]}';

    $res = ai_complete($prompt, [
        'action' => 'fill_seo', 'system' => ai_rewrite_system_prompt(),
        'max_tokens' => 500, 'temperature' => 0.3,
    ]);
    if (empty($res['ok'])) {
        return ['ok' => false, 'seo_title' => '', 'seo_desc' => '', 'tags' => [],
                'error' => (string)arr($res, 'error', 'Sağlayıcıya ulaşılamadı.')];
    }

    $text = (string)arr($res, 'text', '');
    $data = ai_extract_json($text);
    if (!is_array($data)) { $data = ai_seo_from_text($text); }

    $seoTitle = sanitize_line((string)arr($data, 'seo_title', ''), 255);
    $seoDesc  = trim(preg_replace('/\s+/u', ' ', sanitize_plain((string)arr($data, 'seo_desc', ''), 500)));
    $tags     = array_slice(tags_to_array(arr($data, 'tags', [])), 0, 5);

    if ($seoTitle === '' && $seoDesc === '' && !$tags) {
        return ['ok' => false, 'seo_title' => '', 'seo_desc' => '', 'tags' => [],
                'error' => 'Model yanıtından SEO alanları çıkarılamadı.'];
    }
    return ['ok' => true, 'seo_title' => $seoTitle, 'seo_desc' => $seoDesc, 'tags' => $tags, 'error' => ''];
}

/** JSON gelmediğinde düz metinden SEO alanlarını çıkarmayı dener. */
function ai_seo_from_text($text) {
    $out = ['seo_title' => '', 'seo_desc' => '', 'tags' => []];
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $l) {
        $l = trim($l);
        if ($l !== '') { $lines[] = $l; }
    }
    foreach ($lines as $l) {
        if ($out['seo_title'] === '' && preg_match('/^[^:]*(seo\s*)?ba[şs]l[ıi]k[^:]*:\s*(.+)$/iu', $l, $m)) {
            $out['seo_title'] = trim($m[2], " \t\"'“”");
            continue;
        }
        if ($out['seo_desc'] === '' && preg_match('/^[^:]*(a[çc][ıi]klama|description|meta)[^:]*:\s*(.+)$/iu', $l, $m)) {
            $out['seo_desc'] = trim($m[2], " \t\"'“”");
            continue;
        }
        if (!$out['tags'] && preg_match('/^[^:]*(etiket|tag)[^:]*:\s*(.+)$/iu', $l, $m)) {
            $out['tags'] = tags_to_array($m[2]);
        }
    }
    // Etiketli satır yoksa sırayla ilk satırlara düş
    if ($out['seo_title'] === '' && isset($lines[0])) { $out['seo_title'] = trim($lines[0], " \t\"'“”"); }
    if ($out['seo_desc'] === '' && isset($lines[1])) { $out['seo_desc'] = trim($lines[1], " \t\"'“”"); }
    if (!$out['tags'] && isset($lines[2]) && strpos($lines[2], ',') !== false) {
        $out['tags'] = tags_to_array($lines[2]);
    }
    return $out;
}
