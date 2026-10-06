<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCA\WorkspaceBotMentions\Middleware;

use OCA\WorkspaceBotMentions\Service\BotSuggestions;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;

class MentionMiddleware extends Middleware {
	private bool $eligible = false;
	public function __construct(
		private IRequest $request,
		private BotSuggestions $suggestions
	) {
	}
	private function target(Controller $controller, string $methodName): bool {
		return get_class($controller) === 'OCA\\Talk\\Controller\\ChatController' && $methodName === 'mentions';
	}
	public function afterController(Controller $controller, string $methodName, Response $response) {
		$this->eligible = $this->target($controller, $methodName)
			&& $response->getStatus() === 200
			&& $this->request->getHeader('x-nextcloud-federation') === ''
			&& $this->request->getMethod() === 'GET';
		if ($this->eligible) {
			// Output may grow after headers are collected by the dispatcher.
			$headers = $response->getHeaders();
			foreach (array_keys($headers) as $name) {
				if (strcasecmp($name, 'Content-Length') === 0) {
					unset($headers[$name]);
				}
			}
			$response->setHeaders($headers);
			$response->addHeader('Cache-Control', 'private, no-store');
			$response->addHeader('Pragma', 'no-cache');
		}
		return $response;
	}
	public function beforeOutput(Controller $controller, string $methodName, string $output) {
		if (!$this->eligible || !$this->target($controller, $methodName)) {
			return $output;
		}
		$this->eligible = false;
		try {
			$body = json_decode($output, true, 64, JSON_THROW_ON_ERROR);
			if (($body['ocs']['meta']['status'] ?? null) !== 'ok'
				|| !in_array($body['ocs']['meta']['statuscode'] ?? null, [100, 200], true)
				|| !is_array($body['ocs']['data'] ?? null) || !array_is_list($body['ocs']['data'])) {
				return $output;
			}
			$token = $this->request->getParam('token', '');
			$search = $this->request->getParam('search', '');
			$limit = filter_var($this->request->getParam('limit', 20), FILTER_VALIDATE_INT);
			if (!is_string($token) || !is_string($search) || $limit === false || $limit < 1) {
				return $output;
			}
			$data = $this->suggestions->append($body['ocs']['data'], $token, $search, $limit);
			if ($data === $body['ocs']['data']) {
				return $output;
			}
			$body['ocs']['data'] = $data;
			return json_encode($body, JSON_HEX_TAG | JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			// Fail closed for bots; never interrupt the existing human picker or expose exception details.
			return $output;
		}
	}
}
