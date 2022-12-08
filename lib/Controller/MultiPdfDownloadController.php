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
use OCP\BackgroundJob\IJobList;

use OCP\IUser;
use OCP\IUserSession;
use OCP\Files\Node;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\IRootFolder;

use OCA\RotDrop\Toolkit\Exceptions\AuthorizationException;
use OCA\RotDrop\Toolkit\Service\UserScopeService;

use OCA\PdfDownloader\Exceptions;
use OCA\PdfDownloader\Service\PdfCombiner;
use OCA\PdfDownloader\Service\PdfGenerator;
use OCA\PdfDownloader\Service\FontService;
use OCA\PdfDownloader\Service\FileSystemWalker;
use OCA\PdfDownloader\Service\NotificationService;
use OCA\PdfDownloader\BackgroundJob\PdfGeneratorJob;
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

  /** @var IConfig */
  private $cloudConfig;

  /** @var IJobList */
  private $jobList;

  /** @var NotificationService */
  private $notificationService;

  /** @var UserScopeService */
  private $userScopeService;

  /** @var PdfCombiner */
  private $pdfCombiner;

  /** @var FontService */
  private $fontService;

  /** @var FileSystemWalker */
  private $fileSystemWalker;

  /** @var string */
  protected $userId;

  /** @var IDateTimeZone */
  private $dateTimeZone;

  /** @var bool */
  private $useAuthenticatedBackgroundJobs;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    IRequest $request,
    IL10N $l10n,
    ILogger $logger,
    IUserSession $userSession,
    IConfig $cloudConfig,
    IRootFolder $rootFolder,
    IJobList $jobList,
    NotificationService $notificationService,
    UserScopeService $userScopeService,
    Pdfcombiner $pdfCombiner,
    FontService $fontService,
    IDateTimeZone $dateTimeZone,
    FileSystemWalker $fileSystemWalker,
  ) {
    parent::__construct($appName, $request);
    $this->l = $l10n;
    $this->logger = $logger;
    $this->cloudConfig = $cloudConfig;
    $this->rootFolder = $rootFolder;
    $this->jobList = $jobList;
    $this->notificationService = $notificationService;
    $this->userScopeService = $userScopeService;
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
      $this->useAuthenticatedBackgroundJobs = $this->cloudConfig->getUserValue(
        $this->userId, $this->appName, SettingsController::PERSONAL_AUTHENTICATED_BACKGROUND_JOBS, SettingsController::PERSONAL_AUTHENTICATED_BACKGROUND_JOBS_DEFAULT);
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
   * @param null|string $downloadFileName The filename presented to the
   * http-client. If null defaults to the pre-configured filename template.
   *
   * @param null|int $cacheId The file-id of a cached file which had been
   * prepared in the background.
   *
   * @param null|bool $pageLabels Whether to decorate the pages with a label.
   *
   * @param null|bool $useTemplate Wether to ignore $downloadFileName and use
   * the configured filename template.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function get(
    string $sourcePath,
    ?string $downloadFileName = null,
    ?int $cacheId = null,
    ?bool $pageLabels = null,
    ?bool $useTemplate = null,
  ):Response {
    $sourcePath = urldecode($sourcePath);

    if ($cacheId !== null) {
      $cacheFile = $this->fileSystemWalker->getCacheFile($sourcePath, $cacheId);
      if (empty($cacheFile)) {
        return self::grumble($this->l->t('Unable to find cached download file with id "%d".', $cacheId));
      }
      return self::dataDownloadResponse($cacheFile->getContent(), $cacheFile->getName(), $cacheFile->getMimeType());
    }

    if (!empty($downloadFileName)) {
      $downloadFileName = urldecode($downloadFileName);
    }
    $downloadFileName = $this->fileSystemWalker->getPdfFilePath($sourcePath, $downloadFileName, $useTemplate);

    $fileName = basename($downloadFileName, '.pdf') . '.pdf';

    $pdfData = $this->fileSystemWalker->generateDownloadData($sourcePath, $pageLabels);

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
   * stored with the configured filename template under the configured
   * directory.
   *
   * @param null|bool $pageLabels Whether to decorate the pages with a label.
   *
   * @param null|bool $useTemplate Wether to ignore $downloadFileName and use
   * the configured filename template.
   *
   * @param null|int $cacheId The file-id of a cached file which had been
   * prepared in the background.
   *
   * @param null|bool $move Move the cache file, ignore if $cacheId is null.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function save(
    string $sourcePath,
    ?string $destinationPath = null,
    ?bool $pageLabels = null,
    ?bool $useTemplate = null,
    ?int $cacheId = null,
    ?bool $move = null,
  ):Response {
    $sourcePath = urldecode($sourcePath);
    if ($destinationPath !== null) {
      $destinationPath = urldecode($destinationPath);
    }

    if ($cacheId !== null) {
      $cacheFile = $this->fileSystemWalker->getCacheFile($sourcePath, $cacheId);
      if (empty($cacheFile)) {
        return self::grumble($this->l->t('Unable to find cached download file with id "%d".', $cacheId));
      }
      /** @var Folder $destinationFolder */
      try {
        $destinationFolder = $this->getUserFolder()->get($destinationPath);
      } catch (FileNotFoundException $e) {
        return self::grumble($this->l->t('Unable to open the destination-folder "%s".', $destinationPath));
      }
      $nonExistingTarget = $destinationFolder->getNonExistingName($cacheFile->getName());
      $destinationPath = $this->getUserFolderPath()
        . Constants::PATH_SEPARATOR . $destinationPath
        . Constants::PATH_SEPARATOR . $nonExistingTarget;

      $pdfFile = $move ? $cacheFile->move($destinationPath) : $cacheFile->copy($destinationPath);
    } else {
      $pdfFile = $this->fileSystemWalker->save(
        $sourcePath,
        $destinationPath,
        pageLabels: $pageLabels,
        useTemplate: $useTemplate,
      );
    }

    $pdfFilePath = $pdfFile->getInternalPath();

    return self::dataResponse([
      'pdfFilePath' => $pdfFilePath,
      'messages' => [ $this->l->t('PDF document saved as "%s".', $pdfFilePath), ],
    ]);
  }

  /**
   * Schedule PDF generation as background job for either downloading (later,
   * after being notified) or for direct storing in the file-system.
   *
   * @param string $jobType The target, either PdfGeneratorJob::TARGET_DOWNLOAD
   * or PdfGeneratorJob::TARGET_FILESYSTEM.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @param null|string $destinationPath The distination path in the cloud
   * where the resulting PDF data should be stored. If null then the file is
   * stored with the configured filename template under the configured
   * directory, or a download with the default configured default name is
   * prepared.
   *
   * @param null|bool $pageLabels Whether to decorate the pages with a label.
   *
   * @param null|bool $useTemplate Wether to ignore $downloadFileName and use
   * the configured filename template.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function schedule(
    string $jobType,
    string $sourcePath,
    ?string $destinationPath = null,
    ?bool $pageLabels = null,
    ?bool $useTemplate = null,
  ):Response {
    $sourcePath = urldecode($sourcePath);
    if ($destinationPath !== null) {
      $destinationPath = urldecode($destinationPath);
    }
    $destinationPath = $this->fileSystemWalker->getPdfFilePath($sourcePath, $destinationPath);
    $sourceNode = $this->getUserFolder()->get($sourcePath);
    $sourceNodeId = $sourceNode->getId();
    if ($jobType == PdfGeneratorJob::TARGET_DOWNLOAD) {
      $userAppFolder = $this->getUserAppFolder();
      /** @var Folder $destinationFolder */
      try {
        $destinationFolder = $userAppFolder->get((string)$sourceNodeId);
      } catch (FileNotFoundException $e) {
        $destinationFolder = $userAppFolder->newFolder((string)$sourceNodeId);
      }
      $destinationPath = $destinationFolder->getInternalPath() . Constants::PATH_SEPARATOR . basename($destinationPath);
      $useTemplate = false;
    } else {
      $destinationPath = Constants::USER_FOLDER_PREFIX . Constants::PATH_SEPARATOR . $destinationPath;
    }

    if ($this->useAuthenticatedBackgroundJobs) {
      $needsAuthentication = $sourceNode->getMountPoint()->getOption('authenticated', false);
      if (!$needsAuthentication && $sourceNode->getType() == FileInfo::TYPE_FOLDER) {
        try {
          $this->folderWalk($sourceNode, function(Node $node, int $depth) {
            if ($node->getType() == FileInfo::TYPE_FOLDER) {
              if ($node->getMountPoint()->getOption('authenticated', false)) {
                throw new AuthenticationException;
              }
            }
          });
        } catch (AuthenticationException $e) {
          $needsAuthentication = true;
        }
      }

      if ($needsAuthentication) {
        list('passphrase' => $tokenSecret) = $this->userScopeService->getAuthToken();
      }
    }

    $this->jobList->add(PdfGeneratorJob::class, [
      PdfGeneratorJob::TARGET_KEY => $jobType,
      PdfGeneratorJob::USER_ID_KEY => $this->userId,
      PdfGeneratorJob::SOURCE_ID_KEY => $sourceNodeId,
      PdfGeneratorJob::SOURCE_PATH_KEY => $sourcePath,
      PdfGeneratorJob::DESTINATION_PATH_KEY => $destinationPath,
      PdfGeneratorJob::PAGE_LABELS_KEY =>  $pageLabels,
      PdfGeneratorJob::USE_TEMPLATE_KEY => $useTemplate,
      PdfGeneratorJob::NEEDS_AUTHENTICATION_KEY => $needsAuthentication ?? false,
      PdfGeneratorJob::AUTH_TOKEN_KEY => $tokenSecret ?? null
    ]);

    $this->notificationService->sendNotificationOnPending($this->userId, $sourceNode, $destinationPath, $jobType);

    return self::dataResponse([
      'jobType' => $jobType,
      'pdfFilePath' => $destinationPath,
      'messages' => [ $this->l->t('PDF generation background job scheduled successfully.'), ],
    ]);
  }

  /**
   * Schedule PDF generation as background job for either downloading (later,
   * after being notified) or for direct storing in the file-system.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function list(string $sourcePath):Response
  {
    $sourcePath = urldecode($sourcePath);
    $sourceNode = $this->getUserFolder()->get($sourcePath);
    $sourceNodeId = $sourceNode->getId();

    $downloads = [];

    $userAppFolder = $this->getUserAppFolder();
    /** @var Folder $destinationFolder */
    try {
      $destinationFolder = $userAppFolder->get((string)$sourceNodeId);

      $listing = $destinationFolder->getDirectoryListing();
      /** @var File $pdfFile */
      foreach ($listing as $pdfFile) {
        if ($pdfFile->getType() != FileInfo::TYPE_FILE) {
          continue; // could throw - but we can as well just ignore it
        }
        $pathInfo = pathinfo($pdfFile->getInternalPath());
        $downloads[] = [
          'id' => $pdfFile->getId(),
          'path' => $pathInfo['dirname'],
          'dir' =>  $pathInfo['dirname'],
          'name' => $pathInfo['basename'],
          'size' => $pdfFile->getSize(),
          'type' => FileInfo::TYPE_FILE,
          'mtime' => $pdfFile->getMTime(),
          'mimetype' => $pdfFile->getMimeType(),
          'permissions' => $pdfFile->getPermissions(),
          'etag' => $pdfFile->getEtag(),
          'isEncrypted' => $pdfFile->isEncrypted(),
        ];
      }
    } catch (FileNotFoundException $e) {
      // ignore, this is just ok, nothing thas been generated yet
    }
    return self::dataResponse($downloads);
  }

  /**
   * Schedule PDF generation as background job for either downloading (later,
   * after being notified) or for direct storing in the file-system.
   *
   * @param string $sourcePath The path to the file-system node to convert to
   * PDF.
   *
   * @param null|int $cacheId
   *
   * @return Response
   *
   * @NoAdminRequired
   */
  public function clean(string $sourcePath, ?int $cacheId = null):Response
  {
    $sourcePath = urldecode($sourcePath);
    $sourceNode = $this->getUserFolder()->get($sourcePath);
    $sourceNodeId = $sourceNode->getId();

    $userAppFolder = $this->getUserAppFolder();

    if ($cacheId === null) {
      /** @var Folder $destinationFolder */
      try {
        $destinationFolder = $userAppFolder->get((string)$sourceNodeId);
        $destinationFolder->delete();
      } catch (FileNotFoundException $e) {
        // ignore, this is just ok, nothing thas been generated yet
      }
      return self::dataResponse([
        'messages' => [ $this->l->t('Cached PDF files for "%s" have been deleted.', $sourceNode->getName()), ],
      ]);
    }

    try {
      $destinationFolder = $userAppFolder->get((string)$sourceNodeId);
      /** @var File $cacheFile */
      list($cacheFile,) = $this->getUserAppFolder()->get($sourceNodeId)->getById($cacheId);
      $cacheFileName = $cacheFile->getName();
      $cacheFile->delete();
      return self::dataResponse([
        'messages' => [ $this->l->t('PDF file "%s" has been deleted.', $cacheFileName), ],
      ]);
    } catch (FileNotFoundException $e) {
      return self::grumble($this->l->t('Unable to find cached download file with id "%d".', $cacheId));
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

    $pdfFileName = $this->fileSystemWalker->getPdfFileName($path, $template);

    return self::dataResponse([
      'pdfFileName' => $pdfFileName,
    ]);
  }
}
