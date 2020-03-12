<?php 

$query="SELECT AVG(ttfr.secs) AS AVG_RESPONSE_TIME
FROM (
         SELECT TIMESTAMPDIFF(SECOND,".TABLE_PREFIX."ticket.created,MIN(".TABLE_PREFIX."thread_entry.created)) as secs
          FROM ".TABLE_PREFIX."ticket
           LEFT JOIN ".TABLE_PREFIX."thread on
            ".TABLE_PREFIX."ticket.ticket_id = ".TABLE_PREFIX."thread.object_id
             LEFT JOIN ".TABLE_PREFIX."thread_entry on
                  ".TABLE_PREFIX."thread.id = ".TABLE_PREFIX."thread_entry.thread_id
                       AND ".TABLE_PREFIX."thread_entry.type IN ('R') /* N is notes, R is replys */ AND YEAR(".TABLE_PREFIX."ticket.created)=".date('Y')." 
                        WHERE ".TABLE_PREFIX."thread_entry.id IS NOT NULL
                         GROUP BY ".TABLE_PREFIX."thread.id
                 ) ttfr";

$result=db_query($query);
$timeInSeconds = db_result($result,0);
 
echo "<div class='quick quick_left'>".date('Y')."</div><div class='quick quick_right'>".Report::duration($timeInSeconds)."</div>";
 
?>
