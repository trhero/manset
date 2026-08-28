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
    blockquote: ['cite'],
    a: ['href', 'title', 'target', 'rel'],
    img: ['src', 'alt', 'title', 'width', 'height', 'loading', 'srcset', 'sizes'],
    figure: ['class'], figcaption: [],
    table: [], thead: [], tbody: [], tr: [],
    th: ['colspan', 'rowspan'], td: ['colspan', 'rowspan'],
    iframe: ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title'],
    span: ['class'], div: ['class'], code: [], pre: []
  };

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

  /** HTML dizesini temizler ve dize olarak döndürür. */
  function htmlTemizle(html) {
    var kap = document.createElement('div');
    kap.innerHTML = String(html == null ? '' : html);
    temizleDugum(kap);
    var cikti = kap.innerHTML;
    // Boş paragrafları sadeleştir
    cikti = cikti.replace(/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/gi, '');
    return cikti.trim();
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
    sIpucu.textContent = 'Yapıştırılan metin düz metne çevrilir.';
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
      var kopya = document.createElement('div');
      kopya.innerHTML = alan.innerHTML;
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

    // Yapıştırma daima düz metin
    alan.addEventListener('paste', function (e) {
      e.preventDefault();
      var pano = e.clipboardData || window.clipboardData;
      var metin = pano ? (pano.getData('text/plain') || '') : '';
      metin = metin.replace(/\r\n/g, '\n');
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
    temizle: htmlTemizle
  };
})();
