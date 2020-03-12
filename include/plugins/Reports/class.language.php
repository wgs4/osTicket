<?php
class rlang {

 	public static function getLanguage($var){

		$sql_id="SELECT id FROM ".TABLE_PREFIX."plugin WHERE install_path LIKE '%Reports%'";
		$res_id=db_query($sql_id);
		$id=db_result($res_id,0);

  		$sql = "SELECT `value` from ".CONFIG_TABLE." WHERE ".CONFIG_TABLE.".key='$var' && namespace='plugin.$id'";
  		$res = db_query($sql);
  		$value = db_result($res,0);

        	// if in {"value":"key"} format, get value only
        	if(strpos($value, '}')&&$var='range'){
                	$entries=explode('"',$value);
                	$value=$entries[1];
        	}


    		return $value;
 	}

        public static function tr($string){

		$translation=self::getLanguage('language');
		$translation==''?$translation='english':NULL;
	    	$all_lang=file(__DIR__."/include/languages/$translation.php");
	    	foreach($all_lang as $lang){
		    $lang=trim($lang);
		    $pieces=explode(",",$lang);
		    $original=$pieces[0];
		    $output=$pieces[1];
		    if($original==$string){
			$output_translation=$output;
		    }
	    	}
	    	if($output_translation==''){error_log("$translation translation needed for '$string'");}

	    	return trim($output_translation);
	}

}
