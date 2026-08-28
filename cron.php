<?php
/**
 * Manşet — zamanlanmış görev girişi.
 *
 * Kullanım (hosting cron paneli):
 *   *\/10 * * * *  curl -s "https://siteniz.com/cron.php?key=CRON_ANAHTARI"
 * Komut satırından:  php cron.php --key=CRON_ANAHTARI
 *
 * Görevler: RSS çekimi → AI kuyruğu → önbellek süpürme → günlük özet.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) {
    http_response_code(503);
    echo "Kurulum tamamlanmamış.\n";
    exit;
}

$isCli = (PHP_SAPI === 'cli');
$givenKey = '';
if ($isCli) {
    foreach ($argv as $a) { if (strpos($a, '--key=') === 0) { $givenKey = substr($a, 6); } }
} else {
    $givenKey = isset($_GET['key']) ? (string)$_GET['key'] : '';
}

$cronKey = (string)cfg('cron_key', '');
if ($cronKey === '' || !hash_equals($cronKey, $givenKey)) {
    if (!$isCli) {
        // Kaba kuvvet denemesini yavaşlat
        rate_limit('cron:' . client_ip(), 20, 300);
        http_response_code(403);
    }
    echo "Geçersiz cron anahtarı.\n";
    log_error('cron.php geçersiz anahtar', client_ip());
    exit;
}

@set_time_limit(0);
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

require_once __DIR__ . '/inc/view.php';
// Not: 'ai_jobs' burada olmazsa hem YZ kuyruğu işlenmez hem de inc/rss.php içindeki
// ai_job_enqueue() kancası sessizce atlanır (Ajan-5 raporu, Faz 2).
foreach (['rss', 'ai', 'ai_jobs', 'cache', 'seo', 'widgets', 'kunye', 'media', 'backup', 'members'] as $mod) {
    $f = __DIR__ . '/inc/' . $mod . '.php';
    if (is_file($f)) { require_once $f; }
}

$started = microtime(true);
$report = [];

/** Görevi çalıştırıp raporu toplar; hata tüm turu düşürmez. */
function cron_task($label, $fn) {
    global $report;
    if (!is_callable($fn)) { $report[] = $label . ': atlandı (modül yok)'; return; }
    $t = microtime(true);
    try {
        $res = $fn();
        $ms = (int)round((microtime(true) - $t) * 1000);
        $detail = is_scalar($res) ? (string)$res : json_encode($res, JSON_UNESCAPED_UNICODE);
        $report[] = $label . ': ' . ($detail === '' || $detail === 'null' ? 'tamam' : $detail) . ' (' . $ms . ' ms)';
    } catch (Throwable $e) {
        $report[] = $label . ': HATA — ' . $e->getMessage();
        log_error('cron[' . $label . '] ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    }
}

// 1) RSS kaynaklarından çekim
cron_task('rss', function_exists('rss_cron_tick') ? 'rss_cron_tick' : null);
// 2) AI iş kuyruğu (eşzamanlılık sınırı modülün içinde)
cron_task('ai', function_exists('ai_jobs_tick') ? 'ai_jobs_tick' : null);
// 3) Zamanı gelen taslakların yayımı
cron_task('zamanlanmis', 'cron_publish_scheduled');
// 4) Slug geçmişi eşitleme (eski adreslerin 301 ile korunması için)
cron_task('slug', function_exists('seo_sync_slug_history') ? 'seo_sync_slug_history' : null);
// 5) Önbellek süpürme
cron_task('cache', function_exists('cache_sweep') ? 'cache_sweep' : null);
// 5) Bakım: eski hız sınırı kayıtları ve log budama
cron_task('bakim', 'cron_maintenance');

$elapsed = (int)round((microtime(true) - $started) * 1000);
$summary = date('Y-m-d H:i:s') . ' cron turu (' . $elapsed . ' ms) — ' . implode(' | ', $report);
log_error('CRON ' . $summary);
setting_set('cron_last_run', now());
setting_set('cron_last_report', implode("\n", $report));

echo $summary . "\n";

/** published_at zamanı gelen 'draft'/'pending' zamanlanmış haberleri yayına alır. */
function cron_publish_scheduled() {
    $rows = qa('SELECT id FROM posts WHERE status = \'scheduled\' AND published_at IS NOT NULL AND published_at <= :n',
        [':n' => now()]);
    foreach ($rows as $r) {
        q('UPDATE posts SET status = \'published\', updated_at = :u WHERE id = :i', [':u' => now(), ':i' => (int)$r['id']]);
    }
    if ($rows && function_exists('cache_flush')) { cache_flush(); }
    return count($rows) . ' haber yayına alındı';
}

/** Eski kayıtların temizliği ve vitrin tutarlılığı. */
function cron_maintenance() {
    $n = 0;
    try {
        // Yayından çıkmış haberler manşette/editör seçiminde asılı kalmasın
        $n += q('UPDATE posts SET is_headline = 0, headline_sort = 0, is_breaking = 0
                 WHERE status <> \'published\' AND (is_headline = 1 OR is_breaking = 1)')->rowCount();
        $picks = array_values(array_filter(array_map('intval', explode(',', (string)setting('editor_picks', '')))));
        if ($picks) {
            $rows = qa('SELECT id FROM posts WHERE status = \'published\' AND id IN (' . implode(',', $picks) . ')');
            $live = array_map(function ($r) { return (int)$r['id']; }, $rows);
            $kept = array_values(array_intersect($picks, $live));
            if (count($kept) !== count($picks)) { setting_set('editor_picks', implode(',', $kept)); $n++; }
        }
        $n += q('DELETE FROM rate_limits WHERE created_at < :c', [':c' => date('Y-m-d H:i:s', time() - 86400)])->rowCount();
        $n += q('DELETE FROM ai_logs WHERE created_at < :c', [':c' => date('Y-m-d H:i:s', time() - 60 * 86400)])->rowCount();
        $n += q('DELETE FROM rss_items WHERE status IN (\'processed\',\'skipped\') AND fetched_at < :c',
            [':c' => date('Y-m-d H:i:s', time() - 45 * 86400)])->rowCount();
    } catch (Throwable $e) { log_error('cron bakım: ' . $e->getMessage()); }
    return $n . ' eski kayıt silindi';
}
