<?php
/**
 * inc/sanitize.php — HTML temizleyici çekirdeği.
 * Koşucu (tests/unit/run.php) ortamı kurar; bu dosya doğrudan çalıştırılmaz.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.\n"); exit(1); }
require_once INC_DIR . '/sanitize.php';

// ------------------------------------------------------------ betik / olay
test('sanitize_html: <script> bloğu tamamen atılır', function () {
    $c = sanitize_html('<p>merhaba</p><script>alert(1)</script><p>dünya</p>');
    icermez($c, 'script', 'script etiketi kalmamalı');
    icermez($c, 'alert(1)', 'betik gövdesi kalmamalı');
    icerir($c, 'merhaba');
    icerir($c, 'dünya');
});

test('sanitize_html: olay öznitelikleri (onclick/onerror) düşer', function () {
    $c = sanitize_html('<p onclick="kotu()">metin</p>');
    icermez($c, 'onclick');
    icerir($c, 'metin');

    $c2 = sanitize_html('<img src="/uploads/a.jpg" onerror="kotu()" alt="a">');
    icermez($c2, 'onerror');
    icerir($c2, '/uploads/a.jpg');
});

test('sanitize_html: javascript: şeması href/src üzerinden geçemez', function () {
    $c = sanitize_html('<a href="javascript:alert(1)">tık</a>');
    icermez($c, 'javascript:');
    icerir($c, 'tık', 'bağlantı metni korunmalı');

    // Kontrol karakteriyle parçalanmış biçim de kapalı olmalı
    $c2 = sanitize_html("<a href=\"java\tscript:alert(1)\">tık</a>");
    icermez(strtolower($c2), 'script:', 'sekmeyle parçalanmış şema da kapanmalı');

    $c3 = sanitize_html('<img src="data:text/html;base64,PHN2Zz4=" alt="x">');
    icermez($c3, 'data:');
});

test('sanitize_is_safe_url: şema kapısı', function () {
    yanlis(sanitize_is_safe_url('javascript:alert(1)'));
    yanlis(sanitize_is_safe_url("java\tscript:alert(1)"));
    yanlis(sanitize_is_safe_url('  JaVaScRiPt:alert(1)'));
    yanlis(sanitize_is_safe_url('data:text/html,x'));
    yanlis(sanitize_is_safe_url('vbscript:msgbox'));
    yanlis(sanitize_is_safe_url(''));
    dogru(sanitize_is_safe_url('https://ornek.test/a'));
    dogru(sanitize_is_safe_url('//ornek.test/a'));
    dogru(sanitize_is_safe_url('/uploads/a.jpg'));
    dogru(sanitize_is_safe_url('mailto:a@b.test'));
    dogru(sanitize_is_safe_url('#bolum'));
});

// ------------------------------------------------------------ beyaz liste
test('sanitize_html: izinli etiketler korunur', function () {
    $c = sanitize_html('<h2>Başlık</h2><p><strong>kalın</strong> ve <em>eğik</em></p>'
        . '<ul><li>bir</li><li>iki</li></ul><blockquote>alıntı</blockquote>');
    icerir($c, '<h2>Başlık</h2>');
    icerir($c, '<strong>kalın</strong>');
    icerir($c, '<em>eğik</em>');
    icerir($c, '<li>bir</li>');
    icerir($c, '<blockquote>alıntı</blockquote>');
});

test('sanitize_html: izinsiz etiket atılır, metni kalır', function () {
    $c = sanitize_html('<marquee>kayan</marquee>');
    icermez($c, 'marquee');
    icerir($c, 'kayan', 'metin kaybolmamalı');
});

test('sanitize_html: <p class="kaynak"> bloğu korunur (mevzuat)', function () {
    $c = sanitize_html('<p class="kaynak">Kaynak: Anadolu Ajansı</p>');
    icerir($c, 'class="kaynak"', 'kaynak sınıfı beyaz listede kalmalı');
    icerir($c, 'Anadolu Ajansı');

    $d = sanitize_html('<p class="duzeltme">Düzeltme metni</p>');
    icerir($d, 'class="duzeltme"');

    $e = sanitize_html('<p class="reklam-hack">metin</p>');
    icermez($e, 'class=', 'beyaz listede olmayan sınıf silinmeli');
    icerir($e, 'metin');
});

// ------------------------------------------------------------ srcset
test('sanitize_html: srcset şema kapısı', function () {
    $iyi = sanitize_html('<img src="/uploads/a.jpg" alt="a" srcset="/uploads/a.jpg 1x, /uploads/a2.jpg 2x">');
    icerir($iyi, 'srcset=', 'güvenli srcset korunmalı');
    icerir($iyi, '2x');

    $kotu = sanitize_html('<img src="/uploads/a.jpg" alt="a" srcset="javascript:alert(1) 1x">');
    icermez($kotu, 'javascript');

    // data: URI virgül taşır — parçalama hilesi (SECURITY_AUDIT B-04)
    $data = sanitize_html('<img src="/uploads/a.jpg" alt="a" srcset="data:image/svg+xml,<svg onload=alert(1)> 1x">');
    icermez($data, 'data:');
    icermez($data, 'alert');

    // Sekmeyle parçalanmış java<TAB>script:
    $tab = sanitize_html("<img src=\"/uploads/a.jpg\" alt=\"a\" srcset=\"java\tscript:alert(1) 1x\">");
    icermez(strtolower($tab), 'script:', 'sekmeyle parçalanmış srcset şeması geçmemeli');
});

// ------------------------------------------------------------ rel / target
test('sanitize_html: rel birleştirme', function () {
    $a = sanitize_html('<a href="https://dis.test/x" rel="nofollow">dış</a>');
    icerir($a, 'nofollow', 'yazarın nofollow değeri korunmalı');
    icerir($a, 'noopener', 'dış bağlantıya noopener eklenmeli');

    $b = sanitize_html('<a href="https://dis.test/x" target="_blank">dış</a>');
    icerir($b, 'noopener');
    icerir($b, 'noreferrer');
    icerir($b, 'target="_blank"');

    $c = sanitize_html('<a href="https://dis.test/x" rel="me kotu-deger nofollow">dış</a>');
    icermez($c, 'kotu-deger', 'beyaz listede olmayan rem değeri süzülmeli');
    icerir($c, 'nofollow');

    $d = sanitize_html('<a href="/ic/sayfa">iç</a>');
    icermez($d, 'rel=', 'iç bağlantıya rel eklenmemeli');
});

// ------------------------------------------------------------ iframe
test('sanitize_html: iframe beyaz listesi', function () {
    $yt = sanitize_html('<iframe src="https://www.youtube.com/embed/abc123" width="560" height="315"></iframe>');
    icerir($yt, '<iframe', 'YouTube gömmesi geçmeli');
    icerir($yt, 'youtube.com/embed/abc123');
    icerir($yt, 'allowfullscreen');

    $kotu = sanitize_html('<iframe src="https://kotu.test/x"></iframe>');
    icermez($kotu, '<iframe', 'rastgele alan adı geçmemeli');

    $goreli = sanitize_html('<iframe src="/yerel/sayfa"></iframe>');
    icermez($goreli, '<iframe', 'göreli iframe adresi geçmemeli');

    $kapali = sanitize_html('<iframe src="https://www.youtube.com/embed/abc"></iframe>', false);
    icermez($kapali, '<iframe', 'allowIframe=false iframe etiketini tamamen kapatmalı');
});

// ------------------------------------------------------------ sayısal öznitelik
test('sanitize_html: sayısal öznitelikler sınırlanır', function () {
    $c = sanitize_html('<img src="/uploads/a.jpg" alt="a" width="99999" height="abc">');
    icerir($c, 'width="4000"', 'genişlik 4000 ile sınırlanmalı');
    icermez($c, 'height=', 'sayı olmayan yükseklik atılmalı');
    icerir($c, 'loading="lazy"', 'görsele tembel yükleme eklenmeli');
});

// ------------------------------------------------------------ bozuk UTF-8
test('sanitize_utf8: bozuk diziler atılır, metin kaybolmaz', function () {
    $bozuk = "Merhaba \xC3\x28 d\xC3\xBCnya";       // \xC3\x28 geçersiz dizi
    $temiz = sanitize_utf8($bozuk);
    dogru(mb_check_encoding($temiz, 'UTF-8'), 'sonuç geçerli UTF-8 olmalı');
    icerir($temiz, 'Merhaba');
    icerir($temiz, 'dünya');
    esit('', sanitize_utf8(''));
    esit('düz metin', sanitize_utf8('düz metin'), 'zaten geçerli metin değişmemeli');
});

test('sanitize_line: tek satıra indirger', function () {
    esit('bir iki üç', sanitize_line("bir\n  iki\t\tüç  "));
    esit('metin', sanitize_line('<b>metin</b>'), 'etiketler atılmalı');
    esit('ab', sanitize_line("a\x00\x07b"), 'kontrol karakterleri atılmalı');
    esit('', sanitize_line('   '));
    esit(5, mb_strlen(sanitize_line('abcdefghij', 5)), 'uzunluk sınırı uygulanmalı');
    // Bozuk UTF-8 girdide de metin kaybolmamalı (preg_replace('/u') NULL tuzağı)
    dogru(sanitize_line("ba\xC3\x28sl\xC4\xB1k") !== '', 'bozuk UTF-8 girdide metin tamamen kaybolmamalı');
});

test('excerpt: kelime sınırında kısaltır', function () {
    esit('kısa metin', excerpt('kısa metin', 160));
    esit('bir iki üç', excerpt("bir\n iki   üç", 160), 'boşluklar tekilleşmeli');
    esit('', excerpt('', 160), 'boş girdi boş kalmalı');
    $uzun = str_repeat('kelime ', 60);
    $k = excerpt($uzun, 50);
    dogru(mb_strlen($k) <= 51, 'kısaltılan metin sınırı aşmamalı: ' . mb_strlen($k));
    icerir($k, '…', 'kısaltmada üç nokta olmalı');
    esit('metin', excerpt('<p>metin</p>', 160), 'etiketler atılmalı');
});
