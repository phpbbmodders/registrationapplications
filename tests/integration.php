<?php
/**
 * Database regressions against a disposable installed phpBB board.
 * Usage: php -d sendmail_path=/bin/true tests/integration.php BOARD_ROOT
 * Requires BOARD_ROOT/.ra-disposable-test; creates synthetic accounts only.
 * GPL-2.0-only.
 */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || in_array($argv[1], array('-h', '--help'), true))
{
	echo "Usage: php -d sendmail_path=/bin/true tests/integration.php BOARD_ROOT\nRequires a disposable board marked with .ra-disposable-test.\n";
	exit(empty($argv[1]) || PHP_SAPI === 'cli' ? 0 : 2);
}
$phpbb_root_path = realpath($argv[1]) . '/';
if (!is_file($phpbb_root_path . '.ra-disposable-test') || ini_get('sendmail_path') !== '/bin/true')
{
	fwrite(STDERR, "Refusing: disposable marker and disabled outbound sendmail required.\n");
	exit(2);
}
define('IN_PHPBB', true);
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
require_once $phpbb_root_path . 'includes/functions_user.php';
$user->session_begin();
$result = $db->sql_query('SELECT * FROM ' . USERS_TABLE . ' WHERE user_id = 2');
$user->data = array_merge($user->data, $db->sql_fetchrow($result));
$db->sql_freeresult($result);
$auth->acl($user->data);
$user->setup();
$language = $phpbb_container->get('language');
$language->add_lang('common', 'phpbbmodders/registrationapplications');
$manager = $phpbb_container->get('phpbbmodders.registrationapplications.manager');
$questions = $table_prefix . 'ra_questions';
$applications = $table_prefix . 'ra_applications';
$checks = 0;
if ($manager->questions(true))
{
	fwrite(STDERR, "Use a fresh disposable board with extension data purged and re-enabled before running these tests.\n");
	exit(2);
}

function ra_check($condition, $label)
{
	global $checks;
	if (!$condition)
	{
		throw new \RuntimeException('FAIL: ' . $label);
	}
	$checks++;
	echo "PASS: $label\n";
}

function ra_row($table, $id)
{
	global $db;
	$result = $db->sql_query('SELECT * FROM ' . $table . ' WHERE user_id = ' . (int) $id);
	$row = $db->sql_fetchrow($result);
	$db->sql_freeresult($result);
	return $row;
}

function ra_account($suffix, $type = USER_INACTIVE, $reason = INACTIVE_REGISTER)
{
	global $db, $manager, $question_id;
	$result = $db->sql_query("SELECT group_id FROM " . GROUPS_TABLE . " WHERE group_name = 'REGISTERED' AND group_type = " . GROUP_SPECIAL);
	$group_id = (int) $db->sql_fetchfield('group_id');
	$db->sql_freeresult($result);
	$name = 'ra_' . $suffix . '_' . bin2hex(random_bytes(4));
	$id = user_add(array(
		'username' => $name, 'user_email' => $name . '@example.invalid',
		'user_password' => 'unusable-test-password', 'group_id' => $group_id,
		'user_type' => $type, 'user_inactive_reason' => $reason,
		'user_inactive_time' => time(), 'user_actkey' => 'testkey',
	));
	$manager->submit($id, array($question_id => '&lt;script&gt;private &amp; answer&lt;/script&gt;'));
	return $id;
}

function ra_refused($id, $action, $mode, $error)
{
	global $manager;
	try
	{
		$manager->decide($id, $action, $mode);
	}
	catch (\RuntimeException $exception)
	{
		ra_check($exception->getMessage() === $error, $error . ' for ' . $action);
		return;
	}
	throw new \RuntimeException('Expected decision refusal');
}

class ra_limited_auth extends \phpbb\auth\auth
{
	public $allowed = array();
	public function acl_get($opt, $f = 0)
	{
		return in_array($opt, $this->allowed, true);
	}
}

$db->sql_query('INSERT INTO ' . $questions . ' ' . $db->sql_build_array('INSERT', array(
	'question_label' => 'Introduce yourself &amp; explain your interest',
	'question_required' => 1, 'min_chars' => 3, 'max_chars' => 6,
)));
$question_id = (int) $db->sql_nextid();
ra_check($manager->configured(), 'administrator activation prerequisites');
ra_check(count($manager->validate_answers(array())) === 1, 'required blank rejected');
ra_check(count($manager->validate_answers(array($question_id => 'ab'))) === 1, 'minimum enforced');
ra_check(!$manager->validate_answers(array($question_id => 'é&amp;字')), 'Unicode and decoded entity length');
ra_check(count($manager->validate_answers(array($question_id => '1234567'))) === 1, 'maximum enforced');
$db->sql_query('UPDATE ' . $questions . ' SET question_required = 0 WHERE question_id = ' . $question_id);
ra_check(!$manager->validate_answers(array()), 'optional blank allowed');
ra_check(count($manager->validate_answers(array($question_id => 'a'))) === 1, 'optional nonblank minimum enforced');
$db->sql_query('UPDATE ' . $questions . ' SET question_enabled = 0 WHERE question_id = ' . $question_id);
ra_check(!$manager->validate_answers(array($question_id => 'a')), 'disabled question ignored');
$db->sql_query('UPDATE ' . $questions . ' SET question_enabled = 1 WHERE question_id = ' . $question_id);

$approved = ra_account('approve');
ra_check((bool) $manager->pending_name($approved), 'eligible confirmation identifies applicant');
$snapshot = ra_row($applications, $approved)['answers'];
$db->sql_query('UPDATE ' . $questions . " SET question_label = 'Changed question' WHERE question_id = " . $question_id);
ra_check(ra_row($applications, $approved)['answers'] === $snapshot, 'question snapshot survives editing');
$manager->decide($approved, 'approve', 'retain');
ra_check(!$manager->pending_name($approved), 'reviewed applicant excluded from confirmations');
ra_check((int) ra_row(USERS_TABLE, $approved)['user_type'] === USER_NORMAL, 'approval activates through core');
ra_check(ra_row(USERS_TABLE, $approved)['user_actkey'] === '', 'approval clears activation key');
ra_check(ra_row($applications, $approved)['answers'] === '[]', 'approval erases private answers');
ra_refused($approved, 'deny', 'retain', 'RA_STALE');

$retained = ra_account('retain');
$config->set('ra_denial_mode', 'retain');
$manager->decide($retained, 'deny', 'retain');
$row = ra_row(USERS_TABLE, $retained);
ra_check((int) $row['user_type'] === USER_INACTIVE && (int) $row['user_inactive_reason'] === INACTIVE_MANUAL, 'retained rejection remains manually inactive');
ra_check($row['user_actkey'] === '' && ra_row($applications, $retained)['answers'] === '[]', 'retained rejection erases keys and answers');

$deleted = ra_account('delete');
$config->set('ra_denial_mode', 'delete');
ra_refused($deleted, 'deny', 'retain', 'RA_MODE_CHANGED');
ra_check((bool) ra_row(USERS_TABLE, $deleted), 'changed mode does not delete account');
$manager->decide($deleted, 'deny', 'delete');
ra_check(!ra_row(USERS_TABLE, $deleted), 'configured delete removes only applicant');
ra_check(!ra_row($applications, $deleted), 'core deletion listener removes private application');

$active = ra_account('active', USER_NORMAL, 0);
ra_refused($active, 'deny', 'delete', 'RA_STALE');
ra_check((int) ra_row(USERS_TABLE, $active)['user_type'] === USER_NORMAL, 'active account protected');
$founder = ra_account('founder', USER_FOUNDER, 0);
ra_refused($founder, 'deny', 'delete', 'RA_STALE');
$manual = ra_account('manual', USER_INACTIVE, INACTIVE_MANUAL);
ra_refused($manual, 'approve', 'delete', 'RA_STALE');

$pending = ra_account('permissions');
$property = new \ReflectionProperty($manager, 'auth');
$property->setAccessible(true);
$limited = new ra_limited_auth();
$property->setValue($manager, $limited);
ra_refused($pending, 'approve', 'delete', 'NOT_AUTHORISED');
$limited->allowed = array('m_ra_view');
ra_refused($pending, 'deny', 'delete', 'NOT_AUTHORISED');
$limited->allowed = array('m_ra_approve');
ra_refused($pending, 'approve', 'delete', 'NOT_AUTHORISED');
$limited->allowed = array('m_ra_view', 'm_ra_deny');
ra_refused($pending, 'approve', 'delete', 'NOT_AUTHORISED');
$property->setValue($manager, $auth);
ra_check(ra_row($applications, $pending)['status'] === 'pending', 'unauthorized attempts preserve pending state');

$external = ra_account('external');
user_active_flip('activate', $external);
ra_check(ra_row($applications, $external)['answers'] === '[]', 'external core activation erases answers');
$removed = ra_account('removed');
user_delete('retain', $removed);
ra_check(!ra_row($applications, $removed), 'external core deletion removes application');
for ($i = 0; $i < 22; $i++)
{
	ra_account('page' . $i);
}
ra_check(count($manager->pending()) === 20 && count($manager->pending(20)) >= 2, 'queue paginated at 20');
$config->set('ra_denial_mode', 'retain');
echo "Completed $checks checks on phpBB " . PHPBB_VERSION . '. PHP ' . PHP_VERSION . ".\n";
