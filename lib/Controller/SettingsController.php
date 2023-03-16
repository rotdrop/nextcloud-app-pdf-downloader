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
use Carbon\CarbonInterval;
use Carbon;

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
use OCA\PdfDownloader\Service\FileSystemWalker;
use OCA\PdfDownloader\Service\DependenciesService;

use OCA\PdfDownloader\Constants;

/**
 * Settings-controller for both, personal and admin, settings.
 */
class SettingsController extends Controller
{
  use \OCA\PdfDownloader\Toolkit\Traits\UtilTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\ResponseTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\IncludeExcludeTrait;

  public const ADMIN_DISABLE_BUILTIN_CONVERTERS = 'disableBuiltinConverters';
  public const ADMIN_FALLBACK_CONVERTER = 'fallbackConverter';
  public const ADMIN_UNIVERSAL_CONVERTER = 'universalConverter';
  public const ADMIN_CONVERTERS = 'converters';
  public const ADMIN_DEPENDENCIES = 'dependencies';

  public const EXTRACT_ARCHIVE_FILES = 'extractArchiveFiles';
  public const ARCHIVE_SIZE_LIMIT = 'archiveSizeLimit';

  public const PERSONAL_PAGE_LABELS = 'pageLabels';
  public const PERSONAL_PAGE_LABELS_DEFAULT = true;
  public const PERSONAL_PAGE_LABELS_FONT = 'pageLabelsFont';
  public const PERSONAL_PAGE_LABELS_FONT_DEFAULT = PdfCombiner::OVERLAY_FONT;
  public const PERSONAL_PAGE_LABELS_FONT_SIZE = 'pageLabelsFontSize';
  public const PERSONAL_PAGE_LABELS_FONT_SIZE_DEFAULT = PdfCombiner::OVERLAY_FONT_SIZE;
  public const PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION = 'pageLabelPageWidthFraction';
  public const PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION_DEFAULT = PdfCombiner::OVERLAY_PAGE_WIDTH_FRACTION;
  public const PERSONAL_PAGE_LABEL_TEMPLATE = 'pageLabelTemplate';
  public const PERSONAL_PAGE_LABEL_TEXT_COLOR = 'pageLabelTextColor';
  public const PERSONAL_PAGE_LABEL_BACKGROUND_COLOR = 'pageLabelBackgroundColor';
  public const PERSONAL_PAGE_LABEL_TEXT_COLOR_PALETTE = 'pageLabelTextColorPalette';
  public const PERSONAL_PAGE_LABEL_BACKGROUND_COLOR_PALETTE = 'pageLabelBackgroundColorPalette';

  public const PERSONAL_GENERATE_ERROR_PAGES = 'generateErrorPages';
  public const PERSONAL_GENERATE_ERROR_PAGES_DEFAULT = true;
  public const PERSONAL_GENERATED_PAGES_FONT = 'generatedPagesFont';
  public const PERSONAL_GENERATED_PAGES_FONT_DEFAULT = FileSystemWalker::ERROR_PAGES_FONT;
  public const PERSONAL_GENERATED_PAGES_FONT_SIZE = 'generatedPagesFontSize';
  public const PERSONAL_GENERATED_PAGES_FONT_SIZE_DEFAULT = FileSystemWalker::ERROR_PAGES_FONT_SIZE;

  public const DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT = Constants::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT;

  public const ADMIN_SETTING = 'Admin';
  public const EXTRACT_ARCHIVE_FILES_ADMIN = self::EXTRACT_ARCHIVE_FILES . self::ADMIN_SETTING;
  public const ARCHIVE_SIZE_LIMIT_ADMIN = self::ARCHIVE_SIZE_LIMIT . self::ADMIN_SETTING;

  public const PERSONAL_EXCLUDE_PATTERN = 'excludePattern';
  public const PERSONAL_EXCLUDE_PATTERN_DEFAULT = '';
  public const PERSONAL_INCLUDE_PATTERN = 'includePattern';
  public const PERSONAL_INCLUDE_PATTERN_DEFAULT = '';
  public const INCLUDE_HAS_PRECEDENCE = 'includeHasPrecedence';
  public const EXCLUDE_HAS_PRECEDENCE = 'excludeHasPrecedence';
  public const PERSONAL_PATTERN_PRECEDENCE = 'patternPrecedence';
  public const PERSONAL_PATTERN_PRECEDENCE_DEFAULT = self::INCLUDE_HAS_PRECEDENCE;
  public const PERSONAL_PATTERN_TEST_STRING = 'patternTestString';
  public const PERSONAL_PATTERN_TEST_STRING_DEFAULT = '';
  public const PERSONAL_PATTERN_TEST_INCLUDED = 'included';
  public const PERSONAL_PATTERN_TEST_EXCLUDED = 'excluded';
  public const PERSONAL_PATTERN_TEST_RESULT = 'patternTestResult';

  public const PERSONAL_GROUPING = 'grouping';
  public const PERSONAL_GROUP_FOLDERS_FIRST = PdfCombiner::GROUP_FOLDERS_FIRST;
  public const PERSONAL_GROUP_FILES_FIRST = PdfCombiner::GROUP_FILES_FIRST;
  public const PERSONAL_UNGROUPED = PdfCombiner::UNGROUPED;

  public const PERSONAL_PDF_CLOUD_FOLDER_PATH = 'pdfCloudFolderPath';
  public const PERSONAL_PDF_FILE_NAME_TEMPLATE = 'pdfFileNameTemplate';

  public const PERSONAL_USE_BACKGROUND_JOBS_DEFAULT = 'useBackgroundJobsDefault';
  public const PERSONAL_USE_BACKGROUND_JOBS_DEFAULT_DEFAULT = false;

  public const PERSONAL_AUTHENTICATED_BACKGROUND_JOBS = 'authenticatedBackgroundJobs';
  public const PERSONAL_AUTHENTICATED_BACKGROUND_JOBS_DEFAULT = false;

  public const PERSONAL_DOWNLOADS_PURGE_TIMEOUT = 'downloadsPurgeTimeout';
  public const PERSONAL_DOWNLOADS_PURGE_TIMEOUT_DEFAULT = 24 * 3600 * 7; // 1 week

  public const PERSONAL_INDIVIDUAL_FILE_CONVERSION = 'individualFileConversion';
  public const PERSONAL_INDIVIDUAL_FILE_CONVERSION_DEFAULT = true;

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
    self::ADMIN_DEPENDENCIES => [ 'rw' => false ],
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
    self::PERSONAL_PAGE_LABELS => [
      'rw' => true,
      'default' => self::PERSONAL_PAGE_LABELS_DEFAULT,
    ],
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
    self::PERSONAL_GENERATE_ERROR_PAGES => [
      'rw' => true,
      'default' => self::PERSONAL_GENERATE_ERROR_PAGES_DEFAULT,
    ],
    self::PERSONAL_GENERATED_PAGES_FONT => [
      'rw' => true,
      'default' => self::PERSONAL_GENERATED_PAGES_FONT_DEFAULT,
    ],
    self::PERSONAL_GENERATED_PAGES_FONT_SIZE => [
      'rw' => true,
      'default' => self::PERSONAL_GENERATED_PAGES_FONT_SIZE_DEFAULT,
    ],
    self::PERSONAL_EXCLUDE_PATTERN => [
      'rw' => true,
      'default' => self::PERSONAL_EXCLUDE_PATTERN_DEFAULT,
    ],
    self::PERSONAL_INCLUDE_PATTERN => [
      'rw' => true,
      'default' => self::PERSONAL_INCLUDE_PATTERN_DEFAULT,
    ],
    self::PERSONAL_PATTERN_PRECEDENCE => [
      'rw' => true,
      self::PERSONAL_PATTERN_PRECEDENCE_DEFAULT,
    ],
    self::PERSONAL_PATTERN_TEST_STRING => [
      'rw' => true,
      self::PERSONAL_PATTERN_TEST_STRING_DEFAULT,
    ],
    self::PERSONAL_PATTERN_TEST_RESULT => [
      'rw' => false,
      'default' => null,
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
    self::PERSONAL_PAGE_LABEL_TEXT_COLOR => [
      'rw' => true,
      'default' => null,
    ],
    self::PERSONAL_PAGE_LABEL_TEXT_COLOR_PALETTE => [
      'rw' => true,
      'default' => null
    ],
    self::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR => [
      'rw' => true,
      'default' => null,
    ],
    self::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR_PALETTE => [
      'rw' => true,
      'default' => null
    ],
    self::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT => [
      'rw' => true,
      'default' => self::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT_DEFAULT,
    ],
    self::PERSONAL_AUTHENTICATED_BACKGROUND_JOBS => [
      'rw' => true,
      'default' => self::PERSONAL_AUTHENTICATED_BACKGROUND_JOBS_DEFAULT,
    ],
    self::PERSONAL_DOWNLOADS_PURGE_TIMEOUT => [
      'rw' => true,
      'default' => self::PERSONAL_DOWNLOADS_PURGE_TIMEOUT_DEFAULT,
    ],
    self::PERSONAL_INDIVIDUAL_FILE_CONVERSION => [
      'rw' => true,
      'default' => self::PERSONAL_INDIVIDUAL_FILE_CONVERSION_DEFAULT,
    ],
  ];

  /** @var IAppContainer */
  private $appContainer;

  /** @var IConfig */
  private $config;

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
   * @AuthorizedAdminSetting(settings=OCA\PdfDownloader\Settings\Admin)
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
   * @AuthorizedAdminSetting(settings=OCA\PdfDownloader\Settings\Admin)
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
        case self::ADMIN_DEPENDENCIES:
          /** @var DependenciesService $dependencies */
          $dependencies = $this->appContainer->get(DependenciesService::class);
          $value = $dependencies->checkForExternalPrograms();
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
      case self::PERSONAL_AUTHENTICATED_BACKGROUND_JOBS:
      case self::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT:
      case self::EXTRACT_ARCHIVE_FILES:
      case self::PERSONAL_PAGE_LABELS:
      case self::PERSONAL_GENERATE_ERROR_PAGES:
      case self::PERSONAL_INDIVIDUAL_FILE_CONVERSION:
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
        /** @var FileSystemWalker $fileSystemWalker */
        $fileSystemWalker = $this->appContainer->get(FileSystemWalker::class);
        if (empty($value)) {
          $value = $fileSystemWalker->getDefaultPdfFileNameTemplate();
        }
        $l10nKeys = $fileSystemWalker->getTemplateKeyTranslations();
        if ($oldValue) {
          $oldValue = $this->untranslateBracedTemplate($oldValue, $l10nKeys);
        }
        $newValue = $this->untranslateBracedTemplate($value, $l10nKeys);
        break;
      case self::PERSONAL_PAGE_LABEL_TEMPLATE:
        if (empty($value)) {
          $value = $this->pdfCombiner->getOverlayTemplate();
        }
        $l10nKeys = $this->pdfCombiner->getPageLabelTemplateKeys();
        if ($oldValue) {
          $oldValue = $this->untranslateBracedTemplate($oldValue, $l10nKeys);
        }
        $newValue = $this->untranslateBracedTemplate($value, $l10nKeys);
        break;
      case self::PERSONAL_PAGE_LABEL_TEXT_COLOR:
        if (empty($value)) {
          $value = $this->rgbaArrayToString(PdfCombiner::OVERLAY_TEXT_COLOR);
        }
        $newValue = $value;
        break;
      case self::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR:
        if (empty($value)) {
          $value = $this->rgbaArrayToString(PdfCombiner::OVERLAY_BACKGROUND_COLOR);
        }
        $newValue = $value;
        break;
      case self::PERSONAL_PAGE_LABEL_TEXT_COLOR_PALETTE:
      case self::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR_PALETTE:
        $newValue = $value;
        if (is_array($newValue)) {
          $settingsValue = strtolower(json_encode($newValue));
        } else {
          $newValue = null;
        }
        if (!empty($oldValue) && is_string($oldValue)) {
          try {
            $oldValue = json_decode(strtolower($oldValue), true);
          } catch (Throwable $t) {
            $this->logException($t, 'Unable to decode old palette value "' . $oldValue . '".');
          }
        }
        break;
      case self::PERSONAL_INCLUDE_PATTERN:
      case self::PERSONAL_EXCLUDE_PATTERN:
        $newValue = $value;
        if (empty($newValue)) {
          $newValue = null;
        } else {
          if (preg_match($newValue, null) === false) {
            $errorMessage = $this->l->t('The regular expression "%1$s" seems to be invalid, error code is "%d".', [
              $newValue, $error
            ]);
            $error = preg_last_error();
            if ($error === 0) {
              $delimiter = $newValue[0];
              if (preg_match('/[[:alnum:][:space:]\\\\]/', $delimiter)
                  || strrpos($newValue, $delimiter) === 0) {
                $errorMessage .= ' ' . $this->l->t('Did you forget the delimiters?');
              }
            }
            return self::grumble($errorMessage);
          }
        }
        break;
      case self::PERSONAL_PATTERN_PRECEDENCE:
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
      case self::PERSONAL_PATTERN_TEST_STRING:
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
      case self::PERSONAL_DOWNLOADS_PURGE_TIMEOUT:
        if (empty($value)) {
          $newValue == null;
          break;
        }
        $newValue = filter_var($value, FILTER_VALIDATE_INT, [ 'min_range' => 0 ]);
        if ($newValue === false) {
          CarbonInterval::setLocale($this->l->getLanguageCode());
          try {
            $unlocalized = (new CarbonInterval)->translateTimeStringTo($value, 'en_US');
            $newValue = CarbonInterval::fromString($unlocalized);
            $newValue = $newValue->total('seconds');
          } catch (\Carbon\Exceptions\InvalidIntervalException $e) {
            return self::grumble($e->getMessage());
          }
        }
        if (empty($newValue)) {
          $newValue = null;
        }
        break;
      default:
        return self::grumble($this->l->t('Unknown personal setting: "%s".', [ $setting ]));
    }
    if ($newValue === null) {
      $this->config->deleteUserValue($this->userId, $this->appName, $setting);
      $newValue = self::PERSONAL_SETTINGS[$setting]['default'] ?? null;
    } else {
      $this->config->setUserValue($this->userId, $this->appName, $setting, $settingsValue ?? $newValue);
    }

    switch ($setting) {
      case self::ARCHIVE_SIZE_LIMIT:
        $humanValue = $newValue === null ? '' : $this->formatStorageValue($newValue);
        break;
      case self::PERSONAL_DOWNLOADS_PURGE_TIMEOUT:
        if ($newValue === null) {
          $humanValue = '';
          break;
        }
        $interval = CarbonInterval::seconds($newValue);
        CarbonInterval::setLocale($this->l->getLanguageCode());
        $humanValue = $interval->cascade()->forHumans();
        break;
      default:
        $humanValue = $value;
        break;
    }

    $data = [
      'newValue' => $newValue,
      'oldValue' => $oldValue,
      'humanValue' => $humanValue,
    ];

    switch ($setting) {
      case self::PERSONAL_INCLUDE_PATTERN:
      case self::PERSONAL_EXCLUDE_PATTERN:
      case self::PERSONAL_PATTERN_TEST_STRING:
      case self::PERSONAL_PATTERN_PRECEDENCE:
        $extraData = [
          'patternTestResult' => $this->getPatternTestResult(),
        ];
        break;
      default:
        $extraData = [];
        break;
    }

    return new DataResponse(array_merge($data, $extraData));
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
        case self::PERSONAL_DOWNLOADS_PURGE_TIMEOUT:
          if ($value !== null) {
            $value = (int)$value;
            $interval = CarbonInterval::seconds($value);
            CarbonInterval::setLocale($this->l->getLanguageCode());
            $humanValue = $interval->cascade()->forHumans();
          } else {
            $humanValue = '';
          }
          break;
        case self::PERSONAL_AUTHENTICATED_BACKGROUND_JOBS:
        case self::PERSONAL_USE_BACKGROUND_JOBS_DEFAULT:
        case self::EXTRACT_ARCHIVE_FILES_ADMIN:
        case self::EXTRACT_ARCHIVE_FILES:
        case self::PERSONAL_PAGE_LABELS:
        case self::PERSONAL_GENERATE_ERROR_PAGES:
        case self::PERSONAL_INDIVIDUAL_FILE_CONVERSION:
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
          $l10nKeys = $this->pdfCombiner->getPageLabelTemplateKeys();
          $value = $this->translateBracedTemplate($value, $l10nKeys);
          break;
        case self::PERSONAL_PDF_FILE_NAME_TEMPLATE:
          /** @var FileSystemWalker $fileSystemWalker */
          $fileSystemWalker = $this->appContainer->get(FileSystemWalker::class);
          if (empty($value)) {
            $value = $fileSystemWalker->getDefaultPdfFileNameTemplate();
          }
          $l10nKeys = $fileSystemWalker->getTemplateKeyTranslations();
          $value = $this->translateBracedTemplate($value, $l10nKeys);
          break;
        case self::PERSONAL_PDF_CLOUD_FOLDER_PATH:
        case self::PERSONAL_GROUPING:
        case self::PERSONAL_INCLUDE_PATTERN:
        case self::PERSONAL_EXCLUDE_PATTERN:
        case self::PERSONAL_PATTERN_PRECEDENCE:
        case self::PERSONAL_PATTERN_TEST_STRING:
          break;
        case self::PERSONAL_PATTERN_TEST_RESULT:
          $value = $this->getPatternTestResult();
          switch ($value) {
            case self::PERSONAL_PATTERN_TEST_INCLUDED:
              $humanValue = $this->l->t('excluded');
              break;
            case self::PERSONAL_PATTERN_TEST_EXCLUDED:
              $humanValue = $this->l->t('excluded');
              break;
            default:
              $humanValue = '';
              break;
          }
          break;
        case self::PERSONAL_PAGE_LABEL_TEXT_COLOR:
          if (empty($value)) {
            $value = $this->rgbaArrayToString(PdfCombiner::OVERLAY_TEXT_COLOR);
          }
          break;
        case self::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR:
          if (empty($value)) {
            $value = $this->rgbaArrayToString(PdfCombiner::OVERLAY_BACKGROUND_COLOR);
          }
          break;
        case self::PERSONAL_PAGE_LABEL_TEXT_COLOR_PALETTE:
        case self::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR_PALETTE:
          if (!empty($value)) {
            $value = json_decode(strtolower($value), true);
          }
          $humanValue = $value;
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

  /** @return null|string */
  private function getPatternTestResult():?string
  {
    $testString = $this->config->getUserValue(
      $this->userId,
      $this->appName,
      self::PERSONAL_PATTERN_TEST_STRING,
      self::PERSONAL_PATTERN_TEST_STRING_DEFAULT,
    );
    $patternTestResult = null;
    if (!empty($testString)) {
      /** @var FileSystemWalker $fileSystemWalker */
      $fileSystemWalker = $this->appContainer->get(FileSystemWalker::class);
      $patternTestResult = $fileSystemWalker->isFileIncluded($testString)
        ? self::PERSONAL_PATTERN_TEST_INCLUDED
        : self::PERSONAL_PATTERN_TEST_EXCLUDED;
    }
    return $patternTestResult;
  }
}
