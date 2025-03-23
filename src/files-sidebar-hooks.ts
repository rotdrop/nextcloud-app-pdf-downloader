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
import getInitialState from './toolkit/util/initial-state.ts';
import type { LegacyFileInfo } from '@nextcloud/files';
import type { InitialState } from './types/initial-state.d.ts';

// eslint-disable-next-line
import logoSvg from '../img/app.svg?raw';

require('./webpack-setup.ts');

interface FilesTab extends Vue {
   update(fileInfo: LegacyFileInfo): Promise<unknown>,
}

let TabInstance: undefined|FilesTab = undefined;

const initialState = getInitialState<InitialState>();

const mimeTypes = [
  'httpd/unix-directory',
];

if (!initialState?.individualFileConversion
    && initialState?.extractArchiveFiles
    && initialState?.extractArchiveFilesAdmin) {
  mimeTypes.splice(0, 0, ...(initialState?.archiveMimeTypes || []));
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

      enabled(fileInfo: LegacyFileInfo) {
        return initialState?.individualFileConversion || mimeTypes.indexOf(fileInfo.mimetype) >= 0;
      },

      async mount<VueType extends Vue>(el: HTMLElement, fileInfo: LegacyFileInfo, context: VueType) {
        const FilesTabAsset = (await import('./files-tab.ts'));
        const factory = FilesTabAsset.default;

        if (TabInstance) {
          TabInstance.$destroy();
        }

        TabInstance = factory(context);

        // Only mount after we have all the info we need
        await TabInstance.update(fileInfo);
        TabInstance.$mount(el);
      },
      update(fileInfo: LegacyFileInfo) {
        TabInstance!.update(fileInfo);
      },
      destroy() {
        if (TabInstance !== undefined) {
          TabInstance.$destroy();
        }
        TabInstance = undefined;
      },
    }));
  }
});
