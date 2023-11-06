<template>
  <td class="file-size" :key="loaded">
    <div v-if="fileSize">
      <span class="font-bold">{{ fileSize.number }}</span>
      <span class="italic">{{ fileSize.unit }}</span>
    </div>
    <div v-else>
      <fwb-spinner color="white" size="6"/>
    </div>
  </td>
</template>

<script>
import {FwbSpinner} from "flowbite-vue";
import FileSize from '../../models/file-size';

export default {
  components: {FwbSpinner},
  props: {
    url: {type: String, required: true},
    size: {type: FileSize, required: false},
  },
  data: () => ({loaded: false, asyncSize: null}),
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
          .then((response) => response.json().then((data) => {
            this.loaded = true;
            this.asyncSize = new FileSize(data);
          }))
          .catch(err => console.error(err))
    }
  }
}
</script>

<style scoped>
</style>
