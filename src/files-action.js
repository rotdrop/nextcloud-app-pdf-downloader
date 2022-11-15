/**
 * @author Claus-Justus Heine
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

import { appName } from './config.js';
import { imagePath } from '@nextcloud/router';
import generateUrl from './toolkit/util/generate-url.js';
import fileDownload from './toolkit/util/file-download.js';
import { attachDialogHandlers } from './toolkit/util/dialogs.js';
import { getInitialState } from './toolkit/services/InitialStateService.js';

const initialState = getInitialState();

const mimeTypes = [
  'httpd/unix-directory',
];

// a menu entry in order to download a folder as multi-page pdf
const fileActionTemplate = {
  name: 'download-pdf',
  displayName: t(appName, 'Download PDF'),
  altText: t(appName, 'Download PDF'),
  // mime: 'httpd/unix-directory',
  // type: OCA.Files.FileActions.TYPE_DROPDOWN,
  // permissions: OC.PERMISSION_READ,
  // shouldRender(context) {}, is not invoked for TYPE_DROPDOWN
  icon() {
    return imagePath('core', 'filetypes/application-pdf');
  },
  // render(actionSpec, isDefault, context) {}, is not invoked for TYPE_DROPDOWN
  /**
   * Handle multi-page PDF download request. Stolen from the
   * files-app download action handler.
   *
   * @param {string} dirName TBD.
   * @param {object} context TBD.
   */
  actionHandler(dirName, context) {
    const fullPath = encodeURIComponent([
      context.fileList.dirInfo.path,
      context.fileList.dirInfo.name,
      dirName,
    ].join('/'));

    const url = generateUrl('download/pdf/{fullPath}', { fullPath });

    // $file is a jQuery object, change that if the files-app gets overhauled
    const downloadFileaction = context.$file.find('.fileactions .action-download-pdf');

    // don't allow a second click on the download action
    if (downloadFileaction.hasClass('disabled')) {
      return;
    }

    if (url) {
      const disableLoadingState = function() {
        context.fileList.showFileBusyState(dirName, false);
      };

      context.fileList.showFileBusyState(dirName, true);
      // OCA.Files.Files.handleDownload(url, disableLoadingState);
      fileDownload(url, false, { always: disableLoadingState });
    }
  },
};

window.addEventListener('DOMContentLoaded', () => {

  attachDialogHandlers();

  console.info('INITIAL STATE', initialState);

  if (OCA.Files && OCA.Files.fileActions) {
    const fileActions = OCA.Files.fileActions;

    if (initialState.extractArchiveFiles && initialState.extractArchiveFilesAdmin) {
      mimeTypes.splice(0, 0, ...initialState.archiveMimeTypes);
      console.info('MIME TYPES', mimeTypes);
    }

    fileActionTemplate.type = OCA.Files.FileActions.TYPE_DROPDOWN;
    fileActionTemplate.permissions = OC.PERMISSION_READ;
    for (const mimeType of mimeTypes) {
      const fileAction = Object.assign({ mime: mimeType }, fileActionTemplate);
      fileActions.registerAction(fileAction);
    }
  }
});
