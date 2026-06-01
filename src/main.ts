/**
 * GeoTag Photos — Nextcloud app
 *
 * Entry point: registers the "Add Geolocation Tag" file action in the Files app.
 *
 * @author    Dimitris Vagiakakos <dimitrislinuxos@protonmail.ch>
 * @copyright 2024 Dimitris Vagiakakos
 * @license   GNU AGPL version 3 or any later version
 */

import { registerFileAction } from '@nextcloud/files'
import type { IFileAction, INode } from '@nextcloud/files'
import { translate as t } from '@nextcloud/l10n'
import { createApp } from 'vue'
import GeotagModal from './components/GeotagModal.vue'

const JPEG_MIMES = new Set(['image/jpeg', 'image/jpg'])

const LOCATION_ICON = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
  <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5
           c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
</svg>`

function openModal(nodes: INode[]): void {
	const container = document.createElement('div')
	document.body.appendChild(container)

	const app = createApp(GeotagModal, {
		nodes,
		onClose: () => {
			app.unmount()
			container.remove()
		},
	})
	app.mount(container)
}

registerFileAction({
	id: 'geotagphotos-add-geolocation',

	displayName: () => t('geotagphotos', 'Add Geolocation Tag'),

	iconSvgInline: () => LOCATION_ICON,

	enabled: ({ nodes }) =>
		nodes.length > 0 && nodes.every(n => JPEG_MIMES.has(n.mime ?? '')),

	async exec({ nodes }) {
		openModal([nodes[0]])
		return null
	},

	async execBatch({ nodes }) {
		openModal(nodes)
		return nodes.map(() => null)
	},

	order: 20,
} satisfies IFileAction)
