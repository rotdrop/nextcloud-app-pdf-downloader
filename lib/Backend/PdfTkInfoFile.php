<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2025 Claus-Justus Heine <himself@claus-justus-heine.de>
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

use \Exception;

use mikehaertl\tmp\File;

/**
 * InfoFile
 *
 * This class represents a temporary dump_data compatible file that can be
 * used to update meta data of PDF with valid unicode characters.
 */
class PdfTkInfoFile extends File
{
  /**
   * Constructor
   *
   * @param array $data the form data as name => value.
   * @param string|null $suffix the optional suffix for the tmp file.
   * @param string|null $prefix the optional prefix for the tmp file. If null 'php_tmpfile_' is used.
   * @param string|null $directory directory where the file should be created. Autodetected if not provided.
   * @param string $encoding of the data. Default is 'UTF-8'.
   */
  public function __construct(
    array $data,
    ?string $suffix = null,
    ?string $prefix = null,
    ?string $directory = null,
    string $encoding = 'UTF-8',
  ) {
    if ($directory === null) {
      $directory = self::getTempDir();
    }
    $suffix = '.txt';
    $prefix = 'php_pdftk_info_';

    $this->_fileName = tempnam($directory, $prefix);
    $newName = $this->_fileName . $suffix;
    rename($this->_fileName, $newName);
    $this->_fileName = $newName;

    if (!function_exists('mb_convert_encoding')) {
      throw new Exception('MB extension required.');
    }

    $fields = '';
    foreach ($data as $key => $value) {
      $key = self::encode($key, $encoding);
      if (is_array($value)) {
        if ($key == 'Info') {
          // Info is special, undo that
          $data = [];
          foreach ($value as $subKey => $subValue) {
            $data[] = [
              'Key' => $subKey,
              'Value' => $subValue,
            ];
          }
          $value = $data;
        }
        foreach ($value as $item) {
          $fields .= "{$key}Begin\n";
          foreach ($item as $subKey => $subValue) {
            // Always convert to UTF-8
            $subKey = self::encode($subKey, $encoding);
            $subValue = self::encode($subValue, $encoding);
            $fields .= "{$key}{$subKey}: {$subValue}\n";
          }
        }
      } else {
        $fields .= "{$key}: {$value}\n";
      }
    }

    // Use fwrite, since file_put_contents() messes around with character encoding
    $fp = fopen($this->_fileName, 'w');
    fwrite($fp, $fields);
    fclose($fp);
  }

  /**
   * Try to re-encode to UTF-8 if possible.
   *
   * @param string $value
   *
   * @param string $encoding
   *
   * @return string
   */
  private static function encode(string $value, string $encoding):string
  {
    // Always convert to UTF-8
    if ($encoding !== 'UTF-8' && function_exists('mb_convert_encoding')) {
      $value = mb_convert_encoding($value, 'UTF-8', $encoding);
      $value = defined('ENT_XML1') ? htmlspecialchars($value, ENT_XML1, 'UTF-8') : htmlspecialchars($value);
    }
    return $value;
  }
}
