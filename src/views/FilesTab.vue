<!--
 - @author Claus-Justus Heine <himself@claus-justus-heine.de>
 - @copyright 2022, 2023, 2024, 2025 Claus-Justus Heine
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
  <div class="files-tab">
    <ul>
      <li class="files-tab-entry flex flex-center clickable">
        <div class="files-tab-entry__avatar icon-play-white"
             @click.prevent.stop="toggleDownloadMenu"
        />
        <div class="files-tab-entry__desc"
             @click.prevent.stop="toggleDownloadMenu"
        >
          <h5>
            <span class="main-title">{{ t(appName, 'Generate PDF') }}</span>
          </h5>
        </div>
        <NcActions ref="downloadActions">
          <NcActionButton icon="icon-download"
                          :disabled="downloading"
                          @click.prevent.stop="handleDownload"
          >
            {{ t(appName, 'download locally') }}
          </NcActionButton>
          <NcActionButton :model-value.sync="showCloudDestination"
                          :disabled="showCloudDestination"
                          @click.prevent.stop="showCloudDestination = !showCloudDestination"
          >
            <template #icon>
              <CloudUpload :size="16"
                           decorative
                           title=""
              />
            </template>
            {{ t(appName, 'save to cloud') }}
          </NcActionButton>
        </NcActions>
      </li>
      <li v-show="showCloudDestination" class="directory-chooser files-tab-entry">
        <FilePrefixPicker v-model="cloudDestinationFileInfo"
                          :hint="t(appName, 'Choose a destination in the cloud:')"
                          :placeholder="t(appName, 'basename')"
                          :readonly="downloadOptions.useTemplate ? 'basename' : false"
                          :disabled="downloading"
                          @update="() => handleSaveToCloud()"
        />
      </li>
      <li class="files-tab-entry flex flex-center clickable">
        <div class="files-tab-entry__avatar icon-settings-white"
             @click="toggleOptionsMenu"
        />
        <div class="files-tab-entry__desc"
             @click="toggleOptionsMenu"
        >
          <h5>
            <span class="main-title">{{ t(appName, 'Options') }}</span>
          </h5>
        </div>
        <NcActions ref="downloadOptionsElement">
          <NcActionCheckbox v-tooltip="tooltips.pageLabels"
                            :checked="!!downloadOptions.pageLabels"
                            @update:checked="setDownloadOption('pageLabels', $event)"
          >
            {{ t(appName, 'page labels') }}
          </NcActionCheckbox>
          <NcActionCheckbox v-tooltip="tooltips.useTemplate"
                            :checked="!!downloadOptions.useTemplate"
                            @update:checked="setDownloadOption('useTemplate', $event)"
          >
            {{ t(appName, 'filename template') }}
          </NcActionCheckbox>
          <NcActionCheckbox v-tooltip="tooltips.offline"
                            :checked="!!downloadOptions.offline"
                            @update:checked="setDownloadOption('offline', $event)"
          >
            {{ t(appName, 'offline') }}
          </NcActionCheckbox>
        </NcActions>
      </li>
      <li class="files-tab-entry flex flex-center clickable">
        <div class="files-tab-entry__avatar icon-download-white"
             @click="showBackgroundDownloads = !showBackgroundDownloads"
        />
        <div class="files-tab-entry__desc"
             @click="showBackgroundDownloads = !showBackgroundDownloads"
        >
          <h5>{{ t(appName, 'Offline Downloads') }}</h5>
        </div>
        <NcActions>
          <NcActionButton :model-value.sync="showBackgroundDownloads"
                          :icon="'icon-triangle-' + (showBackgroundDownloads ? 'n' : 's')"
                          @click.prevent.stop="showBackgroundDownloads = !showBackgroundDownloads"
          />
        </NcActions>
      </li>
      <li v-show="showBackgroundDownloads" class="files-tab-entry">
        <div v-if="loading" class="icon-loading-small" />
        <div v-else-if="downloads.length === 0" class="flex flex-center justify-center">
          <span class="label">
            {{ t(appName, 'No Downloads Yet') }}
          </span>
          <NcActions>
            <NcActionButton icon="icon-play"
                            @click.prevent.stop="refreshAvailableDownloads"
            >
              {{ t(appName, 'refresh') }}
            </NcActionButton>
          </NcActions>
        </div>
        <ul v-else>
          <li v-for="{fileid, basename} in downloads" :key="fileid" class="flex flex-center flex-wrap">
            <a :href="downloadUrl(fileid)"
               class="download external flex-grow"
               download
               @click.prevent.stop="handleCacheFileDownload(fileid, basename)"
            >
              {{ basename }}
            </a>
            <NcActions class="flex-no-grow flex-no-shrink">
              <NcActionButton @click.prevent.stop="handleCacheFileSave(fileid)">
                <template #icon>
                  <CloudUpload :size="16"
                               decorative
                               title=""
                  />
                </template>
                {{ t(appName, 'save to cloud') }}
              </NcActionButton>
              <NcActionButton icon="icon-download"
                              :disabled="downloading"
                              @click.prevent.stop="handleCacheFileDownload(fileid, basename)"
              >
                {{ t(appName, 'download locally') }}
              </NcActionButton>
              <NcActionButton icon="icon-delete"
                              :disabled="downloading"
                              @click.prevent.stop="handleCacheFileDelete(fileid)"
              >
                {{ t(appName, 'delete PDF file') }}
              </NcActionButton>
            </NcActions>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</template>
<script setup lang="ts">
import { appName } from '../config.ts'
import {
  computed,
  reactive,
  ref,
  set as vueSet,
  watch,
} from 'vue'
import { getRequestToken } from '@nextcloud/auth'
import { emit, subscribe } from '@nextcloud/event-bus'
import { fileInfoToNode } from '../toolkit/util/file-node-helper.ts'
import type { FileInfoDTO } from '../toolkit/util/file-node-helper.ts'
import {
  NcActions,
  NcActionButton,
  NcActionCheckbox,
} from '@nextcloud/vue'
import {
  getFilePickerBuilder,
  TOAST_PERMANENT_TIMEOUT,
  showError,
  showSuccess,
} from '@nextcloud/dialogs'
import type {
  IFilePickerButton,
} from '@nextcloud/dialogs'
import CloudUpload from 'vue-material-design-icons/CloudUpload.vue'
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
// import path, * as Path from 'path'
import generateAppUrl from '../toolkit/util/generate-url.ts'
import getInitialState from '../toolkit/util/initial-state.ts'
import fileDownload from '../toolkit/util/axios-file-download.ts'
import FilePrefixPicker from '@rotdrop/nextcloud-vue-components/lib/components/FilePrefixPicker.vue'
import { basename as pathBasename } from 'path'
import { isAxiosErrorResponse } from '../toolkit/types/axios-type-guards.ts'
import IconMove from '@mdi/svg/svg/folder-move.svg?raw'
import IconCopy from '@mdi/svg/svg/folder-multiple.svg?raw'
import type { LegacyFileInfo } from '@nextcloud/files'
import type { InitialState } from '../types/initial-state.d.ts'

const initialState = getInitialState<InitialState>()

const tooltips = reactive({
  pageLabels: t(appName, 'Decorate each page with the original file name and the page number within that file. The default is configured in the personal preferences for the app.'),
  offline: t(appName, 'When converting many or large files to PDF you will encounter timeouts because the request just lasts too long and the web server bails out. If this happens you can schedule offline generation of the PDF. This will not make things faster for you, but the execution time is not constrained by the web server limits. You will be notified when it is ready. If you chose to store the PDF in the cloud file system, then it will just show up there. If you chose to download to you local computer then the download will show up here (and in the notification). The download links have a configurable expiration time.'),
  useTemplate: t(appName, 'Auto-generate the download filename from the given template. The default template can be configured in the personal settings for this app.'),
})

const fileInfo = ref<undefined|LegacyFileInfo>(undefined)

const downloadOptions = reactive({
  offline: undefined as undefined|boolean,
  pageLabels: undefined as undefined|boolean,
  useTemplate: true,
})
// currently, there is no type information on the event handlers in
// the tempalte AND it is also not possible to use TS annotations in
// the templates. So this stupid approach is simply in order to shut
// off some warnings ...
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const setDownloadOption = (key: string, value: any) => { downloadActions[key] = value }

const cloudDestinationFileInfo = reactive({
  dirName: '',
  baseName: '',
})

const showCloudDestination = ref(false)

const showBackgroundDownloads = ref(false)

const downloading = ref(false)

const activeLoaders = ref(-1)

const downloads = ref<FileInfoDTO[]>([])

const loading = computed(() => activeLoaders.value !== 0)

const sourceFileId = computed(() => fileInfo.value?.id)

const sourcePath = computed(
  () => fileInfo.value?.path + (fileInfo.value?.path === '/' ? '' : '/') + fileInfo.value?.name,
)

const cloudDestinationBaseName = computed({
  get() {
    return cloudDestinationFileInfo.baseName
  },
  set(value) {
    vueSet(cloudDestinationFileInfo, 'baseName', value)
    return value
  },
})

const cloudDestinationDirName = computed({
  get() {
    return cloudDestinationFileInfo.dirName
  },
  set(value) {
    vueSet(cloudDestinationFileInfo, 'dirName', value)
    return value
  },
})

const cloudDestinationPathName = computed(
  () => cloudDestinationDirName.value + (cloudDestinationBaseName.value ? '/' + cloudDestinationBaseName.value : ''),
)

const downloadActions = ref<null|typeof NcActions>(null)

watch(showCloudDestination, (newValue, _oldValue) => {
  if (newValue) {
    downloadActions.value!.closeMenu()
  }
})

watch(downloads, (newValue, _oldValue) => {
  showBackgroundDownloads.value = newValue.length > 0
})

/**
 * This used to turn on a busy indicator on the current row of the file-list.
 *
 * @param _state TBD.
 *
 * @todo Find out if this still can be achieved.
 */
const setBusySate = (_state: boolean) => {}

/**
 * Update current fileInfo and fetch new data
 *
 * @param newFileInfo the current file FileInfo
 */
const update = async (newFileInfo: LegacyFileInfo) => {
  activeLoaders.value = 1

  fileInfo.value = newFileInfo

  // NOPE, the following is no longer there:

  cloudDestinationDirName.value = initialState?.pdfCloudFolderPath || fileInfo.value.path
  if (fileInfo.value.type === 'dir') {
    // this.folderName = fileInfo.name
  } else {
    // archive file, split the relevant extensions
    // const pathInfo = Path.parse(fileInfo.name)
    // this.folderName = Path.basename(pathInfo.name, '.tar')
  }
  downloadOptions.pageLabels = !!initialState?.pageLabels
  downloadOptions.offline = !!initialState?.useBackgroundJobsDefault

  fetchPdfFileNameFromTemplate(sourcePath.value)
    .then((value) => {
      cloudDestinationBaseName.value = value
    })

  refreshAvailableDownloads()

  --activeLoaders.value
}

const downloadOptionsElement = ref<typeof NcActions|null>(null)

const toggleOptionsMenu = () => {
  if (downloadOptionsElement.value!.opened) {
    downloadOptionsElement.value!.closeMenu()
  } else {
    downloadOptionsElement.value!.openMenu()
  }
}

const toggleDownloadMenu = () => {
  if (downloadActions.value!.opened) {
    downloadActions.value!.closeMenu()
  } else if (showCloudDestination.value) {
    showCloudDestination.value = false
  } else {
    downloadActions.value!.openMenu()
  }
}

const fetchPdfFileNameFromTemplate = async (folderPath: string) => {
  ++activeLoaders.value
  try {
    const response = await axios.get(generateAppUrl(
      'sample/pdf-filename/{template}/{path}', {
        template: encodeURIComponent(initialState?.pdfFileNameTemplate || ''),
        path: encodeURIComponent(folderPath),
      }))
    --activeLoaders.value
    return response.data.pdfFileName
  } catch (e) {
    let message = t(appName, 'reason unknown')
    if (isAxiosErrorResponse(e) && e.response.data) {
      const responseData = e.response.data as { messages?: string[] }
      if (Array.isArray(responseData.messages)) {
        message = responseData.messages.join(' ')
      }
    }
    showError(t(appName, 'Unable to obtain the pdf-file template example: {message}', {
      message,
    }), {
      timeout: TOAST_PERMANENT_TIMEOUT,
    })
    --activeLoaders.value
    return undefined
  }
}

const refreshAvailableDownloads = async () => {
  const downloads = await fetchAvailableDownloads()
  downloads.value = downloads
  showBackgroundDownloads.value = downloads.length > 0
}

const fetchAvailableDownloads = async (silent?: boolean) => {
  if (silent !== true) {
    ++activeLoaders.value
  }
  try {
    const response = await axios.get(generateAppUrl(
      'list/{sourcePath}', {
        sourcePath: encodeURIComponent(sourcePath.value),
      }))
    console.info('DOWNLOADS RESPONSE', response)
    if (silent !== true) {
      --activeLoaders.value
    }
    return response.data
  } catch (e) {
    let message = t(appName, 'reason unknown')
    if (isAxiosErrorResponse(e) && e.response.data) {
      const responseData = e.response.data as { messages?: string[] }
      if (Array.isArray(responseData.messages)) {
        message = responseData.messages.join(' ')
      }
    }
    showError(t(appName, 'Unable to obtain the list of available downloads: {message}', {
      message,
    }), {
      timeout: TOAST_PERMANENT_TIMEOUT,
    })
    if (silent !== true) {
      --activeLoaders.value
    }
    return []
  }
}

const downloadUrl = (cacheId: number) => {
  return generateAppUrl('download/{sourceFileId}/{cacheId}', {
    sourceFileId: sourceFileId.value!,
    cacheId,
    requesttoken: getRequestToken(),
  })
}

const handleCacheFileSave = async (cacheId: number) => {
  // This cannot work with CopyMove as the promise returned is
  // resolved with only the path-name, the information about the
  // chosen mode of operation is not available.
  //
  let mode: 'Copy'|'Move'|undefined
  const picker = getFilePickerBuilder(t(appName, 'Choose a destination'))
    .startAt(cloudDestinationDirName.value)
    .setMultiSelect(false)
    .setMimeTypeFilter(['httpd/unix-directory'])
    .allowDirectories()
    .setButtonFactory((nodes, path) => {
      const buttons: IFilePickerButton[] = []
      const node: string = nodes?.[0]?.attributes?.displayName || nodes?.[0]?.basename
      const target = node || pathBasename(path)

      buttons.push({
        callback: () => { mode = 'Copy' },
        label: target ? t('core', 'Copy to {target}', { target }) : t('core', 'Copy'),
        type: 'primary',
        icon: IconCopy,
      })

      buttons.push({
        callback: () => { mode = 'Move' },
        label: target ? t('core', 'Move to {target}', { target }) : t('core', 'Move'),
        type: 'secondary',
        icon: IconMove,
      })

      return buttons
    })
    .build()
  let dir: string
  try {
    dir = await picker.pick()
    console.info('PATH AND MODE', dir, mode)
  } catch (e) {
    return
  }

  dir = dir || '/'
  if (dir.startsWith('//')) { // new in Nextcloud 25?
    dir = dir.slice(1)
  }
  await handleSaveToCloud(cacheId, dir, mode === 'Move')
  if (mode === 'Move') {
    const cacheIndex = downloads.value.findIndex((fileInfo: FileInfoDTO) => fileInfo.fileid === cacheId)
    if (cacheIndex >= 0) {
      downloads.value.splice(cacheIndex, 1)
    } else {
      console.info('DELETED DOWNLOAD ' + cacheId + ' HAS VANISHED FROM DATA?', downloads)
      fetchAvailableDownloads().then((newDownloads) => { downloads.value = newDownloads })
    }
  }
}

const handleCacheFileDownload = async (cacheId: number, baseName: string) => {
  downloading.value = true
  setBusySate(true)
  try {
    await fileDownload(downloadUrl(cacheId))
  } catch (e) {
    let message = ''
    if (isAxiosErrorResponse(e) && e.response.data) {
      const responseData = e.response.data as { messages?: string[] }
      if (Array.isArray(responseData.messages)) {
        message = responseData.messages.join(' ')
      }
    }
    const errorMessage = message
      ? t(appName, 'Download of {fileName} failed: {message}.', { fileName: baseName, message })
      : t(appName, 'Download of {fileName} failed.', { fileName: baseName })
    showError(errorMessage, { timeout: TOAST_PERMANENT_TIMEOUT })
  }
  downloading.value = false
  setBusySate(false)
}

const handleCacheFileDelete = async (cacheId: number) => {
  downloading.value = true
  try {
    const response = await axios.post(generateAppUrl(
      'clean/{sourcePath}/{cacheId}', {
        sourcePath: encodeURIComponent(sourcePath.value),
        cacheId,
      },
    ))
    const responseData = response.data
    if (Array.isArray(responseData.messages)) {
      for (const message of responseData.messages) {
        showSuccess(message)
      }
    }
    const cacheIndex = downloads.value.findIndex((fileInfo) => fileInfo.fileid === cacheId)
    if (cacheIndex >= 0) {
      downloads.value.splice(cacheIndex, 1)
    } else {
      console.info('DELETED DOWNLOAD ' + cacheId + ' HAS VANISHED?', downloads)
      fetchAvailableDownloads().then((newDownloads) => { downloads.value = newDownloads })
    }
  } catch (e) {
    let message = t(appName, 'reason unknown')
    if (isAxiosErrorResponse(e) && e.response.data) {
      const responseData = e.response.data as { messages?: string[] }
      if (Array.isArray(responseData.messages)) {
        message = responseData.messages.join(' ')
      }
    }
    showError(t(appName, 'Unable to delete the cached PDF file: {message}', {
      message,
    }), {
      timeout: TOAST_PERMANENT_TIMEOUT,
    })
    fetchAvailableDownloads().then((newDownloads) => { downloads.value = newDownloads })
  }
  downloading.value = false
}

const handleDownload = async () => {
  downloadActions.value!.closeMenu()
  showCloudDestination.value = false
  const urlParameters = {
    sourceFileId: sourceFileId.value!,
    sourcePath: encodeURIComponent(sourcePath.value),
    destinationPath: encodeURIComponent(cloudDestinationBaseName.value),
  }
  const queryParameters = {
    pageLabels: !!downloadOptions.pageLabels,
    useTemplate: downloadOptions.useTemplate,
  }
  downloading.value = true
  setBusySate(true)
  if (downloadOptions.offline) {
    try {
      axios.post(
        generateAppUrl('schedule/download/{sourcePath}/{destinationPath}', urlParameters),
        queryParameters,
      )
      showSuccess(t(appName, 'Background PDF generation for {sourceFile} has been scheduled.', {
        sourceFile: sourcePath.value,
      }))
    } catch (e) {
      let message = t(appName, 'reason unknown')
      if (isAxiosErrorResponse(e) && e.response.data) {
        const responseData = e.response.data as { messages?: string[] }
        if (Array.isArray(responseData.messages)) {
          message = responseData.messages.join(' ')
        }
      }
      showError(t(appName, 'Unable to schedule background PDF generation for {sourceFile}: {message}', {
        sourceFile: sourcePath.value,
        message,
      }), {
        timeout: TOAST_PERMANENT_TIMEOUT,
      })
    }
    downloading.value = false
    setBusySate(false)
  } else {
    const url = generateAppUrl('download/{sourceFileId}', { ...urlParameters, ...queryParameters })
    try {
      await fileDownload(url)
    } catch (e) {
      let message = ''
      if (isAxiosErrorResponse(e) && e.response.data) {
        const responseData = e.response.data as { messages?: string[] }
        if (Array.isArray(responseData.messages)) {
          message = responseData.messages.join(' ')
        }
      }
      const errorMessage = message
        ? t(appName, 'Download of generated PDF failed: {message}.', { message })
        : t(appName, 'Download of generated PDF failed.')
      showError(errorMessage, { timeout: TOAST_PERMANENT_TIMEOUT })
    }
    downloading.value = false
    setBusySate(false)
  }
}

const handleSaveToCloud = async (
  cacheFileId?: number,
  destinationFolder?: string,
  move?: boolean,
) => {
  downloading.value = true
  setBusySate(true)
  const offline = cacheFileId === undefined && downloadOptions.offline
  let urlTemplate = offline
    ? 'schedule/filesystem/{sourcePath}/{destinationPath}'
    : 'save/{sourcePath}/{destinationPath}'
  const destinationPathName = destinationFolder || cloudDestinationPathName.value
  const requestParameters: Record<string, string|number> = {
    sourcePath: encodeURIComponent(sourcePath.value),
    destinationPath: encodeURIComponent(destinationPathName),
  }
  if (cacheFileId) {
    urlTemplate += '/{cacheFileId}'
    requestParameters.cacheFileId = cacheFileId
  }
  console.info('TEMPLATE', urlTemplate, requestParameters)
  try {
    const response = await axios.post(
      generateAppUrl(urlTemplate, requestParameters), {
        pageLabels: downloadOptions.pageLabels,
        useTemplate: downloadOptions.useTemplate,
        move,
      },
    )
    if (offline) {
      showSuccess(t(appName, 'Scheduled offline PDF generation to {path}.', { path: response.data.pdfFilePath }))
    } else {
      const pdfFilePath = response.data.pdfFilePath.substring('/files'.length)
      showSuccess(t(appName, 'PDF saved as {path}.', { path: pdfFilePath }))

      // Emit a birth signal over the event bus. We don't care
      // whether the new node is located in the currently viewed
      // directory.
      const node = fileInfoToNode(response.data.fileInfo)
      console.info('NODE', node)

      // Update files list
      emit('files:node:created', node)
    }
  } catch (e) {
    let message = t(appName, 'reason unknown')
    if (isAxiosErrorResponse(e) && e.response.data) {
      const responseData = e.response.data as { messages?: string[] }
      if (Array.isArray(responseData.messages)) {
        message = responseData.messages.join(' ')
      }
    }
    const notice = t(appName, 'Unable to save the PDF generated from {sourceFile} to the cloud: {message}', {
      sourceFile: sourcePath.value,
      message,
    })
    showError(notice, { timeout: TOAST_PERMANENT_TIMEOUT })
    console.error(notice, e)
  }
  setBusySate(false)
  downloading.value = false
}

subscribe('notifications:notification:received', (event) => {
  const notification = event?.notification
  if (notification?.app !== appName) {
    return
  }
  const richParameters = notification?.subjectRichParameters
  if (richParameters.source?.id !== sourceFileId.value) {
    console.info('*** PDF generation notification for other file received', sourceFileId, richParameters)
    return
  }
  const destinationData = richParameters?.destination
  if (!destinationData) {
    return
  }
  if (destinationData?.status !== 'download' || !destinationData?.file) {
    console.info('*** PDF generation notification received, but not for for download.', destinationData)
    return
  }
  if (!destinationData?.file) {
    console.info('*** PDF generation notification received, but carries no file information.', destinationData)
    return
  }
  console.info('*** PDF download generation event received, updating downloads list', destinationData.file)
  const pdfFile = destinationData.file
  const pdfFilePath = pdfFile.path // undefined for removal notification
  const pdfFileId = pdfFile.fileid
  const downloadsIndex = downloads.value.findIndex((file) => file.fileid === pdfFileId)
  if (downloadsIndex === -1 && pdfFilePath) {
    console.info('*** Adding file to list of available downloads.', pdfFile)
    downloads.value.push(destinationData.file)
  } else if (downloadsIndex >= 0 && !pdfFilePath) {
    console.info('*** Removing file from list of available downloads.', pdfFile)
    downloads.value.splice(downloadsIndex, 1)
  }
})

defineExpose({
  update,
})

</script>
<style lang="scss" scoped>
.files-tab {
  .flex {
    display:flex;
    &.flex-center {
      align-items:center;
    }
    &.flex-wrap {
      flex-wrap:wrap;
    }
    &.justify-center {
      justify-content: center;
    }
    &.flex-wrap {
      flex-wrap:wrap;
    }
    .flex-grow {
      flex-grow:1;
    }
    .flex-no-grow {
      flex-grow:0;
    }
    .flex-no-shrink {
      flex-shrink:0;
    }
  }
  a.icon {
    background-position: left;
    padding-left:20px;
  }
  .files-tab-entry {
    min-height:44px;
    &.clickable {
      &, & * {
        cursor:pointer;
      }
    }
    .files-tab-entry__avatar {
      width: 32px;
      height: 32px;
      line-height: 32px;
      font-size: 18px;
      background-color: var(--color-text-maxcontrast);
      border-radius: 50%;
      flex-shrink: 0;
    }
    .files-tab-entry__desc {
      flex: 1 1;
      padding: 8px;
      line-height: 1.2em;
      min-width:0;
      h5 {
        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
        max-width: inherit;
      }
    }
    &.directory-chooser {
      .dirname {
        font-weight:bold;
        font-family:monospace;
        .button {
          display:block;
        }
      }
      .label {
        padding-right:0.5ex;
      }
    }
  }
}
</style>
