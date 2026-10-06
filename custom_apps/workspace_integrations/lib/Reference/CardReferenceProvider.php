<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceIntegrations\Reference;

use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\IReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IURLGenerator;

/** Shared reference caches contain static metadata only; never fetch a card here. */
class CardReferenceProvider implements IReferenceProvider {
	public function __construct(
		private IURLGenerator $urls,
	) {
	}
	public function cardIdOf(string $url): ?string {
		$base = rtrim($this->urls->getAbsoluteURL('/'), '/') . '/';
		if (!str_starts_with($url, $base)) {
			return null;
		}
		return preg_match('#^(?:index\.php/)?apps/workspace_integrations/cards/([a-f0-9]{32})$#D', substr($url, strlen($base)), $m) === 1 ? $m[1] : null;
	}
	public function matchReference(string $referenceText): bool {
		return $this->cardIdOf($referenceText) !== null;
	}
	public function resolveReference(string $referenceText): ?IReference {
		$id = $this->cardIdOf($referenceText);
		if ($id === null) {
			return null;
		}
		$ref = new Reference($referenceText);
		$ref->setTitle('Notification details');
		$ref->setUrl($referenceText);
		$ref->setRichObject('workspace_notification_card', ['cardId' => $id]);
		return $ref;
	}
	public function getCachePrefix(string $referenceId): string {
		return $this->cardIdOf($referenceId) ?? '';
	}
	public function getCacheKey(string $referenceId): ?string {
		return null;
	}
}
