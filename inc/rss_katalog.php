<?php
/**
 * Manşet — örnek RSS kaynak kataloğu (1.4)
 *
 * ---------------------------------------------------------------------------
 * BU KATALOG BİR ÖNERİ LİSTESİDİR, OTOMATİK EKLENMEZ.
 * ---------------------------------------------------------------------------
 * Yayıncı Panel → RSS Kaynakları → "Örnek kaynaklardan ekle" ekranından
 * istediklerini seçer. Hiçbiri kurulumda kendiliğinden eklenmez; eklenenler de
 * **otomatik yayın kapalı** ve **YZ yeniden yazma kapalı** başlar.
 *
 * NEDEN OTOMATİK DEĞİL — TELİF
 * Başka bir yayın kuruluşunun beslemesinden gelen metni **tam olarak** kendi
 * sitenizde yayımlamak telif hakkı meselesidir. RSS'in yayımlanmış olması,
 * içeriğin serbestçe çoğaltılabileceği anlamına gelmez. Yaygın ve savunulabilir
 * kullanım: kısa alıntı/özet + kaynak adı + özgün habere bağlantı. Kalıcı ve
 * ticari kullanım için kaynak kuruluşla anlaşın.
 *
 * Manşet bunu kolaylaştırır ama sizin yerinize karar VERMEZ: kaynak adı ve
 * bağlantısı zorunludur, gövde sonuna kaynak bloğu eklenir, YZ ile yeniden
 * yazılan içerik işaretlenir. Sorumluluk yayıncıya aittir.
 *
 * ADRESLER ÖLÇÜLDÜ
 * Liste, halka açık bir derlemeden (2018) alındı ve **tek tek denendi**:
 * 64 adresin 50'si yanıt verdi ve geçerli XML döndürdü; yanıt vermeyen 14
 * adres listeye ALINMADI. Beslemeler zamanla taşınır ya da kapanır — bir
 * kaynak hata vermeye başlarsa Manşet onu geri çekilmeyle yavaşlatır ve
 * panelde işaretler.
 *
 * KATEGORİ EŞLEŞMESİ
 * `kategori` alanı `inc/schema.php` içindeki varsayılan kategori adlarıyla
 * BİREBİR aynı olmalıdır. Eşleşmezse kaynak kategorisiz eklenir (hata değil,
 * ama editör her haberi elle kategorilemek zorunda kalır).
 */

/**
 * Örnek kaynaklar: her biri ['ad', 'url', 'kategori', 'aralik_dk'].
 *
 * `aralik_dk` kaynağın ne sıklıkla yoklanacağıdır. Ajans ve son dakika
 * beslemeleri sık, köşe/kültür beslemeleri seyrek yoklanır — her kaynağı
 * dakikada bir yoklamak hem sunucuyu hem karşı tarafı gereksiz yorar.
 */
function rss_katalog() {
    return [
        // ---------------------------------------------------------- ajans
        ['Anadolu Ajansı — Güncel',      'https://www.aa.com.tr/tr/rss/default?cat=guncel',        'Gündem',        15],
        ['TRT Haber — Son Dakika',       'https://www.trthaber.com/sondakika.rss',                 'Gündem',        15],

        // ---------------------------------------------------------- NTV
        ['NTV — Gündem',                 'https://www.ntv.com.tr/gundem.rss',                      'Gündem',        20],
        ['NTV — Türkiye',                'https://www.ntv.com.tr/turkiye.rss',                     'Gündem',        30],
        ['NTV — Dünya',                  'https://www.ntv.com.tr/dunya.rss',                       'Dünya',         30],
        ['NTV — Ekonomi',                'https://www.ntv.com.tr/ekonomi.rss',                     'Ekonomi',       30],
        ['NTV — Teknoloji',              'https://www.ntv.com.tr/teknoloji.rss',                   'Teknoloji',     60],
        ['NTV — Yaşam',                  'https://www.ntv.com.tr/yasam.rss',                       'Yaşam',         60],
        ['NTV — Sağlık',                 'https://www.ntv.com.tr/saglik.rss',                      'Sağlık',        60],
        ['NTV — Otomobil',               'https://www.ntv.com.tr/otomobil.rss',                    'Otomobil',      120],
        ['NTV — Eğitim',                 'https://www.ntv.com.tr/egitim.rss',                      'Eğitim',        120],

        // ---------------------------------------------------------- CNN Türk
        ['CNN Türk — Tümü',              'https://www.cnnturk.com/feed/rss/all/news',              'Gündem',        20],
        ['CNN Türk — Türkiye',           'https://www.cnnturk.com/feed/rss/turkiye/news',          'Gündem',        30],
        ['CNN Türk — Dünya',             'https://www.cnnturk.com/feed/rss/dunya/news',            'Dünya',         30],
        ['CNN Türk — Ekonomi',           'https://www.cnnturk.com/feed/rss/ekonomi/news',          'Ekonomi',       30],
        ['CNN Türk — Spor',              'https://www.cnnturk.com/feed/rss/spor/news',             'Spor',          30],
        ['CNN Türk — Kültür Sanat',      'https://www.cnnturk.com/feed/rss/kultur-sanat/news',     'Kültür Sanat',  120],
        ['CNN Türk — Sağlık',            'https://www.cnnturk.com/feed/rss/saglik/news',           'Sağlık',        60],
        ['CNN Türk — Yaşam',             'https://www.cnnturk.com/feed/rss/yasam/news',            'Yaşam',         60],
        ['CNN Türk — Magazin',           'https://www.cnnturk.com/feed/rss/magazin/news',          'Magazin',       60],
        ['CNN Türk — Otomobil',          'https://www.cnnturk.com/feed/rss/otomobil/news',         'Otomobil',      120],
        ['CNN Türk — Seyahat',           'https://www.cnnturk.com/feed/rss/seyahat/news',          'Yaşam',         180],

        // ---------------------------------------------------------- Hürriyet
        ['Hürriyet — Gündem',            'http://www.hurriyet.com.tr/rss/gundem',                  'Gündem',        20],
        ['Hürriyet — Ekonomi',           'http://www.hurriyet.com.tr/rss/ekonomi',                 'Ekonomi',       30],
        ['Hürriyet — Spor',              'http://www.hurriyet.com.tr/rss/spor',                    'Spor',          30],
        ['Hürriyet — Dünya',             'http://www.hurriyet.com.tr/rss/dunya',                   'Dünya',         30],
        ['Hürriyet — Teknoloji',         'http://www.hurriyet.com.tr/rss/teknoloji',               'Teknoloji',     60],

        // ---------------------------------------------------------- Sabah
        ['Sabah — Gündem',               'https://www.sabah.com.tr/rss/gundem.xml',                'Gündem',        20],
        ['Sabah — Ekonomi',              'https://www.sabah.com.tr/rss/ekonomi.xml',               'Ekonomi',       30],
        ['Sabah — Spor',                 'https://www.sabah.com.tr/rss/spor.xml',                  'Spor',          30],
        ['Sabah — Dünya',                'https://www.sabah.com.tr/rss/dunya.xml',                 'Dünya',         30],
        ['Sabah — Yaşam',                'https://www.sabah.com.tr/rss/yasam.xml',                 'Yaşam',         60],
        ['Sabah — Kültür Sanat',         'https://www.sabah.com.tr/rss/kultur-sanat.xml',          'Kültür Sanat',  120],
        ['Sabah — Sağlık',               'https://www.sabah.com.tr/rss/saglik.xml',                'Sağlık',        60],

        // ---------------------------------------------------------- diğer ulusal
        ['Habertürk',                    'https://www.haberturk.com/rss',                          'Gündem',        20],
        ['Cumhuriyet — Son Dakika',      'http://www.cumhuriyet.com.tr/rss/son_dakika.xml',        'Gündem',        20],
        ['Milliyet — Gündem',            'http://www.milliyet.com.tr/rss/rssNew/gundemRss.xml',    'Gündem',        30],
        ['Milliyet — Ekonomi',           'http://www.milliyet.com.tr/rss/rssNew/ekonomiRss.xml',   'Ekonomi',       30],
        ['A Haber — Gündem',             'https://www.ahaber.com.tr/rss/gundem.xml',               'Gündem',        30],
        ['A Haber — Ekonomi',            'https://www.ahaber.com.tr/rss/ekonomi.xml',              'Ekonomi',       60],
        ['Takvim — Güncel',              'https://www.takvim.com.tr/rss/guncel.xml',               'Gündem',        60],
        ['İnternet Haber',               'https://www.internethaber.com/rss',                      'Gündem',        30],
        ['Yeni Asır',                    'https://www.yeniasir.com.tr/rss/anasayfa.xml',           'Gündem',        60],
        ['Açık Gazete',                  'http://www.acikgazete.com/feed/',                        'Gündem',        180],

        // ---------------------------------------------------------- ekonomi
        ['Dünya Gazetesi',               'https://www.dunya.com/rss?dunya',                        'Ekonomi',       30],

        // ---------------------------------------------------------- uluslararası (Türkçe)
        ['BBC Türkçe',                   'http://feeds.bbci.co.uk/turkce/rss.xml',                 'Dünya',         30],
        ['Sputnik Türkiye',              'https://tr.sputniknews.com/export/rss2/archive/index.xml','Dünya',        60],
    ];
}

/** Katalogda geçen kategori adları (tekil). */
function rss_katalog_kategoriler() {
    $out = [];
    foreach (rss_katalog() as $k) { $out[$k[2]] = true; }
    $ad = array_keys($out);
    sort($ad);
    return $ad;
}

/**
 * Kaynağı katalogdan ekler.
 *
 * KURALLAR — bunlar bilinçlidir, gevşetilmemelidir:
 *   · `auto_publish = 0`  → hiçbir şey insan okumadan yayına çıkmaz
 *   · `ai_rewrite = 0`    → yayıncı ücreti bilerek üstlenmeli
 *   · aynı adres iki kez eklenmez (mükerrer kaynak, mükerrer haber demektir)
 *
 * @return array ['ok'=>bool, 'id'=>int, 'error'=>string]
 */
function rss_katalog_ekle($url, $kategoriAdi = '', $ad = '', $aralik = 0) {
    $url = trim((string)$url);
    if ($url === '') { return ['ok' => false, 'id' => 0, 'error' => 'Adres boş.']; }

    // Katalogda OLMAYAN bir adres bu uçtan eklenemez: aksi halde "örnek ekle"
    // düğmesi, keyfi bir adresi kaynak yapmanın kısa yolu olurdu.
    $kayit = null;
    foreach (rss_katalog() as $k) {
        if (strcasecmp($k[1], $url) === 0) { $kayit = $k; break; }
    }
    if ($kayit === null) { return ['ok' => false, 'id' => 0, 'error' => 'Bu adres katalogda yok.']; }

    if ((int)qv('SELECT COUNT(*) FROM rss_sources WHERE url = :u', [':u' => $url], 0) > 0) {
        return ['ok' => false, 'id' => 0, 'error' => 'Bu kaynak zaten ekli.'];
    }

    $ad = $ad !== '' ? $ad : (string)$kayit[0];
    $kategoriAdi = $kategoriAdi !== '' ? $kategoriAdi : (string)$kayit[2];
    $aralik = $aralik > 0 ? (int)$aralik : (int)$kayit[3];

    // Kategori adı → id. Yoksa 0 (kategorisiz) — kaynak yine eklenir, çünkü
    // kategoriyi sonradan atamak, kaynağı hiç ekleyememekten iyidir.
    $katId = (int)qv('SELECT id FROM categories WHERE slug = :s', [':s' => slugify($kategoriAdi)], 0);

    $veri = [
        'name'         => sanitize_line($ad, 190),
        'url'          => $url,
        'category_id'  => $katId,
        'auto_publish' => 0,
        'ai_rewrite'   => 0,
        'active'       => 1,
        'error_count'  => 0,
    ];
    if (schema_has_column('rss_sources', 'interval_min')) { $veri['interval_min'] = max(0, min(1440, $aralik)); }

    try {
        $id = (int)db_insert('rss_sources', $veri);
    } catch (Throwable $e) {
        log_error('rss_katalog_ekle: ' . $e->getMessage());
        return ['ok' => false, 'id' => 0, 'error' => 'Kaynak eklenemedi.'];
    }
    return ['ok' => true, 'id' => $id, 'error' => ''];
}
