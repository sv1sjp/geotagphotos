<template>
  <div class="gt-backdrop" @click.self="handleClose">
    <div
      ref="dialogRef"
      class="gt-dialog"
      role="dialog"
      aria-modal="true"
      :aria-label="t('geotagphotos', 'Add Geolocation Tag')"
    >
      <!-- Header -->
      <div class="gt-dialog__header">
        <h2 class="gt-dialog__title">{{ t('geotagphotos', 'Add Geolocation Tag') }}</h2>
        <button
          class="gt-icon-btn"
          :disabled="saving"
          :aria-label="t('geotagphotos', 'Close')"
          @click="handleClose"
        >
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20">
            <path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
          </svg>
        </button>
      </div>

      <!-- Fatal error -->
      <div v-if="fatalError" class="gt-dialog__body">
        <div class="gt-note gt-note--error">{{ fatalError }}</div>
        <div class="gt-dialog__actions">
          <button class="gt-btn" @click="handleClose">{{ t('geotagphotos', 'Close') }}</button>
        </div>
      </div>

      <!-- Main form -->
      <div v-else class="gt-dialog__body">
        <div v-if="isBatch" class="gt-note gt-note--info">{{ batchLabel }}</div>
        <div v-if="!isBatch && hasGps" class="gt-note gt-note--warning">{{ existingGpsLabel }}</div>

        <div class="gt-form">
          <div class="gt-field">
            <label for="geotag-lat" class="gt-label">
              {{ t('geotagphotos', 'Latitude') }}
              <span class="gt-hint">−90 … +90</span>
            </label>
            <input
              id="geotag-lat"
              v-model.number="inputLat"
              type="number"
              step="any"
              min="-90"
              max="90"
              :placeholder="t('geotagphotos', 'e.g. 37.9838')"
              class="gt-input"
              :disabled="saving"
            />
          </div>
          <div class="gt-field">
            <label for="geotag-lon" class="gt-label">
              {{ t('geotagphotos', 'Longitude') }}
              <span class="gt-hint">−180 … +180</span>
            </label>
            <input
              id="geotag-lon"
              v-model.number="inputLon"
              type="number"
              step="any"
              min="-180"
              max="180"
              :placeholder="t('geotagphotos', 'e.g. 23.7275')"
              class="gt-input"
              :disabled="saving"
            />
          </div>
          <div class="gt-field">
            <label for="geotag-alt" class="gt-label">
              {{ t('geotagphotos', 'Altitude in meters (optional)') }}
            </label>
            <input
              id="geotag-alt"
              v-model="inputAltRaw"
              type="number"
              step="any"
              :placeholder="t('geotagphotos', 'e.g. 150')"
              class="gt-input"
              :disabled="saving"
            />
          </div>
        </div>

        <p v-if="validationError" class="gt-validation-error">{{ validationError }}</p>
        <div v-if="saveError" class="gt-note gt-note--error">{{ saveError }}</div>
        <div v-if="clipboardNote" :class="['gt-note', clipboardNoteIsError ? 'gt-note--error' : 'gt-note--success']">
          {{ clipboardNote }}
        </div>

        <div class="gt-dialog__actions">
          <button class="gt-btn gt-btn--clipboard" :disabled="saving" @click="pasteFromClipboard">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="15" height="15" aria-hidden="true">
              <path fill="currentColor" d="M19 2h-4.18C14.4.84 13.3 0 12 0c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm7 18H5V4h2v3h10V4h2v16z"/>
            </svg>
            {{ t('geotagphotos', 'Paste from Clipboard') }}
          </button>
          <button
            v-if="!isBatch && hasGps"
            class="gt-btn gt-btn--error"
            :disabled="saving"
            @click="clearGps"
          >
            {{ t('geotagphotos', 'Clear GPS') }}
          </button>
          <button class="gt-btn" :disabled="saving" @click="handleClose">
            {{ t('geotagphotos', 'Cancel') }}
          </button>
          <button class="gt-btn gt-btn--primary" :disabled="saving" @click="saveGps">
            <span v-if="saving" class="gt-spinner" aria-hidden="true"></span>
            {{ saveLabel }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script lang="ts">
import { defineComponent, ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import type { PropType } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import type { INode } from '@nextcloud/files'

// ---------------------------------------------------------------------------
// Coordinate parsing helpers (no reactive state — safe as module-level fns)
// ---------------------------------------------------------------------------

function isValidCoords(lat: number, lon: number): boolean {
	return !isNaN(lat) && !isNaN(lon) && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180
}

/**
 * Parse a clipboard string into { lat, lon }.
 * Supported formats:
 *   Decimal degrees : "38.083454, 23.697252"  /  "38.083454 23.697252"
 *   DMS with ref    : "38°05'21.1\"N 23°42'42.0\"E"
 * Returns null when the text cannot be interpreted.
 */
function parseClipboardCoords(raw: string): { lat: number; lon: number } | null {
	// 1. DMS — scanned across the whole text (no anchors needed).
	//    e.g. "38°05'21.1\"N 23°42'42.0\"E" plus any surrounding garbage.
	const dmsMatch = raw.match(
		/(\d+)\s*[°]\s*(\d+)\s*['‘’′]\s*([\d.]+)\s*["“”″]\s*([NSns])\s*(?:,\s*)?\s*(\d+)\s*[°]\s*(\d+)\s*['‘’′]\s*([\d.]+)\s*["“”″]\s*([EWew])/,
	)
	if (dmsMatch) {
		let lat = parseInt(dmsMatch[1]) + parseInt(dmsMatch[2]) / 60 + parseFloat(dmsMatch[3]) / 3600
		let lon = parseInt(dmsMatch[5]) + parseInt(dmsMatch[6]) / 60 + parseFloat(dmsMatch[7]) / 3600
		if (/[Ss]/.test(dmsMatch[4])) lat = -lat
		if (/[Ww]/.test(dmsMatch[8])) lon = -lon
		if (isValidCoords(lat, lon)) return { lat, lon }
	}

	// 2. Decimal degrees — tested line-by-line so trailing blank lines, addresses,
	//    or other text copied alongside the coordinates (e.g. from Google Maps) are
	//    ignored and the first valid coordinate line wins.
	const ddPattern = /^([NSns])?\s*([+-]?\d+(?:\.\d+)?)\s*[°]?\s*([NSns])?\s*(?:,\s*|\s+)([EWew])?\s*([+-]?\d+(?:\.\d+)?)\s*[°]?\s*([EWew])?$/
	for (const line of raw.split(/\r?\n/)) {
		const trimmed = line.trim()
		if (!trimmed) continue
		const ddMatch = trimmed.match(ddPattern)
		if (!ddMatch) continue
		let lat = parseFloat(ddMatch[2])
		let lon = parseFloat(ddMatch[5])
		const latRef = (ddMatch[1] ?? ddMatch[3] ?? '').toUpperCase()
		const lonRef = (ddMatch[4] ?? ddMatch[6] ?? '').toUpperCase()
		if (latRef === 'S') lat = -Math.abs(lat)
		if (lonRef === 'W') lon = -Math.abs(lon)
		if (isValidCoords(lat, lon)) return { lat, lon }
	}

	return null
}

export default defineComponent({
	name: 'GeotagModal',

	props: {
		nodes: {
			type: Array as PropType<INode[]>,
			required: true,
		},
	},

	emits: ['close'],

	setup(props, { emit }) {
		const dialogRef = ref<HTMLElement | null>(null)

		const saving = ref(false)
		const fatalError = ref<string | null>(null)
		const saveError = ref<string | null>(null)
		const validationError = ref<string | null>(null)

		const hasGps = ref(false)
		const currentLat = ref<number | null>(null)
		const currentLon = ref<number | null>(null)

		const inputLat = ref<number | ''>('')
		const inputLon = ref<number | ''>('')
		const inputAltRaw = ref<string>('')

		const clipboardNote = ref<string | null>(null)
		const clipboardNoteIsError = ref(false)
		let clipboardNoteTimer: ReturnType<typeof setTimeout> | null = null

		function showClipboardNote(message: string, isError: boolean): void {
			clipboardNote.value = message
			clipboardNoteIsError.value = isError
			if (clipboardNoteTimer !== null) clearTimeout(clipboardNoteTimer)
			clipboardNoteTimer = setTimeout(() => { clipboardNote.value = null }, 4000)
		}

		async function pasteFromClipboard(): Promise<void> {
			if (!navigator.clipboard?.readText) {
				showClipboardNote(t('geotagphotos', 'Clipboard not available — check browser permissions'), true)
				return
			}
			let text: string
			try {
				text = await navigator.clipboard.readText()
			} catch {
				showClipboardNote(t('geotagphotos', 'Could not read clipboard — check browser permissions'), true)
				return
			}
			const parsed = parseClipboardCoords(text)
			if (!parsed) {
				showClipboardNote(t('geotagphotos', 'Unknown Data'), true)
				return
			}
			inputLat.value = parseFloat(parsed.lat.toFixed(7))
			inputLon.value = parseFloat(parsed.lon.toFixed(7))
			showClipboardNote(t('geotagphotos', 'Coordinates pasted — click Save to apply'), false)
		}

		const isBatch = computed(() => props.nodes.length > 1)

		const batchLabel = computed(() =>
			n('geotagphotos', 'Applying coordinates to %n photo', 'Applying coordinates to %n photos', props.nodes.length),
		)

		const existingGpsLabel = computed(() => {
			const lat = currentLat.value !== null ? currentLat.value.toFixed(6) : '?'
			const lon = currentLon.value !== null ? currentLon.value.toFixed(6) : '?'
			return t('geotagphotos', 'This photo already has GPS coordinates: {lat}, {lon}', { lat, lon })
		})

		const saveLabel = computed(() =>
			!isBatch.value && hasGps.value
				? t('geotagphotos', 'Replace')
				: t('geotagphotos', 'Save'),
		)

		function handleClose() {
			if (!saving.value) emit('close')
		}

		function handleKeydown(e: KeyboardEvent) {
			if (e.key === 'Escape') {
				handleClose()
				return
			}
			// Simple focus trap
			if (e.key === 'Tab' && dialogRef.value) {
				const focusable = Array.from(
					dialogRef.value.querySelectorAll<HTMLElement>(
						'button:not([disabled]), input:not([disabled])',
					),
				)
				if (focusable.length === 0) return
				const first = focusable[0]
				const last = focusable[focusable.length - 1]
				if (e.shiftKey && document.activeElement === first) {
					e.preventDefault()
					last.focus()
				} else if (!e.shiftKey && document.activeElement === last) {
					e.preventDefault()
					first.focus()
				}
			}
		}

		onMounted(async () => {
			document.addEventListener('keydown', handleKeydown)
			await nextTick()
			const first = dialogRef.value?.querySelector<HTMLElement>('button, input')
			first?.focus()

			if (isBatch.value) return
			try {
				const { data } = await axios.get(apiUrl(props.nodes[0]))
				if (data.hasGps) {
					hasGps.value = true
					currentLat.value = data.latitude
					currentLon.value = data.longitude
					inputLat.value = data.latitude
					inputLon.value = data.longitude
					if (data.altitude !== undefined) {
						inputAltRaw.value = String(data.altitude)
					}
				}
			} catch (err: unknown) {
				fatalError.value = httpError(err, t('geotagphotos', 'Failed to read GPS data'))
			}
		})

		onUnmounted(() => {
			document.removeEventListener('keydown', handleKeydown)
			if (clipboardNoteTimer !== null) clearTimeout(clipboardNoteTimer)
		})

		async function saveGps(): Promise<void> {
			if (!validate()) return
			saving.value = true
			saveError.value = null
			const lat = Number(inputLat.value)
			const lon = Number(inputLon.value)
			const alt = inputAltRaw.value.trim() !== '' ? Number(inputAltRaw.value) : null
			try {
				for (const node of props.nodes) {
					await axios.post(apiUrl(node), { latitude: lat, longitude: lon, altitude: alt })
				}
				emit('close')
			} catch (err: unknown) {
				saveError.value = httpError(err, t('geotagphotos', 'Failed to save GPS data'))
				saving.value = false
			}
		}

		async function clearGps(): Promise<void> {
			saving.value = true
			saveError.value = null
			try {
				await axios.delete(apiUrl(props.nodes[0]))
				emit('close')
			} catch (err: unknown) {
				saveError.value = httpError(err, t('geotagphotos', 'Failed to clear GPS data'))
				saving.value = false
			}
		}

		function validate(): boolean {
			validationError.value = null
			if (inputLat.value === '' || inputLon.value === '') {
				validationError.value = t('geotagphotos', 'Latitude and longitude are required')
				return false
			}
			const lat = Number(inputLat.value)
			const lon = Number(inputLon.value)
			if (isNaN(lat) || lat < -90 || lat > 90) {
				validationError.value = t('geotagphotos', 'Latitude must be between −90 and 90')
				return false
			}
			if (isNaN(lon) || lon < -180 || lon > 180) {
				validationError.value = t('geotagphotos', 'Longitude must be between −180 and 180')
				return false
			}
			if (inputAltRaw.value.trim() !== '' && isNaN(Number(inputAltRaw.value))) {
				validationError.value = t('geotagphotos', 'Altitude must be a number')
				return false
			}
			return true
		}

		function apiUrl(node: INode): string {
			return generateUrl(`/apps/geotagphotos/api/exif/${node.fileid}`)
		}

		function httpError(err: unknown, fallback: string): string {
			if (err && typeof err === 'object' && 'response' in err) {
				const resp = (err as { response?: { data?: { error?: string } } }).response
				if (resp?.data?.error) return resp.data.error
			}
			return fallback
		}

		return {
			dialogRef,
			saving, fatalError, saveError, validationError,
			hasGps, currentLat, currentLon,
			inputLat, inputLon, inputAltRaw,
			clipboardNote, clipboardNoteIsError, pasteFromClipboard,
			isBatch, batchLabel, existingGpsLabel, saveLabel,
			handleClose, saveGps, clearGps,
			t,
		}
	},
})
</script>

<style scoped>
/* Backdrop */
.gt-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}

/* Dialog box */
.gt-dialog {
  background: var(--color-main-background);
  color: var(--color-main-text);
  border-radius: var(--border-radius-large, 8px);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
  width: min(480px, 94vw);
  max-height: 90vh;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.gt-dialog__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px 12px;
  border-bottom: 1px solid var(--color-border);
}

.gt-dialog__title {
  font-size: 1.05rem;
  font-weight: 600;
  margin: 0;
}

.gt-icon-btn {
  background: none;
  border: none;
  cursor: pointer;
  padding: 4px;
  border-radius: var(--border-radius);
  color: var(--color-main-text);
  display: flex;
  align-items: center;
  line-height: 1;
}

.gt-icon-btn:hover:not(:disabled) {
  background: var(--color-background-hover);
}

.gt-dialog__body {
  padding: 16px 20px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.gt-dialog__actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  flex-wrap: wrap;
}

/* Notices */
.gt-note {
  padding: 8px 12px;
  border-radius: var(--border-radius);
  font-size: 0.875rem;
  line-height: 1.4;
}

.gt-note--info {
  background: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
  border-left: 3px solid var(--color-primary-element);
}

.gt-note--warning {
  background: color-mix(in srgb, var(--color-warning, #e9a429) 15%, transparent);
  border-left: 3px solid var(--color-warning, #e9a429);
}

.gt-note--error {
  background: color-mix(in srgb, var(--color-error, #e9322d) 15%, transparent);
  border-left: 3px solid var(--color-error, #e9322d);
}

.gt-note--success {
  background: color-mix(in srgb, var(--color-success, #46ba61) 15%, transparent);
  border-left: 3px solid var(--color-success, #46ba61);
}

/* Form */
.gt-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.gt-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.gt-label {
  font-weight: 600;
  font-size: 0.875rem;
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.gt-hint {
  font-weight: 400;
  font-size: 0.75rem;
  color: var(--color-text-maxcontrast);
}

.gt-input {
  width: 100%;
  padding: 6px 10px;
  border: 1px solid var(--color-border-dark);
  border-radius: var(--border-radius);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 0.9rem;
  font-family: inherit;
  box-sizing: border-box;
}

.gt-input:focus {
  outline: none;
  border-color: var(--color-primary-element);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-primary-element) 25%, transparent);
}

.gt-input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.gt-validation-error {
  color: var(--color-error, #e9322d);
  font-size: 0.85rem;
  margin: 0;
}

/* Buttons */
.gt-btn {
  padding: 6px 16px;
  border: 2px solid var(--color-border-dark);
  border-radius: var(--border-radius-pill, 20px);
  background: var(--color-main-background);
  color: var(--color-main-text);
  font-size: 0.875rem;
  font-family: inherit;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.gt-btn:hover:not(:disabled) {
  background: var(--color-background-hover);
}

.gt-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.gt-btn--primary {
  background: var(--color-primary-element);
  color: var(--color-primary-element-text, #fff);
  border-color: var(--color-primary-element);
}

.gt-btn--primary:hover:not(:disabled) {
  background: var(--color-primary-element-hover, var(--color-primary-element));
  filter: brightness(1.08);
}

.gt-btn--error {
  background: var(--color-error, #e9322d);
  color: #fff;
  border-color: var(--color-error, #e9322d);
}

.gt-btn--error:hover:not(:disabled) {
  filter: brightness(0.9);
}

.gt-btn--clipboard {
  margin-right: auto;
}

/* Spinner for saving state */
@keyframes gt-spin {
  to { transform: rotate(360deg); }
}

.gt-spinner {
  display: inline-block;
  width: 13px;
  height: 13px;
  border: 2px solid currentColor;
  border-top-color: transparent;
  border-radius: 50%;
  animation: gt-spin 0.7s linear infinite;
  flex-shrink: 0;
}
</style>
