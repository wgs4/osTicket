<?php 

	$year = date('Y') -1;
	$query = "SELECT ROUND(AVG(TIMESTAMPDIFF(HOUR, 
			".TABLE_PREFIX."ticket.created, 
			".TABLE_PREFIX."ticket.closed)),2) AS hoursAVG 
			FROM ".TICKET_TABLE." 
			WHERE YEAR(created)='$year' AND 
				closed IS NOT NULL";

	$result=db_query($query);
	$fu = db_result($result,0);

	// convert days to seconds (*24 hours *60 minutes *60seconds)
	$timeInSeconds = $fu * 60 * 60;
 
	echo "<div class='quick quick_left'>".$year."</div>
	      <div class='quick quick_right'>".Report::duration($timeInSeconds)."</div>";
