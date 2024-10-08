<?php
/**
 * SPDX-FileCopyrightText: 2016 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\User_SAML\Tests\Settings;

use OCA\User_SAML\GroupManager;
use OCA\User_SAML\SAMLSettings;
use OCA\User_SAML\UserBackend;
use OCA\User_SAML\UserData;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\User\Events\UserChangedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class UserBackendTest extends TestCase {
	/** @var UserData|MockObject */
	private $userData;
	/** @var IConfig|MockObject */
	private $config;
	/** @var IURLGenerator|MockObject */
	private $urlGenerator;
	/** @var ISession|MockObject */
	private $session;
	/** @var IDBConnection|MockObject */
	private $db;
	/** @var IUserManager|MockObject */
	private $userManager;
	/** @var GroupManager|MockObject */
	private $groupManager;
	/** @var UserBackend|MockObject */
	private $userBackend;
	/** @var SAMLSettings|MockObject */
	private $SAMLSettings;
	/** @var LoggerInterface|MockObject */
	private $logger;
	/** @var IEventDispatcher|MockObject */
	private $eventDispatcher;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->session = $this->createMock(ISession::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(GroupManager::class);
		$this->SAMLSettings = $this->getMockBuilder(SAMLSettings::class)->disableOriginalConstructor()->getMock();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userData = $this->createMock(UserData::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
	}

	public function getMockedBuilder(array $mockedFunctions = []) {
		if ($mockedFunctions !== []) {
			$this->userBackend = $this->getMockBuilder(UserBackend::class)
				->setConstructorArgs([
					$this->config,
					$this->urlGenerator,
					$this->session,
					$this->db,
					$this->userManager,
					$this->groupManager,
					$this->SAMLSettings,
					$this->logger,
					$this->userData,
					$this->eventDispatcher
				])
				->setMethods($mockedFunctions)
				->getMock();
		} else {
			$this->userBackend = new UserBackend(
				$this->config,
				$this->urlGenerator,
				$this->session,
				$this->db,
				$this->userManager,
				$this->groupManager,
				$this->SAMLSettings,
				$this->logger,
				$this->userData,
				$this->eventDispatcher
			);
		}
	}

	public function testGetBackendName() {
		$this->getMockedBuilder();
		$this->assertSame('user_saml', $this->userBackend->getBackendName());
	}

	public function testUpdateAttributesWithoutAttributes() {
		$this->getMockedBuilder(['getDisplayName']);
		/** @var IUser|MockObject $user */
		$user = $this->createMock(IUser::class);

		$this->config->method('getAppValue')
			->willReturnCallback(function ($appId, $key, $default) {
				return $default;
			});

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('ExistingUser')
			->willReturn($user);
		$user
			->expects($this->once())
			->method('getSystemEMailAddress')
			->willReturn(null);
		$user
			->expects($this->never())
			->method('setSystemEMailAddress');
		$this->userBackend
			->expects($this->once())
			->method('getDisplayName')
			->with('ExistingUser')
			->willReturn('');
		$this->groupManager
			->expects($this->once())
			->method('handleIncomingGroups')
			->with($user, []);
		$this->userBackend->updateAttributes('ExistingUser', []);
	}

	public function testUpdateAttributesWithoutValidUser() {
		$this->getMockedBuilder();

		$this->config->method('getAppValue')
			->willReturnCallback(function ($appId, $key, $default) {
				return $default;
			});

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('ExistingUser')
			->willReturn(null);
		$this->userBackend->updateAttributes('ExistingUser', []);
	}

	public function testUpdateAttributes() {
		$this->getMockedBuilder(['getDisplayName', 'setDisplayName']);
		/** @var IUser|MockObject $user */
		$user = $this->createMock(IUser::class);

		$this->config
			->expects($this->at(0))
			->method('getAppValue')
			->with('user_saml', 'saml-attribute-mapping-email_mapping', '')
			->willReturn('email');
		$this->config
			->expects($this->at(1))
			->method('getAppValue')
			->with('user_saml', 'saml-attribute-mapping-displayName_mapping', '')
			->willReturn('displayname');
		$this->config
			->expects($this->at(2))
			->method('getAppValue')
			->with('user_saml', 'saml-attribute-mapping-quota_mapping', '')
			->willReturn('quota');
		$this->config
			->expects($this->at(3))
			->method('getAppValue')
			->with('user_saml', 'saml-attribute-mapping-group_mapping', '')
			->willReturn('groups');

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('ExistingUser')
			->willReturn($user);
		$user
			->expects($this->once())
			->method('getSystemEMailAddress')
			->willReturn('old@example.com');
		$user
			->expects($this->once())
			->method('setSystemEMailAddress')
			->with('new@example.com');
		$user
			->expects($this->once())
			->method('setQuota')
			->with('50MB');
		$this->userBackend
			->expects($this->once())
			->method('getDisplayName')
			->with('ExistingUser')
			->willReturn('');
		$this->userBackend
			->expects($this->once())
			->method('setDisplayName')
			->with('ExistingUser', 'New Displayname');
		$this->groupManager
			->expects($this->once())
			->method('handleIncomingGroups')
			->with($user, ['groupB', 'groupC']);
		$this->userBackend->updateAttributes('ExistingUser', [
			'email' => 'new@example.com',
			'displayname' => 'New Displayname',
			'quota' => '50MB',
			'groups' => ['groupB', 'groupC'],
		]);
	}

	public function testUpdateAttributesQuotaDefaultFallback() {
		$this->getMockedBuilder(['getDisplayName', 'setDisplayName']);
		/** @var IUser|MockObject $user */
		$user = $this->createMock(IUser::class);


		$this->config->method('getAppValue')
			->willReturnCallback(function ($appId, $key, $default) {
				switch ($key) {
					case 'saml-attribute-mapping-email_mapping':
						return 'email';
					case 'saml-attribute-mapping-displayName_mapping':
						return 'displayname';
					case 'saml-attribute-mapping-quota_mapping':
						return 'quota';
				}
				return $default;
			});

		$this->userManager
			->expects($this->once())
			->method('get')
			->with('ExistingUser')
			->willReturn($user);
		$user
			->expects($this->once())
			->method('getSystemEMailAddress')
			->willReturn('old@example.com');
		$user
			->expects($this->once())
			->method('setSystemEMailAddress')
			->with('new@example.com');
		$user
			->expects($this->once())
			->method('setQuota')
			->with('default');
		$this->userBackend
			->expects($this->once())
			->method('getDisplayName')
			->with('ExistingUser')
			->willReturn('');
		$this->userBackend
			->expects($this->once())
			->method('setDisplayName')
			->with('ExistingUser', 'New Displayname');
		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with(new UserChangedEvent($user, 'displayName', 'New Displayname', ''));
		$this->groupManager
			->expects($this->once())
			->method('handleIncomingGroups')
			->with($user, []);
		$this->userBackend->updateAttributes('ExistingUser', ['email' => 'new@example.com', 'displayname' => 'New Displayname', 'quota' => '']);
	}
}
