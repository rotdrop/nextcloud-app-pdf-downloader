/**
 * @author Claus-Justus Heine
 * @copyright 2022, 2023, 2024, 2025 Claus-Justus Heine <himself@claus-justus-heine.de>
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

import { appName } from './config.ts';
import { translate as t } from '@nextcloud/l10n';
import { getInitialState } from './toolkit/services/InitialStateService.js';

// eslint-disable-next-line
import logoSvg from '../img/app.svg?raw';

require('./webpack-setup.ts');

let TabInstance = null;

const initialState = getInitialState();

const mimeTypes = [
  'httpd/unix-directory',
];

if (!initialState.individualFileConversion
    && initialState.extractArchiveFiles
    && initialState.extractArchiveFilesAdmin) {
  mimeTypes.splice(0, 0, ...initialState.archiveMimeTypes);
}

window.addEventListener('DOMContentLoaded', () => {

  /**
   * Register a new tab in the sidebar
   */
  if (OCA.Files && OCA.Files.Sidebar) {
    OCA.Files.Sidebar.registerTab(new OCA.Files.Sidebar.Tab({
      id: appName,
      name: t(appName, 'PDF'),
      iconSvg: logoSvg,

      enabled(fileInfo) {
        return initialState.individualFileConversion || mimeTypes.indexOf(fileInfo.mimetype) >= 0;
      },

      async mount(el, fileInfo, context) {
        const FilesTabAsset = (await import('./views/FilesTab.vue'));
        const Vue = FilesTabAsset.Vue;
        const FilesTab = FilesTabAsset.default;
        const View = Vue.extend(FilesTab);

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
        TabInstance.update(fileInfo);
      },
      destroy() {
        if (TabInstance !== null) {
          TabInstance.$destroy();
        }
        TabInstance = null;
      },
    }));
  }
});
