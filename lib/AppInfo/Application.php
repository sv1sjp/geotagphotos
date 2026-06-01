<?php

declare(strict_types=1);

/**
 * GeoTag Photos — Nextcloud app
 *
 * @author    Dimitris Vagiakakos <dimitrislinuxos@protonmail.ch>
 * @copyright 2026 Dimitris Vagiakakos @sv1sjp - TuxHouse EU
 * @license   GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace OCA\GeotagPhotos\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'geotagphotos';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {}

	public function boot(IBootContext $context): void {
		\OCP\Util::addScript(self::APP_ID, self::APP_ID . '-main');
	}
}
