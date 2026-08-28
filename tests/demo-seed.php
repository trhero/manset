<?php
/**
 * Manşet — dolu demo veri üretici.
 *
 * Kullanım:  php tests/demo-seed.php [--reset]
 *   --reset  Mevcut haber/yorum/reklam/kategori verisini siler, sıfırdan üretir.
 *
 * Üretilenler: 5 kategori, 40 haber (manşetler, son dakika, video, YZ üretimi örnekleri),
 * 3 kullanıcı, ~35 yorum, 4 reklam örneği, 2 RSS kaynağı, 3 sabit sayfa.
 * Yalnız kurulu bir örnek üzerinde çalışır (config.php gerekir).
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Bu betik yalnız komut satırından çalıştırılır.\n";
    exit(1);
}
if (!is_file(ROOT_DIR . '/config.php')) {
    echo "config.php bulunamadı. Önce kurulumu tamamlayın veya: php tests/dev-install.php\n";
    exit(1);
}

require_once ROOT_DIR . '/inc/schema.php';
require_once ROOT_DIR . '/inc/sanitize.php';
// Görsel içe aktarımı için medya katmanı (doğrulama + varyant üretimi onda)
if (is_file(ROOT_DIR . '/inc/media.php')) { require_once ROOT_DIR . '/inc/media.php'; }
// Faz 7 üyelik modülü (eski kurulumlarda bulunmayabilir)
if (is_file(ROOT_DIR . '/inc/members.php')) { require_once ROOT_DIR . '/inc/members.php'; }

$reset = in_array('--reset', $argv, true);

schema_install();
migrations_run();

if ($reset) {
    foreach (['comments', 'corrections', 'posts', 'ads', 'rss_items', 'rss_sources', 'ai_jobs'] as $t) {
        if (schema_has_table($t)) { q('DELETE FROM ' . $t); }
    }
    // Faz 7: demo personel ve üye hesapları da temizlenir (yönetici korunur)
    if (schema_has_table('memberships')) {
        q('DELETE FROM memberships WHERE user_id IN (SELECT id FROM users WHERE email LIKE \'%@ornek.test\')');
    }
    q('DELETE FROM users WHERE email LIKE \'%@ornek.test\' AND role <> \'admin\'');
    // Demo görselleri de düşer: dosyalar uploads/ altında yetim kalmasın.
    if (schema_has_table('media') && function_exists('media_delete')) {
        foreach (qa('SELECT id FROM media WHERE filename LIKE \'%demo-%\'') as $m) {
            try { media_delete((int)$m['id']); } catch (Throwable $e) { /* dosya yoksa geç */ }
        }
    }
    q('DELETE FROM categories WHERE slug NOT IN (\'gundem\',\'ekonomi\',\'spor\')');
    echo "Mevcut demo verisi silindi.\n";
}

// ---------------------------------------------------------------- kullanıcılar
$adminId = (int)qv('SELECT id FROM users WHERE role = \'admin\' ORDER BY id ASC LIMIT 1', [], 0);
if (!$adminId) { echo "Yönetici kullanıcı yok. Önce kurulumu tamamlayın.\n"; exit(1); }

// Faz 7: 10 rolün her biri demo veride temsil edilir ki izin matrisi
// ve panel görünürlüğü kurulumdan hemen sonra denenebilsin.
$demoUsers = [
    ['Selin Aydemir',      'selin@ornek.test',   'chief_editor', 1],
    ['Mert Kılıçarslan',   'mert@ornek.test',    'editor',       0],
    ['Deniz Şahinoğlu',    'deniz@ornek.test',   'yazar',        0],
    ['Ece Tunçdemir',      'ece@ornek.test',     'yazar',        0],
    ['Barış Yıldırımlı',   'baris@ornek.test',   'reporter',     0],
    ['Nihan Özgüven',      'nihan@ornek.test',   'wire_editor',  0],
    ['Kaan Serttaş',       'kaan@ornek.test',    'seo_editor',   0],
    ['Pelin Akarsu',       'pelin@ornek.test',   'ads_manager',  0],
    ['Tolga Erdinç',       'tolga@ornek.test',   'moderator',    0],
];
// Haber yazarı olarak kullanılacak roller (reklam/moderasyon rolleri haber yazmaz)
$yazanRoller = ['chief_editor', 'editor', 'yazar', 'reporter', 'wire_editor'];
$userIds = [$adminId];
$demoUserIds = [];
foreach ($demoUsers as $u) {
    $id = (int)qv('SELECT id FROM users WHERE email = :e', [':e' => $u[1]], 0);
    if (!$id) {
        $satir = [
            'name' => $u[0], 'email' => $u[1],
            'pass_hash' => password_hash('demo12345', PASSWORD_DEFAULT),
            'role' => $u[2], 'active' => 1, 'created_at' => now(),
        ];
        // Bu sütunlar Faz 7 göçüyle geldi; eski şemada yoksa atlanır.
        if (schema_has_column('users', 'is_staff'))       { $satir['is_staff'] = 1; }
        if (schema_has_column('users', 'is_responsible')) { $satir['is_responsible'] = (int)$u[3]; }
        $id = db_insert('users', $satir);
    }
    $demoUserIds[$u[2]] = $id;
    if (in_array($u[2], $yazanRoller, true)) { $userIds[] = $id; }
}

// ---------------------------------------------------------------- kategoriler
$categoryDefs = [
    ['Gündem', '#c0392b', 1], ['Ekonomi', '#27632a', 2], ['Spor', '#1f4e79', 3],
    ['Teknoloji', '#5b3a8e', 4], ['Kültür Sanat', '#b8860b', 5], ['Yaşam', '#0f766e', 6],
];
$catIds = [];
foreach ($categoryDefs as $c) {
    $slug = slugify($c[0]);
    $id = (int)qv('SELECT id FROM categories WHERE slug = :s', [':s' => $slug], 0);
    if (!$id) {
        $id = db_insert('categories', [
            'name' => $c[0], 'slug' => $slug, 'sort' => $c[2],
            'color' => $c[1], 'in_menu' => 1, 'active' => 1,
        ]);
    } else {
        db_update('categories', ['color' => $c[1], 'sort' => $c[2], 'in_menu' => 1, 'active' => 1], 'id = :i', [':i' => $id]);
    }
    $catIds[$c[0]] = $id;
}

// ---------------------------------------------------------------- haber metinleri
/** Kategoriye göre gerçekçi demo haber başlıkları ve spot metinleri. */
function demo_articles() {
    return [
        'Gündem' => [
            ['Şehir merkezinde yeni tramvay hattı hizmete girdi', 'Toplam 11 duraklı hat, günlük 40 bin yolcuya hizmet verecek. Açılışta ilk hafta ücretsiz.'],
            ['Belediye meclisi bütçe görüşmelerine başladı', 'Görüşmelerde altyapı yatırımlarına ayrılan payın artırılması gündeme geldi.'],
            ['Sağlık ocaklarında randevu sistemi yenilendi', 'Yeni sistemle randevular çevrim içi alınabilecek, bekleme süresinin kısalması hedefleniyor.'],
            ['Kent ormanında ağaçlandırma seferberliği', 'Gönüllülerin katılımıyla hafta sonu 12 bin fidan toprakla buluşturuldu.'],
            ['Trafik yoğunluğuna karşı yeni kavşak düzenlemesi', 'Sabah saatlerindeki yoğunluğun yüzde 20 azalması bekleniyor.'],
            ['Muhtarlardan altyapı talebi', 'Mahalle muhtarları, kanalizasyon hattı yenileme çalışmalarının hızlandırılmasını istedi.'],
            ['İtfaiyeden yangın tatbikatı', 'Okullarda gerçekleştirilen tatbikata 400 öğrenci katıldı.'],
            ['Kütüphaneler 7/24 hizmete geçiyor', 'Sınav dönemi boyunca üç merkez kütüphane kesintisiz açık kalacak.'],
        ],
        'Ekonomi' => [
            ['Esnaf odasından fiyat etiketi uyarısı', 'Oda başkanı, etiketlerde şeffaflığın tüketici güveni için belirleyici olduğunu vurguladı.'],
            ['Organize sanayi bölgesinde istihdam arttı', 'Bölgedeki firmalar son çeyrekte 1.200 yeni personel aldı.'],
            ['Tarım kredilerinde başvuru dönemi başladı', 'Üreticiler başvurularını ay sonuna kadar yapabilecek.'],
            ['İhracatta yeni pazar arayışı', 'Sektör temsilcileri, komşu ülkelerdeki fuarlara katılım planlıyor.'],
            ['Kooperatiflerden ortak alım kararı', 'Girdi maliyetlerini düşürmek için ortak tedarik modeli uygulanacak.'],
            ['Küçük işletmelere dijitalleşme desteği', 'Programa başvuran işletmeler eğitim ve danışmanlık alabilecek.'],
            ['Zeytin rekoltesi beklentilerin üzerinde', 'Üreticiler, hasadın geçen yıla göre yüzde 15 arttığını bildirdi.'],
        ],
        'Spor' => [
            ['Yerel takım deplasmanda 2-1 kazandı', 'Karşılaşmanın golleri ikinci yarıda geldi; ligde çıkış arayan ekip üç puanla döndü.'],
            ['Gençlik merkezi yaz kursları başvuruya açıldı', 'Kurslara katılım ücretsiz; başvurular ay sonuna kadar sürecek.'],
            ['Voleybol altyapısından ulusal takıma davet', 'Kulübün üç genç sporcusu millî takım kampına çağrıldı.'],
            ['Yarı maratona rekor katılım', 'Etkinliğe 4.500 sporcu katıldı, gelir okul spor salonuna aktarılacak.'],
            ['Sokak basketbolu turnuvası başlıyor', 'Turnuvaya 32 takım katılacak, final maçı meydanda oynanacak.'],
            ['Güreş takımı bölge şampiyonu oldu', 'Sporcular üç altın, iki gümüş madalya kazandı.'],
        ],
        'Teknoloji' => [
            ['Üniversitede tarım teknolojileri merkezi açıldı', 'Merkez, lisansüstü projelere ev sahipliği yapacak ve üreticilere danışmanlık verecek.'],
            ['Kamu hizmetleri mobil uygulamada toplandı', 'Vatandaşlar 30 farklı işlemi tek uygulamadan yapabilecek.'],
            ['Okullarda kodlama atölyeleri yaygınlaşıyor', 'Bu dönem 18 okulda atölye kurulması planlanıyor.'],
            ['Yerli üretim sensörle sulama tasarrufu', 'Saha denemelerinde su kullanımında yüzde 30 azalma ölçüldü.'],
            ['Siber güvenlik farkındalık eğitimi', 'Kamu çalışanlarına yönelik eğitimlerde oltalama saldırıları anlatıldı.'],
            ['Açık veri portalı yayına girdi', 'Belediye verileri makine okunabilir biçimde paylaşılacak.'],
        ],
        'Kültür Sanat' => [
            ['Kent müzesinde yeni sergi', 'Sergide kentin son yüzyılına ait 200 fotoğraf yer alıyor.'],
            ['Tiyatro festivali programı açıklandı', 'Festivalde 14 oyun sahnelenecek, biletler yarın satışa çıkıyor.'],
            ['Şiir gecesine yoğun ilgi', 'Etkinlikte genç şairler eserlerini okudu.'],
            ['Tarihî konak restorasyonu tamamlandı', 'Yapı, kültür merkezi olarak hizmet verecek.'],
            ['Kısa film atölyesi başvuruları', 'Atölye sonunda çekilen filmler festivalde gösterilecek.'],
            ['Halk oyunları topluluğundan yurt dışı turnesi', 'Topluluk üç ülkede gösteri yapacak.'],
        ],
        'Yaşam' => [
            ['Sahil bandında bisiklet yolu uzatıldı', 'Yeni bölümle birlikte toplam uzunluk 14 kilometreye çıktı.'],
            ['Sokak hayvanları için kış barınakları', 'Gönüllüler ve belediye iş birliğiyle 300 barınak yerleştirildi.'],
            ['Pazar yerlerinde plastik poşet azalıyor', 'Bez çanta dağıtımı sonrası kullanım yüzde 40 düştü.'],
            ['Yaşlılar için evde bakım hizmeti genişliyor', 'Hizmet kapsamına iki yeni mahalle eklendi.'],
            ['Kan bağışı kampanyasına destek çağrısı', 'Stokların azaldığı bildirildi, bağış noktaları hafta boyunca açık.'],
            ['Çocuk oyun alanları yenileniyor', 'On iki parkta zemin ve oyun grupları değiştirilecek.'],
            ['Kent bostanında hasat zamanı', 'Gönüllülerin ektiği sebzeler aşevine bağışlandı.'],
        ],
    ];
}

/** Demo gövde metni üretir (3–6 paragraf). */
function demo_body($title, $spot, $seed) {
    $paragraphs = [
        $spot,
        'Konuya ilişkin açıklama yapan yetkililer, sürecin başından beri ilgili tarafların görüşlerinin alındığını belirtti. Çalışmanın planlanan takvime uygun ilerlediği ifade edildi.',
        'Kentte yaşayanlar, uygulamanın günlük hayata etkisini olumlu değerlendirdi. Bölge sakinleri, benzer çalışmaların diğer mahallelerde de yapılmasını istedi.',
        'Yetkililer, ikinci etabın önümüzdeki dönemde başlayacağını, bu kapsamda ek kaynak ayrıldığını duyurdu. Çalışmanın tamamlanmasıyla birlikte hizmet kapasitesinin artması bekleniyor.',
        'Konuyla ilgili değerlendirmelerde bulunan uzmanlar, benzer uygulamaların uzun vadede maliyet avantajı sağladığına dikkat çekti.',
        'Sürecin izlenmesi için bir komisyon kurulduğu, üç ayda bir rapor yayımlanacağı bildirildi.',
    ];
    $count = 3 + ($seed % 4);
    $out = '';
    for ($i = 0; $i < $count; $i++) {
        $out .= '<p>' . esc($paragraphs[$i % count($paragraphs)]) . "</p>\n";
    }
    if ($seed % 5 === 0) {
        $out .= "<h2>Ne değişecek?</h2>\n<p>Uygulamanın ilk sonuçları önümüzdeki ay açıklanacak.</p>\n";
    }
    if ($seed % 7 === 0) {
        $out .= "<blockquote>Bu çalışma, kentin ihtiyaçlarına doğrudan yanıt veriyor.</blockquote>\n";
    }
    if ($seed % 4 === 0) {
        $out .= "<ul>\n<li>Başvurular çevrim içi alınacak</li>\n<li>Katılım ücretsiz</li>\n<li>Kontenjan sınırlı</li>\n</ul>\n";
    }
    return $out;
}

// ---------------------------------------------------------------- haberler
$existing = (int)qv('SELECT COUNT(*) FROM posts', [], 0);
$now = time();
$seed = 0;
$createdIds = [];
$tagPool = ['gündem', 'kent', 'yerel yönetim', 'ekonomi', 'üretim', 'spor', 'gençlik',
            'teknoloji', 'eğitim', 'kültür', 'sanat', 'çevre', 'sağlık', 'ulaşım', 'tarım'];

foreach (demo_articles() as $catName => $items) {
    foreach ($items as $item) {
        $seed++;
        $title = $item[0];
        if (qv('SELECT 1 FROM posts WHERE title = :t', [':t' => $title])) { continue; }

        $isVideo = ($seed % 11 === 0);
        $isAi = ($seed % 6 === 0);
        $status = 'published';
        if ($seed % 13 === 0) { $status = 'draft'; }
        elseif ($seed % 17 === 0) { $status = 'pending'; }

        $published = date('Y-m-d H:i:s', $now - ($seed * 5400) - random_int(0, 3600));
        $body = demo_body($title, $item[1], $seed);
        if ($isVideo) {
            $body = '<figure class="manset-embed"><iframe src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ" '
                  . 'title="Video" allowfullscreen loading="lazy"></iframe></figure>' . "\n" . $body;
        }
        $sourceName = $isAi ? 'Örnek Haber Ajansı' : '';
        $sourceUrl = $isAi ? 'https://ornek-ajans.test/haber/' . $seed : '';
        if ($isAi) {
            $body .= '<p class="kaynak">Kaynak: <a href="' . esc($sourceUrl) . '" rel="nofollow noopener" target="_blank">'
                   . esc($sourceName) . '</a></p>';
        }

        shuffle($tagPool);
        $tags = implode(', ', array_slice($tagPool, 0, 4));

        $id = db_insert('posts', [
            'title'         => $title,
            'slug'          => unique_slug('posts', $title),
            'spot'          => $item[1],
            'body'          => sanitize_html($body, true),
            'image'         => '',
            'category_id'   => $catIds[$catName],
            'author_id'     => $userIds[$seed % count($userIds)],
            'status'        => $status,
            'type'          => $isVideo ? 'video' : ($seed % 9 === 0 ? 'makale' : 'haber'),
            'source_name'   => $sourceName,
            'source_url'    => $sourceUrl,
            'ai_generated'  => $isAi ? 1 : 0,
            'seo_title'     => mb_substr($title, 0, 60),
            'seo_desc'      => excerpt($item[1], 150),
            'tags'          => $tags,
            'view_count'    => $status === 'published' ? random_int(20, 4200) : 0,
            'is_headline'   => 0,
            'headline_sort' => 0,
            'is_breaking'   => 0,
            'published_at'  => $published,
            'created_at'    => $published,
            'updated_at'    => ($seed % 8 === 0) ? date('Y-m-d H:i:s', strtotime($published) + 7200) : $published,
        ]);
        $createdIds[] = ['id' => $id, 'status' => $status, 'published_at' => $published];
    }
}

// Manşet: en yeni 8 yayındaki haber
$headlineIds = qa('SELECT id FROM posts WHERE status = \'published\' ORDER BY published_at DESC LIMIT 8');
q('UPDATE posts SET is_headline = 0, headline_sort = 0');
foreach ($headlineIds as $i => $r) {
    q('UPDATE posts SET is_headline = 1, headline_sort = :s WHERE id = :i',
        [':s' => $i + 1, ':i' => (int)$r['id']]);
}
// Son dakika: en yeni 3
q('UPDATE posts SET is_breaking = 0');
foreach (array_slice($headlineIds, 0, 3) as $r) {
    q('UPDATE posts SET is_breaking = 1 WHERE id = :i', [':i' => (int)$r['id']]);
}
// Editörün seçimi
$picks = qa('SELECT id FROM posts WHERE status = \'published\' ORDER BY view_count DESC LIMIT 4');
setting_set('editor_picks', implode(',', array_map(function ($r) { return (int)$r['id']; }, $picks)));

// ---------------------------------------------------------------- yorumlar
$commentAuthors = [
    ['Ayşe Yıldırım', 'ayse@ornek.test'], ['Kemal Doğan', 'kemal@ornek.test'],
    ['Zeynep Arslan', 'zeynep@ornek.test'], ['Burak Şen', 'burak@ornek.test'],
    ['Elif Çetin', 'elif@ornek.test'], ['Hakan Öztürk', 'hakan@ornek.test'],
];
$commentTexts = [
    'Uzun zamandır beklenen bir gelişme, emeği geçen herkese teşekkürler.',
    'Haberde geçen tarih bilgisi güncel mi acaba? Kaynağı merak ettim.',
    'Bizim mahallede de benzer bir çalışma yapılmasını isteriz.',
    'Faydalı bir haber olmuş, takipteyiz.',
    'Katılım koşulları hakkında ayrıntılı bilgi nereden alınabilir?',
    'Geçen yılki uygulamaya göre epey ilerleme var.',
    'Bu konuda daha önce de yazmıştınız, gelişmeleri izlemek güzel.',
];
$published = qa('SELECT id, published_at FROM posts WHERE status = \'published\' ORDER BY published_at DESC LIMIT 20');
$commentCount = 0;
foreach ($published as $i => $p) {
    $n = random_int(0, 3);
    for ($k = 0; $k < $n; $k++) {
        $a = $commentAuthors[($i + $k) % count($commentAuthors)];
        $status = (($i + $k) % 9 === 0) ? 'pending' : ((($i + $k) % 14 === 0) ? 'spam' : 'approved');
        db_insert('comments', [
            'post_id' => (int)$p['id'], 'user_id' => 0,
            'name' => $a[0], 'email' => $a[1],
            'body' => $commentTexts[($i + $k * 3) % count($commentTexts)],
            'ip' => '203.0.113.' . random_int(2, 250),
            'status' => $status,
            'created_at' => date('Y-m-d H:i:s', strtotime($p['published_at']) + random_int(3600, 172800)),
        ]);
        $commentCount++;
    }
}

// ---------------------------------------------------------------- reklamlar
if ((int)qv('SELECT COUNT(*) FROM ads', [], 0) === 0) {
    $ads = [
        ['header', 'Üst banner (örnek)', '<div style="background:#eef1f4;border:1px dashed #c3cad2;padding:24px;text-align:center;border-radius:6px;color:#6b7280;font:14px system-ui">Reklam alanı — 970×90</div>'],
        ['sidebar_1', 'Kenar çubuğu 1 (örnek)', '<div style="background:#eef1f4;border:1px dashed #c3cad2;padding:60px 12px;text-align:center;border-radius:6px;color:#6b7280;font:14px system-ui">Reklam alanı — 300×250</div>'],
        ['in_article', 'Haber içi (örnek)', '<div style="background:#eef1f4;border:1px dashed #c3cad2;padding:28px;text-align:center;border-radius:6px;color:#6b7280;font:14px system-ui">Reklam alanı — haber içi</div>'],
        ['footer', 'Alt banner (örnek)', '<div style="background:#eef1f4;border:1px dashed #c3cad2;padding:20px;text-align:center;border-radius:6px;color:#6b7280;font:14px system-ui">Reklam alanı — alt bant</div>'],
    ];
    foreach ($ads as $i => $a) {
        db_insert('ads', [
            'slot_key' => $a[0], 'title' => $a[1], 'html' => $a[2],
            'active' => 1, 'sort' => $i, 'starts_at' => null, 'ends_at' => null,
        ]);
    }
}

// ---------------------------------------------------------------- RSS kaynakları (örnek, pasif)
if ((int)qv('SELECT COUNT(*) FROM rss_sources', [], 0) === 0) {
    $base = 'http://127.0.0.1:8080/tests/fixtures/';
    db_insert('rss_sources', [
        'name' => 'Örnek Besleme (yerel test)', 'url' => $base . 'ornek-feed.xml',
        'category_id' => $catIds['Gündem'], 'auto_publish' => 0, 'ai_rewrite' => 0,
        'active' => 0, 'error_count' => 0, 'last_fetch_at' => null, 'fetch_error' => '',
    ]);
    db_insert('rss_sources', [
        'name' => 'Örnek Atom (yerel test)', 'url' => $base . 'ornek-atom.xml',
        'category_id' => $catIds['Teknoloji'], 'auto_publish' => 0, 'ai_rewrite' => 1,
        'active' => 0, 'error_count' => 0, 'last_fetch_at' => null, 'fetch_error' => '',
    ]);
}

// ---------------------------------------------------------------- künye örnek değerleri
if (setting('kunye_imtiyaz_sahibi', '') === '') {
    setting_set_many([
        'kunye_imtiyaz_sahibi'  => 'Örnek Medya A.Ş.',
        'kunye_sorumlu_mudur'   => 'Selin Aydemir',
        'kunye_ticaret_unvani'  => 'Örnek Medya Yayıncılık A.Ş.',
        'kunye_adres'           => 'Örnek Mah. Basın Cad. No: 1, Merkez / İl',
        'kunye_telefon'         => '+90 (000) 000 00 00',
        'kunye_eposta'          => setting('site_email', 'iletisim@ornek.test'),
        'kunye_kep'             => 'ornekmedya@hs01.kep.tr',
        'kunye_hosting'         => 'Örnek Hosting A.Ş.',
        'kunye_hosting_adres'   => 'Teknopark Mah. Sunucu Sok. No: 5, İl',
        'kunye_hosting_telefon' => '+90 (000) 111 11 11',
        'kunye_vergi_dairesi'   => 'Merkez',
        'kunye_vergi_no'        => '0000000000',
        'kunye_mersis'          => '0000000000000000',
    ]);
}

// ---------------------------------------------------------------- widget örnekleri
setting_set('widget_market_data', json_encode([
    'USD' => '41,25', 'EUR' => '44,80', 'GRAM_ALTIN' => '4.310',
], JSON_UNESCAPED_UNICODE));
setting_set('widget_weather_data', json_encode([
    'sehir' => 'İstanbul', 'derece' => '24', 'durum' => 'Parçalı bulutlu',
], JSON_UNESCAPED_UNICODE));

// ------------------------------------------------------------------- üyeler
// Faz 7: üyelik sistemi demo veriyle gösterilir. Üç farklı durum kurulur ki
// ödeme duvarının davranışı (açık / kilitli / süresi dolmuş) elle denenebilsin.
if (function_exists('member_tables_ready') && member_tables_ready()) {
    $demoMembers = [
        // ad, e-posta, kademe, geçerlilik (null = süresiz), not
        ['Ayşe Korkmazer', 'ayse@ornek.test',  'free',    null,                                    'Ücretsiz üye'],
        ['Cem Bilginer',   'cem@ornek.test',   'premium', date('Y-m-d H:i:s', time() + 86400 * 90), 'Abonelik sürüyor'],
        ['Ferda Uçarlı',   'ferda@ornek.test', 'premium', date('Y-m-d H:i:s', time() - 86400 * 5),  'Aboneliği bitmiş'],
    ];
    $memberIds = [];
    foreach ($demoMembers as $m) {
        $id = (int)qv('SELECT id FROM users WHERE email = :e', [':e' => $m[1]], 0);
        if (!$id) {
            $satir = [
                'name' => $m[0], 'email' => $m[1],
                'pass_hash' => password_hash('demo12345', PASSWORD_DEFAULT),
                'role' => 'member', 'active' => 1, 'created_at' => now(),
            ];
            // Üye personel DEĞİLDİR: is_staff = 0 olmalı, yoksa admin.access açılır.
            if (schema_has_column('users', 'is_staff')) { $satir['is_staff'] = 0; }
            $id = db_insert('users', $satir);
        }
        $memberIds[] = $id;
        // Demo üyeler doğrulanmış sayılır ki giriş akışı hemen denenebilsin.
        if (function_exists('member_mark_verified')) { member_mark_verified($id); }
        if (!qv('SELECT id FROM memberships WHERE user_id = :u', [':u' => $id], 0)) {
            db_insert('memberships', [
                'user_id'     => $id,
                'tier'        => $m[2],
                'valid_until' => $m[3],
                'note'        => $m[4],
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    // Kaydedilenler ekranının boş görünmemesi için birkaç yer imi
    if (schema_has_table('bookmarks') && count($memberIds) > 1) {
        foreach (q('SELECT id FROM posts WHERE status = \'published\' ORDER BY id DESC LIMIT 3')->fetchAll() as $ih) {
            if (!qv('SELECT id FROM bookmarks WHERE user_id = :u AND post_id = :p',
                    [':u' => $memberIds[1], ':p' => (int)$ih['id']], 0)) {
                db_insert('bookmarks', [
                    'user_id' => $memberIds[1], 'post_id' => (int)$ih['id'], 'created_at' => now(),
                ]);
            }
        }
    }
}

// ---------------------------------------------------------------- görseller
/**
 * Demo haberlerine görsel bağlar.
 *
 * İKİ KAYNAK:
 *   varsayılan  → GD ile YEREL üretim. Çevrimdışı çalışır, dış bağımlılık yoktur,
 *                 paylaşımlı hostingde dışa istek gerektirmez. Kategoriye göre
 *                 renklenen, haber başlığını taşıyan bir kapak üretilir.
 *   --foto      → picsum.photos'tan GERÇEK fotoğraf indirir. Tasarımı gerçek
 *                 fotoğraflarla değerlendirmek isteyen için. Dışa istek yaptığı
 *                 için BİLEREK opt-in'dir; varsayılan olsaydı betik sessizce
 *                 üçüncü taraf bir servise bağlanırdı.
 *
 * Görsel çekirdeği haberin slug'ından türetilir: aynı haber her koşuda aynı
 * görseli alır, demo ekran görüntüleri arasında fark oluşmaz.
 */
$fotoIndir = in_array('--foto', $argv, true);

/** Kategoriye göre kapak rengi (koyu → açık geçiş). */
function demo_kapak_renkleri($slug) {
    $palet = [
        'gundem'       => [[27, 54, 93],   [58, 110, 165]],
        'ekonomi'      => [[22, 78, 63],   [52, 148, 118]],
        'spor'         => [[123, 45, 38],  [198, 93, 62]],
        'teknoloji'    => [[45, 34, 88],   [104, 84, 178]],
        'kultur-sanat' => [[102, 51, 96],  [176, 104, 154]],
        'yasam'        => [[120, 84, 20],  [196, 152, 62]],
    ];
    return isset($palet[$slug]) ? $palet[$slug] : [[45, 55, 72], [110, 125, 148]];
}

/** GD ile kapak görseli üretir; dosya yolunu döndürür. */
function demo_kapak_uret($baslik, $katSlug, $katAd, $seed, $hedef) {
    if (!function_exists('imagecreatetruecolor')) { return ''; }
    $g = 1280; $y = 720;
    $im = imagecreatetruecolor($g, $y);

    list($a, $b) = demo_kapak_renkleri($katSlug);
    // Köşegen geçiş
    for ($i = 0; $i < $y; $i++) {
        $t = $i / max(1, $y - 1);
        $c = imagecolorallocate($im,
            (int)round($a[0] + ($b[0] - $a[0]) * $t),
            (int)round($a[1] + ($b[1] - $a[1]) * $t),
            (int)round($a[2] + ($b[2] - $a[2]) * $t));
        imagefilledrectangle($im, 0, $i, $g, $i, $c);
    }

    // Çekirdeğe göre değişen geometrik desen — her haberde farklı görünsün
    mt_srand($seed);
    $acik = imagecolorallocatealpha($im, 255, 255, 255, 105);
    for ($i = 0; $i < 7; $i++) {
        $cx = mt_rand(0, $g); $cy = mt_rand(0, $y); $r = mt_rand(140, 460);
        imagefilledellipse($im, $cx, $cy, $r, $r, $acik);
    }
    $cizgi = imagecolorallocatealpha($im, 255, 255, 255, 118);
    for ($i = 0; $i < 5; $i++) {
        $x1 = mt_rand(-200, $g); imageline($im, $x1, 0, $x1 + mt_rand(200, 700), $y, $cizgi);
    }

    // Alt bant + kategori adı
    $bant = imagecolorallocatealpha($im, 0, 0, 0, 55);
    imagefilledrectangle($im, 0, $y - 168, $g, $y, $bant);
    $beyaz = imagecolorallocate($im, 255, 255, 255);
    $solukBeyaz = imagecolorallocatealpha($im, 255, 255, 255, 45);

    imagestring($im, 5, 48, $y - 138, demo_ascii(mb_strtoupper($katAd, 'UTF-8')), $solukBeyaz);
    // Başlık iki satıra sığdırılır (yerleşik yazı tipi ASCII'dir)
    $satirlar = demo_satirla(demo_ascii($baslik), 58);
    $ust = $y - 104;
    foreach (array_slice($satirlar, 0, 2) as $s) {
        imagestring($im, 5, 48, $ust, $s, $beyaz);
        $ust += 26;
    }

    $ok = imagejpeg($im, $hedef, 82);
    imagedestroy($im);
    return $ok ? $hedef : '';
}

/** Yerleşik GD yazı tipi ASCII'dir; Türkçe harfleri okunur karşılıklarına indirger. */
function demo_ascii($s) {
    $harita = ['ç'=>'c','Ç'=>'C','ğ'=>'g','Ğ'=>'G','ı'=>'i','İ'=>'I','ö'=>'o','Ö'=>'O',
               'ş'=>'s','Ş'=>'S','ü'=>'u','Ü'=>'U','â'=>'a','î'=>'i','û'=>'u'];
    return strtr((string)$s, $harita);
}

/** Metni verilen genişlikte satırlara böler. */
function demo_satirla($metin, $genislik) {
    $kelimeler = preg_split('/\s+/', trim((string)$metin));
    $satirlar = []; $simdi = '';
    foreach ($kelimeler as $k) {
        if ($simdi === '') { $simdi = $k; continue; }
        if (strlen($simdi) + 1 + strlen($k) <= $genislik) { $simdi .= ' ' . $k; }
        else { $satirlar[] = $simdi; $simdi = $k; }
    }
    if ($simdi !== '') { $satirlar[] = $simdi; }
    return $satirlar;
}

/** picsum.photos'tan fotoğraf indirir; dosya yolunu ya da '' döndürür. */
function demo_foto_indir($seed, $hedef) {
    $url = 'https://picsum.photos/seed/' . rawurlencode($seed) . '/1280/720.jpg';
    $veri = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'Manset demo-seed',
        ]);
        $veri = (string)curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($kod !== 200) { $veri = ''; }
    }
    if ($veri === '' || strlen($veri) < 2048) { return ''; }
    if (file_put_contents($hedef, $veri) === false) { return ''; }
    // Gerçekten görsel mi? (medya katmanı da denetler; burada erken eleriz)
    $bilgi = @getimagesize($hedef);
    if (!$bilgi) { @unlink($hedef); return ''; }
    return $hedef;
}

$gorselli = 0;
$fotoBasarisiz = 0;
$gorselHedefler = qa('SELECT p.id, p.title, p.slug, p.image, c.slug AS kat_slug, c.name AS kat_ad
                      FROM posts p LEFT JOIN categories c ON c.id = p.category_id
                      WHERE p.status = \'published\'
                      ORDER BY p.id ASC');

foreach ($gorselHedefler as $h) {
    if (trim((string)arr($h, 'image', '')) !== '') { continue; }   // zaten görseli var

    $seed = 'manset-' . (string)$h['slug'];
    $sayi = crc32($seed);
    $gecici = sys_get_temp_dir() . '/manset-demo-' . $h['id'] . '.jpg';

    $uretilen = '';
    if ($fotoIndir) {
        $uretilen = demo_foto_indir($seed, $gecici);
        if ($uretilen === '') { $fotoBasarisiz++; }
    }
    if ($uretilen === '') {
        $uretilen = demo_kapak_uret((string)$h['title'], (string)arr($h, 'kat_slug', ''),
                                    (string)arr($h, 'kat_ad', 'Haber'), $sayi, $gecici);
    }
    if ($uretilen === '') { continue; }

    $alt = sanitize_line((string)$h['title'], 200);
    $sonuc = media_import_file($uretilen, 'demo-' . $h['slug'] . '.jpg', $alt);
    @unlink($gecici);

    if (!empty($sonuc['ok'])) {
        q('UPDATE posts SET image = :f WHERE id = :i', [':f' => $sonuc['filename'], ':i' => (int)$h['id']]);
        $gorselli++;
    }
}

if ($fotoBasarisiz > 0) {
    echo '  not: ' . $fotoBasarisiz . " fotoğraf indirilemedi, yerel kapak üretildi.\n";
}

// ------------------------------------------------- kilitli içerik örnekleri
// İki haber ödeme duvarının arkasına alınır: biri üyelere, biri abonelere.
// teaser alanı kilit kutusunun üstünde gösterilir; boşsa spot kullanılır.
$duzeyler = ['members', 'premium'];
$sira = 0;
foreach (q('SELECT id, spot FROM posts WHERE status = \'published\' ORDER BY id DESC LIMIT 2')->fetchAll() as $k) {
    if (!isset($duzeyler[$sira])) { break; }
    q('UPDATE posts SET visibility = :v, teaser = :t WHERE id = :i', [
        ':v' => $duzeyler[$sira],
        ':t' => (string)$k['spot'],
        ':i' => (int)$k['id'],
    ]);
    $sira++;
}

if (function_exists('cache_flush')) { cache_flush(); }

// ---------------------------------------------------------------- özet
$stats = [
    'kategori' => (int)qv('SELECT COUNT(*) FROM categories', [], 0),
    'haber'    => (int)qv('SELECT COUNT(*) FROM posts', [], 0),
    'yayında'  => (int)qv('SELECT COUNT(*) FROM posts WHERE status = \'published\'', [], 0),
    'manşet'   => (int)qv('SELECT COUNT(*) FROM posts WHERE is_headline = 1', [], 0),
    'yorum'    => (int)qv('SELECT COUNT(*) FROM comments', [], 0),
    'reklam'   => (int)qv('SELECT COUNT(*) FROM ads', [], 0),
    'kullanıcı' => (int)qv('SELECT COUNT(*) FROM users', [], 0),
    'üye'       => (int)qv('SELECT COUNT(*) FROM users WHERE role = \'member\'', [], 0),
    'kilitli'   => (int)qv('SELECT COUNT(*) FROM posts WHERE visibility <> \'public\'', [], 0),
    'görselli'  => (int)qv('SELECT COUNT(*) FROM posts WHERE image <> \'\'', [], 0),
];
echo "Demo veri hazır.\n";
foreach ($stats as $k => $v) { printf("  %-10s %d\n", $k . ':', $v); }
echo "\nDemo hesaplar — parola hepsinde: demo12345\n";
foreach ($demoUsers as $u) { printf("  %-20s %s\n", $u[1], $u[2]); }
if (isset($demoMembers)) {
    echo "  --- üyeler ---\n";
    foreach ($demoMembers as $m) { printf("  %-20s %s (%s)\n", $m[1], $m[2], $m[4]); }
}
echo "Önceki verileri silip yeniden üretmek için: php tests/demo-seed.php --reset\n";
echo "Gerçek fotoğraflarla (dışa istek yapar): php tests/demo-seed.php --foto\n";
