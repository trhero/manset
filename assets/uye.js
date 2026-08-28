/* ============================================================================
   Manşet — üye formları (ön yüz).
   window.M panelde tanımlıdır; ön yüzde yok. Bu yüzden kendi küçük yardımcımızı
   kullanıyoruz. CSRF anahtarı assets/site.js tarafından tembel doldurulur
   (input[data-csrf]); burada yalnız değeri okuruz.
   ============================================================================ */
(function () {
  'use strict';

  var API = (function () {
    var s = document.currentScript ? document.currentScript.src : '';
    return s ? s.replace(/\/assets\/uye\.js.*$/, '/api.php') : 'api.php';
  })();

  /** Formdaki alanları düz nesneye çevirir. */
  function formVerisi(form) {
    var out = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name) { return; }
      if (el.type === 'checkbox') { out[el.name] = el.checked ? 1 : 0; }
      else if (el.type === 'radio') { if (el.checked) { out[el.name] = el.value; } }
      else { out[el.name] = el.value; }
    });
    return out;
  }

  function sonucGoster(form, ok, mesaj) {
    var kutu = form.querySelector('.form-sonuc');
    if (!kutu) {
      kutu = document.createElement('div');
      kutu.className = 'form-sonuc';
      form.insertBefore(kutu, form.firstChild);
    }
    kutu.hidden = false;
    kutu.className = 'form-sonuc ' + (ok ? 'ok' : 'err');
    kutu.textContent = mesaj;
    kutu.setAttribute('tabindex', '-1');
    kutu.focus({ preventScroll: false });
  }

  /** Girişten sonra nereye dönülecek? */
  function hedefAdres(form, yanit) {
    if (yanit && yanit.redirect) { return yanit.redirect; }
    var geri = form.getAttribute('data-geri');
    var taban = window.location.pathname;
    if (geri) { return taban + '?s=' + encodeURIComponent(geri); }
    return taban + '?s=profil';
  }

  Array.prototype.forEach.call(document.querySelectorAll('form[data-uye]'), function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();

      // CSRF alanı henüz doldurulmadıysa site.js'in submit yakalayıcısı devreye
      // girer ve formu yeniden gönderir; burada bir şey yapmıyoruz.
      var csrfAlan = form.querySelector('input[data-csrf]');
      if (csrfAlan && !csrfAlan.value) { return; }

      var uc = form.getAttribute('data-uye');
      var veri = formVerisi(form);
      var dugme = form.querySelector('button[type=submit]');
      if (dugme) { dugme.disabled = true; }

      fetch(API + '?a=' + encodeURIComponent(uc), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF': veri._csrf || ''
        },
        body: JSON.stringify(veri),
        credentials: 'same-origin'
      }).then(function (r) {
        return r.json().catch(function () {
          return { ok: false, error: 'Sunucu yanıtı okunamadı.' };
        });
      }).then(function (yanit) {
        if (dugme) { dugme.disabled = false; }

        if (!yanit.ok) {
          sonucGoster(form, false, yanit.error || 'İşlem tamamlanamadı.');
          return;
        }

        sonucGoster(form, true, yanit.message || 'İşlem tamamlandı.');

        // Giriş ve parola sıfırlama sonrası yönlendir
        if (uc === 'members.login' || uc === 'members.reset_apply') {
          setTimeout(function () { window.location.href = hedefAdres(form, yanit); }, 600);
          return;
        }
        // Kayıt sonrası formu temizle (doğrulama beklenebilir)
        if (uc === 'members.register') { form.reset(); return; }
        // Parola değiştirme sonrası alanları boşalt
        if (uc === 'members.password') {
          Array.prototype.forEach.call(form.querySelectorAll('input[type=password]'), function (i) { i.value = ''; });
        }
      }).catch(function () {
        if (dugme) { dugme.disabled = false; }
        sonucGoster(form, false, 'Bağlantı kurulamadı. Lütfen tekrar deneyin.');
      });
    });
  });

  /* CSRF anahtarını çözer: sayfada site.js'in doldurduğu bir alan varsa onu
     kullanır, yoksa public.csrf ucundan ister. Kaydetme düğmesi bir formun
     içinde olmadığı için okuyacağı hazır alan olmayabilir. */
  function csrfAl() {
    var alan = document.querySelector('input[data-csrf]');
    if (alan && alan.value) { return Promise.resolve(alan.value); }
    return fetch(API + '?a=public.csrf', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (y) { return (y && y.csrf) ? y.csrf : ''; })
      .catch(function () { return ''; });
  }

  /* -------------------------------------------------- haber kaydetme düğmesi */
  Array.prototype.forEach.call(document.querySelectorAll('[data-kaydet]'), function (dugme) {
    dugme.addEventListener('click', function () {
      var id = parseInt(dugme.getAttribute('data-kaydet'), 10) || 0;
      if (!id) { return; }
      dugme.disabled = true;
      csrfAl().then(function (anahtar) {
      return fetch(API + '?a=members.bookmark', {
        method: 'POST',
        // CSRF başlığı ZORUNLU: yazma ucudur, başlıksız istek 403 döner.
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF': anahtar
        },
        body: JSON.stringify({ post_id: id, _csrf: anahtar }),
        credentials: 'same-origin'
      }); }).then(function (r) { return r.json(); }).then(function (y) {
        dugme.disabled = false;
        if (!y.ok) {
          // Giriş gerekiyorsa üye sayfasına gönder
          if (y.login_required) { window.location.href = dugme.getAttribute('data-giris') || '/hesap.php?s=giris'; return; }
          return;
        }
        dugme.classList.toggle('kayitli', !!y.saved);
        dugme.setAttribute('aria-pressed', y.saved ? 'true' : 'false');
        dugme.title = y.saved ? 'Kaydedilenlerden çıkar' : 'Haberi kaydet';
      }).catch(function () { dugme.disabled = false; });
    });
  });
})();
