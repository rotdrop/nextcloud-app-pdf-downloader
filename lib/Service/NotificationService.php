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

use OCP\Files\Node;
use OCP\Files\File;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use Psr\Log\LoggerInterface as ILogger;
use OCP\IUserSession;

use OCA\PdfDownloader\BackgroundJob\PdfGeneratorJob;
use OCA\PdfDownloader\Notification\Notifier;
use OCA\PdfDownloader\Constants;

/** Service class for notification management. */
class NotificationService
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\UserRootFolderTrait;

  /** @var string */
  protected $appName;

  /** @var null|string */
  protected $userId = null;

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
    }
  }
  // phpcs:enable

  /**
   * @param string $userId
   *
   * @param Node $sourceNode
   *
   * @param string $destinationPath
   *
   * @param string $jobType PdfGeneratorJob::TARGET_DOWNLOAD or
   * PdfGeneratorJob::TARGET_FILESYSTEM.
   *
   * @return void
   */
  public function sendNotificationOnPending(
    string $userId,
    Node $sourceNode,
    string $destinationPath,
  ):void {
    $this->userId = $userId;
    $sourcePath = $sourceNode->getPath();
    $target = str_starts_with($destinationPath, Constants::USER_FOLDER_PREFIX) ? PdfGeneratorJob::TARGET_FILESYSTEM : PdfGeneratorJob::TARGET_DOWNLOAD;
    $notification = $this->buildNotification(
      Notifier::TYPE_SCHEDULED,
      $target,
      $userId,
      $sourcePath,
      $sourceNode->getId(),
      $destinationPath,
    )
      ->setDateTime(new DateTime());
    $this->notificationManager->notify($notification);
    $this->logInfo('NOTIFY PENDING');
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
    $this->userId = $userId;
    $destinationPath = $job->getDestinationPath();
    $sourcePath = $job->getSourcePath();
    $sourceId = $job->getSourceId();

    $this->logInfo('DEST / USER ' . $destinationPath . ' | ' . Constants::USER_FOLDER_PREFIX);

    $target = str_starts_with($destinationPath, Constants::USER_FOLDER_PREFIX) ? PdfGeneratorJob::TARGET_FILESYSTEM : PdfGeneratorJob::TARGET_DOWNLOAD;

    $this->notificationManager->markProcessed($this->buildNotification(
      Notifier::TYPE_SCHEDULED,
      $target,
      $userId,
      $sourcePath,
      $sourceId,
      $destinationPath,
    ));

    $notification = $this->buildNotification(
      Notifier::TYPE_SUCCESS,
      $target,
      $userId,
      $sourcePath,
      $sourceId,
      $destinationPath,
    );
    $subject = $notification->getSubject();
    $subjectParameters = $notification->getSubjectParameters();
    $subjectParameters['destinationId'] = $file->getId();

    $notification
      ->setDateTime(new DateTime())
      ->setSubject($subject, $subjectParameters);
    $this->notificationManager->notify($notification);
    $this->logInfo('NOTIFY SUCCESS');
  }

  /**
   * @param PdfGeneratorJob $job
   *
   * @return void
   */
  public function sendNotificationOnFailure(PdfGeneratorJob $job):void
  {
    $userId = $job->getUserId();
    $this->userId = $userId;
    $destinationPath = $job->getDestinationPath();
    $sourcePath = $job->getSourcePath();
    $sourceId = $job->getSourceId();
    $target = str_starts_with($destinationPath, Constants::USER_FOLDER_PREFIX) ? PdfGeneratorJob::TARGET_FILESYSTEM : PdfGeneratorJob::TARGET_DOWNLOAD;

    $this->notificationManager->markProcessed($this->buildNotification(
      Notifier::TYPE_SCHEDULED,
      $target,
      $userId,
      $sourcePath,
      $sourceId,
      $destinationPath,
    ));

    $notification = $this->buildNotification(
      Notifier::TYPE_FAILURE,
      $target,
      $userId,
      $sourcePath,
      $sourceId,
      $destinationPath,
    );
    $notification
      ->setDateTime(new DateTime())
      ->setObject('job', (string)$job->getId());
    $this->notificationManager->notify($notification);
    $this->logInfo('NOTIFY FAILURE');
  }

  /**
   * @param int $type
   *
   * @param string $target
   *
   * @param string $userId
   *
   * @param string $sourcePath
   *
   * @param int $sourceId
   *
   * @param string $destinationPath
   *
   * @return INotification
   */
  private function buildNotification(
    int $type,
    string $target,
    string $userId,
    string $sourcePath,
    int $sourceId,
    string $destinationPath,
  ):INotification {
    $type |= ($target == PdfGeneratorJob::TARGET_DOWNLOAD ? Notifier::TYPE_DOWNLOAD : Notifier::TYPE_FILESYSTEM);
    $notification = $this->notificationManager->createNotification();
    $notification->setUser($userId)
      ->setApp($this->appName)
      ->setObject('target', md5($destinationPath))
      ->setSubject((string)$type, [
        'sourceId' => $sourceId,
        'sourcePath' => $sourcePath,
        'sourceDirectory' => dirname($sourcePath),
        'sourceDirectoryName' => basename(dirname($sourcePath)),
        'sourceBaseName' => basename($sourcePath),
        'destinationPath' => $destinationPath,
        'destinationDirectory' => dirname($destinationPath),
        'destinationDirectoryName' => basename(dirname($destinationPath)),
        'destinationBaseName' => basename($destinationPath),
      ]);
    return $notification;
  }
}
