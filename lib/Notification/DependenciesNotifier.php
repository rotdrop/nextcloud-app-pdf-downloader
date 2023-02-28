<?php
/**
 * Recursive PDF Downloader App for Nextcloud
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023 Claus-Justus Heine <himself@claus-justus-heine.de>
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

use OCA\PdfDownloader\Service\DependenciesService;

/**
 * Handle admin notifications concerning installation problems.
 */
class DependenciesNotifier implements INotifier
{
  use \OCA\PdfDownloader\Toolkit\Traits\LoggerTrait;

  public const REQUIRED_DEPENDENCIES = 'required dependencies';
  public const SUGGESTED_DEPENDENCIES = 'suggested dependencies';

  public const SUBJECTS = [
    DependenciesService::REQUIRED => self::REQUIRED_DEPENDENCIES,
    DependenciesService::SUGGESTED => self::SUGGESTED_DEPENDENCIES,
  ];

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
    return $this->appName . 'Installation';
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
      case self::REQUIRED_DEPENDENCIES:
        $parameters = $notification->getSubjectParameters();
        $numMissing = count($parameters[DependenciesService::MISSING]);
        $notification->setRichSubject(
          $l->n(
            'The app "{app}" will not work without installing the following required program: {programs}.',
            'The app "{app}" will not work without installing the following required programs: {programs}.',
            $numMissing), [
              'programs' => [
                'type' => 'highlight',
                'id' => $notification->getObjectId() . 'programs',
                'name' => implode(', ', $parameters[DependenciesService::MISSING]),
              ],
              'app' => [
                'type' => 'highlight',
                'id' => $notification->getObjectId() . 'app',
                'name' => $this->appName,
              ],
            ]);
        break;
      case self::SUGGESTED_DEPENDENCIES:
        $parameters = $notification->getSubjectParameters();
        $numMissing = count($parameters[DependenciesService::MISSING]);
        $notification->setRichSubject(
          $l->n(
            'The app "{app}" will work better if you install the following suggested helper program: {programs}.',
            'The app "{app}" will work better if you install the following suggested helper programs: {programs}.',
            $numMissing), [
              'programs' => [
                'type' => 'highlight',
                'id' => $notification->getObjectId() . 'programs',
                'name' => implode(', ', $parameters[DependenciesService::MISSING]),
              ],
              'app' => [
                'type' => 'highlight',
                'id' => $notification->getObjectId() . 'app',
                'name' => $this->appName,
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
      $replacements[] = $parameter['name'];
    }
    $notification->setParsedSubject(str_replace($placeholders, $replacements, $notification->getRichSubject()));
  }
}
