/**
 * Manşet — Gazete teması ön yüz betiği.
 * Dış kütüphane YOK. assets/site.js'e ek olarak yalnız temaya özgü davranışlar:
 *   son dakika kayan şeridi · mobil menü · canlı arama önerisi ·
 *   paylaş/panoya kopyala · bülten kaydı
 */
(function () {
  'use strict';

  var API = (function () {
    var s = document.currentScript ? document.currentScript.src : '';
    return s ? s.replace(/\/themes\/[^/]+\/theme\.js.*$/, '/api.php') : 'api.php';
  })();

  function qs(sel, kok) { return (kok || document).querySelector(sel); }
  function qsa(sel, kok) { return Array.prototype.slice.call((kok || document).querySelectorAll(sel)); }

  function post(action, data, csrf) {
    var h = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
    if (csrf) { h['X-CSRF'] = csrf; }
    return fetch(API + '?a=' + encodeURIComponent(action), {
      method: 'POST',
      headers: h,
      body: JSON.stringify(data || {}),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Sunucu yanıtı okunamadı.' }; });
    });
  }

  /* ------------------------------------------------------------ mobil menü */
  (function () {
    var dugme = qs('[data-menu-ac]');
    var menu = document.getElementById('anaMenu');
    if (!dugme || !menu) { return; }
    dugme.addEventListener('click', function () {
      var acik = menu.classList.toggle('acik');
      dugme.setAttribute('aria-expanded', acik ? 'true' : 'false');
      dugme.setAttribute('aria-label', acik ? 'Menüyü kapat' : 'Menüyü aç');
    });
  })();

  /* ------------------------------------------- son dakika kayan şeridi */
  (function () {
    var kutu = qs('[data-serit]');
    if (!kutu) { return; }
    var ray = qs('.serit-ray', kutu);
    if (!ray || !ray.children.length) { return; }

    // Kesintisiz döngü için liste bir kez çoğaltılır
    var kopya = ray.cloneNode(true);
    kopya.setAttribute('aria-hidden', 'true');
    kopya.classList.add('serit-kopya');
    ray.parentNode.appendChild(kopya);
    kutu.classList.add('serit-hazir');

    var durdur = qs('[data-serit-durdur]', kutu);
    if (durdur) {
      durdur.addEventListener('click', function () {
        var durdu = kutu.classList.toggle('duraklat');
        durdur.setAttribute('aria-label', durdu ? 'Şeridi başlat' : 'Şeridi duraklat');
        durdur.textContent = durdu ? '▶' : '❚❚';
      });
    }
    kutu.addEventListener('mouseenter', function () { kutu.classList.add('uzerinde'); });
    kutu.addEventListener('mouseleave', function () { kutu.classList.remove('uzerinde'); });
  })();

  /* ------------------------------------------------- canlı arama önerisi */
  qsa('form[data-oneri]').forEach(function (form) {
    var girdi = qs('input[type=search]', form);
    var kutu = qs('.arama-oneri', form);
    if (!girdi || !kutu) { return; }

    var zaman = null;
    var secili = -1;
    var sonSorgu = '';

    function kapat() {
      kutu.hidden = true;
      kutu.innerHTML = '';
      secili = -1;
      girdi.setAttribute('aria-expanded', 'false');
    }

    function isaretle() {
      qsa('li', kutu).forEach(function (li, i) {
        if (i === secili) { li.classList.add('secili'); li.setAttribute('aria-selected', 'true'); }
        else { li.classList.remove('secili'); li.setAttribute('aria-selected', 'false'); }
      });
    }

    function bas(items) {
      kutu.innerHTML = '';
      if (!items || !items.length) {
        var bos = document.createElement('li');
        bos.className = 'oneri-bos';
        bos.textContent = 'Sonuç bulunamadı.';
        kutu.appendChild(bos);
        kutu.hidden = false;
        girdi.setAttribute('aria-expanded', 'true');
        return;
      }
      items.forEach(function (it) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', 'false');
        var a = document.createElement('a');
        a.href = it.url;
        a.textContent = it.title;
        li.appendChild(a);
        kutu.appendChild(li);
      });
      kutu.hidden = false;
      girdi.setAttribute('aria-expanded', 'true');
      secili = -1;
    }

    function sor() {
      var q = girdi.value.trim();
      if (q.length < 2) { kapat(); return; }
      if (q === sonSorgu) { return; }
      sonSorgu = q;
      post('public.search_suggest', { q: q }).then(function (r) {
        if (girdi.value.trim() !== q) { return; }
        if (r && r.ok) { bas(r.items || []); } else { kapat(); }
      }).catch(kapat);
    }

    girdi.addEventListener('input', function () {
      if (zaman) { clearTimeout(zaman); }
      zaman = setTimeout(sor, 220);
    });

    girdi.addEventListener('keydown', function (e) {
      var say = kutu.hidden ? 0 : qsa('li a', kutu).length;
      if (e.key === 'Escape') { kapat(); return; }
      if (!say) { return; }
      if (e.key === 'ArrowDown') { e.preventDefault(); secili = (secili + 1) % say; isaretle(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); secili = (secili - 1 + say) % say; isaretle(); }
      else if (e.key === 'Enter' && secili >= 0) {
        var bag = qsa('li a', kutu)[secili];
        if (bag) { e.preventDefault(); window.location.href = bag.href; }
      }
    });

    document.addEventListener('click', function (e) {
      if (!form.contains(e.target)) { kapat(); }
    });
  });

  /* --------------------------------------------------- paylaş / kopyala */
  qsa('[data-kopyala]').forEach(function (dugme) {
    dugme.addEventListener('click', function () {
      var adres = dugme.getAttribute('data-kopyala') || window.location.href;
      var kutu = dugme.parentNode ? dugme.parentNode.querySelector('.paylas-sonuc') : null;

      function bildir(metin) {
        if (kutu) {
          kutu.textContent = metin;
          setTimeout(function () { kutu.textContent = ''; }, 2500);
        }
      }

      function yedek() {
        try {
          var alan = document.createElement('textarea');
          alan.value = adres;
          alan.setAttribute('readonly', 'readonly');
          alan.style.position = 'absolute';
          alan.style.left = '-9999px';
          document.body.appendChild(alan);
          alan.select();
          document.execCommand('copy');
          document.body.removeChild(alan);
          bildir('Bağlantı kopyalandı.');
        } catch (err) {
          bildir('Kopyalanamadı, bağlantıyı elle kopyalayın.');
        }
      }

      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(adres).then(function () {
          bildir('Bağlantı kopyalandı.');
        }).catch(yedek);
      } else {
        yedek();
      }
    });
  });

  /* ------------------------------------------------------- bülten kaydı */
  qsa('form[data-bulten]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var kutu = qs('.form-sonuc', form);
      var dugme = qs('button[type=submit]', form);
      var eposta = qs('input[name=email]', form);
      var onay = qs('input[name=kvkk]', form);
      var tuzak = qs('input[name=website]', form);
      var csrfAlan = qs('input[name=_csrf]', form);

      function bildir(ok, metin) {
        if (!kutu) { return; }
        kutu.hidden = false;
        kutu.className = 'form-sonuc ' + (ok ? 'ok' : 'err');
        kutu.textContent = metin;
      }

      if (!eposta || !eposta.value.trim()) { bildir(false, 'E-posta adresinizi girin.'); return; }
      if (onay && !onay.checked) { bildir(false, 'Devam etmek için onay kutusunu işaretleyin.'); return; }

      var veri = {
        email: eposta.value.trim(),
        kvkk: onay && onay.checked ? 1 : 0,
        website: tuzak ? tuzak.value : '',
        _csrf: csrfAlan ? csrfAlan.value : ''
      };
      if (dugme) { dugme.disabled = true; }

      post('newsletter.subscribe', veri, veri._csrf).then(function (r) {
        if (r && r.ok) {
          bildir(true, r.message || 'Kaydınız alındı. Teşekkürler.');
          form.reset();
        } else {
          // Uç nokta henüz yayında değilse (404) kullanıcıya "yakında" denir
          bildir(false, (r && r.error && r.error.indexOf('Bilinmeyen uç') === -1)
            ? r.error
            : 'Bülten kaydı yakında etkinleşecek.');
        }
        if (dugme) { dugme.disabled = false; }
      }).catch(function () {
        bildir(false, 'Bülten kaydı şu anda yapılamıyor. Daha sonra tekrar deneyin.');
        if (dugme) { dugme.disabled = false; }
      });
    });
  });
})();
