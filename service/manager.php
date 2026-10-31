<?php
/**
 * Private application storage and staff decisions. GPL-2.0-only.
 */
namespace phpbbmodders\registrationapplications\service;

class manager
{
	protected $db;
	protected $config;
	protected $auth;
	protected $user;
	protected $language;
	protected $notifications;
	protected $log;
	protected $container;
	protected $questions_table;
	protected $applications_table;
	protected $root_path;
	protected $php_ext;

	public function __construct($db, $config, $auth, $user, $language, $notifications, $log, $container, $questions_table, $applications_table, $root_path, $php_ext)
	{
		$this->db = $db;
		$this->config = $config;
		$this->auth = $auth;
		$this->user = $user;
		$this->language = $language;
		$this->notifications = $notifications;
		$this->log = $log;
		$this->container = $container;
		$this->questions_table = $questions_table;
		$this->applications_table = $applications_table;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	public function configured()
	{
		return (int) $this->config['require_activation'] === USER_ACTIVATION_ADMIN && $this->config['email_enable'];
	}

	public function denial_mode()
	{
		return $this->config['ra_denial_mode'] === 'delete' ? 'delete' : 'retain';
	}

	public function questions($all = false)
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->questions_table .
			($all ? '' : ' WHERE question_enabled = 1') . ' ORDER BY question_order, question_id');
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	/** Validate decoded text by Unicode code points, never HTML-entity length. */
	public function validate_answers(array $answers)
	{
		$errors = array();
		foreach ($this->questions() as $question)
		{
			$value = isset($answers[$question['question_id']]) ? trim(html_entity_decode($answers[$question['question_id']], ENT_QUOTES, 'UTF-8')) : '';
			$length = utf8_strlen($value);
			if (($question['question_required'] && !$length) ||
				($length && ($length < $question['min_chars'] || $length > $question['max_chars'])))
			{
				$errors[] = $this->language->lang('RA_ANSWER_LENGTH', $question['question_label'], (int) $question['min_chars'], (int) $question['max_chars']);
			}
		}
		return $errors;
	}

	/** Store question snapshots so later edits cannot change an application. */
	public function submit($user_id, array $answers)
	{
		$snapshot = array();
		foreach ($this->questions() as $question)
		{
			$snapshot[] = array('label' => $question['question_label'], 'answer' => isset($answers[$question['question_id']]) ? $answers[$question['question_id']] : '');
		}
		$this->db->sql_query('INSERT INTO ' . $this->applications_table . ' ' . $this->db->sql_build_array('INSERT', array(
			'user_id' => (int) $user_id,
			'answers' => json_encode($snapshot),
			'submitted_at' => time(),
		)));
	}

	protected function pending_where()
	{
		return "a.status = 'pending' AND u.user_type = " . USER_INACTIVE .
			' AND u.user_inactive_reason = ' . INACTIVE_REGISTER . ' AND u.user_id <> ' . ANONYMOUS;
	}

	public function count_pending()
	{
		$result = $this->db->sql_query('SELECT COUNT(*) AS total FROM ' . $this->applications_table . ' a JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id WHERE ' . $this->pending_where());
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);
		return $total;
	}

	public function pending($start = 0)
	{
		$result = $this->db->sql_query_limit('SELECT a.*, u.username FROM ' . $this->applications_table . ' a JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id WHERE ' . $this->pending_where() . ' ORDER BY a.submitted_at, a.user_id', 20, max(0, (int) $start));
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function pending_name($id)
	{
		$result = $this->db->sql_query('SELECT u.username FROM ' . $this->applications_table . ' a JOIN ' . USERS_TABLE . ' u ON u.user_id = a.user_id WHERE ' . $this->pending_where() . ' AND a.user_id = ' . (int) $id);
		$name = $this->db->sql_fetchfield('username');
		$this->db->sql_freeresult($result);
		return $name;
	}

	/**
	 * Authorize again here; callers cannot turn view access into deletion.
	 * Returns false when the saved approval's email could not be sent.
	 */
	public function decide($user_id, $action, $expected_mode)
	{
		if (!$this->auth->acl_get('m_ra_view') || !in_array($action, array('approve', 'deny'), true) ||
			!$this->auth->acl_get('m_ra_' . $action))
		{
			throw new \RuntimeException('NOT_AUTHORISED');
		}
		if ($action === 'deny' && $expected_mode !== $this->denial_mode())
		{
			throw new \RuntimeException('RA_MODE_CHANGED');
		}
		include_once($this->root_path . 'includes/functions_user.' . $this->php_ext);
		$this->db->sql_transaction('begin');
		// Claim the application with a conditional write, serializing staff decisions.
		$this->db->sql_query('UPDATE ' . $this->applications_table . " SET status = 'reviewing' WHERE user_id = " . (int) $user_id . " AND status = 'pending'");
		if (!$this->db->sql_affectedrows())
		{
			$this->db->sql_transaction('rollback');
			throw new \RuntimeException('RA_STALE');
		}
		$result = $this->db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id);
		$account = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if (!$account || (int) $account['user_type'] !== USER_INACTIVE ||
			(int) $account['user_inactive_reason'] !== INACTIVE_REGISTER || (int) $user_id === ANONYMOUS ||
			(int) $user_id === (int) $this->user->data['user_id'])
		{
			$this->db->sql_transaction('rollback');
			throw new \RuntimeException('RA_STALE');
		}
		// Lock and recheck the account itself, including against concurrent core changes.
		$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET user_inactive_reason = ' . INACTIVE_MANUAL .
			' WHERE user_id = ' . (int) $user_id . ' AND user_type = ' . USER_INACTIVE .
			' AND user_inactive_reason = ' . INACTIVE_REGISTER);
		if (!$this->db->sql_affectedrows())
		{
			$this->db->sql_transaction('rollback');
			throw new \RuntimeException('RA_STALE');
		}
		if ($action === 'approve')
		{
			user_active_flip('activate', (int) $user_id);
			$result = $this->db->sql_query('SELECT user_type FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id);
			$activated_type = $this->db->sql_fetchfield('user_type');
			$this->db->sql_freeresult($result);
			if ($activated_type === false || (int) $activated_type !== USER_NORMAL)
			{
				$this->db->sql_transaction('rollback');
				throw new \RuntimeException('RA_STALE');
			}
			$this->db->sql_query('UPDATE ' . USERS_TABLE . " SET user_actkey = '' WHERE user_id = " . (int) $user_id);
		}
		else if ($this->denial_mode() === 'delete')
		{
			// Never delete posts through this workflow, even if another extension created some.
			user_delete('retain', (int) $user_id);
		}
		else
		{
			$this->db->sql_query('UPDATE ' . USERS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE', array(
				'user_inactive_reason' => INACTIVE_MANUAL, 'user_actkey' => '', 'user_newpasswd' => '',
			)) . ' WHERE user_id = ' . (int) $user_id);
		}
		// Answers are needed only for review; erase them when the decision is made.
		$this->db->sql_query('UPDATE ' . $this->applications_table . ' SET ' . $this->db->sql_build_array('UPDATE', array(
			'status' => $action === 'approve' ? 'approved' : 'denied',
			'answers' => '[]', 'reviewed_at' => time(), 'reviewer_id' => (int) $this->user->data['user_id'],
		)) . ' WHERE user_id = ' . (int) $user_id);
		$this->notifications->delete_notifications('notification.type.admin_activate_user', (int) $user_id);
		$this->log->add('mod', $this->user->data['user_id'], $this->user->ip, $action === 'approve' ? 'LOG_RA_APPROVED' : 'LOG_RA_DENIED', false, array($account['username']));
		$this->db->sql_transaction('commit');
		if ($action === 'approve')
		{
			return $this->send_activation_email($account);
		}
		return true;
	}

	/** phpBB 4 uses the messenger service collection; 3.3 uses messenger. */
	protected function send_activation_email(array $account)
	{
		if (!$this->config['email_enable'])
		{
			return false;
		}
		if ($this->container->has('messenger.method_collection'))
		{
			$messenger = $this->container->get('messenger.method_collection')->offsetGet('messenger.method.email');
			$messenger->set_use_queue(false);
		}
		else
		{
			include_once($this->root_path . 'includes/functions_messenger.' . $this->php_ext);
			$messenger = new \messenger(false);
		}
		$messenger->template('admin_welcome_activated', $account['user_lang']);
		$messenger->set_addresses($account);
		$messenger->anti_abuse_headers($this->config, $this->user);
		$messenger->assign_vars(array('USERNAME' => html_entity_decode($account['username'], ENT_COMPAT, 'UTF-8')));
		if ($this->container->has('messenger.method_collection'))
		{
			return $messenger->send();
		}
		else
		{
			return $messenger->send(NOTIFY_EMAIL);
		}
	}

	/** Remove private answers if a user is deleted through phpBB itself. */
	public function remove_users(array $ids)
	{
		if ($ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->applications_table . ' WHERE ' . $this->db->sql_in_set('user_id', array_map('intval', $ids)));
		}
	}

	public function activated(array $statements)
	{
		foreach ($statements as $id => $row)
		{
			if ((int) $row['user_type'] === USER_NORMAL)
			{
				$this->db->sql_query('UPDATE ' . $this->applications_table . " SET status = 'approved', answers = '[]', reviewed_at = " . time() . ', reviewer_id = ' . (int) $this->user->data['user_id'] . ' WHERE user_id = ' . (int) $id . " AND status = 'pending'");
			}
		}
	}
}
