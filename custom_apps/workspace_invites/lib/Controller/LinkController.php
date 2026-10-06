<?php

declare(strict_types=1);
namespace OCA\WorkspaceInvites\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\VerificationToken\IVerificationToken;
use Psr\Log\LoggerInterface;

class LinkController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $session,
		private IGroupManager $groups,
		private IUserManager $users,
		private IVerificationToken $tokens,
		private IURLGenerator $urls,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	// Default Nextcloud middleware requires an authenticated administrator and CSRF.
	#[PasswordConfirmationRequired]
	#[UserRateLimit(limit: 20, period: 300)]
	public function create(string $userId): JSONResponse {
		$actor = $this->session->getUser();
		if ($actor === null || !$this->groups->isAdmin($actor->getUID())) {
			return $this->reply(['message' => 'Administrator access required.'], 403);
		}
		if ($userId === '' || strlen($userId) > 255) {
			return $this->reply(['message' => 'Invalid account.'], 400);
		}
		$user = $this->users->get($userId);
		if ($user === null) {
			return $this->reply(['message' => 'Account not found.'], 404);
		}
		if (!$user->isEnabled() || !$user->canChangePassword() || $user->getBackendClassName() !== 'Database') {
			return $this->reply(['message' => 'This account cannot use a local password-setup link.'], 409);
		}
		if ($this->config->getSystemValueString('lost_password_link', '') !== '') {
			return $this->reply(['message' => 'Local password reset is disabled or externally managed.'], 409);
		}
		$email = $user->getEMailAddress();
		// Nextcloud 34's LostController passes this directly to a string parameter.
		// A missing address causes its reset form to fail; do not issue a broken link.
		if ($email === null || trim($email) === '') {
			return $this->reply(['message' => 'Set an email address on this account first. Email delivery does not need to be configured; no email will be sent.'], 409);
		}
		$token = $this->tokens->create($user, 'lostpassword', $email);
		$url = $this->urls->linkToRouteAbsolute('core.lost.resetform', ['userId' => $user->getUID(), 'token' => $token]);
		// Security audit at the default log threshold; never record the token or URL.
		$this->logger->warning('Administrator generated a password-setup link', [
			'app' => 'workspace_invites', 'actor' => $actor->getUID(), 'target' => $user->getUID(),
			'operation' => 'password_setup_link_created',
		]);
		return $this->reply(['url' => $url, 'expiresInSeconds' => 604800], 200);
	}

	private function reply(array $data, int $status): JSONResponse {
		return new JSONResponse($data, $status, [
			'Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache',
			'Referrer-Policy' => 'no-referrer',
		]);
	}
}
