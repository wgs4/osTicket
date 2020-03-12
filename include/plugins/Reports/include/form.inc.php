<?php
ob_start();
var_dump(class_exists('RRule\not_empty'));
$loaded = ob_get_clean();
if($loaded==false){
require_once('RRULE/src/RRule.php');
require_once('RRULE/src/RRuleInterface.php');
}

	$thispage=basename($_SERVER['PHP_SELF']);
	switch ($thispage){

		case 'reports.php':
			$pdf_req=false;
			break;

		case 'scheduling.php':
			$pdf_req=true;

			$report_info = array(db_input($_POST['type']),
					     	     db_input($_POST['range']),
					    	     db_input($_POST['dateRange']),
						         db_input($_POST['fromDate']),
						         db_input($_POST['toDate']));

		if (isset($_POST['deletions'])) 
		{
		 $to_delete=$_POST['deletions'];
		foreach($to_delete as $cid){
		 $msg=Report::delete_scheduled_report($cid)?rlang::tr('_scheduled_report_successfully_deleted_'):NULL;
		}
			}			
			break;
		
	}

	$post_type=$report->rtype;
	$post_range=$report->range;
	$post_dateRange=$report->date_range;

?>
<?php if($msg!=NULL){ ?>
<div id="msg_notice"><?php echo $msg; ?></div>
<?php } ?>
<div class='section-header'>
<span class='section-title'><?php echo rlang::tr('_reportcriteria_'); ?></span>
</div>
<div class='section-content'>

<form method="POST" name="reportForm" > 
<table name="formTable_schedule" id='scheduleForm'>
<?php 

csrf_token(); 
if($thispage=='scheduling.php'){

	require_once('../include/plugins/Reports/include/form.rrule.php');


} 
?>

<table name="formTable" id='reportForm'>
  <tr>
	  <td><?php echo rlang::tr('_selectdaterange_'); ?></td>
  <td>
   <input type="radio" name="dateRange" value="timePeriod" 
	<?php echo $post_dateRange=='timePeriod'?"selected":NULL;?> checked 
   />
   <select name="range" onclick="document.reportForm.dateRange[0].checked=true" id='range'>
   	<option value="today" 
 		<?php echo $post_range=='today'?"selected":NULL;?>>
		<?php echo rlang::tr('_today_'); ?>
   	</option>
   	<option value="yesterday" 
		<?php echo $post_range=='yesterday'?"selected":NULL;?>>
		<?php echo rlang::tr('_yesterday_'); ?>
   	</option>
    	<option value="thismonth" 
		<?php echo $post_range=='thismonth'?"selected":NULL;?>>
		<?php echo rlang::tr('_thismonth_'); ?>
    	</option>
    	<option value="lastmonth" 
		<?php echo $post_range=='lastmonth'?"selected":NULL;?>>
		<?php echo rlang::tr('_lastmonth_'); ?>
	</option>
    	<option value="lastthirty" 
		<?php echo $post_range=='lastthirty'?"selected":NULL;?>>
		<?php echo rlang::tr('_lastthirty_'); ?>
	</option>
    	<option value="thisweek" 
		<?php echo $post_range=='thisweek'?"selected":NULL;?>>
		<?php echo rlang::tr('_thisweek_'); ?>
	</option>
    	<option value="lastweek" 
		<?php echo $post_range=='lastweek'?"selected":NULL;?>>
		<?php echo rlang::tr('_lastweek_'); ?>
	</option>
    	<option value="thisbusweek" 
		<?php echo $post_range=='thisbusweek'?"selected":NULL;?>>
		<?php echo rlang::tr('_thisbusinessweek_'); ?>
	</option>
    	<option value="lastbusweek" 
		<?php echo $post_range=='lastbusweek'?"selected":NULL;?>>
		<?php echo rlang::tr('_lastbusinessweek_'); ?>
	</option>
    	<option value="thisyear" 
		<?php echo $post_range=='thisyear'?"selected":NULL;?>>
		<?php echo rlang::tr('_thisyear_'); ?>
	</option>
    	<option value="lastyear" 
		<?php echo $post_range=='lastyear'?"selected":NULL;?>>
		<?php echo rlang::tr('_lastyear_'); ?>
	</option>
    	<option value="alltime" 
		<?php echo $post_range=='alltime'?"selected":NULL;?>>
		<?php echo rlang::tr('_alltime_'); ?>
	</option>
   </select>
  </td>
  <td>
	<input type="radio" name="dateRange" value="timeRange" 
		<?php echo $post_dateRange=='timeRange'?"checked":NULL;?>
	/>
  </td>
  <td>
	<?php echo rlang::tr('_from_'); ?> 
	<input type="text" name="fromDate" id='fromDate' 
		value="<?php echo $_POST['fromDate']!=''?$_POST['fromDate']:date("Y-m-d");?>" 
		onclick="document.reportForm.dateRange[1].checked=true"
	/>
      	<?php echo rlang::tr('_to_'); ?> 
	<input type="text" name="toDate" id='toDate' 
		value="<?php echo $_POST['toDate']!=''?$_POST['toDate']:date("Y-m-d");?>"     
		onclick="document.reportForm.dateRange[1].checked=true"
	/>
  </td>
 </tr>
 <tr>
 <td><?php echo rlang::tr('_reporttype_'); ?></td>
  <td>
      <select name="type" id='reportSelect'>
      	<option value="tixPerDept" 
		<?php echo $post_type=='tixPerDept'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperdepartment_'); ?>
	</option>
      	<option value="tixPerTeam" 
		<?php echo $post_type=='tixPerTeam'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperteam_'); ?>
	</option>
      	<option value="tixPerDay" 
		<?php echo $post_type=='tixPerDay'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperday_'); ?>
	</option>
      	<option value="tixPerMonth" 
		<?php echo $post_type=='tixPerMonth'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketspermonth_'); ?>
	</option>
      	<option value="tixPerStaff" 
		<?php echo $post_type=='tixPerStaff'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperagent_'); ?></option>
      	<option value="tixPerTopic" 
		<?php echo $post_type=='tixPerTopic'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperhelptopic_'); ?>
	</option>
      	<option value="tixPerClient" 
		<?php echo $post_type=='tixPerClient'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperclient_'); ?>
	</option>
<!--
      	<option value="repliesPerStaff" 
		<?php //echo $post_type=='repliesPerStaff'?"selected":NULL;?>>
		<?php //echo rlang::tr('_repliesperstaff_'); ?>
	</option>                                                            
-->    
  	<option value="tixPerOrg" 
		<?php echo $post_type=='tixPerOrg'?"selected":NULL;?>>
		<?php echo rlang::tr('_ticketsperorganization_'); ?>
	</option>                                                            
   </select>
  </td>
  <td><input type='checkbox' name='generate_chart' id='chart_checkbox' <?php echo ($report->get_default('generate_chart')==1||$_POST['generate_chart']=='on')?'checked':NULL;?>/></td><td><?php echo rlang::tr('_generate_chart_'); ?></td>
 </tr>
 <tr>
  <td>
    <?php echo rlang::tr('_filter_'); ?> (<?php echo rlang::tr('_optional_'); ?>)
  </td>
  <td>
    <select id='report_filter' name='report_filter'>
        <option value=''>-- <?php echo rlang::tr('_nofilter_'); ?> --</option>
    </select>
  </td>
  <td><input type='checkbox' name='generate_csv' id='csv_checkbox' 
		<?php
			// if post generate_csv is on
			if($_POST['generate_csv']=='on'){ echo 'checked'; }

			// if post generate_csv is off
			if(isset($_POST) && !isset($_POST['generate_csv']) && $report->csv==true){ echo 'checked'; }
		?>
	/>
    </td><td><?php echo rlang::tr('_generate_csv_'); ?></td>
 </tr>
  <tr>
	<td><?php echo rlang::tr('_email_').' ('.rlang::tr('_optional_').')'; ?></td>
	<td>
	<input type='text' name='email' id='emailInput'
		value='<?php echo $report->get_default('default_email'); ?>'
	/>
    </td>
	<td><input type='checkbox' name='generate_pdf' id='pdf_checkbox' 
		<?php
			// if post generate_csv is on
			if($_POST['generate_pdf']=='on'){ echo 'checked'; }

			// if post generate_pdf is off
			if(isset($_POST) && !isset($_POST['generate_pdf']) && $report->pdf==true){ echo 'checked'; }

			if($pdf_req==true){ echo 'checked="checked" disabled="disabled"'; }
		?>
	    />
	</td>
    <td><?php echo rlang::tr('_generate_pdf_'); ?></td></tr>
 <tr>
  <td align="right" colspan="4">
   <input type="submit" name="submit" class="button"/>
   <input type="reset" name="reset" class='button'/>
  </td>
 </tr>
</table>
</form>
</div>
<?php if($thispage=='scheduling.php'){?>
<div class='section-header'><span class='section-title'><?php echo rlang::tr('_scheduled_reports_'); ?></span></div>
<div class='section-content'>
<!-- Get a list of currently scheduled reports -->
	<?php


		$sql="SELECT * FROM ".CONFIG_TABLE." WHERE namespace='schedules' ORDER BY updated DESC";
		$res=db_query($sql);
		$num=db_num_rows($res);
		if($num==0){
            echo rlang::tr('_no_scheduled_reports_');
		}

	while($row = db_fetch_array($res)){
		$cid  = $row['id'];
		$name = $row['key'];
		$schedule_array=(array) json_decode($row['value']);
		// Remove the report information as it can't be read by rrule
		unset($schedule_array['REPORT_TYPE']);
		unset($schedule_array['REPORT_RANGE']);
		unset($schedule_array['FILTER']);
		unset($schedule_array['PERIOD_RANGE']);
		unset($schedule_array['EMAIL']);
		unset($schedule_array['RANGE']);
		unset($schedule_array['FROMDATE']);
		unset($schedule_array['TODATE']);
		unset($schedule_array['lastrun']);
		$rrule = new RRule\RRule($schedule_array);
		$n=count($rrule);
		switch($n){

			case $n<10:
				$i=$n;
				$first=NULL;
				$ntext=$n.' '.rlang::tr('_runs_');
			break;

			case $n>10 && $n<200:
				$i=10;
				$first=rlang::tr('_first_');
				$ntext=$n;
			break;

			case $n>200 && $n<1000:
				$i=10;
				$first=rlang::tr('_first_');
				$ntext=rlang::tr('_hundreds_of_').' '.rlang::tr('_runs_');
			break;

			case $n>2000 && $n<10000:
				$i=10;
				$first=rlang::tr('_first_');
				$ntext=rlang::tr('_thousands_of_').' '.rlang::tr('_runs_');
			break;

			case $n>20000:
				$i=10;
				$first=rlang::tr('_first_');
				$ntext=rlang::tr('_tens_of_thousands_or_more_').' '.rlang::tr('_runs_');
			break;


			default:
				$i=$n;
				$first=NULL;
				$ntext=$n;
			break;

		}

	?>


<div>
<form method="POST" name="scheduled_reports" > 
<?php csrf_token(); ?>
    <fieldset class="majorpoints">
    <div id='report_name'>
    <input type='checkbox' value='<?php echo $cid; ?>' name='deletions[]' /> <?php echo $name; ?></div>
    <div class="hiders" style="display:none" >
		<div><?php echo "Showing $first $i of $ntext"; ?></div>
        <ul>
	<?php 
		$k=1;
 		foreach ($rrule as $occurrence ) {
			if($k<=$i){
			echo '<li>'.$occurrence->format("F d, Y (l) @ Hi").'</li>';
			}
			$k++;
 		}
	?>
        </ul>
    </div>
</div>
<?php }
if($n>0){ ?>
    <input type='submit' name='deletion_submit' value='<?php echo rlang::tr('_delete_checked_'); ?>' />
<?php } ?>
</form> <!-- End deletions -->
<script>
$('.majorpoints').click(function(){
    $(this).find('.hiders').toggle();
});
</script>
</div>
<?php } ?>
