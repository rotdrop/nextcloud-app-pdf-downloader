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

  const ADMIN_DISABLE_BUILTIN_CONVERTERS = 'disableBuiltinConverters';
  const ADMIN_FALLBACK_CONVERTER = 'fallbackConverter';
  const ADMIN_UNIVERSAL_CONVERTER = 'universalConverter';
  const ADMIN_CONVERTERS = 'converters';

  const PERSONAL_PAGE_LABELS = 'pageLabels';
  const PERSONAL_PAGE_LABELS_FONT = 'pageLabelsFont';
  const PERSONAL_GENERATED_PAGES_FONT = 'generatedPagesFont';

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
    $newValue = $value;
    $oldValue = $this->config->getAppValue($this->appName, $setting);
    switch ($setting) {
      case self::ADMIN_DISABLE_BUILTIN_CONVERTERS:
      case self::ADMIN_FALLBACK_CONVERTER:
      case self::ADMIN_UNIVERSAL_CONVERTER:
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
      case self::ADMIN_DISABLE_BUILTIN_CONVERTERS:
      case self::ADMIN_FALLBACK_CONVERTER:
      case self::ADMIN_UNIVERSAL_CONVERTER:
        $value = $this->config->getAppValue($this->appName, $setting);
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
        return self::grumble($this->l->t('Unknown admin setting: "%1$s"', $setting));
    }
    return new DataResponse([
      'value' => $value,
    ]);
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
    switch ($setting) {
      case self::PERSONAL_PAGE_LABELS:
        $realValue = filter_var($value, FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
        if ($realValue === null) {
          return self::grumble($this->l->t('Value "%1$s" for setting "%2$s" is not convertible to boolean.', [ $value, $setting ]));
        }
        if ($realValue === true) {
          $realValue = null;
        } else {
          $realValue = 0;
        }
        break;
      case self::PERSONAL_GENERATED_PAGES_FONT:
      case self::PERSONAL_PAGE_LABELS_FONT:
        $realValue = $value;
        if (empty($realValue)) {
          $realValue = null;
        }
        break;
      default:
        return self::grumble($this->l->t('Unknown personal setting: "%s".', [ $setting ]));
    }
    if ($realValue === null) {
      $this->config->deleteUserValue($this->userId, $this->appName, $setting);
    } else {
      $this->config->setUserValue($this->userId, $this->appName, $setting, $realValue);
    }
    return new DataResponse([
      'oldValue' => $oldValue,
    ]);
  }

  /**
   * @NoAdminRequired
   */
  public function getPersonal(string $setting)
  {
    $value = $this->config->getUserValue($this->userId, $this->appName, $setting);
    switch ($setting) {
      case self::PERSONAL_PAGE_LABELS:
        if ($value === '' || $value === null) {
          $value = true;
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
    }
    return new DataResponse([
      'value' => $value,
    ]);
  }
}
