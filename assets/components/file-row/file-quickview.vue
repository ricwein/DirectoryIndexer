<template>
  <th scope="row">
    <i v-if="file.type === 'dir'"
       class="icon fa-solid fa-folder fa-xl bg-slate-800"
       :style="{width: `${previewSize}px`, height: `${previewSize}px`}"
    ></i>
    <img v-else-if="file.fileType === 'image'"
         v-bind:src="previewUrl"
         v-bind:alt="previewAlt"
         v-bind:width="previewSize"
         v-bind:height="previewSize"
    >
    <i v-else
       class="icon fa-solid fa-file fa-xl bg-slate-800"
       :style="{width: `${previewSize}px`, height: `${previewSize}px`}"
    ></i>

    <div class="pl-3">
      <div class="text-base font-semibold">{{ file.filename + (file.type === 'dir' ? '/' : '') }}</div>
      <div class="font-normal text-gray-400">{{ file.mime }}</div>
    </div>
  </th>
</template>

<script>
import File from "@/models/file";

export default {
  props: {
    url: {type: String, required: true},
    file: {type: File, required: true},
    previewSize: {type: Number, default: 40}
  },
  computed: {
    previewAlt: function () {
      return `Preview of ${this.file.filename}`;
    },
    previewUrl: function () {
      return `${this.url}?preview&wh=${this.previewSize}`
    }
  }
}
</script>

<style scoped>
</style>

