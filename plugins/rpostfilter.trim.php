<?php

/**
 * string trim(CTE, string)
 */
function CTEPL__trim($string)
{
  $string = preg_replace("/\n/", '', $string);
  $string = preg_replace("/\r/", '', $string);
  $string = preg_replace("/\t/", '', $string);

  return $string;
}
?>