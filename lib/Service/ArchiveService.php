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

namespace OCA\PdfDownloader\Service;

use OCP\IL10N;
use Psr\Log\LoggerInterface as ILogger;

use OCA\PdfDownloader\Toolkit\Service\ArchiveService as ToolkitService;

/** Just a wrapper around the toolkit service in order to get the $l10n from this app. */
class ArchiveService extends ToolkitService
{
  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ILogger $logger,
    ?IL10N $l10n,
  ) {
    parent::__construct($logger, $l10n);
  }
  // phpcs:enable
}
