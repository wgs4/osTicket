<?php
// Needed to convert rlang
require_once 'class.language.php';
require_once 'class.report.php';

// Begin reports plugin class
class_alias('modreportPluginConfig','rpts');
class modreportPluginConfig extends PluginConfig {

	function getSoftModsVersionInfo(){

		return Report::get_default('version_info');

	}
	function getSoftModsVersion($plugin,$my_version){

		$file = 'http://www.software-mods.com/clients/downloads/current_versions.txt';
		$searchfor = $plugin;

		// get the file contents, assuming the file to be readable (and exist)
		$contents = file_get_contents($file);
		// escape special characters in the query
		$pattern = preg_quote($searchfor, '/');
		// finalise the regular expression, matching the whole line
		$pattern = "/^.*$pattern.*\$/m";
		// search, and store all matching occurences in $matches
		if(preg_match_all($pattern, $contents, $matches)){

	   		$full_line=implode('\n',$matches[0]);
	   		$pieces=explode(',',$full_line);
	
			// Assign the pieces
			$name	=$pieces[0];
			$version=$pieces[1];
			$notes  =$pieces[2];
			$release=$pieces[3];
	
			$full=array($name,$version,$release,$notes);

		}
		Report::set_config('version_info',json_encode($full));
		// Update how long it's been
		Report::set_last_version_check($version);

    } // end function

    function getOptions() {

	$version='8.1.0'; // 8.1.0 : osTicket 1.11 Only - Added filter, minor css change, team report fix
                      // 8.0.9 : osTicket 1.11 Only - Updated to function with event/thread changes in DB
                      // 8.0.8 : Removed default timezone set
                      // 8.0.7 : Cron check update
                      //       : Multiple email recipients
	                  // 8.0.6 : Show HTML in scheduled emails
                      //       : Show Graph in regular run report email
                      //       : Allow for sending to comma seperated emails
                      // 8.0.5 : Fixed "Run Once" scheduled reports
	                  // 8.0.4 : Fixed Tickets per Organization
	                  // 8.0.3 : Fixed custom resolved/closed status names issue on reports
	                  // 8.0.2 : Fixed version checker
	                  // 8.0.1 : define REPORTS redone to not use INCLUDE_DIR
                      // 8.0.0 : Rewrite to class, PDF, status at time of report - not 'now'
                    

	// If it hasn't been at least 24 hours then just stop (don't spam software-mods.com)

	$last=Report::get_last_version_check();
	$now =date('U');
	$gtlt = $now - $last > 10 ? 'greater' : 'lesser' ;
	// Check how long it's been since I last checked

		switch ($gtlt) {

			case 'greater':
				// Check the version again
				self::getSoftModsVersion('Reports',$version);
				$versioninfo=self::getSoftModsVersionInfo();
			break;

			case 'lesser':
				// Get information from last check
				$versioninfo=self::getSoftModsVersionInfo();
			break;

		}
        $versioninfo=explode('"',$versioninfo);
		$smversion = preg_replace('/[.]/','',$versioninfo[3]);
		$myversion = preg_replace('/[.]/','',$version);
		$diff = $smversion - $myversion;
		$info="Update available!";
		$hint=$versioninfo[1]." ".$versioninfo[3]." ".$versioninfo[5].": ".$versioninfo[7];
	
		$versiontext = $diff > 0 ? $info : rlang::tr('_you_are_running_the_most_up_to_date_release_').'!';
		$hint = $diff > 0 ? $hint : NULL;

	        return array(
		        'soft_version' => new SectionBreakField(array(
                'label' => rlang::tr('_software_version_').' '.$version. ' - '.$versiontext,
		        'hint' => $hint
            )),		
		        'visual' => new SectionBreakField(array(
                'label' => rlang::tr('_visual_options_'),
                'hint' => rlang::tr('_how_the_reports_are_visually_represented_') 
            )),		
                'language' => new ChoiceField(array(
                'label' => rlang::tr('_language_'),
                'choices' => array(
                'afrikaans' => 'Afrikaans',
                'arabic' => 'العَرَبِيَّة',
                'english' => 'English',
                'french' => 'Français',
                'italian' => 'Italiano',
                'swedish' => 'Swedish',
                ),
            )),
                'resolution' => new ChoiceField(array(
                'label' => rlang::tr('_time_to_resolution_'),
		'default' => 'days',
                'choices' => array(
                'days' => rlang::tr('_days_'),
                'hours' => rlang::tr('_hours_'),
                ),
            )),
		'text' => new SectionBreakField(array(
                'label' => rlang::tr('_who_to_display_this_tool_to_'),
                'hint' => rlang::tr('_show_to_staff_admin_both_'),
            )),
            	'showstaff' => new BooleanField(array(
                'id' => 's6r',
                'label' => rlang::tr('_staff_'),
		'default' => 1,
                'configuration' => array(
                'desc' => rlang::tr('_display_application_menu_to_staff_'))
            )),
		'showadmin' => new BooleanField(array(
                'id' => 'a6r',
                'label' => rlang::tr('_admin_'),
		'default' => '1',
                'configuration' => array(
                'desc' => rlang::tr('_display_application_menu_to_admin_'))
            )),
       	    	'drafts_desc' => new SectionBreakField(array(
                'label' => rlang::tr('_administration_options_'),
            )),
            'generate_pdf' => new BooleanField(array(
                'id' => 'pdf_chart',
                'label' => rlang::tr('_generate_pdf_by_default_'),
		'default' => '0',
                'configuration' => array(
                'desc' => rlang::tr('_enable_')),
            )),
            'generate_chart' => new BooleanField(array(
                'id' => 'generate_chart',
                'label' => rlang::tr('_generate_chart_by_default_'),
		'default' => '0',
                'configuration' => array(
                'desc' => rlang::tr('_enable_')),
            )),
            'generate_csv' => new BooleanField(array(
                'id' => 'generate_csv',
                'label' => rlang::tr('_generate_csv_by_default_'),
		'default' => '0',
                'configuration' => array(
                'desc' => rlang::tr('_enable_')),
            )),
            'show_quick' => new BooleanField(array(
                'id' => 'show_quick',
                'label' => rlang::tr('_show_quick_stats_'),
		'default' => '1',
                'configuration' => array(
                'desc' => rlang::tr('_enable_')),
            )),
     	    	 'default_email' => new TextboxField(array(
                 'label' => rlang::tr('_default_email_'),
                 'configuration' => array('html'=>false, 'size'=>60, 'length'=>256),
                 'hint' => rlang::tr('_email_address_to_mail_reports_to_'),
             )),
     	    	 'csv_separator' => new TextboxField(array(
                 'label' => rlang::tr('_csvseparator_'),
                 'configuration' => array('html'=>false, 'size'=>1, 'length'=>5),
		 'default' => ',',
                 'hint' => rlang::tr('_change_delimiter_'),
             )),
     	    	 'output_directory' => new TextboxField(array(
                 'label' => rlang::tr('_output_directory_'),
                 'configuration' => array('html'=>false, 'size'=>60, 'length'=>256),
		 'default' => 'REPORTS',
                 'hint' => rlang::tr('_directory_to_save_to_').'<br>'.rlang::tr('_relative_to_root_'),
             )),
            'reportSelect' => new ChoiceField(array(
				'label' => rlang::tr('_default_report_'),
                'choices' => array(
                    'tixPerDept' => rlang::tr('_ticketsperdepartment_'),
                    'tixPerTeam' => rlang::tr('_ticketsperteam_'),
                    'tixPerDay' =>  rlang::tr('_ticketsperday_'),
                    'tixPerMonth' => rlang::tr('_ticketspermonth_'),
                    'tixPerStaff' => rlang::tr('_ticketsperagent_'),
                    'tixPerTopic' => rlang::tr('_ticketsperhelptopic_'),
                    'tixPerClient' => rlang::tr('_ticketsperclient_'),
                    'repliesPerStaff' => rlang::tr('_repliesperstaff_'),
                    'tixPerOrg' => rlang::tr('_ticketsperorganization_'),
                ),
		'hint' => rlang::tr('_default_report_to_display_'),
            )),
            'range' => new ChoiceField(array(
		'label' => rlang::tr('_default_range_'),
                'choices' => array(
                    'today' => rlang::tr('_today_'),
                    'yesterday' => rlang::tr('_yesterday_'),
                    'thismonth' => rlang::tr('_thismonth_'),
                    'lastmonth' => rlang::tr('_lastmonth_'),
                    'lastthirty' => rlang::tr('_lastthirty_'),
                    'thisweek' => rlang::tr('_thisweek_'),
                    'lastweek' => rlang::tr('_lastweek_'),
                    'thisbusweek' => rlang::tr('_thisbusinessweek_'),
                    'lastbusweek' => rlang::tr('_lastbusinessweek_'),
                    'thisyear' => rlang::tr('_thisyear_'),
                    'lastyear' => rlang::tr('_lastyear_'),
                    'alltime' => rlang::tr('_alltime_'),
                ),
		'hint' => rlang::tr('_default_time_range_to_display_'),
            )),
        );
    }

    function pre_save(&$config, &$errors) {

        global $msg;
        if (!$errors)
	    $msg = rlang::tr('_reporting_configuration_updated_successfully_');

        return !$errors;
    }

}

