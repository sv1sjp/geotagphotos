<?php

declare(strict_types=1);

/**
 * GeoTag Photos — Nextcloud app
 *
 * @author    Dimitris Vagiakakos <dimitrislinuxos@protonmail.ch>
 * @copyright 2026 Dimitris Vagiakakos @sv1sjp - TuxHouse EU
 * @license   GNU AGPL version 3 or any later version
 */

namespace OCA\GeotagPhotos\Service;

use OCP\Files\File;
use OCP\Files\NotFoundException;

class ExifService {
	private string $exiftoolBin;

	public function __construct() {
		$this->exiftoolBin = $this->resolveExiftool();
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Read GPS EXIF tags from a file.
	 *
	 * Returns an array with:
	 *   ['hasGps' => false]
	 * or
	 *   ['hasGps' => true, 'latitude' => float, 'longitude' => float, 'altitude' => float|null]
	 *
	 * Latitude is negative for South, longitude is negative for West.
	 */
	public function readGpsExif(File $file): array {
		$path = $this->localPath($file);

		[$code, $stdout] = $this->run([
			'-json', '-n',
			'-GPSLatitude', '-GPSLongitude',
			'-GPSLatitudeRef', '-GPSLongitudeRef',
			'-GPSAltitude', '-GPSAltitudeRef',
			$path,
		]);

		if ($code !== 0) {
			throw new \RuntimeException('exiftool read failed (exit ' . $code . ')');
		}

		$rows = json_decode($stdout, true);
		if (!is_array($rows) || empty($rows[0])) {
			return ['hasGps' => false];
		}
		$exif = $rows[0];

		if (!isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
			return ['hasGps' => false];
		}

		$lat = (float)$exif['GPSLatitude'];
		$lon = (float)$exif['GPSLongitude'];

		// exiftool -n returns absolute values; direction comes from the Ref tags.
		if (isset($exif['GPSLatitudeRef']) && strtoupper((string)$exif['GPSLatitudeRef']) === 'S') {
			$lat = -abs($lat);
		}
		if (isset($exif['GPSLongitudeRef']) && strtoupper((string)$exif['GPSLongitudeRef']) === 'W') {
			$lon = -abs($lon);
		}

		$result = ['hasGps' => true, 'latitude' => $lat, 'longitude' => $lon];

		if (isset($exif['GPSAltitude'])) {
			$alt = (float)$exif['GPSAltitude'];
			// GPSAltitudeRef: 0 = above sea level, 1 = below sea level
			if (isset($exif['GPSAltitudeRef']) && (int)$exif['GPSAltitudeRef'] === 1) {
				$alt = -abs($alt);
			}
			$result['altitude'] = $alt;
		}

		return $result;
	}

	/**
	 * Write GPS EXIF tags into a file.
	 * Only GPSLatitude, GPSLongitude, GPSLatitudeRef, GPSLongitudeRef
	 * (and optionally GPSAltitude/GPSAltitudeRef) are written.
	 * All other EXIF fields are untouched.
	 */
	public function writeGpsExif(File $file, float $latitude, float $longitude, ?float $altitude = null): void {
		$path = $this->localPath($file);

		$latRef = $latitude >= 0 ? 'N' : 'S';
		$lonRef = $longitude >= 0 ? 'E' : 'W';

		$args = [
			'-overwrite_original',
			'-n',
			'-GPSLatitude=' . abs($latitude),
			'-GPSLongitude=' . abs($longitude),
			'-GPSLatitudeRef=' . $latRef,
			'-GPSLongitudeRef=' . $lonRef,
		];

		if ($altitude !== null) {
			$args[] = '-GPSAltitude=' . abs($altitude);
			$args[] = '-GPSAltitudeRef=' . ($altitude >= 0 ? '0' : '1');
		}

		$args[] = $path;

		[$code, , $stderr] = $this->run($args);
		if ($code !== 0) {
			throw new \RuntimeException('exiftool write failed: ' . $stderr);
		}

		$this->refreshCache($file, $path);
	}

	/**
	 * Remove all GPS EXIF tags from a file.
	 */
	public function clearGpsExif(File $file): void {
		$path = $this->localPath($file);

		[$code, , $stderr] = $this->run([
			'-overwrite_original',
			'-GPSLatitude=',
			'-GPSLongitude=',
			'-GPSLatitudeRef=',
			'-GPSLongitudeRef=',
			'-GPSAltitude=',
			'-GPSAltitudeRef=',
			$path,
		]);

		if ($code !== 0) {
			throw new \RuntimeException('exiftool clear failed: ' . $stderr);
		}

		$this->refreshCache($file, $path);
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Run exiftool with the given arguments.
	 * Uses proc_open with an array command so no shell is involved — safe against
	 * injection even if a future caller forgot to sanitise an argument.
	 *
	 * @return array{int, string, string}  [exitCode, stdout, stderr]
	 */
	private function run(array $args): array {
		$cmd = array_merge([$this->exiftoolBin], $args);

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($cmd, $descriptors, $pipes);
		if ($process === false) {
			throw new \RuntimeException('Failed to start exiftool process');
		}

		fclose($pipes[0]);
		$stdout = (string)stream_get_contents($pipes[1]);
		$stderr = (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);

		return [$exitCode, $stdout, $stderr];
	}

	/**
	 * Resolve the absolute path to the local file on disk and verify it exists.
	 * Security: uses realpath() to eliminate any path traversal.
	 */
	private function localPath(File $file): string {
		$storage = $file->getStorage();

		if (!$storage->isLocal()) {
			throw new \RuntimeException(
				'GeoTag Photos only supports local storage. ' .
				'External or object-store mounted files are not supported.'
			);
		}

		$localPath = $storage->getLocalFile($file->getInternalPath());
		if ($localPath === null || $localPath === '') {
			throw new NotFoundException('Could not resolve local path for file');
		}

		$realPath = realpath($localPath);
		if ($realPath === false) {
			throw new \RuntimeException('File path could not be resolved: ' . $localPath);
		}

		return $realPath;
	}

	/**
	 * Tell Nextcloud's file cache that the file has changed so etag/size/mtime
	 * are refreshed. This lets Maps/Memories (and the Files app) see the updated file.
	 */
	private function refreshCache(File $file, string $localPath): void {
		$storage    = $file->getStorage();
		$internalPath = $file->getInternalPath();

		// Propagate the change up the directory tree (updates etags).
		$storage->getPropagator()->propagateChange($internalPath, time());

		// Update the cache entry for this file directly.
		$cache = $storage->getCache();
		$entry  = $cache->get($internalPath);
		if ($entry !== false) {
			$cache->update($entry->getId(), [
				'size'  => filesize($localPath),
				'mtime' => filemtime($localPath),
			]);
		}
	}

	/**
	 * Locate the exiftool binary.
	 * Checks well-known paths first to avoid spawning a shell just to call which.
	 */
	private function resolveExiftool(): string {
		$candidates = [
			'/usr/bin/exiftool',
			'/usr/local/bin/exiftool',
			'/opt/homebrew/bin/exiftool',
			'/bin/exiftool',
		];

		foreach ($candidates as $path) {
			if (is_executable($path)) {
				return $path;
			}
		}

		// Fall back to PATH lookup.
		// as the executable in proc_open's array form (no shell involved).
		$found = trim((string)shell_exec('which exiftool 2>/dev/null'));
		if ($found !== '' && is_executable($found)) {
			return $found;
		}

		throw new \RuntimeException(
			'exiftool is not installed or not in PATH. ' .
			'Please install it on the Nextcloud server (e.g. apt install libimage-exiftool-perl).'
		);
	}
}
