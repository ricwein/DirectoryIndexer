/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

import 'flowbite';
import '@/styles/app.scss';

import {createApp} from 'vue'
import FileRow from "@/components/file-row.vue";
import ParentRow from "@/components/parent-row.vue";

const app = createApp({
    components: {FileRow, ParentRow},
})
app.mount('#app')
