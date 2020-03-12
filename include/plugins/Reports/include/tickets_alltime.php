<?php 

	$query = "SELECT COUNT(ticket_id) as counted FROM ".TICKET_TABLE." WHERE YEAR(`created`) is NOT NULL;";
	$result=db_query($query);
	$num = db_affected_rows($result);

	$vals = array();

	$i=0;
	while ($i < $num) {
 		$counted = db_result($result,$i,"counted");
		$debug=0;

 		// if debug is 1 print extra info
 		if($debug==1)
  			echo "Opened: ".$created."($time1) Closed: ".$closed."($time2) --- <br>";

	++$i;
	}

	if($counted==NULL){$counted=0;}

	echo "<div class='quick quick_left quick_no_border'>".rlang::tr('_alltime_')."</div>
	      <div class='quick quick_right quick_no_border'>$counted</div>";

?>
