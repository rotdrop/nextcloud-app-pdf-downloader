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
  <div :class="['font-select-container', ...cloudVersionClasses]">
    <div class="label-container">
      <label v-if="label !== undefined" :for="id + '-font-select'">{{ label }}</label>
    </div>
    <div class="multiselect-wrapper">
      <MultiSelect :id="id + '-font-select'"
                   ref="fontSelect"
                   v-model="fontObject"
                   v-bind="$attrs"
                   :value="fontObject"
                   :options="fontsList"
                   class="fonts-select multiselect-vue"
                   :placeholder="placeholder"
                   :show-labels="true"
                   :allow-empty="true"
                   :searchable="true"
                   :close-on-select="true"
                   track-by="family"
                   label="fontName"
                   :multiple="false"
                   :tag-width="60"
                   :disabled="disabled || loading"
      >
        <template #option="optionData">
          <EllipsisedFontOption :name="$refs.fontSelect.getOptionLabel(optionData.option)"
                                :option="optionData.option"
                                :search="optionData.search"
                                :label="$refs.fontSelect.label"
                                :sample-uri="getFontSampleUri(optionData.option)"
          />
        </template>
        <template #singleLabel="singleLabelData">
          <span v-tooltip="fontInfoPopup(singleLabelData.option, getFontSampleUri(singleLabelData.option))"
                class="single-label"
          >
            {{ $refs.fontSelect.$refs.VueMultiselect.currentOptionLabel }}
          </span>
        </template>
      </MultiSelect>
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
<script>
import { appName } from '../config.js'
import MultiSelect from '@nextcloud/vue/dist/Components/Multiselect'
import EllipsisedFontOption from './EllipsisedFontOption'
import fontInfoPopup from './mixins/font-info-popup'
import generateUrl from '../toolkit/util/generate-url.js'
import fontSampleText from '../toolkit/util/pangram.js'

const cloudVersion = OC.config.versionstring.split('.')
const cloudVersionClasses = [
  'cloud-version',
  'cloud-version-major-' + cloudVersion[0],
  'cloud-version-minor-' + cloudVersion[1],
  'cloud-version-patch-' + cloudVersion[2],
]

export default {
  name: 'FontSelect',
  components: {
    MultiSelect,
    EllipsisedFontOption,
  },
  data() {
    return {
      fontObject: null,
      fontSize: null,
      // loading: true,
      cloudVersionClasses,
    };
  },
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
      default: [],
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
  mixins: [
    fontInfoPopup,
  ],
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
          this.$emit('input', { ...newValue, fontSize: this.fontSize, }) // Vue 2
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
  methods: {
    info() {
      console.info(...arguments)
    },
    getFontSampleUri(fontObject, options) {
      options = options || {}
      const fontSampleText = options.text || this.fontSampleText
      const fontSampleSize = options.fontSize || this.fontSampleSize
      const fontSampleColor = options.textColor || this.fontSampleColor
      const fontSampleFormat = options.format || this.fontSampleFormat
      return generateUrl(
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
      this.$emit('input', { ...this.fontObject, fontSize: this.fontSize, }) // Vue 2
    },
  },
}
</script>
<style lang="scss" scoped>
.cloud-version {
  --cloud-icon-checkmark: var(--icon-checkmark-000);
  --cloud-input-height: 34px;
  --cloud-border-radius: var(--border-radius);
  --cloud-input-border-width: 1px;
  --cloud-input-border-color: var(--color-border-dark);
  --cloud-input-margin: 0;
  &.cloud-version-major-25 {
    --cloud-icon-checkmark: var(--icon-checkmark-dark);
    --cloud-input-height: 36px;
    --cloud-border-radius: var(--border-radius-large);
    --cloud-input-border-width: 2px;
    --cloud-input-border-color: var(--color-border-maxcontrast);
    --cloud-input-margin: 3px;
  }
}
.font-select-container {
  .flex-container {
    display:flex;
    &.flex-center {
      align-items:center;
    }
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
      height: var(--cloud-input-height) !important;
      margin-top: var(--cloud-input-margin);
      margin-bottom: var(--cloud-input-margin);
      flex-grow:1;
      .multiselect__content-wrapper {
        border: var(--cloud-input-border-width) solid var(--cloud-input-border-color);
      }
      .multiselect__tags {
        border: var(--cloud-input-border-width) solid var(--cloud-input-border-color);
        border-radius: var(--cloud-border-radius);
        .multiselect__single .single-label {
          width:100%;
        }
      }
      &.multiselect--active {
        .multiselect__tags {
          border-radius: var(--cloud-border-radius) var(--cloud-border-radius) 0 0;
        }
        .multiselect__content-wrapper {
          border-radius: 0 0 var(--cloud-border-radius) var(--cloud-border-radius);
        }
        &.multiselect--above {
          .multiselect__tags {
            border-radius: 0 0 var(--cloud-border-radius) var(--cloud-border-radius);
          }
          .multiselect__content-wrapper {
            border-radius: var(--cloud-border-radius) var(--cloud-border-radius) 0 0;
          }
        }
      }
      &:hover .multiselect__tags {
        border-color: var(--color-primary-element);
        outline: none;
      }
     .multiselect__content-wrapper li > span {
        &::before {
          background-image: var(--cloud-icon-checkmark);
          display:block;
        }
        &:not(.multiselect__option--selected):hover::before {
          visibility:hidden;
        }
      }
    }
    .font-size-container {
      input.font-size {
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
  }
}
</style>
<style lang="scss">
.vue-tooltip.vue-tooltip-font-info-popup {
  &, .tooltip-inner {
    max-width:unset!important;
    .font-sample img {
      min-height:24px;
    }
  }
}
body[data-themes*="dark"] {
  .font-select-container .font-sample,
  .vue-tooltip.vue-tooltip-font-info-popup {
    img {
      filter: Invert();
    }
  }
}
</style>
