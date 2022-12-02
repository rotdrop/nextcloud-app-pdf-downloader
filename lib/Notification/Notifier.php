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

use Psr\Log\LoggerInterface as ILogger;
use OCP\IURLGenerator;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * Background PDF generator job in order to move time-consuming jobs out of
 * reach of the web-server limits.
 */
class Notifier implements INotifier
{
  use \OCA\RotDrop\Toolkit\Traits\LoggerTrait;

  /** @var IL10NFactory */
  protected $l10nFactory;

  /** @var IURLGenerator */
  protected $urlGenerator;

  // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
  public function __construct(
    ILogger $logger,
    IL10NFactory $l10nFactory,
    IURLGenerator $urlGenerator,
  ) {
    $this->logger = $logger;
  }
  // phpcs:enable

  // public function getID(): string
  // {
  //   return 'files_zip';
  // }

  // public function getName(): string {
  //   return $this->factory->get('files_zip')->t('Zipper');
  // }

  // public function prepare(INotification $notification, string $languageCode): INotification {
  //   if ($notification->getApp() !== 'files_zip') {
  //     throw new InvalidArgumentException('Application should be files_zip instead of ' . $notification->getApp());
  //   }

  //   $l = $this->factory->get('files_zip', $languageCode);

  //   switch ($notification->getSubject()) {
  //     case self::TYPE_SCHEDULED:
  //       $parameters = $notification->getSubjectParameters();
  //       $notification->setRichSubject($l->t('A Zip archive {target} will be created.'), [
  //         'target' => [
  //           'type' => 'highlight',
  //           'id' => $notification->getObjectId(),
  //           'name' => $parameters['target-name'],
  //         ]
  //       ]);
  //       break;
  //     case self::TYPE_SUCCESS:
  //       $parameters = $notification->getSubjectParameters();
  //       $notification->setRichSubject($l->t('Your files have been stored as a Zip archive in {path}.'), [
  //         'path' => [
  //           'type' => 'file',
  //           'id' => $parameters['fileid'],
  //           'name' => $parameters['name'],
  //           'path' => $parameters['path']
  //         ]
  //       ]);
  //       break;
  //     case self::TYPE_FAILURE:
  //       $parameters = $notification->getSubjectParameters();
  //       $notification->setRichSubject($l->t('Creating the Zip file {path} failed.'), [
  //         'path' => [
  //           'type' => 'highlight',
  //           'id' => $notification->getObjectId(),
  //           'name' => basename($parameters['target']),
  //         ]
  //       ]);
  //       break;
  //     default:
  //       throw new InvalidArgumentException();
  //   }
  //   $notification->setIcon($this->url->getAbsoluteURL($this->url->imagePath('files_zip', 'files_zip-dark.svg')));
  //   $this->setParsedSubjectFromRichSubject($notification);
  //   return $notification;
  // }

  // protected function setParsedSubjectFromRichSubject(INotification $notification): void {
  //   $placeholders = $replacements = [];
  //   foreach ($notification->getRichSubjectParameters() as $placeholder => $parameter) {
  //     $placeholders[] = '{' . $placeholder . '}';
  //     if ($parameter['type'] === 'file') {
  //       $replacements[] = $parameter['path'];
  //     } else {
  //       $replacements[] = $parameter['name'];
  //     }
  //   }

  //   $notification->setParsedSubject(str_replace($placeholders, $replacements, $notification->getRichSubject()));
  // }
}
