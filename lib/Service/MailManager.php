<?php

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * Mail
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Mail\Service;

use Horde_Imap_Client_Ids;
use Horde_Imap_Client_Mailbox;
use OCA\Mail\Account;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Folder;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\Sync\Request;
use OCA\Mail\IMAP\Sync\Response;
use OCA\Mail\IMAP\Sync\Synchronizer;
use OCA\Mail\Mailbox;
use OCA\Mail\Service\FolderMapper;
use OCA\Mail\Service\FolderNameTranslator;

class MailManager implements IMailManager {

	/** @var IMAPClientFactory */
	private $imapClientFactory;

	/** @var FolderMapper */
	private $folderMapper;

	/** @var FolderNameTranslator */
	private $folderNameTranslator;

	/** @var Synchronizer */
	private $synchronizer;

	/**
	 * @param IMAPClientFactory $imapClientFactory
	 * @param FolderMapper $folderMapper
	 * @param FolderNameTranslator $folderNameTranslator
	 * @param Synchronizer $synchronizer
	 */
	public function __construct(IMAPClientFactory $imapClientFactory,
		FolderMapper $folderMapper, FolderNameTranslator $folderNameTranslator,
		Synchronizer $synchronizer) {
		$this->imapClientFactory = $imapClientFactory;
		$this->folderMapper = $folderMapper;
		$this->folderNameTranslator = $folderNameTranslator;
		$this->synchronizer = $synchronizer;
	}

	/**
	 * @param Account $account
	 * @return Folder[]
	 */
	public function getFolders(Account $account) {
		$client = $this->imapClientFactory->getClient($account);

		$folders = $this->folderMapper->getFolders($account, $client);
		$this->folderMapper->getFoldersStatus($folders, $client);
		$this->folderMapper->detectFolderSpecialUse($folders);
		$this->folderMapper->sortFolders($folders);
		$this->folderNameTranslator->translateAll($folders);
		return $this->folderMapper->buildFolderHierarchy($folders);
	}

	/**
	 * @param Account $account
	 * @param Request $syncRequest
	 * @return Response
	 */
	public function syncMessages(Account $account, Request $syncRequest) {
		$client = $this->imapClientFactory->getClient($account);

		return $this->synchronizer->sync($client, $syncRequest);
	}

	/**
	 * @param Account $account
	 * @param string $sourceFolderId
	 * @param int  $messageId
	 * @param string $destFolderId
	 */
	public function moveMessage(Account $account, $sourceFolderId, $messageId,
		$destFolderId) {
		$client = $this->imapClientFactory->getClient($account);

		$client->copy($sourceFolderId, $destFolderId,
			[
			'ids' => new Horde_Imap_Client_Ids($messageId),
			'move' => true,
		]);
	}

	/**
	 * @param Account $account
	 * @param string $sourceFolderId
	 * @param int $messageId
	 */
	public function deleteMessage(Account $account, $sourceFolderId, $messageId) {
		$client = $this->imapClientFactory->getClient($account);

		// TODO: fix
		$mb = $this->getMailbox($sourceFolderId);
		// by default we will create a 'Trash' folder if no trash is found
		$trashId = "Trash";
		$createTrash = true;

		// TODO: FIX
		$trashFolders = $this->getSpecialFolder('trash', true);

		if (count($trashFolders) !== 0) {
			$trashId = $trashFolders[0]->getFolderId();
			$createTrash = false;
		} else {
			// no trash -> guess
			// TODO: FIX
			$trashes = array_filter($this->getMailboxes(),
				function($box) {
				/**
				 * @var Mailbox $box
				 */
				return (stripos($box->getDisplayName(), 'trash') !== false);
			});
			if (!empty($trashes)) {
				$trashId = array_values($trashes);
				$trashId = $trashId[0]->getFolderId();
				$createTrash = false;
			}
		}

		$hordeMessageIds = new Horde_Imap_Client_Ids($messageId);
		$hordeTrashMailBox = new Horde_Imap_Client_Mailbox($trashId);

		if ($sourceFolderId === $trashId) {
			$client->expunge($sourceFolderId,
				[
				'ids' => $hordeMessageIds,
				'delete' => true
			]);

			$this->logger->info("Message expunged: {message} from mailbox {mailbox}",
				[
				'message' => $messageId,
				'mailbox' => $sourceFolderId
			]);
		} else {
			$client->copy($sourceFolderId, $hordeTrashMailBox,
				[
				'create' => $createTrash,
				'move' => true,
				'ids' => $hordeMessageIds
			]);

			$this->logger->info("Message moved to trash: {message} from mailbox {mailbox}",
				[
				'message' => $messageId,
				'mailbox' => $sourceFolderId
			]);
		}
	}

}
