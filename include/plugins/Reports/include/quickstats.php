<div id='quickstats'>
<div class='section-header'>
<span class='section-title'><?php echo rlang::tr('_quickstats_'); ?></span>
</div>

<div class='section-content'>

  <div class='sub-section'>
   <div class='sub-header'>
   <span class='sub-title'><?php echo rlang::tr('_averagetimeticketsremainedopen_'); ?></span>
   </div>
  <div class='sub-content'>
   <?php 
    $thisyear = require_once("timecalc_thisyear.php");
    $lastyear = require_once("timecalc_lastyear.php");
    $alltime  = require_once("timecalc_alltime.php");
   ?>
  </div>
 </div>

 <div class='sub-section'>
  <div class='sub-header'>
  <span class='sub-title'><?php echo rlang::tr('_averageresponsetime_'); ?></span>
  </div>
 <div class='sub-content'>
    <?php 
     $avgrespontime = require_once("avgrespontime_thisyear.php");
     $avgrespontime = require_once("avgrespontime_lastyear.php");
     $avgrespontime = require_once("avgrespontime_alltime.php");
    ?>
   </div>
  </div>
 
 <div class='sub-section'>
  <div class='sub-header'>
  <span class='sub-title'><?php echo rlang::tr('_numberofticketsopened_'); ?></span>
  </div>
 <div class='sub-content'>
   <?php 
    $ticketsthisyear = require_once("tickets_thisyear.php");
    $ticketslastyear = require_once("tickets_lastyear.php");
    $ticketslastyear = require_once("tickets_alltime.php");
   ?>
  </div>
 </div>
<div style='clear:both;'></div>

</div>
</div>
