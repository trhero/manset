<?php
/**
 * Manşet — JSON Feed kısayolu.
 *
 * `/feed.php` → `rss.php?tur=json`. Aynı kapsam parametrelerini kabul eder
 * (`kategori`, `etiket`, `yazar`, `adet`).
 *
 * NEDEN AYRI DOSYA: JSON Feed istemcileri beslemeyi genellikle uzantısız ya da
 * `.json` uzantılı sabit bir adreste arar. Rewrite kuralı `.htaccess` dosyasında
 * ve o dosya bu ajanın değil; tek dosyalık bu kısayol paylaşımlı hostingde
 * rewrite olmadan da çalışır. `/feed.json` yönlendirmesi için orkestratöre
 * ayrıca istek bırakıldı (bkz. gelistirme/1.2-ajan-e.md).
 */

$_GET['tur'] = 'json';
require __DIR__ . '/rss.php';
