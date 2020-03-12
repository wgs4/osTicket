<?php 

$year = date(Y) -1;

// query database for ALL tickets created and closed values.
$query = "select COUNT(ticket_id) as counted FROM `".TABLE_PREFIX."ticket` WHERE YEAR(`created`)='$year';";
$result=db_query($query);
$num = db_affected_rows($result);

//$values = array();
$vals = array();

$i=0;
while ($i < $num) {
 $counted = db_result($result,$i,"counted");
 
 // if debug is 1 print extra info
 if(debug==1) {
  echo "Opened: ".$created."($time1) Closed: ".$closed."($time2) --- <br>";
 }

	++$i;
}
if($counted==NULL){$counted=0;}

echo "<div class='quick quick_left'>$year</div><div class='quick quick_right'>$counted</div>";

?>
