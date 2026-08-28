# BİK / Basın Kanunu Notları — Manşet için gereksinim çıkarımı

> **Uyarı:** Bu belge, Manşet'in künye ve düzeltme-cevap modüllerini tasarlarken
> başvurulan kaynakların özetidir; hukuki görüş değildir. Mevzuat değişir.
> **Yayıncı, yürürlükteki güncel metni resmî kaynaktan teyit etmekle yükümlüdür.**
> Bu nedenle Manşet'te künye alanlarının tamamı panelden düzenlenebilir ve
> yayıncı kendi ihtiyacına göre alan ekleyip çıkarabilir.
>
> Derleme tarihi: 22 Ağustos 2026.

---

## 1. Kaynaklar

| Kaynak | Konu |
|---|---|
| 5187 sayılı Basın Kanunu (7418 sayılı Kanun ile 18.10.2022'de değişik) | İnternet haber siteleri süreli yayın sayıldı; zorunlu bilgiler m.4, düzeltme-cevap m.14 |
| Basın İlan Kurumu — Resmî İlan ve Reklam Yönetmeliği (RG 01.02.2023) | Resmî ilan alma şartları |
| BİK — "İnternet Haber Sitelerinin Uyacakları Teknik Kurallara İlişkin Usul ve Esaslar" | Teknik standartlar; gözden geçirilmiş hâli **1 Haziran 2024**'te yürürlüğe girdi |

---

## 2. Ana sayfadan doğrudan ulaşılabilir "İletişim" başlığı altında bulunması gerekenler

Basın Kanunu m.4'e göre internet haber sitelerinde **ana sayfadan doğrudan
ulaşılabilecek** ve **iletişim başlığı altında** bulundurulması gerekenler:

| Alan | Manşet ayar anahtarı |
|---|---|
| Faaliyet gösterilen iş yeri adresi | `kunye_adres` |
| Ticari unvan | `kunye_ticaret_unvani` |
| Elektronik posta adresi | `kunye_eposta` |
| İletişim telefonu | `kunye_telefon` |
| Elektronik tebligat (KEP/UETS) adresi | `kunye_kep` |
| Yer sağlayıcısının (hosting) adı ve adresi | `kunye_hosting`, `kunye_hosting_adres` |

Süreli yayın olmanın genel gereklerinden gelen ve uygulamada künyede yer alan diğer alanlar:

| Alan | Manşet ayar anahtarı |
|---|---|
| Yayının adı | `kunye_yayin_adi` |
| İmtiyaz sahibi | `kunye_imtiyaz_sahibi` |
| Sorumlu yazı işleri müdürü | `kunye_sorumlu_mudur` |
| Yayın türü | `kunye_yayin_turu` |
| Vergi dairesi / vergi no | `kunye_vergi_dairesi`, `kunye_vergi_no` |
| MERSİS no | `kunye_mersis` |
| Ek notlar (serbest alan) | `kunye_ek_notlar` |

**Manşet'teki karşılığı**
* `/kunye` yolu her temada alt bilgiden **doğrudan** bağlanır (ana sayfadan tek tıkla erişim).
* Alanlar `settings` tablosunda `kunye_*` anahtarlarıyla tutulur, Panel → Künye/BİK ekranından düzenlenir.
* Boş bırakılan alan künyede **basılmaz** (yanlış/uydurma bilgi görünmez).
* Panelde eksik zorunlu alanlar için uyarı listesi gösterilir.

---

## 3. İçerik tarihi zorunluluğu

Basın Kanunu m.4: *"İnternet haber sitelerinde bir içeriğin ilk kez sunulmaya
başlandığı tarih ile sonraki güncelleme tarihleri … içeriğin üzerinde belirtilir."*

**Manşet'teki karşılığı — tüm temalar için zorunlu:**
`single.php` içinde `published_at` **ve** (farklıysa) `updated_at` görünür biçimde basılır:

```php
<time datetime="<?= esc($post['published_at']) ?>"><?= esc(tr_date($post['published_at'])) ?></time>
<?php if (!empty($post['updated_at']) && strtotime($post['updated_at']) > strtotime($post['published_at']) + 120): ?>
  <span class="guncelleme">Güncelleme: <time datetime="<?= esc($post['updated_at']) ?>"><?= esc(tr_date($post['updated_at'])) ?></time></span>
<?php endif; ?>
```
(120 saniyelik tolerans, kaydetme anındaki küçük farkın "güncellendi" gibi görünmesini önler.)

---

## 4. Düzeltme ve cevap hakkı (m.14)

* Sorumlu müdür, düzeltme ve cevap yazısını **aldığı tarihten itibaren en geç bir gün içinde**
  yayımlamak zorundadır.
* Yayım biçimi: **URL bağlantısı sağlanarak, aynı puntolarla ve aynı şekilde**.
* Engelleme/karar durumunda metin, ilgili internet haber sitesinde **ilk 24 saat ana sayfada**
  olmak üzere **bir hafta** süreyle yayımlanır.

**Manşet'teki karşılığı**
* Haber altında "Düzeltme talep et" formu → `corrections` tablosu (`status`: `pending|answered|rejected`).
* Panelde her talep için **süre göstergesi**: `created_at + 1 gün` yaklaşırken/aşılınca uyarı rozeti.
* "Düzeltmeyi yayımla" eylemi: düzeltme metnini ilgili haberin altına ekler **ve**
  isteğe bağlı olarak anasayfada 24 saat / bir hafta görünür kılar
  (`corrections.publish_at`, `corrections.homepage_until` — migration ile eklenecek).
* Düzeltme metni yayımlandığında ilgili habere URL bağlantısı verilir.

---

## 5. BİK teknik esasları — Manşet'in karşıladığı / karşılamadığı

| Esas | Manşet durumu |
|---|---|
| Güncel SSL/TLS sertifikası | **Hosting sorumluluğu** — Manşet HTTPS'i algılar (`base_url()`, secure cookie), sertifikayı sağlamaz. README'de belirtilir. |
| Kaynak kodun erişilebilir olması | ✅ Açık kaynak (MIT) |
| Meta açıklama / anahtar kelimelerin içerikle uyumlu olması | ✅ SEO alanları haberden üretilir; uydurma anahtar kelime doldurulmaz |
| Geçiş reklamlarının ana sayfa, resmî ilan ve iletişim sayfası kontrollerini engellememesi | ✅ Reklam slotları sabit; tam ekran geçiş reklamı slotu **bilinçli olarak yok** |
| Ana sayfanın başka alan adına yönlendirmemesi | ✅ Yönlendirici yalnız kanonik slug düzeltmesi için 301 verir |
| Kullanıcının aldatıcı biçimde başka siteye yönlendirilmemesi | ✅ Dış bağlantılar `rel="noopener"`, gövde temizleyicide `javascript:` yasak |
| İçerik tarihi ve arşiv | ✅ `published_at`/`updated_at` + kategori/etiket/arama arşivleri + sitemap |
| Ziyaretçi trafiğinin manipüle edilmemesi (1 sn altı oturum sayılmaz, bot tetiklemesi yasak) | ✅ `post_register_view()` oturum bazlı tekilleştirme yapar; yapay sayaç artırma özelliği **yoktur** |

---

## 6. Manşet'e düşen görevler (bu belgeden çıkan iş kalemleri)

1. **Ajan-9:** Künye ekranında yukarıdaki alan listesi; eksik alan uyarısı; `/kunye` çıktısı;
   `corrections` tablosuna `publish_at` + `homepage_until` migration'ı; süre göstergeli talep ekranı.
2. **Ajan-6 ve Ajan-7:** Tüm temalarda `single.php` içinde güncelleme tarihi (§3);
   alt bilgide `/kunye` bağlantısı; anasayfada aktif düzeltme metni bloğu (varsa).
3. **README:** "Yayıncı yürürlükteki güncel yönetmeliği teyit etmelidir" notu + SSL'in
   hosting sorumluluğunda olduğu uyarısı.
