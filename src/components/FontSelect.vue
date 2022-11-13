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
  <div class="font-select-container">
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
          />
        </template>
        <template #singleLabel="singleLabelData">
          <span v-tooltip="fontInfoPopup(singleLabelData.option)">
            {{ $refs.fontSelect.$refs.VueMultiselect.currentOptionLabel }}
          </span>
        </template>
      </MultiSelect>
      <div v-show="loading" class="loading" />
    </div>
    <div v-if="fontObject" class="font-sample flex flex-center">
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
import fontInfoPopup from '../mixins/font-info-popup'
import generateUrl from '../util/generate-url.js'

export default {
  name: 'FontSelect',
  components: {
    MultiSelect,
    EllipsisedFontOption,
  },
  data() {
    return {
      fontObject: null,
      // loading: true,
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
    fontSampleText: {
      type: String,
      default: t(appName, 'The quick brown fox jumps over the lazy dog.'),
    },
    fontSampleSize: {
      type: Number,
      default: 18,
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
      return generateUrl(
        'pdf/fonts/sample/{text}/{font}/{fontSize}', {
          text: encodeURIComponent(this.fontSampleText),
          font: encodeURIComponent(this.fontObject.family),
          fontSize: this.fontSampleSize,
          format: this.fontSampleFormat,
          output: 'blob',
        },
      )
    },
  },
  watch: {
    fontObject(newValue) {
      this.$emit('input', newValue) // Vue 2
      this.$emit('update:modelValue', newValue) // Vue 3
    },
    value(newVal) {
      console.info('VALUE CHANGED', newVal)
      this.fontObject = newVal
    },
  },
  created() {
    console.info('VALUE' , this.value)
    this.fontObject = this.value
  },
  methods: {
    emitInput() {
      this.emit('input')
      this.emitUpdate()
    },
    emitUpdate() {
      this.emit('update')
    },
    emit(event) {
      this.$emit(event, this.fontObject)
    },
  },
}
</script>
<style lang="scss" scoped>
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
      height:34px !important;
      flex-grow:1;
      &:hover .multiselect__tags {
        border-color: var(--color-primary-element);
        outline: none;
      }
     .multiselect__content-wrapper li > span {
        &::before {
          background-image: var(--icon-checkmark-000);
          display:block;
        }
        &:not(.multiselect__option--selected):hover::before {
          visibility:hidden;
        }
      }
    }
  }
  .hint {
    color: var(--color-text-lighter);
    font-size: 80%;
  }
}
</style>
