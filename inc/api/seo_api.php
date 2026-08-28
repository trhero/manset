<?php
/**
 * JSON API — SEO uçları (Ajan-E).
 *
 * Ad alanı: `seo.*`
 * İzin: hepsi `seo.manage` (CONTRACTS §4 — chief_editor + seo_editor).
 *
 * Panel ekranı (admin/pages/seo.php) form gönderimiyle çalışır; bu uçlar
 * ekranın JS tarafı ve dışarıdan betiklenen bakım işleri içindir. `seo.preview`
 * haber editöründeki SERP/paylaşım kartını sunucudaki GERÇEK kurallarla
 * (başlık şablonu, uzunluk kırpma) doğrular — istemcideki önizleme yaklaşıktır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

require_once INC_DIR . '/seo.php';

/** Ortak uç seçenekleri. */
function seo_api_opts(array $ek = []) {
    return array_merge(['perm' => 'seo.manage', 'methods' => ['POST']], $ek);
}

/** JSON gövdesi ya da form alanı. */
function seo_api_field($key, $default = '') {
    $b = json_body();
    if (array_key_exists($key, $b)) { return $b[$key]; }
    return inp($key, $default);
}

// ---------------------------------------------------------------- seo.redirects
api_register('seo.redirects', function () {
    $items = [];
    foreach (seo_redirect_list(200) as $r) {
        $items[] = [
            'id'       => (int)$r['id'],
            'src_path' => (string)$r['src_path'],
            'target'   => (string)$r['target'],
            'code'     => (int)$r['code'],
            'active'   => (int)$r['active'],
            'hits'     => (int)$r['hits'],
            'note'     => (string)$r['note'],
        ];
    }
    return ['items' => $items, 'message' => count($items) . ' kural listelendi.'];
}, seo_api_opts(['methods' => ['GET', 'POST']]));

// ---------------------------------------------------------------- seo.redirect_save
api_register('seo.redirect_save', function () {
    $res = seo_redirect_save([
        'id'       => (int)seo_api_field('id', 0),
        'src_path' => (string)seo_api_field('src_path', ''),
        'target'   => (string)seo_api_field('target', ''),
        'code'     => (int)seo_api_field('code', 301),
        'active'   => (int)seo_api_field('active', 1),
        'note'     => (string)seo_api_field('note', ''),
    ]);
    if (!$res['ok']) { json_err($res['error']); }
    return ['id' => (int)$res['id'], 'message' => 'Yönlendirme kaydedildi.'];
}, seo_api_opts());

// ---------------------------------------------------------------- seo.redirect_delete
api_register('seo.redirect_delete', function () {
    $id = (int)seo_api_field('id', 0);
    if ($id <= 0) { json_err('Kural kimliği zorunlu.'); }
    if (!seo_redirect_delete($id)) { json_err('Kural silinemedi.'); }
    return ['message' => 'Yönlendirme silindi.'];
}, seo_api_opts());

// ---------------------------------------------------------------- seo.tag_save
api_register('seo.tag_save', function () {
    $tag = (string)seo_api_field('tag', '');
    if (trim($tag) === '') { json_err('Etiket adı zorunlu.'); }
    if (!seo_tag_meta_save($tag, seo_api_field('seo_title', ''), seo_api_field('seo_desc', ''))) {
        json_err('Etiket üstverisi kaydedilemedi.');
    }
    if (function_exists('cache_flush')) { cache_flush(); }
    return ['message' => 'Etiket üstverisi kaydedildi.'];
}, seo_api_opts());

// ---------------------------------------------------------------- seo.ping_run
api_register('seo.ping_run', function () {
    $ozet = seo_ping_tick();
    return ['summary' => (string)$ozet, 'stats' => seo_ping_stats(), 'message' => 'Kuyruk işlendi.'];
}, seo_api_opts());

// ---------------------------------------------------------------- seo.preview
// Haber editöründeki SERP / paylaşım kartı için sunucu tarafı doğrulama.
// Kaydedilmemiş taslak da önizlenebilsin diye alanlar İSTEKTEN okunur;
// veritabanına hiç dokunulmaz.
api_register('seo.preview', function () {
    $baslik   = sanitize_line((string)seo_api_field('title', ''), 300);
    $seoTitle = sanitize_line((string)seo_api_field('seo_title', ''), 300);
    $seoDesc  = sanitize_line((string)seo_api_field('seo_desc', ''), 600);
    $spot     = sanitize_line((string)seo_api_field('spot', ''), 600);
    $slug     = sanitize_line((string)seo_api_field('slug', ''), 200);

    $ctx = ['template' => 'single', 'vars' => ['post' => [
        'id' => 0, 'title' => $baslik, 'slug' => $slug,
        'seo_title' => $seoTitle, 'seo_desc' => $seoDesc, 'spot' => $spot,
        'body' => '', 'visibility' => 'public',
    ]]];

    return [
        'title'       => seo_title($ctx),
        'share_title' => seo_share_title($ctx),
        'description' => seo_description($ctx),
        'url'         => base_url() . '/haber/' . ($slug !== '' ? $slug : 'ornek-adres') . '-0',
        'site_name'   => (string)site('title'),
    ];
}, seo_api_opts(['perm' => 'posts.seo']));
