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
 */

namespace OCA\PdfDownloader\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;
use Psr\Log\LoggerInterface as ILogger;

use OCP\IUser;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use OCP\Files\Node as FileSystemNode;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeDetector;

use OCA\PdfDownloader\Service\AnyToPdf;
use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\PdfGenerator;

/**
 * Walk throught a directory tree, convert all files to PDF and combine the
 * resulting PDFs into a single PDF. Present this as download response.
 */
class MultiPdfDownloadController extends Controller
{
  use \OCA\PdfDownloader\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Traits\ResponseTrait;

  const ERROR_PAGES_FONT = 'dejavusans';
  const ERROR_PAGES_FONTSIZE = '12';
  const ERROR_PAGES_PAPER = 'A4';

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var AnyToPdf */
  private $anyToPdf;

  /** @var IRootFolder */
  private $rootFolder;

  /** @var IMimeTypeDetector */
  private $mimeTypeDetector;

  /** @var IConfig */
  private $cloudConfig;

  /** @var Folder */
  private $userFolder;

  /** @var string */
  private $userId;

  /** @var string */
  private $errorPagesFont = self::ERROR_PAGES_FONT;

  public function __construct(
    string $appName
    , IRequest $request
    , IL10N $l
    , ILogger $logger
    , IUserSession $userSession
    , IConfig $cloudConfig
    , IRootFolder $rootFolder
    , IMimeTypeDetector $mimeTypeDetector
    , PdfCombiner $pdfCombiner
    , AnyToPdf $anyToPdf
  ) {
    parent::__construct($appName, $request);
    $this->l = $l;
    $this->logger = $logger;
    $this->rootFolder = $rootFolder;
    $this->cloudConfig = $cloudConfig;
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->pdfCombiner = $pdfCombiner;
    $this->anyToPdf = $anyToPdf;
    /** @var IUser $user */
    $user = $userSession->getUser();
    if (!empty($user)) {
      $this->userFolder = $this->rootFolder->getUserFolder($user->getUID());
      $this->userId = $user->getUID();
      $pdfCombiner->setOverlayFont(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS_FONT)
      );
      $this->setErrorPagesFont(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_GENERATED_PAGES_FONT)
      );
    }

    $this->anyToPdf->disableBuiltinConverters(
      $this->config->getAppValue($this->appName, SettingsController::ADMIN_DISABLE_BUILTIN_CONVERTERS, false));
    $this->anyToPdf->setFallbackConverter(
      $this->config->getAppValue($this->appName, SettingsController::ADMIN_FALLBACK_CONVERTER, null));
    $this->anyToPdf->setUniversalConverter(
      $this->config->getAppValue($this->appName, SettingsController::ADMIN_UNIVERSAL_CONVERTER, null));
  }

  public function getErrorPagesFont():?string
  {
    return $this->errorPagesFont ?? self::ERROR_PAGES_FONT;
  }

  public function setErrorPagesFont(?string $errorPagesFont)
  {
    $this->errorPagesFont = empty($errorPagesFont) ? self::ERROR_PAGES_FONT : $errorPagesFont;
  }

  private function generateErrorPage(string $fileData, string $path, \Throwable $throwable)
  {
    $pdf = new PdfGenerator(orientation: 'P', unit: 'mm', format: self::ERROR_PAGES_PAPER);
    $pdf->setFont($this->getErrorPagesFont());
    $pdf->setFontSize(self::ERROR_PAGES_FONTSIZE);

    $mimeType = $this->mimeTypeDetector->detectString($fileData);

    $message = $throwable->getMessage();
    $trace = $throwable->getTraceAsString();
    $html =<<<__EOF__
<h1>Error converting $path to PDF</h1>
<h2>Error Message</h2>
<span>$message</span>
<h2>Trace</h2>
<pre>$trace</pre>
__EOF__;

    $pdf->addPage();
    $pdf->writeHTML($html);

    return $pdf->Output($path, 'S');
  }

  private function addFilesRecursively(Folder $folder, string $parentName = '')
  {
    $parentName .= (!empty($parentName) ? '/' : '') . $folder->getName();
    /** @var FileSystemNode $node */
    foreach ($folder->getDirectoryListing() as $node) {
      if ($node->getType() != FileInfo::TYPE_FILE) {
        $this->addFilesRecursively($node, $parentName);
      } else {
        /** @var File $node */
        $path = $parentName . '/' . $node->getName();
        $fileData = $node->getContent();
        try {
          $pdfData = $this->anyToPdf->convertData($fileData, $node->getMimeType());
        } catch (\Throwable $t) {
          // @todo add an error page to the output
          $this->logException($t);
          $pdfData = $this->generateErrorPage($fileData, $path, $t);
        }
        $this->pdfCombiner->addDocument($pdfData, $path);
      }
    }
  }

  /**
   * Download the contents (plain-files only, non-recursive) of the given
   * folder as multi-page PDF after converting everything to PDF.
   *
   * @NoAdminRequired
   * @return Response
   */
  public function get(string $folder):Response
  {
    $pageLabels = $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS, true);
    $this->pdfCombiner->addPageLabels($pageLabels);
    $folderPath = urldecode($folder);

    $folder = $this->userFolder->get($folderPath);
    $this->addFilesRecursively($folder);

    $fileName = basename($folderPath) . '.pdf';

    return self::dataDownloadResponse($this->pdfCombiner->combine(), $fileName, 'application/pdf');
  }

  /**
   * Get the list of available fonts.
   *
   * @NoAdminRequired
   * @return Response
   */
  public function getFonts():Response
  {
    $pdf = new PdfGenerator;
    $fonts = $pdf->getFonts();
    return self::dataResponse($fonts);
  }

}

// Local Variables: ***
// c-basic-offset: 2 ***
// indent-tabs-mode: nil ***
// End: ***
