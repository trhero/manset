<?php
/**
 * Yerel teması — yorumlar. Gövde: themes/gazete/parts/yorum-govde.php
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
part('yorum-govde', [
    'post'             => isset($post) ? $post : null,
    'yorumBaslik'      => 'Okur Yorumları',
    'yorumBolumSinif'  => '',
    'yorumBaslikSinif' => 'blok-baslik',
    'yorumSayiSinif'   => 'soluk',
]);
