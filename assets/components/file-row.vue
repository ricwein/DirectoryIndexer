<template>
  <tr
      v-on:click="openFile"
      @mouseover="isHovering=true"
      @mouseout="isHovering=false"
      @click="isClicked=true"
  >
    <FileQuickview :file="fileObj" :url="url" :is-hovering="isHovering" :is-clicked="isClicked"/>
    <FileSize :size="sizeObj" :url="url"/>
    <FileTime :file="fileObj"/>
    <FileActions :file="fileObj" :size="sizeObj" :hashes="hashesObj" :url="url"/>
  </tr>
</template>

<script>
import FileQuickview from "@/components/file-row/file-quickview.vue";
import FileSize from "@/components/file-row/file-size.vue";
import FileTime from "@/components/file-row/file-time.vue";
import FileActions from "@/components/file-row/file-actions.vue";

import FileModel from "@/models/file";
import FileSizeModel from "@/models/file-size";
import FileHashesModel from "@/models/file-hashes";
import {openUrl} from "@/modules/helper";

export default {
  name: "FileRow",
  components: {FileActions, FileTime, FileQuickview, FileSize},
  props: {
    url: {type: String, required: true},
    file: {type: Object, required: true},
    size: {type: Object, required: false},
    hashes: {type: Object, required: false},
    previewSize: {type: Number, default: 40}
  },
  data: () => ({isHovering: false, isClicked: false}),
  computed: {
    fileObj: function () {
      // noinspection JSCheckFunctionSignatures
      return new FileModel(this.file)
    },
    sizeObj: function () {
      // noinspection JSCheckFunctionSignatures
      return this.size ? new FileSizeModel(this.size) : null;
    },
    hashesObj: function () {
      // noinspection JSCheckFunctionSignatures
      return this.hashes ? new FileHashesModel(this.hashes) : null;
    },
  },
  methods: {
    openFile: function (event) {
      openUrl(this.url, event);
    }
  }
}
</script>

<style scoped>
</style>
