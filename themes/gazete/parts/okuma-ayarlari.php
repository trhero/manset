<?php
/**
 * Okuma ayarları kutusu (1.2-13): yazı ölçeği (3 kademe) + görünüm (sistem/açık/koyu).
 *
 * NEDEN yerel depolama: okur tercihi sunucuya GİTMEZ. Çerez verilseydi
 * (sözleşme §1) anonim ziyaretçi çerez almış olur, `Vary: Cookie` yüzünden
 * sayfa önbelleği ve `s-maxage: 60` fiilen ölürdü. Tercih `localStorage`'da
 * tutulur ve <head>'deki `okuma-bas` parçası ilk boyamadan önce uygular.
 *
 * NEDEN <details>/<fieldset>/<input type=radio>: hepsi yerel öge; klavye
 * gezinmesi (Tab, ok tuşları, Enter/Space, Escape) tarayıcıdan gelir, taklit
 * edilmiş bir açılır kutuda yeniden yazmak zorunda kalmayız.
 *
 * Beş temanın da single.php ve parts/footer.php dosyasından çağrılır; temada
 * kopyası yoksa part() gazete'ye düşer (CONTRACTS §5.1).
 *
 * @var string $yer  'haber' | 'alt' — aynı sayfada iki örnek olabildiği için
 *                   id/name çakışmasını engeller.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }

$yer = isset($yer) && $yer === 'alt' ? 'alt' : 'haber';
$on  = 'okuma-' . $yer;

// Sunucu okurun seçimini BİLMEZ (çerez yok). Bu yüzden gerçekte işaretli olan
// düğme sunucuda değil aşağıdaki betikte belirlenir; buradaki `checked`
// yalnız JavaScript kapalı tarayıcıda makul bir başlangıç göstersin diye var.
$olcekler = ['kucuk' => 'Küçük', 'orta' => 'Orta', 'buyuk' => 'Büyük'];
$temalar  = ['sistem' => 'Sistem', 'acik' => 'Açık', 'koyu' => 'Koyu'];
?>
<div class="okuma-ayar" data-okuma-ayar>
  <details class="okuma-ayar-kutu" id="<?= esc($on) ?>-kutu">
    <summary class="okuma-ayar-ac" title="Okuma ayarları">
      <span aria-hidden="true">Aa</span>
      <span class="gizli">Okuma ayarları</span>
    </summary>
    <div class="okuma-ayar-panel" role="group" aria-label="Okuma ayarları">

      <fieldset class="okuma-ayar-grup">
        <legend>Yazı boyutu</legend>
        <div class="okuma-ayar-secenek">
          <?php foreach ($olcekler as $deger => $etiket): ?>
            <label>
              <input type="radio" name="<?= esc($on) ?>-olcek" value="<?= esc($deger) ?>"
                     data-olcek-sec<?= $deger === 'orta' ? ' checked' : '' ?>>
              <span><?= esc($etiket) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <fieldset class="okuma-ayar-grup">
        <legend>Görünüm</legend>
        <div class="okuma-ayar-secenek">
          <?php foreach ($temalar as $deger => $etiket): ?>
            <label>
              <input type="radio" name="<?= esc($on) ?>-tema" value="<?= esc($deger) ?>"
                     data-tema-sec<?= $deger === 'sistem' ? ' checked' : '' ?>>
              <span><?= esc($etiket) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <p class="okuma-ayar-not">Tercihiniz yalnız bu tarayıcıda saklanır; sunucuya gönderilmez.</p>
    </div>
  </details>
</div>
<?php
/* Davranış betiği sayfada YALNIZ BİR KEZ basılır: kutu hem haber meta satırında
   hem alt bilgide olabiliyor, dinleyici ise belge düzeyinde tek. */
if (empty($GLOBALS['manset_okuma_betik'])):
    $GLOBALS['manset_okuma_betik'] = true;
?>
<script>
(function () {
  'use strict';
  var kok = document.documentElement;
  var OLCEK = { kucuk: 1, orta: 1, buyuk: 1 };   // geçerli değer kümesi
  var TEMA  = { sistem: 1, acik: 1, koyu: 1 };

  function yaz(anahtar, deger) {
    // Gizli sekmede / depolama kapalıyken localStorage atar; tercih o oturum
    // için uygulanır ama saklanmaz. Sayfanın çalışması buna bağlı olmamalı.
    try { window.localStorage.setItem(anahtar, deger); } catch (e) {}
  }
  function oku(anahtar) {
    try { return window.localStorage.getItem(anahtar); } catch (e) { return null; }
  }

  function isaretle(sec, deger) {
    var liste = document.querySelectorAll('[' + sec + ']');
    for (var i = 0; i < liste.length; i++) { liste[i].checked = (liste[i].value === deger); }
  }

  var sistemKoyu = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  /* Seçim (data-tema) ile ETKİN görünümü (data-goruntu) ayırıyoruz: stil dosyası
     yalnız data-goruntu'ya bakar, "sistem" burada çözülür. Bkz. parts/okuma-bas.php */
  function goruntuyuUygula(secim) {
    var etkin = secim === 'sistem' ? ((sistemKoyu && sistemKoyu.matches) ? 'koyu' : 'acik') : secim;
    kok.setAttribute('data-goruntu', etkin);
  }

  // İşletim sistemi teması çalışırken değişirse "sistem" seçili okur da uysun.
  if (sistemKoyu) {
    var sistemDinle = function () {
      if ((kok.getAttribute('data-tema') || 'sistem') === 'sistem') { goruntuyuUygula('sistem'); }
    };
    if (sistemKoyu.addEventListener) { sistemKoyu.addEventListener('change', sistemDinle); }
    else if (sistemKoyu.addListener) { sistemKoyu.addListener(sistemDinle); }   // eski Safari
  }

  var temaSimdi  = kok.getAttribute('data-tema')  || 'sistem';
  var olcekSimdi = kok.getAttribute('data-olcek') || 'orta';
  if (!TEMA[temaSimdi])   { temaSimdi  = 'sistem'; }
  if (!OLCEK[olcekSimdi]) { olcekSimdi = 'orta'; }

  document.addEventListener('change', function (e) {
    var g = e.target;
    if (!g || g.tagName !== 'INPUT') { return; }
    if (g.hasAttribute('data-tema-sec') && TEMA[g.value]) {
      kok.setAttribute('data-tema', g.value);
      goruntuyuUygula(g.value);
      yaz('manset:tema', g.value);
      isaretle('data-tema-sec', g.value);
    } else if (g.hasAttribute('data-olcek-sec') && OLCEK[g.value]) {
      kok.setAttribute('data-olcek', g.value);
      yaz('manset:olcek', g.value);
      isaretle('data-olcek-sec', g.value);
    }
  });

  // Escape ile kapat + odağı açan düğmeye geri ver (klavye tuzağı olmasın).
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.keyCode !== 27) { return; }
    var acik = document.querySelectorAll('.okuma-ayar-kutu[open]');
    for (var i = 0; i < acik.length; i++) {
      if (!acik[i].contains(document.activeElement)) { continue; }
      acik[i].removeAttribute('open');
      var ac = acik[i].querySelector('summary');
      if (ac) { ac.focus(); }
    }
  });

  // Panel dışına tıklanınca kapansın (fare kullanıcısı için; klavyeyi etkilemez).
  document.addEventListener('click', function (e) {
    var acik = document.querySelectorAll('.okuma-ayar-kutu[open]');
    for (var i = 0; i < acik.length; i++) {
      if (!acik[i].contains(e.target)) { acik[i].removeAttribute('open'); }
    }
  });

  // Depolama başka sekmede değişirse bu sekme de uysun.
  window.addEventListener('storage', function (e) {
    if (e.key === 'manset:tema' && TEMA[e.newValue]) {
      kok.setAttribute('data-tema', e.newValue); goruntuyuUygula(e.newValue);
      isaretle('data-tema-sec', e.newValue);
    }
    if (e.key === 'manset:olcek' && OLCEK[e.newValue]) {
      kok.setAttribute('data-olcek', e.newValue); isaretle('data-olcek-sec', e.newValue);
    }
  });

  /* Açılış eşitlemesi DOM HAZIR OLUNCA yapılır. NEDEN: bu betik haber meta
     satırında, sayfanın ortasında çalışıyor; alt bilgideki İKİNCİ kutu henüz
     ayrıştırılmamış oluyor. Hemen çalıştırılınca yalnız ilk kutu işaretleniyor
     ve alt bilgideki kutu "Sistem/Orta" gösteriyordu (ölçüldü). */
  function baslat() {
    // Depolama ile <html> ayrışmışsa (önbellekten gelen HTML) depolama doğrudur.
    var t = oku('manset:tema'), o = oku('manset:olcek');
    if (t && TEMA[t] && t !== temaSimdi) { temaSimdi = t; kok.setAttribute('data-tema', t); goruntuyuUygula(t); }
    if (o && OLCEK[o] && o !== olcekSimdi) { olcekSimdi = o; kok.setAttribute('data-olcek', o); }
    isaretle('data-tema-sec', temaSimdi);
    isaretle('data-olcek-sec', olcekSimdi);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', baslat);
  } else {
    baslat();
  }
})();
</script>
<?php endif; ?>
