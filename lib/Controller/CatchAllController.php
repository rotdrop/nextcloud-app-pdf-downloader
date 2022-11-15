<?php
/**
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

use OCA\PdfDownloader\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IL10N;

/**
 * Catch-all controller in order to generate usable error messages.
 */
class CatchAllController extends Controller
{
  use \OCA\RotDrop\Toolkit\Traits\ResponseTrait;

  /** @var IL10N */
  private $l;

  /**
   * @param string $appName
   *
   * @param IRequest $request
   *
   * @param IL10N $l10n
   */
  public function __construct(
    string $appName,
    IRequest $request,
    IL10N $l10n,
  ) {
    parent::__construct($this->appName, $request);
    $this->l = $l10n;
  }

  // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag
  /**
   * @SuppressWarnings(PHPMD.ShortVariable)
   * @NoAdminRequired
   * @NoCSRFRequired
   *
   * @return Response
   */
  public function post($a, $b, $c, $d, $e, $f, $g):Response
  {
    $parts = [ $a, $b, $c, $d, $e, $f, $g ];
    $request = implode('/', array_filter($parts));
    if (!empty($request)) {
      return self::grumble(
        $this->l->t('Post to end-point "%s" not implemented.', $request));
    } else {
      return self::grumble(
        $this->l->t('Post to base-url of app "%s" not allowed.', $this->appName()));
    }
  }

  // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag
  /**
   * @SuppressWarnings(PHPMD.ShortVariable)
   * @NoAdminRequired
   * @NoCSRFRequired
   *
   * @return Response
   */
  public function get($a, $b, $c, $d, $e, $f, $g):Response
  {
    $parts = [ $a, $b, $c, $d, $e, $f, $g ];
    $request = implode('/', array_filter($parts));
    return self::grumble(
      $this->l->t('Get from end-point "%s" not implemented.', $request));
  }
}
