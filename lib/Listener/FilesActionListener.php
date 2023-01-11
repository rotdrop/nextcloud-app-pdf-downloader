<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\PdfDownloader\Listener;

use Psr\Log\LoggerInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IConfig as CloudConfig;
use OCP\IL10N;

use OCA\Files\Event\LoadAdditionalScriptsEvent as HandledEvent;

use OCA\RotDrop\Toolkit\Service\MimeTypeService;

use OCA\PdfDownloader\Controller\MultiPdfDownloadController;
use OCA\PdfDownloader\Controller\SettingsController;
use OCA\PdfDownloader\Service\FileSystemWalker;
use OCA\PdfDownloader\Constants;

/**
 * In particular listen to the asset-loading events.
 */
class FilesActionListener implements IEventListener
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\CloudAdminTrait;
  use \OCA\RotDrop\Toolkit\Traits\AssetTrait;

  const EVENT = HandledEvent::class;

  const BASENAME = 'files-action';

  /** @var IAppContainer */
  private $appContainer;

  /** @var bool */
  private $handled = false;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(IAppContainer $appContainer)
  {
    $this->appContainer = $appContainer;
  }

  /**
   * @param Event $event
   *
   * @SuppressWarnings(PHPMD.Superglobals)
   *
   * @return void
   */
  public function handle(Event $event): void
  {
    if (!($event instanceof HandledEvent)) {
      return;
    }
    /** @var HandledEvent $event */

    // this really only needs to be executed once per request.
    if ($this->handled) {
      return;
    }
    $this->handled = true;

    /** @var IUserSession $userSession */
    $userSession = $this->appContainer->get(IUserSession::class);
    $user = $userSession->getUser();

    if (empty($user)) {
      return;
    }

    $userId = $user->getUID();

    $appName = $this->appContainer->get('appName');

    $this->l = $this->appContainer->get(IL10N::class);

    $this->logger = $this->appContainer->get(LoggerInterface::class);

    /** @var CloudConfig $cloudConfig */
    $cloudConfig = $this->appContainer->get(CloudConfig::class);

    /** @var IInitialState $initialState */
    $initialState = $this->appContainer->get(IInitialState::class);

    $extractArchiveFilesAdmin =  $cloudConfig->getAppValue(
      $appName, SettingsController::EXTRACT_ARCHIVE_FILES, false
    );
    $extractArchiveFilesUser = $cloudConfig->getUserValue(
      $userId, $appName, SettingsController::EXTRACT_ARCHIVE_FILES, $extractArchiveFilesAdmin
    );
    /** @var FileSystemWalker $fileSystemWalker */
    $fileSystemWalker = $this->appContainer->get(FileSystemWalker::class);
    $pdfFileNameTemplate = $cloudConfig->getUserValue(
      $userId,
      $appName,
      SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE,
      $fileSystemWalker->getDefaultPdfFileNameTemplate(),
    );
    $pdfCloudFolderPath = $cloudConfig->getUserValue(
      $userId,
      $appName,
      SettingsController::PERSONAL_PDF_CLOUD_FOLDER_PATH,
      null,
    );
    $createPageLabels = $cloudConfig->getUserValue(
      $userId,
      $appName,
      SettingsController::PERSONAL_PAGE_LABELS,
      SettingsController::PERSONAL_PAGE_LABELS_DEFAULT,
    );
    $useBackgroundJobsDefault = $cloudConfig->getUserValue(
      $userId,
      $appName,
      SettingsController::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT,
      SettingsController::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT_DEFAULT,
    );
    $downloadsPurgeTimeout = $cloudConfig->getUserValue(
      $userId,
      $appName,
      SettingsController::PERSONAL_DOWNLOADS_PURGE_TIMEOUT,
      SettingsController::PERSONAL_DOWNLOADS_PURGE_TIMEOUT_DEFAULT,
    );

    /** @var MimeTypeService $mimeTypeService */
    $mimeTypeService = $this->appContainer->get(MimeTypeService::class);
    $archiveMimeTypes = $mimeTypeService->setAppPath(__DIR__ . '/../../')->getSupportedMimeTypes();

    // just admin contact and stuff to make the ajax error handlers work.
    $this->groupManager = $this->appContainer->get(\OCP\IGroupManager::class);
    $initialState->provideInitialState('config', [
      'adminContact' => $this->getCloudAdminContacts(implode: true),
      'phpUserAgent' => $_SERVER['HTTP_USER_AGENT'], // @@todo get in javascript from request
      SettingsController::EXTRACT_ARCHIVE_FILES => $extractArchiveFilesUser,
      SettingsController::EXTRACT_ARCHIVE_FILES_ADMIN => $extractArchiveFilesAdmin,
      SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE => $pdfFileNameTemplate,
      SettingsController::PERSONAL_PDF_CLOUD_FOLDER_PATH => $pdfCloudFolderPath,
      SettingsController::PERSONAL_PAGE_LABELS => $createPageLabels,
      SettingsController::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT => $useBackgroundJobsDefault,
      SettingsController::PERSONAL_DOWNLOADS_PURGE_TIMEOUT => $downloadsPurgeTimeout,
      'archiveMimeTypes' => $archiveMimeTypes,
    ]);

    // $this->logInfo('MIME ' . print_r($archiveMimeTypes, true));

    $this->initializeAssets(__DIR__);
    list(Constants::ASSET => $scriptAsset,) = $this->getJSAsset(self::BASENAME);
    list(Constants::ASSET => $styleAsset,) = $this->getCSSAsset(self::BASENAME);
    \OCP\Util::addScript($appName, $scriptAsset);
    \OCP\Util::addStyle($appName, $styleAsset);
  }
}
