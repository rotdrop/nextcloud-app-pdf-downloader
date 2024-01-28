<?php
/**
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright Copyright (c) 2022, 2023, 2024 Claus-Justus Heine
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
use OCP\Files\IRootFolder;
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
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\UserRootFolderTrait;

  /** @var string */
  protected string $userId;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    protected $appName,
    private IManager $notificationManager,
    protected ILogger $logger,
    protected IRootFolder $rootFolder,
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
    $notification = $this->buildNotification(
      Notifier::TYPE_SCHEDULED,
      $userId,
      $sourcePath,
      $sourceNode->getId(),
      $destinationPath,
    );

    $this->logInfo('NOTIFY PENDING');
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
    $this->userId = $userId;

    $sourcePath = $job->getSourcePath();
    $sourceId = $job->getSourceId();
    $oldDestinationPath = $job->getDestinationPath();

    $this->deleteNotification(Notifier::TYPE_SCHEDULED, $oldDestinationPath, $userId);

    // $destinationPath may have change in background jobs, for one because
    // the timestamp is later. Another reason is that the background-job
    // strifes to make the filename unique. The destination path must be
    // relative to the top level user folder.
    //
    // files/Test/ArchiveTests/2024-01-27T21:24:18+01:00-Test:ArchiveTests:test-archive.pdf | /claus/files/Test/ArchiveTests/2024-01-27T21:25:00+01:00-Test:ArchiveTests:test-archive.pdf
    $destinationPath = ltrim($this->getUserRootFolder()->getRelativePath($file->getPath()), Constants::PATH_SEPARATOR);

    $this->logInfo('JOB DEST / FILE PATH / ACTUAL DEST ' . $oldDestinationPath . ' | ' . $file->getPath() . ' | ' . $destinationPath);

    $this->logInfo('BEFORE BUILD');
    $notification = $this->buildNotification(
      Notifier::TYPE_SUCCESS,
      $userId,
      $sourcePath,
      $sourceId,
      $destinationPath,
    );
    $this->logInfo('AFTER BUILD');
    $subject = $notification->getSubject();
    $subjectParameters = $notification->getSubjectParameters();
    $subjectParameters['destinationId'] = $file->getId();

    $message = $notification->getMessage();
    $messageParameters = $notification->getMessageParameters();
    $messageParameters['destinationId'] = $file->getId();

    $this->logInfo('BEFORE SET SUBJECT PARAMETERS');
    $notification
      ->setSubject($subject, $subjectParameters)
      ->setMessage($message, $messageParameters);
    // ->setDateTime(new DateTime()

    $this->logInfo('BEFORE NOTIFY SUCCESS');
    $this->notificationManager->notify($notification);
    $this->logInfo('NOTIFY SUCCESS');
  }

  /**
   * @param PdfGeneratorJob $job
   *
   * @param null|string $errorMessage Optional error message.
   *
   * @return void
   */
  public function sendNotificationOnFailure(
    PdfGeneratorJob $job,
    ?string $errorMessage = null,
  ):void {
    $userId = $job->getUserId();
    $this->userId = $userId;
    $destinationPath = $job->getDestinationPath();
    $sourcePath = $job->getSourcePath();
    $sourceId = $job->getSourceId();

    $this->deleteNotification(Notifier::TYPE_SCHEDULED, $destinationPath, $userId);

    $notification = $this->buildNotification(
      Notifier::TYPE_FAILURE,
      $userId,
      $sourcePath,
      $sourceId,
      $destinationPath,
      $errorMessage,
    );
    $notification->setObject('job', (string)$job->getId());

    $this->logInfo('NOTIFY FAILURE');
    $this->notificationManager->notify($notification);
  }

  public function sendNotificationOnClean()
  {
  }

  /**
   * @param int $type
   *
   * @param string $userId
   *
   * @param string $sourcePath
   *
   * @param int $sourceId
   *
   * @param string $destinationPath
   *
   * @param null|string $errorMessage Optional error message when emitting
   * failure notifications.
   *
   * @return INotification
   */
  private function buildNotification(
    int $type,
    string $userId,
    string $sourcePath,
    int $sourceId,
    string $destinationPath,
    ?string $errorMessage = null,
  ):INotification {
    $type |= (str_starts_with($destinationPath, Constants::USER_FOLDER_PREFIX) ? Notifier::TYPE_FILESYSTEM : Notifier::TYPE_DOWNLOAD);
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
        'errorMessage' => $errorMessage,
      ])
      ->setMessage((string)$type, [
        'sourceId' => $sourceId,
        'sourcePath' => $sourcePath,
        'sourceDirectory' => dirname($sourcePath),
        'sourceDirectoryName' => basename(dirname($sourcePath)),
        'sourceBaseName' => basename($sourcePath),
        'destinationPath' => $destinationPath,
        'destinationDirectory' => dirname($destinationPath),
        'destinationDirectoryName' => basename(dirname($destinationPath)),
        'destinationBaseName' => basename($destinationPath),
        'errorMessage' => $errorMessage,
      ])
      ->setDateTime(new DateTime());
    return $notification;
  }

  /**
   * Mark the notification of the given type for the given user and the given
   * destination file-system target as processed or deleted. This is also
   * needed in order to interact with cache-cleanout and user deletion
   * events. If the targe object is deleted, the notification should also go
   * away, perhaps there should then be a new notification which does not
   * reference the gone objects but informs the user.
   *
   * @param int $type
   *
   * @param string $destinationPath
   *
   * @param string $userId
   *
   * @return void
   */
  public function deleteNotification(int $type, string $destinationPath, string $userId):void
  {
    $type |= (str_starts_with($destinationPath, Constants::USER_FOLDER_PREFIX) ? Notifier::TYPE_FILESYSTEM : Notifier::TYPE_DOWNLOAD);
    $notification = $this->notificationManager->createNotification();
    $notification
      ->setUser($userId)
      ->setApp($this->appName)
      ->setObject('target', md5($destinationPath));
    if ($type != Notifier::TYPE_ANY) {
      $notification->setSubject((string)$type);
    }
    $this->notificationManager->markProcessed($notification);
  }
}
