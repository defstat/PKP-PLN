<?php

/**
 * @file classes/DepositObjectDAO.inc.php
 *
 * Copyright (c) 2013-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DepositObjectDAO
 * @brief Operations for adding a PLN deposit object
 */

import('lib.pkp.classes.db.DAO');

class DepositObjectDAO extends DAO {
	/**
	 * Retrieve a deposit object by deposit object id.
	 * @param $journalId int
	 * @param $depositObjectId int
	 * @return DepositObject
	 */
	public function getById($journalId, $depositObjectId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposit_objects WHERE journal_id = ? and deposit_object_id = ?',
			array(
				(int) $journalId,
				(int) $depositObjectId
			)
		);

		$returner = null;
		if ($result->RecordCount() != 0) {
			$returner = $this->_fromRow($result->GetRowAssoc(false));
		}

		$result->Close();

		return $returner;
	}

	/**
	 * Retrieve all deposit objects by deposit id.
	 * @param $journalId int
	 * @param $depositId int
	 * @return array DepositObject ordered by sequence
	 */
	public function getByDepositId($journalId, $depositId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposit_objects WHERE journal_id = ? AND deposit_id = ?',
			array (
				(int) $journalId,
				(int) $depositId
			)
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposit objects with no deposit id.
	 * @param $journalId int
	 * @return array DepositObject ordered by sequence
	 */
	public function getNew($journalId) {
		$result = $this->retrieve(
			'SELECT * FROM pln_deposit_objects WHERE journal_id = ? AND deposit_id = 0',
			(int) $journalId
		);

		return new DAOResultFactory($result, $this, '_fromRow');
	}

	/**
	 * Retrieve all deposit objects with no deposit id.
	 * @param $journalId int
	 * @param $objectType string
	 */
	public function markHavingUpdatedContent($journalId, $objectType) {
		$depositDao = DAORegistry::getDAO('DepositDAO');

		switch ($objectType) {
			case PLN_PLUGIN_DEPOSIT_OBJECT_ARTICLE:
				$result = $this->retrieve(
					'SELECT pdo.deposit_object_id, a.last_modified FROM pln_deposit_objects pdo
					LEFT JOIN articles a ON pdo.object_id = a.article_id
					WHERE a.journal_id = ? AND pdo.journal_id = ? AND pdo.date_modified < a.last_modified',
					array (
						(int) $journalId,
						(int) $journalId
					)
				);
				while (!$result->EOF) {
					$row = $result->GetRowAssoc(false);
					$depositObject = $this->getById($journalId, $row['deposit_object_id']);
					$deposit = $depositDao->getById($depositObject->getDepositId());
					if($deposit->getSentStatus() || !$deposit->getTransferredStatus()) {
						// only update a deposit after it has been synced in LOCKSS.
						$depositObject->setDateModified($row['last_modified']);
						$this->updateObject($depositObject);
						$deposit->setNewStatus();
						$deposit->setLockssAgreementStatus(true); // this is an update.
						$depositDao->updateObject($deposit);
					}
					$result->MoveNext();
				}
				$result->Close();
				break;
			case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
				$result = $this->retrieve(
					'SELECT pdo.deposit_object_id, MAX(i.last_modified) as issue_modified, MAX(a.last_modified) as article_modified
					FROM issues i
					LEFT JOIN pln_deposit_objects pdo ON pdo.object_id = i.issue_id
					LEFT JOIN published_submissions pa ON pa.issue_id = i.issue_id
					LEFT JOIN submissions a ON a.submission_id = pa.submission_id
					WHERE (pdo.date_modified < a.last_modified OR pdo.date_modified < i.last_modified)
					AND (pdo.journal_id = ?)
					GROUP BY pdo.deposit_object_id',
					(int) $journalId
				);
				while (!$result->EOF) {
					$row = $result->GetRowAssoc(false);
					$depositObject = $this->getById($journalId, $row['deposit_object_id']);
					$deposit = $depositDao->getById($depositObject->getDepositId());
					if($deposit->getSentStatus() || !$deposit->getTransferredStatus()) {
						// only update a deposit after it has been synced in LOCKSS.
						if ($row['issue_modified'] > $row['article_modified']) {
							$depositObject->setDateModified($row['issue_modified']);
						} else {
							$depositObject->setDateModified($row['article_modified']);
						}

						$this->updateObject($depositObject);
						$deposit->setNewStatus();
						$deposit->setLockssAgreementStatus(true); // this is an update.
						$depositDao->updateObject($deposit);
					}
					$result->MoveNext();
				}
				$result->Close();
				break;
			default: assert(false);
		}
	}

	/**
	 * Create a new deposit object for OJS content that doesn't yet have one
	 *
	 * @param $journalId int
	 * @param $objectType string
	 * @return array DepositObject ordered by sequence
	 */
	public function createNew($journalId, $objectType) {
		$objects = array();

		switch ($objectType) {
			case PLN_PLUGIN_DEPOSIT_OBJECT_ARTICLE:
				$published_article_dao = DAORegistry::getDAO('PublishedArticleDAO');
				$result = $this->retrieve(
					'SELECT pa.submission_id FROM published_submissions pa
					LEFT JOIN submissions a ON pa.submission_id = a.submission_id
					LEFT JOIN pln_deposit_objects pdo ON pa.submission_id = pdo.object_id
					WHERE a.journal_id = ? AND pdo.object_id is null',
					(int) $journalId
				);
				while (!$result->EOF) {
					$row = $result->GetRowAssoc(false);
					$objects[] = $published_article_dao->getPublishedArticleByArticleId($row['article_id']);
					$result->MoveNext();
				}
				$result->Close();
				break;
			case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
				$issueDao = DAORegistry::getDAO('IssueDAO');
				$result = $this->retrieve(
					'SELECT i.issue_id
					FROM issues i
					LEFT JOIN pln_deposit_objects pdo ON pdo.object_id = i.issue_id
					WHERE i.journal_id = ?
					AND i.published = 1
					AND pdo.object_id is null',
					(int) $journalId
				);
				while (!$result->EOF) {
					$row = $result->GetRowAssoc(false);
					$objects[] = $issueDao->getById($row['issue_id']);
					$result->MoveNext();
				}
				$result->Close();
				break;
			default: assert(false);
		}

		$depositObjects = array();
		foreach($objects as $object) {
			$depositObject = $this->newDataObject();
			$depositObject->setContent($object);
			$depositObject->setJournalId($journalId);
			$this->insertObject($depositObject);
			$depositObjects[] = $depositObject;
		}

		return $depositObjects;
	}

	/**
	 * Insert deposit object
	 * @param $depositObject DepositObject
	 * @return int inserted DepositObject id
	 */
	public function insertObject($depositObject) {
		$this->update(
			sprintf('
				INSERT INTO pln_deposit_objects
					(journal_id,
					object_id,
					object_type,
					deposit_id,
					date_created,
					date_modified)
				VALUES
					(?, ?, ?, ?, NOW(), %s)',
				$this->datetimeToDB($depositObject->getDateModified())
			),
			array(
				(int) $depositObject->getJournalId(),
				(int) $depositObject->getObjectId(),
				$depositObject->getObjectType(),
				(int)$depositObject->getDepositId()
			)
		);

		$depositObject->setId($this->getInsertId());
		return $depositObject->getId();
	}

	/**
	 * Update deposit object
	 * @param $depositObject DepositObject
	 */
	public function updateObject($depositObject) {
		$this->update(
			sprintf('
				UPDATE pln_deposit_objects SET
					journal_id = ?,
					object_type = ?,
					object_id = ?,
					deposit_id = ?,
					date_created = %s,
					date_modified = NOW()
				WHERE deposit_object_id = ?',
				$this->datetimeToDB($depositObject->getDateCreated())
			),
			array(
				(int) $depositObject->getJournalId(),
				$depositObject->getObjectType(),
				(int) $depositObject->getObjectId(),
				(int) $depositObject->getDepositId(),
				(int) $depositObject->getId()
			)
		);
	}

	/**
	 * Delete deposit object
	 * @param $depositObject Deposit
	 */
	public function deleteObject($depositObject) {
		$this->update(
			'DELETE from pln_deposit_objects WHERE deposit_object_id = ?',
			(int) $depositObject->getId()
		);
	}

	/**
	 * Get the ID of the last inserted deposit object.
	 * @return int
	 */
	public function getInsertId() {
		return $this->_getInsertId('pln_deposit_objects', 'object_id');
	}

	/**
	 * Construct a new data object corresponding to this DAO.
	 * @return DepositObject
	 */
	public function newDataObject() {
		return new DepositObject();
	}

	/**
	 * Internal function to return a deposit object from a row.
	 * @param $row array
	 * @return DepositObject
	 */
	public function _fromRow($row) {
		$depositObject = $this->newDataObject();
		$depositObject->setId($row['deposit_object_id']);
		$depositObject->setJournalId($row['journal_id']);
		$depositObject->setObjectType($row['object_type']);
		$depositObject->setObjectId($row['object_id']);
		$depositObject->setDepositId($row['deposit_id']);
		$depositObject->setDateCreated($this->datetimeFromDB($row['date_created']));
		$depositObject->setDateModified($this->datetimeFromDB($row['date_modified']));

		HookRegistry::call('DepositObjectDAO::_fromRow', array(&$depositObject, &$row));

		return $depositObject;
	}
}
