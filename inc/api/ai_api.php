<?php
/**
 * Manşet — yapay zekâ uç noktaları (ai.*) — Ajan-5.
 *
 * Kayıtlı uçlar:
 *   ai.save_settings  ai.save_prompts  ai.reset_prompt  ai.test        (perm: ai.configure)
 *   ai.jobs  ai.run_now  ai.retry  ai.delete_job  ai.enqueue  ai.logs  (perm: ai.use)
 *   ai.suggest_title  ai.suggest_spot  ai.fill_seo                     (perm: ai.use)
 *
 * API anahtarı hiçbir uçtan ham dönmez; yalnız ai_api_key_masked().
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/schema.php';
require_once INC_DIR . '/ai.php';
require_once INC_DIR . '/ai_jobs.php';

// ============================================================ yardımcılar

/** Yapay zekâ yapılandırılmamışsa kullanıcı hatası olarak yanıtla (500 değil). */
function ai_api_require_configured() {
    if (!ai_configured()) {
        json_err('Yapay zekâ yapılandırılmadı. Panel → Yapay Zekâ ekranından sağlayıcı ve API anahtarı girin.', 400);
    }
}

/** Gövde alanını (JSON ya da form) okur. */
function ai_api_field($key, $default = '') {
    $b = json_body();
    if (array_key_exists($key, $b)) { return $b[$key]; }
    return inp($key, $default);
}

/** Gövde alanı gönderilmiş mi? (Gönderilmeyen ayar değiştirilmez.) */
function ai_api_has($key) {
    $b = json_body();
    return array_key_exists($key, $b) || isset($_POST[$key]) || isset($_GET[$key]);
}

/** İstek gövdesinden iş id'si. */
function ai_api_id() {
    return (int)ai_api_field('id', 0);
}

/** İstem şablonu metnini temizler (etiketler korunur, denetim karakterleri atılır). */
function ai_api_clean_prompt($text) {
    $t = (string)$text;
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
    $t = trim($t);
    if (mb_strlen($t) > 8000) { $t = mb_substr($t, 0, 8000); }
    return $t;
}

/** Editör uçlarının ortak girdisi: başlık / gövde / kategori. */
function ai_api_editor_input() {
    return [
        'title'    => sanitize_line((string)ai_api_field('title', ''), 255),
        // Gövde HTML olabilir: etiketler atılır, uzunluk kırpılır.
        // (ai_prompt() ayrıca ai_neutralize() uygular — prompt enjeksiyonu kalkanı.)
        'body'     => ai_text_for_prompt((string)ai_api_field('body', ''), 6000),
        'category' => sanitize_line((string)ai_api_field('category', ''), 120),
    ];
}

/** Panelde/JS'te kullanılacak iş satırı. */
function ai_api_job_row(array $j) {
    return [
        'id'          => (int)$j['id'],
        'rss_item_id' => (int)arr($j, 'rss_item_id', 0),
        'kind'        => (string)arr($j, 'kind', ''),
        'status'      => (string)arr($j, 'status', ''),
        'attempts'    => (int)arr($j, 'attempts', 0),
        'item_title'  => (string)arr($j, 'item_title', ''),
        'source_name' => (string)arr($j, 'source_name', ''),
        'post_id'     => (int)arr($j, 'result_post_id', 0),
        'post_title'  => (string)arr($j, 'post_title', ''),
        'error'       => (string)arr($j, 'error', ''),
        'retry_after' => (string)arr($j, 'retry_after', ''),
        'created_at'  => (string)arr($j, 'created_at', ''),
        'started_at'  => (string)arr($j, 'started_at', ''),
        'finished_at' => (string)arr($j, 'finished_at', ''),
    ];
}

// ============================================================ ayarlar

api_register('ai.save_settings', function () {
    $save = [];

    if (ai_api_has('provider')) {
        $p = (string)ai_api_field('provider', 'anthropic');
        $save['ai_provider'] = in_array($p, ['anthropic', 'openai_uyumlu'], true) ? $p : 'anthropic';
    }
    if (ai_api_has('model')) {
        $save['ai_model'] = sanitize_line((string)ai_api_field('model', ''), 120);
    }
    if (ai_api_has('base_url')) {
        $u = trim((string)ai_api_field('base_url', ''));
        if ($u !== '' && !preg_match('#^https?://[^\s<>"]{3,240}$#i', $u)) {
            json_err('Taban adres geçersiz. http:// ya da https:// ile başlamalı.', 400);
        }
        $save['ai_base_url'] = $u === '' ? '' : rtrim($u, '/');
    }
    if (ai_api_has('timeout')) {
        $save['ai_timeout'] = (string)max(5, min(180, (int)ai_api_field('timeout', 45)));
    }
    if (ai_api_has('max_concurrent')) {
        $save['ai_max_concurrent'] = (string)max(1, min(8, (int)ai_api_field('max_concurrent', 2)));
    }
    if (ai_api_has('enabled')) {
        $v = ai_api_field('enabled', '0');
        $save['ai_enabled'] = ($v === '1' || $v === 1 || $v === true) ? '1' : '0';
    }

    // Aylık bütçe tavanı (0 = sınırsız). Tavan aşılınca yeni çağrı yapılmaz,
    // kuyruk durur ama iş kaybolmaz (1.1-07).
    if (ai_api_has('monthly_budget')) {
        $v = trim(str_replace(',', '.', (string)ai_api_field('monthly_budget', '')));
        if ($v === '') { $v = '0'; }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 10000000) {
            json_err('Aylık bütçe sayı olmalı (0 = sınırsız).', 400);
        }
        $save['ai_monthly_budget'] = (string)(float)$v;
    }

    /*
     * Aylık ÇAĞRI tavanı (B08). NEDEN: para cinsinden tavan yalnız birim fiyat
     * girilmişse ölçülebiliyor; varsayılan kurulumda hiç korumuyordu. Çağrı
     * sayısı ai_logs'tan fiyat gerektirmeden sayılır, bu yüzden her kurulumda
     * çalışan ikinci bir tavandır. 0 = sınırsız.
     */
    if (ai_api_has('monthly_calls')) {
        $v = trim((string)ai_api_field('monthly_calls', ''));
        if ($v === '') { $v = (string)AI_MONTHLY_CALLS_DEFAULT; }
        if (!ctype_digit($v) || (int)$v > 1000000) {
            json_err('Aylık çağrı tavanı 0 ile 1.000.000 arasında bir tam sayı olmalı (0 = sınırsız).', 400);
        }
        $save['ai_monthly_calls'] = (string)(int)$v;
    }

    // Birim fiyatlar (boş bırakılabilir; fiyat sabitlenmez)
    foreach (['price_in' => 'ai_price_in', 'price_out' => 'ai_price_out'] as $in => $key) {
        if (!ai_api_has($in)) { continue; }
        $v = trim(str_replace(',', '.', (string)ai_api_field($in, '')));
        if ($v === '') { $save[$key] = ''; continue; }
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100000) {
            json_err('Birim fiyat sayı olmalı (örn. 3 ya da 15.5).', 400);
        }
        $save[$key] = (string)(float)$v;
    }

    // Anahtar: silme onayı varsa temizlenir, boş gönderilirse mevcut korunur.
    $clear = ai_api_field('clear_key', '');
    if ($clear === '1' || $clear === 1 || $clear === true) {
        $save['ai_api_key'] = '';
    } elseif (ai_api_has('api_key')) {
        $k = trim((string)ai_api_field('api_key', ''));
        if ($k !== '') {
            if (!preg_match('/^[\x21-\x7E]{8,300}$/', $k)) {
                json_err('API anahtarı geçersiz görünüyor (boşluksuz, 8–300 karakter olmalı).', 400);
            }
            $save['ai_api_key'] = $k;
        }
    }

    if ($save) { setting_set_many($save); settings_all(true); }

    return [
        'message'    => 'Yapay zekâ ayarları kaydedildi.',
        'status'     => ai_status_text(),
        'provider'   => ai_provider(),
        'model'      => ai_model(),
        'key_masked' => ai_api_key_masked(),
        'budget'     => ai_budget_status(),
    ];
}, ['perm' => 'ai.configure', 'methods' => ['POST']]);

api_register('ai.save_prompts', function () {
    $keys = ['ai_prompt_rewrite', 'ai_prompt_title', 'ai_prompt_spot', 'ai_prompt_seo'];
    $save = [];
    foreach ($keys as $k) {
        if (!ai_api_has($k)) { continue; }
        $v = ai_api_clean_prompt(ai_api_field($k, ''));
        if ($v === '') { json_err('İstem şablonu boş bırakılamaz: ' . $k, 400); }
        $save[$k] = $v;
    }
    if (!$save) { json_err('Kaydedilecek şablon gönderilmedi.', 400); }
    setting_set_many($save);
    settings_all(true);
    return ['message' => count($save) . ' istem şablonu kaydedildi.', 'saved' => array_keys($save)];
}, ['perm' => 'ai.configure', 'methods' => ['POST']]);

api_register('ai.reset_prompt', function () {
    $key = (string)ai_api_field('key', '');
    $allowed = ['ai_prompt_rewrite', 'ai_prompt_title', 'ai_prompt_spot', 'ai_prompt_seo'];
    if (!in_array($key, $allowed, true)) { json_err('Bilinmeyen şablon anahtarı.', 400); }

    $default = (string)arr(schema_default_settings(), $key, '');
    if ($default === '') { json_err('Bu şablon için varsayılan değer bulunamadı.', 400); }

    setting_set($key, $default);
    settings_all(true);
    return ['message' => 'Şablon varsayılana döndürüldü.', 'key' => $key, 'value' => $default];
}, ['perm' => 'ai.configure', 'methods' => ['POST']]);

api_register('ai.test', function () {
    $r = ai_test_connection();
    return [
        'ok'    => !empty($r['ok']),
        'text'  => (string)arr($r, 'text', ''),
        'ms'    => (int)arr($r, 'ms', 0),
        'error' => (string)arr($r, 'error', ''),
        'message' => !empty($r['ok']) ? 'Bağlantı başarılı.' : '',
    ];
}, ['perm' => 'ai.configure', 'methods' => ['POST']]);

// ============================================================ iş kuyruğu

api_register('ai.jobs', function () {
    $status = (string)ai_api_field('status', '');
    $filters = in_array($status, ai_job_statuses(), true) ? ['status' => $status] : [];
    $page = max(1, (int)ai_api_field('page', 1));
    $perPage = 30;

    $rows = ai_jobs_list($filters, $perPage, ($page - 1) * $perPage);
    $items = [];
    foreach ($rows as $r) { $items[] = ai_api_job_row($r); }

    return [
        'items' => $items,
        'total' => ai_jobs_count($filters),
        'page'  => $page,
        'stats' => ai_jobs_stats(),
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.run_now', function () {
    ai_api_require_configured();
    $id = ai_api_id();
    if ($id <= 0) { json_err('Geçersiz iş numarası.', 400); }

    // Elle çalıştırmada başarısız iş yeniden kuyruğa alınır (deneme sayacı korunur).
    $st = (string)qv('SELECT status FROM ai_jobs WHERE id = :i', [':i' => $id], '');
    if ($st === '') { json_err('İş bulunamadı.', 404); }
    if ($st === 'failed') {
        q('UPDATE ai_jobs SET status = \'queued\', error = \'\' WHERE id = :i AND status = \'failed\'', [':i' => $id]);
    }

    $r = ai_job_run($id);
    return [
        'ok'      => !empty($r['ok']),
        'post_id' => (int)arr($r, 'post_id', 0),
        'error'   => (string)arr($r, 'error', ''),
        'message' => !empty($r['ok']) ? 'Haber üretildi.' : '',
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.retry', function () {
    $id = ai_api_id();
    if ($id <= 0) { json_err('Geçersiz iş numarası.', 400); }
    $r = ai_job_retry($id);
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'İş yeniden kuyruğa alınamadı.'), 400); }
    return ['message' => (string)arr($r, 'message', 'İş yeniden kuyruğa alındı.'), 'id' => $id];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.delete_job', function () {
    $id = ai_api_id();
    if ($id <= 0) { json_err('Geçersiz iş numarası.', 400); }
    $r = ai_job_delete($id);
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'İş silinemedi.'), 400); }
    return ['message' => (string)arr($r, 'message', 'İş silindi.'), 'id' => $id];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.enqueue', function () {
    $itemId = (int)ai_api_field('rss_item_id', 0);
    if ($itemId <= 0) { json_err('Geçersiz RSS öge numarası.', 400); }

    $kind = (string)ai_api_field('kind', 'rss_rewrite');
    if (!in_array($kind, ai_job_kinds(), true)) { $kind = 'rss_rewrite'; }

    $id = ai_job_enqueue($itemId, $kind);
    if ($id <= 0) { json_err('İş kuyruğa eklenemedi. RSS ögesi bulunamadı olabilir.', 400); }

    return ['id' => $id, 'message' => 'İş kuyruğa eklendi (#' . $id . ').'];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.logs', function () {
    $page = max(1, (int)ai_api_field('page', 1));
    $perPage = 50;
    $rows = ai_logs_list($perPage, ($page - 1) * $perPage);
    $items = [];
    foreach ($rows as $l) {
        $items[] = [
            'id'          => (int)$l['id'],
            'created_at'  => (string)arr($l, 'created_at', ''),
            'action'      => (string)arr($l, 'action', ''),
            'provider'    => (string)arr($l, 'provider', ''),
            'model'       => (string)arr($l, 'model', ''),
            'tokens_in'   => (int)arr($l, 'tokens_in', 0),
            'tokens_out'  => (int)arr($l, 'tokens_out', 0),
            'duration_ms' => (int)arr($l, 'duration_ms', 0),
            'ok'          => (int)arr($l, 'ok', 0) === 1,
            'error'       => (string)arr($l, 'error', ''),
        ];
    }
    $summary = ai_logs_summary(30);
    $summary['cost'] = ai_estimated_cost($summary['tokens_in'], $summary['tokens_out']);

    return ['items' => $items, 'total' => ai_logs_count(), 'page' => $page,
            'summary' => $summary, 'budget' => ai_budget_status()];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

// ============================================================ editör yardımcıları
// Ajan-2'nin haber editörü bu üç ucu çağırır; yanıt biçimi sözleşmede sabittir.

api_register('ai.suggest_title', function () {
    ai_api_require_configured();
    $in = ai_api_editor_input();
    if ($in['title'] === '' && $in['body'] === '') {
        json_err('Başlık önerisi için haber başlığı ya da metni gerekiyor.', 400);
    }

    $r = ai_suggest_titles($in['title'], $in['body'], $in['category']);
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'Başlık önerisi alınamadı.'), 400); }

    return ['ok' => true, 'items' => array_values($r['items'])];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.suggest_spot', function () {
    ai_api_require_configured();
    $in = ai_api_editor_input();
    if ($in['title'] === '' && $in['body'] === '') {
        json_err('Spot önerisi için haber başlığı ya da metni gerekiyor.', 400);
    }

    $r = ai_suggest_spot($in['title'], $in['body'], $in['category']);
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'Spot önerisi alınamadı.'), 400); }

    return ['ok' => true, 'spot' => (string)$r['spot']];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);

api_register('ai.fill_seo', function () {
    ai_api_require_configured();
    $in = ai_api_editor_input();
    if ($in['title'] === '' && $in['body'] === '') {
        json_err('SEO alanları için haber başlığı ya da metni gerekiyor.', 400);
    }

    $r = ai_suggest_seo($in['title'], $in['body'], $in['category']);
    if (empty($r['ok'])) { json_err((string)arr($r, 'error', 'SEO alanları üretilemedi.'), 400); }

    return [
        'ok'        => true,
        'seo_title' => (string)$r['seo_title'],
        'seo_desc'  => (string)$r['seo_desc'],
        'tags'      => array_values($r['tags']),
    ];
}, ['perm' => 'ai.use', 'methods' => ['POST']]);
