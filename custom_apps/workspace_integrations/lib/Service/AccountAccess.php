<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Service;

use OCP\IUser;

final class AccountAccess {
	public static function allowed(?IUser $user): bool {
		// Guests exposes this exact backend name; the concrete check also covers
		// subclasses. Unrelated backends containing "guest" are not guest accounts.
		return $user !== null && $user->isEnabled()
			&& !($user->getBackend() instanceof \OCA\Guests\UserBackend)
			&& !in_array($user->getBackendClassName(), ['Guests', 'OCA\\Guests\\UserBackend'], true);
	}
}
