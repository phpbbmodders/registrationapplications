<?php
/**
 * Staff application queue. GPL-2.0-only.
 */
namespace phpbbmodders\registrationapplications\controller;

class queue
{
	protected $manager;
	protected $request;
	protected $template;
	protected $language;
	protected $auth;
	protected $user;
	protected $helper;
	protected $pagination;

	public function __construct($manager, $request, $template, $language, $auth, $user, $helper, $pagination)
	{
		$this->manager = $manager;
		$this->request = $request;
		$this->template = $template;
		$this->language = $language;
		$this->auth = $auth;
		$this->user = $user;
		$this->helper = $helper;
		$this->pagination = $pagination;
	}

	public function handle()
	{
		$this->language->add_lang('common', 'phpbbmodders/registrationapplications');
		if (!$this->auth->acl_get('m_ra_view'))
		{
			throw new \phpbb\exception\http_exception(403, 'NOT_AUTHORISED');
		}
		$url = $this->helper->route('phpbbmodders_registrationapplications_queue');
		$message = '';
		add_form_key('ra_queue');
		if ($this->request->is_set_post('decision'))
		{
			if (!check_form_key('ra_queue'))
			{
				throw new \phpbb\exception\http_exception(400, 'FORM_INVALID');
			}
			$action = $this->request->variable('decision', '', false, \phpbb\request\request_interface::POST);
			$id = $this->request->variable('applicant', 0, false, \phpbb\request\request_interface::POST);
			$mode = $this->request->variable('denial_mode', '', false, \phpbb\request\request_interface::POST);
			if (!in_array($action, array('approve', 'deny'), true) || !$this->auth->acl_get('m_ra_' . $action))
			{
				throw new \phpbb\exception\http_exception(403, 'NOT_AUTHORISED');
			}
			if (!confirm_box(true))
			{
				$name = $this->manager->pending_name($id);
				if (!$name)
				{
					throw new \phpbb\exception\http_exception(409, 'RA_STALE');
				}
				confirm_box(false, $this->language->lang($action === 'approve' ? 'RA_CONFIRM_APPROVE' : ($mode === 'delete' ? 'RA_CONFIRM_DELETE' : 'RA_CONFIRM_RETAIN'), $name), build_hidden_fields(array(
					'decision' => $action, 'applicant' => $id, 'denial_mode' => $mode,
				)), '@phpbbmodders_registrationapplications/confirm.html', $url);
				redirect($url);
			}
			try
			{
				$mail_sent = $this->manager->decide($id, $action, $mode);
				$message = $this->language->lang($mail_sent ? 'RA_DECIDED' : 'RA_APPROVED_MAIL_FAILED');
			}
			catch (\RuntimeException $e)
			{
				$message = $this->language->lang($e->getMessage());
			}
		}
		$total = $this->manager->count_pending();
		$start = max(0, $this->request->variable('start', 0));
		$start = $total ? min($start, (int) (floor(($total - 1) / 20) * 20)) : 0;
		foreach ($this->manager->pending($start) as $application)
		{
			$this->template->assign_block_vars('applications', array(
				'ID' => $application['user_id'], 'USERNAME' => $application['username'],
				'SUBMITTED' => $this->user->format_date($application['submitted_at']),
			));
			foreach ((array) json_decode($application['answers'], true) as $answer)
			{
				$this->template->assign_block_vars('applications.answers', array('LABEL' => $answer['label'], 'TEXT' => $answer['answer']));
			}
		}
		$this->pagination->generate_template_pagination($url, 'pagination', 'start', $total, 20, $start);
		$this->template->assign_vars(array(
			'S_RA_ACTION' => $url, 'RA_MESSAGE' => $message, 'RA_TOTAL' => $total,
			'RA_DENIAL_MODE' => $this->manager->denial_mode(),
			'S_RA_APPROVE' => $this->auth->acl_get('m_ra_approve'), 'S_RA_DENY' => $this->auth->acl_get('m_ra_deny'),
		));
		return $this->helper->render('@phpbbmodders_registrationapplications/queue.html', $this->language->lang('RA_TITLE'));
	}
}
