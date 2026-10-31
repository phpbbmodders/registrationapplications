<?php
/**
 * Registration and navigation event integration. GPL-2.0-only.
 */
namespace phpbbmodders\registrationapplications\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	protected $manager;
	protected $request;
	protected $template;
	protected $language;
	protected $auth;
	protected $helper;
	protected $answers = array();

	public function __construct($manager, $request, $template, $language, $auth, $helper)
	{
		$this->manager = $manager;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->auth = $auth;
		$this->helper = $helper;
	}

	public static function getSubscribedEvents()
	{
		return array(
			'core.user_setup' => 'language',
			'core.permissions' => 'permissions',
			'core.page_header' => 'navigation',
			'core.ucp_register_data_before' => 'form',
			'core.ucp_register_data_after' => 'validate',
			'core.ucp_register_register_after' => 'registered',
			'core.delete_user_after' => 'deleted',
			'core.user_active_flip_after' => 'activated',
		);
	}

	public function language($event)
	{
		$lang = $event['lang_set_ext'];
		$lang[] = array('ext_name' => 'phpbbmodders/registrationapplications', 'lang_set' => 'common');
		$event['lang_set_ext'] = $lang;
	}

	public function permissions($event)
	{
		$permissions = $event['permissions'];
		foreach (array('view', 'approve', 'deny') as $action)
		{
			$permissions['m_ra_' . $action] = array('lang' => 'ACL_M_RA_' . strtoupper($action), 'cat' => 'misc');
		}
		$event['permissions'] = $permissions;
	}

	public function navigation()
	{
		if ($this->auth->acl_get('m_ra_view'))
		{
			$this->template->assign_vars(array(
				'U_RA_QUEUE' => $this->helper->route('phpbbmodders_registrationapplications_queue'),
				'RA_PENDING' => $this->manager->count_pending(),
				'RA_ICON_TEMPLATE' => phpbb_version_compare(PHPBB_VERSION, '4.0.0-dev', '>=') ? '@phpbbmodders_registrationapplications/icon_40.html' : '@phpbbmodders_registrationapplications/icon_33.html',
			));
		}
	}

	public function form()
	{
		$this->language->add_lang('common', 'phpbbmodders/registrationapplications');
		$this->answers = $this->request->variable('ra_answers', array(0 => ''), true, \phpbb\request\request_interface::POST);
		foreach ($this->manager->questions() as $question)
		{
			$this->template->assign_block_vars('ra_questions', array(
				'ID' => $question['question_id'], 'LABEL' => $question['question_label'],
				'REQUIRED' => $question['question_required'], 'MIN' => $question['min_chars'], 'MAX' => $question['max_chars'],
				'ANSWER' => isset($this->answers[$question['question_id']]) ? $this->answers[$question['question_id']] : '',
			));
		}
	}

	public function validate($event)
	{
		$errors = $event['error'];
		if (!$this->manager->configured())
		{
			$errors[] = $this->language->lang('RA_REQUIREMENTS');
		}
		$event['error'] = array_merge($errors, $this->manager->validate_answers($this->answers));
	}

	public function registered($event)
	{
		$this->manager->submit((int) $event['user_id'], $this->answers);
	}

	public function deleted($event)
	{
		$this->manager->remove_users($event['user_ids']);
	}

	public function activated($event)
	{
		$this->manager->activated($event['sql_statements']);
	}
}
