<script>
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
</script>
<template>
  <SettingsSection :class="appName" :title="t(appName, 'Recursive Pdf Downloader, Personal Settings')">
    <div :class="['flex-container', 'flex-center', { pageLabels }]">
      <input id="page-labels"
             v-model="pageLabels"
             type="checkbox"
             :disabled="loading"
             @change="saveSetting('pageLabels')"
      >
      <label for="page-labels">
        {{ t(appName, 'Label output pages with file-name and page-number') }}
      </label>
    </div>
    <span class="hint">
      {{ t(appName, 'Format of the page-label: BASENAME_CURRENT_FILE PAGE/FILE_PAGES') }}
    </span>
    <div class="page-label-font-select-container">
      <div class="label-container">
        <label>{{ t(appName, 'Font for generated PDF page-annotations') }}</label>
      </div>
      <div class="multiselect-wrapper">
        <MultiSelect id="page-label-font-select"
                     ref="pageLabelsFontSelect"
                     v-model="pageLabelsFontObject"
                     class="fonts-select multiselect-vue"
                     :placeholder="t(appName, 'Select a Font')"
                     :show-labels="true"
                     :allow-empty="true"
                     :searchable="true"
                     :options="fontsList"
                     :close-on-select="true"
                     track-by="family"
                     label="fontName"
                     :multiple="false"
                     :tag-width="60"
                     :disabled="!pageLabels || loading"
        >
          <template #option="optionData">
            <EllipsisedFontOption :name="$refs.pageLabelsFontSelect.getOptionLabel(optionData.option)"
                                  :option="optionData.option"
                                  :search="optionData.search"
                                  :label="$refs.pageLabelsFontSelect.label"
            />
          </template>
          <template #singleLabel="singleLabelData">
            <span v-tooltip="fontInfoPopup(singleLabelData.option)">
              {{ $refs.pageLabelsFontSelect.$refs.VueMultiselect.currentOptionLabel }}
            </span>
          </template>
        </MultiSelect>
        <div v-show="loading" class="loading" />
      </div>
      <span class="hint">
        {{ t(appName, 'The font to use for the page-labels: {pageLabelsFont}', { pageLabelsFont }) }}
      </span>
    </div>
    <div class="generated-page-font-select-container">
      <div class="label-container">
        <label>{{ t(appName, 'Font for generated PDF (error-)pages') }}</label>
      </div>
      <div class="multiselect-wrapper">
        <MultiSelect id="generated-page-font-select"
                     ref="generatedPagesFontSelect"
                     v-model="generatedPagesFontObject"
                     class="fonts-select multiselect-vue"
                     :placeholder="t(appName, 'Select a Font')"
                     :show-labels="true"
                     :allow-empty="true"
                     :searchable="true"
                     :options="fontsList"
                     :close-on-select="true"
                     track-by="family"
                     label="fontName"
                     :multiple="false"
                     :tag-width="60"
                     :disabled="loading"
        >
          <template #option="optionData">
            <EllipsisedFontOption :name="$refs.generatedPagesFontSelect.getOptionLabel(optionData.option)"
                                  :option="optionData.option"
                                  :search="optionData.search"
                                  :label="$refs.generatedPagesFontSelect.label"
            />
          </template>
          <template #singleLabel="singleLabelData">
            <span v-tooltip="fontInfoPopup(singleLabelData.option)">
              {{ $refs.generatedPagesFontSelect.$refs.VueMultiselect.currentOptionLabel }}
            </span>
          </template>
        </MultiSelect>
        <div v-show="loading" class="loading" />
      </div>
      <span class="hint">
        {{ t(appName, 'The font to use for generated pages: {generatedPagesFont}', { generatedPagesFont }) }}
      </span>
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
import MultiSelect from '@nextcloud/vue/dist/Components/Multiselect'
import EllipsisedFontOption from './components/EllipsisedFontOption'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess, showInfo, TOAST_DEFAULT_TIMEOUT, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import fontInfoPopup from './mixins/font-info-popup'
import settingsSync from './mixins/settings-sync'

export default {
  name: 'PersonalSettings',
  components: {
    SettingsSection,
    SettingsInputText,
    MultiSelect,
    EllipsisedFontOption,
  },
  data() {
    return {
      pageLabels: true,
      fontsList: [],
      loading: true,
      pageLabelsFont: '',
      pageLabelsFontObject: null,
      generatedPagesFont: '',
      generatedPagesFontObject: null,
      old: {
        pageLabelsFont: 'unset',
        generatedPagesFont: 'unset',
      }
    }
  },
  mixins: [
    fontInfoPopup,
    settingsSync,
  ],
  watch: {
    pageLabels(newValue, oldValue) {
      this.old.pageLabels = oldValue
    },
    pageLabelsFontObject(newValue, oldValue) {
      const skip = this.old.pageLabelsFont === 'unset'
      console.info('PAGE LABEL FONT', newValue, oldValue)
      this.old.pageLabelsFont = oldValue ? oldValue.family : null
      this.pageLabelsFont = newValue ? newValue.family : null
      this.old.pageLabelsFontObject = oldValue
      if (!skip) {
        this.saveSetting('pageLabelsFont')
      }
    },
    generatedPagesFontObject(newValue, oldValue) {
      const skip = this.old.generatedPagesFont === 'unset'
      console.info('GENERATED PAGES FONT', newValue, oldValue)
      this.old.generatedPagesFont = oldValue ? oldValue.family : null
      this.generatedPagesFont = newValue ? newValue.family : null
      this.old.generatedPagesFontObject = oldValue
      if (!skip) {
        this.saveSetting('generatedPagesFont')
      }
    },
  },
  created() {
    this.getData()
  },
  methods: {
    async getData() {
      const settings = ['pageLabels', 'pageLabelsFont', 'generatedPagesFont']
      for (const setting of settings) {
        this.fetchSetting(setting, 'personal')
      }
      try {
        const response = await axios.get(generateUrl('apps/' + appName + '/pdf/fonts'))
        this.fontsList = response.data
        console.info('FONTS', this.fontsList)
      } catch (e) {
        console.info('RESPONSE', e)
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data && e.response.data.message) {
          message = e.response.data.message;
        }
        showError(t(appName, 'Unable to obtain the list of available fonts: {message}', {
          message,
        }))
      }
      console.info('SETTINGS', this.pageLabelsFont)
      let fontIndex = this.fontsList.findIndex((x) => x.family === this.pageLabelsFont)
      this.pageLabelsFontObject = fontIndex >= 0 ? this.fontsList[fontIndex] : null
      fontIndex = this.fontsList.findIndex((x) => x.family === this.generatedPagesFont)
      this.generatedPagesFontObject = fontIndex >= 0 ? this.fontsList[fontIndex] : null
      this.loading = false
    },
    async saveSetting(setting) {
      this.saveSimpleSetting(setting, 'personal')
    },
  },
}
</script>
<style lang="scss" scoped>
.settings-section {
  :deep(.settings-section__title) {
    padding-left:60px;
    background-image:url('../img/app-dark.svg');
    background-repeat:no-repeat;
    background-origin:border-box;
    background-size:32px;
    background-position:left center;
    height:32px;
  }
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
  }
  .label-container {
    height:34px;
    display:flex;
    align-items:center;
    justify-content:left;
  }
  .multiselect-wrapper {
    position:relative;
    .loading {
      position:absolute;
      width:0;
      height:0;
      top:50%;
      left:50%;
    }
    display: flex;
    flex-wrap: wrap;
    width: 100%;
    max-width: 400px;
    align-items: center;
    :deep(div.multiselect.multiselect-vue.multiselect--single) {
      height:34px !important;
      flex-grow:1;
      &:hover .multiselect__tags {
        border-color: var(--color-primary-element);
        outline: none;
      }
     .multiselect__content-wrapper li > span {
        &::before {
          background-image: var(--icon-checkmark-000);
          display:block;
        }
        &:not(.multiselect__option--selected):hover::before {
          visibility:hidden;
        }
      }
    }
  }
  .hint {
    color: var(--color-text-lighter);
    font-size: 80%;
  }
}
</style>
