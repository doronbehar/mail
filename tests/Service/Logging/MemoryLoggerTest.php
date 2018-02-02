<?php

/**
 * @copyright 2017 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2017 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
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

namespace OCA\Mail\Tests\Service\Logging;

use OCA\Mail\Service\Logging\MemoryLogger;
use PHPUnit_Framework_TestCase;

class MemoryLoggerTest extends PHPUnit_Framework_TestCase {

	/** @var MemoryLogger */
	private $logger;

	protected function setUp() {
		parent::setUp();

		$this->logger = new MemoryLogger();
	}

	public function testGetStream() {
		$stream = $this->logger->getStream();

		$this->assertTrue(is_resource($stream));
	}

}
