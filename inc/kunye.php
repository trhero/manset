<?php
/**
 * Manşet — Künye (BİK) bilgileri ve düzeltme-cevap süreci. (Ajan-9)
 *
 * Kaynak: docs/BIK-NOTLARI.md — 5187 sayılı Basın Kanunu m.4 (künye/iletişim)
 * ve m.14 (düzeltme ve cevap) özeti.
 *
 * UYARI: Bu modül hukuki görüş üretmez, yalnız yayıncının girdiği bilgileri
 * yayımlar. Mevzuat değişebilir; yürürlükteki güncel Basın Kanunu ve Basın
 * İlan Kurumu düzenlemelerini resmî kaynaklardan teyit etmek yayıncının
 * sorumluluğundadır. Bu nedenle tüm alanlar panelden düzenlenebilir ve boş
 * bırakılan alan künyede basılmaz (uydurma bilgi görünmez).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/sanitize.php';

// ---------------------------------------------------------------- sabitler
// Düzeltme-cevap süreleri (docs/BIK-NOTLARI.md §4)
// KUNYE_ANSWER_DEADLINE artık yalnız VARSAYILANDIR: yürürlükteki süre mevzuatla
// değişebileceği için gerçek eşik `corrections_deadline_hours` ayarından okunur
// (bkz. corrections_deadline_seconds). Belgede özetlenen süre "bir gün"dür;
// yayıncı yürürlükteki güncel yönetmeliği teyit etmelidir.
if (!defined('KUNYE_ANSWER_DEADLINE')) { define('KUNYE_ANSWER_DEADLINE', 86400); }   // varsayılan yanıt süresi: 1 gün
if (!defined('KUNYE_DEADLINE_WARN'))   { define('KUNYE_DEADLINE_WARN', 21600); }     // varsayılan: 6 saat kala uyarı
if (!defined('KUNYE_HOMEPAGE_SECS'))   { define('KUNYE_HOMEPAGE_SECS', 86400); }     // ana sayfada 24 saat
if (!defined('KUNYE_VISIBLE_SECS'))    { define('KUNYE_VISIBLE_SECS', 604800); }     // haber altında 7 gün
if (!defined('KUNYE_ANSWER_MIN'))      { define('KUNYE_ANSWER_MIN', 20); }           // en az metin uzunluğu
if (!defined('KUNYE_BIK_MAX'))         { define('KUNYE_BIK_MAX', 10000); }           // EİDS kodu uzunluk tavanı
if (!defined('KUNYE_UPDATE_MIN'))      { define('KUNYE_UPDATE_MIN', 5); }            // güncelleme notu en az uzunluk

/**
 * Editoryal "güncelleme notu" kaydının `corrections.status` değeri.
 *
 * DÜZELTME (m.14) ile GÜNCELLEME AYRI ŞEYLERDİR:
 *   - düzeltme/cevap → okuyucu talebi, YASAL süreye tabi, sorumlu müdür yayımlar;
 *   - güncelleme     → yayıncının kendi editoryal notu, yasal süre yok, süresiz görünür.
 * Aynı tabloda ayrı bir `status` değeriyle tutulur (yeni göç dosyası gerektirmez);
 * listeleme/sayaç/süre fonksiyonları bu türü düzeltmelerden ayıklar.
 */
if (!defined('CORRECTION_KIND_UPDATE')) { define('CORRECTION_KIND_UPDATE', 'update'); }

// ================================================================ künye alanları

/**
 * Künye alan tanımları — docs/BIK-NOTLARI.md §2 tablosunun birebir karşılığı.
 *
 * Her öğe: ['key','label','type','required','group','help']
 *   type     : text | textarea | email | tel | code
 *   required : YALNIZ Basın Kanunu m.4'ün açıkça saydığı alanlarda true
 *   code     : ham basılan (temizlenmeyen) alan — bkz. kunye_bik_embed()
 *
 * @return array
 */
function kunye_field_defs() {
    return [
        // ---- Basın Kanunu m.4'ün açıkça saydığı bilgiler
        [
            'key' => 'kunye_adres', 'label' => 'Faaliyet gösterilen iş yeri adresi',
            'type' => 'textarea', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Açık adres. Boş bırakılırsa künyede hiç basılmaz.',
        ],
        [
            'key' => 'kunye_ticaret_unvani', 'label' => 'Ticari unvan',
            'type' => 'text', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Ticaret sicilinde kayıtlı tam unvan.',
        ],
        [
            'key' => 'kunye_eposta', 'label' => 'Elektronik posta adresi',
            'type' => 'email', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Künyede tıklanabilir bağlantı olarak basılır.',
        ],
        [
            'key' => 'kunye_telefon', 'label' => 'İletişim telefonu',
            'type' => 'tel', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Ülke kodu ile yazmanız önerilir.',
        ],
        [
            'key' => 'kunye_kep', 'label' => 'Elektronik tebligat (KEP/UETS) adresi',
            'type' => 'text', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Kayıtlı elektronik posta ya da UETS adresi.',
        ],
        [
            'key' => 'kunye_hosting', 'label' => 'Yer sağlayıcısının (hosting) adı',
            'type' => 'text', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Sitenin barındırıldığı firmanın ticari adı.',
        ],
        [
            'key' => 'kunye_hosting_adres', 'label' => 'Yer sağlayıcısının adresi',
            'type' => 'textarea', 'required' => true, 'group' => 'Zorunlu iletişim bilgileri',
            'help' => 'Yer sağlayıcısının açık adresi.',
        ],

        // ---- Süreli yayın künyelerinde uygulamada yer alan bilgiler
        [
            'key' => 'kunye_yayin_adi', 'label' => 'Yayının adı',
            'type' => 'text', 'required' => false, 'group' => 'Yayın bilgileri',
            'help' => 'Boşsa site adı kullanılmaz; alan basılmaz.',
        ],
        [
            'key' => 'kunye_imtiyaz_sahibi', 'label' => 'İmtiyaz sahibi',
            'type' => 'text', 'required' => false, 'group' => 'Yayın bilgileri',
            'help' => 'Gerçek ya da tüzel kişi adı.',
        ],
        [
            'key' => 'kunye_sorumlu_mudur', 'label' => 'Sorumlu yazı işleri müdürü',
            'type' => 'text', 'required' => false, 'group' => 'Yayın bilgileri',
            'help' => 'Düzeltme ve cevap yazılarının yayımından sorumlu kişi.',
        ],
        [
            'key' => 'kunye_yayin_turu', 'label' => 'Yayın türü',
            'type' => 'text', 'required' => false, 'group' => 'Yayın bilgileri',
            'help' => 'Örn. “Yaygın süreli yayın (internet haber sitesi)”.',
        ],

        // ---- Ticari kayıt bilgileri
        [
            'key' => 'kunye_vergi_dairesi', 'label' => 'Vergi dairesi',
            'type' => 'text', 'required' => false, 'group' => 'Ticari bilgiler', 'help' => '',
        ],
        [
            'key' => 'kunye_vergi_no', 'label' => 'Vergi numarası',
            'type' => 'text', 'required' => false, 'group' => 'Ticari bilgiler', 'help' => '',
        ],
        [
            'key' => 'kunye_mersis', 'label' => 'MERSİS numarası',
            'type' => 'text', 'required' => false, 'group' => 'Ticari bilgiler', 'help' => '16 haneli merkezi sicil kayıt numarası.',
        ],

        // ---- Ek alanlar
        [
            'key' => 'kunye_hosting_telefon', 'label' => 'Yer sağlayıcısı telefonu',
            'type' => 'tel', 'required' => false, 'group' => 'Ek', 'help' => '',
        ],
        [
            'key' => 'kunye_ek_notlar', 'label' => 'Ek notlar',
            'type' => 'textarea', 'required' => false, 'group' => 'Ek',
            'help' => 'Künyenin sonunda serbest metin olarak basılır.',
        ],
        [
            'key' => 'bik_eids_kodu', 'label' => 'Basın İlan Kurumu / EİDS doğrulama kodu',
            'type' => 'code', 'required' => false, 'group' => 'Ek', 'raw' => true, 'admin_only' => true,
            'help' => 'Bu alandaki kod siteye olduğu gibi eklenir. Yalnız Basın İlan Kurumu’nun '
                    . 'verdiği doğrulama kodunu yapıştırın.',
        ],
    ];
}

/**
 * Alan gruplarının sırası ve kısa açıklaması.
 * Dil kasıtlı olarak temkinlidir: hukuki iddia değil, kaynağa atıftır.
 * @return array grup adı => açıklama
 */
function kunye_field_groups() {
    return [
        'Zorunlu iletişim bilgileri' =>
            'Basın Kanunu m.4 uyarınca ana sayfadan doğrudan erişilebilir olması beklenen bilgiler '
            . '(bkz. docs/BIK-NOTLARI.md §2).',
        'Yayın bilgileri' =>
            'Süreli yayın künyelerinde uygulamada yer alan bilgiler.',
        'Ticari bilgiler' =>
            'Ticari kayıt bilgileri; okuyucuya şeffaflık sağlamak için künyede paylaşılabilir.',
        'Ek' =>
            'Serbest alanlar ve Basın İlan Kurumu doğrulama kodu.',
    ];
}

/** Anahtara göre tek alan tanımı (yoksa null). */
function kunye_field_def($key) {
    foreach (kunye_field_defs() as $f) {
        if ($f['key'] === $key) { return $f; }
    }
    return null;
}

/**
 * Temaların kullanacağı künye verisi — inc/view.php → kunye_fields() bunu çağırır.
 * YALNIZ dolu alanlar döner; ham basılan kod alanı (bik_eids_kodu) listeye girmez.
 *
 * @return array [['key','label','value','type','group'], …]
 */
function kunye_data() {
    $out = [];
    foreach (kunye_field_defs() as $f) {
        if (!empty($f['raw'])) { continue; }
        $value = trim((string)setting($f['key'], ''));
        if ($value === '') { continue; }
        $out[] = [
            'key'   => $f['key'],
            'label' => $f['label'],
            'value' => $value,
            'type'  => $f['type'],
            'group' => $f['group'],
        ];
    }
    return $out;
}

/**
 * Doldurulmamış zorunlu alanların etiketleri.
 * @return array ['Faaliyet gösterilen iş yeri adresi', …]
 */
function kunye_missing_required() {
    $missing = [];
    foreach (kunye_field_defs() as $f) {
        if (empty($f['required'])) { continue; }
        if (trim((string)setting($f['key'], '')) === '') { $missing[] = $f['label']; }
    }
    return $missing;
}

/**
 * Basın İlan Kurumu / EİDS doğrulama kodu — HAM döner (temizlenmez).
 * Bu alan yalnız `settings.manage` izniyle (yani admin rolüyle) yazılabilir;
 * yazma denetimi inc/api/kunye_api.php ve admin/pages/kunye.php içindedir.
 */
function kunye_bik_embed() {
    return (string)setting('bik_eids_kodu', '');
}

/** Tek künye değerini görünür HTML'e çevirir (e-posta/telefon bağlantılanır). */
function kunye_value_html(array $field) {
    $v = (string)$field['value'];
    switch ($field['type']) {
        case 'email':
            return '<a href="mailto:' . esc($v) . '">' . esc($v) . '</a>';
        case 'tel':
            $dial = preg_replace('/[^0-9+]/', '', $v);
            return $dial !== '' ? '<a href="tel:' . esc($dial) . '">' . esc($v) . '</a>' : esc($v);
        case 'textarea':
            return nl2br(esc($v), false);
        default:
            return esc($v);
    }
}

/**
 * /kunye sayfasının gövdesi (index.php → 'kunye' yolu).
 *
 * Boş alan basılmaz. Yapı: grup başlığı (<h2>) + tanım listesi (<dl>).
 * Etiket <strong>, değer <p> içine alınır: gövdeyi ikinci kez süzen temalarda
 * (tema page.php şablonu → sanitize_html) dl/dt/dd sarmalayıcıları düşse bile
 * etiket–değer ayrımı okunur kalır.
 *
 * Organization JSON-LD burada BASILMAZ — SEO tarafı (inc/seo.php) üretir.
 * Çıktının tamamı esc()'ten geçer; yalnız kunye_bik_embed() ham eklenir.
 */
function kunye_render_html() {
    $fields = kunye_data();
    $byGroup = [];
    foreach ($fields as $f) { $byGroup[$f['group']][] = $f; }

    $out = '<div class="kunye-bilgi">';

    if (!$fields) {
        $out .= '<p>Künye bilgileri henüz girilmedi. Yönetim panelinden '
              . '<strong>Künye / BİK</strong> ekranını doldurabilirsiniz.</p>';
    }

    foreach (kunye_field_groups() as $group => $note) {
        if (empty($byGroup[$group])) { continue; }
        $out .= '<h2>' . esc($group) . '</h2>';
        if ($note !== '') { $out .= '<p><small>' . esc($note) . '</small></p>'; }
        $out .= '<dl>';
        foreach ($byGroup[$group] as $f) {
            $out .= '<dt><strong>' . esc($f['label']) . '</strong></dt>'
                  . '<dd><p>' . kunye_value_html($f) . '</p></dd>';
        }
        $out .= '</dl>';
    }

    // Yayın ilkeleri sayfası (varsa) — künyeden doğrudan erişilebilsin
    $policy = function_exists('page_by_slug') ? page_by_slug('yayin-ilkeleri') : null;
    if ($policy) {
        $out .= '<h2>Yayın ilkeleri</h2>'
              . '<p><a href="' . esc(url_page($policy)) . '">' . esc($policy['title']) . '</a> '
              . 'sayfasında yayın kuruluşunun izlediği ilkeler yer alır.</p>';
    }

    // Düzeltme ve cevap hakkı
    $out .= '<h2>Düzeltme ve cevap hakkı</h2>'
          . '<p>Basın Kanunu m.14 uyarınca, kişilik haklarının zarar gördüğünü düşünen okuyucular '
          . 'düzeltme ve cevap hakkını kullanabilir. Talepler, haber sayfalarının altındaki '
          . '<strong>“Düzeltme talep et”</strong> formundan iletilir; sorumlu yazı işleri müdürü '
          . 'düzeltme ve cevap yazısını, aldığı tarihten itibaren en geç bir gün içinde, '
          . 'ilgili habere URL bağlantısı sağlanarak yayımlar.</p>'
          . '<p><small>Bu metin bilgilendirme amaçlıdır, hukuki görüş değildir. '
          . 'Yürürlükteki güncel mevzuat resmî kaynaklardan teyit edilmelidir.</small></p>';

    // Basın İlan Kurumu doğrulama kodu — yönetici girdisi, ham basılır
    $embed = trim(kunye_bik_embed());
    if ($embed !== '') {
        $out .= '<div class="kunye-bik">' . $embed . '</div>';
    }

    return $out . '</div>';
}

// ================================================================ düzeltme-cevap

/** corrections.status → [etiket, ton] */
function corrections_status_label($status) {
    $map = [
        'pending'  => ['Bekliyor', 'uyari'],
        'answered' => ['Yanıtlandı', 'olumlu'],
        'rejected' => ['Reddedildi', 'olumsuz'],
        CORRECTION_KIND_UPDATE => ['Güncelleme notu', 'bilgi'],
    ];
    return isset($map[$status]) ? $map[$status] : [(string)$status, 'notr'];
}

/** Saniyeyi insan okunur süreye çevirir: "18 saat 20 dakika". */
function corrections_human_duration($seconds) {
    $seconds = (int)abs($seconds);
    if ($seconds < 60) { return $seconds . ' saniye'; }
    $days  = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $mins  = (int)floor(($seconds % 3600) / 60);
    $parts = [];
    if ($days > 0)  { $parts[] = $days . ' gün'; }
    if ($hours > 0) { $parts[] = $hours . ' saat'; }
    if ($mins > 0 && $days === 0) { $parts[] = $mins . ' dakika'; }
    if (!$parts) { $parts[] = $mins . ' dakika'; }
    return implode(' ', $parts);
}

// ---------------------------------------------------------------- yasal süre eşiği

/**
 * Düzeltme/cevap yanıt süresi eşiği — SAAT cinsinden, ayardan okunur.
 *
 * Neden ayar? docs/BIK-NOTLARI.md §4'te özetlenen süre "bir gün"dür, ancak
 * mevzuat değişebilir ve belge hukuki görüş değildir. Bu yüzden eşik koda
 * gömülmez; `corrections_deadline_hours` ayarıyla yayıncı tarafından
 * güncellenebilir. Varsayılan 24 saattir.
 *
 * @return int 1–720 arası saat
 */
function corrections_deadline_hours() {
    $h = (int)setting('corrections_deadline_hours', (string)(KUNYE_ANSWER_DEADLINE / 3600));
    if ($h < 1)   { $h = 1; }
    if ($h > 720) { $h = 720; }        // 30 gün — elle girilen saçma değerlere tavan
    return $h;
}

/** Yanıt süresi eşiği, saniye. */
function corrections_deadline_seconds() {
    return corrections_deadline_hours() * 3600;
}

/**
 * "Süre doluyor" uyarısının başladığı kalan süre (saniye).
 * Eşiğin dörtte biri; en az 1 saat, en çok varsayılan 6 saat.
 */
function corrections_deadline_warn_seconds() {
    $w = (int)floor(corrections_deadline_seconds() / 4);
    if ($w < 3600) { $w = 3600; }
    if ($w > KUNYE_DEADLINE_WARN) { $w = KUNYE_DEADLINE_WARN; }
    return $w;
}

/**
 * Yanıt süresi durumu — `created_at + corrections_deadline_hours` (docs/BIK-NOTLARI.md §4).
 *
 * kalan > uyarı eşiği       → zamaninda / olumlu
 * 0 < kalan <= uyarı eşiği  → yaklasiyor / uyari
 * süre geçmiş               → gecti / olumsuz
 *
 * Ölçüm anı: talep hâlâ bekliyorsa "şimdi", yayımlanmış/reddedilmişse işlemin
 * yapıldığı an (publish_at) — böylece zamanında yanıtlanmış eski kayıtlar
 * listede kırmızıya dönmez.
 *
 * @return array ['durum','kalan','ton','etiket','deadline','saniye']
 */
function corrections_deadline_state(array $row) {
    $created = strtotime((string)arr($row, 'created_at', ''));
    if (!$created) { $created = time(); }
    $deadline = $created + corrections_deadline_seconds();

    $status = (string)arr($row, 'status', 'pending');
    // Editoryal güncelleme notlarının yasal yanıt süresi YOKTUR.
    if ($status === CORRECTION_KIND_UPDATE) {
        return [
            'durum' => 'yok', 'kalan' => '', 'ton' => 'notr',
            'etiket' => 'Editoryal güncelleme', 'deadline' => '', 'saniye' => 0,
            'yas' => corrections_human_duration(time() - $created),
        ];
    }
    $refText = (string)arr($row, 'publish_at', '');
    $ref = ($status !== 'pending' && $refText !== '') ? strtotime($refText) : time();
    if (!$ref) { $ref = time(); }

    $left = $deadline - $ref;
    $human = corrections_human_duration($left);

    if ($left > corrections_deadline_warn_seconds()) {
        $durum = 'zamaninda'; $ton = 'olumlu';
        $etiket = $status === 'pending' ? $human . ' kaldı' : 'Süresinde yanıtlandı';
    } elseif ($left > 0) {
        $durum = 'yaklasiyor'; $ton = 'uyari';
        $etiket = $status === 'pending' ? 'Süre doluyor · ' . $human . ' kaldı' : 'Süre dolmadan yanıtlandı';
    } else {
        $durum = 'gecti'; $ton = 'olumsuz';
        $etiket = $status === 'pending' ? 'Süre doldu · ' . $human . ' gecikme' : $human . ' gecikmeyle yanıtlandı';
    }

    return [
        'durum'    => $durum,
        'kalan'    => $human,
        'ton'      => $ton,
        'etiket'   => $etiket,
        'deadline' => date('Y-m-d H:i:s', $deadline),
        'saniye'   => (int)$left,
        // Talebin yaşı: "alındığından bu yana geçen süre" — panelde her satırda
        // gösterilir; durumdan bağımsızdır.
        'yas'      => corrections_human_duration(time() - $created),
    ];
}

/** Süzgeç dizisinden WHERE parçası üretir. */
function corrections_where(array $filters) {
    $where = [];
    $p = [];
    $status = (string)arr($filters, 'status', '');
    if ($status === CORRECTION_KIND_UPDATE) {
        // Yalnız editoryal güncelleme notları
        $where[] = 'c.status = :st';
        $p[':st'] = CORRECTION_KIND_UPDATE;
    } else {
        // Güncelleme notları düzeltme listelerine KARIŞMAZ ("Tümü" dâhil).
        $where[] = 'c.status <> :notupd';
        $p[':notupd'] = CORRECTION_KIND_UPDATE;
        if ($status !== '' && $status !== 'all' && in_array($status, ['pending', 'answered', 'rejected'], true)) {
            $where[] = 'c.status = :st';
            $p[':st'] = $status;
        }
    }
    $postId = (int)arr($filters, 'post_id', 0);
    if ($postId > 0) { $where[] = 'c.post_id = :pid'; $p[':pid'] = $postId; }

    $q = trim((string)arr($filters, 'q', ''));
    if ($q !== '') {
        $where[] = '(c.name LIKE :q OR c.email LIKE :q OR c.message LIKE :q)';
        $p[':q'] = '%' . $q . '%';
    }
    return [$where ? ' WHERE ' . implode(' AND ', $where) : '', $p];
}

/**
 * Düzeltme talepleri listesi (panel).
 * @return array satırlar + post_title / post_slug
 */
function corrections_list(array $filters = [], $limit = 30, $offset = 0) {
    list($where, $p) = corrections_where($filters);
    $limit = max(1, min(200, (int)$limit));
    $offset = max(0, (int)$offset);
    // handled_by göç 012 ile geldi; uygulanmamış kurulumda sorgu patlamasın diye
    // önce genişletilmiş sorgu denenir, olmazsa sütunsuz sürüme düşülür.
    $sel = 'SELECT c.id, c.post_id, c.name, c.email, c.message, c.note, c.answer, c.ip, c.status,
                   c.created_at, c.publish_at, c.homepage_until, c.visible_until,
                   {HB} p.title AS post_title, p.slug AS post_slug
            FROM corrections c LEFT JOIN posts p ON p.id = c.post_id {HJ}'
         . $where . ' ORDER BY c.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    try {
        return qa(strtr($sel, [
            '{HB}' => 'c.handled_by, u.name AS handled_name,',
            '{HJ}' => 'LEFT JOIN users u ON u.id = c.handled_by',
        ]), $p);
    } catch (Throwable $e) {
        return qa(strtr($sel, ['{HB}' => '', '{HJ}' => '']), $p);
    }
}

/** Süzgece uyan talep sayısı. */
function corrections_count(array $filters = []) {
    list($where, $p) = corrections_where($filters);
    return (int)qv('SELECT COUNT(*) FROM corrections c' . $where, $p, 0);
}

/**
 * Durum bazlı sayaçlar (sekmeler için).
 * `all` YALNIZ düzeltme taleplerini sayar; editoryal güncelleme notları
 * ayrı `update` anahtarında döner.
 */
function corrections_counts() {
    $out = ['pending' => 0, 'answered' => 0, 'rejected' => 0, 'all' => 0, CORRECTION_KIND_UPDATE => 0];
    foreach (qa('SELECT status, COUNT(*) AS n FROM corrections GROUP BY status') as $r) {
        $s = (string)$r['status'];
        $n = (int)$r['n'];
        if (isset($out[$s])) { $out[$s] = $n; }
        if ($s !== CORRECTION_KIND_UPDATE) { $out['all'] += $n; }
    }
    return $out;
}

/** Süresi geçmiş bekleyen talep sayısı (panel uyarısı). */
function corrections_overdue_count() {
    $cut = date('Y-m-d H:i:s', time() - corrections_deadline_seconds());
    return (int)qv('SELECT COUNT(*) FROM corrections WHERE status = \'pending\' AND created_at <= :c',
        [':c' => $cut], 0);
}

/** Tek talep (yoksa null). */
function corrections_get($id) {
    $id = (int)$id;
    if ($id <= 0) { return null; }
    return q1('SELECT c.*, p.title AS post_title, p.slug AS post_slug
               FROM corrections c LEFT JOIN posts p ON p.id = c.post_id WHERE c.id = :i', [':i' => $id]);
}

/**
 * Ön yüz düzeltme talebi (uç: public.correction).
 *
 * @param array $input ['post_id','name','email','message','website'(honeypot)]
 * @return array ['ok'=>bool, 'message'=>string, 'error'=>string, 'code'=>int]
 */
function corrections_submit(array $input) {
    $fail = function ($msg, $code = 400) {
        return ['ok' => false, 'message' => '', 'error' => $msg, 'code' => $code];
    };
    $okMessage = 'Talebiniz yayın kuruluşuna iletildi. Yasal süre içinde değerlendirilecektir.';

    // 1) Hız sınırı — CONTRACTS §3: correction:<ip> 3 / 900 sn
    $ip = client_ip();
    if (!rate_limit('correction:' . $ip, 3, 900)) {
        return $fail('Çok sık düzeltme talebi gönderiyorsunuz. Lütfen 15 dakika sonra tekrar deneyin.', 429);
    }

    // 2) Honeypot — dolu ise sessizce başarı döner, kayıt açılmaz
    if (trim((string)arr($input, 'website', '')) !== '') {
        return ['ok' => true, 'message' => $okMessage, 'error' => '', 'code' => 200];
    }

    // 3) Form site genelinde açık mı?
    if (setting('corrections_enabled', '1') !== '1') {
        return $fail('Düzeltme talebi formu şu anda kapalı.', 400);
    }

    // 4) Haber var ve yayında mı?
    $postId = (int)arr($input, 'post_id', 0);
    $post = ($postId > 0 && function_exists('post_by_id')) ? post_by_id($postId) : null;
    if (!$post) {
        return $fail('Düzeltme talep edilen haber bulunamadı.', 404);
    }

    // 5) Alan doğrulaması (sanitize_* kırptığı için sınır ham girdide denetlenir)
    $name = sanitize_line((string)arr($input, 'name', ''), 200);
    if (mb_strlen($name) < 2)  { return $fail('Adınız en az 2 karakter olmalı.'); }
    if (mb_strlen($name) > 60) { return $fail('Adınız en fazla 60 karakter olabilir.'); }

    $email = trim((string)arr($input, 'email', ''));
    if (mb_strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $fail('Geçerli bir e-posta adresi girin. (Yayımlanmaz.)');
    }

    $message = sanitize_plain((string)arr($input, 'message', ''), 4000);
    $len = mb_strlen($message);
    if ($len < 20)   { return $fail('Talebiniz en az 20 karakter olmalı.'); }
    if ($len > 3000) { return $fail('Talebiniz en fazla 3000 karakter olabilir.'); }

    db_insert('corrections', [
        'post_id'    => (int)$post['id'],
        'name'       => $name,
        'email'      => $email,
        'message'    => $message,
        'note'       => '',
        'ip'         => $ip,
        'status'     => 'pending',
        'created_at' => now(),
    ]);

    return ['ok' => true, 'message' => $okMessage, 'error' => '', 'code' => 200];
}

// ---------------------------------------------------------------- işlemi yapan

/** `corrections.handled_by` sütunu var mı? (göç 012) — süreç içinde bir kez ölçülür. */
function corrections_has_handled_by() {
    static $var = null;
    if ($var !== null) { return $var; }
    if (function_exists('schema_has_column')) {
        try { return $var = (bool)schema_has_column('corrections', 'handled_by'); }
        catch (Throwable $e) { /* aşağıdaki sondaya düşülür */ }
    }
    try { qv('SELECT handled_by FROM corrections WHERE 1 = 0', [], 0); $var = true; }
    catch (Throwable $e) { $var = false; }
    return $var;
}

/**
 * İşlemi yapan kullanıcının id'si. Oturum yoksa (cron/CLI) 0 döner.
 * Basın Kanunu m.14 yükümlülüğü ŞAHSA bağlı olduğu için "kim yayımladı"
 * kaydı denetlenebilirlik açısından önemlidir.
 */
function corrections_actor_id() {
    if (!function_exists('current_user')) { return 0; }
    $u = current_user();
    return $u ? (int)arr($u, 'id', 0) : 0;
}

/** db_update verisine handled_by ekler (sütun varsa). */
function corrections_with_actor(array $data) {
    if (corrections_has_handled_by()) { $data['handled_by'] = corrections_actor_id(); }
    return $data;
}

// ---------------------------------------------------------------- başvurana bildirim

/**
 * Başvurana işlemsel e-posta gönderilebilir mi?
 *
 * CONTRACTS §13 TOPLU gönderimi (bülten) kapsam dışı bırakır. Buradaki gönderim
 * TEK ALICILIDIR ve işlemseldir: kişi kendi düzeltme talebinin sonucunu öğrenir.
 * `corrections_notify_enabled` ayarı '0' ise ya da sunucuda mail() kapalıysa
 * SESSİZCE atlanır — işlem yine başarıyla tamamlanır.
 */
function corrections_mail_available() {
    if (setting('corrections_notify_enabled', '1') !== '1') { return false; }
    // Mevcut altyapı: inc/members.php → member_mail_send() (aynı ayar/başlık kuralları)
    if (function_exists('member_mail_available')) { return (bool)member_mail_available(); }
    if (!function_exists('mail')) { return false; }
    $kapali = array_map('trim', explode(',', (string)@ini_get('disable_functions')));
    return !in_array('mail', $kapali, true);
}

/**
 * Tek alıcılı işlemsel e-posta. Mevcut altyapı varsa ona devreder;
 * yoksa aynı kurallarla yerel `mail()` sarmalayıcısı kullanılır.
 *
 * @return array ['ok'=>bool,'error'=>string]
 */
function corrections_mail_send($to, $subject, $body) {
    if (!corrections_mail_available()) { return ['ok' => false, 'error' => 'E-posta gönderimi kapalı.']; }
    if (function_exists('member_mail_send')) { return member_mail_send($to, $subject, $body); }

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return ['ok' => false, 'error' => 'Geçersiz alıcı.']; }

    // Gönderici: site e-postası, yoksa alan adından türetilir
    $from = trim((string)setting('site_email', ''));
    if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $host = (string)parse_url(base_url(), PHP_URL_HOST);
        if ($host === '') { $host = 'localhost'; }
        $from = 'noreply@' . preg_replace('/^www\./i', '', $host);
    }
    // Başlık enjeksiyonuna karşı satır sonları temizlenir
    $from    = str_replace(["\r", "\n"], '', $from);
    $subject = str_replace(["\r", "\n"], ' ', (string)$subject);
    $headers = 'From: ' . $from . "\r\n"
             . 'Reply-To: ' . $from . "\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n";

    $ok = false;
    try { $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', (string)$body, $headers); }
    catch (Throwable $e) { log_error('corrections_mail_send: ' . $e->getMessage()); $ok = false; }

    return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'E-posta gönderilemedi.'];
}

/**
 * Başvurana sonucu bildirir (yayımlandı / reddedildi).
 *
 * Hiçbir koşulda hata FIRLATMAZ: gönderim kapalıysa ya da başarısızsa sessizce
 * false döner, düzeltme işlemi yine tamamlanmış sayılır.
 * Dâhilî ret notu e-postaya KONULMAZ (yalnız panelde görünür).
 *
 * @param array  $row  corrections satırı
 * @param string $kind 'answered' | 'rejected'
 * @return bool gönderildi mi
 */
function corrections_notify_applicant(array $row, $kind) {
    $to = trim((string)arr($row, 'email', ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    if (!corrections_mail_available()) { return false; }

    $site  = (string)setting('site_title', 'Manşet');
    $ad    = trim((string)arr($row, 'name', ''));
    $selam = $ad !== '' ? 'Sayın ' . $ad . ',' : 'Merhaba,';
    $baslik = trim((string)arr($row, 'post_title', ''));
    $slug   = (string)arr($row, 'post_slug', '');
    $link   = ($slug !== '' && function_exists('url_post'))
        ? url_post(['id' => (int)arr($row, 'post_id', 0), 'slug' => $slug]) : '';

    $haberSatiri = '';
    if ($baslik !== '') {
        $haberSatiri = 'İlgili haber: ' . $baslik . "\n";
        if ($link !== '') { $haberSatiri .= $link . "\n"; }
        $haberSatiri .= "\n";
    }

    if ($kind === 'answered') {
        $konu = $site . ' — düzeltme ve cevap talebiniz yayımlandı';
        $govde = $selam . "\n\n"
               . 'İletmiş olduğunuz düzeltme ve cevap talebi değerlendirildi ve metin yayımlandı.' . "\n\n"
               . $haberSatiri
               . 'Yayımlanan metin:' . "\n"
               . trim(strip_tags((string)arr($row, 'answer', ''))) . "\n\n"
               . 'Bu ileti, talebinizin sonucunu bildirmek için otomatik olarak gönderilmiştir; '
               . 'yanıtlamanıza gerek yoktur.' . "\n\n"
               . $site . "\n";
    } else {
        $konu = $site . ' — düzeltme ve cevap talebiniz hakkında';
        $govde = $selam . "\n\n"
               . 'İletmiş olduğunuz düzeltme ve cevap talebi değerlendirildi; talep bu hâliyle '
               . 'yayımlanmamıştır.' . "\n\n"
               . $haberSatiri
               . 'Talebinizin özeti:' . "\n"
               . excerpt((string)arr($row, 'message', ''), 500) . "\n\n"
               . 'Değerlendirmeye itirazınız varsa künye sayfamızdaki iletişim adresinden '
               . 'yayın kuruluşuna ulaşabilirsiniz.' . "\n\n"
               . $site . "\n";
    }

    $res = corrections_mail_send($to, $konu, $govde);
    if (empty($res['ok'])) {
        // Sessiz atlama: yalnız günlüğe düşer, çağırana hata dönmez.
        log_error('Düzeltme bildirimi gönderilemedi', 'talep=' . (int)arr($row, 'id', 0)
            . ' hata=' . (string)arr($res, 'error', ''));
        return false;
    }
    return true;
}

/**
 * Düzeltme/cevap metnini yayımlar (Basın Kanunu m.14).
 *
 * Metin HABERİN GÖVDESİNE YAZILMAZ; ayrı tabloda durur ve tema
 * kunye_post_corrections() ile basar. Böylece süre dolunca kendiliğinden kalkar.
 *
 * @return array ['ok','message','error','code']
 */
function corrections_publish($id, $answer) {
    $row = corrections_get($id);
    if (!$row) {
        return ['ok' => false, 'message' => '', 'error' => 'Düzeltme talebi bulunamadı.', 'code' => 404];
    }

    // iframe'e izin verilmez: düzeltme metni yalnız metin ve bağlantı içerir
    $clean = sanitize_html((string)$answer, false);
    if (mb_strlen(trim(preg_replace('/\s+/u', ' ', strip_tags($clean)))) < KUNYE_ANSWER_MIN) {
        return ['ok' => false, 'message' => '', 'code' => 400,
                'error' => 'Yayımlanacak düzeltme metni en az ' . KUNYE_ANSWER_MIN . ' karakter olmalı.'];
    }

    $t = time();
    db_update('corrections', corrections_with_actor([
        'answer'         => $clean,
        'status'         => 'answered',
        'publish_at'     => date('Y-m-d H:i:s', $t),
        'homepage_until' => date('Y-m-d H:i:s', $t + KUNYE_HOMEPAGE_SECS),
        'visible_until'  => date('Y-m-d H:i:s', $t + KUNYE_VISIBLE_SECS),
    ]), 'id = :i', [':i' => (int)$row['id']]);

    if (function_exists('cache_flush')) { cache_flush(); }

    // Başvurana tek alıcılık işlemsel bildirim (kapalıysa sessizce atlanır)
    $row['answer'] = $clean;
    $gonderildi = corrections_notify_applicant($row, 'answered');

    return [
        'ok' => true, 'error' => '', 'code' => 200,
        'mailed' => $gonderildi,
        'message' => 'Düzeltme metni yayımlandı. Haber altında 7 gün, ana sayfada 24 saat görünecek.'
                   . ($gonderildi ? ' Başvurana bilgilendirme e-postası gönderildi.' : ''),
    ];
}

/**
 * Talebi reddeder. Not dâhilîdir, ön yüzde yayımlanmaz.
 * @return array ['ok','message','error','code']
 */
function corrections_reject($id, $note) {
    $row = corrections_get($id);
    if (!$row) {
        return ['ok' => false, 'message' => '', 'error' => 'Düzeltme talebi bulunamadı.', 'code' => 404];
    }
    $note = sanitize_plain((string)$note, 2000);
    if (mb_strlen($note) < 3) {
        return ['ok' => false, 'message' => '', 'code' => 400,
                'error' => 'Ret gerekçesini kısaca yazın (en az 3 karakter). Bu not yayımlanmaz.'];
    }

    db_update('corrections', corrections_with_actor([
        'note'           => $note,
        'status'         => 'rejected',
        'publish_at'     => now(),
        'homepage_until' => null,
        'visible_until'  => null,
    ]), 'id = :i', [':i' => (int)$row['id']]);

    if (function_exists('cache_flush')) { cache_flush(); }

    // Başvurana bildirim — dâhilî ret notu e-postaya KONULMAZ
    $gonderildi = corrections_notify_applicant($row, 'rejected');

    return ['ok' => true, 'error' => '', 'code' => 200,
            'mailed' => $gonderildi,
            'message' => 'Talep reddedildi olarak işaretlendi. Not yalnız panelde görünür.'
                       . ($gonderildi ? ' Başvurana bilgilendirme e-postası gönderildi.' : '')];
}

/** Yayımlanmış düzeltme satırını tema biçimine çevirir. */
function corrections_public_row(array $r) {
    $slug = (string)arr($r, 'post_slug', '');
    $post = ['id' => (int)arr($r, 'post_id', 0), 'slug' => $slug];
    return [
        'id'          => (int)$r['id'],
        'post_id'     => (int)$r['post_id'],
        'post_title'  => (string)arr($r, 'post_title', ''),
        'post_url'    => $slug !== '' ? url_post($post) : '',
        'message'     => (string)arr($r, 'message', ''),
        'answer'      => (string)arr($r, 'answer', ''),            // temizlenmiş HTML — ham basılabilir
        'answer_text' => trim(strip_tags((string)arr($r, 'answer', ''))),
        'created_at'  => (string)arr($r, 'created_at', ''),
        'publish_at'  => (string)arr($r, 'publish_at', ''),
    ];
}

/**
 * Ana sayfada gösterilecek düzeltme metinleri (ilk 24 saat) — en yeni 3.
 * inc/view.php → home_correction_notices() bunu çağırır.
 */
function kunye_homepage_corrections() {
    try {
        $rows = qa('SELECT c.id, c.post_id, c.message, c.answer, c.created_at, c.publish_at,
                           p.title AS post_title, p.slug AS post_slug
                    FROM corrections c LEFT JOIN posts p ON p.id = c.post_id
                    WHERE c.status = \'answered\' AND c.homepage_until IS NOT NULL AND c.homepage_until >= :n
                    ORDER BY c.publish_at DESC, c.id DESC LIMIT 3', [':n' => now()]);
    } catch (Throwable $e) {
        return []; // migration henüz uygulanmadıysa tema bloğu basılmaz
    }
    $out = [];
    foreach ($rows as $r) { $out[] = corrections_public_row($r); }
    return $out;
}

/** Haber altında gösterilecek yayımlanmış düzeltmeler (yayım + 7 gün). */
function kunye_post_corrections($postId) {
    $postId = (int)$postId;
    if ($postId <= 0) { return []; }
    try {
        $rows = qa('SELECT c.id, c.post_id, c.message, c.answer, c.created_at, c.publish_at,
                           p.title AS post_title, p.slug AS post_slug
                    FROM corrections c LEFT JOIN posts p ON p.id = c.post_id
                    WHERE c.status = \'answered\' AND c.post_id = :p
                      AND c.visible_until IS NOT NULL AND c.visible_until >= :n
                    ORDER BY c.publish_at ASC, c.id ASC LIMIT 10', [':p' => $postId, ':n' => now()]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) { $out[] = corrections_public_row($r); }
    return $out;
}

// ================================================================ güncelleme notu

/**
 * Editoryal "güncelleme notu" — DÜZELTME/TEKZİP DEĞİLDİR.
 *
 * Ayrım kasıtlıdır ve karıştırılmamalıdır:
 *   • Düzeltme ve cevap (m.14) → okuyucunun talebi, yasal süreye tabi,
 *     sorumlu yazı işleri müdürünün yayımladığı metin.
 *   • Güncelleme notu          → yayıncının kendi editoryal açıklaması
 *     ("bu haber saat 14:30'da güncellendi, şu bilgi eklendi"). Yasal süre
 *     yoktur, süresiz görünür, başvurana e-posta gönderilmez.
 *
 * Kayıt `corrections` tablosunda `status = 'update'` ile tutulur; listeleme,
 * sayaç ve süre fonksiyonları bu türü düzeltmelerden ayıklar.
 *
 * @param int    $postId haber id'si
 * @param string $text   not metni (düz metin olarak alınır, HTML'e çevrilir)
 * @return array ['ok','message','error','code','id']
 */
function post_update_note_add($postId, $text) {
    $postId = (int)$postId;
    $post = ($postId > 0 && function_exists('post_by_id')) ? post_by_id($postId) : null;
    if (!$post) {
        return ['ok' => false, 'message' => '', 'error' => 'Haber bulunamadı.', 'code' => 404, 'id' => 0];
    }

    $plain = sanitize_plain((string)$text, 2000);
    if (mb_strlen($plain) < KUNYE_UPDATE_MIN) {
        return ['ok' => false, 'message' => '', 'code' => 400, 'id' => 0,
                'error' => 'Güncelleme notu en az ' . KUNYE_UPDATE_MIN . ' karakter olmalı.'];
    }

    // Ön yüzde basılacak sürüm: satır sonları korunarak paragrafa çevrilir,
    // sonra ortak temizleyiciden geçirilir (iframe yok).
    $html = sanitize_html('<p>' . str_replace("\n", '<br>', esc($plain)) . '</p>', false);

    $u = function_exists('current_user') ? current_user() : null;
    $ad = $u ? trim((string)(arr($u, 'display_name', '') !== '' ? $u['display_name'] : arr($u, 'name', ''))) : '';
    if ($ad === '') { $ad = 'Yayın kurulu'; }

    $data = [
        'post_id'    => $postId,
        'name'       => sanitize_line($ad, 120),
        'email'      => '',                       // başvuran yok — bildirim gönderilmez
        'message'    => $plain,
        'answer'     => $html,
        'note'       => '',
        'ip'         => function_exists('client_ip') ? client_ip() : '',
        'status'     => CORRECTION_KIND_UPDATE,
        'created_at' => now(),
        'publish_at' => now(),
    ];
    if (corrections_has_handled_by()) { $data['handled_by'] = corrections_actor_id(); }

    db_insert('corrections', $data);
    $id = (int)qv('SELECT id FROM corrections WHERE post_id = :p AND status = :s ORDER BY id DESC LIMIT 1',
        [':p' => $postId, ':s' => CORRECTION_KIND_UPDATE], 0);

    if (function_exists('cache_flush')) { cache_flush(); }

    return ['ok' => true, 'error' => '', 'code' => 200, 'id' => $id,
            'message' => 'Güncelleme notu eklendi. Haberin altında okura görünür.'];
}

/** Güncelleme notunu siler. */
function post_update_note_delete($id) {
    $id = (int)$id;
    $row = $id > 0 ? q1('SELECT id, status FROM corrections WHERE id = :i', [':i' => $id]) : null;
    if (!$row || (string)$row['status'] !== CORRECTION_KIND_UPDATE) {
        return ['ok' => false, 'message' => '', 'error' => 'Güncelleme notu bulunamadı.', 'code' => 404];
    }
    q('DELETE FROM corrections WHERE id = :i', [':i' => $id]);
    if (function_exists('cache_flush')) { cache_flush(); }
    return ['ok' => true, 'error' => '', 'code' => 200, 'message' => 'Güncelleme notu silindi.'];
}

/**
 * Haberin güncelleme notları — TEMANIN ÇAĞIRACAĞI FONKSİYON.
 *
 * Düzeltmelerden farklı olarak süresizdir (Basın Kanunu m.4'ün güncelleme
 * tarihi şeffaflığı ile uyumlu: not kalıcıdır).
 *
 * @return array [['id','text','html','created_at','by'], …] eskiden yeniye
 */
function post_update_notes($postId) {
    $postId = (int)$postId;
    if ($postId <= 0) { return []; }
    try {
        $rows = qa('SELECT id, name, message, answer, created_at FROM corrections
                    WHERE post_id = :p AND status = :s ORDER BY id ASC LIMIT 20',
            [':p' => $postId, ':s' => CORRECTION_KIND_UPDATE]);
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int)$r['id'],
            'text'       => (string)arr($r, 'message', ''),
            'html'       => (string)arr($r, 'answer', ''),   // temizlenmiş — ham basılabilir
            'created_at' => (string)arr($r, 'created_at', ''),
            'by'         => (string)arr($r, 'name', ''),
        ];
    }
    return $out;
}

/**
 * Güncelleme notu bloğunun hazır HTML'i — tema tek satırda basabilsin diye.
 * Not yoksa BOŞ METİN döner (tema koşul yazmak zorunda kalmaz).
 *
 * Kullanım (inc/view.php ya da tema single.php içinde):
 *     echo post_update_notes_html($post['id']);
 */
function post_update_notes_html($postId) {
    $notes = post_update_notes($postId);
    if (!$notes) { return ''; }

    $out = '<aside class="guncelleme-notlari" aria-label="Güncelleme notları">'
         . '<h3>Güncelleme notu</h3>';
    foreach ($notes as $n) {
        $out .= '<div class="guncelleme-notu">'
              . '<p class="guncelleme-tarih"><time datetime="' . esc($n['created_at']) . '">'
              . esc(tr_date($n['created_at'])) . '</time>'
              . ($n['by'] !== '' ? ' · ' . esc($n['by']) : '')
              . '</p>'
              . ($n['html'] !== '' ? $n['html'] : '<p>' . nl2br(esc($n['text']), false) . '</p>')
              . '</div>';
    }
    return $out . '</aside>';
}
