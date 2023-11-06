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
      <template #header>
        <div class="flex items-center text-lg">
          File-info
        </div>
      </template>
      <template #body>
        <p class="text-base leading-relaxed text-white">
          This is a long test.
          Nostrud sit ullamco veniam dolore enim velit non voluptate et esse. Aliqua qui laborum esse amet culpa. Minim esse deserunt irure laboris sit nulla voluptate voluptate mollit. Elit cillum sint ex fugiat consequat nisi. Aliqua
          officia tempor enim sint in pariatur cillum. Minim esse labore mollit sit dolore sint. Nisi veniam nulla minim excepteur nulla. Deserunt in elit reprehenderit sit cillum in voluptate nisi adipisicing irure duis commodo.
        </p>
      </template>
    </fwb-modal>
  </Teleport>

</template>

<script>
import {FwbModal} from "flowbite-vue";

export default {
  components: {FwbModal},
  data: () => ({isShowModal: false}),
  methods: {
    showModal: function (event) {
      event.preventDefault();
      event.stopPropagation();

      this.isShowModal = true;
    },
    closeModal: function () {
      this.isShowModal = false;
    }
  }
}
</script>

<style scoped>
</style>
