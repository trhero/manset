<?php
/**
 * Manşet — zamanlı manşet ve manşete özel kısa başlık (1.3-09).
 * Sahip: Ajan-C.  Ad alanı (CONTRACTS §7): headlines.*
 *
 * NEDEN AYRI DOSYA: `inc/api/vitrin.php` Ajan-3'ün dosyası ve 1.2 denetiminde
 * oradaki vitrin kapıları (çöp kutusundaki haber manşete alınamaz, yayımlanmamış
 * haber manşete alınamaz) düzeltildi. O kapılara dokunulmadı; 1.3'ün yeni uçları
 * burada açıldı. Var olan `headlines.list/add/remove/reorder/breaking/picks/search`
 * uçları AYNEN yerinde durur — sürükle-bırak ve klavye sıralaması da mevcut
 * `headlines.reorder` ucunu kullanır, yeni bir sıralama ucu AÇILMADI.
 *
 * BU DOSYA ÜÇ BAĞLAMDA YÜKLENİR
 *   1) api.php            → glob('inc/api/*.php'); aşağıdaki api_register()'lar çalışır.
 *   2) admin/pages/headlines.php → require_once; api_register tanımlı değildir,
 *      kayıt bloğu atlanır, yalnız yardımcılar kullanılır.
 *   3) cron.php           → inc/media.php'deki köprü require_once ile (bkz. oradaki
 *      not). Zamanlı manşet görevi `cron_register()` ile BURADA bildirilir ve
 *      bildirim api_register kapısının ÜSTÜNDE olmak zorundadır.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ şema kapısı

/** Göç 026 uygulandı mı? (headline_title / headline_starts_at / headline_ends_at) */
function headlines_v2_ready() {
    static $var = null;
    if ($var !== null) { return $var; }
    if (function_exists('schema_has_column')) {
        $var = schema_has_column('posts', 'headline_title')
            && schema_has_column('posts', 'headline_starts_at')
            && schema_has_column('posts', 'headline_ends_at');
    } else {
        try { qv('SELECT headline_title FROM posts LIMIT 1'); $var = true; }
        catch (Throwable $e) { $var = false; }
    }
    return $var;
}

/** Manşette en çok kaç haber olabilir. vitrin.php yüklüyse onun sınırı geçerlidir. */
function headlines_max() {
    return function_exists('vitrin_headline_max') ? (int)vitrin_headline_max() : 12;
}

/** Manşete özel kısa başlığın uzunluk sınırı (karakter). */
function headlines_title_max() { return 120; }

// ============================================================ ortak yardımcılar

/**
 * 'Y-m-d H:i:s' biçimine getirir; boş/geçersizse boş dize döner.
 *
 * NEDEN KENDİ KOPYASI: `vitrin_datetime()` aynı işi yapar ama o dosya CRON
 * bağlamında yüklenmez (inc/api/* yalnız api.php'de glob edilir). Zamanlı
 * manşet görevi cron'da çalışacağı için burada bağımsız bir kopya tutulur.
 * Fark: vitrin sürümü null döner, bu sürüm boş dize — sütunlar NOT NULL
 * DEFAULT '' olduğu için depolama biçimiyle birebir aynı.
 */
function headlines_datetime($value) {
    $v = trim((string)$value);
    if ($v === '') { return ''; }
    $v = str_replace('T', ' ', $v);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $d = DateTime::createFromFormat($format, $v);
        if (!($d instanceof DateTime)) { continue; }
        $err = DateTime::getLastErrors();
        if (is_array($err) && (!empty($err['error_count']) || !empty($err['warning_count']))) { continue; }
        if ($format === 'Y-m-d') { $d->setTime(0, 0, 0); }
        return $d->format('Y-m-d H:i:s');
    }
    return '';
}

/**
 * Haber satırı manşete alınabilir mi? (yayında + çöpte değil)
 *
 * `vitrin_is_published()` ile AYNI ölçüt; cron bağlamında o dosya yüklenmediği
 * için burada da tanımlıdır. Ölçüt değişirse İKİSİ birden değişmelidir.
 */
function headlines_row_publishable($row) {
    if (!is_array($row) || (string)arr($row, 'status', '') !== 'published') { return false; }
    if (trim((string)arr($row, 'deleted_at', '')) !== '') { return false; }
    $pa = (string)arr($row, 'published_at', '');
    return $pa === '' || $pa <= now();
}

/**
 * Verilen haber id'lerinin 1.3 manşet alanları.
 * @return array id => ['headline_title'=>…, 'headline_starts_at'=>…, 'headline_ends_at'=>…]
 */
function headlines_extra(array $ids) {
    $out = [];
    if (!headlines_v2_ready()) { return $out; }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return $out; }
    // Tamsayıya indirgenmiş id listesi; dize birleştirme güvenlidir.
    $rows = qa('SELECT id, headline_title, headline_starts_at, headline_ends_at
        FROM posts WHERE id IN (' . implode(',', $ids) . ')');
    foreach ($rows as $r) {
        $out[(int)$r['id']] = [
            'headline_title'     => (string)arr($r, 'headline_title', ''),
            'headline_starts_at' => (string)arr($r, 'headline_starts_at', ''),
            'headline_ends_at'   => (string)arr($r, 'headline_ends_at', ''),
        ];
    }
    return $out;
}

/**
 * Manşete girmeyi BEKLEYEN haberler (zamanı henüz gelmemiş).
 * Panelde ayrı bir listede gösterilir; ön yüzde görünmezler.
 */
function headlines_pending_rows($limit = 30) {
    if (!headlines_v2_ready()) { return []; }
    $limit = max(1, min(100, (int)$limit));
    return qa('SELECT p.id, p.title, p.headline_title, p.headline_starts_at, p.headline_ends_at,
            p.status, p.published_at, p.is_headline, c.name AS category_name
        FROM posts p LEFT JOIN categories c ON c.id = p.category_id
        WHERE p.is_headline = 0 AND p.headline_starts_at <> \'\'
        ORDER BY p.headline_starts_at ASC LIMIT ' . $limit);
}

/** Panel için bekleyen kayıt özeti. */
function headlines_pending_items($limit = 30) {
    $out = [];
    $n = now();
    foreach (headlines_pending_rows($limit) as $r) {
        $bitis = (string)arr($r, 'headline_ends_at', '');
        $out[] = [
            'id'                 => (int)$r['id'],
            'title'              => (string)$r['title'],
            'headline_title'     => (string)arr($r, 'headline_title', ''),
            'category'           => (string)arr($r, 'category_name', ''),
            'headline_starts_at' => (string)arr($r, 'headline_starts_at', ''),
            'headline_ends_at'   => $bitis,
            'publishable'        => headlines_row_publishable($r),
            'overdue'            => ((string)arr($r, 'headline_starts_at', '') <= $n),
            'expired'            => ($bitis !== '' && $bitis <= $n),
        ];
    }
    return $out;
}

// ============================================================ yazma işlemleri

/** Manşete özel kısa başlığı kaydeder. Boş dize = özgün başlık kullanılsın. */
function headlines_set_title($id, $title) {
    $id = (int)$id;
    if (!headlines_v2_ready()) {
        return ['ok' => false, 'error' => 'Kısa başlık için 026 numaralı güncelleme gerekiyor.'];
    }
    if (!qv('SELECT 1 FROM posts WHERE id = :i', [':i' => $id])) {
        return ['ok' => false, 'error' => 'Haber bulunamadı.'];
    }
    // sanitize_line: satır sonu ve denetim karakterlerini atar, uzunluğu keser.
    $clean = sanitize_line((string)$title, headlines_title_max());
    db_update('posts', ['headline_title' => $clean], 'id = :id', [':id' => $id]);
    return ['ok' => true, 'error' => '', 'headline_title' => $clean];
}

/**
 * Manşet zamanlamasını kaydeder.
 *
 * KURALLAR
 *   · Başlangıç bitişten sonra olamaz.
 *   · Geçmişe verilen başlangıç kabul edilir; ilk cron turunda uygulanır
 *     (editör "hemen al" demek için de kullanabilir).
 *   · Haber ZATEN manşetteyse başlangıç anlamsızdır: yalnız bitiş yazılır.
 *   · Zamanlama, haberin yayında olmasını GARANTİ ETMEZ. Cron manşete alırken
 *     yayın/çöp ölçütünü yeniden doğrular.
 */
function headlines_set_schedule($id, $startsAt, $endsAt) {
    $id = (int)$id;
    if (!headlines_v2_ready()) {
        return ['ok' => false, 'error' => 'Zamanlı manşet için 026 numaralı güncelleme gerekiyor.'];
    }
    $post = q1('SELECT id, is_headline FROM posts WHERE id = :i', [':i' => $id]);
    if (!$post) { return ['ok' => false, 'error' => 'Haber bulunamadı.']; }

    $ham = trim((string)$startsAt);
    $hamBitis = trim((string)$endsAt);
    $bas = headlines_datetime($ham);
    $bit = headlines_datetime($hamBitis);
    if ($ham !== '' && $bas === '') { return ['ok' => false, 'error' => 'Başlangıç zamanı anlaşılamadı.']; }
    if ($hamBitis !== '' && $bit === '') { return ['ok' => false, 'error' => 'Bitiş zamanı anlaşılamadı.']; }
    if ($bas !== '' && $bit !== '' && $bas > $bit) {
        return ['ok' => false, 'error' => 'Başlangıç zamanı bitiş zamanından sonra olamaz.'];
    }
    // Zaten manşetteki haberin "girişi" olmaz.
    if ((int)arr($post, 'is_headline', 0) === 1) { $bas = ''; }

    db_update('posts', ['headline_starts_at' => $bas, 'headline_ends_at' => $bit], 'id = :id', [':id' => $id]);
    return ['ok' => true, 'error' => '', 'headline_starts_at' => $bas, 'headline_ends_at' => $bit];
}

// ============================================================ zamanlı manşet (cron)

/**
 * Zamanı gelen haberleri manşete alır, süresi dolanları çıkarır.
 *
 * `cron_publish_scheduled()` deseniyle aynı: sorgu → güncelle → önbellek boşalt.
 * Görev UCUZDUR (ağa çıkmaz, birkaç UPDATE), ama yine de her turda en çok
 * `headlines_max()` kadar kayıt açar — manşet sınırı aşılamaz.
 *
 * @return array cron_task() biçiminde ['items'=>int,'note'=>string]
 */
function headlines_cron_schedule() {
    if (!headlines_v2_ready()) { return ['items' => 0, 'note' => 'göç 026 uygulanmamış']; }

    $n = now();
    $acilan = 0;
    $kapanan = 0;
    $atlanan = 0;

    /* 1) SÜRESİ DOLANLAR ÖNCE — böylece boşalan yer aynı turda doldurulabilir. */
    foreach (qa('SELECT id FROM posts
        WHERE is_headline = 1 AND headline_ends_at <> \'\' AND headline_ends_at <= :n', [':n' => $n]) as $r) {
        db_update('posts', ['is_headline' => 0, 'headline_sort' => 0, 'headline_ends_at' => ''],
            'id = :id', [':id' => (int)$r['id']]);
        $kapanan++;
    }

    /* 2) ZAMANI GELENLER */
    $dolu = (int)qv('SELECT COUNT(*) FROM posts WHERE is_headline = 1', [], 0);
    $max = headlines_max();
    $adaylar = qa('SELECT id, status, published_at, deleted_at, headline_ends_at FROM posts
        WHERE is_headline = 0 AND headline_starts_at <> \'\' AND headline_starts_at <= :n
        ORDER BY headline_starts_at ASC LIMIT ' . (int)$max, [':n' => $n]);

    foreach ($adaylar as $r) {
        $id = (int)$r['id'];
        // Bitiş zamanı da geçmişse hiç alma; zamanlamayı temizle.
        $bit = (string)arr($r, 'headline_ends_at', '');
        if ($bit !== '' && $bit <= $n) {
            db_update('posts', ['headline_starts_at' => '', 'headline_ends_at' => ''], 'id = :id', [':id' => $id]);
            $atlanan++;
            continue;
        }
        // KAPI: yayında değilse ya da çöpteyse manşete ALINMAZ. Zamanlama
        // beklemede bırakılır — haber yayına girince bir sonraki tur alır.
        if (!headlines_row_publishable($r)) { $atlanan++; continue; }
        if ($dolu >= $max) { $atlanan++; continue; }   // manşet dolu, sırada beklesin

        $sonSira = (int)qv('SELECT MAX(headline_sort) FROM posts WHERE is_headline = 1', [], 0);
        db_update('posts', [
            'is_headline'        => 1,
            'headline_sort'      => $sonSira + 1,
            'headline_starts_at' => '',
        ], 'id = :id', [':id' => $id]);
        $dolu++;
        $acilan++;
    }

    if (($acilan + $kapanan) > 0) {
        // Sıra numaralarında boşluk kalmasın (vitrin.php yüklüyse onun sıkıştırıcısı).
        if (function_exists('vitrin_compact')) { vitrin_compact(); }
        if (function_exists('cache_flush')) { cache_flush(); }
    }

    return [
        'items' => $acilan + $kapanan,
        'note'  => $acilan . ' manşete alındı, ' . $kapanan . ' manşetten çıktı'
                   . ($atlanan ? ', ' . $atlanan . ' beklemede' : ''),
    ];
}

// Zamanlı manşet — UCUZ: ağa çıkmaz, birkaç UPDATE sürer.
// KAYIT api_register kapısının ÜSTÜNDE olmalı: cron bağlamında api_register
// tanımlı değildir ve dosya aşağıdaki `return` ile erken biter.
cron_register('manset', 'headlines_cron_schedule', true);

// ============================================================ uç noktalar

// admin/pages/headlines.php bu dosyayı yalnız yardımcılar için yükler.
if (!function_exists('api_register')) { return; }

/**
 * headlines.schedule — zamanlı manşet.
 * İstek : {id:int, starts_at?:string, ends_at?:string}   ('' = temizle)
 * Yanıt : {ok:true, id, headline_starts_at, headline_ends_at, pending:[…], message}
 */
api_register('headlines.schedule', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id <= 0) { json_err('Geçersiz haber.', 400); }

    $r = headlines_set_schedule($id, (string)arr($d, 'starts_at', ''), (string)arr($d, 'ends_at', ''));
    if (empty($r['ok'])) { json_err((string)$r['error'], 400); }
    if (function_exists('cache_flush')) { cache_flush(); }

    $bas = (string)$r['headline_starts_at'];
    $bit = (string)$r['headline_ends_at'];
    $mesaj = ($bas === '' && $bit === '')
        ? 'Zamanlama temizlendi.'
        : 'Zamanlama kaydedildi.'
          . ($bas !== '' ? ' Manşete giriş: ' . $bas . '.' : '')
          . ($bit !== '' ? ' Manşetten çıkış: ' . $bit . '.' : '')
          . ' Uygulanması zamanlı görevin (cron) çalışmasına bağlıdır.';

    return [
        'id'                 => $id,
        'headline_starts_at' => $bas,
        'headline_ends_at'   => $bit,
        'pending'            => headlines_pending_items(),
        'message'            => $mesaj,
    ];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

/**
 * headlines.title — manşete özel kısa başlık.
 * İstek : {id:int, headline_title:string}   ('' = özgün başlık kullanılsın)
 * Yanıt : {ok:true, id, headline_title, message}
 */
api_register('headlines.title', function () {
    $d = json_body();
    $id = (int)arr($d, 'id', 0);
    if ($id <= 0) { json_err('Geçersiz haber.', 400); }

    $r = headlines_set_title($id, (string)arr($d, 'headline_title', ''));
    if (empty($r['ok'])) { json_err((string)$r['error'], 400); }
    if (function_exists('cache_flush')) { cache_flush(); }

    return [
        'id'             => $id,
        'headline_title' => (string)$r['headline_title'],
        'message'        => $r['headline_title'] === ''
            ? 'Kısa başlık temizlendi; manşette özgün başlık görünecek.'
            : 'Manşet başlığı kaydedildi.',
    ];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);

/**
 * headlines.meta — manşet listesinin 1.3 alanları (kısa başlık + zamanlama).
 * Panel bunu `headlines.list` yanıtının ÜSTÜNE bindirir; vitrin.php'nin
 * `vitrin_item()` biçimi değişmedi.
 * İstek : {ids?:[int]}   (boşsa manşetteki haberler)
 * Yanıt : {ok:true, meta:{id:{…}}, pending:[…], ready:bool}
 */
api_register('headlines.meta', function () {
    $d = json_body();
    $ids = arr($d, 'ids', []);
    if (!is_array($ids) || !$ids) {
        $ids = [];
        foreach (qa('SELECT id FROM posts WHERE is_headline = 1') as $r) { $ids[] = (int)$r['id']; }
    }
    return [
        'ready'   => headlines_v2_ready(),
        'meta'    => headlines_extra($ids),
        'pending' => headlines_pending_items(),
        'message' => 'Manşet ayrıntıları yenilendi.',
    ];
}, ['perm' => 'headlines.manage', 'methods' => ['POST']]);
