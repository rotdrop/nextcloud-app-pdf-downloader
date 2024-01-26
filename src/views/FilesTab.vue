<script>
/**
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022, 2023, 2024 Claus-Justus Heine
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
        <Actions ref="downloadActions">
          <ActionButton icon="icon-download"
                        :disabled="downloading"
                        @click.prevent.stop="handleDownload"
          >
            {{ t(appName, 'download locally') }}
          </ActionButton>
          <ActionButton v-model="showCloudDestination"
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
          </ActionButton>
        </Actions>
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
        <Actions ref="downloadOptions">
          <ActionCheckBox v-tooltip="tooltips.pageLabels"
                          :checked="!!downloadOptions.pageLabels"
                          @update:checked="(value) => downloadOptions.pageLabels = value"
          >
            {{ t(appName, 'page labels') }}
          </ActionCheckBox>
          <ActionCheckBox v-tooltip="tooltips.useTemplate"
                          :checked="!!downloadOptions.useTemplate"
                          @update:checked="(value) => downloadOptions.useTemplate = value"
          >
            {{ t(appName, 'filename template') }}
          </ActionCheckBox>
          <ActionCheckBox v-tooltip="tooltips.offline"
                          :checked="!!downloadOptions.offline"
                          @update:checked="(value) => downloadOptions.offline = value"
          >
            {{ t(appName, 'offline') }}
          </ActionCheckBox>
        </Actions>
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
        <Actions>
          <ActionButton v-model="showBackgroundDownloads"
                        :icon="'icon-triangle-' + (showBackgroundDownloads ? 'n' : 's')"
                        @click.prevent.stop="showBackgroundDownloads = !showBackgroundDownloads"
          />
        </Actions>
      </li>
      <li v-show="showBackgroundDownloads" class="files-tab-entry">
        <div v-if="loading" class="icon-loading-small" />
        <div v-else-if="downloads.length === 0" class="flex flex-center justify-center">
          <span class="label">
            {{ t(appName, 'No Downloads Yet') }}
          </span>
          <Actions>
            <ActionButton icon="icon-play"
                          @click.prevent.stop="refreshAvailableDownloads"
            >
              {{ t(appName, 'refresh') }}
            </ActionButton>
          </Actions>
        </div>
        <ul v-else>
          <li v-for="{id, name} in downloads" :key="id" class="flex flex-center flex-wrap">
            <a :href="downloadUrl(id)"
               class="download external flex-grow"
               download
               @click.prevent.stop="handleCacheFileDownload(id)"
            >
              {{ name }}
            </a>
            <Actions class="flex-no-grow flex-no-shrink">
              <ActionButton @click.prevent.stop="handleCacheFileSave(id)">
                <template #icon>
                  <CloudUpload :size="16"
                               decorative
                               title=""
                  />
                </template>
                {{ t(appName, 'save to cloud') }}
              </ActionButton>
              <ActionButton icon="icon-download"
                            :disabled="downloading"
                            @click.prevent.stop="handleCacheFileDownload(id)"
              >
                {{ t(appName, 'download locally') }}
              </ActionButton>
              <ActionButton icon="icon-delete"
                            :disabled="downloading"
                            @click.prevent.stop="handleCacheFileDelete(id)"
              >
                {{ t(appName, 'delete PDF file') }}
              </ActionButton>
            </Actions>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</template>
<script>

import { appName } from '../config.js'
import Vue from 'vue'
import { getRequestToken, getCurrentUser } from '@nextcloud/auth'
import { emit, subscribe } from '@nextcloud/event-bus'
import { fileInfoToNode } from '../toolkit/util/file-node-helper.js'
import Actions from '@nextcloud/vue/dist/Components/NcActions'
import ActionButton from '@nextcloud/vue/dist/Components/NcActionButton'
import ActionCheckBox from '@nextcloud/vue/dist/Components/NcActionCheckbox'
import { /* getFilePickerBuilder, */ showError, showSuccess, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs';

import CloudUpload from 'vue-material-design-icons/CloudUpload'
import axios from '@nextcloud/axios'
import * as Path from 'path'
import generateAppUrl from '../toolkit/util/generate-url.js'
import { getInitialState } from '../toolkit/services/InitialStateService.js'
import fileDownload from '../toolkit/util/file-download.js';
import FilePrefixPicker from '../components/FilePrefixPicker'
import { FilePickerType } from '@nextcloud/dialogs'

const initialState = getInitialState()

const downloadsPollingInterval = 30 * 1000

export default {
  name: 'FilesTab',
  components: {
    FilePrefixPicker,
    Actions,
    ActionButton,
    ActionCheckBox,
    CloudUpload,
  },
  mixins: [
  ],
  data() {
    return {
      fileList: undefined,
      fileInfo: {},
      folderName: undefined,
      config: initialState,
      downloads: [],
      downloadOptions: {
        offline: undefined,
        pageLabels: undefined,
        useTemplate: true,
      },
      showCloudDestination: false,
      cloudDestinationFileInfo: {
        dirName: undefined,
        baseName: undefined,
      },
      showBackgroundDownloads: false,
      activeLoaders: -1,
      downloading: false,
      downloadsTimer: undefined,
      tooltips: {
        pageLabels: t(appName, 'Decorate each page with the original file name and the page number within that file. The default is configured in the personal preferences for the app.'),
        offline: t(appName, 'When converting many or large files to PDF you will encounter timeouts because the request just lasts too long and the web server bails out. If this happens you can schedule offline generation of the PDF. This will not make things faster for you, but the execution time is not constrained by the web server limits. You will be notified when it is ready. If you chose to store the PDF in the cloud file system, then it will just show up there. If you chose to download to you local computer then the download will show up here (and in the notification). The download links have a configurable expiration time.'),
        useTemplate: t(appName, 'Auto-generate the download filename from the given template. The default template can be configured in the personal settings for this app.'),
      },
      personalSettings: {},
    };
  },
  created() {
    // this.getData()
    subscribe('notifications:notification:received', this.onNotification)
  },
  mounted() {
    // this.getData()
  },
  computed: {
    loading() {
      return this.activeLoaders !== 0
    },

    /**
     * @return {string} The folder name to use for downloads. If
     * this.sourcePath refers to an archive file then this
     * this.folderPath contains the source filename without archive
     * extensions (multi extensions like .tar.EXT are also stripped).
     */
    folderPath() {
      return this.fileInfo.path + (this.fileInfo.path === '/' ? '' : '/') + this.folderName
    },
    /**
     * @return {string} The full path to the source file-system object
     * (folder or archive file).
     */
    sourcePath() {
      return this.fileInfo.path + (this.fileInfo.path === '/' ? '' : '/') + this.fileInfo.name
    },
    cloudDestinationBaseName: {
      get() {
        return this.cloudDestinationFileInfo.baseName
      },
      set(value) {
        Vue.set(this.cloudDestinationFileInfo, 'baseName', value)
        return value
      }
    },
    cloudDestinationDirName: {
      get() {
        return this.cloudDestinationFileInfo.dirName
      },
      set(value) {
        Vue.set(this.cloudDestinationFileInfo, 'dirName', value)
        return value
      }
    },
    cloudDestinationPathName() {
      return this.cloudDestinationDirName + (this.cloudDestinationBaseName ? '/' + this.cloudDestinationBaseName : '')
    },
  },
  watch: {
    showCloudDestination(newValue/*, oldValue */) {
      if (newValue) {
        this.$refs.downloadActions.closeMenu()
      }
    },
  },
  beforeDestroy() {
    if (this.downloadsTimer) {
      clearInterval(this.downloadsTimer)
      this.downloadsTimer = undefined
    }
  },
  methods: {
    info() {
      console.info.apply(null, arguments)
    },
    setBusySate(/*state*/) {
      // This cannot be used any longer. How to?
      // this.fileList.showFileBusyState(this.fileInfo.name, state)
    },
     /**
     * Update current fileInfo and fetch new data
     * @param {Object} fileInfo the current file FileInfo
     */
    async update(fileInfo) {
      this.activeLoaders = 1;

      this.fileInfo = fileInfo

      // NOPE, the following is no longer there:
      // this.fileList = OCA.Files.App.currentFileList

      this.cloudDestinationDirName = this.config.pdfCloudFolderPath || fileInfo.path
      if (this.fileInfo.type === 'dir') {
        this.folderName = fileInfo.name
      } else {
        // archive file, split the relevant extensions
        const pathInfo = Path.parse(fileInfo.name)
        this.folderName = Path.basename(pathInfo.name, '.tar')
      }
      this.downloadOptions.pageLabels = this.config.pageLabels
      this.downloadOptions.offline = this.config.useBackgroundJobsDefault

      this.fetchPdfFileNameFromTemplate(this.folderPath)
          .then((value) => {
            this.cloudDestinationBaseName = value
          })

      this.refreshAvailableDownloads()

      --this.activeLoaders
    },
    /**
     * Fetch some needed data ...
     */
    async getData() {
      // await this.fetchSettings('personal', this.personalSettings)
      // Vue.set(this.downloadOptions, 'pageLabels', this.personalSettings.pageLabels)
      // this.downloadOptions.pageLabels = this.personalSettings.pageLabels
      // this.loading = false
    },
    toggleOptionsMenu() {
      if (this.$refs.downloadOptions.opened) {
        this.$refs.downloadOptions.closeMenu()
      } else {
        this.$refs.downloadOptions.openMenu()
      }
    },
    toggleDownloadMenu() {
      if (this.$refs.downloadActions.opened) {
        this.$refs.downloadActions.closeMenu()
      } else if (this.showCloudDestination) {
        this.showCloudDestination = false;
      } else {
        this.$refs.downloadActions.openMenu()
      }
    },
    async fetchPdfFileNameFromTemplate(folderPath) {
      ++this.activeLoaders
      try {
        const response = await axios.get(generateAppUrl(
          'sample/pdf-filename/{template}/{path}', {
            template: encodeURIComponent(this.config.pdfFileNameTemplate),
            path: encodeURIComponent(folderPath),
        }));
        --this.activeLoaders
        return response.data.pdfFileName
      } catch (e) {
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        showError(t(appName, 'Unable to obtain the pdf-file template example: {message}', {
          message,
        }), {
          timeout: TOAST_PERMANENT_TIMEOUT,
        })
        --this.activeLoaders
        return undefined
      }
    },
    async refreshAvailableDownloads() {
      const downloads = await this.fetchAvailableDownloads()
      this.downloads = downloads
      this.showBackgroundDownloads = downloads.length > 0
    },
    async fetchAvailableDownloads(silent) {
      if (silent !== true) {
        ++this.activeLoaders
      }
      try {
        const response = await axios.get(generateAppUrl(
          'list/{sourcePath}', {
            sourcePath: encodeURIComponent(this.sourcePath),
        }))
        console.info('DOWNLOADS RESPONSE', response)
        if (silent !== true) {
          --this.activeLoaders
        }
        return response.data
      } catch (e) {
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        showError(t(appName, 'Unable to obtain the list of available downloads: {message}', {
          message,
        }), {
          timeout: TOAST_PERMANENT_TIMEOUT,
        })
        if (silent !== true) {
          --this.activeLoaders
        }
        return []
      }
    },
    downloadUrl(cacheId) {
      return generateAppUrl('download/{sourcePath}/{cacheId}', {
        sourcePath: encodeURIComponent(this.sourcePath),
        cacheId,
        requesttoken: getRequestToken()
      })
    },
    async handleCacheFileSave(cacheId) {
      // This cannot work with CopyMove as the promise returned is
      // resolved with only the path-name, the information about the
      // chosen mode of operation is not available.
      //
      // const picker = getFilePickerBuilder(t(appName, 'Choose a destination'))
      //   .startAt(this.cloudDestinationDirName)
      //   .setMultiSelect(false)
      //   .setModal(true)
      //   .setType(FilePicker.CopyMove)
      //   .setMimeTypeFilter(['httpd/unix-directory'])
      //   .allowDirectories()
      //   .build()
      // let dir = await picker.pick()

      // so let's try something which could be a bugfix for @nextcloud/dialogs
      let { dir, mode } = await new Promise((res/*, rej */) => {
        OC.dialogs.filepicker(
          t(appName, 'Choose a destination'), // title
          (dir, mode) => res({ dir, mode }), // callback _WITH_ mode
          false, // multiselect
          ['httpd/unix-directory'], // mime-types
          true, // modal
          FilePickerType.CopyMove, // FilePickerType is not exported
          this.cloudDestinationDirName, // initial location
          {
            allowDirectoryChooser: true,
          }
        )
      })
      console.info('PATH AND MODE', dir, mode)
      dir = dir || '/'
      if (dir.startsWith('//')) { // new in Nextcloud 25?
        dir = dir.slice(1)
      }
      await this.handleSaveToCloud(cacheId, dir, mode === FilePickerType.Move)
      if (mode === FilePickerType.Move) {
        const cacheIndex = this.downloads.findIndex((fileInfo) => fileInfo.id === cacheId);
        if (cacheIndex >= 0) {
          this.downloads.splice(cacheIndex, 1)
        } else {
          console.info('DELETED DOWNLOAD ' + cacheId + ' HAS VANISHED FROM DATA?', this.downloads)
          this.fetchAvailableDownloads().then((downloads) => { this.downloads = downloads })
        }
      }
    },
    async handleCacheFileDownload(cacheId) {
      this.downloading = true
      this.setBusySate(true)
      fileDownload(this.downloadUrl(cacheId), false, {
        always: () => {
          this.downloading = false
          this.setBusySate(false)
        }
      })
    },
    async handleCacheFileDelete(cacheId) {
      this.downloading = true
      try {
        const response = await axios.post(generateAppUrl(
          'clean/{sourcePath}/{cacheId}', {
            sourcePath: encodeURIComponent(this.sourcePath),
            cacheId,
        }))
        const responseData = response.data
        if (Array.isArray(responseData.messages)) {
          for (const message of responseData.messages) {
            showSuccess(message)
          }
        }
        const cacheIndex = this.downloads.findIndex((fileInfo) => fileInfo.id === cacheId);
        if (cacheIndex >= 0) {
          this.downloads.splice(cacheIndex, 1)
        } else {
          console.info('DELETED DOWNLOAD ' + cacheId + ' HAS VANISHED?', this.downloads)
          this.fetchAvailableDownloads().then((downloads) => { this.downloads = downloads })
        }
      } catch (e) {
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        showError(t(appName, 'Unable to delete the cached PDF file: {message}', {
          message,
        }), {
          timeout: TOAST_PERMANENT_TIMEOUT,
        })
        this.fetchAvailableDownloads().then((downloads) => { this.downloads = downloads })
      }
      this.downloading = false
    },
    onNotification(event) {
      console.info('NOTIFICATION RECEIVED', event)
    },
    downloadsPoller(downloadFileIds) {
      // this probably should be replaced by an event-bus listener on notifications:action:execute
      this.fetchAvailableDownloads(true).then((downloads) => {
        let loadingFinished = downloads.length !== downloadFileIds.length
        if (!loadingFinished) {
          const fileIds = downloads.map(fileInfo => fileInfo.id).sort()
          for (const i = 0; i < fileIds.length; ++i) {
            if (fileIds[i] !== downloadFileIds[i]) {
              loadingFinished = true
              break
            }
          }
        }
        if (!loadingFinished) {
          this.downloadsTimer = setTimeout(() => this.downloadsPoller(downloadFileIds), downloadsPollingInterval)
        } else {
          this.downloadsTimer = undefined
          this.downloads = downloads
          this.showBackgroundDownloads = downloads.length > 0
        }
      })
    },
    async handleDownload() {
      this.$refs.downloadActions.closeMenu()
      this.showCloudDestination = false
      const urlParameters = {
        sourcePath: encodeURIComponent(this.sourcePath),
        destinationPath: encodeURIComponent(this.cloudDestinationBaseName),
      }
      const queryParameters = {
        pageLabels: this.downloadOptions.pageLabels,
        useTemplate: this.downloadOptions.useTemplate,
      }
      this.downloading = true
      this.setBusySate(true)
      if (this.downloadOptions.offline) {
        try {
          axios.post(
            generateAppUrl('schedule/download/{sourcePath}/{destinationPath}', urlParameters),
            queryParameters
          )
          showSuccess(t(appName, 'Background PDF generation for {sourceFile} has been scheduled.', {
            sourceFile: this.sourcePath,
          }))
          const downloadFileIds = this.downloads.map(fileInfo => fileInfo.id).sort()
          if (this.downloadsTimer) {
            clearInterval(this.downloadsTimer)
          }
          this.downloadsTimer = setTimeout(() => this.downloadsPoller(downloadFileIds), downloadsPollingInterval)
        } catch (e) {
          let message = t(appName, 'reason unknown')
          if (e.response && e.response.data) {
            const responseData = e.response.data;
            if (Array.isArray(responseData.messages)) {
              message = responseData.messages.join(' ');
            }
          }
          showError(t(appName, 'Unable to schedule background PDF generation for {sourceFile}: {message}', {
            sourceFile: this.sourcePath,
            message,
          }), {
            timeout: TOAST_PERMANENT_TIMEOUT,
          })
        }
        this.downloading = false
        this.setBusySate(false)
      } else {
        const url = generateAppUrl('download/{sourcePath}', { ...urlParameters, ...queryParameters });
        fileDownload(url, false, {
          always: () => {
            this.downloading = false
            this.setBusySate(false)
          }
        })
      }
    },
    async handleSaveToCloud(cacheFileId, destinationFolder, move) {
      this.downloading = true
      this.setBusySate(true)
      const offline = cacheFileId === undefined && this.downloadOptions.offline
      let urlTemplate = offline
        ? 'schedule/filesystem/{sourcePath}/{destinationPath}'
        : 'save/{sourcePath}/{destinationPath}'
      const sourcePath = encodeURIComponent(this.sourcePath)
      const destinationPathName = destinationFolder || this.cloudDestinationPathName
      const destinationPath = encodeURIComponent(destinationPathName)
      const requestParameters = {
        sourcePath,
        destinationPath,
      }
      if (cacheFileId) {
        urlTemplate += '/{cacheFileId}'
        requestParameters.cacheFileId = cacheFileId
      }
      console.info('TEMPLATE', urlTemplate, requestParameters)
      try {
        const response = await axios.post(
          generateAppUrl(urlTemplate, requestParameters), {
            pageLabels: this.downloadOptions.pageLabels,
            useTemplate: this.downloadOptions.useTemplate,
            move,
        })
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
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        const notice = t(appName, 'Unable to save the PDF generated from {sourceFile} to the cloud: {message}', {
          sourceFile: this.sourcePath,
          message,
        })
        showError(notice, { timeout: TOAST_PERMANENT_TIMEOUT, })
        console.error(notice, e)
      }
      this.setBusySate(false)
      this.downloading = false
    },
  },
}
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
