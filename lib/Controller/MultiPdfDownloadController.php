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

namespace OCA\PdfDownloader\Controller;

use Throwable;
use DateTimeImmutable;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\IAppContainer;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IDateTimeZone;
use Psr\Log\LoggerInterface as ILogger;
use Psr\Log\LogLevel;

use OCP\IUser;
use OCP\IUserSession;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\IRootFolder;

use OCA\PdfDownloader\Exceptions;
use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\PdfGenerator;
use OCA\PdfDownloader\Service\FontService;
use OCA\PdfDownloader\Service\FileSystemWalker;
use OCA\PdfDownloader\Constants;

/**
 * Walk throught a directory tree, convert all files to PDF and combine the
 * resulting PDFs into a single PDF. Present this as download response.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MultiPdfDownloadController extends Controller
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;
  use \OCA\RotDrop\Toolkit\Traits\ResponseTrait;
  use \OCA\RotDrop\Toolkit\Traits\UtilTrait;
  use \OCA\RotDrop\Toolkit\Traits\UserRootFolderTrait;

  /**
   * @var string
   *
   * Present the font-sample as image blob.
   */
  public const FONT_SAMPLE_OUTPUT_FORMAT_BLOB = 'blob';

  /**
   * @var string
   *
   * Present the font-sample as object with meta information:
   * ```
   * [
   *   'text' => SAMPLE_TEXT,
   *   'font' => FONT_FAMILY,
   *   'fontSize' => FONT_SIZE_IN_PT,
   *   'data' => BASE_64_RENDERED_FONT_DATA,
   * ]
   * ```
   */
  public const FONT_SAMPLE_OUTPUT_FORMAT_OBJECT = 'object';

  public const FONT_SAMPLE_OUTPUT_FORMATS = [
    self::FONT_SAMPLE_OUTPUT_FORMAT_BLOB,
    self::FONT_SAMPLE_OUTPUT_FORMAT_OBJECT,
  ];

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var IConfig */
  private $cloudConfig;

  /** @var FontService */
  private $fontService;

  /** @var FileSystemWalker */
  private $fileSystemWalker;

  /** @var string */
  protected $userId;

  /** @var IDateTimeZone */
  private $dateTimeZone;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IRequest $request,
    IL10N $l10n,
    ILogger $logger,
    IUserSession $userSession,
    IConfig $cloudConfig,
    Pdfcombiner $pdfCombiner,
    FontService $fontService,
    IDateTimeZone $dateTimeZone,
    FileSystemWalker $fileSystemWalker,
  ) {
    parent::__construct($appName, $request);
    $this->l = $l10n;
    $this->logger = $logger;
    $this->cloudConfig = $cloudConfig;
    $this->pdfCombiner = $pdfCombiner;
    $this->fontService = $fontService;
    $this->dateTimeZone = $dateTimeZone;
    $this->fileSystemWalker = $fileSystemWalker;

    /** @var IUser $user */
    $user = $userSession->getUser();
    if (!empty($user)) {
      $this->userId = $user->getUID();
      $this->pdfCombiner->setOverlayFont(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS_FONT, null)
      );
      $this->pdfCombiner->setOverlayFontSize(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_GENERATED_PAGES_FONT_SIZE, null)
      );
      $this->pdfCombiner->setOverlayPageWidthFraction(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION, null)
      );
      $this->pdfCombiner->setOverlayTextColor(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_TEXT_COLOR, null)
      );
      $this->pdfCombiner->setOverlayBackgroundColor(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR, null)
      );
    }
  }
  // phpcs:enable

  /**
   * Download the contents of the given folder as multi-page PDF after
   * converting everything to PDF.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @param null|string $downloadFileName The file-name presented to the
   * http-client. If null defaults to the pre-configured file-name template.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function get(string $sourcePath, ?string $downloadFileName = null):Response
  {
    if (empty($downloadFileName)) {
      $template = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE,
        MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l),
      );
      $this->logInfo('TEMPLATE ' . $template);
      $fileName = basename($this->fileSystemWalker->getPdfFileName($template, $sourcePath));
    } else {
      $downloadFileName = urldecode($downloadFileName);
      $fileName = basename($downloadFileName, '.pdf') . '.pdf';
    }

    $pdfData = $this->fileSystemWalker->generateDownloadData($sourcePath);

    return self::dataDownloadResponse($pdfData, $fileName, 'application/pdf');
  }

  /**
   * Download the contents of the given folder as multi-page PDF after
   * converting everything to PDF.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @param null|string $destinationPath The distination path in the cloud
   * where the resulting PDF data should be stored. If null then the file is
   * stored with the configured file-name template under the configured
   * directory.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function save(string $sourcePath, ?string $destinationPath = null):Response
  {
    $sourcePath = urldecode($sourcePath);

    if ($destinationPath === null) {
      $destinationPath = urldecode($destinationPath);
      $template = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE,
        MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l),
      );
      $destinationPath = $this->fileSystemWalker->getPdfFileName($template, $sourcePath);
    }

    $pdfFile = $this->fileSystemWalker->save($sourcePath, $destinationPath);

    $pdfFilePath = $pdfFile->getInternalPath();

    $this->logInfo('PDF READY');

    return self::dataResponse([
      'pdfFilePath' => $pdfFilePath,
      'messages' => $this->l->t('PDF document saved as "%s".', $pdfFilePath),
    ]);
  }

  /**
   * Schedule PDF generation as background job for either downloading (later,
   * after being notified) or for direct storing in the file-system.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @param bool|null|string $destinationPath The distination path in the
   * cloud where the resulting PDF data should be stored. If null then the
   * file is stored with the configured file-name template under the
   * configured directory. If \false then a temporary file is generated in the
   * parent of the user's home-folder
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function schedule(string $sourcePath, mixed $destinationPath)
  {
    if ($destinationPath === false) {

    } elseif ($destinationPath === null) {
      $destinationPath = urldecode($destinationPath);
      $template = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE,
        MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l),
      );
      $destinationPath = $this->fileSystemWalker->getPdfFileName($template, $sourcePath);
    }
  }

  /**
   * Get the list of available fonts.
   *
   * @return Response
   *
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function getFonts():Response
  {
    $fonts = $this->fontService->getFonts();
    return self::dataResponse($fonts);
  }

  /**
   * Generate a page label from a user supplied template and and example data-set.
   *
   * @param string $template
   *
   * @param string $path Example file path, preferrably with non-empty
   * directory part.
   *
   * @param int $dirPageNumber Example in-directory page-number.
   *
   * @param int $dirTotalPages Total number of in-directory pages example. $dirPageNumber should
   * be smaller or equal.
   *
   * @param int $filePageNumber Example in-file page-number.
   *
   * @param int $fileTotalPages Total number of in-file pages example. $filePageNumber should
   * be smaller or equal.
   *
   * @return DataResponse
   *
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function getPageLabelSample(
    string $template,
    string $path,
    int $dirPageNumber,
    int $dirTotalPages,
    int $filePageNumber,
    int $fileTotalPages,
  ):DataResponse {
    $template = urldecode($template);
    $path = urldecode($path);
    $this->pdfCombiner->setOverlayTemplate($template);
    $pageLabel = $this->pdfCombiner->makePageLabelFromTemplate(
      $path,
      $dirPageNumber,
      $dirTotalPages,
      $filePageNumber,
      $fileTotalPages,
    );

    return self::dataResponse([
      'pageLabel' => $pageLabel,
    ]);
  }

  /**
   * Get a font sample
   *
   * @param string $text
   *
   * @param string $font
   *
   * @param int $fontSize
   *
   * @param string $textColor RGB text-color to use in #RRGGBB format (hex), defaults to black #000000.
   *
   * @param string $format
   *
   * @param string $output The output format.
   *
   * @param string $hash MD5 checksum of font-data, used for cache-invalidation.
   *
   * @return Response
   *
   * @see FONT_SAMPLE_OUTPUT_FORMATS
   *
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function getFontSample(
    string $text,
    string $font,
    int $fontSize = 12,
    string $textColor = '#FF0000',
    string $format = FontService::FONT_SAMPLE_FORMAT_SVG,
    string $output = self::FONT_SAMPLE_OUTPUT_FORMAT_OBJECT,
    ?string $hash = null,
  ):Response {
    $cache = true;
    $metaData = null;
    try {
      $sampleData = $this->fontService->generateFontSample(
        urldecode($text),
        urldecode($font),
        $fontSize,
        rgbTextColor: $textColor,
        format: $format,
        hash: $hash,
        sampleMetaData: $metaData,
      );
    } catch (Exceptions\EnduserNotificationException $e) {
      $message = $e->getMessage();
      $fontSize = 12;
      $width = $fontSize * strlen($message);
      $padding = $fontSize / 4;
      $height = 2 * $padding + $fontSize;
      $left = $padding;
      $top = $fontSize + $padding;
      $sampleData =<<<EOF
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<svg xmlns="http://www.w3.org/2000/svg" height="$height" width="$width" version="1.0" viewBox="0 0 $width $height">
<text x="$left" y="$top" font-family="sans" font-size="$fontSize">$message</text>
</svg>
EOF;
      $cache = false;
    }
    switch ($output) {
      case self::FONT_SAMPLE_OUTPUT_FORMAT_OBJECT:
        $data = array_merge($metaData, [
          'data' => base64_encode($sampleData),
        ]);
        return self::dataResponse($data);
      case self::FONT_SAMPLE_OUTPUT_FORMAT_BLOB:
        $downloadResponse = self::dataDownloadResponse($sampleData, $metaData['fileName'], $metaData['mimeType']);
        if ($cache) {
          $downloadResponse->cacheFor(1800, public: true, immutable: true);
        }
        return $downloadResponse;
    }
  }

  /**
   * @param IL10N $l
   *
   * @return string
   */
  public static function getDefaultPdfFileNameTemplate(IL10N $l):string
  {
    return '{' . $l->t('DATETIME') . '}-{' . $l->t('DIRNAME') . '@:/' . '}-{' . $l->t('BASENAME') . '}' . '.pdf';
  }

  /**
   * Generate a page label from a user supplied template and and example data-set.
   *
   * @param string $template
   *
   * @param string $path Example file path, preferrably with non-empty
   * directory part.
   *
   * @return DataResponse
   *
   * @NoAdminRequired
   * @NoCSRFRequired
   */
  public function getPdfFileNameSample(
    string $template,
    string $path,
  ):DataResponse {
    $template = urldecode($template);
    $path = urldecode($path);

    $pdfFileName = $this->fileSystemWalker->getPdfFileName($template, $path);

    return self::dataResponse([
      'pdfFileName' => $pdfFileName,
    ]);
  }
}
