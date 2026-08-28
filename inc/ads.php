<?php
/**
 * Manşet — Reklam v2 (1.2-03).  Ajan-B.
 *
 * Bu dosya `inc/view.php` içindeki hazır kancayı doldurur:
 *     ads_slot_decorate($key, $html)
 * Görevi ÜÇ şey: (a) sponsorlu/reklam etiketi basmak, (b) gösterim sayımı için
 * işaret bırakmak, (c) görsel reklamın bağlantısını tıklama sayacından geçirmek.
 *
 * ---------------------------------------------------------------------------
 * NEDEN SUNUCUDA GÖSTERİM SAYILMIYOR — ÖLÇÜLMÜŞ GEREKÇE
 * ---------------------------------------------------------------------------
 * `ads_slot_decorate()` sayfa oluşturulurken çalışır. Sayfa önbelleği açıkken
 * (CONTRACTS §11, varsayılan AÇIK) ikinci ve sonraki ziyaretçiler hazır HTML
 * dosyasını alır; PHP hiç çalışmaz, bu fonksiyon hiç çağrılmaz. Yani sunucu
 * tarafı sayaç, önbellek TTL'i başına en çok BİR kez artar:
 *   anasayfa TTL 60 sn → saatte en çok 60 gösterim, gerçek trafik ne olursa olsun.
 * Ölçüm ve rakamlar: gelistirme/1.2-ajan-b.md.
 *
 * Bu yüzden gösterim, sayfanın kendisi önbellekten gelse bile çalışan tek yerde
 * sayılır: TARAYICIDA. Slot HTML'ine `data-ad-ids` bırakılır, satır içi küçük
 * bir betik reklam ekrana girdiğinde `api.php?a=ads.beacon` ucuna `fetch` atar.
 * Aynı desen 1.1'de okunma sayacı için zaten kullanılıyor (public.view).
 *
 * ÇEREZ KURALI (CONTRACTS §3.1): beacon ucu ANONİM ziyaretçiye çerez VERMEZ.
 *   · uç `perm => 'public'` — api.php `current_user()` çağırmaz, oturum açılmaz;
 *   · uç gövdesinde oturum açan hiçbir çağrı YOK (oturum başlatma, CSRF anahtarı
 *     üretme, kullanıcı okuma — üçü de yasak; birim test kaynağı tarar);
 *   · istemci tarafında `credentials: 'omit'` — var olan çerez bile gönderilmez.
 * Tekilleştirme bu yüzden oturum bazlı DEĞİL, IP başına hız sınırı iledir.
 *
 * TIKLAMA: bağlantı `api.php?a=ads.click&id=N` ucundan geçer. Hedef adres
 * İSTEKTEN OKUNMAZ — yalnız `ads.link_url` sütunundan. Böylece uç bir açık
 * yönlendirme (open redirect) aracına dönüşemez.
 *
 * HAM HTML KURALI DEĞİŞMEDİ: bu dosya `ad_render_row()`'un onay kapısına
 * dokunmaz; yalnız hazır çıktının ETRAFINI sarar. Ham kod reklamının içindeki
 * bağlantılar yeniden yazılmaz (üçüncü taraf koduna regex ile girilmez);
 * yalnız `kind='image'` kayıtlarının kendi ürettiğimiz çıpası değiştirilir.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

// ============================================================ ayarlar

/**
 * Sayaç toplama açık mı? (varsayılan AÇIK)
 * Ayar anahtarı bilerek OLUMSUZ: gerekçesi admin/settings-groups/40-reklam.php içinde.
 */
function ads_counting_enabled() { return setting('ads_count_disabled', '0') !== '1'; }

/**
 * Reklam etiketi metni. BOŞ BIRAKILAMAZ: ayar boşsa varsayılana düşer.
 * Etiketin kendisi kapatılabilir bir seçenek değildir (bkz. dosya başı notu).
 */
function ads_label_text() {
    $v = trim((string)setting('ads_label_text', ''));
    return $v !== '' ? $v : 'Reklam';
}

/** Sponsorlu içerik etiketi metni. Aynı kural: boşsa varsayılana düşer. */
function ads_sponsored_label() {
    $v = trim((string)setting('ads_sponsored_label', ''));
    return $v !== '' ? $v : 'Sponsorlu içerik';
}

/** Gövde içi reklamın hangi paragraftan sonra basılacağı. 0 = kapalı. */
function ads_inbody_paragraph() {
    return max(0, min(30, (int)setting('ads_inbody_paragraph', '0')));
}

/** İstatistik kaç gün saklanır. */
function ads_stats_keep_days() {
    return max(30, min(1825, (int)setting('ads_stats_keep_days', '400')));
}

// ============================================================ uygun reklamlar

/**
 * Yayında olan reklamlar, slot anahtarına göre gruplanmış.
 * `ad_slot()` ile AYNI ölçütleri kullanır; amaç aynı kayıt kümesini bulmaktır.
 * Sorgu istek başına bir kez çalışır.
 */
function ads_eligible_by_slot() {
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        $rows = qa('SELECT id, slot_key, kind, link_url, is_sponsored FROM ads
            WHERE active = 1
              AND (starts_at IS NULL OR starts_at = \'\' OR starts_at <= :n1)
              AND (ends_at IS NULL OR ends_at = \'\' OR ends_at >= :n2)
            ORDER BY sort ASC, id ASC', [':n1' => now(), ':n2' => now()]);
    } catch (Throwable $e) {
        // `is_sponsored` sütunu yoksa (göç uygulanmamış) v1 sorgusuna düşülür.
        try {
            $rows = qa('SELECT id, slot_key, kind, link_url FROM ads
                WHERE active = 1
                  AND (starts_at IS NULL OR starts_at = \'\' OR starts_at <= :n1)
                  AND (ends_at IS NULL OR ends_at = \'\' OR ends_at >= :n2)
                ORDER BY sort ASC, id ASC', [':n1' => now(), ':n2' => now()]);
        } catch (Throwable $e2) { return $cache; }
    }
    foreach ($rows as $r) { $cache[(string)$r['slot_key']][] = $r; }
    return $cache;
}

/** Bir slottaki yayında olan reklam kayıtları. */
function ads_slot_rows($key) {
    $all = ads_eligible_by_slot();
    return isset($all[$key]) ? $all[$key] : [];
}

// ============================================================ etiket

/**
 * Slot için okuyucuya gösterilecek etiket.
 * Slotta sponsorlu bir kayıt varsa "Sponsorlu içerik" de eklenir; her ikisi de
 * varsa ikisi birden yazılır — okuyucu hangi kutunun ne olduğunu bilmeli.
 */
function ads_slot_label(array $rows) {
    $reklam = false; $sponsor = false;
    foreach ($rows as $r) {
        if ((int)arr($r, 'is_sponsored', 0) === 1) { $sponsor = true; } else { $reklam = true; }
    }
    if (!$rows) { $reklam = true; }
    $parts = [];
    if ($reklam)  { $parts[] = ads_label_text(); }
    if ($sponsor) { $parts[] = ads_sponsored_label(); }
    return implode(' · ', $parts);
}

// ============================================================ tıklama bağlantısı

/** Tıklama sayacı ucunun adresi. */
function ads_click_url($adId) {
    return base_url() . '/api.php?a=ads.click&id=' . (int)$adId;
}

/**
 * Görsel reklamın çıpasındaki `href`'i tıklama ucuyla değiştirir.
 *
 * REGEX KULLANILMAZ: `ad_render_row()` çıpayı BİREBİR şu biçimde üretir, bu
 * yüzden aranacak dize tam olarak yeniden kurulabilir. Böylece ham HTML
 * reklamının (üçüncü taraf kodu) içine hiç dokunulmaz.
 */
function ads_rewrite_click($html, array $row) {
    if ((string)arr($row, 'kind', 'html') !== 'image') { return $html; }
    $link = (string)arr($row, 'link_url', '');
    if ($link === '' || !sanitize_is_safe_url($link, false)) { return $html; }
    $eski = '<a href="' . esc($link) . '" rel="nofollow noopener sponsored" target="_blank">';
    if (strpos($html, $eski) === false) { return $html; }
    $yeni = '<a href="' . esc(ads_click_url($row['id'])) . '" rel="nofollow noopener sponsored" target="_blank">';
    // Yalnız İLK eşleşme değiştirilir: aynı hedefe giden iki ayrı kayıt varsa
    // her biri kendi turunda kendi çıpasını alsın.
    $poz = strpos($html, $eski);
    return substr($html, 0, $poz) . $yeni . substr($html, $poz + strlen($eski));
}

// ============================================================ KANCA

/**
 * inc/view.php → ad_slot() bunu çağırır. Modül yoksa reklam 1.1'deki gibi basılır.
 *
 * @param string $key  slot anahtarı (header, sidebar_1, sidebar_2, in_article, footer)
 * @param string $html ad_slot()'un ürettiği ham çıktı
 */
function ads_slot_decorate($key, $html) {
    $html = (string)$html;
    if (trim($html) === '') { return $html; }

    $rows = ads_slot_rows((string)$key);

    // Tıklama sayacı — yalnız görsel reklamların kendi çıpası.
    foreach ($rows as $r) { $html = ads_rewrite_click($html, $r); }

    $ids = [];
    foreach ($rows as $r) { $ids[] = (int)$r['id']; }

    $etiket = ads_slot_label($rows);
    $veri   = ads_counting_enabled() && $ids ? ' data-ad-ids="' . esc(implode(',', $ids)) . '"' : '';

    $out = '<div class="ad-kutu" data-ad-slot="' . esc((string)$key) . '"' . $veri . '>'
         . '<span class="ad-etiket">' . esc($etiket) . '</span>'
         . $html
         . '</div>';

    $out .= ads_inline_css();
    if ($veri !== '') { $out .= ads_beacon_script(); }
    return $out;
}

/** Etiket için asgari biçim. Bir kez basılır; dış CSS dosyası yok (sözleşme). */
function ads_inline_css() {
    static $basildi = false;
    if ($basildi) { return ''; }
    $basildi = true;
    return '<style>.ad-kutu{position:relative}'
        . '.ad-kutu>.ad-etiket{display:block;font-size:11px;line-height:1.6;letter-spacing:.04em;'
        . 'text-transform:uppercase;opacity:.7;margin:0 0 4px}</style>';
}

/**
 * Gösterim beacon'ı — satır içi, tek kez.
 *
 * NEDEN SATIR İÇİ: `assets/ads.js` ayrı bir dosya olsaydı temaların
 * `layout.php` dosyasına `<script src>` eklenmesi gerekirdi; o dosyalar
 * Ajan-6/7'ye ait ve beş temanın hepsi değişmek zorunda kalırdı. Satır içi
 * betik yalnız reklam BASILAN sayfalarda çıkar ve tema değişikliği istemez.
 *
 * ÇEREZ YOK: `credentials:'omit'` — tarayıcı var olan çerezi bile göndermez.
 */
function ads_beacon_script() {
    static $basildi = false;
    if ($basildi) { return ''; }
    $basildi = true;
    $uc = json_encode(base_url() . '/api.php?a=ads.beacon');
    return '<script>(function(){'
        . 'var UC=' . $uc . ',gorulen={},kuyruk=[],zaman=null;'
        . 'function gonder(){if(!kuyruk.length){return;}var v=kuyruk.slice();kuyruk=[];'
        . 'try{fetch(UC,{method:"POST",credentials:"omit",headers:{"Content-Type":"application/json"},'
        . 'body:JSON.stringify({ids:v}),keepalive:true})["catch"](function(){});}catch(e){}}'
        . 'function kuyrukla(el){var ham=el.getAttribute("data-ad-ids")||"";'
        . 'ham.split(",").forEach(function(s){var n=parseInt(s,10);'
        . 'if(n>0&&!gorulen[n]){gorulen[n]=1;kuyruk.push(n);}});'
        . 'if(zaman){clearTimeout(zaman);}zaman=setTimeout(gonder,600);}'
        . 'function basla(){var liste=document.querySelectorAll("[data-ad-ids]");'
        . 'if(!liste.length){return;}'
        . 'if(!window.IntersectionObserver){for(var i=0;i<liste.length;i++){kuyrukla(liste[i]);}return;}'
        . 'var g=new IntersectionObserver(function(kayitlar){kayitlar.forEach(function(k){'
        . 'if(k.isIntersecting){kuyrukla(k.target);g.unobserve(k.target);}});},{threshold:0.5});'
        . 'for(var j=0;j<liste.length;j++){g.observe(liste[j]);}}'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",basla);}else{basla();}'
        . '})();</script>';
}

// ============================================================ sayaç yazımı

/** Bugünün gün anahtarı (YYYY-MM-DD). */
function ads_today() { return substr(now(), 0, 10); }

/**
 * (reklam, gün) satırındaki bir sayacı artırır.
 *
 * ÖNCE UPDATE, TUTMAZSA INSERT: iki sürücüde de aynı çalışan tek yol budur
 * (SQLite'ın `ON CONFLICT` ve MySQL'in `ON DUPLICATE KEY` sözdizimleri farklı).
 * Yarışta INSERT tekil kısıta takılırsa bir kez daha UPDATE denenir.
 *
 * @param string $alan 'impressions' | 'clicks'  — SABİT, girdiden gelmez.
 */
function ads_stat_bump($adId, $alan, $slotKey = '', $adet = 1) {
    $adId = (int)$adId;
    $adet = max(1, min(100, (int)$adet));
    if ($adId <= 0) { return false; }
    if ($alan !== 'impressions' && $alan !== 'clicks') { return false; }

    $gun = ads_today();
    $sql = $alan === 'impressions'
        ? 'UPDATE ad_stats SET impressions = impressions + :n, updated_at = :u WHERE ad_id = :a AND day = :d'
        : 'UPDATE ad_stats SET clicks = clicks + :n, updated_at = :u WHERE ad_id = :a AND day = :d';
    try {
        $st = q($sql, [':n' => $adet, ':u' => now(), ':a' => $adId, ':d' => $gun]);
        if ($st->rowCount() > 0) { return true; }
    } catch (Throwable $e) {
        log_error('ads_stat_bump güncelleme: ' . $e->getMessage());
        return false;
    }
    try {
        db_insert('ad_stats', [
            'ad_id'       => $adId,
            'day'         => $gun,
            'slot_key'    => substr((string)$slotKey, 0, 40),
            'impressions' => $alan === 'impressions' ? $adet : 0,
            'clicks'      => $alan === 'clicks' ? $adet : 0,
            'updated_at'  => now(),
        ]);
        return true;
    } catch (Throwable $e) {
        // Yarış: başka bir istek satırı bu arada açtı. Tekrar UPDATE.
        try { q($sql, [':n' => $adet, ':u' => now(), ':a' => $adId, ':d' => $gun]); return true; }
        catch (Throwable $e2) { log_error('ads_stat_bump ekleme: ' . $e2->getMessage()); }
    }
    return false;
}

/**
 * Beacon'dan gelen kimlik listesini sayar.
 * Kimlikler DOĞRULANIR: yalnız o an YAYINDA olan reklamlar sayılır, uydurma
 * kimlik gönderen bir istemci veritabanında satır açtıramaz.
 *
 * @return int sayılan reklam adedi
 */
function ads_record_impressions(array $ids) {
    if (!ads_counting_enabled()) { return 0; }
    $gecerli = [];
    foreach (ads_eligible_by_slot() as $slot => $rows) {
        foreach ($rows as $r) { $gecerli[(int)$r['id']] = (string)$slot; }
    }
    $sayilan = 0;
    $gorulen = [];
    foreach ($ids as $ham) {
        $id = (int)$ham;
        if ($id <= 0 || isset($gorulen[$id]) || !isset($gecerli[$id])) { continue; }
        $gorulen[$id] = true;
        if (ads_stat_bump($id, 'impressions', $gecerli[$id])) { $sayilan++; }
        if ($sayilan >= 20) { break; }   // tek istekte en çok 20 slot
    }
    return $sayilan;
}

/**
 * Tıklamayı sayar ve reklamın KENDİ hedef adresini döndürür.
 * Adres yalnız veritabanından okunur; istekten gelen hiçbir değer hedefe
 * dönüşmez (açık yönlendirme kalkanı). Geçersizse boş dize.
 */
function ads_click_target($adId) {
    $adId = (int)$adId;
    if ($adId <= 0) { return ''; }
    $row = q1('SELECT id, slot_key, kind, link_url, active, starts_at, ends_at FROM ads WHERE id = :i', [':i' => $adId]);
    if (!$row) { return ''; }
    if ((string)arr($row, 'kind', 'html') !== 'image') { return ''; }
    $link = (string)arr($row, 'link_url', '');
    // İKİNCİ DOĞRULAMA: kayıt yazıldıktan sonra sütun elle değiştirilmiş
    // olabilir (yedek geri yükleme, doğrudan SQL). Yönlendirmeden önce yeniden bak.
    if ($link === '' || !preg_match('#^https?://#i', $link) || !sanitize_is_safe_url($link, false)) { return ''; }
    ads_stat_bump($adId, 'clicks', (string)arr($row, 'slot_key', ''));
    return $link;
}

// ============================================================ rapor

/**
 * Son N günün reklam başına toplamı.
 * @return array [ ['ad_id'=>…, 'title'=>…, 'slot_key'=>…, 'impressions'=>…, 'clicks'=>…, 'ctr'=>float], … ]
 */
function ads_stats_summary($gun = 30) {
    $gun = max(1, min(365, (int)$gun));
    $bas = date('Y-m-d', time() - ($gun - 1) * 86400);
    try {
        $rows = qa('SELECT s.ad_id, s.slot_key, SUM(s.impressions) AS imp, SUM(s.clicks) AS clk
            FROM ad_stats s WHERE s.day >= :b GROUP BY s.ad_id, s.slot_key ORDER BY imp DESC', [':b' => $bas]);
    } catch (Throwable $e) { return []; }

    $baslik = [];
    try {
        foreach (qa('SELECT id, title, kind FROM ads') as $a) {
            $baslik[(int)$a['id']] = (string)$a['title'] !== '' ? (string)$a['title'] : '(başlıksız)';
        }
    } catch (Throwable $e) { }

    $out = [];
    foreach ($rows as $r) {
        $imp = (int)$r['imp']; $clk = (int)$r['clk'];
        $id  = (int)$r['ad_id'];
        $out[] = [
            'ad_id'       => $id,
            'title'       => isset($baslik[$id]) ? $baslik[$id] : '(silinmiş reklam #' . $id . ')',
            'slot_key'    => (string)$r['slot_key'],
            'impressions' => $imp,
            'clicks'      => $clk,
            'ctr'         => $imp > 0 ? round($clk * 100 / $imp, 2) : 0.0,
        ];
    }
    return $out;
}

/** Son N günün gün gün toplamı (grafik/çizelge için). */
function ads_stats_daily($gun = 30) {
    $gun = max(1, min(365, (int)$gun));
    $bas = date('Y-m-d', time() - ($gun - 1) * 86400);
    try {
        return qa('SELECT day, SUM(impressions) AS imp, SUM(clicks) AS clk
            FROM ad_stats WHERE day >= :b GROUP BY day ORDER BY day ASC', [':b' => $bas]);
    } catch (Throwable $e) { return []; }
}

// ============================================================ ads.txt

/** Panelde saklanan ads.txt gövdesi. */
function ads_txt_content() { return (string)setting('ads_txt', ''); }

/**
 * ads.txt metnini temizler.
 * Dosya biçimi düz metindir; HTML yoktur. Denetim karakterleri atılır, satır
 * sonu normalleştirilir, boyut sınırlanır (IAB dosyaları birkaç KB olur).
 */
function ads_txt_clean($metin) {
    $t = (string)$metin;
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $t);
    $t = trim($t);
    if (strlen($t) > 65536) { $t = substr($t, 0, 65536); }
    return $t;
}

/** Kayda değer (yorum/boş olmayan) satır sayısı. */
function ads_txt_line_count($metin) {
    $n = 0;
    foreach (explode("\n", (string)$metin) as $satir) {
        $s = trim($satir);
        if ($s === '' || $s[0] === '#') { continue; }
        $n++;
    }
    return $n;
}

/** Kök dizindeki statik ads.txt dosyasının yolu. */
function ads_txt_static_path() { return ROOT_DIR . '/ads.txt'; }

/** Kökte statik bir ads.txt var mı ve paneldekiyle aynı mı? */
function ads_txt_static_state() {
    $yol = ads_txt_static_path();
    if (!is_file($yol)) { return ['var' => false, 'ayni' => false]; }
    $icerik = ads_txt_clean((string)@file_get_contents($yol));
    return ['var' => true, 'ayni' => $icerik === ads_txt_clean(ads_txt_content())];
}

// ============================================================ gövde içi reklam

/**
 * Haber gövdesine N. paragraftan SONRA reklam yerleştirir.
 *
 * GÖVDE HTML'İ BOZULMAZ: kapanış `</p>` etiketleri sayılır ve araya yalnız
 * ekleme yapılır; hiçbir etiket silinmez, kesilmez, yeniden yazılmaz.
 * `</p>` bir metin düğümünde ya da öznitelikte GEÇEMEZ (sanitize_html çıktısında
 * `<` kaçırılmıştır), bu yüzden sayım güvenlidir.
 *
 * Reklam SON paragraftan sonra basılmaz: orada zaten `ad_slot('in_article')`
 * teması tarafından basılıyor, üst üste iki kutu çıkardı.
 *
 * @param string $html  post_body_html() çıktısı
 * @param string $key   kullanılacak slot (varsayılan in_article)
 * @return string
 */
function ads_body_inject($html, $key = 'in_article') {
    $html = (string)$html;
    $n = ads_inbody_paragraph();
    if ($n <= 0 || $html === '') { return $html; }

    if (!preg_match_all('#</p>#i', $html, $m, PREG_OFFSET_CAPTURE)) { return $html; }
    // En az n+1 paragraf olmalı: aksi hâlde reklam gövdenin sonuna düşer.
    if (count($m[0]) <= $n) { return $html; }

    $reklam = ad_slot($key);
    if (trim($reklam) === '') { return $html; }

    $poz = (int)$m[0][$n - 1][1] + strlen($m[0][$n - 1][0]);
    return substr($html, 0, $poz) . $reklam . substr($html, $poz);
}

// ============================================================ bakım

/**
 * Eski istatistik satırlarını siler.
 * UCUZ görev: ağa çıkmaz, tek DELETE çalıştırır.
 */
function ads_cron_prune() {
    $sinir = date('Y-m-d', time() - ads_stats_keep_days() * 86400);
    try {
        $st = q('DELETE FROM ad_stats WHERE day < :b', [':b' => $sinir]);
        $n = $st->rowCount();
        return $n > 0 ? ($n . ' eski reklam istatistiği silindi') : 'silinecek eski kayıt yok';
    } catch (Throwable $e) {
        return 'reklam istatistiği temizlenemedi: ' . $e->getMessage();
    }
}

if (function_exists('cron_register')) {
    cron_register('reklam', 'ads_cron_prune', true);
}
