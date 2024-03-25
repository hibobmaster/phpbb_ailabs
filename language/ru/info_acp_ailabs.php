<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2023, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB')) {
	exit;
}

if (empty($lang) || !is_array($lang)) {
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_AILABS_TITLE' 			=> 'AI Labs',
	'ACP_AILABS_TITLE_VIEW' 	=> 'Просмотр конфигурации AI Labs',
	'ACP_AILABS_TITLE_ADD' 		=> 'Добавить конфигурацию AI Labs',
	'ACP_AILABS_TITLE_EDIT'		=> 'Изменить конфигурацию AI Labs',
	'ACP_AILABS_SETTINGS' 		=> 'Настройки',

	'ACP_AILABS_ADD' 			=> 'Добавить конфигурацию',

	'AILABS_USER_EMPTY' 				=> 'Пожалуйста, выберите пользователя',
	'AILABS_USER_NOT_FOUND'				=> 'Не удалось найти пользователя %1$s',
	'AILABS_USER_ALREADY_CONFIGURED'	=> 'Пользователь %1$s уже настроен, поддерживается только одна конфигурация на пользователя',
	'AILABS_SPECIFY_FORUM'	=> 'Укажите хотя бы один форум',

	'LOG_ACP_AILABS_ADDED' 				=> 'Конфигурация AI Labs добавлена',
	'LOG_ACP_AILABS_EDITED' 			=> 'Конфигурация AI Labs изменена',
	'LOG_ACP_AILABS_DELETED' 			=> 'Конфигурация AI Labs удалена',

	'ACP_AILABS_ADDED' 				=> 'Конфигурация успешно создана',
	'ACP_AILABS_UPDATED' 			=> 'Конфигурация успешно обновлена',
	'ACP_AILABS_DELETED_CONFIRM'	=> 'Вы уверены, что хотите удалить конфигурацию, связанную с пользователем %1$s?',

	'LBL_AILABS_SETTINGS_DESC'		=> 'Пожалуйста, посетите 👉 <a href="https://github.com/privet-fun/phpbb_ailabs">https://github.com/privet-fun/phpbb_ailabs</a> для получения подробных инструкций по настройке и примеров.',
	'LBL_AILABS_USERNAME'			=> 'AI бот',
	'LBL_AILABS_CONTROLLER'			=> 'AI',
	'LBL_AILABS_CONFIG'             => 'Конфигурация в формате JSON',
	'LBL_AILABS_TEMPLATE'           => 'Шаблон',

	'LBL_AILABS_REPLY_TO'			=> 'Форумы где AI бот отвечает на',
	'LBL_AILABS_POST_FORUMS'		=> 'Новую тему',
	'LBL_AILABS_REPLY_FORUMS'		=> 'Ответ в теме',
	'LBL_AILABS_QUOTE_FORUMS'		=> 'Цитирование или <a href="https://www.phpbb.com/customise/db/extension/simple_mentions/" rel="nofollow">упоминание</a>',
	'LBL_AILABS_ENABLED'			=> 'Включено',
	'LBL_AILABS_SELECT_FORUMS'		=> 'Выберите форумы...',

	'LBL_AILABS_CONFIG_EXPLAIN'				=> 'Пожалуйста, обратитесь к документации для получения подробных инструкций по настройке и примеров',
	'LBL_AILABS_TEMPLATE_EXPLAIN'			=> 'Допустимые переменные: {post_id}, {request}, {info}, {response}, {images}, {attachments}, {poster_id}, {poster_name}, {ailabs_username}',
	'LBL_AILABS_POST_FORUMS_EXPLAIN'		=> 'Укажите форумы, на которых AI будет отвечать на новые темы',
	'LBL_AILABS_REPLY_FORUMS_EXPLAIN'		=> 'Укажите форумы, на которых AI будет отвечать на ответы',
	'LBL_AILABS_QUOTE_FORUMS_EXPLAIN'		=> 'Укажите форумы, на которых AI будет отвечать на цитируемые сообщения или <a href="https://www.phpbb.com/customise/db/extension/simple_mentions/" rel="nofollow">упоминания</a>',
	'LBL_AILABS_IP_VALIDATION'				=> '⚠️ Предупреждение: Администрировать > Общие > Конфигурация сервера > Безопасность > ' .
		'<a href="%1$s">Проверка IP-адреса сессии НЕ установлена в значение «Нет»</a>. ' .
		'Это может препятствовать работе AI Labs в случае если вы используете расширения phpBB, ' .
		'требующие авторизацию пользователя ' .
		'(например <a href="https://www.phpbb.com/customise/db/extension/login_required">Login Required</a>). ' .
		'Установите проверку IP-адреса сессии в значение "Нет" или добавьте "/ailabs/*" в белый список расширений. ' .
		'Пожалуйста, обратитесь к <a href="https://github.com/privet-fun/phpbb_ailabs#troubleshooting">разделу по ' .
		'устранению неполадок</a> для получения дополнительной информации.',

	'LBL_AILABS_CONFIG_DEFAULT'				=> 'Загрузить конфигурацию по умолчанию',
	'LBL_AILABS_TEMPLATE_DEFAULT'			=> 'Загрузить шаблон по умолчанию',
]);
