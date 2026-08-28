<?php
/**
 * Panel — haberler (Ajan-2).
 *
 * İki kip:
 *   Liste kipi  : ?p=posts            (filtre, toplu işlem, sayfalama)
 *   Editör kipi : ?p=posts&duzenle=ID · ?p=posts&yeni=1
 *
 * Rol kuralları (aynıları inc/api/admin.php içinde sunucu tarafında da uygulanır):
 *   · posts.edit_any yoksa yalnız kendi haberleri listelenir/düzenlenir.
 *   · posts.publish yoksa yayın/zamanlama seçilemez, kayıt 'pending' olur.
 *   · posts.delete yoksa silme düğmesi görünmez.
 *   · 1.1-09 — silme yumuşaktır. Liste çöptekileri göstermez; ?durum=cop çöp kutusudur.
 *     Kalıcı silme yalnız admin rolüne görünür (uç: posts.purge).
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
require_can('posts.view');

$adminPageTitle = 'Haberler';
$me = current_user();

$hepsiniDuzenle = can($me, 'posts.edit_any');
$yayinlayabilir = can($me, 'posts.publish');
$silebilir      = can($me, 'posts.delete');
$olusturabilir  = can($me, 'posts.create');
// Kalıcı silme rol kapısı — inc/api/admin.php posts.purge ucundaki kuralın aynısı.
$kaliciSilebilir = $silebilir && (string)arr($me, 'role', '') === 'admin';
$copVar          = function_exists('published_supports_trash') && published_supports_trash();

// 1.1-02 — kataloğa yazılıp hiçbir kapıya bağlanmamış izinler burada bağlanıyor.
$telDuzenler   = can($me, 'posts.edit_wire');   // ajans/YZ kaynaklı haberler
$ustveriKipi   = can($me, 'posts.seo') && !$hepsiniDuzenle && !can($me, 'posts.edit_own');
$yayindanCeker = can($me, 'posts.takedown');

$editId = inp_i('duzenle', 0);
$yeni   = inp_i('yeni', 0) === 1;

/** Haber ajans/YZ kaynaklı mı? (inc/api/admin.php · api_admin_is_wire_post ile aynı ölçüt) */
$telHaberMi = function ($p) {
    return (int)arr($p, 'ai_generated', 0) === 1 || trim((string)arr($p, 'source_url', '')) !== '';
};

// ---------------------------------------------------------------- editör kipi verisi
$post = null;
if ($editId > 0) {
    $post = q1('SELECT * FROM posts WHERE id = :i', [':i' => $editId]);
    if (!$post) {
        flash('err', 'Haber bulunamadı.');
        header('Location: ' . admin_url('posts'), true, 302);
        exit;
    }
    // Kapı sunucu tarafındaki api_admin_load_post() ile aynı: sahiplik YA DA
    // ajans/YZ kaynağı (posts.edit_wire) YA DA üstveri kipi (posts.seo).
    if (!$hepsiniDuzenle
        && (int)$post['author_id'] !== (int)$me['id']
        && !($telDuzenler && $telHaberMi($post))
        && !$ustveriKipi) {
        flash('err', 'Yalnız kendi haberlerinizi düzenleyebilirsiniz.');
        header('Location: ' . admin_url('posts'), true, 302);
        exit;
    }
}
if ($yeni && !$olusturabilir) {
    flash('err', 'Haber oluşturma yetkiniz yok.');
    header('Location: ' . admin_url('posts'), true, 302);
    exit;
}
$editorModu = ($post !== null) || $yeni;

$kategoriler = qa('SELECT id, name FROM categories ORDER BY sort ASC, name ASC');
$kategoriSecenek = [];
foreach ($kategoriler as $c) { $kategoriSecenek[(int)$c['id']] = $c['name']; }

$turler = ['haber' => 'Haber', 'makale' => 'Makale', 'video' => 'Video'];

$durumEtiket = [
    'draft'     => 'Taslak',
    'pending'   => 'Onaya gönder',
    'published' => 'Yayınla',
    'scheduled' => 'Zamanla',
    'archived'  => 'Arşivle',
];
?>

<?php if (!$editorModu): ?>
<?php
// ================================================================ LİSTE KİPİ

$fDurum  = (string)inp('durum', '');
$fKat    = inp_i('kategori', 0);
$fYazar  = inp_i('yazar', 0);
$fYz     = inp_i('yz', 0) === 1;
$fArama  = sanitize_line((string)inp('q', ''), 120);
// 1.3-11 — gövde içi arama, tarih aralığı, etiket süzgeci.
$fGovde  = inp_i('govde', 0) === 1;
$fEtiket = sanitize_line((string)inp('etiket', ''), 60);
/** 'YYYY-AA-GG' değilse boş döner — tarih girdisi doğrudan SQL'e gitmesin diye. */
$tarihSuz = function ($ham) {
    $ham = trim((string)$ham);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ham) ? $ham : '';
};
$fBas    = $tarihSuz(inp('bas', ''));
$fBit    = $tarihSuz(inp('bit', ''));
$sayfaNo = max(1, inp_i('sayfa', 1));
$perPage = 20;

$durumSecenek = [
    'draft' => 'Taslak', 'pending' => 'Onay bekliyor', 'scheduled' => 'Zamanlanmış',
    'published' => 'Yayında', 'archived' => 'Arşiv',
];

// ?durum=cop → çöp kutusu kipi. 'cop' bir status değeri DEĞİL, ayrı bir görünüm.
$copKip = ($copVar && $fDurum === 'cop');

$where = ['1 = 1'];
$prm = [];
if (!$hepsiniDuzenle) {
    // Otomasyon editörü (posts.edit_wire) kendi haberlerinin YANINDA ajans/YZ kaynaklı
    // haberleri de görür: AI ve RSS taslaklarının yazarı daima ilk yönetici yazılıyor,
    // yoksa kendi iş kolunun çıktısı listesine hiç düşmüyordu. SEO editörü (üstveri
    // kipi) ise üstveriyi düzenlemek için tüm haberleri görmek zorunda.
    if ($ustveriKipi) {
        // süzgeç yok — yalnız üstveri düzenlenebildiği için tüm liste görünür
    } elseif ($telDuzenler) {
        $where[] = '(p.author_id = :me OR p.ai_generated = 1 OR p.source_url <> :bosKaynak)';
        $prm[':me'] = (int)$me['id'];
        $prm[':bosKaynak'] = '';
    } else {
        $where[] = 'p.author_id = :me';
        $prm[':me'] = (int)$me['id'];
    }
}
if ($copVar) {
    // Panel listesinde çöp görünmez; çöp kutusunda ise YALNIZ çöp görünür.
    $where[] = $copKip ? 'p.deleted_at <> :bos' : 'p.deleted_at = :bos';
    $prm[':bos'] = '';
}
if (!$copKip && $fDurum !== '' && isset($durumSecenek[$fDurum])) { $where[] = 'p.status = :st'; $prm[':st'] = $fDurum; }
if ($fKat > 0)   { $where[] = 'p.category_id = :cid'; $prm[':cid'] = $fKat; }
if ($fYazar > 0 && $hepsiniDuzenle) { $where[] = 'p.author_id = :aid'; $prm[':aid'] = $fYazar; }
if ($fYz)        { $where[] = 'p.ai_generated = 1'; }
if ($fArama !== '') {
    // Gövde içi arama İSTEĞE BAĞLI: `body` üzerinde LIKE indeks kullanamaz ve
    // arşiv büyüdükçe tam tarama olur. Tam metin arama (FTS5 / FULLTEXT) 1.3'te
    // ayrı bir iş kolunun konusu; buradaki kutu, editörün "metnin içinde geçiyordu"
    // dediği haberi bulabilmesi için ölçülü bir açık kapıdır.
    $where[] = $fGovde
        ? '(p.title LIKE :q OR p.slug LIKE :q OR p.spot LIKE :q OR p.body LIKE :q)'
        : '(p.title LIKE :q OR p.slug LIKE :q OR p.spot LIKE :q)';
    $prm[':q'] = '%' . str_replace(['%', '_'], ' ', $fArama) . '%';
}
if ($fEtiket !== '') {
    // `tags` virgülle ayrılmış tek alan (1.3'te normalizasyon ayrı bir iş kolunda).
    // Kısmi eşleşme kabul edilir; joker karakterler etkisizleştirilir.
    $where[] = 'p.tags LIKE :etiket';
    $prm[':etiket'] = '%' . str_replace(['%', '_'], ' ', $fEtiket) . '%';
}
// Tarih aralığı yayın tarihine, yoksa oluşturma tarihine bakar — listenin
// sıralama ölçütüyle (COALESCE(published_at, created_at)) aynı olsun.
if ($fBas !== '') {
    $where[] = 'COALESCE(p.published_at, p.created_at) >= :bas';
    $prm[':bas'] = $fBas . ' 00:00:00';
}
if ($fBit !== '') {
    $where[] = 'COALESCE(p.published_at, p.created_at) <= :bit';
    $prm[':bit'] = $fBit . ' 23:59:59';
}
$sqlWhere = ' WHERE ' . implode(' AND ', $where);

$toplam = (int)qv('SELECT COUNT(*) FROM posts p' . $sqlWhere, $prm, 0);

// Çöp sekmesi rozeti — kendi haberleriyle sınırlı kullanıcıda yalnız kendi çöpü sayılır.
$copSayi = 0;
if ($copVar) {
    $copWhere = 'WHERE p.deleted_at <> :bos';
    $copPrm = [':bos' => ''];
    if (!$hepsiniDuzenle) { $copWhere .= ' AND p.author_id = :me'; $copPrm[':me'] = (int)$me['id']; }
    $copSayi = (int)qv('SELECT COUNT(*) FROM posts p ' . $copWhere, $copPrm, 0);
}
$copSutun = $copVar ? ' p.deleted_at,' : '';
// Düzenleme kilidi sütunları göç 028 ile gelir; yoksa listede rozet çizilmez.
$kilitVar = function_exists('revisions_ready') && revisions_ready();
$kilitSutun = $kilitVar ? ' p.locked_by, p.locked_at,' : '';

$haberler = qa('SELECT p.id, p.title, p.slug, p.status, p.type, p.image, p.category_id, p.author_id,
        p.ai_generated, p.view_count, p.published_at, p.created_at, p.is_headline,' . $copSutun . $kilitSutun . '
        c.name AS category_name, c.color AS category_color, u.name AS author_name
    FROM posts p
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN users u ON u.id = p.author_id'
    . $sqlWhere . ' ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC
    LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($sayfaNo - 1) * $perPage), $prm);

$yazarlar = $hepsiniDuzenle
    ? qa('SELECT id, name FROM users WHERE id IN (SELECT DISTINCT author_id FROM posts) ORDER BY name ASC')
    : [];
$yazarSecenek = [];
foreach ($yazarlar as $y) { $yazarSecenek[(int)$y['id']] = $y['name']; }

$filtreQuery = array_filter([
    'durum' => $fDurum, 'kategori' => $fKat ?: '', 'yazar' => $fYazar ?: '',
    'yz' => $fYz ? 1 : '', 'q' => $fArama,
    'govde' => $fGovde ? 1 : '', 'etiket' => $fEtiket, 'bas' => $fBas, 'bit' => $fBit,
], function ($v) { return $v !== '' && $v !== 0; });

// Toplu üstveri atama (1.3-11) — yalnız tam düzenleme yetkisi olanda.
$topluAtama = $hepsiniDuzenle && !$copKip
    && function_exists('revisions_ready') && revisions_ready();
?>

<div class="sayfa-basligi">
  <h1><?= $copKip ? 'Çöp kutusu' : 'Haberler' ?> <span class="soluk kucuk">(<?= (int)$toplam ?>)</span></h1>
  <?php if ($olusturabilir && !$copKip): ?>
    <a class="dugme birincil" href="<?= esc(admin_url('posts', ['yeni' => 1])) ?>">+ Yeni haber</a>
  <?php endif; ?>
</div>

<?php if ($copVar): ?>
  <div class="sekmeler" aria-label="Liste görünümü">
    <a class="<?= $copKip ? '' : 'etkin' ?>" href="<?= esc(admin_url('posts')) ?>"
       <?= $copKip ? '' : 'aria-current="page"' ?>>Haberler</a>
    <a class="<?= $copKip ? 'etkin' : '' ?>" href="<?= esc(admin_url('posts', ['durum' => 'cop'])) ?>"
       <?= $copKip ? 'aria-current="page"' : '' ?>>Çöp <span class="sekme-sayi"><?= (int)$copSayi ?></span></a>
  </div>
<?php endif; ?>

<?php if ($copKip): ?>
  <div class="uyari bilgi mini">
    Silinen haberler burada bekler; ön yüzde görünmezler. <strong>Geri al</strong> ile eski hâline döner.
    <?php if ($kaliciSilebilir): ?>
      <strong>Kalıcı sil</strong> haberi yorumlarıyla birlikte geri dönüşsüz siler.
    <?php else: ?>
      Kalıcı silme yalnız yöneticide.
    <?php endif; ?>
  </div>
<?php endif; ?>

<form class="arac-cubugu" method="get" action="<?= esc(base_url()) ?>/admin/">
  <input type="hidden" name="p" value="posts">
  <?php if ($copKip): ?>
    <input type="hidden" name="durum" value="cop">
  <?php else: ?>
    <select name="durum" aria-label="Durum"><?= admin_options($durumSecenek, $fDurum, 'Tüm durumlar') ?></select>
  <?php endif; ?>
  <select name="kategori" aria-label="Kategori"><?= admin_options($kategoriSecenek, $fKat, 'Tüm kategoriler') ?></select>
  <?php if ($hepsiniDuzenle && $yazarSecenek): ?>
    <select name="yazar" aria-label="Yazar"><?= admin_options($yazarSecenek, $fYazar, 'Tüm yazarlar') ?></select>
  <?php endif; ?>
  <label class="onay-satir" style="margin:0">
    <input type="checkbox" name="yz" value="1" <?= $fYz ? 'checked' : '' ?>>
    <span class="kucuk">Yalnız YZ üretimi</span>
  </label>
  <input type="search" name="q" value="<?= esc($fArama) ?>" placeholder="Başlık, slug, spot…" aria-label="Haber ara">
  <label class="onay-satir" style="margin:0" title="Haber metninin içinde de arar (büyük arşivde yavaş olabilir).">
    <input type="checkbox" name="govde" value="1" <?= $fGovde ? 'checked' : '' ?>>
    <span class="kucuk">Gövdede de ara</span>
  </label>
  <input type="text" name="etiket" value="<?= esc($fEtiket) ?>" placeholder="Etiket…" aria-label="Etikete göre süz" size="12">
  <label class="kucuk soluk" style="margin:0">Başlangıç
    <input type="date" name="bas" value="<?= esc($fBas) ?>" aria-label="Başlangıç tarihi">
  </label>
  <label class="kucuk soluk" style="margin:0">Bitiş
    <input type="date" name="bit" value="<?= esc($fBit) ?>" aria-label="Bitiş tarihi">
  </label>
  <button type="submit" class="dugme">Filtrele</button>
  <?php if ($filtreQuery): ?>
    <a class="dugme" href="<?= esc(admin_url('posts')) ?>">Sıfırla</a>
  <?php endif; ?>
</form>

<?php if (!$haberler): ?>
  <div class="bos-durum">
    <span class="buyuk" aria-hidden="true"><?= $copKip ? '🗑' : '✎' ?></span>
    <h3><?= $copKip ? 'Çöp kutusu boş' : ($filtreQuery ? 'Filtreye uyan haber yok' : 'Henüz haber yok') ?></h3>
    <p><?= $copKip ? 'Silinen haberler burada görünür.'
                   : ($filtreQuery ? 'Filtreleri sıfırlayıp tekrar deneyin.' : 'İlk haberinizi oluşturun.') ?></p>
    <?php if ($olusturabilir && !$copKip): ?>
      <a class="dugme birincil" href="<?= esc(admin_url('posts', ['yeni' => 1])) ?>">+ Yeni haber</a>
    <?php endif; ?>
  </div>
<?php else: ?>

  <?php if ($hepsiniDuzenle && !$copKip): ?>
    <div class="arac-cubugu">
      <label class="onay-satir" style="margin:0">
        <input type="checkbox" id="tumunuSec" aria-label="Tümünü seç">
        <span class="kucuk">Tümünü seç</span>
      </label>
      <select id="topluIslem" aria-label="Toplu işlem">
        <option value="">Toplu işlem…</option>
        <?php if ($yayinlayabilir): ?><option value="publish">Yayınla</option><?php endif; ?>
        <option value="draft">Taslağa al</option>
        <option value="archive">Arşivle</option>
        <?php if ($silebilir): ?><option value="delete">Sil</option><?php endif; ?>
      </select>
      <button type="button" class="dugme" id="topluUygula">Uygula</button>
      <span class="sag kucuk soluk" id="secimSayisi"></span>
    </div>
  <?php endif; ?>

  <?php if ($topluAtama): ?>
    <?php /* Toplu kategori / etiket atama (1.3-11).
             YIKICI OLABİLİR: kaç kayda uygulanacağı ekranda yazar ve onay istenir;
             sunucu da `confirm` ve seçim sayısı eşleşmesi arar. Her kayda önce
             sürüm yazılır, yani işlem geri alınabilir. */ ?>
    <details class="kart" id="topluAtamaKart">
      <summary class="kart-baslik" style="cursor:pointer">Toplu kategori / etiket atama</summary>
      <div class="arac-cubugu bosluk-ust">
        <select id="atamaKategori" aria-label="Atanacak kategori">
          <option value="">Kategoriye dokunma</option>
          <option value="0">Kategorisiz yap</option>
          <?= admin_options($kategoriSecenek, '__yok__') ?>
        </select>
        <input type="text" id="atamaEtiket" placeholder="etiket1, etiket2" aria-label="Atanacak etiketler">
        <select id="atamaEtiketKip" aria-label="Etiket işlemi">
          <option value="add">Etiket ekle</option>
          <option value="remove">Etiket çıkar</option>
          <option value="replace">Etiketleri değiştir (siler)</option>
        </select>
        <button type="button" class="dugme" id="atamaUygula">Uygula</button>
      </div>
      <p class="form-yardim">
        Seçili haberlere uygulanır. <strong>Etiketleri değiştir</strong> mevcut etiketleri siler;
        diğer iki seçenek eklemeli/çıkarmalıdır. Her haberin önceki hâli sürüm geçmişine yazılır,
        yani işlem tek tek geri alınabilir. Başka bir editörün açık düzenlemesi olan haberler atlanır.
      </p>
    </details>
  <?php endif; ?>

  <div class="tablo-sarma">
    <table class="tablo">
      <thead>
        <tr>
          <?php if ($hepsiniDuzenle && !$copKip): ?><th class="dar"><span class="gizli">Seç</span></th><?php endif; ?>
          <th class="dar"><span class="gizli">Görsel</span></th>
          <th>Başlık</th>
          <th class="dar">Kategori</th>
          <th class="dar">Yazar</th>
          <th class="dar">Durum</th>
          <th class="dar">Tarih</th>
          <th class="dar">Görüntülenme</th>
          <th class="islem">İşlem</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($haberler as $h):
          list($etiket, $ton) = admin_status_label((string)$h['status']); ?>
          <tr>
            <?php if ($hepsiniDuzenle && !$copKip): ?>
              <td class="dar"><input type="checkbox" class="haber-sec" value="<?= (int)$h['id'] ?>"
                                     aria-label="<?= esc($h['title']) ?> seç"></td>
            <?php endif; ?>
            <td class="dar">
              <?php if ($h['image'] !== ''): ?>
                <img class="kucuk-gorsel" src="<?= esc(post_image($h, 'thumb')) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="kucuk-gorsel" aria-hidden="true"></span>
              <?php endif; ?>
            </td>
            <td>
              <a href="<?= esc(admin_url('posts', ['duzenle' => (int)$h['id']])) ?>"><strong><?= esc($h['title']) ?></strong></a>
              <?php if ((int)$h['ai_generated'] === 1): ?> <?= admin_badge('YZ', 'ai') ?><?php endif; ?>
              <?php if ((int)$h['is_headline'] === 1): ?> <?= admin_badge('manşet', 'bilgi') ?><?php endif; ?>
              <?php if ($h['type'] !== 'haber'): ?> <?= admin_badge($turler[$h['type']] ?? $h['type'], 'notr') ?><?php endif; ?>
              <?php
              // "Başkası düzenliyor" rozeti — süresi dolmuş kilit gösterilmez.
              if ($kilitVar && (int)arr($h, 'locked_by', 0) > 0
                  && (int)$h['locked_by'] !== (int)$me['id']
                  && (string)arr($h, 'locked_at', '') !== ''
                  && strtotime((string)$h['locked_at']) >= time() - revisions_lock_ttl()) {
                  $kimDuzenler = (string)qv('SELECT name FROM users WHERE id = :i', [':i' => (int)$h['locked_by']], '');
                  echo ' ' . admin_badge('düzenleniyor' . ($kimDuzenler !== '' ? ': ' . $kimDuzenler : ''), 'uyari');
              }
              ?>
              <span class="satir-alt tek-satir">/<?= esc($h['slug']) ?></span>
            </td>
            <td class="dar"><?= esc(arr($h, 'category_name', '') ?: '—') ?></td>
            <td class="dar"><?= esc(arr($h, 'author_name', '') ?: '—') ?></td>
            <td class="dar"><?= admin_badge($etiket, $ton) ?></td>
            <td class="dar mini soluk" title="<?= esc(tr_date($h['published_at'] ?: $h['created_at'])) ?>">
              <?= esc(tr_ago($h['published_at'] ?: $h['created_at'])) ?>
            </td>
            <td class="dar"><?= number_format((int)$h['view_count'], 0, ',', '.') ?></td>
            <td class="islem">
              <?php if ($copKip): ?>
                <button type="button" class="dugme kucuk" data-haber-geri="<?= (int)$h['id'] ?>">Geri al</button>
                <?php if ($kaliciSilebilir): ?>
                  <button type="button" class="dugme kucuk tehlike" data-haber-yoket="<?= (int)$h['id'] ?>"
                          data-onay="“<?= esc($h['title']) ?>” KALICI olarak silinecek (yorumlarıyla birlikte). Bu işlem geri alınamaz. Onaylıyor musunuz?">Kalıcı sil</button>
                <?php endif; ?>
              <?php else: ?>
                <?php if ($h['status'] === 'published'): ?>
                  <a class="dugme kucuk" href="<?= esc(url_post($h)) ?>" target="_blank" rel="noopener" title="Ön yüzde aç">↗</a>
                <?php endif; ?>
                <a class="dugme kucuk" href="<?= esc(admin_url('posts', ['duzenle' => (int)$h['id']])) ?>"><?= $ustveriKipi ? 'Üstveri' : 'Düzenle' ?></a>
                <?php if ($yayindanCeker && $h['status'] === 'published'): ?>
                  <button type="button" class="dugme kucuk" data-haber-kaldir="<?= (int)$h['id'] ?>"
                          data-onay="“<?= esc($h['title']) ?>” yayından kaldırılacak (arşive alınır, silinmez). Onaylıyor musunuz?">Yayından kaldır</button>
                <?php endif; ?>
                <?php if ($silebilir): ?>
                  <button type="button" class="dugme kucuk tehlike" data-haber-sil="<?= (int)$h['id'] ?>"
                          data-onay="“<?= esc($h['title']) ?>” çöp kutusuna taşınacak. Onaylıyor musunuz?">Sil</button>
                <?php endif; ?>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?= admin_paginate($toplam, $perPage, $sayfaNo, 'posts', $filtreQuery) ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function secilenler() { return M.qsa('.haber-sec:checked').map(function (c) { return parseInt(c.value, 10); }); }

  function sayiYaz() {
    var el = document.getElementById('secimSayisi');
    if (el) { var n = secilenler().length; el.textContent = n ? n + ' seçili' : ''; }
  }
  var hepsi = document.getElementById('tumunuSec');
  if (hepsi) {
    hepsi.addEventListener('change', function () {
      M.qsa('.haber-sec').forEach(function (c) { c.checked = hepsi.checked; });
      sayiYaz();
    });
  }
  M.qsa('.haber-sec').forEach(function (c) { c.addEventListener('change', sayiYaz); });

  var uygula = document.getElementById('topluUygula');
  if (uygula) {
    uygula.addEventListener('click', function () {
      var islem = document.getElementById('topluIslem').value;
      if (!islem) { M.toast('warn', 'Önce bir işlem seçin.'); return; }
      var ids = secilenler();
      if (!ids.length) { M.toast('warn', 'Hiç haber seçmediniz.'); return; }
      if (islem === 'delete' && !M.onay(ids.length + ' haber kalıcı olarak silinecek. Onaylıyor musunuz?')) { return; }
      uygula.disabled = true;
      M.api('posts.bulk', { ids: ids, action: islem }).then(function (r) {
        if (M.sonuc(r, 'İşlem tamam.')) { setTimeout(function () { location.reload(); }, 500); }
        else { uygula.disabled = false; }
      });
    });
  }

  /* -------------------------------------------------- toplu kategori/etiket atama (1.3-11) */
  var atamaDugme = document.getElementById('atamaUygula');
  if (atamaDugme) {
    atamaDugme.addEventListener('click', function () {
      var ids = secilenler();
      if (!ids.length) { M.toast('warn', 'Önce haber seçin.'); return; }

      var katHam = document.getElementById('atamaKategori').value;
      var etiket = document.getElementById('atamaEtiket').value.trim();
      var kip = document.getElementById('atamaEtiketKip').value;
      if (katHam === '' && etiket === '' && kip !== 'replace') {
        M.toast('warn', 'Uygulanacak bir değişiklik seçmediniz.'); return;
      }

      /* ONAY METNİ KAÇ KAYDA UYGULANACAĞINI YAZAR — toplu işlem yıkıcı olabilir. */
      var satir = [];
      if (katHam !== '') {
        var s = document.getElementById('atamaKategori');
        satir.push('kategori → ' + (katHam === '0' ? 'Kategorisiz' : s.options[s.selectedIndex].text));
      }
      if (etiket !== '' || kip === 'replace') {
        satir.push(kip === 'add' ? ('etiket eklenecek: ' + etiket)
                 : (kip === 'remove' ? ('etiket çıkarılacak: ' + etiket)
                 : ('MEVCUT ETİKETLER SİLİNİP yerine yazılacak: ' + (etiket || '(boş)'))));
      }
      if (!M.onay(ids.length + ' habere uygulanacak:\n· ' + satir.join('\n· ')
        + '\n\nHer haberin önceki hâli sürüm geçmişine yazılacak (geri alınabilir).\nOnaylıyor musunuz?')) { return; }

      var istek = { ids: ids, confirm: true, expected: ids.length, tag_mode: kip };
      if (katHam !== '') { istek.category_id = parseInt(katHam, 10) || 0; }
      if (etiket !== '' || kip === 'replace') { istek.tags = etiket; }

      atamaDugme.disabled = true;
      M.api('posts.bulk_assign', istek).then(function (r) {
        if (M.sonuc(r, 'Atama tamam.')) { setTimeout(function () { location.reload(); }, 600); }
        else { atamaDugme.disabled = false; }
      });
    });
  }

  /* Çöp kutusu işlemleri — onay diyalogu admin.js'teki data-onay kancasından gelir. */
  function satirIslemi(nitelik, uc, mesaj) {
    M.qsa('[' + nitelik + ']').forEach(function (b) {
      b.addEventListener('click', function () {
        b.disabled = true;
        M.api(uc, { id: parseInt(b.getAttribute(nitelik), 10) }).then(function (r) {
          if (M.sonuc(r, mesaj)) { setTimeout(function () { location.reload(); }, 450); }
          else { b.disabled = false; }
        });
      });
    });
  }
  satirIslemi('data-haber-sil', 'posts.delete', 'Haber çöp kutusuna taşındı.');
  satirIslemi('data-haber-geri', 'posts.restore', 'Haber geri alındı.');
  satirIslemi('data-haber-yoket', 'posts.purge', 'Haber kalıcı olarak silindi.');
  satirIslemi('data-haber-kaldir', 'posts.takedown', 'Haber yayından kaldırıldı.');
});
</script>

<?php else: ?>
<?php
// ================================================================ EDİTÖR KİPİ

$v = function ($key, $default = '') use ($post) {
    return $post !== null ? (string)arr($post, $key, $default) : $default;
};
$durumMevcut = $post ? (string)$post['status'] : 'draft';
if (!$yayinlayabilir && in_array($durumMevcut, ['published', 'scheduled'], true)) { $durumMevcut = 'pending'; }

$durumSecenekleri = $yayinlayabilir
    ? $durumEtiket
    : ['draft' => 'Taslak', 'pending' => 'Onaya gönder'];
if (!$yayinlayabilir && $post && in_array((string)$post['status'], ['published', 'scheduled', 'archived'], true)) {
    // Yayındaki bir habere sonradan bakan yazar, mevcut durumu görebilsin (ama seçemez)
    $durumSecenekleri[(string)$post['status']] = $durumEtiket[(string)$post['status']] . ' (yetki yok)';
}

$yayinTarihi = $v('published_at');
$yayinTarihiLocal = $yayinTarihi !== '' ? str_replace(' ', 'T', substr($yayinTarihi, 0, 16)) : '';
$gorsel = $v('image');

/* 1.3-01 / 1.3-02 — sürüm geçmişi, otomatik kayıt, düzenleme kilidi, notlar.
   Göç 028 uygulanmamışsa (ya da yeni haberde, henüz id yokken) tüm blok
   sessizce yok sayılır; editör 1.2'deki gibi çalışır. */
$surumVar   = function_exists('revisions_ready') && revisions_ready() && $post !== null;
$otoKayit   = $surumVar && !$ustveriKipi;          // üstveri kipi gövdeye dokunamaz
$geriYukler = $otoKayit && ($hepsiniDuzenle || can($me, 'posts.edit_own') || $telDuzenler);
$yayinlayan = $surumVar ? revisions_publisher_name($post) : '';
?>

<script src="<?= esc(url_asset('editor.js')) ?>?v=<?= esc(MANSET_VERSION) ?>" defer></script>
<?php if ($surumVar): ?>
<script src="<?= esc(url_asset('revisions.js')) ?>?v=<?= esc(MANSET_VERSION) ?>"></script>
<?php endif; ?>

<div class="sayfa-basligi">
  <h1><?= $post ? 'Haberi düzenle' : 'Yeni haber' ?></h1>
  <div class="dugme-grup">
    <a class="dugme" href="<?= esc(admin_url('posts')) ?>">← Liste</a>
    <?php if ($post): ?>
      <a class="dugme" id="onYuzBag" href="<?= esc(url_post($post)) ?>" target="_blank" rel="noopener">Ön yüzde gör ↗</a>
    <?php endif; ?>
    <?php if ($post && $yayindanCeker && (string)$post['status'] === 'published'): ?>
      <button type="button" class="dugme" data-haber-kaldir="<?= (int)$post['id'] ?>"
              data-onay="“<?= esc($post['title']) ?>” yayından kaldırılacak (arşive alınır, silinmez). Onaylıyor musunuz?">Yayından kaldır</button>
    <?php endif; ?>
    <?php if ($post && $silebilir): ?>
      <button type="button" class="dugme tehlike" data-haber-sil="<?= (int)$post['id'] ?>"
              data-onay="“<?= esc($post['title']) ?>” çöp kutusuna taşınacak. Onaylıyor musunuz?">Sil</button>
    <?php endif; ?>
    <button type="submit" form="haberForm" class="dugme birincil"><?= $ustveriKipi ? 'Üstveriyi kaydet' : 'Kaydet' ?></button>
  </div>
</div>

<?php if ($surumVar): ?>
  <?php /* Düzenleme kilidi uyarısı — içeriği revisions.js doldurur. */ ?>
  <div id="kilitUyari" hidden></div>
<?php endif; ?>

<?php if ($ustveriKipi): ?>
  <div class="uyari warn mini">
    <strong>Üstveri kipi.</strong> Bu ekranda yalnız adres, görsel, etiket ve SEO alanlarını
    düzenleyebilirsiniz. Başlık, spot, haber metni ve yayın durumu <strong>kilitlidir</strong> —
    kaydettiğinizde değişmez.
  </div>
<?php elseif (!$yayinlayabilir): ?>
  <div class="uyari bilgi mini">
    Yayımlama yetkiniz yok. Kaydettiğiniz haber <strong>onay kuyruğuna</strong> düşer; bir editör yayımlar.
  </div>
<?php endif; ?>

<form id="haberForm" class="iki-kolon" autocomplete="off">
  <input type="hidden" name="id" value="<?= (int)($post ? $post['id'] : 0) ?>">

  <!-- ------------------------------------------------ sol kolon -->
  <div>
    <div class="kart">
      <div class="form-alan">
        <label for="h_title">Başlık</label>
        <input type="text" id="h_title" name="title" required maxlength="255" value="<?= esc($v('title')) ?>"
               placeholder="Haberin başlığı"<?= $ustveriKipi ? ' readonly' : '' ?>>
      </div>

      <div class="form-alan">
        <label for="h_slug">Adres (slug)</label>
        <div class="esnek">
          <input type="text" id="h_slug" name="slug" maxlength="200" value="<?= esc($v('slug')) ?>" style="flex:1">
          <button type="button" class="dugme kucuk" id="slugYenile" title="Başlıktan yeniden üret">↻</button>
        </div>
        <span class="form-yardim">Boş bırakılırsa başlıktan üretilir.</span>
        <div id="slugUyari" class="uyari warn mini bosluk-ust" hidden></div>
      </div>

      <div class="form-alan">
        <label for="h_spot">Spot</label>
        <textarea id="h_spot" name="spot" rows="3" maxlength="300"<?= $ustveriKipi ? ' readonly' : '' ?>
                  placeholder="Haberin özeti — liste ve kartlarda görünür."><?= esc($v('spot')) ?></textarea>
        <div class="sayac-metin"><span id="spotSayac">0</span> / 300</div>
      </div>
    </div>

    <div class="kart<?= $ustveriKipi ? ' ustveri-kilit' : '' ?>">
      <div class="kart-baslik">Haber metni <?= $ustveriKipi ? '<span class="soluk kucuk">· kilitli</span>' : '' ?></div>
      <textarea id="h_body" name="body" rows="18"<?= $ustveriKipi ? ' readonly' : '' ?>><?= esc($v('body')) ?></textarea>
    </div>

    <div class="kart">
      <div class="kart-baslik">Kaynak</div>
      <div class="form-satir">
        <div class="form-alan">
          <label for="h_source_name">Kaynak adı</label>
          <input type="text" id="h_source_name" name="source_name" maxlength="190" value="<?= esc($v('source_name')) ?>">
        </div>
        <div class="form-alan">
          <label for="h_source_url">Kaynak adresi</label>
          <input type="url" id="h_source_url" name="source_url" maxlength="500" value="<?= esc($v('source_url')) ?>"
                 placeholder="https://…">
        </div>
      </div>
      <span class="form-yardim">Yapay zekâ ile üretilen haberlerde kaynak zorunludur.</span>
    </div>
  </div>

  <!-- ------------------------------------------------ sağ kolon -->
  <div>
    <div class="kart">
      <div class="kart-baslik">Yayın</div>
      <div class="form-alan">
        <label for="h_status">Durum</label>
        <select id="h_status" name="status"<?= $ustveriKipi ? ' disabled' : '' ?>><?= admin_options($durumSecenekleri, $durumMevcut) ?></select>
      </div>
      <div class="form-alan" id="tarihAlani">
        <label for="h_published_at">Yayın tarihi</label>
        <input type="datetime-local" id="h_published_at" name="published_at" value="<?= esc($yayinTarihiLocal) ?>"<?= $ustveriKipi ? ' disabled' : '' ?>>
        <span class="form-yardim">“Zamanla” seçilirse gelecekte bir tarih girin; cron zamanı gelince yayımlar.</span>
      </div>
      <div class="form-alan">
        <label for="h_type">Tür</label>
        <select id="h_type" name="type"<?= $ustveriKipi ? ' disabled' : '' ?>><?= admin_options($turler, $v('type', 'haber')) ?></select>
      </div>
      <?php if (function_exists('post_visibility_levels')): ?>
      <div class="form-alan">
        <label for="h_visibility">Kimler okuyabilir</label>
        <select id="h_visibility" name="visibility"<?= $ustveriKipi ? ' disabled' : '' ?>><?= admin_options([
          'public'  => 'Herkes',
          'members' => 'Yalnız üyeler',
          'premium' => 'Yalnız aboneler',
        ], $v('visibility', 'public')) ?></select>
        <span class="form-yardim">Kilitli içerikte ziyaretçiye yalnız önizleme gösterilir.
          Personel her zaman tam metni görür.</span>
      </div>
      <div class="form-alan" id="teaserAlani"<?= $v('visibility', 'public') === 'public' ? ' hidden' : '' ?>>
        <label for="h_teaser">Önizleme metni</label>
        <textarea id="h_teaser" name="teaser" rows="3" maxlength="600"<?= $ustveriKipi ? ' readonly' : '' ?>><?= esc($v('teaser', '')) ?></textarea>
        <span class="form-yardim">Boş bırakılırsa gövdenin ilk paragrafı kullanılır.
          Arama motorlarına da bu metin gider.</span>
      </div>
      <?php endif; ?>
      <div class="form-alan">
        <label for="h_category">Kategori</label>
        <select id="h_category" name="category_id"<?= $ustveriKipi ? ' disabled' : '' ?>><?= admin_options($kategoriSecenek, (int)$v('category_id', '0'), 'Kategorisiz') ?></select>
        <?php if (!$kategoriSecenek && can($me, 'categories.manage')): ?>
          <span class="form-yardim">Henüz kategori yok — <a href="<?= esc(admin_url('categories')) ?>">kategori ekleyin</a>.</span>
        <?php endif; ?>
      </div>
      <div class="dugme-grup">
        <button type="submit" class="dugme birincil">Kaydet</button>
      </div>
    </div>

    <div class="kart">
      <div class="kart-baslik">Öne çıkan görsel</div>
      <input type="hidden" name="image" id="h_image" value="<?= esc($gorsel) ?>">
      <div class="gorsel-onizleme" id="gorselOnizleme" <?= $gorsel === '' ? 'hidden' : '' ?>>
        <img id="gorselImg" src="<?= esc($gorsel !== '' ? post_image($post, 'medium') : '') ?>" alt="">
      </div>
      <p class="soluk kucuk" id="gorselYok" <?= $gorsel !== '' ? 'hidden' : '' ?>>Henüz görsel seçilmedi.</p>
      <div class="dugme-grup bosluk-ust">
        <button type="button" class="dugme" id="gorselSec">Kütüphaneden seç</button>
        <button type="button" class="dugme" id="gorselKaldir" <?= $gorsel === '' ? 'disabled' : '' ?>>Kaldır</button>
      </div>
    </div>

    <div class="kart">
      <div class="kart-baslik">Etiketler</div>
      <div class="form-alan">
        <label for="h_tags" class="gizli">Etiketler</label>
        <input type="text" id="h_tags" name="tags" maxlength="500" value="<?= esc($v('tags')) ?>"
               placeholder="deprem, afad, kandilli">
        <span class="form-yardim">Virgülle ayırın. En çok 15 etiket.</span>
      </div>
    </div>

    <div class="kart">
      <div class="kart-baslik">Arama motoru (SEO)</div>
      <div class="form-alan">
        <label for="h_seo_title">SEO başlığı</label>
        <input type="text" id="h_seo_title" name="seo_title" maxlength="255" value="<?= esc($v('seo_title')) ?>"
               placeholder="Boş bırakılırsa haber başlığı kullanılır">
        <div class="olcek" id="seoBaslikOlcek"><span style="width:0"></span></div>
        <div class="sayac-metin"><span id="seoBaslikSayac">0</span> / 60 önerilen</div>
      </div>
      <div class="form-alan">
        <label for="h_seo_desc">SEO açıklaması</label>
        <textarea id="h_seo_desc" name="seo_desc" rows="3" maxlength="500"
                  placeholder="Boş bırakılırsa spot kullanılır"><?= esc($v('seo_desc')) ?></textarea>
        <div class="olcek" id="seoAcikOlcek"><span style="width:0"></span></div>
        <div class="sayac-metin"><span id="seoAcikSayac">0</span> / 155 önerilen</div>
      </div>
      <div class="seo-olcer" id="seoOlcer" aria-live="polite">
        <div class="seo-satir"><span class="seo-nokta" data-olcut="baslik"></span><span data-metin="baslik">Başlık uzunluğu</span></div>
        <div class="seo-satir"><span class="seo-nokta" data-olcut="aciklama"></span><span data-metin="aciklama">Açıklama uzunluğu</span></div>
        <div class="seo-satir"><span class="seo-nokta" data-olcut="gorsel"></span><span data-metin="gorsel">Öne çıkan görsel</span></div>
        <div class="seo-satir"><span class="seo-nokta" data-olcut="spot"></span><span data-metin="spot">Spot metni</span></div>
        <div class="seo-satir"><span class="seo-nokta" data-olcut="etiket"></span><span data-metin="etiket">Etiketler</span></div>
      </div>

      <?php /* SERP / paylaşım önizlemesi (Ajan-E, 1.2-06).
               Yazar başlığı yazarken Google'da ve paylaşımda ne göreceğini
               burada görür. Metinler önce tarayıcıda kurulur (anında tepki),
               sonra `seo.preview` ucundan SUNUCUNUN gerçek çıktısıyla
               (başlık şablonu + kırpma kuralları) tazelenir. */ ?>
      <div class="bosluk-ust" id="serpKart" hidden>
        <div class="kart-baslik">Önizleme</div>
        <div class="sekmeler" style="margin-bottom:8px">
          <button type="button" class="etkin" data-onizleme="serp">Google</button>
          <button type="button" data-onizleme="og">Paylaşım</button>
        </div>

        <div data-onizleme-govde="serp" style="border:1px solid var(--cizgi,#ddd);border-radius:8px;padding:12px">
          <div class="mini soluk tek-satir" id="serpUrl"></div>
          <div id="serpBaslik" style="color:#1a0dab;font-size:17px;line-height:1.3;margin:2px 0 3px"></div>
          <div class="kucuk" id="serpAcik" style="line-height:1.45"></div>
        </div>

        <div data-onizleme-govde="og" hidden style="border:1px solid var(--cizgi,#ddd);border-radius:8px;overflow:hidden;max-width:360px">
          <div id="ogGorsel" style="background:#eee;aspect-ratio:1200/630;background-size:cover;background-position:center"></div>
          <div style="padding:8px 10px">
            <div class="mini soluk tek-satir" id="ogAlan"></div>
            <div style="font-weight:600;line-height:1.3" id="ogBaslik"></div>
            <div class="kucuk soluk" id="ogAcik" style="line-height:1.4"></div>
          </div>
        </div>
        <span class="form-yardim">Google başlığı ve açıklamayı kendi kurallarıyla kısaltabilir; bu önizleme yaklaşıktır.</span>
      </div>
    </div>

    <?php if ($surumVar): ?>
    <div class="kart">
      <div class="kart-baslik">Sürüm geçmişi</div>
      <?php if ($otoKayit): ?>
        <p class="form-yardim" id="otoKayitDurum">
          Otomatik kayıt <?= (int)revisions_autosave_interval() ?> saniyede bir çalışır.
          <strong>Canlı metni değiştirmez</strong> — yalnız sürüm geçmişine yazar.
        </p>
      <?php endif; ?>
      <div id="surumGecmis"><p class="soluk kucuk">Yükleniyor…</p></div>
    </div>

    <div class="kart">
      <div class="kart-baslik">Notlar ve onay</div>
      <?php if ($yayinlayan !== ''): ?>
        <p class="mini soluk">Yayımlayan: <?= esc($yayinlayan) ?></p>
      <?php endif; ?>
      <div id="haberNotlar"><p class="soluk kucuk">Yükleniyor…</p></div>
    </div>
    <?php endif; ?>

    <div class="kart">
      <div class="kart-baslik">Yapay zekâ yardımcıları</div>
      <div class="dugme-grup">
        <?php if (!$ustveriKipi): ?>
          <button type="button" class="dugme kucuk" id="yzBaslik">Başlık öner</button>
          <button type="button" class="dugme kucuk" id="yzSpot">Spot öner</button>
        <?php endif; ?>
        <button type="button" class="dugme kucuk" id="yzSeo">SEO doldur</button>
      </div>
      <span class="form-yardim">Yapay zekâ kapalıysa düğmeler uyarı gösterir; haber akışı etkilenmez.</span>
    </div>
  </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('haberForm');
  var listeAdres = <?= json_encode(admin_url('posts')) ?>;
  var duzenleSablon = <?= json_encode(admin_url('posts', ['duzenle' => '__ID__'])) ?>;
  var yuklemeKok = (window.MANSET && MANSET.uploads) || '';
  var siteAdi = <?= json_encode((string)setting('site_title', 'Manşet'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var siteKok = <?= json_encode(base_url(), JSON_UNESCAPED_SLASHES) ?>;
  var haberOnEk = <?= json_encode(rtrim(base_url(), '/') . '/haber/', JSON_UNESCAPED_SLASHES) ?>;

  // Üstveri kipinde (posts.seo) gövde kilitlidir: zengin editör hiç bağlanmaz,
  // yoksa contenteditable alan readonly textarea'nın kilidini fiilen deler.
  var ustveriKipi = <?= $ustveriKipi ? 'true' : 'false' ?>;

  /* NEDEN (denetim 3 · cop-izin B08): posts.save üstveri kipinde GÖNDERİLMEYEN
     alanları boşaltıyor (seo_title/seo_desc/tags için sunucuda yedek yok) ve boş
     `slug` gelince adresi başlıktan YENİDEN üretip slug_history'ye yeni bir 301
     halkası ekliyor. Bu yüzden arayüz, kısmi gönderim yapmayacağına dair güvenceyi
     kendi tarafında verir: aşağıdaki sunucu üretimi yedek, isteğe her seferinde
     TÜM üstveri alanlarının bir değerle gitmesini sağlar (alan silinmiş, boş
     bırakılmış ya da devre dışı olsa bile mevcut değer taşınır).
     Slug'ı elle boşaltıp başlıktan yeniden üretmek isteyen editör ↻ düğmesini
     kullanır — sessiz yeniden üretim böylece kazayla olmaz. */
  var ustveriYedek = <?= json_encode([
      'slug'      => $v('slug'),
      'tags'      => $v('tags'),
      'image'     => $gorsel,
      'seo_title' => $v('seo_title'),
      'seo_desc'  => $v('seo_desc'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var editor = (window.MansetEditor && !ustveriKipi)
    ? MansetEditor.baglat(document.getElementById('h_body'), { bos: 'Haber metnini buraya yazın…' })
    : null;
  var kirli = M.kirliTakip(form);

  var elTitle = document.getElementById('h_title');
  var elSlug = document.getElementById('h_slug');
  var elSpot = document.getElementById('h_spot');
  var elTags = document.getElementById('h_tags');
  var elImage = document.getElementById('h_image');
  var elSeoTitle = document.getElementById('h_seo_title');
  var elSeoDesc = document.getElementById('h_seo_desc');
  var elStatus = document.getElementById('h_status');
  var elId = M.qs('input[name=id]', form);

  /* ------------------------------------------------ sürüm geçmişi / kilit (1.3-01) */
  var surumVar = <?= $surumVar ? 'true' : 'false' ?>;
  var otoKayit = <?= $otoKayit ? 'true' : 'false' ?>;
  var surumler = null;

  function govdeMetni() {
    return editor ? editor.html() : document.getElementById('h_body').value;
  }

  if (surumVar && window.MansetRevisions) {
    surumler = MansetRevisions.baglat({
      postId: parseInt(elId.value, 10) || 0,
      userId: <?= (int)$me['id'] ?>,
      interval: <?= (int)(function_exists('revisions_autosave_interval') ? revisions_autosave_interval() : 30) ?>,
      kilitEl: document.getElementById('kilitUyari'),
      gecmisEl: document.getElementById('surumGecmis'),
      notEl: document.getElementById('haberNotlar'),
      durumEl: document.getElementById('otoKayitDurum'),
      geriYukleyebilir: <?= $geriYukler ? 'true' : 'false' ?>,
      yayinlayabilir: <?= $yayinlayabilir ? 'true' : 'false' ?>,
      /* Otomatik kayda giden alanlar. `status` ve `published_at` BİLEREK YOK:
         anlık görüntüye veritabanındaki gerçek yayın durumu yazılır, yoksa bir
         otomatik kayıt geri yüklendiğinde yayın durumu da geri sarılırdı. */
      oku: function () {
        return {
          title: elTitle.value, slug: elSlug.value, spot: elSpot.value,
          body: govdeMetni(), tags: elTags.value, image: elImage.value,
          seo_title: elSeoTitle.value, seo_desc: elSeoDesc.value,
          category_id: parseInt(document.getElementById('h_category').value, 10) || 0,
          type: document.getElementById('h_type').value,
          teaser: (document.getElementById('h_teaser') || { value: '' }).value,
          source_name: (document.getElementById('h_source_name') || { value: '' }).value,
          source_url: (document.getElementById('h_source_url') || { value: '' }).value
        };
      }
    });

    if (otoKayit && surumler) {
      // Her yazma girişi otomatik kaydı "kirli" işaretler; zamanlayıcı ilk
      // dokunuşta kurulur, dokunulmayan sekme sunucuya hiç istek atmaz.
      M.qsa('input, textarea, select', form).forEach(function (a) {
        a.addEventListener('input', surumler.isaretle);
        a.addEventListener('change', surumler.isaretle);
      });
      var govdeAlan = document.getElementById('h_body');
      if (govdeAlan && govdeAlan.parentNode) {
        govdeAlan.parentNode.addEventListener('input', surumler.isaretle);
      }
    }
  }

  /* ------------------------------------------------ slug */
  function slugla(s) {
    var harita = { 'ğ':'g','Ğ':'g','ü':'u','Ü':'u','ş':'s','Ş':'s','ı':'i','İ':'i','ö':'o','Ö':'o','ç':'c','Ç':'c','â':'a','î':'i','û':'u' };
    return String(s || '').replace(/[ğĞüÜşŞıİöÖçÇâîû]/g, function (c) { return harita[c] || c; })
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }
  var slugElle = elSlug.value !== '';
  var slugUyari = document.getElementById('slugUyari');
  var slugZaman;

  function slugDenetle() {
    clearTimeout(slugZaman);
    slugZaman = setTimeout(function () {
      var deger = elSlug.value.trim() || elTitle.value.trim();
      if (!deger) { slugUyari.hidden = true; return; }
      M.api('posts.slug_check', { slug: elSlug.value, title: elTitle.value, id: parseInt(elId.value, 10) || 0 })
        .then(function (r) {
          if (!r.ok) { return; }
          if (r.available) { slugUyari.hidden = true; return; }
          slugUyari.innerHTML = 'Bu adres kullanımda. Önerilen: <strong>' + M.esc(r.suggestion) + '</strong> '
            + '<button type="button" class="dugme kucuk" id="slugOneriKullan">Kullan</button>';
          slugUyari.hidden = false;
          var b = document.getElementById('slugOneriKullan');
          if (b) {
            b.addEventListener('click', function () {
              elSlug.value = r.suggestion; slugElle = true; slugUyari.hidden = true;
            });
          }
        });
    }, 420);
  }

  elSlug.addEventListener('input', function () { slugElle = true; slugDenetle(); });
  elTitle.addEventListener('input', function () {
    if (!slugElle) { elSlug.value = slugla(elTitle.value); }
    slugDenetle();
    seoGuncelle();
  });
  document.getElementById('slugYenile').addEventListener('click', function () {
    elSlug.value = slugla(elTitle.value);
    slugElle = false;
    slugDenetle();
  });

  /* ------------------------------------------------ sayaçlar + SEO ölçer */
  function olcekAyarla(kutu, uzunluk, hedefMin, hedefMax) {
    var cubuk = kutu.querySelector('span');
    var oran = Math.min(100, Math.round((uzunluk / hedefMax) * 100));
    cubuk.style.width = oran + '%';
    kutu.classList.remove('orta', 'kotu');
    if (uzunluk === 0 || uzunluk > hedefMax) { kutu.classList.add('kotu'); }
    else if (uzunluk < hedefMin) { kutu.classList.add('orta'); }
  }

  function noktaAyarla(olcut, seviye, metin) {
    var nokta = M.qs('.seo-nokta[data-olcut=' + olcut + ']');
    var yazi = M.qs('[data-metin=' + olcut + ']');
    if (!nokta) { return; }
    nokta.classList.remove('iyi', 'orta', 'kotu');
    nokta.classList.add(seviye);
    if (yazi && metin) { yazi.textContent = metin; }
  }

  function seoGuncelle() {
    var spotUz = elSpot.value.length;
    document.getElementById('spotSayac').textContent = spotUz;

    var seoBaslik = elSeoTitle.value.trim() || elTitle.value.trim();
    var seoAcik = elSeoDesc.value.trim() || elSpot.value.trim();

    document.getElementById('seoBaslikSayac').textContent = seoBaslik.length;
    document.getElementById('seoAcikSayac').textContent = seoAcik.length;
    olcekAyarla(document.getElementById('seoBaslikOlcek'), seoBaslik.length, 30, 60);
    olcekAyarla(document.getElementById('seoAcikOlcek'), seoAcik.length, 70, 155);

    noktaAyarla('baslik',
      (seoBaslik.length >= 30 && seoBaslik.length <= 60) ? 'iyi' : (seoBaslik.length ? 'orta' : 'kotu'),
      'Başlık uzunluğu: ' + seoBaslik.length + ' karakter');
    noktaAyarla('aciklama',
      (seoAcik.length >= 70 && seoAcik.length <= 155) ? 'iyi' : (seoAcik.length ? 'orta' : 'kotu'),
      'Açıklama uzunluğu: ' + seoAcik.length + ' karakter');
    noktaAyarla('gorsel', elImage.value ? 'iyi' : 'kotu',
      elImage.value ? 'Öne çıkan görsel seçili' : 'Öne çıkan görsel yok');
    noktaAyarla('spot', elSpot.value.trim() ? 'iyi' : 'kotu',
      elSpot.value.trim() ? 'Spot metni girildi' : 'Spot metni boş');
    var etiketSayi = elTags.value.split(',').filter(function (t) { return t.trim() !== ''; }).length;
    noktaAyarla('etiket', etiketSayi >= 2 ? 'iyi' : (etiketSayi === 1 ? 'orta' : 'kotu'),
      etiketSayi + ' etiket');

    onizlemeGuncelle(seoBaslik, seoAcik);
  }

  /* ------------------------------------------------ SERP / paylaşım önizlemesi */
  var serpKart = document.getElementById('serpKart');
  var onizlemeZaman = null;

  function onizlemeYaz(baslik, acik, tamBaslik) {
    if (!serpKart) { return; }
    var slug = (elSlug.value.trim() || slugla(elTitle.value) || 'ornek-adres');
    document.getElementById('serpUrl').textContent = haberOnEk + slug;
    document.getElementById('serpBaslik').textContent = tamBaslik || (baslik + ' — ' + siteAdi);
    document.getElementById('serpAcik').textContent = acik || 'Açıklama girilmedi; spot metni kullanılacak.';
    document.getElementById('ogAlan').textContent = siteKok.replace(/^https?:\/\//, '');
    document.getElementById('ogBaslik').textContent = baslik;
    document.getElementById('ogAcik').textContent = acik;
    var g = elImage.value ? (yuklemeKok + elImage.value) : '';
    document.getElementById('ogGorsel').style.backgroundImage = g ? 'url("' + g.replace(/"/g, '%22') + '")' : '';
  }

  function onizlemeGuncelle(baslik, acik) {
    if (!serpKart) { return; }
    serpKart.hidden = (baslik === '');
    if (baslik === '') { return; }
    onizlemeYaz(baslik, acik, '');
    // Sunucudaki GERÇEK çıktıyla tazele: başlık şablonu ve kırpma kuralları
    // yalnız sunucuda bilinir. Uç yanıt vermezse yerel önizleme kalır.
    clearTimeout(onizlemeZaman);
    onizlemeZaman = setTimeout(function () {
      M.api('seo.preview', {
        title: elTitle.value, seo_title: elSeoTitle.value, seo_desc: elSeoDesc.value,
        spot: elSpot.value, slug: elSlug.value
      }).then(function (r) {
        if (!r || !r.ok) { return; }
        onizlemeYaz(r.share_title || baslik, r.description || acik, r.title);
      }).catch(function () { });
    }, 700);
  }

  M.qsa('[data-onizleme]', serpKart || document).forEach(function (dugme) {
    dugme.addEventListener('click', function () {
      var hedef = dugme.getAttribute('data-onizleme');
      M.qsa('[data-onizleme]', serpKart).forEach(function (b) { b.classList.remove('etkin'); });
      dugme.classList.add('etkin');
      M.qsa('[data-onizleme-govde]', serpKart).forEach(function (g) {
        g.hidden = (g.getAttribute('data-onizleme-govde') !== hedef);
      });
    });
  });

  [elSpot, elTags, elSeoTitle, elSeoDesc].forEach(function (el) {
    el.addEventListener('input', seoGuncelle);
  });
  seoGuncelle();

  /* ------------------------------------------------ tarih alanı görünürlüğü */
  function tarihGoster() {
    var alan = document.getElementById('tarihAlani');
    alan.style.opacity = (elStatus.value === 'scheduled') ? '1' : '';
    if (elStatus.value === 'scheduled' && !document.getElementById('h_published_at').value) {
      var d = new Date(Date.now() + 3600000);
      d.setSeconds(0, 0);
      document.getElementById('h_published_at').value =
        d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
        + 'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }
  }
  elStatus.addEventListener('change', tarihGoster);
  tarihGoster();

  /* ------------------------------------------------ öne çıkan görsel */
  function gorselYaz(dosya, url) {
    elImage.value = dosya || '';
    var onizleme = document.getElementById('gorselOnizleme');
    var yok = document.getElementById('gorselYok');
    var img = document.getElementById('gorselImg');
    if (dosya) {
      img.src = url || (yuklemeKok + dosya);
      onizleme.hidden = false;
      yok.hidden = true;
      document.getElementById('gorselKaldir').disabled = false;
    } else {
      img.removeAttribute('src');
      onizleme.hidden = true;
      yok.hidden = false;
      document.getElementById('gorselKaldir').disabled = true;
    }
    seoGuncelle();
  }
  document.getElementById('gorselSec').addEventListener('click', function () {
    M.medyaSec(function (o) { gorselYaz(o.filename, o.url); });
  });
  document.getElementById('gorselKaldir').addEventListener('click', function () { gorselYaz('', ''); });

  /* ------------------------------------------------ kaydet */
  function verileriTopla() {
    var v = M.formVerisi(form);
    v.id = parseInt(v.id, 10) || 0;
    v.category_id = parseInt(v.category_id, 10) || 0;
    v.body = editor ? editor.html() : document.getElementById('h_body').value;
    v.published_at = M.dtSql(v.published_at || '');
    if (ustveriKipi) {
      // Sunucu da yok sayıyor (api_admin_metadata_only); burada hiç göndermiyoruz ki
      // kilitli alanların değişmeyeceği istekten de okunabilsin.
      ['title', 'spot', 'body', 'status', 'published_at', 'type', 'category_id', 'visibility', 'teaser']
        .forEach(function (ad) { delete v[ad]; });
    }

    /* B08 — üstveri alanları HER istekte tam gitsin. Eksik/boş alan sunucuda
       "boşalt" olarak okunuyor; slug için boş değer "başlıktan yeniden üret"
       anlamına geliyor. Mevcut haberde (id > 0) yedek değerleri geri koyuyoruz.
       Yeni haberde (id = 0) yedek zaten boştur, davranış değişmez. */
    if (v.id > 0) {
      ['slug', 'tags', 'image', 'seo_title', 'seo_desc'].forEach(function (ad) {
        if (v[ad] === undefined || v[ad] === null) { v[ad] = ustveriYedek[ad] || ''; }
      });
      // Slug'ın boşa düşmesi = sunucuda başlıktan yeniden üretim; mevcut adresi koru.
      if (String(v.slug || '').trim() === '') { v.slug = ustveriYedek.slug || ''; }
    }
    return v;
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var v = verileriTopla();
    if (!ustveriKipi && !String(v.title || '').trim()) { M.toast('err', 'Başlık zorunlu.'); elTitle.focus(); return; }

    var kaydetDugmeleri = M.qsa('button[type=submit]');
    kaydetDugmeleri.forEach(function (b) { b.disabled = true; });

    /* GERİ ALINABİLİRLİK: üzerine yazmadan ÖNCE mevcut hâli sürüm olarak sakla.
       İçerik istemciden gitmez — `revisions.snapshot` veritabanındaki satırı
       okur. Sürüm yazılamazsa kayıt yine de sürer (geçmiş bir güvence
       katmanıdır, hizmet kesici değil). */
    var oncesi = (surumler && v.id > 0)
      ? surumler.kayitOncesi().catch(function () { return null; })
      : Promise.resolve(null);

    oncesi.then(function () { return M.api('posts.save', v); }).then(function (r) {
      kaydetDugmeleri.forEach(function (b) { b.disabled = false; });
      if (!M.sonuc(r, 'Haber kaydedildi.')) { return; }
      kirli.temizle();
      if (r.slug) { elSlug.value = r.slug; slugElle = true; }
      if (r.status && elStatus.querySelector('option[value=' + r.status + ']')) { elStatus.value = r.status; }
      if (r.published_at) { document.getElementById('h_published_at').value = M.dtLocal(r.published_at); }
      if (v.id === 0 && r.id) {
        elId.value = r.id;
        history.replaceState(null, '', duzenleSablon.replace('__ID__', r.id));
        var bag = document.getElementById('onYuzBag');
        if (bag && r.url) { bag.href = r.url; }
      }
      slugUyari.hidden = true;
      if (surumler) { surumler.kayitSonrasi(); }
    });
  });

  M.qsa('[data-haber-sil]').forEach(function (b) {
    b.addEventListener('click', function () {
      M.api('posts.delete', { id: parseInt(b.getAttribute('data-haber-sil'), 10) }).then(function (r) {
        if (M.sonuc(r, 'Haber çöp kutusuna taşındı.')) {
          kirli.temizle();
          setTimeout(function () { location.href = listeAdres; }, 500);
        }
      });
    });
  });

  /* Yayından kaldır (posts.takedown) — metne dokunmaz, haberi arşive alır. */
  M.qsa('[data-haber-kaldir]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      M.api('posts.takedown', { id: parseInt(b.getAttribute('data-haber-kaldir'), 10) }).then(function (r) {
        if (M.sonuc(r, 'Haber yayından kaldırıldı.')) {
          kirli.temizle();
          setTimeout(function () { location.reload(); }, 500);
        } else { b.disabled = false; }
      });
    });
  });

  /* ------------------------------------------------ yapay zekâ yardımcıları */
  function yzGovde() {
    var metin = editor ? (editor.alan.innerText || '') : document.getElementById('h_body').value;
    return metin.slice(0, 6000);
  }
  function yzKategori() {
    var s = document.getElementById('h_category');
    return s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : '';
  }
  function yzCagir(dugme, uc, sonra) {
    var eskiMetin = dugme.textContent;
    dugme.disabled = true;
    dugme.textContent = 'Çalışıyor…';
    M.api(uc, { title: elTitle.value, body: yzGovde(), category: yzKategori(), spot: elSpot.value })
      .then(function (r) {
        dugme.disabled = false;
        dugme.textContent = eskiMetin;
        if (!r || !r.ok) { M.toast('err', (r && r.error) || 'Yapay zekâ yanıt vermedi.'); return; }
        sonra(r);
      });
  }

  var yzBaslikDugme = document.getElementById('yzBaslik');
  if (yzBaslikDugme) { yzBaslikDugme.addEventListener('click', function () {
    var d = this;
    yzCagir(d, 'ai.suggest_title', function (r) {
      var liste = (r.items || []);
      if (!liste.length) { M.toast('warn', 'Öneri üretilemedi.'); return; }
      var html = '<p class="soluk kucuk">Kullanmak istediğiniz başlığa tıklayın.</p><div class="dugme-grup" style="flex-direction:column;align-items:stretch">';
      liste.forEach(function (t, i) {
        html += '<button type="button" class="dugme" data-oneri="' + i + '" style="text-align:left">' + M.esc(t) + '</button>';
      });
      html += '</div>';
      var govde = M.modal.ac('Başlık önerileri', html, '520px');
      M.qsa('[data-oneri]', govde).forEach(function (b) {
        b.addEventListener('click', function () {
          elTitle.value = liste[parseInt(b.getAttribute('data-oneri'), 10)];
          if (!slugElle) { elSlug.value = slugla(elTitle.value); }
          M.modal.kapat();
          seoGuncelle();
          slugDenetle();
        });
      });
    });
  }); }

  var yzSpotDugme = document.getElementById('yzSpot');
  if (yzSpotDugme) { yzSpotDugme.addEventListener('click', function () {
    var d = this;
    yzCagir(d, 'ai.suggest_spot', function (r) {
      if (!r.spot) { M.toast('warn', 'Spot üretilemedi.'); return; }
      elSpot.value = String(r.spot).slice(0, 300);
      seoGuncelle();
      M.toast('ok', 'Spot alanı dolduruldu.');
    });
  }); }

  document.getElementById('yzSeo').addEventListener('click', function () {
    var d = this;
    yzCagir(d, 'ai.fill_seo', function (r) {
      if (r.seo_title) { elSeoTitle.value = String(r.seo_title).slice(0, 255); }
      if (r.seo_desc) { elSeoDesc.value = String(r.seo_desc).slice(0, 500); }
      if (r.tags && r.tags.length) { elTags.value = r.tags.join(', '); }
      seoGuncelle();
      M.toast('ok', 'SEO alanları dolduruldu.');
    });
  });
});
</script>

<?php endif; ?>

<script>
// Görünürlük 'Herkes' değilse önizleme metni alanı görünür (Faz 7d içerik kilidi).
M.hazir(function () {
  var g = document.getElementById('h_visibility');
  var t = document.getElementById('teaserAlani');
  if (!g || !t) { return; }
  var guncelle = function () { t.hidden = (g.value === 'public'); };
  g.addEventListener('change', guncelle);
  guncelle();
});
</script>
