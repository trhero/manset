<?php
/**
 * Manşet — JSON API dağıtıcısı.
 *
 * Kullanım:  api.php?a=<modul>.<eylem>
 * Uç noktalar inc/api/*.php dosyalarında api_register() ile tanımlanır;
 * böylece her iş paketi kendi dosyasına yazar, tek dosyada çakışma olmaz.
 */

require_once __DIR__ . '/inc/bootstrap.php';

if (!MANSET_INSTALLED) { json_err('Kurulum tamamlanmamış.', 503); }

require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/sanitize.php';
// Uç noktaların ihtiyaç duyduğu iş modülleri (varsa) yüklenir.
// Örn. posts.save içinden seo_remember_slug(), media.upload içinden media_save_upload().
foreach (array_merge(['seo', 'media', 'kunye', 'widgets', 'rss', 'ai_jobs', 'members'],
                     manset_feature_modules()) as $mansetModule) {
    $mansetModuleFile = __DIR__ . '/inc/' . $mansetModule . '.php';
    if (is_file($mansetModuleFile)) { require_once $mansetModuleFile; }
}

// api_register() inc/bootstrap.php içinde tanımlıdır — böylece inc/api/*.php
// dosyaları panel sayfalarından da güvenle require edilebilir.

// Modül dosyalarını yükle (her biri kendi uçlarını kaydeder)
foreach ((array)glob(__DIR__ . '/inc/api/*.php') as $moduleFile) {
    require_once $moduleFile;
}

$action = isset($_GET['a']) ? (string)$_GET['a'] : '';
if ($action === '' || !preg_match('/^[a-z0-9_]+\.[a-z0-9_]+$/i', $action)) {
    json_err('Geçersiz istek. Beklenen biçim: api.php?a=modul.eylem', 400);
}
if (!isset($GLOBALS['manset_api'][$action])) {
    json_err('Bilinmeyen uç nokta: ' . sanitize_line($action, 60), 404);
}

$route = $GLOBALS['manset_api'][$action];
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

if (!in_array($method, array_map('strtoupper', $route['methods']), true)) {
    header('Allow: ' . implode(', ', $route['methods']));
    json_err('Bu uç nokta ' . implode('/', $route['methods']) . ' bekliyor.', 405);
}

// Yetki kapısı
if ($route['perm'] !== 'public') {
    $user = current_user();
    if (!$user) { json_err('Oturum açmanız gerekiyor.', 401); }
    if (!can($user, $route['perm'])) { json_err('Bu işlem için yetkiniz yok.', 403); }
}

// DEMO KAPISI (1.4). Yetki kapısının HEMEN ardında, tek noktada.
// Düğme gizlemek kısıt değildir: uçlar buradan doğrudan çağrılabilir, bu yüzden
// engelleme sunucuda ve merkezî olmak zorundadır.
if (function_exists('demo_block_reason')) {
    $mansetDemoNeden = demo_block_reason($action);
    if ($mansetDemoNeden !== '') {
        json_err('Demo kurulumunda bu işlem kapalıdır. ' . $mansetDemoNeden, 403);
    }
}

// CSRF kapısı (yazma istekleri)
if ($route['csrf'] && $method !== 'GET' && $method !== 'HEAD') { csrf_guard(); }

try {
    $result = call_user_func($route['handler']);
    if (is_array($result)) {
        if (!array_key_exists('ok', $result)) { $result = ['ok' => true] + $result; }
        json_out($result);
    }
    // Handler kendi çıktısını verdiyse burada bir şey yapılmaz
} catch (Throwable $e) {
    log_error('API hatası [' . $action . ']: ' . $e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    json_err('İşlem tamamlanamadı. Yönetici günlüğü inceleyebilir.', 500);
}
