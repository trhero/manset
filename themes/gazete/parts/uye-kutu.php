<?php
/**
 * Gazete teması — kenar çubuğu üye kutusu.
 * Giriş yapmışsa kısa hesap özeti, yapmamışsa üye ol / giriş yap çağrısı.
 * member_current() oturum AÇMAZ (current_user_if_session üzerinden çalışır).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
if (!function_exists('member_current')) { return; }

$uyeKutu = member_current();
?>
<section class="kutu uye-kutu">
  <?php if ($uyeKutu): ?>
    <h2 class="kutu-baslik">Merhaba</h2>
    <p class="uye-ad"><strong><?= esc(member_display_name($uyeKutu)) ?></strong></p>
    <?php $ab = member_subscription((int)$uyeKutu['id']); ?>
    <p class="mini">
      <?php if (arr($ab, 'tier') === 'premium'): ?>
        <span class="rozet-premium">ABONE</span>
      <?php else: ?>
        <span class="rozet-uye">ÜCRETSİZ ÜYE</span>
      <?php endif; ?>
    </p>
    <ul class="uye-baglar">
      <li><a href="<?= esc(member_url('profil')) ?>">Hesabım</a></li>
      <li><a href="<?= esc(member_url('kaydedilenler')) ?>">Kaydettiklerim</a></li>
      <li><a href="<?= esc(member_url('cikis')) ?>">Çıkış</a></li>
    </ul>
  <?php else: ?>
    <h2 class="kutu-baslik">Üyelik</h2>
    <p class="mini soluk">Üye olun; haberleri kaydedin, adınızla yorum yazın.</p>
    <p class="uye-eylem">
      <a class="dugme" href="<?= esc(member_url('kayit')) ?>">Üye ol</a>
      <a class="dugme ghost" href="<?= esc(member_url('giris')) ?>">Giriş yap</a>
    </p>
  <?php endif; ?>
</section>

<style>
.uye-kutu .uye-ad{margin:0 0 4px}
.uye-kutu .uye-baglar{list-style:none;margin:10px 0 0;padding:0}
.uye-kutu .uye-baglar li{padding:5px 0;border-bottom:1px solid var(--renk-cizgi,#e3e7ec);font-size:14px}
.uye-kutu .uye-baglar li:last-child{border-bottom:0}
.uye-kutu .uye-eylem{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}
.uye-kutu .dugme{background:var(--renk-ana,#c0392b);color:#fff;border:0;padding:9px 16px;border-radius:var(--kose,6px);font-size:14px;font-weight:600}
.uye-kutu .dugme.ghost{background:transparent;color:var(--renk-metin,#1c1f23);border:1px solid var(--renk-cizgi,#e3e7ec)}
.uye-kutu .dugme:hover{text-decoration:none}
</style>
