<template>
  <td class="file-actions">
    <button
        @click="showModal"
        type="button"
        class="w-full h-20 py-2 px-3 hover:bg-gray-800 border-gray-800 hover:border-blue-400 transition duration-150 text-white text-sm font-semibold focus:outline-none"
    >
      <i class="fa-solid fa-eye"></i>
      show info
    </button>
  </td>
  <Teleport to="body">
    <fwb-modal v-if="isShowModal" @close="closeModal" size="2xl">
      <template #header class="bg-blue-900">
        <table class="text-sm text-left text-gray-500 dark:text-gray-400">
          <thead class="text-xs text-gray-700 bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
          <tr>
            <th class="pl-20 pr-5 text-right uppercase">File</th>
            <th class="pr-20 pl-5 font-bold truncate max-w-2xl text-lg">{{ file.filename }}</th>
          </tr>
          </thead>
        </table>
      </template>

      <template #body>
        <table class="text-sm text-left text-gray-500 dark:text-gray-400">
          <tbody>
          <tr class="bg-white dark:bg-gray-800 dark:border-gray-700">
            <td class="pl-20 pr-5 py-6 text-right uppercase">Size</td>
            <td class="pr-20 pl-5 py-6 font-bold" title="{{ fileSize.bytes }} B">
              <span class="font-bold">{{ fileSize.number }}</span>
              <span class="italic">{{ fileSize.unit }}</span>
            </td>
          </tr>
          <tr class="bg-white dark:bg-gray-800 dark:border-gray-700">
            <td class="pl-20 pr-5 py-6 text-right uppercase">MD5</td>
            <td class="pr-20 pl-5 py-6 font-bold">{{ fileHashes.md5 }}</td>
          </tr>
          <tr class="bg-white dark:bg-gray-800 dark:border-gray-700">
            <td class="pl-20 pr-5 py-6 text-right uppercase">SHA1</td>
            <td class="pr-20 pl-5 py-6 font-bold">{{ fileHashes.sha1 }}</td>
          </tr>
          <tr class="bg-white dark:bg-gray-800 dark:border-gray-700">
            <td class="pl-20 pr-5 py-6 text-right uppercase">SHA256</td>
            <td class="pr-20 pl-5 py-6 font-bold">{{ fileHashes.sha256 }}</td>
          </tr>
          <tr class="bg-white dark:bg-gray-800 dark:border-gray-700">
            <td class="pl-20 pr-5 py-6 text-right uppercase">SHA512</td>
            <td class="pr-20 pl-5 py-6 font-bold">{{ fileHashes.sha512 }}</td>
          </tr>
          </tbody>
        </table>
      </template>
    </fwb-modal>
  </Teleport>
</template>

<script>
import {FwbModal} from "flowbite-vue";
import FileSize from '@/models/file-size';
import FileHashes from "@/models/file-hashes";
import File from "@/models/file";

export default {
  components: {FwbModal},
  props: {
    url: {type: String, required: true},
    file: {type: File, required: false},
    size: {type: FileSize, required: false},
    hashes: {type: FileHashes, required: false},
  },
  data: () => ({isShowModal: false, asyncSize: null, asyncHashes: null}),
  computed: {
    fileSize: function () {
      return this.size ?? this.asyncSize;
    },
    fileHashes: function () {
      return this.hashes ?? this.asyncHashes;
    },
  },
  created() {
    if (this.hashes === null) {
      this.fetchHashData();
    }
    if (this.size === null) {
      this.fetchSizeData();
    }
  },
  methods: {
    showModal: function (event) {
      event.preventDefault();
      event.stopPropagation();
      this.isShowModal = true;
    },
    closeModal: function () {
      this.isShowModal = false;
    },
    fetchSizeData: function () {
      fetch(`${this.url}?attr=size`, {method: 'OPTIONS'})
          .then((response) => response.json().then((data) => {
            this.asyncSize = new FileSize(data);
          }))
          .catch(err => console.error(err))
    },
    fetchHashData: function () {
      fetch(`${this.url}?attr=hashes`, {method: 'OPTIONS'})
          .then((response) => response.json().then((data) => {
            this.asyncHashes = new FileHashes(data);
          }))
          .catch(err => console.error(err))
    }
  }
}
</script>

<style scoped>
</style>
