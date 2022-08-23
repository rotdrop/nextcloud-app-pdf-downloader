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
  <SettingsSection :title="t(appName, 'Recursive Pdf Downloader, Admin Settings')">
    <div :class="['flex-container', 'flex-center']">
      <input id="disable-builtin-converters"
             v-model="disableBuiltinConverters"
             type="checkbox"
             :disabled="loading"
             @change="saveSetting('disableBuiltinConverters')"
      >
      <label for="disable-builtin-converters">
        {{ t(appName, 'Disable the builtin-converters.') }}
      </label>
    </div>
    <SettingsInputText
      v-model="universalConverter"
      :label="t(appName, 'Universal Converter')"
      :hint="t(appName, 'Full path to a filter-program to be executed first for all files. If it fails, the other converters will be tried in turn.')"
      :disabled="loading"
      @update="saveTextInput(...arguments, 'universalConverter')"
    />
    <SettingsInputText
      v-model="fallbackConverter"
      :label="t(appName, 'Fallback Converter')"
      :hint="t(appName, 'Full path to a filter-program to be run when all other filters have failed. If it fails an error page will be substituted for the failing document.')"
      :disabled="loading"
      @update="saveTextInput(...arguments, 'fallbackConverter')"
    />
    <div class="converter-status">
      <div><label>{{ t(appName, 'Status of the Builtin-Converters') }}</label></div>
      <dl>
        <template v-for="(value, mimeType) in converters">
          <dt :key="`dt-${ mimeType }`">
            {{ mimeType }}
          </dt>
          <dd :key="`dd-${ mimeType }`">
            <ul>
              <li v-for="(items, index) in value" :key="index">
                <span v-for="(executable, converter) in items" :key="converter">
                  {{ converter }}: {{ executable }}
                </span>
              </li>
            </ul>
          </dd>
        </template>
      </dl>
    </div>
  </SettingsSection>
</template>

<script>
import { appName } from './config.js'
import SettingsSection from '@nextcloud/vue/dist/Components/SettingsSection'
import SettingsInputText from './components/SettingsInputText'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess, showInfo, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import saveSettings from './mixins/save-settings.js'

export default {
  name: 'AdminSettings',
  components: {
    SettingsSection,
    SettingsInputText,
  },
  data() {
    return {
      disableBuiltinConverters: false,
      universalConverter: '',
      fallbackConverter: '',
      converters: {},
      loading: true,
    }
  },
  mixins: [
    saveSettings,
  ],
  created() {
    this.getData()
  },
  computed: {
  },
  methods: {
    async getData() {
      const settings = ['disableBuiltinConverters', 'universalConverter', 'fallbackConverter', 'converters']
      for (const setting of settings) {
        try {
          let response = await axios.get(generateUrl('apps/' + appName + '/settings/admin/' + setting), {})
          this[setting] = response.data.value
          console.info('SETTING', setting, this[setting])
        } catch (e) {
          console.error('ERROR', e)
          let message = t(appName, 'reason unknown')
          if (e.response && e.response.data && e.response.data.message) {
            message = e.response.data.message;
          }
          showError(t(appName, 'Unable to query the initial value of "{setting}": {message}', {
            setting,
            message,
          }))
        }
      }
      this.loading = false
    },
    async saveTextInput(value, settingsKey, force) {
      this.saveConfirmedSetting(value, 'admin', settingsKey, force)
    },
    async saveSetting(setting) {
      this.saveSimpleSetting(setting, 'admin')
    },
  },
}
</script>
<style lang="scss" scoped>
.settings-section {
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
  }
  :deep(&__title) {
    padding-left:60px;
    background-image:url('../img/app.svg');
    background-repeat:no-repeat;
    background-origin:border-box;
    background-size:45px;
    background-position:left center;
    height:30px;
  }
}
</style>
