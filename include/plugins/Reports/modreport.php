<?php
$curr_path = explode('/',getcwd());
$sql = "SELECT `value` FROM ".CONFIG_TABLE." WHERE `key`='default_timezone'";
$zone = db_result(db_query($sql));
//date_default_timezone_set($zone);
require_once(INCLUDE_DIR.'class.plugin.php');
require_once('class.dispatcher.php');
require_once('config.php');
require_once(INCLUDE_DIR.'class.app.php');

// Required for PDF generation
require_once(INCLUDE_DIR.'mpdf/vendor/autoload.php');

require_once(INCLUDE_DIR.'plugins/Reports/class.report.php');

class modreport extends Plugin {
  var $config_class = 'modreportPluginConfig';

     /**
     * Creates menu links in the staff backend.
     */
	function getScheduledReports() {
        $logging = new Report;
		$sql = "SELECT * FROM ".CONFIG_TABLE." WHERE namespace='schedules'";
		$res = db_query($sql);
		while($row = db_fetch_array($res)){
			$cid  = $row['id'];
			$name = $row['key'];
			$schedule_array=(array) json_decode($row['value']);

            // Keep a copy to add the last run to and update the entry with.
            $post_array = $schedule_array;

			// Remove the report information as it can't be read by rrule
			$report_type     = $schedule_array['REPORT_TYPE'];
			$report_range    = $schedule_array['REPORT_RANGE'];
			$report_filter   = $schedule_array['FILTER'];
			$report_from     = $schedule_array['FROMDATE'];
			$report_to       = $schedule_array['TODATE'];
			$report_email    = $schedule_array['EMAIL'];
			$period_or_range = $schedule_array['PERIOD_RANGE'];
            $run_count       = $schedule_array['COUNT'];
            if($run_count==NULL){$run_count=0;}
			unset($schedule_array['REPORT_TYPE']);
			unset($schedule_array['REPORT_RANGE']);
			unset($schedule_array['FROMDATE']);
			unset($schedule_array['EMAIL']);
			unset($schedule_array['TODATE']);
			unset($schedule_array['PERIOD_RANGE']);
			unset($schedule_array['lastrun']);
			unset($schedule_array['FILTER']);
			$DTSTART=strtotime($schedule_array['DTSTART']);
			$sql = "SELECT `value` FROM ".CONFIG_TABLE." WHERE `key`='default_timezone'";
			$zone = db_result(db_query($sql));
			date_default_timezone_set($zone);
			$now_epoch = time();

            // Check that we have not passed the "UNTIL" date.
            if(isset($schedule_array['UNTIL'])){
                $until=$schedule_array['UNTIL'];
	            $until_epoch=strtotime($until);

	            if($until_epoch < $now_epoch){
	                // No longer send this report
                    // $logging = new Report;
                    // $logging->report_log('Report "UNTIL" date: '.$schedule_array['UNTIL']. ', is in the past. Skipped');
	                continue;
	            }
            }

            // Check that this report hasn't already run in the last 5 minutes
            if(isset($post_array['lastrun'])){
                $lastrun=$post_array['lastrun'];

	            if($now_epoch - $lastrun <= 300){
                    // Already sent this report within the last 5 minutes
                    $logging = new Report;
                    $logging->report_log('Report has already run recently at '.date('r',$lastrun));
	                continue;
	            }
            }
            

	        $now=date('Y-m-d H:i', $now_epoch);

			$rrule = new RRule\RRule($schedule_array);

			// Start going through date times to run
			// Check up to the present
			// if future entry then break loop
 			foreach ($rrule as $occurrence ) {
				$epoch_to_check=strtotime($occurrence->format('Y-m-d H:i'));
				$time_to_check=date('Y-m-d H:i',$epoch_to_check);

				// If this is in the future - stop processing this rule
				if($epoch_to_check>$now_epoch){
					break;
				}

				// If this is the minute - do stuff!
				if($time_to_check==$now){
					// Match - Do the things!
					$scheduled_report = new Report;

					// This is redundant code with scp/reports.php 
					// change to a function later
					// What
					$scheduled_report->rtype      = $report_type;
				
					// When
					$scheduled_report->date_range = $period_or_range;

                    // Filter   
                    $scheduled_report->filter = $report_filter;
				
					switch ($period_or_range) {
				
						// if a time period is selected (today, yesterday)
						case 'timePeriod':
							$scheduled_report->set_range(strtolower($report_range));
							$scheduled_report->to_date   = NULL;
							$scheduled_report->from_date = NULL;
							break;
				
						// if a specific range is given
						case 'timeRange':
							$scheduled_report->to_date   = $report_to;
							$scheduled_report->from_date = $report_from;
							break;

					}
					ob_start();
					$scheduled_report->create_report('html');
					$out=ob_get_clean();
					$odir=$scheduled_report->get_default('output_directory');
					$http=db_result(db_query("SELECT `value` FROM ".CONFIG_TABLE." WHERE `key`='helpdesk_url'"));
					$out=str_replace($odir,$http."/scp/$odir",$out);
					$scheduled_report->create_pdf($out,true);
					require_once(INCLUDE_DIR.'plugins/Reports/include/scheduled_email.php');
				}
			}

		}
	}
	function bootstrap() {

		$config = $this->getConfig ();
		$drafts_toggle = $this->getConfig()->get('drafts_toggle');
		$drafts_age = $this->getConfig()->get('draft_age');
		$showstaff = $this->getConfig()->get('showstaff');
		$showadmin = $this->getConfig()->get('showadmin');
		if(($drafts_toggle == 'true')||($showstaff == 'true')||($showadmin == 'true')) {
		  if ($config->get ( 'showstaff' )) { $this->createStaffMenu (); }
		  if ($config->get ( 'showadmin' )) { $this->createAdminMenu (); }
		}


				// I don't know why this is necessary... but it is.
				$self = $this;
				// Watch for cron to be called, after cron is complete
				// run this code.
				Signal::connect('cron', function($info) use ($self) {
					self::getScheduledReports();
				});			

	}


	
	//function to log info to osTicket Warning log
	function logWarning($title, $message, $alert=true) {
		global $ost;
		return $ost->log(LOG_WARN, $title, $message, $alert);
    }
	
	// Function to display Applications -> plugin on Agent Panel
	function createStaffMenu() {
        Application::registerStaffApp ( 'Reporting', 'reports.php', array (
                'iconclass' => 'faq-categories' 
        ) );
    }
	
	// Function to display Applications -> plugin on Admin Panel
	function createAdminMenu() {
        Application::registerAdminApp ( 'Reporting',  'reports.php', array (
                'iconclass' => 'faq-categories' 
        ) );
        Application::registerAdminApp ( 'Scheduling',  'scheduling.php', array (
                'iconclass' => 'faq-categories' 
        ) );
    }

}
