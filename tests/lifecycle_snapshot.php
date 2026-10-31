<?php
/**
 * Cross-database lifecycle assertions for marked disposable phpBB boards.
 * Usage: php tests/lifecycle_snapshot.php BOARD_ROOT save|preserved|purged|clean
 * GPL-2.0-only.
 */
if (PHP_SAPI !== 'cli' || empty($argv[2]))
{
	echo "Usage: php tests/lifecycle_snapshot.php BOARD_ROOT save|preserved|purged|clean\n";
	exit(0);
}
$phpbb_root_path = realpath($argv[1]) . '/';
$operation = $argv[2];
if (!is_file($phpbb_root_path . '.ra-disposable-test') || !in_array($operation, array('save', 'preserved', 'purged', 'clean'), true))
{
	fwrite(STDERR, "Refusing an unmarked board or invalid operation.\n");
	exit(2);
}
define('IN_PHPBB', true);
$phpEx = 'php';
require $phpbb_root_path . 'common.php';
$snapshot_file = $phpbb_root_path . 'store/ra-lifecycle-snapshot.json';
$questions = $table_prefix . 'ra_questions';
$applications = $table_prefix . 'ra_applications';

function ra_lifecycle_rows($sql)
{
	global $db;
	$result = $db->sql_query($sql);
	$rows = $db->sql_fetchrowset($result);
	$db->sql_freeresult($result);
	return $rows;
}

function ra_lifecycle_check($condition, $message)
{
	if (!$condition)
	{
		throw new \RuntimeException($message);
	}
	echo 'PASS: ' . $message . "\n";
}

$accounts = ra_lifecycle_rows('SELECT user_id, user_type, user_inactive_reason FROM ' . USERS_TABLE . ' ORDER BY user_id');
if ($operation === 'save')
{
	file_put_contents($snapshot_file, json_encode(array(
		'accounts' => $accounts,
		'questions' => ra_lifecycle_rows('SELECT * FROM ' . $questions . ' ORDER BY question_id'),
		'applications' => ra_lifecycle_rows('SELECT * FROM ' . $applications . ' ORDER BY user_id'),
	)));
	echo "Saved lifecycle snapshot.\n";
	exit(0);
}
if ($operation === 'clean')
{
	ra_lifecycle_check(!ra_lifecycle_rows('SELECT user_id FROM ' . $applications), 'clean reinstall has no applications');
	ra_lifecycle_check(!ra_lifecycle_rows('SELECT question_id FROM ' . $questions), 'clean reinstall has no questions');
	ra_lifecycle_check($config['ra_denial_mode'] === 'retain', 'clean reinstall uses retain default');
	exit(0);
}
$saved = json_decode(file_get_contents($snapshot_file), true);
ra_lifecycle_check($accounts === $saved['accounts'], 'all account identities and activation states preserved');
if ($operation === 'preserved')
{
	ra_lifecycle_check(ra_lifecycle_rows('SELECT * FROM ' . $questions . ' ORDER BY question_id') === $saved['questions'], 'questions preserved');
	ra_lifecycle_check(ra_lifecycle_rows('SELECT * FROM ' . $applications . ' ORDER BY user_id') === $saved['applications'], 'applications preserved');
}
else
{
	$tools = $phpbb_container->get('dbal.tools');
	ra_lifecycle_check(!$tools->sql_table_exists($questions) && !$tools->sql_table_exists($applications), 'extension tables removed');
	ra_lifecycle_check(!isset($config['ra_denial_mode']), 'extension setting removed');
	ra_lifecycle_check(!ra_lifecycle_rows('SELECT auth_option FROM ' . ACL_OPTIONS_TABLE . " WHERE auth_option IN ('m_ra_view', 'm_ra_approve', 'm_ra_deny')"), 'extension permissions removed');
}
