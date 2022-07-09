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
  <SettingsSection :title="t(appName, 'Recursive Pdf Downloader, Personal Settings')">
    <SettingsInputText
      :id="'test-input'"
      v-model="example"
      :label="t(appName, 'Test Input')"
      :hint="t(appName, 'Test Hint')"
      @update="saveInputExample" />
  </SettingsSection>
</template>

<script>
import { appName } from './config.js'
import SettingsSection from '@nextcloud/vue/dist/Components/SettingsSection'
import SettingsInputText from './components/SettingsInputText'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
  name: 'PersonalSettings',
  components: {
    SettingsSection,
    SettingsInputText,
  },
  data() {
    return {
      example: '',
    }
  },
  created() {
    this.getData()
  },
  methods: {
    async getData() {
      const response = await axios.get(generateUrl('apps/' + appName + '/settings/personal/example'), {})
      console.info('RESPONSE', response)
      this.example = response.data.value
      console.info('VALUE', this.example)
    },
    async saveInputExample() {
      console.info('SAVE INPUTTEST', this.example)
      const response = await axios.post(generateUrl('apps/' + appName + '/settings/personal/example'), { value: this.example })
      console.info('RESPONSE', response)
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
  }
</style>
