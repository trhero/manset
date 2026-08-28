<?php
/**
 * YZ sağlayıcı kataloğu (1.4).
 *
 * Katalog, "yeni sağlayıcı eklemek tek satır olsun" diye kuruldu. Bu testlerin
 * işi, o vaadin arkasındaki sessiz kırılma noktalarını tutmaktır: bir sağlayıcı
 * katalogda görünüp API doğrulamasında reddedilirse, yayıncı onu seçtiğini
 * sanır ve istek BAŞKA bir sağlayıcıya gider.
 */

if (!defined('MANSET_BOOTSTRAPPED')) { fwrite(STDERR, "php tests/unit/run.php ile çalıştırın.
"); exit(1); }
require_once INC_DIR . '/ai.php';

test('katalog: her sağlayıcı zorunlu alanları taşır', function () {
    $liste = ai_providers();
    dogru(count($liste) >= 8, 'katalogda en az 8 sağlayıcı olmalı, var: ' . count($liste));
    foreach ($liste as $k => $d) {
        dogru(isset($d['ad']) && $d['ad'] !== '', $k . ': ad boş');
        dogru(in_array(arr($d, 'protokol', ''), ['anthropic', 'openai'], true), $k . ': protokol geçersiz');
        dogru(preg_match('#^https?://#', (string)arr($d, 'base', '')) === 1, $k . ': base adres geçersiz');
        dogru(trim((string)arr($d, 'model', '')) !== '', $k . ': varsayılan model boş');
    }
});

test('katalog: OpenRouter tanımlı ve OpenAI protokolü konuşuyor', function () {
    $liste = ai_providers();
    dogru(isset($liste['openrouter']), 'openrouter katalogda olmalı');
    esit('openai', $liste['openrouter']['protokol']);
    icerir($liste['openrouter']['base'], 'openrouter.ai');
});

test('seçilen her sağlayıcı GERÇEKTEN seçili kalır', function () {
    // Sessiz geri düşme sınıfı: API beyaz listesi katalogla aynı kaynaktan
    // beslenmezse, seçim kaydedilir gibi görünüp anthropic'e döner.
    foreach (array_keys(ai_providers()) as $k) {
        setting_set('ai_provider', $k);
        settings_all(true);
        esit($k, ai_provider(), $k . ' seçimi korunmadı');
    }
    setting_set('ai_provider', 'anthropic');
    settings_all(true);
});

test('bilinmeyen sağlayıcı güvenli tarafa düşer', function () {
    setting_set('ai_provider', 'olmayan-saglayici');
    settings_all(true);
    esit('anthropic', ai_provider());
    setting_set('ai_provider', 'anthropic');
    settings_all(true);
});

test('taban adres: ayar boşsa katalogdan, doluysa ayardan', function () {
    setting_set('ai_provider', 'openrouter');
    setting_set('ai_base_url', '');
    settings_all(true);
    icerir(ai_base_url(), 'openrouter.ai');

    setting_set('ai_base_url', 'https://vekil.ornek.test/v1/');
    settings_all(true);
    esit('https://vekil.ornek.test/v1', ai_base_url(), 'sondaki eğik çizgi kırpılmalı');

    setting_set('ai_base_url', '');
    setting_set('ai_provider', 'anthropic');
    settings_all(true);
});

test('model: ayar boşsa sağlayıcının varsayılanı', function () {
    setting_set('ai_model', '');
    foreach (['anthropic', 'openrouter', 'groq'] as $k) {
        setting_set('ai_provider', $k);
        settings_all(true);
        $bekl = ai_providers()[$k]['model'];
        esit($bekl, ai_model(), $k . ' varsayılan modeli');
    }
    setting_set('ai_provider', 'anthropic');
    settings_all(true);
});

test('Ollama anahtar istemez, diğerleri ister', function () {
    setting_set('ai_provider', 'ollama');
    settings_all(true);
    yanlis(ai_needs_key(), 'ollama yerel çalışır, anahtar istememeli');

    setting_set('ai_provider', 'openrouter');
    settings_all(true);
    dogru(ai_needs_key(), 'openrouter anahtar ister');

    setting_set('ai_provider', 'anthropic');
    settings_all(true);
});

test('protokol eşlemesi: yalnız anthropic kendi protokolünü konuşur', function () {
    foreach (ai_providers() as $k => $d) {
        setting_set('ai_provider', $k);
        settings_all(true);
        $bekl = ($k === 'anthropic') ? 'anthropic' : 'openai';
        esit($bekl, ai_protocol(), $k . ' protokolü');
    }
    setting_set('ai_provider', 'anthropic');
    settings_all(true);
});
