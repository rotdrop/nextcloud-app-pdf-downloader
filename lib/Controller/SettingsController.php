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
 *"
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\PdfDownloader\Controller;

use InvalidArgumentException;

use Psr\Log\LoggerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use OCP\IConfig;
use OCP\IL10N;

use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\AnyToPdf;

use OCA\PdfDownloader\Constants;

/**
 * Settings-controller for both, personal and admin, settings.
 */
class SettingsController extends Controller
{
  use \OCA\RotDrop\Toolkit\Traits\ResponseTrait;
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\UtilTrait;

  public const ADMIN_DISABLE_BUILTIN_CONVERTERS = 'disableBuiltinConverters';
  public const ADMIN_FALLBACK_CONVERTER = 'fallbackConverter';
  public const ADMIN_UNIVERSAL_CONVERTER = 'universalConverter';
  public const ADMIN_CONVERTERS = 'converters';

  public const EXTRACT_ARCHIVE_FILES = 'extractArchiveFiles';
  public const ARCHIVE_SIZE_LIMIT = 'archiveSizeLimit';

  public const PERSONAL_PAGE_LABELS = 'pageLabels';
  public const PERSONAL_PAGE_LABELS_FONT = 'pageLabelsFont';
  public const PERSONAL_PAGE_LABELS_FONT_DEFAULT = PdfCombiner::OVERLAY_FONT;
  public const PERSONAL_PAGE_LABELS_FONT_SIZE = 'pageLabelsFontSize';
  public const PERSONAL_PAGE_LABELS_FONT_SIZE_DEFAULT = PdfCombiner::OVERLAY_FONT_SIZE;
  public const PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION = 'pageLabelPageWidthFraction';
  public const PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION_DEFAULT = PdfCombiner::OVERLAY_PAGE_WIDTH_FRACTION;
  public const PERSONAL_PAGE_LABEL_TEMPLATE = 'pageLabelTemplate';

  public const PERSONAL_GENERATED_PAGES_FONT = 'generatedPagesFont';
  public const PERSONAL_GENERATED_PAGES_FONT_DEFAULT = MultiPdfDownloadController::ERROR_PAGES_FONT;
  public const PERSONAL_GENERATED_PAGES_FONT_SIZE = 'generatedPagesFontSize';
  public const PERSONAL_GENERATED_PAGES_FONT_SIZE_DEFAULT = MultiPdfDownloadController::ERROR_PAGES_FONT_SIZE;

  public const DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT = Constants::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT;

  public const ADMIN_SETTING = 'Admin';
  public const EXTRACT_ARCHIVE_FILES_ADMIN = self::EXTRACT_ARCHIVE_FILES . self::ADMIN_SETTING;
  public const ARCHIVE_SIZE_LIMIT_ADMIN = self::ARCHIVE_SIZE_LIMIT . self::ADMIN_SETTING;

  public const PERSONAL_GROUPING = 'grouping';
  public const PERSONAL_GROUP_FOLDERS_FIRST = PdfCombiner::GROUP_FOLDERS_FIRST;
  public const PERSONAL_GROUP_FILES_FIRST = PdfCombiner::GROUP_FILES_FIRST;
  public const PERSONAL_UNGROUPED = PdfCombiner::UNGROUPED;

  public const PERSONAL_PDF_CLOUD_FOLDER_PATH = 'pdfCloudFolderPath';
  public const PERSONAL_PDF_FILE_NAME_TEMPLATE = 'pdfFileNameTemplate';

  /**
   * @var array<string, array>
   *
   * Admin settings with r/w flag and default value (booleans)
   */
  const ADMIN_SETTINGS = [
    self::EXTRACT_ARCHIVE_FILES => [ 'rw' => true, 'default' => false, ],
    self::ARCHIVE_SIZE_LIMIT => [ 'rw' => true, 'default' => self::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT, ],
    self::ADMIN_DISABLE_BUILTIN_CONVERTERS => [  'rw' => true, 'default' => false, ],
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
    self::EXTRACT_ARCHIVE_FILES => [ 'rw' => true, 'default' => self::ADMIN_SETTING, ],
    self::ARCHIVE_SIZE_LIMIT => [ 'rw' => true, ],
    self::EXTRACT_ARCHIVE_FILES_ADMIN => [ 'rw' => false, 'default' => false, ],
    self::ARCHIVE_SIZE_LIMIT_ADMIN => [ 'rw' => false, 'default' => self::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT, ],
    self::PERSONAL_PAGE_LABELS => [ 'rw' => true, 'default' => true, ],
    self::PERSONAL_PAGE_LABELS_FONT => [
      'rw' => true,
      'default' => self::PERSONAL_GENERATED_PAGES_FONT_DEFAULT,
    ],
    self::PERSONAL_PAGE_LABELS_FONT_SIZE => [
      'rw' => true,
      'default' => self::PERSONAL_PAGE_LABELS_FONT_SIZE_DEFAULT,
    ],
    self::PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION => [
      'rw' => true,
      'default' => self::PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION_DEFAULT,
    ],
    self::PERSONAL_PAGE_LABEL_TEMPLATE => [
      'rw' => true,
      'default' => null, // dynamic from PdfCombiner
    ],
    self::PERSONAL_GENERATED_PAGES_FONT => [
      'rw' => true,
      'default' => self::PERSONAL_GENERATED_PAGES_FONT_DEFAULT,
    ],
    self::PERSONAL_GENERATED_PAGES_FONT_SIZE => [
      'rw' => true,
      'default' => self::PERSONAL_GENERATED_PAGES_FONT_SIZE_DEFAULT,
    ],
    self::PERSONAL_GROUPING => [ 'rw' => true, 'default' => self::PERSONAL_GROUP_FOLDERS_FIRST, ],
    self::PERSONAL_PDF_CLOUD_FOLDER_PATH => [
      'rw' => true,
      'default' => null,
    ],
    self::PERSONAL_PDF_FILE_NAME_TEMPLATE => [
      'rw' => true,
      'default' => null,
    ],
  ];

  /** @var IAppContainer */
  private $appContainer;

  /** @var IConfig */
  private $config;

  /** @var IL10N */
  private $l;

  /** @var string */
  private $userId;

  /** @var PdfCombiner */
  private $pdfCombiner;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IRequest $request,
    $userId,
    LoggerInterface $logger,
    IL10N $l10n,
    IConfig $config,
    PdfCombiner $pdfCombiner,
    IAppContainer $appContainer,
  ) {
    parent::__construct($appName, $request);
    $this->logger = $logger;
    $this->l = $l10n;
    $this->config = $config;
    $this->userId = $userId;
    $this->pdfCombiner = $pdfCombiner;
    $this->appContainer = $appContainer;
  }

  /**
   * @param string $setting
   *
   * @param mixed $value
   *
   * @param bool $force
   *
   * @return DataResponse
   *
   * @AuthorizedAdminSetting(settings=OCA\GroupFolders\Settings\Admin)
   * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
   */
  public function setAdmin(string $setting, mixed $value, bool $force = false):DataResponse
  {
    if (!isset(self::ADMIN_SETTINGS[$setting])) {
      return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
    }
    if (!(self::ADMIN_SETTINGS[$setting]['rw'] ?? false)) {
      return self::grumble($this->l->t('The admin setting "%1$s" is read-only', $setting));
    }
    $oldValue = $this->config->getAppValue(
      $this->appName,
      $setting,
      self::ADMIN_SETTINGS[$setting]['default'] ?? null,
    );
    switch ($setting) {
      case self::ADMIN_DISABLE_BUILTIN_CONVERTERS:
      case self::EXTRACT_ARCHIVE_FILES:
        $newValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($newValue === null) {
          return self::grumble($this->l->t(
            'Value "%1$s" for setting "%2$s" is not convertible to boolean.', [
              $value, $setting,
            ]));
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
        try {
          $newValue = $this->parseMemorySize($value);
        } catch (InvalidArgumentException $t) {
          return self::grumble($t->getMessage());
        }
        break;
      default:
        return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
    }

    if ($newValue === null) {
      $this->config->deleteAppValue($this->appName, $setting);
      $newValue = self::ADMIN_SETTINGS[$setting]['default'] ?? null;
    } else {
      $this->config->setAppValue($this->appName, $setting, $newValue);
    }

    switch ($setting) {
      case self::ARCHIVE_SIZE_LIMIT:
        $humanValue = $newValue === null ? '' : $this->formatStorageValue($newValue);
        break;
      default:
        $humanValue = $value;
        break;
    }

    return new DataResponse([
      'newValue' => $newValue,
      'oldValue' => $oldValue,
      'humanValue' => $humanValue,
    ]);
  }

  /**
   * @param string $setting
   *
   * @return DataResponse
   *
   * @AuthorizedAdminSetting(settings=OCA\GroupFolders\Settings\Admin)
   */
  public function getAdmin(?string $setting = null):DataResponse
  {
    if ($setting === null) {
      $allSettings = self::ADMIN_SETTINGS;
    } else {
      if (!isset(self::ADMIN_SETTINGS[$setting])) {
        return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
      }
      $allSettings = [ $setting => self::ADMIN_SETTINGS[$setting] ];
    }
    $results = [];
    foreach (array_keys($allSettings) as $oneSetting) {
      switch ($oneSetting) {
        case self::ADMIN_DISABLE_BUILTIN_CONVERTERS:
        case self::EXTRACT_ARCHIVE_FILES:
          $value = $this->config->getAppValue(
            $this->appName,
            $oneSetting,
            self::ADMIN_SETTINGS[$oneSetting]['default'] ?? false,
          );
          $value = (int)$value;
          $humanValue = $value;
          break;
        case self::ARCHIVE_SIZE_LIMIT:
          $value = $this->config->getAppValue(
            $this->appName,
            $oneSetting,
            self::ADMIN_SETTINGS[$oneSetting]['default'] ?? null,
          );
          if ($value !== null) {
            $value = (int)$value;
            $humanValue = $this->formatStorageValue($value);
          } else {
            $humanValue = '';
          }
          break;
        case self::ADMIN_FALLBACK_CONVERTER:
        case self::ADMIN_UNIVERSAL_CONVERTER:
          $value = $this->config->getAppValue(
            $this->appName,
            $oneSetting,
            self::ADMIN_SETTINGS[$oneSetting]['default'] ?? null,
          );
          $humanValue = $value;
          break;
        case self::ADMIN_CONVERTERS:
          /** @var AnyToPdf $anyToPdf */
          $anyToPdf = $this->appContainer->get(AnyToPdf::class);

          if ($this->config->getAppValue($this->appName, self::ADMIN_DISABLE_BUILTIN_CONVERTERS, false)) {
            $anyToPdf->disableBuiltinConverters();
          } else {
            $anyToPdf->enableBuiltinConverters();
          }
          $anyToPdf->setFallbackConverter(
            $this->config->getAppValue($this->appName, self::ADMIN_FALLBACK_CONVERTER, null));
          $anyToPdf->setUniversalConverter(
            $this->config->getAppValue($this->appName, self::ADMIN_UNIVERSAL_CONVERTER, null));

          $value = $anyToPdf->findConverters();
          $humanValue = $value;
          break;
        default:
          return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $oneSetting));
      }
      $results[$oneSetting] = $value;
      $results['human' . ucfirst($oneSetting)] = $humanValue;
    }

    if ($setting === null) {
      return new DataResponse($results);
    } else {
      return new DataResponse([
        'value' => $results[$setting],
        'humanValue' => $results['human' . ucfirst($setting)],
      ]);
    }
  }

  /**
   * Set a personal setting value.
   *
   * @param string $setting
   *
   * @param mixed $value
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function setPersonal(string $setting, mixed $value):Response
  {
    if (!isset(self::PERSONAL_SETTINGS[$setting])) {
      return self::grumble($this->l->t('Unknown personal setting: "%1$s"', $setting));
    }
    if (!(self::PERSONAL_SETTINGS[$setting]['rw'] ?? false)) {
      return self::grumble($this->l->t('The personal setting "%1$s" is read-only', $setting));
    }
    $oldValue = $this->config->getUserValue(
      $this->userId,
      $this->appName,
      $setting,
      self::PERSONAL_SETTINGS[$setting]['default'] ?? null);
    switch ($setting) {
      case self::EXTRACT_ARCHIVE_FILES:
      case self::PERSONAL_PAGE_LABELS:
        $newValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($newValue === null) {
          return self::grumble(
            $this->l->t('Value "%1$s" for setting "%2$s" is not convertible to boolean.', [
              $value, $setting,
            ]));
        }
        if ($newValue === (self::PERSONAL_SETTINGS[$setting]['default'] ?? false)) {
          $newValue = null;
        } else {
          $newValue = (int)$newValue;
        }
        break;
      case self::PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION:
        $newValue = $value;
        if ($newValue === null || $newValue === '') {
          // allow empty value in order to request a fixed font-size.
          $newValue = '';
        }
        break;
      case self::PERSONAL_PDF_FILE_NAME_TEMPLATE:
        $newValue = $value;
        if (empty($value)) {
          $value = MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l);
        }
        break;
      case self::PERSONAL_PAGE_LABEL_TEMPLATE:
        $newValue = $value;
        if (empty($value)) {
          $value = $this->pdfCombiner->getOverlayTemplate();
        }
        break;
      case self::PERSONAL_PDF_CLOUD_FOLDER_PATH:
      case self::PERSONAL_GENERATED_PAGES_FONT_SIZE:
      case self::PERSONAL_PAGE_LABELS_FONT_SIZE:
      case self::PERSONAL_GENERATED_PAGES_FONT:
      case self::PERSONAL_PAGE_LABELS_FONT:
      case self::PERSONAL_GROUPING:
        $newValue = $value;
        if (empty($newValue)) {
          $newValue = null;
        }
        break;
      case self::ARCHIVE_SIZE_LIMIT:
        try {
          $newValue = $this->parseMemorySize($value);
        } catch (InvalidArgumentException $t) {
          return self::grumble($t->getMessage());
        }
        break;
      default:
        return self::grumble($this->l->t('Unknown personal setting: "%s".', [ $setting ]));
    }
    if ($newValue === null) {
      $this->config->deleteUserValue($this->userId, $this->appName, $setting);
      $newValue = self::PERSONAL_SETTINGS[$setting]['default'] ?? null;
    } else {
      $this->config->setUserValue($this->userId, $this->appName, $setting, $newValue);
    }

    switch ($setting) {
      case self::ARCHIVE_SIZE_LIMIT:
        $humanValue = $newValue === null ? '' : $this->formatStorageValue($newValue);
        break;
      default:
        $humanValue = $value;
        break;
    }

    return new DataResponse([
      'newValue' => $newValue,
      'oldValue' => $oldValue,
      'humanValue' => $humanValue,
    ]);
  }

  /**
   * Get one or all personal settings.
   *
   * @param null|string $setting If null get all settings, otherwise just the
   * requested one.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function getPersonal(?string $setting = null):Response
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
    foreach (array_keys($allSettings) as $oneSetting) {
      if (str_ends_with($oneSetting, self::ADMIN_SETTING)) {
        $oneAdminSetting = substr($oneSetting, 0, -strlen(self::ADMIN_SETTING));
        $value = $this->config->getAppValue(
          $this->appName,
          $oneAdminSetting,
          self::ADMIN_SETTINGS[$oneAdminSetting]['default'] ?? null,
        );
      } else {
        $value = $this->config->getUserValue(
          $this->userId,
          $this->appName,
          $oneSetting,
          self::PERSONAL_SETTINGS[$oneSetting]['default'] ?? null,
        );
      }
      $humanValue = $value;
      switch ($oneSetting) {
        case self::ARCHIVE_SIZE_LIMIT:
        case self::ARCHIVE_SIZE_LIMIT_ADMIN:
          if ($value !== null) {
            $value = (int)$value;
            $humanValue = $this->formatStorageValue($value);
          } else {
            $humanValue = '';
          }
          break;
        case self::EXTRACT_ARCHIVE_FILES_ADMIN:
        case self::EXTRACT_ARCHIVE_FILES:
        case self::PERSONAL_PAGE_LABELS:
          if ($value === '' || $value === null) {
            $value = self::PERSONAL_SETTINGS[$oneSetting]['default'] ?? false;
            if ($value === self::ADMIN_SETTING) {
              $value = $this->config->getAppValue(
                $this->appName, $oneSetting, self::ADMIN_SETTINGS[$oneSetting]['default'] ?? false);
            }
          }
          $value= (int)$value;
          break;
        case self::PERSONAL_GENERATED_PAGES_FONT_SIZE:
          if (empty($value)) {
            /** @var MultiPdfDownloadController $downloadController */
            $downloadController = $this->appContainer->get(MultiPdfDownloadController::class);
            $value = $downloadController->getErrorPagesFontSize();
          }
          break;
        case self::PERSONAL_GENERATED_PAGES_FONT:
          if (empty($value)) {
            /** @var MultiPdfDownloadController $downloadController */
            $downloadController = $this->appContainer->get(MultiPdfDownloadController::class);
            $value = $downloadController->getErrorPagesFont();
          }
          break;
        case self::PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION:
          if ($value === null) {
            $value = $this->pdfCombiner->getOverlayPageWidthFraction();
          }
          break;
        case self::PERSONAL_PAGE_LABELS_FONT_SIZE:
          if (empty($value)) {
            $value = $this->pdfCombiner->getOverlayFontSize();
          }
          break;
        case self::PERSONAL_PAGE_LABELS_FONT:
          if (empty($value)) {
            $value = $this->pdfCombiner->getOverlayFont();
          }
          break;
        case self::PERSONAL_PAGE_LABEL_TEMPLATE:
          if (empty($value)) {
            $value = $this->pdfCombiner->getOverlayTemplate();
          }
          break;
        case self::PERSONAL_PDF_FILE_NAME_TEMPLATE:
          if (empty($value)) {
            $value = MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l);
          }
          break;
        case self::PERSONAL_PDF_CLOUD_FOLDER_PATH:
          break;
        case self::PERSONAL_GROUPING:
          break;
        default:
          return self::grumble($this->l->t('Unknown personal setting: "%1$s"', $oneSetting));
      }
      $results[$oneSetting] = $value;
      $results['human' . ucfirst($oneSetting)] = $humanValue;
    }

    if ($setting === null) {
      return new DataResponse($results);
    } else {
      return new DataResponse([
        'value' => $results[$setting],
        'humanValue' => $results['human' . ucfirst($setting)],
      ]);
    }
  }

  /**
   * @param string $stringValue
   *
   * @return null|string
   *
   * @throws InvalidArgumentException
   */
  private function parseMemorySize(string $stringValue):?string
  {
    if ($stringValue === '') {
      $stringValue = null;
    }
    if ($stringValue === null) {
      return $stringValue;
    }
    $newValue = $this->storageValue($stringValue);
    if (!is_int($newValue) && !is_float($newValue)) {
      throw new InvalidArgumentException($this->l->t('Unable to parse memory size limit "%s"', $stringValue));
    }
    if (empty($newValue)) {
      $newValue = null;
    }
    return $newValue;
  }
}
