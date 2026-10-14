<template><el-dialog title="选择本店素材" :visible="visible" width="92%" append-to-body :close-on-click-modal="false" @close="$emit('close')"><attachment-library v-if="visible" selectable @select="$emit('select', $event)" /></el-dialog></template>
<script>import AttachmentLibrary from './AttachmentLibrary.vue'; export default { components: { AttachmentLibrary }, props: { visible: Boolean } };</script>
