<?php
/**
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright Copyright (c) 2023 Claus-Justus Heine
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

namespace OCA\PdfDownloader\Migration;

use DateTime;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface as ILogger;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\IUser;
use OCP\IGroupManager;

use OCA\PdfDownloader\Exceptions;
use OCA\PdfDownloader\Service\DependenciesService;
use OCA\PdfDownloader\Notification\DependenciesNotifier;

/** Check for and (later perhaps) install missing external dependencies. */
class CheckDependencies implements IRepairStep
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\CloudAdminTrait;

  /** @var string */
  protected $appName;

  /** @var INotificationManager */
  protected $notificationManager;

  /** @var IGroupManager */
  protected $groupManager;

  /** @var DependenciesService */
  protected $dependencies;

  // phpcs:disable Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    ILogger $logger,
    INotificationManager $notificationManager,
    IGroupManager $groupManager,
    DependenciesService $dependencies,
  ) {
    $this->appName = $appName;
    $this->logger = $logger;
    $this->notificationManager = $notificationManager;
    $this->groupManager = $groupManager;
    $this->dependencies = $dependencies;
  }
  // phpcs:enable Squiz.Commenting.FunctionComment.Missing

  /** {@inheritdoc} */
  public function getName()
  {
    return 'Register MIME types for ' . $this->appName;
  }

  /** {@inheritdoc} */
  public function run(IOutput $output)
  {
    $dependencies = $this->dependencies->checkForExternalPrograms();

    foreach (DependenciesService::DEPENDENCY_TYPES as $type) {
      if ($dependencies[DependenciesService::MISSING][$type] > 0) {
        $missing = array_keys(array_filter($dependencies[$type], fn($path) => $path ===  'missing'));
        /** @var IUser $adminUser */
        foreach ($this->getCloudAdmins() as $adminUser) {
          $notification = $this->notificationManager->createNotification();
          $notification->setApp($this->appName)
            ->setUser($adminUser->getUID())
            ->setDateTime(new DateTime)
            ->setObject('installation', md5(implode($missing)))
            ->setSubject(DependenciesNotifier::SUBJECTS[$type], [
              DependenciesService::MISSING => $missing,
            ]);
          $this->notificationManager->notify($notification);
        }
      }
    }
  }
}
