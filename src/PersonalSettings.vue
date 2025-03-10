<!--
 - @copyright Copyright (c) 2022-2025 Claus-Justus Heine <himself@claus-justus-heine.de>
 - @author Claus-Justus Heine <himself@claus-justus-heine.de>
 - @license AGPL-3.0-or-later
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program. If not, see <http://www.gnu.org/licenses/>.
 -->
<template>
  <div :class="['templateroot', appName, ...cloudVersionClasses]">
    <h1 class="title">
      {{ t(appName, 'Recursive PDF Downloader, Personal Settings') }}
    </h1>
    <NcSettingsSection id="decorations-and-fonts"
                       :name="t(appName, 'Decorations and Fonts')"
    >
      <div :class="['flex-container', 'flex-center', { pageLabels }]">
        <input id="page-labels"
               v-model="pageLabels"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('pageLabels')"
        >
        <label for="page-labels">
          {{ t(appName, 'Label output pages with filename and page number') }}
        </label>
      </div>
      <div v-show="pageLabels" class="horizontal-rule" />
      <!-- avoid v-model here as the update of pageLabelTemplate causes instant font-sample generation -->
      <!-- v-tooltip="unclippedPopup(pageLabelTemplate)" -->
      <TextField v-show="pageLabels"
                 :value="pageLabelTemplate"
                 :label="t(appName, 'Template for the page labels')"
                 :disabled="loading > 0"
                 @submit="(value) => { pageLabelTemplate = value; saveSetting('pageLabelTemplate'); }"
      >
        <template #hint>
          <div class="template-example-container flex-container flex-baseline">
            <span class="template-example-caption">
              {{ t(appName, 'Given Filename Example') }}:
            </span>
            <span class="template-example-file-path">
              {{ exampleFilePath }}
            </span>
          </div>
          <div class="template-example-container flex-container flex-center">
            <span class="template-example-caption">
              {{ t(appName, 'Generated Label') }}
            </span>
            <span v-if="pageLabelTemplateFontSampleUri !== ''" class="template-example-caption">
              {{ t(appName, 'as Image') }}:
            </span>
            <span :class="['template-example-rendered', { 'set-minimum-height': !!pageLabelPageWidthFraction }]"
                  :style="{ 'background-color': pageLabelBackgroundColor }"
            >
              <img :src="pageLabelTemplateFontSampleUri"
                   :style="{ filter: pageLabelTemplateFontSampleFilter }"
              >
            </span>
            <span v-if="pageLabelTemplateExample !== ''" class="template-example-caption">
              {{ t(appName, 'as Text') }}:
            </span>
            <span class="template-example-plain-text"
                  :style="{ 'background-color': pageLabelBackgroundColor, 'color': pageLabelTextColor, 'font-style': 'normal' }"
            >
              {{ pageLabelTemplateExample }}
            </span>
          </div>
        </template>
      </TextField>
      <div v-show="pageLabels" class="horizontal-rule" />
      <div v-show="pageLabels" class="page-label-colors flex-container flex-center">
        <div class="label">
          {{ t(appName, 'Page label colors') }}:
        </div>
        <ColorPicker ref="pageLabelTextColorPicker"
                     v-model="pageLabelTextColor"
                     :label="t(appName, 'Text')"
                     :color-palette="pageLabelTextColorPalette"
                     @update="saveSetting('pageLabelTextColor')"
                     @update:color-palette="(palette) => { pageLabelTextColorPalette = palette; saveSetting('pageLabelTextColorPalette'); }"
        />
        <ColorPicker ref="pageLabelBackgroundColorPicker"
                     v-model="pageLabelBackgroundColor"
                     :label="t(appName, 'Background')"
                     :color-palette="pageLabelBackgroundColorPalette"
                     @update="saveSetting('pageLabelBackgroundColor')"
                     @update:color-palette="(palette) => { pageLabelBackgroundColorPalette = palette; saveSetting('pageLabelBackgroundColorPalette'); }"
        />
      </div>
      <div v-show="pageLabels" class="horizontal-rule" />
      <TextField :value.sync="pageLabelPageWidthFraction"
                 :label="t(appName, 'Page label width fraction')"
                 :hint="t(appName, 'Page label width as decimal fraction of the page width. Leave empty to use a fixed font size.')"
                 :disabled="loading > 0 || !pageLabels"
                 type="number"
                 :placeholder="t(appName, 'e.g. 0.4')"
                 min="0.01"
                 max="1.00"
                 step="0.01"
                 dir="rtl"
                 @submit="(value) => saveTextInput('pageLabelPageWidthFraction', value)"
      />
      <div v-show="pageLabels" class="horizontal-rule" />
      <FontSelect v-show="pageLabels"
                  ref="pageLabelsFontSelect"
                  v-model="pageLabelsFontObject"
                  :placeholder="t(appName, 'Select a Font')"
                  :fonts-list="fontsList"
                  :label="t(appName, 'Font for generated PDF page annotations')"
                  :hint="t(appName, 'The font to use for the page labels: {pageLabelsFont}', { pageLabelsFont })"
                  :disabled="!pageLabels || loading > 0"
                  :loading="loading > 0"
                  :font-size-chooser="!pageLabelPageWidthFraction"
      />
      <div class="horizontal-rule" />
      <div :class="['flex-container', 'flex-center', { generateErrorPages: 'generate-error-pages' }]">
        <input id="generate-error-pages"
               v-model="generateErrorPages"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('generateErrorPages')"
        >
        <label for="generate-error-pages">
          {{ t(appName, 'Generate a Placeholder-Page for Failed Conversions') }}
        </label>
      </div>
      <div v-show="generateErrorPages" class="horizontal-rule" />
      <FontSelect v-show="generateErrorPages"
                  v-model="generatedPagesFontObject"
                  :placeholder="t(appName, 'Select a Font')"
                  :fonts-list="fontsList"
                  :label="t(appName, 'Font for generated PDF (error)pages')"
                  :hint="t(appName, 'The font to use for generated pages: {generatedPagesFont}', { generatedPagesFont })"
                  :disabled="loading > 0"
                  :loading="loading > 0"
      />
    </NcSettingsSection>
    <NcSettingsSection id="sorting-options"
                       :name="t(appName, 'Sorting Options')"
    >
      <div :class="['flex-container', 'flex-center']">
        <span :class="['radio-option', 'grouping-option', 'flex-container', 'flex-center']">
          <input id="group-folders-first"
                 v-model="grouping"
                 type="radio"
                 value="folders-first"
                 :disabled="loading > 0"
                 @change="saveSetting('grouping')"
          >
          <label for="group-folders-first">
            {{ t(appName, 'Group Folders First') }}
          </label>
        </span>
        <span :class="['radio-option', 'grouping-option', 'flex-container', 'flex-center']">
          <input id="group-files-first"
                 v-model="grouping"
                 type="radio"
                 value="files-first"
                 :disabled="loading > 0"
                 @change="saveSetting('grouping')"
          >
          <label for="group-files-first">
            {{ t(appName, 'Group Files First') }}
          </label>
        </span>
        <span v-if="false" :class="['radio-option', 'grouping-option', 'flex-container', 'flex-center']">
          <input id="group-ungrouped"
                 v-model="grouping"
                 type="radio"
                 value="ungrouped"
                 :disabled="loading > 0"
                 @change="saveSetting('grouping')"
          >
          <label for="group-ungrouped">
            {{ t(appName, 'Do Not Group') }}
          </label>
        </span>
      </div>
    </NcSettingsSection>
    <NcSettingsSection id="filename-patterns"
                       :name="t(appName, 'Filename Patterns')"
    >
      <TextField :value.sync="excludePattern"
                 :label="t(appName, 'Exclude Pattern')"
                 :disabled="loading > 0"
                 @submit="(value) => saveTextInput('excludePattern', value)"
      />
      <TextField :value.sync="includePattern"
                 :label="t(appName, 'Include Pattern')"
                 :disabled="loading > 0"
                 @submit="(value) => saveTextInput('includePattern', value)"
      />
      <div :class="['flex-container', 'flex-center']">
        <span :class="['radio-option', 'label']">{{ t(appName, 'Precedence:') }}</span>
        <span :class="['radio-option', 'include-exclude', 'flex-container', 'flex-center']">
          <input id="include-has-precedence"
                 v-model="patternPrecedence"
                 type="radio"
                 value="includeHasPrecedence"
                 :selected="patternPrecedence === 'includeHasPrecedence'"
                 :disabled="loading > 0 || (!includePattern && !!excludePattern)"
                 @change="saveSetting('patternPrecedence')"
          >
          <label for="include-has-precedence">
            {{ t(appName, 'Include Pattern') }}
          </label>
        </span>
        <span :class="['radio-option', 'include-exclude', 'flex-container', 'flex-center']">
          <input id="exclude-has-precedence"
                 v-model="patternPrecedence"
                 type="radio"
                 value="excludeHasPrecedence"
                 :selected="patternPrecedence === 'excludeHasPrecedence'"
                 :disabled="loading > 0 || !excludePattern"
                 @change="saveSetting('patternPrecedence')"
          >
          <label for="exclude-has-precedence">
            {{ t(appName, 'Exclude Pattern') }}
          </label>
        </span>
      </div>
      <TextField v-model="patternTestString"
                 :label="t(appName, 'Test String')"
                 @submit="(value) => saveTextInput('patternTestString', value)"
      >
        <template #hint>
          <div class="pattern-test-result flex-container flex-baseline">
            <span class="pattern-test-caption label">
              {{ t(appName, 'Test Result:') }}
            </span>
            <span :class="['pattern-test-result', patternTestResult]">
              {{ l10nPatternTestResult }}
            </span>
          </div>
        </template>
      </TextField>
    </NcSettingsSection>
    <NcSettingsSection id="default-download-options"
                       :name="t(appName, 'Default Download Options')"
    >
      <TextField :value="pdfFileNameTemplate"
                 :label="t(appName, 'PDF Filename Template:')"
                 @submit="(value) => { pdfFileNameTemplate = value; saveSetting('pdfFileNameTemplate', value); }"
      >
        <template #hint>
          <div class="template-example-container flex-container flex-baseline">
            <span class="template-example-caption">
              {{ t(appName, 'Given Folder Example') }}:
            </span>
            <span class="template-example-file-path">
              {{ exampleFilePathParent }}
            </span>
          </div>
          <div class="template-example-container flex-container flex-baseline">
            <span class="template-example-caption">
              {{ t(appName, 'Generated Filename') }}:
            </span>
            <span class="template-example-pdf-filename">
              {{ pdfFileNameTemplateExample }}
            </span>
          </div>
        </template>
      </TextField>
      <div class="horizontal-rule" />
      <!-- Here we should use the ordinary file-picker, the prefix picker does not make any sense here. -->
      <FilePrefixPicker v-model="pdfCloudFolderFileInfo"
                        :hint="t(appName, `Optionally choose a default destination folder in the
cloud. If left blank PDFs will be generated in the current directory.`)"
                        :only-dir-name="true"
                        @update="saveTextInput('pdfCloudFolderPath')"
      />
      <div class="horizontal-rule" />
      <div :class="['flex-container', 'flex-center']">
        <input id="use-background-jobs-default"
               v-model="useBackgroundJobsDefault"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('useBackgroundJobsDefault')"
        >
        <label v-tooltip="tooltips.useBackgroundJobsDefault"
               for="use-background-jobs-default"
        >
          {{ t(appName, 'Generate PDFs in the background by default.') }}
        </label>
      </div>
      <!-- <div :class="['flex-container', 'flex-center']">
        <input id="authenticated-background-jobs"
               v-model="authenticatedBackgroundJobs"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('authenticatedBackgroundJobs')"
        >
        <label v-tooltip="tooltips.authenticatedBackgroundJobs"
               for="authenticated-background-jobs"
        >
          {{ t(appName, 'Use authenticated background jobs if necessary.') }}
        </label>
      </div> -->
      <div class="horizontal-rule" />
      <TextField :value.sync="humanDownloadsPurgeTimeout"
                 :label="t(appName, 'Purge Timeout')"
                 :hint="t(appName, 'For how long to keep the offline generated PDF files. After this time they will eventually be deleted by a background job.')"
                 :disabled="loading > 0"
                 @submit="(value) => saveTextInput('downloadsPurgeTimeout', value)"
      />
    </NcSettingsSection>
    <NcSettingsSection id="archive-extraction"
                       :name="t(appName, 'Archive Extraction')"
    >
      <div :class="['flex-container', 'flex-center', { extractArchiveFiles: extractArchiveFiles }]">
        <input id="extract-archive-files"
               v-model="extractArchiveFiles"
               type="checkbox"
               :disabled="loading > 0 || !extractArchiveFilesAdmin"
               @change="saveSetting('extractArchiveFiles')"
        >
        <label v-if="extractArchiveFilesAdmin" for="extract-archive-files">
          {{ t(appName, 'On-the-fly extraction of archive files.') }}
        </label>
        <label v-else for="extract-archive-files">
          {{ t(appName, 'On-the-fly extraction of archive files is disabled by the administrator.') }}
        </label>
      </div>
      <TextField v-show="extractArchiveFiles && extractArchiveFilesAdmin"
                 :value.sync="humanArchiveSizeLimit"
                 :label="t(appName, 'Archive Size Limit')"
                 :hint="t(appName, 'Disallow archive extraction for archives with decompressed size larger than this limit.')"
                 :disabled="loading > 0 || !extractArchiveFiles || !extractArchiveFilesAdmin"
                 @submit="(value) => saveTextInput('archiveSizeLimit', value)"
      />
      <div v-if="extractArchiveFiles && extractArchiveFilesAdmin && archiveSizeLimitAdmin > 0" :class="{ hint: true, 'admin-limit-exceeded': archiveSizeLimitAdmin < archiveSizeLimit, 'icon-error': archiveSizeLimitAdmin < archiveSizeLimit }">
        {{ t(appName, 'Administrative size limit: {value}', { value: humanArchiveSizeLimitAdmin }) }}
      </div>
    </NcSettingsSection>
    <NcSettingsSection id="individual-conversion-title"
                       :name="l10nStrings.individualFileConversionTitle"
    >
      <div :class="['flex-container', 'flex-center', { individualFileConversion: individualFileConversion }]">
        <input id="individual-file-conversion"
               v-model="individualFileConversion"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('individualFileConversion')"
        >
        <label for="individual-file-conversion">
          {{ l10nStrings.individualFileConversionLabel }}
        </label>
      </div>
      <ul>
        <li class="hint">
          {{ t(appName, 'The actions menu entry will then also appear for non-archive files.') }}
        </li>
        <li class="hint">
          {{ t(appName, 'PDF files will also be decorated with page labels if page decoration is enabled.') }}
        </li>
        <li class="hint">
          {{ t(appName, 'The directory part of the page labels will remain empty.') }}
        </li>
      </ul>
    </NcSettingsSection>
  </div>
</template>
<script lang="ts">
import { appName } from './config.ts'
import { set as vueSet } from 'vue'
import {
  NcSettingsSection,
} from '@nextcloud/vue'
import TextField from '@rotdrop/nextcloud-vue-components/lib/components/TextFieldWithSubmitButton.vue'
import ColorPicker from '@rotdrop/nextcloud-vue-components/lib/components/ColorPickerExtension.vue'
import FilePrefixPicker from '@rotdrop/nextcloud-vue-components/lib/components/FilePrefixPicker.vue'
import FontSelect from './components/FontSelect.vue'
import { generateUrl as generateAppUrl } from './toolkit/util/generate-url.js'
import fontSampleText from './toolkit/util/pangram.js'
import { getInitialState } from './toolkit/services/InitialStateService.js'
import {
  showError,
  // showSuccess,
  showInfo,
  // TOAST_DEFAULT_TIMEOUT,
  // TOAST_PERMANENT_TIMEOUT,
} from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { parse as pathParse } from 'path'
import settingsSync from './toolkit/mixins/settings-sync.js'
import unclippedPopup from './components/mixins/unclipped-popup.js'
import { hexToCSSFilter } from 'hex-to-css-filter'
import cloudVersionClasses from './toolkit/util/cloud-version-classes.js'

const initialState = getInitialState()

export default {
  name: 'PersonalSettings',
  components: {
    ColorPicker,
    FilePrefixPicker,
    FontSelect,
    NcSettingsSection,
    TextField,
  },
  mixins: [
    settingsSync,
    unclippedPopup,
  ],
  data() {
    return {
      initialState,
      cloudVersionClasses,
      grouping: 'folders-first',
      sorting: 'ascending',
      includePattern: null,
      excludePattern: null,
      patternPrecedence: 'includeHasPrecedence',
      patternTestString: null,
      patternTestResult: null,
      fontsList: [],
      fontSamples: [],
      // TRANSLATORS: This should be a pangram (see https://en.wikipedia.org/wiki/Pangram) in the target language.
      fontSampleText,
      loading: 1,
      //
      pageLabels: true,
      pageLabelTemplate: initialState.defaultPageLabelTemplate,
      pageLabelTextColor: '#ff0000',
      pageLabelTextColorPalette: [],
      pageLabelBackgroundColor: '#c8c8c8',
      pageLabelBackgroundColorPalette: [],
      pageLabelPageWidthFraction: 0.4,
      pageLabelsFont: '',
      pageLabelsFontSize: 12,
      pageLabelsFontObject: null,
      pageLabelTemplateExample: '',
      //
      generateErrorPages: true,
      generatedPagesFont: '',
      generatedPagesFontSize: 12,
      generatedPagesFontObject: null,
      old: {
        pageLabelsFont: 'unset',
        pageLabelsFontSize: 'unset',
        pageLabelsFontObject: 'unset',
        generatedPagesFont: 'unset',
        generatedPagesFontSize: 'unset',
        generatedPagesFontObject: 'unset',
      },
      extractArchiveFiles: false,
      archiveSizeLimit: null,
      humanArchiveSizeLimit: '',
      extractArchiveFilesAdmin: false,
      archiveSizeLimitAdmin: null,
      humanArchiveSizeLimitAdmin: '',
      individualFileConversion: true,
      sampleFontSize: 18, // should be pt, but actually is rendered as px it seems
      pdfCloudFolderFileInfo: {
        dirName: '',
        baseName: undefined,
      },
      pdfFileNameTemplate: initialState.defaultPdfFileNameTemplate,
      pdfFileNameTemplateExample: null,
      //
      useBackgroundJobsDefault: false,
      authenticatedBackgroundJobs: false,
      humanDownloadsPurgeTimeout: '1 week',
      downloadsPurgeTimeout: 24 * 3600 * 7,
      //
      exampleFilePath: t(appName, 'invoices/2022/october/invoice.fodt'),
      //
      tooltips: {
        useBackgroundJobsDefault: t(appName, 'If checked default to background PDF generation. This can be overridden by navigating to the PDF panel in the details sidebar for each particular source folder or archive file.'),
        authenticatedBackgroundJobs: t(appName, 'If unsure keep this disabled. Enabling this option leads to an additional directory scan prior to scheduling a background operation. If the scan detects a mount point in the directory which has been mounted with the "authenticated" mount option then your login credentials will be temporarily promoted to the background job. This is primarily used to handle special cases which should only concern the author of this package. Keep the option disabled unless you really know what it means and you really known that you need it.'),
      },
      // TRANSLATORS comments seemingly cannot be placed in HTML section of Vue code, so:
      l10nStrings: {
        // TRANSLATORS: This is the heading for a configuration option
        // TRANSLATORS: which enables PDF-conversion of individual
        // TRANSLATORS: files in addition to converting entire
        // TRANSLATORS: directory trees or archive files.
        individualFileConversionTitle: t(appName, 'Individual File Conversion'),
        // TRANSLATORS: Title of an option to enable the conversion of
        // TRANSLATORS: individual files to PDF in addition to be able
        // TRANSLATORS: to convert entire folder hierarchies or
        // TRANSLATORS: file-collections contained in archive
        // TRANSLATORS: files.
        individualFileConversionLabel: t(appName, 'Enable conversion of individual files in addition to folders and archives.'),
      },
    }
  },
  computed: {
    pageLabelTemplateFontSampleUri() {
      if (!this.pageLabelsFontObject) {
        return ''
      }
      const text = this.pageLabelTemplateExample
      return this.$refs.pageLabelsFontSelect.getFontSampleUri(this.pageLabelsFontObject, {
        text,
        textColor: '#000000',
        fontSize: this.pageLabelPageWidthFraction ? undefined : this.pageLabelsFontSize,
      })
    },
    pageLabelTemplateFontSampleFilter() {
      const targetRgbColor = this.pageLabelTextColor
      const cssFilter = hexToCSSFilter(targetRgbColor)
      return cssFilter.filter.replace(/;$/g, '')
    },
    pdfCloudFolderPath: {
      get() {
        return this.pdfCloudFolderFileInfo.dirName
      },
      set(value) {
        vueSet(this.pdfCloudFolderFileInfo, 'dirName', value || '/')
        return value
      },
    },
    exampleFilePathParent() {
      const pathInfo = pathParse(this.exampleFilePath || '')
      return pathInfo.dir + '/'
    },
    l10nPatternTestResult() {
      switch (this.patternTestResult) {
      case 'included':
        return t(appName, 'included')
      case 'excluded':
        return t(appName, 'excluded')
      default:
        return ''
      }
    },
  },
  watch: {
    pageLabels(newValue, oldValue) {
      this.old.pageLabels = oldValue
    },
    pageLabelsFontObject(newValue, oldValue) {
      this.fontObjectWatcher('pageLabels', newValue, oldValue)
    },
    generatedPagesFontObject(newValue, oldValue) {
      this.fontObjectWatcher('generatedPages', newValue, oldValue)
    },
    pageLabelTemplate(_newValue, _oldValue) {
      this.fetchPageLabelTemplateExample()
    },
    pdfFileNameTemplate(_newValue, _oldValue) {
      this.fetchPdfFileNameTemplateExample()
    },
    pageLabelTextColor(newValue, oldValue) {
      console.info('TEXT COLOR', newValue, oldValue)
    },
    pageLabelBackgroundColor(newValue, oldValue) {
      console.info('BACKGROUND', newValue, oldValue)
    },
    includePattern(_newValue, _oldValue) {
      this.sanitizePatternPrecedence()
    },
    excludePattern(_newValue, _oldValue) {
      this.sanitizePatternPrecedence()
    },
  },
  created() {
    this.getData()
  },
  mounted() {
    if (!this.loading) {
      this.$refs.pageLabelTextColorPicker.saveState()
      this.$refs.pageLabelBackgroundColorPicker.saveState()
    }
  },
  methods: {
    info(...rest) {
      console.info(...rest)
    },
    async getData() {
      // slurp in all personal settings
      ++this.loading
      const settingsPromise = this.fetchSettings('personal')
      settingsPromise.finally(() => {
        if (this.$refs.pageLabelTextColorPicker) {
          this.$refs.pageLabelTextColorPicker.saveState()
          this.$refs.pageLabelBackgroundColorPicker.saveState()
        }
        --this.loading
      })
      ++this.loading
      const fontsPromise = axios.get(generateAppUrl('fonts'))
      fontsPromise.catch((e) => {
        console.info('RESPONSE', e)
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ')
          }
        }
        showError(t(appName, 'Unable to obtain the list of available fonts: {message}', {
          message,
        }))
      }).finally(() => {
        --this.loading
      })

      Promise.all([settingsPromise, fontsPromise]).then(
        (responses) => {
          const response = responses[1]
          this.fontsList = response.data
          let fontIndex = this.fontsList.findIndex((x) => x.family === this.pageLabelsFont)
          this.pageLabelsFontObject = fontIndex >= 0 ? { ...this.fontsList[fontIndex] } : null
          if (this.pageLabelsFontObject) {
            vueSet(this.pageLabelsFontObject, 'fontSize', this.pageLabelsFontSize)
          }
          fontIndex = this.fontsList.findIndex((x) => x.family === this.generatedPagesFont)
          this.generatedPagesFontObject = fontIndex >= 0 ? { ...this.fontsList[fontIndex] } : null
          if (this.generatedPagesFontObject) {
            vueSet(this.generatedPagesFontObject, 'fontSize', this.generatedPagesFontSize)
          }
        })

      ++this.loading
      this.fetchPageLabelTemplateExample().finally(() => {
        --this.loading
      })
      ++this.loading
      this.fetchPdfFileNameTemplateExample().finally(() => {
        --this.loading
      })
      --this.loading
    },
    updatePatternTestResult(responseData) {
      if (responseData?.patternTestResult) {
        this.patternTestResult = responseData.patternTestResult
        showInfo(t(appName, 'Include/exclude test result for "{string}" is "{result}".', {
          string: this.patternTestString,
          result: this.l10nPatternTestResult,
        }))
      }
    },
    // make sure that the pattern precedence has an "expected" value
    // if any or either of the include/exclude patterns is not set.
    sanitizePatternPrecedence() {
      let forcedPrecedence = this.patternPrecedence
      if (!this.includePattern && !this.excludePattern) {
        forcedPrecedence = 'includeHasPrecedence'
      } else if (!this.includePattern) {
        forcedPrecedence = 'excludeHasPrecedence'
      } else if (!this.excludePattern) {
        forcedPrecedence = 'includeHasPrecedence'
      }
      if (forcedPrecedence !== this.patternPrecedence) {
        this.patternPrecedence = forcedPrecedence
        this.saveSetting('patternPrecedence')
      }
    },
    async saveTextInput(settingsKey, value, force) {
      if (value === undefined) {
        value = this[settingsKey] || ''
      }
      if (this.loading > 0) {
        // avoid ping-pong by reactivity
        console.info('SKIPPING SETTINGS-SAVE DURING LOAD', settingsKey, value)
        return
      }
      this.saveConfirmedSetting(value, 'personal', settingsKey, force, this.updatePatternTestResult)
    },
    async saveSetting(setting) {
      if (this.loading > 0) {
        // avoid ping-pong by reactivity
        console.info('SKIPPING SETTINGS-SAVE DURING LOAD', setting)
        return
      }
      this.saveSimpleSetting(setting, 'personal', this.updatePatternTestResult)
    },
    fontObjectWatcher(fontType, newValue, oldValue) {
      // track the font-object by family and font-size
      if (newValue && oldValue
          && newValue.family === oldValue.family
          && newValue.fontSize === oldValue.fontSize) {
        return
      }
      const fontKey = fontType + 'Font'
      const sizeKey = fontType + 'FontSize'
      const objectKey = fontType + 'FontObject'
      const skipSave = this.loading || this.old[fontKey] === 'unset' || this.old[sizeKey] === 'unset'
      this.old[fontKey] = oldValue ? oldValue.family : null
      this.old[sizeKey] = oldValue ? oldValue.fontSize : null
      this.old[objectKey] = oldValue
      this[fontKey] = newValue ? newValue.family : null
      this[sizeKey] = newValue ? newValue.fontSize : null
      if (!skipSave) {
        if (this[fontKey] !== this.old[fontKey]) {
          this.saveSetting(fontKey)
        }
        if (this[sizeKey] !== this.old[sizeKey]) {
          this.saveSetting(sizeKey)
        }
      }
    },
    async fetchPageLabelTemplateExample() {
      try {
        const response = await axios.get(generateAppUrl(
          'sample/page-label/{template}/{path}/{pageNumber}/{totalPages}', {
            template: encodeURIComponent(this.pageLabelTemplate),
            path: encodeURIComponent(this.exampleFilePath),
            dirPageNumber: 13,
            dirTotalPages: 197,
            filePageNumber: 3,
            fileTotalPages: 17,
          }))
        this.pageLabelTemplateExample = response.data.pageLabel
      } catch (e) {
        console.info('RESPONSE', e)
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ')
          }
        }
        showError(t(appName, 'Unable to obtain page label template example: {message}', {
          message,
        }))
        // can't help, just return the unsubstituted template
        this.pageLabelTemplateExample = this.pageLabelTemplate
      }
    },
    async fetchPdfFileNameTemplateExample() {
      try {
        const response = await axios.get(generateAppUrl(
          'sample/pdf-filename/{template}/{path}', {
            template: encodeURIComponent(this.pdfFileNameTemplate),
            path: encodeURIComponent(this.exampleFilePathParent),
          }))
        this.pdfFileNameTemplateExample = response.data.pdfFileName
      } catch (e) {
        console.info('RESPONSE', e)
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ')
          }
        }
        showError(t(appName, 'Unable to obtain the PDF file template example: {message}', {
          message,
        }))
        // can't help, just return the unsubstituted template
        this.pdfFileNameTemplateExample = this.pdfFileNameTemplate
      }
    },
  },
}
</script>
<style lang="scss">
[csstag="vue-tooltip-unclipped-popup"].v-popper--theme-tooltip {
  .v-popper__inner {
    max-width:unset!important;
  }
}
</style>
<style lang="scss" scoped>
.cloud-version {
  --cloud-icon-info: var(--icon-info-dark);
  --cloud-icon-checkmark: var(--icon-checkmark-dark);
  --cloud-icon-alert: var(--icon-alert-outline-dark);
  --cloud-theme-filter: var(--background-invert-if-dark);
  &.cloud-version-major-24 {
    --cloud-icon-info: var(--icon-info-000);
    --cloud-icon-checkmark: var(--icon-checkmark-000);
    --cloud-icon-alert: var(--icon-alert-outline-000);
    --cloud-theme-filter: none;
  }
}
.templateroot::v-deep {
  h1.title {
    margin: 30px 30px 0px;
    font-size:revert;
    font-weight:revert;
    position: relative;
    padding-left:60px;
    height:32px;
    &::before {
      content: "";
      position: absolute;
      left: 0;
      top: 0;
      width: 32px;
      height: 32px;
      background-size:32px;
      background-image:url('../img/app-dark.svg');
      background-repeat:no-repeat;
      background-origin:border-box;
      background-position:left center;
      filter: var(--cloud-theme-filter);
    }
  }
  .horizontal-rule {
    opacity: 0.1;
    border-top: black 1px solid;
    margin-top: 2px;
    padding-top: 2px;
  }
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
    &.flex-baseline {
      align-items:baseline;
    }
  }
  .label-container {
    height:34px;
    display:flex;
    align-items:center;
    justify-content:left;
  }
  .radio-option {
    padding-right: 0.5em;
  }
  .label {
    padding-right: 0.5em;
  }
  .pattern-test-result {
    span.pattern-test-result {
      padding-right: 20px;
      background-position: right;
      background-repeat: no-repeat;
      &.excluded {
        color: red;
        background-image: var(--cloud-icon-alert);
      }
      &.included {
        color: green;
        background-image: var(--cloud-icon-checkmark);
      }
    }
  }
  .template-example-container {
    .template-example-rendered {
      display:flex;
      margin-right: 0.5em;
      color: red; // same as PdfCombiner
      background: #C8C8C8; // same as PdfCombiner
      &.set-minimum-height {
        img {
          min-height: var(--default-line-height);
        }
      }
    }
    .template-example-plain-text {
      padding: 0 0.3em;
      font-style: normal;
    }
    .template-example-caption {
      padding-right:0.5em;
    }
    .template-example-file-path {
      font-family:monospace;
      font-style: normal;
    }
    .template-example-pdf-filename {
      font-family:monospace;
    }
  }
  p.hint {
    color: var(--color-text-lighter);
    font-style: italic;
  }
  li, div {
    &.hint {
      color: var(--color-text-lighter);
      font-size: 80%;
      &.admin-limit-exceeded {
        color:red;
        font-weight:bold;
        font-style:italic;
        &.icon-error {
          padding-left:20px;
          background-position:left;
        }
      }
    }
  }
  label.has-tooltip {
    padding-right: 16px;
    background-image: var(--cloud-icon-info);
    background-size: 12px;
    background-position: right center;
    background-repeat: no-repeat;
  }
}
</style>
