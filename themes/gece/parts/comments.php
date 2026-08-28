<?php
/**
 * Gece teması — yorumlar. Gövde: themes/gazete/parts/yorum-govde.php
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
part('yorum-govde', [
    'post'             => isset($post) ? $post : null,
    'yorumBaslik'      => 'Yorumlar',
    'yorumBolumSinif'  => 'kutu',
    'yorumBaslikSinif' => 'kutu-baslik',
    'yorumSayiSinif'   => 'mono soluk',
]);
