<?php
/**
 * Bülten teması — yorumlar. Gövde: themes/gazete/parts/yorum-govde.php
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
part('yorum-govde', [
    'post'             => isset($post) ? $post : null,
    'yorumBaslik'      => 'Okur yorumları',
    'yorumBolumSinif'  => '',
    'yorumBaslikSinif' => 'bolum-baslik',
    'yorumSayiSinif'   => 'soluk',
]);
