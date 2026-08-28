<?php
/**
 * Manşet — okunma raporu uç noktaları (Ajan-A, 1.2-01).
 * Ad alanı: analytics.*
 *
 * Kayıtlı uçlar:
 *   analytics.series   → gün gün görüntülenme + tekil ziyaretçi serisi
 *   analytics.top      → en çok okunanlar
 *   analytics.summary  → yönlendiren/cihaz dağılımı + abone hunisi
 *
 * HEPSİ SALT OKUMA ve hepsi `analytics.view` ister. KAMUYA AÇIK UÇ YOKTUR:
 * trafik verisi rakip bir yayıncı için ticari bilgidir; ayrıca yönlendiren
 * dağılımı, sitenin hangi platformdan beslendiğini dışarıya söyler.
 *
 * Ham veri (IP, referrer adresi, user-agent) bu uçlardan DÖNMEZ — çünkü hiç
 * saklanmıyor (bkz. inc/analytics.php başlığı).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

/**
 * MODÜLÜ BURADAN YÜKLÜYORUZ — GEREKÇE (ölçülerek bulundu, 1.2-01):
 *
 * `api.php` iş modüllerini sabit bir listeden yükler ve o listede `analytics`
 * YOKTUR (cron.php ve admin/index.php listelerine eklenmiş, api.php'ninkine
 * eklenmemiş). Okunma sayacı ise CONTRACTS §3.1 gereği İSTEMCİDEN çağrılır:
 * assets/site.js → api.php?a=public.view → post_register_view(). O yol
 * api.php bağlamında koştuğu için `function_exists('analytics_record_view')`
 * FALSE dönüyor ve kanca sessizce atlanıyordu — yani gerçek ziyaretlerin
 * HİÇBİRİ kaydedilmezdi. Panel tarafında tablolar dolu görünmediği için bu,
 * kod okunarak değil ancak çalışan bir kurulumda ölçülerek görülür.
 *
 * `inc/api/*.php` dizini glob ile yüklendiğinden bu dosya her istekte okunur;
 * modülü buradan require etmek düzeltmeyi kendi sahiplik alanımızda tutar.
 * api.php'nin listesine `analytics` eklenirse bu satır zararsız hâle gelir
 * (require_once).
 */
if (is_file(dirname(__DIR__) . '/analytics.php')) {
    require_once dirname(__DIR__) . '/analytics.php';
}

/** Gün sayısını istekten okur ve makul aralığa sıkıştırır. */
function analytics_api_days($default = 7) {
    $d = json_body();
    $n = isset($d['gun']) ? (int)$d['gun'] : inp_i('gun', (int)$default);
    return max(1, min(365, $n));
}

// ---------------------------------------------------------------- analytics.series
/**
 * İstek : {gun:int}   (varsayılan 7, en çok 365)
 * Yanıt : {ok:true, gun:int, seri:[{day,hits,visitors},…]}
 */
api_register('analytics.series', function () {
    if (!function_exists('analytics_series')) { json_err('Analitik modülü yüklü değil.', 500); }
    $gun = analytics_api_days(7);
    return ['gun' => $gun, 'seri' => analytics_series($gun)];
}, ['perm' => 'analytics.view', 'methods' => ['GET', 'POST']]);

// ---------------------------------------------------------------- analytics.top
/**
 * İstek : {gun:int, limit:int}
 * Yanıt : {ok:true, gun:int, haberler:[{post_id,hits,title,slug,status},…]}
 */
api_register('analytics.top', function () {
    if (!function_exists('analytics_top_posts')) { json_err('Analitik modülü yüklü değil.', 500); }
    $gun = analytics_api_days(7);
    $d = json_body();
    $limit = isset($d['limit']) ? (int)$d['limit'] : inp_i('limit', 10);
    return ['gun' => $gun, 'haberler' => analytics_top_posts($gun, $limit)];
}, ['perm' => 'analytics.view', 'methods' => ['GET', 'POST']]);

// ---------------------------------------------------------------- analytics.summary
/**
 * İstek : {gun:int}
 * Yanıt : {ok:true, gun, yonlendiren:{tur=>sayi}, cihaz:{tur=>sayi}, huni:{…}}
 */
api_register('analytics.summary', function () {
    if (!function_exists('analytics_ref_breakdown')) { json_err('Analitik modülü yüklü değil.', 500); }
    $gun = analytics_api_days(7);
    return [
        'gun'         => $gun,
        'yonlendiren' => analytics_ref_breakdown($gun),
        'cihaz'       => analytics_device_breakdown($gun),
        'huni'        => analytics_funnel(max($gun, 30)),
    ];
}, ['perm' => 'analytics.view', 'methods' => ['GET', 'POST']]);
