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

namespace OCA\PdfDownloader\Listener;

use Psr\Log\LoggerInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Services\IInitialState;
use OCP\IUserSession;
use OCP\Contacts\IManager as IContactsManager;
use OCP\IConfig as CloudConfig;

use OCA\Files\Event\LoadAdditionalScriptsEvent as HandledEvent;

use OCA\PdfDownloader\Service\AssetService;
use OCA\PdfDownloader\Controller\SettingsController;
use OCA\RotDrop\Toolkit\Service\MimeTypeService;

/**
 * In particular listen to the asset-loading events.
 */
class FilesActionListener implements IEventListener
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\CloudAdminTrait;

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

    /** @var MimeTypeService $mimeTypeService */
    $mimeTypeService = $this->appContainer->get(MimeTypeService::class);
    $archiveMimeTypes = $mimeTypeService->setAppPath(__DIR__ . '/../../')->getSupportedMimeTypes();

    // just admin contact and stuff to make the ajax error handlers work.
    $groupManager = $this->appContainer->get(\OCP\IGroupManager::class);
    $initialState->provideInitialState('config', [
      'adminContact' => $this->getCloudAdminContacts($groupManager, implode: true),
      'phpUserAgent' => $_SERVER['HTTP_USER_AGENT'], // @@todo get in javascript from request
      SettingsController::EXTRACT_ARCHIVE_FILES => $extractArchiveFilesUser,
      SettingsController::EXTRACT_ARCHIVE_FILES_ADMIN => $extractArchiveFilesAdmin,
      'archiveMimeTypes' => $archiveMimeTypes,
    ]);

    // $this->logInfo('MIME ' . print_r($archiveMimeTypes, true));

    /** @var AssetService $assetService */
    $assetService = $this->appContainer->get(AssetService::class);
    list('asset' => $scriptAsset,) = $assetService->getJSAsset(self::BASENAME);
    list('asset' => $styleAsset,) = $assetService->getCSSAsset(self::BASENAME);
    \OCP\Util::addScript($appName, $scriptAsset);
    \OCP\Util::addStyle($appName, $styleAsset);
  }
}



// Local Variables: ***
// c-basic-offset: 2 ***
// indent-tabs-mode: nil ***
// End: ***
