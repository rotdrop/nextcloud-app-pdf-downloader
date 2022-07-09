<?php
/**
 * @copyright Copyright (c) 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
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
 *
 */

namespace OCA\PdfDownloader\Controller;

use Psr\Log\LoggerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IL10N;

use OCA\PdfDownloader\Service\GroupFoldersService;
use OCA\PdfDownloader\Service\ProjectGroupService;

class SettingsController extends Controller
{
  use \OCA\PdfDownloader\Traits\ResponseTrait;
  use \OCA\PdfDownloader\Traits\LoggerTrait;

  const EXAMPLE_SETTING_KEY = 'example';

  /** @var IConfig */
  private $config;

  /** @var IL10N */
  private $l;

  /** @var string */
  private $userId;

  public function __construct(
    string $appName
    , IRequest $request
    , $userId
    , LoggerInterface $logger
    , IL10N $l10n
    , IConfig $config
  ) {
    parent::__construct($appName, $request);
    $this->logger = $logger;
    $this->l = $l10n;
    $this->config = $config;
    $this->userId = $userId;
  }

  /**
   * @AuthorizedAdminSetting(settings=OCA\GroupFolders\Settings\Admin)
   *
   * @param string $setting
   *
   * @param null|string $value
   *
   * @return DataResponse
   */
  public function setAdmin(string $setting, ?string $value, bool $force = false):DataResponse
  {
    $newValue = $value;
    $oldValue = $this->config->getAppValue($this->appName, $setting);
    switch ($setting) {
      case self::EXAMPLE_SETTING_KEY:
        break;
      default:
        return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
    }
    $this->config->setAppValue($this->appName, $setting, $newValue);
    return new DataResponse([
      'oldValue' => $oldValue,
    ]);
  }

  /**
   * @AuthorizedAdminSetting(settings=OCA\GroupFolders\Settings\Admin)
   *
   * @param string $setting
   *
   * @return DataResponse
   */
  public function getAdmin(string $setting):DataResponse
  {
    $result = null;
    switch ($setting) {
      case self::EXAMPLE_SETTING_KEY:
        return new DataResponse([
          'value' => $this->config->getAppValue($this->appName, $setting),
        ]);
    }
    return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
  }


  /**
   * Export some of the admin settings
   *
   * @NoAdminRequired
   *
   * @param string $setting
   *
   * @return DataResponse
   */
  public function getApp(string $setting):DataResponse
  {
    switch ($setting) {
      case self::EXAMPLE_SETTING_KEY:
        return $this->getAdmin($setting);
      default:
        return self::grumble($this->l->t('Unknown app setting: "%1$s"', $setting));
    }
  }

  /**
   * @NoAdminRequired
   */
  public function setPersonal(string $setting, $value)
  {
    $oldValue = $this->config->getUserValue($this->userId, $this->appName, $setting);
    $this->config->setUserValue($this->userId, $this->appName, $setting, $value);
    return new DataResponse([
      'oldValue' => $oldValue,
    ]);
  }

  /**
   * @NoAdminRequired
   */
  public function getPersonal(string $setting)
  {
    return new DataResponse([
      'value' => $this->config->getUserValue($this->userId, $this->appName, $setting),
    ]);
  }
}
