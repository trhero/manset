/* ============================================================================
   Manşet — Tema Editörü betiği (Ajan-7).

   Bağımlılık: assets/admin.js (window.M) — <head> içinde defer'siz yüklenir.
   Veri kaynağı: admin/pages/theme.php içindeki window.__TEMA paketi.
   Dış kütüphane yoktur.

   Canlı önizleme: önizleme iframe'i site ile AYNI ORIGIN'de olduğu için
   iframe belgesine doğrudan bir <style id="manset-onizleme"> düğümü enjekte
   edilir. postMessage yolu yedek olarak ayrıca gönderilir.
   ============================================================================ */
(function () {
  'use strict';

  var M = window.M;
  var T = window.__TEMA || {};
  if (!M) { return; }

  /* -------------------------------------------------- durum */
  var degerler = {};          // anahtar => değer (kaydedilmemiş olabilir)
  var bloklar = [];           // [{type,on,title,limit,category_id,slot}]
  var etkinGrup = '';
  var kirli = null;
  var cerceve = null;
  var cerceveHazir = false;
  var sunucuZaman = null;
  var siteKok = '';

  /* -------------------------------------------------- küçük yardımcılar */
  function el(id) { return document.getElementById(id); }

  function kopya(o) {
    var out = {};
    Object.keys(o || {}).forEach(function (k) { out[k] = o[k]; });
    return out;
  }

  /** '#abc' → '#aabbcc' (input[type=color] 6 hane ister). */
  function hexGenislet(v) {
    v = String(v || '').trim();
    if (/^#[0-9a-f]{3}$/i.test(v)) {
      return '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3];
    }
    return /^#[0-9a-f]{6}$/i.test(v) ? v : '#000000';
  }

  function alan(key) {
    var i, s = T.schema || [];
    for (i = 0; i < s.length; i++) { if (s[i].key === key) { return s[i]; } }
    return null;
  }

  /* ==========================================================================
     1) CSS üretimi — inc/view.php theme_css_vars() kurallarının aynısı
     ========================================================================== */
  function cssUret(vals) {
    var satirlar = [];
    (T.schema || []).forEach(function (f) {
      if (!f.css_var || !f.key) { return; }
      var v = vals[f.key];
      if (v === undefined || v === null) { v = ''; }
      v = String(v);
      if (v === '') { return; }

      if (f.type === 'color') {
        if (!/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) { return; }
      } else if (f.type === 'size') {
        v = v.replace(/[^0-9a-z%.\-]/gi, '');
        if (v === '') { return; }
        if (/^-?\d+(\.\d+)?$/.test(v)) { v += (f.unit || 'px'); }
      } else {
        v = v.replace(/[<>{};"]/g, '');
      }

      var ad = String(f.css_var).replace(/[^a-z0-9-]/gi, '').replace(/^-+/, '');
      if (!ad) { return; }
      satirlar.push('  --' + ad + ': ' + v + ';');
    });
    if (!satirlar.length) { return ''; }
    return ':root{\n' + satirlar.join('\n') + '\n}\n';
  }

  function ozelCss(vals) {
    var c = String(vals.custom_css || '');
    if (!c.trim()) { return ''; }
    return c.replace(/<\/style/gi, '<\\/style').replace(/<script/gi, '<\\script');
  }

  function karanlikMi() {
    return !!T.supportsDark && String(degerler.dark_mode || '0') === '1';
  }

  /* ==========================================================================
     2) Önizleme
     ========================================================================== */
  function cerceveBelge() {
    try {
      return cerceve && cerceve.contentDocument ? cerceve.contentDocument : null;
    } catch (e) { return null; }
  }

  function durum(mesaj) {
    var d = el('onizlemeDurum');
    if (d) { d.textContent = mesaj; }
  }

  /** Önizleme adresini üretir. `_p` yalnız önbellek atlatma parametresidir; ön yüz yok sayar. */
  function onizlemeAdresi() {
    var kok = String(T.onizlemeUrl || '/');
    return kok + (kok.indexOf('?') >= 0 ? '&' : '?') + '_p=' + Date.now();
  }

  function onizlemeYukle() {
    if (!cerceve) { return; }
    cerceveHazir = false;
    durum('yükleniyor…');
    cerceve.src = onizlemeAdresi();
  }

  /** Ayarları önizlemeye yansıtır: doğrudan DOM enjeksiyonu + postMessage yedeği. */
  function onizlemeUygula() {
    var css = cssUret(degerler) + ozelCss(degerler);
    var koyu = karanlikMi();
    var doc = cerceveBelge();

    if (doc && doc.head) {
      var st = doc.getElementById('manset-onizleme');
      if (!st) {
        st = doc.createElement('style');
        st.id = 'manset-onizleme';
        doc.head.appendChild(st);
      }
      // Her zaman en sonda dursun ki aynı özgüllükteki :root kurallarını ezsin
      if (doc.head.lastChild !== st) { doc.head.appendChild(st); }
      st.textContent = css;

      if (doc.body) {
        if (T.supportsDark) {
          doc.body.classList.toggle('karanlik', koyu);
          doc.body.classList.toggle('aydinlik', !koyu);
        }
        doc.documentElement.setAttribute('data-tema', koyu ? 'karanlik' : 'aydinlik');
      }
      cerceveHazir = true;
      durum('canlı önizleme');
    } else if (cerceve && cerceve.src && cerceve.src !== 'about:blank') {
      durum('önizlemeye erişilemedi — yenileyin');
    }

    // postMessage yedeği (aynı origin garantili olsa da sözleşme gereği gönderilir)
    try {
      if (cerceve && cerceve.contentWindow && siteKok) {
        cerceve.contentWindow.postMessage({ tip: 'manset-onizleme', css: css, dark: koyu }, siteKok);
      }
    } catch (e) { /* yok say */ }
  }

  /** Sunucudan doğrulanmış CSS ister (geçersiz değerler orada elenir). */
  function sunucuCssIste() {
    clearTimeout(sunucuZaman);
    sunucuZaman = setTimeout(function () {
      M.api('theme.preview_css', { name: T.aktif, values: degerler }).then(function (r) {
        if (!r || !r.ok) { return; }
        var doc = cerceveBelge();
        if (!doc || !doc.head) { return; }
        var st = doc.getElementById('manset-onizleme');
        if (!st) { return; }
        st.textContent = String(r.css || '') + String(r.custom || '');
        if (r.errors && r.errors.length) { durum(r.errors.length + ' değer geçersiz'); }
      });
    }, 450);
  }

  function degistir(key, deger) {
    degerler[key] = deger;
    onizlemeUygula();
    sunucuCssIste();
  }

  function cihaz(ad) {
    if (!cerceve) { return; }
    var g = ad === 'mobil' ? '375px' : (ad === 'tablet' ? '768px' : '100%');
    cerceve.style.width = g;
    M.qsa('[data-cihaz]').forEach(function (b) {
      b.classList.toggle('etkin', b.getAttribute('data-cihaz') === ad);
    });
  }

  /* ==========================================================================
     3) Ayar alanlarının şemadan üretimi
     ========================================================================== */
  function gruplar() {
    var sira = [], gorulen = {};
    (T.schema || []).forEach(function (f) {
      if (f.admin_only && !T.yonetici) { return; }
      if (f.key === 'dark_mode' && !T.supportsDark) { return; }
      var g = f.group || 'Gelişmiş';
      if (!gorulen[g]) { gorulen[g] = true; sira.push(g); }
    });
    var oncelik = { 'Renkler': 1, 'Tipografi': 2, 'Yerleşim': 3 };
    sira.sort(function (a, b) {
      var pa = oncelik[a] || (a === 'Gelişmiş' ? 90 : 50);
      var pb = oncelik[b] || (b === 'Gelişmiş' ? 90 : 50);
      if (pa !== pb) { return pa - pb; }
      return 0;
    });
    return sira;
  }

  function sekmeleriCiz() {
    var kap = el('temaSekmeler');
    if (!kap) { return; }
    var gs = gruplar();
    if (!etkinGrup || gs.indexOf(etkinGrup) < 0) { etkinGrup = gs[0] || ''; }
    kap.innerHTML = gs.map(function (g) {
      return '<button type="button" role="tab" data-grup="' + M.esc(g) + '"'
        + (g === etkinGrup ? ' class="etkin" aria-selected="true"' : ' aria-selected="false"')
        + '>' + M.esc(g) + '</button>';
    }).join('');
    M.qsa('[data-grup]', kap).forEach(function (b) {
      b.addEventListener('click', function () {
        etkinGrup = b.getAttribute('data-grup');
        sekmeleriCiz();
        alanlariCiz();
      });
    });
  }

  function yardimHtml(f) {
    return f.help ? '<span class="form-yardim">' + M.esc(f.help) + '</span>' : '';
  }

  function alanHtml(f) {
    var id = 'ta_' + f.key;
    var v = degerler[f.key] === undefined ? '' : String(degerler[f.key]);
    var basi = '<div class="form-alan te-alan-grup" data-alan="' + M.esc(f.key) + '">';
    var etiket = '<label class="form-etiket" for="' + id + '">' + M.esc(f.label) + '</label>';

    switch (f.type) {

      case 'color':
        return basi + etiket
          + '<div class="renk-alan">'
          + '<input type="color" id="' + id + '" value="' + M.esc(hexGenislet(v)) + '" aria-label="' + M.esc(f.label) + ' seçici">'
          + '<input type="text" data-hex="' + M.esc(f.key) + '" value="' + M.esc(v) + '" maxlength="7" spellcheck="false">'
          + '<button type="button" class="dugme kucuk" data-varsayilan="' + M.esc(f.key) + '" title="Varsayılan: ' + M.esc(f.default) + '">↺</button>'
          + '</div>' + yardimHtml(f) + '</div>';

      case 'size': {
        var sayi = v.replace(/[^0-9.\-]/g, '');
        var birim = f.unit || 'px';
        var min = f.min !== '' ? parseFloat(f.min) : null;
        var max = f.max !== '' ? parseFloat(f.max) : null;
        var kaydirak = (min !== null && max !== null)
          ? '<input type="range" data-range="' + M.esc(f.key) + '" min="' + min + '" max="' + max + '" step="1" value="' + M.esc(sayi || '0') + '">'
          : '';
        return basi + etiket
          + '<div class="te-sinir">'
          + '<input type="number" id="' + id + '" value="' + M.esc(sayi) + '"'
          + (min !== null ? ' min="' + min + '"' : '') + (max !== null ? ' max="' + max + '"' : '') + '>'
          + '<span class="te-birim">' + M.esc(birim) + '</span>'
          + kaydirak
          + '</div>' + yardimHtml(f) + '</div>';
      }

      case 'font': {
        var yiginlar = T.fonts || [];
        var bulundu = false;
        var secenek = yiginlar.map(function (y) {
          var s = y.value === v;
          if (s) { bulundu = true; }
          return '<option value="' + M.esc(y.value) + '"' + (s ? ' selected' : '') + '>' + M.esc(y.label) + '</option>';
        }).join('');
        secenek += '<option value="__ozel"' + (!bulundu && v !== '' ? ' selected' : '') + '>Özel (elle yazılır)</option>';
        var ozelGorunur = (!bulundu && v !== '');
        return basi + etiket
          + '<select id="' + id + '" data-font="' + M.esc(f.key) + '">' + secenek + '</select>'
          + '<input type="text" data-font-ozel="' + M.esc(f.key) + '" class="kod-alan bosluk-ust" value="' + M.esc(v) + '"'
          + (ozelGorunur ? '' : ' hidden') + ' placeholder="ör. Inter, system-ui, sans-serif">'
          + yardimHtml(f) + '</div>';
      }

      case 'select': {
        var ops = (f.options || []).map(function (o) {
          return '<option value="' + M.esc(o.value) + '"' + (String(o.value) === v ? ' selected' : '') + '>'
            + M.esc(o.label || o.value) + '</option>';
        }).join('');
        return basi + etiket + '<select id="' + id + '">' + ops + '</select>' + yardimHtml(f) + '</div>';
      }

      case 'bool':
        return basi
          + '<label class="anahtar">'
          + '<input type="checkbox" id="' + id + '"' + (v === '1' ? ' checked' : '') + '>'
          + '<span class="kaydirak"></span><span>' + M.esc(f.label) + '</span>'
          + '</label>' + yardimHtml(f) + '</div>';

      case 'image':
        return basi + etiket
          + '<div class="esnek">'
          + '<input type="text" id="' + id + '" value="' + M.esc(v) + '" readonly style="flex:1">'
          + '<button type="button" class="dugme kucuk" data-gorsel="' + M.esc(f.key) + '">Seç</button>'
          + '<button type="button" class="dugme kucuk" data-gorsel-sil="' + M.esc(f.key) + '">Kaldır</button>'
          + '</div>'
          + '<div class="gorsel-onizleme bosluk-ust" data-gorsel-onizleme="' + M.esc(f.key) + '"' + (v ? '' : ' hidden') + '>'
          + (v ? '<img src="' + M.esc(T.uploads + v) + '" alt="">' : '')
          + '</div>' + yardimHtml(f) + '</div>';

      case 'css':
        return basi + etiket
          + '<textarea id="' + id + '" class="kod-alan" rows="10" spellcheck="false"'
          + ' placeholder="/* çıktıya olduğu gibi eklenir */">' + M.esc(v) + '</textarea>'
          + '<div class="te-css-sayac" data-css-sayac="' + M.esc(f.key) + '"></div>'
          + '<span class="form-yardim">Bu CSS site çıktısına <strong>olduğu gibi</strong> eklenir '
          + 've yalnız yönetici düzenleyebilir. En çok ' + (T.cssLimit || 20000) + ' karakter.</span>'
          + '</div>';

      default:
        return basi + etiket
          + '<input type="text" id="' + id + '" value="' + M.esc(v) + '" maxlength="500"'
          + (f.key === 'google_fonts_url' ? ' placeholder="https://fonts.googleapis.com/css2?family=…"' : '') + '>'
          + yardimHtml(f) + '</div>';
    }
  }

  function alanlariCiz() {
    var kap = el('temaAlanlar');
    if (!kap) { return; }

    var liste = (T.schema || []).filter(function (f) {
      if (f.admin_only && !T.yonetici) { return false; }
      if (f.key === 'dark_mode' && !T.supportsDark) { return false; }
      return (f.group || 'Gelişmiş') === etkinGrup;
    });

    var html = liste.map(alanHtml).join('');

    // Karanlık modu desteklemeyen temada bilgi notu
    if (etkinGrup === 'Gelişmiş' && !T.supportsDark) {
      html += '<div class="uyari bilgi">Bu tema karanlık modu desteklemiyor '
        + '(<code>theme.json → supports_dark: false</code>), bu yüzden karanlık mod anahtarı gizlendi.</div>';
    }
    if (!html) { html = '<p class="soluk">Bu grupta ayar yok.</p>'; }
    kap.innerHTML = html;

    liste.forEach(bagla);
    sayaclariGuncelle();
  }

  function bagla(f) {
    var id = 'ta_' + f.key;
    var g = el(id);

    if (f.type === 'color') {
      var hex = M.qs('[data-hex="' + f.key + '"]');
      var vars = M.qs('[data-varsayilan="' + f.key + '"]');
      if (g) {
        g.addEventListener('input', function () {
          if (hex) { hex.value = g.value; }
          degistir(f.key, g.value);
        });
      }
      if (hex) {
        hex.addEventListener('input', function () {
          var v = hex.value.trim();
          if (v && v[0] !== '#') { v = '#' + v; hex.value = v; }
          if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(v)) {
            hex.classList.remove('hata');
            if (g) { g.value = hexGenislet(v); }
            degistir(f.key, v.toLowerCase());
          }
        });
      }
      if (vars) {
        vars.addEventListener('click', function () {
          if (hex) { hex.value = f.default; }
          if (g) { g.value = hexGenislet(f.default); }
          degistir(f.key, f.default);
        });
      }
      return;
    }

    if (f.type === 'size') {
      var rng = M.qs('[data-range="' + f.key + '"]');
      var yaz = function (sayi) {
        var birim = f.unit || 'px';
        degistir(f.key, sayi === '' ? '' : (String(sayi) + birim));
      };
      if (g) {
        g.addEventListener('input', function () {
          if (rng) { rng.value = g.value; }
          yaz(g.value);
        });
      }
      if (rng) {
        rng.addEventListener('input', function () {
          if (g) { g.value = rng.value; }
          yaz(rng.value);
        });
      }
      return;
    }

    if (f.type === 'font') {
      var ozel = M.qs('[data-font-ozel="' + f.key + '"]');
      if (g) {
        g.addEventListener('change', function () {
          if (g.value === '__ozel') {
            if (ozel) { ozel.hidden = false; ozel.focus(); }
            return;
          }
          if (ozel) { ozel.hidden = true; ozel.value = g.value; }
          degistir(f.key, g.value);
        });
      }
      if (ozel) {
        ozel.addEventListener('input', function () { degistir(f.key, ozel.value); });
      }
      return;
    }

    if (f.type === 'bool') {
      if (g) {
        g.addEventListener('change', function () { degistir(f.key, g.checked ? '1' : '0'); });
      }
      return;
    }

    if (f.type === 'image') {
      var sec = M.qs('[data-gorsel="' + f.key + '"]');
      var sil = M.qs('[data-gorsel-sil="' + f.key + '"]');
      var onz = M.qs('[data-gorsel-onizleme="' + f.key + '"]');
      if (sec) {
        sec.addEventListener('click', function () {
          M.medyaSec(function (o) {
            if (g) { g.value = o.filename; }
            if (onz) { onz.hidden = false; onz.innerHTML = '<img src="' + M.esc(o.url) + '" alt="">'; }
            degistir(f.key, o.filename);
          });
        });
      }
      if (sil) {
        sil.addEventListener('click', function () {
          if (g) { g.value = ''; }
          if (onz) { onz.hidden = true; onz.innerHTML = ''; }
          degistir(f.key, '');
        });
      }
      return;
    }

    if (f.type === 'css') {
      if (g) {
        g.addEventListener('input', function () {
          degistir(f.key, g.value);
          sayaclariGuncelle();
        });
      }
      return;
    }

    // text · select · diğer
    if (g) {
      var olay = f.type === 'select' ? 'change' : 'input';
      g.addEventListener(olay, function () {
        var v = g.value;
        if (f.key === 'google_fonts_url' && v.trim() !== ''
            && v.trim().indexOf('https://fonts.googleapis.com/') !== 0) {
          g.setAttribute('aria-invalid', 'true');
          durum('Google Fonts adresi yalnız https://fonts.googleapis.com/ olabilir');
        } else {
          g.removeAttribute('aria-invalid');
        }
        degistir(f.key, v);
      });
    }
  }

  function sayaclariGuncelle() {
    var sinir = T.cssLimit || 20000;
    M.qsa('[data-css-sayac]').forEach(function (s) {
      var k = s.getAttribute('data-css-sayac');
      var n = String(degerler[k] || '').length;
      s.textContent = n + ' / ' + sinir + ' karakter';
      s.classList.toggle('dolu', n > sinir);
    });
    var sayac = el('temaSayaci');
    if (sayac) { sayac.textContent = (T.schema || []).length + ' ayar · ' + T.aktif; }
  }

  /* ==========================================================================
     4) Anasayfa blok dizilimi
     ========================================================================== */
  function blokAlanlari(tip) {
    var a = { baslik: false, adet: false, kategori: false, slot: false };
    if (tip === 'category') { a.kategori = true; a.adet = true; a.baslik = true; }
    else if (tip === 'ad') { a.slot = true; }
    else if (['latest', 'most_read', 'editor_pick', 'video'].indexOf(tip) >= 0) { a.adet = true; a.baslik = true; }
    else if (['breaking', 'headline'].indexOf(tip) >= 0) { a.adet = true; a.baslik = true; }
    else { a.baslik = true; }
    return a;
  }

  function blokEtiket(tip) {
    return (T.blockLabels && T.blockLabels[tip]) ? T.blockLabels[tip] : tip;
  }

  function bloklariCiz() {
    var kap = el('blokListe');
    if (!kap) { return; }
    if (!bloklar.length) {
      kap.innerHTML = '<div class="bos-durum"><span class="buyuk">▤</span>'
        + '<h3>Blok yok</h3><p>Aşağıdan blok ekleyin.</p></div>';
      return;
    }

    kap.innerHTML = bloklar.map(function (b, i) {
      var a = blokAlanlari(b.type);
      var alanlar = '';

      if (a.baslik) {
        alanlar += '<div class="te-w-baslik"><label for="bt_' + i + '">Özel başlık</label>'
          + '<input type="text" id="bt_' + i + '" data-b-baslik="' + i + '" maxlength="80" value="' + M.esc(b.title || '') + '"'
          + ' placeholder="varsayılan"></div>';
      }
      if (a.adet) {
        alanlar += '<div class="te-w-adet"><label for="bl_' + i + '">Adet</label>'
          + '<input type="number" id="bl_' + i + '" data-b-adet="' + i + '" min="1" max="24" value="' + (b.limit || '') + '"'
          + ' placeholder="oto"></div>';
      }
      if (a.kategori) {
        var kops = '<option value="0"' + (!b.category_id ? ' selected' : '') + '>Tüm kategoriler</option>'
          + (T.categories || []).map(function (c) {
            return '<option value="' + c.id + '"' + (Number(b.category_id) === c.id ? ' selected' : '') + '>'
              + M.esc(c.name) + (c.active ? '' : ' (pasif)') + '</option>';
          }).join('');
        alanlar += '<div class="te-w-secim"><label for="bc_' + i + '">Kategori</label>'
          + '<select id="bc_' + i + '" data-b-kat="' + i + '">' + kops + '</select></div>';
      }
      if (a.slot) {
        var sops = (T.slots || []).map(function (s) {
          return '<option value="' + M.esc(s.value) + '"' + (b.slot === s.value ? ' selected' : '') + '>'
            + M.esc(s.label) + '</option>';
        }).join('');
        alanlar += '<div class="te-w-secim"><label for="bs_' + i + '">Reklam slotu</label>'
          + '<select id="bs_' + i + '" data-b-slot="' + i + '">' + sops + '</select></div>';
      }

      return '<div class="te-blok' + (b.on ? '' : ' kapali') + '">'
        + '<div class="te-blok-sira">'
        + '<button type="button" data-b-yukari="' + i + '" title="Yukarı taşı" aria-label="Yukarı taşı"' + (i === 0 ? ' disabled' : '') + '>▲</button>'
        + '<button type="button" data-b-asagi="' + i + '" title="Aşağı taşı" aria-label="Aşağı taşı"' + (i === bloklar.length - 1 ? ' disabled' : '') + '>▼</button>'
        + '</div>'
        + '<div class="te-blok-orta">'
        + '<div class="te-blok-ad"><span>' + (i + 1) + '. ' + M.esc(blokEtiket(b.type)) + '</span>'
        + '<span class="rozet notr mini">' + M.esc(b.type) + '</span></div>'
        + (alanlar ? '<div class="te-blok-alanlar">' + alanlar + '</div>' : '')
        + '</div>'
        + '<div class="te-blok-sag">'
        + '<label class="anahtar" title="Aç / kapa">'
        + '<input type="checkbox" data-b-acik="' + i + '"' + (b.on ? ' checked' : '') + '>'
        + '<span class="kaydirak"></span></label>'
        + '<button type="button" class="dugme kucuk tehlike" data-b-sil="' + i + '"'
        + ' data-onay="Bu blok listeden kaldırılacak. Onaylıyor musunuz?">Kaldır</button>'
        + '</div>'
        + '</div>';
    }).join('');

    bloklariBagla();
  }

  function bloklariBagla() {
    M.qsa('[data-b-yukari]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = parseInt(b.getAttribute('data-b-yukari'), 10);
        if (i <= 0) { return; }
        var t = bloklar[i - 1]; bloklar[i - 1] = bloklar[i]; bloklar[i] = t;
        bloklariCiz();
      });
    });
    M.qsa('[data-b-asagi]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = parseInt(b.getAttribute('data-b-asagi'), 10);
        if (i >= bloklar.length - 1) { return; }
        var t = bloklar[i + 1]; bloklar[i + 1] = bloklar[i]; bloklar[i] = t;
        bloklariCiz();
      });
    });
    M.qsa('[data-b-sil]').forEach(function (b) {
      b.addEventListener('click', function () {
        var i = parseInt(b.getAttribute('data-b-sil'), 10);
        bloklar.splice(i, 1);
        bloklariCiz();
      });
    });
    M.qsa('[data-b-acik]').forEach(function (b) {
      b.addEventListener('change', function () {
        bloklar[parseInt(b.getAttribute('data-b-acik'), 10)].on = b.checked ? 1 : 0;
        bloklariCiz();
      });
    });
    M.qsa('[data-b-baslik]').forEach(function (b) {
      b.addEventListener('input', function () {
        bloklar[parseInt(b.getAttribute('data-b-baslik'), 10)].title = b.value;
      });
    });
    M.qsa('[data-b-adet]').forEach(function (b) {
      b.addEventListener('input', function () {
        bloklar[parseInt(b.getAttribute('data-b-adet'), 10)].limit = parseInt(b.value, 10) || 0;
      });
    });
    M.qsa('[data-b-kat]').forEach(function (b) {
      b.addEventListener('change', function () {
        bloklar[parseInt(b.getAttribute('data-b-kat'), 10)].category_id = parseInt(b.value, 10) || 0;
      });
    });
    M.qsa('[data-b-slot]').forEach(function (b) {
      b.addEventListener('change', function () {
        bloklar[parseInt(b.getAttribute('data-b-slot'), 10)].slot = b.value;
      });
    });
  }

  function blokEkleSecenekleri() {
    var s = el('blokEkleTip');
    if (!s) { return; }
    s.innerHTML = (T.blockTypes || []).map(function (t) {
      return '<option value="' + M.esc(t) + '">' + M.esc(blokEtiket(t)) + '</option>';
    }).join('');
  }

  function blokEkle() {
    var s = el('blokEkleTip');
    if (!s || !s.value) { return; }
    if (bloklar.length >= 20) { M.toast('warn', 'En çok 20 blok tanımlanabilir.'); return; }
    bloklar.push({
      type: s.value, on: 1, title: '', limit: 0,
      category_id: 0, slot: s.value === 'ad' ? 'in_article' : ''
    });
    bloklariCiz();
  }

  function bloklariKaydet() {
    M.api('theme.save_blocks', { blocks: bloklar }).then(function (r) {
      if (!M.sonuc(r, 'Blok dizilimi kaydedildi.')) { return; }
      (r.errors || []).forEach(function (e) { M.toast('warn', e); });
      bloklar = r.blocks || bloklar;
      bloklariCiz();
      onizlemeYukle();
    });
  }

  /* ==========================================================================
     5) Kaydetme / sıfırlama / tema etkinleştirme
     ========================================================================== */
  function kaydet() {
    var btn = el('temaKaydet');
    if (btn) { btn.disabled = true; }
    M.api('theme.save', { name: T.aktif, values: degerler }).then(function (r) {
      if (btn) { btn.disabled = false; }
      if (!M.sonuc(r, 'Tema ayarları kaydedildi.')) { return; }
      (r.errors || []).forEach(function (e) { M.toast('warn', e); });
      if (r.skipped && r.skipped.length) {
        M.toast('warn', 'Şemada olmayan ' + r.skipped.length + ' anahtar yok sayıldı.');
      }
      if (kirli) { kirli.temizle(); }
      onizlemeYukle();
    });
  }

  function siteKaydet() {
    var logo = el('siteLogo');
    var fav = el('siteFavicon');
    M.api('theme.save_site', {
      site_logo: logo ? logo.value : '',
      site_favicon: fav ? fav.value : ''
    }).then(function (r) {
      if (M.sonuc(r, 'Logo ve favicon kaydedildi.')) { onizlemeYukle(); }
    });
  }

  function temaSifirla() {
    M.api('theme.reset', { name: T.aktif }).then(function (r) {
      if (!M.sonuc(r, 'Tema ayarları sıfırlandı.')) { return; }
      degerler = kopya(r.values || {});
      // Şemadaki varsayılanları da tazele
      (T.schema || []).forEach(function (f) {
        if (degerler[f.key] === undefined) { degerler[f.key] = f.default; }
      });
      alanlariCiz();
      if (kirli) { kirli.temizle(); }
      onizlemeYukle();
    });
  }

  function bloklariSifirla() {
    M.api('theme.reset_blocks', {}).then(function (r) {
      if (!M.sonuc(r, 'Blok dizilimi sıfırlandı.')) { return; }
      bloklar = r.blocks || [];
      bloklariCiz();
      onizlemeYukle();
    });
  }

  function temaKullan(ad) {
    M.api('theme.activate', { name: ad }).then(function (r) {
      if (!M.sonuc(r, 'Tema etkinleştirildi.')) { return; }
      if (kirli) { kirli.temizle(); }
      window.location.reload();
    });
  }

  /* ==========================================================================
     6) Başlatma
     ========================================================================== */
  function baslat() {
    degerler = kopya(T.values || {});
    bloklar = (T.blocks || []).map(function (b) {
      return {
        type: String(b.type || ''), on: b.on ? 1 : 0, title: String(b.title || ''),
        limit: parseInt(b.limit, 10) || 0, category_id: parseInt(b.category_id, 10) || 0,
        slot: String(b.slot || '')
      };
    });

    try {
      var a = document.createElement('a');
      a.href = T.onizlemeUrl || '/';
      siteKok = a.origin || (a.protocol + '//' + a.host);
    } catch (e) { siteKok = window.location.origin; }

    cerceve = el('onizlemeCerceveIc');

    sekmeleriCiz();
    alanlariCiz();
    blokEkleSecenekleri();
    bloklariCiz();

    var form = el('temaForm');
    if (form) {
      kirli = M.kirliTakip(form);
      form.addEventListener('submit', function (e) { e.preventDefault(); kaydet(); });
    }

    if (cerceve) {
      cerceve.addEventListener('load', function () {
        // Aynı origin olduğu için iframe belgesine doğrudan erişilir
        onizlemeUygula();
      });
      onizlemeYukle();
    }

    M.qsa('[data-cihaz]').forEach(function (b) {
      b.addEventListener('click', function () { cihaz(b.getAttribute('data-cihaz')); });
    });

    var kaydetBtn = el('temaKaydet');
    if (kaydetBtn) { kaydetBtn.addEventListener('click', kaydet); }

    var yenileBtn = el('temaYenile');
    if (yenileBtn) { yenileBtn.addEventListener('click', onizlemeYukle); }

    var siteBtn = el('siteKaydet');
    if (siteBtn) { siteBtn.addEventListener('click', siteKaydet); }

    var sifirlaBtn = el('temaSifirla');
    if (sifirlaBtn) { sifirlaBtn.addEventListener('click', temaSifirla); }

    var blokSifirlaBtn = el('blokSifirla');
    if (blokSifirlaBtn) { blokSifirlaBtn.addEventListener('click', bloklariSifirla); }

    var blokEkleBtn = el('blokEkle');
    if (blokEkleBtn) { blokEkleBtn.addEventListener('click', blokEkle); }

    var blokKaydetBtn = el('blokKaydet');
    if (blokKaydetBtn) { blokKaydetBtn.addEventListener('click', bloklariKaydet); }

    M.qsa('.te-kullan').forEach(function (b) {
      b.addEventListener('click', function () { temaKullan(b.getAttribute('data-tema')); });
    });

    // Logo / favicon medya seçicileri
    M.qsa('[data-medya]').forEach(function (b) {
      b.addEventListener('click', function () {
        var hedef = el(b.getAttribute('data-medya'));
        var onz = el(b.getAttribute('data-medya') + 'Onizleme');
        M.medyaSec(function (o) {
          if (hedef) { hedef.value = o.filename; }
          if (onz) { onz.hidden = false; onz.innerHTML = '<img src="' + M.esc(o.url) + '" alt="">'; }
        });
      });
    });
    M.qsa('[data-medya-sil]').forEach(function (b) {
      b.addEventListener('click', function () {
        var hedef = el(b.getAttribute('data-medya-sil'));
        var onz = el(b.getAttribute('data-medya-sil') + 'Onizleme');
        if (hedef) { hedef.value = ''; }
        if (onz) { onz.hidden = true; onz.innerHTML = ''; }
      });
    });
  }

  /* -------------------------------------------------- dışa açılan arayüz */
  window.MansetTemaEditor = {
    baslat: baslat,
    cssUret: function () { return cssUret(degerler); },
    degerler: function () { return kopya(degerler); },
    bloklar: function () { return bloklar.slice(); },
    onizlemeUygula: onizlemeUygula,
    onizlemeYukle: onizlemeYukle,
    kaydet: kaydet,
    cihaz: cihaz
  };

  M.hazir(function () {
    if (document.getElementById('temaAlanlar')) { baslat(); }
  });
})();
