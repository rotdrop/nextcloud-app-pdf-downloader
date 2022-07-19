<?php
/**
 * @copyright Copyright (c) 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license AGPL-3.0-or-later
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

namespace OCA\PdfDownloader\Service;

use Symfony\Component\Finder\Finder as FileNodeFinder;

class PdfGenerator extends \TCPDF
{
  const FONT_FLAG_MONOSPACE = (1 << 0);
  const FONT_FLAG_SYMBOLIC = (1 << 2);
  const FONT_FLAG_NORMAL = (1 << 5);
  const FONT_FLAG_ITALIC = (1 << 6);


  /**
   * @var array
   * Per request cache of the list of distributed fonts.
   */
  private $distributedFonts = [];

  public function __construct($orientation='P', $unit='mm', $format='A4', $unicode=true, $encoding='UTF-8', $diskcache=false, $pdfa=false)
  {
    parent::__construct(
      orientation: $orientation
      , unit: $unit
      , format: $format
      , unicode: $unicode
      , encoding: $encoding
      , diskcache: $diskcache
      , pdfa: $pdfa
    );
  }

  /**
   * Return the array of available fonts
   */
  public function getFonts():array
  {
    if (empty($this->distributedFonts)) {
      $fontPath = \TCPDF_FONTS::_getfontpath();
      $finder = new FileNodeFinder;
      /** @var \SplFileInfo $finderFile */
      foreach ($finder->in($fontPath)->files()->name('*.php') as $finderFile) {
        include($finderFile->getRealPath());
        $family = $finderFile->getBasename('.php');
        $this->distributedFonts[] = [
          'family' => $family,
          'type' => $type,
          'fontName' => $name,
          'flags' => $desc['Flags'],
        ];
      }
      usort($this->distributedFonts, fn($a, $b) => strcmp($a['fontName'], $b['fontName']));
    }
    return $this->distributedFonts;
  }
};
