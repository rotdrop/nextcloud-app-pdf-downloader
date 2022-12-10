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

namespace OCA\PdfDownloader\Notification;

use InvalidArgumentException;

use Psr\Log\LoggerInterface as ILogger;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

use OCA\PdfDownloader\BackgroundJob\PdfGeneratorJob;

/**
 * Background PDF generator job in order to move time-consuming jobs out of
 * reach of the web-server limits.
 */
class Notifier implements INotifier
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;

  public const TYPE_DOWNLOAD = (1 << 0);
  public const TYPE_FILESYSTEM = (1 << 1);
  public const TYPE_SCHEDULED = (1 << 2);
  public const TYPE_SUCCESS = (1 << 3);
  public const TYPE_FAILURE = (1 << 4);

  /** @var string */
  protected $appName;

  /** @var IL10NFactory */
  protected $l10nFactory;

  /** @var IURLGenerator */
  protected $urlGenerator;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    string $appName,
    ILogger $logger,
    IL10NFactory $l10nFactory,
    IURLGenerator $urlGenerator,
  ) {
    $this->appName = $appName;
    $this->logger = $logger;
    $this->l10nFactory = $l10nFactory;
    $this->urlGenerator = $urlGenerator;
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

    switch ($notification->getSubject()) {
      case self::TYPE_SCHEDULED|self::TYPE_FILESYSTEM:
        $parameters = $notification->getSubjectParameters();
        $notification->setRichSubject($l->t('A PDF file {destination} will be created from the sources at {source}.'), [
          'destination' => [
            'type' => 'highlight',
            'id' => $notification->getObjectId(),
            'name' => $parameters['destinationBaseName'],
          ],
          'source' => [
            'type' => 'file',
            'id' => $parameters['sourceId'],
            'name' => $parameters['sourceBaseName'],
            'path' => $parameters['sourceDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['sourceId'],
            ]),
          ],
        ]);
        break;
      case self::TYPE_SCHEDULED|self::TYPE_DOWNLOAD:
        $parameters = $notification->getSubjectParameters();
        $notification->setRichSubject($l->t('A PDF download will be created from the sources at {source}.'), [
          'source' => [
            'type' => 'file',
            'id' => $parameters['sourceId'],
            'name' => $parameters['sourceBaseName'],
            'path' => $parameters['sourceDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['sourceId'],
            ]),
          ],
        ]);
        break;
      case self::TYPE_SUCCESS|self::TYPE_FILESYSTEM:
        $parameters = $notification->getSubjectParameters();
        $notification->setRichSubject($l->t('Your folder {source} has been converted to a PDF file at {destination}.'), [
          'source' => [
            'type' => 'file',
            'id' => $parameters['sourceId'],
            'name' => $parameters['sourceBaseName'],
            'path' => $parameters['sourceDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['sourceId'],
            ]),
          ],
          'destination' => [
            'type' => 'file',
            'id' => $parameters['destinationId'],
            'name' => $parameters['destinationBaseName'],
            'path' => $parameters['destinationDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['destinationId'],
            ]),
          ],
        ]);
        break;
      case self::TYPE_SUCCESS|self::TYPE_DOWNLOAD:
        $parameters = $notification->getSubjectParameters();
        $notification->setRichSubject($l->t('You folder {source} has been converted to a PDF file. Please visit the details-tab of the source-folder to download the file.'), [
          'source' => [
            'type' => 'file',
            'id' => $parameters['sourceId'],
            'name' => $parameters['sourceBaseName'],
            'path' => $parameters['sourceDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['sourceId'],
            ]),
          ],
          'destination' => [
            'type' => 'file',
            'id' => $parameters['destinationId'],
            'name' => $parameters['destinationBaseName'],
            'path' => $parameters['destinationDirectory'],
            'link' => $this->urlGenerator->linkToRouteAbsolute('files.viewcontroller.showFile', [
              'fileid' => $parameters['destinationId'],
            ]),
          ],
        ]);
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
            ]),
          ],
          'message' => [
            'type' => 'highlight',
            'id' => $notification->getObjectId(),
            'name' => $l->t($errorMessage),
          ],
        ]);
        break;
      default:
        throw new InvalidArgumentException($l->t('Unsupported subject: "%s".', $notification->getSubject()));
    }
    $notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath($this->appName, 'app-dark.svg')));
    $this->setParsedSubjectFromRichSubject($notification);
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
}
