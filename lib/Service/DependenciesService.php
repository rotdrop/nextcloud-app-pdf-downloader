<?php
/**
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright Copyright (c) 2023, 2025 Claus-Justus Heine
 * @license GNU AGPL version 3 or any later version
 *
 * "stolen" from files_zip Copyright (c) 2021 Julius Härtl <jus@bitgrid.net>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\PdfDownloader\Service;

use Psr\Log\LoggerInterface as ILogger;

use OCA\PdfDownloader\Exceptions;

/** Check for and (later perhaps) install missing external dependencies. */
class DependenciesService
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;

  public const REQUIRED = 'required';
  public const SUGGESTED = 'suggested';
  public const MISSING = 'missing';

  public const DEPENDENCY_TYPES = [
    self::REQUIRED,
    self::SUGGESTED,
  ];

  public const REQUIRED_EXECUTABLES = [
    'pdftk'
  ];

  public const SUGGESTED_EXECUTABLES = [
    'img2pdf',
    'mhonarc',
    'pandoc',
    'tiff2pdf',
    'unoconv',
    'unoconvert',
    'wkhtmltopdf',
    'pdf2svg',
  ];

  public const EXECUTABLES = [
    self::REQUIRED => self::REQUIRED_EXECUTABLES,
    self::SUGGESTED => self::SUGGESTED_EXECUTABLES,
  ];

  /** @var string */
  private $appName;

  /** @var ExecutableFinder */
  private $executableFinder;

  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    ILogger $logger,
    ExecutableFinder $executableFinder,
  ) {
    $this->appName = $appName;
    $this->logger = $logger;
    $this->executableFinder = $executableFinder;
  }
  // phpcs:enable Squiz.Commenting.FunctionComment.Missing

  /**
   * Check for required and desirable executables and return a status report.
   *
   * @param null|string $only Only check for the given class of programs where
   * $only must be one of \null, self::REQUIRED or self::SUGGESTED.
   *
   * @param bool $force Force re-check for the presence of the executable
   * ignoring cached values.
   *
   * @return array Status report of the form
   * ```
   * [
   *   'missing' => [ 'required' => NUM_REQUIRED_MISSING, 'suggested' => NUM_SUGGESTED_MISSING ],
   *   'required' => [ NAME => FULL_PATH, ... ],
   *   'suggested' => [ NAME => FULL_PATH, ... ],
   * ]
   * ```
   */
  public function checkForExternalPrograms(?string $only = null, bool $force = false):array
  {
    $status = [
      self::MISSING => [],
    ];

    foreach (self::EXECUTABLES as $classification => $executables) {
      if (!empty($only) && $classification !== $only) {
        continue;
      }
      $status[$classification] = [];
      $status[self::MISSING][$classification] = 0;
      foreach ($executables as $executable) {
        try {
          $fullPath = $this->executableFinder->find($executable, $force);
        } catch (Exceptions\EnduserNotificationException $e) {
          $fullPath = self::MISSING;
          ++$status[self::MISSING][$classification];
        }
        $status[$classification][$executable] = $fullPath;
      }
    }
    return $status;
  }
}
