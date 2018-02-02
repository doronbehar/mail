<?php

/**
 * @author Alexander Weidinger <alexwegoo@gmail.com>
 * @author Christian Nöding <christian@noeding-online.de>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Christoph Wurst <ChristophWurst@users.noreply.github.com>
 * @author Christoph Wurst <wurst.christoph@gmail.com>
 * @author Clement Wong <mail@clement.hk>
 * @author gouglhupf <dr.gouglhupf@gmail.com>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Robin McCorkell <rmccorkell@karoshi.org.uk>
 * @author Thomas Imbreckx <zinks@iozero.be>
 * @author Thomas I <thomas@oatr.be>
 * @author Thomas Mueller <thomas.mueller@tmit.eu>
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

namespace OCA\Mail;

use Horde_Imap_Client;
use Horde_Imap_Client_Socket;
use Horde_Mail_Rfc822_List;
use Horde_Mail_Transport;
use Horde_Mail_Transport_Null;
use Horde_Mail_Transport_Smtphorde;
use Horde_Mime_Headers_Date;
use Horde_Mime_Headers_MessageId;
use Horde_Mime_Mail;
use JsonSerializable;
use OCA\Mail\Cache\Cache;
use OCA\Mail\Db\Alias;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Model\IMessage;
use OCA\Mail\Model\Message;
use OCA\Mail\Model\ReplyMessage;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;

class Account implements JsonSerializable {

	/** @var MailAccount */
	private $account;

	/** @var Horde_Imap_Client_Socket */
	private $client;

	/** @var ICrypto */
	private $crypto;

	/** @var IConfig */
	private $config;

	/** @var ICacheFactory */
	private $memcacheFactory;

	/** @var Alias */
	private $alias;

	/**
	 * @param MailAccount $account
	 * @param ICrypto $crypto
	 * @param IConfig $config
	 * @param ICacheFactory $cacheFactory
	 * @param IL10N $l10n
	 */
	public function __construct(MailAccount $account, ICrypto $crypto,
		IConfig $config, ICacheFactory $cacheFactory) {
		$this->account = $account;
		$this->crypto = $crypto;
		$this->config = $config;
		$this->memcacheFactory = $cacheFactory;
		$this->alias = null;
	}

	public function getMailAccount() {
		return $this->account;
	}

	/**
	 * @return int
	 */
	public function getId() {
		return $this->account->getId();
	}

	/**
	 * @param Alias|null $alias
	 * @return void
	 */
	public function setAlias($alias) {
		$this->alias = $alias;
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->alias ? $this->alias->getName() : $this->account->getName();
	}

	/**
	 * @return string
	 */
	public function getEMailAddress() {
		return $this->account->getEmail();
	}

	/**
	 * @return Horde_Imap_Client_Socket
	 */
	public function getImapConnection() {
		if (is_null($this->client)) {
			$host = $this->account->getInboundHost();
			$user = $this->account->getInboundUser();
			$password = $this->account->getInboundPassword();
			$password = $this->crypto->decrypt($password);
			$port = $this->account->getInboundPort();
			$ssl_mode = $this->convertSslMode($this->account->getInboundSslMode());

			$params = [
				'username' => $user,
				'password' => $password,
				'hostspec' => $host,
				'port' => $port,
				'secure' => $ssl_mode,
				'timeout' => (int) $this->config->getSystemValue('app.mail.imap.timeout', 20),
			];
			if ($this->config->getSystemValue('debug', false)) {
				$params['debug'] = $this->config->getSystemValue('datadirectory') . '/horde_imap.log';
			}
			if ($this->config->getSystemValue('app.mail.server-side-cache.enabled', true)) {
				if ($this->memcacheFactory->isAvailable()) {
					$params['cache'] = [
						'backend' => new Cache(array(
							'cacheob' => $this->memcacheFactory
								->createDistributed(md5($this->getId() . $this->getEMailAddress()))
					))];
				}
			}
			$this->client = new \Horde_Imap_Client_Socket($params);
			$this->client->login();
		}
		return $this->client;
	}

	/**
	 * Send a new message or reply to an existing message
	 *
	 * @param IMessage $message
	 * @param Horde_Mail_Transport $transport
	 * @param Mailbox $sentFolder
	 * @return int message UID
	 */
	public function sendMessage(IMessage $message, Horde_Mail_Transport $transport, Mailbox $sentFolder) {
		// build mime body
		$headers = [
			'From' => $message->getFrom()->first()->toHorde(),
			'To' => $message->getTo()->toHorde(),
			'Cc' => $message->getCC()->toHorde(),
			'Bcc' => $message->getBCC()->toHorde(),
			'Subject' => $message->getSubject(),
		];

		if (!is_null($message->getRepliedMessage())) {
			$headers['In-Reply-To'] = $message->getRepliedMessage()->getMessageId();
		}

		$mail = new Horde_Mime_Mail();
		$mail->addHeaders($headers);
		$mail->setBody($message->getContent());

		// Append cloud attachments
		foreach ($message->getCloudAttachments() as $attachment) {
			$mail->addMimePart($attachment);
		}
		// Append local attachments
		foreach ($message->getLocalAttachments() as $attachment) {
			$mail->addMimePart($attachment);
		}

		// Send the message
		$mail->send($transport, false, false);

		// Save the message in the sent folder
		$raw = stream_get_contents($mail->getRaw());
		$uid = $sentFolder->saveMessage($raw, [
			Horde_Imap_Client::FLAG_SEEN
		]);

		return $uid;
	}

	/**
	 * @param IMessage $message
	 * @return int
	 */
	public function saveDraft(IMessage $message) {
		// build mime body
		$headers = [
			'From' => $message->getFrom()->first()->toHorde(),
			'To' => $message->getTo()->toHorde(),
			'Cc' => $message->getCC()->toHorde(),
			'Bcc' => $message->getBCC()->toHorde(),
			'Subject' => $message->getSubject(),
			'Date' => Horde_Mime_Headers_Date::create(),
		];

		$mail = new Horde_Mime_Mail();
		$mail->addHeaders($headers);
		$mail->setBody($message->getContent());
		$mail->addHeaderOb(Horde_Mime_Headers_MessageId::create());

		// "Send" the message
		$transport = new Horde_Mail_Transport_Null();
		$mail->send($transport, false, false);
		// save the message in the drafts folder
		// TODO: fix
		$draftsFolder = $this->getDraftsFolder();
		$raw = stream_get_contents($mail->getRaw());
		return $draftsFolder->saveDraft($raw);
	}

	/**
	 * @return array
	 */
	public function jsonSerialize() {
		return $this->account->toJson();
	}

	/**
	 * Convert special security mode values into Horde parameters
	 *
	 * @param string $sslMode
	 * @return false|string
	 */
	protected function convertSslMode($sslMode) {
		switch ($sslMode) {
			case 'none':
				return false;
		}
		return $sslMode;
	}

	/**
	 * @return string|Horde_Mail_Rfc822_List
	 */
	public function getEmail() {
		return $this->account->getEmail();
	}

	public function testConnectivity(Horde_Mail_Transport $transport) {
		// connect to imap
		$this->getImapConnection();

		// connect to smtp
		if ($transport instanceof Horde_Mail_Transport_Smtphorde) {
			$transport->getSMTPObject();
		}
	}

	/**
	 * Factory method for creating new messages
	 *
	 * @return IMessage
	 */
	public function newMessage() {
		return new Message();
	}

	/**
	 * Factory method for creating new reply messages
	 *
	 * @return ReplyMessage
	 */
	public function newReplyMessage() {
		return new ReplyMessage();
	}

}
