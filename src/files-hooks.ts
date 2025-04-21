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
import generateAppUrl from './toolkit/util/generate-url.ts';
import fileDownload from './toolkit/util/axios-file-download.ts';
import { fileInfoToNode } from './toolkit/util/file-node-helper.ts';
import { translate as t } from '@nextcloud/l10n';
import { emit, subscribe } from '@nextcloud/event-bus';
import type { NotificationEvent } from './toolkit/types/event-bus.d.ts';
import axios from '@nextcloud/axios';
import { registerFileAction, FileAction, Node, Permission } from '@nextcloud/files';
import { showError, showSuccess, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs';
import getInitialState from './toolkit/util/initial-state.ts';
import { isAxiosErrorResponse } from './toolkit/types/axios-type-guards.ts';
import logoSvg from '../img/app.svg?raw';
import logger from './logger.ts';
import type { InitialState } from './types/initial-state.d.ts';

require('./webpack-setup.ts');

const initialState = getInitialState<InitialState>();

logger.info('INITIAL STATE PDF DOWNLOADER', initialState);

const mimeTypes: Array<string> = [
  'httpd/unix-directory',
];

if (!initialState?.individualFileConversion
    && initialState?.extractArchiveFiles
    && initialState?.extractArchiveFilesAdmin) {
  mimeTypes.splice(0, 0, ...(initialState?.archiveMimeTypes || []));
  logger.info('MIME TYPES', mimeTypes);
}

subscribe('notifications:notification:received', (event: NotificationEvent) => {
  if (event?.notification?.app !== appName) {
    return;
  }
  const destinationData = event.notification?.subjectRichParameters?.destination;
  if (!destinationData) {
    return;
  }
  if (destinationData.status !== 'filesystem') {
    logger.info('*** PDF generation notification received, but not for cloud filesystem.');
    return;
  }
  if (!destinationData.file) {
    logger.info('*** PDF generation notification received, but carries no file information.');
    return;
  }
  const node = fileInfoToNode(destinationData.file);

  emit('files:node:created', node);
});

registerFileAction(new FileAction({
  id: appName,
  displayName(/* nodes: Node[], view: View */) {
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
    if (!initialState?.individualFileConversion) {
      return node.mime !== undefined && mimeTypes.findIndex((mime) => mime === node.mime) >= 0;
    }
    return true;
  },
  async exec(node: Node/* , view: View, dir: string */) {

    const fileId = node.fileid || null;

    if (initialState?.useBackgroundJobsDefault) {
      const url = generateAppUrl('schedule/download/{fileId}', { fileId });
      try {
        await axios.post(url);
        showSuccess(t(appName, 'Background PDF generation for {sourceFile} has been scheduled.', {
          sourceFile: node.path,
        }));
      } catch (e) {
        logger.error('ERROR', e);
        let message = t(appName, 'reason unknown');
        if (isAxiosErrorResponse(e) && e.response.data) {
          const responseData = e.response.data as { messages?: string[] };
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
      const url = generateAppUrl('download/{fileId}', { fileId });
      try {
        await fileDownload(url);
      } catch (e) {
        let message = '';
        if (isAxiosErrorResponse(e) && e.response.data) {
          const responseData = e.response.data as { messages?: string[] };
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        const errorMessage = message
          ? t(appName, 'Download of {fileName} failed: {message}.', { fileName: node.path, message })
          : t(appName, 'Download of {fileName} failed.', { fileName: node.path });
        showError(errorMessage, { timeout: TOAST_PERMANENT_TIMEOUT });
      }
    }
    return null;
  },
  inline: () => false,
}));
