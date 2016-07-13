<?php
require_once( $IP . "/includes/specials/SpecialRecentchanges.php" );
require_once( $IP . "/includes/specials/SpecialWatchlist.php");
require_once( $IP . "/includes/WatchedItem.php" );

# modifications by MHart - this page will email a watchlist digest using sendmail, once per day
# update to be a plugin not a specialpage by MarcoWobben

function mailRecentChangesDigest()
{
	global $wgUser, $wgOut, $wgLang, $wgTitle, $wgMemc, $wgRequest, $wgLoadBalancer;
	global $wgUseWatchlistCache, $wgWLCacheTimeout, $wgDBname, $wgIsMySQL;
	$fname = "mailRecentChangesDigest";

	$wgOut->setPagetitle( wfMsg( "maildigest" ) );
	$sub = wfMsg( "maildigest", $wgUser->getName() );
	$wgOut->setSubtitle( $sub );
	$wgOut->setRobotpolicy( "noindex,nofollow" );

	$specialTitle = Title::makeTitle( NS_SPECIAL, "MailDigest" );
	
	$uid = $wgUser->getID();
	
	if ($wgUser->getName() != '127.0.0.1') { // adding support for user groups makes sence
		$wgOut->addHTML($wgUser->getName() . ' is not authorized to send emails');
		return;
	}
	
#	if( $uid == 0 ) {
#		$wgOut->addHTML( wfMsg( "nowatchlist" ) );
#		return;
#	}

	# Get query variables
	$days = $wgRequest->getVal( 'days' );
	$action = $wgRequest->getVal( 'action' );
	$remove = $wgRequest->getVal( 'remove' );
	$id = $wgRequest->getVal( 'id' );
	
	$days = 1;

	if(($action == "submit") && isset($remove) && is_array($id)) {
		$wgOut->addHTML( wfMsg( "removingchecked" ) );
		foreach($id as $one) {
			$t = Title::newFromURL( $one );
			if($t->getDBkey() != "") {
				$wl = WatchedItem::fromUserTitle( $wgUser, $t );
				if( $wl->removeWatch() === false ) {
					$wgOut->addHTML( "<br />\n" . wfMsg( "couldntremove", htmlspecialchars($one) ) );
				} else {
					$wgOut->addHTML( " (" . htmlspecialchars($one) . ")" );
				}
			} else {
				$wgOut->addHTML( "<br />\n" . wfMsg( "iteminvalidname", htmlspecialchars($one) ) );
			}
		}
		$wgOut->addHTML( "done.\n<p>" );
	}

	if ( $wgUseWatchlistCache ) {
		$memckey = "$wgDBname:watchlist:id:" . $wgUser->getId();
		$cache_s = @$wgMemc->get( $memckey );
		if( $cache_s ){
			$wgOut->addHTML( wfMsg("wlsaved") );
			$wgOut->addHTML( $cache_s );
			return;
		}
	}

$userSQL = "SELECT DISTINCT wl_user FROM watchlist";
$db = wfGetDB(DB_SLAVE);
$userRES = $db->query($userSQL);
while ($userS = $userRES->fetchObject())
{
	$nUSER = $userS->wl_user;
	$htmlmessage = '';

	$sqlGetUser = "SELECT user_name, user_email,user_options FROM user WHERE user_id=$nUSER";
	$resGetUser = $db->query( $sqlGetUser );
	$sGetUser = $resGetUser->fetchObject();
	$mailUser =User::newFromName($sGetUser->user_name);
	$mailUser->decodeOptions($sGetUser->user_options);

	// $wgOut->addHTML( print_r($mailUser, true) . "<hr/>" );

	if ( 1 == $mailUser->getOption( 'emailwatch' ) )
	{
		// $wgLoadBalancer->force(-1);
		# $sql = "SELECT COUNT(*) AS n FROM watchlist WHERE wl_user=$uid";
		$sql = "SELECT COUNT(*) AS n FROM watchlist WHERE wl_user=$nUSER";
		$res = $db->query( $sql );
		$s = $res->fetchObject();
		$nitems = $s->n;
		// $wgLoadBalancer->force(0);
		if($nitems == 0) {
	//        $wgOut->addHTML( wfMsg( "nowatchlist" ) );
			return;
	} 
	
	// $wgLoadBalancer->force(-1);
	if ( is_null( $days ) ) {
		$big = 1000;
		if($nitems > $big) {
			# Set default cutoff shorter
			$days = (12.0 / 24.0); # 12 hours...
		} else {
			$days = 3; # longer cutoff for shortlisters
		}
	} else {
		$days = floatval($days);
	}
	
	if ( $days <= 0 ) {
		$docutoff = '';
		$cutoff = false;
		$npages = wfMsg( "all" );
	} else {
	        $docutoff = "AND page_touched > FROM_UNIXTIME(" .
		  ( $cutoff =  time() - intval( $days * 86400 ) )
		  . ")";
	        $sql = "SELECT COUNT(*) AS n FROM page WHERE page_touched > '$cutoff'";

		$res = $db->query( $sql );
		$s = $res->fetchObject();
		$npages = $s->n;
		
	}
	
	if(isset($_REQUEST['magic'])) {
		$toadd = wfMsg( "watchlistcontains", $wgLang->formatNum( $nitems ) ) .
			"<p>" . wfMsg( "watcheditlist" ) . "</p>\n" ;
#		$wgOut->addHTML( $toadd);
		$htmlmessage .= $toadd;
		
		$toadd = "<form action='" .
			$specialTitle->escapeLocalUrl( "action=submit" ) .
			"' method='post'>\n" .
			"<ul>\n" ;
#		$wgOut->addHTML( $toadd);
		$htmlmessage .= $toadd;
		# $sql = "SELECT wl_namespace,wl_title FROM watchlist WHERE wl_user=$uid";
		$sql = "SELECT wl_namespace,wl_title FROM watchlist WHERE wl_user=$nUSER";
		$res = wfQuery( $sql, DB_READ );

		$sk = $wgUser->getSkin();
		while( $s = wfFetchObject( $res ) ) {
			$t = Title::makeTitle( $s->wl_namespace, $s->wl_title );
			if( is_null( $t ) ) {
#				$wgOut->addHTML( '<!-- bad title "' . htmlspecialchars( $s->wl_title ) . '" in namespace ' . IntVal( $s->wl_namespace ) . " -->\n" );
			} else {
				$t = $t->getPrefixedText();
				$toadd = "<li><input type='checkbox' name='id[]' value=\"" . htmlspecialchars($t) . "\" />" .
					$sk->makeLink( $t, $t ) .
					"</li>\n" ;
#				$wgOut->addHTML( $toadd);
				$htmlmessage  .= $toadd;
			}
		}
		$toadd =  "</ul>\n" .
			"<input type='submit' name='remove' value=\"" .
			htmlspecialchars( wfMsg( "removechecked" ) ) . "\" />\n" .
			"</form>\n" ;
#		$wgOut->addHTML($toadd);
		$htmlmessage .= $toadd;
		
		$wgLoadBalancer->force(0);
		return;
	}
	
	# If the watchlist is relatively short, it's simplest to zip
	# down its entirety and then sort the results.
	
	# If it's relatively long, it may be worth our while to zip
	# through the time-sorted page list checking for watched items.
	
	# Up estimate of watched items by 15% to compensate for talk pages...
	if( $cutoff && ( $nitems*1.15 > $npages ) ) {
		$x = "page_touched";
		$y = wfMsg( "watchmethod-recent" );
		$z = "wl_namespace=page_namespace&65534";
	} else {
		$x = "name_title_timestamp";
		$y = wfMsg( "watchmethod-list" );
		$z = "(wl_namespace=page_namespace OR wl_namespace+1=page_namespace)";
	}

	
	$toadd = "<i>" . wfMsg( "watchdetails",
		$wgLang->formatNum( $nitems ), $wgLang->formatNum( $npages ), $y,
		$specialTitle->escapeLocalUrl( "magic=yes" ) ) . "</i><br />\n" ;
		
#	$wgOut->addHTML( $toadd);
	$htmlmessage .= $toadd;
	 
	$use_index=$wgIsMySQL?"USE INDEX ($x)":"";
	$sql = "SELECT 
		page_namespace, page_title, rev_comment, page_id, rev_user, rev_user_text, page_touched, page_is_new
		FROM watchlist
		JOIN page ON page.page_namespace = watchlist.wl_namespace
		AND page.page_title = wl_title
		JOIN revision ON revision.rev_id = page.page_latest
		AND revision.rev_page = page.page_id
		WHERE wl_user =$nUSER
  AND $z
  AND wl_title = page.page_title
  $docutoff
  ORDER BY page.page_touched DESC";
	$res = $db->query( $sql );
	$numRows = $res->numRows( $res );
	if($days >= 1)
		$note = wfMsg( "rcnote", $wgLang->formatNum( $numRows ), $wgLang->formatNum( $days ) );
	elseif($days > 0)
		$note = wfMsg( "wlnote", $wgLang->formatNum( $numRows ), $wgLang->formatNum( round($days*24) ) );
	else
		$note = "";
	$toadd =  "\n<hr />\n{$note}\n<br />" ;
#	$wgOut->addHTML($toadd);
	$htmlmessage .= $toadd;
	
	$note = wlCutoffLinks( $days );
	$toadd = "{$note}\n" ;
#	$wgOut->addHTML( $toadd);
	$htmlmessage .= $toadd;

	if ( $numRows == 0 ) {
		$toadd = "<p><i>" . wfMsg( "watchnochange" ) . "</i></p>" ;
#		$wgOut->addHTML($toadd);
		$htmlmessage .= $toadd;
		$wgLoadBalancer->force(0);
		return;
	}

	$sk = $wgUser->getSkin();
	$s = "";
	$rc = new EnhancedChangesList($sk);
	$s = $rc->beginRecentChangesList();
	$counter = 1;
	while ( $obj = $res->fetchObject( ) ) {
		# Make fake RC entry
		$rcr = RecentChange::newFromCurRow( $obj );
		$rcr->counter = $counter++;
		$s .= $rc->recentChangesLine( $rcr, true );
	}
	$s .= $rc->endRecentChangesList();

	$res = null;//->freeResult( );
	$wgOut->addHTML( $s );
	$htmlmessage .= $s;

	if ( $wgUseWatchlistCache ) {
		$wgMemc->set( $memckey, $s, $wgWLCacheTimeout);
	}
	
	// $wgLoadBalancer->force(0);

	global $wgSitename, $wgServer, $wgScriptPath;
	
	$mailedURL = $wgServer . $wgScriptPath;
	$fixedhtmlmessage = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2//EN">';	
	$fixedhtmlmessage .= '<html><body>' .
		str_replace('/index',$mailedURL . '/index', $htmlmessage) .
		'</body></html>';

	$to	 = $sGetUser->user_name . '<' . $sGetUser->user_email . '>';
	$subject = $wgSitename . ' Watchlist Digest';
	if ( 1 == $mailUser->getOption( 'emailplainformat' ) ) {
		$headers .= 'From: ' . $wgSitename . "<noreply@corduroy.nl>\r\n";
		$fixedhtmlmessage .= "\r\n" . $mailedURL . '/index.php/Special:Watchlist' . "\r\n";
		mail($to, $subject, strip_tags($fixedhtmlmessage), $headers);
		$wgOut->addHTML('plain email sent to ' . $sGetUser->user_name . ' (' . $sGetUser->user_email . ')' . '<br>');
	}
	else {
		$headers = 'MIME-Version: 1.0' . "\r\n";
		$headers .= 'X-Mailer: Html Mime Class' . "\r\n";
		$headers = 'Content-Type: text/html; charset="utf-8"'  . "\r\n"; // iso-8859-1
		$headers .= 'Content-Transfer-Encoding: 7bit'  . "\r\n";
		$headers .= 'From: ' . $wgSitename . " <noreply@corduroy.nl>\r\n";
		mail($to, $subject, $fixedhtmlmessage, $headers);
		$wgOut->addHTML('html email sent to ' . $sGetUser->user_name . ' (' . $sGetUser->user_email . ')' . '<br>');
	}
}
}	
}
