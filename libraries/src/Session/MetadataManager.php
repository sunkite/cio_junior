<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Session;

defined('JPATH_PLATFORM') or die;

use Joomla\Application\AbstractApplication;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Exception\ExecutionFailureException;
use Joomla\Database\ParameterType;

/**
 * Manager for optional session metadata.
 *
 * @since  3.8.6
 * @internal
 */
final class MetadataManager
{
	/**
	 * Application object.
	 *
	 * @var    AbstractApplication
	 * @since  3.8.6
	 */
	private $app;

	/**
	 * Database driver.
	 *
	 * @var    DatabaseInterface
	 * @since  3.8.6
	 */
	private $db;

	/**
	 * MetadataManager constructor.
	 *
	 * @param   AbstractApplication  $app  Application object.
	 * @param   DatabaseInterface    $db   Database driver.
	 *
	 * @since   3.8.6
	 */
	public function __construct(AbstractApplication $app, DatabaseInterface $db)
	{
		$this->app = $app;
		$this->db  = $db;
	}

	/**
	 * Create the metadata record if it does not exist.
	 *
	 * @param   Session  $session  The session to create the metadata record for.
	 * @param   User     $user     The user to associate with the record.
	 *
	 * @return  void
	 *
	 * @since   3.8.6
	 * @throws  \RuntimeException
	 */
	public function createRecordIfNonExisting(Session $session, User $user)
	{
		$sessionId = $session->getId();

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('session_id'))
			->from($this->db->quoteName('#__session'))
			->where($this->db->quoteName('session_id') . ' = :session_id')
			->bind(':session_id', $sessionId)
			->setLimit(1);

		$this->db->setQuery($query);

		try
		{
			$exists = $this->db->loadResult();
		}
		catch (ExecutionFailureException $e)
		{
			return;
		}

		// If the session record doesn't exist initialise it.
		if ($exists)
		{
			return;
		}

		$query->clear();

		$time = $session->isNew() ? time() : $session->get('session.timer.start');

		$columns = [
			$this->db->quoteName('session_id'),
			$this->db->quoteName('guest'),
			$this->db->quoteName('time'),
			$this->db->quoteName('userid'),
			$this->db->quoteName('username'),
		];

		// Add query placeholders
		$values = [
			':session_id',
			':guest',
			':time',
			':user_id',
			':username',
		];

		// Bind query values
		$userIsGuest = $user->guest;
		$userId      = $user->id;
		$username    = $user->username;

		$query->bind(':session_id', $sessionId)
			->bind(':guest', $userIsGuest, ParameterType::BOOLEAN)
			->bind(':time', $time, ParameterType::INTEGER)
			->bind(':user_id', $userId, ParameterType::INTEGER)
			->bind(':username', $username);

		if ($this->app instanceof CMSApplication && !$this->app->get('shared_session', false))
		{
			$clientId = $this->app->getClientId();

			$columns[] = $this->db->quoteName('client_id');
			$values[] = ':client_id';

			$query->bind(':client_id', $clientId, ParameterType::INTEGER);
		}

		$query->insert($this->db->quoteName('#__session'))
			->columns($columns)
			->values(implode(', ', $values));

		$this->db->setQuery($query);

		try
		{
			$this->db->execute();
		}
		catch (ExecutionFailureException $e)
		{
			// This failure isn't critical, we can go on without the metadata
		}
	}

	/**
	 * Delete records with a timestamp prior to the given time.
	 *
	 * @param   integer  $time  The time records should be deleted if expired before.
	 *
	 * @return  void
	 *
	 * @since   3.8.6
	 */
	public function deletePriorTo($time)
	{
		$query = $this->db->getQuery(true)
			->delete($this->db->quoteName('#__session'))
			->where($this->db->quoteName('time') . ' < :time')
			->bind(':time', $time, ParameterType::INTEGER);

		$this->db->setQuery($query);

		try
		{
			$this->db->execute();
		}
		catch (ExecutionFailureException $exception)
		{
			// Since garbage collection does not result in a fatal error when run in the session API, we don't allow it here either.
		}
	}
}
