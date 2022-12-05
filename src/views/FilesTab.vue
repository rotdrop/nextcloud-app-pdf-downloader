<script>
/**
 * @author Claus-Justus Heine <himself@claus-justus-heine.de>
 * @copyright 2022 Claus-Justus Heine
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
             @click="toggleDownloadMenu"
        />
        <div class="files-tab-entry__desc"
             @click="toggleDownloadMenu"
        >
          <h5>
            <span class="main-title">{{ t(appName, 'Generate PDF') }}</span>
          </h5>
        </div>
        <Actions ref="downloadActions">
          <ActionButton v-model="showCloudDestination"
                        @click="showCloudDestination = !showCloudDestination"
          >
            <template #icon>
              <CloudUpload :size="16"
                           decorative
                           title=""
              />
            </template>
            {{ t(appName, 'save to cloud') }}
          </ActionButton>
          <ActionButton icon="icon-download"
                        :disabled="downLoading"
                        @click="handleDownload"
          >
            {{ t(appName, 'download locally') }}
          </ActionButton>
        </Actions>
      </li>
      <li v-show="showCloudDestination" class="directory-chooser files-tab-entry">
        <FilePrefixPicker v-model="cloudDestinationFileInfo"
                          :hint="t(appName, 'Choose a destination in the cloud:')"
                          :placeholder="t(appName, 'base-name')"
                          :readonly="downloadOptions.useTemplate ? 'basename' : false"
                          @update="handleSaveToCloud"
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
          <h5>{{ t(appName, 'Offline-Downloads') }}</h5>
        </div>
        <Actions>
          <ActionButton v-model="showBackgroundDownloads"
                        :icon="'icon-triangle-' + (showBackgroundDownloads ? 's' : 'n')"
                        @click="showBackgroundDownloads = !showBackgroundDownloads"
          />
        </Actions>
      </li>
      <li v-show="showBackgroundDownloads" class="files-tab-entry">
        <div v-if="loading" class="icon-loading-small" />
        <div v-else>
          {{ t(appName, 'NO DOWNLOADS YET') }}
        </div>
      </li>
    </ul>
  </div>
</template>
<script>

import { appName } from '../config.js'
import Vue from 'vue'
import Actions from '@nextcloud/vue/dist/Components/Actions'
import ActionButton from '@nextcloud/vue/dist/Components/ActionButton'
import ActionCheckBox from '@nextcloud/vue/dist/Components/ActionCheckbox'
import CloudUpload from 'vue-material-design-icons/CloudUpload'
import axios from '@nextcloud/axios'
import { showError, showSuccess, TOAST_PERMANENT_TIMEOUT } from '@nextcloud/dialogs';
import * as Path from 'path'
import generateAppUrl from '../toolkit/util/generate-url.js'
import { getInitialState } from '../toolkit/services/InitialStateService.js'
import fileDownload from '../toolkit/util/file-download.js';
import FilePrefixPicker from '../components/FilePrefixPicker'

const initialState = getInitialState()

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
      loading: true,
      downLoading: false,
      tooltips: {
        pageLabels: t(appName, 'Decorate each page with the original file name and the page number within that file. The default is configured in the personal preferences for the app.'),
        offline: t(appName, 'When converting many or large files to PDF you will encounter timeouts because the request just lasts too long and the web-server bails out. If this happens you can schedule offline generation of the PDF. This will not make things faster for you, but the execution time is not constrained by the web-server limits. You will be notified when it is ready. If you chose to store the PDF in the cloud file-system, then it will just show up there. If you chose to download to you local computer then the download will show up here (and in the notification). The download links have a configurable expiration time.'),
        useTemplate: t(appName, 'Auto-generate the download file-name from the given template. The default template can be configured in the personal settings for this app.'),
      },
      personalSettings: {},
    };
  },
  created() {
    // this.getData()
  },
  mounted() {
    // this.getData()
  },
  computed: {
    /**
     * @return {string} The folder name to use for downloads. If
     * this.sourcePath refers to an archive file then this
     * this.folderPath contains the source file-name without archive
     * extensions (muli extenaions lik .tar.EXT are also stripped).
     */
    folderPath() {
      return this.fileInfo.path + '/' + this.folderName
    },
    /**
     * @return {string} The full path to the source file-system object
     * (folder or archive file).
     */
    sourcePath() {
      return this.fileInfo.path + '/' + this.fileInfo.name
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
    showCloudDestination(newValue, oldValue) {
      if (newValue) {
        this.$refs.downloadActions.closeMenu()
      }
    },
  },
  methods: {
    info() {
      console.info.apply(null, arguments)
    },
     /**
     * Update current fileInfo and fetch new data
     * @param {Object} fileInfo the current file FileInfo
     */
    async update(fileInfo) {
      this.loading = true

      this.fileInfo = fileInfo

      this.fileList = OCA.Files.App.currentFileList
      this.fileList.$el.off('updated').on('updated', function(event) {
        console.info('FILE LIST UPDATED, ARGS', arguments)
      })

      this.cloudDestinationDirName = this.config.pdfCloudFolderPath || fileInfo.path
      if (this.fileInfo.type === 'dir') {
        this.folderName = fileInfo.name
      } else {
        // archive file, split the relevant extensions
        const pathInfo = Path.parse(fileInfo.name)
        this.folderName = Path.basename(pathInfo.name, '.tar')
      }
      this.downloadOptions.pageLabels = this.config.pageLabels

      this.cloudDestinationBaseName = await this.fetchPdfFileNameFromTemplate(this.folderPath)

      this.loading = false
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
      try {
        const response = await axios.get(generateAppUrl(
          'sample/pdf-filename/{template}/{path}', {
            template: encodeURIComponent(this.config.pdfFileNameTemplate),
            path: encodeURIComponent(folderPath),
        }));
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
        return undefined
      }
    },
    async handleDownload() {
      const urlParameters = {
        sourcePath: encodeURIComponent(this.sourcePath),
        destinationPath: encodeURIComponent(this.cloudDestinationBaseName),
      }
      const queryParameters = {
        pageLabels: this.downloadOptions.pageLabels,
        useTemplate: this.downloadOptions.useTemplate,
      }
      this.downLoading = true
      this.fileList.showFileBusyState(this.fileInfo.name, true)
      if (this.downloadOptions.offline) {
        try {
          const response = await axios.post(
            generateAppUrl('schedule/{sourcePath}/{destinationPath}/download', urlParameters),
            queryParameters
          )
          showSuccess(t(appName, 'PDF saved as {path}.', { path: response.data.pdfFilePath }))
        } catch (e) {
          let message = t(appName, 'reason unknown')
          if (e.response && e.response.data) {
            const responseData = e.response.data;
            if (Array.isArray(responseData.messages)) {
              message = responseData.messages.join(' ');
            }
          }
          showError(t(appName, 'Unable to save {sourceFile} to the cloud: {message}', {
            sourceFile: this.sourcePath,
            message,
          }), {
            timeout: TOAST_PERMANENT_TIMEOUT,
          })
        }
        this.downLoading = false
        this.fileList.showFileBusyState(this.fileInfo.name, false)
      } else {
        const url = generateAppUrl('download/{sourcePath}', { ...urlParameters, ...queryParameters });
        fileDownload(url, false, {
          always: () => {
            this.downLoading = false
            this.fileList.showFileBusyState(this.fileInfo.name, false)
          }
        })
      }
    },
    async handleSaveToCloud() {
      const sourcePath = encodeURIComponent(this.sourcePath)
      const destinationPath = encodeURIComponent(this.cloudDestinationPathName)
      this.fileList.showFileBusyState(this.fileInfo.name, true)
      const requestParameters = {
        sourcePath,
        destinationPath,
      }
      const urlTemplate = this.downloadOptions.offline
        ? 'save/{sourcePath}/{destinationPath}'
        : 'schedule/{sourcePath}/{destinationPath}/filesystem'
      try {
        const response = await axios.post(generateAppUrl(
          urlTemplate, {
            sourcePath,
            destinationPath,
        }), {
          pageLabels: this.downloadOptions.pageLabels,
          useTemplate: this.downloadOptions.useTemplate,
        })
        showSuccess(t(appName, 'PDF saved as {path}.', { path: response.data.pdfFilePath }))
      } catch (e) {
        let message = t(appName, 'reason unknown')
        if (e.response && e.response.data) {
          const responseData = e.response.data;
          if (Array.isArray(responseData.messages)) {
            message = responseData.messages.join(' ');
          }
        }
        showError(t(appName, 'Unable to save {sourceFile} to the cloud: {message}', {
          sourceFile: this.sourcePath,
          message,
        }), {
          timeout: TOAST_PERMANENT_TIMEOUT,
        })
      }
      this.fileList.showFileBusyState(this.fileInfo.name, false)
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
    .flex-grow {
      flex-grow:1;
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
