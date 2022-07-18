<script>
/**
 * @copyright Copyright (c) 2022 Claus-Justus Heine <himself@claus-justus-heine.de>
 *
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 *
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
 *
 */
</script>
<template>
  <SettingsSection :class="appName" :title="t(appName, 'Recursive Pdf Downloader, Personal Settings')">
    <div class="flex-container flex-center">
      <input id="page-labels"
             v-model="pageLabels"
             type="checkbox"
             @change="saveSetting('pageLabels')"
      >
      <label for="page-labels">
        {{ t(appName, 'Label output pages with file-name and page-number') }}
      </label>
    </div>
    <!-- <SettingsInputText
      :id="'test-input'"
      v-model="example"
      :label="t(appName, 'Test Input')"
      :hint="t(appName, 'Test Hint')"
      @update="saveInputExample" /> -->
  </SettingsSection>
</template>

<script>
import { appName } from './config.js'
import SettingsSection from '@nextcloud/vue/dist/Components/SettingsSection'
import SettingsInputText from './components/SettingsInputText'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess, showInfo, TOAST_DEFAULT_TIMEOUT, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

export default {
  name: 'PersonalSettings',
  components: {
    SettingsSection,
    SettingsInputText,
  },
  data() {
    return {
      pageLabels: true,
      old: {},
    }
  },
  watch: {
    pageLabels(newValue, oldValue) {
      this.old.pageLabels = oldValue
    },
  },
  created() {
    this.getData()
  },
  methods: {
    async getData() {
      const response = await axios.get(generateUrl('apps/' + appName + '/settings/personal/pageLabels'), {})
      console.info('RESPONSE', response)
      this.pageLables = response.data.value
      console.info('VALUE', this.pageLables)
    },
    async saveSetting(setting) {
      console.info('SAVE SETTING', this[setting])
      const value = this[setting]
      const printValue = value === true ? t(appName, 'true') : '' + value;
      console.info('VALUE', value)
      try {
        const response = await axios.post(generateUrl('apps/' + appName + '/settings/personal/' + setting), { value })
        if (value) {
          showInfo(t(appName, 'Successfully set "{setting}" to {value}.', { setting, value: printValue }))
        } else {
          showInfo(t(appName, 'Setting "{setting}" has been unset successfully.', { setting }))
        }
      } catch (e) {
        console.info('RESPONSE', e)
        let message = t(appName, 'reason unknown')
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
        this[setting] = this.old[setting]
      }
    },
  },
}
</script>
<style lang="scss" scoped>
.settings-section {
  :deep(&__title) {
    padding-left:60px;
    background-image:url('../img/app.svg');
    background-repeat:no-repeat;
    background-origin:border-box;
    background-size:45px;
    background-position:left center;
    height:30px;
  }
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
  }
}
</style>
