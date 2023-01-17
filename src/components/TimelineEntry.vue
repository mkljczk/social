<template>
	<div :class="['timeline-entry', hasHeader ? 'with-header' : '']">
		<div v-if="item.type === 'SocialAppNotification'" class="notification">
			<Bell :size="22" />
			<span class="notification-action">
				{{ actionSummary }}
			</span>
		</div>
		<template v-else-if="item.type === 'Announce'">
			<div class="container-icon-boost boost">
				<span class="icon-boost" />
			</div>
			<div class="boost">
				<router-link v-if="!isProfilePage && item.account" :to="{ name: 'profile', params: { account: isLocal ? item.account.preferredUsername : item.actor_info.account }}">
					<span v-tooltip.bottom="item.actor_info.account" class="post-author">
						{{ userDisplayName(item.account) }}
					</span>
				</router-link>
				<a v-else :href="item.account.id">
					<span class="post-author-id">
						{{ item.account.id }}
					</span>
				</a>
				{{ boosted }}
			</div>
		</template>
		<UserEntry v-if="item.type === 'SocialAppNotification' && item.details.actor" :key="item.details.actor.id" :item="item.details.actor" />
		<template v-else>
			<div class="wrapper">
				<TimelineAvatar class="entry__avatar" :item="entryContent" />
				<TimelinePost class="entry__content"
					:item="entryContent"
					:parent-announce="isBoost" />
			</div>
		</template>
	</div>
</template>

<script>
import TimelinePost from './TimelinePost.vue'
import TimelineAvatar from './TimelineAvatar.vue'
import UserEntry from './UserEntry.vue'
import Bell from 'vue-material-design-icons/Bell.vue'

export default {
	name: 'TimelineEntry',
	components: {
		TimelinePost,
		TimelineAvatar,
		UserEntry,
		Bell,
	},
	props: {
		/** @type {import('vue').PropType<import('../types/Mastodon.js').Status>} */
		item: {
			type: Object,
			default: () => {},
		},
		isProfilePage: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		entryContent() {
			if (this.item.type === 'Announce') {
				return this.item.cache[this.item.object].object
			} else if (this.item.type === 'SocialAppNotification') {
				return this.item.details.post
			} else {
				return this.item
			}
		},
		/**
		 * @return {import('../types/Mastodon.js').Status}
		 */
		isBoost() {
			if (this.item.type === 'Announce') {
				return this.item
			}
			return {}
		},
		/**
		 * @return {boolean}
		 */
		hasHeader() {
			return this.item.type === 'Announce' || this.item.type === 'SocialAppNotification'
		},
		/**
		 * @return {string}
		 */
		boosted() {
			return t('social', 'boosted')
		},
		/**
		 * @return {string}
		 */
		actionSummary() {
			let summary = this.item.summary
			for (const key in this.item.details) {

				const keyword = '{' + key + '}'
				if (typeof this.item.details[key] !== 'string' && this.item.details[key].length > 1) {

					let concatenation = ''
					for (const stringKey in this.item.details[key]) {

						if (this.item.details[key].length > 3 && stringKey === '3') {
							// ellipses the actors' list to 3 actors when it's big
							concatenation = concatenation.substring(0, concatenation.length - 2)
							concatenation += ' and ' + (this.item.details[key].length - 3).toString() + ' other(s), '
							break
						} else {
							concatenation += this.item.details[key][stringKey] + ', '
						}
					}

					concatenation = concatenation.substring(0, concatenation.length - 2)
					summary = summary.replace(keyword, concatenation)

				} else {
					summary = summary.replace(keyword, this.item.details[key])
				}
			}

			return summary
		},
		/**
		 * @return {boolean}
		 */
		isLocal() {
			return this.item.account.acct.includes('@')
		},
	},
	methods: {
		/**
		 * @param {import('../types/Mastodon.js').Account} actorInfo
		 */
		userDisplayName(actorInfo) {
			return actorInfo.display_name ?? actorInfo.username
		},
	},
}
</script>
<style scoped lang="scss">
	.wrapper {
		display: flex;
		margin: auto;
		padding: 0;

		&:focus {
			background-color: rgba(47, 47, 47, 0.068);
		}

		.entry__avatar {
			flex-shrink: 0;
		}

		.entry__content {
			flex-grow: 1;
			width: 0;
		}
	}

	.notification-header {
		display: flex;
		align-items: bottom;
	}

	.notification {
		display: flex;
		padding-left: 2rem;
		gap: 0.2rem;
		margin-top: 1rem;

		&-action {
			flex-grow: 1;
			display: inline-block;
			grid-row: 1;
			grid-column: 2;
			color: var(--color-text-lighter);
		}

		.bell-icon {
			opacity: .5;
		}
	}

	.icon-boost {
		display: inline-block;
		vertical-align: middle;
	}

	.icon-favorite {
		display: inline-block;
		vertical-align: middle;
	}

	.icon-user {
		display: inline-block;
		vertical-align: middle;
	}

	.container-icon-boost {
		display: inline-block;
		padding-right: 6px;
	}

	.icon-boost {
		display: inline-block;
		width: 38px;
		height: 17px;
		opacity: .5;
		background-position: right center;
		vertical-align: middle;
	}

	.boost {
		opacity: .5;
	}
</style>
