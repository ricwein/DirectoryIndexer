<template>
  <td class="file-size" :key="loaded">
    <div v-if="asyncError !== null" v-bind:title="asyncError">
      <div class="inline-flex items-center bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full dark:bg-red-900 dark:text-red-300">
        error
      </div>
    </div>
    <div v-else-if="fileSize !== null">
      <span class="font-bold mr-1">{{ fileSize.number }}</span>
      <span class="italic">{{ fileSize.unit }}</span>
    </div>
    <div v-else>
      <fwb-spinner color="white" size="6"/>
    </div>
  </td>
</template>

<script>
import {FwbSpinner} from "flowbite-vue";
import FileSize from '@/models/file-size';

export default {
  components: {FwbSpinner},
  props: {
    url: {type: String, required: true},
    size: {type: FileSize, required: false},
  },
  data: () => ({loaded: false, asyncSize: null, asyncError: null}),
  computed: {
    fileSize: function () {
      return this.size ?? this.asyncSize;
    },
  },
  created() {
    if (this.size) {
      this.loaded = true;
    } else {
      this.fetchSizeData();
    }
  },
  methods: {
    fetchSizeData: function () {
      fetch(`${this.url}?attr=size`, {method: 'OPTIONS'})
          .then(response => {
            if (!response.ok) {
              throw Error(response.statusText);
            }
            return response.json()
          })
          .then(json => {
            this.loaded = true;
            this.asyncSize = new FileSize(json);
          })
          .catch(err => {
            this.asyncError = err
            console.error(err)
          })
    }
  }
}
</script>

<style scoped>
</style>
