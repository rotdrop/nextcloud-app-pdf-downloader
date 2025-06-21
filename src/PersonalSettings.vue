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
      <div :class="['flex-container', 'flex-center', { pageLabels: settings.pageLabels }]">
        <input id="page-labels"
               v-model="settings.pageLabels"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('pageLabels')"
        >
        <label for="page-labels">
          {{ t(appName, 'Label output pages with filename and page number') }}
        </label>
      </div>
      <div v-show="settings.pageLabels" class="horizontal-rule" />
      <!-- avoid v-model here as the update of pageLabelTemplate causes instant font-sample generation -->
      <!-- v-tooltip="unclippedPopup(settings.pageLabelTemplate)" -->
      <TextField v-show="settings.pageLabels"
                 :value="settings.pageLabelTemplate"
                 :label="t(appName, 'Template for the page labels')"
                 :disabled="loading > 0"
                 @submit="(value) => { settings.pageLabelTemplate = value; saveSetting('pageLabelTemplate'); }"
      />
      <div v-show="settings.pageLabels" class="page-label-hints">
        <div class="template-example-container flex-container flex-baseline">
          <span class="template-example-caption">
            {{ t(appName, 'Given Filename Example') }}:
          </span>
          <span class="template-example-file-path">
            {{ settings.exampleFilePath }}
          </span>
        </div>
        <div class="template-example-container flex-container flex-center">
          <span class="template-example-caption">
            {{ t(appName, 'Generated Label') }}
          </span>
          <span v-if="pageLabelTemplateFontSampleUri !== ''" class="template-example-caption">
            {{ t(appName, 'as Image') }}:
          </span>
          <span :class="['template-example-rendered', { 'set-minimum-height': !!settings.pageLabelPageWidthFraction }]"
                :style="{ 'background-color': settings.pageLabelBackgroundColor }"
          >
            <img :src="pageLabelTemplateFontSampleUri"
                 :style="{ filter: pageLabelTemplateFontSampleFilter }"
            >
          </span>
          <span v-if="pageLabelTemplateExample !== ''" class="template-example-caption">
            {{ t(appName, 'as Text') }}:
          </span>
          <span class="template-example-plain-text"
                :style="{ 'background-color': settings.pageLabelBackgroundColor, 'color': settings.pageLabelTextColor, 'font-style': 'normal' }"
          >
            {{ pageLabelTemplateExample }}
          </span>
        </div>
      </div>
      <div v-show="settings.pageLabels" class="horizontal-rule" />
      <div v-show="settings.pageLabels" class="page-label-colors flex-container flex-center">
        <div class="label">
          {{ t(appName, 'Page label colors') }}:
        </div>
        <ColorPicker ref="pageLabelTextColorPicker"
                     v-model="settings.pageLabelTextColor"
                     :label="t(appName, 'Text')"
                     :color-palette="settings.pageLabelTextColorPalette"
                     :advanced-fields="true"
                     @submit="saveSetting('pageLabelTextColor')"
                     @update:color-palette="(palette) => { settings.pageLabelTextColorPalette = palette; saveSetting('pageLabelTextColorPalette'); }"
        />
        <ColorPicker ref="pageLabelBackgroundColorPicker"
                     v-model="settings.pageLabelBackgroundColor"
                     :label="t(appName, 'Background')"
                     :color-palette="settings.pageLabelBackgroundColorPalette"
                     :advanced-fields="true"
                     @submit="saveSetting('pageLabelBackgroundColor')"
                     @update:color-palette="(palette) => { settings.pageLabelBackgroundColorPalette = palette; saveSetting('pageLabelBackgroundColorPalette'); }"
        />
      </div>
      <div v-show="settings.pageLabels" class="horizontal-rule" />
      <TextField :value.sync="settings.pageLabelPageWidthFraction"
                 :label="t(appName, 'Page label width fraction')"
                 :helper-text="t(appName, 'Page label width as decimal fraction of the page width. Leave empty to use a fixed font size.')"
                 :disabled="loading > 0 || !settings.pageLabels"
                 type="number"
                 :placeholder="t(appName, 'e.g. 0.4')"
                 min="0.01"
                 max="1.00"
                 step="0.01"
                 dir="rtl"
                 @submit="(value) => saveTextInput('pageLabelPageWidthFraction', value)"
      />
      <div v-show="settings.pageLabels" class="horizontal-rule" />
      <FontSelect v-show="settings.pageLabels"
                  ref="pageLabelsFontSelect"
                  v-model="pageLabelsFontObject"
                  :placeholder="t(appName, 'Select a Font')"
                  :fonts-list="fontsList"
                  :label="t(appName, 'Font for generated PDF page annotations')"
                  :hint="t(appName, 'The font to use for the page labels: {font}', { font: settings.pageLabelsFont })"
                  :disabled="!settings.pageLabels || loading > 0"
                  :loading="loading > 0"
                  :font-size-chooser="!settings.pageLabelPageWidthFraction"
      />
      <div class="horizontal-rule" />
      <div :class="['flex-container', 'flex-center', { generateErrorPages: 'generate-error-pages' }]">
        <input id="generate-error-pages"
               v-model="settings.generateErrorPages"
               type="checkbox"
               :disabled="loading > 0"
               @change="saveSetting('generateErrorPages')"
        >
        <label for="generate-error-pages">
          {{ t(appName, 'Generate a Placeholder-Page for Failed Conversions') }}
        </label>
      </div>
      <div v-show="settings.generateErrorPages" class="horizontal-rule" />
      <FontSelect v-show="settings.generateErrorPages"
                  v-model="generatedPagesFontObject"
                  :placeholder="t(appName, 'Select a Font')"
                  :fonts-list="fontsList"
                  :label="t(appName, 'Font for generated PDF (error)pages')"
                  :hint="t(appName, 'The font to use for generated pages: {font}', { font: settings.generatedPagesFont })"
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
                 v-model="settings.grouping"
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
                 v-model="settings.grouping"
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
                 v-model="settings.grouping"
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
      <TextField v-model="settings.excludePattern"
                 :label="t(appName, 'Exclude Pattern')"
                 :disabled="loading > 0"
                 @submit="(value) => saveTextInput('excludePattern', value)"
      />
      <TextField v-model="settings.includePattern"
                 :label="t(appName, 'Include Pattern')"
                 :disabled="loading > 0"
                 @submit="(value) => saveTextInput('includePattern', value)"
      />
      <div :class="['flex-container', 'flex-center']">
        <span :class="['radio-option', 'label']">{{ t(appName, 'Precedence:') }}</span>
        <span :class="['radio-option', 'include-exclude', 'flex-container', 'flex-center']">
          <input id="include-has-precedence"
                 v-model="settings.patternPrecedence"
                 type="radio"
                 value="includeHasPrecedence"
                 :selected="settings.patternPrecedence === 'includeHasPrecedence'"
                 :disabled="loading > 0 || (!settings.includePattern && !!settings.excludePattern)"
                 @change="saveSetting('patternPrecedence')"
          >
          <label for="include-has-precedence">
            {{ t(appName, 'Include Pattern') }}
          </label>
        </span>
        <span :class="['radio-option', 'include-exclude', 'flex-container', 'flex-center']">
          <input id="exclude-has-precedence"
                 v-model="settings.patternPrecedence"
                 type="radio"
                 value="excludeHasPrecedence"
                 :selected="settings.patternPrecedence === 'excludeHasPrecedence'"
                 :disabled="loading > 0 || !settings.excludePattern"
                 @change="saveSetting('patternPrecedence')"
          >
          <label for="exclude-has-precedence">
            {{ t(appName, 'Exclude Pattern') }}
          </label>
        </span>
      </div>
      <TextField v-model="settings.patternTestString"
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
      <TextField :value="settings.pdfFileNameTemplate"
                 :label="t(appName, 'PDF Filename Template:')"
                 @submit="(value) => { settings.pdfFileNameTemplate = value; saveSetting('pdfFileNameTemplate'); }"
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
                        :hint="t(appName, 'Optionally choose a default destination folder in the cloud. If left blank PDFs will be generated in the current directory.')"
                        :only-dir-name="true"
      />
      <div class="horizontal-rule" />
      <div :class="['flex-container', 'flex-center']">
        <input id="use-background-jobs-default"
               v-model="settings.useBackgroundJobsDefault"
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
      <TextField v-model="settings.humanDownloadsPurgeTimeout"
                 :label="t(appName, 'Purge Timeout')"
                 :helper-text="t(appName, 'For how long to keep the offline generated PDF files. After this time they will eventually be deleted by a background job.')"
                 :disabled="loading > 0"
                 @submit="(value) => saveTextInput('downloadsPurgeTimeout', value)"
      />
    </NcSettingsSection>
    <NcSettingsSection id="archive-extraction"
                       :name="t(appName, 'Archive Extraction')"
    >
      <div :class="['flex-container', 'flex-center', { extractArchiveFiles: settings.extractArchiveFiles }]">
        <input id="extract-archive-files"
               v-model="settings.extractArchiveFiles"
               type="checkbox"
               :disabled="loading > 0 || !settings.extractArchiveFilesAdmin"
               @change="saveSetting('extractArchiveFiles')"
        >
        <label v-if="settings.extractArchiveFilesAdmin" for="extract-archive-files">
          {{ t(appName, 'On-the-fly extraction of archive files.') }}
        </label>
        <label v-else for="extract-archive-files">
          {{ t(appName, 'On-the-fly extraction of archive files is disabled by the administrator.') }}
        </label>
      </div>
      <TextField v-show="settings.extractArchiveFiles && settings.extractArchiveFilesAdmin"
                 v-model="settings.humanArchiveSizeLimit"
                 :label="t(appName, 'Archive Size Limit')"
                 :helper-text="t(appName, 'Disallow archive extraction for archives with decompressed size larger than this limit.')"
                 :disabled="loading > 0 || !settings.extractArchiveFiles || !settings.extractArchiveFilesAdmin"
                 @submit="(value) => saveTextInput('archiveSizeLimit', value)"
      />
      <div v-if="settings.extractArchiveFiles && settings.extractArchiveFilesAdmin && lt(0, settings.archiveSizeLimitAdmin)"
           :class="{
             hint: true,
             'admin-limit-exceeded': lt(settings.archiveSizeLimitAdmin, settings.archiveSizeLimit),
             'icon-error': lt(settings.archiveSizeLimitAdmin, settings.archiveSizeLimit),
           }"
      >
        {{ t(appName, 'Administrative size limit: {value}', { value: settings.humanArchiveSizeLimitAdmin }) }}
      </div>
    </NcSettingsSection>
    <NcSettingsSection id="individual-conversion-title"
                       :name="l10nStrings.individualFileConversionTitle"
    >
      <div :class="['flex-container', 'flex-center', { individualFileConversion: settings.individualFileConversion }]">
        <input id="individual-file-conversion"
               v-model="settings.individualFileConversion"
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
<script setup lang="ts">
import { appName } from './config.ts'
import {
  watch,
  computed,
  ref,
  set as vueSet,
  reactive,
  onMounted,
} from 'vue'
import {
  NcSettingsSection,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import TextField from '@rotdrop/nextcloud-vue-components/lib/components/TextFieldWithSubmitButton.vue'
import ColorPicker from '@rotdrop/nextcloud-vue-components/lib/components/ColorPickerExtension.vue'
import FilePrefixPicker from '@rotdrop/nextcloud-vue-components/lib/components/FilePrefixPicker.vue'
import FontSelect from './components/FontSelect.vue'
import { generateUrl as generateAppUrl } from './toolkit/util/generate-url.ts'
import getInitialState from './toolkit/util/initial-state.ts'
import {
  showError,
  // showSuccess,
  showInfo,
  // TOAST_DEFAULT_TIMEOUT,
  // TOAST_PERMANENT_TIMEOUT,
} from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { parse as pathParse } from 'path'
import { hexToCSSFilter } from 'hex-to-css-filter'
import cloudVersionClassesImport from './toolkit/util/cloud-version-classes.js'
import {
  fetchSettings,
  saveConfirmedSetting,
  saveSimpleSetting,
} from './toolkit/util/settings-sync.ts'
import logger from './logger.ts'
import type { FontDescriptor } from './model/fonts.d.ts'
// import type { AxiosResponse } from 'axios'

interface PersonalSettingsInitialState {
  defaultPageLabelTemplate: string,
  defaultPdfFileNameTemplate: string,
}

const initialState = getInitialState<PersonalSettingsInitialState>()

const loading = ref(0)
const cloudVersionClasses = computed(() => cloudVersionClassesImport)

const settings = reactive({
  pageLabels: true,
  pageLabelTemplate: initialState?.defaultPageLabelTemplate || '',
  pageLabelTextColor: '#ff0000',
  pageLabelTextColorPalette: [],
  pageLabelBackgroundColor: '#c8c8c8',
  pageLabelBackgroundColorPalette: [],
  pageLabelPageWidthFraction: 0.4,
  pageLabelsFont: '',
  pageLabelsFontSize: 12,
  //
  grouping: 'folders-first' as 'folders-first'|'files-first'|'ungrouped',
  //
  includePattern: null as null|string,
  excludePattern: null as null|string,
  patternPrecedence: 'includeHasPrecedence' as 'includeHasPrecedence'|'excludeHasPrecedence',
  patternTestString: null as null|string,
  //
  fontSamples: [] as string[],
  //
  generateErrorPages: true,
  generatedPagesFont: '',
  generatedPagesFontSize: 12,
  extractArchiveFiles: false,
  archiveSizeLimit: null,
  humanArchiveSizeLimit: '',
  extractArchiveFilesAdmin: false,
  archiveSizeLimitAdmin: null,
  humanArchiveSizeLimitAdmin: '',
  individualFileConversion: true,
  sampleFontSize: 18, // should be pt, but actually is rendered as px it seems
  pdfFileNameTemplate: initialState?.defaultPdfFileNameTemplate || '',
  //
  useBackgroundJobsDefault: false,
  authenticatedBackgroundJobs: false,
  humanDownloadsPurgeTimeout: '1 week',
  downloadsPurgeTimeout: 24 * 3600 * 7,
  //
  exampleFilePath: t(appName, 'invoices/2022/october/invoice.fodt'),
  //
  pdfCloudFolderPath: undefined as undefined|string,
})

watch(() => settings.pageLabelTextColor, (value, oldValue) => {
  logger.info('PAGE LABEL TEXT COLOR', { value, oldValue })
})

watch(() => settings.pageLabelBackgroundColor, (value, oldValue) => {
  logger.info('PAGE LABEL BG COLOR', { value, oldValue })
})

const oldSettings: {
  pageLabels?: boolean,
  pageLabelsFont?: string,
  pageLabelsFontSize?: number,
  pageLabelsFontObject?: FontDescriptor,
  generatedPagesFont?: string,
  generatedPagesFontSize?: number,
  generatedPagesFontObject?: FontDescriptor
} = {}

const fontsList = ref<FontDescriptor[]>([])
const pageLabelsFontObject = ref<FontDescriptor|undefined>(undefined)
const generatedPagesFontObject = ref<FontDescriptor|undefined>(undefined)

const pageLabelTemplateExample = ref('')
const pdfFileNameTemplateExample = ref('')

const pdfCloudFolderFileInfo = reactive({
  dirName: '',
  baseName: '',
})

const tooltips = {
  useBackgroundJobsDefault: t(appName, 'If checked default to background PDF generation. This can be overridden by navigating to the PDF panel in the details sidebar for each particular source folder or archive file.'),
  authenticatedBackgroundJobs: t(appName, 'If unsure keep this disabled. Enabling this option leads to an additional directory scan prior to scheduling a background operation. If the scan detects a mount point in the directory which has been mounted with the "authenticated" mount option then your login credentials will be temporarily promoted to the background job. This is primarily used to handle special cases which should only concern the author of this package. Keep the option disabled unless you really know what it means and you really known that you need it.'),
}

// TRANSLATORS comments seemingly cannot be placed in HTML section of Vue code, so:
const l10nStrings = {
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
}

const pageLabelTextColorPicker = ref<null|typeof ColorPicker>(null)
const pageLabelBackgroundColorPicker = ref<null|typeof ColorPicker>(null)

const fetchPageLabelTemplateExample = async () => {
  try {
    const response = await axios.get<{ pageLabel: string }>(generateAppUrl(
      'sample/page-label/{template}/{path}/{pageNumber}/{totalPages}', {
        template: encodeURIComponent(settings.pageLabelTemplate),
        path: encodeURIComponent(settings.exampleFilePath),
        dirPageNumber: 13,
        dirTotalPages: 197,
        filePageNumber: 3,
        fileTotalPages: 17,
      }))
    pageLabelTemplateExample.value = response.data.pageLabel
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
  } catch (e: any) {
    logger.info('RESPONSE', e)
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
    pageLabelTemplateExample.value = settings.pageLabelTemplate
  }
}

const exampleFilePathParent = computed(() => {
  const pathInfo = pathParse(settings.exampleFilePath || '')
  return pathInfo.dir + '/'
})

const fetchPdfFileNameTemplateExample = async () => {
  try {
    const response = await axios.get(generateAppUrl(
      'sample/pdf-filename/{template}/{path}', {
        template: encodeURIComponent(settings.pdfFileNameTemplate),
        path: encodeURIComponent(exampleFilePathParent.value),
      }))
    pdfFileNameTemplateExample.value = response.data.pdfFileName
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
  } catch (e: any) {
    logger.info('RESPONSE', e)
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
    pdfFileNameTemplateExample.value = settings.pdfFileNameTemplate
  }
}

const getData = async () => {
  // slurp in all personal settings
  ++loading.value
  const settingsPromise = fetchSettings({ section: 'personal', settings })
  settingsPromise.finally(() => {
    if (pageLabelTextColorPicker.value) {
      pageLabelTextColorPicker.value.saveState()
      pageLabelBackgroundColorPicker.value!.saveState()
    }
    --loading.value
  })
  ++loading.value
  const fontsPromise = axios.get(generateAppUrl('fonts'))
  fontsPromise.catch((e) => {
    logger.info('RESPONSE', e)
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
    --loading.value
  })

  Promise.all([settingsPromise, fontsPromise]).then(
    (responses) => {
      const response = responses[1]
      fontsList.value = response.data
      let fontIndex = fontsList.value.findIndex((x) => x.family === settings.pageLabelsFont)
      pageLabelsFontObject.value = fontIndex >= 0 ? { ...fontsList.value[fontIndex] } : undefined
      if (pageLabelsFontObject.value) {
        vueSet(pageLabelsFontObject.value, 'fontSize', settings.pageLabelsFontSize)
      }
      fontIndex = fontsList.value.findIndex((x) => x.family === settings.generatedPagesFont)
      generatedPagesFontObject.value = fontIndex >= 0 ? { ...fontsList.value[fontIndex] } : undefined
      if (generatedPagesFontObject.value) {
        vueSet(generatedPagesFontObject.value, 'fontSize', settings.generatedPagesFontSize)
      }
    },
  )

  ++loading.value
  fetchPageLabelTemplateExample().finally(() => {
    --loading.value
  })
  ++loading.value
  fetchPdfFileNameTemplateExample().finally(() => {
    --loading.value
  })
  --loading.value
}
getData()

const patternTestResult = ref('')

const updatePatternTestResult = (responseData: { patternTestResult?: string }) => {
  if (responseData?.patternTestResult) {
    patternTestResult.value = responseData.patternTestResult
    showInfo(t(appName, 'Include/exclude test result for "{string}" is "{result}".', {
      string: settings.patternTestString || '',
      result: l10nPatternTestResult.value,
    }))
  }
}

// make sure that the pattern precedence has an "expected" value
// if any or either of the include/exclude patterns is not set.
const sanitizePatternPrecedence = () => {
  let forcedPrecedence = settings.patternPrecedence
  if (!settings.includePattern && !settings.excludePattern) {
    forcedPrecedence = 'includeHasPrecedence'
  } else if (!settings.includePattern) {
    forcedPrecedence = 'excludeHasPrecedence'
  } else if (!settings.excludePattern) {
    forcedPrecedence = 'includeHasPrecedence'
  }
  if (forcedPrecedence !== settings.patternPrecedence) {
    settings.patternPrecedence = forcedPrecedence
    saveSetting('patternPrecedence')
  }
}

const lt = (a: null|undefined|number, b: null|undefined|number) => a! < b!

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const saveTextInput = async (settingsKey: string, value?: any, force?: boolean) => {
  if (value === undefined) {
    value = settings[settingsKey] || ''
  }
  if (loading.value > 0) {
    // avoid ping-pong by reactivity
    logger.info('SKIPPING SETTINGS-SAVE DURING LOAD', settingsKey, value)
    return
  }
  return saveConfirmedSetting({ value, section: 'personal', settingsKey, force, onSuccess: updatePatternTestResult, settings })
}

const saveSetting = async (settingsKey: string) => {
  if (loading.value > 0) {
    // avoid ping-pong by reactivity
    logger.info('SKIPPING SETTINGS-SAVE DURING LOAD', settingsKey)
    return
  }
  return saveSimpleSetting({ settingsKey, section: 'personal', onSuccess: updatePatternTestResult, settings })
}

const fontObjectWatcher = (fontType: string, newValue?: FontDescriptor, oldValue?: FontDescriptor) => {
  // track the font-object by family and font-size
  if (newValue && oldValue
    && newValue.family === oldValue.family
    && newValue.fontSize === oldValue.fontSize) {
    return
  }
  const fontKey = fontType + 'Font'
  const sizeKey = fontType + 'FontSize'
  const objectKey = fontType + 'FontObject'
  const skipSave = loading.value || oldSettings[fontKey] === undefined || oldSettings[sizeKey] === undefined
  oldSettings[fontKey] = oldValue ? oldValue.family : null
  oldSettings[sizeKey] = oldValue ? oldValue.fontSize : null
  oldSettings[objectKey] = oldValue
  settings[fontKey] = newValue ? newValue.family : null
  settings[sizeKey] = newValue ? newValue.fontSize : null
  if (!skipSave) {
    if (settings[fontKey] !== oldSettings[fontKey]) {
      saveSetting(fontKey)
    }
    if (settings[sizeKey] !== oldSettings[sizeKey]) {
      saveSetting(sizeKey)
    }
  }
}

const pageLabelsFontSelect = ref<null|typeof FontSelect>(null)

const pageLabelTemplateFontSampleUri = computed(() => {
  if (!pageLabelsFontObject.value) {
    return ''
  }
  const text = pageLabelTemplateExample.value
  return pageLabelsFontSelect.value!.getFontSampleUri(pageLabelsFontObject.value, {
    text,
    textColor: '#000000',
    fontSize: settings.pageLabelPageWidthFraction ? undefined : settings.pageLabelsFontSize,
  })
})

const pageLabelTemplateFontSampleFilter = computed(() => {
  const targetRgbColor = settings.pageLabelTextColor
  const cssFilter = hexToCSSFilter(targetRgbColor)
  return cssFilter.filter.replace(/;$/g, '')
})

watch(pdfCloudFolderFileInfo, (value) => {
  settings.pdfCloudFolderPath = value.dirName
  saveTextInput('pdfCloudFolderPath')
})

const l10nPatternTestResult = computed(() => {
  switch (patternTestResult.value) {
  case 'included':
    return t(appName, 'included')
  case 'excluded':
    return t(appName, 'excluded')
  default:
    return ''
  }
})

watch(() => settings.pageLabels, (_newValue, oldValue) => {
  oldSettings.pageLabels = oldValue
})

watch(pageLabelsFontObject, (newValue, oldValue) => {
  fontObjectWatcher('pageLabels', newValue, oldValue)
})

watch(generatedPagesFontObject, (newValue, oldValue) => {
  fontObjectWatcher('generatedPages', newValue, oldValue)
})

watch(() => settings.pageLabelTemplate, (_newValue, _oldValue) => {
  fetchPageLabelTemplateExample()
})

watch(() => settings.pdfFileNameTemplate, (_newValue, _oldValue) => {
  fetchPdfFileNameTemplateExample()
})

watch(() => settings.pageLabelTextColor, (newValue, oldValue) => {
  logger.info('TEXT COLOR', newValue, oldValue)
})

watch(() => settings.pageLabelBackgroundColor, (newValue, oldValue) => {
  logger.info('BACKGROUND', newValue, oldValue)
})

watch(() => settings.includePattern, (_newValue, _oldValue) => {
  sanitizePatternPrecedence()
})

watch(() => settings.excludePattern, (_newValue, _oldValue) => {
  sanitizePatternPrecedence()
})

onMounted(() => {
  if (!loading.value) {
    pageLabelTextColorPicker.value!.saveState()
    pageLabelBackgroundColorPicker.value!.saveState()
  }
})
</script>
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
      color: red; // saggme as PdfCombiner
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
