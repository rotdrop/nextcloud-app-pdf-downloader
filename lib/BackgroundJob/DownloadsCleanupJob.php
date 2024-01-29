<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2024 Claus-Justus Heine <himself@claus-justus-heine.de>
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

use OCP\BackgroundJob\TimedJob;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface as ILogger;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IConfig;
use OCP\Files\IRootFolder;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\Node;

use OCA\PdfDownloader\Controller\SettingsController;
use OCA\PdfDownloader\Service\NotificationService;

/**
 * Remove stale downloads after a configured (or default) time.
 */
class DownloadsCleanupJob extends TimedJob
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\UserRootFolderTrait;

  public const DEFAULT_CLEANUP_INTERVAL = 24 * 3600 * 1; // check once a day
  public const DEFAULT_TIME_TO_KEEP = SettingsController::PERSONAL_DOWNLOADS_PURGE_TIMEOUT_DEFAULT;
  public const CLEANUP_INTERVAL_KEY = 'cleanup_interval_seconds';

  public const USER_ID_KEY = 'userId';

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ITimeFactory $timeFactory,
    protected $appName,
    private IConfig $cloudConfig,
    protected ILogger $logger,
    private IUserSession $userSession,
    private IUserManager $userManager,
    protected IRootFolder $rootFolder,
    private IJobList $jobList,
    private NotificationService $notificationService,
  ) {
    parent::__construct($timeFactory);
    $this->setInterval($this->cloudConfig->getAppValue($this->appName, self::CLEANUP_INTERVAL_KEY, self::DEFAULT_CLEANUP_INTERVAL));
    $this->setTimeSensitivity(self::TIME_INSENSITIVE);
  }
  // phpcs:enable

  /**
   * @return string
   *
   * @throws InvalidArgumentException
   */
  public function getUserId():string
  {
    $sourcePath = $this->argument[self::USER_ID_KEY] ?? null;
    if (empty($sourcePath)) {
      throw new InvalidArgumentException('User id argument is empty.');
    }
    return $sourcePath;
  }

  /** {@inheritdoc} */
  protected function run($argument)
  {
    try {
      $user = $this->userManager->get($this->getUserId());
      if (empty($user)) {
        throw new InvalidArgumentException('No user found for user-id ' . $this->getUserId());
      }
      $this->userSession->setUser($user);
      $this->userId = $user->getUID();

      $now = $this->time->getTime();

      $userAppFolder = $this->getUserAppFolder();

      $cacheDirectories = $userAppFolder->getDirectoryListing();
      $deletedCaches = 0;

      $purgeTimeout = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_DOWNLOADS_PURGE_TIMEOUT,
        SettingsController::PERSONAL_DOWNLOADS_PURGE_TIMEOUT_DEFAULT,
      );

      /** @var Folder $folder */
      foreach ($cacheDirectories as $folder) {
        if ($folder->getType() !== FileInfo::TYPE_FOLDER) {
          $folder->delete();
          ++$deletedCaches;
          continue;
        }
        $sourceId = (int)$folder->getName();
        $folderCacheNodes = $folder->getDirectoryListing();
        $numberDeleted = 0;
        /** @var Node $cacheNode */
        foreach ($folderCacheNodes as $cacheNode) {
          // Note that Node::getCreationTime() is not usable. It is almost
          // ever set to 0. Use MTime.
          if ($cacheNode->getMTime() + $purgeTimeout < $now) {
            $cacheNodeId = $cacheNode->getId();
            $cacheNodePath = $cacheNode->getPath();
            $cacheNodeMTime = $cacheNode->getMTime();
            $cacheNode->delete();
            ++$numberDeleted;
            $this->notificationService->sendNotificationOnClean(
              userId: $this->userId,
              sourceId: $sourceId,
              destinationId: $cacheNodeId,
              destinationPath: $cacheNodePath,
              destinationMTime: $cacheNodeMTime,
            );
          }
        }
        if ($numberDeleted == count($folderCacheNodes)) {
          $folder->delete();
          ++$deletedCaches;
        }
      }

      if ($deletedCaches === count($cacheDirectories)) {
        $this->logInfo('Nothing more to do, removing us from the run-list');
        // remove us from the list of jobs
        $this->jobList->remove($this);
      }

      \OC_Util::tearDownFS();

    } catch (Throwable $t) {
      $this->logger->error('Failed to cleanup stale composite PDF files.', [ 'exception' => $t ]);
    }
  }
}
