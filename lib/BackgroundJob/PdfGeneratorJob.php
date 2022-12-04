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

use InvalidArgumentException;
use Throwable;

use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface as ILogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ITempManager;

use OCA\PdfDownloader\Service\FileSystemWalker;
use OCA\PdfDownloader\Service\NotificationService;

/**
 * Background PDF generator job in order to move time-consuming jobs out of
 * reach of the web-server limits.
 */
class PdfGeneratorJob extends QueuedJob
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;

  public const USER_ID_KEY = 'userId';
  public const SOURCE_PATH_KEY = 'sourcePath';
  public const DESTINATION_PATH_KEY = 'destinationPath';

  /** @var ITempManager */
  private $tempManager;

  /** @var FileSystemWalker */
  private $fileSystemWalker;

  /** @var NotificationService */
  private $notificationService;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ITimeFactory $timeFactory,
    ILogger $logger,
    ITempManager $tempManager,
    FileSystemWalker $fileSystemWalker,
    NotificationService $notificationService,
  ) {
    parent::__construct($timeFactory);
    $this->logger = $logger;
    $this->tempManager = $tempManager;
    $this->fileSystemWalker = $fileSystemWalker;
    $this->notificationService = $notificationService;
  }
  // phpcs:enable

  /**
   * @return string
   *
   * @throws InvalidArgumentException
   */
  public function getUserId():string
  {
    $sourcePath = $this->argument[self::USER_ID_KEY];
    if (empty($sourcePath)) {
      throw new InvalidArgumentException('User id argument is empty.');
    }
    return $sourcePath;
  }

  /**
   * @return string
   *
   * @throws InvalidArgumentException
   */
  public function getSourcePath():string
  {
    $sourcePath = $this->argument[self::SOURCE_PATH_KEY];
    if (empty($sourcePath)) {
      throw new InvalidArgumentException('Source path argument is empty.');
    }
    return $sourcePath;
  }

  /**
   * @return string
   *
   * @throws InvalidArgumentException
   */
  public function getDestinationPath():string
  {
    $destinationPath = $this->argument[self::DESTINATION_PATH_KEY];
    if (empty($destinationPath)) {
      throw new InvalidArgumentException('Destination path argument is empty.');
    }
    return $destinationPath;
  }

  /** {@inheritdoc} */
  protected function run($argument)
  {
    try {
      $file = $this->fileSystemWalker->save($this->getSourcePath(), $this->getDestinationPath());
      $this->notificationService->sendNotificationOnSuccess($this, $file);
    } catch (Throwable $t) {
      $this->logger->error('Failed to create composite PDF.', [ 'exception' => $t ]);
      $this->notificationService->sendNotificationOnFailure($this);
    } finally {
      $this->tempManager->clean();
    }
  }
}
