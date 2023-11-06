<template>
  <tr v-on:click="openFile">
    <FileQuickview :file="fileObj" :url="url"/>
    <FileSize :size="sizeObj" :url="url"/>
    <FileTime :file="fileObj"></FileTime>
    <FileActions></FileActions>
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
      window.open(
          this.url,
          (event.metaKey || event.which === 2)
              ? '_target'
              : '_self'
      )
    }
  }
}
</script>

<style scoped>
</style>
