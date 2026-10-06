<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
namespace OCP { interface IUserSession {
	public function getUser();
} interface IUserManager {
	public function get($uid);
} interface IRequest {
	public function getHeader($name);
	public function getParam($key, $default = null);
	public function getMethod();
} class Server {
	public static array $services = [];
	public static function get($id) {
		return self::$services[$id];
	}
} }
namespace OCP\AppFramework { class Controller {
} abstract class Middleware {
} }
namespace OCP\AppFramework\Http { class Response {
	public array $headers = ['Content-Length' => '1','X-Preserve' => 'yes'];
	public function __construct(
		public int $status = 200,
	) {
	} public function getStatus() {
		return $this->status;
	} public function getHeaders() {
		return $this->headers;
	} public function setHeaders($h) {
		$this->headers = $h;
	} public function addHeader($k, $v) {
		$this->headers[$k] = $v;
	}
} }
namespace OCA\Talk { class Manager {
	public bool $federated = false;
	public function getRoomForUserByToken($t, $u) {
		return new class($this->federated) {
			public function __construct(
				private bool $f,
			) {
			} public function isFederatedConversation() {
				return $this->f;
			}
		};
	}
} }
namespace OCA\Talk\Model { class Attendee {
	public const ACTOR_USERS = 'users';
} class Bot {
	public const STATE_ENABLED = 1;
	public const FEATURE_WEBHOOK = 1;
	public const FEATURE_EVENT = 4;
} }
namespace OCA\Talk\Controller { class ChatController extends \OCP\AppFramework\Controller {
} }
namespace OCA\Talk\Service { class BotService {
	public array $bots = [];
	public function getBotsForToken($t, $f) {
		return $this->bots;
	} public function isAppForBotEnabled($b) {
		return $b->app;
	}
} class ParticipantService {
	public bool $member = true;
	public function getParticipantByActor($r, $t, $u) {
		if (!$this->member) {
			throw new \RuntimeException('private');
		} return new class($u) {
			public function __construct(
				private string $uid,
			) {
			}public function getAttendee() {
				return $this;
			}public function getActorType() {
				return 'users';
			}public function getActorId() {
				return $this->uid;
			}
		};
	}
} }
namespace {
	require __DIR__ . '/../lib/Service/BotSuggestions.php';
	require __DIR__ . '/../lib/Middleware/MentionMiddleware.php';
	use OCA\WorkspaceBotMentions\Middleware\MentionMiddleware;
	use OCA\WorkspaceBotMentions\Service\BotSuggestions;
	use OCP\Server;

	function check(bool $ok, string $name):void {
		if (!$ok) {
			throw new RuntimeException($name);
		} echo "PASS: $name\n";
	}
	function bot(string $name, int $state = 1, int $room = 1, bool $app = true):object {
		return new class($name, $state, $room, $app) {
			public function __construct(
				private string $name,
				private int $state,
				private int $room,
				public bool $app,
			) {
			}public function isEnabled() {
				return $this->state !== 0 && $this->room !== 0;
			}public function getBotServer() {
				return $this;
			}public function getBotConversation() {
				return new class($this->room) {
					public function __construct(
						private int $s,
					) {
					}public function getState() {
						return $this->s;
					}
				};
			}public function getState() {
				return $this->state;
			}public function getName() {
				return $this->name;
			}public function getUrlHash() {
				return hash('sha256', $this->name);
			}
		};
	}
	$users = new class implements \OCP\IUserSession {
		public bool $logged = true;
		public function getUser() {
			return $this->logged?new class {
				public function getUID() {
					return 'member';
				}
			}:null;
		}
	};
	$manager = new class implements \OCP\IUserManager {
		public array $uids = [];
		public function get($uid) {
			return in_array(strtolower($uid), $this->uids, true)?new stdClass():null;
		}
	};
	$room = new \OCA\Talk\Manager();
	$members = new \OCA\Talk\Service\ParticipantService();
	$bots = new \OCA\Talk\Service\BotService();
	Server::$services = [\OCA\Talk\Manager::class => $room,\OCA\Talk\Service\ParticipantService::class => $members,\OCA\Talk\Service\BotService::class => $bots];
	$s = new BotSuggestions($users, $manager);
	$human = [['id' => 'alice','label' => 'Alice','source' => 'users','mentionId' => 'alice']];
	$bots->bots = [bot('Edison')];
	$out = $s->append($human, 'room1', 'EDI', 20);
	check(count($out) === 2 && $out[0] === $human[0] && $out[1]['mentionId'] === 'Edison' && $out[1]['source'] === 'bots' && $out[1]['label'] === 'Edison (bot)', 'case insensitive bot alias, humans preserved');
	check(array_keys($out[1]) === ['id','label','source','mentionId'], 'strict output whitelist excludes URLs and credentials');
	check($s->append($human, 'room1', 'zzz', 20) === $human, 'search scope');
	check($s->append($human, 'room1', '', 1) === $human, 'human results never displaced');
	check($s->append([], 'bad/token', '', 20) === [], 'invalid room token');
	$users->logged = false;
	check($s->append([], 'room1', '', 20) === [], 'guest denied');
	$users->logged = true;
	$room->federated = true;
	check($s->append([], 'room1', '', 20) === [], 'federation denied');
	$room->federated = false;
	$bots->bots = [bot('Disabled', 0),bot('OffInRoom', 1, 0),bot('GoneApp', 1, 1, false),bot('all'),bot('Bad@Alias'),bot('bad/name'),bot('bad"quote'),bot(' Edison')];
	check($s->append([], 'room1', '', 20) === [], 'disabled unavailable unsafe reserved aliases excluded');
	$bots->bots = [bot('NoSetup', 2)];
	check(count($s->append([], 'room1', '', 20)) === 1, 'no-setup bot remains available as defined by Talk isEnabled');
	$bots->bots = [bot('Edison'),bot('edison')];
	check($s->append([], 'room1', '', 20) === [], 'duplicate aliases ambiguous and excluded');
	$bots->bots = [bot('Edison')];
	$manager->uids = ['edison'];
	check($s->append([], 'room1', '', 20) === [], 'actual user ID collision excluded');
	$manager->uids = [];
	$req = new class implements \OCP\IRequest {
		public string $fed = '';
		public string $method = 'GET';
		public array $params = ['token' => 'room1','search' => 'edi'];
		public function getHeader($n) {
			return $this->fed;
		}public function getParam($k, $d = null) {
			return $this->params[$k] ?? $d;
		}public function getMethod() {
			return $this->method;
		}
	};
	$m = new MentionMiddleware($req, $s);
	$ctrl = new \OCA\Talk\Controller\ChatController();
	$body = json_encode(['ocs' => ['meta' => ['status' => 'ok','statuscode' => 200,'message' => 'OK','extra' => 'preserved'],'data' => $human]]);
	$render = function ($method = 'mentions', $status = 200, $text = null) use ($m, $ctrl, $body) {
		$response = new \OCP\AppFramework\Http\Response($status);
		$m->afterController($ctrl, $method, $response);
		return [$m->beforeOutput($ctrl, $method, $text ?? $body),$response];
	};
	[$result,$response] = $render();
	$parsed = json_decode($result, true);
	check(count($parsed['ocs']['data']) === 2 && $parsed['ocs']['meta']['extra'] === 'preserved' && $response->headers['Cache-Control'] === 'private, no-store', 'successful JSON enriched with metadata and no-store preserved');
	check(!isset($response->headers['Content-Length']) && $response->headers['X-Preserve'] === 'yes', 'remove stale content length, retain other headers');
	check($render('receiveMessages')[0] === $body && $render('mentions', 403)[0] === $body, 'method and HTTP failure gated');
	$req->fed = '1';
	check($render()[0] === $body, 'federated header gated');
	$req->fed = '';
	$req->method = 'POST';
	check($render()[0] === $body, 'verb gated');
	$req->method = 'GET';
	check($render('mentions', 200, '<ocs/>')[0] === '<ocs/>', 'XML unchanged');
	$failed = str_replace('"status":"ok"', '"status":"failure"', $body);
	check($render('mentions', 200, $failed)[0] === $failed, 'OCS failure unchanged');
	$members->member = false;
	check($render()[0] === $body, 'nonparticipant failure closed preserving human response');
	$members->member = true;
	$req->params['limit'] = 'nope';
	check($render()[0] === $body, 'malformed limit unchanged');
	echo "All checks passed.\n";
}
