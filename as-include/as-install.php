<?php

/*
	Question2Answer by Gideon Greenspan and contributors

	http://www.question2answer.org/

	
	File: as-include/as-install.php
	Version: See define()s at top of as-include/as-base.php
	Description: User interface for installing, upgrading and fixing the database


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

	require_once AS_INCLUDE_DIR.'as-db-install.php';
	
	as_report_process_stage('init_install');


//	Output start of HTML early, so we can see a nicely-formatted list of database queries when upgrading	

?>
<html>
	<head>
		<meta http-equiv="content-type" content="text/html; charset=utf-8">
		<style type="text/css">
			body,input {font-size:16px; font-family:Verdana, Arial, Helvetica, sans-serif;}
			body {text-align:center; width:640px; margin:64px auto;}
			table {margin: 16px auto;}
		</style>
	</head>
	<body>
<?php


//	Define database failure handler for install process, if not defined already (file could be included more than once)

	if (!function_exists('as_install_db_fail_handler')) {

		function as_install_db_fail_handler($type, $errno=null, $error=null, $query=null)
	/*
		Handler function for database failures during the installation process
	*/
		{
			global $pass_failure_from_install;
			
			$pass_failure_type=$type;
			$pass_failure_errno=$errno;
			$pass_failure_error=$error;
			$pass_failure_query=$query;
			$pass_failure_from_install=true;
			
			require AS_INCLUDE_DIR.'as-install.php';
			
			as_exit('error');
		}
		
	}


	$success='';
	$errorhtml='';
	$suggest='';
	$buttons=array();
	$fields=array();
	$fielderrors=array();
	$hidden=array();

	if (isset($pass_failure_type)) { // this page was requested due to query failure, via the fail handler
		switch ($pass_failure_type) {
			case 'connect':
				$errorhtml.='Could not establish database connection. Please check the username, password and hostname in the config file, and if necessary set up the appropriate MySQL user and privileges.';
				break;
			
			case 'select':
				$errorhtml.='Could not switch to the Question2Answer database. Please check the database name in the config file, and if necessary create the database in MySQL and grant appropriate user privileges.';
				break;
				
			case 'query':
				global $pass_failure_from_install;
				
				if (@$pass_failure_from_install)
					$errorhtml.="Question2Answer was unable to perform the installation query below. Please check the user in the config file has CREATE and ALTER permissions:\n\n".as_html($pass_failure_query."\n\nError ".$pass_failure_errno.": ".$pass_failure_error."\n\n");
				else
					$errorhtml.="A Question2Answer database query failed when generating this page.\n\nA full description of the failure is available in the web server's error log file.";
				break;
		}

	} else { // this page was requested by user GET/POST, so handle any incoming clicks on buttons
		as_db_connect('as_install_db_fail_handler');
		
		if (as_clicked('create')) {
			as_db_install_tables();
			
			if (AS_FINAL_EXTERNAL_USERS) {
				if (defined('AS_FINAL_WORDPRESS_INTEGRATE_PATH')) {
					require_once AS_INCLUDE_DIR.'as-db-admin.php';
					require_once AS_INCLUDE_DIR.'as-app-format.php';
					
					as_db_page_move(as_db_page_create(get_option('blogname'), AS_PAGE_FLAGS_EXTERNAL, get_option('home'), null, null, null), 'O', 1);
						// create link back to WordPress home page
					
					$success.='Your Question2Answer database has been created and integrated with your WordPress site.';

				} else
					$success.='Your Question2Answer database has been created for external user identity management. Please read the online documentation to complete integration.';
			
			} else
				$success.='Your Question2Answer database has been created.';
		}
		
		if (as_clicked('nonuser')) {
			as_db_install_tables();
			$success.='The additional Question2Answer database tables have been created.';
		}
		
		if (as_clicked('upgrade')) {
			as_db_upgrade_tables();
			$success.='Your Question2Answer database has been updated.';
		}

		if (as_clicked('repair')) {
			as_db_install_tables();
			$success.='The Question2Answer database tables have been repaired.';
		}
		
		if (as_clicked('module')) {
			$moduletype=as_post_text('moduletype');
			$modulename=as_post_text('modulename');
			
			$module=as_load_module($moduletype, $modulename);
			
			$queries=$module->init_queries(as_db_list_tables_lc());
			
			if (!empty($queries)) {
				if (!is_array($queries))
					$queries=array($queries);
					
				foreach ($queries as $query)
					as_db_upgrade_query($query);
			}

			$success.='The '.$modulename.' '.$moduletype.' module has completed database initialization.';
		}

		if (as_clicked('super')) {
			require_once AS_INCLUDE_DIR.'as-db-users.php';
			require_once AS_INCLUDE_DIR.'as-app-users-edit.php';
	
			$inemail=as_post_text('email');
			$inpassword=as_post_text('password');
			$inhandle=as_post_text('handle');
			
			$fielderrors=array_merge(
				as_handle_email_filter($inhandle, $inemail),
				as_password_validate($inpassword)
			);
			
			if (empty($fielderrors)) {
				require_once AS_INCLUDE_DIR.'as-app-users.php';
				
				$userid=as_create_new_user($inemail, $inpassword, $inhandle, AS_USER_LEVEL_SUPER);
				as_set_logged_in_user($userid, $inhandle);
				
				as_set_option('feedback_email', $inemail);
				
				$success.="Congratulations - Your Question2Answer site is ready to go!\n\nYou are logged in as the super administrator and can start changing settings.\n\nThank you for installing Question2Answer.";
			}
		}
	}
	
	if (is_resource(as_db_connection(false)) && !@$pass_failure_from_install) {
		$check=as_db_check_tables(); // see where the database is at
		
		switch ($check) {
			case 'none':
				if (@$pass_failure_errno==1146) // don't show error if we're in installation process
					$errorhtml='';
					
				$errorhtml.='Welcome to Question2Answer. It\'s time to set up your database!';

				if (AS_FINAL_EXTERNAL_USERS) {
					if (defined('AS_FINAL_WORDPRESS_INTEGRATE_PATH'))
						$errorhtml.="\n\nWhen you click below, your Question2Answer site will be set up to integrate with the users of your WordPress site <a href=\"".as_html(get_option('home'))."\" target=\"_blank\">".as_html(get_option('blogname'))."</a>. Please consult the online documentation for more information.";
					else
						$errorhtml.="\n\nWhen you click below, your Question2Answer site will be set up to integrate with your existing user database and management. Users will be referenced with database column type ".as_html(as_get_mysql_user_column_type()).". Please consult the online documentation for more information.";
					
					$buttons=array('create' => 'Create Database');
				} else {
					$errorhtml.="\n\nWhen you click below, your Question2Answer database will be set up to manage user identities and logins internally.\n\nIf you want to offer a single sign-on for an existing user base or website, please consult the online documentation before proceeding.";
					$buttons=array('create' => 'Create Database including User Management');
				}
				break;
				
			case 'old-version':
				if (!@$pass_failure_from_install)
					$errorhtml=''; // don't show error if we need to upgrade
					
				$errorhtml.='Your Question2Answer database needs to be upgraded for this version of the software.'; // don't show error before this
				$buttons=array('upgrade' => 'Upgrade Database');
				break;
				
			case 'non-users-missing':
				$errorhtml='This Question2Answer site is sharing its users with another Q2A site, but it needs some additional database tables for its own content. Please click below to create them.';
				$buttons=array('nonuser' => 'Create Tables');
				break;
				
			case 'table-missing':
				$errorhtml.='One or more tables are missing from your Question2Answer database.';
				$buttons=array('repair' => 'Repair Database');
				break;
				
			case 'column-missing':
				$errorhtml.='One or more Question2Answer database tables are missing a column.';
				$buttons=array('repair' => 'Repair Database');
				break;
				
			default:
				require_once AS_INCLUDE_DIR.'as-db-admin.php';
	
				if ( (!AS_FINAL_EXTERNAL_USERS) && (as_db_count_users()==0) ) {
					$errorhtml.="There are currently no users in the Question2Answer database.\n\nPlease enter your details below to create the super administrator:";
					$fields=array('handle' => 'Username:', 'password' => 'Password:', 'email' => 'Email address:');
					$buttons=array('super' => 'Create Super Administrator');
	
				} else {
					$tables=as_db_list_tables_lc();
					
					$moduletypes=as_list_module_types();
					
					foreach ($moduletypes as $moduletype) {
						$modules=as_load_modules_with($moduletype, 'init_queries');
						
						foreach ($modules as $modulename => $module) {
							$queries=$module->init_queries($tables);
							if (!empty($queries)) { // also allows single query to be returned
								$errorhtml=strtr(as_lang_html('admin/module_x_database_init'), array(
									'^1' => as_html($modulename),
									'^2' => as_html($moduletype),
									'^3' => '',
									'^4' => '',
								));
								
								$buttons=array('module' => 'Initialize Database');
	
								$hidden['moduletype']=$moduletype;
								$hidden['modulename']=$modulename;
								break;
							}
						}
					}
				}
				break;
		}
	}
	
	if (empty($errorhtml)) {
		if (empty($success))
			$success='Your Question2Answer database has been checked with no problems.';
		
		$suggest='<a href="'.as_path_html('admin', null, null, AS_URL_FORMAT_SAFEST).'">Go to admin center</a>';
	}

?>

		<form method="post" action="<?php echo as_path_html('install', null, null, AS_URL_FORMAT_SAFEST)?>">

<?php

	if (strlen($success))
		echo '<p><font color="#006600">'.nl2br(as_html($success)).'</font></p>'; // green
		
	if (strlen($errorhtml))
		echo '<p><font color="#990000">'.nl2br($errorhtml).'</font></p>'; // red
		
	if (strlen($suggest))
		echo '<p>'.$suggest.'</p>';


//	Very simple general form display logic (we don't use theme since it depends on tons of DB options)

	if (count($fields)) {
		echo '<table>';
		
		foreach ($fields as $name => $prompt) {
			echo '<tr><td>'.as_html($prompt).'</td><td><input type="text" size="24" name="'.as_html($name).'" value="'.as_html(@${'in'.$name}).'"></td>';
			if (isset($fielderrors[$name]))
				echo '<td><font color="#990000"><small>'.as_html($fielderrors[$name]).'</small></font></td>';
			echo '</tr>';
		}
		
		echo '</table>';
	}
	
	foreach ($buttons as $name => $value)
		echo '<input type="submit" name="'.as_html($name).'" value="'.as_html($value).'">';
		
	foreach ($hidden as $name => $value)
		echo '<input type="hidden" name="'.as_html($name).'" value="'.as_html($value).'">';

	as_db_disconnect();
?>

		</form>
	</body>
</html>