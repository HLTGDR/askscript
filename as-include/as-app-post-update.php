<?php
	
/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-app-post-update.php
	Version: See define()s at top of as-include/as-base.php
	Description: Changing questions, answer and comments (application level)


	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	More about this license: http://www.question2answer.org/license.php
*/

	if (!defined('AS_VERSION')) { // don't allow this page to be requested directly from browser
		header('Location: ../');
		exit;
	}

	require_once AS_INCLUDE_DIR.'as-app-post-create.php';
	require_once AS_INCLUDE_DIR.'as-app-updates.php';
	require_once AS_INCLUDE_DIR.'as-db-post-create.php';
	require_once AS_INCLUDE_DIR.'as-db-post-update.php';
	require_once AS_INCLUDE_DIR.'as-db-points.php';
	require_once AS_INCLUDE_DIR.'as-db-hotness.php';
	
	
	define('AS_POST_STATUS_NORMAL', 0);
	define('AS_POST_STATUS_HIDDEN', 1);
	define('AS_POST_STATUS_QUEUED', 2);

	
	function as_question_set_content($oldquestion, $title, $content, $format, $text, $tagstring, $notify, $userid, $handle, $cookieid, $extravalue=null, $name=null, $remoderate=false, $silent=false)
/*
	Change the fields of a question (application level) to $title, $content, $format, $tagstring, $notify, $extravalue
	and $name, then reindex based on $text. For backwards compatibility if $name is null then the name will not be
	changed. Pass the question's database record before changes in $oldquestion and details of the user doing this in
	$userid, $handle and $cookieid. Set $remoderate to true if the question should be requeued for moderation if
	modified. Set $silent to true to not mark the question as edited. Reports event as appropriate. See as-app-posts.php
	for a higher-level function which is easier to use.
*/
	{
		as_post_unindex($oldquestion['postid']);
		
		$wasqueued=($oldquestion['type']=='Q_QUEUED');
		$titlechanged=strcmp($oldquestion['title'], $title) ? true : false;
		$contentchanged=strcmp($oldquestion['content'], $content) || strcmp($oldquestion['format'], $format);
		$tagschanged=strcmp($oldquestion['tags'], $tagstring) ? true : false;
		$setupdated=($titlechanged || $contentchanged || $tagschanged) && (!$wasqueued) && !$silent;
		
		as_db_post_set_content($oldquestion['postid'], $title, $content, $format, $tagstring, $notify,
			$setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null,
			($titlechanged || $contentchanged) ? AS_UPDATE_CONTENT : AS_UPDATE_TAGS, $name);
			
		if (isset($extravalue)) {
			require_once AS_INCLUDE_DIR.'as-db-metas.php';
			as_db_postmeta_set($oldquestion['postid'], 'as_q_extra', $extravalue);
		}
		
		if ($setupdated && $remoderate) {
			require_once AS_INCLUDE_DIR.'as-app-posts.php';
			
			$answers=as_post_get_question_answers($oldquestion['postid']);
			$commentsfollows=as_post_get_question_commentsfollows($oldquestion['postid']);
			$closepost=as_post_get_question_closepost($oldquestion['postid']);
			
			foreach ($answers as $answer)
				as_post_unindex($answer['postid']);
				
			foreach ($commentsfollows as $comment)
				if ($comment['basetype']=='C')
					as_post_unindex($comment['postid']);
					
			if (@$closepost['parentid']==$oldquestion['postid'])
				as_post_unindex($closepost['postid']);
			
			as_db_post_set_type($oldquestion['postid'], 'Q_QUEUED');
			as_update_counts_for_q($oldquestion['postid']);
			as_db_queuedcount_update();
			as_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects'));
			
			if ($oldquestion['flagcount'])
				as_db_flaggedcount_update();
			
		} elseif ($oldquestion['type']=='Q') { // not hidden or queued
			as_post_index($oldquestion['postid'], 'Q', $oldquestion['postid'], $oldquestion['parentid'], $title, $content, $format, $text, $tagstring, $oldquestion['categoryid']);
		}
		
		$eventparams=array(
			'postid' => $oldquestion['postid'],
			'title' => $title,
			'content' => $content,
			'format' => $format,
			'text' => $text,
			'tags' => $tagstring,
			'extra' => $extravalue,
			'name' => $name,
			'oldquestion' => $oldquestion,
		);

		as_report_event('q_edit', $userid, $handle, $cookieid, $eventparams + array(
			'silent' => $silent,
			'oldtitle' => $oldquestion['title'],
			'oldcontent' => $oldquestion['content'],
			'oldformat' => $oldquestion['format'],
			'oldtags' => $oldquestion['tags'],
			'titlechanged' => $titlechanged,
			'contentchanged' => $contentchanged,
			'tagschanged' => $tagschanged,
		));
		
		if ($setupdated && $remoderate)
			as_report_event('q_requeue', $userid, $handle, $cookieid, $eventparams);
	}
	
	
	function as_question_set_selchildid($userid, $handle, $cookieid, $oldquestion, $selchildid, $answers)
/*
	Set the selected answer (application level) of $oldquestion to $selchildid. Pass details of the user doing this
	in $userid, $handle and $cookieid, and the database records for all answers to the question in $answers.
	Handles user points values and notifications.
	See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		$oldselchildid=$oldquestion['selchildid'];
		
		as_db_post_set_selchildid($oldquestion['postid'], isset($selchildid) ? $selchildid : null, $userid, as_remote_ip_address());
		as_db_points_update_ifuser($oldquestion['userid'], 'aselects');
		as_db_unselqcount_update();
		
		if (isset($oldselchildid) && isset($answers[$oldselchildid])) {
			as_db_points_update_ifuser($answers[$oldselchildid]['userid'], 'aselecteds');
		
			as_report_event('a_unselect', $userid, $handle, $cookieid, array(
				'parentid' => $oldquestion['postid'],
				'parent' => $oldquestion,
				'postid' => $oldselchildid,
				'answer' => $answers[$oldselchildid],
			));
		}

		if (isset($selchildid)) {
			as_db_points_update_ifuser($answers[$selchildid]['userid'], 'aselecteds');

			as_report_event('a_select', $userid, $handle, $cookieid, array(
				'parentid' => $oldquestion['postid'],
				'parent' => $oldquestion,
				'postid' => $selchildid,
				'answer' => $answers[$selchildid],
			));
		}
	}

	
	function as_question_close_clear($oldquestion, $oldclosepost, $userid, $handle, $cookieid)
/*
	Reopen $oldquestion if it was closed. Pass details of the user doing this in $userid, $handle and $cookieid, and the
	$oldclosepost (to match $oldquestion['closedbyid']) if any.
	See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		if (isset($oldquestion['closedbyid'])) {
			as_db_post_set_closed($oldquestion['postid'], null, $userid, as_remote_ip_address());

			if (isset($oldclosepost) && ($oldclosepost['parentid']==$oldquestion['postid'])) {
				as_post_unindex($oldclosepost['postid']);
				as_db_post_delete($oldclosepost['postid']);
			}

			as_report_event('q_reopen', $userid, $handle, $cookieid, array(
				'postid' => $oldquestion['postid'],
				'oldquestion' => $oldquestion,
			));
		}
	}
	
	
	function as_question_close_duplicate($oldquestion, $oldclosepost, $originalpostid, $userid, $handle, $cookieid)
/*
	Close $oldquestion as a duplicate of the question with id $originalpostid. Pass details of the user doing this in
	$userid, $handle and $cookieid, and the $oldclosepost (to match $oldquestion['closedbyid']) if any. See
	as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		as_question_close_clear($oldquestion, $oldclosepost, $userid, $handle, $cookieid);

		as_db_post_set_closed($oldquestion['postid'], $originalpostid, $userid, as_remote_ip_address());
		
		as_report_event('q_close', $userid, $handle, $cookieid, array(
			'postid' => $oldquestion['postid'],
			'oldquestion' => $oldquestion,
			'reason' => 'duplicate',
			'originalid' => $originalpostid,
		));
	}
	

	function as_question_close_other($oldquestion, $oldclosepost, $note, $userid, $handle, $cookieid)
/*
	Close $oldquestion with the reason given in $note. Pass details of the user doing this in $userid, $handle and
	$cookieid, and the $oldclosepost (to match $oldquestion['closedbyid']) if any.
	See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		as_question_close_clear($oldquestion, $oldclosepost, $userid, $handle, $cookieid);
		
		$postid=as_db_post_create('NOTE', $oldquestion['postid'], $userid, isset($userid) ? null : $cookieid,
			as_remote_ip_address(), null, $note, '', null, null, $oldquestion['categoryid']);
		
		as_db_posts_calc_category_path($postid);
		
		if ($oldquestion['type']=='Q')
			as_post_index($postid, 'NOTE', $oldquestion['postid'], $oldquestion['postid'], null, $note, '', $note, null, $oldquestion['categoryid']);
		
		as_db_post_set_closed($oldquestion['postid'], $postid, $userid, as_remote_ip_address());
		
		as_report_event('q_close', $userid, $handle, $cookieid, array(
			'postid' => $oldquestion['postid'],
			'oldquestion' => $oldquestion,
			'reason' => 'other',
			'note' => $note,
		));
	}

	
	function as_question_set_hidden($oldquestion, $hidden, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost=null)
/*
	Set $oldquestion to hidden if $hidden is true, visible/normal if otherwise. All other parameters are as for as_question_set_status(...)
	This function is included mainly for backwards compatibility.
*/
	{
		as_question_set_status($oldquestion, $hidden ? AS_POST_STATUS_HIDDEN : AS_POST_STATUS_NORMAL, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost);
	}

	
	function as_question_set_status($oldquestion, $status, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost=null)
/*
	Set the status (application level) of $oldquestion to $status, one of the AS_POST_STATUS_* constants above. Pass
	details of the user doing this in $userid, $handle and $cookieid, the database records for all answers to the
	question in $answers, the database records for all comments on the question or the question's answers in
	$commentsfollows ($commentsfollows can also contain records for follow-on questions which are ignored), and
	$closepost to match $oldquestion['closedbyid'] (if any). Handles indexing, user points, cached counts and event
	reports. See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		require_once AS_INCLUDE_DIR.'as-app-format.php';
		require_once AS_INCLUDE_DIR.'as-app-updates.php';
		
		$washidden=($oldquestion['type']=='Q_HIDDEN');
		$wasqueued=($oldquestion['type']=='Q_QUEUED');
		$wasrequeued=$wasqueued && isset($oldquestion['updated']);
		
		as_post_unindex($oldquestion['postid']);
		
		foreach ($answers as $answer)
			as_post_unindex($answer['postid']);
		
		foreach ($commentsfollows as $comment)
			if ($comment['basetype']=='C')
				as_post_unindex($comment['postid']);
				
		if (@$closepost['parentid']==$oldquestion['postid'])
			as_post_unindex($closepost['postid']);
			
		$setupdated=false;
		$event=null;
		
		if ($status==AS_POST_STATUS_QUEUED) {
			$newtype='Q_QUEUED';
			if (!$wasqueued)
				$event='q_requeue'; // same event whether it was hidden or shown before

		} elseif ($status==AS_POST_STATUS_HIDDEN) {
			$newtype='Q_HIDDEN';
			if (!$washidden) {
				$event=$wasqueued ? 'q_reject' : 'q_hide';
				if (!$wasqueued)
					$setupdated=true;
			}
				
		} elseif ($status==AS_POST_STATUS_NORMAL) {
			$newtype='Q';
			if ($wasqueued)
				$event='q_approve';
			elseif ($washidden) {
				$event='q_reshow';
				$setupdated=true;
			}
		
		} else
			as_fatal_error('Unknown status in as_question_set_status(): '.$status);
			
		as_db_post_set_type($oldquestion['postid'], $newtype, $setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null, AS_UPDATE_VISIBLE);
		
		if ( $wasqueued && ($status==AS_POST_STATUS_NORMAL) && as_opt('moderate_update_time') ) { // ... for approval of a post, can set time to now instead			
			if ($wasrequeued) // reset edit time to now if there was one, since we're approving the edit...
				as_db_post_set_updated($oldquestion['postid'], null);
				
			else { // ... otherwise we're approving original created post
				as_db_post_set_created($oldquestion['postid'], null);
				as_db_hotness_update($oldquestion['postid']);
			}
		}
		
		as_update_counts_for_q($oldquestion['postid']);
		as_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects'));
		
		if ($wasqueued || ($status==AS_POST_STATUS_QUEUED))
			as_db_queuedcount_update();			
		
		if ($oldquestion['flagcount'])
			as_db_flaggedcount_update();
			
		if ($status==AS_POST_STATUS_NORMAL) {
			as_post_index($oldquestion['postid'], 'Q', $oldquestion['postid'], $oldquestion['parentid'], $oldquestion['title'], $oldquestion['content'],
				$oldquestion['format'], as_viewer_text($oldquestion['content'], $oldquestion['format']), $oldquestion['tags'], $oldquestion['categoryid']);

			foreach ($answers as $answer)
				if ($answer['type']=='A') // even if question visible, don't index hidden or queued answers
					as_post_index($answer['postid'], $answer['type'], $oldquestion['postid'], $answer['parentid'], null,
						$answer['content'], $answer['format'], as_viewer_text($answer['content'], $answer['format']), null, $answer['categoryid']);
					
			foreach ($commentsfollows as $comment)
				if ($comment['type']=='C') {
					$answer=@$answers[$comment['parentid']];
					
					if ( (!isset($answer)) || ($answer['type']=='A') ) // don't index comment if it or its parent is hidden
						as_post_index($comment['postid'], $comment['type'], $oldquestion['postid'], $comment['parentid'], null,
							$comment['content'], $comment['format'], as_viewer_text($comment['content'], $comment['format']), null, $comment['categoryid']);
				}
							
			if ($closepost['parentid']==$oldquestion['postid'])
				as_post_index($closepost['postid'], $closepost['type'], $oldquestion['postid'], $closepost['parentid'], null,
					$closepost['content'], $closepost['format'], as_viewer_text($closepost['content'], $closepost['format']), null, $closepost['categoryid']);
		}
		
		$eventparams=array(
			'postid' => $oldquestion['postid'],
			'parentid' => $oldquestion['parentid'],
			'parent' => isset($oldquestion['parentid']) ? as_db_single_select(as_db_full_post_selectspec(null, $oldquestion['parentid'])) : null,
			'title' => $oldquestion['title'],
			'content' => $oldquestion['content'],
			'format' => $oldquestion['format'],
			'text' => as_viewer_text($oldquestion['content'], $oldquestion['format']),
			'tags' => $oldquestion['tags'],
			'categoryid' => $oldquestion['categoryid'],
			'name' => $oldquestion['name'],
		);
		
		if (isset($event))
			as_report_event($event, $userid, $handle, $cookieid, $eventparams + array(
				'oldquestion' => $oldquestion,
			));
		
		if ($wasqueued && ($status==AS_POST_STATUS_NORMAL) && !$wasrequeued) {
			require_once AS_INCLUDE_DIR.'as-db-selects.php';
			require_once AS_INCLUDE_DIR.'as-util-string.php';
			
			as_report_event('q_post', $oldquestion['userid'], $oldquestion['handle'], $oldquestion['cookieid'], $eventparams + array(
				'notify' => isset($oldquestion['notify']),
				'email' => as_email_validate($oldquestion['notify']) ? $oldquestion['notify'] : null,
				'delayed' => $oldquestion['created'],
			));
		}
	}
	
	
	function as_question_set_category($oldquestion, $categoryid, $userid, $handle, $cookieid, $answers, $commentsfollows, $closepost=null, $silent=false)
/*
	Sets the category (application level) of $oldquestion to $categoryid. Pass details of the user doing this in
	$userid, $handle and $cookieid, the database records for all answers to the question in $answers, the database
	records for all comments on the question or the question's answers in $commentsfollows ($commentsfollows can also
	contain records for follow-on questions which are ignored), and $closepost to match $oldquestion['closedbyid'] (if any).
	Set $silent to true to not mark the question as edited. Handles cached counts and event reports and will reset category
	IDs and paths for all answers and comments. See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		$oldpath=as_db_post_get_category_path($oldquestion['postid']);
		
		as_db_post_set_category($oldquestion['postid'], $categoryid, $silent ? null : $userid, $silent ? null : as_remote_ip_address());
		as_db_posts_calc_category_path($oldquestion['postid']);
		
		$newpath=as_db_post_get_category_path($oldquestion['postid']);
		
		as_db_category_path_qcount_update($oldpath);
		as_db_category_path_qcount_update($newpath);

		$otherpostids=array();
		foreach ($answers as $answer)
			$otherpostids[]=$answer['postid'];
			
		foreach ($commentsfollows as $comment)
			if ($comment['basetype']=='C')
				$otherpostids[]=$comment['postid'];
				
		if (@$closepost['parentid']==$oldquestion['postid'])
			$otherpostids[]=$closepost['postid'];
				
		as_db_posts_set_category_path($otherpostids, $newpath);

		$searchmodules=as_load_modules_with('search', 'move_post');
		foreach ($searchmodules as $searchmodule) {
			$searchmodule->move_post($oldquestion['postid'], $categoryid);
			foreach ($otherpostids as $otherpostid)
				$searchmodule->move_post($otherpostid, $categoryid);
		}

		as_report_event('q_move', $userid, $handle, $cookieid, array(
			'postid' => $oldquestion['postid'],
			'oldquestion' => $oldquestion,
			'categoryid' => $categoryid,
			'oldcategoryid' => $oldquestion['categoryid'],
		));
	}
	
	
	function as_question_delete($oldquestion, $userid, $handle, $cookieid, $oldclosepost=null)
/*
	Permanently delete a question (application level) from the database. The question must not have any answers or
	comments on it. Pass details of the user doing this in $userid, $handle and $cookieid, and $closepost to match
	$oldquestion['closedbyid'] (if any). Handles unindexing, votes, points, cached counts and event reports.
	See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		require_once AS_INCLUDE_DIR.'as-db-votes.php';
		
		if ($oldquestion['type']!='Q_HIDDEN')
			as_fatal_error('Tried to delete a non-hidden question');
		
		if (isset($oldclosepost) && ($oldclosepost['parentid']==$oldquestion['postid'])) {
			as_db_post_set_closed($oldquestion['postid'], null); // for foreign key constraint
			as_post_unindex($oldclosepost['postid']);
			as_db_post_delete($oldclosepost['postid']);
		}

		$useridvotes=as_db_uservote_post_get($oldquestion['postid']);
		$oldpath=as_db_post_get_category_path($oldquestion['postid']);
		
		as_post_unindex($oldquestion['postid']);
		as_db_post_delete($oldquestion['postid']); // also deletes any related voteds due to foreign key cascading
		as_update_counts_for_q(null);
		as_db_category_path_qcount_update($oldpath); // don't do inside as_update_counts_for_q() since post no longer exists
		as_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects', 'qvoteds', 'upvoteds', 'downvoteds'));
		
		foreach ($useridvotes as $voteruserid => $vote)
			as_db_points_update_ifuser($voteruserid, ($vote>0) ? 'qupvotes' : 'qdownvotes');
				// could do this in one query like in as_db_users_recalc_points() but this will do for now - unlikely to be many votes
		
		as_report_event('q_delete', $userid, $handle, $cookieid, array(
			'postid' => $oldquestion['postid'],
			'oldquestion' => $oldquestion,
		));
	}


	function as_question_set_userid($oldquestion, $userid, $handle, $cookieid)
/*
	Set the author (application level) of $oldquestion to $userid and also pass $handle and $cookieid
	of user. Updates points and reports events as appropriate.
*/
	{
		as_db_post_set_userid($oldquestion['postid'], $userid);

		as_db_points_update_ifuser($oldquestion['userid'], array('qposts', 'aselects', 'qvoteds', 'upvoteds', 'downvoteds'));
		as_db_points_update_ifuser($userid, array('qposts', 'aselects', 'qvoteds', 'upvoteds', 'downvoteds'));
		
		as_report_event('q_claim', $userid, $handle, $cookieid, array(
			'postid' => $oldquestion['postid'],
			'oldquestion' => $oldquestion,
		));
	}

	
	function as_post_unindex($postid)
/*
	Remove post $postid from our index and update appropriate word counts. Calls through to all search modules.
*/
	{
		global $as_post_indexing_suspended;

		if ($as_post_indexing_suspended>0)
			return;
			
	//	Send through to any search modules for unindexing
	
		$searchmodules=as_load_modules_with('search', 'unindex_post');
		foreach ($searchmodules as $searchmodule)
			$searchmodule->unindex_post($postid);
	}

	
	function as_answer_set_content($oldanswer, $content, $format, $text, $notify, $userid, $handle, $cookieid, $question, $name=null, $remoderate=false, $silent=false)
/*
	Change the fields of an answer (application level) to $content, $format, $notify and $name, then reindex based on
	$text. For backwards compatibility if $name is null then the name will not be changed. Pass the answer's database
	record before changes in $oldanswer, the question's in $question, and details of the user doing this in $userid,
	$handle and $cookieid. Set $remoderate to true if the question should be requeued for moderation if modified. Set
	$silent to true to not mark the question as edited. Handle indexing and event reports as appropriate. See
	as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		as_post_unindex($oldanswer['postid']);
		
		$wasqueued=($oldanswer['type']=='A_QUEUED');
		$contentchanged=strcmp($oldanswer['content'], $content) || strcmp($oldanswer['format'], $format);
		$setupdated=$contentchanged && (!$wasqueued) && !$silent;
		
		as_db_post_set_content($oldanswer['postid'], $oldanswer['title'], $content, $format, $oldanswer['tags'], $notify,
			$setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null, AS_UPDATE_CONTENT, $name);
		
		if ($setupdated && $remoderate) {
			require_once AS_INCLUDE_DIR.'as-app-posts.php';
			
			$commentsfollows=as_post_get_answer_commentsfollows($oldanswer['postid']);

			foreach ($commentsfollows as $comment)
				if ( ($comment['basetype']=='C') && ($comment['parentid']==$oldanswer['postid']) )
					as_post_unindex($comment['postid']);
					
			as_db_post_set_type($oldanswer['postid'], 'A_QUEUED');			
			as_update_q_counts_for_a($question['postid']);
			as_db_queuedcount_update();
			as_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds'));
			
			if ($oldanswer['flagcount'])
				as_db_flaggedcount_update();
			
		} elseif ( ($oldanswer['type']=='A') && ($question['type']=='Q') ) { // don't index if question or answer are hidden/queued
			as_post_index($oldanswer['postid'], 'A', $question['postid'], $oldanswer['parentid'], null, $content, $format, $text, null, $oldanswer['categoryid']);
		}

		$eventparams=array(
			'postid' => $oldanswer['postid'],
			'parentid' => $oldanswer['parentid'],
			'parent' => $question,
			'content' => $content,
			'format' => $format,
			'text' => $text,
			'name' => $name,
			'oldanswer' => $oldanswer,
		);
		
		as_report_event('a_edit', $userid, $handle, $cookieid, $eventparams + array(
			'silent' => $silent,
			'oldcontent' => $oldanswer['content'],
			'oldformat' => $oldanswer['format'],
			'contentchanged' => $contentchanged,
		));
		
		if ($setupdated && $remoderate)
			as_report_event('a_requeue', $userid, $handle, $cookieid, $eventparams);
	}

	
	function as_answer_set_hidden($oldanswer, $hidden, $userid, $handle, $cookieid, $question, $commentsfollows)
/*
	Set $oldanswer to hidden if $hidden is true, visible/normal if otherwise. All other parameters are as for as_answer_set_status(...)
	This function is included mainly for backwards compatibility.
*/
	{
		as_answer_set_status($oldanswer, $hidden ? AS_POST_STATUS_HIDDEN : AS_POST_STATUS_NORMAL, $userid, $handle, $cookieid, $question, $commentsfollows);
	}
	
	
	function as_answer_set_status($oldanswer, $status, $userid, $handle, $cookieid, $question, $commentsfollows)
/*
	Set the status (application level) of $oldanswer to $status, one of the AS_POST_STATUS_* constants above. Pass
	details of the user doing this in $userid, $handle and $cookieid, the database record for the question in $question,
	and the database records for all comments on the answer in $commentsfollows ($commentsfollows can also contain other
	records which are ignored). Handles indexing, user points, cached counts and event reports. See as-app-posts.php for
	a higher-level function which is easier to use.
*/
	{
		require_once AS_INCLUDE_DIR.'as-app-format.php';
			
		$washidden=($oldanswer['type']=='A_HIDDEN');
		$wasqueued=($oldanswer['type']=='A_QUEUED');
		$wasrequeued=$wasqueued && isset($oldanswer['updated']);
		
		as_post_unindex($oldanswer['postid']);
		
		foreach ($commentsfollows as $comment)
			if ( ($comment['basetype']=='C') && ($comment['parentid']==$oldanswer['postid']) )
				as_post_unindex($comment['postid']);
		
		$setupdated=false;
		$event=null;
		
		if ($status==AS_POST_STATUS_QUEUED) {
			$newtype='A_QUEUED';
			if (!$wasqueued)
				$event='a_requeue'; // same event whether it was hidden or shown before

		} elseif ($status==AS_POST_STATUS_HIDDEN) {
			$newtype='A_HIDDEN';
			if (!$washidden) {
				$event=$wasqueued ? 'a_reject' : 'a_hide';
				if (!$wasqueued)
					$setupdated=true;
			}
				
		} elseif ($status==AS_POST_STATUS_NORMAL) {
			$newtype='A';
			if ($wasqueued)
				$event='a_approve';
			elseif ($washidden) {
				$event='a_reshow';
				$setupdated=true;
			}
		
		} else
			as_fatal_error('Unknown status in as_answer_set_status(): '.$status);
		
		as_db_post_set_type($oldanswer['postid'], $newtype, $setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null, AS_UPDATE_VISIBLE);

		if ( $wasqueued && ($status==AS_POST_STATUS_NORMAL) && as_opt('moderate_update_time') ) { // ... for approval of a post, can set time to now instead
			if ($wasrequeued)
				as_db_post_set_updated($oldanswer['postid'], null);
			else
				as_db_post_set_created($oldanswer['postid'], null);
		}
		
		as_update_q_counts_for_a($question['postid']);
		as_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds'));
		
		if ($wasqueued || ($status==AS_POST_STATUS_QUEUED))
			as_db_queuedcount_update();
		
		if ($oldanswer['flagcount'])
			as_db_flaggedcount_update();
			
		if (($question['type']=='Q') && ($status==AS_POST_STATUS_NORMAL)) { // even if answer visible, don't index if question is hidden or queued
			as_post_index($oldanswer['postid'], 'A', $question['postid'], $oldanswer['parentid'], null, $oldanswer['content'],
				$oldanswer['format'], as_viewer_text($oldanswer['content'], $oldanswer['format']), null, $oldanswer['categoryid']);
			
			foreach ($commentsfollows as $comment)
				if ( ($comment['type']=='C') && ($comment['parentid']==$oldanswer['postid']) ) // and don't index hidden/queued comments
					as_post_index($comment['postid'], $comment['type'], $question['postid'], $comment['parentid'], null, $comment['content'],
						$comment['format'], as_viewer_text($comment['content'], $comment['format']), null, $comment['categoryid']);
		}
		
		$eventparams=array(
			'postid' => $oldanswer['postid'],
			'parentid' => $oldanswer['parentid'],
			'parent' => $question,
			'content' => $oldanswer['content'],
			'format' => $oldanswer['format'],
			'text' => as_viewer_text($oldanswer['content'], $oldanswer['format']),
			'categoryid' => $oldanswer['categoryid'],
			'name' => $oldanswer['name'],
		);

		if (isset($event))
			as_report_event($event, $userid, $handle, $cookieid, $eventparams + array(
				'oldanswer' => $oldanswer,
			));
		
		if ($wasqueued && ($status==AS_POST_STATUS_NORMAL) && !$wasrequeued) {
			require_once AS_INCLUDE_DIR.'as-util-string.php';
			
			as_report_event('a_post', $oldanswer['userid'], $oldanswer['handle'], $oldanswer['cookieid'], $eventparams + array(
				'notify' => isset($oldanswer['notify']),
				'email' => as_email_validate($oldanswer['notify']) ? $oldanswer['notify'] : null,
				'delayed' => $oldanswer['created'],
			));
		}
	}

	
	function as_answer_delete($oldanswer, $question, $userid, $handle, $cookieid)
/*
	Permanently delete an answer (application level) from the database. The answer must not have any comments or
	follow-on questions. Pass the database record for the question in $question and details of the user doing this
	in $userid, $handle and $cookieid. Handles unindexing, votes, points, cached counts and event reports.
	See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		require_once AS_INCLUDE_DIR.'as-db-votes.php';
		
		if ($oldanswer['type']!='A_HIDDEN')
			as_fatal_error('Tried to delete a non-hidden answer');
		
		$useridvotes=as_db_uservote_post_get($oldanswer['postid']);
		
		as_post_unindex($oldanswer['postid']);
		as_db_post_delete($oldanswer['postid']); // also deletes any related voteds due to cascading
		
		if ($question['selchildid']==$oldanswer['postid']) {
			as_db_post_set_selchildid($question['postid'], null);
			as_db_points_update_ifuser($question['userid'], 'aselects');
			as_db_unselqcount_update();
		}
		
		as_update_q_counts_for_a($question['postid']);		
		as_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds', 'avoteds', 'upvoteds', 'downvoteds'));
		
		foreach ($useridvotes as $voteruserid => $vote)
			as_db_points_update_ifuser($voteruserid, ($vote>0) ? 'aupvotes' : 'adownvotes');
				// could do this in one query like in as_db_users_recalc_points() but this will do for now - unlikely to be many votes
		
		as_report_event('a_delete', $userid, $handle, $cookieid, array(
			'postid' => $oldanswer['postid'],
			'parentid' => $oldanswer['parentid'],
			'oldanswer' => $oldanswer,
		));
	}
	
	
	function as_answer_set_userid($oldanswer, $userid, $handle, $cookieid)
/*
	Set the author (application level) of $oldanswer to $userid and also pass $handle and $cookieid
	of user. Updates points and reports events as appropriate.
*/
	{
		as_db_post_set_userid($oldanswer['postid'], $userid);

		as_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds', 'avoteds', 'upvoteds', 'downvoteds'));
		as_db_points_update_ifuser($userid, array('aposts', 'aselecteds', 'avoteds', 'upvoteds', 'downvoteds'));

		as_report_event('a_claim', $userid, $handle, $cookieid, array(
			'postid' => $oldanswer['postid'],
			'parentid' => $oldanswer['parentid'],
			'oldanswer' => $oldanswer,
		));
	}

	
	function as_comment_set_content($oldcomment, $content, $format, $text, $notify, $userid, $handle, $cookieid, $question, $parent, $name=null, $remoderate=false, $silent=false)
/*
	Change the fields of a comment (application level) to $content, $format, $notify and $name, then reindex based on
	$text. For backwards compatibility if $name is null then the name will not be changed. Pass the comment's database
	record before changes in $oldcomment, details of the user doing this in $userid, $handle and $cookieid, the
	antecedent question in $question and the answer's database record in $answer if this is a comment on an answer,
	otherwise null. Set $remoderate to true if the question should be requeued for moderation if modified. Set $silent
	to true to not mark the question as edited. Handles unindexing and event reports. See as-app-posts.php for a
	higher-level function which is easier to use.
*/
	{
		if (!isset($parent))
			$parent=$question; // for backwards compatibility with old answer parameter
		
		as_post_unindex($oldcomment['postid']);
		
		$wasqueued=($oldcomment['type']=='C_QUEUED');
		$contentchanged=strcmp($oldcomment['content'], $content) || strcmp($oldcomment['format'], $format);
		$setupdated=$contentchanged && (!$wasqueued) && !$silent;
		
		as_db_post_set_content($oldcomment['postid'], $oldcomment['title'], $content, $format, $oldcomment['tags'], $notify,
			$setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null, AS_UPDATE_CONTENT, $name);
		
		if ($setupdated && $remoderate) {
			as_db_post_set_type($oldcomment['postid'], 'C_QUEUED');
			as_db_ccount_update();
			as_db_queuedcount_update();
			as_db_points_update_ifuser($oldcomment['userid'], array('cposts'));
			
			if ($oldcomment['flagcount'])
				as_db_flaggedcount_update();
			
		} elseif ( ($oldcomment['type']=='C') && ($question['type']=='Q') && (($parent['type']=='Q') || ($parent['type']=='A')) ) { // all must be visible
			as_post_index($oldcomment['postid'], 'C', $question['postid'], $oldcomment['parentid'], null, $content, $format, $text, null, $oldcomment['categoryid']);
		}
		
		$eventparams=array(
			'postid' => $oldcomment['postid'],
			'parentid' => $oldcomment['parentid'],
			'parenttype' => $parent['basetype'],
			'parent' => $parent,
			'questionid' => $question['postid'],
			'question' => $question,
			'content' => $content,
			'format' => $format,
			'text' => $text,
			'name' => $name,
			'oldcomment' => $oldcomment,
		);

		as_report_event('c_edit', $userid, $handle, $cookieid, $eventparams + array(
			'silent' => $silent,
			'oldcontent' => $oldcomment['content'],
			'oldformat' => $oldcomment['format'],
			'contentchanged' => $contentchanged,
		));
		
		if ($setupdated && $remoderate)
			as_report_event('c_requeue', $userid, $handle, $cookieid, $eventparams);
	}

	
	function as_answer_to_comment($oldanswer, $parentid, $content, $format, $text, $notify, $userid, $handle, $cookieid, $question, $answers, $commentsfollows, $name=null, $remoderate=false, $silent=false)
/*
	Convert an answer to a comment (application level) and set its fields to $content, $format, $notify and $name. For
	backwards compatibility if $name is null then the name will not be changed. Pass the answer's database record before
	changes in $oldanswer, the new comment's $parentid to be, details of the user doing this in $userid, $handle and
	$cookieid, the antecedent question's record in $question, the records for all answers to that question in $answers,
	and the records for all comments on the (old) answer and questions following from the (old) answer in
	$commentsfollows ($commentsfollows can also contain other records which are ignored). Set $remoderate to true if the
	question should be requeued for moderation if modified. Set $silent to true to not mark the question as edited.
	Handles indexing (based on $text), user points, cached counts and event reports.
*/
	{
		$parent=isset($answers[$parentid]) ? $answers[$parentid] : $question;
			
		as_post_unindex($oldanswer['postid']);
		
		$wasqueued=($oldanswer['type']=='A_QUEUED');
		$contentchanged=strcmp($oldanswer['content'], $content) || strcmp($oldanswer['format'], $format);
		$setupdated=$contentchanged && (!$wasqueued) && !$silent;
		
		if ($setupdated && $remoderate)
			$newtype='C_QUEUED';
		else
			$newtype=substr_replace($oldanswer['type'], 'C', 0, 1);
		
		as_db_post_set_type($oldanswer['postid'], $newtype, ($wasqueued || $silent) ? null : $userid,
			($wasqueued || $silent) ? null : as_remote_ip_address(), AS_UPDATE_TYPE);
		as_db_post_set_parent($oldanswer['postid'], $parentid);
		as_db_post_set_content($oldanswer['postid'], $oldanswer['title'], $content, $format, $oldanswer['tags'], $notify,
			$setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null, AS_UPDATE_CONTENT, $name);
		
		foreach ($commentsfollows as $commentfollow)
			if ($commentfollow['parentid']==$oldanswer['postid']) // do same thing for comments and follows
				as_db_post_set_parent($commentfollow['postid'], $parentid);

		as_update_q_counts_for_a($question['postid']);
		as_db_ccount_update();
		as_db_points_update_ifuser($oldanswer['userid'], array('aposts', 'aselecteds', 'cposts'));
	
		if ($setupdated && $remoderate) {
			as_db_queuedcount_update();

			if ($oldanswer['flagcount'])
				as_db_flaggedcount_update();
			
		} elseif ( ($oldanswer['type']=='A') && ($question['type']=='Q') && (($parent['type']=='Q') || ($parent['type']=='A')) ) // only if all fully visible
			as_post_index($oldanswer['postid'], 'C', $question['postid'], $parentid, null, $content, $format, $text, null, $oldanswer['categoryid']);
			
		$eventparams=array(
			'postid' => $oldanswer['postid'],
			'parentid' => $parentid,
			'parenttype' => $parent['basetype'],
			'parent' => $parent,
			'questionid' => $question['postid'],
			'question' => $question,
			'content' => $content,
			'format' => $format,
			'text' => $text,
			'name' => $name,
			'oldanswer' => $oldanswer,
		);

		as_report_event('a_to_c', $userid, $handle, $cookieid, $eventparams + array(
			'silent' => $silent,
			'oldcontent' => $oldanswer['content'],
			'oldformat' => $oldanswer['format'],
			'contentchanged' => $contentchanged,
		));
		
		if ($setupdated && $remoderate)
			as_report_event('c_requeue', $userid, $handle, $cookieid, $eventparams);
				// a-to-c conversion can be detected by presence of $event['oldanswer'] instead of $event['oldcomment']
	}

	
	function as_comment_set_hidden($oldcomment, $hidden, $userid, $handle, $cookieid, $question, $parent)
/*
	Set $oldcomment to hidden if $hidden is true, visible/normal if otherwise. All other parameters are as for as_comment_set_status(...)
	This function is included mainly for backwards compatibility.
*/
	{
		as_comment_set_status($oldcomment, $hidden ? AS_POST_STATUS_HIDDEN : AS_POST_STATUS_NORMAL, $userid, $handle, $cookieid, $question, $parent);
	}
	
	
	function as_comment_set_status($oldcomment, $status, $userid, $handle, $cookieid, $question, $parent)
/*
	Set the status (application level) of $oldcomment to $status, one of the AS_POST_STATUS_* constants above. Pass the
	antecedent question's record in $question, details of the user doing this in $userid, $handle and $cookieid, and the
	answer's database record in $answer if this is a comment on an answer, otherwise null. Handles indexing, user
	points, cached counts and event reports. See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		require_once AS_INCLUDE_DIR.'as-app-format.php';
		
		if (!isset($parent))
			$parent=$question; // for backwards compatibility with old answer parameter
		
		$washidden=($oldcomment['type']=='C_HIDDEN');
		$wasqueued=($oldcomment['type']=='C_QUEUED');
		$wasrequeued=$wasqueued && isset($oldcomment['updated']);
		
		as_post_unindex($oldcomment['postid']);
		
		$setupdated=false;
		$event=null;
		
		if ($status==AS_POST_STATUS_QUEUED) {
			$newtype='C_QUEUED';
			if (!$wasqueued)
				$event='c_requeue'; // same event whether it was hidden or shown before

		} elseif ($status==AS_POST_STATUS_HIDDEN) {
			$newtype='C_HIDDEN';
			if (!$washidden) {
				$event=$wasqueued ? 'c_reject' : 'c_hide';
				if (!$wasqueued)
					$setupdated=true;
			}
				
		} elseif ($status==AS_POST_STATUS_NORMAL) {
			$newtype='C';
			if ($wasqueued)
				$event='c_approve';
			elseif ($washidden) {
				$event='c_reshow';
				$setupdated=true;
			}
		
		} else
			as_fatal_error('Unknown status in as_comment_set_status(): '.$status);

		as_db_post_set_type($oldcomment['postid'], $newtype, $setupdated ? $userid : null, $setupdated ? as_remote_ip_address() : null, AS_UPDATE_VISIBLE);

		if ( $wasqueued && ($status==AS_POST_STATUS_NORMAL) && as_opt('moderate_update_time') ) { // ... for approval of a post, can set time to now instead
			if ($wasrequeued)
				as_db_post_set_updated($oldcomment['postid'], null);
			else
				as_db_post_set_created($oldcomment['postid'], null);
		}
			
		as_db_ccount_update();
		as_db_points_update_ifuser($oldcomment['userid'], array('cposts'));
		
		if ($wasqueued || ($status==AS_POST_STATUS_QUEUED))
			as_db_queuedcount_update();
		
		if ($oldcomment['flagcount'])
			as_db_flaggedcount_update();
			
		if ( ($question['type']=='Q') && (($parent['type']=='Q') || ($parent['type']=='A')) && ($status==AS_POST_STATUS_NORMAL)) // only index if none of the things it depends on are hidden or queued
			as_post_index($oldcomment['postid'], 'C', $question['postid'], $oldcomment['parentid'], null, $oldcomment['content'],
				$oldcomment['format'], as_viewer_text($oldcomment['content'], $oldcomment['format']), null, $oldcomment['categoryid']);
				
		$eventparams=array(
			'postid' => $oldcomment['postid'],
			'parentid' => $oldcomment['parentid'],
			'parenttype' => $parent['basetype'],
			'parent' => $parent,
			'questionid' => $question['postid'],
			'question' => $question,
			'content' => $oldcomment['content'],
			'format' => $oldcomment['format'],
			'text' => as_viewer_text($oldcomment['content'], $oldcomment['format']),
			'categoryid' => $oldcomment['categoryid'],
			'name' => $oldcomment['name'],
		);

		if (isset($event))
			as_report_event($event, $userid, $handle, $cookieid, $eventparams + array(
				'oldcomment' => $oldcomment,
			));
		
		if ($wasqueued && ($status==AS_POST_STATUS_NORMAL) && !$wasrequeued) {
			require_once AS_INCLUDE_DIR.'as-db-selects.php';
			require_once AS_INCLUDE_DIR.'as-util-string.php';
			
			$commentsfollows=as_db_single_select(as_db_full_child_posts_selectspec(null, $oldcomment['parentid']));			
			$thread=array();
			
			foreach ($commentsfollows as $comment)
				if (($comment['type']=='C') && ($comment['parentid']==$parent['postid']))
					$thread[]=$comment;
				
			as_report_event('c_post', $oldcomment['userid'], $oldcomment['handle'], $oldcomment['cookieid'], $eventparams + array(
				'thread' => $thread,
				'notify' => isset($oldcomment['notify']),
				'email' => as_email_validate($oldcomment['notify']) ? $oldcomment['notify'] : null,
				'delayed' => $oldcomment['created'],
			));
		}
	}

	
	function as_comment_delete($oldcomment, $question, $parent, $userid, $handle, $cookieid)
/*
	Permanently delete a comment in $oldcomment (application level) from the database. Pass the database question in $question
	and the answer's database record in $answer if this is a comment on an answer, otherwise null. Pass details of the user
	doing this in $userid, $handle and $cookieid. Handles unindexing, points, cached counts and event reports.
	See as-app-posts.php for a higher-level function which is easier to use.
*/
	{
		if (!isset($parent))
			$parent=$question; // for backwards compatibility with old answer parameter
		
		if ($oldcomment['type']!='C_HIDDEN')
			as_fatal_error('Tried to delete a non-hidden comment');
		
		as_post_unindex($oldcomment['postid']);
		as_db_post_delete($oldcomment['postid']);
		as_db_points_update_ifuser($oldcomment['userid'], array('cposts'));
		as_db_ccount_update();

		as_report_event('c_delete', $userid, $handle, $cookieid, array(
			'postid' => $oldcomment['postid'],
			'parentid' => $oldcomment['parentid'],
			'oldcomment' => $oldcomment,
			'parenttype' => $parent['basetype'],
			'questionid' => $question['postid'],
		));
	}

	
	function as_comment_set_userid($oldcomment, $userid, $handle, $cookieid)
/*
	Set the author (application level) of $oldcomment to $userid and also pass $handle and $cookieid
	of user. Updates points and reports events as appropriate.
*/
	{
		as_db_post_set_userid($oldcomment['postid'], $userid);
		
		as_db_points_update_ifuser($oldcomment['userid'], array('cposts'));
		as_db_points_update_ifuser($userid, array('cposts'));

		as_report_event('c_claim', $userid, $handle, $cookieid, array(
			'postid' => $oldcomment['postid'],
			'parentid' => $oldcomment['parentid'],
			'oldcomment' => $oldcomment,
		));
	}
	

/*
	Omit PHP closing tag to help avoid accidental output
*/