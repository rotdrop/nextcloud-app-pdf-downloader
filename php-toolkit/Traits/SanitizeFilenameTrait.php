<?php
/**
 * A collection of reusable traits classes for Nextcloud apps.
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2025 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\RotDrop\Toolkit\Traits;

use OCP\AppFramework\IAppContainer;
use OC\Files\FilenameValidator;

/**
 * Cloned from OCP\Files\Command\SanitzeFilenames.
 */
trait SanitizeFilenameTrait
{
  use LoggerTrait;

  protected IAppContainer $appContainer;

  /**
   * Remove "forbidden" characters as configured in order to achieve a
   * filename which is also valid on certain strange operating systems.
   *
   * @param string $name
   *
   * @return string
   */
  protected function sanitizeFilename(string $name): string
  {
    $oldName = $name;
    try {
      /** @var FilenameValidator $filenameValidator */
      $filenameValidator = $this->appContainer->get(FilenameValidator::class);

      $forbiddenCharacters = $filenameValidator->getForbiddenCharacters();
      $charReplacement = array_diff(['_', ' ', '-'], $forbiddenCharacters);
      $charReplacement = reset($charReplacement) ?: '';

      foreach ($filenameValidator->getForbiddenExtensions() as $extension) {
        if (str_ends_with($name, $extension)) {
          $name = substr($name, 0, strlen($name) - strlen($extension));
        }
      }

      $basename = substr($name, 0, strpos($name, '.', 1) ?: null);
      if (in_array($basename, $filenameValidator->getForbiddenBasenames())) {
        $name = str_replace($basename, $this->l->t('%1$s (renamed)', [$basename]), $name);
      }

      if ($name === '') {
        $name = $this->l->t('renamed file');
      }

      $forbiddenCharacter = $filenameValidator->getForbiddenCharacters();
      $name = str_replace($forbiddenCharacter, $charReplacement, $name);
    } catch (Throwable $t) {
      $this->logException($t, 'Unable to sanitize filename');
      return $oldName;
    }
    return $name;
  }
}
