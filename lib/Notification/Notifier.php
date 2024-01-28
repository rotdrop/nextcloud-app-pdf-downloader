<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2024 Claus-Justus Heine <himself@claus-justus-heine.de>
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

namespace OCA\PdfDownloader\Notification;

use InvalidArgumentException;

use Psr\Log\LoggerInterface as ILogger;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\IUserSession;
use OCP\IPreview;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

use OCA\PdfDownloader\BackgroundJob\PdfGeneratorJob;

/**
 * Background PDF generator job in order to move time-consuming jobs out of
 * reach of the web-server limits.
 */
class Notifier implements INotifier
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\NodeTrait;
  use \OCA\PdfDownloader\Toolkit\Traits\UserRootFolderTrait;

  public const TYPE_ANY = 0;
  public const TYPE_DOWNLOAD = (1 << 0);
  public const TYPE_FILESYSTEM = (1 << 1);
  public const TYPE_SCHEDULED = (1 << 2);
  public const TYPE_SUCCESS = (1 << 3);
  public const TYPE_FAILURE = (1 << 4);
  public const TYPE_CLEANED = (1 << 5);

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    protected $appName,
    protected ILogger $logger,
    protected IL10NFactory $l10nFactory,
    protected IURLGenerator $urlGenerator,
    protected IRootFolder $rootFolder,
    protected IPreview $previewManager,
    IUserSession $userSession,
  ) {
    $user = $userSession->getUser();
    if (!empty($user)) {
      $this->userId = $user->getUID();
    }
  }
  // phpcs:enable

  /** {@inheritdoc} */
  public function getID(): string
  {
    return $this->appName;
  }

  /** {@inheritdoc} */
  public function getName():string
  {
    return $this->l10nFactory->get($this->appName)->t('PDF Downloader');
  }

  /** {@inheritdoc} */
  public function prepare(INotification $notification, string $languageCode):INotification
  {
    if ($notification->getApp() !== $this->appName) {
      throw new InvalidArgumentException('Application should be ' . $this->appName . ' instead of ' . $notification->getApp());
    }

    $l = $this->l10nFactory->get($this->appName, $languageCode);

    $this->userId = $notification->getUser();

    $parameters = $notification->getSubjectParameters();
    $richSubstitutions = [
      'source' => [
        'type' => 'file',
        'id' => $parameters['sourceId'],
        'name' => $parameters['sourceBaseName'],
        'path' => $parameters['sourcePath'],
        'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
          'fileid' => $parameters['sourceId'],
        ]),
      ],
    ];

    switch ($notification->getSubject()) {
      case self::TYPE_SCHEDULED|self::TYPE_FILESYSTEM:
        $this->logInfo('PREPARE PENDING SAVE');
        $richSubstitutions['destination'] = [
          'type' => 'highlight',
          'id' => $notification->getObjectId(),
          'name' => $parameters['destinationBaseName'],
        ];
        $notification->setRichSubject($l->t('A PDF file {destination} will be created from the sources at {source}.'), $richSubstitutions);
        break;
      case self::TYPE_SCHEDULED|self::TYPE_DOWNLOAD:
        $this->logInfo('PREPARE PENDING DOWNLOAD');
        $notification->setRichSubject($l->t('A PDF download will be created from the sources at {source}.'), $richSubstitutions);
        break;
      case self::TYPE_SUCCESS|self::TYPE_FILESYSTEM:
        $this->logInfo('PREPARE SUCCESS SAVE');
        $richSubstitutions['destination'] = [
          'type' => 'file',
          'id' => $parameters['destinationId'],
          'name' => $parameters['destinationBaseName'],
          'path' => $parameters['destinationDirectory'],
          'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
            'fileid' => $parameters['destinationId'],
            'requesttoken' => \OCP\Util::callRegister(),
          ]),
          'status' => 'filesystem',
        ];
        $this->logInfo('AFTER PREPARE DEST PARAMETERS');

        try {
          $destination = $this->getUserRootFolder()->get($parameters['destinationPath']);
          $richSubstitutions['destination']['file'] = $this->formatNode($destination);
        } catch (NotFoundException $e) {
          $this->logException($e, 'SUCCESS, but no pdf-file BY PATH ' . print_r($parameters, true));
        }

        $notification->setRichSubject(
          $l->t(
            'Your folder {source} has been converted to a PDF file at {destination}.'
          ),
          $richSubstitutions
        );
        break;
      case self::TYPE_SUCCESS|self::TYPE_DOWNLOAD:
        $richSubstitutions['destination'] = [
          'type' => 'file',
          'id' => $parameters['destinationId'],
          'name' => $parameters['destinationBaseName'],
          'path' => $parameters['destinationDirectory'],
          'link' => $this->urlGenerator->linkToRouteAbsolute($this->appName . '.multi_pdf_download.get', [
            'sourceFileId' => $parameters['sourceId'],
            'cacheId' => $parameters['destinationId'],
            'requesttoken' => \OCP\Util::callRegister(),
          ]),
          'status' => 'download',
        ];

        try {
          $this->logInfo('PREPARE SUCCESS DOWNLOAD, TRY GET FILE ' . $parameters['destinationPath']);
          $destination = $this->getUserRootFolder()->get($parameters['destinationPath']);
          $this->logInfo('PREPARE SUCCESS DOWNLOAD, GOT FILE ' . $parameters['destinationPath'] . ' ' . $destination->getPath());
          $richSubstitutions['destination']['file'] = $this->formatNode($destination);
          $this->logInfo('GOT FILE ' . print_r($richSubstitutions['destination']['file'], true));
        } catch (NotFoundException $e) {
          $this->logException($e, 'SUCCESS, but no pdf-file BY PATH ' . print_r($parameters, true));
        }

        $notification->setRichSubject(
          $l->t(
            'Your folder {source} has been converted to a PDF file.'
          ), $richSubstitutions
        );
        $notification->setRichMessage(
          $l->t(
            'Please visit the details tab of the source folder {source} to download the file, or just click on the following link. The name of the download is {destination}. The download file will be removed automatically after some time, this purge-time can be configured in our personal preferences for this app.'
          ),
          $richSubstitutions,
        );
        break;
      case self::TYPE_FAILURE|self::TYPE_FILESYSTEM:
      case self::TYPE_FAILURE|self::TYPE_DOWNLOAD:
        $parameters = $notification->getSubjectParameters();
        $errorMessage = $parameters['errorMessage'] ?? null;
        if ($errorMessage) {
          $subjectTemplate = $l->t('Converting {source} to PDF has failed: {message}');
        } else {
          $subjectTemplate = $l->t('Converting {source} to PDF has failed.');
        }
        $notification->setRichSubject($subjectTemplate, [
          'source' => [
            'type' => 'file',
            'id' => $parameters['sourceId'],
            'name' => $parameters['sourceBaseName'],
            'path' => $parameters['sourceDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['sourceId'],
              'requesttoken' => \OCP\Util::callRegister(),
            ]),
          ],
          'message' => [
            'type' => 'highlight',
            'id' => $notification->getObjectId(),
            'name' => $l->t($errorMessage),
          ],
        ]);
        break;
      case self::TYPE_CLEANED|self::TYPE_FILESYSTEM:
      case self::TYPE_CLEANED|self::TYPE_DOWNLOAD:
      default:
        throw new InvalidArgumentException($l->t('Unsupported subject: "%s".', $notification->getSubject()));
    }
    $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath($this->appName, 'app-dark.svg')));
    $this->setParsedSubjectFromRichSubject($notification);
    if ($notification->getRichMessage()) {
      $this->setParsedMessageFromRichMessage($notification);
    }

    return $notification;
  }

  /**
   * @param INotification $notification
   *
   * @return void
   */
  protected function setParsedSubjectFromRichSubject(INotification $notification):void
  {
    $placeholders = $replacements = [];
    foreach ($notification->getRichSubjectParameters() as $placeholder => $parameter) {
      $placeholders[] = '{' . $placeholder . '}';
      if ($parameter['type'] === 'file') {
        $replacements[] = $parameter['path'];
      } else {
        $replacements[] = $parameter['name'];
      }
    }

    $notification->setParsedSubject(str_replace($placeholders, $replacements, $notification->getRichSubject()));
  }

  /**
   * @param INotification $notification
   *
   * @return void
   */
  protected function setParsedMessageFromRichMessage(INotification $notification):void
  {
    $placeholders = $replacements = [];
    foreach ($notification->getRichMessageParameters() as $placeholder => $parameter) {
      $placeholders[] = '{' . $placeholder . '}';
      if ($parameter['type'] === 'file') {
        $replacements[] = $parameter['path'];
      } else {
        $replacements[] = $parameter['name'];
      }
    }

    $notification->setParsedMessage(str_replace($placeholders, $replacements, $notification->getRichMessage()));
  }
}
