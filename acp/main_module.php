<?php
/** Registration Applications settings and question editor. GPL-2.0-only. */
namespace phpbbmodders\registrationapplications\acp;

class main_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $config, $db, $user;
		$language = $phpbb_container->get('language');
		$language->add_lang('common', 'phpbbmodders/registrationapplications');
		$manager = $phpbb_container->get('phpbbmodders.registrationapplications.manager');
		$table = $phpbb_container->getParameter('phpbbmodders.registrationapplications.questions_table');
		$this->tpl_name = 'acp_registrationapplications';
		$this->page_title = 'ACP_RA_SETTINGS';
		add_form_key('ra_settings');
		$error = '';
		$edit_id = $request->variable('edit', 0);
		$values = array('question_label' => '', 'question_required' => 1, 'min_chars' => 0, 'max_chars' => 3000, 'question_order' => 0, 'question_enabled' => 1);
		if ($request->is_set_post('save_settings') || $request->is_set_post('save_question'))
		{
			if (!check_form_key('ra_settings'))
			{
				trigger_error('FORM_INVALID' . adm_back_link($this->u_action), E_USER_WARNING);
			}
			if ($request->is_set_post('save_settings'))
			{
				$denial = $request->variable('denial_mode', 'retain');
				if (!in_array($denial, array('retain', 'delete'), true))
				{
					trigger_error('RA_INVALID_SETTINGS' . adm_back_link($this->u_action), E_USER_WARNING);
				}
				$config->set('ra_denial_mode', $denial);
				trigger_error($language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
			}
			$edit_id = $request->variable('question_id', 0);
			$values = array(
				'question_label' => trim($request->variable('question_label', '', true)),
				'question_required' => $request->variable('question_required', false) ? 1 : 0,
				'min_chars' => $request->variable('min_chars', 0), 'max_chars' => $request->variable('max_chars', 3000),
				'question_order' => $request->variable('question_order', 0), 'question_enabled' => $request->variable('question_enabled', false) ? 1 : 0,
			);
			if (!$values['question_label'] || utf8_strlen($values['question_label']) > 255 ||
				$values['min_chars'] < 0 || $values['max_chars'] < 1 || $values['max_chars'] > 10000 ||
				$values['min_chars'] > $values['max_chars'] || $values['question_order'] < 0)
			{
				$error = $language->lang('RA_INVALID_SETTINGS');
			}
			else if (!$edit_id && count($manager->questions(true)) >= 20)
			{
				$error = $language->lang('RA_QUESTION_LIMIT');
			}
			else
			{
				if ($edit_id)
				{
					$db->sql_query('UPDATE ' . $table . ' SET ' . $db->sql_build_array('UPDATE', $values) . ' WHERE question_id = ' . (int) $edit_id);
				}
				else
				{
					$db->sql_query('INSERT INTO ' . $table . ' ' . $db->sql_build_array('INSERT', $values));
				}
				trigger_error($language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
			}
		}
		foreach ($manager->questions(true) as $question)
		{
			$template->assign_block_vars('questions', array(
				'LABEL' => $question['question_label'], 'ENABLED' => $question['question_enabled'],
				'U_EDIT' => $this->u_action . '&amp;edit=' . (int) $question['question_id'],
			));
			if (!$error && $edit_id == $question['question_id'])
			{
				$values = $question;
			}
		}
		$template->assign_vars(array(
			'U_ACTION' => $this->u_action, 'ERROR_MSG' => $error,
			'S_RA_CONFIGURED' => $manager->configured(), 'S_DELETE' => $manager->denial_mode() === 'delete',
			'QUESTION_ID' => $edit_id, 'QUESTION_LABEL' => $values['question_label'], 'QUESTION_REQUIRED' => $values['question_required'],
			'MIN_CHARS' => $values['min_chars'], 'MAX_CHARS' => $values['max_chars'], 'QUESTION_ORDER' => $values['question_order'],
			'QUESTION_ENABLED' => $values['question_enabled'], 'U_NEW' => $this->u_action,
		));
	}
}
