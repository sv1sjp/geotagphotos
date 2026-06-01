<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'geotag#getExif',   'url' => '/api/exif/{fileId}', 'verb' => 'GET'],
		['name' => 'geotag#setExif',   'url' => '/api/exif/{fileId}', 'verb' => 'POST'],
		['name' => 'geotag#clearExif', 'url' => '/api/exif/{fileId}', 'verb' => 'DELETE'],
	],
];
