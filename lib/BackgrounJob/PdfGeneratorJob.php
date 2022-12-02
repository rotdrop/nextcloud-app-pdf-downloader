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

namespace OCA\PdfDownloader\BackgroundJob;

use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface as ILogger;

/**
 * Background PDF generator job in order to move time-consuming jobs out of
 * reach of the web-server limits.
 */
class PdfGeneratorJob extends QueuedJob
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ILogger $logger
  ) {
    $this->logger = $logger;
  }
  // phpcs:enable

  /** {@inheritdoc} */
  protected function run($argument)
  {
  }
}
