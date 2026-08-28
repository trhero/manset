/**
 * Manşet — sürüm geçmişi / otomatik kayıt / düzenleme kilidi arayüzü.
 * (Ajan-F · 1.3-01, 1.3-02)
 *
 * `admin/pages/posts.php` editör kipinde MansetRevisions.baglat({…}) ile bağlanır.
 * Dış kütüphane yok; yalnız window.M (assets/admin.js) yardımcıları kullanılır.
 *
 * TASARIM NOTU — OTOMATİK KAYIT NEYE YAZAR
 * Otomatik kayıt `revisions.autosave` ucuna gider ve o uç `posts` tablosuna
 * HİÇ dokunmaz; içerik yalnız sürüm geçmişine düşer. Yani bu dosyadaki hiçbir
 * zamanlayıcı yayımlanmış bir haberi değiştiremez. Editör "Kaydet" demedikçe
 * canlı metin aynı kalır; yazdıkları ise kaybolmaz — sayfayı yeniden açtığında
 * "kurtarılabilir otomatik kayıt" uyarısıyla geri alabilir.
 */
(function (global) {
  'use strict';

  function el(tag, cls, metin) {
    var d = document.createElement(tag);
    if (cls) { d.className = cls; }
    if (metin !== undefined) { d.textContent = metin; }
    return d;
  }

  function saatBicim(s) {
    if (!s) { return ''; }
    return String(s).replace('T', ' ').slice(0, 16);
  }

  var TUR_ETIKET = {
    manual: 'kayıt', auto: 'otomatik', restore: 'geri yükleme öncesi',
    publish: 'yayın öncesi', bulk: 'toplu işlem öncesi'
  };

  function baglat(ayar) {
    var postId = parseInt(ayar.postId, 10) || 0;
    var meId = parseInt(ayar.userId, 10) || 0;
    if (postId <= 0) { return null; }

    var kilitKutu = ayar.kilitEl;
    var gecmisKutu = ayar.gecmisEl;
    var notKutu = ayar.notEl;
    var aralik = (parseInt(ayar.interval, 10) || 30) * 1000;

    var kilitBende = false;
    var sonSahip = 0;
    var kirli = false;
    var gonderiliyor = false;
    var zamanlayici = null;
    var durumEl = ayar.durumEl || null;

    function durum(metin, ton) {
      if (!durumEl) { return; }
      durumEl.textContent = metin;
      durumEl.className = 'kucuk ' + (ton === 'err' ? 'uyari-metin' : 'soluk');
    }

    /* ------------------------------------------------------------ kilit */

    function kilitCiz(kilit) {
      if (!kilitKutu) { return; }
      kilitKutu.innerHTML = '';
      sonSahip = parseInt(kilit.locked_by, 10) || 0;
      kilitBende = !!kilit.mine;

      if (kilitBende || sonSahip === 0 || kilit.expired) {
        kilitKutu.hidden = true;
        kilitEtkisi(true);
        return;
      }

      kilitKutu.hidden = false;
      kilitKutu.className = 'uyari warn';
      var ad = kilit.locked_name || ('kullanıcı #' + sonSahip);
      var p = el('div');
      p.appendChild(el('strong', null, 'Bu haberi şu anda ' + ad + ' düzenliyor.'));
      p.appendChild(document.createTextNode(
        ' Son işareti: ' + saatBicim(kilit.locked_at) + '. Kaydederseniz onun yazdıklarının '
        + 'üzerine yazarsınız; bu yüzden kaydetme kapalı. Kilit ' + Math.round((kilit.ttl || 600) / 60)
        + ' dakika işaretsiz kalırsa kendiliğinden düşer.'));
      kilitKutu.appendChild(p);

      var dugme = el('button', 'dugme kucuk', 'Kilidi devral');
      dugme.type = 'button';
      dugme.addEventListener('click', function () {
        if (!M.onay(ad + ' bu haberi düzenliyor olabilir. Kilidi devralırsanız kaydettiğinde '
          + 'uyarı alacak. Devralınsın mı?')) { return; }
        dugme.disabled = true;
        M.api('revisions.lock', { post_id: postId, takeover: 1, seen_by: sonSahip }).then(function (r) {
          dugme.disabled = false;
          if (!r || !r.ok) { M.toast('err', (r && r.error) || 'Kilit alınamadı.'); return; }
          kilitCiz(r.lock);
          if (r.acquired) { M.toast('ok', 'Kilit sizde. Artık kaydedebilirsiniz.'); }
          else { M.toast('warn', 'Kilidi başkası devraldı.'); }
        });
      });
      kilitKutu.appendChild(dugme);
      kilitEtkisi(false);
    }

    /** Kaydet düğmelerini kilide göre aç/kapat. */
    function kilitEtkisi(acik) {
      M.qsa('button[type=submit], button[form=haberForm]').forEach(function (b) {
        b.disabled = !acik;
        b.title = acik ? '' : 'Haberi başka bir editör düzenliyor.';
      });
    }

    function kilitAl() {
      return M.api('revisions.lock', { post_id: postId }).then(function (r) {
        if (r && r.ok) { kilitCiz(r.lock); }
        return r;
      });
    }

    function kilitBirak() {
      // `keepalive`: sayfa kapanırken de isteğin gitmesini sağlar. Kilidin
      // zaman aşımı zaten var; bu yalnız "hemen serbest bırak" kolaylığıdır.
      try {
        fetch((window.MANSET && MANSET.api) + '?a=revisions.unlock', {
          method: 'POST', keepalive: true, credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-CSRF': (window.MANSET && MANSET.csrf) || '' },
          body: JSON.stringify({ post_id: postId })
        });
      } catch (e) { }
    }

    /* ------------------------------------------------------------ otomatik kayıt */

    function otomatikKaydet() {
      if (!kirli || gonderiliyor) { return; }
      var v = ayar.oku();
      v.post_id = postId;
      gonderiliyor = true;
      durum('Otomatik kaydediliyor…');
      M.api('revisions.autosave', v).then(function (r) {
        gonderiliyor = false;
        if (!r || !r.ok) { durum((r && r.error) || 'Otomatik kayıt yapılamadı', 'err'); return; }
        kirli = false;
        durum(r.saved
          ? 'Otomatik kayıt alındı · ' + saatBicim(r.at) + ' (canlı metin değişmedi)'
          : 'Değişiklik yok — otomatik kayıt gerekmedi');
        if (r.lock) { kilitCiz(r.lock); }
      });
    }

    function isaretle() {
      kirli = true;
      if (zamanlayici) { return; }
      zamanlayici = setInterval(otomatikKaydet, aralik);
    }

    /* ------------------------------------------------------------ geçmiş paneli */

    function gecmisCiz(veri) {
      if (!gecmisKutu) { return; }
      gecmisKutu.innerHTML = '';

      // Kurtarılabilir otomatik kayıt uyarısı
      if (veri.recoverable) {
        var u = el('div', 'uyari bilgi mini');
        u.appendChild(document.createTextNode(
          'Bu haberde kaydedilmemiş bir otomatik kayıt var ('
          + saatBicim(veri.recoverable.created_at) + '). Canlı metne uygulanmadı.'));
        var g = el('button', 'dugme kucuk', 'Önizle');
        g.type = 'button';
        g.style.marginLeft = '8px';
        g.addEventListener('click', function () { onizle(veri.recoverable.id); });
        u.appendChild(g);
        gecmisKutu.appendChild(u);
      }

      if (!veri.items || !veri.items.length) {
        gecmisKutu.appendChild(el('p', 'soluk kucuk', 'Henüz sürüm yok. İlk kayıttan sonra burada listelenir.'));
        return;
      }

      var liste = el('div', 'surum-liste');
      veri.items.forEach(function (r) {
        var satir = el('div', 'surum-satir');
        satir.style.cssText = 'display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px solid var(--cizgi,#eee)';

        var sol = el('div');
        sol.style.flex = '1';
        sol.appendChild(el('div', 'kucuk', saatBicim(r.created_at) + ' · ' + (TUR_ETIKET[r.kind] || r.kind)));
        sol.appendChild(el('div', 'mini soluk',
          (r.user_name || 'bilinmiyor') + (r.note ? ' · ' + r.note : '')));
        satir.appendChild(sol);

        var onizleD = el('button', 'dugme kucuk', 'Önizle');
        onizleD.type = 'button';
        onizleD.addEventListener('click', function () { onizle(r.id); });
        satir.appendChild(onizleD);

        if (ayar.geriYukleyebilir) {
          var geriD = el('button', 'dugme kucuk', 'Geri yükle');
          geriD.type = 'button';
          geriD.addEventListener('click', function () { geriYukle(r.id, r.created_at); });
          satir.appendChild(geriD);
        }
        liste.appendChild(satir);
      });
      gecmisKutu.appendChild(liste);
      gecmisKutu.appendChild(el('p', 'form-yardim',
        'En son ' + (veri.keep || 20) + ' sürüm saklanır; eskiler kendiliğinden budanır.'));
    }

    function onizle(id) {
      M.api('revisions.get', { id: id }).then(function (r) {
        if (!r || !r.ok) { M.toast('err', (r && r.error) || 'Sürüm okunamadı.'); return; }
        var s = r.revision;
        var govde = M.modal.ac('Sürüm önizlemesi · ' + saatBicim(s.created_at), '', '720px');
        govde.innerHTML = '';
        govde.appendChild(el('div', 'kucuk soluk',
          'Durum: ' + s.status + ' · Adres: /' + s.slug + ' · Tür: ' + (TUR_ETIKET[s.kind] || s.kind)));
        govde.appendChild(el('h3', null, s.title));
        if (s.spot) { govde.appendChild(el('p', 'soluk', s.spot)); }
        var kutu = el('div', 'surum-govde');
        kutu.style.cssText = 'border:1px solid var(--cizgi,#ddd);border-radius:8px;padding:10px;max-height:46vh;overflow:auto';
        // Sunucu gövdeyi sanitize_html() ile kaydediyor; yine de önizleme
        // METİN olarak değil HTML olarak gösterilmeli ki editör gerçekten
        // ne geri yükleyeceğini görsün.
        kutu.innerHTML = s.body || '';
        govde.appendChild(kutu);
        if (ayar.geriYukleyebilir) {
          var d = el('button', 'dugme birincil', 'Bu sürümü geri yükle');
          d.type = 'button';
          d.style.marginTop = '10px';
          d.addEventListener('click', function () { M.modal.kapat(); geriYukle(s.id, s.created_at); });
          govde.appendChild(d);
        }
      });
    }

    function geriYukle(id, ne) {
      if (!M.onay(saatBicim(ne) + ' tarihli sürüm geri yüklenecek. Şu anki metin de bir sürüm '
        + 'olarak saklanacağı için bu işlem geri alınabilir. Devam edilsin mi?')) { return; }
      M.api('revisions.restore', { id: id }).then(function (r) {
        if (!M.sonuc(r, 'Sürüm geri yüklendi.')) { return; }
        setTimeout(function () { location.reload(); }, 600);
      });
    }

    /* ------------------------------------------------------------ notlar */

    function notlarCiz(veri) {
      if (!notKutu) { return; }
      notKutu.innerHTML = '';

      if (veri.revision_request) {
        var uy = el('div', 'uyari warn mini');
        uy.appendChild(el('strong', null, 'Revizyon istendi. '));
        uy.appendChild(document.createTextNode(
          (veri.revision_request.user_name || '') + ' · ' + saatBicim(veri.revision_request.created_at)
          + ' — ' + veri.revision_request.body));
        notKutu.appendChild(uy);
      }
      if (veri.published_by) {
        notKutu.appendChild(el('p', 'mini soluk', 'Yayımlayan: ' + veri.published_by));
      }

      var liste = el('div');
      (veri.notes || []).forEach(function (n) {
        var s = el('div', 'not-satir');
        s.style.cssText = 'padding:6px 0;border-bottom:1px solid var(--cizgi,#eee)';
        var etiket = n.kind === 'revision_request' ? ' · revizyon isteği'
                   : (n.kind === 'approve' ? ' · onay' : '');
        s.appendChild(el('div', 'mini soluk',
          (n.user_name || 'bilinmiyor') + ' · ' + saatBicim(n.created_at) + etiket));
        s.appendChild(el('div', 'kucuk', n.body));
        liste.appendChild(s);
      });
      if (!(veri.notes || []).length) {
        liste.appendChild(el('p', 'soluk kucuk', 'Henüz not yok.'));
      }
      notKutu.appendChild(liste);

      var alan = el('textarea');
      alan.rows = 2;
      alan.placeholder = 'Muhabire / editöre not…';
      alan.setAttribute('aria-label', 'Habere not ekle');
      notKutu.appendChild(alan);

      var grup = el('div', 'dugme-grup bosluk-ust');
      var ekle = el('button', 'dugme kucuk', 'Not ekle');
      ekle.type = 'button';
      ekle.addEventListener('click', function () {
        var m = alan.value.trim();
        if (!m) { M.toast('warn', 'Not boş olamaz.'); return; }
        ekle.disabled = true;
        M.api('revisions.note', { post_id: postId, body: m }).then(function (r) {
          ekle.disabled = false;
          if (!M.sonuc(r, 'Not eklendi.')) { return; }
          alan.value = '';
          tazele();
        });
      });
      grup.appendChild(ekle);

      if (ayar.yayinlayabilir) {
        var revizyon = el('button', 'dugme kucuk', 'Revizyon iste');
        revizyon.type = 'button';
        revizyon.title = 'Haberi taslağa döndürüp muhabire gerekçeyle geri gönderir.';
        revizyon.addEventListener('click', function () {
          var m = alan.value.trim();
          if (!m) { M.toast('warn', 'Revizyon isterken gerekçe yazmalısınız.'); alan.focus(); return; }
          if (!M.onay('Haber taslağa döndürülüp muhabire geri gönderilecek. Onaylıyor musunuz?')) { return; }
          revizyon.disabled = true;
          M.api('revisions.request_changes', { post_id: postId, body: m }).then(function (r) {
            revizyon.disabled = false;
            if (!M.sonuc(r, 'Haber geri gönderildi.')) { return; }
            setTimeout(function () { location.reload(); }, 600);
          });
        });
        grup.appendChild(revizyon);

        var onay = el('button', 'dugme kucuk', 'Onayla ve yayımla');
        onay.type = 'button';
        onay.title = 'Metne dokunmadan yayına alır; yayımlayan olarak siz kaydedilirsiniz.';
        onay.addEventListener('click', function () {
          if (!M.onay('Haber yayına alınacak ve yayımlayan olarak siz kaydedileceksiniz. Onaylıyor musunuz?')) { return; }
          onay.disabled = true;
          M.api('revisions.approve', { post_id: postId, note: alan.value.trim() }).then(function (r) {
            onay.disabled = false;
            if (!M.sonuc(r, 'Haber yayımlandı.')) { return; }
            setTimeout(function () { location.reload(); }, 600);
          });
        });
        grup.appendChild(onay);
      }
      notKutu.appendChild(grup);
    }

    /* ------------------------------------------------------------ tazeleme */

    function tazele() {
      return M.api('revisions.list', { post_id: postId }).then(function (r) {
        if (!r || !r.ok) { return r; }
        if (r.interval) { aralik = r.interval * 1000; }
        gecmisCiz(r);
        notlarCiz(r);
        kilitCiz(r.lock);
        return r;
      });
    }

    /* ------------------------------------------------------------ açılış */

    kilitAl().then(tazele);
    window.addEventListener('beforeunload', function () {
      if (kilitBende) { kilitBirak(); }
    });

    return {
      isaretle: isaretle,
      tazele: tazele,
      kilitBende: function () { return kilitBende; },
      /** Elle kayıttan HEMEN ÖNCE çağrılır: önceki hâl sürüm olarak saklanır. */
      kayitOncesi: function () {
        return M.api('revisions.snapshot', { post_id: postId, note: 'kaydetmeden önce' });
      },
      kayitSonrasi: function () { kirli = false; durum(''); tazele(); }
    };
  }

  global.MansetRevisions = { baglat: baglat };
}(window));
