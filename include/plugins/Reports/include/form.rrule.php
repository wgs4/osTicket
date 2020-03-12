<?php
if(isset($_POST['freq'])){

// Report::printR($_POST);

// Start with a basic schedule so it doesn't complain
$schedule_array=array('DTSTART' => $_POST['dtstart'],
			'FREQ' => $_POST['freq']);

switch ($_POST['freq']) {

	case 'daily':

		switch($_POST['event-recurring']){

			case 'yes':

				switch($_POST['end-select']){

					case 'count':
						$schedule_array['COUNT'] 	= $_POST['count'];
						break;

					case 'until':
						$schedule_array['UNTIL'] 	= $_POST['until'];
						break;
				}
				$schedule_array['INTERVAL'] 	= $_POST['interval'];
				break;

			case 'no':
				$schedule_array['COUNT'] = 1;
				break;
		}
		break;

	case 'weekly':
			switch($_POST['end-select']){

				case 'count':
					$schedule_array['COUNT'] 	= $_POST['count'];
					break;

				case 'until':
					$schedule_array['UNTIL'] 	= $_POST['until'];
					break;
			}
			if(isset($_POST['sunday_on'])){    $schedule_array['BYDAY'][] = 'SU'; }
			if(isset($_POST['monday_on'])){    $schedule_array['BYDAY'][] = 'MO'; }
			if(isset($_POST['tuesday_on'])){   $schedule_array['BYDAY'][] = 'TU'; }
			if(isset($_POST['wednesday_on'])){ $schedule_array['BYDAY'][] = 'WE'; }
			if(isset($_POST['thursday_on'])){  $schedule_array['BYDAY'][] = 'TH'; }
			if(isset($_POST['friday_on'])){    $schedule_array['BYDAY'][] = 'FR'; }
			if(isset($_POST['saturday_on'])){  $schedule_array['BYDAY'][] = 'SA'; }
			$schedule_array['INTERVAL'] 	= $_POST['interval'];
		break;

	case 'monthly':
			switch($_POST['end-select']){

				case 'count':
					$schedule_array['COUNT'] 	= $_POST['count'];
					break;

				case 'until':
					$schedule_array['UNTIL'] 	= $_POST['until'];
					break;
			}
			if(isset($_POST['saturday_on'])){  $schedule_array['BYDAY'][] = 'SA'; }
			$schedule_array['INTERVAL'] 	= $_POST['interval'];
		break;

	case 'yearly':
			switch($_POST['end-select']){

				case 'count':
					$schedule_array['COUNT'] 	= $_POST['count'];
					break;

				case 'until':
					$schedule_array['UNTIL'] 	= $_POST['until'];
					break;
			}
			if(isset($_POST['january_on'])){  $schedule_array['BYMONTH'][] = '1'; }
			if(isset($_POST['february_on'])){  $schedule_array['BYMONTH'][] = '2'; }
			if(isset($_POST['march_on'])){  $schedule_array['BYMONTH'][] = '3'; }
			if(isset($_POST['april_on'])){  $schedule_array['BYMONTH'][] = '4'; }
			if(isset($_POST['may_on'])){  $schedule_array['BYMONTH'][] = '5'; }
			if(isset($_POST['june_on'])){  $schedule_array['BYMONTH'][] = '6'; }
			if(isset($_POST['july_on'])){  $schedule_array['BYMONTH'][] = '7'; }
			if(isset($_POST['august_on'])){  $schedule_array['BYMONTH'][] = '8'; }
			if(isset($_POST['september_on'])){  $schedule_array['BYMONTH'][] = '9'; }
			if(isset($_POST['october_on'])){  $schedule_array['BYMONTH'][] = '10'; }
			if(isset($_POST['november_on'])){  $schedule_array['BYMONTH'][] = '11'; }
			if(isset($_POST['december_on'])){  $schedule_array['BYMONTH'][] = '12'; }
			$schedule_array['BYMONTHDAY']=$_POST['day-of-month'];
			$schedule_array['INTERVAL'] 	= $_POST['interval'];
		break;

} // End freq switch
$HOURMIN=$_POST['runtime'];
$HOUR=ltrim(substr($HOURMIN,0,2),0);
if($HOUR=='00' || $HOUR==NULL){ $HOUR='0'; }
$MIN=ltrim(substr($HOURMIN,3,2),0);
if($MIN=='00' || $MIN==NULL){ $MIN='0'; }
$schedule_array['BYHOUR']=$HOUR;
$schedule_array['BYMINUTE']=$MIN;
$rrule = new RRule\RRule($schedule_array);

//echo $rrule->humanReadable();

// Lower to 1 for testing
$k=12;
foreach ($rrule as $occurrence ) {
	if($k<11){
		echo '<br>'.$occurrence->format('D Y-m-d');
	}
	$k++;
}
$name=$_POST['rule_name'];
$schedule_array['REPORT_TYPE'] = $_POST['type'];
$schedule_array['REPORT_RANGE'] = $_POST['range'];
$schedule_array['EMAIL'] = $_POST['email'];
$schedule_array['PERIOD_RANGE'] = $_POST['dateRange'];
$schedule_array['FROMDATE'] = $_POST['fromDate'];
$schedule_array['TODATE'] = $_POST['toDate'];
$schedule_array['FILTER'] = $_POST['report_filter'];
$json=json_encode($schedule_array);
Report::set_config($name,$json,'schedules');
}
?>
<form name="rrule-gen" id="rrule">
<input type='text' name='rule_name' style='margin: 10px 0;' required/> <?php echo rlang::tr('_rule_name_alpha_numeric_only_'); ?>
 <div id='recur_start'>
    <div id='recur_left'>
<?php echo rlang::tr('_start_date_'); ?>: <input type="text" class="datepicker" name='dtstart' id="start-date" />
    </div>
    <div id='recur_center'> 
    <label for="runtime"><?php echo rlang::tr('_run_time_'); ?>: </label>
    <input id="runtime" type="time" name="runtime"
           min="00:00" max="23:59" required value="00:30">
    <span class="validity"></span>
  <input type="hidden" name="start-date-formatted" id="start-date-hidden" value="" />
  </div>
  <div id='recur_right'>
<?php echo rlang::tr('_recurring_event_'); ?>?
<input type="radio" name="event-recurring" value="no" checked="checked" /> <?php echo rlang::tr('_no_'); ?>
<input type="radio" name="event-recurring" value="yes" /> <?php echo rlang::tr('_yes_'); ?>
  <p>
 </div>
 </div>

  <div id="recurring-rules" style="display:none;">

<!-- FREQ -->
<p><?php echo rlang::tr('_frequency_'); ?>
    <select name="freq">
        <option value="daily" class="days"><?php echo rlang::tr('_daily_'); ?></option>
        <option value="weekly" class="weeks"><?php echo rlang::tr('_weekly_'); ?></option>
        <option value="monthly" class="months"><?php echo rlang::tr('_monthly_'); ?></option>
        <option value="yearly" class="years"><?php echo rlang::tr('_yearly_'); ?></option>
    </select>
    </p>
    <p><?php echo rlang::tr('_every_'); ?> <input type="text" name="interval" value="1" size="2" /> <span class="freq-selection"><?php echo rlang::tr('_days_'); ?></span>
    </p>

    
<!-- BYWEEKDAY -->
    <div id="weekday-select" class="btn-toolbar weeks-choice" role="toolbar" style="display:none;">
    <h4><?php echo rlang::tr('_which_days_of_the_week_does_this_repeat_on_'); ?>:</h4>
<div class="select-size">
  <input type="checkbox" name="sunday_on" id="sunday" checked/>
  <input type="checkbox" name="monday_on" id="monday" />
  <input type="checkbox" name="tuesday_on" id="tuesday" />
  <input type="checkbox" name="wednesday_on" id="wednesday" />
  <input type="checkbox" name="thursday_on" id="thursday" />
  <input type="checkbox" name="friday_on" id="friday" />
  <input type="checkbox" name="saturday_on" id="saturday" />

  <label for="sunday"><?php echo rlang::tr('_sunday_'); ?></label>
  <label for="monday"><?php echo rlang::tr('_monday_'); ?></label>
  <label for="tuesday"><?php echo rlang::tr('_tuesday_'); ?></label>
  <label for="wednesday"><?php echo rlang::tr('_wednesday_'); ?></label>
  <label for="thursday"><?php echo rlang::tr('_thursday_'); ?></label>
  <label for="friday"><?php echo rlang::tr('_friday_'); ?></label>
  <label for="saturday"><?php echo rlang::tr('_saturday_'); ?></label>

</div>
<div style='clear: both;'></div>
    </div>



<!-- BYMONTH -->
    <div id="bymonth-select" class="btn-toolbar years-choice" role="toolbar" style="display:none;">
    <h4><?php echo rlang::tr('_which_months_of_the_year_'); ?></h4>
      <p><input type="radio" name="yearly-options" id="yearly-multiple-months" checked/> <?php echo rlang::tr('_multiple_months_'); ?></p>
<div class="select-months">
  <input type="checkbox" name="january_on" id="january"/>
  <input type="checkbox" name="february_on" id="february"/>
  <input type="checkbox" name="march_on" id="march"/>
  <input type="checkbox" name="april_on" id="april"/>
  <input type="checkbox" name="may_on" id="may"/>
  <input type="checkbox" name="june_on" id="june"/>
  <input type="checkbox" name="july_on" id="july"/>
  <input type="checkbox" name="august_on" id="august"/>
  <input type="checkbox" name="september_on" id="september"/>
  <input type="checkbox" name="october_on" id="october"/>
  <input type="checkbox" name="november_on" id="november"/>
  <input type="checkbox" name=december"_on" id="december"/>

  <label for="january"><?php echo rlang::tr('_january_'); ?></label>
  <label for="february"><?php echo rlang::tr('_february_'); ?></label>
  <label for="march"><?php echo rlang::tr('_march_'); ?></label>
  <label for="april"><?php echo rlang::tr('_april_'); ?></label>
  <label for="may"><?php echo rlang::tr('_may_'); ?></label>
  <label for="june"><?php echo rlang::tr('_june_'); ?></label>
  <label for="july"><?php echo rlang::tr('_july_'); ?></label>
  <label for="august"><?php echo rlang::tr('_august_'); ?></label>
  <label for="september"><?php echo rlang::tr('_september_'); ?></label>
  <label for="october"><?php echo rlang::tr('_october_'); ?></label>
  <label for="november"><?php echo rlang::tr('_november_'); ?></label>
  <label for="december"><?php echo rlang::tr('_december_'); ?></label>

</div>
<?php echo rlang::tr('_on_day_'); ?>
<select name='day-of-month'>
<?php

	for($c = 1;$c <= 31; $c++){
		echo "<option value='$c'>$c</option>";
	}

?>
    </select> <?php echo rlang::tr('_of_the_month_'); ?>
<div style='clear: both;'></div>

    <p><input type="radio" name="yearly-options" id="yearly-precise" /> <?php echo rlang::tr('_or_be_precise_'); ?></p>
        <select name="yearly-bysetpos" class="yearly-precise" disabled="disabled">
        <option value="1" selected="selected"><?php echo rlang::tr('_First_'); ?></option>
        <option value="2"><?php echo rlang::tr('_Second_'); ?></option>
        <option value="3"><?php echo rlang::tr('_Third_'); ?></option>
        <option value="4"><?php echo rlang::tr('_Fourth_'); ?></option>
        <option value="-1"><?php echo rlang::tr('_Last_'); ?></option>
        </select>
        <select name="yearly-byday" class="yearly-precise" disabled="disabled">
        <option value="SU" selected="selected"><?php echo rlang::tr('_sunday_'); ?></option>
        <option value="MO"><?php echo rlang::tr('_monday_'); ?></option>
        <option value="TU"><?php echo rlang::tr('_tuesday_'); ?></option>
        <option value="WE"><?php echo rlang::tr('_wednesday_'); ?></option>
        <option value="TH"><?php echo rlang::tr('_thursday_'); ?></option>
        <option value="FR"><?php echo rlang::tr('_friday_'); ?></option>
        <option value="SA"><?php echo rlang::tr('_saturday_'); ?></option>
        <option value="SU,MO,TU,WE,TH,FR,SA" selected="selected"><?php echo rlang::tr('_everyday_'); ?></option>
        <option value="MO,TU,WE,TH,FR"><?php echo rlang::tr('_weekdays_'); ?></option>
        <option value="SU,SA"><?php echo rlang::tr('_weekends_'); ?></option>
        </select>
      in <select name="yearly-bymonth-with-bysetpos-byday"  id="yearly-bymonth-with-bysetpos-byday" class="yearly-precise" disabled="disabled">
      <option value="1" selected="selected"><?php echo rlang::tr('_january_'); ?></option>
      <option value="2"><?php echo rlang::tr('_february_'); ?></option>
      <option value="3"><?php echo rlang::tr('_march_'); ?></option>
      <option value="4"><?php echo rlang::tr('_april_'); ?></option>
      <option value="5"><?php echo rlang::tr('_may_'); ?></option>
      <option value="6"><?php echo rlang::tr('_june_'); ?></option>
      <option value="7"><?php echo rlang::tr('_july_'); ?></option>
      <option value="8"><?php echo rlang::tr('_august_'); ?></option>
      <option value="9"><?php echo rlang::tr('_september_'); ?></option>
      <option value="10"><?php echo rlang::tr('_october_'); ?></option>
      <option value="11"><?php echo rlang::tr('_november_'); ?></option>
      <option value="12"><?php echo rlang::tr('_december_'); ?></option>
      </select>
      
    </div>

<!-- BYMONTHDAY -->   
    <div id="monthday-select" class="btn-toolbar months-choice" role="toolbar" style="display:none;">
      <input type="radio" name="monthday-pos-select" value="monthday-selected" id="monthday-selected" checked="checked" /> 
      
      <h4><?php echo rlang::tr('_which_days_of_the_month_does_this_repeat_on_'); ?></h4>
<div class="select-days">

<?php 

for ($c = 1; $c <= 31; $c++){
	echo "<input type='checkbox' name='on_$c' id='month_$c' />";
}
for ($l = 1; $l <= 31; $l++){
  	echo "<label for='month_$l'>$l</label>";
}
?>

</div>

  <!-- BYDAY -->  
      <div>
      <input type="radio" name="monthday-pos-select" value="month-byday-pos-selected" id="month-byday-pos-selected" /> <?php echo rlang::tr('_or_be_precise_'); ?>
        <select name="month-byday-pos" disabled="yes">
            <option value="1" selected="selected"><?php echo rlang::tr('_First_'); ?></option>
            <option value="2"><?php echo rlang::tr('_Second_'); ?></option>
            <option value="3"><?php echo rlang::tr('_Third_'); ?></option>
            <option value="4"><?php echo rlang::tr('_Fourth_'); ?></option>
            <option value="-1"><?php echo rlang::tr('_Last_'); ?></option>
        </select>
        <select name="month-byday-pos-name" disabled="yes">
        <option value="SU"><?php echo rlang::tr('_sunday_'); ?></option>
        <option value="MO"><?php echo rlang::tr('_monday_'); ?></option>
        <option value="TU"><?php echo rlang::tr('_tuesday_'); ?></option>
        <option value="WE"><?php echo rlang::tr('_wednesday_'); ?></option>
        <option value="TH"><?php echo rlang::tr('_thursday_'); ?></option>
        <option value="FR"><?php echo rlang::tr('_friday_'); ?></option>
        <option value="SA"><?php echo rlang::tr('_saturday_'); ?></option>
        <option value="SU,MO,TU,WE,TH,FR,SA" selected="selected"><?php echo rlang::tr('_everyday_'); ?></option>
        <option value="MO,TU,WE,TH,FR"><?php echo rlang::tr('_weekdays_'); ?></option>
        <option value="SU,SA"><?php echo rlang::tr('_weekends_'); ?></option>
        </select>
      </div>
    </div>

    <div id="until-rules" style="display:none;">
    <p><?php echo rlang::tr('_until_'); ?></p>
    <p> <label for="count-select"><input type="radio" name="end-select" value="count" id="count-select" checked="checked"/> <?php echo rlang::tr('_how_many_times_does_this_transaction_occur_'); ?>? 

    <input autocomplete="off" type="number" name="count" min="1" max="50" value="1" step="1"/> <?php echo rlang::tr('_times_'); ?></label>
    </p>
    <p><label for="until-select"><input type="radio" name="end-select" value="until" id="until-select" /> <?php echo rlang::tr('_specific_date_until_'); ?> <input type="text" name="until" id="end-date" disabled="yes" />
    <input type="hidden" name="end-date-formatted" id="end-date-hidden" value="" /></label>
    </p>
    </div>
  </div>
<div class="show-dates">
</div>
<div class="readout">

</div>
<!-- End Recurrence Form -->


                   </form>
	
<script type="text/javascript">
/*
RRULE GENERATOR FORM aka RRULE GUI
MIT License
Copyright (c) 2016 Jordan Roberts
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:
The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/
function readRule( rrule ) {

	rrule = typeof rrule !== 'undefined' ? rrule : '';

	if ( rrule != '') {
	// Break down the rule by semi-colons first
	var items = rrule.split(';');
	var recur = [];
	for(i = 0; i < items.length; i++ ) {
		if ( items[i] !== '' ) {
			temp = items[i].split('=');
		}
		recur[temp[0]] = temp[1];
	}
	console.log(recur);

	// See if the recurring rule has enough valid parts
	if (recur.FREQ && recur.DTSTART && (recur.COUNT || recur.UNTIL)) {
		recurringRule = {
					freq: recur.FREQ,
					dtstart: recur.DTSTART,
					interval: recur.INTERVAL,
					byday: "",
					bysetpos: "",
					bymonthday: "",
					bymonth: "",
					count: "",
					until: ""

		};

	// Set either COUNT or UNTIL
	if( typeof recur.COUNT == 'undefined' && recur.UNTIL ) {
		recurringRule.until = recur.UNTIL;
		
	} else if(typeof recur.UNTIL == 'undefined' && recur.COUNT ) {
		recurringRule.count = recur.COUNT;
	}

	// Set INTERVAL
	$('input[name="interval"]').val(recur.INTERVAL);


    // Setup start-date picker
    	startYear = recur.DTSTART.substring(0,4);
    	startMonth = recur.DTSTART.substring(4,6);
    	startDay = recur.DTSTART.substring(6,8);
    	//alert(startYear + startMonth + startDay);
    $('#start-date').val(startYear + '-' + startMonth + '-' + startDay);
	$('#start-date-hidden').val(startYear+startMonth+startDay + 'T040000z');
    
    // Setup start-date picker
      $( '#start-date' ).datepicker({
      	showOtherMonths: true,
      	selectOtherMonths: true,
      	dateFormat: 'yy-mm-dd',
      	onSelect: function(value) {

								dateSelected = new Date(value + ' 00:00:00');
								dtstartString = dateSelected.getFullYear() + ('0' + (dateSelected.getMonth()+1)).slice(-2) + ('0' + dateSelected.getDate()).slice(-2);
								$('#start-date-hidden').val(dtstartString + 'T040000z');
								recurringRule.dtstart = dtstartString + 'T040000z';
								
								// Set minimum selected date on end-datepicker
									minEndDate = dateSelected.getFullYear() + '-' + ('0' + (dateSelected.getMonth()+1)).slice(-2) + '-' + ('0' + dateSelected.getDate()).slice(-2);
									$( '#end-date' ).datepicker('option', 'minDate', minEndDate);
									// Reset the selected enddate
									$('#end-date-hidden').val(dtstartString + 'T040000z');
									
								}
    	}).datepicker('setDate', 'today');
      
      
      // Setup the end-date picker
      $( '#end-date' ).datepicker({
      	showOtherMonths: true,
      	selectOtherMonths: true,
      	dateFormat: 'yy-mm-dd',
      	onSelect: function(value) {
								dateSelected = new Date(value + ' 00:00:00');
								untilString = dateSelected.getFullYear() + ('0' + (dateSelected.getMonth()+1)).slice(-2) + ('0' + dateSelected.getDate()).slice(-2);
								$('#end-date-hidden').val(untilString + 'T040000z');
								// Remove the count variable
								recurringRule.count = '';
								// Set until variable
								recurringRule.until = untilString + 'T040000z';
								}
    	}).datepicker('setDate', 'today');

      if ( recur.UNTIL ) {
    // Setup end date picker
    	endYear = recur.UNTIL.substring(0,4);
    	endMonth = recur.UNTIL.substring(4,6);
    	endDay = recur.UNTIL.substring(6,8);
    	
   		 $('#end-date').val(endYear + '-' + endMonth + '-' + endDay);
		 $('#end-date-hidden').val(endYear+endMonth+endDay + 'T040000z');

		 	// Set ENDDATE radio to yes
			$('input[name="end-select"]').prop('checked', true);
		}

	// Set Recurring event radio to yes
	$('input[name="event-recurring"]').prop('checked', true);
	
	// Show Recurring rules	
	$('#recurring-rules').slideDown();

	// Show Until Rules
	$('#until-rules').show();

	
	switch(recur.FREQ) {

		case("DAILY"):

		break;

		case("WEEKLY"):
			// Selectbox FREQ = monthly
			$('select[name="freq"]').val('weekly');

			// Hide all DIVS
			$('#recurring-rules > div').hide();

			// Show selected DIV
			$('div.' + 'weeks-choice').show();
			$('span.freq-selection').text('week(s)');

			// Show Until / Count Rules
			$('#until-rules').show();
			
			if ( typeof recur.BYDAY !== 'undefined' ) {

				// Split up the individual bymonthdays
				bydays = recur.BYDAY.split(',');

				// Loop through the BYDAYs
				for( v = 0; v < bydays.length; v++ ) {
					console.log(bydays[v]);
					// Set select monthday buttons to active
					$('#weekday-select button[id="'+bydays[v]+'"]').addClass('active')
				}
				recurringRule.byday = recur.BYDAY;
				
				return true;
			}
			
		break;

		case("MONTHLY"):
			// Selectbox FREQ = monthly
			$('select[name="freq"]').val('monthly');

			// Hide all DIVS
			$('#recurring-rules > div').hide();

			// Show selected DIV
			$('div.' + 'months-choice').show();
			$('span.freq-selection').text('month(s)');

			// Show Until / Count Rules
			$('#until-rules').show();
			
			if ( typeof recur.BYMONTHDAY !== 'undefined' ) {

				// Split up the individual bymonthdays
				bymonthdays = recur.BYMONTHDAY.split(',');

				// Loop through the BYMONTHDAYs
				for( v = 0; v < bymonthdays.length; v++ ) {
					console.log(bymonthdays[v]);
					// Set select monthday buttons to active
					$('#monthday-select button[data-day-num="'+bymonthdays[v]+'"]').addClass('active')
				}
				recurringRule.bymonthday = recur.BYMONTHDAY;
				
				return true;
			}

			if ( typeof recur.BYSETPOS !== 'undefined' && typeof recur.BYDAY !== 'undefined'   ) {
				
				// Set Radio Button
				$('input#month-byday-pos-selected').prop('checked', true);

//				alert(recur.BYDAY);
				$('select[name^="month-byday"]').removeAttr('disabled');

				// Set values
				$('select[name="month-byday-pos"]').val(recur.BYSETPOS);
				$('select[name="month-byday-pos-name"]').val(recur.BYDAY);
				

				//Disable day buttons
				$('#monthday-select button').attr('disabled','disabled');
				
				recurringRule.bysetpos = recur.BYSETPOS;
				recurringRule.byday = recur.BYDAY;

				return true;
			}

			
			
		break;

		case("YEARLY"):
			// Selectbox FREQ = monthly
			$('select[name="freq"]').val('yearly');

			// Hide all DIVS
			$('#recurring-rules > div').hide();

			// Show selected DIV
			$('div.' + 'years-choice').show();
			$('span.freq-selection').text('year(s)');

			// Show Until / Count Rules
			$('#until-rules').show();
			
			// BYMONTH and BYMONTHDAY attributes are going to be set
			if ( typeof recur.BYMONTHDAY !== 'undefined' && typeof recur.BYMONTH !== 'undefined' ) {

				// Set Radio Button
				$('input#yearly-one-month').prop('checked', true);

//				alert(recur.BYDAY);
				$('select[name="yearly-bymonth"]').removeAttr('disabled');
				$('select[name="yearly-bymonthday"]').removeAttr('disabled');

				// Set values
				$('select[name="yearly-bymonth"]').val(recur.BYMONTH);
				$('select[name="yearly-bymonthday"]').val(recur.BYMONTHDAY);
				
				
				recurringRule.bymonth = recur.BYMONTH;
				recurringRule.bymonthday = recur.BYMONTHDAY;
				
				return true;
			}

			// Multiple Month Selection
			if ( typeof recur.BYMONTH!== 'undefined' && typeof recur.BYMONTHDAY == 'undefined'   ) {
				// Disable yearly select boxes
				$('select[name^=yearly-').attr('disabled','disabled');
				// Set Radio Button
				$('input#yearly-multiple-months').prop('checked', true);

				// Make buttons active
				$('.yearly-multiple-months button').removeAttr('disabled');
				// Split up the individual bymonthdays
				bymonth = recur.BYMONTH.split(',');

				// Loop through the BYMONTHDAYs
				for( v = 0; v < bymonth.length; v++ ) {
					console.log(bymonth[v]);
					// Set select monthday buttons to active
					$('.yearly-multiple-months button[data-month-num="'+bymonth[v]+'"]').addClass('active')
				}
				recurringRule.bymonth = recur.BYMONTH;

				return true;
			}

			// Precise Yearly Selection
			if ( typeof recur.BYMONTH!== 'undefined' && typeof recur.BYDAY !== 'undefined' && typeof recur.BYSETPOS !== 'undefined' ) {
				// Disable yearly select boxes
				$('select[name^=yearly-').attr('disabled','disabled');

				// Enable the right select
				$('select[class=yearly-precise').removeAttr('disabled');

				// Set Radio Button
				$('input#yearly-precise').prop('checked', true);
				
				// Set select values
				$('select[name="yearly-byday"]').val(recur.BYDAY);
				$('select[name="yearly-bysetpos"]').val(recur.BYSETPOS);

				recurringRule.bymonth = recur.BYMONTH;
				recurringRule.byday = recur.BYDAY;
				recurringRule.bysetpos = recur.BYSETPOS;

				return true;
			}

			
		break;

	}

	}
}
 
	return false;

}

readRule = false;

function resetOptions() {
	
	// Format the date (http://stackoverflow.com/questions/3605214/javascript-add-leading-zeroes-to-date)
	today = new Date();
    MyDateString = today.getFullYear() + ('0' + (today.getMonth()+1)).slice(-2) + ('0' + today.getDate()).slice(-2);

	// Reset all the selected rules
		recurringRule = {
					freq: "daily",
					dtstart: MyDateString + 'T040000z',
					interval: "1",
					byday: "",
					bysetpos: "",
					bymonthday: "",
					bymonth: "",
					count: "",
					until: MyDateString + 'T040000z'

		};

		// Reset all button states and input values
		$('button').removeClass('active');

		// Reset back interval label to 'days'
		$('span.freq-selection').text('days');

		// Hide all but the daily options

		$('#monthday-select,#bymonth-select,#weekday-select').hide();
		
		// Reset Interval back to 1
		$('input[name="interval"]').val("1");

		// Reset Count back to 1
		$('input[name="count"]').val("1");
		

		// Change back Daily
		$('select[name="freq"]').val('daily');

		// Reset Until / Count radio buttons
		$('input[id="until-select"]').prop('checked', false);
		$('input[id="count-select"]').prop('checked', true).change();
		// $('#count').reset();
}

function rruleGenerate() {
		// Produce RRULE state to feed to rrule.js
		 rrule = "";

		 // Check to be sure there is a count value or until date selected
		 if ( recurringRule.count == "" && recurringRule.until == "" ){
		 	// No end in sight, make it default to 1 occurence
		 	recurringRule.count = "1";
		 }
		 for ( var key in recurringRule ) {
			  if ( recurringRule.hasOwnProperty(key) ) {
			    if ( recurringRule[key] != '') {
			    	rrule += key + '=' + recurringRule[key] + ';';
			    }
			  }
			}			
		// Remove the last semicolon from the end of RRULE
		rrule = rrule.replace(/;\s*$/, "");

		// Convert to Uppercase and return
		return rrule.toUpperCase();		
}

$(document).ready(function(){

	if ( readRule == false) {
		resetOptions();
	
    // Setup start-date picker
      $( '#start-date' ).datepicker({
      	showOtherMonths: true,
      	selectOtherMonths: true,
      	dateFormat: 'yy-mm-dd',
      	onSelect: function(value) {

								dateSelected = new Date(value.replace(/-/g, "/") + ' 00:00:00'); // REGEX used to please SAFARI browser!
								dtstartString = dateSelected.getFullYear() + ('0' + (dateSelected.getMonth()+1)).slice(-2) + ('0' + dateSelected.getDate()).slice(-2);
								$('#start-date-hidden').val(dtstartString + 'T040000z');
								recurringRule.dtstart = dtstartString + 'T040000z';
								
								// Set minimum selected date on end-datepicker
									minEndDate = dateSelected.getFullYear() + '-' + ('0' + (dateSelected.getMonth()+1)).slice(-2) + '-' + ('0' + dateSelected.getDate()).slice(-2);
									$( '#end-date' ).datepicker('option', 'minDate', minEndDate);
									// Reset the selected enddate
									$('#end-date-hidden').val(dtstartString + 'T040000z');
									//alert(dateSelected);
								}
    	}).datepicker('setDate', 'today');
      
      $('#start-date-hidden').val(MyDateString + 'T000000z');

      // Setup the end-date picker
      $( '#end-date' ).datepicker({
      	showOtherMonths: true,
      	selectOtherMonths: true,
      	dateFormat: 'yy-mm-dd',
      	onSelect: function(value) {
								//dateSelected = Date.parseExact(value, "yyyy-MM-dd");
								dateSelected = new Date(value.replace(/-/g, "/") + ' 00:00:00'); // REGEX used to please SAFARI browser!
								untilString = dateSelected.getFullYear() + ('0' + (dateSelected.getMonth()+1)).slice(-2) + ('0' + dateSelected.getDate()).slice(-2);
								$('#end-date-hidden').val(untilString + 'T040000z');
								// Remove the count variable
								recurringRule.count = '';
								// Set until variable
								recurringRule.until = untilString + 'T040000z';

								//alert(dateSelected);
								}
    	}).datepicker('setDate', 'today');

      
		$('#end-date-hidden').val(MyDateString + 'T040000z');
	}

	// Setup buttons Don't allow any buttons to submit the form
		$('button').click(function(e){
			e.preventDefault;
			return false;
		});

});		

// Recurring Event Calculator: Show/Hide.  Grab all the radio buttons to see which one.
$('input[name="event-recurring"]').change(function(){
	
	// Resets all the recurring options
	resetOptions();

	// enable the input next to the selected radio button
	if( $(this).val() == "yes" ){
		$('#recurring-rules').slideDown();

		// Show Until Rules
		$('#until-rules').show();
	
	} else {
		//disable the inputs not selected.
		$('#recurring-rules').hide();
	}
	
});



// FREQ Selection
	$(document).on('change','select[name="freq"]', function(){

		selectedFrequency = $('select[name="freq"] option:selected').attr('class');

		// Hide all DIVS
		$('#recurring-rules > div').hide();

		// Show selected DIV
		$('div.' + selectedFrequency +'-choice').show();
		$('span.freq-selection').text(selectedFrequency);

		// Show Until / Count Rules
		$('#until-rules').show();


		// Reset all the selected rules
		recurringRule = {
			freq: "",
			dtstart: $('#start-date-hidden').val(),
			interval: "1",
			byday: "",
			bysetpos: "",
			bymonthday: "",
			bymonth: "",
			count: "1",
			until: ""

		};

		// Set frequency
		recurringRule.freq = $('select[name="freq"] option:selected').val();
		
		// If it is yearly, fire a change to setup the default values
		if (recurringRule.freq == "yearly") {
			$('select[name="yearly-bymonth"]').change();
		}

	});

// Interval Selection
	$(document).on('change blur keyup', 'input[name="interval"]', function(){
		recurringRule.interval = $(this).val();
		
	});

// BYDAY - FREQ: WEEKLY Selection
	$('#weekday-select button').on('click', function(){
		$(this).toggleClass('active');
		var byday = []; // Array to Store 'byday' in. Reset it to '' to store new days in below

		// Store Selected Days in the BYDAY rule
		$('#weekday-select button').each(function(){
		  
			// Active class is the selected day, store the ID of active days which contains the short day name for the rrule (ex. MO, TU, WE, etc)
			if ( $(this).hasClass('active') ) {
				byday.push($(this).attr('id'));
			}

		});
		recurringRule.byday = byday;
		
	});

// BYMONTHDAY Selection
	$('#monthday-select button').on('click', function(){
		$(this).toggleClass('active');
		var bymonthday = []; // Array to Store 'bymonthday' in. Reset it to '' to store new days in below

		// Store Selected Days in the BYDAY rule
		$('#monthday-select button').each(function(){
		  
			// Active class is the selected day, store the ID of active days which contains the short day name for the rrule (ex. MO, TU, WE, etc)
			if ( $(this).hasClass('active') ) {
				bymonthday.push($(this).attr('data-day-num'));
			}

		});
		recurringRule.bymonthday = bymonthday;
		
		// Reset BYDAY Option
		recurringRule.byday = "";

		// Reset BySetPos
		recurringRule.bysetpos = "";
	});

// BYMONTH Selection
	$('#bymonth-select button').on('click', function(){
		$(this).toggleClass('active');
		var bymonth = []; // Array to Store 'byday' in. Reset it to '' to store new days in below

		// Store Selected Days in the BYDAY rule
		$('#bymonth-select button').each(function(){
		  
			// Active class is the selected day, store the ID of active days which contains the short day name for the rrule (ex. MO, TU, WE, etc)
			if ( $(this).hasClass('active') ) {
				bymonth.push($(this).attr('data-month-num'));
			}

		});
		recurringRule.bymonth = bymonth;
	});


// FREQ=MONTHLY - RADIO BUTTONS
	$('input[name="monthday-pos-select"]').change( function(){

		// Selected Radio Button
		var selectedRadio = $(this).val();

		// A Radio was changed.  Grab all the radio buttons to see which one.
		$('input[name="monthday-pos-select"]').each(function(){
			
			if ( $(this).val() == selectedRadio ) {
				
				switch ( $(this).val() ) {
					case "month-byday-pos-selected":
						// ByDay Select Boxes are being used instead of the Month Day
						
						// Disable all the monthday buttons
						$('#monthday-select button').attr('disabled','disabled');
						$('select[name^="month-byday"]').removeAttr('disabled');

						// Generate the BYDAY variable from selected dropdowns by firing 'change' event
						$('select[name^="month-byday"]').change();
						// Mark recurring object bymonthday back to nothing
						recurringRule.bymonthday = "";

					break; 

					case "monthday-selected":

						// Month Day Buttons are being used instead of the ByDay select boxes
						$('#monthday-select button').removeAttr('disabled');
						$('#monthday-select button').removeClass('active');

						$('select[name^="month-byday"]').attr('disabled','disabled');
						recurringRule.byday = "";
						recurringRule.bysetpos = "";
						

					break; 

				}
			}

		});
	});

// FREQ=YEARLY - RADIO BUTTONS
	$('input[name="yearly-options"]').change( function(){

		// Selected Radio Button ID
		var selectedRadio = $(this).attr('id');

		// A Radio was changed.  Check all the radio buttons' ids to see which one.
		$('input[name="yearly-options"]').each(function(){
			
			if (  $(this).attr('id') == selectedRadio ) {
				
				switch ( $(this).attr('id') ) {
					case "yearly-one-month":
						// Example Pattern
						// FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1;UNTIL=20150818;
						
						// BYMONTH and BYMONTHDAY attributes are going to be set
						// Reset BYSETPOS, BYDAY, 
						recurringRule.bysetpos = "";
						recurringRule.byday = "";

						// Disable all the yearly select boxes 
						$('select[class^="yearly"]').attr('disabled','disabled');
						
						// Disable all the yearly multiple month buttons
						$('.yearly-multiple-months button').attr('disabled','disabled');
						$('.yearly-multiple-months button').removeClass('active');

						// Enable Yearly One Month Options
						$('select[class="yearly-one-month"]').removeAttr('disabled');

						// Fire change to select default values
						$('select[name="yearly-bymonth"]').change();

					break; 

					case "yearly-multiple-months":
						// Example Pattern 
						// FREQ=YEARLY;INTERVAL=1;BYMONTH=1,3,4,10;COUNT=1
						
						// BYMONTH attribute is going to be set
						// Reset BYMONTHDAY, BYDAY, BYSETPOS
						recurringRule.bymonthday = "";	
						recurringRule.byday = "";
						recurringRule.bysetpos = "";

						// Disable all the monthday buttons
						$('select[class^="yearly"]').attr('disabled','disabled');
						
						// Enable the buttons
						$('.yearly-multiple-months button').removeAttr('disabled');
						

					break; 

					case "yearly-precise":
						// Example Pattern
						// FREQ=YEARLY;BYDAY=SU;BYSETPOS=1;BYMONTH=1;UNTIL=20150818;

						// BYDAY, BYSETPOS, and BYMONTH are going to be set
						// Reset BYMONTHDAY
						recurringRule.bymonthday = "";

						// Disable all the yearly select boxes 
						$('select[class^="yearly"]').attr('disabled','disabled');
						
						// Disable all the yearly multiple month buttons
						$('.yearly-multiple-months button').attr('disabled','disabled');
						$('.yearly-multiple-months button').removeClass('active');

						// Enable Yearly One Month Options
						$('select[class="yearly-precise"]').removeAttr('disabled');

						// Fire change to select default values
						$('select[name="yearly-bysetpos"]').change();
					break; 
				}
			}

		});
	});

// FREQ=YEARLY - Yearly One Month selection
// Example Pattern
// FREQ=YEARLY;BYMONTH=1;BYMONTHDAY=1;UNTIL=20150818;

// BYMONTH and BYMONTHDAY attributes are going to be set

$(document).on('change', 'select[name^="yearly-bymonth"]', function(){
	// Example Pattern
	// FREQ=MONTHLY;INTERVAL=1;BYDAY=SU,SA;BYSETPOS=1;COUNT=1

	// First, Second, Third, Fourth or Last Days
	var bymonth = $('select[name="yearly-bymonth"]').val();

	// Make array of selected days.
	var bymonthday = $('select[name="yearly-bymonthday"]').val();

	recurringRule.bymonth = bymonth;
	recurringRule.bymonthday = bymonthday;
});

// FREQ=YEARLY - Yearly Multiple Month selection
// Example Pattern
// FREQ=YEARLY;INTERVAL=1;BYMONTH=1,3,4,10;COUNT=1
	$(document).on('click', '.yearly-multiple-months button', function(){
		$(this).toggleClass('active');
		var bymonth = []; // Array to Store 'bymonth' in. Reset it to '' to store new days in below

		// Store Selected Days in the BYDAY rule
		$('.yearly-multiple-months button').each(function(){
		  
			// Active class is the selected day, store the ID of active days which contains the short day name for the rrule (ex. MO, TU, WE, etc)
			if ( $(this).hasClass('active') ) {
				bymonth.push($(this).attr('data-month-num'));
			}

		});
		recurringRule.bymonth = bymonth;
	});

// FREQ=YEARLY - Yearly Precise Selection
// Example PAttern
// FREQ=YEARLY;BYDAY=SU;BYSETPOS=1;BYMONTH=1;UNTIL=20150818;

// BYDAY, BYSETPOS, and BYMONTH are going to be set

$(document).on('change', 'select[name="yearly-bysetpos"], select[name="yearly-byday"], select[name="yearly-bymonth-with-bysetpos-byday"]', function(){
	// Example Pattern
	// FREQ=MONTHLY;INTERVAL=1;BYDAY=SU,SA;BYSETPOS=1;COUNT=1

	// First, Second, Third, Fourth or Last Days
	var bysetpos = $('select[name="yearly-bysetpos"]').val();

	// Make array of selected days.
	var byday = $('select[name="yearly-byday"]').val().split(',');

	// First, Second, Third, Fourth or Last Days
	var bymonth = $('select[name="yearly-bymonth-with-bysetpos-byday"]').val();
	//alert($('select[name="yearly-bymonth-with-bysetpos-byday"]').val());

	recurringRule.bymonthday = "";

	recurringRule.bymonth = bymonth;
	recurringRule.byday = byday;
	recurringRule.bysetpos = bysetpos;
});


// FREQ=MONTHLY
// BYDAY and BYSETPOS MONTHLY Setting

$(document).on('change', 'select[name^="month-byday"]', function(){
	// Example Pattern
	// FREQ=MONTHLY;INTERVAL=1;BYDAY=SU,SA;BYSETPOS=1;COUNT=1

	// First, Second, Third, Fourth or Last Days
	var bySetPos = $('select[name="month-byday-pos"]').val();

	// Make array of selected days.
	var daysSelected = $('select[name="month-byday-pos-name"]').val().split(',');

	recurringRule.bysetpos = bySetPos;
	recurringRule.byday = daysSelected;
});

// Set the count variable
	$(document).on('input change', 'input[name="count"]', function() {
	
		recurringRule.count = $(this).val();
	});



// Handle Until / Count Radio Buttons
	$('input[name="end-select"]').change(function(){

		// Selected Radio Button
		var selectedRadio = $(this).val();

		// A Radio was changed.  Grab all the radio buttons to see which one.
		$('input[name="end-select"]').each(function(){
			// enable the input next to the selected radio button
			if( $(this).val() == selectedRadio ){
				$('input[name="'+selectedRadio+'"]').removeAttr('disabled');
				if ( $(this).val() == 'until' ) {
					// Set the date in the until textbox as the until date

					// Remove the count variable
					recurringRule.count = '';
					// Set until variable
					recurringRule.until = $('#end-date-hidden').val();
				}
			} else {
				//disable the inputs not selected.
				$(this).next('input').attr('disabled','disabled').val('');
				
				//reset the stored value in the recurringRule object
				 var not_selected = $(this).next('input').attr('name');
				 
				 recurringRule[not_selected] = '';

			}

		});
	});


// Handle Form Submission
	$('#rrule').submit(function(e){
		e.preventDefault();
		// feed statement to rrule.js
		// var rrule = rruleGenerate();
		
		// if ( rrule != '' ) {
		// 	var recurringDays = new RRule.fromString(rrule);
		// 	var html = makeRows(recurringDays.all());
		// 	$(".show-dates").html(html);
			
		// }
		alert ( rruleGenerate() );
		return false;
		
	});
</script>
