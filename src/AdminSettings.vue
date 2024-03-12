<!--
 - @copyright Copyright (c) 2022, 2023, 2024 Claus-Justus Heine <himself@claus-justus-heine.de>
 -
 - @author Claus-Justus Heine <himself@claus-justus-heine.de>
 -
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
  <div :class="['templateroot', ...cloudVersionClasses]">
    <h1 class="title">
      {{ t(appName, 'Recursive PDF Downloader') }}
    </h1>
    <NcSettingsSection v-if="dependencies.missing.required + dependencies.missing.suggested > 0"
                       id="missing-dependencies"
                       :name="t(appName, 'Missing Dependencies')"
    >
      <div v-if="dependencies.missing.required > 0" class="required-dependencies">
        <div><label>{{ t(appName, 'Required Missing') }}</label></div>
        <ul>
          <ListItem v-for="(path, program) in dependencies.required"
                    :key="program"
                    :title="program"
                    :details="path"
                    :bold="false"
          >
            <template #subtitle>
              <div class="hint">
                {{ t(appName, 'The app will not work unless you install {program} such that it can be found by the web-server.', { program }) }}
              </div>
            </template>
          </ListItem>
        </ul>
      </div>
      <div v-if="dependencies.missing.suggested > 0" class="suggested-dependencies">
        <div><label>{{ t(appName, 'Suggested Missing') }}</label></div>
        <ul>
          <ListItem v-for="(path, program) in dependencies.suggested"
                    :key="program"
                    :title="program"
                    :details="path"
                    :bold="false"
          >
            <template #subtitle>
              <div v-if="path === 'missing'" class="hint">
                {{ t(appName, 'The app will work without installing {program}, but the conversion results may be degraded.', { program }) }}
              </div>
            </template>
          </ListItem>
        </ul>
      </div>
    </NcSettingsSection>
    <NcSettingsSection id="archive-extraction"
                       :name="t(appName, 'Archive Extraction')"
    >
      <div :class="['flex-container', 'flex-center', { extractArchiveFiles }]">
        <input id="extract-archive files"
               v-model="extractArchiveFiles"
               type="checkbox"
               :disabled="loading"
               @change="saveSetting('extractArchiveFiles')"
        >
        <label for="extract-archive files">
          {{ t(appName, 'On-the-fly extraction of archive files. If enabled users can control this setting on a per-user basis.') }}
        </label>
      </div>
      <SettingsInputText v-model="humanArchiveSizeLimit"
                         :label="t(appName, 'Archive Size Limit')"
                         :hint="t(appName, 'Disallow archive extraction for archives with decompressed size larger than this limit.')"
                         :disabled="loading || !extractArchiveFiles"
                         @update="saveTextInput(...arguments, 'archiveSizeLimit')"
      />
    </NcSettingsSection>
    <NcSettingsSection id="authenticated-background-jobs"
                       :name="t(appName, 'Authenticated Background Jobs')"
    >
      <div :class="['flex-container', 'flex-center']">
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
      </div>
      <template v-if="authenticatedBackgroundJobs">
        <div v-if="authenticatedFolders.length > 0">
          {{ t(appName, 'List of additional folders needing authentication') }}
        </div>
        <ul>
          <ListItem v-for="folder of authenticatedFolders"
                    :key="folder"
                    :title="folder"
                    :bold="false"
          >
            <template #icon>
              <FolderIcon />
            </template>
            <template #actions>
              <NcActionButton @click="() => removeAuthenticatedFolder(folder)">
                <template #icon>
                  <DeleteIcon />
                </template>
              </NcActionButton>
            </template>
          </ListItem>
        </ul>
        <NcButton aria-label="t(appName, 'Add a Folder')"
                  type="primary"
                  @click="addAuthenticatedFolder"
        >
          <template #icon>
            <PlusIcon />
          </template>
          {{ t(appName, 'Add a Folder') }}
        </NcButton>
        <div class="hint">
          {{ t(appName, 'Subfolders are taken into account, you only need to specify the top-most folders.') }}
        </div>
      </template>
    </NcSettingsSection>
    <NcSettingsSection id="custom-converter-scripts"
                       :name="t(appName, 'Custom Converter Scripts')"
    >
      <div :class="['flex-container', 'flex-center']">
        <input id="disable-builtin-converters"
               v-model="disableBuiltinConverters"
               type="checkbox"
               :disabled="loading"
               @change="saveSetting('disableBuiltinConverters')"
        >
        <label for="disable-builtin-converters">
          {{ t(appName, 'Disable the builtin converters.') }}
        </label>
      </div>
      <SettingsInputText v-model="universalConverter"
                         :label="t(appName, 'Universal Converter')"
                         :hint="t(appName, 'Full path to a filter program to be executed first for all files. If it fails, the other converters will be tried in turn.')"
                         :disabled="loading"
                         @update="saveTextInput(...arguments, 'universalConverter')"
      />
      <SettingsInputText v-model="fallbackConverter"
                         :label="t(appName, 'Fallback Converter')"
                         :hint="t(appName, 'Full path to a filter program to be run when all other filters have failed. If it fails an error page will be substituted for the failing document.')"
                         :disabled="loading || builtinConvertersDisabled"
                         @update="saveTextInput(...arguments, 'fallbackConverter')"
      />
    </NcSettingsSection>
    <NcSettingsSection id="converters"
                       :name="t(appName, 'Converters')"
    >
      <div class="converter-status">
        <div><label>{{ t(appName, 'Status of the configured Converters') }}</label></div>
        <ul>
          <ListItem v-for="(value, mimeType) in converters"
                    :key="mimeType"
                    :title="mimeType"
                    :details="value.length > 1 ? t(appName, 'converter chain') : t(appName, 'single converter')"
                    :bold="true"
          >
            <template #subtitle>
              <ul>
                <ListItem v-for="(items, index) in value"
                          :key="index"
                          :title="Object.values(items).length > 1 ? t(appName, 'alternatives') : t(appName, 'converter')"
                          :show-counter="value.length > 1"
                          :counter-number="value.length > 1 ? index + 1 : 0"
                >
                  <template #subtitle>
                    <ListItem v-for="(executable, converter) in items"
                              :key="converter"
                              title=""
                              :details="items.length > 1 ? t(appName, 'converter') : ''"
                    >
                      <template #subtitle>
                        <span>{{ converter }}: {{ executable }}</span>
                      </template>
                    </ListItem>
                  </template>
                </ListItem>
              </ul>
            </template>
          </ListItem>
        </ul>
      </div>
    </NcSettingsSection>
  </div>
</template>

<script>
import { appName } from './config.js'
import {
  NcActionButton,
  NcButton,
  NcSettingsSection,
} from '@nextcloud/vue'
import SettingsInputText from '@rotdrop/nextcloud-vue-components/lib/components/SettingsInputText.vue'
import ListItem from '@rotdrop/nextcloud-vue-components/lib/components/ListItem.vue'
import {
  getFilePickerBuilder,
  FilePickerType,
  // showError,
  // showSuccess,
  // showInfo,
  // TOAST_PERMANENT_TIMEOUT,
} from '@nextcloud/dialogs'
import settingsSync from './toolkit/mixins/settings-sync.js'
import cloudVersionClasses from './toolkit/util/cloud-version-classes.js'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'

export default {
  name: 'AdminSettings',
  components: {
    DeleteIcon,
    FolderIcon,
    ListItem,
    NcActionButton,
    NcButton,
    NcSettingsSection,
    PlusIcon,
    SettingsInputText,
  },
  mixins: [
    settingsSync,
  ],
  data() {
    return {
      cloudVersionClasses,
      extractArchiveFiles: false,
      archiveSizeLimit: null,
      humanArchiveSizeLimit: '',
      disableBuiltinConverters: false,
      universalConverter: '',
      fallbackConverter: '',
      authenticatedBackgroundJobs: false,
      authenticatedFolders: [],
      converters: {},
      dependencies: {
        missing: {
          required: 0,
          suggested: 0,
        },
        required: {},
        suggested: {},
      },
      tooltips: {
        authenticatedBackgroundJobs: t(appName, 'If unsure keep this disabled. Enabling this option leads to an additional directory scan prior to scheduling a background operation. If the scan detects a mount point in the directory which has been mounted with the "authenticated" mount option then your login credentials will be temporarily promoted to the background job. This is primarily used to handle special cases which should only concern the author of this package. Keep the option disabled unless you really know what it means and you really known that you need it.'),
      },
      loading: true,
    }
  },
  computed: {
    builtinConvertersDisabled() {
      return !!this.disableBuiltinConverters
    },
  },
  watch: {},
  created() {
    this.getData()
  },
  methods: {
    info() {
      console.info('ADMIN SETTINGS', ...arguments)
    },
    async getData() {
      // slurp in all settings
      await this.fetchSettings('admin')
      this.loading = false
    },
    async saveTextInput(value, settingsKey, force) {
      if (await this.saveConfirmedSetting(value, 'admin', settingsKey, force)) {
        if (settingsKey.endsWith('Converter')) {
          this.fetchSetting('converters', 'admin')
        }
      }
    },
    async saveSetting(setting) {
      if (await this.saveSimpleSetting(setting, 'admin')) {
        if (setting === 'disableBuiltinConverters') {
          this.fetchSetting('converters', 'admin')
        }
      }
    },
    async addAuthenticatedFolder() {
      const picker = getFilePickerBuilder(t(appName, 'Choose a folder requiring authentication'))
        .startAt('/')
        .setMultiSelect(true)
        .setModal(true)
        .setType(FilePickerType.Choose)
        .setMimeTypeFilter(['httpd/unix-directory'])
        .allowDirectories()
        .build()
      const directories = await picker.pick()
      for (let dir of directories) {
        if (dir.startsWith('//')) { // new in Nextcloud 25?
          dir = dir.slice(1)
        }
        this.authenticatedFolders.push(dir)
      }
      await this.saveSetting('authenticatedFolders')
    },
    async removeAuthenticatedFolder(folder) {
      const index = this.authenticatedFolders.indexOf(folder)
      if (index >= 0) {
        this.authenticatedFolders.splice(index, 1)
      }
      await this.saveSetting('authenticatedFolders')
    },
  },
}
</script>
<style lang="scss" scoped>
.cloud-version {
  --cloud-theme-filter: var(--background-invert-if-dark);
  &.cloud-version-major-24 {
    --cloud-theme-filter: none;
  }
}
.templateroot {
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
  }
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
      top: 0;
      left: 0;
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
  :deep(.settings-section) {
    margin-bottom: 40px;
  }
  .hint {
    color: var(--color-text-lighter);
    font-size: 80%;
  }
}
</style>
