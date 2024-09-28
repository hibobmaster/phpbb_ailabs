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
	$lang = array();
}

$lang = array_merge($lang, [
	'AILABS_MJ_BUTTONS'					=> 'Rispondi citando un delle azioni supportate [size=60][url=https://docs.midjourney.com/docs/quick-start#8-upscale-or-create-variations]1[/url] [url=https://docs.midjourney.com/docs/quick-start#9-enhance-or-modify-your-image]2[/url] [url=https://docs.midjourney.com/docs/zoom-out#custom-zoom]3[/url] [url=https://docs.midjourney.com/docs/seeds]4[/url][/size]: ',
	'AILABS_QUOTE_BUTTONS'				=> 'Rispondi citando una delle azioni supportate: ',
	'AILABS_MJ_BUTTON_ALREADY_USED'		=> 'L\'azione %1s è stata già [url=%2$s?p=%3$d#p%3$d]eseguita[/url]',
	'AILABS_ERROR_CHECK_LOGS'			=> '[color=#FF0000]Errore. Si prega di controllare i log.[/color]',
	'AILABS_ERROR_UNABLE_DOWNLOAD_URL'	=> 'Impossibile scaricare ',
	'AILABS_NO_PROMPT'					=> 'Manca il prompt.',
	'AILABS_ERROR_PROVIDE_URL' 			=> 'Si prega di allegare un\'immagine o di fornire l\'URL di un\'immagine per l\'analisi.',
	'AILABS_ERROR_PROVIDE_URL_2x'		=> 'Si prega di allegare le immagini o fornire gli URL delle immagini sia per le immagini di origine che per quelle di destinazione per il face swap.',
	'AILABS_ERROR'						=> '[color=#FF0000]%1s[/color]',
	'AILABS_POSTS_DISCARDED'  			=> ', i post a cominciare da [url=%1$s?p=%2$d#p%2$d]questo post[/url] sono stati scartati',
	'AILABS_DISCARDED_INFO' 			=> '[size=75][url=%1$s?p=%2$d#p%2$d]Inizio[/url] di una conversazione contentente %3$d post%4$s (sono stati usati %5$d token su %6$d)[/size]',
	'AILABS_THINKING' 					=> 'sto pensando',
	'AILABS_REPLYING' 					=> 'sto rispondendo…',
	'AILABS_REPLIED' 					=> 'risposto ↓',
	'AILABS_UNABLE_TO_REPLY' 			=> 'in capace di rispondere',
	'AILABS_QUERY' 						=> 'interrogando',
	'L_AILABS_AI'						=> 'IA',
	'AILABS_SETTINGS_OVERRIDE'			=> '[size=75]%1$s[/size]'
]);
