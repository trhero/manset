<?php
/**
 * Manşet — tema API uçları (Ajan-7).
 *
 * Kayıtlı uçlar:
 *   theme.list · theme.activate · theme.schema · theme.save · theme.save_site
 *   theme.blocks · theme.save_blocks · theme.reset · theme.reset_blocks · theme.preview_css
 *
 * Sözleşme: CONTRACTS.md §5 (tema motoru) ve §7 (JSON API).
 *
 * Bu dosya admin/pages/theme.php tarafından da require edilir; api_register()
 * bootstrap içinde tanımlı olduğundan API bağlamı dışında yalnız diziye yazar.
 * Aşağıdaki theme_api_* yardımcıları hem panel hem uçlar tarafından kullanılır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ sabit kümeler

/** Anasayfa blok tiplerinin sabit listesi (home_block_labels() sözlüğünden). */
function theme_api_block_types() {
    return array_keys(home_block_labels());
}

/** Reklam slotlarının sabit listesi (CONTRACTS §2 · ads.slot_key). */
function theme_api_ad_slots() {
    return ['header', 'sidebar_1', 'sidebar_2', 'in_article', 'footer'];
}

/** Reklam slotu insan okunur adları. */
function theme_api_ad_slot_labels() {
    return [
        'header'     => 'Üst bant (header)',
        'sidebar_1'  => 'Kenar çubuğu 1 (sidebar_1)',
        'sidebar_2'  => 'Kenar çubuğu 2 (sidebar_2)',
        'in_article' => 'Haber içi (in_article)',
        'footer'     => 'Alt bilgi (footer)',
    ];
}

/** Dışarıdan yükleme gerektirmeyen sistem yazı tipi yığınları. */
function theme_api_font_stacks() {
    return [
        ['value' => 'system-ui, -apple-system, \'Segoe UI\', Roboto, Arial, sans-serif', 'label' => 'Sistem (system-ui)'],
        ['value' => 'Georgia, \'Iowan Old Style\', \'Times New Roman\', serif',            'label' => 'Georgia (serif)'],
        ['value' => '\'Times New Roman\', Times, serif',                                   'label' => 'Times New Roman (serif)'],
        ['value' => '\'Helvetica Neue\', Helvetica, Arial, sans-serif',                    'label' => 'Helvetica / Arial (sans)'],
        ['value' => 'Verdana, Geneva, sans-serif',                                         'label' => 'Verdana (sans)'],
        ['value' => 'Tahoma, \'Segoe UI\', sans-serif',                                    'label' => 'Tahoma (sans)'],
        ['value' => 'ui-monospace, Consolas, \'SFMono-Regular\', Menlo, monospace',        'label' => 'Consolas (mono)'],
    ];
}

/** `size` alanlarında kabul edilen birimler. */
function theme_api_size_units() {
    return ['px', 'rem', 'em', '%', 'vw', 'vh', 'ch', 'pt'];
}

/** Özel CSS alanı için üst sınır (karakter). */
function theme_api_css_limit() { return 20000; }

/** Varsayılan anasayfa blok dizilimi (home_blocks() ile aynı). */
function theme_api_default_blocks() {
    return [
        ['type' => 'breaking',  'on' => 1, 'title' => '', 'limit' => 5, 'category_id' => 0, 'slot' => ''],
        ['type' => 'headline',  'on' => 1, 'title' => '', 'limit' => 6, 'category_id' => 0, 'slot' => ''],
        ['type' => 'latest',    'on' => 1, 'title' => '', 'limit' => 8, 'category_id' => 0, 'slot' => ''],
        ['type' => 'category',  'on' => 1, 'title' => '', 'limit' => 4, 'category_id' => 0, 'slot' => ''],
        ['type' => 'most_read', 'on' => 1, 'title' => '', 'limit' => 6, 'category_id' => 0, 'slot' => ''],
    ];
}

// ============================================================ doğrulama

/** Tema adını doğrular; geçersizse 400 ile sonlandırır. */
function theme_api_require_name($name, $fallbackActive = true) {
    $name = (string)$name;
    if ($name === '' && $fallbackActive) { $name = theme_name(); }
    if (!theme_is_valid($name)) { json_err('Geçersiz tema adı.', 400); }
    return $name;
}

/** Dosya adı yalnız uploads/ altında güvenli bir yol mu? */
function theme_api_valid_upload_path($v) {
    $v = (string)$v;
    if ($v === '') { return true; }
    if (strlen($v) > 255) { return false; }
    if (strpos($v, '..') !== false) { return false; }
    if (strpos($v, '\\') !== false) { return false; }
    if ($v[0] === '/' || $v[0] === '.') { return false; }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $v)) { return false; }  // şema (http:, javascript:, data:)
    return (bool)preg_match('#^[A-Za-z0-9._\-/]{1,255}$#', $v);
}

/** Google Fonts URL kuralı: yalnız https://fonts.googleapis.com/ ile başlayabilir. */
function theme_api_valid_fonts_url($v) {
    $v = trim((string)$v);
    if ($v === '') { return true; }
    if (strlen($v) > 500) { return false; }
    return (bool)preg_match('#^https://fonts\.googleapis\.com/#', $v);
}

/**
 * Şemadan gelen tek bir alan için değeri doğrular / normalleştirir.
 * @return array [kabul:bool, deger:string, hata:string]
 */
function theme_api_check_field(array $field, $raw, $user) {
    $type = isset($field['type']) ? (string)$field['type'] : 'text';
    $label = isset($field['label']) ? (string)$field['label'] : (string)$field['key'];
    $val = is_scalar($raw) ? (string)$raw : '';

    // admin_only alanları (özel CSS dâhil) KİLİTLİ `theme.css` iznini ister.
    // GÜVENLİK (denetim tur 2, B02): eskiden burada `theme.manage` denetleniyordu;
    // o izin `seo_editor` rolünde de AÇIK olduğu için özel CSS kutusuna
    // </style><script> yazılıp kalıcı XSS elde edilebiliyordu. `theme.css`
    // kilitli izindir: DB katmanından hiç kimseye verilemez, yalnız admin geçer.
    if (!empty($field['admin_only']) && !can($user, 'theme.css')) {
        return [false, '', $label . ' alanını yalnız yönetici düzenleyebilir.'];
    }

    switch ($type) {
        case 'color':
            $val = trim($val);
            if ($val === '') { return [true, '', '']; }
            if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $val)) {
                return [false, '', $label . ': geçersiz renk değeri (#rgb ya da #rrggbb bekleniyor).'];
            }
            return [true, strtolower($val), ''];

        case 'size':
            // Kabul edilen biçim: "720" ya da "720px" · yalnız izinli birimler
            $val = trim(str_replace(',', '.', $val));
            if ($val === '') { return [true, '', '']; }
            if (!preg_match('/^(-?\d{1,6}(?:\.\d{1,3})?)\s*([a-z%]*)$/i', $val, $m)) {
                return [false, '', $label . ': geçersiz ölçü değeri.'];
            }
            $unit = strtolower($m[2]);
            if ($unit !== '' && !in_array($unit, theme_api_size_units(), true)) {
                return [false, '', $label . ': izin verilmeyen birim (' . sanitize_line($unit, 10) . ').'];
            }
            // Birimsiz saklanabilir; theme_css_vars() alanın `unit` değerini ekler
            return [true, $m[1] . $unit, ''];

        case 'select':
            $opts = [];
            foreach ((array)arr($field, 'options', []) as $o) {
                if (is_array($o) && isset($o['value'])) { $opts[] = (string)$o['value']; }
            }
            if (!in_array($val, $opts, true)) {
                return [false, '', $label . ': listede olmayan seçenek.'];
            }
            return [true, $val, ''];

        case 'bool':
            return [true, ($val === '1' || $val === 'true' || $val === 'on') ? '1' : '0', ''];

        case 'image':
            $val = trim($val);
            if (!theme_api_valid_upload_path($val)) {
                return [false, '', $label . ': yalnız uploads klasöründeki bir dosya adı verilebilir.'];
            }
            return [true, $val, ''];

        case 'css':
            if (!can($user, 'theme.manage')) {
                return [false, '', $label . ' alanını yalnız yönetici düzenleyebilir.'];
            }
            if (mb_strlen($val) > theme_api_css_limit()) {
                return [false, '', $label . ': en çok ' . theme_api_css_limit() . ' karakter olabilir.'];
            }
            // Satır sonlarını koru, denetim karakterlerini at
            $val = str_replace(["\r\n", "\r"], "\n", $val);
            $val = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $val);
            return [true, (string)$val, ''];

        case 'font':
        case 'text':
        default:
            $val = sanitize_line($val, 500);
            return [true, $val, ''];
    }
}

/**
 * Gönderilen değerleri şemaya göre süzer.
 * Şemada olmayan anahtar sonuca ASLA girmez.
 *
 * @return array ['values' => [anahtar => deger], 'errors' => [mesaj, …], 'skipped' => [anahtar, …]]
 */
function theme_api_filter_values($name, $values, $user = null) {
    if ($user === null) { $user = current_user(); }
    $schema = theme_setting_schema($name);
    $byKey = [];
    foreach ($schema as $f) {
        if (!empty($f['key'])) { $byKey[(string)$f['key']] = $f; }
    }

    $out = ['values' => [], 'errors' => [], 'skipped' => []];
    if (!is_array($values)) { return $out; }

    foreach ($values as $key => $raw) {
        $key = (string)$key;
        if (!isset($byKey[$key])) { $out['skipped'][] = $key; continue; }   // şemada yok → yazılmaz

        // Google Fonts URL'i tipinden bağımsız olarak ayrıca denetlenir
        if ($key === 'google_fonts_url') {
            $u = trim(is_scalar($raw) ? (string)$raw : '');
            if (!theme_api_valid_fonts_url($u)) {
                $out['errors'][] = 'Google Fonts adresi yalnız https://fonts.googleapis.com/ ile başlayabilir.';
                continue;
            }
            $out['values'][$key] = $u;
            continue;
        }

        list($ok, $clean, $err) = theme_api_check_field($byKey[$key], $raw, $user);
        if (!$ok) { $out['errors'][] = $err; continue; }
        $out['values'][$key] = $clean;
    }
    return $out;
}

/**
 * Verilen (kaydedilmemiş olabilir) değerlerden :root{…} bloğu üretir.
 * inc/view.php içindeki theme_css_vars() ile aynı kuralları uygular.
 */
function theme_api_css_from($name, array $values) {
    $vars = [];
    foreach (theme_setting_schema($name) as $field) {
        if (empty($field['css_var']) || empty($field['key'])) { continue; }
        $key = (string)$field['key'];
        $val = array_key_exists($key, $values)
            ? $values[$key]
            : theme_setting($key, isset($field['default']) ? $field['default'] : '', $name);
        if ($val === '' || $val === null) { continue; }
        $type = isset($field['type']) ? $field['type'] : 'text';
        if ($type === 'color') {
            if (!preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$val)) { continue; }
        } elseif ($type === 'size') {
            $val = preg_replace('/[^0-9a-z%\.\-]/i', '', (string)$val);
            if ($val === '') { continue; }
            if (is_numeric($val)) { $val .= isset($field['unit']) ? $field['unit'] : 'px'; }
        } else {
            $val = str_replace(['<', '>', '{', '}', ';', '"'], '', (string)$val);
        }
        $cssVar = preg_replace('/[^a-z0-9\-]/i', '', (string)$field['css_var']);
        if ($cssVar === '') { continue; }
        $vars[$cssVar] = $val;
    }
    if (!$vars) { return ''; }
    $out = ":root{\n";
    foreach ($vars as $k => $v) { $out .= '  --' . ltrim($k, '-') . ': ' . $v . ";\n"; }
    return $out . "}\n";
}

/** Ham (kapalılar dâhil) blok dizilimini okur. */
function theme_api_blocks_raw() {
    $raw = setting('home_blocks', '');
    $blocks = json_decode((string)$raw, true);
    if (!is_array($blocks) || !$blocks) { return theme_api_default_blocks(); }
    $out = [];
    foreach ($blocks as $b) {
        if (!is_array($b) || empty($b['type'])) { continue; }
        $out[] = [
            'type'        => (string)$b['type'],
            'on'          => isset($b['on']) && !$b['on'] ? 0 : 1,
            'title'       => (string)arr($b, 'title', ''),
            'limit'       => (int)arr($b, 'limit', 0),
            'category_id' => (int)arr($b, 'category_id', 0),
            'slot'        => (string)arr($b, 'slot', ''),
        ];
    }
    return $out ?: theme_api_default_blocks();
}

/**
 * Blok dizilimini doğrular.
 * @return array ['blocks'=>[…], 'errors'=>[…]]
 */
function theme_api_normalize_blocks($blocks) {
    $types = theme_api_block_types();
    $slots = theme_api_ad_slots();
    $catIds = [0];
    foreach (all_categories(false) as $c) { $catIds[] = (int)$c['id']; }

    $out = [];
    $errors = [];
    if (!is_array($blocks)) { return ['blocks' => [], 'errors' => ['Blok listesi okunamadı.']]; }
    if (count($blocks) > 20) { $errors[] = 'En çok 20 blok tanımlanabilir; fazlası atıldı.'; }

    foreach ($blocks as $b) {
        if (count($out) >= 20) { break; }
        if (!is_array($b)) { continue; }
        $type = (string)arr($b, 'type', '');
        if (!in_array($type, $types, true)) {
            $errors[] = 'Bilinmeyen blok tipi atlandı: ' . sanitize_line($type, 30);
            continue;
        }
        $catId = (int)arr($b, 'category_id', 0);
        if (!in_array($catId, $catIds, true)) { $catId = 0; }

        $slot = (string)arr($b, 'slot', '');
        if ($slot !== '' && !in_array($slot, $slots, true)) { $slot = ''; }
        if ($type === 'ad' && $slot === '') { $slot = 'in_article'; }

        $limit = (int)arr($b, 'limit', 0);
        if ($limit !== 0) { $limit = max(1, min(24, $limit)); }

        $on = arr($b, 'on', 1);
        $out[] = [
            'type'        => $type,
            'on'          => ($on === 0 || $on === '0' || $on === false) ? 0 : 1,
            'title'       => sanitize_line((string)arr($b, 'title', ''), 80),
            'limit'       => $limit,
            'category_id' => $catId,
            'slot'        => $slot,
        ];
    }
    return ['blocks' => $out, 'errors' => $errors];
}

/** theme:<ad>:* anahtarlarını siler. Silinen anahtar sayısını döndürür. */
function theme_api_reset_settings($name) {
    $prefix = 'theme:' . $name . ':';
    $n = 0;
    foreach (array_keys(settings_all()) as $k) {
        if (strpos($k, $prefix) === 0) {
            q('DELETE FROM settings WHERE skey = :k', [':k' => $k]);
            $n++;
        }
    }
    settings_all(true);
    return $n;
}

/** Yazma sonrası önbelleği süpür (modül varsa). */
function theme_api_flush() {
    if (function_exists('cache_flush')) { cache_flush(); }
}

/** Temanın panelde gösterilecek üstverisi. */
function theme_api_meta($name) {
    $meta = theme_json($name);
    return [
        'ad'            => $name,
        'name'          => (string)arr($meta, 'name', $name),
        'description'   => (string)arr($meta, 'description', ''),
        'version'       => (string)arr($meta, 'version', ''),
        'author'        => (string)arr($meta, 'author', ''),
        'preview'       => preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)arr($meta, 'preview', ''))
                             ? strtolower((string)$meta['preview']) : '#c0392b',
        'supports_dark' => !empty($meta['supports_dark']),
        'active'        => theme_name() === $name,
    ];
}

// ============================================================ theme.list

/**
 * İstek : {}
 * Yanıt : {ok:true, items:{ad:{name,description,preview,active,supports_dark,…}}, active:'gazete'}
 */
api_register('theme.list', function () {
    $items = [];
    foreach (array_keys(theme_list()) as $name) {
        $items[$name] = theme_api_meta($name);
    }
    return ['items' => $items, 'active' => theme_name()];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.activate

/**
 * İstek : {name:'gece'}
 * Yanıt : {ok:true, active:'gece', message:'…'}
 */
api_register('theme.activate', function () {
    $d = json_body();
    $name = theme_api_require_name(arr($d, 'name', ''), false);
    setting_set('theme', $name);
    theme_api_flush();
    $meta = theme_json($name);
    return [
        'active'  => $name,
        'message' => '“' . (string)arr($meta, 'name', $name) . '” teması etkinleştirildi.',
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.schema

/**
 * İstek : {name?:'gece'}
 * Yanıt : {ok:true, name, schema:[…], values:{…}, supports_dark:bool, meta:{…},
 *          fonts:[…], units:[…]}
 */
api_register('theme.schema', function () {
    $d = json_body();
    $name = theme_api_require_name(arr($d, 'name', ''));
    $schema = [];
    foreach (theme_setting_schema($name) as $f) {
        if (empty($f['key'])) { continue; }
        $schema[] = [
            'key'        => (string)$f['key'],
            'label'      => (string)arr($f, 'label', $f['key']),
            'type'       => (string)arr($f, 'type', 'text'),
            'default'    => (string)arr($f, 'default', ''),
            'css_var'    => (string)arr($f, 'css_var', ''),
            'group'      => (string)arr($f, 'group', 'Gelişmiş'),
            'unit'       => (string)arr($f, 'unit', ''),
            'help'       => (string)arr($f, 'help', ''),
            'min'        => (string)arr($f, 'min', ''),
            'max'        => (string)arr($f, 'max', ''),
            'options'    => is_array(arr($f, 'options', null)) ? $f['options'] : [],
            'admin_only' => !empty($f['admin_only']),
        ];
    }
    $values = [];
    foreach ($schema as $f) {
        $values[$f['key']] = (string)theme_setting($f['key'], $f['default'], $name);
    }
    return [
        'name'          => $name,
        'schema'        => $schema,
        'values'        => $values,
        'supports_dark' => !empty(theme_json($name)['supports_dark']),
        'meta'          => theme_api_meta($name),
        'fonts'         => theme_api_font_stacks(),
        'units'         => theme_api_size_units(),
        'css_limit'     => theme_api_css_limit(),
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.save

/**
 * İstek : {name:'gece', values:{anahtar:deger, …}}
 * Yanıt : {ok:true, saved:[…], skipped:[…], errors:[…], css:':root{…}', message:'…'}
 * Şemada olmayan anahtar yazılmaz; geçersiz değer reddedilir.
 */
api_register('theme.save', function () {
    $d = json_body();
    $name = theme_api_require_name(arr($d, 'name', ''));
    $values = arr($d, 'values', []);
    if (!is_array($values)) { json_err('Ayar listesi okunamadı.', 400); }
    if (count($values) > 200) { json_err('Çok fazla alan gönderildi.', 400); }

    $res = theme_api_filter_values($name, $values, current_user());
    foreach ($res['values'] as $k => $v) {
        theme_setting_set($k, $v, $name);
    }
    theme_api_flush();

    $msg = count($res['values']) . ' ayar kaydedildi.';
    if ($res['errors']) { $msg .= ' ' . count($res['errors']) . ' alan reddedildi.'; }

    return [
        'name'    => $name,
        'saved'   => array_keys($res['values']),
        'skipped' => $res['skipped'],
        'errors'  => $res['errors'],
        'css'     => theme_api_css_from($name, []),
        'message' => $msg,
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.save_site

/**
 * İstek : {site_logo?:'logo.png', site_favicon?:'fav.png'}
 * Bunlar tema ayarı değil site ayarıdır; tema değiştirince korunur.
 */
api_register('theme.save_site', function () {
    $d = json_body();
    $saved = [];
    foreach (['site_logo', 'site_favicon'] as $key) {
        if (!array_key_exists($key, $d)) { continue; }
        $v = sanitize_line(is_scalar($d[$key]) ? (string)$d[$key] : '', 255);
        if (!theme_api_valid_upload_path($v)) {
            json_err('Görsel yalnız uploads klasöründeki bir dosya adı olabilir.', 400);
        }
        setting_set($key, $v);
        $saved[] = $key;
    }
    if (!$saved) { json_err('Kaydedilecek alan gönderilmedi.', 400); }
    theme_api_flush();
    return ['saved' => $saved, 'message' => 'Logo ve favicon kaydedildi.'];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.blocks

/**
 * Yanıt : {ok:true, blocks:[…], labels:{tip:ad}, categories:[{id,name}], slots:[{value,label}]}
 */
api_register('theme.blocks', function () {
    $cats = [];
    foreach (all_categories(false) as $c) {
        $cats[] = ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'active' => (int)$c['active']];
    }
    $slots = [];
    foreach (theme_api_ad_slot_labels() as $v => $l) { $slots[] = ['value' => $v, 'label' => $l]; }

    return [
        'blocks'     => theme_api_blocks_raw(),
        'labels'     => home_block_labels(),
        'categories' => $cats,
        'slots'      => $slots,
        'types'      => theme_api_block_types(),
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.save_blocks

/**
 * İstek : {blocks:[{type,on,title,limit,category_id,slot}, …]}  (en çok 20)
 */
api_register('theme.save_blocks', function () {
    $d = json_body();
    $res = theme_api_normalize_blocks(arr($d, 'blocks', []));
    if (!$res['blocks']) {
        json_err('Geçerli blok bulunamadı. En az bir blok tanımlayın.', 400);
    }
    setting_set('home_blocks', json_encode($res['blocks'], JSON_UNESCAPED_UNICODE));
    theme_api_flush();
    return [
        'blocks'  => $res['blocks'],
        'errors'  => $res['errors'],
        'message' => count($res['blocks']) . ' blok kaydedildi.',
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.reset

/**
 * İstek : {name:'gece'} → yalnız theme:<ad>:* anahtarlarını siler.
 */
api_register('theme.reset', function () {
    $d = json_body();
    $name = theme_api_require_name(arr($d, 'name', ''));
    $n = theme_api_reset_settings($name);
    theme_api_flush();
    return [
        'name'    => $name,
        'removed' => $n,
        'values'  => theme_settings_all($name),
        'css'     => theme_api_css_from($name, []),
        'message' => $n > 0
            ? 'Tema ayarları varsayılana döndürüldü (' . $n . ' kayıt silindi).'
            : 'Tema zaten varsayılan ayarlardaydı.',
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.reset_blocks

api_register('theme.reset_blocks', function () {
    setting_set('home_blocks', '');
    theme_api_flush();
    return [
        'blocks'  => theme_api_default_blocks(),
        'message' => 'Blok dizilimi varsayılana döndürüldü.',
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);

// ============================================================ theme.preview_css

/**
 * İstek : {name?, values:{…}} → kaydetmeden doğrulanmış CSS üretir.
 * Yanıt : {ok:true, css:':root{…}', errors:[…], skipped:[…], dark:bool}
 */
api_register('theme.preview_css', function () {
    $d = json_body();
    $name = theme_api_require_name(arr($d, 'name', ''));
    $values = arr($d, 'values', []);
    if (!is_array($values)) { $values = []; }
    if (count($values) > 200) { json_err('Çok fazla alan gönderildi.', 400); }

    $res = theme_api_filter_values($name, $values, current_user());
    $dark = !empty(theme_json($name)['supports_dark'])
        && (array_key_exists('dark_mode', $res['values'])
            ? $res['values']['dark_mode'] === '1'
            : theme_setting('dark_mode', '0', $name) === '1');

    $css = theme_api_css_from($name, $res['values']);
    $custom = array_key_exists('custom_css', $res['values'])
        ? (string)$res['values']['custom_css']
        : (string)theme_setting('custom_css', '', $name);
    $custom = str_replace(['</style', '<script'], ['<\\/style', '<\\script'], $custom);

    return [
        'name'    => $name,
        'css'     => $css,
        'custom'  => $custom,
        'dark'    => $dark,
        'errors'  => $res['errors'],
        'skipped' => $res['skipped'],
    ];
}, ['perm' => 'theme.manage', 'methods' => ['POST']]);
