/**
 * @author Claus-Justus Heine
 * @copyright 2022, 2023, 2024 Claus-Justus Heine <himself@claus-justus-heine.de>
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
import generateAppUrl from './toolkit/util/generate-url.js';
import fileDownload from './toolkit/util/file-download.js';
import { translate as t } from '@nextcloud/l10n';
import axios from '@nextcloud/axios';
import { registerFileAction, FileAction, Node, NodeStatus, Permission } from '@nextcloud/files';
import { showError, showSuccess, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs';
import { getInitialState } from './toolkit/services/InitialStateService.js';

import logoSvg from '../img/app.svg?raw';

require('./webpack-setup.js');

const initialState = getInitialState();

console.info('INITIAL STATE PDF DOWNLOADER', initialState);

const mimeTypes: Array<string> = [
  'httpd/unix-directory',
];

if (!initialState.individualFileConversion
    && initialState.extractArchiveFiles
    && initialState.extractArchiveFilesAdmin) {
  mimeTypes.splice(0, 0, ...initialState.archiveMimeTypes);
  console.info('MIME TYPES', mimeTypes);
}

registerFileAction(new FileAction({
  id: appName,
  displayName(/*nodes: Node[], view: View*/) {
    return t(appName, 'Download PDF');
  },
  title(/* files: Node[], view: View */) {
    return t(appName, 'Convert the entry into a PDF document.');
  },
  iconSvgInline(/* files: Node[], view: View) */) {
    return logoSvg;
  },
  enabled(nodes: Node[]/* , view: View) */) {
    if (nodes.length !== 1) {
      return false;
    }
    const node = nodes[0];
    if (!(node.permissions & Permission.READ)) {
      return false;
    }
    if (!initialState.individualFileConversion) {
      return node.mime !== undefined && mimeTypes.findIndex((mime) => mime === node.mime) >= 0;
    }
    return true;
  },
  async exec(node: Node/*, view: View, dir: string*/) {

    const fullPath = encodeURIComponent(node.path);

    if (initialState.useBackgroundJobsDefault) {
      const url = generateAppUrl('schedule/download/{fullPath}', { fullPath }, undefined);
      try {
        await axios.post(url);
        showSuccess(t(appName, 'Background PDF generation for {sourceFile} has been scheduled.', {
          sourceFile: fullPath,
        }));
      } catch (e: any) {
        console.error('ERROR', e);
        let message = t(appName, 'reason unknown');
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        showError(t(appName, 'Unable to schedule background PDF generation for {sourceFile}: {message}', {
          sourceFile: node.path,
          message,
        }), {
          timeout: TOAST_PERMANENT_TIMEOUT,
        });
      }
    } else {
      const url = generateAppUrl('download/{fullPath}', { fullPath }, undefined);
      await fileDownload(url, false, undefined);
    }
    return null;
  },
  inline: () => false,
  order: -1000000,
}));
