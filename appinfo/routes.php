<?php

return [
  'routes' => [
    [
      'name' => 'settings#set_admin',
      'url' => '/settings/admin/{setting}',
      'verb' => 'POST',
    ],
    [
      'name' => 'settings#get_admin',
      'url' => '/settings/admin/{setting}',
      'verb' => 'GET',
      'requirements' => [
        'setting' => '^.+$',
      ],
    ],
    [
      'name' => 'settings#get_admin',
      'url' => '/settings/admin',
      'verb' => 'GET',
      'postfix' => '.all',
    ],
    [
      'name' => 'settings#set_personal',
      'url' => '/settings/personal/{setting}',
      'verb' => 'POST',
    ],
    [
      'name' => 'settings#get_personal',
      'url' => '/settings/personal/{setting}',
      'verb' => 'GET',
      'requirements' => [
        'setting' => '^.+$',
      ],
    ],
    [
      'name' => 'settings#get_personal',
      'url' => '/settings/personal',
      'verb' => 'GET',
      'postfix' => '.all',
    ],
    [
      'name' => 'multi_pdf_download#get',
      'url' => '/download/pdf/{folder}',
      'verb' => 'GET',
    ],
    [
      'name' => 'multi_pdf_download#get_fonts',
      'url' => '/pdf/fonts',
      'verb' => 'GET',
    ],
    /**
     * Attempt a catch all ...
     */
    [
      'name' => 'catch_all#post',
      'postfix' => 'post',
      'url' => '/{a}/{b}/{c}/{d}/{e}/{f}/{g}',
      'verb' => 'POST',
      'defaults' => [
        'a' => '',
        'b' => '',
        'c' => '',
        'd' => '',
        'e' => '',
        'f' => '',
        'g' => '',
      ],
    ],
    [
      'name' => 'catch_all#get',
      'postfix' => 'get',
      'url' => '/{a}/{b}/{c}/{d}/{e}/{f}/{g}',
      'verb' => 'GET',
      'defaults' => [
        'a' => '',
        'b' => '',
        'c' => '',
        'd' => '',
        'e' => '',
        'f' => '',
        'g' => '',
      ],
    ],
  ],
];
