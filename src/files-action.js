/**
 * @author Claus-Justus Heine
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

import Vue from 'vue';
import { appName } from './config.js';
import generateAppUrl from './toolkit/util/generate-url.js';
import fileDownload from './toolkit/util/file-download.js';
import { attachDialogHandlers } from './toolkit/util/dialogs.js';
import { generateFilePath, imagePath, generateUrl } from '@nextcloud/router';
import axios from '@nextcloud/axios';
import { showError, showSuccess, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs';
import { getInitialState } from './toolkit/services/InitialStateService.js';
import FilesTab from './views/FilesTab.vue';
import { Tooltip } from '@nextcloud/vue';

require('dialogs.scss');
require('pdf-downloader.scss');

Vue.directive('tooltip', Tooltip);

// eslint-disable-next-line
__webpack_public_path__ = generateFilePath(appName, '', 'js');
Vue.mixin({ data() { return { appName }; }, methods: { t, n, generateUrl } });

const View = Vue.extend(FilesTab);
let TabInstance = null;

const initialState = getInitialState();

console.info('INITIAL STATE PDF DOWNLOADER', initialState);

const mimeTypes = [
  'httpd/unix-directory',
];

if (!initialState.singlePlainFileConversion
    && initialState.extractArchiveFiles
    && initialState.extractArchiveFilesAdmin) {
  mimeTypes.splice(0, 0, ...initialState.archiveMimeTypes);
  console.info('MIME TYPES', mimeTypes);
}

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
  async actionHandler(dirName, context) {
    // $file is a jQuery object, change that if the files-app gets overhauled
    const downloadFileaction = context.$file.find('.fileactions .action-download-pdf');

    // don't allow a second click on the download action
    if (downloadFileaction.hasClass('disabled')) {
      return;
    }

    const fullPath = encodeURIComponent([
      context.fileList.dirInfo.path,
      context.fileList.dirInfo.name,
      dirName,
    ].join('/'));

    const disableLoadingState = function() {
      context.fileList.showFileBusyState(dirName, false);
    };
    context.fileList.showFileBusyState(dirName, true);
    if (initialState.useBackgroundJobsDefault) {
      const url = generateAppUrl('schedule/download/{fullPath}', { fullPath });
      try {
        await axios.post(url);
        showSuccess(t(appName, 'Background PDF generation for {sourceFile} has been scheduled.', {
          sourceFile: fullPath,
        }));
      } catch (e) {
        console.error('ERROR', e);
        let message = t(appName, 'reason unknown');
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        showError(t(appName, 'Unable to schedule background PDF generation for {sourceFile}: {message}', {
          sourceFile: this.sourcePath,
          message,
        }), {
          timeout: TOAST_PERMANENT_TIMEOUT,
        });
      }
      disableLoadingState();
    } else {
      const url = generateAppUrl('download/{fullPath}', { fullPath });
      fileDownload(url, false, { always: disableLoadingState });
    }
  },
};

window.addEventListener('DOMContentLoaded', () => {

  attachDialogHandlers();

  console.info('INITIAL STATE', initialState);

  /**
   * Register a new tab in the sidebar
   */
  if (OCA.Files && OCA.Files.Sidebar) {
    OCA.Files.Sidebar.registerTab(new OCA.Files.Sidebar.Tab({
      id: appName,
      name: t(appName, 'PDF'),
      icon: 'icon-pdf-downloader',

      enabled(fileInfo) {
        return initialState.singlePlainFileConversion || mimeTypes.indexOf(fileInfo.mimetype) >= 0;
      },

      async mount(el, fileInfo, context) {

        if (TabInstance) {
          TabInstance.$destroy();
        }

        TabInstance = new View({
          // Better integration with vue parent component
          parent: context,
        });

        // Only mount after we have all the info we need
        await TabInstance.update(fileInfo);

        TabInstance.$mount(el);
      },
      update(fileInfo) {
        console.info('ARGUMENTS', arguments);
        TabInstance.update(fileInfo);
      },
      destroy() {
        TabInstance.$destroy();
        TabInstance = null;
      },
    }));
  }

  if (OCA.Files && OCA.Files.fileActions) {
    const fileActions = OCA.Files.fileActions;

    fileActionTemplate.type = OCA.Files.FileActions.TYPE_DROPDOWN;
    fileActionTemplate.permissions = OC.PERMISSION_READ;
    if (!initialState.singlePlainFileConversion) {
      for (const mimeType of mimeTypes) {
        const fileAction = Object.assign({ mime: mimeType }, fileActionTemplate);
        fileActions.registerAction(fileAction);
      }
    } else {
      const fileAction = Object.assign({ mime: 'all' }, fileActionTemplate);
      fileActions.registerAction(fileAction);
    }
  }
});
