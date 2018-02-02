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

use Horde_Imap_Client;
use Horde_Imap_Client_Mailbox;
use Horde_Imap_Client_Socket;
use OCA\Mail\Account;
use OCA\Mail\Folder;
use OCA\Mail\Mailbox;
use OCA\Mail\SearchFolder;
use OCA\Mail\SearchMailbox;

class FolderMapper {

	/**
	 * @param Account $account
	 * @param Horde_Imap_Client_Socket $client
	 * @param string $pattern
	 * @return Folder
	 */
	public function getFolders(Account $account, Horde_Imap_Client_Socket $client,
		$pattern = '*') {
		$mailboxes = $client->listMailboxes($pattern, Horde_Imap_Client::MBOX_ALL,
			[
			'delimiter' => true,
			'attributes' => true,
			'special_use' => true,
		]);

		$folders = [];
		foreach ($mailboxes as $mailbox) {
			/**
			 * This is a temporary workaround for when the sieve folder is a subfolder of
			 * INBOX. Once "#386 Subfolders and Dovecot" has been resolved, we can go back
			 * to just comparing to 'dovecot.sieve'.
			 */
			$dovecotSieveFolders = [
				'dovecot.sieve',
				'INBOX.dovecot.sieve'
			];
			if (in_array($mailbox['mailbox']->utf8, $dovecotSieveFolders, true)) {
				// This is a special folder that must not be shown
				continue;
			}

			$folder = new Folder($account, $mailbox['mailbox'], $mailbox['attributes'],
				$mailbox['delimiter']);

			if ($folder->isSearchable()) {
				$folder->setSyncToken($client->getSyncToken($folder->getMailbox()));
			}

			$folders[] = $folder;
			if ($mailbox['mailbox']->utf8 === 'INBOX') {
				$searchFolder = new SearchFolder($account, $mailbox['mailbox'],
					$mailbox['attributes'], $mailbox['delimiter']);
				if ($searchFolder->isSearchable()) {
					$searchFolder->setSyncToken($client->getSyncToken($folder->getMailbox()));
				}
				$folders[] = $searchFolder;
			}
		}
		return $folders;
	}

	/**
	 * @param Folder[] $folders
	 * @return Folder[]
	 */
	public function buildFolderHierarchy(array $folders) {
		$indexedFolders = [];
		foreach ($folders as $folder) {
			$indexedFolders[$folder->getMailbox()] = $folder;
		}

		$top = array_filter($indexedFolders,
			function(Folder $folder) {
			return $folder instanceof SearchFolder || is_null($this->getParentId($folder));
		});

		foreach ($indexedFolders as $folder) {
			if (isset($top[$folder->getMailbox()])) {
				continue;
			}

			$parentId = $this->getParentId($folder);
			if (isset($top[$parentId])) {
				$indexedFolders[$parentId]->addFolder($folder);
			}
		}

		return array_values($top);
	}

	/**
	 * @param Account $account
	 * @param string $id
	 * @return Mailbox
	 */
	public function find(Account $account, $id) {
		$parts = explode('/', $id);
		if (count($parts) > 1 && $parts[1] === 'FLAGGED') {
			$mailbox = new Horde_Imap_Client_Mailbox($parts[0]);
			return new SearchMailbox($conn, $mailbox, []);
		}
		$mailbox = new Horde_Imap_Client_Mailbox($id);
		return new Mailbox($conn, $mailbox, []);
	}

	/**
	 * Get the drafts mailbox
	 *
	 * @param Account $account
	 * @param Horde_Imap_Client_Socket $client
	 * @return Mailbox The best candidate for the "drafts" inbox
	 */
	public function findDraftsFolder(Account $account,
		Horde_Imap_Client_Socket $client) {
		// check for existence
		$draftsFolder = $this->findSpecialFolder($this->getFolders($account, $client),
			'drafts');
		if ($draftsFolder === null) {
			// drafts folder does not exist - let's create one
			// TODO: also search for translated drafts mailboxes
			return $this->create($client, 'Drafts',
					[
					'special_use' => ['drafts'],
			]);
		}
		return $draftsFolder;
	}

	/**
	 * @return Mailbox|null
	 */
	public function findInbox(Account $account) {
		return $this->findSpecialFolder($this->findAll($account), 'inbox');
	}

	/**
	 * Get the "sent mail" mailbox
	 *
	 * @param Account $account
	 * @param Horde_Imap_Client_Socket $client
	 * @return Mailbox
	 */
	public function findSentFolder(Account $account,
		Horde_Imap_Client_Socket $client) {
		//check for existence
		$sentFolders = $this->findSpecialFolder($this->getFolders($account, $client),
			'sent');
		if (is_null($sentFolders)) {
			//sent folder does not exist - let's create one
			//TODO: also search for translated sent mailboxes
			return $this->create($client, 'Sent', [
					'special_use' => ['sent'],
			]);
		}
		return $sentFolders;
	}

	/**
	 * @param Account $account
	 * @param Horde_Imap_Client_Socket $client
	 * @param string $mailBox
	 * @param string $opts
	 * @return Folder
	 */
	public function create(Horde_Imap_Client_Socket $client, $mailBox, $opts = []) {
		$client->createMailbox($mailBox, $opts);

		return $this->find($mailBox);
	}

	/**
	 * @param Account $account
	 * @param string $mailBox
	 */
	public function delete(Account $account, $mailBox) {
		if ($mailBox instanceof Mailbox) {
			$mailBox = $mailBox->getFolderId();
		}
		$conn = $account->getImapConnection();
		$conn->deleteMailbox($mailBox);
	}

	/**
	 * Get mailbox(es) that have the given special use role
	 *
	 * With this method we can get a list of all mailboxes that have been
	 * determined to have a specific special use role. It can also return
	 * the best candidate for this role, for situations where we want
	 * one single folder.
	 *
	 * @param Mailbox[] $mailboxes
	 * @param string $role Special role of the folder we want to get ('sent', 'inbox', etc.)
	 * @param bool $guessBest If set to true, return only the folder with the most messages in it
	 *
	 * @return Mailbox|null
	 */
	private function findSpecialFolder(array $folders, $role) {
		$specialFolders = array_filter($folders,
			function(Folder $folder) use ($role) {
			return in_array($role, $folder->getSpecialUse(), true);
		});

		if (empty($specialFolders)) {
			return null;
		}

		return $this->guessBestMailBox($specialFolders);
	}

	/**
	 * Get 'best' mailbox guess
	 *
	 * For now the best candidate is the one with
	 * the most messages in it.
	 *
	 * @param array $mailboxes
	 * @return Mailbox
	 */
	private function guessBestMailBox(array $mailboxes) {
		$maxMessages = -1;
		$bestGuess = null;
		foreach ($mailboxes as $mailbox) {
			/** @var Mailbox $folder */
			if ($mailbox->getTotalMessages() > $maxMessages) {
				$maxMessages = $mailbox->getTotalMessages();
				$bestGuess = $mailbox;
			}
		}
		return $bestGuess;
	}

	/**
	 * @param Folder $folder
	 * @return string
	 */
	private function getParentId(Folder $folder) {
		$hierarchy = explode($folder->getDelimiter(), $folder->getMailbox());
		if (count($hierarchy) <= 1) {
			// Top level folder
			return null;
		}
		return $hierarchy[0];
	}

	/**
	 * @param Folder[] $folders
	 * @param Horde_Imap_Client_Socket $client
	 */
	public function getFoldersStatus(array $folders,
		Horde_Imap_Client_Socket $client) {
		$mailboxes = array_map(function(Folder $folder) {
			return $folder->getMailbox();
		},
			array_filter($folders,
				function(Folder $folder) {
				return $folder->isSearchable();
			}));

		$status = $client->status($mailboxes);

		foreach ($folders as $folder) {
			if (isset($status[$folder->getMailbox()])) {
				$folder->setStatus($status[$folder->getMailbox()]);
			}
		}
	}

	/**
	 * @param Folder[] $folders
	 */
	public function detectFolderSpecialUse(array $folders) {
		foreach ($folders as $folder) {
			$this->detectSpecialUse($folder);
		}
	}

	/**
	 * Get the special use of the mailbox
	 *
	 * This method reads the attributes sent by the server
	 *
	 * @param Folder $folder
	 */
	private function detectSpecialUse(Folder $folder) {
		/*
		 * @todo: support multiple attributes on same folder
		 * "any given server or  message store may support
		 *  any combination of the attributes"
		 *  https://tools.ietf.org/html/rfc6154
		 */
		/* Convert attributes to lowercase, because gmail
		 * returns them as lowercase (eg. \trash and not \Trash)
		 */
		$specialUseAttributes = [
			strtolower(Horde_Imap_Client::SPECIALUSE_ALL),
			strtolower(Horde_Imap_Client::SPECIALUSE_ARCHIVE),
			strtolower(Horde_Imap_Client::SPECIALUSE_DRAFTS),
			strtolower(Horde_Imap_Client::SPECIALUSE_FLAGGED),
			strtolower(Horde_Imap_Client::SPECIALUSE_JUNK),
			strtolower(Horde_Imap_Client::SPECIALUSE_SENT),
			strtolower(Horde_Imap_Client::SPECIALUSE_TRASH)
		];

		$attributes = array_map(function($n) {
			return strtolower($n);
		}, $folder->getAttributes());

		foreach ($specialUseAttributes as $attr) {
			if (in_array($attr, $attributes)) {
				$folder->addSpecialUse(ltrim($attr, '\\'));
			}
		}

		if (empty($folder->getSpecialUse())) {
			$this->guessSpecialUse($folder);
		}
	}

	/**
	 * Assign a special use based on the name
	 */
	protected function guessSpecialUse(Folder $folder) {
		$specialFoldersDict = [
			'inbox' => ['inbox'],
			'sent' => ['sent', 'sent items', 'sent messages', 'sent-mail', 'sentmail'],
			'drafts' => ['draft', 'drafts'],
			'archive' => ['archive', 'archives'],
			'trash' => ['deleted messages', 'trash'],
			'junk' => ['junk', 'spam', 'bulk mail'],
		];

		$lowercaseExplode = explode($folder->getDelimiter(), $folder->getMailbox(), 2);
		$lowercaseId = strtolower(array_pop($lowercaseExplode));
		foreach ($specialFoldersDict as $specialRole => $specialNames) {
			if (in_array($lowercaseId, $specialNames)) {
				$folder->addSpecialUse($specialRole);
			}
		}
	}

	/**
	 * @param Folder[] $folders
	 * @return Folder[]
	 */
	public function sortFolders(array &$folders) {
		usort($folders,
			function(Folder $f1, Folder $f2) {
			$specialUse1 = $f1->getSpecialUse();
			$specialUse2 = $f2->getSpecialUse();
			$roleA = count($specialUse1) > 0 ? reset($specialUse1) : null;
			$roleB = count($specialUse2) > 0 ? reset($specialUse2) : null;
			$specialRolesOrder = [
				'all' => 0,
				'inbox' => 1,
				'flagged' => 2,
				'drafts' => 3,
				'sent' => 4,
				'archive' => 5,
				'junk' => 6,
				'trash' => 7,
			];
			// if there is a flag unknown to us, we ignore it for sorting :
			// the folder will be sorted by name like any other 'normal' folder
			if (array_key_exists($roleA, $specialRolesOrder) === false) {
				$roleA = null;
			}
			if (array_key_exists($roleB, $specialRolesOrder) === false) {
				$roleB = null;
			}

			if ($roleA === null && $roleB !== null) {
				return 1;
			} elseif ($roleA !== null && $roleB === null) {
				return -1;
			} elseif ($roleA !== null && $roleB !== null) {
				if ($roleA === $roleB) {
					return strcasecmp($f1->getDisplayName(), $f2->getDisplayName());
				} else {
					return $specialRolesOrder[$roleA] - $specialRolesOrder[$roleB];
				}
			}
			// we get here if $roleA === null && $roleB === null
			return strcasecmp($f1->getDisplayName(), $f2->getDisplayName());
		});
	}

}
