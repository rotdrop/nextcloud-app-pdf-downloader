<?php
/**
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright Copyright (c) 2022 Claus-Justus Heine
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

declare(strict_types=1);

namespace OCA\PdfDownloader\Service;

use DateTime;

use OCP\Files\File;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface as ILogger;
use OCP\IUserSession;

use OCA\PdfDownloader\BackgroundJob\PdfGeneratorJob;
use OCA\PdfDownloader\Notification\Notifier;

/** Service class for notification management. */
class NotificationService
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\UserRootFolderTrait;

  public const TARGET_FILESYSTEM = '__filesystem__';
  public const TARGET_DOWNLOAD = '__download__';

  public const TYPE_DOWNLOAD = (1 << 0);
  public const TYPE_FILESYSTEM = (1 << 1);
  public const TYPE_SCHEDULED = (1 << 2);
  public const TYPE_SUCCESS = (1 << 3);
  public const TYPE_FAILURE = (1 << 4);

  /** @var string */
  private $appName;

  /** @var null|string */
  private $userId = null;

  /** @var null|string */
  private $userFolderPath = null;

  /** @var IManager */
  private $notificationManager;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IManager $notificationManager,
    ILogger $logger,
    IUserSession $userSession,
  ) {
    $this->appName = $appName;
    $this->notificationManager = $notificationManager;
    $this->logger = $logger;
    $user = $userSession->getUser();
    if (!empty($user)) {
      $this->userId = $user->getUID();
      $this->userFolderPath = $this->getUserFolderPath();
    }
  }
  // phpcs:enable

  /**
   * @param string $userId
   *
   * @param string $sourcePath
   *
   * @param string $destinationPath
   *
   * @return void
   */
  public function sendNotificationOnPending(string $userId, string $sourcePath, string $destinationPath):void
  {
    $target = str_starts_with($destinationPath, $this->userFolderPath) ? self::TYPE_FILESYSTEM : self::TYPE_DOWNLOAD;
    $notification = $this->buildScheduledNotification($userId, $sourcePath, $target)
      ->setDateTime(new DateTime());
    $this->notificationManager->notify($notification);
  }

  /**
   * @param PdfGeneratorJob $job
   *
   * @param File $file
   *
   * @return void
   */
  public function sendNotificationOnSuccess(PdfGeneratorJob $job, File $file):void
  {
    $userId = $job->getUserId();
    $destinationPath = $job->getDestinationPath();
    $sourcePath = $job->getSourcePath();
    $target = str_starts_with($destinationPath, $this->userFolderPath) ? self::TYPE_FILESYSTEM : self::TYPE_DOWNLOAD;

    $this->notificationManager->markProcessed($this->buildScheduledNotification($userId, $sourcePath, $target));
    $notification = $this->notificationManager->createNotification();
    $notification->setUser($userId)
      ->setApp($this->appName)
      ->setDateTime(new DateTime())
      ->setObject('target', md5($target))
      ->setSubject(Notifier::TYPE_SUCCESS, [
        'source' => $sourcePath,
        'fileid' => $file->getId(),
        'name' => basename($destinationPath),
        'path' => dirname($destinationPath),
      ]);
    $this->notificationManager->notify($notification);
  }

  /**
   * @param PdfGeneratorJob $job
   *
   * @return void
   */
  public function sendNotificationOnFailure(PdfGeneratorJob $job):void
  {
    $userId = $job->getUserId();
    $destinationPath = $job->getDestinationPath();
    $sourcePath = $job->getSourcePath();
    $target = str_starts_with($destinationPath, $this->userFolderPath) ? self::TYPE_FILESYSTEM : self::TYPE_DOWNLOAD;

    $this->notificationManager->markProcessed($this->buildScheduledNotification($userId, $sourcePath, $target));
    $notification = $this->notificationManager->createNotification();
    $notification->setUser($userId)
      ->setApp($this->appName)
      ->setDateTime(new DateTime())
      ->setObject('job', (string)$job->getId())
      ->setSubject(Notifier::TYPE_FAILURE, [
        'source' => $sourcePath,
        'target' => $destinationPath,
      ]);
    $this->notificationManager->notify($notification);
  }

  /**
   * @param string $userId
   *
   * @param string $sourcePath
   *
   * @param string $target Either a destination path or NotificationService::DOWNLOAD_TARGET.
   *
   * @return INotification
   */
  private function buildScheduledNotification(string $userId, string $sourcePath, string $target):INotification
  {
    $type = self::TYPE_SCHEDULED | ($target == self::TARGET_DOWNLOAD ? self::TYPE_DOWNLOAD : self::TYPE_FILESYSTEM);
    $notification = $this->notificationManager->createNotification();
    $notification->setUser($userId)
      ->setApp($this->appName)
      ->setObject('target', md5($target))
      ->setSubject($type, [
        'directory' => dirname($target),
        'directory-name' => basename(dirname($target)),
        'target-name' => basename($target),
      ]);
    return $notification;
  }
}
