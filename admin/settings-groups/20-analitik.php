<?php
/**
 * Ayar grubu — Analitik (1.2-01, Ajan-A).
 *
 * İki ayrı şey burada: (a) kendi çerezsiz ölçümümüzün saklama ayarları,
 * (b) yayıncı zorunlu kalırsa takacağı DIŞ ölçümcünün kodu.
 *
 * ---------------------------------------------------------------------------
 * NEDEN DIŞ KOD ALANI `settings.manage` İSTER — `analytics.view` YETMEZ
 * ---------------------------------------------------------------------------
 * `analytics.view` bir OKUMA iznidir; SEO editörü ve reklam sorumlusu da
 * taşır (CONTRACTS 1.2 §4). Bu alana yazılan şey ise sitenin her sayfasında
 * çalışacak bir <script>'tir — yani reklam HTML'i ve tema özel CSS'iyle
 * TAM OLARAK aynı sınıfta bir yetki yükseltme yüzeyi: oraya yazılan kod
 * yönetici oturumunu çalabilir. Rapor okuyabilen herkesin siteye kod
 * enjekte edebilmesi, izin ayrımını anlamsız kılardı.
 *
 * `settings.manage` KİLİTLİ bir izindir (CONTRACTS §12.5): yalnız `admin`
 * rolündedir ve veritabanından geçersiz kılınamaz. Grup `perm` alanı
 * sayesinde alan, yetkisi olmayana HİÇ BASILMAZ.
 *
 * ---------------------------------------------------------------------------
 * NEDEN `clamp` İÇİNDE ELLE OKUNUYOR
 * ---------------------------------------------------------------------------
 * admin/pages/settings.php `textarea` tipini `sanitize_plain()` ile kaydeder;
 * o da `strip_tags()` uygular — yani bir <script> parçacığı kaydedildiği anda
 * yok olurdu. Alanın doğası gereği HAM olması gerekiyor, ama çatının genel
 * kuralını gevşetmek DİĞER tüm textarea alanlarını da açardı. Bu yüzden ham
 * değer yalnız burada, yalnız bu anahtar için, kendi izin kapısıyla yeniden
 * okunuyor. `clamp` POST işlenirken çalıştığı için istek gövdesi elimizdedir.
 */

return [
    'key'   => 'analitik',
    'title' => 'Analitik ve ölçüm',
    'perm'  => 'settings.manage',
    'note'  => 'Kendi ölçümümüz çerez kullanmaz ve ham IP saklamaz; '
             . 'ziyaretçi tekilleştirmesi günlük değişen bir tuzla üretilen kısa özetle yapılır.',
    'fields' => [
        'analytics_enabled' => ['bool', 'Okunma ölçümü açık',
            'Kapatılırsa haber görüntülemeleri hiç kaydedilmez (mevcut veri silinmez).'],
        'analytics_detail_days' => ['number', 'Haber kırılımı kaç gün saklansın',
            'Bu süreden eski günlerde tek tek haber ayrıntısı gün toplamına katlanır. 7–400.'],
        'analytics_keep_days' => ['number', 'Günlük satırlar kaç gün saklansın',
            'Bu süreden eski her şey silinir. 30–1200.'],
        'analytics_external_on' => ['bool', 'Dış analitik kodunu bas',
            'Plausible / Matomo / Google Analytics gibi bir ölçümcü kullanıyorsanız açın.'],
        'analytics_external_code' => ['textarea', 'Dış analitik kodu (HAM)',
            'Sağlayıcının verdiği <script> parçacığını olduğu gibi yapıştırın. '
          . 'Buraya yazılan kod sitenin HER sayfasında çalışır — yalnız güvendiğiniz '
          . 'sağlayıcının kodunu yapıştırın. KVKK: dış ölçümcüler genellikle çerez '
          . 'kullanır; çerez bildiriminizi buna göre güncellemeniz gerekir.'],
    ],

    'clamp' => function (array $saved) {
        // Sayısal sınırlar (inc/analytics.php ile aynı aralık).
        if (array_key_exists('analytics_detail_days', $saved)) {
            $saved['analytics_detail_days'] = (string)max(7, min(400, (int)$saved['analytics_detail_days']));
        }
        if (array_key_exists('analytics_keep_days', $saved)) {
            $saved['analytics_keep_days'] = (string)max(30, min(1200, (int)$saved['analytics_keep_days']));
        }

        // HAM kod alanı — kendi izin kapısı. Yetki yoksa alan hiç basılmamıştır,
        // dolayısıyla mevcut değer de DEĞİŞTİRİLMEZ (dizinden çıkarılır).
        if (!can(current_user(), 'settings.manage')) {
            unset($saved['analytics_external_code']);
            return $saved;
        }
        if (!array_key_exists('analytics_external_code', $saved)) { return $saved; }

        $ham = (string)inp('analytics_external_code', '');
        // Yalnız denetim karakterleri ve boyut sınırı. İçerik bilinçli olarak
        // temizlenmez: sağlayıcı kodu bozulursa ölçüm sessizce ölür ve yayıncı
        // nedenini bulamaz. Kapı burada değil, İZİNDE (yalnız admin).
        $ham = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $ham);
        if (strlen($ham) > 8000) { $ham = substr($ham, 0, 8000); }
        $saved['analytics_external_code'] = trim($ham);
        return $saved;
    },
];
