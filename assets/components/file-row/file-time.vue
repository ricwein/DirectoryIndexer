<template>
  <td class="file-time">
    <span v-if="isBefore(mTimeMoment, 1, 'day')"
          class="
            bg-cyan-100 text-cyan-600 border-cyan-600
            dark:bg-cyan-950 dark:text-cyan-400 dark:border-cyan-400
          "
    >{{ mTimeMoment.format('DD.MM.YYYY') }}</span>
    <span v-else-if="isBefore(mTimeMoment, 1, 'week')"
          class="
            bg-sky-100 text-sky-600 border-sky-600
            dark:bg-sky-950 dark:text-sky-400 dark:border-sky-400
          "
    >{{ mTimeMoment.format('DD.MM.YYYY') }}</span>
    <span v-else-if="isBefore(mTimeMoment, 6, 'months')"
          class="
            bg-blue-100 text-blue-600 border-blue-600
            dark:bg-blue-950 dark:text-blue-400 dark:border-blue-400
          "
    >{{ mTimeMoment.format('DD.MM.YYYY') }}</span>
    <span v-else
          class="
            bg-gray-200 text-gray-800 border-gray-800
            dark:bg-gray-800 dark:text-gray-400 dark:border-gray-400
          "
    >{{ mTimeMoment.format('DD.MM.YYYY') }}</span>
  </td>
</template>

<script>
import moment from "moment";
import File from "@/models/file";

export default {
  props: {
    file: {type: File, required: true},
  },
  computed: {
    mTimeMoment: function () {
      return moment.unix(this.file.mTime)
    }
  },
  methods: {
    isBefore: (compare, quantity, unit) => moment().subtract(quantity, unit).isBefore(compare)
  }
}
</script>

<style scoped>
</style>

