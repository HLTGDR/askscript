<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-db-install.php
	Version: See define()s at top of as-include/as-base.php
	Description: Database-level functions for installation and upgrading


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

	define('AS_DB_VERSION_CURRENT', 56);


	function as_db_user_column_type_verify()
/*
	Return the column type for user ids after verifying it is one of the legal options
*/
	{
		$coltype=strtoupper(as_get_mysql_user_column_type());

		switch ($coltype) {
			case 'SMALLINT':
			case 'MEDIUMINT':
			case 'INT':
			case 'BIGINT':
			case 'SMALLINT UNSIGNED':
			case 'MEDIUMINT UNSIGNED':
			case 'INT UNSIGNED':
			case 'BIGINT UNSIGNED':
				// these are all OK
				break;
			
			default:
				if (!preg_match('/VARCHAR\([0-9]+\)/', $coltype))
					as_fatal_error('Specified user column type is not one of allowed values - please read documentation');
				break;
		}
		
		return $coltype;
	}

	
	function as_db_table_definitions()
/*
	Return an array of table definitions. For each element of the array, the key is the table name (without prefix)
	and the value is an array of column definitions, [column name] => [definition]. The column name is omitted for indexes.
*/
	{
		if (as_to_override(__FUNCTION__)) { $args=func_get_args(); return as_call_override(__FUNCTION__, $args); }
		
		require_once AS_INCLUDE_DIR.'as-db-maxima.php';
		require_once AS_INCLUDE_DIR.'as-app-users.php';

	/*
		Important note on character encoding in database and PHP connection to MySQL

		[this note is no longer relevant since we *do* explicitly set the connection character set since Q2A 1.5 - see as-db.php
	*/
	
	/*
		Other notes on the definitions below
		
		* In MySQL versions prior to 5.0.3, VARCHAR(x) columns will be silently converted to TEXT where x>255
		
		* See box at top of as-app-recalc.php for a list of redundant (non-normal) information in the database
		
		* Starting in version 1.2, we explicitly name keys and foreign key constraints, instead of allowing MySQL
		  to name these by default. Our chosen names match the default names that MySQL would have assigned, and
		  indeed *did* assign for people who installed an earlier version of Q2A. By naming them explicitly, we're
		  on more solid ground for possible future changes to indexes and foreign keys in the schema.
		  
		* There are other foreign key constraints that it would be valid to add, but that would not serve much
		  purpose in terms of preventing inconsistent data being retrieved, and would just slow down some queries.
		  
		* We name some columns here in a not entirely intuitive way. The reason is to match the names of columns in
		  other tables which are of a similar nature. This will save time and space when combining several SELECT
		  queries together via a UNION in as_db_multi_select() - see comments in as-db.php for more information.
	*/
	
		$useridcoltype=as_db_user_column_type_verify();

		$tables=array(
			'users' => array(
				'userid' => $useridcoltype.' NOT NULL AUTO_INCREMENT',
				'created' => 'DATETIME NOT NULL',
				'createip' => 'INT UNSIGNED NOT NULL', // INET_ATON of IP address when created
				'email' => 'VARCHAR('.AS_DB_MAX_EMAIL_LENGTH.') NOT NULL',
				'handle' => 'VARCHAR('.AS_DB_MAX_HANDLE_LENGTH.') NOT NULL', // username
				'avatarblobid' => 'BIGINT UNSIGNED', // blobid of stored avatar
				'avatarwidth' => 'SMALLINT UNSIGNED', // pixel width of stored avatar
				'avatarheight' => 'SMALLINT UNSIGNED', // pixel height of stored avatar
				'passsalt' => 'BINARY(16)', // salt used to calculate passcheck - null if no password set for direct login
				'passcheck' => 'BINARY(20)', // checksum from password and passsalt - null if no passowrd set for direct login
				'level' => 'TINYINT UNSIGNED NOT NULL', // basic, editor, admin, etc...
				'loggedin' => 'DATETIME NOT NULL', // time of last login
				'loginip' => 'INT UNSIGNED NOT NULL', // INET_ATON of IP address of last login
				'written' => 'DATETIME', // time of last write action done by user
				'writeip' => 'INT UNSIGNED', // INET_ATON of IP address of last write action done by user
				'emailcode' => 'CHAR(8) CHARACTER SET ascii NOT NULL DEFAULT \'\'', // for email confirmation or password reset
				'sessioncode' => 'CHAR(8) CHARACTER SET ascii NOT NULL DEFAULT \'\'', // for comparing against session cookie in browser
				'sessionsource' => 'VARCHAR (16) CHARACTER SET ascii DEFAULT \'\'', // e.g. facebook, openid, etc...
				'flags' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', // see constants at top of as-app-users.php
				'wallposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // cached count of wall posts 
				'PRIMARY KEY (userid)',
				'KEY email (email)',
				'KEY handle (handle)',
				'KEY level (level)',
				'kEY created (created, level, flags)',
			),
			
			'userlogins' => array(
				'userid' => $useridcoltype.' NOT NULL',
				'source' => 'VARCHAR (16) CHARACTER SET ascii NOT NULL', // e.g. facebook, openid, etc...
				'identifier' => 'VARBINARY (1024) NOT NULL', // depends on source, e.g. Facebook uid or OpenID url
				'identifiermd5' => 'BINARY (16) NOT NULL', // used to reduce size of index on identifier
				'KEY source (source, identifiermd5)',
				'KEY userid (userid)',
			),
			
			'userlevels' => array(
				'userid' => $useridcoltype.' NOT NULL', // the user who has this level
				'entitytype' => "CHAR(1) CHARACTER SET ascii NOT NULL", // see as-app-updates.php
				'entityid' => 'INT UNSIGNED NOT NULL', // relevant postid / userid / tag wordid / categoryid
				'level' => 'TINYINT UNSIGNED', // if not NULL, special permission level for that user and that entity
				'UNIQUE userid (userid, entitytype, entityid)',
				'KEY entitytype (entitytype, entityid)',
			),
			
			'userprofile' => array(
				'userid' => $useridcoltype.' NOT NULL',
				'title' => 'VARCHAR('.AS_DB_MAX_PROFILE_TITLE_LENGTH.') NOT NULL', // profile field name
				'content' => 'VARCHAR('.AS_DB_MAX_PROFILE_CONTENT_LENGTH.') NOT NULL', // profile field value
				'UNIQUE userid (userid,title)',
			),
			
			'userfields' => array(
				'fieldid' => 'SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT',
				'title' => 'VARCHAR('.AS_DB_MAX_PROFILE_TITLE_LENGTH.') NOT NULL', // to match title column in userprofile table
				'content' => 'VARCHAR('.AS_DB_MAX_PROFILE_TITLE_LENGTH.')', // label for display on user profile pages - NULL means use default
				'position' => 'SMALLINT UNSIGNED NOT NULL',
				'flags' => 'TINYINT UNSIGNED NOT NULL', // AS_FIELD_FLAGS_* at top of as-app-users.php
				'permit' => 'TINYINT UNSIGNED', // minimum user level required to view (uses AS_PERMIT_* constants), null means no restriction
				'PRIMARY KEY (fieldid)',
			),
			
			'messages' => array(
				'messageid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
				'type' => "ENUM('PUBLIC', 'PRIVATE') NOT NULL DEFAULT 'PRIVATE'",
				'fromuserid' => $useridcoltype.' NOT NULL',
				'touserid' => $useridcoltype.' NOT NULL',
				'content' => 'VARCHAR('.AS_DB_MAX_CONTENT_LENGTH.') NOT NULL',
				'format' => 'VARCHAR('.AS_DB_MAX_FORMAT_LENGTH.') CHARACTER SET ascii NOT NULL',
				'created' => 'DATETIME NOT NULL',
				'PRIMARY KEY (messageid)',
				'KEY type (type, fromuserid, touserid, created)',
				'KEY touserid (touserid, type, created)',
			),
			
			'userfavorites' => array(
				'userid' => $useridcoltype.' NOT NULL', // the user who favorited the entity
				'entitytype' => "CHAR(1) CHARACTER SET ascii NOT NULL", // see as-app-updates.php
				'entityid' => 'INT UNSIGNED NOT NULL', // favorited postid / userid / tag wordid / categoryid
				'nouserevents' => 'TINYINT UNSIGNED NOT NULL', // do we skip writing events to the user stream?
				'PRIMARY KEY (userid, entitytype, entityid)',
				'KEY userid (userid, nouserevents)',
				'KEY entitytype (entitytype, entityid, nouserevents)',
			),
			
			'usernotices' => array(
				'noticeid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
				'userid' => $useridcoltype.' NOT NULL', // the user to whom the notice is directed
				'content' => 'VARCHAR('.AS_DB_MAX_CONTENT_LENGTH.') NOT NULL',
				'format' => 'VARCHAR('.AS_DB_MAX_FORMAT_LENGTH.') CHARACTER SET ascii NOT NULL',
				'tags' => 'VARCHAR('.AS_DB_MAX_CAT_PAGE_TAGS_LENGTH.')', // any additional information for a plugin to access
				'created' => 'DATETIME NOT NULL',
				'PRIMARY KEY (noticeid)',
				'KEY userid (userid, created)',
			),
			
			'userevents' => array(
				'userid' => $useridcoltype.' NOT NULL', // the user to be informed about this event in their updates
				'entitytype' => "CHAR(1) CHARACTER SET ascii NOT NULL", // see as-app-updates.php
				'entityid' => 'INT UNSIGNED NOT NULL', // favorited source of event - see userfavorites table - 0 means not from a favorite
				'questionid' => 'INT UNSIGNED NOT NULL', // the affected question
				'lastpostid' => 'INT UNSIGNED NOT NULL', // what part of question was affected
				'updatetype' => 'CHAR(1) CHARACTER SET ascii', // what was done to this part - see as-app-updates.php
				'lastuserid' => $useridcoltype, // which user (if any) did this action
				'updated' => 'DATETIME NOT NULL', // when the event happened
				'KEY userid (userid, updated)', // for truncation
				'KEY questionid (questionid, userid)', // to limit number of events per question per stream
			),
			
			'sharedevents' => array(
				'entitytype' => "CHAR(1) CHARACTER SET ascii NOT NULL", // see as-app-updates.php
				'entityid' => 'INT UNSIGNED NOT NULL', // see userfavorites table
				'questionid' => 'INT UNSIGNED NOT NULL', // see userevents table
				'lastpostid' => 'INT UNSIGNED NOT NULL', // see userevents table
				'updatetype' => 'CHAR(1) CHARACTER SET ascii', // see userevents table
				'lastuserid' => $useridcoltype, // see userevents table
				'updated' => 'DATETIME NOT NULL', // see userevents table
				'KEY entitytype (entitytype, entityid, updated)', // for truncation
				'KEY questionid (questionid, entitytype, entityid)', // to limit number of events per question per stream
			),

			'cookies' => array(
				'cookieid' => 'BIGINT UNSIGNED NOT NULL',
				'created' => 'DATETIME NOT NULL',
				'createip' => 'INT UNSIGNED NOT NULL', // INET_ATON of IP address when cookie created
				'written' => 'DATETIME', // time of last write action done by anon user with cookie
				'writeip' => 'INT UNSIGNED', // INET_ATON of IP address of last write action done by anon user with cookie
				'PRIMARY KEY (cookieid)',
			),
			
			'categories' => array(
				'categoryid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
				'parentid' => 'INT UNSIGNED',
				'title' => 'VARCHAR('.AS_DB_MAX_CAT_PAGE_TITLE_LENGTH.') NOT NULL', // category name
				'tags' => 'VARCHAR('.AS_DB_MAX_CAT_PAGE_TAGS_LENGTH.') NOT NULL', // slug (url fragment) used to identify category
				'content' => 'VARCHAR('.AS_DB_MAX_CAT_CONTENT_LENGTH.') NOT NULL DEFAULT \'\'', // description of category
				'qcount' => 'INT UNSIGNED NOT NULL DEFAULT 0',
				'position' => 'SMALLINT UNSIGNED NOT NULL',
				'backpath' => 'VARCHAR('.(AS_CATEGORY_DEPTH*(AS_DB_MAX_CAT_PAGE_TAGS_LENGTH+1)).') NOT NULL DEFAULT \'\'',
					// full slug path for category, with forward slash separators, in reverse order to make index from effective
				'PRIMARY KEY (categoryid)',
				'UNIQUE parentid (parentid, tags)',
				'UNIQUE parentid_2 (parentid, position)',
				'KEY backpath (backpath('.AS_DB_MAX_CAT_PAGE_TAGS_LENGTH.'))',
			),
			
			'pages' => array(
				'pageid' => 'SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT',
				'title' => 'VARCHAR('.AS_DB_MAX_CAT_PAGE_TITLE_LENGTH.') NOT NULL', // title for navigation
				'nav' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // which navigation does it go in (M=main, F=footer, B=before main, O=opposite main, other=none)
				'position' => 'SMALLINT UNSIGNED NOT NULL', // global ordering, which allows links to be ordered within each nav area
				'flags' => 'TINYINT UNSIGNED NOT NULL', // local or external, open in new window?
				'permit' => 'TINYINT UNSIGNED', // is there a minimum user level required for it (uses AS_PERMIT_* constants), null means no restriction
				'tags' => 'VARCHAR('.AS_DB_MAX_CAT_PAGE_TAGS_LENGTH.') NOT NULL', // slug (url fragment) for page, or url for external pages
				'heading' => 'VARCHAR('.AS_DB_MAX_TITLE_LENGTH.')', // for display within <h1> tags
				'content' => 'MEDIUMTEXT', // remainder of page HTML
				'PRIMARY KEY (pageid)',
				'KEY tags (tags)',
				'UNIQUE position (position)',
			),
			
			'widgets' => array(
				'widgetid' => 'SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT',
				'place' => 'CHAR(2) CHARACTER SET ascii NOT NULL',
					// full region: FT=very top of page, FH=below nav area, FL=above footer, FB = very bottom of page
					// side region: ST=top of side, SH=below sidebar, SL=below categories, SB=very bottom of side
					// main region: MT=top of main, MH=below page title, ML=above links, MB=very bottom of main region
				'position' => 'SMALLINT UNSIGNED NOT NULL', // global ordering, which allows widgets to be ordered within each place
				'tags' => 'VARCHAR('.AS_DB_MAX_WIDGET_TAGS_LENGTH.') CHARACTER SET ascii NOT NULL', // comma-separated list of templates to display on
				'title' => 'VARCHAR('.AS_DB_MAX_WIDGET_TITLE_LENGTH.') NOT NULL', // name of widget module that should be displayed
				'PRIMARY KEY (widgetid)',
				'UNIQUE position (position)',
			),
			
			'posts' => array(
				'postid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
				'type' => "ENUM('Q', 'A', 'C', 'Q_HIDDEN', 'A_HIDDEN', 'C_HIDDEN', 'Q_QUEUED', 'A_QUEUED', 'C_QUEUED', 'NOTE') NOT NULL",
				'parentid' => 'INT UNSIGNED', // for follow on questions, all answers and comments
				'categoryid' => 'INT UNSIGNED', // this is the canonical final category id
				'catidpath1' => 'INT UNSIGNED', // the catidpath* columns are calculated from categoryid, for the full hierarchy of that category
				'catidpath2' => 'INT UNSIGNED', // note that AS_CATEGORY_DEPTH=4
				'catidpath3' => 'INT UNSIGNED',
				'acount' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', // number of answers (for questions)
				'amaxvote' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0', // highest netvotes of child answers (for questions)
				'selchildid' => 'INT UNSIGNED', // selected answer (for questions)
				'closedbyid' => 'INT UNSIGNED', // not null means question is closed
					// if closed due to being a duplicate, this is the postid of that other question
					// if closed for another reason, that reason should be added as a comment on the question, and this field is the comment's id
				'userid' => $useridcoltype, // which user wrote it
				'cookieid' => 'BIGINT UNSIGNED', // which cookie wrote it, if an anonymous post
				'createip' => 'INT UNSIGNED', // INET_ATON of IP address used to create the post
				'lastuserid' => $useridcoltype, // which user last modified it
				'lastip' => 'INT UNSIGNED', // INET_ATON of IP address which last modified the post
				'upvotes' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
				'downvotes' => 'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
				'netvotes' => 'SMALLINT NOT NULL DEFAULT 0',
				'lastviewip' => 'INT UNSIGNED', // INET_ATON of IP address which last viewed the post
				'views' => 'INT UNSIGNED NOT NULL DEFAULT 0',
				'hotness' => 'FLOAT',
				'flagcount' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
				'format' => 'VARCHAR('.AS_DB_MAX_FORMAT_LENGTH.') CHARACTER SET ascii NOT NULL DEFAULT \'\'', // format of content, e.g. 'html'
				'created' => 'DATETIME NOT NULL',
				'updated' => 'DATETIME', // time of last update
				'updatetype' => 'CHAR(1) CHARACTER SET ascii', // see as-app-updates.php
				'title' => 'VARCHAR('.AS_DB_MAX_TITLE_LENGTH.')',
				'content' => 'VARCHAR('.AS_DB_MAX_CONTENT_LENGTH.')',
				'tags' => 'VARCHAR('.AS_DB_MAX_TAGS_LENGTH.')', // string of tags separated by commas
				'name' => 'VARCHAR('.AS_DB_MAX_NAME_LENGTH.')', // name of author if post anonymonus
				'notify' => 'VARCHAR('.AS_DB_MAX_EMAIL_LENGTH.')', // email address, or @ to get from user, or NULL for none
				'PRIMARY KEY (postid)',
				'KEY type (type, created)', // for getting recent questions, answers, comments
				'KEY type_2 (type, acount, created)', // for getting unanswered questions
				'KEY type_4 (type, netvotes, created)', // for getting posts with the most votes
				'KEY type_5 (type, views, created)', // for getting questions with the most views
				'KEY type_6 (type, hotness)', // for getting 'hot' questions
				'KEY type_7 (type, amaxvote, created)', // for getting questions with no upvoted answers
				'KEY parentid (parentid, type)', // for getting a question's answers, any post's comments and follow-on questions
				'KEY userid (userid, type, created)', // for recent questions, answers or comments by a user
				'KEY selchildid (selchildid, type, created)', // for counting how many of a user's answers have been selected, unselected qs
				'KEY closedbyid (closedbyid)', // for the foreign key constraint
				'KEY catidpath1 (catidpath1, type, created)', // for getting question, answers or comments in a specific level category
				'KEY catidpath2 (catidpath2, type, created)', // note that AS_CATEGORY_DEPTH=4
				'KEY catidpath3 (catidpath3, type, created)',
				'KEY categoryid (categoryid, type, created)', // this can also be used for searching the equivalent of catidpath4
				'KEY createip (createip, created)', // for getting posts created by a specific IP address
				'KEY updated (updated, type)', // for getting recent edits across all categories
				'KEY flagcount (flagcount, created, type)', // for getting posts with the most flags
				'KEY catidpath1_2 (catidpath1, updated, type)', // for getting recent edits in a specific level category
				'KEY catidpath2_2 (catidpath2, updated, type)', // note that AS_CATEGORY_DEPTH=4
				'KEY catidpath3_2 (catidpath3, updated, type)',
				'KEY categoryid_2 (categoryid, updated, type)',
				'KEY lastuserid (lastuserid, updated, type)', // for getting posts edited by a specific user
				'KEY lastip (lastip, updated, type)', // for getting posts edited by a specific IP address
				'CONSTRAINT ^posts_ibfk_2 FOREIGN KEY (parentid) REFERENCES ^posts(postid)', // ^posts_ibfk_1 is set later on userid
				'CONSTRAINT ^posts_ibfk_3 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE SET NULL',
				'CONSTRAINT ^posts_ibfk_4 FOREIGN KEY (closedbyid) REFERENCES ^posts(postid)',
			),
			
			'blobs' => array(
				'blobid' => 'BIGINT UNSIGNED NOT NULL',
				'format' => 'VARCHAR('.AS_DB_MAX_FORMAT_LENGTH.') CHARACTER SET ascii NOT NULL', // format e.g. 'jpeg', 'gif', 'png'
				'content' => 'MEDIUMBLOB', // null means it's stored on disk in AS_BLOBS_DIRECTORY
				'filename' => 'VARCHAR('.AS_DB_MAX_BLOB_FILE_NAME_LENGTH.')', // name of source file (if appropriate)
				'userid' => $useridcoltype, // which user created it
				'cookieid' => 'BIGINT UNSIGNED', // which cookie created it
				'createip' => 'INT UNSIGNED', // INET_ATON of IP address that created it
				'created' => 'DATETIME', // when it was created
				'PRIMARY KEY (blobid)',
			),
			
			'words' => array(
				'wordid' => 'INT UNSIGNED NOT NULL AUTO_INCREMENT',
				'word' => 'VARCHAR('.AS_DB_MAX_WORD_LENGTH.') NOT NULL',
				'titlecount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // only counts one per post
				'contentcount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // only counts one per post
				'tagwordcount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // for words in tags - only counts one per post
				'tagcount' => 'INT UNSIGNED NOT NULL DEFAULT 0', // for tags as a whole - only counts one per post (though no duplicate tags anyway)
				'PRIMARY KEY (wordid)',
				'KEY word (word)',
				'KEY tagcount (tagcount)', // for sorting by most popular tags
			),
			
			'titlewords' => array(
				'postid' => 'INT UNSIGNED NOT NULL',
				'wordid' => 'INT UNSIGNED NOT NULL',
				'KEY postid (postid)',
				'KEY wordid (wordid)',
				'CONSTRAINT ^titlewords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
				'CONSTRAINT ^titlewords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
			),
				
			'contentwords' => array(
				'postid' => 'INT UNSIGNED NOT NULL',
				'wordid' => 'INT UNSIGNED NOT NULL',
				'count' => 'TINYINT UNSIGNED NOT NULL', // how many times word appears in the post - anything over 255 can be ignored
				'type' => "ENUM('Q', 'A', 'C', 'NOTE') NOT NULL", // the post's type (copied here for quick searching)
				'questionid' => 'INT UNSIGNED NOT NULL', // the id of the post's antecedent parent (here for quick searching)
				'KEY postid (postid)',
				'KEY wordid (wordid)',
				'CONSTRAINT ^contentwords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
				'CONSTRAINT ^contentwords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
			),
			
			'tagwords' => array(
				'postid' => 'INT UNSIGNED NOT NULL',
				'wordid' => 'INT UNSIGNED NOT NULL',
				'KEY postid (postid)',
				'KEY wordid (wordid)',
				'CONSTRAINT ^tagwords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
				'CONSTRAINT ^tagwords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
			),
				
			'posttags' => array(
				'postid' => 'INT UNSIGNED NOT NULL',
				'wordid' => 'INT UNSIGNED NOT NULL',
				'postcreated' => 'DATETIME NOT NULL', // created time of post (copied here for tag page's list of recent questions)
				'KEY postid (postid)',
				'KEY wordid (wordid,postcreated)',
				'CONSTRAINT ^posttags_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
				'CONSTRAINT ^posttags_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
			),
			
			'uservotes' => array(
				'postid' => 'INT UNSIGNED NOT NULL',
				'userid' => $useridcoltype.' NOT NULL',
				'vote' => 'TINYINT NOT NULL', // -1, 0 or 1
				'flag' => 'TINYINT NOT NULL', // 0 or 1
				'UNIQUE userid (userid, postid)',
				'KEY postid (postid)',
				'CONSTRAINT ^uservotes_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
			),
			
			// many userpoints columns could be unsigned but MySQL appears to mess up points calculations that go negative as a result
			
			'userpoints' => array(
				'userid' => $useridcoltype.' NOT NULL',
				'points' => 'INT NOT NULL DEFAULT 0', // user's points as displayed, after final multiple
				'qposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions by user (excluding hidden/queued)
				'aposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers by user (excluding hidden/queued)
				'cposts' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of comments by user (excluding hidden/queued)
				'aselects' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions by user where they've selected an answer
				'aselecteds' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers by user that have been selected as the best
				'qupvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions the user has voted up
				'qdownvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of questions the user has voted down
				'aupvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers the user has voted up
				'adownvotes' => 'MEDIUMINT NOT NULL DEFAULT 0', // number of answers the user has voted down
				'qvoteds' => 'INT NOT NULL DEFAULT 0', // points from votes on this user's questions (applying per-question limits), before final multiple
				'avoteds' => 'INT NOT NULL DEFAULT 0', // points from votes on this user's answers (applying per-answer limits), before final multiple
				'upvoteds' => 'INT NOT NULL DEFAULT 0', // number of up votes received on this user's questions or answers
				'downvoteds' => 'INT NOT NULL DEFAULT 0', // number of down votes received on this user's questions or answers
				'bonus' => 'INT NOT NULL DEFAULT 0', // bonus assigned by administrator to a user
				'PRIMARY KEY (userid)',
				'KEY points (points)',
			),
				
			'userlimits' => array(
				'userid' => $useridcoltype.' NOT NULL',
				'action' => 'CHAR(1) CHARACTER SET ascii NOT NULL', // see constants at top of as-app-limits.php
				'period' => 'INT UNSIGNED NOT NULL', // integer representing hour of last action
				'count' => 'SMALLINT UNSIGNED NOT NULL', // how many of this action has been performed within that hour
				'UNIQUE userid (userid, action)',
			),
			
			// most columns in iplimits have the same meaning as those in userlimits
			
			'iplimits' => array(
				'ip' => 'INT UNSIGNED NOT NULL', // INET_ATON of IP address
				'action' => 'CHAR(1) CHARACTER SET ascii NOT NULL',
				'period' => 'INT UNSIGNED NOT NULL',
				'count' => 'SMALLINT UNSIGNED NOT NULL',
				'UNIQUE ip (ip, action)',
			),
				
			'options' => array(
				'title' => 'VARCHAR('.AS_DB_MAX_OPTION_TITLE_LENGTH.') NOT NULL', // name of option
				'content' => 'VARCHAR('.AS_DB_MAX_CONTENT_LENGTH.') NOT NULL', // value of option
				'PRIMARY KEY (title)',
			),
			
			'cache' => array(
				'type' => 'CHAR(8) CHARACTER SET ascii NOT NULL', // e.g. 'avXXX' for avatar sized to XXX pixels square
				'cacheid' => 'BIGINT UNSIGNED DEFAULT 0', // optional further identifier, e.g. blobid on which cache entry is based
				'content' => 'MEDIUMBLOB NOT NULL',
				'created' => 'DATETIME NOT NULL',
				'lastread' => 'DATETIME NOT NULL',
				'PRIMARY KEY (type,cacheid)',
				'KEY (lastread)',
			),

			'usermetas' => array(
				'userid' => $useridcoltype.' NOT NULL',
				'title' => 'VARCHAR('.AS_DB_MAX_META_TITLE_LENGTH.') NOT NULL',
				'content' => 'VARCHAR('.AS_DB_MAX_META_CONTENT_LENGTH.') NOT NULL',
				'PRIMARY KEY (userid, title)',
			),
			
			'postmetas' => array(
				'postid' => 'INT UNSIGNED NOT NULL',
				'title' => 'VARCHAR('.AS_DB_MAX_META_TITLE_LENGTH.') NOT NULL',
				'content' => 'VARCHAR('.AS_DB_MAX_META_CONTENT_LENGTH.') NOT NULL',
				'PRIMARY KEY (postid, title)',
				'CONSTRAINT ^postmetas_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
			),
			
			'categorymetas' => array(
				'categoryid' => 'INT UNSIGNED NOT NULL',
				'title' => 'VARCHAR('.AS_DB_MAX_META_TITLE_LENGTH.') NOT NULL',
				'content' => 'VARCHAR('.AS_DB_MAX_META_CONTENT_LENGTH.') NOT NULL',
				'PRIMARY KEY (categoryid, title)',
				'CONSTRAINT ^categorymetas_ibfk_1 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE CASCADE',
			),
			
			'tagmetas' => array(
				'tag' => 'VARCHAR('.AS_DB_MAX_WORD_LENGTH.') NOT NULL',
				'title' => 'VARCHAR('.AS_DB_MAX_META_TITLE_LENGTH.') NOT NULL',
				'content' => 'VARCHAR('.AS_DB_MAX_META_CONTENT_LENGTH.') NOT NULL',
				'PRIMARY KEY (tag, title)',
			),
			
		);
		
		if (AS_FINAL_EXTERNAL_USERS) {
			unset($tables['users']);
			unset($tables['userlogins']);
			unset($tables['userprofile']);
			unset($tables['userfields']);
			unset($tables['messages']);

		} else {
			$userforeignkey='FOREIGN KEY (userid) REFERENCES ^users(userid)';
			
			$tables['userlogins'][]='CONSTRAINT ^userlogins_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['userprofile'][]='CONSTRAINT ^userprofile_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['posts'][]='CONSTRAINT ^posts_ibfk_1 '.$userforeignkey.' ON DELETE SET NULL';
			$tables['uservotes'][]='CONSTRAINT ^uservotes_ibfk_2 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['userlimits'][]='CONSTRAINT ^userlimits_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['userfavorites'][]='CONSTRAINT ^userfavorites_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['usernotices'][]='CONSTRAINT ^usernotices_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['userevents'][]='CONSTRAINT ^userevents_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['userlevels'][]='CONSTRAINT ^userlevels_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
			$tables['usermetas'][]='CONSTRAINT ^usermetas_ibfk_1 '.$userforeignkey.' ON DELETE CASCADE';
		}

		return $tables;
	}

	
	function as_array_to_lower_keys($array)
/*
	Return $array with all keys converted to lower case
*/
	{
		$keyarray=array();

		foreach ($array as $value)
			$keyarray[strtolower($value)]=true;
			
		return $keyarray;
	}

	
	function as_db_missing_tables($definitions)
/*
	Return a list of tables missing from the database, [table name] => [column/index definitions]
*/
	{
		$keydbtables=as_array_to_lower_keys(as_db_list_tables());
		
		$missing=array();
		
		foreach ($definitions as $rawname => $definition)
			if (!isset($keydbtables[strtolower(as_db_add_table_prefix($rawname))]))
				$missing[$rawname]=$definition;
		
		return $missing;
	}

	
	function as_db_missing_columns($table, $definition)
/*
	Return a list of columns missing from $table in the database, given the full definition set in $definition
*/
	{
		$keycolumns=as_array_to_lower_keys(as_db_read_all_values(as_db_query_sub('SHOW COLUMNS FROM ^'.$table)));
		
		$missing=array();
		
		foreach ($definition as $colname => $coldefn)
			if ( (!is_int($colname)) && !isset($keycolumns[strtolower($colname)]) )
				$missing[$colname]=$coldefn;
				
		return $missing;
	}

	
	function as_db_get_db_version()
/*
	Return the current version of the Q2A database, to determine need for DB upgrades
*/
	{
		$definitions=as_db_table_definitions();
		
		if (count(as_db_missing_columns('options', $definitions['options']))==0) {
			$version=(int)as_db_read_one_value(as_db_query_sub("SELECT content FROM ^options WHERE title='db_version'"), true);
			
			if ($version>0)
				return $version;
		}
			
		return null;
	}

	
	function as_db_set_db_version($version)
/*
	Set the current version in the database
*/
	{
		as_db_query_sub("REPLACE ^options (title,content) VALUES ('db_version', #)", $version);
	}

	
	function as_db_check_tables()
/*
	Return a string describing what is wrong with the database, or false if everything is just fine
*/
	{
		as_db_query_raw('UNLOCK TABLES'); // we could be inside a lock tables block
		
		$version=as_db_read_one_value(as_db_query_raw('SELECT VERSION()'));
		
		if (((float)$version)<4.1)
			as_fatal_error('MySQL version 4.1 or later is required - you appear to be running MySQL '.$version);
		
		$definitions=as_db_table_definitions();
		$missing=as_db_missing_tables($definitions);
		
		if (count($missing) == count($definitions))
			return 'none';
		
		else {
			if (!isset($missing['options'])) {
				$version=as_db_get_db_version();
				
				if (isset($version) && ($version<AS_DB_VERSION_CURRENT))
					return 'old-version';
			}
		
			if (count($missing)) {
				if (defined('AS_MYSQL_USERS_PREFIX')) { // special case if two installations sharing users
					$datacount=0;
					$datamissing=0;
					
					foreach ($definitions as $rawname => $definition)
						if (as_db_add_table_prefix($rawname)==(AS_MYSQL_TABLE_PREFIX.$rawname)) {
							$datacount++;
							
							if (isset($missing[$rawname]))
								$datamissing++;
						}
						
					if ( ($datacount==$datamissing) && ($datamissing==count($missing)) )
						return 'non-users-missing';
				}
							
				return 'table-missing';
				
			} else
				foreach ($definitions as $table => $definition)
					if (count(as_db_missing_columns($table, $definition)))
						return 'column-missing';
		}
				
		return false;
	}

	
	function as_db_install_tables()
/*
	Install any missing database tables and/or columns and automatically set version as latest.
	This is not suitable for use if the database needs upgrading.
*/
	{
		$definitions=as_db_table_definitions();
		
		$missingtables=as_db_missing_tables($definitions);
		
		foreach ($missingtables as $rawname => $definition) {
			as_db_query_sub(as_db_create_table_sql($rawname, $definition));

			if ($rawname=='userfields')
				as_db_query_sub(as_db_default_userfields_sql());
		}
		
		foreach ($definitions as $table => $definition) {
			$missingcolumns=as_db_missing_columns($table, $definition);
			
			foreach ($missingcolumns as $colname => $coldefn)
				as_db_query_sub('ALTER TABLE ^'.$table.' ADD COLUMN '.$colname.' '.$coldefn);
		}
		
		as_db_set_db_version(AS_DB_VERSION_CURRENT);
	}

	
	function as_db_create_table_sql($rawname, $definition)
/*
	Return the SQL command to create a table with $rawname and $definition obtained from as_db_table_definitions()
*/
	{
		$querycols='';
		foreach ($definition as $colname => $coldef)
			if (isset($coldef))
				$querycols.=(strlen($querycols) ? ', ' : '').(is_int($colname) ? $coldef : ($colname.' '.$coldef));
			
		return 'CREATE TABLE ^'.$rawname.' ('.$querycols.') ENGINE=InnoDB CHARSET=utf8';
	}
	
	
	function as_db_default_userfields_sql()
/*
	Return the SQL to create the default entries in the userfields table (before 1.3 these were hard-coded in PHP)
*/
	{
		$oldprofileflags=array(
			'name' => 0,
			'location' => 0,
			'website' => AS_FIELD_FLAGS_LINK_URL,
			'about' => AS_FIELD_FLAGS_MULTI_LINE,
		);
		
		$sql='INSERT INTO ^userfields (title, position, flags) VALUES '; // content column will be NULL, meaning use default from lang files
		
		$index=0;
		foreach ($oldprofileflags as $title => $flags)
			$sql.=($index ? ', ' : '')."('".as_db_escape_string($title)."', ".(++$index).", ".(int)@$oldprofileflags[$title].")";
				
		return $sql;
	}
	
	
	function as_db_upgrade_tables()
/*
	Upgrade the database schema to the latest version, outputting progress to the browser
*/
	{
		require_once AS_INCLUDE_DIR.'as-app-recalc.php';
		
		$definitions=as_db_table_definitions();
		$keyrecalc=array();
		
	//	Write-lock all Q2A tables before we start so no one can read or write anything

		$keydbtables=as_array_to_lower_keys(as_db_list_tables());

		foreach ($definitions as $rawname => $definition)
			if (isset($keydbtables[strtolower(as_db_add_table_prefix($rawname))]))
				$locks[]='^'.$rawname.' WRITE';
				
		$locktablesquery='LOCK TABLES '.implode(', ', $locks);
			
		as_db_upgrade_query($locktablesquery);
		
	//	Upgrade it step-by-step until it's up to date (do LOCK TABLES after ALTER TABLE because the lock can sometimes be lost)

		while (1) {
			$version=as_db_get_db_version();
			
			if ($version>=AS_DB_VERSION_CURRENT)
				break;
			
			$newversion=$version+1;
			
			as_db_upgrade_progress(AS_DB_VERSION_CURRENT-$version.' upgrade step/s remaining...');
			
			switch ($newversion) {
			
			//	Up to here: Version 1.0 beta 1
				
				case 2:
					as_db_upgrade_query('ALTER TABLE ^posts DROP COLUMN votes, ADD COLUMN upvotes '.$definitions['posts']['upvotes'].
						' AFTER cookieid, ADD COLUMN downvotes '.$definitions['posts']['downvotes'].' AFTER upvotes');
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['dorecountposts']=true;
					break;
					
				case 3:
					as_db_upgrade_query('ALTER TABLE ^userpoints ADD COLUMN upvoteds '.$definitions['userpoints']['upvoteds'].
						' AFTER avoteds, ADD COLUMN downvoteds '.$definitions['userpoints']['downvoteds'].' AFTER upvoteds');
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['dorecalcpoints']=true;
					break;
					
				case 4:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN lastuserid '.$definitions['posts']['lastuserid'].' AFTER cookieid, CHANGE COLUMN updated updated '.$definitions['posts']['updated']);
					as_db_upgrade_query($locktablesquery);
					as_db_upgrade_query('UPDATE ^posts SET updated=NULL WHERE updated=0 OR updated=created');
					break;
					
				case 5:
					as_db_upgrade_query('ALTER TABLE ^contentwords ADD COLUMN type '.$definitions['contentwords']['type'].' AFTER count, ADD COLUMN questionid '.$definitions['contentwords']['questionid'].' AFTER type');
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['doreindexcontent']=true;
					break;
					
			//	Up to here: Version 1.0 beta 2
				
				case 6:
					as_db_upgrade_query('ALTER TABLE ^userpoints ADD COLUMN cposts '.$definitions['userpoints']['cposts'].' AFTER aposts');
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['dorecalcpoints']=true;
					break;
					
				case 7:
					if (!AS_FINAL_EXTERNAL_USERS) {
						as_db_upgrade_query('ALTER TABLE ^users ADD COLUMN sessioncode '.$definitions['users']['sessioncode'].' AFTER writeip');
						as_db_upgrade_query($locktablesquery);
					}
					break;
					
				case 8:
					as_db_upgrade_query('ALTER TABLE ^posts ADD KEY (type, acount, created)');
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['dorecountposts']=true; // for unanswered question count
					break;

			//	Up to here: Version 1.0 beta 3, 1.0, 1.0.1 beta, 1.0.1
			
				case 9:
					if (!AS_FINAL_EXTERNAL_USERS) {
						as_db_upgrade_query('ALTER TABLE ^users CHANGE COLUMN resetcode emailcode '.$definitions['users']['emailcode'].', ADD COLUMN flags '.$definitions['users']['flags'].' AFTER sessioncode');
						as_db_upgrade_query($locktablesquery);
						as_db_upgrade_query('UPDATE ^users SET flags=1');
					}
					break;
				
				case 10:
					as_db_upgrade_query('UNLOCK TABLES');
					as_db_upgrade_query(as_db_create_table_sql('categories', array(
						'categoryid' => $definitions['categories']['categoryid'],
						'title' => $definitions['categories']['title'],
						'tags' => $definitions['categories']['tags'],
						'qcount' => $definitions['categories']['qcount'],
						'position' => $definitions['categories']['position'],
						'PRIMARY KEY (categoryid)',
						'UNIQUE tags (tags)',
						'UNIQUE position (position)',
					))); // hard-code list of columns and indexes to ensure we ignore any added at a later stage

					$locktablesquery.=', ^categories WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
				
				case 11:
					as_db_upgrade_query('ALTER TABLE ^posts ADD CONSTRAINT ^posts_ibfk_2 FOREIGN KEY (parentid) REFERENCES ^posts(postid), ADD COLUMN categoryid '.$definitions['posts']['categoryid'].' AFTER parentid, ADD KEY categoryid (categoryid, type, created), ADD CONSTRAINT ^posts_ibfk_3 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE SET NULL');
						// foreign key on parentid important now that deletion is possible
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 12:
					as_db_upgrade_query('UNLOCK TABLES');
					as_db_upgrade_query(as_db_create_table_sql('pages', array(
						'pageid' => $definitions['pages']['pageid'],
						'title' => $definitions['pages']['title'],
						'nav' => $definitions['pages']['nav'],
						'position' => $definitions['pages']['position'],
						'flags' => $definitions['pages']['flags'],
						'tags' => $definitions['pages']['tags'],
						'heading' => $definitions['pages']['heading'],
						'content' => $definitions['pages']['content'],
						'PRIMARY KEY (pageid)',
						'UNIQUE tags (tags)',
						'UNIQUE position (position)',
					))); // hard-code list of columns and indexes to ensure we ignore any added at a later stage
					$locktablesquery.=', ^pages WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 13:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN createip '.$definitions['posts']['createip'].' AFTER cookieid, ADD KEY createip (createip, created)');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 14:
					as_db_upgrade_query('ALTER TABLE ^userpoints DROP COLUMN qvotes, DROP COLUMN avotes, ADD COLUMN qupvotes '.$definitions['userpoints']['qupvotes'].' AFTER aselecteds, ADD COLUMN qdownvotes '.$definitions['userpoints']['qdownvotes'].' AFTER qupvotes, ADD COLUMN aupvotes '.$definitions['userpoints']['aupvotes'].' AFTER qdownvotes, ADD COLUMN adownvotes '.$definitions['userpoints']['adownvotes'].' AFTER aupvotes');
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['dorecalcpoints']=true;
					break;
					
			//	Up to here: Version 1.2 beta 1
			
				case 15:
					if (!AS_FINAL_EXTERNAL_USERS)
						as_db_upgrade_table_columns($definitions, 'users', array('emailcode', 'sessioncode', 'flags'));
					
					as_db_upgrade_table_columns($definitions, 'posts', array('acount', 'upvotes', 'downvotes', 'format'));
					as_db_upgrade_table_columns($definitions, 'categories', array('qcount'));
					as_db_upgrade_table_columns($definitions, 'words', array('titlecount', 'contentcount', 'tagcount'));
					as_db_upgrade_table_columns($definitions, 'userpoints', array('points', 'qposts', 'aposts', 'cposts',
						'aselects', 'aselecteds', 'qupvotes', 'qdownvotes', 'aupvotes', 'adownvotes', 'qvoteds', 'avoteds', 'upvoteds', 'downvoteds'));
					as_db_upgrade_query($locktablesquery);
					break;

			//	Up to here: Version 1.2 (release)
			
				case 16:
					as_db_upgrade_table_columns($definitions, 'posts', array('format'));
					as_db_upgrade_query($locktablesquery);
					$keyrecalc['doreindexcontent']=true; // because of new treatment of apostrophes in words
					break;
					
				case 17:
					as_db_upgrade_query('ALTER TABLE ^posts ADD KEY updated (updated, type), ADD KEY categoryid_2 (categoryid, updated, type)');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 18:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN lastip '.$definitions['posts']['lastip'].' AFTER lastuserid, ADD KEY lastip (lastip, updated, type)');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 19:
					if (!AS_FINAL_EXTERNAL_USERS)
						as_db_upgrade_query('ALTER TABLE ^users ADD COLUMN avatarblobid '.$definitions['users']['avatarblobid'].' AFTER handle, ADD COLUMN avatarwidth '.$definitions['users']['avatarwidth'].' AFTER avatarblobid, ADD COLUMN avatarheight '.$definitions['users']['avatarheight'].' AFTER avatarwidth');
					
					// hard-code list of columns and indexes to ensure we ignore any added at a later stage

					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('blobs', array(
						'blobid' => $definitions['blobs']['blobid'],
						'format' => $definitions['blobs']['format'],
						'content' => $definitions['blobs']['content'],
						'PRIMARY KEY (blobid)'
					)));

					as_db_upgrade_query(as_db_create_table_sql('cache', array(
						'type' => $definitions['cache']['type'],
						'cacheid' => $definitions['cache']['cacheid'],
						'content' => $definitions['cache']['content'],
						'created' => $definitions['cache']['created'],
						'lastread' => $definitions['cache']['lastread'],
						'PRIMARY KEY (type,cacheid)',
						'KEY (lastread)',
					))); // hard-code list of columns and indexes to ensure we ignore any added at a later stage

					$locktablesquery.=', ^blobs WRITE, ^cache WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 20:
					if (!AS_FINAL_EXTERNAL_USERS) {
						as_db_upgrade_query('UNLOCK TABLES');
						
						as_db_upgrade_query(as_db_create_table_sql('userlogins', array(
							'userid' => $definitions['userlogins']['userid'],
							'source' => $definitions['userlogins']['source'],
							'identifier' => $definitions['userlogins']['identifier'],
							'identifiermd5' => $definitions['userlogins']['identifiermd5'],
							'KEY source (source, identifiermd5)',
							'KEY userid (userid)',
							'CONSTRAINT ^userlogins_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
						)));
						
						as_db_upgrade_query('ALTER TABLE ^users CHANGE COLUMN passsalt passsalt '.$definitions['users']['passsalt'].', CHANGE COLUMN passcheck passcheck '.$definitions['users']['passcheck']);
						
						$locktablesquery.=', ^userlogins WRITE';
						as_db_upgrade_query($locktablesquery);
					}
					break;
					
				case 21:
					if (!AS_FINAL_EXTERNAL_USERS) {
						as_db_upgrade_query('UNLOCK TABLES');
						
						as_db_upgrade_query(as_db_create_table_sql('userfields', array(
							'fieldid' => $definitions['userfields']['fieldid'],
							'title' => $definitions['userfields']['title'],
							'content' => $definitions['userfields']['content'],
							'position' => $definitions['userfields']['position'],
							'flags' => $definitions['userfields']['flags'],
							'PRIMARY KEY (fieldid)',
						)));
						
						$locktablesquery.=', ^userfields WRITE';
						as_db_upgrade_query($locktablesquery);
						
						as_db_upgrade_query(as_db_default_userfields_sql());
					}
					break;
					
			//	Up to here: Version 1.3 beta 1
			
				case 22:
					if (!AS_FINAL_EXTERNAL_USERS) {
						as_db_upgrade_query('ALTER TABLE ^users ADD COLUMN sessionsource '.$definitions['users']['sessionsource'].' AFTER sessioncode');
						as_db_upgrade_query($locktablesquery);
					}
					break;
			
			//	Up to here: Version 1.3 beta 2 and release
			
				case 23:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('widgets', array(
						'widgetid' => $definitions['widgets']['widgetid'],
						'place' => $definitions['widgets']['place'],
						'position' => $definitions['widgets']['position'],
						'tags' => $definitions['widgets']['tags'],
						'title' => $definitions['widgets']['title'],
						'PRIMARY KEY (widgetid)',
						'UNIQUE position (position)',
					)));
					
					$locktablesquery.=', ^widgets WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 24:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('tagwords', array(
						'postid' => $definitions['tagwords']['postid'],
						'wordid' => $definitions['tagwords']['wordid'],
						'KEY postid (postid)',
						'KEY wordid (wordid)',
						'CONSTRAINT ^tagwords_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
						'CONSTRAINT ^tagwords_ibfk_2 FOREIGN KEY (wordid) REFERENCES ^words(wordid)',
					)));
					
					$locktablesquery.=', ^tagwords WRITE';
					
					as_db_upgrade_query('ALTER TABLE ^words ADD COLUMN tagwordcount '.$definitions['words']['tagwordcount'].' AFTER contentcount');
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['doreindexcontent']=true;
					break;
					
			//	Up to here: Version 1.4 developer preview
			
				case 25:
					$keycolumns=as_array_to_lower_keys(as_db_read_all_values(as_db_query_sub('SHOW COLUMNS FROM ^blobs')));
						// might be using blobs table shared with another installation, so check if we need to upgrade
					
					if (isset($keycolumns['filename']))
						as_db_upgrade_progress('Skipping upgrading blobs table since it was already upgraded by another Q2A site sharing it.');

					else {
						as_db_upgrade_query('ALTER TABLE ^blobs ADD COLUMN filename '.$definitions['blobs']['filename'].' AFTER content, ADD COLUMN userid '.$definitions['blobs']['userid'].' AFTER filename, ADD COLUMN cookieid '.$definitions['blobs']['cookieid'].' AFTER userid, ADD COLUMN createip '.$definitions['blobs']['createip'].' AFTER cookieid, ADD COLUMN created '.$definitions['blobs']['created'].' AFTER createip');
						as_db_upgrade_query($locktablesquery);
					}
					break;
					
				case 26:
					as_db_upgrade_query('ALTER TABLE ^uservotes ADD COLUMN flag '.$definitions['uservotes']['flag'].' AFTER vote');
					as_db_upgrade_query($locktablesquery);
					
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN flagcount '.$definitions['posts']['flagcount'].' AFTER downvotes, ADD KEY type_3 (type, flagcount, created)');
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorecountposts']=true;
					break;
					
				case 27:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN netvotes '.$definitions['posts']['netvotes'].' AFTER downvotes, ADD KEY type_4 (type, netvotes, created)');
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorecountposts']=true;
					break;
					
				case 28:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN views '.$definitions['posts']['views'].' AFTER netvotes, ADD COLUMN hotness '.$definitions['posts']['hotness'].' AFTER views, ADD KEY type_5 (type, views, created), ADD KEY type_6 (type, hotness)');
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorecountposts']=true;
					break;
					
				case 29:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN lastviewip '.$definitions['posts']['lastviewip'].' AFTER netvotes');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 30:
					as_db_upgrade_query('ALTER TABLE ^posts DROP FOREIGN KEY ^posts_ibfk_3'); // to allow category column types to be changed
					as_db_upgrade_query($locktablesquery);
					
					as_db_upgrade_query('ALTER TABLE ^posts DROP KEY categoryid, DROP KEY categoryid_2');
					as_db_upgrade_query($locktablesquery);
					
					as_db_upgrade_query('ALTER TABLE ^categories CHANGE COLUMN categoryid categoryid '.$definitions['categories']['categoryid'].', ADD COLUMN parentid '.$definitions['categories']['parentid'].' AFTER categoryid, ADD COLUMN backpath '.$definitions['categories']['backpath'].' AFTER position, ADD COLUMN content '.$definitions['categories']['content'].' AFTER tags, DROP INDEX tags, DROP INDEX position, ADD UNIQUE parentid (parentid, tags), ADD UNIQUE parentid_2 (parentid, position), ADD KEY backpath (backpath('.AS_DB_MAX_CAT_PAGE_TAGS_LENGTH.'))');
					as_db_upgrade_query($locktablesquery);
					
					as_db_upgrade_query('ALTER TABLE ^posts CHANGE COLUMN categoryid categoryid '.$definitions['posts']['categoryid'].', ADD COLUMN catidpath1 '.$definitions['posts']['catidpath1'].' AFTER categoryid, ADD COLUMN catidpath2 '.$definitions['posts']['catidpath2'].' AFTER catidpath1, ADD COLUMN catidpath3 '.$definitions['posts']['catidpath3'].' AFTER catidpath2'); // AS_CATEGORY_DEPTH=4
					as_db_upgrade_query($locktablesquery);
					
					as_db_upgrade_query('ALTER TABLE ^posts ADD KEY catidpath1 (catidpath1, type, created), ADD KEY catidpath2 (catidpath2, type, created), ADD KEY catidpath3 (catidpath3, type, created), ADD KEY categoryid (categoryid, type, created), ADD KEY catidpath1_2 (catidpath1, updated, type), ADD KEY catidpath2_2 (catidpath2, updated, type), ADD KEY catidpath3_2 (catidpath3, updated, type), ADD KEY categoryid_2 (categoryid, updated, type)');
					as_db_upgrade_query($locktablesquery);
					
					as_db_upgrade_query('ALTER TABLE ^posts ADD CONSTRAINT ^posts_ibfk_3 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE SET NULL');
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorecalccategories']=true;
					break;					
					
			//	Up to here: Version 1.4 betas and release
			
				case 31:
					as_db_upgrade_query('ALTER TABLE ^posts CHANGE COLUMN type type '.$definitions['posts']['type'].', ADD COLUMN updatetype '.$definitions['posts']['updatetype'].' AFTER updated, ADD COLUMN closedbyid '.$definitions['posts']['closedbyid'].' AFTER selchildid, ADD KEY closedbyid (closedbyid), ADD CONSTRAINT ^posts_ibfk_4 FOREIGN KEY (closedbyid) REFERENCES ^posts(postid)');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 32:
					as_db_upgrade_query("UPDATE ^posts SET updatetype=IF(INSTR(type, '_HIDDEN')>0, 'H', 'E') WHERE updated IS NOT NULL");
					break;
					
				case 33:
					as_db_upgrade_query('ALTER TABLE ^contentwords CHANGE COLUMN type type '.$definitions['contentwords']['type']);
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 34:
					if (!AS_FINAL_EXTERNAL_USERS) {
						$keytables=as_array_to_lower_keys(as_db_read_all_values(as_db_query_sub('SHOW TABLES')));
							// might be using messages table shared with another installation, so check if we need to upgrade
						
						if (isset($keytables[as_db_add_table_prefix('messages')]))
							as_db_upgrade_progress('Skipping messages table since it was already added by another Q2A site sharing these users.');
						
						else {
							as_db_upgrade_query('UNLOCK TABLES');
							
							as_db_upgrade_query(as_db_create_table_sql('messages', array(
								'messageid' => $definitions['messages']['messageid'],
								'fromuserid' => $definitions['messages']['fromuserid'],
								'touserid' => $definitions['messages']['touserid'],
								'content' => $definitions['messages']['content'],
								'format' => $definitions['messages']['format'],
								'created' => $definitions['messages']['created'],
								'PRIMARY KEY (messageid)',
								'KEY fromuserid (fromuserid, touserid, created)',
							)));
							
							$locktablesquery.=', ^messages WRITE';
							as_db_upgrade_query($locktablesquery);
						}
					}
					break;
					
				case 35:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('userfavorites', array(
						'userid' => $definitions['userfavorites']['userid'],
						'entitytype' => $definitions['userfavorites']['entitytype'],
						'entityid' => $definitions['userfavorites']['entityid'],
						'nouserevents' => $definitions['userfavorites']['nouserevents'],
						'PRIMARY KEY (userid, entitytype, entityid)',
						'KEY userid (userid, nouserevents)',
						'KEY entitytype (entitytype, entityid, nouserevents)',
						AS_FINAL_EXTERNAL_USERS ? null : 'CONSTRAINT ^userfavorites_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
					)));
					
					$locktablesquery.=', ^userfavorites WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 36:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('userevents', array(
						'userid' => $definitions['userevents']['userid'],
						'entitytype' => $definitions['userevents']['entitytype'],
						'entityid' => $definitions['userevents']['entityid'],
						'questionid' => $definitions['userevents']['questionid'],
						'lastpostid' => $definitions['userevents']['lastpostid'],
						'updatetype' => $definitions['userevents']['updatetype'],
						'lastuserid' => $definitions['userevents']['lastuserid'],
						'updated' => $definitions['userevents']['updated'],
						'KEY userid (userid, updated)',
						'KEY questionid (questionid, userid)',
						AS_FINAL_EXTERNAL_USERS ? null : 'CONSTRAINT ^userevents_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
					)));
					
					$locktablesquery.=', ^userevents WRITE';
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorefillevents']=true;
					break;
					
				case 37:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('sharedevents', array(
						'entitytype' => $definitions['sharedevents']['entitytype'],
						'entityid' => $definitions['sharedevents']['entityid'],
						'questionid' => $definitions['sharedevents']['questionid'],
						'lastpostid' => $definitions['sharedevents']['lastpostid'],
						'updatetype' => $definitions['sharedevents']['updatetype'],
						'lastuserid' => $definitions['sharedevents']['lastuserid'],
						'updated' => $definitions['sharedevents']['updated'],
						'KEY entitytype (entitytype, entityid, updated)',
						'KEY questionid (questionid, entitytype, entityid)',
					)));
					
					$locktablesquery.=', ^sharedevents WRITE';
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorefillevents']=true;
					break;
					
				case 38:
					as_db_upgrade_query('ALTER TABLE ^posts ADD KEY lastuserid (lastuserid, updated, type)');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 39:
					as_db_upgrade_query('ALTER TABLE ^posts DROP KEY type_3, ADD KEY flagcount (flagcount, created, type)');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 40:
					as_db_upgrade_query('ALTER TABLE ^userpoints ADD COLUMN bonus '.$definitions['userpoints']['bonus'].' AFTER downvoteds');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 41:
					as_db_upgrade_query('ALTER TABLE ^pages ADD COLUMN permit '.$definitions['pages']['permit'].' AFTER flags');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 42:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('usermetas', array(
						'userid' => $definitions['usermetas']['userid'],
						'title' => $definitions['usermetas']['title'],
						'content' => $definitions['usermetas']['content'],
						'PRIMARY KEY (userid, title)',
						AS_FINAL_EXTERNAL_USERS ? null : 'CONSTRAINT ^usermetas_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
					)));
					
					$locktablesquery.=', ^usermetas WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 43:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('postmetas', array(
						'postid' => $definitions['postmetas']['postid'],
						'title' => $definitions['postmetas']['title'],
						'content' => $definitions['postmetas']['content'],
						'PRIMARY KEY (postid, title)',
						'CONSTRAINT ^postmetas_ibfk_1 FOREIGN KEY (postid) REFERENCES ^posts(postid) ON DELETE CASCADE',
					)));
				
					$locktablesquery.=', ^postmetas WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 44:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('categorymetas', array(
						'categoryid' => $definitions['categorymetas']['categoryid'],
						'title' => $definitions['categorymetas']['title'],
						'content' => $definitions['categorymetas']['content'],
						'PRIMARY KEY (categoryid, title)',
						'CONSTRAINT ^categorymetas_ibfk_1 FOREIGN KEY (categoryid) REFERENCES ^categories(categoryid) ON DELETE CASCADE',
					)));
				
					$locktablesquery.=', ^categorymetas WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 45:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('tagmetas', array(
						'tag' => $definitions['tagmetas']['tag'],
						'title' => $definitions['tagmetas']['title'],
						'content' => $definitions['tagmetas']['content'],
						'PRIMARY KEY (tag, title)',
					)));
				
					$locktablesquery.=', ^tagmetas WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 46:
					as_db_upgrade_query('ALTER TABLE ^posts DROP KEY selchildid, ADD KEY selchildid (selchildid, type, created), ADD COLUMN amaxvote SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER acount, ADD KEY type_7 (type, amaxvote, created)');
					as_db_upgrade_query($locktablesquery);
					
					$keyrecalc['dorecountposts']=true;
					break;
					
				 case 47:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query(as_db_create_table_sql('usernotices', array(
						'noticeid' => $definitions['usernotices']['noticeid'],
						'userid' => $definitions['usernotices']['userid'],
						'content' => $definitions['usernotices']['content'],
						'format' => $definitions['usernotices']['format'],
						'tags' => $definitions['usernotices']['tags'],
						'created' => $definitions['usernotices']['created'],
						'PRIMARY KEY (noticeid)',
						'KEY userid (userid, created)',
						AS_FINAL_EXTERNAL_USERS ? null : 'CONSTRAINT ^usernotices_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
					)));
					
					$locktablesquery.=', ^usernotices WRITE';
					as_db_upgrade_query($locktablesquery);
					break;

			//	Up to here: Version 1.5.x
			
				case 48:
					if (!AS_FINAL_EXTERNAL_USERS) {
						$keycolumns=as_array_to_lower_keys(as_db_read_all_values(as_db_query_sub('SHOW COLUMNS FROM ^messages')));
							// might be using messages table shared with another installation, so check if we need to upgrade
						
						if (isset($keycolumns['type']))
							as_db_upgrade_progress('Skipping upgrading messages table since it was already upgraded by another Q2A site sharing it.');
	
						else {
							as_db_upgrade_query('ALTER TABLE ^messages ADD COLUMN type '.$definitions['messages']['type'].' AFTER messageid, DROP KEY fromuserid, ADD key type (type, fromuserid, touserid, created), ADD KEY touserid (touserid, type, created)');
							as_db_upgrade_query($locktablesquery);
						}
					}
					break;
					
				case 49:
					if (!AS_FINAL_EXTERNAL_USERS) {
						as_db_upgrade_query('ALTER TABLE ^users CHANGE COLUMN flags flags '.$definitions['users']['flags']);
						as_db_upgrade_query($locktablesquery);
					}
					break;
					
				case 50:
					as_db_upgrade_query('ALTER TABLE ^posts ADD COLUMN name '.$definitions['posts']['name'].' AFTER tags');
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 51:
					if (!AS_FINAL_EXTERNAL_USERS) {
						$keycolumns=as_array_to_lower_keys(as_db_read_all_values(as_db_query_sub('SHOW COLUMNS FROM ^userfields')));
							// might be using userfields table shared with another installation, so check if we need to upgrade
						
						if (isset($keycolumns['permit']))
							as_db_upgrade_progress('Skipping upgrading userfields table since it was already upgraded by another Q2A site sharing it.');
	
						else {
							as_db_upgrade_query('ALTER TABLE ^userfields ADD COLUMN permit '.$definitions['userfields']['permit'].' AFTER flags');
							as_db_upgrade_query($locktablesquery);
						}
					}
					break;
				
				case 52:
					if (!AS_FINAL_EXTERNAL_USERS) {
						$keyindexes=as_array_to_lower_keys(as_db_read_all_assoc(as_db_query_sub('SHOW INDEX FROM ^users'), null, 'Key_name'));
						
						if (isset($keyindexes['created']))
							as_db_upgrade_progress('Skipping upgrading users table since it was already upgraded by another Q2A site sharing it.');
						
						else {
							as_db_upgrade_query('ALTER TABLE ^users ADD KEY created (created, level, flags)');
							as_db_upgrade_query($locktablesquery);
						}
					}
					break;
					
				case 53:
					as_db_upgrade_query('ALTER TABLE ^blobs CHANGE COLUMN content content '.$definitions['blobs']['content']);
					as_db_upgrade_query($locktablesquery);
					break;
					
				case 54:
					as_db_upgrade_query('UNLOCK TABLES');
					
					as_db_upgrade_query('SET FOREIGN_KEY_CHECKS=0'); // in case InnoDB not available
					
					as_db_upgrade_query(as_db_create_table_sql('userlevels', array(
						'userid' => $definitions['userlevels']['userid'],
						'entitytype' => $definitions['userlevels']['entitytype'],
						'entityid' => $definitions['userlevels']['entityid'],
						'level' => $definitions['userlevels']['level'],
						'UNIQUE userid (userid, entitytype, entityid)',
						'KEY entitytype (entitytype, entityid)',
						AS_FINAL_EXTERNAL_USERS ? null : 'CONSTRAINT ^userlevels_ibfk_1 FOREIGN KEY (userid) REFERENCES ^users(userid) ON DELETE CASCADE',
					)));
				
					$locktablesquery.=', ^userlevels WRITE';
					as_db_upgrade_query($locktablesquery);
					break;
					
			//	Up to here: Version 1.6 beta 1
			
				case 55:
					if (!AS_FINAL_EXTERNAL_USERS) {
						$keycolumns=as_array_to_lower_keys(as_db_read_all_values(as_db_query_sub('SHOW COLUMNS FROM ^users')));
							// might be using messages table shared with another installation, so check if we need to upgrade
						
						if (isset($keycolumns['wallposts']))
							as_db_upgrade_progress('Skipping upgrading users table since it was already upgraded by another Q2A site sharing it.');
	
						else {
							as_db_upgrade_query('ALTER TABLE ^users ADD COLUMN wallposts '.$definitions['users']['wallposts'].' AFTER flags');
							as_db_upgrade_query($locktablesquery);
						}
					}
					break;
			
			//	Up to here: Version 1.6 beta 2
			
				case 56:
					as_db_upgrade_query('ALTER TABLE ^pages DROP INDEX tags, ADD KEY tags (tags)');
					as_db_upgrade_query($locktablesquery);
					break;
					
			//	Up to here: Version 1.6 (release)
			
			}
			
			as_db_set_db_version($newversion);
			
			if (as_db_get_db_version()!=$newversion)
				as_fatal_error('Could not increment database version');
		}
		
		as_db_upgrade_query('UNLOCK TABLES');

	//	Perform any necessary recalculations, as determined by upgrade steps
		
		foreach ($keyrecalc as $state => $dummy)
			while ($state) {
				set_time_limit(60);
			
				$stoptime=time()+2;
				
				while ( as_recalc_perform_step($state) && (time()<$stoptime) )
					;
				
				as_db_upgrade_progress(as_recalc_get_message($state));
			}
	}
	
	
	function as_db_upgrade_table_columns($definitions, $table, $columns)
/*
	Reset the definitions of $columns in $table according to the $definitions array
*/
	{
		$sqlchanges=array();
		
		foreach ($columns as $column)
			$sqlchanges[]='CHANGE COLUMN '.$column.' '.$column.' '.$definitions[$table][$column];

		as_db_upgrade_query('ALTER TABLE ^'.$table.' '.implode(', ', $sqlchanges));
	}

	
	function as_db_upgrade_query($query)
/*
	Perform upgrade $query and output progress to the browser
*/
	{
		as_db_upgrade_progress('Running query: '.as_db_apply_sub($query, array()).' ...');
		as_db_query_sub($query);
	}

	
	function as_db_upgrade_progress($text)
/*
	Output $text to the browser (after converting to HTML) and do all we can to get it displayed
*/
	{
		echo as_html($text).str_repeat('    ', 1024)."<br><br>\n";
		flush();
	}


/*
	Omit PHP closing tag to help avoid accidental output
*/