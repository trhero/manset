# Manşet

**Yapay zekâ destekli, açık kaynak haber portalı.**
PHP 8 · framework yok · Composer yok · npm yok · paylaşımlı hostingde FTP ile çalışır.

Manşet, küçük ve orta ölçekli haber siteleri için tasarlandı: RSS kaynaklarından haber
toplar, isteğe bağlı olarak yapay zekâ ile özgün cümlelerle yeniden yazar, editör onayından
geçirir ve beş farklı temayla yayımlar. Künye ve düzeltme-cevap gibi Türkiye'ye özgü
yayıncılık gereksinimleri en baştan düşünülmüştür.

---

## İçindekiler

- [Öne çıkanlar](#öne-çıkanlar)
- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
- [Zamanlanmış görev (cron)](#zamanlanmış-görev-cron)
- [Yapay zekâ kurulumu ve maliyet](#yapay-zekâ-kurulumu-ve-maliyet)
- [Temalar](#temalar)
- [Tema geliştirme rehberi](#tema-geliştirme-rehberi)
- [Roller ve üyelik](#roller-ve-üyelik)
- [Künye ve BİK notu](#künye-ve-bik-notu)
- [Güvenlik](#güvenlik)
- [Yedekleme](#yedekleme)
- [Geliştirme ve testler](#geliştirme-ve-testler)
- [Sık sorulan sorular](#sık-sorulan-sorular)
- [Yol haritası](#yol-haritası)
- [Lisans](#lisans)

---

## Öne çıkanlar

| Alan | Neler var |
|---|---|
| **İçerik** | Elle yazılmış zengin metin editörü, medya kütüphanesi (otomatik boyutlandırma), kategoriler, sabit sayfalar, etiketler, zamanlanmış yayın |
| **Vitrin** | 12 haberlik sıralanabilir manşet, son dakika şeridi, editörün seçimi, 5 reklam alanı (tarih aralıklı) |
| **Otomasyon** | RSS/Atom çekimi, mükerrer engeli, havuz ekranı, yapay zekâ iş kuyruğu |
| **Yapay zekâ** | Sağlayıcı seçmeli adaptör (Anthropic Messages API veya OpenAI uyumlu herhangi bir uç), panelden düzenlenebilir istem şablonları, jeton ve maliyet görünürlüğü |
| **Görünüm** | 5 hazır tema, canlı önizlemeli tema editörü, anasayfa blok dizilimi, özel CSS |
| **SEO** | SEF adresler, OpenGraph/Twitter kartları, `NewsArticle` JSON-LD, sayfalı site haritası + Google News haritası, RSS çıktısı, slug değişiminde 301 |
| **Yayıncılık** | Künye sayfası, yayın ilkeleri, düzeltme-cevap modülü, çerez bildirimi, yapay zekâ üretimi rozeti |
| **Performans** | Dosya tabanlı sayfa önbelleği (ETag + 304), WebP dönüşümü, `srcset`, lazy load |
| **Bakım** | Veritabanı yedeği ve geri yükleme, medya varyant onarımı, sistem tanılama, hata günlüğü görüntüleyici |
| **Roller** | 10 kullanıcı tipi, düzenlenebilir izin matrisi (kilitli çekirdekle), kullanıcıya özel ek izinler, denetim kaydı |
| **Üyelik** | Kayıt, e-posta doğrulama, profil, kaydedilen haberler; abonelere özel içerik kilidi |
| **Güvenlik** | Yalnız prepared statement, CSRF, hız sınırı, beyaz listeli HTML temizleyici, SSRF kalkanı, yükleme sıkılaştırması |

---

## Gereksinimler

**Zorunlu**
- PHP **8.0** veya üzeri
- PDO + `pdo_sqlite` **veya** `pdo_mysql`
- `SimpleXML` (RSS çekimi)
- `db/`, `uploads/` ve kök klasörde yazma izni

**Önerilen**
- `mbstring` — kapalıysa Manşet kendi yedek fonksiyonlarını kullanır
- `GD` — kapalıysa görsel boyutlandırma yapılmaz, orijinal görsel kullanılır
- `cURL` veya `allow_url_fopen` — RSS ve yapay zekâ çağrıları için
- `mod_rewrite` — temiz adresler için (yoksa `?r=` yedeği devreye girer)

Manşet **Composer, npm veya derleme adımı gerektirmez.** Dosyaları FTP ile yükleyip
tarayıcıdan kuruluma girmeniz yeterlidir.

---

## Kurulum

1. Tüm dosyaları sunucunuza yükleyin (örn. `public_html/`).
2. Tarayıcıdan `https://siteniz.com/` adresini açın — otomatik olarak kuruluma yönlendirilirsiniz.
3. **Adım 1 — Gereksinimler:** Kırmızı (✕) işaretli zorunlu maddeler giderilmeden devam edilemez.
   Sarı (!) işaretli maddeler önerilerdir.
4. **Adım 2 — Bilgiler:** Site adı, yönetici hesabı, veritabanı (SQLite önerilir) ve tema seçimi.
5. **Adım 3 — Kurulum:** Kurulum tamamlanır, `install/.locked` yazılır.
   **`install/` klasörünü sunucudan silmeniz önerilir.**

Kurulum `config.php` dosyasını kökte oluşturur. Bu dosya veritabanı bilgilerini,
`cron_key` ve `app_key` değerlerini içerir; **sürüm kontrolüne eklemeyin, yedekleyin.**

### MySQL ile kurulum
Veritabanını ve kullanıcıyı hosting panelinizden **önceden oluşturun** (karakter seti
`utf8mb4` önerilir), sonra kurulum sihirbazında MySQL'i seçip bilgileri girin.

### Alt klasöre kurulum
`https://siteniz.com/haber/` gibi bir alt klasöre kurarsanız `.htaccess` içindeki
`RewriteBase` satırını açıp kendi yolunuzu yazın:
```apache
RewriteBase /haber/
```

### Temiz adresler çalışmıyorsa
Manşet bunu kendiliğinden algılar ve adresleri `?r=haber/ornek-1` biçiminde üretir —
site tam çalışır, yalnız adresler daha az okunaklı olur. Sunucunuzda `mod_rewrite`'ı
etkinleştirdikten sonra **Panel → Ayarlar → Temiz adres sondası → Yeniden sına**
düğmesini kullanın.

---

## Zamanlanmış görev (cron)

RSS çekimi, yapay zekâ kuyruğu, zamanlanmış yayın ve önbellek bakımı `cron.php` ile yürür.
Hosting panelinizin "Cron Jobs" bölümüne **10 dakikada bir** çalışacak şekilde ekleyin:

```
*/10 * * * * curl -s "https://siteniz.com/cron.php?key=CRON_ANAHTARINIZ"
```

Komut satırından:
```
php /home/kullanici/public_html/cron.php --key=CRON_ANAHTARINIZ
```

Anahtarı **Panel → Ayarlar** ekranında hazır komut olarak bulabilirsiniz; kaynağı
`config.php` içindeki `cron_key` değeridir. Cron kurulmazsa site çalışır ama RSS
ve yapay zekâ otomasyonu ilerlemez (panelde uyarı görürsünüz).

---

## Yapay zekâ kurulumu ve maliyet

Yapay zekâ **isteğe bağlıdır**. Anahtar girilmezse ilgili düğmeler "yapılandırılmadı"
uyarısı verir; **sistemin geri kalanı tam çalışır.**

**Panel → Yapay Zekâ** ekranından:

| Sağlayıcı | Açıklama |
|---|---|
| `anthropic` | Anthropic Messages API (`https://api.anthropic.com/v1/messages`) |
| `openai_uyumlu` | `/chat/completions` uyumlu **herhangi bir** uç: OpenAI, yerli sağlayıcılar, kendi sunucunuzdaki bir model. `base_url` + `model` + anahtar girersiniz. |

API anahtarı veritabanında saklanır ve panelde **yalnız maskeli** gösterilir
(`sk-a…4f2b`). Anahtarı değiştirmek istemiyorsanız alanı boş bırakmanız yeterlidir.

### Maliyet
Her RSS ögesinin yeniden yazımı, kaynak metnin uzunluğuna göre kabaca **1.500–3.000
jeton** tüketir. Gerçek maliyet seçtiğiniz sağlayıcı ve modele bağlıdır.

Maliyeti kontrol altında tutmak için:
- RSS kaynaklarında **"YZ ile yeniden yaz"** seçeneğini yalnız gerçekten gereken kaynaklarda açın.
- **Otomatik yayın**ı kapalı bırakıp havuzdan seçtiğiniz haberleri tek tek yeniden yazdırın.
- Aynı anda çalışacak iş sayısı varsayılan **2**'dir (paylaşımlı hosting nezaketi).
- **Panel → Yapay Zekâ → Günlükler** ekranında son 30 günün çağrı, jeton ve hata özetini
  görürsünüz. 1M jeton birim fiyatını girerseniz tahmini tutar da hesaplanır.

### Yapay zekâ ile üretilen içerik
- Üretilen her habere `ai_generated` işareti konur ve ön yüzde **"Yapay zekâ desteğiyle
  hazırlandı, editör onayından geçti"** rozeti gösterilir.
- **Kaynak adı ve kaynak bağlantısı zorunludur**; gövdenin sonuna otomatik kaynak bloğu eklenir.
- Modelden gelen metin sunucuda beyaz listeyle temizlenir.
- Dış kaynaklı metin modele **yalnız veri olarak** gider; sistem talimatı ayrı kanaldan
  gönderilir ve talimat ele geçirme kalıpları etkisizleştirilir.

> Yapay zekâ desteği editör denetiminin yerine geçmez. Yayımlamadan önce içeriği okuyun.

---

## Temalar

| Tema | Karakter |
|---|---|
| **gazete** | Klasik yoğun haber sitesi: geniş manşet, üç sütun, kategori şeritleri |
| **kagit** | Gazete nostaljisi: serif başlıklar, ince çizgiler, krem zemin |
| **bulten** | Minimal/dergi: bol boşluk, büyük görsel, tek sütun akış |
| **gece** | Koyu tema, teknoloji/gündem havası, kırmızı vurgulu son dakika şeridi |
| **yerel** | Yerel gazete: ilçe/bölge vurgusu, duyuru kutusu, vefat/ilan alanı |

**Panel → Tema Editörü** ekranından canlı önizlemeyle renk paleti, tipografi, logo/favicon,
yerleşim (kenar çubuğu konumu, kart stili, köşe yumuşaklığı, konteyner genişliği),
anasayfa blok dizilimi ve özel CSS düzenlenir. Her tema için ayrı ayar profili tutulur;
"Varsayılana dön" ile tek tıkla geri alınır.

---

## Tema geliştirme rehberi

Yeni bir tema, `themes/` altında bir klasördür. Kopyalayarak başlamak en kolayı:

```
themes/kendi-temam/
  theme.json          # zorunlu — üstveri ve ayar şeması
  layout.php          # zorunlu — üst/alt çatı
  home.php  category.php  single.php  page.php  search.php  notfound.php
  style.css
  parts/  header.php  footer.php  card.php  sidebar.php  comments.php
```

Bir şablon temanızda yoksa Manşet otomatik olarak `themes/gazete/` karşılığına düşer —
yani yalnız `theme.json`, `layout.php` ve `style.css` ile de çalışan bir tema yazabilirsiniz.

### Altın kural
> **Tema dosyaları veritabanına asla doğrudan dokunmaz.**
> Yalnız `inc/view.php`'nin sunduğu fonksiyonlar kullanılır. `db()`, `q()`, `qa()` gibi
> çağrılar tema dosyalarında yasaktır.

Her tema dosyası şununla başlar:
```php
<?php if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
```

### `theme.json`

```json
{
  "name": "Kendi Temam",
  "description": "Kısa tanıtım",
  "version": "1.0.0",
  "author": "Adınız",
  "preview": "#c0392b",
  "supports_dark": true,
  "settings": [
    { "key": "color_primary", "label": "Ana renk", "type": "color",
      "default": "#c0392b", "css_var": "renk-ana", "group": "Renkler" },
    { "key": "container_width", "label": "Genişlik", "type": "size",
      "unit": "px", "default": "1200", "css_var": "genislik", "group": "Yerleşim" },
    { "key": "sidebar_position", "label": "Kenar çubuğu", "type": "select",
      "default": "right", "group": "Yerleşim",
      "options": [{"value":"right","label":"Sağda"},{"value":"none","label":"Yok"}] },
    { "key": "custom_css", "label": "Özel CSS", "type": "css",
      "default": "", "group": "Gelişmiş", "admin_only": true }
  ]
}
```

Panel bu şemadan **otomatik olarak ayar formu üretir**. Değerler
`settings` tablosuna `theme:<tema-adı>:<anahtar>` biçiminde yazılır.

**Alan tipleri:** `color` · `size` · `font` · `text` · `select` · `bool` · `image` · `css`
`css_var` verirseniz değer `:root { --<css_var>: … }` olarak çıktıya basılır.

### `layout.php` iskeleti

```php
<?php if (!defined('MANSET_BOOTSTRAPPED')) { exit; } ?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?= seo_head_tags() ?>
<?= theme_font_link() ?>
<link rel="stylesheet" href="<?= esc(theme_url('style.css')) ?>">
<style><?= theme_css_vars() ?><?= theme_custom_css() ?></style>
</head>
<body>
  <?php part('header'); ?>
  <main><?= ad_slot('header') ?><?php view_content(); ?></main>
  <?php part('footer'); ?>
  <?= cookie_notice_html() ?>
</body>
</html>
```

### En çok kullanılan veri fonksiyonları

```php
site('title')                      // site adı, slogan, desc, logo, favicon, url, year
menu()                             // üst menüdeki kategoriler
headline_posts(6)                  // manşet
breaking(5)                        // son dakika
latest_posts(10, 0, $haricIdler)   // son haberler
posts_by_category($kategori, 6)    // kategori haberleri
most_read(5, 7)                    // çok okunanlar (son 7 gün)
editor_picks(4)  video_posts(4)
related_posts($post, 4)
post_image($post, 'medium')        // thumb | medium | large | orig
post_image_srcset($post)
post_body_html($post)              // temizlenmiş gövde
post_tags($post)
comments_for($post['id'])
ad_slot('sidebar_1')               // reklam alanı
paginate($total, $perPage, $page, fn($n) => url(...))
theme_setting('color_primary')     // tema ayarı
part('card', ['post' => $p])       // parça çağırma
```

Tam liste ve imzalar: [`CONTRACTS.md` §5.3](CONTRACTS.md).

### Zorunlu kanca noktaları
Temanız şunları basmalıdır — yoksa SEO, reklam, çerez ve künye özellikleri çalışmaz:

| Kanca | Yer |
|---|---|
| `seo_head_tags()` | `<head>` |
| `theme_css_vars()` + `theme_custom_css()` | `<style>`, stylesheet'ten sonra |
| `ad_slot('header' / 'sidebar_1' / 'sidebar_2' / 'in_article' / 'footer')` | ilgili alanlar |
| `cookie_notice_html()` | `</body>` öncesi |
| `part('comments', ['post' => $post])` | `single.php` |
| `url('kunye')` bağlantısı | alt bilgi |
| `published_at` **ve** `updated_at` | `single.php` (mevzuat gereği — bkz. [docs/BIK-NOTLARI.md](docs/BIK-NOTLARI.md)) |

### Anasayfa blok dizilimi
`home.php` içinde `home_blocks()` döngüsüyle blokları basın. Blok tipleri:
`breaking` `headline` `latest` `category` `most_read` `editor_pick` `video` `ad`
`market` `weather` `newsletter`. Desteklemediğiniz bloğu sessizce atlayın.

### Temanızı paylaşmak
Klasörü zipleyip dağıtabilirsiniz. Kullanıcı `themes/` altına açar, panelden seçer.
Manşet MIT lisanslıdır; temanızı istediğiniz lisansla dağıtabilirsiniz.

---

## Roller ve üyelik

Manşet **10 kullanıcı tipi** tanımlar. Tasarım kararları ve gerekçeleri:
[`USER_TYPES_PLAN.md`](USER_TYPES_PLAN.md).

| Rol | Ne yapar |
|---|---|
| **Sistem Yöneticisi** | Her şey: ayarlar, yedek, yapay zekâ anahtarı, ham reklam kodu |
| **Genel Yayın Yönetmeni** | Her haberi yayımlar/siler, ekibe hesap açar; teknik ayarlara dokunamaz |
| **Editör** | Masa editörü: düzenler ve yayımlar |
| **Yazar** | Yalnız kendi içeriği; yayımlanmış yazısını da düzeltebilir |
| **Muhabir** | Mobil hızlı giriş; yayımlandıktan sonra metnine dokunamaz |
| **Otomasyon Editörü** | RSS havuzu ve yapay zekâ kuyruğu; **yayımlama yetkisi yok** |
| **Vitrin ve SEO Editörü** | Manşet sırası ve üstveri; gövdeye dokunamaz |
| **Reklam Yöneticisi** | Kampanya zamanlar, görsel reklam kurar; ham kod giremez |
| **Topluluk Moderatörü** | Yalnız yorum kuyruğu ve üye yönetimi |
| **Üye** | Panele giremez; yorum yazar, haber kaydeder |

### İzin matrisi düzenlenebilir — ama çekirdeği kilitli
**Panel → Roller ve İzinler** ekranından 36 iznin her birini rollere açıp kapatabilirsiniz.
Ancak yedi izin **kilitlidir** ve hiçbir role atanamaz:
`ai.configure` · `ads.html` · `theme.css` · `settings.manage` · `users.manage` ·
`roles.manage` · `tools.manage`

> **Neden?** Bu izinler teknik olarak yönetici yetkisine eşdeğerdir. Örneğin ham reklam
> kodu sitede JavaScript çalıştırabilir, JavaScript de panelde işlem yapabilir.
> Ayrıca panelde veritabanı geri yükleme özelliği var: izinler tamamen veritabanında
> tutulsaydı, hazırlanmış bir yedeği geri yüklemek doğrudan yetki yükseltme olurdu.
> Bu yüzden kilitli izinler için karar **koddan** verilir; `role_overrides` tablosuna
> elle yazılsalar bile yok sayılırlar.

### Sorumlu yazı işleri müdürü
Panel → Kullanıcılar ekranından bir kişiyi **sorumlu yazı işleri müdürü** olarak
işaretleyebilirsiniz. İşaretli etkin bir kullanıcı varsa, düzeltme ve cevap yayımlama
yetkisi **yalnız o kişide** çalışır — izin başka rolde bulunsa bile. Basın Kanunu m.14
yükümlülüğü şahsa bağlı olduğu için bu ayrım koda gömülmüştür.

### Üyelik ve abonelere özel içerik
Ziyaretçiler `/uye/kayit` adresinden üye olabilir. Haber düzenleme ekranındaki
**“Kimler okuyabilir”** alanıyla içerik üç düzeye ayrılır: herkes · yalnız üyeler ·
yalnız aboneler. Kilitli içerikte ziyaretçiye önizleme metni gösterilir.

Abonelik kademesi **Panel → Üyeler** ekranından elle verilir; bitiş tarihi
okuma anında değerlendirilir, yani **zamanlanmış görev gerekmez**.
Manşet ödeme altyapısı içermez; tahsilat yayın kuruluşunun kendi süreciyle yapılır.

> **E-posta gönderimi:** Doğrulama bağlantısı sunucuda `mail()` çalışıyorsa gönderilir.
> Çalışmıyorsa üyeler **Panel → Üyeler → Bekleyen doğrulamalar** bölümünden elle
> onaylanır. Toplu bülten gönderimi kapsam dışıdır (bkz. Yol haritası).

---

## Künye ve BİK notu

Manşet, künye alanlarını **panelden düzenlenebilir** biçimde sunar
(**Panel → Künye / BİK**): yayın adı, imtiyaz sahibi, sorumlu yazı işleri müdürü,
ticari unvan, iş yeri adresi, telefon, e-posta, elektronik tebligat (KEP/UETS) adresi,
yer sağlayıcı (hosting) adı ve adresi, vergi dairesi/no, MERSİS ve serbest ek notlar.
Künye `/kunye` adresinde yayımlanır ve her temanın alt bilgisinden doğrudan bağlanır.

Ayrıca hazır gelenler: yayın ilkeleri sayfası şablonu, haber altında **düzeltme talep etme**
formu ve panelde süre göstergeli talep yönetimi, EİDS/BİK doğrulama kodu alanı,
yapay zekâ üretimi rozeti, içerik üzerinde yayım ve güncelleme tarihi.

> ⚠️ **Yayıncı sorumluluğu.** Mevzuat değişir. Manşet'in sunduğu alan listesi
> [docs/BIK-NOTLARI.md](docs/BIK-NOTLARI.md) belgesinde kaynaklarıyla açıklanmıştır,
> ancak **hukuki görüş değildir.** Yayıncı, yürürlükteki güncel Basın Kanunu ve
> Basın İlan Kurumu düzenlemelerini resmî kaynaklardan teyit etmek ve künyesini
> buna göre doldurmakla yükümlüdür. Gerekiyorsa panelde "ek notlar" alanını kullanarak
> alan ekleyebilirsiniz.
>
> BİK teknik esaslarının gerektirdiği **güncel SSL/TLS sertifikası hosting
> sorumluluğundadır**; Manşet HTTPS'i algılar ve güvenli çerez kullanır, ancak
> sertifikayı sağlamaz.

---

## Güvenlik

- **SQL:** Yalnız PDO prepared statement. Dize birleştirmeli sorgu yok.
- **XSS:** Çıktıda `esc()`; haber gövdesi beyaz listeli temizleyiciden geçer
  (`iframe` yalnız YouTube/Vimeo/Dailymotion/Spotify/SoundCloud).
- **CSRF:** Tüm yazma uçlarında `X-CSRF` başlığı veya `_csrf` alanı zorunlu.
- **Hız sınırı:** Giriş 8/5dk, yorum 3/5dk, düzeltme talebi 3/15dk.
- **Oturum:** `httponly` + `samesite=Lax` + HTTPS'te `secure`; girişte `session_regenerate_id`.
- **SSRF:** RSS ve harici JSON adreslerinde şema/host doğrulaması; `localhost`, özel ve
  ayrılmış IP aralıkları reddedilir; yönlendirme takibi sınırlı.
- **HTML temizleme:** `ext/dom` **zorunludur**. Eklenti yoksa haber gövdesindeki
  tüm biçimlendirme atılır (güvenli taraf) — düzenli ifadeyle HTML temizlemek
  güvenli hâle getirilemez.
- **Yükleme:** Uzantı **ve** gerçek MIME doğrulaması, SVG reddi, çift uzantı koruması,
  rastgele dosya adı, `uploads/` içinde PHP çalıştırma kapalı.
- **Dosya erişimi:** `db/` ve `inc/` için `Require all denied`; SQLite dosya adı rastgele
  ekli (`.htaccess` desteklemeyen sunucularda ek koruma).
- **Kurulum:** Tamamlanınca `install/.locked` ile kilitlenir.
- **Reklam kodu, özel CSS ve yapay zekâ anahtarı** yalnız `admin` rolüne açıktır.

### Apache dışı sunucular — bunu atlamayın

Yukarıdaki dosya erişim kuralları `.htaccess` ile uygulanır ve **`.htaccess` yalnız
Apache'de okunur.** Nginx, IIS, LiteSpeed'in bazı kipleri ya da Caddy altında bu
dosyalar sessizce yok sayılır: site çalışmaya devam eder, ama `db/` altındaki
veritabanı ve **yedekler** doğrudan indirilebilir hale gelir. Hiçbir hata mesajı
görmezsiniz — bu yüzden kurulumdan sonra bir kez **elle sınayın**:

```
curl -I https://siteniz.com/db/
```

`403` ya da `404` görmüyorsanız koruma yoktur. Sunucunuza göre ekleyin:

**Nginx** (site bloğunun içine):

```nginx
location ~ ^/(db|inc|install)/  { deny all; return 404; }
location ~ ^/(config\.php|cron\.php)$ { deny all; return 404; }
location ~ \.(sqlite|sqlite-wal|sqlite-shm|sql|tar)$ { deny all; return 404; }
location ^~ /uploads/ { location ~ \.php$ { deny all; return 404; } }
```

> `cron.php` yalnız dışarıdan çağrılıyorsa engellemeyin; anahtarla korunuyor.
> Yedekler `db/` altında durduğu için son kural onları da kapsar.

**IIS** — `web.config` içinde `<requestFiltering>` ile `db`, `inc`, `install`
dizinlerini gizleyin ve `.sqlite` / `.tar` uzantılarını reddedin.

Ek katman olarak SQLite dosya adı ve yedek adları rastgele ek taşır (tahmin
edilemezler), ama bu **korumanın yerine geçmez** — yalnız zaman kazandırır.

Denetim bulguları ve düzeltmeleri: [`SECURITY_AUDIT.md`](SECURITY_AUDIT.md).

Güvenlik açığı bildirimi: lütfen genel bir issue açmadan önce depo sahibiyle iletişime geçin.

---

## Yedekleme

**Panel → Araçlar** ekranından veritabanının anlık kopyasını indirebilir, aynı ekrandan
geri yükleyebilirsiniz (geri yükleme yönetici parolasını yeniden ister).

Elle yedek alıyorsanız şu üçünü birlikte saklayın:
1. `config.php`
2. `db/` klasörü (SQLite) veya MySQL dökümü
3. `uploads/` klasörü

---

## Geliştirme ve testler

```bash
# Geliştirme kurulumu (kökte config.php + db/manset-dev.sqlite üretir; git dışıdır)
php tests/dev-install.php

# Yerel sunucu (rewrite davranışını taklit eden yönlendiriciyle)
php -S 127.0.0.1:8080 -t . tests/router.php

# Uçtan uca testler — kendi geçici kopyasında çalışır, verinize dokunmaz
bash tests/e2e.sh
# PHP başka bir yoldaysa:
PHP=/usr/bin/php8.3 bash tests/e2e.sh

# Dolu demo veri (40 haber, manşetler, yorumlar, reklam örnekleri,
# 10 rolün tamamı için personel hesabı, üç üyelik durumu, iki kilitli haber)
php tests/demo-seed.php

# Aynısı, haber görselleri gerçek fotoğraflarla (dışa istek yapar)
php tests/demo-seed.php --foto
```

Demo hesapların parolası `demo12345`. Betik çalıştıktan sonra oluşturulan
hesapların listesini ekrana basar. Üyelik tarafında üç durum kurulur:

| Hesap | Durum | Ne görür |
|---|---|---|
| `ayse@ornek.test` | ücretsiz üye | `üyelere özel` haberleri okur, `abonelere özel` haberde kilit görür |
| `cem@ornek.test` | geçerli abonelik | tümünü okur |
| `ferda@ornek.test` | süresi dolmuş abonelik | ücretsiz üye gibi davranır |

**Haber görselleri.** Demo tohumu her yayındaki habere bir kapak görseli üretir.
Varsayılan olarak görseller **yerelde** üretilir (GD ile; kategoriye göre renklenen,
başlığı taşıyan bir kapak) — çevrimdışı çalışır ve dışa hiç istek yapmaz.
`--foto` verirseniz görseller `picsum.photos` üzerinden gerçek fotoğraf olarak
indirilir; tasarımı gerçek fotoğraflarla değerlendirmek için kullanışlıdır ama
**dışa istek yaptığı için bilerek opt-in'dir**. İndirme başarısız olursa yerel
üretime düşer, betik yarıda kalmaz. Görsel çekirdeği haberin adresinden türetilir:
aynı haber her koşuda aynı görseli alır.

> Demo veri **yalnız denemek içindir**; yayına alınacak kurulumda
> `php tests/demo-seed.php --reset` ile temizleyin ve demo hesapları silin.

Katkı kuralları:
- Arayüz metinleri **Türkçe**, tanımlayıcılar **İngilizce**, yorumlar **Türkçe**.
- Dış JS/CSS kütüphanesi eklenmez.
- Şema değişikliği `inc/migrations/NNN_ad.php` dosyasıyla yapılır.
- Mimari sözleşme: [`CONTRACTS.md`](CONTRACTS.md).

---

## Sık sorulan sorular

**Composer veya npm gerekiyor mu?**
Hayır. Dosyaları yükleyip kuruluma girmeniz yeterlidir.

**Yapay zekâ zorunlu mu?**
Hayır. Anahtar girilmezse yapay zekâ özellikleri kapalı kalır, site tam çalışır.

**Hangi yapay zekâ sağlayıcılarını kullanabilirim?**
Anthropic Messages API ve `/chat/completions` uyumlu **herhangi bir** uç. Yerli ve
alternatif sağlayıcılar `openai_uyumlu` seçeneğiyle `base_url` girilerek kullanılır.

**SQLite yeterli mi?**
Küçük ve orta ölçekli siteler için evet — WAL kipi ve indeksler ayarlıdır. Çok yüksek
eşzamanlı yazma bekliyorsanız kurulumda MySQL seçin.

**Temiz adresler çalışmıyor.**
Manşet bunu algılayıp `?r=` yedeğine geçer. `mod_rewrite`'ı açtıktan sonra
Panel → Ayarlar → "Yeniden sına" düğmesini kullanın.

**RSS'ten çekilen haberler otomatik yayımlanıyor mu?**
Yalnız kaynağın "Otomatik yayın" seçeneğini açtıysanız. Varsayılan olarak haberler
**havuza** düşer ve editör onayı bekler.

**Yorumlar neden görünmüyor?**
Varsayılan olarak yorumlar moderasyondan geçer. Panel → Yorumlar ekranından onaylayın
veya Ayarlar'dan moderasyonu kapatın.

**Kaç tema kurabilirim?**
Sınırsız. `themes/` altına klasör olarak eklenir, panelden seçilir.

**Çoklu site (multi-tenant) desteği var mı?**
Hayır — bilinçli olarak kapsam dışıdır. Her site kendi kurulumunda çalışır.

---

## Yol haritası

### Kapsam dışı

Aşağıdakiler **bilinçli olarak kapsam dışı** bırakılmıştır; katkıya açıktır:

- Çoklu site (multi-tenant) yönetimi
- Mobil uygulama
- AMP çıktısı
- Push bildirim servisi
- Video barındırma (yalnız gömme desteklenir)
- Yapay zekâ ile otomatik görsel üretimi (adaptörde ileride değerlendirilecek)
- Sosyal medyaya otomatik paylaşım API'leri
- E-posta bülten **gönderim** motoru — abone toplama formu vardır, toplu gönderim yoktur

---

## Lisans

[MIT](LICENSE) — Manşet katkıcıları.
