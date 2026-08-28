/**
 * Manşet — Yerel teması ön yüz betiği.
 * Dış kütüphane YOK. assets/site.js'e ek olarak yalnız temaya özgü davranışlar:
 *   mobil menü · canlı arama önerisi · paylaş / panoya kopyala
 */
(function () {
  'use strict';

  var API = (function () {
    var s = document.currentScript ? document.currentScript.src : '';
    return s ? s.replace(/\/themes\/[^/]+\/theme\.js.*$/, '/api.php') : 'api.php';
  })();

  function qs(sel, kok) { return (kok || document).querySelector(sel); }
  function qsa(sel, kok) { return Array.prototype.slice.call((kok || document).querySelectorAll(sel)); }

  function post(action, data) {
    return fetch(API + '?a=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
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
})();
