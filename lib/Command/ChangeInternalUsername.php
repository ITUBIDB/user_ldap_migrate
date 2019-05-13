<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @author Semih Serhat Karakaya <karakayasemi@itu.edu.tr>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
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

namespace OCA\User_LDAP_Migrate\Command;

use OC;
use OCA\User_LDAP\Mapping\UserMapping;
use OCA\User_LDAP\User_Proxy;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeInternalUsername extends Command {
	/** @var \OCP\UserInterface $ldapBackend */
	private $ldapBackend;

	/** @var UserMapping $userMapping */
	private $userMapping;

	/** @var IDBConnection $dbConection */
	private $dbConection;

	public function __construct() {
		parent::__construct();
		$this->ldapBackend = OC::$server->getUserManager()->getBackend(User_Proxy::class);
		$this->userMapping = new UserMapping(OC::$server->getDatabaseConnection());
		$this->dbConection = OC::$server->getDatabaseConnection();
	}

	protected function configure() {
		$this
			->setName('user_ldap_migrate:change_all_internal_names')
			->setDescription('Fix wrong database entries when changing internal username');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$builder = $this->dbConection->getQueryBuilder();
		$mappings = $builder->select(['owncloud_name', 'directory_uuid'])
			->from('ldap_user_mapping')
			->execute()
			->fetchAll();
		$progressBar = new ProgressBar($output, count($mappings));
		$progressBar->setBarCharacter('<fg=green>⚬</>');
		$progressBar->setEmptyBarCharacter("<fg=red>⚬</>");
		$progressBar->setProgressCharacter("<fg=green>➤</>");
		$progressBar->setFormat(
			"<fg=white;bg=magenta> %status:-80s%</>\n%current%/%max% [%bar%] %percent:3s%%\n🏁"
		);
		$progressBar->start();
		foreach ($mappings as $mapping) {
			$progressBar->setMessage(
				"Changing " . $mapping['owncloud_name'] . " internal username as " . $mapping['directory_uuid'] ,
				'status'
			);
			$this->fixDbRecords($mapping['owncloud_name'], $mapping['directory_uuid']);
			$progressBar->setMessage($mapping['owncloud_name']);
			$progressBar->advance();
		}
		$progressBar->finish();
		return 0;
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	protected function fixDbRecords($username, $uuid) {
		$this->fixAccountRecord($username, $uuid);
		$this->fixActivityRecords($username, $uuid);
		$this->fixAuthTokenRecords($username, $uuid);
		$this->fixCommentRecords($username, $uuid);
		$this->fixCommentRecordReadMarkers($username, $uuid);
		$this->fixLdapUserMappingRecord($username, $uuid);
		$this->fixMountRecords($username, $uuid);
		$this->fixPreferencesRecords($username, $uuid);
		$this->fixShareRecords($username, $uuid);
		$this->fixStorageRecord($username, $uuid);
		$this->fixVcategoryRecords($username, $uuid);
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixAccountRecord($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('accounts')
			->set('user_id', $builder->createNamedParameter($uuid))
			->set('lower_user_id', $builder->createNamedParameter(strtolower($uuid)))
			->where($exprBuilder->eq('user_id', $exprBuilder->literal($username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixActivityRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$oldParam = '"' . $username . '"';
		$newParam = '"' . $uuid . '"';
		//get all user related activities
		$activitiesOfUser = $builder
			->select(['activity_id', 'user', 'affecteduser', 'subjectparams'])
			->from('activity')
			->where($exprBuilder->eq('user', $exprBuilder->literal($username)))
			->orWhere($exprBuilder->eq('affecteduser', $exprBuilder->literal($username)))
			->orWhere($exprBuilder->like('subjectparams', $exprBuilder->literal('%' . $oldParam . '%')))
			->execute()
			->fetchAll();

		foreach ($activitiesOfUser as $activity) {
			$builder = $this->dbConection->getQueryBuilder();
			$exprBuilder = $builder->expr();
			if($activity['user'] === $username) {
				$activity['user'] = $uuid;

			}
			if($activity['affecteduser'] === $username) {
				$activity['affecteduser'] = $uuid;

			}
			$activity['subjectparams'] = str_replace($oldParam, $newParam, $activity['subjectparams']);
			$builder
				->update('activity')
				->set('user', $builder->createNamedParameter($activity['user']))
				->set('affecteduser', $builder->createNamedParameter($activity['affecteduser']))
				->set('subjectparams', $builder->createNamedParameter($activity['subjectparams']))
				->where($exprBuilder->eq('activity_id', $exprBuilder->literal($activity['activity_id'])))
				->execute();
		}
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixAuthTokenRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('authtoken')
			->set('uid', $builder->createNamedParameter($uuid))
			->where($exprBuilder->eq('uid', $exprBuilder->literal($username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixCommentRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('comments')
			->set('actor_id', $builder->createNamedParameter($uuid))
			->where($exprBuilder->eq('actor_id', $exprBuilder->literal($username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixCommentRecordReadMarkers($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('comments_read_markers')
			->set('user_id', $builder->createNamedParameter($uuid))
			->where($exprBuilder->eq('user_id', $exprBuilder->literal($username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixLdapUserMappingRecord($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('ldap_user_mapping')
			->set('owncloud_name', $builder->createNamedParameter($uuid))
			->where($exprBuilder->eq('owncloud_name', $exprBuilder->literal($username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixMountRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();

		$mountsOfUser = $builder
			->select(['id', 'user_id', 'mount_point'])
			->from('mounts')
			->where($exprBuilder->eq('user_id', $exprBuilder->literal($username)))
			->execute()
			->fetchAll();

		$oldMountStartEntry = "/$username/";
		$newMountStartEntry = "/$uuid/";
		foreach ($mountsOfUser as $mount) {
			$builder = $this->dbConection->getQueryBuilder();
			$exprBuilder = $builder->expr();
			$newMountPoint = $mount['mount_point'];
			if(substr($newMountPoint, 0, strlen($oldMountStartEntry)) === $oldMountStartEntry) {
				$newMountPoint = $newMountStartEntry . substr($newMountPoint, strlen($oldMountStartEntry));
			}
			$builder
				->update('mounts')
				->set('user_id', $builder->createNamedParameter($uuid))
				->set('mount_point', $builder->createNamedParameter($newMountPoint))
				->where($exprBuilder->eq('id', $exprBuilder->literal($mount['id'])))
				->execute();
		}
	}
	
	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixPreferencesRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('preferences')
			->set('userid', $builder->createNamedParameter($uuid))
			->where($exprBuilder->eq('userid', $exprBuilder->literal($username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixShareRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();

		$sharesOfUser = $builder
			->select(['id', 'share_with', 'uid_owner', 'uid_initiator'])
			->from('share')
			->where($exprBuilder->eq('share_with', $exprBuilder->literal($username)))
			->orWhere($exprBuilder->eq('uid_owner', $exprBuilder->literal($username)))
			->orWhere($exprBuilder->eq('uid_initiator', $exprBuilder->literal($username)))
			->execute()
			->fetchAll();

		foreach ($sharesOfUser as $share) {
			$builder = $this->dbConection->getQueryBuilder();
			$exprBuilder = $builder->expr();
			if($share['share_with'] === $username) {
				$share['share_with'] = $uuid;

			}
			if($share['uid_owner'] === $username) {
				$share['uid_owner'] = $uuid;

			}
			if($share['uid_initiator'] === $username) {
				$share['uid_initiator'] = $uuid;

			}
			$builder
				->update('share')
				->set('share_with', $builder->createNamedParameter($share['share_with']))
				->set('uid_owner', $builder->createNamedParameter($share['uid_owner']))
				->set('uid_initiator', $builder->createNamedParameter($share['uid_initiator']))
				->where($exprBuilder->eq('id', $exprBuilder->literal($share['id'])))
				->execute();
		}
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixStorageRecord($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('storages')
			->set('id', $builder->createNamedParameter('home::' . $uuid))
			->where($exprBuilder->eq('id', $exprBuilder->literal('home::' . $username)))
			->execute();
	}

	/**
	 * @param string $username
	 * @param string $uuid
	 */
	private function fixVcategoryRecords($username, $uuid) {
		$builder = $this->dbConection->getQueryBuilder();
		$exprBuilder = $builder->expr();
		$builder
			->update('vcategory')
			->set('uid', $builder->createNamedParameter($uuid))
			->where($exprBuilder->eq('uid', $exprBuilder->literal($username)))
			->execute();
	}
}
