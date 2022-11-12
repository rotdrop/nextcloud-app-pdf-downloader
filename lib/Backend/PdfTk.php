<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\PdfDownloader\Backend;

use mikehaertl\pdftk\Pdf as PdfTkUpstream;

use PdfTkInfoFile as InfoFile;

/**
 * Tweak vanilla php-pdftk to suit our needs.
 */
class PdfTk extends PdfTkUpstream
{
  /**
   * @var array
   * Ensure that pdftk (the java program) sees an UTF-8 locale. Otherwise it
   * fails to parse input data with non-ASCII chars or to generate such output
   * data.
   */
  protected const DEFAULT_COMMAND_OPTIONS = [
    'locale' => 'C.UTF-8',
    'procEnv' => [
      'LANG' => 'C.UTF-8',
      'LC_ALL' => 'C.UTF-8',
    ],
  ];

  /**
   * @param string|Pdf|array $pdf a pdf filename or Pdf instance or an array
   * of filenames/instances indexed by a handle. The array values can also
   * be arrays of the form array($filename, $password) if some files are
   * password protected.
   * @param array $options Options to pass to set on the Command instance,
   * e.g. the pdftk binary path
   */
  public function __construct($pdf = null, $options = [])
  {
    $options = array_merge(self::DEFAULT_COMMAND_OPTIONS, $options);
    parent::__construct($pdf, $options);
  }

  /**
   * Update meta data of PDF
   *
   * @param string|array $data either a InfoFile filename or an array with
   * form field data (name => value)
   * @param string $encoding the encoding of the data. Default is 'UTF-8'.
   *
   * @return Pdf the pdf instance for method chaining
   *
   * @note This is here because the upstream version as of now is not able to
   * parse the output of getData() back into an array.
   */
  public function updateInfo($data, $encoding = 'UTF-8')
  {
    $this->constrainSingleFile();
    if (is_array($data)) {
      $data = new InfoFile($data, null, null, $this->tempDir, $encoding);
    }
    $this->getCommand()
      ->setOperation($encoding == 'UTF-8' ? 'update_info_utf8' : 'update_info')
      ->setOperationArgument($data, true);

    return $this;
  }
}
