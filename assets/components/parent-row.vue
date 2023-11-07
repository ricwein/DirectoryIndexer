<template>
  <tr v-on:click="navigateToParent">
    <th scope="row" title="navigate to parent directory">
      <i class="fa-solid fa-arrow-turn-up fa-xl pl-3.5 pt-4 h-10 w-10 rounded-full bg-slate-800"></i>
      <div class="pl-3">
        <div class="text-base font-semibold">../</div>
      </div>
    </th>
    <td colspan="3"></td>
  </tr>
</template>

<script>
import {openUrl} from "@/modules/helper";

export default {
  name: "ParentRow",
  props: {
    url: {type: String, required: true},
  },
  methods: {
    navigateToParent: function (event) {
      openUrl(this.url, event);
    }
  }
}
</script>

<style scoped>
</style>
