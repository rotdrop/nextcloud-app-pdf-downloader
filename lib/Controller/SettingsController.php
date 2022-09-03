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
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IL10N;

use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\AnyToPdf;

class SettingsController extends Controller
{
  use \OCA\PdfDownloader\Traits\ResponseTrait;
  use \OCA\PdfDownloader\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Traits\UtilTrait;

  const ADMIN_DISABLE_BUILTIN_CONVERTERS = 'disableBuiltinConverters';
  const ADMIN_FALLBACK_CONVERTER = 'fallbackConverter';
  const ADMIN_UNIVERSAL_CONVERTER = 'universalConverter';
  const ADMIN_CONVERTERS = 'converters';

  const EXTRACT_ARCHIVE_FILES = 'extractArchiveFiles';
  const ARCHIVE_SIZE_LIMIT = 'archiveSizeLimit';

  const PERSONAL_PAGE_LABELS = 'pageLabels';
  const PERSONAL_PAGE_LABELS_FONT = 'pageLabelsFont';
  const PERSONAL_GENERATED_PAGES_FONT = 'generatedPagesFont';

  const ADMIN_SETTING = 'Admin';
  const EXTRACT_ARCHIVE_FILES_ADMIN = self::EXTRACT_ARCHIVE_FILES . self::ADMIN_SETTING;
  const ARCHIVE_SIZE_LIMIT_ADMIN = self::ARCHIVE_SIZE_LIMIT . self::ADMIN_SETTING;

  /**
   * @var array<string, array>
   *
   * Admin settings with r/w flag and default value (booleans)
   */
  const ADMIN_SETTINGS = [
    self::EXTRACT_ARCHIVE_FILES => [  'rw' => true, 'default' => false ],
    self::ARCHIVE_SIZE_LIMIT => [ 'rw' => true, ],
    self::ADMIN_DISABLE_BUILTIN_CONVERTERS => [  'rw' => true, 'default' => false ],
    self::ADMIN_FALLBACK_CONVERTER => [ 'rw' => true, ],
    self::ADMIN_UNIVERSAL_CONVERTER => [ 'rw' => true, ],
    self::ADMIN_CONVERTERS => [ 'rw' => false, ],
  ];

  /**
   * @var array<string, array>
   *
   * Personal settings with r/w flag and default value (booleans)
   */
  const PERSONAL_SETTINGS = [
    self::EXTRACT_ARCHIVE_FILES => [ 'rw' => true, 'default' => self::ADMIN_SETTING ],
    self::ARCHIVE_SIZE_LIMIT => [ 'rw' => true, ],
    self::EXTRACT_ARCHIVE_FILES_ADMIN => [ 'rw' => false, 'default' => false ],
    self::ARCHIVE_SIZE_LIMIT_ADMIN => [ 'rw' => false, ],
    self::PERSONAL_PAGE_LABELS => [ 'rw' => true, 'default' => true ],
    self::PERSONAL_PAGE_LABELS_FONT => [ 'rw' => true, ],
    self::PERSONAL_GENERATED_PAGES_FONT => [ 'rw' => true, ],
  ];

  /** @var IAppContainer */
  private $appContainer;

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
    , IAppContainer $appContainer
  ) {
    parent::__construct($appName, $request);
    $this->logger = $logger;
    $this->l = $l10n;
    $this->config = $config;
    $this->userId = $userId;
    $this->appContainer = $appContainer;
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
  public function setAdmin(string $setting, $value, bool $force = false):DataResponse
  {
    if (!isset(self::ADMIN_SETTINGS[$setting])) {
      return self::grumble($this->l->t('Unknown personal setting: "%1$s"', $setting));
    }
    if (!(self::ADMIN_SETTINGS[$setting]['rw'] ?? false)) {
      return self::grumble($this->l->t('The personal setting "%1$s" is read-only', $setting));
    }
    $oldValue = $this->config->getAppValue($this->appName, $setting);
    switch ($setting) {
      case self::ADMIN_DISABLE_BUILTIN_CONVERTERS:
      case self::EXTRACT_ARCHIVE_FILES:
        $newValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($newValue === null) {
          return self::grumble($this->l->t('Value "%1$s" for setting "%2$s" is not convertible to boolean.', [ $value, $setting ]));
        }
        if ($newValue === (self::ADMIN_SETTINGS[$setting]['default'] ?? false)) {
          $newValue = null;
        } else {
          $newValue = (int)$newValue;
        }
        break;
      case self::ADMIN_FALLBACK_CONVERTER:
      case self::ADMIN_UNIVERSAL_CONVERTER:
        $newValue = $value;
        break;
      case self::ARCHIVE_SIZE_LIMIT:
        $newValue = $this->parseMemorySize($value);
        break;
      default:
        return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
    }

    if ($newValue === null) {
      $this->config->deleteAppValue($this->appName, $setting);
    } else {
      $this->config->setAppValue($this->appName, $setting, $newValue);
    }

    return new DataResponse([
      'newValue' => $newValue,
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
  public function getAdmin(?string $setting = null):DataResponse
  {
    if ($setting === null) {
      $allSettings = self::ADMIN_SETTINGS;
    } else {
      $allSettings = [ $setting ];
    }
    $results = [];
    foreach ($allSettings as $oneSetting => $info) {
      switch ($oneSetting) {
        case self::ADMIN_DISABLE_BUILTIN_CONVERTERS:
        case self::EXTRACT_ARCHIVE_FILES:
          $value = $this->config->getAppValue($this->appName, $oneSetting);
          if ($value === '' || $value === null) {
            $value = self::ADMIN_SETTING[$oneSetting]['default'] ?? false;
          }
          $value = (int)$value;
          break;
        case self::ARCHIVE_SIZE_LIMIT:
          $value = $this->config->getAppValue($this->appName, $oneSetting, null);
          $value = $value ? (int)$value : '';
          break;
        case self::ADMIN_FALLBACK_CONVERTER:
        case self::ADMIN_UNIVERSAL_CONVERTER:
          $value = $this->config->getAppValue($this->appName, $oneSetting);
          break;
        case self::ADMIN_CONVERTERS:
          /** @var AnyToPdf $anyToPdf */
          $anyToPdf = $this->appContainer->get(AnyToPdf::class);

          $anyToPdf->disableBuiltinConverters($this->config->getAppValue($this->appName, self::ADMIN_DISABLE_BUILTIN_CONVERTERS, false));
          $anyToPdf->setFallbackConverter($this->config->getAppValue($this->appName, self::ADMIN_FALLBACK_CONVERTER, null));
          $anyToPdf->setUniversalConverter($this->config->getAppValue($this->appName, self::ADMIN_UNIVERSAL_CONVERTER, null));

          $value = $anyToPdf->findConverters();
          break;
        default:
          return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $oneSetting));
      }
      $results[$oneSetting] = $value;
    }

    if ($setting === null) {
      return new DataResponse($results);
    } else {
      return new DataResponse([
        'value' => $results[$setting],
      ]);
    }
  }

  /**
   * @NoAdminRequired
   */
  public function setPersonal(string $setting, $value)
  {
    if (!isset(self::PERSONAL_SETTINGS[$setting])) {
      return self::grumble($this->l->t('Unknown personal setting: "%1$s"', $setting));
    }
    if (!(self::PERSONAL_SETTINGS[$setting]['rw'] ?? false)) {
      return self::grumble($this->l->t('Thge personal setting "%1$s" is read-only', $setting));
    }
    $oldValue = $this->config->getUserValue($this->userId, $this->appName, $setting);
    switch ($setting) {
      case self::EXTRACT_ARCHIVE_FILES:
      case self::PERSONAL_PAGE_LABELS:
        $newValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($newValue === null) {
          return self::grumble($this->l->t('Value "%1$s" for setting "%2$s" is not convertible to boolean.', [ $value, $setting ]));
        }
        if ($newValue === (self::PERSONAL_SETTINGS[$setting]['default'] ?? false)) {
          $newValue = null;
        } else {
          $newValue = (int)$newValue;
        }
        break;
      case self::PERSONAL_GENERATED_PAGES_FONT:
      case self::PERSONAL_PAGE_LABELS_FONT:
        $newValue = $value;
        if (empty($newValue)) {
          $newValue = null;
        }
        break;
      case self::ARCHIVE_SIZE_LIMIT:
        $newValue = $this->parseMemorySize($value);
        break;
      default:
        return self::grumble($this->l->t('Unknown personal setting: "%s".', [ $setting ]));
    }
    if ($newValue === null) {
      $this->config->deleteUserValue($this->userId, $this->appName, $setting);
    } else {
      $this->config->setUserValue($this->userId, $this->appName, $setting, $newValue);
    }
    return new DataResponse([
      'newValue' => $newValue,
      'oldValue' => $oldValue,
    ]);
  }

  /**
   * @NoAdminRequired
   */
  public function getPersonal(?string $setting = null)
  {
    if ($setting === null) {
      $allSettings = self::PERSONAL_SETTINGS;
    } else {
      if (!isset(self::PERSONAL_SETTINGS[$setting])) {
        return self::grumble($this->l->t('Unknown personal setting: "%1$s"', $setting));
      }
      $allSettings = [ $setting => self::PERSONAL_SETTINGS[$setting] ];
    }
    $results = [];
    foreach ($allSettings as $oneSetting => $info) {
      if (str_ends_with($oneSetting, self::ADMIN_SETTING)) {
        $value = $this->config->getAppValue($this->appName, substr($oneSetting, 0, -strlen(self::ADMIN_SETTING)));
      } else {
        $value = $this->config->getUserValue($this->userId, $this->appName, $oneSetting);
      }
      switch ($oneSetting) {
        case self::ARCHIVE_SIZE_LIMIT:
        case self::ARCHIVE_SIZE_LIMIT_ADMIN:
          $value = $value ? (int)$value : '';
          break;
        case self::EXTRACT_ARCHIVE_FILES_ADMIN:
        case self::EXTRACT_ARCHIVE_FILES:
        case self::PERSONAL_PAGE_LABELS:
          if ($value === '' || $value === null) {
            $value = self::PERSONAL_SETTINGS[$oneSetting]['default'] ?? false;
            if ($value === self::ADMIN_SETTING) {
              $value = $this->config->getAppValue($this->appName, $oneSetting, self::ADMIN_SETTINGS[$oneSetting]['default'] ?? false);
            }
          }
          $value= (int)$value;
          break;
        case self::PERSONAL_GENERATED_PAGES_FONT:
          if (empty($value)) {
            /** @var MultiPdfDownloadController $downloadController */
            $downloadController = $this->appContainer->get(MultiPdfDownloadController::class);
            $value = $downloadController->getErrorPagesFont();
          }
          break;
        case self::PERSONAL_PAGE_LABELS_FONT:
          if (empty($value)) {
            /** @var PdfCombiner $pdfCombiner */
            $pdfCombiner = $this->appContainer->get(PdfCombiner::class);
            $value = $pdfCombiner->getOverlayFont();
          }
          break;
        default:
          return self::grumble($this->l->t('Unknown personal setting: "%1$s"', $oneSetting));
      }
      $results[$oneSetting] = $value;
    }

    if ($setting === null) {
      return new DataResponse($results);
    } else {
      return new DataResponse([
        'value' => $results[$setting],
      ]);
    }
  }

  private function parseMemorySize(string $stringValue):?string
  {
    if ($stringValue === '') {
      $stringValue = null;
    }
    if ($stringValue !== null) {
      $newValue = $this->storageValue($stringValue);
      $newValue = filter_var($newValue, FILTER_VALIDATE_INT, [ 'min_range' => 0 ]);
      if ($newValue === false) {
        return self::grumble($this->l->t('Unable to parse memory size limit "%s"', $stringValue));
      }
      if ($newValue === 0) {
        $newValue = null;
      }
    }
    return $newValue;
  }
}
