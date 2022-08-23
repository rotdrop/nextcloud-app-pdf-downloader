/**
 * @copyright Copyright (c) 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
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

import { appName } from '../config.js';
import { showError, showSuccess, showInfo, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs';
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

export default {
  methods: {
    async saveSimpleSetting(setting, settingsSection) {
      console.info('ARGUMENTS', setting, arguments);
      console.info('SAVE SETTING', setting, this[setting]);
      const value = this[setting];
      const printValue = value === true ? t(appName, 'true') : '' + value;
      console.info('VALUE', value);
      try {
        await axios.post(generateUrl('apps/' + appName + '/settings/' + settingsSection + '/' + setting), { value });
        if (value) {
          showInfo(t(appName, 'Successfully set "{setting}" to {value}.', { setting, value: printValue }));
        } else {
          showInfo(t(appName, 'Setting "{setting}" has been unset successfully.', { setting }));
        }
      } catch (e) {
        console.info('RESPONSE', e);
        let message = t(appName, 'reason unknown');
        if (e.response && e.response.data && e.response.data.message) {
          message = e.response.data.message;
        }
        if (value) {
          showError(t(appName, 'Unable to set "{setting}" to {value}: {message}.', {
            setting,
            value: printValue,
            message,
          }));
        } else {
          showError(t(appName, 'Unable to unset "{setting}": {message}', {
            setting,
            value: this[setting] || t(appName, 'false'),
            message,
          }));
        }
        this[setting] = this.old[setting];
      }
    },
    async saveConfirmedSetting(value, settingsSection, settingsKey, force) {
      const self = this;
      console.info('ARGS', arguments);
      try {
        const response = await axios.post(generateUrl('apps/' + appName + '/settings/' + settingsSection + '/' + settingsKey), { value, force });
        const responseData = response.data;
        if (responseData.status === 'unconfirmed') {
          OC.dialogs.confirm(
            responseData.feedback,
            t(appName, 'Confirmation Required'),
            function(answer) {
              if (answer) {
                self.saveTextInput(value, settingsKey, true);
              } else {
                showInfo(t(appName, 'Unconfirmed, reverting to old value.'));
                self.getData();
              }
            },
            true);
        } else {
          showSuccess(t(appName, 'Successfully set value for "{settingsKey}" to "{value}"', { settingsKey, value }));
        }
        console.info('RESPONSE', response);
      } catch (e) {
        let message = t(appName, 'reason unknown');
        if (e.response && e.response.data && e.response.data.message) {
          message = e.response.data.message;
          console.info('RESPONSE', e.response);
        }
        showError(t(appName, 'Could not set value for "{settingsKey}" to "{value}": {message}', { settingsKey, value, message }), { timeout: TOAST_PERMANENT_TIMEOUT });
        self.getData();
      }
    },
  },
};
