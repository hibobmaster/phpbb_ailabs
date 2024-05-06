<?php

/**
 *
 * AI Labs extension
 *
 * @copyright (c) 2023, privet.fun, https://privet.fun
 * @license GNU General Public License, version 2 (GPL-2.0)
 * 
 * Italian translation by Lord Phobos https://www.phpbb.com/community/memberlist.php?mode=viewprofile&u=121301
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
	'ACP_AILABS_TITLE_VIEW' 	=> 'AI Labs Vedi Configurazione',
	'ACP_AILABS_TITLE_ADD' 		=> 'AI Labs Aggiungi Configurazione',
	'ACP_AILABS_TITLE_EDIT'		=> 'AI Labs Modifica Configurazione',
	'ACP_AILABS_SETTINGS' 		=> 'Impostazioni',

	'ACP_AILABS_ADD' 			=> 'Aggiungi Configurazione',

	'AILABS_USER_EMPTY' 				=> 'Si prega di selezionare un utente',
	'AILABS_USER_NOT_FOUND'				=> 'Impossibile localizzare l\'utente %1$s',
	'AILABS_USER_ALREADY_CONFIGURED'	=> 'L\'utente %1$s � gi� configurato, � supportata una sola configurazione per utente',
	'AILABS_SPECIFY_FORUM'				=> 'Si prega di selezionare almeno un forum',

	'LOG_ACP_AILABS_ADDED' 				=> 'Configurazione AI Labs aggiunta',
	'LOG_ACP_AILABS_EDITED' 			=> 'Configurazione AI Labs aggiornata',
	'LOG_ACP_AILABS_DELETED' 			=> 'Configurazione AI Labs cancellata',

	'ACP_AILABS_ADDED' 				=> 'Configurazione creata con successo',
	'ACP_AILABS_UPDATED' 			=> 'Configurazione aggiornata con successo',
	'ACP_AILABS_DELETED_CONFIRM'	=> 'Sei sicuto di voler cancellare la configurazione associata con l\'utente %1$s?',

	'LBL_AILABS_SETTINGS_DESC'		=> 'Si prega di visitare 👉 <a href="https://github.com/privet-fun/phpbb_ailabs" target="_blank" rel="nofollow">https://github.com/privet-fun/phpbb_ailabs</a> per istruzioni e configurazioni, troubleshooting con esempi.',
	'LBL_AILABS_USERNAME'			=> 'BOt IA',
	'LBL_AILABS_CONTROLLER'			=> 'IA',
	'LBL_AILABS_CONFIG'             => 'Configurazione JSON',
	'LBL_AILABS_TEMPLATE'           => 'Modello',

	'LBL_AILABS_REPLY_TO'			=> 'I forum in cui il bot IA risponde',
	'LBL_AILABS_POST_FORUMS'		=> 'Nuovo topic',
	'LBL_AILABS_REPLY_FORUMS'		=> 'Risposta in un topic',
	'LBL_AILABS_QUOTE_FORUMS'		=> 'Cita o <a href="https://www.phpbb.com/customise/db/extension/simple_mentions/" target="_blank" rel="nofollow">menziona</a>',
	'LBL_AILABS_ENABLED'			=> 'Abilitato',
	'LBL_AILABS_SELECT_FORUMS'		=> 'Selezione i forum...',

	'LBL_AILABS_BOT_URL'			=> 'URL del BOT (prova)',
	'LBL_AILABS_BOT_URL_EXPLAIN'	=> 'Clicca sull\'URL fornito, e si dovrebbe aprire un nuovo tab con la risposta "Processing job 0". <a href="https://github.com/privet-fun/phpbb_ailabs?tab=readme-ov-file#troubleshooting" target="_blank" rel="nofollow">Troubleshooting</a>',

	'LBL_AILABS_CONFIG_EXPLAIN'				=> 'Deve essere un JSON valido, si prega di fare riferimento alla documentazione per i dettagli',
	'LBL_AILABS_TEMPLATE_EXPLAIN'			=> 'Variabili valide: {post_id}, {request}, {info}, {response}, {images}, {mp4}, {attachments}, {poster_id}, {poster_name}, {ailabs_username}, {settings}',
	'LBL_AILABS_POST_FORUMS_EXPLAIN'		=> 'Specifica i forum in cui l\'IA risponder� a nuovi topic',
	'LBL_AILABS_REPLY_FORUMS_EXPLAIN'		=> 'Specifica i forum in cui l\'IA risponder� alle risposte nel topic',
	'LBL_AILABS_QUOTE_FORUMS_EXPLAIN'		=> 'Specifica i forum in cui l\'IA risponder� quando citata o <a href="https://www.phpbb.com/customise/db/extension/simple_mentions/" target="_blank" rel="nofollow">menzionata</a>',
	'LBL_AILABS_IP_VALIDATION'				=> '⚠️ Attenzione: Nel tuo PCA > Generale > Configurazione Server > Sicurezza > ' .
		'<a href="%1$s">l\'impostazione di Convalida sessione IP NON sia impostata su Nessuno</a>, ' .
		'questo potrebbe impedire ad AI Labs di rispondere se stai usando estensioni phpBB che forzino l\'utente ad essere collegato ' .
		'(per esempio <a href="https://www.phpbb.com/customise/db/extension/login_required" target="_blank" rel="nofollow">Richiesto Login</a>). ' .
		'Imposta la validazione Convalida sessione IP a Nessuno o aggiunti "/ailabs/*" alla whiteslist delle estensioni. ' .
		'Fai riferimento alla <a href="https://github.com/privet-fun/phpbb_ailabs#troubleshooting" target="_blank" rel="nofollow">sezione troubleshooting</a> per ulteriori dettagli.',

	'LBL_AILABS_CONFIG_DEFAULT'				=> 'Carica configurazione predefinita',
	'LBL_AILABS_TEMPLATE_DEFAULT'			=> 'Carica modello predefinito',
	
	'LBL_AILABS_API_DOCS'			=> 'Documentazione API',
]);
