<?php
/**
 * Ayar grubu — Arama (1.3-06, Ajan-A).
 *
 * İki anahtar var, ikisi de bilinçli olarak "kapatılabilir":
 *
 *  - `search_fts_enabled` — tam metin dizinini kullan. Kapatıldığında arama
 *    1.2'deki LIKE sorgusuna döner (başlık + spot + etiket). Yayıncının bunu
 *    kapatabilmesi gerekir: dizin bozulduğunda ya da paylaşımlı hostingde
 *    beklenmedik bir davranış çıktığında, aramanın ÇALIŞIR durumda kalması
 *    "iyi çalışması"ndan önce gelir.
 *  - `search_highlight` — sonuç listesinde eşleşen sözcüğü işaretle.
 *
 * `perm` = `settings.manage` (KİLİTLİ izin, yalnız admin). Yeni izin
 * EKLENMEDİ (1.3 sözleşmesi §4).
 */

return [
    'key'   => 'arama',
    'title' => 'Arama',
    'perm'  => 'settings.manage',
    'note'  => 'Tam metin dizini haberin GÖVDESİNİ de arar. Dizin, haber '
             . 'eklendiğinde/düzenlendiğinde/silindiğinde veritabanı '
             . 'tetikleyicisiyle aynı anda güncellenir; elle yeniden kurmak '
             . 'gerekmez. Kurulumunuz tam metni desteklemiyorsa arama sessizce '
             . 'eski yöntemle (başlık + spot + etiket) çalışır.',
    'fields' => [
        'search_fts_enabled' => ['bool', 'Tam metin araması açık',
            'Kapatılırsa arama yalnız başlık, spot ve etikette yapılır.'],
        'search_highlight' => ['bool', 'Eşleşen sözcükleri sonuçta işaretle',
            'Sonuç listesinde aranan sözcük vurgulanır.'],
    ],

    'clamp' => function (array $saved) {
        // Ayar değişince motor önbelleği geçersizdir; ayrıca açılırken dizin
        // henüz kurulmamış olabilir (göç öncesi kapatılıp sonra açılan kurulum).
        if (array_key_exists('search_fts_enabled', $saved) && function_exists('search_engine_reset')) {
            $saved['search_fts_enabled'] = $saved['search_fts_enabled'] === '1' ? '1' : '0';
            search_engine_reset();
            if ($saved['search_fts_enabled'] === '1' && function_exists('search_index_ensure')) {
                // Dizin yoksa ya da boşsa (kapalıyken eklenen haberler değil —
                // tetikleyiciler kapalıyken de çalışır — ama göç öncesi kurulmuş
                // bir kopyada tablo hiç olmayabilir) kurulur ve doldurulur.
                if (search_index_ensure() === 'fts5'
                    && (int)qv('SELECT COUNT(*) FROM search_index', [], 0) === 0) {
                    search_index_rebuild();
                }
            }
        }
        return $saved;
    },
];
