<?php

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Run as Nextcloud's web user. Default: inspect; --apply enables both sounds.
 * NEXTCLOUD_ROOT defaults to /var/www/html. No core files are modified.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
	exit(1);
}
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';

$app = \OCP\Server::get(\OCP\IAppConfig::class);
$users = \OCP\Server::get(\OCP\IUserManager::class);
$prefs = \OCP\Server::get(\OCP\Config\IUserConfig::class);
$keys = ['sound_notification', 'sound_talk'];
$apply = in_array('--apply', $argv, true);
$report = ['apply' => $apply, 'defaults' => [], 'users' => []];
foreach ($keys as $key) {
	if ($apply) {
		$app->setValueBool('notifications', $key, true);
	}
	$report['defaults'][$key] = $app->getValueBool('notifications', $key);
	if ($apply && !$report['defaults'][$key]) {
		throw new \RuntimeException('Default verification failed');
	}
}
$users->callForAllUsers(function (\OCP\IUser $user) use ($keys, $apply, $prefs, $app, &$report): void {
	$id = $user->getUID();
	foreach ($keys as $key) {
		if ($apply) {
			$prefs->setValueBool($id, 'notifications', $key, true);
		}
		$value = $prefs->getValueBool($id, 'notifications', $key, $app->getValueBool('notifications', $key));
		$report['users'][$id][$key] = $value;
		if ($apply && !$value) {
			throw new \RuntimeException('User preference verification failed');
		}
	}
});
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
