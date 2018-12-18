<?php
declare(strict_types=1);


/**
 * Nextcloud - Social Support
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\Social\Service\ActivityPub;


use daita\MySmallPhpTools\Exceptions\MalformedArrayException;
use Exception;
use OC\User\NoUserException;
use OCA\Social\Db\NotesRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\NoteNotFoundException;
use OCA\Social\Exceptions\Request410Exception;
use OCA\Social\Exceptions\RequestException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Note;
use OCA\Social\Model\ActivityPub\Person;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;


class NoteService implements ICoreService {


	const TYPE_PUBLIC = 'public';
	const TYPE_UNLISTED = 'unlisted';
	const TYPE_FOLLOWERS = 'followers';
	const TYPE_DIRECT = 'direct';


	/** @var NotesRequest */
	private $notesRequest;

	/** @var ActivityService */
	private $activityService;

	/** @var ActorService */
	private $actorService;

	/** @var PersonService */
	private $personService;

	/** @var CurlService */
	private $curlService;

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/** @var string */
	private $viewerId = '';


	/**
	 * NoteService constructor.
	 *
	 * @param NotesRequest $notesRequest
	 * @param ActivityService $activityService
	 * @param ActorService $actorService
	 * @param PersonService $personService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		NotesRequest $notesRequest, ActivityService $activityService, ActorService $actorService,
		PersonService $personService, CurlService $curlService, ConfigService $configService,
		MiscService $miscService
	) {
		$this->notesRequest = $notesRequest;
		$this->activityService = $activityService;
		$this->actorService = $actorService;
		$this->personService = $personService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param string $viewerId
	 */
	public function setViewerId(string $viewerId) {
		$this->viewerId = $viewerId;
		$this->notesRequest->setViewerId($viewerId);
	}

	public function getViewerId(): string {
		return $this->viewerId;
	}


	/**
	 * @param string $userId
	 * @param string $content
	 *
	 * @param string $type
	 *
	 * @return Note
	 * @throws ActorDoesNotExistException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 */
	public function generateNote(string $userId, string $content, string $type) {
		$note = new Note();
		$actor = $this->actorService->getActorFromUserId($userId);

		$note->setId($this->configService->generateId('@' . $actor->getPreferredUsername()));
		$note->setPublished(date("c"));
		$note->setAttributedTo(
			$this->configService->getUrlSocial() . '@' . $actor->getPreferredUsername()
		);

		$this->setRecipient($note, $actor, $type);
		$note->setContent($content);
		$note->convertPublished();
		$note->setLocal(true);

		$note->saveAs($this);

		return $note;
	}


	/**
	 * @param Note $note
	 * @param Person $actor
	 * @param string $type
	 */
	private function setRecipient(Note $note, Person $actor, string $type) {
		switch ($type) {
			case self::TYPE_UNLISTED:
				$note->setTo($actor->getFollowers());
				$note->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				$note->addCc(ActivityService::TO_PUBLIC);
				break;

			case self::TYPE_FOLLOWERS:
				$note->setTo($actor->getFollowers());
				$note->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;

			case self::TYPE_DIRECT:
				break;

			default:
				$note->setTo(ActivityService::TO_PUBLIC);
				$note->addCc($actor->getFollowers());
				$note->addInstancePath(
					new InstancePath(
						$actor->getFollowers(), InstancePath::TYPE_FOLLOWERS,
						InstancePath::PRIORITY_LOW
					)
				);
				break;
		}
	}


	/**
	 * @param Note $note
	 * @param string $type
	 * @param string $account
	 */
	public function addRecipient(Note $note, string $type, string $account) {
		if ($account === '') {
			return;
		}

		try {
			$actor = $this->personService->getFromAccount($account);
		} catch (Exception $e) {
			return;
		}

		$instancePath = new InstancePath(
			$actor->getInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM
		);
		if ($type === self::TYPE_DIRECT) {
			$instancePath->setPriority(InstancePath::PRIORITY_HIGH);
			$note->addToArray($actor->getId());
		} else {
			$note->addCc($actor->getId());
		}

		$note->addTag(
			[
				'type' => 'Mention',
				'href' => $actor->getId()
			]
		);

		$note->addInstancePath($instancePath);
	}


	/**
	 * @param Note $note
	 * @param string $type
	 * @param array $accounts
	 */
	public function addRecipients(Note $note, string $type, array $accounts) {
		if ($accounts === []) {
			return;
		}

		foreach ($accounts as $account) {
			$this->addRecipient($note, $type, $account);
		}
	}


	/**
	 * @param Note $note
	 * @param string $replyTo
	 *
	 * @throws InvalidResourceException
	 * @throws MalformedArrayException
	 * @throws NoteNotFoundException
	 * @throws Request410Exception
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 */
	public function replyTo(Note $note, string $replyTo) {
		if ($replyTo === '') {
			return;
		}

		$author = $this->getAuthorFromPostId($replyTo);
		$note->setInReplyTo($replyTo);
		// TODO - type can be NOT public !
		$note->addInstancePath(
			new InstancePath(
				$author->getSharedInbox(), InstancePath::TYPE_INBOX, InstancePath::PRIORITY_HIGH
			)
		);
	}


	/**
	 * This method is called when saving the Note object
	 *
	 * @param ACore $note
	 *
	 * @throws InvalidOriginException
	 */
	public function parse(ACore $note) {

		/** @var Note $note */
		if ($note->isRoot()) {
			return;
		}

		$parent = $note->getParent();
		if (!$parent->isRoot()) {
			return;
		}

		if ($parent->getType() === Create::TYPE) {
			$parent->checkOrigin($note->getAttributedTo());
			$this->save($note);
		}
	}


	/**
	 * @param ACore $note
	 */
	public function save(ACore $note) {
		/** @var Note $note */

		try {
			$this->notesRequest->getNoteById($note->getId());
		} catch (NoteNotFoundException $e) {
			$this->notesRequest->save($note);
		}
	}


	/**
	 * @param Note $note
	 *
	 * @throws ActorDoesNotExistException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 */
	public function deleteLocalNote(Note $note) {
		if (!$note->isLocal()) {
			return;
		}

		$note->setActorId($note->getAttributedTo());
		$this->activityService->deleteActivity($note);
	}


	/**
	 * @param ACore $item
	 */
	public function delete(ACore $item) {
		/** @var Note $item */
		$this->notesRequest->deleteNoteById($item->getId());
	}


	/**
	 * @param string $id
	 *
	 * @return Note
	 * @throws NoteNotFoundException
	 */
	public function getNoteById(string $id): Note {
		return $this->notesRequest->getNoteById($id);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamHome(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamHome($actor, $since, $limit);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamNotifications(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamNotifications($actor, $since, $limit);
	}


	/**
	 * @param string $actorId
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamAccount(string $actorId, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamAccount($actorId, $since, $limit);
	}


	/**
	 * @param Person $actor
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamDirect(Person $actor, int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamDirect($actor, $since, $limit);
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamLocalTimeline(int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamTimeline($since, $limit, true);
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamInternalTimeline(int $since = 0, int $limit = 5): array {
		// TODO - admin should be able to provide a list of 'friendly/internal' instance of ActivityPub
	}


	/**
	 * @param int $since
	 * @param int $limit
	 *
	 * @return Note[]
	 */
	public function getStreamGlobalTimeline(int $since = 0, int $limit = 5): array {
		return $this->notesRequest->getStreamTimeline($since, $limit, false);
	}


	/**
	 * @param $noteId
	 *
	 * @return Person
	 * @throws NoteNotFoundException
	 * @throws RequestException
	 * @throws SocialAppConfigException
	 * @throws InvalidResourceException
	 * @throws Request410Exception
	 * @throws UrlCloudException
	 * @throws MalformedArrayException
	 */
	public function getAuthorFromPostId($noteId) {
		$note = $this->notesRequest->getNoteById($noteId);

		return $this->personService->getFromId($note->getAttributedTo());
	}
}

