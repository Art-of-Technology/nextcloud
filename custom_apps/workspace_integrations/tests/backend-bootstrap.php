<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
$nc = getenv('NEXTCLOUD_SRC') ?: '/usr/src/nextcloud';
require $nc . '/3rdparty/autoload.php';
require $nc . '/lib/composer/autoload.php';
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\WorkspaceIntegrations\\';
	if (str_starts_with($class, $prefix)) {
		$file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
