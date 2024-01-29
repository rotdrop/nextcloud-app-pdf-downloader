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
use InvalidArgumentException;

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
   * @param string $destinationPath As returned by Node::getPath() or relative
   * to the top level folder.
   */
  private function getJobTypeFromDestinationPath(string $destinationPath):int
  {
    list($one, $two,) = explode(Constants::PATH_SEPARATOR, trim($destinationPath, Constants::PATH_SEPARATOR), 3);

    $result = ($two == Constants::USER_FOLDER_PREFIX) ? Notifier::TYPE_FILESYSTEM : Notifier::TYPE_DOWNLOAD;

    $this->logInfo('ONE / TWO IS ' . $one . ' || ' . $two . ' RESULT ' . $result . ' DEST' . $destinationPath);

    return $result;
  }

  /**
   * @param string $userId
   *
   * @param Node $sourceNode
   *
   * @param string $destinationPath
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
      sourceId: $sourceNode->getId(),
      sourcePath: $sourcePath,
      destinationPath: $destinationPath,
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
    $sourcePath = $job->getSourcePath();
    $sourceId = $job->getSourceId();
    $oldDestinationPath = $job->getDestinationPath();

    $this->deleteNotification(
      Notifier::TYPE_SCHEDULED,
      $userId,
      sourceId: $sourceId,
      destinationPath: $oldDestinationPath,
    );

    $notification = $this->buildNotification(
      Notifier::TYPE_SUCCESS,
      $userId,
      sourceId: $sourceId,
      sourcePath: $sourcePath,
      destinationId: $file->getId(),
      destinationPath: $file->getPath(),
    );

    $this->logInfo('NOTIFY SUCCESS');
    $this->notificationManager->notify($notification);
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
    $sourcePath = $job->getSourcePath();
    $sourceId = $job->getSourceId();
    $destinationPath = $job->getDestinationPath();

    $this->deleteNotification(
      Notifier::TYPE_SCHEDULED,
      $userId,
      sourceId: $sourceId,
      destinationPath: $destinationPath,
    );

    $notification = $this->buildNotification(
      Notifier::TYPE_FAILURE,
      $userId,
      sourceId: $sourceId,
      sourcePath: $sourcePath,
      destinationPath: $destinationPath,
      errorMessage: $errorMessage,
    );

    $this->logInfo('NOTIFY FAILURE');
    $this->notificationManager->notify($notification);
  }

  public function sendNotificationOnCancel()
  {
  }

  /**
   * Send a notification when either a temporary download file has been
   * cleaned out by the background job from the app-storage or the
   * corresponding file has been deleted from the user's file space. This is
   * in order to clean out notifications which refer to existing files. They
   * are confusing and lead to errors when referring to no longer existing
   * file-system objects.
   *
   * @param string $userId
   *
   * @param int $sourceId
   *
   * @param int $destinationId
   *
   * @param string $destinationPath
   *
   * @param int $destinationMTime
   *
   * @return void
   */
  public function sendNotificationOnClean(
    string $userId,
    int $sourceId,
    int $destinationId,
    string $destinationPath,
    int $destinationMTime,
  ):void {
    $this->deleteNotification(
      Notifier::TYPE_SUCCESS,
      $userId,
      destinationId: $destinationId,
      destinationPath: $destinationPath,
    );

    $notification = $this->buildNotification(
      Notifier::TYPE_CLEANED,
      userId: $userId,
      sourceId: $sourceId,
      destinationId: $destinationId,
      sourcePath: 'irrelevant',
      destinationPath: $destinationPath,
    );

    $subject = $notification->getSubject();
    $subjectParameters = $notification->getSubjectParameters();
    $subjectParameters['destinationMTime'] = $destinationMTime;

    $message = $notification->getMessage();
    $messageParameters = $notification->getMessageParameters();
    $messageParameters['destinationMTime'] = $destinationMTime;

    $notification
      ->setSubject($subject, $subjectParameters)
      ->setMessage($message, $messageParameters);

    $this->logInfo('NOTIFY CLEAN');
    $this->notificationManager->notify($notification);
  }

  /**
   * @param int $type
   *
   * @param string $userId
   *
   * @param int $sourceId
   *
   * @param int $destinationId
   *
   * @param null|string $sourcePath
   *
   * @param null|string $destinationPath
   *
   * @param null|string $errorMessage Optional error message when emitting
   * failure notifications.
   *
   * @return INotification
   *
   * @throws InvalidArgumentException
   */
  private function buildNotification(
    int $type,
    string $userId,
    int $sourceId = -1,
    int $destinationId = -1,
    ?string $sourcePath = null,
    ?string $destinationPath = null,
    ?string $errorMessage = null,
  ):INotification {
    $notification = $this->notificationManager->createNotification();
    if (!empty($destinationPath)) {
      $type &= ~(Notifier::TYPE_FILESYSTEM|Notifier::TYPE_DOWNLOAD);
      $type |= self::getJobTypeFromDestinationPath($destinationPath);
    }
    if (($type & Notifier::TYPE_FILESYSTEM|Notifier::TYPE_DOWNLOAD) == 0) {
      throw new InvalidArgumentException('The subject must indicate whether the notification refers to a download or store-in-cloud request.');
    }
    if ($type & (Notifier::TYPE_SCHEDULED|Notifier::TYPE_FAILURE|Notifier::TYPE_CANCELLED)) {
      if ($sourceId <= 0) {
        throw new InvalidArgumentException('Source-id must be given for notification subject = ' . $type);
      }
      $objectType = 'sourceId';
      $objectId = $sourceId;
    } else {
      if ($destinationId <= 0) {
        throw new InvalidArgumentException('Destination-id must be given for notification subject = ' . $type);
      }
      $objectType = 'destinationId';
      $objectId = $destinationId;
    }
    $sourcePath = $sourcePath ?? '';
    $parameters = [
      'sourceId' => $sourceId,
      'sourcePath' => $sourcePath,
      'sourceDirectory' => dirname($sourcePath),
      'sourceDirectoryName' => basename(dirname($sourcePath)),
      'sourceBaseName' => basename($sourcePath),
      'destinationId' => $destinationId,
      'destinationPath' => $destinationPath,
      'destinationDirectory' => dirname($destinationPath),
      'destinationDirectoryName' => basename(dirname($destinationPath)),
      'destinationBaseName' => basename($destinationPath),
      'errorMessage' => $errorMessage,
    ];

    $notification->setUser($userId)
      ->setApp($this->appName)
      ->setObject($objectType, (string)$objectId)
      ->setSubject((string)$type, $parameters)
      ->setMessage((string)$type, $parameters)
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
   * @param string $userId
   *
   * @param int $sourceId
   *
   * @param int $destinationId
   *
   * @return void
   *
   * @throws InvalidArgumentException
   */
  public function deleteNotification(
    int $type,
    string $userId,
    int $sourceId = -1,
    int $destinationId = -1,
    ?string $destinationPath = null,
  ):void {
    if ($type == Notifier::TYPE_ANY) {
      if (($sourceId <= 0) == ($destinationId <= 0)) {
        throw new InvalidArgumentException('Exactly on of "sourceid" and "destination" id must be specified for notification subject = ' . $type);
      }
      if ($sourceId > 0) {
        $objectType = 'sourceId';
        $objectId = $sourceId;
      } else {
        $objectType = 'destinationId';
        $objectId = $destinationId;
      }
    } else {
      if (!empty($destinationPath)) {
        $type &= ~(Notifier::TYPE_FILESYSTEM|Notifier::TYPE_DOWNLOAD);
        $type |= self::getJobTypeFromDestinationPath($destinationPath);
      }
      if ($type & (Notifier::TYPE_SCHEDULED|Notifier::TYPE_FAILURE|Notifier::TYPE_CANCELLED)) {
        if ($sourceId <= 0) {
          throw new InvalidArgumentException('Source-id must be given for notification subject = ' . $type);
        }
        $objectType = 'sourceId';
        $objectId = $sourceId;
      } else {
        if ($destinationId <= 0) {
          throw new InvalidArgumentException('Destination-id must be given for notification subject = ' . $type);
        }
        $objectType = 'destinationId';
        $objectId = $destinationId;
      }
    }
    $notification = $this->notificationManager->createNotification();
    $notification
      ->setUser($userId)
      ->setApp($this->appName)
      ->setObject($objectType, (string)$objectId);
    if ($type != Notifier::TYPE_ANY) {
      $notification->setSubject((string)$type);
    }
    $this->logInfo('REQUEST CLEAN OF ' . $type . ' ' . $destinationPath . ' ' . $userId);
    $this->notificationManager->markProcessed($notification);
  }
}
