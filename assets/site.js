/* Manşet — ön yüz betiği. Dış kütüphane yok, yalnız vanilla JS + fetch. */
(function () {
  'use strict';

  var API = (function () {
    var s = document.currentScript ? document.currentScript.src : '';
    // .../assets/site.js → .../api.php
    return s ? s.replace(/\/assets\/site\.js.*$/, '/api.php') : 'api.php';
  })();

  /** Basit POST yardımcısı: CSRF başlığı ekler, JSON döndürür. */
  function post(action, data, csrf) {
    return fetch(API + '?a=' + encodeURIComponent(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf || '', 'Accept': 'application/json' },
      body: JSON.stringify(data),
      credentials: 'same-origin'
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Sunucu yanıtı okunamadı.' }; });
    });
  }

  /** Form altındaki sonuç kutusuna mesaj basar. */
  function sonuc(form, ok, mesaj) {
    var box = form.querySelector('.form-sonuc');
    if (!box) {
      box = document.createElement('div');
      box.className = 'form-sonuc';
      form.insertBefore(box, form.firstChild);
    }
    box.hidden = false;
    box.className = 'form-sonuc ' + (ok ? 'ok' : 'err');
    box.textContent = mesaj;
  }

  /** Form alanlarını düz nesneye çevirir. */
  function formData(form) {
    var out = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) { return; }
      if (el.type === 'checkbox') { out[el.name] = el.checked ? 1 : 0; }
      else { out[el.name] = el.value; }
    });
    return out;
  }

  /* ------------------------------------------------------------------
     Tembel CSRF
     Ön yüz formları anahtarı boş basar (csrf_field_lazy). Böylece sayfa HTML'i
     oturum açmadan üretilir ve önbelleğe alınabilir. Anahtar, kullanıcı forma
     dokunduğunda ya da göndermeye çalıştığında bu uçtan alınır.
     ------------------------------------------------------------------ */
  var csrfSozu = null;

  function csrfAl() {
    if (csrfSozu) { return csrfSozu; }
    csrfSozu = fetch(API + '?a=public.csrf', {
      method: 'GET',
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); })
      .then(function (r) { return (r && r.ok && r.csrf) ? r.csrf : ''; })
      .catch(function () { csrfSozu = null; return ''; });
    return csrfSozu;
  }

  /** Formdaki boş CSRF alanlarını doldurur. */
  function csrfDoldur(kok) {
    var alanlar = Array.prototype.slice.call((kok || document).querySelectorAll('input[data-csrf]'))
      .filter(function (el) { return !el.value; });
    if (!alanlar.length) { return Promise.resolve(true); }
    return csrfAl().then(function (t) {
      if (!t) { return false; }
      alanlar.forEach(function (el) { el.value = t; });
      return true;
    });
  }

  // Kullanıcı forma dokunur dokunmaz anahtarı çek — gönderimde gecikme olmasın.
  document.addEventListener('focusin', function (e) {
    var form = e.target && e.target.form;
    if (form && form.querySelector('input[data-csrf]')) { csrfDoldur(form); }
  }, true);

  // Yedek: anahtar hâlâ boşken gönderim denenirse gönderimi bekletip tamamla.
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.querySelector) { return; }
    var alan = form.querySelector('input[data-csrf]');
    if (!alan || alan.value) { return; }
    e.preventDefault();
    e.stopImmediatePropagation();
    csrfDoldur(form).then(function () {
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    });
  }, true);

  /** Yorum formu. */
  var yorumForm = document.getElementById('yorumForm');
  if (yorumForm) {
    yorumForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = formData(yorumForm);
      d.post_id = parseInt(yorumForm.getAttribute('data-post'), 10) || 0;
      var btn = yorumForm.querySelector('button[type=submit]');
      if (btn) { btn.disabled = true; }
      post('public.comment', d, d._csrf).then(function (r) {
        sonuc(yorumForm, !!r.ok, r.ok ? (r.message || 'Yorumunuz alındı, editör onayından sonra yayımlanacak.') : (r.error || 'Gönderilemedi.'));
        if (r.ok) { yorumForm.reset(); }
        if (btn) { btn.disabled = false; }
      }).catch(function () {
        sonuc(yorumForm, false, 'Bağlantı hatası. Lütfen tekrar deneyin.');
        if (btn) { btn.disabled = false; }
      });
    });
  }

  /** Düzeltme talebi formu. */
  Array.prototype.forEach.call(document.querySelectorAll('.duzeltme-form'), function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var d = formData(form);
      d.post_id = parseInt(form.getAttribute('data-post'), 10) || 0;
      var btn = form.querySelector('button[type=submit]');
      if (btn) { btn.disabled = true; }
      post('public.correction', d, d._csrf).then(function (r) {
        sonuc(form, !!r.ok, r.ok ? (r.message || 'Talebiniz iletildi.') : (r.error || 'Gönderilemedi.'));
        if (r.ok) { form.reset(); }
        if (btn) { btn.disabled = false; }
      }).catch(function () {
        sonuc(form, false, 'Bağlantı hatası. Lütfen tekrar deneyin.');
        if (btn) { btn.disabled = false; }
      });
    });
  });

  /* ------------------------------------------------------------------
     Görüntülenme sayacı
     Sayfa HTML'i oturumsuz üretilebilsin (ve önbelleğe alınabilsin) diye sayaç
     sunucuda değil burada tetiklenir. Haber kimliği kanonik adresten okunur;
     bu yüzden hiçbir temanın işaretleme eklemesi gerekmez.
     ------------------------------------------------------------------ */
  (function sayacGonder() {
    var kaynak = '';
    var kanonik = document.querySelector('link[rel="canonical"]');
    if (kanonik && kanonik.href) { kaynak = kanonik.href; }
    if (!kaynak) { kaynak = location.href; }

    // /haber/{slug}-{id}  ya da  index.php?r=haber/{slug}-{id}
    var m = kaynak.match(/\/haber\/[^/?#]*?-(\d+)(?:[/?#]|$)/) ||
            kaynak.match(/[?&]r=haber(?:%2F|\/)[^&#]*?-(\d+)(?:[&#]|$)/);
    if (!m) { return; }
    var id = parseInt(m[1], 10);
    if (!id) { return; }

    var gonder = function () {
      fetch(API + '?a=public.view', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        // document.referrer, okurun siteye NEREDEN geldigini tasiyan tek kaynak:
        // sunucu bu istegin Referer basliginda haberin KENDI adresini gorur
        // (ayni koken), yani bilgi baska hicbir yerden gelemez. Sunucu ham
        // adresi SAKLAMAZ, yalniz turunu (arama/sosyal/dogrudan/ic/diger).
        body: JSON.stringify({ id: id, ref: document.referrer || '' }),
        credentials: 'same-origin',
        keepalive: true
      }).catch(function () { /* sayaç kritik değil, sessiz geç */ });
    };
    // Sayfa yükünü geciktirmemek için boşta çalıştır
    if (window.requestIdleCallback) { window.requestIdleCallback(gonder, { timeout: 2500 }); }
    else { setTimeout(gonder, 800); }
  })();

  /** Çerez bildirimi çubuğu (localStorage ile bir kez gösterilir). */
  var bar = document.getElementById('cookieBar');
  if (bar) {
    var KEY = 'manset_cookie_ok';
    var kabul = false;
    try { kabul = window.localStorage.getItem(KEY) === '1'; } catch (err) { kabul = false; }
    if (!kabul) { bar.hidden = false; }
    var ok = document.getElementById('cookieOk');
    if (ok) {
      ok.addEventListener('click', function () {
        try { window.localStorage.setItem(KEY, '1'); } catch (err) { /* yok say */ }
        bar.hidden = true;
      });
    }
  }
})();
