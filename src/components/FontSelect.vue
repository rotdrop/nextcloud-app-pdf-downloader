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
<script lang="ts">
import { appName } from '../config.ts'
import SelectWithSubmitButton from '@rotdrop/nextcloud-vue-components/lib/components/SelectWithSubmitButton.vue'
import NcEllipsisedOption from '@nextcloud/vue/dist/Components/NcEllipsisedOption.js'
import fontInfoPopup from './mixins/font-info-popup.js'
import { generateUrl as generateAppUrl } from '../toolkit/util/generate-url.js'
import fontSampleText from '../toolkit/util/pangram.js'
import cloudVersionClasses from '../toolkit/util/cloud-version-classes.js'

export default {
  name: 'FontSelect',
  components: {
    SelectWithSubmitButton,
    NcEllipsisedOption,
  },
  mixins: [
    fontInfoPopup,
  ],
  props: {
    value: {
      type: Object,
      default: () => {},
    },
    disabled: {
      type: Boolean,
      default: false,
    },
    loading: {
      type: Boolean,
      default: true,
    },
    label: {
      type: String,
      default: undefined,
    },
    hint: {
      type: String,
      default: undefined,
    },
    placeholder: {
      type: String,
      default: t(appName, 'Select a Font'),
    },
    fontsList: {
      type: Array,
      default: () => [],
    },
    fontSizeChooser: {
      type: Boolean,
      default: true,
    },
    fontSampleText: {
      type: String,
      default: fontSampleText,
    },
    fontSampleSize: {
      type: Number,
      default: 12,
    },
    fontSampleColor: {
      type: String,
      default: '#000000',
    },
    fontSampleFormat: {
      type: String,
      default: 'svg',
    },
  },
  emits: [
    'update:modelValue',
    'input',
  ],
  data() {
    return {
      fontObject: null,
      fontSize: null,
      // loading: true,
      cloudVersionClasses,
      ncSelect: null,
    }
  },
  computed: {
    id() {
      return 'font-select-' + this._uid
    },
    fontSampleSource() {
      if (!this.fontObject) {
        return ''
      }
      return this.getFontSampleUri(this.fontObject)
    },
  },
  watch: {
    fontObject: {
      handler(newValue, oldValue) {
        if (newValue && oldValue
            && newValue.family === oldValue.family
            && newValue.fontSize === oldValue.fontSize
        ) {
          return
        }
        if (newValue) {
          this.$emit('input', { ...newValue, fontSize: this.fontSize }) // Vue 2
        } else {
          this.$emit('input', newValue)
        }
        // this.$emit('update:modelValue', newValue) // Vue 3
      },
      deep: true,
    },
    value: {
      handler(newValue, oldValue) {
        if (newValue && oldValue
            && newValue.family === oldValue.family
            && newValue.fontSize === oldValue.fontSize) {
          return
        }
        this.fontObject = newValue
        if (this.fontObject && this.fontObject.fontSize !== this.fontSize) {
          this.fontSize = this.fontObject.fontSize
        }
      },
      deep: true,
    },
  },
  created() {
    this.fontObject = this.value
    if (this.value) {
      this.fontSize = this.value.fontSize
    }
  },
  mounted() {
    this.ncSelect = this.$refs.select.ncSelect
  },
  methods: {
    info(...rest) {
      console.info(...rest)
    },
    getFontSampleUri(fontObject, options) {
      options = options || {}
      const fontSampleText = options.text || this.fontSampleText
      const fontSampleSize = options.fontSize || this.fontSampleSize
      const fontSampleColor = options.textColor || this.fontSampleColor
      const fontSampleFormat = options.format || this.fontSampleFormat
      return generateAppUrl(
        'sample/font/{text}/{font}/{fontSize}', {
          text: encodeURIComponent(fontSampleText),
          font: encodeURIComponent(fontObject.family),
          fontSize: fontSampleSize,
          textColor: fontSampleColor,
          format: fontSampleFormat,
          output: 'blob',
          hash: fontObject.fontHash,
        },
      )
    },
    emitFontSizeChange() {
      if (!this.fontObject) {
        return // no font no font size
      }
      this.$emit('input', { ...this.fontObject, fontSize: this.fontSize }) // Vue 2
    },
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
        height: 44px!important; // in order to have the same height as the select
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
