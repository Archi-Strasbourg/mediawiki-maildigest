<?php
/*

Inspired by: http://www.mediawiki.org/wiki/Extension:Email_Digest
Created by: Marco Wobben.
Created on: 2009-06-30

Docs:
	http://www.mediawiki.org/wiki/Manual:Extensions#Writing_Extensions

*/

$wgExtensionCredits['validextensionclass'][] = array(
       'name' => 'MailDigest',
       'author' =>'Marco Wobben', 
       'url' => 'http://www.mediawiki.org/wiki/User:marco.wobben', 
       'description' => 'This extension allows cron to mail a daily digest of watched pages.'
       );

$dir = dirname(__FILE__) . '/';
	
$wgExtensionMessagesFiles['MailDigest'] = $dir . 'MailDigest.i18n.php';
require_once( $dir . "MailDigest.body.php" );
		
function add_user_toggles(&$extraToggles) 
{	
	$extraToggles[] = 'emailwatch'; // ] = 'Email daily Watchlist changes';
 	$extraToggles[] = 'emailplainformat';// ] = 'Email using plain text (HTML is default)';
 
 /*
 * Changes in this file will be lost during software upgrades.
 * You can make your customizations on the wiki.
 * While logged in as administrator, go to [[Special:Allmessages]]
 * and edit the MediaWiki:* pages listed there.
 */

 	return true;
}

function maildigest($action, $article)
{
	if ($action == "maildigest") {
		mailRecentChangesDigest();
		return false;
	}
	return true;
}

$wgHooks['UserToggles'][] = array('add_user_toggles');
$wgHooks['UnknownAction'][] = 'maildigest';
