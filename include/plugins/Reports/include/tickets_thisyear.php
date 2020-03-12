<?php 

	$year = date('Y');
	$query = "SELECT COUNT(ticket_id) as counted FROM ".TICKET_TABLE." WHERE YEAR(`created`)='$year';";
	$result=db_query($query)or die("Die");
	$num = db_affected_rows($result);

	$vals = array();
	$i=0;

	while ($i < $num) {
 		$counted = db_result($result,$i,"counted");
 
		if(debug==1)
  			echo "Opened: ".$created."($time1) Closed: ".$closed."($time2) --- <br>";

	++$i;
	}

	if($counted==NULL){$counted=0;}

	echo "<div class='quick quick_left'>$year</div>
	      <div class='quick quick_right'>$counted</div>";

?>
