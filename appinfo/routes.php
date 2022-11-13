<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author    Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license   AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

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
      'url' => '/download/pdf/{nodePath}',
      'verb' => 'GET',
    ],
    [
      'name' => 'multi_pdf_download#get_fonts',
      'url' => '/pdf/fonts',
      'verb' => 'GET',
    ],
    [
      'name' => 'multi_pdf_download#get_font_sample',
      'url' => '/pdf/fonts/sample/{text}/{font}/{fontSize}',
      'verb' => 'GET',
      'defaults' => [
        'fontSize' => '12',
      ],
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
