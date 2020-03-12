<?php
!defined('REPORTS')?define('REPORTS',__DIR__.'/'):NULL;
!defined('PCHARTS')?define('PCHARTS',REPORTS.'include/pChart/'):NULL;
require_once REPORTS.'include/RRULE/src/RRuleInterface.php';
require_once REPORTS.'include/RRULE/src/RRule.php';
require_once PCHARTS.'class/pData.class.php';
require_once PCHARTS.'class/pDraw.class.php';
require_once PCHARTS.'class/pImage.class.php';
require_once REPORTS.'class.language.php';

class Report {

	// Criteria
	var $rtype;
    var $filter;
	var $begin;
	var $end;

	// Options
	var $csv;
	var $pdf;
	var $chart;
	var $email;
	var $quick;
	var $range;
	var $showadmin;
	var $showstaff;
	var $language;
	var $resolution;
	var $output_directory;
	var $select;
	var $where;
	var $group;
	var $date_range;
	var $separator;
	var $make_image;

	// Database
	var $query;
	private $results;

	// Other
	var $plugin_id;
	var $default;
	private $plugin_keys;
	private $ticket_list;
	private $headers;

	function __construct(){

		// Default only allows for 'today', 'this week', etc 
		// so set date_range to timePeriod
		$this->date_range	= 'timePeriod';
		$this->from_date	= NULL;
		$this->to_date		= NULL;

		$this->select   	= $this->set_select();
		$this->rtype 		= $this->get_default('reportSelect');
		$this->csv   		= $this->get_default('generate_csv');
		$this->pdf   		= $this->get_default('generate_pdf');
		$this->chart 		= $this->get_default('generate_chart');
		$this->email 		= $this->get_default('default_email');
		$this->quick 		= $this->get_default('show_quick');
		$this->range 		= $this->get_default('range');
		$this->showadmin 	= $this->get_default('showadmin');
		$this->showstaff 	= $this->get_default('showstaff');
		$this->language 	= $this->get_default('language');

		$this->resolution 	= $this->get_default('resolution');
		$this->resolution==''?$this->resolution='days':NULL;

		$this->output_directory = $this->get_default('csv_directory');
		$this->query 		= $this->get_query();

		// Create image by default
		$this->make_image	= true;

		$this->separator 	= $this->get_default('csv_separator');

	}

	function get_human_range(){

		switch($this->date_range){

	         	case 'timePeriod':

				switch($this->range){

					case 'alltime':
						$range	= rlang::tr('_alltime_');
						break;

					case 'today':
						$range	= rlang::tr('_today_');
						break;
					
					case 'yesterday':
						$range	= rlang::tr('_yesterday_');
						break;
		
					case 'thismonth':
						$range	= rlang::tr('_thismonth_');
						break;
		
					case 'lastmoth':
						$range	= rlang::tr('_lastmonth_');
						break;
		
					case 'thisweek':
						$range	= rlang::tr('_thisweek_');
						break;
		
					case 'lastweek':
						$range	= rlang::tr('_lastweek_');
						break;
		
					case 'thisbusweek':
						$range	= rlang::tr('_thisbusinessweek_');
						break;
		
					case 'lastbusweek':
						$range	= rlang::tr('_lastbusinessweek_');
						break;
		
					case 'thisyear':
						$range	= rlang::tr('_thisyear_');
						break;
		
					case 'lastyear':
						$range	= rlang::tr('_lastyear_');
						break;
		
					case 'lastthirty':
						$range	= rlang::tr('_lastthirty_');
						break;
		
					}
					break;

			default:
				// TODO modify to match DB
				$range = $this->from_date . ' - '. $this->to_date;
				break;

		}

		return $range;
	}

	function get_human_rtype(){

		switch($this->rtype){

			case 'tixPerDay':
				$rtype	= rlang::tr('_ticketsperday_');
				break;

			case 'tixPerMonth':
				$rtype	= rlang::tr('_ticketspermonth_');
				break;
			
			case 'tixPerStaff':
				$rtype	= rlang::tr('_ticketsperagent_');
				break;

			case 'tixPerDept':
				$rtype	= rlang::tr('_ticketsperdepartment_');
				break;

			case 'tixPerOrg':
				$rtype	= rlang::tr('_ticketsperorganization_');
				break;

			case 'tixPerTeam':
				$rtype	= rlang::tr('_ticketsperteam_');
				break;

			case 'tixPerClient':
				$rtype	= rlang::tr('_ticketsperclient_');
				break;

			case 'repliesPerStaff':
				$rtype	= rlang::tr('_repliesperstaff_');
				break;

			case 'tixPerTopic':
				$rtype	= rlang::tr('_ticketsperhelptopic_');
				break;

		}

		return $rtype;

	}

	function set_resolution($res){

		$this->resolution = $res;

	}

	function set_to_date($date){

		$this->to_date = $date;

	}

	function set_from_date($date){

		$this->from_date = $date;

	}

	function set_date_range($date_range){

		$this->date_range = $date_range;

	}

	function set_rtype($rtype){

		// Type of report effects the select line
		$this->rtype  = $rtype;
		$this->set_select();
		$this->set_group();


	}
	
	function get_query(){

		// SELECT // bippity
		$this->select = $this->set_select();

		// WHERE //  boppity
		$this->where = $this->set_where();

		// GROUP //  boo
		$this->group = $this->set_group();

		// Put it together, what have you got? // bippity boppity boo!

		// sql currently turns out to be WHERE AND - fix it! 06/28/18
		$string = $this->select . " WHERE " . $this->where . $this->group;

		return $string;

	}

	function get_ticket($ticket_id){

		$sql="SELECT 
			ticket.ticket_id,
                        number,
                        firstname,
                        lastname,
                        subject,
                        orgs.name AS orgname,
                        statuses.name AS statusname,
			UNIX_TIMESTAMP(ticket.created) AS t_created,
			UNIX_TIMESTAMP(ticket.closed) AS t_closed,
                        users.name AS username
                        FROM ".TICKET_TABLE." ticket
                        LEFT JOIN ".STAFF_TABLE." staff ON staff.staff_id=ticket.staff_id
                        LEFT JOIN ".USER_TABLE." users ON users.id=ticket.user_id
                        LEFT JOIN ".TABLE_PREFIX."organization orgs ON orgs.id=users.org_id
                        LEFT JOIN ".DEPT_TABLE." depts ON depts.id=ticket.dept_id
                        LEFT JOIN ".TABLE_PREFIX."ticket__cdata cdata ON cdata.ticket_id=ticket.ticket_id
                        LEFT JOIN ".TABLE_PREFIX."thread threads ON ticket.ticket_id=threads.object_id AND object_type='T'
                        LEFT JOIN ".TABLE_PREFIX."thread_event myevents ON myevents.thread_id=threads.id
                        LEFT JOIN ".TABLE_PREFIX."ticket_status statuses ON statuses.id=ticket.status_id 
			WHERE ticket.ticket_id=$ticket_id GROUP BY ticket.ticket_id; ";

		$res=db_query($sql);

		return $row = db_fetch_array($res);

	}
	function get_ticket_list($ticket_ids){

		$html = '<table class="subTable" cellspacing=0>';
		foreach ($ticket_ids as $ticket_id){

			$ticket=self::get_ticket($ticket_id);
			$html.='<tr>
				<td>#'.$ticket['number'].'</td>
				<td>'.$ticket['statusname'].'</td>
				<td colspan=3>'.$ticket['subject'].'</td>
				<td>'.$ticket['username'].'</td>
				<td colspan=2>'.$ticket['orgname'].'</td></tr>';
		}
		$html .= '</table>';

		return $html;

	}

	function set_select(){

		$this->where = $this->set_where();

		$select = "SELECT 
		   myevents.id as event_id,
		   users.id as user_id,
		   users.name,
 		   address,
		   staff.firstname,
		   staff.lastname,
		   staff.staff_id,
		   tickets.ticket_id,
		   teams.team_id,
	       teams.name as team,
		   depts.name as dept_name,
		   depts.id as dept_id,
           tickets.topic_id,
		   help_topics.topic, 
		   myevents.timestamp,
		   MONTH(myevents.timestamp) as month,
		   DAY(myevents.timestamp) as day,
		   YEAR(myevents.timestamp) as year,
		    concat(MONTH(myevents.timestamp), '-', YEAR(myevents.timestamp)) as monthYear,
		    concat(DAY(myevents.timestamp), '-', MONTH(myevents.timestamp), '-', YEAR(myevents.timestamp)) as dayMonthYear,
		   UNIX_TIMESTAMP(myevents.timestamp) as epoch,
		   orgs.name as orgname,
           orgs.id   as org_id,
		   ev.name as state,
		   myevents.data,
		   tickets.created,
		   tickets.closed
  		   FROM `".TABLE_PREFIX."thread_event` myevents
		   LEFT JOIN ".TABLE_PREFIX."thread threads
			ON myevents.thread_id=threads.id
		   LEFT JOIN ".TICKET_TABLE." tickets
			ON threads.object_id=tickets.ticket_id
           LEFT JOIN ".TABLE_PREFIX."user users 
			ON users.id=tickets.user_id
           LEFT JOIN ".TABLE_PREFIX."user_email uemails
			ON users.id=uemails.user_id
	       LEFT JOIN ".TABLE_PREFIX."help_topic help_topics
			ON tickets.topic_id=help_topics.topic_id
           LEFT JOIN ".TABLE_PREFIX."team teams
			ON tickets.team_id=teams.team_id
           LEFT JOIN ".TABLE_PREFIX."department depts
			ON tickets.dept_id=depts.id 
           LEFT JOIN ".TABLE_PREFIX."organization orgs
			ON users.org_id=orgs.id
           LEFT JOIN ".TABLE_PREFIX."event ev
            ON myevents.event_id=ev.id
           LEFT JOIN ".TABLE_PREFIX."staff staff
			ON myevents.staff_id=staff.staff_id";

		$string = $select;

		return $string;
	}

	function set_where(){
			
		$date_range=$this->date_range;

		switch ($this->date_range) {

			case 'timePeriod':

				switch ($this->range) {

					case 'today':
						// Query code for today
						switch ($this->rtype) {

							case 'tixPerTeam':
      								$where = " DATE(timestamp) = CURDATE() AND 
									   tickets.team_id!=0 ";
								break;
								
							// All the others
							default:
  								$where = " DATE(timestamp) = CURDATE() ";
								break;
   						}			
						break;

					case 'yesterday':

						switch($this->rtype) {
							case 'tixPerTeam':
      								$where = " DATE(timestamp) =
										DATE_ADD(CURDATE(), INTERVAL -1 DAY) AND
										tickets.team_id!=0 ";
								break;

							default:
  								$where = " DATE(timestamp) = DATE_ADD(CURDATE(), INTERVAL -1 DAY) ";
								break;
						}
						break;
					
					case 'thismonth':
						// Query code for this month
						switch ($this->rtype) {

							case 'tixPerTeam':
      								$where = " YEAR(timestamp) = YEAR(CURDATE()) AND 
									   MONTH(timestamp) = MONTH(CURDATE()) AND 
									   myevents.team_id!=0 ";
							break;

							default:
   								$where = "YEAR(timestamp) = YEAR(CURDATE()) AND 
									  MONTH(timestamp) = MONTH(CURDATE())";
							break;
						}
						break;

					case 'lastmonth':
						// Query code for last month
						switch ($this->rtype) {
					
							case 'tixPerTeam':
      								$where = " YEAR(timestamp) = 
										YEAR(CURDATE()) AND 
										MONTH(timestamp) = 
										MONTH(DATE_ADD(CURDATE(),INTERVAL -1 MONTH)) AND 
										tickets.team_id!=0 ";
								break;

							default:
  								$where = " YEAR(timestamp) = 
										YEAR(CURDATE()) AND 
										MONTH(timestamp) = 
										MONTH(DATE_ADD(CURDATE(),INTERVAL -1 MONTH)) ";
								break;
   }
						break;

					case 'lastthirty':

						switch ($this->rtype) {
								
							case 'tixPerTeam':

      								$where = " timestamp >=
									DATE_ADD(CURDATE(), INTERVAL -30 DAY) AND 
									tickets.team_id!=0 ";

								break;
				
							default:
  								$where = " timestamp >= 
										DATE_ADD(CURDATE(), INTERVAL -30 DAY) ";
								break;
							}
						break;

					// TO DO: Allow for manually assigning what days of the week this is
					case 'thisweek':

						switch ($this->rtype) {

							case 'tixPerTeam':
					
      								$where = " timestamp >=
										DATE_ADD(CURDATE(), INTERVAL(0 - DAYOFWEEK(CURDATE())) DAY)
										 AND timestamp <=
										DATE_ADD(CURDATE(), INTERVAL(6 - DAYOFWEEK(CURDATE())) DAY)
										 AND tickets.team_id!=0 ";
								break;

							default:
  								$where = " timestamp >= 
										DATE_ADD(CURDATE(), INTERVAL(0 - DAYOFWEEK(CURDATE())) DAY)
										 AND timestamp <= 
										DATE_ADD(CURDATE(), INTERVAL(6 - DAYOFWEEK(CURDATE())) DAY)";
								break;

						}

						break;

					// TO DO: Allow for manually assigning what days of the week this is
					case 'lastweek':

						$beg=mktime(0,0,0,date("n"),date("j") - date("N"));
						$beg = $beg - 604800;
						$end=mktime(23,59,59,date("n"),date("j") - date("N") + 6);
						$end = $end - 604800;

						switch ($this->rtype) {

							case 'tixPerTeam':
      								$where = " UNIX_TIMESTAMP(timestamp) >= $beg AND UNIX_TIMESTAMP(timestamp) <= $end AND tickets.team_id!=0 ";
								break;

							default:
   								$where = " UNIX_TIMESTAMP(timestamp) >= $beg AND UNIX_TIMESTAMP(timestamp) <= $end ";
								break;

						}

						break;

					// TO DO: Allow for manually assigning what days of the week this is
					case 'thisbusweek':

						switch ($this->rtype) {

							case 'tixPerTeam':
      								$where = " timestamp >= 
										DATE_ADD(CURDATE(), 
										INTERVAL(2 - DAYOFWEEK(CURDATE())) DAY) AND 
										tickets.created <= 
										DATE_ADD(CURDATE(), 
										INTERVAL(6 - DAYOFWEEK(CURDATE())) DAY) AND 
										tickets.team_id!=0 ";
								break;

							default:
  								$where = " timestamp >= 
										DATE_ADD(CURDATE(), 
										INTERVAL(2 - DAYOFWEEK(CURDATE())) DAY) AND 
										timestamp <= 
										DATE_ADD(CURDATE(), 
										INTERVAL(6 - DAYOFWEEK(CURDATE()) ) DAY)";
								break;

						}

						break;
	
					// TO DO: Allow for manually assigning what days of the week this is
					case 'lastbusweek':

						switch ($this->rtype) {

							case 'tixPerTeam':
      								$where = " timestamp >= 
										DATE_ADD(DATE_ADD(CURDATE(), 
										INTERVAL(2 - DAYOFWEEK(CURDATE()) ) DAY), 
										INTERVAL - 1 WEEK) AND 
										timestamp <= 
										DATE_ADD(DATE_ADD(CURDATE(), 
										INTERVAL(6 - DAYOFWEEK(CURDATE()) ) DAY), 
										INTERVAL - 1 WEEK) AND tickets.team_id!=0 ";
								break;

							default:
  								$where = " timestamp >= 
										DATE_ADD(DATE_ADD(CURDATE(), 
										INTERVAL(2 - DAYOFWEEK(CURDATE()) ) DAY), 
										INTERVAL - 1 WEEK) AND 
										timestamp <= 
										DATE_ADD(DATE_ADD(CURDATE(), 
										INTERVAL(6 - DAYOFWEEK(CURDATE()) ) DAY), 
										INTERVAL - 1 WEEK)";
								break;

						}

						break;

					case 'thisyear':

						switch ($this->rtype) {

							case 'tixPerTeam':
      								$where = " YEAR(timestamp) = 
										YEAR(CURDATE()) AND 
										tickets.team_id!=0 ";
								break;

							default:
  								$where = "YEAR(timestamp) = 
										YEAR(CURDATE()) ";
								break;

						}

						break;

					case 'lastyear':

						switch ($this->rtype) {

							case 'tixPerTeam':
  								$where = " YEAR(timestamp) = 
										YEAR(DATE_SUB(CURDATE(), 
										INTERVAL 1 YEAR)) AND 
										tickets.team_id!=0 ";
								break;

							default:
  								$where = " YEAR(timestamp) = 
										YEAR(DATE_SUB(CURDATE(), 
										INTERVAL 1 YEAR)) "; 
								break;

						}

						break;

					case 'alltime':

   								$where =  "1";

						break;


				}


				break;

			case 'timeRange':

				// Manipulate the time range
				switch ($this->rtype) {

					case 'tixPerTeam':
      						$where = " timestamp >='".$this->from_date." 00:00:00' AND 
								 timestamp<='".$this->to_date." 23:59:59'   AND
								 tickets.team_id!=0 ";
					break;

					default:
      						$where = " timestamp >='".$this->from_date." 00:00:00' AND 
								 timestamp <='".$this->to_date." 23:59:59' ";
					break;
				}
		
				break;

		}
    // Filter
    switch ($this->rtype){

        case 'tixPerTopic':
                if($this->filter!=0 && $this->filter!='999999'){
                    $where.=" AND tickets.topic_id=".$this->filter;
                }elseif($this->filter=='999999'){
                    $where.=" AND tickets.topic_id IS NULL";
                }
        break;

        case 'tixPerClient':
                if($this->filter!=''){
                    $where.=" AND users.id=".$this->filter;
                }
        break;

        case 'tixPerStaff':
                if($this->filter!=0 && $this->filter!='999999'){
                    $where.=" AND staff.staff_id=".$this->filter;
                }elseif($this->filter=='999999'){
                    $where.=" AND staff.staff_id IS NULL";
                }
        break;

        case 'tixPerTeam':
                if($this->filter!=0 && $this->filter!='999999'){
                    $where.=" AND teams.team_id=".$this->filter;
                }elseif($this->filter=='999999'){
                    $where.=" AND teams.team_id IS NULL";
                }
        break;

        case 'tixPerDept':
                if($this->filter!=0 && $this->filter!='999999'){
                    $where.=" AND depts.id=".$this->filter;
                }elseif($this->filter=='999999'){
                    $where.=" AND depts.id IS NULL";
                }
        break;

        case 'tixPerOrg':
                if($this->filter!=0 && $this->filter!='999999'){
                    $where.=" AND orgs.id=".$this->filter;
                }elseif($this->filter=='999999'){
                    $where.=" AND orgs.id IS NULL";
                }
        break;

    }

	// Tickets only!
	$where .= ' AND threads.object_type="T" AND ev.name IN ("created","assigned","closed","overdue","reopened")';
	return $where;
		

	}

	function set_group(){

		switch ($this->rtype) {

			case 'tixPerDept':
    				$group = " ORDER BY depts.name DESC"; 
				break;

			case 'tixPerOrg':
				$group = " ORDER BY orgname"; 
				break;

			case 'tixPerClient':
				$group = " ORDER BY address"; 
				break;

			case 'tixPerTopic':
				$group = " ORDER BY tickets.topic_id";
				break;

			case 'tixPerMonth':
			case 'tixPerDay':
				$group = " ORDER BY timestamp DESC  "; 
				break;

            case 'repliesPerStaff':
			case 'tixPerStaff':
				$group = " ORDER BY staff.lastname "; 
				break;

			case 'tixPerTeam':
				$group = " ORDER BY teams.name DESC"; 
				break;
	
		}

		return $group;

	}

	function set_range($range){

		$this->range = $range;
		$this->set_where();

	}

	function set_query($sql){

		$this->query = $sql;

	}

	function get_report(){

		// $addition is for the sub-rows (Show the actual tickets)

		$sql = $this->get_query();
		// ECHO HERE
		// echo $sql."<br><br>";
		$res = db_query($sql);

		$n=0;
		while($row = db_fetch_array($res)){

			$report_rows[$n]=$row;
			$report_rows[$n]['report_type']=$this->rtype;
			$report_rows[$n]['report_range']=$this->range;
			$days_hours=ucwords($this->resolution);
			$headers=array();

			switch ($this->rtype) {

				case 'tixPerDept':

					$dept_id=$report_rows[$n]['dept_id']==''?0:$report_rows[$n]['dept_id'];
					$addition=" AND ticket.dept_id=".$dept_id;
					array_push($headers,rlang::tr('_department_'));
					break;

				case 'tixPerOrg':

					$report_rows[$n]['org_id']==''?$org_id=0:$org_id=$report_rows['org_id'];
					if($org_id==0){	$addition=" AND orgname IS NULL ";}
					else{ 		$addition=" AND ".TABLE_PREFIX."orgs.id=".$org_id;}
					array_push($headers,rlang::tr('_organization_'));
					break;

				case 'tixPerClient':
					$addition=" AND users.id='".$report_rows['user_id']."'";
					array_push($headers,rlang::tr('_client_'));
					break;

				case 'tixPerTopic':
					$addition=" AND help_topics.topic_id='".$report_rows[$n]['topic_id']."'";
					array_push($headers,rlang::tr('_helptopic_'));
					break;

				case 'tixPerMonth':
					$m=date('m',strtotime($report_rows['timestamp']));	
					$y=date('Y',strtotime($report_rows['timestamp']));	
					$addition=" AND MONTH(timestamp)=$m AND YEAR(timestamp)=$y "; 
					array_push($headers,rlang::tr('_month_'));
					break;

				case 'tixPerDay':
					$d=date('d',strtotime($report_rows['timestamp']));	
					$m=date('m',strtotime($report_rows['timestamp']));	
					$y=date('Y',strtotime($report_rows['timestamp']));	
					$addition=" AND MONTH(timestamp)=$m AND YEAR(timestamp)=$y AND DAY(timestamp)=$d "; 
					array_push($headers,rlang::tr('_day_'));
					break;

				case 'tixPerStaff':
					$report_rows[$n]['staff_id']==''?$report_rows[$n]['staff_id']=0:NULL;
					$addition=" AND ticket.staff_id='".$report_rows[$n]['staff_id']."'";
					array_push($headers,rlang::tr('_agent_'));
					break;

				case 'tixPerTeam':
					$report_rows[$n]['staff_id']==''?$report_rows[$n]['staff_id']=0:NULL;
					$addition=" AND ticket.staff_id=".$report_rows[$n]['staff_id'];
					array_push($headers,rlang::tr('_team_'));
					break;	

			}

			array_push($headers,
					rlang::tr('_created_'),
					rlang::tr('_assigned_'),
					rlang::tr('_overdue_'),
					rlang::tr('_closed_'),
					rlang::tr('_resolved_'),
					rlang::tr('_reopened_'),
					$days_hours.' '.rlang::tr('_toresolutionavg_')
				  );
			$report_rows[$n]['headers']=$headers;
			$total_count_this_row = $report_rows[$n]['number'] +
						$report_rows[$n]['assigned'] +
						$report_rows[$n]['overdue'] +
						$report_rows[$n]['reopened'] +
						$report_rows[$n]['closed'] +
						$report_rows[$n]['resolved'];

			$n++;
		}

		return $report_rows;
		
	}

	public static function delete_scheduled_report($cid){

		$sql = "DELETE FROM ".CONFIG_TABLE." WHERE id='$cid' AND namespace='schedules'";
		if($res = db_query($sql)){
			return true;
		}else{	
			return false;
		}

	}
	public static function set_config($key,$value,$namespace=false,$update=false){

		$ns  = $namespace==true ? $namespace : 'plugin.'.self::get_plugin_id();
		$method = self::get_default($key);

		if($method!='' || $update===true){

			// UPDATE
			$sql = "UPDATE ".CONFIG_TABLE." 
					SET `value`='$value' 
					WHERE `key`='$key' AND
					`namespace`='$ns'";

		}else{

			// INSERT
			$sql = "INSERT INTO ".CONFIG_TABLE." (`key`,`value`,`namespace`) 
					VALUES ('$key','$value','$ns')";

		}

		return db_query($sql);

	}

	public static function get_default($var,$namespace=false){

		$ns  = $namespace==true ? $namespace : 'plugin.'.self::get_plugin_id();
  		$sql = "SELECT value from ".CONFIG_TABLE." WHERE 
					  ".CONFIG_TABLE.".key='$var' AND 
					  namespace='$ns'";

  		$res   = db_query($sql);
  		$value = db_result($res,0);

        	// if in {"value":"key"} format, get value only
        	if(strpos($value, '}')&&$var='range'){
                	$entries=explode('"',$value);
                	$value=$entries[1];
        	}
		$string = $value;

    		return $string;
	}

	// Call this after the last version check
	public static function set_last_version_check($my_version){

		$namespace='plugin.'.self::get_plugin_id();
		$last = self::get_default('version_checked');
		if($last==''){

			$sql="INSERT INTO ".CONFIG_TABLE." 
				(`value`,`key`,`namespace`) VALUES 
				(UNIX_TIMESTAMP(NOW()),'version_checked','$namespace')"; 

		}else{
			// Code to compare now to $last
			$now=date('U');
			$diff=$now - $last;

			$sql="UPDATE ".CONFIG_TABLE." SET `value`=UNIX_TIMESTAMP(NOW())
				WHERE `key`='version_checked' AND namespace='$namespace'";

		}
		$res=db_query($sql);

		return;

	}

	public static function get_last_version_check() {

		return self::get_default('version_checked');

	}

	public static function get_plugin_id(){

		// Find our plugin number
		$sql="SELECT id FROM ".TABLE_PREFIX."plugin WHERE install_path LIKE '%Reports%'";
		$res=db_query($sql);
		$id=db_result($res,0);

		return $id;
	}

	function get_plugin_keys(){

		$plugin='plugin.'.$this->get_plugin_id();

		// Get a list of all keys used by this plugin
		$sql="SELECT `key` FROM ".CONFIG_TABLE." WHERE `namespace`='$plugin'";
		$res=db_query($sql);

		while($row = db_fetch_array($res)){
			$keys[]=$row;
		}

		return $keys;

	}
	
	function create_pdf($html,$quick=false){

		// Modify in place CSS to
		// accomadate portrait page
		$html.='<style>';
		$html.='.sub-section {
				width: 200px;
				font-size: 10px;
				margin-left: 12px;
			}
			.quick_left {
				width: 60px;
			}
			.quick_right {
				width: 110px;
			}
			.section-content {
				border: none;
			}
			.sub-header {
				padding: 10px 0 10px 5px;
			}
			#hor-minimalist-b th,td { 
				padding-right: 28px;
			}
			';

		if($quick==false)
		$html.='#quickstats { display: none; }';

		$html.='</style>';

		// Print to PDF
		require_once ROOT_DIR.'include/class.pdf.php';
		//$mpdf = new mPDF;
        $test=array();
        $mpdf = new mPDFWithLocalImages($test, 'Letter');
		$mpdf->WriteHtml($html);

		$ofile = ROOT_DIR.'scp/'.self::get_default('output_directory').'/report.pdf';
		$mpdf->Output($ofile,'F');

	}

	function create_image($array){

 		/* Create and populate the pData object */
		$MyData = new pData();  
		foreach($array as $row){

			$legend[]=$this->get_legend($row);
			$created_total = $row['cr_count'];
			$assigned_total= $row['as_count'];
			$overdue_total = $row['ov_count'];
			$reopened_total= $row['re_count'];
			$closed_total  = $row['cl_count'];
			$resolved_total= $row['rs_count'];

				$MyData->addPoints($created_total,rlang::tr('_created_'));
				$MyData->addPoints($assigned_total,rlang::tr('_assigned_'));
				$MyData->addPoints($overdue_total,rlang::tr('_overdue_'));
				$MyData->addPoints($reopened_total,rlang::tr('_reopened_'));
				$MyData->addPoints($closed_total,rlang::tr('_closed_'));
				$MyData->addPoints($resolved_total,rlang::tr('_resolved_'));
			}

		$MyData->setAxisName(0,"Tickets");
		$MyData->addPoints($legend,"Legend");
		$MyData->setSerieDescription("Tickets","Tickets");
		$MyData->setAbscissa("Legend");

		/* Create the pChart object */
		$myPicture = new pImage(900,230,$MyData);

		/* Turn of Antialiasing */
		$myPicture->Antialias = FALSE;

		/* Add a border to the picture */
		$myPicture->drawRectangle(0,0,899,229,array("R"=>192,"G"=>192,"B"=>192));

		/* Set the default font */
		$myPicture->setFontProperties(array("FontName"=>PCHARTS."fonts/pf_arma_five.ttf","FontSize"=>10));

		/* Define the chart area */
		$myPicture->setGraphArea(60,40,870,200);

		/* Draw the scale */
		$scaleSettings = array("GridR"=>200,"GridG"=>200,"GridB"=>200,"DrawSubTicks"=>TRUE,"CycleBackground"=>TRUE);
		$myPicture->drawScale($scaleSettings);

		/* Write the chart legend */
		$myPicture->drawLegend(75,12,array("Style"=>LEGEND_NOBORDER,"Mode"=>LEGEND_HORIZONTAL));

		/* Turn on shadow computing */ 
		$myPicture->setShadow(TRUE,array("X"=>1,"Y"=>1,"R"=>0,"G"=>0,"B"=>0,"Alpha"=>10));

		/* Draw the chart */
		$myPicture->setShadow(TRUE,array("X"=>1,"Y"=>1,"R"=>0,"G"=>0,"B"=>0,"Alpha"=>10));
		$settings = array("Gradient"=>TRUE,
					"GradientMode"=>GRADIENT_EFFECT_CAN,
					"DisplayPos"=>LABEL_POS_INSIDE,
					"DisplayValues"=>TRUE,
					"DisplayR"=>255,
					"DisplayG"=>255,
					"DisplayB"=>255,
					"DisplayShadow"=>TRUE,
					"Surrounding"=>10);
		$myPicture->drawBarChart();
		// TODO: Directory needs to be one way for web and another
		// for PDF/email/scheduled
		$img_dir=$this->get_default('helpdesk_url','core')."/scp/".$this->get_default('output_directory')."/";
		$myPicture->Render(ROOT_DIR."/scp/".$this->get_default('output_directory')."/report.png");
		$img='<img id="pchart_image" src="'.$img_dir.'report.png" />';

		return $img;

	}

	function get_legend($row){


			// Print the primary rows
			switch ($row['report_type']){

				case 'tixPerDept':
					$element=$row['dept_name']!=''?$row['dept_name']:'No Dept';
					break;

				case 'tixPerOrg':
					$element=$row['orgname']!=''?$row['orgname']:'No Org';
					break;

				case 'tixPerClient':
					$element=$row['name'];
					break;

				case 'tixPerTopic':
					$element=$row['topic']!=''?$row['topic']:'No Topic';
					break;

				case 'tixPerMonth':
					$element=date('F Y',strtotime($row['dayMonthYear']));
					$element==''?$element=date('F Y',strtotime($row['timestamp'])):NULL;
					break;

				case 'tixPerDay':
					$element=date('d F Y',strtotime($row['dayMonthYear']));
					$element==''?$element=date('d F Y',strtotime($row['timestamp'])):NULL;
					break;

                case 'repliesPerStaff':
				case 'tixPerStaff':
					$element=$row['lastname'] . ' ' . $row['firstname'];
					$element==' '?$element='Unassigned':NULL;
					break;

				case 'tixPerTeam':
					$element=$row['team']!=''?$row['team']:'No Team';
					break;

			}
			return $element;
	}


	function create_report($output){

		$tickets=$this->get_report();

		$t=0;
		$c=count($tickets);
		if($c==0){ $out = "No data for the specified timeframe"; echo $out; return; }

		switch ($output) {

			case 'csv':
		        $separator = $this->separator;
				$begin="\r\n";
				$end=NULL;
			break;

			case 'html':

				// Add styling
				$out.= file_get_contents(REPORTS.'include/css/style.css');
				$out.= file_get_contents(REPORTS.'include/js/reports.js');

				// Show quick stats if they are requested
				$this->quick==1?require_once(REPORTS.'include/quickstats.php'):NULL;
				$separator="</td><td>";
				$end="</td></tr>";
			break;
	
		}
    		$r=rand(0,getrandmax());
			switch ($output) {
		
				case 'html':
					$begin="<tr id='parent_".$r."'><td>";
					break;

				case 'csv':
					break;
			}

			$headers=array();

			// The first key isn't always 0,
			// non report matching tickets were removed (unset)
			$first_key = key($tickets);

			switch ($tickets[$first_key]['report_type']) {

				case 'tixPerDept':
					array_push($headers,rlang::tr('_department_'));
					$groupby='dept_id';
					break;

				case 'tixPerOrg':
					array_push($headers,rlang::tr('_organization_'));
					$groupby='orgname';
					break;

				case 'tixPerClient':
					array_push($headers,rlang::tr('_client_'));
					$groupby='user_id';
					break;

				case 'tixPerTopic':
					array_push($headers,rlang::tr('_helptopic_'));
					$groupby='topic_id';
					break;

				case 'tixPerMonth':
					array_push($headers,rlang::tr('_month_'));
					$groupby='monthYear';
					break;

				case 'tixPerDay':
					array_push($headers,rlang::tr('_day_'));
					$groupby='dayMonthYear';
					break;

                case 'repliesPerStaff':
				case 'tixPerStaff':
					array_push($headers,rlang::tr('_agent_'));
					$groupby='staff_id';
					break;

				case 'tixPerTeam':
					array_push($headers,rlang::tr('_team_'));
					$groupby='team_id';
					break;	

			}

		array_push($headers,
				rlang::tr('_created_'),
				rlang::tr('_assigned_'),
				rlang::tr('_overdue_'),
				rlang::tr('_reopened_'),
				rlang::tr('_closed_'),
				rlang::tr('_resolved_'),
				$days_hours.' '.rlang::tr('_timetoresolutionavg_')
	  );

		// Print the header row
		if($t<1){

			switch ($output) {
					
				case 'html':
					$out.="<table id='hor-minimalist-b'>";
					$out.="<tr id='parent_".$r."'><th>" . implode('</th><th>',$headers)."</th></tr>";
					break;

				case 'csv':
					echo implode($this->separator,$headers).PHP_EOL;
					break;
			}
		}

		// Row by row
		$ids=array();
		$events=array();
		$s=0;
		foreach($tickets as $ticket){
			
		// If this is a custom range
		switch ($this->date_range){

				case 'timeRange':
					$to_month=substr($_POST['toDate'],5,2);
					$to_year=substr($_POST['toDate'],0,4);
					$to_day=substr($_POST['toDate'],8,2);
					$from_month=substr($_POST['fromDate'],5,2);
					$from_year=substr($_POST['fromDate'],0,4);
					$from_day=substr($_POST['fromDate'],8,2);
					$beg=strtotime("$from_month/$from_day/$from_year");
					$end=strtotime("$to_month/$to_day/$to_year");
					$end = $end + 86399;
				break;

				case 'timePeriod':

				// If this is a pre-defined range
				switch ($ticket['report_range']){

							case 'thisyear':
								$beg=strtotime("1/1/".date('Y'));
								$end=strtotime("12/31/".date('Y'));
								$end = $end + 86399;
								break;
							case 'lastyear':
								$beg=strtotime("1/1/".date('Y',strtotime("1 year ago")));
								$end=strtotime("12/31/".date('Y',strtotime("1 year ago")));
								$end = $end + 86399;
								break;
							case 'thismonth':
								$beg=strtotime(date('m')."/1/".date('Y'));
								$end=strtotime(date('m')."/".date('t')."/".date('Y'));
								$end=$end + 86399;
								break;
							case 'lastmonth':
								$beg=strtotime(date('m',
										strtotime("1 month ago"))."/1/".date('Y',
										strtotime("1 month ago")));
								$end=strtotime(date('m',
										strtotime("1 month ago"))."/".date('t',
										strtotime("1 month ago"))."/".date('Y',
										strtotime("1 month ago")));
								$end=$end + 86399;
								break;
							case 'lastthirty':
								$end=mktime(23,59,59,date("n"),date("j") - 1,date("Y"));
								$beg=$end - 2592000;
								break;
							case 'thisweek':
								$beg=mktime(0,0,0,date("n"),date("j") - date("N"));
								$end=mktime(23,59,59,date("n"),date("j") - date("N") + 6);
								break;
							case 'lastweek':
								// Same as 'thisweek' then subtract a week
								$beg=mktime(0,0,0,date("n"),date("j") - date("N"));
								$beg = $beg - 604800;
								$end=mktime(23,59,59,date("n"),date("j") - date("N") + 6);
								$end = $end - 604800;
								break;
							case 'lastbusweek':
								$beg=mktime(0,0,0,date("n"),date("j") - date("N") + 1);
								$beg=$beg - 604800;
								$end=mktime(23,59,59,date("n"),date("j") - date("N") + 5);
								$end=$end - 604800;
								break;
							case 'thisbusweek':
								$beg=mktime(0,0,0,date("n"),date("j") - date("N") + 1);
								$end=mktime(23,59,59,date("n"),date("j") - date("N") + 5);
								break;
							case 'today':
								$beg=mktime(0,0,0,date("n"),date("j"),date("Y"));
								$end=mktime(23,59,59,date("n"),date("j"),date("Y"));
								break;
							case 'yesterday':
								$beg=mktime(0,0,0,date("n"),date("j") - 1,date("Y"));
								$end=mktime(23,59,59,date("n"),date("j") - 1,date("Y"));
								break;
							case 'alltime':
								$beg=mktime(0,0,0,1,1,1970);
								$end=9999999999999999999999;
								break;

			}
			break;

			} // end switch for date_range
			// If it's in range then add it
			$epoch=$ticket['epoch'];
			if(($epoch > $beg && $epoch < $end)){
				$events[]=$ticket;
			}
			if($s==0 && basename($_SERVER['PHP_SELF'])=='scheduling.php'){
				$starting = date("F d, Y @ H:i:s", substr($beg, 0, 10));
				$ending = date("F d, Y @ H:i:s", substr($end, 0, 10));
				echo '<div style="text-align: right; margin-top: 5px; margin-right: 15px;">Showing ticket events triggered between '.$starting.' and '.$ending.'</div>';
				$s++;
			}

		}


		// Ok we have them filtered via time (report_range)
		// now get via type (report_type)
		foreach ($events as $event){

			$id=$event['event_id'];
			switch ($event['report_type']) {

				case 'tixPerDept':
					$dept_id=$event['dept_id'];
					$KEEP[$dept_id][]=$event;
				break;
			
				case 'tixPerTeam':
					$team_id=$event['team_id'];
					$KEEP[$team_id][]=$event;
				break;

				case 'tixPerOrg':
					$org_id=$event['org_id'];
					$KEEP[$org_id][]=$event;
				break;

				case 'tixPerClient':
					$user_id=$event['user_id'];
					$KEEP[$user_id][]=$event;
				break;

				case 'tixPerTopic':
					$topic_id=$event['topic_id'];
					$KEEP[$topic_id][]=$event;
				break;

                case 'repliesPerStaff':
				case 'tixPerStaff':
					$staff_id=$event['staff_id'];
					$KEEP[$staff_id][]=$event;
				break;

				case 'tixPerDay':
					$dayMonthYear=$event['dayMonthYear'];
					$KEEP[$dayMonthYear][]=$event;
				break;

				case 'tixPerMonth':
					$monthYear=$event['monthYear'];
					$KEEP[$monthYear][]=$event;
				break;
			}

		}

		$b=$cr_count=$re_count=$ov_count=$as_count=$cl_count=$rs_count=$total_seconds=$time_to_res=0;
		foreach ($KEEP as $item) {

//echo '<pre>'; print_r($item); echo '</pre>';
			$tix=$cr_tix=$re_tix=$rs_tix=$ov_tix=$as_tix=$cl_tix=array();
			foreach ($item as $k => $v) {
//echo '<pre>'; print_r($tix); echo '</pre>';

				$name  = self::get_legend($v);
				$state = $v['state'];
				$data  = mb_substr($v['data'],0,12);

				switch ($state) {

					case 'created':
						$cr_count++;
						$cr_tix[]=$v['ticket_id'];
						$calc=0;
					break;
					case 'reopened':
						$re_count++;
						$re_tix[]=$v['ticket_id'];
						$calc=0;
					break;
					case 'assigned':
						$as_count++;
						$as_tix[]=$v['ticket_id'];
						$calc=0;
					break;
					case 'overdue':
						$ov_count++;
						$ov_tix[]=$v['ticket_id'];
						$calc=0;
					break;
					
				}
				switch ($data) {

                    // Resolved
					case '{"status":[2':
						$rs_count++;
						$rs_tix[]=$v['ticket_id'];
						$calc=1;
					break;

                    // Closed
					case '{"status":[3':
						$cl_count++;
						$cl_tix[]=$v['ticket_id'];
						$calc=1;
					break;
				

				}

				if($calc==1){
					// Only record how many seconds it took to resolve
					// once per ticket involved
					if(!in_array($v['event_id'],$tix)){
						$tix[]=$v['event_id'];
						$created=strtotime($v['created']);
						$closed =strtotime($v['closed']);
						$secs_to_closure=$closed - $created;
						$total_seconds=$secs_to_closure + $total_seconds;
					}else{ echo $v['event_id'].' already in $tix<br>'; }
				}


			}

		$tix=array_unique($tix);
		if($total_seconds!=0){
			$time_to_res 			= $total_seconds / count($tix);
		}else{
			$time_to_res = 0;
		}
		// Reset total seconds so the next row starts at 0
		$total_seconds=0;
		$image_rows[$b]['name'] 	= $name;
      	$image_rows[$b]['report_type']  = $v['report_type'];
		$image_rows[$b]['dept_name']    = $v['dept_name'];
		$image_rows[$b]['timestamp']    = $v['timestamp'];
		$image_rows[$b]['orgname']      = $v['orgname'];
		$image_rows[$b]['topic']        = $v['topic'];
		$image_rows[$b]['team']    = $v['team'];
		$image_rows[$b]['firstname']    = $v['firstname'];
		$image_rows[$b]['lastname']     = $v['lastname'];
		$image_rows[$b]['cr_count']	= $cr_count;
		$month				= substr($v['timestamp'],5,2);
		$year				= substr($v['timestamp'],0,4);
		$day				= substr($v['timestamp'],8,2);
		$image_rows[$b]['dayMonthYear']	= $day."-".$month."-".$year;
		$image_rows[$b]['as_count']	= $as_count;
		$image_rows[$b]['ov_count']	= $ov_count;
		$image_rows[$b]['re_count']	= $re_count;
		$image_rows[$b]['cl_count']	= $cl_count;
		$image_rows[$b]['rs_count']	= $rs_count;

        switch ($output) {

        case 'csv':
    
            $out.="$name".$this->separator.
                    "$cr_count".$this->separator.
                    "$as_count".$this->separator.
                    "$ov_count".$this->separator.
                    "$re_count".$this->separator.
                    "$cl_count".$this->separator.
                    "$rs_count".$this->separator.
                    self::duration($time_to_res).PHP_EOL;

        break;
        case 'html':
		$out.='<tr id="MyRow_'.$b.'"><td>'.$name.'</td>
			<td id="MyCreatedRow_'.$b.'" data-query="'.base64_encode(json_encode($cr_tix)).'">'.$cr_count.'</td>
			<td id="MyAssignedRow_'.$b.'" data-query="'.base64_encode(json_encode($as_tix)).'">'.$as_count.'</td>
			<td id="MyOverdueRow_'.$b.'" data-query="'.base64_encode(json_encode($ov_tix)).'">'.$ov_count.'</td>
			<td id="MyReopenedRow_'.$b.'" data-query="'.base64_encode(json_encode($re_tix)).'">'.$re_count.'</td>
			<td id="MyClosedRow_'.$b.'" data-query="'.base64_encode(json_encode($cl_tix)).'">'.$cl_count.'</td>
			<td id="MyResolvedRow_'.$b.'" data-query="'.base64_encode(json_encode($rs_tix)).'">'.$rs_count.'</td>
			<td id="MyResolutionRow_'.$b.'" >'.self::duration($time_to_res).'</td></tr>';
        break;
        }
		$b++;

		// Sum totals
		$t_cr_count=$t_cr_count + $cr_count;
		$t_as_count=$t_as_count + $as_count;
		$t_ov_count=$t_ov_count + $ov_count;
		$t_cl_count=$t_cl_count + $cl_count;
		$t_rs_count=$t_rs_count + $rs_count;
		$t_re_count=$t_re_count + $re_count;

		$cr_count=$re_count=$ov_count=$as_count=$cl_count=$rs_count=0;
		}

        switch ($output) {

        case 'html':
		$out.="<tr class='totalRow'><td>".rlang::tr('_totals_')."</td>
			<td>$t_cr_count</td>
			<td>$t_as_count</td>
			<td>$t_ov_count</td>
			<td>$t_re_count</td>
			<td>$t_cl_count</td>
			<td>$t_rs_count".'</td><td></td></tr></table>';
        break;

        case 'csv':

            $out.=rlang::tr('_totals_').$this->separator.
                            $t_cr_count.$this->separator.
                            $t_as_count.$this->separator.
                            $t_ov_count.$this->separator.
                            $t_re_count.$this->separator.
                            $t_cl_count.$this->separator.
                            $t_rs_count.PHP_EOL;

            break;
        }

	$out.=$this->make_image==1?$this->create_image($image_rows):NULL;
	echo $out;
	}

	function duration($seconds, $max_periods = 6){

    		$periods = array("Y" => 31536000, 
				 "M" => 2419200, 
				 "w" => 604800, 
				 "d" => 86400, 
				 "h" => 3600, 
				 "m" => 60, 
				 "s" => 1);

    		$i = 1;
    		foreach ( $periods as $period => $period_seconds ){

	        $period_duration = floor($seconds / $period_seconds);
        	$seconds = $seconds % $period_seconds;
        
		if ( ( $period_duration == 0 ) && ( $period != 's' ))  continue;
        	$duration[] = "{$period_duration}{$period}" . ($period_duration > 1 ? '' : '');
        	$i++;
        	if ( $i >  $max_periods ) break;
    		}

		$return = implode(' ', $duration);
		$return = $duration[0].' '.$duration[1].' '.$duration[2].' '.$duration[3];

		return $return;

        }

	public static function printR($arrayOrObject){

		echo '<pre>';
		print_r($arrayOrObject);
		echo '</pre>';

	}
	function array_sort($array, $on, $order=SORT_ASC)
{
    $new_array = array();
    $sortable_array = array();

    if (count($array) > 0) {
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $k2 => $v2) {
                    if ($k2 == $on) {
                        $sortable_array[$k] = $v2;
                    }
                }
            } else {
                $sortable_array[$k] = $v;
            }
        }

        switch ($order) {
            case SORT_ASC:
                asort($sortable_array);
            break;
            case SORT_DESC:
                arsort($sortable_array);
            break;
        }

        foreach ($sortable_array as $k => $v) {
            $new_array[$k] = $array[$k];
        }
    }

    return $new_array;
}

function checkKey($key,$array){

	if(array_key_exists($key,$array)){
		return 1;
	}else{
		return 0;
	}


}

function report_log($text){

	$report_log = ROOT_DIR.'scp/'.$this->get_default('output_directory').'/report.log';
	$timestamp=date('r');
	$text=$timestamp.PHP_EOL.' - '.$text;
	file_put_contents($report_log, $text.PHP_EOL, FILE_APPEND);

}

}
