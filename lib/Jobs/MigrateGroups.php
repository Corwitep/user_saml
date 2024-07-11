<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\User_SAML\Jobs;

use OCA\User_SAML\GroupBackend;
use OCA\User_SAML\GroupManager;
use OCA\User_SAML\Service\GroupMigration;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\DB\Exception;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class MigrateGroups
 *
 * @package OCA\User_SAML\Jobs
 * @todo: remove this, when dropping Nextcloud 29 support
 */
class MigrateGroups extends QueuedJob {
	use TTransactional;

	protected const BATCH_SIZE = 1000;

	/** @var IConfig */
	private $config;
	/** @var IGroupManager */
	private $groupManager;
	/** @var IDBConnection */
	private $dbc;
	/** @var GroupBackend */
	private $ownGroupBackend;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		protected GroupMigration $groupMigration,
		protected GroupManager $ownGroupManager,
		IConfig $config,
		IGroupManager $groupManager,
		IDBConnection $dbc,
		GroupBackend $ownGroupBackend,
		LoggerInterface $logger,
		ITimeFactory $timeFactory,
	) {
		parent::__construct($timeFactory);
		$this->config = $config;
		$this->groupManager = $groupManager;
		$this->dbc = $dbc;
		$this->ownGroupBackend = $ownGroupBackend;
		$this->logger = $logger;
	}

	protected function run($argument) {
		try {
			$candidates = $this->getMigratableGroups();
			$toMigrate = $this->getGroupsToMigrate($argument['gids'], $candidates);
			$migrated = $this->migrateGroups($toMigrate);
			$this->updateCandidatePool($migrated);
		} catch (\RuntimeException $e) {
			return;
		}
	}

	protected function updateCandidatePool(array $migratedGroups): void {
		$candidateInfo = $this->config->getAppValue('user_saml', GroupManager::LOCAL_GROUPS_CHECK_FOR_MIGRATION, '');
		if ($candidateInfo === '' || $candidateInfo === GroupManager::STATE_MIGRATION_PHASE_EXPIRED) {
			return;
		}
		$candidateInfo = \json_decode($candidateInfo, true);
		if (!isset($candidateInfo['dropAfter']) || !isset($candidateInfo['groups'])) {
			return;
		}
		$candidateInfo['groups'] = array_diff($candidateInfo['groups'], $migratedGroups);
		$this->config->setAppValue(
			'user_saml',
			GroupManager::LOCAL_GROUPS_CHECK_FOR_MIGRATION,
			json_encode($candidateInfo)
		);
	}

	protected function migrateGroups(array $toMigrate): array {
		return array_filter($toMigrate, function ($gid) {
			return $this->migrateGroup($gid);
		});
	}

	protected function migrateGroup(string $gid): bool {
		$isMigrated = false;
		$allUsersInserted = false;
		try {
			$allUsersInserted = $this->groupMigration->migrateGroupUsers($gid);

			$this->dbc->beginTransaction();

			$qb = $this->dbc->getQueryBuilder();
			$affected = $qb->delete('groups')
				->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
				->executeStatement();
			if ($affected === 0) {
				throw new \RuntimeException('Could not delete group from local backend');
			}
			if (!$this->ownGroupBackend->createGroup($gid)) {
				throw new \RuntimeException('Could not create group in SAML backend');
			}

			$this->dbc->commit();
			$isMigrated = true;
		} catch (Throwable $e) {
			$this->dbc->rollBack();
			$this->logger->warning($e->getMessage(), ['app' => 'user_saml', 'exception' => $e]);
		}

		if ($allUsersInserted && $isMigrated) {
			try {
				$this->groupMigration->cleanUpOldGroupUsers($gid);
			} catch (Exception $e) {
				$this->logger->warning('Error while cleaning up group members in (oc_)group_user of group (gid) {gid}', [
					'app' => 'user_saml',
					'gid' => $gid,
					'exception' => $e,
				]);
			}
		}

		return $isMigrated;
	}

	protected function getGroupsToMigrate(array $samlGroups, array $pool): array {
		return array_filter($samlGroups, function (string $gid) use ($pool) {
			if (!in_array($gid, $pool)) {
				return false;
			}

			$group = $this->groupManager->get($gid);
			if ($group === null) {
				return false;
			}

			$backendNames = $group->getBackendNames();
			if (!in_array('Database', $backendNames, true)) {
				return false;
			}

			foreach ($group->getUsers() as $user) {
				if ($user->getBackendClassName() !== 'user_saml') {
					return false;
				}
			}

			return true;
		});
	}

	protected function getMigratableGroups(): array {
		$candidateInfo = $this->ownGroupManager->getCandidateInfoIfValid();
		if ($candidateInfo === null) {
			throw new \RuntimeException('No migration tasks of groups to SAML backend');
		}

		return $candidateInfo['groups'];
	}
}
