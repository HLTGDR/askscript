<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-page-admin-recalc.php
	Version: See define()s at top of as-include/as-base.php
	Description: Handles admin-triggered recalculations if JavaScript disabled


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

	require_once AS_INCLUDE_DIR.'as-app-admin.php';
	require_once AS_INCLUDE_DIR.'as-app-recalc.php';

	
//	Check we have administrative privileges

	if (!as_admin_check_privileges($content))
		return $content;

	
//	Find out the operation

	$allowstates=array(
		'dorecountposts',
		'doreindexcontent',
		'dorecalcpoints',
		'dorefillevents',
		'dorecalccategories',
		'dodeletehidden',
		'doblobstodisk',
		'doblobstodb',
	);
	
	$recalcnow=false;
	
	foreach ($allowstates as $allowstate)
		if (as_post_text($allowstate) || as_get($allowstate)) {
			$state=$allowstate;
			$code=as_post_text('code');
			
			if (isset($code) && as_check_form_security_code('admin/recalc', $code))
				$recalcnow=true;
		}
			
	if ($recalcnow) {
?>

<html>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=utf-8">
	</head>
	<body>
		<tt>

<?php

		while ($state) {
			set_time_limit(60);
			
			$stoptime=time()+2; // run in lumps of two seconds...
			
			while ( as_recalc_perform_step($state) && (time()<$stoptime) )
				;
			
			echo as_html(as_recalc_get_message($state)).str_repeat('    ', 1024)."<br>\n";

			flush();
			sleep(1); // ... then rest for one
		}

?>
		</tt>
		
		<a href="<?php echo as_path_html('admin/stats')?>"><?php echo as_lang_html('admin/admin_title').' - '.as_lang_html('admin/stats_title')?></a>
	</body>
</html>

<?php
		as_exit();
	
	} elseif (isset($state)) {
		$content=as_content_prepare();

		$content['title']=as_lang_html('admin/admin_title');
		$content['error']=as_lang_html('misc/form_security_again');
		
		$content['form']=array(
			'tags' => 'method="post" action="'.as_self_html().'"',
		
			'style' => 'wide',
			
			'buttons' => array(
				'recalc' => array(
					'tags' => 'name="'.as_html($state).'"',
					'label' => as_lang_html('misc/form_security_again'),
				),
			),
			
			'hidden' => array(
				'code' => as_get_form_security_code('admin/recalc'),
			),
		);
		
		return $content;
	
	} else {
		require_once AS_INCLUDE_DIR.'as-app-format.php';
		
		$content=as_content_prepare();

		$content['title']=as_lang_html('admin/admin_title');
		$content['error']=as_lang_html('main/page_not_found');
		
		return $content;
	}
			

/*
	Omit PHP closing tag to help avoid accidental output
*/