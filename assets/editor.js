/* ============================================================================
   Manşet — elle yazılmış zengin metin editörü.
   Dış kütüphane YOK. contenteditable + document.execCommand tabanlı.

   Kullanım:
     var ed = window.MansetEditor.baglat(document.getElementById('govde'));
     ed.html(); ed.setHtml('<p>…</p>'); ed.odak(); ed.kelimeSayisi();

   Not: Sunucu tarafında sanitize_html() son sözü söyler. Buradaki temizlik
   kolaylık içindir — Word/HTML çöpü daha kaydedilmeden ayıklanır.
   ============================================================================ */
(function () {
  'use strict';

  /* ---------------------------------------------------- beyaz liste (CONTRACTS §4) */
  var ETIKET = {
    p: ['class'], br: [], hr: [], strong: [], b: [], em: [], i: [], u: [], s: [],
    sub: [], sup: [], h2: [], h3: [], h4: [], ul: [], ol: [], li: [],
    dl: ['class'], dt: [], dd: [], small: [],
    blockquote: ['cite'],
    a: ['href', 'title', 'target', 'rel'],
    img: ['src', 'alt', 'title', 'width', 'height', 'loading', 'srcset', 'sizes'],
    figure: ['class'], figcaption: [],
    table: [], caption: [], thead: [], tbody: [], tfoot: [], tr: [],
    th: ['colspan', 'rowspan', 'scope'], td: ['colspan', 'rowspan'],
    iframe: ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title'],
    span: ['class'], div: ['class'], code: [], pre: []
  };

  /** `scope` için kapalı sözcük kümesi (sunucu tarafıyla aynı). */
  var SCOPE = ['row', 'col', 'rowgroup', 'colgroup'];

  /** İçeriğiyle birlikte tamamen atılan etiketler. */
  var YOKET = ['script', 'style', 'noscript', 'template', 'object', 'embed', 'applet',
    'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math'];

  /**
   * p/figure/span/div üzerinde izinli sınıflar.
   * inc/sanitize.php → sanitize_allowed_classes() ile aynı liste tutulmalıdır;
   * 'kaynak', 'duzeltme', 'guncelleme', 'ai-notu' mevzuat/otomasyon blokları için
   * zorunludur — burada kırpılırsa yeniden kaydetmede kaybolurlar.
   */
  var SINIF = [
    'manset-embed', 'manset-figure', 'manset-quote', 'manset-lead',
    'kaynak', 'duzeltme', 'guncelleme', 'ai-notu',
    'text-center', 'text-right'
  ];

  /** iframe yalnız bu alan adlarından gömülebilir (sanitize_allowed_iframe_hosts ile aynı liste). */
  var IFRAME_SUNUCU = [
    'www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'youtube-nocookie.com',
    'player.vimeo.com', 'www.dailymotion.com', 'geo.dailymotion.com',
    'open.spotify.com', 'w.soundcloud.com'
  ];

  /* ---------------------------------------------------- yardımcılar */

  function guvenliUrl(deger) {
    var s = String(deger == null ? '' : deger)
      .replace(/[\x00-\x20\x7F\u200B-\u200D\uFEFF]/g, '')
      .toLowerCase();
    if (!s) { return false; }
    return !/^(javascript|vbscript|data|file|about|blob)\s*:/.test(s);
  }

  function disBaglanti(href) {
    return /^https?:\/\//i.test(String(href || ''));
  }

  /** Verilen kök düğümü beyaz listeye göre yerinde temizler. */
  function temizleDugum(kok) {
    var cocuklar = Array.prototype.slice.call(kok.childNodes);
    for (var i = cocuklar.length - 1; i >= 0; i--) {
      var d = cocuklar[i];

      if (d.nodeType === 3) { continue; }                       // metin düğümü
      if (d.nodeType !== 1) { kok.removeChild(d); continue; }   // yorum, CDATA…

      var ad = d.nodeName.toLowerCase();

      // Tehlikeli blok: içeriğiyle birlikte at
      if (YOKET.indexOf(ad) >= 0) { kok.removeChild(d); continue; }

      // Beyaz listede yok: etiketi at, metnini koru
      if (!Object.prototype.hasOwnProperty.call(ETIKET, ad)) {
        temizleDugum(d);
        while (d.firstChild) { kok.insertBefore(d.firstChild, d); }
        kok.removeChild(d);
        continue;
      }

      // Öznitelik süzgeci
      var izin = ETIKET[ad];
      var oz = Array.prototype.slice.call(d.attributes);
      for (var j = 0; j < oz.length; j++) {
        var an = String(oz[j].name).toLowerCase();
        var av = oz[j].value;

        if (an.indexOf('on') === 0 || an === 'style' || izin.indexOf(an) < 0) {
          d.removeAttribute(oz[j].name);
          continue;
        }
        if ((an === 'href' || an === 'src') && !guvenliUrl(av)) {
          d.removeAttribute(oz[j].name);
          continue;
        }
        if (an === 'class') {
          var kalan = String(av).split(/\s+/).filter(function (c) { return SINIF.indexOf(c) >= 0; });
          if (kalan.length) { d.setAttribute('class', kalan.join(' ')); }
          else { d.removeAttribute('class'); }
        }
        if (an === 'scope') {
          // Kapalı küme — sunucudaki sanitize_walk() ile birebir aynı kural.
          var sv = String(av).trim().toLowerCase();
          if (SCOPE.indexOf(sv) >= 0) { d.setAttribute('scope', sv); }
          else { d.removeAttribute(oz[j].name); }
        }
      }

      if (ad === 'a') {
        var href = d.getAttribute('href') || '';
        if (!href) { d.removeAttribute('target'); d.removeAttribute('rel'); }
        else if (disBaglanti(href)) {
          d.setAttribute('rel', d.getAttribute('target') === '_blank' ? 'noopener noreferrer' : 'noopener');
        }
      }
      if (ad === 'img') { d.setAttribute('loading', 'lazy'); }
      if (ad === 'iframe') {
        // Yalnız beyaz listeli gömme sunucuları (sunucu tarafı da aynı listeyi uygular)
        var kaynak = d.getAttribute('src') || '';
        var sunucu = '';
        try { sunucu = new URL(kaynak, window.location.href).hostname.toLowerCase(); } catch (eUrl) { sunucu = ''; }
        if (IFRAME_SUNUCU.indexOf(sunucu) < 0) { kok.removeChild(d); continue; }
        d.setAttribute('loading', 'lazy');
      }

      // Anlam taşımayan span/div (execCommand kalıntısı) sarmalını aç
      if ((ad === 'span' || ad === 'div') && !d.getAttribute('class')) {
        temizleDugum(d);
        while (d.firstChild) { kok.insertBefore(d.firstChild, d); }
        kok.removeChild(d);
        continue;
      }

      temizleDugum(d);
    }
  }

  /**
   * Güvenilmeyen HTML'i ETKİSİZ (inert) bir belgede ayrıştırır.
   *
   * GÜVENLİK — NEDEN `document.createElement('div').innerHTML` DEĞİL:
   * Bağlantısız (detached) bir düğüme `innerHTML` yazmak `<script>` çalıştırmaz
   * AMA görsel yüklemesini BAŞLATIR: `<img src=x onerror=alert(1)>` tarayıcının
   * "görsel verisini güncelle" adımını tetikler ve `onerror` düğüm belgede
   * olmasa bile ateşlenir. Panelde bu, oturumlu bir bağlamda kod çalıştırmak
   * demektir — yani yapıştırmayla tetiklenen gerçek bir XSS.
   *
   * `DOMParser.parseFromString(..., 'text/html')` ETKİSİZ bir belge üretir:
   * betik çalışmaz, hiçbir kaynak (img/iframe) İSTENMEZ. Temizlik bu belgede
   * yapılır, geriye yalnız DİZE döner.
   *
   * DOMParser yoksa (çok eski tarayıcı) yedeğe düşülür; o yolda olay
   * öznitelikleri ayrıştırmadan ÖNCE dizeden kazınır.
   *
   * @returns {{doc: Document, kok: Element}}
   */
  function guvenliAyristir(html) {
    var s = String(html == null ? '' : html);
    if (typeof window.DOMParser === 'function') {
      try {
        var d = new DOMParser().parseFromString('<body>' + s + '</body>', 'text/html');
        if (d && d.body) { return { doc: d, kok: d.body }; }
      } catch (e) { /* yedeğe düş */ }
    }
    // YEDEK YOL: olay öznitelikleri ve javascript: şeması dizede kazınır,
    // çünkü aşağıdaki innerHTML canlı belgede ayrıştırma yapacak.
    s = s.replace(/\son[a-z]+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, ' ');
    s = s.replace(/(src|href|xlink:href)\s*=\s*("\s*javascript:[^"]*"|'\s*javascript:[^']*')/gi, '');
    var kap = document.createElement('div');
    kap.innerHTML = s;
    return { doc: document, kok: kap };
  }

  /** HTML dizesini temizler ve dize olarak döndürür. */
  function htmlTemizle(html) {
    var kap = guvenliAyristir(html).kok;
    temizleDugum(kap);
    var cikti = kap.innerHTML;
    // Boş paragrafları sadeleştir
    cikti = cikti.replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '');
    return cikti.trim();
  }

  /* ==========================================================================
     BİÇİMLİ YAPIŞTIRMA (Word / Google Docs)
     ==========================================================================
     Eskiden her yapıştırma DÜZ METNE indiriliyordu: güvenliydi ama muhabir
     Word'de hazırladığı haberi yapıştırdığında bütün paragraf düzeni, kalın/
     italik ve bağlantılar gidiyordu. Şimdi biçim KORUNUYOR, çöp AYIKLANIYOR.

     İki aşama var, ikisi de ayrı ayrı ölçülebilsin diye ayrılmıştır:

       1) yapistirmaOnTemizlik(html)  — SAF DİZE İŞLEMİ, DOM gerektirmez.
          Word'ün koşullu yorumlarını (`<!--[if gte mso 9]>…`), `<xml>` blokunu,
          `<style>` blokunu, `o:p / w: / m:` ad alanı etiketlerini ve HTML
          yorumlarını atar. Bunlar DOM'a hiç girmemelidir: koşullu yorumun
          İÇİNDE tam bir belge (`<w:WordDocument>`) durur ve innerHTML'e
          verildiğinde tarayıcıdan tarayıcıya farklı ayrıştırılır.

       2) temizleDugum(...)           — beyaz liste (zaten vardı).
          `style` özniteliği ve `Mso…` sınıfları burada düşer, sınıfsız
          span/div sarmalı açılır.

     Arada bir ÖN GEÇİŞ var: Google Docs bütün seçimi
     `<b style="font-weight:normal" id="docs-internal-guid-…">` içine sarar.
     `style` beyaz listede olmadığı için düşer ve geriye GERÇEK bir `<b>` kalır
     — yani yapıştırılan haberin TAMAMI kalın olur. Bu yüzden stili nötrleyen
     b/strong/i/em sarmalları, stil silinmeden ÖNCE açılır.
     ========================================================================== */

  /** Aşama 1 — saf dize temizliği (DOM'suz, testi kolay). */
  function yapistirmaOnTemizlik(html) {
    var s = String(html == null ? '' : html);
    // Word koşullu yorumları (içeriğiyle birlikte)
    s = s.replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '');
    s = s.replace(/<!\[if[\s\S]*?<!\[endif\]>/gi, '');
    // <xml>…</xml> (Word belge üstverisi) ve <style>…</style>
    s = s.replace(/<\s*xml\b[\s\S]*?<\s*\/\s*xml\s*>/gi, '');
    s = s.replace(/<\s*style\b[\s\S]*?<\s*\/\s*style\s*>/gi, '');
    s = s.replace(/<\s*script\b[\s\S]*?<\s*\/\s*script\s*>/gi, '');
    // Kalan HTML yorumları
    s = s.replace(/<!--[\s\S]*?-->/g, '');
    // Ad alanlı Word/Office etiketleri: <o:p>, </o:p>, <w:sdt>, <m:oMath>…
    s = s.replace(/<\/?[a-z]+:[a-z0-9_-]+[^>]*>/gi, '');
    // Word'ün bölüm/sayfa ayraçları
    s = s.replace(/<\s*(hr)[^>]*class="?msonormal/gi, '<$1 class="msonormal');
    return s;
  }

  /** Aşama 2 öncesi — stili nötrleyen b/strong/i/em sarmallarını açar. */
  function sahteVurguAc(kok) {
    var adaylar = kok.querySelectorAll ? kok.querySelectorAll('b,strong,i,em') : [];
    for (var i = adaylar.length - 1; i >= 0; i--) {
      var d = adaylar[i];
      var st = String(d.getAttribute('style') || '').toLowerCase().replace(/\s+/g, '');
      var kalin = (d.nodeName === 'B' || d.nodeName === 'STRONG');
      var sahte = kalin ? /font-weight:(normal|400)/.test(st) : /font-style:normal/.test(st);
      if (!sahte) { continue; }
      while (d.firstChild) { d.parentNode.insertBefore(d.firstChild, d); }
      d.parentNode.removeChild(d);
    }
  }

  /** Blok olarak kullanılmış sınıfsız div'leri paragrafa çevirir. */
  function divleriParagrafaCevir(kok) {
    var divler = kok.querySelectorAll ? kok.querySelectorAll('div') : [];
    for (var i = divler.length - 1; i >= 0; i--) {
      var d = divler[i];
      if (d.querySelector && d.querySelector('p,div,table,ul,ol,figure,blockquote')) { continue; }
      // DİKKAT: `kok` etkisiz bir DOMParser belgesinde olabilir; yeni düğüm o
      // belgede üretilmeli, yoksa Firefox 'WrongDocumentError' atar.
      var p = (d.ownerDocument || document).createElement('p');
      while (d.firstChild) { p.appendChild(d.firstChild); }
      d.parentNode.replaceChild(p, d);
    }
  }

  /**
   * Panodan gelen HTML'i gövdeye girebilecek hâle indirir.
   * @returns {string} temiz HTML (boşsa çağıran düz metne düşmelidir)
   */
  function yapistirmaTemizle(html) {
    // PANO GÜVENİLMEZDİR: etkisiz belgede ayrıştır (bkz. guvenliAyristir).
    var kap = guvenliAyristir(yapistirmaOnTemizlik(html)).kok;
    sahteVurguAc(kap);
    divleriParagrafaCevir(kap);
    temizleDugum(kap);

    var s = kap.innerHTML;
    // Word'ün sert boşlukları: satır içinde normal boşluğa iner.
    s = s.replace(/(&nbsp;| ){2,}/g, ' ');
    // Boş paragraf / boş başlık / boş hücre kalıntıları
    s = s.replace(/<(p|h2|h3|h4|li)>(\s|&nbsp;| |<br\s*\/?>)*<\/\1>/gi, '');
    s = s.replace(/<(p|h2|h3|h4)>\s*<\/\1>/gi, '');
    return s.trim();
  }

  /* ---------------------------------------------------- araç çubuğu tanımı */

  /**
   * Satır içi SVG simge üretir.
   *
   * NEDEN SVG: araç çubuğunda önce emoji kullanılıyordu (🔗 ⛓ 🖼). Windows'ta
   * bir kısmı sistem yazı tipinde bulunmayıp tofu kutusu olarak çıkıyordu.
   * SVG yazı tipinden bağımsızdır ve dış kütüphane gerektirmez.
   */
  function svg(ic) {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
         + 'aria-hidden="true" focusable="false">' + ic + '</svg>';
  }

  var DUGMELER = [
    { ad: 'bold',       etiket: '<strong>B</strong>', baslik: 'Kalın (Ctrl+B)',       komut: 'bold' },
    { ad: 'italic',     etiket: '<em>I</em>',         baslik: 'İtalik (Ctrl+I)',      komut: 'italic' },
    { ayrac: true },
    { ad: 'h2',         etiket: 'H2',                 baslik: 'Başlık 2',             blok: 'h2' },
    { ad: 'h3',         etiket: 'H3',                 baslik: 'Başlık 3',             blok: 'h3' },
    { ad: 'p',          etiket: '¶',                  baslik: 'Normal paragraf',      blok: 'p' },
    { ayrac: true },
    { ad: 'link',       etiket: svg('<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>'), baslik: 'Bağlantı ekle (Ctrl+K)' },
    { ad: 'unlink',     etiket: svg('<path d="M18.8 13.2 21 11a5 5 0 0 0-7-7l-2.2 2.2"/><path d="M5.2 10.8 3 13a5 5 0 0 0 7 7l2.2-2.2"/><path d="m2 2 20 20"/>'), baslik: 'Bağlantıyı kaldır',    komut: 'unlink' },
    { ad: 'image',      etiket: svg('<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-4.5-4.5L7 21"/>'), baslik: 'Görsel ekle' },
    { ayrac: true },
    { ad: 'quote',      etiket: svg('<path d="M7 15c-2 0-3-1.3-3-3.2C4 8.7 6 6.4 9 5"/><path d="M17 15c-2 0-3-1.3-3-3.2C14 8.7 16 6.4 19 5"/><path d="M7 15c0 2.2 1 3.6 3 4"/><path d="M17 15c0 2.2 1 3.6 3 4"/>'), baslik: 'Alıntı',               blok: 'blockquote' },
    { ad: 'ul',         etiket: svg('<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1.2" fill="currentColor"/><circle cx="4.5" cy="12" r="1.2" fill="currentColor"/><circle cx="4.5" cy="18" r="1.2" fill="currentColor"/>'), baslik: 'Madde listesi',        komut: 'insertUnorderedList' },
    { ad: 'ol',         etiket: svg('<path d="M10 6h10M10 12h10M10 18h10"/><path d="M4 5.5 5.2 5v3.5M3.4 15.2c0-.7.6-1.2 1.3-1.2s1.3.5 1.3 1.2c0 1.2-2.6 1.8-2.6 3.3h2.8"/>'), baslik: 'Numaralı liste',       komut: 'insertOrderedList' },
    { ad: 'hr',         etiket: svg('<path d="M3 12h18"/>'), baslik: 'Ayraç çizgisi',        komut: 'insertHorizontalRule' },
    { ayrac: true },
    { ad: 'table',      etiket: svg('<rect x="3" y="4" width="18" height="16" rx="1"/><path d="M3 10h18M3 15h18M9 4v16M15 4v16"/>'), baslik: 'Tablo ekle / düzenle' },
    { ad: 'embed',      etiket: svg('<rect x="2" y="5" width="20" height="14" rx="2"/><path d="m10 9 5 3-5 3z"/>'), baslik: 'Gömme ekle (YouTube, Vimeo, X, Instagram)' },
    { ayrac: true },
    { ad: 'preview',    etiket: svg('<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>'), baslik: 'Taslak önizleme bağlantısı' },
    { ad: 'clear',      etiket: svg('<path d="M4 7V5h13v2"/><path d="M10 5v9"/><path d="M7 19h7"/><path d="m16 14 5 5m0-5-5 5"/>'), baslik: 'Biçimi temizle' }
  ];

  /* ---------------------------------------------------- ana kurucu */

  /**
   * Gizli textarea'yı zengin metin editörüyle sarmalar.
   * @param {HTMLTextAreaElement} ta
   * @param {Object} [opts] { bos: 'yer tutucu metin' }
   * @returns {{html:function, setHtml:function, odak:function, kelimeSayisi:function}}
   */
  function baglat(ta, opts) {
    opts = opts || {};
    if (!ta || ta.nodeName !== 'TEXTAREA') { return null; }
    if (ta.mansetEditor) { return ta.mansetEditor; }

    var sarma = document.createElement('div');
    sarma.className = 'editor-sarma';

    var cubuk = document.createElement('div');
    cubuk.className = 'editor-cubugu';
    cubuk.setAttribute('role', 'toolbar');
    cubuk.setAttribute('aria-label', 'Metin biçimlendirme araçları');

    var alan = document.createElement('div');
    alan.className = 'editor-alan';
    alan.setAttribute('contenteditable', 'true');
    alan.setAttribute('role', 'textbox');
    alan.setAttribute('aria-multiline', 'true');
    alan.setAttribute('aria-label', opts.etiket || 'Haber gövdesi');
    alan.setAttribute('data-bos', opts.bos || 'Haber metnini buraya yazın…');
    if (ta.id) { alan.setAttribute('id', ta.id + '_editor'); }

    var durum = document.createElement('div');
    durum.className = 'editor-durum';
    var sKelime = document.createElement('span');
    var sKarakter = document.createElement('span');
    var sIpucu = document.createElement('span');
    sIpucu.className = 'soluk';
    sIpucu.textContent = 'Word/Docs yapıştırması temizlenir · Ctrl+S kaydeder · Ctrl+Shift+V düz metin';
    durum.appendChild(sKelime);
    durum.appendChild(sKarakter);
    durum.appendChild(sIpucu);

    sarma.appendChild(cubuk);
    sarma.appendChild(alan);
    sarma.appendChild(durum);

    ta.style.display = 'none';
    ta.setAttribute('aria-hidden', 'true');
    if (ta.parentNode) { ta.parentNode.insertBefore(sarma, ta.nextSibling); }

    // Başlangıç içeriği
    alan.innerHTML = htmlTemizle(ta.value || '');
    if (!alan.innerHTML) { alan.innerHTML = '<p><br></p>'; }

    try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* yoksay */ }
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e2) { /* yoksay */ }

    var sonAralik = null;

    function araligiSakla() {
      var sel = window.getSelection();
      if (sel && sel.rangeCount && alan.contains(sel.anchorNode)) {
        sonAralik = sel.getRangeAt(0).cloneRange();
      }
    }
    function araligiGeriYukle() {
      if (!sonAralik) { alan.focus(); return; }
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(sonAralik);
      alan.focus();
    }

    /** Temiz HTML üretir (canlı DOM'a dokunmadan, kopya üzerinden). */
    function temizCikti() {
      // Etkisiz belgede kopyala: içerik zaten canlı editörde ama bu yol her
      // senkronda çalışıyor; canlı belgede yeniden ayrıştırmak her tuş
      // vuruşunda görsel isteği tetiklerdi.
      var kopya = guvenliAyristir(alan.innerHTML).kok;
      temizleDugum(kopya);
      var s = kopya.innerHTML.replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '');
      return s.trim();
    }

    function sayilar() {
      var metin = (alan.innerText || alan.textContent || '').replace(/\u00A0/g, ' ');
      var kirpik = metin.replace(/\s+/g, ' ').trim();
      return {
        kelime: kirpik === '' ? 0 : kirpik.split(' ').length,
        karakter: metin.replace(/\n/g, '').length
      };
    }

    function sayacGuncelle() {
      var s = sayilar();
      sKelime.textContent = s.kelime + ' kelime';
      sKarakter.textContent = s.karakter + ' karakter';
    }

    /** textarea'ya temiz HTML yazar. kullanici=true ise input olayı tetiklenir. */
    function senkron(kullanici) {
      ta.value = temizCikti();
      sayacGuncelle();
      if (kullanici) {
        var ev;
        try { ev = new Event('input', { bubbles: true }); }
        catch (e) { ev = document.createEvent('Event'); ev.initEvent('input', true, false); }
        ta.dispatchEvent(ev);
      }
    }

    function komutCalistir(komut, deger) {
      alan.focus();
      // Her komuttan önce tazele: bazı tarayıcılar bu bayrağı sıfırlar ve
      // sıfırlanırsa biçimlendirme <span style> olarak üretilip temizlikte kaybolur.
      try { document.execCommand('styleWithCSS', false, false); } catch (eS) { /* yoksay */ }
      try { document.execCommand(komut, false, deger === undefined ? null : deger); }
      catch (e) { /* tarayıcı desteklemiyorsa sessiz geç */ }
      senkron(true);
      durumGuncelle();
    }

    /** İmlecin bulunduğu blok etiketini döndürür. */
    function aktifBlok() {
      var sel = window.getSelection();
      if (!sel || !sel.rangeCount) { return ''; }
      var d = sel.getRangeAt(0).startContainer;
      while (d && d !== alan) {
        if (d.nodeType === 1) {
          var ad = d.nodeName.toLowerCase();
          if (['p', 'h2', 'h3', 'h4', 'blockquote', 'li', 'pre'].indexOf(ad) >= 0) { return ad; }
        }
        d = d.parentNode;
      }
      return '';
    }

    function blokUygula(etiket) {
      var mevcut = aktifBlok();
      var hedef = (mevcut === etiket) ? 'p' : etiket;
      komutCalistir('formatBlock', '<' + hedef + '>');
    }

    /* -------------------------------------------------- bağlantı */
    function baglantiEkle() {
      araligiSakla();
      var mevcut = '';
      var sel = window.getSelection();
      if (sel && sel.rangeCount) {
        var d = sel.getRangeAt(0).startContainer;
        while (d && d !== alan) {
          if (d.nodeType === 1 && d.nodeName.toLowerCase() === 'a') { mevcut = d.getAttribute('href') || ''; break; }
          d = d.parentNode;
        }
      }

      function uygula(url, yeniSekme) {
        url = String(url || '').trim();
        if (!url) { return; }
        if (!guvenliUrl(url)) {
          if (window.M) { M.toast('err', 'Bu bağlantı şeması güvenli değil.'); }
          return;
        }
        if (!/^(https?:\/\/|mailto:|tel:|\/|#)/i.test(url)) { url = 'https://' + url; }
        araligiGeriYukle();
        try { document.execCommand('createLink', false, url); } catch (e) { /* yoksay */ }
        // Yeni oluşan bağlantıya güvenli öznitelikleri koy
        var baglar = alan.querySelectorAll('a[href]');
        for (var i = 0; i < baglar.length; i++) {
          if (baglar[i].getAttribute('href') !== url) { continue; }
          if (yeniSekme) { baglar[i].setAttribute('target', '_blank'); }
          if (disBaglanti(url)) {
            baglar[i].setAttribute('rel', yeniSekme ? 'noopener noreferrer' : 'noopener');
          }
        }
        senkron(true);
        durumGuncelle();
      }

      if (window.M && M.modal) {
        var govde = M.modal.ac('Bağlantı ekle',
          '<div class="form-alan"><label for="edLinkUrl">Adres</label>'
          + '<input type="text" id="edLinkUrl" placeholder="https://ornek.com/haber" value="' + M.esc(mevcut) + '"></div>'
          + '<div class="onay-satir"><input type="checkbox" id="edLinkYeni"><label for="edLinkYeni">Yeni sekmede açılsın</label></div>'
          + '<div class="dugme-grup"><button type="button" class="dugme birincil" id="edLinkTamam">Ekle</button>'
          + '<button type="button" class="dugme" id="edLinkVazgec">Vazgeç</button></div>', '440px');
        var girdi = govde.querySelector('#edLinkUrl');
        girdi.focus();
        govde.querySelector('#edLinkTamam').addEventListener('click', function () {
          var u = girdi.value;
          var y = govde.querySelector('#edLinkYeni').checked;
          M.modal.kapat();
          uygula(u, y);
        });
        govde.querySelector('#edLinkVazgec').addEventListener('click', function () { M.modal.kapat(); });
        girdi.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); govde.querySelector('#edLinkTamam').click(); }
        });
      } else {
        var cevap = window.prompt('Bağlantı adresi:', mevcut || 'https://');
        if (cevap !== null) { uygula(cevap, false); }
      }
    }

    /* -------------------------------------------------- görsel */
    function gorselEkle() {
      araligiSakla();
      if (!window.M || !M.medyaSec) {
        if (window.M) { M.toast('err', 'Medya kütüphanesi yüklenemedi.'); }
        return;
      }
      M.medyaSec(function (o) {
        araligiGeriYukle();
        var alt = (o.alt || o.filename || '').replace(/"/g, '&quot;');
        var html = '<figure class="manset-figure">'
          + '<img src="' + String(o.url).replace(/"/g, '&quot;') + '" alt="' + alt + '" loading="lazy">'
          + (o.alt ? '<figcaption>' + (window.M ? M.esc(o.alt) : o.alt) + '</figcaption>' : '')
          + '</figure><p><br></p>';
        try { document.execCommand('insertHTML', false, html); }
        catch (e) { alan.insertAdjacentHTML('beforeend', html); }
        senkron(true);
        durumGuncelle();
      });
    }

    /* -------------------------------------------------- HTML ekleme yardımcısı */
    function htmlEkle(html) {
      araligiGeriYukle();
      try { document.execCommand('insertHTML', false, html); }
      catch (e) { alan.insertAdjacentHTML('beforeend', html); }
      senkron(true);
      durumGuncelle();
    }

    /* -------------------------------------------------- tablo */

    /** İmlecin bulunduğu hücre / satır / tablo (yoksa null). */
    function tabloBaglam() {
      var sel = window.getSelection();
      var d = (sel && sel.rangeCount) ? sel.getRangeAt(0).startContainer : null;
      var hucre = null, tablo = null;
      while (d && d !== alan) {
        if (d.nodeType === 1) {
          var ad = d.nodeName.toLowerCase();
          if (!hucre && (ad === 'td' || ad === 'th')) { hucre = d; }
          if (ad === 'table') { tablo = d; break; }
        }
        d = d.parentNode;
      }
      if (!tablo) { return null; }
      var satir = hucre ? hucre.parentNode : null;
      return { tablo: tablo, satir: satir, hucre: hucre,
               sutun: (hucre && satir) ? Array.prototype.indexOf.call(satir.cells, hucre) : -1 };
    }

    /** rows×cols boş tablo HTML'i (başlık satırı `scope="col"` ile). */
    function tabloHtml(satir, sutun) {
      var h = '<table><caption>Tablo açıklaması</caption><thead><tr>';
      for (var s = 0; s < sutun; s++) { h += '<th scope="col">Başlık ' + (s + 1) + '</th>'; }
      h += '</tr></thead><tbody>';
      for (var r = 0; r < satir; r++) {
        h += '<tr>';
        for (var c = 0; c < sutun; c++) { h += '<td>&nbsp;</td>'; }
        h += '</tr>';
      }
      return h + '</tbody></table><p><br></p>';
    }

    /** Satır ekle: yon = -1 üst, +1 alt. */
    function satirEkle(ctx, yon) {
      if (!ctx || !ctx.satir) { return; }
      var kaynak = ctx.satir;
      var yeni = kaynak.cloneNode(true);
      for (var i = 0; i < yeni.cells.length; i++) {
        // Klon her zaman VERİ hücresi olur: başlık satırının altına ikinci bir
        // başlık satırı eklemek tabloyu erişilebilirlik açısından bozardı.
        var eski = yeni.cells[i];
        var td = document.createElement('td');
        td.innerHTML = '&nbsp;';
        if (eski.getAttribute('colspan')) { td.setAttribute('colspan', eski.getAttribute('colspan')); }
        eski.parentNode.replaceChild(td, eski);
      }
      var hedefGovde = kaynak.parentNode;
      if (kaynak.parentNode.nodeName.toLowerCase() === 'thead' && yon > 0) {
        // Başlıktan sonra eklenen satır gövdeye gider
        var tb = ctx.tablo.querySelector('tbody');
        if (tb) { tb.insertBefore(yeni, tb.firstChild); senkron(true); return; }
      }
      hedefGovde.insertBefore(yeni, yon < 0 ? kaynak : kaynak.nextSibling);
      senkron(true);
    }

    /** Sütun ekle: yon = -1 sol, +1 sağ. */
    function sutunEkle(ctx, yon) {
      if (!ctx || ctx.sutun < 0) { return; }
      var satirlar = ctx.tablo.rows;
      for (var i = 0; i < satirlar.length; i++) {
        var sr = satirlar[i];
        var ref = sr.cells[ctx.sutun];
        var basliktir = ref && ref.nodeName.toLowerCase() === 'th';
        var yeni = document.createElement(basliktir ? 'th' : 'td');
        if (basliktir) { yeni.setAttribute('scope', 'col'); yeni.textContent = 'Başlık'; }
        else { yeni.innerHTML = '&nbsp;'; }
        if (!ref) { sr.appendChild(yeni); }
        else { sr.insertBefore(yeni, yon < 0 ? ref : ref.nextSibling); }
      }
      senkron(true);
    }

    function satirSil(ctx) {
      if (!ctx || !ctx.satir) { return; }
      if (ctx.tablo.rows.length <= 1) { tabloSil(ctx); return; }
      ctx.satir.parentNode.removeChild(ctx.satir);
      senkron(true);
    }

    function sutunSil(ctx) {
      if (!ctx || ctx.sutun < 0) { return; }
      var satirlar = ctx.tablo.rows;
      if (satirlar[0] && satirlar[0].cells.length <= 1) { tabloSil(ctx); return; }
      for (var i = 0; i < satirlar.length; i++) {
        var h = satirlar[i].cells[ctx.sutun];
        if (h) { satirlar[i].removeChild(h); }
      }
      senkron(true);
    }

    function tabloSil(ctx) {
      if (!ctx || !ctx.tablo) { return; }
      ctx.tablo.parentNode.removeChild(ctx.tablo);
      if (!alan.innerHTML.trim()) { alan.innerHTML = '<p><br></p>'; }
      senkron(true);
    }

    function tabloAraci() {
      araligiSakla();
      var ctx = tabloBaglam();
      if (!window.M || !M.modal) {
        if (!ctx) { htmlEkle(tabloHtml(2, 3)); }
        return;
      }
      var html;
      if (ctx) {
        html = '<p class="soluk">İmleç bir tablonun içinde.</p>'
             + '<div class="dugme-grup" style="flex-wrap:wrap;gap:6px">'
             + '<button type="button" class="dugme kucuk" data-tb="satir-ust">Üste satır</button>'
             + '<button type="button" class="dugme kucuk" data-tb="satir-alt">Alta satır</button>'
             + '<button type="button" class="dugme kucuk" data-tb="sutun-sol">Sola sütun</button>'
             + '<button type="button" class="dugme kucuk" data-tb="sutun-sag">Sağa sütun</button>'
             + '<button type="button" class="dugme kucuk" data-tb="satir-sil">Satırı sil</button>'
             + '<button type="button" class="dugme kucuk" data-tb="sutun-sil">Sütunu sil</button>'
             + '<button type="button" class="dugme kucuk" data-tb="tablo-sil">Tabloyu sil</button>'
             + '</div>';
      } else {
        html = '<div class="form-alan"><label for="edTbSatir">Satır</label>'
             + '<input type="number" id="edTbSatir" min="1" max="30" value="3"></div>'
             + '<div class="form-alan"><label for="edTbSutun">Sütun</label>'
             + '<input type="number" id="edTbSutun" min="1" max="10" value="3"></div>'
             + '<div class="dugme-grup"><button type="button" class="dugme birincil" data-tb="ekle">Tabloyu ekle</button></div>';
      }
      var govde = M.modal.ac('Tablo', html, '460px');
      M.qsa('[data-tb]', govde).forEach(function (b) {
        b.addEventListener('click', function () {
          var eylem = b.getAttribute('data-tb');
          if (eylem === 'ekle') {
            var r = Math.max(1, Math.min(30, parseInt(govde.querySelector('#edTbSatir').value, 10) || 3));
            var c = Math.max(1, Math.min(10, parseInt(govde.querySelector('#edTbSutun').value, 10) || 3));
            M.modal.kapat();
            htmlEkle(tabloHtml(r, c));
            return;
          }
          // Düzenleme eylemleri canlı DOM üzerinde çalışır; bağlam TAZE okunur.
          var g = tabloBaglam();
          if (eylem === 'satir-ust')  { satirEkle(g, -1); }
          if (eylem === 'satir-alt')  { satirEkle(g, 1); }
          if (eylem === 'sutun-sol')  { sutunEkle(g, -1); }
          if (eylem === 'sutun-sag')  { sutunEkle(g, 1); }
          if (eylem === 'satir-sil')  { satirSil(g); }
          if (eylem === 'sutun-sil')  { sutunSil(g); }
          if (eylem === 'tablo-sil')  { tabloSil(g); M.modal.kapat(); }
        });
      });
    }

    /* -------------------------------------------------- gömme */

    /**
     * Gömme adresi SUNUCUDA çözülür (api.php?a=embed.resolve).
     * İstemcide oEmbed çağrısı YAPILMAZ: (1) tarayıcıdan atılan istek okurun
     * IP'sini sağlayıcıya verir, (2) SSRF kalkanı ve önbellek sunucudadır,
     * (3) gövdeye girecek işaretlemeye istemci karar veremez.
     */
    function gommeEkle() {
      araligiSakla();
      if (!window.M || !M.modal) { return; }
      var govde = M.modal.ac('Gömme ekle',
        '<div class="form-alan"><label for="edGomUrl">Adres</label>'
        + '<input type="text" id="edGomUrl" placeholder="https://www.youtube.com/watch?v=…"></div>'
        + '<p class="soluk">YouTube, Vimeo, X ve Instagram desteklenir. '
        + 'X ve Instagram gönderileri betik yüklemeyen bir <strong>bağlantı kartı</strong> olarak eklenir.</p>'
        + '<div class="dugme-grup"><button type="button" class="dugme birincil" id="edGomTamam">Ekle</button>'
        + '<button type="button" class="dugme" id="edGomVazgec">Vazgeç</button></div>'
        + '<div id="edGomDurum" class="soluk" role="status"></div>', '520px');

      var girdi = govde.querySelector('#edGomUrl');
      var durumEl = govde.querySelector('#edGomDurum');
      var dugme = govde.querySelector('#edGomTamam');
      girdi.focus();

      function calis() {
        var u = String(girdi.value || '').trim();
        if (!u) { return; }
        dugme.disabled = true;
        durumEl.textContent = 'Çözümleniyor…';
        M.api('embed.resolve', { url: u }).then(function (r) {
          dugme.disabled = false;
          if (!r || !r.ok) {
            durumEl.textContent = (r && r.error) ? r.error : 'Gömme çözümlenemedi.';
            return;
          }
          M.modal.kapat();
          // SUNUCUDAN GELEN HTML DE İSTEMCİ BEYAZ LİSTESİNDEN GEÇER.
          // Sunucu zaten sanitize_html() uyguladı; burada ikinci kez süzmek
          // "sunucu yanıtına güven" varsayımını hiç kurmamak içindir.
          var temiz = htmlTemizle(r.html || '');
          if (!temiz) { M.toast('err', 'Gömme yerel denetimden geçemedi.'); return; }
          htmlEkle(temiz + '<p><br></p>');
          M.toast('ok', (r.cached ? 'Gömme eklendi (önbellek).' : 'Gömme eklendi.'));
        });
      }
      dugme.addEventListener('click', calis);
      govde.querySelector('#edGomVazgec').addEventListener('click', function () { M.modal.kapat(); });
      girdi.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); calis(); }
      });
    }

    /* -------------------------------------------------- taslak önizleme bağlantısı */

    /** Düzenlenen haberin kimliği (posts.php'deki gizli `id` alanından). */
    function haberKimligi() {
      if (opts.postId) { return parseInt(opts.postId, 10) || 0; }
      var f = ta.form;
      var el = f ? f.querySelector('input[name=id]') : null;
      return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    function onizlemeBaglantisi() {
      if (!window.M || !M.modal) { return; }
      var id = haberKimligi();
      if (id <= 0) {
        M.toast('err', 'Önce haberi kaydedin; önizleme bağlantısı kayıtlı bir taslağa üretilir.');
        return;
      }
      var govde = M.modal.ac('Taslak önizleme bağlantısı',
        '<p class="soluk">Bağlantı <strong>süreli</strong>dir, yalnız bu haberi açar, '
        + 'arama motorlarına kapalıdır ve açan kişiye oturum vermez.</p>'
        + '<div class="form-alan"><label for="edOnSaat">Geçerlilik (saat)</label>'
        + '<input type="number" id="edOnSaat" min="1" max="168" value="48"></div>'
        + '<div class="dugme-grup"><button type="button" class="dugme birincil" id="edOnUret">Bağlantı üret</button>'
        + '<button type="button" class="dugme" id="edOnGeri">Bağlantıları geri çek</button></div>'
        + '<div id="edOnSonuc" role="status"></div>', '560px');

      var sonucEl = govde.querySelector('#edOnSonuc');
      govde.querySelector('#edOnUret').addEventListener('click', function () {
        var saat = parseInt(govde.querySelector('#edOnSaat').value, 10) || 48;
        sonucEl.textContent = 'Üretiliyor…';
        M.api('preview.create', { id: id, hours: saat }).then(function (r) {
          if (!r || !r.ok) { sonucEl.textContent = (r && r.error) ? r.error : 'Bağlantı üretilemedi.'; return; }
          sonucEl.innerHTML = '<p><a href="' + M.esc(r.url) + '" target="_blank" rel="noopener noreferrer">'
            + M.esc(r.url) + '</a></p><p class="soluk">' + M.esc(r.note || '')
            + ' Son kullanma: ' + M.esc(r.expires_at || '') + '</p>';
        });
      });
      govde.querySelector('#edOnGeri').addEventListener('click', function () {
        sonucEl.textContent = 'Geri çekiliyor…';
        M.api('preview.revoke', { id: id }).then(function (r) {
          sonucEl.textContent = (r && r.ok) ? (r.message || 'Geri çekildi.')
                                            : ((r && r.error) ? r.error : 'İşlem başarısız.');
        });
      });
    }

    /* -------------------------------------------------- biçim temizle */
    function bicimTemizle() {
      komutCalistir('removeFormat');
      // Canlı DOM'u da beyaz listeye indir (style/class kalıntıları için)
      temizleDugum(alan);
      if (!alan.innerHTML.trim()) { alan.innerHTML = '<p><br></p>'; }
      senkron(true);
    }

    /* -------------------------------------------------- düğmeleri kur */
    var dugmeHaritasi = {};
    DUGMELER.forEach(function (tanim) {
      if (tanim.ayrac) {
        var ay = document.createElement('span');
        ay.className = 'ayrac';
        ay.setAttribute('aria-hidden', 'true');
        cubuk.appendChild(ay);
        return;
      }
      var b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = tanim.etiket;
      b.title = tanim.baslik;
      b.setAttribute('aria-label', tanim.baslik);
      b.setAttribute('aria-pressed', 'false');
      b.setAttribute('data-ed', tanim.ad);
      // Odak kaybını engelle: seçim korunsun
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () {
        if (tanim.blok) { blokUygula(tanim.blok); return; }
        if (tanim.komut) { komutCalistir(tanim.komut); return; }
        if (tanim.ad === 'link') { baglantiEkle(); return; }
        if (tanim.ad === 'image') { gorselEkle(); return; }
        if (tanim.ad === 'table') { tabloAraci(); return; }
        if (tanim.ad === 'embed') { gommeEkle(); return; }
        if (tanim.ad === 'preview') { onizlemeBaglantisi(); return; }
        if (tanim.ad === 'clear') { bicimTemizle(); return; }
      });
      cubuk.appendChild(b);
      dugmeHaritasi[tanim.ad] = b;
    });

    /** aria-pressed durumlarını tazeler. */
    function durumGuncelle() {
      function ayarla(ad, acik) {
        var b = dugmeHaritasi[ad];
        if (!b) { return; }
        b.setAttribute('aria-pressed', acik ? 'true' : 'false');
        if (acik) { b.classList.add('etkin'); } else { b.classList.remove('etkin'); }
      }
      var durumOku = function (k) {
        try { return document.queryCommandState(k); } catch (e) { return false; }
      };
      ayarla('bold', durumOku('bold'));
      ayarla('italic', durumOku('italic'));
      ayarla('ul', durumOku('insertUnorderedList'));
      ayarla('ol', durumOku('insertOrderedList'));
      var blok = aktifBlok();
      ayarla('h2', blok === 'h2');
      ayarla('h3', blok === 'h3');
      ayarla('p', blok === 'p');
      ayarla('quote', blok === 'blockquote');
    }

    /* -------------------------------------------------- olaylar */
    alan.addEventListener('input', function () { senkron(true); });
    alan.addEventListener('keyup', function () { araligiSakla(); durumGuncelle(); });
    alan.addEventListener('mouseup', function () { araligiSakla(); durumGuncelle(); });
    alan.addEventListener('blur', function () { araligiSakla(); senkron(false); });

    /** Düz metni imlece yazar (biçimli yol tutmadığında yedek). */
    function duzMetinYapistir(metin) {
      metin = String(metin || '').replace(/\r\n/g, '\n');
      if (!metin) { return; }
      try { document.execCommand('insertText', false, metin); }
      catch (err) {
        var sel = window.getSelection();
        if (sel && sel.rangeCount) {
          var r = sel.getRangeAt(0);
          r.deleteContents();
          r.insertNode(document.createTextNode(metin));
          r.collapse(false);
        }
      }
    }

    /*
     * BİÇİMLİ YAPIŞTIRMA.
     * Pano HTML taşıyorsa temizlenip eklenir; taşımıyorsa (ya da temizlikten
     * geriye bir şey kalmıyorsa) düz metne düşülür. Ctrl+Shift+V ZORLA düz
     * metindir — muhabir "hiçbir biçim istemiyorum" diyebilmelidir.
     */
    alan.addEventListener('paste', function (e) {
      e.preventDefault();
      var pano = e.clipboardData || window.clipboardData;
      if (!pano) { return; }

      var duzZorla = e.shiftKey === true;
      var ham = duzZorla ? '' : (pano.getData('text/html') || '');
      if (ham) {
        var temiz = '';
        try { temiz = yapistirmaTemizle(ham); } catch (eT) { temiz = ''; }
        if (temiz) {
          try { document.execCommand('insertHTML', false, temiz); }
          catch (eI) { alan.insertAdjacentHTML('beforeend', temiz); }
          senkron(true);
          durumGuncelle();
          return;
        }
      }
      duzMetinYapistir(pano.getData('text/plain') || '');
      senkron(true);
    });

    // Sürükle-bırakla gelen HTML de düz metne indirilir
    alan.addEventListener('drop', function (e) {
      if (!e.dataTransfer) { return; }
      var metin = e.dataTransfer.getData('text/plain') || '';
      if (!metin) { return; }
      e.preventDefault();
      try { document.execCommand('insertText', false, metin); } catch (err) { /* yoksay */ }
      senkron(true);
    });

    // Klavye kısayolları
    alan.addEventListener('keydown', function (e) {
      var mod = e.ctrlKey || e.metaKey;
      if (!mod || e.altKey) { return; }
      var t = String(e.key || '').toLowerCase();
      if (t === 'b') { e.preventDefault(); komutCalistir('bold'); }
      else if (t === 'i') { e.preventDefault(); komutCalistir('italic'); }
      else if (t === 'k') { e.preventDefault(); baglantiEkle(); }
    });

    // Form gönderiminden hemen önce son senkron
    var form = ta.form;
    if (form) { form.addEventListener('submit', function () { senkron(false); }); }

    /*
     * CTRL+S = KAYDET.
     * Dinleyici FORMA bağlanır, belgeye değil: panelde aynı anda başka formlar
     * da olabilir ve editörün kısayolu yalnız kendi formunu göndermelidir.
     * Kaydetme akışını BİZ yazmayız — `requestSubmit()` formun kendi `submit`
     * olayını tetikler, haber ekranındaki mevcut dinleyici işi yapar
     * (admin/pages/posts.php Ajan-F'nin dosyasıdır, oraya dokunulmadı).
     */
    if (form) {
      form.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.altKey) { return; }
        if (String(e.key || '').toLowerCase() !== 's') { return; }
        e.preventDefault();
        senkron(false);
        if (typeof form.requestSubmit === 'function') { form.requestSubmit(); return; }
        // Eski tarayıcı yedeği: iptal edilebilir submit olayı gönder.
        var ev;
        try { ev = new Event('submit', { bubbles: true, cancelable: true }); }
        catch (e2) { ev = document.createEvent('Event'); ev.initEvent('submit', true, true); }
        form.dispatchEvent(ev);
      });
    }

    senkron(false);
    durumGuncelle();

    var api = {
      /** Temizlenmiş HTML. */
      html: function () { return temizCikti(); },
      /** İçeriği değiştirir (temizleyerek). */
      setHtml: function (s) {
        alan.innerHTML = htmlTemizle(s);
        if (!alan.innerHTML.trim()) { alan.innerHTML = '<p><br></p>'; }
        senkron(false);
      },
      /** Düzenleme alanına odaklanır. */
      odak: function () { alan.focus(); },
      /** Kelime sayısı. */
      kelimeSayisi: function () { return sayilar().kelime; },
      /** Karakter sayısı (ek kolaylık). */
      karakterSayisi: function () { return sayilar().karakter; },
      /** Alt düğümlere erişim (sayfa betikleri için). */
      alan: alan,
      sarma: sarma
    };
    ta.mansetEditor = api;
    return api;
  }

  window.MansetEditor = {
    baglat: baglat,
    temizle: htmlTemizle,
    // Ölçülebilirlik: aşama 1 saf dize işlemidir, DOM'suz sınanabilir
    // (tests/unit/… karşılığı yok; node ile doğrudan çağrılır).
    yapistirmaOnTemizlik: yapistirmaOnTemizlik
  };
})();
