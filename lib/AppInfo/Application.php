<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author    Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\PdfDownloader\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IConfig;

use Psr\Container\ContainerInterface;

use OCA\PdfDownloader\Listener\Registration as ListenerRegistration;
use OCA\PdfDownloader\Exceptions;
use OCA\FilesArchive\Service\MimeTypeService;

/**
 * App entry point.
 */
class Application extends App implements IBootstrap
{
  use \OCA\PdfDownloader\Traits\AppNameTrait;

  /** Constructor. */
  public function __construct()
  {
    $this->appName = $this->getAppInfoAppName();
    parent::__construct($this->appName);
  }

  /**
   * Called later than "register".
   *
   * @param IBootContext $context
   *
   * @return void
   */
  public function boot(IBootContext $context): void
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
    if ((include_once __DIR__ . '/../../vendor/autoload.php') === false) {
      throw new Exceptions\Exception('Cannot include autoload. Did you run install dependencies using composer?');
    }

    // Register listeners
    ListenerRegistration::register($context);
  }
}
