 <!--
 - @copyright Copyright (c) 2022, 2023, 2024, 2025 Claus-Justus Heine <himself@claus-justus-heine.de>
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
  <div :class="['font-select-container', ...cloudVersionClasses]">
    <div class="label-container">
      <label v-if="label !== undefined" :for="id + '-font-select'">{{ label }}</label>
    </div>
    <div class="multiselect-wrapper">
      <SelectWithSubmitButton :id="id + '-font-select'"
                              ref="select"
                              v-model="fontObject"
                              v-bind="$attrs"
                              :options="fontsList"
                              class="fonts-select"
                              :options-limit="100"
                              :placeholder="placeholder"
                              :multiple="false"
                              label="fontName"
                              :disabled="disabled || loading"
                              :clearable="true"
                              :searchable="true"
                              :submit-button="false"
      >
        <template #option="option">
          <NcEllipsisedOption v-tooltip="fontInfoPopup(option, getFontSampleUri(option))"
                              :name="ncSelect ? String(option[ncSelect.localLabel]) : t(appName, 'undefined')"
                              :search="ncSelect ? ncSelect.search : t(appName, 'undefined')"
          />
        </template>
        <template #selected-option="option">
          <NcEllipsisedOption v-tooltip="fontInfoPopup(option, getFontSampleUri(option))"
                              :name="ncSelect ? String(option[ncSelect.localLabel]) : t(appName, 'undefined')"
                              :search="ncSelect ? ncSelect.search : t(appName, 'undefined')"
          />
        </template>
        <template #alignedAfter>
          <div v-if="fontSizeChooser" class="font-size-container">
            <input v-model="fontSize"
                   class="font-size"
                   type="number"
                   min="0"
                   max="128"
                   step="1"
                   dir="rtl"
                   :disabled="disabled || loading || !fontObject"
                   @change="emitFontSizeChange"
            >
            <span class="font-size-unit">pt</span>
          </div>
        </template>
      </SelectWithSubmitButton>
      <div v-show="loading" class="loading" />
    </div>
    <div v-if="fontObject" class="font-sample flex-container flex-center">
      <img :src="fontSampleSource">
    </div>
    <div v-if="hint !== undefined" class="hint">
      {{ hint }}
    </div>
  </div>
</template>
<script setup lang="ts">
import { appName } from '../config.ts'
import { translate as t } from '@nextcloud/l10n'
import SelectWithSubmitButton from '@rotdrop/nextcloud-vue-components/lib/components/SelectWithSubmitButton.vue'
import NcEllipsisedOption from '@nextcloud/vue/dist/Components/NcEllipsisedOption.js'
import { generateUrl as generateAppUrl } from '../toolkit/util/generate-url.ts'
import pangram from '../toolkit/util/pangram.ts'
import cloudVersionClassesImport from '../toolkit/util/cloud-version-classes.js'
import {
  computed,
  ref,
  watch,
} from 'vue'
import { v4 as uuidv4 } from 'uuid'
import type { FontDescriptor } from '../model/fonts.d.ts'
import type { NcSelect } from '@nextcloud/vue'

const props = withDefaults(defineProps<{
  modelValue?: FontDescriptor,
  disabled?: boolean,
  loading?: boolean,
  label?: string,
  hint?: string,
  placeholder?: string,
  fontsList?: FontDescriptor[],
  fontSizeChooser?: boolean,
  fontSampleText?: string,
  fontSampleSize?: number,
  fontSampleColor?: string,
  fontSampleFormat?: 'svg'|'png',
}>(), {
  modelValue: undefined,
  disabled: false,
  loading: true,
  label: undefined,
  hint: undefined,
  placeholder: t(appName, 'Select a Font'),
  fontsList: () => [],
  fontSizeChooser: true,
  fontSampleText: pangram,
  fontSampleSize: 12,
  fontSampleColor: '#000000',
  fontSampleFormat: 'svg',
})

const emit = defineEmits([
  'input',
  'update:modelValue',
])

const fontObject = ref<undefined|FontDescriptor>(undefined)

const fontSize = ref<undefined|number>(undefined)

const select = ref<null | typeof SelectWithSubmitButton>(null)

const ncSelect = computed(() => select.value?.ncSelect as (typeof NcSelect | null))

const id = computed<string>(() => uuidv4())

const cloudVersionClasses = computed<string[]>(() => cloudVersionClassesImport)

const fontSampleSource = computed(() => {
  if (!fontObject.value) {
    return ''
  }
  return getFontSampleUri(fontObject.value)
})

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const fontInfoPopup = (fontOption: FontDescriptor|any, sampleUri: string) => {
  // console.info('FONT OPTION', fontOption, sampleUri);
  const content = `<div class="font-family">${fontOption.fontName}</div><div class="font-sample"><img src="${sampleUri}"></div>`
  return {
    content,
    preventOverflow: true,
    html: true,
    // shown: true,
    // triggers: [],
    csstag: ['vue-tooltip-font-info-popup'],
  }
}

interface FontSampleOptions {
  text?: string,
  fontSize?: number,
  textColor?: string,
  format?: string,
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const getFontSampleUri = (fontObject: FontDescriptor|any, options?: FontSampleOptions) => {
  const text = encodeURIComponent(options?.text || props.fontSampleText)
  const fontSize = options?.fontSize || props.fontSampleSize
  const textColor = options?.textColor || props.fontSampleColor
  const format = options?.format || props.fontSampleFormat
  return generateAppUrl(
    'sample/font/{text}/{font}/{fontSize}', {
      text,
      fontSize,
      textColor,
      format,
      output: 'blob',
      font: encodeURIComponent(fontObject.family),
      hash: fontObject.fontHash,
    },
  )
}

const emitUpdate = (value) => {
  emit('input', value) // Vue 2
  emit('update:modelValue', value)
  emit('update:model-value', value)
  emit('update:value', value)
}

const emitFontSizeChange = () => {
  if (!fontObject.value) {
    return // no font no font size
  }
  emitUpdate({ ...fontObject.value, fontSize: fontSize.value })
}

watch(fontObject, (newValue, oldValue) => {
  if (newValue && oldValue
      && newValue.family === oldValue.family
      && newValue.fontSize === oldValue.fontSize
  ) {
    return
  }
  const value = newValue ? { ...newValue, fontSize: fontSize.value } : undefined
  emitUpdate(value)
})

watch(
  () => props.modelValue,
  (newValue: FontDescriptor|undefined, oldValue: FontDescriptor|undefined) => {
    if (newValue && oldValue
      && newValue.family === oldValue.family
      && newValue.fontSize === oldValue.fontSize) {
      return
    }
    fontObject.value = newValue
    if (fontObject.value && fontObject.value.fontSize !== fontSize.value) {
      fontSize.value = fontObject.value.fontSize
    }
  },
  { deep: true },
)

defineExpose({
  getFontSampleUri,
})

fontObject.value = props.modelValue
if (props.modelValue) {
  fontSize.value = props.modelValue.fontSize
}
</script>
<script lang="ts">
export default {
  name: 'FontSelect',
  inheritAttrs: false,
  model: {
    prop: 'modelValue',
    event: 'update:modelValue',
  },
}
</script>
<style lang="scss" scoped>
.cloud-version {
  --cloud-icon-checkmark: var(--icon-checkmark-dark);
  --cloud-input-height: 36px;
  --cloud-border-radius: var(--border-radius-large);
  --cloud-input-border-width: 2px;
  --cloud-input-border-color: var(--color-border-maxcontrast);
  --cloud-input-margin: 3px;
  --cloud-theme-filter: var(--background-invert-if-dark);
  &.cloud-version-major-24 {
    --cloud-icon-checkmark: var(--icon-checkmark-000);
    --cloud-input-height: 34px;
    --cloud-border-radius: var(--border-radius);
    --cloud-input-border-width: 1px;
    --cloud-input-border-color: var(--color-border-dark);
    --cloud-input-margin: 0;
    --cloud-theme-filter: none;
  }
}
.font-select-container {
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
  }
  input[type='text'], input[type='number'] {
    &:read-only {
      background-color: var(--color-background-dark) !important;
    }
  }
  .multiselect-wrapper {
    display: flex;
    flex-wrap: wrap;
    width: 100%;
    max-width: 400px;
    position:relative;
    align-items: center;
    .loading {
      position:absolute;
      width:0;
      height:0;
      top:50%;
      left:50%;
    }
    .font-size-container {
      display:flex;
      flex-wrap:nowrap;
      align-items:center;
      flex-shrink:0;
      input.font-size {
        margin: 0 3px;
        height: var(--default-clickable-area); // probably no longer necessary
        width: 3em;
        direction:rtl;
      }
    }
  } /* .multiselect-wrapper */
  .hint {
    color: var(--color-text-lighter);
    font-size: 80%;
  }
  .font-sample img {
    min-height:24px;
    filter: var(--cloud-theme-filter);
  }
}
</style>
<style lang="scss">
[csstag="vue-tooltip-font-info-popup"].v-popper--theme-tooltip {
  .v-popper__inner {
    max-width:unset!important;
    // min-width:1024px!important;
    .font-sample img {
      min-height:24px;
    }
  }
  img {
    filter: var(--background-invert-if-dark);
  }
}
</style>
