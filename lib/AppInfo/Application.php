<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author    Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023, 2024 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @license   AGPL-3.0-or-later
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

// phpcs:disable PSR1.Files.SideEffects
// phpcs:ignore PSR1.Files.SideEffects

namespace OCA\PdfDownloader\AppInfo;

use Exception;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IConfig;

use OCA\PdfDownloader\Service\MimeTypeService;

use OCA\PdfDownloader\Listener\Registration as ListenerRegistration;
use OCA\PdfDownloader\Notification;

include_once __DIR__ . '/../../vendor/autoload.php';

/**
 * App entry point.
 */
class Application extends App implements IBootstrap
{
  use \OCA\PdfDownloader\Toolkit\Traits\AppNameTrait;

  /** Constructor. */
  public function __construct()
  {
    $appName = $this->getAppInfoAppName(__DIR__);
    parent::__construct($appName);
  }

  /**
   * Called later than "register".
   *
   * @param IBootContext $context
   *
   * @return void
   */
  public function boot(IBootContext $context):void
  {
    $context->injectFn(function(MimeTypeService $mimeTypeService) {
      $mimeTypeService->registerMimeTypeMappings();
    });
  }

  /**
   * Called earlier than boot, so anything initialized in the
   * "boot()" method must not be used here.
   *
   * @param IRegistrationContext $context
   *
   * @return void
   */
  public function register(IRegistrationContext $context): void
  {
    // Register listeners
    ListenerRegistration::register($context);

    $context->registerNotifierService(Notification\Notifier::class);
    $context->registerNotifierService(Notification\DependenciesNotifier::class);
  }
}
