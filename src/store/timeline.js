/**
 * @copyright Copyright (c) 2018 Julius Härtl <jus@bitgrid.net>
 *
 * @file Timeline related store
 *
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Jonas Sulzer <jonas@violoncello.ch>
 *
 * @license AGPL-3.0-or-later
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

import Vue from 'vue'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import logger from '../services/logger.js'

/**
 * @property {object} timeline - The posts' collection
 * @property {number} since - Time (EPOCH) of the most recent post
 * @property {string} type - Timeline's type: 'home', 'single-post',...
 * @property {object} params - Timeline's parameters
 * @property {string} account -
 */
const state = {
	timeline: {},
	since: Math.floor(Date.now() / 1000) + 1,
	type: 'home',
	/**
	 * @namespace params
	 * @property {string} account ???
	 * @property {string} id
	 * @property {string} localId
	 * @property {string} type ???
	 */
	params: {},
	account: '',
	/**
	 * Tells whether the composer should be displayed or not.
	 * It's up to the view to honor this status or not.
	 *
	 * @member {boolean}
	 */
	composerDisplayStatus: false,
}
const mutations = {
	addToTimeline(state, data) {
		for (const item in data) {
			state.since = data[item].publishedTime
			Vue.set(state.timeline, data[item].id, data[item])
		}
	},
	removePost(state, post) {
		Vue.delete(state.timeline, post.id)
	},
	resetTimeline(state) {
		state.timeline = {}
		state.since = Math.floor(Date.now() / 1000) + 1
	},
	setTimelineType(state, type) {
		state.type = type
	},
	setTimelineParams(state, params) {
		state.params = params
	},
	setComposerDisplayStatus(state, status) {
		state.composerDisplayStatus = status
	},
	setAccount(state, account) {
		state.account = account
	},
	likePost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'liked', true)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'liked', true)
		}
	},
	unlikePost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'liked', false)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'liked', false)
		}
	},
	boostPost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'boosted', true)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'boosted', true)
		}
	},
	unboostPost(state, { post, parentAnnounce }) {
		if (typeof state.timeline[post.id] !== 'undefined') {
			Vue.set(state.timeline[post.id].action.values, 'boosted', false)
		}
		if (typeof parentAnnounce.id !== 'undefined') {
			Vue.set(state.timeline[parentAnnounce.id].cache[parentAnnounce.object].object.action.values, 'boosted', false)
		}
	},
}
const getters = {
	getComposerDisplayStatus(state) {
		return state.composerDisplayStatus
	},
	getTimeline(state) {
		return Object.values(state.timeline).sort(function(a, b) {
			return b.publishedTime - a.publishedTime
		})
	},
	getPostFromTimeline(state) {
		return (postId) => {
			if (typeof state.timeline[postId] !== 'undefined') {
				return state.timeline[postId]
			} else {
				logger.warn('Could not find post in timeline', { postId })
			}
		}
	},
}
const actions = {
	changeTimelineType(context, { type, params }) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', type)
		context.commit('setTimelineParams', params)
		context.commit('setAccount', '')
	},
	changeTimelineTypeAccount(context, account) {
		context.commit('resetTimeline')
		context.commit('setTimelineType', 'account')
		context.commit('setAccount', account)
	},
	async post(context, post) {
		try {
			const { data } = await axios.post(generateUrl('apps/social/api/v1/post'), post, {
				headers: {
					'Content-Type': 'multipart/form-data',
				},
			})
			logger.info('Post created with token ' + data.result.token)
		} catch (error) {
			showError('Failed to create a post')
			logger.error('Failed to create a post', { error: error.response })
		}
	},
	postDelete(context, post) {
		return axios.delete(generateUrl(`apps/social/api/v1/post?id=${post.id}`)).then((response) => {
			context.commit('removePost', post)
			logger.info('Post deleted with token ' + response.data.result.token)
		}).catch((error) => {
			showError('Failed to delete the post')
			logger.error('Failed to delete the post', { error })
		})
	},
	postLike(context, { post, parentAnnounce }) {
		return new Promise((resolve, reject) => {
			axios.post(generateUrl(`apps/social/api/v1/post/like?postId=${post.id}`)).then((response) => {
				context.commit('likePost', { post, parentAnnounce })
				resolve(response)
			}).catch((error) => {
				showError('Failed to like post')
				logger.error('Failed to like post', { error: error.response })
				reject(error)
			})
		})
	},
	postUnlike(context, { post, parentAnnounce }) {
		return axios.delete(generateUrl(`apps/social/api/v1/post/like?postId=${post.id}`)).then((response) => {
			context.commit('unlikePost', { post, parentAnnounce })
			// Remove post from list if we are in the 'liked' timeline
			if (state.type === 'liked') {
				context.commit('removePost', post)
			}
		}).catch((error) => {
			showError('Failed to unlike post')
			logger.error('Failed to unlike post', { error })
		})
	},
	postBoost(context, { post, parentAnnounce }) {
		return new Promise((resolve, reject) => {
			axios.post(generateUrl(`apps/social/api/v1/post/boost?postId=${post.id}`)).then((response) => {
				context.commit('boostPost', { post, parentAnnounce })
				logger.info('Post boosted with token ' + response.data.result.token)
				resolve(response)
			}).catch((error) => {
				showError('Failed to create a boost post')
				logger.error('Failed to create a boost post', { error: error.response })
				reject(error)
			})
		})
	},
	postUnBoost(context, { post, parentAnnounce }) {
		return axios.delete(generateUrl(`apps/social/api/v1/post/boost?postId=${post.id}`)).then((response) => {
			context.commit('unboostPost', { post, parentAnnounce })
			logger.info('Boost deleted with token ' + response.data.result.token)
		}).catch((error) => {
			showError('Failed to delete the boost')
			logger.error('Failed to delete the boost', { error })
		})
	},
	refreshTimeline(context) {
		return this.dispatch('fetchTimeline', { sinceTimestamp: Math.floor(Date.now() / 1000) + 1 })
	},
	fetchTimeline(context, { sinceTimestamp }) {

		if (typeof sinceTimestamp === 'undefined') {
			sinceTimestamp = state.since - 1
		}

		// Compute URl to get the data
		let url = ''
		if (state.type === 'account') {
			url = generateUrl(`apps/social/api/v1/account/${state.account}/stream?limit=25&since=` + sinceTimestamp)
		} else if (state.type === 'tags') {
			url = generateUrl(`apps/social/api/v1/stream/tag/${state.params.tag}?limit=25&since=` + sinceTimestamp)
		} else if (state.type === 'single-post') {
			url = generateUrl(`apps/social/local/v1/post/replies?id=${state.params.id}&limit=5&since=` + sinceTimestamp)
		} else {
			url = generateUrl(`apps/social/api/v1/stream/${state.type}?limit=25&since=` + sinceTimestamp)
		}

		// Get the data and add them to the timeline
		return axios.get(url).then((response) => {

			if (response.status === -1) {
				throw response.message
			}

			// Add results to timeline
			context.commit('addToTimeline', response.data.result)

		return response.data
	},
	addToTimeline(context, data) {
		context.commit('addToTimeline', data)
	},
}

export default { state, mutations, getters, actions }
