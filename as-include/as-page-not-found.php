<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-page-not-found.php
	Version: See define()s at top of as-include/as-base.php
	Description: Controller for page not found (error 404)


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
	
	require_once AS_INCLUDE_DIR.'as-app-format.php';


	header('HTTP/1.0 404 Not Found');

	as_set_template('not-found');

	$content=as_content_prepare();
	$content['error']=as_lang_html('main/page_not_found');
	$content['suggest_next']=as_html_suggest_qs_tags(as_using_tags());
	
	
	return $content;


/*
	Omit PHP closing tag to help avoid accidental output
*/