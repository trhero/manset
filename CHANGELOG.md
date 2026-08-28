# Değişiklik Günlüğü

Bu proje [Anlamsal Sürümleme](https://semver.org/lang/tr/) kurallarını izler.

## [Yayımlanmadı]

## [1.2.0] — "Yayıncı paketi"

1.1 zemini sağlamlaştırmıştı; 1.2 **gelir, görünürlük ve okur deneyimi** getiriyor.
Yayıncı artık okunmasını kendi ölçüyor, reklamverene rapor verebiliyor, aboneden
tahsilat yapabiliyor ve okuru arama motorlarında görünür kılıyor.

> **Yükseltme notu.** Dosyaları FTP ile üstüne attıktan sonra panele bir kez girin:
> şema kendiliğinden güncellenir ve öncesinde yedek alınır. Kendi teması olanlar
> **aşağıdaki kırılma notunu** okumalıdır.

### Gelir
- **Ödeme sağlayıcı adaptörü** (`inc/payment.php`, YZ adaptörü deseniyle):
  havale/EFT (dekont yükle → yönetici onaylar) ve barındırılan ödeme sayfası +
  webhook. **Kart verisi bu sisteme hiç girmez, dokunulmaz, saklanmaz.**
  Abonelik planları tablosu; webhook `memberships.valid_until` süresini uzatır.
  Webhook imzası `hash_equals` ile doğrulanır, aynı bildirim iki kez gelirse
  abonelik **bir kez** uzar (sağlayıcılar bildirimi tekrar gönderir — bu normaldir).
- **Ölçülü ödeme duvarı**: ayda N ücretsiz kilitli haber, tek kullanımlık hediye
  bağlantısı, deneme süresi, yenileme hatırlatması (tek tek, toplu gönderim yok).
  Sayaç **okurun imzalı çerezinde** durur, sunucuda satır tutulmaz — çerezsiz
  ziyaretçiyi izlememek için. Karşılığında okur çerezini silerse sayaç sıfırlanır;
  bilinçli ve belgelenmiş bir sınırdır.
- **Reklam v2**: gösterim/tıklama sayacı, `ads.txt` yönetimi, **sponsorlu içerik
  rozeti**, paragraf-içi reklam alanı. Gösterim sunucuda sayılamaz (önbellekten
  sunulan sayfa PHP'yi hiç çalıştırmaz), bu yüzden çerezsiz bir işaretle sayılır.
- **Paylaşım metni üretici** (YZ) ve paylaşım görseli şablonu (GD).

### Görünürlük
- **Çerezsiz kendi analitiği**: günlük toplu okunma, yönlendiren türü ve cihaz
  sınıfı. **Ham IP saklanmaz**, ham yönlendiren adresi saklanmaz.
  Panelde 7/30 gün grafiği (dış kütüphane yok), en çok okunanlar, abone hunisi.
  İsteyen Plausible/Matomo/GA kodunu ayrılan alana yapıştırabilir.
- **Panel "bekleyen işler" rozetleri** ve dashboard sağlık kartı: cron bayatlığı,
  disk, veritabanı, yedek yaşı, YZ hata oranı.
- **Kurulum sonrası kontrol listesi** ve demo verisini temizleme düğmesi.

### SEO ve beslemeler
- SEO ayar grubu, başlık şablonları, kategori/etiket SEO alanları, editörde
  SERP/OG önizlemesi, **elle 301 yöneticisi** (hedef yalnız site içi olabilir).
- **IndexNow ve sitemap bildirimi kuyrukla** çalışır: yayın düğmesine basan
  editör dış servisi beklemez ve servis çökerse yayın düşmez.
- Görsel sitemap, JSON Feed, etiket ve yazar beslemeleri, besleme özet/tam ayarı.

### İşletim
- **Zamanlı ve uzak yedek**: günlük veritabanı, haftalık `uploads` arşivi, saf
  PHP `ftp_*` ile uzak hedef, tür başına saklama politikası, panelde yedek yaşı.
- **Bülten çift onayı** (1.0'dan beri `confirmed` sütunu hiç 1 olmuyordu),
  token'lı çıkış bağlantısı, kategori segmenti, CSV dışa aktarma.
  Abone listesini indirmek ayrı bir yetki ister ve **denetim kaydına yazılır**.

### Okuma deneyimi
- **Karanlık mod** beş temada (tam palet: gazete, kâğıt), 3 durumlu: sistem /
  açık / koyu. Tercih `localStorage`'da, **çerez yok** — sayfa önbelleği korunur.
- 3 kademeli yazı ölçeği ve `72ch` satır genişliği.

### Düzeltmeler
- **Abone listesi zayıf yetkiyle indirilebiliyordu.** Eski `newsletter.export`
  ucu hâlâ kayıtlıydı, abone IP'lerini de veriyor ve denetim kaydı yazmıyordu;
  yenisinin geçerli olması yalnızca dosya adlarının alfabetik sırasına bağlıydı.
  Aynı atlama Widget'lar ekranında da vardı. İkisi de kapatıldı.
- Ölçülü hakla açılan tam metin, önbellekte **paylaşılabiliyordu**; hakkı biten
  okurun kilitli sayfası da önbelleğe yazılıp hakkı olan okura sunuluyordu.
- `.rozet-premium` kontrastı 3.25:1 idi (WCAG AA eşiği 4.5:1) → 5.32:1.
  Karanlık modda ölçülen 13 kontrast kırığı düzeltildi.
- Zamanlanmış ve sonradan yayımlanan haberler arama motoruna **hiç
  bildirilmiyordu** (keşif haber kimliğine dayanıyordu, oysa zamanlanmış haberin
  kimliği yayından günler önce atanır).

### Geliştiriciler için
- **Genişleme kayıt defterleri** (CONTRACTS §14): `cron_register()`,
  `admin/settings-groups/*.php`, `manset_feature_modules()` ve kanca noktaları.
  Yeni bir zamanlı görev ya da ayar grubu eklemek artık çekirdek dosyaya
  dokunmayı gerektirmiyor.
- Ayar ekranına `select` ve `secret` alan tipleri. `secret` ekrana basılmaz ve
  boş gönderim mevcut değeri korur (yoksa her kaydediş API anahtarını silerdi).
- `tests/probe.php dup_api`: aynı API ucunu iki dosyada tanımlayan çakışmaları
  arar — `api_register()` sessizce üzerine yazdığı için bu hata görünmezdi.
- e2e artık kök dizindeki tüm `.php` dosyalarını kopyalar (liste elle sayılmıyor)
  ve çalışma dizini/geçici dosyaları **porta bağlıdır**; eşzamanlı turlar
  birbirini bozmuyor.

### Tema sözleşmesi — kırılma notu
Üçüncü taraf temalar `<html>` üzerinde `data-tema`, `data-goruntu`, `data-olcek`
özniteliklerini basmalı ve `part('okuma-bas')` ile `part('okuma-ayarlari')`
kancalarını uygulamalıdır (CONTRACTS §5.5).
**`<body>`'deki `koyu-mod` sınıfı kaldırıldı** — basılıyordu ama hiçbir CSS
kullanmıyordu ve yayıncının ayarını yansıtıyordu; karanlık mod artık okurun
kararıdır. Ona dayanan tema `html[data-goruntu="koyu"]` seçicisine geçmelidir.


## [1.1.0] — "Sağlam zemin"

Bu sürümde **yeni ürün özelliği yok**. 1.0'ın tamamlanmamış kenarları kapatıldı,
bir sonraki yükseltmenin güvenli olması için altyapı kuruldu ve otomasyon
dayanıklı hâle getirildi. Yol haritası: `GELISTIRME_PLANI.md`.

> **Yükseltme notu.** Yeni sürümün dosyalarını FTP ile üstüne attıktan sonra
> panele bir kez girin: şema kendiliğinden güncellenir (öncesinde yedek alınır).
> Elle bir şey yapmanız gerekmez. Kendi teması olanlar için: tema sözleşmesi
> genişledi (aşağıya bakın).

### Yükseltme ve dayanıklılık
- **Şema göçü artık kendiliğinden uygulanıyor.** `settings.installed_version`
  damgası eklendi; panele (ya da cron'a) ilk girişte sürüm farkı görülürse
  yedek alınır, göçler uygulanır, önbellek temizlenir ve damga güncellenir.
  1.0'da göçler yalnız kurulumda ve panelde bir düğmeyle çalışıyordu — zip'i
  üstüne atan yönetici o düğmeyi bilmezse site yarı çalışıyordu.
  Göç **yalnız yetkili bağlamda** tetiklenir; anonim ön yüz isteğinde şema
  değiştirmek yarış koşulu yaratır ve ilk ziyaretçiye saniyeler süren bir
  istek olarak yansırdı.
- **Cron kilidi ve görev geçmişi.** `flock` ile aynı anda iki cron çalışması
  engellendi (RSS aynı haberi iki kez alabiliyordu). Her görev `cron_runs`
  tablosuna yazılıyor: başlangıç, süre, işlenen öge, sonuç.
- **Görev bütçesi.** Uzun görevler süre dolunca kaldığı yeri bırakıp çıkıyor,
  sonraki turda devam ediyor (`cron_task_budget_sec`, varsayılan 25 sn).
- **"Poor man's cron".** Gerçek cron kuramayan paylaşımlı hostingler için:
  `cron_web_tick` açıksa görevler bir panel isteğinin **sonunda** tetiklenir.
  Ön yüzde çalışmaz — anonim istek süresi ve sayfa önbelleği korunur.
- **AI geri çekilmesi.** Sağlayıcı geçici hata verdiğinde (5xx, zaman aşımı)
  iş artık `failed` olmuyor; üstel bekleme ile yeniden deneniyor. Yalnız kalıcı
  hatalarda (geçersiz istek, hatalı anahtar) başarısız sayılıyor.
- **AI aylık bütçe tavanı** (`ai_monthly_budget`, 0 = sınırsız). Tavan dolunca
  yeni çağrı yapılmaz, kuyruk durur, iş kaybolmaz; panelde harcama göstergesi.
- **RSS geri çekilmesi.** Kaynak 5 hatada kalıcı olarak pasifleşiyordu; geçici
  bir kesinti kaynağı sonsuza dek öldürüyordu. Artık "beklemede" durumuna
  geçiyor (5 dk → 24 sa) ve kendiliğinden yeniden deneniyor. Kaynak başına
  kontrol aralığı eklendi.
- **Sağlık ucu** `public.health` (anahtarlı, varsayılan kapalı): sürüm, bekleyen
  göç, cron yaşı, disk, veritabanı boyutu, bekleyen kuyruklar.
- **Çöp kutusu.** Haber silme artık geri alınabilir (`posts.deleted_at`).
  Kalıcı silme ayrı bir yetki ister.

### Performans
- **LCP görselleri artık tembel yüklenmiyor.** Manşetin ilk kartı ve haber
  sayfasının ana görseli `loading="eager"` + `fetchpriority="high"`; sayfanın
  en üstündeki görseli tembel yüklemek Largest Contentful Paint'i doğrudan
  cezalandırıyordu ve bu Google Haberler sıralamasında bir sinyaldir.
- **WebP artık gerçekten sunuluyor.** Varyantlar 1.0'da üretiliyor ama hiçbir
  yerde kullanılmıyordu — diskte duran ölü dosyalardı. `<picture>` ile sunuluyor.
- Görsellere `width`/`height` verildi (düzen kayması azaldı).
- `.htaccess`: sıkıştırma (mod_deflate), statik dosyalara uzun önbellek
  (mod_expires/mod_headers), `Vary: Accept-Encoding`. HTML bilinçli olarak
  kapsam dışı — sayfa önbelleğinin başlıklarını PHP yönetiyor.

### Temalar
- **Beş temada özellik eşitlendi.** `bulten`, `gece` ve `yerel` temalarında
  `theme.js` hiç yokmuş: mobil menü, canlı arama önerisi ve panoya kopyalama
  tamamen eksikti. Paylaş düğmeleri, okuma süresi ve oturum farkındalı yorum
  formu da eklendi.
- **Ödeme duvarı kutusu artık stilli.** `.icerik-kilit` hiçbir temada
  tanımlanmamıştı; abonelik daveti biçimsiz görünüyordu.
- **"Kaydet" düğmesi çalışıyor.** Düğme hiçbir temada basılmıyordu ve ön yüzde
  üyelik modülü yüklenmediği için `member_current()` hiç tanımlı değildi —
  üyeliğin görünür tek getirisi ölüydü.
- **Düzeltme metni üç temada hiç basılmıyordu** (`bulten`, `gece`, `yerel`).
  Basın Kanunu m.14 düzeltme ve cevabın ilgili içerikle yayımlanmasını ister;
  bu temaları seçen yayıncı yükümlülüğünü yerine getirdiğini sanıyordu.

### Roller ve yetki
- **Üç ölü izin kapıya bağlandı.** `posts.edit_wire`, `posts.seo` ve
  `posts.takedown` katalogda tanımlıydı ama hiçbir kapıda okunmuyordu:
  Otomasyon Editörü kendi iş kolunun çıktısını açamıyor, SEO Editörü üstveri
  düzenleyemiyordu. Artık SEO Editörü "üstveri kipi"nde yalnız SEO alanlarını
  yazabiliyor; gövde ve başlık girdisi sunucuda yok sayılıyor.
- **Panel sayfa kapısı fail-closed oldu.** İzin artık menüden bağımsız bir
  haritadan okunuyor; haritada olmayan sayfaya erişim yok. Eskiden menüde
  kayıtlı olmayan bir sayfa için izin hiç sorulmuyordu.
- `probe unused_perms` sondası: katalogda tanımlı ama hiçbir kapıda
  kullanılmayan izinleri listeler. Böyle bir izin sessizce ölüdür — yayıncı
  açar, hiçbir şey değişmez.

### Muhabir ekranı
- **Çevrimdışı yedek haber metnini kaybediyordu.** Yerel yedek geri yüklenirken
  yalnız başlık ve spot yazılıyordu; sahada şebeke koparsa en kıymetli alan
  gidiyordu. Gövde, görsel ve kategori de geri yükleniyor.
- "Düzenle" bağlantısı ölüydü (`?duzenle=` parametresi hiç okunmuyordu).
- Çoklu fotoğraf desteği; çevrimdışı yedek tek anahtardan kuyruğa çevrildi
  (ikinci haber birincinin yedeğini siliyordu).

### Düzeltme ve cevap (Basın Kanunu m.14)
- `handled_by` artık yazılıyor: talebi kimin işlediği kayıtlı.
- Başvurana tek alıcılık, işlemsel bilgilendirme e-postası (toplu gönderim yok).
- Yasal süre görünürlüğü: `corrections_deadline_hours` ayarıyla gecikmiş
  talepler panelde işaretleniyor. Süre koda gömülü değildir; **yayıncı
  yürürlükteki güncel yönetmeliği teyit etmelidir.**
- Editoryal "Güncelleme notu" türü — yasal düzeltmeden ayrı tutulur.

### Kurulum
- Gereksinim denetimi genişledi: `fileinfo`, `openssl`, mod_rewrite/SEF, PHP
  ini değerleri, disk alanı. Her eksik madde için **paylaşımlı hostingde ne
  yapılacağı** yazılı.
- Alt dizine kurulumda `RewriteBase` kendiliğinden hesaplanıyor.
- nginx ve IIS için kopyalanabilir SEF yönergesi.
- HTTPS'e yönlendirme seçeneği.

### Test ve süreklilik
- **Birim test koşucusu** (`tests/unit/run.php`) — Composer'sız, saf PHP.
  52 test, 333 iddia; `sanitize_html`, `roles_can`, `slugify`, `rate_limit`
  ve önbellek yardımcılarını kapsıyor.
- **GitHub Actions**: PHP 8.0/8.2/8.4 × SQLite matrisi ve ayrı bir MySQL işi.
- `tests/e2e.sh --driver=mysql` desteği (MySQL yolu ilk kez CI'da koşuyor).

### Düzeltilen hatalar
- `slugify()` büyük harfli Nordik/İspanyol harflerini sessizce siliyordu
  (`Øslo` → `slo`). Türkçe davranışı değişmedi.
- `roles_cache_reset()` asıl izin önbelleğini temizlemiyordu; uzun ömürlü
  süreçlerde (cron/CLI) bayat sonuç veriyordu.
- `uploads/.htaccess` içindeki koşulsuz `php_flag` satırı kaldırıldı: PHP'nin
  FPM/CGI olarak çalıştığı sunucularda dizinin tamamını 500'e düşürüyordu.
  Koruma azalmadı — asıl koruyucu direktifler SAPI'den bağımsızdır.
- Besleme kısayolları (`/rss.xml`, `/sitemap.xml`) 302 yerine 301 döndürüyor.

### Tema sözleşmesi (kendi teması olanlar için)
Aşağıdakiler tema sözleşmesine eklendi; kendi temanızı güncellemeniz önerilir:
- Sayfanın LCP adayı görseli `post_image_attrs($post, $size, true)` ile
  **eager** basılmalıdır.
- WebP `post_image_webp_srcset()` ile `<picture><source>` olarak sunulmalıdır.
- Düzeltme bloğu (`part('duzeltme-haber')`) haber sayfasında **basılmak
  zorundadır** — yasal yükümlülük.
- `post_update_notes_html()` düzeltme bloğunun üstünde basılmalıdır.

### Arayüz düzeltmeleri
- **Panel anahtar (toggle) düğmeleri metnin üstüne biniyordu.** `.form-alan > label`
  kuralı (özgüllük 0,1,1) `.anahtar`ı (0,1,0) eziyor ve etiketi blok yapıyordu;
  blok bağlamda `.kaydirak` satır içi kalıyor, `width:38px` yok sayılıyor ve geriye
  yalnız `::after` kalıyordu — metnin ilk harflerini örten 17×17 beyaz daire.
  ("Otomatik yayın" → "( omatik yayın" olarak görünen şey buydu.)
- **İki kolonlu panel ekranları dar pencerede 236px yatay taşıyordu.**
  `grid-template-columns:1fr` aslında `minmax(auto,1fr)`tir; `auto` alt sınırı
  içeriğin min-content genişliğidir, dolayısıyla geniş tablolar rayı kilitliyordu.
  `minmax(0,1fr)` ile ray küçülebiliyor, tablo kendi `.tablo-sarma` kutusunda kayıyor.
- **"Çok Okunanlar" kartlarında metin kutusundan taşıyor, kategori rozeti sıra
  numarasının altına giriyordu.** `.kart-kucuk` yatay bir karttır (görsel solda) ve
  en az ~300px ister; `.izgara.dar` rayı 172px'ti, gövdeye 57px kalıyordu.
  Ray genişletildi (bu ray yalnız yatay kartlarla kullanılıyor, yedi çağrı yerinin
  hepsi denetlendi) ve kartlar sertleştirildi: rozet kutusundan taşamaz, sıra
  numarası olan kartta rozete yer ayrılır.
- `.kart-baslik` negatif marjı sabit 18px yazılmıştı; `.kart.sikisik` dolgusu 14px
  olduğu için başlık her kenarda 4px taşıyordu. Dolgu artık CSS değişkeninden okunuyor.
- Uzun rol anahtarları (`chief_editor`) tablo hücresinden, boşluksuz JSON örneği de
  dar ekranda kod kutusundan taşıyordu.
- Muhabir hızlı giriş formu 420px'te sarmalayıcısını aşıyordu.
- **Son dakika şeridinde kaydırma çubuğu görünüyordu.** `prefers-reduced-motion`
  açık olan kullanıcıda kayan animasyon yerine `overflow-x:auto` veriliyor — doğru
  bir erişilebilirlik yedeği, ama tarayıcı çubuğu şeridin altında ikinci bir gri
  bant gibi duruyordu. Kaydırma yeteneği korundu, çubuk gizlendi.
- **Metin editörünün araç çubuğunun üstünde 54 piksellik boş bant vardı.**
  `.editor-sarma{overflow:hidden}` sarmalayıcıyı bir kaydırma bağlamı yapıyor,
  içindeki `position:sticky; top:var(--ust)` araç çubuğu da sayfaya değil o kutuya
  göre konumlanıp tam `--ust` kadar aşağı itiliyordu. Artık yapışkanlık sayfaya göre
  doğru çalışıyor: kutunun tepesinde duruyor, sayfa kayınca panel başlığının altına
  yapışıyor.
- **Araç çubuğu simgeleri tofu kutusu olarak çıkıyordu.** Bağlantı, bağlantı kaldır
  ve görsel düğmeleri emoji kullanıyordu (🔗 ⛓ 🖼); Windows'ta bir kısmı sistem yazı
  tipinde yok. Sekiz simge satır içi SVG'ye çevrildi (dış kütüphane değil).
  `B2`/`B3` düğmeleri `H2`/`H3` oldu — kalın 2 gibi okunuyordu.

### Demo
- Demo tohumu artık her yayındaki habere **kapak görseli** üretiyor. Varsayılan
  yerel üretimdir (GD, çevrimdışı, dışa istek yok); `--foto` ile gerçek fotoğraflar
  indirilir. Görsel çekirdeği haberin adresinden türetilir, böylece aynı haber her
  koşuda aynı görseli alır. `--reset` görselleri ve medya kayıtlarını da temizler.

### Güvenlik — denetim turu 2 (Faz 7 yüzeyi)
- **RSS/Atom beslemesi abonelere özel haberlerin tam gövdesini anonim basıyordu.**
  Görünürlük süzgeci yoktu; `curl site/rss.php` tek satırda ödeme duvarını devre
  dışı bırakıyordu. Beslemeler artık kilit kapısından geçiyor; başlık ve bağlantı
  keşif için beslemede kalıyor.
- **`theme.css` kilitli izni hiçbir kapıda okunmuyordu** — özel CSS kutusu
  `theme.manage`'e bakıyordu ve o izin `seo_editor` rolünde açıktı; `</style><script>`
  ile kalıcı XSS mümkündü.
- **Yetki yükseltme:** `chief_editor` kendine `admin` rolünde hesap açabiliyordu.
  Artık kimse kendisinde olmayan yetkileri içeren bir rolü atayamıyor.
- **`is_staff = 0` yalnız panel ekranını kapatıyordu**, API uçlarını değil; askıya
  alınan personel rolünün tüm yetkilerini kullanmaya devam edebiliyordu.
- **`posts.edit_own_published` yalnız arayüzde uygulanıyordu**; muhabir API'den
  yayındaki haberini değiştirebiliyor ve siteden düşürebiliyordu.
- **`rss.autopublish` izni hiç kontrol edilmiyordu** — insan onayı olmadan yayın.
- **`ads.manage` / `corrections.manage` katalogda yoktu**: `moderator` ve
  `ads_manager` rolleri fiilen ölüydü, Basın Kanunu m.14 sorumlu müdür kapısı
  hiç çalışmıyordu.
- **E-posta doğrulama ve parola sıfırlama bağlantıları hiç çalışmıyordu**
  (`?token=` gönderiliyor, `?t=` okunuyordu).
- **İki hesap sayım oracle'ı kapatıldı**: kayıt ve parola sıfırlama yanıtları artık
  e-postanın kayıtlı olup olmadığından bağımsız.
- **Oturum parola özetine bağlandı**: parola değişince diğer cihazlardaki oturumlar
  düşüyor.
- **Hız sınırındaki yarış kapatıldı** (önce yaz, sonra say) ve üye girişi ile parola
  sıfırlamaya hesap başına ikinci kova eklendi.
- `srcset` şema denetimindeki desende ham NUL baytı vardı; kapı ölü çalışıyordu.
- `tests/dev-install.php` komut satırı kapısı taşımıyordu.
- Kilit kutusu ve künye uyarısı anonim ziyaretçiye oturum açıyordu (önbellek kapısı).
- Meta açıklama kilitli gövdeden üretiliyordu.
- Çıkış CSRF'siz GET ile yapılıyordu; artık POST + `csrf_guard()`.
- `members.bookmark` CSRF başlığı göndermiyordu (her zaman 403) ve `login_required`
  alanı API'de hiç üretilmiyordu.

### Sözleşme
- CONTRACTS §0 ("SQL içinde yalnız tek tırnak") 24 dosyada 94 dizede ihlal
  ediliyordu; tamamı çevrildi. `tests/kural-sql.php` denetimi eklendi ve e2e her
  koşuda çalıştırıyor.
- `probe missing_perms` sondası: kapı olarak kullanılan her izin katalogda tanımlı mı?

### Çekirdek
- PDO tabanlı veri katmanı, ayar sistemi, CSRF, hız sınırı, rol kapısı (`can()`)
- SQLite (WAL + `busy_timeout`) varsayılan, kurulumda MySQL seçeneği
- Sürücüden bağımsız DDL ve `inc/migrations/` göç yürütücüsü
- 3 adımlı kurulum sihirbazı (gereksinim denetimi → bilgiler → kurulum ve kilit)
- SEF URL yönlendirici, `?r=` yedeği ve kendi kendini onaran rewrite algılama
- Beyaz listeli HTML temizleyici ve SSRF kalkanı (`safe_remote_url()`)
- `inc/api/*.php` modüllerini yükleyen JSON API dağıtıcısı — **75 uç**
- Zamanlanmış görev girişi: RSS, YZ kuyruğu, zamanlanmış yayın, slug eşitleme, önbellek, bakım

### Yönetim paneli
- Hız sınırlı giriş, rol tabanlı menü, ortak tasarım sistemi ve `window.M` JS yardımcıları
- Genel ayarlar, kullanıcı yönetimi (son yönetici koruması)
- Gösterge paneli: yayın/taslak/yorum/RSS/YZ sayaçları, sistem durumu
- Haber listesi (durum, kategori, yazar, YZ süzgeçleri) ve iki kolonlu editör
- **Elle yazılmış zengin metin editörü** — contenteditable, dış kütüphane yok, yapıştırma düz metne iner
- Medya kütüphanesi: MIME + uzantı doğrulaması, SVG reddi, çift uzantı koruması, GD varyantları + WebP
- Kategori, sabit sayfa ve yorum moderasyonu ekranları

### Vitrin
- Manşet yönetimi (12 sınırı, yukarı/aşağı sıralama), son dakika işaretleme, editörün seçimi
- 5 slotlu reklam yönetimi: tarih aralığı, aktiflik, sandbox'lı önizleme (kod alanı yalnız yöneticiye)

### Otomasyon
- RSS/Atom/RDF çekimi: karakter seti normalizasyonu, `guid_hash` mükerrer engeli, görsel adayı bulma,
  5 hatada kaynağı pasifleştirme, XXE reddi, 3 MB / 20 sn sınırları
- Havuz ekranı: "YZ ile yeniden yaz" ya da "olduğu gibi taslak yap"
- YZ iş kuyruğu: yarış koşulsuz iş devralma, eşzamanlılık sınırı, takılma kurtarma, 3 deneme
- YZ sağlayıcı adaptörü (`anthropic`, `openai_uyumlu`), maskeli anahtar, düzenlenebilir istem şablonları,
  jeton ve maliyet görünürlüğü, prompt enjeksiyonu kalkanı
- Editör için "başlık öner", "spot öner", "SEO doldur" düğmeleri

### Görünüm
- Tema motoru: `theme.json` şemasından **otomatik ayar formu**, şablon/parça çözümleme ve gazete temasına
  düşme, anasayfa blok dizilimi (11 blok tipi)
- **5 hazır tema**: `gazete` · `kagit` · `bulten` · `gece` · `yerel`
- Tema editörü: canlı iframe önizleme, renk/tipografi/yerleşim, logo-favicon, blok dizilimi,
  özel CSS, karanlık mod, tema başına ayar profili, varsayılana dön

### SEO ve beslemeler
- Meta, OpenGraph, Twitter kartları, kanonik URL, `prev`/`next`, `noindex` kuralları
- JSON-LD: `NewsArticle`, `BreadcrumbList`, `WebSite` + `SearchAction`, `Organization`, `CollectionPage`
- Sayfalı `sitemap.php` (haber/kategori/sayfa/etiket) + **Google News** haritası (son 48 saat)
- `rss.php`: RSS 2.0, Atom ve kategori beslemeleri; `robots.txt` üretimi
- Slug geçmişi tablosu ve URL değişiminde **301** yönlendirme

### Yayıncılık (Türkiye)
- Künye modülü — Basın Kanunu m.4'ün saydığı 7 zorunlu alan (KEP/UETS ve yer sağlayıcı dâhil),
  eksik alan uyarısı, BİK/EİDS doğrulama kodu alanı
- Düzeltme-cevap modülü (m.14): 1 günlük süre göstergesi, yayımlandığında 24 saat ana sayfada + 7 gün haber altında
- Yayın ilkeleri sayfası şablonu, çerez bildirimi, YZ üretimi rozeti
- İçerik üzerinde yayım **ve güncelleme** tarihi (m.4)

### Widget ve etkileşim
- Piyasa şeridi ve hava durumu: elle giriş veya harici JSON (15 dk önbellek, nokta-yolu alan eşlemesi)
- Yorum sistemi: honeypot, hız sınırı, KVKK onayı, moderasyon havuzu, basit spam sezgisi
- Canlı arama önerisi, bülten abone toplama (gönderim kapsam dışı)

### Performans ve güvenlik
- **Oturumsuz ön yüz**: tembel CSRF (`public.csrf`) ve istemci tarafı görüntülenme sayacı
  (`public.view`) sayesinde anonim ziyaretçiye çerez verilmez — sayfa önbelleği devreye girebilir
- Yalnız prepared statement, çıktıda `esc()`, yazma uçlarında CSRF, kamuya açık uçlarda hız sınırı
- `db/` ve `inc/` erişime kapalı, `uploads/` içinde PHP çalıştırma kapalı, rastgele SQLite dosya adı

### Performans, yedekleme ve bakım (Faz 4)
- **Dosya tabanlı sayfa önbelleği**: iki seviyeli klasör, kapsam bazlı TTL (liste 60 sn / haber 300 sn),
  `ETag` + `If-None-Match` ile 304, atomik `rename()` ile yazma, kapsam bazlı geçersizleştirme
  (`post:<id>`, `home`, `category`), boyut sınırında budama
- Önbellek **güvenlik kapıları**: oturum açılmışsa yazılmaz, yanıt `Set-Cookie` veriyorsa yazılmaz,
  çıktıda dolu CSRF anahtarı varsa yazılmaz, 200 dışı durum kodları önbelleğe girmez
- Önbellek klasörü yazılamıyorsa site çalışmaya devam eder, önbellek sessizce devre dışı kalır
- **Yedekleme**: SQLite `VACUUM INTO` (yoksa dosya kopyası + WAL checkpoint), MySQL için saf PHP
  SQL dökümü, parola doğrulamalı geri yükleme, geri yükleme öncesi otomatik güvenlik yedeği
- **Medya bakımı**: varyantları parça parça yeniden üretme, sahipsiz dosya taraması, depolama istatistikleri
- **Araçlar ekranı**: önbellek, yedek, medya, sistem bilgisi, `VACUUM`/`OPTIMIZE`, göç uygulama
- **Günlük ekranı**: `db/error.log` tür ve metin süzgeciyle, sondan geriye okuma, indirme/temizleme
- 10.040 haberle ölçüm: kategori 50. sayfa 40 ms (derin sayfalamada bozulma yok), haber detay 13 ms;
  `sitemap.php` GET ucundaki yazma kaldırıldı (347 ms → 28 ms)
- Slug üretiminde Türkçe kesme işareti düzeltildi (`Iğdır'da` → `igdirda`)

### Kullanıcı tipleri ve üyelik (Faz 7)
- **10 rol**: Sistem Yöneticisi · Genel Yayın Yönetmeni · Editör · Yazar · Muhabir ·
  Otomasyon Editörü · Vitrin ve SEO Editörü · Reklam Yöneticisi · Topluluk Moderatörü · Üye
- **Düzenlenebilir izin matrisi** (36 izin × 10 rol) — panelden değiştirilir, ancak
  yönetici-eşdeğeri **7 izin kilitlidir**: `ai.configure` `ads.html` `theme.css`
  `settings.manage` `users.manage` `roles.manage` `tools.manage`. Bu izinler için `can()`
  veritabanına **hiç bakmaz** — hazırlanmış bir yedeği geri yüklemek yetki yükseltmeye dönüşmez
- Kullanıcı başına ek izin (`user_grants`) ve masa editörü kategori kapsamı (`user_categories`)
- **Tehlikeli izinler bölündü**: `ads.manage` → `ads.schedule` + `ads.creative` + `ads.html`(kilitli);
  `theme.manage` → `theme.manage` + `theme.css`(kilitli); `rss.manage` → `rss.manage` + `rss.autopublish`
- **Reklam türü ayrımı**: görsel reklam (güvenli kurulum, `rel="nofollow noopener sponsored"`)
  ve ham HTML/JS reklam — ikincisi **yönetici onayı olmadan ön yüzde basılmaz**, kod değişince onay sıfırlanır
- **Sorumlu yazı işleri müdürü işareti** (`users.is_responsible`): işaretli etkin bir kullanıcı
  varsa düzeltme/cevap yayımlama yetkisi **yalnız o kişide** çalışır (Basın Kanunu m.14 şahsa bağlı yükümlülük)
- **Üyelik**: kayıt, giriş, e-posta doğrulama (token yalnız SHA-256 özeti olarak saklanır),
  parola sıfırlama, profil, kaydedilen haberler, yorum geçmişi, abonelik ekranı
- **Premium içerik kilidi**: `posts.visibility` (`public|members|premium`) tek kapıdan uygulanır
  (`post_body_html()`), temalara bırakılmaz. Süresi dolan abonelik **cron olmadan** free'ye düşer
- **Muhabir hızlı giriş ekranı**: mobil öncelikli tek form, kamera erişimi, 20 sn'de bir otomatik
  taslak, bağlantı koparsa `localStorage` yedeği ve şebeke gelince gönderim
- Yorumlar üyeliğe bağlandı: oturum açmış üyenin adı ve e-postası **hesabından** alınır
  (formdan gelen değer yok sayılır — kimlik taklidi engellenir)
- Panel ekranları: Roller ve İzinler · Üyeler · Hızlı Giriş
- Denetim kaydı (`audit_log`): izin değişiklikleri ve reklam onayları kayda geçer

### Araçlar
- Uçtan uca test paketi (gerçek sunucu + curl) — **268 kontrol**
- `tests/demo-seed.php` dolu demo veri, `tests/dev-install.php` geliştirme kurulumu
- Demo tohumu Faz 7 yüzeyini de kurar: 10 rolün tamamı için personel hesabı,
  üç üyelik durumu (ücretsiz / geçerli abonelik / süresi dolmuş) ve iki kilitli
  haber (`members` ve `premium`) — ödeme duvarı kurulumdan hemen sonra denenebilir
- `tools/build-dist.php` dağıtım paketi üreticisi (paketlemeden önce `php -l` kapısı)
