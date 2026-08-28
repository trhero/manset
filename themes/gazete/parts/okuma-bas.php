<?php
/**
 * Okuma tercihi ön yükleyicisi (1.2-13) — <head> içinde, ilk boyamadan ÖNCE.
 *
 * NEDEN inline ve neden burada:
 *  - Tercih ÇEREZDE DEĞİL `localStorage`'da (sözleşme §1 / CONTRACTS §3.1).
 *    Sunucu okurun seçimini bilmez; HTML tek varyant kalır, sayfa önbelleği
 *    ve `s-maxage` bozulmaz. Bunun bedeli: seçimi istemci uygulamak zorunda.
 *  - `defer` ile gelen harici bir betik gövde boyandıktan SONRA çalışırdı;
 *    koyu modu seçmiş okur her sayfada beyaz bir çakma (FOUC) görürdü.
 *    Bu yüzden betik küçük, satır içi ve senkron.
 *
 * İKİ AYRI ÖZNİTELİK, NEDEN:
 *  - `data-tema`    = okurun SEÇİMİ (sistem | acik | koyu). Kutudaki işaretli
 *                     düğme buna bakar.
 *  - `data-goruntu` = o an ETKİN görünüm (acik | koyu). "sistem" seçiliyken
 *                     `prefers-color-scheme` burada çözülür.
 *  - `data-okuma="1"` = bu betik çalıştı. Kutu ancak bu işaret varken görünür;
 *    JavaScript kapalıyken seçim uygulanamayacağı için hiçbir işe yaramayan bir
 *    denetim göstermek okuru yanıltırdı.
 *    Stil dosyası tek bir seçici ailesiyle (`html[data-goruntu=koyu]`)
 *    yazılabiliyor; aksi hâlde her koyu kural bir de @media içinde tekrar
 *    edilmek zorunda kalırdı ve iki kopya kaçınılmaz olarak birbirinden
 *    ayrılırdı. JavaScript kapalıysa yalnız RENK DEĞİŞKENLERİ için
 *    @media yedeği devrede kalır (style.css sonundaki blok).
 *
 * Bu parça beş temanın da layout.php dosyasından çağrılır; temada kopyası
 * yoksa part() gazete'ye düşer, yani tema seçimi bu davranışı düşürmez.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
?>
<script>/* okuma tercihi — localStorage, çerez yok */
(function(){try{var d=document.documentElement,s=window.localStorage,g={sistem:1,acik:1,koyu:1};
var t=s.getItem('manset:tema');if(!g[t]){t=d.getAttribute('data-tema')||'sistem';}
d.setAttribute('data-tema',t);
d.setAttribute('data-goruntu',t==='sistem'?((window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'koyu':'acik'):t);
var o=s.getItem('manset:olcek');if(o==='kucuk'||o==='orta'||o==='buyuk'){d.setAttribute('data-olcek',o);}
d.setAttribute('data-okuma','1');
}catch(e){}})();</script>
