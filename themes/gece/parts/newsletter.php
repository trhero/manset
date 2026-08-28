<?php
/**
 * Gece teması — e-bülten kayıt kutusu.
 * Uç nokta: newsletter.subscribe (Ajan-9). Uç kurulmamışsa Türkçe hata gösterir.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
$bultenBaslik = isset($baslik) && $baslik !== '' ? $baslik : 'Gündemi kaçırmayın';
?>
<section class="kutu bulten-kutu">
  <h2 class="kutu-baslik"><?= esc($bultenBaslik) ?></h2>
  <p class="soluk kucuk">Günün öne çıkan başlıklarını e-postanıza gönderelim. İstediğiniz an çıkabilirsiniz.</p>
  <form class="bulten-form" data-bulten="1">
    <div class="form-sonuc" role="status" hidden></div>
    <label class="gizli" for="bultenEposta">E-posta adresiniz</label>
    <div class="satir-inline">
      <input type="email" id="bultenEposta" name="email" required maxlength="120" placeholder="ornek@eposta.com">
      <button type="submit">Abone ol</button>
    </div>
    <label class="onay"><input type="checkbox" name="kvkk" required>
      E-posta adresimin bülten gönderimi için işlenmesini onaylıyorum.</label>
    <div class="tuzak" aria-hidden="true"><label>Boş bırakın<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <?= csrf_field_lazy() ?>
  </form>
</section>
<?php if (empty($GLOBALS['manset_bulten_js'])): $GLOBALS['manset_bulten_js'] = true; ?>
<script>
(function () {
  'use strict';
  var api = <?= json_encode(base_url() . '/api.php') ?>;
  Array.prototype.forEach.call(document.querySelectorAll('form[data-bulten]'), function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var kutu = f.querySelector('.form-sonuc');
      var btn = f.querySelector('button[type=submit]');
      var veri = {};
      Array.prototype.forEach.call(f.elements, function (el) {
        if (!el.name) { return; }
        veri[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
      });
      if (btn) { btn.disabled = true; }
      fetch(api + '?a=newsletter.subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF': veri._csrf || '', 'Accept': 'application/json' },
        body: JSON.stringify(veri),
        credentials: 'same-origin'
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Sunucu yanıtı okunamadı.' }; });
      }).then(function (r) {
        if (kutu) {
          kutu.hidden = false;
          kutu.className = 'form-sonuc ' + (r.ok ? 'ok' : 'err');
          kutu.textContent = r.ok ? (r.message || 'Kaydınız alındı.') : (r.error || 'Kayıt tamamlanamadı.');
        }
        if (r.ok) { f.reset(); }
        if (btn) { btn.disabled = false; }
      }).catch(function () {
        if (kutu) { kutu.hidden = false; kutu.className = 'form-sonuc err'; kutu.textContent = 'Bağlantı kurulamadı.'; }
        if (btn) { btn.disabled = false; }
      });
    });
  });
})();
</script>
<?php endif; ?>
