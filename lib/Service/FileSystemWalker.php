<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author    Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023, 2024 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\PdfDownloader\Service;

use Throwable;
use DateTimeImmutable;

use OCP\IL10N;
use OCP\IConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface as ILogger;
use Psr\Log\LogLevel;
use OCP\Files\IMimeTypeDetector;
use OCP\IUserSession;
use OCP\IDateTimeZone;
use OCP\Files\IRootFolder;
use OCP\Files\Node as FileSystemNode;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException as FileNotFoundException;

use OCA\PdfDownloader\Toolkit\Exceptions as ToolkitExceptions;

use OCA\PdfDownloader\Controller\MultiPdfDownloadController;
use OCA\PdfDownloader\Constants;
use OCA\PdfDownloader\Controller\SettingsController;
use OCA\PdfDownloader\Exceptions\EnduserNotificationException;

/**
 * Workhorse which finally combines the converter and merge services.
 */
class FileSystemWalker
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\UtilTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\UserRootFolderTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\IncludeExcludeTrait;

  public const ERROR_PAGES_FONT = 'dejavusans';
  public const ERROR_PAGES_FONT_SIZE = '12';
  public const ERROR_PAGES_PAPER = 'A4';

  private const ARCHIVE_HANDLED = 0;
  private const ARCHIVE_IGNORED = 2;

  /** @var string */
  private $errorPagesFont = self::ERROR_PAGES_FONT;

  /** @var int */
  private $errorPagesFontSize = self::ERROR_PAGES_FONT_SIZE;

  /** @var IConfig */
  private $cloudConfig;

  /** @var IMimeTypeDetector */
  private $mimeTypeDetector;

  /** @var IDateTimeZone */
  private $dateTimeZone;

  /** @var null|string */
  protected string $userId;

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var ArchiveService */
  private $archiveService;

  /** @var null|string */
  private $cloudFolderPath = null;

  /** @var null|int */
  private $archiveSizeLimit = null;

  /** @var int */
  private $archiveBombLimit = Constants::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT;

  /** @var null|array */
  private $templateKeyTranslations = null;

  /** @var null|string */
  private $includePattern = SettingsController::PERSONAL_EXCLUDE_PATTERN_DEFAULT;

  /** @var null|string */
  private $excludePattern = SettingsController::PERSONAL_INCLUDE_PATTERN_DEFAULT;

  /** @var bool */
  private $includeHasPrecedence = SettingsController::PERSONAL_PATTERN_PRECEDENCE_DEFAULT;

  /** @var bool */
  private $generateErrorPages = SettingsController::PERSONAL_GENERATE_ERROR_PAGES_DEFAULT;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IL10N $l10n,
    ILogger $logger,
    IConfig $cloudConfig,
    protected IRootFolder $rootFolder,
    IMimeTypeDetector $mimeTypeDetector,
    IUserSession $userSession,
    IDateTimeZone $dateTimeZone,
    PdfCombiner $pdfCombiner,
    AnyToPdf $anyToPdf,
    ArchiveService $archiveService,
  ) {
    $this->appName = $appName;
    $this->l = $l10n;
    $this->logger = $logger;
    $this->cloudConfig = $cloudConfig;
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->dateTimeZone = $dateTimeZone;
    $this->pdfCombiner = $pdfCombiner;
    $this->anyToPdf = $anyToPdf;
    $this->archiveService = $archiveService;

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

    /** @var IUser $user */
    $user = $userSession->getUser();
    if (!empty($user)) {
      $this->userId = $user->getUID();
      $this->archiveSizeLimit = $cloudConfig->getUserValue(
        $this->userId, $this->appName, SettingsController::ARCHIVE_SIZE_LIMIT, null);

      $this->pdfCombiner->setOverlayTemplate(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_TEMPLATE, null)
      );
      $this->pdfCombiner->setOverlayFont(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS_FONT, null)
      );
      $this->pdfCombiner->setOverlayFontSize(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS_FONT_SIZE, null)
      );
      $this->pdfCombiner->setOverlayPageWidthFraction(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_PAGE_WIDTH_FRACTION, null) ?: null
      );
      $this->pdfCombiner->setOverlayTextColor(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_TEXT_COLOR, null)
      );
      $this->pdfCombiner->setOverlayBackgroundColor(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABEL_BACKGROUND_COLOR, null)
      );

      $this->setErrorPagesFont(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_GENERATED_PAGES_FONT)
      );
      $this->setErrorPagesFontSize(
        $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_GENERATED_PAGES_FONT_SIZE, null)
      );
      if ($this->extractArchiveFiles) {
        $this->extractArchiveFiles =
          $this->cloudConfig->getUserValue(
            $this->userId, $this->appName, SettingsController::EXTRACT_ARCHIVE_FILES, true);
        // $this->logInfo('USER EXTRACT_ARCHIVE_FILES ' . $this->extractArchiveFiles);
      }
      $grouping = $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_GROUPING, PdfCombiner::GROUP_FOLDERS_FIRST);
      $this->pdfCombiner->setGrouping($grouping);

      $this->cloudFolderPath = $this->cloudConfig->getUserValue($this->userId, $this->appName, SettingsController::PERSONAL_PDF_CLOUD_FOLDER_PATH, null);

      $this->includePattern = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_INCLUDE_PATTERN,
        SettingsController::PERSONAL_INCLUDE_PATTERN_DEFAULT,
      );
      $this->excludePattern = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_EXCLUDE_PATTERN,
        SettingsController::PERSONAL_EXCLUDE_PATTERN_DEFAULT,
      );
      $precedence = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_PATTERN_PRECEDENCE,
        SettingsController::PERSONAL_PATTERN_PRECEDENCE_DEFAULT,
      );
      $this->includeHasPrecedence = $precedence !== SettingsController::EXCLUDE_HAS_PRECEDENCE;
      $this->generateErrorPages = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_GENERATE_ERROR_PAGES,
        SettingsController::PERSONAL_GENERATE_ERROR_PAGES_DEFAULT,
      );
    }

    $this->archiveService->setSizeLimit($this->actualArchiveSizeLimit());
  }
  // phpcs:enable

  /**
   * Decide whether the given file-name should be included in the output or
   * note, basing the decision on the configured include and exclude patterns.
   *
   * @param string $fileName
   *
   * @return bool
   */
  public function isFileIncluded(string $fileName):bool
  {
    return $this->isIncluded($fileName, $this->includePattern, $this->excludePattern, $this->includeHasPrecedence);
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
   * @return FileSystemWalker Return $this for chaining setters.
   */
  public function setErrorPagesFont(?string $errorPagesFont):FileSystemWalker
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
   * @return FileSystemWalker Return $this for chaining setters.
   */
  public function setErrorPagesFontSize(?int $errorPagesFontSize):FileSystemWalker
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

    $mimeType = $this->detectMimeType($path, $fileData);

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
    if (!$this->isFileIncluded($path)) {
      return self::ARCHIVE_IGNORED;
    }
    try {
      $this->archiveService->open($fileNode);

      $archiveDirectoryName = ArchiveService::getArchiveFolderName($fileNode->getPath());
      $topLevelFolder = $this->archiveService->getCommonDirectoryPrefix();
      $this->logInfo('COMMON PREFIX ' . $topLevelFolder);
      $stripRoot = !empty($topLevelFolder) ? strlen($topLevelFolder) : 0;

      foreach (array_keys($this->archiveService->getFiles()) as $archiveFile) {
        $path = $parentName . '/' . $archiveDirectoryName . '/' . substr($archiveFile, $stripRoot);
        // $this->logInfo('ARCHIVE FILE ' . $archiveFile . ' PATH ' . $path);
        if (!$this->isFileIncluded($path)) {
          continue;
        }
        try {
          $fileData = $this->archiveService->getFileContent($archiveFile);
          $mimeType = $this->detectMimeType($path, $fileData);
          $pdfData = $this->anyToPdf->convertData($fileData, $mimeType);
        } catch (Throwable $t) {
          $this->logException($t);
          if (!$this->generateErrorPages) {
            continue;
          }
          $pdfData = $this->generateErrorPage($fileData ?? null, $path, $t);
        }
        $this->pdfCombiner->addDocument($pdfData, $path);
      }

      return self::ARCHIVE_HANDLED; // success
    } catch (ToolkitExceptions\ArchiveCannotOpenException $oe) {
      $this->logException($oe, level: LogLevel::DEBUG);

      return self::ARCHIVE_IGNORED; // process as ordinary file
    } catch (ToolkitExceptions\ArchiveTooLargeException $se) {
      if ($this->generateErrorPages) {
        $pdfData = $this->generateErrorPage($fileData, $path, $se);
        $this->pdfCombiner->addDocument($pdfData, $path);
      }
      return self::ARCHIVE_HANDLED;
    }
  }

  /**
   * Add an individual file to the PDF document.
   *
   * @param File $node The file to add.
   *
   * @param string $parentName The name of the containing folder.
   *
   * @param bool $ignoreExcludes Add the file regardless of any exclude
   * patterns. This is used for the case were the user initiates direct
   * PDF conversion of an individual file in which case the exclude list
   * is not taken into account.
   *
   * @return bool Success status.
   */
  private function addFile(File $node, string $parentName, bool $ignoreExcludes = false):bool
  {
    if ($this->extractArchiveFiles
        && $this->addArchiveMembers($node, $parentName) === self::ARCHIVE_HANDLED) {
      return true;
    }
    $path = $parentName . '/' . $node->getName();
    if (!$ignoreExcludes && !$this->isFileIncluded($path)) {
      return true; // ignore
    }
    $fileData = $node->getContent();
    try {
      $pdfData = $this->anyToPdf->convertData($fileData, $node->getMimeType());
    } catch (Throwable $t) {
      $this->logException($t);
      if (!$this->generateErrorPages) {
        return false;
      }
      $pdfData = $this->generateErrorPage($fileData, $path, $t);
    }
    $this->pdfCombiner->addDocument($pdfData, $path);
    return true;
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
          break;
        case FileInfo::TYPE_FILE:
          $this->addFile($node, $parentName, ignoreExcludes: false);
          break;
        default:
          throw new Exceptions\Exception(
            $this->l->t(
              'Internal error, "%s" is neither a file nor a directory.',
              $node->getType()
            ));
      }
    }
  }

  /**
   * @param string $nodePath Source directory or archive file name.
   *
   * @param null|bool $pageLabels Whether to decorate the pages with a label.
   *
   * @return string The PDF data from combining the given sources below
   * $nodePath.
   */
  public function generateDownloadData(
    string $nodePath,
    ?bool $pageLabels = null,
  ):string {
    if ($pageLabels === null) {
      $pageLabels = $this->cloudConfig->getUserValue(
        $this->userId, $this->appName, SettingsController::PERSONAL_PAGE_LABELS, SettingsController::PERSONAL_PAGE_LABELS_DEFAULT);
    }
    $this->pdfCombiner->addPageLabels($pageLabels);

    /** @var FileSystemNode $node */
    $node = $this->getUserFolder()->get($nodePath);
    switch ($node->getType()) {
      case FileInfo::TYPE_FOLDER:
        $this->addFilesRecursively($node);
        break;
      case FileInfo::TYPE_FILE:
        if (!$this->addFile($node, parentName: '', ignoreExcludes: true)) {
          throw new EnduserNotificationException(
            $this->l->t('"%s" could not be converted to PDF.', $nodePath));
        }
        break;
      default:
        throw new Exceptions\Exception(
          $this->l->t(
            'Internal error, "%1$s" is neither a file nor a directory, but has type "%2$s.', [
              $nodePath, $node->getType(),
            ])
        );
        break;
    }

    return $this->pdfCombiner->combine();
  }

  /**
   * Download the contents of the given folder as multi-page PDF after
   * converting everything to PDF.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @param null|string $destinationPath The distination path in the cloud
   * where the resulting PDF data should be stored. If null then the default
   * location is used. The path must be relative to the parent of the user
   * folder.
   *
   * @param null|bool $pageLabels Whether to decorate the pages with a label.
   *
   * @param null|bool $useTemplate Wether to ignore $downloadFileName and use
   * the configured filename template.
   *
   * @return File File-system object pointing to the new file.
   */
  public function save(
    string $sourcePath,
    mixed $destinationPath = null,
    ?bool $pageLabels = null,
    ?bool $useTemplate = null,
  ):File {
    $destinationPath = $this->getPdfFilePath($sourcePath, $destinationPath, $useTemplate);
    $pathInfo = pathinfo($destinationPath);
    $destinationDirName = $pathInfo['dirname'];
    $destinationBaseName = $pathInfo['basename'];
    $userRootFolder = $this->getUserRootFolder();
    try {
      $destinationFolder = $userRootFolder->get($destinationDirName);
      if ($destinationFolder->getType() != FileInfo::TYPE_FOLDER) {
        throw new EnduserNotificationException(
          $this->l->t('Destination parent folder conflicts with existing file "%s".', $destinationDirName));
      }
    } catch (FileNotFoundException $e) {
      try {
        $destinationFolder = $userRootFolder->newFolder($destinationDirName);
      } catch (Throwable $t) {
        throw new EnduserNotificationException(
          $this->l->t('Unable to create the parent folder "%s".', $destinationDirName));
      }
    }

    // $this->logInfo('DESTINATION DIR ' . $destinationDirName);

    $pdfData = $this->generateDownloadData($sourcePath, $pageLabels);

    // $this->logInfo('PDF DATA READY');

    $nonExistingTarget = $destinationFolder->getNonExistingName($destinationBaseName);
    if ($nonExistingTarget != $destinationBaseName) {
      $destinationBaseName = $nonExistingTarget;
    }

    return $destinationFolder->newFile($destinationBaseName, $pdfData);
  }

  /**
   * @param string $sourcePath
   *
   * @param int $cacheFileId
   *
   * @return null|File
   */
  public function getCacheFile(string $sourcePath, int $cacheFileId):?File
  {
    $sourceNode = $this->getUserFolder()->get($sourcePath);
    $sourceNodeId = $sourceNode->getId();
    try {
      /** @var File $cacheFile */
      list($cacheFile,) = $this->getUserAppFolder()->get($sourceNodeId)->getById($cacheFileId);
    } catch (FileNotFoundException $e) {
      return null;
    }
    return $cacheFile;
  }

  /** @return int */
  private function actualArchiveSizeLimit():int
  {
    return min($this->archiveBombLimit, $this->archiveSizeLimit ?? PHP_INT_MAX);
  }

  /**
   * @param string $sourcePath
   *
   * @param string $destinationPath
   *
   * @param null|bool $useTemplate
   *
   * @return string
   */
  public function getPdfFilePath(string $sourcePath, ?string $destinationPath, ?bool $useTemplate = null):string
  {
    if ($destinationPath === null) {
      $destinationDirectory = Constants::USER_FOLDER_PREFIX . Constants::PATH_SEPARATOR
        . ($this->cloudFolderPath ?? dirname($sourcePath)) . Constants::PATH_SEPARATOR;
    } else {
      $destinationDirectory = dirname($destinationPath);
    }
    if ($destinationPath == null || $useTemplate === true) {
      // default cloud destination
      $destinationPath = $destinationDirectory . Constants::PATH_SEPARATOR . $this->getPdfFileName($sourcePath);
    }
    return $destinationPath;
  }

  /**
   * The purpose of this function is to collect translations for text
   * substitution placeholders in a single place in the source code in order
   * to make consistent translations possible.
   *
   * @return array
   */
  public function getTemplateKeyTranslations():array
  {
    if ($this->templateKeyTranslations !== null) {
      return $this->templateKeyTranslations;
    }
    $this->templateKeyTranslations = [
      // TRANSLATORS: This is a text substitution placeholder. If the target language knows the concept of casing, then
      // TRANSLATORS: please use only uppercase letters in the translation. Otherwise please use whatever else
      // TRANSLATORS: convention "usually" applies to placeholder keywords in the target language.
      'PATH' => $this->l->t('PATH'),
      // TRANSLATORS: This is a text substitution placeholder. If the target language knows the concept of casing, then
      // TRANSLATORS: please use only uppercase letters in the translation. Otherwise please use whatever else
      // TRANSLATORS: convention "usually" applies to placeholder keywords in the target language.
      'BASENAME' => $this->l->t('BASENAME'),
      // TRANSLATORS: This is a text substitution placeholder. If the target language knows the concept of casing, then
      // TRANSLATORS: please use only uppercase letters in the translation. Otherwise please use whatever else
      // TRANSLATORS: convention "usually" applies to placeholder keywords in the target language.
      'FILENAME' => $this->l->t('FILENAME'),
      // TRANSLATORS: This is a text substitution placeholder. If the target language knows the concept of casing, then
      // TRANSLATORS: please use only uppercase letters in the translation. Otherwise please use whatever else
      // TRANSLATORS: convention "usually" applies to placeholder keywords in the target language.
      'EXTENSION' => $this->l->t('EXTENSION'),
      // TRANSLATORS: This is a text substitution placeholder. If the target language knows the concept of casing, then
      // TRANSLATORS: please use only uppercase letters in the translation. Otherwise please use whatever else
      // TRANSLATORS: convention "usually" applies to placeholder keywords in the target language.
      'DIRNAME' => $this->l->t('DIRNAME'),
      // TRANSLATORS: This is a text substitution placeholder. If the target language knows the concept of casing, then
      // TRANSLATORS: please use only uppercase letters in the translation. Otherwise please use whatever else
      // TRANSLATORS: convention "usually" applies to placeholder keywords in the target language.
      'DATETIME' => $this->l->t('DATETIME'),
    ];
    return $this->templateKeyTranslations;
  }

  /**
   * @return string The localized default filename template.
   */
  public function getDefaultPdfFileNameTemplate():string
  {
    $keys = $this->getTemplateKeyTranslations();
    return '{' . $keys['DATETIME'] . '}-{' . $keys['DIRNAME'] . '@:/' . '}-{' . $keys['BASENAME'] . '}' . '.pdf';
  }

  /**
   * Generate a download filename from a given template and full path.
   *
   * @param string $path Folder path.
   *
   * @param null|string $template
   *
   * @return string
   */
  public function getPdfFileName(
    string $path,
    ?string $template = null,
  ):string {
    if (empty($template)) {
      $template = $this->cloudConfig->getUserValue(
        $this->userId,
        $this->appName,
        SettingsController::PERSONAL_PDF_FILE_NAME_TEMPLATE,
        $this->getDefaultPdfFileNameTemplate(),
      );
    }

    $keys = $this->getTemplateKeyTranslations();
    $pathInfo = pathinfo($path);
    $templateValues = [
      'PATH' => trim($path, Constants::PATH_SEPARATOR),
      'BASENAME' => $pathInfo['basename'],
      'FILENAME' => $pathInfo['filename'],
      'DIRNAME' => trim($pathInfo['dirname'], Constants::PATH_SEPARATOR),
      'EXTENSION' => $pathInfo['extension'] ?? '',
      'DATETIME' => (new DateTimeImmutable)->setTimezone($this->dateTimeZone->getTimeZone()),
    ];

    $pdfFileName = $this->replaceBracedPlaceholders($template, $templateValues, $keys);
    $pathInfo = pathinfo($pdfFileName);
    $pdfFileName = $pathInfo['filename'] . '.pdf';
    if (!empty($pathInfo['dirname']) && $pathInfo['dirname'] != '.') {
      $pdfFileName = $pathInfo['dirname'] . Constants::PATH_SEPARATOR . $pdfFileName;
    }

    return $pdfFileName;
  }

  /**
   * Try to make a good guess concerning the mime-type.
   *
   * @param string $path
   *
   * @param null|string $fileData
   *
   * @return string
   */
  private function detectMimeType(string $path, ?string $fileData):string
  {
    $pathType = $this->mimeTypeDetector->detectPath($path);
    if (!$fileData) {
      return $pathType;
    }
    $contentType = $this->mimeTypeDetector->detectString($fileData);
    if ($pathType !== $contentType) {
      switch ($contentType) {
        case 'application/octett-stream':
        case 'text/plain':
          return $pathType;
      }
    }
    return $contentType;
  }
}
