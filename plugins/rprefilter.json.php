<?php

/**
 * string trim(CTE, string)
 */
function CTEPL__json($string)
{
  $re_dquoted = '(?:".*?(?<!\\\)")';
  $re_squoted = '(?:\'.*?(?<!\\\)\')';
  $re_newArray = '(?:new Array\()';
  $toks = preg_split('/('.$re_dquoted.'|'.$re_squoted.'|'.$re_newArray.')/', $string, null, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
  
  for($i=0, $ii=count($toks); $i<$ii; $i++)
  {
    if(!preg_match('/^'.$re_dquoted.'|'.$re_squoted.'|'.$re_newArray.'$/', $toks[$i]))
      $toks[$i] = preg_replace("/\n|\r|\t|\s/", '', $toks[$i]);
  }
  
  return implode('', $toks);
}
?>