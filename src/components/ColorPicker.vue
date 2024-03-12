<!--
 - @author Claus-Justus Heine <himself@claus-justus-heine.de>
 - @copyright 2022, 2024 Claus-Justus Heine
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
  <ColorPickerExtension ref="wrappedComponent"
                        v-bind="$attrs"
                        :label="label"
                        :component-labels="componentLabels"
                        v-on="$listeners"
  />
</template>
<script>
import { appName } from '../config.js'
import ColorPickerExtension from '@rotdrop/nextcloud-vue-components/lib/components/ColorPickerExtension.vue'

export default {
  // BIG FAT NOTE: THIS IS NOT PURELY COSMETIC; SO GIVE A DAMN ON
  // ESLINT AND ENFORCE A DIFFERING COMPONENT NAME.
  //
  // eslint-disable-next-line vue/match-component-file-name
  name: 'ColorPickerExtensionWrapper',
  components: {
    ColorPickerExtension,
  },
  inheritAttrs: false,
  props: {
    label: {
      type: String,
      default: '',
    },
    componentLabels: {
      type: Object,
      default: () => {
        return {
          openColorPicker: t(appName, 'open'),
          submitColorChoice: t(appName, 'submit'),
          revertColor: t(appName, 'revert'),
          openButton: t(appName, 'pick a color'),
          revertColorPalette: t(appName, 'restore palette'),
          resetColorPalette: t(appName, 'factory reset palette'),
        }
      },
    },
  },
  methods: {
    saveState() {
      this.$refs.wrappedComponent.saveState()
    },
  },
}
</script>
