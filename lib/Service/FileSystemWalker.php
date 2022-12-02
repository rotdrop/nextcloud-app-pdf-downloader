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

namespace OCA\PdfDownloader\Service;

use OCP\IL10N;
use OCP\IConfig;
use OCP\IUser;
use Psr\Log\LoggerInterface as ILogger;

use OCP\Files\IMimeTypeDetector;
use OCP\IUserSession;
use OCP\Files\IRootFolder;
use OCP\Files\Node as FileSystemNode;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException as FileNotFoundException;

use OCA\RotDrop\Toolkit\Service\ArchiveService;
use OCA\RotDrop\Toolkit\Exceptions as ToolkitExceptions;

use OCA\PdfDownloader\Constants;
use OCA\PdfDownloader\Controller\SettingsController;
use OCA\PdfDownloader\Exceptions\EnduserNotificationException;

/**
 * Workhorse which finally combines the converter and merge services.
 */
class FileSystemWalker
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;

  public const ERROR_PAGES_FONT = 'dejavusans';
  public const ERROR_PAGES_FONT_SIZE = '12';
  public const ERROR_PAGES_PAPER = 'A4';

  private const ARCHIVE_HANDLED = 0;
  private const ARCHIVE_IGNORED = 2;

  /** @var string */
  private $appName;

  /** @var string */
  private $errorPagesFont = self::ERROR_PAGES_FONT;

  /** @var int */
  private $errorPagesFontSize = self::ERROR_PAGES_FONT_SIZE;

  /** @var IConfig */
  private $cloudConfig;

  /** @var IMimeTypeDetector */
  private $mimeTypeDetector;

  /** @var Folder */
  private $userFolder;

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var ArchiveService */
  private $archiveService;

  /** @var null|int */
  private $archiveSizeLimit = null;

  /** @var int */
  private $archiveBombLimit = Constants::DEFAULT_ADMIN_ARCHIVE_SIZE_LIMIT;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IL10N $l10n,
    ILogger $logger,
    IConfig $cloudConfig,
    IRootFolder $rootFolder,
    IMimeTypeDetector $mimeTypeDetector,
    IUserSession $userSession,
    Pdfcombiner $pdfCombiner,
    AnyToPdf $anyToPdf,
    ArchiveService $archiveService,
  ) {
    $this->appName = $appName;
    $this->l = $l10n;
    $this->logger = $logger;
    $this->cloudConfig = $cloudConfig;
    $this->mimeTypeDetector = $mimeTypeDetector;
    $this->pdfCombiner = $pdfCombiner;
    $this->anyToPdf = $anyToPdf;
    $this->archiveService = $archiveService;
    $this->archiveService->setL10N($l10n);

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
      $this->userFolder = $rootFolder->getUserFolder($user->getUID());
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
  // phpcs:enable

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
        $path = $parentName . '/' . $archiveDirectoryName . '/' . substr($archiveFile, $stripRoot);
        $this->logInfo('ARCHIVE FILE ' . $archiveFile . ' PATH ' . $path);
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
   * @param string $nodePath Source directory or archive file name.
   *
   * @return string The PDF data from combining the given sources below
   * $nodePath.
   */
  public function generateDownloadData(string $nodePath):string
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
          self::grumble(
            $this->l->t('"%s" is not a folder and archive extraction is disabled.', $nodePath));
        } else {
          self::grumble(
            $this->l->t('"%s" is not a folder and cannot be processed by archive extraction.', $nodePath));
        }
      }
      $pathInfo = pathinfo($nodePath);
      $nodePath = $pathInfo['dirname'] . Constants::PATH_SEPARATOR . basename($pathInfo['filename'], '.tar');
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
   * where the resulting PDF data should be stored.
   *
   * @return File File-system object pointing to the new file.
   */
  public function save(string $sourcePath, ?string $destinationPath = null):File
  {
    $pathInfo = pathinfo($destinationPath);
    $destinationDirName = $pathInfo['dirname'];
    $destinationBaseName = $pathInfo['basename'];
    try {
      $destinationFolder = $this->userFolder->get($destinationDirName);
      if ($destinationFolder->getType() != FileInfo::TYPE_FOLDER) {
        self::grumble($this->l->t('Destination parent folder conflicts with existing file "%s".', $destinationDirName));
      }
    } catch (FileNotFoundException $e) {
      try {
        $destinationFolder = $this->userFolder->newFolder($destinationDirName);
      } catch (Throwable $t) {
        self::grumble($this->l->t('Unable to create the parent folder "%s".', $destinationDirName));
      }
    }

    $this->logInfo('DESTINATION DIR ' . $destinationDirName);

    $pdfData = $this->fileSystemWalker->generateDownloadData($sourcePath);

    $this->logInfo('PDF DATA READY');

    $nonExistingTarget = $destinationFolder->getNonExistingName($destinationBaseName);
    if ($nonExistingTarget != $destinationBaseName) {
      $destinationBaseName = $nonExistingTarget;
    }

    return $destinationFolder->newFile($destinationBaseName, $pdfData);
  }

  /** @return int */
  private function actualArchiveSizeLimit():int
  {
    return min($this->archiveBombLimit, $this->archiveSizeLimit ?? PHP_INT_MAX);
  }

  /**
   * @param string $message
   *
   * @return void
   *
   * @throws EnduserNotificationException
   */
  private static function grumble(string $message):void
  {
    throw new EnduserNotificationException($message);
  }
}
