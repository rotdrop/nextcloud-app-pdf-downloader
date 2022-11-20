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
use OCP\Files\IRootFolder;
use OCP\Files\Node as FileSystemNode;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\IMimeTypeDetector;

use OCA\RotDrop\Toolkit\Service\ArchiveService;
use OCA\RotDrop\Toolkit\Exceptions as ToolkitExceptions;

use OCA\PdfDownloader\Exceptions;
use OCA\PdfDownloader\Service\AnyToPdf;
use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\PdfGenerator;
use OCA\PdfDownloader\Service\FontService;
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

  public const ERROR_PAGES_FONT = 'dejavusans';
  public const ERROR_PAGES_FONT_SIZE = '12';
  public const ERROR_PAGES_PAPER = 'A4';

  private const ARCHIVE_HANDLED = 0;
  private const ARCHIVE_IGNORED = 2;

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

  /** @var AnyToPdf */
  private $anyToPdf;

  /** @var ArchiveService */
  private $archiveService;

  /** @var IRootFolder */
  private $rootFolder;

  /** @var IMimeTypeDetector */
  private $mimeTypeDetector;

  /** @var IConfig */
  private $cloudConfig;

  /** @var Folder */
  private $userFolder;

  /** @var FontService */
  private $fontService;

  /** @var string */
  private $userId;

  /** @var string */
  private $errorPagesFont = self::ERROR_PAGES_FONT;

  /** @var int */
  private $errorPagesFontSize = self::ERROR_PAGES_FONT_SIZE;

  /* @var bool */
  private $extractArchiveFiles = false;

  /** @var null|int */
  private $archiveSizeLimit = null;

  /** @var int */
  private $archiveBombLimit = Constants::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT;

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
    IRootFolder $rootFolder,
    IMimeTypeDetector $mimeTypeDetector,
    Pdfcombiner $pdfCombiner,
    AnyToPdf $anyToPdf,
    ArchiveService $archiveService,
    FontService $fontService,
    IDateTimeZone $dateTimeZone,
  ) {
    parent::__construct($appName, $request);
    $this->l = $l10n;
    $this->logger = $logger;
    $this->rootFolder = $rootFolder;
    $this->cloudConfig = $cloudConfig;
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->pdfCombiner = $pdfCombiner;
    $this->anyToPdf = $anyToPdf;
    $this->fontService = $fontService;
    $this->archiveService = $archiveService;
    $this->archiveService->setL10N($l10n);
    $this->dateTimeZone = $dateTimeZone;

    if ($this->cloudConfig->getAppValue($this->appName, SettingsController::ADMIN_DISABLE_BUILTIN_CONVERTERS, false)) {
      $this->anyToPdf->disableBuiltinConverters();
    } else {
      $this->anyToPdf->enableBuiltinConverters();
    }
    $this->anyToPdf->setFallbackConverter(
      $this->cloudConfig->getAppValue($this->appName, SettingsController::ADMIN_FALLBACK_CONVERTER, null));
    $this->anyToPdf->setUniversalConverter(
      $this->cloudConfig->getAppValue($this->appName, SettingsController::ADMIN_UNIVERSAL_CONVERTER, null));

    $this->extractArchiveFiles = $this->cloudConfig->getAppValue(
      $this->appName, SettingsController::EXTRACT_ARCHIVE_FILES, false);

    $this->archiveBombLimit = $cloudConfig->getAppValue(
      $this->appName, SettingsController::ARCHIVE_SIZE_LIMIT, Constants::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT);

    $this->archiveSizeLimit = $cloudConfig->getUserValue(
      $this->userId, $this->appName, SettingsController::ARCHIVE_SIZE_LIMIT, null);

    /** @var IUser $user */
    $user = $userSession->getUser();
    if (!empty($user)) {
      $this->userFolder = $this->rootFolder->getUserFolder($user->getUID());
      $this->userId = $user->getUID();
      $this->pdfCombiner->setOverlayTemplate(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_TEMPLATE, null)
      );
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

      $this->setErrorPagesFont(
        $this->cloudConfig->getUserValue(
          $this->userId, $this->appName, SettingsController::PERSONAL_GENERATED_PAGES_FONT));
      if ($this->extractArchiveFiles) {
        $this->extractArchiveFiles =
          $this->cloudConfig->getUserValue(
            $this->userId, $this->appName, SettingsController::EXTRACT_ARCHIVE_FILES, true);
        // $this->logInfo('USER EXTRACT_ARCHIVE_FILES ' . $this->extractArchiveFiles);
      }
      $grouping = $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_GROUPING, PdfCombiner::GROUP_FOLDERS_FIRST);
      $this->pdfCombiner->setGrouping($grouping);
    }

    $this->archiveService->setSizeLimit($this->actualArchiveSizeLimit());
  }

  /**
   * Return the current error-pages font-name.
   *
   * @return string The font-name.
   */
  public function getErrorPagesFont():string
  {
    return $this->errorPagesFont ?? self::ERROR_PAGES_FONT;
  }

  /**
   * Set the current error-pages font-name.
   *
   * @param null|string $errorPagesFont Specify `null` to reset to the default.
   *
   * @return MultiPdfDownloadController Return $this for chaining setters.
   */
  public function setErrorPagesFont(?string $errorPagesFont):MultiPdfDownloadController
  {
    $this->errorPagesFont = empty($errorPagesFont) ? self::ERROR_PAGES_FONT : $errorPagesFont;

    return $this;
  }

  /**
   * Return the current error-pages font-size.
   *
   * @return int The font-size
   */
  public function getErrorPagesFontSize():int
  {
    return $this->errorPagesFontSize ?? self::ERROR_PAGES_FONT_SIZE;
  }

  /**
   * Set the current error-pages font-size.
   *
   * @param null|int $errorPagesFontSize The font-size in [pt]. Use null to reset to default.
   *
   * @return MultiPdfDownloadController Return $this for chaining setters.
   */
  public function setErrorPagesFontSize(?int $errorPagesFontSize):MultiPdfDownloadController
  {
    $this->errorPagesFontSize = $errorPagesFontSize === null ? self::ERROR_PAGES_FONT_SIZE : $errorPagesFontSize;

    return $this;
  }

  /**
   * @param null|string $fileData
   *
   * @param string $path
   *
   * @param Throwable $throwable
   *
   * @return string
   */
  private function generateErrorPage(?string $fileData, string $path, Throwable $throwable):string
  {
    $pdf = new PdfGenerator(orientation: 'P', unit: 'mm', format: self::ERROR_PAGES_PAPER);
    $pdf->setFont($this->getErrorPagesFont());
    $pdf->setFontSize($this->getErrorPagesFontSize());

    $mimeType = $fileData ? $this->mimeTypeDetector->detectString($fileData) : $this->l->t('unknown');

    $message = $throwable->getMessage();
    $trace = $throwable->getTraceAsString();
    $html =<<<__EOF__
<h1>Error converting $path to PDF</h1>
<h2>Mime-Type</h2>
<span>$mimeType</span>
<h2>Error Message</h2>
<span>$message</span>
<h2>Trace</h2>
<pre>$trace</pre>
__EOF__;

    $pdf->addPage();
    $pdf->writeHTML($html);

    return $pdf->Output($path, 'S');
  }

  /**
   * Try to handle an archive file, actgually any file.
   *
   * @param File $fileNode
   *
   * @param string $parentName
   *
   * @return int self::ARCHIVE_HANDLED or self::ARCHIVE_IGNORED
   */
  private function addArchiveMembers(File $fileNode, string $parentName = ''):int
  {
    $path = $parentName . '/' . $fileNode->getName();

    try {
      $this->archiveService->open($fileNode);

      $archiveDirectoryName = $this->archiveService->getArchiveFolderName();
      $topLevelFolder = $this->archiveService->getCommonDirectoryPrefix();
      $this->logInfo('COMMON PREFIX ' . $topLevelFolder);
      $stripRoot = !empty($topLevelFolder) ? strlen($topLevelFolder) : 0;

      foreach (array_keys($this->archiveService->getFiles()) as $archiveFile) {
        $this->logInfo('ARCHIVE FILE ' . $archiveFile);
        $path = $parentName . '/' . $archiveDirectoryName . '/' . substr($archiveFile, $stripRoot);
        try {
          $fileData = $this->archiveService->getFileContent($archiveFile);
          $mimeType = $this->mimeTypeDetector->detectString($fileData);
          $pdfData = $this->anyToPdf->convertData($fileData, $mimeType);
        } catch (Throwable $t) {
          $this->logException($t);
          $pdfData = $this->generateErrorPage($fileData ?? null, $path, $t);
        }
        $this->pdfCombiner->addDocument($pdfData, $path);
      }

      return self::ARCHIVE_HANDLED; // success
    } catch (ToolkitExceptions\ArchiveCannotOpenException $oe) {
      $this->logException($oe, level: LogLevel::DEBUG);

      return self::ARCHIVE_IGNORED; // process as ordinary file
    } catch (ToolkitExceptions\ArchiveTooLargeException $se) {
      $pdfData = $this->generateErrorPage($fileData, $path, $se);
      $this->pdfCombiner->addDocument($pdfData, $path);
      return self::ARCHIVE_HANDLED;
    }
  }

  /**
   * @param Folder $folder
   *
   * @param $string $parentName
   *
   * @return void
   */
  private function addFilesRecursively(Folder $folder, string $parentName = ''):void
  {
    $parentName .= (!empty($parentName) ? '/' : '') . $folder->getName();
    /** @var FileSystemNode $node */
    foreach ($folder->getDirectoryListing() as $node) {
      switch ($node->getType()) {
        case FileInfo::TYPE_FOLDER:
          $this->addFilesRecursively($node, $parentName);
          break 1;
        case FileInfo::TYPE_FILE:
          /** @var File $node */
          if ($this->extractArchiveFiles
              && $this->addArchiveMembers($node, $parentName) === self::ARCHIVE_HANDLED) {
            continue 2;
          }

          $path = $parentName . '/' . $node->getName();
          $fileData = $node->getContent();
          try {
            $pdfData = $this->anyToPdf->convertData($fileData, $node->getMimeType());
          } catch (Throwable $t) {
            $this->logException($t);
            $pdfData = $this->generateErrorPage($fileData, $path, $t);
          }
          $this->pdfCombiner->addDocument($pdfData, $path);
          break;
        default:
          throw new Exceptions\Exception(
            $this->l->t(
              'Internal error, unknown file-system node-type: "%s".',
              $node->getType()
            ));
      }
    }
  }

  /**
   * Download the contents of the given folder as multi-page PDF after
   * converting everything to PDF.
   *
   * @param string $nodePath The path to the file-system node to convert to
   * PDF.
   *
   * @param null|string $downloadFileName The file-name presented to the
   * http-client. If null defaults to the pre-configured file-name template.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function get(string $nodePath, ?string $downloadFileName):Response
  {
    $pageLabels = $this->cloudConfig->getUserValue(
      $this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS, true);
    $this->pdfCombiner->addPageLabels($pageLabels);
    $nodePath = urldecode($nodePath);

    /** @var FileSystemNode $node */
    $node = $this->userFolder->get($nodePath);
    if ($node->getType() === FileInfo::TYPE_FOLDER) {
      $this->addFilesRecursively($node);
    } else {
      if (!$this->extractArchiveFiles
               || $this->addArchiveMembers($node) !== self::ARCHIVE_HANDLED) {
        if (!$this->extractArchiveFiles) {
          return self::grumble(
            $this->l->t('"%s" is not a folder and archive extraction is disabled.', $nodePath));
        } else {
          return self::grumble(
            $this->l->t('"%s" is not a folder and cannot be processed by archive extraction.', $nodePath));
        }
      }
      $pathInfo = pathinfo($nodePath);
      $nodePath = $pathInfo['dirname'] . Constants::PATH_SEPARATOR . basename($pathInfo['filename'], '.tar');
    }

    if (empty($downloadFileName)) {
      $template = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE,
        MultiPdfDownloadController::getDefaultPdfFileNameTemplate($this->l),
      );
      $this->logInfo('TEMPLATE ' . $template);
      $fileName = basename($this->getPdfFileName($template, $nodePath), '.pdf') . '.pdf';
    } else {
      $fileName = basename($downloadFileName, '.pdf') . '.pdf';
    }

    $this->logInfo('DOWNLOAD FILENAME ' . $fileName);

    return self::dataDownloadResponse($this->pdfCombiner->combine(), $fileName, 'application/pdf');
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
   * @param int $pageNumber Current page-number example.
   *
   * @param int $totalPages Total number of pages example. $pageNumber should
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
    int $pageNumber,
    int $totalPages,
  ):DataResponse {
    $template = urldecode($template);
    $path = urldecode($path);
    $this->pdfCombiner->setOverlayTemplate($template);
    $pageLabel = $this->pdfCombiner->makePageLabelFromTemplate($path, $pageNumber, $totalPages);

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

  /** @return int */
  private function actualArchiveSizeLimit():int
  {
    return min($this->archiveBombLimit, $this->archiveSizeLimit ?? PHP_INT_MAX);
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

    $pdfFileName = $this->getPdfFileName($template, $path);

    return self::dataResponse([
      'pdfFileName' => $pdfFileName,
    ]);
  }

  /**
   * Generate a download file-name from a given template and full path.
   *
   * @param string $template
   *
   * @param string $path Folder Path.
   * directory part.
   *
   * @return string
   */
  private function getPdfFileName(
    string $template,
    string $path,
  ):string {
    $keys = [
      'BASENAME' => $this->l->t('BASENAME'),
      'FILENAME' => $this->l->t('FILENAME'),
      'EXTENSION' => $this->l->t('EXTENSION'),
      'DIRNAME' => $this->l->t('DIRNAME'),
      'DATETIME' => $this->l->t('DATETIME'),
    ];
    $pathInfo = pathinfo($path);
    $templateValues = [
      'BASENAME' => $pathInfo['basename'],
      'FILENAME' => $pathInfo['filename'],
      'DIRNAME' => $pathInfo['dirname'],
      'EXTENSION' => $pathInfo['extension'] ?? '',
      'DATETIME' => (new DateTimeImmutable)->setTimezone($this->dateTimeZone->getTimeZone()),
    ];

    $pdfFileName = $this->replaceBracedPlaceholders($template, $templateValues, $keys);

    return $pdfFileName;
  }
}
