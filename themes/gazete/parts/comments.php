<?php
/**
 * Gazete teması — yorumlar.
 * Gövde ORTAK parçadadır (parts/yorum-govde.php); burada yalnız temaya özgü
 * başlık/sınıf tercihleri var. Yanıt zinciri ve bildir mantığı tek yerde
 * durduğu için bir düzeltme dört temada birden geçerli olur.
 */
if (!defined('MANSET_BOOTSTRAPPED')) { exit; }
part('yorum-govde', [
    'post'             => isset($post) ? $post : null,
    'yorumBaslik'      => 'Yorumlar',
    'yorumBolumSinif'  => '',
    'yorumBaslikSinif' => '',
    'yorumSayiSinif'   => 'sayi',
]);
