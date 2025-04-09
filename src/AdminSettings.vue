<!--
 - @copyright Copyright (c) 2022, 2023, 2024, 2025 Claus-Justus Heine <himself@claus-justus-heine.de>
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
    <NcSettingsSection v-if="settings.dependencies.missing.required + settings.dependencies.missing.suggested > 0"
                       id="missing-dependencies"
                       :name="t(appName, 'Missing Dependencies')"
    >
      <div v-if="settings.dependencies.missing.required > 0" class="required-dependencies">
        <div><label>{{ t(appName, 'Required Missing') }}</label></div>
        <ul>
          <ListItem v-for="(path, program) in settings.dependencies.required"
                    :key="program"
                    :name="program"
                    :details="path"
                    :bold="false"
          >
            <template #subname>
              <div class="hint">
                {{ t(appName, 'The app will not work unless you install {program} such that it can be found by the web-server.', { program }) }}
              </div>
            </template>
          </ListItem>
        </ul>
      </div>
      <div v-if="settings.dependencies.missing.suggested > 0" class="suggested-dependencies">
        <div><label>{{ t(appName, 'Suggested Missing') }}</label></div>
        <ul>
          <ListItem v-for="(path, program) in settings.dependencies.suggested"
                    :key="program"
                    :name="program"
                    :details="path"
                    :bold="false"
          >
            <template #subname>
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
      <div :class="['flex-container', 'flex-center', { extractArchiveFiles: settings.extractArchiveFiles }]">
        <input id="extract-archive files"
               v-model="settings.extractArchiveFiles"
               type="checkbox"
               :disabled="loading"
               @change="saveSetting('extractArchiveFiles')"
        >
        <label for="extract-archive files">
          {{ t(appName, 'On-the-fly extraction of archive files. If enabled users can control this setting on a per-user basis.') }}
        </label>
      </div>
      <TextField :value.sync="settings.humanArchiveSizeLimit"
                 :label="t(appName, 'Archive Size Limit')"
                 :hint="t(appName, 'Disallow archive extraction for archives with decompressed size larger than this limit.')"
                 :disabled="loading || !settings.extractArchiveFiles"
                 @submit="saveTextInput('archiveSizeLimit')"
      />
    </NcSettingsSection>
    <NcSettingsSection id="authenticated-background-jobs"
                       :name="t(appName, 'Authenticated Background Jobs')"
    >
      <div :class="['flex-container', 'flex-center']">
        <input id="authenticated-background-jobs"
               v-model="settings.authenticatedBackgroundJobs"
               type="checkbox"
               :disabled="loading"
               @change="saveSetting('authenticatedBackgroundJobs')"
        >
        <label v-tooltip="tooltips.authenticatedBackgroundJobs"
               for="authenticated-background-jobs"
        >
          {{ t(appName, 'Use authenticated background jobs if necessary.') }}
        </label>
      </div>
      <template v-if="settings.authenticatedBackgroundJobs">
        <div v-if="settings.authenticatedFolders.length > 0">
          {{ t(appName, 'List of additional folders needing authentication') }}
        </div>
        <ul>
          <ListItem v-for="folder of settings.authenticatedFolders"
                    :key="folder"
                    :name="folder"
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
               v-model="settings.disableBuiltinConverters"
               type="checkbox"
               :disabled="loading"
               @change="saveSetting('disableBuiltinConverters')"
        >
        <label for="disable-builtin-converters">
          {{ t(appName, 'Disable the builtin converters.') }}
        </label>
      </div>
      <TextField :value.sync="settings.universalConverter"
                 :label="t(appName, 'Universal Converter')"
                 :hint="t(appName, 'Full path to a filter program to be executed first for all files. If it fails, the other converters will be tried in turn.')"
                 :disabled="loading"
                 @submit="saveTextInput('universalConverter')"
      />
      <TextField :value.sync="settings.fallbackConverter"
                 :label="t(appName, 'Fallback Converter')"
                 :hint="t(appName, 'Full path to a filter program to be run when all other filters have failed. If it fails an error page will be substituted for the failing document.')"
                 :disabled="loading || !!settings.disableBuiltinConverters"
                 @submit="saveTextInput('fallbackConverter')"
      />
    </NcSettingsSection>
    <NcSettingsSection id="converters"
                       :name="t(appName, 'Converters')"
    >
      <div class="converter-status">
        <div><label>{{ t(appName, 'Status of the configured Converters') }}</label></div>
        <ul>
          <ListItem v-for="(chainAlternative, chainIndex) in settings.converters"
                    :key="chainIndex"
                    class="mime-type"
                    :name="chainAlternative.mimeType"
                    :details="chainAlternative.chain.length > 1 ? t(appName, 'converter chain') : t(appName, 'single converter')"
                    :bold="true"
          >
            <template #subname>
              <ul>
                <ListItem v-for="(items, index) in chainAlternative.chain"
                          :key="index"
                          :name="Object.values(items).length > 1 ? t(appName, 'alternatives') : t(appName, 'converter')"
                          :show-counter="chainAlternative.chain.length > 1"
                          :counter-number="chainAlternative.chain.length > 1 ? index + 1 : 0"
                >
                  <template #subname>
                    <ListItem v-for="(executable, converter) in items"
                              :key="converter"
                              name=""
                              :details="Object.values(items).length > 1 ? t(appName, 'converter') : ''"
                    >
                      <template #subname>
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
<script setup lang="ts">
import { appName } from './config.ts'
import {
  NcActionButton,
  NcButton,
  NcSettingsSection,
  NcListItem as ListItem,
} from '@nextcloud/vue'
import { translate as t } from '@nextcloud/l10n'
import TextField from '@rotdrop/nextcloud-vue-components/lib/components/TextFieldWithSubmitButton.vue'
import {
  getFilePickerBuilder,
  FilePickerType,
  // showError,
  // showSuccess,
  // showInfo,
  // TOAST_PERMANENT_TIMEOUT,
} from '@nextcloud/dialogs'
import cloudVersionClassesImport from './toolkit/util/cloud-version-classes.js'
import {
  fetchSettings,
  fetchSetting,
  saveConfirmedSetting,
  saveSimpleSetting,
} from './toolkit/util/settings-sync.ts'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import {
  computed,
  reactive,
  ref,
} from 'vue'

const cloudVersionClasses = computed<string[]>(() => cloudVersionClassesImport)
const loading = ref(true)
const settings = reactive({
  extractArchiveFiles: false,
  archiveSizeLimit: null,
  humanArchiveSizeLimit: '',
  disableBuiltinConverters: false,
  universalConverter: '',
  fallbackConverter: '',
  authenticatedBackgroundJobs: false,
  authenticatedFolders: [] as string[],
  converters: {} as Record<string, Record<string, string>[]>,
  dependencies: {
    missing: {
      required: 0,
      suggested: 0,
    },
    required: {},
    suggested: {},
  },
})

const tooltips = computed(() => ({
  authenticatedBackgroundJobs: t(appName, 'If unsure keep this disabled. Enabling this option leads to an additional directory scan prior to scheduling a background operation. If the scan detects a mount point in the directory which has been mounted with the "authenticated" mount option then your login credentials will be temporarily promoted to the background job. This is primarily used to handle special cases which should only concern the author of this package. Keep the option disabled unless you really know what it means and you really known that you need it.'),
}))

// slurp in all settings
const getData = async () => {
  return fetchSettings({ section: 'admin', settings }).finally(() => {
    loading.value = false
  })
}
getData()

const saveTextInput = async (settingsKey: string, value?: string, force?: boolean) => {
  if (value === undefined) {
    value = settings[settingsKey] || ''
  }
  if (await saveConfirmedSetting({ value, section: 'admin', settingsKey, force, settings })) {
    if (settingsKey.endsWith('Converter')) {
      fetchSetting({ settingsKey: 'converters', section: 'admin', settings })
    }
  }
}

const saveSetting = async (settingsKey: string) => {
  if (await saveSimpleSetting({ settingsKey, section: 'admin', settings })) {
    if (settingsKey === 'disableBuiltinConverters') {
      fetchSetting({ settingsKey: 'converters', section: 'admin', settings })
    }
  }
}

const addAuthenticatedFolder = async () => {
  const picker = getFilePickerBuilder(t(appName, 'Choose a folder requiring authentication'))
    .startAt('/')
    .setMultiSelect(true)
    .setType(FilePickerType.Choose)
    .setMimeTypeFilter(['httpd/unix-directory'])
    .allowDirectories()
    .build()
  const directories: string[] = await picker.pick()
  for (let dir of directories) {
    if (dir.startsWith('//')) { // new in Nextcloud 25?
      dir = dir.slice(1)
    }
    settings.authenticatedFolders.push(dir)
  }
  await saveSetting('authenticatedFolders')
}

const removeAuthenticatedFolder = async (folder: string) => {
  const index = settings.authenticatedFolders.indexOf(folder)
  if (index >= 0) {
    settings.authenticatedFolders.splice(index, 1)
  }
  await saveSetting('authenticatedFolders')
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
  :deep(.converter-status) {
    .list-item__anchor {
      height: auto;
    }
    .mime-type .list-item-content__details {
      justify-content: start;
    }
  }
}
</style>
