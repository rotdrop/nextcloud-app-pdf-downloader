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

namespace OCA\PdfDownloader\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;

use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\AssetService;
use OCA\PdfDownloader\Controller\MultiPdfDownloadController;

/**
 * Render the personal per-user settings for this app.
 */
class Personal implements ISettings
{
  const TEMPLATE = "personal-settings";

  /** @var string */
  private $appName;

  /** @var AssetService */
  private $assetService;

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var IInitialState */
  private $initialState;

  /** @var IL10N */
  private $l;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IL10N $l10n,
    AssetService $assetService,
    PdfCombiner $pdfCombiner,
    IInitialState $initialState,
  ) {
    $this->appName = $appName;
    $this->l = $l10n;
    $this->assetService = $assetService;
    $this->pdfCombiner = $pdfCombiner;
    $this->initialState = $initialState;
  }

  /**
   * Return the HTML-template in order to render the personal settings.
   *
   * @return TemplateResponse
   */
  public function getForm():TemplateResponse
  {
    $this->initialState->provideInitialState('config', [
      'defaultPageLabelTemplate' => $this->pdfCombiner->getOverlayTemplate(),
      'defaultPdfFileNameTemplate' => MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l),
    ]);

    return new TemplateResponse(
      $this->appName,
      self::TEMPLATE, [
        'appName' => $this->appName,
        'assets' => [
          AssetService::JS => $this->assetService->getJSAsset(self::TEMPLATE),
          AssetService::CSS => $this->assetService->getCSSAsset(self::TEMPLATE),
        ],
      ]);
  }

  /**
   * @return string the section ID, e.g. 'sharing'
   *
   * @since 9.1
   */
  public function getSection()
  {
    return $this->appName;
  }

  /**
   * @return int whether the form should be rather on the top or bottom of
   * the admin section. The forms are arranged in ascending order of the
   * priority values. It is required to return a value between 0 and 100.
   *
   * E.g.: 70
   * @since 9.1
   */
  public function getPriority()
  {
    return 50;
  }
}

// Local Variables: ***
// c-basic-offset: 2 ***
// indent-tabs-mode: nil ***
// End: ***
