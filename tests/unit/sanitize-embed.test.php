<?php
/**
 * inc/sanitize.php — 1.3-03 ile eklenen genişletmeler ve saldırı girdileri.
 *
 * Bu dosyanın varlık sebebi: temizleyici projedeki en güvenlik-kritik dosya ve
 * ÜÇ denetim turunda buradan bulgu çıktı (srcset kapısı, iframe beyaz listesi,
 * NUL baytı). Her genişletme burada saldırı girdisiyle ÖLÇÜLÜR.
 *
 * Koşucu (tests/unit/run.php) ortamı kurar; bu dosya doğrudan çalıştırılmaz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/sanitize.php';

// ==================================================== yeni tablo ögeleri

test('sanitize_html: <caption> geçer ama ÖZNİTELİK ALMAZ', function () {
    $c = sanitize_html('<table><caption>Bütçe</caption><tr><td>a</td></tr></table>');
    icerir($c, '<caption>Bütçe</caption>', 'caption korunmalı');

    $k = sanitize_html('<table><caption onclick="kotu()" class="x" style="color:red">c</caption></table>');
    icermez($k, 'onclick');
    icermez($k, 'style');
    icermez($k, 'class=');
    icerir($k, '<caption>c</caption>');
});

test('sanitize_html: th@scope yalnız kapalı sözcük kümesini kabul eder', function () {
    foreach (['row', 'col', 'rowgroup', 'colgroup'] as $iyi) {
        $c = sanitize_html('<table><tr><th scope="' . $iyi . '">a</th></tr></table>');
        icerir($c, 'scope="' . $iyi . '"', $iyi . ' korunmalı');
    }
    // Büyük harf + boşluk normalize edilir
    icerir(sanitize_html('<table><tr><th scope="  COL ">a</th></tr></table>'), 'scope="col"');

    foreach (['javascript:alert(1)', 'col onmouseover=1', '"><script>', 'satir', ''] as $kotu) {
        $c = sanitize_html('<table><tr><th scope="' . $kotu . '">a</th></tr></table>');
        icermez($c, 'scope=', 'geçersiz scope düşmeli: ' . $kotu);
        icermez($c, 'script');
    }
    // scope yalnız th üzerinde; td'de beyaz listede değil
    icermez(sanitize_html('<table><tr><td scope="col">a</td></tr></table>'), 'scope');
});

test('sanitize_html: tfoot geçer, ısmarlama tablo etiketi geçmez', function () {
    icerir(sanitize_html('<table><tfoot><tr><td>t</td></tr></tfoot></table>'), '<tfoot>');
    // colgroup/col beyaz listede DEĞİL: sarmalı açılır, içerik kaybolmaz
    $c = sanitize_html('<table><colgroup><col span="2"></colgroup><tr><td>x</td></tr></table>');
    icermez($c, 'colgroup');
    icermez($c, '<col ');
    icerir($c, 'x');
});

// ==================================================== srcset / şema kapıları

test('sanitize_html: srcset kapısı parçalanmış şemaları kapatır (probe srcset_gate)', function () {
    $sekme = '<img src="/a.png" srcset="java' . chr(9) . 'script:alert(1) 2x">';
    icermez(strtolower(sanitize_html($sekme)), 'srcset', 'sekmeyle parçalanmış şema kapıyı geçmemeli');

    $del = '<img src="/a.png" srcset="java' . chr(127) . 'script:alert(1) 2x">';
    icermez(strtolower(sanitize_html($del)), 'srcset', 'DEL baytıyla parçalanmış şema da kapanmalı');

    $duz = '<img src="/a.png" srcset="javascript:alert(1) 2x">';
    icermez(strtolower(sanitize_html($duz)), 'srcset');

    // Virgülle parçalama hilesi: HAM değerde tehlikeli şema varsa öznitelik tümden atılır
    $virgul = '<img src="/a.png" srcset="data:image/svg+xml,<svg onload=alert(1)/> 1x, /b.png 2x">';
    $c = sanitize_html($virgul);
    icermez($c, 'srcset');
    icermez($c, 'onload');

    // GEÇERLİ srcset korunur — kapı ölü olmamalı
    $iyi = sanitize_html('<img src="/uploads/a.jpg" srcset="/uploads/a.jpg 1x, /uploads/a-2x.jpg 2x" alt="a">');
    icerir($iyi, 'srcset=');
    icerir($iyi, '/uploads/a-2x.jpg 2x');
});

test('sanitize_html: iframe beyaz listesi genişletilmedi', function () {
    // Beyaz listenin kendisi
    $hostlar = sanitize_allowed_iframe_hosts();
    dogru(in_array('www.youtube-nocookie.com', $hostlar, true), 'youtube-nocookie listede olmalı');
    dogru(in_array('player.vimeo.com', $hostlar, true), 'vimeo listede olmalı');
    // 1.3'te X ve Instagram için iframe EKLENMEDİ
    yanlis(in_array('platform.twitter.com', $hostlar, true), 'twitter iframe host olmamalı');
    yanlis(in_array('www.instagram.com', $hostlar, true), 'instagram iframe host olmamalı');
    yanlis(in_array('publish.twitter.com', $hostlar, true), 'publish.twitter iframe host olmamalı');

    // Davranış
    icerir(sanitize_html('<iframe src="https://www.youtube-nocookie.com/embed/abc123"></iframe>'), 'youtube-nocookie.com/embed/abc123');
    foreach ([
        '<iframe src="https://www.instagram.com/p/Abc/embed/"></iframe>',
        '<iframe src="https://platform.twitter.com/embed/x"></iframe>',
        '<iframe src="https://www.youtube.com@evil.example/x"></iframe>',
        '<iframe src="https://www.youtube.com.evil.example/x"></iframe>',
        '<iframe src="//evil.example/x"></iframe>',
        '<iframe src="/yerel"></iframe>',
        '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    ] as $kotu) {
        $c = sanitize_html($kotu);
        icermez($c, 'evil.example');
        icermez($c, 'instagram.com');
        icermez($c, 'platform.twitter');
        icermez($c, 'srcdoc');
    }
});

// ==================================================== biçimli yapıştırma

test('sanitize_html: Word yapıştırması temizlenir (mso, o:p, style, boş p)', function () {
    // Gerçek Word çıktısına yakın bir parça.
    $word = '<html xmlns:o="urn:schemas-microsoft-com:office:office">'
          . '<head><meta name=Generator content="Microsoft Word 15">'
          . '<style><!-- p.MsoNormal {mso-style-parent:""} --></style></head>'
          . '<body lang=TR style=\'tab-interval:35.4pt\'>'
          . '<p class=MsoNormal><span style=\'font-size:11.0pt;mso-fareast-font-family:"Times New Roman"\'>'
          . 'Belediye başkanı açıklama yaptı.<o:p></o:p></span></p>'
          . '<p class=MsoNormal><o:p>&nbsp;</o:p></p>'
          . '<p class=MsoNormal><a href="https://ornek.com/haber">Kaynak</a><o:p></o:p></p>'
          . '</body></html>';

    $c = sanitize_html($word);
    icermez($c, 'mso-', 'mso- stilleri kalmamalı');
    icermez($c, 'MsoNormal', 'Word sınıfları kalmamalı');
    icermez($c, 'style=', 'style özniteliği kalmamalı');
    icermez($c, 'o:p', 'Office ad alanı etiketi kalmamalı');
    icermez($c, '<meta', 'meta kalmamalı');
    icermez($c, 'tab-interval');
    icerir($c, 'Belediye başkanı açıklama yaptı.', 'metin korunmalı');
    icerir($c, 'https://ornek.com/haber', 'bağlantı korunmalı');
    icermez($c, '<p>&nbsp;</p>', 'boş paragraf sadeleşmeli');
});

test('sanitize_html: Google Docs sarmalı metni yiyip bitirmez', function () {
    $docs = '<b style="font-weight:normal" id="docs-internal-guid-1">'
          . '<p dir="ltr"><span style="font-size:11pt;color:#000000">Muhabir notu.</span></p></b>';
    $c = sanitize_html($docs);
    icermez($c, 'style=');
    icermez($c, 'docs-internal-guid');
    icerir($c, 'Muhabir notu.');
});

// ==================================================== gömme çıktısı temizleyiciden geçer

test('sanitize_html: üretilen X/Instagram kartı olduğu gibi korunur', function () {
    $kart = '<figure class="manset-embed"><blockquote><p>Açıklama metni.</p></blockquote>'
          . '<figcaption><a href="https://x.com/kullanici/status/1234567890" target="_blank"'
          . ' rel="nofollow noopener noreferrer">@kullanici — X gönderisini aç</a></figcaption></figure>';
    $c = sanitize_html($kart, true);
    icerir($c, 'class="manset-embed"');
    icerir($c, '<blockquote>');
    icerir($c, 'https://x.com/kullanici/status/1234567890');
    icerir($c, 'nofollow');
    icerir($c, 'noopener');
    icermez($c, '<script', 'kartta hiçbir betik olmamalı');
});

test('sanitize_html: sağlayıcının betikli oEmbed html alanı gövdeye giremez', function () {
    // publish.twitter.com'un GERÇEK yanıt biçimi (kısaltılmış).
    $saglayici = '<blockquote class="twitter-tweet"><p lang="tr" dir="ltr">Merhaba</p>'
               . '&mdash; Ad (@kullanici) <a href="https://twitter.com/kullanici/status/1">1 Ocak 2026</a>'
               . '</blockquote> <script async src="https://platform.twitter.com/widgets.js"'
               . ' charset="utf-8"></script>';
    $c = sanitize_html($saglayici, true);
    icermez($c, '<script');
    icermez($c, 'widgets.js');
    icermez($c, 'platform.twitter.com');
});
