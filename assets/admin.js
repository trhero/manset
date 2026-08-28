/* ============================================================================
   Manşet — yönetim paneli ortak betiği.
   Dış kütüphane yok. Tüm panel sayfaları window.M üzerinden bu yardımcıları kullanır.
   ============================================================================ */
(function () {
  'use strict';

  var CFG = window.MANSET || { api: 'api.php', csrf: '', base: '', uploads: '' };

  var M = {
    cfg: CFG,

    qs: function (sel, kok) { return (kok || document).querySelector(sel); },
    qsa: function (sel, kok) { return Array.prototype.slice.call((kok || document).querySelectorAll(sel)); },

    /** HTML kaçışı (istemci tarafı). */
    esc: function (s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },

    /**
     * JSON API çağrısı.
     * @returns {Promise<Object>} { ok: bool, ... }
     */
    api: function (action, data, opts) {
      opts = opts || {};
      var url = CFG.api + '?a=' + encodeURIComponent(action);
      var init = {
        method: opts.method || 'POST',
        headers: { 'X-CSRF': CFG.csrf, 'Accept': 'application/json' },
        credentials: 'same-origin'
      };
      if (opts.formData) {
        init.body = opts.formData;
      } else if (init.method === 'GET') {
        if (data) { url += '&' + M.qs_build(data); }
      } else {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(data || {});
      }
      return fetch(url, init).then(function (r) {
        return r.json().catch(function () {
          return { ok: false, error: 'Sunucu yanıtı okunamadı (HTTP ' + r.status + ').' };
        });
      }).catch(function () {
        return { ok: false, error: 'Bağlantı kurulamadı.' };
      });
    },

    qs_build: function (obj) {
      return Object.keys(obj).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]);
      }).join('&');
    },

    /** Sağ üstte kısa bildirim. */
    toast: function (tip, mesaj, sure) {
      var kap = M.qs('#toastKap');
      if (!kap) {
        kap = document.createElement('div');
        kap.id = 'toastKap';
        kap.style.cssText = 'position:fixed;right:16px;top:66px;z-index:80;display:flex;flex-direction:column;gap:8px;max-width:360px';
        document.body.appendChild(kap);
      }
      var el = document.createElement('div');
      el.className = 'uyari ' + (tip === true ? 'ok' : (tip === false ? 'err' : tip));
      el.setAttribute('role', 'status');
      el.style.cssText = 'margin:0;box-shadow:0 4px 14px rgba(16,24,40,.14)';
      el.textContent = mesaj;
      kap.appendChild(el);
      setTimeout(function () {
        el.style.transition = 'opacity .3s';
        el.style.opacity = '0';
        setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, 320);
      }, sure || 3800);
      return el;
    },

    /** API sonucunu doğrudan bildirime çevirir. */
    sonuc: function (r, basariMesaji) {
      if (r && r.ok) { M.toast('ok', r.message || basariMesaji || 'İşlem tamamlandı.'); return true; }
      M.toast('err', (r && r.error) || 'İşlem başarısız.');
      return false;
    },

    onay: function (mesaj) { return window.confirm(mesaj); },

    /** Basit modal. M.modal.ac(baslikHtml, govdeHtml) */
    modal: {
      el: null,
      ac: function (baslik, govde, genislik) {
        var m = M.modal.el;
        if (!m) {
          m = document.createElement('div');
          m.className = 'modal';
          m.innerHTML = '<div class="modal-icerik"><div class="modal-ust"><h2></h2>'
            + '<button type="button" class="modal-kapat" aria-label="Kapat">&times;</button></div>'
            + '<div class="modal-govde"></div></div>';
          document.body.appendChild(m);
          m.addEventListener('click', function (e) { if (e.target === m) { M.modal.kapat(); } });
          m.querySelector('.modal-kapat').addEventListener('click', M.modal.kapat);
          M.modal.el = m;
        }
        m.querySelector('.modal-ust h2').textContent = baslik;
        m.querySelector('.modal-govde').innerHTML = govde;
        if (genislik) { m.querySelector('.modal-icerik').style.maxWidth = genislik; }
        m.hidden = false;
        return m.querySelector('.modal-govde');
      },
      kapat: function () { if (M.modal.el) { M.modal.el.hidden = true; } }
    },

    /**
     * Medya kütüphanesi seçici.
     * @param {function} secildi  ({id, filename, url, alt}) ile çağrılır
     */
    medyaSec: function (secildi) {
      var govde = M.modal.ac('Medya kütüphanesi',
        '<div class="esnek-arasi bosluk-alt">'
        + '<input type="search" id="medyaAra" placeholder="Dosya adı veya alt metin…" style="max-width:280px">'
        + '<label class="dugme">Yükle<input type="file" id="medyaYukle" accept="image/*" hidden multiple></label>'
        + '</div><div class="medya-izgara" id="medyaListe"><p class="soluk">Yükleniyor…</p></div>');

      function ciz(ogeler) {
        var liste = M.qs('#medyaListe', govde);
        if (!ogeler.length) {
          liste.innerHTML = '<p class="soluk">Kütüphanede görsel yok. Yukarıdaki “Yükle” düğmesiyle ekleyin.</p>';
          return;
        }
        liste.innerHTML = ogeler.map(function (o) {
          return '<div class="medya-oge" data-id="' + o.id + '" data-file="' + M.esc(o.filename) + '" data-alt="' + M.esc(o.alt || '') + '">'
            + '<img src="' + M.esc(o.thumb || o.url) + '" alt="' + M.esc(o.alt || o.filename) + '" loading="lazy">'
            + '<div class="ad">' + M.esc(o.filename) + '</div></div>';
        }).join('');
        M.qsa('.medya-oge', liste).forEach(function (el) {
          el.addEventListener('click', function () {
            secildi({
              id: parseInt(el.getAttribute('data-id'), 10),
              filename: el.getAttribute('data-file'),
              alt: el.getAttribute('data-alt'),
              url: CFG.uploads + el.getAttribute('data-file')
            });
            M.modal.kapat();
          });
        });
      }

      function yenile(q) {
        M.api('media.list', { q: q || '', limit: 60 }).then(function (r) {
          if (!r.ok) { M.qs('#medyaListe', govde).innerHTML = '<p class="uyari err">' + M.esc(r.error || 'Liste alınamadı.') + '</p>'; return; }
          ciz(r.items || []);
        });
      }
      yenile('');

      var ara = M.qs('#medyaAra', govde), zaman;
      ara.addEventListener('input', function () {
        clearTimeout(zaman);
        zaman = setTimeout(function () { yenile(ara.value); }, 280);
      });

      var yukle = M.qs('#medyaYukle', govde);
      yukle.addEventListener('change', function () {
        if (!yukle.files.length) { return; }
        var fd = new FormData();
        Array.prototype.forEach.call(yukle.files, function (f) { fd.append('dosya[]', f); });
        M.toast('bilgi', 'Yükleniyor…', 1500);
        M.api('media.upload', null, { formData: fd }).then(function (r) {
          if (M.sonuc(r, 'Yüklendi.')) { yenile(ara.value); }
        });
      });
    },

    /** Form alanlarını düz nesneye çevirir. */
    formVerisi: function (form) {
      var out = {};
      M.qsa('input,select,textarea', form).forEach(function (el) {
        if (!el.name || el.disabled) { return; }
        if (el.type === 'checkbox') { out[el.name] = el.checked ? 1 : 0; }
        else if (el.type === 'radio') { if (el.checked) { out[el.name] = el.value; } }
        else { out[el.name] = el.value; }
      });
      return out;
    },

    /** Kaydedilmemiş değişiklik uyarısı. */
    kirliTakip: function (form) {
      var kirli = false;
      form.addEventListener('input', function () { kirli = true; });
      form.addEventListener('change', function () { kirli = true; });
      window.addEventListener('beforeunload', function (e) {
        if (!kirli) { return; }
        e.preventDefault();
        e.returnValue = '';
      });
      return { temizle: function () { kirli = false; } };
    },

    /** '2026-08-22 09:30:00' → datetime-local değeri */
    dtLocal: function (s) { return s ? String(s).replace(' ', 'T').slice(0, 16) : ''; },
    dtSql: function (s) { return s ? String(s).replace('T', ' ') + (s.length === 16 ? ':00' : '') : ''; }
  };

  window.M = M;

  /* -------------------------------------------------- mobil menü
     Bu dosya <head> içinde defer'siz yüklenir (sayfa içi betikler M'yi hazır bulsun),
     bu yüzden DOM'a dokunan bağlamalar DOMContentLoaded'a ertelenir. */
  function domHazir(fn) {
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); }
    else { fn(); }
  }
  M.hazir = domHazir;

  domHazir(function () {
    var menuDugme = document.getElementById('menuAc');
    var ortu = document.getElementById('ortu');
    if (menuDugme) {
      menuDugme.addEventListener('click', function () {
        document.body.classList.toggle('menu-acik');
        if (ortu) { ortu.hidden = !document.body.classList.contains('menu-acik'); }
      });
    }
    if (ortu) {
      ortu.addEventListener('click', function () {
        document.body.classList.remove('menu-acik');
        ortu.hidden = true;
      });
    }
  });

  /* -------------------------------------------------- Esc ile modal kapat */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && M.modal.el && !M.modal.el.hidden) { M.modal.kapat(); }
  });

  /* -------------------------------------------------- data-onay ile silme koruması */
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('[data-onay]') : null;
    if (!el) { return; }
    if (!window.confirm(el.getAttribute('data-onay'))) { e.preventDefault(); e.stopPropagation(); }
  }, true);
})();
