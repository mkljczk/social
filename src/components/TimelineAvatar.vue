<template>
	<div v-if="item.account" class="post-avatar">
		<NcAvatar v-if="isLocal"
			class="messages__avatar__icon"
			:show-user-status="false"
			menu-position="left"
			:user="userTest"
			:display-name="item.account.acct"
			:disable-tooltip="true" />
		<NcAvatar v-else
			:url="avatarUrl"
			:disable-tooltip="true" />
	</div>
</template>

<script>
import NcAvatar from '@nextcloud/vue/dist/Components/NcAvatar.js'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'TimelineAvatar',
	components: {
		NcAvatar,
	},
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
			default: () => {},
		},
	},
	computed: {
		/**
		 * @return {string}
		 */
		userTest() {
			return this.item.account.preferredUsername
		},
		/**
		 * @return {string}
		 */
		avatarUrl() {
			return generateUrl('/apps/social/api/v1/global/actor/avatar?id=' + this.item.account.id)
		},
		/** @return {boolean} */
		isLocal() {
			return this.item.account.acct.includes('@')
		},
	},
}
</script>

<style scoped>
.post-avatar {
	position: relative;
	padding: 5px 10px 10px 5px;
	height: 52px;
	width: 52px;
}
</style>
