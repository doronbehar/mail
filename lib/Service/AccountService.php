<?php

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

use Exception;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Db\MailAccountMapper;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\DefaultAccount\Manager;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;

class AccountService {

	/** @var MailAccountMapper */
	private $mapper;

	/** @var IL10N */
	private $l10n;

	/** @var Manager */
	private $defaultAccountManager;

	/** @var Logger */
	private $logger;

	/** @var ICacheFactory */
	private $cacheFactory;

	/** @var ICrypto */
	private $crypto;

	/** @var IConfig */
	private $config;

	/**
	 * Cache accounts for multiple calls to 'findByUserId'
	 *
	 * @var Account[]
	 */
	private $accounts;

	/**
	 * @param MailAccountMapper $mapper
	 * @param IL10N $l10n
	 * @param Manager $defaultAccountManager
	 * @param ICrypto $crypto
	 * @param IConfig $config
	 * @param ICacheFactory $cacheFactory
	 * @param \OCA\Mail\Service\Logger $logger
	 */
	public function __construct(MailAccountMapper $mapper, IL10N $l10n,
		Manager $defaultAccountManager, ICrypto $crypto, IConfig $config,
		ICacheFactory $cacheFactory, Logger $logger) {
		$this->mapper = $mapper;
		$this->l10n = $l10n;
		$this->defaultAccountManager = $defaultAccountManager;
		$this->crypto = $crypto;
		$this->config = $config;
		$this->cacheFactory = $cacheFactory;
		$this->logger = $logger;
	}

	public function newAccount(MailAccount $mailAccount) {
		return new Account($mailAccount, $this->crypto, $this->config,
			$this->cacheFactory, $this->logger, $this->l10n);
	}

	/**
	 * @param string $currentUserId
	 * @return Account[]
	 */
	public function findByUserId($currentUserId) {
		if ($this->accounts === null) {
			$accounts = array_map(function($a) {
				return $this->newAccount($a);
			}, $this->mapper->findByUserId($currentUserId));

			$defaultAccount = $this->defaultAccountManager->getDefaultAccount();
			if (!is_null($defaultAccount)) {
				$accounts[] = $this->newAccount($defaultAccount);
			}

			$this->accounts = $accounts;
		}

		return $this->accounts;
	}

	/**
	 * @param $currentUserId
	 * @param $accountId
	 * @return Account
	 */
	public function find($currentUserId, $accountId) {
		if ($this->accounts !== null) {
			foreach ($this->accounts as $account) {
				if ($account->getId() === $accountId) {
					return $account;
				}
			}
			throw new Exception("Invalid account id <$accountId>");
		}

		if ((int) $accountId === Manager::ACCOUNT_ID) {
			$defaultAccount = $this->defaultAccountManager->getDefaultAccount();
			if (is_null($defaultAccount)) {
				throw new Exception('Default account config missing');
			}
			return $this->newAccount($defaultAccount);
		}
		return $this->newAccount($this->mapper->find($currentUserId, $accountId));
	}

	private function moveMessageOnSameAccount(Account $account, $sourceFolderId,
		$destFolderId, $messageId) {
		$account->moveMessage(base64_decode($sourceFolderId), $messageId,
			base64_decode($destFolderId));
	}

	public function moveMessage($accountId, $folderId, $id, $destAccountId,
		$destFolderId, $userId) {
		$sourceAccount = $this->find($userId, $accountId);
		$destAccount = $this->find($userId, $destAccountId);

		if ($sourceAccount->getId() === $destAccount->getId()) {
			$this->moveMessageOnSameAccount($sourceAccount, $folderId, $destFolderId, $id);
		} else {
			throw new ServiceException('It is not possible to move across accounts yet');
		}
	}

	/**
	 * @param int $accountId
	 */
	public function delete($currentUserId, $accountId) {
		if ((int) $accountId === Manager::ACCOUNT_ID) {
			return;
		}
		$mailAccount = $this->mapper->find($currentUserId, $accountId);
		$this->mapper->delete($mailAccount);
	}

	/**
	 * @param MailAccount $newAccount
	 * @return MailAccount
	 */
	public function save(MailAccount $newAccount) {
		return $this->mapper->save($newAccount);
	}

}
