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
use OCA\PdfDownloader\Service\FileSystemWalker;
use OCA\PdfDownloader\Constants;

/**
 * Render the personal per-user settings for this app.
 */
class Personal implements ISettings
{
  use \OCA\PdfDownloader\Toolkit\Traits\AssetTrait;

  const TEMPLATE = "personal-settings";

  /** @var string */
  private $appName;

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var FileSystemWalker */
  private $fileSystemWalker;

  /** @var IInitialState */
  private $initialState;

  /** @var IL10N */
  protected $l;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IL10N $l10n,
    PdfCombiner $pdfCombiner,
    FileSystemWalker $fileSystemWalker,
    IInitialState $initialState,
  ) {
    $this->appName = $appName;
    $this->l = $l10n;
    $this->pdfCombiner = $pdfCombiner;
    $this->fileSystemWalker = $fileSystemWalker;
    $this->initialState = $initialState;
    $this->initializeAssets(__DIR__);
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
      'defaultPdfFileNameTemplate' => $this->fileSystemWalker->getDefaultPdfFileNameTemplate(),
    ]);

    return new TemplateResponse(
      $this->appName,
      self::TEMPLATE, [
        'appName' => $this->appName,
        'assets' => [
          Constants::JS => $this->getJSAsset(self::TEMPLATE),
          Constants::CSS => $this->getCSSAsset(self::TEMPLATE),
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
