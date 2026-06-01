<?php

declare(strict_types=1);

/**
 * GeoTag Photos — Nextcloud app
 *
 * @author    Dimitris Vagiakakos <dimitrislinuxos@protonmail.ch>
 * @copyright 2024 Dimitris Vagiakakos
 * @license   GNU AGPL version 3 or any later version
 */

namespace OCA\GeotagPhotos\Controller;

use OCA\GeotagPhotos\Service\ExifService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;

class GeotagController extends Controller {
	/** MIME types we accept. JPEG only — PNG/TIFF do not reliably support GPS EXIF. */
	private const ALLOWED_MIMES = ['image/jpeg'];

	public function __construct(
		IRequest $request,
		private ExifService $exifService,
		private IRootFolder $rootFolder,
		private ?string $userId,
	) {
		parent::__construct('geotagphotos', $request);
	}

	// -------------------------------------------------------------------------
	// Routes
	// -------------------------------------------------------------------------

	/**
	 * Return existing GPS EXIF data for a file.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getExif(int $fileId): DataResponse {
		$result = $this->withFile($fileId, function (File $file): DataResponse {
			$gps = $this->exifService->readGpsExif($file);
			return new DataResponse($gps);
		});
		return $result;
	}

	/**
	 * Write GPS EXIF data to a file.
	 *
	 * @NoAdminRequired
	 */
	public function setExif(int $fileId, float $latitude, float $longitude, ?float $altitude = null): DataResponse {
		if ($latitude < -90.0 || $latitude > 90.0) {
			return new DataResponse(['error' => 'Latitude must be between -90 and 90'], Http::STATUS_BAD_REQUEST);
		}
		if ($longitude < -180.0 || $longitude > 180.0) {
			return new DataResponse(['error' => 'Longitude must be between -180 and 180'], Http::STATUS_BAD_REQUEST);
		}

		return $this->withFile($fileId, function (File $file) use ($latitude, $longitude, $altitude): DataResponse {
			$this->exifService->writeGpsExif($file, $latitude, $longitude, $altitude);
			return new DataResponse(['success' => true]);
		});
	}

	/**
	 * Remove all GPS EXIF tags from a file.
	 *
	 * @NoAdminRequired
	 */
	public function clearExif(int $fileId): DataResponse {
		return $this->withFile($fileId, function (File $file): DataResponse {
			$this->exifService->clearGpsExif($file);
			return new DataResponse(['success' => true]);
		});
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve the file and run $callback, returning a DataResponse.
	 * Centralises auth check, MIME check, and exception handling.
	 */
	private function withFile(int $fileId, callable $callback): DataResponse {
		if ($this->userId === null) {
			return new DataResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$file = $this->getUserFile($fileId);
		} catch (NotFoundException) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}

		if (!in_array($file->getMimeType(), self::ALLOWED_MIMES, true)) {
			return new DataResponse(
				['error' => 'Only JPEG files are supported'],
				Http::STATUS_UNSUPPORTED_MEDIA_TYPE
			);
		}

		try {
			return $callback($file);
		} catch (\RuntimeException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Retrieve a File node owned by the current user.
	 *
	 * @throws NotFoundException
	 */
	private function getUserFile(int $fileId): File {
		$nodes = $this->rootFolder
			->getUserFolder($this->userId)
			->getById($fileId);

		if (empty($nodes) || !($nodes[0] instanceof File)) {
			throw new NotFoundException("File $fileId not found in user folder");
		}

		return $nodes[0];
	}
}
